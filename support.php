<?php
// ══════════════════════════════════════════════════════
// support.php — Bantuan & Support Tiket (Sisi Tenant)
// Akses: permission 'bantuan.view' — semua role default punya
// ══════════════════════════════════════════════════════
$activePage = 'support';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once __DIR__ . '/components.php';

date_default_timezone_set('Asia/Jakarta');

$user     = currentUser();
$tenant   = currentTenant();
$tenantId = TenantResolver::id();
$outletId = TenantResolver::outletId();
$userId   = (int)($user['id'] ?? 0);
$db       = Database::get();

// dismiss_banner tidak butuh bantuan.view (tenant dismiss notif umum)
$action = $_REQUEST['action'] ?? '';
if ($action !== 'dismiss_banner') {
    requirePermission('bantuan.view');
}

// ── JSON API ──────────────────────────────────────────
if ($action) {
    header('Content-Type: application/json');

    // ── list_tickets ──────────────────────────────────
    if ($action === 'list_tickets') {
        $rows = $db->prepare(
            "SELECT st.*,
                    sa.name AS assigned_nama
             FROM support_tickets st
             LEFT JOIN super_admins sa ON sa.id = st.assigned_to
             WHERE st.tenant_id = ?
             ORDER BY
               FIELD(st.status,'open','in_progress','waiting_tenant','resolved','closed'),
               st.updated_at DESC
             LIMIT 50"
        );
        $rows->execute([$tenantId]);
        echo json_encode($rows->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // ── get_thread ────────────────────────────────────
    if ($action === 'get_thread') {
        $tid = (int)($_GET['id'] ?? 0);

        $ts = $db->prepare("SELECT * FROM support_tickets WHERE id=? AND tenant_id=? LIMIT 1");
        $ts->execute([$tid, $tenantId]);
        $ticket = $ts->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) { echo json_encode(['error'=>'Tiket tidak ditemukan.']); exit; }

        // Mark unread replies as read (by recording tenant view time)
        $db->prepare("UPDATE support_tickets SET updated_at=updated_at WHERE id=? AND tenant_id=?")
           ->execute([$tid, $tenantId]); // no-op touch, just fire

        $rs = $db->prepare(
            "SELECT r.*, sa.name AS sa_nama, u.nama AS user_nama
             FROM support_ticket_replies r
             LEFT JOIN super_admins sa ON sa.id = r.superadmin_id
             LEFT JOIN hl_users u ON u.id = r.user_id AND u.tenant_id = r.tenant_id
             WHERE r.ticket_id = ? AND r.is_internal = 0
             ORDER BY r.created_at ASC"
        );
        $rs->execute([$tid]);
        $replies = $rs->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['ticket' => $ticket, 'replies' => $replies]);
        exit;
    }

    // ── submit_ticket ────────────────────────────────
    if ($action === 'submit_ticket' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        if (!hasPermission('bantuan.submit')) { echo json_encode(['error'=>'Akses ditolak.']); exit; }

        $subject  = substr(trim($_POST['subject'] ?? ''), 0, 200);
        $message  = trim($_POST['message'] ?? '');
        $category = in_array($_POST['category'] ?? '', ['billing','teknis','fitur','akun','lainnya'])
                    ? $_POST['category'] : 'lainnya';

        if (!$subject || !$message) {
            echo json_encode(['error'=>'Subject dan pesan wajib diisi.']); exit;
        }

        $db->prepare(
            "INSERT INTO support_tickets
               (tenant_id, outlet_id, submitted_by, subject, message, category, status, created_at, updated_at)
             VALUES (?,?,?,?,?,?,'open',NOW(),NOW())"
        )->execute([$tenantId, $outletId ?: null, $userId, $subject, $message, $category]);
        $ticketId = (int)$db->lastInsertId();

        // Notify super admin: ticket baru (best-effort)
        try {
            require_once ROOT . '/core/SaNotifier.php';
            SaNotifier::supportTicketCreated($ticketId);
        } catch (Throwable $e) { error_log('[SaNotify supportTicket] ' . $e->getMessage()); }

        // Build WA link for owner to escalate (semi-automated notify)
        $waNumber  = preg_replace('/[^0-9]/', '', $tenant['owner_wa'] ?? '');
        $ownerName = $tenant['owner_name'] ?? 'Pelanggan';

        echo json_encode([
            'success'   => true,
            'ticket_id' => $ticketId,
            'message'   => "Tiket #$ticketId berhasil dikirim. Tim kami akan merespons segera.",
        ]);
        exit;
    }

    // ── reply (tenant balas) ──────────────────────────
    if ($action === 'reply' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        if (!hasPermission('bantuan.reply')) { echo json_encode(['error'=>'Akses ditolak.']); exit; }

        $tid     = (int)($_POST['ticket_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');

        if (!$tid || !$message) { echo json_encode(['error'=>'Data tidak lengkap.']); exit; }

        // Verify ownership & status
        $ts = $db->prepare("SELECT status FROM support_tickets WHERE id=? AND tenant_id=? LIMIT 1");
        $ts->execute([$tid, $tenantId]);
        $trow = $ts->fetch(PDO::FETCH_ASSOC);
        if (!$trow) { echo json_encode(['error'=>'Tiket tidak ditemukan.']); exit; }
        if ($trow['status'] === 'closed') { echo json_encode(['error'=>'Tiket sudah ditutup.']); exit; }

        $db->prepare(
            "INSERT INTO support_ticket_replies (ticket_id, user_id, message, is_internal)
             VALUES (?,?,?,0)"
        )->execute([$tid, $userId, $message]);

        // Update ticket status: if waiting_tenant → in_progress
        if ($trow['status'] === 'waiting_tenant') {
            $db->prepare("UPDATE support_tickets SET status='in_progress', updated_at=NOW() WHERE id=?")
               ->execute([$tid]);
        } else {
            $db->prepare("UPDATE support_tickets SET updated_at=NOW() WHERE id=?")->execute([$tid]);
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // ── close_ticket (tenant tutup tiket resolved) ───
    if ($action === 'close_ticket' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        if (!hasPermission('bantuan.close')) { echo json_encode(['error'=>'Akses ditolak.']); exit; }

        $tid    = (int)($_POST['ticket_id'] ?? 0);
        $rating = max(1, min(5, (int)($_POST['rating'] ?? 0)));
        $remark = substr(trim($_POST['rating_comment'] ?? ''), 0, 500);

        $ts = $db->prepare(
            "SELECT status FROM support_tickets WHERE id=? AND tenant_id=? LIMIT 1"
        );
        $ts->execute([$tid, $tenantId]);
        $trow = $ts->fetch(PDO::FETCH_ASSOC);

        if (!$trow || !in_array($trow['status'], ['resolved','in_progress','waiting_tenant'])) {
            echo json_encode(['error' => 'Status tiket tidak memungkinkan penutupan.']); exit;
        }

        $db->prepare(
            "UPDATE support_tickets
                SET status='closed', closed_at=NOW(), updated_at=NOW(),
                    rating=?, rating_comment=?
              WHERE id=? AND tenant_id=?"
        )->execute([$rating ?: null, $remark ?: null, $tid, $tenantId]);

        echo json_encode(['success' => true]);
        exit;
    }

    // ── dismiss_banner (mark announcement as read) ──
    if ($action === 'dismiss_banner' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $annId = (int)($_POST['announcement_id'] ?? 0);
        if ($annId) {
            try {
                $db->prepare(
                    "INSERT IGNORE INTO saas_announcement_reads (announcement_id, tenant_id) VALUES (?,?)"
                )->execute([$annId, $tenantId]);
                $db->prepare(
                    "UPDATE saas_announcements SET total_reads=total_reads+1 WHERE id=?"
                )->execute([$annId]);
            } catch (Throwable) {}
        }
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['error' => 'Action tidak dikenal.']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Bantuan & Support'); ?>
<style>
/* ── Ticket status badges ───────────────────────── */
.t-badge-open           { background:#FEF3C7;color:#92400E; }
.t-badge-in_progress    { background:#DBEAFE;color:#1D4ED8; }
.t-badge-waiting_tenant { background:#EDE9FE;color:#5B21B6; }
.t-badge-resolved       { background:#D1FAE5;color:#065F46; }
.t-badge-closed         { background:var(--light);color:var(--gray); }

/* ── Priority badges ────────────────────────────── */
.p-badge-low      { background:#F3F4F6;color:#6B7280; }
.p-badge-normal   { background:#DBEAFE;color:#1D4ED8; }
.p-badge-high     { background:#FEF3C7;color:#92400E; }
.p-badge-critical { background:#FEE2E2;color:#991B1B; }

/* ── Category icon ──────────────────────────────── */
.cat-chip {
  display:inline-flex;align-items:center;gap:4px;
  font-size:11px;font-weight:600;padding:2px 8px;
  border-radius:20px;background:var(--teal-bg);color:var(--teal-d);
}

/* ── Submit form card ───────────────────────────── */
.submit-toggle {
  display:flex;align-items:center;justify-content:space-between;
  cursor:pointer;user-select:none;
}
.submit-toggle h2 { font-size:15px;font-weight:700;color:var(--navy);margin:0; }
.submit-form-body { margin-top:16px; }
.submit-form-body.hidden { display:none; }

/* ── Ticket rows ────────────────────────────────── */
.t-row { cursor:pointer;transition:background .12s; }
.t-row:hover td { background:rgba(53,232,213,.04); }
.t-subject { font-weight:600;color:var(--navy);font-size:13.5px; }
.t-meta    { font-size:11.5px;color:var(--gray);margin-top:2px; }
.t-time    { font-size:12px;color:var(--gray);white-space:nowrap; }

/* ── Thread modal ───────────────────────────────── */
.thread-wrap {
  display:flex;flex-direction:column;gap:12px;
  padding:4px 0 8px;
}
.bubble {
  max-width:80%;display:flex;flex-direction:column;gap:4px;
}
.bubble.admin { align-self:flex-start; }
.bubble.tenant{ align-self:flex-end; }
.bubble-head {
  font-size:11px;font-weight:700;color:var(--gray);
}
.bubble.tenant .bubble-head { text-align:right; }
.bubble-body {
  padding:10px 14px;border-radius:12px;font-size:13.5px;line-height:1.55;
  white-space:pre-wrap;word-break:break-word;
}
.bubble.admin  .bubble-body { background:var(--off);border:1px solid var(--light);color:var(--dark); border-top-left-radius:4px; }
.bubble.tenant .bubble-body { background:var(--navy-d);color:#fff;border-top-right-radius:4px; }
.bubble-time { font-size:10.5px;color:var(--gray); }
.bubble.tenant .bubble-time { text-align:right; }

/* Empty thread */
.thread-empty {
  text-align:center;padding:28px;color:var(--gray);font-size:13px;
}

/* Reply form in modal */
.reply-area {
  border-top:1px solid var(--light);
  padding-top:16px;margin-top:4px;
}

/* Ticket detail header in modal */
.t-detail-bar {
  display:flex;flex-wrap:wrap;gap:6px;align-items:center;
  padding-bottom:12px;border-bottom:1px solid var(--light);margin-bottom:4px;
}

/* Star rating */
.star-row { display:flex;gap:4px;margin-top:4px; }
.star {
  font-size:24px;cursor:pointer;line-height:1;
  filter:grayscale(1);transition:filter .1s;
}
.star.active,.star:hover { filter:none; }

/* SLA warning row */
.sla-warn {
  display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;
  padding:6px 10px;border-radius:8px;margin-bottom:4px;
}
.sla-warn.amber { background:#FEF3C7;color:#92400E; }
.sla-warn.red   { background:#FEE2E2;color:#991B1B; }
</style>
</head>
<body>
<?php renderTopbar('support'); ?>

<div class="hl-page-header">
  <h1 class="hl-page-title">🎧 Bantuan & Support</h1>
  <p class="hl-page-sub">Kirim pertanyaan atau laporkan masalah — tim LaMaSy siap membantu.</p>
</div>

<!-- ══ SECTION 1: Submit Tiket Baru ═════════════════ -->
<?php if (hasPermission('bantuan.submit')): ?>
<div class="hl-card" style="margin-bottom:20px;" id="submitCard">
  <div class="hl-card-header">
    <div class="submit-toggle" onclick="toggleSubmitForm()">
      <span class="hl-card-title">✉️ Buat Tiket Baru</span>
      <button type="button" class="hl-btn hl-btn-primary hl-btn-sm" id="submitToggleBtn"
              onclick="event.stopPropagation();toggleSubmitForm()">+ Buat Tiket</button>
    </div>
  </div>
  <div class="hl-card-body submit-form-body hidden" id="submitFormBody">
    <form id="submitTicketForm" onsubmit="submitTicket(event)">
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Kategori <span class="req">*</span></label>
          <select name="category" id="ticketCategory" class="hl-select" required>
            <option value="teknis">🔧 Teknis — Bug / Error Sistem</option>
            <option value="billing">💳 Billing — Coin / Pembayaran</option>
            <option value="fitur">💡 Fitur — Cara Penggunaan</option>
            <option value="akun">👤 Akun — Password / User</option>
            <option value="lainnya">📩 Lainnya</option>
          </select>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Subject <span class="req">*</span></label>
          <input type="text" name="subject" id="ticketSubject" class="hl-input"
                 placeholder="Deskripsikan masalah secara singkat" required maxlength="200"/>
        </div>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Detail Masalah <span class="req">*</span></label>
        <textarea name="message" id="ticketMessage" class="hl-textarea" rows="4" required
                  placeholder="Jelaskan masalah selengkap mungkin — langkah yang dilakukan, error yang muncul, dll."></textarea>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:4px;">
        <button type="button" class="hl-btn hl-btn-outline hl-btn-sm"
                onclick="toggleSubmitForm()">Batal</button>
        <button type="submit" class="hl-btn hl-btn-primary hl-btn-sm" id="submitBtn">
          Kirim Tiket →
        </button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ══ SECTION 2: Daftar Tiket ══════════════════════ -->
<div class="hl-card">
  <div class="hl-card-header" style="display:flex;align-items:center;justify-content:space-between;">
    <span class="hl-card-title">📋 Tiket Saya</span>
    <div style="display:flex;gap:8px;align-items:center;">
      <select id="filterStatus" class="hl-select" style="width:auto;padding:5px 10px;font-size:12px;"
              onchange="loadTickets()">
        <option value="">Semua Status</option>
        <option value="open">Open</option>
        <option value="in_progress">In Progress</option>
        <option value="waiting_tenant">Waiting Respons</option>
        <option value="resolved">Resolved</option>
        <option value="closed">Closed</option>
      </select>
    </div>
  </div>
  <div class="hl-card-body" style="padding:0;">
    <div class="hl-table-wrap">
      <table class="hl-table" id="ticketTable">
        <thead>
          <tr>
            <th style="width:52px">ID</th>
            <th>Subject</th>
            <th style="width:110px">Kategori</th>
            <th style="width:90px">Status</th>
            <th style="width:90px">Terakhir</th>
          </tr>
        </thead>
        <tbody id="ticketBody">
          <tr><td colspan="5" style="text-align:center;padding:28px;color:var(--gray);">Memuat…</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ══ Thread Modal ══════════════════════════════════ -->
<div class="hl-modal-overlay" id="threadOverlay" onclick="closeThread(event)">
  <div class="hl-modal hl-modal-lg">
    <div class="hl-modal-panel">

      <div class="hl-modal-header">
        <span class="hl-modal-title" id="threadTitle">Detail Tiket</span>
        <button class="hl-modal-close" onclick="closeThread()">✕</button>
      </div>

      <div class="hl-modal-body" id="threadBody" style="max-height:68vh;overflow-y:auto;">
        <div style="text-align:center;padding:32px;color:var(--gray)">Memuat…</div>
      </div>

    </div>
  </div>
</div>

<?php renderToast(); ?>

<script>
const CAN_SUBMIT_BANTUAN = <?= hasPermission('bantuan.submit') ? 'true' : 'false' ?>;
const CAN_REPLY_BANTUAN  = <?= hasPermission('bantuan.reply')  ? 'true' : 'false' ?>;
const CAN_CLOSE_BANTUAN  = <?= hasPermission('bantuan.close')  ? 'true' : 'false' ?>;

const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

// ── Category & status helpers ────────────────────────
const catIcon  = {billing:'💳',teknis:'🔧',fitur:'💡',akun:'👤',lainnya:'📩'};
const catLabel = {billing:'Billing',teknis:'Teknis',fitur:'Fitur',akun:'Akun',lainnya:'Lainnya'};
const statusLabel = {open:'Open',in_progress:'In Progress',waiting_tenant:'Menunggu Kamu',resolved:'Resolved',closed:'Closed'};
const priLabel    = {low:'Low',normal:'Normal',high:'High',critical:'Critical'};
const esc = s => { const d=document.createElement('div');d.textContent=s||'';return d.innerHTML; };
const fmtAgo = s => {
  if (!s) return '-';
  const diff = Date.now() - new Date(s).getTime();
  const h = Math.floor(diff/3600000), m = Math.floor(diff/60000);
  if (h < 1) return m + ' mnt lalu';
  if (h < 24) return h + ' jam lalu';
  return Math.floor(h/24) + ' hari lalu';
};
const fmtDateTime = s => s ? new Date(s).toLocaleString('id-ID',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '-';

// ── Toggle submit form ───────────────────────────────
let _submitOpen = false;
function toggleSubmitForm() {
  _submitOpen = !_submitOpen;
  document.getElementById('submitFormBody').classList.toggle('hidden', !_submitOpen);
  document.getElementById('submitToggleBtn').textContent = _submitOpen ? '✕ Batal' : '+ Buat Tiket';
}

// ── Load tickets ─────────────────────────────────────
function loadTickets() {
  const filterStatus = document.getElementById('filterStatus').value;
  const tbody = document.getElementById('ticketBody');
  // Skeleton loading state
  tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:28px;color:var(--gray)">
    ⏳ Memuat tiket…
  </td></tr>`;
  fetch('support.php?action=list_tickets', { headers:{'X-Requested-With':'XMLHttpRequest'} })
    .then(r => r.json()).then(rows => {
      const filtered = filterStatus ? rows.filter(r => r.status === filterStatus) : rows;
      renderTickets(filtered);
    })
    .catch(err => {
      tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:28px;color:#EF4444">
        ⚠️ Gagal load tiket. <button onclick="loadTickets()" style="color:#3B82F6;background:none;border:none;text-decoration:underline;cursor:pointer">Coba lagi</button>
      </td></tr>`;
      console.warn('loadTickets:', err);
    });
}

function renderTickets(rows) {
  const tbody = document.getElementById('ticketBody');
  if (!rows || !rows.length) {
    tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:28px;color:var(--gray);">
      Belum ada tiket. Klik "+ Buat Tiket" untuk melaporkan masalah.
    </td></tr>`;
    return;
  }

  tbody.innerHTML = rows.map(r => {
    // SLA warning
    const ageH = (Date.now() - new Date(r.created_at).getTime()) / 3600000;
    const slaWarn = r.status === 'open' && ageH > 24
      ? `<span title="Tiket sudah >24 jam belum direspons" style="color:#EF4444;font-size:10px;margin-left:4px;">⏱ Lama</span>` : '';

    return `<tr class="t-row" onclick="openThread(${r.id})">
      <td style="font-family:var(--mono);font-size:12px;color:var(--gray);">#${r.id}</td>
      <td>
        <div class="t-subject">${esc(r.subject)}${slaWarn}</div>
        <div class="t-meta">${catIcon[r.category]||'📩'} ${catLabel[r.category]||r.category}
          ${r.assigned_nama ? '&nbsp;·&nbsp; Ditangani oleh ' + esc(r.assigned_nama) : ''}
        </div>
      </td>
      <td><span class="hl-badge cat-chip">${catIcon[r.category]||'📩'} ${catLabel[r.category]||r.category}</span></td>
      <td><span class="hl-badge t-badge-${r.status}">${statusLabel[r.status]||r.status}</span></td>
      <td class="t-time">${fmtAgo(r.updated_at||r.created_at)}</td>
    </tr>`;
  }).join('');
}

// ── Open thread modal ────────────────────────────────
let _currentTicketId = null;
function openThread(ticketId) {
  _currentTicketId = ticketId;
  document.getElementById('threadOverlay').classList.add('open');
  document.getElementById('threadBody').innerHTML =
    '<div style="text-align:center;padding:32px;color:var(--gray)">Memuat…</div>';

  fetch(`support.php?action=get_thread&id=${ticketId}`, { headers:{'X-Requested-With':'XMLHttpRequest'} })
    .then(r => r.json()).then(d => {
      if (d.error) { showToast(d.error,'error'); return; }
      renderThread(d.ticket, d.replies);
    });
}

function closeThread(e) {
  if (e && e.target !== document.getElementById('threadOverlay')) return;
  document.getElementById('threadOverlay').classList.remove('open');
  _currentTicketId = null;
  loadTickets(); // refresh list
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeThread(); });

function renderThread(ticket, replies) {
  document.getElementById('threadTitle').textContent = `Tiket #${ticket.id} — ${ticket.subject}`;

  const canReply  = CAN_REPLY_BANTUAN && !['closed'].includes(ticket.status);
  const isResolved = ticket.status === 'resolved';
  const isClosed   = ticket.status === 'closed';
  const myName     = <?= json_encode($user['nama'] ?? 'Kamu') ?>;

  // Detail bar
  const detailBar = `
    <div class="t-detail-bar">
      <span class="hl-badge t-badge-${ticket.status}">${statusLabel[ticket.status]||ticket.status}</span>
      <span class="hl-badge p-badge-${ticket.priority}">${priLabel[ticket.priority]||ticket.priority}</span>
      <span class="hl-badge cat-chip">${catIcon[ticket.category]||'📩'} ${catLabel[ticket.category]||ticket.category}</span>
      <span style="font-size:12px;color:var(--gray);margin-left:auto">Dibuat ${fmtDateTime(ticket.created_at)}</span>
    </div>`;

  // Thread bubbles
  let bubblesHtml = '';
  if (!replies || !replies.length) {
    bubblesHtml = `<div class="thread-empty">💬 Belum ada balasan. Tim kami akan segera merespons tiket ini.</div>`;
  } else {
    bubblesHtml = '<div class="thread-wrap">';
    // Show original message first
    bubblesHtml += `
      <div class="bubble tenant">
        <div class="bubble-head">Kamu &nbsp;·&nbsp; pesan awal</div>
        <div class="bubble-body">${esc(ticket.message)}</div>
        <div class="bubble-time">${fmtDateTime(ticket.created_at)}</div>
      </div>`;
    // Then replies
    replies.forEach(r => {
      const isAdmin = !!r.superadmin_id;
      const sender  = isAdmin ? (r.sa_nama || 'Tim LaMaSy') : (r.user_nama || myName);
      bubblesHtml += `
        <div class="bubble ${isAdmin ? 'admin' : 'tenant'}">
          <div class="bubble-head">${esc(sender)}</div>
          <div class="bubble-body">${esc(r.message)}</div>
          <div class="bubble-time">${fmtDateTime(r.created_at)}</div>
        </div>`;
    });
    bubblesHtml += '</div>';
  }

  // Original message (if no replies yet, already shown above; skip)
  let origHtml = '';
  if (!replies || !replies.length) {
    origHtml = `
      <div style="background:var(--off);border:1px solid var(--light);border-radius:10px;padding:14px;margin:4px 0 12px;font-size:13.5px;color:var(--dark);white-space:pre-wrap;">
        ${esc(ticket.message)}
      </div>`;
    bubblesHtml = origHtml; // replace the "no replies" message
  }

  // Reply form
  let replyHtml = '';
  if (canReply && !isResolved) {
    replyHtml = `
      <div class="reply-area">
        <div style="font-size:12px;font-weight:700;color:var(--gray);margin-bottom:8px;">Tulis Balasan</div>
        <textarea id="replyMsg" class="hl-textarea" rows="3"
                  placeholder="Tulis pesan untuk tim support…" style="margin-bottom:8px;"></textarea>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
          <button id="replyBtn" class="hl-btn hl-btn-primary hl-btn-sm" onclick="sendReply()">Kirim Balasan →</button>
        </div>
      </div>`;
  }

  // Resolve → Close + rating section
  let closeHtml = '';
  if (isResolved && CAN_CLOSE_BANTUAN) {
    closeHtml = `
      <div class="reply-area" style="background:#F0FDF4;border:1px solid #6EE7B7;border-radius:10px;padding:16px;">
        <div style="font-weight:700;color:#065F46;margin-bottom:6px;">✅ Masalah sudah diselesaikan?</div>
        <div style="font-size:13px;color:#374151;margin-bottom:12px;">
          Beri rating untuk membantu kami meningkatkan layanan support.
        </div>
        <div style="margin-bottom:10px;">
          <div style="font-size:12px;font-weight:700;color:var(--gray);margin-bottom:4px;">Rating Layanan</div>
          <div class="star-row" id="starRow">
            ${[1,2,3,4,5].map(i=>`<span class="star" data-v="${i}" onclick="setStar(${i})">⭐</span>`).join('')}
          </div>
          <input type="hidden" id="starValue" value="5"/>
        </div>
        <textarea id="ratingComment" class="hl-textarea" rows="2"
                  placeholder="Komentar (opsional)…" style="margin-bottom:10px;"></textarea>
        <div style="display:flex;gap:8px;">
          <button data-close-btn class="hl-btn hl-btn-green hl-btn-sm" onclick="closeTicket()">✅ Tandai Selesai & Kirim Rating</button>
          <button data-close-btn class="hl-btn hl-btn-outline hl-btn-sm" onclick="closeTicketNoRating()">Tutup Tanpa Rating</button>
        </div>
      </div>`;
  }

  // Closed info
  let closedHtml = '';
  if (isClosed) {
    closedHtml = `
      <div style="background:var(--off);border:1px solid var(--light);border-radius:10px;
                  padding:14px;text-align:center;font-size:13px;color:var(--gray);margin-top:4px;">
        🔒 Tiket ini sudah ditutup.
        ${ticket.rating ? `<br>Rating kamu: ${'⭐'.repeat(parseInt(ticket.rating))} (${ticket.rating}/5)` : ''}
      </div>`;
  }

  document.getElementById('threadBody').innerHTML =
    detailBar + bubblesHtml + replyHtml + closeHtml + closedHtml;

  // Init star rating
  if (isResolved) setStar(5);
}

function setStar(v) {
  document.getElementById('starValue').value = v;
  document.querySelectorAll('.star').forEach(s => {
    s.classList.toggle('active', parseInt(s.dataset.v) <= v);
  });
}

// ── Submit ticket ────────────────────────────────────
function submitTicket(e) {
  e.preventDefault();
  const btn  = document.getElementById('submitBtn');
  const form = e.target;
  btn.disabled = true; btn.textContent = 'Mengirim…';

  const body = new FormData(form);
  body.append('action','submit_ticket');
  body.append('_csrf', CSRF);

  fetch('support.php', { method:'POST', body, headers:{'X-Requested-With':'XMLHttpRequest'} })
    .then(r => r.json()).then(d => {
      if (d.error) { showToast(d.error,'error'); }
      else {
        showToast(d.message || 'Tiket berhasil dikirim!','success');
        form.reset();
        toggleSubmitForm();
        loadTickets();
      }
    }).finally(() => { btn.disabled=false; btn.textContent='Kirim Tiket →'; });
}

// ── Send reply ───────────────────────────────────────
function sendReply() {
  const msg = document.getElementById('replyMsg')?.value?.trim();
  if (!msg) { showToast('Pesan tidak boleh kosong.','error'); return; }
  const btn = document.getElementById('replyBtn');
  if (btn) { btn.disabled = true; btn.textContent = 'Mengirim…'; }

  const body = new FormData();
  body.append('action','reply');
  body.append('_csrf', CSRF);
  body.append('ticket_id', _currentTicketId);
  body.append('message', msg);

  fetch('support.php', { method:'POST', body, headers:{'X-Requested-With':'XMLHttpRequest'} })
    .then(r => r.json()).then(d => {
      if (d.error) showToast(d.error,'error');
      else openThread(_currentTicketId); // reload thread
    })
    .catch(err => showToast('Gagal kirim balasan. Cek koneksi.','error'))
    .finally(() => { if (btn) { btn.disabled=false; btn.textContent='Kirim Balasan'; } });
}

// ── Close ticket with rating ─────────────────────────
function closeTicket() {
  const rating  = document.getElementById('starValue')?.value || 5;
  const comment = document.getElementById('ratingComment')?.value || '';
  _doCloseTicket(rating, comment);
}
function closeTicketNoRating() { _doCloseTicket('', ''); }

function _doCloseTicket(rating, comment) {
  // Disable all close buttons to prevent double-submit
  document.querySelectorAll('[data-close-btn]').forEach(b => { b.disabled = true; });

  const body = new FormData();
  body.append('action','close_ticket');
  body.append('_csrf', CSRF);
  body.append('ticket_id', _currentTicketId);
  body.append('rating', rating);
  body.append('rating_comment', comment);

  fetch('support.php', { method:'POST', body, headers:{'X-Requested-With':'XMLHttpRequest'} })
    .then(r => r.json()).then(d => {
      if (d.error) showToast(d.error,'error');
      else {
        showToast('Tiket berhasil ditutup. Terima kasih!','success');
        document.getElementById('threadOverlay').classList.remove('open');
        loadTickets();
      }
    })
    .catch(err => showToast('Gagal tutup tiket. Cek koneksi.','error'))
    .finally(() => {
      document.querySelectorAll('[data-close-btn]').forEach(b => { b.disabled = false; });
    });
}

// ── Toast helper ─────────────────────────────────────
function showToast(msg, type='success') {
  const el = document.getElementById('toast');
  if (!el) return;
  el.textContent = msg;
  el.className = 'hl-toast show' + (type==='error' ? ' error' : '');
  clearTimeout(el._t);
  el._t = setTimeout(() => el.className='hl-toast', 3000);
}

loadTickets();
</script>
</body>
</html>

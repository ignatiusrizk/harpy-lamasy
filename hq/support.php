<?php
// ══════════════════════════════════════════════════════
// hq/support.php — Bantuan & Support Tiket (HQ / Tenant-wide View)
//
// Beda dgn /support (outlet view):
//   - List SEMUA tiket lintas outlet dalam tenant
//   - Filter by outlet + status
//   - Submit form punya selector outlet ("Semua Outlet / Tenant-wide" = NULL)
//   - Layout pakai hq_guard + HQ sidebar
// ══════════════════════════════════════════════════════

$activePage = 'hq-support';
$pageTitle  = 'Bantuan & Support';
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';

date_default_timezone_set('Asia/Jakarta');

$db       = Database::get();
$tenantId = (int)$hqTenant['id'];
$userId   = (int)($_SESSION['user_id'] ?? 0);

// ── JSON API ──────────────────────────────────────────
$action = $_REQUEST['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');

    // ── list_tickets ──────────────────────────────────
    if ($action === 'list_tickets') {
        $outletFilter = (int)($_GET['outlet_id'] ?? 0);
        $sql = "SELECT st.*,
                       o.nama_outlet,
                       sa.name AS assigned_nama
                FROM support_tickets st
                LEFT JOIN outlets o ON o.id = st.outlet_id
                LEFT JOIN super_admins sa ON sa.id = st.assigned_to
                WHERE st.tenant_id = ?";
        $params = [$tenantId];
        if ($outletFilter > 0) {
            $sql .= " AND st.outlet_id = ?";
            $params[] = $outletFilter;
        } elseif ($outletFilter === -1) {
            // -1 = tenant-wide only (outlet_id IS NULL)
            $sql .= " AND st.outlet_id IS NULL";
        }
        $sql .= " ORDER BY FIELD(st.status,'open','in_progress','waiting_tenant','resolved','closed'),
                           st.updated_at DESC LIMIT 100";
        $st = $db->prepare($sql);
        $st->execute($params);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // ── get_thread ────────────────────────────────────
    if ($action === 'get_thread') {
        $tid = (int)($_GET['id'] ?? 0);
        $ts = $db->prepare(
            "SELECT st.*, o.nama_outlet
               FROM support_tickets st
               LEFT JOIN outlets o ON o.id = st.outlet_id
              WHERE st.id=? AND st.tenant_id=? LIMIT 1"
        );
        $ts->execute([$tid, $tenantId]);
        $ticket = $ts->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) { echo json_encode(['error'=>'Tiket tidak ditemukan.']); exit; }

        $rs = $db->prepare(
            "SELECT r.*, sa.name AS sa_nama, u.nama AS user_nama
             FROM support_ticket_replies r
             LEFT JOIN super_admins sa ON sa.id = r.superadmin_id
             LEFT JOIN hl_users u ON u.id = r.user_id AND u.tenant_id = r.tenant_id
             WHERE r.ticket_id = ? AND r.is_internal = 0
             ORDER BY r.created_at ASC"
        );
        $rs->execute([$tid]);
        echo json_encode(['ticket' => $ticket, 'replies' => $rs->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── submit_ticket ────────────────────────────────
    if ($action === 'submit_ticket' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // HQ guard sudah enforce CSRF via session — pakai token check minimal
        $csrf = $_POST['_csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
            echo json_encode(['error' => 'CSRF mismatch']); exit;
        }

        $subject  = substr(trim($_POST['subject'] ?? ''), 0, 200);
        $message  = trim($_POST['message'] ?? '');
        $category = in_array($_POST['category'] ?? '', ['billing','teknis','fitur','akun','lainnya'])
                    ? $_POST['category'] : 'lainnya';
        $outletPick = $_POST['outlet_id'] ?? '';
        $outletId = ($outletPick === '' || $outletPick === '0') ? null : (int)$outletPick;

        if (!$subject || !$message) {
            echo json_encode(['error'=>'Subject dan pesan wajib diisi.']); exit;
        }
        // Verify outlet belongs to tenant (kalau bukan NULL)
        if ($outletId) {
            $vo = $db->prepare("SELECT id FROM outlets WHERE id=? AND tenant_id=? LIMIT 1");
            $vo->execute([$outletId, $tenantId]);
            if (!$vo->fetchColumn()) { echo json_encode(['error'=>'Outlet tidak valid.']); exit; }
        }

        $db->prepare(
            "INSERT INTO support_tickets
               (tenant_id, outlet_id, submitted_by, subject, message, category, status, created_at, updated_at)
             VALUES (?,?,?,?,?,?,'open',NOW(),NOW())"
        )->execute([$tenantId, $outletId, $userId, $subject, $message, $category]);
        $ticketId = (int)$db->lastInsertId();

        try {
            require_once ROOT . '/core/SaNotifier.php';
            SaNotifier::supportTicketCreated($ticketId);
        } catch (Throwable $e) { error_log('[SaNotify supportTicket HQ] ' . $e->getMessage()); }

        echo json_encode([
            'success'   => true,
            'ticket_id' => $ticketId,
            'message'   => "Tiket #$ticketId berhasil dikirim. Tim kami akan merespons segera.",
        ]);
        exit;
    }

    // ── reply ─────────────────────────────────────────
    if ($action === 'reply' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['_csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
            echo json_encode(['error' => 'CSRF mismatch']); exit;
        }
        $tid     = (int)($_POST['ticket_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');
        if (!$tid || !$message) { echo json_encode(['error'=>'Data tidak lengkap.']); exit; }

        $ts = $db->prepare("SELECT status FROM support_tickets WHERE id=? AND tenant_id=? LIMIT 1");
        $ts->execute([$tid, $tenantId]);
        $trow = $ts->fetch(PDO::FETCH_ASSOC);
        if (!$trow) { echo json_encode(['error'=>'Tiket tidak ditemukan.']); exit; }
        if ($trow['status'] === 'closed') { echo json_encode(['error'=>'Tiket sudah ditutup.']); exit; }

        $db->prepare(
            "INSERT INTO support_ticket_replies (ticket_id, user_id, message, is_internal)
             VALUES (?,?,?,0)"
        )->execute([$tid, $userId, $message]);

        if ($trow['status'] === 'waiting_tenant') {
            $db->prepare("UPDATE support_tickets SET status='in_progress', updated_at=NOW() WHERE id=?")->execute([$tid]);
        } else {
            $db->prepare("UPDATE support_tickets SET updated_at=NOW() WHERE id=?")->execute([$tid]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // ── close_ticket ──────────────────────────────────
    if ($action === 'close_ticket' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['_csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
            echo json_encode(['error' => 'CSRF mismatch']); exit;
        }
        $tid    = (int)($_POST['ticket_id'] ?? 0);
        $rating = max(1, min(5, (int)($_POST['rating'] ?? 0)));
        $remark = substr(trim($_POST['rating_comment'] ?? ''), 0, 500);

        $ts = $db->prepare("SELECT status FROM support_tickets WHERE id=? AND tenant_id=? LIMIT 1");
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

    echo json_encode(['error' => 'Action tidak dikenal.']);
    exit;
}

// ── Outlets untuk dropdown filter & submit form ──
$outletList = $db->prepare(
    "SELECT id, nama_outlet FROM outlets
      WHERE tenant_id=? AND status!='closed' ORDER BY is_main DESC, nama_outlet ASC"
);
$outletList->execute([$tenantId]);
$outletList = $outletList->fetchAll(PDO::FETCH_ASSOC);

// Ensure CSRF token exists
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrfToken = $_SESSION['csrf_token'];

require __DIR__ . '/_layout_open.php';
?>

<style>
.t-badge-open           { background:#FEF3C7;color:#92400E;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600 }
.t-badge-in_progress    { background:#DBEAFE;color:#1D4ED8;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600 }
.t-badge-waiting_tenant { background:#EDE9FE;color:#5B21B6;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600 }
.t-badge-resolved       { background:#D1FAE5;color:#065F46;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600 }
.t-badge-closed         { background:#F3F4F6;color:#6B7280;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600 }
.p-badge-low     { background:#F3F4F6;color:#6B7280;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600 }
.p-badge-normal  { background:#DBEAFE;color:#1D4ED8;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600 }
.p-badge-high    { background:#FEF3C7;color:#92400E;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600 }
.p-badge-critical{ background:#FEE2E2;color:#991B1B;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600 }
.cat-chip{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;background:#E0F2FE;color:#075985}
.outlet-chip{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;background:#F3E8FF;color:#6B21A8}
.outlet-chip.tenant-wide{background:#E0E7FF;color:#3730A3}

.submit-toggle{display:flex;align-items:center;justify-content:space-between;width:100%;gap:12px;cursor:pointer;user-select:none}
.submit-form-body{margin-top:16px}
.submit-form-body.hidden{display:none}

.t-row{cursor:pointer;transition:background .12s}
.t-row:hover td{background:rgba(99,102,241,.04)}
.t-subject{font-weight:600;color:#0F1C3A;font-size:13.5px}
.t-meta{font-size:11.5px;color:#6B7280;margin-top:2px}
.t-time{font-size:12px;color:#6B7280;white-space:nowrap}

.hq-card{background:#fff;border:1px solid #E5E9F2;border-radius:12px;padding:18px;margin-bottom:18px}
.hq-card-head{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid #F0F1F4}
@media(max-width:640px){.tk-filter{width:100%}.tk-filter .hq-select{flex:1 1 0;min-width:0;width:auto!important}}
.hq-card-title{font-size:15px;font-weight:700;color:#0F1C3A}

.hq-form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
.hq-form-group{display:flex;flex-direction:column;gap:6px;margin-bottom:12px}
.hq-label{font-size:12px;font-weight:600;color:#374151}
.hq-input,.hq-select,.hq-textarea{padding:8px 11px;border:1px solid #E5E9F2;border-radius:8px;font-size:13px;font-family:inherit;width:100%;box-sizing:border-box;background:#fff}
.hq-input:focus,.hq-select:focus,.hq-textarea:focus{outline:none;border-color:#0F1C3A}
.hq-textarea{resize:vertical;min-height:80px}

.hq-btn{padding:8px 14px;border-radius:8px;font-weight:600;font-size:12.5px;border:1px solid transparent;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:5px;height:36px;box-sizing:border-box;line-height:1;transition:all .15s}
.hq-btn-primary{background:#0F1C3A;color:#fff;border-color:#0F1C3A}.hq-btn-primary:hover{background:#1a2d52}
.hq-btn-outline{background:#fff;color:#0F1C3A;border-color:#E5E9F2}.hq-btn-outline:hover{background:#F7F8FC}
.hq-btn-green{background:#10B981;color:#fff;border-color:#10B981}.hq-btn-green:hover{background:#059669}
.hq-btn-sm{padding:6px 12px;font-size:12px;height:32px}

.hq-table{width:100%;border-collapse:collapse}
.hq-table th{background:#F7F8FC;padding:9px 11px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;color:#6B7280}
.hq-table td{padding:10px 11px;border-top:1px solid #F0F1F4}

/* Thread modal */
.hq-modal-bg{display:none;position:fixed;inset:0;background:rgba(15,28,58,.5);z-index:1000;align-items:flex-start;justify-content:center;padding:40px 16px;overflow-y:auto}
.hq-modal-bg.open{display:flex}
.hq-modal{background:#fff;border-radius:14px;width:100%;max-width:680px;padding:24px}
.hq-modal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid #F0F1F4}
.hq-modal-close{background:none;border:none;font-size:20px;cursor:pointer;color:#6B7280}

.thread-wrap{display:flex;flex-direction:column;gap:12px;padding:4px 0 8px}
.bubble{max-width:80%;display:flex;flex-direction:column;gap:4px}
.bubble.admin{align-self:flex-start}
.bubble.tenant{align-self:flex-end}
.bubble-head{font-size:11px;font-weight:700;color:#6B7280}
.bubble.tenant .bubble-head{text-align:right}
.bubble-body{padding:10px 14px;border-radius:12px;font-size:13.5px;line-height:1.55;white-space:pre-wrap;word-break:break-word}
.bubble.admin .bubble-body{background:#F7F8FC;border:1px solid #E5E9F2;color:#0F1C3A;border-top-left-radius:4px}
.bubble.tenant .bubble-body{background:#0F1C3A;color:#fff;border-top-right-radius:4px}
.bubble-time{font-size:10.5px;color:#6B7280}
.bubble.tenant .bubble-time{text-align:right}

.t-detail-bar{display:flex;flex-wrap:wrap;gap:6px;align-items:center;padding-bottom:12px;border-bottom:1px solid #F0F1F4;margin-bottom:12px}
.reply-area{border-top:1px solid #F0F1F4;padding-top:16px;margin-top:12px}
.thread-empty{text-align:center;padding:28px;color:#6B7280;font-size:13px}
.star-row{display:flex;gap:4px;margin-top:4px}
.star{font-size:24px;cursor:pointer;line-height:1;filter:grayscale(1);transition:filter .1s}
.star.active,.star:hover{filter:none}

.hq-toast{position:fixed;bottom:24px;right:24px;background:#10B981;color:#fff;padding:12px 18px;border-radius:10px;font-weight:600;font-size:13px;opacity:0;transform:translateY(20px);transition:all .2s;z-index:2000;pointer-events:none}
.hq-toast.show{opacity:1;transform:translateY(0)}
.hq-toast.error{background:#EF4444}
</style>

<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>"/>

<h1 style="font-size:22px;font-weight:700;color:#0F1C3A;margin-bottom:4px">🎧 Bantuan & Support — HQ View</h1>
<p style="color:#6B7280;font-size:13px;margin-bottom:18px">Tiket dari semua outlet dalam tenant — submit di level tenant untuk masalah billing/akun, atau pilih outlet spesifik.</p>

<!-- SUBMIT TIKET -->
<div class="hq-card" id="submitCard">
  <div class="hq-card-head">
    <div class="submit-toggle" onclick="toggleSubmitForm()">
      <span class="hq-card-title">✉️ Buat Tiket Baru</span>
      <button type="button" class="hq-btn hq-btn-primary hq-btn-sm" id="submitToggleBtn"
              onclick="event.stopPropagation();toggleSubmitForm()">+ Buat Tiket</button>
    </div>
  </div>
  <div class="submit-form-body hidden" id="submitFormBody">
    <form id="submitTicketForm" onsubmit="submitTicket(event)">
      <div class="hq-form-row">
        <div class="hq-form-group">
          <label class="hq-label">Outlet Terkait</label>
          <select name="outlet_id" class="hq-select">
            <option value="">🏢 Tenant-wide (Billing / Akun / Umum)</option>
            <?php foreach ($outletList as $o): ?>
            <option value="<?= (int)$o['id'] ?>">🏪 <?= htmlspecialchars($o['nama_outlet']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="hq-form-group">
          <label class="hq-label">Kategori</label>
          <select name="category" class="hq-select" required>
            <option value="teknis">🔧 Teknis — Bug / Error</option>
            <option value="billing">💳 Billing — Coin / Pembayaran</option>
            <option value="fitur">💡 Fitur — Cara Penggunaan</option>
            <option value="akun">👤 Akun — Password / User</option>
            <option value="lainnya">📩 Lainnya</option>
          </select>
        </div>
      </div>
      <div class="hq-form-group">
        <label class="hq-label">Subject *</label>
        <input type="text" name="subject" class="hq-input" required maxlength="200"
               placeholder="Deskripsikan masalah secara singkat"/>
      </div>
      <div class="hq-form-group">
        <label class="hq-label">Detail Masalah *</label>
        <textarea name="message" class="hq-textarea" rows="4" required
                  placeholder="Jelaskan masalah selengkap mungkin — langkah, error, dll."></textarea>
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button type="button" class="hq-btn hq-btn-outline hq-btn-sm" onclick="toggleSubmitForm()">Batal</button>
        <button type="submit" class="hq-btn hq-btn-primary hq-btn-sm" id="submitBtn">Kirim Tiket →</button>
      </div>
    </form>
  </div>
</div>

<!-- LIST TIKET -->
<div class="hq-card">
  <div class="hq-card-head">
    <span class="hq-card-title">📋 Tiket — Semua Outlet</span>
    <div class="tk-filter" style="display:flex;gap:8px;align-items:center">
      <select id="filterOutlet" class="hq-select" style="width:auto;font-size:12px;height:32px;padding:6px 10px" onchange="loadTickets()">
        <option value="0">Semua Outlet</option>
        <option value="-1">🏢 Tenant-wide saja</option>
        <?php foreach ($outletList as $o): ?>
        <option value="<?= (int)$o['id'] ?>"><?= htmlspecialchars($o['nama_outlet']) ?></option>
        <?php endforeach; ?>
      </select>
      <select id="filterStatus" class="hq-select" style="width:auto;font-size:12px;height:32px;padding:6px 10px" onchange="renderFiltered()">
        <option value="">Semua Status</option>
        <option value="open">Open</option>
        <option value="in_progress">In Progress</option>
        <option value="waiting_tenant">Waiting Respons</option>
        <option value="resolved">Resolved</option>
        <option value="closed">Closed</option>
      </select>
    </div>
  </div>
  <div style="overflow-x:auto">
    <table class="hq-table" id="ticketTable">
      <thead>
        <tr>
          <th style="width:52px">ID</th>
          <th>Subject</th>
          <th style="width:140px">Outlet</th>
          <th style="width:100px">Kategori</th>
          <th style="width:100px">Status</th>
          <th style="width:90px">Terakhir</th>
        </tr>
      </thead>
      <tbody id="ticketBody">
        <tr><td colspan="6" style="text-align:center;padding:28px;color:#6B7280">⏳ Memuat…</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- THREAD MODAL -->
<div class="hq-modal-bg" id="threadOverlay" onclick="closeThread(event)">
  <div class="hq-modal" onclick="event.stopPropagation()">
    <div class="hq-modal-head">
      <span class="hq-card-title" id="threadTitle">Detail Tiket</span>
      <button class="hq-modal-close" onclick="closeThread()">✕</button>
    </div>
    <div id="threadBody" style="max-height:68vh;overflow-y:auto">
      <div style="text-align:center;padding:32px;color:#6B7280">Memuat…</div>
    </div>
  </div>
</div>

<div class="hq-toast" id="toast"></div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
let _rawRows = [];

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

let _submitOpen = false;
function toggleSubmitForm(){
  _submitOpen = !_submitOpen;
  document.getElementById('submitFormBody').classList.toggle('hidden', !_submitOpen);
  document.getElementById('submitToggleBtn').textContent = _submitOpen ? '✕ Batal' : '+ Buat Tiket';
}

function loadTickets(){
  const outlet = document.getElementById('filterOutlet').value;
  const tbody = document.getElementById('ticketBody');
  tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:28px;color:#6B7280">⏳ Memuat tiket…</td></tr>';
  fetch('/hq/support.php?action=list_tickets&outlet_id=' + outlet, { headers:{'X-Requested-With':'XMLHttpRequest'} })
    .then(r => r.json()).then(rows => { _rawRows = rows || []; renderFiltered(); })
    .catch(err => {
      tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:28px;color:#EF4444">
        ⚠️ Gagal load tiket. <button onclick="loadTickets()" style="color:#3B82F6;background:none;border:none;text-decoration:underline;cursor:pointer">Coba lagi</button>
      </td></tr>`;
    });
}

function renderFiltered(){
  const fs = document.getElementById('filterStatus').value;
  const rows = fs ? _rawRows.filter(r => r.status === fs) : _rawRows;
  const tbody = document.getElementById('ticketBody');
  if (!rows.length){
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:28px;color:#6B7280">Belum ada tiket sesuai filter.</td></tr>';
    return;
  }
  tbody.innerHTML = rows.map(r => {
    const ageH = (Date.now() - new Date(r.created_at).getTime()) / 3600000;
    const slaWarn = r.status === 'open' && ageH > 24
      ? '<span title="Tiket >24 jam belum direspons" style="color:#EF4444;font-size:10px;margin-left:4px">⏱ Lama</span>' : '';
    const outletBadge = r.outlet_id
      ? `<span class="outlet-chip">🏪 ${esc(r.nama_outlet || '#'+r.outlet_id)}</span>`
      : '<span class="outlet-chip tenant-wide">🏢 Tenant-wide</span>';
    return `<tr class="t-row" onclick="openThread(${r.id})">
      <td style="font-family:monospace;font-size:12px;color:#6B7280">#${r.id}</td>
      <td>
        <div class="t-subject">${esc(r.subject)}${slaWarn}</div>
        <div class="t-meta">${r.assigned_nama ? 'Ditangani oleh ' + esc(r.assigned_nama) : 'Belum di-assign'}</div>
      </td>
      <td>${outletBadge}</td>
      <td><span class="cat-chip">${catIcon[r.category]||'📩'} ${catLabel[r.category]||r.category}</span></td>
      <td><span class="t-badge-${r.status}">${statusLabel[r.status]||r.status}</span></td>
      <td class="t-time">${fmtAgo(r.updated_at||r.created_at)}</td>
    </tr>`;
  }).join('');
}

let _currentTicketId = null;
function openThread(ticketId){
  _currentTicketId = ticketId;
  document.getElementById('threadOverlay').classList.add('open');
  document.getElementById('threadBody').innerHTML = '<div style="text-align:center;padding:32px;color:#6B7280">Memuat…</div>';
  fetch(`/hq/support.php?action=get_thread&id=${ticketId}`, { headers:{'X-Requested-With':'XMLHttpRequest'} })
    .then(r => r.json()).then(d => {
      if (d.error) { showToast(d.error,'error'); return; }
      renderThread(d.ticket, d.replies);
    });
}

function closeThread(e){
  if (e && e.target !== document.getElementById('threadOverlay')) return;
  document.getElementById('threadOverlay').classList.remove('open');
  _currentTicketId = null;
  loadTickets();
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeThread(); });

function renderThread(ticket, replies){
  document.getElementById('threadTitle').textContent = `Tiket #${ticket.id} — ${ticket.subject}`;
  const isResolved = ticket.status === 'resolved';
  const isClosed   = ticket.status === 'closed';
  const myName     = <?= json_encode($hqUser['nama'] ?? 'Kamu') ?>;
  const outletInfo = ticket.outlet_id
    ? `<span class="outlet-chip">🏪 ${esc(ticket.nama_outlet || '#'+ticket.outlet_id)}</span>`
    : '<span class="outlet-chip tenant-wide">🏢 Tenant-wide</span>';

  const detailBar = `
    <div class="t-detail-bar">
      <span class="t-badge-${ticket.status}">${statusLabel[ticket.status]||ticket.status}</span>
      <span class="p-badge-${ticket.priority}">${priLabel[ticket.priority]||ticket.priority}</span>
      <span class="cat-chip">${catIcon[ticket.category]||'📩'} ${catLabel[ticket.category]||ticket.category}</span>
      ${outletInfo}
      <span style="font-size:12px;color:#6B7280;margin-left:auto">Dibuat ${fmtDateTime(ticket.created_at)}</span>
    </div>`;

  let bubblesHtml = '';
  if (!replies || !replies.length){
    bubblesHtml = `<div style="background:#F7F8FC;border:1px solid #E5E9F2;border-radius:10px;padding:14px;margin-bottom:12px;font-size:13.5px;color:#0F1C3A;white-space:pre-wrap">${esc(ticket.message)}</div>
      <div class="thread-empty">💬 Belum ada balasan. Tim kami akan segera merespons.</div>`;
  } else {
    bubblesHtml = '<div class="thread-wrap">';
    bubblesHtml += `
      <div class="bubble tenant">
        <div class="bubble-head">Kamu · pesan awal</div>
        <div class="bubble-body">${esc(ticket.message)}</div>
        <div class="bubble-time">${fmtDateTime(ticket.created_at)}</div>
      </div>`;
    replies.forEach(r => {
      const isAdmin = !!r.superadmin_id;
      const sender  = isAdmin ? (r.sa_nama || 'Tim LAMASY') : (r.user_nama || myName);
      bubblesHtml += `
        <div class="bubble ${isAdmin ? 'admin' : 'tenant'}">
          <div class="bubble-head">${esc(sender)}</div>
          <div class="bubble-body">${esc(r.message)}</div>
          <div class="bubble-time">${fmtDateTime(r.created_at)}</div>
        </div>`;
    });
    bubblesHtml += '</div>';
  }

  let replyHtml = '';
  if (!isClosed && !isResolved){
    replyHtml = `
      <div class="reply-area">
        <div style="font-size:12px;font-weight:700;color:#6B7280;margin-bottom:8px">Tulis Balasan</div>
        <textarea id="replyMsg" class="hq-textarea" rows="3" placeholder="Tulis pesan untuk tim support…" style="margin-bottom:8px"></textarea>
        <div style="display:flex;gap:8px;justify-content:flex-end">
          <button id="replyBtn" class="hq-btn hq-btn-primary hq-btn-sm" onclick="sendReply()">Kirim Balasan →</button>
        </div>
      </div>`;
  }

  let closeHtml = '';
  if (isResolved){
    closeHtml = `
      <div class="reply-area" style="background:#F0FDF4;border:1px solid #6EE7B7;border-radius:10px;padding:16px">
        <div style="font-weight:700;color:#065F46;margin-bottom:6px">✅ Masalah sudah diselesaikan?</div>
        <div style="font-size:13px;color:#374151;margin-bottom:12px">Beri rating untuk membantu kami meningkatkan layanan.</div>
        <div style="margin-bottom:10px">
          <div style="font-size:12px;font-weight:700;color:#6B7280;margin-bottom:4px">Rating Layanan</div>
          <div class="star-row" id="starRow">${[1,2,3,4,5].map(i=>`<span class="star" data-v="${i}" onclick="setStar(${i})">⭐</span>`).join('')}</div>
          <input type="hidden" id="starValue" value="5"/>
        </div>
        <textarea id="ratingComment" class="hq-textarea" rows="2" placeholder="Komentar (opsional)…" style="margin-bottom:10px"></textarea>
        <div style="display:flex;gap:8px">
          <button data-close-btn class="hq-btn hq-btn-green hq-btn-sm" onclick="closeTicket()">✅ Tandai Selesai & Kirim Rating</button>
          <button data-close-btn class="hq-btn hq-btn-outline hq-btn-sm" onclick="closeTicketNoRating()">Tutup Tanpa Rating</button>
        </div>
      </div>`;
  }

  let closedHtml = '';
  if (isClosed){
    closedHtml = `<div style="background:#F7F8FC;border:1px solid #E5E9F2;border-radius:10px;padding:14px;text-align:center;font-size:13px;color:#6B7280;margin-top:12px">
      🔒 Tiket ini sudah ditutup.
      ${ticket.rating ? '<br>Rating kamu: ' + '⭐'.repeat(parseInt(ticket.rating)) + ' ('+ticket.rating+'/5)' : ''}
    </div>`;
  }

  document.getElementById('threadBody').innerHTML = detailBar + bubblesHtml + replyHtml + closeHtml + closedHtml;
  if (isResolved) setStar(5);
}

function setStar(v){
  document.getElementById('starValue').value = v;
  document.querySelectorAll('.star').forEach(s => s.classList.toggle('active', parseInt(s.dataset.v) <= v));
}

function submitTicket(e){
  e.preventDefault();
  const btn = document.getElementById('submitBtn');
  const form = e.target;
  btn.disabled = true; btn.textContent = 'Mengirim…';
  const body = new FormData(form);
  body.append('action','submit_ticket');
  body.append('_csrf', CSRF);
  fetch('/hq/support.php', { method:'POST', body, headers:{'X-Requested-With':'XMLHttpRequest'} })
    .then(r => r.json()).then(d => {
      if (d.error) showToast(d.error,'error');
      else {
        showToast(d.message || 'Tiket berhasil dikirim!','success');
        form.reset(); toggleSubmitForm(); loadTickets();
      }
    }).finally(() => { btn.disabled=false; btn.textContent='Kirim Tiket →'; });
}

function sendReply(){
  const msg = document.getElementById('replyMsg')?.value?.trim();
  if (!msg){ showToast('Pesan tidak boleh kosong.','error'); return; }
  const btn = document.getElementById('replyBtn');
  if (btn){ btn.disabled = true; btn.textContent = 'Mengirim…'; }
  const body = new FormData();
  body.append('action','reply');
  body.append('_csrf', CSRF);
  body.append('ticket_id', _currentTicketId);
  body.append('message', msg);
  fetch('/hq/support.php', { method:'POST', body, headers:{'X-Requested-With':'XMLHttpRequest'} })
    .then(r => r.json()).then(d => {
      if (d.error) showToast(d.error,'error');
      else openThread(_currentTicketId);
    })
    .catch(() => showToast('Gagal kirim balasan.','error'))
    .finally(() => { if (btn){ btn.disabled=false; btn.textContent='Kirim Balasan →'; } });
}

function closeTicket(){
  _doClose(document.getElementById('starValue')?.value || 5, document.getElementById('ratingComment')?.value || '');
}
function closeTicketNoRating(){ _doClose('', ''); }
function _doClose(rating, comment){
  document.querySelectorAll('[data-close-btn]').forEach(b => b.disabled = true);
  const body = new FormData();
  body.append('action','close_ticket');
  body.append('_csrf', CSRF);
  body.append('ticket_id', _currentTicketId);
  body.append('rating', rating);
  body.append('rating_comment', comment);
  fetch('/hq/support.php', { method:'POST', body, headers:{'X-Requested-With':'XMLHttpRequest'} })
    .then(r => r.json()).then(d => {
      if (d.error) showToast(d.error,'error');
      else {
        showToast('Tiket ditutup. Terima kasih!','success');
        document.getElementById('threadOverlay').classList.remove('open');
        loadTickets();
      }
    })
    .catch(() => showToast('Gagal tutup tiket.','error'))
    .finally(() => document.querySelectorAll('[data-close-btn]').forEach(b => b.disabled = false));
}

function showToast(msg, type='success'){
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.className = 'hq-toast show' + (type==='error' ? ' error' : '');
  clearTimeout(el._t);
  el._t = setTimeout(() => el.className='hq-toast', 3000);
}

loadTickets();
</script>

<?php require __DIR__ . '/_layout_close.php'; ?>

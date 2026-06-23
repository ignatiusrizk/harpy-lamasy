<?php
// ══════════════════════════════════════════════════════
// superadmin/support.php — Inbox Tiket Support
// ══════════════════════════════════════════════════════
if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';
require_once SA_ROOT . '/../core/SaPermission.php';

date_default_timezone_set('Asia/Jakarta');

$saAdmin = saCurrentAdmin();
$saId    = (int)($_SESSION['superadmin_id'] ?? 0);
$db      = Database::get();

$action = $_GET['action'] ?? '';

if ($action) {
    header('Content-Type: application/json');

    // ── stats ────────────────────────────────────────
    if ($action === 'stats') {
        $open  = (int)$db->query("SELECT COUNT(*) FROM support_tickets WHERE status='open'")->fetchColumn();
        $prog  = (int)$db->query("SELECT COUNT(*) FROM support_tickets WHERE status='in_progress'")->fetchColumn();
        $wait  = (int)$db->query("SELECT COUNT(*) FROM support_tickets WHERE status='waiting_tenant'")->fetchColumn();
        $today = (int)$db->query("SELECT COUNT(*) FROM support_tickets WHERE status IN ('resolved','closed') AND DATE(resolved_at)=CURDATE()")->fetchColumn();

        // Avg first-response time (tickets responded today, in minutes)
        $avgRes = $db->query(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, first_response_at)) AS avg_min
             FROM support_tickets WHERE first_response_at IS NOT NULL AND DATE(first_response_at)=CURDATE()"
        )->fetchColumn();

        // SLA alerts: open > 6h and open > 24h
        $sla6h  = (int)$db->query("SELECT COUNT(*) FROM support_tickets WHERE status='open' AND created_at < DATE_SUB(NOW(),INTERVAL 6 HOUR)")->fetchColumn();
        $sla24h = (int)$db->query("SELECT COUNT(*) FROM support_tickets WHERE status='open' AND created_at < DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetchColumn();

        // Avg rating this month
        $avgRating = $db->query(
            "SELECT ROUND(AVG(rating),1) FROM support_tickets WHERE rating IS NOT NULL AND MONTH(closed_at)=MONTH(NOW())"
        )->fetchColumn();

        echo json_encode(compact('open','prog','wait','today','avgRes','sla6h','sla24h','avgRating'));
        exit;
    }

    // ── list ─────────────────────────────────────────
    if ($action === 'list') {
        $status   = $_GET['status']   ?? '';
        $category = $_GET['category'] ?? '';
        $priority = $_GET['priority'] ?? '';
        $assigned = $_GET['assigned'] ?? '';
        $search   = trim($_GET['search'] ?? '');
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $limit    = 30;
        $offset   = ($page - 1) * $limit;

        $where  = ['1=1'];
        $params = [];

        if ($status)   { $where[] = 'st.status=?';   $params[] = $status; }
        if ($category) { $where[] = 'st.category=?'; $params[] = $category; }
        if ($priority) { $where[] = 'st.priority=?'; $params[] = $priority; }
        if ($assigned === 'me')  { $where[] = 'st.assigned_to=?'; $params[] = $saId; }
        elseif ($assigned === 'unassigned') { $where[] = 'st.assigned_to IS NULL'; }
        if ($search) {
            $where[] = '(t.nama_perusahaan LIKE ? OR t.owner_name LIKE ? OR st.subject LIKE ?)';
            $like = "%$search%";
            array_push($params, $like, $like, $like);
        }
        $w = implode(' AND ', $where);

        $cnt = $db->prepare("SELECT COUNT(*) FROM support_tickets st LEFT JOIN tenants t ON t.id=st.tenant_id WHERE $w");
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        $rows = $db->prepare(
            "SELECT st.*,
                    t.nama_perusahaan AS nama_outlet, t.owner_name, t.owner_wa,
                    sa.name AS assigned_nama,
                    TIMESTAMPDIFF(HOUR, st.created_at, NOW()) AS age_hours,
                    (SELECT COUNT(*) FROM support_ticket_replies r WHERE r.ticket_id=st.id) AS reply_count
             FROM support_tickets st
             LEFT JOIN tenants t ON t.id = st.tenant_id
             LEFT JOIN super_admins sa ON sa.id = st.assigned_to
             WHERE $w
             ORDER BY
               FIELD(st.status,'open','in_progress','waiting_tenant','resolved','closed'),
               FIELD(st.priority,'critical','high','normal','low'),
               st.updated_at DESC
             LIMIT $limit OFFSET $offset"
        );
        $rows->execute($params);

        echo json_encode([
            'rows'  => $rows->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'pages' => max(1,(int)ceil($total/$limit)),
            'page'  => $page,
        ]);
        exit;
    }

    // ── get_thread ────────────────────────────────────
    if ($action === 'get_thread') {
        $tid = (int)($_GET['id'] ?? 0);

        $ts = $db->prepare(
            "SELECT st.*, t.nama_perusahaan AS nama_outlet, t.owner_name, t.owner_wa,
                    sa.name AS assigned_nama
             FROM support_tickets st
             LEFT JOIN tenants t ON t.id=st.tenant_id
             LEFT JOIN super_admins sa ON sa.id=st.assigned_to
             WHERE st.id=? LIMIT 1"
        );
        $ts->execute([$tid]);
        $ticket = $ts->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) { echo json_encode(['error'=>'Tiket tidak ditemukan.']); exit; }

        // All replies including internal (superadmin can see all)
        $rs = $db->prepare(
            "SELECT r.*,
                    sa.name AS sa_nama,
                    u.nama AS user_nama
             FROM support_ticket_replies r
             LEFT JOIN super_admins sa ON sa.id = r.superadmin_id
             LEFT JOIN hl_users u ON u.id = r.user_id
             WHERE r.ticket_id = ?
             ORDER BY r.created_at ASC"
        );
        $rs->execute([$tid]);
        $replies = $rs->fetchAll(PDO::FETCH_ASSOC);

        // List of superadmins for assign dropdown
        $admins = $db->query("SELECT id, name FROM super_admins WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['ticket'=>$ticket, 'replies'=>$replies, 'admins'=>$admins]);
        exit;
    }

    // ── get_superadmins ───────────────────────────────
    if ($action === 'get_superadmins') {
        echo json_encode($db->query("SELECT id, name FROM super_admins WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // ────── POST actions ──────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        saVerifyCsrf();

        // ── reply ─────────────────────────────────────
        if ($action === 'reply') {
            SaPermission::require('support.reply');
            $tid        = (int)($_POST['ticket_id'] ?? 0);
            $message    = trim($_POST['message'] ?? '');
            $isInternal = (int)($_POST['is_internal'] ?? 0);

            if (!$tid || !$message) { echo json_encode(['error'=>'Data tidak lengkap.']); exit; }

            $ts = $db->prepare("SELECT * FROM support_tickets WHERE id=? LIMIT 1");
            $ts->execute([$tid]);
            $ticket = $ts->fetch(PDO::FETCH_ASSOC);
            if (!$ticket) { echo json_encode(['error'=>'Tiket tidak ditemukan.']); exit; }

            // Insert reply
            $db->prepare(
                "INSERT INTO support_ticket_replies (ticket_id, superadmin_id, message, is_internal)
                 VALUES (?,?,?,?)"
            )->execute([$tid, $saId, $message, $isInternal]);
            $replyId = $db->lastInsertId();

            // Update ticket
            $updates = ['updated_at=NOW()'];
            $uParams = [];

            // Set first_response_at if not yet set and not internal
            if (!$isInternal && !$ticket['first_response_at']) {
                $updates[] = 'first_response_at=NOW()';
            }
            // Update status: if open → in_progress (only for public reply)
            if (!$isInternal && $ticket['status'] === 'open') {
                $updates[] = "status='in_progress'";
            }
            // Auto-assign to me if unassigned
            if (!$ticket['assigned_to']) {
                $updates[] = 'assigned_to=?';
                $uParams[] = $saId;
            }
            $uParams[] = $tid;
            $db->prepare("UPDATE support_tickets SET " . implode(', ',$updates) . " WHERE id=?")
               ->execute($uParams);

            // Build WA link for tenant notification (semi-automated)
            $waLink = null;
            if (!$isInternal && $ticket['owner_wa'] ?? '') {
                $waNum  = preg_replace('/[^0-9]/', '', $ticket['owner_wa']);
                $preview = mb_substr($message, 0, 100) . (mb_strlen($message) > 100 ? '…' : '');
                $statusMap = ['open'=>'Open','in_progress'=>'Sedang Ditangani','waiting_tenant'=>'Menunggu Respons','resolved'=>'Selesai','closed'=>'Ditutup'];
                $waMsg = "💬 *Ada balasan untuk tiket kamu*\n\n"
                       . "Tiket  : #{$ticket['id']} — {$ticket['subject']}\n"
                       . "Status : " . ($statusMap[$ticket['status']] ?? $ticket['status']) . "\n\n"
                       . "Balasan:\n{$preview}\n\n"
                       . "Lihat detail: " . (defined('APP_URL') ? APP_URL : 'https://lamasy.harpy.id') . "/support.php\n\n_Tim LaMaSy_";
                $waLink = "https://wa.me/{$waNum}?text=" . urlencode($waMsg);
            }

            logSuperAdminAction('ticket_reply', (int)$ticket['tenant_id'],
                "Reply tiket #{$tid}" . ($isInternal ? " [internal]" : ""));

            echo json_encode(['success'=>true, 'reply_id'=>$replyId, 'wa_link'=>$waLink]);
            exit;
        }

        // ── update_status ─────────────────────────────
        if ($action === 'update_status') {
            SaPermission::require('support.close');
            $tid    = (int)($_POST['ticket_id'] ?? 0);
            $status = $_POST['status'] ?? '';
            $valid  = ['open','in_progress','waiting_tenant','resolved','closed'];
            if (!$tid || !in_array($status, $valid)) { echo json_encode(['error'=>'Invalid.']); exit; }

            $ts = $db->prepare("SELECT * FROM support_tickets WHERE id=? LIMIT 1");
            $ts->execute([$tid]);
            $ticket = $ts->fetch(PDO::FETCH_ASSOC);
            if (!$ticket) { echo json_encode(['error'=>'Tiket tidak ditemukan.']); exit; }

            $sets    = ['status=?', 'updated_at=NOW()'];
            $uParams = [$status];

            if ($status === 'resolved' && !$ticket['resolved_at']) {
                $sets[]    = 'resolved_at=NOW()';
                if (!$ticket['first_response_at']) $sets[] = 'first_response_at=NOW()';
            }
            if ($status === 'closed' && !$ticket['closed_at']) {
                $sets[] = 'closed_at=NOW()';
            }
            $uParams[] = $tid;
            $db->prepare("UPDATE support_tickets SET " . implode(',',$sets) . " WHERE id=?")
               ->execute($uParams);

            // Build WA for resolved/closed
            $waLink = null;
            if (in_array($status, ['resolved','closed']) && ($ticket['owner_wa'] ?? '')) {
                $waNum = preg_replace('/[^0-9]/', '', $ticket['owner_wa']);
                $statusLabel = $status === 'resolved' ? 'Diselesaikan ✅' : 'Ditutup 🔒';
                $waMsg = "✅ *Tiket kamu sudah $statusLabel*\n\n"
                       . "Tiket : #{$ticket['id']} — {$ticket['subject']}\n\n"
                       . ($status === 'resolved' ? "Mohon konfirmasi jika masalah sudah teratasi di: " . (defined('APP_URL') ? APP_URL : 'https://lamasy.harpy.id') . "/support.php\n\n" : "")
                       . "Terima kasih telah menggunakan LaMaSy! 🙏\n_Tim LaMaSy_";
                $waLink = "https://wa.me/{$waNum}?text=" . urlencode($waMsg);
            }

            logSuperAdminAction('ticket_status', (int)$ticket['tenant_id'], "Status tiket #{$tid} → {$status}");
            echo json_encode(['success'=>true, 'wa_link'=>$waLink]);
            exit;
        }

        // ── assign ────────────────────────────────────
        if ($action === 'assign') {
            $tid        = (int)($_POST['ticket_id'] ?? 0);
            $assignedTo = (int)($_POST['assigned_to'] ?? 0) ?: null;
            if (!$tid) { echo json_encode(['error'=>'Invalid.']); exit; }

            $db->prepare("UPDATE support_tickets SET assigned_to=?, updated_at=NOW() WHERE id=?")
               ->execute([$assignedTo, $tid]);

            // Build WA notify to assigned SA (get their WA if available — use name for now)
            logSuperAdminAction('ticket_assign', null, "Tiket #{$tid} assigned ke SA #{$assignedTo}");
            echo json_encode(['success'=>true]);
            exit;
        }

        // ── set_priority ──────────────────────────────
        if ($action === 'set_priority') {
            $tid      = (int)($_POST['ticket_id'] ?? 0);
            $priority = $_POST['priority'] ?? 'normal';
            if (!in_array($priority, ['low','normal','high','critical'])) { echo json_encode(['error'=>'Invalid.']); exit; }
            $db->prepare("UPDATE support_tickets SET priority=?, updated_at=NOW() WHERE id=?")->execute([$priority, $tid]);
            echo json_encode(['success'=>true]);
            exit;
        }
    }

    echo json_encode(['error' => 'Action tidak dikenal.']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php saRenderHead('Support Tickets'); ?>
<style>
/* ── Status badges ──────────────────────────────── */
.t-open           { background:#FFFBEB;color:#92400E; }
.t-in_progress    { background:#EEF2FF; color:var(--indigo); }
.t-waiting_tenant { background:rgba(168,85,247,.15); color:#D8B4FE; }
.t-resolved       { background:#ECFDF5; color:var(--sage); }
.t-closed         { background:var(--crease-soft);color:var(--ash-dim); }

/* ── Priority ───────────────────────────────────── */
.p-low      { background:var(--crease-soft);color:var(--ash-dim); }
.p-normal   { background:#EEF2FF; color:var(--indigo); }
.p-high     { background:#FFFBEB; color:#92400E; }
.p-critical { background:#FECACA;  color:#991B1B; }

/* ── SLA indicator ──────────────────────────────── */
.sla-dot { width:8px;height:8px;border-radius:50%;flex-shrink:0;display:inline-block; }
.sla-ok     { background:#10B981; }
.sla-warn   { background:#F59E0B; }
.sla-danger { background:#EF4444;animation:pulse 1.2s infinite; }
@keyframes pulse{ 0%,100%{opacity:1;} 50%{opacity:.4;} }

/* ── Stats alert ────────────────────────────────── */
.sla-alert {
  background:#FECACA;border:1px solid #FCA5A5;
  border-radius:var(--r);padding:12px 16px;display:flex;align-items:center;
  gap:10px;color:#991B1B;font-size:13px;font-weight:600;margin-bottom:20px;
}
.sla-alert a { color:#991B1B;text-decoration:underline;margin-left:6px; }

/* ── Thread bubble ──────────────────────────────── */
.thread-wrap { display:flex;flex-direction:column;gap:10px;padding:4px 0 12px; }
.bubble { max-width:78%; display:flex;flex-direction:column;gap:3px; }
.bubble.tenant { align-self:flex-start; }
.bubble.admin  { align-self:flex-start; }
.bubble.internal { align-self:flex-start;opacity:.8; }
.bubble-head { font-size:11px;font-weight:700;color:var(--ash); }
.bubble-body {
  padding:10px 14px;border-radius:12px;font-size:13px;
  line-height:1.55;white-space:pre-wrap;word-break:break-word;
}
.bubble.tenant  .bubble-body { background:var(--crease-soft);border:1px solid var(--crease); border-top-left-radius:4px; }
.bubble.admin   .bubble-body { background:#EEF2FF;border:1px solid #C7D2FE; border-top-left-radius:4px; }
.bubble.internal .bubble-body { background:#FFFBEB;border:1px dashed rgba(245,158,11,.3);font-style:italic; }
.bubble-time { font-size:10.5px;color:var(--ash-dim); }

/* ── Reply area ─────────────────────────────────── */
.reply-tabs { display:flex;gap:2px;margin-bottom:10px; }
.reply-tab {
  padding:6px 14px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;
  border:1.5px solid var(--crease);background:transparent;color:var(--ash);
  transition:all .15s;
}
.reply-tab.active { border-color:var(--sa);background:var(--sa-l);color:var(--white); }

/* ── Action row in modal ────────────────────────── */
.modal-actions {
  display:flex;flex-wrap:wrap;gap:8px;align-items:center;
  padding:12px 20px;border-top:1px solid var(--crease-soft);
  background:var(--navy-m);
}

/* ── Filter bar ─────────────────────────────────── */
.sup-filter {
  display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:16px;
}
.sup-filter select, .sup-filter input {
  background:var(--crease-soft);border:1px solid var(--crease);
  color:var(--white);border-radius:7px;padding:7px 11px;font-size:12.5px;
  font-family:var(--font);outline:none;transition:border-color .15s;
}
.sup-filter select:focus, .sup-filter input:focus { border-color:var(--sa); }

/* ── Modal WA popup ─────────────────────────────── */
#waPopup {
  position:fixed;bottom:24px;right:24px;
  background:var(--navy-m);border:1px solid rgba(99,102,241,.3);
  border-radius:12px;padding:14px 18px;z-index:2000;
  box-shadow:0 8px 32px rgba(0,0,0,.4);
  display:none;align-items:center;gap:12px;
  max-width:320px;
}
#waPopup.show { display:flex; }
</style>
</head>
<body>
<div class="sa-layout">
<?php saRenderNav('support', 'Support Tickets'); ?>

<div class="sa-page-header">
  <h1>🎧 Support Tickets</h1>
  <p>Inbox tiket bantuan dari seluruh tenant</p>
</div>

<!-- SLA Alert (populated by JS) -->
<div id="slaAlert" style="display:none;" class="sla-alert"></div>

<!-- Stats Bar -->
<div class="sa-stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr));margin-bottom:24px;">
  <div class="sa-stat-card yellow">
    <div class="label">Open</div>
    <div class="value" id="s-open" style="font-size:22px;">—</div>
    <span class="icon-bg">📬</span>
  </div>
  <div class="sa-stat-card indigo">
    <div class="label">In Progress</div>
    <div class="value" id="s-prog" style="font-size:22px;">—</div>
    <span class="icon-bg">⚙️</span>
  </div>
  <div class="sa-stat-card blue">
    <div class="label">Waiting Tenant</div>
    <div class="value" id="s-wait" style="font-size:22px;">—</div>
    <span class="icon-bg">⏳</span>
  </div>
  <div class="sa-stat-card green">
    <div class="label">Resolved Hari Ini</div>
    <div class="value" id="s-today" style="font-size:22px;">—</div>
    <span class="icon-bg">✅</span>
  </div>
  <div class="sa-stat-card blue">
    <div class="label">Avg Response</div>
    <div class="value" id="s-avg" style="font-size:16px;">—</div>
    <div class="sub" id="s-rating"></div>
    <span class="icon-bg">⏱</span>
  </div>
</div>

<!-- Ticket List -->
<div class="sa-card">
  <div class="sa-card-header" style="display:flex;align-items:center;justify-content:space-between;">
    <h3>Semua Tiket</h3>
  </div>

  <div class="sup-filter">
    <input type="text" id="fSearch" placeholder="🔍 Cari tenant / subject…" oninput="debounce()" style="min-width:200px;"/>
    <select id="fStatus" onchange="tPage=1;loadTickets()">
      <option value="">Semua Status</option>
      <option value="open">Open</option>
      <option value="in_progress">In Progress</option>
      <option value="waiting_tenant">Waiting</option>
      <option value="resolved">Resolved</option>
      <option value="closed">Closed</option>
    </select>
    <select id="fCategory" onchange="tPage=1;loadTickets()">
      <option value="">Semua Kategori</option>
      <option value="billing">Billing</option>
      <option value="teknis">Teknis</option>
      <option value="fitur">Fitur</option>
      <option value="akun">Akun</option>
      <option value="lainnya">Lainnya</option>
    </select>
    <select id="fPriority" onchange="tPage=1;loadTickets()">
      <option value="">Semua Prioritas</option>
      <option value="critical">🔴 Critical</option>
      <option value="high">🟡 High</option>
      <option value="normal">🔵 Normal</option>
      <option value="low">⚪ Low</option>
    </select>
    <select id="fAssigned" onchange="tPage=1;loadTickets()">
      <option value="">Semua Assigned</option>
      <option value="me">Assigned ke Saya</option>
      <option value="unassigned">Belum di-assign</option>
    </select>
  </div>

  <div class="sa-table-wrap">
    <table class="sa-table">
      <thead>
        <tr>
          <th style="width:54px">ID</th>
          <th>Tenant</th>
          <th>Subject</th>
          <th style="width:100px">Kategori</th>
          <th style="width:80px">Prioritas</th>
          <th style="width:120px">Status</th>
          <th style="width:110px">Balasan</th>
          <th style="width:100px">Waktu</th>
        </tr>
      </thead>
      <tbody id="ticketBody">
        <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--ash-dim);">Memuat…</td></tr>
      </tbody>
    </table>
  </div>
  <div class="sa-pagination" id="ticketPagination"></div>
</div>

<!-- ══ Detail Modal ══════════════════════════════════ -->
<div class="sa-modal-overlay" id="detailOverlay" onclick="closeDetail(event)">
  <div class="sa-modal sa-modal-xl" style="max-width:760px;max-height:90vh;overflow:hidden;display:flex;flex-direction:column;">
    <!-- Header -->
    <div class="sa-modal-header">
      <span class="sa-modal-title" id="detailTitle">Detail Tiket</span>
      <button class="sa-modal-close" onclick="closeDetail()">✕</button>
    </div>

    <!-- Ticket meta bar -->
    <div id="detailMeta" style="padding:12px 20px;background:var(--linen);border-bottom:1px solid var(--crease-soft);display:flex;flex-wrap:wrap;gap:8px;align-items:center;font-size:12px;">
    </div>

    <!-- Thread -->
    <div id="detailThread" style="flex:1;overflow-y:auto;padding:16px 20px;">
      <div style="text-align:center;padding:32px;color:var(--ash-dim);">Memuat…</div>
    </div>

    <!-- Reply area -->
    <div style="border-top:1px solid var(--crease-soft);padding:14px 20px;" id="replyArea">
      <div class="reply-tabs">
        <button class="reply-tab active" id="tabPublic"   onclick="setReplyTab(0)">💬 Balas ke Tenant</button>
        <button class="reply-tab"        id="tabInternal" onclick="setReplyTab(1)">🔒 Internal Note</button>
      </div>
      <textarea id="replyMsg" class="sa-input" rows="3" style="width:100%;resize:vertical;min-height:72px;margin-bottom:10px;"
                placeholder="Tulis balasan…"></textarea>
      <div style="display:flex;gap:8px;justify-content:flex-end;">
        <button class="sa-btn sa-btn-outline sa-btn-sm" onclick="closeDetail()">Tutup</button>
        <button class="sa-btn sa-btn-primary sa-btn-sm" id="sendBtn" onclick="sendReply()">Kirim →</button>
      </div>
    </div>

    <!-- Action row -->
    <div class="modal-actions" id="actionRow">
      <span style="font-size:11px;color:var(--ash-dim);font-weight:600;">UBAH:</span>
      <select id="aStatus" class="sa-input" style="width:auto;padding:5px 10px;font-size:12px;" onchange="updateStatus()">
        <option value="">— Status —</option>
        <option value="open">Open</option>
        <option value="in_progress">In Progress</option>
        <option value="waiting_tenant">Waiting Tenant</option>
        <option value="resolved">Resolved ✅</option>
        <option value="closed">Closed 🔒</option>
      </select>
      <select id="aPriority" class="sa-input" style="width:auto;padding:5px 10px;font-size:12px;" onchange="setPriority()">
        <option value="">— Prioritas —</option>
        <option value="low">Low</option>
        <option value="normal">Normal</option>
        <option value="high">High 🟡</option>
        <option value="critical">Critical 🔴</option>
      </select>
      <select id="aAssign" class="sa-input" style="width:auto;padding:5px 10px;font-size:12px;" onchange="assignTicket()">
        <option value="">— Assign ke —</option>
      </select>
    </div>
  </div>
</div>

<!-- WA Popup -->
<div id="waPopup">
  <span style="font-size:20px;">💬</span>
  <div style="flex:1;min-width:0;">
    <div style="font-size:12px;font-weight:700;color:var(--white);margin-bottom:2px;" id="waPopupMsg">Kirim notif WA ke tenant?</div>
    <a id="waPopupLink" href="#" target="_blank"
       style="font-size:12px;color:var(--sage);text-decoration:none;font-weight:600;"
       onclick="document.getElementById('waPopup').classList.remove('show')">
      📲 Buka WhatsApp →
    </a>
  </div>
  <button onclick="document.getElementById('waPopup').classList.remove('show')"
          style="background:none;border:none;color:var(--ash);cursor:pointer;font-size:16px;flex-shrink:0;">✕</button>
</div>

<?php saRenderNavClose(); ?>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const esc  = s => { const d=document.createElement('div');d.textContent=s||'';return d.innerHTML; };
const fmtDT = s => s ? new Date(s).toLocaleString('id-ID',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'}) : '-';
const fmtAgo = s => {
  if (!s) return '-';
  const h = (Date.now()-new Date(s).getTime())/3600000;
  if (h<1) return Math.floor(h*60)+'m lalu';
  if (h<24) return Math.floor(h)+'j lalu';
  return Math.floor(h/24)+'h lalu';
};

const statusLabel   = {open:'Open',in_progress:'In Progress',waiting_tenant:'Waiting',resolved:'Resolved',closed:'Closed'};
const priLabel      = {low:'Low',normal:'Normal',high:'High',critical:'Critical'};
const catLabel      = {billing:'💳 Billing',teknis:'🔧 Teknis',fitur:'💡 Fitur',akun:'👤 Akun',lainnya:'📩 Lainnya'};
const statusBadge   = s => `<span class="sa-badge t-${s}" style="font-size:11px;">${statusLabel[s]||s}</span>`;
const priBadge      = p => `<span class="sa-badge p-${p}" style="font-size:10.5px;">${priLabel[p]||p}</span>`;

let tPage = 1, _debTimer = null;
function debounce() { clearTimeout(_debTimer); _debTimer = setTimeout(()=>{tPage=1;loadTickets();},320); }

// ── Load stats ────────────────────────────────────────
fetch('support.php?action=stats',{headers:{'X-Requested-With':'XMLHttpRequest'}})
  .then(r=>r.json()).then(d => {
    document.getElementById('s-open').textContent  = d.open;
    document.getElementById('s-prog').textContent  = d.prog;
    document.getElementById('s-wait').textContent  = d.wait;
    document.getElementById('s-today').textContent = d.today;
    const avgMin = parseFloat(d.avgRes||0);
    document.getElementById('s-avg').textContent   = avgMin > 0
      ? (avgMin < 60 ? Math.round(avgMin)+'m' : (avgMin/60).toFixed(1)+'j') : '—';
    if (d.avgRating) {
      document.getElementById('s-rating').textContent = '⭐ ' + d.avgRating + ' rating';
    }
    // SLA alert
    if (d.sla24h > 0) {
      const a = document.getElementById('slaAlert');
      a.style.display = 'flex';
      a.innerHTML = `⚠️ <strong>${d.sla24h} tiket</strong> belum direspons lebih dari 24 jam`
        + (d.sla6h > d.sla24h ? ` &nbsp;·&nbsp; ${d.sla6h-d.sla24h} tiket lebih dari 6 jam` : '')
        + `<a onclick="document.getElementById('fStatus').value='open';loadTickets();" href="#">Lihat →</a>`;
    }
  });

// ── Load tickets ──────────────────────────────────────
function loadTickets() {
  const p = new URLSearchParams({
    action:   'list',
    status:   document.getElementById('fStatus').value,
    category: document.getElementById('fCategory').value,
    priority: document.getElementById('fPriority').value,
    assigned: document.getElementById('fAssigned').value,
    search:   document.getElementById('fSearch').value,
    page:     tPage,
  });
  fetch('support.php?'+p,{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d => {
      renderTickets(d.rows);
      renderPagination(d.page,d.pages,d.total);
    });
}

function renderTickets(rows) {
  const tbody = document.getElementById('ticketBody');
  if (!rows||!rows.length) {
    tbody.innerHTML='<tr><td colspan="8" style="text-align:center;padding:28px;color:var(--ash-dim);">Tidak ada tiket.</td></tr>';
    return;
  }
  tbody.innerHTML = rows.map(r => {
    const age = parseInt(r.age_hours||0);
    const sleCls = age>24?'sla-danger':age>6?'sla-warn':'sla-ok';
    const slaTitle = age>24?`${age}j — KRITIS!`:age>6?`${age}j — Perlu perhatian`:`${age}j`;

    return `<tr style="cursor:pointer" onclick="openDetail(${r.id})">
      <td style="font-family:var(--mono);font-size:12px;color:var(--ash);">#${r.id}</td>
      <td>
        <div style="font-size:13px;font-weight:600;">${esc(r.nama_outlet||'-')}</div>
        <div style="font-size:11px;color:var(--ash-dim);">${esc(r.owner_name||'')}</div>
      </td>
      <td>
        <div style="font-size:13px;font-weight:600;color:var(--white);">${esc(r.subject)}</div>
        <div style="font-size:11px;color:var(--ash-dim);">${r.assigned_nama ? '👤 '+esc(r.assigned_nama) : '<span style="color:rgba(255,165,0,.6)">⚠ Unassigned</span>'}</div>
      </td>
      <td><span class="sa-badge" style="font-size:10.5px;">${catLabel[r.category]||r.category}</span></td>
      <td>${priBadge(r.priority)}</td>
      <td>
        ${statusBadge(r.status)}
        ${r.status==='open'?`<span class="sla-dot ${sleCls}" style="margin-left:5px;" title="Usia tiket: ${slaTitle}"></span>`:''}
      </td>
      <td style="font-size:12px;text-align:center;color:var(--ash);">${r.reply_count||0} balasan</td>
      <td style="font-size:12px;color:var(--ash-dim);">${fmtAgo(r.updated_at||r.created_at)}</td>
    </tr>`;
  }).join('');
}

function renderPagination(page,pages,total) {
  const el = document.getElementById('ticketPagination');
  let html = `<span style="font-size:12px;color:var(--ash-dim);margin-right:10px">Total: ${total}</span>`;
  html += `<button class="sa-btn sa-btn-sm sa-btn-outline${page<=1?' disabled':''}" onclick="tGoto(${page-1})">‹ Prev</button>`;
  for(let i=Math.max(1,page-2);i<=Math.min(pages,page+2);i++)
    html+=`<button class="sa-btn sa-btn-sm ${i===page?'sa-btn-primary':'sa-btn-outline'}" onclick="tGoto(${i})">${i}</button>`;
  html+=`<button class="sa-btn sa-btn-sm sa-btn-outline${page>=pages?' disabled':''}" onclick="tGoto(${page+1})">Next ›</button>`;
  el.innerHTML = html;
}
function tGoto(p){if(p<1)return;tPage=p;loadTickets();}

// ── Open detail modal ─────────────────────────────────
let _curTicket = null, _isInternal = 0;

function openDetail(id) {
  _curTicket = id;
  document.getElementById('detailOverlay').classList.add('open');
  document.getElementById('detailThread').innerHTML =
    '<div style="text-align:center;padding:24px;color:var(--ash-dim)">Memuat…</div>';
  document.getElementById('replyMsg').value = '';

  fetch(`support.php?action=get_thread&id=${id}`,{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d => {
      if (d.error) { showToast(d.error,'error'); return; }
      renderDetail(d.ticket, d.replies, d.admins);
    });
}

function closeDetail(e) {
  if (e && e.target !== document.getElementById('detailOverlay')) return;
  document.getElementById('detailOverlay').classList.remove('open');
  _curTicket = null;
  loadTickets();
}
document.addEventListener('keydown', e => { if (e.key==='Escape') closeDetail(); });

function renderDetail(ticket, replies, admins) {
  _curTicket = ticket.id;

  document.getElementById('detailTitle').textContent = `Tiket #${ticket.id} — ${ticket.subject}`;

  // Meta bar
  document.getElementById('detailMeta').innerHTML = `
    ${statusBadge(ticket.status)}
    ${priBadge(ticket.priority)}
    <span class="sa-badge" style="font-size:10.5px;">${catLabel[ticket.category]||ticket.category}</span>
    <span style="color:var(--ash-dim);">📍 ${esc(ticket.nama_outlet||'-')}</span>
    <span style="color:var(--ash-dim);">👤 ${esc(ticket.owner_name||'')}</span>
    <span style="color:var(--ash-dim);margin-left:auto;font-size:11px;">Dibuat ${fmtDT(ticket.created_at)}</span>`;

  // Thread
  let html = '<div class="thread-wrap">';
  // Original message
  html += `<div class="bubble tenant">
    <div class="bubble-head">${esc(ticket.owner_name||'Tenant')} &nbsp;·&nbsp; pesan awal</div>
    <div class="bubble-body">${esc(ticket.message)}</div>
    <div class="bubble-time">${fmtDT(ticket.created_at)}</div>
  </div>`;

  (replies||[]).forEach(r => {
    const isInternal = parseInt(r.is_internal||0);
    const isAdmin    = !!r.superadmin_id;
    const sender     = isAdmin ? (r.sa_nama||'Tim LaMaSy') : (r.user_nama||'Tenant');
    const cls        = isInternal ? 'internal' : (isAdmin ? 'admin' : 'tenant');
    const tag        = isInternal ? ' 🔒 Internal' : '';
    html += `<div class="bubble ${cls}">
      <div class="bubble-head">${esc(sender)}${tag}</div>
      <div class="bubble-body">${esc(r.message)}</div>
      <div class="bubble-time">${fmtDT(r.created_at)}</div>
    </div>`;
  });
  html += '</div>';

  if (!replies||!replies.length) {
    html += `<div style="text-align:center;padding:12px;font-size:12px;color:var(--ash-dim)">Belum ada balasan.</div>`;
  }
  document.getElementById('detailThread').innerHTML = html;
  // Scroll to bottom
  const dt = document.getElementById('detailThread');
  dt.scrollTop = dt.scrollHeight;

  // Show/hide reply area
  const canReply = !['closed'].includes(ticket.status);
  document.getElementById('replyArea').style.display = canReply ? 'block' : 'none';

  // Populate action selects
  document.getElementById('aStatus').value   = ticket.status;
  document.getElementById('aPriority').value = ticket.priority;

  // Populate assign dropdown
  const aAssign = document.getElementById('aAssign');
  aAssign.innerHTML = '<option value="">— Assign ke —</option>'
    + (admins||[]).map(a => `<option value="${a.id}" ${parseInt(ticket.assigned_to)===parseInt(a.id)?'selected':''}>${esc(a.name)}</option>`).join('');
}

// ── Reply tab ─────────────────────────────────────────
function setReplyTab(internal) {
  _isInternal = internal;
  document.getElementById('tabPublic').classList.toggle('active', !internal);
  document.getElementById('tabInternal').classList.toggle('active', !!internal);
  document.getElementById('replyMsg').placeholder = internal
    ? 'Catatan internal — tidak terlihat tenant…'
    : 'Tulis balasan untuk tenant…';
}

// ── Send reply ────────────────────────────────────────
function sendReply() {
  const msg = document.getElementById('replyMsg').value.trim();
  if (!msg) { showToast('Pesan kosong.','error'); return; }
  const btn = document.getElementById('sendBtn');
  btn.disabled=true; btn.textContent='Mengirim…';

  const body = new FormData();
  body.append('action','reply');
  body.append('_csrf', CSRF);
  body.append('ticket_id', _curTicket);
  body.append('message', msg);
  body.append('is_internal', _isInternal);

  fetch('support.php', {method:'POST',body,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d => {
      if (d.error) { showToast(d.error,'error'); return; }
      document.getElementById('replyMsg').value='';
      openDetail(_curTicket); // reload thread
      // Show WA popup if public reply
      if (!_isInternal && d.wa_link) showWaPopup(d.wa_link, 'Notifikasi WA ke tenant');
    }).finally(()=>{ btn.disabled=false; btn.textContent='Kirim →'; });
}

// ── Update status ─────────────────────────────────────
function updateStatus() {
  const status = document.getElementById('aStatus').value;
  if (!status) return;
  const body = new FormData();
  body.append('action','update_status'); body.append('_csrf',CSRF);
  body.append('ticket_id',_curTicket); body.append('status',status);
  fetch('support.php',{method:'POST',body,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
      if(d.error){showToast(d.error,'error');return;}
      showToast('Status diperbarui.','success');
      if(d.wa_link) showWaPopup(d.wa_link,'Notif WA resolve/close ke tenant');
      openDetail(_curTicket);
    });
}

// ── Set priority ──────────────────────────────────────
function setPriority() {
  const pri = document.getElementById('aPriority').value;
  if (!pri) return;
  const body = new FormData();
  body.append('action','set_priority'); body.append('_csrf',CSRF);
  body.append('ticket_id',_curTicket); body.append('priority',pri);
  fetch('support.php',{method:'POST',body,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{ if(d.success) showToast('Prioritas diperbarui.'); });
}

// ── Assign ticket ─────────────────────────────────────
function assignTicket() {
  const assignedTo = document.getElementById('aAssign').value;
  const body = new FormData();
  body.append('action','assign'); body.append('_csrf',CSRF);
  body.append('ticket_id',_curTicket); body.append('assigned_to', assignedTo||'');
  fetch('support.php',{method:'POST',body,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{ if(d.success) showToast('Tiket di-assign.'); });
}

// ── WA popup ──────────────────────────────────────────
function showWaPopup(link, msg) {
  document.getElementById('waPopupMsg').textContent  = msg;
  document.getElementById('waPopupLink').href        = link;
  document.getElementById('waPopup').classList.add('show');
  setTimeout(()=>document.getElementById('waPopup').classList.remove('show'), 12000);
}

// ── Toast ─────────────────────────────────────────────
function showToast(msg, type='success') {
  let t = document.getElementById('toast');
  if(!t){t=document.createElement('div');t.id='toast';t.className='sa-toast';document.body.appendChild(t);}
  t.textContent=msg;
  t.className=`sa-toast ${type} show`;
  clearTimeout(t._t); t._t=setTimeout(()=>t.className='sa-toast',3000);
}

loadTickets();
</script>
</body>
</html>

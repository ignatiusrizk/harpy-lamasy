<?php
// ══════════════════════════════════════════════════════
// owner_report.php — Feed laporan & alert in-app untuk owner
// Hanya owner/manager yang punya akses
// ══════════════════════════════════════════════════════

$activePage = 'owner_report';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/Notifier.php';
require_once ROOT . '/core/DailyReport.php';
require_once __DIR__ . '/components.php';

$user = currentUser();
$role = $user['role'] ?? 'staff';
if (!TenantResolver::isAdminLevel()) {
    http_response_code(403);
    die('Akses ditolak — hanya owner/manager.');
}

$tid = TenantResolver::id();
$oid = TenantResolver::outletId();
$action = $_GET['action'] ?? '';

// ── API: list feed (paginated, sort by sent_at desc) ──
if ($action === 'list') {
    header('Content-Type: application/json');
    $limit = max(10, min(50, (int)($_GET['limit'] ?? 30)));
    try {
        $db = Database::get();
        $stmt = $db->prepare("SELECT id, type, channel, subject, body_summary, status,
                                     read_at, sent_at
                                FROM hl_notif_log
                               WHERE tenant_id=? AND outlet_id=?
                                 AND channel IN ('inapp','email')
                               ORDER BY id DESC LIMIT ?");
        $stmt->bindValue(1, $tid, PDO::PARAM_INT);
        $stmt->bindValue(2, $oid, PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $unread = Notifier::unreadCount($tid, $oid);
        echo json_encode(['ok'=>true, 'rows'=>$rows, 'unread'=>$unread]);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ── API: mark read 1 / semua ──
if ($action === 'mark_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($d['id'] ?? 0);
    $all = !empty($d['all']);
    try {
        $db = Database::get();
        if ($all) {
            $db->prepare("UPDATE hl_notif_log SET read_at=NOW()
                          WHERE tenant_id=? AND outlet_id=? AND read_at IS NULL AND channel='inapp'")
               ->execute([$tid, $oid]);
        } elseif ($id) {
            $db->prepare("UPDATE hl_notif_log SET read_at=NOW()
                          WHERE id=? AND tenant_id=? AND outlet_id=?")
               ->execute([$id, $tid, $oid]);
        }
        echo json_encode(['ok'=>true]);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ── API: force kirim daily report sekarang (tombol "kirim sekarang") ──
if ($action === 'send_now' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!TenantResolver::isAdminLevel()) { echo json_encode(['error'=>'Akses ditolak']); exit; }
    try {
        // Bypass jam check dengan trick: clear sent_today filter via deleting today's daily_report log
        // Lebih aman: build laporan & kirim langsung tanpa Notifier dedup
        $report = DailyReport::build($tid, $oid, ['omset','order','kas','absensi','alert']);
        $res = Notifier::notifyOwner($tid, $oid, [
            'type'         => 'daily_report_manual',  // type beda biar tidak terkunci sentToday
            'subject'      => $report['subject'] . ' (manual)',
            'body_html'    => $report['html'],
            'body_summary' => $report['summary'],
            'channels'     => ['email','inapp'],
            'coin_feature' => 'daily_report',
        ]);
        echo json_encode($res);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ── API: get notif preferences ──
if ($action === 'get_prefs') {
    header('Content-Type: application/json');
    if (!TenantResolver::isAdminLevel()) { echo json_encode(['error'=>'Akses ditolak']); exit; }
    $db = Database::get();
    $s = $db->prepare("SELECT notif_settings FROM tenants WHERE id=?");
    $s->execute([$tid]);
    $raw = $s->fetchColumn();
    $cfg = $raw ? (json_decode($raw, true) ?: []) : [];
    $g = function($cat,$ch) use ($cfg) { return (int)($cfg[$cat][$ch] ?? 1); }; // default 1
    echo json_encode([
        'dr_email'=>$g('daily_report','email'), 'dr_inapp'=>$g('daily_report','inapp'),
        'an_email'=>$g('alert_anomali','email'), 'an_inapp'=>$g('alert_anomali','inapp'),
        'jam'=>$cfg['daily_report_jam'] ?? '21:00',
        'konten'=>$cfg['daily_report_konten'] ?? ['omset','order','kas','absensi','alert'],
    ]); exit;
}

// ── API: save notif preferences (merge — jaga key HQ) ──
if ($action === 'save_prefs' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!TenantResolver::isAdminLevel()) { echo json_encode(['error'=>'Akses ditolak']); exit; }
    verifyCsrf();
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $db = Database::get();
    $s = $db->prepare("SELECT notif_settings FROM tenants WHERE id=?");
    $s->execute([$tid]);
    $raw = $s->fetchColumn();
    $cur = $raw ? (json_decode($raw, true) ?: []) : [];   // merge — jaga key HQ (coin_low/trial_ending)
    $cur['daily_report']  = ['email'=>!empty($d['dr_email'])?1:0, 'inapp'=>!empty($d['dr_inapp'])?1:0];
    $cur['alert_anomali'] = ['email'=>!empty($d['an_email'])?1:0, 'inapp'=>!empty($d['an_inapp'])?1:0];
    $jam = $d['jam'] ?? '21:00';
    if (!preg_match('/^\d{2}:\d{2}$/', $jam)) $jam = '21:00';
    $cur['daily_report_jam'] = $jam;
    $valid = ['omset','order','kas','absensi','alert']; $konten = [];
    foreach ((array)($d['konten'] ?? []) as $k) { if (in_array($k, $valid, true)) $konten[] = $k; }
    if (!$konten) $konten = $valid;
    $cur['daily_report_konten'] = $konten;
    try {
        $db->prepare("UPDATE tenants SET notif_settings=? WHERE id=?")->execute([json_encode($cur), $tid]);
        echo json_encode(['success'=>true]);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ── API: preview JSON (untuk modal) ──
if ($action === 'preview') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    $db = Database::get();
    $s = $db->prepare("SELECT id, subject, body_summary, type, channel, sent_at, read_at FROM hl_notif_log
                        WHERE id=? AND tenant_id=? AND outlet_id=?");
    $s->execute([$id, $tid, $oid]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo json_encode(['error'=>'Not found']); exit; }
    echo json_encode(['ok'=>true, 'row'=>$row]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Laporan Owner'); ?>
<style>
.feed-item{background:#fff;border:1px solid #E5E9F2;border-radius:10px;padding:14px 16px;margin-bottom:10px;cursor:pointer;transition:box-shadow .15s,transform .1s}
.feed-item:hover{box-shadow:0 2px 8px rgba(0,0,0,.06)}
.feed-item.unread{background:#FEF9E7;border-left:4px solid #F59E0B}
.feed-head{display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:4px}
.feed-subj{font-size:14px;font-weight:700;color:#0F1C3A;flex:1;min-width:0}
.feed-meta{font-size:11px;color:#9CA3AF;white-space:nowrap}
.feed-sum{font-size:13px;color:#374151;line-height:1.5}
.feed-tag{display:inline-block;font-size:10px;font-weight:700;padding:2px 7px;border-radius:100px;margin-right:5px}
.tag-daily{background:#D1FAE5;color:#065F46}
.tag-alert{background:#FEE2E2;color:#991B1B}
.tag-invoice{background:#DBEAFE;color:#1E40AF}
.tag-reminder{background:#FEF3C7;color:#92400E}

/* ── Preview Modal ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,28,58,.5);z-index:9999;align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(2px)}
.modal-overlay.open{display:flex;animation:fadeIn .15s ease}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.modal-card{background:#fff;border-radius:16px;width:100%;max-width:560px;max-height:88vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.22);animation:slideUp .2s ease}
@keyframes slideUp{from{transform:translateY(16px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-header{padding:20px 20px 16px;border-bottom:1px solid #F1F5F9;display:flex;align-items:flex-start;gap:14px}
.modal-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
.modal-icon.t-daily{background:#D1FAE5}
.modal-icon.t-alert{background:#FEE2E2}
.modal-icon.t-invoice{background:#DBEAFE}
.modal-icon.t-reminder{background:#FEF3C7}
.modal-title-wrap{flex:1;min-width:0}
.modal-title-wrap h2{font-size:15px;font-weight:700;color:#0F1C3A;margin:0 0 6px;line-height:1.4;word-break:break-word}
.modal-badge{display:inline-block;font-size:10px;font-weight:700;padding:2px 9px;border-radius:100px}
.modal-close{width:32px;height:32px;border:none;background:#F1F5F9;border-radius:8px;cursor:pointer;font-size:15px;color:#6B7280;flex-shrink:0;transition:background .15s;line-height:1;margin-top:-2px}
.modal-close:hover{background:#E2E8F0;color:#374151}
.modal-meta{display:flex;flex-wrap:wrap;gap:12px 20px;padding:10px 20px;background:#F8FAFC;border-bottom:1px solid #F1F5F9}
.modal-meta-item{font-size:12px;color:#6B7280}
.modal-meta-item b{color:#374151;font-weight:600}
.modal-body{padding:20px;overflow-y:auto;flex:1;font-size:13px;color:#374151;line-height:1.75;white-space:pre-wrap;word-break:break-word}
.modal-footer{padding:12px 20px;border-top:1px solid #F1F5F9;display:flex;justify-content:flex-end;gap:8px;background:#FAFBFC}
</style>
</head>
<body>
<?php renderTopbar('owner_report'); ?>

<div class="hl-main" style="max-width:760px">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
    <div>
      <h1 style="font-size:1.3rem;font-weight:800;color:var(--navy)">📨 Notifikasi & Laporan</h1>
      <p style="font-size:13px;color:var(--gray)">Feed laporan harian + alert anomali outlet <span id="unreadBadge"></span></p>
    </div>
    <div style="display:flex;gap:8px">
      <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="markAllRead()">✓ Tandai semua dibaca</button>
      <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="openPrefs()">⚙️ Pengaturan Notifikasi</button>
      <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="sendNow()">📊 Kirim Laporan Sekarang</button>
    </div>
  </div>

  <div id="feedBox">
    <?php for($i=0;$i<4;$i++): ?>
    <div class="hl-skel-card" style="padding:14px">
      <span class="hl-skel" style="width:120px;display:block"></span>
      <span class="hl-skel lg" style="width:75%;display:block;margin-top:8px"></span>
      <span class="hl-skel" style="width:50%;display:block;margin-top:6px"></span>
    </div>
    <?php endfor; ?>
  </div>
</div>

<?php renderToast(); ?>

<!-- ── Preview Modal ── -->
<div class="modal-overlay" id="previewModal" onclick="onOverlayClick(event)">
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="mSubject">
    <div class="modal-header">
      <div class="modal-icon" id="mIcon">📊</div>
      <div class="modal-title-wrap">
        <h2 id="mSubject">—</h2>
        <span class="modal-badge" id="mBadge"></span>
      </div>
      <button class="modal-close" onclick="closeModal()" title="Tutup">✕</button>
    </div>
    <div class="modal-meta">
      <div class="modal-meta-item">🕐 <b id="mDate">—</b></div>
      <div class="modal-meta-item" id="mChannelWrap">📣 <b id="mChannel">—</b></div>
      <div class="modal-meta-item" id="mReadWrap"></div>
    </div>
    <div class="modal-body" id="mBody"></div>
    <div class="modal-footer">
      <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="closeModal()">Tutup</button>
    </div>
  </div>
</div>

<!-- ── Prefs Modal ── -->
<div class="modal-overlay" id="prefsModal" onclick="if(event.target===this)closePrefs()">
  <div class="modal-card" role="dialog" aria-modal="true" style="max-width:480px">
    <div class="modal-header">
      <div class="modal-icon t-daily">⚙️</div>
      <div class="modal-title-wrap">
        <h2 style="font-size:1.1rem;font-weight:800;color:var(--navy)">⚙️ Pengaturan Notifikasi</h2>
      </div>
      <button class="modal-close" onclick="closePrefs()" title="Tutup">✕</button>
    </div>
    <div class="modal-body" style="padding:20px">
      <div style="display:flex;flex-direction:column;gap:14px">
        <div>
          <div style="font-weight:700;color:var(--navy);font-size:13px;margin-bottom:6px">📊 Laporan Harian</div>
          <label style="display:flex;gap:8px;align-items:center;font-size:13px;margin-bottom:4px"><input type="checkbox" id="pf_dr_email"> Email</label>
          <label style="display:flex;gap:8px;align-items:center;font-size:13px"><input type="checkbox" id="pf_dr_inapp"> In-app (feed)</label>
          <div style="margin-top:8px;font-size:12px;color:var(--gray)">Jam kirim: <input type="time" id="pf_jam" style="padding:4px 8px;border:1px solid #E5E9F2;border-radius:6px"></div>
          <div style="margin-top:8px;display:flex;gap:10px;flex-wrap:wrap">
            <?php foreach (['omset'=>'💰 Omset','order'=>'📦 Order','kas'=>'💵 Kas','absensi'=>'👥 Absensi','alert'=>'⚠️ Alert'] as $k=>$lbl): ?>
              <label style="display:flex;gap:5px;align-items:center;font-size:12px"><input type="checkbox" class="pf_konten" value="<?= $k ?>"> <?= $lbl ?></label>
            <?php endforeach; ?>
          </div>
        </div>
        <div>
          <div style="font-weight:700;color:var(--navy);font-size:13px;margin-bottom:6px">⚠️ Alert Anomali</div>
          <label style="display:flex;gap:8px;align-items:center;font-size:13px;margin-bottom:4px"><input type="checkbox" id="pf_an_email"> Email</label>
          <label style="display:flex;gap:8px;align-items:center;font-size:13px"><input type="checkbox" id="pf_an_inapp"> In-app (feed)</label>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="closePrefs()">Batal</button>
      <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="savePrefs()">💾 Simpan</button>
    </div>
  </div>
</div>

<script>
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

const tagMap = {
  daily_report:'tag-daily', daily_report_manual:'tag-daily',
  alert_omset_drop:'tag-alert', alert_kas_tidak_diinput:'tag-alert',
  alert_order_menumpuk:'tag-alert', alert_absensi_rendah:'tag-alert',
  alert_coin_rendah:'tag-alert',
  invoice_b2b:'tag-invoice', reminder_piutang:'tag-reminder',
};

const typeConfig = {
  daily_report:        {cls:'t-daily',   icon:'📊', label:'Daily Report'},
  daily_report_manual: {cls:'t-daily',   icon:'📊', label:'Daily Report'},
  alert_omset_drop:    {cls:'t-alert',   icon:'⚠️', label:'Alert Omset'},
  alert_kas_tidak_diinput:{cls:'t-alert',icon:'⚠️', label:'Alert Kas'},
  alert_order_menumpuk:{cls:'t-alert',   icon:'⚠️', label:'Alert Order'},
  alert_absensi_rendah:{cls:'t-alert',   icon:'⚠️', label:'Alert Absensi'},
  alert_coin_rendah:   {cls:'t-alert',   icon:'⚠️', label:'Alert Koin'},
  invoice_b2b:         {cls:'t-invoice', icon:'🧾', label:'Invoice B2B'},
  reminder_piutang:    {cls:'t-reminder',icon:'🔔', label:'Reminder Piutang'},
};
const defaultType = {cls:'t-daily', icon:'📨', label:'Notifikasi'};

let feedRows = {};

async function loadFeed(){
  const box = document.getElementById('feedBox');
  try {
    const r = await fetch('owner_report.php?action=list');
    const d = await r.json();
    if (d.error){ box.innerHTML = `<div style="text-align:center;padding:40px;color:#EF4444">⚠️ ${esc(d.error)}</div>`; return; }
    document.getElementById('unreadBadge').innerHTML = d.unread > 0
      ? `<span style="background:#EF4444;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:100px;margin-left:6px">${d.unread} belum dibaca</span>` : '';
    if (!d.rows.length){
      box.innerHTML = `<div class="hl-empty-v2">
        <div class="e-icon">📭</div>
        <div class="e-title">Belum ada notifikasi</div>
        <div class="e-sub">Notifikasi alert anomali & daily report akan muncul di sini</div>
      </div>`;
      return;
    }
    feedRows = {};
    d.rows.forEach(r => feedRows[r.id] = r);
    box.innerHTML = d.rows.map(r => {
      const unread = r.channel==='inapp' && !r.read_at;
      const tag = tagMap[r.type] || 'tag-daily';
      const tc  = typeConfig[r.type] || defaultType;
      const dt  = new Date(r.sent_at).toLocaleString('id-ID',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'});
      const summary = (r.body_summary||'').split('\n')[0];  // first line only in feed
      return `<div class="feed-item ${unread?'unread':''}" onclick="openItem(${r.id}, ${unread?1:0})">
        <div class="feed-head">
          <div class="feed-subj">
            <span class="feed-tag ${tag}">${esc(tc.label)}</span>
            ${esc(r.subject || '-')}
          </div>
          <div class="feed-meta">
            ${r.channel==='email'?'📧':'🔔'} ${dt}
          </div>
        </div>
        ${summary ? `<div class="feed-sum">${esc(summary)}</div>` : ''}
      </div>`;
    }).join('');
  } catch(e){ box.innerHTML = `<div style="color:#EF4444;text-align:center;padding:30px">⚠️ ${esc(e.message)}</div>`; }
}

async function openItem(id, isUnread){
  if (isUnread) {
    try {
      await fetch('owner_report.php?action=mark_read', {method:'POST', body:JSON.stringify({id})});
    } catch(e){}
    loadFeed();
  }
  if (feedRows[id]) {
    showPreviewModal(feedRows[id]);
  } else {
    // fallback: fetch from server (e.g. after page reload)
    try {
      const r = await fetch('owner_report.php?action=preview&id='+id);
      const d = await r.json();
      if (d.ok) showPreviewModal(d.row);
      else showToast('⚠️ Notifikasi tidak ditemukan','error');
    } catch(e){ showToast('Gagal memuat notifikasi','error'); }
  }
}

function showPreviewModal(row){
  const tc = typeConfig[row.type] || defaultType;

  // icon & badge
  document.getElementById('mIcon').className  = 'modal-icon ' + tc.cls;
  document.getElementById('mIcon').textContent = tc.icon;
  document.getElementById('mBadge').className  = 'modal-badge ' + (tagMap[row.type] || 'tag-daily');
  document.getElementById('mBadge').textContent = tc.label;

  // subject
  document.getElementById('mSubject').textContent = row.subject || '(tanpa judul)';

  // meta
  const dt = row.sent_at
    ? new Date(row.sent_at).toLocaleString('id-ID',{weekday:'short',day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'})
    : '—';
  document.getElementById('mDate').textContent = dt;
  document.getElementById('mChannel').textContent = row.channel === 'email' ? '📧 Email' : '🔔 In-App';
  document.getElementById('mReadWrap').innerHTML = row.read_at
    ? `✅ <b>Dibaca</b> ${new Date(row.read_at).toLocaleString('id-ID',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'})}`
    : `<span style="color:#F59E0B">● Belum dibaca</span>`;

  // body — preserve line breaks, render nicely
  const bodyEl = document.getElementById('mBody');
  const raw = row.body_summary || '';
  if (!raw) {
    bodyEl.innerHTML = '<span style="color:#9CA3AF;font-style:italic">Tidak ada isi pesan.</span>';
  } else {
    // Bold lines that look like section headers (ALL CAPS, or ending with ':')
    const html = raw.split('\n').map(line => {
      const t = line.trim();
      if (!t) return '<br>';
      if (/^[A-Z\sÀ-ÿ]{4,}$/.test(t) || t.endsWith(':')) {
        return `<strong style="color:#0F1C3A">${esc(t)}</strong>`;
      }
      return esc(t);
    }).join('\n');
    bodyEl.innerHTML = html;
  }

  document.getElementById('previewModal').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeModal(){
  document.getElementById('previewModal').classList.remove('open');
  document.body.style.overflow = '';
}

function onOverlayClick(e){
  if (e.target === document.getElementById('previewModal')) closeModal();
}

// Close modal on Escape key
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

async function markAllRead(){
  if (!confirm('Tandai semua notifikasi sebagai sudah dibaca?')) return;
  try {
    await fetch('owner_report.php?action=mark_read', {method:'POST', body:JSON.stringify({all:true})});
    showToast('✅ Semua ditandai dibaca','success');
    loadFeed();
  } catch(e){ showToast('Gagal: '+e.message,'error'); }
}

async function sendNow(){
  if (!confirm('Kirim laporan harian sekarang? (deduct 100 coin)')) return;
  try {
    const r = await fetch('owner_report.php?action=send_now', {method:'POST'});
    const d = await r.json();
    if (d.error){ showToast('⚠️ '+d.error,'error'); return; }
    showToast('✅ Laporan terkirim ke ' + (d.channels_sent||[]).join(', '), 'success');
    loadFeed();
  } catch(e){ showToast('Gagal: '+e.message,'error'); }
}

loadFeed();

async function openPrefs(){
  try {
    const r = await fetch('owner_report.php?action=get_prefs');
    const d = await r.json();
    if (d.error) { showToast(d.error,'error'); return; }
    document.getElementById('pf_dr_email').checked = !!d.dr_email;
    document.getElementById('pf_dr_inapp').checked = !!d.dr_inapp;
    document.getElementById('pf_an_email').checked = !!d.an_email;
    document.getElementById('pf_an_inapp').checked = !!d.an_inapp;
    document.getElementById('pf_jam').value = d.jam || '21:00';
    document.querySelectorAll('.pf_konten').forEach(c => { c.checked = (d.konten||[]).includes(c.value); });
    document.getElementById('prefsModal').classList.add('open');
    document.body.style.overflow = 'hidden';
  } catch(e){ showToast('Gagal memuat pengaturan: '+e.message,'error'); }
}

function closePrefs(){
  document.getElementById('prefsModal').classList.remove('open');
  document.body.style.overflow = '';
}

async function savePrefs(){
  const konten = Array.from(document.querySelectorAll('.pf_konten')).filter(c=>c.checked).map(c=>c.value);
  const body = {
    dr_email: document.getElementById('pf_dr_email').checked ? 1 : 0,
    dr_inapp: document.getElementById('pf_dr_inapp').checked ? 1 : 0,
    an_email: document.getElementById('pf_an_email').checked ? 1 : 0,
    an_inapp: document.getElementById('pf_an_inapp').checked ? 1 : 0,
    jam: document.getElementById('pf_jam').value || '21:00',
    konten: konten,
  };
  try {
    const r = await fetch('owner_report.php?action=save_prefs', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()}, body: JSON.stringify(body)
    });
    const d = await r.json();
    if (d.success) { showToast('Pengaturan disimpan','success'); closePrefs(); }
    else showToast(d.error || 'Gagal','error');
  } catch(e){ showToast('Gagal menyimpan: '+e.message,'error'); }
}
</script>
</body>
</html>

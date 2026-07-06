<?php
// ══════════════════════════════════════════════════════
// superadmin/affiliates.php — Affiliate Management
// ══════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';
require_once SA_ROOT . '/../core/SaPermission.php';
require_once SA_ROOT . '/../core/BillingConfig.php';

date_default_timezone_set('Asia/Jakarta');

$action = $_GET['action'] ?? '';

// ── API ACTIONS ───────────────────────────────────────
if ($action) {
    header('Content-Type: application/json');
    $db = Database::get();

    // ── LIST AFFILIATE ────────────────────────────────
    if ($action === 'list_affiliate') {
        SaPermission::require('clients.view');
        $q      = trim($_GET['q'] ?? '');
        $status = $_GET['status'] ?? '';
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 25;
        $offset = ($page - 1) * $limit;

        $where  = ['1=1'];
        $params = [];
        if ($q) {
            $where[] = '(a.nama LIKE ? OR a.email LIKE ? OR a.kode LIKE ?)';
            $like = "%$q%";
            array_push($params, $like, $like, $like);
        }
        if ($status) { $where[] = 'a.status = ?'; $params[] = $status; }

        $whereStr = implode(' AND ', $where);

        $cntSt = $db->prepare("SELECT COUNT(*) FROM hl_affiliate a WHERE $whereStr");
        $cntSt->execute($params);
        $total = (int)$cntSt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT a.id, a.nama, a.email, a.kode, a.status, a.saldo_komisi, a.total_dibayar,
                    a.created_at,
                    COUNT(r.id) AS referral_count
             FROM hl_affiliate a
             LEFT JOIN hl_affiliate_referral r ON r.affiliate_id = a.id
             WHERE $whereStr
             GROUP BY a.id
             ORDER BY a.created_at DESC
             LIMIT $limit OFFSET $offset"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['rows' => $rows, 'total' => $total,
            'pages' => max(1, (int)ceil($total / $limit)), 'page' => $page]);
        exit;
    }

    // ── TOGGLE AFFILIATE STATUS ───────────────────────
    if ($action === 'toggle_affiliate') {
        SaPermission::require('clients.suspend');
        saVerifyCsrf();
        $id        = (int)($_POST['id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        if ($id <= 0 || !in_array($newStatus, ['active', 'suspended'])) {
            echo json_encode(['error' => 'Data tidak valid.']); exit;
        }
        $db->prepare("UPDATE hl_affiliate SET status=? WHERE id=?")->execute([$newStatus, $id]);
        logSuperAdminAction('affiliate_toggle', $id, "Status → $newStatus");
        echo json_encode(['success' => true]);
        exit;
    }

    // ── LIST REFERRAL ─────────────────────────────────
    if ($action === 'list_referral') {
        SaPermission::require('clients.view');
        $q      = trim($_GET['q'] ?? '');
        $status = $_GET['status'] ?? '';
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 25;
        $offset = ($page - 1) * $limit;

        $where  = ['1=1'];
        $params = [];
        if ($q) {
            $where[] = '(a.nama LIKE ? OR a.email LIKE ? OR t.nama_perusahaan LIKE ?)';
            $like = "%$q%";
            array_push($params, $like, $like, $like);
        }
        if ($status) { $where[] = 'r.status = ?'; $params[] = $status; }

        $whereStr = implode(' AND ', $where);

        $cntSt = $db->prepare(
            "SELECT COUNT(*) FROM hl_affiliate_referral r
             LEFT JOIN hl_affiliate a ON a.id = r.affiliate_id
             LEFT JOIN tenants t ON t.id = r.tenant_id
             WHERE $whereStr"
        );
        $cntSt->execute($params);
        $total = (int)$cntSt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT r.id, r.affiliate_id, r.tenant_id, r.status, r.komisi, r.created_at,
                    a.nama AS aff_nama, a.email AS aff_email, a.kode AS aff_kode,
                    t.nama_perusahaan AS tenant_nama
             FROM hl_affiliate_referral r
             LEFT JOIN hl_affiliate a ON a.id = r.affiliate_id
             LEFT JOIN tenants t ON t.id = r.tenant_id
             WHERE $whereStr
             ORDER BY r.created_at DESC
             LIMIT $limit OFFSET $offset"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['rows' => $rows, 'total' => $total,
            'pages' => max(1, (int)ceil($total / $limit)), 'page' => $page]);
        exit;
    }

    // ── LIST PAYOUT ───────────────────────────────────
    if ($action === 'list_payout') {
        SaPermission::require('clients.view');
        $status = $_GET['status'] ?? '';
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 25;
        $offset = ($page - 1) * $limit;

        $where  = ['1=1'];
        $params = [];
        if ($status) { $where[] = 'p.status = ?'; $params[] = $status; }

        $whereStr = implode(' AND ', $where);

        $cntSt = $db->prepare(
            "SELECT COUNT(*) FROM hl_affiliate_payout p WHERE $whereStr"
        );
        $cntSt->execute($params);
        $total = (int)$cntSt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT p.id, p.affiliate_id, p.jumlah, p.status, p.created_at, p.paid_at,
                    p.catatan_sa,
                    a.nama AS aff_nama, a.email AS aff_email,
                    a.saldo_komisi AS aff_saldo
             FROM hl_affiliate_payout p
             LEFT JOIN hl_affiliate a ON a.id = p.affiliate_id
             WHERE $whereStr
             ORDER BY p.created_at DESC
             LIMIT $limit OFFSET $offset"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['rows' => $rows, 'total' => $total,
            'pages' => max(1, (int)ceil($total / $limit)), 'page' => $page]);
        exit;
    }

    // ── MARK PAID (transaksional) ─────────────────────
    if ($action === 'mark_paid') {
        SaPermission::require('billing.topup');
        saVerifyCsrf();
        $id      = (int)($_POST['id'] ?? 0);
        $catatan = trim($_POST['catatan'] ?? '');
        if ($id <= 0) {
            echo json_encode(['error' => 'ID tidak valid.']); exit;
        }
        $db->beginTransaction();
        try {
            $p = $db->prepare(
                "SELECT affiliate_id, jumlah, status FROM hl_affiliate_payout WHERE id=? FOR UPDATE"
            );
            $p->execute([$id]);
            $po = $p->fetch(PDO::FETCH_ASSOC);
            if (!$po || $po['status'] !== 'requested') {
                throw new RuntimeException('Payout tidak valid atau sudah diproses.');
            }
            // Cap bayar ke saldo current (lock affiliate row dulu)
            $affRow = $db->prepare("SELECT saldo_komisi FROM hl_affiliate WHERE id=? FOR UPDATE");
            $affRow->execute([(int)$po['affiliate_id']]);
            $saldoCur = (int)$affRow->fetchColumn();
            $bayar    = min((int)$po['jumlah'], $saldoCur);
            $db->prepare(
                "UPDATE hl_affiliate_payout SET status='paid', jumlah=?, paid_at=NOW(), catatan_sa=? WHERE id=?"
            )->execute([$bayar, $catatan, $id]);
            $db->prepare(
                "UPDATE hl_affiliate
                 SET saldo_komisi  = saldo_komisi  - ?,
                     total_dibayar = total_dibayar + ?
                 WHERE id=?"
            )->execute([$bayar, $bayar, (int)$po['affiliate_id']]);
            $db->commit();
            logSuperAdminAction('affiliate_mark_paid', (int)$po['affiliate_id'],
                "Payout ID $id dibayar Rp " . number_format($bayar) . ". " . $catatan);
            echo json_encode(['success' => true]);
        } catch (Throwable $e) {
            $db->rollBack();
            apiErr($e);
        }
        exit;
    }

    // ── REJECT PAYOUT ─────────────────────────────────
    if ($action === 'reject_payout') {
        SaPermission::require('billing.topup');
        saVerifyCsrf();
        $id      = (int)($_POST['id'] ?? 0);
        $catatan = trim($_POST['catatan'] ?? '');
        if ($id <= 0) {
            echo json_encode(['error' => 'ID tidak valid.']); exit;
        }
        $stmt = $db->prepare(
            "SELECT status FROM hl_affiliate_payout WHERE id=?"
        );
        $stmt->execute([$id]);
        $po = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$po || $po['status'] !== 'requested') {
            echo json_encode(['error' => 'Payout tidak valid atau sudah diproses.']); exit;
        }
        $db->prepare(
            "UPDATE hl_affiliate_payout SET status='rejected', catatan_sa=? WHERE id=?"
        )->execute([$catatan, $id]);
        logSuperAdminAction('affiliate_reject_payout', $id,
            "Payout ID $id ditolak. " . $catatan);
        echo json_encode(['success' => true]);
        exit;
    }

    // ── SET COMMISSION ────────────────────────────────
    if ($action === 'set_commission') {
        SaPermission::require('billing.topup');
        saVerifyCsrf();
        $val = (int)($_POST['commission'] ?? 0);
        if ($val < 0) {
            echo json_encode(['error' => 'Komisi tidak valid.']); exit;
        }
        $saId = saCurrentAdmin()['id'] ?? null;
        BillingConfig::set('affiliate_commission', (string)$val, $saId);
        logSuperAdminAction('affiliate_set_commission', 0,
            "affiliate_commission diset ke Rp " . number_format($val));
        echo json_encode(['success' => true, 'commission' => $val]);
        exit;
    }

    echo json_encode(['error' => 'Action tidak dikenal.']);
    exit;
}

// ── HTML ──────────────────────────────────────────────
$commissionNow = BillingConfig::getInt('affiliate_commission', 100000);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php saRenderHead('Affiliates'); ?>
<style>
.aff-tabs {
  display:flex; gap:6px; margin-bottom:20px;
  background:rgba(10,15,31,.4);
  border:1px solid var(--crease-soft);
  border-radius:12px; padding:5px; width:fit-content;
}
.aff-tab {
  padding:8px 20px; border-radius:9px; font-size:13px; font-weight:600;
  cursor:pointer; border:none;
  background:transparent; color:var(--ash);
  transition:all .15s;
}
.aff-tab:hover  { background:var(--slate-elev); color:var(--glow); }
.aff-tab.active {
  background:rgba(99,102,241,.18); border:1px solid rgba(99,102,241,.3);
  color:var(--white);
  box-shadow: 0 2px 8px #C7D2FE;
}
.aff-panel      { display:none; }
.aff-panel.show { display:block; }
.aff-badge-active    { color:var(--sage); font-weight:600; font-size:12.5px; }
.aff-badge-suspended { color:#F43F5E; font-weight:600; font-size:12.5px; }
.aff-badge-paid      { color:var(--sage); font-weight:600; font-size:12.5px; }
.aff-badge-requested { color:var(--amber); font-weight:600; font-size:12.5px; }
.aff-badge-rejected  { color:#F43F5E; font-weight:600; font-size:12.5px; }
.aff-badge-signup    { color:var(--ash); font-size:12.5px; }
.aff-badge-activated { color:var(--sage); font-size:12.5px; }
</style>
</head>
<body>
<div class="sa-layout">
<?php saRenderNav('affiliates', 'Affiliate Management'); ?>

<div class="sa-page-header">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
      <h1>Affiliate</h1>
      <p>Kelola program affiliate, referral, dan pembayaran komisi</p>
    </div>
    <div class="sa-card" style="padding:12px 18px;display:flex;align-items:center;gap:12px;">
      <label style="font-size:13px;font-weight:600;color:var(--ash);">Komisi/Konversi</label>
      <div style="display:flex;align-items:center;gap:8px;">
        <span style="color:var(--ash-dim);font-size:13px;">Rp</span>
        <input type="number" id="commissionInput" value="<?= (int)$commissionNow ?>"
               min="0" step="1000"
               style="width:120px;background:var(--slate-elev);border:1px solid var(--crease-soft);
                      border-radius:8px;color:var(--white);padding:6px 10px;font-size:13px;">
        <button class="sa-btn-sm sa-btn-primary" onclick="doSetCommission()">Simpan</button>
      </div>
    </div>
  </div>
</div>

<!-- Tabs -->
<div class="aff-tabs">
  <button class="aff-tab active" onclick="switchTab('affiliate')">🤝 Affiliate</button>
  <button class="aff-tab" onclick="switchTab('referral')">🔗 Referral</button>
  <button class="aff-tab" onclick="switchTab('payout')">💸 Payout</button>
</div>

<!-- ══ TAB: AFFILIATE ═════════════════════════════════ -->
<div class="aff-panel show" id="panel-affiliate">
  <div class="sa-card" style="margin-bottom:20px;">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
      <input type="text" id="affSearch" placeholder="Cari nama/email/kode…"
             style="flex:1;min-width:200px;background:var(--slate-elev);border:1px solid var(--crease-soft);
                    border-radius:8px;color:var(--white);padding:8px 12px;font-size:13px;">
      <select id="affStatusFilter"
              style="background:var(--slate-elev);border:1px solid var(--crease-soft);
                     border-radius:8px;color:var(--white);padding:8px 12px;font-size:13px;">
        <option value="">Semua Status</option>
        <option value="active">Aktif</option>
        <option value="suspended">Suspended</option>
      </select>
      <button class="sa-btn-sm sa-btn-primary" onclick="loadAffiliate(1)">Cari</button>
    </div>
  </div>
  <div class="sa-card" style="overflow-x:auto;">
    <table class="sa-table" id="affTable">
      <thead>
        <tr>
          <th>#</th>
          <th>Nama</th>
          <th>Email</th>
          <th>Kode</th>
          <th>Status</th>
          <th>Referral</th>
          <th>Saldo Komisi</th>
          <th>Total Dibayar</th>
          <th>Daftar</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody id="affTbody">
        <tr><td colspan="10" class="sa-skeleton" style="height:40px;"></td></tr>
      </tbody>
    </table>
    <div id="affPager" style="margin-top:12px;display:flex;gap:8px;align-items:center;"></div>
  </div>
</div>

<!-- ══ TAB: REFERRAL ══════════════════════════════════ -->
<div class="aff-panel" id="panel-referral">
  <div class="sa-card" style="margin-bottom:20px;">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
      <input type="text" id="refSearch" placeholder="Cari nama affiliate/tenant…"
             style="flex:1;min-width:200px;background:var(--slate-elev);border:1px solid var(--crease-soft);
                    border-radius:8px;color:var(--white);padding:8px 12px;font-size:13px;">
      <select id="refStatusFilter"
              style="background:var(--slate-elev);border:1px solid var(--crease-soft);
                     border-radius:8px;color:var(--white);padding:8px 12px;font-size:13px;">
        <option value="">Semua Status</option>
        <option value="signup">Signup</option>
        <option value="activated">Aktivasi</option>
      </select>
      <button class="sa-btn-sm sa-btn-primary" onclick="loadReferral(1)">Cari</button>
    </div>
  </div>
  <div class="sa-card" style="overflow-x:auto;">
    <table class="sa-table" id="refTable">
      <thead>
        <tr>
          <th>#</th>
          <th>Affiliate</th>
          <th>Kode</th>
          <th>Tenant</th>
          <th>Status</th>
          <th>Komisi</th>
          <th>Tanggal</th>
        </tr>
      </thead>
      <tbody id="refTbody">
        <tr><td colspan="7" class="sa-skeleton" style="height:40px;"></td></tr>
      </tbody>
    </table>
    <div id="refPager" style="margin-top:12px;display:flex;gap:8px;align-items:center;"></div>
  </div>
</div>

<!-- ══ TAB: PAYOUT ════════════════════════════════════ -->
<div class="aff-panel" id="panel-payout">
  <div class="sa-card" style="margin-bottom:20px;">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
      <select id="payStatusFilter"
              style="background:var(--slate-elev);border:1px solid var(--crease-soft);
                     border-radius:8px;color:var(--white);padding:8px 12px;font-size:13px;">
        <option value="requested">Requested</option>
        <option value="">Semua</option>
        <option value="paid">Paid</option>
        <option value="rejected">Rejected</option>
      </select>
      <button class="sa-btn-sm sa-btn-primary" onclick="loadPayout(1)">Filter</button>
    </div>
  </div>
  <div class="sa-card" style="overflow-x:auto;">
    <table class="sa-table" id="payTable">
      <thead>
        <tr>
          <th>#</th>
          <th>Affiliate</th>
          <th>Email</th>
          <th>Saldo Aff</th>
          <th>Jumlah</th>
          <th>Status</th>
          <th>Tanggal Request</th>
          <th>Dibayar</th>
          <th>Catatan SA</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody id="payTbody">
        <tr><td colspan="10" class="sa-skeleton" style="height:40px;"></td></tr>
      </tbody>
    </table>
    <div id="payPager" style="margin-top:12px;display:flex;gap:8px;align-items:center;"></div>
  </div>
</div>

<?php saRenderNavClose(); ?>

<script>
// ── Helpers ───────────────────────────────────────────
function esc(s){ return (s??'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function fmtRp(v){ return 'Rp '+parseInt(v||0).toLocaleString('id-ID'); }
function fmtDt(s){ if(!s)return '-'; const d=new Date(s.replace(' ','T')); return d.toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'}); }

function buildPager(containerId, page, pages, loadFn){
  const c = document.getElementById(containerId);
  if(pages <= 1){ c.innerHTML=''; return; }
  let h = `<span style="color:var(--ash-dim);font-size:12px;">Hal ${page}/${pages}</span>`;
  if(page > 1)  h += `<button class="sa-btn-sm" onclick="${loadFn}(${page-1})">← Prev</button>`;
  if(page < pages) h += `<button class="sa-btn-sm" onclick="${loadFn}(${page+1})">Next →</button>`;
  c.innerHTML = h;
}

// ── TAB SWITCH ────────────────────────────────────────
const TABS = ['affiliate','referral','payout'];
function switchTab(tab){
  TABS.forEach(t => {
    document.getElementById('panel-'+t).classList.toggle('show', t===tab);
    document.querySelectorAll('.aff-tab').forEach((b,i)=>b.classList.toggle('active', TABS[i]===tab));
  });
  if(tab==='affiliate' && !window._affLoaded){ loadAffiliate(1); window._affLoaded=true; }
  if(tab==='referral'  && !window._refLoaded){ loadReferral(1);  window._refLoaded=true; }
  if(tab==='payout'    && !window._payLoaded){ loadPayout(1);    window._payLoaded=true; }
}

// ── AFFILIATE TAB ─────────────────────────────────────
window._affPage = 1;
function loadAffiliate(page){
  window._affPage = page;
  const q   = document.getElementById('affSearch').value;
  const st  = document.getElementById('affStatusFilter').value;
  const url = `affiliates.php?action=list_affiliate&q=${encodeURIComponent(q)}&status=${encodeURIComponent(st)}&page=${page}`;
  saFetch(url).then(data=>{
    const tb = document.getElementById('affTbody');
    if(!data.rows || !data.rows.length){
      tb.innerHTML = '<tr><td colspan="10" style="color:var(--ash-dim);text-align:center;padding:24px;">Tidak ada data.</td></tr>';
      document.getElementById('affPager').innerHTML = '';
      return;
    }
    tb.innerHTML = data.rows.map(r => `
      <tr>
        <td style="color:var(--ash-dim)">${esc(r.id)}</td>
        <td>${esc(r.nama)}</td>
        <td style="font-size:12px">${esc(r.email)}</td>
        <td><code style="font-size:11px;color:var(--indigo)">${esc(r.kode)}</code></td>
        <td><span class="aff-badge-${esc(r.status)}">${esc(r.status)}</span></td>
        <td style="text-align:center">${esc(r.referral_count)}</td>
        <td>${fmtRp(r.saldo_komisi)}</td>
        <td>${fmtRp(r.total_dibayar)}</td>
        <td style="font-size:12px;color:var(--ash-dim)">${fmtDt(r.created_at)}</td>
        <td>
          ${r.status==='active'
            ? `<button class="sa-btn-sm" style="background:#7F1D1D;color:#FCA5A5;"
                 onclick="doToggleAffiliate(${parseInt(r.id)},'suspended')">Suspend</button>`
            : `<button class="sa-btn-sm" style="background:#14532D;color:#86EFAC;"
                 onclick="doToggleAffiliate(${parseInt(r.id)},'active')">Aktifkan</button>`
          }
        </td>
      </tr>`).join('');
    buildPager('affPager', data.page, data.pages, 'loadAffiliate');
  }).catch(()=>{
    document.getElementById('affTbody').innerHTML =
      '<tr><td colspan="10" style="color:#F43F5E;text-align:center;padding:24px;">Gagal memuat data.</td></tr>';
  });
}

async function doToggleAffiliate(id, newStatus){
  const label = newStatus === 'active' ? 'aktifkan' : 'suspend';
  if(!await lmConfirm(`Yakin ${label} affiliate #${id}?`)) return;
  saPost(`affiliates.php?action=toggle_affiliate`, { id, new_status: newStatus })
    .then(r=>r.json()).then(d=>{
      if(d.error){ saShowToast(d.error,'error'); return; }
      saShowToast('Status affiliate diperbarui.');
      loadAffiliate(window._affPage);
    });
}

// ── REFERRAL TAB ──────────────────────────────────────
window._refPage = 1;
function loadReferral(page){
  window._refPage = page;
  const q  = document.getElementById('refSearch').value;
  const st = document.getElementById('refStatusFilter').value;
  const url = `affiliates.php?action=list_referral&q=${encodeURIComponent(q)}&status=${encodeURIComponent(st)}&page=${page}`;
  saFetch(url).then(data=>{
    const tb = document.getElementById('refTbody');
    if(!data.rows || !data.rows.length){
      tb.innerHTML = '<tr><td colspan="7" style="color:var(--ash-dim);text-align:center;padding:24px;">Tidak ada data.</td></tr>';
      document.getElementById('refPager').innerHTML = '';
      return;
    }
    tb.innerHTML = data.rows.map(r => `
      <tr>
        <td style="color:var(--ash-dim)">${esc(r.id)}</td>
        <td>${esc(r.aff_nama)}</td>
        <td><code style="font-size:11px;color:var(--indigo)">${esc(r.aff_kode)}</code></td>
        <td>${esc(r.tenant_nama ?? '-')}</td>
        <td><span class="aff-badge-${esc(r.status)}">${esc(r.status)}</span></td>
        <td>${r.komisi ? fmtRp(r.komisi) : '-'}</td>
        <td style="font-size:12px;color:var(--ash-dim)">${fmtDt(r.created_at)}</td>
      </tr>`).join('');
    buildPager('refPager', data.page, data.pages, 'loadReferral');
  }).catch(()=>{
    document.getElementById('refTbody').innerHTML =
      '<tr><td colspan="7" style="color:#F43F5E;text-align:center;padding:24px;">Gagal memuat data.</td></tr>';
  });
}

// ── PAYOUT TAB ────────────────────────────────────────
window._payPage = 1;
function loadPayout(page){
  window._payPage = page;
  const st  = document.getElementById('payStatusFilter').value;
  const url = `affiliates.php?action=list_payout&status=${encodeURIComponent(st)}&page=${page}`;
  saFetch(url).then(data=>{
    const tb = document.getElementById('payTbody');
    if(!data.rows || !data.rows.length){
      tb.innerHTML = '<tr><td colspan="10" style="color:var(--ash-dim);text-align:center;padding:24px;">Tidak ada data.</td></tr>';
      document.getElementById('payPager').innerHTML = '';
      return;
    }
    tb.innerHTML = data.rows.map(r => `
      <tr>
        <td style="color:var(--ash-dim)">${esc(r.id)}</td>
        <td>${esc(r.aff_nama)}</td>
        <td style="font-size:12px">${esc(r.aff_email)}</td>
        <td style="font-size:12px">${fmtRp(r.aff_saldo)}</td>
        <td style="font-weight:600">${fmtRp(r.jumlah)}</td>
        <td><span class="aff-badge-${esc(r.status)}">${esc(r.status)}</span></td>
        <td style="font-size:12px;color:var(--ash-dim)">${fmtDt(r.created_at)}</td>
        <td style="font-size:12px;color:var(--ash-dim)">${fmtDt(r.paid_at)}</td>
        <td style="font-size:12px;color:var(--ash-dim)">${esc(r.catatan_sa ?? '')}</td>
        <td>
          ${r.status==='requested' ? `
            <button class="sa-btn-sm" style="background:#14532D;color:#86EFAC;"
              onclick="doMarkPaid(${parseInt(r.id)})">Bayar</button>
            <button class="sa-btn-sm" style="background:#7F1D1D;color:#FCA5A5;margin-top:4px;"
              onclick="doRejectPayout(${parseInt(r.id)})">Tolak</button>
          ` : '-'}
        </td>
      </tr>`).join('');
    buildPager('payPager', data.page, data.pages, 'loadPayout');
  }).catch(()=>{
    document.getElementById('payTbody').innerHTML =
      '<tr><td colspan="10" style="color:#F43F5E;text-align:center;padding:24px;">Gagal memuat data.</td></tr>';
  });
}

async function doMarkPaid(id){
  const catatan = await lmPrompt(`Catatan pembayaran payout #${id} (opsional):`);
  if(catatan === null) return; // user cancel
  saPost(`affiliates.php?action=mark_paid`, { id, catatan: catatan ?? '' })
    .then(r=>r.json()).then(d=>{
      if(d.error){ saShowToast(d.error,'error'); return; }
      saShowToast('Payout ditandai PAID. Saldo affiliate dikurangi.');
      loadPayout(window._payPage);
    });
}

async function doRejectPayout(id){
  const catatan = await lmPrompt(`Alasan penolakan payout #${id}:`);
  if(catatan === null) return; // user cancel
  if(!catatan.trim()){ saShowToast('Catatan penolakan wajib diisi.','error'); return; }
  saPost(`affiliates.php?action=reject_payout`, { id, catatan })
    .then(r=>r.json()).then(d=>{
      if(d.error){ saShowToast(d.error,'error'); return; }
      saShowToast('Payout ditolak.');
      loadPayout(window._payPage);
    });
}

// ── SET COMMISSION ────────────────────────────────────
async function doSetCommission(){
  const val = parseInt(document.getElementById('commissionInput').value);
  if(isNaN(val) || val < 0){ saShowToast('Nilai komisi tidak valid.','error'); return; }
  if(!await lmConfirm(`Set komisi affiliate ke Rp ${val.toLocaleString('id-ID')} per konversi?`)) return;
  saPost(`affiliates.php?action=set_commission`, { commission: val })
    .then(r=>r.json()).then(d=>{
      if(d.error){ saShowToast(d.error,'error'); return; }
      saShowToast('Komisi affiliate berhasil diperbarui.');
    });
}

// ── INIT ──────────────────────────────────────────────
window._affLoaded = true;
loadAffiliate(1);
</script>

</body>
</html>

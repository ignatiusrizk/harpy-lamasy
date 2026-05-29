<?php
// ══════════════════════════════════════════════════════
// superadmin/clients.php — Client & Outlet List
// ══════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';

date_default_timezone_set('Asia/Jakarta');

$action = $_GET['action'] ?? '';

// ── API ACTIONS ───────────────────────────────────────
if ($action) {
    header('Content-Type: application/json');
    $db = Database::get();

    // ── LIST TENANTS ──────────────────────────────────
    if ($action === 'list') {
        $q          = trim($_GET['q'] ?? '');
        $status     = $_GET['status'] ?? '';
        $coinFilter = $_GET['coin_filter'] ?? '';
        $actFilter  = $_GET['activity_filter'] ?? '';
        $joinFilter = $_GET['join_filter'] ?? '';
        $page       = max(1, (int)($_GET['page'] ?? 1));
        $sort       = in_array($_GET['sort'] ?? '', ['nama','coin_balance','created_at','last_login','outlets']) ? $_GET['sort'] : 'created_at';
        $dir        = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $limit      = 20;
        $offset     = ($page - 1) * $limit;

        $sortMap = [
            'nama'         => 't.nama_perusahaan',
            'coin_balance' => 't.coin_balance',
            'created_at'   => 't.provisioned_at',
            'last_login'   => 'last_login',
            'outlets'      => 'outlet_count',
        ];
        $sortCol = $sortMap[$sort] ?? 't.provisioned_at';

        $where  = ['1=1'];
        $params = [];

        if ($q) {
            $where[] = '(t.nama_perusahaan LIKE ? OR t.owner_name LIKE ? OR t.owner_wa LIKE ? OR t.slug LIKE ?)';
            $like = "%$q%";
            array_push($params, $like, $like, $like, $like);
        }
        if ($status)  { $where[] = 't.status = ?'; $params[] = $status; }
        if ($coinFilter === 'kritis') $where[] = 't.coin_balance < 5000';
        elseif ($coinFilter === 'rendah') $where[] = 't.coin_balance < 10000';
        if ($joinFilter === 'bulan_ini') {
            $where[] = 'MONTH(t.provisioned_at) = MONTH(NOW()) AND YEAR(t.provisioned_at) = YEAR(NOW())';
        } elseif ($joinFilter === 'bulan_lalu') {
            $where[] = 'MONTH(t.provisioned_at) = MONTH(NOW() - INTERVAL 1 MONTH) AND YEAR(t.provisioned_at) = YEAR(NOW() - INTERVAL 1 MONTH)';
        }

        $havingFilters = ['aktif_7','tidak_aktif_14','belum_login'];
        $needHaving    = in_array($actFilter, $havingFilters);
        $havingStr = '';
        if ($needHaving) {
            if ($actFilter === 'aktif_7')          $havingStr = 'HAVING MAX(u.last_login) > NOW() - INTERVAL 7 DAY';
            elseif ($actFilter === 'tidak_aktif_14') $havingStr = 'HAVING (MAX(u.last_login) < NOW() - INTERVAL 14 DAY OR MAX(u.last_login) IS NULL)';
            elseif ($actFilter === 'belum_login')  $havingStr = 'HAVING MAX(u.last_login) IS NULL';
        }

        $whereStr = implode(' AND ', $where);

        $cntStmt = $db->prepare(
            "SELECT COUNT(*) FROM (
               SELECT t.id FROM tenants t
               LEFT JOIN hl_users u ON u.tenant_id = t.id
               WHERE $whereStr
               GROUP BY t.id
               $havingStr
             ) x"
        );
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT t.*,
               MAX(u.last_login) AS last_login,
               (SELECT COUNT(*) FROM outlets o WHERE o.tenant_id = t.id) AS outlet_count,
               (SELECT COUNT(*) FROM outlets o WHERE o.tenant_id = t.id AND o.status = 'active') AS outlet_active
             FROM tenants t
             LEFT JOIN hl_users u ON u.tenant_id = t.id
             WHERE $whereStr
             GROUP BY t.id
             $havingStr
             ORDER BY $sortCol $dir
             LIMIT $limit OFFSET $offset"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        echo json_encode(['rows' => $rows, 'total' => $total,
            'pages' => max(1, (int)ceil($total / $limit)), 'page' => $page]);
        exit;
    }

    // ── LIST OUTLETS ──────────────────────────────────
    if ($action === 'list_outlets') {
        $q      = trim($_GET['q'] ?? '');
        $status = $_GET['status'] ?? '';
        $tid    = (int)($_GET['tenant_id'] ?? 0);
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $sort   = in_array($_GET['sort'] ?? '', ['nama_outlet','status','coin_balance','created_at']) ? $_GET['sort'] : 'created_at';
        $dir    = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $limit  = 25;
        $offset = ($page - 1) * $limit;

        $sortMap = [
            'nama_outlet'  => 'o.nama_outlet',
            'status'       => 'o.status',
            'coin_balance' => 'o.coin_balance',
            'created_at'   => 'o.created_at',
        ];
        $sortCol = $sortMap[$sort] ?? 'o.created_at';

        $where  = ['1=1'];
        $params = [];

        if ($q) {
            $where[] = '(o.nama_outlet LIKE ? OR t.nama_perusahaan LIKE ? OR t.owner_name LIKE ? OR o.kota LIKE ?)';
            $like = "%$q%";
            array_push($params, $like, $like, $like, $like);
        }
        if ($status) { $where[] = 'o.status = ?'; $params[] = $status; }
        if ($tid > 0) { $where[] = 'o.tenant_id = ?'; $params[] = $tid; }

        $whereStr = implode(' AND ', $where);

        $cntS = $db->prepare("SELECT COUNT(*) FROM outlets o JOIN tenants t ON t.id = o.tenant_id WHERE $whereStr");
        $cntS->execute($params);
        $total = (int)$cntS->fetchColumn();

        $stmt = $db->prepare(
            "SELECT o.id, o.tenant_id, o.nama_outlet, o.slug, o.kota, o.status,
                    o.coin_balance, o.trial_coin_balance, o.is_main, o.setup_done, o.created_at,
                    t.nama_perusahaan, t.owner_name, t.slug AS tenant_slug
             FROM outlets o
             JOIN tenants t ON t.id = o.tenant_id
             WHERE $whereStr
             ORDER BY $sortCol $dir
             LIMIT $limit OFFSET $offset"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        echo json_encode(['rows' => $rows, 'total' => $total,
            'pages' => max(1, (int)ceil($total / $limit)), 'page' => $page]);
        exit;
    }

    // ── TOPUP ─────────────────────────────────────────
    if ($action === 'topup') {
        saVerifyCsrf();
        $tenantId = (int)($_POST['tenant_id'] ?? 0);
        $amount   = (int)($_POST['amount'] ?? 0);
        $note     = trim($_POST['note'] ?? '');

        if ($tenantId <= 0 || $amount <= 0) {
            echo json_encode(['error' => 'Data tidak valid.']); exit;
        }
        try {
            $db->beginTransaction();
            $stm = $db->prepare("SELECT coin_balance FROM tenants WHERE id=?");
            $stm->execute([$tenantId]);
            $bal    = (int)$stm->fetchColumn();
            $newBal = $bal + $amount;

            $db->prepare("UPDATE tenants SET coin_balance = ? WHERE id = ?")->execute([$newBal, $tenantId]);
            $db->prepare(
                "INSERT INTO coin_ledger (tenant_id, type, amount, feature_used, description, balance_after)
                 VALUES (?, 'topup', ?, 'manual_topup', ?, ?)"
            )->execute([$tenantId, $amount, $note ?: 'Manual topup by super admin', $newBal]);

            $db->commit();
            logSuperAdminAction('topup_coin', $tenantId, "Topup $amount coin. Note: $note");
            echo json_encode(['success' => true, 'new_balance' => $newBal]);
        } catch (Throwable $e) {
            $db->rollBack();
            echo json_encode(['error' => 'Gagal topup: ' . $e->getMessage()]);
        }
        exit;
    }

    // ── TOGGLE STATUS ─────────────────────────────────
    if ($action === 'toggle_status') {
        saVerifyCsrf();
        $tenantId  = (int)($_POST['tenant_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        if (!in_array($newStatus, ['active','suspended'])) {
            echo json_encode(['error' => 'Status tidak valid.']); exit;
        }
        $db->prepare("UPDATE tenants SET status = ? WHERE id = ?")->execute([$newStatus, $tenantId]);
        logSuperAdminAction('toggle_status', $tenantId, "Status diubah ke $newStatus");
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
<?php saRenderHead('Clients'); ?>
<style>
.cl-tabs { display:flex; gap:4px; margin-bottom:20px; }
.cl-tab  {
  padding:9px 20px; border-radius:8px; font-size:13px; font-weight:600;
  cursor:pointer; border:1px solid rgba(255,255,255,.1);
  background:rgba(255,255,255,.04); color:rgba(255,255,255,.5);
  transition:all .15s;
}
.cl-tab:hover  { background:rgba(255,255,255,.07); color:rgba(255,255,255,.8); }
.cl-tab.active { background:var(--sa-l); border-color:var(--sa); color:var(--white); }
.cl-panel      { display:none; }
.cl-panel.show { display:block; }

.outlet-status-active    { color:#6EE7B7; font-weight:600; }
.outlet-status-pending   { color:#FCD34D; font-weight:600; }
.outlet-status-suspended { color:#FCA5A5; font-weight:600; }
.outlet-status-inactive  { color:rgba(255,255,255,.3); }

.main-badge {
  display:inline-block; font-size:9px; font-weight:700; letter-spacing:.06em;
  text-transform:uppercase; padding:2px 6px; border-radius:10px;
  background:rgba(99,102,241,.2); color:var(--sa); margin-left:4px;
  vertical-align:middle;
}
.setup-done   { color:#6EE7B7; font-size:12px; }
.setup-notyet { color:rgba(255,255,255,.25); font-size:12px; }
</style>
</head>
<body>
<div class="sa-layout">
<?php saRenderNav('clients', 'Client Management'); ?>

<div class="sa-page-header">
  <h1>Clients</h1>
  <p>Kelola seluruh tenant dan outlet platform</p>
</div>

<!-- Tabs -->
<div class="cl-tabs">
  <button class="cl-tab active" onclick="switchTab('tenants')">🏢 Tenants</button>
  <button class="cl-tab" onclick="switchTab('outlets')">🏪 Outlets</button>
</div>

<!-- ══ TAB: TENANTS ══════════════════════════════════════ -->
<div class="cl-panel show" id="panel-tenants">
  <div class="sa-card" style="margin-bottom:20px;">
    <div class="sa-filter-bar">
      <input type="text" id="tSearch" placeholder="Cari nama bisnis, owner, WA, slug..." style="flex:1;min-width:200px;" oninput="debounceLoad('tenants')"/>
      <select id="tStatus" onchange="loadTenants()">
        <option value="">Semua Status</option>
        <option value="active">Aktif</option>
        <option value="suspended">Suspended</option>
      </select>
      <select id="tCoin" onchange="loadTenants()">
        <option value="">Semua Coin</option>
        <option value="rendah">Coin Rendah (&lt;10K)</option>
        <option value="kritis">Coin Kritis (&lt;5K)</option>
      </select>
      <select id="tActivity" onchange="loadTenants()">
        <option value="">Semua Aktivitas</option>
        <option value="aktif_7">Aktif 7 hari</option>
        <option value="tidak_aktif_14">Tidak aktif 14 hari</option>
        <option value="belum_login">Belum pernah login</option>
      </select>
      <select id="tJoin" onchange="loadTenants()">
        <option value="">Semua Waktu Daftar</option>
        <option value="bulan_ini">Bulan ini</option>
        <option value="bulan_lalu">Bulan lalu</option>
      </select>
    </div>

    <div class="sa-table-wrap">
      <table class="sa-table">
        <thead>
          <tr>
            <th><a href="#" onclick="sortTenants('nama');return false;" style="color:inherit;text-decoration:none;">Nama Bisnis ↕</a></th>
            <th>Owner</th>
            <th>WA</th>
            <th><a href="#" onclick="sortTenants('outlets');return false;" style="color:inherit;text-decoration:none;">Outlets ↕</a></th>
            <th><a href="#" onclick="sortTenants('status');return false;" style="color:inherit;text-decoration:none;">Status ↕</a></th>
            <th><a href="#" onclick="sortTenants('coin_balance');return false;" style="color:inherit;text-decoration:none;">Coin ↕</a></th>
            <th><a href="#" onclick="sortTenants('last_login');return false;" style="color:inherit;text-decoration:none;">Last Login ↕</a></th>
            <th><a href="#" onclick="sortTenants('created_at');return false;" style="color:inherit;text-decoration:none;">Bergabung ↕</a></th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody id="tenantsBody">
          <tr><td colspan="9" style="text-align:center;color:rgba(255,255,255,.35);padding:32px;">Memuat data...</td></tr>
        </tbody>
      </table>
    </div>
    <div class="sa-pagination" id="tenantsPagination"></div>
  </div>
</div>

<!-- ══ TAB: OUTLETS ══════════════════════════════════════ -->
<div class="cl-panel" id="panel-outlets">
  <div class="sa-card" style="margin-bottom:20px;">
    <div class="sa-filter-bar">
      <input type="text" id="oSearch" placeholder="Cari nama outlet, bisnis, owner, kota..." style="flex:1;min-width:200px;" oninput="debounceLoad('outlets')"/>
      <select id="oStatus" onchange="loadOutlets()">
        <option value="">Semua Status</option>
        <option value="active">Active</option>
        <option value="pending">Pending</option>
        <option value="suspended">Suspended</option>
        <option value="inactive">Inactive</option>
      </select>
      <select id="oSort" onchange="loadOutlets()">
        <option value="created_at">Terbaru</option>
        <option value="nama_outlet">Nama A–Z</option>
        <option value="status">Status</option>
        <option value="coin_balance">Coin</option>
      </select>
    </div>

    <div class="sa-table-wrap">
      <table class="sa-table">
        <thead>
          <tr>
            <th>Nama Outlet</th>
            <th>Bisnis / Tenant</th>
            <th>Kota</th>
            <th>Status</th>
            <th>Coin</th>
            <th>Setup</th>
            <th>Dibuat</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody id="outletsBody">
          <tr><td colspan="8" style="text-align:center;color:rgba(255,255,255,.35);padding:32px;">Memuat data...</td></tr>
        </tbody>
      </table>
    </div>
    <div class="sa-pagination" id="outletsPagination"></div>
  </div>
</div>

<!-- Topup Modal -->
<div class="sa-modal-overlay" id="topupModal">
  <div class="sa-modal">
    <h3>🪙 Topup Coin</h3>
    <input type="hidden" id="topupTenantId"/>
    <div class="form-group">
      <label>Tenant</label>
      <input type="text" id="topupTenantName" readonly style="opacity:.6;"/>
    </div>
    <div class="form-group">
      <label>Jumlah Coin</label>
      <input type="number" id="topupAmount" placeholder="Contoh: 50000" min="1" required/>
    </div>
    <div class="form-group">
      <label>Keterangan</label>
      <input type="text" id="topupNote" placeholder="Alasan topup..."/>
    </div>
    <div class="sa-modal-footer">
      <button class="sa-btn sa-btn-outline" onclick="closeModal('topupModal')">Batal</button>
      <button class="sa-btn sa-btn-primary" onclick="submitTopup()">Topup Sekarang</button>
    </div>
  </div>
</div>

<?php saRenderNavClose(); ?>

<script>
// ── State ─────────────────────────────────────────────
let tPage = 1, tSort = 'created_at', tDir = 'DESC';
let oPage = 1, oSort = 'created_at', oDir  = 'DESC';
let debTimers = {};
let activeTab = 'tenants';

// ── Tab switching ─────────────────────────────────────
function switchTab(tab) {
  activeTab = tab;
  document.querySelectorAll('.cl-tab').forEach((el, i) => {
    el.classList.toggle('active', ['tenants','outlets'][i] === tab);
  });
  document.querySelectorAll('.cl-panel').forEach(el => el.classList.remove('show'));
  document.getElementById('panel-' + tab).classList.add('show');
  if (tab === 'tenants') loadTenants();
  else loadOutlets();
}

function debounceLoad(tab) {
  clearTimeout(debTimers[tab]);
  debTimers[tab] = setTimeout(() => {
    if (tab === 'tenants') { tPage = 1; loadTenants(); }
    else { oPage = 1; loadOutlets(); }
  }, 380);
}

// ── Helpers ───────────────────────────────────────────
function relTime(ts) {
  if (!ts) return '<span style="color:rgba(255,255,255,.3);">Belum</span>';
  const diff = Math.floor((Date.now() - new Date(ts).getTime()) / 1000);
  if (diff < 3600)  return Math.floor(diff/60) + ' mnt lalu';
  if (diff < 86400) return Math.floor(diff/3600) + ' jam lalu';
  return Math.floor(diff/86400) + ' hari lalu';
}
function coinHtml(bal) {
  const n = parseInt(bal)||0;
  const f = n.toLocaleString('id-ID');
  if (n < 5000)  return `<span class="coin-kritis">${f}</span>`;
  if (n < 10000) return `<span class="coin-rendah">${f}</span>`;
  return `<span class="coin-ok">${f}</span>`;
}
function statusBadge(s) {
  const lbl = {active:'Aktif', trial:'Trial', suspended:'Suspended'};
  const cls = {active:'active', trial:'trial', suspended:'suspended'};
  return `<span class="sa-badge sa-badge-${cls[s]||'indigo'}">${lbl[s]||s}</span>`;
}
function outletStatusHtml(s) {
  const map = {
    active:    ['outlet-status-active',    'Active'],
    pending:   ['outlet-status-pending',   'Pending'],
    suspended: ['outlet-status-suspended', 'Suspended'],
    inactive:  ['outlet-status-inactive',  'Inactive'],
  };
  const [cls, lbl] = map[s] || ['outlet-status-inactive', s];
  return `<span class="${cls}">${lbl}</span>`;
}
function esc(s) { const d=document.createElement('div'); d.textContent=String(s||''); return d.innerHTML; }
function fmtDate(d) { return d ? new Date(d).toLocaleDateString('id-ID', {day:'2-digit',month:'short',year:'numeric'}) : '-'; }

function renderPagination(page, pages, total, onGoto, containerId) {
  const el = document.getElementById(containerId);
  let html = `<span style="font-size:12px;color:rgba(255,255,255,.35);margin-right:10px;">Total: ${total}</span>`;
  html += `<button class="sa-btn sa-btn-sm sa-btn-outline${page<=1?' disabled':''}" onclick="${onGoto}(${page-1})">‹ Prev</button>`;
  for (let i = Math.max(1,page-2); i <= Math.min(pages,page+2); i++) {
    html += `<button class="sa-btn sa-btn-sm ${i===page?'sa-btn-primary':'sa-btn-outline'}" onclick="${onGoto}(${i})">${i}</button>`;
  }
  html += `<button class="sa-btn sa-btn-sm sa-btn-outline${page>=pages?' disabled':''}" onclick="${onGoto}(${page+1})">Next ›</button>`;
  el.innerHTML = html;
}

// ── TENANTS ───────────────────────────────────────────
function sortTenants(col) {
  if (tSort === col) tDir = tDir === 'ASC' ? 'DESC' : 'ASC';
  else { tSort = col; tDir = col === 'nama' ? 'ASC' : 'DESC'; }
  loadTenants();
}

function loadTenants() {
  const params = new URLSearchParams({
    action:          'list',
    q:               document.getElementById('tSearch').value,
    status:          document.getElementById('tStatus').value,
    coin_filter:     document.getElementById('tCoin').value,
    activity_filter: document.getElementById('tActivity').value,
    join_filter:     document.getElementById('tJoin').value,
    page: tPage, sort: tSort, dir: tDir,
  });
  fetch('clients.php?' + params)
    .then(r => r.json()).then(data => {
      renderTenants(data.rows);
      renderPagination(data.page, data.pages, data.total, 'gotoTenants', 'tenantsPagination');
    }).catch(() => {
      document.getElementById('tenantsBody').innerHTML =
        '<tr><td colspan="9" style="text-align:center;color:#FCA5A5;padding:24px;">Gagal memuat data.</td></tr>';
    });
}

function gotoTenants(p) { tPage = p; loadTenants(); }

function renderTenants(rows) {
  const tbody = document.getElementById('tenantsBody');
  if (!rows?.length) {
    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:rgba(255,255,255,.35);padding:32px;">Tidak ada data.</td></tr>';
    return;
  }
  tbody.innerHTML = rows.map(t => {
    const opp   = t.status === 'suspended' ? 'active' : 'suspended';
    const btnLbl = t.status === 'suspended' ? 'Aktifkan' : 'Suspend';
    const btnCls = t.status === 'suspended' ? 'sa-btn-green' : 'sa-btn-danger';
    const outletInfo = parseInt(t.outlet_count) > 0
      ? `<span style="font-weight:700;color:var(--white);">${t.outlet_count}</span>
         <span style="font-size:10px;color:rgba(255,255,255,.35);margin-left:3px;">outlet</span>
         ${parseInt(t.outlet_active) > 0 ? `<span style="font-size:10px;color:#6EE7B7;margin-left:4px;">(${t.outlet_active} aktif)</span>` : ''}`
      : `<span style="color:rgba(255,255,255,.25);font-size:12px;">—</span>`;
    return `<tr>
      <td>
        <strong>${esc(t.nama_perusahaan)}</strong><br>
        <small style="color:rgba(255,255,255,.3);font-family:var(--mono);font-size:10px;">${esc(t.slug)}</small>
      </td>
      <td>${esc(t.owner_name)}</td>
      <td><a href="https://wa.me/${esc(t.owner_wa)}" target="_blank" style="color:#86efac;text-decoration:none;font-family:var(--mono);font-size:12px;">${esc(t.owner_wa)}</a></td>
      <td>${outletInfo}</td>
      <td>${statusBadge(t.status)}</td>
      <td>${coinHtml(t.coin_balance)}</td>
      <td style="font-size:12px;">${relTime(t.last_login)}</td>
      <td style="font-size:12px;color:rgba(255,255,255,.4);">${fmtDate(t.provisioned_at)}</td>
      <td>
        <a href="client_detail.php?id=${t.id}" class="sa-btn sa-btn-sm sa-btn-outline">Detail</a>
        <a href="https://wa.me/${esc(t.owner_wa)}" target="_blank" class="sa-btn sa-btn-sm sa-btn-wa" style="margin-left:3px;">WA</a>
        <button class="sa-btn sa-btn-sm sa-btn-primary" style="margin-left:3px;" onclick="openTopup(${t.id},'${esc(t.nama_perusahaan)}')">Topup</button>
        <button class="sa-btn sa-btn-sm ${btnCls}" style="margin-left:3px;" onclick="toggleStatus(${t.id},'${opp}','${esc(t.nama_perusahaan)}')">${btnLbl}</button>
      </td>
    </tr>`;
  }).join('');
}

// ── OUTLETS ───────────────────────────────────────────
let outletsLoaded = false;

function loadOutlets() {
  outletsLoaded = true;
  const params = new URLSearchParams({
    action:  'list_outlets',
    q:       document.getElementById('oSearch').value,
    status:  document.getElementById('oStatus').value,
    page:    oPage,
    sort:    document.getElementById('oSort').value,
    dir:     oDir,
  });
  fetch('clients.php?' + params)
    .then(r => r.json()).then(data => {
      renderOutlets(data.rows);
      renderPagination(data.page, data.pages, data.total, 'gotoOutlets', 'outletsPagination');
    }).catch(() => {
      document.getElementById('outletsBody').innerHTML =
        '<tr><td colspan="8" style="text-align:center;color:#FCA5A5;padding:24px;">Gagal memuat data.</td></tr>';
    });
}

function gotoOutlets(p) { oPage = p; loadOutlets(); }

function renderOutlets(rows) {
  const tbody = document.getElementById('outletsBody');
  if (!rows?.length) {
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:rgba(255,255,255,.35);padding:32px;">Tidak ada outlet.</td></tr>';
    return;
  }
  tbody.innerHTML = rows.map(o => {
    const mainBadge = parseInt(o.is_main) ? '<span class="main-badge">Utama</span>' : '';
    const setupHtml = parseInt(o.setup_done)
      ? '<span class="setup-done">✓ Done</span>'
      : '<span class="setup-notyet">Belum</span>';
    return `<tr>
      <td>
        <strong>${esc(o.nama_outlet)}</strong>${mainBadge}<br>
        <small style="color:rgba(255,255,255,.3);font-family:var(--mono);font-size:10px;">${esc(o.slug)}</small>
      </td>
      <td>
        <a href="client_detail.php?id=${o.tenant_id}" style="color:rgba(255,255,255,.8);text-decoration:none;font-weight:600;">${esc(o.nama_perusahaan)}</a><br>
        <small style="color:rgba(255,255,255,.35);font-size:11px;">${esc(o.owner_name)}</small>
      </td>
      <td style="font-size:12px;color:rgba(255,255,255,.5);">${esc(o.kota || '—')}</td>
      <td>${outletStatusHtml(o.status)}</td>
      <td>${coinHtml(o.coin_balance)}</td>
      <td>${setupHtml}</td>
      <td style="font-size:12px;color:rgba(255,255,255,.4);">${fmtDate(o.created_at)}</td>
      <td>
        <a href="client_detail.php?id=${o.tenant_id}#tab-outlets" class="sa-btn sa-btn-sm sa-btn-outline">Detail</a>
      </td>
    </tr>`;
  }).join('');
}

// ── TOPUP & STATUS ────────────────────────────────────
function openTopup(id, nama) {
  document.getElementById('topupTenantId').value = id;
  document.getElementById('topupTenantName').value = nama;
  document.getElementById('topupAmount').value = '';
  document.getElementById('topupNote').value = '';
  document.getElementById('topupModal').classList.add('open');
}
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function submitTopup() {
  const id     = document.getElementById('topupTenantId').value;
  const amount = document.getElementById('topupAmount').value;
  const note   = document.getElementById('topupNote').value;
  if (!amount || amount < 1) { saShowToast('Jumlah coin harus > 0', 'error'); return; }
  saPost('clients.php?action=topup', { tenant_id: id, amount, note })
    .then(r => r.json()).then(d => {
      if (d.error) { saShowToast(d.error, 'error'); return; }
      saShowToast('Topup berhasil! Saldo baru: ' + parseInt(d.new_balance).toLocaleString('id-ID'), 'success');
      closeModal('topupModal');
      loadTenants();
    });
}

function toggleStatus(id, newStatus, nama) {
  if (!confirm(`Yakin ${newStatus === 'suspended' ? 'suspend' : 'aktifkan'} ${nama}?`)) return;
  saPost('clients.php?action=toggle_status', { tenant_id: id, new_status: newStatus })
    .then(r => r.json()).then(d => {
      if (d.error) { saShowToast(d.error, 'error'); return; }
      saShowToast('Status berhasil diubah.', 'success');
      loadTenants();
    });
}

// Initial load
loadTenants();
</script>
</body>
</html>

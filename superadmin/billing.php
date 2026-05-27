<?php
// ══════════════════════════════════════════════════════
// superadmin/billing.php — Revenue Overview (v2 — saas_manual_payments)
// ══════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';

date_default_timezone_set('Asia/Jakarta');

$action = $_GET['action'] ?? '';

if ($action) {
    header('Content-Type: application/json');
    $db = Database::get();

    // ── stats: revenue cards + pending alert ──────────────────────────────
    if ($action === 'stats') {
        $m  = (int)date('n');  $y  = (int)date('Y');
        $lm = (int)date('n', strtotime('-1 month'));
        $ly = (int)date('Y', strtotime('-1 month'));

        function smpStats(PDO $db, int $month, int $year): array {
            $s = $db->prepare(
                "SELECT
                   COALESCE(SUM(CASE WHEN type='setup_fee'  THEN nominal_dibayar END),0) AS setup_fee,
                   COALESCE(SUM(CASE WHEN type='coin_topup' THEN nominal_dibayar END),0) AS coin_topup,
                   COALESCE(SUM(CASE WHEN type='adjustment' THEN nominal_dibayar END),0) AS adjustment,
                   COALESCE(SUM(nominal_dibayar),0) AS grand_total,
                   COALESCE(SUM(coin_dikreditkan),0) AS total_coin,
                   COUNT(*) AS txn_count
                 FROM saas_manual_payments
                 WHERE status='confirmed'
                   AND MONTH(tanggal_bayar)=? AND YEAR(tanggal_bayar)=?"
            );
            $s->execute([$month, $year]);
            return $s->fetch(PDO::FETCH_ASSOC) ?: [];
        }

        $ytdS = $db->prepare(
            "SELECT COALESCE(SUM(nominal_dibayar),0) AS total,
                    COALESCE(SUM(coin_dikreditkan),0) AS total_coin
             FROM saas_manual_payments WHERE status='confirmed' AND YEAR(tanggal_bayar)=?"
        );
        $ytdS->execute([$y]);
        $ytdRow = $ytdS->fetch(PDO::FETCH_ASSOC);

        $pendingCount = (int)$db->query(
            "SELECT COUNT(*) FROM saas_manual_payments WHERE status='pending'"
        )->fetchColumn();

        echo json_encode([
            'bulan_ini'     => smpStats($db, $m, $y),
            'bulan_lalu'    => smpStats($db, $lm, $ly),
            'ytd'           => (float)($ytdRow['total'] ?? 0),
            'ytd_coin'      => (int)($ytdRow['total_coin'] ?? 0),
            'pending_count' => $pendingCount,
        ]);
        exit;
    }

    // ── chart: stacked bar 6 months ───────────────────────────────────────
    if ($action === 'chart') {
        // Build 6 month labels
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $ts       = strtotime("-$i month");
            $months[] = date('Y-m', $ts);
        }

        $rows = $db->query(
            "SELECT DATE_FORMAT(tanggal_bayar,'%Y-%m') AS mon,
                    type,
                    COALESCE(SUM(nominal_dibayar),0) AS rev
             FROM saas_manual_payments
             WHERE status='confirmed'
               AND tanggal_bayar >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH),'%Y-%m-01')
             GROUP BY mon, type
             ORDER BY mon"
        )->fetchAll(PDO::FETCH_ASSOC);

        // Index by [mon][type]
        $map = [];
        foreach ($rows as $r) { $map[$r['mon']][$r['type']] = (float)$r['rev']; }

        $labels   = [];
        $setup    = [];
        $topup    = [];
        $adj      = [];

        foreach ($months as $mon) {
            $dt = \DateTime::createFromFormat('Y-m', $mon);
            $labels[] = $dt->format('M y');
            $setup[]  = (float)($map[$mon]['setup_fee']  ?? 0);
            $topup[]  = (float)($map[$mon]['coin_topup'] ?? 0);
            $adj[]    = (float)($map[$mon]['adjustment'] ?? 0) + (float)($map[$mon]['custom'] ?? 0);
        }

        echo json_encode(compact('labels','setup','topup','adj'));
        exit;
    }

    // ── top_tenants: 5 terbesar (all time) ───────────────────────────────
    if ($action === 'top_tenants') {
        $rows = $db->query(
            "SELECT t.id, t.nama_outlet, t.owner_name,
                    COALESCE(SUM(p.nominal_dibayar),0) AS total_spent,
                    COUNT(*) AS txn_count,
                    t.coin_balance
             FROM saas_manual_payments p
             JOIN tenants t ON t.id = p.tenant_id
             WHERE p.status='confirmed'
             GROUP BY t.id
             ORDER BY total_spent DESC
             LIMIT 5"
        )->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($rows);
        exit;
    }

    // ── coin_metrics: total beredar + burn rate 30d ───────────────────────
    if ($action === 'coin_metrics') {
        $beredar = (int)$db->query(
            "SELECT COALESCE(SUM(coin_balance),0) FROM tenants WHERE status='active'"
        )->fetchColumn();

        $burned30 = (int)$db->query(
            "SELECT COALESCE(SUM(amount),0) FROM coin_ledger
             WHERE type='deduct' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        )->fetchColumn();

        $topup30 = (int)$db->query(
            "SELECT COALESCE(SUM(amount),0) FROM coin_ledger
             WHERE type='topup' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        )->fetchColumn();

        // Distinct tenants with coin activity last 30d
        $activeUsers30 = (int)$db->query(
            "SELECT COUNT(DISTINCT tenant_id) FROM coin_ledger
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        )->fetchColumn();

        echo json_encode(compact('beredar','burned30','topup30','activeUsers30'));
        exit;
    }

    // ── list: paginated payment history ───────────────────────────────────
    if ($action === 'list') {
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $month  = (int)($_GET['month'] ?? 0);
        $year   = (int)($_GET['year']  ?? date('Y'));
        $type   = $_GET['type']   ?? '';
        $status = $_GET['status'] ?? '';
        $search = trim($_GET['search'] ?? '');
        $limit  = 25;
        $offset = ($page - 1) * $limit;

        $where  = ['1=1'];
        $params = [];

        if ($month > 0) {
            $where[] = 'MONTH(p.tanggal_bayar)=? AND YEAR(p.tanggal_bayar)=?';
            array_push($params, $month, $year);
        }
        if ($type)   { $where[] = 'p.type=?';   $params[] = $type; }
        if ($status) { $where[] = 'p.status=?'; $params[] = $status; }
        if ($search) {
            $where[] = '(t.nama_outlet LIKE ? OR t.owner_name LIKE ? OR p.ref_transfer LIKE ?)';
            $like = "%$search%";
            array_push($params, $like, $like, $like);
        }

        $w = implode(' AND ', $where);

        $cnt = $db->prepare("SELECT COUNT(*) FROM saas_manual_payments p LEFT JOIN tenants t ON t.id=p.tenant_id WHERE $w");
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        $stm = $db->prepare(
            "SELECT p.*,
                    t.nama_outlet, t.owner_name,
                    pk.nama AS package_nama,
                    cb.nama AS bundle_nama,
                    sa.nama AS superadmin_nama
             FROM saas_manual_payments p
             LEFT JOIN tenants t  ON t.id  = p.tenant_id
             LEFT JOIN saas_packages pk ON pk.id = p.package_id
             LEFT JOIN saas_coin_bundles cb ON cb.id = p.bundle_id
             LEFT JOIN super_admins sa ON sa.id = p.superadmin_id
             WHERE $w
             ORDER BY p.created_at DESC
             LIMIT $limit OFFSET $offset"
        );
        $stm->execute($params);
        $rows = $stm->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'rows'  => $rows,
            'total' => $total,
            'pages' => max(1, (int)ceil($total / $limit)),
            'page'  => $page,
        ]);
        exit;
    }

    echo json_encode(['error' => 'Action tidak dikenal.']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php saRenderHead('Billing'); ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<style>
/* ── Pending alert ─────────────────────────────── */
#pendingAlert {
  display: none;
  background: linear-gradient(135deg, rgba(245,158,11,.15), rgba(245,158,11,.05));
  border: 1px solid rgba(245,158,11,.4);
  border-radius: var(--r);
  padding: 14px 18px;
  margin-bottom: 20px;
  display: flex; align-items: center; gap: 12px;
  color: #FCD34D;
  font-weight: 600;
}
#pendingAlert a { color: #FCD34D; text-decoration: underline; }
#pendingAlert.hidden { display: none; }

/* ── Chart card ────────────────────────────────── */
.chart-card {
  background: var(--navy-m);
  border: 1px solid rgba(255,255,255,.07);
  border-radius: var(--r);
  padding: 20px 24px;
}
.chart-card h3 { font-size: 14px; font-weight: 700; color: rgba(255,255,255,.7); margin-bottom: 16px; }

/* ── Two-col grid ──────────────────────────────── */
.billing-grid {
  display: grid;
  grid-template-columns: 1fr 340px;
  gap: 20px;
  margin-bottom: 24px;
}
@media(max-width:900px){ .billing-grid { grid-template-columns:1fr; } }

/* ── Top tenants ───────────────────────────────── */
.top-tenant-row {
  display: flex; align-items: center; justify-content: space-between;
  padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,.06);
  gap: 10px;
}
.top-tenant-row:last-child { border-bottom: none; }
.top-tenant-rank {
  font-family: var(--mono); font-size: 18px; font-weight: 700;
  color: rgba(255,255,255,.2); width: 24px; flex-shrink: 0;
}
.top-tenant-info { flex: 1; min-width: 0; }
.top-tenant-info .name { font-size: 13px; font-weight: 600; color: var(--white);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.top-tenant-info .sub  { font-size: 11px; color: rgba(255,255,255,.35); }
.top-tenant-amount { font-family: var(--mono); font-size: 13px; font-weight: 600; color: #6EE7B7; text-align: right; white-space: nowrap; }

/* ── Coin metrics ──────────────────────────────── */
.coin-metrics-grid {
  display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px;
  margin-bottom: 24px;
}
@media(max-width:700px){ .coin-metrics-grid { grid-template-columns: repeat(2,1fr); } }
.coin-metric-card {
  background: var(--navy-m);
  border: 1px solid rgba(255,255,255,.07);
  border-radius: var(--r);
  padding: 16px 18px;
  position: relative; overflow: hidden;
}
.coin-metric-card .label { font-size: 11px; font-weight: 600; text-transform: uppercase;
  letter-spacing: .06em; color: rgba(255,255,255,.4); margin-bottom: 6px; }
.coin-metric-card .value { font-family: var(--mono); font-size: 20px; font-weight: 700;
  color: var(--white); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.coin-metric-card .sub { font-size: 11px; color: rgba(255,255,255,.3); margin-top: 3px; }
.coin-metric-card .ico { position: absolute; right: 14px; top: 14px; font-size: 22px; opacity: .2; }

/* ── Filter bar ────────────────────────────────── */
.b-filter-bar {
  display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; align-items: center;
}
.b-filter-bar input[type=text],
.b-filter-bar select {
  background: rgba(255,255,255,.06);
  border: 1px solid rgba(255,255,255,.12);
  color: var(--white); border-radius: 7px;
  padding: 7px 12px; font-size: 13px; font-family: var(--font);
  outline: none; transition: border-color .15s;
}
.b-filter-bar input[type=text] { min-width: 200px; }
.b-filter-bar select:focus,
.b-filter-bar input[type=text]:focus { border-color: var(--sa); }
</style>
</head>
<body>
<div class="sa-layout">
<?php saRenderNav('billing', 'Billing & Revenue'); ?>

<div class="sa-page-header">
  <h1>Billing & Revenue</h1>
  <p>Ringkasan keuangan dan riwayat pembayaran seluruh platform</p>
</div>

<!-- Pending Alert -->
<div id="pendingAlert" class="hidden">
  ⚠️ <span id="pendingMsg">Ada pembayaran pending</span> —
  <a href="payments.php?status=pending">Tinjau sekarang →</a>
</div>

<!-- Revenue Stats Cards -->
<div class="sa-stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(190px,1fr));margin-bottom:24px;" id="statsGrid">
  <div class="sa-stat-card green">
    <div class="label">Setup Fee Bln Ini</div>
    <div class="value" id="s-sf" style="font-size:17px;">—</div>
    <span class="icon-bg">🏪</span>
  </div>
  <div class="sa-stat-card green">
    <div class="label">Coin Topup Bln Ini</div>
    <div class="value" id="s-ct" style="font-size:17px;">—</div>
    <span class="icon-bg">🪙</span>
  </div>
  <div class="sa-stat-card indigo">
    <div class="label">Total Bln Ini</div>
    <div class="value" id="s-tot" style="font-size:17px;">—</div>
    <div class="sub" id="s-cnt"></div>
    <span class="icon-bg">💰</span>
  </div>
  <div class="sa-stat-card blue">
    <div class="label">Total Bln Lalu</div>
    <div class="value" id="s-last" style="font-size:17px;">—</div>
    <span class="icon-bg">📅</span>
  </div>
  <div class="sa-stat-card yellow">
    <div class="label">YTD <?= date('Y') ?></div>
    <div class="value" id="s-ytd" style="font-size:17px;">—</div>
    <div class="sub" id="s-ytd-coin"></div>
    <span class="icon-bg">📊</span>
  </div>
</div>

<!-- Coin Metrics -->
<div class="coin-metrics-grid" id="coinMetricsGrid">
  <div class="coin-metric-card">
    <div class="ico">🪙</div>
    <div class="label">Total Coin Beredar</div>
    <div class="value" id="cm-beredar">—</div>
    <div class="sub">saldo aktif seluruh tenant</div>
  </div>
  <div class="coin-metric-card">
    <div class="ico">🔥</div>
    <div class="label">Burn Rate (30 Hari)</div>
    <div class="value" id="cm-burn">—</div>
    <div class="sub">coin terpakai 30 hari terakhir</div>
  </div>
  <div class="coin-metric-card">
    <div class="ico">⬆️</div>
    <div class="label">Topup (30 Hari)</div>
    <div class="value" id="cm-topup30">—</div>
    <div class="sub">coin masuk 30 hari terakhir</div>
  </div>
  <div class="coin-metric-card">
    <div class="ico">👥</div>
    <div class="label">Tenant Aktif (30 Hari)</div>
    <div class="value" id="cm-active">—</div>
    <div class="sub">tenant punya aktivitas coin</div>
  </div>
</div>

<!-- Chart + Top Tenants -->
<div class="billing-grid">
  <div class="chart-card">
    <h3>📈 Revenue 6 Bulan Terakhir</h3>
    <canvas id="revenueChart" height="200"></canvas>
  </div>
  <div class="chart-card">
    <h3>🏆 Top 5 Tenant (All Time)</h3>
    <div id="topTenantsList">
      <div style="text-align:center;padding:24px;color:rgba(255,255,255,.25);">Memuat…</div>
    </div>
  </div>
</div>

<!-- Payment History -->
<div class="sa-card">
  <div class="sa-card-header" style="display:flex;align-items:center;justify-content:space-between;">
    <h3>Riwayat Pembayaran</h3>
    <a href="payments.php" class="sa-btn sa-btn-sm sa-btn-primary">+ Konfirmasi Pembayaran</a>
  </div>

  <div class="b-filter-bar">
    <input type="text" id="bSearch" placeholder="🔍 Cari tenant / referensi…" oninput="debounceLoad()">
    <select id="bMonth" onchange="bPage=1;loadList()">
      <option value="">Semua Bulan</option>
      <?php for ($i = 1; $i <= 12; $i++): ?>
      <option value="<?= $i ?>" <?= $i == date('n') ? 'selected' : '' ?>><?= date('M', mktime(0,0,0,$i,1)) ?> <?= date('Y') ?></option>
      <?php endfor; ?>
    </select>
    <select id="bType" onchange="bPage=1;loadList()">
      <option value="">Semua Tipe</option>
      <option value="setup_fee">Setup Fee</option>
      <option value="coin_topup">Coin Topup</option>
      <option value="adjustment">Adjustment</option>
      <option value="custom">Custom</option>
    </select>
    <select id="bStatus" onchange="bPage=1;loadList()">
      <option value="confirmed" selected>Confirmed</option>
      <option value="">Semua Status</option>
      <option value="pending">Pending</option>
      <option value="cancelled">Cancelled</option>
    </select>
  </div>

  <div class="sa-table-wrap">
    <table class="sa-table">
      <thead>
        <tr>
          <th>Tanggal</th>
          <th>Tenant</th>
          <th>Tipe</th>
          <th>Paket / Bundle</th>
          <th>Nominal</th>
          <th>Coin</th>
          <th>Metode</th>
          <th>Ref / Pengirim</th>
          <th>Oleh</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody id="billingBody">
        <tr><td colspan="10" style="text-align:center;padding:32px;color:rgba(255,255,255,.3);">Memuat…</td></tr>
      </tbody>
    </table>
  </div>
  <div class="sa-pagination" id="billingPagination"></div>
</div>

<?php saRenderNavClose(); ?>

<script>
const rupiah = n => 'Rp ' + parseInt(n||0).toLocaleString('id-ID');
const coin   = n => parseInt(n||0).toLocaleString('id-ID') + ' 🪙';
const esc    = s => { const d = document.createElement('div'); d.textContent = s||''; return d.innerHTML; };
const fmtD   = s => s ? new Date(s).toLocaleDateString('id-ID', {day:'2-digit',month:'short',year:'numeric'}) : '-';

let bPage = 1, _debTimer = null;
function debounceLoad() { clearTimeout(_debTimer); _debTimer = setTimeout(()=>{ bPage=1; loadList(); }, 350); }

// ── Load Stats ─────────────────────────────────────────────────────────────
fetch('billing.php?action=stats', { headers:{'X-Requested-With':'XMLHttpRequest'} })
  .then(r=>r.json()).then(d => {
    document.getElementById('s-sf').textContent    = rupiah(d.bulan_ini.setup_fee);
    document.getElementById('s-ct').textContent    = rupiah(d.bulan_ini.coin_topup);
    document.getElementById('s-tot').textContent   = rupiah(d.bulan_ini.grand_total);
    document.getElementById('s-cnt').textContent   = (d.bulan_ini.txn_count||0) + ' transaksi';
    document.getElementById('s-last').textContent  = rupiah(d.bulan_lalu.grand_total);
    document.getElementById('s-ytd').textContent   = rupiah(d.ytd);
    document.getElementById('s-ytd-coin').textContent = parseInt(d.ytd_coin||0).toLocaleString('id-ID') + ' coin dikreditkan';

    if (d.pending_count > 0) {
      const el = document.getElementById('pendingAlert');
      el.classList.remove('hidden');
      el.style.display = 'flex';
      document.getElementById('pendingMsg').textContent =
        d.pending_count + ' pembayaran menunggu konfirmasi';
    }
  });

// ── Load Coin Metrics ──────────────────────────────────────────────────────
fetch('billing.php?action=coin_metrics', { headers:{'X-Requested-With':'XMLHttpRequest'} })
  .then(r=>r.json()).then(d => {
    document.getElementById('cm-beredar').textContent  = parseInt(d.beredar||0).toLocaleString('id-ID');
    document.getElementById('cm-burn').textContent     = parseInt(d.burned30||0).toLocaleString('id-ID');
    document.getElementById('cm-topup30').textContent  = parseInt(d.topup30||0).toLocaleString('id-ID');
    document.getElementById('cm-active').textContent   = (d.activeUsers30||0) + ' tenant';
  });

// ── Load Chart ─────────────────────────────────────────────────────────────
let revenueChart = null;
fetch('billing.php?action=chart', { headers:{'X-Requested-With':'XMLHttpRequest'} })
  .then(r=>r.json()).then(d => {
    const ctx = document.getElementById('revenueChart').getContext('2d');
    revenueChart = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: d.labels,
        datasets: [
          {
            label: 'Setup Fee',
            data: d.setup,
            backgroundColor: 'rgba(16,185,129,.75)',
            borderRadius: 4,
          },
          {
            label: 'Coin Topup',
            data: d.topup,
            backgroundColor: 'rgba(99,102,241,.75)',
            borderRadius: 4,
          },
          {
            label: 'Lain-lain',
            data: d.adj,
            backgroundColor: 'rgba(245,158,11,.6)',
            borderRadius: 4,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: {
            labels: { color: 'rgba(255,255,255,.55)', font: { size: 11 }, boxWidth: 12 },
          },
          tooltip: {
            callbacks: {
              label: ctx => ' ' + ctx.dataset.label + ': ' + rupiah(ctx.parsed.y),
            },
          },
        },
        scales: {
          x: {
            stacked: true,
            grid:  { color: 'rgba(255,255,255,.05)' },
            ticks: { color: 'rgba(255,255,255,.4)', font: { size: 11 } },
          },
          y: {
            stacked: true,
            grid:  { color: 'rgba(255,255,255,.05)' },
            ticks: {
              color: 'rgba(255,255,255,.4)', font: { size: 11 },
              callback: v => v >= 1000000 ? 'Rp' + (v/1000000).toFixed(1) + 'jt' : 'Rp' + (v/1000).toFixed(0) + 'k',
            },
          },
        },
      },
    });
  });

// ── Load Top Tenants ───────────────────────────────────────────────────────
fetch('billing.php?action=top_tenants', { headers:{'X-Requested-With':'XMLHttpRequest'} })
  .then(r=>r.json()).then(rows => {
    const el = document.getElementById('topTenantsList');
    if (!rows || !rows.length) {
      el.innerHTML = '<div style="text-align:center;padding:24px;color:rgba(255,255,255,.25);">Belum ada data.</div>';
      return;
    }
    const medals = ['🥇','🥈','🥉','4','5'];
    el.innerHTML = rows.map((r,i) => `
      <div class="top-tenant-row">
        <div class="top-tenant-rank">${medals[i]||i+1}</div>
        <div class="top-tenant-info">
          <div class="name"><a href="client_detail.php?id=${r.id}" style="color:var(--white);text-decoration:none;">${esc(r.nama_outlet||'-')}</a></div>
          <div class="sub">${esc(r.owner_name||'')} · ${r.txn_count} transaksi · ${parseInt(r.coin_balance||0).toLocaleString('id-ID')} 🪙</div>
        </div>
        <div class="top-tenant-amount">${rupiah(r.total_spent)}</div>
      </div>`).join('');
  });

// ── Load List ──────────────────────────────────────────────────────────────
const TYPE_LABEL  = { setup_fee:'Setup Fee', coin_topup:'Coin Topup', adjustment:'Adjustment', custom:'Custom' };
const TYPE_BADGE  = { setup_fee:'sa-badge-blue', coin_topup:'sa-badge-indigo', adjustment:'sa-badge-yellow', custom:'sa-badge-active' };
const STATUS_BADGE = { confirmed:'sa-badge-active', pending:'sa-badge-yellow', cancelled:'sa-badge-red' };

function loadList() {
  const params = new URLSearchParams({
    action: 'list',
    month:  document.getElementById('bMonth').value,
    year:   new Date().getFullYear(),
    type:   document.getElementById('bType').value,
    status: document.getElementById('bStatus').value,
    search: document.getElementById('bSearch').value,
    page:   bPage,
  });

  document.getElementById('billingBody').innerHTML =
    '<tr><td colspan="10" style="text-align:center;padding:24px;color:rgba(255,255,255,.25);">Memuat…</td></tr>';

  fetch('billing.php?' + params, { headers:{'X-Requested-With':'XMLHttpRequest'} })
    .then(r => r.json()).then(data => {
      renderList(data.rows);
      renderPagination(data.page, data.pages, data.total);
    });
}

function renderList(rows) {
  const tbody = document.getElementById('billingBody');
  if (!rows || !rows.length) {
    tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:24px;color:rgba(255,255,255,.25);">Tidak ada data.</td></tr>';
    return;
  }
  tbody.innerHTML = rows.map(r => {
    const paketNama = r.package_nama || r.bundle_nama || '—';
    const metodeFmt = (r.metode || '').replace(/_/g,' ');
    const refInfo   = [r.ref_transfer, r.nama_pengirim].filter(Boolean).join(' / ') || '—';
    return `<tr>
      <td style="font-size:12px;">${fmtD(r.tanggal_bayar)}</td>
      <td>
        <a href="client_detail.php?id=${r.tenant_id}" style="color:var(--white);text-decoration:none;font-weight:600;">${esc(r.nama_outlet||'-')}</a>
        <br><small style="color:rgba(255,255,255,.3);font-size:11px;">${esc(r.owner_name||'')}</small>
      </td>
      <td><span class="sa-badge ${TYPE_BADGE[r.type]||'sa-badge-indigo'}" style="font-size:10.5px;">${TYPE_LABEL[r.type]||r.type}</span></td>
      <td style="font-size:12px;color:rgba(255,255,255,.55);">${esc(paketNama)}</td>
      <td style="font-family:var(--mono);color:#6EE7B7;">${rupiah(r.nominal_dibayar)}</td>
      <td style="font-family:var(--mono);">${r.coin_dikreditkan > 0 ? '+' + parseInt(r.coin_dikreditkan).toLocaleString('id-ID') : r.coin_dikreditkan < 0 ? parseInt(r.coin_dikreditkan).toLocaleString('id-ID') : '—'}</td>
      <td style="font-size:12px;color:rgba(255,255,255,.45);">${esc(metodeFmt||'—')}</td>
      <td style="font-size:11.5px;color:rgba(255,255,255,.35);font-family:var(--mono);">${esc(refInfo)}</td>
      <td style="font-size:11.5px;color:rgba(255,255,255,.35);">${esc(r.superadmin_nama||'—')}</td>
      <td><span class="sa-badge ${STATUS_BADGE[r.status]||'sa-badge-indigo'}">${esc(r.status)}</span></td>
    </tr>`;
  }).join('');
}

function renderPagination(page, pages, total) {
  const el = document.getElementById('billingPagination');
  if (pages <= 1 && total === 0) { el.innerHTML = ''; return; }
  let html = `<span style="font-size:12px;color:rgba(255,255,255,.3);margin-right:10px;">Total: ${total}</span>`;
  html += `<button class="sa-btn sa-btn-sm sa-btn-outline${page<=1?' disabled':''}" onclick="bGoto(${page-1})">‹ Prev</button>`;
  for (let i = Math.max(1,page-2); i <= Math.min(pages,page+2); i++) {
    html += `<button class="sa-btn sa-btn-sm ${i===page?'sa-btn-primary':'sa-btn-outline'}" onclick="bGoto(${i})">${i}</button>`;
  }
  html += `<button class="sa-btn sa-btn-sm sa-btn-outline${page>=pages?' disabled':''}" onclick="bGoto(${page+1})">Next ›</button>`;
  el.innerHTML = html;
}

function bGoto(p) { if (p < 1) return; bPage = p; loadList(); }

loadList();
</script>
</body>
</html>

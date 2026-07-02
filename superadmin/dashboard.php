<?php
// ══════════════════════════════════════════════════════
// superadmin/dashboard.php — Platform Overview
// ══════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';
require_once SA_ROOT . '/../core/AIRateLimiter.php';

// Ambil tenant yang hit rate limit >=3x hari ini
$_aiAbusers = AIRateLimiter::getAbusersToday(3);

date_default_timezone_set('Asia/Jakarta');

$action = $_GET['action'] ?? '';

// ── API ACTIONS ───────────────────────────────────────
if ($action) {
    header('Content-Type: application/json');
    $db = Database::get();

    try {

    if ($action === 'stats') {
        $month = date('n');
        $year  = date('Y');

        // Trial dihitung dari OUTLET, bukan tenant
        $totals = $db->query(
            "SELECT COUNT(*) as total,
               SUM(status='active') as aktif,
               (SELECT COUNT(DISTINCT tenant_id) FROM outlets WHERE status='trial') as trial,
               SUM(status='suspended') as suspended
             FROM tenants"
        )->fetch();

        // payments table mungkin belum ada — tangkap gracefully
        try {
            $rev = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='success' AND MONTH(paid_at)=? AND YEAR(paid_at)=?");
            $rev->execute([$month, $year]);
            $revenueTotal = (float)$rev->fetchColumn();

            $cs = $db->prepare("SELECT COALESCE(SUM(coin_amount),0) FROM payments WHERE type='coin_topup' AND MONTH(paid_at)=? AND YEAR(paid_at)=? AND status='success'");
            $cs->execute([$month, $year]);
            $coinSoldTotal = (int)$cs->fetchColumn();
        } catch (Throwable) {
            $revenueTotal  = 0;
            $coinSoldTotal = 0;
        }

        $newTenants = (int)$db->query(
            "SELECT COUNT(*) FROM tenants WHERE provisioned_at >= NOW() - INTERVAL 30 DAY"
        )->fetchColumn();

        // churnRisk: tenant active + outlet trial mau habis ATAU coin tipis
        try {
            $churnRisk = (int)$db->query(
                "SELECT COUNT(DISTINCT t.id) FROM tenants t
                 LEFT JOIN outlets o ON o.tenant_id = t.id
                 WHERE t.status = 'active'
                 AND (
                   t.coin_balance < 5000
                   OR (o.status = 'trial' AND o.trial_ends_at < DATE_ADD(NOW(), INTERVAL 3 DAY))
                 )"
            )->fetchColumn();
        } catch (Throwable) {
            $churnRisk = 0;
        }

        $coinKritis = (int)$db->query(
            "SELECT COUNT(*) FROM tenants WHERE coin_balance < 5000 AND status='active'"
        )->fetchColumn();

        echo json_encode([
            'total'       => (int)($totals['total'] ?? 0),
            'aktif'       => (int)($totals['aktif'] ?? 0),
            'trial'       => (int)($totals['trial'] ?? 0),
            'suspended'   => (int)($totals['suspended'] ?? 0),
            'revenue'     => $revenueTotal,
            'coin_sold'   => $coinSoldTotal,
            'new_tenants' => $newTenants,
            'churn_risk'  => $churnRisk,
            'coin_kritis' => $coinKritis,
        ]);
        exit;
    }

    if ($action === 'alerts') {
        $coinAlert = $db->query(
            "SELECT id, nama_perusahaan AS nama_outlet, owner_name, owner_wa, coin_balance
             FROM tenants WHERE coin_balance < 10000 AND status='active'
             ORDER BY coin_balance ASC LIMIT 10"
        )->fetchAll();

        // Trial alert: outlet (bukan tenant) yang trial-nya hampir habis
        $trialAlert = $db->query(
            "SELECT t.id, t.nama_perusahaan AS nama_outlet, t.owner_name, t.owner_wa,
                    o.trial_ends_at,
                    DATEDIFF(o.trial_ends_at, NOW()) as days_left
             FROM tenants t
             JOIN outlets o ON o.tenant_id = t.id
             WHERE o.status='trial'
               AND o.trial_ends_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)
             ORDER BY o.trial_ends_at ASC LIMIT 10"
        )->fetchAll();

        // JOIN hl_users — aman walau tenant_id belum ada (LEFT JOIN)
        try {
            $inactiveAlert = $db->query(
                "SELECT t.id, t.nama_perusahaan AS nama_outlet, t.owner_name, t.owner_wa,
                        MAX(u.last_login) as last_login,
                        DATEDIFF(NOW(), MAX(u.last_login)) as days_inactive
                 FROM tenants t
                 LEFT JOIN hl_users u ON u.tenant_id = t.id
                 WHERE t.status = 'active'
                 GROUP BY t.id
                 HAVING last_login < NOW() - INTERVAL 14 DAY OR last_login IS NULL
                 ORDER BY last_login ASC LIMIT 10"
            )->fetchAll();
        } catch (Throwable) {
            $inactiveAlert = [];
        }

        echo json_encode([
            'coin_kritis' => $coinAlert,
            'trial_habis' => $trialAlert,
            'tidak_login' => $inactiveAlert,
        ]);
        exit;
    }

    if ($action === 'chart_tenants') {
        $rows = $db->query(
            "SELECT DATE_FORMAT(provisioned_at,'%b %Y') as label,
                    YEAR(provisioned_at) as yr, MONTH(provisioned_at) as mo,
                    COUNT(*) as total
             FROM tenants
             WHERE provisioned_at >= NOW() - INTERVAL 6 MONTH
             GROUP BY yr, mo, label
             ORDER BY yr ASC, mo ASC"
        )->fetchAll();
        echo json_encode($rows);
        exit;
    }

    if ($action === 'chart_coins') {
        try {
            $rows = $db->query(
                "SELECT DATE_FORMAT(paid_at,'%b %Y') as label,
                        YEAR(paid_at) as yr, MONTH(paid_at) as mo,
                        COALESCE(SUM(coin_amount),0) as total
                 FROM payments
                 WHERE type='coin_topup' AND status='success'
                   AND paid_at >= NOW() - INTERVAL 6 MONTH
                 GROUP BY yr, mo, label
                 ORDER BY yr ASC, mo ASC"
            )->fetchAll();
        } catch (Throwable) {
            $rows = [];
        }
        echo json_encode($rows);
        exit;
    }

    if ($action === 'support_widget') {
        $tkSt = $db->query(
            "SELECT
               SUM(status='open')         AS open_count,
               SUM(status='in_progress')  AS in_progress,
               SUM(status='waiting_tenant') AS waiting,
               SUM(status IN ('resolved','closed') AND DATE(COALESCE(resolved_at, updated_at))=CURDATE()) AS resolved_today,
               ROUND(AVG(CASE WHEN first_response_at IS NOT NULL
                         THEN TIMESTAMPDIFF(MINUTE, created_at, first_response_at) END), 0) AS avg_resp_min,
               ROUND(AVG(CASE WHEN rating IS NOT NULL THEN rating END), 1) AS avg_rating,
               SUM(CASE WHEN status IN ('open','in_progress')
                        AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS sla_breached
             FROM support_tickets"
        )->fetch(PDO::FETCH_ASSOC);

        $slaItems = $db->query(
            "SELECT t.id, t.subject, t.status, t.priority, tn.nama_perusahaan AS nama_outlet, t.created_at,
                    TIMESTAMPDIFF(HOUR, t.created_at, NOW()) AS age_hours
             FROM support_tickets t
             JOIN tenants tn ON tn.id = t.tenant_id
             WHERE t.status IN ('open','in_progress')
               AND t.created_at < DATE_SUB(NOW(), INTERVAL 6 HOUR)
             ORDER BY t.created_at ASC LIMIT 5"
        )->fetchAll(PDO::FETCH_ASSOC);

        $annSt = $db->query(
            "SELECT
               SUM(status='published' AND (expires_at IS NULL OR expires_at > NOW())) AS published,
               SUM(status='draft') AS draft,
               COUNT(*) AS total
             FROM saas_announcements"
        )->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'tickets'       => $tkSt,
            'sla_items'     => $slaItems,
            'announcements' => $annSt,
        ]);
        exit;
    }

    echo json_encode(['error' => 'Action tidak dikenal.']);
    exit;

    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php saRenderHead('Dashboard'); ?>
</head>
<body>
<div class="sa-layout">
<?php saRenderNav('dashboard', 'Dashboard'); ?>

<div class="sa-page-header">
  <h1>Platform Dashboard</h1>
  <p>Overview seluruh tenant Harpy Laundry ERP</p>
</div>

<!-- Stats Grid -->
<div class="sa-stats-grid" id="statsGrid">
  <div class="sa-stat-card indigo"><div class="label">Total Tenant</div><div class="value" id="s-total">—</div><span class="icon-bg">🏪</span></div>
  <div class="sa-stat-card green"><div class="label">Aktif</div><div class="value" id="s-aktif">—</div><span class="icon-bg">✅</span></div>
  <div class="sa-stat-card blue"><div class="label">Trial</div><div class="value" id="s-trial">—</div><span class="icon-bg">🔬</span></div>
  <div class="sa-stat-card red"><div class="label">Suspended</div><div class="value" id="s-suspended">—</div><span class="icon-bg">🔒</span></div>
  <div class="sa-stat-card green"><div class="label">Revenue Bulan Ini</div><div class="value" id="s-revenue" style="font-size:18px">—</div><span class="icon-bg">💰</span></div>
  <div class="sa-stat-card indigo"><div class="label">Coin Terjual Bulan Ini</div><div class="value" id="s-coin" style="font-size:18px">—</div><span class="icon-bg">🪙</span></div>
  <div class="sa-stat-card blue"><div class="label">Tenant Baru 30 Hari</div><div class="value" id="s-new">—</div><span class="icon-bg">🆕</span></div>
  <div class="sa-stat-card yellow"><div class="label">Churn Risk</div><div class="value" id="s-churn">—</div><div class="sub">perlu follow up</div><span class="icon-bg">⚠️</span></div>
  <div class="sa-stat-card red"><div class="label">Coin Kritis</div><div class="value" id="s-kritis">—</div><div class="sub">&lt; 5.000 coin</div><span class="icon-bg">🔴</span></div>
</div>

<!-- Charts Row -->
<div class="sa-grid-2" style="margin-bottom:24px;">
  <div class="sa-card">
    <div class="sa-card-header"><h3>📈 Tenant Baru per Bulan</h3></div>
    <div class="sa-card-body"><canvas id="chartTenants" height="180"></canvas></div>
  </div>
  <div class="sa-card">
    <div class="sa-card-header"><h3>🪙 Coin Terjual per Bulan</h3></div>
    <div class="sa-card-body"><canvas id="chartCoins" height="180"></canvas></div>
  </div>
</div>

<!-- Alerts -->
<div class="sa-card">
  <div class="sa-card-header">
    <h3>🚨 Alert Aktif</h3>
    <div class="sa-tabs" style="margin-bottom:0;border:none;">
      <button class="sa-tab active" onclick="switchAlertTab('coin')">Coin Kritis</button>
      <button class="sa-tab" onclick="switchAlertTab('trial')">Trial Habis</button>
      <button class="sa-tab" onclick="switchAlertTab('inactive')">Tidak Aktif</button>
    </div>
  </div>

  <div class="sa-card-body">
    <div id="alertCoin">
      <div style="color:var(--ash);font-size:13px;">Memuat...</div>
    </div>
    <div id="alertTrial" style="display:none">
      <div style="color:var(--ash);font-size:13px;">Memuat...</div>
    </div>
    <div id="alertInactive" style="display:none">
      <div style="color:var(--ash);font-size:13px;">Memuat...</div>
    </div>
  </div>
</div>

<!-- Support & Announcement Widget Row -->
<div class="sa-grid-2" style="margin-top:24px;">

  <!-- Support Overview -->
  <div class="sa-card">
    <div class="sa-card-header">
      <h3>🎧 Support Overview</h3>
      <a href="/superadmin/support.php" class="sa-btn sa-btn-sm sa-btn-outline">Kelola Tiket →</a>
    </div>
    <div class="sa-card-body">
      <div class="sa-mini-grid" style="grid-template-columns:repeat(auto-fit,minmax(120px,1fr));">
        <div class="sa-mini-stat red">
          <div class="val" id="sw-open">—</div>
          <div class="lbl">Open</div>
        </div>
        <div class="sa-mini-stat indigo">
          <div class="val" id="sw-inprogress">—</div>
          <div class="lbl">In Progress</div>
        </div>
        <div class="sa-mini-stat green">
          <div class="val" id="sw-resolved-today">—</div>
          <div class="lbl">Resolved Hari Ini</div>
        </div>
        <div class="sa-mini-stat yellow">
          <div class="val" id="sw-rating">—</div>
          <div class="lbl">Avg Rating</div>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:14px;padding:10px 0;border-top:1px solid var(--crease-soft);flex-wrap:wrap;">
        <div>
          <span style="font-size:11px;color:var(--text-muted);">Avg First Response</span>
          <span id="sw-avgResp" style="margin-left:8px;font-family:var(--mono);color:var(--indigo);font-size:13px;">—</span>
        </div>
        <span class="sa-badge sa-badge-red" id="sw-sla-badge" style="display:none;"></span>
      </div>
      <div id="sw-sla-list"></div>
    </div>
  </div>

  <!-- Announcement Overview -->
  <div class="sa-card">
    <div class="sa-card-header">
      <h3>📢 Announcements</h3>
      <a href="/superadmin/announcements.php" class="sa-btn sa-btn-sm sa-btn-outline">Kelola →</a>
    </div>
    <div class="sa-card-body">
      <div class="sa-grid-2" style="margin-bottom:18px;gap:12px;">
        <div style="text-align:center;padding:18px 12px;background:rgba(53,232,213,.08);border-radius:12px;border:1px solid rgba(53,232,213,.30);">
          <div id="aw-published" class="sa-mini-stat indigo" style="margin:0;display:block;">
            <div class="val" style="font-size:36px;">—</div>
            <div class="lbl" style="margin-top:5px;">Published Aktif</div>
          </div>
        </div>
        <div style="text-align:center;padding:18px 12px;background:rgba(10,15,31,.4);border-radius:12px;border:1px solid var(--crease-soft);">
          <div id="aw-draft" class="sa-mini-stat" style="margin:0;display:block;">
            <div class="val" id="aw-draft-val" style="font-size:36px;color:var(--ash);">—</div>
            <div class="lbl" style="margin-top:5px;">Draft</div>
          </div>
        </div>
      </div>
      <a href="/superadmin/announcements.php" class="sa-btn sa-btn-primary" style="width:100%;justify-content:center;">
        ＋ Buat Announcement Baru
      </a>
    </div>
  </div>

</div>

<!-- Platform Health Widget -->
<div class="sa-card" style="margin-top:24px;">
  <div class="sa-card-header">
    <h3>🩺 Platform Health — Hari Ini</h3>
    <a href="/superadmin/health.php" class="sa-btn sa-btn-sm sa-btn-outline">Detail →</a>
  </div>
  <div class="sa-card-body">
    <div class="sa-mini-grid" style="grid-template-columns:repeat(auto-fill,minmax(130px,1fr));" id="healthWidget">
      <div class="sa-mini-stat"><div class="val" id="hw-login">—</div><div class="lbl">Login Hari Ini</div></div>
      <div class="sa-mini-stat"><div class="val" id="hw-tx">—</div><div class="lbl">Transaksi</div></div>
      <div class="sa-mini-stat"><div class="val" id="hw-wa">—</div><div class="lbl">WA Terkirim</div></div>
      <div class="sa-mini-stat"><div class="val" id="hw-wa-rate">—</div><div class="lbl">WA Rate</div></div>
      <div class="sa-mini-stat"><div class="val" id="hw-ai">—</div><div class="lbl">AI Calls</div></div>
      <div class="sa-mini-stat yellow"><div class="val" id="hw-coin">—</div><div class="lbl">Coin Dipakai</div></div>
      <div class="sa-mini-stat green"><div class="val" id="hw-rev">—</div><div class="lbl">Revenue</div></div>
      <div class="sa-mini-stat"><div class="val" id="hw-err">—</div><div class="lbl">Errors</div></div>
    </div>
    <div id="hw-wa-alert" class="sa-alert-banner danger" style="display:none;margin-top:14px;">
      ⚠️ WA delivery rate di bawah 95%! <a href="/superadmin/health.php">Cek di Health dashboard →</a>
    </div>
    <div id="hw-err-alert" class="sa-alert-banner warn" style="display:none;margin-top:8px;">
      ⚠️ Ada error baru hari ini. <a href="/superadmin/health.php#errors">Lihat error log →</a>
    </div>

    <?php if (!empty($_aiAbusers)): ?>
    <div class="sa-ai-glow" style="margin-top:14px;background:rgba(167,139,250,.06);border:1px solid rgba(167,139,250,.32);border-radius:10px;padding:14px 16px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:10px;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:10px">
          <span class="sa-ai-pill">AI Rate Limit</span>
          <span style="font-size:13px;font-weight:700;color:var(--ai-violet)"><?= count($_aiAbusers) ?> tenant hit rate limit hari ini</span>
        </div>
        <a href="/superadmin/health.php" style="color:var(--ai-violet);font-size:11px;font-weight:700;text-decoration:none">Cek detail →</a>
      </div>
      <table style="width:100%;font-size:12px;color:var(--ink-soft)">
        <thead><tr style="text-align:left;color:var(--ash);font-size:10px;text-transform:uppercase">
          <th style="padding:4px 0">Tenant ID</th>
          <th>Fitur</th>
          <th style="text-align:right">Attempts Blocked</th>
        </tr></thead>
        <tbody>
        <?php foreach ($_aiAbusers as $a): ?>
          <tr><td style="padding:3px 0;font-family:'DM Mono',monospace">#<?= (int)$a['tenant_id'] ?></td>
              <td style="font-family:'DM Mono',monospace;font-size:11px"><?= htmlspecialchars($a['feature']) ?></td>
              <td style="text-align:right;font-family:'DM Mono',monospace;font-weight:700;color:var(--amber)"><?= (int)$a['attempts'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php saRenderNavClose(); ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const rupiah = n => 'Rp ' + parseInt(n).toLocaleString('id-ID');
const saFmtCoin = n => parseInt(n).toLocaleString('id-ID');

// Load stats
fetch('dashboard.php?action=stats', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
  .then(r => r.json()).then(d => {
    document.getElementById('s-total').textContent = d.total;
    document.getElementById('s-aktif').textContent = d.aktif;
    document.getElementById('s-trial').textContent = d.trial;
    document.getElementById('s-suspended').textContent = d.suspended;
    document.getElementById('s-revenue').textContent = rupiah(d.revenue);
    document.getElementById('s-coin').textContent = saFmtCoin(d.coin_sold);
    document.getElementById('s-new').textContent = d.new_tenants;
    document.getElementById('s-churn').textContent = d.churn_risk;
    document.getElementById('s-kritis').textContent = d.coin_kritis;
  });

// Charts
const chartDefaults = {
  plugins: { legend: { display: false } },
  scales: {
    x: { grid: { color: 'var(--crease-soft)' }, ticks: { color: 'rgba(26,31,46,.55)', font: { size: 11 } } },
    y: { grid: { color: 'var(--crease-soft)' }, ticks: { color: 'rgba(26,31,46,.55)', font: { size: 11 } }, beginAtZero: true }
  }
};

fetch('dashboard.php?action=chart_tenants', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
  .then(r => r.json()).then(rows => {
    new Chart(document.getElementById('chartTenants'), {
      type: 'bar',
      data: {
        labels: rows.map(r => r.label),
        datasets: [{ data: rows.map(r => r.total),
          backgroundColor: 'rgba(99,102,241,.6)', borderColor: '#6366F1',
          borderWidth: 2, borderRadius: 6 }]
      },
      options: { ...chartDefaults, responsive: true }
    });
  });

fetch('dashboard.php?action=chart_coins', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
  .then(r => r.json()).then(rows => {
    new Chart(document.getElementById('chartCoins'), {
      type: 'line',
      data: {
        labels: rows.map(r => r.label),
        datasets: [{ data: rows.map(r => r.total),
          borderColor: '#6366F1', backgroundColor: '#EEF2FF',
          borderWidth: 2, fill: true, tension: .4,
          pointBackgroundColor: '#6366F1', pointRadius: 4 }]
      },
      options: { ...chartDefaults, responsive: true }
    });
  });

// Alerts
let alertsData = null;
fetch('dashboard.php?action=alerts', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
  .then(r => r.json()).then(d => {
    alertsData = d;
    renderAlertCoin(d.coin_kritis);
    renderAlertTrial(d.trial_habis);
    renderAlertInactive(d.tidak_login);
  });

function renderAlertCoin(list) {
  const el = document.getElementById('alertCoin');
  if (!list || !list.length) { el.innerHTML = '<p style="color:var(--ash-dim);font-size:13px;">Tidak ada alert coin kritis.</p>'; return; }
  el.innerHTML = list.map(t => `
    <div class="sa-alert-item">
      <span class="alert-icon">🔴</span>
      <div class="alert-text">
        <strong>${esc(t.nama_outlet)}</strong> — ${esc(t.owner_name)}
        <span class="coin-kritis" style="margin-left:8px;">${parseInt(t.coin_balance).toLocaleString('id-ID')} coin</span>
      </div>
      <div class="alert-action">
        <a href="client_detail.php?id=${t.id}" class="sa-btn sa-btn-sm sa-btn-outline">Detail</a>
        <a href="https://wa.me/${t.owner_wa}" target="_blank" class="sa-btn sa-btn-sm sa-btn-wa" style="margin-left:4px;">WA</a>
      </div>
    </div>`).join('');
}

function renderAlertTrial(list) {
  const el = document.getElementById('alertTrial');
  if (!list || !list.length) { el.innerHTML = '<p style="color:var(--ash-dim);font-size:13px;">Tidak ada trial akan habis.</p>'; return; }
  el.innerHTML = list.map(t => `
    <div class="sa-alert-item">
      <span class="alert-icon">⏰</span>
      <div class="alert-text">
        <strong>${esc(t.nama_outlet)}</strong> — ${esc(t.owner_name)}
        <span class="sa-badge sa-badge-yellow" style="margin-left:8px;">${t.days_left} hari lagi</span>
      </div>
      <div class="alert-action">
        <a href="client_detail.php?id=${t.id}" class="sa-btn sa-btn-sm sa-btn-outline">Detail</a>
        <a href="https://wa.me/${t.owner_wa}" target="_blank" class="sa-btn sa-btn-sm sa-btn-wa" style="margin-left:4px;">WA</a>
      </div>
    </div>`).join('');
}

function renderAlertInactive(list) {
  const el = document.getElementById('alertInactive');
  if (!list || !list.length) { el.innerHTML = '<p style="color:var(--ash-dim);font-size:13px;">Tidak ada tenant tidak aktif.</p>'; return; }
  el.innerHTML = list.map(t => `
    <div class="sa-alert-item">
      <span class="alert-icon">😴</span>
      <div class="alert-text">
        <strong>${esc(t.nama_outlet)}</strong> — ${esc(t.owner_name)}
        <span style="color:var(--ash);margin-left:8px;font-size:12px;">${t.days_inactive ? t.days_inactive+' hari tidak login' : 'Belum pernah login'}</span>
      </div>
      <div class="alert-action">
        <a href="client_detail.php?id=${t.id}" class="sa-btn sa-btn-sm sa-btn-outline">Detail</a>
        <a href="https://wa.me/${t.owner_wa}" target="_blank" class="sa-btn sa-btn-sm sa-btn-wa" style="margin-left:4px;">WA</a>
      </div>
    </div>`).join('');
}

function switchAlertTab(tab) {
  document.querySelectorAll('.sa-tabs .sa-tab').forEach((t,i) => {
    const tabs = ['coin','trial','inactive'];
    t.classList.toggle('active', tabs[i] === tab);
  });
  document.getElementById('alertCoin').style.display    = tab === 'coin'     ? '' : 'none';
  document.getElementById('alertTrial').style.display   = tab === 'trial'    ? '' : 'none';
  document.getElementById('alertInactive').style.display = tab === 'inactive' ? '' : 'none';
}

function esc(s) {
  const d = document.createElement('div'); d.textContent = s; return d.innerHTML;
}

// Support & Announcement widget
fetch('dashboard.php?action=support_widget', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
  .then(r => r.json()).then(d => {
    const t = d.tickets || {};
    document.getElementById('sw-open').textContent           = parseInt(t.open_count ?? 0).toLocaleString('id-ID');
    document.getElementById('sw-inprogress').textContent     = parseInt(t.in_progress ?? 0).toLocaleString('id-ID');
    document.getElementById('sw-resolved-today').textContent = parseInt(t.resolved_today ?? 0).toLocaleString('id-ID');
    document.getElementById('sw-rating').textContent         = t.avg_rating ? parseFloat(t.avg_rating).toFixed(1) + ' ⭐' : '—';

    const avgMin = parseInt(t.avg_resp_min ?? 0);
    document.getElementById('sw-avgResp').textContent = avgMin
      ? (avgMin < 60 ? avgMin + ' mnt' : (avgMin / 60).toFixed(1) + ' jam')
      : '—';

    const slaCount = parseInt(t.sla_breached ?? 0);
    if (slaCount > 0) {
      const badge = document.getElementById('sw-sla-badge');
      badge.textContent = '⚠️ ' + slaCount + ' SLA breach';
      badge.style.display = '';
    }

    // Announcements
    const ann = d.announcements || {};
    document.getElementById('aw-published').querySelector('.val').textContent = parseInt(ann.published ?? 0).toLocaleString('id-ID');
    document.getElementById('aw-draft-val').textContent = parseInt(ann.draft ?? 0).toLocaleString('id-ID');

    // SLA items list
    const slaList = document.getElementById('sw-sla-list');
    if (d.sla_items && d.sla_items.length) {
      slaList.innerHTML = `<div style="margin-top:12px;border-top:1px solid var(--crease-soft);padding-top:12px;">
        <div style="font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--ash-dim);margin-bottom:8px;">Tiket Overdue</div>
        ${d.sla_items.map(tk => {
          const col = tk.age_hours >= 24 ? '#F87171' : tk.age_hours >= 6 ? '#FBBF24' : '#FCD34D';
          return `<div style="display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--linen);">
            <div style="flex:1;min-width:0;">
              <div style="font-size:12.5px;color:var(--glow);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(tk.subject||'(tanpa subjek)')}</div>
              <div style="font-size:11px;color:var(--ash-dim);">${esc(tk.nama_outlet||'')}</div>
            </div>
            <span style="color:${col};font-family:var(--mono);font-size:12px;margin-left:10px;white-space:nowrap;">${tk.age_hours}j</span>
            <a href="/superadmin/support.php?ticket_id=${tk.id}" class="sa-btn sa-btn-sm sa-btn-outline" style="margin-left:8px;flex-shrink:0;">Buka</a>
          </div>`;
        }).join('')}
      </div>`;
    }
  }).catch(() => {});

// ── Platform Health Widget ──────────────────────────
async function loadHealthWidget() {
    try {
        const resp = await fetch('/superadmin/health.php?action=today');
        if (!resp.ok) return;
        const d = await resp.json();

        const fmt = n => (n === null || n === undefined) ? '—' : Number(n).toLocaleString('id-ID');

        document.getElementById('hw-login').textContent = fmt(d.login_today);
        document.getElementById('hw-tx').textContent    = fmt(d.total_tx);
        document.getElementById('hw-wa').textContent    = fmt(d.wa_sent);
        document.getElementById('hw-ai').textContent    = fmt(d.ai_calls);
        document.getElementById('hw-coin').textContent  = fmt(d.coin_burned);
        document.getElementById('hw-rev').textContent   = 'Rp ' + fmt(d.revenue);
        document.getElementById('hw-err').textContent   = fmt(d.error_count);

        const rate = d.wa_rate;
        const rateEl = document.getElementById('hw-wa-rate');
        if (rate === null) {
            rateEl.textContent = 'N/A';
        } else {
            rateEl.textContent = rate + '%';
            rateEl.style.color = rate < 90 ? '#F87171' : rate < 95 ? '#FBBF24' : '#6EE7B7';
        }

        // Anomaly alerts
        document.getElementById('hw-wa-alert').style.display = (rate !== null && rate < 95) ? '' : 'none';
        document.getElementById('hw-err-alert').style.display = (d.error_count > 0) ? '' : 'none';

    } catch(e) {
        // Gagal silent — widget tidak kritis
    }
}
loadHealthWidget();
</script>
</body>
</html>

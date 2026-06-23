<?php
// ══════════════════════════════════════════════════════
// superadmin/health.php — Platform Intelligence Dashboard
//
// Tab 1: Health Overview — live stats + 30-day trend + feature usage
// Tab 2: Error Log       — deduplicated errors + ack/resolve actions
// ══════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';
require_once SA_ROOT . '/../core/ErrorLogger.php';
require_once SA_ROOT . '/../core/AIRateLimiter.php';

// ── AI Usage Stats (untuk dashboard section) ──────────
function getAIUsageStats(string $date): array {
    $features = ['ai_briefing','ai_briefing_hq','ai_upselling','ai_analyst',
                 'ai_chat_data','ai_churn_message','ai_migration_mapping',
                 'ai_review','ai_insight_laporan'];
    $rows = [];
    $totalCalls = 0;
    $totalCoin  = 0;
    foreach ($features as $f) {
        $s = AIRateLimiter::getPlatformStats($f, $date);
        if ($s['total_calls'] > 0) {
            $rows[] = $s;
            $totalCalls += $s['total_calls'];
            $totalCoin  += $s['total_coin'];
        }
    }
    // Estimasi cost API Anthropic — asumsi ~$0.01/call (gross average)
    $estCostUSD = $totalCalls * 0.01;
    $estCostIDR = $estCostUSD * 16000;
    $coinIDR    = $totalCoin; // 1 coin = 1 IDR (asumsi pricing platform)
    $margin     = $coinIDR - $estCostIDR;
    $marginPct  = $coinIDR > 0 ? round($margin / $coinIDR * 100, 1) : 0;

    return [
        'rows'        => $rows,
        'total_calls' => $totalCalls,
        'total_coin'  => $totalCoin,
        'est_cost_usd'=> $estCostUSD,
        'est_cost_idr'=> $estCostIDR,
        'coin_idr'    => $coinIDR,
        'margin'      => $margin,
        'margin_pct'  => $marginPct,
    ];
}

date_default_timezone_set('Asia/Jakarta');

$db     = Database::get();
$action = $_GET['action'] ?? '';

// ── API ACTIONS ───────────────────────────────────────
if ($action) {
    header('Content-Type: application/json');

    // ── today: live stats dari DB langsung ──
    if ($action === 'today') {
        $today = date('Y-m-d');

        // Tenant aktif / trial
        $ts = $db->query("SELECT SUM(status='active') AS aktif, SUM(status='trial') AS trial FROM tenants")->fetch(PDO::FETCH_ASSOC);

        // Login hari ini
        try {
            $login = (int)$db->prepare("SELECT COUNT(DISTINCT tenant_id) FROM hl_audit_log WHERE DATE(created_at)=CURDATE()")->query()->fetchColumn();
        } catch (Throwable) {
            $stmt = $db->prepare("SELECT COUNT(DISTINCT tenant_id) FROM hl_audit_log WHERE DATE(created_at)=?");
            $stmt->execute([$today]);
            $login = (int)$stmt->fetchColumn();
        }
        $loginQ = $db->prepare("SELECT COUNT(DISTINCT tenant_id) FROM hl_audit_log WHERE DATE(created_at)=?");
        $loginQ->execute([$today]);
        $login = (int)$loginQ->fetchColumn();

        // Transaksi
        $txQ = $db->prepare("SELECT COUNT(*) FROM hl_transaksi WHERE DATE(created_at)=?");
        $txQ->execute([$today]);
        $totalTx = (int)$txQ->fetchColumn();

        // WA hari ini
        $waQ = $db->prepare("SELECT SUM(status='sent') AS sent, SUM(status='failed') AS failed FROM saas_wa_log WHERE DATE(created_at)=?");
        $waQ->execute([$today]);
        $wa = $waQ->fetch(PDO::FETCH_ASSOC) ?: ['sent'=>0,'failed'=>0];

        // AI calls + coin
        $aiQ = $db->prepare("SELECT COUNT(*) FROM hl_ai_cache WHERE DATE(created_at)=?");
        $aiQ->execute([$today]);
        $aiCalls = (int)$aiQ->fetchColumn();

        $coinQ = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM coin_ledger WHERE DATE(created_at)=? AND type='deduct'");
        $coinQ->execute([$today]);
        $coinBurned = (int)$coinQ->fetchColumn();

        // Revenue hari ini
        $revQ = $db->prepare("SELECT COALESCE(SUM(nominal_dibayar),0) FROM saas_manual_payments WHERE DATE(tanggal_bayar)=? AND status='confirmed'");
        $revQ->execute([$today]);
        $revenue = (int)$revQ->fetchColumn();

        // Errors hari ini
        $errQ = $db->prepare("SELECT COUNT(*) FROM saas_error_log WHERE DATE(tanggal)=?");
        $errQ->execute([$today]);
        $errCount = (int)$errQ->fetchColumn();

        // WA rate
        $waSent   = (int)($wa['sent']   ?? 0);
        $waFailed = (int)($wa['failed'] ?? 0);
        $waTotal  = $waSent + $waFailed;
        $waRate   = $waTotal > 0 ? round($waSent / $waTotal * 100, 1) : null;

        echo json_encode([
            'tenant_aktif'  => (int)($ts['aktif'] ?? 0),
            'tenant_trial'  => (int)($ts['trial'] ?? 0),
            'login_today'   => $login,
            'total_tx'      => $totalTx,
            'wa_sent'       => $waSent,
            'wa_failed'     => $waFailed,
            'wa_rate'       => $waRate,
            'ai_calls'      => $aiCalls,
            'coin_burned'   => $coinBurned,
            'revenue'       => $revenue,
            'error_count'   => $errCount,
            'updated_at'    => date('H:i:s'),
        ]);
        exit;
    }

    // ── trend: 30-day historical dari saas_platform_health ──
    if ($action === 'trend') {
        $rows = $db->query("
            SELECT tanggal,
                   total_tenant_aktif, total_tenant_trial,
                   tenant_login_hari_ini,
                   total_transaksi,
                   total_wa_terkirim, total_wa_gagal,
                   total_ai_calls, total_ai_cost_coin,
                   total_coin_terjual, total_coin_dipakai,
                   total_revenue_hari,
                   total_error_php, total_ai_error
            FROM saas_platform_health
            ORDER BY tanggal DESC
            LIMIT 30
        ")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(array_reverse($rows));
        exit;
    }

    // ── feature_usage: top features dari coin_ledger ──
    if ($action === 'feature_usage') {
        $days = max(1, min(90, (int)($_GET['days'] ?? 30)));
        $rows = $db->prepare("
            SELECT feature_used,
                   COUNT(*)        AS calls,
                   SUM(amount)     AS coins
            FROM coin_ledger
            WHERE type = 'deduct'
              AND created_at >= NOW() - INTERVAL ? DAY
              AND feature_used IS NOT NULL
            GROUP BY feature_used
            ORDER BY coins DESC
            LIMIT 15
        ");
        $rows->execute([$days]);
        echo json_encode($rows->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // ── coin_analytics: platform-wide coin health ──
    if ($action === 'coin_analytics') {
        // Burn rate: avg daily deduct (30d)
        $burnQ = $db->query("
            SELECT COALESCE(SUM(amount),0) / 30 AS daily_burn
            FROM coin_ledger
            WHERE type = 'deduct'
              AND created_at >= NOW() - INTERVAL 30 DAY
        ");
        $dailyBurn = (float)$burnQ->fetchColumn();

        // Total coin beredar (sum of all balances)
        $balQ = $db->query("SELECT COALESCE(SUM(coin_balance),0) FROM tenants")->fetchColumn();
        $totalBalance = (int)$balQ;

        // Days remaining at current burn rate
        $daysLeft = ($dailyBurn > 0 && $totalBalance > 0)
            ? round($totalBalance / $dailyBurn)
            : null;

        // Top 5 tenant by burn (30d)
        $topBurn = $db->query("
            SELECT t.nama_perusahaan, cl.tenant_id,
                   SUM(cl.amount) AS burned
            FROM coin_ledger cl
            JOIN tenants t ON t.id = cl.tenant_id
            WHERE cl.type = 'deduct'
              AND cl.created_at >= NOW() - INTERVAL 30 DAY
            GROUP BY cl.tenant_id
            ORDER BY burned DESC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Topup vs burn last 30d
        $ledgerQ = $db->query("
            SELECT type, COALESCE(SUM(amount),0) AS total
            FROM coin_ledger
            WHERE created_at >= NOW() - INTERVAL 30 DAY
            GROUP BY type
        ")->fetchAll(PDO::FETCH_ASSOC);
        $ledger = [];
        foreach ($ledgerQ as $r) $ledger[$r['type']] = (int)$r['total'];

        echo json_encode([
            'daily_burn'    => round($dailyBurn, 0),
            'total_balance' => $totalBalance,
            'days_left'     => $daysLeft,
            'topup_30d'     => $ledger['topup'] ?? 0,
            'burn_30d'      => $ledger['deduct'] ?? 0,
            'top_burners'   => $topBurn,
        ]);
        exit;
    }

    // ── errors: paginated error log ──
    if ($action === 'errors') {
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 20;
        $offset = ($page - 1) * $limit;
        $type   = $_GET['type']   ?? '';
        $status = $_GET['status'] ?? 'new';

        $where    = ['1=1'];
        $params   = [];
        $tenantId = (int)($_GET['tenant_id'] ?? 0);

        if ($tenantId) { $where[] = 'el.tenant_id = ?'; $params[] = $tenantId; }
        if ($type)     { $where[] = 'el.error_type = ?'; $params[] = $type;    }
        if ($status)   { $where[] = 'el.status = ?';     $params[] = $status;  }

        // Build flat WHERE for count (no joins, strip table prefix)
        $whereStr     = implode(' AND ', $where);
        $whereStrFlat = str_replace('el.', '', $whereStr);

        $countQ = $db->prepare("SELECT COUNT(*) FROM saas_error_log WHERE $whereStrFlat");
        $countQ->execute($params);
        $total = (int)$countQ->fetchColumn();

        $rowsQ = $db->prepare("
            SELECT el.*,
                   t.nama_perusahaan AS nama_outlet,
                   sa.name AS resolved_by_name
            FROM saas_error_log el
            LEFT JOIN tenants     t  ON t.id  = el.tenant_id
            LEFT JOIN super_admins sa ON sa.id = el.resolved_by
            WHERE $whereStr
            ORDER BY el.last_seen DESC
            LIMIT $limit OFFSET $offset
        ");
        $rowsQ->execute($params);
        $rows = $rowsQ->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'total' => $total,
            'pages' => (int)ceil($total / $limit),
            'page'  => $page,
            'rows'  => $rows,
        ]);
        exit;
    }

    // ── ack: acknowledge error ──
    if ($action === 'ack' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        saVerifyCsrf();
        $id = (int)($_POST['id'] ?? 0);
        ErrorLogger::acknowledge($id);
        logSuperAdminAction('error_ack', null, "Error #$id acknowledged");
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── resolve: resolve error ──
    if ($action === 'resolve' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        saVerifyCsrf();
        $id   = (int)($_POST['id'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        $admin = saCurrentAdmin();
        ErrorLogger::resolve($id, (int)($admin['id'] ?? 0), $note);
        logSuperAdminAction('error_resolve', null, "Error #$id resolved: $note");
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']); exit;
}

// ── PAGE RENDER ───────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php saRenderHead('Platform Health'); ?>
<style>
/* ── Health-specific styles ── */
.live-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 10px; border-radius: 20px;
    background: #ECFDF5; border: 1px solid #A7F3D0;
    font-size: 11px; font-weight: 600; color: #6EE7B7;
}
.live-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: #10B981;
    animation: livePulse 1.4s ease-in-out infinite;
}
@keyframes livePulse { 0%,100% { opacity:1; transform:scale(1); } 50% { opacity:.4; transform:scale(.7); } }

.stat-huge { font-size: 32px; font-family: var(--mono); font-weight: 800; color: var(--white); }
.stat-sub  { font-size: 11px; color: var(--ash-dim); margin-top: 3px; }

.wa-rate-bar {
    height: 6px; border-radius: 3px;
    background: var(--crease);
    margin-top: 8px; overflow: hidden;
}
.wa-rate-fill {
    height: 100%; border-radius: 3px;
    background: #10B981;
    transition: width .4s ease;
}
.wa-rate-fill.warn  { background: #F59E0B; }
.wa-rate-fill.crit  { background: #EF4444; }

.chart-wrap {
    position: relative; height: 200px;
    background: var(--linen);
    border-radius: 10px; overflow: hidden;
}
canvas { width: 100% !important; }

.feature-bar-row {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 0; border-bottom: 1px solid var(--crease-soft);
}
.feature-bar-row:last-child { border-bottom: none; }
.feature-name { width: 140px; font-size: 12px; color: var(--ink-soft); flex-shrink: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.feature-bar-bg { flex: 1; height: 8px; background: var(--crease-soft); border-radius: 4px; overflow: hidden; }
.feature-bar-fg { height: 100%; background: linear-gradient(90deg, var(--sa), #818CF8); border-radius: 4px; transition: width .5s; }
.feature-coins  { width: 64px; text-align: right; font-size: 12px; font-family: var(--mono); color: var(--ash); flex-shrink: 0; }

/* Error log */
.err-row-expand { display: none; }
.err-row-expand.open { display: table-row; }
.err-expand-cell {
    background: rgba(0,0,0,.2);
    border-bottom: 1px solid var(--crease-soft);
    padding: 12px 16px;
}
.err-trace {
    font-family: var(--mono); font-size: 11px;
    color: var(--ash); white-space: pre-wrap;
    max-height: 200px; overflow-y: auto;
    background: rgba(0,0,0,.25); padding: 10px; border-radius: 8px;
    margin-top: 8px;
}
.err-actions { display: flex; gap: 8px; margin-top: 10px; }

.note-input {
    width: 100%;
    padding: 8px 12px;
    background: var(--crease-soft); border: 1.5px solid var(--crease);
    border-radius: 8px; color: var(--white);
    font-family: var(--font); font-size: 13px; outline: none;
    transition: border-color .15s; margin-top: 8px;
}
.note-input:focus { border-color: var(--sa); }

.refresh-info {
    font-size: 11.5px; color: var(--ash-dim);
    display: flex; align-items: center; gap: 6px;
}
</style>
</head>
<body>
<?php saRenderNav('health', 'Platform Health'); ?>

<div class="sa-page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div>
    <h1>🩺 Platform Health</h1>
    <p>Monitoring real-time platform LaMaSy — auto-refresh setiap 60 detik</p>
  </div>
  <div style="display:flex;align-items:center;gap:12px;">
    <span class="live-badge"><span class="live-dot"></span> LIVE</span>
    <span class="refresh-info" id="refreshInfo">Updated —</span>
    <button class="sa-btn sa-btn-outline sa-btn-sm" onclick="refreshAll()">⟳ Refresh</button>
  </div>
</div>

<!-- ── TABS ─────────────────────────────────────────── -->
<div class="sa-tabs">
  <button class="sa-tab active" onclick="switchTab('health',this)">📊 Health Overview</button>
  <button class="sa-tab" onclick="switchTab('errors',this)">🚨 Error Log</button>
</div>

<!-- ════════════════════════════════════════════════════
     TAB 1 — HEALTH OVERVIEW
     ════════════════════════════════════════════════════ -->
<div class="sa-tab-panel active" id="tab-health">

  <!-- Live stat cards -->
  <div class="sa-stats-grid" id="liveStats" style="grid-template-columns: repeat(auto-fill, minmax(180px,1fr));">
    <?php foreach ([
      ['id'=>'statTenantAktif','label'=>'Tenant Aktif',    'color'=>'green',  'icon'=>'🏪','sub'=>'total aktif'],
      ['id'=>'statTenantTrial','label'=>'Tenant Trial',    'color'=>'blue',   'icon'=>'🆓','sub'=>'total trial'],
      ['id'=>'statLogin',      'label'=>'Login Hari Ini',  'color'=>'indigo', 'icon'=>'👤','sub'=>'unique tenant'],
      ['id'=>'statTx',         'label'=>'Transaksi',       'color'=>'',       'icon'=>'📋','sub'=>'orders hari ini'],
      ['id'=>'statWa',         'label'=>'WA Terkirim',     'color'=>'',       'icon'=>'💬','sub'=>'success hari ini'],
      ['id'=>'statAi',         'label'=>'AI Calls',        'color'=>'',       'icon'=>'🤖','sub'=>'hari ini'],
      ['id'=>'statCoin',       'label'=>'Coin Dipakai',    'color'=>'yellow', 'icon'=>'🪙','sub'=>'hari ini'],
      ['id'=>'statRevenue',    'label'=>'Revenue',         'color'=>'green',  'icon'=>'💰','sub'=>'hari ini (Rp)'],
      ['id'=>'statError',      'label'=>'Errors',          'color'=>'red',    'icon'=>'⚠️','sub'=>'error baru hari ini'],
    ] as $s): ?>
    <div class="sa-stat-card <?= $s['color'] ?>">
      <div class="label"><?= $s['label'] ?></div>
      <div class="stat-huge" id="<?= $s['id'] ?>">—</div>
      <div class="stat-sub"><?= $s['sub'] ?></div>
      <div class="icon-bg"><?= $s['icon'] ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- WA Rate banner -->
  <div class="sa-card" style="margin-bottom:16px;" id="waRateCard">
    <div class="sa-card-body" style="padding:14px 20px;">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <div>
          <div style="font-size:12px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--ash);margin-bottom:4px;">
            WA Delivery Rate (hari ini)
          </div>
          <div style="font-size:22px;font-weight:800;font-family:var(--mono);" id="waRateVal">—</div>
        </div>
        <div id="waRateAlert" style="display:none;" class="sa-badge sa-badge-red">⚠️ Rate di bawah 95%! Cek log WA.</div>
      </div>
      <div class="wa-rate-bar"><div class="wa-rate-fill" id="waRateFill" style="width:0%"></div></div>
    </div>
  </div>

  <!-- Charts row -->
  <div class="sa-grid-2" style="gap:20px;margin-bottom:24px;">
    <div class="sa-card">
      <div class="sa-card-header">
        <h3>📈 Transaksi 30 Hari</h3>
        <span style="font-size:11px;color:var(--ash-dim);" id="trendDays">memuat...</span>
      </div>
      <div class="sa-card-body">
        <div class="chart-wrap"><canvas id="chartTx"></canvas></div>
      </div>
    </div>
    <div class="sa-card">
      <div class="sa-card-header">
        <h3>🪙 Coin Dipakai 30 Hari</h3>
      </div>
      <div class="sa-card-body">
        <div class="chart-wrap"><canvas id="chartCoin"></canvas></div>
      </div>
    </div>
  </div>

  <!-- Feature usage + Coin analytics -->
  <div class="sa-grid-2" style="gap:20px;margin-bottom:24px;">

    <!-- Feature usage -->
    <div class="sa-card">
      <div class="sa-card-header">
        <h3>⚡ Feature Usage (30 hari)</h3>
        <select id="featureDays" class="sa-filter-bar" style="padding:4px 10px;font-size:12px;border-radius:8px;"
                onchange="loadFeatureUsage(this.value)">
          <option value="7">7 hari</option>
          <option value="30" selected>30 hari</option>
          <option value="90">90 hari</option>
        </select>
      </div>
      <div class="sa-card-body" id="featureUsageBody">
        <div style="color:var(--ash-dim);font-size:13px;">Memuat...</div>
      </div>
    </div>

    <!-- Coin analytics -->
    <div class="sa-card">
      <div class="sa-card-header"><h3>💎 Coin Analytics (30 hari)</h3></div>
      <div class="sa-card-body">

        <div class="sa-stats-grid" style="grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
          <div class="sa-stat-card yellow" style="padding:14px;">
            <div class="label">Burn Harian</div>
            <div style="font-size:20px;font-weight:800;font-family:var(--mono);" id="coinDailyBurn">—</div>
            <div class="stat-sub">rata-rata 30 hari</div>
          </div>
          <div class="sa-stat-card" style="padding:14px;">
            <div class="label">Total Saldo Platform</div>
            <div style="font-size:20px;font-weight:800;font-family:var(--mono);" id="coinTotalBalance">—</div>
            <div class="stat-sub">semua tenant</div>
          </div>
          <div class="sa-stat-card green" style="padding:14px;">
            <div class="label">Topup 30 Hari</div>
            <div style="font-size:20px;font-weight:800;font-family:var(--mono);" id="coinTopup30">—</div>
          </div>
          <div class="sa-stat-card red" style="padding:14px;">
            <div class="label">Burn 30 Hari</div>
            <div style="font-size:20px;font-weight:800;font-family:var(--mono);" id="coinBurn30">—</div>
          </div>
        </div>

        <div id="coinDaysAlert" style="display:none;" class="sa-card" style="border-color:rgba(245,158,11,.3);background:rgba(245,158,11,.05);margin-bottom:12px;">
          <div class="sa-card-body" style="padding:10px 14px;color:#92400E;font-size:13px;">
            ⚠️ Estimasi platform kehabisan coin dalam <strong id="coinDaysVal">?</strong> hari
          </div>
        </div>

        <div>
          <div style="font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--ash-dim);margin-bottom:10px;">
            Top 5 Tenant — Burn 30 Hari
          </div>
          <div id="topBurnersList">
            <div style="color:var(--ash-dim);font-size:13px;">Memuat...</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- 30-day revenue + login trend -->
  <div class="sa-card" style="margin-bottom:24px;">
    <div class="sa-card-header">
      <h3>💰 Revenue & Login 30 Hari</h3>
    </div>
    <div class="sa-card-body">
      <div class="chart-wrap" style="height:180px;"><canvas id="chartRevLogin"></canvas></div>
    </div>
  </div>

</div><!-- /#tab-health -->

<!-- ════════════════════════════════════════════════════
     TAB 2 — ERROR LOG
     ════════════════════════════════════════════════════ -->
<div class="sa-tab-panel" id="tab-errors">

  <div class="sa-card">
    <div class="sa-filter-bar">
      <select id="errType" onchange="loadErrors(1)">
        <option value="">Semua Tipe</option>
        <option value="php_error">php_error</option>
        <option value="wa_error">wa_error</option>
        <option value="ai_error">ai_error</option>
        <option value="db_error">db_error</option>
        <option value="system_error">system_error</option>
      </select>
      <select id="errStatus" onchange="loadErrors(1)">
        <option value="new">New</option>
        <option value="acknowledged">Acknowledged</option>
        <option value="resolved">Resolved</option>
        <option value="">Semua</option>
      </select>
      <button class="sa-btn sa-btn-outline sa-btn-sm" onclick="loadErrors(1)">⟳ Refresh</button>
    </div>

    <div class="sa-table-wrap">
      <table class="sa-table" id="errTable">
        <thead>
          <tr>
            <th style="width:36px;"></th>
            <th>Tipe</th>
            <th>Pesan</th>
            <th>Tenant</th>
            <th>Occurences</th>
            <th>Terakhir</th>
            <th>Status</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody id="errTbody">
          <tr><td colspan="8" style="text-align:center;color:var(--ash-dim);padding:32px;">Memuat...</td></tr>
        </tbody>
      </table>
    </div>

    <div id="errPagination" class="sa-pagination"></div>
  </div>

</div><!-- /#tab-errors -->

<!-- Resolve modal -->
<div class="sa-modal-overlay" id="resolveModal">
  <div class="sa-modal" style="max-width:400px;">
    <h3>✅ Resolve Error</h3>
    <input type="hidden" id="resolveId">
    <div class="form-group">
      <label>Catatan Resolusi (opsional)</label>
      <textarea class="note-input" id="resolveNote" rows="3" placeholder="Misal: Fixed di commit abc123, penyebab: race condition..."></textarea>
    </div>
    <div class="sa-modal-footer">
      <button class="sa-btn sa-btn-outline" onclick="closeModal('resolveModal')">Batal</button>
      <button class="sa-btn sa-btn-green" onclick="submitResolve()">✅ Mark Resolved</button>
    </div>
  </div>
</div>

<!-- ══════════════════════════ AI USAGE STATS ═══════════════════════════ -->
<?php
  $aiToday = getAIUsageStats(date('Y-m-d'));
?>
<div style="background:var(--linen);border:1px solid var(--crease);border-radius:14px;padding:24px;margin-top:24px">
  <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:16px;flex-wrap:wrap;gap:12px">
    <div>
      <h3 style="margin:0 0 4px;font-size:16px;font-weight:700;color:var(--ink)">🤖 AI Usage Stats — Hari Ini</h3>
      <p style="margin:0;font-size:12px;color:var(--ash)">Per fitur · semua tenant · <?= date('d M Y') ?></p>
    </div>
    <div style="display:flex;gap:16px;font-size:12px">
      <div><div style="color:var(--ash);font-size:10px;text-transform:uppercase">Total API Calls</div><div style="font-family:'DM Mono',monospace;font-weight:800;font-size:18px;color:#35E8D5"><?= number_format($aiToday['total_calls']) ?></div></div>
      <div><div style="color:var(--ash);font-size:10px;text-transform:uppercase">Est. API Cost</div><div style="font-family:'DM Mono',monospace;font-weight:800;font-size:18px;color:var(--amber)">Rp <?= number_format($aiToday['est_cost_idr'], 0, ',', '.') ?></div></div>
      <div><div style="color:var(--ash);font-size:10px;text-transform:uppercase">Coin Revenue</div><div style="font-family:'DM Mono',monospace;font-weight:800;font-size:18px;color:#10B981">Rp <?= number_format($aiToday['coin_idr'], 0, ',', '.') ?></div></div>
      <div><div style="color:var(--ash);font-size:10px;text-transform:uppercase">Margin AI</div><div style="font-family:'DM Mono',monospace;font-weight:800;font-size:18px;color:<?= $aiToday['margin'] >= 0 ? '#35E8D5' : '#E24B4A' ?>">Rp <?= number_format($aiToday['margin'], 0, ',', '.') ?> · <?= $aiToday['margin_pct'] ?>%</div></div>
    </div>
  </div>

  <?php if (empty($aiToday['rows'])): ?>
    <div style="text-align:center;padding:24px;color:var(--ash);font-size:13px">Belum ada AI usage hari ini.</div>
  <?php else: ?>
  <table class="sa-table" style="margin-top:8px">
    <thead>
      <tr>
        <th>Feature</th>
        <th style="text-align:right">Total Calls</th>
        <th style="text-align:right">Unique Tenant</th>
        <th style="text-align:right">Max / Tenant</th>
        <th style="text-align:right">Coin Revenue</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($aiToday['rows'] as $r):
      $heavy = $r['max_single_tenant'] >= 50; ?>
      <tr>
        <td style="font-family:'DM Mono',monospace;font-size:12px"><?= htmlspecialchars($r['feature']) ?></td>
        <td style="text-align:right;font-family:'DM Mono',monospace;font-weight:700"><?= number_format($r['total_calls']) ?></td>
        <td style="text-align:right;font-family:'DM Mono',monospace"><?= number_format($r['unique_tenants']) ?></td>
        <td style="text-align:right;font-family:'DM Mono',monospace<?= $heavy ? ';color:var(--amber);font-weight:700' : '' ?>"><?= number_format($r['max_single_tenant']) ?><?= $heavy ? ' ⚠' : '' ?></td>
        <td style="text-align:right;font-family:'DM Mono',monospace;color:#10B981">Rp <?= number_format($r['total_coin'], 0, ',', '.') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php saRenderNavClose(); ?>

<!-- ── Chart.js CDN (light, no color plugins needed) ── -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// ────────────────────────────────────────────────────
// Config
// ────────────────────────────────────────────────────
const CSRF = saCsrf();
let refreshTimer  = null;
let errPage       = 1;

// Charts
let chartTxInst, chartCoinInst, chartRevLoginInst;

// ────────────────────────────────────────────────────
// Tabs
// ────────────────────────────────────────────────────
function switchTab(name, btn) {
    document.querySelectorAll('.sa-tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.sa-tab').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    btn.classList.add('active');

    if (name === 'errors') loadErrors(1);
}

// ────────────────────────────────────────────────────
// Live stats
// ────────────────────────────────────────────────────
async function loadLiveStats() {
    try {
        const d = await apiFetch('?action=today');
        set('statTenantAktif', fmt(d.tenant_aktif));
        set('statTenantTrial', fmt(d.tenant_trial));
        set('statLogin',       fmt(d.login_today));
        set('statTx',          fmt(d.total_tx));
        set('statWa',          fmt(d.wa_sent));
        set('statAi',          fmt(d.ai_calls));
        set('statCoin',        fmt(d.coin_burned));
        set('statRevenue',     'Rp ' + fmtRp(d.revenue));
        set('statError',       fmt(d.error_count));

        // WA rate
        const rate = d.wa_rate;
        const rateEl = document.getElementById('waRateVal');
        const fillEl = document.getElementById('waRateFill');
        const alertEl = document.getElementById('waRateAlert');

        if (rate === null) {
            rateEl.textContent = 'N/A';
            fillEl.style.width = '0%';
        } else {
            rateEl.textContent = rate + '%';
            fillEl.style.width = Math.min(rate, 100) + '%';
            fillEl.className = 'wa-rate-fill' + (rate < 90 ? ' crit' : rate < 95 ? ' warn' : '');
            alertEl.style.display = rate < 95 ? '' : 'none';
        }

        document.getElementById('refreshInfo').textContent = 'Updated ' + d.updated_at;
    } catch(e) {
        console.error('Live stats error:', e);
    }
}

// ────────────────────────────────────────────────────
// Trend charts
// ────────────────────────────────────────────────────
const chartDefaults = {
    type: 'line',
    options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 400 },
        plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } },
        scales: {
            x: { grid: { color: 'var(--crease-soft)' }, ticks: { color: 'var(--ash-dim)', maxRotation: 45, font: { size: 10 } } },
            y: { grid: { color: 'var(--crease-soft)' }, ticks: { color: 'var(--ash-dim)', font: { size: 10 } }, beginAtZero: true },
        }
    }
};

function mkChart(id, labels, datasets) {
    const canvas = document.getElementById(id);
    const ctx = canvas.getContext('2d');
    return new Chart(ctx, {
        ...chartDefaults,
        data: { labels, datasets }
    });
}

function destroyAndCreate(inst, id, labels, datasets) {
    if (inst) inst.destroy();
    return mkChart(id, labels, datasets);
}

async function loadTrend() {
    try {
        const rows = await apiFetch('?action=trend');
        if (!rows.length) { document.getElementById('trendDays').textContent = '(belum ada data)'; return; }

        document.getElementById('trendDays').textContent = rows.length + ' hari';

        const labels = rows.map(r => r.tanggal.slice(5)); // MM-DD

        // Chart Tx
        chartTxInst = destroyAndCreate(chartTxInst, 'chartTx', labels, [{
            label: 'Transaksi',
            data: rows.map(r => +r.total_transaksi),
            borderColor: '#6366F1',
            backgroundColor: '#EEF2FF',
            fill: true, tension: .4, pointRadius: 2,
        }]);

        // Chart Coin
        chartCoinInst = destroyAndCreate(chartCoinInst, 'chartCoin', labels, [{
            label: 'Coin Dipakai',
            data: rows.map(r => +r.total_coin_dipakai),
            borderColor: '#F59E0B',
            backgroundColor: 'rgba(245,158,11,.1)',
            fill: true, tension: .4, pointRadius: 2,
        }]);

        // Chart Rev + Login (dual axis)
        chartRevLoginInst = destroyAndCreate(chartRevLoginInst, 'chartRevLogin', labels, [
            {
                label: 'Revenue (Rp)',
                data: rows.map(r => +r.total_revenue_hari),
                borderColor: '#10B981', backgroundColor: 'rgba(16,185,129,.1)',
                fill: false, tension: .4, pointRadius: 2, yAxisID: 'y',
            },
            {
                label: 'Tenant Login',
                data: rows.map(r => +r.tenant_login_hari_ini),
                borderColor: '#818CF8', backgroundColor: 'rgba(129,140,248,.1)',
                fill: false, tension: .4, pointRadius: 2, yAxisID: 'y1',
            },
        ]);
        // Dual axis config
        chartRevLoginInst.options.scales.y1 = {
            position: 'right',
            grid: { drawOnChartArea: false },
            ticks: { color: 'var(--ash-dim)', font: { size: 10 } },
            beginAtZero: true,
        };
        chartRevLoginInst.update();

    } catch(e) {
        console.error('Trend error:', e);
    }
}

// ────────────────────────────────────────────────────
// Feature usage
// ────────────────────────────────────────────────────
async function loadFeatureUsage(days) {
    const body = document.getElementById('featureUsageBody');
    body.innerHTML = '<div style="color:var(--ash-dim);font-size:13px;">Memuat...</div>';
    try {
        const rows = await apiFetch('?action=feature_usage&days=' + days);
        if (!rows.length) { body.innerHTML = '<div style="color:var(--ash-dim);font-size:13px;">Belum ada data.</div>'; return; }

        const maxCoins = Math.max(...rows.map(r => +r.coins));
        body.innerHTML = rows.map(r => `
            <div class="feature-bar-row">
              <div class="feature-name" title="${esc(r.feature_used)}">${esc(r.feature_used||'-')}</div>
              <div class="feature-bar-bg">
                <div class="feature-bar-fg" style="width:${maxCoins>0?(+r.coins/maxCoins*100).toFixed(1):0}%"></div>
              </div>
              <div class="feature-coins">${fmt(+r.coins)} 🪙</div>
            </div>
        `).join('');
    } catch(e) {
        body.innerHTML = '<div style="color:#991B1B;font-size:13px;">Gagal memuat.</div>';
    }
}

// ────────────────────────────────────────────────────
// Coin analytics
// ────────────────────────────────────────────────────
async function loadCoinAnalytics() {
    try {
        const d = await apiFetch('?action=coin_analytics');
        set('coinDailyBurn',    fmt(d.daily_burn));
        set('coinTotalBalance', fmt(d.total_balance));
        set('coinTopup30',      fmt(d.topup_30d));
        set('coinBurn30',       fmt(d.burn_30d));

        const alertEl = document.getElementById('coinDaysAlert');
        if (d.days_left !== null && d.days_left < 30) {
            document.getElementById('coinDaysVal').textContent = d.days_left;
            alertEl.style.display = '';
        } else {
            alertEl.style.display = 'none';
        }

        const listEl = document.getElementById('topBurnersList');
        if (!d.top_burners || !d.top_burners.length) {
            listEl.innerHTML = '<div style="color:var(--ash-dim);font-size:13px;">Belum ada data.</div>';
            return;
        }
        listEl.innerHTML = d.top_burners.map((r, i) => `
            <div style="display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid var(--linen);">
              <span style="width:20px;font-size:13px;color:var(--ash-dim);font-family:var(--mono);">${i+1}</span>
              <span style="flex:1;font-size:13px;color:var(--ink);">${esc(r.nama_perusahaan||r.nama_outlet||'Tenant #'+r.tenant_id)}</span>
              <span style="font-family:var(--mono);font-size:12px;color:#92400E;">${fmt(+r.burned)} 🪙</span>
            </div>
        `).join('');
    } catch(e) {
        console.error('Coin analytics error:', e);
    }
}

// ────────────────────────────────────────────────────
// Error log
// ────────────────────────────────────────────────────
const ERR_TYPE_COLORS = {
    php_error:    'sa-badge-red',
    wa_error:     'sa-badge-yellow',
    ai_error:     'sa-badge-indigo',
    db_error:     'sa-badge-red',
    system_error: 'sa-badge-yellow',
};

async function loadErrors(page) {
    errPage = page;
    const tbody = document.getElementById('errTbody');
    const type   = document.getElementById('errType').value;
    const status = document.getElementById('errStatus').value;

    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--ash-dim);padding:32px;">Memuat...</td></tr>';

    try {
        const d = await apiFetch(`?action=errors&page=${page}&type=${encodeURIComponent(type)}&status=${encodeURIComponent(status)}`);
        if (!d.rows.length) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--ash-dim);padding:32px;">Tidak ada error.</td></tr>';
            document.getElementById('errPagination').innerHTML = '';
            return;
        }

        tbody.innerHTML = d.rows.map(r => {
            const badge  = ERR_TYPE_COLORS[r.error_type] || 'sa-badge-yellow';
            const sta    = r.status === 'resolved' ? '✅ Resolved' : r.status === 'acknowledged' ? '👁 Acked' : '🔴 New';
            const rowId  = 'err-' + r.id;
            const expId  = 'exp-' + r.id;
            return `
            <tr class="sa-table-row" onclick="toggleErrExpand('${expId}')" style="cursor:pointer;">
              <td style="font-size:12px;color:var(--ash-dim);font-family:var(--mono);">#${r.id}</td>
              <td><span class="sa-badge ${badge}">${esc(r.error_type)}</span></td>
              <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                  title="${esc(r.error_message)}">${esc(r.error_message.substring(0,80))}${r.error_message.length>80?'…':''}</td>
              <td style="font-size:12px;color:var(--ash);">${esc(r.nama_outlet||'-')}</td>
              <td style="font-family:var(--mono);font-size:12px;text-align:center;">${r.occurrence_count}</td>
              <td style="font-size:11.5px;color:var(--ash);">${fmtDate(r.last_seen)}</td>
              <td style="font-size:12px;">${sta}</td>
              <td onclick="event.stopPropagation()">
                ${r.status === 'new' ? `<button class="sa-btn sa-btn-outline sa-btn-sm" onclick="ackError(${r.id})">👁 Ack</button>` : ''}
                ${r.status !== 'resolved' ? `<button class="sa-btn sa-btn-green sa-btn-sm" onclick="openResolve(${r.id})">✅</button>` : ''}
              </td>
            </tr>
            <tr class="err-row-expand" id="${expId}">
              <td colspan="8" class="err-expand-cell">
                <div style="font-size:12px;color:var(--ash);margin-bottom:6px;">
                  <strong style="color:var(--ink-soft);">URL:</strong> ${esc(r.url||'—')} &nbsp;|&nbsp;
                  <strong style="color:var(--ink-soft);">Kode:</strong> ${esc(r.error_code||'—')} &nbsp;|&nbsp;
                  <strong style="color:var(--ink-soft);">Pertama:</strong> ${fmtDate(r.first_seen)}
                  ${r.resolution_note ? `&nbsp;|&nbsp;<strong style="color:var(--sage);">Catatan:</strong> ${esc(r.resolution_note)}` : ''}
                </div>
                ${r.stack_trace ? `<div class="err-trace">${esc(r.stack_trace)}</div>` : ''}
              </td>
            </tr>`;
        }).join('');

        // Pagination
        renderPagination(d.page, d.pages, 'loadErrors');

    } catch(e) {
        tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;color:#991B1B;padding:32px;">Gagal memuat: ${esc(e.message)}</td></tr>`;
    }
}

function toggleErrExpand(id) {
    const el = document.getElementById(id);
    if (el) el.classList.toggle('open');
}

function renderPagination(page, pages, fn) {
    const el = document.getElementById('errPagination');
    if (pages <= 1) { el.innerHTML = ''; return; }
    let html = `<span style="font-size:12px;color:var(--ash-dim);">Hal ${page} / ${pages}</span>`;
    if (page > 1)     html += `<button class="sa-btn sa-btn-sm sa-btn-outline" onclick="${fn}(${page-1})">← Prev</button>`;
    if (page < pages) html += `<button class="sa-btn sa-btn-sm sa-btn-outline" onclick="${fn}(${page+1})">Next →</button>`;
    el.innerHTML = html;
}

async function ackError(id) {
    try {
        const fd = new FormData();
        fd.append('id', id);
        fd.append('_csrf', CSRF);
        const r = await fetch('?action=ack', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok) { saShowToast('Error #' + id + ' acknowledged', 'success'); loadErrors(errPage); }
        else saShowToast(d.error || 'Gagal', 'error');
    } catch(e) { saShowToast('Error: ' + e.message, 'error'); }
}

function openResolve(id) {
    document.getElementById('resolveId').value = id;
    document.getElementById('resolveNote').value = '';
    document.getElementById('resolveModal').classList.add('open');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}

async function submitResolve() {
    const id   = document.getElementById('resolveId').value;
    const note = document.getElementById('resolveNote').value;
    try {
        const fd = new FormData();
        fd.append('id', id);
        fd.append('note', note);
        fd.append('_csrf', CSRF);
        const r = await fetch('?action=resolve', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok) {
            saShowToast('Error #' + id + ' resolved', 'success');
            closeModal('resolveModal');
            loadErrors(errPage);
        } else saShowToast(d.error || 'Gagal', 'error');
    } catch(e) { saShowToast('Error: ' + e.message, 'error'); }
}

// ────────────────────────────────────────────────────
// Utilities
// ────────────────────────────────────────────────────
async function apiFetch(url) {
    const r = await fetch(url);
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return r.json();
}

function set(id, val) {
    const el = document.getElementById(id);
    if (el) el.textContent = val;
}

function fmt(n) {
    if (n === null || n === undefined) return '—';
    return Number(n).toLocaleString('id-ID');
}

function fmtRp(n) {
    if (!n) return '0';
    return Number(n).toLocaleString('id-ID');
}

function fmtDate(str) {
    if (!str) return '—';
    return str.replace('T',' ').substring(0,16);
}

function esc(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ────────────────────────────────────────────────────
// Full refresh
// ────────────────────────────────────────────────────
function refreshAll() {
    loadLiveStats();
    loadTrend();
    loadFeatureUsage(document.getElementById('featureDays').value || 30);
    loadCoinAnalytics();
}

// ────────────────────────────────────────────────────
// Init
// ────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    refreshAll();
    // Auto-refresh every 60s
    refreshTimer = setInterval(loadLiveStats, 60_000);
});
</script>
</body>
</html>

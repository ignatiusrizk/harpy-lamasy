<?php
// ══════════════════════════════════════════════════════════════════
// superadmin/ai_usage.php — Dashboard AI Usage & Margin Monitoring
// ══════════════════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';
require_once dirname(__DIR__) . '/core/AIBudget.php';

date_default_timezone_set('Asia/Jakarta');

$action = $_GET['action'] ?? '';

// Default range: 7 hari terakhir
$endDate   = $_GET['end']   ?? date('Y-m-d');
$startDate = $_GET['start'] ?? date('Y-m-d', strtotime('-6 days'));

// ─────────────────────────────────────────────────────────────────
// API LAYER
// ─────────────────────────────────────────────────────────────────
if ($action) {
    header('Content-Type: application/json');
    $db = Database::get();

    if ($action === 'overview') {
        try {
            $byTenant  = AIBudget::aggregateByTenant($startDate, $endDate, 100);
            $byFeature = AIBudget::aggregateByFeature($startDate, $endDate);
            $byDate    = AIBudget::aggregateByDate($startDate, $endDate);

            // Total
            $totals = [
                'calls'        => 0,
                'tokens_total' => 0,
                'cost_idr'     => 0,
                'coin_charged' => 0,
                'cache_hits'   => 0,
            ];
            foreach ($byTenant as $t) {
                $totals['calls']        += (int)$t['total_calls'];
                $totals['tokens_total'] += (int)$t['tokens_in'] + (int)$t['tokens_out'];
                $totals['cost_idr']     += (int)$t['cost_idr'];
                $totals['coin_charged'] += (int)$t['coin_charged'];
                $totals['cache_hits']   += (int)$t['cache_hits'];
            }

            // Coin → IDR rate (asumsi Popular Pack: Rp 4,17/coin)
            $coinToIdr = 4.17;
            $revenueIdr = (int)round($totals['coin_charged'] * $coinToIdr);
            $marginIdr  = $revenueIdr - $totals['cost_idr'];
            $marginPct  = $revenueIdr > 0 ? round(($marginIdr / $revenueIdr) * 100, 1) : 0;

            echo json_encode([
                'ok'          => true,
                'totals'      => $totals + [
                    'revenue_idr' => $revenueIdr,
                    'margin_idr'  => $marginIdr,
                    'margin_pct'  => $marginPct,
                ],
                'by_tenant'   => $byTenant,
                'by_feature'  => $byFeature,
                'by_date'     => $byDate,
                'coin_to_idr' => $coinToIdr,
                'range'       => ['start' => $startDate, 'end' => $endDate],
            ]);
        } catch (Throwable $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'update_budget') {
        saVerifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $tenantId = (int)($d['tenant_id'] ?? 0);
        $budget   = max(0, (int)($d['budget'] ?? 0));
        if (!$tenantId) { echo json_encode(['error' => 'tenant_id wajib']); exit; }
        try {
            $db->prepare("UPDATE tenants SET ai_daily_budget_coin = ? WHERE id = ?")
               ->execute([$budget, $tenantId]);
            logSuperAdminAction('update_ai_budget', $tenantId, "Set AI daily budget ke $budget coin");
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}

$csrf = saGetCsrf();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php saRenderHead('AI Usage'); ?>
<style>
  .kpi-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:14px; margin-bottom:20px; }
  .kpi-card {
    background:rgba(10,15,31,.4); border:1px solid var(--crease);
    border-radius:12px; padding:16px;
  }
  .kpi-label { font-size:11px; color:var(--ash); text-transform:uppercase; letter-spacing:.05em; }
  .kpi-value { font-size:22px; font-weight:800; margin-top:6px; font-family:var(--mono); color:var(--glow); }
  .kpi-value.positive { color:#86EFAC; }
  .kpi-value.negative { color:#F43F5E; }
  .kpi-sub { font-size:11px; color:var(--ash); margin-top:4px; }

  .range-bar {
    display:flex; gap:10px; align-items:center; margin-bottom:18px; flex-wrap:wrap;
    background:rgba(10,15,31,.4); padding:12px 14px; border-radius:10px;
  }
  .range-bar input[type=date] {
    background:var(--slate-elev); border:1px solid var(--crease);
    border-radius:6px; padding:6px 10px; color:var(--glow); font-size:13px;
  }
  .quick-pill {
    background:var(--slate-elev); color:var(--glow); border:1px solid var(--crease);
    padding:6px 12px; border-radius:6px; cursor:pointer; font-size:12px; font-weight:600;
  }
  .quick-pill:hover { background:var(--slate-elev); color:var(--glow); }

  .margin-cell { font-family:var(--mono); font-weight:700; }
  .margin-cell.positive { color:#86EFAC; }
  .margin-cell.negative { color:#F43F5E; }

  .feature-badge {
    background:rgba(168,85,247,.18); color:#C4B5FD;
    padding:3px 8px; border-radius:6px; font-size:11px; font-weight:700; font-family:var(--mono);
  }

  .budget-input {
    background:var(--slate-elev); border:1px solid var(--crease);
    border-radius:6px; padding:6px 10px; color:var(--glow); font-size:12px; font-family:var(--mono);
    width:90px;
  }

  .chart-card { background:rgba(10,15,31,.4); border:1px solid var(--crease-soft); border-radius:12px; padding:16px; }
  .bar-row { display:flex; align-items:center; gap:8px; margin-bottom:6px; }
  .bar-row .bar-label { width:90px; font-size:11px; color:var(--ink-soft); font-family:var(--mono); }
  .bar-row .bar-track { flex:1; background:var(--slate-elev); border-radius:4px; height:18px; position:relative; overflow:hidden; }
  .bar-row .bar-fill { position:absolute; left:0; top:0; bottom:0; background:linear-gradient(90deg, #35E8D5, #1CC4B2); border-radius:4px; }
  .bar-row .bar-val { font-size:11px; color:var(--ink-soft); min-width:80px; text-align:right; font-family:var(--mono); }
</style>
</head>
<body>
<div class="sa-layout">
<?php saRenderNav('ai_usage', 'AI Usage'); ?>

<div class="sa-ai-strip"></div>
<div class="sa-page-header">
  <h1 style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    AI Usage &amp; Margin <span class="sa-ai-pill">Powered by Claude</span>
  </h1>
  <p>Monitor pemakaian AI per tenant, cost actual vs revenue coin</p>
</div>

<!-- Range picker -->
<div class="range-bar">
  <strong style="font-size:12px;color:var(--ink-soft);">Periode:</strong>
  <input type="date" id="fStart" value="<?= htmlspecialchars($startDate) ?>">
  <span style="color:var(--ash);">—</span>
  <input type="date" id="fEnd" value="<?= htmlspecialchars($endDate) ?>">
  <button class="sa-btn sa-btn-primary sa-btn-sm" onclick="reload()">Terapkan</button>
  <span style="flex:1"></span>
  <button class="quick-pill" onclick="setRange(0)">Hari ini</button>
  <button class="quick-pill" onclick="setRange(6)">7 hari</button>
  <button class="quick-pill" onclick="setRange(29)">30 hari</button>
</div>

<!-- KPI Cards -->
<div class="kpi-grid" id="kpiGrid">
  <div class="kpi-card"><div class="kpi-label">Total AI Calls</div><div class="kpi-value" id="kCalls">—</div><div class="kpi-sub" id="kCacheRate">cache: —</div></div>
  <div class="kpi-card"><div class="kpi-label">Total Tokens</div><div class="kpi-value" id="kTokens">—</div></div>
  <div class="kpi-card"><div class="kpi-label">Cost Anthropic</div><div class="kpi-value negative" id="kCost">—</div><div class="kpi-sub">est. rupiah</div></div>
  <div class="kpi-card"><div class="kpi-label">Revenue Coin</div><div class="kpi-value positive" id="kRevenue">—</div><div class="kpi-sub" id="kCoinRate">@ —/coin</div></div>
  <div class="kpi-card"><div class="kpi-label">Net Margin</div><div class="kpi-value" id="kMargin">—</div><div class="kpi-sub" id="kMarginPct">—</div></div>
</div>

<!-- By Feature -->
<div class="sa-card" style="margin-bottom:18px;">
  <div class="sa-card-header"><h3>Per Fitur</h3></div>
  <div class="sa-table-wrap">
    <table class="sa-table">
      <thead>
        <tr>
          <th>Fitur</th>
          <th style="text-align:right;">Calls</th>
          <th style="text-align:right;">Cache Hits</th>
          <th style="text-align:right;">Tokens (in / out)</th>
          <th style="text-align:right;">Cost (Rp)</th>
          <th style="text-align:right;">Revenue (Rp)</th>
          <th style="text-align:right;">Margin</th>
        </tr>
      </thead>
      <tbody id="byFeatureBody">
        <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--ash-dim);">Memuat...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- By Tenant -->
<div class="sa-card" style="margin-bottom:18px;">
  <div class="sa-card-header"><h3>Per Tenant — Top Usage</h3></div>
  <div class="sa-table-wrap">
    <table class="sa-table">
      <thead>
        <tr>
          <th>Tenant</th>
          <th style="text-align:right;">Calls</th>
          <th style="text-align:right;">Tokens</th>
          <th style="text-align:right;">Cost (Rp)</th>
          <th style="text-align:right;">Revenue (Rp)</th>
          <th style="text-align:right;">Margin</th>
          <th style="text-align:right;">Budget/hari</th>
        </tr>
      </thead>
      <tbody id="byTenantBody">
        <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--ash-dim);">Memuat...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Trend Chart -->
<div class="chart-card">
  <h3 style="font-size:14px;font-weight:700;margin-bottom:14px;color:var(--glow);">Trend Harian (Cost vs Revenue)</h3>
  <div id="trendChart"><div style="text-align:center;padding:32px;color:var(--ash-dim);">Memuat...</div></div>
</div>

<?php saRenderNavClose(); ?>

<script>
const CSRF = <?= json_encode($csrf) ?>;

function fmt(n) { return Number(n || 0).toLocaleString('id-ID'); }
function esc(s) { return (s==null?'':String(s)).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function setRange(days) {
  const end = new Date();
  const start = new Date(); start.setDate(end.getDate() - days);
  document.getElementById('fStart').value = start.toISOString().slice(0,10);
  document.getElementById('fEnd').value   = end.toISOString().slice(0,10);
  reload();
}

async function reload() {
  const s = document.getElementById('fStart').value;
  const e = document.getElementById('fEnd').value;
  const r = await fetch('/superadmin/ai_usage.php?action=overview&start=' + s + '&end=' + e);
  const j = await r.json();
  if (!j.ok) { alert(j.error || 'Gagal load'); return; }
  renderKPI(j.totals, j.coin_to_idr);
  renderByFeature(j.by_feature, j.coin_to_idr);
  renderByTenant(j.by_tenant, j.coin_to_idr);
  renderTrend(j.by_date);
}

function renderKPI(t, coinRate) {
  document.getElementById('kCalls').textContent   = fmt(t.calls);
  const cacheRate = t.calls > 0 ? Math.round((t.cache_hits / t.calls) * 100) : 0;
  document.getElementById('kCacheRate').textContent = `cache hits: ${fmt(t.cache_hits)} (${cacheRate}%)`;
  document.getElementById('kTokens').textContent  = fmt(t.tokens_total);
  document.getElementById('kCost').textContent    = 'Rp ' + fmt(t.cost_idr);
  document.getElementById('kRevenue').textContent = 'Rp ' + fmt(t.revenue_idr);
  document.getElementById('kCoinRate').textContent = `@ Rp ${coinRate}/coin (Popular Pack)`;
  const mEl = document.getElementById('kMargin');
  mEl.textContent = 'Rp ' + fmt(t.margin_idr);
  mEl.className = 'kpi-value ' + (t.margin_idr >= 0 ? 'positive' : 'negative');
  document.getElementById('kMarginPct').textContent = (t.margin_idr >= 0 ? '+' : '') + t.margin_pct + '%';
}

function renderByFeature(rows, coinRate) {
  const tb = document.getElementById('byFeatureBody');
  if (!rows || rows.length === 0) {
    tb.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px;color:var(--ash-dim);">Belum ada data</td></tr>';
    return;
  }
  tb.innerHTML = rows.map(r => {
    const revenue = Math.round(r.coin_charged * coinRate);
    const margin  = revenue - r.cost_idr;
    const mClass  = margin >= 0 ? 'positive' : 'negative';
    return `
      <tr>
        <td><span class="feature-badge">${esc(r.feature_key)}</span></td>
        <td style="text-align:right;">${fmt(r.total_calls)}</td>
        <td style="text-align:right;font-size:11px;color:var(--ink-soft);">${fmt(r.cache_hits)}</td>
        <td style="text-align:right;font-family:var(--mono);font-size:12px;color:var(--ink-soft);">
          ${fmt(r.tokens_in)} / ${fmt(r.tokens_out)}
        </td>
        <td style="text-align:right;font-family:var(--mono);">${fmt(r.cost_idr)}</td>
        <td style="text-align:right;font-family:var(--mono);color:#86EFAC;">${fmt(revenue)}</td>
        <td class="margin-cell ${mClass}" style="text-align:right;">${margin >= 0 ? '+' : ''}${fmt(margin)}</td>
      </tr>
    `;
  }).join('');
}

function renderByTenant(rows, coinRate) {
  const tb = document.getElementById('byTenantBody');
  if (!rows || rows.length === 0) {
    tb.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px;color:var(--ash-dim);">Belum ada data</td></tr>';
    return;
  }
  tb.innerHTML = rows.map(r => {
    const tokens = (parseInt(r.tokens_in) + parseInt(r.tokens_out));
    const revenue = Math.round(r.coin_charged * coinRate);
    const margin  = revenue - r.cost_idr;
    const mClass  = margin >= 0 ? 'positive' : 'negative';
    return `
      <tr>
        <td>
          <div style="font-weight:600;">${esc(r.nama_perusahaan || 'Tenant #' + r.tenant_id)}</div>
          <div style="font-size:10px;color:var(--ash);">${esc(r.owner_email || '')}</div>
        </td>
        <td style="text-align:right;">${fmt(r.total_calls)}</td>
        <td style="text-align:right;font-family:var(--mono);font-size:12px;">${fmt(tokens)}</td>
        <td style="text-align:right;font-family:var(--mono);">${fmt(r.cost_idr)}</td>
        <td style="text-align:right;font-family:var(--mono);color:#86EFAC;">${fmt(revenue)}</td>
        <td class="margin-cell ${mClass}" style="text-align:right;">${margin >= 0 ? '+' : ''}${fmt(margin)}</td>
        <td style="text-align:right;">
          <input type="number" class="budget-input" value="${r.ai_daily_budget_coin || 10000}"
                 onchange="updateBudget(${r.tenant_id}, this.value)" min="0" step="1000">
        </td>
      </tr>
    `;
  }).join('');
}

function renderTrend(rows) {
  const c = document.getElementById('trendChart');
  if (!rows || rows.length === 0) {
    c.innerHTML = '<div style="text-align:center;padding:32px;color:var(--ash-dim);">Belum ada data</div>';
    return;
  }
  // Find max for scaling
  let maxVal = 0;
  rows.forEach(r => {
    maxVal = Math.max(maxVal, parseInt(r.cost_idr), Math.round(r.coin_charged * 4.17));
  });
  c.innerHTML = rows.map(r => {
    const revenue = Math.round(r.coin_charged * 4.17);
    const cost = parseInt(r.cost_idr);
    const margin = revenue - cost;
    const costPct = maxVal > 0 ? (cost / maxVal) * 100 : 0;
    const revPct  = maxVal > 0 ? (revenue / maxVal) * 100 : 0;
    return `
      <div style="margin-bottom:14px;border-bottom:1px solid var(--crease-soft);padding-bottom:10px;">
        <div style="display:flex;justify-content:space-between;margin-bottom:6px;font-size:12px;color:var(--ink-soft);">
          <strong>${r.tanggal}</strong>
          <span>${fmt(r.total_calls)} calls · margin: <span class="${margin >= 0 ? 'positive' : 'negative'}" style="font-family:var(--mono);font-weight:700;color:${margin >= 0 ? '#86EFAC' : '#FCA5A5'};">${margin >= 0 ? '+' : ''}Rp ${fmt(margin)}</span></span>
        </div>
        <div class="bar-row">
          <div class="bar-label">Cost</div>
          <div class="bar-track"><div class="bar-fill" style="width:${costPct}%;background:linear-gradient(90deg,#EF4444,#DC2626);"></div></div>
          <div class="bar-val">Rp ${fmt(cost)}</div>
        </div>
        <div class="bar-row">
          <div class="bar-label">Revenue</div>
          <div class="bar-track"><div class="bar-fill" style="width:${revPct}%;"></div></div>
          <div class="bar-val">Rp ${fmt(revenue)}</div>
        </div>
      </div>
    `;
  }).join('');
}

async function updateBudget(tenantId, budget) {
  const r = await fetch('/superadmin/ai_usage.php?action=update_budget', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
    body: JSON.stringify({ tenant_id: tenantId, budget: parseInt(budget) }),
  });
  const j = await r.json();
  if (j.error) { alert(j.error); return; }
}

reload();
</script>
</body>
</html>

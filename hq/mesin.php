<?php
// ══════════════════════════════════════════════════════
// hq/mesin.php — Konsolidasi Mesin Self-Service Lintas Outlet
//
// Tab Live    : grid mesin semua outlet (status real-time)
// Tab Revenue : tabel revenue + utilization per mesin per outlet
// ══════════════════════════════════════════════════════
$activePage = 'hq-mesin';
$pageTitle  = 'Mesin Self-Service Lintas Outlet';
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';

// requirePermission/logAudit stubs sudah di hq_guard.php

$db   = Database::get();
$tid  = (int) TenantResolver::id();
$user = currentUser();
$csrf = getCsrfToken();

if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || !empty($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    // ── LIVE: all mesin per outlet + active sesi
    if ($action === 'live_consolidated') {
        $stmt = $db->prepare(
            "SELECT m.*, o.nama_outlet,
                    s.id           AS sesi_id,
                    s.pelanggan_nama,
                    s.cycle_label,
                    s.estimated_done_at,
                    s.status       AS sesi_status,
                    s.started_at
             FROM hl_mesin m
             JOIN outlets o ON o.id = m.outlet_id
             LEFT JOIN hl_mesin_sesi s
                    ON s.mesin_id = m.id
                   AND s.status IN ('booked','running')
                   AND s.id = (SELECT MAX(s2.id) FROM hl_mesin_sesi s2 WHERE s2.mesin_id=m.id AND s2.status IN ('booked','running'))
             WHERE m.tenant_id = ? AND m.is_active = 1
               AND o.status IN ('active','trial','grace')
             ORDER BY o.is_main DESC, o.nama_outlet ASC, m.tipe ASC, m.nama ASC"
        );
        $stmt->execute([$tid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by outlet
        $byOutlet = [];
        foreach ($rows as $r) {
            $oid = $r['outlet_id'];
            if (!isset($byOutlet[$oid])) {
                $byOutlet[$oid] = ['outlet_id'=>$oid, 'nama_outlet'=>$r['nama_outlet'], 'mesin'=>[]];
            }
            $byOutlet[$oid]['mesin'][] = $r;
        }
        echo json_encode(['data' => array_values($byOutlet), 'server_time' => date('Y-m-d H:i:s')]);
        exit;
    }

    // ── REVENUE per mesin: count sesi done + total revenue periode
    if ($action === 'revenue') {
        $dari = $_GET['dari'] ?? date('Y-m-01');
        $sampai = $_GET['sampai'] ?? date('Y-m-d');

        $stmt = $db->prepare(
            "SELECT m.id, m.nama, m.kode, m.tipe, m.outlet_id, o.nama_outlet,
                    COUNT(s.id) AS sesi_count,
                    COALESCE(SUM(CASE WHEN s.status='done' AND s.status_bayar='lunas' THEN s.tarif ELSE 0 END), 0) AS revenue,
                    COALESCE(SUM(CASE WHEN s.status='done' THEN s.durasi_menit ELSE 0 END), 0) AS total_menit
             FROM hl_mesin m
             JOIN outlets o ON o.id = m.outlet_id
             LEFT JOIN hl_mesin_sesi s ON s.mesin_id = m.id AND DATE(s.booked_at) BETWEEN ? AND ?
             WHERE m.tenant_id = ? AND m.is_active = 1
             GROUP BY m.id
             ORDER BY revenue DESC, o.nama_outlet, m.nama"
        );
        $stmt->execute([$dari, $sampai, $tid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Hitung utilization: total_menit / (total menit periode * 1.0)
        $startTs = strtotime($dari);
        $endTs   = strtotime($sampai . ' 23:59:59');
        $totalMenitPeriode = max(1, intval(($endTs - $startTs) / 60));
        foreach ($rows as &$r) {
            $r['utilization_pct'] = round(($r['total_menit'] / $totalMenitPeriode) * 100, 1);
        }
        unset($r);

        // Aggregates
        $totalRev = array_sum(array_column($rows, 'revenue'));
        $totalSesi = array_sum(array_column($rows, 'sesi_count'));

        echo json_encode([
            'data' => $rows,
            'summary' => [
                'total_revenue' => $totalRev,
                'total_sesi' => $totalSesi,
                'periode' => "$dari → $sampai",
            ]
        ]);
        exit;
    }

    echo json_encode(['error'=>'Unknown action']);
    exit;
}

require __DIR__ . '/_layout_open.php';
?>

<style>
.hq-ms-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
.hq-ms-card{background:#fff;border-radius:14px;padding:14px 16px;border:1px solid #E5E7EB;position:relative;overflow:hidden}
.hq-ms-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px}
.hq-ms-card.outlet::before{background:linear-gradient(90deg,#3B82F6,#60A5FA)}
.hq-ms-card.mesin::before{background:linear-gradient(90deg,#8B5CF6,#A78BFA)}
.hq-ms-card.running::before{background:linear-gradient(90deg,#10B981,#34D399)}
.hq-ms-card.rev::before{background:linear-gradient(90deg,#F59E0B,#FBBF24)}
.hq-ms-num{font-size:1.5rem;font-weight:800;color:#0F1C3A;font-family:'DM Mono',monospace;margin-bottom:4px}

.hq-tabs{display:flex;gap:4px;margin-bottom:18px;border-bottom:2px solid #E5E7EB}
.hq-tab{padding:10px 18px;cursor:pointer;font-weight:600;font-size:14px;color:#6B7280;background:none;border:none;border-bottom:3px solid transparent;margin-bottom:-2px}
.hq-tab.active{color:#0F766E;border-bottom-color:#14B8A6}

.outlet-section{margin-bottom:24px}
.outlet-head{font-size:14px;font-weight:800;color:#0F1C3A;background:#F3F4F6;padding:10px 14px;border-radius:8px;margin-bottom:10px;display:flex;justify-content:space-between}
.mesin-tile-hq{background:white;border:1px solid #E5E7EB;border-radius:10px;padding:12px 14px;display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;font-size:13px}
.mesin-tile-hq.running {border-color:#3B82F6;background:#EFF6FF}
.mesin-tile-hq.booked  {border-color:#F59E0B;background:#FFFBEB}
.mt-tipe-mini{display:inline-block;font-size:10px;padding:1px 6px;border-radius:8px;text-transform:uppercase;font-weight:700;margin-left:6px}
.mt-tipe-mini.cuci{background:#DBEAFE;color:#1E40AF}
.mt-tipe-mini.kering{background:#FEF3C7;color:#92400E}

.rev-table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;font-size:13px}
.rev-table th,.rev-table td{padding:10px 12px;border-bottom:1px solid #F3F4F6;text-align:left}
.rev-table th{background:#F9FAFB;font-size:11px;text-transform:uppercase;color:#6B7280;font-weight:700}
.rev-table td.num{text-align:right;font-family:'DM Mono',monospace;font-weight:700}
.util-bar{display:inline-block;width:60px;height:6px;background:#E5E7EB;border-radius:3px;overflow:hidden;vertical-align:middle;margin-right:6px}
.util-bar-fill{height:100%;background:linear-gradient(90deg,#10B981,#14B8A6)}
</style>

<div class="hq-page-wrap">
  <div style="margin-bottom:18px">
    <h1 style="margin:0 0 4px;font-size:24px;font-weight:800;color:#0F1C3A">🪙 Mesin Self-Service Lintas Outlet</h1>
    <p style="margin:0;color:#6B7280;font-size:14px">Monitor status real-time semua mesin, revenue, dan utilization per outlet.</p>
  </div>

  <div class="hq-ms-summary">
    <div class="hq-ms-card outlet"><div class="hq-ms-num" id="hqSumOutlet">-</div><div style="font-size:11px;color:#6B7280;font-weight:600;text-transform:uppercase">🏪 Outlet dgn Mesin</div></div>
    <div class="hq-ms-card mesin"><div class="hq-ms-num" id="hqSumMesin">-</div><div style="font-size:11px;color:#6B7280;font-weight:600;text-transform:uppercase">📦 Total Mesin Aktif</div></div>
    <div class="hq-ms-card running"><div class="hq-ms-num" id="hqSumRunning" style="color:#10B981">-</div><div style="font-size:11px;color:#6B7280;font-weight:600;text-transform:uppercase">▶️ Sedang Running</div></div>
    <div class="hq-ms-card rev"><div class="hq-ms-num" id="hqSumRev" style="color:#D97706">Rp 0</div><div style="font-size:11px;color:#6B7280;font-weight:600;text-transform:uppercase">💰 Revenue Bulan Ini</div></div>
  </div>

  <div class="hq-tabs">
    <button class="hq-tab active" onclick="switchHqMsTab('live',this)">🔴 LIVE per Outlet</button>
    <button class="hq-tab" onclick="switchHqMsTab('revenue',this)">💰 Revenue & Utilization</button>
  </div>

  <div id="hq-ms-tab-live" class="hq-ms-tab-content">
    <div id="hqLiveBody"><div style="text-align:center;padding:40px;color:#6B7280">⏳ Memuat...</div></div>
  </div>

  <div id="hq-ms-tab-revenue" class="hq-ms-tab-content" style="display:none">
    <div style="display:flex;gap:10px;margin-bottom:14px;align-items:center">
      <label style="font-size:12px;font-weight:700;color:#0F1C3A">Dari</label>
      <input type="date" id="rvDari" class="hl-input" style="width:auto" onchange="loadRevenue()"/>
      <label style="font-size:12px;font-weight:700;color:#0F1C3A">s/d</label>
      <input type="date" id="rvSampai" class="hl-input" style="width:auto" onchange="loadRevenue()"/>
    </div>
    <div style="overflow-x:auto">
      <table class="rev-table">
        <thead>
          <tr>
            <th>Outlet</th>
            <th>Mesin</th>
            <th>Tipe</th>
            <th class="num">Total Sesi</th>
            <th class="num">Revenue</th>
            <th>Utilization</th>
          </tr>
        </thead>
        <tbody id="rvBody"><tr><td colspan="6" style="text-align:center;padding:40px;color:#6B7280">⏳ Memuat...</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

<script>
let liveTimer = null;
function switchHqMsTab(tab, el) {
  document.querySelectorAll('.hq-tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  document.querySelectorAll('.hq-ms-tab-content').forEach(t => t.style.display = 'none');
  document.getElementById('hq-ms-tab-' + tab).style.display = 'block';
  if (tab === 'live') { loadLiveHq(); startLiveHqTimer(); }
  else { stopLiveHqTimer(); }
  if (tab === 'revenue') loadRevenue();
}
function startLiveHqTimer() { stopLiveHqTimer(); liveTimer = setInterval(loadLiveHq, 15000); }
function stopLiveHqTimer() { if (liveTimer) clearInterval(liveTimer); liveTimer = null; }

async function loadLiveHq() {
  const r = await fetch('/hq/mesin?action=live_consolidated', { headers: { 'X-Requested-With':'XMLHttpRequest' } });
  const j = await r.json();
  const wrap = document.getElementById('hqLiveBody');

  let totalMesin = 0, running = 0;
  j.data.forEach(o => {
    totalMesin += o.mesin.length;
    o.mesin.forEach(m => { if (m.sesi_status === 'running') running++; });
  });
  document.getElementById('hqSumOutlet').textContent  = j.data.length;
  document.getElementById('hqSumMesin').textContent   = totalMesin;
  document.getElementById('hqSumRunning').textContent = running;

  if (!j.data.length) {
    wrap.innerHTML = '<div style="text-align:center;padding:40px;color:#6B7280">Belum ada outlet yang punya mesin. Tambah lewat halaman /mesin di outlet masing-masing.</div>';
    return;
  }

  wrap.innerHTML = j.data.map(o => {
    const tiles = o.mesin.map(m => {
      let body = '';
      if (m.sesi_status === 'running') {
        const est = m.estimated_done_at;
        body = `<span style="color:#1E40AF;font-weight:700">▶️ ${esc(m.pelanggan_nama)}</span> · <span class="countdown-hq" data-est="${new Date(est.replace(' ','T')).toISOString()}">--:--</span>`;
      } else if (m.sesi_status === 'booked') {
        body = `<span style="color:#92400E;font-weight:700">⏳ Booked: ${esc(m.pelanggan_nama)}</span>`;
      } else {
        body = `<span style="color:#9CA3AF">Idle</span>`;
      }
      return `<div class="mesin-tile-hq ${m.sesi_status||''}">
        <div><strong>${esc(m.nama)}</strong><span class="mt-tipe-mini ${m.tipe}">${m.tipe}</span></div>
        <div>${body}</div>
      </div>`;
    }).join('');
    return `<div class="outlet-section">
      <div class="outlet-head">🏪 ${esc(o.nama_outlet)} <span style="font-weight:500;color:#6B7280">${o.mesin.length} mesin</span></div>
      ${tiles}
    </div>`;
  }).join('');
}

async function loadRevenue() {
  const dari = document.getElementById('rvDari').value;
  const sampai = document.getElementById('rvSampai').value;
  const r = await fetch(`/hq/mesin?action=revenue&dari=${dari}&sampai=${sampai}`, { headers: { 'X-Requested-With':'XMLHttpRequest' }});
  const j = await r.json();
  document.getElementById('hqSumRev').textContent = 'Rp ' + fmtNum(j.summary.total_revenue);

  const tbody = document.getElementById('rvBody');
  if (!j.data.length) { tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:40px;color:#6B7280">Belum ada data.</td></tr>'; return; }
  tbody.innerHTML = j.data.map(m => {
    const util = m.utilization_pct;
    return `<tr>
      <td>${esc(m.nama_outlet)}</td>
      <td><strong>${esc(m.nama)}</strong> <small style="color:#6B7280">${esc(m.kode)}</small></td>
      <td><span class="mt-tipe-mini ${m.tipe}">${m.tipe}</span></td>
      <td class="num">${m.sesi_count}</td>
      <td class="num">Rp ${fmtNum(m.revenue)}</td>
      <td><span class="util-bar"><span class="util-bar-fill" style="width:${Math.min(100,util*4)}%"></span></span><strong>${util}%</strong></td>
    </tr>`;
  }).join('');
}

// Countdown updater
setInterval(() => {
  document.querySelectorAll('.countdown-hq[data-est]').forEach(el => {
    const diff = Math.floor((new Date(el.dataset.est).getTime() - Date.now()) / 1000);
    if (diff <= 0) { el.textContent = 'selesai'; el.style.color = '#EF4444'; }
    else { const m = Math.floor(diff/60), s = diff%60; el.textContent = String(m).padStart(2,'0')+':'+String(s).padStart(2,'0'); }
  });
}, 1000);

// esc/fmtNum sudah global di components.php

// Init
(function init() {
  const today = new Date().toISOString().split('T')[0];
  document.getElementById('rvDari').value = today.substring(0,8) + '01';
  document.getElementById('rvSampai').value = today;
  loadLiveHq();
  startLiveHqTimer();
})();
</script>

<?php require __DIR__ . '/_layout_close.php'; ?>

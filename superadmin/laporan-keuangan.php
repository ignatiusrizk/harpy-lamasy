<?php
// superadmin/laporan-keuangan.php — Laporan Keuangan platform (P&L + coin float + rincian + export CSV).
if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';
require_once SA_ROOT . '/../core/SaFinance.php';
date_default_timezone_set('Asia/Jakarta');

// ── Sanitasi periode (default: bulan ini) ──
function lkDate(?string $v, string $fallback): string {
    if ($v) { $d = DateTime::createFromFormat('Y-m-d', $v); if ($d && $d->format('Y-m-d') === $v) return $v; }
    return $fallback;
}
$from = lkDate($_GET['from'] ?? null, date('Y-m-01'));
$to   = lkDate($_GET['to']   ?? null, date('Y-m-t'));
if ($from > $to) { $from = date('Y-m-01'); $to = date('Y-m-t'); }

// ── Export CSV (Task 3 mengisi handler ini) ──
if (($_GET['action'] ?? '') === 'export') {
    saVerifyCsrf();
    require SA_ROOT . '/laporan-keuangan-export.inc.php';
    exit;
}

$pnl  = SaFinance::pnl($from, $to);
$rows = SaFinance::revenueRows($from, $to);
$rp = fn($n) => 'Rp ' . number_format((int)$n, 0, ',', '.');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php saRenderHead('Laporan Keuangan'); ?>
<style>
  .lk-filter { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:20px; }
  .lk-filter input[type=date]{ padding:8px 10px; border:1px solid var(--crease,#e5e7eb); border-radius:8px; font-size:13px; }
  .lk-pnl { max-width:520px; }
  .lk-row { display:flex; justify-content:space-between; padding:7px 0; font-size:14px; }
  .lk-row.sub { color:var(--ash,#6B7280); padding-left:14px; font-size:13px; }
  .lk-row.total { font-weight:800; border-top:1px solid var(--crease,#e5e7eb); margin-top:4px; }
  .lk-row.laba { font-weight:800; font-size:16px; border-top:2px solid #0F1C3A; margin-top:6px; padding-top:10px; }
  .lk-neg { color:#DC2626; }
</style>
</head>
<body>
<div class="sa-layout">
<?php saRenderNav('laporan-keuangan', 'Laporan Keuangan'); ?>

<div class="sa-page-header">
  <h1>📑 Laporan Keuangan</h1>
</div>

<div class="lk-filter">
  <button class="sa-btn sa-btn-sm sa-btn-outline" onclick="lkPreset('month')">Bulan Ini</button>
  <button class="sa-btn sa-btn-sm sa-btn-outline" onclick="lkPreset('lastmonth')">Bulan Lalu</button>
  <button class="sa-btn sa-btn-sm sa-btn-outline" onclick="lkPreset('year')">Tahun Ini</button>
  <input type="date" id="lkFrom" value="<?= htmlspecialchars($from) ?>">
  <span>s/d</span>
  <input type="date" id="lkTo" value="<?= htmlspecialchars($to) ?>">
  <button class="sa-btn sa-btn-sm" onclick="lkApply()">Terapkan</button>
  <button class="sa-btn sa-btn-sm sa-btn-outline" onclick="lkExport()">⬇ Export CSV</button>
</div>

<div class="sa-card lk-pnl">
  <div class="sa-card-header"><h3>Laba / Rugi Platform</h3></div>
  <div class="sa-card-body">
    <div class="lk-row"><span><strong>Pendapatan</strong></span><span></span></div>
    <div class="lk-row sub"><span>Setup Fee</span><span><?= $rp($pnl['revenue']['setup_fee']) ?></span></div>
    <div class="lk-row sub"><span>Top-up Coin</span><span><?= $rp($pnl['revenue']['coin_topup']) ?></span></div>
    <div class="lk-row sub"><span>Adjustment</span><span><?= $rp($pnl['revenue']['adjustment']) ?></span></div>
    <div class="lk-row total"><span>Total Pendapatan</span><span><?= $rp($pnl['revenue']['total']) ?></span></div>
    <div class="lk-row" style="margin-top:10px"><span><strong>Biaya</strong></span><span></span></div>
    <div class="lk-row sub"><span>Biaya AI (Anthropic)</span><span class="lk-neg">(<?= $rp($pnl['ai_cost']) ?>)</span></div>
    <div class="lk-row sub"><span>Komisi Affiliate</span><span class="lk-neg">(<?= $rp($pnl['affiliate']) ?>)</span></div>
    <div class="lk-row total"><span>Total Biaya</span><span class="lk-neg">(<?= $rp($pnl['total_cost']) ?>)</span></div>
    <div class="lk-row laba"><span>Laba Kotor Platform</span><span><?= $rp($pnl['laba']) ?> <small style="color:var(--ash,#6B7280);font-weight:600">(margin <?= $pnl['margin_pct'] ?>%)</small></span></div>
  </div>
</div>

<div class="sa-card lk-pnl" style="margin-top:18px">
  <div class="sa-card-header"><h3>Coin Float — estimasi kewajiban layanan</h3></div>
  <div class="sa-card-body">
    <div class="lk-row"><span>Coin outstanding (semua tenant)</span><span><?= number_format($pnl['coin']['coin_outstanding'],0,',','.') ?> coin</span></div>
    <div class="lk-row"><span>Estimasi nilai (rate <?= SaFinance::COIN_TO_IDR ?>/coin)</span><span>± <?= $rp($pnl['coin']['rp_estimate']) ?></span></div>
    <div class="lk-row"><span>Coin terpakai periode</span><span><?= number_format($pnl['coin_consumed'],0,',','.') ?> coin</span></div>
  </div>
</div>

<div class="sa-card" style="margin-top:18px">
  <div class="sa-card-header"><h3>Rincian Transaksi Pendapatan</h3></div>
  <div class="sa-table-wrap">
    <table class="sa-table">
      <thead><tr><th>Tanggal</th><th>Tenant</th><th>Tipe</th><th style="text-align:right">Nominal</th><th style="text-align:right">Coin</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="5" style="text-align:center;color:var(--ash,#6B7280);padding:24px">Belum ada transaksi pada periode ini</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><?= htmlspecialchars((string)$r['tanggal']) ?></td>
          <td><?= htmlspecialchars($r['tenant_nama']) ?></td>
          <td><?= htmlspecialchars($r['tipe']) ?></td>
          <td style="text-align:right"><?= $rp($r['nominal']) ?></td>
          <td style="text-align:right"><?= $r['coin'] ? number_format($r['coin'],0,',','.') : '—' ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php saRenderNavClose(); ?>
<script>
const CSRF = <?= json_encode(saGetCsrf()) ?>;
function lkApply(){
  const f = document.getElementById('lkFrom').value, t = document.getElementById('lkTo').value;
  location.href = '?from=' + encodeURIComponent(f) + '&to=' + encodeURIComponent(t);
}
function lkPreset(kind){
  const now = new Date(); let f, t;
  const iso = d => d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');
  if (kind==='month'){ f=new Date(now.getFullYear(),now.getMonth(),1); t=new Date(now.getFullYear(),now.getMonth()+1,0); }
  else if (kind==='lastmonth'){ f=new Date(now.getFullYear(),now.getMonth()-1,1); t=new Date(now.getFullYear(),now.getMonth(),0); }
  else { f=new Date(now.getFullYear(),0,1); t=new Date(now.getFullYear(),11,31); }
  document.getElementById('lkFrom').value=iso(f); document.getElementById('lkTo').value=iso(t); lkApply();
}
async function lkExport(){
  const f = document.getElementById('lkFrom').value, t = document.getElementById('lkTo').value;
  const r = await fetch('?action=export&from='+encodeURIComponent(f)+'&to='+encodeURIComponent(t), { headers:{'X-CSRF-Token':CSRF} });
  if (!r.ok){ alert('Export gagal (' + r.status + ')'); return; }
  const blob = await r.blob(); const url = URL.createObjectURL(blob);
  const a = document.createElement('a'); a.href=url; a.download='laporan-keuangan_'+f+'_'+t+'.csv';
  document.body.appendChild(a); a.click(); a.remove(); setTimeout(()=>URL.revokeObjectURL(url),4000);
}
</script>
</body>
</html>

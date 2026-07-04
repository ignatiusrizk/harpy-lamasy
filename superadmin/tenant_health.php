<?php
// ══════════════════════════════════════════════════════
// superadmin/tenant_health.php — Tenant Health Monitor (Strategi #5)
//
// Skor keterikatan (0-4) tiap tenant trial → CS/sales bisa outreach manual
// ke tenant berisiko (score rendah + hari trial mendekati akhir).
// ══════════════════════════════════════════════════════
if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';
require_once SA_ROOT . '/../core/StickinessTracker.php';

$db = Database::get();

// Tenant yang punya outlet trial + hari trial (dari trial_starts_at paling awal)
$rows = [];
try {
    $q = $db->query(
        "SELECT t.id, t.nama_perusahaan, t.owner_name, t.owner_wa, t.email,
                MIN(o.trial_starts_at) trial_start, MIN(o.trial_ends_at) trial_end
           FROM tenants t
           JOIN outlets o ON o.tenant_id=t.id AND o.status='trial'
          GROUP BY t.id, t.nama_perusahaan, t.owner_name, t.owner_wa, t.email"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($q as $r) {
        $start = $r['trial_start'] ?: ($r['trial_end'] ? date('Y-m-d H:i:s', strtotime($r['trial_end']) - 7*86400) : null);
        $day   = $start ? max(1, (int)floor((time() - strtotime($start)) / 86400) + 1) : 1;
        $stk   = StickinessTracker::calculate((int)$r['id']);
        $rows[] = $r + ['trial_day'=>$day, 'score'=>$stk['score'], 'criteria'=>$stk['criteria'],
                        'missing'=>StickinessTracker::missingLabels($stk['criteria'])];
    }
    // Paling urgent dulu: score terendah, lalu trial_day tertinggi
    usort($rows, fn($a,$b) => ($a['score'] <=> $b['score']) ?: ($b['trial_day'] <=> $a['trial_day']));
} catch (Throwable $e) { error_log('[tenant_health] '.$e->getMessage()); }

function thStatus(int $score): array {
    if ($score >= 3) return ['🟢 Siap', '#10B981'];
    if ($score == 2) return ['🟡 Berisiko', '#F59E0B'];
    return ['🔴 Kritis', '#E24B4A'];
}
$critical = count(array_filter($rows, fn($r) => $r['score'] < 3));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php saRenderHead('Tenant Health'); ?>
</head>
<body>
<?php saRenderNav('tenant_health', 'Tenant Health'); ?>

<div class="sa-page-header">
  <div>
    <h1>🩺 Tenant Health — Stickiness</h1>
    <p>Skor keterikatan tenant trial (target ≥3/4 sebelum hari-7). Untuk outreach manual CS ke tenant berisiko.</p>
  </div>
  <div style="text-align:right">
    <div style="color:var(--ash);font-size:10px;text-transform:uppercase">Trial berisiko (&lt;3)</div>
    <div style="font-family:'DM Mono',monospace;font-weight:800;font-size:22px;color:<?= $critical ? '#E24B4A' : '#10B981' ?>"><?= $critical ?> / <?= count($rows) ?></div>
  </div>
</div>

<div class="sa-ai-border" style="padding:20px;margin-top:8px">
  <table class="sa-table">
    <thead><tr>
      <th>Tenant</th><th>Trial Day</th><th>Score</th><th>Status</th>
      <th>Kriteria belum terpenuhi</th><th style="text-align:right">Kontak</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): [$slabel,$scolor] = thStatus($r['score']); ?>
      <tr>
        <td>
          <div style="font-weight:600"><?= htmlspecialchars($r['nama_perusahaan'] ?: 'Tenant #'.$r['id']) ?></div>
          <div style="font-size:11px;color:var(--ash)"><?= htmlspecialchars($r['owner_name'] ?? '') ?></div>
        </td>
        <td style="font-family:'DM Mono',monospace"><?= (int)$r['trial_day'] ?><?= $r['trial_day']>=5 ? ' ⏰' : '' ?></td>
        <td style="font-family:'DM Mono',monospace;font-weight:800;font-size:16px;color:<?= $scolor ?>"><?= (int)$r['score'] ?>/4</td>
        <td style="font-size:12px;white-space:nowrap"><?= $slabel ?></td>
        <td style="font-size:12px;color:var(--ash)">
          <?= $r['missing'] ? htmlspecialchars(implode(', ', $r['missing'])) : '<span style="color:#10B981">— lengkap —</span>' ?>
        </td>
        <td style="text-align:right;white-space:nowrap">
          <?php if (!empty($r['owner_wa'])): ?>
          <a class="lmx-btn" style="padding:5px 10px;font-size:12px" target="_blank" rel="noopener"
             href="https://wa.me/<?= preg_replace('/[^0-9]/','', (strpos($r['owner_wa'],'0')===0 ? '62'.substr($r['owner_wa'],1) : $r['owner_wa'])) ?>?text=<?= rawurlencode('Halo '.($r['owner_name']??'').', dari tim LaMaSy — mau bantu maksimalkan trial outletmu.') ?>">WA</a>
          <?php endif; ?>
          <?php if (!empty($r['email'])): ?><span style="font-size:11px;color:var(--ash)"><?= htmlspecialchars($r['email']) ?></span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($rows)): ?><tr><td colspan="6" style="text-align:center;color:var(--ash);padding:24px">Tidak ada tenant trial saat ini.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <p style="color:var(--ash);font-size:11.5px;margin-top:10px">Kriteria: Data (≥<?= StickinessTracker::MIN_TRANSAKSI ?> transaksi &amp; ≥<?= StickinessTracker::MIN_CUSTOMER ?> customer) · Laporan keuangan dibuka · Portal customer aktif · Staf non-owner transaksi. Skor internal — jangan ditampilkan ke tenant.</p>
</div>

<?php saRenderNavClose(); ?>
</body>
</html>

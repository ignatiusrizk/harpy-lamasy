<?php
// ══════════════════════════════════════════════════════
// superadmin/leads.php — Daftar subscriber / lead dari landing page
// Sumber: footer newsletter + popup exit-intent (tabel hl_leads).
// ══════════════════════════════════════════════════════
if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';

$db = Database::get();

// ── Export CSV ─────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="leads_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Email', 'Sumber', 'Tanggal', 'Referrer']);
    try {
        $rows = $db->query("SELECT email, source, created_at, referrer FROM hl_leads ORDER BY created_at DESC");
        foreach ($rows as $r) {
            fputcsv($out, [$r['email'], $r['source'], $r['created_at'], $r['referrer']]);
        }
    } catch (Throwable $e) { error_log('[leads csv] '.$e->getMessage()); }
    fclose($out);
    exit;
}

require_once SA_ROOT . '/superadmin_components.php';

$leads = []; $total = 0; $bySource = [];
try {
    $total    = (int)$db->query("SELECT COUNT(*) FROM hl_leads")->fetchColumn();
    $bySource = $db->query("SELECT source, COUNT(*) n FROM hl_leads GROUP BY source ORDER BY n DESC")->fetchAll(PDO::FETCH_KEY_PAIR);
    $leads    = $db->query("SELECT email, source, created_at FROM hl_leads ORDER BY created_at DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('[leads] '.$e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php saRenderHead('Leads'); ?>
</head>
<body>
<?php saRenderNav('leads', 'Leads'); ?>

<div class="sa-page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
  <div>
    <h1>📧 Leads / Subscriber</h1>
    <p>Email yang mendaftar dari footer newsletter &amp; popup exit-intent landing page.</p>
  </div>
  <div style="text-align:right">
    <div style="color:var(--ash);font-size:10px;text-transform:uppercase">Total lead</div>
    <div style="font-family:'DM Mono',monospace;font-weight:800;font-size:22px;color:#35E8D5"><?= number_format($total) ?></div>
  </div>
</div>

<div style="margin:8px 0 16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
  <?php foreach ($bySource as $src => $n): ?>
    <span style="font-size:12px;background:rgba(53,232,213,.1);border:1px solid rgba(53,232,213,.25);color:#35E8D5;padding:4px 10px;border-radius:100px"><?= htmlspecialchars($src) ?>: <strong><?= (int)$n ?></strong></span>
  <?php endforeach; ?>
  <a href="?export=csv" class="lmx-btn" style="margin-left:auto;padding:7px 14px;font-size:13px">⬇ Export CSV</a>
</div>

<div class="sa-ai-border" style="padding:20px">
  <table class="sa-table">
    <thead><tr><th>#</th><th>Email</th><th>Sumber</th><th>Tanggal</th></tr></thead>
    <tbody>
    <?php foreach ($leads as $i => $r): ?>
      <tr>
        <td style="color:var(--ash);font-size:12px"><?= $i + 1 ?></td>
        <td style="font-weight:600"><?= htmlspecialchars($r['email']) ?></td>
        <td style="font-size:12px"><?= htmlspecialchars($r['source']) ?></td>
        <td style="font-size:12px;font-family:'DM Mono',monospace;color:var(--ash)"><?= htmlspecialchars(substr((string)$r['created_at'],0,16)) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($leads)): ?><tr><td colspan="4" style="text-align:center;color:var(--ash);padding:24px">Belum ada lead.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <?php if ($total > 500): ?><p style="color:var(--ash);font-size:12px;margin-top:10px">Menampilkan 500 terbaru dari <?= number_format($total) ?>. Export CSV untuk semua.</p><?php endif; ?>
</div>

<?php saRenderNavClose(); ?>
</body>
</html>

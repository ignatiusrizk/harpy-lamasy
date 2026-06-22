<?php
// ══════════════════════════════════════════════════════
// produksi.php — Mobile-first worker app
//
// Stage forms: terima, cuci, kering, setrika, siap, diambil
// Actions: ?action=list|get_by_kode|mesin_list|upload_foto|save_stage
// ══════════════════════════════════════════════════════

define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/TenantQuery.php';
require_once ROOT . '/core/TenantResolver.php';

requirePermission('produksi.work');

$tid    = TenantResolver::id();
$oid    = TenantResolver::outletId();
$userId = (int)(currentUser()['id'] ?? 0);
$db     = Database::get();

// Stage mapping
const STAGE_FROM = [
  'terima'  => null,
  'cuci'    => 'masuk',
  'kering'  => 'cuci',
  'setrika' => 'kering',
  'siap'    => 'setrika',
  'diambil' => 'siap',
];
const STAGE_TO = [
  'terima'  => null,
  'cuci'    => 'cuci',
  'kering'  => 'kering',
  'setrika' => 'setrika',
  'siap'    => 'siap',
  'diambil' => 'diambil',
];

$action = $_GET['action'] ?? '';

if ($action) {
    header('Content-Type: application/json');
    // Action handlers ditambahkan di Task 4-5
    echo json_encode(['error' => 'Action belum diimplementasi: ' . $action]);
    exit;
}

$activePage = 'produksi';
$pageTitle  = '🧺 Produksi';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php require __DIR__ . '/components.php'; ?>
<?php renderHead($pageTitle); ?>
<style>
/* Placeholder styles akan ditambahkan di Task 6 */
.ol-main { padding: 20px; }
.ol-content { max-width: 1200px; margin: 0 auto; }
</style>
</head>
<body>
<?php renderTopbar($activePage); ?>

<main class="ol-main">
  <div class="ol-content">
    <h1 style="margin:0 0 16px">🧺 Produksi</h1>
    <div id="produksiRoot">
      <p style="color:var(--gray)">Stub — UI ditambahkan di Task 6.</p>
    </div>
  </div>
</main>

<?php renderToast(); ?>
</body>
</html>

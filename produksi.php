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

    if ($action === 'list') {
        $stage = $_GET['stage'] ?? 'masuk';
        // Map stage tab to status_proses filter
        $statusMap = [
            'terima'  => 'masuk',       // sama dengan masuk; differ by foto_paths existence
            'cuci'    => 'cuci',
            'kering'  => 'kering',
            'setrika' => 'setrika',
            'siap'    => 'siap',
            'diambil' => 'diambil',
        ];
        $statusFilter = $statusMap[$stage] ?? 'masuk';
        $rows = TenantQuery::raw(
            "SELECT t.id, t.no_order, t.nama_pelanggan, t.telepon, t.total,
                    t.status_proses, t.tanggal, t.estimasi_selesai,
                    (SELECT COUNT(*) FROM hl_transaksi_item WHERE transaksi_id=t.id) AS jml_item
               FROM hl_transaksi t
              WHERE t.tenant_id=? AND t.outlet_id=? AND t.status_proses=?
              ORDER BY t.tanggal DESC LIMIT 100",
            [$tid, $oid, $statusFilter]
        );
        echo json_encode(['rows' => $rows]);
        exit;
    }

    if ($action === 'get_by_kode') {
        $kode = trim($_GET['kode'] ?? '');
        if (!$kode) { echo json_encode(['error' => 'Kode kosong']); exit; }
        $order = TenantQuery::rawOne(
            "SELECT id, no_order, nama_pelanggan, telepon, total, status_proses, estimasi_selesai
               FROM hl_transaksi
              WHERE tenant_id=? AND outlet_id=? AND no_order=? LIMIT 1",
            [$tid, $oid, $kode]
        );
        if (!$order) { echo json_encode(['error' => 'Order tidak ditemukan']); exit; }
        echo json_encode($order);
        exit;
    }

    if ($action === 'mesin_list') {
        $jenis = $_GET['jenis'] ?? '';
        if (!in_array($jenis, ['cuci','kering'], true)) {
            echo json_encode(['error' => 'Jenis invalid']); exit;
        }
        $rows = TenantQuery::raw(
            "SELECT id, nama, kode FROM hl_mesin
              WHERE tenant_id=? AND outlet_id=? AND tipe=? AND status!='maintenance'
              ORDER BY nama",
            [$tid, $oid, $jenis]
        );
        echo json_encode(['rows' => $rows]);
        exit;
    }

    if ($action === 'upload_foto' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        require_once ROOT . '/core/FileUpload.php';
        $f = $_FILES['foto'] ?? null;
        if (!$f) { echo json_encode(['error' => 'File foto tidak ditemukan']); exit; }
        $res = FileUpload::uploadImage($f, 'uploads/foto_proses', 't' . $tid . '_o' . $oid);
        if (!empty($res['error'])) { echo json_encode(['error' => $res['error']]); exit; }
        echo json_encode(['ok' => true, 'path' => $res['path']]);
        exit;
    }

    if ($action === 'save_stage' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $transaksiId = (int)($d['transaksi_id'] ?? 0);
        $stage       = $d['stage'] ?? '';
        $dataFields  = $d['data'] ?? [];
        $fotoPaths   = $d['foto'] ?? [];          // array of paths
        $catatan     = trim($d['catatan'] ?? '');
        $signature   = $d['signature'] ?? '';     // data URL untuk stage diambil

        if ($transaksiId <= 0 || !array_key_exists($stage, STAGE_FROM)) {
            echo json_encode(['error' => 'Input tidak valid']); exit;
        }

        // Decode signature dataURL → save as PNG → append to foto_paths
        if ($signature && preg_match('/^data:image\/png;base64,(.+)$/', $signature, $m)) {
            $bin = base64_decode($m[1]);
            if ($bin !== false && strlen($bin) < 1000000) { // 1MB cap
                $fn = 'uploads/foto_proses/sig_t' . $tid . '_o' . $oid . '_' . bin2hex(random_bytes(8)) . '.png';
                if (file_put_contents(ROOT . '/' . $fn, $bin) !== false) {
                    $fotoPaths[] = $fn;
                }
            }
        }

        try {
            $db->beginTransaction();
            $st = $db->prepare(
                "SELECT status_proses FROM hl_transaksi
                  WHERE id=? AND tenant_id=? AND outlet_id=? FOR UPDATE"
            );
            $st->execute([$transaksiId, $tid, $oid]);
            $current = $st->fetchColumn();
            if ($current === false) { throw new Exception('Order tidak ditemukan'); }

            $expectedFrom = STAGE_FROM[$stage];
            if ($expectedFrom !== null && $current !== $expectedFrom) {
                throw new Exception('Order sudah diupdate worker lain. Refresh halaman.');
            }

            // Insert input record (outlet_id explicit — hl_proses_input not in outletTables)
            TenantQuery::insert('hl_proses_input', [
                'outlet_id'    => $oid,
                'transaksi_id' => $transaksiId,
                'stage'        => $stage,
                'karyawan_id'  => $userId,
                'data_json'    => json_encode($dataFields),
                'foto_paths'   => implode(',', $fotoPaths),
                'catatan'      => $catatan,
            ]);

            // Update status (kecuali stage 'terima')
            $newStatus = STAGE_TO[$stage];
            if ($newStatus !== null) {
                $upd = $db->prepare(
                    "UPDATE hl_transaksi SET status_proses=?, updated_at=NOW()
                      WHERE id=? AND tenant_id=? AND outlet_id=?"
                );
                $upd->execute([$newStatus, $transaksiId, $tid, $oid]);

                // Log status change
                // Schema: id, tenant_id, transaksi_id, status_lama, status_baru, tipe, catatan, oleh, created_at
                $byName = currentUser()['nama'] ?? '';
                $logSt = $db->prepare(
                    "INSERT INTO hl_proses_log (tenant_id, transaksi_id, status_lama, status_baru, tipe, catatan, oleh)
                     VALUES (?, ?, ?, ?, 'produksi', ?, ?)"
                );
                $logSt->execute([
                    $tid, $transaksiId, $current, $newStatus,
                    'Stage ' . $stage . ' via /produksi',
                    $byName,
                ]);
            }

            logAudit('proses_stage', 'transaksi', "id={$transaksiId} stage={$stage}");
            $db->commit();
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[produksi save_stage] ' . $e->getMessage());
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['error' => 'Unknown action: ' . $action]);
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

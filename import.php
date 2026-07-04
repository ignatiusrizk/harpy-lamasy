<?php
// ══════════════════════════════════════════════════════
// import.php — Self-Service Data Migration Wizard
//
// Wizard 4 langkah:
//   Step 1: Pilih entitas + download template
//   Step 2: Upload file (CSV/Excel)
//   Step 3: Preview AI mapping + konfirmasi / adjust manual
//   Step 4: Hasil import (sukses/gagal/skip per baris)
//
// AI mapping: 1.000 coin (self-service)
// Mapping dari cache: GRATIS
// ══════════════════════════════════════════════════════

$activePage = 'import';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/components.php';
require_once ROOT . '/core/AIMigrationMapper.php';
require_once ROOT . '/core/MigrationImporter.php';
require_once ROOT . '/core/AIBudget.php';
require_once ROOT . '/core/AIRateLimiter.php';

date_default_timezone_set('Asia/Jakarta');

$user = currentUser();
requirePermission('settings.roles');
$tid  = TenantResolver::id();
$oid  = TenantResolver::outletId();
$db   = Database::get();

$action = $_GET['action'] ?? '';

// ═══════════════════════════════════════════════════════
// API ACTIONS (JSON)
// ═══════════════════════════════════════════════════════
if ($action) {
    header('Content-Type: application/json');

    // ── Riwayat import ────────────────────────────────
    if ($action === 'history') {
        $rows = $db->prepare("
            SELECT id, entity_type, file_name, status,
                   total_rows, success_rows, failed_rows, skipped_rows,
                   is_assisted, created_at, completed_at
            FROM hl_migration_jobs
            WHERE tenant_id = ? AND outlet_id = ?
            ORDER BY created_at DESC
            LIMIT 20
        ");
        $rows->execute([$tid, $oid]);
        echo json_encode($rows->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // ── Upload file ───────────────────────────────────
    if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();

        $entityType = $_POST['entity_type'] ?? '';
        $allowed    = ['layanan','pelanggan','karyawan','transaksi','poin_pelanggan'];
        if (!in_array($entityType, $allowed, true)) {
            echo json_encode(['error' => 'Tipe entitas tidak valid.']); exit;
        }

        if (empty($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['error' => 'File tidak berhasil diupload. Coba lagi.']); exit;
        }

        $file = $_FILES['import_file'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ['csv', 'xlsx', 'xls'], true)) {
            echo json_encode(['error' => 'Format file tidak didukung. Gunakan CSV, XLSX, atau XLS.']); exit;
        }

        if ($file['size'] > 10 * 1024 * 1024) { // 10 MB max
            echo json_encode(['error' => 'File terlalu besar. Maksimum 10 MB.']); exit;
        }

        // Simpan file
        $uploadDir = ROOT . '/uploads/migrations/' . $tid . '/';
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

        $safeName = 'import_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $filePath = $uploadDir . $safeName;

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            echo json_encode(['error' => 'Gagal menyimpan file. Hubungi support.']); exit;
        }

        // Buat migration job
        $db->prepare("
            INSERT INTO hl_migration_jobs
              (tenant_id, outlet_id, imported_by_user, entity_type,
               file_name, file_path, file_size, file_type, status)
            VALUES (?,?,?,?, ?,?,?,?, 'uploaded')
        ")->execute([
            $tid, $oid, $user['id'], $entityType,
            $file['name'], $filePath, $file['size'], $ext,
        ]);
        $jobId = (int)$db->lastInsertId();

        // Parse file & baca headers + sample rows
        try {
            $allRows = MigrationImporter::parseFile($filePath, $ext);
        } catch (Throwable $e) {
            $db->prepare("UPDATE hl_migration_jobs SET status='failed' WHERE id=?")->execute([$jobId]);
            @unlink($filePath);
            // Log full error untuk ops, kasih generic message ke user (no PHPSpreadsheet path leak)
            ErrorLogger::logException('import_parse', $e, $tid, $oid);
            echo json_encode(['error' => 'File tidak bisa dibaca. Pastikan format CSV/XLSX/XLS valid dan tidak corrupt.']); exit;
        }

        if (empty($allRows)) {
            $db->prepare("UPDATE hl_migration_jobs SET status='failed' WHERE id=?")->execute([$jobId]);
            @unlink($filePath);
            echo json_encode(['error' => 'File kosong atau tidak ada data.']); exit;
        }

        $headers    = array_keys($allRows[0]);
        $sampleRows = array_slice($allRows, 0, 5);
        $totalRows  = count($allRows);

        // Simpan headers ke job
        $db->prepare("
            UPDATE hl_migration_jobs
            SET raw_headers = ?, total_rows = ?, status = 'ai_mapping'
            WHERE id = ?
        ")->execute([json_encode($headers), $totalRows, $jobId]);

        echo json_encode([
            'job_id'     => $jobId,
            'headers'    => $headers,
            'sample'     => $sampleRows,
            'total_rows' => $totalRows,
        ]);
        exit;
    }

    // ── Pre-check sebelum AI dipanggil ────────────────
    // Frontend pakai ini buat tau: format ini sudah pernah di-map (cache)
    // jadi gratis, atau format baru sehingga butuh AI (1.000 coin).
    // Tujuan: minta approval user sebelum coin kepotong.
    if ($action === 'check_cache' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $jobId = (int)($_POST['job_id'] ?? 0);
        $jobQ  = $db->prepare("SELECT * FROM hl_migration_jobs WHERE id=? AND tenant_id=? LIMIT 1");
        $jobQ->execute([$jobId, $tid]);
        $job = $jobQ->fetch(PDO::FETCH_ASSOC);
        if (!$job) { echo json_encode(['error' => 'Job tidak ditemukan.']); exit; }

        // Parse ulang utk dapatkan headers yang akurat (parser bisa di-update)
        $headers = json_decode($job['raw_headers'], true) ?: [];
        try {
            $all = MigrationImporter::parseFile($job['file_path'], $job['file_type']);
            if (!empty($all)) {
                $fresh = array_keys($all[0]);
                if (!empty($fresh)) {
                    $headers = $fresh;
                    $db->prepare("UPDATE hl_migration_jobs SET raw_headers=? WHERE id=?")
                       ->execute([json_encode($headers), $jobId]);
                }
            }
        } catch (Throwable) {}

        $cached  = AIMigrationMapper::hasCachedMapping($job['entity_type'], $headers);
        $balance = TenantResolver::coinBalance();
        $cost    = CoinLedger::getHarga('ai_migration_mapping');
        if ($cost <= 0) $cost = 1000;

        echo json_encode([
            'cached'       => $cached,
            'coin_cost'    => $cost,
            'coin_balance' => $balance,
            'can_afford'   => $balance >= $cost,
            'headers'      => $headers,
        ]);
        exit;
    }

    // ── AI Mapping ────────────────────────────────────
    if ($action === 'ai_map' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();

        $jobId = (int)($_POST['job_id'] ?? 0);
        $jobQ  = $db->prepare("SELECT * FROM hl_migration_jobs WHERE id=? AND tenant_id=? LIMIT 1");
        $jobQ->execute([$jobId, $tid]);
        $job = $jobQ->fetch(PDO::FETCH_ASSOC);

        if (!$job) { echo json_encode(['error' => 'Job tidak ditemukan.']); exit; }

        $ignoreCache = !empty($_POST['ignore_cache']);

        // Selalu parse ulang file supaya kalau parser di-update (mis. deteksi
        // header 2-tingkat), header baru ikut terpakai, bukan yang lama yang
        // disimpan di raw_headers saat upload.
        $sampleRows = [];
        $headers    = json_decode($job['raw_headers'], true) ?: [];
        try {
            $all        = MigrationImporter::parseFile($job['file_path'], $job['file_type']);
            $sampleRows = array_slice($all, 0, 5);
            if (!empty($sampleRows)) {
                $freshHeaders = array_keys($sampleRows[0]);
                if (!empty($freshHeaders)) {
                    $headers = $freshHeaders;
                    // Update raw_headers job kalau berubah
                    $db->prepare("UPDATE hl_migration_jobs SET raw_headers=? WHERE id=?")
                       ->execute([json_encode($headers), $jobId]);
                }
            }
        } catch (Throwable) {}

        // Cek apakah mapping dari cache (gratis) atau perlu AI (bayar coin)
        $db->prepare("UPDATE hl_migration_jobs SET status='ai_mapping' WHERE id=?")->execute([$jobId]);

        // Panggil AI mapping (cek cache dulu di dalam mapper, kecuali user paksa)
        $mapResult = AIMigrationMapper::map($job['entity_type'], $headers, $sampleRows, null, $ignoreCache);

        // Hanya 'cache' yang gratis (format pernah diimport). 'ai' = bayar,
        // 'ai_failed' = AI down → fallback heuristik, tidak dibayar.
        $freeSource = ($mapResult['source'] ?? '') === 'cache';
        $fromCache  = $freeSource;

        // Mapping dianggap GAGAL/tidak berguna kalau AI error ATAU masih ada
        // field wajib yang belum terpetakan → JANGAN potong coin (user harus
        // mapping manual / ulang, AI tidak memberi hasil yang bisa dipakai).
        $aiFailed      = ($mapResult['source'] ?? '') === 'ai_failed';
        $missingReq    = !empty($mapResult['missing_required']);
        $mappingUsable = !$aiFailed && !$missingReq;

        $coinUsed = 0;
        // Deduct HANYA jika: perlu AI (bukan gratis) + bukan assisted + hasil
        // mapping benar-benar bisa dipakai.
        if (!$freeSource && !$job['is_assisted'] && $mappingUsable) {
            if (!AIRateLimiter::canCall('ai_migration_mapping')) {
                $db->prepare("UPDATE hl_migration_jobs SET status='uploaded' WHERE id=?")->execute([$jobId]);
                echo json_encode(AIRateLimiter::errorResponse('ai_migration_mapping'));
                exit;
            }
            if (!CoinLedger::canAfford('ai_migration_mapping')) {
                $db->prepare("UPDATE hl_migration_jobs SET status='uploaded' WHERE id=?")->execute([$jobId]);
                echo json_encode([
                    'error' => 'Coin tidak cukup untuk AI mapping. Dibutuhkan 1.000 coin.',
                    'coin_required' => 1000,
                    'coin_balance'  => TenantResolver::coinBalance(),
                ]); exit;
            }
            CoinLedger::deduct('ai_migration_mapping', $jobId);
            $coinUsed = 1000;
            // Track ke dashboard AI usage super admin (best-effort)
            if (class_exists('AIBudget')) {
                try {
                    AIBudget::record(
                        TenantResolver::id(), TenantResolver::outletId(), 'ai_migration_mapping',
                        (int)($mapResult['tokens_in'] ?? 0), (int)($mapResult['tokens_out'] ?? 0),
                        1000, $mapResult['model'] ?? null, false
                    );
                } catch (Throwable) {}
            }
        }

        // Simpan mapping ke job
        $db->prepare("
            UPDATE hl_migration_jobs
            SET ai_mapping = ?, status = 'mapped'
            WHERE id = ?
        ")->execute([json_encode($mapResult), $jobId]);

        echo json_encode(array_merge($mapResult, [
            'job_id'     => $jobId,
            'from_cache' => $fromCache,
            'coin_used'  => $coinUsed,
        ]));
        exit;
    }

    // ── Konfirmasi mapping (dan jalankan import) ──────
    if ($action === 'confirm_and_import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();

        $jobId      = (int)($_POST['job_id'] ?? 0);
        $customMap  = $_POST['custom_mapping'] ?? null; // JSON string jika user adjust manual

        $jobQ = $db->prepare("SELECT * FROM hl_migration_jobs WHERE id=? AND tenant_id=? LIMIT 1");
        $jobQ->execute([$jobId, $tid]);
        $job  = $jobQ->fetch(PDO::FETCH_ASSOC);

        if (!$job || $job['status'] !== 'mapped') {
            echo json_encode(['error' => 'Job tidak valid atau mapping belum selesai.']); exit;
        }

        // Update mapping jika ada custom adjustment
        if ($customMap) {
            $customDecoded = json_decode($customMap, true);
            if (is_array($customDecoded)) {
                // Merge custom mapping ke dalam ai_mapping
                $existing = json_decode($job['ai_mapping'], true);
                $existing['mapping'] = $customDecoded;
                $db->prepare("UPDATE hl_migration_jobs SET ai_mapping=? WHERE id=?")
                   ->execute([json_encode($existing), $jobId]);
            }
        }

        // Tandai mapping dikonfirmasi
        $db->prepare("
            UPDATE hl_migration_jobs
            SET mapping_confirmed=1, mapping_confirmed_at=NOW()
            WHERE id=?
        ")->execute([$jobId]);

        // Jalankan import
        $result = MigrationImporter::process($jobId);

        if (isset($result['error'])) {
            echo json_encode(['error' => $result['error']]); exit;
        }

        echo json_encode([
            'ok'         => true,
            'job_id'     => $jobId,
            'success'    => $result['success'],
            'failed'     => $result['failed'],
            'skipped'    => $result['skipped'],
            'errors'     => array_slice($result['errors'], 0, 50), // max 50 baris error di response
        ]);
        exit;
    }

    // ── Download laporan error ────────────────────────
    if ($action === 'error_report') {
        $jobId = (int)($_GET['job_id'] ?? 0);
        $jobQ  = $db->prepare("SELECT * FROM hl_migration_jobs WHERE id=? AND tenant_id=? LIMIT 1");
        $jobQ->execute([$jobId, $tid]);
        $job = $jobQ->fetch(PDO::FETCH_ASSOC);
        if (!$job) { echo json_encode(['error' => 'Job tidak ditemukan.']); exit; }

        $errors = json_decode($job['error_log'], true) ?: [];
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="error_import_' . $jobId . '.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Baris', 'Error', 'Data']);
        foreach ($errors as $e) {
            $dataStr = is_array($e['data'] ?? null) ? implode(' | ', $e['data']) : ($e['data'] ?? '');
            fputcsv($out, [$e['baris'] ?? '-', $e['error'] ?? '-', $dataStr]);
        }
        fclose($out);
        exit;
    }

    echo json_encode(['error' => 'Action tidak dikenal.']); exit;
}

// ═══════════════════════════════════════════════════════
// PAGE RENDER
// ═══════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Import Data'); ?>
<!-- import.php styles -->
<style>
/* ── Import Wizard — Light Theme ── */
.import-wizard { max-width: 860px; margin: 0 auto; }

/* Step indicator */
.step-indicator {
    display: flex; gap: 0; margin-bottom: 32px;
    background: #fff;
    border: 1px solid #E5E9F2;
    border-radius: 12px; overflow: hidden;
    box-shadow: 0 1px 4px rgba(0,0,0,.04);
}
.step-item {
    flex: 1; padding: 14px 16px; text-align: center;
    font-size: 12.5px; font-weight: 600; color: #9CA3AF;
    border-right: 1px solid #E5E9F2;
    transition: all .2s;
    display: flex; flex-direction: column; gap: 4px; align-items: center;
}
.step-item:last-child { border-right: none; }
.step-item .step-num {
    width: 24px; height: 24px; border-radius: 50%;
    background: #E5E9F2; color: #6B7280;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 800; margin-bottom: 2px;
}
.step-item.active { color: #0F7B6C; background: #F0FDFB; }
.step-item.active .step-num { background: #35E8D5; color: #0F1C3A; }
.step-item.done { color: #059669; }
.step-item.done .step-num { background: #D1FAE5; color: #059669; }

.wizard-step { display: none; }
.wizard-step.active { display: block; }

/* Entity cards */
.entity-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 12px; margin-bottom: 20px;
}
.entity-card {
    padding: 16px; border-radius: 12px;
    border: 1.5px solid #E5E9F2; background: #fff;
    cursor: pointer; text-align: center; transition: all .15s;
    box-shadow: 0 1px 3px rgba(0,0,0,.04);
}
.entity-card:hover  { border-color: #35E8D5; background: #F0FDFB; }
.entity-card.selected { border-color: #35E8D5; background: #E6FBF8; box-shadow: 0 0 0 3px rgba(53,232,213,.15); }
.entity-card .ec-icon { font-size: 28px; margin-bottom: 8px; }
.entity-card .ec-name { font-size: 13px; font-weight: 700; color: #1B2D5A; }
.entity-card .ec-coin { font-size: 11px; color: #6B7280; margin-top: 4px; }

/* Upload zone */
.upload-zone {
    border: 2px dashed #D1D5DB; border-radius: 12px;
    padding: 40px 24px; text-align: center; cursor: pointer;
    transition: all .2s; background: #F9FAFB;
}
.upload-zone:hover, .upload-zone.drag-over { border-color: #35E8D5; background: #F0FDFB; }
.upload-zone .uz-icon { font-size: 40px; margin-bottom: 12px; }
.upload-zone .uz-text { font-size: 14px; font-weight: 600; color: #374151; }
.upload-zone .uz-sub  { font-size: 12px; color: #9CA3AF; margin-top: 4px; }

/* Mapping table */
.mapping-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.mapping-table th {
    padding: 10px 12px; background: #F7F8FC;
    font-size: 10px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase;
    color: #9CA3AF; border-bottom: 1px solid #E5E9F2;
}
.mapping-table td { padding: 10px 12px; border-bottom: 1px solid #F3F4F6; color: #374151; vertical-align: middle; }
.mapping-table tr:last-child td { border-bottom: none; }
.mapping-select {
    background: #fff; border: 1.5px solid #E5E9F2;
    border-radius: 7px; color: #1B2D5A; padding: 6px 10px;
    font-family: inherit; font-size: 12.5px; outline: none;
    transition: border-color .15s; width: 100%;
}
.mapping-select:focus { border-color: #35E8D5; }

/* Confidence pills */
.conf-pill {
    display: inline-flex; align-items: center; gap: 3px;
    padding: 2px 8px; border-radius: 20px; font-size: 10.5px; font-weight: 600;
}
.conf-high   { background: #D1FAE5; color: #065F46; }
.conf-medium { background: #FEF3C7; color: #92400E; }
.conf-low    { background: #FEE2E2; color: #991B1B; }
.conf-skip   { background: #F3F4F6; color: #6B7280; }

/* Preview table */
.preview-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.preview-table th, .preview-table td { padding: 8px 10px; border: 1px solid #E5E9F2; }
.preview-table th { background: #F7F8FC; color: #6B7280; font-weight: 600; }
.preview-table td { color: #374151; }

/* Result bar */
.result-bar {
    display: flex; gap: 16px; flex-wrap: wrap;
    background: #F7F8FC; border: 1px solid #E5E9F2;
    border-radius: 12px; padding: 20px; margin-bottom: 20px;
}
.result-stat { text-align: center; flex: 1; min-width: 100px; }
.result-stat .rs-num { font-size: 32px; font-weight: 800; font-family: var(--mono, monospace); }
.result-stat .rs-label { font-size: 11.5px; color: #9CA3AF; margin-top: 4px; }

/* Error rows */
.error-row {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 8px 12px; background: #FEF2F2;
    border-radius: 8px; border: 1px solid #FECACA;
    margin-bottom: 6px; font-size: 12.5px;
}
.error-row .er-baris { color: #9CA3AF; min-width: 48px; font-family: var(--mono, monospace); }
.error-row .er-msg   { color: #DC2626; flex: 1; }

/* History rows */
.history-row {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 0; border-bottom: 1px solid #F3F4F6;
    font-size: 12.5px; color: #374151;
}
.history-row:last-child { border-bottom: none; }

/* Spinner */
.spinner {
    display: inline-block; width: 20px; height: 20px;
    border: 2px solid #E5E9F2; border-top-color: #35E8D5;
    border-radius: 50%; animation: spin .7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

@media (max-width: 600px) {
    .entity-grid { grid-template-columns: 1fr 1fr; }
    .result-bar { gap: 10px; }
    .result-stat .rs-num { font-size: 24px; }
}
</style>
</head>
<body>
<?php renderTopbar('import'); ?>

<div class="import-wizard">
  <div class="hl-page-header" style="margin-bottom:24px;">
    <h1 style="font-size:22px;font-weight:800;color:#1B2D5A;">📥 Import Data</h1>
    <p style="font-size:13px;color:#6B7280;margin-top:4px;">
      Import data dari Smartlink, iLaundry, Excel, atau format apapun — AI mapping otomatis.
    </p>
    <div style="margin-top:8px;background:#FFFBEB;border:1px solid #FDE68A;border-radius:8px;padding:10px 14px;font-size:12.5px;color:#92400E;display:inline-block;">
      🧠 <strong>AI menganalisa isi data &amp; memetakan kolom otomatis</strong> — <strong>1.000 coin</strong> per format baru.
      Gratis kalau format file sudah pernah diimport (dikenali dari cache). Kamu akan diminta konfirmasi sebelum coin dipotong.
      Saldo kamu: <strong id="coinBalance"><?= number_format(TenantResolver::coinBalance()) ?> coin</strong>
    </div>
  </div>

  <!-- Step Indicator -->
  <div class="step-indicator">
    <div class="step-item active" id="stepInd1">
      <div class="step-num">1</div>
      <span>Pilih & Template</span>
    </div>
    <div class="step-item" id="stepInd2">
      <div class="step-num">2</div>
      <span>Upload File</span>
    </div>
    <div class="step-item" id="stepInd3">
      <div class="step-num">3</div>
      <span>Konfirmasi Mapping</span>
    </div>
    <div class="step-item" id="stepInd4">
      <div class="step-num">4</div>
      <span>Hasil Import</span>
    </div>
  </div>

  <!-- ════════════════════════════════════════
       STEP 1: Pilih Entitas
       ════════════════════════════════════════ -->
  <div class="wizard-step active" id="step1">
    <div class="hl-card">
      <div class="hl-card-header">Langkah 1 — Pilih jenis data yang ingin diimport</div>
      <div class="hl-card-body">
        <div class="entity-grid">
          <?php
          $entities = [
            'layanan'        => ['icon'=>'🧺','name'=>'Layanan',   'desc'=>'Daftar layanan & harga'],
            'pelanggan'      => ['icon'=>'👥','name'=>'Pelanggan', 'desc'=>'Data customer & kontak'],
            'karyawan'       => ['icon'=>'👤','name'=>'Karyawan',  'desc'=>'Data staff & role'],
            'transaksi'      => ['icon'=>'📋','name'=>'Transaksi', 'desc'=>'Histori order & pembayaran'],
            'poin_pelanggan' => ['icon'=>'⭐','name'=>'Poin',      'desc'=>'Saldo poin dari sistem lama'],
          ];
          foreach ($entities as $key => $e): ?>
          <div class="entity-card" id="ec_<?= $key ?>" onclick="selectEntity('<?= $key ?>')">
            <div class="ec-icon"><?= $e['icon'] ?></div>
            <div class="ec-name"><?= $e['name'] ?></div>
            <div class="ec-coin"><?= $e['desc'] ?></div>
          </div>
          <?php endforeach; ?>
        </div>

        <div id="templateSection" style="display:none;">
          <div style="background:#F7F8FC;border:1px solid #E5E9F2;border-radius:10px;padding:16px;margin-bottom:20px;">
            <div style="font-size:13px;font-weight:700;margin-bottom:10px;color:#1B2D5A;">📄 Download Template (Opsional)</div>
            <p style="font-size:12.5px;color:#6B7280;margin-bottom:12px;">
              Jika kamu punya file dari Smartlink, iLaundry, atau Excel sendiri — langsung upload saja.
              AI akan mapping otomatis. Template ini untuk data baru atau jika mau format standar LaMaSy.
            </p>
            <a id="templateLink" href="#" class="hl-btn hl-btn-outline hl-btn-sm" target="_blank">
              ⬇ Download Template CSV
            </a>
          </div>
          <div style="text-align:right;">
            <button class="hl-btn hl-btn-primary" onclick="goStep(2)">Lanjut → Upload File</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Riwayat Import -->
    <div class="hl-card">
      <div class="hl-card-header">
        📜 Riwayat Import
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadHistory()">⟳ Refresh</button>
      </div>
      <div class="hl-card-body" id="historyBody">
        <div style="color:#9CA3AF;font-size:13px;">Memuat...</div>
      </div>
    </div>
  </div><!-- /#step1 -->

  <!-- ════════════════════════════════════════
       STEP 2: Upload File
       ════════════════════════════════════════ -->
  <div class="wizard-step" id="step2">
    <div class="hl-card">
      <div class="hl-card-header">
        <span>Langkah 2 — Upload file data</span>
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="goStep(1)">← Kembali</button>
      </div>
      <div class="hl-card-body">
        <div style="background:#E6FBF8;border:1px solid #99F6E4;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:12.5px;color:#0F7B6C;">
          📌 Format didukung: <strong>CSV, Excel (.xlsx, .xls)</strong> — ukuran max 10 MB.<br>
          File dari Smartlink, iLaundry, atau Excel kustom sekalipun bisa diupload — AI yang mapping.
        </div>

        <div class="upload-zone" id="uploadZone" onclick="document.getElementById('fileInput').click()">
          <div class="uz-icon" id="uzIcon">📁</div>
          <div class="uz-text" id="uzText">Klik di sini atau drag & drop file</div>
          <div class="uz-sub" id="uzSub">CSV, XLSX, XLS — maks. 10 MB</div>
        </div>
        <input type="file" id="fileInput" accept=".csv,.xlsx,.xls" style="display:none"
               onchange="handleFileSelect(this.files[0])">

        <div id="uploadInfo" style="display:none;margin-top:12px;background:#F7F8FC;border:1px solid #E5E9F2;border-radius:8px;padding:12px 16px;font-size:13px;color:#374151;"></div>

        <div style="margin-top:16px;text-align:right;">
          <button class="hl-btn hl-btn-primary" id="uploadBtn" onclick="doUpload()" disabled
                  style="opacity:.5;cursor:not-allowed;">
            <span id="uploadBtnText">📤 Upload & Analisa AI</span>
          </button>
        </div>
      </div>
    </div>
  </div><!-- /#step2 -->

  <!-- ════════════════════════════════════════
       STEP 3: Preview Mapping
       ════════════════════════════════════════ -->
  <div class="wizard-step" id="step3">
    <div class="hl-card">
      <div class="hl-card-header">Langkah 3 — Konfirmasi Mapping AI</div>
      <div class="hl-card-body">

        <!-- AI result summary -->
        <div id="mappingMeta" style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px;"></div>

        <!-- Missing required warning -->
        <div id="missingWarning" style="display:none;background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:12.5px;color:#DC2626;"></div>

        <!-- Mapping table -->
        <div style="overflow-x:auto;">
          <table class="mapping-table">
            <thead>
              <tr>
                <th>Kolom di File</th>
                <th>→ Target Field</th>
                <th>Aksi</th>
                <th>Confidence</th>
                <th>Sample Data</th>
              </tr>
            </thead>
            <tbody id="mappingTbody"></tbody>
          </table>
        </div>

        <!-- Preview 3 baris -->
        <div id="previewSection" style="margin-top:20px;"></div>

        <div style="margin-top:20px;display:flex;gap:10px;justify-content:space-between;flex-wrap:wrap;align-items:center;">
          <button class="hl-btn hl-btn-outline" id="rerunAiBtn" onclick="rerunAiMapping()" title="Paksa AI analisa ulang, abaikan cache mapping sebelumnya">
            🔄 Run AI Lagi
          </button>
          <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button class="hl-btn hl-btn-outline" onclick="goStep(2)">← Upload Ulang</button>
            <button class="hl-btn hl-btn-primary" id="importBtn" onclick="doImport()">
              ▶ Mulai Import
            </button>
          </div>
        </div>
      </div>
    </div>
  </div><!-- /#step3 -->

  <!-- ════════════════════════════════════════
       STEP 4: Hasil Import
       ════════════════════════════════════════ -->
  <div class="wizard-step" id="step4">
    <div class="hl-card">
      <div class="hl-card-header" id="step4Header">✅ Import Selesai</div>
      <div class="hl-card-body">

        <!-- Stats bar -->
        <div class="result-bar" id="resultBar"></div>

        <!-- Error list -->
        <div id="errorSection"></div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <button class="hl-btn hl-btn-outline" id="downloadErrorBtn" style="display:none;"
                  onclick="downloadErrors()">⬇ Download Laporan Error</button>
          <button class="hl-btn hl-btn-primary" onclick="resetWizard()">✓ Import Lagi / Selesai</button>
        </div>
      </div>
    </div>
  </div><!-- /#step4 -->

</div><!-- /.import-wizard -->

<?php renderToast(); ?>

<script>
// ────────────────────────────────────────────────────
// State
// ────────────────────────────────────────────────────
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
let selectedEntity = '';
let currentStep    = 1;
let selectedFile   = null;
let currentJobId   = null;
let currentMapping = null;
let uploadedSample = [];
let uploadedHeaders= [];

const ENTITY_NAMES = {
    layanan: 'Layanan', pelanggan: 'Pelanggan', karyawan: 'Karyawan',
    transaksi: 'Transaksi', poin_pelanggan: 'Poin Pelanggan',
};

// Daftar field target per entity — HARUS MATCH dengan
// core/AIMigrationMapper.php::SCHEMAS supaya AI bisa map ke sini DAN
// user bisa pilih manual via dropdown.
const TARGET_FIELDS = {
    layanan:        ['nama','harga','satuan','kategori','keterangan'],
    pelanggan:      ['nama','telepon','alamat','tipe_bayar','catatan'],
    karyawan:       ['nama','telepon','role','gaji_pokok','tgl_masuk'],
    transaksi: [
        // Identitas
        'no_order','nama_pelanggan','telepon',
        // Tanggal
        'tanggal','estimasi_selesai','tgl_selesai',
        // Nominal
        'subtotal','diskon','biaya_tambahan','total','dp',
        // Status & metode
        'status_bayar','status_proses','tipe_order','metode_bayar','catatan',
        // Detail item (per baris layanan)
        'nama_layanan','jumlah_item','satuan_item','subtotal_item',
    ],
    poin_pelanggan: ['telepon','nama_pelanggan','saldo_poin'],
};

// ────────────────────────────────────────────────────
// Step navigation
// ────────────────────────────────────────────────────
function goStep(n) {
    document.querySelectorAll('.wizard-step').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.step-item').forEach(el => {
        el.classList.remove('active');
        if (parseInt(el.id.replace('stepInd','')) < n) el.classList.add('done');
        else el.classList.remove('done');
    });
    document.getElementById('step' + n).classList.add('active');
    document.getElementById('stepInd' + n).classList.add('active');
    currentStep = n;
    window.scrollTo(0, 0);
}

// ────────────────────────────────────────────────────
// Step 1 — Entity selection
// ────────────────────────────────────────────────────
function selectEntity(key) {
    selectedEntity = key;
    document.querySelectorAll('.entity-card').forEach(el => el.classList.remove('selected'));
    document.getElementById('ec_' + key)?.classList.add('selected');
    document.getElementById('templateSection').style.display = '';
    document.getElementById('templateLink').href = `/api/migration_template.php?entity=${key}`;
    document.getElementById('templateLink').textContent = `⬇ Download Template ${ENTITY_NAMES[key]}`;
}

// ────────────────────────────────────────────────────
// Step 2 — File upload
// ────────────────────────────────────────────────────
function handleFileSelect(file) {
    if (!file) return;
    selectedFile = file;
    const ext = file.name.split('.').pop().toLowerCase();
    const maxMB = 10;
    if (!['csv','xlsx','xls'].includes(ext)) {
        showToast('Format tidak didukung. Gunakan CSV atau Excel.', 'error');
        return;
    }
    if (file.size > maxMB * 1024 * 1024) {
        showToast('File terlalu besar. Maks 10 MB.', 'error');
        return;
    }
    document.getElementById('uzIcon').textContent = '📄';
    document.getElementById('uzText').textContent = file.name;
    document.getElementById('uzSub').textContent  = (file.size / 1024).toFixed(1) + ' KB · ' + ext.toUpperCase();
    document.getElementById('uploadInfo').style.display = '';
    document.getElementById('uploadInfo').innerHTML =
        `<span style="color:#374151;">File dipilih: <strong>${esc(file.name)}</strong></span>`;
    const btn = document.getElementById('uploadBtn');
    btn.disabled = false; btn.style.opacity='1'; btn.style.cursor='pointer';
}

// Drag & drop
const zone = document.getElementById('uploadZone');
zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag-over'); });
zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
zone.addEventListener('drop', e => {
    e.preventDefault(); zone.classList.remove('drag-over');
    const f = e.dataTransfer.files[0];
    if (f) { document.getElementById('fileInput').files = e.dataTransfer.files; handleFileSelect(f); }
});

async function doUpload() {
    if (!selectedFile || !selectedEntity) return;

    const btn = document.getElementById('uploadBtn');
    const txt = document.getElementById('uploadBtnText');
    btn.disabled = true;
    txt.innerHTML = '<span class="spinner"></span> Mengupload & parsing...';

    const fd = new FormData();
    fd.append('import_file', selectedFile);
    fd.append('entity_type', selectedEntity);
    fd.append('_csrf', CSRF);

    try {
        const resp = await fetch('?action=upload', { method:'POST', body:fd });
        const d    = await resp.json();

        if (d.error) { showToast(d.error, 'error'); btn.disabled=false; txt.textContent='📤 Upload & Analisa AI'; return; }

        currentJobId     = d.job_id;
        uploadedHeaders  = d.headers;
        uploadedSample   = d.sample;

        goStep(3);
        await checkCacheThenMap();

    } catch(e) {
        showToast('Upload gagal: ' + e.message, 'error');
        btn.disabled=false; txt.textContent='📤 Upload & Analisa AI';
    }
}

// Cek cache dulu — kalau hit, mapping gratis (auto-run). Kalau miss → format
// baru, prompt user untuk approve pemakaian AI (1.000 coin).
async function checkCacheThenMap() {
    const tbody = document.getElementById('mappingTbody');
    tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:32px;color:#6B7280;">
        <span class="spinner"></span>&nbsp; Mengecek format file...
    </td></tr>`;
    document.getElementById('importBtn').disabled = true;

    const fd = new FormData();
    fd.append('job_id', currentJobId);
    fd.append('_csrf', CSRF);

    try {
        const resp = await fetch('?action=check_cache', { method:'POST', body:fd });
        const d    = await resp.json();
        if (d.error) {
            tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:32px;color:#DC2626;">${esc(d.error)}</td></tr>`;
            return;
        }

        if (d.cached) {
            // Format pernah di-map → gratis, jalan langsung
            await doAiMapping(false);
            return;
        }

        // Format baru → minta approval pakai AI
        showAiCostPrompt(d.coin_cost, d.coin_balance, d.can_afford, /*isRerun*/false);

    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:32px;color:#DC2626;">Gagal cek format: ${esc(e.message)}</td></tr>`;
    }
}

// Tampilkan modal konfirmasi pemakaian AI + coin.
function showAiCostPrompt(cost, balance, canAfford, isRerun) {
    const fmt = n => Number(n).toLocaleString('id-ID');
    const title = isRerun ? 'Run AI Ulang?' : 'Format Baru Terdeteksi';
    const intro = isRerun
        ? 'AI akan dipanggil ulang untuk menganalisa file ini (cache sebelumnya diabaikan).'
        : 'File ini punya format yang belum pernah di-import. AI Claude akan menganalisa kolom & mapping otomatis ke schema LaMaSy.';

    const tbody = document.getElementById('mappingTbody');
    tbody.innerHTML = `
      <tr><td colspan="5" style="padding:24px;">
        <div style="max-width:520px;margin:0 auto;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:12px;padding:24px;">
          <h3 style="margin:0 0 8px;font-size:16px;color:#111827;">🤖 ${title}</h3>
          <p style="margin:0 0 16px;font-size:13px;color:#4B5563;line-height:1.5;">${intro}</p>

          <div style="background:#fff;border:1px solid #E5E7EB;border-radius:8px;padding:14px 16px;margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;font-size:13px;">
              <span style="color:#6B7280;">Biaya AI Mapping</span>
              <strong style="color:#0F7B6C;">${fmt(cost)} coin</strong>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;font-size:13px;">
              <span style="color:#6B7280;">Saldo coin kamu</span>
              <strong style="color:${canAfford ? '#111827' : '#DC2626'};">${fmt(balance)} coin</strong>
            </div>
          </div>

          <div style="background:#FEFCE8;border:1px solid #FDE68A;border-radius:6px;padding:10px 12px;margin-bottom:16px;font-size:12px;color:#854D0E;line-height:1.5;">
            ℹ️ Coin hanya dipotong kalau mapping berhasil (semua field wajib terpetakan). Kalau AI gagal, coin tidak terpotong.<br>
            Format yang sama tidak akan dicharge lagi di import berikutnya (auto-cache).
          </div>

          <div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;">
            <button class="hl-btn hl-btn-outline" onclick="goStep(2)">← Batal</button>
            <button class="hl-btn hl-btn-primary" onclick="confirmAiRun(${isRerun ? 'true' : 'false'})" ${canAfford ? '' : 'disabled title="Coin tidak cukup"'}>
              ${canAfford ? `▶ Lanjut & Pakai ${fmt(cost)} Coin` : '⚠️ Coin Tidak Cukup'}
            </button>
          </div>
          ${!canAfford ? `<div style="text-align:right;margin-top:8px;"><a href="/billing" style="color:#0F7B6C;font-size:12px;">Topup Coin →</a></div>` : ''}
        </div>
      </td></tr>`;
}

async function confirmAiRun(isRerun) {
    await doAiMapping(isRerun);
}

// ────────────────────────────────────────────────────
// Step 3 — AI Mapping
// ────────────────────────────────────────────────────
async function doAiMapping(ignoreCache = false) {
    const tbody = document.getElementById('mappingTbody');
    tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:32px;color:#6B7280;">
        <span class="spinner"></span>&nbsp; ${ignoreCache ? 'AI menganalisa ulang (paksa, abaikan cache)...' : 'AI sedang menganalisa file Anda...'}
    </td></tr>`;
    document.getElementById('mappingMeta').innerHTML = '';
    document.getElementById('missingWarning').style.display = 'none';
    document.getElementById('previewSection').innerHTML = '';
    document.getElementById('importBtn').disabled = true;
    const rerunBtn = document.getElementById('rerunAiBtn');
    if (rerunBtn) rerunBtn.disabled = true;

    const fd = new FormData();
    fd.append('job_id', currentJobId);
    fd.append('_csrf', CSRF);
    if (ignoreCache) fd.append('ignore_cache', '1');

    try {
        const resp = await fetch('?action=ai_map', { method:'POST', body:fd });
        const d    = await resp.json();

        if (d.error) {
            tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:32px;color:#DC2626;">
                ❌ ${esc(d.error)}
                ${d.coin_required ? `<br><a href="/billing" style="color:#0F7B6C;font-size:12px;">Topup Coin →</a>` : ''}
            </td></tr>`;
            return;
        }

        currentMapping = d;
        renderMapping(d);
        document.getElementById('importBtn').disabled = false;

    } catch(e) {
        tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:32px;color:#DC2626;">Gagal: ${esc(e.message)}</td></tr>`;
    } finally {
        if (rerunBtn) rerunBtn.disabled = false;
    }
}

async function rerunAiMapping() {
    // Ambil harga & saldo terkini supaya modal akurat
    const fd = new FormData();
    fd.append('job_id', currentJobId);
    fd.append('_csrf', CSRF);
    try {
        const resp = await fetch('?action=check_cache', { method:'POST', body:fd });
        const d    = await resp.json();
        if (d.error) { showToast(d.error, 'error'); return; }
        showAiCostPrompt(d.coin_cost, d.coin_balance, d.can_afford, /*isRerun*/true);
    } catch (e) {
        showToast('Gagal cek saldo: ' + e.message, 'error');
    }
}

function renderMapping(d) {
    // Meta info
    const conf     = (d.overall_confidence * 100).toFixed(0);
    const srcLabel = { smartlink:'Smartlink', ilaundy:'iLaundry', excel:'Excel', unknown:'Tidak diketahui' };
    const confClass= conf >= 85 ? 'conf-high' : conf >= 60 ? 'conf-medium' : 'conf-low';
    document.getElementById('mappingMeta').innerHTML = `
        <div style="background:#F7F8FC;border:1px solid #E5E9F2;border-radius:8px;padding:10px 16px;font-size:12.5px;color:#374151;">
            🤖 <strong>Sumber terdeteksi:</strong> ${esc(srcLabel[d.source_system_detected]||d.source_system_detected||'—')}
        </div>
        <div style="background:#F7F8FC;border:1px solid #E5E9F2;border-radius:8px;padding:10px 16px;font-size:12.5px;color:#374151;">
            📊 <strong>Confidence:</strong>
            <span class="conf-pill ${confClass}">${conf}%</span>
        </div>
        ${d.from_cache ? `<div style="background:#ECFDF5;border:1px solid #6EE7B7;border-radius:8px;padding:10px 16px;font-size:12.5px;color:#065F46;">
            ✓ Mapping dari cache — <strong>GRATIS</strong> (format sudah dikenal)
        </div>` : `<div style="background:#FFFBEB;border:1px solid #FDE68A;border-radius:8px;padding:10px 16px;font-size:12.5px;color:#92400E;">
            🪙 1.000 coin digunakan untuk AI mapping
        </div>`}
    `;

    // Missing required warning
    if (d.missing_required && d.missing_required.length > 0) {
        const w = document.getElementById('missingWarning');
        w.style.display = '';
        w.innerHTML = `⚠️ Field wajib yang belum terpetakan: <strong>${d.missing_required.join(', ')}</strong>. Silakan mapping manual di bawah.`;
    }

    // Mapping table
    const fields = TARGET_FIELDS[selectedEntity] || [];
    const tbody  = document.getElementById('mappingTbody');
    const mapDef = d.mapping || {};

    tbody.innerHTML = Object.entries(mapDef).map(([srcCol, info]) => {
        const target = info.target_field || '';
        const action = info.action || 'skip';
        const cf     = parseFloat(info.confidence || 0);
        const cfPct  = (cf * 100).toFixed(0);
        const cfClass= cf >= 0.85 ? 'conf-high' : cf >= 0.6 ? 'conf-medium' : action === 'skip' ? 'conf-skip' : 'conf-low';
        const cfText = action === 'skip' ? '⊘ Skip' : `✓ ${cfPct}%`;

        // Sample data untuk kolom ini
        const sample = uploadedSample.slice(0,2).map(r => {
            const v = r[srcCol] ?? Object.values(r).find((_,i) => Object.keys(r)[i] === srcCol) ?? '—';
            return `<span style="color:#9CA3AF;">${esc(String(v||'').substring(0,30))}</span>`;
        }).join(' / ');

        // Target field dropdown (manual adjust)
        const opts = ['', ...fields].map(f =>
            `<option value="${f}" ${f===target?'selected':''}>${f || '(skip)'}</option>`
        ).join('');

        return `<tr>
            <td style="font-family:var(--mono,monospace);font-size:12px;color:#1B2D5A;">${esc(srcCol)}</td>
            <td>
                <select class="mapping-select" data-src="${esc(srcCol)}"
                        onchange="updateManualMapping('${esc(srcCol)}',this.value)">
                    ${opts}
                </select>
            </td>
            <td style="font-size:11.5px;color:#9CA3AF;">${esc(action)}</td>
            <td><span class="conf-pill ${cfClass}">${cfText}</span></td>
            <td style="font-size:11.5px;">${sample}</td>
        </tr>`;
    }).join('');

    // Preview 3 baris
    if (uploadedSample.length > 0) {
        const previewHeaders = Object.entries(mapDef)
            .filter(([,v]) => v.target_field && v.action !== 'skip')
            .map(([src, v]) => ({ src, target: v.target_field }));

        let previewHtml = `<div style="font-size:12px;font-weight:700;color:#9CA3AF;margin-bottom:8px;text-transform:uppercase;letter-spacing:.05em;">Preview 3 baris pertama</div>
        <div style="overflow-x:auto;"><table class="preview-table">
            <thead><tr>${previewHeaders.map(h => `<th>${esc(h.target)}</th>`).join('')}</tr></thead>
            <tbody>`;
        uploadedSample.slice(0,3).forEach(row => {
            previewHtml += '<tr>' + previewHeaders.map(h =>
                `<td>${esc(String(row[h.src]||'').substring(0,40))}</td>`
            ).join('') + '</tr>';
        });
        previewHtml += '</tbody></table></div>';
        document.getElementById('previewSection').innerHTML = previewHtml;
    }
}

let manualMappingAdjusts = {};
function updateManualMapping(srcCol, targetField) {
    manualMappingAdjusts[srcCol] = targetField;
    // Update currentMapping
    if (currentMapping?.mapping?.[srcCol]) {
        currentMapping.mapping[srcCol].target_field = targetField;
        currentMapping.mapping[srcCol].action = targetField ? 'map' : 'skip';
    }
}

// ────────────────────────────────────────────────────
// Step 4 — Run import
// ────────────────────────────────────────────────────
async function doImport() {
    const btn = document.getElementById('importBtn');
    btn.disabled = true;
    btn.textContent = '⏳ Mengimport...';

    // Build custom mapping dari dropdown
    const customMap = {};
    document.querySelectorAll('.mapping-select').forEach(sel => {
        const src    = sel.dataset.src;
        const target = sel.value;
        const orig   = currentMapping?.mapping?.[src] || {};
        customMap[src] = {
            target_field: target || null,
            action: target ? (orig.action || 'map') : 'skip',
            transform_note: orig.transform_note || '',
            confidence: orig.confidence || 0.8,
        };
    });

    const fd = new FormData();
    fd.append('job_id', currentJobId);
    fd.append('custom_mapping', JSON.stringify(customMap));
    fd.append('_csrf', CSRF);

    try {
        const resp = await fetch('?action=confirm_and_import', { method:'POST', body:fd });
        const d    = await resp.json();

        if (d.error) { showToast(d.error, 'error'); btn.disabled=false; btn.textContent='▶ Mulai Import'; return; }

        goStep(4);
        renderResult(d);
        loadHistory();

    } catch(e) {
        showToast('Import gagal: ' + e.message, 'error');
        btn.disabled=false; btn.textContent='▶ Mulai Import';
    }
}

function renderResult(d) {
    const total = d.success + d.failed + d.skipped;

    // Header
    const hasErr = d.failed > 0;
    document.getElementById('step4Header').textContent = hasErr
        ? (d.success > 0 ? '⚠️ Import Sebagian Berhasil' : '❌ Import Gagal')
        : '✅ Import Selesai';

    // Stats
    document.getElementById('resultBar').innerHTML = `
        <div class="result-stat">
            <div class="rs-num" style="color:#059669;">${d.success}</div>
            <div class="rs-label">✓ Berhasil</div>
        </div>
        <div class="result-stat">
            <div class="rs-num" style="color:#DC2626;">${d.failed}</div>
            <div class="rs-label">✗ Gagal</div>
        </div>
        <div class="result-stat">
            <div class="rs-num" style="color:#6B7280;">${d.skipped}</div>
            <div class="rs-label">⊘ Skip</div>
        </div>
        <div class="result-stat">
            <div class="rs-num" style="color:#1B2D5A;">${total}</div>
            <div class="rs-label">Total Baris</div>
        </div>
    `;

    // Errors
    const errSec = document.getElementById('errorSection');
    const dlBtn  = document.getElementById('downloadErrorBtn');

    if (d.errors && d.errors.length > 0) {
        dlBtn.style.display = '';
        errSec.innerHTML = `
            <div style="font-size:13px;font-weight:700;margin-bottom:10px;color:#DC2626;">
                Baris yang gagal (${d.failed > d.errors.length ? d.failed + ' total, ' : ''}${d.errors.length} ditampilkan):
            </div>
            ${d.errors.slice(0,20).map(e => `
                <div class="error-row">
                    <div class="er-baris">Baris ${e.baris||'?'}</div>
                    <div class="er-msg">${esc(e.error||'Error tidak diketahui')}</div>
                </div>
            `).join('')}
            ${d.errors.length > 20 ? `<div style="font-size:12px;color:#9CA3AF;margin-top:8px;">
                … dan ${d.errors.length-20} baris lainnya. Download laporan untuk detil lengkap.
            </div>` : ''}
        `;
    } else {
        dlBtn.style.display = 'none';
        errSec.innerHTML = '';
    }
}

function downloadErrors() {
    if (!currentJobId) return;
    window.location.href = `?action=error_report&job_id=${currentJobId}`;
}

// ────────────────────────────────────────────────────
// History
// ────────────────────────────────────────────────────
async function loadHistory() {
    const body = document.getElementById('historyBody');
    if (!body) return;
    try {
        const resp = await fetch('?action=history');
        const rows = await resp.json();
        if (!rows.length) {
            body.innerHTML = '<div style="color:#9CA3AF;font-size:13px;">Belum ada riwayat import.</div>';
            return;
        }
        const STATUS_ICON = { completed:'✅', partial:'⚠️', failed:'❌', importing:'⏳', mapped:'🔍', uploaded:'📁', ai_mapping:'🤖' };
        body.innerHTML = rows.map(r => `
            <div class="history-row">
                <span style="font-size:16px;">${STATUS_ICON[r.status]||'?'}</span>
                <span style="flex:1;font-weight:600;color:#1B2D5A;">${esc(ENTITY_NAMES[r.entity_type]||r.entity_type)}</span>
                <span style="color:#9CA3AF;font-size:11.5px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(r.file_name)}">${esc(r.file_name)}</span>
                <span style="font-family:var(--mono,monospace);font-size:12px;color:#6B7280;">${r.success_rows}/${r.total_rows}</span>
                <span style="font-size:11px;color:#9CA3AF;">${fmtDate(r.created_at)}</span>
                ${r.status==='failed'||r.status==='partial' ? `<a href="?action=error_report&job_id=${r.id}" style="font-size:11px;color:#DC2626;text-decoration:none;">⬇ Error</a>` : ''}
            </div>
        `).join('');
    } catch(e) {
        body.innerHTML = '<div style="color:#DC2626;font-size:13px;">Gagal memuat riwayat.</div>';
    }
}

function resetWizard() {
    selectedEntity = ''; selectedFile = null; currentJobId = null; currentMapping = null;
    uploadedSample = []; uploadedHeaders = [];
    document.querySelectorAll('.entity-card').forEach(el => el.classList.remove('selected'));
    document.getElementById('templateSection').style.display = 'none';
    document.getElementById('uploadZone').querySelector('.uz-text').textContent = 'Klik di sini atau drag & drop file';
    document.getElementById('uploadZone').querySelector('.uz-sub').textContent = 'CSV, XLSX, XLS — maks. 10 MB';
    document.getElementById('uploadInfo').style.display = 'none';
    document.getElementById('fileInput').value = '';
    const btn = document.getElementById('uploadBtn');
    btn.disabled = true; btn.style.opacity='.5'; btn.style.cursor='not-allowed';
    btn.querySelector('#uploadBtnText').textContent = '📤 Upload & Analisa AI';
    manualMappingAdjusts = {};
    goStep(1);
    loadHistory();
}

// ────────────────────────────────────────────────────
// Utilities
// ────────────────────────────────────────────────────
function esc(str) {
    return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function fmtDate(str) { return str ? str.substring(0,16).replace('T',' ') : '—'; }
function showToast(msg, type='success') {
    const t = document.getElementById('toast');
    if (!t) { alert(msg); return; }
    t.textContent = msg; t.className = 'hl-toast hl-toast-' + type + ' show';
    setTimeout(() => t.className = 'hl-toast', 3500);
}

// ────────────────────────────────────────────────────
// Init
// ────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  loadHistory();
  // Preselect entity dari URL (?entity=pelanggan) — entry point import (Komponen 3)
  var pe = new URLSearchParams(location.search).get('entity');
  if (pe && document.getElementById('ec_' + pe)) { selectEntity(pe); goStep(2); }
});
</script>
</body>
</html>

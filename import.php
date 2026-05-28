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

date_default_timezone_set('Asia/Jakarta');

$user = currentUser();
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
            echo json_encode(['error' => 'File tidak bisa dibaca: ' . $e->getMessage()]); exit;
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

    // ── AI Mapping ────────────────────────────────────
    if ($action === 'ai_map' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();

        $jobId = (int)($_POST['job_id'] ?? 0);
        $jobQ  = $db->prepare("SELECT * FROM hl_migration_jobs WHERE id=? AND tenant_id=? LIMIT 1");
        $jobQ->execute([$jobId, $tid]);
        $job = $jobQ->fetch(PDO::FETCH_ASSOC);

        if (!$job) { echo json_encode(['error' => 'Job tidak ditemukan.']); exit; }

        $headers    = json_decode($job['raw_headers'], true) ?: [];
        $sampleRows = []; // Baca ulang dari file untuk sample
        try {
            $all        = MigrationImporter::parseFile($job['file_path'], $job['file_type']);
            $sampleRows = array_slice($all, 0, 5);
        } catch (Throwable) {}

        // Cek apakah mapping dari cache (gratis) atau perlu AI (bayar coin)
        $db->prepare("UPDATE hl_migration_jobs SET status='ai_mapping' WHERE id=?")->execute([$jobId]);

        // Panggil AI mapping (cek cache dulu di dalam mapper)
        $mapResult = AIMigrationMapper::map($job['entity_type'], $headers, $sampleRows);

        $fromCache = ($mapResult['source'] ?? '') === 'cache';

        // Deduct coin HANYA jika bukan cache dan bukan assisted
        if (!$fromCache && !$job['is_assisted']) {
            if (!CoinLedger::canAfford('ai_migration_mapping')) {
                // Kembalikan status ke uploaded
                $db->prepare("UPDATE hl_migration_jobs SET status='uploaded' WHERE id=?")->execute([$jobId]);
                echo json_encode([
                    'error' => 'Coin tidak cukup untuk AI mapping. Dibutuhkan 1.000 coin.',
                    'coin_required' => 1000,
                    'coin_balance'  => TenantResolver::coinBalance(),
                ]); exit;
            }
            CoinLedger::deduct('ai_migration_mapping', $jobId);
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
            'coin_used'  => $fromCache ? 0 : 1000,
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
/* ── Import Wizard Styles ── */
.import-wizard { max-width: 860px; margin: 0 auto; }

.step-indicator {
    display: flex; gap: 0; margin-bottom: 32px;
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 12px; overflow: hidden;
}
.step-item {
    flex: 1; padding: 14px 16px; text-align: center;
    font-size: 12.5px; font-weight: 600;
    color: rgba(255,255,255,.35);
    border-right: 1px solid rgba(255,255,255,.07);
    transition: all .2s;
    display: flex; flex-direction: column; gap: 4px;
    align-items: center;
}
.step-item:last-child { border-right: none; }
.step-item .step-num {
    width: 24px; height: 24px; border-radius: 50%;
    background: rgba(255,255,255,.1);
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 800; margin-bottom: 2px;
}
.step-item.active { color: var(--accent, #35E8D5); background: rgba(53,232,213,.06); }
.step-item.active .step-num { background: var(--accent, #35E8D5); color: #0F1C3A; }
.step-item.done { color: #6EE7B7; }
.step-item.done .step-num { background: rgba(16,185,129,.2); color: #6EE7B7; }

.wizard-step { display: none; }
.wizard-step.active { display: block; }

.entity-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 12px; margin-bottom: 20px;
}
.entity-card {
    padding: 16px; border-radius: 12px;
    border: 1.5px solid rgba(255,255,255,.08);
    background: rgba(255,255,255,.03);
    cursor: pointer; text-align: center;
    transition: all .15s;
}
.entity-card:hover { border-color: var(--accent, #35E8D5); background: rgba(53,232,213,.05); }
.entity-card.selected { border-color: var(--accent, #35E8D5); background: rgba(53,232,213,.08); }
.entity-card .ec-icon { font-size: 28px; margin-bottom: 8px; }
.entity-card .ec-name { font-size: 13px; font-weight: 700; color: rgba(255,255,255,.9); }
.entity-card .ec-coin { font-size: 11px; color: rgba(255,255,255,.4); margin-top: 4px; }

.upload-zone {
    border: 2px dashed rgba(255,255,255,.15);
    border-radius: 12px; padding: 40px 24px;
    text-align: center; cursor: pointer;
    transition: all .2s; background: rgba(255,255,255,.02);
}
.upload-zone:hover, .upload-zone.drag-over {
    border-color: var(--accent, #35E8D5);
    background: rgba(53,232,213,.05);
}
.upload-zone .uz-icon { font-size: 40px; margin-bottom: 12px; }
.upload-zone .uz-text { font-size: 14px; font-weight: 600; color: rgba(255,255,255,.7); }
.upload-zone .uz-sub  { font-size: 12px; color: rgba(255,255,255,.35); margin-top: 4px; }

.mapping-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.mapping-table th {
    padding: 10px 12px;
    background: rgba(255,255,255,.04);
    font-size: 10px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase;
    color: rgba(255,255,255,.35); border-bottom: 1px solid rgba(255,255,255,.07);
}
.mapping-table td {
    padding: 10px 12px;
    border-bottom: 1px solid rgba(255,255,255,.05);
    color: rgba(255,255,255,.8); vertical-align: middle;
}
.mapping-table tr:last-child td { border-bottom: none; }
.mapping-select {
    background: rgba(255,255,255,.06); border: 1.5px solid rgba(255,255,255,.1);
    border-radius: 7px; color: var(--white, #fff); padding: 6px 10px;
    font-family: inherit; font-size: 12.5px; outline: none;
    transition: border-color .15s; width: 100%;
}
.mapping-select:focus { border-color: var(--accent, #35E8D5); }
.mapping-select option { background: #0F1C3A; }

.conf-pill {
    display: inline-flex; align-items: center; gap: 3px;
    padding: 2px 8px; border-radius: 20px;
    font-size: 10.5px; font-weight: 600;
}
.conf-high   { background: rgba(16,185,129,.15);  color: #6EE7B7; }
.conf-medium { background: rgba(245,158,11,.15);  color: #FCD34D; }
.conf-low    { background: rgba(239,68,68,.15);   color: #FCA5A5; }
.conf-skip   { background: rgba(107,114,128,.15); color: #D1D5DB; }

.preview-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.preview-table th, .preview-table td { padding: 8px 10px; border: 1px solid rgba(255,255,255,.07); }
.preview-table th { background: rgba(255,255,255,.04); color: rgba(255,255,255,.4); font-weight: 600; }
.preview-table td { color: rgba(255,255,255,.7); }

.result-bar {
    display: flex; gap: 16px; flex-wrap: wrap;
    background: rgba(255,255,255,.03);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 12px; padding: 20px; margin-bottom: 20px;
}
.result-stat { text-align: center; flex: 1; min-width: 100px; }
.result-stat .rs-num { font-size: 32px; font-weight: 800; font-family: var(--mono, monospace); }
.result-stat .rs-label { font-size: 11.5px; color: rgba(255,255,255,.45); margin-top: 4px; }

.error-row {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 8px 12px;
    background: rgba(239,68,68,.05); border-radius: 8px;
    border: 1px solid rgba(239,68,68,.1); margin-bottom: 6px;
    font-size: 12.5px;
}
.error-row .er-baris { color: rgba(255,255,255,.4); min-width: 48px; font-family: var(--mono, monospace); }
.error-row .er-msg   { color: #FCA5A5; flex: 1; }

.hl-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 10px 18px; border-radius: 10px;
    font-family: inherit; font-size: 13.5px; font-weight: 700;
    border: none; cursor: pointer; text-decoration: none;
    transition: all .15s; white-space: nowrap;
}
.hl-btn-primary { background: linear-gradient(135deg, #35E8D5, #22C4AA); color: #0F1C3A; }
.hl-btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(53,232,213,.3); }
.hl-btn-outline { background: transparent; border: 1.5px solid rgba(255,255,255,.15); color: rgba(255,255,255,.7); }
.hl-btn-outline:hover { border-color: #35E8D5; color: #fff; }
.hl-btn-sm { padding: 6px 12px; font-size: 12px; }
.hl-btn-danger { background: rgba(239,68,68,.15); border: 1px solid rgba(239,68,68,.2); color: #FCA5A5; }

.spinner {
    display: inline-block; width: 20px; height: 20px;
    border: 2px solid rgba(255,255,255,.1);
    border-top-color: #35E8D5; border-radius: 50%;
    animation: spin .7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

.hl-card {
    background: rgba(255,255,255,.03);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 14px; overflow: hidden;
    margin-bottom: 20px;
}
.hl-card-header {
    padding: 14px 20px;
    border-bottom: 1px solid rgba(255,255,255,.07);
    font-size: 14px; font-weight: 700; color: rgba(255,255,255,.9);
    display: flex; align-items: center; justify-content: space-between;
}
.hl-card-body { padding: 20px; }

.history-row {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,.05);
    font-size: 12.5px;
}
.history-row:last-child { border-bottom: none; }

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
    <h1 style="font-size:22px;font-weight:800;">📥 Import Data</h1>
    <p style="font-size:13px;color:rgba(255,255,255,.45);margin-top:4px;">
      Import data dari Smartlink, iLaundry, Excel, atau format apapun — AI mapping otomatis.
    </p>
    <div style="margin-top:8px;background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.2);border-radius:8px;padding:10px 14px;font-size:12.5px;color:#FCD34D;display:inline-block;">
      💡 AI mapping menggunakan <strong>1.000 coin</strong>. Gratis jika format sudah dikenal dari cache.
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
          <div style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);border-radius:10px;padding:16px;margin-bottom:20px;">
            <div style="font-size:13px;font-weight:700;margin-bottom:10px;">📄 Download Template (Opsional)</div>
            <p style="font-size:12.5px;color:rgba(255,255,255,.5);margin-bottom:12px;">
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
        <div style="color:rgba(255,255,255,.3);font-size:13px;">Memuat...</div>
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
        <div style="background:rgba(53,232,213,.05);border:1px solid rgba(53,232,213,.15);border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:12.5px;color:#35E8D5;">
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

        <div id="uploadInfo" style="display:none;margin-top:12px;background:rgba(255,255,255,.04);border-radius:8px;padding:12px 16px;font-size:13px;"></div>

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
        <div id="missingWarning" style="display:none;background:rgba(239,68,68,.07);border:1px solid rgba(239,68,68,.2);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:12.5px;color:#FCA5A5;"></div>

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

        <div style="margin-top:20px;display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;">
          <button class="hl-btn hl-btn-outline" onclick="goStep(2)">← Upload Ulang</button>
          <button class="hl-btn hl-btn-primary" id="importBtn" onclick="doImport()">
            ▶ Mulai Import
          </button>
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

const TARGET_FIELDS = {
    layanan:        ['nama','harga','satuan','kategori','keterangan'],
    pelanggan:      ['nama','telepon','alamat','tipe_bayar','catatan'],
    karyawan:       ['nama','telepon','role','gaji_pokok','tgl_masuk'],
    transaksi:      ['nama_pelanggan','telepon','nama_layanan','berat_kg','total','tanggal','metode_bayar','catatan'],
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
        `<span style="color:rgba(255,255,255,.7);">File dipilih: <strong>${esc(file.name)}</strong></span>`;
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
        await doAiMapping();

    } catch(e) {
        showToast('Upload gagal: ' + e.message, 'error');
        btn.disabled=false; txt.textContent='📤 Upload & Analisa AI';
    }
}

// ────────────────────────────────────────────────────
// Step 3 — AI Mapping
// ────────────────────────────────────────────────────
async function doAiMapping() {
    const tbody = document.getElementById('mappingTbody');
    tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:32px;">
        <span class="spinner"></span>&nbsp; AI sedang menganalisa file Anda...
    </td></tr>`;
    document.getElementById('mappingMeta').innerHTML = '';
    document.getElementById('missingWarning').style.display = 'none';
    document.getElementById('previewSection').innerHTML = '';
    document.getElementById('importBtn').disabled = true;

    const fd = new FormData();
    fd.append('job_id', currentJobId);
    fd.append('_csrf', CSRF);

    try {
        const resp = await fetch('?action=ai_map', { method:'POST', body:fd });
        const d    = await resp.json();

        if (d.error) {
            tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:32px;color:#FCA5A5;">
                ❌ ${esc(d.error)}
                ${d.coin_required ? `<br><a href="/billing.php" style="color:#35E8D5;font-size:12px;">Topup Coin →</a>` : ''}
            </td></tr>`;
            return;
        }

        currentMapping = d;
        renderMapping(d);
        document.getElementById('importBtn').disabled = false;

    } catch(e) {
        tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:32px;color:#FCA5A5;">Gagal: ${esc(e.message)}</td></tr>`;
    }
}

function renderMapping(d) {
    // Meta info
    const conf     = (d.overall_confidence * 100).toFixed(0);
    const srcLabel = { smartlink:'Smartlink', ilaundy:'iLaundry', excel:'Excel', unknown:'Tidak diketahui' };
    const confClass= conf >= 85 ? 'conf-high' : conf >= 60 ? 'conf-medium' : 'conf-low';
    document.getElementById('mappingMeta').innerHTML = `
        <div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);border-radius:8px;padding:10px 16px;font-size:12.5px;">
            🤖 <strong>Sumber terdeteksi:</strong> ${esc(srcLabel[d.source_system_detected]||d.source_system_detected||'—')}
        </div>
        <div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);border-radius:8px;padding:10px 16px;font-size:12.5px;">
            📊 <strong>Confidence:</strong>
            <span class="conf-pill ${confClass}">${conf}%</span>
        </div>
        ${d.from_cache ? `<div style="background:rgba(16,185,129,.07);border:1px solid rgba(16,185,129,.2);border-radius:8px;padding:10px 16px;font-size:12.5px;color:#6EE7B7;">
            ✓ Mapping dari cache — <strong>GRATIS</strong> (format sudah dikenal)
        </div>` : `<div style="background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.2);border-radius:8px;padding:10px 16px;font-size:12.5px;color:#FCD34D;">
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
            return `<span style="color:rgba(255,255,255,.5);">${esc(String(v||'').substring(0,30))}</span>`;
        }).join(' / ');

        // Target field dropdown (manual adjust)
        const opts = ['', ...fields].map(f =>
            `<option value="${f}" ${f===target?'selected':''}>${f || '(skip)'}</option>`
        ).join('');

        return `<tr>
            <td style="font-family:var(--mono,monospace);font-size:12px;">${esc(srcCol)}</td>
            <td>
                <select class="mapping-select" data-src="${esc(srcCol)}"
                        onchange="updateManualMapping('${esc(srcCol)}',this.value)">
                    ${opts}
                </select>
            </td>
            <td style="font-size:11.5px;color:rgba(255,255,255,.5);">${esc(action)}</td>
            <td><span class="conf-pill ${cfClass}">${cfText}</span></td>
            <td style="font-size:11.5px;">${sample}</td>
        </tr>`;
    }).join('');

    // Preview 3 baris
    if (uploadedSample.length > 0) {
        const previewHeaders = Object.entries(mapDef)
            .filter(([,v]) => v.target_field && v.action !== 'skip')
            .map(([src, v]) => ({ src, target: v.target_field }));

        let previewHtml = `<div style="font-size:12px;font-weight:700;color:rgba(255,255,255,.5);margin-bottom:8px;text-transform:uppercase;letter-spacing:.05em;">Preview 3 baris pertama</div>
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
            <div class="rs-num" style="color:#6EE7B7;">${d.success}</div>
            <div class="rs-label">✓ Berhasil</div>
        </div>
        <div class="result-stat">
            <div class="rs-num" style="color:#FCA5A5;">${d.failed}</div>
            <div class="rs-label">✗ Gagal</div>
        </div>
        <div class="result-stat">
            <div class="rs-num" style="color:#D1D5DB;">${d.skipped}</div>
            <div class="rs-label">⊘ Skip</div>
        </div>
        <div class="result-stat">
            <div class="rs-num" style="color:rgba(255,255,255,.5);">${total}</div>
            <div class="rs-label">Total Baris</div>
        </div>
    `;

    // Errors
    const errSec = document.getElementById('errorSection');
    const dlBtn  = document.getElementById('downloadErrorBtn');

    if (d.errors && d.errors.length > 0) {
        dlBtn.style.display = '';
        errSec.innerHTML = `
            <div style="font-size:13px;font-weight:700;margin-bottom:10px;color:#FCA5A5;">
                Baris yang gagal (${d.failed > d.errors.length ? d.failed + ' total, ' : ''}${d.errors.length} ditampilkan):
            </div>
            ${d.errors.slice(0,20).map(e => `
                <div class="error-row">
                    <div class="er-baris">Baris ${e.baris||'?'}</div>
                    <div class="er-msg">${esc(e.error||'Error tidak diketahui')}</div>
                </div>
            `).join('')}
            ${d.errors.length > 20 ? `<div style="font-size:12px;color:rgba(255,255,255,.35);margin-top:8px;">
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
            body.innerHTML = '<div style="color:rgba(255,255,255,.3);font-size:13px;">Belum ada riwayat import.</div>';
            return;
        }
        const STATUS_ICON = { completed:'✅', partial:'⚠️', failed:'❌', importing:'⏳', mapped:'🔍', uploaded:'📁', ai_mapping:'🤖' };
        body.innerHTML = rows.map(r => `
            <div class="history-row">
                <span style="font-size:16px;">${STATUS_ICON[r.status]||'?'}</span>
                <span style="flex:1;font-weight:600;">${esc(ENTITY_NAMES[r.entity_type]||r.entity_type)}</span>
                <span style="color:rgba(255,255,255,.4);font-size:11.5px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(r.file_name)}">${esc(r.file_name)}</span>
                <span style="font-family:var(--mono,monospace);font-size:12px;color:rgba(255,255,255,.5);">${r.success_rows}/${r.total_rows}</span>
                <span style="font-size:11px;color:rgba(255,255,255,.3);">${fmtDate(r.created_at)}</span>
                ${r.status==='failed'||r.status==='partial' ? `<a href="?action=error_report&job_id=${r.id}" style="font-size:11px;color:#FCA5A5;text-decoration:none;">⬇ Error</a>` : ''}
            </div>
        `).join('');
    } catch(e) {
        body.innerHTML = '<div style="color:#FCA5A5;font-size:13px;">Gagal memuat riwayat.</div>';
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
document.addEventListener('DOMContentLoaded', () => { loadHistory(); });
</script>
</body>
</html>

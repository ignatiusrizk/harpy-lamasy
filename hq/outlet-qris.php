<?php
// ══════════════════════════════════════════════════════
// hq/outlet-qris.php — Upload QRIS image per outlet
// ══════════════════════════════════════════════════════

if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';

// hq_guard.php already sets Asia/Jakarta timezone, starts session,
// loads db.php + Database.php, defines getCsrfToken() + verifyCsrf()

$tenantId = (int)($_SESSION['tenant_id'] ?? 0);
$outletId = (int)($_GET['outlet_id'] ?? 0);

if (!$outletId) { http_response_code(400); exit('outlet_id required'); }

$db = Database::get();

// Validasi outlet milik tenant ini
$stmt = $db->prepare("SELECT id, nama_outlet, qris_image, qris_label, qris_uploaded_at
                      FROM outlets WHERE id=? AND tenant_id=?");
$stmt->execute([$outletId, $tenantId]);
$outlet = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$outlet) { http_response_code(404); exit('Outlet tidak ditemukan'); }

$msg = '';
$err = '';

// ─── POST: Delete QRIS ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verifyCsrf();

    try {
        if ($outlet['qris_image']) {
            $absPath = ROOT . $outlet['qris_image'];
            if (is_file($absPath)) @unlink($absPath);
        }
        $up = $db->prepare("UPDATE outlets SET qris_image=NULL, qris_label=NULL, qris_uploaded_at=NULL
                            WHERE id=? AND tenant_id=?");
        $up->execute([$outletId, $tenantId]);
        $msg = 'QRIS berhasil dihapus.';
        // Refresh outlet data
        $stmt->execute([$outletId, $tenantId]);
        $outlet = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $err = 'Gagal hapus: ' . $e->getMessage();
    }
}

// ─── POST: Upload QRIS ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    verifyCsrf();

    try {
        $label = trim($_POST['label'] ?? '');
        if ($label === '') throw new RuntimeException('Label QRIS wajib diisi');
        if (mb_strlen($label) > 100) throw new RuntimeException('Label max 100 karakter');

        $f = $_FILES['qris_image'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('File upload gagal atau tidak ada file');
        }

        // 1. Type check (MIME, bukan extension)
        $mime = mime_content_type($f['tmp_name']);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($allowed[$mime])) {
            throw new RuntimeException('Format harus JPG, PNG, atau WebP. Detected: ' . $mime);
        }

        // 2. Size check (max 500 KB)
        if ($f['size'] > 500 * 1024) {
            throw new RuntimeException('Ukuran max 500 KB. Anda: ' . round($f['size']/1024) . ' KB');
        }

        // 3. Dimension check (min 400×400)
        $info = @getimagesize($f['tmp_name']);
        if (!$info || $info[0] < 400 || $info[1] < 400) {
            $w = $info[0] ?? 0; $h = $info[1] ?? 0;
            throw new RuntimeException("Min 400×400 px. Anda: {$w}×{$h}");
        }

        // 4. Save dengan random filename component
        $ext = $allowed[$mime];
        $filename = sprintf('outlet_%d_%d_%s.%s', $outletId, time(), bin2hex(random_bytes(3)), $ext);
        $dir = ROOT . '/assets/outlet-qris';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $absPath = "$dir/$filename";
        if (!move_uploaded_file($f['tmp_name'], $absPath)) {
            throw new RuntimeException('Gagal save file. Cek permission /assets/outlet-qris/');
        }

        // 5. Delete old image (kalau replace)
        if ($outlet['qris_image']) {
            $oldAbs = ROOT . $outlet['qris_image'];
            if (is_file($oldAbs)) @unlink($oldAbs);
        }

        // 6. UPDATE DB (tenant_id scope check)
        $relPath = "/assets/outlet-qris/$filename";
        $up = $db->prepare("UPDATE outlets SET qris_image=?, qris_label=?, qris_uploaded_at=NOW()
                            WHERE id=? AND tenant_id=?");
        $up->execute([$relPath, $label, $outletId, $tenantId]);

        $msg = 'QRIS berhasil di-upload.';
        // Refresh outlet
        $stmt->execute([$outletId, $tenantId]);
        $outlet = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$activePage = 'hq-outlet';
$pageTitle  = 'Setup QRIS — ' . htmlspecialchars($outlet['nama_outlet']);
?>
<?php require_once __DIR__ . '/_layout_open.php'; ?>

<div class="hq-page" style="max-width:720px;margin:0 auto;padding:20px">
  <div style="margin-bottom:16px">
    <a href="/hq/outlet" style="color:#6b7280;font-size:13px;text-decoration:none">&larr; Kembali ke daftar outlet</a>
  </div>

  <h1 style="font-size:24px;margin:0 0 8px 0">Setup QRIS</h1>
  <p style="color:#6b7280;margin:0 0 24px 0">
    Outlet: <strong><?= htmlspecialchars($outlet['nama_outlet']) ?></strong>
  </p>

  <?php if ($msg): ?>
    <div style="background:#dcfce7;border:1px solid #86efac;color:#166534;padding:12px;border-radius:8px;margin-bottom:16px">
      &#10003; <?= htmlspecialchars($msg) ?>
    </div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:12px;border-radius:8px;margin-bottom:16px">
      &#10005; <?= htmlspecialchars($err) ?>
    </div>
  <?php endif; ?>

  <?php if ($outlet['qris_image']): ?>
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin-bottom:20px">
      <h3 style="margin:0 0 12px 0;font-size:16px">QRIS Saat Ini</h3>
      <img src="<?= htmlspecialchars($outlet['qris_image']) ?>"
           alt="QRIS"
           style="max-width:280px;width:100%;border:1px solid #e5e7eb;border-radius:8px;display:block;margin-bottom:12px">
      <div style="color:#374151;font-weight:600;margin-bottom:4px">
        <?= htmlspecialchars($outlet['qris_label']) ?>
      </div>
      <div style="color:#9ca3af;font-size:12px;margin-bottom:16px">
        Di-upload: <?= htmlspecialchars($outlet['qris_uploaded_at']) ?>
      </div>
      <form method="POST" onsubmit="return confirm('Yakin hapus QRIS ini? Customer tidak bisa bayar via QRIS setelah dihapus.')">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(getCsrfToken()) ?>">
        <input type="hidden" name="action" value="delete">
        <button type="submit" style="background:#ef4444;color:#fff;border:0;padding:10px 16px;border-radius:8px;cursor:pointer;font-weight:600">
          Hapus QRIS
        </button>
      </form>
    </div>
  <?php endif; ?>

  <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px">
    <h3 style="margin:0 0 16px 0;font-size:16px">
      <?= $outlet['qris_image'] ? 'Ganti QRIS' : 'Upload QRIS Baru' ?>
    </h3>

    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(getCsrfToken()) ?>">
      <input type="hidden" name="action" value="upload">

      <div style="margin-bottom:16px">
        <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151">Label QRIS *</label>
        <input type="text" name="label" required maxlength="100"
               value="<?= htmlspecialchars($outlet['qris_label'] ?? '') ?>"
               placeholder="BCA - PT Rizky Laundry"
               style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;box-sizing:border-box">
        <div style="font-size:12px;color:#9ca3af;margin-top:4px">
          Tampil di POS sebagai info ke customer
        </div>
      </div>

      <div style="margin-bottom:16px">
        <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151">Upload Gambar QRIS *</label>
        <input type="file" name="qris_image" accept="image/jpeg,image/png,image/webp" required
               style="width:100%;padding:10px;border:1px dashed #d1d5db;border-radius:8px;background:#f9fafb">
      </div>

      <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:12px;margin-bottom:16px;font-size:13px;color:#0c4a6e">
        <strong>Spesifikasi:</strong>
        <ul style="margin:6px 0 0 18px;padding:0">
          <li>Format: JPG, PNG, WebP</li>
          <li>Min 400 &times; 400 px (square)</li>
          <li>Maks 500 KB</li>
          <li>Gambar QRIS dari banking app harus jelas + fokus</li>
        </ul>
      </div>

      <button type="submit" style="background:#0d9488;color:#fff;border:0;padding:12px 20px;border-radius:8px;cursor:pointer;font-weight:600;font-size:14px">
        Simpan QRIS
      </button>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/_layout_close.php'; ?>

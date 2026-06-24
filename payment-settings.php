<?php
// ══════════════════════════════════════════════════════
// payment-settings.php — Pembayaran QRIS per outlet
//
// Outlet-level page: kasir/owner di outlet view bisa upload
// QRIS image untuk outlet ini (current session outlet).
// ══════════════════════════════════════════════════════

$activePage = 'payment-settings';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once __DIR__ . '/components.php';
$user = currentUser();
requirePermission('settings.roles');

$tid = TenantResolver::id();
$oid = TenantResolver::outletId();
$db  = Database::get();

// Validasi outlet milik tenant ini + load current QRIS data
$stmt = $db->prepare("SELECT id, nama_outlet, qris_image, qris_label, qris_uploaded_at
                      FROM outlets WHERE id=? AND tenant_id=?");
$stmt->execute([$oid, $tid]);
$outlet = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$outlet) { http_response_code(404); exit('Outlet tidak ditemukan'); }

$msg = '';
$err = '';

// ─── AJAX: Method list (JSON) ──────────────────────────
if (($_GET['action'] ?? '') === 'method_list') {
    header('Content-Type: application/json');
    $stmt = $db->prepare("
        SELECT id, code, label, emoji, is_builtin, is_active, sort_order
        FROM hl_payment_methods
        WHERE outlet_id=? AND tenant_id=?
        ORDER BY sort_order, id
    ");
    $stmt->execute([$oid, $tid]);
    echo json_encode(['ok' => true, 'methods' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ─── Helper: slugify code dari label ───────────────────
function slugifyMethodCode(string $label): string {
    $s = strtolower(trim($label));
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    $s = trim($s, '_');
    return substr($s, 0, 30) ?: 'method_' . dechex(random_int(0, 0xFFFFFF));
}

// ─── POST: Method add ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'method_add') {
    header('Content-Type: application/json');
    try {
        verifyCsrf();
        $label = substr(trim(strip_tags($_POST['label'] ?? '')), 0, 50);
        $emoji = substr(trim($_POST['emoji'] ?? '💳'), 0, 8);
        if ($label === '') throw new RuntimeException('Label wajib diisi');

        // Slug + collision resolve
        $base = slugifyMethodCode($label);
        $code = $base;
        $i = 2;
        $check = $db->prepare("SELECT 1 FROM hl_payment_methods
                               WHERE tenant_id=? AND outlet_id=? AND code=?");
        while (true) {
            $check->execute([$tid, $oid, $code]);
            if (!$check->fetchColumn()) break;
            $code = $base . '_' . $i++;
            if ($i > 50) throw new RuntimeException('Tidak bisa generate code unik');
        }

        // Compute next sort_order
        $stmt2 = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM hl_payment_methods
                              WHERE tenant_id=? AND outlet_id=?");
        $stmt2->execute([$tid, $oid]);
        $nextSort = (int)$stmt2->fetchColumn();

        $ins = $db->prepare("INSERT INTO hl_payment_methods
            (tenant_id, outlet_id, code, label, emoji, is_builtin, is_active, sort_order)
            VALUES (?, ?, ?, ?, ?, 0, 1, ?)");
        $ins->execute([$tid, $oid, $code, $label, $emoji, $nextSort]);

        echo json_encode(['ok' => true, 'id' => $db->lastInsertId(), 'code' => $code]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ─── POST: Method edit (label + emoji only) ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'method_edit') {
    header('Content-Type: application/json');
    try {
        verifyCsrf();
        $id    = (int)($_POST['id'] ?? 0);
        $label = substr(trim(strip_tags($_POST['label'] ?? '')), 0, 50);
        $emoji = substr(trim($_POST['emoji'] ?? '💳'), 0, 8);
        if (!$id) throw new RuntimeException('ID method invalid');
        if ($label === '') throw new RuntimeException('Label wajib diisi');

        // Built-in row tidak boleh edit (server-side enforce)
        $check = $db->prepare("SELECT is_builtin FROM hl_payment_methods
                               WHERE id=? AND outlet_id=? AND tenant_id=?");
        $check->execute([$id, $oid, $tid]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Method tidak ditemukan');
        if ((int)$row['is_builtin'] === 1) {
            throw new RuntimeException('Metode bawaan tidak bisa di-edit');
        }

        $up = $db->prepare("UPDATE hl_payment_methods
                            SET label=?, emoji=?
                            WHERE id=? AND outlet_id=? AND tenant_id=? AND is_builtin=0");
        $up->execute([$label, $emoji, $id, $oid, $tid]);

        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ─── POST: Method delete (custom only) ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'method_delete') {
    header('Content-Type: application/json');
    try {
        verifyCsrf();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) throw new RuntimeException('ID method invalid');

        $check = $db->prepare("SELECT is_builtin FROM hl_payment_methods
                               WHERE id=? AND outlet_id=? AND tenant_id=?");
        $check->execute([$id, $oid, $tid]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Method tidak ditemukan');
        if ((int)$row['is_builtin'] === 1) {
            throw new RuntimeException('Metode bawaan tidak bisa di-hapus, hanya di-nonaktifkan');
        }

        $del = $db->prepare("DELETE FROM hl_payment_methods
                             WHERE id=? AND outlet_id=? AND tenant_id=? AND is_builtin=0");
        $del->execute([$id, $oid, $tid]);

        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ─── POST: Method toggle is_active ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'method_toggle') {
    header('Content-Type: application/json');
    try {
        verifyCsrf();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) throw new RuntimeException('ID method invalid');

        $up = $db->prepare("UPDATE hl_payment_methods
                            SET is_active = 1 - is_active
                            WHERE id=? AND outlet_id=? AND tenant_id=?");
        $up->execute([$id, $oid, $tid]);

        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

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
        $up->execute([$oid, $tid]);
        $msg = 'QRIS berhasil dihapus.';
        $stmt->execute([$oid, $tid]);
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

        $mime = mime_content_type($f['tmp_name']);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($allowed[$mime])) {
            throw new RuntimeException('Format harus JPG, PNG, atau WebP. Detected: ' . $mime);
        }

        if ($f['size'] > 500 * 1024) {
            throw new RuntimeException('Ukuran max 500 KB. Anda: ' . round($f['size']/1024) . ' KB');
        }

        $info = @getimagesize($f['tmp_name']);
        if (!$info || $info[0] < 400 || $info[1] < 400) {
            $w = $info[0] ?? 0; $h = $info[1] ?? 0;
            throw new RuntimeException("Min 400×400 px. Anda: {$w}×{$h}");
        }

        $ext = $allowed[$mime];
        $filename = sprintf('outlet_%d_%d_%s.%s', $oid, time(), bin2hex(random_bytes(3)), $ext);
        $dir = ROOT . '/assets/outlet-qris';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $absPath = "$dir/$filename";
        if (!move_uploaded_file($f['tmp_name'], $absPath)) {
            throw new RuntimeException('Gagal save file. Cek permission /assets/outlet-qris/');
        }

        if ($outlet['qris_image']) {
            $oldAbs = ROOT . $outlet['qris_image'];
            if (is_file($oldAbs)) @unlink($oldAbs);
        }

        $relPath = "/assets/outlet-qris/$filename";
        $up = $db->prepare("UPDATE outlets SET qris_image=?, qris_label=?, qris_uploaded_at=NOW()
                            WHERE id=? AND tenant_id=?");
        $up->execute([$relPath, $label, $oid, $tid]);

        $msg = 'QRIS berhasil di-upload.';
        $stmt->execute([$oid, $tid]);
        $outlet = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Pembayaran QRIS'); ?>
</head>
<body>
<?php renderTopbar('payment-settings'); ?>

<div class="hl-main">
  <div class="settings-tabs" style="display:flex;gap:2px;margin-bottom:18px;border-bottom:1px solid var(--off)">
    <a href="/outlet-settings" class="settings-tab" style="padding:11px 18px;border-bottom:3px solid transparent;color:var(--gray);font-weight:600;font-size:14px;text-decoration:none">🏢 Outlet & Nota</a>
    <a href="/struk" class="settings-tab" style="padding:11px 18px;border-bottom:3px solid transparent;color:var(--gray);font-weight:600;font-size:14px;text-decoration:none">🧾 Struk & Invoice</a>
    <a href="/payment-settings" class="settings-tab active" style="padding:11px 18px;border-bottom:3px solid var(--teal);color:var(--navy-d);font-weight:700;font-size:14px;text-decoration:none">💳 Pembayaran QRIS</a>
  </div>

  <div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;padding:14px 18px;margin-bottom:18px;font-size:13.5px;color:#1E40AF;line-height:1.55">
    💡 <strong>Pembayaran QRIS</strong> — upload gambar QRIS dari banking app outlet ini. Customer akan scan QR ini di POS saat pilih bayar QRIS. Settlement langsung ke rekening outlet.
  </div>

  <div style="max-width:680px">
    <p style="color:#6b7280;margin:0 0 24px 0">
      Outlet: <strong><?= htmlspecialchars($outlet['nama_outlet']) ?></strong>
    </p>

    <!-- ═══ Section: Metode Pembayaran POS ═══ -->
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin-bottom:24px">
      <h3 style="margin:0 0 6px 0;font-size:16px">💳 Metode Pembayaran POS</h3>
      <p style="color:#6b7280;font-size:13px;margin:0 0 16px 0">
        Kelola metode yang muncul di POS saat input pembayaran. Centang untuk aktifkan, uncheck untuk sembunyikan.
      </p>

      <div id="methodsList" style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px">
        <div style="color:#9ca3af;font-size:13px;padding:8px">Memuat…</div>
      </div>

      <button type="button" onclick="openMethodModal()"
              style="background:#0d9488;color:#fff;border:0;padding:10px 16px;border-radius:8px;cursor:pointer;font-weight:600;font-size:13px">
        + Tambah Metode
      </button>
    </div>

    <!-- ═══ Section: QRIS Image (existing — header polish) ═══ -->
    <h3 style="margin:0 0 12px 0;font-size:16px;color:#374151">📱 Setup Gambar QRIS</h3>

    <?php if ($msg): ?>
      <div style="background:#dcfce7;border:1px solid #86efac;color:#166534;padding:12px;border-radius:8px;margin-bottom:16px">
        ✓ <?= htmlspecialchars($msg) ?>
      </div>
    <?php endif; ?>
    <?php if ($err): ?>
      <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:12px;border-radius:8px;margin-bottom:16px">
        ✕ <?= htmlspecialchars($err) ?>
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
            🗑️ Hapus QRIS
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
          <strong>📐 Spesifikasi:</strong>
          <ul style="margin:6px 0 0 18px;padding:0">
            <li>Format: JPG, PNG, WebP</li>
            <li>Min 400 × 400 px (square)</li>
            <li>Maks 500 KB</li>
            <li>Gambar QRIS dari banking app harus jelas + fokus</li>
          </ul>
        </div>

        <button type="submit" style="background:#0d9488;color:#fff;border:0;padding:12px 20px;border-radius:8px;cursor:pointer;font-weight:600;font-size:14px">
          💾 Simpan QRIS
        </button>
      </form>
    </div>
  </div>
</div>

<!-- Method Add/Edit Modal -->
<div id="methodModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center"
     onclick="if (event.target===this) closeMethodModal()">
  <div style="background:#fff;border-radius:12px;padding:24px;max-width:420px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.3)">
    <h3 id="methodModalTitle" style="margin:0 0 16px 0;font-size:18px">Tambah Metode Pembayaran</h3>
    <input type="hidden" id="methodEditId" value="">

    <div style="margin-bottom:14px">
      <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151">Label *</label>
      <input id="methodLabel" type="text" maxlength="50" placeholder="Transfer BCA"
             style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;box-sizing:border-box">
      <div style="font-size:12px;color:#9ca3af;margin-top:4px">Max 50 karakter</div>
    </div>

    <div style="margin-bottom:14px">
      <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151">Emoji Icon</label>
      <input id="methodEmoji" type="text" maxlength="4" placeholder="💳"
             style="width:80px;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:18px;text-align:center;box-sizing:border-box">
      <div style="font-size:12px;color:#9ca3af;margin-top:4px">Default: 💳</div>
    </div>

    <div id="methodModalError" style="display:none;background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:10px;border-radius:8px;font-size:13px;margin-bottom:12px"></div>

    <div style="display:flex;gap:8px;justify-content:flex-end">
      <button type="button" onclick="closeMethodModal()"
              style="background:#fff;color:#374151;border:1px solid #d1d5db;padding:10px 16px;border-radius:8px;cursor:pointer;font-weight:600">
        Batal
      </button>
      <button type="button" onclick="saveMethod()"
              style="background:#0d9488;color:#fff;border:0;padding:10px 16px;border-radius:8px;cursor:pointer;font-weight:600">
        💾 Simpan
      </button>
    </div>
  </div>
</div>

<script>
const CSRF_TOKEN = <?= json_encode(getCsrfToken()) ?>;

async function loadMethods() {
  const r = await fetch('/payment-settings?action=method_list');
  const d = await r.json();
  const listEl = document.getElementById('methodsList');
  if (!d.ok) { listEl.innerHTML = '<div style="color:#dc2626;padding:8px">Gagal load: ' + (d.error || 'unknown') + '</div>'; return; }
  if (!d.methods.length) { listEl.innerHTML = '<div style="color:#9ca3af;padding:8px">Belum ada metode.</div>'; return; }

  listEl.innerHTML = d.methods.map(m => {
    const isBuiltin = parseInt(m.is_builtin) === 1;
    const isActive = parseInt(m.is_active) === 1;
    const safeLabel = String(m.label).replace(/[<>&"']/g, c => ({ '<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;',"'":'&#39;' })[c]);
    const safeEmoji = String(m.emoji || '💳').replace(/[<>&"']/g, c => ({ '<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;',"'":'&#39;' })[c]);

    return `
      <div style="display:flex;align-items:center;gap:12px;padding:10px 12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;${isActive ? '' : 'opacity:0.5'}">
        <input type="checkbox" ${isActive ? 'checked' : ''}
               onchange="toggleMethod(${m.id})"
               style="width:18px;height:18px;cursor:pointer">
        <span style="font-size:18px">${safeEmoji}</span>
        <span style="flex:1;font-weight:600;color:#374151">${safeLabel}</span>
        ${isBuiltin
          ? '<span style="font-size:11px;color:#9ca3af;background:#e5e7eb;padding:2px 8px;border-radius:4px">built-in</span>'
          : `<button type="button" onclick="openMethodModal(${m.id})"
                     style="background:#fff;border:1px solid #d1d5db;padding:4px 10px;border-radius:6px;cursor:pointer;font-size:12px">✏️ Edit</button>
             <button type="button" onclick="deleteMethod(${m.id}, ${JSON.stringify(safeLabel)})"
                     style="background:#fef2f2;border:1px solid #fca5a5;color:#dc2626;padding:4px 10px;border-radius:6px;cursor:pointer;font-size:12px">🗑️ Hapus</button>`
        }
      </div>
    `;
  }).join('');
}

function openMethodModal(id = null) {
  document.getElementById('methodModalError').style.display = 'none';
  document.getElementById('methodEditId').value = id || '';
  if (id) {
    // Find row data dari list (re-query loadMethods response indirectly via fetch)
    fetch('/payment-settings?action=method_list').then(r => r.json()).then(d => {
      const row = d.methods.find(m => parseInt(m.id) === id);
      if (!row) return;
      document.getElementById('methodModalTitle').textContent = 'Edit Metode Pembayaran';
      document.getElementById('methodLabel').value = row.label;
      document.getElementById('methodEmoji').value = row.emoji || '💳';
    });
  } else {
    document.getElementById('methodModalTitle').textContent = 'Tambah Metode Pembayaran';
    document.getElementById('methodLabel').value = '';
    document.getElementById('methodEmoji').value = '💳';
  }
  document.getElementById('methodModal').style.display = 'flex';
  setTimeout(() => document.getElementById('methodLabel').focus(), 50);
}

function closeMethodModal() {
  document.getElementById('methodModal').style.display = 'none';
}

async function saveMethod() {
  const errEl = document.getElementById('methodModalError');
  errEl.style.display = 'none';

  const id = document.getElementById('methodEditId').value;
  const label = document.getElementById('methodLabel').value.trim();
  const emoji = document.getElementById('methodEmoji').value.trim() || '💳';

  if (!label) { errEl.textContent = 'Label wajib diisi'; errEl.style.display = 'block'; return; }

  const fd = new FormData();
  fd.append('_csrf', CSRF_TOKEN);
  fd.append('action', id ? 'method_edit' : 'method_add');
  fd.append('label', label);
  fd.append('emoji', emoji);
  if (id) fd.append('id', id);

  const r = await fetch('/payment-settings', { method: 'POST', body: fd });
  const d = await r.json();
  if (!d.ok) { errEl.textContent = d.error || 'Gagal'; errEl.style.display = 'block'; return; }
  closeMethodModal();
  loadMethods();
}

async function deleteMethod(id, label) {
  if (!confirm('Hapus metode "' + label + '"? Transaksi historis tidak terpengaruh.')) return;

  const fd = new FormData();
  fd.append('_csrf', CSRF_TOKEN);
  fd.append('action', 'method_delete');
  fd.append('id', id);

  const r = await fetch('/payment-settings', { method: 'POST', body: fd });
  const d = await r.json();
  if (!d.ok) { alert('Gagal: ' + (d.error || 'unknown')); return; }
  loadMethods();
}

async function toggleMethod(id) {
  const fd = new FormData();
  fd.append('_csrf', CSRF_TOKEN);
  fd.append('action', 'method_toggle');
  fd.append('id', id);

  const r = await fetch('/payment-settings', { method: 'POST', body: fd });
  const d = await r.json();
  if (!d.ok) { alert('Gagal: ' + (d.error || 'unknown')); loadMethods(); return; }
  loadMethods();
}

// Initial load
loadMethods();
</script>

</body>
</html>

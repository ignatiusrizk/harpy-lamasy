<?php
// ══════════════════════════════════════════════════════
// superadmin/layanan_presets.php — Kelola preset layanan wizard onboarding
// (Data Accumulation · Komponen 2 · Opsi D)
//
// Preset yang muncul di wizard quick-setup layanan (onboarding.php).
// SA bisa tambah/edit/nonaktif/hapus + atur urutan & default-centang.
// ══════════════════════════════════════════════════════
if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';

$db    = Database::get();
$flash = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    saVerifyCsrf();
    $act = $_POST['act'] ?? '';
    try {
        if ($act === 'save') {
            $id       = (int)($_POST['id'] ?? 0);
            $nama     = mb_substr(trim(strip_tags($_POST['nama'] ?? '')), 0, 100);
            $satuan   = mb_substr(trim(strip_tags($_POST['satuan'] ?? 'kg')), 0, 30) ?: 'kg';
            $kategori = mb_substr(trim(strip_tags($_POST['kategori'] ?? 'Kiloan')), 0, 50) ?: 'Kiloan';
            $urutan   = (int)($_POST['urutan'] ?? 0);
            $defChk   = !empty($_POST['default_checked']) ? 1 : 0;
            if ($nama === '') {
                $flash = ['ok'=>false, 'msg'=>'Nama layanan wajib diisi.'];
            } elseif ($id > 0) {
                $db->prepare("UPDATE saas_layanan_presets SET nama=?, satuan=?, kategori=?, urutan=?, default_checked=? WHERE id=?")
                   ->execute([$nama, $satuan, $kategori, $urutan, $defChk, $id]);
                $flash = ['ok'=>true, 'msg'=>'Preset diperbarui.'];
            } else {
                $db->prepare("INSERT INTO saas_layanan_presets (nama, satuan, kategori, urutan, default_checked) VALUES (?,?,?,?,?)")
                   ->execute([$nama, $satuan, $kategori, $urutan, $defChk]);
                $flash = ['ok'=>true, 'msg'=>'Preset ditambahkan.'];
            }
        } elseif ($act === 'toggle') {
            $db->prepare("UPDATE saas_layanan_presets SET is_active = 1 - is_active WHERE id=?")->execute([(int)$_POST['id']]);
            $flash = ['ok'=>true, 'msg'=>'Status preset diubah.'];
        } elseif ($act === 'delete') {
            $db->prepare("DELETE FROM saas_layanan_presets WHERE id=?")->execute([(int)$_POST['id']]);
            $flash = ['ok'=>true, 'msg'=>'Preset dihapus.'];
        }
    } catch (Throwable $e) {
        error_log('[layanan_presets] '.$e->getMessage());
        $flash = ['ok'=>false, 'msg'=>'Terjadi kesalahan sistem.'];
    }
    // PRG kalau bukan mode edit
    if ($act !== '' && empty($_POST['stay_edit'])) {
        $_SESSION['_lp_flash'] = $flash;
        header('Location: /superadmin/layanan_presets.php'); exit;
    }
}
if (!empty($_SESSION['_lp_flash'])) { $flash = $_SESSION['_lp_flash']; unset($_SESSION['_lp_flash']); }

$rows = $db->query("SELECT * FROM saas_layanan_presets ORDER BY urutan, id")->fetchAll(PDO::FETCH_ASSOC);
$editId  = (int)($_GET['edit'] ?? 0);
$editRow = null;
foreach ($rows as $r) { if ((int)$r['id'] === $editId) { $editRow = $r; break; } }
$csrf = saGetCsrf();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php saRenderHead('Preset Layanan'); ?>
</head>
<body>
<?php saRenderNav('layanan_presets', 'Preset Layanan'); ?>

<div class="sa-page-header">
  <div>
    <h1>🧺 Preset Layanan</h1>
    <p>Daftar layanan siap-pakai yang muncul di wizard onboarding tenant baru. Tenant tinggal centang &amp; isi harga.</p>
  </div>
</div>

<?php if ($flash): ?>
<div style="margin:16px 0;padding:12px 16px;border-radius:10px;font-size:14px;
     background:<?= $flash['ok'] ? 'rgba(16,185,129,.12)' : 'rgba(226,75,74,.12)' ?>;
     border:1px solid <?= $flash['ok'] ? 'rgba(16,185,129,.4)' : 'rgba(226,75,74,.4)' ?>;
     color:<?= $flash['ok'] ? '#10B981' : '#E24B4A' ?>"><?= htmlspecialchars($flash['msg']) ?></div>
<?php endif; ?>

<!-- Form tambah / edit -->
<div class="sa-ai-border" style="padding:20px;margin-top:8px">
  <h3 style="margin:0 0 12px;font-size:15px;color:var(--glow)"><?= $editRow ? '✏️ Edit Preset' : '➕ Tambah Preset' ?></h3>
  <form method="post" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="act" value="save">
    <input type="hidden" name="id" value="<?= (int)($editRow['id'] ?? 0) ?>">
    <label style="flex:2;min-width:180px;font-size:12px;color:var(--ash)">Nama layanan
      <input type="text" name="nama" required value="<?= htmlspecialchars($editRow['nama'] ?? '') ?>" class="lmx-input" style="width:100%;margin-top:4px;padding:8px 10px;border-radius:8px;border:1px solid var(--crease,#2a3550);background:var(--crease-soft,#141d33);color:#fff;font-family:inherit">
    </label>
    <label style="flex:1;min-width:110px;font-size:12px;color:var(--ash)">Satuan
      <select name="satuan" class="lm-cust" style="width:100%;margin-top:4px;padding:8px 10px;border-radius:8px;border:1px solid var(--crease,#2a3550);background:var(--crease-soft,#141d33);color:#fff;font-family:inherit">
        <?php foreach (['kg','pcs','pasang','m²','set','meter'] as $s): $sel = ($editRow['satuan'] ?? 'kg')===$s?'selected':''; ?>
        <option value="<?= $s ?>" <?= $sel ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label style="flex:1;min-width:120px;font-size:12px;color:var(--ash)">Kategori
      <input type="text" name="kategori" value="<?= htmlspecialchars($editRow['kategori'] ?? 'Kiloan') ?>" class="lmx-input" style="width:100%;margin-top:4px;padding:8px 10px;border-radius:8px;border:1px solid var(--crease,#2a3550);background:var(--crease-soft,#141d33);color:#fff;font-family:inherit">
    </label>
    <label style="width:80px;font-size:12px;color:var(--ash)">Urutan
      <input type="number" name="urutan" value="<?= (int)($editRow['urutan'] ?? (count($rows)+1)) ?>" class="lmx-input" style="width:100%;margin-top:4px;padding:8px 10px;border-radius:8px;border:1px solid var(--crease,#2a3550);background:var(--crease-soft,#141d33);color:#fff;font-family:inherit">
    </label>
    <label style="font-size:12px;color:var(--ash);display:flex;align-items:center;gap:6px;padding-bottom:8px">
      <input type="checkbox" name="default_checked" value="1" <?= !empty($editRow['default_checked']) ? 'checked' : '' ?>> Tercentang default
    </label>
    <button type="submit" class="lmx-btn" style="padding:9px 18px"><?= $editRow ? 'Simpan' : 'Tambah' ?></button>
    <?php if ($editRow): ?><a href="/superadmin/layanan_presets.php" class="lmx-btn" style="padding:9px 18px;background:transparent;border:1px solid var(--crease,#2a3550)">Batal</a><?php endif; ?>
  </form>
</div>

<!-- List -->
<div class="sa-ai-border" style="padding:20px;margin-top:16px">
  <table class="sa-table">
    <thead><tr><th>#</th><th>Nama</th><th>Satuan</th><th>Kategori</th><th>Default</th><th>Status</th><th style="text-align:right">Aksi</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr style="<?= $r['is_active'] ? '' : 'opacity:.5' ?>">
        <td style="font-family:'DM Mono',monospace"><?= (int)$r['urutan'] ?></td>
        <td style="font-weight:600"><?= htmlspecialchars($r['nama']) ?></td>
        <td style="font-size:12px"><?= htmlspecialchars($r['satuan']) ?></td>
        <td style="font-size:12px"><?= htmlspecialchars($r['kategori']) ?></td>
        <td><?= $r['default_checked'] ? '✓' : '—' ?></td>
        <td style="font-size:12px;color:<?= $r['is_active'] ? '#10B981' : 'var(--ash)' ?>"><?= $r['is_active'] ? 'Aktif' : 'Nonaktif' ?></td>
        <td style="text-align:right;white-space:nowrap">
          <a href="?edit=<?= (int)$r['id'] ?>" class="lmx-btn" style="padding:5px 10px;font-size:12px">Edit</a>
          <form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button type="submit" class="lmx-btn" style="padding:5px 10px;font-size:12px;background:transparent;border:1px solid var(--crease,#2a3550)"><?= $r['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button></form>
          <form method="post" style="display:inline" onsubmit="return confirm('Hapus preset <?= htmlspecialchars($r['nama']) ?>?')"><input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button type="submit" class="lmx-btn" style="padding:5px 10px;font-size:12px;background:transparent;border:1px solid rgba(226,75,74,.5);color:#E24B4A">Hapus</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($rows)): ?><tr><td colspan="7" style="text-align:center;color:var(--ash);padding:20px">Belum ada preset.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php saRenderNavClose(); ?>
</body>
</html>

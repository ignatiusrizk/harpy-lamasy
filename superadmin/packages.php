<?php
// ══════════════════════════════════════════════════════
// superadmin/packages.php — Kelola Paket & Coin Bundle
// CRUD: saas_packages + saas_coin_bundles
// ══════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';

date_default_timezone_set('Asia/Jakarta');

$action = $_GET['action'] ?? '';

// ── API LAYER ─────────────────────────────────────────
if ($action) {
    header('Content-Type: application/json');
    $db = Database::get();

    // ── GET: list packages ────────────────────────────
    if ($action === 'list_packages') {
        $rows = $db->query(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM saas_manual_payments WHERE package_id = p.id) AS used_payments,
                    (SELECT COUNT(*) FROM tenants WHERE package_id = p.id)              AS used_tenants
             FROM saas_packages p
             ORDER BY p.urutan ASC, p.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'rows' => $rows]);
        exit;
    }

    // ── GET: list bundles ─────────────────────────────
    if ($action === 'list_bundles') {
        $rows = $db->query(
            "SELECT b.*,
                    (SELECT COUNT(*) FROM saas_manual_payments WHERE bundle_id = b.id) AS used_payments
             FROM saas_coin_bundles b
             ORDER BY b.urutan ASC, b.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'rows' => $rows]);
        exit;
    }

    // ── POST actions — CSRF required ──────────────────
    saVerifyCsrf();

    // ── SAVE PACKAGE (insert or update) ──────────────
    if ($action === 'save_package') {
        $d          = json_decode(file_get_contents('php://input'), true) ?: [];
        $id         = (int)($d['id'] ?? 0);
        $nama       = substr(trim($d['nama'] ?? ''), 0, 100);
        $rawSlug    = strtolower(preg_replace('/[^a-z0-9_-]/', '', str_replace([' ', '.'], '_', $d['slug'] ?? $nama)));
        $slug       = substr($rawSlug, 0, 50);
        $deskripsi  = trim($d['deskripsi'] ?? '');
        $setup_fee  = max(0, (int)($d['setup_fee'] ?? 0));
        $coin_awal  = max(0, (int)($d['coin_awal'] ?? 50000));
        $trial_hari = max(1, (int)($d['trial_hari'] ?? 30));
        $max_outlets = max(0, (int)($d['max_outlets'] ?? 1));
        $is_custom  = empty($d['is_custom']) ? 0 : 1;
        $urutan     = (int)($d['urutan'] ?? 0);

        if (!$nama)  { echo json_encode(['error' => 'Nama paket wajib diisi.']); exit; }
        if (!$slug)  { echo json_encode(['error' => 'Slug tidak valid.']); exit; }

        try {
            if ($id > 0) {
                $db->prepare(
                    "UPDATE saas_packages
                        SET nama=?, slug=?, deskripsi=?, setup_fee=?, coin_awal=?,
                            trial_hari=?, max_outlets=?, is_custom=?, urutan=?
                      WHERE id=?"
                )->execute([$nama, $slug, $deskripsi, $setup_fee, $coin_awal,
                             $trial_hari, $max_outlets, $is_custom, $urutan, $id]);
                logSuperAdminAction('update_package', null, "Update paket #$id: $nama");
                echo json_encode(['ok' => true, 'msg' => "Paket \"$nama\" berhasil diperbarui."]);
            } else {
                $db->prepare(
                    "INSERT INTO saas_packages
                        (nama, slug, deskripsi, setup_fee, coin_awal, trial_hari, max_outlets, is_custom, urutan)
                     VALUES (?,?,?,?,?,?,?,?,?)"
                )->execute([$nama, $slug, $deskripsi, $setup_fee, $coin_awal,
                             $trial_hari, $max_outlets, $is_custom, $urutan]);
                $newId = (int)$db->lastInsertId();
                logSuperAdminAction('create_package', null, "Buat paket baru #$newId: $nama");
                echo json_encode(['ok' => true, 'msg' => "Paket \"$nama\" berhasil ditambahkan."]);
            }
        } catch (Throwable $e) {
            // Slug duplicate?
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                echo json_encode(['error' => "Slug \"$slug\" sudah digunakan. Ganti nama atau ubah slug."]);
            } else {
                echo json_encode(['error' => $e->getMessage()]);
            }
        }
        exit;
    }

    // ── SAVE BUNDLE (insert or update) ───────────────
    if ($action === 'save_bundle') {
        $d           = json_decode(file_get_contents('php://input'), true) ?: [];
        $id          = (int)($d['id'] ?? 0);
        $nama        = substr(trim($d['nama'] ?? ''), 0, 100);
        $harga       = max(1, (int)($d['harga'] ?? 0));
        $coin_didapat = max(1, (int)($d['coin_didapat'] ?? 0));
        $bonus_pct   = max(0.0, min(100.0, (float)($d['bonus_pct'] ?? 0)));
        $is_featured = empty($d['is_featured']) ? 0 : 1;
        $urutan      = (int)($d['urutan'] ?? 0);

        if (!$nama)   { echo json_encode(['error' => 'Nama bundle wajib diisi.']); exit; }
        if ($harga < 1) { echo json_encode(['error' => 'Harga harus lebih dari 0.']); exit; }
        if ($coin_didapat < 1) { echo json_encode(['error' => 'Coin harus lebih dari 0.']); exit; }

        try {
            if ($id > 0) {
                $db->prepare(
                    "UPDATE saas_coin_bundles
                        SET nama=?, harga=?, coin_didapat=?, bonus_pct=?, is_featured=?, urutan=?
                      WHERE id=?"
                )->execute([$nama, $harga, $coin_didapat, $bonus_pct, $is_featured, $urutan, $id]);
                logSuperAdminAction('update_bundle', null, "Update bundle #$id: $nama");
                echo json_encode(['ok' => true, 'msg' => "Bundle \"$nama\" berhasil diperbarui."]);
            } else {
                $db->prepare(
                    "INSERT INTO saas_coin_bundles (nama, harga, coin_didapat, bonus_pct, is_featured, urutan)
                     VALUES (?,?,?,?,?,?)"
                )->execute([$nama, $harga, $coin_didapat, $bonus_pct, $is_featured, $urutan]);
                $newId = (int)$db->lastInsertId();
                logSuperAdminAction('create_bundle', null, "Buat bundle baru #$newId: $nama");
                echo json_encode(['ok' => true, 'msg' => "Bundle \"$nama\" berhasil ditambahkan."]);
            }
        } catch (Throwable $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // ── TOGGLE PACKAGE is_active ──────────────────────
    if ($action === 'toggle_package') {
        $d   = json_decode(file_get_contents('php://input'), true) ?: [];
        $id  = (int)($d['id'] ?? 0);
        $val = (int)(!empty($d['value']));
        $db->prepare("UPDATE saas_packages SET is_active=? WHERE id=?")->execute([$val, $id]);
        logSuperAdminAction('toggle_package', null, "Paket #$id is_active → $val");
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── TOGGLE BUNDLE (is_active atau is_featured) ────
    if ($action === 'toggle_bundle') {
        $d     = json_decode(file_get_contents('php://input'), true) ?: [];
        $id    = (int)($d['id'] ?? 0);
        $field = $d['field'] ?? '';
        $val   = (int)(!empty($d['value']));
        if (!in_array($field, ['is_active', 'is_featured'], true)) {
            echo json_encode(['error' => 'Field tidak valid.']); exit;
        }
        $db->prepare("UPDATE saas_coin_bundles SET {$field}=? WHERE id=?")->execute([$val, $id]);
        logSuperAdminAction('toggle_bundle', null, "Bundle #$id $field → $val");
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── DELETE PACKAGE ────────────────────────────────
    if ($action === 'delete_package') {
        $d  = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($d['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'ID tidak valid.']); exit; }

        $stmtP = $db->prepare("SELECT COUNT(*) FROM saas_manual_payments WHERE package_id=?");
        $stmtP->execute([$id]);
        $usedP = (int)$stmtP->fetchColumn();

        $stmtT = $db->prepare("SELECT COUNT(*) FROM tenants WHERE package_id=?");
        $stmtT->execute([$id]);
        $usedT = (int)$stmtT->fetchColumn();

        if ($usedP > 0 || $usedT > 0) {
            $detail = [];
            if ($usedT > 0) $detail[] = "$usedT tenant";
            if ($usedP > 0) $detail[] = "$usedP pembayaran";
            echo json_encode([
                'error' => 'Tidak bisa dihapus — sudah dipakai oleh ' . implode(' dan ', $detail) . '. Nonaktifkan saja.'
            ]);
            exit;
        }

        $db->prepare("DELETE FROM saas_packages WHERE id=?")->execute([$id]);
        logSuperAdminAction('delete_package', null, "Hapus paket #$id");
        echo json_encode(['ok' => true, 'msg' => 'Paket berhasil dihapus.']);
        exit;
    }

    // ── DELETE BUNDLE ─────────────────────────────────
    if ($action === 'delete_bundle') {
        $d  = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($d['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'ID tidak valid.']); exit; }

        $stmtP = $db->prepare("SELECT COUNT(*) FROM saas_manual_payments WHERE bundle_id=?");
        $stmtP->execute([$id]);
        $usedP = (int)$stmtP->fetchColumn();

        if ($usedP > 0) {
            echo json_encode([
                'error' => "Tidak bisa dihapus — sudah dipakai di $usedP transaksi. Nonaktifkan saja."
            ]);
            exit;
        }

        $db->prepare("DELETE FROM saas_coin_bundles WHERE id=?")->execute([$id]);
        logSuperAdminAction('delete_bundle', null, "Hapus bundle #$id");
        echo json_encode(['ok' => true, 'msg' => 'Bundle berhasil dihapus.']);
        exit;
    }

    echo json_encode(['error' => 'Action tidak dikenal.']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php saRenderHead('Paket & Bundle'); ?>
<style>
/* ── Extra styles for packages page ── */
.pkg-badge-custom {
  background: rgba(245,158,11,.15); color: #FCD34D;
  border: 1px solid rgba(245,158,11,.25);
  font-size: 10px; font-weight: 700; padding: 1px 7px;
  border-radius: 20px; margin-left: 6px;
}
.bundle-featured {
  background: rgba(99,102,241,.15); color: #A5B4FC;
  border: 1px solid rgba(99,102,241,.25);
  font-size: 10px; font-weight: 700; padding: 1px 7px;
  border-radius: 20px;
}
.pkg-setup-fee { font-family: var(--mono); font-size: 13px; color: #6EE7B7; font-weight: 600; }
.pkg-setup-free { font-family: var(--mono); font-size: 11px; color: rgba(255,255,255,.3); }
.pkg-coin { font-family: var(--mono); color: #FCD34D; }
.pkg-max-outlet { font-size: 12px; color: rgba(255,255,255,.6); }
.bonus-pct { font-size: 11px; font-weight: 700; color: #6EE7B7; margin-left: 4px; }
.bonus-zero { color: rgba(255,255,255,.3); }

/* ── Toggle switch ── */
.sw { position: relative; display: inline-block; width: 36px; height: 20px; }
.sw input { opacity: 0; width: 0; height: 0; }
.sw-track {
  position: absolute; inset: 0; border-radius: 20px; cursor: pointer;
  background: rgba(255,255,255,.12); transition: background .2s;
}
.sw-track::after {
  content: ''; position: absolute; left: 3px; top: 3px;
  width: 14px; height: 14px; border-radius: 50%;
  background: rgba(255,255,255,.4); transition: transform .2s, background .2s;
}
.sw input:checked + .sw-track { background: rgba(99,102,241,.6); }
.sw input:checked + .sw-track::after { transform: translateX(16px); background: #fff; }

/* ── Form groups in modal ── */
.fg { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
.fg label { font-size: 10px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: rgba(255,255,255,.4); }
.fg input, .fg textarea, .fg select {
  padding: 9px 12px; background: rgba(255,255,255,.06);
  border: 1.5px solid rgba(255,255,255,.1); border-radius: 8px;
  color: var(--white); font-family: var(--font); font-size: 13px; outline: none;
  transition: border-color .15s;
}
.fg input:focus, .fg textarea:focus, .fg select:focus {
  border-color: var(--sa); box-shadow: 0 0 0 3px rgba(99,102,241,.12);
}
.fg textarea { resize: vertical; min-height: 72px; }
.fg select option { background: var(--navy); }
.fg-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.fg-hint { font-size: 11px; color: rgba(255,255,255,.3); margin-top: 3px; }
.fg-check { display: flex; align-items: center; gap: 10px; }
.fg-check input[type=checkbox] { width: 16px; height: 16px; cursor: pointer; accent-color: var(--sa); }
.fg-check label { font-size: 13px; color: rgba(255,255,255,.7); text-transform: none; letter-spacing: 0; font-weight: 500; }
</style>
</head>
<body>
<div class="sa-layout">
<?php saRenderNav('packages', 'Paket & Coin Bundle'); ?>

<div class="sa-page-header">
  <h1>📦 Paket & Coin Bundle</h1>
  <p>Kelola struktur paket berlangganan dan paket topup coin untuk tenant</p>
</div>

<!-- Tabs -->
<div class="sa-tabs" id="mainTabs">
  <button class="sa-tab active" onclick="switchTab('packages')">📦 Paket LaMaSy</button>
  <button class="sa-tab"       onclick="switchTab('bundles')">🪙 Coin Bundle</button>
</div>

<!-- ══ TAB: PACKAGES ══════════════════════════════════ -->
<div id="tabPackages">
  <div class="sa-card">
    <div class="sa-card-header">
      <h3>Paket LaMaSy</h3>
      <button class="sa-btn sa-btn-primary sa-btn-sm" onclick="openPackageModal()">＋ Tambah Paket</button>
    </div>
    <div class="sa-table-wrap">
      <table class="sa-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Nama Paket</th>
            <th>Setup Fee</th>
            <th>Coin Awal</th>
            <th>Trial</th>
            <th>Max Outlet</th>
            <th>Dipakai</th>
            <th>Aktif</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody id="packagesBody">
          <tr><td colspan="9" style="text-align:center;padding:32px;color:rgba(255,255,255,.3);">Memuat...</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ══ TAB: BUNDLES ═══════════════════════════════════ -->
<div id="tabBundles" style="display:none">
  <div class="sa-card">
    <div class="sa-card-header">
      <h3>Coin Bundle</h3>
      <button class="sa-btn sa-btn-primary sa-btn-sm" onclick="openBundleModal()">＋ Tambah Bundle</button>
    </div>
    <div class="sa-table-wrap">
      <table class="sa-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Nama Bundle</th>
            <th>Harga</th>
            <th>Coin Didapat</th>
            <th>Bonus</th>
            <th>Dipakai</th>
            <th>Featured</th>
            <th>Aktif</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody id="bundlesBody">
          <tr><td colspan="9" style="text-align:center;padding:32px;color:rgba(255,255,255,.3);">Memuat...</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ══ MODAL: PACKAGE ═════════════════════════════════ -->
<div class="sa-modal-overlay" id="packageModal">
  <div class="sa-modal" style="max-width:560px;">
    <h3 id="packageModalTitle">📦 Tambah Paket</h3>
    <input type="hidden" id="pkgId" value="0"/>

    <div class="fg-row">
      <div class="fg">
        <label>Nama Paket *</label>
        <input type="text" id="pkgNama" placeholder="Starter, Pro, Enterprise..." maxlength="100"
               oninput="autoSlug()"/>
      </div>
      <div class="fg">
        <label>Slug *</label>
        <input type="text" id="pkgSlug" placeholder="starter" maxlength="50"
               style="font-family:var(--mono);font-size:12px;"/>
        <span class="fg-hint">Huruf kecil, angka, underscore saja</span>
      </div>
    </div>

    <div class="fg">
      <label>Deskripsi</label>
      <textarea id="pkgDeskripsi" placeholder="Jelaskan fitur & keunggulan paket ini..."></textarea>
    </div>

    <div class="fg-row">
      <div class="fg">
        <label>Setup Fee (Rp)</label>
        <input type="number" id="pkgSetupFee" placeholder="300000" min="0" step="1000"/>
        <span class="fg-hint" id="pkgSetupFeeHint">Kosongkan / isi 0 jika gratis</span>
      </div>
      <div class="fg">
        <label>Coin Awal</label>
        <input type="number" id="pkgCoinAwal" placeholder="50000" min="0"/>
        <span class="fg-hint">Dikreditkan saat aktivasi</span>
      </div>
    </div>

    <div class="fg-row">
      <div class="fg">
        <label>Durasi Trial (hari)</label>
        <input type="number" id="pkgTrialHari" value="30" min="1" max="365"/>
      </div>
      <div class="fg">
        <label>Max Outlet</label>
        <input type="number" id="pkgMaxOutlets" value="1" min="0"/>
        <span class="fg-hint">0 = unlimited</span>
      </div>
    </div>

    <div class="fg-row" style="align-items:end">
      <div class="fg">
        <label>Urutan Tampil</label>
        <input type="number" id="pkgUrutan" value="0" min="0"/>
        <span class="fg-hint">Angka kecil tampil duluan</span>
      </div>
      <div class="fg" style="padding-bottom:12px;">
        <div class="fg-check">
          <input type="checkbox" id="pkgIsCustom" onchange="onCustomChange()"/>
          <label for="pkgIsCustom">Harga custom (Enterprise)</label>
        </div>
        <span class="fg-hint">Setup fee tidak dihitung — nego langsung</span>
      </div>
    </div>

    <div class="sa-modal-footer">
      <button class="sa-btn sa-btn-outline" onclick="closeModal('packageModal')">Batal</button>
      <button class="sa-btn sa-btn-primary" onclick="submitPackage()">💾 Simpan Paket</button>
    </div>
  </div>
</div>

<!-- ══ MODAL: BUNDLE ══════════════════════════════════ -->
<div class="sa-modal-overlay" id="bundleModal">
  <div class="sa-modal" style="max-width:480px;">
    <h3 id="bundleModalTitle">🪙 Tambah Coin Bundle</h3>
    <input type="hidden" id="bdlId" value="0"/>

    <div class="fg">
      <label>Nama Bundle *</label>
      <input type="text" id="bdlNama" placeholder="Paket Hemat, Paket Standar..." maxlength="100"/>
    </div>

    <div class="fg-row">
      <div class="fg">
        <label>Harga (Rp) *</label>
        <input type="number" id="bdlHarga" placeholder="100000" min="1" step="1000"
               oninput="autoCalcCoin()"/>
      </div>
      <div class="fg">
        <label>Bonus (%)</label>
        <input type="number" id="bdlBonusPct" placeholder="0" min="0" max="100" step="0.5"
               oninput="autoCalcCoin()"/>
        <span class="fg-hint">0 = tidak ada bonus</span>
      </div>
    </div>

    <div class="fg">
      <label>Coin Didapat *</label>
      <input type="number" id="bdlCoinDidapat" placeholder="110000" min="1"/>
      <span class="fg-hint" id="bdlCoinHint" style="color:rgba(99,102,241,.8)"></span>
    </div>

    <div class="fg-row" style="align-items:end">
      <div class="fg">
        <label>Urutan Tampil</label>
        <input type="number" id="bdlUrutan" value="0" min="0"/>
      </div>
      <div class="fg" style="padding-bottom:12px;">
        <div class="fg-check">
          <input type="checkbox" id="bdlIsFeatured"/>
          <label for="bdlIsFeatured">⭐ Tampilkan sebagai featured</label>
        </div>
      </div>
    </div>

    <div class="sa-modal-footer">
      <button class="sa-btn sa-btn-outline" onclick="closeModal('bundleModal')">Batal</button>
      <button class="sa-btn sa-btn-primary" onclick="submitBundle()">💾 Simpan Bundle</button>
    </div>
  </div>
</div>

<?php saRenderNavClose(); ?>

<script>
const rp  = n => 'Rp ' + parseInt(n||0).toLocaleString('id-ID');
const coin = n => parseInt(n||0).toLocaleString('id-ID');
function esc(s){ const d=document.createElement('div'); d.textContent=String(s??''); return d.innerHTML; }

// ── Tab switching ─────────────────────────────────────
function switchTab(tab) {
  document.getElementById('tabPackages').style.display = tab === 'packages' ? '' : 'none';
  document.getElementById('tabBundles').style.display  = tab === 'bundles'  ? '' : 'none';
  document.querySelectorAll('#mainTabs .sa-tab').forEach((el, i) => {
    el.classList.toggle('active', (i === 0 && tab === 'packages') || (i === 1 && tab === 'bundles'));
  });
  if (tab === 'packages') loadPackages();
  else loadBundles();
}

// ── Load packages ─────────────────────────────────────
function loadPackages() {
  saFetch('packages.php?action=list_packages')
    .then(r => r.json()).then(d => {
      if (d.error) { saShowToast(d.error, 'error'); return; }
      renderPackages(d.rows);
    });
}

function renderPackages(rows) {
  const tb = document.getElementById('packagesBody');
  if (!rows.length) {
    tb.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:32px;color:rgba(255,255,255,.3);">Belum ada paket.</td></tr>';
    return;
  }
  tb.innerHTML = rows.map((p, i) => `
    <tr>
      <td style="color:rgba(255,255,255,.3);font-size:12px;">${i+1}</td>
      <td>
        <strong>${esc(p.nama)}</strong>
        ${p.is_custom ? '<span class="pkg-badge-custom">Custom</span>' : ''}
        ${p.slug ? `<br><small style="font-family:var(--mono);font-size:10px;color:rgba(255,255,255,.25);">${esc(p.slug)}</small>` : ''}
      </td>
      <td>
        ${p.is_custom
          ? '<span style="font-size:11px;color:rgba(255,255,255,.35);">Nego</span>'
          : (p.setup_fee > 0
              ? `<span class="pkg-setup-fee">${rp(p.setup_fee)}</span>`
              : '<span class="pkg-setup-free">Gratis</span>')}
      </td>
      <td><span class="pkg-coin">${coin(p.coin_awal)}</span></td>
      <td style="font-size:12px;color:rgba(255,255,255,.6);">${esc(p.trial_hari)} hari</td>
      <td class="pkg-max-outlet">${p.max_outlets == 0 ? '∞ Unlimited' : p.max_outlets}</td>
      <td style="font-size:12px;">
        ${p.used_tenants > 0 ? `<span style="color:#93C5FD;">${p.used_tenants} tenant</span>` : ''}
        ${p.used_payments > 0 ? `<span style="color:rgba(255,255,255,.35);margin-left:4px;">${p.used_payments} txn</span>` : ''}
        ${(p.used_tenants == 0 && p.used_payments == 0) ? '<span style="color:rgba(255,255,255,.2);">—</span>' : ''}
      </td>
      <td>
        <label class="sw" title="${p.is_active ? 'Nonaktifkan' : 'Aktifkan'}">
          <input type="checkbox" ${p.is_active ? 'checked' : ''}
            onchange="togglePackage(${p.id}, this.checked)"/>
          <span class="sw-track"></span>
        </label>
      </td>
      <td>
        <button class="sa-btn sa-btn-sm sa-btn-outline" onclick="editPackage(${p.id})">Edit</button>
        <button class="sa-btn sa-btn-sm sa-btn-danger" style="margin-left:4px;"
                onclick="deletePackage(${p.id}, '${esc(p.nama)}')">Hapus</button>
      </td>
    </tr>`).join('');
}

// ── Load bundles ──────────────────────────────────────
function loadBundles() {
  saFetch('packages.php?action=list_bundles')
    .then(r => r.json()).then(d => {
      if (d.error) { saShowToast(d.error, 'error'); return; }
      renderBundles(d.rows);
    });
}

function renderBundles(rows) {
  const tb = document.getElementById('bundlesBody');
  if (!rows.length) {
    tb.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:32px;color:rgba(255,255,255,.3);">Belum ada bundle.</td></tr>';
    return;
  }
  tb.innerHTML = rows.map((b, i) => {
    const bonusHtml = parseFloat(b.bonus_pct) > 0
      ? `<span class="bonus-pct">+${parseFloat(b.bonus_pct)}%</span>`
      : `<span class="bonus-zero">—</span>`;
    return `
    <tr>
      <td style="color:rgba(255,255,255,.3);font-size:12px;">${i+1}</td>
      <td>
        <strong>${esc(b.nama)}</strong>
        ${b.is_featured ? ' <span class="bundle-featured">⭐ Featured</span>' : ''}
      </td>
      <td><span class="pkg-setup-fee">${rp(b.harga)}</span></td>
      <td><span class="pkg-coin">${coin(b.coin_didapat)}</span></td>
      <td>${bonusHtml}</td>
      <td style="font-size:12px;color:rgba(255,255,255,.35);">
        ${b.used_payments > 0 ? `${b.used_payments} txn` : '—'}
      </td>
      <td>
        <label class="sw" title="${b.is_featured ? 'Hapus featured' : 'Set featured'}">
          <input type="checkbox" ${b.is_featured ? 'checked' : ''}
            onchange="toggleBundle(${b.id}, 'is_featured', this.checked)"/>
          <span class="sw-track"></span>
        </label>
      </td>
      <td>
        <label class="sw" title="${b.is_active ? 'Nonaktifkan' : 'Aktifkan'}">
          <input type="checkbox" ${b.is_active ? 'checked' : ''}
            onchange="toggleBundle(${b.id}, 'is_active', this.checked)"/>
          <span class="sw-track"></span>
        </label>
      </td>
      <td>
        <button class="sa-btn sa-btn-sm sa-btn-outline" onclick="editBundle(${b.id})">Edit</button>
        <button class="sa-btn sa-btn-sm sa-btn-danger" style="margin-left:4px;"
                onclick="deleteBundle(${b.id}, '${esc(b.nama)}')">Hapus</button>
      </td>
    </tr>`;
  }).join('');
}

// ── Package modal ─────────────────────────────────────
let _pkgRows = [];

function openPackageModal(pkg = null) {
  document.getElementById('packageModalTitle').textContent = pkg ? '✏️ Edit Paket' : '📦 Tambah Paket';
  document.getElementById('pkgId').value         = pkg?.id    ?? 0;
  document.getElementById('pkgNama').value        = pkg?.nama  ?? '';
  document.getElementById('pkgSlug').value        = pkg?.slug  ?? '';
  document.getElementById('pkgDeskripsi').value   = pkg?.deskripsi ?? '';
  document.getElementById('pkgSetupFee').value    = pkg?.setup_fee ?? '';
  document.getElementById('pkgCoinAwal').value    = pkg?.coin_awal ?? 50000;
  document.getElementById('pkgTrialHari').value   = pkg?.trial_hari ?? 30;
  document.getElementById('pkgMaxOutlets').value  = pkg?.max_outlets ?? 1;
  document.getElementById('pkgUrutan').value      = pkg?.urutan ?? 0;
  document.getElementById('pkgIsCustom').checked  = !!parseInt(pkg?.is_custom ?? 0);
  onCustomChange();
  document.getElementById('packageModal').classList.add('open');
  setTimeout(() => document.getElementById('pkgNama').focus(), 100);
}

function editPackage(id) {
  saFetch('packages.php?action=list_packages')
    .then(r => r.json()).then(d => {
      const pkg = (d.rows || []).find(p => p.id == id);
      if (pkg) openPackageModal(pkg);
    });
}

function autoSlug() {
  const n = document.getElementById('pkgNama').value;
  document.getElementById('pkgSlug').value =
    n.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
}

function onCustomChange() {
  const isCustom = document.getElementById('pkgIsCustom').checked;
  const feeInput = document.getElementById('pkgSetupFee');
  feeInput.disabled = isCustom;
  feeInput.style.opacity = isCustom ? '.35' : '1';
  document.getElementById('pkgSetupFeeHint').textContent =
    isCustom ? 'Harga dinegosiasikan langsung' : 'Kosongkan / isi 0 jika gratis';
}

function submitPackage() {
  const id = parseInt(document.getElementById('pkgId').value);
  const payload = {
    id,
    nama:        document.getElementById('pkgNama').value.trim(),
    slug:        document.getElementById('pkgSlug').value.trim(),
    deskripsi:   document.getElementById('pkgDeskripsi').value.trim(),
    setup_fee:   document.getElementById('pkgSetupFee').value || 0,
    coin_awal:   document.getElementById('pkgCoinAwal').value || 0,
    trial_hari:  document.getElementById('pkgTrialHari').value || 30,
    max_outlets: document.getElementById('pkgMaxOutlets').value || 1,
    urutan:      document.getElementById('pkgUrutan').value || 0,
    is_custom:   document.getElementById('pkgIsCustom').checked ? 1 : 0,
  };
  if (!payload.nama) { saShowToast('Nama paket wajib diisi.', 'error'); return; }

  saFetch('packages.php?action=save_package', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  }).then(r => r.json()).then(d => {
    if (d.error) { saShowToast(d.error, 'error'); return; }
    saShowToast(d.msg, 'success');
    closeModal('packageModal');
    loadPackages();
  });
}

function togglePackage(id, active) {
  saFetch('packages.php?action=toggle_package', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id, value: active ? 1 : 0 }),
  }).then(r => r.json()).then(d => {
    if (d.error) { saShowToast(d.error, 'error'); loadPackages(); }
    else saShowToast(active ? 'Paket diaktifkan.' : 'Paket dinonaktifkan.', 'info');
  });
}

function deletePackage(id, nama) {
  if (!confirm(`Hapus paket "${nama}"?\n\nPaket yang sudah dipakai tenant tidak bisa dihapus.`)) return;
  saFetch('packages.php?action=delete_package', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id }),
  }).then(r => r.json()).then(d => {
    if (d.error) { saShowToast(d.error, 'error'); return; }
    saShowToast(d.msg, 'success');
    loadPackages();
  });
}

// ── Bundle modal ──────────────────────────────────────
function openBundleModal(bdl = null) {
  document.getElementById('bundleModalTitle').textContent = bdl ? '✏️ Edit Bundle' : '🪙 Tambah Coin Bundle';
  document.getElementById('bdlId').value         = bdl?.id ?? 0;
  document.getElementById('bdlNama').value        = bdl?.nama ?? '';
  document.getElementById('bdlHarga').value       = bdl?.harga ?? '';
  document.getElementById('bdlBonusPct').value    = bdl?.bonus_pct ?? 0;
  document.getElementById('bdlCoinDidapat').value = bdl?.coin_didapat ?? '';
  document.getElementById('bdlUrutan').value      = bdl?.urutan ?? 0;
  document.getElementById('bdlIsFeatured').checked = !!parseInt(bdl?.is_featured ?? 0);
  document.getElementById('bdlCoinHint').textContent = '';
  document.getElementById('bundleModal').classList.add('open');
  setTimeout(() => document.getElementById('bdlNama').focus(), 100);
}

function editBundle(id) {
  saFetch('packages.php?action=list_bundles')
    .then(r => r.json()).then(d => {
      const bdl = (d.rows || []).find(b => b.id == id);
      if (bdl) openBundleModal(bdl);
    });
}

function autoCalcCoin() {
  const harga   = parseInt(document.getElementById('bdlHarga').value) || 0;
  const bonus   = parseFloat(document.getElementById('bdlBonusPct').value) || 0;
  if (!harga) { document.getElementById('bdlCoinHint').textContent = ''; return; }
  const baseCoin   = harga;            // 1 Rp = 1 coin (base)
  const bonusCoin  = Math.round(baseCoin * bonus / 100);
  const totalCoin  = baseCoin + bonusCoin;
  document.getElementById('bdlCoinDidapat').value = totalCoin;
  document.getElementById('bdlCoinHint').textContent =
    bonus > 0
      ? `Base ${harga.toLocaleString('id-ID')} + bonus ${bonusCoin.toLocaleString('id-ID')} = ${totalCoin.toLocaleString('id-ID')} coin`
      : `1 Rp = 1 coin`;
}

function submitBundle() {
  const id = parseInt(document.getElementById('bdlId').value);
  const payload = {
    id,
    nama:         document.getElementById('bdlNama').value.trim(),
    harga:        document.getElementById('bdlHarga').value || 0,
    coin_didapat: document.getElementById('bdlCoinDidapat').value || 0,
    bonus_pct:    document.getElementById('bdlBonusPct').value || 0,
    is_featured:  document.getElementById('bdlIsFeatured').checked ? 1 : 0,
    urutan:       document.getElementById('bdlUrutan').value || 0,
  };
  if (!payload.nama)  { saShowToast('Nama bundle wajib diisi.', 'error'); return; }
  if (!payload.harga) { saShowToast('Harga wajib diisi.', 'error'); return; }

  saFetch('packages.php?action=save_bundle', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  }).then(r => r.json()).then(d => {
    if (d.error) { saShowToast(d.error, 'error'); return; }
    saShowToast(d.msg, 'success');
    closeModal('bundleModal');
    loadBundles();
  });
}

function toggleBundle(id, field, value) {
  saFetch('packages.php?action=toggle_bundle', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id, field, value: value ? 1 : 0 }),
  }).then(r => r.json()).then(d => {
    if (d.error) { saShowToast(d.error, 'error'); loadBundles(); }
  });
}

function deleteBundle(id, nama) {
  if (!confirm(`Hapus bundle "${nama}"?\n\nBundle yang sudah dipakai dalam transaksi tidak bisa dihapus.`)) return;
  saFetch('packages.php?action=delete_bundle', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id }),
  }).then(r => r.json()).then(d => {
    if (d.error) { saShowToast(d.error, 'error'); return; }
    saShowToast(d.msg, 'success');
    loadBundles();
  });
}

function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}

// Close modals on backdrop click
document.querySelectorAll('.sa-modal-overlay').forEach(el => {
  el.addEventListener('click', e => { if (e.target === el) el.classList.remove('open'); });
});

// Close modals on Escape
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') document.querySelectorAll('.sa-modal-overlay.open')
    .forEach(el => el.classList.remove('open'));
});

// Initial load
loadPackages();
</script>
</body>
</html>

<?php
// ══════════════════════════════════════════════════════
// superadmin/packages.php — Biaya Aktivasi & Coin Bundle
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

    // ── SAVE BUNDLE (insert or update) ───────────────
    if ($action === 'save_bundle') {
        $d            = json_decode(file_get_contents('php://input'), true) ?: [];
        $id           = (int)($d['id'] ?? 0);
        $nama         = substr(trim($d['nama'] ?? ''), 0, 100);
        $harga        = max(1, (int)($d['harga'] ?? 0));
        $coin_didapat = max(1, (int)($d['coin_didapat'] ?? 0));
        $bonus_pct    = max(0.0, min(100.0, (float)($d['bonus_pct'] ?? 0)));
        $is_featured  = empty($d['is_featured']) ? 0 : 1;
        $urutan       = (int)($d['urutan'] ?? 0);

        if (!$nama)         { echo json_encode(['error' => 'Nama bundle wajib diisi.']); exit; }
        if ($harga < 1)     { echo json_encode(['error' => 'Harga harus lebih dari 0.']); exit; }
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

    // ── DELETE BUNDLE ─────────────────────────────────
    if ($action === 'delete_bundle') {
        $d  = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($d['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'ID tidak valid.']); exit; }

        $stmtP = $db->prepare("SELECT COUNT(*) FROM saas_manual_payments WHERE bundle_id=?");
        $stmtP->execute([$id]);
        if ((int)$stmtP->fetchColumn() > 0) {
            echo json_encode(['error' => 'Tidak bisa dihapus — sudah dipakai dalam transaksi. Nonaktifkan saja.']);
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
<?php saRenderHead('Coin & Aktivasi'); ?>
<style>
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
.fg select option { background: var(--navy); }
.fg-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.fg-hint { font-size: 11px; color: rgba(255,255,255,.3); margin-top: 3px; }
.fg-check { display: flex; align-items: center; gap: 10px; }
.fg-check input[type=checkbox] { width: 16px; height: 16px; cursor: pointer; accent-color: var(--sa); }
.fg-check label { font-size: 13px; color: rgba(255,255,255,.7); text-transform: none; letter-spacing: 0; font-weight: 500; }

.bundle-featured {
  background: rgba(99,102,241,.15); color: #A5B4FC;
  border: 1px solid rgba(99,102,241,.25);
  font-size: 10px; font-weight: 700; padding: 1px 7px; border-radius: 20px;
}
.bonus-pct  { font-size: 11px; font-weight: 700; color: #6EE7B7; margin-left: 4px; }
.bonus-zero { color: rgba(255,255,255,.3); }
.pkg-fee    { font-family: var(--mono); font-size: 13px; color: #6EE7B7; font-weight: 600; }
.pkg-coin   { font-family: var(--mono); color: #FCD34D; }

/* ── Activation fee card ── */
.activation-card {
  background: rgba(16,185,129,.04);
  border: 1px solid rgba(16,185,129,.15);
  border-radius: 14px; padding: 22px 24px;
  margin-bottom: 24px;
  display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;
}
.activation-card .ac-label {
  font-size: 11px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase;
  color: rgba(255,255,255,.4); margin-bottom: 4px;
}
.activation-card .ac-value {
  font-size: 28px; font-weight: 800; font-family: var(--mono); color: #6EE7B7;
}
.activation-card .ac-sub {
  font-size: 12px; color: rgba(255,255,255,.35); margin-top: 4px;
}
</style>
</head>
<body>
<div class="sa-layout">
<?php saRenderNav('packages', 'Coin & Aktivasi'); ?>

<div class="sa-page-header">
  <h1>🪙 Coin &amp; Aktivasi</h1>
  <p>Kelola biaya aktivasi outlet dan paket topup coin</p>
</div>

<!-- ── Biaya Aktivasi Outlet ──────────────────────────── -->
<div class="activation-card">
  <div>
    <div class="ac-label">Biaya Aktivasi Outlet</div>
    <div class="ac-value" id="activationFeeDisplay">—</div>
    <div class="ac-sub">Diinput langsung saat provisioning di wizard registrasi</div>
  </div>
  <div style="text-align:right;">
    <div style="font-size:12px;color:rgba(255,255,255,.3);margin-bottom:8px;">Nilai default untuk wizard</div>
    <button class="sa-btn sa-btn-outline sa-btn-sm" onclick="openDefaultModal()">✏️ Edit Default</button>
  </div>
</div>

<!-- ── Coin Bundle ────────────────────────────────────── -->
<div class="sa-card">
  <div class="sa-card-header">
    <h3>Paket Topup Coin</h3>
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

<!-- ══ MODAL: EDIT DEFAULT AKTIVASI ══════════════════════ -->
<div class="sa-modal-overlay" id="defaultModal">
  <div class="sa-modal" style="max-width:420px;">
    <h3>✏️ Edit Default Aktivasi</h3>
    <p style="font-size:12.5px;color:rgba(255,255,255,.4);margin-bottom:18px;line-height:1.6;">
      Nilai ini akan muncul sebagai <em>default</em> di Step 2 wizard registrasi.
      Bisa diubah per-klien saat provisioning.
    </p>

    <div class="fg-row">
      <div class="fg">
        <label>Biaya Aktivasi Outlet (Rp)</label>
        <input type="text" id="defFee" inputmode="numeric" placeholder="300.000"
               oninput="ribuan(this);previewDiscount()" autocomplete="off"/>
      </div>
      <div class="fg">
        <label>Diskon (%)</label>
        <input type="number" id="defDiscount" min="0" max="100" step="1" placeholder="0" oninput="previewDiscount()"/>
        <span class="fg-hint" id="defDiscountPreview"></span>
      </div>
    </div>
    <div class="fg">
      <label>Coin Awal Dikreditkan</label>
      <input type="text" id="defCoin" inputmode="numeric" placeholder="50.000"
             oninput="ribuan(this)" autocomplete="off"/>
    </div>

    <div class="sa-modal-footer">
      <button class="sa-btn sa-btn-outline" onclick="closeModal('defaultModal')">Batal</button>
      <button class="sa-btn sa-btn-primary" onclick="saveDefaults()">💾 Simpan</button>
    </div>
  </div>
</div>

<!-- ══ MODAL: BUNDLE ══════════════════════════════════════ -->
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
const rp   = n => 'Rp ' + parseInt(n||0).toLocaleString('id-ID');
const coin = n => parseInt(n||0).toLocaleString('id-ID');
function esc(s){ const d=document.createElement('div'); d.textContent=String(s??''); return d.innerHTML; }

// ── Thousand-separator formatter ──────────────────────
function ribuan(el) {
  const raw    = el.value.replace(/\D/g, '');
  el.dataset.raw = raw;
  el.value = raw ? parseInt(raw).toLocaleString('id-ID') : '';
}
function rawInt(id) {
  const el = document.getElementById(id);
  return parseInt((el?.dataset.raw ?? el?.value ?? '').replace(/\D/g, '') || '0') || 0;
}

// ── Defaults (stored in localStorage for now) ─────────
const DEF_KEY = 'sa_activation_defaults';
function loadDefaults() {
  try { return JSON.parse(localStorage.getItem(DEF_KEY)) || {}; } catch { return {}; }
}
function saveDefaultsToStorage(fee, discount, coinAwal) {
  localStorage.setItem(DEF_KEY, JSON.stringify({ fee, discount, coinAwal }));
}

function refreshActivationCard() {
  const d        = loadDefaults();
  const fee      = parseInt(d.fee)      || 0;
  const discount = parseFloat(d.discount) || 0;
  const final    = Math.round(fee * (1 - discount / 100));
  let display    = fee > 0 ? 'Rp ' + parseInt(fee).toLocaleString('id-ID') : 'Gratis';
  if (discount > 0 && fee > 0) {
    display += ` <span style="font-size:14px;color:rgba(255,255,255,.35);text-decoration:line-through">${display}</span>`
             + ` → <span style="color:#6EE7B7;">Rp ${final.toLocaleString('id-ID')}</span>`
             + ` <span style="font-size:13px;color:#FCD34D;">(−${discount}%)</span>`;
    display = 'Rp ' + parseInt(fee).toLocaleString('id-ID')
            + ` <span style="font-size:16px;color:rgba(255,255,255,.3);text-decoration:line-through;margin-left:8px;"></span>`;
    display = `<span style="font-family:var(--mono);">Rp ${final.toLocaleString('id-ID')}</span>`
            + ` <span style="font-size:13px;color:#FCD34D;margin-left:6px;">−${discount}%</span>`;
  }
  document.getElementById('activationFeeDisplay').innerHTML = display;
}

function openDefaultModal() {
  const d   = loadDefaults();
  const fee = parseInt(d.fee) || 300000;
  const coi = parseInt(d.coinAwal) || 50000;
  const feeEl = document.getElementById('defFee');
  const coiEl = document.getElementById('defCoin');
  feeEl.value = fee.toLocaleString('id-ID'); feeEl.dataset.raw = fee;
  coiEl.value = coi.toLocaleString('id-ID'); coiEl.dataset.raw = coi;
  document.getElementById('defDiscount').value = d.discount ?? 0;
  document.getElementById('defDiscountPreview').textContent = '';
  document.getElementById('defaultModal').classList.add('open');
}

function previewDiscount() {
  const fee      = rawInt('defFee');
  const discount = parseFloat(document.getElementById('defDiscount').value) || 0;
  const hint     = document.getElementById('defDiscountPreview');
  if (fee > 0 && discount > 0) {
    const final = Math.round(fee * (1 - discount / 100));
    hint.textContent = `Harga setelah diskon: Rp ${final.toLocaleString('id-ID')}`;
    hint.style.color = '#6EE7B7';
  } else {
    hint.textContent = '';
  }
}

function saveDefaults() {
  const fee      = rawInt('defFee');
  const discount = parseFloat(document.getElementById('defDiscount').value) || 0;
  const coinA    = rawInt('defCoin');
  saveDefaultsToStorage(fee, discount, coinA);
  refreshActivationCard();
  closeModal('defaultModal');
  saShowToast('Default aktivasi disimpan.', 'success');
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
    tb.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:32px;color:rgba(255,255,255,.3);">Belum ada bundle. Klik ＋ Tambah Bundle.</td></tr>';
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
      <td><span class="pkg-fee">${rp(b.harga)}</span></td>
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

// ── Bundle modal ──────────────────────────────────────
function openBundleModal(bdl = null) {
  document.getElementById('bundleModalTitle').textContent = bdl ? '✏️ Edit Bundle' : '🪙 Tambah Coin Bundle';
  document.getElementById('bdlId').value          = bdl?.id ?? 0;
  document.getElementById('bdlNama').value         = bdl?.nama ?? '';
  document.getElementById('bdlHarga').value        = bdl?.harga ?? '';
  document.getElementById('bdlBonusPct').value     = bdl?.bonus_pct ?? 0;
  document.getElementById('bdlCoinDidapat').value  = bdl?.coin_didapat ?? '';
  document.getElementById('bdlUrutan').value       = bdl?.urutan ?? 0;
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
  const harga  = parseInt(document.getElementById('bdlHarga').value) || 0;
  const bonus  = parseFloat(document.getElementById('bdlBonusPct').value) || 0;
  if (!harga) { document.getElementById('bdlCoinHint').textContent = ''; return; }
  const bonusCoin = Math.round(harga * bonus / 100);
  const total     = harga + bonusCoin;
  document.getElementById('bdlCoinDidapat').value = total;
  document.getElementById('bdlCoinHint').textContent =
    bonus > 0
      ? `${harga.toLocaleString('id-ID')} + bonus ${bonusCoin.toLocaleString('id-ID')} = ${total.toLocaleString('id-ID')} coin`
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

document.querySelectorAll('.sa-modal-overlay').forEach(el => {
  el.addEventListener('click', e => { if (e.target === el) el.classList.remove('open'); });
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') document.querySelectorAll('.sa-modal-overlay.open')
    .forEach(el => el.classList.remove('open'));
});

// Init
refreshActivationCard();
loadBundles();
</script>
</body>
</html>

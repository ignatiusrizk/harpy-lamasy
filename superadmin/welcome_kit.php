<?php
// ══════════════════════════════════════════════════════
// superadmin/welcome_kit.php — Antrian & Konfigurasi Welcome Kit
// ══════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';
require_once dirname(__DIR__) . '/core/BillingConfig.php';
require_once dirname(__DIR__) . '/core/WelcomeKit.php';

date_default_timezone_set('Asia/Jakarta');

$action = $_GET['action'] ?? '';

// ── API LAYER ─────────────────────────────────────────
if ($action) {
    header('Content-Type: application/json');

    // GET: list queue (optional ?status=)
    if ($action === 'list') {
        $status = $_GET['status'] ?? null;
        echo json_encode(['ok' => true, 'rows' => WelcomeKit::listQueue($status ?: null)]);
        exit;
    }

    // GET: get config
    if ($action === 'get_config') {
        echo json_encode([
            'ok'      => true,
            'enabled' => BillingConfig::getInt('welcome_kit_enabled', 1),
            'items'   => WelcomeKit::items(),
        ]);
        exit;
    }

    // POST actions — CSRF required
    saVerifyCsrf();
    $d  = json_decode(file_get_contents('php://input'), true) ?: [];
    $sa = (int)($_SESSION['superadmin_id'] ?? 0) ?: null;

    // POST: mark_shipped
    if ($action === 'mark_shipped') {
        $id   = (int)($d['id']   ?? 0);
        $kurir = trim($d['kurir'] ?? '');
        $resi  = trim($d['resi']  ?? '');
        if ($id < 1 || $kurir === '' || $resi === '') {
            echo json_encode(['error' => 'ID, kurir, dan resi wajib diisi.']);
            exit;
        }
        WelcomeKit::markShipped($id, $kurir, $resi);
        echo json_encode(['ok' => true, 'msg' => 'Ditandai dikirim.']);
        exit;
    }

    // POST: mark_delivered
    if ($action === 'mark_delivered') {
        $id = (int)($d['id'] ?? 0);
        if ($id < 1) { echo json_encode(['error' => 'ID tidak valid.']); exit; }
        WelcomeKit::markDelivered($id);
        echo json_encode(['ok' => true, 'msg' => 'Ditandai terkirim.']);
        exit;
    }

    // POST: save_config
    if ($action === 'save_config') {
        $enabled    = !empty($d['enabled']) ? '1' : '0';
        $cleanItems = [];
        foreach ((array)($d['items'] ?? []) as $it) {
            $n = trim($it['nama'] ?? '');
            if ($n === '') continue;
            $cleanItems[] = ['nama' => substr($n, 0, 120), 'qty' => max(1, (int)($it['qty'] ?? 1))];
        }
        BillingConfig::set('welcome_kit_enabled', $enabled, $sa);
        BillingConfig::set('welcome_kit_items', json_encode($cleanItems, JSON_UNESCAPED_UNICODE), $sa);
        echo json_encode(['ok' => true, 'msg' => 'Konfigurasi kit berhasil disimpan.']);
        exit;
    }

    echo json_encode(['error' => 'Aksi tidak dikenal.']);
    exit;
}

// ── HTML PAGE ─────────────────────────────────────────
$activePage = 'welcome_kit';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php saRenderHead('Welcome Kit'); ?>
<style>
/* ── Tab nav ── */
.wk-tabs { display: flex; gap: 6px; margin-bottom: 24px; border-bottom: 1px solid var(--crease); padding-bottom: 0; }
.wk-tab {
  padding: 10px 18px; font-size: 13px; font-weight: 600;
  color: var(--ash); background: none; border: none;
  border-bottom: 2px solid transparent; cursor: pointer;
  transition: color .15s, border-color .15s;
  letter-spacing: .01em; margin-bottom: -1px;
}
.wk-tab:hover { color: var(--glow); }
.wk-tab.active { color: var(--teal); border-bottom-color: var(--teal); }

/* ── Tab panes ── */
.wk-pane { display: none; }
.wk-pane.active { display: block; }

/* ── Filter bar ── */
.wk-filter-bar { display: flex; gap: 8px; margin-bottom: 18px; flex-wrap: wrap; align-items: center; }
.wk-filter-bar select {
  padding: 7px 12px; background: var(--crease-soft);
  border: 1.5px solid var(--crease); border-radius: 8px;
  color: var(--ink); font-family: var(--font); font-size: 13px; outline: none;
  cursor: pointer;
}
.wk-filter-bar select option { background: var(--navy); }
.wk-filter-bar select:focus { border-color: var(--teal); }

/* ── Status badge ── */
.kit-badge {
  display: inline-block; padding: 2px 9px; border-radius: 20px;
  font-size: 10.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
}
.kit-badge.pending   { background: rgba(245,158,11,.14); color: #F59E0B; border: 1px solid rgba(245,158,11,.3); }
.kit-badge.shipped   { background: rgba(53,232,213,.10); color: var(--teal); border: 1px solid rgba(53,232,213,.3); }
.kit-badge.delivered { background: rgba(132,204,22,.12); color: var(--sage); border: 1px solid rgba(132,204,22,.3); }
.kit-badge.cancelled { background: rgba(244,63,94,.10); color: var(--coral); border: 1px solid rgba(244,63,94,.3); }

/* ── Items compact list ── */
.kit-items-list { font-size: 12px; color: var(--ash); line-height: 1.6; }

/* ── Form groups (reuse packages.php style) ── */
.fg { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
.fg label { font-size: 10px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: var(--ash); }
.fg input, .fg select {
  padding: 9px 12px; background: var(--crease-soft);
  border: 1.5px solid var(--crease); border-radius: 8px;
  color: var(--white); font-family: var(--font); font-size: 13px; outline: none;
  transition: border-color .15s;
}
.fg input:focus, .fg select:focus { border-color: var(--teal); box-shadow: 0 0 0 3px rgba(53,232,213,.12); }

/* ── Config section ── */
.cfg-section { margin-bottom: 28px; }
.cfg-section h4 { font-size: 13px; font-weight: 700; color: var(--glow); margin-bottom: 14px; }

/* ── Item rows in config ── */
.item-row {
  display: flex; gap: 10px; align-items: center; margin-bottom: 8px;
}
.item-row input[type="text"] {
  flex: 1; padding: 8px 11px; background: var(--crease-soft);
  border: 1.5px solid var(--crease); border-radius: 8px;
  color: var(--white); font-family: var(--font); font-size: 13px; outline: none;
}
.item-row input[type="number"] {
  width: 72px; padding: 8px 10px; background: var(--crease-soft);
  border: 1.5px solid var(--crease); border-radius: 8px;
  color: var(--white); font-family: var(--font); font-size: 13px; outline: none;
  text-align: center;
}
.item-row input:focus { border-color: var(--teal); }
.item-row .remove-btn {
  background: rgba(244,63,94,.1); border: 1px solid rgba(244,63,94,.25);
  color: var(--coral); border-radius: 7px; padding: 6px 10px; cursor: pointer;
  font-size: 13px; line-height: 1; transition: background .12s;
}
.item-row .remove-btn:hover { background: rgba(244,63,94,.2); }

/* ── Toggle switch ── */
.sw { position: relative; display: inline-block; width: 36px; height: 20px; }
.sw input { opacity: 0; width: 0; height: 0; }
.sw-track {
  position: absolute; inset: 0; border-radius: 20px; cursor: pointer;
  background: var(--crease); transition: background .2s;
}
.sw-track::after {
  content: ''; position: absolute; left: 3px; top: 3px;
  width: 14px; height: 14px; border-radius: 50%;
  background: var(--ash); transition: transform .2s, background .2s;
}
.sw input:checked + .sw-track { background: rgba(53,232,213,.6); }
.sw input:checked + .sw-track::after { transform: translateX(16px); background: #fff; }

.enable-row {
  display: flex; align-items: center; gap: 12px;
  margin-bottom: 20px; padding: 14px 18px;
  background: var(--teal-faint); border: 1px solid rgba(53,232,213,.2);
  border-radius: 10px;
}
.enable-row .enable-label { font-size: 14px; font-weight: 600; color: var(--glow); }
.enable-row .enable-hint  { font-size: 12px; color: var(--ash); margin-top: 2px; }
</style>
</head>
<body>
<div class="sa-layout">
<?php saRenderNav('welcome_kit', 'Welcome Kit'); ?>

<div class="sa-page-header">
  <h1>📦 Welcome Kit</h1>
  <p>Antrian fulfillment pengiriman kit sambutan ke outlet baru + konfigurasi isi kit</p>
</div>

<!-- ── TABS ──────────────────────────────────────────── -->
<div class="wk-tabs">
  <button class="wk-tab active" onclick="switchTab('antrian', this)">📋 Antrian Pengiriman</button>
  <button class="wk-tab" onclick="switchTab('config', this)">⚙️ Konfigurasi Kit</button>
</div>

<!-- ══ TAB: ANTRIAN ═══════════════════════════════════ -->
<div class="wk-pane active" id="pane-antrian">

  <div class="wk-filter-bar">
    <select id="statusFilter" onchange="loadQueue()">
      <option value="">Semua Status</option>
      <option value="pending">Pending</option>
      <option value="shipped">Dikirim</option>
      <option value="delivered">Terkirim</option>
      <option value="cancelled">Dibatalkan</option>
    </select>
    <button class="sa-btn sa-btn-outline sa-btn-sm" onclick="loadQueue()">🔄 Refresh</button>
  </div>

  <div class="sa-card">
    <div style="overflow-x:auto;">
      <table class="sa-table" style="min-width:900px;">
        <thead>
          <tr>
            <th style="white-space:nowrap;">#</th>
            <th style="white-space:nowrap;">Outlet / Tenant</th>
            <th style="white-space:nowrap;">Penerima</th>
            <th style="white-space:nowrap;">Alamat Pengiriman</th>
            <th style="white-space:nowrap;">Isi Kit</th>
            <th style="white-space:nowrap;">Status</th>
            <th style="white-space:nowrap;">Kurir / Resi</th>
            <th style="white-space:nowrap;">Aksi</th>
          </tr>
        </thead>
        <tbody id="queueBody">
          <tr><td colspan="8" style="text-align:center;padding:36px;color:var(--ash-dim);">Memuat...</td></tr>
        </tbody>
      </table>
    </div>
  </div>

</div><!-- /#pane-antrian -->

<!-- ══ TAB: KONFIGURASI ═══════════════════════════════ -->
<div class="wk-pane" id="pane-config">

  <div class="sa-card" style="max-width:600px;">

    <!-- Toggle enabled -->
    <div class="enable-row">
      <label class="sw" title="Aktif/nonaktifkan Welcome Kit">
        <input type="checkbox" id="cfgEnabled"/>
        <span class="sw-track"></span>
      </label>
      <div>
        <div class="enable-label">Aktifkan Welcome Kit</div>
        <div class="enable-hint">Bila aktif, kit dibuat otomatis saat outlet baru diaktivasi.</div>
      </div>
    </div>

    <!-- Item list -->
    <div class="cfg-section">
      <h4>Isi Kit</h4>
      <div style="font-size:11.5px;color:var(--ash-dim);margin-bottom:12px;">
        Masukkan nama item dan jumlah. Ini jadi snapshot <em>items_json</em> untuk setiap kit baru.
      </div>

      <div style="display:flex;gap:8px;margin-bottom:6px;padding:0 2px;">
        <span style="flex:1;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ash-dim);">Nama Item</span>
        <span style="width:72px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--ash-dim);text-align:center;">Qty</span>
        <span style="width:36px;"></span>
      </div>

      <div id="itemRows"></div>

      <button class="sa-btn sa-btn-outline sa-btn-sm" onclick="addItemRow()" style="margin-top:6px;">
        ＋ Tambah Item
      </button>
    </div>

    <!-- Save button -->
    <div class="sa-modal-footer" style="padding:0;margin-top:8px;">
      <button class="sa-btn sa-btn-primary" onclick="saveConfig()">💾 Simpan Konfigurasi</button>
    </div>

  </div>

</div><!-- /#pane-config -->

<!-- ══ MODAL: TANDAI DIKIRIM ═══════════════════════════ -->
<div class="sa-modal-overlay" id="shipModal">
  <div class="sa-modal" style="max-width:440px;">
    <h3>📦 Tandai Dikirim</h3>
    <p style="font-size:12.5px;color:var(--ash);margin-bottom:18px;line-height:1.6;">
      Masukkan kurir dan nomor resi untuk kit ini.
    </p>
    <input type="hidden" id="shipId"/>

    <div class="fg">
      <label>Kurir *</label>
      <input type="text" id="shipKurir" placeholder="JNE, TIKI, SiCepat, J&T, Pos …" maxlength="60"/>
    </div>
    <div class="fg">
      <label>Nomor Resi *</label>
      <input type="text" id="shipResi" placeholder="Contoh: JNE123456789" maxlength="80"
             onkeydown="if(event.key==='Enter')doMarkShipped()"/>
    </div>

    <div class="sa-modal-footer">
      <button class="sa-btn sa-btn-outline" onclick="closeModal('shipModal')">Batal</button>
      <button class="sa-btn sa-btn-primary" onclick="doMarkShipped()">✅ Tandai Dikirim</button>
    </div>
  </div>
</div>

<?php saRenderNavClose(); ?>

<script>
function esc(s){ const d=document.createElement('div'); d.textContent=String(s??''); return d.innerHTML; }

// ── Tab switching ─────────────────────────────────────
function switchTab(name, btn) {
  document.querySelectorAll('.wk-tab').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.wk-pane').forEach(p => p.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('pane-' + name).classList.add('active');
  if (name === 'config') loadConfig();
}

// ── Format items_json compact ─────────────────────────
function fmtItems(json) {
  let arr = [];
  try { arr = JSON.parse(json || '[]'); } catch(e) {}
  if (!arr.length) return '<span style="color:var(--ash-dim);">—</span>';
  return '<div class="kit-items-list">' +
    arr.map(it => `${parseInt(it.qty)||1}× ${esc(it.nama)}`).join('<br>') +
    '</div>';
}

// ── Status badge ──────────────────────────────────────
function fmtStatus(s) {
  const labels = { pending: 'Pending', shipped: 'Dikirim', delivered: 'Terkirim', cancelled: 'Dibatalkan' };
  return `<span class="kit-badge ${esc(s)}">${labels[s]||esc(s)}</span>`;
}

// ── Load queue ────────────────────────────────────────
function loadQueue() {
  const status = document.getElementById('statusFilter').value;
  const url    = 'welcome_kit.php?action=list' + (status ? '&status=' + encodeURIComponent(status) : '');
  const body   = document.getElementById('queueBody');
  body.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:36px;color:var(--ash-dim);">Memuat...</td></tr>';

  saFetch(url).then(d => {
    if (!d.ok) { saShowToast(d.error || 'Gagal memuat data.', 'error'); return; }
    renderQueue(d.rows || []);
  });
}

function renderQueue(rows) {
  const body = document.getElementById('queueBody');
  if (!rows.length) {
    body.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:36px;color:var(--ash-dim);">Tidak ada data antrian.</td></tr>';
    return;
  }
  body.innerHTML = rows.map((r, i) => {
    const addr = [r.alamat, r.kota, r.kode_pos].filter(Boolean).join(', ');
    const kurirResi = r.kurir
      ? `<span style="font-family:var(--mono);font-size:11px;">${esc(r.kurir)}</span><br><span style="font-size:11px;color:var(--ash-dim);">${esc(r.resi||'')}</span>`
      : '<span style="color:var(--ash-dim);">—</span>';

    let aksiBtn = '';
    if (r.status === 'pending') {
      aksiBtn = `<button class="sa-btn sa-btn-sm sa-btn-primary" onclick="openShipModal(${r.id})">📦 Dikirim</button>`;
    } else if (r.status === 'shipped') {
      aksiBtn = `<button class="sa-btn sa-btn-sm sa-btn-outline" onclick="doMarkDelivered(${r.id})">✅ Terkirim</button>`;
    }

    return `<tr>
      <td style="font-size:11px;color:var(--ash-dim);">${r.id}</td>
      <td>
        <div style="font-weight:600;font-size:13px;">${esc(r.nama_outlet||'—')}</div>
        <div style="font-size:11px;color:var(--ash-dim);margin-top:2px;">${esc(r.nama_perusahaan||'')}</div>
      </td>
      <td>
        <div style="font-size:13px;">${esc(r.penerima||'—')}</div>
        ${r.hp ? `<div style="font-size:11px;color:var(--ash-dim);">${esc(r.hp)}</div>` : ''}
      </td>
      <td style="max-width:220px;">
        <div style="font-size:12px;line-height:1.5;">${esc(addr||'—')}</div>
        ${r.catatan ? `<div style="font-size:11px;color:var(--amber);margin-top:3px;">⚠ ${esc(r.catatan)}</div>` : ''}
      </td>
      <td>${fmtItems(r.items_json)}</td>
      <td>${fmtStatus(r.status)}</td>
      <td>${kurirResi}</td>
      <td style="white-space:nowrap;">${aksiBtn}</td>
    </tr>`;
  }).join('');
}

// ── Modal: mark shipped ───────────────────────────────
function openShipModal(id) {
  document.getElementById('shipId').value    = id;
  document.getElementById('shipKurir').value = '';
  document.getElementById('shipResi').value  = '';
  document.getElementById('shipModal').classList.add('open');
  setTimeout(() => document.getElementById('shipKurir').focus(), 100);
}

function doMarkShipped() {
  const id    = parseInt(document.getElementById('shipId').value);
  const kurir = document.getElementById('shipKurir').value.trim();
  const resi  = document.getElementById('shipResi').value.trim();
  if (!kurir || !resi) { saShowToast('Kurir dan resi wajib diisi.', 'error'); return; }

  saFetch('welcome_kit.php?action=mark_shipped', {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify({ id, kurir, resi }),
  }).then(d => {
    if (!d.ok) { saShowToast(d.error || 'Gagal.', 'error'); return; }
    saShowToast(d.msg || 'Ditandai dikirim.', 'success');
    closeModal('shipModal');
    loadQueue();
  });
}

// ── Mark delivered ────────────────────────────────────
function doMarkDelivered(id) {
  if (!confirm('Tandai kit ini sebagai TERKIRIM?')) return;
  saFetch('welcome_kit.php?action=mark_delivered', {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify({ id }),
  }).then(d => {
    if (!d.ok) { saShowToast(d.error || 'Gagal.', 'error'); return; }
    saShowToast(d.msg || 'Ditandai terkirim.', 'success');
    loadQueue();
  });
}

// ── Config: load ──────────────────────────────────────
function loadConfig() {
  saFetch('welcome_kit.php?action=get_config').then(d => {
    if (!d.ok) { saShowToast(d.error || 'Gagal memuat config.', 'error'); return; }
    document.getElementById('cfgEnabled').checked = !!parseInt(d.enabled ?? 1);
    renderItemRows(d.items || []);
  });
}

function renderItemRows(items) {
  const container = document.getElementById('itemRows');
  container.innerHTML = '';
  if (items.length === 0) {
    addItemRow();
    return;
  }
  items.forEach(it => addItemRow(it.nama, it.qty));
}

function addItemRow(nama = '', qty = 1) {
  const container = document.getElementById('itemRows');
  const row = document.createElement('div');
  row.className = 'item-row';
  row.innerHTML = `
    <input type="text" placeholder="Nama item (cth: Roll thermal 58mm)" maxlength="120"/>
    <input type="number" value="${parseInt(qty)||1}" min="1" max="999" placeholder="1"/>
    <button class="remove-btn" title="Hapus baris" onclick="this.closest('.item-row').remove()">✕</button>
  `;
  // Set value via property (bukan interpolasi atribut) — hindari pola rapuh esc() di konteks atribut.
  row.querySelector('input[type="text"]').value = nama;
  container.appendChild(row);
  if (!nama) row.querySelector('input[type="text"]').focus();
}

// ── Config: save ──────────────────────────────────────
function saveConfig() {
  const enabled = document.getElementById('cfgEnabled').checked;
  const items   = [];
  document.querySelectorAll('#itemRows .item-row').forEach(row => {
    const inputs = row.querySelectorAll('input');
    const nama   = inputs[0].value.trim();
    const qty    = parseInt(inputs[1].value) || 1;
    if (nama) items.push({ nama, qty });
  });

  saFetch('welcome_kit.php?action=save_config', {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify({ enabled, items }),
  }).then(d => {
    if (!d.ok) { saShowToast(d.error || 'Gagal menyimpan.', 'error'); return; }
    saShowToast(d.msg || 'Konfigurasi disimpan.', 'success');
  });
}

// ── Modal helpers ─────────────────────────────────────
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

// ── Init ──────────────────────────────────────────────
loadQueue();
</script>
</body>
</html>

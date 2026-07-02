<?php
// ══════════════════════════════════════════════════════
// hq/struk.php — Master Template Struk (HQ View)
// Owner bisa buat template "standar brand" dan push
// ke semua outlet sekaligus.
// ══════════════════════════════════════════════════════

$activePage = 'hq-struk';
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';
require_once ROOT . '/core/StrukGenerator.php';

$db  = Database::get();
$tid = (int)$hqTenant['id'];

// ── AJAX actions ──────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');

    // Daftar outlet beserta status template-nya
    if ($action === 'list_outlets') {
        $rows = $db->prepare(
            "SELECT o.id, o.nama_outlet, o.kota, o.status,
                    MAX(CASE WHEN st.tipe='retail' THEN st.updated_at END) AS retail_updated,
                    MAX(CASE WHEN st.tipe='b2b'    THEN st.updated_at END) AS b2b_updated
               FROM outlets o
               LEFT JOIN hl_struk_template st ON st.outlet_id = o.id AND st.tenant_id = ?
              WHERE o.tenant_id = ? AND o.status NOT IN ('closed')
              GROUP BY o.id
              ORDER BY o.is_main DESC, o.nama_outlet ASC"
        );
        $rows->execute([$tid, $tid]);
        echo json_encode($rows->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // Ambil template (dari outlet tertentu atau master dummy)
    if ($action === 'get_template') {
        $tipe = $_GET['tipe'] ?? 'retail';
        $oid  = (int)($_GET['outlet_id'] ?? 0);
        if (!in_array($tipe, ['retail','b2b'])) { echo json_encode(['error'=>'Tipe invalid']); exit; }

        if ($oid) {
            // Validasi outlet milik tenant
            $v = $db->prepare("SELECT id FROM outlets WHERE id=? AND tenant_id=? LIMIT 1");
            $v->execute([$oid, $tid]);
            if (!$v->fetchColumn()) { echo json_encode(['error'=>'Outlet tidak ditemukan']); exit; }
            $tmpl = StrukGenerator::loadTemplate($tid, $oid, $tipe);
        } else {
            // "Master" → ambil dari outlet utama sebagai referensi
            $mainOid = $db->prepare("SELECT id FROM outlets WHERE tenant_id=? AND is_main=1 LIMIT 1");
            $mainOid->execute([$tid]);
            $mainId = (int)$mainOid->fetchColumn();
            $tmpl = $mainId ? StrukGenerator::loadTemplate($tid, $mainId, $tipe) : [];
        }
        echo json_encode(['template' => $tmpl]);
        exit;
    }

    // Push template ke semua outlet (atau outlet tertentu)
    if ($action === 'push' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$hqIsOwner) { echo json_encode(['error'=>'Hanya owner yang bisa push template']); exit; }
        verifyCsrf();

        $body    = json_decode(file_get_contents('php://input'), true) ?? [];
        $tipe    = $body['tipe'] ?? 'retail';
        $targets = $body['targets'] ?? 'all'; // 'all' | [outlet_id, ...]
        if (!in_array($tipe, ['retail','b2b'])) { echo json_encode(['error'=>'Tipe invalid']); exit; }

        // Field whitelist
        $bools = [
            'show_logo','show_alamat','show_telp','show_email',
            'show_no_order','show_tanggal','show_nama_kasir','show_nama_pelanggan',
            'show_telp_pelanggan','show_alamat_pelanggan','show_detail_item',
            'show_subtotal','show_diskon','show_dp','show_total',
            'show_metode_bayar','show_sisa_bayar','show_estimasi','show_catatan',
            'show_poin_earned','show_saldo_poin',
            'show_periode_invoice','show_jatuh_tempo','show_rekening',
            'show_footer_ucapan','show_footer_syarat','show_footer_sosmed',
            'show_qr_wa','show_border','show_watermark',
        ];
        $strings = [
            'format','logo_size','tagline',
            'show_alamat','show_telp',
            'footer_ucapan','footer_syarat','footer_sosmed','footer_extra',
            'rekening_bank','rekening_nomor','rekening_atas_nama',
            'font_size',
        ];

        $data = ['tipe' => $tipe];
        foreach ($bools as $f) {
            if (array_key_exists($f, $body)) $data[$f] = empty($body[$f]) ? 0 : 1;
        }
        foreach ($strings as $f) {
            if (array_key_exists($f, $body)) {
                $data[$f] = substr(trim((string)($body[$f] ?? '')), 0, 500) ?: null;
            }
        }
        // Note: nama_outlet, alamat_override, logo_url TIDAK di-push
        // agar masing-masing outlet tetap bisa punya identitas sendiri

        // Ambil daftar outlet yang akan di-push
        if ($targets === 'all') {
            $oidSt = $db->prepare("SELECT id FROM outlets WHERE tenant_id=? AND status NOT IN ('closed')");
            $oidSt->execute([$tid]);
            $outletIds = $oidSt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            // Validasi semua outlet ID milik tenant ini
            $placeholders = implode(',', array_fill(0, count($targets), '?'));
            $oidSt = $db->prepare("SELECT id FROM outlets WHERE tenant_id=? AND id IN ($placeholders)");
            $oidSt->execute(array_merge([$tid], (array)$targets));
            $outletIds = $oidSt->fetchAll(PDO::FETCH_COLUMN);
        }

        $pushed = 0;
        foreach ($outletIds as $oid) {
            try {
                StrukGenerator::saveTemplate($tid, (int)$oid, $tipe, $data);
                $pushed++;
            } catch (Throwable $e) {
                error_log("[hq/struk push] outlet=$oid: " . $e->getMessage());
            }
        }

        echo json_encode(['success' => true, 'pushed' => $pushed, 'total' => count($outletIds)]);
        exit;
    }

    // Preview dengan data dummy
    if ($action === 'preview') {
        header('Content-Type: text/html; charset=utf-8');
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $tipe = $body['tipe'] ?? 'retail';
        try {
            $tmpl = array_merge(
                StrukGenerator::loadTemplate($tid,
                    (int)($body['outlet_id'] ?? 0) ?: $tid,
                    $tipe),
                $body
            );
            // Preview dari konteks HQ: gunakan outlet utama sebagai basis
            $mainOid = $db->prepare("SELECT id FROM outlets WHERE tenant_id=? AND is_main=1 LIMIT 1");
            $mainOid->execute([$tid]);
            $mainId = (int)$mainOid->fetchColumn();
            if ($mainId) {
                $_SESSION['outlet_id'] = $mainId; // temp
                TenantResolver::reset();
            }
            echo StrukGenerator::preview($tmpl, $tipe);
        } catch (Throwable $e) {
            echo '<p style="color:red">' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        exit;
    }

    echo json_encode(['error' => 'Action tidak dikenal']); exit;
}

// ── Page ──────────────────────────────────────────────
$pageTitle = 'Master Template Struk';
require __DIR__ . '/_layout_open.php';
?>
<style>
/* Inherit dari struk.php outlet, tambah HQ specifics */
.tab-bar{ display:flex; gap:6px; margin-bottom:16px; }
.tab-btn{ padding:9px 22px; border-radius:8px; font-size:13px; font-weight:700;
          border:1.5px solid rgba(27,45,90,.15); background:#fff; color:var(--dark,#1a1a2e);
          cursor:pointer; transition:all .15s; }
.tab-btn.active{ background:#0F1C3A; color:#fff; border-color:#0F1C3A; }

.hq-struk-grid{ display:grid; grid-template-columns:380px minmax(0,1fr); gap:16px; align-items:start; }
/* Cegah track/panel melebar mengikuti lebar iframe asli (yg di-scale) → tak meluber */
.hq-struk-grid > *{ min-width:0; }
@media(max-width:960px){ .hq-struk-grid{ grid-template-columns:1fr; } }

.settings-panel{ background:#fff; border-radius:12px; border:1px solid rgba(27,45,90,.08);
                 overflow:hidden; box-shadow:0 1px 6px rgba(0,0,0,.04); }
.settings-inner{ padding:16px; max-height:calc(100vh - 220px); overflow-y:auto; }

.section-lbl{ font-size:11px; font-weight:800; text-transform:uppercase; color:#6B7280;
              letter-spacing:.7px; margin:16px 0 8px; padding-bottom:5px;
              border-bottom:1px solid rgba(27,45,90,.08); }
.check-row{ display:flex; align-items:center; gap:10px; padding:6px 0;
            border-bottom:1px solid rgba(27,45,90,.05); }
.check-row:last-child{ border-bottom:none; }
.check-row input[type=checkbox]{ width:15px; height:15px; accent-color:#35E8D5; flex-shrink:0; }
.check-row label{ font-size:13px; cursor:pointer; flex:1; }
.form-field{ margin-bottom:10px; }
.form-field label{ display:block; font-size:12px; font-weight:600; margin-bottom:4px; }
.form-field input,.form-field select,.form-field textarea{
  width:100%; padding:7px 10px; border:1.5px solid rgba(27,45,90,.12); border-radius:8px;
  font-size:13px; font-family:inherit; outline:none; transition:border-color .15s; }
.form-field input:focus,.form-field select:focus,.form-field textarea:focus{ border-color:#35E8D5; }

.action-bar{ padding:12px 16px; border-top:1px solid rgba(27,45,90,.08); background:#fafbfc;
             display:flex; gap:8px; flex-wrap:wrap; }

.preview-panel{ position:sticky; top:16px; }
.preview-card{ background:#fff; border-radius:12px; border:1px solid rgba(27,45,90,.08);
               padding:14px; margin-bottom:14px; box-shadow:0 1px 6px rgba(0,0,0,.04); }
.preview-card h3{ font-size:13px; font-weight:700; color:#0F1C3A; margin-bottom:10px; }
.preview-frame-wrap{ background:#f4f6fb; border-radius:8px; overflow:hidden; }
.preview-frame-wrap iframe{ width:100%; border:none; min-height:400px; }

/* Outlet list */
.outlet-list{ background:#fff; border-radius:12px; border:1px solid rgba(27,45,90,.08);
              padding:16px; margin-top:16px; box-shadow:0 1px 6px rgba(0,0,0,.04); }
.outlet-list h3{ font-size:14px; font-weight:700; color:#0F1C3A; margin-bottom:12px; }
.outlet-item{ display:flex; align-items:center; gap:10px; padding:8px 0;
              border-bottom:1px solid rgba(27,45,90,.06); }
.outlet-item:last-child{ border-bottom:none; }
.outlet-item input[type=checkbox]{ width:15px; height:15px; accent-color:#35E8D5; }
.outlet-item .name{ flex:1; font-size:13px; font-weight:600; color:#0F1C3A; }
.outlet-item .meta{ font-size:11px; color:#9CA3AF; }

.btn{ display:inline-flex; align-items:center; gap:5px; padding:8px 16px;
      border-radius:8px; font-size:13px; font-weight:700; border:none; cursor:pointer;
      text-decoration:none; font-family:inherit; transition:filter .15s; }
.btn:hover{ filter:brightness(.95); }
.btn-primary{ background:#35E8D5; color:#0F1C3A; }
.btn-navy   { background:#0F1C3A; color:#fff; }
.btn-light  { background:#F3F4F6; color:#1a1a2e; }
.btn-sm{ padding:6px 12px; font-size:12px; }

.alert{ padding:10px 14px; border-radius:8px; font-size:13px; margin-bottom:12px; }
.alert-ok  { background:#D1FAE5; color:#065F46; }
.alert-err { background:#FEE2E2; color:#991B1B; }
</style>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
  <div>
    <h1 style="font-size:1.3rem;font-weight:800;color:#0F1C3A">🧾 Master Template Struk</h1>
    <div style="font-size:13px;color:#6B7280;margin-top:2px">
      Atur template standar brand lalu push ke semua outlet sekaligus
    </div>
  </div>
</div>

<div class="tab-bar">
  <button class="tab-btn active" onclick="switchTab('retail')" id="tab-retail">🧾 Retail</button>
  <button class="tab-btn"        onclick="switchTab('b2b')"    id="tab-b2b">📄 B2B / Invoice</button>
</div>

<div id="alertMsg" style="display:none"></div>

<div class="hq-struk-grid">

  <!-- ── Settings ── -->
  <div>
    <div class="settings-panel">
      <div class="settings-inner" id="settingsPanel">
        <div style="text-align:center;padding:40px;color:#9CA3AF">⏳ Memuat…</div>
      </div>
      <div class="action-bar">
        <button class="btn btn-primary" onclick="pushToAll()">🚀 Push ke Semua Outlet</button>
        <button class="btn btn-light btn-sm" onclick="pushSelected()">📌 Push ke Outlet Dipilih</button>
        <button class="btn btn-light btn-sm" onclick="refreshPreview()">🔄 Preview</button>
      </div>
    </div>

    <!-- Outlet list untuk push selektif -->
    <div class="outlet-list">
      <h3>Outlet Target <span id="outletSelCount" style="font-weight:400;color:#9CA3AF;font-size:12px"></span></h3>
      <div style="margin-bottom:10px">
        <label style="font-size:12px;cursor:pointer">
          <input type="checkbox" id="checkAll" onchange="toggleAllOutlets(this.checked)"> Pilih semua
        </label>
      </div>
      <div id="outletListEl">
        <div style="color:#9CA3AF;font-size:13px">⏳ Memuat outlet…</div>
      </div>
    </div>
  </div>

  <!-- ── Preview ── -->
  <div class="preview-panel">
    <div class="preview-card">
      <h3>👁 Preview <span id="previewFmtLbl" style="font-weight:400;color:#9CA3AF;font-size:11px"></span></h3>
      <div class="preview-frame-wrap">
        <iframe id="previewFrame" title="Preview"></iframe>
      </div>
      <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-navy btn-sm" onclick="printPreview()">🖨 Test Print</button>
        <a id="openPreviewLink" href="#" target="_blank" class="btn btn-light btn-sm">↗ Buka Penuh</a>
      </div>
    </div>
  </div>

</div><!-- /grid -->

<script>
const CSRF       = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
let activeTab    = 'retail';
let outletList   = [];
let debounce;

// ══════════════════════════════════════════════════
function switchTab(tipe) {
  activeTab = tipe;
  ['retail','b2b'].forEach(t =>
    document.getElementById('tab-' + t).classList.toggle('active', t === tipe)
  );
  loadTemplate(tipe);
}

// ══════════════════════════════════════════════════
async function loadTemplate(tipe) {
  document.getElementById('settingsPanel').innerHTML =
    '<div style="text-align:center;padding:30px;color:#9CA3AF">⏳ Memuat…</div>';

  // Ambil dari outlet utama sebagai referensi master
  const r = await fetch(`/hq/struk.php?action=get_template&tipe=${tipe}`);
  const j = await r.json();
  if (j.error) { showAlert(j.error, 'err'); return; }
  renderForm(tipe, j.template || {});
  refreshPreview();
}

// ══════════════════════════════════════════════════
function renderForm(tipe, t) {
  const isB2b = tipe === 'b2b';
  const v   = (k, def='') => (t[k] !== null && t[k] !== undefined) ? t[k] : def;
  const chk = (k) => parseInt(v(k, 0)) === 1 ? 'checked' : '';
  const sel = (k, opts, def='') => opts.map(o =>
    `<option value="${o.v}" ${v(k,def)===o.v?'selected':''}>${o.l}</option>`).join('');

  let html = `
  <div class="section-lbl">📐 Format & Font</div>
  <div class="form-field">
    <label>Format Output</label>
    <select id="f_format" onchange="onField()">
      <option value="thermal_58" ${v('format')==='thermal_58'?'selected':''}>🖨 Thermal 58mm</option>
      <option value="thermal_80" ${v('format')==='thermal_80'?'selected':''}>🖨 Thermal 80mm</option>
      <option value="a5"         ${v('format')==='a5'?'selected':''}>📄 PDF A5</option>
      <option value="a4"         ${v('format')==='a4'?'selected':''}>📄 PDF A4</option>
    </select>
  </div>
  <div class="form-field">
    <label>Ukuran Font</label>
    <select id="f_font_size" onchange="onField()">
      ${sel('font_size',[{v:'small',l:'Kecil'},{v:'normal',l:'Normal'},{v:'large',l:'Besar'}],'normal')}
    </select>
  </div>

  <div class="section-lbl">📋 Header</div>
  ${cfChk('show_logo', 'Tampilkan Logo', t)}
  <div class="form-field" style="margin-top:8px">
    <label>Ukuran Logo</label>
    <select id="f_logo_size" onchange="onField()">
      ${sel('logo_size',[{v:'small',l:'Kecil'},{v:'medium',l:'Sedang'},{v:'large',l:'Besar'}],'medium')}
    </select>
  </div>
  <div class="form-field">
    <label>Tagline Brand</label>
    <input type="text" id="f_tagline" value="${esc(v('tagline'))}" maxlength="200"
           placeholder="cth: Bersih, Cepat, Terpercaya!" oninput="onField()">
  </div>
  ${cfChk('show_alamat', 'Tampilkan Alamat', t)}
  ${cfChk('show_telp',   'Tampilkan Telepon Outlet', t)}
  <div class="form-field" style="margin-top:8px">
    <label>Teks Tambahan Header</label>
    <input type="text" id="f_header_extra" value="${esc(v('header_extra'))}" maxlength="200" oninput="onField()">
  </div>

  <div class="section-lbl">📝 Isi Struk</div>
  ${cfChk('show_no_order',        'Nomor Order', t)}
  ${cfChk('show_tanggal',         'Tanggal & Waktu', t)}
  ${cfChk('show_nama_kasir',      'Nama Kasir', t)}
  ${cfChk('show_nama_pelanggan',  'Nama Pelanggan', t)}
  ${cfChk('show_telp_pelanggan',  'Telepon Pelanggan', t)}
  ${isB2b ? cfChk('show_alamat_pelanggan', 'Alamat Pelanggan', t) : ''}
  ${cfChk('show_detail_item',     'Detail Item', t)}
  ${cfChk('show_subtotal',        'Subtotal', t)}
  ${cfChk('show_diskon',          'Diskon', t)}
  ${cfChk('show_dp',              'DP', t)}
  ${cfChk('show_total',           'Total', t)}
  ${cfChk('show_metode_bayar',    'Metode Bayar', t)}
  ${cfChk('show_sisa_bayar',      'Sisa Bayar', t)}
  ${!isB2b ? cfChk('show_estimasi',    'Estimasi Selesai', t) : ''}
  ${cfChk('show_catatan',         'Catatan Order', t)}
  ${!isB2b ? cfChk('show_poin_earned', 'Poin Didapat', t) : ''}
  ${!isB2b ? cfChk('show_saldo_poin',  'Saldo Poin', t) : ''}
  ${isB2b ? `
    ${cfChk('show_jatuh_tempo',   'Tanggal Jatuh Tempo', t)}
    ${cfChk('show_rekening',      'Info Rekening Pembayaran', t)}
    <div class="form-field" style="margin-top:8px">
      <label>Bank</label>
      <input type="text" id="f_rekening_bank" value="${esc(v('rekening_bank'))}" maxlength="50" oninput="onField()">
    </div>
    <div class="form-field">
      <label>Nomor Rekening</label>
      <input type="text" id="f_rekening_nomor" value="${esc(v('rekening_nomor'))}" maxlength="50" oninput="onField()">
    </div>
    <div class="form-field">
      <label>Atas Nama</label>
      <input type="text" id="f_rekening_atas_nama" value="${esc(v('rekening_atas_nama'))}" maxlength="100" oninput="onField()">
    </div>
  ` : ''}

  <div class="section-lbl">🙏 Footer</div>
  ${cfChk('show_footer_ucapan', 'Ucapan Terima Kasih', t)}
  <div class="form-field" style="margin-top:8px">
    <label>Teks Ucapan</label>
    <textarea id="f_footer_ucapan" maxlength="255" oninput="onField()">${esc(v('footer_ucapan'))}</textarea>
  </div>
  ${cfChk('show_footer_sosmed', 'Sosial Media / Kontak', t)}
  <div class="form-field" style="margin-top:8px">
    <label>Teks Sosmed</label>
    <input type="text" id="f_footer_sosmed" value="${esc(v('footer_sosmed'))}" maxlength="200" oninput="onField()">
  </div>
  ${cfChk('show_footer_syarat', 'Syarat & Ketentuan', t)}
  <div class="form-field" style="margin-top:8px">
    <label>Teks Syarat</label>
    <textarea id="f_footer_syarat" maxlength="500" rows="2" oninput="onField()">${esc(v('footer_syarat'))}</textarea>
  </div>

  <div class="section-lbl">🎨 Tampilan</div>
  ${cfChk('show_border',    'Garis Pemisah', t)}
  ${cfChk('show_watermark', 'Watermark COPY', t)}

  <div style="background:#FEF3C7;border:1px solid #FCD34D;border-radius:8px;padding:10px 12px;margin-top:16px;font-size:12px;color:#92400E">
    ⚠️ <strong>Catatan:</strong> Push master tidak menimpa Nama Outlet, Alamat Override, dan Logo di masing-masing outlet — agar tiap outlet tetap punya identitas sendiri.
  </div>
  `;

  document.getElementById('settingsPanel').innerHTML = html;
}

function cfChk(field, label, t) {
  const chk = parseInt((t[field] !== null && t[field] !== undefined) ? t[field] : 0) === 1 ? 'checked' : '';
  return `<div class="check-row">
    <input type="checkbox" id="f_${field}" ${chk} onchange="onField()">
    <label for="f_${field}">${label}</label>
  </div>`;
}

// ══════════════════════════════════════════════════
// Kumpulkan form
// ══════════════════════════════════════════════════
function collectForm() {
  const g  = id => document.getElementById(id);
  const gv = id => { const el=g(id); return el?el.value:null; };
  const gc = id => { const el=g(id); return el?(el.checked?1:0):0; };

  const bools = [
    'show_logo','show_alamat','show_telp',
    'show_no_order','show_tanggal','show_nama_kasir','show_nama_pelanggan',
    'show_telp_pelanggan','show_alamat_pelanggan','show_detail_item',
    'show_subtotal','show_diskon','show_dp','show_total',
    'show_metode_bayar','show_sisa_bayar','show_estimasi','show_catatan',
    'show_poin_earned','show_saldo_poin',
    'show_jatuh_tempo','show_rekening',
    'show_footer_ucapan','show_footer_syarat','show_footer_sosmed',
    'show_border','show_watermark',
  ];
  const strings = [
    'format','logo_size','tagline','header_extra',
    'footer_ucapan','footer_syarat','footer_sosmed',
    'rekening_bank','rekening_nomor','rekening_atas_nama','font_size',
  ];
  const out = { tipe: activeTab };
  bools.forEach(f => out[f] = gc('f_' + f));
  strings.forEach(f => { const v=gv('f_'+f); if(v!==null) out[f]=v; });
  return out;
}

// ══════════════════════════════════════════════════
// Live preview
// ══════════════════════════════════════════════════
function onField() {
  clearTimeout(debounce);
  debounce = setTimeout(refreshPreview, 700);
}

async function refreshPreview() {
  const data = collectForm();
  const fmts = {thermal_58:'58mm',thermal_80:'80mm',a5:'A5',a4:'A4'};
  document.getElementById('previewFmtLbl').textContent = '— ' + (fmts[data.format] || data.format);

  const r    = await fetch('/hq/struk.php?action=preview', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF},
    body: JSON.stringify(data),
  });
  const html = await r.text();
  const frame = document.getElementById('previewFrame');
  const fmt = data.format || 'thermal_80';
  window._previewFmt = fmt;
  const doc = frame.contentDocument || frame.contentWindow.document;
  doc.open(); doc.write(html); doc.close();
  // Skala-agar-muat: render di lebar dokumen asli lalu kecilkan proporsional
  // (biar preview A4/A5 tak meluber & terpotong di layar sempit).
  const applyFit = () => fitPreview(fmt);
  frame.onload = applyFit;
  setTimeout(applyFit, 130);
}

// Lebar dokumen asli dalam px (96dpi): A4 210mm, A5 148mm, thermal 80/58mm.
const PREVIEW_W = { a4:794, a5:559, thermal_80:348, thermal_58:272 };
function fitPreview(fmt){
  const frame = document.getElementById('previewFrame');
  if (!frame) return;
  const wrap = frame.parentElement;
  const wpx  = PREVIEW_W[fmt] || 348;
  frame.style.transform = 'none';
  frame.style.maxWidth  = 'none';
  frame.style.minHeight = '0';
  frame.style.width     = wpx + 'px';
  frame.style.transformOrigin = 'top left';
  let h = 500;
  try { const d = frame.contentDocument; h = Math.max(d.documentElement.scrollHeight, d.body.scrollHeight, 400); } catch(e){}
  frame.style.height = h + 'px';
  const avail = wrap.clientWidth || wpx;
  const scale = Math.min(1, avail / wpx);
  frame.style.transform = scale < 1 ? `scale(${scale})` : 'none';
  wrap.style.height   = Math.ceil(h * scale) + 'px';
  wrap.style.overflow = 'hidden';
}
window.addEventListener('resize', () => { if (window._previewFmt) fitPreview(window._previewFmt); });

function printPreview() {
  const f = document.getElementById('previewFrame');
  if (f?.contentWindow) { f.contentWindow.focus(); f.contentWindow.print(); }
}

// ══════════════════════════════════════════════════
// Load outlet list
// ══════════════════════════════════════════════════
async function loadOutlets() {
  const r = await fetch('/hq/struk.php?action=list_outlets');
  outletList = await r.json();
  const el = document.getElementById('outletListEl');
  if (!outletList.length) { el.innerHTML = '<div style="color:#9CA3AF;font-size:13px">Tidak ada outlet aktif</div>'; return; }

  el.innerHTML = outletList.map(o => {
    const retailDot = o.retail_updated ? '✓' : '–';
    const b2bDot    = o.b2b_updated    ? '✓' : '–';
    return `<div class="outlet-item">
      <input type="checkbox" class="outlet-cb" value="${o.id}" checked onchange="updateSelCount()">
      <span class="name">${esc(o.nama_outlet)}</span>
      <span class="meta">${esc(o.kota||'')} · R:${retailDot} B:${b2bDot}</span>
    </div>`;
  }).join('');
  updateSelCount();
}

function toggleAllOutlets(checked) {
  document.querySelectorAll('.outlet-cb').forEach(cb => cb.checked = checked);
  updateSelCount();
}
function updateSelCount() {
  const n = document.querySelectorAll('.outlet-cb:checked').length;
  document.getElementById('outletSelCount').textContent = `(${n} dipilih)`;
}
function getSelectedOutlets() {
  return [...document.querySelectorAll('.outlet-cb:checked')].map(cb => parseInt(cb.value));
}

// ══════════════════════════════════════════════════
// Push ke outlet
// ══════════════════════════════════════════════════
async function pushToAll() {
  if (!await lmConfirm(`Push template ${activeTab.toUpperCase()} ke SEMUA outlet aktif?`)) return;
  await doPush('all');
}
async function pushSelected() {
  const sel = getSelectedOutlets();
  if (!sel.length) { showAlert('Pilih minimal satu outlet', 'err'); return; }
  if (!await lmConfirm(`Push template ${activeTab.toUpperCase()} ke ${sel.length} outlet dipilih?`)) return;
  await doPush(sel);
}
async function doPush(targets) {
  const data = { ...collectForm(), targets };
  const r    = await fetch('/hq/struk.php?action=push', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF},
    body: JSON.stringify(data),
  });
  const j = await r.json();
  if (j.error) { showAlert('❌ ' + j.error, 'err'); return; }
  showAlert(`✓ Template berhasil dipush ke ${j.pushed} dari ${j.total} outlet!`, 'ok');
  loadOutlets(); // refresh status template
}

// ══════════════════════════════════════════════════
// Alert & helpers
// ══════════════════════════════════════════════════
function showAlert(msg, type) {
  const el = document.getElementById('alertMsg');
  el.className = 'alert alert-' + type;
  el.textContent = msg;
  el.style.display = 'block';
  clearTimeout(el._t);
  el._t = setTimeout(() => el.style.display='none', 5000);
}
function esc(s) {
  return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Init
loadTemplate('retail');
loadOutlets();
</script>
<?php require __DIR__ . '/_layout_close.php'; ?>

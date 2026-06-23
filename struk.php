<?php
// ══════════════════════════════════════════════════════
// struk.php — Kustomisasi Struk & Invoice per Outlet
// Tab: RETAIL | B2B/INVOICE
// Live preview · Upload logo · Simpan template
// ══════════════════════════════════════════════════════

$activePage = 'struk';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/StrukGenerator.php';

$db   = Database::get();
$tid  = TenantResolver::id();
$oid  = TenantResolver::outletId();
$user = currentUser() ?? [];
$csrf = getCsrfToken();

// Hanya admin-level yang bisa edit template (F1 RBAC v2)
$canEdit = TenantResolver::isAdminLevel() || hasPermission('settings.roles');

// ── AJAX actions ──────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');

    // ── Ambil template ──────────────────────────────
    if ($action === 'get_template') {
        $tipe = $_GET['tipe'] ?? 'retail';
        if (!in_array($tipe, ['retail','b2b'])) { echo json_encode(['error'=>'Tipe invalid']); exit; }
        echo json_encode(['template' => StrukGenerator::loadTemplate($tid, $oid, $tipe)]);
        exit;
    }

    // ── Simpan template ────────────────────────────
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canEdit) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $tipe = $body['tipe'] ?? 'retail';
        if (!in_array($tipe, ['retail','b2b'])) { echo json_encode(['error'=>'Tipe invalid']); exit; }

        // Cast checkbox fields ke int
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
        $data = ['tipe' => $tipe];
        foreach ($bools as $f) {
            $data[$f] = empty($body[$f]) ? 0 : 1;
        }
        $strings = [
            'format','logo_size','nama_outlet','tagline',
            'alamat_override','header_extra','footer_ucapan',
            'footer_syarat','footer_sosmed','footer_extra',
            'rekening_bank','rekening_nomor','rekening_atas_nama',
            'font_size',
        ];
        foreach ($strings as $f) {
            if (array_key_exists($f, $body)) {
                $data[$f] = substr(trim((string)($body[$f] ?? '')), 0, 500) ?: null;
            }
        }

        try {
            StrukGenerator::saveTemplate($tid, $oid, $tipe, $data);
            echo json_encode(['success' => true]);
        } catch (Throwable $e) {
            error_log('[struk.php save] ' . $e->getMessage());
            echo json_encode(['error' => 'Gagal simpan: ' . $e->getMessage()]);
        }
        exit;
    }

    // ── Live preview HTML ──────────────────────────
    if ($action === 'preview') {
        header('Content-Type: text/html; charset=utf-8');
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $tipe = $body['tipe'] ?? 'retail';
        if (!in_array($tipe, ['retail','b2b'])) { echo 'Tipe invalid'; exit; }
        try {
            $tmpl = array_merge(
                StrukGenerator::loadTemplate($tid, $oid, $tipe),
                $body
            );
            echo StrukGenerator::preview($tmpl, $tipe);
        } catch (Throwable $e) {
            echo '<p style="color:red">Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        exit;
    }

    // ── Generate struk dari transaksi real ─────────
    if ($action === 'generate') {
        header('Content-Type: text/html; charset=utf-8');
        $trxId  = (int)($_GET['id'] ?? 0);
        $tipe   = $_GET['tipe'] ?? 'retail';
        $format = $_GET['format'] ?? null;
        $isPreview = !empty($_GET['preview']); // preview = tidak potong coin
        if (!$trxId) { echo 'ID transaksi tidak valid'; exit; }
        try {
            echo StrukGenerator::generate($trxId, $tipe, $format, !$isPreview);
        } catch (Throwable $e) {
            echo '<p style="color:red">Gagal: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        exit;
    }

    // ── Upload logo ────────────────────────────────
    if ($action === 'upload_logo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canEdit) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();

        $file = $_FILES['logo'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['error' => 'File tidak diterima. Error: ' . ($file['error'] ?? 'unknown')]);
            exit;
        }

        // Validasi tipe
        $allowedMime = ['image/png','image/jpeg','image/jpg','image/svg+xml','image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, $allowedMime)) {
            echo json_encode(['error' => 'Format file tidak didukung. Gunakan PNG, JPG, atau SVG.']);
            exit;
        }

        // Validasi ukuran (maks 2MB)
        if ($file['size'] > 2 * 1024 * 1024) {
            echo json_encode(['error' => 'File terlalu besar. Maksimal 2MB.']);
            exit;
        }

        // Direktori upload
        $uploadDir = ROOT . '/uploads/logos/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $ext      = $mime === 'image/svg+xml' ? 'svg' : 'png';
        $filename = "t{$tid}_o{$oid}.{$ext}";
        $destPath = $uploadDir . $filename;
        $publicUrl = '/uploads/logos/' . $filename;

        // Resize PNG/JPG ke maks 400px lebar (SVG tidak perlu)
        if ($ext === 'png' && function_exists('imagecreatefromstring')) {
            $src = imagecreatefromstring(file_get_contents($file['tmp_name']));
            if ($src) {
                $w = imagesx($src);
                $h = imagesy($src);
                if ($w > 400) {
                    $nh = (int)($h * 400 / $w);
                    $dst = imagecreatetruecolor(400, $nh);
                    // Preserve transparency
                    imagealphablending($dst, false);
                    imagesavealpha($dst, true);
                    imagecopyresampled($dst, $src, 0, 0, 0, 0, 400, $nh, $w, $h);
                    imagepng($dst, $destPath);
                    imagedestroy($src); imagedestroy($dst);
                } else {
                    imagepng($src, $destPath);
                    imagedestroy($src);
                }
            } else {
                move_uploaded_file($file['tmp_name'], $destPath);
            }
        } else {
            // SVG atau GD tidak tersedia
            move_uploaded_file($file['tmp_name'], $destPath);
        }

        // Simpan logo_url ke template kedua tipe
        foreach (['retail','b2b'] as $t) {
            StrukGenerator::saveTemplate($tid, $oid, $t, [
                'logo_url'  => $publicUrl,
                'show_logo' => 1,
            ]);
        }

        echo json_encode(['success' => true, 'url' => $publicUrl . '?v=' . time()]);
        exit;
    }

    // ── Reset ke default ───────────────────────────
    if ($action === 'reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canEdit) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $tipe = $body['tipe'] ?? 'retail';
        if (!in_array($tipe, ['retail','b2b'])) { echo json_encode(['error'=>'Tipe invalid']); exit; }
        try {
            $db->prepare(
                "DELETE FROM hl_struk_template WHERE tenant_id=? AND outlet_id=? AND tipe=?"
            )->execute([$tid, $oid, $tipe]);
            echo json_encode(['success' => true]);
        } catch (Throwable $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['error' => 'Action tidak dikenal']); exit;
}

// ── Page render ───────────────────────────────────────
$pageTitle  = 'Kustomisasi Struk';
require_once ROOT . '/components.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Kustomisasi Struk'); ?>
<style>
/* ── Layout ── */
/* struk-wrap deprecated — pakai .hl-main biar konsisten dengan /outlet-settings */
.struk-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; flex-wrap:wrap; gap:10px; }
.struk-header h1 { font-size:1.35rem; font-weight:800; color:var(--navy); }
.struk-header h1 small { display:block; font-size:13px; font-weight:400; color:var(--gray); margin-top:2px; }

/* ── Tabs ── */
.tab-bar { display:flex; gap:6px; margin-bottom:16px; }
.tab-btn { padding:9px 22px; border-radius:8px; font-size:13px; font-weight:700;
           border:1.5px solid rgba(27,45,90,.15); background:#fff; color:var(--dark);
           cursor:pointer; transition:all .15s; }
.tab-btn.active { background:var(--navy); color:#fff; border-color:var(--navy); }

/* ── Main grid ── */
.struk-grid { display:grid; grid-template-columns:360px 1fr; gap:18px; align-items:start; }
@media(max-width:900px){ .struk-grid{ grid-template-columns:1fr; } }

/* ── Settings panel ── */
.settings-panel { background:#fff; border-radius:14px; border:1px solid rgba(27,45,90,.08); padding:0; overflow:hidden; }
.settings-panel-inner { padding:18px; max-height: calc(100vh - 160px); overflow-y:auto; }
.section-title { font-size:11px; font-weight:800; text-transform:uppercase; color:var(--gray);
                 letter-spacing:.8px; margin:18px 0 10px; padding-bottom:6px;
                 border-bottom:1px solid rgba(27,45,90,.08); }
.section-title:first-child { margin-top:0; }

/* ── Form elements ── */
.check-row { display:flex; align-items:center; gap:10px; padding:7px 0;
             border-bottom:1px solid rgba(27,45,90,.05); }
.check-row:last-child { border-bottom:none; }
.check-row input[type=checkbox] { width:16px; height:16px; accent-color:var(--teal); flex-shrink:0; }
.check-row label { font-size:13px; color:var(--dark); cursor:pointer; flex:1; user-select:none; }

.form-field { margin-bottom:12px; }
.form-field label { display:block; font-size:12px; font-weight:600; color:var(--dark); margin-bottom:5px; }
.form-field input, .form-field textarea, .form-field select {
  width:100%; padding:8px 11px; border:1.5px solid rgba(27,45,90,.12); border-radius:8px;
  font-size:13px; font-family:inherit; color:var(--dark); background:#fff;
  transition:border-color .15s; outline:none; }
.form-field input:focus, .form-field textarea:focus, .form-field select:focus { border-color:var(--teal); }
.form-field textarea { min-height:60px; resize:vertical; }
.form-field .hint { font-size:11px; color:var(--gray); margin-top:4px; }

/* ── Logo upload ── */
.logo-area { border:1.5px dashed rgba(27,45,90,.2); border-radius:10px; padding:12px;
             text-align:center; margin-bottom:12px; }
.logo-preview { max-height:60px; max-width:160px; margin:0 auto 8px; display:block; }
.logo-area .hint { font-size:11px; color:var(--gray); }

/* ── Action bar (sticky) ── */
.action-bar { padding:14px 18px; border-top:1px solid rgba(27,45,90,.08);
              background:#fafbfc; display:flex; gap:8px; flex-wrap:wrap; }

/* ── Preview panel ── */
.preview-panel { position:sticky; top:20px; }
.preview-card { background:#fff; border-radius:14px; border:1px solid rgba(27,45,90,.08);
                padding:16px; margin-bottom:14px; }
.preview-card h3 { font-size:13px; font-weight:700; color:var(--navy); margin-bottom:12px;
                   display:flex; align-items:center; gap:8px; }
.preview-iframe-wrap { background:#f8faff; border-radius:8px; border:1px solid rgba(27,45,90,.08);
                       overflow:hidden; width:100%; }
.preview-iframe-wrap iframe { width:100%; border:none; min-height:400px; background:#fff; }

.preview-actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:12px; }

/* ── Buttons ── */
.btn { display:inline-flex; align-items:center; gap:6px; padding:9px 18px; border-radius:9px;
       font-size:13px; font-weight:700; border:none; cursor:pointer; text-decoration:none; font-family:inherit; }
.btn-primary { background:var(--teal); color:var(--navy); }
.btn-primary:hover { filter:brightness(.95); }
.btn-secondary { background:var(--navy); color:#fff; }
.btn-light { background:#F3F4F6; color:var(--dark); }
.btn-danger { background:#FEE2E2; color:#991B1B; }
.btn-sm { padding:6px 12px; font-size:12px; }

/* ── Alert ── */
.alert { padding:10px 14px; border-radius:8px; font-size:13px; margin-bottom:12px; }
.alert-success { background:#D1FAE5; color:#065F46; border:1px solid #6EE7B7; }
.alert-error   { background:#FEE2E2; color:#991B1B; border:1px solid #FECACA; }

/* ── Readonly notice ── */
.readonly-notice { background:#FEF3C7; color:#92400E; border:1px solid #FDE68A;
                   padding:10px 14px; border-radius:8px; font-size:13px; margin-bottom:14px; }
</style>
</head>
<body>
<?php renderTopbar('outlet-settings'); ?>
<div class="hl-main">
  <div class="settings-tabs" style="display:flex;gap:2px;margin-bottom:18px;border-bottom:1px solid var(--off)">
    <a href="/outlet-settings" class="settings-tab" style="padding:11px 18px;border-bottom:3px solid transparent;color:var(--gray);font-weight:600;font-size:14px;text-decoration:none">🏢 Outlet & Nota</a>
    <a href="/struk" class="settings-tab active" style="padding:11px 18px;border-bottom:3px solid var(--teal);color:var(--navy-d);font-weight:700;font-size:14px;text-decoration:none">🧾 Struk & Invoice</a>
  </div>

  <div class="struk-header">
    <h1>🧾 Kustomisasi Struk & Invoice
      <small>Atur tampilan nota dan invoice untuk outlet ini</small>
    </h1>
  </div>

  <?php if (!$canEdit): ?>
  <div class="readonly-notice">🔒 Kamu hanya bisa melihat pengaturan. Perlu role Owner atau Admin untuk mengedit.</div>
  <?php endif; ?>

  <!-- Tabs -->
  <div class="tab-bar">
    <button class="tab-btn active" onclick="switchTab('retail')" id="tab-retail">🧾 Retail</button>
    <button class="tab-btn"       onclick="switchTab('b2b')"    id="tab-b2b">📄 B2B / Invoice</button>
  </div>

  <div id="alertMsg" style="display:none"></div>

  <div class="struk-grid">

    <!-- ── LEFT: Settings ── -->
    <div class="settings-panel">
      <div class="settings-panel-inner" id="settingsPanel">
        <div style="text-align:center;padding:40px;color:var(--gray)">⏳ Memuat pengaturan…</div>
      </div>
      <div class="action-bar">
        <button class="btn btn-primary" onclick="saveTemplate()" <?= $canEdit?'':'disabled'?>>💾 Simpan</button>
        <button class="btn btn-light"   onclick="refreshPreview()">🔄 Refresh Preview</button>
        <button class="btn btn-danger btn-sm" onclick="resetTemplate()" <?= $canEdit?'':'disabled'?>>↩ Reset Default</button>
      </div>
    </div>

    <!-- ── RIGHT: Preview ── -->
    <div class="preview-panel">
      <div class="preview-card">
        <h3>👁 Live Preview <span id="previewFormatLabel" style="font-weight:400;color:var(--gray);font-size:11px"></span></h3>
        <div class="preview-iframe-wrap">
          <iframe id="previewFrame" title="Preview Struk"></iframe>
        </div>
        <div class="preview-actions">
          <button class="btn btn-secondary btn-sm" onclick="printPreview()">🖨 Test Print</button>
          <a id="openPreviewBtn" href="#" target="_blank" class="btn btn-light btn-sm">↗ Buka di Tab Baru</a>
        </div>
      </div>
    </div>

  </div><!-- /struk-grid -->
</div><!-- /hl-main -->

<?php renderToast(); ?>
<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
let activeTab   = 'retail';
let currentTmpl = {};
let debounceTimer;

// ══════════════════════════════════════════════════
// Tab switching
// ══════════════════════════════════════════════════
function switchTab(tipe) {
  activeTab = tipe;
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + tipe).classList.add('active');
  loadTemplate(tipe);
}

// ══════════════════════════════════════════════════
// Load template dari server → render form
// ══════════════════════════════════════════════════
async function loadTemplate(tipe) {
  document.getElementById('settingsPanel').innerHTML =
    '<div style="text-align:center;padding:40px;color:var(--gray)">⏳ Memuat…</div>';

  const r = await fetch(`/struk.php?action=get_template&tipe=${tipe}`);
  const j = await r.json();
  if (j.error) { showAlert(j.error, 'error'); return; }
  currentTmpl = j.template;
  renderForm(tipe, j.template);
  refreshPreview();
}

// ══════════════════════════════════════════════════
// Render form settings dinamis
// ══════════════════════════════════════════════════
function renderForm(tipe, t) {
  const isB2b = tipe === 'b2b';
  const v = (k, def='') => t[k] !== null && t[k] !== undefined ? t[k] : def;
  const chk = (k) => parseInt(v(k, 0)) === 1 ? 'checked' : '';
  const sel = (k, opt, def='') => {
    const cur = v(k, def);
    return opt.map(o => `<option value="${o.val}" ${cur===o.val?'selected':''}>${o.lbl}</option>`).join('');
  };

  let html = `
  <!-- ── FORMAT ── -->
  <div class="section-title">📐 Format Output</div>
  <div class="form-field">
    <label>Format</label>
    <select id="f_format" onchange="onFieldChange()">
      <option value="thermal_58" ${v('format')==='thermal_58'?'selected':''}>🖨 Thermal 58mm</option>
      <option value="thermal_80" ${v('format')==='thermal_80'?'selected':''}>🖨 Thermal 80mm (Default)</option>
      <option value="a5"         ${v('format')==='a5'?'selected':''}>📄 PDF A5</option>
      <option value="a4"         ${v('format')==='a4'?'selected':''}>📄 PDF A4</option>
    </select>
  </div>
  <div class="form-field">
    <label>Ukuran Font</label>
    <select id="f_font_size" onchange="onFieldChange()">
      ${sel('font_size',[{val:'small',lbl:'Kecil'},{val:'normal',lbl:'Normal'},{val:'large',lbl:'Besar'}],'normal')}
    </select>
  </div>

  <!-- ── LOGO ── -->
  <div class="section-title">🖼 Logo</div>
  <div class="logo-area" id="logoArea">
    ${v('logo_url') ? `<img src="${escHtml(v('logo_url'))}" class="logo-preview" id="logoImg">` : '<div id="logoImg" style="color:var(--gray);font-size:12px;margin-bottom:8px">Belum ada logo</div>'}
    <input type="file" id="logoFile" accept="image/png,image/jpeg,image/svg+xml,image/webp"
           style="display:none" onchange="uploadLogo(this)">
    <button class="btn btn-light btn-sm" onclick="document.getElementById('logoFile').click()">📁 Pilih Gambar</button>
    <div class="hint">PNG / JPG / SVG · maks 2MB · otomatis resize 400px</div>
  </div>
  <div class="check-row">
    <input type="checkbox" id="f_show_logo" ${chk('show_logo')} onchange="onFieldChange()">
    <label for="f_show_logo">Tampilkan Logo</label>
  </div>
  <div class="form-field" style="margin-top:8px">
    <label>Ukuran Logo</label>
    <select id="f_logo_size" onchange="onFieldChange()">
      ${sel('logo_size',[{val:'small',lbl:'Kecil (20mm)'},{val:'medium',lbl:'Sedang (30mm)'},{val:'large',lbl:'Besar (40mm)'}],'medium')}
    </select>
  </div>

  <!-- ── HEADER ── -->
  <div class="section-title">📋 Header</div>
  <div class="form-field">
    <label>Nama Outlet <span style="font-weight:400;font-size:11px;color:var(--gray)">(kosongkan = pakai nama outlet)</span></label>
    <input type="text" id="f_nama_outlet" value="${escHtml(v('nama_outlet'))}" maxlength="100"
           placeholder="cth: Bersih Laundry Cabang Pusat" oninput="onFieldChange()">
  </div>
  <div class="form-field">
    <label>Tagline</label>
    <input type="text" id="f_tagline" value="${escHtml(v('tagline'))}" maxlength="200"
           placeholder="cth: Bersih, Cepat, Terpercaya!" oninput="onFieldChange()">
  </div>
  <div class="check-row">
    <input type="checkbox" id="f_show_alamat" ${chk('show_alamat')} onchange="onFieldChange()">
    <label for="f_show_alamat">Tampilkan Alamat</label>
  </div>
  <div class="form-field" style="margin-top:8px">
    <label>Override Alamat <span style="font-weight:400;font-size:11px;color:var(--gray)">(kosongkan = pakai alamat outlet)</span></label>
    <input type="text" id="f_alamat_override" value="${escHtml(v('alamat_override'))}" maxlength="300"
           placeholder="Jl. Contoh No. 1, Kota" oninput="onFieldChange()">
  </div>
  <div class="check-row">
    <input type="checkbox" id="f_show_telp" ${chk('show_telp')} onchange="onFieldChange()">
    <label for="f_show_telp">Tampilkan Telepon Outlet</label>
  </div>
  <div class="form-field" style="margin-top:8px">
    <label>Teks Tambahan Header</label>
    <input type="text" id="f_header_extra" value="${escHtml(v('header_extra'))}" maxlength="200"
           placeholder="cth: Buka 07.00–22.00 Setiap Hari" oninput="onFieldChange()">
  </div>

  <!-- ── BODY ── -->
  <div class="section-title">📝 Isi Struk</div>
  ${checkRow('show_no_order',    'Nomor Order', t)}
  ${checkRow('show_tanggal',     'Tanggal & Waktu', t)}
  ${checkRow('show_nama_kasir',  'Nama Kasir', t)}
  ${checkRow('show_nama_pelanggan', 'Nama Pelanggan', t)}
  ${checkRow('show_telp_pelanggan', 'Telepon Pelanggan', t)}
  ${isB2b ? checkRow('show_alamat_pelanggan', 'Alamat Pelanggan (B2B)', t) : ''}
  ${checkRow('show_detail_item', 'Detail Item (Nama · Qty · Harga)', t)}
  ${checkRow('show_subtotal',    'Subtotal', t)}
  ${checkRow('show_diskon',      'Diskon', t)}
  ${checkRow('show_dp',          'DP', t)}
  ${checkRow('show_total',       'Total', t)}
  ${checkRow('show_metode_bayar','Metode Bayar', t)}
  ${checkRow('show_sisa_bayar',  'Sisa Bayar', t)}
  ${!isB2b ? checkRow('show_estimasi', 'Estimasi Selesai', t) : ''}
  ${checkRow('show_catatan',     'Catatan Order', t)}
  ${!isB2b ? checkRow('show_poin_earned', 'Poin Didapat', t) : ''}
  ${!isB2b ? checkRow('show_saldo_poin',  'Saldo Poin', t) : ''}

  ${isB2b ? `
  <!-- ── B2B EXTRA ── -->
  <div class="section-title">🏢 Khusus B2B / Invoice</div>
  ${checkRow('show_jatuh_tempo', 'Tanggal Jatuh Tempo', t)}
  ${checkRow('show_rekening',    'Info Rekening Pembayaran', t)}
  <div class="form-field" style="margin-top:8px">
    <label>Nama Bank</label>
    <input type="text" id="f_rekening_bank" value="${escHtml(v('rekening_bank'))}" maxlength="50"
           placeholder="cth: BCA" oninput="onFieldChange()">
  </div>
  <div class="form-field">
    <label>Nomor Rekening</label>
    <input type="text" id="f_rekening_nomor" value="${escHtml(v('rekening_nomor'))}" maxlength="50"
           placeholder="cth: 5520513584" oninput="onFieldChange()">
  </div>
  <div class="form-field">
    <label>Atas Nama</label>
    <input type="text" id="f_rekening_atas_nama" value="${escHtml(v('rekening_atas_nama'))}" maxlength="100"
           placeholder="cth: Bersih Laundry" oninput="onFieldChange()">
  </div>
  ` : ''}

  <!-- ── FOOTER ── -->
  <div class="section-title">🙏 Footer</div>
  <div class="check-row">
    <input type="checkbox" id="f_show_footer_ucapan" ${chk('show_footer_ucapan')} onchange="onFieldChange()">
    <label for="f_show_footer_ucapan">Tampilkan Ucapan Terima Kasih</label>
  </div>
  <div class="form-field" style="margin-top:8px">
    <label>Teks Ucapan</label>
    <textarea id="f_footer_ucapan" maxlength="255" oninput="onFieldChange()">${escHtml(v('footer_ucapan'))}</textarea>
  </div>
  <div class="check-row">
    <input type="checkbox" id="f_show_footer_sosmed" ${chk('show_footer_sosmed')} onchange="onFieldChange()">
    <label for="f_show_footer_sosmed">Tampilkan Sosial Media / Kontak</label>
  </div>
  <div class="form-field" style="margin-top:8px">
    <label>Teks Sosmed</label>
    <input type="text" id="f_footer_sosmed" value="${escHtml(v('footer_sosmed'))}" maxlength="200"
           placeholder="cth: IG: @harpy_laundry | WA: 0812xxx" oninput="onFieldChange()">
  </div>
  <div class="check-row">
    <input type="checkbox" id="f_show_footer_syarat" ${chk('show_footer_syarat')} onchange="onFieldChange()">
    <label for="f_show_footer_syarat">Tampilkan Syarat & Ketentuan</label>
  </div>
  <div class="form-field" style="margin-top:8px">
    <label>Teks Syarat & Ketentuan</label>
    <textarea id="f_footer_syarat" maxlength="500" rows="3" oninput="onFieldChange()">${escHtml(v('footer_syarat'))}</textarea>
  </div>
  <div class="form-field">
    <label>Teks Tambahan Footer</label>
    <input type="text" id="f_footer_extra" value="${escHtml(v('footer_extra'))}" maxlength="200" oninput="onFieldChange()">
  </div>

  <!-- ── STYLING ── -->
  <div class="section-title">🎨 Tampilan</div>
  ${checkRow('show_border',    'Tampilkan Garis Pemisah', t)}
  ${checkRow('show_watermark', 'Watermark "COPY" (untuk salinan)', t)}
  `;

  document.getElementById('settingsPanel').innerHTML = html;
}

// Helper: checkbox row
function checkRow(field, label, t) {
  const chk = parseInt(t[field] ?? 0) === 1 ? 'checked' : '';
  return `<div class="check-row">
    <input type="checkbox" id="f_${field}" ${chk} onchange="onFieldChange()">
    <label for="f_${field}">${label}</label>
  </div>`;
}

// ══════════════════════════════════════════════════
// Kumpulkan nilai form → object
// ══════════════════════════════════════════════════
function collectForm() {
  const g = id => document.getElementById(id);
  const gv = id => { const el = g(id); return el ? el.value : null; };
  const gc = id => { const el = g(id); return el ? (el.checked ? 1 : 0) : 0; };

  const bools = [
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
  const strings = [
    'format','logo_size','nama_outlet','tagline','alamat_override','header_extra',
    'footer_ucapan','footer_syarat','footer_sosmed','footer_extra','font_size',
    'rekening_bank','rekening_nomor','rekening_atas_nama',
  ];

  const out = { tipe: activeTab };
  bools.forEach(f  => out[f] = gc('f_' + f));
  strings.forEach(f => { const v = gv('f_' + f); if (v !== null) out[f] = v; });
  return out;
}

// ══════════════════════════════════════════════════
// Live preview — debounce 600ms
// ══════════════════════════════════════════════════
function onFieldChange() {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(refreshPreview, 600);
}

async function refreshPreview() {
  const data = collectForm();
  const fmt  = data.format || 'thermal_80';

  // Update label format
  const fmtLabels = {
    thermal_58: '58mm Thermal', thermal_80: '80mm Thermal',
    a5: 'PDF A5', a4: 'PDF A4'
  };
  document.getElementById('previewFormatLabel').textContent = '— ' + (fmtLabels[fmt] || fmt);

  // Update open-in-new-tab link
  document.getElementById('openPreviewBtn').href = '#';

  try {
    const r = await fetch('/struk.php?action=preview', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify(data),
    });
    const html = await r.text();

    const frame = document.getElementById('previewFrame');
    // Sesuaikan tinggi iframe dengan lebar format
    const isA4 = fmt === 'a4', isA5 = fmt === 'a5';
    const isPdf = isA4 || isA5;
    frame.style.minHeight = isPdf ? '700px' : '400px';
    frame.style.width = isA4 ? '210mm' : (isA5 ? '148mm' : (fmt==='thermal_58'?'72mm':'92mm'));
    frame.style.maxWidth = '100%';

    // Write HTML ke iframe
    const doc = frame.contentDocument || frame.contentWindow.document;
    doc.open(); doc.write(html); doc.close();
  } catch(e) {
    console.error('Preview error:', e);
  }
}

// ══════════════════════════════════════════════════
// Simpan template
// ══════════════════════════════════════════════════
async function saveTemplate() {
  const data = collectForm();
  const r = await fetch('/struk.php?action=save', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
    body: JSON.stringify(data),
  });
  const j = await r.json();
  if (j.error) { showAlert('❌ ' + j.error, 'error'); return; }
  showAlert('✓ Template berhasil disimpan!', 'success');
}

// ══════════════════════════════════════════════════
// Reset ke default
// ══════════════════════════════════════════════════
async function resetTemplate() {
  if (!confirm(`Reset template ${activeTab.toUpperCase()} ke pengaturan default?`)) return;
  const r = await fetch('/struk.php?action=reset', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
    body: JSON.stringify({ tipe: activeTab }),
  });
  const j = await r.json();
  if (j.error) { showAlert('❌ ' + j.error, 'error'); return; }
  showAlert('↩ Template direset ke default.', 'success');
  loadTemplate(activeTab);
}

// ══════════════════════════════════════════════════
// Upload logo
// ══════════════════════════════════════════════════
async function uploadLogo(input) {
  const file = input.files[0];
  if (!file) return;

  const fd = new FormData();
  fd.append('logo', file);
  fd.append('_csrf', CSRF);

  const btn = document.querySelector('.logo-area button');
  const orig = btn.textContent;
  btn.textContent = '⏳ Mengupload…'; btn.disabled = true;

  try {
    const r = await fetch('/struk.php?action=upload_logo', {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': CSRF },
      body: fd,
    });
    const j = await r.json();
    if (j.error) { showAlert('❌ ' + j.error, 'error'); return; }

    // Update preview logo
    let img = document.getElementById('logoImg');
    if (img.tagName === 'DIV') {
      img.outerHTML = `<img src="${j.url}" class="logo-preview" id="logoImg">`;
    } else {
      img.src = j.url;
    }
    // Paksa field show_logo = checked
    const chk = document.getElementById('f_show_logo');
    if (chk) chk.checked = true;
    showAlert('✓ Logo berhasil diupload!', 'success');
    onFieldChange();
  } catch(e) {
    showAlert('❌ Upload gagal: ' + e.message, 'error');
  } finally {
    btn.textContent = orig; btn.disabled = false;
    input.value = '';
  }
}

// ══════════════════════════════════════════════════
// Print preview iframe
// ══════════════════════════════════════════════════
function printPreview() {
  const frame = document.getElementById('previewFrame');
  frame.contentWindow.focus();
  frame.contentWindow.print();
}

// ══════════════════════════════════════════════════
// Alert
// ══════════════════════════════════════════════════
function showAlert(msg, type) {
  const el = document.getElementById('alertMsg');
  el.className = 'alert alert-' + type;
  el.textContent = msg;
  el.style.display = 'block';
  clearTimeout(el._timer);
  el._timer = setTimeout(() => { el.style.display='none'; }, 4000);
}

// HTML escape
function escHtml(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ══════════════════════════════════════════════════
// Init
// ══════════════════════════════════════════════════
loadTemplate('retail');
</script>
</body>
</html>

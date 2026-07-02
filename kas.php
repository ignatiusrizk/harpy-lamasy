<?php
$activePage = 'kas';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once __DIR__ . '/components.php';
$user = currentUser();
requirePermission('kas.view');

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $tid = TenantResolver::id();
    $oid = TenantResolver::outletId();

    // LIST KAS
    if ($action === 'list') {
        $dari   = $_GET['dari']   ?? date('Y-m-01');
        $sampai = $_GET['sampai'] ?? date('Y-m-d');
        $tipe   = $_GET['tipe']   ?? '';
        $kat    = $_GET['kat']    ?? '';

        $where  = ['tenant_id = ?', 'outlet_id = ?', 'tanggal BETWEEN ? AND ?'];
        $params = [$tid, $oid, $dari, $sampai];
        if ($tipe) { $where[] = 'tipe=?';     $params[] = $tipe; }
        if ($kat)  { $where[] = 'kategori=?'; $params[] = $kat; }
        $whereStr = implode(' AND ', $where);

        $rows    = TenantQuery::raw("SELECT * FROM hl_kas WHERE $whereStr ORDER BY tanggal DESC, id DESC", $params);
        $summary = TenantQuery::raw(
            "SELECT COALESCE(SUM(CASE WHEN tipe='masuk' THEN jumlah END),0) as total_masuk,
                    COALESCE(SUM(CASE WHEN tipe='keluar' THEN jumlah END),0) as total_keluar,
                    COUNT(*) as total_transaksi
             FROM hl_kas WHERE $whereStr",
            $params
        );
        echo json_encode(['data'=>$rows, 'summary'=>$summary[0] ?? []]);
        exit;
    }

    // SAVE KAS
    if ($action === 'save' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('kas.create')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $keterangan = substr(trim(strip_tags($d['keterangan'] ?? '')), 0, 500);
        if (!$keterangan) { echo json_encode(['error'=>'Keterangan wajib diisi']); exit; }
        if (floatval($d['jumlah'] ?? 0) <= 0) { echo json_encode(['error'=>'Jumlah harus lebih dari 0']); exit; }

        // bukti_foto: hanya terima path di folder bukti milik tenant+outlet ini (anti XSS/path injeksi)
        $buktiFoto = null;
        $bf = trim((string)($d['bukti_foto'] ?? ''));
        $bfPrefix = 'uploads/kas_bukti/t' . $tid . '_o' . $oid . '_'; // trailing _ cegah cross-outlet (o1 vs o10)
        if ($bf !== '' && strpos($bf, '..') === false && strpos($bf, $bfPrefix) === 0) {
            $buktiFoto = substr($bf, 0, 255);
        }

        $data = [
            'tanggal'    => $d['tanggal']   ?? date('Y-m-d'),
            'tipe'       => in_array($d['tipe']??'', ['masuk','keluar']) ? $d['tipe'] : 'masuk',
            'kategori'   => substr(trim($d['kategori'] ?? ''), 0, 50),
            'keterangan' => $keterangan,
            'jumlah'     => floatval($d['jumlah']),
            'ref_order'  => $d['ref_order'] ? strtoupper(substr(trim($d['ref_order']), 0, 30)) : null,
            'bukti_foto' => $buktiFoto,
        ];

        // Kategori tak boleh milik tipe lawan (mis. 'Penjualan Laundry' pada kas keluar)
        // — nilai custom/legacy di luar dua daftar ini tetap diterima.
        $_katMasuk  = ['Penjualan Laundry','Pelunasan Order','Pendapatan Lain','Modal'];
        $_katKeluar = ['Gaji Karyawan','Bahan & Deterjen','Listrik & Air','Sewa Tempat','Peralatan','Transportasi','Operasional','Lain-lain'];
        if (($data['tipe'] === 'masuk'  && in_array($data['kategori'], $_katKeluar, true)) ||
            ($data['tipe'] === 'keluar' && in_array($data['kategori'], $_katMasuk,  true))) {
            echo json_encode(['error' => 'Kategori tidak sesuai tipe kas (masuk/keluar)']); exit;
        }

        if (!empty($d['id'])) {
            TenantQuery::update('hl_kas', $data, 'id = ?', [intval($d['id'])]);
        } else {
            $data['created_by'] = $user['id'];
            TenantQuery::insert('hl_kas', $data);
        }
        logAudit(!empty($d['id'])?'update':'create','kas',($data['tipe']).' Rp '.number_format($data['jumlah'],0,',','.').': '.$keterangan);
        echo json_encode(['success'=>true]);
        // Anomaly check (silent)
        try {
            require_once __DIR__ . '/core/AnomalyDetector.php';
            AnomalyDetector::check($tid, $oid);
        } catch (Throwable $e) {
            ErrorLogger::logException('anomaly_check', $e, $tid, $oid);
        }
        exit;
    }

    // DELETE KAS
    if ($action === 'delete' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('kas.delete')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        TenantQuery::delete('hl_kas', 'id = ?', [intval($d['id'])]);
        echo json_encode(['success'=>true]); exit;
    }

    // SUMMARY HARIAN
    if ($action === 'summary_harian') {
        $tgl = $_GET['tgl'] ?? date('Y-m-d');
        $kasData  = TenantQuery::raw(
            "SELECT COALESCE(SUM(CASE WHEN tipe='masuk' THEN jumlah END),0) as kas_masuk,
                    COALESCE(SUM(CASE WHEN tipe='keluar' THEN jumlah END),0) as kas_keluar
             FROM hl_kas WHERE tenant_id=? AND outlet_id=? AND tanggal=?",
            [$tid, $oid, $tgl]
        );
        $orderData = TenantQuery::raw(
            "SELECT COUNT(*) as total_order,
                    COALESCE(SUM(total),0) as omset,
                    COALESCE(SUM(dp),0) as terkumpul
             FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal)=?",
            [$tid, $oid, $tgl]
        );
        echo json_encode(array_merge($kasData[0] ?? [], $orderData[0] ?? [])); exit;
    }

    // UPLOAD BUKTI STRUK
    if ($action === 'upload_bukti' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('kas.create')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        require_once ROOT . '/core/FileUpload.php';
        $up = FileUpload::uploadImage($_FILES['foto'] ?? [], 'uploads/kas_bukti', 't'.$tid.'_o'.$oid);
        if ($up['error']) { echo json_encode(['error'=>$up['error']]); exit; }
        echo json_encode(['path'=>$up['path']]); exit;
    }

    // KATEGORI LIST
    if ($action === 'kategori') {
        $rows = TenantQuery::raw("SELECT DISTINCT kategori FROM hl_kas WHERE tenant_id=? AND outlet_id=? ORDER BY kategori", [$tid, $oid]);
        echo json_encode(array_column($rows, 'kategori')); exit;
    }

    echo json_encode(['error'=>'Unknown']); exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Kas'); ?>
<style>
/* SUMMARY CARDS */
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.sum-card{background:var(--white);border-radius:var(--r-lg);padding:18px 20px;border:1px solid rgba(27,45,90,.07);box-shadow:var(--shadow);position:relative;overflow:hidden;text-align:center}
.sum-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px}
.sum-card.masuk::before{background:linear-gradient(90deg,var(--green),#34D399)}
.sum-card.keluar::before{background:linear-gradient(90deg,#EF4444,#F87171)}
.sum-card.saldo::before{background:linear-gradient(90deg,var(--teal),var(--teal-d))}
.sum-card.order::before{background:linear-gradient(90deg,#8B5CF6,#A78BFA)}
.sum-num{font-size:1.4rem;font-weight:800;color:var(--navy);line-height:1;font-family:var(--mono);margin-bottom:4px}
.sum-num.green{color:var(--green)}
.sum-num.red{color:#EF4444}
.sum-num.teal{color:var(--teal-d)}
.sum-label{font-size:12px;color:var(--gray);font-weight:500}

/* LAYOUT 2 COL */
.layout-2{display:grid;grid-template-columns:360px 1fr;gap:20px;align-items:start}

/* FORM */
.tipe-toggle{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px}
.tipe-btn{padding:12px;border-radius:var(--r);border:2px solid rgba(27,45,90,.12);background:var(--off);cursor:pointer;text-align:center;font-family:var(--font);font-weight:600;font-size:14px;transition:all .2s}
.tipe-btn.masuk.active{background:#D1FAE5;border-color:var(--green);color:#065F46}
.tipe-btn.keluar.active{background:#FEE2E2;border-color:#EF4444;color:#991B1B}
.tipe-btn:not(.active):hover{border-color:var(--teal)}
/* Select native lain (filter tipe) — chevron kustom teal biar seragam */
select.hl-input{
  -webkit-appearance:none;appearance:none;cursor:pointer;padding-right:38px;line-height:1.3;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%231CC4B2' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:right 13px center;
}
/* ── Dropdown kategori KUSTOM (panel sendiri, bukan native OS) ── */
.kat-dd{position:relative}
.kat-trigger{
  width:100%;display:flex;align-items:center;justify-content:space-between;gap:8px;
  padding:11px 14px;border:1.5px solid rgba(27,45,90,.12);border-radius:var(--r);
  background:var(--white);font-family:var(--font);font-size:14px;color:var(--navy);
  cursor:pointer;text-align:left;transition:border-color .15s;
}
.kat-trigger:hover{border-color:var(--teal)}
.kat-trigger.open{border-color:var(--teal-d);box-shadow:0 0 0 3px rgba(53,232,213,.18)}
.kat-trigger .kat-ph{color:#9CA3AF}
.kat-trigger::after{
  content:"";width:16px;height:16px;flex-shrink:0;transition:transform .2s;
  background:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%231CC4B2' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E") no-repeat center;
}
.kat-trigger.open::after{transform:rotate(180deg)}
.kat-panel{
  position:absolute;top:calc(100% + 6px);left:0;right:0;z-index:60;
  background:#fff;border:1px solid #E5E9F2;border-radius:14px;padding:6px;
  box-shadow:0 14px 38px rgba(15,28,58,.18);max-height:300px;overflow-y:auto;
  animation:katIn .14s ease;
}
@keyframes katIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
.kat-group{
  font-size:10.5px;font-weight:800;color:#6B7280;text-transform:uppercase;
  letter-spacing:.06em;padding:9px 12px 5px;
}
.kat-opt{
  display:flex;align-items:center;gap:11px;width:100%;padding:11px 12px;border:0;
  background:none;border-radius:9px;cursor:pointer;font-family:var(--font);
  font-size:14px;color:var(--navy);text-align:left;
}
.kat-opt:hover{background:#F0FDFA}
.kat-opt.is-active{background:#EAFBF8;font-weight:700}
.kat-opt .kat-e{font-size:19px;line-height:1;flex-shrink:0;width:24px;text-align:center}
.kat-opt .kat-l{flex:1}
.kat-opt .kat-ck{color:var(--teal-d);font-weight:800;font-size:15px}

/* TABLE */
.td-jumlah{font-family:var(--mono);font-weight:700;text-align:right;font-size:14px}
.td-masuk{color:var(--green)}
.td-keluar{color:#EF4444}
tfoot tr{background:var(--navy);color:var(--white)}
tfoot td{padding:12px;font-weight:700;font-size:13px}
tfoot td.td-jumlah{font-family:var(--mono)}

/* BADGE */
.b-masuk{background:#D1FAE5;color:#065F46}
.b-keluar{background:#FEE2E2;color:#991B1B}
.b-kat{background:var(--light);color:var(--gray)}

/* SALDO BOX */
.saldo-box{background:linear-gradient(135deg,var(--navy-d, #0F1C3A),var(--navy));border-radius:var(--r-lg);padding:20px;color:var(--white);margin-top:16px}
.sb-row{display:flex;justify-content:space-between;padding:5px 0;font-size:14px}
.sb-label{color:rgba(255,255,255,.6)}
.sb-value{font-family:var(--mono);font-weight:600}
.sb-value.green{color:#6EE7B7}
.sb-value.red{color:#FCA5A5}
.sb-divider{border:none;border-top:1px solid rgba(255,255,255,.15);margin:8px 0}
.sb-saldo{font-size:1.4rem;font-weight:800;color:var(--teal)}
.shortcut-btns{display:flex;gap:6px}
.sc-btn{padding:6px 12px;border-radius:8px;font-size:12px;font-weight:600;border:1.5px solid rgba(27,45,90,.12);background:var(--off);cursor:pointer;font-family:var(--font);transition:all .2s;color:var(--navy)}
.sc-btn:hover,.sc-btn.active{background:var(--teal);color:var(--navy);border-color:var(--teal)}
@media(max-width:860px){.layout-2{grid-template-columns:1fr}.summary-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:680px){.summary-grid{grid-template-columns:repeat(2,1fr);gap:10px}.sum-card{padding:14px}.sum-num{font-size:1.1rem}}
/* Total Periode (tfoot) di mobile: kotak navy full-width rapi, bukan setengah */
@media(max-width:900px){
  .hl-stack-mobile tfoot tr{ background:var(--navy)!important; border:none!important; border-radius:10px; padding:14px 16px!important; margin-top:8px; display:block; width:100% }
  .hl-stack-mobile tfoot td{ display:block!important; width:100%; padding:2px 0!important; border:none!important; color:#fff; text-align:left }
  .hl-stack-mobile tfoot td:empty{ display:none!important }
  .hl-stack-mobile tfoot td::before{ content:none!important }
  #footTotal{ font-size:15px; font-family:var(--mono); font-weight:800 }
}
</style>
</head>
<body>
<?php renderTopbar('kas'); ?>
<div class="hl-main">

  <div class="summary-grid">
    <div class="sum-card masuk"><div class="sum-num green" id="sumMasuk">Rp 0</div><div class="sum-label">💚 Total Kas Masuk</div></div>
    <div class="sum-card keluar"><div class="sum-num red" id="sumKeluar">Rp 0</div><div class="sum-label">❤️ Total Kas Keluar</div></div>
    <div class="sum-card saldo"><div class="sum-num teal" id="sumSaldo">Rp 0</div><div class="sum-label">💎 Saldo Bersih</div></div>
    <div class="sum-card order"><div class="sum-num" id="sumOrder" style="color:#8B5CF6">0</div><div class="sum-label">📋 Transaksi Kas</div></div>
  </div>

  <div class="hl-filter-collapsible">
    <button class="hl-filter-toggle-btn" id="kasFilterBtn" onclick="toggleFilter('kasFilter')">
      🔍 Filter Periode <span class="hl-toggle-arrow">▼</span>
    </button>
    <div class="hl-filter-bar" id="kasFilter">
      <label style="font-size:12px;font-weight:700;color:var(--navy)">Dari</label>
      <input type="date" id="fDari" class="hl-input" style="width:auto" onchange="loadKas()"/>
      <label style="font-size:12px;font-weight:700;color:var(--navy)">s/d</label>
      <input type="date" id="fSampai" class="hl-input" style="width:auto" onchange="loadKas()"/>
      <select id="fTipe" class="hl-input" style="width:auto" onchange="loadKas()">
        <option value="">Semua Tipe</option>
        <option value="masuk">Kas Masuk</option>
        <option value="keluar">Kas Keluar</option>
      </select>
      <div class="shortcut-btns">
        <button class="sc-btn" onclick="setRange('hari',this)">Hari Ini</button>
        <button class="sc-btn active" onclick="setRange('bulan',this)">Bulan Ini</button>
        <button class="sc-btn" onclick="setRange('minggu',this)">7 Hari</button>
      </div>
      <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadKas()" style="margin-left:auto">🔄</button>
    </div>
  </div>

  <div class="layout-2" <?= !hasPermission('kas.create') ? 'style="grid-template-columns:1fr"' : '' ?>>

    <!-- KOLOM KIRI: Form Input (hanya untuk kas.create) -->
    <?php if (hasPermission('kas.create')): ?>
    <div>
      <div class="hl-card">
        <div class="hl-card-header">
          <div class="hl-card-title" id="formTitle">➕ Input Kas</div>
          <button type="button" class="hl-btn hl-btn-outline hl-btn-sm" onclick="document.getElementById('strukFile').click()">📸 Scan Struk</button>
          <input type="file" id="strukFile" accept="image/*" capture="environment" style="display:none" onchange="kasStrukUpload(this)">
        </div>
        <div style="padding:18px">
          <div class="tipe-toggle">
            <button class="tipe-btn masuk active" id="btnMasuk" onclick="setTipe('masuk')">💚 Kas Masuk</button>
            <button class="tipe-btn keluar" id="btnKeluar" onclick="setTipe('keluar')">❤️ Kas Keluar</button>
          </div>
          <input type="hidden" id="f_tipe" value="masuk"/>
          <input type="hidden" id="f_id" value=""/>
          <input type="hidden" id="f_bukti_foto" value=""/>

          <div class="hl-form-row">
            <div class="hl-form-group">
              <label class="hl-label">Tanggal <span class="req">*</span></label>
              <input type="date" id="f_tanggal" class="hl-input"/>
            </div>
            <div class="hl-form-group">
              <label class="hl-label">Jumlah (Rp) <span class="req">*</span></label>
              <input type="text" inputmode="numeric" id="f_jumlah" class="hl-input" placeholder="0" oninput="fmtJumlah(this); updateJumlahPreview()"/>
            </div>
          </div>

          <div class="hl-form-group">
            <label class="hl-label">Kategori <span class="req">*</span></label>
            <!-- Native select disembunyikan: tetap sumber nilai (server & kode existing baca .value/.options) -->
            <select id="f_kategori" style="display:none">
              <option value="">— Pilih Kategori —</option>
              <optgroup label="💚 Kas Masuk" id="optMasuk">
                <option value="Penjualan Laundry">💰 Penjualan Laundry</option>
                <option value="Pelunasan Order">🧾 Pelunasan Order</option>
                <option value="Pendapatan Lain">➕ Pendapatan Lain</option>
                <option value="Modal">🏦 Modal</option>
              </optgroup>
              <optgroup label="❤️ Kas Keluar" id="optKeluar">
                <option value="Gaji Karyawan">👥 Gaji Karyawan</option>
                <option value="Bahan & Deterjen">🧴 Bahan &amp; Deterjen</option>
                <option value="Listrik & Air">⚡ Listrik &amp; Air</option>
                <option value="Sewa Tempat">🏠 Sewa Tempat</option>
                <option value="Peralatan">🔧 Peralatan</option>
                <option value="Transportasi">🛵 Transportasi</option>
                <option value="Operasional">⚙️ Operasional</option>
                <option value="Lain-lain">📌 Lain-lain</option>
              </optgroup>
            </select>
            <!-- Dropdown kustom (panel bisa distyle penuh, bukan native OS) -->
            <div class="kat-dd" id="katDD">
              <button type="button" class="kat-trigger" id="katTrigger" onclick="katToggle(event)">
                <span id="katTriggerLabel" class="kat-ph">— Pilih Kategori —</span>
              </button>
              <div class="kat-panel" id="katPanel" hidden></div>
            </div>
          </div>

          <div class="hl-form-group">
            <label class="hl-label">Keterangan <span class="req">*</span></label>
            <textarea id="f_keterangan" class="hl-input hl-textarea" placeholder="Deskripsi transaksi kas..."></textarea>
          </div>

          <div class="hl-form-group">
            <label class="hl-label">No. Order Terkait (opsional)</label>
            <input type="text" id="f_ref_order" class="hl-input" placeholder="HL-20260501-001"
              style="font-family:var(--mono);text-transform:uppercase;letter-spacing:.06em"
              oninput="this.value=this.value.toUpperCase()"/>
          </div>

          <div id="jumlahPreview" style="display:none;text-align:center;padding:12px;border-radius:var(--r);margin-bottom:12px;font-size:1.2rem;font-weight:800;font-family:var(--mono)"></div>

          <button class="hl-btn hl-btn-primary hl-btn-full" onclick="saveKas()" id="btnSave" style="margin-bottom:8px">💾 Simpan</button>
          <button class="hl-btn hl-btn-outline hl-btn-full" onclick="resetForm()">↺ Reset</button>
        </div>
      </div>

      <!-- SALDO BOX HARI INI -->
      <div class="saldo-box" id="saldoBox">
        <div style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.4);margin-bottom:12px">📊 Ringkasan Hari Ini</div>
        <div class="sb-row"><span class="sb-label">Order Masuk</span><span class="sb-value" id="sbOrder">-</span></div>
        <div class="sb-row"><span class="sb-label">Omset</span><span class="sb-value green" id="sbOmset">-</span></div>
        <div class="sb-row"><span class="sb-label">Terkumpul</span><span class="sb-value green" id="sbTerkumpul">-</span></div>
        <hr class="sb-divider"/>
        <div class="sb-row"><span class="sb-label">Kas Masuk</span><span class="sb-value green" id="sbKasMasuk">-</span></div>
        <div class="sb-row"><span class="sb-label">Kas Keluar</span><span class="sb-value red" id="sbKasKeluar">-</span></div>
        <hr class="sb-divider"/>
        <div class="sb-row"><span style="color:white;font-weight:700">Saldo Bersih</span><span class="sb-saldo" id="sbSaldo">-</span></div>
      </div>
    </div>
    <?php endif; ?>

    <!-- KOLOM KANAN: Tabel -->
    <div>
      <div class="hl-card">
        <div class="hl-card-header">
          <div class="hl-card-title">📋 Riwayat Kas</div>
          <span id="tableInfo" style="font-size:12px;color:var(--gray)"></span>
        </div>
        <div class="hl-table-wrap">
          <table class="hl-table hl-stack-mobile">
            <thead>
              <tr>
                <th>Tanggal</th><th>Tipe</th><th>Kategori</th><th>Keterangan</th>
                <th>Ref Order</th><th style="text-align:right">Jumlah</th><th>Bukti</th><th></th>
              </tr>
            </thead>
            <tbody id="tableBody">
              <tr><td colspan="7" class="hl-loading">⏳ Memuat...</td></tr>
            </tbody>
            <tfoot id="tableFoot" style="display:none">
              <tr>
                <td colspan="4" style="color:rgba(255,255,255,.6)">Total Periode</td>
                <td></td>
                <td class="td-jumlah" id="footTotal"></td>
                <td></td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>

  </div>
</div>

<?php renderToast(); ?>

<!-- MODAL KONFIRMASI HASIL SCAN STRUK -->
<div id="kasStrukModal" class="hl-modal" style="display:none">
  <div class="hl-modal-box" style="max-width:440px">
    <h3 style="margin:0 0 10px">🧾 Hasil Baca Struk</h3>
    <img id="ksImg" style="max-width:100%;max-height:180px;border-radius:8px;display:block;margin:0 auto 10px" src="" alt="Struk"/>
    <label class="hl-label">Jumlah (Rp)</label>
    <input type="number" id="ksJumlah" class="hl-input" min="1" style="margin-bottom:8px">
    <label class="hl-label">Tanggal</label>
    <input type="date" id="ksTanggal" class="hl-input" style="margin-bottom:8px">
    <label class="hl-label">Keterangan</label>
    <input type="text" id="ksKeterangan" class="hl-input" maxlength="500" style="margin-bottom:8px">
    <label class="hl-label">Kategori</label>
    <input type="text" id="ksKategori" class="hl-input" maxlength="50" style="margin-bottom:14px">
    <div style="display:flex;gap:8px">
      <button class="hl-btn hl-btn-outline" style="flex:1" onclick="document.getElementById('kasStrukModal').style.display='none';document.getElementById('strukFile').click()">🔄 Scan Ulang</button>
      <button class="hl-btn hl-btn-outline" onclick="document.getElementById('kasStrukModal').style.display='none'">✕</button>
      <button class="hl-btn hl-btn-primary" style="flex:2" onclick="kasStrukApply()">✓ Terapkan ke Form</button>
    </div>
  </div>
</div>

<script>
const CAN_CREATE_KAS = <?= hasPermission('kas.create') ? 'true' : 'false' ?>;
const CAN_DEL_KAS    = <?= hasPermission('kas.delete') ? 'true' : 'false' ?>;

function localDateStr(d) {
  const dt = d || new Date();
  return dt.getFullYear()+'-'+String(dt.getMonth()+1).padStart(2,'0')+'-'+String(dt.getDate()).padStart(2,'0');
}

document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('f_tanggal').value = localDateStr();
  setRange('bulan');
  loadSaldoHarian();
});

// ── Dropdown kategori kustom ──
const KAT = {
  masuk: [
    {v:'Penjualan Laundry', e:'💰'}, {v:'Pelunasan Order', e:'🧾'},
    {v:'Pendapatan Lain', e:'➕'},  {v:'Modal', e:'🏦'},
  ],
  keluar: [
    {v:'Gaji Karyawan', e:'👥'}, {v:'Bahan & Deterjen', e:'🧴'}, {v:'Listrik & Air', e:'⚡'},
    {v:'Sewa Tempat', e:'🏠'},   {v:'Peralatan', e:'🔧'},        {v:'Transportasi', e:'🛵'},
    {v:'Operasional', e:'⚙️'},   {v:'Lain-lain', e:'📌'},
  ],
};
function katEsc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;'); }
function katMeta(v){ for (const t of ['masuk','keluar']) { const o = KAT[t].find(x=>x.v===v); if (o) return o; } return null; }
// Sinkron label trigger dari nilai hidden select (sumber kebenaran)
function katSync(){
  const v = document.getElementById('f_kategori').value;
  const lbl = document.getElementById('katTriggerLabel');
  const m = katMeta(v);
  if (m) { lbl.textContent = m.e + ' ' + m.v; lbl.classList.remove('kat-ph'); }
  else   { lbl.textContent = '— Pilih Kategori —'; lbl.classList.add('kat-ph'); }
}
function katRender(tipe){
  const cur = document.getElementById('f_kategori').value;
  const head = tipe === 'masuk' ? '💚 Kas Masuk' : '❤️ Kas Keluar';
  let html = '<div class="kat-group">' + head + '</div>';
  KAT[tipe].forEach(o => {
    const act = o.v === cur ? ' is-active' : '';
    html += '<button type="button" class="kat-opt' + act + '" data-v="' + katEsc(o.v) + '">'
          + '<span class="kat-e">' + o.e + '</span><span class="kat-l">' + katEsc(o.v) + '</span>'
          + (act ? '<span class="kat-ck">✓</span>' : '') + '</button>';
  });
  document.getElementById('katPanel').innerHTML = html;
}
function katToggle(e){
  e.stopPropagation();
  const p = document.getElementById('katPanel');
  if (p.hidden) { katRender(document.getElementById('f_tipe').value); p.hidden = false;
                  document.getElementById('katTrigger').classList.add('open'); }
  else katClose();
}
function katClose(){
  const p = document.getElementById('katPanel'); if (p) p.hidden = true;
  document.getElementById('katTrigger')?.classList.remove('open');
}
function katPick(v){ document.getElementById('f_kategori').value = v; katSync(); katClose(); }
document.getElementById('katPanel').addEventListener('click', e => {
  const b = e.target.closest('.kat-opt'); if (b) katPick(b.dataset.v);
});
document.addEventListener('click', e => { if (!e.target.closest('#katDD')) katClose(); });

function setTipe(tipe) {
  document.getElementById('f_tipe').value = tipe;
  document.getElementById('btnMasuk').classList.toggle('active', tipe==='masuk');
  document.getElementById('btnKeluar').classList.toggle('active', tipe==='keluar');
  // Kategori mengikuti tipe: kosongkan pilihan bila milik tipe lawan, re-render panel bila terbuka
  const sel = document.getElementById('f_kategori');
  if (sel.value && !KAT[tipe].some(o => o.v === sel.value)) sel.value = '';
  katSync();
  const p = document.getElementById('katPanel'); if (p && !p.hidden) katRender(tipe);
  updateJumlahPreview();
}
setTipe('masuk'); // init: default masuk

// Pemisah ribuan saat ketik (id-ID, hanya angka)
function fmtJumlah(el){
  const c = el.value.replace(/[^\d]/g,'');
  el.value = c ? Number(c).toLocaleString('id-ID') : '';
}
// Nilai angka murni dari field jumlah (buang pemisah ribuan)
function jumlahVal(){
  return parseInt(String(document.getElementById('f_jumlah').value).replace(/[^\d]/g,''),10) || 0;
}
function updateJumlahPreview() {
  const jumlah = jumlahVal();
  const tipe   = document.getElementById('f_tipe').value;
  const el     = document.getElementById('jumlahPreview');
  if (jumlah <= 0) { el.style.display='none'; return; }
  el.style.display = 'block';
  el.style.background = tipe==='masuk' ? '#D1FAE5' : '#FEE2E2';
  el.style.color = tipe==='masuk' ? '#065F46' : '#991B1B';
  el.textContent = (tipe==='masuk' ? '+ ' : '- ') + 'Rp ' + jumlah.toLocaleString('id-ID');
}

function setRange(type, el) {
  const now = new Date();
  let dari, sampai = localDateStr(now);
  if (type === 'hari') {
    dari = sampai;
  } else if (type === 'minggu') {
    const w = new Date(now); w.setDate(w.getDate()-6); dari = localDateStr(w);
  } else {
    dari = now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0')+'-01';
  }
  document.getElementById('fDari').value   = dari;
  document.getElementById('fSampai').value = sampai;
  document.querySelectorAll('.sc-btn').forEach(b=>b.classList.remove('active'));
  if (el) el.classList.add('active');
  loadKas();
}

async function loadKas() {
  const dari   = document.getElementById('fDari').value;
  const sampai = document.getElementById('fSampai').value;
  const tipe   = document.getElementById('fTipe').value;
  document.getElementById('tableBody').innerHTML = Array.from({length:5}).map(()=>
    `<tr><td colspan="8" style="padding:0;border-bottom:1px solid var(--light)">
      <div class="hl-skel-row" style="padding:12px 14px">
        <span class="hl-skel" style="width:80px"></span>
        <span class="hl-skel" style="width:140px"></span>
        <span class="hl-skel" style="width:60px;margin-left:auto"></span>
      </div></td></tr>`).join('');

  const r = await fetch(`kas.php?action=list&dari=${dari}&sampai=${sampai}&tipe=${tipe}`);
  const d = await r.json();
  const sm = d.summary;
  const masuk  = parseFloat(sm.total_masuk||0);
  const keluar = parseFloat(sm.total_keluar||0);
  const saldo  = masuk - keluar;

  document.getElementById('sumMasuk').textContent  = 'Rp '+masuk.toLocaleString('id-ID');
  document.getElementById('sumKeluar').textContent = 'Rp '+keluar.toLocaleString('id-ID');
  document.getElementById('sumSaldo').textContent  = 'Rp '+saldo.toLocaleString('id-ID');
  document.getElementById('sumOrder').textContent  = sm.total_transaksi||0;
  document.getElementById('sumSaldo').style.color  = saldo>=0?'var(--green)':'#EF4444';

  if (!d.data?.length) {
    document.getElementById('tableBody').innerHTML = '<tr><td colspan="8" class="hl-empty">📭 Belum ada data kas untuk periode ini.</td></tr>';
    document.getElementById('tableFoot').style.display = 'none';
    document.getElementById('tableInfo').textContent = '';
    return;
  }

  document.getElementById('tableBody').innerHTML = d.data.map(row => `
    <tr>
      <td data-lbl="Tanggal" style="white-space:nowrap;font-size:13px">${fmtDate(row.tanggal)}</td>
      <td data-lbl="Tipe"><span class="hl-badge b-${row.tipe}">${row.tipe==='masuk'?'💚 Masuk':'❤️ Keluar'}</span></td>
      <td data-lbl="Kategori"><span class="hl-badge b-kat" style="background:var(--light);color:var(--gray)">${esc(row.kategori)}</span></td>
      <td data-lbl="Keterangan" style="font-size:13px;max-width:200px">${esc(row.keterangan)}</td>
      <td data-lbl="Ref Order" style="font-family:var(--mono);font-size:12px;color:var(--teal-d)">${row.ref_order||'-'}</td>
      <td data-lbl="Jumlah" class="td-jumlah ${row.tipe==='masuk'?'td-masuk':'td-keluar'}">
        ${row.tipe==='masuk'?'+':'-'} Rp ${parseFloat(row.jumlah).toLocaleString('id-ID')}
      </td>
      <td data-lbl="Bukti" style="text-align:center">
        ${row.bukti_foto ? `<a href="/${esc(row.bukti_foto)}" target="_blank" title="Lihat bukti struk">🧾</a>` : ''}
      </td>
      <td>
        <div style="display:flex;gap:4px">
          ${CAN_CREATE_KAS ? `<button class="hl-btn hl-btn-outline hl-btn-sm" onclick="editKas(${row.id})">✏️ Edit</button>` : ''}
          ${CAN_DEL_KAS    ? `<button class="hl-btn hl-btn-sm" style="background:#FEE2E2;color:#991B1B" onclick="deleteKas(${row.id})">🗑️ Hapus</button>` : ''}
        </div>
      </td>
    </tr>`).join('');

  document.getElementById('tableFoot').style.display = '';
  document.getElementById('footTotal').innerHTML =
    `<span style="color:#6EE7B7">+ Rp ${masuk.toLocaleString('id-ID')}</span>` +
    ` / <span style="color:#FCA5A5">- Rp ${keluar.toLocaleString('id-ID')}</span>` +
    ` = <span style="color:${saldo>=0?'var(--teal)':'#FCA5A5'}">Rp ${saldo.toLocaleString('id-ID')}</span>`;
  document.getElementById('tableInfo').textContent = `${d.data.length} transaksi`;
}

async function loadSaldoHarian() {
  const r = await fetch('kas.php?action=summary_harian&tgl='+localDateStr());
  const d = await r.json();
  document.getElementById('sbOrder').textContent     = (d.total_order||0)+' order';
  document.getElementById('sbOmset').textContent     = 'Rp '+parseFloat(d.omset||0).toLocaleString('id-ID');
  document.getElementById('sbTerkumpul').textContent = 'Rp '+parseFloat(d.terkumpul||0).toLocaleString('id-ID');
  document.getElementById('sbKasMasuk').textContent  = 'Rp '+parseFloat(d.kas_masuk||0).toLocaleString('id-ID');
  document.getElementById('sbKasKeluar').textContent = 'Rp '+parseFloat(d.kas_keluar||0).toLocaleString('id-ID');
  const saldo = parseFloat(d.kas_masuk||0) - parseFloat(d.kas_keluar||0);
  document.getElementById('sbSaldo').textContent = 'Rp '+saldo.toLocaleString('id-ID');
  document.getElementById('sbSaldo').style.color = saldo>=0?'var(--teal)':'#FCA5A5';
}

async function saveKas() {
  const jumlah   = jumlahVal();
  const ket      = document.getElementById('f_keterangan').value.trim();
  const kategori = document.getElementById('f_kategori').value;
  if (jumlah<=0)  { showToast('⚠️ Jumlah harus lebih dari 0','error'); return; }
  if (!ket)       { showToast('⚠️ Keterangan wajib diisi','error'); return; }
  if (!kategori)  { showToast('⚠️ Pilih kategori','error'); return; }

  const btn = document.getElementById('btnSave');
  btn.disabled = true; btn.textContent = '⏳ Menyimpan...';

  const r = await fetch('kas.php?action=save', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({
      id: document.getElementById('f_id').value||null,
      tanggal: document.getElementById('f_tanggal').value,
      tipe: document.getElementById('f_tipe').value,
      kategori, keterangan:ket, jumlah,
      ref_order: document.getElementById('f_ref_order').value||null,
      bukti_foto: document.getElementById('f_bukti_foto').value||null,
    })
  });
  const d = await r.json();
  if (d.success) { showToast('✅ Kas berhasil disimpan!','success'); resetForm(); loadKas(); loadSaldoHarian(); }
  else showToast('❌ '+(d.error||'Gagal'),'error');
  btn.disabled=false; btn.textContent='💾 Simpan';
}

async function editKas(id) {
  const r = await fetch(`kas.php?action=list&dari=2020-01-01&sampai=2030-12-31`);
  const d = await r.json();
  const row = d.data.find(x => x.id==id);
  if (!row) return;
  document.getElementById('f_id').value         = row.id;
  document.getElementById('f_tanggal').value    = row.tanggal;
  document.getElementById('f_jumlah').value     = Number(row.jumlah||0).toLocaleString('id-ID');
  document.getElementById('f_keterangan').value = row.keterangan;
  document.getElementById('f_kategori').value   = row.kategori;
  katSync();
  document.getElementById('f_ref_order').value  = row.ref_order||'';
  document.getElementById('f_bukti_foto').value = row.bukti_foto||'';
  setTipe(row.tipe); updateJumlahPreview();
  document.getElementById('formTitle').textContent = '✏️ Edit Kas #'+row.id;
  document.getElementById('btnSave').textContent = '💾 Update';
  document.querySelector('.hl-card').scrollIntoView({behavior:'smooth'});
  showToast('📝 Edit mode — ubah data lalu klik Simpan','success');
}

async function deleteKas(id) {
  if (!await lmConfirm('Hapus catatan kas ini?')) return;
  const r = await fetch('kas.php?action=delete', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({id})
  });
  const d = await r.json();
  if (d.success) { showToast('🗑️ Dihapus','success'); loadKas(); loadSaldoHarian(); }
  else showToast('❌ Gagal hapus','error');
}

function resetForm() {
  document.getElementById('f_id').value='';
  document.getElementById('f_jumlah').value='';
  document.getElementById('f_keterangan').value='';
  document.getElementById('f_kategori').value='';
  katSync();
  document.getElementById('f_ref_order').value='';
  document.getElementById('f_tanggal').value=localDateStr();
  document.getElementById('f_bukti_foto').value='';
  document.getElementById('jumlahPreview').style.display='none';
  document.getElementById('formTitle').textContent='➕ Input Kas';
  document.getElementById('btnSave').textContent='💾 Simpan';
  setTipe('masuk');
}

function fmtDate(d){if(!d)return'-';return new Date(d).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'})}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}

// ===== SCAN STRUK =====
let _strukParsed = null, _strukPath = null;

async function kasStrukUpload(input) {
  const f = input.files && input.files[0];
  input.value = '';
  if (!f) return;
  showToast('📤 Mengunggah…', 'info');
  try {
    const fd = new FormData();
    fd.append('foto', f);
    const up = await fetch('kas.php?action=upload_bukti', { method: 'POST', body: fd });
    const ud = await up.json();
    if (ud.error) { showToast(ud.error, 'error'); return; }
    showToast('🧠 Membaca struk…', 'info');
    const r = await fetch('/api/kas_struk_scan.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ foto_path: ud.path })
    });
    const d = await r.json();
    if (!d.ok) {
      const msg = ({
        rate_limited: 'Limit AI harian tercapai',
        insufficient_coin: 'Coin tak cukup',
        ai_error: 'Gagal membaca struk, coba foto lebih jelas',
        not_receipt: 'Bukan struk / total tak terbaca',
        bad_path: 'File tidak valid',
        forbidden: 'Akses ditolak'
      })[d.reason] || 'Gagal scan struk';
      showToast(msg, 'error'); return;
    }
    _strukParsed = d.parsed; _strukPath = d.foto_path;
    kasStrukShowModal(d.parsed, d.foto_path);
  } catch (e) { showToast('Gagal koneksi: ' + (e.message || e), 'error'); }
}

function kasStrukShowModal(p, path) {
  document.getElementById('ksImg').src = '/' + path;
  document.getElementById('ksJumlah').value     = p.jumlah || '';
  document.getElementById('ksTanggal').value    = p.tanggal || new Date().toISOString().slice(0, 10);
  document.getElementById('ksKeterangan').value = p.keterangan || '';
  document.getElementById('ksKategori').value   = p.kategori || '';
  document.getElementById('kasStrukModal').style.display = 'flex';
}

function kasStrukApply() {
  // Isi form kas (real field ids: f_tanggal, f_jumlah, f_keterangan, f_kategori, f_tipe, f_bukti_foto)
  // Set tipe keluar via setTipe(), TIDAK auto-submit
  setTipe('keluar');
  document.getElementById('f_tanggal').value    = document.getElementById('ksTanggal').value;
  document.getElementById('f_jumlah').value     = Number(String(document.getElementById('ksJumlah').value).replace(/[^\d]/g,'')||0).toLocaleString('id-ID');
  document.getElementById('f_keterangan').value = document.getElementById('ksKeterangan').value;
  // Kategori: coba set select; jika tidak match, biarkan user memilih
  const katEl = document.getElementById('f_kategori');
  const katVal = document.getElementById('ksKategori').value;
  let katFound = false;
  for (let i = 0; i < katEl.options.length; i++) {
    if (katEl.options[i].value === katVal) { katEl.value = katVal; katFound = true; break; }
  }
  if (!katFound) katEl.value = '';
  katSync();
  document.getElementById('f_bukti_foto').value = _strukPath || '';
  updateJumlahPreview();
  document.getElementById('kasStrukModal').style.display = 'none';
  showToast('Form terisi dari struk' + (katFound ? '' : ' — pilih kategori') + ' — cek & Simpan', 'success');
}
</script>
</body>
</html>

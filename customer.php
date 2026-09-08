<?php
$activePage = 'customer';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once __DIR__ . '/components.php';
$user = currentUser();
requirePermission('pelanggan.view');

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $tid = TenantResolver::id();
    $oid = TenantResolver::outletId();

    if ($action === 'list') {
        $q       = $_GET['q'] ?? '';
        $tipe    = $_GET['tipe'] ?? '';
        $segmen  = $_GET['segmen'] ?? '';
        $tier    = $_GET['tier'] ?? '';
        $page    = max(1, intval($_GET['page'] ?? 1));
        $limit   = 24;
        $offset  = ($page - 1) * $limit;

        // Customer = tenant-scoped (lintas outlet), konsisten dgn stats/segmen_stats.
        // Riwayat order tetap outlet-scoped lewat get_orders.
        $where = ['p.tenant_id = ?']; $params = [$tid];
        if ($q) { $where[] = '(p.nama LIKE ? OR p.telepon LIKE ? OR p.alamat LIKE ?)'; $like="%$q%"; $params=array_merge($params,[$like,$like,$like]); }
        if ($tipe)   { $where[] = 'p.tipe=?';   $params[] = $tipe; }
        if ($segmen && in_array($segmen, ['baru','regular','vip','dormant'], true)) {
            $where[] = 'p.segmen=?'; $params[] = $segmen;
        }
        if ($tier && in_array($tier, ['regular','silver','gold','platinum'], true)) {
            $where[] = 'p.tier=?'; $params[] = $tier;
        }

        $whereStr = implode(' AND ', $where);

        $countRows = TenantQuery::raw("SELECT COUNT(DISTINCT p.id) as c FROM hl_pelanggan p WHERE $whereStr", $params);
        $total = intval($countRows[0]['c'] ?? 0);

        $dataParams = array_merge($params, []);
        $rows = TenantQuery::raw(
            "SELECT p.*,
                COUNT(t.id) as total_order,
                COALESCE(SUM(t.total),0) as total_omset,
                MAX(t.tanggal) as last_order
                FROM hl_pelanggan p
                LEFT JOIN hl_transaksi t ON t.pelanggan_id = p.id AND t.tenant_id = p.tenant_id
                WHERE $whereStr
                GROUP BY p.id
                ORDER BY p.nama
                LIMIT {$limit} OFFSET {$offset}",
            $dataParams
        );

        echo json_encode([
            'data'        => $rows,
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => ceil($total / $limit),
        ]); exit;
    }

    if ($action === 'save' && $_SERVER['REQUEST_METHOD']==='POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        if (!empty($d['id']) && !hasPermission('pelanggan.edit')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        if (empty($d['id']) && !hasPermission('pelanggan.create')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();

        $metodeBayar = $d['metode_bayar'] ?? 'langsung';
        $nama    = substr(trim(strip_tags($d['nama'] ?? '')), 0, 100);
        $telepon = substr(trim(strip_tags(preg_replace('/[^0-9+\-\s]/', '', $d['telepon'] ?? ''))), 0, 20);
        $alamat  = substr(trim(strip_tags($d['alamat'] ?? '')), 0, 300);
        $catatan = substr(trim(strip_tags($d['catatan'] ?? '')), 0, 300);
        // Kolom hl_pelanggan.tipe cuma terima enum('retail','korporat','bulanan') —
        // 'b2b' BUKAN nilai valid, kalau lolos ke query MySQL diam-diam nyimpen '' (data korup
        // tanpa error). Terima 'b2b' dari form lama/cache tapi map ke 'korporat' yang beneran ada.
        $tipeIn  = $d['tipe'] ?? '';
        $tipe    = $tipeIn === 'b2b' ? 'korporat' : (in_array($tipeIn, ['retail','korporat'], true) ? $tipeIn : 'retail');

        if (!$nama) { echo json_encode(['error'=>'Nama wajib diisi']); exit; }

        if (!empty($d['id'])) {
            TenantQuery::update('hl_pelanggan', [
                'nama'        => $nama,
                'telepon'     => $telepon,
                'alamat'      => $alamat,
                'tipe'        => $tipe,
                'catatan'     => $catatan,
                'metode_bayar'=> $metodeBayar,
                'is_active'   => intval($d['is_active'] ?? 1),
            ], 'id = ?', [intval($d['id'])]);
        } else {
            // Cek duplikat telepon (TENANT-SCOPED — lintas outlet)
            // Sesuai brief: 1 nomor HP unique per tenant
            if (!empty($telepon)) {
                if (TenantQuery::exists('hl_pelanggan', 'telepon = ?', [$telepon])) {
                    echo json_encode(['error'=>'Nomor HP sudah terdaftar di akun ini (cek di outlet lain)']);
                    exit;
                }
            }
            $currentOid = TenantResolver::outletId();
            TenantQuery::insert('hl_pelanggan', [
                'nama'                 => $nama,
                'telepon'              => $telepon,
                'alamat'               => $alamat,
                'tipe'                 => $tipe,
                'catatan'              => $catatan,
                'metode_bayar'         => $metodeBayar,
                'registered_outlet_id' => $currentOid, // catat outlet pertama daftar
                'outlet_id'            => $currentOid, // legacy compat
                'portal_token'         => bin2hex(random_bytes(16)),
            ]);
        }
        logAudit(!empty($d['id'])?'update':'create','customer',(!empty($d['id'])?'Edit':'Tambah').' customer: '.$nama);
        echo json_encode(['success'=>true]); exit;
    }

    // Ambil 1 pelanggan by id — dipakai deep-link (?open=<id>) dari halaman lain
    // (mis. detail order) krn daftar di 'list' ter-paginasi, gak selalu ada di
    // halaman yang lagi dimuat browser.
    if ($action === 'get') {
        $id = intval($_GET['id'] ?? 0);
        $rows = TenantQuery::raw(
            "SELECT p.*,
                COUNT(t.id) as total_order,
                COALESCE(SUM(t.total),0) as total_omset,
                MAX(t.tanggal) as last_order
                FROM hl_pelanggan p
                LEFT JOIN hl_transaksi t ON t.pelanggan_id = p.id AND t.tenant_id = p.tenant_id
                WHERE p.tenant_id = ? AND p.id = ?
                GROUP BY p.id",
            [$tid, $id]
        );
        echo json_encode(['data' => $rows[0] ?? null]); exit;
    }

    if ($action === 'get_orders') {
        $id = intval($_GET['id']);
        $rows = TenantQuery::raw(
            "SELECT t.id,t.no_order,t.tanggal,t.total,t.status_proses,t.status_bayar,
                GROUP_CONCAT(i.nama_layanan SEPARATOR ', ') as layanan
                FROM hl_transaksi t
                LEFT JOIN hl_transaksi_item i ON i.transaksi_id=t.id AND i.tenant_id=t.tenant_id AND i.outlet_id=t.outlet_id
                WHERE t.tenant_id = ? AND t.outlet_id = ? AND t.pelanggan_id = ?
                GROUP BY t.id ORDER BY t.tanggal DESC LIMIT 20",
            [$tid, $oid, $id]
        );
        echo json_encode($rows); exit;
    }

    if ($action === 'stats') {
        $total = TenantQuery::count('hl_pelanggan', 'is_active=1');
        $b2b   = TenantQuery::count('hl_pelanggan', "tipe='korporat' AND is_active=1");
        $baru  = TenantQuery::count('hl_pelanggan', 'MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())');
        echo json_encode(['total'=>$total,'b2b'=>$b2b,'baru'=>$baru]); exit;
    }

    // Save preferensi pelanggan
    if ($action === 'save_preferensi' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hasPermission('pelanggan.edit')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d  = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($d['id'] ?? 0);
        if (!$id) { echo json_encode(['error'=>'id wajib']); exit; }
        try {
            Database::get()
                ->prepare("UPDATE hl_pelanggan
                              SET preferensi_parfum=?, preferensi_suhu=?, catatan_tetap=?
                            WHERE id=? AND tenant_id=?")
                ->execute([
                    substr(trim($d['parfum'] ?? ''), 0, 50),
                    substr(trim($d['suhu'] ?? ''), 0, 20),
                    substr(trim($d['catatan_tetap'] ?? ''), 0, 1000),
                    $id, $tid,
                ]);
            logAudit('update_preferensi', 'customer#'.$id, '');
            echo json_encode(['ok'=>true]);
        } catch (Throwable $e) {
            apiErr($e);
        }
        exit;
    }

    // Stats agregat per-segmen
    if ($action === 'segmen_stats') {
        require_once ROOT . '/core/SegmentasiManager.php';
        echo json_encode(['ok'=>true, 'stats'=>SegmentasiManager::stats($tid)]);
        exit;
    }

    // Force re-run segmentasi (untuk tombol manual refresh)
    if ($action === 'segmen_refresh' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hasPermission('pelanggan.edit') && !hasPermission('pelanggan.view')) {
            echo json_encode(['error'=>'Akses ditolak']); exit;
        }
        require_once ROOT . '/core/SegmentasiManager.php';
        $changed = SegmentasiManager::updateAll($tid, $oid, true);
        echo json_encode(['ok'=>true, 'changed'=>$changed]);
        exit;
    }

    if ($action === 'regen_token' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        requirePermission('pelanggan.edit');
        $d  = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($d['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['error'=>'Input invalid']); exit; }
        $newToken = bin2hex(random_bytes(16));
        $st = Database::get()->prepare("UPDATE hl_pelanggan SET portal_token=? WHERE id=? AND tenant_id=?");
        $st->execute([$newToken, $id, $tid]);
        if (!$st->rowCount()) { echo json_encode(['error'=>'Tidak ditemukan']); exit; }
        logAudit('portal_token_regen', 'pelanggan', "pelanggan_id=$id");
        echo json_encode(['ok'=>true]);
        exit;
    }

    echo json_encode(['error'=>'Unknown']); exit;
}

// Metode pembayaran aktif outlet ini — dipakai modal Bayar (persis pola orders.php)
$_pmTid = TenantResolver::id(); $_pmOid = TenantResolver::outletId();
$_pmStmt = Database::get()->prepare(
    "SELECT code, label, emoji FROM hl_payment_methods
     WHERE tenant_id=? AND outlet_id=? AND is_active=1 ORDER BY sort_order, id"
);
$_pmStmt->execute([$_pmTid, $_pmOid]);
$activeMethods = $_pmStmt->fetchAll(PDO::FETCH_ASSOC);
if (!$activeMethods) {
    $activeMethods = [
        ['code'=>'cash',     'label'=>'Tunai',         'emoji'=>'💵'],
        ['code'=>'transfer', 'label'=>'Transfer Bank', 'emoji'=>'🏦'],
        ['code'=>'qris',     'label'=>'QRIS',          'emoji'=>'📱'],
    ];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Customer'); ?>
<style>
/* SEGMEN bar — header sendiri + chips grid rapi di HP (desktop tetap sebaris) */
.seg-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;gap:8px}
.seg-title{font-size:12px;color:var(--gray);font-weight:700;letter-spacing:.05em}
.seg-note{font-size:11px;color:var(--gray)}
.seg-pills{display:flex;gap:8px;flex-wrap:wrap}
.seg-pill{cursor:pointer;padding:7px 11px;border-radius:8px;font-size:12px;font-weight:600;display:flex;align-items:center;gap:6px}
.seg-pill strong{margin-left:2px}
@media(max-width:680px){
  .seg-pills{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}
  .seg-pill{justify-content:space-between;padding:9px 12px}
  .seg-pill strong{margin-left:auto;font-size:13px}
}
.cust-card{background:var(--white);border-radius:var(--r-lg);border:1px solid rgba(27,45,90,.07);padding:18px;transition:all .2s;cursor:pointer}
.cust-card:hover{box-shadow:var(--shadow-lg);transform:translateY(-1px);border-color:var(--teal)}
.cust-nama{font-size:15px;font-weight:700;color:var(--navy);margin-bottom:4px}
.cust-telp{font-size:13px;color:var(--gray);margin-bottom:8px}
.cust-stats{display:flex;gap:12px;font-size:12px;color:var(--gray)}
.cust-stat{display:flex;flex-direction:column;align-items:center}
.cust-stat strong{font-size:15px;font-weight:800;color:var(--navy);font-family:var(--mono)}

/* LIST VIEW */
.cust-list-item{background:var(--white);border-radius:var(--r-lg);border:1px solid rgba(27,45,90,.07);padding:14px 18px;display:flex;align-items:center;gap:16px;transition:all .2s;cursor:pointer}
.cust-list-item:hover{box-shadow:var(--shadow-lg);border-color:var(--teal)}
.cust-list-avatar{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,var(--navy),var(--teal-d));color:white;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:16px;flex-shrink:0}
.cust-list-info{flex:1;min-width:0}
.cust-list-nama{font-size:14px;font-weight:700;color:var(--navy);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cust-list-telp{font-size:12px;color:var(--gray)}
.cust-list-stats{display:flex;gap:20px;flex-shrink:0}
.cust-list-stat{text-align:right;font-size:12px;color:var(--gray)}
.cust-list-stat strong{display:block;font-size:13px;font-weight:700;color:var(--navy);font-family:var(--mono)}

/* TOGGLE BTN */
.view-toggle{display:flex;gap:4px;background:var(--light);border-radius:8px;padding:3px}
.view-btn{padding:5px 10px;border-radius:6px;border:none;cursor:pointer;font-size:13px;background:transparent;transition:all .2s;color:var(--gray)}
.view-btn.active{background:var(--white);color:var(--navy);box-shadow:0 1px 4px rgba(27,45,90,.1)}
@media(max-width:680px){
  .cust-list-stats{gap:12px}
  .cust-list-stat strong{font-size:12px}
  .cust-card{padding:14px}
}
@media(max-width:400px){
  .cust-list-stats{display:none}
}

/* MODAL BAYAR (persis pola orders.php, di-scope #modalBayar semua supaya
   gak nyenggol style input/select/textarea/tombol lain di halaman ini). */
#modalBayar.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,28,58,.6);backdrop-filter:blur(4px);z-index:400;align-items:center;justify-content:center;padding:16px}
#modalBayar.modal-overlay.open{display:flex}
#modalBayar .modal{background:var(--white);border-radius:var(--r-lg);width:480px;max-width:95vw;max-height:90vh;overflow-y:auto;box-shadow:var(--shadow-lg);display:flex;flex-direction:column}
#modalBayar .modal-header{padding:18px 20px;border-bottom:1px solid var(--light);display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:var(--white);z-index:10}
#modalBayar .modal-title{font-size:15px;font-weight:700;color:var(--navy)}
#modalBayar .modal-close{background:none;border:none;font-size:18px;cursor:pointer;color:var(--gray);padding:4px}
#modalBayar .modal-body{padding:20px;flex:1;overflow-y:auto}
#modalBayar .modal-footer{padding:16px 20px;border-top:1px solid var(--light);display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;position:sticky;bottom:0;background:var(--white)}
#modalBayar .form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:12px}
#modalBayar label{font-size:11px;font-weight:700;color:var(--navy);letter-spacing:.05em;text-transform:uppercase}
#modalBayar .req{color:var(--red)}
#modalBayar input,#modalBayar select{padding:9px 12px;border:1.5px solid rgba(27,45,90,.14);border-radius:var(--r);font-family:var(--font);font-size:14px;color:var(--dark);background:var(--off);outline:none;transition:all .2s;width:100%}
#modalBayar input:focus,#modalBayar select:focus{border-color:var(--teal);background:var(--white);box-shadow:0 0 0 3px rgba(53,232,213,.1)}
#modalBayar .pay-opt{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px}
#modalBayar .pay-btn{padding:16px;border-radius:var(--r);border:2px solid rgba(27,45,90,.12);background:var(--off);cursor:pointer;text-align:center;transition:all .2s;font-family:var(--font)}
#modalBayar .pay-btn:hover{border-color:var(--teal)}
#modalBayar .pay-btn.selected{border-color:var(--teal);background:var(--teal-bg)}
#modalBayar .pay-btn .pay-icon{font-size:1.6rem;display:block;margin-bottom:6px}
#modalBayar .pay-btn .pay-label{font-size:13px;font-weight:700;color:var(--navy)}
#modalBayar .pay-btn .pay-sub{font-size:11px;color:var(--gray);margin-top:2px}
#modalBayar .bukti-preview{width:100%;max-height:160px;object-fit:cover;border-radius:var(--r);margin-top:8px;display:none}
#modalBayar .bukti-drop{border:2px dashed rgba(27,45,90,.18);border-radius:var(--r);padding:20px;text-align:center;cursor:pointer;transition:all .2s;background:var(--off)}
#modalBayar .bukti-drop:hover{border-color:var(--teal);background:var(--teal-bg)}
#modalBayar .bukti-drop p{font-size:13px;color:var(--gray);margin-top:6px}
#modalBayar .btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:9px 16px;border-radius:var(--r);font-family:var(--font);font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;border:none}
#modalBayar .btn-primary{background:var(--teal);color:var(--navy-d)}
#modalBayar .btn-primary:hover{background:var(--teal-d)}
#modalBayar .btn-outline{background:transparent;color:var(--navy);border:1.5px solid rgba(27,45,90,.2)}
#modalBayar .btn-outline:hover{background:var(--light)}
#modalBayar .btn-sm{padding:6px 12px;font-size:12px}
@media(max-width:680px){
  #modalBayar .modal{width:100%;max-width:100%;border-radius:var(--r-lg) var(--r-lg) 0 0;max-height:92vh}
  #modalBayar.modal-overlay{align-items:flex-end;padding:0}
  #modalBayar .pay-opt{grid-template-columns:1fr 1fr}
}
</style>
</head>
<body>
<?php renderTopbar('customer'); ?>
<div class="hl-main">

  <div class="hl-stat-grid-4" style="margin-bottom:20px">
    <div class="hl-stat-card teal"><div class="hl-stat-num" id="sTotal">-</div><div class="hl-stat-label">👥 Total Customer</div></div>
    <div class="hl-stat-card navy"><div class="hl-stat-num" id="sB2B">-</div><div class="hl-stat-label">🏢 B2B / Korporat</div></div>
    <div class="hl-stat-card green"><div class="hl-stat-num" id="sBaru">-</div><div class="hl-stat-label">✨ Baru Bulan Ini</div></div>
    <div class="hl-stat-card purple">
      <?php if (hasPermission('pelanggan.create')): ?>
      <button class="hl-btn hl-btn-primary hl-btn-full" onclick="openModal()" style="margin-top:4px">+ Tambah Customer</button>
      <?php else: ?>
      <span style="font-size:12px;color:rgba(255,255,255,.55)">View Only</span>
      <?php endif; ?>
    </div>
  </div>

  <!-- SEGMEN STATS -->
  <div id="segmenStatsBar" style="display:none;background:#fff;border:1px solid rgba(27,45,90,.07);border-radius:12px;padding:12px 14px;margin-bottom:16px">
    <div class="seg-head">
      <span class="seg-title">SEGMEN</span>
      <span class="seg-note">Update otomatis 1x/hari</span>
    </div>
    <div class="seg-pills">
      <div class="seg-pill" data-seg="baru" onclick="filterSegmen('baru')" style="background:#DBEAFE;color:#1E40AF">🆕 Baru <strong id="segCntBaru">0</strong></div>
      <div class="seg-pill" data-seg="regular" onclick="filterSegmen('regular')" style="background:#F1F5F9;color:#475569">Regular <strong id="segCntRegular">0</strong></div>
      <div class="seg-pill" data-seg="vip" onclick="filterSegmen('vip')" style="background:#FEF3C7;color:#92400E">⭐ VIP <strong id="segCntVip">0</strong></div>
      <div class="seg-pill" data-seg="dormant" onclick="filterSegmen('dormant')" style="background:#FEE2E2;color:#991B1B">😴 Dormant <strong id="segCntDormant">0</strong></div>
    </div>
  </div>

  <div class="hl-filter-collapsible">
    <button class="hl-filter-toggle-btn" id="custFilterBtn" onclick="toggleFilter('custFilter')">
      🔍 Filter &amp; Pencarian <span class="hl-filter-active-dot" id="custFilterDot"></span>
      <span class="hl-toggle-arrow">▼</span>
    </button>
    <div class="hl-filter-bar" id="custFilter">
      <input type="text" id="fSearch" class="hl-input" placeholder="Cari nama, telepon, alamat..." oninput="debounce()" style="flex:1;max-width:320px"/>
      <select id="fTipe" class="hl-input" style="width:auto" onchange="loadCustomer(1)">
        <option value="">Semua Tipe</option>
        <option value="retail">Retail</option>
        <option value="korporat">B2B / Korporat</option>
      </select>
      <select id="fSegmen" class="hl-input" style="width:auto" onchange="loadCustomer(1)">
        <option value="">Semua Segmen</option>
        <option value="baru">🆕 Baru</option>
        <option value="regular">Regular</option>
        <option value="vip">⭐ VIP</option>
        <option value="dormant">😴 Dormant</option>
      </select>
      <select id="fTier" class="hl-input" style="width:auto" onchange="loadCustomer(1)">
        <option value="">Semua Tier</option>
        <option value="silver">🥈 Silver</option>
        <option value="gold">🥇 Gold</option>
        <option value="platinum">💎 Platinum</option>
      </select>
      <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadCustomer()">↻</button>
      <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="refreshSegmen()" title="Re-hitung segmen sekarang">🔄 Segmen</button>
      <span id="custInfo" style="font-size:12px;color:var(--gray);margin-left:auto"></span>
      <div class="view-toggle">
        <button class="view-btn active" id="btnGrid" onclick="setView('grid')" title="Grid">⊞</button>
        <button class="view-btn" id="btnList" onclick="setView('list')" title="List">☰</button>
      </div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px" id="custGrid">
    <div class="hl-loading">⏳ Memuat...</div>
  </div>
</div>
<div id="custPaging"></div>

<!-- MODAL TAMBAH/EDIT -->
<div class="hl-modal-overlay" id="modalCust">
  <div class="hl-modal">
    <div class="hl-modal-header">
      <span class="hl-modal-title" id="custModalTitle">➕ Tambah Customer</span>
      <button class="hl-modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="hl-modal-body">
      <input type="hidden" id="f_id"/>
      <div class="hl-form-group">
        <label class="hl-label">Nama <span class="req">*</span></label>
        <input type="text" id="f_nama" class="hl-input" placeholder="Nama lengkap / perusahaan"/>
      </div>
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">No. Telepon</label>
          <input type="tel" id="f_telepon" class="hl-input" placeholder="08xxxxxxxxxx"/>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Tipe</label>
          <select id="f_tipe" class="hl-input">
            <option value="retail">Retail</option>
            <option value="korporat">B2B / Korporat</option>
          </select>
        </div>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Alamat</label>
        <textarea id="f_alamat" class="hl-input hl-textarea" placeholder="Alamat lengkap..."></textarea>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Catatan</label>
        <input type="text" id="f_catatan" class="hl-input" placeholder="Preferensi, info tambahan..."/>
      </div>
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Metode Pembayaran</label>
          <select id="f_metode_bayar" class="hl-input">
            <option value="langsung">Bayar Langsung</option>
            <option value="bulanan">Tagihan Bulanan</option>
          </select>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Status</label>
          <select id="f_active" class="hl-input">
            <option value="1">✅ Aktif</option>
            <option value="0">⏸️ Nonaktif</option>
          </select>
        </div>
      </div>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeModal()">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="saveCustomer()">💾 Simpan</button>
    </div>
  </div>
</div>

<!-- MODAL DETAIL CUSTOMER -->
<div class="hl-modal-overlay" id="modalDetail">
  <div class="hl-modal hl-modal-lg" style="max-height:90vh">
    <div class="hl-modal-header">
      <span class="hl-modal-title" id="detailTitle">Detail Customer</span>
      <button class="hl-modal-close" onclick="closeDetail()">✕</button>
    </div>
    <div class="hl-modal-body" id="detailBody"></div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeDetail()">Tutup</button>
      <?php if (hasPermission('pelanggan.edit')): ?>
      <button class="hl-btn hl-btn-primary" id="btnEditFromDetail" onclick="editFromDetail()">✏️ Edit</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- MODAL PEMBAYARAN — dipicu dari tombol 💰 Bayar di Riwayat Order, tetap
     di halaman Pelanggan (gak pindah ke Order). POST ke orders.php?action=bayar
     (endpoint sama yg dipakai halaman Order sendiri). -->
<div class="modal-overlay" id="modalBayar" style="align-items:center;justify-content:center;padding:20px;z-index:400">
  <div class="modal" style="max-height:90vh;width:480px">
    <div class="modal-header">
      <span class="modal-title">💰 Update Pembayaran</span>
      <button class="modal-close" onclick="closeBayarModal()">✕</button>
    </div>
    <div class="modal-body">
      <div id="bayarInfo" style="background:var(--off);border-radius:var(--r);padding:12px 14px;margin-bottom:16px;font-size:13px"></div>

      <div class="pay-opt">
        <button class="pay-btn selected" id="btnSebagian" onclick="selectTipe('sebagian')">
          <span class="pay-icon">⚡</span>
          <div class="pay-label">Bayar Sebagian</div>
          <div class="pay-sub">Input nominal yang dibayar</div>
        </button>
        <button class="pay-btn" id="btnLunas" onclick="selectTipe('lunas')">
          <span class="pay-icon">✅</span>
          <div class="pay-label">Lunas Sekarang</div>
          <div class="pay-sub">Bayar semua sisa tagihan</div>
        </button>
      </div>

      <div id="nominalWrap" class="form-group">
        <label>Jumlah Dibayar (Rp) <span class="req">*</span></label>
        <input class="lm-rp" type="number" id="bayarJumlah" placeholder="0" min="0" step="500"
          oninput="updateBayarPreview()"/>
        <div id="quickNominal" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px"></div>
        <div id="bayarPreview" style="margin-top:8px;border-radius:var(--r);padding:10px 12px;display:none;font-size:13px"></div>
      </div>

      <div id="pembulatanWrap" style="display:none;margin-bottom:14px">
        <label style="font-size:11px;font-weight:700;color:var(--navy);text-transform:uppercase;letter-spacing:.05em">Pembulatan (Cash)</label>
        <div style="display:flex;gap:6px;margin-top:6px">
          <button class="btn btn-outline btn-sm" onclick="setPembulatan(500)">ke 500</button>
          <button class="btn btn-outline btn-sm" onclick="setPembulatan(1000)">ke 1.000</button>
          <button class="btn btn-outline btn-sm" onclick="setPembulatan(2000)">ke 2.000</button>
          <button class="btn btn-outline btn-sm" onclick="setPembulatan(5000)">ke 5.000</button>
          <button class="btn btn-outline btn-sm" onclick="setPembulatan(10000)">ke 10.000</button>
        </div>
        <div id="pembulatanInfo" style="font-size:12px;color:var(--gray);margin-top:6px"></div>
      </div>

      <div class="form-group">
        <label>Metode Pembayaran</label>
        <select id="bayarMetode" onchange="onMetodeChange()">
          <?php foreach ($activeMethods as $_m): ?>
          <option value="<?= htmlspecialchars($_m['code']) ?>"><?= htmlspecialchars(trim(($_m['emoji'] ?? '') . ' ' . $_m['label'])) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label>Bukti Pembayaran (opsional)</label>
        <div class="bukti-drop" onclick="document.getElementById('buktiFile').click()">
          <div style="font-size:1.5rem">📎</div>
          <p>Klik untuk upload foto bukti transfer/QRIS</p>
          <p style="font-size:11px">JPG, PNG, maks 5MB</p>
        </div>
        <input type="file" id="buktiFile" accept="image/*" style="display:none"
          onchange="previewBukti(this)"/>
        <img id="buktiPreview" class="bukti-preview"/>
        <div id="buktiName" style="font-size:12px;color:var(--teal);margin-top:4px"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline btn-sm" onclick="closeBayarModal()">Batal</button>
      <button class="btn btn-primary btn-sm" onclick="submitBayar()">💾 Simpan Pembayaran</button>
    </div>
  </div>
</div>

<?php renderToast(); ?>
<script>
let allCustomer = [];
let searchTimer = null;
let currentDetailId = null;
let currentBayarId = null;
let currentBayarData = null;
let currentTipeBayar = 'sebagian';
const CAN_CREATE_CUST = <?= hasPermission('pelanggan.create') ? 'true' : 'false' ?>;
const CAN_EDIT_CUST   = <?= hasPermission('pelanggan.edit')   ? 'true' : 'false' ?>;
const CAN_VIEW_ORDERS = <?= (hasPermission('orders.view_all') || hasPermission('orders.view_own')) ? 'true' : 'false' ?>;
const CAN_BAYAR_ORDER  = <?= hasPermission('orders.bayar')    ? 'true' : 'false' ?>;

document.addEventListener('DOMContentLoaded', async () => {
  initFilter('custFilter'); loadStats(); loadSegmenStats();
  await loadCustomer();
  // Deep-link dari halaman lain (mis. detail order): /customer?open=<id>
  const openId = new URLSearchParams(location.search).get('open');
  if (openId && /^\d+$/.test(openId)) openDetail(parseInt(openId, 10));
});

async function loadStats() {
  const r = await fetch('customer.php?action=stats');
  const d = await r.json();
  document.getElementById('sTotal').textContent = d.total;
  document.getElementById('sB2B').textContent   = d.b2b;
  document.getElementById('sBaru').textContent  = d.baru;
}

async function loadSegmenStats() {
  try {
    const r = await fetch('customer.php?action=segmen_stats');
    const d = await r.json();
    if (!d.ok) return;
    const s = d.stats || {};
    document.getElementById('segCntBaru').textContent    = s.baru || 0;
    document.getElementById('segCntRegular').textContent = s.regular || 0;
    document.getElementById('segCntVip').textContent     = s.vip || 0;
    document.getElementById('segCntDormant').textContent = s.dormant || 0;
    document.getElementById('segmenStatsBar').style.display = 'block';
  } catch(e){}
}

function filterSegmen(seg) {
  document.getElementById('fSegmen').value = seg;
  loadCustomer(1);
}

async function refreshSegmen() {
  if (!await lmConfirm('Re-hitung segmen semua pelanggan sekarang?')) return;
  showToast('⏳ Menghitung segmen...', 'success');
  try {
    const r = await fetch('customer.php?action=segmen_refresh', {
      method:'POST', headers:{'X-CSRF-Token':csrfToken()}
    });
    const d = await r.json();
    if (d.error) { showToast(d.error, 'error'); return; }
    showToast('✓ Segmen di-update: ' + (d.changed || 0) + ' pelanggan berubah', 'success');
    loadCustomer(1); loadSegmenStats();
  } catch(e) { showToast('Network error', 'error'); }
}

// Helpers untuk badge
const TIER_BADGES   = {silver:'🥈',gold:'🥇',platinum:'💎'};
const SEGMEN_BADGES = {baru:['🆕','#1E40AF','#DBEAFE'], vip:['⭐','#92400E','#FEF3C7'], dormant:['😴','#991B1B','#FEE2E2']};
function tierBadge(t) {
  if (!t || !TIER_BADGES[t]) return '';
  return `<span style="font-size:11px;font-weight:600;color:#475569;margin-left:4px">${TIER_BADGES[t]} ${t}</span>`;
}
function segmenBadge(s) {
  if (!s || !SEGMEN_BADGES[s]) return '';
  const [emo, fg, bg] = SEGMEN_BADGES[s];
  return `<span style="font-size:10px;font-weight:700;padding:2px 6px;border-radius:8px;background:${bg};color:${fg};margin-left:4px">${emo} ${s.toUpperCase()}</span>`;
}

let currentPage = 1;
let totalPages  = 1;
let totalCustomer = 0;

async function loadCustomer(page=1) {
  currentPage = page;
  const q      = document.getElementById('fSearch').value;
  const tipe   = document.getElementById('fTipe').value;
  const segmen = document.getElementById('fSegmen')?.value || '';
  const tier   = document.getElementById('fTier')?.value || '';
  // Skeleton 6 cards
  document.getElementById('custGrid').innerHTML = Array.from({length:6}).map(()=>`
    <div class="hl-skel-card" style="padding:18px">
      <span class="hl-skel lg" style="width:65%;display:block"></span>
      <span class="hl-skel" style="width:45%;display:block;margin-top:8px"></span>
      <div style="display:flex;gap:6px;margin-top:12px">
        <span class="hl-skel" style="width:70px"></span>
        <span class="hl-skel" style="width:50px"></span>
      </div>
      <div style="display:flex;gap:14px;margin-top:14px;padding-top:12px;border-top:1px solid var(--light)">
        <span class="hl-skel" style="width:30%"></span>
        <span class="hl-skel" style="width:30%"></span>
        <span class="hl-skel" style="width:30%"></span>
      </div>
    </div>`).join('');

  const r = await fetch(`customer.php?action=list&q=${encodeURIComponent(q)}&tipe=${tipe}&segmen=${segmen}&tier=${tier}&page=${page}`);
  const d = await r.json();
  allCustomer   = d.data;
  totalPages    = d.total_pages;
  totalCustomer = d.total;

  document.getElementById('custInfo').textContent = `${d.total} customer`;
  renderCustomer();
  renderPaging();
}

let currentView = 'grid';

function setView(view) {
  currentView = view;
  document.getElementById('btnGrid').classList.toggle('active', view==='grid');
  document.getElementById('btnList').classList.toggle('active', view==='list');
  const grid = document.getElementById('custGrid');
  if (view === 'grid') {
    grid.style.display = 'grid';
    grid.style.gridTemplateColumns = 'repeat(auto-fill,minmax(280px,1fr))';
    grid.style.flexDirection = '';
  } else {
    grid.style.display = 'flex';
    grid.style.flexDirection = 'column';
    grid.style.gridTemplateColumns = '';
  }
  renderCustomer();
}

function renderCustomer() {
  const grid = document.getElementById('custGrid');
  if (!allCustomer.length) {
    grid.innerHTML = `<div style="grid-column:1/-1"><div class="hl-empty-v2">
      <div class="e-icon">👥</div>
      <div class="e-title">Belum ada customer</div>
      <div class="e-sub">Punya data pelanggan lama di Excel/CSV? Import biar langsung lengkap — atau tambah manual.</div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:center;margin-top:6px">
        <a class="hl-btn hl-btn-primary hl-btn-sm" href="/import.php?entity=pelanggan">📥 Import Excel/CSV</a>
        ${CAN_CREATE_CUST ? `<button class="hl-btn hl-btn-outline hl-btn-sm" onclick="openModal()">+ Tambah manual</button>` : ''}
      </div>
    </div></div>`;
    return;
  }

  if (currentView === 'list') {
    grid.innerHTML = allCustomer.map(c => `
      <div class="cust-list-item" onclick="openDetail(${c.id})">
        <div class="cust-list-avatar">${esc(c.nama).charAt(0).toUpperCase()}</div>
        <div class="cust-list-info">
          <div class="cust-list-nama">${esc(c.nama)} ${tierBadge(c.tier)} ${segmenBadge(c.segmen)}</div>
          <div class="cust-list-telp">${esc(c.telepon||'No telepon')} ·
            <span class="hl-badge ${c.tipe==='korporat'?'hl-badge-navy':'hl-badge-teal'}" style="font-size:10px">${c.tipe==='korporat'?'B2B':'Retail'}</span>
            ${c.metode_bayar==='bulanan'?'<span class="hl-badge" style="background:#FEF3C7;color:#92400E;font-size:10px;margin-left:4px">Bulanan</span>':''}
            ${parseInt(c.poin_balance||0) > 0 ? '<span class="hl-badge" style="background:#F0FDFB;color:#0F766E;font-size:10px;margin-left:4px">⭐ '+parseInt(c.poin_balance)+' poin</span>' : ''}
          </div>
        </div>
        <div class="cust-list-stats">
          <div class="cust-list-stat"><strong>${c.total_order||0}</strong>Order</div>
          <div class="cust-list-stat"><strong>Rp ${parseFloat(c.total_omset||0).toLocaleString('id-ID')}</strong>Omset</div>
          <div class="cust-list-stat"><strong>${c.last_order?fmtDate(c.last_order):'-'}</strong>Terakhir</div>
          ${CAN_EDIT_CUST ? `<button class="hl-btn hl-btn-outline hl-btn-sm" onclick="event.stopPropagation();regenToken(${c.id})" title="Regenerate portal token (struk lama jadi invalid)">🔄</button>` : ''}
        </div>
      </div>`).join('');
  } else {
    grid.innerHTML = allCustomer.map(c => `
      <div class="cust-card" onclick="openDetail(${c.id})">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px">
          <div>
            <div class="cust-nama">${esc(c.nama)}</div>
            <div class="cust-telp">${esc(c.telepon||'No telepon')}</div>
            <div style="margin-top:5px;display:flex;gap:4px;flex-wrap:wrap">
              ${tierBadge(c.tier)} ${segmenBadge(c.segmen)}
              ${parseInt(c.poin_balance||0) > 0 ? '<span class="hl-badge" style="background:#F0FDFB;color:#0F766E;font-size:10px">⭐ '+grpRibu(c.poin_balance)+'</span>' : ''}
            </div>
          </div>
          <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px">
            <span class="hl-badge ${c.tipe==='korporat'?'hl-badge-navy':'hl-badge-teal'}" style="font-size:10px">${c.tipe==='korporat'?'B2B':'Retail'}</span>
            ${c.metode_bayar==='bulanan'?'<span class="hl-badge" style="background:#FEF3C7;color:#92400E;font-size:10px">Bulanan</span>':''}
          </div>
        </div>
        ${c.alamat?`<div style="font-size:12px;color:var(--gray);margin-bottom:10px;line-height:1.4">${esc(c.alamat)}</div>`:''}
        <div class="cust-stats">
          <div class="cust-stat"><strong>${c.total_order||0}</strong><span>Order</span></div>
          <div class="cust-stat"><strong style="font-size:12px">Rp ${parseFloat(c.total_omset||0).toLocaleString('id-ID')}</strong><span>Omset</span></div>
          <div class="cust-stat"><strong style="font-size:11px">${c.last_order?fmtDate(c.last_order):'-'}</strong><span>Terakhir</span></div>
        </div>
      </div>`).join('');
  }
}

async function openDetail(id) {
  currentDetailId = id;
  let c = allCustomer.find(x=>x.id==id);
  if (!c) {
    // Gak ada di halaman yang lagi termuat (mis. deep-link dari luar, atau
    // halaman pelanggan terpaginasi) — fetch langsung by id.
    try {
      const r = await fetch('customer.php?action=get&id='+id);
      const d = await r.json();
      c = d.data;
    } catch (e) { c = null; }
  }
  if (!c) { showToast('❌ Pelanggan tidak ditemukan', 'error'); return; }
  document.getElementById('detailTitle').textContent = '👤 ' + c.nama;
  document.getElementById('detailBody').innerHTML = '<div class="hl-loading">⏳ Memuat riwayat...</div>';
  document.getElementById('modalDetail').classList.add('open');

  const r = await fetch('customer.php?action=get_orders&id='+id);
  const orders = await r.json();

  // Hitung next reward untuk progress bar
  const poin = parseInt(c.poin_balance||0);
  const nextThr = poin < 100 ? 100 : poin < 200 ? 200 : poin < 500 ? 500 : 0;
  const prevThr = poin >= 500 ? 500 : poin >= 200 ? 200 : poin >= 100 ? 100 : 0;
  const pct = nextThr ? Math.round(((poin - prevThr) / (nextThr - prevThr)) * 100) : 100;
  const nextLabel = nextThr === 100 ? 'Silver' : nextThr === 200 ? 'Gold' : nextThr === 500 ? 'Platinum' : 'Max tier';

  document.getElementById('detailBody').innerHTML = `
    <div style="background:linear-gradient(135deg,#0F1C3A,#1E3A8A);color:#fff;border-radius:14px;padding:16px 18px;margin-bottom:16px">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:8px">
        <div>
          <div style="font-size:1.3rem;font-weight:800">${esc(c.nama)}</div>
          <div style="font-size:12px;opacity:.8;margin-top:3px">📞 ${esc(c.telepon||'-')} · Sejak ${fmtDate(c.created_at)}</div>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          ${c.tier && c.tier!=='regular' ? `<span style="background:rgba(255,255,255,.15);font-size:11px;font-weight:700;padding:4px 10px;border-radius:100px">${{silver:'🥈 Silver',gold:'🥇 Gold',platinum:'💎 Platinum'}[c.tier]||esc(c.tier)}</span>` : ''}
          ${c.segmen && c.segmen!=='regular' ? `<span style="background:rgba(255,255,255,.15);font-size:11px;font-weight:700;padding:4px 10px;border-radius:100px">${{baru:'🆕 Baru',vip:'⭐ VIP',dormant:'😴 Dormant'}[c.segmen]||esc(c.segmen)}</span>` : ''}
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px;padding-top:14px;border-top:1px solid rgba(255,255,255,.15)">
        <div><div style="font-size:11px;opacity:.7">Total Order</div><div style="font-size:1.2rem;font-weight:800">${c.total_order||0}</div></div>
        <div><div style="font-size:11px;opacity:.7">Total Spending</div><div style="font-size:1.2rem;font-weight:800">Rp ${parseFloat(c.total_omset||0).toLocaleString('id-ID')}</div></div>
      </div>
    </div>

    <!-- POIN PROGRESS -->
    <div style="background:linear-gradient(90deg,#F0FDFB,#ECFDF5);border:1px solid #B6F0E6;border-radius:12px;padding:14px 16px;margin-bottom:14px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <div>
          <div style="font-size:11px;color:#0F766E;font-weight:700;text-transform:uppercase;letter-spacing:.06em">⭐ Saldo Poin</div>
          <div style="font-size:1.4rem;font-weight:800;color:#0F1C3A">${poin.toLocaleString('id-ID')} <span style="font-size:13px;color:var(--gray);font-weight:500">poin</span></div>
        </div>
        ${nextThr ? `<div style="text-align:right;font-size:11px;color:#0F766E">
          ${nextThr - poin} poin lagi<br><strong>→ ${nextLabel}</strong>
        </div>` : '<div style="font-size:11px;color:#0F766E">💎 Tier tertinggi!</div>'}
      </div>
      ${nextThr ? `<div style="height:8px;background:#fff;border-radius:100px;overflow:hidden">
        <div style="height:100%;width:${pct}%;background:linear-gradient(90deg,#10B981,#06B6D4);transition:width .3s"></div>
      </div>` : ''}
    </div>

    <!-- PREFERENSI -->
    <div style="background:#fff;border:1px solid rgba(27,45,90,.08);border-radius:12px;padding:14px 16px;margin-bottom:14px" id="prefBox">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <div style="font-size:11px;color:var(--gray);font-weight:700;text-transform:uppercase;letter-spacing:.06em">🌸 Preferensi</div>
        ${CAN_EDIT_CUST ? `<button class="hl-btn hl-btn-outline hl-btn-sm" onclick="togglePrefEdit()" id="prefEditBtn">✏️ Edit</button>` : ''}
      </div>
      <div id="prefDisplay">
        <div style="font-size:13px;line-height:1.7">
          Parfum: <strong>${esc(c.preferensi_parfum||'-')}</strong>
          &nbsp;·&nbsp;Suhu: <strong>${esc(c.preferensi_suhu||'-')}</strong>
        </div>
        ${c.catatan_tetap ? `<div style="font-size:13px;color:#475569;margin-top:5px;background:#F8FAFC;padding:7px 10px;border-radius:8px;border-left:3px solid var(--teal)">📝 ${esc(c.catatan_tetap)}</div>` : '<div style="font-size:12px;color:var(--gray);font-style:italic;margin-top:5px">Belum ada catatan tetap</div>'}
      </div>
      <div id="prefEdit" style="display:none">
        <div class="hl-form-row" style="margin-bottom:8px">
          <div class="hl-form-group" style="margin:0">
            <label class="hl-label">Parfum</label>
            <input type="text" id="pf_parfum" class="hl-input" placeholder="Lavender / Vanilla / dll" value="${esc(c.preferensi_parfum||'')}"/>
          </div>
          <div class="hl-form-group" style="margin:0">
            <label class="hl-label">Suhu Cuci</label>
            <select id="pf_suhu" class="hl-input">
              <option value="">- Default -</option>
              <option value="Normal" ${c.preferensi_suhu==='Normal'?'selected':''}>Normal</option>
              <option value="Hangat" ${c.preferensi_suhu==='Hangat'?'selected':''}>Hangat</option>
              <option value="Panas"  ${c.preferensi_suhu==='Panas'?'selected':''}>Panas</option>
              <option value="Dingin" ${c.preferensi_suhu==='Dingin'?'selected':''}>Dingin</option>
            </select>
          </div>
        </div>
        <div class="hl-form-group" style="margin-bottom:10px">
          <label class="hl-label">Catatan Tetap (auto-load ke POS saat pelanggan ini dipilih)</label>
          <textarea id="pf_catatan" class="hl-input hl-textarea" placeholder="Baju putih pisah, jangan setrika kerah, dll">${esc(c.catatan_tetap||'')}</textarea>
        </div>
        <div style="display:flex;gap:6px">
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="togglePrefEdit()">Batal</button>
          <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="savePreferensi(${c.id})">💾 Simpan</button>
        </div>
      </div>
    </div>

    <div style="background:var(--off);border-radius:var(--r);padding:14px 16px;margin-bottom:16px;display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:14px">
      <div><span style="color:var(--gray)">Tipe: </span><strong>${c.tipe==='korporat'?'B2B / Korporat':'Retail'}</strong></div>
      <div><span style="color:var(--gray)">Last Transaksi: </span><strong>${c.last_transaksi?fmtDate(c.last_transaksi):'-'}</strong></div>
      ${c.alamat?`<div style="grid-column:1/-1"><span style="color:var(--gray)">Alamat: </span>${esc(c.alamat)}</div>`:''}
      ${c.catatan?`<div style="grid-column:1/-1"><span style="color:var(--gray)">Catatan: </span>${esc(c.catatan)}</div>`:''}
    </div>
    <div style="font-size:12px;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px">Riwayat Order (20 terakhir)</div>
    ${orders.length ? `<div class="hl-table-wrap"><table class="hl-table">
      <thead><tr><th>No Order</th><th>Tanggal</th><th>Layanan</th><th>Status</th><th style="text-align:right">Total</th>${CAN_VIEW_ORDERS ? '<th></th>' : ''}</tr></thead>
      <tbody>${orders.map(o=>`<tr>
        <td style="font-family:var(--mono);font-size:12px;color:var(--teal-d)">${CAN_VIEW_ORDERS ? `<a href="orders.php?open=${o.id}" target="_blank" style="color:inherit">${esc(o.no_order)}</a>` : esc(o.no_order)}</td>
        <td style="font-size:12px">${fmtDate(o.tanggal)}</td>
        <td style="font-size:12px;color:var(--gray);max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(o.layanan||'-')}</td>
        <td>${statusBayarBadge(o.status_bayar)}</td>
        <td style="font-family:var(--mono);font-size:12px;text-align:right;font-weight:600">Rp ${parseFloat(o.total).toLocaleString('id-ID')}</td>
        ${CAN_VIEW_ORDERS ? `<td style="white-space:nowrap"><div style="display:flex;gap:4px">
          ${CAN_BAYAR_ORDER && o.status_bayar !== 'lunas' ? `<button onclick="openBayarByIdCust(${o.id})" class="hl-btn hl-btn-primary hl-btn-sm" style="padding:4px 8px;font-size:11px">💰 Bayar</button>` : ''}
          <a href="orders.php?open=${o.id}&qa=wa" target="_blank" class="hl-btn hl-btn-outline hl-btn-sm" style="padding:4px 8px;font-size:11px">💬 WA</a>
        </div></td>` : ''}
      </tr>`).join('')}</tbody>
    </table></div>` : '<div class="hl-empty">Belum ada order</div>'}`;
}

// ═══════════════════════════════════════════════════════
// MODAL BAYAR — dipicu tombol 💰 Bayar di Riwayat Order.
// Sama persis logic-nya dgn orders.php (POST ke endpoint yg sama,
// orders.php?action=bayar), cuma dipanggil dari sini supaya user
// gak perlu pindah dari halaman Pelanggan.
// ═══════════════════════════════════════════════════════
async function openBayarByIdCust(id) {
  const r = await fetch('orders.php?action=get&id=' + id);
  const d = await r.json();
  if (d.error) { showToast(d.error, 'error'); return; }
  openBayarModal(d.id, d.nama_pelanggan, d.total, d.dp || 0, d.sisa_bayar || 0);
}

function openBayarModal(id, namaP, total, dp, sisa) {
  currentBayarId   = id;
  currentBayarData = {total: parseFloat(total), dp: parseFloat(dp), sisa: parseFloat(sisa)};
  currentTipeBayar = 'sebagian';

  document.getElementById('bayarInfo').innerHTML = `
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;text-align:center">
      <div><div style="font-size:11px;color:var(--gray)">Total</div><div style="font-weight:700;font-family:var(--mono)">Rp ${parseFloat(total).toLocaleString('id-ID')}</div></div>
      <div><div style="font-size:11px;color:var(--gray)">Sudah Bayar</div><div style="font-weight:700;font-family:var(--mono);color:var(--green)">Rp ${parseFloat(dp).toLocaleString('id-ID')}</div></div>
      <div><div style="font-size:11px;color:var(--gray)">Sisa Tagihan</div><div style="font-weight:700;font-family:var(--mono);color:var(--red)">Rp ${parseFloat(sisa).toLocaleString('id-ID')}</div></div>
    </div>
    <div style="margin-top:8px;font-size:13px;font-weight:600;color:var(--navy)">Pelanggan: ${esc(namaP)}</div>`;

  document.getElementById('bayarJumlah').value = '';
  document.getElementById('bayarPreview').style.display = 'none';
  document.getElementById('buktiPreview').style.display = 'none';
  document.getElementById('buktiName').textContent = '';
  document.getElementById('buktiFile').value = '';
  document.getElementById('pembulatanInfo').textContent = '';
  selectTipe('sebagian');
  buildQuickNominal(parseFloat(sisa));
  document.getElementById('modalBayar').classList.add('open');
}

function closeBayarModal() {
  document.getElementById('modalBayar').classList.remove('open');
  currentBayarId = null;
}

function buildQuickNominal(sisa) {
  const roundUp = (n, to) => Math.ceil(n / to) * to;
  const opts = new Set([
    sisa,
    roundUp(sisa, 500),
    roundUp(sisa, 1000),
    roundUp(sisa, 5000),
    roundUp(sisa, 10000),
  ]);
  const el = document.getElementById('quickNominal');
  el.innerHTML = [...opts].filter(v => v > 0).map(v =>
    `<button class="btn btn-outline btn-sm" style="font-family:var(--mono);font-size:11px"
      onclick="setNominal(${v})">Rp ${v.toLocaleString('id-ID')}</button>`
  ).join('');
}

function setNominal(val) {
  document.getElementById('bayarJumlah').value = val;
  updateBayarPreview();
}

function setPembulatan(kelipatan) {
  const sisa    = currentBayarData?.sisa || 0;
  const rounded = Math.ceil(sisa / kelipatan) * kelipatan;
  document.getElementById('bayarJumlah').value = rounded;
  updateBayarPreview();
}

function onMetodeChange() {
  const metode = document.getElementById('bayarMetode').value;
  const wrap   = document.getElementById('pembulatanWrap');
  wrap.style.display = metode === 'cash' ? 'block' : 'none';
  if (metode !== 'cash') {
    document.getElementById('pembulatanInfo').textContent = '';
  }
  updateBayarPreview();
}

function selectTipe(tipe) {
  currentTipeBayar = tipe;
  document.getElementById('btnSebagian').classList.toggle('selected', tipe==='sebagian');
  document.getElementById('btnLunas').classList.toggle('selected', tipe==='lunas');
  document.getElementById('nominalWrap').style.display = 'flex';
  if (tipe === 'lunas' && currentBayarData) {
    document.getElementById('bayarJumlah').value = currentBayarData.sisa;
    buildQuickNominal(currentBayarData.sisa);
  }
  updateBayarPreview();
  onMetodeChange();
}

function updateBayarPreview() {
  const val    = parseFloat(document.getElementById('bayarJumlah').value) || 0;
  const sisa   = currentBayarData?.sisa || 0;
  const metode = document.getElementById('bayarMetode').value;
  const el     = document.getElementById('bayarPreview');
  const pInfo  = document.getElementById('pembulatanInfo');

  if (val <= 0) { el.style.display='none'; return; }

  el.style.display = 'block';
  const kembalian = val - sisa;

  if (val > sisa) {
    el.style.background = '#D1FAE5';
    el.style.color      = '#065F46';
    el.innerHTML = `
      <div style="display:flex;justify-content:space-between">
        <span>Dibayar:</span><strong>Rp ${val.toLocaleString('id-ID')}</strong>
      </div>
      <div style="display:flex;justify-content:space-between;border-top:1px dashed rgba(0,0,0,.1);margin-top:6px;padding-top:6px">
        <span>Kembalian:</span><strong>Rp ${kembalian.toLocaleString('id-ID')}</strong>
      </div>`;
    if (metode === 'cash' && kembalian > 0) {
      pInfo.innerHTML = `<span style="color:var(--green)">Kembalian: Rp ${kembalian.toLocaleString('id-ID')}</span>`;
    }
  } else if (val === sisa) {
    el.style.background = '#D1FAE5';
    el.style.color      = '#065F46';
    el.innerHTML = '<strong>✅ Pas — order akan lunas</strong>';
  } else {
    const sisaSetelah = sisa - val;
    el.style.background = '#FEF3C7';
    el.style.color      = '#92400E';
    el.innerHTML = `
      <div style="display:flex;justify-content:space-between">
        <span>Dibayar:</span><strong>Rp ${val.toLocaleString('id-ID')}</strong>
      </div>
      <div style="display:flex;justify-content:space-between;border-top:1px dashed rgba(0,0,0,.1);margin-top:6px;padding-top:6px">
        <span>Sisa setelah ini:</span><strong>Rp ${sisaSetelah.toLocaleString('id-ID')}</strong>
      </div>`;
  }

  if (metode === 'cash' && val > 0 && val < sisa) {
    pInfo.innerHTML = '<span style="color:var(--yellow)">⚠️ Bayar sebagian — tidak perlu pembulatan</span>';
  } else if (metode === 'cash' && val === sisa) {
    pInfo.innerHTML = '<span style="color:var(--green)">✅ Nominal pas</span>';
  }
}

function previewBukti(input) {
  const file = input.files[0];
  if (!file) return;
  if (file.size > 5*1024*1024) { showToast('❌ File terlalu besar (maks 5MB)', 'error'); input.value=''; return; }
  const reader = new FileReader();
  reader.onload = e => {
    const img = document.getElementById('buktiPreview');
    img.src = e.target.result;
    img.style.display = 'block';
  };
  reader.readAsDataURL(file);
  document.getElementById('buktiName').textContent = '📎 ' + file.name;
}

function bayarLabel(s){return{'lunas':'✅ Lunas','dp':'⚡ DP','belum_bayar':'⏳ Belum Bayar'}[s]||s}

async function submitBayar() {
  if (!currentBayarId) return;
  const tipe   = currentTipeBayar;
  const jumlah = parseFloat(document.getElementById('bayarJumlah').value) || 0;
  const metode = document.getElementById('bayarMetode').value;
  const file   = document.getElementById('buktiFile').files[0];

  if (tipe === 'sebagian' && jumlah <= 0) {
    showToast('⚠️ Masukkan jumlah yang dibayar', 'error'); return;
  }

  const fd = new FormData();
  fd.append('id', currentBayarId);
  fd.append('tipe_bayar', tipe);
  fd.append('jumlah', tipe === 'lunas' ? (currentBayarData?.sisa || 0) : jumlah);
  fd.append('metode', metode);
  if (file) fd.append('bukti', file);

  try {
    const r = await fetch('orders.php?action=bayar', {
      method: 'POST',
      headers: {'X-CSRF-Token': csrfToken()},
      body: fd
    });
    const d = await r.json();
    if (d.success) {
      showToast('✅ Pembayaran berhasil disimpan! Status: ' + bayarLabel(d.status_bayar), 'success');
      closeBayarModal();
      if (currentDetailId) openDetail(currentDetailId); // refresh Riwayat Order
    } else {
      showToast('❌ ' + (d.error||'Gagal'), 'error');
    }
  } catch(e) {
    showToast('❌ Error: ' + e.message, 'error');
  }
}

function togglePrefEdit(){
  const disp = document.getElementById('prefDisplay');
  const edit = document.getElementById('prefEdit');
  const btn  = document.getElementById('prefEditBtn');
  const showEdit = edit.style.display === 'none';
  disp.style.display = showEdit ? 'none' : '';
  edit.style.display = showEdit ? 'block' : 'none';
  btn.textContent    = showEdit ? '✕ Batal' : '✏️ Edit';
}

async function savePreferensi(pid){
  const body = {
    id: pid,
    parfum:         document.getElementById('pf_parfum').value,
    suhu:           document.getElementById('pf_suhu').value,
    catatan_tetap:  document.getElementById('pf_catatan').value,
  };
  try {
    const r = await fetch('customer.php?action=save_preferensi', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify(body)
    });
    const d = await r.json();
    if (d.error) { showToast(d.error,'error'); return; }
    showToast('✓ Preferensi tersimpan','success');
    // Update cached row + re-open detail dengan data segar
    const c = allCustomer.find(x => x.id == pid);
    if (c) {
      c.preferensi_parfum = body.parfum;
      c.preferensi_suhu   = body.suhu;
      c.catatan_tetap     = body.catatan_tetap;
    }
    openDetail(pid);
  } catch(e){ showToast('Network error','error'); }
}

function statusBayarBadge(s){
  const m={lunas:'<span class="hl-badge hl-badge-lunas">✅ Lunas</span>',dp:'<span class="hl-badge hl-badge-dp">⚡ DP</span>',belum_bayar:'<span class="hl-badge hl-badge-belum">⏳ Belum</span>'};
  return m[s]||s;
}

function editFromDetail() {
  const c = allCustomer.find(x=>x.id==currentDetailId);
  if (c) { closeDetail(); openModal(c); }
}
function closeDetail() { document.getElementById('modalDetail').classList.remove('open'); }

function openModal(data=null) {
  document.getElementById('f_id').value       = data?.id||'';
  document.getElementById('f_nama').value     = data?.nama||'';
  document.getElementById('f_telepon').value  = data?.telepon||'';
  document.getElementById('f_tipe').value         = data?.tipe||'retail';
  document.getElementById('f_metode_bayar').value = data?.metode_bayar||'langsung';
  document.getElementById('f_alamat').value        = data?.alamat||'';
  document.getElementById('f_catatan').value  = data?.catatan||'';
  document.getElementById('f_active').value   = data?.is_active??1;
  document.getElementById('custModalTitle').textContent = data ? '✏️ Edit Customer' : '➕ Tambah Customer';
  document.getElementById('modalCust').classList.add('open');
}
function closeModal() { document.getElementById('modalCust').classList.remove('open'); }

async function regenToken(id) {
  if (!await lmConfirm('Regenerate portal token? Struk lama dengan token lama tidak akan bisa login lagi.')) return;
  const r = await fetch('customer.php?action=regen_token', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({id})
  });
  const d = await r.json();
  if (d.error) { showToast(d.error,'error'); return; }
  showToast('✅ Token regenerated. Cetak struk baru untuk pelanggan ini.', 'success');
}

async function saveCustomer() {
  const nama = document.getElementById('f_nama').value.trim();
  if (!nama) { showToast('⚠️ Nama wajib diisi','error'); return; }
  const r = await fetch('customer.php?action=save', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({
      id:       document.getElementById('f_id').value,
      nama,
      telepon:  document.getElementById('f_telepon').value,
      tipe:         document.getElementById('f_tipe').value,
      metode_bayar: document.getElementById('f_metode_bayar').value,
      alamat:       document.getElementById('f_alamat').value,
      catatan:  document.getElementById('f_catatan').value,
      is_active:document.getElementById('f_active').value,
    })
  });
  const d = await r.json();
  if (d.success) { showToast('✅ Customer disimpan!','success'); closeModal(); loadCustomer(); loadStats(); }
  else showToast('❌ '+(d.error||'Gagal'),'error');
}

function renderPaging() {
  const el = document.getElementById('custPaging');
  if (!el) return;
  if (totalPages <= 1) { el.innerHTML = ''; return; }

  let html = '<div style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:20px;flex-wrap:wrap">';
  html += `<button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadCustomer(${currentPage-1})" ${currentPage===1?'disabled':''}>← Prev</button>`;

  const start = Math.max(1, currentPage - 2);
  const end   = Math.min(totalPages, currentPage + 2);

  if (start > 1) html += `<button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadCustomer(1)">1</button>`;
  if (start > 2) html += `<span style="color:var(--gray);padding:0 4px">...</span>`;

  for (let i = start; i <= end; i++) {
    html += `<button class="hl-btn ${i===currentPage?'hl-btn-primary':'hl-btn-outline'} hl-btn-sm" onclick="loadCustomer(${i})">${i}</button>`;
  }

  if (end < totalPages - 1) html += `<span style="color:var(--gray);padding:0 4px">...</span>`;
  if (end < totalPages) html += `<button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadCustomer(${totalPages})">${totalPages}</button>`;

  html += `<button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadCustomer(${currentPage+1})" ${currentPage===totalPages?'disabled':''}>Next →</button>`;
  html += `<span style="font-size:12px;color:var(--gray);margin-left:8px">Halaman ${currentPage} dari ${totalPages}</span>`;
  html += '</div>';

  el.innerHTML = html;
}

function debounce(){ clearTimeout(searchTimer); searchTimer=setTimeout(()=>loadCustomer(1),400); }
// Terima DATE ('YYYY-MM-DD') maupun DATETIME ('YYYY-MM-DD HH:MM:SS', mis. created_at)
// — DATETIME apa adanya + 'T00:00:00' jadi string ISO tidak valid ("...T00:00:00T00:00:00"
// efektifnya) → new Date() balikin Invalid Date. Ganti spasi jadi 'T' kalau ada.
function fmtDate(d){if(!d)return'-';const s=String(d);return new Date(s.includes(' ')?s.replace(' ','T'):s+'T00:00:00').toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'})}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
</script>
</body>
</html>

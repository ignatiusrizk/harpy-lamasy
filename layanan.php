<?php
$activePage = 'layanan';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/ServiceCatalog.php';
require_once __DIR__ . '/components.php';
$user = currentUser();
requirePermission('layanan.view');

$action = $_GET['action'] ?? '';
if (!$action) {
    // Cegah WebView APK sajikan versi lama halaman (perubahan UI tak ke-load)
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}
if ($action) {
    header('Content-Type: application/json');
    $tid = TenantResolver::id();
    $oid = TenantResolver::outletId();

    if ($action === 'list') {
        // JOIN master untuk expose aturan override (kalau kolom master ada)
        try {
            $rows = TenantQuery::raw(
                "SELECT l.*, m.allow_override, m.override_max_pct, m.harga_default
                   FROM hl_layanan l
                   LEFT JOIN hl_layanan_master m ON m.id = l.master_id
                  WHERE l.tenant_id=? AND l.outlet_id=? ORDER BY l.kategori,l.urutan,l.nama",
                [$tid, $oid]
            );
        } catch (Throwable) {
            // Fallback kalau migration master belum dijalankan
            $rows = TenantQuery::raw(
                "SELECT * FROM hl_layanan WHERE tenant_id=? AND outlet_id=? ORDER BY kategori,urutan,nama",
                [$tid, $oid]
            );
        }
        echo json_encode($rows); exit;
    }

    // ── Override harga layanan dari master (outlet adjust ±max_pct) ──
    if ($action === 'override_harga' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('layanan.edit')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $masterId = (int)($d['master_id'] ?? 0);
        $harga    = (float)($d['harga'] ?? 0);
        if (!$masterId) { echo json_encode(['error'=>'Layanan bukan dari master']); exit; }
        try {
            ServiceCatalog::setOutletOverride($tid, $oid, $masterId, $harga);
            logAudit('override','layanan',"Adjust harga layanan master #$masterId jadi Rp ".number_format($harga,0,',','.'));
            echo json_encode(['success'=>true]);
        } catch (Throwable $e) {
            apiErr($e);
        }
        exit;
    }
    if ($action === 'save' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('layanan.create') && !hasPermission('layanan.edit')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $nama    = substr(trim(strip_tags($d['nama'] ?? '')), 0, 100);
        $kategori= substr(trim(strip_tags($d['kategori'] ?? '')), 0, 50);
        if (!$nama) { echo json_encode(['error'=>'Nama wajib diisi']); exit; }
        // Layanan dari master katalog: nama/kategori/satuan dikunci HQ.
        // Harga harus lewat override (action=override_harga).
        if (!empty($d['id'])) {
            try {
                $chk = TenantQuery::raw("SELECT master_id FROM hl_layanan WHERE id=? AND tenant_id=? AND outlet_id=?",
                    [intval($d['id']), $tid, $oid]);
                if (!empty($chk[0]['master_id'])) {
                    echo json_encode(['error'=>'Layanan ini dari master katalog HQ. Hanya harga yang bisa di-adjust (jika diizinkan).']);
                    exit;
                }
            } catch (Throwable) {}
        }
        $estimasiJam = max(1, intval($d['estimasi_jam'] ?? 24));
        if (!empty($d['id'])) {
            TenantQuery::update('hl_layanan', [
                'nama'     => $nama,
                'kategori' => $kategori,
                'satuan'      => $d['satuan'] ?? 'kg',
                'harga'       => floatval($d['harga'] ?? 0),
                'qty_minimum' => max(0, floatval($d['qty_minimum'] ?? 0)),
                'estimasi_jam'=> $estimasiJam,
                'is_active'=> intval($d['is_active'] ?? 1),
                'urutan'   => intval($d['urutan'] ?? 0),
                'is_pinned'=> intval($d['is_pinned'] ?? 0) ? 1 : 0,
            ], 'id = ?', [intval($d['id'])]);
        } else {
            $newId = TenantQuery::insert('hl_layanan', [
                'nama'     => $nama,
                'kategori' => $kategori,
                'satuan'      => $d['satuan'] ?? 'kg',
                'harga'       => floatval($d['harga'] ?? 0),
                'qty_minimum' => max(0, floatval($d['qty_minimum'] ?? 0)),
                'estimasi_jam'=> $estimasiJam,
                'urutan'   => intval($d['urutan'] ?? 0),
                'is_pinned'=> intval($d['is_pinned'] ?? 0) ? 1 : 0,
                'is_active'=> 1,
            ]);
        }
        logAudit(!empty($d['id'])?'update':'create','layanan',(!empty($d['id'])?'Edit':'Tambah').' layanan: '.$nama);
        echo json_encode(['success'=>true, 'id'=>$newId ?? intval($d['id'] ?? 0)]); exit;
    }
    if ($action === 'delete' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('layanan.delete')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        TenantQuery::update('hl_layanan', ['is_active'=>0], 'id = ?', [intval($d['id'])]);
        echo json_encode(['success'=>true]); exit;
    }

    // ── PRESET SA: daftar preset aktif + tanda "sudah ada" di outlet ini ──
    if ($action === 'presets') {
        if (!hasPermission('layanan.create')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        $existing = [];
        try {
            $s = Database::get()->prepare("SELECT LOWER(nama) FROM hl_layanan WHERE tenant_id=? AND outlet_id=?");
            $s->execute([$tid, $oid]);
            $existing = array_flip($s->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable) {}
        $out = [];
        try {
            $ps = Database::get()->query(
                "SELECT nama, satuan, kategori, default_checked FROM saas_layanan_presets WHERE is_active=1 ORDER BY urutan, id"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($ps as $p) {
                $out[] = [
                    'nama'     => $p['nama'],
                    'satuan'   => $p['satuan'],
                    'kategori' => $p['kategori'],
                    'sudah_ada'=> isset($existing[mb_strtolower($p['nama'])]),
                    'default_checked' => (int)$p['default_checked'],
                ];
            }
        } catch (Throwable $e) { error_log('[layanan presets] '.$e->getMessage()); }
        echo json_encode(['ok'=>true, 'presets'=>$out]); exit;
    }

    // ── PRESET SA: simpan preset terpilih → hl_layanan (harga = input tenant) ──
    if ($action === 'save_presets' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('layanan.create')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $items = is_array($d['items'] ?? null) ? $d['items'] : [];
        if (!$items) { echo json_encode(['error'=>'Tidak ada layanan dipilih']); exit; }

        // Whitelist server-side: nama/kategori/satuan HANYA dari preset SA aktif
        // (klien cuma boleh mengirim nama + harga; kategori/satuan diambil dari DB).
        $valid = [];
        try {
            foreach (Database::get()->query("SELECT nama, satuan, kategori FROM saas_layanan_presets WHERE is_active=1")->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $valid[mb_strtolower($p['nama'])] = $p;
            }
        } catch (Throwable) {}
        // Dedup terhadap layanan existing outlet
        $existing = [];
        try {
            $s = Database::get()->prepare("SELECT LOWER(nama) FROM hl_layanan WHERE tenant_id=? AND outlet_id=?");
            $s->execute([$tid, $oid]);
            $existing = array_flip($s->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable) {}

        $added = 0; $skip = 0; $u = 0;
        foreach ($items as $it) {
            $key   = mb_strtolower(trim((string)($it['nama'] ?? '')));
            $harga = (int)($it['harga'] ?? 0);
            if (!isset($valid[$key]) || $harga <= 0) { $skip++; continue; }  // bukan preset asli / harga invalid
            if (isset($existing[$key])) { $skip++; continue; }               // sudah ada → lewati
            $p = $valid[$key];
            try {
                TenantQuery::insert('hl_layanan', [
                    'nama'=>$p['nama'], 'kategori'=>$p['kategori'], 'satuan'=>$p['satuan'],
                    'harga'=>$harga, 'estimasi_jam'=>24, 'urutan'=>$u++, 'is_active'=>1,
                ]);
                $existing[$key] = 1; $added++;
            } catch (Throwable $e) { error_log('[layanan save_presets] '.$e->getMessage()); }
        }
        if ($added > 0) logAudit('create', 'layanan', "Tambah $added layanan dari preset");
        echo json_encode(['ok'=>true, 'added'=>$added, 'skip'=>$skip]); exit;
    }
    if ($action === 'toggle' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('layanan.edit')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        TenantQuery::update('hl_layanan', ['is_active'=>intval($d['is_active'])], 'id = ?', [intval($d['id'])]);
        echo json_encode(['success'=>true]); exit;
    }

    // ── List outlets utk tier "Berlaku di" dropdown ──
    if ($action === 'outlets') {
        try {
            $st = Database::get()->prepare("SELECT id, nama_outlet FROM outlets WHERE tenant_id=? ORDER BY is_main DESC, id ASC");
            $st->execute([$tid]);
            echo json_encode(['outlets' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable) { echo json_encode(['outlets'=>[]]); }
        exit;
    }

    // ── Tier Express CRUD (tenant-level, dgn opsional per-outlet override) ──
    if ($action === 'tier_list') {
        try {
            $st = Database::get()->prepare(
                "SELECT id, nama_tier, estimasi_jam, tipe_biaya, nilai_biaya, is_active, urutan
                   FROM hl_express_tier
                  WHERE tenant_id = ? ORDER BY urutan ASC, estimasi_jam DESC"
            );
            $st->execute([$tid]);
            echo json_encode(['tiers' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) {
            echo json_encode([
                'error' => 'Tabel tier belum ada. Run migration: express_tier_global_migration.sql',
                'tiers' => []
            ]);
        }
        exit;
    }

    if ($action === 'tier_save' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('layanan.edit') && !hasPermission('layanan.create')) {
            echo json_encode(['error'=>'Akses ditolak']); exit;
        }
        verifyCsrf();
        $d      = json_decode(file_get_contents('php://input'), true);
        $nama   = substr(trim((string)($d['nama_tier'] ?? '')), 0, 50);
        $jam    = max(1, (int)($d['estimasi_jam'] ?? 0));
        $tipe   = in_array($d['tipe_biaya'] ?? '', ['flat','percent'], true) ? $d['tipe_biaya'] : 'percent';
        $nilai  = max(0, (float)($d['nilai_biaya'] ?? 0));
        $aktif  = (int)($d['is_active'] ?? 1) ? 1 : 0;
        $urut   = (int)($d['urutan'] ?? 0);
        // outlet_id: NULL = berlaku semua outlet, int = khusus outlet ini
        $tierOutletId = !empty($d['outlet_id']) ? (int)$d['outlet_id'] : null;
        if ($nama === '' || $jam <= 0 || $nilai < 0) {
            echo json_encode(['error'=>'Nama tier, estimasi jam, dan nilai wajib diisi (jam > 0)']); exit;
        }
        // Verifikasi outlet milik tenant ini (kalau di-pass)
        if ($tierOutletId !== null) {
            $own = TenantQuery::rawOne("SELECT id FROM outlets WHERE id=? AND tenant_id=?", [$tierOutletId, $tid]);
            if (!$own) { echo json_encode(['error'=>'Outlet tidak valid']); exit; }
        }

        $db = Database::get();
        try {
            if (!empty($d['id'])) {
                $st = $db->prepare(
                    "UPDATE hl_express_tier
                        SET nama_tier=?, estimasi_jam=?, tipe_biaya=?, nilai_biaya=?,
                            is_active=?, urutan=?, outlet_id=?
                      WHERE id=? AND tenant_id=?"
                );
                $st->execute([$nama, $jam, $tipe, $nilai, $aktif, $urut, $tierOutletId, (int)$d['id'], $tid]);
            } else {
                $st = $db->prepare(
                    "INSERT INTO hl_express_tier
                        (tenant_id, outlet_id, nama_tier, estimasi_jam, tipe_biaya, nilai_biaya, is_active, urutan)
                     VALUES (?,?,?,?,?,?,?,?)"
                );
                $st->execute([$tid, $tierOutletId, $nama, $jam, $tipe, $nilai, $aktif, $urut]);
            }
            echo json_encode(['success'=>true]);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'uniq_tenant_tier') || str_contains($msg, 'Duplicate')) {
                echo json_encode(['error'=>'Nama tier "'.$nama.'" sudah ada (gunakan nama beda kalau mau per-outlet, atau hapus yg sebelumnya)']);
            } else {
                echo json_encode(['error'=>'Gagal simpan: '.$msg]);
            }
        }
        exit;
    }

    if ($action === 'tier_delete' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('layanan.delete') && !hasPermission('layanan.edit')) {
            echo json_encode(['error'=>'Akses ditolak']); exit;
        }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $tierId = (int)($d['id'] ?? 0);
        Database::get()->prepare("DELETE FROM hl_express_tier WHERE id=? AND tenant_id=?")
                       ->execute([$tierId, $tid]);
        echo json_encode(['success'=>true]); exit;
    }

    // ── Biaya Lainnya Tier CRUD (tenant-level, dgn opsional per-outlet override) ──
    if ($action === 'biaya_lainnya_list') {
        try {
            $st = Database::get()->prepare(
                "SELECT id, nama, tipe_biaya, nilai_biaya, is_active, urutan, outlet_id
                   FROM hl_biaya_lainnya_tier
                  WHERE tenant_id = ? ORDER BY urutan ASC, id ASC"
            );
            $st->execute([$tid]);
            echo json_encode(['tiers' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) {
            echo json_encode(['error' => 'Gagal load: ' . $e->getMessage(), 'tiers' => []]);
        }
        exit;
    }

    if ($action === 'biaya_lainnya_save' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('layanan.edit') && !hasPermission('layanan.create')) {
            echo json_encode(['error'=>'Akses ditolak']); exit;
        }
        verifyCsrf();
        $d      = json_decode(file_get_contents('php://input'), true);
        $nama   = substr(trim(strip_tags((string)($d['nama'] ?? ''))), 0, 50);
        $tipe   = in_array($d['tipe_biaya'] ?? '', ['flat','percent'], true) ? $d['tipe_biaya'] : 'flat';
        $nilai  = max(0, (float)($d['nilai_biaya'] ?? 0));
        $aktif  = (int)($d['is_active'] ?? 1) ? 1 : 0;
        $urut   = (int)($d['urutan'] ?? 0);
        $tierOutletId = !empty($d['outlet_id']) ? (int)$d['outlet_id'] : null;
        $tierId = !empty($d['id']) ? (int)$d['id'] : null;
        if ($nama === '' || $nilai < 0) {
            echo json_encode(['error'=>'Nama & nilai wajib diisi']); exit;
        }
        if ($tierOutletId !== null) {
            $own = TenantQuery::rawOne("SELECT id FROM outlets WHERE id=? AND tenant_id=?", [$tierOutletId, $tid]);
            if (!$own) { echo json_encode(['error'=>'Outlet tidak valid']); exit; }
        }

        // Cek manual nama duplikat — UNIQUE index DB tidak cukup krn MySQL/MariaDB
        // menganggap outlet_id NULL beda-beda tiap baris (2 tier global nama sama
        // tetap lolos dari sisi DB). Tier global vs tier per-outlet nama sama juga
        // harus dicegah (skenario override harus disadari user, bukan bikin 2 entry).
        $dupSql    = "SELECT id FROM hl_biaya_lainnya_tier WHERE tenant_id = ? AND nama = ?";
        $dupParams = [$tid, $nama];
        if ($tierOutletId !== null) {
            $dupSql      .= " AND (outlet_id IS NULL OR outlet_id = ?)";
            $dupParams[]  = $tierOutletId;
        }
        if ($tierId !== null) {
            $dupSql      .= " AND id != ?";
            $dupParams[]  = $tierId;
        }
        $dup = TenantQuery::rawOne($dupSql, $dupParams);
        if ($dup) {
            echo json_encode(['error'=>'Nama "'.$nama.'" sudah ada (gunakan nama beda kalau mau per-outlet, atau hapus yg sebelumnya)']);
            exit;
        }

        $db = Database::get();
        try {
            if (!empty($d['id'])) {
                $st = $db->prepare(
                    "UPDATE hl_biaya_lainnya_tier
                        SET nama=?, tipe_biaya=?, nilai_biaya=?, is_active=?, urutan=?, outlet_id=?
                      WHERE id=? AND tenant_id=?"
                );
                $st->execute([$nama, $tipe, $nilai, $aktif, $urut, $tierOutletId, (int)$d['id'], $tid]);
            } else {
                $st = $db->prepare(
                    "INSERT INTO hl_biaya_lainnya_tier
                        (tenant_id, outlet_id, nama, tipe_biaya, nilai_biaya, is_active, urutan)
                     VALUES (?,?,?,?,?,?,?)"
                );
                $st->execute([$tid, $tierOutletId, $nama, $tipe, $nilai, $aktif, $urut]);
            }
            echo json_encode(['success'=>true]);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'uniq_tenant_outlet_biaya') || str_contains($msg, 'Duplicate')) {
                echo json_encode(['error'=>'Nama "'.$nama.'" sudah ada (gunakan nama beda kalau mau per-outlet, atau hapus yg sebelumnya)']);
            } else {
                echo json_encode(['error' => 'Gagal simpan: ' . $msg]);
            }
        }
        exit;
    }

    if ($action === 'biaya_lainnya_delete' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('layanan.delete') && !hasPermission('layanan.edit')) {
            echo json_encode(['error'=>'Akses ditolak']); exit;
        }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $tierId = (int)($d['id'] ?? 0);
        Database::get()->prepare("DELETE FROM hl_biaya_lainnya_tier WHERE id=? AND tenant_id=?")
                       ->execute([$tierId, $tid]);
        echo json_encode(['success'=>true]); exit;
    }

    if ($action === 'stats') {
        $total    = TenantQuery::count('hl_layanan', 'is_active=1');
        $kat      = TenantQuery::raw("SELECT COUNT(DISTINCT kategori) as c FROM hl_layanan WHERE tenant_id=? AND is_active=1", [$tid]);
        $terlaris = TenantQuery::raw(
            "SELECT i.nama_layanan, COUNT(*) as c FROM hl_transaksi_item i
             WHERE i.tenant_id=? GROUP BY i.nama_layanan ORDER BY c DESC LIMIT 1",
            [$tid]
        );
        echo json_encode([
            'total'    => $total,
            'kategori' => intval($kat[0]['c'] ?? 0),
            'terlaris' => $terlaris[0]['nama_layanan'] ?? '-',
        ]); exit;
    }
    echo json_encode(['error'=>'Unknown']); exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Master Layanan'); ?>
<style>
.layanan-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
.layanan-card{background:var(--white);border-radius:var(--r-lg);border:2px solid rgba(27,45,90,.07);padding:18px;transition:all .2s;position:relative}
.layanan-card:hover{box-shadow:var(--shadow-lg);transform:translateY(-2px)}
.layanan-card.inactive{opacity:.5}
.layanan-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:var(--r-lg) var(--r-lg) 0 0;background:var(--teal)}
.layanan-harga{font-family:var(--mono);font-size:1.3rem;font-weight:800;color:var(--navy);margin:6px 0 4px}
.lyn-badge{font-size:9px;font-weight:700;padding:2px 7px;border-radius:100px;margin-left:4px;white-space:nowrap}
.lyn-badge.adj{background:#E0F2FE;color:#0369A1}
.lyn-badge.lock{background:#F3F4F6;color:#6B7280}
.lyn-badge.ov{background:#FEF3C7;color:#92400E}
.layanan-nama{font-size:15px;font-weight:700;color:var(--navy);margin-bottom:4px}
.layanan-kat{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:var(--gray);margin-bottom:10px}
.layanan-actions{display:flex;gap:6px;margin-top:12px}
.toggle-switch{position:relative;width:40px;height:22px;cursor:pointer}
.toggle-switch input{opacity:0;width:0;height:0}
.toggle-slider{position:absolute;inset:0;background:#CBD5E1;border-radius:100px;transition:.3s}
.toggle-slider::before{content:'';position:absolute;width:16px;height:16px;left:3px;bottom:3px;background:white;border-radius:50%;transition:.3s}
input:checked + .toggle-slider{background:var(--green)}
input:checked + .toggle-slider::before{transform:translateX(18px)}
@media(max-width:680px){
  .layanan-grid{grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px}
  .layanan-harga{font-size:1.1rem}
}
@media(max-width:400px){.layanan-grid{grid-template-columns:1fr}}
/* Dropdown custom (ganti select native) — pola sama dgn piutang.php */
.lmui-trg{width:100%;padding:9px 12px;border:1.5px solid rgba(27,45,90,.14);border-radius:9px;font-family:inherit;font-size:14px;background:#fff;text-align:left;display:flex;align-items:center;justify-content:space-between;gap:8px;cursor:pointer;color:var(--navy);font-weight:600}
.lmui-trg .lmui-lbl{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.lmui-trg .lmui-car{color:var(--gray);font-size:12px;flex:0 0 auto}
.lmui-trg.ph .lmui-lbl{color:var(--gray)}
.hl-filter-bar .lmui-trg{width:auto;min-width:150px}
.lmui-pop{position:fixed;background:#fff;border:1px solid rgba(27,45,90,.12);border-radius:10px;box-shadow:0 12px 32px rgba(15,28,58,.16);z-index:9000;max-height:280px;overflow-y:auto;padding:6px}
.lmui-opt{display:block;width:100%;text-align:left;padding:10px 12px;border:0;background:none;font-family:inherit;font-size:14px;border-radius:7px;cursor:pointer;color:var(--navy);font-weight:600}
.lmui-opt:hover{background:var(--off,#F1F5FB)}
.lmui-opt.sel{background:#E8F0FE;color:#1E40AF;font-weight:700}
</style>
</head>
<body>
<?php renderTopbar('layanan'); ?>
<div class="hl-main">

  <!-- #3 Flag penjelas hierarki master → outlet -->
  <div style="display:flex;align-items:flex-start;gap:10px;background:#EFF6FF;border:1px solid #BFDBFE;
              border-radius:10px;padding:11px 14px;margin-bottom:16px;font-size:13px;color:#1E40AF;line-height:1.55">
    <span style="font-size:16px;flex-shrink:0">🧺</span>
    <div>
      <strong>Layanan khusus outlet ini.</strong>
      Daftar &amp; harga dasar dikelola terpusat di <strong>Master Katalog (HQ)</strong> lalu di-push ke outlet.
      Di sini kamu bisa lihat &amp; sesuaikan harga khusus outlet ini.
      <?php if (TenantResolver::isOwnerLevel()): ?>
        <a href="/hq/layanan" style="color:#1D4ED8;font-weight:700;text-decoration:underline">Buka Master Katalog →</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="hl-stat-grid-4" style="grid-template-columns:repeat(3,1fr);margin-bottom:14px">
    <div class="hl-stat-card teal"><div class="hl-stat-num" id="sTotal">-</div><div class="hl-stat-label">🧺 Layanan Aktif</div></div>
    <div class="hl-stat-card navy"><div class="hl-stat-num" id="sKat">-</div><div class="hl-stat-label">📂 Kategori</div></div>
    <div class="hl-stat-card green"><div class="hl-stat-num" id="sTerlaris" style="font-size:1rem">-</div><div class="hl-stat-label">🏆 Terlaris</div></div>
  </div>

  <?php if (hasPermission('layanan.create')): ?>
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px">
    <button class="hl-btn hl-btn-primary" onclick="openModal()" style="flex:1;min-width:150px">+ Tambah Layanan</button>
    <button class="hl-btn hl-btn-outline" onclick="openPresetModal()" style="flex:1;min-width:150px" title="Tambah cepat dari daftar layanan umum">📋 Dari Preset</button>
    <button class="hl-btn" onclick="openTierModal()" style="flex:1;min-width:150px;background:#F59E0B;color:#fff;border:none" title="Atur tier express: 12 jam, 6 jam, kilat, dll">⚡ Kelola Tier Express</button>
    <button class="hl-btn" onclick="openBiayaLainnyaModal()" style="flex:1;min-width:150px;background:#0EA5E9;color:#fff;border:none" title="Atur biaya lain yg otomatis kena ke semua order (biaya admin, PPN, dll)">💰 Kelola Biaya Lainnya</button>
  </div>
  <?php endif; ?>

  <div class="hl-filter-collapsible">
    <button class="hl-filter-toggle-btn" id="layananFilterBtn" onclick="toggleFilter('layananFilter')">
      🔍 Filter &amp; Pencarian <span class="hl-filter-active-dot" id="layananFilterDot"></span>
      <span class="hl-toggle-arrow">▼</span>
    </button>
    <div class="hl-filter-bar collapsed" id="layananFilter">
      <span class="hl-filter-label">Filter</span>
      <select id="fKat" class="hl-input lm-cust" style="width:auto" onchange="renderLayanan()">
        <option value="">Semua Kategori</option>
      </select>
      <select id="fStatus" class="hl-input lm-cust" style="width:auto" onchange="renderLayanan()">
        <option value="">Semua Status</option>
        <option value="1">Aktif</option>
        <option value="0">Nonaktif</option>
      </select>
      <input type="text" id="fSearch" class="hl-input" placeholder="🔍 Cari layanan..." style="max-width:240px" oninput="renderLayanan()"/>
      <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadLayanan()">↻</button>
    </div>
  </div>

  <div class="layanan-grid" id="layananGrid">
    <div class="hl-loading">⏳ Memuat...</div>
  </div>
</div>

<!-- MODAL -->
<div class="hl-modal-overlay" id="modalLayanan">
  <div class="hl-modal hl-modal-sm">
    <div class="hl-modal-header">
      <span class="hl-modal-title" id="modalTitle">➕ Tambah Layanan</span>
      <button class="hl-modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="hl-modal-body">
      <input type="hidden" id="f_id"/>
      <div class="hl-form-group">
        <label class="hl-label">Nama Layanan <span class="req">*</span></label>
        <input type="text" id="f_nama" class="hl-input" placeholder="Contoh: Kiloan Reguler"/>
      </div>
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Kategori <span class="req">*</span></label>
          <input type="text" id="f_kat" class="hl-input" placeholder="Kiloan, Satuan, dll" list="katList"/>
          <datalist id="katList"></datalist>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Satuan</label>
          <select id="f_satuan" class="hl-input lm-cust">
            <option value="kg">kg (kiloan)</option>
            <option value="pcs">pcs (potong/satuan)</option>
            <option value="item">item</option>
            <option value="pasang">pasang (sepatu/sandal)</option>
            <option value="set">set</option>
            <option value="lembar">lembar (selimut/sprei)</option>
            <option value="meter">meter (gorden/karpet)</option>
            <option value="kodi">kodi</option>
          </select>
        </div>
      </div>
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Harga / Satuan (Rp) <span class="req">*</span></label>
          <input type="text" id="f_harga" class="hl-input" placeholder="0" inputmode="numeric" autocomplete="off"/>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Min. Order <span style="color:var(--gray);font-weight:400;font-size:11px;">— 0 = tidak ada minimum</span></label>
          <input type="number" id="f_qty_min" class="hl-input" value="0" min="0" step="0.5" placeholder="0"/>
        </div>
      </div>
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Urutan Tampil <span style="color:var(--gray);font-weight:400;font-size:11px;">— khusus antar layanan yang di-pin</span></label>
          <input type="number" id="f_urutan" class="hl-input" value="0" min="0"/>
        </div>
        <div class="hl-form-group" style="display:flex;align-items:flex-end;padding-bottom:8px">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px">
            <input type="checkbox" id="f_pinned" style="width:18px;height:18px"/>
            📌 Pin ke atas di POS
          </label>
        </div>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Status</label>
        <select id="f_active" class="hl-input lm-cust">
          <option value="1">✅ Aktif</option>
          <option value="0">⏸️ Nonaktif</option>
        </select>
      </div>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeModal()">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="saveLayanan()">💾 Simpan</button>
    </div>
  </div>
</div>

<!-- ════ Modal Tambah dari Preset ════ -->
<div class="hl-modal-overlay" id="modalPreset">
  <div class="hl-modal">
    <div class="hl-modal-header">
      <span class="hl-modal-title">📋 Tambah dari Preset</span>
      <button class="hl-modal-close" onclick="document.getElementById('modalPreset').classList.remove('open')">✕</button>
    </div>
    <div class="hl-modal-body">
      <p style="font-size:12.5px;color:var(--gray);margin-bottom:12px">Centang layanan umum yang mau ditambahkan, lalu isi harganya. Layanan yang sudah ada dilewati otomatis.</p>
      <div id="presetList" style="max-height:52vh;overflow-y:auto"></div>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="document.getElementById('modalPreset').classList.remove('open')">Batal</button>
      <button class="hl-btn hl-btn-primary" id="btnSavePreset" onclick="savePresets()">💾 Tambahkan</button>
    </div>
  </div>
</div>
<!-- ════ Modal Tier Express GLOBAL ════ -->
<div class="hl-modal-overlay" id="modalTier">
  <div class="hl-modal" style="max-width:680px">
    <div class="hl-modal-header">
      <span class="hl-modal-title">⚡ Kelola Tier Express</span>
      <button class="hl-modal-close" onclick="closeTierModal()">✕</button>
    </div>
    <div class="hl-modal-body">

      <div style="background:#FEF3C7;border:1px solid #FDE68A;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#92400E;line-height:1.5;">
        💡 Tier ini berlaku untuk semua layanan. Di POS, kasir bisa pilih tier per item baris — 1 nota bisa campur reguler &amp; express. Biaya tambahan dihitung otomatis (flat atau % dari subtotal item).
      </div>

      <!-- List tier -->
      <div id="tierList" style="margin-bottom:16px;"></div>

      <!-- Form tambah/edit tier -->
      <div style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:10px;padding:14px;">
        <div style="font-weight:600;font-size:13px;color:#374151;margin-bottom:10px;" id="tierFormTitle">➕ Tambah Tier Baru</div>
        <input type="hidden" id="tf_id"/>
        <div class="hl-form-row">
          <div class="hl-form-group">
            <label class="hl-label">Nama Tier <span class="req">*</span></label>
            <input type="text" id="tf_nama" class="hl-input" placeholder="Express 12 Jam" maxlength="50"/>
          </div>
          <div class="hl-form-group">
            <label class="hl-label">Estimasi Selesai (jam) <span class="req">*</span></label>
            <input type="number" id="tf_jam" class="hl-input" placeholder="12" min="1" max="168"/>
          </div>
        </div>
        <div class="hl-form-row">
          <div class="hl-form-group">
            <label class="hl-label">Tipe Biaya</label>
            <select id="tf_tipe" class="hl-input lm-cust" onchange="updateNilaiUnit()">
              <option value="percent">Percent (% dari subtotal item)</option>
              <option value="flat">Flat (Rp tetap)</option>
            </select>
          </div>
          <div class="hl-form-group">
            <label class="hl-label">Nilai <span class="req">*</span> <span id="nilaiUnit" style="color:var(--gray);font-weight:400;">(%)</span></label>
            <input type="number" id="tf_nilai" class="hl-input" placeholder="30" min="0" step="any"/>
          </div>
        </div>
        <div class="hl-form-row">
          <div class="hl-form-group">
            <label class="hl-label">Berlaku di Outlet <span style="font-size:11px;color:var(--gray);font-weight:400">— strategi per outlet</span></label>
            <select id="tf_outlet" class="hl-input lm-cust">
              <option value="">🌍 Semua outlet</option>
              <!-- populated by JS -->
            </select>
          </div>
          <div class="hl-form-group">
            <label class="hl-label">Status</label>
            <select id="tf_active" class="hl-input lm-cust">
              <option value="1">✅ Aktif</option>
              <option value="0">⏸️ Nonaktif</option>
            </select>
          </div>
        </div>
        <div class="hl-form-row">
          <div class="hl-form-group">
            <label class="hl-label">Urutan</label>
            <input type="number" id="tf_urutan" class="hl-input" value="0" min="0"/>
          </div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px;">
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="resetTierForm()">↺ Reset</button>
          <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="saveTier()">💾 Simpan Tier</button>
        </div>
      </div>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeTierModal()">Tutup</button>
    </div>
  </div>
</div>

<!-- ════ Modal Biaya Lainnya GLOBAL ════ -->
<div class="hl-modal-overlay" id="modalBiayaLainnya">
  <div class="hl-modal" style="max-width:680px">
    <div class="hl-modal-header">
      <span class="hl-modal-title">💰 Kelola Biaya Lainnya</span>
      <button class="hl-modal-close" onclick="closeBiayaLainnyaModal()">✕</button>
    </div>
    <div class="hl-modal-body">

      <div style="background:#E0F2FE;border:1px solid #BAE6FD;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#0C4A6E;line-height:1.5;">
        💡 Biaya di sini OTOMATIS kena ke SETIAP order baru di POS — tidak
        ada pilihan apa pun buat kasir. Kalau lebih dari 1 status Aktif,
        semuanya dijumlah & tampil sbg baris terpisah di struk.
      </div>

      <!-- List tier -->
      <div id="biayaLainnyaList" style="margin-bottom:16px;"></div>

      <!-- Form tambah/edit -->
      <div style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:10px;padding:14px;">
        <div style="font-weight:600;font-size:13px;color:#374151;margin-bottom:10px;" id="blFormTitle">➕ Tambah Biaya Baru</div>
        <input type="hidden" id="bl_id"/>
        <div class="hl-form-row">
          <div class="hl-form-group">
            <label class="hl-label">Nama Biaya <span class="req">*</span></label>
            <input type="text" id="bl_nama" class="hl-input" placeholder="Biaya Admin" maxlength="50"/>
          </div>
          <div class="hl-form-group">
            <label class="hl-label">Tipe Biaya</label>
            <select id="bl_tipe" class="hl-input lm-cust" onchange="updateBlNilaiUnit()">
              <option value="flat">Flat (Rp tetap)</option>
              <option value="percent">Percent (% dari subtotal order)</option>
            </select>
          </div>
        </div>
        <div class="hl-form-row">
          <div class="hl-form-group">
            <label class="hl-label">Nilai <span class="req">*</span> <span id="blNilaiUnit" style="color:var(--gray);font-weight:400;">(Rp)</span></label>
            <input type="number" id="bl_nilai" class="hl-input" placeholder="2000" min="0" step="any"/>
          </div>
          <div class="hl-form-group">
            <label class="hl-label">Berlaku di Outlet <span style="font-size:11px;color:var(--gray);font-weight:400">— strategi per outlet</span></label>
            <select id="bl_outlet" class="hl-input lm-cust">
              <option value="">🌍 Semua outlet</option>
              <!-- populated by JS -->
            </select>
          </div>
        </div>
        <div class="hl-form-row">
          <div class="hl-form-group">
            <label class="hl-label">Status</label>
            <select id="bl_active" class="hl-input lm-cust">
              <option value="1">✅ Aktif</option>
              <option value="0">⏸️ Nonaktif</option>
            </select>
          </div>
          <div class="hl-form-group">
            <label class="hl-label">Urutan</label>
            <input type="number" id="bl_urutan" class="hl-input" value="0" min="0"/>
          </div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px;">
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="resetBiayaLainnyaForm()">↺ Reset</button>
          <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="saveBiayaLainnya()">💾 Simpan</button>
        </div>
      </div>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeBiayaLainnyaModal()">Tutup</button>
    </div>
  </div>
</div>

<?php renderToast(); ?>
<script>
let allLayanan = [];
const CAN_CREATE = <?= hasPermission('layanan.create') ? 'true' : 'false' ?>;
const CAN_EDIT   = <?= hasPermission('layanan.edit')   ? 'true' : 'false' ?>;
const CAN_DELETE = <?= hasPermission('layanan.delete') ? 'true' : 'false' ?>;

document.addEventListener('DOMContentLoaded', () => { loadLayanan(); loadStats(); });

async function loadStats() {
  const r = await fetch('layanan.php?action=stats');
  const d = await r.json();
  document.getElementById('sTotal').textContent    = d.total;
  document.getElementById('sKat').textContent      = d.kategori;
  document.getElementById('sTerlaris').textContent = d.terlaris;
}

// ── Tambah dari Preset (dikelola SuperAdmin) ──
async function openPresetModal() {
  const box = document.getElementById('presetList');
  box.innerHTML = '<div style="text-align:center;padding:24px;color:var(--gray)">⏳ Memuat preset…</div>';
  document.getElementById('modalPreset').classList.add('open');
  try {
    const r = await fetch('layanan.php?action=presets');
    const d = await r.json();
    if (d.error) { box.innerHTML = `<div style="color:#DC2626;padding:16px">${d.error}</div>`; return; }
    const ps = d.presets || [];
    if (!ps.length) { box.innerHTML = '<div style="text-align:center;padding:24px;color:var(--gray)">Belum ada preset tersedia.</div>'; return; }
    box.innerHTML = ps.map((p, i) => {
      const dis = p.sudah_ada;
      return `<div style="display:flex;align-items:center;gap:10px;padding:10px 4px;border-bottom:1px solid var(--light);${dis?'opacity:.5':''}">
        <input type="checkbox" class="pchk" data-i="${i}" data-nama="${esc(p.nama)}" ${p.default_checked && !dis ? 'checked':''} ${dis?'disabled':''}
               style="width:18px;height:18px;flex-shrink:0" onchange="pToggleRow(${i})">
        <div style="flex:1;min-width:0">
          <div style="font-weight:600;font-size:13.5px">${esc(p.nama)}${dis?' <span style="font-size:11px;color:var(--gray);font-weight:400">· sudah ada</span>':''}</div>
          <div style="font-size:11px;color:var(--gray)">${esc(p.kategori)} · per ${esc(p.satuan)}</div>
        </div>
        <div style="display:flex;align-items:center;gap:4px;flex-shrink:0">
          <span style="font-size:12px;color:var(--gray)">Rp</span>
          <input type="text" inputmode="numeric" class="pharga" data-i="${i}" placeholder="Harga" ${dis?'disabled':''}
                 oninput="this.value=(this.value.replace(/[^0-9]/g,'')||'').replace(/\\B(?=(\\d{3})+(?!\\d))/g,'.')"
                 style="width:90px;padding:6px 8px;border:1.5px solid var(--light);border-radius:8px;font-size:13px;text-align:right">
        </div>
      </div>`;
    }).join('');
  } catch (e) {
    box.innerHTML = `<div style="color:#DC2626;padding:16px">Gagal memuat: ${e.message}</div>`;
  }
}
function pToggleRow(i) {
  // fokuskan input harga saat dicentang
  const chk = document.querySelector(`.pchk[data-i="${i}"]`);
  const inp = document.querySelector(`.pharga[data-i="${i}"]`);
  if (chk && chk.checked && inp && !inp.value) inp.focus();
}
async function savePresets() {
  const items = [];
  document.querySelectorAll('.pchk:checked').forEach(chk => {
    const i = chk.dataset.i;
    const inp = document.querySelector(`.pharga[data-i="${i}"]`);
    const harga = parseInt((inp?.value || '').replace(/[^0-9]/g,''), 10) || 0;
    items.push({ nama: chk.dataset.nama, harga });
  });
  if (!items.length) { showToast('Centang minimal 1 layanan', 'error'); return; }
  const noPrice = items.filter(x => x.harga <= 0);
  if (noPrice.length) { showToast(`Isi harga untuk: ${noPrice.map(x=>x.nama).join(', ')}`, 'error'); return; }
  const btn = document.getElementById('btnSavePreset');
  btn.disabled = true;
  try {
    const r = await fetch('layanan.php?action=save_presets', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify({ items })
    });
    const d = await r.json();
    if (d.error) { showToast(d.error, 'error'); return; }
    showToast(`✅ ${d.added} layanan ditambahkan${d.skip ? ` (${d.skip} dilewati)` : ''}`, 'success');
    document.getElementById('modalPreset').classList.remove('open');
    loadLayanan(); loadStats();
  } catch (e) {
    showToast('Gagal: ' + e.message, 'error');
  } finally { btn.disabled = false; }
}

// Rekomendasi kategori umum laundry — digabung dengan kategori yang sudah dipakai
const KAT_REKOMENDASI = ['Kiloan','Satuan','Express','Setrika','Cuci Kering','Dry Clean','Khusus','Sepatu','Bedcover & Selimut','Karpet & Gorden','B2B / Korporat','Self-Service','Tambahan Self-Service'];

async function loadLayanan() {
  const r = await fetch('layanan.php?action=list');
  allLayanan = await r.json();
  const kats = [...new Set(allLayanan.map(l=>l.kategori).filter(Boolean))].sort();
  const fKat = document.getElementById('fKat');
  fKat.innerHTML = '<option value="">Semua Kategori</option>' + kats.map(k=>`<option>${k}</option>`).join('');
  lmSyncSel('fKat');
  // datalist input kategori: gabung rekomendasi + yang sudah dipakai (unik)
  const katOpts = [...new Set([...kats, ...KAT_REKOMENDASI])];
  document.getElementById('katList').innerHTML = katOpts.map(k=>`<option value="${k}">`).join('');
  renderLayanan();
}

function renderLayanan() {
  const q      = document.getElementById('fSearch').value.toLowerCase();
  const kat    = document.getElementById('fKat').value;
  const status = document.getElementById('fStatus').value;

  let list = allLayanan;
  if (q)      list = list.filter(l => l.nama.toLowerCase().includes(q) || (l.kategori||'').toLowerCase().includes(q));
  if (kat)    list = list.filter(l => l.kategori === kat);
  if (status !== '') list = list.filter(l => String(l.is_active) === status);

  const grid = document.getElementById('layananGrid');
  if (!list.length) { grid.innerHTML = `<div style="grid-column:1/-1"><div class="hl-empty-v2">
    <div class="e-icon">🧺</div>
    <div class="e-title">Belum ada layanan</div>
    <div class="e-sub">Tambah layanan supaya bisa dipakai di POS</div>
  </div></div>`; return; }

  grid.innerHTML = list.map(l => {
    const isMaster = !!l.master_id;
    const canAdjust = isMaster && String(l.allow_override) === '1';
    const isOverridden = String(l.harga_overridden) === '1';

    // Badge sumber
    let badge = '';
    if (isMaster) {
      badge = canAdjust
        ? `<span class="lyn-badge adj" title="Dari HQ, boleh adjust ±${l.override_max_pct}%">🏢 HQ · ±${l.override_max_pct}%</span>`
        : `<span class="lyn-badge lock" title="Harga dikunci HQ">🔒 HQ</span>`;
    }
    const ovTag = isOverridden ? `<span class="lyn-badge ov">harga custom</span>` : '';

    // Tombol aksi: master → adjust/locked; non-master → edit/delete penuh
    let actions;
    if (isMaster) {
      actions = canAdjust
        ? `<button class="hl-btn hl-btn-outline hl-btn-sm" onclick='openAdjust(${JSON.stringify(l)})'>💲 Adjust Harga</button>`
        : `<span style="font-size:11px;color:var(--gray)">dikelola HQ</span>`;
    } else {
      actions = '';
      if (CAN_EDIT)   actions += `<button class="hl-btn hl-btn-outline hl-btn-sm" onclick="editLayanan(${l.id})">✏️ Edit</button>`;
      if (CAN_DELETE) actions += `<button class="hl-btn hl-btn-danger hl-btn-sm" onclick="deleteLayanan(${l.id})">🗑️</button>`;
      if (!actions)   actions  = `<span style="font-size:11px;color:var(--gray)">view only</span>`;
    }

    const pinBadge = l.is_pinned==1 ? `<span class="lyn-badge" title="Pin ke atas di POS">📌</span>` : '';
    return `
    <div class="layanan-card ${l.is_active==1?'':'inactive'}">
      <div class="layanan-kat">${esc(l.kategori||'Umum')} ${badge} ${ovTag} ${pinBadge}</div>
      <div class="layanan-nama">${esc(l.nama)}</div>
      <div class="layanan-harga">Rp ${grpRibu(l.harga)} <span style="font-size:13px;font-weight:400;color:var(--gray)">/ ${l.satuan}</span></div>
      ${canAdjust ? `<div style="font-size:11px;color:var(--gray);margin-top:2px">Default HQ: Rp ${grpRibu(l.harga_default)}</div>` : ''}
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px">
        <label class="toggle-switch" title="${l.is_active==1?'Nonaktifkan':'Aktifkan'}">
          <input type="checkbox" ${l.is_active==1?'checked':''} onchange="toggleLayanan(${l.id},this.checked)"/>
          <span class="toggle-slider"></span>
        </label>
        <div class="layanan-actions">${actions}</div>
      </div>
    </div>`;
  }).join('');
}

function openModal(data=null) {
  document.getElementById('f_id').value     = data?.id || '';
  document.getElementById('f_nama').value   = data?.nama || '';
  document.getElementById('f_kat').value    = data?.kategori || '';
  document.getElementById('f_satuan').value = data?.satuan || 'kg';
  document.getElementById('f_harga').value  = data?.harga ? grpRibu(data.harga) : '';
  document.getElementById('f_qty_min').value = parseFloat(data?.qty_minimum) || 0;
  document.getElementById('f_urutan').value = data?.urutan || 0;
  document.getElementById('f_pinned').checked = !!(data?.is_pinned == 1);
  document.getElementById('f_active').value = data?.is_active ?? 1;
  lmSyncSel('f_satuan','f_active');
  document.getElementById('modalTitle').textContent = data ? '✏️ Edit Layanan' : '➕ Tambah Layanan';
  document.getElementById('modalLayanan').classList.add('open');
}
function editLayanan(id) { openModal(allLayanan.find(l=>l.id==id)); }
function closeModal() { document.getElementById('modalLayanan').classList.remove('open'); }

// ── Tier Express GLOBAL CRUD ──
let allOutlets = [];

async function loadOutletsForTier() {
  if (allOutlets.length > 0) return;
  try {
    const r = await fetch('?action=outlets');
    const d = await r.json();
    allOutlets = d.outlets || [];
    const sel = document.getElementById('tf_outlet');
    sel.innerHTML = '<option value="">🌍 Semua outlet</option>' +
      allOutlets.map(o => `<option value="${o.id}">🏪 ${esc(o.nama_outlet)}</option>`).join('');
    lmSyncSel('tf_outlet');
  } catch(e) { /* silent */ }
}

async function openTierModal() {
  await loadOutletsForTier();
  resetTierForm();
  document.getElementById('modalTier').classList.add('open');
  await loadTiers();
}
function closeTierModal() { document.getElementById('modalTier').classList.remove('open'); }

async function loadTiers() {
  const list = document.getElementById('tierList');
  list.innerHTML = '<div style="text-align:center;padding:14px;color:var(--gray);font-size:12px;">Memuat...</div>';
  try {
    const r = await fetch(`?action=tier_list`);
    const d = await r.json();
    if (d.error && d.tiers === undefined) { showToast(d.error, 'error'); list.innerHTML = ''; return; }
    if (d.error) showToast(d.error, 'info');
    renderTierList(d.tiers || []);
  } catch (e) {
    showToast('Gagal load tier: ' + e.message, 'error');
    list.innerHTML = '';
  }
}

function renderTierList(tiers) {
  const list = document.getElementById('tierList');
  if (!tiers.length) {
    list.innerHTML = '<div style="text-align:center;padding:24px;color:var(--gray);font-size:13px;background:#F9FAFB;border:1px dashed #E5E7EB;border-radius:8px;">⚡ Belum ada tier. Tambah pakai form di bawah ↓</div>';
    return;
  }
  list.innerHTML = `
    <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:13px;">
      <thead>
        <tr style="background:#F3F4F6;text-align:left;">
          <th style="padding:8px 10px;">Nama Tier</th>
          <th style="padding:8px 10px;">Outlet</th>
          <th style="padding:8px 10px;">Estimasi</th>
          <th style="padding:8px 10px;">Biaya</th>
          <th style="padding:8px 10px;">Status</th>
          <th style="padding:8px 10px;text-align:right;">Aksi</th>
        </tr>
      </thead>
      <tbody>
        ${tiers.map(t => {
          const outletLabel = t.outlet_id
            ? (allOutlets.find(o => o.id == t.outlet_id)?.nama_outlet || `Outlet #${t.outlet_id}`)
            : '🌍 Semua';
          return `
          <tr style="border-bottom:1px solid #F3F4F6;">
            <td style="padding:10px;">⚡ <strong>${esc(t.nama_tier)}</strong></td>
            <td style="padding:10px;font-size:11px;${t.outlet_id?'color:#0F7B6C;':'color:#6B7280;'}">${esc(outletLabel)}</td>
            <td style="padding:10px;color:#4B5563;">${t.estimasi_jam} jam</td>
            <td style="padding:10px;">
              ${t.tipe_biaya === 'flat'
                ? '+Rp ' + grpRibu(t.nilai_biaya)
                : '+' + parseFloat(t.nilai_biaya) + '%'}
            </td>
            <td style="padding:10px;">${t.is_active == 1 ? '<span style="color:#059669;">● Aktif</span>' : '<span style="color:#9CA3AF;">○ Off</span>'}</td>
            <td style="padding:10px;text-align:right;white-space:nowrap;">
              <button class="hl-btn hl-btn-outline hl-btn-sm" onclick='editTier(${JSON.stringify(t)})'>✏️</button>
              <button class="hl-btn hl-btn-danger hl-btn-sm" onclick="deleteTier(${t.id})">🗑️</button>
            </td>
          </tr>`;
        }).join('')}
      </tbody>
    </table></div>`;
}

function updateNilaiUnit() {
  const tipe = document.getElementById('tf_tipe').value;
  document.getElementById('nilaiUnit').textContent = tipe === 'flat' ? '(Rp)' : '(%)';
  const input = document.getElementById('tf_nilai');
  input.placeholder = tipe === 'flat' ? '5000' : '30';
}

function resetTierForm() {
  document.getElementById('tf_id').value     = '';
  document.getElementById('tf_nama').value   = '';
  document.getElementById('tf_jam').value    = '';
  document.getElementById('tf_tipe').value   = 'percent';
  document.getElementById('tf_nilai').value  = '';
  document.getElementById('tf_urutan').value = 0;
  document.getElementById('tf_active').value = 1;
  document.getElementById('tf_outlet').value = '';
  lmSyncSel('tf_tipe','tf_active','tf_outlet');
  document.getElementById('tierFormTitle').textContent = '➕ Tambah Tier Baru';
  updateNilaiUnit();
}

function editTier(t) {
  document.getElementById('tf_id').value     = t.id;
  document.getElementById('tf_nama').value   = t.nama_tier;
  document.getElementById('tf_jam').value    = t.estimasi_jam;
  document.getElementById('tf_tipe').value   = t.tipe_biaya;
  document.getElementById('tf_nilai').value  = t.nilai_biaya;
  document.getElementById('tf_urutan').value = t.urutan;
  document.getElementById('tf_active').value = t.is_active;
  document.getElementById('tf_outlet').value = t.outlet_id || '';
  lmSyncSel('tf_tipe','tf_active','tf_outlet');
  document.getElementById('tierFormTitle').textContent = '✏️ Edit Tier';
  updateNilaiUnit();
}

async function saveTier() {
  const payload = {
    id:           document.getElementById('tf_id').value || null,
    nama_tier:    document.getElementById('tf_nama').value.trim(),
    estimasi_jam: parseInt(document.getElementById('tf_jam').value) || 0,
    tipe_biaya:   document.getElementById('tf_tipe').value,
    nilai_biaya:  parseFloat(document.getElementById('tf_nilai').value) || 0,
    is_active:    parseInt(document.getElementById('tf_active').value),
    urutan:       parseInt(document.getElementById('tf_urutan').value) || 0,
    outlet_id:    document.getElementById('tf_outlet').value || null,
  };
  if (!payload.nama_tier || payload.estimasi_jam <= 0) {
    showToast('Nama tier & estimasi jam wajib diisi', 'error'); return;
  }
  try {
    const r = await fetch('?action=tier_save', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify(payload)
    });
    const d = await r.json();
    if (d.error) { showToast(d.error, 'error'); return; }
    showToast('Tier tersimpan', 'success');
    resetTierForm();
    await loadTiers();
  } catch(e) {
    showToast('Gagal simpan: ' + e.message, 'error');
  }
}

async function deleteTier(id) {
  if (!await lmConfirm('Hapus tier ini? Aksi tidak bisa di-undo.')) return;
  try {
    const r = await fetch('?action=tier_delete', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify({id})
    });
    const d = await r.json();
    if (d.error) { showToast(d.error, 'error'); return; }
    showToast('Tier dihapus', 'success');
    await loadTiers();
  } catch(e) {
    showToast('Gagal hapus: ' + e.message, 'error');
  }
}

// ── Biaya Lainnya Tier GLOBAL CRUD ──
async function openBiayaLainnyaModal() {
  await loadOutletsForTier(); // reuse loader Tier Express — sama-sama isi #tf_outlet-style dropdown
  const sel = document.getElementById('bl_outlet');
  sel.innerHTML = '<option value="">🌍 Semua outlet</option>' +
    allOutlets.map(o => `<option value="${o.id}">🏪 ${esc(o.nama_outlet)}</option>`).join('');
  lmSyncSel('bl_outlet');
  resetBiayaLainnyaForm();
  document.getElementById('modalBiayaLainnya').classList.add('open');
  await loadBiayaLainnyaTiers();
}
function closeBiayaLainnyaModal() { document.getElementById('modalBiayaLainnya').classList.remove('open'); }

async function loadBiayaLainnyaTiers() {
  const list = document.getElementById('biayaLainnyaList');
  list.innerHTML = '<div style="text-align:center;padding:14px;color:var(--gray);font-size:12px;">Memuat...</div>';
  try {
    const r = await fetch(`?action=biaya_lainnya_list`);
    const d = await r.json();
    if (d.error && d.tiers === undefined) { showToast(d.error, 'error'); list.innerHTML = ''; return; }
    renderBiayaLainnyaList(d.tiers || []);
  } catch (e) {
    showToast('Gagal load: ' + e.message, 'error');
    list.innerHTML = '';
  }
}

let currentBiayaLainnyaTiers = [];

function renderBiayaLainnyaList(tiers) {
  currentBiayaLainnyaTiers = tiers;
  const list = document.getElementById('biayaLainnyaList');
  if (!tiers.length) {
    list.innerHTML = '<div style="text-align:center;padding:24px;color:var(--gray);font-size:13px;background:#F9FAFB;border:1px dashed #E5E7EB;border-radius:8px;">💰 Belum ada biaya lain. Tambah pakai form di bawah ↓</div>';
    return;
  }
  list.innerHTML = `
    <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:13px;">
      <thead>
        <tr style="background:#F3F4F6;text-align:left;">
          <th style="padding:8px 10px;">Nama</th>
          <th style="padding:8px 10px;">Outlet</th>
          <th style="padding:8px 10px;">Nilai</th>
          <th style="padding:8px 10px;">Status</th>
          <th style="padding:8px 10px;text-align:right;">Aksi</th>
        </tr>
      </thead>
      <tbody>
        ${tiers.map(t => {
          const outletLabel = t.outlet_id
            ? (allOutlets.find(o => o.id == t.outlet_id)?.nama_outlet || `Outlet #${t.outlet_id}`)
            : '🌍 Semua';
          return `
          <tr style="border-bottom:1px solid #F3F4F6;">
            <td style="padding:10px;">💰 <strong>${esc(t.nama)}</strong></td>
            <td style="padding:10px;font-size:11px;${t.outlet_id?'color:#0F7B6C;':'color:#6B7280;'}">${esc(outletLabel)}</td>
            <td style="padding:10px;">
              ${t.tipe_biaya === 'flat'
                ? '+Rp ' + grpRibu(t.nilai_biaya)
                : '+' + parseFloat(t.nilai_biaya) + '%'}
            </td>
            <td style="padding:10px;">${t.is_active == 1 ? '<span style="color:#059669;">● Aktif</span>' : '<span style="color:#9CA3AF;">○ Off</span>'}</td>
            <td style="padding:10px;text-align:right;white-space:nowrap;">
              <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="editBiayaLainnya(${t.id})">✏️</button>
              <button class="hl-btn hl-btn-danger hl-btn-sm" onclick="deleteBiayaLainnya(${t.id})">🗑️</button>
            </td>
          </tr>`;
        }).join('')}
      </tbody>
    </table></div>`;
}

function updateBlNilaiUnit() {
  const tipe = document.getElementById('bl_tipe').value;
  document.getElementById('blNilaiUnit').textContent = tipe === 'flat' ? '(Rp)' : '(%)';
  const input = document.getElementById('bl_nilai');
  input.placeholder = tipe === 'flat' ? '2000' : '2';
}

function resetBiayaLainnyaForm() {
  document.getElementById('bl_id').value     = '';
  document.getElementById('bl_nama').value   = '';
  document.getElementById('bl_tipe').value   = 'flat';
  document.getElementById('bl_nilai').value  = '';
  document.getElementById('bl_urutan').value = 0;
  document.getElementById('bl_active').value = 1;
  document.getElementById('bl_outlet').value = '';
  lmSyncSel('bl_tipe','bl_active','bl_outlet');
  document.getElementById('blFormTitle').textContent = '➕ Tambah Biaya Baru';
  updateBlNilaiUnit();
}

function editBiayaLainnya(id) {
  const t = currentBiayaLainnyaTiers.find(x => x.id == id);
  if (!t) return;
  document.getElementById('bl_id').value     = t.id;
  document.getElementById('bl_nama').value   = t.nama;
  document.getElementById('bl_tipe').value   = t.tipe_biaya;
  document.getElementById('bl_nilai').value  = t.nilai_biaya;
  document.getElementById('bl_urutan').value = t.urutan;
  document.getElementById('bl_active').value = t.is_active;
  document.getElementById('bl_outlet').value = t.outlet_id || '';
  lmSyncSel('bl_tipe','bl_active','bl_outlet');
  document.getElementById('blFormTitle').textContent = '✏️ Edit Biaya';
  updateBlNilaiUnit();
}

async function saveBiayaLainnya() {
  const payload = {
    id:          document.getElementById('bl_id').value || null,
    nama:        document.getElementById('bl_nama').value.trim(),
    tipe_biaya:  document.getElementById('bl_tipe').value,
    nilai_biaya: parseFloat(document.getElementById('bl_nilai').value) || 0,
    is_active:   parseInt(document.getElementById('bl_active').value),
    urutan:      parseInt(document.getElementById('bl_urutan').value) || 0,
    outlet_id:   document.getElementById('bl_outlet').value || null,
  };
  if (!payload.nama) {
    showToast('Nama biaya wajib diisi', 'error'); return;
  }
  try {
    const r = await fetch('?action=biaya_lainnya_save', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify(payload)
    });
    const d = await r.json();
    if (d.error) { showToast(d.error, 'error'); return; }
    showToast('Biaya tersimpan', 'success');
    resetBiayaLainnyaForm();
    await loadBiayaLainnyaTiers();
  } catch(e) {
    showToast('Gagal simpan: ' + e.message, 'error');
  }
}

async function deleteBiayaLainnya(id) {
  if (!await lmConfirm('Hapus biaya ini? Aksi tidak bisa di-undo.')) return;
  try {
    const r = await fetch('?action=biaya_lainnya_delete', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify({id})
    });
    const d = await r.json();
    if (d.error) { showToast(d.error, 'error'); return; }
    showToast('Biaya dihapus', 'success');
    await loadBiayaLainnyaTiers();
  } catch(e) {
    showToast('Gagal hapus: ' + e.message, 'error');
  }
}

// ── Adjust harga (override) untuk layanan dari master ──
async function openAdjust(l){
  const base = parseFloat(l.harga_default) || 0;
  const pct  = parseFloat(l.override_max_pct) || 0;
  const min = base > 0 && pct > 0 ? Math.round(base * (1 - pct/100)) : 0;
  const max = base > 0 && pct > 0 ? Math.round(base * (1 + pct/100)) : 0;
  const rangeTxt = (min && max)
    ? `Rentang diizinkan: Rp ${grpRibu(min)} – Rp ${grpRibu(max)} (±${pct}%)`
    : `Default HQ: Rp ${grpRibu(base)}`;

  const harga = await lmPrompt(
    `Adjust harga "${l.nama}"\n${rangeTxt}\n\nHarga sekarang: Rp ${grpRibu(l.harga)}\nMasukkan harga baru:`,
    l.harga
  );
  if (harga === null) return;
  const val = parseFloat(harga);
  if (isNaN(val) || val < 0) { showToast('⚠️ Harga tidak valid','error'); return; }

  const r = await fetch('layanan.php?action=override_harga', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({ master_id: l.master_id, harga: val })
  });
  const d = await r.json();
  if (d.success) { showToast('✅ Harga di-adjust!','success'); loadLayanan(); }
  else showToast('❌ '+(d.error||'Gagal'),'error');
}

async function saveLayanan() {
  const nama  = document.getElementById('f_nama').value.trim();
  const harga = document.getElementById('f_harga').value.replace(/\./g,''); // buang separator ribuan
  if (!nama)  { showToast('⚠️ Nama wajib diisi','error'); return; }
  if (!harga) { showToast('⚠️ Harga wajib diisi','error'); return; }

  const r = await fetch('layanan.php?action=save', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({
      id: document.getElementById('f_id').value, nama, harga,
      kategori:    document.getElementById('f_kat').value,
      satuan:      document.getElementById('f_satuan').value,
      qty_minimum: document.getElementById('f_qty_min').value,
      urutan:      document.getElementById('f_urutan').value,
      is_pinned:   document.getElementById('f_pinned').checked ? 1 : 0,
      is_active:   document.getElementById('f_active').value,
    })
  });
  const d = await r.json();
  if (d.success) { showToast('✅ Layanan disimpan!','success'); closeModal(); loadLayanan(); loadStats(); }
  else showToast('❌ '+(d.error||'Gagal'),'error');
}

async function toggleLayanan(id, active) {
  await fetch('layanan.php?action=toggle', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({id, is_active: active ? 1 : 0})
  });
  loadLayanan(); loadStats();
}

async function deleteLayanan(id) {
  if (!await lmConfirm('Nonaktifkan layanan ini?')) return;
  await fetch('layanan.php?action=delete', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({id})
  });
  showToast('✅ Layanan dinonaktifkan','success'); loadLayanan(); loadStats();
}

function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}

/* ── Format ribuan (WebView tak dukung toLocaleString grouping) ── */
function grpRibu(n){ return String(Math.round(parseFloat(n)||0)).replace(/\B(?=(\d{3})+(?!\d))/g,'.'); }
// Auto-separator saat ketik harga
(function(){
  const fh = document.getElementById('f_harga');
  fh.addEventListener('input', () => {
    const digits = fh.value.replace(/\D/g,'');
    fh.value = digits ? grpRibu(digits) : '';
  });
})();

/* ── Dropdown custom (ganti select native) — pola sama dgn piutang.php ── */
let _pop=null,_popAnchor=null;
function _closePop(){ if(_pop){_pop.remove();_pop=null;} _popAnchor=null;
  document.removeEventListener('mousedown',_onOutside,true);
  window.removeEventListener('scroll',_closePop,true); window.removeEventListener('resize',_closePop); }
function _onOutside(e){ if(e.target.closest('.lmui-pop')||e.target.closest('.lmui-trg')) return; _closePop(); }
function _placePop(anchor){
  const r=anchor.getBoundingClientRect();
  _pop.style.left=r.left+'px'; _pop.style.minWidth=r.width+'px';
  const ph=_pop.offsetHeight; let top=r.bottom+4;
  if(top+ph>window.innerHeight-8) top=Math.max(8,r.top-ph-4);
  _pop.style.top=top+'px';
  const pw=_pop.offsetWidth;
  if(r.left+pw>window.innerWidth-8) _pop.style.left=Math.max(8,window.innerWidth-pw-8)+'px';
}
function _initSel(sel){
  sel.classList.remove('lm-cust'); sel.style.display='none';
  const trg=document.createElement('button'); trg.type='button'; trg.className='lmui-trg';
  trg.innerHTML='<span class="lmui-lbl"></span><span class="lmui-car">▾</span>';
  const lbl=trg.querySelector('.lmui-lbl');
  const sync=()=>{ const o=sel.options[sel.selectedIndex]; lbl.textContent=o?o.textContent:'— Pilih —'; trg.classList.toggle('ph', !sel.value); };
  sel._lmSync=sync; sel.addEventListener('change',sync);
  trg.onclick=()=>{
    if(_popAnchor===trg){ _closePop(); return; }
    _closePop();
    _pop=document.createElement('div'); _pop.className='lmui-pop';
    _pop.innerHTML=Array.from(sel.options).map((o,i)=>`<button type="button" class="lmui-opt${i===sel.selectedIndex?' sel':''}" data-i="${i}">${esc(o.textContent)}</button>`).join('');
    _pop.onclick=e=>{ const b=e.target.closest('.lmui-opt'); if(!b) return; sel.selectedIndex=+b.dataset.i; sel.dispatchEvent(new Event('change')); _closePop(); };
    document.body.appendChild(_pop); _popAnchor=trg; _placePop(trg);
    document.addEventListener('mousedown',_onOutside,true);
    window.addEventListener('scroll',_closePop,true); window.addEventListener('resize',_closePop);
  };
  sel.after(trg); sync();
}
function lmSyncSel(...ids){ ids.forEach(id=>{ const el=document.getElementById(id); if(el&&el._lmSync) el._lmSync(); }); }
document.querySelectorAll('select.lm-cust').forEach(_initSel);
</script>
</body>
</html>

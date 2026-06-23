<?php
// ── Mode routing (HQ vs Outlet) — single URL /dashboard.php ──
if (session_status() === PHP_SESSION_NONE) session_start();

// Switch mode via ?to=hq atau ?to=outlet
if (isset($_GET['to'])) {
    $_SESSION['hq_mode'] = ($_GET['to'] === 'hq');
    header('Location: /dashboard');
    exit;
}

// Kalau hq_mode aktif & user boleh akses HQ → render HQ view (F1 RBAC v2)
require_once __DIR__ . '/core/TenantResolver.php';
if (!empty($_SESSION['hq_mode']) && TenantResolver::canAccessHqV2()) {
    require __DIR__ . '/hq/dashboard.php';
    exit;
}
// Kalau bukan owner/manager tapi hq_mode tertinggal di session → reset
$_SESSION['hq_mode'] = false;

$activePage = 'dashboard';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once __DIR__ . '/components.php';

$user  = currentUser();
$today = date('Y-m-d');
$tid   = TenantResolver::id();

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /login?msg=logout');
    exit;
}

// ── Early: tangani AJAX saat belum ada outlet ─────────
$hasOutlet = TenantResolver::hasOutlet();

// ── POST handlers (no-outlet state) — HARUS sebelum output HTML ──
$pwError = '';

if (!$hasOutlet && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    $ownerWa  = preg_replace('/\D/', '', $_POST['owner_wa'] ?? '');
    if (substr($ownerWa, 0, 2) === '08') $ownerWa = '628' . substr($ownerWa, 2);
    if (substr($ownerWa, 0, 1) === '8')  $ownerWa = '62' . $ownerWa;
    $namaPerusahaan = trim(strip_tags($_POST['nama_perusahaan'] ?? ''));
    $kota           = trim(strip_tags($_POST['kota']            ?? ''));

    try {
        $db = Database::get();
        $db->prepare("UPDATE tenants SET nama_perusahaan=?, owner_wa=?, kota=? WHERE id=?")
           ->execute([$namaPerusahaan ?: null, $ownerWa ?: null, $kota ?: null, $tid]);
        TenantResolver::reset();
        header('Location: /dashboard?profile_saved=1');
        exit;
    } catch (Throwable $e) {
        error_log('[dashboard save_profile] ' . $e->getMessage());
        header('Location: /dashboard?profile_error=' . urlencode($e->getMessage()));
        exit;
    }
}

if (!$hasOutlet && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $db        = Database::get();
    $currentPw = $_POST['current_password'] ?? '';
    $newPw     = $_POST['new_password']     ?? '';
    $confPw    = $_POST['confirm_password'] ?? '';

    $row = $db->prepare("SELECT password FROM hl_users WHERE id=?");
    $row->execute([$user['id']]);
    $stored = $row->fetchColumn();

    if (!password_verify($currentPw, $stored)) {
        $pwError = 'Password lama tidak sesuai.';
    } elseif (strlen($newPw) < 8) {
        $pwError = 'Password baru minimal 8 karakter.';
    } elseif ($newPw !== $confPw) {
        $pwError = 'Konfirmasi password tidak cocok.';
    } else {
        $hash = password_hash($newPw, PASSWORD_BCRYPT, ['cost' => 11]);
        $db->prepare("UPDATE hl_users SET password=? WHERE id=?")->execute([$hash, $user['id']]);
        $db->prepare("UPDATE tenants SET password_hash=? WHERE id=?")->execute([$hash, $tid]);
        header('Location: /dashboard?pw_changed=1');
        exit;
    }
}

// ── AJAX ACTIONS ──────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');

    // Jika belum ada outlet, kembalikan data kosong agar JS tidak error
    if (!$hasOutlet) {
        if ($action === 'stats') {
            echo json_encode(['order'=>['total_order'=>0,'omset'=>0,'terkumpul'=>0,'belum_lunas'=>0,'siap_diambil'=>0],'kas'=>['masuk'=>0,'keluar'=>0],'aktif'=>0,'hadir'=>0,'saldo'=>0,'is_staff'=>false,'role'=>$user['role']]);
        } elseif ($action === 'alerts') {
            echo json_encode(['siap'=>[],'mepet'=>[],'piutang'=>[]]);
        } elseif ($action === 'pipeline') {
            echo json_encode([]);
        } elseif ($action === 'chart7') {
            echo json_encode([]);
        } else {
            echo json_encode(['error'=>'Belum ada outlet.']);
        }
        exit;
    }

    $oid = TenantResolver::outletId();

    // ── STATS HARIAN ─────────────────────────────────
    if ($action === 'stats') {
        $isStaff = ($user['role'] === 'staff');

        if ($isStaff) {
            // Staff (operator) memproses order, bukan membuatnya — tampilkan order
            // yang dia buat ATAU yang dia tangani (handled_by), bukan cuma created_by.
            $orderData = TenantQuery::rawOne(
                "SELECT COUNT(*) as total_order,
                        COALESCE(SUM(total),0) as omset,
                        COALESCE(SUM(dp),0) as terkumpul,
                        SUM(CASE WHEN status_bayar != 'lunas' THEN 1 ELSE 0 END) as belum_lunas,
                        SUM(CASE WHEN status_proses = 'siap' THEN 1 ELSE 0 END) as siap_diambil
                 FROM hl_transaksi
                 WHERE tenant_id = ? AND outlet_id = ? AND DATE(tanggal) = ?
                   AND (created_by = ? OR handled_by = ?)",
                [$tid, $oid, $today, $user['id'], $user['id']]
            );
        } else {
            $orderData = TenantQuery::rawOne(
                "SELECT COUNT(*) as total_order,
                        COALESCE(SUM(total),0) as omset,
                        COALESCE(SUM(dp),0) as terkumpul,
                        SUM(CASE WHEN status_bayar != 'lunas' THEN 1 ELSE 0 END) as belum_lunas,
                        SUM(CASE WHEN status_proses = 'siap' THEN 1 ELSE 0 END) as siap_diambil
                 FROM hl_transaksi
                 WHERE tenant_id = ? AND outlet_id = ? AND DATE(tanggal) = ?",
                [$tid, $oid, $today]
            );
        }

        $kasData = ['masuk' => 0, 'keluar' => 0];
        if (!$isStaff) {
            $kasData = TenantQuery::rawOne(
                "SELECT COALESCE(SUM(CASE WHEN tipe='masuk' THEN jumlah END),0) as masuk,
                        COALESCE(SUM(CASE WHEN tipe='keluar' THEN jumlah END),0) as keluar
                 FROM hl_kas WHERE tenant_id = ? AND outlet_id = ? AND tanggal = ?",
                [$tid, $oid, $today]
            ) ?: ['masuk' => 0, 'keluar' => 0];
        }

        $aktif = TenantQuery::count('hl_transaksi', "status_proses != 'diambil'");

        if ($isStaff) {
            $absensi = TenantQuery::rawOne(
                "SELECT jam_masuk FROM hl_absensi WHERE tenant_id = ? AND outlet_id = ? AND user_id = ? AND tanggal = ?",
                [$tid, $oid, $user['id'], $today]
            );
            $hadir = ($absensi && $absensi['jam_masuk']) ? 1 : 0;
        } else {
            $hadir = TenantQuery::count('hl_absensi', "tanggal = ? AND status = 'hadir'", [$today]);
        }

        // Target omset (defensif kalau kolom belum ada)
        $targetHarian = 0; $targetBulanan = 0;
        try {
            $tg = TenantQuery::rawOne(
                "SELECT target_omset_harian, target_omset_bulanan FROM outlets WHERE id=? AND tenant_id=?",
                [$oid, $tid]
            );
            if ($tg) { $targetHarian = (int)$tg['target_omset_harian']; $targetBulanan = (int)$tg['target_omset_bulanan']; }
        } catch (Throwable) {}

        // Omset bulan ini (untuk progress bulanan)
        $omsetBulan = 0;
        try {
            $om = TenantQuery::rawOne(
                "SELECT COALESCE(SUM(total),0) s FROM hl_transaksi
                  WHERE tenant_id=? AND outlet_id=? AND DATE_FORMAT(tanggal,'%Y-%m')=DATE_FORMAT(?, '%Y-%m')",
                [$tid, $oid, $today]
            );
            $omsetBulan = (int)($om['s'] ?? 0);
        } catch (Throwable) {}

        echo json_encode([
            'order'    => $orderData,
            'kas'      => $kasData,
            'aktif'    => $aktif,
            'hadir'    => $hadir,
            'saldo'    => floatval($kasData['masuk']) - floatval($kasData['keluar']),
            'is_staff' => $isStaff,
            'role'     => $user['role'],
            'target'   => [
                'harian'      => $targetHarian,
                'bulanan'     => $targetBulanan,
                'omset_bulan' => $omsetBulan,
                'hari_sisa'   => max(0, (int)date('t') - (int)date('j')),
            ],
        ]);
        exit;
    }

    // ── QUICK SEARCH (HP/nama/no order) ──
    if ($action === 'quick_search') {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 3) { echo json_encode(['ok'=>true,'rows'=>[]]); exit; }
        try {
            $like = '%' . $q . '%';
            $db = Database::get();
            $stmt = $db->prepare("
                SELECT t.id, t.no_order, t.nama_pelanggan, t.telepon,
                       t.total, t.status_proses, t.status_bayar,
                       t.tanggal, t.estimasi_selesai, t.created_at
                  FROM hl_transaksi t
                 WHERE t.tenant_id=? AND t.outlet_id=?
                   AND (t.no_order LIKE ? OR t.nama_pelanggan LIKE ? OR t.telepon LIKE ?)
                 ORDER BY t.status_proses='diambil' ASC, t.id DESC
                 LIMIT 10
            ");
            $stmt->execute([$tid, $oid, $like, $like, $like]);
            echo json_encode(['ok'=>true, 'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    // ── EXTRAS (segmen breakdown, top 5 pelanggan, week vs week) ──
    if ($action === 'extras') {
        $thisWeekStart = date('Y-m-d', strtotime('monday this week'));
        $todayStr      = date('Y-m-d');
        $lastWeekStart = date('Y-m-d', strtotime('monday this week -7 days'));
        $lastWeekEnd   = date('Y-m-d', strtotime('sunday this week -7 days'));

        // 1. Breakdown segmen hari ini
        $segmen = [];
        try {
            $s = $db->prepare("
                SELECT COALESCE(l.segmen, CASE WHEN t.drop_point_id IS NOT NULL THEN 'drop_point' ELSE 'lainnya' END) seg,
                       COALESCE(SUM(ti.subtotal),0) total
                  FROM hl_transaksi t
                  LEFT JOIN hl_transaksi_item ti ON ti.transaksi_id=t.id
                  LEFT JOIN hl_layanan l ON l.id=ti.layanan_id AND l.tenant_id=ti.tenant_id
                 WHERE t.tenant_id=? AND t.outlet_id=? AND DATE(t.tanggal)=?
                 GROUP BY seg ORDER BY total DESC
            ");
            $s->execute([$tid, $oid, $todayStr]);
            $segmen = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {}

        // 2. Top 5 pelanggan bulan ini
        $topCust = [];
        try {
            $monthStart = date('Y-m-01');
            $s = $db->prepare("
                SELECT p.nama, p.telepon, COUNT(t.id) ord, COALESCE(SUM(t.total),0) spend
                  FROM hl_transaksi t
                  JOIN hl_pelanggan p ON p.id=t.pelanggan_id AND p.tenant_id=t.tenant_id
                 WHERE t.tenant_id=? AND t.outlet_id=? AND DATE(t.tanggal) BETWEEN ? AND ?
                 GROUP BY p.id, p.nama, p.telepon
                 ORDER BY spend DESC LIMIT 5
            ");
            $s->execute([$tid, $oid, $monthStart, $todayStr]);
            $topCust = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {}

        // 3. Week vs week (omset & order)
        $wow = ['this_omset'=>0,'this_order'=>0,'last_omset'=>0,'last_order'=>0];
        try {
            $s = $db->prepare("SELECT COUNT(*) c, COALESCE(SUM(total),0) o FROM hl_transaksi
                                WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal) BETWEEN ? AND ?");
            $s->execute([$tid,$oid,$thisWeekStart,$todayStr]); $a = $s->fetch(PDO::FETCH_ASSOC);
            $wow['this_omset'] = (int)$a['o']; $wow['this_order'] = (int)$a['c'];
            $s->execute([$tid,$oid,$lastWeekStart,$lastWeekEnd]); $b = $s->fetch(PDO::FETCH_ASSOC);
            $wow['last_omset'] = (int)$b['o']; $wow['last_order'] = (int)$b['c'];
        } catch (Throwable) {}

        echo json_encode(['ok'=>true, 'segmen'=>$segmen, 'top_pelanggan'=>$topCust, 'wow'=>$wow]);
        exit;
    }

    // ── ALERTS ───────────────────────────────────────
    if ($action === 'alerts') {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        $siap = TenantQuery::raw(
            "SELECT no_order, nama_pelanggan, telepon, estimasi_selesai,
                    total, sisa_bayar, status_bayar, updated_at
             FROM hl_transaksi
             WHERE tenant_id = ? AND outlet_id = ? AND status_proses = 'siap'
             ORDER BY updated_at DESC LIMIT 20",
            [$tid, $oid]
        );

        $mepet = TenantQuery::raw(
            "SELECT no_order, nama_pelanggan, telepon, estimasi_selesai,
                    total, sisa_bayar, status_bayar, status_proses
             FROM hl_transaksi
             WHERE tenant_id = ? AND outlet_id = ? AND estimasi_selesai <= ?
               AND status_proses NOT IN ('siap','diambil')
             ORDER BY estimasi_selesai ASC LIMIT 20",
            [$tid, $oid, $tomorrow]
        );

        $piutang = TenantQuery::raw(
            "SELECT t.no_order, t.nama_pelanggan, t.telepon, t.tanggal,
                    t.total, t.sisa_bayar, t.status_proses,
                    DATEDIFF(CURDATE(), t.tanggal) as hari_lalu
             FROM hl_transaksi t
             LEFT JOIN hl_pelanggan p ON p.id = t.pelanggan_id AND p.tenant_id = t.tenant_id
                                      AND p.tenant_id = t.tenant_id
                                      AND p.outlet_id = t.outlet_id
             WHERE t.tenant_id = ? AND t.outlet_id = ?
               AND t.status_bayar != 'lunas'
               AND t.tanggal <= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
               AND t.status_proses != 'diambil'
               AND (p.metode_bayar IS NULL OR p.metode_bayar = 'langsung')
             ORDER BY t.tanggal ASC LIMIT 20",
            [$tid, $oid]
        );

        // Mitra drop point yang inaktif >7 hari (tidak ada order)
        $mitraInaktif = [];
        try {
            $mitraInaktif = TenantQuery::raw(
                "SELECT dp.id, dp.nama_mitra, dp.wa,
                        (SELECT MAX(created_at) FROM hl_transaksi
                          WHERE tenant_id=dp.tenant_id AND drop_point_id=dp.id) AS last_order
                   FROM hl_drop_points dp
                  WHERE dp.tenant_id=? AND dp.outlet_id=? AND dp.status='aktif'
                    AND NOT EXISTS (
                       SELECT 1 FROM hl_transaksi t
                        WHERE t.tenant_id=dp.tenant_id AND t.drop_point_id=dp.id
                          AND t.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    )
                  ORDER BY dp.nama_mitra",
                [$tid, $oid]
            );
        } catch (Throwable) {}

        // Mesin self-service — sesi running yang sudah lewat estimasi (perlu attention)
        $mesinAttention = [];
        try {
            $mesinAttention = TenantQuery::raw(
                "SELECT s.id AS sesi_id, s.pelanggan_nama, s.pelanggan_telepon,
                        m.nama AS mesin_nama, m.kode AS mesin_kode, s.estimated_done_at,
                        TIMESTAMPDIFF(MINUTE, s.estimated_done_at, NOW()) AS lewat_menit
                 FROM hl_mesin_sesi s
                 JOIN hl_mesin m ON m.id = s.mesin_id AND m.tenant_id = s.tenant_id
                 WHERE s.tenant_id = ? AND s.outlet_id = ?
                   AND s.status = 'running'
                   AND s.estimated_done_at IS NOT NULL
                   AND s.estimated_done_at < NOW()
                 ORDER BY s.estimated_done_at ASC LIMIT 10",
                [$tid, $oid]
            );
        } catch (Throwable) {}

        // Inventori bahan baku — stok kritis (habis / minim)
        $inventoriKritis = [];
        try {
            $inventoriKritis = TenantQuery::raw(
                "SELECT id, nama, kategori, satuan, stok_terkini, stok_minimum, status_stok
                 FROM hl_bahan_stok
                 WHERE tenant_id = ? AND outlet_id = ? AND is_active = 1
                   AND stok_terkini <= stok_minimum
                 ORDER BY (stok_terkini <= 0) DESC, nama ASC
                 LIMIT 10",
                [$tid, $oid]
            );
        } catch (Throwable) {}

        echo json_encode([
            'siap'           => $siap,
            'mepet'          => $mepet,
            'piutang'        => $piutang,
            'mitra_inaktif'  => $mitraInaktif,
            'inventori_kritis' => $inventoriKritis,
            'mesin_attention'  => $mesinAttention,
        ]);
        exit;
    }

    // ── PIPELINE ─────────────────────────────────────
    if ($action === 'pipeline') {
        $rows = TenantQuery::raw(
            "SELECT status_proses, COUNT(*) as count
             FROM hl_transaksi
             WHERE tenant_id = ? AND outlet_id = ? AND status_proses != 'diambil'
             GROUP BY status_proses",
            [$tid, $oid]
        );
        $map = [];
        foreach ($rows as $r) $map[$r['status_proses']] = $r['count'];
        echo json_encode($map);
        exit;
    }

    // ── CHART 7 HARI ─────────────────────────────────
    if ($action === 'chart7') {
        $rows = TenantQuery::raw(
            "SELECT DATE(tanggal) as tgl,
                    COALESCE(SUM(total),0) as omset,
                    COUNT(*) as order_count
             FROM hl_transaksi
             WHERE tenant_id = ? AND outlet_id = ? AND tanggal >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
             GROUP BY DATE(tanggal) ORDER BY tgl",
            [$tid, $oid]
        );
        echo json_encode($rows);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Dashboard'); ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
.dash-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; margin-bottom: 20px; }
.dash-card {
  background: var(--white); border-radius: var(--r-lg);
  border: 1px solid rgba(27,45,90,.07); box-shadow: var(--shadow);
  padding: 20px; position: relative; overflow: hidden;
}
.dash-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
.dash-card.teal::before   { background: linear-gradient(90deg,var(--teal),var(--teal-d)); }
.dash-card.green::before  { background: linear-gradient(90deg,var(--green),#34D399); }
.dash-card.red::before    { background: linear-gradient(90deg,var(--red),#F87171); }
.dash-card.navy::before   { background: linear-gradient(90deg,var(--navy),#2D4A8A); }
.dash-card.purple::before { background: linear-gradient(90deg,var(--purple),#A78BFA); }
.dash-num   { font-size: 1.6rem; font-weight: 900; color: var(--navy); font-family: var(--mono); line-height: 1; margin-bottom: 4px; }
.dash-label { font-size: 12px; color: var(--gray); font-weight: 500; }
.dash-sub   { font-size: 11px; color: var(--gray); margin-top: 6px; }
.pipeline   { display: flex; gap: 8px; }
.pipe-item  {
  flex: 1; background: var(--white); border-radius: var(--r);
  padding: 12px 14px; border: 1px solid rgba(27,45,90,.07);
  box-shadow: var(--shadow); text-align: center;
}
.pipe-num   { font-size: 1.4rem; font-weight: 800; color: var(--navy); font-family: var(--mono); }
.pipe-label { font-size: 11px; color: var(--gray); margin-top: 3px; }
.pipe-item.active { border-color: var(--teal); background: var(--teal-bg); }
.pipe-item.active .pipe-num { color: var(--teal-d); }
.alert-title  { font-size: 13px; font-weight: 700; color: var(--navy); display: flex; align-items: center; gap: 8px; }
.alert-badge  { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 100px; }
.alert-row {
  display: flex; align-items: center; justify-content: space-between;
  padding: 10px 14px; background: var(--white);
  border-radius: var(--r); border: 1px solid rgba(27,45,90,.07);
  margin-bottom: 6px; gap: 12px;
}
.alert-no   { font-family: var(--mono); font-size: 12px; font-weight: 700; color: var(--teal-d); white-space: nowrap; }
.alert-nama { font-size: 14px; font-weight: 600; color: var(--navy); }
.alert-meta { font-size: 12px; color: var(--gray); }
.alert-wa   { padding: 5px 10px; background: #25D366; color: white; border: none; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap; text-decoration: none; }
.chart-wrap { position: relative; height: 200px; }

@media(max-width:900px) {
  .dash-grid { grid-template-columns: repeat(2,1fr); }
  .pipeline  { flex-wrap: wrap; }
  .pipe-item { flex: 1 1 calc(33% - 8px); min-width: 80px; }
}
@media(max-width:680px) {
  .dash-grid { grid-template-columns: repeat(2,1fr); gap: 10px; }
  .dash-card { padding: 12px; }
  .dash-num  { font-size: 1.1rem; }
  .dash-sub  { font-size: 10px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .pipeline  { overflow-x: auto; flex-wrap: nowrap; padding-bottom: 4px; }
  .pipe-item { flex: 0 0 auto; min-width: 62px; padding: 9px 7px; }
  .alert-row { flex-wrap: wrap; gap: 6px; padding: 9px 10px; }
  .alert-row > div:first-child { flex: 1 1 100%; }
  .alert-row > div:last-child  { width: 100%; display: flex; justify-content: flex-end; gap: 6px; }
  .chart-wrap { height: 150px; }
}
</style>
</head>
<body>
<?php renderTopbar('dashboard', !$hasOutlet); ?>

<?php if (!$hasOutlet):
// ════════════════════════════════════════════════════════
// NO-OUTLET STATE — onboarding dashboard
// ════════════════════════════════════════════════════════
$tenant = currentTenant();
$ownerNama  = $user['nama'] ?? 'Owner';
$tenantPerusahaan = $tenant['nama_perusahaan'] ?? '';
$tenantWa         = $tenant['owner_wa']        ?? '';
$tenantKota       = $tenant['kota']            ?? '';
$tenantEmail      = $tenant['email']           ?? $user['email'] ?? '';

// Cek onboarding progress
$profileDone  = !empty($tenantWa);
$profileSaved = isset($_GET['profile_saved']);
$pwChanged    = isset($_GET['pw_changed']);
$pwError      = $pwError ?? '';
?>
<style>
/* ── Onboarding dashboard (light theme matching app shell) ── */
.ob-wrap {
  background: #F4F7FB;
  min-height: calc(100vh - 60px);
  padding: 32px 16px 100px;
}
.ob-inner { max-width: 880px; margin: 0 auto; display: flex; flex-direction: column; gap: 20px; }

.ob-alert {
  padding: 12px 16px; border-radius: 10px; font-size: 13.5px;
  border: 1px solid; display: flex; align-items: center; gap: 8px;
}
.ob-alert-ok { background: #D1FAE5; border-color: #6EE7B7; color: #065F46; }
.ob-alert-err { background: #FEE2E2; border-color: #FCA5A5; color: #991B1B; }

/* Hero CTA — dark intentional contrast */
.ob-hero {
  background: linear-gradient(135deg, #0F1C3A 0%, #1a2d52 100%);
  border-radius: 18px; padding: 40px 32px; text-align: center;
  position: relative; overflow: hidden;
  box-shadow: 0 6px 24px rgba(15,28,58,.15);
}
.ob-hero::before {
  content: ''; position: absolute; top:-100px; right:-80px;
  width: 280px; height: 280px;
  background: radial-gradient(circle, rgba(53,232,213,.2), transparent 70%);
  pointer-events: none;
}
.ob-hero-badge {
  display: inline-block; background: rgba(53,232,213,.15);
  border: 1px solid rgba(53,232,213,.3);
  color: #35E8D5; font-size: 12px; font-weight: 700;
  padding: 5px 14px; border-radius: 100px;
  margin-bottom: 16px; letter-spacing: .05em; position: relative;
}
.ob-hero h1 {
  font-size: clamp(1.5rem, 4vw, 2.2rem); font-weight: 800;
  color: #fff; margin: 0 0 12px; line-height: 1.2; position: relative;
}
.ob-hero p {
  font-size: 15px; color: rgba(255,255,255,.75);
  max-width: 520px; margin: 0 auto 28px; line-height: 1.65; position: relative;
}
.ob-cta {
  display: inline-block; background: #35E8D5; color: #0F1C3A;
  font-weight: 800; font-size: 16px; padding: 14px 36px;
  border-radius: 12px; text-decoration: none;
  box-shadow: 0 8px 24px rgba(53,232,213,.3);
  transition: all .2s; position: relative;
}
.ob-cta:hover { transform: translateY(-2px); box-shadow: 0 12px 32px rgba(53,232,213,.45); }
.ob-cta-sub { margin-top: 14px; font-size: 12px; color: rgba(255,255,255,.5); position: relative; }

.ob-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }

/* Cards — light/white */
.ob-card {
  background: #fff;
  border: 1px solid #E5E7EB;
  border-radius: 14px; padding: 24px;
  box-shadow: 0 1px 8px rgba(0,0,0,.04);
}
.ob-card h3 {
  font-size: 15px; font-weight: 700; color: #0F1C3A;
  margin: 0 0 18px; display: flex; align-items: center; gap: 10px;
  letter-spacing: -.01em;
}
.ob-card h3 .icon {
  background: #F0FDFB; padding: 5px 9px; border-radius: 8px;
  font-size: 14px;
}

.ob-field { margin-bottom: 14px; }
.ob-label {
  font-size: 11.5px; font-weight: 600; color: #6B7280;
  display: block; margin-bottom: 5px; letter-spacing: .04em; text-transform: uppercase;
}
.ob-label .hint { font-weight: 400; color: #9CA3AF; text-transform: none; letter-spacing: 0; }
.ob-input {
  width: 100%; padding: 10px 12px;
  background: #fff;
  border: 1.5px solid #E5E7EB;
  border-radius: 9px;
  font-size: 14px; color: #0F1C3A; font-family: inherit;
  box-sizing: border-box; outline: none;
  transition: border-color .15s;
}
.ob-input:focus { border-color: #35E8D5; }
.ob-input::placeholder { color: #9CA3AF; }
.ob-input:disabled { background: #F9FAFB; color: #9CA3AF; cursor: not-allowed; }

.ob-btn-primary {
  width: 100%; background: #35E8D5; color: #0F1C3A;
  border: none; padding: 11px; border-radius: 9px;
  font-weight: 700; font-size: 14px; cursor: pointer; font-family: inherit;
  transition: opacity .15s;
}
.ob-btn-primary:hover { opacity: .9; }
.ob-btn-secondary {
  width: 100%; background: #fff;
  border: 1.5px solid #E5E7EB; color: #374151;
  padding: 9px; border-radius: 9px;
  font-size: 13px; cursor: pointer; font-family: inherit;
}
.ob-btn-secondary:hover { background: #F9FAFB; border-color: #D1D5DB; }

.ob-pw-section { margin-top: 16px; border-top: 1px solid #F3F4F6; padding-top: 16px; }

.ob-progress { margin-bottom: 16px; }
.ob-progress-head {
  display: flex; justify-content: space-between;
  font-size: 12px; color: #6B7280; margin-bottom: 6px;
}
.ob-progress-val { color: #0891B2; font-weight: 700; }
.ob-progress-track {
  background: #F3F4F6; border-radius: 100px;
  height: 8px; overflow: hidden;
}
.ob-progress-fill {
  background: linear-gradient(90deg, #35E8D5, #0891B2);
  height: 100%; border-radius: 100px;
  transition: width .5s ease;
}

.ob-step {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 12px; border-radius: 9px; margin-bottom: 7px;
  font-size: 13.5px; border: 1px solid;
}
.ob-step-done {
  background: #F0FDF4;
  border-color: #6EE7B7;
  color: #065F46;
}
.ob-step-active {
  background: #fff;
  border-color: #E5E7EB;
  color: #0F1C3A;
}
.ob-step-locked {
  background: #F9FAFB;
  border-color: #E5E7EB;
  color: #9CA3AF;
}
.ob-step .check { font-size: 14px; flex-shrink: 0; }
.ob-step .body { flex: 1; }
.ob-step .sub { font-size: 11px; color: #9CA3AF; display: block; margin-top: 2px; }
.ob-step-btn {
  background: #35E8D5; color: #0F1C3A; font-size: 11px; font-weight: 700;
  padding: 5px 12px; border-radius: 6px; text-decoration: none; white-space: nowrap;
}
.ob-step-btn:hover { opacity: .9; }

.ob-tour-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
.ob-tour-card {
  background: #F8FAFC;
  border: 1.5px solid #E5E7EB;
  border-radius: 12px; padding: 18px 14px; text-align: center;
  transition: all .2s;
}
.ob-tour-card:hover {
  background: #fff;
  border-color: #35E8D5;
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(53,232,213,.15);
}
.ob-tour-icon { font-size: 28px; margin-bottom: 10px; display: block; }
.ob-tour-title { font-size: 13px; font-weight: 700; color: #0F1C3A; margin-bottom: 5px; }
.ob-tour-desc { font-size: 11.5px; color: #6B7280; line-height: 1.5; }
.ob-tour-cta { text-align: center; margin-top: 16px; }
.ob-tour-cta a { font-size: 13px; color: #0891B2; font-weight: 600; text-decoration: none; }
.ob-tour-cta a:hover { text-decoration: underline; }

.ob-faq-item { border-bottom: 1px solid #F3F4F6; }
.ob-faq-item:last-child { border-bottom: none; }
.ob-faq-btn {
  width: 100%; text-align: left; background: none; border: none;
  padding: 14px 0; font-size: 14px; font-weight: 600; color: #0F1C3A;
  cursor: pointer; font-family: inherit;
  display: flex; justify-content: space-between; align-items: center; gap: 8px;
}
.ob-faq-arrow { font-size: 12px; color: #9CA3AF; flex-shrink: 0; transition: transform .2s; }
.ob-faq-ans {
  max-height: 0; overflow: hidden; transition: max-height .3s ease;
  font-size: 13px; color: #4B5563; line-height: 1.7;
}
.ob-faq-ans-inner { padding: 0 0 14px; }
.ob-faq-ans strong { color: #0F1C3A; font-weight: 700; }

.ob-footer-wa {
  margin-top: 18px; text-align: center; font-size: 13px;
  color: #6B7280;
}
.ob-footer-wa a { color: #0891B2; font-weight: 700; text-decoration: none; }

.ob-fab {
  position: fixed; bottom: 24px; right: 24px;
  background: #25D366; color: #fff;
  border-radius: 100px; padding: 12px 18px 12px 14px;
  font-size: 14px; font-weight: 700; text-decoration: none;
  box-shadow: 0 6px 20px rgba(37,211,102,.4);
  display: flex; align-items: center; gap: 8px;
  z-index: 999; transition: transform .2s;
}
.ob-fab:hover { transform: scale(1.05); }

@media (max-width: 640px) {
  .ob-grid { grid-template-columns: 1fr; }
  .ob-tour-grid { grid-template-columns: repeat(2, 1fr); }
  .ob-hero { padding: 28px 22px; }
  .ob-card { padding: 20px; }
}
</style>

<div class="ob-wrap">
<div class="ob-inner">

<?php if ($profileSaved): ?>
<div class="ob-alert ob-alert-ok">✅ Profil berhasil diperbarui.</div>
<?php endif; ?>
<?php if (!empty($_GET['profile_error'])): ?>
<div class="ob-alert ob-alert-err">❌ Gagal menyimpan profil: <?= htmlspecialchars($_GET['profile_error']) ?></div>
<?php endif; ?>
<?php if ($pwChanged): ?>
<div class="ob-alert ob-alert-ok">✅ Password berhasil diubah.</div>
<?php endif; ?>

<!-- ① HERO CTA ──────────────────────────────────────── -->
<div class="ob-hero">
  <div class="ob-hero-badge">🎁 TRIAL 7 HARI GRATIS · 1.000 COIN</div>
  <h1>Selamat datang, <?= htmlspecialchars($ownerNama) ?>! 👋</h1>
  <p>Akun LAMASY kamu sudah aktif. Daftarkan outlet pertama untuk mulai mengelola laundry dengan AI — gratis 7 hari, tanpa kartu kredit.</p>
  <a href="/add-outlet" class="ob-cta">🏪 Daftarkan Outlet — Gratis 7 Hari</a>
  <div class="ob-cta-sub">⏱ Cuma butuh 3 menit · Tidak perlu kartu kredit</div>
</div>

<!-- ② PROFIL + CHECKLIST ────────────────────────────── -->
<div class="ob-grid">

  <!-- PROFIL AKUN -->
  <div class="ob-card">
    <h3><span class="icon">👤</span> Profil Akun</h3>
    <form method="POST">
      <input type="hidden" name="save_profile" value="1">
      <div class="ob-field">
        <label class="ob-label">Nama Perusahaan / Brand <span class="hint">(opsional)</span></label>
        <input type="text" name="nama_perusahaan" class="ob-input"
               value="<?= htmlspecialchars($tenantPerusahaan) ?>"
               placeholder="cth: PT Bersih Jaya Group">
      </div>
      <div class="ob-field">
        <label class="ob-label">Email <span class="hint">(tidak bisa diubah)</span></label>
        <input type="email" class="ob-input" value="<?= htmlspecialchars($tenantEmail) ?>" disabled>
      </div>
      <div class="ob-field">
        <label class="ob-label">Nomor WhatsApp</label>
        <input type="tel" name="owner_wa" class="ob-input"
               value="<?= htmlspecialchars(preg_replace('/^628/', '08', $tenantWa)) ?>"
               placeholder="08xxxxxxxxxx">
      </div>
      <div class="ob-field" style="margin-bottom:16px">
        <label class="ob-label">Kota</label>
        <input type="text" name="kota" class="ob-input"
               value="<?= htmlspecialchars($tenantKota) ?>" placeholder="cth: Surabaya">
      </div>
      <button type="submit" class="ob-btn-primary">💾 Simpan Profil</button>
    </form>

    <!-- Password change toggle -->
    <div class="ob-pw-section">
      <button type="button" class="ob-btn-secondary"
              onclick="var f=document.getElementById('pwForm');f.style.display=f.style.display==='none'?'block':'none'">
        🔑 Ubah Password
      </button>
      <div id="pwForm" style="display:<?= $pwError ? 'block' : 'none' ?>;margin-top:12px">
        <?php if ($pwError): ?>
        <div class="ob-alert ob-alert-err" style="margin-bottom:10px;font-size:12.5px;padding:8px 12px"><?= htmlspecialchars($pwError) ?></div>
        <?php endif; ?>
        <form method="POST">
          <input type="hidden" name="change_password" value="1">
          <input type="password" name="current_password" class="ob-input" placeholder="Password lama" style="margin-bottom:8px">
          <input type="password" name="new_password" class="ob-input" placeholder="Password baru (min 8 karakter)" style="margin-bottom:8px">
          <input type="password" name="confirm_password" class="ob-input" placeholder="Ulangi password baru" style="margin-bottom:10px">
          <button type="submit" class="ob-btn-primary" style="background:rgba(53,232,213,.2);color:#35E8D5">
            Simpan Password Baru
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- ONBOARDING CHECKLIST -->
  <div class="ob-card">
    <h3><span class="icon">✅</span> Setup Checklist</h3>
    <?php
    $steps = [
      ['done'=>true,        'locked'=>false, 'label'=>'Verifikasi email',           'link'=>null,                        'icon'=>'📧'],
      ['done'=>$profileDone,'locked'=>false, 'label'=>'Lengkapi profil perusahaan', 'link'=>null,                        'icon'=>'👤'],
      ['done'=>false,       'locked'=>false, 'label'=>'Daftarkan outlet pertama',   'link'=>'/add-outlet.php', 'icon'=>'🏪'],
      ['done'=>false,       'locked'=>true,  'label'=>'Setup layanan & harga',      'link'=>null,                        'icon'=>'🧺'],
      ['done'=>false,       'locked'=>true,  'label'=>'Tambah karyawan pertama',    'link'=>null,                        'icon'=>'👥'],
      ['done'=>false,       'locked'=>true,  'label'=>'Buat order pertama',         'link'=>null,                        'icon'=>'🛒'],
    ];
    $doneCnt = count(array_filter($steps, fn($s) => $s['done']));
    $total   = count($steps);
    $pct     = round($doneCnt / $total * 100);
    ?>
    <div class="ob-progress">
      <div class="ob-progress-head">
        <span><?= $doneCnt ?>/<?= $total ?> selesai</span>
        <span class="ob-progress-val"><?= $pct ?>%</span>
      </div>
      <div class="ob-progress-track">
        <div class="ob-progress-fill" style="width:<?= $pct ?>%"></div>
      </div>
    </div>
    <?php foreach ($steps as $i => $s):
      $cls = $s['done'] ? 'ob-step-done' : ($s['locked'] ? 'ob-step-locked' : 'ob-step-active');
      $check = $s['done'] ? '✅' : ($s['locked'] ? '🔒' : '⭕');
    ?>
    <div class="ob-step <?= $cls ?>">
      <span class="check"><?= $check ?></span>
      <span class="body">
        <?= $s['icon'] ?> <?= htmlspecialchars($s['label']) ?>
        <?php if ($s['locked']): ?>
          <span class="sub">Tersedia setelah outlet didaftarkan</span>
        <?php endif; ?>
      </span>
      <?php if (!$s['done'] && !$s['locked'] && $s['link']): ?>
      <a href="<?= $s['link'] ?>" class="ob-step-btn"><?= $i === 2 ? 'Mulai →' : 'Buka →' ?></a>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div><!-- /ob-grid -->

<!-- ③ FEATURE TOUR ──────────────────────────────────── -->
<div class="ob-card">
  <h3 style="margin-bottom:6px">🖥️ Ini yang bisa kamu lakukan dengan LAMASY</h3>
  <p style="font-size:13px;color:rgba(255,255,255,.5);margin:0 0 18px">Daftar outlet untuk akses penuh ke semua fitur</p>
  <div class="ob-tour-grid">
    <?php
    $features = [
      ['icon'=>'🛒','title'=>'POS & Order',        'desc'=>'Input order, cetak nota, kelola status cucian'],
      ['icon'=>'📊','title'=>'Dashboard & Laporan','desc'=>'Pantau omset, saldo kas, dan kinerja harian'],
      ['icon'=>'🤖','title'=>'AI Briefing',        'desc'=>'Laporan performa outlet otomatis setiap hari'],
      ['icon'=>'💬','title'=>'WhatsApp Otomatis',  'desc'=>'Notif pelanggan saat cucian siap, otomatis'],
    ];
    foreach ($features as $f): ?>
    <div class="ob-tour-card">
      <span class="ob-tour-icon"><?= $f['icon'] ?></span>
      <div class="ob-tour-title"><?= htmlspecialchars($f['title']) ?></div>
      <div class="ob-tour-desc"><?= htmlspecialchars($f['desc']) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="ob-tour-cta"><a href="/add-outlet">Daftar outlet untuk akses penuh →</a></div>
</div>

<!-- ④ FAQ ──────────────────────────────────────────── -->
<div class="ob-card">
  <h3>❓ Pertanyaan Umum</h3>
  <?php
  $faqs = [
    ['q'=>'Apa bedanya "akun" dan "outlet" di LAMASY?',
     'a'=>'<strong>Akun</strong> = identitas perusahaan kamu (gratis selamanya, tidak kadaluarsa). <strong>Outlet</strong> = toko/cabang operasional yang punya trial 7 hari. 1 akun bisa punya banyak outlet.'],
    ['q'=>'Berapa biaya setelah trial 7 hari habis?',
     'a'=>'Setup fee Rp 300rb–500rb untuk aktivasi outlet. Setelah itu pakai sistem coin: topup mulai Rp 50rb, bayar per fitur yang dipakai saja.'],
    ['q'=>'Saya punya 3 cabang, harus bayar 3x?',
     'a'=>'Ya, setiap outlet bayar setup fee terpisah. Tapi 1 akun bisa kelola semua cabang dari 1 dashboard — hemat waktu dan lebih mudah dipantau.'],
    ['q'=>'Apakah data saya aman?',
     'a'=>'Data tersimpan di server aman dengan multi-tenant isolated architecture + HTTPS + bcrypt password. Setelah trial habis, data tetap ada 7 hari grace + 30 hari recovery.'],
    ['q'=>'Butuh install aplikasi?',
     'a'=>'Tidak perlu. LAMASY berjalan di browser — buka di HP atau laptop. Bisa juga di-install sebagai PWA via Add to Home Screen.'],
  ];
  foreach ($faqs as $i => $faq): ?>
  <div class="ob-faq-item">
    <button class="ob-faq-btn" onclick="toggleFaqNo(<?= $i ?>)">
      <span><?= htmlspecialchars($faq['q']) ?></span>
      <span class="ob-faq-arrow" id="faqArrowNo<?= $i ?>">▼</span>
    </button>
    <div class="ob-faq-ans" id="faqAnsNo<?= $i ?>">
      <p class="ob-faq-ans-inner"><?= $faq['a'] ?></p>
    </div>
  </div>
  <?php endforeach; ?>
  <div class="ob-footer-wa">
    Masih ada pertanyaan?
    <a href="https://wa.me/6285121519302?text=Halo+Tim+LAMASY%2C+saya+baru+daftar+dan+ingin+setup+akun+pertama+saya.+Bisa+minta+bantuan%3F" target="_blank" rel="noopener">Chat WhatsApp Kami →</a>
  </div>
</div>

</div><!-- /ob-inner -->
</div><!-- /ob-wrap -->

<!-- FLOATING WA BUTTON -->
<a href="https://wa.me/6285121519302?text=Halo+Tim+LAMASY%2C+saya+baru+daftar+dan+ingin+setup+akun+pertama+saya.+Bisa+minta+bantuan%3F"
   target="_blank" rel="noopener" class="ob-fab">
  <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
  </svg>
  Butuh bantuan?
</a>
<script>
function toggleFaqNo(i){
  var a=document.getElementById('faqAnsNo'+i);
  var arr=document.getElementById('faqArrowNo'+i);
  var isOpen=a.style.maxHeight&&a.style.maxHeight!=='0px';
  a.style.maxHeight=isOpen?'0px':a.scrollHeight+'px';
  arr.textContent=isOpen?'▼':'▲';
}
// Mark tutorial step done via localStorage
if(localStorage.getItem('lamasy_tutorial_done')){
  // Could update UI here if needed
}
</script>

<?php else: ?>
<!-- ════════════════════════════════════════════════════
     NORMAL DASHBOARD (outlet exists)
════════════════════════════════════════════════════ -->
<div class="hl-main" style="max-width:1400px;width:100%">

<?php
// ── Status banners (trial / grace) ────────────────────
$banners = TenantResolver::getBannerInfo();
foreach ($banners as $b):
    $bg = $b['type'] === 'warning'
        ? 'linear-gradient(90deg,#FEF3C7,#FDE68A)'
        : 'linear-gradient(90deg,#DBEAFE,#BFDBFE)';
    $border = $b['type'] === 'warning' ? '#F59E0B' : '#3B82F6';
    $color  = $b['type'] === 'warning' ? '#92400E' : '#1E40AF';
?>
<div style="background:<?= $bg ?>;border-left:4px solid <?= $border ?>;
            color:<?= $color ?>;padding:10px 16px;border-radius:8px;
            font-size:13px;margin-bottom:14px;line-height:1.5">
    <?= $b['message'] ?>
</div>
<?php endforeach; ?>

<?php
// ── Promo/Feature Banner Carousel (Smartlink-inspired) ──
require_once ROOT . '/core/BannerLoader.php';
echo BannerLoader::renderCarousel(TenantResolver::id());
?>

<?php
// ══════════════════════════════════════════════════════
// Dashboard variant per role (brief Akses Karyawan Section 6.4)
// owner/manager/admin/superadmin → full dashboard (existing)
// kasir/staff/kurir → dashboard ringkas (task-focused)
// ══════════════════════════════════════════════════════
$_dashRole = $user['role'] ?? '';
$_isRingkas = in_array($_dashRole, ['kasir','staff','kurir'], true);

if ($_isRingkas):
    $oid = TenantResolver::outletId();
    $uname = htmlspecialchars($user['nama'] ?? 'Karyawan');
    $greetTime = (date('H') < 11 ? 'pagi' : (date('H') < 15 ? 'siang' : (date('H') < 19 ? 'sore' : 'malam')));
    $outletNm = htmlspecialchars(TenantResolver::namaOutlet());

    // Cek absensi hari ini
    $absStmt = TenantQuery::rawOne(
        "SELECT id, jam_masuk, jam_keluar, status FROM hl_absensi
          WHERE tenant_id=? AND outlet_id=? AND user_id=? AND tanggal=? LIMIT 1",
        [$tid, $oid, $user['id'], $today]
    );
    $clockedIn  = $absStmt && !empty($absStmt['jam_masuk']) && empty($absStmt['jam_keluar']);
    $clockedOut = $absStmt && !empty($absStmt['jam_keluar']);
?>

<!-- HERO RINGKAS -->
<div style="background:linear-gradient(135deg,#0F1C3A,#1a2d52);color:#fff;border-radius:14px;
            padding:22px 26px;margin-bottom:20px;display:flex;justify-content:space-between;
            align-items:center;flex-wrap:wrap;gap:14px">
  <div>
    <h2 style="font-size:1.2rem;font-weight:800;margin-bottom:3px">Selamat <?= $greetTime ?>, <?= $uname ?>!</h2>
    <div style="font-size:13px;color:rgba(255,255,255,.55)">
      📍 <?= $outletNm ?> · <?= date('d M Y') ?>
    </div>
  </div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <?php if ($clockedOut): ?>
      <div style="background:rgba(52,211,153,.15);border:1px solid rgba(52,211,153,.3);
                  color:#34D399;font-size:12px;font-weight:700;padding:6px 14px;border-radius:100px">
        ✓ Sudah Clock Out
      </div>
    <?php elseif ($clockedIn): ?>
      <div style="background:rgba(52,211,153,.15);border:1px solid rgba(52,211,153,.3);
                  color:#34D399;font-size:12px;font-weight:700;padding:6px 14px;border-radius:100px">
        🟢 Clocked In · <?= substr($absStmt['jam_masuk'], 0, 5) ?>
      </div>
      <a href="/absensi" class="hl-btn hl-btn-outline hl-btn-sm" style="color:#fff;border-color:rgba(255,255,255,.3)">Clock Out</a>
    <?php else: ?>
      <a href="/absensi" class="hl-btn"
         style="background:#35E8D5;color:#0F1C3A;font-weight:700;padding:8px 18px;
                border-radius:8px;text-decoration:none;font-size:13px">
        🕐 Clock In Sekarang
      </a>
    <?php endif; ?>
  </div>
</div>

<?php // ── DASHBOARD KASIR ──────────────────────────────
if ($_dashRole === 'kasir'):
    // Stats kasir: transaksi yang dia proses hari ini
    $kasirStats = TenantQuery::rawOne(
        "SELECT COUNT(*) AS total, COALESCE(SUM(total),0) AS omset
           FROM hl_transaksi
          WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal)=? AND created_by=?",
        [$tid, $oid, $today, $user['id']]
    ) ?: ['total'=>0,'omset'=>0];

    // Order masuk hari ini (semua kasir di outlet ini)
    $orderMasuk = (int)(TenantQuery::rawOne(
        "SELECT COUNT(*) AS c FROM hl_transaksi
          WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal)=?",
        [$tid, $oid, $today]
    )['c'] ?? 0);

    // Order siap diambil
    $orderSiap = (int)(TenantQuery::rawOne(
        "SELECT COUNT(*) AS c FROM hl_transaksi
          WHERE tenant_id=? AND outlet_id=? AND status_proses='siap'",
        [$tid, $oid]
    )['c'] ?? 0);
?>
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px" class="rk-grid3">
  <div style="background:#fff;border-radius:12px;padding:18px;box-shadow:0 1px 6px rgba(0,0,0,.05);border-top:3px solid #35E8D5">
    <div style="font-size:1.4rem;font-weight:800;color:#0F1C3A;font-family:var(--mono)"><?= (int)$kasirStats['total'] ?></div>
    <div style="font-size:12px;color:#6B7280;font-weight:600">Transaksi Saya Hari Ini</div>
    <div style="font-size:11px;color:#9CA3AF;margin-top:3px">Rp <?= number_format((int)$kasirStats['omset'], 0, ',', '.') ?></div>
  </div>
  <div style="background:#fff;border-radius:12px;padding:18px;box-shadow:0 1px 6px rgba(0,0,0,.05);border-top:3px solid #3B82F6">
    <div style="font-size:1.4rem;font-weight:800;color:#0F1C3A;font-family:var(--mono)"><?= $orderMasuk ?></div>
    <div style="font-size:12px;color:#6B7280;font-weight:600">Order Masuk Hari Ini</div>
    <div style="font-size:11px;color:#9CA3AF;margin-top:3px">Semua kasir outlet</div>
  </div>
  <div style="background:#fff;border-radius:12px;padding:18px;box-shadow:0 1px 6px rgba(0,0,0,.05);border-top:3px solid #F59E0B">
    <div style="font-size:1.4rem;font-weight:800;color:#0F1C3A;font-family:var(--mono)"><?= $orderSiap ?></div>
    <div style="font-size:12px;color:#6B7280;font-weight:600">Siap Diambil</div>
    <div style="font-size:11px;color:#9CA3AF;margin-top:3px">Perlu notif pelanggan</div>
  </div>
</div>

<!-- Quick actions kasir -->
<div style="background:#fff;border-radius:12px;padding:22px;box-shadow:0 1px 6px rgba(0,0,0,.05);margin-bottom:20px">
  <h3 style="font-size:14px;font-weight:700;color:#0F1C3A;margin-bottom:14px">⚡ Aksi Cepat</h3>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px">
    <a href="/pos" style="background:#35E8D5;color:#0F1C3A;font-weight:800;font-size:15px;
       padding:18px;border-radius:10px;text-decoration:none;text-align:center">
      🛒 Buat Order Baru
    </a>
    <a href="/orders?status=aktif" style="background:#0F1C3A;color:#fff;font-weight:700;font-size:14px;
       padding:18px;border-radius:10px;text-decoration:none;text-align:center">
      📋 Lihat Antrian Order
    </a>
    <a href="/orders?status=siap" style="background:#F59E0B;color:#fff;font-weight:700;font-size:14px;
       padding:18px;border-radius:10px;text-decoration:none;text-align:center">
      ✅ Order Siap Diambil
    </a>
    <a href="/customer" style="background:rgba(53,232,213,.1);color:#0F1C3A;font-weight:700;font-size:14px;
       padding:18px;border-radius:10px;text-decoration:none;text-align:center;border:1.5px solid rgba(53,232,213,.3)">
      👥 Cari Pelanggan
    </a>
  </div>
</div>

<?php elseif ($_dashRole === 'staff'):
    // ── DASHBOARD STAFF (produksi) ────────────────────
    $perluKerja = (int)(TenantQuery::rawOne(
        "SELECT COUNT(*) AS c FROM hl_transaksi
          WHERE tenant_id=? AND outlet_id=? AND status_proses IN ('cuci','kering','setrika')",
        [$tid, $oid]
    )['c'] ?? 0);
    $selesaiHariIni = (int)(TenantQuery::rawOne(
        "SELECT COUNT(*) AS c FROM hl_transaksi
          WHERE tenant_id=? AND outlet_id=? AND status_proses='siap' AND DATE(tanggal)=?",
        [$tid, $oid, $today]
    )['c'] ?? 0);
?>
<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-bottom:20px" class="rk-grid2">
  <div style="background:#fff;border-radius:12px;padding:18px;box-shadow:0 1px 6px rgba(0,0,0,.05);border-top:3px solid #F59E0B">
    <div style="font-size:1.5rem;font-weight:800;color:#0F1C3A;font-family:var(--mono)"><?= $perluKerja ?></div>
    <div style="font-size:12px;color:#6B7280;font-weight:600">Perlu Dikerjakan</div>
    <div style="font-size:11px;color:#9CA3AF;margin-top:3px">Status: cuci / kering / setrika</div>
  </div>
  <div style="background:#fff;border-radius:12px;padding:18px;box-shadow:0 1px 6px rgba(0,0,0,.05);border-top:3px solid #34D399">
    <div style="font-size:1.5rem;font-weight:800;color:#0F1C3A;font-family:var(--mono)"><?= $selesaiHariIni ?></div>
    <div style="font-size:12px;color:#6B7280;font-weight:600">Selesai Hari Ini</div>
    <div style="font-size:11px;color:#9CA3AF;margin-top:3px">Status: siap</div>
  </div>
</div>

<div style="background:#fff;border-radius:12px;padding:22px;box-shadow:0 1px 6px rgba(0,0,0,.05);margin-bottom:20px">
  <h3 style="font-size:14px;font-weight:700;color:#0F1C3A;margin-bottom:14px">📋 Order Yang Perlu Dikerjakan</h3>
  <?php
  $orderList = TenantQuery::raw(
    "SELECT id, no_order, nama_pelanggan, status_proses, tanggal, estimasi_selesai
       FROM hl_transaksi
      WHERE tenant_id=? AND outlet_id=? AND status_proses IN ('cuci','kering','setrika')
      ORDER BY estimasi_selesai ASC, tanggal ASC LIMIT 10",
    [$tid, $oid]
  );
  ?>
  <?php if (empty($orderList)): ?>
  <div style="text-align:center;padding:30px;color:#9CA3AF;font-size:13px">Tidak ada order yang perlu dikerjakan saat ini ✓</div>
  <?php else: foreach ($orderList as $o): ?>
  <div style="display:flex;justify-content:space-between;align-items:center;padding:11px 0;
              border-bottom:1px solid #F3F4F6;font-size:13px">
    <div>
      <div style="font-weight:700;color:#0F1C3A"><?= htmlspecialchars($o['no_order']) ?>
        — <?= htmlspecialchars($o['nama_pelanggan'] ?? '-') ?></div>
      <div style="font-size:11px;color:#9CA3AF">Estimasi: <?= $o['estimasi_selesai'] ? date('d M H:i', strtotime($o['estimasi_selesai'])) : '-' ?></div>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
      <span style="background:#FEF3C7;color:#92400E;font-size:10px;font-weight:700;padding:3px 10px;border-radius:100px;text-transform:uppercase">
        <?= $o['status_proses'] ?>
      </span>
      <a href="/orders?id=<?= $o['id'] ?>" style="color:#0891B2;text-decoration:none;font-size:12px;font-weight:700">Update →</a>
    </div>
  </div>
  <?php endforeach; endif; ?>
</div>

<?php elseif ($_dashRole === 'kurir'):
    // ── DASHBOARD KURIR (delivery) ────────────────────
    $siapAntar = (int)(TenantQuery::rawOne(
        "SELECT COUNT(*) AS c FROM hl_transaksi
          WHERE tenant_id=? AND outlet_id=? AND status_proses='siap'",
        [$tid, $oid]
    )['c'] ?? 0);
    $sudahAntar = (int)(TenantQuery::rawOne(
        "SELECT COUNT(*) AS c FROM hl_transaksi
          WHERE tenant_id=? AND outlet_id=? AND status_proses='selesai' AND DATE(tanggal)=?",
        [$tid, $oid, $today]
    )['c'] ?? 0);
?>
<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-bottom:20px" class="rk-grid2">
  <div style="background:#fff;border-radius:12px;padding:18px;box-shadow:0 1px 6px rgba(0,0,0,.05);border-top:3px solid #F59E0B">
    <div style="font-size:1.5rem;font-weight:800;color:#0F1C3A;font-family:var(--mono)"><?= $siapAntar ?></div>
    <div style="font-size:12px;color:#6B7280;font-weight:600">Siap Antar Hari Ini</div>
  </div>
  <div style="background:#fff;border-radius:12px;padding:18px;box-shadow:0 1px 6px rgba(0,0,0,.05);border-top:3px solid #34D399">
    <div style="font-size:1.5rem;font-weight:800;color:#0F1C3A;font-family:var(--mono)"><?= $sudahAntar ?></div>
    <div style="font-size:12px;color:#6B7280;font-weight:600">Sudah Diantar Hari Ini</div>
  </div>
</div>

<div style="background:#fff;border-radius:12px;padding:22px;box-shadow:0 1px 6px rgba(0,0,0,.05);margin-bottom:20px">
  <h3 style="font-size:14px;font-weight:700;color:#0F1C3A;margin-bottom:14px">🚚 Daftar Antar Hari Ini</h3>
  <?php
  $antarList = TenantQuery::raw(
    "SELECT t.id, t.no_order, t.nama_pelanggan, t.telepon, t.status_proses,
            p.alamat AS alamat_pelanggan
       FROM hl_transaksi t
       LEFT JOIN hl_pelanggan p ON p.id = t.pelanggan_id AND p.tenant_id = t.tenant_id
      WHERE t.tenant_id=? AND t.outlet_id=? AND t.status_proses='siap'
      ORDER BY t.tanggal ASC LIMIT 10",
    [$tid, $oid]
  );
  ?>
  <?php if (empty($antarList)): ?>
  <div style="text-align:center;padding:30px;color:#9CA3AF;font-size:13px">Belum ada order yang perlu diantar ✓</div>
  <?php else: foreach ($antarList as $o): ?>
  <div style="padding:13px 0;border-bottom:1px solid #F3F4F6;font-size:13px">
    <div style="display:flex;justify-content:space-between;align-items:start;gap:10px;margin-bottom:5px">
      <div style="font-weight:700;color:#0F1C3A">
        <?= htmlspecialchars($o['no_order']) ?> — <?= htmlspecialchars($o['nama_pelanggan'] ?? '-') ?>
      </div>
      <a href="/orders?id=<?= $o['id'] ?>" style="color:#0891B2;text-decoration:none;font-size:12px;font-weight:700">Update Status →</a>
    </div>
    <?php if (!empty($o['alamat_pelanggan'])): ?>
    <div style="font-size:12px;color:#6B7280">📍 <?= htmlspecialchars($o['alamat_pelanggan']) ?></div>
    <?php endif; ?>
    <?php if (!empty($o['telepon'])): ?>
    <div style="font-size:12px;color:#6B7280">📞
      <a href="tel:<?= htmlspecialchars($o['telepon']) ?>" style="color:#0891B2;text-decoration:none"><?= htmlspecialchars($o['telepon']) ?></a>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; endif; ?>
</div>

<?php endif; // role-specific ringkas ?>

<style>
@media(max-width:640px){
  .rk-grid3{grid-template-columns:1fr!important}
  .rk-grid2{grid-template-columns:1fr!important}
}
</style>

</div><!-- /hl-main untuk ringkas -->
<?php renderToast(); ?>
</body>
</html>
<?php exit; endif; // _isRingkas — full dashboard di bawah hanya untuk owner/manager ?>

  <!-- ══ FULL DASHBOARD (owner/manager/admin/superadmin) ══ -->

  <!-- ── Announcement Banners ─────────────────────── -->
  <?php
  try {
    $annDb = Database::get();
    $annTid = TenantResolver::id();
    $annOutletStatus = TenantResolver::outletStatus() ?? 'active';
    $annBannerSt = $annDb->prepare(
      "SELECT a.* FROM saas_announcements a
       LEFT JOIN saas_announcement_reads ar
         ON ar.announcement_id = a.id AND ar.tenant_id = ?
       WHERE a.status = 'published'
         AND a.show_as_banner = 1
         AND (a.expires_at IS NULL OR a.expires_at > NOW())
         AND (a.target_audience = 'semua' OR a.target_audience = ?)
         AND ar.announcement_id IS NULL
       ORDER BY a.is_pinned DESC, a.published_at DESC
       LIMIT 3"
    );
    $annBannerSt->execute([$annTid, $annOutletStatus]);
    $annBanners = $annBannerSt->fetchAll(PDO::FETCH_ASSOC);
    $annBannerColors = [
      'blue'  => ['bg'=>'#EFF6FF','color'=>'#1D4ED8','border'=>'#BFDBFE'],
      'green' => ['bg'=>'#F0FDF4','color'=>'#166534','border'=>'#BBF7D0'],
      'amber' => ['bg'=>'#FFFBEB','color'=>'#92400E','border'=>'#FDE68A'],
      'red'   => ['bg'=>'#FEF2F2','color'=>'#991B1B','border'=>'#FECACA'],
    ];
    $annTypeIcon = ['fitur_baru'=>'✨','maintenance'=>'🔧','penting'=>'⚠️','promo'=>'🎁','umum'=>'🔔'];
    foreach ($annBanners as $banner):
      $bc = $annBannerColors[$banner['banner_color']] ?? $annBannerColors['blue'];
  ?>
  <div id="annBanner<?= $banner['id'] ?>"
       style="background:<?= $bc['bg'] ?>;border:1px solid <?= $bc['border'] ?>;border-radius:10px;
              padding:11px 16px;margin-bottom:10px;display:flex;align-items:center;gap:10px;
              color:<?= $bc['color'] ?>;font-size:13.5px;font-weight:600;">
    <span style="font-size:16px;flex-shrink:0;"><?= $annTypeIcon[$banner['type']] ?? '📢' ?></span>
    <span style="flex:1;"><?= htmlspecialchars($banner['title']) ?></span>
    <button type="button"
            onclick="dismissBanner(<?= $banner['id'] ?>, this.closest('div'))"
            style="background:none;border:none;font-size:18px;color:<?= $bc['color'] ?>;
                   opacity:.5;cursor:pointer;padding:0 2px;line-height:1;flex-shrink:0;">✕</button>
  </div>
  <?php endforeach;
  } catch (Throwable) {} ?>
  <script>
  function dismissBanner(annId, el) {
    el.style.display = 'none';
    fetch('/support.php?action=dismiss_banner', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
      body: '_csrf=' + encodeURIComponent(csrfToken()) + '&announcement_id=' + annId,
    });
  }
  </script>

  <!-- GREETING -->
  <div style="margin-bottom:20px;display:flex;align-items:flex-start;justify-content:space-between;gap:10px;flex-wrap:wrap">
    <div>
      <h1 style="font-size:1.3rem;font-weight:800;color:var(--navy)" id="greeting">Selamat pagi!</h1>
      <p style="font-size:13px;color:var(--gray)" id="dashDate">--</p>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <?php if ($user['role'] !== 'staff'): ?>
      <div id="aiBriefingBadge"
           style="background:linear-gradient(135deg,#667eea,#764ba2);color:white;font-size:12px;font-weight:600;padding:6px 14px;border-radius:100px;cursor:pointer"
           onclick="toggleBriefing()">✨ AI Briefing</div>
      <?php endif; ?>
      <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadAll()">↻ Refresh</button>
    </div>
  </div>

  <!-- HANDOVER PENDING BANNER -->
  <div id="hoBanner" style="display:none;margin-bottom:14px"></div>

  <!-- QUICK SEARCH BAR -->
  <div style="position:relative;margin-bottom:20px">
    <input type="text" id="qSearch" placeholder="🔍 Cari cepat: nomor HP, nama pelanggan, atau no. order…"
           autocomplete="off"
           style="width:100%;padding:13px 16px;font-size:14px;border:1.5px solid #E5E9F2;border-radius:12px;
                  background:#fff;font-family:inherit;outline:none;transition:border .15s"
           onfocus="this.style.borderColor='#35E8D5'"
           onblur="this.style.borderColor='#E5E9F2'">
    <div id="qSearchRes" style="position:absolute;top:calc(100% + 4px);left:0;right:0;background:#fff;border:1px solid #E5E9F2;
                                border-radius:10px;box-shadow:0 8px 24px rgba(15,28,58,.15);max-height:420px;overflow-y:auto;
                                z-index:50;display:none"></div>
  </div>

  <!-- AI BRIEFING PANEL -->
  <div id="aiBriefingPanel" style="display:none;margin-bottom:20px">
    <div class="hl-card" style="border:2px solid rgba(139,92,246,.2);background:linear-gradient(135deg,#FAFAFA,#F5F3FF)">
      <div class="hl-card-header" style="border-bottom:1px solid rgba(139,92,246,.1)">
        <div class="hl-card-title" style="display:flex;align-items:center;gap:8px">
          <span style="background:linear-gradient(135deg,#667eea,#764ba2);color:white;font-size:10px;font-weight:800;padding:3px 8px;border-radius:100px;letter-spacing:.06em">AI</span>
          Briefing Harian
        </div>
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadBriefing()" id="btnBriefingRefresh">↻</button>
      </div>
      <div class="hl-card-body" id="aiBriefingContent">
        <div class="hl-loading">⏳ AI sedang menganalisis data...</div>
      </div>
    </div>
  </div>

  <!-- TARGET OMSET -->
  <div id="targetWrap" style="display:none;margin-bottom:14px">
    <div style="background:#fff;border:1px solid rgba(27,45,90,.07);border-radius:var(--r-lg);padding:14px 16px;box-shadow:var(--shadow)">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px" id="targetGrid">
        <div id="targetHarianBox">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px">
            <span style="font-size:11px;font-weight:800;color:#6B7280;letter-spacing:.06em">🎯 TARGET HARI INI</span>
            <span id="targetHarianPct" style="font-size:13px;font-weight:800;color:#0F1C3A">0%</span>
          </div>
          <div style="font-size:13px;font-weight:700;color:#0F1C3A;margin-bottom:5px" id="targetHarianText">-</div>
          <div style="background:#EEF1F8;border-radius:100px;height:8px;overflow:hidden">
            <div id="targetHarianBar" style="height:100%;background:linear-gradient(90deg,#35E8D5,#10B981);width:0%;transition:width .4s"></div>
          </div>
          <div id="targetHarianSub" style="font-size:11px;color:#6B7280;margin-top:4px">-</div>
        </div>
        <div id="targetBulananBox">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px">
            <span style="font-size:11px;font-weight:800;color:#6B7280;letter-spacing:.06em">🎯 TARGET BULAN INI</span>
            <span id="targetBulananPct" style="font-size:13px;font-weight:800;color:#0F1C3A">0%</span>
          </div>
          <div style="font-size:13px;font-weight:700;color:#0F1C3A;margin-bottom:5px" id="targetBulananText">-</div>
          <div style="background:#EEF1F8;border-radius:100px;height:8px;overflow:hidden">
            <div id="targetBulananBar" style="height:100%;background:linear-gradient(90deg,#3B82F6,#8B5CF6);width:0%;transition:width .4s"></div>
          </div>
          <div id="targetBulananSub" style="font-size:11px;color:#6B7280;margin-top:4px">-</div>
        </div>
      </div>
    </div>
  </div>

  <!-- STAT CARDS -->
  <div class="dash-grid">
    <div class="dash-card teal">
      <div class="dash-num" id="dOmset">-</div>
      <div class="dash-label">Omset Hari Ini</div>
      <div class="dash-sub" id="dTerkumpul">Terkumpul: -</div>
    </div>
    <div class="dash-card green">
      <div class="dash-num" id="dOrder">-</div>
      <div class="dash-label">Order Masuk Hari Ini</div>
      <div class="dash-sub" id="dAktif">Aktif: - order</div>
    </div>
    <div class="dash-card navy" id="dashKasCard">
      <div class="dash-num" id="dSaldo">-</div>
      <div class="dash-label">Saldo Kas Hari Ini</div>
      <div class="dash-sub" id="dKasSub">Masuk: - / Keluar: -</div>
    </div>
    <div class="dash-card purple">
      <div class="dash-num" id="dHadir">-</div>
      <div class="dash-label">Karyawan Hadir</div>
      <div class="dash-sub" id="dSiap">Siap diambil: - order</div>
    </div>
  </div>

  <!-- EXTRAS: SEGMEN + TOP PELANGGAN + WOW -->
  <div id="extrasWrap" style="display:none;margin-bottom:20px">
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px" id="extrasGrid">
      <div class="hl-card" style="padding:14px 16px">
        <div style="font-size:12px;font-weight:700;color:#6B7280;letter-spacing:.05em;margin-bottom:8px">🧷 OMSET PER SEGMEN HARI INI</div>
        <div id="segmenBox" style="font-size:13px"></div>
      </div>
      <div class="hl-card" style="padding:14px 16px">
        <div style="font-size:12px;font-weight:700;color:#6B7280;letter-spacing:.05em;margin-bottom:8px">🏆 TOP 5 PELANGGAN BULAN INI</div>
        <div id="topCustBox" style="font-size:13px"></div>
      </div>
      <div class="hl-card" style="padding:14px 16px">
        <div style="font-size:12px;font-weight:700;color:#6B7280;letter-spacing:.05em;margin-bottom:8px">📊 MINGGU INI vs MINGGU LALU</div>
        <div id="wowBox" style="font-size:13px"></div>
      </div>
    </div>
  </div>

  <!-- PIPELINE -->
  <div class="hl-card" style="margin-bottom:20px">
    <div class="hl-card-header">
      <div class="hl-card-title">Pipeline Order Aktif</div>
      <span id="pipeTotal" style="font-size:12px;color:var(--gray)"></span>
    </div>
    <div class="hl-card-body" style="padding:14px">
      <div class="pipeline" id="pipeline"><div class="hl-loading">⏳</div></div>
    </div>
  </div>

  <div class="hl-grid-2" style="gap:20px">
    <div>
      <!-- SIAP DIAMBIL -->
      <div class="hl-card" style="margin-bottom:18px">
        <div class="hl-card-header">
          <div class="alert-title">Siap Diambil
            <span class="alert-badge" id="badgeSiap" style="background:#D1FAE5;color:#065F46">0</span>
          </div>
          <a href="/orders?status=siap" style="font-size:12px;color:var(--teal);text-decoration:none">Lihat semua</a>
        </div>
        <div class="hl-card-body" style="padding:12px" id="listSiap"><div class="hl-loading">⏳</div></div>
      </div>

      <!-- MEPET ESTIMASI -->
      <div class="hl-card">
        <div class="hl-card-header">
          <div class="alert-title">Harus Selesai Hari Ini / Besok
            <span class="alert-badge" id="badgeMepet" style="background:#FEF3C7;color:#92400E">0</span>
          </div>
        </div>
        <div class="hl-card-body" style="padding:12px" id="listMepet"><div class="hl-loading">⏳</div></div>
      </div>
    </div>

    <div>
      <!-- CHART 7 HARI -->
      <div class="hl-card" style="margin-bottom:18px">
        <div class="hl-card-header">
          <div class="hl-card-title">Omset 7 Hari Terakhir</div>
        </div>
        <div class="hl-card-body">
          <div class="chart-wrap"><canvas id="chartOmset"></canvas></div>
        </div>
      </div>

      <!-- PIUTANG -->
      <div class="hl-card">
        <div class="hl-card-header">
          <div class="alert-title">Belum Bayar (&gt; 3 Hari)
            <span class="alert-badge" id="badgePiutang" style="background:#FEE2E2;color:#991B1B">0</span>
          </div>
          <a href="/orders?bayar=belum" style="font-size:12px;color:var(--teal);text-decoration:none">Lihat semua</a>
        </div>
        <div class="hl-card-body" style="padding:12px" id="listPiutang"><div class="hl-loading">⏳</div></div>
      </div>
    </div>

    <!-- MITRA DROP POINT INAKTIF — full-width (grid-column 1/-1) -->
    <div id="mitraInaktifWrap" style="display:none;margin-top:16px;grid-column:1/-1">
      <div class="hl-card" style="border-left:4px solid #F59E0B">
        <div class="hl-card-header">
          <div class="alert-title">📦 Mitra Drop Point Tidak Aktif &gt;7 Hari</div>
          <a href="droppoint_manager.php" style="font-size:12px;color:var(--teal);text-decoration:none">Kelola mitra</a>
        </div>
        <div class="hl-card-body" style="padding:12px" id="mitraInaktifList"></div>
      </div>
    </div>

    <!-- MESIN ATTENTION — full-width -->
    <div id="mesinAttentionWrap" style="display:none;margin-top:16px;grid-column:1/-1">
      <div class="hl-card" style="border-left:4px solid #EF4444">
        <div class="hl-card-header">
          <div class="alert-title">🪙 Mesin Self-Service Selesai (Customer Perlu Diingatkan)
            <span class="alert-badge" id="badgeMesinAttention" style="background:#FEE2E2;color:#991B1B">0</span>
          </div>
          <a href="/mesin" style="font-size:12px;color:var(--teal);text-decoration:none">Buka mesin →</a>
        </div>
        <div class="hl-card-body" style="padding:12px" id="mesinAttentionList"></div>
      </div>
    </div>

    <!-- INVENTORI KRITIS — full-width friendly reminder -->
    <div id="inventoriKritisWrap" style="display:none;margin-top:16px;grid-column:1/-1">
      <div class="hl-card" style="border-left:4px solid #F59E0B;background:#FFFBEB">
        <div class="hl-card-header" style="background:transparent;border-bottom:1px dashed #FDE68A">
          <div class="alert-title" style="color:#78350F">
            📦 Reminder: ada <span id="badgeInventoriKritis" style="font-weight:800;color:#78350F">0</span> bahan baku stok-nya menipis
          </div>
          <button onclick="dismissInvWarn()" style="background:none;border:none;color:#92400E;font-size:11px;cursor:pointer;text-decoration:underline">Tutup hari ini</button>
        </div>
        <div class="hl-card-body" style="padding:12px 16px;color:#78350F;font-size:13px">
          <div style="margin-bottom:10px;font-size:12.5px;line-height:1.5">
            <strong>Tenant baru?</strong> Wajar kalau stok belum di-input. Kamu bisa <a href="/inventori" style="color:#0891B2;font-weight:700">setup inventori</a> kapan saja, atau <a href="#" onclick="event.preventDefault();dismissInvWarn()" style="color:#78350F">skip dulu</a> dan kembali nanti.
          </div>
          <div id="inventoriKritisList"></div>
        </div>
      </div>
    </div>
    <script>
    // Dismiss-for-today (localStorage flag, key per outlet+date)
    function dismissInvWarn(){
      var key='hl_inv_warn_dismiss_'+(<?= (int)($outletId ?? 0) ?>)+'_'+new Date().toISOString().slice(0,10);
      localStorage.setItem(key,'1');
      var w=document.getElementById('inventoriKritisWrap');if(w)w.style.display='none';
    }
    (function(){var k='hl_inv_warn_dismiss_'+(<?= (int)($outletId ?? 0) ?>)+'_'+new Date().toISOString().slice(0,10);if(localStorage.getItem(k)){var w=document.getElementById('inventoriKritisWrap');if(w){w.dataset.dismissed='1';}}})();
    </script>

</div><!-- /hl-main -->

<?php endif; // hasOutlet ?>

<?php renderToast(); ?>
<script>
let chartInstance = null;

function localDateStr(d){const dt=d||new Date();return dt.getFullYear()+'-'+String(dt.getMonth()+1).padStart(2,'0')+'-'+String(dt.getDate()).padStart(2,'0');}

document.addEventListener('DOMContentLoaded',()=>{
  const now=new Date(), h=now.getHours();
  const greet=h<11?'Selamat pagi':h<15?'Selamat siang':h<18?'Selamat sore':'Selamat malam';
  document.getElementById('greeting').textContent=greet+', <?= htmlspecialchars($user['nama']) ?>!';
  document.getElementById('dashDate').textContent=now.toLocaleDateString('id-ID',{weekday:'long',day:'numeric',month:'long',year:'numeric'});
  loadAll();
});

async function loadAll(){loadStats();loadAlerts();loadPipeline();loadChart();loadExtras();loadHandoverBanner();}

// ── HANDOVER BANNER (unacknowledged shifts) ──
async function loadHandoverBanner(){
  try {
    const r = await fetch('absensi.php?action=handover_pending');
    const d = await r.json();
    const box = document.getElementById('hoBanner');
    if (!box) return;
    if (!d.rows || !d.rows.length) { box.style.display='none'; return; }
    box.style.display = 'block';
    box.innerHTML = d.rows.map(h => `
      <div style="background:linear-gradient(90deg,#FEF3C7,#FFE4B5);border-left:4px solid #F59E0B;padding:10px 14px;border-radius:10px;display:flex;align-items:center;gap:10px;font-size:13px;margin-bottom:6px">
        <span style="font-size:18px">🤝</span>
        <div style="flex:1">
          <strong>Handover dari ${(h.nama_keluar||'-')}</strong>
          (${h.tanggal} · ${h.shift}) — Kas Rp ${parseInt(h.saldo_kas_akhir).toLocaleString('id-ID')},
          ${h.order_pending} pending, ${h.order_siap_ambil} siap.
          ${h.catatan_khusus ? `<div style="color:#92400E;font-size:12px;margin-top:2px"><em>“${h.catatan_khusus}”</em></div>` : ''}
        </div>
        <a href="/absensi" style="background:#F59E0B;color:#fff;padding:6px 12px;border-radius:8px;font-weight:600;text-decoration:none;font-size:12px">Buka Absensi →</a>
      </div>`).join('');
  } catch (e) {}
}

// ── QUICK SEARCH ──
const STATUS_PILL_BG = {
  masuk:'#DBEAFE/#1E40AF', cuci:'#FEF3C7/#92400E', kering:'#CFFAFE/#155E75',
  setrika:'#EDE9FE/#5B21B6', siap:'#D1FAE5/#065F46', diambil:'#F3F4F6/#6B7280'
};
function qsEsc(s){return String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}
function qsTimerLabel(estimasiSelesai, statusProses){
  if (statusProses === 'diambil' || statusProses === 'selesai') return '✓ Sudah diambil';
  if (!estimasiSelesai) return '-';
  const t = new Date(estimasiSelesai.replace(' ','T')).getTime();
  const diffMs = t - Date.now();
  if (diffMs < 0) {
    const lateH = Math.abs(diffMs)/3600000;
    return '⚠️ TERLAMBAT ' + (lateH<1 ? Math.round(lateH*60)+'m' : lateH.toFixed(1).replace('.0','')+'j');
  }
  const h = diffMs/3600000;
  return (h<1 ? Math.round(h*60)+'m' : h.toFixed(1).replace('.0','')+'j') + ' lagi';
}
let qsTimer = null;
const qsInput = document.getElementById('qSearch');
const qsRes   = document.getElementById('qSearchRes');
if (qsInput) {
  qsInput.addEventListener('input', () => {
    clearTimeout(qsTimer);
    const q = qsInput.value.trim();
    if (q.length < 3) { qsRes.style.display='none'; qsRes.innerHTML=''; return; }
    qsTimer = setTimeout(async () => {
      try {
        const r = await fetch('dashboard.php?action=quick_search&q=' + encodeURIComponent(q));
        const d = await r.json();
        if (d.error || !d.rows){ qsRes.style.display='none'; return; }
        if (!d.rows.length){
          qsRes.innerHTML = '<div style="padding:14px;color:#9CA3AF;font-size:13px;text-align:center">Tidak ada order yang cocok.</div>';
        } else {
          qsRes.innerHTML = d.rows.map(r => {
            const [bg,fg] = (STATUS_PILL_BG[r.status_proses] || '#F3F4F6/#6B7280').split('/');
            const timer = qsTimerLabel(r.estimasi_selesai, r.status_proses);
            const timerColor = timer.includes('TERLAMBAT') ? '#EF4444' : (r.status_proses==='diambil' ? '#10B981' : '#374151');
            return `<a href="/orders?q=${encodeURIComponent(r.no_order)}" style="display:block;padding:11px 14px;border-bottom:1px solid #F3F4F6;text-decoration:none;color:inherit">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
                <div style="min-width:0;flex:1">
                  <div style="font-weight:700;color:#0F1C3A;font-size:13px">${qsEsc(r.nama_pelanggan)} <small style="color:#9CA3AF;font-weight:400">· ${qsEsc(r.no_order)}</small></div>
                  <div style="font-size:11px;color:#6B7280;margin-top:2px">${qsEsc(r.telepon||'-')} · Rp ${Number(r.total).toLocaleString('id-ID')}</div>
                </div>
                <div style="text-align:right;white-space:nowrap">
                  <span style="background:${bg};color:${fg};font-size:10px;font-weight:700;padding:2px 8px;border-radius:100px;text-transform:uppercase">${qsEsc(r.status_proses)}</span>
                  <div style="font-size:11px;font-weight:600;color:${timerColor};margin-top:3px">⏱ ${timer}</div>
                </div>
              </div>
            </a>`;
          }).join('');
        }
        qsRes.style.display='block';
      } catch(e){ qsRes.style.display='none'; }
    }, 300);
  });
  document.addEventListener('click', e => {
    if (!e.target.closest('#qSearch') && !e.target.closest('#qSearchRes')) qsRes.style.display='none';
  });
}

const SEG_LBL = {kiloan:'🧺 Kiloan',self_service:'🪙 Self-Service',b2b:'🏢 B2B',satuan:'👕 Satuan',drop_point:'📦 Drop Point',lainnya:'📦 Lainnya'};
async function loadExtras(){
  try {
    const r = await fetch('dashboard.php?action=extras');
    const d = await r.json();
    if (d.error) return;
    document.getElementById('extrasWrap').style.display = 'block';
    const fmt = n => 'Rp '+Number(n||0).toLocaleString('id-ID');

    // Segmen
    const totSeg = (d.segmen||[]).reduce((s,r)=>s+Number(r.total),0) || 1;
    document.getElementById('segmenBox').innerHTML = (d.segmen||[]).length
      ? d.segmen.map(s => {
          const pct = Math.round(Number(s.total)/totSeg*100);
          return `<div style="margin-bottom:7px">
            <div style="display:flex;justify-content:space-between;font-size:12px">
              <span>${SEG_LBL[s.seg]||s.seg}</span>
              <span style="font-family:var(--mono);font-weight:700">${fmt(s.total)} <small style="color:#9CA3AF">${pct}%</small></span>
            </div>
            <div style="background:#EEF1F8;border-radius:100px;height:5px;margin-top:2px"><div style="background:#35E8D5;height:100%;width:${pct}%;border-radius:100px"></div></div>
          </div>`;
        }).join('')
      : '<div style="color:#9CA3AF">Belum ada transaksi hari ini</div>';

    // Top Pelanggan
    document.getElementById('topCustBox').innerHTML = (d.top_pelanggan||[]).length
      ? d.top_pelanggan.map((p,i) => {
          const medal = i===0?'🥇':i===1?'🥈':i===2?'🥉':`#${i+1}`;
          return `<div style="display:flex;justify-content:space-between;align-items:center;padding:4px 0;border-bottom:1px solid #F3F4F6">
            <div style="min-width:0;flex:1">
              <div style="font-weight:700;color:#0F1C3A;font-size:12px">${medal} ${p.nama||'-'}</div>
              <div style="font-size:10px;color:#9CA3AF">${p.ord||0} order</div>
            </div>
            <div style="font-family:var(--mono);font-weight:700;font-size:12px">${fmt(p.spend)}</div>
          </div>`;
        }).join('')
      : '<div style="color:#9CA3AF">Belum ada pelanggan terdaftar</div>';

    // WoW
    const w = d.wow || {};
    const oPct = w.last_omset > 0 ? Math.round((w.this_omset - w.last_omset)/w.last_omset*100) : (w.this_omset>0?100:0);
    const orPct= w.last_order > 0 ? Math.round((w.this_order - w.last_order)/w.last_order*100) : (w.this_order>0?100:0);
    const arrow = v => v>0?`<span style="color:#10B981">↑ +${v}%</span>` : (v<0?`<span style="color:#EF4444">↓ ${v}%</span>`:`<span style="color:#9CA3AF">→ 0%</span>`);
    document.getElementById('wowBox').innerHTML = `
      <div style="margin-bottom:8px">
        <div style="font-size:11px;color:#6B7280">Omset minggu ini</div>
        <div style="font-family:var(--mono);font-weight:800;color:#0F1C3A">${fmt(w.this_omset)}</div>
        <div style="font-size:11px">${arrow(oPct)} vs ${fmt(w.last_omset)} mgg lalu</div>
      </div>
      <div>
        <div style="font-size:11px;color:#6B7280">Order minggu ini</div>
        <div style="font-family:var(--mono);font-weight:800;color:#0F1C3A">${w.this_order} order</div>
        <div style="font-size:11px">${arrow(orPct)} vs ${w.last_order} mgg lalu</div>
      </div>`;
  } catch(e){}
}

// ── AI BRIEFING ───────────────────────────────────────
let briefingLoaded=false, briefingVisible=false;
function toggleBriefing(){
  briefingVisible=!briefingVisible;
  document.getElementById('aiBriefingPanel').style.display=briefingVisible?'block':'none';
  if(briefingVisible&&!briefingLoaded)loadBriefing();
}
async function loadBriefing(){
  const btn=document.getElementById('btnBriefingRefresh');

  // ── Confirm tier price untuk panggilan ke-2 ke atas ──
  try {
    const sr = await fetch('ai.php?action=briefing_status');
    const ss = await sr.json();
    if (ss.ok && ss.used > 0 && !ss.blocked && ss.next_price != null) {
      const remaining = ss.limit - ss.used;
      const msg = `Briefing ke-${ss.used + 1} dari ${ss.limit} hari ini.\n\n`
        + `💰 Biaya: ${ss.next_price} coin (sebelumnya: ${ss.current_price} coin)\n`
        + `📊 Sisa setelah ini: ${remaining - 1}× panggilan\n\n`
        + `Lanjutkan generate?`;
      if (!confirm(msg)) {
        if (btn) { btn.disabled = false; btn.textContent = '↻'; }
        return;
      }
    }
  } catch(e){ /* silent — kalau status check fail, lanjut aja */ }

  if(btn){btn.disabled=true;btn.textContent='⏳';}
  document.getElementById('aiBriefingContent').innerHTML='<div class="hl-loading">⏳ AI sedang menganalisis data hari ini...</div>';
  try{
    const r=await fetch('ai.php?action=briefing');
    const txt=await r.text();
    let d;
    try{d=JSON.parse(txt);}
    catch(parseErr){
      document.getElementById('aiBriefingContent').innerHTML=
        `<div style="background:#FEF3C7;border-left:4px solid #F59E0B;padding:12px 14px;border-radius:8px;font-size:13px;color:#92400E">
          <div style="font-weight:700;margin-bottom:6px">⚠️ AI Briefing gagal merespons</div>
          <div>Server mengembalikan format tidak valid (HTTP ${r.status}). Cek error_log atau coba lagi nanti.</div>
        </div>`;
      return;
    }
    if(d.error){
      // Friendly handler khusus rate_limited
      if(d.error === 'rate_limited'){
        document.getElementById('aiBriefingContent').innerHTML = `
          <div style="background:#FEF3C7;border:1px solid #FDE68A;border-left:4px solid #F59E0B;padding:14px 16px;border-radius:10px;font-size:13px;color:#78350F">
            <div style="font-weight:700;margin-bottom:6px;display:flex;align-items:center;gap:6px">⏰ Briefing harian sudah dipakai</div>
            <div style="line-height:1.6;margin-bottom:10px">${esc(d.message || 'Jatah AI Briefing harian sudah habis.')}</div>
            <div style="font-size:12px;color:#92400E">💡 Tetap mau coba? Topup coin untuk extra request, atau tunggu reset besok pagi.</div>
          </div>`;
        return;
      }
      // Generic error fallback
      document.getElementById('aiBriefingContent').innerHTML = `
        <div style="background:#FEE2E2;border:1px solid #FCA5A5;border-left:4px solid #EF4444;padding:14px 16px;border-radius:10px;font-size:13px;color:#7F1D1D">
          <div style="font-weight:700;margin-bottom:4px">❌ Gagal generate briefing</div>
          <div>${esc(d.message || d.error)}</div>
        </div>`;
      return;
    }
    const data=d.data;
    const cmap={baik:'var(--green)',waspada:'var(--yellow)',kritis:'var(--red)'};
    const imap={baik:'✅',waspada:'⚠️',kritis:'🚨'};
    document.getElementById('aiBriefingContent').innerHTML=`
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
        <span style="font-size:1.4rem">${imap[data.kondisi]||'📊'}</span>
        <div>
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:${cmap[data.kondisi]||'var(--gray)'}">${data.kondisi?.toUpperCase()}</div>
          <div style="font-size:14px;color:var(--dark);font-weight:500">${esc(data.ringkasan)}</div>
        </div>
      </div>
      ${data.poin_penting?.length?`<div style="margin-bottom:14px">${data.poin_penting.map(p=>`<div style="display:flex;gap:8px;align-items:flex-start;padding:7px 0;border-bottom:1px solid rgba(27,45,90,.06)"><span style="color:var(--teal);font-weight:700;flex-shrink:0">→</span><span style="font-size:13px;color:var(--dark)">${esc(p)}</span></div>`).join('')}</div>`:''}
      ${data.peluang?`<div style="background:linear-gradient(135deg,#D1FAE5,#A7F3D0);border-radius:var(--r);padding:10px 14px;font-size:13px;color:#065F46">💡 <strong>Peluang:</strong> ${esc(data.peluang)}</div>`:''}
      <div style="font-size:11px;color:var(--gray);text-align:right;margin-top:10px">AI · ${new Date().toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit'})}</div>`;
    briefingLoaded=true;
  }catch(e){document.getElementById('aiBriefingContent').innerHTML=`<div style="color:var(--red);font-size:13px">❌ ${e.message}</div>`;}
  finally{if(btn){btn.disabled=false;btn.textContent='↻';}}
}

// ── STATS ─────────────────────────────────────────────
async function loadStats(){
  const r=await fetch('dashboard.php?action=stats');
  const d=await r.json();
  const isStaff=d.is_staff;
  document.getElementById('dOmset').textContent='Rp '+parseFloat(d.order?.omset||0).toLocaleString('id-ID');
  document.getElementById('dTerkumpul').textContent=isStaff?'Order saya: '+(d.order?.total_order||0):'Terkumpul: Rp '+parseFloat(d.order?.terkumpul||0).toLocaleString('id-ID');
  document.getElementById('dOrder').textContent=(d.order?.total_order||0)+' order';
  document.getElementById('dAktif').textContent='Aktif: '+(d.aktif||0)+' order';
  if(isStaff){
    const kc=document.getElementById('dashKasCard');
    if(kc)kc.style.display='none';
  }else{
    const saldo=parseFloat(d.saldo||0);
    document.getElementById('dSaldo').textContent='Rp '+saldo.toLocaleString('id-ID');
    document.getElementById('dSaldo').style.color=saldo>=0?'var(--navy)':'var(--red)';
    document.getElementById('dKasSub').textContent='Masuk: Rp '+parseFloat(d.kas?.masuk||0).toLocaleString('id-ID')+' / Keluar: Rp '+parseFloat(d.kas?.keluar||0).toLocaleString('id-ID');
  }
  document.getElementById('dHadir').textContent=isStaff?(d.hadir?'Sudah Clock In':'Belum Clock In'):(d.hadir||0)+' orang';
  if(isStaff)document.getElementById('dHadir').style.color=d.hadir?'var(--green)':'var(--red)';
  document.getElementById('dSiap').textContent='Siap diambil: '+(d.order?.siap_diambil||0)+' order';

  // Target omset progress
  renderTargetProgress(d);
}

function renderTargetProgress(d){
  const wrap = document.getElementById('targetWrap');
  if (!wrap) return;
  const t = d.target || {};
  const tH = parseInt(t.harian)||0, tB = parseInt(t.bulanan)||0;
  if (!tH && !tB) { wrap.style.display='none'; return; }
  wrap.style.display='block';
  const fmt = n => 'Rp '+Number(n||0).toLocaleString('id-ID');

  // Harian
  const omsetH = parseInt(d.order?.omset)||0;
  const boxH = document.getElementById('targetHarianBox');
  if (tH > 0) {
    boxH.style.display='block';
    const pct = Math.min(100, Math.round(omsetH/tH*100));
    document.getElementById('targetHarianPct').textContent  = pct + '%';
    document.getElementById('targetHarianText').textContent = fmt(omsetH) + ' / ' + fmt(tH);
    document.getElementById('targetHarianBar').style.width  = pct + '%';
    const kurang = Math.max(0, tH - omsetH);
    document.getElementById('targetHarianSub').textContent = kurang > 0
      ? `Kurang ${fmt(kurang)} lagi`
      : '✓ Target tercapai!';
  } else { boxH.style.display='none'; }

  // Bulanan
  const omsetB = parseInt(t.omset_bulan)||0;
  const sisaHari = parseInt(t.hari_sisa)||0;
  const boxB = document.getElementById('targetBulananBox');
  if (tB > 0) {
    boxB.style.display='block';
    const pct = Math.min(100, Math.round(omsetB/tB*100));
    document.getElementById('targetBulananPct').textContent = pct + '%';
    document.getElementById('targetBulananText').textContent = fmt(omsetB) + ' / ' + fmt(tB);
    document.getElementById('targetBulananBar').style.width = pct + '%';
    const kurang = Math.max(0, tB - omsetB);
    let sub;
    if (kurang === 0) sub = '✓ Target tercapai!';
    else if (sisaHari <= 0) sub = `Kurang ${fmt(kurang)} (bulan berakhir hari ini)`;
    else sub = `Sisa ${sisaHari} hari — butuh ${fmt(Math.round(kurang/sisaHari))}/hari`;
    document.getElementById('targetBulananSub').textContent = sub;
  } else { boxB.style.display='none'; }
}

// ── PIPELINE ──────────────────────────────────────────
async function loadPipeline(){
  const r=await fetch('dashboard.php?action=pipeline');
  const d=await r.json();
  const steps=[{key:'masuk',label:'Diterima'},{key:'cuci',label:'Cuci'},{key:'kering',label:'Kering'},{key:'setrika',label:'Setrika'},{key:'siap',label:'Siap Ambil'}];
  const total=Object.values(d).reduce((s,v)=>s+parseInt(v||0),0);
  document.getElementById('pipeTotal').textContent=total+' order aktif';
  document.getElementById('pipeline').innerHTML=steps.map(s=>`<div class="pipe-item ${s.key==='siap'?'active':''}"><div class="pipe-num">${d[s.key]||0}</div><div class="pipe-label">${s.label}</div></div>`).join('');
}

// ── ALERTS ────────────────────────────────────────────
async function loadAlerts(){
  const r=await fetch('dashboard.php?action=alerts');
  const d=await r.json();
  document.getElementById('badgeSiap').textContent=d.siap.length;
  document.getElementById('listSiap').innerHTML=d.siap.length?d.siap.map(o=>alertRow(o,'siap')).join(''):'<div class="hl-empty" style="padding:16px">Tidak ada order siap diambil</div>';
  document.getElementById('badgeMepet').textContent=d.mepet.length;
  document.getElementById('listMepet').innerHTML=d.mepet.length?d.mepet.map(o=>alertRow(o,'mepet')).join(''):'<div class="hl-empty" style="padding:16px">Semua order on-track</div>';
  document.getElementById('badgePiutang').textContent=d.piutang.length;
  document.getElementById('listPiutang').innerHTML=d.piutang.length?d.piutang.map(o=>alertRow(o,'piutang')).join(''):'<div class="hl-empty" style="padding:16px">Tidak ada piutang tertunggak</div>';

  // Mitra drop point inaktif >7 hari
  const wrapMI = document.getElementById('mitraInaktifWrap');
  if (wrapMI && Array.isArray(d.mitra_inaktif) && d.mitra_inaktif.length) {
    wrapMI.style.display = 'block';
    document.getElementById('mitraInaktifList').innerHTML = d.mitra_inaktif.map(m => {
      const last = m.last_order ? new Date(m.last_order).toLocaleDateString('id-ID',{day:'2-digit',month:'short'}) : 'belum pernah';
      const wa = m.wa ? (''+m.wa).replace(/[^0-9]/g,'').replace(/^0/,'62') : '';
      const waLink = wa ? `<a class="alert-wa" target="_blank" href="https://wa.me/${wa.startsWith('62')?wa:'62'+wa}?text=${encodeURIComponent('Halo '+m.nama_mitra+', sudah lama tidak ada order dari titik kamu. Semua baik2 saja? 🙏')}">💬 WA</a>` : '';
      return `<div class="alert-row">
        <div style="flex:1;min-width:0">
          <div class="alert-nama">📦 ${m.nama_mitra}</div>
          <div class="alert-meta">Order terakhir: ${last}</div>
        </div>${waLink}
      </div>`;
    }).join('');
  } else if (wrapMI) {
    wrapMI.style.display = 'none';
  }

  // Mesin self-service: sesi running lewat estimasi
  const wrapMS = document.getElementById('mesinAttentionWrap');
  if (wrapMS && Array.isArray(d.mesin_attention) && d.mesin_attention.length) {
    wrapMS.style.display = 'block';
    document.getElementById('badgeMesinAttention').textContent = d.mesin_attention.length;
    document.getElementById('mesinAttentionList').innerHTML = d.mesin_attention.map(s => {
      const phone = (s.pelanggan_telepon || '').replace(/[^0-9]/g,'').replace(/^0/,'62');
      const waMsg = `Halo ${s.pelanggan_nama}, cuci/kering kamu di ${s.mesin_nama} sudah selesai. Mohon segera diambil ya, terima kasih.`;
      const waUrl = phone ? `https://wa.me/${phone}?text=${encodeURIComponent(waMsg)}` : null;
      return `<div class="alert-row">
        <div style="flex:1;min-width:0">
          <div class="alert-nama">🪙 ${esc(s.mesin_nama)} <small style="color:var(--gray)">${esc(s.mesin_kode)}</small></div>
          <div class="alert-meta">${esc(s.pelanggan_nama)} · Selesai ${s.lewat_menit} menit lalu</div>
        </div>
        ${waUrl ? `<a href="${waUrl}" target="_blank" class="alert-wa">💬 WA</a>` : ''}
      </div>`;
    }).join('');
  } else if (wrapMS) {
    wrapMS.style.display = 'none';
  }

  // Inventori bahan baku stok kritis
  const wrapINV = document.getElementById('inventoriKritisWrap');
  if (wrapINV && Array.isArray(d.inventori_kritis) && d.inventori_kritis.length) {
    wrapINV.style.display = 'block';
    document.getElementById('badgeInventoriKritis').textContent = d.inventori_kritis.length;
    document.getElementById('inventoriKritisList').innerHTML = d.inventori_kritis.map(b => {
      const habis = parseInt(b.stok_terkini) <= 0;
      const badge = habis
        ? '<span class="hl-badge hl-badge-red" style="font-size:10px">🔴 Habis</span>'
        : '<span class="hl-badge" style="font-size:10px;background:#FEF3C7;color:#92400E">⚠️ Minim</span>';
      return `<div class="alert-row">
        <div style="flex:1;min-width:0">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:2px">${badge}</div>
          <div class="alert-nama">${esc(b.nama)}</div>
          <div class="alert-meta">Stok: <strong>${b.stok_terkini} ${esc(b.satuan)}</strong> · Min: ${b.stok_minimum}</div>
        </div>
        <a href="/inventori" class="hl-btn hl-btn-outline hl-btn-sm" style="font-size:11px">Kelola</a>
      </div>`;
    }).join('');
  } else if (wrapINV) {
    wrapINV.style.display = 'none';
  }
}

function alertRow(o,tipe){
  const phone=(o.telepon||'').replace(/[^0-9]/g,'').replace(/^0/,'62');
  const waMsg=tipe==='siap'?`Halo *${o.nama_pelanggan}*, laundry Anda order *${o.no_order}* sudah siap diambil. Total: Rp ${parseFloat(o.total).toLocaleString('id-ID')}. Terima kasih!`:tipe==='piutang'?`Halo *${o.nama_pelanggan}*, mengingatkan pembayaran order *${o.no_order}* sebesar Rp ${parseFloat(o.sisa_bayar).toLocaleString('id-ID')} belum lunas.`:`Halo *${o.nama_pelanggan}*, order *${o.no_order}* dijadwalkan selesai ${fmtDate(o.estimasi_selesai)}.`;
  const waUrl=phone?'https://wa.me/'+phone+'?text='+encodeURIComponent(waMsg):null;
  let badge='';
  if(tipe==='mepet'){const est=new Date(o.estimasi_selesai+'T00:00:00'),today=new Date();today.setHours(0,0,0,0);const diff=Math.round((est-today)/86400000);badge=diff<=0?'<span class="hl-badge hl-badge-red" style="font-size:10px">Terlambat</span>':'<span class="hl-badge hl-badge-dp" style="font-size:10px">Besok</span>';}
  if(tipe==='piutang')badge=`<span class="hl-badge hl-badge-red" style="font-size:10px">${o.hari_lalu} hari lalu</span>`;
  return `<div class="alert-row">
    <div style="min-width:0;flex:1">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:2px"><span class="alert-no">${o.no_order}</span>${badge}</div>
      <div class="alert-nama">${esc(o.nama_pelanggan)}</div>
      <div class="alert-meta">${tipe==='siap'?'Sisa bayar: <strong>Rp '+parseFloat(o.sisa_bayar||0).toLocaleString('id-ID')+'</strong>':tipe==='mepet'?'Est: '+fmtDate(o.estimasi_selesai)+' · '+statusLabel(o.status_proses):'Sisa: <strong style="color:var(--red)">Rp '+parseFloat(o.sisa_bayar).toLocaleString('id-ID')+'</strong> · '+fmtDate(o.tanggal)}</div>
    </div>
    <div style="display:flex;gap:6px;align-items:center;flex-shrink:0">
      ${waUrl?`<a href="${waUrl}" target="_blank" class="alert-wa">WA</a>`:''}
      <a href="/orders" class="hl-btn hl-btn-outline hl-btn-sm" style="font-size:11px">Detail</a>
    </div>
  </div>`;
}

// ── CHART ─────────────────────────────────────────────
async function loadChart(){
  const r=await fetch('dashboard.php?action=chart7');
  const d=await r.json();
  if(chartInstance)chartInstance.destroy();
  const days=[];
  for(let i=6;i>=0;i--){const dt=new Date();dt.setDate(dt.getDate()-i);days.push(localDateStr(dt));}
  const dataMap={};
  d.forEach(x=>{dataMap[x.tgl]={omset:parseFloat(x.omset),count:parseInt(x.order_count)};});
  chartInstance=new Chart(document.getElementById('chartOmset'),{
    type:'bar',
    data:{
      labels:days.map(d=>new Date(d+'T00:00:00').toLocaleDateString('id-ID',{day:'numeric',month:'short'})),
      datasets:[{label:'Omset',data:days.map(d=>dataMap[d]?.omset||0),backgroundColor:days.map((_,i)=>i===6?'rgba(53,232,213,.8)':'rgba(27,45,90,.5)'),borderRadius:6}]
    },
    options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{callback:v=>'Rp '+(v/1000).toFixed(0)+'k'}},x:{grid:{display:false}}}}
  });
}

function statusLabel(s){return{masuk:'Diterima',cuci:'Cuci',kering:'Kering',setrika:'Setrika',siap:'Siap',diambil:'Diambil'}[s]||s;}
function fmtDate(d){if(!d)return'-';return new Date(d+'T00:00:00').toLocaleDateString('id-ID',{day:'2-digit',month:'short'});}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
</script>
</body>
</html>

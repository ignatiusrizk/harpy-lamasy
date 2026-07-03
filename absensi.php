<?php
$activePage = 'absensi';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once __DIR__ . '/components.php';
require_once ROOT . '/core/Geo.php';
require_once ROOT . '/core/ShiftCalc.php';
$user = currentUser();
// Akses absensi: butuh minimal absensi.view (manajer) ATAU absensi.clock (karyawan)
if (!hasPermission('absensi.view') && !hasPermission('absensi.clock')) {
    requirePermission('absensi.view');
}

// ── API ───────────────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action === '') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}
if ($action) {
    header('Content-Type: application/json');
    $tid = TenantResolver::id();
    $oid = TenantResolver::outletId();

    // CLOCK IN
    if ($action === 'clock_in' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hasPermission('absensi.clock')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d   = json_decode(file_get_contents('php://input'), true);
        $tgl = date('Y-m-d');
        $jam = date('H:i:s');

        $row = TenantQuery::rawOne(
            "SELECT * FROM hl_absensi WHERE tenant_id=? AND outlet_id=? AND user_id=? AND tanggal=?",
            [$tid, $oid, $user['id'], $tgl]
        );

        if ($row) {
            if ($row['jam_masuk']) {
                echo json_encode(['error' => 'Anda sudah clock in hari ini jam ' . substr($row['jam_masuk'],0,5)]);
            } else {
                echo json_encode(['error' => 'Data absensi hari ini sudah ada']);
            }
            exit;
        }

        // Baca config absensi outlet ini
        $cfg = TenantQuery::rawOne(
            "SELECT absensi_selfie_wajib, absensi_geofence_aktif, absensi_lat, absensi_lng, absensi_radius_m
               FROM outlets WHERE id=? AND tenant_id=? LIMIT 1",
            [$oid, $tid]
        ) ?: [];

        $lokasi = substr(trim(strip_tags($d['lokasi'] ?? '')), 0, 255) ?: null;

        // Geofence (strict)
        if (!empty($cfg['absensi_geofence_aktif'])) {
            $lat = isset($d['lat']) && $d['lat'] !== '' ? (float)$d['lat'] : null;
            $lng = isset($d['lng']) && $d['lng'] !== '' ? (float)$d['lng'] : null;
            if ($lat === null || $lng === null || ($cfg['absensi_lat'] === null || $cfg['absensi_lng'] === null)) {
                echo json_encode(['error' => 'Lokasi tak terdeteksi. Aktifkan izin lokasi untuk clock-in.']); exit;
            }
            $dist = Geo::haversineMeters($lat, $lng, (float)$cfg['absensi_lat'], (float)$cfg['absensi_lng']);
            if ($dist > (int)$cfg['absensi_radius_m']) {
                echo json_encode(['error' => 'Kamu di luar area outlet (' . round($dist) . ' m > ' . (int)$cfg['absensi_radius_m'] . ' m).']); exit;
            }
            $lokasi = round($lat, 7) . ',' . round($lng, 7);
        }

        // Selfie wajib
        $selfie = null;
        if (!empty($cfg['absensi_selfie_wajib'])) {
            $sp = trim((string)($d['selfie_path'] ?? ''));
            $pre = 'uploads/absensi_selfie/t' . $tid . '_o' . $oid . '_'; // trailing _ cegah cross-outlet (o1 vs o10)
            if ($sp === '' || strpos($sp, '..') !== false || strpos($sp, $pre) !== 0) {
                echo json_encode(['error' => 'Selfie wajib untuk clock-in.']); exit;
            }
            $selfie = substr($sp, 0, 255);
        }

        // Jadwal shift hari ini → telat
        $shiftId = null; $telatMenit = 0;
        $hari = (int)date('N'); // 1=Senin..7=Minggu
        $jd = TenantQuery::rawOne(
            "SELECT s.id, s.jam_mulai, s.toleransi_telat_menit
               FROM hl_jadwal_shift j JOIN hl_shift s ON s.id=j.shift_id AND s.tenant_id=j.tenant_id AND s.outlet_id=j.outlet_id
              WHERE j.tenant_id=? AND j.outlet_id=? AND j.user_id=? AND j.hari=? LIMIT 1",
            [$tid, $oid, $user['id'], $hari]
        );
        if ($jd) {
            $shiftId = (int)$jd['id'];
            $telatMenit = ShiftCalc::hitungTelat($jam, $jd['jam_mulai'], (int)$jd['toleransi_telat_menit']);
        }

        TenantQuery::insert('hl_absensi', [
            'user_id'      => $user['id'],
            'tanggal'      => $tgl,
            'jam_masuk'    => $jam,
            'lokasi_masuk' => $lokasi,
            'selfie_masuk' => $selfie,
            'status'       => 'hadir',
            'shift_id'     => $shiftId,
            'telat_menit'  => $telatMenit,
        ]);

        logAudit('clock_in', 'absensi', 'Tanggal: ' . $tgl);
        echo json_encode(['success' => true, 'jam' => substr($jam, 0, 5), 'tanggal' => $tgl]);
        exit;
    }

    // UPLOAD SELFIE
    if ($action === 'upload_selfie' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hasPermission('absensi.clock')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        require_once ROOT . '/core/FileUpload.php';
        $up = FileUpload::uploadImage($_FILES['foto'] ?? [], 'uploads/absensi_selfie', 't'.$tid.'_o'.$oid);
        if ($up['error']) { echo json_encode(['error'=>$up['error']]); exit; }
        echo json_encode(['path'=>$up['path']]); exit;
    }

    // CLOCK OUT
    if ($action === 'clock_out' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hasPermission('absensi.clock')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d   = json_decode(file_get_contents('php://input'), true);
        $tgl = date('Y-m-d');
        $jam = date('H:i:s');

        $row = TenantQuery::rawOne(
            "SELECT * FROM hl_absensi WHERE tenant_id=? AND outlet_id=? AND user_id=? AND tanggal=?",
            [$tid, $oid, $user['id'], $tgl]
        );

        if (!$row) {
            echo json_encode(['error' => 'Anda belum clock in hari ini']); exit;
        }
        if ($row['jam_keluar']) {
            echo json_encode(['error' => 'Anda sudah clock out jam ' . substr($row['jam_keluar'],0,5)]); exit;
        }

        $masuk  = strtotime($tgl . ' ' . $row['jam_masuk']);
        $keluar = strtotime($tgl . ' ' . $jam);
        $durasi = round(($keluar - $masuk) / 60);

        $lemburMenit = 0;
        if (!empty($row['shift_id'])) {
            $sh = TenantQuery::rawOne("SELECT jam_selesai, lembur_after_menit FROM hl_shift WHERE id=? AND tenant_id=? LIMIT 1", [(int)$row['shift_id'], $tid]);
            if ($sh) $lemburMenit = ShiftCalc::hitungLembur($jam, $sh['jam_selesai'], (int)$sh['lembur_after_menit']);
        }

        TenantQuery::update('hl_absensi',
            ['jam_keluar' => $jam, 'durasi_menit' => $durasi,
             'lokasi_keluar' => substr(trim(strip_tags($d['lokasi'] ?? '')), 0, 255) ?: null,
             'lembur_menit' => $lemburMenit],
            'id = ?', [$row['id']]
        );

        logAudit('clock_out', 'absensi', 'Durasi: ' . $durasi . ' menit');
        $jam_str = substr($jam,0,5);
        $dur_str = floor($durasi/60) . ' jam ' . ($durasi%60) . ' menit';
        echo json_encode(['success'=>true, 'jam'=>$jam_str, 'durasi'=>$dur_str]);
        exit;
    }

    // STATUS HARI INI
    if ($action === 'status_hari_ini') {
        $tgl = date('Y-m-d');
        $row = TenantQuery::rawOne(
            "SELECT * FROM hl_absensi WHERE tenant_id=? AND outlet_id=? AND user_id=? AND tanggal=?",
            [$tid, $oid, $user['id'], $tgl]
        );
        echo json_encode($row ?: ['status'=>'belum']);
        exit;
    }

    // REKAP PERSONAL
    if ($action === 'rekap_personal') {
        $bulan = $_GET['bulan'] ?? date('Y-m');
        [$y,$m] = explode('-', $bulan);
        $dari   = "$y-$m-01";
        $sampai = date('Y-m-t', strtotime($dari));

        $uid = hasPermission('absensi.view') && !empty($_GET['user_id'])
               ? intval($_GET['user_id']) : $user['id'];

        $data = TenantQuery::raw(
            "SELECT a.*, u.nama FROM hl_absensi a
             JOIN hl_users u ON u.id=a.user_id AND u.tenant_id=a.tenant_id
             WHERE a.tenant_id=? AND a.outlet_id=? AND a.user_id=? AND a.tanggal BETWEEN ? AND ?
             ORDER BY a.tanggal",
            [$tid, $oid, $uid, $dari, $sampai]
        );

        $hadir  = count(array_filter($data, fn($r) => $r['status']==='hadir'));
        $izin   = count(array_filter($data, fn($r) => $r['status']==='izin'));
        $sakit  = count(array_filter($data, fn($r) => $r['status']==='sakit'));
        $alpha  = count(array_filter($data, fn($r) => $r['status']==='alpha'));
        $total_menit = array_sum(array_column($data, 'durasi_menit'));

        echo json_encode([
            'data'    => $data,
            'summary' => compact('hadir','izin','sakit','alpha','total_menit'),
            'periode' => ['dari'=>$dari,'sampai'=>$sampai,'bulan'=>$bulan],
        ]); exit;
    }

    // REKAP SEMUA KARYAWAN (admin only)
    if ($action === 'rekap_all') {
        if (!hasPermission('absensi.view') && !hasPermission('absensi.approve')) {
            echo json_encode(['error'=>'Akses ditolak']); exit;
        }
        $bulan  = $_GET['bulan'] ?? date('Y-m');
        [$y,$m] = explode('-', $bulan);
        $dari   = "$y-$m-01";
        $sampai = date('Y-m-t', strtotime($dari));

        $rows = TenantQuery::raw(
            "SELECT u.id, u.nama, u.role,
             COUNT(CASE WHEN a.status='hadir'  THEN 1 END) as hadir,
             COUNT(CASE WHEN a.status='izin'   THEN 1 END) as izin,
             COUNT(CASE WHEN a.status='sakit'  THEN 1 END) as sakit,
             COUNT(CASE WHEN a.status='alpha'  THEN 1 END) as alpha,
             COALESCE(SUM(a.durasi_menit),0) as total_menit,
             COALESCE(SUM(a.telat_menit),0) as total_telat,
             COALESCE(SUM(a.lembur_menit),0) as total_lembur,
             MAX(a.tanggal) as last_absen
             FROM hl_users u
             JOIN hl_karyawan_outlet ko
               ON ko.karyawan_id=u.id AND ko.tenant_id=u.tenant_id
              AND ko.outlet_id=? AND ko.is_active=1
             LEFT JOIN hl_absensi a ON a.user_id=u.id AND a.tenant_id=u.tenant_id AND a.outlet_id=?
                AND a.tanggal BETWEEN ? AND ?
             WHERE u.tenant_id=? AND u.is_active=1
             GROUP BY u.id ORDER BY u.nama",
            [$oid, $oid, $dari, $sampai, $tid]
        );
        echo json_encode(['data'=>$rows, 'periode'=>['bulan'=>$bulan,'dari'=>$dari,'sampai'=>$sampai]]);
        exit;
    }

    // INPUT IZIN/SAKIT
    if ($action === 'input_izin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $d    = json_decode(file_get_contents('php://input'), true);
        $dari = substr(trim($d['dari'] ?? ''), 0, 10);
        $samp = substr(trim($d['sampai'] ?? ''), 0, 10);
        $tipe = in_array($d['tipe']??'', ['izin','sakit','cuti']) ? $d['tipe'] : 'izin';
        $alas = substr(trim(strip_tags($d['alasan'] ?? '')), 0, 500);

        TenantQuery::insert('hl_izin', [
            'user_id'        => $user['id'],
            'dari_tanggal'   => $dari,
            'sampai_tanggal' => $samp,
            'tipe'           => $tipe,
            'alasan'         => $alas,
        ]);

        // INSERT IGNORE untuk range tanggal ke hl_absensi
        $db   = Database::get();
        $stmt = $db->prepare(
            "INSERT IGNORE INTO hl_absensi (tenant_id,outlet_id,user_id,tanggal,status,catatan)
             VALUES (?,?,?,?,?,?)"
        );
        $cur = strtotime($dari);
        $end = strtotime($samp);
        while ($cur <= $end) {
            $stmt->execute([$tid, $oid, $user['id'], date('Y-m-d',$cur), $tipe, $alas]);
            $cur = strtotime('+1 day', $cur);
        }

        logAudit('input_izin', 'absensi', $tipe . ': ' . $dari . ' – ' . $samp);
        echo json_encode(['success'=>true]); exit;
    }

    // APPROVE IZIN (admin only)
    if ($action === 'approve_izin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        if (!hasPermission('absensi.view') && !hasPermission('absensi.approve')) {
            echo json_encode(['error'=>'Akses ditolak']); exit;
        }
        $d      = json_decode(file_get_contents('php://input'), true);
        $status = in_array($d['status']??'', ['approved','rejected']) ? $d['status'] : 'rejected';

        TenantQuery::update('hl_izin',
            ['status' => $status, 'approved_by' => $user['id']],
            'id = ?', [intval($d['id'])]
        );
        logAudit('approve_izin', 'absensi', 'ID: ' . intval($d['id']) . ' → ' . $status);
        echo json_encode(['success'=>true]); exit;
    }

    // LIST IZIN
    if ($action === 'list_izin') {
        if (hasPermission('absensi.view')) {
            $rows = TenantQuery::raw(
                "SELECT i.*,u.nama FROM hl_izin i
                 JOIN hl_users u ON u.id=i.user_id AND u.tenant_id=i.tenant_id
                 WHERE i.tenant_id=? AND i.outlet_id=? ORDER BY i.created_at DESC LIMIT 50",
                [$tid, $oid]
            );
        } else {
            $rows = TenantQuery::raw(
                "SELECT i.*,u.nama FROM hl_izin i
                 JOIN hl_users u ON u.id=i.user_id AND u.tenant_id=i.tenant_id
                 WHERE i.tenant_id=? AND i.outlet_id=? AND i.user_id=? ORDER BY i.created_at DESC LIMIT 20",
                [$tid, $oid, $user['id']]
            );
        }
        echo json_encode($rows); exit;
    }

    // LIST USERS (admin)
    if ($action === 'list_users') {
        if (!hasPermission('absensi.view') && !hasPermission('absensi.approve')) {
            echo json_encode([]); exit;
        }
        // Hanya karyawan yang ditugaskan ke outlet ini (per brief HQ-Outlet)
        $rows = TenantQuery::raw(
            "SELECT u.id, u.nama, u.role
             FROM hl_users u
             JOIN hl_karyawan_outlet ko
               ON ko.karyawan_id=u.id AND ko.tenant_id=u.tenant_id
              AND ko.outlet_id=? AND ko.is_active=1
             WHERE u.tenant_id=? AND u.is_active=1
             ORDER BY u.nama",
            [$oid, $tid]
        );
        echo json_encode($rows); exit;
    }

    // HANDOVER: precompute (saldo kas, order_pending, order_siap_ambil)
    if ($action === 'handover_compute') {
        $tgl = $_GET['tanggal'] ?? date('Y-m-d');
        try {
            $db = Database::get();
            // Saldo kas akhir hari = pemasukan - pengeluaran hari ini (sederhana)
            $kas = 0;
            try {
                $st = $db->prepare("SELECT COALESCE(SUM(CASE WHEN tipe='masuk' THEN nominal ELSE -nominal END),0)
                                      FROM hl_kas WHERE tenant_id=? AND outlet_id=? AND DATE(tanggal)=?");
                $st->execute([$tid, $oid, $tgl]);
                $kas = (int)$st->fetchColumn();
            } catch (Throwable $e) { $kas = 0; }

            $pending = TenantQuery::count('hl_transaksi',
                "status_proses IN ('masuk','cuci','kering','setrika')", []);
            $siap    = TenantQuery::count('hl_transaksi',
                "status_proses='siap'", []);

            // Cek existing handover hari ini
            $existing = null;
            try {
                $st = $db->prepare("SELECT * FROM hl_shift_handover WHERE tenant_id=? AND outlet_id=? AND tanggal=? AND user_id_keluar=? ORDER BY id DESC LIMIT 1");
                $st->execute([$tid, $oid, $tgl, $_SESSION['user_id'] ?? 0]);
                $existing = $st->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                ErrorLogger::logException('db_error', $e, $tid, $oid);
            }

            echo json_encode([
                'ok' => true,
                'saldo_kas_akhir'   => $kas,
                'order_pending'     => $pending,
                'order_siap_ambil'  => $siap,
                'existing'          => $existing,
            ]);
        } catch (Throwable $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'handover_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $tgl   = $d['tanggal'] ?? date('Y-m-d');
        $shift = in_array(($d['shift'] ?? 'pagi'), ['pagi','sore','malam'], true) ? $d['shift'] : 'pagi';
        try {
            $db  = Database::get();
            $stmt = $db->prepare("INSERT INTO hl_shift_handover
                (tenant_id, outlet_id, user_id_keluar, user_id_masuk, tanggal, shift,
                 saldo_kas_akhir, order_pending, order_siap_ambil,
                 kondisi_mesin, catatan_khusus, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?, 'submitted')");
            $stmt->execute([
                $tid, $oid,
                $_SESSION['user_id'] ?? 0,
                !empty($d['user_id_masuk']) ? intval($d['user_id_masuk']) : null,
                $tgl, $shift,
                intval($d['saldo_kas_akhir'] ?? 0),
                intval($d['order_pending'] ?? 0),
                intval($d['order_siap_ambil'] ?? 0),
                trim($d['kondisi_mesin'] ?? ''),
                trim($d['catatan_khusus'] ?? ''),
            ]);
            logAudit('handover_submit', 'shift', "$tgl/$shift");
            echo json_encode(['ok'=>true, 'id'=>(int)$db->lastInsertId()]);
        } catch (Throwable $e) {
            echo json_encode(['error'=>'Gagal simpan handover: '.$e->getMessage()]);
        }
        exit;
    }

    if ($action === 'handover_ack' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $d  = json_decode(file_get_contents('php://input'), true);
        $id = intval($d['id'] ?? 0);
        if (!$id) { echo json_encode(['error'=>'id wajib']); exit; }
        try {
            $db = Database::get();
            $stmt = $db->prepare("UPDATE hl_shift_handover
                                     SET status='acknowledged', acknowledged_at=NOW(), acknowledged_by=?
                                   WHERE tenant_id=? AND outlet_id=? AND id=?");
            $stmt->execute([$_SESSION['user_id'] ?? 0, $tid, $oid, $id]);
            logAudit('handover_ack', 'shift#'.$id, '');
            echo json_encode(['ok'=>true]);
        } catch (Throwable $e) {
            echo json_encode(['error'=>$e->getMessage()]);
        }
        exit;
    }

    if ($action === 'handover_pending') {
        try {
            $db = Database::get();
            $stmt = $db->prepare("SELECT h.*, u.nama AS nama_keluar
                                    FROM hl_shift_handover h
                                    LEFT JOIN hl_users u ON u.id=h.user_id_keluar AND u.tenant_id=h.tenant_id
                                   WHERE h.tenant_id=? AND h.outlet_id=?
                                     AND h.status='submitted'
                                   ORDER BY h.id DESC LIMIT 5");
            $stmt->execute([$tid, $oid]);
            echo json_encode(['ok'=>true, 'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) {
            echo json_encode(['ok'=>true, 'rows'=>[]]);
        }
        exit;
    }

    // ── SHIFT CRUD ────────────────────────────────────
    if ($action === 'shift_list') {
        if (!hasPermission('absensi.view')) { echo json_encode([]); exit; }
        echo json_encode(TenantQuery::raw(
            "SELECT * FROM hl_shift WHERE tenant_id=? AND outlet_id=? ORDER BY urutan, jam_mulai", [$tid,$oid]
        )); exit;
    }
    if ($action === 'shift_save' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('absensi.view')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $nama = substr(trim(strip_tags($d['nama'] ?? '')), 0, 50);
        $jm = $d['jam_mulai'] ?? ''; $js = $d['jam_selesai'] ?? '';
        if (!$nama || !preg_match('/^\d{2}:\d{2}/', $jm) || !preg_match('/^\d{2}:\d{2}/', $js)) { echo json_encode(['error'=>'Nama & jam wajib (HH:MM)']); exit; }
        if (strtotime($js) <= strtotime($jm)) { echo json_encode(['error'=>'Jam selesai harus setelah jam mulai (shift lintas malam belum didukung)']); exit; }
        $data = [
            'nama'=>$nama,
            'jam_mulai'=>substr($jm,0,8) ?: $jm.':00',
            'jam_selesai'=>substr($js,0,8) ?: $js.':00',
            'toleransi_telat_menit'=>max(0,(int)($d['toleransi_telat_menit'] ?? 15)),
            'lembur_after_menit'=>max(0,(int)($d['lembur_after_menit'] ?? 30)),
            'is_active'=>1,
            'urutan'=>(int)($d['urutan'] ?? 0),
        ];
        if (!empty($d['id'])) TenantQuery::update('hl_shift', $data, 'id=?', [(int)$d['id']]);
        else TenantQuery::insert('hl_shift', $data);
        echo json_encode(['success'=>true]); exit;
    }
    if ($action === 'shift_delete' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('absensi.view')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $sid = (int)($d['id'] ?? 0);
        $used = TenantQuery::rawOne("SELECT COUNT(*) c FROM hl_jadwal_shift WHERE tenant_id=? AND outlet_id=? AND shift_id=?", [$tid,$oid,$sid]);
        if (($used['c'] ?? 0) > 0) { echo json_encode(['error'=>'Shift masih dipakai di jadwal. Hapus dari jadwal dulu.']); exit; }
        TenantQuery::delete('hl_shift', 'id=?', [$sid]);
        echo json_encode(['success'=>true]); exit;
    }
    if ($action === 'shift_seed_template' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('absensi.view')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $ada = TenantQuery::rawOne("SELECT COUNT(*) c FROM hl_shift WHERE tenant_id=? AND outlet_id=?", [$tid,$oid]);
        if (($ada['c'] ?? 0) > 0) { echo json_encode(['error'=>'Sudah ada shift, template tidak dibuat']); exit; }
        foreach ([['Pagi','08:00:00','16:00:00',1],['Sore','14:00:00','22:00:00',2],['Full','08:00:00','20:00:00',3]] as $t) {
            TenantQuery::insert('hl_shift', ['nama'=>$t[0],'jam_mulai'=>$t[1],'jam_selesai'=>$t[2],'toleransi_telat_menit'=>15,'lembur_after_menit'=>30,'is_active'=>1,'urutan'=>$t[3]]);
        }
        echo json_encode(['success'=>true]); exit;
    }

    // ── JADWAL SHIFT ──────────────────────────────────
    if ($action === 'jadwal_get') {
        if (!hasPermission('absensi.view')) { echo json_encode([]); exit; }
        echo json_encode(TenantQuery::raw(
            "SELECT user_id, hari, shift_id FROM hl_jadwal_shift WHERE tenant_id=? AND outlet_id=?", [$tid,$oid]
        )); exit;
    }
    if ($action === 'jadwal_save' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('absensi.view')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $uid = (int)($d['user_id'] ?? 0); $hari = (int)($d['hari'] ?? 0); $sid = (int)($d['shift_id'] ?? 0);
        if ($uid < 1 || $hari < 1 || $hari > 7) { echo json_encode(['error'=>'Data tidak valid']); exit; }
        // Validasi user_id adalah karyawan outlet ini
        $karOk = TenantQuery::rawOne(
            "SELECT u.id FROM hl_users u
             JOIN hl_karyawan_outlet ko
               ON ko.karyawan_id=u.id AND ko.tenant_id=u.tenant_id
              AND ko.outlet_id=? AND ko.is_active=1
             WHERE u.tenant_id=? AND u.id=? AND u.is_active=1 LIMIT 1",
            [$oid, $tid, $uid]
        );
        if (!$karOk) { echo json_encode(['error'=>'Karyawan tidak valid']); exit; }
        if ($sid < 1) {
            // libur → hapus baris
            TenantQuery::delete('hl_jadwal_shift', 'user_id=? AND hari=?', [$uid,$hari]);
        } else {
            // validasi shift_id milik outlet ini
            $shiftOk = TenantQuery::rawOne("SELECT id FROM hl_shift WHERE tenant_id=? AND outlet_id=? AND id=?", [$tid,$oid,$sid]);
            if (!$shiftOk) { echo json_encode(['error'=>'Shift tidak ditemukan di outlet ini']); exit; }
            // upsert (UNIQUE user+hari) — hapus lalu insert (sederhana & tenant+outlet-scoped)
            TenantQuery::delete('hl_jadwal_shift', 'user_id=? AND hari=?', [$uid,$hari]);
            TenantQuery::insert('hl_jadwal_shift', ['user_id'=>$uid,'hari'=>$hari,'shift_id'=>$sid]);
        }
        echo json_encode(['success'=>true]); exit;
    }

    echo json_encode(['error'=>'Unknown']); exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Absensi'); ?>
<style>
/* ── CLOCK WIDGET ── */
.clock-widget{background:linear-gradient(135deg,var(--navy-d),var(--navy));border-radius:20px;padding:32px;text-align:center;color:var(--white);margin-bottom:20px;position:relative;overflow:hidden}
.clock-widget::before{content:'';position:absolute;top:-60px;right:-60px;width:200px;height:200px;background:rgba(53,232,213,.06);border-radius:50%}
.clock-time{font-family:var(--mono);font-size:3rem;font-weight:800;color:var(--teal);letter-spacing:.06em;line-height:1;margin-bottom:6px}
.clock-date{font-size:14px;color:rgba(255,255,255,.5);margin-bottom:24px}
.clock-status{font-size:13px;font-weight:600;padding:8px 20px;border-radius:100px;display:inline-block;margin-bottom:20px}
.clock-status.belum{background:rgba(255,255,255,.08);color:rgba(255,255,255,.5)}
.clock-status.masuk{background:rgba(16,185,129,.2);color:#6EE7B7}
.clock-status.keluar{background:rgba(107,114,128,.2);color:rgba(255,255,255,.5)}
.clock-btns{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
.btn-clock-in{padding:14px 32px;background:var(--teal);color:var(--navy-d);border:none;border-radius:12px;font-family:var(--font);font-size:15px;font-weight:700;cursor:pointer;transition:all .2s}
.btn-clock-in:hover{background:var(--teal-d);transform:translateY(-2px);box-shadow:0 8px 24px rgba(53,232,213,.3)}
.btn-clock-out{padding:14px 32px;background:rgba(239,68,68,.15);color:#FCA5A5;border:1.5px solid rgba(239,68,68,.3);border-radius:12px;font-family:var(--font);font-size:15px;font-weight:700;cursor:pointer;transition:all .2s}
.btn-clock-out:hover{background:var(--red);color:white;transform:translateY(-2px)}
.btn-clock:disabled{opacity:.4;pointer-events:none}
.jam-info{display:flex;gap:16px;justify-content:center;margin-top:12px}
.jam-chip{background:rgba(255,255,255,.06);border-radius:10px;padding:8px 16px;font-size:13px}
.jam-chip span{font-family:var(--mono);font-weight:700;color:var(--teal)}

/* ── CALENDAR ── */
.absensi-cal{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;margin-top:12px}
.cal-header{text-align:center;font-size:11px;font-weight:700;color:var(--gray);padding:6px 0;text-transform:uppercase;letter-spacing:.06em}
.cal-day{aspect-ratio:1;border-radius:8px;display:flex;flex-direction:column;align-items:center;justify-content:center;font-size:12px;font-weight:600;cursor:default;transition:all .2s;border:1.5px solid transparent}
.cal-day.today{border-color:var(--teal);color:var(--teal)}
.cal-day.hadir{background:#D1FAE5;color:#065F46}
.cal-day.izin{background:#FEF3C7;color:#92400E}
.cal-day.sakit{background:#DBEAFE;color:#1D4ED8}
.cal-day.alpha{background:#FEE2E2;color:#991B1B}
.cal-day.libur{background:var(--off);color:var(--gray);opacity:.5}
.cal-day.empty{visibility:hidden}
.cal-dot{width:5px;height:5px;border-radius:50%;background:currentColor;margin-top:2px}

/* ── TABS ── */
.hl-tabs{display:flex;gap:4px;background:var(--white);border-radius:var(--r-lg);padding:6px;box-shadow:var(--shadow);margin-bottom:20px;border:1px solid rgba(27,45,90,.07);overflow-x:auto;-webkit-overflow-scrolling:touch}
.hl-tabs::-webkit-scrollbar{display:none}
.hl-tab{flex:0 0 auto;white-space:nowrap;padding:10px 16px;border-radius:var(--r);font-size:14px;font-weight:600;color:var(--gray);cursor:pointer;text-align:center;transition:all .2s;border:none;background:transparent;font-family:var(--font)}
.hl-tab:hover{color:var(--navy)}
.hl-tab.active{background:var(--navy);color:var(--white)}

/* ── IZIN FORM ── */
.tipe-izin-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px}
.tipe-izin-btn{padding:12px 8px;border-radius:var(--r);border:2px solid rgba(27,45,90,.12);background:var(--off);cursor:pointer;text-align:center;font-family:var(--font);font-size:13px;font-weight:600;transition:all .2s;color:var(--navy)}
.tipe-izin-btn:hover{border-color:var(--teal)}
.tipe-izin-btn.active{border-color:var(--teal);background:var(--teal-bg);color:var(--navy)}

/* ── REKAP TABLE ── */
.durasi-bar{background:var(--light);border-radius:100px;height:6px;margin-top:4px;overflow:hidden}
.durasi-fill{height:100%;background:var(--teal);border-radius:100px;transition:width .5s}
@media(max-width:680px){
  .clock-widget{padding:22px 18px}
  .clock-time{font-size:2.2rem}
  .clock-date{font-size:12px;margin-bottom:16px}
  .btn-clock-in,.btn-clock-out{padding:12px 24px;font-size:14px}
  .jam-info{gap:10px}
  .tipe-izin-grid{grid-template-columns:1fr 1fr 1fr}
  #calStats{grid-template-columns:repeat(3,1fr) !important;gap:6px !important}
  .hl-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
  .hl-table thead th{font-size:11px;padding:8px 8px}
  .hl-table tbody td{font-size:12px;padding:8px 8px}
}
@media(max-width:400px){
  .clock-time{font-size:1.9rem}
  .clock-btns{gap:8px}
  .btn-clock-in,.btn-clock-out{padding:10px 18px;font-size:13px}
  #calStats{grid-template-columns:repeat(3,1fr) !important}
}
</style>
</head>
<body>
<?php renderTopbar('absensi'); ?>

<div class="hl-main">

  <!-- CLOCK WIDGET + REKAP PRIBADI (2 COL) -->
  <div class="hl-grid-2" style="margin-bottom:20px">

    <!-- CLOCK IN/OUT -->
    <div>
      <div class="clock-widget">
        <div class="clock-time" id="clockTime">--:--:--</div>
        <div class="clock-date" id="clockDate">--</div>
        <div class="clock-status belum" id="clockStatus">⏳ Memuat status...</div>
        <input type="file" id="selfieFile" accept="image/*" capture="user" style="display:none">
        <div class="clock-btns">
          <button class="btn-clock-in btn-clock" id="btnClockIn" onclick="clockIn()" disabled>
            ▶ Clock In
          </button>
          <button class="btn-clock-out btn-clock" id="btnClockOut" onclick="clockOut()" disabled>
            ■ Clock Out
          </button>
        </div>
        <div class="jam-info" id="jamInfo" style="display:none">
          <div class="jam-chip">Masuk: <span id="jamMasuk">-</span></div>
          <div class="jam-chip">Keluar: <span id="jamKeluar">-</span></div>
          <div class="jam-chip">Durasi: <span id="durasi">-</span></div>
        </div>
      </div>

      <!-- SERAH TERIMA SHIFT (handover) -->
      <div class="hl-card" style="margin-bottom:16px">
        <div class="hl-card-header">
          <div class="hl-card-title">🤝 Serah Terima Shift</div>
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="toggleHandover()">
            <span id="hoToggleLabel">Buka Form</span>
          </button>
        </div>
        <div class="hl-card-body" id="handoverBody" style="display:none">
          <div id="handoverPending" style="margin-bottom:10px"></div>
          <div class="hl-form-row" style="margin-bottom:10px">
            <div class="hl-form-group">
              <label class="hl-label">Tanggal</label>
              <input type="date" id="ho_tanggal" class="hl-input"/>
            </div>
            <div class="hl-form-group">
              <label class="hl-label">Shift</label>
              <select id="ho_shift" class="hl-input" onchange="">
                <option value="pagi">Pagi</option>
                <option value="sore">Sore</option>
                <option value="malam">Malam</option>
              </select>
            </div>
          </div>
          <div class="hl-form-row" style="margin-bottom:10px">
            <div class="hl-form-group">
              <label class="hl-label">Saldo Kas Akhir (Rp)</label>
              <input type="number" id="ho_kas" class="lm-rp hl-input" step="500" min="0"/>
            </div>
            <div class="hl-form-group">
              <label class="hl-label">Diserahkan ke (opsional)</label>
              <select id="ho_user_masuk" class="hl-input">
                <option value="">— Pilih kasir penerus —</option>
              </select>
            </div>
          </div>
          <div class="hl-form-row" style="margin-bottom:10px">
            <div class="hl-form-group">
              <label class="hl-label">Order Pending</label>
              <input type="number" id="ho_pending" class="hl-input" min="0" readonly style="background:#F1F5F9"/>
            </div>
            <div class="hl-form-group">
              <label class="hl-label">Order Siap Diambil</label>
              <input type="number" id="ho_siap" class="hl-input" min="0" readonly style="background:#F1F5F9"/>
            </div>
          </div>
          <div class="hl-form-group" style="margin-bottom:10px">
            <label class="hl-label">Kondisi Mesin</label>
            <textarea id="ho_mesin" class="hl-input hl-textarea" placeholder="Mesin A normal, mesin B sedikit bunyi..." style="min-height:60px"></textarea>
          </div>
          <div class="hl-form-group" style="margin-bottom:12px">
            <label class="hl-label">Catatan Khusus</label>
            <textarea id="ho_catatan" class="hl-input hl-textarea" placeholder="Pelanggan A janji ambil sore, dll." style="min-height:60px"></textarea>
          </div>
          <div style="display:flex;gap:8px">
            <button class="hl-btn hl-btn-outline" onclick="refreshHandover()">↻ Refresh Data</button>
            <button class="hl-btn hl-btn-primary" style="flex:1" onclick="submitHandover()">📤 Submit Handover</button>
          </div>
          <small style="display:block;margin-top:8px;color:var(--gray)">
            ℹ️ Optional — tidak menghalangi Clock Out. Berguna untuk audit & shift swap.
          </small>
        </div>
      </div>

      <!-- FORM IZIN/SAKIT -->
      <div class="hl-card">
        <div class="hl-card-header">
          <div class="hl-card-title">📝 Ajukan Izin / Sakit</div>
        </div>
        <div class="hl-card-body">
          <div class="tipe-izin-grid">
            <button class="tipe-izin-btn active" id="tipeIzin" onclick="setTipeIzin('izin',this)">📋 Izin</button>
            <button class="tipe-izin-btn" onclick="setTipeIzin('sakit',this)">🤒 Sakit</button>
            <button class="tipe-izin-btn" onclick="setTipeIzin('cuti',this)">🏖️ Cuti</button>
          </div>
          <input type="hidden" id="f_tipe_izin" value="izin"/>
          <div class="hl-form-row" style="margin-bottom:12px">
            <div class="hl-form-group">
              <label class="hl-label">Dari Tanggal</label>
              <input type="date" id="f_izin_dari" class="hl-input"/>
            </div>
            <div class="hl-form-group">
              <label class="hl-label">Sampai Tanggal</label>
              <input type="date" id="f_izin_sampai" class="hl-input"/>
            </div>
          </div>
          <div class="hl-form-group">
            <label class="hl-label">Alasan</label>
            <textarea id="f_alasan" class="hl-input hl-textarea" placeholder="Keterangan izin..."></textarea>
          </div>
          <button class="hl-btn hl-btn-primary hl-btn-full" onclick="submitIzin()">
            📤 Ajukan
          </button>
        </div>
      </div>
    </div>

    <!-- KALENDER ABSENSI BULAN INI -->
    <div>
      <div class="hl-card" style="margin-bottom:16px">
        <div class="hl-card-header">
          <div class="hl-card-title">📅 Kalender Absensi</div>
          <div style="display:flex;gap:8px;align-items:center">
            <input type="month" id="calBulan" class="hl-input" style="width:auto;font-size:13px;padding:5px 10px"/>
            <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadKalender()">↻</button>
          </div>
        </div>
        <div class="hl-card-body">
          <!-- Stat mini -->
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:16px" id="calStats">
            <div style="text-align:center;padding:10px;background:#D1FAE5;border-radius:10px">
              <div style="font-size:1.3rem;font-weight:800;color:#065F46" id="cHadir">-</div>
              <div style="font-size:11px;color:#065F46">Hadir</div>
            </div>
            <div style="text-align:center;padding:10px;background:#FEF3C7;border-radius:10px">
              <div style="font-size:1.3rem;font-weight:800;color:#92400E" id="cIzin">-</div>
              <div style="font-size:11px;color:#92400E">Izin</div>
            </div>
            <div style="text-align:center;padding:10px;background:#DBEAFE;border-radius:10px">
              <div style="font-size:1.3rem;font-weight:800;color:#1D4ED8" id="cSakit">-</div>
              <div style="font-size:11px;color:#1D4ED8">Sakit</div>
            </div>
            <div style="text-align:center;padding:10px;background:#FEE2E2;border-radius:10px">
              <div style="font-size:1.3rem;font-weight:800;color:#991B1B" id="cAlpha">-</div>
              <div style="font-size:11px;color:#991B1B">Alpha</div>
            </div>
            <div style="text-align:center;padding:10px;background:#FEF3C7;border-radius:10px">
              <div style="font-size:1.1rem;font-weight:800;color:#92400E" id="cTelat">-</div>
              <div style="font-size:11px;color:#92400E">Total Telat</div>
            </div>
            <div style="text-align:center;padding:10px;background:#DBEAFE;border-radius:10px">
              <div style="font-size:1.1rem;font-weight:800;color:#1E40AF" id="cLembur">-</div>
              <div style="font-size:11px;color:#1E40AF">Total Lembur</div>
            </div>
          </div>
          <!-- Calendar grid -->
          <div class="absensi-cal" id="calGrid">
            <div class="cal-header">Min</div>
            <div class="cal-header">Sen</div>
            <div class="cal-header">Sel</div>
            <div class="cal-header">Rab</div>
            <div class="cal-header">Kam</div>
            <div class="cal-header">Jum</div>
            <div class="cal-header">Sab</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- TABS ADMIN -->
  <?php if (hasPermission('absensi.view') || hasPermission('absensi.approve')): ?>
  <div class="hl-tabs">
    <button class="hl-tab active" onclick="switchTab('rekap',this)">📊 Rekap Semua Karyawan</button>
    <button class="hl-tab" onclick="switchTab('izin',this)">📋 Pengajuan Izin</button>
    <?php if (hasPermission('absensi.view')): ?>
    <button class="hl-tab" onclick="switchTab('jadwal',this)">📅 Jadwal</button>
    <?php endif; ?>
  </div>

  <!-- REKAP ALL -->
  <div id="tabRekap">
    <div class="hl-filter-collapsible">
      <button class="hl-filter-toggle-btn" id="rekapFilterBtn" onclick="toggleFilter('rekapFilter')">
        📅 Periode Rekap <span class="hl-toggle-arrow">▼</span>
      </button>
      <div class="hl-filter-bar" id="rekapFilter">
        <span class="hl-filter-label">Bulan</span>
        <input type="month" id="rekapBulan" class="hl-input" style="width:auto"/>
        <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="loadRekapAll()">🔍 Tampilkan</button>
      </div>
    </div>
    <div class="hl-card">
      <div class="hl-card-header">
        <div class="hl-card-title">👥 Rekap Kehadiran Karyawan</div>
        <span id="rekapInfo" style="font-size:12px;color:var(--gray)"></span>
      </div>
      <div class="hl-table-wrap">
        <table class="hl-table hl-stack-mobile">
          <thead>
            <tr>
              <th>Nama</th>
              <th>Role</th>
              <th style="text-align:center">Hadir</th>
              <th style="text-align:center">Izin</th>
              <th style="text-align:center">Sakit</th>
              <th style="text-align:center">Alpha</th>
              <th>Total Jam</th>
              <th>Rata-rata/hari</th>
              <th style="text-align:center">Telat</th>
              <th style="text-align:center">Lembur</th>
              <th>Terakhir</th>
            </tr>
          </thead>
          <tbody id="rekapBody">
            <tr><td colspan="11" class="hl-loading">⏳ Pilih bulan dan klik Tampilkan</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- IZIN LIST -->
  <div id="tabIzin" style="display:none">
    <div class="hl-card">
      <div class="hl-card-header">
        <div class="hl-card-title">📋 Pengajuan Izin & Sakit</div>
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadIzinList()">↻ Refresh</button>
      </div>
      <div class="hl-table-wrap">
        <table class="hl-table hl-stack-mobile">
          <thead>
            <tr>
              <th>Nama</th>
              <th>Tipe</th>
              <th>Dari</th>
              <th>Sampai</th>
              <th>Alasan</th>
              <th>Status</th>
              <th>Aksi</th>
            </tr>
          </thead>
          <tbody id="izinBody">
            <tr><td colspan="7" class="hl-loading">⏳ Memuat...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- JADWAL TAB -->
  <?php if (hasPermission('absensi.view')): ?>
  <div id="tabJadwal" style="display:none">

    <!-- Kartu: Kelola Shift -->
    <div class="hl-card" style="margin-bottom:16px">
      <div class="hl-card-header">
        <div class="hl-card-title">⏱️ Kelola Shift</div>
        <button class="hl-btn hl-btn-teal hl-btn-sm" onclick="openShiftForm()">+ Tambah Shift</button>
      </div>
      <div class="hl-card-body">
        <div id="shiftList"><p style="color:var(--gray)">⏳ Memuat...</p></div>
      </div>
    </div>

    <!-- Kartu: Jadwal Mingguan -->
    <div class="hl-card">
      <div class="hl-card-header">
        <div class="hl-card-title">📆 Jadwal Mingguan Karyawan</div>
      </div>
      <div class="hl-card-body">
        <div class="hl-table-wrap">
          <div id="jadwalGrid"><p style="color:var(--gray)">⏳ Memuat grid jadwal...</p></div>
        </div>
      </div>
    </div>

  </div>

  <!-- Modal Form Shift -->
  <div id="shiftFormModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center">
    <div class="hl-card" style="width:100%;max-width:420px;margin:16px;max-height:90vh;overflow-y:auto">
      <div class="hl-card-header">
        <div class="hl-card-title">✏️ Form Shift</div>
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="document.getElementById('shiftFormModal').style.display='none'">✕ Tutup</button>
      </div>
      <div class="hl-card-body">
        <input type="hidden" id="sf_id"/>
        <div class="hl-form-group" style="margin-bottom:12px">
          <label class="hl-label">Nama Shift</label>
          <input type="text" id="sf_nama" class="hl-input" placeholder="misal: Pagi, Sore, Full" maxlength="50"/>
        </div>
        <div class="hl-form-row" style="margin-bottom:12px">
          <div class="hl-form-group">
            <label class="hl-label">Jam Mulai</label>
            <input type="time" id="sf_mulai" class="hl-input"/>
          </div>
          <div class="hl-form-group">
            <label class="hl-label">Jam Selesai</label>
            <input type="time" id="sf_selesai" class="hl-input"/>
          </div>
        </div>
        <div class="hl-form-row" style="margin-bottom:16px">
          <div class="hl-form-group">
            <label class="hl-label">Toleransi Telat (menit)</label>
            <input type="number" id="sf_tol" class="hl-input" value="15" min="0" max="120"/>
          </div>
          <div class="hl-form-group">
            <label class="hl-label">Lembur setelah (menit)</label>
            <input type="number" id="sf_lembur" class="hl-input" value="30" min="0" max="240"/>
          </div>
        </div>
        <button class="hl-btn hl-btn-primary hl-btn-full" onclick="saveShift()">💾 Simpan Shift</button>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php endif; ?>

</div>

<?php renderToast(); ?>

<?php
$abCfgRow = TenantQuery::rawOne("SELECT absensi_selfie_wajib, absensi_geofence_aktif, absensi_radius_m FROM outlets WHERE id=? AND tenant_id=? LIMIT 1", [TenantResolver::outletId(), TenantResolver::id()]) ?: [];
?>
<script>
window.ABSENSI_CFG = {
  selfie_wajib: <?= !empty($abCfgRow['absensi_selfie_wajib']) ? 'true' : 'false' ?>,
  geofence:     <?= !empty($abCfgRow['absensi_geofence_aktif']) ? 'true' : 'false' ?>,
  radius:       <?= (int)($abCfgRow['absensi_radius_m'] ?? 100) ?>
};
const IS_ADMIN = <?= (hasPermission('absensi.view') || hasPermission('absensi.approve')) ? 'true' : 'false' ?>;

// ── LIVE CLOCK ────────────────────────────────────────
function updateClock() {
  const now  = new Date();
  const time = now.toLocaleTimeString('id-ID', {hour:'2-digit',minute:'2-digit',second:'2-digit'});
  const date = now.toLocaleDateString('id-ID', {weekday:'long',day:'numeric',month:'long',year:'numeric'});
  document.getElementById('clockTime').textContent = time;
  document.getElementById('clockDate').textContent = date;
}
setInterval(updateClock, 1000);
updateClock();

// ── HELPERS ───────────────────────────────────────────
function localDateStr(d) {
  const dt = d || new Date();
  return dt.getFullYear() + '-' +
    String(dt.getMonth()+1).padStart(2,'0') + '-' +
    String(dt.getDate()).padStart(2,'0');
}
function localMonthStr(d) {
  const dt = d || new Date();
  return dt.getFullYear() + '-' + String(dt.getMonth()+1).padStart(2,'0');
}

document.addEventListener('DOMContentLoaded', () => {
  initFilter('rekapFilter');
  const today = localDateStr();
  const bulan = today.substring(0,7);
  document.getElementById('calBulan').value    = bulan;
  document.getElementById('f_izin_dari').value  = today;
  document.getElementById('f_izin_sampai').value = today;
  if (IS_ADMIN) document.getElementById('rekapBulan').value = bulan;

  loadStatusHariIni();
  loadKalender();
  loadIzinList();

  // Handover defaults
  document.getElementById('ho_tanggal').value = today;
  const h = new Date().getHours();
  document.getElementById('ho_shift').value = (h < 12 ? 'pagi' : (h < 18 ? 'sore' : 'malam'));
});

// ── HANDOVER SHIFT ────────────────────────────────────
let handoverOpen = false;
async function toggleHandover() {
  handoverOpen = !handoverOpen;
  document.getElementById('handoverBody').style.display = handoverOpen ? 'block' : 'none';
  document.getElementById('hoToggleLabel').textContent  = handoverOpen ? 'Tutup' : 'Buka Form';
  if (handoverOpen) {
    await refreshHandover();
    await loadHandoverUsers();
    await loadHandoverPending();
  }
}

async function refreshHandover() {
  const tgl = document.getElementById('ho_tanggal').value || localDateStr();
  try {
    const r = await fetch('absensi.php?action=handover_compute&tanggal=' + tgl);
    const d = await r.json();
    if (d.error) return;
    document.getElementById('ho_kas').value    = d.saldo_kas_akhir || 0;
    document.getElementById('ho_pending').value = d.order_pending || 0;
    document.getElementById('ho_siap').value    = d.order_siap_ambil || 0;
  } catch (e) {}
}

async function loadHandoverUsers() {
  try {
    const r = await fetch('absensi.php?action=list_users');
    const d = await r.json();
    if (Array.isArray(d)) {
      const sel = document.getElementById('ho_user_masuk');
      sel.innerHTML = '<option value="">— Pilih kasir penerus —</option>' +
        d.map(u => `<option value="${u.id}">${u.nama} (${u.role})</option>`).join('');
    }
  } catch (e) {}
}

async function loadHandoverPending() {
  try {
    const r = await fetch('absensi.php?action=handover_pending');
    const d = await r.json();
    const box = document.getElementById('handoverPending');
    if (!d.rows || !d.rows.length) { box.innerHTML = ''; return; }
    box.innerHTML = d.rows.map(h => `
      <div style="background:#FEF3C7;border-left:4px solid #F59E0B;padding:8px 12px;border-radius:8px;margin-bottom:6px;font-size:13px">
        ⚠️ Handover dari <strong>${h.nama_keluar || '-'}</strong> (${h.tanggal} ${h.shift})
        — Kas Rp ${parseInt(h.saldo_kas_akhir).toLocaleString('id-ID')},
        ${h.order_pending} pending, ${h.order_siap_ambil} siap ambil.
        <button class="hl-btn hl-btn-sm" style="margin-left:6px" onclick="ackHandover(${h.id})">✓ Acknowledge</button>
        ${h.catatan_khusus ? `<div style="margin-top:4px;color:#92400E"><em>Catatan: ${h.catatan_khusus}</em></div>` : ''}
      </div>`).join('');
  } catch (e) {}
}

async function ackHandover(id) {
  try {
    const r = await fetch('absensi.php?action=handover_ack', {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify({id})
    });
    const d = await r.json();
    if (d.error) { showToast(d.error, 'error'); return; }
    showToast('✓ Handover di-acknowledge');
    loadHandoverPending();
  } catch (e) { showToast('Network error','error'); }
}

async function submitHandover() {
  const body = {
    tanggal: document.getElementById('ho_tanggal').value,
    shift:   document.getElementById('ho_shift').value,
    user_id_masuk:    document.getElementById('ho_user_masuk').value || null,
    saldo_kas_akhir:  parseInt(document.getElementById('ho_kas').value)||0,
    order_pending:    parseInt(document.getElementById('ho_pending').value)||0,
    order_siap_ambil: parseInt(document.getElementById('ho_siap').value)||0,
    kondisi_mesin:    document.getElementById('ho_mesin').value,
    catatan_khusus:   document.getElementById('ho_catatan').value,
  };
  try {
    const r = await fetch('absensi.php?action=handover_save', {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify(body)
    });
    const d = await r.json();
    if (d.error) { showToast(d.error, 'error'); return; }
    showToast('✓ Handover tersimpan');
    document.getElementById('ho_mesin').value = '';
    document.getElementById('ho_catatan').value = '';
    loadHandoverPending();
  } catch (e) { showToast('Network error','error'); }
}

// ── STATUS HARI INI ───────────────────────────────────
async function loadStatusHariIni() {
  const r = await fetch('absensi.php?action=status_hari_ini');
  const d = await r.json();
  updateClockUI(d);
}

function updateClockUI(d) {
  const statusEl = document.getElementById('clockStatus');
  const inBtn    = document.getElementById('btnClockIn');
  const outBtn   = document.getElementById('btnClockOut');
  const jamInfo  = document.getElementById('jamInfo');

  if (!d || d.status === 'belum') {
    statusEl.className = 'clock-status belum';
    statusEl.textContent = '⏳ Belum Clock In';
    inBtn.disabled  = false;
    outBtn.disabled = true;
    jamInfo.style.display = 'none';
  } else if (d.jam_masuk && !d.jam_keluar) {
    statusEl.className = 'clock-status masuk';
    statusEl.textContent = '✅ Sedang Bekerja';
    inBtn.disabled  = true;
    outBtn.disabled = false;
    jamInfo.style.display = 'flex';
    document.getElementById('jamMasuk').textContent  = d.jam_masuk.substring(0,5);
    document.getElementById('jamKeluar').textContent = '-';
    document.getElementById('durasi').textContent    = '-';
  } else if (d.jam_keluar) {
    statusEl.className = 'clock-status keluar';
    statusEl.textContent = '🏁 Selesai Bekerja';
    inBtn.disabled  = true;
    outBtn.disabled = true;
    jamInfo.style.display = 'flex';
    document.getElementById('jamMasuk').textContent  = d.jam_masuk.substring(0,5);
    document.getElementById('jamKeluar').textContent = d.jam_keluar.substring(0,5);
    const dur = parseInt(d.durasi_menit||0);
    document.getElementById('durasi').textContent = Math.floor(dur/60) + 'j ' + (dur%60) + 'm';
  } else if (['izin','sakit','alpha'].includes(d.status)) {
    statusEl.className = 'clock-status belum';
    statusEl.textContent = {izin:'📋 Izin',sakit:'🤒 Sakit',alpha:'❌ Alpha'}[d.status];
    inBtn.disabled  = true;
    outBtn.disabled = true;
  }
}

// ── GPS + SELFIE HELPERS ──────────────────────────────
function getGPS() {
  return new Promise((res, rej) => {
    if (!navigator.geolocation) return rej(new Error('no-geo'));
    navigator.geolocation.getCurrentPosition(res, rej, { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });
  });
}
function captureSelfie() {
  return new Promise((resolve, reject) => {
    const inp = document.getElementById('selfieFile');
    inp.value = '';
    let settled = false;
    const done = (fn, v) => { if (!settled) { settled = true; window.removeEventListener('focus', onCancel); fn(v); } };
    // Deteksi batal: saat kamera/picker ditutup, window dapat focus lagi; kalau tak ada file → batal
    function onCancel() { setTimeout(() => { if (!settled && !(inp.files && inp.files[0])) done(reject, new Error('dibatalkan')); }, 800); }
    inp.onchange = async () => {
      const f = inp.files && inp.files[0];
      if (!f) return done(reject, new Error('no-file'));
      try {
        const fd = new FormData(); fd.append('foto', f);
        const r = await fetch('absensi.php?action=upload_selfie', { method:'POST', headers:{'X-CSRF-Token':csrfToken()}, body: fd });
        const d = await r.json();
        if (d.path) done(resolve, d.path); else done(reject, new Error(d.error || 'upload gagal'));
      } catch (e) { done(reject, new Error('koneksi')); }
    };
    window.addEventListener('focus', onCancel);
    inp.click();
  });
}

// ── CLOCK IN/OUT ──────────────────────────────────────
async function clockIn() {
  const cfg = window.ABSENSI_CFG || {};
  const btn = document.getElementById('btnClockIn');
  let lat = null, lng = null, selfiePath = null;

  if (cfg.geofence) {
    try { const pos = await getGPS(); lat = pos.coords.latitude; lng = pos.coords.longitude; }
    catch (e) { showToast('Aktifkan izin lokasi (GPS) untuk clock-in', 'error'); return; }
  }
  if (cfg.selfie_wajib) {
    try { showToast('📸 Ambil selfie…', 'info'); selfiePath = await captureSelfie(); }
    catch (e) { showToast('Selfie wajib untuk clock-in' + (e.message ? ': ' + e.message : ''), 'error'); return; }
  }

  btn.disabled = true; btn.textContent = '⏳...';
  try {
    const r = await fetch('absensi.php?action=clock_in', {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
      body: JSON.stringify({ lat: lat, lng: lng, selfie_path: selfiePath })
    });
    const d = await r.json();
    if (d.success) {
      showToast('✅ Clock In berhasil! Jam ' + d.jam, 'success');
      loadStatusHariIni(); loadKalender();
    } else {
      showToast('❌ ' + (d.error || 'Gagal'), 'error');
      btn.disabled = false;
    }
  } catch (e) {
    showToast('❌ Gagal koneksi, coba lagi', 'error');
    btn.disabled = false;
  }
  btn.textContent = '▶ Clock In';
}

async function clockOut() {
  if (!await lmConfirm('Yakin clock out sekarang?')) return;
  const btn = document.getElementById('btnClockOut');
  btn.disabled = true; btn.textContent = '⏳...';

  const r = await fetch('absensi.php?action=clock_out', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({})
  });
  const d = await r.json();
  if (d.success) {
    showToast('✅ Clock Out! Durasi kerja: ' + d.durasi, 'success');
    loadStatusHariIni();
    loadKalender();
  } else {
    showToast('❌ ' + (d.error||'Gagal'), 'error');
    btn.disabled = false;
  }
  btn.textContent = '■ Clock Out';
}

// ── KALENDER ──────────────────────────────────────────
async function loadKalender() {
  const bulan = document.getElementById('calBulan').value;
  if (!bulan) return;

  const r = await fetch('absensi.php?action=rekap_personal&bulan=' + bulan);
  const d = await r.json();

  document.getElementById('cHadir').textContent = d.summary.hadir;
  document.getElementById('cIzin').textContent  = d.summary.izin;
  document.getElementById('cSakit').textContent = d.summary.sakit;
  document.getElementById('cAlpha').textContent = d.summary.alpha;

  // Telat/lembur totals
  let totalTelat = 0, totalLembur = 0;
  d.data.forEach(row => { totalTelat += parseInt(row.telat_menit)||0; totalLembur += parseInt(row.lembur_menit)||0; });
  const elTelat = document.getElementById('cTelat');
  const elLembur = document.getElementById('cLembur');
  if (elTelat)  elTelat.textContent  = totalTelat  ? totalTelat  + 'm' : '0m';
  if (elLembur) elLembur.textContent = totalLembur ? totalLembur + 'm' : '0m';

  const [y,m] = bulan.split('-').map(Number);
  const firstDay = new Date(y, m-1, 1).getDay();
  const daysInMonth = new Date(y, m, 0).getDate();
  const today = localDateStr();

  const statusMap = {};
  const selfieMap = {};
  const telatMap  = {};
  const lemburMap = {};
  d.data.forEach(row => {
    statusMap[row.tanggal] = row.status;
    if (row.selfie_masuk) selfieMap[row.tanggal] = row.selfie_masuk;
    if (parseInt(row.telat_menit)  > 0) telatMap[row.tanggal]  = parseInt(row.telat_menit);
    if (parseInt(row.lembur_menit) > 0) lemburMap[row.tanggal] = parseInt(row.lembur_menit);
  });

  const cal = document.getElementById('calGrid');
  while (cal.children.length > 7) cal.removeChild(cal.lastChild);

  for (let i = 0; i < firstDay; i++) {
    const empty = document.createElement('div');
    empty.className = 'cal-day empty';
    cal.appendChild(empty);
  }

  for (let day = 1; day <= daysInMonth; day++) {
    const dateStr = `${y}-${String(m).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
    const status  = statusMap[dateStr];
    const isToday = dateStr === today;
    const isSun   = new Date(dateStr).getDay() === 0;
    const selfie  = selfieMap[dateStr];
    const telat   = telatMap[dateStr]  || 0;
    const lembur  = lemburMap[dateStr] || 0;

    const el = document.createElement('div');
    el.className = 'cal-day ' + (status || (isSun ? 'libur' : '')) + (isToday ? ' today' : '');
    el.innerHTML = `<span>${day}</span>${status ? '<div class="cal-dot"></div>' : ''}${selfie ? `<a href="/${esc(selfie)}" target="_blank" title="Lihat selfie" style="font-size:10px;line-height:1;text-decoration:none">🤳</a>` : ''}${telat>0?`<span style="background:#FEF3C7;color:#92400E;font-size:9px;padding:1px 4px;border-radius:6px">telat ${telat}m</span>`:''}${lembur>0?`<span style="background:#DBEAFE;color:#1E40AF;font-size:9px;padding:1px 4px;border-radius:6px">+${lembur}m</span>`:''}`;
    el.title     = status ? statusLabel(status) : dateStr;
    cal.appendChild(el);
  }
}

// ── IZIN ──────────────────────────────────────────────
function setTipeIzin(tipe, el) {
  document.getElementById('f_tipe_izin').value = tipe;
  document.querySelectorAll('.tipe-izin-btn').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
}

async function submitIzin() {
  const payload = {
    tipe:   document.getElementById('f_tipe_izin').value,
    dari:   document.getElementById('f_izin_dari').value,
    sampai: document.getElementById('f_izin_sampai').value,
    alasan: document.getElementById('f_alasan').value,
  };
  if (!payload.dari || !payload.sampai) { showToast('⚠️ Tanggal wajib diisi','error'); return; }
  if (!payload.alasan.trim()) { showToast('⚠️ Alasan wajib diisi','error'); return; }

  const r = await fetch('absensi.php?action=input_izin', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify(payload)
  });
  const d = await r.json();
  if (d.success) {
    showToast('✅ Pengajuan berhasil dikirim!', 'success');
    document.getElementById('f_alasan').value = '';
    loadKalender();
    loadIzinList();
  } else {
    showToast('❌ ' + (d.error||'Gagal'), 'error');
  }
}

// ── REKAP ALL (ADMIN) ─────────────────────────────────
async function loadRekapAll() {
  if (!IS_ADMIN) return;
  const bulan = document.getElementById('rekapBulan').value;
  document.getElementById('rekapBody').innerHTML = '<tr><td colspan="11" class="hl-loading">⏳ Memuat...</td></tr>';

  const r = await fetch('absensi.php?action=rekap_all&bulan=' + bulan);
  const d = await r.json();

  if (!d.data?.length) {
    document.getElementById('rekapBody').innerHTML = '<tr><td colspan="11" class="hl-empty">Belum ada data</td></tr>';
    return;
  }

  const maxMenit = Math.max(...d.data.map(x => parseInt(x.total_menit)||0), 1);

  document.getElementById('rekapBody').innerHTML = d.data.map(row => {
    const menit   = parseInt(row.total_menit)||0;
    const jam     = Math.floor(menit/60);
    const hadir   = parseInt(row.hadir)||0;
    const rataMin = hadir > 0 ? Math.round(menit/hadir) : 0;
    const rataStr = hadir > 0 ? Math.floor(rataMin/60) + 'j ' + (rataMin%60) + 'm' : '-';
    const pct     = Math.round((menit/maxMenit)*100);
    const telat   = parseInt(row.total_telat)||0;
    const lembur  = parseInt(row.total_lembur)||0;
    return `<tr>
      <td data-lbl="Nama" style="font-weight:600;color:var(--navy)">${esc(row.nama)}</td>
      <td data-lbl="Role"><span class="hl-badge hl-badge-gray" style="font-size:10px">${row.role}</span></td>
      <td data-lbl="Hadir" style="text-align:center"><span style="font-weight:700;color:var(--green)">${row.hadir}</span></td>
      <td data-lbl="Izin" style="text-align:center"><span style="font-weight:700;color:var(--yellow)">${row.izin}</span></td>
      <td data-lbl="Sakit" style="text-align:center"><span style="font-weight:700;color:var(--blue)">${row.sakit}</span></td>
      <td data-lbl="Alpha" style="text-align:center"><span style="font-weight:700;color:var(--red)">${row.alpha}</span></td>
      <td data-lbl="Total Jam">
        <div style="font-family:var(--mono);font-size:13px;font-weight:600">${jam}j ${menit%60}m</div>
        <div class="durasi-bar"><div class="durasi-fill" style="width:${pct}%"></div></div>
      </td>
      <td data-lbl="Rata/hari" style="font-size:13px;color:var(--gray)">${rataStr}</td>
      <td data-lbl="Telat" style="text-align:center">${telat > 0 ? '<span class="hl-badge" style="background:#FEE2E2;color:#991B1B;font-size:11px">' + telat + 'm</span>' : '-'}</td>
      <td data-lbl="Lembur" style="text-align:center">${lembur > 0 ? '<span class="hl-badge" style="background:#D1FAE5;color:#065F46;font-size:11px">' + lembur + 'm</span>' : '-'}</td>
      <td data-lbl="Terakhir" style="font-size:12px;color:var(--gray)">${row.last_absen ? fmtDate(row.last_absen) : '-'}</td>
    </tr>`;
  }).join('');
  document.getElementById('rekapInfo').textContent = d.data.length + ' karyawan · ' + d.periode.bulan;
}

// ── IZIN LIST ─────────────────────────────────────────
async function loadIzinList() {
  const r = await fetch('absensi.php?action=list_izin');
  const d = await r.json();
  const el = document.getElementById('izinBody');
  if (!el) return;

  if (!d.length) {
    el.innerHTML = '<tr><td colspan="7" class="hl-empty">Belum ada pengajuan izin</td></tr>';
    return;
  }

  const tipeBadge = {izin:'📋 Izin',sakit:'🤒 Sakit',cuti:'🏖️ Cuti'};
  const statusBadge = {
    pending:'<span class="hl-badge" style="background:#FEF3C7;color:#92400E">⏳ Pending</span>',
    approved:'<span class="hl-badge hl-badge-green">✅ Approved</span>',
    rejected:'<span class="hl-badge hl-badge-red">❌ Ditolak</span>',
  };

  el.innerHTML = d.map(row => `<tr>
    <td data-lbl="Nama" style="font-weight:600">${esc(row.nama)}</td>
    <td data-lbl="Tipe"><span class="hl-badge hl-badge-gray">${tipeBadge[row.tipe]||row.tipe}</span></td>
    <td data-lbl="Dari" style="font-size:13px">${fmtDate(row.dari_tanggal)}</td>
    <td data-lbl="Sampai" style="font-size:13px">${fmtDate(row.sampai_tanggal)}</td>
    <td data-lbl="Alasan" style="font-size:13px;max-width:180px;color:var(--gray)">${esc(row.alasan||'-')}</td>
    <td data-lbl="Status">${statusBadge[row.status]||row.status}</td>
    <td>
      ${IS_ADMIN && row.status==='pending' ? `
        <div style="display:flex;gap:4px">
          <button class="hl-btn hl-btn-green hl-btn-sm" onclick="approveIzin(${row.id},'approved')">✅ Approve</button>
          <button class="hl-btn hl-btn-danger hl-btn-sm" onclick="approveIzin(${row.id},'rejected')">❌ Tolak</button>
        </div>` : '-'}
    </td>
  </tr>`).join('');
}

async function approveIzin(id, status) {
  const r = await fetch('absensi.php?action=approve_izin', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({id, status})
  });
  const d = await r.json();
  if (d.success) {
    showToast(status==='approved' ? '✅ Izin disetujui' : '❌ Izin ditolak', 'success');
    loadIzinList();
  }
}

// ── TABS ──────────────────────────────────────────────
function switchTab(name, el) {
  document.getElementById('tabRekap').style.display   = name==='rekap'   ? 'block' : 'none';
  document.getElementById('tabIzin').style.display    = name==='izin'    ? 'block' : 'none';
  const tabJadwal = document.getElementById('tabJadwal');
  if (tabJadwal) tabJadwal.style.display = name==='jadwal' ? 'block' : 'none';
  document.querySelectorAll('.hl-tab').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  if (name==='rekap')  loadRekapAll();
  if (name==='izin')   loadIzinList();
  if (name==='jadwal') loadShifts();
}

// ── KELOLA SHIFT ──────────────────────────────────────
const HARI = {1:'Sen',2:'Sel',3:'Rab',4:'Kam',5:'Jum',6:'Sab',7:'Min'};

async function loadShifts() {
  const r = await fetch('absensi.php?action=shift_list');
  const list = await r.json();
  const box = document.getElementById('shiftList');
  if (!list.length) {
    box.innerHTML = '<p style="color:var(--gray)">Belum ada shift. <button class="hl-btn hl-btn-teal-sm" onclick="seedTemplate()">Buat template (Pagi/Sore/Full)</button></p>';
    window._shifts = [];
    return;
  }
  window._shifts = list;
  box.innerHTML = list.map(s => `<div style="display:flex;justify-content:space-between;align-items:center;padding:8px;border-bottom:1px solid var(--light)">
    <span><b>${esc(s.nama)}</b> ${s.jam_mulai.substring(0,5)}–${s.jam_selesai.substring(0,5)} · tol ${s.toleransi_telat_menit}m · lembur >${s.lembur_after_menit}m</span>
    <span><button class="hl-btn hl-btn-outline hl-btn-sm" onclick="editShift(${s.id})">Edit</button>
    <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="deleteShift(${s.id})">Hapus</button></span></div>`).join('');
  renderJadwalGrid();
}

async function seedTemplate() {
  const r = await fetch('absensi.php?action=shift_seed_template', {
    method: 'POST',
    headers: {'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: '{}'
  });
  const d = await r.json();
  if (d.success) { showToast('Template dibuat', 'success'); loadShifts(); }
  else showToast(d.error || 'Gagal', 'error');
}

function editShift(id) {
  const s = (window._shifts || []).find(x => x.id == id);
  if (s) openShiftForm(s);
}

function openShiftForm(s) {
  s = s || {};
  document.getElementById('sf_id').value      = s.id || '';
  document.getElementById('sf_nama').value    = s.nama || '';
  document.getElementById('sf_mulai').value   = (s.jam_mulai || '').substring(0,5);
  document.getElementById('sf_selesai').value = (s.jam_selesai || '').substring(0,5);
  document.getElementById('sf_tol').value     = s.toleransi_telat_menit ?? 15;
  document.getElementById('sf_lembur').value  = s.lembur_after_menit ?? 30;
  document.getElementById('shiftFormModal').style.display = 'flex';
}

async function saveShift() {
  const body = {
    id:                     document.getElementById('sf_id').value || null,
    nama:                   document.getElementById('sf_nama').value,
    jam_mulai:              document.getElementById('sf_mulai').value,
    jam_selesai:            document.getElementById('sf_selesai').value,
    toleransi_telat_menit:  +document.getElementById('sf_tol').value,
    lembur_after_menit:     +document.getElementById('sf_lembur').value,
  };
  const r = await fetch('absensi.php?action=shift_save', {
    method: 'POST',
    headers: {'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify(body)
  });
  const d = await r.json();
  if (d.success) {
    showToast('Shift tersimpan', 'success');
    document.getElementById('shiftFormModal').style.display = 'none';
    loadShifts();
  } else showToast(d.error || 'Gagal', 'error');
}

async function deleteShift(id) {
  if (!await lmConfirm('Hapus shift ini?')) return;
  const r = await fetch('absensi.php?action=shift_delete', {
    method: 'POST',
    headers: {'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({id})
  });
  const d = await r.json();
  if (d.success) { showToast('Dihapus', 'success'); loadShifts(); }
  else showToast(d.error || 'Gagal', 'error');
}

// ── GRID JADWAL MINGGUAN ──────────────────────────────
async function renderJadwalGrid() {
  const [uRes, jRes] = await Promise.all([
    fetch('absensi.php?action=list_users'),
    fetch('absensi.php?action=jadwal_get')
  ]);
  const users = await uRes.json();
  const jad   = await jRes.json();
  const map   = {};
  jad.forEach(j => { map[j.user_id + '_' + j.hari] = j.shift_id; });
  const shifts = window._shifts || [];
  const opts = sel => '<option value="0">Libur</option>' +
    shifts.map(s => `<option value="${s.id}" ${s.id==sel?'selected':''}>${esc(s.nama)}</option>`).join('');
  let html = '<table class="hl-table"><thead><tr><th>Karyawan</th>' +
    [1,2,3,4,5,6,7].map(h => `<th>${HARI[h]}</th>`).join('') +
    '</tr></thead><tbody>';
  html += users.map(u =>
    `<tr><td>${esc(u.nama)}</td>` +
    [1,2,3,4,5,6,7].map(h =>
      `<td><select onchange="saveJadwal(${u.id},${h},this.value)">${opts(map[u.id+'_'+h]||0)}</select></td>`
    ).join('') + '</tr>'
  ).join('');
  html += '</tbody></table>';
  document.getElementById('jadwalGrid').innerHTML = users.length
    ? html
    : '<p style="color:var(--gray)">Belum ada karyawan di outlet ini.</p>';
}

async function saveJadwal(uid, hari, shiftId) {
  const r = await fetch('absensi.php?action=jadwal_save', {
    method: 'POST',
    headers: {'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({user_id: uid, hari: hari, shift_id: +shiftId})
  });
  const d = await r.json();
  if (d.success) showToast('Jadwal disimpan', 'success');
  else showToast(d.error || 'Gagal', 'error');
}

function statusLabel(s){return{hadir:'✅ Hadir',izin:'📋 Izin',sakit:'🤒 Sakit',alpha:'❌ Alpha'}[s]||s}
function fmtDate(d){if(!d)return'-';return new Date(d+'T00:00:00').toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'})}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
function showToast(msg,type='success'){const t=document.getElementById('toast');t.textContent=msg;t.className='hl-toast '+type+' show';setTimeout(()=>t.className='hl-toast',3500)}
</script>
</body>
</html>

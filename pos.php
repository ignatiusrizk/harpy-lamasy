<?php
$activePage = 'pos';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/Loyalty.php';
require_once ROOT . '/core/BiayaLainnyaTier.php';
require_once ROOT . '/core/ExpressTier.php';
require_once ROOT . '/core/MemberTier.php';
require_once ROOT . '/core/NotaFormatter.php';
require_once ROOT . '/core/DepositManager.php';
require_once ROOT . '/core/PushSender.php';
require_once ROOT . '/core/Referral.php';
require_once __DIR__ . '/components.php';
$user = currentUser();
requirePermission('pos.view');
$loyaltyCfg  = Loyalty::config((int)TenantResolver::id());
$referralCfg = Referral::config((int)TenantResolver::id());

// ── API HANDLER ───────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action === '') { header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'); header('Pragma: no-cache'); }
if ($action) {
    header('Content-Type: application/json');
    $tid = TenantResolver::id();

    // GET layanan list
    $oid = TenantResolver::outletId();

    if ($action === 'get_layanan') {
        $rows = TenantQuery::raw(
            "SELECT l.*,
                    COALESCE(freq.cnt, 0) AS freq_30d
             FROM hl_layanan l
             LEFT JOIN (
                 SELECT ti.layanan_id, COUNT(*) AS cnt
                   FROM hl_transaksi_item ti
                   JOIN hl_transaksi t ON t.id = ti.transaksi_id AND t.tenant_id = ti.tenant_id
                  WHERE ti.tenant_id = ? AND t.outlet_id = ? AND ti.layanan_id IS NOT NULL
                    AND t.tanggal >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                  GROUP BY ti.layanan_id
             ) freq ON freq.layanan_id = l.id
             WHERE l.tenant_id=? AND l.outlet_id=? AND l.is_active=1
             ORDER BY l.is_pinned DESC,
                      (CASE WHEN l.is_pinned=1 THEN l.urutan ELSE 0 END) ASC,
                      freq_30d DESC, l.nama ASC",
            [$tid, $oid, $tid, $oid]
        );
        echo json_encode($rows); exit;
    }

    // Ambil tier express utk tenant + outlet ini (dipakai POS dropdown per item)
    // Filter outlet: tier global (NULL) + tier khusus outlet ini.
    if ($action === 'express_tiers') {
        echo json_encode(['tiers' => ExpressTier::forTenant($tid, $oid)]); exit;
    }

    if ($action === 'biaya_lainnya_tiers') {
        echo json_encode(['tiers' => BiayaLainnyaTier::activeForTenant($tid, $oid)]); exit;
    }

    // Load parfum list utk outlet ini (global + outlet-specific)
    if ($action === 'parfum_list') {
        try {
            $st = Database::get()->prepare(
                "SELECT nama FROM hl_parfum
                  WHERE tenant_id=? AND is_active=1
                    AND (outlet_id IS NULL OR outlet_id = ?)
                  ORDER BY urutan ASC, nama ASC"
            );
            $st->execute([$tid, $oid]);
            echo json_encode(['parfums' => array_column($st->fetchAll(PDO::FETCH_ASSOC), 'nama')]);
        } catch (Throwable $e) {
            ErrorLogger::logException('db_error', $e, (int)TenantResolver::id());
            echo json_encode(['parfums'=>[]]);
        }
        exit;
    }

    // Cek apakah pelanggan punya membership aktif (utk POS badge & auto-diskon preview)
    if ($action === 'check_member') {
        $pid = (int)($_GET['pelanggan_id'] ?? 0);
        if ($pid <= 0) { echo json_encode(['member' => null]); exit; }
        $mem = MemberTier::activeForPelanggan($tid, $pid);
        echo json_encode(['member' => $mem]); exit;
    }

    // Cek saldo deposit pelanggan (utk badge & preview "Bayar pakai Saldo")
    if ($action === 'check_deposit') {
        $pid = (int)($_GET['pelanggan_id'] ?? 0);
        if ($pid <= 0) { echo json_encode(['balance' => 0]); exit; }
        echo json_encode(['balance' => DepositManager::balance($tid, $pid)]);
        exit;
    }

    // SEARCH pelanggan — TENANT-SCOPED (lintas outlet)
    // Pelanggan adalah aset account, bisa transaksi di outlet manapun
    // ── action=estimasi_suggest: hitung estimasi jam berdasarkan antrian saat ini ──
    if ($action === 'estimasi_suggest') {
        try {
            $stmt = Database::get()->prepare(
                "SELECT COUNT(*) FROM hl_transaksi
                  WHERE tenant_id=? AND outlet_id=?
                    AND status_proses NOT IN ('siap','diambil','selesai','batal','dibatalkan')"
            );
            $stmt->execute([$tid, $oid]);
            $antrian = (int)$stmt->fetchColumn();
            $jam = 24;
            if ($antrian > 20) $jam = 36;
            if ($antrian > 40) $jam = 48;
            $datetime = date('Y-m-d H:i:s', strtotime("+{$jam} hours"));
            $tanggalOnly = date('Y-m-d', strtotime("+{$jam} hours"));
            $hari = ['Min','Sen','Sel','Rab','Kam','Jum','Sab'][(int)date('w', strtotime($datetime))];
            $isToday = $tanggalOnly === date('Y-m-d');
            $isTomorrow = $tanggalOnly === date('Y-m-d', strtotime('+1 day'));
            $label = ($isToday ? 'Hari ini' : ($isTomorrow ? 'Besok' : "$hari ".date('d M', strtotime($datetime))))
                   . ' jam ' . date('H:i', strtotime($datetime));
            echo json_encode([
                'ok'=>true, 'antrian'=>$antrian, 'jam'=>$jam,
                'datetime'=>$datetime, 'date_only'=>$tanggalOnly,
                'label'=>$label,
            ]);
        } catch (Throwable $e) { apiErr($e); }
        exit;
    }

    if ($action === 'search_pelanggan') {
        $q = '%' . trim($_GET['q'] ?? '') . '%';
        $rows = TenantQuery::raw(
            "SELECT p.*,
                    (SELECT nama_outlet FROM outlets WHERE id=p.registered_outlet_id AND tenant_id=p.tenant_id) AS registered_at_outlet
             FROM hl_pelanggan p
             WHERE p.tenant_id=? AND (p.nama LIKE ? OR p.telepon LIKE ?) AND p.is_active=1
             ORDER BY (p.registered_outlet_id = ?) DESC, p.total_visit_count DESC
             LIMIT 8",
            [$tid, $q, $q, $oid]
        );
        echo json_encode($rows); exit;
    }

    // INFO POIN + REWARD untuk pelanggan terpilih
    if ($action === 'pelanggan_poin') {
        $pid = intval($_GET['id'] ?? 0);
        if (!$pid) { echo json_encode(['error'=>'id wajib']); exit; }
        try {
            $db = Database::get();
            $st = $db->prepare("SELECT id, nama, tier, segmen, poin_balance, catatan_tetap,
                                       preferensi_parfum, preferensi_suhu
                                  FROM hl_pelanggan WHERE id=? AND tenant_id=?");
            $st->execute([$pid, $tid]);
            $pel = $st->fetch(PDO::FETCH_ASSOC);
            if (!$pel) { echo json_encode(['error'=>'Pelanggan tidak ditemukan']); exit; }

            $poin = (int)$pel['poin_balance'];
            $rewards = Loyalty::availableRewards($tid, $oid, $poin);
            echo json_encode([
                'ok' => true,
                'pelanggan' => [
                    'id'       => (int)$pel['id'],
                    'nama'     => $pel['nama'],
                    'tier'     => $pel['tier'] ?? 'regular',
                    'segmen'   => $pel['segmen'] ?? 'baru',
                    'poin'     => $poin,
                    'catatan_tetap'      => $pel['catatan_tetap'] ?? '',
                    'preferensi_parfum'  => $pel['preferensi_parfum'] ?? '',
                    'preferensi_suhu'    => $pel['preferensi_suhu'] ?? '',
                ],
                'rewards' => $rewards,
                'config'  => [
                    'enabled'    => Loyalty::isEnabled($tid),
                    'poin_value' => $loyaltyCfg['poin_value'],
                ],
            ]);
        } catch (Throwable $e) {
            apiErr($e);
        }
        exit;
    }

    // SAVE transaksi
    // UPLOAD FOTO KONDISI CUCIAN (multipart) — returns relative path
    if ($action === 'upload_foto' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hasPermission('pos.create')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        require_once ROOT . '/core/FileUpload.php';
        $f = $_FILES['foto'] ?? null;
        if (!$f) { echo json_encode(['error'=>'File foto tidak ditemukan']); exit; }
        $res = FileUpload::uploadImage($f, 'uploads/foto_masuk', 't' . $tid . '_o' . $oid);
        if ($res['error']) { echo json_encode(['error'=>$res['error']]); exit; }
        echo json_encode(['ok'=>true, 'path'=>$res['path']]);
        exit;
    }

    // ── Snapshot katalog untuk cache offline klien ──
    if ($action === 'catalog_snapshot') {
        $layanan = TenantQuery::raw(
            "SELECT id, nama, kategori, harga, satuan FROM hl_layanan WHERE tenant_id=? AND outlet_id=? AND is_active=1 ORDER BY nama",
            [$tid, $oid]
        );
        $tier = ExpressTier::forTenant($tid, $oid);
        $pel  = TenantQuery::raw(
            "SELECT id, nama, telepon FROM hl_pelanggan WHERE tenant_id=? AND outlet_id=? ORDER BY id DESC LIMIT 200",
            [$tid, $oid]
        );
        echo json_encode(['layanan' => $layanan, 'tier' => $tier, 'pelanggan' => $pel]);
        exit;
    }

    // ── Sync batch order offline ──
    if ($action === 'sync_offline' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hasPermission('pos.create')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        require_once __DIR__ . '/core/OrderCreator.php';
        $body   = json_decode(file_get_contents('php://input'), true);
        $orders = is_array($body['orders'] ?? null) ? $body['orders'] : [];
        $db     = Database::get();
        $results = [];
        foreach ($orders as $o) {
            $uuid = (string)($o['uuid'] ?? '');
            if ($uuid === '') continue;
            $payload             = is_array($o['payload'] ?? null) ? $o['payload'] : [];
            $payload['uuid']     = $uuid;
            $payload['tempCode'] = (string)($o['tempCode'] ?? '');
            try {
                $results[$uuid] = OrderCreator::createOffline($db, $tid, $oid, $user, $payload);
            } catch (Throwable $e) {
                ErrorLogger::logException('sync_offline', $e, $tid, $oid);
                $results[$uuid] = ['ok' => false, 'error' => 'Gagal proses'];
            }
        }
        echo json_encode(['results' => $results]);
        exit;
    }

    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hasPermission('pos.create')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $data  = json_decode(file_get_contents('php://input'), true);
        $items = $data['items'] ?? [];
        if (empty($items))      { echo json_encode(['error'=>'Minimal 1 item']); exit; }
        if (count($items) > 30) { echo json_encode(['error'=>'Maksimal 30 item per order']); exit; }

        // Sanitize
        $nama_pel  = substr(trim(strip_tags($data['nama_pelanggan'] ?? '')), 0, 100);
        $telepon   = substr(preg_replace('/[^0-9+\-\s]/', '', $data['telepon'] ?? ''), 0, 20);
        $catatan   = substr(trim(strip_tags($data['catatan'] ?? '')), 0, 500);
        $parfum       = substr(trim(strip_tags($data['parfum']  ?? '')), 0, 50);
        $referralKode = substr(trim(strip_tags($data['referral_code'] ?? '')), 0, 50);
        $tanggal   = substr(trim($data['tanggal'] ?? date('Y-m-d')), 0, 10);
        // Estimasi selesai — terima DATE (yyyy-mm-dd) atau DATETIME, normalisasi ke DATETIME.
        // Kalau kosong, auto-compute dari antrian saat ini.
        $estRaw = trim($data['estimasi'] ?? '');
        if ($estRaw === '') {
            // Auto: hitung dari antrian
            try {
                $q = Database::get()->prepare("SELECT COUNT(*) FROM hl_transaksi
                      WHERE tenant_id=? AND outlet_id=?
                        AND status_proses NOT IN ('siap','diambil','selesai','batal','dibatalkan')");
                $q->execute([$tid, $oid]);
                $antrian = (int)$q->fetchColumn();
            } catch (Throwable) { $antrian = 0; }
            $estimasiJam = $antrian > 40 ? 48 : ($antrian > 20 ? 36 : 24);
            $estimasi    = date('Y-m-d H:i:s', strtotime("+{$estimasiJam} hours"));
        } else {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $estRaw)) {
                // Tanggal saja → default jam 14:00
                $estimasi    = $estRaw . ' 14:00:00';
            } else {
                $estimasi    = substr($estRaw, 0, 19);
            }
            $estimasiJam = max(1, (int)round((strtotime($estimasi) - time()) / 3600));
        }

        if (!$nama_pel) { echo json_encode(['error'=>'Nama pelanggan wajib diisi']); exit; }
        // Nomor HP wajib/opsional diatur per-outlet (Outlet & Nota Settings,
        // kolom outlets.telepon_wajib, default 0/opsional). Kalau opsional &
        // kosong, order tetap tersimpan, cuma skip link/create hl_pelanggan
        // (lihat blok upsert di bawah).
        if (!$telepon) {
            $wajibRow = TenantQuery::rawOne("SELECT telepon_wajib FROM outlets WHERE id=? AND tenant_id=?", [$oid, $tid]);
            if (!empty($wajibRow['telepon_wajib'])) {
                echo json_encode(['error'=>'Nomor HP pelanggan wajib diisi (diaktifkan di Outlet & Nota Settings)']); exit;
            }
        }

        // Validasi items
        foreach ($items as $item) {
            if (floatval($item['jumlah'] ?? 0) <= 0)      { echo json_encode(['error'=>'Jumlah item harus lebih dari 0']); exit; }
            if (floatval($item['harga_satuan'] ?? 0) < 0)  { echo json_encode(['error'=>'Harga tidak boleh negatif']); exit; }
            if (empty($item['nama_layanan']))               { echo json_encode(['error'=>'Nama layanan tidak boleh kosong']); exit; }
        }

        // Validasi minimum order per layanan (kalau layanan-nya punya qty_minimum > 0)
        try {
            $db = Database::get();
            foreach ($items as $item) {
                $lid = (int)($item['layanan_id'] ?? 0);
                if ($lid <= 0) continue;
                $minRow = $db->prepare("SELECT qty_minimum, satuan, nama FROM hl_layanan WHERE id=? AND tenant_id=? LIMIT 1");
                $minRow->execute([$lid, $tid]);
                $row = $minRow->fetch(PDO::FETCH_ASSOC);
                if (!$row) continue;
                $qMin = (float)($row['qty_minimum'] ?? 0);
                if ($qMin > 0 && (float)$item['jumlah'] < $qMin) {
                    echo json_encode([
                        'error' => "Jumlah '{$row['nama']}' di bawah minimum order ({$qMin} {$row['satuan']})"
                    ]); exit;
                }
            }
        } catch (Throwable) { /* kolom qty_minimum belum ada — skip check */ }

        $db = Database::get();
        $db->beginTransaction();
        try {
            // Generate no order pakai NotaFormatter (template per-tenant)
            $no = NotaFormatter::next($tid, $oid, $tanggal);

            // Hitung total
            $subtotal = 0;
            foreach ($items as $item) {
                $subtotal += floatval($item['jumlah']) * floatval($item['harga_satuan']);
            }
            $diskon     = floatval($data['diskon'] ?? 0);
            $redeemPoin = max(0, (int)($data['redeem_poin'] ?? 0));
            // total/dp/status dihitung SETELAH pel_id + redeem diketahui

            // Upsert pelanggan — TENANT-SCOPED (lintas outlet)
            // Lookup by tenant_id + telepon (HP unique per tenant)
            $pel_id        = null;
            $isNewPelanggan = false;
            if ($nama_pel && $telepon !== '') {
                $pelRow = TenantQuery::rawOne(
                    "SELECT id FROM hl_pelanggan WHERE tenant_id=? AND telepon=? LIMIT 1",
                    [$tid, $telepon]
                );
                if ($pelRow) {
                    // Sudah pernah daftar (di outlet manapun) — increment visit count
                    $pel_id = $pelRow['id'];
                    $db->prepare(
                        "UPDATE hl_pelanggan
                            SET total_order = total_order + 1,
                                total_visit_count = total_visit_count + 1
                          WHERE id = ? AND tenant_id = ?"
                    )->execute([$pel_id, $tid]);
                } else {
                    // Pelanggan baru — catat outlet pertama daftar
                    TenantQuery::insert('hl_pelanggan', [
                        'nama'                  => $nama_pel,
                        'telepon'               => $telepon,
                        'tipe'                  => 'retail',
                        'total_order'           => 1,
                        'total_visit_count'     => 1,
                        'registered_outlet_id'  => $oid,
                        'outlet_id'             => $oid, // legacy compat
                        'portal_token'          => bin2hex(random_bytes(16)),
                    ]);
                    $pel_id         = $db->lastInsertId();
                    $isNewPelanggan = true;
                }
            }

            // ── Loyalty redeem (poin → diskon) — hitung nilai dulu, deduct setelah insert ──
            $redeemValue = 0;
            $rewardId    = max(0, (int)($data['reward_id'] ?? 0));

            if (($redeemPoin > 0 || $rewardId > 0) && $pel_id && Loyalty::isEnabled($tid)) {
                $cfg = Loyalty::config($tid);
                $balPoin   = Loyalty::balance($tid, (int)$pel_id);
                $maxRupiah = max(0, $subtotal - $diskon);

                if ($rewardId > 0) {
                    // Validate reward applicable di outlet ini via junction
                    $reward = TenantQuery::rawOne(
                        "SELECT r.* FROM hl_poin_reward r
                          WHERE r.id=? AND r.tenant_id=? AND r.is_active=1
                            AND (NOT EXISTS (SELECT 1 FROM hl_poin_reward_outlet WHERE reward_id=r.id)
                                 OR EXISTS (SELECT 1 FROM hl_poin_reward_outlet WHERE reward_id=r.id AND outlet_id=?))
                          LIMIT 1",
                        [$rewardId, $tid, $oid]
                    );
                    if ($reward && $balPoin >= (int)$reward['poin_dibutuhkan']) {
                        // Min transaksi check
                        if ((int)$reward['min_transaksi'] > 0 && $subtotal < (int)$reward['min_transaksi']) {
                            $rewardId = 0; $redeemPoin = 0;
                            $reward = null;
                        }
                        // Max redeem per bulan check
                        if ($reward && (int)$reward['max_redeem_per_bulan'] > 0) {
                            $stMonthly = $db->prepare(
                                "SELECT COUNT(*) FROM hl_loyalty_log
                                  WHERE pelanggan_id=? AND reward_id=? AND type='redeem'
                                    AND YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())"
                            );
                            $stMonthly->execute([(int)$pel_id, (int)$reward['id']]);
                            if ((int)$stMonthly->fetchColumn() >= (int)$reward['max_redeem_per_bulan']) {
                                $rewardId = 0; $redeemPoin = 0;
                                $reward = null;
                            }
                        }
                    }
                    if ($reward && $balPoin >= (int)$reward['poin_dibutuhkan']) {
                        $redeemPoin = (int)$reward['poin_dibutuhkan'];
                        // Compute discount per tipe
                        switch ($reward['tipe']) {
                            case 'diskon_nominal':
                                $redeemValue = (int)$reward['nilai'];
                                break;
                            case 'diskon_persen':
                                $redeemValue = (int)floor($maxRupiah * ((int)$reward['nilai'] / 100));
                                break;
                            case 'gratis_layanan':
                                $redeemValue = (int)$reward['nilai'];
                                break;
                        }
                        $redeemValue = min($redeemValue, $maxRupiah);
                        if ($catatan === '') $catatan = "Reward: " . $reward['nama_reward'];
                        else $catatan .= " · Reward: " . $reward['nama_reward'];
                    } else {
                        $rewardId   = 0;
                        $redeemPoin = 0;
                    }
                } else {
                    // Manual numeric redeem (existing behavior)
                    $maxPoin    = min($balPoin, (int)floor($maxRupiah / $cfg['poin_value']));
                    $redeemPoin = min($redeemPoin, $maxPoin);
                    if ($redeemPoin > 0) {
                        $redeemValue = $redeemPoin * $cfg['poin_value'];
                        if ($catatan === '') $catatan = "Redeem $redeemPoin poin (-Rp " . number_format($redeemValue,0,',','.') . ")";
                        else $catatan .= " · Redeem $redeemPoin poin (-Rp " . number_format($redeemValue,0,',','.') . ")";
                    } else {
                        $redeemPoin = 0;
                    }
                }
            } else {
                $redeemPoin = 0;
                $rewardId   = 0;
            }

            // Biaya tambahan = SUM dari per-item biaya_express (server-side
            // re-compute supaya gak bisa di-tamper dari frontend).
            // Load tier yg berlaku di outlet ini (global + outlet-specific)
            $tierMap = [];
            foreach (ExpressTier::forTenant($tid, $oid) as $t) {
                $tierMap[$t['nama_tier']] = $t;
            }
            $biayaTbh = 0.0;
            $itemsWithTier = [];
            foreach ($items as $i => $item) {
                $itTier = trim((string)($item['express_tier_nama'] ?? ''));
                $itFee  = 0;
                if ($itTier !== '' && isset($tierMap[$itTier])) {
                    $t = $tierMap[$itTier];
                    $sub = floatval($item['jumlah']) * floatval($item['harga_satuan']);
                    $itFee = $t['tipe_biaya'] === 'flat'
                        ? (float)$t['nilai_biaya']
                        : round($sub * ((float)$t['nilai_biaya'] / 100));
                    $biayaTbh += $itFee;
                } else {
                    $itTier = ''; // invalid tier name → drop
                }
                $itemsWithTier[$i] = ['express_tier_nama' => $itTier ?: null, 'biaya_express' => $itFee];
            }
            // Derive tipe_order + express_tier_nama dominant utk header
            $dom = ExpressTier::dominantTier(array_map(
                fn($i, $x) => array_merge($i, $x),
                $items, $itemsWithTier
            ));
            $tipeOrder       = $dom['tipe_order'];
            $expressTierNama = $dom['nama'];

            // Member auto-discount (kalau pelanggan member dgn tier diskon > 0)
            $memberDiskon = 0;
            $memberLabel  = null;
            if ($pel_id) {
                [$memberDiskon, $memberLabel] = MemberTier::calcMemberDiscount($tid, (int)$pel_id, (float)$subtotal);
                if ($memberDiskon > 0) {
                    $catatanAdd = "Auto-diskon: $memberLabel (-Rp " . number_format($memberDiskon,0,',','.') . ")";
                    $catatan = $catatan === '' ? $catatanAdd : ($catatan . ' · ' . $catatanAdd);
                }
            }

            // Biaya Lainnya — dihitung server dari tier aktif (anti-tamper,
            // sama seperti biaya_tambahan), TIDAK dipercaya dari klien sama sekali.
            $biayaLainnyaRows  = BiayaLainnyaTier::calcAppliedFees($tid, $oid, $subtotal);
            $biayaLainnya      = array_sum(array_column($biayaLainnyaRows, 'nominal'));

            // Total final (subtotal − diskon − member_diskon + biaya tambahan + biaya lainnya)
            $diskonTotal = $diskon + $redeemValue + $memberDiskon;
            $total    = max(0, $subtotal - $diskonTotal + $biayaTbh + $biayaLainnya);

            // ── Bayar pakai Saldo Deposit ──
            // User checklist "Bayar pakai Saldo" + input jumlah ambil dari saldo.
            // Sistem deduct saldo (transaction-locked) → counted as paid.
            $depositPay = 0;
            $depositErr = null;
            if (!empty($data['use_deposit']) && $pel_id) {
                $depositPay = (float)($data['deposit_amount'] ?? 0);
                $maxBalance = DepositManager::balance($tid, (int)$pel_id);
                if ($depositPay <= 0) $depositPay = min($maxBalance, $total); // auto max
                $depositPay = min($depositPay, $maxBalance, $total);
            }

            $dp       = floatval($data['dp'] ?? 0);
            $totalPaid = $dp + $depositPay;
            $sisa     = max(0, $total - $totalPaid);
            $status_b = $totalPaid >= $total ? 'lunas' : ($totalPaid > 0 ? 'dp' : 'belum_bayar');

            // Cek apakah kolom biaya_tambahan & tipe_order sudah ada (migration applied?)
            $hasBiayaTipe = true;
            try { $db->query("SELECT biaya_tambahan, tipe_order FROM hl_transaksi LIMIT 1"); }
            catch (Throwable) { $hasBiayaTipe = false; }
            $hasTierNama = true;
            try { $db->query("SELECT express_tier_nama FROM hl_transaksi LIMIT 1"); }
            catch (Throwable) { $hasTierNama = false; }
            $hasParfum = true;
            try { $db->query("SELECT parfum FROM hl_transaksi LIMIT 1"); }
            catch (Throwable) { $hasParfum = false; }
            $hasBiayaLainnya = true;
            try { $db->query("SELECT biaya_lainnya FROM hl_transaksi LIMIT 1"); }
            catch (Throwable) { $hasBiayaLainnya = false; }

            // Foto masuk (optional, dari upload_foto endpoint)
            $fotoMasuk = trim($data['foto_masuk'] ?? '');
            $hasFotoMasuk = true;
            try { $db->query("SELECT foto_masuk FROM hl_transaksi LIMIT 1"); } catch (Throwable) { $hasFotoMasuk = false; }

            // Insert transaksi header
            // Kolom opsional: estimasi_jam (lama), biaya_tambahan + tipe_order (baru Phase 2)
            $hasEstJam = true;
            try { $db->query("SELECT estimasi_jam FROM hl_transaksi LIMIT 1"); } catch (Throwable) { $hasEstJam = false; }

            // Append catatan kalau ada deposit pay (audit visible di nota)
            if ($depositPay > 0) {
                $depCatatan = "Bayar Saldo Deposit: Rp " . number_format($depositPay, 0, ',', '.');
                $catatan = $catatan === '' ? $depCatatan : ($catatan . ' · ' . $depCatatan);
            }

            // Metode bayar: kalau belum ada pembayaran → kosong → simpan NULL (jangan "cash" palsu).
            // Kalau diisi → validasi terhadap metode aktif (anti-tamper).
            $_metodeIn    = trim($data['metode_bayar'] ?? '');
            $_metodeStore = null;
            if ($_metodeIn !== '') {
                $_validate = $db->prepare("
                    SELECT 1 FROM hl_payment_methods
                    WHERE outlet_id=? AND tenant_id=? AND code=? AND is_active=1
                ");
                $_validate->execute([$oid, $tid, $_metodeIn]);
                if (!$_validate->fetchColumn()) {
                    // Selaras dgn fallback render dropdown: outlet tanpa rows sama sekali
                    // (pra-migrasi) tetap boleh pakai 3 builtin default — jangan tampilkan
                    // Tunai di UI lalu tolak di server.
                    $_cnt = $db->prepare("SELECT COUNT(*) FROM hl_payment_methods WHERE outlet_id=? AND tenant_id=?");
                    $_cnt->execute([$oid, $tid]);
                    if ((int)$_cnt->fetchColumn() > 0 || !in_array($_metodeIn, ['cash','transfer','qris'], true)) {
                        throw new RuntimeException('Metode pembayaran tidak valid atau dinonaktifkan.');
                    }
                }
                $_metodeStore = $_metodeIn;
            }

            // Bangun INSERT dinamis sesuai kolom yg tersedia
            // $dp di DB = $totalPaid (semua sumber payment: cash + deposit)
            $cols   = ['tenant_id','outlet_id','no_order','tanggal','pelanggan_id','nama_pelanggan','telepon',
                       'subtotal','diskon','total','dp','sisa_bayar','metode_bayar','status_bayar',
                       'status_proses','estimasi_selesai','catatan','created_by','created_at'];
            $vals   = [$tid,$oid,$no,$tanggal,$pel_id,$nama_pel,$telepon,
                       $subtotal,$diskonTotal,$total,$totalPaid,$sisa,
                       $_metodeStore, $status_b,
                       'masuk',$estimasi,$catatan,$user['id'],date('Y-m-d H:i:s')];
            if ($hasEstJam)    { $cols[] = 'estimasi_jam';   $vals[] = $estimasiJam; }
            if ($hasBiayaTipe) { $cols[] = 'biaya_tambahan'; $vals[] = $biayaTbh;
                                 $cols[] = 'tipe_order';     $vals[] = $tipeOrder; }
            if ($hasBiayaLainnya && $biayaLainnya > 0) {
                $cols[] = 'biaya_lainnya'; $vals[] = $biayaLainnya;
            }
            if ($hasTierNama)  { $cols[] = 'express_tier_nama'; $vals[] = $expressTierNama; }
            if ($hasParfum && $parfum !== '') { $cols[] = 'parfum'; $vals[] = $parfum; }
            $placeholders = implode(',', array_fill(0, count($cols), '?'));

            // Referral attribution — SEBELUM INSERT hl_transaksi supaya referee masih
            // terlihat sebagai pelanggan baru (zero orders). Best-effort, rollback aman.
            if ($isNewPelanggan && $pel_id && $referralKode !== '' && Referral::config($tid)['enabled']) {
                try { Referral::attribute($tid, $referralKode, (int)$pel_id); } catch (Throwable) {}
            }

            $stmt = $db->prepare("INSERT INTO hl_transaksi (".implode(',', $cols).") VALUES ($placeholders)");
            $stmt->execute($vals);
            $trx_id = $db->lastInsertId();

            // Simpan breakdown Biaya Lainnya (snapshot per baris)
            if (!empty($biayaLainnyaRows)) {
                $blStmt = $db->prepare(
                    "INSERT INTO hl_transaksi_biaya_lainnya (tenant_id, outlet_id, transaksi_id, nama, nominal) VALUES (?,?,?,?,?)"
                );
                foreach ($biayaLainnyaRows as $row) {
                    $blStmt->execute([$tid, $oid, $trx_id, $row['nama'], $row['nominal']]);
                }
            }

            // Simpan foto_masuk kalau kolom & data ada
            if ($hasFotoMasuk && $fotoMasuk !== '') {
                try {
                    $db->prepare("UPDATE hl_transaksi SET foto_masuk=? WHERE id=? AND tenant_id=? AND outlet_id=?")
                       ->execute([substr($fotoMasuk,0,255), $trx_id, $tid, $oid]);
                } catch (Throwable) {}
            }

            // Deduct poin redeem (dalam transaksi yang sama) — transaksi_id terisi
            if ($redeemPoin > 0 && $pel_id) {
                Loyalty::redeemInTx($db, $tid, $oid, (int)$pel_id, $redeemPoin, (int)$trx_id, $user['id'], $rewardId > 0 ? $rewardId : null);
            }

            // Deduct saldo deposit (audit trail di hl_deposit_usage)
            if ($depositPay > 0 && $pel_id) {
                [$_id, $depErr] = DepositManager::deduct(
                    $tid, $oid, (int)$pel_id, $depositPay,
                    (int)$trx_id, "Bayar nota $no", (int)$user['id']
                );
                if ($depErr) {
                    // Saldo tidak cukup atau error — rollback semua
                    throw new RuntimeException('Gagal potong saldo deposit: ' . $depErr);
                }
            }

            // Insert items — cek kolom tier ada (Phase 3 rebuild)
            $hasItemTier = true;
            try { $db->query("SELECT express_tier_nama, biaya_express FROM hl_transaksi_item LIMIT 1"); }
            catch (Throwable) { $hasItemTier = false; }

            $istmt = $hasItemTier
                ? $db->prepare(
                    "INSERT INTO hl_transaksi_item
                     (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal,catatan_item,express_tier_nama,biaya_express)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
                  )
                : $db->prepare(
                    "INSERT INTO hl_transaksi_item
                     (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal,catatan_item)
                     VALUES (?,?,?,?,?,?,?,?,?,?)"
                  );

            foreach ($items as $i => $item) {
                $sub = floatval($item['jumlah']) * floatval($item['harga_satuan']);
                $vals = [
                    $tid, $oid, $trx_id,
                    $item['layanan_id'] ?: null,
                    substr(trim(strip_tags($item['nama_layanan'])), 0, 100),
                    $item['satuan'] ?? 'kg',
                    $item['jumlah'],
                    $item['harga_satuan'],
                    $sub,
                    substr(trim(strip_tags($item['catatan_item'] ?? '')), 0, 255),
                ];
                if ($hasItemTier) {
                    $vals[] = $itemsWithTier[$i]['express_tier_nama'] ?? null;
                    $vals[] = $itemsWithTier[$i]['biaya_express']     ?? 0;
                }
                $istmt->execute($vals);
            }

            // Log status masuk (hl_proses_log tidak punya outlet_id)
            $db->prepare(
                "INSERT INTO hl_proses_log (tenant_id,transaksi_id,status_baru,oleh,created_at) VALUES (?,?,?,?,?)"
            )->execute([$tid, $trx_id, 'masuk', $user['nama'], date('Y-m-d H:i:s')]);

            // AUTO INSERT KAS jika ada DP/Lunas
            if ($dp > 0) {
                $metode      = $data['metode_bayar'] ?? 'cash';
                $metodeLabel = ['cash'=>'Cash','transfer'=>'Transfer','qris'=>'QRIS'][$metode] ?? 'Cash';
                $isPaid      = $dp >= $total;
                $kasKet      = ($isPaid ? 'Pembayaran LUNAS' : 'DP/Uang Muka') .
                               ' order ' . $no . ' - ' . $nama_pel . ' via ' . $metodeLabel;

                // Kas cuma catat pendapatan yg BENERAN masuk — kalau kasir terima cash
                // lebih dari yg dibutuhkan (customer bayar pas-pasan lembar besar), sisanya
                // adalah KEMBALIAN ke customer, bukan pendapatan. $depositPay sudah tercatat
                // ledger deposit sendiri (saat top-up), jadi porsi yg jadi "jatah" $dp di sini
                // cuma sisa kebutuhan SETELAH dikurangi deposit yg kepakai.
                $kasJumlah = min($dp, max(0, $total - $depositPay));

                if ($kasJumlah > 0) {
                    TenantQuery::insert('hl_kas', [
                        'tanggal'    => $tanggal,
                        'tipe'       => 'masuk',
                        'kategori'   => 'Penjualan Laundry',
                        'keterangan' => $kasKet,
                        'jumlah'     => $kasJumlah,
                        'ref_order'  => $no,
                        'created_by' => $user['id'],
                    ]);
                }
            }

            // Auto-INSERT antar row kalau cb_antar dicentang (bypass perm antar.manage — kasir authorized via pos.create)
            $antarActive = !empty($data['antar_active'] ?? '');
            if ($antarActive) {
                $alamat   = substr(trim($data['antar_alamat']  ?? ''), 0, 255);
                $antCat   = substr(trim($data['antar_catatan'] ?? ''), 0, 255);
                $zonaId   = (int)($data['antar_zona'] ?? 0) ?: null;
                if ($alamat !== '' || $antCat !== '') {
                    $fee = 0;
                    if ($zonaId) {
                        $z = TenantQuery::rawOne(
                            "SELECT fee FROM hl_zona_antar WHERE id=? AND tenant_id=? AND outlet_id=? AND aktif=1",
                            [$zonaId, $tid, $oid]
                        );
                        if ($z) $fee = (int)$z['fee'];
                    }
                    try {
                        TenantQuery::insert('hl_antar_jemput', [
                            'tipe'         => 'antar',
                            'transaksi_id' => $trx_id,
                            'pelanggan_id' => $pel_id ?: null,
                            'nama'         => $nama_pel,
                            'telepon'      => $telepon,
                            'alamat'       => $alamat  ?: null,
                            'zona_id'      => $zonaId,
                            'fee'          => $fee,
                            'catatan'      => $antCat  ?: null,
                            'created_by'   => $user['id'],
                            'outlet_id'    => $oid,
                        ]);
                    } catch (Throwable $e) {
                        ErrorLogger::logException('antar_auto_create', $e, $tid, $oid);
                    }
                }
            }

            $db->commit();
            logAudit('create', 'orders', 'Buat order baru: ' . $no . ' - ' . $nama_pel, $no);
            PushSender::send('order_baru', (int)$tid, (int)$oid, [
                'title' => 'Order baru masuk',
                'body'  => '#' . $no . ' • ' . $nama_pel,
                'url'   => '/orders?q=' . urlencode($no),
            ]);

            // Loyalty: earn poin TIDAK lagi di sini — sekarang triggered saat
            // status_proses berubah ke 'siap' (di orders.php / kanban.php).
            // Touch last_transaksi supaya segmentasi akurat saat order dibuat.
            $poinEarned = 0;
            if ($pel_id) {
                try { Loyalty::touchLastTransaksi($tid, (int)$pel_id); } catch (Throwable) {}
            }

            // Referral payout — kalau order lahir LUNAS dan ada pelanggan.
            // Dipanggil SETELAH commit (payoutOnFirstLunas buka transaksi sendiri). Best-effort.
            if ($status_b === 'lunas' && $pel_id) {
                try {
                    Referral::payoutOnFirstLunas($tid, (int)$pel_id, (int)$trx_id, $user['id']);
                } catch (Throwable $e) {
                    ErrorLogger::logException('referral_payout_pos_lunas', $e, $tid, $oid);
                }
            }

            echo json_encode(['success'=>true, 'no_order'=>$no, 'id'=>$trx_id,
                'total'=>$total, 'sisa'=>$sisa, 'poin_earned'=>$poinEarned,
                'poin_redeemed'=>$redeemPoin, 'redeem_value'=>$redeemValue]);

            // Run anomaly check (silent, async-ish — setelah response dikirim)
            try {
                require_once ROOT . '/core/AnomalyDetector.php';
                AnomalyDetector::check($tid, $oid);
            } catch (Throwable) {}
        } catch (Throwable $e) {
            $db->rollBack();
            apiErr($e);
        }
        exit;
    }

    // GET detail transaksi (untuk print)
    if ($action === 'get_detail') {
        $id  = intval($_GET['id']);
        $t   = TenantQuery::rawOne(
            "SELECT * FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND id=?", [$tid, $oid, $id]
        );
        if (!$t) { echo json_encode(['error'=>'Not found']); exit; }
        $t['items'] = TenantQuery::raw(
            "SELECT * FROM hl_transaksi_item WHERE tenant_id=? AND outlet_id=? AND transaksi_id=? ORDER BY id",
            [$tid, $oid, $id]
        );
        echo json_encode($t); exit;
    }

    // GENERATE WA NOTA — kirim struk via WA setelah order tersimpan
    if ($action === 'wa_nota') {
        require_once ROOT . '/core/CoinLedger.php';
        require_once ROOT . '/core/WaLogger.php';
        require_once ROOT . '/core/StrukGenerator.php';

        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['error'=>'id wajib']); exit; }

        $t = TenantQuery::rawOne(
            "SELECT * FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND id=?", [$tid, $oid, $id]
        );
        if (!$t) { echo json_encode(['error'=>'Order tidak ditemukan']); exit; }

        $phone = preg_replace('/[^0-9]/', '', $t['telepon'] ?? '');
        if (!$phone) { echo json_encode(['error'=>'Nomor HP pelanggan kosong']); exit; }
        if (substr($phone, 0, 1) === '0') $phone = '62' . substr($phone, 1);

        // Cek coin balance
        if (!CoinLedger::canAfford('send_wa_nota')) {
            echo json_encode(['error'=>'Koin tidak cukup untuk WA Nota (butuh 150 koin).']);
            exit;
        }

        // Load items, tenant info, outlet info
        $t['items'] = TenantQuery::raw(
            "SELECT * FROM hl_transaksi_item WHERE tenant_id=? AND outlet_id=? AND transaksi_id=? ORDER BY id",
            [$tid, $oid, $id]
        );

        $tenant      = TenantResolver::getTenant();
        $outlet      = TenantResolver::getOutlet();
        $brandName   = $tenant['nama_perusahaan'] ?: ($outlet['nama_outlet'] ?? 'Laundry');
        $outletNama  = $outlet['nama_outlet'] ?? $brandName;
        $alamat      = trim(($outlet['alamat'] ?? '') . ($outlet['kota'] ? ', ' . $outlet['kota'] : ''));

        $itemList = '';
        foreach ($t['items'] as $item) {
            $itemList .= "\n   • " . $item['nama_layanan'] . " — " . floatval($item['jumlah']) . " " . $item['satuan'];
        }

        $totalFmt = "Rp " . number_format(floatval($t['total']), 0, ',', '.');
        $dpFmt    = "Rp " . number_format(floatval($t['dp']), 0, ',', '.');
        $sisaFmt  = "Rp " . number_format(floatval($t['sisa_bayar']), 0, ',', '.');
        $tgl      = $t['tanggal'] ? date('d M Y', strtotime($t['tanggal'])) : '-';
        $est      = $t['estimasi_selesai'] ? date('d M Y', strtotime($t['estimasi_selesai'])) : '-';
        $metode   = $t['metode_bayar'] ? ((['cash'=>'Cash','transfer'=>'Transfer','qris'=>'QRIS'][$t['metode_bayar']]) ?? $t['metode_bayar']) : '';
        $trackUrl = (defined('APP_URL') ? APP_URL : 'https://lamasy.harpy.id') . '/track.php?order=' . urlencode($t['no_order']);

        $waTmpl  = StrukGenerator::loadTemplate($tid, $oid, 'retail');
        $waAid   = StrukGenerator::paymentAidFor($t, $waTmpl, $outlet);
        $waNudge = StrukGenerator::waPaymentNudgeLine($waAid);

        $msg = "Halo *{$t['nama_pelanggan']}*,\n\n"
             . "Pesanan Anda di *{$brandName}* sudah kami terima.\n\n"
             . "*No. Order:* {$t['no_order']}\n"
             . "*Tanggal:* {$tgl}\n"
             . "*Layanan:*{$itemList}\n\n"
             . "*Total:* {$totalFmt}\n"
             . ($t['metode_bayar']
                 ? ("*Bayar ({$metode}):* {$dpFmt}\n" . ($t['sisa_bayar'] > 0 ? "*Sisa Bayar:* {$sisaFmt}\n" : "*Status Bayar:* Lunas\n"))
                 : "*Status Bayar:* Belum Bayar\n")
             . "*Est. Selesai:* {$est}\n\n"
             . "Cek status real-time:\n{$trackUrl}\n"
             . $waNudge . "\n"
             . ($alamat ? "*Alamat outlet:*\n{$outletNama}\n{$alamat}\n\n" : "")
             . "Terima kasih sudah mempercayakan cucian Anda kepada kami.\n"
             . "_" . $brandName . "_";

        // Deduct coin + log
        CoinLedger::deduct('send_wa_nota', $t['no_order']);
        WaLogger::log('wa_nota', $phone, mb_substr($msg, 0, 200), $tid, $oid);

        echo json_encode(['ok'=>true, 'message'=>$msg, 'phone'=>$phone, 'no_order'=>$t['no_order']]);
        exit;
    }

    echo json_encode(['error'=>'Unknown action']); exit;
}

// Load QRIS data untuk modal display di payment method
$_pageOid = TenantResolver::outletId();
$_pageTid = TenantResolver::id();
$outletQrisStmt = Database::get()->prepare("SELECT qris_image, qris_label, label_size, telepon_wajib FROM outlets WHERE id=? AND tenant_id=?");
$outletQrisStmt->execute([$_pageOid, $_pageTid]);
$outletQrisData = $outletQrisStmt->fetch(PDO::FETCH_ASSOC) ?: ['qris_image'=>null, 'qris_label'=>null, 'telepon_wajib'=>0];

// Load active payment methods untuk dropdown render
$methodsStmt = Database::get()->prepare("
    SELECT code, label, emoji
    FROM hl_payment_methods
    WHERE outlet_id=? AND tenant_id=? AND is_active=1
    ORDER BY sort_order, id
");
$methodsStmt->execute([$_pageOid, $_pageTid]);
$activeMethods = $methodsStmt->fetchAll(PDO::FETCH_ASSOC);
// Fallback ke 3 default kalau outlet belum punya rows (shouldn't happen post-migration, defensive)
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
<?php renderHead('POS'); ?>
<style>
/* LAYOUT */
.main{max-width:1100px;width:100%;margin:0 auto;padding:24px 20px}
.grid-2{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(300px,.9fr);gap:20px;align-items:start}

/* CARD */
.card{background:var(--white);border-radius:var(--r-lg);border:1px solid rgba(27,45,90,.07);box-shadow:var(--shadow);overflow:hidden;margin-bottom:20px}
.card-header{padding:16px 20px;border-bottom:1px solid var(--light);display:flex;align-items:center;justify-content:space-between}
.card-title{font-size:14px;font-weight:700;color:var(--navy);display:flex;align-items:center;gap:8px}
.card-body{padding:20px}

/* FORM */
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
.form-row.cols2{grid-template-columns:1fr 1fr}
.form-row.cols3{grid-template-columns:1fr 1fr 1fr}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group.full{grid-column:1/-1}
label{font-size:11px;font-weight:700;color:var(--navy);letter-spacing:.05em;text-transform:uppercase}
label .req{color:var(--red)}
input,select,textarea{padding:9px 12px;border:1.5px solid rgba(27,45,90,.14);border-radius:var(--r);font-family:var(--font);font-size:14px;color:var(--dark);background:var(--off);outline:none;transition:all .2s;width:100%}
input:focus,select:focus,textarea:focus{border-color:var(--teal);background:var(--white);box-shadow:0 0 0 3px rgba(53,232,213,.1)}
textarea{resize:vertical;min-height:64px}

/* AUTOCOMPLETE */
.autocomplete-wrap{position:relative}
.autocomplete-list{position:absolute;top:100%;left:0;right:0;background:var(--white);border:1.5px solid rgba(53,232,213,.3);border-radius:var(--r);z-index:50;max-height:200px;overflow-y:auto;box-shadow:var(--shadow-lg);display:none}
.autocomplete-list.open{display:block}
.ac-item{padding:10px 14px;cursor:pointer;font-size:14px;border-bottom:1px solid var(--light);transition:background .15s}
.ac-item:hover{background:var(--teal-bg)}
.ac-item .ac-sub{font-size:11px;color:var(--gray);margin-top:2px}

/* ITEMS GRID */
.items-table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:12px}
.items-table thead tr{background:var(--navy-d)}
.items-table thead th{padding:9px 10px;text-align:left;font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.6);white-space:nowrap}
.items-table tbody tr{border-bottom:1px solid var(--light)}
.items-table tbody td{padding:6px 6px;vertical-align:middle}
.items-table tbody tr:last-child{border-bottom:none}
.item-input{padding:7px 9px;font-size:13px}
/* hint "min N" DI DALAM kotak Jumlah, nempel kiri (bukan teks lepas di sebelah input) */
.qty-wrap{position:relative;display:inline-flex;align-items:center}
.qty-wrap input{width:84px;padding-left:40px}
.qty-wrap .qty-min{position:absolute;left:9px;font-size:9px;font-weight:700;color:var(--gray);pointer-events:none;letter-spacing:.02em}
.qty-wrap .qty-min.bad{color:#DC2626}
.item-subtotal{font-family:var(--mono);font-weight:600;color:var(--navy);text-align:right;white-space:nowrap;font-size:13px;min-width:90px}
.btn-remove{background:#FEE2E2;color:var(--red);border:none;border-radius:6px;padding:5px 9px;cursor:pointer;font-size:13px;transition:all .2s}
.btn-remove:hover{background:var(--red);color:white}

/* SUMMARY BOX */
.summary-box{background:linear-gradient(135deg,var(--navy-d),var(--navy));border-radius:var(--r-lg);padding:20px;color:var(--white);margin-top:4px}
.sum-row{display:flex;justify-content:space-between;align-items:center;padding:6px 0;font-size:14px}
.sum-row.total{border-top:1px solid rgba(255,255,255,.15);margin-top:8px;padding-top:12px}
.sum-label{color:rgba(255,255,255,.6)}
.sum-value{font-family:var(--mono);font-weight:700;white-space:nowrap}
.sum-value.big{font-size:clamp(1rem,5vw,1.4rem);white-space:nowrap;letter-spacing:-0.02em;color:var(--teal)}
.sum-value.sisa{color:#FCA5A5}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:10px 18px;border-radius:var(--r);font-family:var(--font);font-size:14px;font-weight:600;cursor:pointer;transition:all .2s;border:none}
.btn-primary{background:var(--teal);color:var(--navy-d);padding:13px 28px;font-size:15px;width:100%}
.btn-primary:hover{background:var(--teal-d);box-shadow:0 4px 16px rgba(53,232,213,.3)}
.btn-outline{background:transparent;color:var(--navy);border:1.5px solid rgba(27,45,90,.2)}
.btn-outline:hover{background:var(--light)}
.btn-teal-sm{background:var(--teal-bg);color:var(--teal-d);border:1px solid rgba(53,232,213,.3);font-size:13px;padding:7px 14px}
.btn-teal-sm:hover{background:var(--teal);color:var(--navy-d)}
.btn-green{background:#D1FAE5;color:#065F46}
.btn-green:hover{background:var(--green);color:white}
.btn-actions{display:flex;gap:10px;margin-top:16px}
.btn:disabled{opacity:.5;pointer-events:none}

/* LAYANAN GRID (quick pick) */
.layanan-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-bottom:12px;max-height:220px;overflow-y:auto}
.layanan-btn{padding:8px 6px;background:var(--off);border:1.5px solid rgba(27,45,90,.1);border-radius:8px;cursor:pointer;text-align:left;transition:all .2s;font-family:var(--font)}
.addon-row td{padding:4px 8px 10px !important;background:transparent}
.addon-btn{display:inline-block;margin:2px 6px 2px 0;padding:5px 10px;font-size:11.5px;font-weight:600;
  border:1.5px dashed var(--teal-d);border-radius:100px;background:rgba(53,232,213,.08);color:var(--teal-d);
  cursor:pointer;font-family:var(--font)}
.req.opt{color:var(--gray);font-weight:400;font-size:11px}
.layanan-btn:hover{border-color:var(--teal);background:var(--teal-bg)}
.layanan-btn .l-nama{font-size:12px;font-weight:600;color:var(--navy);line-height:1.3}
.layanan-btn .l-harga{font-size:11px;color:var(--teal-d);font-family:var(--mono);margin-top:2px}
.layanan-btn .l-kat{font-size:10px;color:var(--gray);margin-bottom:2px}
.layanan-search{margin-bottom:8px}

/* TOAST */
.toast{position:fixed;bottom:20px;right:20px;z-index:999;padding:13px 18px;border-radius:var(--r);font-size:14px;font-weight:600;color:white;transform:translateY(60px);opacity:0;transition:all .3s cubic-bezier(.4,0,.2,1);pointer-events:none;max-width:320px}
.toast.show{transform:translateY(0);opacity:1}
.toast.success{background:var(--green)}
.toast.error{background:var(--red)}

/* VOUCHER */
.voucher-applied{background:linear-gradient(135deg,#D1FAE5,#A7F3D0);border:1.5px solid #6EE7B7;border-radius:var(--r);padding:10px 14px;font-size:13px;color:#065F46}
.voucher-applied strong{font-family:var(--mono);letter-spacing:.08em}

/* PRINT MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,28,58,.6);backdrop-filter:blur(4px);z-index:200;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal{background:var(--white);border-radius:var(--r-lg);padding:0;width:380px;max-width:95vw;max-height:90vh;overflow-y:auto;box-shadow:var(--shadow-lg)}
.modal-header{padding:16px 20px;border-bottom:1px solid var(--light);display:flex;justify-content:space-between;align-items:center}
.modal-title{font-size:15px;font-weight:700;color:var(--navy)}
.modal-close{background:none;border:none;font-size:18px;cursor:pointer;color:var(--gray);padding:4px}
.modal-body{padding:20px}
.modal-footer{padding:16px 20px;border-top:1px solid var(--light);display:flex;gap:10px;justify-content:flex-end}

/* STRUK THERMAL */
.struk{font-family:'Courier New',monospace;font-size:12px;line-height:1.6;color:#000;width:72mm;margin:0 auto}
.struk-header{text-align:center;border-bottom:1px dashed #000;padding-bottom:8px;margin-bottom:8px}
.struk-header h2{font-size:14px;font-weight:bold;letter-spacing:.04em}
.struk-header p{font-size:10px}
.struk-row{display:flex;justify-content:space-between;font-size:11px}
.struk-row.bold{font-weight:bold;font-size:12px}
.struk-item{margin:4px 0;font-size:11px}
.struk-divider{border:none;border-top:1px dashed #000;margin:6px 0}
.struk-total{border-top:2px solid #000;margin-top:6px;padding-top:6px}
.struk-footer{text-align:center;margin-top:8px;font-size:10px;border-top:1px dashed #000;padding-top:8px}

@media print{
  body *{visibility:hidden}
  #strukPrint,#strukPrint *{visibility:visible}
  #strukPrint{position:fixed;left:0;top:0;width:80mm;padding:4mm;background:white}
  @page{size:80mm auto;margin:0}
}

@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}

/* Antar ke Pelanggan — toggle card */
.hl-antar-toggle{display:flex;align-items:center;gap:12px;margin:0 0 14px;padding:14px 16px;background:#F0FDFC;border:1.5px solid #BBF0EA;border-radius:12px;cursor:pointer;transition:all .15s;position:relative}
.hl-antar-toggle:hover{background:#E0FBF7;border-color:#80E4D8}
.hl-antar-toggle input[type=checkbox]{position:absolute;opacity:0;pointer-events:none}
.hl-antar-icon{font-size:24px;flex-shrink:0;width:42px;height:42px;display:flex;align-items:center;justify-content:center;background:#fff;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.hl-antar-text{flex:1;min-width:0}
.hl-antar-title{font-size:14px;font-weight:700;color:var(--navy);line-height:1.3}
.hl-antar-sub{font-size:12px;color:var(--gray);margin-top:2px}
.hl-antar-check{width:24px;height:24px;border-radius:50%;border:2px solid #BBF0EA;background:#fff;flex-shrink:0;display:flex;align-items:center;justify-content:center;transition:all .15s}
.hl-antar-toggle:has(input:checked){background:#D1FAEF;border-color:#35E8D5;box-shadow:0 0 0 3px rgba(53,232,213,.15)}
.hl-antar-toggle:has(input:checked) .hl-antar-check{background:#35E8D5;border-color:#35E8D5}
.hl-antar-toggle:has(input:checked) .hl-antar-check::after{content:'✓';color:#0F1C3A;font-weight:900;font-size:14px}

@media(max-width:800px){
  .main{padding:16px 14px}
  .grid-2{grid-template-columns:1fr;gap:14px}
  .grid-2 > div[style*="sticky"]{position:static!important}  /* lepas sticky di HP */
  .layanan-grid{grid-template-columns:repeat(2,1fr)}
  .form-row{grid-template-columns:1fr}
  .form-row.cols3{grid-template-columns:1fr 1fr}
  /* Konfirmasi modal fit HP */
  #confirmSaveModal > div{padding:16px 18px}
}
@media(max-width:680px){
  .main{padding:12px 10px 80px;max-width:100%;overflow-x:hidden}
  .card{margin-bottom:14px;overflow:visible} /* lepas overflow:hidden supaya table wrap bisa scroll */
  /* card-header wrap + tombol stack */
  .card-header{padding:12px 14px;flex-wrap:wrap;gap:8px}
  .card-header .btn,.card-header button{flex-shrink:0}
  .card-body{padding:14px}
  .layanan-grid{grid-template-columns:repeat(2,1fr);gap:5px;max-height:180px}

  /* TABEL ITEMS — convert ke layout stacked card per row di HP (UX lebih baik dari scroll horizontal) */
  .items-table, .items-table thead, .items-table tbody,
  .items-table tr, .items-table th, .items-table td { display:block; width:100% }
  .items-table thead { display:none }  /* sembunyikan header — pakai label inline */
  .items-table tbody tr {
    border:1px solid rgba(27,45,90,.1); border-radius:10px;
    margin-bottom:10px; padding:10px 12px; background:#fff;
  }
  .items-table tbody td {
    display:flex; justify-content:space-between; align-items:center;
    padding:5px 0; border:none; font-size:13px; gap:8px;
  }
  /* Label kolom via pseudo-element */
  .items-table tbody td::before {
    content: attr(data-lbl); font-size:11px; color:var(--gray); font-weight:600;
    text-transform:uppercase; letter-spacing:.05em; flex-shrink:0;
  }
  /* Hide labels jika tidak ada data-lbl */
  .items-table tbody td:empty::before { content:'' }
  /* Inputs di stacked layout — .lmx-btn (dropdown custom) ikut; !important perlu
     krn template item pakai inline width (64px satuan / 130px express) yg tersalin
     ke tombol → tanpa ini kotaknya sempit & lebar acak */
  .items-table tbody td input,
  .items-table tbody td select,
  .items-table tbody td .lmx-btn {
    text-align:right; flex:1 1 auto !important; min-width:0 !important;
    max-width:160px; width:auto !important;
  }
  .items-table tbody td .lmx-btn .lmx-lbl { text-align:right; }
  /* wrapper Jumlah ikut melebar spt input lain; input di dalamnya isi penuh */
  .items-table tbody td .qty-wrap { flex:1 1 auto; min-width:0; max-width:160px; }
  .items-table tbody td .qty-wrap input { width:100% !important; max-width:none; }
  .items-table tbody td .item-sub { font-weight:700; color:var(--navy); }
  /* Tombol remove di pojok kanan atas card */
  .items-table tbody td:last-child {
    justify-content:flex-end; padding-top:8px;
    border-top:1px dashed rgba(27,45,90,.08); margin-top:6px;
  }
  /* Baris addon (tombol saran self-service, td colspan) BUKAN pasangan
     label/value spt kolom item lain — jangan ikut kena display:flex
     tanpa wrap di atas (bikin tombol kepotong horizontal di HP).
     Reset ke block biasa supaya .addon-btn (inline-block) bisa wrap. */
  .items-table tbody tr.addon-row { border:none; margin:0; padding:0; background:transparent; }
  .items-table tbody tr.addon-row td { display:block !important; }
  .items-table tbody tr.addon-row td::before { content:none; }

  .table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
  .btn-actions{flex-direction:column;gap:8px}
  .btn-actions .btn{width:100%}
  .btn{padding:12px 14px;font-size:14px}
  /* AI floating menutupi tombol — geser ke kiri-bawah */
  #aiBubbleBtn{bottom:80px!important;right:14px!important}
  #aiChatPanel{right:14px!important;left:14px;width:auto!important;max-width:none}
  /* Loyalty reward list maks 140px di HP */
  #rewardsList{max-height:140px!important}
  /* Summary box — pastikan tidak overflow */
  .summary-box{padding:14px}
  .summary-box input{max-width:90px!important}
  /* Voucher row stack di HP */
  .form-row.cols3{grid-template-columns:1fr 1fr}
}
@media(max-width:400px){
  .main{padding:8px 8px 80px}
  .layanan-grid{grid-template-columns:1fr 1fr;gap:4px}
}

/* Mobile sticky action bar — Total + Simpan selalu accessible */
.pos-mobile-cta { display: none; }
@media(max-width:680px) {
  .pos-mobile-cta {
    display: flex;
    position: fixed;
    /* Nempel penuh ke bawah; padding-bawah mengangkat Total+Simpan ke atas
       bottom nav + FAB (reserve ~70px, sama dgn .ol-content), sekaligus latar
       putihnya menutup celah — tak ada konten scroll bocor di antara CTA & nav.
       z-index di BAWAH nav (40) biar nav tetap di atas & FAB 🛒 nyembul di atas
       latar putih CTA, bukan di atas konten. */
    bottom: 0; left: 0; right: 0;
    padding: 10px 14px calc(70px + env(safe-area-inset-bottom, 0px));
    background: var(--white);
    border-top: 1px solid rgba(27,45,90,.1);
    box-shadow: 0 -4px 20px rgba(0,0,0,.08);
    gap: 12px;
    align-items: center;
    z-index: 30;
  }
  .pos-mobile-cta-total {
    flex: 1; min-width: 0; display: flex; align-items: baseline; gap: 6px;
    white-space: nowrap; overflow: hidden;
    font-size: 11px; color: var(--gray); text-transform: uppercase; letter-spacing: .3px;
  }
  .pos-mobile-cta-total strong {
    font-size: 18px; color: var(--navy); font-family: var(--mono);
    overflow: hidden; text-overflow: ellipsis;
  }
  .pos-mobile-cta button {
    flex: 0 0 auto; width: auto !important; padding: 12px 22px;
    font-size: 14px; font-weight: 700; white-space: nowrap;
  }
  /* Hide tombol Simpan asli (avoid duplicate), tombol Reset tetap di flow */
  #btnSave { display: none !important; }
  /* Extra bottom padding biar content gak ketutup sticky bar */
  .main { padding-bottom: 90px !important; }
}
/* AI bubble jangan nabrak bottom-nav (≤900) & sticky CTA Simpan (≤680) */
@media (max-width: 900px) {
  #aiBubbleBtn { bottom: calc(84px + env(safe-area-inset-bottom,0px)) !important; }
  #aiNotifDot  { bottom: calc(126px + env(safe-area-inset-bottom,0px)) !important; }
  #aiChatPanel { bottom: calc(150px + env(safe-area-inset-bottom,0px)) !important; max-height: 60vh !important; }
}
@media (max-width: 680px) {
  #aiBubbleBtn { bottom: calc(140px + env(safe-area-inset-bottom,0px)) !important; }
  #aiNotifDot  { bottom: calc(182px + env(safe-area-inset-bottom,0px)) !important; }
  #aiChatPanel { bottom: calc(206px + env(safe-area-inset-bottom,0px)) !important; }
}
</style>
</head>
<body data-tour-page="pos">
<?php renderTopbar('pos'); ?>
<script src="/assets/vendor/html2canvas.min.js?v=<?= @filemtime(__DIR__.'/assets/vendor/html2canvas.min.js') ?: '1' ?>"></script>
<script src="/assets/js/thermal-print.js?v=<?= @filemtime(__DIR__.'/assets/js/thermal-print.js') ?: '1' ?>"></script>

<!-- Modal pilih printer thermal (app-only) -->
<div id="printerModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:14px;padding:18px;max-width:340px;width:90%;max-height:80vh;overflow:auto">
    <h3 style="margin:0 0 12px;font-size:16px;color:#0F1C3A">🖨 Printer Thermal</h3>
    <div style="font-size:13px;color:#374151;margin-bottom:8px">Terpilih: <strong id="printerCurrent">—</strong></div>
    <button class="btn btn-teal-sm" onclick="posPickPrinter()">🔍 Cari / Ganti Printer</button>
    <div id="printerList" style="margin-top:10px"></div>
    <label style="display:flex;gap:8px;align-items:center;margin-top:14px;font-size:13px;cursor:pointer">
      <input type="checkbox" id="printerAuto" onchange="ThermalPrint.setAuto(this.checked)">
      Auto-cetak struk setelah simpan order
    </label>
    <p style="font-size:11px;color:#9CA3AF;margin:10px 0 0">Printer harus sudah di-pair di Setelan Bluetooth HP.</p>
    <button class="btn btn-outline btn-sm" style="margin-top:12px;width:100%" onclick="posTestPrint()">🧪 Tes Cetak Teks (diagnostik)</button>
    <div style="text-align:right;margin-top:14px"><button class="btn btn-outline" onclick="document.getElementById('printerModal').style.display='none'">Tutup</button></div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (window.ThermalPrint && ThermalPrint.isAvailable()) {
    var b = document.getElementById('btnPrinterSetting'); if (b) b.style.display = '';
  }
});
// Diagnostik boot: snapshot kemampuan APK dikirim ke server saat POS dibuka dari
// WebView Android — mendeteksi kenapa plugin printer tak terdeteksi tanpa akses device.
document.addEventListener('DOMContentLoaded', function () {
  try {
    var ua = navigator.userAgent || '';
    if (!/Android/i.test(ua)) return; // browser desktop: jangan spam log
    var cap = window.Capacitor || null;
    var info = {
      boot: 'pos-print-diag',
      ua: ua.slice(0, 140),
      capacitor: !!cap,
      isNative: !!(cap && typeof cap.isNativePlatform === 'function' && cap.isNativePlatform()),
      platform: (cap && typeof cap.getPlatform === 'function') ? cap.getPlatform() : null,
      plugins: (cap && cap.Plugins) ? Object.keys(cap.Plugins).slice(0, 30) : null,
      thermalPlugin: !!(cap && cap.Plugins && cap.Plugins.CapacitorThermalPrinter),
      tpAvail: window.ThermalPrint ? ThermalPrint.isAvailable() : 'ThermalPrint undefined',
      printerSaved: window.ThermalPrint ? ThermalPrint.getPrinter() : null
    };
    fetch('/api/print_debug.php', { method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
      body: JSON.stringify({ trace: JSON.stringify(info) }) });
  } catch (e) {}
});
// Diagnostik: kirim TEKS polos (bukan gambar) untuk mengisolasi masalah cetak.
// Teks bersih → masalah di raster image(); teks juga acak → encoding/koneksi.
async function posTestPrint() {
  const p = (window.Capacitor && Capacitor.Plugins) ? Capacitor.Plugins.CapacitorThermalPrinter : null;
  const pr = window.ThermalPrint ? ThermalPrint.getPrinter() : null;
  // Trace tiap langkah → dikirim ke server (saas_error_log type=print_debug)
  // supaya hasil di device bisa dibaca dari server tanpa remote debugging.
  const T = [];
  const log = (s, v) => { try { T.push(s + (v !== undefined ? ': ' + (typeof v === 'string' ? v : JSON.stringify(v)) : '')); } catch (e) { T.push(s + ': [unserializable]'); } };
  const send = async () => { try { await fetch('/api/print_debug.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() }, body: JSON.stringify({ trace: T.join('\n') }) }); } catch (e) {} };
  log('ts', new Date().toISOString());
  log('ua', navigator.userAgent.slice(0, 100));
  log('plugin ada', !!p);
  log('printer tersimpan', pr);
  if (!p) { showToast('Plugin printer tak terdeteksi', 'error'); await send(); return; }
  if (!pr || !pr.address) { showToast('Pilih printer dulu', 'error'); await send(); return; }
  showToast('🧪 Tes cetak teks…', 'info');
  let cid = {};
  const arg = (x) => Object.assign({}, cid, x || {});
  const step = async (name, fn) => {
    try { const r = await fn(); log('OK ' + name, r); return r; }
    catch (e) { log('ERR ' + name, (e && (e.message || e.code)) || String(e)); throw e; }
  };
  try {
    const res = await step('connect', () => p.connect({ address: pr.address, encoding: 'GBK' }));
    if (res && res.connectionId) cid = { connectionId: res.connectionId };
    try { await step('isConnected', () => p.isConnected(arg())); } catch (e) {}
    await step('begin', () => p.begin(arg()));
    await step('align-center', () => p.align(arg({ alignment: 'center' })));
    await step('text-judul', () => p.text(arg({ text: 'LAMASY TES CETAK\n' })));
    await step('align-left', () => p.align(arg({ alignment: 'left' })));
    await step('text-angka', () => p.text(arg({ text: 'Angka  : 1234567890\n' })));
    await step('text-rupiah', () => p.text(arg({ text: 'Rupiah : Rp 61.600\n' })));
    await step('text-item', () => p.text(arg({ text: 'Item   : Reguler 7.7 kg\n' })));
    await step('feedCutPaper', () => p.feedCutPaper(arg({ half: false, feedLines: 3 })));
    await step('write', () => p.write(arg()));
    try { await step('disconnect', () => p.disconnect(arg())); } catch (e) {}
    showToast('✅ Tes terkirim & trace tercatat — cek kertasnya', 'success');
  } catch (e) {
    showToast('❌ ' + (e.message || 'Gagal tes') + ' (trace tercatat)', 'error');
  }
  await send();
}

function posOpenPrinterModal() {
  var pr = ThermalPrint.getPrinter();
  document.getElementById('printerCurrent').textContent = pr ? pr.name : '—';
  document.getElementById('printerAuto').checked = ThermalPrint.autoEnabled();
  document.getElementById('printerList').innerHTML = '';
  document.getElementById('printerModal').style.display = 'flex';
}
async function posPickPrinter() {
  var box = document.getElementById('printerList');
  box.innerHTML = '<div style="font-size:12px;color:#6B7280">🔍 Mencari printer (±6 dtk)…</div>';
  try {
    var list = await ThermalPrint.scanPrinters(6000);
    if (!list.length) { box.innerHTML = '<div style="font-size:12px;color:#DC2626">Tak ada printer ketemu. Pastikan printer nyala & sudah di-pair di Setelan Bluetooth.</div>'; return; }
    box.innerHTML = list.map(function (p) {
      return '<button class="btn btn-teal-sm" style="display:block;width:100%;margin:4px 0;text-align:left" ' +
             'onclick=\'posSelectPrinter(' + JSON.stringify(p).replace(/'/g, "&#39;") + ')\'>🖨 ' + esc(p.name) + '</button>';
    }).join('');
  } catch (e) { box.innerHTML = '<div style="font-size:12px;color:#DC2626">Gagal: ' + esc(e.message || 'error') + '</div>'; }
}
function posSelectPrinter(p) {
  ThermalPrint.setPrinter(p);
  document.getElementById('printerCurrent').textContent = p.name;
  showToast('✅ Printer dipilih: ' + p.name, 'success');
}
</script>

<div class="main">
  <div class="grid-2">

    <!-- KOLOM KIRI: Form + Items -->
    <div>

      <!-- INFO PELANGGAN -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">👤 Informasi Pelanggan</div>
          <span id="noOrderBadge" style="font-family:var(--mono);font-size:12px;color:var(--teal)"></span>
        </div>
        <div class="card-body">
          <div class="form-row">
            <div class="form-group">
              <label>Tanggal <span class="req">*</span></label>
              <input type="date" id="f_tanggal"/>
            </div>
            <div class="form-group">
              <label>Estimasi Selesai</label>
              <input type="date" id="f_estimasi"/>
              <small id="estHint" style="display:block;margin-top:4px;font-size:11px;color:#0891B2;font-weight:600">⏱ Memuat saran…</small>
            </div>
          </div>
          <style>
            #voiceOrderBtn.voice-rec{background:#EF4444!important;border-color:#EF4444!important;color:#fff!important;animation:voicePulse 1s ease-in-out infinite}
            @keyframes voicePulse{0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.5)}50%{box-shadow:0 0 0 8px rgba(239,68,68,0)}}
          </style>
          <div class="form-row">
            <div class="form-group full">
              <button type="button" id="voiceOrderBtn" class="btn btn-teal-sm" style="display:none;width:100%" onclick="voiceOrderStart()" title="Input order dengan suara">🎤 Voice Order</button>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group full">
              <label>Nama Pelanggan <span class="req">*</span></label>
              <div class="autocomplete-wrap">
                <input type="text" id="f_nama" data-tour="t_pos_customer" placeholder="Ketik nama atau cari pelanggan..."
                  autocomplete="off" oninput="searchPelanggan(this.value)"/>
                <div class="autocomplete-list" id="acList"></div>
              </div>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>No. Telepon <span class="req<?= empty($outletQrisData['telepon_wajib']) ? ' opt' : '' ?>" id="f_telepon_req"><?= empty($outletQrisData['telepon_wajib']) ? '(opsional)' : '*' ?></span></label>
              <input type="tel" id="f_telepon" placeholder="08xxxxxxxxxx"/>
            </div>
            <div class="form-group">
              <label>Parfum / Pewangi <span style="font-size:10px;color:var(--gray);font-weight:400;">— opsional</span></label>
              <input type="text" id="f_parfum" list="parfumList" placeholder="Lavender, Original, Rose, dll"/>
              <datalist id="parfumList"></datalist>
            </div>
          </div>
          <!-- Member badge — muncul saat pelanggan punya membership aktif -->
          <div id="memberBadgeBox" style="display:none;background:#FEF3C7;border:1px solid #FDE68A;border-radius:8px;padding:8px 14px;margin:0 0 12px;font-size:12.5px;color:#92400E;">
            <!-- isi auto by loadMemberInfo() -->
          </div>
          <?php if ($referralCfg['enabled']): ?>
          <div class="form-row" id="referralRow">
            <div class="form-group full">
              <label>Kode Referral <span style="font-size:10px;color:var(--gray);font-weight:400;">— opsional, untuk pelanggan baru</span></label>
              <input type="text" id="f_referral_code" placeholder="Masukkan kode referral..."
                style="text-transform:uppercase;letter-spacing:.08em;font-family:var(--mono)"
                oninput="this.value=this.value.toUpperCase()"/>
            </div>
          </div>
          <?php endif; ?>
          <div class="form-row">
            <div class="form-group full">
              <label>Catatan Order</label>
              <textarea id="f_catatan" placeholder="Warna, permintaan khusus, kondisi pakaian, dll..." style="min-height:80px"></textarea>
            </div>
          </div>
        </div>
      </div>

      <!-- ANTAR KE PELANGGAN — card toggle style -->
      <label class="hl-antar-toggle" id="antarToggleCard">
        <input type="checkbox" id="cb_antar" onchange="toggleAntarSection()">
        <div class="hl-antar-icon">🛵</div>
        <div class="hl-antar-text">
          <div class="hl-antar-title">Antar ke Pelanggan</div>
          <div class="hl-antar-sub">Aktifkan kalau pelanggan mau cucian diantar</div>
        </div>
        <div class="hl-antar-check"></div>
      </label>
      <div id="antarSection" style="display:none;margin:0 0 14px;padding:14px;background:#fff;border:1px solid #BBF0EA;border-radius:10px">
        <label style="font-size:12px;font-weight:600;color:var(--gray);text-transform:uppercase;letter-spacing:.06em">Alamat (opsional)</label>
        <textarea id="antar_alamat" class="input" rows="2" placeholder="Jl. Mawar 12, RT 03/RW 04..."></textarea>
        <label style="font-size:12px;font-weight:600;color:var(--gray);text-transform:uppercase;letter-spacing:.06em;margin-top:8px;display:block">Patokan/Catatan</label>
        <input type="text" id="antar_catatan" class="input" placeholder="Dekat warung Bu Inah">
        <div id="antarZonaWrap" style="display:none;margin-top:8px">
          <label style="font-size:12px;font-weight:600;color:var(--gray);text-transform:uppercase;letter-spacing:.06em">Zona</label>
          <select id="antar_zona" class="input"><option value="">-- Pilih zona --</option></select>
        </div>
      </div>

      <!-- ITEM LAYANAN -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">🧺 Layanan yang Digunakan</div>
        </div>
        <div class="card-body" style="padding-bottom:12px">

          <!-- Quick pick layanan -->
          <div style="margin-bottom:12px">
            <div style="display:flex;gap:8px;margin-bottom:8px">
              <input type="text" class="layanan-search" id="layananSearch"
                placeholder="🔍 Cari layanan..." oninput="filterLayanan(this.value)"
                style="flex:1;margin:0"/>
              <?php if (hasPermission('layanan.create')): ?>
              <button type="button" class="btn btn-teal-sm" onclick="openLayananQuick()" style="white-space:nowrap" title="Tambah layanan baru cepat">+ Layanan</button>
              <?php endif; ?>
            </div>
            <div class="layanan-grid" id="layananGrid" data-tour="t_pos_layanan">
              <div style="color:var(--gray);font-size:13px;padding:8px">Memuat layanan...</div>
            </div>
          </div>

          <!-- Quick-create layanan modal -->
          <div id="lynQuickModal" style="display:none;position:fixed;inset:0;background:rgba(15,28,58,.65);z-index:9998;align-items:center;justify-content:center;padding:20px">
            <div style="background:#fff;border-radius:14px;padding:24px;max-width:440px;width:100%;max-height:90vh;overflow-y:auto">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
                <h3 style="margin:0;font-size:16px;font-weight:800;color:var(--navy)">➕ Tambah Layanan Baru</h3>
                <button onclick="closeLayananQuick()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--gray)">×</button>
              </div>
              <div class="form-group" style="margin-bottom:10px">
                <label>Nama Layanan *</label>
                <input type="text" id="lyn_q_nama" class="input" placeholder="Misal: Cuci Setrika Reguler"/>
              </div>
              <div class="form-row" style="margin-bottom:10px">
                <div class="form-group">
                  <label>Kategori</label>
                  <input type="text" id="lyn_q_kategori" class="input" placeholder="Reguler/Express/Premium"/>
                </div>
                <div class="form-group">
                  <label>Satuan</label>
                  <select id="lyn_q_satuan" class="input">
                    <option value="kg">kg</option>
                    <option value="pcs">pcs</option>
                    <option value="set">set</option>
                    <option value="m2">m²</option>
                  </select>
                </div>
              </div>
              <div class="form-row" style="margin-bottom:10px">
                <div class="form-group">
                  <label>Harga / Satuan (Rp)</label>
                  <input type="number" id="lyn_q_harga" class="lm-rp input" placeholder="7000" min="0" step="500"/>
                </div>
                <div class="form-group">
                  <label>Estimasi *</label>
                  <div style="display:flex;gap:6px">
                    <input type="number" id="lyn_q_jam" class="input" value="24" min="1" placeholder="24" style="flex:1"/>
                    <select id="lyn_q_unit" class="input" style="width:84px" onchange="lynUnitChanged()">
                      <option value="jam">Jam</option>
                      <option value="hari">Hari</option>
                    </select>
                  </div>
                </div>
              </div>
              <div class="form-group" style="margin-bottom:14px">
                <label>Min. Order (opsional)</label>
                <input type="number" id="lyn_q_min" class="input" value="0" min="0" step="0.5" placeholder="0 = tidak ada minimum"/>
              </div>
              <div style="display:flex;gap:10px">
                <button class="btn btn-secondary" onclick="closeLayananQuick()" style="flex:1">Batal</button>
                <button class="btn btn-primary" onclick="saveLayananQuick()" style="flex:1.5">💾 Simpan & Pakai</button>
              </div>
            </div>
          </div>

          <!-- Table items -->
          <div class="items-table-wrap" style="overflow-x:auto">
            <table class="items-table">
              <thead>
                <tr>
                  <th style="min-width:130px">Layanan</th>
                  <th style="width:60px">Satuan</th>
                  <th style="width:70px">Jumlah</th>
                  <th style="width:100px">Harga/Sat</th>
                  <th style="width:90px">Subtotal</th>
                  <th style="width:140px">⚡ Express</th>
                  <th style="width:80px">Catatan</th>
                  <th style="width:36px"></th>
                </tr>
              </thead>
              <tbody id="itemsBody"></tbody>
            </table>
          </div>
          <div id="emptyItems" style="text-align:center;padding:20px;color:var(--gray);font-size:14px">
            Pilih layanan di atas dari daftar
          </div>

          <!-- FOTO KONDISI CUCIAN -->
          <div style="margin-top:16px;padding-top:14px;border-top:1px dashed rgba(27,45,90,.1)">
            <label style="font-size:12px;font-weight:600;color:var(--gray);display:block;margin-bottom:6px">
              📸 Foto Kondisi Cucian (opsional)
            </label>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
              <input type="file" id="f_foto" accept="image/*" capture="environment"
                     style="font-size:13px" onchange="uploadFotoMasuk(this)"/>
              <span id="fotoStatus" style="font-size:12px;color:var(--gray)"></span>
              <button type="button" class="btn btn-outline btn-sm" id="btnFotoClear" onclick="clearFoto()" style="display:none">✕ Hapus</button>
            </div>
            <img id="fotoPreview" style="display:none;max-height:80px;border-radius:8px;margin-top:8px;border:1px solid rgba(27,45,90,.1)"/>
            <input type="hidden" id="f_foto_path" value=""/>
          </div>
        </div>
      </div>

    </div>

    <!-- KOLOM KANAN: Summary + Bayar -->
    <div style="position:sticky;top:72px">

      <!-- SUMMARY -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">💰 Ringkasan Pembayaran</div>
        </div>
        <div class="card-body">
          <div class="summary-box" data-tour="t_pos_cart">
            <div class="sum-row">
              <span class="sum-label">Subtotal</span>
              <span class="sum-value" id="sumSubtotal">Rp 0</span>
            </div>
            <div class="sum-row">
              <span class="sum-label">Diskon</span>
              <span class="sum-value" style="color:#FCA5A5">- Rp <span id="sumDiskon">0</span></span>
            </div>
            <div class="sum-row" id="sumBiayaRow" style="display:none">
              <span class="sum-label">Biaya Tambahan</span>
              <span class="sum-value" style="color:#FCD34D">+ Rp <span id="sumBiaya">0</span></span>
            </div>
            <div class="sum-row total">
              <span style="font-weight:700;color:white">TOTAL</span>
              <span class="sum-value big" id="sumTotal">Rp 0</span>
            </div>
            <div class="sum-row" style="margin-top:8px">
              <span class="sum-label">DP / Bayar</span>
              <span class="sum-value" id="sumDP">Rp 0</span>
            </div>
            <div class="sum-row">
              <span class="sum-label">Sisa Bayar</span>
              <span class="sum-value sisa" id="sumSisa">Rp 0</span>
            </div>
            <div class="sum-row" id="sumKembalianRow" style="display:none">
              <span class="sum-label">Kembalian</span>
              <span class="sum-value" style="color:#6EE7B7" id="sumKembalian">Rp 0</span>
            </div>
          </div>

          <div style="margin-top:16px;display:flex;flex-direction:column;gap:10px">

            <!-- VOUCHER -->
            <div style="display:flex;gap:8px;align-items:flex-end">
              <div class="form-group" style="flex:1;margin-bottom:0">
                <label>🎟️ Kode Voucher / Promo</label>
                <input type="text" id="f_voucher" placeholder="Masukkan kode..."
                  style="text-transform:uppercase;letter-spacing:.08em;font-family:var(--mono)"
                  oninput="this.value=this.value.toUpperCase()"/>
              </div>
              <button type="button" id="btnApplyVoucher" class="btn btn-teal-sm" onclick="applyVoucher()" style="margin-bottom:1px;white-space:nowrap">
                ✓ Pakai
              </button>
            </div>
            <div id="voucherInfo" style="display:none">
              <div id="voucherInfoText"></div>
              <button type="button" onclick="removeVoucher()" style="background:none;border:none;color:var(--red);font-size:12px;cursor:pointer;margin-top:4px;padding:0">✕ Hapus kode</button>
            </div>

            <!-- LOYALTY REDEEM -->
            <div id="loyaltyBox" style="display:none;background:#F0FDFB;border:1px solid #B6F0E6;border-radius:8px;padding:11px 13px">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                <span style="font-size:13px;font-weight:700;color:#0F1C3A">
                  ⭐ Poin Loyalty
                  <span id="loyaltyTierBadge" style="margin-left:6px;font-size:10px;font-weight:700;padding:2px 7px;border-radius:100px;background:#fff;color:#0891B2;display:none"></span>
                  <span id="loyaltySegmenBadge" style="margin-left:4px;font-size:10px;font-weight:700;padding:2px 7px;border-radius:100px;display:none"></span>
                </span>
                <span style="font-size:12px;color:#0891B2;font-weight:700"><span id="loyaltyBal">0</span> poin</span>
              </div>

              <!-- DAFTAR REWARD (dynamic) -->
              <div id="rewardsList" style="display:none;margin-bottom:8px;max-height:180px;overflow-y:auto"></div>
              <input type="hidden" id="f_reward_id" value="0"> <!-- ponytail: reward_id for loyalty redeem -->

              <!-- INPUT MANUAL POIN -->
              <div style="display:flex;gap:8px;align-items:flex-end;padding-top:8px;border-top:1px dashed rgba(8,145,178,.25)">
                <div class="form-group" style="flex:1;margin-bottom:0">
                  <label style="font-size:11px">Tukar Poin (manual)</label>
                  <input type="number" id="f_redeem_poin" value="0" min="0" oninput="document.getElementById('f_reward_id').value='0'; recalc()"/>
                </div>
                <button type="button" class="btn btn-teal-sm" onclick="redeemMax()" style="margin-bottom:1px;white-space:nowrap">Max</button>
              </div>
              <div id="redeemInfo" style="font-size:11px;color:#0891B2;margin-top:5px;display:none"></div>
            </div>

            <!-- Biaya Tambahan now auto-derived dari per-item tier (read-only display) -->
            <div class="form-group" id="biayaTambahanBox" style="display:none;background:#FEF3C7;border:1px solid #FDE68A;border-radius:8px;padding:10px 14px;margin-bottom:8px;font-size:12.5px;color:#92400E;">
              ⚡ <strong>Total Biaya Express:</strong> Rp <span id="biayaTotalDisplay">0</span>
              <span style="display:block;font-size:11px;color:#A16207;margin-top:2px;">Otomatis dari pilihan tier di tiap baris item</span>
              <input type="hidden" id="f_biaya_tambahan" value="0"/>
              <input type="hidden" id="f_tipe_order" value="reguler"/>
            </div>

            <!-- Biaya Lainnya — OTOMATIS dari tier aktif, read-only, tidak ada input -->
            <div class="form-group" id="biayaLainnyaBox" style="display:none;background:#E0F2FE;border:1px solid #BAE6FD;border-radius:8px;padding:10px 14px;margin-bottom:8px;font-size:12.5px;color:#0C4A6E;">
              💰 <strong>Biaya Lainnya (otomatis):</strong>
              <div id="biayaLainnyaBreakdown" style="margin-top:4px"></div>
            </div>

            <div class="form-row cols3">
              <div class="form-group">
                <label>Diskon (Rp)</label>
                <input type="number" id="f_diskon" value="0" min="0"
                  onfocus="this.value=''"
                  onblur="if(this.value===''){ this.value='0'; recalc(); }"
                  oninput="lmCleanNum(this,false);recalc()"/>
              </div>
              <div class="form-group">
                <label>DP / Bayar</label>
                <input class="lm-rp" type="number" id="f_dp" value="0" min="0"
                  onfocus="this.value=''"
                  onblur="if(this.value===''){ this.value='0'; recalc(); }"
                  oninput="recalc()"/>
              </div>
              <script>
                // f_dp punya class lm-rp -> components.php enhanceRp() sembunyikan input asli
                // & bikin sibling <input> visible ("vis") yg beneran disentuh user (buat separator
                // ribuan live). onfocus/onblur di elemen asli di atas gak pernah kepanggil krn user
                // gak pernah fokus ke situ. Delegasikan ke vis (nextElementSibling dari #f_dp).
                (function(){
                  document.addEventListener('focus', function(e){
                    var dp = document.getElementById('f_dp');
                    if (dp && e.target === dp.nextElementSibling) e.target.value = '';
                  }, true);
                  document.addEventListener('blur', function(e){
                    var dp = document.getElementById('f_dp');
                    if (dp && e.target === dp.nextElementSibling && e.target.value === '') {
                      e.target.value = '0';
                      e.target.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                  }, true);
                })();
              </script>
              <div class="form-group">
                <label>Metode</label>
                <select id="f_metode" onchange="onMetodeChange()">
                  <option value="">—</option>
                  <?php foreach ($activeMethods as $m): ?>
                    <?php $isQrisDisabled = ($m['code'] === 'qris' && empty($outletQrisData['qris_image'])); ?>
                    <option value="<?= htmlspecialchars($m['code']) ?>" <?= $isQrisDisabled ? 'disabled' : '' ?>>
                      <?= htmlspecialchars($m['emoji']) ?> <?= htmlspecialchars($m['label']) ?><?= $isQrisDisabled ? ' (belum di-setup)' : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
<script>
window.outletQris = <?= json_encode([
    'image' => $outletQrisData['qris_image'],
    'label' => $outletQrisData['qris_label'],
]) ?>;
const TELEPON_WAJIB = <?= !empty($outletQrisData['telepon_wajib']) ? 'true' : 'false' ?>;
</script>

            <!-- Bayar pakai Saldo Deposit (Phase 4.1) — muncul kalau pelanggan punya saldo > 0 -->
            <div id="depositBox" style="display:none;background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 14px;margin-bottom:8px;font-size:13px;color:#166534">
              <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
                <div>💳 <strong>Saldo Deposit:</strong> <span id="depositBalance">Rp 0</span></div>
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
                  <input type="checkbox" id="f_use_deposit" onchange="onUseDepositChange()"/>
                  <span>Bayar pakai Saldo</span>
                </label>
              </div>
              <div id="depositAmountWrap" style="display:none;margin-top:8px">
                <label style="font-size:11px;color:#166534">Jumlah dari Saldo (max sesuai saldo & total):</label>
                <input class="lm-rp" type="number" id="f_deposit_amount" value="0" min="0" oninput="recalc()" style="width:100%;padding:6px 10px;border:1px solid #BBF7D0;border-radius:6px;font-size:13px"/>
              </div>
            </div>

            <div id="statusBayarInfo" style="text-align:center;font-size:13px;font-weight:600;padding:8px;border-radius:8px;background:var(--light);color:var(--gray)">
              Belum ada item
            </div>

            <button class="btn btn-primary" id="btnSave" data-tour="t_pos_save" onclick="saveTransaksi()" disabled>
              💾 Simpan & Print Struk
            </button>
            <button class="btn btn-outline" onclick="resetForm()">
              ↺ Reset Form
            </button>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- FLOATING AI CHATBOT -->
<div id="aiFloating" style="display:none">
  <button id="aiBubbleBtn" onclick="toggleAIChat()"
    style="position:fixed;bottom:24px;right:24px;z-index:1000;
           width:52px;height:52px;border-radius:50%;border:none;cursor:pointer;
           background:linear-gradient(135deg,#667eea,#764ba2);
           color:white;font-size:20px;box-shadow:0 4px 20px rgba(102,126,234,.5);
           transition:all .3s;display:flex;align-items:center;justify-content:center">
    ✨
  </button>
  <div id="aiNotifDot" style="display:none;position:fixed;bottom:66px;right:24px;z-index:1001;
    width:12px;height:12px;background:var(--red);border-radius:50%;border:2px solid white"></div>
  <div id="aiChatPanel"
    style="display:none;position:fixed;bottom:88px;right:24px;z-index:999;
           width:340px;max-height:520px;
           background:white;border-radius:16px;
           box-shadow:0 8px 40px rgba(27,45,90,.2);
           border:1px solid rgba(139,92,246,.2);
           flex-direction:column;overflow:hidden">
    <div style="background:linear-gradient(135deg,#667eea,#764ba2);padding:14px 16px;display:flex;align-items:center;justify-content:space-between">
      <div style="display:flex;align-items:center;gap:10px">
        <div style="width:32px;height:32px;background:rgba(255,255,255,.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px">✨</div>
        <div>
          <div style="color:white;font-weight:700;font-size:14px">AI Assistant</div>
          <div style="color:rgba(255,255,255,.7);font-size:11px" id="aiStatusText">Pilih customer dulu</div>
        </div>
      </div>
      <div style="display:flex;gap:6px">
        <button onclick="loadAIRekomendasi()" id="btnRefreshAI"
          style="background:rgba(255,255,255,.2);border:none;color:white;border-radius:8px;padding:5px 10px;cursor:pointer;font-size:12px;font-weight:600">↻</button>
        <button onclick="toggleAIChat()"
          style="background:rgba(255,255,255,.2);border:none;color:white;border-radius:8px;padding:5px 10px;cursor:pointer;font-size:14px">✕</button>
      </div>
    </div>
    <div id="aiContent" style="flex:1;overflow-y:auto;padding:14px;font-size:13px;max-height:420px;background:var(--off)">
      <div style="text-align:center;padding:32px 16px;color:var(--gray)">
        <div style="font-size:2rem;margin-bottom:8px;opacity:.4">✨</div>
        <div style="font-size:13px;font-weight:600;margin-bottom:4px">AI Upselling Assistant</div>
        <div style="font-size:12px">Pilih customer di form untuk mendapatkan rekomendasi layanan</div>
      </div>
    </div>
  </div>
</div>

<!-- MODAL STRUK -->
<div class="modal-overlay" id="modalStruk">
  <div class="modal" style="max-width:520px">
    <div class="modal-header">
      <span class="modal-title">🧾 Struk Pembayaran</span>
      <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-body" style="padding:8px;background:#f4f6fb;min-height:300px;display:flex;align-items:center;justify-content:center">
      <iframe id="strukFrame"
              style="border:none;background:#fff;width:100%;min-height:420px;border-radius:6px;box-shadow:0 2px 12px rgba(0,0,0,.1)"
              title="Struk"></iframe>
      <div id="strukLoading" style="display:none;text-align:center;color:#6B7280;padding:40px">
        ⏳ Memuat struk…
      </div>
    </div>
    <div class="modal-footer" style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
      <button class="btn btn-green" style="grid-column:1/-1" onclick="printStruk()">🖨️ Print Struk</button>
      <button id="btnPrinterSetting" class="btn btn-teal-sm" style="display:none" onclick="posOpenPrinterModal()">⚙️ Printer</button>
      <button class="btn btn-teal-sm" onclick="printLabel()" title="Cetak label stiker (ukuran diatur di Outlet Settings)">🏷 Print Label</button>
      <button class="btn btn-teal-sm" onclick="kirimNotaWA()" title="Kirim nota via WhatsApp (150 koin)">📲 Kirim WA</button>
      <a id="openStrukBtn" href="#" target="_blank" class="btn btn-teal-sm" style="text-align:center">↗ Buka Penuh</a>
      <button class="btn btn-teal-sm" onclick="window.location.href='/orders'">📋 Orders</button>
      <button class="btn btn-outline" style="grid-column:1/-1" onclick="closeModal()">Tutup</button>
    </div>
  </div>
</div>

<!-- QR Display Modal — Static QRIS Payment -->
<div class="modal-overlay" id="modalQris" style="display:none">
  <div class="modal" style="max-width:440px">
    <div class="modal-header">
      <span class="modal-title">💳 Pembayaran QRIS</span>
      <button class="modal-close" onclick="closeQrisModal()">✕</button>
    </div>
    <div class="modal-body" style="padding:20px;text-align:center">
      <div style="font-size:13px;color:#6b7280;margin-bottom:4px">Total Pembayaran</div>
      <div style="font-size:28px;font-weight:700;color:#0d9488;margin-bottom:16px">
        Rp <span id="qrisAmount">0</span>
      </div>

      <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;display:inline-block;margin-bottom:12px">
        <img id="qrisImageEl" src="" alt="QRIS"
             style="display:block;width:280px;height:280px;object-fit:contain">
      </div>

      <div id="qrisLabelEl" style="font-weight:600;color:#374151;margin-bottom:16px"></div>

      <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:12px;text-align:left;font-size:13px;color:#78350f;margin-bottom:16px">
        <strong>Cara bayar:</strong>
        <ol style="margin:6px 0 0 18px;padding:0">
          <li>Customer scan QR pakai banking app</li>
          <li>Cek banking app outlet untuk notif masuk</li>
          <li>Pastikan nominal masuk sesuai total</li>
          <li>Klik tombol di bawah</li>
        </ol>
      </div>
    </div>
    <div class="modal-footer" style="gap:8px;flex-wrap:wrap">
      <button class="btn btn-primary" style="flex:1;padding:12px" onclick="confirmQrisPayment()">
        ✓ Pembayaran Diterima
      </button>
      <button class="btn btn-outline" style="flex:1;padding:12px" onclick="cancelQrisPayment()">
        Batal
      </button>
    </div>
  </div>
</div>

<script>
let items = [];
let layananAll = [];
let lastSaved  = null;
let acTimeout  = null;

// ── Estimasi auto-suggest dari antrian ──
async function loadEstimasiHint(){
  const el = document.getElementById('estHint');
  if (!el) return;
  try {
    const r = await fetch('pos.php?action=estimasi_suggest');
    const d = await r.json();
    if (d.error || !d.ok) { el.textContent = ''; return; }
    el.innerHTML = `⏱ Saran: <strong>${d.label}</strong> (${d.jam}j, antrian ${d.antrian} order)`;
    // Auto-isi date kalau kosong
    const fe = document.getElementById('f_estimasi');
    if (fe && !fe.value) fe.value = d.date_only;
  } catch(e){ /* silent */ }
}
const LOYALTY = <?= json_encode(['enabled'=>$loyaltyCfg['enabled'],'poin_value'=>$loyaltyCfg['poin_value'],'rupiah_per_poin'=>$loyaltyCfg['rupiah_per_poin']]) ?>;
let currentPelangganPoin = 0;

function localDateStr(d) {
  const dt = d || new Date();
  return dt.getFullYear() + '-' +
    String(dt.getMonth()+1).padStart(2,'0') + '-' +
    String(dt.getDate()).padStart(2,'0');
}

document.addEventListener('DOMContentLoaded', () => {
  const today = localDateStr();
  document.getElementById('f_tanggal').value = today;
  // Kosongkan estimasi dulu — loadEstimasiHint akan isi otomatis sesuai antrian
  loadLayanan();
  loadEstimasiHint();
  loadGlobalTiers();
  loadBiayaLainnyaTiers();
  loadParfumList();

  // ── KEYBOARD SHORTCUTS ──
  document.addEventListener('keydown', (e) => {
    // Skip kalau lagi ketik di input/textarea (kecuali F2/F3/Esc yang harus tetap aktif)
    const tag = (e.target.tagName||'').toLowerCase();
    const isField = tag === 'input' || tag === 'textarea' || tag === 'select';

    if (e.key === 'F2') {
      e.preventDefault();
      const el = document.getElementById('f_nama'); if (el) el.focus();
    } else if (e.key === 'F3') {
      e.preventDefault();
      saveTransaksi();
    } else if (e.key === 'Escape') {
      const cfm = document.getElementById('confirmSaveModal');
      if (cfm && cfm.style.display === 'flex') { closeCfm(); return; }
      if (!isField) resetForm();
    } else if (e.key === 'Enter') {
      const cfm = document.getElementById('confirmSaveModal');
      if (cfm && cfm.style.display === 'flex') { e.preventDefault(); doSaveTransaksi(); }
    }
  });
});

// ── FOTO MASUK UPLOAD ────────────────────────────────
async function uploadFotoMasuk(input) {
  const f = input.files && input.files[0];
  if (!f) return;
  const status = document.getElementById('fotoStatus');
  status.textContent = '⏳ Mengunggah...';
  const fd = new FormData();
  fd.append('foto', f);
  try {
    const r = await fetch('pos.php?action=upload_foto', {
      method:'POST',
      headers:{'X-CSRF-Token':csrfToken()},
      body: fd
    });
    const d = await r.json();
    if (d.error) { status.textContent = '❌ ' + d.error; status.style.color = 'var(--red)'; return; }
    document.getElementById('f_foto_path').value = d.path;
    const prev = document.getElementById('fotoPreview');
    prev.src = '/' + d.path;
    prev.style.display = 'block';
    document.getElementById('btnFotoClear').style.display = '';
    status.textContent = '✓ Terunggah';
    status.style.color = 'var(--green)';
  } catch(e){ status.textContent = '❌ Network error'; status.style.color = 'var(--red)'; }
}

function clearFoto() {
  document.getElementById('f_foto').value = '';
  document.getElementById('f_foto_path').value = '';
  document.getElementById('fotoPreview').style.display = 'none';
  document.getElementById('fotoPreview').src = '';
  document.getElementById('fotoStatus').textContent = '';
  document.getElementById('btnFotoClear').style.display = 'none';
}

// ── KONFIRMASI MODAL ────────────────────────────────
function closeCfm(){ document.getElementById('confirmSaveModal').style.display = 'none'; }

async function loadLayanan() {
  const res = await fetch('pos.php?action=get_layanan');
  layananAll = await res.json();
  renderLayananGrid(layananAll);
}

function renderLayananGrid(list) {
  const grid = document.getElementById('layananGrid');
  if (!list.length) {
    grid.innerHTML = '<div style="color:var(--gray);font-size:13px;padding:8px;grid-column:1/-1">Tidak ada layanan</div>';
    return;
  }
  grid.innerHTML = list.map(l => `
    <button class="layanan-btn" onclick="addLayananItem(${l.id},'${esc(l.nama)}','${l.satuan}',${l.harga})">
      <div class="l-kat">${esc(l.kategori||'')}</div>
      <div class="l-nama">${esc(l.nama)}</div>
      <div class="l-harga">Rp ${parseFloat(l.harga).toLocaleString('id-ID')}/${l.satuan}</div>
    </button>`).join('');
}

function filterLayanan(q) {
  const filtered = q
    ? layananAll.filter(l => l.nama.toLowerCase().includes(q.toLowerCase()) || (l.kategori||'').toLowerCase().includes(q.toLowerCase()))
    : layananAll;
  renderLayananGrid(filtered);
}

function addLayananItem(id, nama, satuan, harga) {
  // Lookup layanan utk qty_minimum + estimasi_jam
  const lyn = (layananAll || []).find(l => l.id == id) || {};
  const qMin = parseFloat(lyn.qty_minimum) || 0;
  const estJam = parseInt(lyn.estimasi_jam) || 24;
  // Default jumlah = qty_minimum kalau ada, else 1
  const defaultJml = qMin > 0 ? qMin : 1;

  const existIdx = items.findIndex(i => i.layanan_id == id && !i.catatan_item);
  if (existIdx >= 0) {
    items[existIdx].jumlah += 1;
    renderItems(); recalc(); applyMaxEstimasi();
    showToast('Quantity ' + nama + ' +1', 'success');
    return;
  }
  const existWithNote = items.findIndex(i => i.layanan_id == id && i.catatan_item);
  if (existWithNote >= 0) {
    lmConfirm(nama + ' sudah ada di daftar (dengan catatan). Tambahkan sebagai baris baru?', {icon:'📋', okText:'Tambah baris', cancelText:'Tidak jadi'}).then(function(ok){
      if (!ok) return;
      items.push({layanan_id:id,nama_layanan:nama,satuan,jumlah:defaultJml,harga_satuan:harga,catatan_item:'',express_tier_nama:null,biaya_express:0,qty_minimum:qMin,estimasi_jam:estJam,kategori:lyn.kategori||''});
      renderItems(); recalc(); applyMaxEstimasi();
    });
    return;
  }
  items.push({layanan_id:id,nama_layanan:nama,satuan,jumlah:defaultJml,harga_satuan:harga,catatan_item:'',express_tier_nama:null,biaya_express:0,qty_minimum:qMin,estimasi_jam:estJam,kategori:lyn.kategori||''});
  renderItems(); recalc(); applyMaxEstimasi();
  if (qMin > 0) showToast(`Min. order ${nama}: ${qMin} ${satuan}`, 'info');
}

// ── Quick-create layanan modal ──
function lynUnitChanged(){
  // Ganti ke Hari: kosongkan angka (default 24 itu satuan jam) — user isi jumlah hari sendiri.
  // Balik ke Jam: kalau kosong, pulihkan default 24.
  const el = document.getElementById('lyn_q_jam');
  if (document.getElementById('lyn_q_unit').value === 'hari') { el.value = ''; el.placeholder = '1'; }
  else if (!el.value) { el.value = '24'; el.placeholder = '24'; }
}
function openLayananQuick(){
  const m = document.getElementById('lynQuickModal');
  if (m) { m.style.display = 'flex'; document.getElementById('lyn_q_nama').focus(); }
}
function closeLayananQuick(){
  const m = document.getElementById('lynQuickModal');
  if (m) m.style.display = 'none';
  ['lyn_q_nama','lyn_q_kategori','lyn_q_harga','lyn_q_min'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
  document.getElementById('lyn_q_jam').value = '24';
  const _u = document.getElementById('lyn_q_unit'); if (_u) _u.value = 'jam';
  document.getElementById('lyn_q_satuan').value = 'kg';
}
async function saveLayananQuick(){
  const nama = document.getElementById('lyn_q_nama').value.trim();
  if (!nama) { alert('Nama layanan wajib diisi'); return; }
  const payload = {
    nama,
    kategori: document.getElementById('lyn_q_kategori').value.trim() || 'Reguler',
    satuan:   document.getElementById('lyn_q_satuan').value,
    harga:    parseFloat(document.getElementById('lyn_q_harga').value) || 0,
    estimasi_jam: (function(){
      const hari = document.getElementById('lyn_q_unit').value === 'hari';
      // Fallback ikut unit: kosong di mode Hari = 1 hari (bukan 24 hari = 576 jam)
      const n = parseInt(document.getElementById('lyn_q_jam').value) || (hari ? 1 : 24);
      return hari ? n * 24 : n;
    })(),
    qty_minimum:  parseFloat(document.getElementById('lyn_q_min').value) || 0,
  };
  try {
    const r = await fetch('/layanan.php?action=save', {
      method: 'POST',
      headers: { 'Content-Type':'application/json', 'X-CSRF-Token': csrfToken() },
      body: JSON.stringify(payload)
    });
    const d = await r.json();
    if (d.error) { alert('Gagal: ' + d.error); return; }
    showToast('Layanan ditambahkan ✓', 'success');
    closeLayananQuick();
    await loadLayanan();
    // Auto-add ke order kalau ID returned
    if (d.id) {
      const lyn = (layananAll || []).find(l => l.id == d.id);
      if (lyn) addLayananItem(lyn.id, lyn.nama, lyn.satuan, lyn.harga);
    }
  } catch(e){ alert('Gagal koneksi: ' + e.message); }
}

function addEmptyRow() {
  items.push({layanan_id:null,nama_layanan:'',satuan:'kg',jumlah:1,harga_satuan:0,catatan_item:'',express_tier_nama:null,biaya_express:0});
  renderItems();
}

function removeItem(idx) { items.splice(idx,1); renderItems(); recalc(); applyMaxEstimasi(); }

// Pemisah ribuan manual (tak bergantung Intl locale — konsisten di WebView APK)
function grpRibu(n){ return String(Math.round(n)||0).replace(/\B(?=(\d{3})+(?!\d))/g,'.'); }

// type="number" di WebView (terutama Android lawas/entry-level) tidak konsisten
// nolak huruf/simbol spt Chrome desktop — huruf bisa ketik masuk. Bersihkan
// manual tiap oninput: digit doang (decimal=true izinkan satu titik desimal).
function lmCleanNum(el, decimal){
  var v = el.value;
  var cleaned = decimal ? v.replace(/[^0-9.]/g, '').replace(/(\..*)\./g, '$1') : v.replace(/[^0-9]/g, '');
  if (cleaned !== v) el.value = cleaned;
  return cleaned;
}

// Deteksi kategori Self-Service / Tambahan Self-Service — normalize toleran
// (dipakai buat render tombol addon, lihat renderAddonRow()).
function lmNormKat(s){ return String(s||'').toLowerCase().replace(/[^a-z0-9]/g,''); }
function isSelfServiceKat(kat){ return lmNormKat(kat) === 'selfservice'; }
function isAddonKat(kat){ return lmNormKat(kat) === 'tambahanselfservice'; }

function renderItems() {
  const tbody = document.getElementById('itemsBody');
  const empty = document.getElementById('emptyItems');
  if (!items.length) {
    tbody.innerHTML = '';
    empty.style.display = 'block';
    document.getElementById('btnSave').disabled = true;
    return;
  }
  empty.style.display = 'none';
  document.getElementById('btnSave').disabled = false;
  // Strip tombol addon cuma dirender SEKALI, setelah item Self-Service TERAKHIR di keranjang
  // (bukan setelah tiap item Self-Service) — hindari duplikat kalau cart punya 2+ item Self-Service.
  let lastSelfServiceIdx = -1;
  items.forEach((it, idx) => { if (isSelfServiceKat(it.kategori)) lastSelfServiceIdx = idx; });
  tbody.innerHTML = items.map((item, i) => `
    <tr>
      <td data-lbl="Layanan"><input class="item-input" style="width:100%;min-width:120px" value="${esc(item.nama_layanan)}"
        placeholder="Nama layanan" oninput="items[${i}].nama_layanan=this.value;recalc()"/></td>
      <td data-lbl="Satuan"><select class="item-input" style="width:64px" onchange="items[${i}].satuan=this.value">
        ${['kg','pcs','set','pasang'].map(s=>`<option value="${s}" ${item.satuan===s?'selected':''}>${s}</option>`).join('')}
      </select></td>
      <td data-lbl="Jumlah">
        <span class="qty-wrap">
          ${item.qty_minimum > 0 ? `<span class="qty-min${item.jumlah < item.qty_minimum ? ' bad' : ''}">min ${item.qty_minimum}</span>` : ''}
          <input class="item-input" type="number" value="${item.jumlah}" min="0.1" step="0.1" style="${item.qty_minimum > 0 && item.jumlah < item.qty_minimum ? 'border:1px solid #DC2626;background:#FEF2F2;' : ''}"
            onfocus="this.value=''"
            onblur="if(this.value===''){ this.value=(${item.qty_minimum}>0?${item.qty_minimum}:1); items[${i}].jumlah=parseFloat(this.value); recalc(); }"
            oninput="lmCleanNum(this,true);items[${i}].jumlah=parseFloat(this.value)||0;recalc()"/>
        </span>
      </td>
      <td data-lbl="Harga"><input class="item-input" type="text" inputmode="numeric" value="${grpRibu(Math.round(item.harga_satuan||0))}" style="width:96px"
        oninput="const v=parseInt(this.value.replace(/\D/g,''))||0;items[${i}].harga_satuan=v;this.value=grpRibu(v);recalc()"/></td>
      <td data-lbl="Subtotal" class="item-subtotal">Rp ${(item.jumlah*item.harga_satuan).toLocaleString('id-ID')}</td>
      <td data-lbl="Express">
        <select class="item-input" style="width:130px;font-size:11px;" onchange="onItemTierChange(${i}, this.value)">
          <option value="">⏱️ Reguler</option>
          ${availableTiers.map(t => `<option value="${esc(t.nama_tier)}" ${item.express_tier_nama===t.nama_tier?'selected':''}>⚡ ${esc(t.nama_tier)}</option>`).join('')}
        </select>
        ${item.biaya_express > 0 ? `<div style="font-size:10px;color:#92400E;margin-top:2px;">+Rp ${Math.round(item.biaya_express).toLocaleString('id-ID')}</div>` : ''}
      </td>
      <td data-lbl="Catatan"><input class="item-input" value="${esc(item.catatan_item)}" placeholder="..."
        style="width:72px" oninput="items[${i}].catatan_item=this.value"/></td>
      <td><button class="btn-remove" onclick="removeItem(${i})">✕ Hapus</button></td>
    </tr>${i === lastSelfServiceIdx ? renderAddonRow() : ''}`).join('');
}

function renderAddonRow() {
  const addons = (layananAll || []).filter(l => isAddonKat(l.kategori));
  if (!addons.length) return '';
  const btns = addons.map(a => {
    const inCart = items.find(it => it.layanan_id == a.id);
    const qtyBadge = inCart ? ` (${inCart.jumlah}×)` : '';
    return `<button type="button" class="addon-btn"
      onclick="addLayananItem(${a.id},'${esc(a.nama)}','${a.satuan}',${a.harga})">
      + ${esc(a.nama)} Rp${Math.round(a.harga).toLocaleString('id-ID')}${qtyBadge}</button>`;
  }).join('');
  return `<tr class="addon-row"><td colspan="8">${btns}</td></tr>`;
}

// ────────────────────────────────────────────────────
// Express Tier — GLOBAL list, dipilih PER ITEM
// ────────────────────────────────────────────────────
let availableTiers = [];  // {nama_tier, estimasi_jam, tipe_biaya, nilai_biaya}
let availableBiayaLainnyaTiers = [];  // {nama, tipe_biaya, nilai_biaya} — auto-apply, bukan pilihan

// Load parfum dynamic (per outlet)
async function loadParfumList() {
  try {
    const r = await fetch('pos.php?action=parfum_list');
    const d = await r.json();
    const dl = document.getElementById('parfumList');
    if (!dl) return;
    const items = d.parfums || [];
    dl.innerHTML = items.map(p => `<option value="${p.replace(/"/g,'&quot;')}"></option>`).join('');
  } catch(e) { /* silent */ }
}

// Load tier sekali saat halaman ready
async function loadGlobalTiers() {
  try {
    const r = await fetch('pos.php?action=express_tiers');
    const d = await r.json();
    availableTiers = d.tiers || [];
  } catch(e) {
    availableTiers = [];
  }
}

async function loadBiayaLainnyaTiers() {
  try {
    const r = await fetch('pos.php?action=biaya_lainnya_tiers');
    const d = await r.json();
    availableBiayaLainnyaTiers = d.tiers || [];
  } catch(e) {
    availableBiayaLainnyaTiers = [];
  }
  recalc(); // breakdown baru siap, langsung refresh tampilan
}

// Hitung breakdown biaya lainnya dari tier aktif thd subtotal — CUMA
// utk PREVIEW tampilan, nilai final tetap dihitung ulang di server
// (anti-tamper, lihat action=save).
function calcBiayaLainnyaBreakdown(subtotal) {
  return availableBiayaLainnyaTiers
    .map(t => ({
      nama: t.nama,
      nominal: t.tipe_biaya === 'flat' ? parseFloat(t.nilai_biaya) : Math.round(subtotal * (parseFloat(t.nilai_biaya) / 100)),
    }))
    .filter(x => x.nominal > 0);
}

// Pas user pilih/ubah tier di satu baris item → recompute fee item + total
function onItemTierChange(idx, tierName) {
  const item = items[idx];
  if (!item) return;
  item.express_tier_nama = tierName || null;
  item.biaya_express = computeItemFee(item);
  // Update estimasi nota: ambil max estimasi_jam dari semua tier yg dipilih
  applyMaxEstimasi();
  renderItems();
  recalc();
}

function computeItemFee(item) {
  if (!item.express_tier_nama) return 0;
  const tier = availableTiers.find(t => t.nama_tier === item.express_tier_nama);
  if (!tier) return 0;
  const sub = (item.jumlah || 0) * (item.harga_satuan || 0);
  if (tier.tipe_biaya === 'flat') return Math.round(parseFloat(tier.nilai_biaya) || 0);
  return Math.round(sub * (parseFloat(tier.nilai_biaya) || 0) / 100);
}

// Re-compute SEMUA item fee (dipanggil saat jumlah/harga item berubah)
function recomputeAllItemFees() {
  items.forEach(it => { it.biaya_express = computeItemFee(it); });
}

// Tanggal estimasi nota = tanggal + jam tier terlama dari item-item yg express
function applyMaxEstimasi() {
  // Max estimasi_jam dari semua items. Tier express override layanan default.
  let maxJam = 0;
  items.forEach(it => {
    let jam = parseInt(it.estimasi_jam) || 0;  // layanan base
    if (it.express_tier_nama) {
      const tier = availableTiers.find(t => t.nama_tier === it.express_tier_nama);
      if (tier && tier.estimasi_jam) jam = parseInt(tier.estimasi_jam);  // tier override
    }
    if (jam > maxJam) maxJam = jam;
  });
  if (maxJam <= 0) return;  // belum ada item → biarkan default suggestion
  const tglEl = document.getElementById('f_tanggal');
  const baseDate = tglEl?.value ? new Date(tglEl.value + 'T08:00:00') : new Date();
  baseDate.setHours(baseDate.getHours() + maxJam);
  const yyyy = baseDate.getFullYear();
  const mm = String(baseDate.getMonth()+1).padStart(2,'0');
  const dd = String(baseDate.getDate()).padStart(2,'0');
  const estEl = document.getElementById('f_estimasi');
  if (estEl) estEl.value = `${yyyy}-${mm}-${dd}`;
  const hint = document.getElementById('estHint');
  if (hint) {
    const hasTier = items.some(it => it.express_tier_nama);
    hint.innerHTML = `⏱ Estimasi: <strong>${maxJam} jam</strong> dari ${hasTier ? 'tier express' : 'layanan'} yang dipilih`;
  }
}

// Derive tipe_order dominant utk dikirim ke backend
function deriveDominantTipeOrder() {
  const counts = {};
  items.forEach(it => {
    if (!it.express_tier_nama) return;
    counts[it.express_tier_nama] = (counts[it.express_tier_nama] || 0) + 1;
  });
  if (Object.keys(counts).length === 0) {
    return { tipe_order: 'reguler', express_tier_nama: null };
  }
  // Ambil yg paling sering
  const top = Object.entries(counts).sort((a,b) => b[1]-a[1])[0][0];
  const lower = top.toLowerCase();
  const tipe = lower.includes('kilat') ? 'kilat' :
               lower.includes('express') ? 'express' : 'custom';
  return { tipe_order: tipe, express_tier_nama: top };
}

function recalc() {
  // Re-compute item fees (in case jumlah/harga berubah)
  recomputeAllItemFees();
  const subtotal = items.reduce((s,i) => s + i.jumlah*i.harga_satuan, 0);
  const diskon   = parseFloat(document.getElementById('f_diskon').value)||0;
  const biayaTbh = items.reduce((s,i) => s + (i.biaya_express || 0), 0);
  // Sync ke hidden input & display
  const bhEl = document.getElementById('f_biaya_tambahan');
  if (bhEl) bhEl.value = biayaTbh;
  const dominant = deriveDominantTipeOrder();
  const tipeEl = document.getElementById('f_tipe_order');
  if (tipeEl) tipeEl.value = dominant.tipe_order;
  // Box display
  const box = document.getElementById('biayaTambahanBox');
  const disp = document.getElementById('biayaTotalDisplay');
  if (box && disp) {
    if (biayaTbh > 0) {
      box.style.display = 'block';
      disp.textContent = biayaTbh.toLocaleString('id-ID');
    } else {
      box.style.display = 'none';
    }
  }

  // Loyalty redeem → diskon
  let redeemValue = 0, redeemPoin = 0;
  if (LOYALTY.enabled && currentPelangganId) {
    redeemPoin = parseInt(document.getElementById('f_redeem_poin')?.value || 0) || 0;
    const maxByRp = Math.floor(Math.max(0, subtotal-diskon)/LOYALTY.poin_value);
    redeemPoin = Math.max(0, Math.min(redeemPoin, currentPelangganPoin, maxByRp));
    redeemValue = redeemPoin * LOYALTY.poin_value;
    const ri = document.getElementById('redeemInfo');
    if (ri) {
      if (redeemPoin > 0) { ri.style.display='block'; ri.textContent = `−Rp ${redeemValue.toLocaleString('id-ID')} dari ${redeemPoin} poin`; }
      else ri.style.display='none';
    }
  }

  // Total = subtotal − diskon − redeem + biaya tambahan + biaya lainnya
  const biayaLainnyaRows = calcBiayaLainnyaBreakdown(subtotal);
  const biayaLainnya = biayaLainnyaRows.reduce((s, r) => s + r.nominal, 0);
  const total    = Math.max(subtotal - diskon - redeemValue + biayaTbh + biayaLainnya, 0);

  // Render box breakdown (read-only, cuma display)
  const blBox = document.getElementById('biayaLainnyaBox');
  const blBreakdownEl = document.getElementById('biayaLainnyaBreakdown');
  if (blBox && blBreakdownEl) {
    if (biayaLainnyaRows.length > 0) {
      blBox.style.display = 'block';
      blBreakdownEl.innerHTML = biayaLainnyaRows.map(r =>
        `<div>${esc(r.nama)}: Rp ${r.nominal.toLocaleString('id-ID')}</div>`
      ).join('');
    } else {
      blBox.style.display = 'none';
    }
  }
  const dp       = parseFloat(document.getElementById('f_dp').value)||0;
  // Bayar pakai saldo deposit (clamped by balance & total-dp)
  let depositPay = 0;
  const useDep = document.getElementById('f_use_deposit')?.checked;
  if (useDep) {
    depositPay = parseFloat(document.getElementById('f_deposit_amount')?.value)||0;
    depositPay = Math.min(depositPay, depositBalance, Math.max(0, total - dp));
  }
  const totalPaid = dp + depositPay;
  const sisa     = Math.max(0, total - totalPaid);

  document.getElementById('sumSubtotal').textContent = 'Rp ' + subtotal.toLocaleString('id-ID');
  document.getElementById('sumDiskon').textContent   = (diskon + redeemValue).toLocaleString('id-ID');
  document.getElementById('sumBiaya').textContent    = biayaTbh.toLocaleString('id-ID');
  document.getElementById('sumBiayaRow').style.display = biayaTbh > 0 ? 'flex' : 'none';
  document.getElementById('sumTotal').textContent    = 'Rp ' + total.toLocaleString('id-ID');
  document.getElementById('sumDP').innerHTML         = 'Rp ' + totalPaid.toLocaleString('id-ID') +
    (depositPay > 0 ? ` <span style="font-size:10px;color:#0F7B6C">(+Rp ${depositPay.toLocaleString('id-ID')} saldo)</span>` : '');
  document.getElementById('sumSisa').textContent     = 'Rp ' + sisa.toLocaleString('id-ID');

  // Kembalian — cuma relevan buat Cash (uang fisik), muncul kalau bayar > total
  const metodeVal = document.getElementById('f_metode')?.value;
  const kembalian = metodeVal === 'cash' ? Math.max(0, totalPaid - total) : 0;
  const kembalianRow = document.getElementById('sumKembalianRow');
  if (kembalianRow) {
    kembalianRow.style.display = kembalian > 0 ? 'flex' : 'none';
    document.getElementById('sumKembalian').textContent = 'Rp ' + kembalian.toLocaleString('id-ID');
  }

  const cells = document.querySelectorAll('.item-subtotal');
  items.forEach((item, i) => { if(cells[i]) cells[i].textContent='Rp '+(item.jumlah*item.harga_satuan).toLocaleString('id-ID'); });

  const info = document.getElementById('statusBayarInfo');
  if (!items.length) {
    info.textContent='Belum ada item';info.style.background='var(--light)';info.style.color='var(--gray)';
  } else if (dp >= total && total > 0) {
    info.textContent='✅ LUNAS';info.style.background='#D1FAE5';info.style.color='#065F46';
  } else if (dp > 0) {
    info.textContent='⚡ DP — Sisa Rp '+sisa.toLocaleString('id-ID');info.style.background='#FEF3C7';info.style.color='#92400E';
  } else {
    info.textContent='⏳ Belum Bayar';info.style.background='#FEE2E2';info.style.color='#991B1B';
  }

  // Metode bayar tetap bisa dipilih walau DP/Bayar = 0 — order belum lunas boleh
  // punya metode "yang akan dipakai nanti" (mis. QRIS/transfer), dipakai StrukGenerator
  // buat nampilin kode QRIS/rekening di struk (lihat paymentAidFor). Dulu di-disable+
  // dikosongkan paksa saat dp=0, jadi metode qris/transfer gak pernah kesimpen buat
  // order belum bayar — itu yang bikin QRIS gak pernah muncul di print.
  const metodeEl = document.getElementById('f_metode');
  if (metodeEl) {
    metodeEl.disabled = false;
    if (dp > 0 && !metodeEl.value) {
      const opt = Array.from(metodeEl.options).find(o => o.value && !o.disabled);
      if (opt) metodeEl.value = opt.value;   // default metode aktif pertama (Tunai)
    }
  }
}

function searchPelanggan(q) {
  clearTimeout(acTimeout);
  const list = document.getElementById('acList');
  if (q.length < 2) { list.classList.remove('open'); return; }
  acTimeout = setTimeout(async () => {
    const res  = await fetch('pos.php?action=search_pelanggan&q=' + encodeURIComponent(q));
    const data = await res.json();
    if (!data.length) { list.classList.remove('open'); return; }
    list.innerHTML = data.map(p => `
      <div class="ac-item" onclick="selectPelanggan(${p.id},'${esc(p.nama)}','${esc(p.telepon||'')}',${parseInt(p.poin_balance||0)})">
        <div>${esc(p.nama)}${LOYALTY.enabled && (p.poin_balance>0)?` <span style="font-size:11px;color:#0891B2">⭐${grpRibu(p.poin_balance)}</span>`:''}</div>
        <div class="ac-sub">${p.telepon||'No telepon'} · ${p.tipe} · ${p.total_order} order</div>
      </div>`).join('');
    list.classList.add('open');
  }, 300);
}

let currentPelangganId = null;
let aiChatOpen = false;

const TIER_BADGE  = {regular:'',silver:'🥈 Silver',gold:'🥇 Gold',platinum:'💎 Platinum'};
const TIER_COLOR  = {regular:'#94A3B8',silver:'#94A3B8',gold:'#D97706',platinum:'#7C3AED'};
const SEGMEN_BADGE= {baru:'🆕 Baru',regular:'',vip:'⭐ VIP',dormant:'😴 Dormant'};
const SEGMEN_COLOR= {baru:'#0891B2',regular:'#94A3B8',vip:'#D97706',dormant:'#9CA3AF'};

function selectPelanggan(id, nama, telp, poin) {
  currentPelangganId = id;
  currentPelangganPoin = parseInt(poin||0);
  document.getElementById('f_nama').value    = nama;
  document.getElementById('f_telepon').value = telp;
  document.getElementById('acList').classList.remove('open');
  document.getElementById('aiFloating').style.display = 'block';
  document.getElementById('aiStatusText').textContent = nama;
  document.getElementById('aiNotifDot').style.display = 'block';
  // Fetch info detail (poin, tier, segmen, rewards, preferensi)
  loadPelangganInfo(id);
  // Fetch info member tier (Tier 1b — Smartlink-inspired)
  loadMemberInfo(id);
  // Fetch saldo deposit (Tier 4.1)
  loadDepositInfo(id);
}

// ── Deposit Wallet (Tier 4.1) ──
let depositBalance = 0;
async function loadDepositInfo(pid) {
  try {
    const r = await fetch('pos.php?action=check_deposit&pelanggan_id=' + pid);
    const d = await r.json();
    depositBalance = parseFloat(d.balance) || 0;
    const box = document.getElementById('depositBox');
    if (!box) return;
    if (depositBalance > 0) {
      box.style.display = 'block';
      document.getElementById('depositBalance').textContent = 'Rp ' + Math.round(depositBalance).toLocaleString('id-ID');
    } else {
      box.style.display = 'none';
    }
  } catch(e) { depositBalance = 0; }
}

function onUseDepositChange() {
  const checked = document.getElementById('f_use_deposit').checked;
  document.getElementById('depositAmountWrap').style.display = checked ? 'block' : 'none';
  if (checked) {
    // Auto-fill with min(balance, total)
    const totalText = document.getElementById('sumTotal').textContent;
    const totalNum = parseFloat(totalText.replace(/[^\d]/g,''))||0;
    const auto = Math.min(depositBalance, totalNum);
    document.getElementById('f_deposit_amount').value = auto;
  } else {
    document.getElementById('f_deposit_amount').value = 0;
  }
  recalc();
}

// ── Member Tier auto-detect ──
async function loadMemberInfo(pid) {
  try {
    const r = await fetch('pos.php?action=check_member&pelanggan_id=' + pid);
    const d = await r.json();
    const box = document.getElementById('memberBadgeBox');
    if (!box) return;
    if (d.member && parseFloat(d.member.diskon_persen) > 0) {
      box.style.display = 'block';
      box.innerHTML = `⭐ <strong>Member ${esc(d.member.nama_tier)}</strong> — auto-diskon <strong>${parseFloat(d.member.diskon_persen)}%</strong> akan diterapkan otomatis saat simpan.${d.member.tgl_kadaluarsa?` <span style="color:#92400E;font-size:11px">(berlaku s/d ${d.member.tgl_kadaluarsa})</span>`:''}`;
    } else if (d.member) {
      box.style.display = 'block';
      box.style.background = '#F0FDF4';
      box.style.borderColor = '#BBF7D0';
      box.style.color = '#166534';
      box.innerHTML = `⭐ Member <strong>${esc(d.member.nama_tier)}</strong>`;
    } else {
      box.style.display = 'none';
    }
  } catch(e) { /* silent */ }
}

async function loadPelangganInfo(id){
  try {
    const r = await fetch('pos.php?action=pelanggan_poin&id=' + id);
    const d = await r.json();
    if (d.error) { updateLoyaltyBox(); return; }
    currentPelangganPoin = parseInt(d.pelanggan.poin || 0);
    // Auto-load catatan_tetap (preferensi) ke field catatan
    const cat = document.getElementById('f_catatan');
    const cur = (cat?.value || '').trim();
    if (cat && d.pelanggan.catatan_tetap && cur === '') {
      cat.value = d.pelanggan.catatan_tetap;
      showToast('💡 Catatan tetap pelanggan otomatis dimuat','success');
    }
    renderTierSegmenBadges(d.pelanggan);
    renderRewards(d.rewards || [], d.pelanggan.poin);
    updateLoyaltyBox();
  } catch(e) { updateLoyaltyBox(); }
}

function renderTierSegmenBadges(p){
  const t = document.getElementById('loyaltyTierBadge');
  const s = document.getElementById('loyaltySegmenBadge');
  if (t) {
    if (TIER_BADGE[p.tier]) { t.textContent = TIER_BADGE[p.tier]; t.style.color = TIER_COLOR[p.tier]; t.style.display=''; }
    else { t.style.display='none'; }
  }
  if (s) {
    if (SEGMEN_BADGE[p.segmen]) { s.textContent = SEGMEN_BADGE[p.segmen]; s.style.background = SEGMEN_COLOR[p.segmen]+'20'; s.style.color = SEGMEN_COLOR[p.segmen]; s.style.display=''; }
    else { s.style.display='none'; }
  }
}

function renderRewards(rewards, poin){
  const list = document.getElementById('rewardsList');
  if (!list) return;
  if (!rewards.length) { list.style.display='none'; return; }
  list.style.display='block';
  list.innerHTML = rewards.map(r => {
    const ok = !!r.bisa_redeem;
    const tipeLabel = {
      diskon_nominal: 'Diskon Rp ' + parseInt(r.nilai).toLocaleString('id-ID'),
      diskon_persen:  'Diskon ' + r.nilai + '%',
      gratis_layanan: 'Gratis Layanan'
    }[r.tipe] || '';
    return `<div style="display:flex;align-items:center;gap:8px;padding:7px 9px;margin-bottom:5px;border-radius:7px;border:1px solid ${ok?'rgba(8,145,178,.25)':'rgba(148,163,184,.2)'};background:${ok?'#fff':'#F8FAFC'};opacity:${ok?1:.65}">
      <div style="flex:1;min-width:0">
        <div style="font-size:12px;font-weight:700;color:#0F1C3A;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(r.nama_reward)}</div>
        <div style="font-size:10px;color:#64748B">${tipeLabel} · <strong>${r.poin_dibutuhkan} poin</strong>${!ok?' · butuh '+r.kurang+' lagi':''}</div>
      </div>
      ${ok
        ? `<button type="button" class="btn btn-teal-sm" style="padding:5px 11px;font-size:11px;white-space:nowrap" onclick="useReward(${r.id},${r.poin_dibutuhkan},${parseInt(r.nilai)},'${r.tipe}','${esc(r.nama_reward)}')">✓ Pakai</button>`
        : `<span style="font-size:10px;color:#94A3B8">🔒</span>`}
    </div>`;
  }).join('');
}

function useReward(rewardId, poin, nilai, tipe, nama){
  document.getElementById('f_reward_id').value = rewardId;
  document.getElementById('f_redeem_poin').value = poin;
  showToast('🎁 Reward dipakai: ' + nama, 'success');
  recalc();
}

function updateLoyaltyBox(){
  const box = document.getElementById('loyaltyBox');
  if (!box) return;
  if (LOYALTY.enabled && currentPelangganId && currentPelangganPoin > 0) {
    document.getElementById('loyaltyBal').textContent = currentPelangganPoin.toLocaleString('id-ID');
    box.style.display = 'block';
  } else {
    box.style.display = 'none';
    const rp = document.getElementById('f_redeem_poin'); if (rp) rp.value = 0;
    const rl = document.getElementById('rewardsList'); if (rl) rl.style.display = 'none';
    const tb = document.getElementById('loyaltyTierBadge'); if (tb) tb.style.display = 'none';
    const sb = document.getElementById('loyaltySegmenBadge'); if (sb) sb.style.display = 'none';
  }
}

function redeemMax(){
  document.getElementById('f_reward_id').value = '0';
  const subtotal = items.reduce((s,i)=>s+i.jumlah*i.harga_satuan,0);
  const diskon   = parseFloat(document.getElementById('f_diskon').value)||0;
  const maxByRp  = Math.floor(Math.max(0, subtotal-diskon) / LOYALTY.poin_value);
  const maxPoin  = Math.min(currentPelangganPoin, maxByRp);
  document.getElementById('f_redeem_poin').value = maxPoin;
  recalc();
}

function toggleAIChat() {
  aiChatOpen = !aiChatOpen;
  const panel = document.getElementById('aiChatPanel');
  const btn   = document.getElementById('aiBubbleBtn');
  panel.style.display = aiChatOpen ? 'flex' : 'none';
  btn.style.transform = aiChatOpen ? 'scale(0.9)' : 'scale(1)';
  btn.textContent     = aiChatOpen ? '✕' : '✨';
  if (aiChatOpen) { document.getElementById('aiNotifDot').style.display='none'; loadAIRekomendasi(); }
}

async function loadAIRekomendasi() {
  if (!currentPelangganId) return;
  const btn = document.getElementById('btnRefreshAI');
  btn.disabled=true; btn.textContent='⏳';
  document.getElementById('aiStatusText').textContent='Sedang menganalisis...';
  document.getElementById('aiContent').innerHTML=`<div style="text-align:center;padding:24px;color:var(--gray)"><div style="font-size:1.5rem;margin-bottom:8px;animation:spin 1s linear infinite;display:inline-block">⚙️</div><div style="font-size:13px">Menganalisis histori customer...</div></div>`;
  try {
    const r = await fetch('ai.php?action=upselling', {
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify({pelanggan_id:currentPelangganId,current_items:items})
    });
    // Defensive: kalau endpoint return HTML (404/500), jangan crash JSON.parse
    const txt = await r.text();
    let d;
    try { d = JSON.parse(txt); }
    catch (parseErr) {
      const isMissing = r.status === 404 || /not found|<!doctype|<html/i.test(txt.substring(0,200));
      document.getElementById('aiContent').innerHTML =
        `<div style="background:#FEF3C7;border-left:4px solid #F59E0B;padding:12px 14px;border-radius:8px;font-size:13px;color:#92400E">
          <div style="font-weight:700;margin-bottom:6px">⚠️ Fitur AI Rekomendasi belum tersedia</div>
          <div>Endpoint <code>ai.php?action=upselling</code> ${isMissing?'belum dibuat di server':'mengembalikan respons tidak valid'}. Hubungi admin untuk aktivasi modul AI Upselling.</div>
          <div style="margin-top:8px;font-size:11px;color:var(--gray)">Status HTTP: ${r.status}</div>
        </div>`;
      document.getElementById('aiStatusText').textContent = 'AI belum aktif';
      return;
    }
    if (d.error) {
      document.getElementById('aiContent').innerHTML=`<div style="color:var(--red);font-size:13px;padding:12px">❌ ${d.error}</div>`;
      return;
    }
    const data=d.data;
    const segmen={'new':'Baru','regular':'Regular','vip':'VIP'}[data.segmen]||data.segmen;
    const segmenColor={'new':'var(--blue)','regular':'var(--teal-d)','vip':'#F59E0B'}[data.segmen]||'var(--gray)';
    document.getElementById('aiStatusText').textContent=segmen+' · '+(data.rekomendasi?.length||0)+' rekomendasi';
    document.getElementById('aiContent').innerHTML=`
      <div style="margin-bottom:12px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <span style="background:${segmenColor};color:white;font-size:10px;font-weight:700;padding:2px 8px;border-radius:100px">${segmen}</span>
        <span style="font-size:12px;color:var(--gray);font-style:italic">"${esc(data.insight)}"</span>
      </div>
      ${(data.rekomendasi||[]).map((r,i)=>`
      <div style="background:${i===0?'#F5F3FF':'white'};border-radius:10px;padding:12px;margin-bottom:8px;border:1.5px solid ${i===0?'rgba(139,92,246,.25)':'rgba(27,45,90,.08)'}">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:4px">
          <div style="font-size:13px;font-weight:700;color:var(--navy)">${i===0?'⭐ ':''}${esc(r.layanan)}</div>
          <span style="font-size:10px;font-weight:700;background:var(--teal-bg);color:var(--teal-d);padding:2px 6px;border-radius:100px;white-space:nowrap;flex-shrink:0;margin-left:6px">+${esc(r.potensi_revenue)}</span>
        </div>
        <div style="font-size:11px;color:var(--gray);margin-bottom:7px">${esc(r.alasan)}</div>
        <div style="background:var(--off);border-radius:7px;padding:7px 10px;font-size:11px;color:var(--navy);border-left:3px solid var(--teal);font-style:italic;line-height:1.5">"${esc(r.script)}"</div>
      </div>`).join('')}
      <div style="font-size:10px;color:var(--gray);text-align:right;margin-top:4px">AI · ${new Date().toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit'})}</div>`;
  } catch(e) {
    document.getElementById('aiContent').innerHTML=`<div style="color:var(--red);font-size:13px;padding:12px">❌ Error: ${e.message}</div>`;
    document.getElementById('aiStatusText').textContent='Error';
  } finally {
    btn.disabled=false; btn.textContent='↻';
  }
}

document.addEventListener('click', e => {
  if (!e.target.closest('.autocomplete-wrap'))
    document.getElementById('acList').classList.remove('open');
});

// ─── QRIS Modal (Static QRIS POS) ──────────────────────
let _qrisConfirmed = false;

function onMetodeChange() {
  const metode = document.getElementById('f_metode').value;
  if (metode === 'qris') {
    if (!window.outletQris || !window.outletQris.image) {
      alert('QRIS belum di-setup oleh owner. Pilih metode lain.');
      document.getElementById('f_metode').value = '';
      return;
    }
    openQrisModal();
  }
  recalc(); // baris Kembalian cuma tampil utk metode cash — perlu re-evaluasi tiap ganti metode
}

function openQrisModal() {
  const total = parseFloat(document.getElementById('f_dp').value) || 0;
  document.getElementById('qrisAmount').textContent = total.toLocaleString('id-ID');
  document.getElementById('qrisImageEl').src = window.outletQris.image;
  document.getElementById('qrisLabelEl').textContent = window.outletQris.label || '';
  _qrisConfirmed = false;
  document.getElementById('modalQris').style.display = 'flex';
}

function confirmQrisPayment() {
  _qrisConfirmed = true;
  document.getElementById('modalQris').style.display = 'none';
}

function cancelQrisPayment() {
  _qrisConfirmed = false;
  document.getElementById('modalQris').style.display = 'none';
  document.getElementById('f_metode').value = ''; // reset to default
}

function closeQrisModal() {
  cancelQrisPayment();
}

function saveTransaksi() {
  const nama = document.getElementById('f_nama').value.trim();
  const telp = document.getElementById('f_telepon').value.trim();
  if (!nama) { showToast('⚠️ Nama pelanggan wajib diisi', 'error'); return; }
  if (!telp && TELEPON_WAJIB) { showToast('⚠️ Nomor HP wajib diisi', 'error'); return; }
  if (!items.length) { showToast('⚠️ Minimal 1 item layanan', 'error'); return; }

  // Tampilkan konfirmasi modal dulu
  const total = document.getElementById('sumTotal')?.textContent || 'Rp 0';
  const dp    = document.getElementById('sumDP')?.textContent || 'Rp 0';
  const sisa  = document.getElementById('sumSisa')?.textContent || 'Rp 0';
  const metode = document.getElementById('f_metode')?.options[document.getElementById('f_metode').selectedIndex]?.text || '-';
  const metodeVal = document.getElementById('f_metode').value;
  if (metodeVal === 'qris' && !_qrisConfirmed) {
    alert('Konfirmasi pembayaran QRIS dulu sebelum simpan.');
    openQrisModal();
    return;
  }
  const fotoOK = !!document.getElementById('f_foto_path').value;

  document.getElementById('cfmBody').innerHTML =
    '<div><strong>' + escapeHtml(nama) + '</strong> · ' + escapeHtml(telp) + '</div>' +
    '<div>Item: <strong>' + items.length + '</strong> baris</div>' +
    '<div>Total: <strong>' + total + '</strong></div>' +
    '<div>DP/Bayar: <strong>' + dp + '</strong> (' + metode + ')</div>' +
    '<div>Sisa: <strong>' + sisa + '</strong></div>' +
    '<div style="margin-top:4px;color:' + (fotoOK?'var(--green)':'var(--gray)') + '">' +
      (fotoOK ? '📸 Foto kondisi terlampir' : '📸 Tanpa foto kondisi') + '</div>';
  const modal = document.getElementById('confirmSaveModal');
  modal.style.display = 'flex';
  setTimeout(()=>document.getElementById('cfmYes')?.focus(), 50);
}

function escapeHtml(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

async function doSaveTransaksi() {
  closeCfm();
  const nama = document.getElementById('f_nama').value.trim();
  const telp = document.getElementById('f_telepon').value.trim();
  if (!nama || (!telp && TELEPON_WAJIB) || !items.length) return;

  const btn = document.getElementById('btnSave');
  btn.disabled=true; btn.textContent='⏳ Menyimpan...';

  // Helper: enqueue ke IndexedDB + tampil struk offline + toast + reset
  async function submitOffline() {
    const offlinePayload = {
      tanggal:        document.getElementById('f_tanggal').value,
      nama_pelanggan: nama,
      telepon:        telp,
      pelanggan_id:   currentPelangganId || null,
      catatan:        document.getElementById('f_catatan').value,
      metode_bayar:   'cash',
      dp:             parseFloat(document.getElementById('f_dp').value) || 0,
      redeem_poin:    0,
      use_deposit:    0,
      deposit_amount: 0,
      items: items.map(it => ({
        layanan_id:       it.layanan_id,
        nama_layanan:     it.nama_layanan,
        satuan:           it.satuan,
        jumlah:           parseFloat(it.jumlah) || 0,
        harga_satuan:     parseFloat(it.harga_satuan) || 0,
        subtotal:         (parseFloat(it.jumlah)||0) * (parseFloat(it.harga_satuan)||0),
        express_tier_nama: it.express_tier_nama || null,
        biaya_express:    parseFloat(it.biaya_express) || 0
      })),
      total: parseFloat(document.getElementById('sumTotal')?.textContent?.replace(/[^0-9]/g,'')) ||
             items.reduce((s,it)=>(s + (parseFloat(it.jumlah)||0)*(parseFloat(it.harga_satuan)||0) + (parseFloat(it.biaya_express)||0)),0)
    };

    const { tempCode } = await OfflinePOS.enqueueOrder(offlinePayload);

    // Tampilkan struk offline minimal di modalStruk
    const isFull = offlinePayload.dp >= offlinePayload.total;
    const itemRowsOffline = offlinePayload.items.map(it => `
      <div class="struk-item">${escapeHtml(it.nama_layanan)}
        <br>&nbsp;&nbsp;${it.jumlah} ${escapeHtml(it.satuan)} x Rp ${parseFloat(it.harga_satuan).toLocaleString('id-ID')}
      </div>
      <div class="struk-row"><span></span><span>Rp ${parseFloat(it.subtotal).toLocaleString('id-ID')}</span></div>`).join('');

    document.getElementById('strukPrint').innerHTML = `
      <div class="struk">
        <div class="struk-header">
          <h2>STRUK OFFLINE</h2>
        </div>
        <div style="background:#FEF3C7;border:1px solid #F59E0B;border-radius:6px;padding:6px 10px;font-size:11px;color:#92400E;text-align:center;margin-bottom:8px">
          ⚠️ ORDER OFFLINE — akan tersinkron saat online
        </div>
        <div class="struk-row"><span>No. Order</span><span>${escapeHtml(tempCode)}</span></div>
        <div class="struk-row"><span>Tanggal</span><span>${escapeHtml(offlinePayload.tanggal)}</span></div>
        <div class="struk-row"><span>Pelanggan</span><span>${escapeHtml(nama)}</span></div>
        ${telp?`<div class="struk-row"><span>Telp</span><span>${escapeHtml(telp)}</span></div>`:''}
        <hr class="struk-divider"/>
        ${itemRowsOffline}
        <hr class="struk-divider"/>
        <div class="struk-total">
          <div class="struk-row bold"><span>TOTAL</span><span>Rp ${offlinePayload.total.toLocaleString('id-ID')}</span></div>
          <div class="struk-row"><span>Bayar (Cash)</span><span>Rp ${offlinePayload.dp.toLocaleString('id-ID')}</span></div>
          ${!isFull?`<div class="struk-row bold"><span>SISA BAYAR</span><span>Rp ${(offlinePayload.total - offlinePayload.dp).toLocaleString('id-ID')}</span></div>`:''}
        </div>
        ${offlinePayload.catatan?`<hr class="struk-divider"/><div style="font-size:11px">Catatan: ${escapeHtml(offlinePayload.catatan)}</div>`:''}
        <div class="struk-footer">
          <p>${isFull?'** LUNAS **':'** BELUM LUNAS **'}</p>
          <p style="color:#92400E;font-size:10px">⚠️ Nomor order sementara — akan diperbarui setelah sinkronisasi</p>
        </div>
      </div>`;

    // Tampilkan modal struk (frame disembunyikan — pakai strukPrint)
    const frame = document.getElementById('strukFrame');
    const loading = document.getElementById('strukLoading');
    if (frame) frame.style.display = 'none';
    if (loading) loading.style.display = 'none';
    document.getElementById('modalStruk').classList.add('open');
    { var _aifab2 = document.getElementById('aiBubbleBtn'); if (_aifab2) _aifab2.style.display = 'none'; }

    showToast('✅ Order offline tersimpan: ' + tempCode + ' — ter-sync saat online', 'success');
    resetForm();
  }

  // ── JALUR OFFLINE ─────────────────────────────────────────────────────────
  if (!OfflinePOS.isOnline()) {
    try {
      await submitOffline();
    } catch(e) {
      showToast('❌ Gagal simpan offline: ' + e.message, 'error');
    }
    btn.disabled=false; btn.textContent='💾 Simpan & Print Struk';
    return;
  }
  // ── AKHIR JALUR OFFLINE ───────────────────────────────────────────────────

  const payload = {
    tanggal:        document.getElementById('f_tanggal').value,
    estimasi:       document.getElementById('f_estimasi').value,
    nama_pelanggan: nama,
    telepon:        document.getElementById('f_telepon').value,
    catatan:        document.getElementById('f_catatan').value,
    diskon:         document.getElementById('f_diskon').value,
    biaya_tambahan: document.getElementById('f_biaya_tambahan').value,
    tipe_order:     document.getElementById('f_tipe_order').value,
    parfum:         document.getElementById('f_parfum')?.value || '',
    redeem_poin:    (LOYALTY.enabled && currentPelangganId) ? (parseInt(document.getElementById('f_redeem_poin')?.value||0)||0) : 0,
    reward_id:      parseInt(document.getElementById('f_reward_id')?.value||0)||0,
    dp:             document.getElementById('f_dp').value,
    use_deposit:    document.getElementById('f_use_deposit')?.checked ? 1 : 0,
    deposit_amount: parseFloat(document.getElementById('f_deposit_amount')?.value)||0,
    metode_bayar:   document.getElementById('f_metode').value,
    foto_masuk:     document.getElementById('f_foto_path').value || '',
    antar_active:   document.getElementById('cb_antar')?.checked ? 1 : 0,
    antar_alamat:   document.getElementById('antar_alamat')?.value || '',
    antar_catatan:  document.getElementById('antar_catatan')?.value || '',
    antar_zona:     parseInt(document.getElementById('antar_zona')?.value) || 0,
    referral_code:  document.getElementById('f_referral_code')?.value?.trim() || '',
    items
  };

  try {
    const res  = await fetch('pos.php?action=save', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (data.success) {
      if (appliedVoucher) {
        await fetch('promo.php?action=apply_voucher', {
          method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
          body: JSON.stringify({voucher_id:appliedVoucher.voucher_id||null,promo_id:appliedVoucher.promo_id||null,no_order:data.no_order})
        });
      }
      showToast('✅ Order ' + data.no_order + ' tersimpan!', 'success');
      lastSaved = data;
      await showStruk(data.id);
      resetForm();
    } else {
      showToast('❌ ' + (data.error||'Gagal menyimpan'), 'error');
    }
  } catch(e) {
    // TypeError = network-level failure (fetch itself threw — not a server HTTP error)
    // Fall back to offline queue so the order is not lost
    if (e instanceof TypeError) {
      try {
        await submitOffline();
        showToast('⚠️ Jaringan gagal — order disimpan offline & akan tersinkron', 'warning');
      } catch(e2) {
        showToast('❌ Gagal simpan offline: ' + e2.message, 'error');
      }
    } else {
      showToast('❌ Error: ' + e.message, 'error');
    }
  }
  btn.disabled=false; btn.textContent='💾 Simpan & Print Struk';
}

async function showStruk(id) {
  // ── Gunakan StrukGenerator via API ──────────────────
  const frame   = document.getElementById('strukFrame');
  const loading = document.getElementById('strukLoading');
  const openBtn = document.getElementById('openStrukBtn');

  const apiUrl = `/api/struk.php?action=generate&id=${id}&tipe=retail`;
  openBtn.href = apiUrl;

  loading.style.display = 'block';
  frame.style.display   = 'none';
  document.getElementById('modalStruk').classList.add('open');
  { var _aifab = document.getElementById('aiBubbleBtn'); if (_aifab) _aifab.style.display = 'none'; }

  frame.onload = () => {
    loading.style.display = 'none';
    frame.style.display   = 'block';
    // Sesuaikan tinggi iframe ke konten
    try {
      const h = frame.contentDocument?.body?.scrollHeight;
      if (h && h > 200) frame.style.minHeight = Math.min(h + 20, 600) + 'px';
    } catch(e) {}
    // Auto-cetak BT kalau diaktifkan (app + printer terpilih)
    if (window.ThermalPrint && ThermalPrint.isAvailable() && ThermalPrint.getPrinter() && ThermalPrint.autoEnabled()) {
      setTimeout(() => posPrintStrukBT(), 350);
    }
  };
  frame.src = apiUrl;
  return; // ── legacy code tidak dijalankan di bawah ini ──

  // LEGACY fallback (tidak aktif — di-skip oleh return di atas)
  const res  = await fetch('pos.php?action=get_detail&id=' + id);
  const data = await res.json();
  if (data.error) return;

  const isFull    = parseFloat(data.dp) >= parseFloat(data.total);
  const metodeTxt = {'cash':'Cash','transfer':'Transfer Bank','qris':'QRIS'}[data.metode_bayar]||data.metode_bayar;
  const trackUrl  = <?= json_encode((defined('APP_URL') ? APP_URL : 'https://lamasy.harpy.id') . '/track.php?order=') ?> + encodeURIComponent(data.no_order);

  const itemRows = (data.items||[]).map(item => `
    <div class="struk-item">
      ${item.nama_layanan}${item.catatan_item?' ('+item.catatan_item+')':''}
      <br>&nbsp;&nbsp;${parseFloat(item.jumlah).toLocaleString('id-ID')} ${item.satuan} x Rp ${parseFloat(item.harga_satuan).toLocaleString('id-ID')}
    </div>
    <div class="struk-row">
      <span></span>
      <span>Rp ${parseFloat(item.subtotal).toLocaleString('id-ID')}</span>
    </div>`).join('');

  document.getElementById('strukPrint').innerHTML = `
    <div class="struk">
      <div class="struk-header">
        <h2>HARPY LAUNDRY</h2>
        <p>Jl. Rawa Selatan IV No.1, Johar Baru</p>
        <p>Jakarta Pusat | +62 896-1525-9302</p>
        <p>harpy.id</p>
      </div>
      <div class="struk-row"><span>No. Order</span><span>${data.no_order}</span></div>
      <div class="struk-row"><span>Tanggal</span><span>${formatDate(data.tanggal)}</span></div>
      <div class="struk-row"><span>Pelanggan</span><span>${data.nama_pelanggan}</span></div>
      ${data.telepon?`<div class="struk-row"><span>Telp</span><span>${data.telepon}</span></div>`:''}
      ${data.estimasi_selesai?`<div class="struk-row"><span>Est. Selesai</span><span>${formatDate(data.estimasi_selesai)}</span></div>`:''}
      <hr class="struk-divider"/>
      ${itemRows}
      <hr class="struk-divider"/>
      <div class="struk-row"><span>Subtotal</span><span>Rp ${parseFloat(data.subtotal).toLocaleString('id-ID')}</span></div>
      ${parseFloat(data.diskon)>0?`<div class="struk-row"><span>Diskon${appliedVoucher?' ('+esc(appliedVoucher.kode)+')':''}</span><span>- Rp ${parseFloat(data.diskon).toLocaleString('id-ID')}</span></div>`:''}
      <div class="struk-total">
        <div class="struk-row bold"><span>TOTAL</span><span>Rp ${parseFloat(data.total).toLocaleString('id-ID')}</span></div>
        <div class="struk-row"><span>Bayar (${metodeTxt})</span><span>Rp ${parseFloat(data.dp).toLocaleString('id-ID')}</span></div>
        ${!isFull?`<div class="struk-row bold"><span>SISA BAYAR</span><span>Rp ${parseFloat(data.sisa_bayar).toLocaleString('id-ID')}</span></div>`:''}
      </div>
      ${data.catatan?`<hr class="struk-divider"/><div style="font-size:11px">Catatan: ${data.catatan}</div>`:''}
      <div class="struk-footer">
        <p>${isFull?'** LUNAS **':'** BELUM LUNAS **'}</p>
        <div style="margin:8px auto;width:80px;height:80px" id="qrcode"></div>
        <p style="font-size:9px">Scan untuk cek status</p>
        <p>Terima kasih telah mempercayakan</p>
        <p>cucian Anda kepada Harpy Laundry!</p>
      </div>
    </div>`;

  const qrEl = document.getElementById('qrcode');
  if (qrEl) {
    const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=' + encodeURIComponent(trackUrl);
    qrEl.innerHTML = `<img src="${qrUrl}" width="80" height="80" style="display:block"/>`;
  }
  document.getElementById('modalStruk').classList.add('open');
  { var _aifab = document.getElementById('aiBubbleBtn'); if (_aifab) _aifab.style.display = 'none'; }
}

// Node struk di dalam iframe (same-origin). Return .struk element atau body.
function posStrukNode() {
  const frame = document.getElementById('strukFrame');
  try {
    const doc = frame && frame.contentDocument;
    if (doc) return doc.querySelector('.struk') || doc.body;
  } catch (e) {}
  return null;
}
function posStrukWidthPx() {
  // Lebar dot printer utk struk: ikut Format Struk outlet (Pengaturan → Struk:
  // thermal_58→384, thermal_80/A4 dst→576); fallback ukuran label 58/80.
  // (POS_STRUK_FORMAT lama tak pernah di-set → selalu 576, cetakan mengecil di printer 58mm.)
  <?php
    try {
        $_fmtStmt = Database::get()->prepare("SELECT format FROM hl_struk_template WHERE tenant_id=? AND outlet_id=? AND tipe='retail' AND is_active=1 LIMIT 1");
        $_fmtStmt->execute([$_pageTid, $_pageOid]);
        $_strukFmt = (string)$_fmtStmt->fetchColumn();
    } catch (Throwable) { $_strukFmt = ''; }
    if ($_strukFmt === 'thermal_58') { $_strukW = 384; }
    elseif ($_strukFmt !== '')       { $_strukW = 576; }
    else { $_strukW = (($outletQrisData['label_size'] ?? '80') === '58') ? 384 : 576; }
  ?>
  return <?= $_strukW ?>;
}
async function posPrintStrukBT() {
  const node = posStrukNode();
  if (!node) { showToast('Struk belum siap', 'error'); return false; }
  try {
    showToast('🖨 Mencetak…', 'info');
    await ThermalPrint.print(node, posStrukWidthPx());
    showToast('✅ Struk tercetak', 'success');
    return true;
  } catch (e) {
    showToast('❌ ' + (e.message || 'Gagal cetak'), 'error');
    return false;
  }
}
function printStruk() {
  try {
    var hasTP  = !!window.ThermalPrint;
    var avail  = hasTP && ThermalPrint.isAvailable();
    var inApp  = !!(window.Capacitor && typeof window.Capacitor.isNativePlatform === 'function' && window.Capacitor.isNativePlatform());
    var pr     = hasTP ? ThermalPrint.getPrinter() : null;

    // Di app + plugin aktif → cetak BT (kalau printer terpilih), else buka modal scan
    if (avail) {
      if (pr && pr.address) { posPrintStrukBT(); return; }
      showToast('Belum ada printer dipilih — buka ⚙️ Printer untuk scan & pilih', 'error');
      if (typeof posOpenPrinterModal === 'function') posOpenPrinterModal();
      return;
    }
    // Di app tapi plugin tak terdeteksi → APK belum punya plugin printer
    if (inApp) {
      showToast('Plugin printer thermal tak terdeteksi (TP=' + hasTP + '). Pakai APK terbaru / restart app.', 'error');
      return;
    }
    // Browser desktop → dialog cetak biasa
    var frame = document.getElementById('strukFrame');
    if (frame && frame.contentWindow) { frame.contentWindow.focus(); frame.contentWindow.print(); }
    else window.print();
  } catch (e) {
    try { showToast('Print error: ' + (e && e.message ? e.message : e), 'error'); }
    catch (_) { alert('Print error: ' + e); }
  }
}
async function printLabel() {
  const id = lastSaved?.id;
  if (!id) { showToast('❌ Order belum tersimpan', 'error'); return; }
  const url = '/api/label.php?id=' + id + '&_=' + Date.now();
  if (window.ThermalPrint && ThermalPrint.isAvailable()) {
    const pr = ThermalPrint.getPrinter();
    if (!pr || !pr.address) { showToast('Belum ada printer dipilih — atur di Pengaturan → Outlet & Nota → 🖨 Printer Thermal', 'error'); return; }
    let f = document.getElementById('lblFrame');
    if (!f) { f = document.createElement('iframe'); f.id = 'lblFrame'; f.style.cssText = 'position:fixed;left:-9999px;top:0;width:420px;height:800px;border:0'; document.body.appendChild(f); }
    showToast('🖨 Mencetak label…', 'info');
    f.onload = () => {
      const doc = f.contentDocument;
      const node = doc.querySelector('.label') || doc.body;
      const w = (f.contentWindow && f.contentWindow.LABEL_WIDTH_PX) || 576;
      const go = async () => { try { await ThermalPrint.print(node, w); showToast('✅ Label tercetak', 'success'); } catch (e) { showToast('❌ ' + (e.message || 'Gagal cetak label'), 'error'); } };
      const img = doc.querySelector('.qr img');
      if (img && !img.complete) { img.addEventListener('load', go); img.addEventListener('error', go); setTimeout(go, 2500); }
      else go();
    };
    f.src = url + '&embed=1';
    return;
  }
  window.open(url, '_blank', 'width=380,height=520');
}
function closeModal()  { document.getElementById('modalStruk').classList.remove('open'); var _aifab = document.getElementById('aiBubbleBtn'); if (_aifab) _aifab.style.display = ''; }

// ── Kirim Nota via WA (150 koin) ──
async function kirimNotaWA() {
  if (!lastSaved || !lastSaved.id) { showToast('⚠️ Order belum tersimpan', 'error'); return; }
  if (!(await lmConfirm('Kirim nota via WhatsApp ke pelanggan?', {icon:'📲', cost:'150 koin', okText:'Kirim WA'}))) return;

  try {
    const r = await fetch('pos.php?action=wa_nota&id=' + lastSaved.id);
    const d = await r.json();
    if (d.error) { showToast('❌ ' + d.error, 'error'); return; }

    // Buka WhatsApp dengan pesan
    const url = 'https://wa.me/' + d.phone + '?text=' + encodeURIComponent(d.message);
    window.open(url, '_blank');
    showToast('📲 WhatsApp dibuka — 150 koin terpotong', 'success');
  } catch (e) {
    showToast('❌ Gagal kirim WA: ' + e.message, 'error');
  }
}

let appliedVoucher = null;

async function applyVoucher() {
  const kode = document.getElementById('f_voucher').value.trim().toUpperCase();
  if (!kode) { showToast('⚠️ Masukkan kode voucher/promo', 'error'); return; }
  const subtotal = items.reduce((s,i)=>s+i.jumlah*i.harga_satuan, 0);
  if (subtotal <= 0) { showToast('⚠️ Tambahkan item terlebih dahulu', 'error'); return; }

  try {
    const r = await fetch('promo.php?action=validate&kode=' + encodeURIComponent(kode) + '&total=' + subtotal);
    const d = await r.json();
    if (d.valid) {
      appliedVoucher = d;
      document.getElementById('f_diskon').value = Math.round(d.diskon);
      recalc();
      const infoEl = document.getElementById('voucherInfo');
      infoEl.style.display = 'block';
      infoEl.className = 'voucher-applied';
      document.getElementById('voucherInfoText').innerHTML =
        '✅ <strong>' + esc(d.kode) + '</strong> — ' + esc(d.nama) +
        ' <span style="background:#065F46;color:white;padding:2px 8px;border-radius:100px;font-size:11px;font-weight:700">' + esc(d.info) + '</span>' +
        '<br><span style="font-size:12px;opacity:.8">Diskon: Rp ' + Math.round(d.diskon).toLocaleString('id-ID') + '</span>';
      document.getElementById('f_voucher').disabled = true;
      showToast('✅ Voucher berhasil dipakai! Diskon Rp ' + Math.round(d.diskon).toLocaleString('id-ID'), 'success');
    } else {
      showToast('❌ ' + (d.error||'Kode tidak valid'), 'error');
    }
  } catch(e) { showToast('❌ Error: ' + e.message, 'error'); }
}

function removeVoucher() {
  appliedVoucher = null;
  document.getElementById('f_voucher').value    = '';
  document.getElementById('f_voucher').disabled = false;
  document.getElementById('f_diskon').value     = '0';
  document.getElementById('voucherInfo').style.display = 'none';
  recalc();
  showToast('🎟️ Kode voucher dihapus', 'success');
}

function resetForm() {
  items = []; appliedVoucher = null;
  renderItems(); recalc();
  ['f_nama','f_telepon','f_catatan'].forEach(id => document.getElementById(id).value='');
  document.getElementById('f_diskon').value='0';
  document.getElementById('f_dp').value='0';
  document.getElementById('f_metode').value='';
  document.getElementById('f_voucher').value='';
  document.getElementById('f_voucher').disabled=false;
  document.getElementById('voucherInfo').style.display='none';
  const today=localDateStr();
  document.getElementById('f_tanggal').value=today;
  const est=new Date(); est.setDate(est.getDate()+2);
  document.getElementById('f_estimasi').value=localDateStr(est);
  currentPelangganId=null; currentPelangganPoin=0;
  const rp=document.getElementById('f_redeem_poin'); if(rp) rp.value='0';
  const ri=document.getElementById('f_reward_id'); if(ri) ri.value='0';
  updateLoyaltyBox();
  if (typeof clearFoto === 'function') clearFoto();
  // Reset antar section
  const cbAntar = document.getElementById('cb_antar');
  if (cbAntar) { cbAntar.checked=false; document.getElementById('antarSection').style.display='none'; }
}

// ponytail: lazy load zona only on first check
async function toggleAntarSection() {
  const cb = document.getElementById('cb_antar');
  const sec = document.getElementById('antarSection');
  sec.style.display = cb.checked ? 'block' : 'none';
  if (cb.checked && !sec.dataset.loaded) {
    sec.dataset.loaded = '1';
    const r = await fetch('/outlet-settings.php?action=zona_list&outlet_id=<?= $oid ?>');
    const d = await r.json();
    if (d.rows && d.rows.length) {
      const sel = document.getElementById('antar_zona');
      sel.innerHTML = '<option value="">-- Pilih zona --</option>' + d.rows.map(z => `<option value="${z.id}">${z.nama} (Rp ${Number(z.fee).toLocaleString('id-ID')})</option>`).join('');
      document.getElementById('antarZonaWrap').style.display = 'block';
    }
  }
}

function formatDate(d) {
  if (!d) return '-';
  return new Date(d).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'});
}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
function showToast(msg,type='success'){const t=document.getElementById('toast');t.textContent=msg;t.className='toast '+type+' show';setTimeout(()=>t.className='toast',3500)}

// ── VOICE ORDER ──
document.addEventListener('DOMContentLoaded', function () {
  var SR = window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.SpeechRecognition;
  var b = document.getElementById('voiceOrderBtn');
  if (SR && b) b.style.display = '';
});

async function voiceOrderStart() {
  var SR = window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.SpeechRecognition;
  if (!SR) { showToast('Voice order hanya di app', 'error'); return; }
  // Lagi merekam? tap lagi = STOP manual (start() di bawah akan resolve dgn hasil sejauh ini)
  if (window.__voiceRecording) { try { await SR.stop(); } catch (e) {} return; }
  if (!navigator.onLine) { showToast('Butuh internet untuk voice order', 'error'); return; }
  try {
    var perm = await SR.requestPermissions();
    if (perm && perm.speechRecognition && perm.speechRecognition !== 'granted') {
      // beberapa versi balas {speechRecognition:'granted'}; kalau ditolak lanjut saja dan biarkan start() gagal
    }
  } catch (e) {}
  try {
    var avail = await SR.available();
    if (avail && avail.available === false) { showToast('STT tak tersedia di perangkat ini', 'error'); return; }
  } catch (e) {}
  var vbtn = document.getElementById('voiceOrderBtn');
  window.__voiceRecording = true;
  voiceRecState(vbtn, true);
  showToast('🔴 Mendengarkan… tap lagi untuk stop', 'info');
  try {
    var res = await SR.start({ language: 'id-ID', maxResults: 1, partialResults: false, popup: false });
    var text = '';
    if (res && res.matches && res.matches.length) text = res.matches[0];
    else if (Array.isArray(res) && res.length) text = res[0];
    if (!text || !text.trim()) { showToast('Tak terdengar, coba lagi', 'error'); return; }
    voiceOrderParse(text.trim());
  } catch (e) {
    showToast('Gagal merekam: ' + (e && e.message ? e.message : 'mic error'), 'error');
  } finally {
    window.__voiceRecording = false;
    voiceRecState(vbtn, false);
  }
}
// Ubah tampilan tombol voice saat merekam (berdenyut merah) → balik normal saat selesai
function voiceRecState(btn, on) {
  if (!btn) return;
  if (on) {
    btn.dataset.orig = btn.innerHTML;
    btn.innerHTML = '⏹ Stop (mendengarkan…)';
    btn.classList.add('voice-rec');
    // JANGAN disable — tap lagi untuk stop manual
  } else {
    btn.innerHTML = btn.dataset.orig || '🎤 Voice Order';
    btn.classList.remove('voice-rec');
  }
}

async function voiceOrderParse(transcript) {
  showToast('🧠 Memproses…', 'info');
  try {
    var r = await fetch('/api/voice_order_parse.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ transcript: transcript })
    });
    var d = await r.json();
    if (!d.ok) {
      var msg = ({
        no_speech: 'Tak terdengar, coba lagi',
        rate_limited: 'Limit AI harian tercapai',
        insufficient_coin: 'Coin tak cukup untuk fitur AI',
        ai_error: 'Gagal memproses suara, coba ucapkan lebih jelas',
        no_match: 'Layanan tak dikenali dari ucapan',
        no_catalog: 'Belum ada layanan di katalog'
      })[d.reason] || 'Gagal voice order';
      if (d.reason === 'no_match') voiceOrderShowModal({ heard: d.heard, parsed: { nama:null, items:[], bayar:{} }, unmatched: d.unmatched || [] }, true);
      else showToast(msg, 'error');
      return;
    }
    voiceOrderShowModal(d, false);
  } catch (e) {
    showToast('Gagal koneksi voice: ' + (e.message || e), 'error');
  }
}

var _voiceData = null;
function voiceOrderShowModal(d, noMatch) {
  _voiceData = d;
  document.getElementById('voiceHeard').textContent = '"' + (d.heard || '') + '"';
  var p = d.parsed || {};
  var html = '';
  if (p.nama) html += '<div>👤 Nama: <b>' + esc(p.nama) + '</b></div>';
  (p.items || []).forEach(function (it) {
    html += '<div>🧺 ' + esc(it.nama_katalog) + ' × <b>' + it.qty + '</b></div>';
  });
  if (p.bayar && (p.bayar.status || p.bayar.metode))
    html += '<div>💳 ' + esc((p.bayar.status||'') + (p.bayar.metode ? ' / ' + p.bayar.metode : '')) + '</div>';
  if (!html) html = '<div style="color:#9CA3AF">Tak ada field terdeteksi</div>';
  document.getElementById('voiceFields').innerHTML = html;
  var um = document.getElementById('voiceUnmatched');
  if (d.unmatched && d.unmatched.length) { um.style.display = ''; um.textContent = '⚠️ Tak ada di katalog: ' + d.unmatched.join(', ') + '. Tambah manual.'; }
  else um.style.display = 'none';
  document.getElementById('voiceApplyBtn').style.display = (p.items && p.items.length) ? '' : 'none';
  document.getElementById('voiceModal').style.display = 'flex';
}
function voiceOrderRetry() { document.getElementById('voiceModal').style.display = 'none'; voiceOrderStart(); }
function voiceOrderApply() {
  var p = _voiceData && _voiceData.parsed; if (!p) return;
  // Set nama pelanggan
  if (p.nama) { var n = document.getElementById('f_nama'); if (n) n.value = p.nama; }
  // Add layanan items — addLayananItem adds with qty_minimum/1 as default; for qty>1 we set jumlah directly after.
  (p.items || []).forEach(function (it) {
    var lyn = (layananAll || []).find(function (l) { return l.id == it.layanan_id; });
    if (!lyn) return;
    var beforeLen = items.length;
    addLayananItem(lyn.id, lyn.nama, lyn.satuan, lyn.harga);
    // If a new row was added (not an existing increment), set qty to what AI parsed
    if (items.length > beforeLen && it.qty > 1) {
      items[items.length - 1].jumlah = it.qty;
    }
  });
  // Set metode bayar — #f_metode is a <select> with option values matching payment method codes
  if (p.bayar && p.bayar.metode) {
    var m = document.getElementById('f_metode');
    if (m) m.value = p.bayar.metode;
  }
  // Note: status_bayar (lunas/dp/belum_bayar) is computed automatically by recalc() from f_dp vs total.
  // There is no status_bayar input field — skip setting it.
  if (typeof renderItems === 'function') renderItems(); // re-render qty inputs after jumlah mutation
  if (typeof recalc === 'function') recalc();
  document.getElementById('voiceModal').style.display = 'none';
  showToast('Order terisi dari suara — cek & Simpan', 'success');
}
</script>
<!-- VOICE ORDER MODAL -->
<div id="voiceModal" style="display:none;position:fixed;inset:0;background:rgba(15,28,58,.55);z-index:2001;align-items:center;justify-content:center;padding:20px">
  <div style="position:relative;background:#fff;border-radius:14px;padding:20px 22px;max-width:420px;width:100%;box-shadow:0 12px 40px rgba(15,28,58,.25)">
    <button aria-label="Tutup" onclick="document.getElementById('voiceModal').style.display='none'" style="position:absolute;top:10px;right:12px;background:none;border:none;font-size:20px;line-height:1;color:#94A3B8;cursor:pointer;padding:4px">✕</button>
    <h3 style="margin:0 0 8px;font-size:16px;font-weight:800;color:var(--navy);padding-right:24px">🎤 Yang Saya Dengar</h3>
    <div id="voiceHeard" style="font-size:12px;color:#6B7280;font-style:italic;margin-bottom:10px"></div>
    <div id="voiceFields" style="font-size:14px"></div>
    <div id="voiceUnmatched" style="display:none;background:#FEF3C7;color:#92400E;padding:8px;border-radius:8px;font-size:12px;margin-top:8px"></div>
    <div style="display:flex;gap:8px;margin-top:14px">
      <button class="btn btn-outline" style="flex:1" onclick="voiceOrderRetry()">🔄 Ulangi</button>
      <button id="voiceApplyBtn" class="btn btn-green" style="flex:1" onclick="voiceOrderApply()">✓ Terapkan</button>
    </div>
  </div>
</div>

<!-- KONFIRMASI MODAL -->
<div id="confirmSaveModal" style="display:none;position:fixed;inset:0;background:rgba(15,28,58,.55);z-index:2000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:14px;padding:20px 22px;max-width:380px;width:90%;box-shadow:0 12px 40px rgba(15,28,58,.25)">
    <div style="font-size:16px;font-weight:800;color:var(--navy);margin-bottom:12px">📋 Konfirmasi Order Baru</div>
    <div id="cfmBody" style="font-size:13px;color:#334155;line-height:1.7;background:#F8FAFC;border-radius:9px;padding:12px 14px;margin-bottom:14px"></div>
    <div style="font-size:11px;color:var(--gray);margin-bottom:14px">Pastikan data benar — order yang sudah tersimpan tidak bisa dibatalkan dari POS.</div>
    <div style="display:flex;gap:8px">
      <button class="btn btn-outline" style="flex:1" onclick="closeCfm()">✕ Batal</button>
      <button class="btn btn-primary" style="flex:1.4" id="cfmYes" onclick="doSaveTransaksi()">✓ Ya, Simpan (Enter)</button>
    </div>
  </div>
</div>

<!-- Mobile sticky CTA (auto-hidden di desktop) -->
<div class="pos-mobile-cta" id="posMobileCta">
  <div class="pos-mobile-cta-total">
    Total
    <strong id="mTotal">Rp 0</strong>
  </div>
  <button class="btn btn-primary" id="btnSaveMobile" onclick="saveTransaksi()" disabled>
    💾 Simpan
  </button>
</div>

<script>
// Sync sticky bar dengan tombol/total utama — set & forget pakai MutationObserver
(function syncMobileCta(){
  const btn = document.getElementById('btnSave');
  const total = document.getElementById('sumTotal');
  const mBtn = document.getElementById('btnSaveMobile');
  const mTot = document.getElementById('mTotal');
  if (!btn || !total || !mBtn || !mTot) return;
  const sync = () => {
    mBtn.disabled = btn.disabled;
    mTot.textContent = total.textContent;
  };
  new MutationObserver(sync).observe(btn, { attributes:true, attributeFilter:['disabled'] });
  new MutationObserver(sync).observe(total, { childList:true, characterData:true, subtree:true });
  sync();
})();
</script>

<?php renderToast(); ?>

<script src="/assets/offline-pos.js?v=1"></script>
<script>
OfflinePOS.init({
  tenantId: <?= (int)TenantResolver::id() ?>,
  outletId: <?= (int)TenantResolver::outletId() ?>,
  userId:   <?= (int)$user['id'] ?>
});

// ── Offline UI: disable kontrol online-only saat tidak ada koneksi ──────────
function applyOfflineUI() {
  const offline = !OfflinePOS.isOnline();

  // f_redeem_poin + Max button
  const elRedeem = document.getElementById('f_redeem_poin');
  if (elRedeem) elRedeem.disabled = offline;
  const maxBtn = elRedeem?.closest('div')?.querySelector('button');
  if (maxBtn) maxBtn.disabled = offline;

  // f_use_deposit + f_deposit_amount
  const elUseDeposit = document.getElementById('f_use_deposit');
  if (elUseDeposit) {
    elUseDeposit.disabled = offline;
    if (offline && elUseDeposit.checked) {
      elUseDeposit.checked = false;
      document.getElementById('depositAmountWrap').style.display = 'none';
    }
  }
  const elDepositAmt = document.getElementById('f_deposit_amount');
  if (elDepositAmt) elDepositAmt.disabled = offline;

  // f_voucher input + Pakai button
  const elVoucher = document.getElementById('f_voucher');
  if (elVoucher) elVoucher.disabled = offline;
  const voucherBtn = document.getElementById('btnApplyVoucher');
  if (voucherBtn) voucherBtn.disabled = offline;

  // f_metode: paksa cash + disable non-cash options
  const elMetode = document.getElementById('f_metode');
  if (elMetode) {
    if (offline) {
      elMetode.value = 'cash';
      for (const opt of elMetode.options) {
        opt.disabled = (opt.value !== 'cash');
      }
    } else {
      for (const opt of elMetode.options) {
        // Restore: hanya biarkan QRIS disabled jika memang tidak ada setup
        if (opt.value !== 'qris' || window.outletQris?.image) opt.disabled = false;
      }
    }
  }

  // Visual indicator di area form
  let indicator = document.getElementById('offlineIndicator');
  if (offline) {
    if (!indicator) {
      indicator = document.createElement('div');
      indicator.id = 'offlineIndicator';
      indicator.style.cssText = 'background:#FEF3C7;border:1px solid #F59E0B;border-radius:8px;padding:8px 14px;font-size:12px;color:#92400E;margin-bottom:8px;text-align:center;font-weight:600';
      indicator.textContent = '⚠️ Mode OFFLINE — hanya metode Cash, deposit/poin/voucher dinonaktifkan';
      const btnSave = document.getElementById('btnSave');
      btnSave?.parentNode?.insertBefore(indicator, btnSave);
    }
  } else {
    indicator?.remove();
  }
}

// Panggil saat load + saat status jaringan berubah
applyOfflineUI();
window.addEventListener('online',  applyOfflineUI);
window.addEventListener('offline', applyOfflineUI);
</script>
</body>
</html>

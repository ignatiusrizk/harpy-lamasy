<?php
$activePage = 'orders';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/Loyalty.php';
require_once ROOT . '/core/ErrorLogger.php';
require_once ROOT . '/core/Referral.php';
require_once ROOT . '/core/PushSender.php';
require_once ROOT . '/core/WaLogger.php';
require_once ROOT . '/core/OrderEditResolver.php';
require_once ROOT . '/core/DepositManager.php';
require_once __DIR__ . '/components.php';
$user = currentUser();

if (!hasPermission('orders.view_all') && !hasPermission('orders.view_own')) requirePermission('orders.view_all');

// Helper: metode bayar aktif outlet (code => "emoji label") — validasi input & label log
if (!function_exists('validPayMethods')) {
    function validPayMethods(int $tid, int $oid): array {
        static $cache = null;
        if ($cache !== null) return $cache;
        try {
            $st = Database::get()->prepare(
                "SELECT code, label, emoji FROM hl_payment_methods
                 WHERE outlet_id=? AND tenant_id=? AND is_active=1
                 ORDER BY sort_order, id");
            $st->execute([$oid, $tid]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) { $rows = []; }
        if (!$rows) $rows = [
            ['code'=>'cash',     'label'=>'Tunai',         'emoji'=>'💵'],
            ['code'=>'transfer', 'label'=>'Transfer Bank', 'emoji'=>'🏦'],
            ['code'=>'qris',     'label'=>'QRIS',          'emoji'=>'📱'],
        ];
        $cache = [];
        foreach ($rows as $r) $cache[$r['code']] = trim(($r['emoji'] ?? '') . ' ' . $r['label']);
        return $cache;
    }
}

// Helper: data filter scope berdasarkan permission
if (!function_exists('getDataFilter')) {
    function getDataFilter(string $kode) {
        // 'view_all' → tanpa filter (return null), 'view_own' → 'own',
        // fallback false kalau tidak punya keduanya
        if (hasPermission($kode)) return null;
        $owns = str_replace('view_all','view_own', $kode);
        if (hasPermission($owns)) return 'own';
        return false;
    }
}

// ── API ───────────────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action === '') { header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'); header('Pragma: no-cache'); }
if ($action) {
    // Tangkap PHP fatal supaya tetap return JSON (bukan empty 500)
    register_shutdown_function(function() {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
            if (!headers_sent()) header('Content-Type: application/json');
            echo json_encode(['error'=>'PHP fatal: '.$e['message'].' @ '.$e['file'].':'.$e['line']]);
        }
    });
    @ini_set('display_errors', '0');
    error_reporting(0);
    header('Content-Type: application/json');
    $tid = TenantResolver::id();
    $oid = TenantResolver::outletId();

    // LIST orders
    // Defensive: cek dukungan kolom drop_point_id (kalau migration belum jalan)
    $hasDropPoint = false;
    try {
        Database::get()->query("SELECT drop_point_id FROM hl_transaksi LIMIT 1");
        Database::get()->query("SELECT 1 FROM hl_drop_points LIMIT 1");
        $hasDropPoint = true;
    } catch (Throwable) { /* migration belum jalan */ }

    if ($action === 'list') {
        $filter = getDataFilter('orders.view_all');
        if ($filter === false) $filter = getDataFilter('orders.view_own');
        $q       = $_GET['q'] ?? '';
        $status  = $_GET['status'] ?? '';
        $bayar   = $_GET['bayar'] ?? '';
        $dari    = $_GET['dari'] ?? '';
        $sampai  = $_GET['sampai'] ?? '';
        $sumber  = $_GET['sumber'] ?? '';      // '' / 'walkin' / 'drop' / 'drop:<id>'
        $page    = max(1, intval($_GET['page'] ?? 1));
        $limit   = 25;
        $offset  = ($page - 1) * $limit;

        $where = ['t.tenant_id = ?', 't.outlet_id = ?']; $params = [$tid, $oid];
        if ($q) {
            $where[] = "(t.no_order LIKE ? OR t.offline_ref LIKE ? OR t.nama_pelanggan LIKE ? OR t.telepon LIKE ?)";
            $like = "%$q%"; $params = array_merge($params, [$like, $like, $like, $like]);
        }
        if ($status) { $where[] = "t.status_proses=?"; $params[] = $status; }
        if ($bayar)  { $where[] = "t.status_bayar=?";  $params[] = $bayar; }
        if ($dari)   { $where[] = "DATE(t.tanggal) >= ?"; $params[] = $dari; }
        if ($sampai) { $where[] = "DATE(t.tanggal) <= ?"; $params[] = $sampai; }
        if ($hasDropPoint) {
            if ($sumber === 'walkin')      { $where[] = "t.drop_point_id IS NULL"; }
            elseif ($sumber === 'drop')    { $where[] = "t.drop_point_id IS NOT NULL"; }
            elseif (strpos($sumber, 'drop:') === 0) {
                $dpId = (int)substr($sumber, 5);
                if ($dpId > 0) { $where[] = "t.drop_point_id = ?"; $params[] = $dpId; }
            }
        }

        // Filter berdasarkan permission
        if ($filter === 'own') { $where[] = "t.created_by=?"; $params[] = $user['id']; }
        elseif ($filter === 'today') { $where[] = "DATE(t.tanggal)=CURDATE()"; }

        $sort    = $_GET['sort'] ?? 'tanggal';
        $dir     = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $sortMap = ['no_order'=>'t.no_order','tanggal'=>'t.tanggal','nama_pelanggan'=>'t.nama_pelanggan',
                    'total'=>'t.total','estimasi_selesai'=>'t.estimasi_selesai'];
        $sortCol = $sortMap[$sort] ?? 't.tanggal';

        $whereStr = implode(' AND ', $where);

        $namaMitraCol = $hasDropPoint
            ? "(SELECT nama_mitra FROM hl_drop_points WHERE id=t.drop_point_id) as nama_mitra"
            : "NULL as nama_mitra";
        $sql = "SELECT t.*,
            (SELECT GROUP_CONCAT(nama_layanan SEPARATOR ', ') FROM hl_transaksi_item WHERE transaksi_id=t.id AND tenant_id=t.tenant_id AND outlet_id=t.outlet_id) as layanan_list,
            $namaMitraCol
            FROM hl_transaksi t
            WHERE $whereStr
            ORDER BY $sortCol $dir
            LIMIT $limit OFFSET $offset";

        try {
            $rows = TenantQuery::raw($sql, $params);
        } catch (Throwable $e) {
            apiErr($e, 'Query gagal. Silakan coba lagi.'); exit;
        }

        try {
            $cnt   = TenantQuery::raw("SELECT COUNT(*) as c FROM hl_transaksi t WHERE $whereStr", $params);
            $total = intval($cnt[0]['c'] ?? 0);
        } catch (Throwable $e) { $total = count($rows); }

        echo json_encode([
            'data'        => $rows,
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => ceil($total / $limit),
        ]);
        exit;
    }

    // GET detail 1 order
    if ($action === 'get') {
        $id = intval($_GET['id']);
        $t  = TenantQuery::rawOne("SELECT * FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND id=?", [$tid, $oid, $id]);
        if (!$t) { echo json_encode(['error'=>'Not found']); exit; }
        $t['items'] = TenantQuery::raw("SELECT * FROM hl_transaksi_item WHERE tenant_id=? AND outlet_id=? AND transaksi_id=? ORDER BY id", [$tid, $oid, $id]);
        $t['logs']  = TenantQuery::raw("SELECT * FROM hl_proses_log WHERE transaksi_id=? ORDER BY created_at DESC LIMIT 10", [$id]);
        $t['biaya_lainnya_breakdown'] = TenantQuery::raw("SELECT nama, nominal FROM hl_transaksi_biaya_lainnya WHERE tenant_id=? AND outlet_id=? AND transaksi_id=? ORDER BY id", [$tid, $oid, $id]);
        require_once ROOT . '/core/DeleteRequest.php';
        $t['pending_delete'] = DeleteRequest::isPending('transaksi', $id, $tid);
        echo json_encode($t); exit;
    }

    // REQUEST DELETE — kasir submit, owner approve (Smartlink-style approval workflow)
    if ($action === 'request_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hasPermission('orders.edit') && !hasPermission('orders.delete')) {
            echo json_encode(['error'=>'Akses ditolak']); exit;
        }
        verifyCsrf();
        require_once ROOT . '/core/DeleteRequest.php';
        $d  = json_decode(file_get_contents('php://input'), true);
        $id = (int)($d['transaksi_id'] ?? 0);
        $alasan = (string)($d['alasan'] ?? '');
        // Verifikasi transaksi punya tenant
        $own = TenantQuery::rawOne("SELECT id FROM hl_transaksi WHERE id=? AND tenant_id=? AND outlet_id=?", [$id, $tid, $oid]);
        if (!$own) { echo json_encode(['error'=>'Transaksi tidak ditemukan']); exit; }
        [$reqId, $err] = DeleteRequest::submit('transaksi', $id, $alasan, (int)$user['id']);
        // Catat di riwayat status order supaya terlihat di detail
        if (!$err) {
            try {
                Database::get()->prepare(
                    "INSERT INTO hl_proses_log (transaksi_id,status_lama,status_baru,tipe,catatan,oleh,created_at) VALUES (?,?,?,?,?,?,?)"
                )->execute([$id, null, 'delete_requested', 'delete_request',
                    '🗑️ Minta hapus diajukan' . ($alasan !== '' ? ': ' . $alasan : '') . ' (menunggu persetujuan owner)',
                    $user['nama'] ?? '-', date('Y-m-d H:i:s')]);
            } catch (Throwable $e) { /* log opsional — jangan gagalkan request */ }
        }
        echo json_encode($err ? ['error'=>$err] : ['success'=>true, 'request_id'=>$reqId]);
        exit;
    }

    // UPLOAD FOTO PICKUP — dokumentasi saat cucian diambil pelanggan
    if ($action === 'upload_foto_pickup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hasPermission('orders.edit') && !hasPermission('orders.update_status')) {
            echo json_encode(['error'=>'Akses ditolak']); exit;
        }
        verifyCsrf();
        require_once ROOT . '/core/FileUpload.php';
        $f = $_FILES['foto'] ?? null;
        if (!$f) { echo json_encode(['error'=>'File foto tidak ditemukan']); exit; }
        $res = FileUpload::uploadImage($f, 'uploads/foto_pickup', 't' . $tid . '_o' . $oid);
        if (!empty($res['error'])) { echo json_encode(['error'=>$res['error']]); exit; }
        echo json_encode(['ok'=>true, 'path'=>$res['path']]);
        exit;
    }

    // UPDATE order
    if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hasPermission('orders.edit') && !hasPermission('orders.update_status')) {
            echo json_encode(['error'=>'Akses ditolak']); exit;
        }
        verifyCsrf();
        $data = json_decode(file_get_contents('php://input'), true);
        $id   = intval($data['id']);

        // Kunci: order dgn permintaan hapus pending tak boleh diedit
        require_once ROOT . '/core/DeleteRequest.php';
        if (DeleteRequest::isPending('transaksi', $id, $tid)) {
            echo json_encode(['error'=>'Order sedang menunggu persetujuan hapus — tidak bisa diedit sampai di-review owner.']); exit;
        }

        $db = Database::get();
        $db->beginTransaction();
        try {
            // Verify ownership
            $oldRow = $db->prepare("SELECT status_proses,status_bayar,catatan,dp,total,diskon,
                                           pelanggan_id,telepon,no_order,nama_pelanggan,
                                           biaya_tambahan,biaya_lainnya
                                      FROM hl_transaksi
                                     WHERE tenant_id=? AND outlet_id=? AND id=? FOR UPDATE");
            $oldRow->execute([$tid, $oid, $id]);
            $oldRow = $oldRow->fetch();
            if (!$oldRow) { $db->rollBack(); echo json_encode(['error'=>'Order tidak ditemukan']); exit; }

            // Deteksi apakah item benar-benar berubah (frontend selalu kirim items walau tak diubah)
            $oldItemsStmt = $db->prepare("SELECT nama_layanan,satuan,jumlah,harga_satuan,catatan_item,express_tier_nama FROM hl_transaksi_item WHERE tenant_id=? AND outlet_id=? AND transaksi_id=? ORDER BY id");
            $oldItemsStmt->execute([$tid, $oid, $id]);
            $oldItems = $oldItemsStmt->fetchAll(PDO::FETCH_ASSOC);
            $sigItems = function($items){ return array_map(function($it){ return [
                trim((string)($it['nama_layanan'] ?? '')), trim((string)($it['satuan'] ?? '')),
                (float)($it['jumlah'] ?? 0), (float)($it['harga_satuan'] ?? 0),
                trim((string)($it['catatan_item'] ?? '')),
                trim((string)($it['express_tier_nama'] ?? '')),  // include tier name dalam deteksi perubahan
            ]; }, $items ?? []); };
            $itemsChanged = ($sigItems($oldItems) != $sigItems($data['items'] ?? []));

            $diskonBerubah = abs((float)($data['diskon'] ?? 0) - (float)$oldRow['diskon']) > 0.001;

            if (($itemsChanged || $diskonBerubah) && !hasPermission('orders.edit')) {
                $db->rollBack();
                echo json_encode(['error' => 'Butuh izin edit order untuk mengubah layanan/diskon']); exit;
            }

            // Recalc total jika ada items baru
            $subtotal = 0;
            if (!empty($data['items'])) {
                foreach ($data['items'] as $item) {
                    $subtotal += floatval($item['jumlah']) * floatval($item['harga_satuan']);
                }
            }

            // biaya_tambahan (Tier Express) & biaya_lainnya (Biaya Lainnya Tier)
            // DIRECOMPUTE dari kondisi terbaru setiap kali item berubah — server-side,
            // tidak percaya nilai fee dari klien (sama pola pos.php). Kalau item TIDAK
            // berubah, tetap dari row lama (persis sebelumnya, tidak disentuh).
            // Lihat docs/superpowers/specs/2026-09-05-tier-express-edit-order-design.md
            require_once ROOT . '/core/ExpressTier.php';
            require_once ROOT . '/core/BiayaLainnyaTier.php';
            $itemsWithTier    = [];
            $biayaLainnyaRows = [];
            $dom = ['nama' => null, 'tipe_order' => 'reguler'];
            if ($itemsChanged && !empty($data['items'])) {
                $biayaTambahanBaru = 0.0;
                foreach ($data['items'] as $i => $item) {
                    $namaTier = trim((string)($item['express_tier_nama'] ?? ''));
                    $tier = $namaTier !== '' ? ExpressTier::findByNama($tid, $namaTier, $oid) : null;
                    $itSub = floatval($item['jumlah']) * floatval($item['harga_satuan']);
                    $itFee = ExpressTier::calcItemFee($itSub, $tier);
                    $itemsWithTier[$i] = [
                        'express_tier_nama' => $tier ? $tier['nama_tier'] : null,
                        'biaya_express'     => $itFee,
                    ];
                    $biayaTambahanBaru += $itFee;
                }
                $dom = ExpressTier::dominantTier(array_map(
                    fn($it, $x) => array_merge($it, $x), $data['items'], $itemsWithTier
                ));
                $biayaLainnyaRows = BiayaLainnyaTier::calcAppliedFees($tid, $oid, $subtotal);
                $biayaLainnyaBaru = array_sum(array_column($biayaLainnyaRows, 'nominal'));
            } else {
                $biayaTambahanBaru = (float)($oldRow['biaya_tambahan'] ?? 0);
                $biayaLainnyaBaru  = (float)($oldRow['biaya_lainnya'] ?? 0);
            }

            $diskon = floatval($data['diskon'] ?? 0);
            $total  = $subtotal > 0
                ? max(0, $subtotal - $diskon + $biayaTambahanBaru + $biayaLainnyaBaru)
                : max(0, floatval($data['total'] ?? 0));
            $dp     = floatval($data['dp'] ?? 0);
            $sisa   = $total - $dp;
            $sbayar = $dp >= $total && $total > 0 ? 'lunas' : ($dp > 0 ? 'dp' : 'belum_bayar');

            // ── Gerbang order LUNAS yang totalnya berubah ─────────────
            $gate = OrderEditResolver::resolve([
                'sbayar_lama'     => $oldRow['status_bayar'],
                'total_lama'      => (float)$oldRow['total'],
                'dp_lama'         => (float)$oldRow['dp'],
                'total_baru'      => (float)$total,
                'berubah'         => $itemsChanged || $diskonBerubah,
                'resolusi'        => $data['confirm_resolution'] ?? null,
                'punya_pelanggan' => !empty($oldRow['pelanggan_id']),
            ]);
            $gateAksi = null; $gateSelisih = 0.0;
            if ($gate['gate']) {
                if (isset($gate['need_confirm'])) {
                    $db->rollBack();
                    echo json_encode([
                        'need_confirm' => $gate['need_confirm'],
                        'selisih'      => (int)round($gate['selisih']),
                        'total_baru'   => (float)$gate['total_baru'],
                        'bisa_deposit' => $gate['bisa_deposit'],
                    ]); exit;
                }
                if (isset($gate['error'])) {
                    $db->rollBack();
                    echo json_encode(['error' => $gate['error']]); exit;
                }
                // Override hasil hitung default dgn keputusan resolver
                $dp     = $gate['apply']['dp'];
                $sisa   = $gate['apply']['sisa'];
                $sbayar = $gate['apply']['status'];
                $gateAksi    = $gate['apply']['aksi'];
                $gateSelisih = (float)$gate['apply']['selisih'];
            }
            $sisa = max(0.0, $sisa); // clamp global — sisa_bayar tak pernah negatif

            // Update header.
            // tgl_selesai distempel saat status pertama kali jadi siap/diambil/selesai.
            // handled_by distempel saat status pertama kali keluar dari 'masuk' (mulai dikerjakan).
            // Stamp dihitung di PHP supaya tidak ada perbandingan `? IN (...)` di SQL
            // (penyebab error collation 1271 di sebagian konfigurasi MariaDB).
            $sp           = $data['status_proses'];
            $stampSelesai = in_array($sp, ['siap','diambil','selesai'], true);
            $stampHandled = ($sp !== 'masuk');

            $setParts = [
                'status_proses=?', 'status_bayar=?', 'catatan=?', 'catatan_internal=?',
                'metode_bayar=?', 'dp=?', 'sisa_bayar=?', 'diskon=?', 'total=?',
                'subtotal=?', 'estimasi_selesai=?',
            ];
            $params = [
                $sp, $sbayar,
                $data['catatan'] ?? '', $data['catatan_internal'] ?? '',
                $data['metode_bayar'] ?? 'cash',
                $dp, $sisa, $diskon, $total, $subtotal > 0 ? $subtotal : null,
                $data['estimasi'] ?: null,
            ];
            // Foto pickup — disimpan saat status 'diambil' & ada foto dari upload
            $fotoPickup = trim((string)($data['foto_pickup'] ?? ''));
            if ($fotoPickup !== '' && $sp === 'diambil') {
                $hasFotoPickup = true;
                try { $db->query("SELECT foto_pickup FROM hl_transaksi LIMIT 1"); }
                catch (Throwable) { $hasFotoPickup = false; }
                if ($hasFotoPickup) {
                    $setParts[] = 'foto_pickup=?';
                    $params[]   = substr($fotoPickup, 0, 255);
                }
            }
            if ($stampSelesai) {
                $setParts[] = 'tgl_selesai = CASE WHEN tgl_selesai IS NULL THEN CURDATE() ELSE tgl_selesai END';
            }
            if ($stampHandled) {
                $setParts[] = 'handled_by = CASE WHEN handled_by IS NULL THEN ? ELSE handled_by END';
                $params[]   = (int)$user['id'];
            }
            $params = [...$params, $tid, $oid, $id];
            $stmt = $db->prepare("UPDATE hl_transaksi SET " . implode(', ', $setParts)
                 . " WHERE tenant_id=? AND outlet_id=? AND id=?");
            $stmt->execute($params);

            // Update items HANYA jika benar-benar berubah (hindari churn + log palsu)
            if (!empty($data['items']) && $itemsChanged) {
                $db->prepare("DELETE FROM hl_transaksi_item WHERE tenant_id=? AND outlet_id=? AND transaksi_id=?")->execute([$tid, $oid, $id]);
                $istmt = $db->prepare("INSERT INTO hl_transaksi_item
                    (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal,catatan_item,express_tier_nama,biaya_express)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                foreach ($data['items'] as $i => $item) {
                    $sub = floatval($item['jumlah']) * floatval($item['harga_satuan']);
                    $istmt->execute([
                        $tid, $oid, $id,
                        $item['layanan_id'] ?: null,
                        $item['nama_layanan'],
                        $item['satuan'],
                        $item['jumlah'],
                        $item['harga_satuan'],
                        $sub,
                        $item['catatan_item'] ?? '',
                        $itemsWithTier[$i]['express_tier_nama'] ?? null,
                        $itemsWithTier[$i]['biaya_express'] ?? 0,
                    ]);
                }
                // Update subtotal + biaya_tambahan + biaya_lainnya + ringkasan tier di header
                $db->prepare("UPDATE hl_transaksi SET subtotal=?, biaya_tambahan=?, biaya_lainnya=?, tipe_order=?, express_tier_nama=? WHERE tenant_id=? AND outlet_id=? AND id=?")
                   ->execute([$subtotal, $biayaTambahanBaru, $biayaLainnyaBaru, $dom['tipe_order'], $dom['nama'], $tid, $oid, $id]);

                // Refresh breakdown Biaya Lainnya (persis pola insert di pos.php:592)
                $db->prepare("DELETE FROM hl_transaksi_biaya_lainnya WHERE tenant_id=? AND outlet_id=? AND transaksi_id=?")->execute([$tid, $oid, $id]);
                if (!empty($biayaLainnyaRows)) {
                    $blstmt = $db->prepare("INSERT INTO hl_transaksi_biaya_lainnya (tenant_id, outlet_id, transaksi_id, nama, nominal) VALUES (?,?,?,?,?)");
                    foreach ($biayaLainnyaRows as $r) {
                        $blstmt->execute([$tid, $oid, $id, $r['nama'], $r['nominal']]);
                    }
                }
            }

            // ── Eksekusi uang utk resolusi gerbang lunas ──────────────
            $waUrl = null;
            if ($gateAksi === 'refund_tunai') {
                $db->prepare("INSERT INTO hl_kas
                    (tenant_id, outlet_id, tanggal, tipe, kategori, keterangan, jumlah, ref_order, created_by, created_at)
                    VALUES (?,?,?,'keluar','Refund Order',?,?,?,?,?)")
                   ->execute([$tid, $oid, date('Y-m-d'),
                       'Refund koreksi order ' . $oldRow['no_order'] . ' — ' . $oldRow['nama_pelanggan'],
                       $gateSelisih, $oldRow['no_order'], (int)$user['id'], date('Y-m-d H:i:s')]);
            } elseif ($gateAksi === 'ke_deposit') {
                // applyBonus=false + numpang transaksi ini; exception → rollback utuh di catch existing
                [, $depErr] = DepositManager::topup(
                    $tid, $oid, (int)$oldRow['pelanggan_id'], $gateSelisih,
                    'refund_order', 'Refund koreksi order ' . $oldRow['no_order'],
                    (int)$user['id'], null, false
                );
                if ($depErr) { throw new RuntimeException('Gagal ke deposit: ' . $depErr); }
            } elseif ($gateAksi === 'tagih' && !empty($oldRow['telepon'])) {
                $p = preg_replace('/[^0-9]/', '', $oldRow['telepon']);
                if (strpos($p, '0') === 0) $p = '62' . substr($p, 1);
                elseif (strpos($p, '62') !== 0) $p = '62' . $p;
                $outletNama = TenantResolver::getOutlet()['nama_outlet'] ?? 'kami';
                $waMsg = "Halo {$oldRow['nama_pelanggan']}, saat penimbangan cucian order {$oldRow['no_order']} "
                       . "terdapat layanan tambahan sehingga total menjadi Rp " . number_format($total, 0, ',', '.') . ". "
                       . "Kekurangan Rp " . number_format($gateSelisih, 0, ',', '.') . " dapat dibayar saat pengambilan. "
                       . "Terima kasih 🙏 — {$outletNama}";
                $waUrl = 'https://wa.me/' . $p . '?text=' . rawurlencode($waMsg);
            }
            if ($gateAksi !== null) {
                $aksiLabel = ['tagih' => 'Lunas → DP: penambahan layanan, kurang Rp ',
                              'refund_tunai' => 'Koreksi turun: refund tunai Rp ',
                              'ke_deposit' => 'Koreksi turun: masuk deposit Rp '][$gateAksi];
                $logs_gate = $aksiLabel . number_format($gateSelisih, 0, ',', '.');
            }

            // ── LOG semua perubahan ──────────────────────────────
            $logs_to_insert = [];

            if ($oldRow['status_proses'] !== $data['status_proses']) {
                $logs_to_insert[] = [
                    'transaksi_id' => $id,
                    'status_lama'  => $oldRow['status_proses'],
                    'status_baru'  => $data['status_proses'],
                    'tipe'         => 'proses',
                    'catatan'      => 'Status diubah: ' . $oldRow['status_proses'] . ' → ' . $data['status_proses'],
                    'oleh'         => $user['nama']
                ];
            }

            if ($oldRow['status_bayar'] !== $sbayar && $gateAksi === null) {
                $logs_to_insert[] = [
                    'transaksi_id' => $id,
                    'status_lama'  => $oldRow['status_bayar'],
                    'status_baru'  => $sbayar,
                    'tipe'         => 'bayar',
                    'catatan'      => 'Pembayaran diupdate: DP Rp ' . number_format($dp, 0, ',', '.') . ' · Status: ' . $sbayar,
                    'oleh'         => $user['nama']
                ];
            }

            if (!empty($data['items']) && $itemsChanged) {
                $newItemNames = implode(', ', array_column($data['items'], 'nama_layanan'));
                $logs_to_insert[] = [
                    'transaksi_id' => $id,
                    'status_lama'  => null,
                    'status_baru'  => 'items_updated',
                    'tipe'         => 'items',
                    'catatan'      => 'Layanan diupdate: ' . $newItemNames,
                    'oleh'         => $user['nama']
                ];
            }

            if (trim($oldRow['catatan'] ?? '') !== trim($data['catatan'] ?? '')) {
                $logs_to_insert[] = [
                    'transaksi_id' => $id,
                    'status_lama'  => null,
                    'status_baru'  => 'catatan_updated',
                    'tipe'         => 'catatan',
                    'catatan'      => 'Catatan diupdate',
                    'oleh'         => $user['nama']
                ];
            }

            if ($gateAksi !== null) {
                $logs_to_insert[] = [
                    'transaksi_id' => $id,
                    'status_lama'  => $oldRow['status_bayar'],
                    'status_baru'  => $sbayar,
                    'tipe'         => 'bayar',
                    'catatan'      => '💰 ' . $logs_gate,
                    'oleh'         => $user['nama'],
                ];
            }

            $lstmt = $db->prepare("INSERT INTO hl_proses_log (transaksi_id,status_lama,status_baru,tipe,catatan,oleh,created_at) VALUES (?,?,?,?,?,?,?)");
            foreach ($logs_to_insert as $log) {
                $lstmt->execute([
                    $log['transaksi_id'],
                    $log['status_lama'],
                    $log['status_baru'],
                    $log['tipe'],
                    $log['catatan'],
                    $log['oleh'],
                    date('Y-m-d H:i:s')
                ]);
            }

            logAudit('update', 'orders', 'Update order ID: ' . $id);
            $db->commit();

            // Loyalty: earn poin saat status_proses berubah ke 'siap' (idempotent)
            $poinEarned = 0;
            if ($data['status_proses'] === 'siap' && $oldRow['status_proses'] !== 'siap') {
                try {
                    $prow = TenantQuery::rawOne("SELECT pelanggan_id,total,no_order FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND id=?", [$tid,$oid,$id]);
                    if ($prow && $prow['pelanggan_id'])
                        $poinEarned = Loyalty::earnForTransaction($tid, $oid, (int)$id, (int)$prow['pelanggan_id'], (float)$prow['total']);
                    $noOrder = $prow['no_order'] ?? '';
                } catch (Throwable $e) {
                    ErrorLogger::logException('loyalty_error', $e, $tid, $oid);
                    $noOrder = '';
                }
                PushSender::send('order_siap', (int)$tid, (int)$oid, [
                    'title' => 'Order siap diambil',
                    'body'  => '#' . $noOrder . ' siap diambil',
                    'url'   => '/orders?q=' . urlencode($noOrder),
                ]);
            }

            echo json_encode(['success' => true, 'poin_earned' => $poinEarned, 'wa_url' => $waUrl]);
        } catch (Throwable $e) {
            $db->rollBack();
            apiErr($e);
        }
        exit;
    }

    // UPDATE PEMBAYARAN — pilihan sebagian/lunas + bukti
    if ($action === 'bayar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        if (!hasPermission('orders.bayar')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        $id     = intval($_POST['id'] ?? 0);
        $tipe   = $_POST['tipe_bayar'] ?? 'sebagian';
        $jumlah = floatval($_POST['jumlah'] ?? 0);

        require_once ROOT . '/core/DeleteRequest.php';
        if (DeleteRequest::isPending('transaksi', $id, $tid)) {
            echo json_encode(['error'=>'Order sedang menunggu persetujuan hapus — tidak bisa update bayar.']); exit;
        }

        // Verify ownership & get current data
        $row = TenantQuery::rawOne("SELECT total, dp, sisa_bayar FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND id=?", [$tid, $oid, $id]);
        if (!$row) { echo json_encode(['error'=>'Order tidak ditemukan']); exit; }

        $old_dp = floatval($row['dp']);
        // Clamp new_dp ke total order — kalau kasir terima cash lebih dari yg
        // dibutuhkan (mis. lunasin sisa Rp20rb tapi customer kasih Rp50rb), sisanya
        // KEMBALIAN, bukan bagian dari dp/pendapatan order ini.
        $new_dp   = min(floatval($row['total']), $old_dp + $jumlah);
        if ($tipe === 'lunas') {
            $new_dp = floatval($row['total']);
        }
        $new_sisa = max(0, floatval($row['total']) - $new_dp);
        $new_status = $new_sisa <= 0 ? 'lunas' : ($new_dp > 0 ? 'dp' : 'belum_bayar');
        // Jumlah yg BENERAN nambah ke dp order (dipakai sbg nominal kas, bukan $jumlah
        // mentah) — supaya kelebihan cash yg jadi kembalian tidak ikut tercatat sbg
        // pendapatan di Kas.
        $kasJumlahBayar = max(0, $new_dp - $old_dp);

        // Upload bukti bayar — validasi MIME type (bukan hanya ekstensi)
        $bukti_path = null;
        if (!empty($_FILES['bukti']['tmp_name']) && $_FILES['bukti']['error'] === UPLOAD_ERR_OK) {
            $f = $_FILES['bukti'];
            // Cek ukuran (max 5MB)
            if ($f['size'] > 5 * 1024 * 1024) {
                echo json_encode(['error'=>'Ukuran file maksimal 5 MB.']); exit;
            }
            // Validasi MIME via finfo (bukan dari nama file — tidak bisa dimanipulasi)
            $allowedMime = ['image/jpeg','image/png','image/gif','image/webp'];
            $mimeMap     = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = $finfo ? finfo_file($finfo, $f['tmp_name']) : null;
            if ($finfo) finfo_close($finfo);
            if (!$mime || !in_array($mime, $allowedMime, true)) {
                echo json_encode(['error'=>'Format tidak didukung. Gunakan JPG, PNG, atau WebP.']); exit;
            }
            $ext      = $mimeMap[$mime];
            $dir      = __DIR__ . '/uploads/bukti_bayar/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $filename = 'bukti_' . $id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (move_uploaded_file($f['tmp_name'], $dir . $filename)) {
                $bukti_path = 'uploads/bukti_bayar/' . $filename;
            }
        }

        $db = Database::get();
        $db->beginTransaction();
        try {
            $pmMap    = validPayMethods($tid, $oid);
            $metodeIn = $_POST['metode'] ?? 'cash';
            if (!isset($pmMap[$metodeIn])) $metodeIn = 'cash';
            $upd    = "UPDATE hl_transaksi SET dp=?, sisa_bayar=?, status_bayar=?, metode_bayar=?";
            $params = [$new_dp, $new_sisa, $new_status, $metodeIn];
            if ($bukti_path) { $upd .= ", bukti_bayar=?"; $params[] = $bukti_path; }
            $upd .= " WHERE tenant_id=? AND outlet_id=? AND id=?"; $params[] = $tid; $params[] = $oid; $params[] = $id;
            $db->prepare($upd)->execute($params);

            // Log
            $ket = $tipe === 'lunas'
                ? "Pembayaran LUNAS Rp " . number_format($new_dp, 0, ',', '.')
                : "Pembayaran sebagian Rp " . number_format($jumlah, 0, ',', '.') . " · Sisa Rp " . number_format(max($new_sisa, 0), 0, ',', '.');
            if ($bukti_path) $ket .= " · Bukti bayar terlampir";

            $db->prepare("INSERT INTO hl_proses_log (transaksi_id,status_lama,status_baru,tipe,catatan,oleh,created_at) VALUES (?,?,?,?,?,?,?)")
               ->execute([$id, $row['dp'] > 0 ? 'dp' : 'belum_bayar', $new_status, 'bayar', $ket, $user['nama'], date('Y-m-d H:i:s')]);

            // Ambil no_order & nama_pelanggan
            $trxData = $db->prepare("SELECT no_order, nama_pelanggan FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND id=?");
            $trxData->execute([$tid, $oid, $id]);
            $trx = $trxData->fetch();

            $metodeLabel = $pmMap[$metodeIn] ?? ucfirst($metodeIn);
            $kasKet = ($tipe === 'lunas' ? 'Pelunasan' : 'Pembayaran sebagian') .
                      ' order ' . ($trx['no_order'] ?? '') .
                      ' - ' . ($trx['nama_pelanggan'] ?? '') .
                      ' via ' . $metodeLabel;

            // AUTO INSERT KAS MASUK — hanya kalau ada nominal yg beneran nambah dp
            // (lihat $kasJumlahBayar: dicap ke total, kelebihan cash = kembalian)
            if ($kasJumlahBayar > 0) {
                TenantQuery::insert('hl_kas', [
                    'tanggal'    => date('Y-m-d'),
                    'tipe'       => 'masuk',
                    'kategori'   => 'Pelunasan Order',
                    'keterangan' => $kasKet,
                    'jumlah'     => $kasJumlahBayar,
                    'ref_order'  => $trx['no_order'] ?? null,
                    'created_by' => $user['id'],
                ]);
            }

            logAudit('payment', 'orders', 'Pembayaran order: ' . ($trx['no_order'] ?? '') . ', Rp ' . number_format($jumlah, 0, ',', '.'));
            $db->commit();

            // Loyalty: earn TIDAK di-trigger oleh pembayaran lagi (sekarang
            // by status_proses='siap'). Cuma touch last_transaksi.
            $poinEarned = 0;
            $pelangganId = null;
            try {
                $prow = TenantQuery::rawOne("SELECT pelanggan_id FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND id=?", [$tid,$oid,$id]);
                if ($prow && $prow['pelanggan_id']) {
                    $pelangganId = $prow['pelanggan_id'];
                    Loyalty::touchLastTransaksi($tid, (int)$pelangganId);
                }
            } catch (Throwable $e) {
                ErrorLogger::logException('loyalty_error', $e, $tid, $oid);
            }

            // Referral payout — dipanggil SETELAH commit (payoutOnFirstLunas buka
            // transaksi sendiri). Idempoten & best-effort — tidak boleh gagalkan response.
            if ($new_status === 'lunas' && $pelangganId) {
                try {
                    Referral::payoutOnFirstLunas($tid, (int)$pelangganId, (int)$id, $user['id']);
                } catch (Throwable $e) {
                    ErrorLogger::logException('referral_payout_bayar', $e, $tid, $oid);
                }
            }

            echo json_encode([
                'success'      => true,
                'poin_earned'  => $poinEarned,
                'status_bayar' => $new_status,
                'dp'           => $new_dp,
                'sisa'         => max($new_sisa, 0),
                'bukti'        => $bukti_path
            ]);
        } catch (Throwable $e) {
            $db->rollBack();
            apiErr($e);
        }
        exit;
    }

    // BULK UPDATE STATUS_PROSES
    if ($action === 'bulk_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hasPermission('orders.update_status')) {
            echo json_encode(['error'=>'Akses ditolak']); exit;
        }
        verifyCsrf();
        $data = json_decode(file_get_contents('php://input'), true);
        $ids    = $data['ids'] ?? [];
        $status = $data['status'] ?? '';
        $allowed = ['masuk','cuci','kering','setrika','siap','diambil'];
        if (!in_array($status, $allowed, true)) { echo json_encode(['error'=>'Status tidak valid']); exit; }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_filter($ids, fn($x) => $x > 0);
        if (!$ids) { echo json_encode(['error'=>'Tidak ada order dipilih']); exit; }
        if (count($ids) > 100) { echo json_encode(['error'=>'Maksimal 100 order per bulk']); exit; }

        $db = Database::get();
        $db->beginTransaction();
        try {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            // Status sudah divalidasi di PHP → tentukan stamp di PHP, hindari
            // perbandingan `? IN (...)` di SQL (penyebab error collation 1271).
            $stampHandled = in_array($status, ['cuci','kering','setrika','siap','diambil','selesai'], true);
            $stampSelesai = in_array($status, ['siap','diambil','selesai'], true);

            $setParts = ['status_proses=?'];
            $params   = [$status];
            if ($stampHandled) {
                $setParts[] = 'handled_by = CASE WHEN handled_by IS NULL THEN ? ELSE handled_by END';
                $params[]   = $user['id'];
            }
            if ($stampSelesai) {
                $setParts[] = 'tgl_selesai = CASE WHEN tgl_selesai IS NULL THEN NOW() ELSE tgl_selesai END';
            }
            $sql = "UPDATE hl_transaksi SET " . implode(', ', $setParts)
                 . " WHERE tenant_id=? AND outlet_id=? AND id IN ($ph)";
            $params = [...$params, $tid, $oid, ...$ids];
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $affected = $stmt->rowCount();

            // Log per id
            $lg = $db->prepare("INSERT INTO hl_proses_log (tenant_id,transaksi_id,status_baru,oleh,catatan,created_at) VALUES (?,?,?,?,?,?)");
            foreach ($ids as $oidord) {
                $lg->execute([$tid, $oidord, $status, $user['nama'], 'Bulk update', date('Y-m-d H:i:s')]);
            }
            $db->commit();
            logAudit('bulk_status', 'orders', count($ids).' order → '.$status);
            echo json_encode(['ok'=>true, 'affected'=>$affected]);
        } catch (Throwable $e) {
            $db->rollBack();
            apiErr($e);
        }
        exit;
    }

    // ── BULK MARK PAID (set status_bayar='lunas' untuk order yg belum lunas) ──
    if ($action === 'bulk_pay' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hasPermission('orders.bayar')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $data = json_decode(file_get_contents('php://input'), true);
        $ids = $data['ids'] ?? [];
        $metode = isset(validPayMethods($tid, $oid)[$data['metode'] ?? '']) ? $data['metode'] : 'cash';
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_filter($ids, fn($x) => $x > 0);
        if (!$ids) { echo json_encode(['error'=>'Tidak ada order dipilih']); exit; }
        if (count($ids) > 100) { echo json_encode(['error'=>'Maksimal 100 order per bulk']); exit; }

        $db = Database::get();
        $db->beginTransaction();
        try {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            // Cari order yang belum lunas + collect kas masuk insertion
            $sel = $db->prepare(
                "SELECT id, no_order, total, dp, sisa_bayar, status_bayar, pelanggan_id
                   FROM hl_transaksi
                  WHERE tenant_id=? AND outlet_id=? AND id IN ($ph)
                    AND status_bayar != 'lunas'"
            );
            $sel->execute([$tid, $oid, ...$ids]);
            $rows = $sel->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                $db->rollBack();
                echo json_encode(['ok'=>true, 'affected'=>0, 'msg'=>'Semua order yang dipilih sudah lunas']);
                exit;
            }

            // Update status_bayar + zero sisa
            $upd = $db->prepare(
                "UPDATE hl_transaksi
                    SET status_bayar='lunas', sisa_bayar=0, dp=total, metode_bayar=?, updated_at=NOW()
                  WHERE tenant_id=? AND outlet_id=? AND id=?"
            );
            // Insert kas masuk per order untuk sisa_bayar yang baru dibayar
            $kas = $db->prepare(
                "INSERT INTO hl_kas (tenant_id, outlet_id, tanggal, tipe, kategori, keterangan, jumlah, ref_order, created_by, created_at)
                 VALUES (?, ?, ?, 'masuk', 'pelunasan', ?, ?, ?, ?, ?)"
            );

            $count = 0; $totalIn = 0;
            foreach ($rows as $r) {
                $upd->execute([$metode, $tid, $oid, (int)$r['id']]);
                $remain = max(0, (float)$r['sisa_bayar']);
                if ($remain > 0) {
                    $kas->execute([$tid, $oid, date('Y-m-d'), 'Pelunasan order #'.$r['no_order'].' ('.$metode.')', $remain, $r['no_order'], $user['id'], date('Y-m-d H:i:s')]);
                    $totalIn += $remain;
                }
                $count++;
            }
            $db->commit();
            logAudit('bulk_pay', 'orders', $count.' order → lunas ('.$metode.') total Rp '.number_format($totalIn,0,',','.'));

            // Referral payout — best-effort, SETELAH commit (payoutOnFirstLunas buka tx sendiri)
            foreach ($rows as $r) {
                if (!empty($r['pelanggan_id'])) {
                    try {
                        Referral::payoutOnFirstLunas($tid, (int)$r['pelanggan_id'], (int)$r['id'], $user['id']);
                    } catch (Throwable $e) {
                        ErrorLogger::logException('referral_payout_bulk_pay', $e, $tid, $oid);
                    }
                }
            }

            echo json_encode(['ok'=>true, 'affected'=>$count, 'total_in'=>$totalIn]);
        } catch (Throwable $e) {
            $db->rollBack();
            ErrorLogger::logException('bulk_pay', $e, $tid, $oid);
            echo json_encode(['error'=>'Gagal proses bulk bayar']);
        }
        exit;
    }

    // ── BULK WA: return list WA links untuk klik manual (no auto-send) ─────
    if ($action === 'bulk_wa') {
        if (!hasPermission('orders.view_all')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        $idsRaw = $_GET['ids'] ?? '';
        $ids = array_filter(array_map('intval', explode(',', $idsRaw)), fn($x) => $x > 0);
        if (!$ids) { echo json_encode(['error'=>'Tidak ada order']); exit; }
        if (count($ids) > 50) { echo json_encode(['error'=>'Max 50 order per batch WA']); exit; }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        try {
            $st = Database::get()->prepare(
                "SELECT id, no_order, nama_pelanggan, telepon, status_proses, total, sisa_bayar
                   FROM hl_transaksi
                  WHERE tenant_id=? AND outlet_id=? AND id IN ($ph)"
            );
            $st->execute([$tid, $oid, ...$ids]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $base = (defined('APP_URL') ? APP_URL : 'https://lamasy.harpy.id');
            $links = [];
            foreach ($rows as $r) {
                $tel = preg_replace('/[^0-9]/', '', $r['telepon'] ?? '');
                if (!$tel) continue;
                if ($tel[0] === '0') $tel = '62' . substr($tel, 1);
                $statusLabel = ['siap'=>'sudah siap diambil','diambil'=>'sudah diambil'][$r['status_proses']] ?? 'sedang diproses';
                $msg = "Halo {$r['nama_pelanggan']}, laundry kamu (No. {$r['no_order']}) $statusLabel. Cek detail: {$base}/track?order={$r['no_order']}";
                if ((float)$r['sisa_bayar'] > 0) {
                    $msg .= "\n\nTotal: Rp " . number_format($r['total'], 0, ',', '.') . " · Sisa: Rp " . number_format($r['sisa_bayar'], 0, ',', '.');
                }
                $links[] = [
                    'no_order' => $r['no_order'],
                    'nama'     => $r['nama_pelanggan'],
                    'url'      => 'https://wa.me/' . $tel . '?text=' . rawurlencode($msg),
                ];
            }
            $skipped = count($rows) - count($links);
            echo json_encode(['ok'=>true, 'links'=>$links, 'skipped_no_phone'=>$skipped]);
        } catch (Throwable $e) {
            ErrorLogger::logException('bulk_wa', $e, $tid, $oid);
            echo json_encode(['error'=>'Gagal generate']);
        }
        exit;
    }

    // LOOKUP order by no_order — untuk scan QR (label/struk) → buka detail
    if ($action === 'find_by_kode') {
        $kode = trim($_GET['kode'] ?? '');
        if ($kode === '' || !preg_match('/^[A-Z0-9\-\/_]{4,40}$/i', $kode)) {
            echo json_encode(['error' => 'Kode tidak valid']); exit;
        }
        $row = TenantQuery::rawOne(
            "SELECT id, no_order FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND no_order=? LIMIT 1",
            [$tid, $oid, $kode]
        );
        echo json_encode($row ?: ['error' => 'Order tidak ditemukan di outlet ini']); exit;
    }

    // LIST CATATAN INTERNAL (multi-row, hl_order_notes)
    if ($action === 'notes_list') {
        $oidv = intval($_GET['order_id'] ?? 0);
        if (!$oidv) { echo json_encode(['ok'=>true,'rows'=>[]]); exit; }
        try {
            $db = Database::get();
            $stmt = $db->prepare("SELECT id, user_id, user_nama, catatan, created_at
                                    FROM hl_order_notes
                                   WHERE tenant_id=? AND outlet_id=? AND transaksi_id=?
                                   ORDER BY id DESC");
            $stmt->execute([$tid, $oid, $oidv]);
            echo json_encode(['ok'=>true, 'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) {
            echo json_encode(['ok'=>true, 'rows'=>[]]);
        }
        exit;
    }

    // ADD CATATAN INTERNAL
    if ($action === 'note_add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $data    = json_decode(file_get_contents('php://input'), true);
        $oidv    = intval($data['order_id'] ?? 0);
        $catatan = trim($data['catatan'] ?? '');
        if (!$oidv || $catatan === '') { echo json_encode(['error'=>'order_id & catatan wajib']); exit; }

        // Verify ownership transaksi
        $ck = TenantQuery::rawOne("SELECT id FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND id=?", [$tid, $oid, $oidv]);
        if (!$ck) { echo json_encode(['error'=>'Order tidak ditemukan']); exit; }

        $userId   = $user['id']   ?? null;
        $userNama = $user['nama'] ?? null;
        try {
            $db = Database::get();
            // created_at ditulis eksplisit dari PHP (WIB) — default NOW() MySQL = UTC,
            // tampil selisih 7 jam di riwayat (fmtDateTime baca apa adanya)
            $stmt = $db->prepare("INSERT INTO hl_order_notes
                (tenant_id, outlet_id, transaksi_id, user_id, user_nama, catatan, created_at)
                VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$tid, $oid, $oidv, $userId, $userNama, $catatan, date('Y-m-d H:i:s')]);
            logAudit('note_add', 'order#'.$oidv, mb_substr($catatan,0,80));
            echo json_encode(['ok'=>true, 'id'=>(int)$db->lastInsertId()]);
        } catch (Throwable $e) {
            apiErr($e, 'Gagal simpan catatan. Silakan coba lagi.');
        }
        exit;
    }

    // SUMMARY stats
    if ($action === 'summary') {
        $statuses = ['masuk','cuci','kering','setrika','siap','diambil'];
        $sc = [];
        foreach ($statuses as $s) {
            $sc[$s] = TenantQuery::count('hl_transaksi', 'status_proses=?', [$s]);
        }
        echo json_encode(['statuses' => $sc]);
        exit;
    }

    // GET layanan
    if ($action === 'get_layanan') {
        $rows = TenantQuery::raw("SELECT * FROM hl_layanan WHERE tenant_id=? AND outlet_id=? AND is_active=1 ORDER BY kategori,urutan", [$tid, $oid]);
        echo json_encode($rows); exit;
    }

    // GET STRUK DATA — untuk cetak ulang nota
    if ($action === 'get_struk') {
        $id = intval($_GET['id']);
        $t  = TenantQuery::rawOne("SELECT * FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND id=?", [$tid, $oid, $id]);
        if (!$t) { echo json_encode(['error'=>'Not found']); exit; }
        $t['items'] = TenantQuery::raw("SELECT * FROM hl_transaksi_item WHERE tenant_id=? AND outlet_id=? AND transaksi_id=? ORDER BY id", [$tid, $oid, $id]);
        echo json_encode($t); exit;
    }

    // GENERATE WA REMINDER MESSAGE
    // ── Belum Diambil: daftar order 'siap' ≥2 hari yg belum diambil ──
    if ($action === 'pickup_reminders') {
        header('Content-Type: application/json');
        // Ambang bisa diatur owner per-outlet (default 2, clamp 1..30)
        $cfg  = TenantQuery::rawOne("SELECT pickup_reminder_days FROM outlets WHERE id=? AND tenant_id=?", [$oid, $tid]);
        $days = max(1, min(30, (int)($cfg['pickup_reminder_days'] ?? 2)));
        try {
            $rows = TenantQuery::raw(
                "SELECT t.id, t.no_order, t.nama_pelanggan, t.telepon,
                        t.reminder_last_at, t.reminder_count,
                        COALESCE(
                          (SELECT MAX(pl.created_at) FROM hl_proses_log pl
                            WHERE pl.transaksi_id=t.id AND pl.status_baru='siap'),
                          t.estimasi_selesai, t.tanggal
                        ) AS siap_at
                   FROM hl_transaksi t
                  WHERE t.tenant_id=? AND t.outlet_id=? AND t.status_proses='siap'
                 HAVING siap_at IS NOT NULL AND siap_at <= (NOW() - INTERVAL $days DAY)
                  ORDER BY siap_at ASC
                  LIMIT 100",
                [$tid, $oid]
            );
            echo json_encode(['ok'=>true, 'days'=>$days, 'count'=>count($rows), 'rows'=>$rows]);
        } catch (Throwable $e) { echo json_encode(['ok'=>false, 'count'=>0, 'rows'=>[], 'error'=>'x']); }
        exit;
    }

    // ── Tandai sudah diingatkan (dipanggil saat tombol WA diklik) ──
    if ($action === 'mark_reminded' && $_SERVER['REQUEST_METHOD']==='POST') {
        header('Content-Type: application/json');
        verifyCsrf();
        $d  = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($d['id'] ?? 0);
        $own = TenantQuery::rawOne("SELECT id FROM hl_transaksi WHERE id=? AND tenant_id=? AND outlet_id=?", [$id, $tid, $oid]);
        if (!$own) { echo json_encode(['error'=>'Not found']); exit; }
        Database::get()->prepare(
            "UPDATE hl_transaksi SET reminder_last_at=NOW(), reminder_count=reminder_count+1 WHERE id=? AND tenant_id=? AND outlet_id=?"
        )->execute([$id, $tid, $oid]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'wa_message') {
        $id   = intval($_GET['id']);
        $tipe = $_GET['tipe'] ?? 'reminder';
        $t    = TenantQuery::rawOne("SELECT * FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND id=?", [$tid, $oid, $id]);
        if (!$t) { echo json_encode(['error'=>'Not found']); exit; }

        $t['items'] = TenantQuery::raw("SELECT * FROM hl_transaksi_item WHERE tenant_id=? AND outlet_id=? AND transaksi_id=? ORDER BY id", [$tid, $oid, $id]);

        $itemList = '';
        foreach ($t['items'] as $item) {
            $itemList .= "\n   • " . $item['nama_layanan'] . " — " . floatval($item['jumlah']) . " " . $item['satuan'];
        }

        $totalFmt = "Rp " . number_format(floatval($t['total']), 0, ',', '.');
        $sisaFmt  = "Rp " . number_format(floatval($t['sisa_bayar']), 0, ',', '.');
        $est      = $t['estimasi_selesai'] ? date('d M Y', strtotime($t['estimasi_selesai'])) : '-';

        $appUrl   = defined('APP_URL') ? APP_URL : 'https://lamasy.harpy.id';
        $trackUrl = $appUrl . '/track.php?order=' . urlencode($t['no_order']);

        // Dinamis: nama brand + alamat outlet dari DB
        $tenant      = TenantResolver::getTenant();
        $outlet      = TenantResolver::getOutlet();
        $brandName   = $tenant['nama_perusahaan'] ?: ($outlet['nama_outlet'] ?? 'Laundry');
        $outletNama  = $outlet['nama_outlet'] ?? $brandName;
        $alamatLine  = trim(($outlet['alamat'] ?? '') . ($outlet['kota'] ? ', ' . $outlet['kota'] : ''));
        $alamatBlock = $alamatLine ? "{$outletNama}\n{$alamatLine}" : $outletNama;

        if ($tipe === 'siap') {
            $msg = "Halo *{$t['nama_pelanggan']}*,\n\n"
                 . "Laundry Anda di *{$brandName}* sudah *SIAP DIAMBIL*.\n\n"
                 . "*No. Order:* {$t['no_order']}\n"
                 . "*Layanan:*{$itemList}\n"
                 . "*Total:* {$totalFmt}\n"
                 . ($t['sisa_bayar'] > 0 ? "*Sisa Bayar:* {$sisaFmt}\n" : "*Status Bayar:* Lunas\n")
                 . "\nSilakan diambil di:\n{$alamatBlock}\n"
                 . "\nCek detail order: {$trackUrl}\n"
                 . "\nTerima kasih sudah mempercayakan cucian Anda kepada kami.\n_{$brandName}_";
        } elseif ($tipe === 'lunas_reminder') {
            $msg = "Halo *{$t['nama_pelanggan']}*,\n\n"
                 . "Ini pengingat untuk pelunasan laundry Anda di *{$brandName}*.\n\n"
                 . "*No. Order:* {$t['no_order']}\n"
                 . "*Total:* {$totalFmt}\n"
                 . "*Sisa yang harus dibayar:* {$sisaFmt}\n\n"
                 . "Detail order: {$trackUrl}\n\n"
                 . "Mohon segera dilunasi saat pengambilan ya.\n"
                 . "\nTerima kasih.\n_{$brandName}_";
        } else {
            $statusLabel = ['masuk'=>'Diterima','cuci'=>'Sedang Dicuci','kering'=>'Sedang Dikeringkan',
                'setrika'=>'Sedang Disetrika','siap'=>'Siap Diambil','diambil'=>'Sudah Diambil/Diantar'];
            $stLabel = $statusLabel[$t['status_proses']] ?? $t['status_proses'];
            $msg = "Halo *{$t['nama_pelanggan']}*,\n\n"
                 . "Update status laundry Anda di *{$brandName}*:\n\n"
                 . "*No. Order:* {$t['no_order']}\n"
                 . "*Status:* {$stLabel}\n"
                 . "*Est. Selesai:* {$est}\n"
                 . "*Layanan:*{$itemList}\n\n"
                 . "Cek status real-time: {$trackUrl}\n\n"
                 . "Terima kasih.\n_{$brandName}_";
        }

        $phone = preg_replace('/[^0-9]/', '', $t['telepon'] ?? '');
        if (substr($phone, 0, 1) === '0') $phone = '62' . substr($phone, 1);

        // Log WA pengiriman ke platform tracker
        WaLogger::log('order_notif', $phone, mb_substr($msg, 0, 200), $tid, $oid);

        echo json_encode(['success'=>true, 'message'=>$msg, 'phone'=>$phone, 'no_order'=>$t['no_order']]); exit;
    }

    echo json_encode(['error'=>'Unknown']); exit;
}

// Metode bayar aktif per-outlet utk dropdown edit & modal bayar (selaras pos.php)
$_pmStmt = Database::get()->prepare("
    SELECT code, label, emoji FROM hl_payment_methods
    WHERE outlet_id=? AND tenant_id=? AND is_active=1
    ORDER BY sort_order, id");
$_pmStmt->execute([TenantResolver::outletId(), TenantResolver::id()]);
$activeMethods = $_pmStmt->fetchAll(PDO::FETCH_ASSOC);
if (!$activeMethods) {
    $activeMethods = [
        ['code'=>'cash',     'label'=>'Tunai',         'emoji'=>'💵'],
        ['code'=>'transfer', 'label'=>'Transfer Bank', 'emoji'=>'🏦'],
        ['code'=>'qris',     'label'=>'QRIS',          'emoji'=>'📱'],
    ];
}

// Lebar dot printer utk cetak struk BT: ikut Format Struk outlet (thermal_58→384,
// lainnya→576); fallback label_size 58/80
try {
    $_fmtStmt = Database::get()->prepare("SELECT format FROM hl_struk_template WHERE tenant_id=? AND outlet_id=? AND tipe='retail' AND is_active=1 LIMIT 1");
    $_fmtStmt->execute([TenantResolver::id(), TenantResolver::outletId()]);
    $_strukFmt = (string)$_fmtStmt->fetchColumn();
} catch (Throwable) { $_strukFmt = ''; }
if ($_strukFmt === 'thermal_58') { $paperWidthPx = 384; }
elseif ($_strukFmt !== '')       { $paperWidthPx = 576; }
else {
    $_lsStmt = Database::get()->prepare("SELECT label_size FROM outlets WHERE id=? AND tenant_id=?");
    $_lsStmt->execute([TenantResolver::outletId(), TenantResolver::id()]);
    $paperWidthPx = ($_lsStmt->fetchColumn() === '58') ? 384 : 576;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Daftar Order'); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--teal:#35E8D5;--teal-d:#1CC4B2;--teal-bg:#E8FBF9;--navy:#1B2D5A;--navy-d:#0F1C3A;--white:#fff;--off:#F7F8FC;--light:#EEF1F8;--gray:#6C7A8D;--dark:#1C1C2E;--red:#EF4444;--green:#10B981;--yellow:#F59E0B;--blue:#3B82F6;--font:'Plus Jakarta Sans',sans-serif;--mono:'DM Mono',monospace;--r:10px;--r-lg:16px;--shadow:0 2px 12px rgba(27,45,90,.08);--shadow-lg:0 8px 32px rgba(27,45,90,.14)}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--off);color:var(--dark);min-height:100vh}
.topbar{background:var(--navy-d);padding:0 24px;height:56px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;border-bottom:1px solid rgba(53,232,213,.15)}
.topbar-brand span{color:var(--teal)}
.main{max-width:1300px;width:100%;margin:0 auto;padding:24px 20px}

/* STATS */
.stats{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;margin-bottom:24px}
.stat-card{background:var(--white);border-radius:var(--r-lg);padding:14px 16px;border:1px solid rgba(27,45,90,.07);box-shadow:var(--shadow);cursor:pointer;transition:all .2s;text-align:center}
.stat-card:hover,.stat-card.active{border-color:var(--teal);box-shadow:0 4px 16px rgba(53,232,213,.15)}
.stat-card.active{background:var(--teal-bg)}
.stat-num{font-size:clamp(0.85rem,3.4vw,1.5rem);white-space:nowrap;letter-spacing:-0.02em;font-weight:800;color:var(--navy);line-height:1;font-family:var(--mono)}
.stat-label{font-size:11px;color:var(--gray);margin-top:4px;font-weight:500}

/* FILTER BAR */
.filter-bar{display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap}
.filter-bar input{flex:1;min-width:200px;padding:9px 14px;border:1.5px solid rgba(27,45,90,.14);border-radius:var(--r);font-family:var(--font);font-size:14px;background:var(--white);outline:none;transition:all .2s}
.filter-bar input:focus{border-color:var(--teal);box-shadow:0 0 0 3px rgba(53,232,213,.1)}
.filter-bar select{padding:9px 12px;border:1.5px solid rgba(27,45,90,.14);border-radius:var(--r);font-family:var(--font);font-size:13px;background:var(--white);outline:none;cursor:pointer}

/* CARD */
.card{background:var(--white);border-radius:var(--r-lg);border:1px solid rgba(27,45,90,.07);box-shadow:var(--shadow);overflow:hidden}
.card-header{padding:16px 20px;border-bottom:1px solid var(--light);display:flex;align-items:center;justify-content:space-between}
.card-title{font-size:14px;font-weight:700;color:var(--navy)}

/* TABLE */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13.5px}
thead tr{background:var(--navy-d)}
thead th{padding:11px 12px;text-align:left;font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.6);white-space:nowrap}
tbody tr{border-bottom:1px solid var(--light);transition:background .15s;cursor:pointer}
tbody tr:hover{background:#F0FDF9}
tbody td{padding:11px 12px;vertical-align:middle}
.td-no{font-family:var(--mono);font-size:12px;color:var(--teal-d);font-weight:600}
.td-nama{font-weight:600;color:var(--navy)}
.td-total{font-family:var(--mono);font-weight:700;color:var(--navy);text-align:right}
.td-layanan{font-size:12px;color:var(--gray);max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

/* BADGES */
.badge{display:inline-block;font-size:11px;font-weight:600;letter-spacing:.04em;padding:3px 9px;border-radius:100px;white-space:nowrap}
.proses-bar{display:block;width:100%;max-width:80px;height:3px;background:#E5E7EB;border-radius:2px;overflow:hidden;margin-top:4px}
.proses-fill{height:100%;background:linear-gradient(90deg,#10B981,#0F7B6C);transition:width .3s ease}
.b-masuk{background:#DBEAFE;color:#1D4ED8}
.b-cuci{background:#FEF9C3;color:#854D0E}
.b-kering{background:#FEF3C7;color:#92400E}
.b-setrika{background:#EDE9FE;color:#5B21B6}
.b-siap{background:#D1FAE5;color:#065F46}
.b-diambil{background:#F3F4F6;color:#374151}
.b-lunas{background:#D1FAE5;color:#065F46}
.b-dp{background:#FEF3C7;color:#92400E}
.b-belum_bayar{background:#FEE2E2;color:#991B1B}

/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,28,58,.6);backdrop-filter:blur(4px);z-index:200;align-items:flex-start;justify-content:flex-end;padding:16px}
.modal-overlay.open{display:flex}
.modal{background:var(--white);border-radius:var(--r-lg);width:520px;max-width:95vw;height:calc(100vh - 32px);overflow-y:auto;box-shadow:var(--shadow-lg);display:flex;flex-direction:column}
.modal-header{padding:18px 20px;border-bottom:1px solid var(--light);display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:var(--white);z-index:10}
.modal-title{font-size:15px;font-weight:700;color:var(--navy)}
.modal-close{background:none;border:none;font-size:18px;cursor:pointer;color:var(--gray);padding:4px}
.modal-body{padding:20px;flex:1;overflow-y:auto}
.modal-footer{padding:16px 20px;border-top:1px solid var(--light);display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;position:sticky;bottom:0;background:var(--white)}

.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:12px}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.form-row.cols3{grid-template-columns:1fr 1fr 1fr}
/* tombol lmx (pengganti select/date) di form modal: samakan persis dgn input,select,textarea di bawah */
.form-group .lmx-btn{background:var(--off);border-radius:var(--r);color:var(--dark);font-weight:400}
label{font-size:11px;font-weight:700;color:var(--navy);letter-spacing:.05em;text-transform:uppercase}
input,select,textarea{padding:9px 12px;border:1.5px solid rgba(27,45,90,.14);border-radius:var(--r);font-family:var(--font);font-size:14px;color:var(--dark);background:var(--off);outline:none;transition:all .2s;width:100%}
input:focus,select:focus,textarea:focus{border-color:var(--teal);background:var(--white);box-shadow:0 0 0 3px rgba(53,232,213,.1)}
textarea{resize:vertical;min-height:64px}

/* ITEMS IN MODAL */
.items-table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:8px}
.items-table thead tr{background:var(--navy-d)}
.items-table thead th{padding:8px 8px;text-align:left;font-size:10px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.6)}
.items-table tbody tr{border-bottom:1px solid var(--light)}
.items-table tbody td{padding:5px 5px;vertical-align:middle}
.item-input{padding:6px 8px;font-size:12px}
.btn-remove{background:#FEE2E2;color:var(--red);border:none;border-radius:6px;padding:4px 7px;cursor:pointer;font-size:12px}
.btn-remove:hover{background:var(--red);color:white}
.item-sub{font-family:var(--mono);font-size:12px;text-align:right;white-space:nowrap}

/* PROSES STEPS */
.proses-steps{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:4px}
.step-btn{padding:7px 12px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;border:1.5px solid rgba(27,45,90,.12);background:var(--off);transition:all .2s;font-family:var(--font)}
.step-btn:hover{border-color:var(--teal);background:var(--teal-bg)}
.step-btn.active{background:var(--navy);color:var(--white);border-color:var(--navy)}

/* LOG */
.log-item{padding:8px 0;border-bottom:1px solid var(--light);font-size:12px;display:flex;gap:8px;align-items:flex-start}
.log-time{font-family:var(--mono);font-size:11px;color:var(--gray);white-space:nowrap;min-width:100px}
.log-text{color:var(--dark)}

/* TOTAL SUMMARY */
.total-box{background:linear-gradient(135deg,var(--navy-d),var(--navy));border-radius:var(--r);padding:14px 16px;color:var(--white);margin-bottom:12px}
.tb-row{display:flex;justify-content:space-between;font-size:13px;padding:3px 0}
.tb-label{color:rgba(255,255,255,.6)}
.tb-value{font-family:var(--mono);font-weight:600}
.tb-total{border-top:1px solid rgba(255,255,255,.2);margin-top:6px;padding-top:8px}
.tb-big{font-size:1.2rem;color:var(--teal)}
.tb-sisa{color:#FCA5A5}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:9px 16px;border-radius:var(--r);font-family:var(--font);font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;border:none}
.btn-primary{background:var(--teal);color:var(--navy-d)}
.btn-primary:hover{background:var(--teal-d)}
.btn-outline{background:transparent;color:var(--navy);border:1.5px solid rgba(27,45,90,.2)}
.btn-outline:hover{background:var(--light)}
.btn-teal-sm{background:var(--teal-bg);color:var(--teal-d);border:1px solid rgba(53,232,213,.3);font-size:12px;padding:6px 12px}
.btn-teal-sm:hover{background:var(--teal);color:var(--navy-d)}
.btn-sm{padding:6px 12px;font-size:12px}

.section-title{font-size:12px;font-weight:700;color:var(--navy);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px;margin-top:16px;display:flex;align-items:center;gap:6px}
.section-title::after{content:'';flex:1;height:1px;background:var(--light)}

.empty{text-align:center;padding:40px;color:var(--gray);font-size:14px}
.loading{text-align:center;padding:32px;color:var(--gray);font-size:14px}

.toast{position:fixed;bottom:20px;right:20px;z-index:999;padding:13px 18px;border-radius:var(--r);font-size:14px;font-weight:600;color:white;transform:translateY(60px);opacity:0;transition:all .3s cubic-bezier(.4,0,.2,1);pointer-events:none;max-width:320px}
.toast.show{transform:translateY(0);opacity:1}
.toast.success{background:var(--green)}
.wa-type-btn{transition:all .2s}
.toast.error{background:var(--red)}

/* ACTION BUTTONS */
.action-btns{display:flex;gap:5px;flex-wrap:wrap}
.th-sort{cursor:pointer;user-select:none;white-space:nowrap}
.th-sort:hover{background:rgba(255,255,255,.1)}
.sort-icon{font-size:10px;opacity:.5;margin-left:3px}
.th-sort.asc .sort-icon::after{content:'↑';opacity:1}
.th-sort.desc .sort-icon::after{content:'↓';opacity:1}
.th-sort.asc .sort-icon,.th-sort.desc .sort-icon{opacity:0}
.th-sort.asc::after,.th-sort.desc::after{content:'';margin-left:4px}
.action-btns .btn{padding:5px 9px;font-size:11px;white-space:nowrap}

/* STRUK PRINT */
.struk{font-family:'Courier New',monospace;font-size:12px;line-height:1.6;color:#000;max-width:300px;margin:0 auto}
.struk-header{text-align:center;border-bottom:1px dashed #000;padding-bottom:8px;margin-bottom:8px}
.struk-header h2{font-size:15px;font-weight:bold}
.struk-header p{font-size:11px}
.struk-row{display:flex;justify-content:space-between;font-size:12px}
.struk-row.bold{font-weight:bold}
.struk-item{margin:4px 0;font-size:11px}
.struk-divider{border:none;border-top:1px dashed #000;margin:6px 0}
.struk-total{border-top:2px solid #000;margin-top:6px;padding-top:6px}
.struk-footer{text-align:center;margin-top:10px;font-size:10px;border-top:1px dashed #000;padding-top:8px}

/* WA PREVIEW */
.wa-bubble{background:#DCF8C6;border-radius:12px 12px 4px 12px;padding:14px 16px;font-size:13px;line-height:1.7;white-space:pre-wrap;max-width:100%;word-break:break-word;margin-bottom:12px;box-shadow:0 1px 4px rgba(0,0,0,.12)}
.wa-bubble strong{font-weight:700}

@media print{
  body *{visibility:hidden}
  #strukCetakUlang,#strukCetakUlang *{visibility:visible}
  #strukCetakUlang{position:fixed;left:0;top:0;width:80mm}
}

/* PAYMENT MODAL */
.pay-opt{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px}
.pay-btn{padding:16px;border-radius:var(--r);border:2px solid rgba(27,45,90,.12);background:var(--off);cursor:pointer;text-align:center;transition:all .2s;font-family:var(--font)}
.pay-btn:hover{border-color:var(--teal)}
.pay-btn.selected{border-color:var(--teal);background:var(--teal-bg)}
.pay-btn .pay-icon{font-size:1.6rem;display:block;margin-bottom:6px}
.pay-btn .pay-label{font-size:13px;font-weight:700;color:var(--navy)}
.pay-btn .pay-sub{font-size:11px;color:var(--gray);margin-top:2px}
.bukti-preview{width:100%;max-height:160px;object-fit:cover;border-radius:var(--r);margin-top:8px;display:none}
.bukti-drop{border:2px dashed rgba(27,45,90,.18);border-radius:var(--r);padding:20px;text-align:center;cursor:pointer;transition:all .2s;background:var(--off)}
.bukti-drop:hover{border-color:var(--teal);background:var(--teal-bg)}
.bukti-drop p{font-size:13px;color:var(--gray);margin-top:6px}

@media(max-width:900px){
  .stats{grid-template-columns:repeat(3,minmax(0,1fr))}
}
@media(max-width:680px){
  .main{padding:12px 10px 80px}
  .stats{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
  .stat-num{font-size:clamp(0.75rem,3.2vw,1.2rem)}
  .stat-label{font-size:10px}
  .filter-bar input{min-width:0 !important;width:100%}
  .filter-bar{gap:8px}
  .card-header{padding:12px 14px;flex-wrap:wrap;gap:6px}
  .modal{width:100%;max-width:100%;border-radius:var(--r-lg) var(--r-lg) 0 0;height:92vh}
  .modal-overlay{align-items:flex-end;padding:0}
  /* Table utama orders stacked di HP (lihat .hl-stack-mobile di harpy-erp.css) */
  .table-wrap{overflow-x:visible}
  .table-wrap table.hl-stack-mobile{min-width:0}
  /* Fallback untuk table lain (mis. log table) yang masih butuh scroll horizontal */
  .table-wrap table:not(.hl-stack-mobile){min-width:760px;overflow-x:auto;-webkit-overflow-scrolling:touch}
  /* Bulk toolbar wrap di HP */
  #bulkToolbar{flex-direction:column;align-items:stretch!important;gap:8px}
  #bulkToolbar select,#bulkToolbar button{width:100%}
  /* Tabel edit item: stacked card per baris di HP (UX > scroll horizontal) */
  .items-table, .items-table thead, .items-table tbody,
  .items-table tr, .items-table th, .items-table td { display:block; width:100% }
  .items-table thead { display:none }
  .items-table tbody tr { border:1px solid rgba(27,45,90,.1); border-radius:10px; margin-bottom:10px; padding:8px 12px; background:#fff }
  .items-table tbody td { display:flex; justify-content:space-between; align-items:center; padding:5px 0; border:none; font-size:13px; gap:8px }
  .items-table tbody td::before { content:attr(data-lbl); font-size:11px; color:var(--gray); font-weight:600; text-transform:uppercase; letter-spacing:.05em; flex-shrink:0 }
  .items-table tbody td:empty::before { content:'' }
  .items-table tbody td input, .items-table tbody td select { text-align:right; flex:1; min-width:0; max-width:170px; width:auto }
  .items-table tbody td.item-sub { font-weight:700; color:var(--navy); font-family:var(--mono) }
  .items-table tbody td:last-child { justify-content:flex-end; padding-top:8px; border-top:1px dashed rgba(27,45,90,.08); margin-top:4px }
  .action-btns{flex-wrap:wrap;justify-content:flex-end;gap:6px}
  .pay-opt{grid-template-columns:1fr 1fr}
  /* Footer modal detail: grid 2 kolom rapi (bukan wrap acak). Simpan Perubahan full-width */
  #modalDetail .modal-footer{display:grid;grid-template-columns:1fr 1fr;gap:8px}
  #modalDetail .modal-footer .btn{width:100%;margin:0;justify-content:center;font-size:12.5px;padding:11px 8px;white-space:nowrap}
  #modalDetail .modal-footer #btnSaveEdit{grid-column:1/-1}
  /* Tombol aksi menyesuaikan lebar teks (jangan dipaksa flex:1 → teks luber) */
  .action-btns .btn{padding:8px 12px;font-size:12px;flex:0 0 auto;min-width:0;line-height:1;white-space:nowrap}
  /* Tombol ikon (cetak/WA) dibuat kotak rapi */
  .action-btns .btn-outline{min-width:40px;justify-content:center}
}
@media(max-width:400px){
  .main{padding:8px 8px 80px}
  .stats{grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}
  .stat-num{font-size:clamp(0.7rem,3vw,1.05rem)}
}
</style>
</head>
<body data-tour-page="orders">
<?php if (($_GET['embed'] ?? '') === 'detail'): ?>
<style>/* embed dari Kanban: sembunyikan chrome, tampilkan modal detail saja */
  .topbar, .ol-bottomnav, .pos-mobile-cta { display:none !important; }
  body, .main { background:transparent !important; }
  .main { display:none !important; }
</style>
<?php endif; ?>
<?php renderTopbar('orders'); ?>

<div class="main">

  <!-- STATS -->
  <div class="stats" id="statsRow">
    <div class="stat-card" onclick="filterByStatus('')" id="statAll">
      <div class="stat-num" id="sAll">-</div>
      <div class="stat-label">Semua Order</div>
    </div>
    <div class="stat-card" onclick="filterByStatus('masuk')" id="statMasuk">
      <div class="stat-num" id="sMasuk">-</div>
      <div class="stat-label">📥 Masuk</div>
    </div>
    <div class="stat-card" onclick="filterByStatus('cuci')" id="statCuci">
      <div class="stat-num" id="sCuci">-</div>
      <div class="stat-label">🫧 Cuci</div>
    </div>
    <div class="stat-card" onclick="filterByStatus('kering')" id="statKering">
      <div class="stat-num" id="sKering">-</div>
      <div class="stat-label">💨 Kering</div>
    </div>
    <div class="stat-card" onclick="filterByStatus('siap')" id="statSiap">
      <div class="stat-num" id="sSiap">-</div>
      <div class="stat-label">✅ Siap</div>
    </div>
    <div class="stat-card" onclick="filterByStatus('diambil')" id="statDiambil">
      <div class="stat-num" id="sDiambil">-</div>
      <div class="stat-label">📦 Diambil/Diantar</div>
    </div>
  </div>

  <!-- FILTER -->
  <div class="hl-filter-collapsible">
    <button class="hl-filter-toggle-btn" id="orderFilterBtn" data-tour="t_orders_filter" onclick="toggleFilter('orderFilter')">
      🔍 Filter &amp; Pencarian <span class="hl-filter-active-dot" id="orderFilterDot"></span>
      <span class="hl-toggle-arrow">▼</span>
    </button>
    <div class="hl-filter-bar" id="orderFilter">
      <input type="text" id="searchInput" class="hl-input" placeholder="Cari nama, no. order, telepon..."
        oninput="debounce()" style="flex:1;min-width:180px"/>
      <select id="filterStatus" class="hl-input" style="width:auto" onchange="loadOrders(1)">
        <option value="">Semua Status</option>
        <option value="masuk">Masuk</option>
        <option value="cuci">Proses Cuci</option>
        <option value="kering">Proses Kering</option>
        <option value="setrika">Setrika</option>
        <option value="siap">Siap Diambil</option>
        <option value="diambil">Sudah Diambil</option>
      </select>
      <select id="filterBayar" class="hl-input" style="width:auto" onchange="loadOrders(1)">
        <option value="">Semua Pembayaran</option>
        <option value="belum_bayar">Belum Bayar</option>
        <option value="dp">DP</option>
        <option value="lunas">Lunas</option>
      </select>
      <select id="filterSumber" class="hl-input" style="width:auto" onchange="loadOrders(1)" title="Sumber order">
        <option value="">Semua Sumber</option>
        <option value="walkin">🏪 Walk-in</option>
        <option value="drop">📦 Drop Point</option>
      </select>
      <label style="display:flex;flex-direction:column;gap:3px;font-size:10px;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:.04em">Dari tgl
        <input type="date" id="filterDari" onchange="loadOrders(1)" title="Dari tanggal"
          style="padding:9px 10px;border:1.5px solid rgba(27,45,90,.14);border-radius:var(--r);font-family:var(--font);font-size:13px;background:var(--white);outline:none"/>
      </label>
      <label style="display:flex;flex-direction:column;gap:3px;font-size:10px;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:.04em">Sampai tgl
        <input type="date" id="filterSampai" onchange="loadOrders(1)" title="Sampai tanggal"
          style="padding:9px 10px;border:1.5px solid rgba(27,45,90,.14);border-radius:var(--r);font-family:var(--font);font-size:13px;background:var(--white);outline:none"/>
      </label>
      <div style="display:flex;gap:8px;align-items:center;width:100%;margin-top:6px">
        <button class="btn btn-outline btn-sm" onclick="resetFilter()" title="Reset filter">✕ Reset</button>
        <button class="btn btn-teal-sm" onclick="loadOrders(1)" title="Muat ulang">↻</button>
        <?php if (hasPermission('pos.view')): ?>
        <a href="/pos" class="btn btn-teal-sm" style="margin-left:auto">+ Order Baru</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- TABLE -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">📋 Daftar Order Laundry</div>
      <span id="tableInfo" style="font-size:12px;color:var(--gray)"></span>
    </div>
    <!-- BULK TOOLBAR -->
    <div id="bulkToolbar" style="display:none;background:#0F1C3A;color:#fff;padding:10px 16px;align-items:center;gap:12px;flex-wrap:wrap">
      <span style="font-size:13px;font-weight:600"><span id="bulkCount">0</span> order dipilih</span>
      <select id="bulkStatus" style="padding:6px 10px;border-radius:7px;border:none;font-size:13px">
        <option value="">— Pilih status baru —</option>
        <option value="masuk">📥 Masuk</option>
        <option value="cuci">🫧 Cuci</option>
        <option value="kering">💨 Kering</option>
        <option value="setrika">👔 Setrika</option>
        <option value="siap">✅ Siap</option>
        <option value="diambil">📦 Diambil/Diantar</option>
      </select>
      <button class="btn btn-teal-sm btn-sm" onclick="applyBulkStatus()">✓ Terapkan</button>
      <span style="opacity:.5;font-size:11px">·</span>
      <?php if (hasPermission('orders.bayar')): ?>
      <button class="btn btn-sm" style="background:#10B981;color:#fff" onclick="applyBulkPay()" title="Tandai lunas (cash) untuk yang belum bayar / DP">💰 Bayar Lunas</button>
      <?php endif; ?>
      <button class="btn btn-sm" style="background:#3B82F6;color:#fff" onclick="applyBulkPrint()" title="Print struk untuk semua yang dipilih (popup berurutan)">🖨️ Print Struk</button>
      <button class="btn btn-sm" style="background:#22C55E;color:#fff" onclick="applyBulkWA()" title="Generate link WA untuk semua yang dipilih (status=siap, ada nomor)">💬 Kirim WA</button>
      <span style="flex:1"></span>
      <button class="btn btn-outline btn-sm" style="background:rgba(255,255,255,.1);color:#fff;border-color:rgba(255,255,255,.2)" onclick="clearBulkSelection()">✕ Batal</button>
    </div>
    <div class="table-wrap">
      <table class="hl-stack-mobile">
        <thead>
          <tr>
            <th style="width:34px;text-align:center"><input type="checkbox" id="bulkAll" onclick="toggleAllBulk(this)" title="Pilih semua di halaman ini"/></th>
            <th class="th-sort" onclick="setSort('no_order')" id="th_no_order">No. Order <span class="sort-icon">↕</span></th>
            <th class="th-sort" onclick="setSort('tanggal')" id="th_tanggal">Tanggal <span class="sort-icon">↕</span></th>
            <th class="th-sort" onclick="setSort('nama_pelanggan')" id="th_nama_pelanggan">Pelanggan <span class="sort-icon">↕</span></th>
            <th>Layanan</th>
            <th>Status Proses</th>
            <th>Status Bayar</th>
            <th class="th-sort" onclick="setSort('total')" id="th_total" style="text-align:right">Total <span class="sort-icon">↕</span></th>
            <th style="text-align:right">Sisa</th>
            <th class="th-sort" onclick="setSort('estimasi_selesai')" id="th_estimasi_selesai">Est. Selesai <span class="sort-icon">↕</span></th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody id="tableBody" data-tour="t_orders_table">
          <tr><td colspan="11"><div class="loading">⏳ Memuat data...</div></td></tr>
        </tbody>
      </table>
    </div>
    <div id="ordersPaging" style="padding:12px 16px;border-top:1px solid var(--light)"></div>
  </div>

</div>

<!-- MODAL PEMBAYARAN -->
<div class="modal-overlay" id="modalBayar" style="align-items:center;justify-content:center;padding:20px;z-index:300">
  <div class="modal" style="height:auto;max-height:90vh;width:480px">
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

<div class="modal-overlay" id="modalDetail">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title" id="modalTitle">Detail Order</span>
      <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-body" id="modalBody">
      <div class="loading">⏳ Memuat...</div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline btn-sm" onclick="closeModal()">Tutup</button>
      <?php if (hasPermission('orders.bayar')): ?>
      <button class="btn btn-teal-sm btn-sm" id="btnBayarDariDetail" onclick="openBayarFromDetail()">💰 Update Bayar</button>
      <?php endif; ?>
      <?php if (hasPermission('orders.edit')): ?>
      <button class="btn btn-primary btn-sm" id="btnSaveEdit" onclick="saveEdit()">💾 Simpan Perubahan</button>
      <?php endif; ?>
      <button class="btn btn-sm" style="background:#25D366;color:#fff;border:none" onclick="shareToWA()" title="Kirim link tracking ke customer via WhatsApp">💬 Kirim Status WA</button>
      <button class="btn btn-sm btn-outline" onclick="printLabel()" title="Cetak label stiker (ukuran diatur di Outlet Settings)">🏷 Print Label</button>
      <?php if (hasPermission('orders.edit') || hasPermission('orders.delete')): ?>
      <button id="btnMintaHapus" class="btn btn-sm" style="background:#FEE2E2;color:#991B1B;border:1px solid #FCA5A5" onclick="requestDelete()" title="Submit permintaan hapus untuk persetujuan owner">🗑️ Minta Hapus</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- MODAL CETAK ULANG NOTA -->
<div class="modal-overlay" id="modalCetak" style="align-items:center;justify-content:center;padding:20px;z-index:300">
  <div class="modal" style="height:auto;max-height:90vh;width:480px">
    <div class="modal-header">
      <span class="modal-title">🖨️ Cetak Ulang Nota</span>
      <button class="modal-close" onclick="closeCetakModal()">✕</button>
    </div>
    <div class="modal-body" style="padding:8px;background:#f4f6fb;min-height:280px;display:flex;align-items:center;justify-content:center">
      <iframe id="cetakFrame"
              style="border:none;background:#fff;width:100%;min-height:380px;border-radius:6px;box-shadow:0 2px 12px rgba(0,0,0,.1)"
              title="Cetak Ulang Nota"></iframe>
    </div>
    <div class="modal-footer" style="gap:6px">
      <button class="btn btn-outline btn-sm" onclick="closeCetakModal()">Tutup</button>
      <button class="btn btn-primary btn-sm" onclick="doPrint()">🖨️ Print</button>
      <a id="openCetakBtn" href="#" target="_blank" class="btn btn-outline btn-sm">↗ Buka Penuh</a>
    </div>
  </div>
</div>

<!-- MODAL WA REMINDER -->
<div class="modal-overlay" id="modalWA" style="align-items:center;justify-content:center;padding:20px;z-index:300">
  <div class="modal" style="height:auto;max-height:90vh;width:480px">
    <div class="modal-header">
      <span class="modal-title">📱 Kirim WhatsApp</span>
      <button class="modal-close" onclick="closeWAModal()">✕</button>
    </div>
    <div class="modal-body">
      <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
        <button class="btn btn-teal-sm btn-sm wa-type-btn active" onclick="selectWAType('reminder',this)">🔄 Update Status</button>
        <button class="btn btn-outline btn-sm wa-type-btn" onclick="selectWAType('siap',this)">✅ Siap Diambil</button>
        <button class="btn btn-outline btn-sm wa-type-btn" onclick="selectWAType('lunas_reminder',this)">💰 Tagihan</button>
      </div>
      <p style="font-size:12px;color:var(--gray);margin-bottom:10px">👁️ Preview pesan:</p>
      <div class="wa-bubble" id="waBubble">Memuat...</div>
      <div style="font-size:12px;color:var(--gray)">
        📱 Nomor: <strong id="waPhone">-</strong>
        &nbsp;·&nbsp;
        <a id="waTrackLink" href="#" target="_blank" style="color:var(--teal);font-size:12px">🔗 Link Tracking</a>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline btn-sm" onclick="closeWAModal()">Tutup</button>
      <button class="btn btn-primary btn-sm" onclick="kirimWA()">📲 Buka WhatsApp</button>
    </div>
  </div>
</div>

<?php
// Tenant + outlet info untuk template struk (dinamis per tenant)
$_tenantInfo  = TenantResolver::getTenant();
$_outletInfo  = TenantResolver::getOutlet();
$_brandName   = $_tenantInfo['nama_perusahaan'] ?: ($_outletInfo['nama_outlet'] ?? 'Laundry');
$_outletNama  = $_outletInfo['nama_outlet'] ?? $_brandName;
$_outletAddr  = trim(($_outletInfo['alamat'] ?? '') . ($_outletInfo['kota'] ? ', ' . $_outletInfo['kota'] : ''));
$_outletTelp  = $_outletInfo['telepon'] ?? '';
?>
<script>
let searchTimer = null;
let currentEditId = null;
let currentOrderBiayaTambahan = 0;
let currentOrderBiayaLainnya = 0;
let currentOrderData = null;
let editItems = [];
let editSnapshot = null;   // snapshot state form saat modal dibuka → deteksi "tidak ada perubahan"

// Serialisasi state editable saat ini (dibandingkan utk cek ada/tidaknya perubahan)
function editStateJSON() {
  const g = id => { const el = document.getElementById(id); return el ? el.value : ''; };
  return JSON.stringify({
    s:  g('edit_status_proses'), c: g('edit_catatan'), ci: g('edit_catatan_internal'),
    m:  g('edit_metode'), d: g('edit_diskon'), dp: g('edit_dp'), e: g('edit_estimasi'),
    fp: (document.getElementById('edit_foto_pickup_path')?.value || ''),
    items: (editItems || []).map(it => ({ l: it.nama_layanan, s: it.satuan, j: it.jumlah, h: it.harga_satuan, k: it.catatan_item || '' }))
  });
}
let layananAll = [];
const BRAND_NAME   = <?= json_encode($_brandName) ?>;
const OUTLET_NAMA  = <?= json_encode($_outletNama) ?>;
const OUTLET_ADDR  = <?= json_encode($_outletAddr) ?>;
const OUTLET_TELP  = <?= json_encode($_outletTelp) ?>;
const CAN_BAYAR      = <?= hasPermission('orders.bayar')         ? 'true' : 'false' ?>;
const CAN_EDIT_ORDER = <?= hasPermission('orders.edit')           ? 'true' : 'false' ?>;
const CAN_DEL_ORDER  = <?= hasPermission('orders.delete')         ? 'true' : 'false' ?>;
const PAY_METHODS  = <?= json_encode($activeMethods, JSON_UNESCAPED_UNICODE) ?>;
const PAPER_WIDTH_PX = <?= (int)$paperWidthPx ?>; // lebar dot printer thermal outlet (384=58mm, 576=80mm)
const PM_LABEL     = Object.fromEntries(PAY_METHODS.map(m => [m.code, ((m.emoji||'') + ' ' + m.label).trim()]));

document.addEventListener('DOMContentLoaded', async () => {
  initFilter('orderFilter');
  loadSummary();
  loadOrders();
  await loadLayanan();
  // Auto-buka detail bila datang dari Kanban (/orders?open=<id>)
  const openId = new URLSearchParams(location.search).get('open');
  if (openId && /^\d+$/.test(openId)) openDetail(parseInt(openId, 10));
});

// ── LOAD ──────────────────────────────────────────────
async function loadSummary() {
  const r = await fetch('orders.php?action=summary');
  const d = await r.json();
  const total = Object.values(d.statuses).reduce((a,b) => a + +b, 0);
  document.getElementById('sAll').textContent     = total;
  document.getElementById('sMasuk').textContent   = d.statuses.masuk   || 0;
  document.getElementById('sCuci').textContent    = d.statuses.cuci    || 0;
  document.getElementById('sKering').textContent  = d.statuses.kering  || 0;
  document.getElementById('sSiap').textContent    = d.statuses.siap    || 0;
  document.getElementById('sDiambil').textContent = d.statuses.diambil || 0;
}

async function loadLayanan() {
  const r = await fetch('orders.php?action=get_layanan');
  layananAll = await r.json();
}

// ── BULK SELECTION ────────────────────────────────────
function onBulkCbChange() {
  const sel = document.querySelectorAll('.bulkCb:checked').length;
  document.getElementById('bulkCount').textContent = sel;
  document.getElementById('bulkToolbar').style.display = sel > 0 ? 'flex' : 'none';
  // Sync header checkbox tri-state
  const total = document.querySelectorAll('.bulkCb').length;
  const all = document.getElementById('bulkAll');
  if (all) {
    all.checked = (sel > 0 && sel === total);
    all.indeterminate = (sel > 0 && sel < total);
  }
}
function toggleAllBulk(cb) {
  document.querySelectorAll('.bulkCb').forEach(x => x.checked = cb.checked);
  onBulkCbChange();
}
function clearBulkSelection() {
  document.querySelectorAll('.bulkCb').forEach(x => x.checked = false);
  const all = document.getElementById('bulkAll'); if (all) { all.checked=false; all.indeterminate=false; }
  onBulkCbChange();
}
async function applyBulkStatus() {
  const status = document.getElementById('bulkStatus').value;
  if (!status) { showToast('Pilih status dulu','error'); return; }
  const ids = getBulkIds();
  if (!ids.length) { showToast('Tidak ada order dipilih','error'); return; }
  if (!(await lmConfirm('Update status ' + ids.length + ' order menjadi "' + status + '"?'))) return;
  try {
    const r = await fetch('orders.php?action=bulk_status', {
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify({ids, status})
    });
    const d = await r.json();
    if (d.error) { showToast(d.error,'error'); return; }
    showToast('✓ ' + (d.affected||ids.length) + ' order diupdate');
    clearBulkSelection();
    loadOrders(ordersCurrentPage);
  } catch (e) { showToast('Network error','error'); }
}

function getBulkIds() {
  return Array.from(document.querySelectorAll('.bulkCb:checked')).map(x => parseInt(x.value));
}

async function applyBulkPay() {
  const ids = getBulkIds();
  if (!ids.length) { showToast('Tidak ada order dipilih','error'); return; }
  const pmCodes = PAY_METHODS.map(m => m.code);
  let metode = await lmPrompt('Metode bayar (' + pmCodes.join('/') + '):', pmCodes[0] || 'cash');
  if (!metode) return;
  metode = metode.trim().toLowerCase();
  if (!pmCodes.includes(metode)) { showToast('Metode tidak dikenal: ' + metode, 'error'); return; }
  if (!(await lmConfirm('Tandai LUNAS ' + ids.length + ' order dengan metode "' + (PM_LABEL[metode] || metode) + '"?\n(Sudah lunas akan di-skip.)'))) return;
  try {
    const r = await fetch('orders.php?action=bulk_pay', {
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify({ids, metode})
    });
    const d = await r.json();
    if (d.error) { showToast(d.error,'error'); return; }
    const msg = (d.affected||0) + ' order dilunasi';
    const totalMsg = d.total_in ? ' · kas masuk Rp ' + Number(d.total_in).toLocaleString('id-ID') : '';
    showToast('✓ ' + msg + totalMsg);
    if ((d.affected||0) === 0 && d.msg) showToast(d.msg, 'info');
    clearBulkSelection();
    loadOrders(ordersCurrentPage);
  } catch (e) { showToast('Network error','error'); }
}

async function applyBulkPrint() {
  const ids = getBulkIds();
  if (!ids.length) { showToast('Tidak ada order dipilih','error'); return; }
  // APK + printer thermal → cetak beruntun via iframe tersembunyi.
  // (Jalur lama window.open diblok WebView → tombol tampak mati total.)
  if (window.ThermalPrint && ThermalPrint.isAvailable()) {
    const pr = ThermalPrint.getPrinter();
    if (!pr || !pr.address) { showToast('Belum ada printer dipilih — pilih dulu lewat POS → struk → ⚙️ Printer', 'error'); return; }
    if (ids.length > 1 && !(await lmConfirm('Cetak ' + ids.length + ' struk ke printer thermal?'))) return;
    let f = document.getElementById('bulkPrintFrame');
    if (!f) { f = document.createElement('iframe'); f.id = 'bulkPrintFrame'; f.style.cssText = 'position:fixed;left:-9999px;top:0;width:420px;height:800px;border:0'; document.body.appendChild(f); }
    let ok = 0;
    for (let i = 0; i < ids.length; i++) {
      showToast('🖨 Mencetak struk ' + (i + 1) + '/' + ids.length + '…', 'info');
      try {
        await new Promise((res) => { f.onload = res; setTimeout(res, 6000); f.src = '/api/struk.php?action=generate&id=' + ids[i] + '&tipe=retail&_=' + Date.now(); });
        const node = f.contentDocument && (f.contentDocument.querySelector('.struk') || f.contentDocument.body);
        if (!node || !node.innerHTML.trim()) throw new Error('struk tidak termuat');
        await ThermalPrint.print(node, PAPER_WIDTH_PX);
        ok++;
      } catch (e) { showToast('❌ Struk ke-' + (i + 1) + ': ' + (e.message || 'gagal'), 'error'); }
    }
    showToast('✅ ' + ok + '/' + ids.length + ' struk tercetak', ok ? 'success' : 'error');
    clearBulkSelection();
    return;
  }
  if (ids.length > 20 && !(await lmConfirm('Print struk untuk ' + ids.length + ' order? Akan buka tab baru per order, browser bisa block popup.'))) return;
  let opened = 0, blocked = 0;
  ids.forEach((id, i) => {
    setTimeout(() => {
      const w = window.open('/api/struk.php?action=generate&id=' + id + '&tipe=retail&auto_print=1', '_blank');
      if (w) opened++; else blocked++;
      if (i === ids.length - 1) {
        showToast(opened + ' struk dibuka' + (blocked > 0 ? ' · ' + blocked + ' di-block popup' : ''));
      }
    }, i * 200); // stagger 200ms untuk hindari browser popup block
  });
}

async function applyBulkWA() {
  const ids = getBulkIds();
  if (!ids.length) { showToast('Tidak ada order dipilih','error'); return; }
  try {
    const r = await fetch('orders.php?action=bulk_wa&ids=' + ids.join(','));
    const d = await r.json();
    if (d.error) { showToast(d.error,'error'); return; }
    if (!d.links?.length) {
      showToast('Tidak ada order dengan nomor HP valid','error');
      return;
    }
    // Tampilkan modal list link WA — klik per order biar gak ke-block popup
    const html = '<div style="padding:12px">'
      + '<h3 style="margin:0 0 10px">💬 Kirim WA — ' + d.links.length + ' order</h3>'
      + (d.skipped_no_phone > 0 ? '<p style="color:#92400E;font-size:12px;background:#FEF3C7;padding:6px 10px;border-radius:6px;margin:0 0 10px">⚠️ ' + d.skipped_no_phone + ' order di-skip karena tidak ada nomor HP</p>' : '')
      + '<p style="font-size:12px;color:#6B7280;margin:0 0 10px">Klik tombol untuk buka chat WA. Auto-fill pesan template.</p>'
      + '<div style="max-height:60vh;overflow-y:auto;display:grid;gap:6px">'
      + d.links.map((l, i) =>
          '<a href="' + l.url + '" target="_blank" rel="noopener" '
          + 'style="display:flex;justify-content:space-between;align-items:center;padding:10px 12px;background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;text-decoration:none;color:#0F1C3A">'
          + '<span><strong>' + l.nama + '</strong> <small style="color:#6B7280">' + l.no_order + '</small></span>'
          + '<span style="background:#22C55E;color:#fff;padding:4px 10px;border-radius:6px;font-size:12px;font-weight:600">💬 Buka WA</span>'
          + '</a>'
        ).join('')
      + '</div>'
      + '<button onclick="document.getElementById(\'bulkWaModal\').remove()" class="hl-btn hl-btn-outline" style="margin-top:14px;width:100%">Tutup</button>'
      + '</div>';
    const modal = document.createElement('div');
    modal.id = 'bulkWaModal';
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(15,28,58,.6);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px';
    modal.innerHTML = '<div style="background:#fff;border-radius:12px;max-width:480px;width:100%;max-height:90vh;overflow:auto">' + html + '</div>';
    modal.onclick = (e) => { if (e.target === modal) modal.remove(); };
    document.body.appendChild(modal);
  } catch (e) { showToast('Network error','error'); }
}

let ordersCurrentPage = 1;
let ordersTotalPages  = 1;
let ordersSort        = 'tanggal';
let ordersSortDir     = 'desc';

function setSort(col) {
  if (ordersSort === col) {
    ordersSortDir = ordersSortDir === 'asc' ? 'desc' : 'asc';
  } else {
    ordersSort    = col;
    ordersSortDir = col === 'tanggal' ? 'desc' : 'asc';
  }
  document.querySelectorAll('.th-sort').forEach(th => th.classList.remove('asc','desc'));
  const th = document.getElementById('th_' + col);
  if (th) th.classList.add(ordersSortDir);
  loadOrders(1);
}

async function loadOrders(page=1) {
  ordersCurrentPage = page;
  const q      = document.getElementById('searchInput').value;
  const st     = document.getElementById('filterStatus').value;
  const by     = document.getElementById('filterBayar').value;
  const dari   = document.getElementById('filterDari').value;
  const sampai = document.getElementById('filterSampai').value;
  const sumber = document.getElementById('filterSumber')?.value || '';

  // Skeleton: 6 row table skeleton
  document.getElementById('tableBody').innerHTML = Array.from({length:6}).map(()=>`
    <tr><td colspan="11" style="padding:0;border-bottom:1px solid var(--light)">
      <div class="hl-skel-row" style="padding:14px 12px">
        <span class="hl-skel" style="width:90px"></span>
        <span class="hl-skel" style="width:70px"></span>
        <span class="hl-skel" style="width:140px"></span>
        <span class="hl-skel" style="width:120px;display:none" class="hide-sm"></span>
        <span class="hl-skel" style="width:70px;margin-left:auto"></span>
      </div>
    </td></tr>`).join('');

  const r = await fetch(`orders.php?action=list&q=${encodeURIComponent(q)}&status=${st}&bayar=${by}&dari=${dari}&sampai=${sampai}&sumber=${sumber}&page=${page}&sort=${ordersSort}&dir=${ordersSortDir}`);
  const d = await r.json();

  if (!d.data?.length) {
    document.getElementById('tableBody').innerHTML = `<tr><td colspan="11" style="padding:0">
      <div class="hl-empty-v2" style="margin:14px;background:transparent;border:0">
        <div class="e-icon">📭</div>
        <div class="e-title">Tidak ada order</div>
        <div class="e-sub">Coba ubah filter atau tanggal pencarian</div>
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="resetFilter()">↻ Reset Filter</button>
      </div></td></tr>`;
    document.getElementById('tableInfo').textContent = '';
    document.getElementById('ordersPaging').innerHTML = '';
    return;
  }

  document.getElementById('tableBody').innerHTML = d.data.map(row => {
    const sisaColor = parseFloat(row.sisa_bayar) > 0 ? 'var(--red)' : 'var(--green)';
    const sisaText  = parseFloat(row.sisa_bayar) > 0 ? 'Rp ' + parseFloat(row.sisa_bayar).toLocaleString('id-ID') : '&#10003;';
    const telp      = row.telepon ? '<div style="font-size:11px;color:var(--gray)">' + row.telepon + '</div>' : '';
    const est       = row.estimasi_selesai ? fmtDate(row.estimasi_selesai) : '-';
    const bayarBtn  = CAN_BAYAR && parseFloat(row.sisa_bayar) > 0
      ? `<button class="btn btn-teal-sm" onclick="openBayarById(${row.id})">&#128176; Bayar</button>`
      : (parseFloat(row.sisa_bayar) > 0 ? '' : '<span style="font-size:11px;color:var(--green);padding:4px">&#10003; Lunas</span>');

    return '<tr onclick="openDetail(' + row.id + ')">'
      + '<td onclick="event.stopPropagation()" style="text-align:center">'
      +   '<input type="checkbox" class="bulkCb" value="' + row.id + '" onclick="onBulkCbChange()"/>'
      + '</td>'
      + '<td data-lbl="No Order"><span class="td-no">' + row.no_order + '</span></td>'
      + '<td data-lbl="Tanggal">' + fmtDate(row.tanggal) + '</td>'
      + '<td data-lbl="Pelanggan"><div class="td-nama">' + esc(row.nama_pelanggan)
      +   (row.nama_mitra ? ' <span style="font-size:9px;font-weight:700;background:#FEF3C7;color:#92400E;padding:2px 7px;border-radius:100px;margin-left:4px">📦 ' + esc(row.nama_mitra) + '</span>' : '')
      +   '</div>' + telp + '</td>'
      + '<td data-lbl="Layanan"><div class="td-layanan">' + esc(row.layanan_list||'-') + '</div></td>'
      + '<td data-lbl="Status"><span class="badge b-' + row.status_proses + '">' + statusLabel(row.status_proses) + '</span><div class="proses-bar" title="' + prosesPercent(row.status_proses) + '% — ' + statusLabel(row.status_proses) + '"><div class="proses-fill" style="width:' + prosesPercent(row.status_proses) + '%"></div></div></td>'
      + '<td data-lbl="Bayar"><span class="badge b-' + row.status_bayar + '">' + bayarLabel(row.status_bayar) + '</span></td>'
      + '<td data-lbl="Total" class="td-total">Rp ' + parseFloat(row.total).toLocaleString('id-ID') + '</td>'
      + '<td data-lbl="Sisa" style="font-family:var(--mono);font-size:12px;text-align:right;color:' + sisaColor + '">' + sisaText + '</td>'
      + '<td data-lbl="Estimasi" style="font-size:12px;color:var(--gray)">' + est + '</td>'
      + '<td onclick="event.stopPropagation()">'
      + '<div class="action-btns">'
      + bayarBtn
      + '<button class="btn btn-outline" onclick="cetakUlang(' + row.id + ')" title="Cetak Ulang">&#128424;&#65039;</button>'
      + '<button class="btn btn-outline" onclick="openWAModal(' + row.id + ')" title="Kirim WA">&#128241;</button>'
      + '</div></td>'
      + '</tr>';
  }).join('');

  ordersTotalPages = d.total_pages;
  document.getElementById('tableInfo').textContent = `${d.total} order · halaman ${page} dari ${d.total_pages}`;
  // Reset bulk selection on reload
  const btb = document.getElementById('bulkToolbar'); if (btb) btb.style.display = 'none';
  const ball = document.getElementById('bulkAll'); if (ball) { ball.checked=false; ball.indeterminate=false; }
  renderOrdersPaging(d.page, d.total_pages);
}

function renderOrdersPaging(page, total) {
  const el = document.getElementById('ordersPaging');
  if (!el || total <= 1) { if(el) el.innerHTML=''; return; }

  let html = '<div style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:16px;flex-wrap:wrap">';
  html += `<button class="btn btn-outline btn-sm" onclick="loadOrders(${page-1})" ${page===1?'disabled':''}>← Prev</button>`;

  const start = Math.max(1, page-2);
  const end   = Math.min(total, page+2);
  if (start > 1) html += `<button class="btn btn-outline btn-sm" onclick="loadOrders(1)">1</button>`;
  if (start > 2) html += `<span style="color:var(--gray);padding:0 4px">...</span>`;
  for (let i=start; i<=end; i++) {
    html += `<button class="btn ${i===page?'btn-teal-sm':'btn-outline btn-sm'}" onclick="loadOrders(${i})">${i}</button>`;
  }
  if (end < total-1) html += `<span style="color:var(--gray);padding:0 4px">...</span>`;
  if (end < total)   html += `<button class="btn btn-outline btn-sm" onclick="loadOrders(${total})">${total}</button>`;
  html += `<button class="btn btn-outline btn-sm" onclick="loadOrders(${page+1})" ${page===total?'disabled':''}>Next →</button>`;
  html += '</div>';
  el.innerHTML = html;
}

function resetFilter() {
  document.getElementById('filterDari').value   = '';
  document.getElementById('filterSampai').value = '';
  document.getElementById('filterStatus').value = '';
  document.getElementById('filterBayar').value  = '';
  const fs = document.getElementById('filterSumber'); if (fs) fs.value = '';
  document.getElementById('searchInput').value  = '';
  loadOrders(1);
}

// ── DETAIL / EDIT MODAL ───────────────────────────────
function renderBiayaLainnyaBreakdown(rows) {
  const el = document.getElementById('viewBiayaLainnya');
  if (!el) return;
  if (!rows.length) { el.textContent = '-'; return; }
  el.innerHTML = rows.map(r => `${esc(r.nama)}: Rp ${grpRibu(r.nominal)}`).join('<br>');
}

async function openDetail(id) {
  currentEditId = id;
  document.getElementById('modalBody').innerHTML = '<div class="loading">⏳ Memuat...</div>';
  document.getElementById('modalDetail').classList.add('open');

  const r = await fetch('orders.php?action=get&id=' + id);
  const d = await r.json();
  if (d.error) { document.getElementById('modalBody').innerHTML = '<div class="empty">❌ ' + d.error + '</div>'; return; }

  currentOrderBiayaTambahan = parseFloat(d.biaya_tambahan) || 0;
  currentOrderBiayaLainnya  = parseFloat(d.biaya_lainnya) || 0;
  editItems = d.items || [];
  currentOrderData = d;
  document.getElementById('modalTitle').textContent = '📋 ' + d.no_order;

  const statuses = [
    ['masuk','📥 Masuk'],['cuci','🫧 Cuci'],['kering','💨 Kering'],
    ['setrika','👔 Setrika'],['siap','✅ Siap'],['diambil','📦 Diambil/Diantar']
  ];

  document.getElementById('modalBody').innerHTML = `
    <div style="background:var(--off);border-radius:var(--r);padding:12px 14px;margin-bottom:16px;font-size:13px">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
        <div><span style="color:var(--gray)">Pelanggan: </span><strong>${esc(d.nama_pelanggan)}</strong></div>
        <div><span style="color:var(--gray)">Telepon: </span>${d.telepon||'-'}</div>
        <div><span style="color:var(--gray)">Tanggal: </span>${fmtDate(d.tanggal)}</div>
        <div><span style="color:var(--gray)">Dibuat oleh: </span>${d.created_by||'-'}</div>
      </div>
    </div>

    <div class="section-title">🔄 Status Proses</div>
    <div class="proses-steps" id="prosesSteps">
      ${statuses.map(([v,l]) => `<button class="step-btn ${d.status_proses===v?'active':''}" onclick="setProses('${v}',this)">${l}</button>`).join('')}
    </div>
    <input type="hidden" id="edit_status_proses" value="${d.status_proses}"/>

    <div class="section-title">🧺 Layanan</div>
    <div style="overflow-x:auto;margin-bottom:8px">
      <table class="items-table">
        <thead><tr>
          <th>Layanan</th><th>Sat</th><th>Jml</th><th>Harga</th><th>Subtotal</th><th>Ket</th><th></th>
        </tr></thead>
        <tbody id="editItemsBody"></tbody>
      </table>
    </div>
    ${CAN_EDIT_ORDER ? `<div style="margin-bottom:12px">
      <div style="display:flex;gap:6px;margin-bottom:6px">
        <input type="text" placeholder="🔍 Cari & tambah layanan..." oninput="filterEditLayanan(this.value)" id="editLayananSearch"
          style="flex:1;font-size:13px;padding:7px 10px"/>
        <button type="button" class="btn btn-teal-sm btn-sm" onclick="openLayananQuick()" style="white-space:nowrap" title="Tambah layanan baru cepat">+ Layanan</button>
      </div>
      <div id="editLayananGrid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:5px;max-height:150px;overflow-y:auto"></div>
    </div>` : ''}

    <div class="total-box" id="editTotalBox">
      <div class="tb-row"><span class="tb-label">Subtotal</span><span class="tb-value" id="etSubtotal">-</span></div>
      <div class="tb-row"><span class="tb-label">Diskon</span><span class="tb-value">- Rp ${CAN_EDIT_ORDER ? `<input type="number" id="edit_diskon" value="${Math.round(d.diskon||0)}" min="0" step="500" oninput="recalcEdit()" style="width:80px;background:transparent;border:none;border-bottom:1px solid rgba(255,255,255,.3);color:white;font-family:var(--mono);font-size:13px;padding:0;outline:none"/>` : `<span id="edit_diskon" style="font-family:var(--mono);color:white">${grpRibu(d.diskon||0)}</span>`}</span></div>
      <div class="tb-row"><span class="tb-label">Biaya Lainnya</span><span class="tb-value" id="viewBiayaLainnya">${d.biaya_lainnya > 0 ? 'Rp ' + grpRibu(d.biaya_lainnya) : '-'}</span></div>
      <div class="tb-row tb-total"><span style="color:white;font-weight:700">TOTAL</span><span class="tb-value tb-big" id="etTotal">-</span></div>
      <div class="tb-row"><span class="tb-label">DP/Bayar</span><span class="tb-value">Rp ${CAN_EDIT_ORDER ? `<input class="lm-rp" type="number" id="edit_dp" value="${Math.round(d.dp||0)}" min="0" step="1000" oninput="recalcEdit()" style="width:90px;background:transparent;border:none;border-bottom:1px solid rgba(255,255,255,.3);color:white;font-family:var(--mono);font-size:13px;padding:0;outline:none"/>` : `<span id="edit_dp" style="font-family:var(--mono);color:white">${grpRibu(d.dp||0)}</span>`}</span></div>
      <div class="tb-row"><span class="tb-label">Sisa Bayar</span><span class="tb-value tb-sisa" id="etSisa">-</span></div>
    </div>

    ${CAN_EDIT_ORDER ? `
    <div class="form-row" style="margin-bottom:12px">
      <div class="form-group">
        <label>Metode Bayar</label>
        <select id="edit_metode">
          ${!d.metode_bayar ? '<option value="" selected>— Pilih metode —</option>' : ''}
          ${PAY_METHODS.map(m=>`<option value="${esc(m.code)}" ${d.metode_bayar===m.code?'selected':''}>${esc(((m.emoji||'')+' '+m.label).trim())}</option>`).join('')}
          ${d.metode_bayar && !PAY_METHODS.some(m=>m.code===d.metode_bayar) ? `<option value="${esc(d.metode_bayar)}" selected>${esc(d.metode_bayar.toUpperCase())}</option>` : ''}
        </select>
      </div>
      <div class="form-group">
        <label>Estimasi Selesai</label>
        <input type="date" id="edit_estimasi" value="${(d.estimasi_selesai||'').slice(0,10)}"/>
      </div>
    </div>
    <div class="section-title">📸 Dokumentasi Pickup <span style="font-size:11px;font-weight:500;color:var(--gray)">— foto bukti saat pelanggan ambil</span></div>
    <div id="fotoPickupBox" style="margin-bottom:12px;${d.status_proses!=='diambil'?'display:none;':''}">
      ${d.foto_pickup ? `
        <div style="margin-bottom:8px"><img src="/${esc(d.foto_pickup)}" alt="Foto Pickup" style="max-width:200px;border-radius:8px;border:1px solid rgba(27,45,90,.1)"/></div>
      ` : ''}
      <input type="file" id="edit_foto_pickup_file" accept="image/*" onchange="uploadFotoPickup(this)" style="font-size:12px"/>
      <input type="hidden" id="edit_foto_pickup_path" value="${d.foto_pickup||''}"/>
      <div id="fotoPickupStatus" style="font-size:11px;color:var(--gray);margin-top:4px"></div>
    </div>

    <div class="section-title">📝 Catatan</div>
    <div class="form-group">
      <label>Catatan untuk Pelanggan</label>
      <textarea id="edit_catatan">${esc(d.catatan||'')}</textarea>
    </div>
    <div class="form-group">
      <label>Catatan Internal <span style="font-weight:500;color:var(--gray);text-transform:none;letter-spacing:0">— 🏷 ikut tercetak di label cucian</span></label>
      <textarea id="edit_catatan_internal" placeholder="Catatan hanya untuk tim...">${esc(d.catatan_internal||'')}</textarea>
    </div>` : `
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px;font-size:13px">
      <div><span style="color:var(--gray)">Metode Bayar: </span><strong>${PM_LABEL[d.metode_bayar] || (d.metode_bayar ? esc(d.metode_bayar.toUpperCase()) : '-')}</strong></div>
      <div><span style="color:var(--gray)">Est. Selesai: </span><strong>${d.estimasi_selesai ? fmtDate(d.estimasi_selesai) : '-'}</strong></div>
    </div>
    ${d.catatan ? `<div style="font-size:13px;margin-bottom:8px"><span style="color:var(--gray)">Catatan: </span>${esc(d.catatan)}</div>` : ''}
    `}

    <div class="section-title">🗒️ Catatan Tim <span style="font-size:11px;font-weight:500;color:var(--gray)">— riwayat antar-shift, tercatat siapa & kapan (tidak ikut tercetak)</span></div>
    <div id="notesList" style="margin-bottom:8px;max-height:200px;overflow-y:auto;border:1px solid rgba(27,45,90,.1);border-radius:8px;padding:8px;background:var(--off)">
      <div style="color:var(--gray);font-size:13px">⏳ Memuat catatan...</div>
    </div>
    <div style="display:flex;gap:6px;margin-bottom:16px">
      <input type="text" id="noteInput" placeholder="Tulis catatan tim (akan tercatat siapa & kapan)..." style="flex:1;font-size:13px;padding:8px 10px;border:1px solid rgba(27,45,90,.15);border-radius:7px"/>
      <button class="btn btn-teal-sm btn-sm" onclick="addNote()" style="padding:8px 14px">+ Tambah</button>
    </div>

    <div class="section-title">📜 Riwayat Status</div>
    <div id="logList">
      ${(d.logs||[]).length ? (d.logs||[]).map(l => {
        const icons = {proses:'🔄',bayar:'💰',items:'🧺',catatan:'📝',bukti:'📎'};
        const icon = icons[l.tipe] || '📌';
        return `<div class="log-item">
          <span class="log-time">${fmtDateTime(l.created_at)}</span>
          <span class="log-text">${icon} ${esc(l.catatan||'')} <span style="color:var(--gray);font-size:11px">· ${esc(l.oleh||'-')}</span></span>
        </div>`;
      }).join('') : '<div style="color:var(--gray);font-size:13px;padding:8px 0">Belum ada riwayat perubahan</div>'}
    </div>`;

  renderBiayaLainnyaBreakdown(d.biaya_lainnya_breakdown || []);
  renderEditItems();
  renderEditLayananGrid(layananAll);
  recalcEdit();
  loadNotes(id);
  editSnapshot = editStateJSON();   // rekam state awal utk deteksi perubahan

  // Reset tombol footer (statis & dipakai ulang antar modal) — jangan bawa state disabled dari order pending sebelumnya
  ['btnSaveEdit', 'btnBayarDariDetail', 'btnMintaHapus'].forEach(bid => {
    const b = document.getElementById(bid);
    if (b) { b.disabled = false; b.style.opacity = ''; b.style.pointerEvents = ''; }
  });
  const bmhReset = document.getElementById('btnMintaHapus');
  if (bmhReset) bmhReset.textContent = '🗑️ Minta Hapus';

  // Banner konteks: order pernah lunas lalu jadi kurang bayar (deteksi dari log yang sudah di-load)
  if (d.status_bayar === 'dp' && parseFloat(d.sisa_bayar||0) > 0) {
    const hasLunasDP = (d.logs||[]).some(l => l.tipe === 'bayar' && (l.catatan||'').includes('Lunas → DP'));
    if (hasLunasDP) {
      const mb = document.getElementById('modalBody');
      mb.insertAdjacentHTML('afterbegin',
        '<div class="hl-badge hl-badge-dp" style="display:block;margin-bottom:12px;padding:8px 12px;border-radius:10px;background:#FEF3C7;border:1px solid #FDE68A;color:#92400E;font-size:13px;font-weight:600">⚠️ Kurang bayar (sebelumnya lunas)</div>');
    }
  }

  // Order dgn permintaan hapus pending → kunci semua editing
  if (d.pending_delete) {
    const mb = document.getElementById('modalBody');
    mb.insertAdjacentHTML('afterbegin',
      '<div style="background:#FEF3C7;border:1px solid #FDE68A;color:#92400E;padding:10px 14px;border-radius:10px;margin-bottom:14px;font-size:13px;font-weight:600">🗑️ Order ini sedang <b>menunggu persetujuan hapus</b> — tidak bisa diedit sampai di-review owner.</div>');
    // Kunci SEMUA kontrol di body: Tambah Item, katalog layanan, tambah catatan, status, hapus-item, dll
    mb.querySelectorAll('input, select, textarea, button').forEach(el => {
      el.disabled = true; el.style.pointerEvents = 'none'; el.style.opacity = '.55';
    });
    ['btnSaveEdit', 'btnBayarDariDetail', 'btnMintaHapus'].forEach(bid => {
      const b = document.getElementById(bid);
      if (b) { b.disabled = true; b.style.opacity = '.45'; b.style.pointerEvents = 'none'; }
    });
    const bmh = document.getElementById('btnMintaHapus');
    if (bmh) bmh.textContent = '⏳ Menunggu Persetujuan Hapus';
  }
}

// ── CATATAN INTERNAL MULTI-ROW ────────────────────────
async function loadNotes(orderId) {
  const box = document.getElementById('notesList');
  if (!box) return;
  try {
    const r = await fetch('orders.php?action=notes_list&order_id=' + orderId);
    const d = await r.json();
    const rows = d.rows || [];
    if (!rows.length) {
      box.innerHTML = '<div style="color:var(--gray);font-size:13px;text-align:center;padding:6px">Belum ada catatan tim</div>';
      return;
    }
    box.innerHTML = rows.map(n => `
      <div style="padding:7px 0;border-bottom:1px dashed rgba(27,45,90,.08)">
        <div style="font-size:13px;color:var(--navy);white-space:pre-wrap">${esc(n.catatan)}</div>
        <div style="font-size:11px;color:var(--gray);margin-top:3px">
          ✍️ ${esc(n.user_nama || '-')} · ${fmtDateTime(n.created_at)}
        </div>
      </div>`).join('');
  } catch (e) {
    box.innerHTML = '<div style="color:#dc2626;font-size:12px">❌ Gagal memuat catatan</div>';
  }
}

async function addNote() {
  if (!currentEditId) return;
  const inp = document.getElementById('noteInput');
  const v = (inp.value || '').trim();
  if (!v) { showToast('Tulis catatan dulu', 'error'); return; }
  try {
    const r = await fetch('orders.php?action=note_add', {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify({order_id: currentEditId, catatan: v})
    });
    const d = await r.json();
    if (d.error) { showToast(d.error, 'error'); return; }
    inp.value = '';
    loadNotes(currentEditId);
    showToast('✓ Catatan ditambahkan');
  } catch (e) {
    showToast('Network error', 'error');
  }
}

function closeModal() {
  document.getElementById('modalDetail').classList.remove('open');
  currentEditId = null;
  editItems = [];
  // Bila dibuka di dalam iframe (mis. dari Kanban) → beri tahu parent utk tutup overlay
  if (window.parent && window.parent !== window) {
    try { window.parent.postMessage('lmOrderDetailClosed', '*'); } catch (e) {}
  }
}

function openBayarFromDetail() {
  if (!currentEditId) return;
  openBayarById(currentEditId);
}

async function openBayarById(id) {
  const r = await fetch('orders.php?action=get&id=' + id);
  const d = await r.json();
  if (d.id) openBayarModal(d.id, d.nama_pelanggan, d.total, d.dp||0, d.sisa_bayar||0);
}

// ── SHARE TRACKING LINK VIA WHATSAPP ──
function shareToWA() {
  if (!currentEditId) return;
  const order = currentOrderData;
  if (!order) { showToast('Order belum di-load', 'error'); return; }
  const phone = (order.telepon || '').replace(/[^0-9]/g, '');
  if (!phone) { showToast('Pelanggan tidak punya nomor telepon', 'error'); return; }
  const waNum = phone.startsWith('0') ? '62' + phone.substring(1) : phone;
  const trackUrl = location.origin + '/cek?n=' + encodeURIComponent(order.no_order);
  const statusTxt = {
    'masuk':'sudah kami terima','cuci':'sedang dicuci','kering':'sedang dikeringkan',
    'setrika':'sedang disetrika','siap':'siap diambil','diambil':'sudah diambil'
  }[order.status_proses] || order.status_proses;
  const msg = `Halo ${order.nama_pelanggan}, cucian Anda dengan nomor *${order.no_order}* saat ini ${statusTxt}.\n\nCek status lengkap (real-time): ${trackUrl}\n\nVerifikasi pakai 4 digit terakhir nomor telepon Anda.`;
  const waUrl = `https://wa.me/${waNum}?text=${encodeURIComponent(msg)}`;
  window.open(waUrl, '_blank');
}

// ── REQUEST DELETE (Smartlink-style approval workflow) ──
async function requestDelete() {
  if (!currentEditId) return;
  const alasan = await lmPrompt('Alasan permintaan hapus order ini?', '', {placeholder:'Wajib diisi, owner akan review', title:'Minta Hapus Order'});
  if (!alasan || alasan.trim().length < 3) {
    showToast('Alasan minimal 3 karakter', 'error');
    return;
  }
  const r = await fetch('orders.php?action=request_delete', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({transaksi_id: currentEditId, alasan: alasan.trim()})
  });
  const d = await r.json();
  if (d.error) { showToast(d.error, 'error'); return; }
  showToast('✅ Permintaan hapus terkirim. Menunggu approval owner.', 'success');
  closeModal();
  loadOrders();
}

// ── PROSES STEPS ──────────────────────────────────────
function setProses(val, el) {
  document.getElementById('edit_status_proses').value = val;
  document.querySelectorAll('.step-btn').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  // Show/hide foto pickup section (muncul saat status 'diambil')
  const fpBox = document.getElementById('fotoPickupBox');
  if (fpBox) fpBox.style.display = val === 'diambil' ? 'block' : 'none';
}

// ── FOTO PICKUP UPLOAD ───────────────────────────────
async function uploadFotoPickup(input) {
  const file = input.files?.[0];
  if (!file) return;
  const status = document.getElementById('fotoPickupStatus');
  status.textContent = '⏳ Upload foto...';
  const fd = new FormData();
  fd.append('foto', file);
  fd.append('_csrf', csrfToken());
  try {
    const r = await fetch('orders.php?action=upload_foto_pickup', {
      method:'POST', headers:{'X-CSRF-Token':csrfToken()}, body: fd
    });
    const d = await r.json();
    if (d.error) { status.textContent = '❌ ' + d.error; return; }
    document.getElementById('edit_foto_pickup_path').value = d.path;
    status.innerHTML = '✅ Foto siap disimpan saat klik Simpan';
  } catch(e) {
    status.textContent = '❌ Gagal upload: ' + e.message;
  }
}

// Pemisah ribuan manual (tak bergantung Intl locale — konsisten di WebView APK)
function grpRibu(n){ return String(Math.round(parseFloat(n)||0)).replace(/\B(?=(\d{3})+(?!\d))/g,'.'); }

// ── EDIT ITEMS ────────────────────────────────────────
function renderEditItems() {
  const tbody = document.getElementById('editItemsBody');
  if (!tbody) return;
  tbody.innerHTML = editItems.map((item, i) => `
    <tr>
      <td data-lbl="Layanan">${CAN_EDIT_ORDER ? `<input class="item-input" value="${esc(item.nama_layanan)}" style="width:110px" oninput="editItems[${i}].nama_layanan=this.value;recalcEdit()"/>` : `<span style="font-size:13px">${esc(item.nama_layanan)}</span>`}</td>
      <td data-lbl="Satuan">${CAN_EDIT_ORDER ? `<select class="item-input" style="width:52px" onchange="editItems[${i}].satuan=this.value">${['kg','pcs','set','pasang'].map(s=>`<option value="${s}" ${item.satuan===s?'selected':''}>${s}</option>`).join('')}</select>` : `<span style="font-size:13px">${item.satuan}</span>`}</td>
      <td data-lbl="Jumlah">${CAN_EDIT_ORDER ? `<input class="item-input" type="number" value="${item.jumlah}" step="0.1" min="0" style="width:52px" oninput="editItems[${i}].jumlah=parseFloat(this.value)||0;recalcEdit()"/>` : `<span style="font-family:var(--mono);font-size:13px">${item.jumlah}</span>`}</td>
      <td data-lbl="Harga">${CAN_EDIT_ORDER ? `<input class="item-input" type="text" inputmode="numeric" value="${grpRibu(item.harga_satuan)}" style="width:80px" oninput="const v=parseInt(this.value.replace(/\\D/g,''))||0;editItems[${i}].harga_satuan=v;this.value=grpRibu(v);recalcEdit()"/>` : `<span style="font-family:var(--mono);font-size:13px">Rp ${grpRibu(item.harga_satuan)}</span>`}</td>
      <td data-lbl="Subtotal" class="item-sub">Rp ${grpRibu(item.jumlah*item.harga_satuan)}</td>
      <td data-lbl="Ket">${CAN_EDIT_ORDER ? `<input class="item-input" value="${esc(item.catatan_item||'')}" placeholder="..." style="width:60px" oninput="editItems[${i}].catatan_item=this.value"/>` : `<span style="font-size:12px;color:var(--gray)">${esc(item.catatan_item||'-')}</span>`}</td>
      <td>${CAN_EDIT_ORDER ? `<button class="btn-remove" onclick="removeEditItem(${i})">✕ Hapus</button>` : ''}</td>
    </tr>`).join('');
}

function addEditRow() {
  editItems.push({layanan_id:null,nama_layanan:'',satuan:'kg',jumlah:1,harga_satuan:0,catatan_item:''});
  renderEditItems(); recalcEdit();
}
function removeEditItem(i) { editItems.splice(i,1); renderEditItems(); recalcEdit(); }

function renderEditLayananGrid(list) {
  const grid = document.getElementById('editLayananGrid');
  if (!grid) return;
  grid.innerHTML = (list||[]).map(l => `
    <button style="padding:6px 8px;background:var(--off);border:1px solid rgba(27,45,90,.1);border-radius:7px;cursor:pointer;text-align:left;font-family:var(--font);transition:all .2s"
      onmouseover="this.style.borderColor='var(--teal)'" onmouseout="this.style.borderColor='rgba(27,45,90,.1)'"
      onclick="addEditLayanan(${l.id},'${esc(l.nama)}','${l.satuan}',${l.harga})">
      <div style="font-size:11px;font-weight:600;color:var(--navy)">${esc(l.nama)}</div>
      <div style="font-size:10px;color:var(--teal-d);font-family:var(--mono)">Rp ${grpRibu(l.harga)}</div>
    </button>`).join('');
}

function filterEditLayanan(q) {
  const filtered = q ? layananAll.filter(l=>l.nama.toLowerCase().includes(q.toLowerCase())) : layananAll;
  renderEditLayananGrid(filtered);
}

function addEditLayanan(id, nama, satuan, harga) {
  editItems.push({layanan_id:id,nama_layanan:nama,satuan,jumlah:1,harga_satuan:harga,catatan_item:''});
  renderEditItems(); recalcEdit();
}

// ── Quick-create layanan (samakan dgn POS) ──
function lynUnitChanged() {
  const el = document.getElementById('lyn_q_jam');
  if (document.getElementById('lyn_q_unit').value === 'hari') { el.value = ''; el.placeholder = '1'; }
  else if (!el.value) { el.value = '24'; el.placeholder = '24'; }
}
function openLayananQuick() {
  const m = document.getElementById('lynQuickModal');
  if (m) { m.style.display = 'flex'; document.getElementById('lyn_q_nama').focus(); }
}
function closeLayananQuick() {
  const m = document.getElementById('lynQuickModal');
  if (m) m.style.display = 'none';
  ['lyn_q_nama','lyn_q_kategori','lyn_q_harga','lyn_q_min'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
  const j = document.getElementById('lyn_q_jam'); if (j) j.value = '24';
  const u = document.getElementById('lyn_q_unit'); if (u) u.value = 'jam';
  const s = document.getElementById('lyn_q_satuan'); if (s) s.value = 'kg';
}
async function saveLayananQuick() {
  const nama = document.getElementById('lyn_q_nama').value.trim();
  if (!nama) { lmAlert('Nama layanan wajib diisi'); return; }
  const payload = {
    nama,
    kategori: document.getElementById('lyn_q_kategori').value.trim() || 'Reguler',
    satuan:   document.getElementById('lyn_q_satuan').value,
    harga:    parseFloat(document.getElementById('lyn_q_harga').value) || 0,
    estimasi_jam: (function(){ const hari = document.getElementById('lyn_q_unit').value === 'hari'; const n = parseInt(document.getElementById('lyn_q_jam').value) || (hari ? 1 : 24); return hari ? n * 24 : n; })(),
    qty_minimum:  parseFloat(document.getElementById('lyn_q_min').value) || 0,
  };
  try {
    const r = await fetch('/layanan.php?action=save', {
      method: 'POST', headers: { 'Content-Type':'application/json', 'X-CSRF-Token': csrfToken() },
      body: JSON.stringify(payload)
    });
    const d = await r.json();
    if (d.error) { lmAlert('Gagal: ' + d.error); return; }
    showToast('Layanan ditambahkan ✓', 'success');
    closeLayananQuick();
    await loadLayanan();
    renderEditLayananGrid(layananAll);
    if (d.id) { const lyn = (layananAll||[]).find(l => l.id == d.id); if (lyn) addEditLayanan(lyn.id, lyn.nama, lyn.satuan, lyn.harga); }
  } catch (e) { lmAlert('Gagal koneksi: ' + e.message); }
}

function recalcEdit() {
  const sub  = editItems.reduce((s,i) => s + i.jumlah * i.harga_satuan, 0);
  const dis  = parseFloat(document.getElementById('edit_diskon')?.value) || 0;
  const tot  = Math.max(sub - dis + (currentOrderBiayaTambahan || 0) + (currentOrderBiayaLainnya || 0), 0);
  const dp   = parseFloat(document.getElementById('edit_dp')?.value) || 0;
  const sisa = tot - dp;
  const subEl = document.getElementById('etSubtotal');
  const totEl = document.getElementById('etTotal');
  const sisEl = document.getElementById('etSisa');
  if (subEl) subEl.textContent = 'Rp ' + grpRibu(sub);
  if (totEl) totEl.textContent = 'Rp ' + grpRibu(tot);
  if (sisEl) sisEl.textContent = 'Rp ' + grpRibu(sisa);
  const cells = document.querySelectorAll('.item-sub');
  editItems.forEach((item,i) => { if(cells[i]) cells[i].textContent = 'Rp ' + grpRibu(item.jumlah*item.harga_satuan); });
}

// ── SAVE EDIT ─────────────────────────────────────────
async function saveEdit() {
  if (!currentEditId) return;
  // Tak ada perubahan → info & tutup, jangan kirim ke server
  if (!window.__editResolution && editSnapshot !== null && editStateJSON() === editSnapshot) {
    showToast('ℹ️ Tidak ada perubahan untuk disimpan', 'info');
    closeModal();
    return;
  }
  const btn = document.getElementById('btnSaveEdit');
  btn.disabled = true; btn.textContent = '⏳ Menyimpan...';

  const payload = {
    id:               currentEditId,
    status_proses:    document.getElementById('edit_status_proses').value,
    catatan:          document.getElementById('edit_catatan').value,
    catatan_internal: document.getElementById('edit_catatan_internal').value,
    metode_bayar:     document.getElementById('edit_metode').value,
    diskon:           document.getElementById('edit_diskon').value,
    dp:               document.getElementById('edit_dp').value,
    estimasi:         document.getElementById('edit_estimasi').value,
    foto_pickup:      document.getElementById('edit_foto_pickup_path')?.value || '',
    items:            editItems,
    confirm_resolution: window.__editResolution || null,
  };

  const r = await fetch('orders.php?action=update', {
    method: 'POST',
    headers: {'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify(payload)
  });
  const d = await r.json();

  if (d.need_confirm === 'kurang_bayar') {
    btn.disabled = false; btn.textContent = '💾 Simpan Perubahan';
    const okTagih = await lmConfirm(
      'Order ini LUNAS. Perubahan membuat kurang bayar Rp ' + Number(d.selisih).toLocaleString('id-ID') +
      ' (total baru Rp ' + Number(d.total_baru).toLocaleString('id-ID') + '). Lanjutkan? Status akan turun ke DP.',
      {icon:'⚠️'});
    if (okTagih) return saveEditWithResolution('tagih');
    return;
  }
  if (d.need_confirm === 'kelebihan') {
    btn.disabled = false; btn.textContent = '💾 Simpan Perubahan';
    const pilih = await lmKelebihanDialog(d.selisih, d.bisa_deposit);
    if (pilih) return saveEditWithResolution(pilih);
    return;
  }

  if (d.success) {
    showToast('✅ Order berhasil diupdate!', 'success');
    const o = currentOrderData;
    const naikSiap = payload.status_proses === 'siap' && o && o.status_proses !== 'siap';
    if (d.wa_url) {
      if (await lmConfirm('Kirim WA pemberitahuan kekurangan bayar ke pelanggan?', {icon:'📲'})) {
        window.open(d.wa_url, '_blank');
      }
    }
    closeModal();
    loadOrders();
    loadSummary();
    if (naikSiap && o.telepon) {
      const phone = String(o.telepon).replace(/[^0-9]/g,'').replace(/^0/,'62').replace(/^8/,'628');
      if (/^[0-9]{9,15}$/.test(phone) && await lmConfirm('Kirim WA "siap diambil" ke pelanggan?', {icon:'📲'})) {
        const msg = `Halo ${o.nama_pelanggan} ✨\nPesanan #${o.no_order} sudah siap diambil di ${OUTLET_NAMA}.\nTotal: Rp ${Number(o.total||0).toLocaleString('id-ID')}\n\nDitunggu ya!`;
        window.open('https://wa.me/' + phone + '?text=' + encodeURIComponent(msg), '_blank');
      }
    }
  } else {
    showToast('❌ ' + (d.error||'Gagal'), 'error');
  }
  btn.disabled = false; btn.textContent = '💾 Simpan Perubahan';
}

async function saveEditWithResolution(res) {
  window.__editResolution = res;
  try { await saveEdit(); } finally { window.__editResolution = null; }
}
// Dialog pilihan kelebihan bayar: refund tunai / masuk deposit / batal
function lmKelebihanDialog(selisih, bisaDeposit) {
  return new Promise(resolve => {
    const rp = 'Rp ' + Number(selisih).toLocaleString('id-ID');
    const wrap = document.createElement('div');
    wrap.style.cssText = 'position:fixed;inset:0;background:rgba(15,28,58,.6);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px';
    wrap.innerHTML = `
      <div style="background:#fff;border-radius:16px;padding:24px;max-width:360px;width:100%;text-align:center">
        <div style="font-size:40px;margin-bottom:8px">💸</div>
        <div style="font-weight:800;color:#0F1C3A;margin-bottom:6px">Kelebihan bayar ${rp}</div>
        <div style="font-size:13px;color:#6B7280;margin-bottom:18px">Order ini sudah lunas. Kelebihan pembayaran mau diapakan?</div>
        <button data-v="refund_tunai" style="display:block;width:100%;padding:12px;margin-bottom:8px;background:#0F1C3A;color:#fff;border:0;border-radius:10px;font-weight:700;cursor:pointer">💵 Refund Tunai ${rp}</button>
        ${bisaDeposit ? `<button data-v="ke_deposit" style="display:block;width:100%;padding:12px;margin-bottom:8px;background:#EAFBF8;color:#0F766E;border:1px solid #99F6E4;border-radius:10px;font-weight:700;cursor:pointer">💳 Masukkan ke Deposit</button>` : ''}
        <button data-v="" style="background:none;border:0;color:#9CA3AF;font-size:13px;cursor:pointer;margin-top:4px">Batal</button>
      </div>`;
    wrap.addEventListener('click', e => {
      const v = e.target?.dataset?.v;
      if (v === undefined && e.target !== wrap) return;
      wrap.remove(); resolve(v || null);
    });
    document.body.appendChild(wrap);
  });
}

// ── FILTER ────────────────────────────────────────────
function filterByStatus(s) {
  document.getElementById('filterStatus').value = s;
  loadOrders();
}
function debounce() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => loadOrders(1), 400);
}

async function printLabel() {
  if (!currentEditId) { showToast('❌ Order belum dipilih', 'error'); return; }
  const url = '/api/label.php?id=' + currentEditId + '&_=' + Date.now();
  // Di APK + printer thermal → cetak langsung (sama seperti nota)
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
  // Browser/desktop → buka popup (auto-print bawaan)
  window.open(url, '_blank', 'width=380,height=520');
}

// ── HELPERS ───────────────────────────────────────────
function statusLabel(s){return{'masuk':'📥 Masuk','cuci':'🫧 Cuci','kering':'💨 Kering','setrika':'👔 Setrika','siap':'✅ Siap','diambil':'📦 Diambil/Diantar'}[s]||s}
function bayarLabel(s){return{'lunas':'✅ Lunas','dp':'⚡ DP','belum_bayar':'⏳ Belum Bayar'}[s]||s}
// Progress % visual per status (inspired by Smartlink — visual KPI)
function prosesPercent(s){return{'masuk':10,'cuci':30,'kering':50,'setrika':70,'siap':90,'diambil':100}[s]||0}
function fmtDate(d){if(!d)return'-';return new Date(d).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'})}
function fmtDateTime(d){if(!d)return'-';return new Date(d).toLocaleString('id-ID',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'})}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
function showToast(msg,type='success'){const t=document.getElementById('toast');t.textContent=msg;t.className='toast '+type+' show';setTimeout(()=>t.className='toast',3500)}

// ── CETAK ULANG — pakai StrukGenerator ───────────────
async function cetakUlang(id) {
  const frame   = document.getElementById('cetakFrame');
  const openBtn = document.getElementById('openCetakBtn');
  // watermark=1 → show "COPY" watermark untuk cetak ulang
  const apiUrl  = `/api/struk.php?action=generate&id=${id}&tipe=retail`;
  openBtn.href  = apiUrl;
  frame.src     = apiUrl;
  document.getElementById('modalCetak').classList.add('open');
  return; // ── legacy code tidak aktif ──

  const r = await fetch('orders.php?action=get_struk&id=' + id);
  const d = await r.json();
  if (d.error) { document.getElementById('strukCetakUlang').innerHTML = '<div style="color:red">❌ ' + d.error + '</div>'; return; }

  const isFull = parseFloat(d.dp) >= parseFloat(d.total);
  const metodeTxt = PM_LABEL[d.metode_bayar] || d.metode_bayar || '-';
  const statusProsesLabel = {'masuk':'Diterima','cuci':'Sedang Dicuci','kering':'Sedang Dikeringkan','setrika':'Sedang Disetrika','siap':'Siap Diambil','diambil':'Sudah Diambil/Diantar'}[d.status_proses] || d.status_proses;

  const itemRows = (d.items||[]).map(item => `
    <div class="struk-item">
      ${item.nama_layanan}
      <br>&nbsp;&nbsp;${parseFloat(item.jumlah).toLocaleString('id-ID')} ${item.satuan} × Rp ${parseFloat(item.harga_satuan).toLocaleString('id-ID')}
      ${item.catatan_item ? '<br>&nbsp;&nbsp;<em>Ket: ' + item.catatan_item + '</em>' : ''}
    </div>
    <div class="struk-row">
      <span></span>
      <span>Rp ${parseFloat(item.subtotal).toLocaleString('id-ID')}</span>
    </div>`).join('');

  document.getElementById('strukCetakUlang').innerHTML = `
    <div class="struk">
      <div class="struk-header">
        <h2>🫧 ${BRAND_NAME.toUpperCase()}</h2>
        ${OUTLET_ADDR ? `<p>${OUTLET_ADDR}</p>` : ''}
        ${OUTLET_TELP ? `<p>${OUTLET_TELP}</p>` : ''}
        <p style="margin-top:4px;font-size:10px">— SALINAN NOTA —</p>
      </div>
      <div class="struk-row"><span>No. Order</span><span>${d.no_order}</span></div>
      <div class="struk-row"><span>Tanggal</span><span>${fmtDate(d.tanggal)}</span></div>
      <div class="struk-row"><span>Pelanggan</span><span>${esc(d.nama_pelanggan)}</span></div>
      ${d.telepon ? `<div class="struk-row"><span>Telp</span><span>${d.telepon}</span></div>` : ''}
      ${d.estimasi_selesai ? `<div class="struk-row"><span>Est. Selesai</span><span>${fmtDate(d.estimasi_selesai)}</span></div>` : ''}
      <div class="struk-row"><span>Status</span><span>${statusProsesLabel}</span></div>
      <hr class="struk-divider"/>
      ${itemRows}
      <hr class="struk-divider"/>
      <div class="struk-row"><span>Subtotal</span><span>Rp ${parseFloat(d.subtotal||d.total).toLocaleString('id-ID')}</span></div>
      ${parseFloat(d.diskon||0)>0 ? `<div class="struk-row"><span>Diskon</span><span>- Rp ${parseFloat(d.diskon).toLocaleString('id-ID')}</span></div>` : ''}
      <div class="struk-total">
        <div class="struk-row bold"><span>TOTAL</span><span>Rp ${parseFloat(d.total).toLocaleString('id-ID')}</span></div>
        <div class="struk-row"><span>Dibayar (${metodeTxt})</span><span>Rp ${parseFloat(d.dp||0).toLocaleString('id-ID')}</span></div>
        ${!isFull ? `<div class="struk-row bold"><span>SISA BAYAR</span><span>Rp ${parseFloat(d.sisa_bayar||0).toLocaleString('id-ID')}</span></div>` : ''}
      </div>
      ${d.catatan ? `<hr class="struk-divider"/><div style="font-size:11px">📝 ${esc(d.catatan)}</div>` : ''}
      <div class="struk-footer">
        <p>Status: ${isFull ? '✅ LUNAS' : '⚡ Belum Lunas'}</p>
        <p>Cek status: lamasy.harpy.id/track.php</p>
        <p>Terima kasih telah mempercayakan</p>
        <p>cucian Anda kepada ${BRAND_NAME}!</p>
      </div>
    </div>`;
}

function closeCetakModal() { document.getElementById('modalCetak').classList.remove('open'); }
async function doPrint() {
  const frame = document.getElementById('cetakFrame');
  const hasTP = !!window.ThermalPrint;
  const avail = hasTP && ThermalPrint.isAvailable();
  const inApp = !!(window.Capacitor && typeof window.Capacitor.isNativePlatform === 'function' && window.Capacitor.isNativePlatform());
  const pr    = hasTP ? ThermalPrint.getPrinter() : null;

  // Di app + plugin aktif → cetak ke printer thermal Bluetooth (sama seperti POS)
  if (avail) {
    if (pr && pr.address) {
      let node = null;
      try { const doc = frame && frame.contentDocument; if (doc) node = doc.querySelector('.struk') || doc.body; } catch (e) {}
      if (!node) { showToast('Struk belum siap', 'error'); return; }
      try {
        showToast('🖨 Mencetak…', 'info');
        await ThermalPrint.print(node, PAPER_WIDTH_PX);
        showToast('✅ Struk tercetak', 'success');
      } catch (e) { showToast('❌ ' + (e.message || 'Gagal cetak'), 'error'); }
      return;
    }
    showToast('Belum ada printer dipilih — atur di Pengaturan → Outlet & Nota → 🖨 Printer Thermal', 'error');
    return;
  }
  // Di app tapi plugin tak terdeteksi
  if (inApp) { showToast('Plugin printer thermal tak terdeteksi. Pakai APK terbaru / restart app.', 'error'); return; }
  // Browser desktop → dialog cetak biasa
  if (frame && frame.contentWindow) { frame.contentWindow.focus(); frame.contentWindow.print(); }
  else window.print();
}

// ── WA REMINDER ───────────────────────────────────────
let currentWAId = null;
let currentWAType = 'reminder';
let currentWAData = null;

async function openWAModal(id) {
  currentWAId = id;
  currentWAType = 'reminder';
  document.getElementById('waBubble').textContent = '⏳ Memuat...';
  document.getElementById('waPhone').textContent = '-';
  document.getElementById('modalWA').classList.add('open');
  await loadWAMessage();
}

function closeWAModal() { document.getElementById('modalWA').classList.remove('open'); currentWAId = null; }

async function selectWAType(type, el) {
  currentWAType = type;
  document.querySelectorAll('.wa-type-btn').forEach(b => {
    b.className = b.classList.contains('wa-type-btn') ? 'btn btn-outline btn-sm wa-type-btn' : b.className;
  });
  el.className = 'btn btn-teal-sm btn-sm wa-type-btn active';
  await loadWAMessage();
}

async function loadWAMessage() {
  if (!currentWAId) return;
  const r = await fetch(`orders.php?action=wa_message&id=${currentWAId}&tipe=${currentWAType}`);
  const d = await r.json();
  if (d.error) { document.getElementById('waBubble').textContent = '❌ ' + d.error; return; }
  currentWAData = d;

  const star = '*';
  const boldRegex = new RegExp('\\' + star + '([^' + star + ']+)\\' + star, 'g');
  const formatted = d.message
    .replace(boldRegex, '<strong>$1</strong>')
    .replace(/\n/g, '<br>');
  document.getElementById('waBubble').innerHTML = formatted;
  document.getElementById('waPhone').textContent = d.phone ? '+' + d.phone : 'Tidak ada nomor';
  document.getElementById('waTrackLink').href = 'track.php?order=' + encodeURIComponent(d.no_order);
}

function kirimWA() {
  if (!currentWAData) return;
  if (!currentWAData.phone) { showToast('⚠️ Nomor HP tidak tersedia', 'error'); return; }
  const url = 'https://wa.me/' + currentWAData.phone + '?text=' + encodeURIComponent(currentWAData.message);
  window.open(url, '_blank');
  closeWAModal();
  showToast('📲 WhatsApp dibuka!', 'success');
}

// ── PAYMENT MODAL ────────────────────────────────────
let currentBayarId = null;
let currentBayarData = null;
let currentTipeBayar = 'sebagian';

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
      loadOrders();
      loadSummary();
      if (currentEditId) openDetail(currentEditId);
    } else {
      showToast('❌ ' + (d.error||'Gagal'), 'error');
    }
  } catch(e) {
    showToast('❌ Error: ' + e.message, 'error');
  }
}
</script>

<!-- Quick-create layanan (samakan dgn POS) -->
<div id="lynQuickModal" style="display:none;position:fixed;inset:0;background:rgba(15,28,58,.65);z-index:99999;align-items:center;justify-content:center;padding:20px">
  <div style="background:#fff;border-radius:14px;padding:22px;max-width:440px;width:100%;max-height:90vh;overflow-y:auto">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
      <h3 style="margin:0;font-size:16px;font-weight:800;color:var(--navy)">➕ Tambah Layanan Baru</h3>
      <button onclick="closeLayananQuick()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--gray)">×</button>
    </div>
    <style>#lynQuickModal .lq{width:100%;padding:9px 11px;border:1px solid rgba(27,45,90,.18);border-radius:8px;font-size:13px;font-family:inherit;box-sizing:border-box}#lynQuickModal label{font-size:11px;font-weight:700;color:var(--navy);text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:4px}</style>
    <div style="margin-bottom:10px"><label>Nama Layanan *</label><input type="text" id="lyn_q_nama" class="lq" placeholder="Misal: Cuci Setrika Reguler"/></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px">
      <div><label>Kategori</label><input type="text" id="lyn_q_kategori" class="lq" placeholder="Reguler/Express"/></div>
      <div><label>Satuan</label><select id="lyn_q_satuan" class="lq"><option value="kg">kg</option><option value="pcs">pcs</option><option value="set">set</option><option value="m2">m²</option></select></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px">
      <div><label>Harga / Satuan (Rp)</label><input type="number" id="lyn_q_harga" class="lm-rp lq" placeholder="7000" min="0" step="500"/></div>
      <div><label>Estimasi *</label><div style="display:flex;gap:6px"><input type="number" id="lyn_q_jam" class="lq" value="24" min="1" style="flex:1"/><select id="lyn_q_unit" class="lq" style="width:80px" onchange="lynUnitChanged()"><option value="jam">Jam</option><option value="hari">Hari</option></select></div></div>
    </div>
    <div style="margin-bottom:14px"><label>Min. Order (opsional)</label><input type="number" id="lyn_q_min" class="lq" value="0" min="0" step="0.5" placeholder="0 = tidak ada minimum"/></div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-outline" onclick="closeLayananQuick()" style="flex:1">Batal</button>
      <button class="btn btn-primary" onclick="saveLayananQuick()" style="flex:1.5">💾 Simpan & Pakai</button>
    </div>
  </div>
</div>

<!-- Cetak thermal Bluetooth (dipakai doPrint di modal Cetak Ulang Nota) -->
<script src="/assets/vendor/html2canvas.min.js?v=<?= @filemtime(__DIR__.'/assets/vendor/html2canvas.min.js') ?: '1' ?>"></script>
<script src="/assets/js/thermal-print.js?v=<?= @filemtime(__DIR__.'/assets/js/thermal-print.js') ?: '1' ?>"></script>

<!-- ── SCAN QR ORDER (label/struk → buka detail) ─────────────────────────
     Live scan via getUserMedia + BarcodeDetector (butuh izin CAMERA di APK —
     build berikutnya). Fallback otomatis: jepret foto → decode. -->
<style>
#scanFab{position:fixed;right:18px;bottom:18px;z-index:150;width:54px;height:54px;border-radius:50%;border:none;
  background:linear-gradient(135deg,var(--teal),var(--teal-d));color:#04211d;font-size:24px;cursor:pointer;
  box-shadow:0 6px 20px rgba(28,196,178,.45)}
/* Mobile: naikkan di atas bottom-nav (.ol-bottomnav tampil ≤900px) */
@media (max-width:900px){ #scanFab{ bottom:calc(86px + env(safe-area-inset-bottom, 0px)); } }
</style>
<button id="scanFab" onclick="scanOpen()" aria-label="Scan QR Order" title="Scan QR Order">📷</button>
<div id="scanOverlay" style="display:none;position:fixed;inset:0;z-index:9998;background:rgba(10,15,31,.92);
     flex-direction:column;align-items:center;justify-content:center;gap:14px;padding:20px">
  <video id="scanVideo" playsinline muted style="width:min(92vw,420px);max-height:60vh;border-radius:14px;background:#000"></video>
  <div id="scanStatus" style="color:#fff;font-size:14px;font-weight:600;text-align:center">Menyiapkan kamera…</div>
  <div style="display:flex;gap:10px">
    <button class="btn btn-teal-sm" onclick="scanPhotoTrigger()">📸 Jepret Foto</button>
    <button class="btn btn-outline" style="color:#fff;border-color:rgba(255,255,255,.4)" onclick="scanClose()">Tutup</button>
  </div>
</div>
<input type="file" id="scanPhotoInput" accept="image/*" capture="environment" style="display:none" onchange="scanOnPhoto(this)">
<script>
let _scanStream = null, _scanTimer = null;

function scanExtractKode(decoded) {
  decoded = String(decoded || '').trim();
  const url = decoded.match(/[?&](?:o|order|n)=([A-Za-z0-9\-\/_%]+)/);
  if (url) return decodeURIComponent(url[1]);
  if (/^[A-Z0-9][A-Z0-9\-\/]{4,}$/i.test(decoded)) return decoded;
  const any = decoded.replace(/https?:\/\/\S*/gi, ' ').match(/([A-Z0-9][A-Z0-9-]{5,})/i);
  return any ? any[1] : null;
}

async function scanHandle(decoded) {
  const kode = scanExtractKode(decoded);
  if (!kode) { showToast('QR tidak dikenali', 'error'); return; }
  try {
    const r = await fetch('orders.php?action=find_by_kode&kode=' + encodeURIComponent(kode));
    const d = await r.json();
    if (d.error) { showToast('❌ ' + d.error, 'error'); return; }
    showToast('✓ ' + d.no_order, 'success');
    openDetail(d.id);
  } catch (e) { showToast('Network error', 'error'); }
}

async function scanOpen() {
  const ov = document.getElementById('scanOverlay');
  const status = document.getElementById('scanStatus');
  const vid = document.getElementById('scanVideo');
  ov.style.display = 'flex'; vid.style.display = ''; status.textContent = 'Menyiapkan kamera…';
  const diag = { where: 'scanOpen', bd: ('BarcodeDetector' in window), md: !!navigator.mediaDevices, gum: !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia) };
  if (diag.bd && diag.gum) {
    try {
      _scanStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
      vid.srcObject = _scanStream; await vid.play();
      status.textContent = 'Arahkan kamera ke QR pada label/struk…';
      const det = new BarcodeDetector({ formats: ['qr_code'] });
      _scanTimer = setInterval(async () => {
        try {
          const found = await det.detect(vid);
          if (found && found.length) { const v = found[0].rawValue; scanClose(); scanHandle(v); }
        } catch (e) {}
      }, 300);
      return;
    } catch (e) {
      diag.err = (e && (e.name + ': ' + e.message)) || String(e);
    }
  }
  // Kirim alasan gagal ke server (print_debug) — biar kelihatan dari server kenapa fallback
  try { fetch('/api/print_debug.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() }, body: JSON.stringify({ trace: 'scan-live-fallback ' + JSON.stringify(diag) + ' ua=' + navigator.userAgent.slice(0, 80) }) }); } catch (e) {}
  vid.style.display = 'none';
  status.textContent = 'Live scan tak tersedia — jepret foto QR-nya';
  scanPhotoTrigger();
}

function scanClose() {
  if (_scanTimer) { clearInterval(_scanTimer); _scanTimer = null; }
  if (_scanStream) { try { _scanStream.getTracks().forEach(t => t.stop()); } catch (e) {} _scanStream = null; }
  document.getElementById('scanOverlay').style.display = 'none';
}

function scanPhotoTrigger() {
  const inp = document.getElementById('scanPhotoInput');
  inp.value = ''; inp.click();
}

async function scanOnPhoto(input) {
  const file = input.files && input.files[0];
  input.value = '';
  if (!file) return;
  scanClose();
  let decoded = null;
  try {
    if ('BarcodeDetector' in window) {
      const bmp = await createImageBitmap(file);
      const found = await new BarcodeDetector({ formats: ['qr_code'] }).detect(bmp);
      if (found && found.length) decoded = found[0].rawValue;
    }
  } catch (e) {}
  if (decoded) { await scanHandle(decoded); return; }
  const kode = await lmPrompt('QR tak terbaca dari foto. Coba lebih dekat & terang, atau ketik no. order:');
  if (kode) await scanHandle(kode.trim());
}
</script>
<?php renderToast(); ?>
</body>
</html>

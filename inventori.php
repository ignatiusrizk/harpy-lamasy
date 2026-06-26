<?php
// ══════════════════════════════════════════════════════
// inventori.php — Inventori Bahan Baku per Outlet
//
// Track stok bahan habis pakai: deterjen, parfum, pewangi,
// plastik kemasan, peralatan, dll. Setiap pergerakan dicatat
// di hl_bahan_mutasi (audit trail). Stok terkini dihitung
// via VIEW hl_bahan_stok.
// ══════════════════════════════════════════════════════
$activePage = 'inventori';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/PushSender.php';
require_once __DIR__ . '/components.php';

$user = currentUser();

// Permission fallback: kalau inventori.view belum di-seed di session, fallback ke kas.view
if (!hasPermission('inventori.view') && !hasPermission('kas.view')) {
    requirePermission('inventori.view'); // ini akan exit dengan pesan akses ditolak
}
$canManage = hasPermission('inventori.manage') || hasPermission('kas.create');

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $tid = TenantResolver::id();
    $oid = TenantResolver::outletId();

    // ─── LIST STOK ─────────────────────────────────────
    if ($action === 'list_stok') {
        $kat = $_GET['kategori'] ?? '';
        $where  = ['tenant_id = ?', 'outlet_id = ?', 'is_active = 1'];
        $params = [$tid, $oid];
        if ($kat) { $where[] = 'kategori = ?'; $params[] = $kat; }
        $whereStr = implode(' AND ', $where);

        $rows = TenantQuery::raw(
            "SELECT * FROM hl_bahan_stok WHERE $whereStr ORDER BY status_stok='aman', nama ASC",
            $params
        );

        // Summary
        $aman = $minim = $habis = 0;
        $nilaiInventori = 0;
        foreach ($rows as $r) {
            if     ($r['status_stok'] === 'habis') $habis++;
            elseif ($r['status_stok'] === 'minim') $minim++;
            else   $aman++;
            $nilaiInventori += intval($r['stok_terkini']) * intval($r['harga_beli']);
        }
        echo json_encode([
            'data' => $rows,
            'summary' => [
                'total' => count($rows),
                'aman' => $aman,
                'minim' => $minim,
                'habis' => $habis,
                'nilai_inventori' => $nilaiInventori,
            ]
        ]);
        exit;
    }

    // ─── LIST MUTASI ──────────────────────────────────
    if ($action === 'list_mutasi') {
        $dari    = $_GET['dari']     ?? date('Y-m-01');
        $sampai  = $_GET['sampai']   ?? date('Y-m-d');
        $tipe    = $_GET['tipe']     ?? '';
        $bahanId = intval($_GET['bahan_id'] ?? 0);

        $where  = ['m.tenant_id = ?', 'm.outlet_id = ?', 'DATE(m.created_at) BETWEEN ? AND ?'];
        $params = [$tid, $oid, $dari, $sampai];
        if ($tipe)    { $where[] = 'm.tipe = ?';     $params[] = $tipe; }
        if ($bahanId) { $where[] = 'm.bahan_id = ?'; $params[] = $bahanId; }
        $whereStr = implode(' AND ', $where);

        // Cap 1000 mutation dalam window (already date-filtered) — outlet super
        // sibuk yang > 1000 mutasi/bulan tetap menampilkan ribuan terbaru dengan
        // hint untuk persempit filter.
        $cap = 1000;
        $rows = TenantQuery::raw(
            "SELECT m.*, b.nama AS bahan_nama, b.satuan, u.nama AS input_by_nama
             FROM hl_bahan_mutasi m
             JOIN hl_bahan b ON b.id = m.bahan_id AND b.tenant_id = m.tenant_id
             LEFT JOIN hl_users u ON u.id = m.input_by AND u.tenant_id = m.tenant_id
             WHERE $whereStr
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT $cap",
            $params
        );
        echo json_encode(['data' => $rows, 'reached_cap' => count($rows) >= $cap, 'cap' => $cap]);
        exit;
    }

    // ─── LIST RESTOCK ALERT ───────────────────────────
    if ($action === 'list_alert') {
        $rows = TenantQuery::raw(
            "SELECT * FROM hl_bahan_stok
             WHERE tenant_id = ? AND outlet_id = ? AND is_active = 1
               AND stok_terkini <= stok_minimum
             ORDER BY (stok_terkini <= 0) DESC, nama ASC",
            [$tid, $oid]
        );
        echo json_encode(['data' => $rows]);
        exit;
    }

    // ─── SAVE BAHAN (create/edit master) ──────────────
    if ($action === 'save_bahan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canManage) { echo json_encode(['error' => 'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);

        $nama = substr(trim(strip_tags($d['nama'] ?? '')), 0, 100);
        if (!$nama) { echo json_encode(['error' => 'Nama bahan wajib diisi']); exit; }

        $katAllowed = ['deterjen','parfum','pewangi','plastik_kemasan','peralatan','lainnya'];
        $kategori   = in_array($d['kategori'] ?? '', $katAllowed) ? $d['kategori'] : 'lainnya';

        $data = [
            'nama'         => $nama,
            'kategori'     => $kategori,
            'satuan'       => substr(trim($d['satuan'] ?? 'pcs'), 0, 20) ?: 'pcs',
            'stok_minimum' => max(0, intval($d['stok_minimum'] ?? 5)),
            'harga_beli'   => max(0, intval($d['harga_beli'] ?? 0)),
            'supplier'     => substr(trim($d['supplier'] ?? ''), 0, 100) ?: null,
            'is_active'    => intval($d['is_active'] ?? 1) ? 1 : 0,
        ];

        if (!empty($d['id'])) {
            TenantQuery::update('hl_bahan', $data, 'id = ?', [intval($d['id'])]);
            logAudit('update','inventori',"Edit bahan: $nama");
            echo json_encode(['success' => true, 'id' => intval($d['id'])]);
        } else {
            $data['stok_awal'] = max(0, intval($d['stok_awal'] ?? 0));
            $newId = TenantQuery::insert('hl_bahan', $data);
            logAudit('create','inventori',"Tambah bahan: $nama (stok awal: {$data['stok_awal']})");
            echo json_encode(['success' => true, 'id' => $newId]);
        }
        exit;
    }

    // ─── SAVE MUTASI (masuk/keluar/adjust) ────────────
    if ($action === 'save_mutasi' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canManage) { echo json_encode(['error' => 'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);

        $bahanId = intval($d['bahan_id'] ?? 0);
        $tipe    = $d['tipe'] ?? '';
        if (!$bahanId) { echo json_encode(['error' => 'Bahan tidak valid']); exit; }
        if (!in_array($tipe, ['masuk','keluar','adjust'])) {
            echo json_encode(['error' => 'Tipe mutasi tidak valid']); exit;
        }

        // Ambil stok terkini dari VIEW
        $stokRow = TenantQuery::rawOne(
            "SELECT stok_terkini, nama, stok_minimum FROM hl_bahan_stok WHERE id = ? AND tenant_id = ? AND outlet_id = ?",
            [$bahanId, $tid, $oid]
        );
        if (!$stokRow) { echo json_encode(['error' => 'Bahan tidak ditemukan']); exit; }
        $stokSebelum = intval($stokRow['stok_terkini']);

        if ($tipe === 'adjust') {
            // adjust pakai stok_aktual sebagai target
            $stokAktual = intval($d['stok_aktual'] ?? -1);
            if ($stokAktual < 0) { echo json_encode(['error' => 'Stok aktual harus >= 0']); exit; }
            $jumlah = abs($stokAktual - $stokSebelum);
            $stokSesudah = $stokAktual;
        } else {
            $jumlah = intval($d['jumlah'] ?? 0);
            if ($jumlah <= 0) { echo json_encode(['error' => 'Jumlah harus > 0']); exit; }
            if ($tipe === 'masuk')  $stokSesudah = $stokSebelum + $jumlah;
            else                    $stokSesudah = $stokSebelum - $jumlah; // keluar
            if ($stokSesudah < 0) {
                echo json_encode(['error' => "Stok tidak cukup. Sisa: $stokSebelum, mau keluar: $jumlah"]);
                exit;
            }
        }

        $row = [
            'tenant_id'    => $tid,
            'outlet_id'    => $oid,
            'bahan_id'     => $bahanId,
            'tipe'         => $tipe,
            'jumlah'       => $jumlah,
            'stok_sebelum' => $stokSebelum,
            'stok_sesudah' => $stokSesudah,
            'catatan'      => substr(trim(strip_tags($d['catatan'] ?? '')), 0, 200) ?: null,
            'input_by'     => $user['id'],
        ];

        if ($tipe === 'masuk') {
            $row['harga_beli'] = max(0, intval($d['harga_beli'] ?? 0));
            $row['supplier']   = substr(trim($d['supplier'] ?? ''), 0, 100) ?: null;
            // Update harga_beli & supplier di master kalau diisi
            if ($row['harga_beli'] > 0) {
                $masterUpd = ['harga_beli' => $row['harga_beli']];
                if ($row['supplier']) $masterUpd['supplier'] = $row['supplier'];
                TenantQuery::update('hl_bahan', $masterUpd, 'id = ?', [$bahanId]);
            }
        }

        TenantQuery::raw(
            "INSERT INTO hl_bahan_mutasi (tenant_id, outlet_id, bahan_id, tipe, jumlah, stok_sebelum, stok_sesudah, harga_beli, supplier, catatan, input_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)",
            [
                $row['tenant_id'], $row['outlet_id'], $row['bahan_id'], $row['tipe'],
                $row['jumlah'], $row['stok_sebelum'], $row['stok_sesudah'],
                $row['harga_beli'] ?? null, $row['supplier'] ?? null,
                $row['catatan'], $row['input_by']
            ]
        );

        logAudit('create','inventori',"Mutasi $tipe: {$stokRow['nama']} ({$row['jumlah']}) → stok: {$stokSesudah}");
        $minimumStok = intval($stokRow['stok_minimum'] ?? 0);
        if (in_array($tipe, ['keluar','adjust'], true) && $stokSesudah <= $minimumStok) {
            PushSender::send('stok_kritis', (int)$tid, (int)$oid, [
                'title' => 'Stok bahan kritis',
                'body'  => $stokRow['nama'] . ' sisa ' . $stokSesudah,
                'url'   => '/inventori',
            ]);
        }
        echo json_encode(['success' => true, 'stok_baru' => $stokSesudah]);
        exit;
    }

    // ─── DELETE BAHAN (soft via is_active=0) ──────────
    if ($action === 'delete_bahan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canManage) { echo json_encode(['error' => 'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $id = intval($d['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'ID tidak valid']); exit; }
        TenantQuery::update('hl_bahan', ['is_active' => 0], 'id = ?', [$id]);
        logAudit('delete','inventori',"Nonaktifkan bahan ID:$id");
        echo json_encode(['success' => true]);
        exit;
    }

    // ─── GET BAHAN (untuk modal edit) ─────────────────
    if ($action === 'get_bahan') {
        $id = intval($_GET['id'] ?? 0);
        $row = TenantQuery::rawOne(
            "SELECT * FROM hl_bahan_stok WHERE id = ? AND tenant_id = ? AND outlet_id = ?",
            [$id, $tid, $oid]
        );
        echo json_encode(['data' => $row]);
        exit;
    }

    // ─── OPNAME: list riwayat ──────────────────────────
    if ($action === 'opname_list') {
        $rows = TenantQuery::raw(
            "SELECT id, tanggal, status, total_item, total_selisih_item, nilai_selisih, finalized_at, created_at
             FROM hl_opname WHERE tenant_id=? AND outlet_id=? ORDER BY created_at DESC LIMIT 50",
            [$tid, $oid]
        );
        echo json_encode(['ok'=>true, 'rows'=>$rows]);
        exit;
    }

    // ─── OPNAME: buat sesi draft + snapshot ────────────
    if ($action === 'opname_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canManage) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $db = Database::get();
        $db->beginTransaction();
        try {
            $tgl = date('Y-m-d');
            $db->prepare("INSERT INTO hl_opname (tenant_id, outlet_id, tanggal, status, input_by)
                          VALUES (?,?,?, 'draft', ?)")
               ->execute([$tid, $oid, $tgl, (int)($user['id'] ?? 0)]);
            $opnameId = (int)$db->lastInsertId();

            // Snapshot semua bahan aktif outlet dari view
            $bahan = $db->prepare("SELECT id AS bahan_id, stok_terkini FROM hl_bahan_stok
                                   WHERE tenant_id=? AND outlet_id=? AND is_active=1 ORDER BY nama");
            $bahan->execute([$tid, $oid]);
            $rows = $bahan->fetchAll(PDO::FETCH_ASSOC);
            $ins = $db->prepare("INSERT INTO hl_opname_item (opname_id, tenant_id, bahan_id, stok_sistem)
                                 VALUES (?,?,?,?)");
            foreach ($rows as $b) {
                $ins->execute([$opnameId, $tid, (int)$b['bahan_id'], (int)$b['stok_terkini']]);
            }
            $db->prepare("UPDATE hl_opname SET total_item=? WHERE id=?")
               ->execute([count($rows), $opnameId]);
            $db->commit();
            echo json_encode(['ok'=>true, 'id'=>$opnameId]);
        } catch (Throwable $e) { $db->rollBack(); echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    // ─── OPNAME: detail sesi + items ───────────────────
    if ($action === 'opname_get') {
        $id = (int)($_GET['id'] ?? 0);
        $hdr = TenantQuery::rawOne(
            "SELECT * FROM hl_opname WHERE id=? AND tenant_id=? AND outlet_id=?",
            [$id, $tid, $oid]
        );
        if (!$hdr) { echo json_encode(['error'=>'Sesi tidak ditemukan']); exit; }
        $items = TenantQuery::raw(
            "SELECT oi.id, oi.bahan_id, oi.stok_sistem, oi.stok_fisik, oi.selisih,
                    b.nama, b.satuan, b.kategori
             FROM hl_opname_item oi
             JOIN hl_bahan b ON b.id=oi.bahan_id AND b.tenant_id=oi.tenant_id
             WHERE oi.opname_id=? AND oi.tenant_id=? ORDER BY b.nama",
            [$id, $tid]
        );
        echo json_encode(['ok'=>true, 'header'=>$hdr, 'items'=>$items]);
        exit;
    }

    // ─── OPNAME: simpan stok fisik (draft) ─────────────
    if ($action === 'opname_save_fisik' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canManage) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $opnameId = (int)($d['opname_id'] ?? 0);
        $items = $d['items'] ?? []; // [{item_id, stok_fisik}]
        $db = Database::get();
        // Pastikan sesi draft milik outlet ini
        $chk = $db->prepare("SELECT status FROM hl_opname WHERE id=? AND tenant_id=? AND outlet_id=?");
        $chk->execute([$opnameId, $tid, $oid]);
        $st = $chk->fetchColumn();
        if ($st === false) { echo json_encode(['error'=>'Sesi tidak ditemukan']); exit; }
        if ($st !== 'draft') { echo json_encode(['error'=>'Sesi sudah selesai']); exit; }

        $db->beginTransaction();
        try {
            $updNull = $db->prepare("UPDATE hl_opname_item SET stok_fisik=NULL, selisih=0
                                     WHERE id=? AND tenant_id=? AND opname_id=?");
            $updVal  = $db->prepare("UPDATE hl_opname_item SET stok_fisik=?, selisih=?-stok_sistem
                                     WHERE id=? AND tenant_id=? AND opname_id=?");
            foreach ($items as $it) {
                $itemId   = (int)($it['item_id'] ?? 0);
                $fisikRaw = $it['stok_fisik'];
                if ($fisikRaw === '' || $fisikRaw === null) {
                    $updNull->execute([$itemId, $tid, $opnameId]);
                } else {
                    $fisik = max(0, (int)$fisikRaw);
                    $updVal->execute([$fisik, $fisik, $itemId, $tid, $opnameId]);
                }
            }
            $db->commit();
            echo json_encode(['ok'=>true]);
        } catch (Throwable $e) { $db->rollBack(); echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    // ─── OPNAME: finalize (adjust + ringkasan) ─────────
    if ($action === 'opname_finalize' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canManage) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $opnameId = (int)($d['opname_id'] ?? 0);
        $db = Database::get();

        $hdr = $db->prepare("SELECT status, tanggal FROM hl_opname WHERE id=? AND tenant_id=? AND outlet_id=?");
        $hdr->execute([$opnameId, $tid, $oid]);
        $h = $hdr->fetch(PDO::FETCH_ASSOC);
        if (!$h) { echo json_encode(['error'=>'Sesi tidak ditemukan']); exit; }
        if ($h['status'] !== 'draft') { echo json_encode(['error'=>'Sesi sudah selesai']); exit; }

        $db->beginTransaction();
        try {
            // Lock header + re-check status di dalam transaksi (anti double-finalize)
            $lock = $db->prepare("SELECT status FROM hl_opname WHERE id=? AND tenant_id=? AND outlet_id=? FOR UPDATE");
            $lock->execute([$opnameId, $tid, $oid]);
            $lockedStatus = $lock->fetchColumn();
            if ($lockedStatus !== 'draft') {
                $db->rollBack();
                echo json_encode(['error'=>'Sesi sudah selesai']); exit;
            }

            // Items dgn fisik terisi + selisih != 0 (JOIN bahan untuk harga + outlet)
            $items = $db->prepare(
                "SELECT oi.id, oi.bahan_id, oi.stok_sistem, oi.stok_fisik, oi.selisih, b.harga_beli
                 FROM hl_opname_item oi
                 JOIN hl_bahan b ON b.id=oi.bahan_id AND b.tenant_id=oi.tenant_id
                 WHERE oi.opname_id=? AND oi.tenant_id=? AND oi.stok_fisik IS NOT NULL");
            $items->execute([$opnameId, $tid]);
            $rows = $items->fetchAll(PDO::FETCH_ASSOC);

            $totalSelisihItem = 0;
            $nilaiSelisih = 0;
            $insMut = $db->prepare(
                "INSERT INTO hl_bahan_mutasi
                   (tenant_id, outlet_id, bahan_id, tipe, jumlah, stok_sebelum, stok_sesudah, harga_beli, supplier, catatan, input_by)
                 VALUES (?,?,?, 'adjust', ?, ?, ?, NULL, NULL, ?, ?)");
            $linkItem = $db->prepare("UPDATE hl_opname_item SET mutasi_id=? WHERE id=?");
            foreach ($rows as $it) {
                $selisih = (int)$it['selisih'];
                if ($selisih === 0) continue;
                $insMut->execute([
                    $tid, $oid, (int)$it['bahan_id'],
                    abs($selisih), (int)$it['stok_sistem'], (int)$it['stok_fisik'],
                    "Opname #{$opnameId} " . $h['tanggal'], (int)($user['id'] ?? 0)
                ]);
                $linkItem->execute([(int)$db->lastInsertId(), (int)$it['id']]);
                $totalSelisihItem++;
                $nilaiSelisih += $selisih * (int)$it['harga_beli'];
            }

            $db->prepare("UPDATE hl_opname
                          SET status='selesai', finalized_at=NOW(),
                              total_selisih_item=?, nilai_selisih=?
                          WHERE id=? AND tenant_id=? AND status='draft'")
               ->execute([$totalSelisihItem, $nilaiSelisih, $opnameId, $tid]);

            $db->commit();
            echo json_encode(['ok'=>true, 'total_selisih_item'=>$totalSelisihItem, 'nilai_selisih'=>$nilaiSelisih]);
        } catch (Throwable $e) { $db->rollBack(); echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Inventori Bahan'); ?>
<style>
/* SUMMARY CARDS */
.inv-summary{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px}
.inv-card{background:var(--white);border-radius:var(--r-lg);padding:14px 16px;border:1px solid rgba(27,45,90,.07);box-shadow:var(--shadow);position:relative;overflow:hidden}
.inv-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px}
.inv-card.total::before  {background:linear-gradient(90deg,var(--teal),var(--teal-d))}
.inv-card.aman::before   {background:linear-gradient(90deg,var(--green),#34D399)}
.inv-card.minim::before  {background:linear-gradient(90deg,#F59E0B,#FBBF24)}
.inv-card.habis::before  {background:linear-gradient(90deg,#EF4444,#F87171)}
.inv-card.nilai::before  {background:linear-gradient(90deg,#8B5CF6,#A78BFA)}
.inv-num{font-size:1.4rem;font-weight:800;color:var(--navy);line-height:1;font-family:var(--mono);margin-bottom:4px}
.inv-num.green{color:var(--green)}
.inv-num.amber{color:#D97706}
.inv-num.red{color:#EF4444}
.inv-num.purple{color:#8B5CF6}
.inv-label{font-size:11px;color:var(--gray);font-weight:600;text-transform:uppercase;letter-spacing:.3px}

/* TABS */
.inv-tabs{display:flex;gap:4px;margin-bottom:18px;border-bottom:2px solid rgba(27,45,90,.08)}
.inv-tab{padding:10px 18px;cursor:pointer;font-family:var(--font);font-weight:600;font-size:14px;color:var(--gray);background:none;border:none;border-bottom:3px solid transparent;margin-bottom:-2px;transition:all .2s}
.inv-tab:hover{color:var(--navy)}
.inv-tab.active{color:var(--teal-d);border-bottom-color:var(--teal)}

/* TABLE */
.status-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:12px;font-size:11px;font-weight:700;text-transform:uppercase}
.status-aman {background:#D1FAE5;color:#065F46}
.status-minim{background:#FEF3C7;color:#92400E}
.status-habis{background:#FEE2E2;color:#991B1B}
.kategori-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;background:var(--light);color:var(--gray)}
.td-num{font-family:var(--mono);font-weight:700;text-align:right}
.td-stok-low{color:#D97706;font-weight:800}
.td-stok-out{color:#EF4444;font-weight:800}

/* FILTER PILL */
.kat-filter{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px}
.kat-pill{padding:6px 12px;border-radius:16px;font-size:12px;font-weight:600;border:1.5px solid rgba(27,45,90,.12);background:var(--off);cursor:pointer;font-family:var(--font);color:var(--navy);transition:all .2s}
.kat-pill:hover{border-color:var(--teal)}
.kat-pill.active{background:var(--teal);color:var(--navy);border-color:var(--teal)}

/* MODAL KELOLA - 3 actions */
.action-tabs{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:18px}
.action-btn{padding:14px;border-radius:var(--r);border:2px solid rgba(27,45,90,.12);background:var(--off);cursor:pointer;text-align:center;font-family:var(--font);font-weight:600;font-size:13px;transition:all .2s}
.action-btn.masuk.active {background:#D1FAE5;border-color:var(--green);color:#065F46}
.action-btn.keluar.active{background:#FEE2E2;border-color:#EF4444;color:#991B1B}
.action-btn.adjust.active{background:#FEF3C7;border-color:#F59E0B;color:#92400E}
.action-btn:not(.active):hover{border-color:var(--teal)}

/* MUTASI ROW STYLING */
.mut-tipe-masuk   {color:#065F46;font-weight:700}
.mut-tipe-keluar  {color:#991B1B;font-weight:700}
.mut-tipe-adjust  {color:#92400E;font-weight:700}
.mut-tipe-transfer{color:#1E40AF;font-weight:700}

@media(max-width:1100px){.inv-summary{grid-template-columns:repeat(3,1fr)}}
@media(max-width:680px){.inv-summary{grid-template-columns:repeat(2,1fr);gap:8px}.inv-card{padding:10px 12px}.inv-num{font-size:1.1rem}}
</style>
</head>
<body>
<?php renderTopbar('inventori'); ?>
<div class="hl-main">

  <div style="margin-bottom:16px">
    <h1 style="font-size:22px;font-weight:800;color:var(--navy);margin:0 0 4px">📦 Inventori Bahan Baku</h1>
    <p style="color:var(--gray);font-size:13px;margin:0">Track stok bahan habis pakai — deterjen, parfum, plastik, peralatan, dll</p>
  </div>

  <!-- SUMMARY -->
  <div class="inv-summary">
    <div class="inv-card total"><div class="inv-num" id="sumTotal">0</div><div class="inv-label">Total Item</div></div>
    <div class="inv-card aman"><div class="inv-num green" id="sumAman">0</div><div class="inv-label">✓ Aman</div></div>
    <div class="inv-card minim"><div class="inv-num amber" id="sumMinim">0</div><div class="inv-label">⚠️ Minim</div></div>
    <div class="inv-card habis"><div class="inv-num red" id="sumHabis">0</div><div class="inv-label">🔴 Habis</div></div>
    <div class="inv-card nilai"><div class="inv-num purple" id="sumNilai">Rp 0</div><div class="inv-label">💎 Nilai Inventori</div></div>
  </div>

  <!-- TABS -->
  <div class="inv-tabs">
    <button class="inv-tab active" onclick="switchTab('stok',this)">📦 Stok Bahan</button>
    <button class="inv-tab" onclick="switchTab('mutasi',this)">📜 Riwayat Mutasi</button>
    <button class="inv-tab" onclick="switchTab('alert',this)">⚠️ Restock Alert</button>
    <button class="inv-tab" onclick="switchTab('opname',this)">📋 Stok Opname</button>
  </div>

  <!-- ═════ TAB: STOK BAHAN ═════ -->
  <div id="tab-stok" class="tab-content">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;gap:12px;flex-wrap:wrap">
      <div class="kat-filter">
        <button class="kat-pill active" data-kat="" onclick="filterKat('',this)">Semua</button>
        <button class="kat-pill" data-kat="deterjen" onclick="filterKat('deterjen',this)">🧴 Deterjen</button>
        <button class="kat-pill" data-kat="parfum" onclick="filterKat('parfum',this)">🌸 Parfum</button>
        <button class="kat-pill" data-kat="pewangi" onclick="filterKat('pewangi',this)">💧 Pewangi</button>
        <button class="kat-pill" data-kat="plastik_kemasan" onclick="filterKat('plastik_kemasan',this)">📦 Plastik</button>
        <button class="kat-pill" data-kat="peralatan" onclick="filterKat('peralatan',this)">🔧 Peralatan</button>
        <button class="kat-pill" data-kat="lainnya" onclick="filterKat('lainnya',this)">📋 Lainnya</button>
      </div>
      <?php if ($canManage): ?>
      <button class="hl-btn hl-btn-primary" onclick="openBahanModal()">➕ Tambah Bahan</button>
      <?php endif; ?>
    </div>

    <div class="hl-card">
      <div class="hl-table-wrap">
        <table class="hl-table">
          <thead>
            <tr>
              <th>Nama Bahan</th>
              <th>Kategori</th>
              <th class="td-num">Stok</th>
              <th class="td-num">Min</th>
              <th>Status</th>
              <th class="td-num">Harga Beli</th>
              <th>Supplier</th>
              <th style="width:140px">Aksi</th>
            </tr>
          </thead>
          <tbody id="bodyStok">
            <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--gray)">⏳ Memuat data...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ═════ TAB: RIWAYAT MUTASI ═════ -->
  <div id="tab-mutasi" class="tab-content" style="display:none">
    <div class="hl-filter-bar" style="margin-bottom:14px">
      <label style="font-size:12px;font-weight:700;color:var(--navy)">Dari</label>
      <input type="date" id="mDari" class="hl-input" style="width:auto" onchange="loadMutasi()"/>
      <label style="font-size:12px;font-weight:700;color:var(--navy)">s/d</label>
      <input type="date" id="mSampai" class="hl-input" style="width:auto" onchange="loadMutasi()"/>
      <select id="mTipe" class="hl-input" style="width:auto" onchange="loadMutasi()">
        <option value="">Semua Tipe</option>
        <option value="masuk">Masuk</option>
        <option value="keluar">Keluar</option>
        <option value="adjust">Adjust</option>
        <option value="transfer">Transfer</option>
      </select>
      <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadMutasi()" style="margin-left:auto">🔄 Refresh</button>
    </div>

    <div class="hl-card">
      <div class="hl-table-wrap">
        <table class="hl-table">
          <thead>
            <tr>
              <th>Tanggal</th>
              <th>Bahan</th>
              <th>Tipe</th>
              <th class="td-num">Jumlah</th>
              <th class="td-num">Stok Sblm</th>
              <th class="td-num">Stok Ssdh</th>
              <th>Catatan</th>
              <th>Input By</th>
            </tr>
          </thead>
          <tbody id="bodyMutasi">
            <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--gray)">⏳ Memuat data...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ═════ TAB: ALERT ═════ -->
  <div id="tab-alert" class="tab-content" style="display:none">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <p style="color:var(--gray);font-size:14px;margin:0">Bahan yang stoknya <strong>menipis atau habis</strong>. Klik <em>Cetak Daftar Belanja</em> untuk export PDF.</p>
      <a href="/api/inventori_po.php?auto_print=1" target="_blank" class="hl-btn hl-btn-primary">🖨️ Cetak Daftar Belanja</a>
    </div>
    <div class="hl-card">
      <div class="hl-table-wrap">
        <table class="hl-table">
          <thead>
            <tr>
              <th>Nama Bahan</th>
              <th>Kategori</th>
              <th class="td-num">Stok Sekarang</th>
              <th class="td-num">Stok Minimum</th>
              <th class="td-num">Perlu Beli</th>
              <th>Supplier</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody id="bodyAlert">
            <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--gray)">⏳ Memuat data...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ═════ TAB: STOK OPNAME ═════ -->
  <div id="tab-opname" class="tab-content" style="display:none">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <h3 style="margin:0;font-size:15px">📋 Riwayat Stok Opname</h3>
      <?php if ($canManage): ?>
      <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="opnameCreate()">+ Mulai Opname Baru</button>
      <?php endif; ?>
    </div>
    <div id="opnameListWrap"><div style="color:#9CA3AF;padding:20px;text-align:center">Memuat…</div></div>
    <div id="opnameDetailWrap" style="display:none;margin-top:16px"></div>
  </div>

</div>

<!-- ═════ MODAL: TAMBAH/EDIT BAHAN ═════ -->
<div class="hl-modal-overlay" id="modalBahan">
  <div class="hl-modal hl-modal-sm">
    <div class="hl-modal-header">
      <span class="hl-modal-title" id="bahanModalTitle">➕ Tambah Bahan</span>
      <button class="hl-modal-close" onclick="closeModal('modalBahan')">×</button>
    </div>
    <div class="hl-modal-body">
      <input type="hidden" id="b_id" value=""/>
      <div class="hl-form-row">
        <div class="hl-form-group" style="flex:2">
          <label class="hl-label">Nama Bahan <span class="req">*</span></label>
          <input type="text" id="b_nama" class="hl-input" placeholder="Misal: Deterjen Bukrim 1kg" maxlength="100"/>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Satuan</label>
          <input type="text" id="b_satuan" class="hl-input" placeholder="pcs / kg / liter" value="pcs" maxlength="20"/>
        </div>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Kategori</label>
        <select id="b_kategori" class="hl-input">
          <option value="deterjen">🧴 Deterjen</option>
          <option value="parfum">🌸 Parfum</option>
          <option value="pewangi">💧 Pewangi</option>
          <option value="plastik_kemasan">📦 Plastik Kemasan</option>
          <option value="peralatan">🔧 Peralatan</option>
          <option value="lainnya" selected>📋 Lainnya</option>
        </select>
      </div>
      <div class="hl-form-row">
        <div class="hl-form-group" id="grpStokAwal">
          <label class="hl-label">Stok Awal</label>
          <input type="number" id="b_stok_awal" class="hl-input" value="0" min="0"/>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Stok Minimum <span class="req">*</span></label>
          <input type="number" id="b_stok_min" class="hl-input" value="5" min="0"/>
        </div>
      </div>
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Harga Beli (Rp)</label>
          <input type="number" id="b_harga" class="hl-input" value="0" min="0" step="500"/>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Supplier</label>
          <input type="text" id="b_supplier" class="hl-input" placeholder="Toko / vendor" maxlength="100"/>
        </div>
      </div>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeModal('modalBahan')">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="saveBahan()">💾 Simpan</button>
    </div>
  </div>
</div>

<!-- ═════ MODAL: KELOLA (MUTASI) ═════ -->
<div class="hl-modal-overlay" id="modalKelola">
  <div class="hl-modal hl-modal-sm">
    <div class="hl-modal-header">
      <span class="hl-modal-title" id="kelolaTitle">Kelola Bahan</span>
      <button class="hl-modal-close" onclick="closeModal('modalKelola')">×</button>
    </div>
    <div class="hl-modal-body">
      <div style="background:var(--off);padding:14px;border-radius:10px;margin-bottom:16px">
        <div style="font-size:13px;color:var(--gray);margin-bottom:4px">Stok Sekarang</div>
        <div id="kelolaStokInfo" style="font-size:1.4rem;font-weight:800;font-family:var(--mono);color:var(--navy)">— pcs</div>
      </div>

      <input type="hidden" id="k_bahan_id" value=""/>

      <div class="action-tabs">
        <button class="action-btn masuk active" id="actMasuk" onclick="setAction('masuk')">➕ Restock</button>
        <button class="action-btn keluar" id="actKeluar" onclick="setAction('keluar')">➖ Pemakaian</button>
        <button class="action-btn adjust" id="actAdjust" onclick="setAction('adjust')">⚖️ Adjust</button>
      </div>

      <!-- MASUK -->
      <div id="formMasuk" class="form-mutasi">
        <div class="hl-form-row">
          <div class="hl-form-group">
            <label class="hl-label">Jumlah Restock <span class="req">*</span></label>
            <input type="number" id="m_jumlah" class="hl-input" value="" min="1" placeholder="0"/>
          </div>
          <div class="hl-form-group">
            <label class="hl-label">Harga Beli/Satuan (Rp)</label>
            <input type="number" id="m_harga" class="hl-input" value="0" min="0" step="500"/>
          </div>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Supplier (opsional)</label>
          <input type="text" id="m_supplier" class="hl-input" placeholder="Toko Kimia ABC" maxlength="100"/>
        </div>
      </div>

      <!-- KELUAR -->
      <div id="formKeluar" class="form-mutasi" style="display:none">
        <div class="hl-form-group">
          <label class="hl-label">Jumlah Pemakaian <span class="req">*</span></label>
          <input type="number" id="k_jumlah" class="hl-input" value="" min="1" placeholder="0"/>
          <small style="color:var(--gray);font-size:12px">Berapa yang dipakai/keluar hari ini</small>
        </div>
      </div>

      <!-- ADJUST -->
      <div id="formAdjust" class="form-mutasi" style="display:none">
        <div class="hl-form-group">
          <label class="hl-label">Stok Aktual Sekarang <span class="req">*</span></label>
          <input type="number" id="a_stok" class="hl-input" value="" min="0" placeholder="Hitung manual"/>
          <small style="color:var(--gray);font-size:12px">Sistem akan koreksi otomatis dari stok tercatat</small>
        </div>
      </div>

      <div class="hl-form-group" style="margin-top:12px">
        <label class="hl-label">Catatan (opsional)</label>
        <input type="text" id="mut_catatan" class="hl-input" maxlength="200" placeholder="Misal: restock dari Toko Kimia, susut akibat tumpah, dll"/>
      </div>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeModal('modalKelola')">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="saveMutasi()">💾 Simpan</button>
    </div>
  </div>
</div>

<script>
const CAN_MANAGE = <?= $canManage ? 'true' : 'false' ?>;
function CSRF() { return csrfToken(); }
let currentTab = 'stok';
let currentKat = '';
let currentAction = 'masuk';

// ── TAB SWITCH ─────────────────────────────────────
function switchTab(tab, el) {
  currentTab = tab;
  document.querySelectorAll('.inv-tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  document.querySelectorAll('.tab-content').forEach(t => t.style.display = 'none');
  document.getElementById('tab-' + tab).style.display = 'block';
  if (tab === 'stok')   loadStok();
  if (tab === 'mutasi') loadMutasi();
  if (tab === 'alert')  loadAlert();
  if (tab === 'opname') loadOpnameList();
}

// ── FILTER KATEGORI ────────────────────────────────
function filterKat(kat, el) {
  currentKat = kat;
  document.querySelectorAll('.kat-pill').forEach(p => p.classList.remove('active'));
  el.classList.add('active');
  loadStok();
}

// ── LOAD STOK ──────────────────────────────────────
async function loadStok() {
  const url = '/inventori.php?action=list_stok' + (currentKat ? '&kategori=' + currentKat : '');
  const r = await fetch(url);
  const j = await r.json();

  document.getElementById('sumTotal').textContent = j.summary.total;
  document.getElementById('sumAman').textContent  = j.summary.aman;
  document.getElementById('sumMinim').textContent = j.summary.minim;
  document.getElementById('sumHabis').textContent = j.summary.habis;
  document.getElementById('sumNilai').textContent = 'Rp ' + fmtNum(j.summary.nilai_inventori);

  const tbody = document.getElementById('bodyStok');
  if (!j.data.length) {
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:40px;color:var(--gray)">Belum ada bahan terdaftar. ' + (CAN_MANAGE ? 'Klik <strong>Tambah Bahan</strong> untuk mulai.' : '') + '</td></tr>';
    return;
  }
  tbody.innerHTML = j.data.map(b => {
    const stokCls = b.status_stok === 'habis' ? 'td-stok-out' : (b.status_stok === 'minim' ? 'td-stok-low' : '');
    const statusBadge = b.status_stok === 'habis'
      ? '<span class="status-badge status-habis">🔴 Habis</span>'
      : b.status_stok === 'minim'
      ? '<span class="status-badge status-minim">⚠️ Minim</span>'
      : '<span class="status-badge status-aman">✓ Aman</span>';
    return `
      <tr>
        <td><strong>${esc(b.nama)}</strong></td>
        <td><span class="kategori-badge">${katLabel(b.kategori)}</span></td>
        <td class="td-num ${stokCls}">${b.stok_terkini} <small style="color:var(--gray);font-weight:500">${esc(b.satuan)}</small></td>
        <td class="td-num">${b.stok_minimum}</td>
        <td>${statusBadge}</td>
        <td class="td-num">${b.harga_beli > 0 ? 'Rp ' + fmtNum(b.harga_beli) : '-'}</td>
        <td>${esc(b.supplier || '-')}</td>
        <td>
          ${CAN_MANAGE ? `<button class="hl-btn hl-btn-sm hl-btn-primary" onclick="openKelola(${b.id})">📋 Kelola</button>
                          <button class="hl-btn hl-btn-sm hl-btn-outline" onclick="editBahan(${b.id})" title="Edit master">✏️</button>` : '-'}
        </td>
      </tr>`;
  }).join('');
}

// ── LOAD MUTASI ────────────────────────────────────
async function loadMutasi() {
  const dari   = document.getElementById('mDari').value;
  const sampai = document.getElementById('mSampai').value;
  const tipe   = document.getElementById('mTipe').value;
  const url = `/inventori.php?action=list_mutasi&dari=${dari}&sampai=${sampai}&tipe=${tipe}`;
  const r = await fetch(url);
  const j = await r.json();

  const tbody = document.getElementById('bodyMutasi');
  if (!j.data.length) {
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:40px;color:var(--gray)">Tidak ada riwayat mutasi di periode ini.</td></tr>';
    return;
  }
  tbody.innerHTML = j.data.map(m => {
    const tipeBadge = `<span class="mut-tipe-${m.tipe}">${tipeIcon(m.tipe)} ${m.tipe.toUpperCase()}</span>`;
    const jumlahDisplay = m.tipe === 'adjust'
      ? `${m.stok_sesudah > m.stok_sebelum ? '+' : '-'}${Math.abs(m.stok_sesudah - m.stok_sebelum)}`
      : (m.tipe === 'masuk' ? '+' : '-') + m.jumlah;
    return `
      <tr>
        <td>${fmtDate(m.created_at)}</td>
        <td><strong>${esc(m.bahan_nama)}</strong></td>
        <td>${tipeBadge}</td>
        <td class="td-num">${jumlahDisplay} ${esc(m.satuan)}</td>
        <td class="td-num">${m.stok_sebelum}</td>
        <td class="td-num"><strong>${m.stok_sesudah}</strong></td>
        <td>${esc(m.catatan || '-')}</td>
        <td>${esc(m.input_by_nama || '-')}</td>
      </tr>`;
  }).join('');
}

// ── LOAD ALERT ─────────────────────────────────────
async function loadAlert() {
  const r = await fetch('/inventori.php?action=list_alert');
  const j = await r.json();
  const tbody = document.getElementById('bodyAlert');
  if (!j.data.length) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:40px;color:var(--green)">✅ Tidak ada bahan yang perlu di-restock. Stok semua aman!</td></tr>';
    return;
  }
  tbody.innerHTML = j.data.map(b => {
    const perluBeli = Math.max(0, (b.stok_minimum * 2) - b.stok_terkini);
    const statusBadge = b.stok_terkini <= 0
      ? '<span class="status-badge status-habis">🔴 Habis</span>'
      : '<span class="status-badge status-minim">⚠️ Minim</span>';
    return `
      <tr>
        <td><strong>${esc(b.nama)}</strong></td>
        <td><span class="kategori-badge">${katLabel(b.kategori)}</span></td>
        <td class="td-num ${b.stok_terkini <= 0 ? 'td-stok-out' : 'td-stok-low'}">${b.stok_terkini} ${esc(b.satuan)}</td>
        <td class="td-num">${b.stok_minimum}</td>
        <td class="td-num" style="color:#1E40AF;font-weight:800">${perluBeli} ${esc(b.satuan)}</td>
        <td>${esc(b.supplier || '-')}</td>
        <td>${statusBadge}</td>
      </tr>`;
  }).join('');
}

// ── MODAL: TAMBAH/EDIT BAHAN ───────────────────────
function openBahanModal() {
  document.getElementById('bahanModalTitle').textContent = '➕ Tambah Bahan';
  document.getElementById('b_id').value = '';
  document.getElementById('b_nama').value = '';
  document.getElementById('b_satuan').value = 'pcs';
  document.getElementById('b_kategori').value = 'lainnya';
  document.getElementById('b_stok_awal').value = '0';
  document.getElementById('b_stok_min').value = '5';
  document.getElementById('b_harga').value = '0';
  document.getElementById('b_supplier').value = '';
  document.getElementById('grpStokAwal').style.display = 'block';
  document.getElementById('modalBahan').classList.add('open');
}

async function editBahan(id) {
  const r = await fetch('/inventori.php?action=get_bahan&id=' + id);
  const j = await r.json();
  if (!j.data) { showToast('Bahan tidak ditemukan', 'error'); return; }
  const b = j.data;
  document.getElementById('bahanModalTitle').textContent = '✏️ Edit Bahan';
  document.getElementById('b_id').value = b.id;
  document.getElementById('b_nama').value = b.nama;
  document.getElementById('b_satuan').value = b.satuan;
  document.getElementById('b_kategori').value = b.kategori;
  document.getElementById('b_stok_awal').value = b.stok_awal;
  document.getElementById('b_stok_min').value = b.stok_minimum;
  document.getElementById('b_harga').value = b.harga_beli;
  document.getElementById('b_supplier').value = b.supplier || '';
  document.getElementById('grpStokAwal').style.display = 'none'; // gak boleh ubah stok awal saat edit
  document.getElementById('modalBahan').classList.add('open');
}

async function saveBahan() {
  const data = {
    id:           document.getElementById('b_id').value,
    nama:         document.getElementById('b_nama').value,
    satuan:       document.getElementById('b_satuan').value,
    kategori:     document.getElementById('b_kategori').value,
    stok_awal:    document.getElementById('b_stok_awal').value,
    stok_minimum: document.getElementById('b_stok_min').value,
    harga_beli:   document.getElementById('b_harga').value,
    supplier:     document.getElementById('b_supplier').value,
  };
  if (!data.nama.trim()) { showToast('Nama bahan wajib diisi', 'error'); return; }

  const r = await fetch('/inventori.php?action=save_bahan', {
    method: 'POST',
    headers: { 'Content-Type':'application/json', 'X-CSRF-Token': CSRF() },
    body: JSON.stringify(data)
  });
  const j = await r.json();
  if (j.error) { showToast(j.error, 'error'); return; }
  showToast('Bahan tersimpan');
  closeModal('modalBahan');
  loadStok();
}

// ── MODAL: KELOLA (MUTASI) ─────────────────────────
async function openKelola(id) {
  const r = await fetch('/inventori.php?action=get_bahan&id=' + id);
  const j = await r.json();
  if (!j.data) { showToast('Bahan tidak ditemukan', 'error'); return; }
  const b = j.data;
  document.getElementById('kelolaTitle').textContent = b.nama;
  document.getElementById('k_bahan_id').value = b.id;
  document.getElementById('kelolaStokInfo').textContent = b.stok_terkini + ' ' + b.satuan;
  document.getElementById('m_jumlah').value = '';
  document.getElementById('k_jumlah').value = '';
  document.getElementById('a_stok').value = b.stok_terkini;
  document.getElementById('m_harga').value = b.harga_beli || 0;
  document.getElementById('m_supplier').value = b.supplier || '';
  document.getElementById('mut_catatan').value = '';
  setAction('masuk');
  document.getElementById('modalKelola').classList.add('open');
}

function setAction(act) {
  currentAction = act;
  ['masuk','keluar','adjust'].forEach(a => {
    document.getElementById('act' + capitalize(a)).classList.toggle('active', a === act);
    document.getElementById('form' + capitalize(a)).style.display = a === act ? 'block' : 'none';
  });
}

async function saveMutasi() {
  const bahanId = parseInt(document.getElementById('k_bahan_id').value);
  if (!bahanId) return;
  const data = {
    bahan_id: bahanId,
    tipe:     currentAction,
    catatan:  document.getElementById('mut_catatan').value,
  };
  if (currentAction === 'masuk') {
    data.jumlah     = parseInt(document.getElementById('m_jumlah').value) || 0;
    data.harga_beli = parseInt(document.getElementById('m_harga').value) || 0;
    data.supplier   = document.getElementById('m_supplier').value;
    if (data.jumlah <= 0) { showToast('Jumlah restock harus > 0', 'error'); return; }
  } else if (currentAction === 'keluar') {
    data.jumlah = parseInt(document.getElementById('k_jumlah').value) || 0;
    if (data.jumlah <= 0) { showToast('Jumlah pemakaian harus > 0', 'error'); return; }
  } else if (currentAction === 'adjust') {
    data.stok_aktual = parseInt(document.getElementById('a_stok').value);
    if (isNaN(data.stok_aktual) || data.stok_aktual < 0) { showToast('Stok aktual tidak valid', 'error'); return; }
  }

  const r = await fetch('/inventori.php?action=save_mutasi', {
    method:'POST',
    headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': CSRF() },
    body: JSON.stringify(data)
  });
  const j = await r.json();
  if (j.error) { showToast(j.error, 'error'); return; }
  showToast('Mutasi tersimpan. Stok baru: ' + j.stok_baru);
  closeModal('modalKelola');
  loadStok();
}

// ── HELPERS lokal — esc/fmtNum/fmtDate/katLabelInventori sudah global di components.php ──
const katLabel = window.katLabelInventori;
function tipeIcon(t) { return { masuk:'⬆️', keluar:'⬇️', adjust:'⚖️', transfer:'↔️' }[t] || ''; }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
// showToast() global sudah di-inject oleh renderToast()

// ── STOK OPNAME ────────────────────────────────────
async function loadOpnameList() {
  const wrap = document.getElementById('opnameListWrap');
  document.getElementById('opnameDetailWrap').style.display = 'none';
  document.getElementById('opnameListWrap').style.display = 'block';
  wrap.innerHTML = '<div style="color:#9CA3AF;padding:20px;text-align:center">Memuat…</div>';
  const r = await fetch('/inventori.php?action=opname_list');
  const d = await r.json();
  if (!d.ok) { wrap.innerHTML = '<div style="color:#DC2626;padding:20px">'+esc(d.error||'Gagal')+'</div>'; return; }
  if (!d.rows.length) { wrap.innerHTML = '<div style="color:#9CA3AF;padding:20px;text-align:center">Belum ada opname.</div>'; return; }
  let h = '<table class="hl-table"><thead><tr><th>Tanggal</th><th>Status</th><th class="td-num">Item</th><th class="td-num">Selisih</th><th class="td-num">Nilai</th><th></th></tr></thead><tbody>';
  d.rows.forEach(o => {
    const badge = o.status==='selesai' ? '<span style="color:#059669">✓ Selesai</span>' : '<span style="color:#92400E">Draft</span>';
    const nilai = o.status==='selesai' ? fmtNilai(o.nilai_selisih) : '-';
    const aksi = o.status==='selesai'
      ? `<button class="hl-btn hl-btn-outline hl-btn-sm" onclick="opnameOpen(${parseInt(o.id)})">👁 Detail</button>`
      : `<button class="hl-btn hl-btn-primary hl-btn-sm" onclick="opnameOpen(${parseInt(o.id)})">Lanjut</button>`;
    h += `<tr><td>${new Date(o.tanggal).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'})}</td>
      <td>${badge}</td><td class="td-num">${parseInt(o.total_item)||0}</td>
      <td class="td-num">${o.status==='selesai'?parseInt(o.total_selisih_item)||0:'-'}</td>
      <td class="td-num">${nilai}</td><td>${aksi}</td></tr>`;
  });
  wrap.innerHTML = h + '</tbody></table>';
}

function fmtNilai(n) { n=parseInt(n)||0; const s=n<0?'−':(n>0?'+':''); return s+'Rp '+Math.abs(n).toLocaleString('id-ID'); }

async function opnameCreate() {
  if (!confirm('Mulai sesi opname baru? Stok sistem akan di-snapshot sekarang.')) return;
  const r = await fetch('/inventori.php?action=opname_create', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF()},
    body: JSON.stringify({})
  });
  const d = await r.json();
  if (!d.ok) { showToast(d.error||'Gagal membuat opname', 'error'); return; }
  opnameOpen(d.id);
}

async function opnameOpen(id) {
  const r = await fetch('/inventori.php?action=opname_get&id='+id);
  const d = await r.json();
  if (!d.ok) { showToast(d.error||'Gagal membuka opname', 'error'); return; }
  const h = d.header, items = d.items;
  const isDraft = h.status === 'draft';
  const wrap = document.getElementById('opnameDetailWrap');
  document.getElementById('opnameListWrap').style.display = 'none';
  wrap.style.display = 'block';

  let rows = items.map(it => {
    const fisik = it.stok_fisik === null ? '' : it.stok_fisik;
    const sel = it.stok_fisik === null ? '-' : (it.selisih>0?'+'+it.selisih:it.selisih);
    const selColor = it.selisih<0?'#DC2626':(it.selisih>0?'#059669':'#6B7280');
    const inputCell = isDraft
      ? `<input type="number" min="0" data-item="${parseInt(it.id)}" value="${esc(String(fisik))}" oninput="opnameRecalc(this,${parseInt(it.stok_sistem)})" style="width:80px;padding:4px 8px;border:1px solid #D1D5DB;border-radius:6px">`
      : (it.stok_fisik===null?'-':it.stok_fisik);
    return `<tr><td>${esc(it.nama)}</td><td>${esc(it.satuan)}</td><td class="td-num">${parseInt(it.stok_sistem)}</td>
      <td class="td-num">${inputCell}</td><td class="td-num sel-cell" style="color:${selColor}">${sel}</td></tr>`;
  }).join('');

  const tglLabel = new Date(h.tanggal).toLocaleDateString('id-ID');
  const statusLabel = isDraft ? '<span style="color:#92400E;font-size:12px">[Draft]</span>' : '<span style="color:#059669;font-size:12px">[Selesai]</span>';
  wrap.innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
      <h3 style="margin:0;font-size:15px">Opname ${tglLabel} ${statusLabel}</h3>
      <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadOpnameList()">← Kembali</button>
    </div>
    <table class="hl-table"><thead><tr><th>Bahan</th><th>Satuan</th><th class="td-num">Sistem</th><th class="td-num">Fisik</th><th class="td-num">Selisih</th></tr></thead><tbody>${rows}</tbody></table>
    ${isDraft ? `<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
      <button class="hl-btn hl-btn-outline" onclick="opnameSave(${parseInt(id)})">💾 Simpan Draft</button>
      <button class="hl-btn hl-btn-primary" onclick="opnameFinalize(${parseInt(id)})">✅ Finalize &amp; Adjust</button></div>` : ''}`;
}

function opnameRecalc(input, sistem) {
  const fisik = input.value === '' ? null : parseInt(input.value);
  const cell = input.closest('tr').querySelector('.sel-cell');
  if (fisik === null || isNaN(fisik)) { cell.textContent='-'; cell.style.color='#6B7280'; return; }
  const sel = fisik - sistem;
  cell.textContent = sel>0?'+'+sel:sel;
  cell.style.color = sel<0?'#DC2626':(sel>0?'#059669':'#6B7280');
}

function collectItems() {
  return [...document.querySelectorAll('#opnameDetailWrap input[data-item]')].map(i => ({
    item_id: parseInt(i.dataset.item), stok_fisik: i.value
  }));
}

async function opnameSave(id) {
  const r = await fetch('/inventori.php?action=opname_save_fisik', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF()},
    body: JSON.stringify({opname_id:id, items:collectItems()})
  });
  const d = await r.json();
  if (!d.ok) { showToast(d.error||'Gagal menyimpan', 'error'); return; }
  showToast('Draft tersimpan');
}

async function opnameFinalize(id) {
  await opnameSave(id);
  if (!confirm('Finalize opname? Selisih akan jadi penyesuaian stok permanen.')) return;
  const r = await fetch('/inventori.php?action=opname_finalize', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF()},
    body: JSON.stringify({opname_id:id})
  });
  const d = await r.json();
  if (!d.ok) { showToast(d.error||'Gagal finalize', 'error'); return; }
  showToast('Opname selesai. '+d.total_selisih_item+' bahan disesuaikan, nilai selisih '+fmtNilai(d.nilai_selisih)+'.');
  loadOpnameList();
}

// ── INIT ───────────────────────────────────────────
(function init() {
  const today = new Date().toISOString().split('T')[0];
  const firstDay = today.substring(0,8) + '01';
  document.getElementById('mDari').value = firstDay;
  document.getElementById('mSampai').value = today;
  loadStok();
})();
</script>

<?php renderToast(); ?>
</body>
</html>

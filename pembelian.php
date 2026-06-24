<?php
// ══════════════════════════════════════════════════════
// pembelian.php — Purchase Order per Outlet
//
// Backend skeleton untuk PO bahan baku: list, buat draft,
// simpan item, dan dropdown supplier/bahan. UI menyusul
// di Task 5. Dipesan/terima di Task 4.
// ══════════════════════════════════════════════════════
$activePage = 'pembelian';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once __DIR__ . '/components.php';

$user = currentUser();

// Permission: butuh inventori.manage atau kas.create
if (!hasPermission('inventori.manage') && !hasPermission('kas.create')) {
    requirePermission('inventori.manage'); // exit dengan pesan akses ditolak
}
$canManage = hasPermission('inventori.manage') || hasPermission('kas.create');

// ── Helper: Generate nomor PO unik (PO/YYYY/MM/000N) ─
function generatePoNo(PDO $db, int $tid): string
{
    $ym     = date('Y/m');
    $prefix = "PO/$ym/";
    $s = $db->prepare("SELECT COUNT(*) FROM hl_po WHERE tenant_id = ? AND no_po LIKE ?");
    $s->execute([$tid, $prefix . '%']);
    return $prefix . str_pad((string)((int)$s->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);
}

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $tid = TenantResolver::id();
    $oid = TenantResolver::outletId();

    // ─── PO LIST ──────────────────────────────────────
    if ($action === 'po_list') {
        $rows = TenantQuery::raw(
            "SELECT p.id, p.no_po, p.tanggal, p.status, p.total, s.nama AS supplier_nama
             FROM hl_po p
             LEFT JOIN hl_supplier s ON s.id = p.supplier_id AND s.tenant_id = p.tenant_id
             WHERE p.tenant_id = ? AND p.outlet_id = ?
             ORDER BY p.created_at DESC LIMIT 50",
            [$tid, $oid]
        );
        echo json_encode(['ok' => true, 'rows' => $rows]);
        exit;
    }

    // ─── SUPPLIER OPTS ────────────────────────────────
    if ($action === 'supplier_opts') {
        $rows = TenantQuery::raw(
            "SELECT id, nama FROM hl_supplier
             WHERE tenant_id = ? AND is_active = 1
             ORDER BY nama",
            [$tid]
        );
        echo json_encode(['ok' => true, 'data' => $rows]);
        exit;
    }

    // ─── BAHAN OPTS ───────────────────────────────────
    if ($action === 'bahan_opts') {
        $rows = TenantQuery::raw(
            "SELECT id, nama, satuan, harga_beli
             FROM hl_bahan
             WHERE tenant_id = ? AND outlet_id = ? AND is_active = 1
             ORDER BY nama",
            [$tid, $oid]
        );
        echo json_encode(['ok' => true, 'data' => $rows]);
        exit;
    }

    // ─── PO CREATE (draft + no_po generator) ──────────
    if ($action === 'po_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canManage) { echo json_encode(['error' => 'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];

        $supplierId = (int)($d['supplier_id'] ?? 0);
        $tgl = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['tanggal'] ?? '') ? $d['tanggal'] : date('Y-m-d');

        if (!$supplierId) { echo json_encode(['error' => 'Pilih supplier']); exit; }

        $db = Database::get();

        // Validasi supplier milik tenant + aktif
        $sc = $db->prepare("SELECT 1 FROM hl_supplier WHERE id = ? AND tenant_id = ? AND is_active = 1");
        $sc->execute([$supplierId, $tid]);
        if (!$sc->fetchColumn()) { echo json_encode(['error' => 'Supplier tidak valid']); exit; }

        $noPo = generatePoNo($db, (int)$tid);
        $db->prepare(
            "INSERT INTO hl_po (tenant_id, outlet_id, supplier_id, no_po, tanggal, status, input_by)
             VALUES (?, ?, ?, ?, ?, 'draft', ?)"
        )->execute([$tid, $oid, $supplierId, $noPo, $tgl, (int)($user['id'] ?? 0)]);

        echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId(), 'no_po' => $noPo]);
        exit;
    }

    // ─── PO GET (header + items) ──────────────────────
    if ($action === 'po_get') {
        $id  = (int)($_GET['id'] ?? 0);
        $hdr = TenantQuery::rawOne(
            "SELECT p.*, s.nama AS supplier_nama
             FROM hl_po p
             LEFT JOIN hl_supplier s ON s.id = p.supplier_id AND s.tenant_id = p.tenant_id
             WHERE p.id = ? AND p.tenant_id = ? AND p.outlet_id = ?",
            [$id, $tid, $oid]
        );
        if (!$hdr) { echo json_encode(['error' => 'PO tidak ditemukan']); exit; }

        $items = TenantQuery::raw(
            "SELECT i.id, i.bahan_id, i.qty, i.harga_satuan, i.subtotal,
                    b.nama, b.satuan
             FROM hl_po_item i
             JOIN hl_bahan b ON b.id = i.bahan_id AND b.tenant_id = i.tenant_id
             WHERE i.po_id = ? AND i.tenant_id = ?
             ORDER BY i.id",
            [$id, $tid]
        );
        echo json_encode(['ok' => true, 'header' => $hdr, 'items' => $items]);
        exit;
    }

    // ─── PO SAVE ITEMS (draft only, replace + recompute total) ──
    if ($action === 'po_save_items' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canManage) { echo json_encode(['error' => 'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];

        $poId  = (int)($d['po_id'] ?? 0);
        $items = $d['items'] ?? []; // [{bahan_id, qty, harga_satuan}]

        $db = Database::get();

        // Pastikan PO milik outlet ini dan masih draft
        $chk = $db->prepare("SELECT status FROM hl_po WHERE id = ? AND tenant_id = ? AND outlet_id = ?");
        $chk->execute([$poId, $tid, $oid]);
        $st = $chk->fetchColumn();
        if ($st === false) { echo json_encode(['error' => 'PO tidak ditemukan']); exit; }
        if ($st !== 'draft') { echo json_encode(['error' => 'PO sudah dipesan/diterima']); exit; }

        $db->beginTransaction();
        try {
            // Hapus item lama lalu sisipkan ulang
            $db->prepare("DELETE FROM hl_po_item WHERE po_id = ? AND tenant_id = ?")
               ->execute([$poId, $tid]);

            $ins = $db->prepare(
                "INSERT INTO hl_po_item (po_id, tenant_id, bahan_id, qty, harga_satuan, subtotal)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );

            // Validasi bahan milik outlet ini + aktif
            $bahanChk = $db->prepare(
                "SELECT 1 FROM hl_bahan WHERE id = ? AND tenant_id = ? AND outlet_id = ? AND is_active = 1"
            );

            $total = 0;
            foreach ($items as $it) {
                $bahanId = (int)($it['bahan_id'] ?? 0);
                $qty     = max(1, (int)($it['qty'] ?? 0));
                $harga   = max(0, (int)($it['harga_satuan'] ?? 0));
                if (!$bahanId || $qty < 1) continue;

                $bahanChk->execute([$bahanId, $tid, $oid]);
                if (!$bahanChk->fetchColumn()) continue; // bahan tidak valid, skip

                $sub = $qty * $harga;
                $ins->execute([$poId, $tid, $bahanId, $qty, $harga, $sub]);
                $total += $sub;
            }

            $db->prepare("UPDATE hl_po SET total = ? WHERE id = ? AND tenant_id = ?")
               ->execute([$total, $poId, $tid]);

            $db->commit();
            echo json_encode(['ok' => true, 'total' => $total]);
        } catch (Throwable $e) {
            $db->rollBack();
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Pembelian'); ?>
</head>
<body>
<?php renderTopbar('pembelian'); ?>
<div class="hl-main">
  <div style="margin-bottom:16px">
    <h1 style="font-size:22px;font-weight:800;color:var(--navy);margin:0 0 4px">🛒 Pembelian (Purchase Order)</h1>
    <p style="color:var(--gray);font-size:13px;margin:0">Kelola order pembelian bahan baku ke supplier — UI menyusul (Task 5)</p>
  </div>
  <div class="hl-card" style="padding:40px;text-align:center;color:var(--gray)">
    <p>Backend sudah aktif. Antarmuka sedang dikembangkan.</p>
  </div>
</div>
<?php renderToast(); ?>
</body>
</html>

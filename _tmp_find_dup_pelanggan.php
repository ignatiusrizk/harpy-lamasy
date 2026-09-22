<?php
// TEMPORARY — read-only duplicate-customer scanner. Token-gated, reverted right after use.
define('ROOT', __DIR__);
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';

header('Content-Type: application/json');

$TOKEN = 'lmx_dupscan_7e3a19';
if (($_GET['token'] ?? '') !== $TOKEN) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$tid = (int)($_GET['tid'] ?? 0);
if (!$tid) { echo json_encode(['error'=>'missing tid']); exit; }

$db = Database::get();
$out = ['tenant_id' => $tid];

try {
    // 1) Same normalized name (case/space-insensitive), regardless of phone.
    $st = $db->prepare(
        "SELECT LOWER(TRIM(nama)) AS norm_nama, GROUP_CONCAT(id) AS ids, COUNT(*) AS cnt
           FROM hl_pelanggan
          WHERE tenant_id = ? AND nama IS NOT NULL AND TRIM(nama) <> ''
          GROUP BY LOWER(TRIM(nama))
         HAVING COUNT(*) > 1
          ORDER BY cnt DESC"
    );
    $st->execute([$tid]);
    $nameGroups = $st->fetchAll(PDO::FETCH_ASSOC);

    // 2) Same non-empty phone number, regardless of name.
    $st2 = $db->prepare(
        "SELECT telepon, GROUP_CONCAT(id) AS ids, COUNT(*) AS cnt
           FROM hl_pelanggan
          WHERE tenant_id = ? AND telepon IS NOT NULL AND TRIM(telepon) <> ''
          GROUP BY telepon
         HAVING COUNT(*) > 1
          ORDER BY cnt DESC"
    );
    $st2->execute([$tid]);
    $phoneGroups = $st2->fetchAll(PDO::FETCH_ASSOC);

    // Hydrate details for every id involved in either kind of group.
    $allIds = [];
    foreach ($nameGroups as $g)  foreach (explode(',', $g['ids']) as $id) $allIds[(int)$id] = true;
    foreach ($phoneGroups as $g) foreach (explode(',', $g['ids']) as $id) $allIds[(int)$id] = true;
    $allIds = array_keys($allIds);

    $details = [];
    if ($allIds) {
        $ph = implode(',', array_fill(0, count($allIds), '?'));
        $st3 = $db->prepare(
            "SELECT p.id, p.nama, p.telepon, p.tipe, p.segmen, p.is_active, p.created_at,
                    COUNT(t.id) AS total_order, COALESCE(SUM(t.total),0) AS total_omset,
                    MAX(t.tanggal) AS last_order
               FROM hl_pelanggan p
               LEFT JOIN hl_transaksi t ON t.pelanggan_id = p.id AND t.tenant_id = p.tenant_id
              WHERE p.tenant_id = ? AND p.id IN ($ph)
              GROUP BY p.id"
        );
        $st3->execute([$tid, ...$allIds]);
        foreach ($st3->fetchAll(PDO::FETCH_ASSOC) as $r) $details[(int)$r['id']] = $r;
    }

    $out['name_dup_groups'] = array_map(function($g) use ($details) {
        $ids = array_map('intval', explode(',', $g['ids']));
        return ['norm_nama' => $g['norm_nama'], 'count' => (int)$g['cnt'],
                'members' => array_map(fn($id) => $details[$id] ?? ['id'=>$id], $ids)];
    }, $nameGroups);

    $out['phone_dup_groups'] = array_map(function($g) use ($details) {
        $ids = array_map('intval', explode(',', $g['ids']));
        return ['telepon' => $g['telepon'], 'count' => (int)$g['cnt'],
                'members' => array_map(fn($id) => $details[$id] ?? ['id'=>$id], $ids)];
    }, $phoneGroups);

    $out['total_name_groups']  = count($nameGroups);
    $out['total_phone_groups'] = count($phoneGroups);

    echo json_encode($out, JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}

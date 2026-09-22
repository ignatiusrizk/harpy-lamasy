<?php
// TEMPORARY — one-off customer merge script. Token-gated, reverted right after use.
define('ROOT', __DIR__);
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';

header('Content-Type: application/json');

$TOKEN = 'lmx_merge_9f2c7a1b';
if (($_GET['token'] ?? '') !== $TOKEN) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$dryRun = !empty($_GET['dry']);
$tid    = (int)($_GET['tid'] ?? 0);
$target = (int)($_GET['target'] ?? 0);
$sources = array_filter(array_map('intval', explode(',', $_GET['sources'] ?? '')));

if (!$tid || !$target || !$sources) { echo json_encode(['error'=>'missing params']); exit; }

$db = Database::get();
$out = ['dry_run' => $dryRun, 'tenant_id' => $tid, 'target' => $target, 'sources' => array_values($sources)];

try {
    // Discover every column literally named 'pelanggan_id' anywhere in this DB.
    $colStmt = $db->prepare(
        "SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = ? AND COLUMN_NAME IN ('pelanggan_id','referrer_pelanggan_id','referee_pelanggan_id')"
    );
    $colStmt->execute([DB_NAME]);
    $cols = $colStmt->fetchAll(PDO::FETCH_ASSOC);
    $out['discovered_columns'] = $cols;

    // Which of those tables also have tenant_id (safety scoping)?
    $tenantColStmt = $db->prepare(
        "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND COLUMN_NAME='tenant_id'"
    );
    $tenantColStmt->execute([DB_NAME]);
    $tablesWithTenant = array_column($tenantColStmt->fetchAll(PDO::FETCH_ASSOC), 'TABLE_NAME');
    $tablesWithTenant = array_flip($tablesWithTenant);

    $db->beginTransaction();

    $reassigned = [];
    foreach ($cols as $c) {
        $table = $c['TABLE_NAME']; $col = $c['COLUMN_NAME'];
        if ($table === 'hl_pelanggan') continue; // handled separately below
        $hasTenant = isset($tablesWithTenant[$table]);
        foreach ($sources as $srcId) {
            $sql = "UPDATE `$table` SET `$col` = :target WHERE `$col` = :src" . ($hasTenant ? " AND tenant_id = :tid" : "");
            $st = $db->prepare($sql);
            $params = ['target' => $target, 'src' => $srcId];
            if ($hasTenant) $params['tid'] = $tid;
            $st->execute($params);
            $n = $st->rowCount();
            if ($n > 0) $reassigned[] = ['table' => $table, 'col' => $col, 'from' => $srcId, 'rows' => $n];
        }
    }
    $out['reassigned'] = $reassigned;

    // Recompute aggregate fields on target from hl_transaksi (source of truth)
    $agg = $db->prepare(
        "SELECT COUNT(*) cnt, COALESCE(SUM(total),0) omset, MAX(tanggal) last_order
           FROM hl_transaksi WHERE tenant_id=? AND pelanggan_id=?"
    );
    $agg->execute([$tid, $target]);
    $aggRow = $agg->fetch(PDO::FETCH_ASSOC);
    $out['recomputed'] = $aggRow;

    // Sum saldo_deposit / poin_balance from sources into target before deleting them
    $ph = implode(',', array_fill(0, count($sources), '?'));
    $sumStmt = $db->prepare(
        "SELECT COALESCE(SUM(saldo_deposit),0) sd, COALESCE(SUM(poin_balance),0) pb
           FROM hl_pelanggan WHERE tenant_id=? AND id IN ($ph)"
    );
    $sumStmt->execute([$tid, ...$sources]);
    $extra = $sumStmt->fetch(PDO::FETCH_ASSOC);
    $out['sources_had_balance'] = $extra;

    $upd = $db->prepare(
        "UPDATE hl_pelanggan
            SET total_order = ?, total_omset = ?, last_order = ?,
                saldo_deposit = saldo_deposit + ?, poin_balance = poin_balance + ?,
                updated_at = NOW()
          WHERE id = ? AND tenant_id = ?"
    );
    $upd->execute([
        (int)$aggRow['cnt'], (float)$aggRow['omset'], $aggRow['last_order'],
        (float)$extra['sd'], (int)$extra['pb'],
        $target, $tid,
    ]);

    // Delete the now-empty source customer rows
    $delStmt = $db->prepare("DELETE FROM hl_pelanggan WHERE tenant_id=? AND id=?");
    $deleted = [];
    foreach ($sources as $srcId) {
        $delStmt->execute([$tid, $srcId]);
        $deleted[] = ['id' => $srcId, 'rows' => $delStmt->rowCount()];
    }
    $out['deleted'] = $deleted;

    if ($dryRun) { $db->rollBack(); $out['note'] = 'DRY RUN — rolled back, nothing committed'; }
    else { $db->commit(); $out['note'] = 'COMMITTED'; }

    echo json_encode($out, JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}

<?php
// TEMPORARY — bulk duplicate-customer cleanup. Token-gated, reverted right after use.
// Re-derives duplicate groups SERVER-SIDE (not from client input) for safety, then per group:
//   - exactly 1 member has a non-empty phone  -> that member is target, rest are sources
//   - 0 members have a phone                  -> member with MAX total_order is target, rest are sources
//   - anything else (2+ phones, or tie/ambiguous with no phone) -> SKIPPED, left untouched
define('ROOT', __DIR__);
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';

header('Content-Type: application/json');

$TOKEN = 'lmx_bulkmerge_4d81ce';
if (($_GET['token'] ?? '') !== $TOKEN) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$dryRun = !empty($_GET['dry']);
$tid    = (int)($_GET['tid'] ?? 0);
if (!$tid) { echo json_encode(['error'=>'missing tid']); exit; }

$db = Database::get();

try {
    // Re-derive name-dup groups fresh (server-side, same logic as the scanner).
    $st = $db->prepare(
        "SELECT LOWER(TRIM(nama)) AS norm_nama, GROUP_CONCAT(id) AS ids
           FROM hl_pelanggan
          WHERE tenant_id = ? AND nama IS NOT NULL AND TRIM(nama) <> ''
          GROUP BY LOWER(TRIM(nama))
         HAVING COUNT(*) > 1"
    );
    $st->execute([$tid]);
    $groups = $st->fetchAll(PDO::FETCH_ASSOC);

    // Columns to reassign across the whole schema.
    $colStmt = $db->prepare(
        "SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = ? AND COLUMN_NAME IN ('pelanggan_id','referrer_pelanggan_id','referee_pelanggan_id')"
    );
    $colStmt->execute([DB_NAME]);
    $cols = $colStmt->fetchAll(PDO::FETCH_ASSOC);
    $tenantColStmt = $db->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND COLUMN_NAME='tenant_id'");
    $tenantColStmt->execute([DB_NAME]);
    $tablesWithTenant = array_flip(array_column($tenantColStmt->fetchAll(PDO::FETCH_ASSOC), 'TABLE_NAME'));

    $detailStmt = $db->prepare(
        "SELECT id, telepon, total_order FROM hl_pelanggan WHERE tenant_id=? AND id=?"
    );

    $summary = ['groups_total' => count($groups), 'merged' => [], 'skipped_ambiguous' => [], 'errors' => []];
    $db->beginTransaction();

    foreach ($groups as $g) {
        $ids = array_map('intval', explode(',', $g['ids']));
        $members = [];
        foreach ($ids as $id) {
            $detailStmt->execute([$tid, $id]);
            $row = $detailStmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $members[] = $row;
        }
        if (count($members) < 2) continue;

        $withPhone = array_values(array_filter($members, fn($m) => $m['telepon'] !== null && trim($m['telepon']) !== ''));

        $target = null;
        if (count($withPhone) === 1) {
            $target = $withPhone[0];
        } elseif (count($withPhone) === 0) {
            usort($members, fn($a, $b) => $b['total_order'] <=> $a['total_order']);
            $top = $members[0];
            $tie = array_filter($members, fn($m) => $m['total_order'] === $top['total_order']);
            if ($top['total_order'] > 0 && count($tie) === 1) $target = $top;
        }

        if (!$target) {
            $summary['skipped_ambiguous'][] = ['norm_nama' => $g['norm_nama'], 'ids' => $ids];
            continue;
        }

        $sourceIds = array_values(array_filter(array_column($members, 'id'), fn($id) => $id !== $target['id']));
        if (!$sourceIds) continue;

        $reassignedRows = 0;
        foreach ($cols as $c) {
            $table = $c['TABLE_NAME']; $col = $c['COLUMN_NAME'];
            if ($table === 'hl_pelanggan') continue;
            $hasTenant = isset($tablesWithTenant[$table]);
            foreach ($sourceIds as $srcId) {
                $sql = "UPDATE `$table` SET `$col` = :target WHERE `$col` = :src" . ($hasTenant ? " AND tenant_id = :tid" : "");
                $upd = $db->prepare($sql);
                $params = ['target' => $target['id'], 'src' => $srcId];
                if ($hasTenant) $params['tid'] = $tid;
                $upd->execute($params);
                $reassignedRows += $upd->rowCount();
            }
        }

        $agg = $db->prepare("SELECT COUNT(*) cnt, MAX(tanggal) last_order FROM hl_transaksi WHERE tenant_id=? AND pelanggan_id=?");
        $agg->execute([$tid, $target['id']]);
        $aggRow = $agg->fetch(PDO::FETCH_ASSOC);

        $ph = implode(',', array_fill(0, count($sourceIds), '?'));
        $sumStmt = $db->prepare("SELECT COALESCE(SUM(saldo_deposit),0) sd, COALESCE(SUM(poin_balance),0) pb FROM hl_pelanggan WHERE tenant_id=? AND id IN ($ph)");
        $sumStmt->execute([$tid, ...$sourceIds]);
        $extra = $sumStmt->fetch(PDO::FETCH_ASSOC);

        $updTarget = $db->prepare(
            "UPDATE hl_pelanggan SET total_order=?, last_transaksi=?, saldo_deposit=saldo_deposit+?, poin_balance=poin_balance+?, updated_at=NOW()
              WHERE id=? AND tenant_id=?"
        );
        $updTarget->execute([(int)$aggRow['cnt'], $aggRow['last_order'], (float)$extra['sd'], (int)$extra['pb'], $target['id'], $tid]);

        $delStmt = $db->prepare("DELETE FROM hl_pelanggan WHERE tenant_id=? AND id=?");
        foreach ($sourceIds as $srcId) $delStmt->execute([$tid, $srcId]);

        $summary['merged'][] = [
            'norm_nama' => $g['norm_nama'], 'target' => $target['id'],
            'sources' => $sourceIds, 'reassigned_rows' => $reassignedRows,
            'target_total_order_after' => (int)$aggRow['cnt'],
        ];
    }

    if ($dryRun) { $db->rollBack(); $summary['note'] = 'DRY RUN — rolled back'; }
    else { $db->commit(); $summary['note'] = 'COMMITTED'; }

    $summary['merged_count'] = count($summary['merged']);
    $summary['skipped_count'] = count($summary['skipped_ambiguous']);
    echo json_encode($summary, JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}

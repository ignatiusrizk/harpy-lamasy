<?php
// TEMPORARY — bulk-close orders dated before 2026-08-01: mark diambil+lunas.
// Pure status correction (user confirmed these are all settled historically) —
// deliberately NOT touching hl_kas (no phantom "cash in today" entries for old
// money) and NOT triggering referral payout (not a real new-payment event).
// Token-gated, reverted right after use.
define('ROOT', __DIR__);
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';

header('Content-Type: application/json');

$TOKEN = 'lmx_closeold_7f4c2e';
if (($_GET['token'] ?? '') !== $TOKEN) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$dryRun = !empty($_GET['dry']);
$tid = 18;
$oid = 13;
$cutoff = '2026-08-01';

$db = Database::get();

try {
    $sel = $db->prepare(
        "SELECT COUNT(*) cnt,
                SUM(status_proses != 'diambil') not_diambil,
                SUM(status_bayar != 'lunas') not_lunas,
                COALESCE(SUM(CASE WHEN status_bayar != 'lunas' THEN sisa_bayar ELSE 0 END),0) sisa_total
           FROM hl_transaksi
          WHERE tenant_id=? AND outlet_id=? AND tanggal < ?"
    );
    $sel->execute([$tid, $oid, $cutoff]);
    $before = $sel->fetch(PDO::FETCH_ASSOC);

    $out = ['dry_run' => $dryRun, 'cutoff' => $cutoff, 'before' => $before];

    if (!$dryRun) {
        $db->beginTransaction();
        $upd = $db->prepare(
            "UPDATE hl_transaksi
                SET status_proses='diambil', status_bayar='lunas', dp=total, sisa_bayar=0, updated_at=NOW()
              WHERE tenant_id=? AND outlet_id=? AND tanggal < ?"
        );
        $upd->execute([$tid, $oid, $cutoff]);
        $out['rows_updated'] = $upd->rowCount();
        $db->commit();
        $out['note'] = 'COMMITTED';
    } else {
        $out['note'] = 'DRY RUN — no changes made';
    }

    echo json_encode($out, JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[_tmp_close_old_orders] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    echo json_encode(['error' => 'internal error — cek error_log server']);
}

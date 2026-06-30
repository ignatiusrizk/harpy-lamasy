<?php
require_once __DIR__ . '/Database.php';

class CoinModeManager
{
    /**
     * Ganti coin_mode tenant + migrasi saldo (transaksional).
     * shared→per_outlet: seluruh tenants.coin_balance → outlet UTAMA.
     * per_outlet→shared: SUM outlets.coin_balance → tenants.coin_balance.
     * trial_coin_balance TIDAK ikut.
     */
    public static function switchMode(int $tenantId, string $newMode, string $actor = ''): array
    {
        if (!in_array($newMode, ['shared', 'per_outlet'], true)) {
            return ['ok'=>false,'moved'=>0,'from'=>'','to'=>$newMode,'error'=>'Mode tidak valid'];
        }
        $db = Database::get();
        $db->beginTransaction();
        try {
            $cur = $db->prepare("SELECT coin_mode FROM tenants WHERE id=? FOR UPDATE");
            $cur->execute([$tenantId]);
            $from = $cur->fetchColumn();
            if ($from === false) {
                $db->rollBack();
                return ['ok'=>false,'moved'=>0,'from'=>'','to'=>$newMode,'error'=>'Tenant tidak ditemukan'];
            }
            if ($from === $newMode) {
                $db->commit();
                return ['ok'=>true,'moved'=>0,'from'=>$from,'to'=>$newMode,'error'=>null];
            }

            $moved = 0;
            $desc  = 'Migrasi mode coin ' . $from . '→' . $newMode . ($actor ? " ({$actor})" : '');

            if ($newMode === 'per_outlet') {
                $tb = $db->prepare("SELECT coin_balance FROM tenants WHERE id=? FOR UPDATE");
                $tb->execute([$tenantId]);
                $pool = (int)$tb->fetchColumn();
                $mainId = self::mainOutletId($db, $tenantId);
                if ($pool > 0 && $mainId > 0) {
                    $ob = $db->prepare("SELECT coin_balance FROM outlets WHERE id=? AND tenant_id=? FOR UPDATE");
                    $ob->execute([$mainId, $tenantId]);
                    $newOut = (int)$ob->fetchColumn() + $pool;
                    $db->prepare("UPDATE outlets SET coin_balance=? WHERE id=? AND tenant_id=?")->execute([$newOut, $mainId, $tenantId]);
                    $db->prepare("UPDATE tenants SET coin_balance=0 WHERE id=?")->execute([$tenantId]);
                    self::ledger($db, $tenantId, null, 'deduct', $pool, $desc, 0);
                    self::ledger($db, $tenantId, $mainId, 'topup', $pool, $desc, $newOut);
                    $moved = $pool;
                }
                $db->prepare("UPDATE tenants SET coin_mode='per_outlet' WHERE id=?")->execute([$tenantId]);
            } else {
                $rows = $db->prepare("SELECT id, coin_balance FROM outlets WHERE tenant_id=? FOR UPDATE");
                $rows->execute([$tenantId]);
                $outlets = $rows->fetchAll(PDO::FETCH_ASSOC);
                $tb = $db->prepare("SELECT coin_balance FROM tenants WHERE id=? FOR UPDATE");
                $tb->execute([$tenantId]);
                $pool = (int)$tb->fetchColumn();
                $sum = 0;
                foreach ($outlets as $o) {
                    $bal = (int)$o['coin_balance'];
                    if ($bal <= 0) continue;
                    $db->prepare("UPDATE outlets SET coin_balance=0 WHERE id=? AND tenant_id=?")->execute([(int)$o['id'], $tenantId]);
                    self::ledger($db, $tenantId, (int)$o['id'], 'deduct', $bal, $desc, 0);
                    $sum += $bal;
                }
                if ($sum > 0) {
                    $newPool = $pool + $sum;
                    $db->prepare("UPDATE tenants SET coin_balance=? WHERE id=?")->execute([$newPool, $tenantId]);
                    self::ledger($db, $tenantId, null, 'topup', $sum, $desc, $newPool);
                    $moved = $sum;
                }
                $db->prepare("UPDATE tenants SET coin_mode='shared' WHERE id=?")->execute([$tenantId]);
            }

            $db->commit();
            return ['ok'=>true,'moved'=>$moved,'from'=>$from,'to'=>$newMode,'error'=>null];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return ['ok'=>false,'moved'=>0,'from'=>'','to'=>$newMode,'error'=>$e->getMessage()];
        }
    }

    private static function mainOutletId(PDO $db, int $tenantId): int
    {
        $s = $db->prepare("SELECT id FROM outlets WHERE tenant_id=? AND status<>'closed' ORDER BY is_main DESC, id ASC LIMIT 1");
        $s->execute([$tenantId]);
        return (int)($s->fetchColumn() ?: 0);
    }

    private static function ledger(PDO $db, int $tenantId, ?int $outletId, string $type, int $amount, string $desc, int $balanceAfter): void
    {
        $db->prepare("INSERT INTO coin_ledger
              (tenant_id, outlet_id, type, amount, feature_used, description, balance_after, ref_id)
            VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$tenantId, $outletId, $type, $amount, 'coin_mode_migration', $desc, $balanceAfter, null]);
    }
}

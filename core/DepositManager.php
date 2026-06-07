<?php
// ══════════════════════════════════════════════════════
// core/DepositManager.php
//
// Prepaid wallet untuk customer. Top up dulu, bayar nota pakai saldo.
//
// Methods:
// - balance(tid, pid): saldo current
// - history(tid, pid, limit): topup + usage merged
// - topup(tid, oid, pid, jumlah, metode, ...): tambah saldo (apply bonus tier auto)
// - deduct(tid, oid, pid, jumlah, trxId): potong saldo (saat bayar nota)
// - calcBonus(tid, oid, jumlah): cek tier bonus yg berlaku
// ══════════════════════════════════════════════════════

class DepositManager
{
    /** Saldo current pelanggan. */
    public static function balance(int $tenantId, int $pelangganId): float
    {
        try {
            $st = Database::get()->prepare(
                "SELECT COALESCE(saldo_deposit,0) FROM hl_pelanggan
                  WHERE id=? AND tenant_id=? LIMIT 1"
            );
            $st->execute([$pelangganId, $tenantId]);
            return (float)$st->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Cek bonus tier yg berlaku utk topup tertentu.
     * Return tier yg min_topup tertinggi yg masih ≤ jumlah.
     */
    public static function calcBonus(int $tenantId, int $outletId, float $jumlah): array
    {
        try {
            $st = Database::get()->prepare(
                "SELECT * FROM hl_deposit_bonus_tier
                  WHERE tenant_id = ? AND is_active = 1
                    AND (outlet_id IS NULL OR outlet_id = ?)
                    AND min_topup <= ?
                  ORDER BY min_topup DESC LIMIT 1"
            );
            $st->execute([$tenantId, $outletId, $jumlah]);
            $tier = $st->fetch(PDO::FETCH_ASSOC);
            if (!$tier) return ['bonus' => 0, 'tier' => null];
            $bonus = $tier['bonus_tipe'] === 'persen'
                ? round($jumlah * ((float)$tier['bonus_nilai'] / 100))
                : (float)$tier['bonus_nilai'];
            return ['bonus' => $bonus, 'tier' => $tier];
        } catch (Throwable) {
            return ['bonus' => 0, 'tier' => null];
        }
    }

    /**
     * Tambah saldo (topup). Auto-apply bonus tier kalau ada.
     * Return [topup_id, ?error].
     */
    public static function topup(
        int    $tenantId,
        int    $outletId,
        int    $pelangganId,
        float  $jumlah,
        string $metode      = 'cash',
        string $catatan     = '',
        ?int   $createdBy   = null,
        ?string $buktiBayar = null
    ): array {
        if ($jumlah <= 0) return [0, 'Jumlah topup harus > 0'];
        $db = Database::get();
        try {
            $db->beginTransaction();
            // Lock pelanggan row
            $st = $db->prepare("SELECT saldo_deposit FROM hl_pelanggan WHERE id=? AND tenant_id=? FOR UPDATE");
            $st->execute([$pelangganId, $tenantId]);
            $saldoSebelum = (float)$st->fetchColumn();

            // Hitung bonus
            $bonusInfo = self::calcBonus($tenantId, $outletId, $jumlah);
            $bonus     = (float)$bonusInfo['bonus'];
            $kredit    = $jumlah + $bonus;
            $saldoSesudah = $saldoSebelum + $kredit;

            // Update saldo
            $db->prepare("UPDATE hl_pelanggan SET saldo_deposit = ? WHERE id = ? AND tenant_id = ?")
               ->execute([$saldoSesudah, $pelangganId, $tenantId]);

            // Catat history
            $ins = $db->prepare(
                "INSERT INTO hl_deposit_topup
                  (tenant_id, outlet_id, pelanggan_id, jumlah, bonus, total_kredit,
                   metode_bayar, bukti_bayar, saldo_sebelum, saldo_sesudah, catatan, created_by)
                 VALUES (?,?,?,?,?,?, ?,?,?,?,?,?)"
            );
            $ins->execute([
                $tenantId, $outletId, $pelangganId,
                $jumlah, $bonus, $kredit,
                $metode, $buktiBayar, $saldoSebelum, $saldoSesudah,
                $catatan ?: null, $createdBy,
            ]);
            $topupId = (int)$db->lastInsertId();
            $db->commit();
            return [$topupId, null];
        } catch (Throwable $e) {
            try { $db->rollBack(); } catch (Throwable) {}
            return [0, 'Gagal topup: ' . $e->getMessage()];
        }
    }

    /**
     * Potong saldo (saat customer bayar nota pakai deposit).
     * Return [usage_id, ?error]. Cek saldo cukup dulu.
     */
    public static function deduct(
        int    $tenantId,
        int    $outletId,
        int    $pelangganId,
        float  $jumlah,
        ?int   $transaksiId = null,
        string $catatan     = '',
        ?int   $createdBy   = null
    ): array {
        if ($jumlah <= 0) return [0, 'Jumlah deduct harus > 0'];
        $db = Database::get();
        try {
            $db->beginTransaction();
            $st = $db->prepare("SELECT saldo_deposit FROM hl_pelanggan WHERE id=? AND tenant_id=? FOR UPDATE");
            $st->execute([$pelangganId, $tenantId]);
            $saldoSebelum = (float)$st->fetchColumn();
            if ($saldoSebelum < $jumlah) {
                $db->rollBack();
                return [0, 'Saldo tidak cukup. Saldo: Rp ' . number_format($saldoSebelum, 0, ',', '.') . ', dibutuhkan: Rp ' . number_format($jumlah, 0, ',', '.')];
            }
            $saldoSesudah = $saldoSebelum - $jumlah;
            $db->prepare("UPDATE hl_pelanggan SET saldo_deposit = ? WHERE id = ? AND tenant_id = ?")
               ->execute([$saldoSesudah, $pelangganId, $tenantId]);
            $ins = $db->prepare(
                "INSERT INTO hl_deposit_usage
                  (tenant_id, outlet_id, pelanggan_id, transaksi_id, jumlah,
                   saldo_sebelum, saldo_sesudah, catatan, created_by)
                 VALUES (?,?,?,?, ?, ?,?,?,?)"
            );
            $ins->execute([
                $tenantId, $outletId, $pelangganId, $transaksiId,
                $jumlah, $saldoSebelum, $saldoSesudah,
                $catatan ?: null, $createdBy,
            ]);
            $usageId = (int)$db->lastInsertId();
            $db->commit();
            return [$usageId, null];
        } catch (Throwable $e) {
            try { $db->rollBack(); } catch (Throwable) {}
            return [0, 'Gagal deduct: ' . $e->getMessage()];
        }
    }

    /**
     * History (topup + usage merged, sorted by date desc).
     */
    public static function history(int $tenantId, int $pelangganId, int $limit = 50): array
    {
        try {
            $db = Database::get();
            $st = $db->prepare(
                "(SELECT 'topup' AS jenis, id, jumlah, bonus, total_kredit AS delta,
                          saldo_sebelum, saldo_sesudah, metode_bayar, catatan, created_at, NULL AS transaksi_id
                     FROM hl_deposit_topup
                    WHERE tenant_id=? AND pelanggan_id=?)
                 UNION ALL
                 (SELECT 'usage' AS jenis, id, jumlah, 0 AS bonus, -jumlah AS delta,
                          saldo_sebelum, saldo_sesudah, NULL AS metode_bayar, catatan, created_at, transaksi_id
                     FROM hl_deposit_usage
                    WHERE tenant_id=? AND pelanggan_id=?)
                 ORDER BY created_at DESC LIMIT $limit"
            );
            $st->execute([$tenantId, $pelangganId, $tenantId, $pelangganId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /** Total saldo deposit semua pelanggan tenant (liability owner). */
    public static function totalLiability(int $tenantId): float
    {
        try {
            $st = Database::get()->prepare(
                "SELECT COALESCE(SUM(saldo_deposit), 0) FROM hl_pelanggan WHERE tenant_id = ?"
            );
            $st->execute([$tenantId]);
            return (float)$st->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }
}

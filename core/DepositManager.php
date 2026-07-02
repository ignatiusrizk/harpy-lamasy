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
        ?string $buktiBayar = null,
        bool   $applyBonus  = true
    ): array {
        if ($jumlah <= 0) return [0, 'Jumlah topup harus > 0'];
        $db = Database::get();
        // Transaction-aware: bila pemanggil sudah dalam transaksi (mis. orders.php
        // action=update), numpang — jangan begin/commit sendiri; error dilempar
        // sebagai exception supaya transaksi luar rollback utuh.
        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) $db->beginTransaction();
            // Lock pelanggan row
            $st = $db->prepare("SELECT saldo_deposit FROM hl_pelanggan WHERE id=? AND tenant_id=? FOR UPDATE");
            $st->execute([$pelangganId, $tenantId]);
            $saldoSebelum = (float)$st->fetchColumn();

            // Hitung bonus (skip utk refund)
            if ($applyBonus) {
                $bonusInfo = self::calcBonus($tenantId, $outletId, $jumlah);
                $bonus     = (float)$bonusInfo['bonus'];
            } else {
                $bonus = 0.0;
            }
            $kredit    = $jumlah + $bonus;
            $saldoSesudah = $saldoSebelum + $kredit;

            // Update saldo
            $db->prepare("UPDATE hl_pelanggan SET saldo_deposit = ? WHERE id = ? AND tenant_id = ?")
               ->execute([$saldoSesudah, $pelangganId, $tenantId]);

            // Hitung tgl expired (kalau outlet set deposit_expired_hari)
            $expiredAt = self::calcExpiry($outletId);

            // Catat history (try with expired_at; fallback tanpa kalau kolom belum ada)
            try {
                $ins = $db->prepare(
                    "INSERT INTO hl_deposit_topup
                      (tenant_id, outlet_id, pelanggan_id, jumlah, bonus, total_kredit,
                       metode_bayar, bukti_bayar, expired_at, saldo_sebelum, saldo_sesudah,
                       catatan, created_by)
                     VALUES (?,?,?,?,?,?, ?,?,?, ?,?, ?,?)"
                );
                $ins->execute([
                    $tenantId, $outletId, $pelangganId,
                    $jumlah, $bonus, $kredit,
                    $metode, $buktiBayar, $expiredAt,
                    $saldoSebelum, $saldoSesudah,
                    $catatan ?: null, $createdBy,
                ]);
            } catch (Throwable) {
                // Fallback tanpa kolom expired_at
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
            }
            $topupId = (int)$db->lastInsertId();
            if ($ownTx) $db->commit();
            return [$topupId, null];
        } catch (Throwable $e) {
            if ($ownTx) {
                try { $db->rollBack(); } catch (Throwable $rbErr) {
                    if (class_exists('ErrorLogger')) ErrorLogger::logException('db_rollback', $rbErr);
                }
                return [0, 'Gagal topup: ' . $e->getMessage()];
            }
            throw $e; // dalam transaksi luar → biarkan pemanggil rollback utuh
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
            try { $db->rollBack(); } catch (Throwable $rbErr) {
                if (class_exists('ErrorLogger')) ErrorLogger::logException('db_rollback', $rbErr);
            }
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

    // ═════════════════════════════════════════════════
    // ── REFUND WORKFLOW (Phase 2) ───────────────────
    // Customer minta saldo balik → kasir submit → owner approve → execute
    // ═════════════════════════════════════════════════

    /** Submit refund request (kasir). Return [id, ?error]. */
    public static function requestRefund(
        int    $tenantId,
        int    $outletId,
        int    $pelangganId,
        float  $jumlah,
        string $alasan,
        int    $requestedBy,
        string $metode = 'cash'
    ): array {
        if ($jumlah <= 0) return [0, 'Jumlah refund harus > 0'];
        if (trim($alasan) === '') return [0, 'Alasan wajib diisi'];
        $saldo = self::balance($tenantId, $pelangganId);
        if ($saldo < $jumlah) return [0, "Saldo customer tidak cukup (Rp " . number_format($saldo,0,',','.') . ")"];

        try {
            $db = Database::get();
            // Cek tidak ada pending request lain
            $chk = $db->prepare("SELECT id FROM hl_deposit_refund WHERE tenant_id=? AND pelanggan_id=? AND status='pending' LIMIT 1");
            $chk->execute([$tenantId, $pelangganId]);
            if ($chk->fetchColumn()) return [0, 'Customer ini sudah punya request refund pending'];

            $st = $db->prepare(
                "INSERT INTO hl_deposit_refund
                  (tenant_id, outlet_id, pelanggan_id, jumlah_refund, metode_refund,
                   alasan, saldo_sebelum, requested_by)
                 VALUES (?,?,?,?,?, ?,?,?)"
            );
            $st->execute([
                $tenantId, $outletId, $pelangganId, $jumlah, $metode,
                substr($alasan, 0, 1000), $saldo, $requestedBy,
            ]);
            return [(int)$db->lastInsertId(), null];
        } catch (Throwable $e) {
            return [0, 'Gagal submit: '.$e->getMessage()];
        }
    }

    /** Approve refund + execute (owner). */
    public static function approveRefund(int $tenantId, int $refundId, int $reviewerId, string $note = ''): ?string
    {
        $db = Database::get();
        try {
            $db->beginTransaction();
            // Lock & ambil request
            $st = $db->prepare("SELECT * FROM hl_deposit_refund WHERE id=? AND tenant_id=? AND status='pending' FOR UPDATE");
            $st->execute([$refundId, $tenantId]);
            $req = $st->fetch(PDO::FETCH_ASSOC);
            if (!$req) { $db->rollBack(); return 'Refund tidak ditemukan / sudah di-review'; }

            // Mark approved dulu
            $db->prepare("UPDATE hl_deposit_refund SET status='approved', reviewed_by=?, reviewed_at=NOW(), review_note=? WHERE id=?")
               ->execute([$reviewerId, substr($note,0,500) ?: null, $refundId]);

            // Execute: deduct saldo (re-cek balance, mungkin udah berubah sejak submit)
            $jml = (float)$req['jumlah_refund'];
            $pid = (int)$req['pelanggan_id'];
            $oid = (int)$req['outlet_id'];

            [$usageId, $err] = self::deduct(
                $tenantId, $oid, $pid, $jml, null,
                "Refund: {$req['alasan']}", $reviewerId
            );
            if ($err) {
                $db->rollBack();
                // Tandai sebagai rejected karena gagal eksekusi
                $db->prepare("UPDATE hl_deposit_refund SET status='rejected', review_note=? WHERE id=?")
                   ->execute(["Gagal eksekusi: $err", $refundId]);
                return "Refund disetujui tapi GAGAL eksekusi: $err. Status di-rollback ke rejected.";
            }
            // Update status executed + link usage
            $db->prepare("UPDATE hl_deposit_refund SET status='executed', usage_id=? WHERE id=?")
               ->execute([$usageId, $refundId]);
            $db->commit();
            return null;
        } catch (Throwable $e) {
            try { $db->rollBack(); } catch (Throwable $rbErr) {
                if (class_exists('ErrorLogger')) ErrorLogger::logException('db_rollback', $rbErr);
            }
            return 'Gagal approve: '.$e->getMessage();
        }
    }

    /** Reject refund. */
    public static function rejectRefund(int $tenantId, int $refundId, int $reviewerId, string $note = ''): ?string
    {
        try {
            Database::get()->prepare(
                "UPDATE hl_deposit_refund
                    SET status='rejected', reviewed_by=?, reviewed_at=NOW(), review_note=?
                  WHERE id=? AND tenant_id=? AND status='pending'"
            )->execute([$reviewerId, substr($note,0,500) ?: null, $refundId, $tenantId]);
            return null;
        } catch (Throwable $e) {
            return 'Gagal reject: '.$e->getMessage();
        }
    }

    /** Count pending refund (utk badge approval inbox). */
    public static function pendingRefundCount(int $tenantId): int
    {
        try {
            $st = Database::get()->prepare("SELECT COUNT(*) FROM hl_deposit_refund WHERE tenant_id=? AND status='pending'");
            $st->execute([$tenantId]);
            return (int)$st->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    // ═════════════════════════════════════════════════
    // ── EXPIRED WORKFLOW (Phase 2) ──────────────────
    // Bonus saldo punya masa berlaku.
    // ═════════════════════════════════════════════════

    /**
     * Hitung tgl expired berdasar config outlet.
     * Return DATE string atau null kalau tidak expire.
     */
    public static function calcExpiry(int $outletId): ?string
    {
        try {
            $st = Database::get()->prepare("SELECT deposit_expired_hari FROM outlets WHERE id=? LIMIT 1");
            $st->execute([$outletId]);
            $hari = (int)$st->fetchColumn();
            if ($hari <= 0) return null;
            return date('Y-m-d', strtotime("+$hari days"));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Run periodik (via cron atau on-access): hapus saldo expired.
     * Untuk setiap topup yang lewat expired_at, deduct bonus dari saldo
     * pelanggan + catat usage dgn label "Expired".
     * Return jumlah saldo yg di-expire total.
     */
    public static function processExpired(int $tenantId): float
    {
        $db = Database::get();
        try {
            // Cari topup expired yang belum di-process
            // Note: kita simpel — process semua bonus expired (tidak track per-topup
            // expiration di usage). Algoritma:
            // 1. Total bonus expired = SUM(bonus) FROM topup WHERE expired_at < CURDATE()
            //    AND NOT IN (sudah masuk usage 'expired' table flag)
            // Untuk MVP: pakai catatan unik 'EXPIRED:topup_id' di hl_deposit_usage
            //    sebagai marker idempotent.

            $st = $db->prepare(
                "SELECT t.id, t.pelanggan_id, t.outlet_id, t.bonus
                   FROM hl_deposit_topup t
                  WHERE t.tenant_id=? AND t.expired_at IS NOT NULL
                    AND t.expired_at < CURDATE() AND t.bonus > 0
                    AND NOT EXISTS (
                      SELECT 1 FROM hl_deposit_usage u
                       WHERE u.pelanggan_id=t.pelanggan_id AND u.tenant_id=t.tenant_id
                         AND u.catatan = CONCAT('EXPIRED:topup_', t.id)
                    )"
            );
            $st->execute([$tenantId]);
            $expiredTopups = $st->fetchAll(PDO::FETCH_ASSOC);

            $totalExpired = 0;
            foreach ($expiredTopups as $t) {
                $bonus = (float)$t['bonus'];
                // Cek saldo customer cukup (kalau udah kepakai duluan, skip)
                $bal = self::balance($tenantId, (int)$t['pelanggan_id']);
                if ($bal <= 0) continue; // saldo sudah habis natural
                $toExpire = min($bonus, $bal);
                if ($toExpire <= 0) continue;
                [$_id, $err] = self::deduct(
                    $tenantId, (int)$t['outlet_id'], (int)$t['pelanggan_id'],
                    $toExpire, null, "EXPIRED:topup_{$t['id']}", null
                );
                if (!$err) $totalExpired += $toExpire;
            }
            return $totalExpired;
        } catch (Throwable) {
            return 0;
        }
    }
}

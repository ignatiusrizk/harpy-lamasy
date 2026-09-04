<?php
// ══════════════════════════════════════════════════════
// core/Loyalty.php — Loyalty poin lintas outlet
//
// Poin = account-level (hl_pelanggan.poin_balance), bisa dikumpulkan
// & ditukar di outlet mana saja. Ledger di hl_loyalty_log.
// ══════════════════════════════════════════════════════

class Loyalty
{
    /** Setting loyalty tenant (cache per request) */
    private static array $cfgCache = [];

    public static function config(int $tenantId): array
    {
        if (isset(self::$cfgCache[$tenantId])) return self::$cfgCache[$tenantId];
        $cfg = ['enabled'=>false, 'rupiah_per_poin'=>1000, 'poin_value'=>100];
        try {
            $db = Database::get();
            $stmt = $db->prepare("SELECT loyalty_enabled, loyalty_rupiah_per_poin, loyalty_poin_value
                                    FROM tenants WHERE id=?");
            $stmt->execute([$tenantId]);
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $cfg = [
                    'enabled'         => (int)($row['loyalty_enabled'] ?? 0) === 1,
                    'rupiah_per_poin' => max(1, (int)($row['loyalty_rupiah_per_poin'] ?? 1000)),
                    'poin_value'      => max(1, (int)($row['loyalty_poin_value'] ?? 100)),
                ];
            }
        } catch (Throwable $e) {
            if (class_exists('ErrorLogger')) ErrorLogger::logException('loyalty_config', $e, $tenantId);
        }
        self::$cfgCache[$tenantId] = $cfg;
        return $cfg;
    }

    public static function isEnabled(int $tenantId): bool
    {
        return self::config($tenantId)['enabled'];
    }

    /** Update tier pelanggan berdasarkan poin_balance saat ini */
    public static function updateTier(int $tenantId, int $pelangganId): string
    {
        try {
            $db = Database::get();
            $stmt = $db->prepare("SELECT poin_balance FROM hl_pelanggan WHERE id=? AND tenant_id=?");
            $stmt->execute([$pelangganId, $tenantId]);
            $poin = (int)$stmt->fetchColumn();
            $tier = 'regular';
            if      ($poin >= 500) $tier = 'platinum';
            elseif  ($poin >= 200) $tier = 'gold';
            elseif  ($poin >= 100) $tier = 'silver';
            $db->prepare("UPDATE hl_pelanggan SET tier=? WHERE id=? AND tenant_id=?")
               ->execute([$tier, $pelangganId, $tenantId]);
            return $tier;
        } catch (Throwable $e) {
            error_log('[Loyalty::updateTier] '.$e->getMessage());
            return 'regular';
        }
    }

    /** Touch last_transaksi pelanggan ke CURDATE() */
    public static function touchLastTransaksi(int $tenantId, int $pelangganId): void
    {
        try {
            Database::get()
                ->prepare("UPDATE hl_pelanggan SET last_transaksi=CURDATE() WHERE id=? AND tenant_id=?")
                ->execute([$pelangganId, $tenantId]);
        } catch (Throwable $e) {
            error_log('[Loyalty::touchLastTransaksi] '.$e->getMessage());
        }
    }

    /** Reward berikutnya yang bisa dicapai pelanggan (untuk pesan motivasi) */
    public static function nextReward(int $tenantId, int $outletId, int $poinSaatIni): ?array
    {
        try {
            $db = Database::get();
            $stmt = $db->prepare("SELECT id, nama_reward, poin_dibutuhkan
                                    FROM hl_poin_reward
                                   WHERE tenant_id=? AND outlet_id=? AND is_active=1
                                     AND poin_dibutuhkan > ?
                                   ORDER BY poin_dibutuhkan ASC LIMIT 1");
            $stmt->execute([$tenantId, $outletId, $poinSaatIni]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (Throwable) { return null; }
    }

    /** List reward aktif yang BISA diredeem pelanggan (poin cukup) */
    public static function availableRewards(int $tenantId, int $outletId, int $poinSaatIni): array
    {
        try {
            $db = Database::get();
            $stmt = $db->prepare(
                "SELECT r.* FROM hl_poin_reward r
                  WHERE r.tenant_id=? AND r.is_active=1
                    AND (
                      NOT EXISTS (SELECT 1 FROM hl_poin_reward_outlet WHERE reward_id=r.id)
                      OR EXISTS (SELECT 1 FROM hl_poin_reward_outlet WHERE reward_id=r.id AND outlet_id=?)
                    )
                  ORDER BY r.poin_dibutuhkan ASC"
            );
            $stmt->execute([$tenantId, $outletId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$r) {
                $r['bisa_redeem'] = $poinSaatIni >= (int)$r['poin_dibutuhkan'];
                $r['kurang']     = max(0, (int)$r['poin_dibutuhkan'] - $poinSaatIni);
            }
            return $rows;
        } catch (Throwable) { return []; }
    }

    /** Return list outlet_id yang apply untuk reward. Empty array = berlaku semua outlet (no junction). */
    public static function applicableOutlets(int $rewardId): array
    {
        try {
            $db = Database::get();
            $st = $db->prepare("SELECT outlet_id FROM hl_poin_reward_outlet WHERE reward_id=? ORDER BY outlet_id");
            $st->execute([$rewardId]);
            return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable) { return []; }
    }

    /** Return true kalau reward dimanage HQ — kasir tidak boleh edit. */
    public static function isHqManaged(int $rewardId): bool
    {
        try {
            $db = Database::get();
            $st = $db->prepare("SELECT is_hq_managed FROM hl_poin_reward WHERE id=? LIMIT 1");
            $st->execute([$rewardId]);
            return (int)($st->fetchColumn() ?: 0) === 1;
        } catch (Throwable) { return false; }
    }

    public static function balance(int $tenantId, int $pelangganId): int
    {
        try {
            $db = Database::get();
            $stmt = $db->prepare("SELECT poin_balance FROM hl_pelanggan WHERE id=? AND tenant_id=?");
            $stmt->execute([$pelangganId, $tenantId]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable) { return 0; }
    }

    /**
     * Earn poin untuk 1 transaksi (idempotent — tidak dobel per transaksi).
     * @return int poin yang ditambahkan (0 kalau tidak earn)
     */
    public static function earnForTransaction(
        int $tenantId, ?int $outletId, int $transaksiId, int $pelangganId, float $total
    ): int {
        if (!self::isEnabled($tenantId) || $pelangganId <= 0 || $total <= 0) return 0;

        $cfg = self::config($tenantId);
        $poin = (int)floor($total / $cfg['rupiah_per_poin']);
        if ($poin <= 0) return 0;

        $db = Database::get();
        try {
            // Idempotency: sudah pernah earn utk transaksi ini?
            $chk = $db->prepare("SELECT 1 FROM hl_loyalty_log
                                  WHERE tenant_id=? AND transaksi_id=? AND type='earn' LIMIT 1");
            $chk->execute([$tenantId, $transaksiId]);
            if ($chk->fetchColumn()) return 0;

            $db->beginTransaction();
            $cur = $db->prepare("SELECT poin_balance FROM hl_pelanggan WHERE id=? AND tenant_id=? FOR UPDATE");
            $cur->execute([$pelangganId, $tenantId]);
            $bal = (int)$cur->fetchColumn();
            $newBal = $bal + $poin;

            $db->prepare("UPDATE hl_pelanggan SET poin_balance=?, last_transaksi=CURDATE() WHERE id=? AND tenant_id=?")
               ->execute([$newBal, $pelangganId, $tenantId]);

            // Expiry — ambil dari setting tenant (default 12 bulan)
            $months = 12;
            try {
                $sst = $db->prepare("SELECT loyalty_expiry_months FROM tenants WHERE id=?");
                $sst->execute([$tenantId]);
                $months = max(1, (int)($sst->fetchColumn() ?: 12));
            } catch (Throwable $e) {
                if (class_exists('ErrorLogger')) ErrorLogger::logException('loyalty_expiry', $e, $tenantId);
            }
            $expDate = date('Y-m-d', strtotime("+{$months} months"));

            $db->prepare("INSERT INTO hl_loyalty_log
                            (tenant_id, outlet_id, pelanggan_id, transaksi_id, type, poin, balance_after, keterangan, expired_at, created_at)
                          VALUES (?,?,?,?,'earn',?,?,?,?,?)")
               ->execute([$tenantId, $outletId, $pelangganId, $transaksiId, $poin, $newBal,
                          'Earn dari transaksi #'.$transaksiId, $expDate, date('Y-m-d H:i:s')]);
            $db->commit();

            // Update tier (di luar transaction — non-critical)
            self::updateTier($tenantId, $pelangganId);
            return $poin;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[Loyalty::earn] '.$e->getMessage());
            return 0;
        }
    }

    /**
     * Redeem poin → return nilai rupiah diskon.
     * @throws RuntimeException kalau poin kurang / disabled.
     */
    public static function redeem(
        int $tenantId, ?int $outletId, int $pelangganId, int $poin, ?int $transaksiId, ?int $userId = null
    ): int {
        if (!self::isEnabled($tenantId)) throw new RuntimeException('Loyalty tidak aktif.');
        if ($poin <= 0) throw new RuntimeException('Jumlah poin tidak valid.');

        $cfg = self::config($tenantId);
        $db = Database::get();
        $db->beginTransaction();
        try {
            $cur = $db->prepare("SELECT poin_balance FROM hl_pelanggan WHERE id=? AND tenant_id=? FOR UPDATE");
            $cur->execute([$pelangganId, $tenantId]);
            $bal = (int)$cur->fetchColumn();
            if ($bal < $poin) { throw new RuntimeException("Poin tidak cukup (saldo: $bal)."); }

            $newBal = $bal - $poin;
            $db->prepare("UPDATE hl_pelanggan SET poin_balance=? WHERE id=? AND tenant_id=?")
               ->execute([$newBal, $pelangganId, $tenantId]);
            $db->prepare("INSERT INTO hl_loyalty_log
                            (tenant_id, outlet_id, pelanggan_id, transaksi_id, type, poin, balance_after, keterangan, created_by, created_at)
                          VALUES (?,?,?,?,'redeem',?,?,?,?,?)")
               ->execute([$tenantId, $outletId, $pelangganId, $transaksiId, -$poin, $newBal,
                          'Redeem '.$poin.' poin', $userId, date('Y-m-d H:i:s')]);
            $db->commit();
            return $poin * $cfg['poin_value'];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * Redeem di dalam transaksi yang SUDAH dibuka caller (tidak begin/commit sendiri).
     * Dipakai POS saat create order. Return nilai rupiah diskon dari poin.
     * @throws RuntimeException kalau poin kurang.
     */
    public static function redeemInTx(
        PDO $db, int $tenantId, ?int $outletId, int $pelangganId, int $poin, ?int $transaksiId, ?int $userId = null, ?int $rewardId = null
    ): int {
        if ($poin <= 0) return 0;
        $cfg = self::config($tenantId);

        $cur = $db->prepare("SELECT poin_balance FROM hl_pelanggan WHERE id=? AND tenant_id=? FOR UPDATE");
        $cur->execute([$pelangganId, $tenantId]);
        $bal = (int)$cur->fetchColumn();
        if ($bal < $poin) throw new RuntimeException("Poin tidak cukup (saldo: $bal).");

        $newBal  = $bal - $poin;
        $ket = 'Redeem '.$poin.' poin di POS';
        $db->prepare("UPDATE hl_pelanggan SET poin_balance=? WHERE id=? AND tenant_id=?")
           ->execute([$newBal, $pelangganId, $tenantId]);
        $db->prepare("INSERT INTO hl_loyalty_log
                        (tenant_id, outlet_id, pelanggan_id, transaksi_id, reward_id, type, poin, balance_after, keterangan, created_by, created_at)
                      VALUES (?,?,?,?,?,'redeem',?,?,?,?,?)")
           ->execute([$tenantId, $outletId, $pelangganId, $transaksiId, $rewardId ?: null, -$poin, $newBal, $ket, $userId, date('Y-m-d H:i:s')]);
        return $poin * $cfg['poin_value'];
    }

    /**
     * Phase 2: Generate kupon kode dari reward loyalty.
     * Deduct saldo poin pelanggan, buat row hl_voucher source=loyalty
     * dengan kode unik. Kupon bisa dishare (lihat /pelanggan portal)
     * dan dipakai sendiri/teman saat order POS.
     *
     * Return: ['kode'=>'X', 'expired_at'=>'YYYY-MM-DD', 'voucher_id'=>N]
     * Throws RuntimeException kalau saldo kurang / reward invalid.
     */
    public static function createCoupon(
        int $tenantId, int $pelangganId, int $rewardId, ?int $outletId = null, ?int $userId = null
    ): array {
        $db = Database::get();
        $db->beginTransaction();
        try {
            // Validate reward
            $r = $db->prepare("SELECT * FROM hl_poin_reward WHERE id=? AND tenant_id=? AND is_active=1 LIMIT 1");
            $r->execute([$rewardId, $tenantId]);
            $reward = $r->fetch(PDO::FETCH_ASSOC);
            if (!$reward) throw new RuntimeException('Reward tidak ditemukan atau tidak aktif.');
            $poinDibutuhkan = (int)$reward['poin_dibutuhkan'];

            // Lock & validate saldo
            $cur = $db->prepare("SELECT poin_balance FROM hl_pelanggan WHERE id=? AND tenant_id=? FOR UPDATE");
            $cur->execute([$pelangganId, $tenantId]);
            $bal = (int)$cur->fetchColumn();
            if ($bal < $poinDibutuhkan) throw new RuntimeException("Poin tidak cukup (saldo: $bal, butuh: $poinDibutuhkan).");

            // Generate kode unik 8 chars (uppercase alphanumeric, exclude ambiguous)
            $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no I/O/0/1
            $maxAttempt = 8;
            $kode = null;
            for ($i = 0; $i < $maxAttempt; $i++) {
                $candidate = '';
                for ($j = 0; $j < 8; $j++) $candidate .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                $chk = $db->prepare("SELECT 1 FROM hl_voucher WHERE tenant_id=? AND kode=? LIMIT 1");
                $chk->execute([$tenantId, $candidate]);
                if (!$chk->fetchColumn()) { $kode = $candidate; break; }
            }
            if (!$kode) throw new RuntimeException('Gagal generate kode unik, coba lagi.');

            // Insert hl_voucher
            $expiredAt = date('Y-m-d', strtotime('+90 days'));
            $namaPel = '';
            $telPel  = '';
            $pStmt = $db->prepare("SELECT nama, telepon FROM hl_pelanggan WHERE id=? AND tenant_id=? LIMIT 1");
            $pStmt->execute([$pelangganId, $tenantId]);
            if ($p = $pStmt->fetch(PDO::FETCH_ASSOC)) { $namaPel = $p['nama']; $telPel = $p['telepon']; }

            $ins = $db->prepare(
                "INSERT INTO hl_voucher
                  (tenant_id, outlet_id, promo_id, reward_id, pelanggan_id, source, kode,
                   nama_penerima, telepon, expired_at, created_at)
                 VALUES (?, ?, NULL, ?, ?, 'loyalty', ?, ?, ?, ?, NOW())"
            );
            $ins->execute([$tenantId, $outletId, $rewardId, $pelangganId, $kode, $namaPel, $telPel, $expiredAt]);
            $voucherId = (int)$db->lastInsertId();

            // Deduct saldo poin + log
            $newBal = $bal - $poinDibutuhkan;
            $db->prepare("UPDATE hl_pelanggan SET poin_balance=? WHERE id=? AND tenant_id=?")
               ->execute([$newBal, $pelangganId, $tenantId]);
            $db->prepare(
                "INSERT INTO hl_loyalty_log
                  (tenant_id, outlet_id, pelanggan_id, transaksi_id, reward_id, type, poin,
                   balance_after, keterangan, created_by, created_at)
                 VALUES (?,?,?, NULL, ?, 'redeem', ?, ?, ?, ?, ?)"
            )->execute([
                $tenantId, $outletId, $pelangganId, $rewardId, -$poinDibutuhkan, $newBal,
                'Generate kupon ' . $kode . ' (reward: ' . $reward['nama_reward'] . ')',
                $userId, date('Y-m-d H:i:s'),
            ]);

            $db->commit();
            return [
                'kode'       => $kode,
                'voucher_id' => $voucherId,
                'expired_at' => $expiredAt,
                'reward'     => $reward,
                'new_saldo'  => $newBal,
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /** Phase 2: cari kupon by kode, return reward info kalau valid (untuk apply di POS). */
    public static function resolveCoupon(int $tenantId, ?int $outletId, string $kode): ?array
    {
        $db = Database::get();
        $st = $db->prepare(
            "SELECT v.id, v.kode, v.reward_id, v.pelanggan_id, v.expired_at, v.is_used,
                    r.nama_reward, r.tipe, r.nilai, r.poin_dibutuhkan,
                    p.nama AS pelanggan_nama
               FROM hl_voucher v
               LEFT JOIN hl_poin_reward r ON r.id=v.reward_id AND r.tenant_id=v.tenant_id
               LEFT JOIN hl_pelanggan p ON p.id=v.pelanggan_id AND p.tenant_id=v.tenant_id
              WHERE v.tenant_id=? AND v.source='loyalty' AND v.kode=? LIMIT 1"
        );
        $st->execute([$tenantId, $kode]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if ((int)$row['is_used'] === 1) return ['error' => 'Kupon sudah terpakai.'];
        if ($row['expired_at'] && $row['expired_at'] < date('Y-m-d')) return ['error' => 'Kupon kadaluwarsa.'];
        if ($row['reward_id']) {
            // Cek apply di outlet (kalau junction ada)
            $oks = $db->prepare("SELECT COUNT(*) FROM hl_poin_reward_outlet WHERE reward_id=?");
            $oks->execute([$row['reward_id']]);
            $hasJunction = (int)$oks->fetchColumn() > 0;
            if ($hasJunction && $outletId) {
                $ok = $db->prepare("SELECT 1 FROM hl_poin_reward_outlet WHERE reward_id=? AND outlet_id=? LIMIT 1");
                $ok->execute([$row['reward_id'], $outletId]);
                if (!$ok->fetchColumn()) return ['error' => 'Kupon ini tidak berlaku di outlet ini.'];
            }
        }
        return $row;
    }

    /** Phase 2: mark kupon as used (dipanggil saat POS apply). */
    public static function useCoupon(int $tenantId, int $voucherId, string $noOrder): bool
    {
        $db = Database::get();
        $st = $db->prepare(
            "UPDATE hl_voucher
                SET is_used=1, used_at=NOW(), used_by_order=?
              WHERE id=? AND tenant_id=? AND source='loyalty' AND is_used=0"
        );
        $st->execute([$noOrder, $voucherId, $tenantId]);
        return $st->rowCount() > 0;
    }

    /** Phase 2: list kupon aktif (belum used + belum expired) milik pelanggan. */
    public static function myCoupons(int $tenantId, int $pelangganId): array
    {
        $db = Database::get();
        $st = $db->prepare(
            "SELECT v.id, v.kode, v.expired_at, v.is_used, v.used_at, v.used_by_order, v.created_at,
                    r.nama_reward, r.tipe, r.nilai
               FROM hl_voucher v
               LEFT JOIN hl_poin_reward r ON r.id=v.reward_id AND r.tenant_id=v.tenant_id
              WHERE v.tenant_id=? AND v.source='loyalty' AND v.pelanggan_id=?
              ORDER BY v.is_used ASC, v.created_at DESC LIMIT 50"
        );
        $st->execute([$tenantId, $pelangganId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Penyesuaian manual (admin) */
    public static function adjust(int $tenantId, int $pelangganId, int $poinDelta, string $note, ?int $userId = null): int
    {
        $db = Database::get();
        $db->beginTransaction();
        try {
            $cur = $db->prepare("SELECT poin_balance FROM hl_pelanggan WHERE id=? AND tenant_id=? FOR UPDATE");
            $cur->execute([$pelangganId, $tenantId]);
            $bal = (int)$cur->fetchColumn();
            $newBal = max(0, $bal + $poinDelta);
            $db->prepare("UPDATE hl_pelanggan SET poin_balance=? WHERE id=? AND tenant_id=?")
               ->execute([$newBal, $pelangganId, $tenantId]);
            $db->prepare("INSERT INTO hl_loyalty_log
                            (tenant_id, pelanggan_id, type, poin, balance_after, keterangan, created_by, created_at)
                          VALUES (?,?,'adjust',?,?,?,?,?)")
               ->execute([$tenantId, $pelangganId, $poinDelta, $newBal, $note ?: 'Penyesuaian manual', $userId, date('Y-m-d H:i:s')]);
            $db->commit();
            return $newBal;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /** Alias backward-compat — spec menyebut earnFromOrder, implementasi sebenarnya earnForTransaction */
    public static function earnFromOrder(
        int $tenantId, ?int $outletId, int $orderId, int $pelangganId, float $total
    ): int {
        return self::earnForTransaction($tenantId, $outletId, $orderId, $pelangganId, $total);
    }

    /** Riwayat poin pelanggan */
    public static function history(int $tenantId, int $pelangganId, int $limit = 50): array
    {
        try {
            $db = Database::get();
            $stmt = $db->prepare("SELECT l.*, (SELECT nama_outlet FROM outlets WHERE id=l.outlet_id AND tenant_id=l.tenant_id) nama_outlet
                                    FROM hl_loyalty_log l
                                   WHERE l.tenant_id=? AND l.pelanggan_id=?
                                   ORDER BY l.id DESC LIMIT ?");
            $stmt->bindValue(1, $tenantId, PDO::PARAM_INT);
            $stmt->bindValue(2, $pelangganId, PDO::PARAM_INT);
            $stmt->bindValue(3, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) { return []; }
    }
}

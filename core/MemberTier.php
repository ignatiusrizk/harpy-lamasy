<?php
// ══════════════════════════════════════════════════════
// core/MemberTier.php
//
// Helper utk Member Tier system (Tier 1b, inspired by Smartlink).
//
// Konsep:
// - Tenant define daftar tier di /member (Tier Gold/Silver/dll dengan
//   validity period + biaya pendaftaran + diskon otomatis).
// - Pelanggan bisa daftar ke 1 tier aktif sekaligus. Tier menentukan:
//   • Tgl mulai → kadaluarsa otomatis dihitung dari masa_aktif_tipe+nilai
//   • Diskon persen yang otomatis di-apply saat transaksi di POS
// - Status auto-expired oleh cron (atau saat akses) ketika
//   tgl_kadaluarsa < hari ini.
//
// Schema: hl_member_tier + hl_pelanggan_member
// ══════════════════════════════════════════════════════

class MemberTier
{
    /** Semua tier aktif utk tenant ini. */
    public static function activeForTenant(int $tenantId): array
    {
        try {
            $st = Database::get()->prepare(
                "SELECT * FROM hl_member_tier
                  WHERE tenant_id = ? AND is_active = 1
                  ORDER BY urutan ASC, id ASC"
            );
            $st->execute([$tenantId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /** Hitung tgl kadaluarsa dari tier (NULL = seumur hidup). */
    public static function calcExpiry(array $tier, string $tglMulai): ?string
    {
        $tipe  = $tier['masa_aktif_tipe'] ?? 'bulan';
        $nilai = (int)($tier['masa_aktif_nilai'] ?? 0);
        if ($tipe === 'seumur' || $nilai <= 0) return null;
        $unit = $tipe === 'tahun' ? 'year' : 'month';
        return date('Y-m-d', strtotime("$tglMulai +$nilai $unit"));
    }

    /**
     * Membership aktif pelanggan (auto-expire kalau tgl_kadaluarsa lewat).
     * Return null kalau tidak ada / sudah expired.
     */
    public static function activeForPelanggan(int $tenantId, int $pelangganId): ?array
    {
        try {
            $db = Database::get();
            // Auto-expire dulu (idempotent — UPDATE returns 0 rows kalau tidak ada)
            $db->prepare(
                "UPDATE hl_pelanggan_member
                    SET status = 'expired'
                  WHERE tenant_id = ? AND pelanggan_id = ?
                    AND status = 'aktif'
                    AND tgl_kadaluarsa IS NOT NULL
                    AND tgl_kadaluarsa < CURDATE()"
            )->execute([$tenantId, $pelangganId]);

            $st = $db->prepare(
                "SELECT m.*, t.nama_tier, t.diskon_persen
                   FROM hl_pelanggan_member m
                   JOIN hl_member_tier t ON t.id = m.member_tier_id
                  WHERE m.tenant_id = ? AND m.pelanggan_id = ?
                    AND m.status = 'aktif'
                  ORDER BY m.tgl_kadaluarsa DESC LIMIT 1"
            );
            $st->execute([$tenantId, $pelangganId]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Hitung diskon dari membership untuk subtotal tertentu.
     * Return [diskon_rupiah, info_label] atau [0, null] kalau no membership.
     */
    public static function calcMemberDiscount(int $tenantId, int $pelangganId, float $subtotal): array
    {
        $mem = self::activeForPelanggan($tenantId, $pelangganId);
        if (!$mem || (float)$mem['diskon_persen'] <= 0) return [0.0, null];
        $diskon = round($subtotal * ((float)$mem['diskon_persen'] / 100));
        return [$diskon, "Member {$mem['nama_tier']} -{$mem['diskon_persen']}%"];
    }

    /**
     * Daftarkan pelanggan ke tier (atau perpanjang membership).
     * Return [pelanggan_member_id, ['error'?]]
     */
    public static function enroll(
        int    $tenantId,
        int    $outletId,
        int    $pelangganId,
        int    $tierId,
        ?int   $registeredBy = null,
        string $catatan      = ''
    ): array {
        $db = Database::get();
        try {
            $tier = $db->prepare("SELECT * FROM hl_member_tier WHERE id=? AND tenant_id=? AND is_active=1 LIMIT 1");
            $tier->execute([$tierId, $tenantId]);
            $tier = $tier->fetch(PDO::FETCH_ASSOC);
            if (!$tier) return [0, 'Tier tidak ditemukan/non-aktif'];

            // Cancel membership aktif sebelumnya (1 pelanggan = 1 active tier)
            $db->prepare(
                "UPDATE hl_pelanggan_member SET status='dibatalkan'
                  WHERE tenant_id=? AND pelanggan_id=? AND status='aktif'"
            )->execute([$tenantId, $pelangganId]);

            $tglMulai      = date('Y-m-d');
            $tglKadaluarsa = self::calcExpiry($tier, $tglMulai);

            $st = $db->prepare(
                "INSERT INTO hl_pelanggan_member
                  (tenant_id, outlet_id, pelanggan_id, member_tier_id,
                   tgl_mulai, tgl_kadaluarsa, biaya_dibayar, status,
                   catatan, registered_by)
                 VALUES (?,?,?,?, ?,?,?, 'aktif', ?,?)"
            );
            $st->execute([
                $tenantId, $outletId, $pelangganId, $tierId,
                $tglMulai, $tglKadaluarsa, (float)$tier['biaya_pendaftaran'],
                $catatan ?: null, $registeredBy,
            ]);
            $newId = (int)$db->lastInsertId();

            // Update flag pelanggan
            try {
                $db->prepare("UPDATE hl_pelanggan SET tipe='member' WHERE id=? AND tenant_id=?")
                   ->execute([$pelangganId, $tenantId]);
            } catch (Throwable) {}

            return [$newId, null];
        } catch (Throwable $e) {
            return [0, $e->getMessage()];
        }
    }
}

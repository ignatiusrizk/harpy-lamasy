<?php
// ══════════════════════════════════════════════════════
// core/BiayaLainnyaTier.php
//
// Master data "Biaya Lainnya" — mirip core/ExpressTier.php, tapi dihitung
// dari SUBTOTAL ORDER (bukan per-item, krn biaya ini level-order).
//
// Konsep:
// - Owner define daftar tier sekali (Biaya Admin, PPN, dll) di layanan.php.
// - Setiap tier aktif OTOMATIS diterapkan ke SEMUA order baru di POS —
//   tidak ada pilihan/interaksi kasir sama sekali.
// - Bisa >1 tier aktif sekaligus, dijumlah, masing2 jadi baris di struk.
// - Outlet-specific tier (nama sama dgn tier global) MENGGANTIKAN yg
//   global, bukan ditambahkan (sama semantik override Express Tier).
// - hl_transaksi.biaya_lainnya = SUM hasil hitung semua tier aktif.
// - hl_transaksi_biaya_lainnya = breakdown per baris (snapshot).
//
// Schema: hl_biaya_lainnya_tier (lihat migrations/2026-08-31-biaya-lainnya-tier.sql)
// ══════════════════════════════════════════════════════

class BiayaLainnyaTier
{
    /**
     * Tier aktif utk tenant + outlet ini. Include tier global (outlet_id
     * NULL = berlaku semua outlet). Outlet-specific MENGGANTIKAN global
     * kalau nama sama (bukan dijumlah dobel).
     */
    public static function activeForTenant(int $tenantId, ?int $outletId = null): array
    {
        try {
            $st = Database::get()->prepare(
                "SELECT id, nama, tipe_biaya, nilai_biaya, urutan, outlet_id
                   FROM hl_biaya_lainnya_tier
                  WHERE tenant_id = ? AND is_active = 1
                    AND (outlet_id IS NULL OR outlet_id = ?)
                  ORDER BY urutan ASC, id ASC"
            );
            $st->execute([$tenantId, $outletId ?? 0]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Dedupe by nama — 2 pass: global dulu, lalu outlet-specific
            // menimpa (urutan pass menjamin outlet-specific selalu menang,
            // apa pun urutan hasil query).
            $byNama = [];
            foreach ($rows as $r) {
                if ($r['outlet_id'] === null) $byNama[$r['nama']] = $r;
            }
            foreach ($rows as $r) {
                if ($r['outlet_id'] !== null) $byNama[$r['nama']] = $r;
            }
            return array_values($byNama);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Hitung fee 1 tier terhadap subtotal order.
     */
    public static function calcFee(array $tier, float $subtotal): float
    {
        if (($tier['tipe_biaya'] ?? '') === 'flat') {
            return (float)($tier['nilai_biaya'] ?? 0);
        }
        // percent
        return round($subtotal * ((float)($tier['nilai_biaya'] ?? 0) / 100));
    }

    /**
     * Hitung SEMUA tier aktif sekaligus terhadap subtotal order.
     * @return array [['nama'=>string, 'nominal'=>float], ...] — hanya yg nominal > 0.
     */
    public static function calcAppliedFees(int $tenantId, ?int $outletId, float $subtotal): array
    {
        $tiers = self::activeForTenant($tenantId, $outletId);
        $rows = [];
        foreach ($tiers as $t) {
            $nominal = self::calcFee($t, $subtotal);
            if ($nominal > 0) {
                $rows[] = ['nama' => $t['nama'], 'nominal' => $nominal];
            }
        }
        return $rows;
    }
}

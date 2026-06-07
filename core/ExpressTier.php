<?php
// ══════════════════════════════════════════════════════
// core/ExpressTier.php
//
// Tier express GLOBAL per tenant.
//
// Konsep:
// - Tenant define daftar tier sekali (Express 12 Jam, Kilat 3 Jam, dll)
//   di halaman kelola tier (/express-tier).
// - Di POS, setiap item baris bisa pilih tier sendiri (atau kosong =
//   reguler). 1 nota bisa campur item reguler & express.
// - Per item: biaya_express dihitung dari item subtotal × percent
//   ATAU flat amount.
// - hl_transaksi.biaya_tambahan = SUM(items.biaya_express).
//
// Schema: hl_express_tier (lihat express_tier_global_migration.sql)
// ══════════════════════════════════════════════════════

class ExpressTier
{
    /**
     * Tier aktif utk tenant + outlet ini.
     * Include tier global (outlet_id NULL = berlaku semua outlet).
     * Dipakai POS utk render dropdown per item.
     */
    public static function forTenant(int $tenantId, ?int $outletId = null): array
    {
        try {
            if ($outletId === null) {
                // Backward compat: kalau outlet tidak di-pass, ambil semua
                $st = Database::get()->prepare(
                    "SELECT id, nama_tier, estimasi_jam, tipe_biaya, nilai_biaya, urutan, outlet_id
                       FROM hl_express_tier
                      WHERE tenant_id = ? AND is_active = 1
                      ORDER BY urutan ASC, estimasi_jam DESC"
                );
                $st->execute([$tenantId]);
            } else {
                // Filter per outlet: global (NULL) + specific outlet
                $st = Database::get()->prepare(
                    "SELECT id, nama_tier, estimasi_jam, tipe_biaya, nilai_biaya, urutan, outlet_id
                       FROM hl_express_tier
                      WHERE tenant_id = ? AND is_active = 1
                        AND (outlet_id IS NULL OR outlet_id = ?)
                      ORDER BY urutan ASC, estimasi_jam DESC"
                );
                $st->execute([$tenantId, $outletId]);
            }
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Cari satu tier by nama (utk hitung fee saat save).
     * Prioritas: outlet-specific dulu, kalau gak ada baru global (NULL).
     */
    public static function findByNama(int $tenantId, string $namaTier, ?int $outletId = null): ?array
    {
        $namaTier = trim($namaTier);
        if ($namaTier === '' || strtolower($namaTier) === 'reguler') return null;
        try {
            // Order by outlet_id DESC supaya outlet-specific dapat duluan
            // (NULL last in MySQL default ASC, but DESC pushes specific to top)
            $st = Database::get()->prepare(
                "SELECT id, nama_tier, estimasi_jam, tipe_biaya, nilai_biaya, outlet_id
                   FROM hl_express_tier
                  WHERE tenant_id = ? AND nama_tier = ? AND is_active = 1
                    AND (outlet_id IS NULL OR outlet_id = ?)
                  ORDER BY (outlet_id IS NULL) ASC
                  LIMIT 1"
            );
            $st->execute([$tenantId, $namaTier, $outletId ?? 0]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Hitung biaya tambahan untuk SATU item baris berdasar tier.
     *
     * @param float  $itemSubtotal  jumlah × harga_satuan
     * @param array  $tier          row dari hl_express_tier (atau null)
     * @return float
     */
    public static function calcItemFee(float $itemSubtotal, ?array $tier): float
    {
        if (!$tier) return 0;
        if (($tier['tipe_biaya'] ?? '') === 'flat') {
            return (float)$tier['nilai_biaya'];
        }
        // percent
        return round($itemSubtotal * ((float)$tier['nilai_biaya'] / 100));
    }

    /**
     * Snapshot tier label utk nota — diambil dari tier yg paling sering
     * dipakai di items, atau yg fee-nya paling besar. Dipakai utk
     * derive hl_transaksi.tipe_order + express_tier_nama (dominant).
     *
     * @param array $itemsWithTier [{express_tier_nama: 'Express 12 Jam', biaya_express: 5000}, ...]
     * @return array {nama: string|null, tipe_order: string}
     */
    public static function dominantTier(array $itemsWithTier): array
    {
        $weights = [];
        foreach ($itemsWithTier as $it) {
            $nama = trim((string)($it['express_tier_nama'] ?? ''));
            if ($nama === '') continue;
            $weights[$nama] = ($weights[$nama] ?? 0) + (float)($it['biaya_express'] ?? 0) + 1;
        }
        if (empty($weights)) return ['nama' => null, 'tipe_order' => 'reguler'];
        arsort($weights);
        $top = array_key_first($weights);
        // Derive tipe_order generic dari nama
        $lower = strtolower($top);
        $tipe = str_contains($lower, 'kilat') ? 'kilat'
              : (str_contains($lower, 'express') ? 'express' : 'custom');
        return ['nama' => $top, 'tipe_order' => $tipe];
    }
}

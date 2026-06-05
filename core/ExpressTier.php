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
     * Semua tier aktif utk tenant ini.
     * Dipakai POS utk render dropdown per item.
     */
    public static function forTenant(int $tenantId): array
    {
        try {
            $st = Database::get()->prepare(
                "SELECT id, nama_tier, estimasi_jam, tipe_biaya, nilai_biaya, urutan
                   FROM hl_express_tier
                  WHERE tenant_id = ? AND is_active = 1
                  ORDER BY urutan ASC, estimasi_jam DESC"
            );
            $st->execute([$tenantId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            // Tabel belum ada → return kosong supaya POS tetap jalan
            return [];
        }
    }

    /**
     * Cari satu tier by nama (utk hitung fee saat save).
     */
    public static function findByNama(int $tenantId, string $namaTier): ?array
    {
        $namaTier = trim($namaTier);
        if ($namaTier === '' || strtolower($namaTier) === 'reguler') return null;
        try {
            $st = Database::get()->prepare(
                "SELECT id, nama_tier, estimasi_jam, tipe_biaya, nilai_biaya
                   FROM hl_express_tier
                  WHERE tenant_id = ? AND nama_tier = ? AND is_active = 1
                  LIMIT 1"
            );
            $st->execute([$tenantId, $namaTier]);
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

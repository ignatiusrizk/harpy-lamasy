<?php
// ══════════════════════════════════════════════════════
// core/ExpressTier.php
//
// Helper utk konfigurasi tier express per layanan.
//
// Konsep:
// - Setiap layanan punya 0+ tier (nama, estimasi_jam, tipe_biaya, nilai)
// - Di POS, user pilih SATU nama tier untuk seluruh nota
// - Sistem cocokkan nama tier ke tiap layanan dlm nota:
//   • Layanan punya tier itu → hitung fee per tipe_biaya
//   • Layanan TIDAK punya tier itu → fee 0 untuk item itu
// - Total biaya_tambahan = sum dari per-item fee
// - Estimasi selesai = tanggal + max(estimasi_jam) dari tier yang
//   matched (utk item-item yang ikut express; layanan reguler pakai
//   estimasi default outlet)
//
// Schema: hl_layanan_express_tier (lihat tenant_schema.sql)
// ══════════════════════════════════════════════════════

class ExpressTier
{
    // ─────────────────────────────────────────────────
    // Ambil semua tier aktif utk satu layanan
    // ─────────────────────────────────────────────────
    public static function forLayanan(int $layananId): array
    {
        $db = Database::get();
        try {
            $st = $db->prepare(
                "SELECT id, nama_tier, estimasi_jam, tipe_biaya, nilai_biaya, urutan
                   FROM hl_layanan_express_tier
                  WHERE layanan_id = ? AND is_active = 1
                  ORDER BY urutan ASC, estimasi_jam DESC"
            );
            $st->execute([$layananId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            // Tabel belum ada (migration belum dijalankan) → return kosong, POS tetap jalan
            return [];
        }
    }

    /**
     * Ambil semua tier dari satu set layanan_id, gabung jadi list unik by nama.
     * Dipakai POS utk populate dropdown "Tipe Order" berdasar layanan-layanan
     * yang ada dalam nota.
     *
     * Return: [
     *   ['nama_tier' => 'Express 12 Jam', 'estimasi_jam' => 12, 'layanan_count' => 3],
     *   ['nama_tier' => 'Kilat 3 Jam',    'estimasi_jam' => 3,  'layanan_count' => 1],
     * ]
     */
    public static function unionForLayananIds(array $layananIds): array
    {
        $layananIds = array_filter(array_map('intval', $layananIds));
        if (empty($layananIds)) return [];

        $db = Database::get();
        $place = implode(',', array_fill(0, count($layananIds), '?'));
        try {
            $st = $db->prepare(
                "SELECT nama_tier, MAX(estimasi_jam) AS estimasi_jam, COUNT(*) AS layanan_count
                   FROM hl_layanan_express_tier
                  WHERE layanan_id IN ($place) AND is_active = 1
                  GROUP BY nama_tier
                  ORDER BY estimasi_jam DESC"
            );
            $st->execute($layananIds);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Hitung biaya tambahan & estimasi jam untuk satu nota berdasar tier yang
     * dipilih dan items dalam nota.
     *
     * @param array  $items    [{layanan_id, jumlah, harga_satuan}, ...]
     * @param string $tierName "Express 12 Jam" dll. Empty/Reguler → biaya 0.
     * @return array {
     *   biaya_tambahan: float,
     *   estimasi_jam:   int,    // max jam dari tier yg matched, default 24
     *   matched_count:  int,    // brp item yg dapat tier match
     *   detail: [               // breakdown per item (debug/struk)
     *     {layanan_id, item_subtotal, fee, matched: true/false}, ...
     *   ]
     * }
     */
    public static function calculate(array $items, string $tierName): array
    {
        $result = [
            'biaya_tambahan' => 0.0,
            'estimasi_jam'   => 24,
            'matched_count'  => 0,
            'detail'         => [],
        ];

        $tierName = trim($tierName);
        if ($tierName === '' || strtolower($tierName) === 'reguler') {
            return $result;
        }

        // Kumpulkan layanan_id unik dari items
        $layananIds = [];
        foreach ($items as $it) {
            $lid = (int)($it['layanan_id'] ?? 0);
            if ($lid > 0) $layananIds[$lid] = true;
        }
        if (empty($layananIds)) return $result;

        // Load tier per layanan (cuma yang match nama_tier dipilih)
        $db = Database::get();
        $place = implode(',', array_fill(0, count($layananIds), '?'));
        try {
            $st = $db->prepare(
                "SELECT layanan_id, estimasi_jam, tipe_biaya, nilai_biaya
                   FROM hl_layanan_express_tier
                  WHERE layanan_id IN ($place) AND nama_tier = ? AND is_active = 1"
            );
            $st->execute([...array_keys($layananIds), $tierName]);
            $tiers = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return $result;
        }

        // Index by layanan_id
        $tierByLayanan = [];
        foreach ($tiers as $t) {
            $tierByLayanan[(int)$t['layanan_id']] = $t;
        }

        $maxJam     = 0;
        $totalFee   = 0.0;
        $matchCount = 0;

        foreach ($items as $it) {
            $lid     = (int)($it['layanan_id'] ?? 0);
            $jumlah  = (float)($it['jumlah'] ?? 0);
            $harga   = (float)($it['harga_satuan'] ?? 0);
            $subItem = $jumlah * $harga;

            $matched = false;
            $fee     = 0.0;
            if (isset($tierByLayanan[$lid])) {
                $tier    = $tierByLayanan[$lid];
                $matched = true;
                $matchCount++;
                if ($tier['tipe_biaya'] === 'flat') {
                    $fee = (float)$tier['nilai_biaya'];
                } else { // percent
                    $fee = $subItem * ((float)$tier['nilai_biaya'] / 100);
                }
                $totalFee += $fee;
                $maxJam   = max($maxJam, (int)$tier['estimasi_jam']);
            }

            $result['detail'][] = [
                'layanan_id'   => $lid,
                'item_subtotal'=> $subItem,
                'fee'          => $fee,
                'matched'      => $matched,
            ];
        }

        $result['biaya_tambahan'] = round($totalFee);
        $result['estimasi_jam']   = $maxJam > 0 ? $maxJam : 24;
        $result['matched_count']  = $matchCount;
        return $result;
    }
}

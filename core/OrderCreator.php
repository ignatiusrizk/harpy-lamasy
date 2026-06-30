<?php
/**
 * OrderCreator — pembuatan order. createOffline() menangani SUBSET offline
 * (layanan+tier+tunai/DP) — SENGAJA terpisah dari jalur pos.php?action=save
 * yang kaya (deposit/redeem/voucher). Lihat plan Global Constraints.
 */
class OrderCreator
{
    private const ONLINE_ONLY_FIELDS = ['redeem_poin','voucher_id','promo_id','reward_id','pakai_deposit'];

    /** @return string[] daftar error (kosong = valid) */
    public static function validateOfflinePayload(array $p, array $validLayananIds, array $validTierIds): array
    {
        $errs = [];
        $items = $p['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            $errs[] = 'Order tanpa item';
            return $errs; // tak lanjut cek item
        }
        foreach ($items as $i => $it) {
            $lid = (int)($it['layanan_id'] ?? 0);
            if (!in_array($lid, $validLayananIds, true)) {
                $errs[] = "Layanan tidak dikenal (item ".($i+1).")";
            }
            $tid = (int)($it['tier_id'] ?? 0);
            if ($tid > 0 && !in_array($tid, $validTierIds, true)) {
                $errs[] = "Tier tidak dikenal (item ".($i+1).")";
            }
            if ((float)($it['qty'] ?? 0) <= 0) {
                $errs[] = "Qty tidak valid (item ".($i+1).")";
            }
        }
        $total = (float)($p['total'] ?? 0);
        $dp    = (float)($p['dp'] ?? 0);
        if ($total < 0)         $errs[] = 'Total negatif';
        if ($dp < 0)            $errs[] = 'DP negatif';
        if ($dp > $total)       $errs[] = 'DP melebihi total';
        if (($p['metode'] ?? 'cash') !== 'cash') $errs[] = 'Metode offline harus tunai';
        foreach (self::ONLINE_ONLY_FIELDS as $f) {
            if (!empty($p[$f])) $errs[] = "Field online-only tidak diizinkan offline: $f";
        }
        return $errs;
    }
}

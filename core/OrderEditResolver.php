<?php
// core/OrderEditResolver.php — keputusan murni utk edit order LUNAS yang mengubah total.
// Tanpa DB/sesi: input konteks angka → output keputusan (need_confirm / apply / error).
// Eksekusi uangnya (kas/deposit) dilakukan pemanggil (orders.php) di transaksinya sendiri.

class OrderEditResolver
{
    public static function resolve(array $ctx): array
    {
        $sbayarLama = (string)($ctx['sbayar_lama'] ?? '');
        $totalLama  = (float)($ctx['total_lama'] ?? 0);
        $dpLama     = (float)($ctx['dp_lama'] ?? 0);
        $totalBaru  = (float)($ctx['total_baru'] ?? 0);
        $berubah    = (bool)($ctx['berubah'] ?? false);
        $resolusi   = $ctx['resolusi'] ?? null;
        $punyaPel   = (bool)($ctx['punya_pelanggan'] ?? false);

        // Gerbang hanya utk order lunas yang komposisinya berubah & totalnya bergeser
        if ($sbayarLama !== 'lunas' || !$berubah || $totalBaru == $totalLama) {
            return ['gate' => false];
        }

        $naik    = $totalBaru > $dpLama;
        $selisih = $naik ? $totalBaru - $dpLama : $dpLama - $totalBaru;

        if ($resolusi === null || $resolusi === '') {
            return [
                'gate'         => true,
                'need_confirm' => $naik ? 'kurang_bayar' : 'kelebihan',
                'selisih'      => $selisih,
                'total_baru'   => $totalBaru,
                'bisa_deposit' => $punyaPel,
            ];
        }

        if ($naik) {
            if ($resolusi !== 'tagih') return ['gate' => true, 'error' => 'Resolusi tidak sesuai'];
            return ['gate' => true, 'apply' => [
                'dp' => $dpLama, 'sisa' => max(0.0, $totalBaru - $dpLama),
                'status' => 'dp', 'aksi' => 'tagih', 'selisih' => $selisih,
            ]];
        }

        // Turun (kelebihan bayar)
        if ($resolusi === 'ke_deposit' && !$punyaPel) {
            return ['gate' => true, 'error' => 'Order tanpa pelanggan terdaftar — pilih refund tunai'];
        }
        if (!in_array($resolusi, ['refund_tunai', 'ke_deposit'], true)) {
            return ['gate' => true, 'error' => 'Resolusi tidak sesuai'];
        }
        return ['gate' => true, 'apply' => [
            'dp' => $totalBaru, 'sisa' => 0.0,
            'status' => 'lunas', 'aksi' => $resolusi, 'selisih' => $selisih,
        ]];
    }
}

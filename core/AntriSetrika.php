<?php
/**
 * AntriSetrika — aturan urutan antrean setrika (kolom Kanban 'antri_setrika').
 * Express dulu (deadline terdekat), lalu order biasa FIFO menurut waktu masuk antrean.
 */
final class AntriSetrika
{
    /** Jumlah antrean di atas ini → header kolom diberi warna peringatan. */
    public const THRESHOLD = 10;

    public static function deadline(array $r): int
    {
        if (!empty($r['estimasi_selesai'])) return (int)strtotime($r['estimasi_selesai']);
        $jam = (int)($r['estimasi_jam'] ?? 0) ?: 24;
        return (int)strtotime($r['created_at']) + $jam * 3600;
    }

    public static function sort(array $rows): array
    {
        usort($rows, function (array $a, array $b): int {
            $ea = !empty($a['is_express']);
            $eb = !empty($b['is_express']);
            if ($ea !== $eb) return $ea ? -1 : 1;
            if ($ea) {
                $c = self::deadline($a) <=> self::deadline($b);
            } else {
                $c = strtotime($a['antri_at'] ?: $a['created_at']) <=> strtotime($b['antri_at'] ?: $b['created_at']);
            }
            return $c ?: ((int)$a['id'] <=> (int)$b['id']);
        });
        return $rows;
    }
}

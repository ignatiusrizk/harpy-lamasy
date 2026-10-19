<?php
// tests/antri_setrika_test.php — jalankan: php tests/antri_setrika_test.php
require __DIR__ . '/../core/AntriSetrika.php';

function t(string $name, bool $ok): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) { $GLOBALS['fail'] = true; }
}
$row = fn(int $id, int $exp, ?string $antri, ?string $est, string $created = '2026-10-19 08:00:00', $jam = 24) =>
    ['id'=>$id,'is_express'=>$exp,'antri_at'=>$antri,'estimasi_selesai'=>$est,'created_at'=>$created,'estimasi_jam'=>$jam];

$rows = [
    $row(1, 0, '2026-10-19 10:00:00', null),
    $row(2, 0, '2026-10-19 09:00:00', null),
    $row(3, 1, '2026-10-19 11:00:00', '2026-10-19 18:00:00'),
    $row(4, 1, '2026-10-19 12:00:00', '2026-10-19 15:00:00'),
    $row(5, 0, null, null, '2026-10-19 07:00:00'),
];
$ids = array_column(AntriSetrika::sort($rows), 'id');
t('express dulu, deadline terdekat', array_slice($ids, 0, 2) === [4, 3]);
t('biasa FIFO (antri_at; null pakai created_at)', array_slice($ids, 2) === [5, 2, 1]);
t('deadline fallback created_at + estimasi_jam',
    AntriSetrika::deadline($row(9, 0, null, null, '2026-10-19 08:00:00', 6)) === strtotime('2026-10-19 14:00:00'));
t('deadline default 24 jam bila estimasi_jam kosong',
    AntriSetrika::deadline($row(9, 0, null, null, '2026-10-19 08:00:00', 0)) === strtotime('2026-10-20 08:00:00'));
t('threshold 10', AntriSetrika::THRESHOLD === 10);
exit(empty($GLOBALS['fail']) ? 0 : 1);

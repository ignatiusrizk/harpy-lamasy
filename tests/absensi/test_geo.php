<?php
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../core/Geo.php';

// titik sama → 0
eqv(round(Geo::haversineMeters(-6.2, 106.8, -6.2, 106.8)), 0.0, 'titik sama = 0 m');

// 1 derajat lintang ≈ 111.19 km (toleransi ±0.5km)
$d = Geo::haversineMeters(0.0, 0.0, 1.0, 0.0);
ok($d > 110690 && $d < 111690, '1° lintang ≈ 111km (got ' . round($d) . ')');

// ~100m: 0.0009° lintang ≈ 100m (toleransi ±10m)
$d2 = Geo::haversineMeters(-6.200000, 106.800000, -6.199100, 106.800000);
ok($d2 > 90 && $d2 < 110, '~0.0009° lintang ≈ 100m (got ' . round($d2,1) . ')');

// simetris
$a = Geo::haversineMeters(-6.21, 106.81, -6.20, 106.80);
$b = Geo::haversineMeters(-6.20, 106.80, -6.21, 106.81);
eqv(round($a,3), round($b,3), 'simetris');

echo "ALL OK\n";

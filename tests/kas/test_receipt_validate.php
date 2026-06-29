<?php
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../core/ReceiptScanner.php';

// 1. struk valid
$r = ReceiptScanner::validate(['is_receipt'=>true,'jumlah'=>85000,'tanggal'=>'2026-06-20','keterangan'=>'Toko Makmur deterjen','kategori'=>'Bahan']);
eqv($r['ok'], true, 'struk valid ok');
eqv($r['jumlah'], 85000, 'jumlah int');
eqv($r['tanggal'], '2026-06-20', 'tanggal valid kept');
eqv($r['kategori'], 'Bahan', 'kategori kept');

// 2. jumlah dengan pemisah ribuan / "Rp"
$r2 = ReceiptScanner::validate(['is_receipt'=>true,'jumlah'=>'Rp 85.000','keterangan'=>'x']);
eqv($r2['ok'], true, 'jumlah string berformat ok');
eqv($r2['jumlah'], 85000, 'Rp 85.000 → 85000');

// 3. is_receipt false → gagal
$r3 = ReceiptScanner::validate(['is_receipt'=>false,'jumlah'=>5000]);
eqv($r3['ok'], false, 'bukan struk → gagal');

// 4. jumlah 0/negatif → gagal
eqv(ReceiptScanner::validate(['is_receipt'=>true,'jumlah'=>0])['ok'], false, 'jumlah 0 gagal');
eqv(ReceiptScanner::validate(['is_receipt'=>true,'jumlah'=>-100])['ok'], false, 'jumlah negatif gagal');

// 5. tanggal invalid / masa depan jauh → null
$r5 = ReceiptScanner::validate(['is_receipt'=>true,'jumlah'=>1000,'tanggal'=>'bukan-tanggal','keterangan'=>'x']);
eqv($r5['tanggal'], null, 'tanggal invalid → null');
$r6 = ReceiptScanner::validate(['is_receipt'=>true,'jumlah'=>1000,'tanggal'=>'2099-01-01','keterangan'=>'x']);
eqv($r6['tanggal'], null, 'tanggal masa depan jauh → null');

// 6. keterangan kosong → fallback
$r7 = ReceiptScanner::validate(['is_receipt'=>true,'jumlah'=>1000,'keterangan'=>'']);
eqv($r7['keterangan'], 'Belanja (scan struk)', 'keterangan kosong → fallback');

// scan() dengan mock
$mock = fn(string $p) => ['is_receipt'=>true,'jumlah'=>'12.500','tanggal'=>'2026-06-25','keterangan'=>'Indomaret','kategori'=>'Operasional'];
$rs = ReceiptScanner::scan('BASE64', 'image/jpeg', $mock);
eqv($rs['ok'], true, 'scan mock ok');
eqv($rs['jumlah'], 12500, 'scan jumlah 12.500 → 12500');

echo "ALL OK\n";

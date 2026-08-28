<?php
// tests/struk/test_payment_aid.php
require __DIR__ . '/../_assert.php';
require dirname(__DIR__, 2) . '/core/StrukGenerator.php';

// ── Helper bikin data dasar ────────────────────────────
function baseTrx(array $over = []): array {
    return array_merge([
        'status_bayar' => 'belum_bayar',
        'sisa_bayar'   => 50000,
        'metode_bayar' => 'qris',
    ], $over);
}
function baseTmpl(array $over = []): array {
    return array_merge([
        'show_qris'          => 1,
        'show_rekening'      => 1,
        'rekening_bank'      => 'BCA',
        'rekening_nomor'     => '1234567890',
        'rekening_atas_nama' => 'Budi Laundry',
    ], $over);
}
function baseOutlet(array $over = []): array {
    return array_merge([
        'qris_image' => '/assets/outlet-qris/foo.png',
        'qris_label' => 'BCA - Budi Laundry',
    ], $over);
}

// ── Kasus: Lunas → tidak ada alat bayar ────────────────
$aid = StrukGenerator::paymentAidFor(baseTrx(['status_bayar' => 'lunas']), baseTmpl(), baseOutlet());
ok($aid === null, 'Lunas => null');

// ── Kasus: belum_bayar tapi sisa_bayar 0 → null ────────
$aid = StrukGenerator::paymentAidFor(baseTrx(['sisa_bayar' => 0]), baseTmpl(), baseOutlet());
ok($aid === null, 'sisa_bayar=0 => null walau status belum_bayar');

// ── Kasus: metode qris, semua syarat terpenuhi → qris ──
$aid = StrukGenerator::paymentAidFor(baseTrx(), baseTmpl(), baseOutlet());
ok($aid !== null && $aid['type'] === 'qris', 'metode qris lengkap => type qris');
eqv($aid['image'], '/assets/outlet-qris/foo.png', 'qris image benar');
eqv($aid['sisa_bayar'], 50000, 'sisa_bayar ikut kebawa (qris)');

// ── Kasus: metode qris tapi outlet belum upload qris_image → null ──
$aid = StrukGenerator::paymentAidFor(baseTrx(), baseTmpl(), baseOutlet(['qris_image' => null]));
ok($aid === null, 'metode qris tapi qris_image kosong => null');

// ── Kasus: metode qris tapi toggle show_qris OFF → null ────
$aid = StrukGenerator::paymentAidFor(baseTrx(), baseTmpl(['show_qris' => 0]), baseOutlet());
ok($aid === null, 'metode qris tapi show_qris=0 => null');

// ── Kasus: metode transfer, semua syarat terpenuhi → rekening ──
$aid = StrukGenerator::paymentAidFor(baseTrx(['metode_bayar' => 'transfer']), baseTmpl(), baseOutlet());
ok($aid !== null && $aid['type'] === 'rekening', 'metode transfer lengkap => type rekening');
eqv($aid['bank'], 'BCA', 'rekening bank benar');
eqv($aid['nomor'], '1234567890', 'rekening nomor benar');

// ── Kasus: metode transfer tapi rekening_nomor kosong di template → null ──
$aid = StrukGenerator::paymentAidFor(
    baseTrx(['metode_bayar' => 'transfer']),
    baseTmpl(['rekening_nomor' => '']),
    baseOutlet()
);
ok($aid === null, 'metode transfer tapi rekening_nomor kosong => null');

// ── Kasus: metode cash → null (tidak ada alat bayar digital relevan) ──
$aid = StrukGenerator::paymentAidFor(baseTrx(['metode_bayar' => 'cash']), baseTmpl(), baseOutlet());
ok($aid === null, 'metode cash => null');

// ── waPaymentNudgeLine() ────────────────────────────────
eqv(StrukGenerator::waPaymentNudgeLine(null), '', 'nudge kosong kalau aid null');
$qrisAid = ['type' => 'qris', 'image' => 'x', 'label' => null, 'sisa_bayar' => 1000];
ok(str_contains(StrukGenerator::waPaymentNudgeLine($qrisAid), 'QRIS'), 'nudge qris mention QRIS');
$rekAid = ['type' => 'rekening', 'bank' => 'BCA', 'nomor' => '111', 'atas_nama' => null, 'sisa_bayar' => 1000];
$nudge = StrukGenerator::waPaymentNudgeLine($rekAid);
ok(str_contains($nudge, 'BCA') && str_contains($nudge, '111'), 'nudge rekening mention bank+nomor');

// ── renderThermal() render blok QRIS/Rekening ──────────
$trxQris = [
    'no_order' => 'TEST-001', 'total' => 50000, 'subtotal' => 50000,
    'diskon' => 0, 'biaya_tambahan' => 0, 'dp' => 0,
    'status_bayar' => 'belum_bayar', 'sisa_bayar' => 50000,
    'metode_bayar' => 'qris', 'tipe_order' => 'reguler',
    'created_at' => date('Y-m-d H:i:s'), 'tanggal' => date('Y-m-d H:i:s'),
];
$tmpl = array_merge(StrukGenerator::defaultTemplate('retail'), baseTmpl());
$outlet = baseOutlet(['nama_outlet' => 'Test Outlet']);
$html = StrukGenerator::renderThermal($trxQris, [], $tmpl, null, null, $outlet, 58);
ok(str_contains($html, '/assets/outlet-qris/foo.png'), 'renderThermal render gambar QRIS saat metode qris+belum lunas');
ok(str_contains($html, 'Sisa Bayar: Rp 50.000') || str_contains($html, 'Sisa Bayar: Rp 50,000'), 'renderThermal tampilkan sisa bayar dekat QRIS');

$trxTransfer = array_merge($trxQris, ['metode_bayar' => 'transfer']);
$html2 = StrukGenerator::renderThermal($trxTransfer, [], $tmpl, null, null, $outlet, 58);
ok(str_contains($html2, 'BCA') && str_contains($html2, '1234567890'), 'renderThermal render info rekening saat metode transfer+belum lunas');

$trxLunas = array_merge($trxQris, ['status_bayar' => 'lunas', 'sisa_bayar' => 0]);
$html3 = StrukGenerator::renderThermal($trxLunas, [], $tmpl, null, null, $outlet, 58);
ok(!str_contains($html3, '/assets/outlet-qris/foo.png'), 'renderThermal TIDAK render QRIS kalau sudah lunas');

// ── Biaya Lainnya muncul di struk (renderThermal & renderPdf) ──
$trxBiayaLainnya = array_merge($trxQris, [
    'metode_bayar' => 'cash',
    'biaya_lainnya' => 7000,
    'biaya_lainnya_label' => 'Biaya Packing Kardus',
]);
$htmlBl = StrukGenerator::renderThermal($trxBiayaLainnya, [], $tmpl, null, null, $outlet, 58);
ok(str_contains($htmlBl, 'Biaya Packing Kardus'), 'renderThermal tampilkan label biaya_lainnya custom');
ok(str_contains($htmlBl, 'Rp 7.000') || str_contains($htmlBl, 'Rp 7,000'), 'renderThermal tampilkan nominal biaya_lainnya');

$trxBiayaLainnyaNoLabel = array_merge($trxBiayaLainnya, ['biaya_lainnya_label' => '']);
$htmlBl2 = StrukGenerator::renderThermal($trxBiayaLainnyaNoLabel, [], $tmpl, null, null, $outlet, 58);
ok(str_contains($htmlBl2, 'Biaya Lainnya'), 'renderThermal fallback label generik "Biaya Lainnya" kalau kosong');

$trxNoBiayaLainnya = array_merge($trxQris, ['metode_bayar' => 'cash', 'biaya_lainnya' => 0]);
$htmlBl3 = StrukGenerator::renderThermal($trxNoBiayaLainnya, [], $tmpl, null, null, $outlet, 58);
ok(!str_contains($htmlBl3, 'Biaya Lainnya') && !str_contains($htmlBl3, 'Biaya Packing'), 'renderThermal TIDAK render baris biaya_lainnya kalau 0');

echo "\nAll tests passed.\n";

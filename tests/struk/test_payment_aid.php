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

// ── Biaya Lainnya multi-baris muncul di struk (renderThermal & renderPdf) ──
$trxBiayaLainnya = array_merge($trxQris, [
    'metode_bayar' => 'cash',
    'biaya_lainnya' => 2600, // rollup, dipakai $hasBreakdown check
    '_biaya_lainnya_rows' => [
        ['nama' => 'Biaya Admin', 'nominal' => 2000],
        ['nama' => 'PPN 2%', 'nominal' => 600],
    ],
]);
$htmlBl = StrukGenerator::renderThermal($trxBiayaLainnya, [], $tmpl, null, null, $outlet, 58);
ok(str_contains($htmlBl, 'Biaya Admin'), 'renderThermal tampilkan baris pertama breakdown');
ok(str_contains($htmlBl, 'PPN 2%'), 'renderThermal tampilkan baris kedua breakdown (multi-baris)');
ok(str_contains($htmlBl, 'Rp 2.000') || str_contains($htmlBl, 'Rp 2,000'), 'renderThermal tampilkan nominal baris pertama');
ok(str_contains($htmlBl, 'Rp 600'), 'renderThermal tampilkan nominal baris kedua');

$trxNoBiayaLainnya = array_merge($trxQris, ['metode_bayar' => 'cash', 'biaya_lainnya' => 0, '_biaya_lainnya_rows' => []]);
$htmlBl3 = StrukGenerator::renderThermal($trxNoBiayaLainnya, [], $tmpl, null, null, $outlet, 58);
ok(!str_contains($htmlBl3, 'Biaya Admin') && !str_contains($htmlBl3, 'PPN'), 'renderThermal TIDAK render apa pun kalau breakdown kosong');

// ── renderPdf() coverage — sama persis, multi-baris ──────────
$pdfBl = StrukGenerator::renderPdf($trxBiayaLainnya, [], $tmpl, null, null, $outlet, 'a4');
ok(str_contains($pdfBl, 'Biaya Admin'), 'renderPdf tampilkan baris pertama breakdown');
ok(str_contains($pdfBl, 'PPN 2%'), 'renderPdf tampilkan baris kedua breakdown');

$pdfBl4 = StrukGenerator::renderPdf($trxNoBiayaLainnya, [], $tmpl, null, null, $outlet, 'a4');
ok(!str_contains($pdfBl4, 'Biaya Admin') && !str_contains($pdfBl4, 'PPN'), 'renderPdf TIDAK render apa pun kalau breakdown kosong');

echo "\nAll tests passed.\n";

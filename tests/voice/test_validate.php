<?php
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../core/VoiceOrderParser.php';

$catalog = [
    ['id' => 12, 'nama' => 'Cuci Setrika Reguler', 'satuan' => 'kg'],
    ['id' => 5,  'nama' => 'Cuci Kering',          'satuan' => 'kg'],
];

// 1. item valid (di katalog) dipertahankan + qty di-cast int
$r = VoiceOrderParser::validate([
    'nama' => 'Heri',
    'items' => [['layanan_id' => 12, 'qty' => '3'], ['layanan_id' => 5, 'qty' => 2]],
    'bayar' => ['status' => 'lunas', 'metode' => 'tunai'],
    'unmatched' => ['pewangi premium'],
], $catalog);
eqv($r['nama'], 'Heri', 'nama lolos');
eqv(count($r['items']), 2, '2 item valid');
eqv($r['items'][0]['layanan_id'], 12, 'id 12 dipertahankan');
eqv($r['items'][0]['nama_katalog'], 'Cuci Setrika Reguler', 'nama_katalog dari katalog');
eqv($r['items'][0]['qty'], 3, 'qty string "3" → int 3');
eqv($r['bayar']['status'], 'lunas', 'status lolos');
eqv($r['bayar']['metode'], 'cash', 'metode tunai → cash (normalize ke POS canonical)');
eqv($r['unmatched'], ['pewangi premium'], 'unmatched passthrough');

// 1b. input 'cash' langsung juga lolos sebagai 'cash'
$r1b = VoiceOrderParser::validate([
    'items' => [['layanan_id' => 12, 'qty' => 1]],
    'bayar' => ['status' => 'lunas', 'metode' => 'cash'],
], $catalog);
eqv($r1b['bayar']['metode'], 'cash', 'metode cash langsung → cash');

// 2. item dengan id di luar katalog dibuang
$r2 = VoiceOrderParser::validate([
    'items' => [['layanan_id' => 999, 'qty' => 1], ['layanan_id' => 12, 'qty' => 1]],
], $catalog);
eqv(count($r2['items']), 1, 'id 999 (luar katalog) dibuang');
eqv($r2['items'][0]['layanan_id'], 12, 'sisa hanya id valid');

// 3. qty < 1 atau hilang → default 1
$r3 = VoiceOrderParser::validate(['items' => [['layanan_id' => 12]]], $catalog);
eqv($r3['items'][0]['qty'], 1, 'qty hilang → 1');

// 4. bayar.status/metode di luar enum → null
$r4 = VoiceOrderParser::validate([
    'items' => [['layanan_id' => 12, 'qty' => 1]],
    'bayar' => ['status' => 'ngutang', 'metode' => 'gopay'],
], $catalog);
eqv($r4['bayar']['status'], null, 'status invalid → null');
eqv($r4['bayar']['metode'], null, 'metode invalid → null');

// 5. items kosong setelah filter → array kosong (endpoint anggap no_match)
$r5 = VoiceOrderParser::validate(['items' => [['layanan_id' => 999, 'qty' => 1]]], $catalog);
eqv($r5['items'], [], 'semua item invalid → kosong');

// parse() dengan aiFn mock (tanpa jaringan)
$mock = function (string $prompt) {
    return ['nama' => 'Budi', 'items' => [['layanan_id' => 5, 'qty' => 4]], 'bayar' => ['status' => 'dp', 'metode' => 'transfer'], 'unmatched' => []];
};
$rp = VoiceOrderParser::parse('budi cuci kering 4 kilo dp transfer', $catalog, $mock);
eqv($rp['items'][0]['layanan_id'], 5, 'parse pakai aiFn mock → item valid');
eqv($rp['bayar']['status'], 'dp', 'parse bayar dp');

echo "ALL OK\n";

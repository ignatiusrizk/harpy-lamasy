<?php
// Test OrderEditResolver::resolve — murni, tanpa DB/sesi.
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../core/OrderEditResolver.php';

$base = ['sbayar_lama'=>'lunas','total_lama'=>50000.0,'dp_lama'=>50000.0,
         'total_baru'=>75000.0,'berubah'=>true,'resolusi'=>null,'punya_pelanggan'=>true];

// ── Tanpa gerbang ──
$r = OrderEditResolver::resolve(['sbayar_lama'=>'dp'] + $base);
eqv($r['gate'], false, "bukan lunas → tanpa gerbang");
$r = OrderEditResolver::resolve(['total_baru'=>50000.0] + $base);
eqv($r['gate'], false, "total sama → tanpa gerbang");
$r = OrderEditResolver::resolve(['berubah'=>false] + $base);
eqv($r['gate'], false, "item/diskon tak berubah → tanpa gerbang");

// ── Naik: butuh konfirmasi ──
$r = OrderEditResolver::resolve($base);
eqv($r['need_confirm'] ?? '', 'kurang_bayar', "naik tanpa resolusi → need_confirm kurang_bayar");
eqv($r['selisih'], 25000.0, "selisih naik = total_baru - dp_lama");
eqv($r['bisa_deposit'], true, "bisa_deposit ikut punya_pelanggan");

// ── Naik + tagih ──
$r = OrderEditResolver::resolve(['resolusi'=>'tagih'] + $base);
eqv($r['apply']['dp'], 50000.0, "tagih: dp dipertahankan");
eqv($r['apply']['sisa'], 25000.0, "tagih: sisa = selisih");
eqv($r['apply']['status'], 'dp', "tagih: status turun ke dp");
eqv($r['apply']['aksi'], 'tagih', "tagih: aksi tercatat");

// ── Naik + resolusi salah arah ──
$r = OrderEditResolver::resolve(['resolusi'=>'refund_tunai'] + $base);
ok(isset($r['error']), "refund saat naik → error resolusi tidak sesuai");

// ── Turun: butuh konfirmasi ──
$turun = ['total_baru'=>40000.0] + $base;
$r = OrderEditResolver::resolve($turun);
eqv($r['need_confirm'] ?? '', 'kelebihan', "turun tanpa resolusi → need_confirm kelebihan");
eqv($r['selisih'], 10000.0, "selisih turun = dp_lama - total_baru");
$r = OrderEditResolver::resolve(['punya_pelanggan'=>false] + $turun);
eqv($r['bisa_deposit'], false, "tanpa pelanggan → bisa_deposit false");

// ── Turun + refund tunai ──
$r = OrderEditResolver::resolve(['resolusi'=>'refund_tunai'] + $turun);
eqv($r['apply']['dp'], 40000.0, "refund: dp = total baru");
eqv($r['apply']['sisa'], 0.0, "refund: sisa 0 (tak pernah negatif)");
eqv($r['apply']['status'], 'lunas', "refund: tetap lunas");
eqv($r['apply']['aksi'], 'refund_tunai', "refund: aksi tercatat");
eqv($r['apply']['selisih'], 10000.0, "refund: selisih utk kas keluar");

// ── Turun + ke_deposit ──
$r = OrderEditResolver::resolve(['resolusi'=>'ke_deposit'] + $turun);
eqv($r['apply']['aksi'], 'ke_deposit', "ke_deposit: aksi tercatat");
$r = OrderEditResolver::resolve(['resolusi'=>'ke_deposit','punya_pelanggan'=>false] + $turun);
ok(isset($r['error']), "ke_deposit tanpa pelanggan → error");

// ── Turun + tagih (salah arah) ──
$r = OrderEditResolver::resolve(['resolusi'=>'tagih'] + $turun);
ok(isset($r['error']), "tagih saat turun → error");

echo "ALL PASS\n";

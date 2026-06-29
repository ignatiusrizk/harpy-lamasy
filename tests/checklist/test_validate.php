<?php
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../core/Checklist.php';

$items = [
    ['text' => 'Sapu lantai',        'required' => 1, 'photo' => 0],
    ['text' => 'Foto mesin bersih',  'required' => 0, 'photo' => 1],
    ['text' => 'Cek stok',           'required' => 0, 'photo' => 0],
];

// 1. semua valid: required dicentang, photo dicentang + ada foto_url
$n = Checklist::validateAnswers($items, [
    0 => ['checked' => 1],
    1 => ['checked' => 1, 'foto_url' => '/uploads/foto_checklist/a.jpg'],
    2 => ['checked' => 0],
]);
eqv($n, 2, 'checked count = 2');

// 2. required (idx0) tak dicentang → throw
$threw = false;
try { Checklist::validateAnswers($items, [1=>['checked'=>0],2=>['checked'=>0]]); }
catch (Throwable $e) { $threw = true; }
ok($threw, 'required tak dicentang → throw');

// 3. photo item dicentang TANPA foto_url → throw
$threw = false;
try { Checklist::validateAnswers($items, [0=>['checked'=>1], 1=>['checked'=>1]]); }
catch (Throwable $e) { $threw = true; }
ok($threw, 'wajib-foto dicentang tanpa foto → throw');

// 4. photo item TIDAK dicentang tanpa foto → lolos
$n4 = Checklist::validateAnswers($items, [0=>['checked'=>1], 1=>['checked'=>0]]);
eqv($n4, 1, 'wajib-foto tak dicentang → tak perlu foto, lolos');

// 5. foto_url kosong string dianggap kosong
$threw = false;
try { Checklist::validateAnswers($items, [0=>['checked'=>1], 1=>['checked'=>1,'foto_url'=>'  ']]); }
catch (Throwable $e) { $threw = true; }
ok($threw, 'foto_url whitespace → dianggap kosong → throw');

echo "ALL OK\n";

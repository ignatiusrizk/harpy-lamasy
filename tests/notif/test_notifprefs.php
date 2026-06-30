<?php
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../core/NotifPrefs.php';

$c = fn($cfg,$kat) => NotifPrefs::channelsFor($cfg,$kat);

eqv($c(['daily_report'=>['email'=>1,'inapp'=>1]], 'daily_report'), ['email','inapp'], 'dua on');
eqv($c(['daily_report'=>['email'=>0,'inapp'=>1]], 'daily_report'), ['inapp'],          'email off');
eqv($c(['daily_report'=>['email'=>1,'inapp'=>0]], 'daily_report'), ['email'],          'inapp off');
eqv($c(['daily_report'=>['email'=>0,'inapp'=>0]], 'daily_report'), [],                 'dua off');
eqv($c(['alert_anomali'=>['email'=>0,'inapp'=>0]], 'daily_report'), ['email','inapp'], 'kategori absen → default');
eqv($c([], 'alert_anomali'), ['email','inapp'], 'cfg kosong → default');
eqv($c(['daily_report'=>['email'=>1]], 'daily_report'), ['email','inapp'], 'inapp key absen → default on');

echo "ALL OK\n";

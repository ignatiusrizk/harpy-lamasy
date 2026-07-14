<?php
require_once __DIR__ . '/middleware/tenant_guard.php';
require_once __DIR__ . '/core/Database.php';

$tenantId = (int)($_SESSION['tenant_id'] ?? 0);
$orderId = $_GET['order_id'] ?? '';

$db = Database::get();
$st = $db->prepare("SELECT * FROM saas_payments WHERE order_id=? AND tenant_id=?");
$st->execute([$orderId, $tenantId]);
$payment = $st->fetch(PDO::FETCH_ASSOC);

if (!$payment) { header('Location: /dashboard'); exit; }

$title = '';
$body = '';
$cta = ['url' => '/dashboard', 'label' => 'Ke Dashboard'];
$autoRefresh = false;

// Snap "finish" bisa mendarat SEBELUM webhook settle tiba — jangan klaim sukses
// saat status masih pending; refresh otomatis sampai webhook mengubah status.
if ($payment['status'] === 'pending') {
    $autoRefresh = true;
    $title = '⏳ Menunggu Konfirmasi…';
    $body  = 'Pembayaranmu sedang dikonfirmasi otomatis (biasanya beberapa detik). Halaman ini akan menyegarkan sendiri.';
    $cta   = ['url' => '/billing-checkout?' . http_build_query(['type' => $payment['type'],
                'bundle_id' => $payment['ref_bundle_id'], 'outlet_id' => $payment['ref_outlet_id']]),
              'label' => 'Kembali ke Halaman Bayar'];
}
elseif (in_array($payment['status'], ['expired', 'failed', 'cancelled'], true)) {
    $title = '⚠️ Pembayaran Belum Selesai';
    $body  = 'Status pembayaran: ' . htmlspecialchars($payment['status']) . '. Kalau kamu merasa sudah membayar, tunggu 1–2 menit lalu muat ulang; kalau belum, ulangi dari halaman top-up/aktivasi.';
}
elseif ($payment['type'] === 'topup_coin') {
    $b = $db->prepare("SELECT coin_didapat, nama FROM saas_coin_bundles WHERE id=?");
    $b->execute([$payment['ref_bundle_id']]);
    $bundle = $b->fetch(PDO::FETCH_ASSOC);
    $tn = $db->prepare("SELECT coin_balance FROM tenants WHERE id=?");
    $tn->execute([$tenantId]);
    $newBal = (int)$tn->fetchColumn();
    $title = '🎉 Top-up Berhasil!';
    $body = "+{$bundle['coin_didapat']} coin dari {$bundle['nama']}. Saldo sekarang: " . number_format($newBal) . " coin.";
}
elseif ($payment['type'] === 'setup_fee') {
    $title = '🚀 Akun Aktif!';
    $body = 'Setup fee berhasil. Akun LAMASY kamu sudah aktif penuh.';
    $cta = ['url' => '/dashboard', 'label' => 'Mulai Pakai LAMASY'];
}
elseif ($payment['type'] === 'outlet_activation') {
    $o = $db->prepare("SELECT nama_outlet FROM outlets WHERE id=?");
    $o->execute([$payment['ref_outlet_id']]);
    $title = '🏪 Outlet Aktif!';
    $body = "Outlet {$o->fetchColumn()} sudah aktif dan siap dipakai.";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<?php if ($autoRefresh): ?><meta http-equiv="refresh" content="4"><?php endif; ?>
<title><?= $autoRefresh ? 'Menunggu Konfirmasi' : 'Berhasil' ?> — LAMASY</title>
<link rel="stylesheet" href="/harpy-erp.css?v=<?= date('Ymd') ?>">
<style>
  body { font-family: 'Plus Jakarta Sans', sans-serif; background: #0F1C3A; color: #fff; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
  .card { background: #162348; border: 1px solid rgba(53,232,213,.3); border-radius: 16px; padding: 38px; text-align: center; max-width: 460px; }
  h1 { font-size: 26px; margin-bottom: 12px; color: #35E8D5; }
  p { color: #94A3B8; font-size: 14.5px; line-height: 1.65; margin-bottom: 28px; }
  a.btn { display: inline-block; background: #35E8D5; color: #0F1C3A; padding: 14px 32px; border-radius: 8px; font-weight: 700; text-decoration: none; }
</style>
</head>
<body>
<div class="card">
  <h1><?= htmlspecialchars($title) ?></h1>
  <p><?= htmlspecialchars($body) ?></p>
  <a href="<?= htmlspecialchars($cta['url']) ?>" class="btn"><?= htmlspecialchars($cta['label']) ?></a>
</div>
</body>
</html>

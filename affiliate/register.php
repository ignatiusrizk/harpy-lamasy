<?php
define('ROOT', dirname(__DIR__));
require_once ROOT . '/core/Database.php';
session_start();
require_once ROOT . '/core/AffiliateAuth.php';

// Redirect kalau sudah login
if (AffiliateAuth::current()) {
    header('Location: /affiliate/dashboard');
    exit;
}

$err = '';
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    $csrf = trim($_POST['aff_csrf'] ?? '');
    if (!$csrf || !hash_equals($_SESSION['aff_csrf'] ?? '', $csrf)) {
        $err = 'Token keamanan tidak valid. Silakan muat ulang halaman.';
    } else {
        $old = $_POST;
        $result = AffiliateAuth::signup($_POST);
        if ($result['ok']) {
            header('Location: /affiliate/dashboard');
            exit;
        }
        $err = $result['error'];
    }
}

// Generate CSRF token
if (empty($_SESSION['aff_csrf'])) {
    $_SESSION['aff_csrf'] = bin2hex(random_bytes(16));
}
$csrfToken = $_SESSION['aff_csrf'];

function old(string $k, array $old): string {
    return htmlspecialchars($old[$k] ?? '');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Daftar Affiliate — LAMASY</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
         background: #f5f6fa; display: flex; align-items: center; justify-content: center;
         min-height: 100vh; padding: 24px 16px; }
  .card { background: #fff; border-radius: 12px; box-shadow: 0 2px 16px rgba(0,0,0,.1);
          padding: 40px 36px; width: 100%; max-width: 480px; }
  .logo { text-align: center; margin-bottom: 28px; }
  .logo h1 { font-size: 22px; font-weight: 700; color: #1a1a2e; }
  .logo p  { font-size: 14px; color: #6b7280; margin-top: 4px; }
  label  { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
  input  { width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px;
           font-size: 15px; outline: none; transition: border-color .2s; margin-bottom: 18px; }
  input:focus { border-color: #4f46e5; }
  .section-title { font-size: 13px; font-weight: 700; color: #4f46e5; letter-spacing: .5px;
                   text-transform: uppercase; margin-bottom: 14px; margin-top: 6px; }
  .divider { border: none; border-top: 1px solid #e5e7eb; margin: 4px 0 22px; }
  .btn  { width: 100%; padding: 12px; background: #4f46e5; color: #fff; border: none;
          border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer;
          transition: background .2s; }
  .btn:hover { background: #4338ca; }
  .err  { background: #fee2e2; color: #b91c1c; border-radius: 8px; padding: 10px 14px;
          font-size: 14px; margin-bottom: 18px; }
  .footer-link { text-align: center; font-size: 14px; color: #6b7280; margin-top: 20px; }
  .footer-link a { color: #4f46e5; text-decoration: none; font-weight: 600; }
  .footer-link a:hover { text-decoration: underline; }
  .hint { font-size: 12px; color: #9ca3af; margin-top: -12px; margin-bottom: 18px; }
</style>
</head>
<body>
<div class="card">
  <div class="logo">
    <h1>LAMASY Affiliate</h1>
    <p>Daftar dan mulai dapatkan komisi</p>
  </div>
  <?php if ($err): ?>
  <div class="err"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>
  <form method="POST" action="/affiliate/register">
    <input type="hidden" name="aff_csrf" value="<?= htmlspecialchars($csrfToken) ?>">

    <div class="section-title">Informasi Pribadi</div>

    <label for="nama">Nama Lengkap</label>
    <input type="text" id="nama" name="nama" placeholder="Nama lengkap Anda"
           value="<?= old('nama', $old) ?>" required>

    <label for="email">Email</label>
    <input type="email" id="email" name="email" placeholder="email@contoh.com"
           value="<?= old('email', $old) ?>" required>

    <label for="telepon">Nomor Telepon</label>
    <input type="tel" id="telepon" name="telepon" placeholder="08xxxxxxxxxx"
           value="<?= old('telepon', $old) ?>">

    <label for="password">Password</label>
    <input type="password" id="password" name="password" placeholder="Min 6 karakter" required>
    <p class="hint">Minimal 6 karakter</p>

    <hr class="divider">
    <div class="section-title">Rekening Bank (untuk payout)</div>

    <label for="rekening_bank">Nama Bank</label>
    <input type="text" id="rekening_bank" name="rekening_bank" placeholder="Contoh: BCA, Mandiri, BNI"
           value="<?= old('rekening_bank', $old) ?>">

    <label for="rekening_nomor">Nomor Rekening</label>
    <input type="text" id="rekening_nomor" name="rekening_nomor" placeholder="Nomor rekening"
           value="<?= old('rekening_nomor', $old) ?>">

    <label for="rekening_atas_nama">Atas Nama</label>
    <input type="text" id="rekening_atas_nama" name="rekening_atas_nama" placeholder="Nama pemilik rekening"
           value="<?= old('rekening_atas_nama', $old) ?>">

    <button type="submit" class="btn">Daftar Sekarang</button>
  </form>
  <p class="footer-link">Sudah punya akun? <a href="/affiliate/login">Masuk di sini</a></p>
</div>
</body>
</html>

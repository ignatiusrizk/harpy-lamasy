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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    $csrf = trim($_POST['aff_csrf'] ?? '');
    if (!$csrf || !hash_equals($_SESSION['aff_csrf'] ?? '', $csrf)) {
        $err = 'Token keamanan tidak valid. Silakan muat ulang halaman.';
    } else {
        $result = AffiliateAuth::login(
            (string)($_POST['email'] ?? ''),
            (string)($_POST['password'] ?? '')
        );
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
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login Affiliate — LAMASY</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
         background: #f5f6fa; display: flex; align-items: center; justify-content: center;
         min-height: 100vh; padding: 16px; }
  .card { background: #fff; border-radius: 12px; box-shadow: 0 2px 16px rgba(0,0,0,.1);
          padding: 40px 36px; width: 100%; max-width: 420px; }
  .logo { text-align: center; margin-bottom: 28px; }
  .logo h1 { font-size: 22px; font-weight: 700; color: #1a1a2e; }
  .logo p  { font-size: 14px; color: #6b7280; margin-top: 4px; }
  label  { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
  input  { width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px;
           font-size: 15px; outline: none; transition: border-color .2s; margin-bottom: 18px; }
  input:focus { border-color: #4f46e5; }
  .btn   { width: 100%; padding: 12px; background: #4f46e5; color: #fff; border: none;
           border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer;
           transition: background .2s; }
  .btn:hover { background: #4338ca; }
  .err   { background: #fee2e2; color: #b91c1c; border-radius: 8px; padding: 10px 14px;
           font-size: 14px; margin-bottom: 18px; }
  .footer-link { text-align: center; font-size: 14px; color: #6b7280; margin-top: 20px; }
  .footer-link a { color: #4f46e5; text-decoration: none; font-weight: 600; }
  .footer-link a:hover { text-decoration: underline; }
</style>
</head>
<body>
<div class="card">
  <div class="logo">
    <h1>LAMASY Affiliate</h1>
    <p>Masuk ke akun affiliate Anda</p>
  </div>
  <?php if ($err): ?>
  <div class="err"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>
  <form method="POST" action="/affiliate/login">
    <input type="hidden" name="aff_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
    <label for="email">Email</label>
    <input type="email" id="email" name="email" placeholder="email@contoh.com"
           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
    <label for="password">Password</label>
    <input type="password" id="password" name="password" placeholder="Password" required>
    <button type="submit" class="btn">Masuk</button>
  </form>
  <p class="footer-link">Belum punya akun? <a href="/affiliate/register">Daftar di sini</a></p>
</div>
</body>
</html>

<?php
// p.php — Portal pelanggan login entry via QR token

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure',   1);
ini_set('session.cookie_samesite', 'Strict');
if (session_status() === PHP_SESSION_NONE) session_start();

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

define('ROOT', __DIR__);
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';

$token = trim($_GET['t'] ?? '');
$nextOrder = trim($_GET['o'] ?? '');
$msg = $_GET['msg'] ?? '';

// Rate limit: 5/menit per IP via session
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateKey = 'p_rate_' . hash('sha256', $ip);
$now = time();
$attempts = $_SESSION[$rateKey] ?? [];
$attempts = array_filter($attempts, fn($t) => $t > $now - 60);
if (count($attempts) >= 5) {
    http_response_code(429);
    die('<!doctype html><meta charset=utf-8><div style="font-family:sans-serif;padding:40px;text-align:center;color:#666"><h2>Terlalu banyak percobaan</h2><p>Tunggu 1 menit lalu coba lagi.</p></div>');
}

$err = '';
if ($token) {
    // Validate token format (32-char hex)
    if (!preg_match('/^[a-f0-9]{32}$/i', $token)) {
        $err = 'Format token tidak valid.';
    } else {
        try {
            $db = Database::get();
            $st = $db->prepare("SELECT id, nama FROM hl_pelanggan WHERE portal_token=? AND is_active=1 LIMIT 1");
            $st->execute([$token]);
            $pel = $st->fetch(PDO::FETCH_ASSOC);
            if ($pel) {
                $_SESSION['portal_pelanggan_id'] = (int)$pel['id'];
                // Audit
                try {
                    $logSt = $db->prepare("INSERT INTO hl_audit_log (tenant_id, user_id, aksi, modul, keterangan, ip_address, created_at) VALUES (NULL, NULL, 'portal_login', 'pelanggan', ?, ?, NOW())");
                    $logSt->execute(["pelanggan_id=" . $pel['id'], $ip]);
                } catch (Throwable) { /* audit table beda schema, ignore */ }

                $redirect = $nextOrder && preg_match('/^[A-Z0-9\-]{3,30}$/i', $nextOrder)
                    ? '/pelanggan-order?o=' . urlencode($nextOrder)
                    : '/pelanggan';
                header('Location: ' . $redirect);
                exit;
            }
            $err = 'Token tidak ditemukan atau pelanggan tidak aktif.';
        } catch (Throwable $e) {
            error_log('[p login] ' . $e->getMessage());
            $err = 'Gagal validasi. Coba lagi.';
        }
    }
    // Record failed attempt
    $attempts[] = $now;
    $_SESSION[$rateKey] = $attempts;
}

?>
<!doctype html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Portal Pelanggan — LAMASY</title>
<style>
body{margin:0;padding:40px 20px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:linear-gradient(135deg,#0F1C3A 0%,#1E3A8A 50%,#312E81 100%);min-height:100vh;color:#fff}
.box{max-width:380px;margin:0 auto;background:#fff;color:#1E293B;border-radius:18px;padding:30px 24px;box-shadow:0 20px 60px rgba(0,0,0,.3);text-align:center}
.brand{font-weight:800;color:#0F1C3A;margin-bottom:6px}
.sub{color:#64748B;font-size:14px;margin-bottom:24px}
.err{background:#FEE2E2;color:#991B1B;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:16px}
.info{background:#EFF6FF;color:#1E40AF;padding:14px;border-radius:8px;font-size:13.5px;line-height:1.5;text-align:left}
</style>
</head>
<body>
<div class="box">
  <h1 class="brand">🧺 LAMASY</h1>
  <div class="sub">Portal Pelanggan</div>
  <?php if ($err): ?>
    <div class="err">❌ <?= htmlspecialchars($err) ?></div>
  <?php elseif ($msg === 'login'): ?>
    <div class="err">Silakan login dulu.</div>
  <?php endif; ?>
  <div class="info">
    📷 <strong>Cara Login:</strong><br>
    Scan QR code yang ada di struk laundry kamu. Otomatis masuk ke akun pelanggan.<br><br>
    Belum punya struk? Kunjungi outlet LAMASY terdekat.
  </div>
</div>
</body>
</html>

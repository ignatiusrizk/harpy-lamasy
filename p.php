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
                session_regenerate_id(true);
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
    // Record failed attempt (token ada tapi invalid/not-found)
    $attempts[] = $now;
    $_SESSION[$rateKey] = $attempts;
}

// Tak ada token valid (token kosong / invalid / pelanggan tak aktif) → arahkan ke
// front door publik. Portal tetap QR-only; /cek?msg=portal menampilkan instruksi scan QR.
header('Location: /cek?msg=portal');
exit;

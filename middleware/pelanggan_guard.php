<?php
// middleware/pelanggan_guard.php — Guard untuk portal pelanggan

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure',   1);
ini_set('session.cookie_samesite', 'Strict');
if (session_status() === PHP_SESSION_NONE) session_start();

if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';

// Security headers
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

function currentPelanggan(): ?array {
    static $cached = null;
    if ($cached !== null) return $cached ?: null;
    $id = (int)($_SESSION['portal_pelanggan_id'] ?? 0);
    if ($id <= 0) { $cached = []; return null; }
    try {
        $db = Database::get();
        $st = $db->prepare("SELECT * FROM hl_pelanggan WHERE id=? AND is_active=1 LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $cached = $row ?: [];
        return $row ?: null;
    } catch (Throwable) { $cached = []; return null; }
}

function requirePelangganLogin(): void {
    if (!currentPelanggan()) {
        header('Location: /p?msg=login');
        exit;
    }
}

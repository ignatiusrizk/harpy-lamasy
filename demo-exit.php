<?php
// demo-exit.php — Keluar dari mode demo, clear session
define('ROOT', __DIR__);
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure',   1);
ini_set('session.cookie_samesite', 'Strict');
if (session_status() === PHP_SESSION_NONE) session_start();

// Update demo_sessions — catat ended_at
if (!empty($_SESSION['demo_session_id'])) {
    try {
        Database::get()->prepare(
            "UPDATE saas_demo_sessions SET ended_at=NOW(),
             pages_viewed=?, actions_done=?
             WHERE id=?"
        )->execute([
            $_SESSION['demo_pages_viewed'] ?? 0,
            $_SESSION['demo_actions']      ?? 0,
            (int)$_SESSION['demo_session_id'],
        ]);
    } catch (Throwable) {}
}

// Cek apakah klik CTA register — catat sebagai converted
$converted = !empty($_GET['convert']);
if ($converted && !empty($_SESSION['demo_session_id'])) {
    try {
        Database::get()->prepare(
            "UPDATE saas_demo_sessions SET converted=1 WHERE id=?"
        )->execute([(int)$_SESSION['demo_session_id']]);
    } catch (Throwable) {}
}

// Clear semua session vars demo
session_destroy();

// Redirect
if ($converted) {
    header('Location: /register');
} else {
    header('Location: /landing');
}
exit;

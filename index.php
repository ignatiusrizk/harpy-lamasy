<?php
// ══════════════════════════════════════════════════════
// Entry point — Smart routing untuk root URL
//
//   - Kalau user sudah login → redirect ke dashboard
//   - Kalau belum login      → tampilkan landing page
//
// User yang sudah login bisa tap Login lagi via /login,
// tapi root URL langsung antar mereka ke kerjaan.
// ══════════════════════════════════════════════════════

// Cek session login — kalau sudah login, langsung ke dashboard
session_start();
if (!empty($_SESSION['user_id']) && !empty($_SESSION['tenant_id'])) {
    header('Location: /dashboard', true, 302);
    exit;
}

// Belum login → tampilkan landing page
require __DIR__ . '/landing.php';

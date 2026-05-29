<?php
// ══════════════════════════════════════════════════════
// switch-outlet.php — Pindah outlet aktif untuk tenant ini
// Aturan:
//   - Outlet harus milik tenant aktif (anti-tampering)
//   - Outlet harus dalam status trial/grace/active
//   - Request harus membawa signed token (anti-CSRF)
//   - Setelah switch, redirect kembali ke dashboard
// ══════════════════════════════════════════════════════

define('ROOT', __DIR__);

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure',   1);
ini_set('session.cookie_samesite', 'Strict');
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['tenant_id'])) {
    header('Location: /login?msg=not_logged_in');
    exit;
}

require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';
require_once ROOT . '/core/TenantResolver.php';

$outletId = (int)($_GET['id'] ?? 0);
$tenantId = (int)$_SESSION['tenant_id'];
$token    = $_GET['t'] ?? '';

// ── Validasi signed token (HMAC-SHA256) ───────────────
// Token dibuat di components.php: hash_hmac('sha256', "so:{$userId}:{$outletId}", $secret)
// Melindungi dari CSRF — penyerang tidak tahu session secret
function switchOutletSecret(): string {
    // Kombinasi session ID + user ID sebagai secret — unik per sesi
    return hash('sha256', session_id() . ($_SESSION['user_id'] ?? '') . 'switch_outlet_v1');
}

function switchOutletToken(int $outletId): string {
    return substr(hash_hmac('sha256', 'so:' . ($_SESSION['user_id'] ?? '') . ':' . $outletId, switchOutletSecret()), 0, 16);
}

if ($outletId <= 0) {
    header('Location: /dashboard');
    exit;
}

// Validasi token — tolak jika tidak cocok
if (!hash_equals(switchOutletToken($outletId), $token)) {
    // Token invalid → bisa jadi CSRF atau link lama — redirect aman
    header('Location: /dashboard?switch_error=invalid_token');
    exit;
}

$db = Database::get();
$stmt = $db->prepare(
    "SELECT id, status FROM outlets
     WHERE id = ? AND tenant_id = ?
       AND status IN ('trial','grace','active')
     LIMIT 1"
);
$stmt->execute([$outletId, $tenantId]);
$outlet = $stmt->fetch();

if (!$outlet) {
    // Outlet tidak ditemukan / bukan milik tenant ini / status tidak valid
    header('Location: /dashboard?switch_error=invalid_outlet');
    exit;
}

// Set outlet baru di session, keluar dari HQ mode
$_SESSION['outlet_id']  = (int)$outlet['id'];
$_SESSION['has_outlet'] = true;
$_SESSION['hq_mode']    = false;
TenantResolver::reset();

header('Location: /dashboard?switched=1');
exit;

<?php
// ══════════════════════════════════════════════════════
// resend-verify.php — Kirim ulang link verifikasi email
// Dipanggil dari verify-email.php saat token expired
// POST: tenant_id (dari hidden field)
// GET:  fallback ke pending-verify.php
// ══════════════════════════════════════════════════════

session_start();

require_once __DIR__ . '/master/config/db.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/EmailVerification.php';
require_once __DIR__ . '/core/Mailer.php';

// GET tanpa tenant_id → arahkan ke pending-verify atau login
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (isset($_SESSION['tenant_id'])) {
        header('Location: /pending-verify.php');
    } else {
        header('Location: /login.php');
    }
    exit;
}

$tenantId = (int)($_POST['tenant_id'] ?? $_SESSION['tenant_id'] ?? 0);

if (!$tenantId) {
    header('Location: /login.php?error=no_tenant');
    exit;
}

// Ambil data tenant
$db   = Database::get();
$stmt = $db->prepare("SELECT email, nama_outlet, owner_name, verified_at FROM tenants WHERE id = ? LIMIT 1");
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch();

// Kalau tidak ditemukan atau sudah verified → login
if (!$tenant) {
    header('Location: /login.php?error=tenant_not_found');
    exit;
}
if ($tenant['verified_at'] !== null) {
    header('Location: /login.php?msg=already_verified');
    exit;
}

// Kirim ulang
$result = EmailVerification::resend($tenantId, $tenant['email']);

// Set session agar pending-verify.php bisa jalan
$_SESSION['tenant_id']      = $tenantId;
$_SESSION['pending_verify'] = true;

// Redirect ke pending-verify dengan flash message
if ($result['ok']) {
    $_SESSION['resend_flash'] = 'success:Email verifikasi sudah dikirim ulang ke ' . htmlspecialchars($tenant['email']);
} else {
    $_SESSION['resend_flash'] = 'error:' . htmlspecialchars($result['message']);
}

header('Location: /pending-verify.php');
exit;

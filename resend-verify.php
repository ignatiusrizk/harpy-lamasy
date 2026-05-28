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

// Rate limit: max 3x per 10 menit per session — cegah spam email
$rlKey  = 'resend_verify_rl';
$rlData = $_SESSION[$rlKey] ?? ['n' => 0, 't' => 0];
if ((time() - $rlData['t']) > 600) $rlData = ['n' => 0, 't' => time()];
if ($rlData['n'] >= 3) {
    $_SESSION['resend_flash'] = 'error:Terlalu banyak permintaan. Tunggu 10 menit sebelum kirim ulang.';
    header('Location: /pending-verify.php');
    exit;
}
$rlData['n']++;
if (empty($rlData['t'])) $rlData['t'] = time();
$_SESSION[$rlKey] = $rlData;

// Ambil data tenant
$db   = Database::get();
$stmt = $db->prepare("SELECT email, nama_perusahaan, owner_name, verified_at FROM tenants WHERE id = ? LIMIT 1");
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

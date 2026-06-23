<?php
// ══════════════════════════════════════════════════════
// superadmin/stop_impersonate.php — End Impersonation Session
//
// Called via GET from the observer banner in tenant pages.
// Updates saas_impersonation_log with ended_at.
// Clears session flags & redirects back to client_detail.
// ══════════════════════════════════════════════════════

// Minimal bootstrap — hanya butuh session & db
define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/../master/config/db.php';
require_once SA_ROOT . '/../core/Database.php';

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure',   1);
ini_set('session.cookie_samesite', 'Strict');
if (session_status() === PHP_SESSION_NONE) session_start();

// Harus ada sesi superadmin aktif
if (empty($_SESSION['superadmin_id'])) {
    header('Location: /superadmin/login.php');
    exit;
}

// CSRF: validasi token dari URL (dibuat saat impersonate dimulai)
$expectedToken = $_SESSION['stop_impersonate_token'] ?? '';
$givenToken    = $_GET['t'] ?? '';
if (!$expectedToken || !hash_equals($expectedToken, $givenToken)) {
    http_response_code(403);
    die('Request tidak valid.');
}

$tenantId        = (int)($_SESSION['impersonating_tenant_id']  ?? 0);
$impersonationId = (int)($_SESSION['impersonation_log_id']     ?? 0);

// Tutup log entry
if ($impersonationId) {
    try {
        Database::get()->prepare("
            UPDATE saas_impersonation_log
               SET ended_at        = NOW(),
                   duration_seconds = TIMESTAMPDIFF(SECOND, started_at, NOW())
             WHERE id = ?
        ")->execute([$impersonationId]);
    } catch (Throwable $e) {
        error_log('[StopImpersonate] ' . $e->getMessage());
    }
}

// Audit log
if ($tenantId) {
    try {
        Database::get()->prepare(
            "INSERT INTO superadmin_logs (superadmin_id, action, target_tenant_id, description, ip_address)
             VALUES (?, 'impersonate_stop', ?, 'Selesai observasi tenant', ?)"
        )->execute([
            $_SESSION['superadmin_id'],
            $tenantId,
            $_SERVER['REMOTE_ADDR'] ?? '-',
        ]);
    } catch (Throwable $e) {
        if (class_exists('ErrorLogger')) ErrorLogger::logException('sa_audit', $e);
    }
}

// Bersihkan SEMUA session vars yang di-set saat impersonasi
// (tenant session vars + impersonasi flags)
// $_SESSION['superadmin_id'] TETAP ADA — tidak dihapus
unset(
    // Tenant session vars
    $_SESSION['user_id'],
    $_SESSION['tenant_id'],
    $_SESSION['tenant_slug'],
    $_SESSION['tenant_coin_balance'],
    $_SESSION['outlet_id'],
    $_SESSION['hq_mode'],
    $_SESSION['hl_login_time'],
    $_SESSION['hl_last_activity'],
    $_SESSION['hl_user'],
    $_SESSION['hl_permissions'],
    // Impersonasi flags
    $_SESSION['impersonating_tenant_id'],
    $_SESSION['impersonation_log_id'],
    $_SESSION['impersonation_admin_name'],
    $_SESSION['impersonation_tenant_name'],
    $_SESSION['stop_impersonate_token'],
    // Misc yang mungkin di-set selama observasi
    $_SESSION['last_anomaly_check'],
    $_SESSION['csrf_token'],
);

// Kembali ke detail page tenant
$redirect = $tenantId
    ? '/superadmin/client_detail.php?id=' . $tenantId . '&msg=observer_ended'
    : '/superadmin/clients.php';

header('Location: ' . $redirect);
exit;

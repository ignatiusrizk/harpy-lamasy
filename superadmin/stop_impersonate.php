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

if (session_status() === PHP_SESSION_NONE) session_start();

// Harus ada sesi superadmin aktif
if (empty($_SESSION['superadmin_id'])) {
    header('Location: /superadmin/login.php');
    exit;
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
    } catch (Throwable) {}
}

// Bersihkan session flags
unset(
    $_SESSION['impersonating_tenant_id'],
    $_SESSION['impersonation_log_id'],
    $_SESSION['impersonation_admin_name'],
    $_SESSION['impersonation_tenant_name']
);

// Kembali ke detail page tenant
$redirect = $tenantId
    ? '/superadmin/client_detail.php?id=' . $tenantId . '&msg=observer_ended'
    : '/superadmin/clients.php';

header('Location: ' . $redirect);
exit;

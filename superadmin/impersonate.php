<?php
// ══════════════════════════════════════════════════════
// superadmin/impersonate.php — Start Impersonation Session
//
// POST only. Logs to saas_impersonation_log.
// Sets session flags so tenant pages show observer banner
// and block all POST/write actions.
//
// Usage (from client_detail.php):
//   POST /superadmin/impersonate.php
//     tenant_id = <int>
//     _csrf     = <token>
// ══════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';

// POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /superadmin/clients.php');
    exit;
}

saVerifyCsrf();

$db       = Database::get();
$admin    = saCurrentAdmin();
$tenantId = (int)($_POST['tenant_id'] ?? 0);

if (!$tenantId) {
    header('Location: /superadmin/clients.php?err=no_tenant');
    exit;
}

// Validasi tenant exist + aktif/trial
$tenant = $db->prepare("SELECT id, slug, nama_perusahaan, status FROM tenants WHERE id = ? LIMIT 1");
$tenant->execute([$tenantId]);
$t = $tenant->fetch(PDO::FETCH_ASSOC);

if (!$t) {
    header('Location: /superadmin/clients.php?err=not_found');
    exit;
}

if (!in_array($t['status'], ['active', 'trial', 'suspended'], true)) {
    header('Location: /superadmin/client_detail.php?id=' . $tenantId . '&err=invalid_status');
    exit;
}

// Cegah impersonate berlapis (sudah sedang impersonate)
if (!empty($_SESSION['impersonating_tenant_id'])) {
    header('Location: /superadmin/client_detail.php?id=' . $tenantId . '&err=already_impersonating');
    exit;
}

// Simpan ke saas_impersonation_log
$db->prepare("
    INSERT INTO saas_impersonation_log
      (superadmin_id, tenant_id, started_at, ip_address, user_agent)
    VALUES (?, ?, NOW(), ?, ?)
")->execute([
    $admin['id']                          ?? 0,
    $tenantId,
    $_SERVER['REMOTE_ADDR']               ?? '-',
    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300),
]);
$impersonationId = (int)$db->lastInsertId();

// Audit log
logSuperAdminAction(
    'impersonate_start',
    $tenantId,
    "Mulai observasi tenant: " . ($t['nama_perusahaan'] ?: $t['slug'])
);

// Set session flags
$_SESSION['impersonating_tenant_id']   = $tenantId;
$_SESSION['impersonation_log_id']      = $impersonationId;
$_SESSION['impersonation_admin_name']  = $admin['name'] ?? 'Superadmin';
$_SESSION['impersonation_tenant_name'] = $t['nama_perusahaan'] ?: $t['slug'];

// Redirect ke dashboard tenant
header('Location: /dashboard.php?tenant_id=' . $tenantId . '&observer=1');
exit;

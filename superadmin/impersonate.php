<?php
// ══════════════════════════════════════════════════════
// superadmin/impersonate.php — Start Impersonation Session
//
// POST only. Logs to saas_impersonation_log.
// Sets full tenant session so tenant pages work normally,
// plus impersonating_tenant_id flag for observer banner
// and read-only enforcement in tenant_guard.php.
//
// Usage (from client_detail.php):
//   POST /superadmin/impersonate.php
//     tenant_id = <int>
//     reason    = <string, wajib>
//     _csrf     = <token>
// ══════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/../core/SaPermission.php';
SaPermission::require('clients.impersonate');

// POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /superadmin/clients.php');
    exit;
}

saVerifyCsrf();

$db       = Database::get();
$admin    = saCurrentAdmin();
$tenantId = (int)($_POST['tenant_id'] ?? 0);
$reason   = trim($_POST['reason'] ?? '');

if (!$tenantId) {
    header('Location: /superadmin/clients.php?err=no_tenant');
    exit;
}

if (!$reason) {
    header('Location: /superadmin/client_detail.php?id=' . $tenantId . '&err=reason_required');
    exit;
}

// Potong reason ke max 200 char
$reason = mb_substr($reason, 0, 200);

// Validasi tenant exist + status valid
$tStmt = $db->prepare("SELECT id, slug, nama_perusahaan, status, coin_balance FROM tenants WHERE id = ? LIMIT 1");
$tStmt->execute([$tenantId]);
$t = $tStmt->fetch(PDO::FETCH_ASSOC);

if (!$t) {
    header('Location: /superadmin/clients.php?err=not_found');
    exit;
}

if (!in_array($t['status'], ['active', 'trial', 'suspended', 'grace'], true)) {
    header('Location: /superadmin/client_detail.php?id=' . $tenantId . '&err=invalid_status');
    exit;
}

// Cegah impersonate berlapis (sudah sedang impersonate)
if (!empty($_SESSION['impersonating_tenant_id'])) {
    header('Location: /superadmin/client_detail.php?id=' . $tenantId . '&err=already_impersonating');
    exit;
}

// ── Cari user owner/admin tenant ini ────────────────────
$ownerQ = $db->prepare("
    SELECT id, username, nama, role, role_id, outlet_id
    FROM hl_users
    WHERE tenant_id = ? AND is_active = 1
      AND role IN ('owner','superadmin','admin','manager')
    ORDER BY
        FIELD(role, 'owner', 'superadmin', 'admin', 'manager'),
        id ASC
    LIMIT 1
");
$ownerQ->execute([$tenantId]);
$ownerUser = $ownerQ->fetch(PDO::FETCH_ASSOC);

if (!$ownerUser) {
    header('Location: /superadmin/client_detail.php?id=' . $tenantId . '&err=no_owner_user');
    exit;
}

// ── Cari outlet utama ───────────────────────────────────
$outletQ = $db->prepare("
    SELECT id FROM outlets
    WHERE tenant_id = ? AND status IN ('active','trial','grace')
    ORDER BY is_main DESC, id ASC
    LIMIT 1
");
$outletQ->execute([$tenantId]);
$mainOutletId = (int)($outletQ->fetchColumn() ?: 0);

// ── Simpan ke saas_impersonation_log ───────────────────
$db->prepare("
    INSERT INTO saas_impersonation_log
      (superadmin_id, tenant_id, outlet_id, reason, started_at, ip_address)
    VALUES (?, ?, ?, ?, NOW(), ?)
")->execute([
    $admin['id'] ?? 0,
    $tenantId,
    $mainOutletId ?: null,
    $reason,
    $_SERVER['REMOTE_ADDR'] ?? '-',
]);
$impersonationId = (int)$db->lastInsertId();

// ── Audit log superadmin ────────────────────────────────
logSuperAdminAction(
    'impersonate_start',
    $tenantId,
    "Mulai observasi: " . ($t['nama_perusahaan'] ?: $t['slug']) . " — Alasan: $reason"
);

// ── Simpan superadmin_id (tetap ada di session) ─────────
// $_SESSION['superadmin_id'] dibiarkan — dipakai stop_impersonate.php
// Kita hanya TAMBAHKAN session vars tenant, tidak menghapus superadmin vars.

// ── Set session tenant (mirip login normal sebagai owner) ──
$_SESSION['user_id']            = $ownerUser['id'];
$_SESSION['tenant_id']          = $tenantId;
$_SESSION['tenant_slug']        = $t['slug'];
$_SESSION['tenant_coin_balance']= (int)$t['coin_balance'];
$_SESSION['outlet_id']          = $mainOutletId;
$_SESSION['hq_mode']            = false; // observer selalu outlet view
$_SESSION['hl_login_time']      = time();
$_SESSION['hl_last_activity']   = time();
$_SESSION['hl_user']            = [
    'id'          => $ownerUser['id'],
    'username'    => $ownerUser['username'],
    'nama'        => '[OBSERVER] ' . ($ownerUser['nama'] ?? ''),
    'role'        => $ownerUser['role'],
    'role_id'     => $ownerUser['role_id'] ?? null,
    'role_nama'   => ucfirst($ownerUser['role']),
    'nama_outlet' => $t['nama_perusahaan'] ?: $t['slug'],
];
$_SESSION['hl_permissions']     = ['*' => 'all']; // observer lihat semua, tapi POST diblok

// ── Flag impersonasi ────────────────────────────────────
$_SESSION['impersonating_tenant_id']   = $tenantId;
$_SESSION['impersonation_log_id']      = $impersonationId;
$_SESSION['impersonation_admin_name']  = $admin['name'] ?? 'Superadmin';
$_SESSION['impersonation_tenant_name'] = $t['nama_perusahaan'] ?: $t['slug'];
// Token CSRF untuk stop_impersonate.php (GET → perlu token agar tidak bisa di-trigger sembarangan)
$_SESSION['stop_impersonate_token']    = bin2hex(random_bytes(16));

// Redirect ke dashboard outlet tenant
header('Location: /dashboard');
exit;

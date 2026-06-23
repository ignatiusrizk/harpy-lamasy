<?php
// ══════════════════════════════════════════════════════
// core/SaPermission.php — SA role-based access control helper
// ══════════════════════════════════════════════════════

require_once __DIR__ . '/Database.php';

class SaPermission
{
    /**
     * Load all perm_keys for a super_admin into session cache.
     * Called after login and after role changes.
     */
    public static function loadIntoSession(int $superadminId): void
    {
        try {
            $s = Database::get()->prepare(
                "SELECT p.perm_key
                 FROM super_admins sa
                 JOIN sa_role_permissions rp ON rp.role_id = sa.role_id
                 JOIN sa_permissions p ON p.id = rp.permission_id
                 WHERE sa.id = ? AND sa.is_active = 1"
            );
            $s->execute([$superadminId]);
            $perms = $s->fetchAll(PDO::FETCH_COLUMN);
            $_SESSION['sa_perms'] = array_values(array_unique($perms));
        } catch (Throwable $e) {
            // Table belum ada (fresh install before migration) — fallback ke owner
            $_SESSION['sa_perms'] = null; // null = uninitialized (fallback ke owner)
            error_log('[SaPermission::loadIntoSession] ' . $e->getMessage());
        }
    }

    /**
     * Check if current SA has a permission.
     * Falls back to true if sa_perms is null (uninitialized — backwards compat for Rizky).
     */
    public static function has(string $permKey): bool
    {
        // If session perm cache is null → tables not migrated yet, allow all (owner fallback)
        if (!isset($_SESSION['sa_perms'])) {
            return true;
        }
        return in_array($permKey, (array)$_SESSION['sa_perms'], true);
    }

    /**
     * Die with 403 JSON if current admin does not have $permKey.
     */
    public static function require(string $permKey): void
    {
        if (!self::has($permKey)) {
            $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
                   || !empty($_GET['action']);
            http_response_code(403);
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Akses ditolak. Permission: ' . $permKey]);
            } else {
                echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>403</title></head>'
                    . '<body style="font-family:sans-serif;background:#0F1C3A;color:#fff;padding:60px;text-align:center">'
                    . '<h1 style="color:#6366F1">403 — Akses Ditolak</h1>'
                    . '<p>Kamu tidak punya permission: <code>' . htmlspecialchars($permKey) . '</code></p>'
                    . '<a href="/superadmin/dashboard.php" style="color:#818CF8">← Kembali ke Dashboard</a>'
                    . '</body></html>';
            }
            exit;
        }
    }

    /**
     * Get all perm_keys for the current admin (from session cache).
     * Returns empty array if not loaded.
     */
    public static function getAllPermsForCurrentAdmin(): array
    {
        if (!isset($_SESSION['sa_perms'])) {
            return [];
        }
        return (array)$_SESSION['sa_perms'];
    }

    /**
     * Return super_admins rows that have a specific permission (for notif routing).
     * Joins through role → role_permissions → permissions.
     */
    public static function getAdminsWithPerm(string $permKey): array
    {
        try {
            $s = Database::get()->prepare(
                "SELECT DISTINCT sa.*
                 FROM super_admins sa
                 JOIN sa_role_permissions rp ON rp.role_id = sa.role_id
                 JOIN sa_permissions p ON p.id = rp.permission_id
                 WHERE sa.is_active = 1 AND p.perm_key = ?"
            );
            $s->execute([$permKey]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('[SaPermission::getAdminsWithPerm] ' . $e->getMessage());
            return [];
        }
    }
}

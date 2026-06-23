# SuperAdmin RBAC + Team Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add role-based access control + full team CRUD to the SuperAdmin panel, replacing the single-admin model with 5 roles (Owner, Ops, Finance, Support, Viewer) and upgrading notification routing to match permissions.

**Architecture:** DB-driven RBAC via 4 tables (`sa_roles`, `sa_permissions`, `sa_role_permissions`, plus `role_id` on `super_admins`). A new `SaPermission` helper loads granted perm keys into `$_SESSION['sa_perms']` at login; all guard checks read from session (fast). Settings UI gains a "SA Team" tab with full CRUD. Notif routing updated to filter by `notif_events` column.

**Tech Stack:** PHP 8, PDO/MariaDB, vanilla JS, existing SA design system (`.sa-card`, `.sa-table`, `.sa-btn`, `.sa-modal`), `password_hash()`, `saVerifyCsrf()`.

## Global Constraints

- Working dir: `/Users/rizky/Documents/lamasy/`
- DB client: `/opt/homebrew/opt/mysql-client/bin/mysql` with `~/.my.cnf`
- Branch: `main` (auto-deploys)
- Commit messages: Indonesian, prefix `feat(sa-rbac):` / `fix(sa-rbac):` / `chore(sa-rbac):`
- Password hashing: `password_hash($pw, PASSWORD_DEFAULT)` (matches existing pattern in `superadmin/login.php`)
- CSRF: use `saVerifyCsrf()` from `superadmin/middleware/superadmin_guard.php`
- UI: match existing dark navy SA design system, indigo accent `#6366F1` / `var(--sa)`
- `saas_sa_notif_log` table already exists (from `sa_notify_migration.sql`)
- `super_admins` already has `email`, `notify_enabled` columns (from `sa_notify_migration.sql`)
- Backwards compat: if `$_SESSION['sa_perms']` absent, fall back to owner-level access (Rizky during transition)
- Never hard-delete owner SA records; soft-delete only (`is_active = 0`)

---

## File Map

| File | Action | Responsibility |
|------|--------|---------------|
| `superadmin/sql/sa_rbac_migration.sql` | **Create** | Schema: 4 tables + 5 roles + 29 perms + junction rows + Rizky backfill |
| `core/SaPermission.php` | **Create** | Static RBAC helper: `has()`, `require()`, `loadIntoSession()`, `getAdminsWithPerm()` |
| `core/SaNotifier.php` | **Modify** | `resolveRecipients()` now accepts `$eventType` and filters by `notif_events` column |
| `superadmin/middleware/superadmin_guard.php` | **Modify** | Call `SaPermission::loadIntoSession()` after auth check on every request |
| `superadmin/login.php` | **Modify** | Call `SaPermission::loadIntoSession()` after successful login |
| `superadmin/settings.php` | **Modify** | Add AJAX actions for `team_*` + new "SA Team" tab HTML |
| `superadmin/clients.php` | **Modify** | Guard `toggle_status` action with `clients.suspend` |
| `superadmin/impersonate.php` | **Modify** | Guard entry with `clients.impersonate` |
| `superadmin/billing.php` | **Modify** | Guard `topup` action with `billing.topup` |
| `superadmin/payments.php` | **Modify** | Guard `confirm` / `approve` actions with `payments.approve` |
| `superadmin/coin_pricing.php` | **Modify** | Guard `save` action with `coin_pricing.edit` |
| `superadmin/support.php` | **Modify** | Guard `reply` action with `support.reply`, `close` with `support.close` |

---

## Task 1: DB Migration — Schema + Seed

**Files:**
- Create: `superadmin/sql/sa_rbac_migration.sql`

**Interfaces:**
- Produces: tables `sa_roles`, `sa_permissions`, `sa_role_permissions`; column `super_admins.role_id`; Rizky assigned to `owner` role

- [ ] **Step 1: Create the migration SQL file**

```sql
-- superadmin/sql/sa_rbac_migration.sql
-- ════════════════════════════════════════════════════════════════
-- SA RBAC — Roles, Permissions, Junction, + Seed
-- Date: 2026-06-24
-- ════════════════════════════════════════════════════════════════

-- 1) Roles table
CREATE TABLE IF NOT EXISTS sa_roles (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  slug        VARCHAR(30)  UNIQUE NOT NULL,
  name        VARCHAR(80)  NOT NULL,
  description TEXT,
  is_system   TINYINT(1)   DEFAULT 0,
  created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Permissions table
CREATE TABLE IF NOT EXISTS sa_permissions (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  perm_key     VARCHAR(60)  UNIQUE NOT NULL,
  module       VARCHAR(30)  NOT NULL,
  action       VARCHAR(30)  NOT NULL,
  description  VARCHAR(255),
  notif_events VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Junction
CREATE TABLE IF NOT EXISTS sa_role_permissions (
  role_id       INT NOT NULL,
  permission_id INT NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  FOREIGN KEY (role_id)       REFERENCES sa_roles(id)       ON DELETE CASCADE,
  FOREIGN KEY (permission_id) REFERENCES sa_permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) ALTER super_admins — add role_id column (safe: idempotent via IF NOT EXISTS workaround)
ALTER TABLE super_admins
  ADD COLUMN IF NOT EXISTS role_id INT DEFAULT NULL AFTER notify_enabled,
  ADD INDEX IF NOT EXISTS idx_role (role_id);

-- 5) Seed 5 system roles
INSERT IGNORE INTO sa_roles (slug, name, description, is_system) VALUES
  ('owner',   'Owner / Founder',   'Akses penuh semua fitur SA', 1),
  ('ops',     'Operations',        'Handle pendaftaran tenant, onboarding, impersonate', 1),
  ('finance', 'Finance / Billing', 'Handle topup coin, payments, pricing, packages', 1),
  ('support', 'Helpdesk Support',  'Handle support tickets, churn risk, broadcast', 1),
  ('viewer',  'Viewer (Read-only)','Lihat dashboard + metric tapi tidak bisa action', 1);

-- 6) Seed ~29 permissions
INSERT IGNORE INTO sa_permissions (perm_key, module, action, description, notif_events) VALUES
  -- Registrations / Onboarding
  ('registrations.view',    'registrations','view',    'Lihat list registrasi',            'tenant_registered,email_verified,outlet_trial'),
  ('registrations.approve', 'registrations','approve', 'Approve registrasi tenant',        NULL),
  ('registrations.reject',  'registrations','reject',  'Reject registrasi',                NULL),
  ('onboarding.manage',     'onboarding',   'manage',  'Manual onboarding via wizard',     NULL),
  -- Clients
  ('clients.view',          'clients',      'view',    'Lihat list tenant',                'trial_expiring_1,trial_expiring_3,outlet_suspended'),
  ('clients.suspend',       'clients',      'suspend', 'Suspend/activate tenant',          NULL),
  ('clients.impersonate',   'clients',      'impersonate','Login as tenant (impersonate)', NULL),
  -- Billing / Payments
  ('billing.view',          'billing',      'view',    'Lihat billing + coin metrics',     'outlet_paid'),
  ('billing.topup',         'billing',      'topup',   'Manual topup coin tenant',         NULL),
  ('billing.refund',        'billing',      'refund',  'Refund coin',                      NULL),
  ('payments.view',         'payments',     'view',    'Lihat manual payments',            'outlet_paid'),
  ('payments.approve',      'payments',     'approve', 'Approve manual payment',           NULL),
  ('coin_pricing.view',     'coin_pricing', 'view',    'Lihat coin pricing',               NULL),
  ('coin_pricing.edit',     'coin_pricing', 'edit',    'Edit coin pricing per feature',    NULL),
  ('packages.view',         'packages',     'view',    'Lihat packages',                   NULL),
  ('packages.edit',         'packages',     'edit',    'Edit subscription packages',       NULL),
  -- Support
  ('support.view',          'support',      'view',    'Lihat tickets',                    'support_ticket'),
  ('support.reply',         'support',      'reply',   'Reply ticket',                     NULL),
  ('support.close',         'support',      'close',   'Close ticket',                     NULL),
  ('churn_risk.view',       'churn_risk',   'view',    'Lihat churn risk',                 NULL),
  -- System / Settings
  ('health.view',           'health',       'view',    'Lihat platform health',            NULL),
  ('ai_usage.view',         'ai_usage',     'view',    'Lihat AI usage stats',             NULL),
  ('migrations.run',        'migrations',   'run',     'Run DB migration',                 NULL),
  ('settings.maintenance',  'settings',     'maintenance','Toggle maintenance mode',       NULL),
  ('settings.notify',       'settings',     'notify',  'Konfigurasi notif',                NULL),
  ('super_admins.manage',   'super_admins', 'manage',  'CRUD SA team + role assign',       NULL),
  -- Content
  ('announcements.publish', 'announcements','publish', 'Publish announcement',             NULL),
  ('banners.publish',       'banners',      'publish', 'Publish banner',                   NULL),
  ('broadcast.send',        'broadcast',    'send',    'Send WA broadcast',                NULL);

-- 7) Map role → permissions

-- Owner: ALL perms
INSERT IGNORE INTO sa_role_permissions (role_id, permission_id)
  SELECT (SELECT id FROM sa_roles WHERE slug='owner'), id FROM sa_permissions;

-- Ops: registrations + onboarding + clients
INSERT IGNORE INTO sa_role_permissions (role_id, permission_id)
  SELECT (SELECT id FROM sa_roles WHERE slug='ops'), id FROM sa_permissions
  WHERE perm_key IN (
    'registrations.view','registrations.approve','registrations.reject',
    'onboarding.manage',
    'clients.view','clients.suspend','clients.impersonate'
  );

-- Finance: billing + payments + pricing + packages + clients.view
INSERT IGNORE INTO sa_role_permissions (role_id, permission_id)
  SELECT (SELECT id FROM sa_roles WHERE slug='finance'), id FROM sa_permissions
  WHERE perm_key IN (
    'billing.view','billing.topup','billing.refund',
    'payments.view','payments.approve',
    'coin_pricing.view','coin_pricing.edit',
    'packages.view','packages.edit',
    'clients.view'
  );

-- Support: tickets + churn + broadcast + clients.view
INSERT IGNORE INTO sa_role_permissions (role_id, permission_id)
  SELECT (SELECT id FROM sa_roles WHERE slug='support'), id FROM sa_permissions
  WHERE perm_key IN (
    'support.view','support.reply','support.close',
    'churn_risk.view',
    'clients.view',
    'broadcast.send'
  );

-- Viewer: all .view and .manage-as-view perms (action='view' only)
INSERT IGNORE INTO sa_role_permissions (role_id, permission_id)
  SELECT (SELECT id FROM sa_roles WHERE slug='viewer'), id FROM sa_permissions
  WHERE action = 'view';

-- 8) Backfill Rizky as owner
UPDATE super_admins SET role_id = (SELECT id FROM sa_roles WHERE slug='owner')
WHERE username = 'rizky';

-- Verify
SELECT 'roles'             AS tabel, COUNT(*) AS total FROM sa_roles
UNION ALL SELECT 'permissions',      COUNT(*) FROM sa_permissions
UNION ALL SELECT 'role_permissions', COUNT(*) FROM sa_role_permissions
UNION ALL SELECT 'admins_with_role', COUNT(*) FROM super_admins WHERE role_id IS NOT NULL;
```

Save to `/Users/rizky/Documents/lamasy/superadmin/sql/sa_rbac_migration.sql`.

- [ ] **Step 2: Run the migration**

```bash
/opt/homebrew/opt/mysql-client/bin/mysql < /Users/rizky/Documents/lamasy/superadmin/sql/sa_rbac_migration.sql
```

Expected output — 4 verify rows:
```
tabel               | total
--------------------|------
roles               | 5
permissions         | 29
role_permissions    | (varies, ~70+)
admins_with_role    | 1
```

- [ ] **Step 3: Spot-check counts**

```bash
/opt/homebrew/opt/mysql-client/bin/mysql -e "
SELECT slug, name FROM sa_roles;
SELECT COUNT(*) AS total_perms FROM sa_permissions;
SELECT r.slug, COUNT(rp.permission_id) AS perms FROM sa_roles r
  JOIN sa_role_permissions rp ON rp.role_id=r.id
  GROUP BY r.slug;
SELECT username, role_id FROM super_admins;
"
```

Expected: 5 roles, 29 perms, owner has all 29, viewer has count of .view perms (~12), Rizky has role_id set.

- [ ] **Step 4: Commit**

```bash
cd /Users/rizky/Documents/lamasy
git add superadmin/sql/sa_rbac_migration.sql
git commit -m "chore(sa-rbac): tambah migrasi schema RBAC — sa_roles, sa_permissions, junction, seed 5 role 29 perm"
```

---

## Task 2: SaPermission.php Helper

**Files:**
- Create: `core/SaPermission.php`

**Interfaces:**
- Consumes: `Database::get()` (from `core/Database.php`), `$_SESSION['superadmin_id']`, `$_SESSION['sa_perms']`
- Produces:
  - `SaPermission::has(string $permKey): bool`
  - `SaPermission::require(string $permKey): void` — exits 403 JSON if missing
  - `SaPermission::getAllPermsForCurrentAdmin(): array` — array of perm_key strings
  - `SaPermission::getAdminsWithPerm(string $permKey): array` — array of super_admins rows
  - `SaPermission::loadIntoSession(int $superadminId): void` — populate `$_SESSION['sa_perms']`

- [ ] **Step 1: Create the file**

```php
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
```

- [ ] **Step 2: Commit**

```bash
cd /Users/rizky/Documents/lamasy
git add core/SaPermission.php
git commit -m "feat(sa-rbac): tambah SaPermission helper — has(), require(), loadIntoSession(), getAdminsWithPerm()"
```

---

## Task 3: Update SaNotifier — Permission-aware recipient routing

**Files:**
- Modify: `core/SaNotifier.php`

**Interfaces:**
- Consumes: `Database::get()`, `Mailer::send()`, new SQL join on `sa_permissions.notif_events`
- Key change: `resolveRecipients()` gains `string $eventType` parameter and uses `FIND_IN_SET` to filter

- [ ] **Step 1: Update `resolveRecipients()` signature in `core/SaNotifier.php`**

Find the existing `resolveRecipients()` method (line 69 area) and replace it entirely:

Old:
```php
    private static function resolveRecipients(): array
    {
        $out = [];

        // 1) Constant default
        if (defined('SA_NOTIFY_EMAILS') && is_array(SA_NOTIFY_EMAILS)) {
            foreach (SA_NOTIFY_EMAILS as $e) {
                if (filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = $e;
            }
        }

        // 2) super_admins yang opt-in
        try {
            $rows = Database::get()->query(
                "SELECT email FROM super_admins WHERE notify_enabled = 1 AND email IS NOT NULL AND is_active = 1"
            )->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $e) {
                if (filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = $e;
            }
        } catch (Throwable) { /* table mungkin belum migrate */ }

        return array_values(array_unique($out));
    }
```

New:
```php
    /**
     * Resolve recipient list for a given event type.
     * Recipients = constant SA_NOTIFY_EMAILS + SA accounts whose role
     * has a permission with notif_events containing $eventType.
     */
    private static function resolveRecipients(string $eventType): array
    {
        $out = [];

        // 1) Constant default (always included)
        if (defined('SA_NOTIFY_EMAILS') && is_array(SA_NOTIFY_EMAILS)) {
            foreach (SA_NOTIFY_EMAILS as $e) {
                if (filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = $e;
            }
        }

        // 2) SA accounts whose role permission covers this event_type
        try {
            $s = Database::get()->prepare(
                "SELECT DISTINCT sa.email FROM super_admins sa
                 JOIN sa_role_permissions rp ON rp.role_id = sa.role_id
                 JOIN sa_permissions p ON p.id = rp.permission_id
                 WHERE sa.notify_enabled = 1 AND sa.is_active = 1 AND sa.email IS NOT NULL
                   AND FIND_IN_SET(?, p.notif_events) > 0"
            );
            $s->execute([$eventType]);
            $rows = $s->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $e) {
                if (filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = $e;
            }
        } catch (Throwable $ex) {
            // Tables not yet migrated — fall back to opt-in list
            try {
                $rows = Database::get()->query(
                    "SELECT email FROM super_admins WHERE notify_enabled=1 AND email IS NOT NULL AND is_active=1"
                )->fetchAll(PDO::FETCH_COLUMN);
                foreach ($rows as $e) {
                    if (filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = $e;
                }
            } catch (Throwable) {}
        }

        return array_values(array_unique($out));
    }
```

- [ ] **Step 2: Update `notify()` call inside `self::notify()` to pass `$eventType` to `resolveRecipients()`**

Find this line (around line 46):
```php
            $recipients = self::resolveRecipients();
```

Replace with:
```php
            $recipients = self::resolveRecipients($eventType);
```

- [ ] **Step 3: Commit**

```bash
cd /Users/rizky/Documents/lamasy
git add core/SaNotifier.php
git commit -m "feat(sa-rbac): SaNotifier routing notif sekarang filter per notif_events di sa_permissions"
```

---

## Task 4: Load Perms into Session (Guard + Login)

**Files:**
- Modify: `superadmin/middleware/superadmin_guard.php`
- Modify: `superadmin/login.php`

**Interfaces:**
- Consumes: `SaPermission::loadIntoSession(int $id)` from Task 2
- Produces: `$_SESSION['sa_perms']` populated on every authenticated request

- [ ] **Step 1: Update `superadmin_guard.php` to load perms on each request**

After the existing `require_once` block at the top of the file (after line 12), add require for SaPermission:

Find this block:
```php
if (!defined('SA_ROOT')) define('SA_ROOT', dirname(__DIR__));
require_once SA_ROOT . '/../master/config/db.php';
require_once SA_ROOT . '/../core/Database.php';
require_once SA_ROOT . '/../core/ErrorLogger.php';
require_once SA_ROOT . '/../core/WaLogger.php';
require_once SA_ROOT . '/../core/PlatformHealthRecorder.php';
```

Replace with:
```php
if (!defined('SA_ROOT')) define('SA_ROOT', dirname(__DIR__));
require_once SA_ROOT . '/../master/config/db.php';
require_once SA_ROOT . '/../core/Database.php';
require_once SA_ROOT . '/../core/ErrorLogger.php';
require_once SA_ROOT . '/../core/WaLogger.php';
require_once SA_ROOT . '/../core/PlatformHealthRecorder.php';
require_once SA_ROOT . '/../core/SaPermission.php';
```

Then find the auth check block that sets session (around line 70):

```php
if (!isset($_SESSION['superadmin_id'])) {
```

After this block (after the entire if/else that does the redirect), add the session perm loader. Find this line:

```php
function saCurrentAdmin(): array {
```

Insert before this function:
```php
// ── Load perm cache into session (once per request) ──
if (isset($_SESSION['superadmin_id']) && !array_key_exists('sa_perms', $_SESSION)) {
    SaPermission::loadIntoSession((int)$_SESSION['superadmin_id']);
}
```

- [ ] **Step 2: Update `superadmin/login.php` to load perms after successful login**

Find the block in `login.php` that sets the session on successful login (around line 98-103):

```php
                // Set session
                $_SESSION['superadmin_id'] = $admin['id'];
                $_SESSION['sa_user'] = [
                    'id'       => $admin['id'],
                    'username' => $admin['username'],
                    'name'     => $admin['name'],
                ];
```

Replace with:
```php
                // Set session
                $_SESSION['superadmin_id'] = $admin['id'];
                $_SESSION['sa_user'] = [
                    'id'       => $admin['id'],
                    'username' => $admin['username'],
                    'name'     => $admin['name'],
                ];

                // Load RBAC perms into session
                require_once SA_ROOT . '/../core/SaPermission.php';
                SaPermission::loadIntoSession((int)$admin['id']);
```

- [ ] **Step 3: Commit**

```bash
cd /Users/rizky/Documents/lamasy
git add superadmin/middleware/superadmin_guard.php superadmin/login.php
git commit -m "feat(sa-rbac): load sa_perms ke session saat login dan guard — SaPermission::loadIntoSession"
```

---

## Task 5: Permission Guards on Sensitive SA Actions

**Files:**
- Modify: `superadmin/clients.php`
- Modify: `superadmin/impersonate.php`
- Modify: `superadmin/billing.php`
- Modify: `superadmin/payments.php`
- Modify: `superadmin/coin_pricing.php`
- Modify: `superadmin/support.php`

**Interfaces:**
- Consumes: `SaPermission::require(string $permKey)` from Task 2
- Each file must `require_once` `core/SaPermission.php`

### clients.php — guard `toggle_status`

- [ ] **Step 1: Add require + guard in `superadmin/clients.php`**

After the existing `require_once` lines at the top (around line 8-9), add:
```php
require_once SA_ROOT . '/../core/SaPermission.php';
```

Then find the `toggle_status` action (search for `$action === 'toggle_status'` or similar). Add guard at the start of that block:
```php
    if ($action === 'toggle_status') {
        SaPermission::require('clients.suspend');
        // ... rest of existing code
```

### impersonate.php — guard entire entry

- [ ] **Step 2: Add require + guard in `superadmin/impersonate.php`**

After:
```php
require_once SA_ROOT . '/middleware/superadmin_guard.php';
```

Add:
```php
require_once SA_ROOT . '/../core/SaPermission.php';
SaPermission::require('clients.impersonate');
```

### billing.php — guard `topup` action

- [ ] **Step 3: Add require + guard in `superadmin/billing.php`**

After the existing `require_once` block at the top, add:
```php
require_once SA_ROOT . '/../core/SaPermission.php';
```

Find the `topup` action block (search `$action === 'topup'`). Add at start:
```php
    if ($action === 'topup') {
        SaPermission::require('billing.topup');
        // ... rest of existing code
```

### payments.php — guard approve/confirm actions

- [ ] **Step 4: Add require + guard in `superadmin/payments.php`**

After existing requires, add:
```php
require_once SA_ROOT . '/../core/SaPermission.php';
```

Find the action that confirms/approves a payment (search `$action === 'confirm'` or `'approve'`). Add at start of that block:
```php
        SaPermission::require('payments.approve');
```

### coin_pricing.php — guard `save` action

- [ ] **Step 5: Add require + guard in `superadmin/coin_pricing.php`**

After existing requires, add:
```php
require_once SA_ROOT . '/../core/SaPermission.php';
```

Find the `save` action (search `$action === 'save'`). Add at start:
```php
    if ($action === 'save') {
        SaPermission::require('coin_pricing.edit');
        // ... rest
```

### support.php — guard reply and close

- [ ] **Step 6: Add require + guard in `superadmin/support.php`**

After existing requires, add:
```php
require_once SA_ROOT . '/../core/SaPermission.php';
```

Find the `reply` action block and add:
```php
    if ($action === 'reply') {
        SaPermission::require('support.reply');
        // ... rest
```

Find the `close` action block and add:
```php
    if ($action === 'close') {
        SaPermission::require('support.close');
        // ... rest
```

- [ ] **Step 7: Commit**

```bash
cd /Users/rizky/Documents/lamasy
git add superadmin/clients.php superadmin/impersonate.php superadmin/billing.php \
        superadmin/payments.php superadmin/coin_pricing.php superadmin/support.php
git commit -m "feat(sa-rbac): tambah permission guard di 6 halaman SA — suspend, impersonate, topup, approve, coin edit, support reply/close"
```

---

## Task 6: SA Team Tab — AJAX Actions in settings.php

**Files:**
- Modify: `superadmin/settings.php`

**Interfaces:**
- Consumes: `SaPermission::require('super_admins.manage')`, `Database::get()`, `saVerifyCsrf()`, `logSuperAdminAction()`
- Produces: 5 new AJAX action handlers (`team_list`, `team_create`, `team_update`, `team_reset_password`, `team_delete`)

- [ ] **Step 1: Add `require_once` for SaPermission at top of settings.php**

After:
```php
require_once SA_ROOT . '/superadmin_components.php';
```

Add:
```php
require_once SA_ROOT . '/../core/SaPermission.php';
```

- [ ] **Step 2: Add team AJAX handlers inside the `if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']))` block**

Find `http_response_code(400);` near the bottom of the AJAX block (before the `exit` after actions). Insert all 5 handlers BEFORE this line:

```php
    // ── SA Team: list ────────────────────────────────
    if ($action === 'team_list') {
        SaPermission::require('super_admins.manage');
        $admins = $db->query(
            "SELECT sa.id, sa.username, sa.name, sa.email, sa.notify_enabled,
                    sa.is_active, sa.last_login, sa.created_at,
                    r.slug AS role_slug, r.name AS role_name
             FROM super_admins sa
             LEFT JOIN sa_roles r ON r.id = sa.role_id
             ORDER BY sa.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        $roles = $db->query("SELECT id, slug, name FROM sa_roles ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'admins' => $admins, 'roles' => $roles]);
        exit;
    }

    // ── SA Team: create ──────────────────────────────
    if ($action === 'team_create') {
        SaPermission::require('super_admins.manage');
        saVerifyCsrf();
        $username = trim($_POST['username'] ?? '');
        $name     = trim($_POST['name']     ?? '');
        $email    = trim($_POST['email']    ?? '');
        $password = $_POST['password']       ?? '';
        $roleId   = (int)($_POST['role_id'] ?? 0);

        if (!$username || !$name || !$password || !$roleId) {
            echo json_encode(['error' => 'Username, nama, password, dan role wajib diisi']); exit;
        }
        if (!preg_match('/^[a-z0-9_]{3,30}$/', $username)) {
            echo json_encode(['error' => 'Username hanya boleh huruf kecil, angka, underscore (3-30 karakter)']); exit;
        }
        if (strlen($password) < 8) {
            echo json_encode(['error' => 'Password minimal 8 karakter']); exit;
        }
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['error' => 'Email tidak valid']); exit;
        }

        // Check username unique
        $exists = $db->prepare("SELECT id FROM super_admins WHERE username=? LIMIT 1");
        $exists->execute([$username]);
        if ($exists->fetchColumn()) {
            echo json_encode(['error' => 'Username sudah digunakan']); exit;
        }

        // Check role exists
        $roleCheck = $db->prepare("SELECT id FROM sa_roles WHERE id=?");
        $roleCheck->execute([$roleId]);
        if (!$roleCheck->fetchColumn()) {
            echo json_encode(['error' => 'Role tidak valid']); exit;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $db->prepare(
            "INSERT INTO super_admins (username, name, email, password, role_id, notify_enabled, is_active)
             VALUES (?,?,?,?,?,1,1)"
        )->execute([$username, $name, $email ?: null, $hash, $roleId]);
        $newId = (int)$db->lastInsertId();

        logSuperAdminAction('sa_team_create', null, "Buat SA baru: @$username ($name), role_id=$roleId");
        echo json_encode(['ok' => true, 'id' => $newId]);
        exit;
    }

    // ── SA Team: update ──────────────────────────────
    if ($action === 'team_update') {
        SaPermission::require('super_admins.manage');
        saVerifyCsrf();
        $id       = (int)($_POST['id']      ?? 0);
        $name     = trim($_POST['name']     ?? '');
        $email    = trim($_POST['email']    ?? '');
        $roleId   = (int)($_POST['role_id'] ?? 0);
        $isActive = (int)($_POST['is_active'] ?? 1);

        if (!$id || !$name || !$roleId) {
            echo json_encode(['error' => 'ID, nama, dan role wajib diisi']); exit;
        }
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['error' => 'Email tidak valid']); exit;
        }

        // Check target role — cannot demote an owner unless requester is also owner
        $targetRow = $db->prepare("SELECT sa.role_id, r.slug FROM super_admins sa LEFT JOIN sa_roles r ON r.id=sa.role_id WHERE sa.id=?");
        $targetRow->execute([$id]);
        $target = $targetRow->fetch(PDO::FETCH_ASSOC);
        if (!$target) { echo json_encode(['error' => 'Admin tidak ditemukan']); exit; }

        // Non-owner cannot edit owner admin
        if ($target['slug'] === 'owner' && !SaPermission::has('super_admins.manage')) {
            echo json_encode(['error' => 'Hanya Owner yang bisa edit akun Owner lain']); exit;
        }

        $db->prepare(
            "UPDATE super_admins SET name=?, email=?, role_id=?, is_active=? WHERE id=?"
        )->execute([$name, $email ?: null, $roleId, $isActive, $id]);

        logSuperAdminAction('sa_team_update', null, "Update SA #$id: name=$name role_id=$roleId is_active=$isActive");
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── SA Team: reset password ──────────────────────
    if ($action === 'team_reset_password') {
        SaPermission::require('super_admins.manage');
        saVerifyCsrf();
        $id       = (int)($_POST['id']       ?? 0);
        $password = $_POST['new_password']    ?? '';
        if (!$id)             { echo json_encode(['error' => 'ID tidak valid']); exit; }
        if (strlen($password) < 8) { echo json_encode(['error' => 'Password minimal 8 karakter']); exit; }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $db->prepare("UPDATE super_admins SET password=? WHERE id=?")->execute([$hash, $id]);
        logSuperAdminAction('sa_team_reset_pw', null, "Reset password SA #$id");
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── SA Team: delete (soft) ───────────────────────
    if ($action === 'team_delete') {
        SaPermission::require('super_admins.manage');
        saVerifyCsrf();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'ID tidak valid']); exit; }

        // Cannot soft-delete owner role
        $roleCheck = $db->prepare(
            "SELECT r.slug FROM super_admins sa LEFT JOIN sa_roles r ON r.id=sa.role_id WHERE sa.id=?"
        );
        $roleCheck->execute([$id]);
        $roleSlug = $roleCheck->fetchColumn();
        if ($roleSlug === 'owner') {
            echo json_encode(['error' => 'Akun Owner tidak bisa dihapus']); exit;
        }

        $db->prepare("UPDATE super_admins SET is_active=0 WHERE id=?")->execute([$id]);
        logSuperAdminAction('sa_team_delete', null, "Soft-delete SA #$id");
        echo json_encode(['ok' => true]);
        exit;
    }
```

- [ ] **Step 3: Commit backend only (UI in next task)**

```bash
cd /Users/rizky/Documents/lamasy
git add superadmin/settings.php
git commit -m "feat(sa-rbac): tambah 5 AJAX action team_* di settings.php — CRUD SA team + perm guard"
```

---

## Task 7: SA Team Tab — UI (settings.php HTML + JS)

**Files:**
- Modify: `superadmin/settings.php` (add tab button + panel HTML + JS)

**Interfaces:**
- Consumes: `team_list`, `team_create`, `team_update`, `team_reset_password`, `team_delete` AJAX actions from Task 6
- Produces: new "SA Team" tab visible in settings UI

- [ ] **Step 1: Add tab button**

Find:
```php
  <button class="set-tab" onclick="switchTab('notify',this);loadNotify()">🔔 Notifications</button>
```

Replace with:
```php
  <button class="set-tab" onclick="switchTab('notify',this);loadNotify()">🔔 Notifications</button>
  <button class="set-tab" onclick="switchTab('team',this);loadTeam()">👥 SA Team</button>
```

- [ ] **Step 2: Add tab panel HTML before closing `</body>` area**

Find the `<!-- ════... MODAL: Edit Tip ═══...` comment. Insert the new panel BEFORE it:

```html
<!-- ══════════════════════════ SA TEAM TAB ═══════════════════════════ -->
<div class="set-panel" id="tab-team">

  <div class="set-card">
    <div class="set-card-head" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
      <div>
        <h3 style="margin:0;font-size:15px;font-weight:700">👥 Super Admin Team</h3>
        <p style="margin:6px 0 0;font-size:13px;color:rgba(255,255,255,.5)">Kelola akun SA, role, dan akses. Hanya Owner yang bisa akses tab ini.</p>
      </div>
      <button class="sa-btn sa-btn-primary" onclick="openTeamCreate()">➕ Tambah Admin</button>
    </div>

    <div class="sa-table-wrap">
      <table class="sa-table" id="teamTable">
        <thead>
          <tr>
            <th>Admin</th>
            <th>Username</th>
            <th>Email</th>
            <th>Role</th>
            <th>Notify</th>
            <th>Status</th>
            <th>Last Login</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody id="teamTableBody">
          <tr><td colspan="8" style="text-align:center;padding:24px;color:rgba(255,255,255,.4)">Memuat...</td></tr>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- ══════════ MODAL: Create SA ══════════ -->
<div id="teamCreateModal" class="sa-modal-overlay">
  <div class="sa-modal" style="max-width:520px">
    <h3>➕ Tambah Super Admin</h3>
    <div class="form-group">
      <label>Username *</label>
      <input type="text" id="tc_username" placeholder="a-z, 0-9, underscore, 3-30 char"
             style="width:100%;padding:10px 14px;background:rgba(255,255,255,.06);border:1.5px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-size:14px;outline:none">
    </div>
    <div class="form-group">
      <label>Nama Lengkap *</label>
      <input type="text" id="tc_name" placeholder="Nama tampil di panel"
             style="width:100%;padding:10px 14px;background:rgba(255,255,255,.06);border:1.5px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-size:14px;outline:none">
    </div>
    <div class="form-group">
      <label>Email (untuk notif)</label>
      <input type="email" id="tc_email" placeholder="email@harpy.id"
             style="width:100%;padding:10px 14px;background:rgba(255,255,255,.06);border:1.5px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-size:14px;outline:none">
    </div>
    <div class="form-group">
      <label>Password * (min 8 karakter)</label>
      <input type="password" id="tc_password" placeholder="••••••••"
             style="width:100%;padding:10px 14px;background:rgba(255,255,255,.06);border:1.5px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-size:14px;outline:none">
    </div>
    <div class="form-group">
      <label>Role *</label>
      <select id="tc_role_id" style="width:100%;padding:10px 14px;background:rgba(15,28,58,.9);border:1.5px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-size:14px;outline:none">
        <option value="">Pilih role...</option>
      </select>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px">
      <button class="sa-btn sa-btn-outline" onclick="closeTeamModals()">Batal</button>
      <button class="sa-btn sa-btn-primary" onclick="submitTeamCreate()">Simpan</button>
    </div>
  </div>
</div>

<!-- ══════════ MODAL: Edit SA ══════════ -->
<div id="teamEditModal" class="sa-modal-overlay">
  <div class="sa-modal" style="max-width:520px">
    <h3>✏️ Edit Super Admin</h3>
    <input type="hidden" id="te_id"/>
    <div class="form-group">
      <label>Nama Lengkap *</label>
      <input type="text" id="te_name"
             style="width:100%;padding:10px 14px;background:rgba(255,255,255,.06);border:1.5px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-size:14px;outline:none">
    </div>
    <div class="form-group">
      <label>Email</label>
      <input type="email" id="te_email"
             style="width:100%;padding:10px 14px;background:rgba(255,255,255,.06);border:1.5px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-size:14px;outline:none">
    </div>
    <div class="form-group">
      <label>Role *</label>
      <select id="te_role_id" style="width:100%;padding:10px 14px;background:rgba(15,28,58,.9);border:1.5px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-size:14px;outline:none">
      </select>
    </div>
    <div class="form-group">
      <label>Status</label>
      <select id="te_is_active" style="width:100%;padding:10px 14px;background:rgba(15,28,58,.9);border:1.5px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-size:14px;outline:none">
        <option value="1">Aktif</option>
        <option value="0">Nonaktif</option>
      </select>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px">
      <button class="sa-btn sa-btn-outline" onclick="closeTeamModals()">Batal</button>
      <button class="sa-btn sa-btn-primary" onclick="submitTeamEdit()">Simpan</button>
    </div>
  </div>
</div>

<!-- ══════════ MODAL: Reset Password ══════════ -->
<div id="teamPwModal" class="sa-modal-overlay">
  <div class="sa-modal" style="max-width:420px">
    <h3>🔑 Reset Password</h3>
    <input type="hidden" id="tp_id"/>
    <div class="form-group">
      <label>Password Baru * (min 8 karakter)</label>
      <input type="password" id="tp_password" placeholder="Password baru"
             style="width:100%;padding:10px 14px;background:rgba(255,255,255,.06);border:1.5px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-size:14px;outline:none">
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px">
      <button class="sa-btn sa-btn-outline" onclick="closeTeamModals()">Batal</button>
      <button class="sa-btn sa-btn-danger" onclick="submitTeamPw()">Reset Password</button>
    </div>
  </div>
</div>
```

- [ ] **Step 3: Add SA Team JS before closing `</script>` tag**

Find the `// Init` section at the bottom of the `<script>` block:

```javascript
// Init
loadMaintStatus();
```

Add the team management JS BEFORE this:

```javascript
// ════════════════════════════════════════════════════════════════
// SA TEAM TAB
// ════════════════════════════════════════════════════════════════
let teamRoles = [];

async function loadTeam() {
  const r = await saFetch('?action=team_list');
  if (!r || !r.ok) {
    if (r && r.error) showToast(r.error, false);
    return;
  }
  teamRoles = r.roles || [];

  // Populate role selects
  const opts = teamRoles.map(role =>
    `<option value="${role.id}">${escapeHtml(role.name)}</option>`
  ).join('');
  document.getElementById('tc_role_id').innerHTML = '<option value="">Pilih role...</option>' + opts;
  document.getElementById('te_role_id').innerHTML = opts;

  const tbody = document.getElementById('teamTableBody');
  if (!r.admins || !r.admins.length) {
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:24px;color:rgba(255,255,255,.4)">Belum ada admin.</td></tr>';
    return;
  }

  tbody.innerHTML = r.admins.map(a => {
    const roleBadge = a.role_slug === 'owner'   ? 'sa-badge-indigo' :
                      a.role_slug === 'finance'  ? 'sa-badge-green' :
                      a.role_slug === 'support'  ? 'sa-badge-blue' :
                      a.role_slug === 'viewer'   ? 'sa-badge-yellow' : 'sa-badge-indigo';
    const statusBadge = a.is_active == 1
      ? '<span class="sa-badge sa-badge-active">Aktif</span>'
      : '<span class="sa-badge sa-badge-suspended">Nonaktif</span>';
    const ll = a.last_login
      ? new Date(a.last_login).toLocaleDateString('id-ID', {day:'2-digit',month:'short',year:'numeric'})
      : '-';
    const rowData = JSON.stringify(a).replace(/'/g,"&apos;");
    return `<tr>
      <td><strong style="color:#fff">${escapeHtml(a.name)}</strong></td>
      <td><code style="color:#A5B4FC;font-size:12px">@${escapeHtml(a.username)}</code></td>
      <td style="font-size:12px;color:rgba(255,255,255,.6)">${escapeHtml(a.email||'—')}</td>
      <td><span class="sa-badge ${roleBadge}">${escapeHtml(a.role_name||'—')}</span></td>
      <td style="font-size:12px">${a.notify_enabled==1?'✓':'—'}</td>
      <td>${statusBadge}</td>
      <td style="font-size:12px;color:rgba(255,255,255,.5)">${escapeHtml(ll)}</td>
      <td>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <button class="sa-btn sa-btn-outline sa-btn-sm" onclick='openTeamEdit(${rowData})'>✏️ Edit</button>
          <button class="sa-btn sa-btn-outline sa-btn-sm" onclick="openTeamPw(${a.id})">🔑</button>
          ${a.role_slug !== 'owner' ? `<button class="sa-btn sa-btn-danger sa-btn-sm" onclick="deleteTeamAdmin(${a.id},'${escapeHtml(a.name)}')">🗑️</button>` : ''}
        </div>
      </td>
    </tr>`;
  }).join('');
}

function openTeamCreate() {
  document.getElementById('tc_username').value = '';
  document.getElementById('tc_name').value     = '';
  document.getElementById('tc_email').value    = '';
  document.getElementById('tc_password').value = '';
  document.getElementById('tc_role_id').value  = '';
  document.getElementById('teamCreateModal').classList.add('open');
}

function openTeamEdit(a) {
  document.getElementById('te_id').value      = a.id;
  document.getElementById('te_name').value    = a.name || '';
  document.getElementById('te_email').value   = a.email || '';
  document.getElementById('te_role_id').value = a.role_id || '';
  document.getElementById('te_is_active').value = a.is_active ?? 1;
  document.getElementById('teamEditModal').classList.add('open');
}

function openTeamPw(id) {
  document.getElementById('tp_id').value = id;
  document.getElementById('tp_password').value = '';
  document.getElementById('teamPwModal').classList.add('open');
}

function closeTeamModals() {
  ['teamCreateModal','teamEditModal','teamPwModal'].forEach(id => {
    document.getElementById(id).classList.remove('open');
  });
}

async function submitTeamCreate() {
  const fd = new FormData();
  fd.append('_csrf', CSRF);
  fd.append('username', document.getElementById('tc_username').value.trim());
  fd.append('name',     document.getElementById('tc_name').value.trim());
  fd.append('email',    document.getElementById('tc_email').value.trim());
  fd.append('password', document.getElementById('tc_password').value);
  fd.append('role_id',  document.getElementById('tc_role_id').value);

  const r = await saFetch('?action=team_create', { method:'POST', body:fd });
  if (r && r.ok) {
    showToast('Admin berhasil ditambahkan');
    closeTeamModals();
    loadTeam();
  } else {
    showToast(r?.error || 'Gagal menambahkan admin', false);
  }
}

async function submitTeamEdit() {
  const fd = new FormData();
  fd.append('_csrf',      CSRF);
  fd.append('id',         document.getElementById('te_id').value);
  fd.append('name',       document.getElementById('te_name').value.trim());
  fd.append('email',      document.getElementById('te_email').value.trim());
  fd.append('role_id',    document.getElementById('te_role_id').value);
  fd.append('is_active',  document.getElementById('te_is_active').value);

  const r = await saFetch('?action=team_update', { method:'POST', body:fd });
  if (r && r.ok) {
    showToast('Admin berhasil diupdate');
    closeTeamModals();
    loadTeam();
  } else {
    showToast(r?.error || 'Gagal update admin', false);
  }
}

async function submitTeamPw() {
  const fd = new FormData();
  fd.append('_csrf',        CSRF);
  fd.append('id',           document.getElementById('tp_id').value);
  fd.append('new_password', document.getElementById('tp_password').value);

  const r = await saFetch('?action=team_reset_password', { method:'POST', body:fd });
  if (r && r.ok) {
    showToast('Password berhasil di-reset');
    closeTeamModals();
  } else {
    showToast(r?.error || 'Gagal reset password', false);
  }
}

async function deleteTeamAdmin(id, name) {
  if (!confirm(`Nonaktifkan akun "${name}"?\nAkun akan di-set is_active=0 (soft delete).`)) return;
  const fd = new FormData();
  fd.append('_csrf', CSRF);
  fd.append('id', id);
  const r = await saFetch('?action=team_delete', { method:'POST', body:fd });
  if (r && r.ok) {
    showToast('Admin dinonaktifkan');
    loadTeam();
  } else {
    showToast(r?.error || 'Gagal menghapus admin', false);
  }
}

// Close modals on overlay click
['teamCreateModal','teamEditModal','teamPwModal'].forEach(id => {
  document.getElementById(id)?.addEventListener('click', function(e) {
    if (e.target === this) closeTeamModals();
  });
});
```

Note: `saFetch` and `saToast` are used in the existing JS. Check if `saFetch` is defined in `superadmin_components.php`. If not, it may need to be added or replaced with the existing `api()` fetch wrapper pattern. Look for `saFetch` in existing settings.php JS — it's used in the Notifications tab (`saveNotify`, `testNotify`). If not defined globally, add this helper near the `esc()` function:

```javascript
async function saFetch(url, opts = {}) {
  try {
    const defaultOpts = {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF, ...opts.headers }
    };
    const mergedOpts = { ...defaultOpts, ...opts, headers: { ...defaultOpts.headers, ...(opts.headers||{}) } };
    const r = await fetch('/superadmin/settings.php' + url, mergedOpts);
    return r.json();
  } catch(e) { return { error: e.message }; }
}
function saToast(msg, type = 'ok') {
  showToast(msg, type === 'ok');
}
```

- [ ] **Step 4: Commit UI**

```bash
cd /Users/rizky/Documents/lamasy
git add superadmin/settings.php
git commit -m "feat(sa-rbac): tambah tab SA Team di settings.php — tabel admin, modal create/edit/password/delete"
```

---

## Task 8: Verification

- [ ] **Step 1: Verify DB state**

```bash
/opt/homebrew/opt/mysql-client/bin/mysql -e "SELECT slug, name FROM sa_roles;"
```
Expected: 5 rows (owner, ops, finance, support, viewer).

```bash
/opt/homebrew/opt/mysql-client/bin/mysql -e "SELECT perm_key, notif_events FROM sa_permissions WHERE notif_events IS NOT NULL;"
```
Expected: ~6 rows (registrations.view, clients.view, billing.view, payments.view, support.view).

```bash
/opt/homebrew/opt/mysql-client/bin/mysql -e "SELECT username, r.slug FROM super_admins sa LEFT JOIN sa_roles r ON r.id=sa.role_id;"
```
Expected: Rizky has slug=owner.

- [ ] **Step 2: Smoke-test session loading**

```bash
# Confirm PHP parses SaPermission.php without errors
php -l /Users/rizky/Documents/lamasy/core/SaPermission.php
# Confirm superadmin_guard.php parses
php -l /Users/rizky/Documents/lamasy/superadmin/middleware/superadmin_guard.php
# Confirm settings.php parses
php -l /Users/rizky/Documents/lamasy/superadmin/settings.php
```
Each should output: `No syntax errors detected`.

- [ ] **Step 3: Smoke-test modified files**

```bash
for f in \
  /Users/rizky/Documents/lamasy/core/SaNotifier.php \
  /Users/rizky/Documents/lamasy/superadmin/login.php \
  /Users/rizky/Documents/lamasy/superadmin/clients.php \
  /Users/rizky/Documents/lamasy/superadmin/impersonate.php \
  /Users/rizky/Documents/lamasy/superadmin/billing.php \
  /Users/rizky/Documents/lamasy/superadmin/payments.php \
  /Users/rizky/Documents/lamasy/superadmin/coin_pricing.php \
  /Users/rizky/Documents/lamasy/superadmin/support.php; do
  php -l "$f" || echo "SYNTAX ERROR: $f"
done
```
All should report no syntax errors.

- [ ] **Step 4: Write report**

Write final status report to `/Users/rizky/Documents/lamasy/.superpowers/sdd/sa-rbac-report.md` with:
- Status line (DONE / DONE_WITH_CONCERNS / BLOCKED)
- Commit count + short SHAs + subjects
- Migration row counts (verified output)
- Files modified/created list
- What works (3-5 bullets)
- Known limits / concerns
- Plan path

---

## Self-Review Checklist

**Spec coverage:**

| Spec Requirement | Task |
|---|---|
| Schema migration (4 tables, 5 roles, 29 perms, junction, Rizky backfill) | Task 1 |
| Run migration via mysql client | Task 1 Step 2 |
| `core/SaPermission.php` — has(), require(), getAllPerms(), getAdminsWithPerm(), loadIntoSession() | Task 2 |
| Update `SaNotifier.resolveRecipients()` to filter by notif_events | Task 3 |
| Update `superadmin_guard.php` to load perms on request | Task 4 |
| Update `login.php` to load perms on login | Task 4 |
| Settings UI new tab "SA Team" | Task 7 |
| AJAX: team_list, team_create, team_update, team_reset_password, team_delete | Task 6 |
| Permission guard: clients.suspend on toggle_status | Task 5 |
| Permission guard: clients.impersonate on impersonate.php | Task 5 |
| Permission guard: billing.topup on topup action | Task 5 |
| Permission guard: payments.approve on confirm action | Task 5 |
| Permission guard: coin_pricing.edit on save action | Task 5 |
| Permission guard: support.reply / support.close | Task 5 |
| Permission guard: super_admins.manage on team_* actions | Task 6 |
| Multiple commits per logical chunk | Tasks 1-7 each have commit step |
| Backwards compat (null sa_perms = owner fallback) | Task 2 `has()` method |
| Non-owner cannot edit owner SA | Task 6 team_update handler |
| Soft delete only (is_active=0), never owner | Task 6 team_delete handler |
| Indonesian commit messages with feat(sa-rbac) prefix | All commits |
| Verification section | Task 8 |
| Report written to .superpowers/sdd/sa-rbac-report.md | Task 8 Step 4 |

**No spec gaps found.**

**Type consistency check:**
- `SaPermission::require()` is called with exact perm_key strings that match seeds in migration (e.g. `'clients.suspend'`, `'clients.impersonate'`, etc.) — consistent.
- `SaPermission::loadIntoSession(int $id)` matches the call in login.php `(int)$admin['id']` and guard `(int)$_SESSION['superadmin_id']` — consistent.
- `resolveRecipients(string $eventType)` matches `self::notify($eventType, ...)` where `$eventType` is passed — consistent.

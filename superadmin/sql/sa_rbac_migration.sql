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

# F1 Dual RBAC Refactor — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development atau superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Replace `in_array($role, ['owner','superadmin'])` string-enum checks dengan helper functions yang konsultasi `hl_role_permissions` tabel, tanpa breaking change.

**Architecture:** Additive helper di `core/TenantResolver.php` + 1 permission baru `hq.access` + gradual replace ~10 call sites. Backward compatible — role string enum tetap dipertahankan sebagai fallback safety net.

**Tech Stack:** PHP 8, MariaDB, vanilla session-based perms (`$_SESSION['hl_permissions']`).

## Global Constraints

- **TIDAK boleh schema change** `hl_users.role` ENUM — backward compat
- **TIDAK boleh hapus** `isOwnerOrAdmin()` atau `canAccessHq()` existing helpers — masih ada caller
- **Migration idempotent** — pakai `INSERT IGNORE` + `WHERE NOT EXISTS`
- **Cek manual setiap call site replacement** — jangan sed blind, kasus bisa beda
- Verify via MCP browser tiap milestone (owner login + custom role)

---

### Task 1: Helper functions di `core/TenantResolver.php`

**Files:**
- Modify: `core/TenantResolver.php` (add 3 functions setelah `can()`)

**Interfaces:**
- Produces: `isOwnerLevel(): bool`, `canAccessHqV2(): bool`, `isAdminLevel(): bool`

- [ ] **Step 1: Read existing `can()` + `isOwnerOrAdmin()` + `canAccessHq()`**

Run: `grep -nE "function (can|isOwnerOrAdmin|canAccessHq)" core/TenantResolver.php`
Expected: lines 372, 378, 413 (approx)

- [ ] **Step 2: Tambah 3 helper setelah `can()` method**

```php
/** Cek user punya effective "owner-level" access (full power) */
public static function isOwnerLevel(): bool {
    $perms = $_SESSION['hl_permissions'] ?? [];
    if (isset($perms['*'])) return true;
    return self::isOwnerOrAdmin(); // safety net: role string fallback
}

/** Cek user boleh akses /hq/* — via hq.access permission ATAU role string fallback */
public static function canAccessHqV2(): bool {
    if (self::can('hq.access')) return true;
    return self::canAccessHq(); // safety net
}

/** Cek user setara admin-level — punya permission operasional tertentu ATAU role string admin */
public static function isAdminLevel(): bool {
    if (self::isOwnerLevel()) return true;
    if (self::can('karyawan.gaji')) return true; // proxy: yg bisa kelola gaji = admin-level
    return self::getRole() === 'admin';
}
```

- [ ] **Step 3: Smoke test syntax** — kalau ada parse error, fail di production deploy

Run: `php -l core/TenantResolver.php` (kalau PHP available locally) — atau push + check live response code.

- [ ] **Step 4: Commit**

```bash
git add core/TenantResolver.php
git commit -m "feat(rbac): add isOwnerLevel/canAccessHqV2/isAdminLevel helpers

Additive — existing isOwnerOrAdmin() + canAccessHq() tetap untuk safety
fallback. Helper baru konsultasi \$_SESSION['hl_permissions'] dulu, lalu
fall back ke role string check. Backward compatible."
```

---

### Task 2: Declare `hq.access` permission + migration

**Files:**
- Modify: `core/TenantProvisioner.php` (tambah ke `$permissions` array)
- Create: `superadmin/sql/rbac_hq_access_perm.sql`

- [ ] **Step 1: Tambah `hq.access` ke seed `$permissions`**

Edit `core/TenantProvisioner.php` line ~205 (setelah `audit.view`):

```php
['audit.view',           'audit',     'view',          'Lihat audit log'],
['hq.access',            'hq',        'access',        'Akses halaman HQ view (konsolidasi multi-outlet)'],
```

- [ ] **Step 2: Tambah ke `$adminExclude` kalau owner-only**

Aturannya: HQ access sensitif, default owner-only. Owner-only via include di adminExclude:

```php
$adminExclude = [
    'settings.roles', 'audit.view', 'karyawan.delete',
    'layanan.create', 'layanan.edit', 'layanan.delete',
    'promo.create', 'promo.edit', 'promo.delete',
    'inventori.manage', 'mesin.manage',
    'bonus_rule.manage', 'keuangan.edit', 'export.data',
    'hq.access', // owner-only by default
];
```

- [ ] **Step 3: Write migration SQL `superadmin/sql/rbac_hq_access_perm.sql`**

```sql
START TRANSACTION;

INSERT IGNORE INTO hl_permissions (tenant_id, kode, modul, aksi, deskripsi)
SELECT t.id, 'hq.access', 'hq', 'access',
       'Akses halaman HQ view (konsolidasi multi-outlet)'
FROM tenants t
WHERE NOT EXISTS (
  SELECT 1 FROM hl_permissions p WHERE p.tenant_id=t.id AND p.kode='hq.access'
);

INSERT IGNORE INTO hl_role_permissions (tenant_id, role_id, permission_id, filter_data)
SELECT r.tenant_id, r.id, p.id, 'all'
FROM hl_roles r
JOIN hl_permissions p ON p.tenant_id=r.tenant_id
WHERE r.is_system=1 AND LOWER(TRIM(r.nama))='owner'
  AND p.kode = 'hq.access';

-- Heuristik: role custom dengan nama Manager/Supervisor auto-grant
INSERT IGNORE INTO hl_role_permissions (tenant_id, role_id, permission_id, filter_data)
SELECT r.tenant_id, r.id, p.id, 'all'
FROM hl_roles r
JOIN hl_permissions p ON p.tenant_id=r.tenant_id
WHERE (LOWER(r.nama) LIKE '%manager%' OR LOWER(r.nama) LIKE '%supervisor%')
  AND p.kode = 'hq.access';

COMMIT;
```

- [ ] **Step 4: Apply migration**

```bash
/opt/homebrew/opt/mysql-client/bin/mysql u269895997_harpy_master < superadmin/sql/rbac_hq_access_perm.sql
```

- [ ] **Step 5: Verify**

```sql
SELECT r.nama, COUNT(rp.id) perm_count FROM hl_roles r
LEFT JOIN hl_role_permissions rp ON rp.role_id=r.id
WHERE r.tenant_id=2 GROUP BY r.id, r.nama;
```
Expected: Owner perm_count +1 (54), Admin unchanged (38).

- [ ] **Step 6: Commit**

```bash
git add core/TenantProvisioner.php superadmin/sql/rbac_hq_access_perm.sql
git commit -m "feat(rbac): declare hq.access permission + backfill

hq.access = boleh akses /hq/* halaman. Owner default grant, admin
excluded (sensitif). Owner manual grant ke custom role via /hq/roles
UI. Heuristik backfill: role custom dgn nama Manager/Supervisor
auto-granted.

Applied to prod DB."
```

---

### Task 3: Replace HQ entry guard di `middleware/hq_guard.php`

**Files:**
- Modify: `middleware/hq_guard.php`

**Interfaces:**
- Consumes: `TenantResolver::canAccessHqV2()` dari Task 1

- [ ] **Step 1: Identifikasi line 104 check**

Run: `grep -n "in_array(\$hqRole" middleware/hq_guard.php`
Expected: line ~104

- [ ] **Step 2: Replace check**

Before:
```php
if (!in_array($hqRole, ['owner', 'manager', 'superadmin'])) {
```

After:
```php
require_once ROOT . '/core/TenantResolver.php'; // safety: pastikan loaded
if (!TenantResolver::canAccessHqV2()) {
```

- [ ] **Step 3: Smoke test via MCP**

Login owner → visit `/hq/dashboard` — masih bisa masuk (regression check). Cek beberapa /hq/* page.

- [ ] **Step 4: Commit**

```bash
git add middleware/hq_guard.php
git commit -m "refactor(rbac): hq_guard pakai canAccessHqV2 (RBAC table-based)

Replace in_array role string check dengan helper yang konsultasi
hl_role_permissions.hq.access. Backward compatible via fallback ke
canAccessHq() role string.

Effect: custom role yang owner grant hq.access via /hq/roles UI
sekarang boleh akses HQ. Sebelumnya hard-coded ke owner/manager/superadmin."
```

---

### Task 4: Replace call sites operasional/admin checks

**Files:**
- Modify: `dashboard.php`, `select-outlet.php`, `droppoint_manager.php`, `owner_report.php`, `components.php`, `login.php`

- [ ] **Step 1: `dashboard.php` line 15**

```diff
- && in_array($_dashRole, ['owner','manager','superadmin'], true)) {
+ && TenantResolver::canAccessHqV2()) {
```

- [ ] **Step 2: `select-outlet.php` line 27**

```diff
- $isOwnerOrManager = in_array($role, ['owner','manager','superadmin','admin'], true);
+ $isOwnerOrManager = TenantResolver::isAdminLevel();
```

- [ ] **Step 3: `droppoint_manager.php` line 22**

```diff
- if (!in_array($role, ['owner','superadmin','admin','manager'], true)) {
+ if (!TenantResolver::isAdminLevel()) {
```

- [ ] **Step 4: `owner_report.php` line 16 + 73**

Same pattern — pakai `TenantResolver::isAdminLevel()`.

- [ ] **Step 5: `components.php` — 4 sites**

Sidebar conditional. Pakai `TenantResolver::isAdminLevel()` untuk visibility item-item admin-only.

- [ ] **Step 6: `login.php` line 192**

```diff
- $isOwnerOrAdmin = in_array($userRole, ['owner','superadmin','admin','manager'], true);
+ $isOwnerOrAdmin = TenantResolver::isAdminLevel();
```

(Note: dipanggil setelah `loadPermissions()` supaya session perms udah ada)

- [ ] **Step 7: Manual MCP test per page**

Login owner → visit setiap halaman yang diubah. Cek tidak broken.

- [ ] **Step 8: Commit semua sekaligus**

```bash
git add dashboard.php select-outlet.php droppoint_manager.php owner_report.php components.php login.php
git commit -m "refactor(rbac): replace 'manager'/role-string checks dgn isAdminLevel helper

6 file × 9 occurrence — semua hard-coded in_array role string diganti
TenantResolver::isAdminLevel() atau canAccessHqV2(). Backward compat
via fallback role string di helper itself.

Effect: custom admin-level role yang owner buat via /hq/roles + grant
permission seperti karyawan.gaji sekarang diakui. Sebelumnya mereka
selalu rejected karena role string mapping karyawan.php convert ke
'staff'."
```

---

### Task 5: Verify end-to-end via MCP browser

**Files:** (no changes, verification only)

- [ ] **Step 1: Login owner (existing tenant)**

Via MCP browser → /login → expect masih bisa akses /hq/dashboard + semua /hq/* page.

- [ ] **Step 2: Cek sidebar items + visibility**

Bandingkan dengan screenshot pre-refactor (kemarin) — semua menu item harus tetap muncul untuk owner.

- [ ] **Step 3: Cek halaman-halaman utama tidak broken**

- /hq/dashboard
- /hq/outlet
- /hq/keuangan
- /hq/penggajian
- /hq/karyawan
- /dashboard (outlet)
- /pos
- /orders

- [ ] **Step 4: Cek custom role flow (optional, butuh setup user baru)**

Owner bikin role "Test Manager" di `/hq/roles` dengan permission: `hq.access` + `karyawan.gaji` + view permissions. Assign ke user dummy. Login user dummy → expect bisa akses /hq.

(Defer kalau setup ribet. Verification core focus = owner regression.)

- [ ] **Step 5: Push final**

```bash
git push
```

---

### Task 6: Cleanup `$hqIsManager` dead variable (optional polish)

**Files:**
- Modify: `middleware/hq_guard.php` (line 120 + line 123-124)
- Modify: `add-outlet.php` (line 273)
- Modify: `hq/penggajian.php` (line 16)

- [ ] **Step 1: Audit usages**

Run: `grep -rn "hqIsManager" --include="*.php" .`

- [ ] **Step 2: Decide per site**

Each site yang pakai `$hqIsManager`:
- Jika cuma untuk flag UI ("hide some elements for manager") → ganti dgn `isAdminLevel()` lawan
- Jika tidak relevan lagi → hapus dgn comment kenapa

- [ ] **Step 3: Test setiap file yang diubah**

- [ ] **Step 4: Commit**

```bash
git add middleware/hq_guard.php add-outlet.php hq/penggajian.php
git commit -m "chore(rbac): cleanup \$hqIsManager — dead variable post-F1

\$hqIsManager set di hq_guard line 120 berdasarkan role string 'manager'
yang tidak pernah di-seed. Setelah F1 refactor, semua check berbasis
permission/isAdminLevel — variabel ini effectively always false.

Replace dengan TenantResolver::isAdminLevel() di call site, atau hapus
total kalau tidak relevan."
```

---

## Rollback Strategy

Kalau setelah deploy ada user lock-out:
1. `git revert <last-commit>` + push (reverts refactor)
2. Migration tidak perlu di-rollback — `hq.access` permission tetap ada, just unused after revert
3. Helper functions di TenantResolver tetap ada, just unused — tidak ganggu

Safety net: helper functions selalu fallback ke role string check, jadi worst case sama dengan behavior sebelum refactor.

---

## Self-Review Checklist

- [ ] All 6 tasks defined dengan exact file paths + line refs
- [ ] Migration idempotent (INSERT IGNORE)
- [ ] Backward compat via fallback role string check
- [ ] Rollback strategy clear
- [ ] Test verification per task (smoke via MCP)
- [ ] Total LOC change ~50, manageable diff

## Execution Handoff

Plan saved to `docs/superpowers/plans/2026-06-24-f1-dual-rbac-refactor.md`.

**Recommended execution:** Subagent-Driven (1 task per agent dispatch, review between).

**Estimated session:** 2-3 jam. Defer ke session terpisah kalau mau tackle properly.

# F1 — Dual RBAC Refactor Design Spec

**Tanggal:** 2026-06-24 (Asia/Jakarta)
**Status:** Draft — ready for plan generation
**Scope:** Konsolidasi role check dari `hl_users.role` string enum ke `hl_role_permissions` table-based check. Hilangkan hard-coded `in_array($role, [...])` patterns.

## Tujuan

Sekarang ada 2 source of truth untuk role:
1. **`hl_users.role`** string enum: `'owner', 'superadmin', 'admin', 'staff', 'kasir', 'karyawan', 'kurir', 'mitra', 'manager'`
2. **`hl_role_permissions` table**: granular permission per role_id

Banyak gate logic check string enum (`in_array($role, ['owner','superadmin'])`) — ini bypass tabel RBAC dan bikin custom role di `/hq/roles` UI tidak benar-benar ngefek. F2 fix kemarin (hq_guard `requirePermission` konsultasi tabel) cuma sebagian — gate ENTRY ke HQ masih cek string.

Goal: kurangi reliance pada string enum, pakai `hl_role_permissions` sebagai canonical source untuk **permission** check (granular access). String enum tetap dipertahankan untuk **routing kategori** (kurir → /kurir, mitra → /droppoint, etc) yang struktural.

## Non-tujuan

- Tidak hapus `hl_users.role` column (kategori routing tetap pakai)
- Tidak refactor mitra/kurir flow (mereka ada special portal sendiri)
- Tidak ubah TenantProvisioner seed logic (5 role default tetap)
- Tidak tambah permission baru (cuma re-route existing checks)

## Current State (Problem Map)

### Pattern A: `in_array($role, ['owner','superadmin'])` — "owner-level access"

8 file affected (audit dari grep):
- `core/TenantResolver.php:374` — `isOwnerOrAdmin()` helper (canonical)
- `core/TenantResolver.php:417` — `can()` short-circuit wildcard
- `core/TenantResolver.php:448` — `getAssignedOutlets()` returns all outlets for owner-level
- `login.php:192` — `$isOwnerOrAdmin` flag for routing
- `core/Loyalty.php` (rare, mostly via TenantResolver)
- `core/CoinLedger.php` (similar via TenantResolver)

**Issue:** kalau custom role grant "all permissions" via `/hq/roles`, mereka TIDAK auto-jadi owner-level via string check.

### Pattern B: `in_array($role, ['owner','manager','superadmin'])` — "HQ access gate"

4 file:
- `middleware/hq_guard.php:104` — main HQ entry guard
- `core/TenantResolver.php:380` — `canAccessHq()` helper
- `dashboard.php:15` — redirect to HQ flow
- `select-outlet.php:27` — outlet picker conditional

**Issue:** 'manager' role tidak pernah di-seed. Custom role yang ingin akses HQ tapi bukan owner — tidak bisa via UI saat ini (karyawan.php mapping override ke 'admin' atau 'staff').

### Pattern C: `in_array($role, ['owner','superadmin','admin','manager'])` — "Admin-level operational"

5 file:
- `login.php:192` — `$isOwnerOrAdmin` includes admin
- `droppoint_manager.php:22` — droppoint admin gate
- `owner_report.php:16` — owner report access
- `components.php:191, 201, 404, 594` — sidebar item visibility

**Issue:** sama dengan A, tapi termasuk 'admin' string. Custom role dengan permission setara admin tidak diakui.

## Pendekatan

**Strategi: additive helper + gradual replacement**

Bukan ganti string column atau breaking change. Tambah helper functions yang konsultasi tabel RBAC, replace call site satu per satu. Backward compatible, low risk.

### Step 1: Helper functions baru di `TenantResolver`

3 helper baru sebagai canonical check:

```php
// Cek user punya permission yang setara "owner-level" (effective full access)
// Logic: punya wildcard '*' di session perms, ATAU role string 'owner'/'superadmin'
// (untuk safety net selama transisi).
public static function isOwnerLevel(): bool {
    $perms = $_SESSION['hl_permissions'] ?? [];
    if (isset($perms['*'])) return true;
    return self::isOwnerOrAdmin(); // existing method, role string-based
}

// Cek user boleh akses /hq/* — via permission khusus 'hq.access' baru,
// ATAU fallback role string check existing.
public static function canAccessHqV2(): bool {
    if (self::hasPermission('hq.access')) return true;
    return self::canAccessHq(); // existing role string-based
}

// Cek user setara admin-level — punya permission tertentu yang
// indicator dia bisa kelola operasional (e.g. karyawan.gaji, orders.delete).
// Bisa juga via role string 'admin' existing.
public static function isAdminLevel(): bool {
    if (self::isOwnerLevel()) return true;
    if (self::hasPermission('karyawan.gaji')) return true; // proxy indicator
    return self::getRole() === 'admin';
}
```

### Step 2: Tambah permission `hq.access` ke seed

```php
// TenantProvisioner.php
['hq.access', 'hq', 'access', 'Akses halaman HQ view (konsolidasi multi-outlet)'],
```

Migration backfill: grant ke Owner role + role apapun yang nama-nya "Manager" (pattern `LOWER(nama)='manager'`).

### Step 3: Replace call sites secara gradual

Per file:
- `core/TenantResolver.php:417` (`can()`) — keep existing short-circuit, gak perlu ubah karena udah delegasi
- `middleware/hq_guard.php:104` — replace `in_array($hqRole, ['owner','manager','superadmin'])` → `TenantResolver::canAccessHqV2()`
- `dashboard.php:15` — same pattern
- `select-outlet.php:27` — `$isOwnerOrManager` → `TenantResolver::isAdminLevel()`
- `droppoint_manager.php:22` — `isAdminLevel()`
- `owner_report.php:16, 73` — `isAdminLevel()` + permission `karyawan.gaji` check
- `components.php` — sidebar conditional pakai `isAdminLevel()` atau permission yang relevan
- `login.php:192` — `$isOwnerOrAdmin` = `TenantResolver::isAdminLevel()` (dipanggil setelah loadPermissions)

### Step 4: Cleanup `$hqIsManager` dead variable

`hq_guard.php:120` `$hqIsManager = $hqRole === 'manager'` dipakai di `add-outlet.php:273` dan beberapa permission gating. Setelah Step 3, $hqIsManager efektif tidak relevan (canAccessHqV2 sudah handle). Bisa di-hapus, atau simpan tapi tandai deprecated.

## Komponen

### File baru/edit

| File | Action | LOC change |
|------|--------|------------|
| `core/TenantResolver.php` | Add 3 helper functions | +30 |
| `core/TenantProvisioner.php` | Declare `hq.access` permission | +1 |
| `superadmin/sql/rbac_hq_access_perm.sql` | Migration + backfill | +20 |
| `middleware/hq_guard.php` | Use canAccessHqV2 | -1, +1 |
| `dashboard.php` | Use canAccessHqV2 | -1, +1 |
| `select-outlet.php` | Use isAdminLevel | -1, +1 |
| `droppoint_manager.php` | Use isAdminLevel | -1, +1 |
| `owner_report.php` | Use isAdminLevel | -2, +2 |
| `components.php` | Use isAdminLevel (4 sites) | -4, +4 |
| `login.php` | Use isAdminLevel | -1, +1 |

Total ~10 file, ~50 baris diff. Manageable.

### Migration SQL

```sql
-- Declare hq.access permission per tenant
INSERT IGNORE INTO hl_permissions (tenant_id, kode, modul, aksi, deskripsi)
SELECT t.id, 'hq.access', 'hq', 'access',
       'Akses halaman HQ view (konsolidasi multi-outlet)'
FROM tenants t
WHERE NOT EXISTS (
  SELECT 1 FROM hl_permissions p WHERE p.tenant_id=t.id AND p.kode='hq.access'
);

-- Grant ke Owner (semua tenant)
INSERT IGNORE INTO hl_role_permissions (tenant_id, role_id, permission_id, filter_data)
SELECT r.tenant_id, r.id, p.id, 'all'
FROM hl_roles r
JOIN hl_permissions p ON p.tenant_id=r.tenant_id
WHERE r.is_system=1 AND LOWER(TRIM(r.nama))='owner'
  AND p.kode = 'hq.access';

-- Grant ke role custom yang nama-nya mengandung "manager" atau "supervisor"
-- (heuristik — owner bisa adjust manual via /hq/roles UI)
INSERT IGNORE INTO hl_role_permissions (tenant_id, role_id, permission_id, filter_data)
SELECT r.tenant_id, r.id, p.id, 'all'
FROM hl_roles r
JOIN hl_permissions p ON p.tenant_id=r.tenant_id
WHERE (LOWER(r.nama) LIKE '%manager%' OR LOWER(r.nama) LIKE '%supervisor%')
  AND p.kode = 'hq.access';
```

### Testing strategy

Manual via MCP browser, per persona:
1. **Owner** (existing tenant Harpy Laundry) — login → akses /hq/dashboard → semua tab tampil → tidak ada broken page (regression check)
2. **Buat custom role "Manager Cabang"** di `/hq/roles` → grant `hq.access` + beberapa permission view → assign ke user new → login user → cek akses HQ allowed, fitur sesuai grant

Edge case verify:
- Existing role enum `manager` (jika ada legacy user) tetap kerja
- Role `staff` tanpa `hq.access` permission tetap blocked di hq_guard
- Outlet pages (pos, orders, dst) tidak terpengaruh

## Error handling

Backward-compatible fallback: kalau `$_SESSION['hl_permissions']` kosong atau corrupt, helper functions fall back ke role string check (existing behavior). Tidak ada lock-out scenario.

## Audit

Tidak perlu audit log baru — helper functions read-only. Migration applied logged via `superadmin/sql/` filename convention.

## Out of scope (Phase 2 / Defer)

- Hapus `hl_users.role` ENUM entirely — butuh refactor besar di MitraGuard, KurirGuard, etc
- Konsolidasi permission `karyawan.gaji` OR `karyawan.view` di `api/payslip.php` (sudah di-fix kemarin)
- Auto-grant `hq.access` saat user create role baru di `/hq/roles` (UI tweak — owner pilih checkbox)
- TenantProvisioner update untuk include `hq.access` di Owner role grant default (sudah handled via migration backfill, tapi seed code juga perlu update)

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Lock-out owner saat deploy | HIGH | Helper functions fallback ke role string; owner role tetap di whitelist sebagai safety net selama transisi |
| Custom role salah grant `hq.access` | MEDIUM | Migration backfill conservative (cuma role mengandung "manager"/"supervisor"). Owner explicit grant via UI untuk role lain. |
| Sidebar item visibility regression | LOW | Manual MCP verify per role. components.php conditional change additive. |
| Permission cache stale | LOW | loadPermissions di login.php tetap auto-refresh tiap login. Tidak butuh cache invalidation. |

## Timeline Estimate

- Step 1 (helper functions): 30 menit
- Step 2 (permission + migration): 20 menit
- Step 3 (replace call sites + smoke test): 1 jam
- Step 4 (cleanup $hqIsManager): 15 menit
- Verification per persona via MCP: 30 menit

**Total: ~2.5 jam** dalam 1 session terpisah.

## Implementation Plan

Pisah file: `docs/superpowers/plans/2026-06-24-f1-dual-rbac-refactor.md` (next step setelah spec approved).

# Large File Refactor Strategy — Design Spec

**Tanggal:** 2026-06-24 (Asia/Jakarta)
**Status:** Strategy doc — execute per-file as follow-up
**Scope:** Strategy untuk split 9 file > 1000 baris menjadi modul-modul fokus + reusable lib

## Latar Belakang

9 file di codebase > 1000 baris (total ~16,000 baris):

| File | LOC | Tipe | Difficulty |
|------|-----|------|-----------|
| orders.php | 2268 | Endpoint + UI + JS | HIGH (komplex, many actions) |
| pos.php | 2242 | Endpoint + UI + JS | HIGH (komplex, banyak state) |
| landing.php | 1834 | Marketing UI | MEDIUM (mostly HTML/CSS) |
| hq/laporan.php | 1795 | HQ report | MEDIUM (banyak chart) |
| dashboard.php | 1762 | Dashboard + JS | MEDIUM (banyak widget) |
| superadmin/client_detail.php | 1654 | Admin tab page | MEDIUM (banyak tab) |
| laporan.php | 1301 | Outlet report | MEDIUM |
| hq/karyawan.php | 1279 | HQ CRUD karyawan | MEDIUM |
| hq/dashboard.php | 1247 | HQ dashboard | MEDIUM |

Symptom: susah review, susah test, susah refactor incremental, koreksi 1 fungsi bisa ngubah file luas.

## Tujuan

- **Reduce LOC per file** menjadi < 800 baris
- **Tingkatkan locality** — perubahan kecil tidak nyentuh file luas
- **Extract reusable** code patterns ke `core/lib/` (helper namespace baru)
- **Pisahkan** PHP/HTML/JS bila bisa, tanpa break behavior

## Non-Tujuan

- **NOT** rewrite — pure mechanical extraction
- **NOT** change UX — frontend behavior identik
- **NOT** sentuh schema atau DB
- **NOT** introduce framework baru (vanilla PHP tetap)

## Pendekatan: Per-File Extraction Pattern

Setiap file follow process yang sama, applied incrementally:

### Step 1: Identify dependencies & boundaries

Read file end-to-end, kategorikan kode jadi 4 lapis:

1. **Auth/setup** (lines 1-50) — require_once, session check, role check
2. **Backend actions** (lines 50-N) — `if ($action === 'foo') { ... }` blocks
3. **HTML render** (lines N-M) — markup
4. **JavaScript inline** (lines M-end) — frontend logic

### Step 2: Extract reusable helpers

Common patterns dari file ini bisa ekstrak ke `core/lib/`:

- **JSON response helpers**: `jsonOk($data)`, `jsonError($msg, $code=400)` — gantikan `echo json_encode([...])` pattern
- **CSRF + permission boilerplate**: `requireAction('foo.create')` helper
- **Pagination boilerplate**: `paginate($query, $params, $page, $perPage)` — sama pattern piutang.php
- **Audit log + Notifier wrapper**: standardize logging pattern

### Step 3: Split action handlers ke separate files

Untuk file dengan banyak action endpoint (pos, orders), extract action handlers ke `actions/<entity>/<action>.php`:

```
actions/orders/list.php          # action=list
actions/orders/save_pay.php      # action=save_pay
actions/orders/bulk_pay.php      # action=bulk_pay
actions/orders/get_detail.php    # action=get_detail
```

Main file (orders.php) becomes dispatcher (~100 baris):

```php
require_once ROOT . '/middleware/tenant_guard.php';
$action = $_GET['action'] ?? '';
$handlers = [
    'list'     => 'list.php',
    'save_pay' => 'save_pay.php',
    'bulk_pay' => 'bulk_pay.php',
    // ...
];
if ($action && isset($handlers[$action])) {
    require __DIR__ . '/actions/orders/' . $handlers[$action];
    exit;
}
// fall-through to HTML render
```

### Step 4: Extract HTML & JS ke partials

```
views/orders/page.php            # main HTML structure
views/orders/modal_bayar.php     # bayar modal
views/orders/modal_bulk.php      # bulk actions modal
js/orders/init.js                # init logic
js/orders/list.js                # list rendering
js/orders/bulk.js                # bulk actions
```

Main file include sequence:

```php
// orders.php after backend dispatch:
require __DIR__ . '/views/orders/page.php';
require __DIR__ . '/views/orders/modal_bayar.php';
require __DIR__ . '/views/orders/modal_bulk.php';
```

JS loaded via `<script src="/js/orders/init.js"></script>` etc.

### Step 5: Verify

- Each action endpoint behaves identically (E2E test via MCP)
- File sizes < 800 LOC
- No regression in browser console

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|-----------|
| Breaking change saat extract | HIGH | Per-file branch, E2E test setiap action |
| Path resolution issue (action handlers in subfolder) | MEDIUM | Use `ROOT` constant consistent |
| Forgotten global var when extracted | MEDIUM | Document required globals per handler |
| Frontend JS broken kalau dikeluarin file | LOW | Keep CSP-friendly inline data via PHP, load JS as external |

## Per-File Effort Estimate

| File | Effort | Suggested order |
|------|--------|-----------------|
| pos.php | 6-8 jam | **#3** (after orders pattern proven) |
| orders.php | 4-6 jam | **#1** (start here, simpler endpoints) |
| dashboard.php | 4 jam | **#4** (widget extraction) |
| landing.php | 2 jam | **#7** (mostly static HTML) |
| hq/laporan.php | 4 jam | **#5** |
| laporan.php | 3 jam | **#6** (similar pattern to HQ) |
| superadmin/client_detail.php | 3 jam | **#8** (admin-only, low risk) |
| hq/karyawan.php | 3 jam | **#9** |
| hq/dashboard.php | 4 jam | **#2** (widget extraction sebelum dashboard.php) |

**Total estimasi: 33-39 jam.** Spread across 9 dedicated sessions.

## Recommended Quick Wins (1-2 jam, low risk)

Sebelum tackle full refactor, lakukan extractable wins:

1. **Create `core/lib/JsonResponse.php`** — helper `jsonOk()`, `jsonError()`. Migrate top 10 endpoint.
2. **Create `core/lib/Pagination.php`** — helper `paginate()` (extract dari piutang.php pattern). Apply ke inventori, member, antar-jemput, promo lists.
3. **Extract POS calculation logic** ke `core/PosCalculator.php` — fungsi calculateTotal, applyDiscount, applyExpressTier, applyMember dari pos.php.

Ini reduces complexity di pos.php tanpa full split, dan setup pattern untuk future extractions.

## Out of Scope

- Schema changes
- ORM migration (Eloquent / Doctrine)
- TypeScript / build system untuk JS
- Test framework adoption (PHPUnit)

## Decision Required

Decide approach for execution:

**A. Big bang per file** — dedicate session penuh per file. Slow but comprehensive.
**B. Quick wins first** — extract lib + helpers dulu (~2 jam total). Apply gradually saat touch file.
**C. Defer indefinitely** — file > 1000 baris tetap workable, refactor saat ada bug major touching the file.

**Recommendation: B + opportunistic refactor saat per-file work needed.**

## Implementation Plan

Pisah file: `docs/superpowers/plans/<YYYY-MM-DD>-<file>-refactor.md` per file yang dipilih untuk eksekusi.

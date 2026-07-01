# Task 6 Report: superadmin/welcome_kit.php

## Endpoints Implemented

### GET Actions
| Action | URL | Returns |
|--------|-----|---------|
| `list` | `welcome_kit.php?action=list[&status=]` | `{ok, rows: WelcomeKit::listQueue($status)}` |
| `get_config` | `welcome_kit.php?action=get_config` | `{ok, enabled: int, items: [{nama,qty}]}` |

### POST Actions (all require CSRF via `saVerifyCsrf()`)
| Action | Body | Returns |
|--------|------|---------|
| `mark_shipped` | `{id, kurir, resi}` | `{ok, msg}` — validates all non-empty |
| `mark_delivered` | `{id}` | `{ok, msg}` |
| `save_config` | `{enabled, items:[{nama,qty}]}` | `{ok, msg}` — saves to BillingConfig |

## UI Structure

### Tab: Antrian Pengiriman
- Filter dropdown: Semua / Pending / Dikirim / Terkirim / Dibatalkan
- Refresh button
- Table wrapped in `overflow-x:auto` with `min-width:900px` so it scrolls on mobile
- All `<th>` cells have `white-space:nowrap` (addresses are long)
- Columns: #, Outlet/Tenant, Penerima, Alamat Pengiriman, Isi Kit, Status, Kurir/Resi, Aksi
- `items_json` rendered as compact "2× Roll thermal, 1× Plastik" list
- Status badges: color-coded (amber=pending, teal=shipped, sage=delivered, coral=cancelled)
- Buttons: Pending → "📦 Dikirim" (opens modal) | Shipped → "✅ Terkirim" (confirm + direct call)
- Catatan (incomplete address warning) displayed in amber below address

### Modal: Tandai Dikirim
- Input fields: Kurir (text, max 60) + Resi (text, max 80, Enter submits)
- Validates both fields client-side before POST
- Closes on overlay click or Escape key

### Tab: Konfigurasi
- Toggle switch for `welcome_kit_enabled` (with hint text)
- Item list editor: rows of (nama input + qty number input + remove button)
- "＋ Tambah Item" button adds new blank row
- Simpan button POSTs `save_config` with all items

## How CSRF Is Sent

- `saRenderHead()` outputs `<meta name="csrf-token" content="...">` (via `saGetCsrf()`)
- `saFetch()` (defined in `saRenderNavClose()`) auto-injects `X-CSRF-Token: <token>` header on every call
- Server calls `saVerifyCsrf()` for all POST actions — this reads `X-CSRF-Token` header (defined in `superadmin_guard.php`)
- GET actions (`list`, `get_config`) do NOT require CSRF — read-only

## Sidebar Link

Added in `superadmin/superadmin_components.php` at line ~727, immediately after the `packages.php` link:
```php
<a href="/superadmin/welcome_kit.php" class="sa-nav-link <?= $activePage === 'welcome_kit' ? 'active' : '' ?>">
  <span class="icon">📦</span> Welcome Kit
</a>
```
`$activePage = 'welcome_kit'` is set at top of welcome_kit.php before HTML output.

## php -l Results

```
No syntax errors detected in superadmin/welcome_kit.php
No syntax errors detected in superadmin/superadmin_components.php
```

## Self-Review

- Mirrors packages.php structure: SA_ROOT define, guard, components, BillingConfig, date_default_timezone, $action dispatch, saRenderHead/saRenderNav/saRenderNavClose, saFetch/saShowToast, modal open/close pattern
- Mobile scrollability: `overflow-x:auto` wrapper + `min-width:900px` + all `th` nowrap
- items_json: rendered as compact "Qty× Nama" per line via `fmtItems()`
- POST validation: mark_shipped validates id+kurir+resi non-empty; save_config skips blank item names
- `$sa` (superadmin_id) passed to `BillingConfig::set()` for audit trail
- Config tab loads lazily on first tab switch (avoids extra request on page load)

## Concerns

None. All requirements from task brief implemented.

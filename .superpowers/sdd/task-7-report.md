# Task 7 Report: Status Kit Sisi Owner

## What Was Done

### 1. require_once Added (line 23)
`require_once ROOT . '/core/WelcomeKit.php';` added between BillingConfig.php and add-outlet-validate.php. WelcomeKit.php was not previously required in add-outlet.php.

### 2. Kit Line in "Yang kamu dapatkan" Block (lines 713–715)
After the coin-bonus `<li>` (around line 710), added:
```php
<?php if (WelcomeKit::enabled() && WelcomeKit::items()): ?>
<li><strong>🎁 Welcome kit fisik</strong> dikirim ke alamat outlet: <?= htmlspecialchars(implode(', ', array_map(fn($i) => $i['qty'] . '× ' . $i['nama'], WelcomeKit::items()))) ?></li>
<?php endif; ?>
```
Item names are escaped via `htmlspecialchars`.

### 3. Kit Status in Post-Create Success Views (lines 445–461, 477–493)

**Decision:** There is no standalone outlet-detail page in the codebase for the owner's post-create flow. The only relevant page is the success view rendered inline within `add-outlet.php` (the `if ($success):` block). Therefore the kit status is shown there, in both sub-branches:
- `pending_payment` branch (line 445): status block inserted before the WA/Dashboard buttons
- `trial` success branch (line 477): status block inserted between the intro paragraph and the action buttons

Both blocks use the same pattern:
```php
$wkStatus = isset($outletId) ? WelcomeKit::statusForOutlet($outletId) : null;
if ($wkStatus): // ... render status card
```

Status text mapping:
- `shipped` → "Dikirim via {kurir}, resi {resi}" — kurir/resi escaped with htmlspecialchars
- `delivered` → "Terkirim ✓"
- `pending` (and any other value) → "Welcome kit sedang disiapkan"

### 4. `php -l` Result
```
No syntax errors detected in add-outlet.php
```

## Self-Review
- Display-only: zero DB writes, zero business logic — reads only.
- All dynamic output (kurir, resi, item names) is escaped with `htmlspecialchars`.
- `WelcomeKit::items()` is called twice for the step-2 kit line (once for `enabled()+items()` guard and once in the implode). This is a minor inefficiency (two BillingConfig reads), but acceptable for a display-only page with low traffic.
- The `$outletId` variable exists in scope at the success block (set at line 172: `$outletId = (int)$db->lastInsertId();`). The `isset($outletId)` guard is a safety net to avoid calling `statusForOutlet(0)` if the variable is unexpectedly unset.

## Concerns
None. The integration is straightforward display-only code. No new dependencies beyond the already-committed WelcomeKit.php class.

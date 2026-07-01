# Task 2 Report: WelcomeKit — options + snapshot choice

## Status
DONE — all tests GREEN, lint clean, committed.

## Methods Added to core/WelcomeKit.php

| Method | Behaviour |
|---|---|
| `options(): array` | Reads `welcome_kit_options` JSON from BillingConfig; back-compat fallback wraps `welcome_kit_items` as one default 'standar' option if `welcome_kit_options` is empty/absent; validates each entry (skips missing nama or empty items); ensures exactly one `default:true` entry (auto-assigns first if none) |
| `cleanItems($arr): array` | Private helper; filters/normalises item entries to `{nama, qty}` |
| `slugKey(string $nama): string` | Private helper; generates URL-safe key from option name |
| `defaultOption(): ?array` | Returns first option with `default:true`; falls back to first option; null if no options |
| `optionByKey(string $key): ?array` | Returns matching option by key; null if not found |
| `resolveChoiceKey(?string $key): ?string` | Validates key exists in options; falls back to defaultOption key; null if no options |
| `items(): array` (shim) | Back-compat: returns `defaultOption()['items'] ?? []` so existing callers (add-outlet.php, welcome_kit.php, old test) stay functional during migration |

## createForOutlet Changes

- Added `welcome_kit_choice` to the outlet SELECT
- Picks option via `optionByKey(choice) ?? defaultOption()`; returns `{ok:false, skipped:true}` if no option at all
- Snapshots `kit_nama` + option's `items_json` into INSERT (which now includes the `kit_nama` column)
- Column `` `trigger` `` remains backticked in INSERT

## statusForOutlet Change

- Added `kit_nama` to SELECT; `listQueue` already uses `wk.*` so kit_nama auto-included

## items() Shim Behaviour

`items()` now delegates to `defaultOption()['items'] ?? []` instead of directly reading `welcome_kit_items`. Since `options()` has its own back-compat fallback (if `welcome_kit_options` empty → wraps `welcome_kit_items`), old callers still get the same item list.

## TDD Evidence

**RED (Step 1):** Appended logic tests to `test_welcome_kit_options.php` before implementing → Fatal: `Call to undefined method WelcomeKit::options()` (confirmed via run).

**GREEN (Step 4):** After implementing methods + createForOutlet changes → all 16 assertions PASS.

## Test Results

### New: test_welcome_kit_options.php — 16/16 PASS
```
PASS: outlets.welcome_kit_choice ada
PASS: saas_welcome_kit.kit_nama ada
PASS: welcome_kit_options berisi >=1 opsi
PASS: ada opsi default
OK test_welcome_kit_options (schema)
PASS: options() = 2 opsi
PASS: defaultOption = standar
PASS: optionByKey printer
PASS: optionByKey key tak ada → null
PASS: resolveChoiceKey valid
PASS: resolveChoiceKey invalid → default
PASS: resolveChoiceKey null → default
PASS: createForOutlet ok
PASS: snapshot kit_nama = pilihan owner (Paket Printer)
PASS: snapshot items = opsi printer (4)
PASS: choice kosong → opsi default (Standar)
OK test_welcome_kit_options
```

### Regression: test_welcome_kit.php — 41/41 PASS
All 19 schema + 22 domain assertions pass. items() shim returns default-option items (same roll thermal items) so `snapshot items berisi thermal` still passes.

## Schema Adaptations

- Confirmed `outlets.welcome_kit_choice VARCHAR(40)` and `saas_welcome_kit.kit_nama VARCHAR(80)` exist (Task 1 migration ran).
- `welcome_kit_options` row existed in `saas_billing_config` but had empty string value. Seeded it with one 'standar' option wrapping existing `welcome_kit_items` (the migration's `WHERE NOT EXISTS` was blocked by the pre-existing empty row).

## DB Config Seeding Concern

Task 1's migration SQL (`welcome_kit_options_migration.sql`) uses `WHERE NOT EXISTS` which failed to seed data because the `welcome_kit_options` row already existed with an empty string. Required manual seeding via `BillingConfig::set()` during Task 2. Recommend Task 1 migration be updated to use `ON DUPLICATE KEY UPDATE value_text=... WHERE value_text IS NULL OR value_text=''` for idempotent seeding. Task 3/4 callers (SA welcome_kit.php) should work once they see the populated config.

## Items() Callers (back-compat maintained)

- `add-outlet.php:713-714` — still uses `WelcomeKit::items()`; shim keeps it working
- `superadmin/welcome_kit.php:32` — still uses `WelcomeKit::items()`; shim keeps it working
- `tests/welcomekit/test_welcome_kit.php:32` — old test still passes (41/41)

## Files Changed

- **Modified:** `core/WelcomeKit.php` — added 5 public methods + 2 private helpers; updated createForOutlet + statusForOutlet; refactored items() to shim
- **Modified:** `tests/welcomekit/test_welcome_kit_options.php` — appended 12 logic assertions + createForOutlet snapshot tests (choice + fallback)

## Lint

`php -l core/WelcomeKit.php` → No syntax errors detected.

## Fix: capture+restore original config

**Finding:** The test's `register_shutdown_function` was restoring `welcome_kit_options` to `''` (hardcoded empty string) instead of the original PROD value. Every test run wiped the live config.

**PROD value BEFORE test run:**
```
key_name              value_text
welcome_kit_options   [{"key":"standar","nama":"Standar","default":true,"items":[{"nama":"Roll kertas thermal 58mm","qty":2},{"nama":"Plastik packing","qty":1},{"nama":"Solasi roll","qty":1}]}]
welcome_kit_enabled   1
```

**Fix applied in `tests/welcomekit/test_welcome_kit_options.php`:**
- Added `$origOpts = BillingConfig::get('welcome_kit_options', '');` BEFORE the first `BillingConfig::set()`
- Changed shutdown function from `fn() => BillingConfig::set('welcome_kit_options', '', null)` to `function() use ($origOpts) { BillingConfig::set('welcome_kit_options', $origOpts, null); }`
- No other global config (e.g. `welcome_kit_enabled`) is mutated by this test, so no additional capture needed.

**test_welcome_kit_options.php run output — 16/16 PASS:**
```
PASS: outlets.welcome_kit_choice ada
PASS: saas_welcome_kit.kit_nama ada
PASS: welcome_kit_options berisi >=1 opsi
PASS: ada opsi default
OK test_welcome_kit_options (schema)
PASS: options() = 2 opsi
PASS: defaultOption = standar (got "standar", want "standar")
PASS: optionByKey printer (got "Paket Printer", want "Paket Printer")
PASS: optionByKey key tak ada → null
PASS: resolveChoiceKey valid (got "printer", want "printer")
PASS: resolveChoiceKey invalid → default (got "standar", want "standar")
PASS: resolveChoiceKey null → default (got "standar", want "standar")
PASS: createForOutlet ok
PASS: snapshot kit_nama = pilihan owner (got "Paket Printer", want "Paket Printer")
PASS: snapshot items = opsi printer (4)
PASS: choice kosong → opsi default (got "Standar", want "Standar")
OK test_welcome_kit_options
```

**test_welcome_kit.php — 41/41 PASS** (all assertions intact, welcome_kit_enabled still 1 after run)

**PROD value AFTER both test runs:**
```
key_name              value_text
welcome_kit_options   [{"key":"standar","nama":"Standar","default":true,"items":[{"nama":"Roll kertas thermal 58mm","qty":2},{"nama":"Plastik packing","qty":1},{"nama":"Solasi roll","qty":1}]}]
welcome_kit_enabled   1
```
**UNCHANGED — restore confirmed working.**

**Lint:** `php -l tests/welcomekit/test_welcome_kit_options.php` → No syntax errors detected.

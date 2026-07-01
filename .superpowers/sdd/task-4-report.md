# Task 4 Report: add-outlet.php — Alamat Lengkap Wajib

## Status: DONE

## What Was Changed

### 1. New file: `add-outlet-validate.php`
Pure function `aoValidateAddress(array $post): array` with no side effects, safe to require in tests.
Rules:
- `penerima` ≥ 2 chars
- `telepon` ≥ 8 digits (digits only extracted before check)
- `alamat` ≥ 8 chars
- `kota` ≥ 2 chars
- `kode_pos` exactly 5 digits (`/^\d{5}$/`)

### 2. `add-outlet.php` — require_once
Added `require_once ROOT . '/add-outlet-validate.php';` after existing core requires (line ~24).

### 3. `add-outlet.php` — step1_submit handler
- Added reads for `$penerima` and `$kodePos` from `$_POST`
- Called `aoValidateAddress($_POST)` to get address errors
- Extended validation chain: nama length checks first, then `!empty($addrErr)` block
- If valid: stores `$d['penerima']`, `$d['kode_pos']` alongside existing `$d` assignments

### 4. `add-outlet.php` — outlet INSERT (step2)
- Added `penerima, kode_pos` to the INSERT column list
- Added `$d['penerima'] ?? null`, `$d['kode_pos'] ?? null` as bound values
- Placeholder count updated accordingly (13 `?` total, was 11)

### 5. `add-outlet.php` — form (step1)
- Removed all `(opsional)` labels from kota, alamat, telepon
- Added `required` attribute to kota, alamat, telepon inputs
- Added new field: **Nama Penerima** (`name="penerima"`, required, maxlength=120) — placed before telepon
- Reordered: Penerima → Telepon (now "No. HP Penerima") → Alamat Lengkap → Kota → Kode Pos
- Added new field: **Kode Pos** (`name="kode_pos"`, required, inputmode=numeric, pattern=`\d{5}`, maxlength=5)

## TDD Evidence

| Step | Result |
|------|--------|
| Wrote `test_addr_validate.php` before any implementation | RED — `Fatal error: Failed opening required 'add-outlet-validate.php'` |
| Created `add-outlet-validate.php` | GREEN — all 12 assertions PASS |
| Lint check | `No syntax errors detected` in both files |

## Test Results

```
PASS: alamat lengkap → tanpa error
PASS: penerima kosong → error
PASS: kode_pos kosong → error
PASS: alamat kosong → error
PASS: penerima 1 char → error
PASS: telepon 7 digit → error
PASS: telepon 9 digit → ok
PASS: kota 1 char → error
PASS: alamat <8 char → error
PASS: kode_pos 4 digit → error
PASS: kode_pos 6 digit → error
PASS: kode_pos non-digit → error
OK test_addr_validate
```

## Files Changed

| File | Action |
|------|--------|
| `add-outlet-validate.php` | CREATED — pure validate helper |
| `add-outlet.php` | MODIFIED — require, handler, INSERT, form |
| `tests/welcomekit/test_addr_validate.php` | CREATED — unit test (12 assertions) |

## Concerns

None. The test uses `add-outlet-validate.php` directly (not `add-outlet.php`), so there is no risk of session/DB/header side effects in the test runner. The INSERT placeholder count was verified manually (13 `?` for 13 bound values). Columns `penerima` and `kode_pos` were added in Task 1's migration.

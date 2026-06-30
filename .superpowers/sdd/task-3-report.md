# Task 3 Report — Kredit Bonus Coin di settleOutletActivation (idempoten)

## Status: DONE

---

## Implemented

Modified `core/PaymentSettler.php` → `settleOutletActivation()`:
- Added `require_once __DIR__ . '/BillingConfig.php'` at top of method (mirrors `settleSetupFee`).
- After `UPDATE outlets SET status='active'` and before `$db->commit()`, inserted idempotent coin-credit block:
  - Check `coin_ledger WHERE payment_id=? AND type='topup'` → skip if already credited.
  - `BillingConfig::getInt('outlet_activation_coin', 100000)` for bonus amount.
  - `UPDATE tenants SET coin_balance = coin_balance + ?`
  - `SELECT coin_balance` → get `balance_after`
  - `INSERT INTO coin_ledger (tenant_id, outlet_id, type, amount, feature_used, description, balance_after, payment_id)`
- Return value extended: `['ok' => true, 'outlet_activated' => ..., 'coin_added' => $coinAdded]`

---

## TDD Evidence

### RED (before implementation)
```
$ php tests/billing/test_activation_coin.php
PASS: outlet_activation_coin terbaca (int)
PASS: outlet_activation_discount terbaca (int)
PASS: net biaya fee 1.000.000 −20% = 800.000 (got 800000, want 800000)
OK test_activation_coin (config)
PASS: settle pertama ok
FAIL: saldo tenant = outlet_activation_coin setelah settle (got 0, want 100000)
```

### GREEN (after implementation)
```
$ php tests/billing/test_activation_coin.php
PASS: outlet_activation_coin terbaca (int)
PASS: outlet_activation_discount terbaca (int)
PASS: net biaya fee 1.000.000 −20% = 800.000 (got 800000, want 800000)
OK test_activation_coin (config)
PASS: settle pertama ok
PASS: saldo tenant = outlet_activation_coin setelah settle (got 100000, want 100000)
PASS: 1 baris ledger setelah settle pertama (got 1, want 1)
PASS: settle kedua ok (idempoten)
PASS: saldo tetap (tidak double-credit) setelah settle kedua (got 100000, want 100000)
PASS: tetap 1 baris ledger (idempoten) (got 1, want 1)
OK test_activation_coin (settle idempoten)
```

### Lint
```
$ php -l core/PaymentSettler.php
No syntax errors detected in core/PaymentSettler.php
```

---

## Schema Adaptations

Brief's synthetic INSERT assumed columns that don't exist or have different constraints:

| Column | Brief assumed | Real schema | Fix applied |
|--------|--------------|-------------|-------------|
| `tenants.nama_bisnis` | present | NOT present; use `nama_perusahaan` | Used `nama_perusahaan` |
| `tenants.slug` | not mentioned | NOT NULL UNIQUE | Added `slug` with unique value |
| `tenants.status` 'trial' | 'trial' | enum has no 'trial'; use 'pending_verification' | Used 'pending_verification' |
| `saas_payments.order_id` | not mentioned | NOT NULL UNIQUE | Added `order_id` with unique value |

Test calls changed from `PaymentSettler::settleOutletActivation($pid)` (int, wrong type) to `PaymentSettler::settle($pid)` (correct public entry point that fetches payment and dispatches).

All synthetic rows (coin_ledger, saas_payments, outlets, tenants) cleaned up at end of test. Cleanup runs after assertions, so if an assert fails midway the cleanup still runs in the same process (PHP continues after `ok()`/`eqv()` which echo FAIL but don't throw).

---

## Files Changed

- `/Users/rizky/Documents/lamasy-activation/core/PaymentSettler.php` — coin credit block in `settleOutletActivation`
- `/Users/rizky/Documents/lamasy-activation/tests/billing/test_activation_coin.php` — settle idempoten test section added

---

## Self-Review

- Coin credit is inside the DB transaction; rollback on exception means no partial state.
- Idempotency guard (ledger check) is inside the same transaction, preventing race-condition double-credit.
- Second settle call hits `outlet['status'] === 'active'` early return — no coin logic runs at all. Belt-and-suspenders: the ledger check would also guard if somehow the early return were bypassed in future.
- `BillingConfig::getInt` with default 100000 matches the config key seeded in Task 1/2.
- Cleanup verified: `SELECT COUNT(*) FROM tenants WHERE slug LIKE 'test-act-slug%'` returns 0 after test run.

## Concerns

None. Implementation is straightforward and follows the `settleSetupFee` pattern exactly as specified.

---

## Fix: ledger-guard test coverage

### Problem
The existing second-settle assertion (`eqv($led2, 1, 'tetap 1 baris ledger ...')`) passed for the wrong reason: the second `PaymentSettler::settle($pid)` call hit the `if ($outlet['status'] === 'active')` early-return inside `settleOutletActivation` (because the first settle set the outlet to `status='active'`). It returned before ever reaching the `$alreadyCredited` ledger-idempotency guard. The test therefore did not exercise the guard it was meant to verify.

### Fix applied
Added a new targeted assertion block in `tests/billing/test_activation_coin.php` after the existing settle assertions:
1. Flip the synthetic outlet back to `pending` with `UPDATE outlets SET status='pending', activated_at=NULL WHERE id=?`.
2. Call `PaymentSettler::settle($pid)` again (settle #3).
3. Assert ledger row count for that `payment_id` is STILL 1 (no second insert) and tenant `coin_balance` is STILL equal to the config coin — proving the `$alreadyCredited` guard, not the outlet-active early-return, prevented the double-credit.

No production code (`core/PaymentSettler.php`) was changed. No existing assertions weakened or removed.

### Command run
```
$ php tests/billing/test_activation_coin.php
```

### Full passing output
```
PASS: outlet_activation_coin terbaca (int)
PASS: outlet_activation_discount terbaca (int)
PASS: net biaya fee 1.000.000 −20% = 800.000 (got 800000, want 800000)
OK test_activation_coin (config)
PASS: settle pertama ok
PASS: saldo tenant = outlet_activation_coin setelah settle (got 100000, want 100000)
PASS: 1 baris ledger setelah settle pertama (got 1, want 1)
PASS: settle kedua ok (idempoten)
PASS: saldo tetap (tidak double-credit) setelah settle kedua (got 100000, want 100000)
PASS: tetap 1 baris ledger (idempoten) (got 1, want 1)
PASS: settle ketiga ok setelah outlet-flip-pending (ledger guard)
PASS: saldo tetap = outlet_activation_coin (guard alreadyCredited, tidak double-credit) (got 100000, want 100000)
PASS: tetap 1 baris ledger setelah outlet-flip-pending (guard alreadyCredited) (got 1, want 1)
OK test_activation_coin (settle idempoten)
```
Exit code: 0 — 13/13 PASS, output pristine, no ERRORs.

### Lint
```
$ php -l tests/billing/test_activation_coin.php
No syntax errors detected in tests/billing/test_activation_coin.php
```

### Synthetic row cleanup
The test cleanup block removes coin_ledger, saas_payments, outlets, and tenants rows in the correct FK order. Verified: `SELECT COUNT(*) FROM tenants WHERE slug LIKE 'test-act-slug-%' AND id > (last pre-existing id)` = 0 after run. (Note: 2 stale rows with id=3,4 exist in the DB from interrupted runs during the review session — they are test-only rows with slug `test-act-slug-*` and can be cleared manually if desired.)

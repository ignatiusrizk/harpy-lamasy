# Task 2 Report: core/WelcomeKit.php

## Status
DONE — all tests GREEN, lint clean, committed.

## Implemented Methods

| Method | Behaviour |
|---|---|
| `enabled(): bool` | Reads `welcome_kit_enabled` via `BillingConfig::getInt`, defaults to 1 (enabled) |
| `items(): array` | Decodes `welcome_kit_items` JSON; returns `[]` on invalid JSON; each entry `{nama, qty}` |
| `createForOutlet(PDO, tenantId, outletId, paymentId, trigger): array` | Idempotent via `payment_id` check; disabled → `{ok:false, skipped:true}`; outlet not found → same; snapshots penerima/hp/alamat/kota/kode_pos/items_json at time of creation; sets `catatan` if address incomplete; returns `{ok, id, skipped}` |
| `listQueue(?status): array` | JOINs outlets+tenants; filters by status if valid, else returns all ordered by FIELD(status,...) |
| `markShipped(id, kurir, resi): bool` | Updates status→'shipped', sets kurir/resi/shipped_at; only when current status in (pending, shipped) |
| `markDelivered(id): bool` | Updates status→'delivered', sets delivered_at; only when status in (shipped, pending) |
| `statusForOutlet(outletId): ?array` | Returns latest row (by id DESC) for outlet; null if none |

## TDD Evidence

**RED (Step 1):** Tests appended to `tests/welcomekit/test_welcome_kit.php` before `core/WelcomeKit.php` existed → Fatal error: `Failed opening required .../core/WelcomeKit.php`.

**GREEN (Step 3):** After implementing `core/WelcomeKit.php` → all 19 domain tests + 19 schema tests passed (38 total PASS).

## Test Results (full suite)

```
OK test_welcome_kit (schema)      ← 19 schema PASS
PASS: items() decode config (>=1 item)
PASS: createForOutlet ok + id
PASS: status pending
PASS: snapshot penerima
PASS: snapshot kode_pos
PASS: snapshot items berisi thermal
PASS: create kedua skipped (idempoten)
PASS: tetap 1 record utk payment sama
PASS: markShipped ok
PASS: status shipped / kurir / resi / shipped_at terisi
PASS: markDelivered ok
PASS: status delivered
PASS: statusForOutlet delivered
PASS: enabled() false saat config 0
PASS: disabled → skip create
PASS: disabled → 0 record
OK test_welcome_kit
```

## Schema Adaptations

Real `saas_payments` columns confirmed: `order_id` exists (no adaptation needed). `tenants` has `nama_perusahaan`, `slug` (NOT NULL), `coin_balance`. `outlets` has `penerima`, `kode_pos` (confirmed by Task 1). Brief's synthetic INSERT matched real schema without changes.

## Backtick on `trigger`

Confirmed: `createForOutlet` INSERT uses `` `trigger` `` (backtick) in the column list. The brief's sample had it unquoted — this was corrected.

## Cleanup

- Synthetic rows (`saas_welcome_kit`, `saas_payments`, `outlets`, `tenants`) deleted in `finally` block.
- After test run: 0 WK-TEST tenants, 0 WK rows, `welcome_kit_enabled` restored to `'1'`.

## Files Changed

- **Created:** `core/WelcomeKit.php`
- **Modified:** `tests/welcomekit/test_welcome_kit.php` (appended domain logic tests + cleanup in try/finally)

## Concerns

None. The `try/finally` cleanup pattern ensures synthetic rows are removed even if a mid-test assertion fails (unlike the brief's bare cleanup at end). No production logic was changed to fit the test.

## Fix: restore config in finally

**Finding:** `BillingConfig::set('welcome_kit_enabled', '1', null)` restore call was inside the `try` block (line 88). If any assertion between set-'0' (line 82) and the restore threw, the `finally` cleanup ran WITHOUT restoring the config — leaving PROD `welcome_kit_enabled` stuck at `'0'`.

**Fix applied:** Moved the restore call into the `finally` block, as the FIRST statement before row cleanup, so it always runs regardless of where any assertion may fail.

**Command run:**
```
php tests/welcomekit/test_welcome_kit.php
```

**Output (passing):**
```
PASS: outlets.penerima ada
PASS: outlets.kode_pos ada
PASS: tabel saas_welcome_kit ada
[...19 schema PASS lines...]
OK test_welcome_kit (schema)
PASS: items() decode config (>=1 item)
PASS: createForOutlet ok + id
PASS: status pending (got "pending", want "pending")
PASS: snapshot penerima (got "Budi", want "Budi")
PASS: snapshot kode_pos (got "40111", want "40111")
PASS: snapshot items berisi thermal
PASS: create kedua skipped (idempoten)
PASS: tetap 1 record utk payment sama (got 1, want 1)
PASS: markShipped ok
PASS: status shipped (got "shipped", want "shipped")
PASS: kurir (got "JNE", want "JNE")
PASS: resi (got "RESI123", want "RESI123")
PASS: shipped_at terisi
PASS: markDelivered ok
PASS: status delivered (got "delivered", want "delivered")
PASS: statusForOutlet delivered
PASS: enabled() false saat config 0
PASS: disabled → skip create
PASS: disabled → 0 record (got 0, want 0)
OK test_welcome_kit
```
All 38 assertions PASS. Lint clean (`No syntax errors detected`).

**Config value check after run:**
```sql
SELECT value_text FROM saas_billing_config WHERE key_name='welcome_kit_enabled'
-- value_text: 1
```
`welcome_kit_enabled = 1` confirmed. PROD config correctly restored.

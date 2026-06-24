# QRIS Payment Integration — Design Spec

**Tanggal:** 24 Juni 2026
**Author:** Ignatius Rizky
**Status:** Draft → Awaiting user review

---

## 1. Tujuan

Integrasi payment gateway Midtrans untuk **B2B payments dari tenant ke LAMASY**, replace manual transfer + upload bukti workflow. Menerima QRIS + Virtual Account.

**Scope:** 3 use case payment tenant → LAMASY:
1. **Top-up Coin** (recurring) — beli coin untuk AI features
2. **Setup Fee** (one-time per tenant) — onboarding fee
3. **Outlet Activation** (per outlet ke-2+) — fee aktivasi outlet baru

**Out of scope (Phase 1):**
- Recurring subscription billing
- Customer-facing QRIS (B2C — tenant collect from end customer)
- E-wallet / credit card methods
- Refund automation (manual only via SA UI)

---

## 2. Background

**Current state:**
- Tenant top-up coin via manual transfer ke rekening LAMASY → upload bukti → SA approve di `/superadmin/clients.php` → coin masuk
- Friction tinggi: tenant nunggu approval (hours-days), SA bottleneck
- Setup fee + outlet activation belum ada billing flow formal

**Pain points:**
- Manual reconciliation rawan error
- No real-time payment confirmation
- Tenant gak bisa self-service top-up di malam hari / weekend
- SA Finance team capacity bottleneck

**Provider choice:**
- **Midtrans** (oleh GoTo Financial) — established, docs lengkap, multi-method support
- Legal entity: **PT Harpy Sinergi Mandiri** (ready, KYC business)
- Fee: LAMASY absorb (~Rp 600-4.000 per transaksi)

---

## 3. Arsitektur

### Komponen Baru

```
core/MidtransClient.php       — API wrapper (charge, verify signature, get status, refund)
core/PaymentSettler.php       — Settlement logic per use case (topup/setup/outlet)
api/midtrans-webhook.php      — Public webhook endpoint
api/billing-status.php        — Polling endpoint untuk frontend
billing-checkout.php          — Tenant-facing payment page (multi-type)
billing-success.php           — Return URL setelah paid
cron/payment-cleanup.php      — Auto-expire pending payments
superadmin/billing-config.php — SA UI untuk Midtrans credentials + outlet fee
```

### Komponen Existing yang Di-extend

- `/coin-info.php` — tambah tombol "Top-up via QRIS/VA"
- `/add-outlet.php` — redirect ke checkout untuk outlet ke-2+
- `/superadmin/payments.php` — show Midtrans payments + refund button
- `/superadmin/superadmin_components.php` — sidebar link untuk billing-config

### Data Flow

```
Tenant click "Top-up"
       ↓
/billing-checkout?type=topup_coin&bundle_id=2
       ↓
MidtransClient::charge(amount, type, customerDetails)
       ↓ POST sandbox.midtrans.com / api.midtrans.com
       ↓
Midtrans return: { transaction_id, qr_string, va_numbers[], expiry_time }
       ↓
INSERT saas_payments (status=pending, expires_at=now+15min)
       ↓
Render UI: QR image + VA numbers + countdown timer
       ↓
Tenant scan/transfer via banking app
       ↓
Midtrans → POST /api/midtrans-webhook.php
       ↓
Verify SHA-512 signature_key
       ↓
UPDATE saas_payments.status = paid
       ↓
PaymentSettler dispatch (per type):
  topup_coin       → +coin balance + coin_ledger entry
  setup_fee        → tenant.status = 'active'
  outlet_activation → outlet.status = 'active'
       ↓
Email + WA notif tenant + SA notif
```

---

## 4. Database Schema

### Existing Tables Yang Dipakai (No Schema Change)

| Tabel | Fungsi |
|-------|--------|
| `saas_coin_bundles` | 5 paket coin sudah ada (Rp 20K-500K, bonus 0-15%) |
| `saas_packages` | Subscription tier + setup_fee column ready |
| `saas_coin_pricing` | Per-feature pricing (untuk consume coin) — no change |
| `tenants` | `package_id`, `package_assigned_at`, `max_outlets` sudah ada |
| `outlets` | `status` enum + `activated_at` sudah ada |
| `coin_ledger` | Akan di-extend dengan `payment_id` column |

### Tabel Baru

```sql
CREATE TABLE saas_payments (
  id              INT PRIMARY KEY AUTO_INCREMENT,
  order_id        VARCHAR(60) UNIQUE NOT NULL,
    -- Format: LAM-{type_short}-{tenant_id}-{timestamp}-{rand6}
    -- Example: LAM-TOPUP-12-1719234567-a3f9b2
    -- type_short: TOPUP / SETUP / OUTLET
  tenant_id       INT NOT NULL,
  type            ENUM('topup_coin','setup_fee','outlet_activation') NOT NULL,
  amount          INT NOT NULL COMMENT 'IDR exact, fee tidak include',

  -- References (1 of 3 filled based on type)
  ref_bundle_id   INT NULL COMMENT 'saas_coin_bundles.id kalau type=topup_coin',
  ref_package_id  INT NULL COMMENT 'saas_packages.id kalau type=setup_fee',
  ref_outlet_id   INT NULL COMMENT 'outlets.id kalau type=outlet_activation',

  -- Midtrans response data
  midtrans_tx_id  VARCHAR(100) NULL COMMENT 'transaction_id dari Midtrans',
  payment_type    VARCHAR(30) NULL COMMENT 'qris / bank_transfer',
  va_bank         VARCHAR(20) NULL COMMENT 'bca / bni / bri / mandiri / permata',
  va_number       VARCHAR(50) NULL,
  qr_string       TEXT NULL COMMENT 'QRIS code data untuk render',

  -- Status
  status          ENUM('pending','paid','expired','failed','cancelled') DEFAULT 'pending',
  paid_at         DATETIME NULL,
  expires_at      DATETIME NOT NULL,
  raw_response    JSON NULL COMMENT 'Full Midtrans response untuk debugging',

  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_tenant_status (tenant_id, status, created_at),
  INDEX idx_status_expires (status, expires_at),
  INDEX idx_order (order_id),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE saas_billing_config (
  id          INT PRIMARY KEY AUTO_INCREMENT,
  key_name    VARCHAR(50) UNIQUE NOT NULL,
  value_text  TEXT,
  description TEXT,
  updated_by  INT NULL,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO saas_billing_config (key_name, value_text, description) VALUES
('midtrans_env',           'sandbox', 'sandbox / production'),
('midtrans_server_key',    '',        'Server key Midtrans (masked di UI)'),
('midtrans_client_key',    '',        'Client key Midtrans'),
('outlet_activation_fee',  '800000',  'Fee aktivasi outlet ke-2+ (IDR)'),
('payment_expiry_minutes', '15',      'Payment expire after N menit');
```

### Existing Table Alter

```sql
ALTER TABLE coin_ledger
  ADD COLUMN payment_id INT NULL AFTER ref_id,
  ADD INDEX idx_payment (payment_id);
```

`coin_ledger.payment_id` link ke `saas_payments.id` untuk audit trail saat top-up coin.

### Status Lifecycle

```
[pending] ─┬─ (webhook: settlement/capture) → [paid]      → trigger settlement
           ├─ (cron: expires_at < now)       → [expired]   → cleanup
           ├─ (tenant cancel)                → [cancelled]
           └─ (webhook: deny/failure)        → [failed]    → tenant retry
```

---

## 5. Settlement Logic per Use Case

Semua settlement wrapped dalam DB transaction untuk atomicity. Idempotent — webhook duplicate handled dengan check `status='paid'` di awal.

### 5.1 Top-up Coin

**Trigger:** `saas_payments.type = 'topup_coin' AND status = 'paid'`

```sql
BEGIN;

-- Lookup bundle
SELECT coin_didapat, bonus_pct, nama FROM saas_coin_bundles WHERE id = ?;

-- Add coin
UPDATE tenants SET coin_balance = coin_balance + ? WHERE id = ?;

-- Ledger
INSERT INTO coin_ledger
  (tenant_id, type, amount, feature_used, description, balance_after, payment_id)
VALUES
  (?, 'topup', ?, 'qris_midtrans',
   CONCAT('Top-up via QRIS — Paket ', ?, ' (', ?, '% bonus)'),
   ?, ?);

COMMIT;
```

**Side effects (post-commit):**
- Email confirmation (template `topup_success` — new)
- WA notif "Top-up berhasil! Saldo: Rp X"
- `SaNotifier::paymentReceived` → email ke SA dengan permission `payments.view`

### 5.2 Setup Fee

**Trigger:** `saas_payments.type = 'setup_fee' AND status = 'paid'`

```sql
BEGIN;

-- Get package referenced
SELECT setup_fee, nama FROM saas_packages WHERE id = ?;

-- Activate tenant
UPDATE tenants
  SET status = 'active'
  WHERE id = ? AND status IN ('pending_verification', 'trial');

COMMIT;
```

**Side effects:**
- Welcome email (template `welcome` — existing di EmailTemplate)
- WA notif "Akun Anda aktif! Login: https://lamasy.harpy.id/login"
- `SaNotifier::tenantActivated` (new event type)

### 5.3 Outlet Activation

**Convention:** Outlet pertama tenant (`is_main=1`) di-create dengan `status='trial'` (kena trial period bareng tenant). Outlet ke-2 dst di-create dengan `status='pending'` dari `/add-outlet.php` → tenant harus bayar activation fee untuk aktivasi. Implementation plan harus enforce ini.

**Trigger:** `saas_payments.type = 'outlet_activation' AND status = 'paid'`

```sql
BEGIN;

-- Verify outlet ownership (defense in depth)
SELECT tenant_id FROM outlets WHERE id = ?;
-- Match dengan saas_payments.tenant_id

-- Activate outlet
UPDATE outlets
  SET status = 'active', activated_at = NOW()
  WHERE id = ? AND tenant_id = ? AND status IN ('pending', 'trial');

COMMIT;
```

**Side effects:**
- Email "Outlet {nama} sudah aktif"
- WA notif
- `SaNotifier::outletActivated` (existing helper)

---

## 6. Tenant-Facing Pages

### 6.1 `/billing-checkout.php`

**URL patterns:**
- `?type=topup_coin&bundle_id=<id>`
- `?type=setup_fee&package_id=<id>` (default ke tenant.package_id)
- `?type=outlet_activation&outlet_id=<id>`

**Flow:**
1. `tenant_guard` authenticate
2. Validate inputs (bundle/package/outlet exists, outlet belongs to tenant)
3. Check existing pending payment untuk tenant + type — kalau ada masih valid, resume
4. Else: `MidtransClient::charge()` → INSERT `saas_payments`
5. Render checkout UI:
   - Order summary (apa yang dibeli + amount)
   - Tab QRIS: QR image (base64) + "Scan dengan banking app"
   - Tab VA: list bank (BCA/BNI/BRI/Mandiri/Permata) + VA number masing-masing
   - Countdown timer (expires_at)
   - Polling JS: GET `/api/billing-status?order_id=X` setiap 5 detik
   - Button "Cancel" → POST cancel endpoint

**Polling behavior:**
- 5 detik interval
- Stop polling kalau status berubah dari `pending`
- On `paid`: redirect ke `/billing-success?order_id=X`
- On `expired/failed/cancelled`: show error + button retry

### 6.2 `/api/billing-status.php`

GET endpoint, returns JSON:
```json
{
  "status": "pending|paid|expired|failed|cancelled",
  "expires_at": "2026-06-24T...",
  "seconds_remaining": 720
}
```

Tenant authentication required. Match `tenant_id` dengan session.

### 6.3 `/billing-success.php`

Render success page based on type:
- **topup_coin:** "Coin masuk! Saldo: Rp X" + link `/dashboard`
- **setup_fee:** "Akun aktif!" + link `/dashboard`
- **outlet_activation:** "Outlet aktif!" + link `/dashboard`

---

## 7. Webhook Endpoint

### `/api/midtrans-webhook.php`

**Method:** POST
**Path:** `https://lamasy.harpy.id/api/midtrans-webhook.php`
**Registered di:** Midtrans dashboard → Settings → Configuration → Payment Notification URL

**Logic:**

```php
<?php
// 1. Read raw body + parse JSON
$body = json_decode(file_get_contents('php://input'), true);
if (!$body || empty($body['order_id'])) {
    http_response_code(400);
    exit;
}

// 2. Get server_key dari saas_billing_config
$serverKey = BillingConfig::get('midtrans_server_key');

// 3. Verify SHA-512 signature
$expected = hash('sha512',
    $body['order_id'] .
    $body['status_code'] .
    $body['gross_amount'] .
    $serverKey
);
if (!hash_equals($expected, $body['signature_key'])) {
    http_response_code(401);
    ErrorLogger::log('midtrans_webhook_invalid_sig', json_encode($body));
    exit;
}

// 4. Lookup payment
$payment = $db->prepare("SELECT * FROM saas_payments WHERE order_id = ?");
$payment->execute([$body['order_id']]);
$row = $payment->fetch();
if (!$row) {
    http_response_code(404);
    exit;
}

// 5. Idempotency check
if ($row['status'] === 'paid') {
    http_response_code(200);  // already settled, OK
    exit;
}

// 6. Map Midtrans status
$transactionStatus = $body['transaction_status'];
$fraudStatus = $body['fraud_status'] ?? 'accept';

$newStatus = match(true) {
    $transactionStatus === 'capture' && $fraudStatus === 'accept' => 'paid',
    $transactionStatus === 'settlement'                            => 'paid',
    $transactionStatus === 'expire'                                => 'expired',
    in_array($transactionStatus, ['cancel', 'deny', 'failure'])    => 'failed',
    default                                                         => 'pending',
};

// 7. Update payment
$db->prepare("UPDATE saas_payments SET
    status = ?,
    paid_at = ?,
    midtrans_tx_id = ?,
    payment_type = ?,
    raw_response = ?
    WHERE id = ?")
   ->execute([
       $newStatus,
       $newStatus === 'paid' ? date('Y-m-d H:i:s') : null,
       $body['transaction_id'],
       $body['payment_type'],
       json_encode($body),
       $row['id']
   ]);

// 8. Settlement (kalau status berubah jadi paid)
if ($newStatus === 'paid' && $row['status'] !== 'paid') {
    PaymentSettler::settle($row['id']);
}

// 9. Return 200 OK
http_response_code(200);
echo json_encode(['ok' => true]);
```

---

## 8. Security

### Secret Management
- `midtrans_server_key` stored di `saas_billing_config`, masked di SA UI (show last 4 char)
- Never expose ke frontend
- Rotate-able dari `/superadmin/billing-config.php`

### Webhook Verification
- SHA-512 HMAC signature wajib (no signature → 401 reject)
- `hash_equals()` untuk timing-safe comparison

### Anti-Abuse
- Rate limit `/billing-checkout`: max 5 payment intents per tenant per 15 menit
- Tenant ownership enforced di every query (`tenant_id` match session)
- Amount immutable — server hitung dari `bundle_id` / `package_id` / config, frontend tidak boleh override

### Audit Trail
- `superadmin_logs` untuk SA actions (refund, manual override)
- `saas_payments.raw_response` simpan full Midtrans response untuk forensic

---

## 9. Testing Strategy

### Sandbox (Phase 1 testing)

Midtrans sandbox URL: `https://app.sandbox.midtrans.com/snap/v1/transactions`

| # | Scenario | Expected |
|---|----------|----------|
| T1 | Top-up coin via QRIS (sandbox simulator) | Coin masuk, ledger entry, email |
| T2 | Top-up coin via VA BCA | Same |
| T3 | Payment expired (15 menit lewat tanpa bayar) | Status='expired', cron pickup |
| T4 | Pay after expired | Settlement tetap jalan |
| T5 | Webhook duplicate (manual replay) | Idempotent, no double-credit |
| T6 | Webhook invalid signature | 401 reject, no settlement |
| T7 | Setup fee E2E | Tenant status='active', welcome email |
| T8 | Outlet activation E2E | Outlet status='active' |
| T9 | Concurrent payments same tenant | Different order_id, no conflict |
| T10 | Network error simulation | Graceful error, tenant retry |

### Production Smoke Test

- Real Rp 20.000 transaction (Paket 20 terkecil)
- Verify webhook fire
- Verify coin masuk
- Refund untuk test refund flow

---

## 10. Implementation Phasing

| Phase | Scope | Estimasi |
|-------|-------|----------|
| **1. Foundation** | Schema + MidtransClient + webhook endpoint + billing-config SA UI | 1 hari |
| **2. Top-up Coin** | Checkout page + polling + settlement + entry di /coin-info | 1 hari |
| **3. Setup Fee + Outlet Activation** | Settlement handlers + entry points | 0.5 hari |
| **4. SA Tracking + Refund** | Extend payments.php + refund button | 0.5 hari |
| **5. Cron + Cleanup** | Auto-expire + daily digest | 0.25 hari |

**Total: 3.25 hari kerja**

---

## 11. Rollout Plan

1. **Sandbox testing** (1-2 hari): all E2E test cases pass
2. **Production credentials**: SA input `midtrans_server_key` production, switch env
3. **Smoke test**: Rp 20K real topup → verify webhook + settlement
4. **Soft launch**: enable QRIS button untuk 1-2 tenant pilihan, monitor 1 minggu
5. **Full rollout**: enable all tenant, manual transfer fallback tetap available

---

## 12. Backward Compatibility

- **Manual top-up tetap jalan** via `/superadmin/clients.php` → tombol "+ Coin" (existing). LAMASY ops bisa override kalau Midtrans down.
- **Existing tenants** dengan trial / setup_fee paid: tidak perlu re-pay.
- **Migration data**: tidak ada — semua schema additive.

---

## 13. Error Handling

| Skenario | Handler |
|----------|---------|
| Payment expired | Cron `/cron/payment-cleanup.php` set status='expired'. Tenant retry. |
| Webhook fire late (paid setelah expired) | Settlement tetap jalan, fair untuk tenant. |
| Webhook duplicate | Idempotent check status='paid' → skip duplicate. |
| Settlement DB error | Return 200 ke Midtrans (avoid retry loop). Log error. Alert SA via email. SA manual fix. |
| Tenant cancel | Button "Cancel" → UPDATE status='cancelled'. Bisa create new. |
| Refund | SA UI: tombol "Refund" → Midtrans API → INSERT coin_ledger type='deduct' kalau topup_coin. Audit logged. |
| Midtrans API down | Graceful error message ke tenant: "Payment service sedang gangguan, coba beberapa menit lagi". Fallback ke manual transfer flow. |

---

## 14. Out of Scope (Phase 1)

- Recurring subscription billing — defer
- Customer-facing QRIS (B2C, per-tenant MID) — defer
- E-wallet (GoPay/OVO/Dana) — defer to Phase 2
- Credit card payment — defer
- Multi-currency — Indonesia IDR only
- Invoice PDF — bisa pakai existing `core/NotaFormatter.php` pattern later

---

## 15. Files Inventory

### New
- `core/MidtransClient.php`
- `core/PaymentSettler.php`
- `api/midtrans-webhook.php`
- `api/billing-status.php`
- `billing-checkout.php`
- `billing-success.php`
- `cron/payment-cleanup.php`
- `superadmin/billing-config.php`

### Modified
- `coin-info.php` — tombol "Top-up via QRIS/VA"
- `add-outlet.php` — redirect ke checkout untuk outlet ke-2+
- `superadmin/payments.php` — filter Midtrans + refund button
- `superadmin/superadmin_components.php` — sidebar link billing-config
- `register.php` / post-verify flow — redirect ke setup fee checkout

### DB Migrations
- `db/migrations/2026-06-24-qris-integration.sql` (NEW)
  - CREATE saas_payments
  - CREATE saas_billing_config + seed defaults
  - ALTER coin_ledger ADD payment_id

---

## 16. References

- Midtrans Snap docs: https://docs.midtrans.com/reference/getting-started-snap
- Midtrans webhook signature: https://docs.midtrans.com/reference/notification-webhooks
- Existing coin flow di `/superadmin/clients.php` action `topup`
- Existing Mailer template pattern di `core/Mailer.php` + `core/EmailTemplate.php`
- Existing `core/SaNotifier.php` untuk SA event routing

---

## 17. Success Criteria

- ✅ Tenant bisa top-up coin self-service via QRIS/VA tanpa nunggu SA approval
- ✅ Average top-up time: dari hours/days (manual) → < 5 menit (QRIS/VA)
- ✅ SA workload reduction: 100% top-up otomatis (zero manual approval kecuali edge case)
- ✅ Payment success rate ≥ 95% di production (sisanya: expired, customer abandon, etc.)
- ✅ Zero double-credit (idempotency works)
- ✅ Refund flow tested + working (1 real refund successful end-to-end)

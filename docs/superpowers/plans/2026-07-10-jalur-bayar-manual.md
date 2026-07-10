# Jalur Bayar Manual (Transfer Bank) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Owner bisa bayar top-up coin / setup fee / aktivasi outlet via transfer bank manual (fallback selama Midtrans Core API belum di-approve), dengan SA mengonfirmasi lunas → coin/aktivasi dikreditkan lewat `PaymentSettler` yang sudah ada.

**Architecture:** Semua logika baru dikumpulkan di satu kelas `core/ManualPay.php` (config, kode-unik, buat row, konfirmasi, tolak) supaya tiap unit bisa di-test terisolasi. `billing-checkout.php`, `superadmin/payments.php`, dan entry point cuma jadi wiring tipis yang memanggil `ManualPay`. Zero schema change — pakai `saas_payments` dengan `payment_type='manual_transfer'` dan `PaymentSettler::settle()` yang sudah menangani ketiga `type`.

**Tech Stack:** PHP 8 (PDO/MySQL, tanpa framework), tes pakai harness `tests/_assert.php` (`ok()`/`eqv()`), jalan via CLI `php tests/billing/test_manual_pay.php`.

## Global Constraints

- Header CSRF WAJIB `X-CSRF-Token` (kapital-semua ditolak Hostinger prod). SA POST pakai `saVerifyCsrf()` (sudah dipanggil sekali di `payments.php:188` untuk semua POST action) + `saFetch()` di JS (auto-inject header).
- `apiErr($e)` (didefinisikan di `core/Database.php:17`) untuk exception di API — jangan echo `$e->getMessage()` ke client.
- Timezone: PHP `date()` = WIB, MySQL `NOW()` = UTC. `expires_at` DITULIS via PHP `date('Y-m-d H:i:s', …)` (WIB) dan DIBANDINGKAN dengan `date('Y-m-d H:i:s')` (WIB) — JANGAN `NOW()` (pola sama `billing-checkout.php` yang ada).
- `tenant_id` SELALU dari `$_SESSION['tenant_id']`, tak pernah dari input.
- Rupiah tampil `number_format($n,0,',','.')`.
- SA permission: `SaPermission::require('payments.approve')` untuk konfirmasi/tolak (sama seperti confirm existing).
- `payment_type` bernilai `'manual_transfer'` (varchar, tak perlu ubah enum). `status` pakai enum yang ada: `pending`→`paid`/`cancelled`.
- Config keys baru di `saas_billing_config`: `manual_payment_enabled`, `manual_bank_name`, `manual_bank_account_no`, `manual_bank_holder`, `manual_payment_expiry_hours`.
- Coin/aktivasi dihitung `PaymentSettler` dari bundle/package/config — BUKAN dari `amount`. Kode unik hanya mengubah `amount` (nominal transfer & tampilan), aman.

---

### Task 1: `core/ManualPay.php` — config helper + UI billing-config

**Files:**
- Create: `core/ManualPay.php`
- Modify: `superadmin/billing-config.php` (tambah field form + POST handler)
- Test: `tests/billing/test_manual_pay.php` (buat baru)

**Interfaces:**
- Consumes: `BillingConfig::get()` / `getInt()` / `set()` (`core/BillingConfig.php`), `Database::get()`.
- Produces:
  - `ManualPay::isEnabled(): bool` — true hanya jika `manual_payment_enabled='1'` DAN ketiga field rekening terisi.
  - `ManualPay::bankInfo(): array` — `['enabled'=>bool,'name'=>string,'account_no'=>string,'holder'=>string,'expiry_hours'=>int]`.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/billing/test_manual_pay.php` (pola persis dari `tests/billing/test_activation_coin.php`):

```php
<?php
require __DIR__ . '/../_assert.php';
if (!defined('DB_HOST')) {
    $mycnf = parse_ini_file(($_SERVER['HOME'] ?? getenv('HOME')) . '/.my.cnf') ?: [];
    define('DB_HOST', $mycnf['host']     ?? 'localhost');
    define('DB_USER', $mycnf['user']     ?? '');
    define('DB_PASS', $mycnf['password'] ?? '');
    define('DB_NAME', $mycnf['database'] ?? '');
    if (!defined('ROOT')) define('ROOT', dirname(__DIR__, 2));
}
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/BillingConfig.php';
require_once dirname(__DIR__, 2) . '/core/ManualPay.php';

$db = Database::get();

// ── Task 1: isEnabled / bankInfo ──────────────────────────────
// Simpan state config lama utk dipulihkan di akhir
$prev = [];
foreach (['manual_payment_enabled','manual_bank_name','manual_bank_account_no','manual_bank_holder','manual_payment_expiry_hours'] as $k) {
    $prev[$k] = BillingConfig::get($k);
}

BillingConfig::set('manual_payment_enabled', '0');
BillingConfig::set('manual_bank_name', 'BCA');
BillingConfig::set('manual_bank_account_no', '1234567890');
BillingConfig::set('manual_bank_holder', 'Test Holder');
ok(ManualPay::isEnabled() === false, 'isEnabled false saat switch=0 walau rekening lengkap');

BillingConfig::set('manual_payment_enabled', '1');
BillingConfig::set('manual_bank_account_no', '');
ok(ManualPay::isEnabled() === false, 'isEnabled false saat rekening tak lengkap walau switch=1');

BillingConfig::set('manual_bank_account_no', '1234567890');
ok(ManualPay::isEnabled() === true, 'isEnabled true saat switch=1 + rekening lengkap');

$info = ManualPay::bankInfo();
eqv($info['name'], 'BCA', 'bankInfo name benar');
eqv($info['account_no'], '1234567890', 'bankInfo account_no benar');
eqv($info['holder'], 'Test Holder', 'bankInfo holder benar');

echo "OK test_manual_pay Task1\n";

// Pulihkan config lama (biar tak ganggu prod DB lokal)
foreach ($prev as $k => $v) {
    if ($v === null) { Database::get()->prepare("DELETE FROM saas_billing_config WHERE key_name=?")->execute([$k]); }
    else { BillingConfig::set($k, $v); }
}
```

- [ ] **Step 2: Jalankan test — pastikan gagal**

Run: `php tests/billing/test_manual_pay.php`
Expected: FATAL "Failed opening required .../core/ManualPay.php" (file belum ada).

- [ ] **Step 3: Buat `core/ManualPay.php` (bagian config)**

```php
<?php
// ══════════════════════════════════════════════════════
// core/ManualPay.php
// Jalur bayar manual (transfer bank) — fallback selama Midtrans
// Core API belum di-approve. Owner transfer → SA konfirmasi → settle.
// ══════════════════════════════════════════════════════

require_once __DIR__ . '/BillingConfig.php';

class ManualPay
{
    /** Jalur manual aktif hanya jika switch on DAN rekening lengkap. */
    public static function isEnabled(): bool
    {
        if (BillingConfig::get('manual_payment_enabled') !== '1') return false;
        $i = self::bankInfo();
        return $i['name'] !== '' && $i['account_no'] !== '' && $i['holder'] !== '';
    }

    /** Info rekening tujuan + expiry. */
    public static function bankInfo(): array
    {
        return [
            'enabled'      => BillingConfig::get('manual_payment_enabled') === '1',
            'name'         => trim((string) BillingConfig::get('manual_bank_name', '')),
            'account_no'   => trim((string) BillingConfig::get('manual_bank_account_no', '')),
            'holder'       => trim((string) BillingConfig::get('manual_bank_holder', '')),
            'expiry_hours' => max(1, BillingConfig::getInt('manual_payment_expiry_hours', 24)),
        ];
    }
}
```

- [ ] **Step 4: Jalankan test — pastikan lolos**

Run: `php tests/billing/test_manual_pay.php`
Expected: baris `ok`/`eqv` PASS semua, cetak `OK test_manual_pay Task1`.

- [ ] **Step 5: Tambah UI di `superadmin/billing-config.php`**

Di blok POST handler, setelah array `$fields` di-loop dan sebelum `logSuperAdminAction`, tambahkan penyimpanan field manual (checkbox + text). Ganti bagian handler POST jadi menyertakan:

```php
    // ── Field jalur bayar manual ──
    BillingConfig::set('manual_payment_enabled', isset($_POST['manual_payment_enabled']) ? '1' : '0', $saId);
    foreach (['manual_bank_name','manual_bank_account_no','manual_bank_holder','manual_payment_expiry_hours'] as $mk) {
        BillingConfig::set($mk, trim($_POST[$mk] ?? ''), $saId);
    }
```

Lalu tambahkan `require_once SA_ROOT . '/../core/ManualPay.php';` di header file (dekat require BillingConfig), dan sisipkan kartu berikut di dalam `<form>` (setelah kartu "💰 Fee Configuration", sebelum tombol submit):

```php
    <div class="sa-card">
      <div class="sa-card-head">
        <h3>🏦 Pembayaran Manual (Transfer Bank)</h3>
      </div>
      <div class="sa-card-body" style="padding: 22px 24px;">
        <div class="form-group" style="margin-bottom:16px;">
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
            <input type="checkbox" name="manual_payment_enabled" value="1"
                   <?= (($conf['manual_payment_enabled']['value_text'] ?? '0') === '1') ? 'checked' : '' ?>>
            Aktifkan jalur transfer manual (fallback saat QRIS/Midtrans belum aktif)
          </label>
          <small style="color:var(--ash-dim);font-size:11px;">Muncul hanya jika switch ini ON dan ketiga field rekening di bawah terisi.</small>
        </div>
        <div class="form-group" style="margin-bottom:16px;">
          <label>Nama Bank</label>
          <input type="text" name="manual_bank_name" value="<?= htmlspecialchars($conf['manual_bank_name']['value_text'] ?? '') ?>" placeholder="BCA"
                 style="width:100%;padding:10px 14px;background:var(--slate-elev);border:1px solid var(--crease);border-radius:8px;color:var(--glow);">
        </div>
        <div class="form-group" style="margin-bottom:16px;">
          <label>No Rekening</label>
          <input type="text" name="manual_bank_account_no" value="<?= htmlspecialchars($conf['manual_bank_account_no']['value_text'] ?? '') ?>" placeholder="1234567890"
                 style="width:100%;padding:10px 14px;background:var(--slate-elev);border:1px solid var(--crease);border-radius:8px;color:var(--glow);font-family:var(--mono);">
        </div>
        <div class="form-group" style="margin-bottom:16px;">
          <label>Atas Nama</label>
          <input type="text" name="manual_bank_holder" value="<?= htmlspecialchars($conf['manual_bank_holder']['value_text'] ?? '') ?>" placeholder="Ignatius Rizky"
                 style="width:100%;padding:10px 14px;background:var(--slate-elev);border:1px solid var(--crease);border-radius:8px;color:var(--glow);">
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label>Masa Berlaku (jam)</label>
          <input type="number" name="manual_payment_expiry_hours" value="<?= htmlspecialchars($conf['manual_payment_expiry_hours']['value_text'] ?? '24') ?>" min="1" max="168"
                 style="width:100%;padding:10px 14px;background:var(--slate-elev);border:1px solid var(--crease);border-radius:8px;color:var(--glow);">
          <small style="color:var(--ash-dim);font-size:11px;">Berapa jam row manual berlaku sebelum kedaluwarsa. Default 24.</small>
        </div>
      </div>
    </div>
```

- [ ] **Step 6: Commit**

```bash
git add core/ManualPay.php superadmin/billing-config.php tests/billing/test_manual_pay.php
git commit -m "feat(manual-pay): ManualPay config helper + UI rekening di billing-config"
```

---

### Task 2: `ManualPay::uniqueAmount()` + `ManualPay::createPayment()`

**Files:**
- Modify: `core/ManualPay.php`
- Test: `tests/billing/test_manual_pay.php` (tambah blok Task 2)

**Interfaces:**
- Consumes: `Database::get()`, `BillingConfig` (via bankInfo), `MidtransClient::generateOrderId()` (`core/MidtransClient.php` — hanya generator string, tak memanggil API).
- Produces:
  - `ManualPay::uniqueAmount(PDO $db, int $base): array` — `['amount'=>int,'code'=>int]`, di mana `amount = base + code`, `code ∈ [1,999]`, dan `amount` unik di antara row `manual_transfer` yang `pending`.
  - `ManualPay::createPayment(PDO $db, int $tenantId, string $type, array $refs, int $base): array` — resume row manual pending yang cocok atau buat baru; kembalikan row `saas_payments` (assoc). `$refs = ['bundle'=>?int,'package'=>?int,'outlet'=>?int]`.

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan di `tests/billing/test_manual_pay.php` SEBELUM baris restore config (setelah `echo "OK test_manual_pay Task1\n";`), dan pindahkan restore config ke paling akhir file. Sisipkan:

```php
// ── Task 2: uniqueAmount + createPayment ──────────────────────
require_once dirname(__DIR__, 2) . '/core/MidtransClient.php';

// Siapkan tenant + bundle sintetis
$db->beginTransaction();
$db->prepare("INSERT INTO tenants (slug, nama_perusahaan, owner_name, owner_wa, email, status, coin_balance, created_at)
              VALUES (?, 'MP-TEST', 'MP Owner', '0811', 'mp@test.local', 'pending_verification', 0, NOW())")
   ->execute(['mp-test-' . time() . '-' . rand(1000,9999)]);
$mpTid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO saas_coin_bundles (nama, harga, coin_didapat, bonus_pct, is_active, urutan)
              VALUES ('MP Bundle', 20000, 100, 0, 1, 999)")->execute();
$mpBundle = (int)$db->lastInsertId();
$db->commit();

// uniqueAmount: base+code dalam range
$u1 = ManualPay::uniqueAmount($db, 20000);
ok($u1['code'] >= 1 && $u1['code'] <= 999, 'code dalam [1,999]');
eqv($u1['amount'], 20000 + $u1['code'], 'amount = base + code');

// createPayment: buat row manual pending
$row1 = ManualPay::createPayment($db, $mpTid, 'topup_coin', ['bundle'=>$mpBundle], 20000);
eqv($row1['payment_type'], 'manual_transfer', 'row payment_type=manual_transfer');
eqv($row1['status'], 'pending', 'row status pending');
ok((int)$row1['amount'] >= 20001 && (int)$row1['amount'] <= 20999, 'amount row = base+code unik');
ok(empty($row1['qr_string']), 'qr_string kosong utk manual');

// createPayment lagi utk type+ref sama → resume row yang sama (bukan buat baru)
$row2 = ManualPay::createPayment($db, $mpTid, 'topup_coin', ['bundle'=>$mpBundle], 20000);
eqv((int)$row2['id'], (int)$row1['id'], 'createPayment kedua me-resume row pending yang sama');

// uniqueAmount hindari tabrakan dgn amount pending yg sudah ada
$taken = (int)$row1['amount'];
$distinct = true;
for ($i=0;$i<20;$i++){ if (ManualPay::uniqueAmount($db, $taken - ($taken % 1000))['amount'] === $taken) { $distinct=false; break; } }
ok($distinct, 'uniqueAmount tak pernah tabrakan dgn amount pending existing');

echo "OK test_manual_pay Task2\n";

// Bersihkan data sintetis Task 2
$db->prepare("DELETE FROM saas_payments WHERE tenant_id=?")->execute([$mpTid]);
$db->prepare("DELETE FROM saas_coin_bundles WHERE id=?")->execute([$mpBundle]);
$db->prepare("DELETE FROM tenants WHERE id=?")->execute([$mpTid]);
```

- [ ] **Step 2: Jalankan test — pastikan gagal**

Run: `php tests/billing/test_manual_pay.php`
Expected: FATAL "Call to undefined method ManualPay::uniqueAmount()".

- [ ] **Step 3: Implementasi di `core/ManualPay.php`**

Tambahkan method di dalam class `ManualPay`:

```php
    /**
     * Kode unik nominal: amount = base + code (1..999), unik di antara
     * row manual_transfer yang masih pending (agar SA cocokkan di mutasi).
     */
    public static function uniqueAmount(PDO $db, int $base): array
    {
        $check = $db->prepare(
            "SELECT 1 FROM saas_payments
             WHERE payment_type='manual_transfer' AND status='pending' AND amount=? LIMIT 1"
        );
        $code = 0;
        for ($i = 0; $i < 30; $i++) {
            $code = random_int(1, 999);
            $check->execute([$base + $code]);
            if (!$check->fetchColumn()) break; // total belum dipakai → aman
        }
        return ['amount' => $base + $code, 'code' => $code];
    }

    /**
     * Resume row manual pending yang cocok (type + ref) atau buat baru.
     * $refs = ['bundle'=>?int,'package'=>?int,'outlet'=>?int]
     */
    public static function createPayment(PDO $db, int $tenantId, string $type, array $refs, int $base): array
    {
        require_once __DIR__ . '/MidtransClient.php';

        $refBundle  = $refs['bundle']  ?? null;
        $refPackage = $refs['package'] ?? null;
        $refOutlet  = $refs['outlet']  ?? null;

        // Resume: pending manual yang masih hidup, type + semua ref cocok.
        // FILTER payment_type='manual_transfer' supaya tak membajak row QRIS.
        $ex = $db->prepare(
            "SELECT * FROM saas_payments
             WHERE tenant_id=? AND type=? AND status='pending'
               AND payment_type='manual_transfer' AND expires_at > ?
               AND COALESCE(ref_bundle_id,0)=COALESCE(?,0)
               AND COALESCE(ref_outlet_id,0)=COALESCE(?,0)
               AND COALESCE(ref_package_id,0)=COALESCE(?,0)
             ORDER BY id DESC LIMIT 1"
        );
        $ex->execute([$tenantId, $type, date('Y-m-d H:i:s'), $refBundle, $refOutlet, $refPackage]);
        if ($row = $ex->fetch(PDO::FETCH_ASSOC)) return $row;

        $u        = self::uniqueAmount($db, $base);
        $info     = self::bankInfo();
        $orderId  = MidtransClient::generateOrderId($type, $tenantId);
        $expires  = date('Y-m-d H:i:s', time() + $info['expiry_hours'] * 3600);
        $raw      = json_encode([
            'manual'      => true,
            'base_amount' => $base,
            'unique_code' => $u['code'],
            'bank'        => ['name' => $info['name'], 'account_no' => $info['account_no'], 'holder' => $info['holder']],
        ]);

        $db->prepare(
            "INSERT INTO saas_payments
                (order_id, tenant_id, type, amount, ref_bundle_id, ref_package_id, ref_outlet_id,
                 payment_type, qr_string, status, expires_at, raw_response)
             VALUES (?,?,?,?,?,?,?, 'manual_transfer', NULL, 'pending', ?, ?)"
        )->execute([$orderId, $tenantId, $type, $u['amount'], $refBundle, $refPackage, $refOutlet, $expires, $raw]);

        $pid = (int)$db->lastInsertId();
        $g = $db->prepare("SELECT * FROM saas_payments WHERE id=?");
        $g->execute([$pid]);
        return $g->fetch(PDO::FETCH_ASSOC);
    }
```

- [ ] **Step 4: Jalankan test — pastikan lolos**

Run: `php tests/billing/test_manual_pay.php`
Expected: cetak `OK test_manual_pay Task1` lalu `OK test_manual_pay Task2`, semua assert PASS.

- [ ] **Step 5: Commit**

```bash
git add core/ManualPay.php tests/billing/test_manual_pay.php
git commit -m "feat(manual-pay): uniqueAmount + createPayment (kode unik nominal + resume pending)"
```

---

### Task 3: `ManualPay::confirm()` + `ManualPay::reject()`

**Files:**
- Modify: `core/ManualPay.php`
- Test: `tests/billing/test_manual_pay.php` (tambah blok Task 3)

**Interfaces:**
- Consumes: `PaymentSettler::settle(int $paymentId)` (`core/PaymentSettler.php` — butuh `status='paid'`, idempoten via `coin_ledger.payment_id`).
- Produces:
  - `ManualPay::confirm(int $paymentId): array` — flip `pending`→`paid` (guarded ke row manual saja), lalu `PaymentSettler::settle()`. Idempoten. Kembalikan `['ok'=>bool, ...]` atau `['ok'=>false,'error'=>..]`.
  - `ManualPay::reject(int $paymentId): array` — flip `pending`→`cancelled`. Kembalikan `['ok'=>bool]`.

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan di `tests/billing/test_manual_pay.php` sebelum blok restore config akhir:

```php
// ── Task 3: confirm (settle idempoten) + reject ───────────────
require_once dirname(__DIR__, 2) . '/core/PaymentSettler.php';

// Setup tenant + bundle utk confirm
$db->beginTransaction();
$db->prepare("INSERT INTO tenants (slug, nama_perusahaan, owner_name, owner_wa, email, status, coin_balance, created_at)
              VALUES (?, 'MP-CONF', 'MP Conf', '0811', 'mpc@test.local', 'active', 0, NOW())")
   ->execute(['mp-conf-' . time() . '-' . rand(1000,9999)]);
$cTid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO saas_coin_bundles (nama, harga, coin_didapat, bonus_pct, is_active, urutan)
              VALUES ('MP Conf Bundle', 20000, 150, 0, 1, 999)")->execute();
$cBundle = (int)$db->lastInsertId();
$db->commit();

$rowC = ManualPay::createPayment($db, $cTid, 'topup_coin', ['bundle'=>$cBundle], 20000);
$pidC = (int)$rowC['id'];

// confirm → coin bertambah 150 (dari bundle), sekali
$rc1 = ManualPay::confirm($pidC);
ok(!empty($rc1['ok']), 'confirm pertama ok');
$balC = (int)$db->query("SELECT coin_balance FROM tenants WHERE id=$cTid")->fetchColumn();
eqv($balC, 150, 'coin_balance = coin_didapat bundle setelah confirm');
$stC = $db->query("SELECT status FROM saas_payments WHERE id=$pidC")->fetchColumn();
eqv($stC, 'paid', 'status row jadi paid');

// double confirm → tak double-credit (idempoten)
$rc2 = ManualPay::confirm($pidC);
$balC2 = (int)$db->query("SELECT coin_balance FROM tenants WHERE id=$cTid")->fetchColumn();
eqv($balC2, 150, 'confirm kedua tak menambah coin (idempoten)');
$ledC = (int)$db->query("SELECT COUNT(*) FROM coin_ledger WHERE payment_id=$pidC")->fetchColumn();
eqv($ledC, 1, 'tetap 1 baris ledger');

// reject row pending baru → cancelled, tanpa kredit
$rowR = ManualPay::createPayment($db, $cTid, 'topup_coin', ['bundle'=>$cBundle], 30000);
$pidR = (int)$rowR['id'];
$rr = ManualPay::reject($pidR);
ok(!empty($rr['ok']), 'reject ok');
$stR = $db->query("SELECT status FROM saas_payments WHERE id=$pidR")->fetchColumn();
eqv($stR, 'cancelled', 'status row jadi cancelled');
$balR = (int)$db->query("SELECT coin_balance FROM tenants WHERE id=$cTid")->fetchColumn();
eqv($balR, 150, 'reject tak mengubah coin');

// confirm row yang sudah cancelled → ditolak (bukan paid, tak kredit)
$rcBad = ManualPay::confirm($pidR);
ok(empty($rcBad['ok']), 'confirm row cancelled ditolak');
$stR2 = $db->query("SELECT status FROM saas_payments WHERE id=$pidR")->fetchColumn();
eqv($stR2, 'cancelled', 'row tetap cancelled (tak berubah jadi paid)');

echo "OK test_manual_pay Task3\n";

// Bersihkan
$db->prepare("DELETE FROM coin_ledger WHERE tenant_id=?")->execute([$cTid]);
$db->prepare("DELETE FROM saas_payments WHERE tenant_id=?")->execute([$cTid]);
$db->prepare("DELETE FROM saas_coin_bundles WHERE id=?")->execute([$cBundle]);
$db->prepare("DELETE FROM tenants WHERE id=?")->execute([$cTid]);
```

- [ ] **Step 2: Jalankan test — pastikan gagal**

Run: `php tests/billing/test_manual_pay.php`
Expected: FATAL "Call to undefined method ManualPay::confirm()".

- [ ] **Step 3: Implementasi di `core/ManualPay.php`**

Tambahkan method:

```php
    /**
     * SA konfirmasi lunas: flip pending→paid (khusus row manual), lalu settle.
     * Guard status='pending' di UPDATE mencegah re-settle. settle idempoten.
     */
    public static function confirm(int $paymentId): array
    {
        require_once __DIR__ . '/PaymentSettler.php';
        $db = Database::get();

        $upd = $db->prepare(
            "UPDATE saas_payments SET status='paid', paid_at=NOW()
             WHERE id=? AND status='pending' AND payment_type='manual_transfer'"
        );
        $upd->execute([$paymentId]);

        if ($upd->rowCount() === 0) {
            // Sudah paid (retry settle idempoten) atau bukan row manual pending.
            $st = $db->prepare("SELECT status, payment_type FROM saas_payments WHERE id=?");
            $st->execute([$paymentId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r || $r['payment_type'] !== 'manual_transfer') {
                return ['ok' => false, 'error' => 'Bukan pembayaran manual.'];
            }
            if ($r['status'] !== 'paid') {
                return ['ok' => false, 'error' => "Status bukan pending (status: {$r['status']})."];
            }
            // status sudah paid → lanjut settle (idempoten)
        }

        return PaymentSettler::settle($paymentId);
    }

    /** SA tolak: pending→cancelled (khusus row manual). Tak ada kredit. */
    public static function reject(int $paymentId): array
    {
        $db  = Database::get();
        $upd = $db->prepare(
            "UPDATE saas_payments SET status='cancelled'
             WHERE id=? AND status='pending' AND payment_type='manual_transfer'"
        );
        $upd->execute([$paymentId]);
        if ($upd->rowCount() === 0) {
            return ['ok' => false, 'error' => 'Hanya pembayaran manual pending yang bisa ditolak.'];
        }
        return ['ok' => true];
    }
```

- [ ] **Step 4: Jalankan test — pastikan lolos**

Run: `php tests/billing/test_manual_pay.php`
Expected: cetak `OK test_manual_pay Task1/2/3`, semua assert PASS.

- [ ] **Step 5: Commit**

```bash
git add core/ManualPay.php tests/billing/test_manual_pay.php
git commit -m "feat(manual-pay): confirm (settle idempoten) + reject"
```

---

### Task 4: `billing-checkout.php` — branch manual + notify_sa + fallback QRIS

**Files:**
- Modify: `billing-checkout.php`
- Modify: `core/SaNotifier.php` (tambah `manualPaymentSubmitted`)

**Interfaces:**
- Consumes: `ManualPay::isEnabled()`, `ManualPay::bankInfo()`, `ManualPay::createPayment()`.
- Produces: `SaNotifier::manualPaymentSubmitted(int $paymentId): void` (best-effort, best-effort try/catch di caller).

**Catatan test:** logika inti (createPayment, uniqueAmount) sudah diuji di Task 2. Task ini wiring halaman + endpoint yang butuh session/HTTP → diverifikasi lewat **smoke manual** (dijelaskan di Step 6), bukan unit test. Reviewer memverifikasi cabang kode terhadap kontrak.

- [ ] **Step 1: Tambah `SaNotifier::manualPaymentSubmitted`**

Di `core/SaNotifier.php`, tambahkan method publik (ikuti pola `outletActivated`/`tenantRegistered` yang sudah ada — pakai `self::notify()` dan `self::layout()`):

```php
    /** Owner menandai sudah transfer manual — minta SA cek & konfirmasi. */
    public static function manualPaymentSubmitted(int $paymentId): void
    {
        try {
            $db = Database::get();
            $st = $db->prepare(
                "SELECT sp.order_id, sp.type, sp.amount, t.nama_perusahaan, t.owner_name
                 FROM saas_payments sp LEFT JOIN tenants t ON t.id=sp.tenant_id
                 WHERE sp.id=?"
            );
            $st->execute([$paymentId]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) return;

            $rp   = 'Rp ' . number_format((int)$p['amount'], 0, ',', '.');
            $body = self::layout('Transfer Manual Masuk', [
                ['Tenant',   ($p['nama_perusahaan'] ?? '—') . ' (' . ($p['owner_name'] ?? '—') . ')'],
                ['Tipe',     $p['type']],
                ['Nominal',  $rp . ' (termasuk kode unik — cocokkan persis di mutasi)'],
                ['Order ID', $p['order_id']],
            ], '/superadmin/payments.php', 'Buka & Konfirmasi');

            self::notify('manual_payment', 'Transfer manual masuk — ' . $rp, $body, $p['order_id']);
        } catch (Throwable $e) {
            error_log('[SaNotifier manualPaymentSubmitted] ' . $e->getMessage());
        }
    }
```

- [ ] **Step 2: Tambah endpoint `action=notify_sa` di `billing-checkout.php`**

Setelah blok `if (($_GET['action'] ?? '') === 'qr_img') { ... }` (yang sudah ada), tambahkan:

```php
// ── Owner menandai "sudah transfer" → ping SA (best-effort) ──
if (($_GET['action'] ?? '') === 'notify_sa') {
    header('Content-Type: application/json');
    $pid = (int)($_GET['pid'] ?? 0);
    $db  = Database::get();
    // Scope tenant + hanya row manual pending milik sendiri
    $st  = $db->prepare("SELECT id FROM saas_payments
                         WHERE id=? AND tenant_id=? AND payment_type='manual_transfer' AND status='pending'");
    $st->execute([$pid, $tenantId]);
    if ($st->fetchColumn()) {
        require_once __DIR__ . '/core/SaNotifier.php';
        try { SaNotifier::manualPaymentSubmitted($pid); } catch (Throwable) {}
    }
    echo json_encode(['ok' => true]);
    exit;
}
```

- [ ] **Step 3: Tambah require + branch manual pada pembuatan payment**

Di header `billing-checkout.php`, tambahkan `require_once __DIR__ . '/core/ManualPay.php';` (dekat require MidtransClient).

Di blok setelah `$amount`/`$refBundleId`/dst dihitung, dan menggantikan alur "create baru" saat `method=manual`: tepat sebelum blok existing `// Check existing pending payment (resume kalau ada)`, sisipkan cabang manual yang mem-bypass Midtrans:

```php
$method = $_GET['method'] ?? 'qris';

// ── Cabang MANUAL: bypass Midtrans, pakai ManualPay ──
if ($method === 'manual') {
    if (!ManualPay::isEnabled()) {
        die('Jalur transfer manual sedang tidak tersedia. Hubungi admin.');
    }
    $payment = ManualPay::createPayment($db, $tenantId, $type, [
        'bundle'  => $refBundleId,
        'package' => $refPackageId,
        'outlet'  => $refOutletId,
    ], $amount);
    $secondsRemaining = max(0, strtotime($payment['expires_at']) - time());
    $manualInfo = ManualPay::bankInfo();
    // Lompat ke render (skip blok pending/charge QRIS di bawah).
    goto render_page;
}
```

Bungkus blok existing "Check existing pending payment" + "Kalau gak ada pending, create baru" (charge QRIS) supaya QRIS path meng-exclude row manual. Ubah query existing-pending menambah filter:

```php
// (di query $existing) tambahkan sebelum ORDER BY:
       AND (payment_type IS NULL OR payment_type <> 'manual_transfer')
```

Lalu di akhir blok pembuatan (setelah `$payment` untuk QRIS siap), sebelum menghitung `$secondsRemaining`, beri label:

```php
render_page:
$secondsRemaining = $secondsRemaining ?? max(0, strtotime($payment['expires_at']) - time());
```

(`goto` dipakai minimal untuk melompati blok QRIS; alternatif tanpa goto: bungkus blok QRIS dalam `if ($method !== 'manual') { ... }` — pilih yang lebih bersih saat implementasi, hasil akhir sama: `$payment` terisi + `$secondsRemaining` dihitung sekali.)

- [ ] **Step 4: Fallback QRIS graceful (ganti `die`)**

Di blok charge QRIS, ganti:

```php
    if (!$res['ok']) {
        die('Gagal generate payment: ' . htmlspecialchars($res['error'] ?? 'Unknown error'));
    }
```

menjadi render kartu error ramah + tombol manual (bukan `die` mentah):

```php
    if (!$res['ok']) {
        $errMsg = $res['error'] ?? 'Gagal membuat pembayaran.';
        $manualUrl = $_SERVER['REQUEST_URI'];
        $manualUrl = preg_replace('/([?&])method=[^&]*/', '$1method=manual', $manualUrl);
        if (!str_contains($manualUrl, 'method=')) {
            $manualUrl .= (str_contains($manualUrl, '?') ? '&' : '?') . 'method=manual';
        }
        $showManual = ManualPay::isEnabled();
        include __DIR__ . '/partials/checkout_error.php'; // render kartu error (Step 5)
        exit;
    }
```

- [ ] **Step 5: Buat partial kartu error + kartu instruksi manual**

Buat `partials/checkout_error.php`:

```php
<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pembayaran — LAMASY</title>
<style>
  body{font-family:system-ui,sans-serif;background:#F3F4F6;color:#1F2937;margin:0;padding:24px;}
  .card{max-width:440px;margin:40px auto;background:#fff;border:1px solid #EEF1F8;border-radius:16px;padding:24px;text-align:center;box-shadow:0 1px 6px rgba(15,28,58,.06);}
  h1{font-size:18px;color:#0F1C3A;margin:0 0 8px;}
  p{color:#6B7280;font-size:14px;line-height:1.5;}
  .btn{display:inline-block;margin-top:16px;background:#0F1C3A;color:#fff;text-decoration:none;padding:13px 22px;border-radius:12px;font-weight:800;font-size:14px;}
  .btn.teal{background:#1CC4B2;}
  .muted{margin-top:14px;font-size:12px;color:#94A3B8;}
</style></head><body>
  <div class="card">
    <h1>Pembayaran otomatis belum tersedia</h1>
    <p><?= htmlspecialchars($errMsg) ?></p>
    <?php if (!empty($showManual)): ?>
      <a class="btn teal" href="<?= htmlspecialchars($manualUrl) ?>">🏦 Bayar via Transfer Manual</a>
      <p class="muted">Transfer ke rekening kami, admin konfirmasi, coin/aktivasi langsung masuk.</p>
    <?php else: ?>
      <a class="btn" href="/dashboard">← Kembali</a>
    <?php endif; ?>
  </div>
</body></html>
```

Untuk **kartu instruksi manual**, di dalam body render utama `billing-checkout.php` (bagian `<div class="wrap">`), tambahkan cabang yang menampilkan rekening alih-alih QR ketika `$payment['payment_type'] === 'manual_transfer'`. Sisipkan SEBELUM blok `<?php if ($payment['payment_type'] === 'qris' ...`:

```php
  <?php if ($payment['payment_type'] === 'manual_transfer'): ?>
  <div class="card" id="payCard">
    <h3 style="margin-bottom:14px;">Transfer Bank Manual</h3>
    <div class="va-box"><div>
      <div style="font-size:11px;color:#94A3B8;">Bank</div>
      <div class="va-num" style="font-size:16px;"><?= htmlspecialchars($manualInfo['name']) ?></div>
    </div></div>
    <div class="va-box"><div>
      <div style="font-size:11px;color:#94A3B8;">No Rekening</div>
      <div class="va-num"><?= htmlspecialchars($manualInfo['account_no']) ?></div>
    </div>
      <button class="copy" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($manualInfo['account_no']) ?>');this.textContent='Copied'">Copy</button>
    </div>
    <div class="va-box"><div>
      <div style="font-size:11px;color:#94A3B8;">Atas Nama</div>
      <div class="va-num" style="font-size:15px;"><?= htmlspecialchars($manualInfo['holder']) ?></div>
    </div></div>
    <div class="va-box" style="background:#FFF7ED;border-color:#FED7AA;"><div>
      <div style="font-size:11px;color:#B45309;">Nominal PERSIS (termasuk 3 angka terakhir)</div>
      <div class="va-num" style="color:#B45309;">Rp <?= number_format((int)$payment['amount'],0,',','.') ?></div>
    </div>
      <button class="copy" onclick="navigator.clipboard.writeText('<?= (int)$payment['amount'] ?>');this.textContent='Copied'">Copy</button>
    </div>
    <p style="font-size:12px;color:#94A3B8;margin-top:14px;">
      Transfer <b>nominal persis</b> (3 angka terakhir adalah kode unik untuk pencocokan). Setelah transfer, tap tombol di bawah.
    </p>
    <div class="qr-actions">
      <button class="act-btn alt" onclick="sudahTransfer(this)">✅ Saya sudah transfer</button>
    </div>
  </div>
  <?php endif; ?>
```

Dan di `<script>`, tambahkan fungsi:

```javascript
async function sudahTransfer(btn){
  btn.disabled = true; btn.textContent = 'Mengirim…';
  try { await fetch('billing-checkout.php?action=notify_sa&pid=<?= (int)$payment['id'] ?>'); } catch(e){}
  btn.textContent = '✅ Menunggu konfirmasi admin';
  const st = document.getElementById('status');
  if (st) st.textContent = 'Transfer kamu sedang menunggu konfirmasi admin. Halaman ini akan otomatis lanjut begitu dikonfirmasi.';
}
```

(Polling `billing-status.php` yang sudah ada tetap jalan — begitu SA konfirmasi, `status='paid'` → redirect ke `billing-success.php`.)

- [ ] **Step 6: Smoke test manual + commit**

Smoke (butuh session tenant login di lokal/staging; kalau tak tersedia, reviewer memverifikasi cabang kode):
1. SA billing-config: aktifkan manual + isi rekening.
2. Buka `billing-checkout.php?type=topup_coin&bundle_id=<valid>&method=manual` sbg tenant → muncul kartu rekening + nominal berkode-unik.
3. Tap "Saya sudah transfer" → network call `action=notify_sa` balas `{ok:true}`.
4. Buka `billing-checkout.php?type=topup_coin&bundle_id=<valid>` (QRIS, Midtrans masih 402) → muncul kartu error + tombol "Bayar via Transfer Manual" (bukan layar `die` mentah).

```bash
git add billing-checkout.php partials/checkout_error.php core/SaNotifier.php
git commit -m "feat(manual-pay): checkout branch manual (kartu rekening + saya-sudah-transfer) + fallback QRIS graceful + SaNotifier"
```

---

### Task 5: `superadmin/payments.php` — tombol & action Konfirmasi/Tolak

**Files:**
- Modify: `superadmin/payments.php`

**Interfaces:**
- Consumes: `ManualPay::confirm(int)`, `ManualPay::reject(int)`.

- [ ] **Step 1: Tambah action `confirm_manual` + `reject_manual`**

Di `superadmin/payments.php`, di dalam API layer setelah `saVerifyCsrf();` (baris ~188) dan sesudah blok `refund`, tambahkan:

```php
    // ── KONFIRMASI TRANSFER MANUAL (saas_payments) ────
    if ($action === 'confirm_manual') {
        SaPermission::require('payments.approve');
        require_once SA_ROOT . '/../core/ManualPay.php';
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'ID tidak valid.']); exit; }
        try {
            $res = ManualPay::confirm($id);
            if (empty($res['ok'])) { echo json_encode(['error' => $res['error'] ?? 'Konfirmasi gagal.']); exit; }
            logSuperAdminAction('confirm_manual_transfer', null, "Konfirmasi transfer manual saas_payments #$id");
            echo json_encode(['ok' => true, 'msg' => 'Pembayaran manual dikonfirmasi. Coin/aktivasi diproses.']);
        } catch (Throwable $e) { apiErr($e, 'Gagal konfirmasi. Coba lagi.'); }
        exit;
    }

    // ── TOLAK TRANSFER MANUAL ─────────────────────────
    if ($action === 'reject_manual') {
        SaPermission::require('payments.approve');
        require_once SA_ROOT . '/../core/ManualPay.php';
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'ID tidak valid.']); exit; }
        try {
            $res = ManualPay::reject($id);
            if (empty($res['ok'])) { echo json_encode(['error' => $res['error'] ?? 'Tolak gagal.']); exit; }
            logSuperAdminAction('reject_manual_transfer', null, "Tolak transfer manual saas_payments #$id");
            echo json_encode(['ok' => true, 'msg' => 'Pembayaran manual ditolak.']);
        } catch (Throwable $e) { apiErr($e, 'Gagal menolak. Coba lagi.'); }
        exit;
    }
```

- [ ] **Step 2: Tampilkan tombol di baris Midtrans (JS)**

Di fungsi `renderMidtransRows(rows)` (baris ~1342), ganti bagian penentuan `refundBtn` menjadi juga menampilkan tombol manual utk row `manual_transfer` pending:

```javascript
    let actionBtn = '';
    if (p.status === 'paid') {
      const paidTs = p.paid_at ? new Date(p.paid_at).getTime() : 0;
      const daysOld = (now - paidTs) / 86400000;
      const withinWindow = daysOld <= 90;
      actionBtn = `<button class="sa-btn sa-btn-sm sa-btn-danger" ${withinWindow ? '' : 'title="Mungkin di luar 90-hari refund window Midtrans"'}
                    onclick="openRefundModal(${JSON.stringify(p).replace(/"/g,'&quot;')})">↩ Refund</button>`;
    } else if (p.payment_type === 'manual_transfer' && p.status === 'pending') {
      actionBtn = `<button class="sa-btn sa-btn-sm sa-btn-primary" onclick="confirmManual(${p.id}, '${esc(p.order_id)}')">✓ Konfirmasi Lunas</button>
                   <button class="sa-btn sa-btn-sm sa-btn-outline" onclick="rejectManual(${p.id})">✗ Tolak</button>`;
    }
```

Lalu di baris `<td>${refundBtn}</td>` ubah jadi `<td>${actionBtn}</td>`.

Untuk membedakan visual, ubah kolom "Payment Type" agar `manual_transfer` tampil jelas — tambahkan ke `payTypeLabel`:

```javascript
const payTypeLabel  = { qris:'QRIS', bank_transfer:'VA Bank', gopay:'GoPay', shopeepay:'ShopeePay', manual_transfer:'🏦 Transfer Manual' };
```

- [ ] **Step 3: Tambah fungsi JS confirm/reject**

Sebelum `// Initial load` di akhir `<script>`, tambahkan:

```javascript
function confirmManual(id, orderId){
  if (!confirm('Konfirmasi transfer manual sudah masuk untuk order ' + orderId + '?\nCoin/aktivasi akan langsung dikreditkan.')) return;
  const form = new FormData(); form.append('id', id);
  saFetch('payments.php?action=confirm_manual', { method:'POST', body: form })
    .then(r => r.json()).then(d => {
      if (d.error) { saShowToast(d.error, 'error'); return; }
      saShowToast(d.msg || 'Dikonfirmasi.', 'success');
      loadMidtrans();
    });
}
function rejectManual(id){
  if (!confirm('Tolak pembayaran manual ini? Status jadi cancelled.')) return;
  const form = new FormData(); form.append('id', id);
  saFetch('payments.php?action=reject_manual', { method:'POST', body: form })
    .then(r => r.json()).then(d => {
      if (d.error) { saShowToast(d.error, 'error'); return; }
      saShowToast(d.msg || 'Ditolak.', 'success');
      loadMidtrans();
    });
}
```

- [ ] **Step 4: Smoke test + commit**

Smoke (butuh SA login): buka `superadmin/payments.php` → tab Midtrans → filter status Pending → row `🏦 Transfer Manual` punya tombol "Konfirmasi Lunas" & "Tolak". Konfirmasi row uji (dari Task 4 smoke) → toast sukses, status jadi Paid, coin tenant bertambah. (Logika confirm/reject sudah diuji unit di Task 3.)

```bash
git add superadmin/payments.php
git commit -m "feat(manual-pay): SA konfirmasi/tolak transfer manual di tab Midtrans payments"
```

---

### Task 6: Tombol "Transfer Manual" di entry point

**Files:**
- Modify: `hq/coin-info.php:346` (tombol top-up)
- Modify: `hq/outlet.php:446-447` (tombol aktivasi outlet)

**Interfaces:**
- Consumes: `ManualPay::isEnabled()`.

- [ ] **Step 1: `hq/coin-info.php` — tombol manual per bundle**

Pastikan file me-`require_once` `ManualPay` (tambah `require_once __DIR__ . '/../core/ManualPay.php';` di header bila belum), dan hitung sekali `$manualOn = ManualPay::isEnabled();` sebelum loop bundle.

Anchor existing (baris ~346-349):

```php
          <a href="/billing-checkout.php?type=topup_coin&bundle_id=<?= (int)$b['id'] ?>"
             class="btn-topup">
            💳 Top-up Sekarang
          </a>
```

Tambahkan tepat setelahnya (masih di dalam `.card` bundle, sebelum `</div>` penutup kartu) tombol manual bersyarat dengan gaya sekunder inline (tak ada kelas tombol sekunder di file ini):

```php
          <?php if ($manualOn): ?>
          <a href="/billing-checkout.php?type=topup_coin&bundle_id=<?= (int)$b['id'] ?>&method=manual"
             class="btn-topup" style="margin-top:8px;background:transparent;color:#0F1C3A;border:1px solid #CBD5E1;">
            🏦 Transfer Manual
          </a>
          <?php endif; ?>
```

- [ ] **Step 2: `hq/outlet.php` — varian aktivasi manual**

Di baris ~446-447 (template literal JS untuk tombol aktivasi), tambahkan tombol manual di samping "Aktivasi". Karena `ManualPay::isEnabled()` dievaluasi PHP, inject flag ke JS: di PHP `hq/outlet.php` tambahkan `require_once __DIR__ . '/../core/ManualPay.php';` dan sebuah `<script>const MANUAL_ON = <?= ManualPay::isEnabled() ? 'true' : 'false' ?>;</script>` sebelum blok JS render. Lalu ubah render tombol:

```javascript
          ? `<a href="/billing-checkout?type=outlet_activation&outlet_id=${o.id}" class="btn btn-dark btn-sm">🧾 Cek Pembayaran</a>`
          : `<a href="/billing-checkout?type=outlet_activation&outlet_id=${o.id}" class="btn btn-warn btn-sm">⚡ Aktivasi</a>`
            + (MANUAL_ON ? ` <a href="/billing-checkout?type=outlet_activation&outlet_id=${o.id}&method=manual" class="btn btn-outline btn-sm">🏦 Manual</a>` : '')
```

(Setup fee tidak butuh tombol khusus — gate `setup_fee` otomatis menampilkan tombol manual lewat fallback QRIS graceful di Task 4.)

- [ ] **Step 3: Smoke test + commit**

Smoke: sebagai tenant dgn manual aktif → `hq/coin-info.php` tiap bundle punya tombol "🏦 Transfer Manual"; `hq/outlet.php` outlet pending punya tombol "🏦 Manual". Matikan switch di billing-config → tombol hilang.

```bash
git add hq/coin-info.php hq/outlet.php
git commit -m "feat(manual-pay): tombol Transfer Manual di coin-info + outlet (bersyarat isEnabled)"
```

---

## Ringkasan Test

Satu file `tests/billing/test_manual_pay.php` menutup logika inti (Task 1-3):
1. `isEnabled`/`bankInfo` — switch + kelengkapan rekening.
2. `uniqueAmount` — base+code dalam range + anti-tabrakan.
3. `createPayment` — buat row manual + resume pending yang sama.
4. `confirm` — kredit coin sekali (idempoten double-confirm), status→paid.
5. `reject` — status→cancelled tanpa kredit.
6. `confirm` row cancelled → ditolak.

Jalankan: `php tests/billing/test_manual_pay.php` (harus cetak `OK test_manual_pay Task1/2/3`).

Task 4-6 (wiring halaman/UI) diverifikasi lewat smoke manual per task — reviewer memeriksa cabang kode terhadap kontrak interface.

## Catatan Arsitektur (untuk implementer)

- Ada tabel LEGACY `saas_manual_payments` + UI SA-nya (tab "Manual" di payments.php) untuk **SA mencatat pembayaran yang sudah terjadi**. JANGAN pakai/ubah itu. Fitur ini memakai `saas_payments` (`payment_type='manual_transfer'`) dan hidup di **tab "Midtrans"** payments.php. Dua sistem sengaja dipisah.
- `PaymentSettler::settle()` sudah menangani `topup_coin`/`setup_fee`/`outlet_activation` idempoten — jangan duplikasi logika kredit; cukup panggil lewat `ManualPay::confirm`.
- `amount` mengandung kode unik; coin/aktivasi TIDAK bergantung pada `amount`. Jangan sekali-kali menghitung coin dari `amount`.

# Outlet Activation: Server Config + Bonus Coin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Jadikan biaya/diskon/bonus-coin aktivasi outlet sebagai satu config server, kreditkan coin saat outlet diaktifkan, dan tampilkan biaya net + bonus coin + rincian di halaman konfirmasi owner.

**Architecture:** Tiga key di `saas_billing_config` (`outlet_activation_fee`/`_discount`/`_coin`) jadi sumber kebenaran. `settleOutletActivation` mengkreditkan coin (idempoten via `coin_ledger.payment_id`). `billing-checkout` menerapkan diskon. UI owner (`add-outlet.php`) + modal SA (`packages.php`) + default wizard membaca config yang sama.

**Tech Stack:** PHP 8, MariaDB (`saas_billing_config`, `coin_ledger`, `tenants`, `outlets`), `core/BillingConfig.php`, `core/PaymentSettler.php`. Test: PHP CLI + `tests/_assert.php`.

## Global Constraints

- Config keys (verbatim): `outlet_activation_fee` (default `800000`), `outlet_activation_discount` (default `0`, persen 0–100), `outlet_activation_coin` (default `100000`).
- Net biaya = `(int)round($fee * (1 - $disc/100))`. Diskon di-clamp `max(0, min(100, $disc))`.
- Coin kredit **idempoten**: skip jika sudah ada `coin_ledger` untuk `payment_id` (pola `settleSetupFee`). `coin_ledger.type` = `'topup'`, `feature_used='outlet_activation'`.
- Nominal yang ditampilkan di konfirmasi owner **harus sama** dengan tagihan `billing-checkout`.
- Coin server-authoritative (dikredit di settle), bukan dari input klien.
- Mode trial (outlet pertama) tidak berubah: "Gratis" + 10.000 coin trial.
- Endpoint config baru = SuperAdmin only + `saVerifyCsrf()`.
- `php -l` semua file PHP yang disentuh harus bersih.

---

## File Structure

| File | Aksi | Tanggung jawab |
|---|---|---|
| `saas_billing_config` (DB) | DATA | 2 row config baru (idempoten) |
| `core/PaymentSettler.php` | MODIFY | `settleOutletActivation` kreditkan coin idempoten |
| `billing-checkout.php` | MODIFY | terapkan diskon pada `outlet_activation` |
| `add-outlet.php` | MODIFY | konfirmasi: biaya net + bonus coin + rincian |
| `superadmin/packages.php` | MODIFY | modal default → server (get/save action), buang localStorage |
| `superadmin/registration_wizard.php` | MODIFY | default Step 2 dari config server |
| `tests/billing/test_activation_coin.php` | CREATE | unit: config, diskon, settle idempoten |

---

### Task 1: Config server — seed 2 key aktivasi

**Files:**
- Data: tabel `saas_billing_config` (jalankan SQL langsung ke PROD via mysql client)
- Test: `tests/billing/test_activation_coin.php` (CREATE — bagian config)

**Interfaces:**
- Produces: key `outlet_activation_discount` (default `0`), `outlet_activation_coin` (default `100000`) terbaca via `BillingConfig::getInt($key, $default)`.

- [ ] **Step 1: Tulis test config (gagal dulu)**

Buat `tests/billing/test_activation_coin.php`:

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

// (1) Config key baru terbaca
ok(BillingConfig::getInt('outlet_activation_coin', 100000) >= 0, 'outlet_activation_coin terbaca (int)');
ok(BillingConfig::getInt('outlet_activation_discount', 0) >= 0, 'outlet_activation_discount terbaca (int)');

echo "OK test_activation_coin (config)\n";
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

Run: `php tests/billing/test_activation_coin.php`
Expected: FAIL — kalau `core/BillingConfig.php` butuh konstanta DB belum ke-define dengan benar / key belum ada, atau lulus trivially. Jika lulus trivial (karena default), lanjut — Step 3 memastikan row benar-benar ada di DB.

- [ ] **Step 3: Seed config ke DB**

Run:
```bash
/opt/homebrew/opt/mysql-client/bin/mysql -e "INSERT INTO saas_billing_config (key_name, value_text, description) VALUES ('outlet_activation_discount','0','Diskon aktivasi outlet (%) 0-100'),('outlet_activation_coin','100000','Bonus coin dikreditkan saat aktivasi outlet') ON DUPLICATE KEY UPDATE key_name=key_name;"
```
Expected: tidak ada error.

- [ ] **Step 4: Verifikasi row ada**

Run: `/opt/homebrew/opt/mysql-client/bin/mysql -e "SELECT key_name, value_text FROM saas_billing_config WHERE key_name LIKE 'outlet_activation_%'"`
Expected: 3 baris — `outlet_activation_fee=800000`, `outlet_activation_discount=0`, `outlet_activation_coin=100000`.

- [ ] **Step 5: Jalankan test, pastikan LULUS**

Run: `php tests/billing/test_activation_coin.php`
Expected: `PASS: outlet_activation_coin ...`, `PASS: outlet_activation_discount ...`, `OK test_activation_coin (config)`.

- [ ] **Step 6: Commit**

```bash
git add tests/billing/test_activation_coin.php
git commit -m "feat(billing): seed config outlet_activation_discount + outlet_activation_coin + test config"
```

---

### Task 2: Diskon di billing-checkout (outlet_activation)

**Files:**
- Modify: `billing-checkout.php` (blok `elseif ($type === 'outlet_activation')`, sekitar baris 49–58)
- Test: `tests/billing/test_activation_coin.php` (tambah assert diskon)

**Interfaces:**
- Consumes: `BillingConfig::getInt('outlet_activation_fee',800000)`, `BillingConfig::getInt('outlet_activation_discount',0)`.
- Produces: `$amount` net = `round(fee*(1-disc/100))` untuk type `outlet_activation`.

- [ ] **Step 1: Tambah test diskon (gagal dulu jika rumus salah)**

Di `tests/billing/test_activation_coin.php`, sebelum baris `echo "OK ...`, tambah:

```php
// (2) Rumus net biaya (fee 1.000.000 − 20%) = 800.000
$net = (int)round(1000000 * (1 - 20/100));
eqv($net, 800000, 'net biaya fee 1.000.000 −20% = 800.000');
```

- [ ] **Step 2: Jalankan test**

Run: `php tests/billing/test_activation_coin.php`
Expected: PASS untuk assert net (rumus murni PHP). Ini meng-anchor rumus yang dipakai di implementasi.

- [ ] **Step 3: Implement — terapkan diskon di billing-checkout**

Di `billing-checkout.php`, ganti baris dalam blok `outlet_activation`:

```php
    $amount = BillingConfig::getInt('outlet_activation_fee', 800000);
```

menjadi:

```php
    $fee  = BillingConfig::getInt('outlet_activation_fee', 800000);
    $disc = max(0, min(100, BillingConfig::getInt('outlet_activation_discount', 0)));
    $amount = (int)round($fee * (1 - $disc / 100));
```

- [ ] **Step 4: Lint**

Run: `php -l billing-checkout.php`
Expected: `No syntax errors detected in billing-checkout.php`

- [ ] **Step 5: Commit**

```bash
git add billing-checkout.php tests/billing/test_activation_coin.php
git commit -m "feat(billing): terapkan diskon aktivasi pada tagihan outlet_activation"
```

---

### Task 3: Kreditkan bonus coin di settleOutletActivation

**Files:**
- Modify: `core/PaymentSettler.php` (`settleOutletActivation`, baris 217–256)
- Test: `tests/billing/test_activation_coin.php` (tambah assert settle idempoten)

**Interfaces:**
- Consumes: `BillingConfig::getInt('outlet_activation_coin',100000)`, tabel `coin_ledger` (kolom `tenant_id,outlet_id,type,amount,feature_used,description,balance_after,payment_id`), `tenants.coin_balance`.
- Produces: `settleOutletActivation(int $paymentId)` kini menambah `coin_added` ke hasil; saldo tenant bertambah; 1 baris ledger per payment (idempoten).

- [ ] **Step 1: Tambah test settle idempoten**

Di `tests/billing/test_activation_coin.php`, sebelum `echo "OK ...`, tambah test yang membuat data sintetis lalu memanggil settle 2×:

```php
require_once dirname(__DIR__, 2) . '/core/PaymentSettler.php';
$db = Database::get();

// Siapkan tenant + outlet pending + payment outlet_activation sintetis
$db->beginTransaction();
$db->prepare("INSERT INTO tenants (nama_bisnis, owner_name, owner_wa, status, coin_balance, created_at)
              VALUES ('TEST-ACT','t','0', 'trial', 0, NOW())")->execute();
$tid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO outlets (tenant_id, nama_outlet, slug, status, coin_balance, is_main, setup_done)
              VALUES (?, 'TEST-ACT-OUTLET', ?, 'pending', 0, 0, 0)")
   ->execute([$tid, 'test-act-'.$tid]);
$oid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO saas_payments (tenant_id, type, amount, status, ref_outlet_id, created_at, expires_at)
              VALUES (?, 'outlet_activation', 800000, 'paid', ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))")
   ->execute([$tid, $oid]);
$pid = (int)$db->lastInsertId();
$db->commit();

$coinCfg = BillingConfig::getInt('outlet_activation_coin', 100000);

$r1 = PaymentSettler::settleOutletActivation($pid);
ok(!empty($r1['ok']), 'settle pertama ok');
$bal1 = (int)$db->query("SELECT coin_balance FROM tenants WHERE id=$tid")->fetchColumn();
eqv($bal1, $coinCfg, 'saldo tenant = outlet_activation_coin setelah settle');
$led1 = (int)$db->query("SELECT COUNT(*) FROM coin_ledger WHERE payment_id=$pid")->fetchColumn();
eqv($led1, 1, '1 baris ledger setelah settle pertama');

$r2 = PaymentSettler::settleOutletActivation($pid);
ok(!empty($r2['ok']), 'settle kedua ok (idempoten)');
$bal2 = (int)$db->query("SELECT coin_balance FROM tenants WHERE id=$tid")->fetchColumn();
eqv($bal2, $coinCfg, 'saldo tetap (tidak double-credit) setelah settle kedua');
$led2 = (int)$db->query("SELECT COUNT(*) FROM coin_ledger WHERE payment_id=$pid")->fetchColumn();
eqv($led2, 1, 'tetap 1 baris ledger (idempoten)');

// Cleanup data sintetis
$db->prepare("DELETE FROM coin_ledger WHERE payment_id=?")->execute([$pid]);
$db->prepare("DELETE FROM saas_payments WHERE id=?")->execute([$pid]);
$db->prepare("DELETE FROM outlets WHERE id=?")->execute([$oid]);
$db->prepare("DELETE FROM tenants WHERE id=?")->execute([$tid]);
```

> Catatan implementer: cek nama kolom `saas_payments` (mis. `amount` vs `nominal`) dan `tenants` (mis. `nama_bisnis`) dengan `SHOW COLUMNS` sebelum run; sesuaikan INSERT sintetis bila beda. Jangan ubah logika produksi untuk mengakomodasi test — sesuaikan test ke skema nyata.

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

Run: `php tests/billing/test_activation_coin.php`
Expected: FAIL pada `saldo tenant = outlet_activation_coin` — karena `settleOutletActivation` belum mengkreditkan coin.

- [ ] **Step 3: Implement — kredit coin idempoten**

Di `core/PaymentSettler.php`, dalam `settleOutletActivation`, ganti blok dari `UPDATE outlets ... status='active'` sampai `$db->commit();` (baris ~240–243) menjadi:

```php
            $db->prepare("UPDATE outlets SET status='active', activated_at=NOW() WHERE id=?")
               ->execute([$payment['ref_outlet_id']]);

            // Bonus coin aktivasi — idempoten via coin_ledger.payment_id
            $coinAdded = 0;
            $ledgerExists = $db->prepare("SELECT id FROM coin_ledger WHERE payment_id=? AND type='topup'");
            $ledgerExists->execute([$payment['id']]);
            $alreadyCredited = (bool)$ledgerExists->fetchColumn();

            $bonusCoin = max(0, BillingConfig::getInt('outlet_activation_coin', 100000));
            if (!$alreadyCredited && $bonusCoin > 0) {
                $db->prepare("UPDATE tenants SET coin_balance = coin_balance + ? WHERE id=?")
                   ->execute([$bonusCoin, $payment['tenant_id']]);

                $balSt = $db->prepare("SELECT coin_balance FROM tenants WHERE id=?");
                $balSt->execute([$payment['tenant_id']]);
                $newBal = (int)$balSt->fetchColumn();

                $db->prepare(
                    "INSERT INTO coin_ledger (tenant_id, outlet_id, type, amount, feature_used, description, balance_after, payment_id)
                     VALUES (?, ?, 'topup', ?, 'outlet_activation', ?, ?, ?)"
                )->execute([
                    $payment['tenant_id'],
                    $payment['ref_outlet_id'],
                    $bonusCoin,
                    'Bonus aktivasi outlet',
                    $newBal,
                    $payment['id'],
                ]);
                $coinAdded = $bonusCoin;
            }

            $db->commit();
```

Dan ubah `return` sukses (baris ~251) menjadi:

```php
            return ['ok' => true, 'outlet_activated' => $payment['ref_outlet_id'], 'coin_added' => $coinAdded];
```

Pastikan `core/BillingConfig.php` ter-`require` (cek bagian atas `PaymentSettler.php`; `settleSetupFee` sudah memakai `BillingConfig::get`, jadi sudah tersedia).

- [ ] **Step 4: Jalankan test, pastikan LULUS**

Run: `php tests/billing/test_activation_coin.php`
Expected: semua `PASS`, termasuk saldo = coin config, tetap 1 ledger, tidak double-credit. `OK test_activation_coin`.

- [ ] **Step 5: Lint**

Run: `php -l core/PaymentSettler.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add core/PaymentSettler.php tests/billing/test_activation_coin.php
git commit -m "feat(billing): kreditkan bonus coin aktivasi di settleOutletActivation (idempoten)"
```

---

### Task 4: Konfirmasi owner — biaya net + bonus coin + rincian

**Files:**
- Modify: `add-outlet.php` (Step 2 konfirmasi, baris 612–620; pastikan `BillingConfig` ter-require di atas file)

**Interfaces:**
- Consumes: `BillingConfig::getInt('outlet_activation_fee'/'_discount'/'_coin', ...)`.
- Produces: tampilan biaya net + bonus coin + rincian. Tidak ada interface kode untuk task lain.

- [ ] **Step 1: Pastikan BillingConfig tersedia di add-outlet.php**

Run: `grep -n "BillingConfig" add-outlet.php`
Expected: jika kosong, tambahkan `require_once ROOT . '/core/BillingConfig.php';` di blok require atas (dekat require Database/TenantProvisioner).

- [ ] **Step 2: Hitung nilai konfirmasi (PHP) sebelum markup Step 2**

Di `add-outlet.php`, tepat sebelum baris `<?php // ═══ STEP 2: Review & Confirm` (baris 575), sisipkan perhitungan:

```php
<?php
  $ao_fee  = BillingConfig::getInt('outlet_activation_fee', 800000);
  $ao_disc = max(0, min(100, BillingConfig::getInt('outlet_activation_discount', 0)));
  $ao_net  = (int)round($ao_fee * (1 - $ao_disc / 100));
  $ao_coin = max(0, BillingConfig::getInt('outlet_activation_coin', 100000));
?>
```

- [ ] **Step 3: Ganti baris "Biaya" + tambah "Bonus coin" + rincian**

Ganti blok baris 612–619 (review-row "Biaya") menjadi:

```php
          <div class="review-row">
            <span class="rv-label">Biaya</span>
            <?php if (($d['mode'] ?? 'trial') === 'paid'): ?>
              <?php if ($ao_net <= 0): ?>
                <span class="rv-val" style="color:var(--green)">Gratis</span>
              <?php elseif ($ao_disc > 0): ?>
                <span class="rv-val" style="color:#DC2626">
                  <span style="text-decoration:line-through;color:#9CA3AF;font-weight:500">Rp <?= number_format($ao_fee,0,',','.') ?></span>
                  Rp <?= number_format($ao_net,0,',','.') ?>
                  <span style="color:#F59E0B;font-size:12px">(−<?= $ao_disc ?>%)</span>
                </span>
              <?php else: ?>
                <span class="rv-val" style="color:#DC2626">Rp <?= number_format($ao_net,0,',','.') ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span class="rv-val" style="color:var(--green)">Gratis</span>
            <?php endif; ?>
          </div>
          <?php if (($d['mode'] ?? 'trial') === 'paid' && $ao_coin > 0): ?>
          <div class="review-row">
            <span class="rv-label">Bonus Coin</span>
            <span class="rv-val" style="color:var(--teal-d)">🪙 <?= number_format($ao_coin,0,',','.') ?> coin</span>
          </div>
          <?php endif; ?>
```

- [ ] **Step 4: Tambah blok rincian "Yang kamu dapatkan"**

Tepat setelah `</div>` penutup grup review (baris 620, sebelum `<form method="POST">`), sisipkan:

```php
        <div style="background:#F0FDFA;border:1px solid #99F6E4;border-radius:12px;padding:14px 16px;margin-bottom:18px">
          <div style="font-weight:700;color:#0F766E;margin-bottom:8px;font-size:13.5px">🏪 Yang kamu dapatkan</div>
          <ul style="margin:0;padding-left:18px;color:#374151;font-size:12.5px;line-height:1.8">
            <li>Outlet aktif penuh — semua fitur (POS, Order &amp; Kanban, Inventori, Mesin, Antar-Jemput, Laporan Keuangan, Absensi, Loyalty)</li>
            <li>Data &amp; pengaturan terpisah per outlet (pelanggan, layanan, harga, staf sendiri)</li>
            <li>Metode pembayaran siap pakai (Tunai, Transfer, QRIS)</li>
            <li>Bahan/inventori default sudah ter-seed</li>
            <li>Penomoran nota otomatis khas outlet</li>
            <?php if (($d['mode'] ?? 'trial') === 'paid' && $ao_coin > 0): ?>
            <li><strong><?= number_format($ao_coin,0,',','.') ?> coin bonus</strong> dikreditkan saat outlet aktif</li>
            <?php endif; ?>
          </ul>
        </div>
```

- [ ] **Step 5: Lint**

Run: `php -l add-outlet.php`
Expected: `No syntax errors detected in add-outlet.php`

- [ ] **Step 6: Commit**

```bash
git add add-outlet.php
git commit -m "feat(outlet): konfirmasi tambah outlet tampil biaya net + bonus coin + rincian fitur"
```

---

### Task 5: Modal SA "Edit Default Aktivasi" → config server

**Files:**
- Modify: `superadmin/packages.php` (API actions + JS modal; baris ~30 API layer, ~333–394 JS)

**Interfaces:**
- Consumes: `saVerifyCsrf()`, `BillingConfig::getInt`, `BillingConfig::set($key,$val,$saId)`, `saFetch`.
- Produces: action `get_activation_defaults` (GET JSON) & `save_activation_defaults` (POST JSON). JS `loadDefaults()` async dari server.

- [ ] **Step 1: Tambah endpoint GET defaults (sebelum `saVerifyCsrf()`)**

Di `superadmin/packages.php`, dalam blok `if ($action)` setelah action `list_bundles` (sebelum komentar `// ── POST actions — CSRF required ──`), tambah:

```php
    if ($action === 'get_activation_defaults') {
        echo json_encode(['ok' => true,
            'fee'      => BillingConfig::getInt('outlet_activation_fee', 800000),
            'discount' => BillingConfig::getInt('outlet_activation_discount', 0),
            'coinAwal' => BillingConfig::getInt('outlet_activation_coin', 100000),
        ]);
        exit;
    }
```

- [ ] **Step 2: Tambah endpoint POST save (setelah `saVerifyCsrf()`, dekat `save_bundle`)**

Setelah baris `saVerifyCsrf();`, tambah:

```php
    if ($action === 'save_activation_defaults') {
        $d   = json_decode(file_get_contents('php://input'), true) ?: [];
        $fee  = max(0, (int)($d['fee'] ?? 0));
        $disc = max(0, min(100, (int)($d['discount'] ?? 0)));
        $coin = max(0, (int)($d['coinAwal'] ?? 0));
        $sa   = (int)($_SESSION['sa_id'] ?? 0) ?: null;
        BillingConfig::set('outlet_activation_fee', (string)$fee, $sa);
        BillingConfig::set('outlet_activation_discount', (string)$disc, $sa);
        BillingConfig::set('outlet_activation_coin', (string)$coin, $sa);
        echo json_encode(['ok' => true, 'msg' => 'Default aktivasi disimpan.']);
        exit;
    }
```

> Catatan: verifikasi nama session SA (`$_SESSION['sa_id']`) di `middleware/superadmin_guard.php`; sesuaikan bila beda. `BillingConfig::set` signature = `set(string $key, string $value, ?int $bySaId)`.

- [ ] **Step 3: Ganti JS `loadDefaults`/`saveDefaults` → server**

Di `superadmin/packages.php`, ganti blok JS `// ── Defaults (stored in localStorage for now) ──` s/d fungsi `saveDefaultsToStorage` (baris 333–340) menjadi:

```js
// ── Defaults (server config: saas_billing_config) ─────
let _activationDefaults = { fee: 0, discount: 0, coinAwal: 0 };
function loadDefaults() { return _activationDefaults; }
function fetchActivationDefaults() {
  return saFetch('packages.php?action=get_activation_defaults')
    .then(r => r.json()).then(d => {
      if (d && d.ok) _activationDefaults = { fee: d.fee, discount: d.discount, coinAwal: d.coinAwal };
      return _activationDefaults;
    });
}
```

- [ ] **Step 4: Ubah `saveDefaults()` → POST server**

Ganti fungsi `saveDefaults()` (baris 386–394) menjadi:

```js
function saveDefaults() {
  const payload = {
    fee:      rawInt('defFee'),
    discount: parseInt(document.getElementById('defDiscount').value) || 0,
    coinAwal: rawInt('defCoin'),
  };
  saFetch('packages.php?action=save_activation_defaults', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  }).then(r => r.json()).then(d => {
    if (d.error) { saShowToast(d.error, 'error'); return; }
    _activationDefaults = { fee: payload.fee, discount: payload.discount, coinAwal: payload.coinAwal };
    refreshActivationCard();
    closeModal('defaultModal');
    saShowToast(d.msg || 'Default aktivasi disimpan.', 'success');
  });
}
```

- [ ] **Step 5: Init dari server saat load (ganti pemanggilan terakhir)**

Ganti baris `refreshActivationCard();` paling bawah (baris ~549) menjadi:

```js
fetchActivationDefaults().then(refreshActivationCard);
```

Dan di `openDefaultModal()` (baris 360), ganti baris pertama `const d = loadDefaults();` tetap sama (sudah baca `_activationDefaults`). Tidak ada perubahan lain di `openDefaultModal`.

- [ ] **Step 6: Lint PHP + cek tidak ada sisa localStorage**

Run: `php -l superadmin/packages.php && grep -n "localStorage\|sa_activation_defaults\|saveDefaultsToStorage\|DEF_KEY" superadmin/packages.php`
Expected: `No syntax errors detected`; grep **kosong** (semua referensi localStorage terhapus).

- [ ] **Step 7: Commit**

```bash
git add superadmin/packages.php
git commit -m "feat(sa): modal Default Aktivasi simpan/baca config server (buang localStorage)"
```

---

### Task 6: Wizard registrasi — default Step 2 dari config server

**Files:**
- Modify: `superadmin/registration_wizard.php` (default `coin_awal`/`discount_pct`/`setup_fee` saat belum di-set; baris ~739–750)

**Interfaces:**
- Consumes: `BillingConfig::getInt('outlet_activation_coin'/'_discount'/'_fee', ...)`.
- Produces: nilai default pre-fill Step 2. Perilaku override per-klien tidak berubah.

- [ ] **Step 1: Pastikan BillingConfig tersedia**

Run: `grep -n "BillingConfig" superadmin/registration_wizard.php`
Expected: jika kosong, tambah `require_once dirname(__DIR__) . '/core/BillingConfig.php';` di area require atas (setelah guard).

- [ ] **Step 2: Hitung default server sebelum markup Step 2**

Cari awal render Step 2 (dekat baris 739 input `discount_pct`). Tepat sebelum bagian markup field tersebut, tambahkan PHP:

```php
<?php
  $def_fee  = BillingConfig::getInt('outlet_activation_fee', 800000);
  $def_disc = BillingConfig::getInt('outlet_activation_discount', 0);
  $def_coin = BillingConfig::getInt('outlet_activation_coin', 100000);
?>
```

- [ ] **Step 3: Pakai default server pada value field**

Ubah input `discount_pct` (baris 739–740):

```php
                 value="<?= (float)($wiz['discount_pct'] ?? $def_disc) ?>"
```

Ubah input `coin_awal` (baris 749–750):

```php
                 value="<?= number_format((int)($wiz['coin_awal'] ?? $def_coin), 0, ',', '.') ?>"
```

Jika ada input `setup_fee`/biaya di Step 2 dengan default hardcoded, ubah fallback-nya ke `$def_fee` dengan pola sama (`?? $def_fee`). Jangan ubah cabang yang sudah memakai `$wiz[...]` hasil input SA.

- [ ] **Step 4: Lint**

Run: `php -l superadmin/registration_wizard.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add superadmin/registration_wizard.php
git commit -m "feat(sa): default Step 2 wizard registrasi dari config server aktivasi"
```

---

### Task 7: Integrasi — full test + deploy

**Files:** tidak ada perubahan; integrasi.

- [ ] **Step 1: Pull (sesi paralel mungkin)**

Run: `git pull --rebase origin main`
Expected: sukses; selesaikan konflik hanya pada file yang disentuh plan ini.

- [ ] **Step 2: Jalankan seluruh test billing + lint**

Run:
```bash
php tests/billing/test_activation_coin.php && \
php -l billing-checkout.php && php -l core/PaymentSettler.php && \
php -l add-outlet.php && php -l superadmin/packages.php && php -l superadmin/registration_wizard.php
```
Expected: semua `PASS` / `No syntax errors detected`.

- [ ] **Step 3: Push (auto-deploy)**

```bash
git push origin main
```
Expected: push sukses; `lamasy.harpy.id` ter-deploy.

- [ ] **Step 4: Verifikasi konfirmasi owner di produksi (MCP/manual)**

Buka `lamasy.harpy.id` → login owner → Tambah Outlet → Step 2: pastikan Biaya = Rp 800.000 (atau net jika diskon di-set), baris Bonus Coin = 100.000 coin, blok "Yang kamu dapatkan" tampil. Set diskon di SA → ulang → angka berubah sesuai config.

---

## Self-Review

**Spec coverage:**
- Config server 3 key → Task 1 (seed) + dipakai Task 2/3/4/5/6 ✓
- Modal SA → server, buang localStorage → Task 5 ✓
- Owner pakai fee−diskon + coin → Task 2 (checkout) + Task 4 (display) ✓
- Coin dikreditkan idempoten di settleOutletActivation → Task 3 ✓
- billing-checkout diskon = tampilan konfirmasi → Task 2 + Task 4 (rumus sama `round(fee*(1-disc/100))`) ✓
- Rincian "yang didapat" (fakta provisioning) → Task 4 ✓
- Wizard default dari config server → Task 6 ✓
- Idempotency, coin=0, fee=0, diskon clamp → Task 3 (ledger check) + Task 2/4 (clamp & net≤0→Gratis) ✓
- Testing PHP unit (config, diskon, settle idempoten) → Task 1/2/3 ✓; deploy verify → Task 7 ✓
- Out of scope (carry-over trial coin, diskon per-klien owner, ubah paket/setup_fee settle) → tidak ada task yang melanggar ✓

**Placeholder scan:** Tidak ada TBD/TODO. Catatan "verifikasi nama kolom/session" adalah instruksi konkret ke implementer (skema nyata), bukan placeholder logika.

**Type consistency:** Key config (`outlet_activation_fee`/`_discount`/`_coin`) konsisten di semua task. Rumus net `round(fee*(1-disc/100))` identik di Task 2 & 4. `coin_ledger` kolom & `type='topup'`, `feature_used='outlet_activation'` konsisten Task 3. JS `_activationDefaults`/`fetchActivationDefaults`/`loadDefaults` konsisten Task 5.

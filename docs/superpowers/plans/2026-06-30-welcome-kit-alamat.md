# Welcome Kit Fisik + Alamat Outlet Wajib Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Saat outlet aktif berbayar, catat welcome kit fisik terutang (snapshot alamat + isi kit) ke antrian SuperAdmin dengan status kirim, dan jadikan alamat outlet lengkap & wajib.

**Architecture:** Config server (`saas_billing_config`) menyimpan isi kit + toggle. `core/WelcomeKit.php` mengelola record `saas_welcome_kit` (create idempoten saat settle, snapshot alamat/isi, status pending→shipped→delivered). `PaymentSettler` memicu create saat `settleSetupFee`/`settleOutletActivation` (best-effort). SA page mengelola antrian + config. add-outlet & wizard mewajibkan alamat lengkap.

**Tech Stack:** PHP 8, MariaDB (`outlets`, `saas_billing_config`, `saas_welcome_kit`), `core/BillingConfig.php`, `core/PaymentSettler.php`. Test: PHP CLI + `tests/_assert.php`.

## Global Constraints

- Config keys (verbatim): `welcome_kit_enabled` (default `1`), `welcome_kit_items` (default JSON `[{"nama":"Roll kertas thermal 58mm","qty":2},{"nama":"Plastik packing","qty":1},{"nama":"Solasi roll","qty":1}]`).
- Kit dibuat HANYA saat outlet aktif berbayar (`settleSetupFee` outlet utama, `settleOutletActivation` outlet 2+). Trial/unpaid tidak dapat kit.
- Idempoten: `saas_welcome_kit.payment_id` UNIQUE → 1 kit per aktivasi.
- Snapshot alamat & `items_json` dibekukan saat create.
- Alamat wajib lengkap untuk outlet baru: Penerima, No. HP (kolom `telepon`), Alamat, Kota, Kode Pos. Tidak retroaktif untuk outlet lama.
- Kit gagal dibuat TIDAK menggagalkan settle/aktivasi (best-effort try/catch, log).
- Status ENUM: `pending`,`shipped`,`delivered`,`cancelled`.
- SA endpoint: guard SuperAdmin + `saVerifyCsrf()` (POST); GET read-only. SA session var `$_SESSION['superadmin_id']`.
- `php -l` semua PHP yang disentuh harus bersih.
- Dua repo tidak relevan (semua di repo PHP). Auto-deploy dari `main`.

---

## File Structure

| File | Aksi | Tanggung jawab |
|---|---|---|
| `outlets` (DB) | ALTER | + `penerima`, `kode_pos` |
| `saas_welcome_kit` (DB) | CREATE | record kit + status |
| `saas_billing_config` (DB) | DATA | `welcome_kit_enabled`, `welcome_kit_items` |
| `core/WelcomeKit.php` | CREATE | domain logic kit |
| `core/PaymentSettler.php` | MODIFY | trigger create saat settle |
| `add-outlet.php` | MODIFY | alamat wajib + kolom baru + validate helper |
| `superadmin/registration_wizard.php` | MODIFY | alamat wajib di wizard |
| `superadmin/welcome_kit.php` | CREATE | antrian + config SA |
| `superadmin/superadmin_components.php` | MODIFY | link sidebar |
| `tests/welcomekit/test_welcome_kit.php` | CREATE | unit WelcomeKit + validate + settle |

---

### Task 1: Schema — kolom outlet, tabel kit, config

**Files:**
- Data: `outlets` (ALTER), `saas_welcome_kit` (CREATE), `saas_billing_config` (seed) — via mysql client ke PROD
- Test: `tests/welcomekit/test_welcome_kit.php` (CREATE — bagian schema)

**Interfaces:**
- Produces: kolom `outlets.penerima`, `outlets.kode_pos`; tabel `saas_welcome_kit`; config `welcome_kit_enabled`/`welcome_kit_items`.

- [ ] **Step 1: Tulis test schema (gagal dulu)**

Buat `tests/welcomekit/test_welcome_kit.php`:

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
$db = Database::get();

$oc = $db->query("SHOW COLUMNS FROM outlets")->fetchAll(PDO::FETCH_COLUMN);
ok(in_array('penerima', $oc), 'outlets.penerima ada');
ok(in_array('kode_pos', $oc), 'outlets.kode_pos ada');

$t = $db->query("SHOW TABLES LIKE 'saas_welcome_kit'")->fetchAll();
ok(count($t) === 1, 'tabel saas_welcome_kit ada');
$wc = $db->query("SHOW COLUMNS FROM saas_welcome_kit")->fetchAll(PDO::FETCH_COLUMN);
foreach (['tenant_id','outlet_id','payment_id','trigger','penerima','hp','alamat','kota','kode_pos','items_json','status','kurir','resi','shipped_at','delivered_at','catatan'] as $c) {
    ok(in_array($c, $wc), "saas_welcome_kit.$c ada");
}

echo "OK test_welcome_kit (schema)\n";
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

Run: `php tests/welcomekit/test_welcome_kit.php`
Expected: FAIL — `outlets.penerima ada` gagal (kolom belum ada).

- [ ] **Step 3: Jalankan migrasi DB**

Run:
```bash
/opt/homebrew/opt/mysql-client/bin/mysql <<'SQL'
ALTER TABLE outlets
  ADD COLUMN penerima VARCHAR(120) NULL AFTER telepon,
  ADD COLUMN kode_pos VARCHAR(10) NULL AFTER penerima;

CREATE TABLE IF NOT EXISTS saas_welcome_kit (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  payment_id INT NULL,
  trigger VARCHAR(24) NOT NULL,
  penerima VARCHAR(120) NULL,
  hp VARCHAR(20) NULL,
  alamat TEXT NULL,
  kota VARCHAR(100) NULL,
  kode_pos VARCHAR(10) NULL,
  items_json TEXT NULL,
  status ENUM('pending','shipped','delivered','cancelled') NOT NULL DEFAULT 'pending',
  kurir VARCHAR(60) NULL,
  resi VARCHAR(80) NULL,
  shipped_at DATETIME NULL,
  delivered_at DATETIME NULL,
  catatan VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_payment (payment_id),
  KEY idx_tenant (tenant_id),
  KEY idx_outlet (outlet_id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO saas_billing_config (key_name, value_text, description) VALUES
 ('welcome_kit_enabled', '1', 'Aktifkan welcome kit fisik saat aktivasi outlet'),
 ('welcome_kit_items', '[{"nama":"Roll kertas thermal 58mm","qty":2},{"nama":"Plastik packing","qty":1},{"nama":"Solasi roll","qty":1}]', 'Isi welcome kit (JSON: nama+qty)')
ON DUPLICATE KEY UPDATE key_name = key_name;
SQL
```
Expected: tidak ada error.

> Catatan: `UNIQUE KEY uniq_payment (payment_id)` — MySQL memperbolehkan banyak baris NULL pada UNIQUE, tapi di sini `payment_id` selalu terisi dari settle, jadi aman.

- [ ] **Step 4: Verifikasi + test lulus**

Run: `php tests/welcomekit/test_welcome_kit.php`
Expected: semua `PASS`, `OK test_welcome_kit (schema)`.

- [ ] **Step 5: Commit**

```bash
git add tests/welcomekit/test_welcome_kit.php
git commit -m "feat(welcomekit): schema — kolom outlets penerima/kode_pos + tabel saas_welcome_kit + config isi kit"
```

---

### Task 2: core/WelcomeKit.php — domain logic

**Files:**
- Create: `core/WelcomeKit.php`
- Test: `tests/welcomekit/test_welcome_kit.php` (tambah bagian WelcomeKit)

**Interfaces:**
- Consumes: `BillingConfig::get($key,$default)`, `BillingConfig::getInt`, `Database::get()`, tabel `saas_welcome_kit`, `outlets`.
- Produces:
  - `WelcomeKit::enabled(): bool`
  - `WelcomeKit::items(): array` — list `['nama'=>string,'qty'=>int]`; JSON invalid → `[]`
  - `WelcomeKit::createForOutlet(PDO $db, int $tenantId, int $outletId, ?int $paymentId, string $trigger): array` — return `['ok'=>bool,'id'=>?int,'skipped'=>bool]`; idempoten via `payment_id`
  - `WelcomeKit::listQueue(?string $status=null): array`
  - `WelcomeKit::markShipped(int $id, string $kurir, string $resi): bool`
  - `WelcomeKit::markDelivered(int $id): bool`
  - `WelcomeKit::statusForOutlet(int $outletId): ?array`

- [ ] **Step 1: Tulis test WelcomeKit (gagal dulu)**

Tambah di `tests/welcomekit/test_welcome_kit.php`, sebelum `echo "OK ...`:

```php
require_once dirname(__DIR__, 2) . '/core/BillingConfig.php';
require_once dirname(__DIR__, 2) . '/core/WelcomeKit.php';

// items() decode
ok(count(WelcomeKit::items()) >= 1, 'items() decode config (>=1 item)');

// Siapkan tenant+outlet+payment sintetis
$db->beginTransaction();
$db->prepare("INSERT INTO tenants (nama_perusahaan, owner_name, owner_wa, slug, status, coin_balance, created_at)
              VALUES ('WK-TEST','t','0', ?, 'active', 0, NOW())")->execute(['wk-'.time().rand(100,999)]);
$tid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO outlets (tenant_id, nama_outlet, slug, status, coin_balance, is_main, setup_done, penerima, telepon, alamat, kota, kode_pos)
              VALUES (?, 'WK-OUTLET', ?, 'active', 0, 1, 0, 'Budi', '08123', 'Jl. Uji 1', 'Bandung', '40111')")
   ->execute([$tid, 'wk-out-'.$tid]);
$oid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO saas_payments (tenant_id, type, amount, status, ref_outlet_id, order_id, created_at, expires_at)
              VALUES (?, 'outlet_activation', 800000, 'paid', ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))")
   ->execute([$tid, $oid, 'WK-ORD-'.$tid]);
$pid = (int)$db->lastInsertId();
$db->commit();

// (a) create → 1 record pending, snapshot benar
$r1 = WelcomeKit::createForOutlet($db, $tid, $oid, $pid, 'outlet_activation');
ok(!empty($r1['ok']) && !empty($r1['id']), 'createForOutlet ok + id');
$row = $db->query("SELECT * FROM saas_welcome_kit WHERE id=".(int)$r1['id'])->fetch(PDO::FETCH_ASSOC);
eqv($row['status'], 'pending', 'status pending');
eqv($row['penerima'], 'Budi', 'snapshot penerima');
eqv($row['kode_pos'], '40111', 'snapshot kode_pos');
ok(strpos($row['items_json'], 'thermal') !== false, 'snapshot items berisi thermal');

// (b) idempoten: create 2× payment sama → tetap 1
$r2 = WelcomeKit::createForOutlet($db, $tid, $oid, $pid, 'outlet_activation');
ok(!empty($r2['skipped']), 'create kedua skipped (idempoten)');
$cnt = (int)$db->query("SELECT COUNT(*) FROM saas_welcome_kit WHERE payment_id=".$pid)->fetchColumn();
eqv($cnt, 1, 'tetap 1 record utk payment sama');

// (c) markShipped + markDelivered
ok(WelcomeKit::markShipped((int)$r1['id'], 'JNE', 'RESI123'), 'markShipped ok');
$row = $db->query("SELECT status,kurir,resi,shipped_at FROM saas_welcome_kit WHERE id=".(int)$r1['id'])->fetch(PDO::FETCH_ASSOC);
eqv($row['status'], 'shipped', 'status shipped'); eqv($row['kurir'], 'JNE', 'kurir'); eqv($row['resi'], 'RESI123', 'resi');
ok(!empty($row['shipped_at']), 'shipped_at terisi');
ok(WelcomeKit::markDelivered((int)$r1['id']), 'markDelivered ok');
eqv($db->query("SELECT status FROM saas_welcome_kit WHERE id=".(int)$r1['id'])->fetchColumn(), 'delivered', 'status delivered');

// (d) statusForOutlet
$st = WelcomeKit::statusForOutlet($oid);
ok($st && $st['status'] === 'delivered', 'statusForOutlet delivered');

// (e) disabled → no-op
$db->prepare("INSERT INTO saas_payments (tenant_id, type, amount, status, ref_outlet_id, order_id, created_at, expires_at)
              VALUES (?, 'outlet_activation', 800000, 'paid', ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))")
   ->execute([$tid, $oid, 'WK-ORD2-'.$tid]);
$pid2 = (int)$db->lastInsertId();
BillingConfig::set('welcome_kit_enabled', '0', null);
ok(!WelcomeKit::enabled(), 'enabled() false saat config 0');
$r3 = WelcomeKit::createForOutlet($db, $tid, $oid, $pid2, 'outlet_activation');
ok(empty($r3['ok']) && !empty($r3['skipped']), 'disabled → skip create');
$cnt2 = (int)$db->query("SELECT COUNT(*) FROM saas_welcome_kit WHERE payment_id=".$pid2)->fetchColumn();
eqv($cnt2, 0, 'disabled → 0 record');
BillingConfig::set('welcome_kit_enabled', '1', null);

// Cleanup
$db->prepare("DELETE FROM saas_welcome_kit WHERE tenant_id=?")->execute([$tid]);
$db->prepare("DELETE FROM saas_payments WHERE tenant_id=?")->execute([$tid]);
$db->prepare("DELETE FROM outlets WHERE id=?")->execute([$oid]);
$db->prepare("DELETE FROM tenants WHERE id=?")->execute([$tid]);
```

> Catatan implementer: verifikasi kolom `tenants`/`saas_payments`/`outlets` dgn `SHOW COLUMNS` sebelum run (mis. `nama_perusahaan`, `order_id` UNIQUE, `slug`). Sesuaikan INSERT sintetis ke skema nyata; jangan ubah logika produksi untuk test.

- [ ] **Step 2: Jalankan, pastikan GAGAL**

Run: `php tests/welcomekit/test_welcome_kit.php`
Expected: FAIL — `WelcomeKit` class belum ada (fatal) atau method belum ada.

- [ ] **Step 3: Implement `core/WelcomeKit.php`**

```php
<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/BillingConfig.php';

class WelcomeKit
{
    public static function enabled(): bool
    {
        return BillingConfig::getInt('welcome_kit_enabled', 1) === 1;
    }

    /** @return array<int,array{nama:string,qty:int}> */
    public static function items(): array
    {
        $raw = BillingConfig::get('welcome_kit_items', '[]');
        $arr = json_decode((string)$raw, true);
        if (!is_array($arr)) { error_log('[WelcomeKit] items JSON invalid'); return []; }
        $out = [];
        foreach ($arr as $it) {
            if (!is_array($it) || empty($it['nama'])) continue;
            $out[] = ['nama' => (string)$it['nama'], 'qty' => max(1, (int)($it['qty'] ?? 1))];
        }
        return $out;
    }

    /**
     * Idempoten via payment_id. Snapshot alamat outlet + isi kit.
     * @return array{ok:bool,id:?int,skipped:bool}
     */
    public static function createForOutlet(PDO $db, int $tenantId, int $outletId, ?int $paymentId, string $trigger): array
    {
        if (!self::enabled()) return ['ok' => false, 'id' => null, 'skipped' => true];

        if ($paymentId !== null) {
            $ex = $db->prepare("SELECT id FROM saas_welcome_kit WHERE payment_id=?");
            $ex->execute([$paymentId]);
            if ($id = $ex->fetchColumn()) {
                return ['ok' => true, 'id' => (int)$id, 'skipped' => true];
            }
        }

        $o = $db->prepare("SELECT penerima, telepon, alamat, kota, kode_pos FROM outlets WHERE id=? AND tenant_id=?");
        $o->execute([$outletId, $tenantId]);
        $outlet = $o->fetch(PDO::FETCH_ASSOC);
        if (!$outlet) return ['ok' => false, 'id' => null, 'skipped' => true];

        $incomplete = (empty($outlet['penerima']) || empty($outlet['alamat']) || empty($outlet['kota']) || empty($outlet['kode_pos']));
        $catatan = $incomplete ? 'alamat belum lengkap — lengkapi sebelum kirim' : null;

        $ins = $db->prepare(
            "INSERT INTO saas_welcome_kit
               (tenant_id, outlet_id, payment_id, trigger, penerima, hp, alamat, kota, kode_pos, items_json, status, catatan)
             VALUES (?,?,?,?,?,?,?,?,?,?, 'pending', ?)"
        );
        $ins->execute([
            $tenantId, $outletId, $paymentId, $trigger,
            $outlet['penerima'] ?: null, $outlet['telepon'] ?: null,
            $outlet['alamat'] ?: null, $outlet['kota'] ?: null, $outlet['kode_pos'] ?: null,
            json_encode(self::items(), JSON_UNESCAPED_UNICODE),
            $catatan,
        ]);
        return ['ok' => true, 'id' => (int)$db->lastInsertId(), 'skipped' => false];
    }

    public static function listQueue(?string $status = null): array
    {
        $db = Database::get();
        if ($status && in_array($status, ['pending','shipped','delivered','cancelled'], true)) {
            $st = $db->prepare(
                "SELECT wk.*, o.nama_outlet, t.nama_perusahaan
                   FROM saas_welcome_kit wk
                   JOIN outlets o ON o.id = wk.outlet_id
                   JOIN tenants t ON t.id = wk.tenant_id
                  WHERE wk.status=? ORDER BY wk.created_at DESC"
            );
            $st->execute([$status]);
        } else {
            $st = $db->query(
                "SELECT wk.*, o.nama_outlet, t.nama_perusahaan
                   FROM saas_welcome_kit wk
                   JOIN outlets o ON o.id = wk.outlet_id
                   JOIN tenants t ON t.id = wk.tenant_id
                  ORDER BY FIELD(wk.status,'pending','shipped','delivered','cancelled'), wk.created_at DESC"
            );
        }
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function markShipped(int $id, string $kurir, string $resi): bool
    {
        $db = Database::get();
        return $db->prepare(
            "UPDATE saas_welcome_kit SET status='shipped', kurir=?, resi=?, shipped_at=NOW() WHERE id=? AND status IN ('pending','shipped')"
        )->execute([substr(trim($kurir),0,60), substr(trim($resi),0,80), $id]);
    }

    public static function markDelivered(int $id): bool
    {
        $db = Database::get();
        return $db->prepare(
            "UPDATE saas_welcome_kit SET status='delivered', delivered_at=NOW() WHERE id=? AND status IN ('shipped','pending')"
        )->execute([$id]);
    }

    public static function statusForOutlet(int $outletId): ?array
    {
        $db = Database::get();
        $st = $db->prepare("SELECT status, kurir, resi, items_json FROM saas_welcome_kit WHERE outlet_id=? ORDER BY id DESC LIMIT 1");
        $st->execute([$outletId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
```

- [ ] **Step 4: Jalankan test, pastikan LULUS**

Run: `php tests/welcomekit/test_welcome_kit.php`
Expected: semua `PASS`; `OK test_welcome_kit`.

- [ ] **Step 5: Lint + commit**

Run: `php -l core/WelcomeKit.php`
```bash
git add core/WelcomeKit.php tests/welcomekit/test_welcome_kit.php
git commit -m "feat(welcomekit): core/WelcomeKit.php — create idempoten + snapshot + status kirim + queue"
```

---

### Task 3: Trigger di PaymentSettler

**Files:**
- Modify: `core/PaymentSettler.php` (`settleOutletActivation` + `settleSetupFee`)
- Test: `tests/welcomekit/test_welcome_kit.php` (tambah assert settle membuat kit)

**Interfaces:**
- Consumes: `WelcomeKit::enabled()`, `WelcomeKit::createForOutlet(...)`.
- Produces: settle sukses juga membuat 1 welcome_kit (bila enabled), best-effort.

- [ ] **Step 1: Tambah test settle→kit**

Di `tests/welcomekit/test_welcome_kit.php`, sebelum cleanup, tambah:

```php
// (f) settleOutletActivation membuat welcome_kit
require_once dirname(__DIR__, 2) . '/core/PaymentSettler.php';
$db->prepare("UPDATE outlets SET status='pending' WHERE id=?")->execute([$oid]);
$db->prepare("INSERT INTO saas_payments (tenant_id, type, amount, status, ref_outlet_id, order_id, created_at, expires_at)
              VALUES (?, 'outlet_activation', 800000, 'paid', ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))")
   ->execute([$tid, $oid, 'WK-ORD3-'.$tid]);
$pid3 = (int)$db->lastInsertId();
$res = PaymentSettler::settleOutletActivation((function() use ($db,$pid3){ $s=$db->prepare("SELECT * FROM saas_payments WHERE id=?"); $s->execute([$pid3]); return $s->fetch(PDO::FETCH_ASSOC); })());
ok(!empty($res['ok']), 'settleOutletActivation ok');
$kitCnt = (int)$db->query("SELECT COUNT(*) FROM saas_welcome_kit WHERE payment_id=".$pid3)->fetchColumn();
eqv($kitCnt, 1, 'settle membuat 1 welcome_kit');
```

> Catatan: `settleOutletActivation` menerima array `$payment`. Panggil dengan row `saas_payments`. Sesuaikan bila signature aktual berbeda (cek file).

- [ ] **Step 2: Jalankan, pastikan GAGAL**

Run: `php tests/welcomekit/test_welcome_kit.php`
Expected: FAIL — `settle membuat 1 welcome_kit` (belum ada trigger).

- [ ] **Step 3: Implement trigger di `settleOutletActivation`**

Di `core/PaymentSettler.php`, di dalam `settleOutletActivation`, setelah `$db->commit();` (setelah blok kredit coin), tambah:

```php
            // Welcome kit fisik (best-effort, tidak menggagalkan settle)
            try {
                require_once __DIR__ . '/WelcomeKit.php';
                if (WelcomeKit::enabled()) {
                    WelcomeKit::createForOutlet(Database::get(), (int)$payment['tenant_id'], (int)$payment['ref_outlet_id'], (int)$payment['id'], 'outlet_activation');
                }
            } catch (Throwable $e) { error_log('[WelcomeKit settleOutletActivation] ' . $e->getMessage()); }
```

- [ ] **Step 4: Implement trigger di `settleSetupFee`**

Di `settleSetupFee`, setelah tenant di-set active + coin di-seed (setelah commit sukses), tambah (resolve outlet utama):

```php
            // Welcome kit fisik outlet utama (best-effort)
            try {
                require_once __DIR__ . '/WelcomeKit.php';
                if (WelcomeKit::enabled()) {
                    $db2 = Database::get();
                    $moSt = $db2->prepare("SELECT id FROM outlets WHERE tenant_id=? ORDER BY is_main DESC, id ASC LIMIT 1");
                    $moSt->execute([$payment['tenant_id']]);
                    $mainOutletId = (int)$moSt->fetchColumn();
                    if ($mainOutletId > 0) {
                        WelcomeKit::createForOutlet($db2, (int)$payment['tenant_id'], $mainOutletId, (int)$payment['id'], 'setup_fee');
                    }
                }
            } catch (Throwable $e) { error_log('[WelcomeKit settleSetupFee] ' . $e->getMessage()); }
```

> Tempatkan setelah transaksi settle commit (jangan di dalam transaksi utama), agar kegagalan kit tak me-rollback settle. Cek struktur `settleSetupFee` untuk titik yang tepat (setelah `$db->commit()` sukses).

- [ ] **Step 5: Jalankan test, pastikan LULUS**

Run: `php tests/welcomekit/test_welcome_kit.php`
Expected: semua `PASS`, termasuk `settle membuat 1 welcome_kit`.

- [ ] **Step 6: Lint + commit**

Run: `php -l core/PaymentSettler.php`
```bash
git add core/PaymentSettler.php tests/welcomekit/test_welcome_kit.php
git commit -m "feat(welcomekit): trigger create kit di settleSetupFee + settleOutletActivation (best-effort)"
```

---

### Task 4: add-outlet.php — alamat lengkap wajib

**Files:**
- Modify: `add-outlet.php` (form fields, validasi, INSERT, validate helper)
- Test: `tests/welcomekit/test_addr_validate.php` (CREATE)

**Interfaces:**
- Produces: fungsi `aoValidateAddress(array $post): array` (return list pesan error, kosong bila valid).

- [ ] **Step 1: Tulis test validate (gagal dulu)**

Buat `tests/welcomekit/test_addr_validate.php`:

```php
<?php
require __DIR__ . '/../_assert.php';
require_once dirname(__DIR__, 2) . '/add-outlet.php'; // memuat definisi fungsi (guard: file harus aman di-include tanpa auto-run — lihat catatan)

$valid = ['nama_outlet'=>'Outlet A','penerima'=>'Budi','telepon'=>'08123456789','alamat'=>'Jl. Uji No 1','kota'=>'Bandung','kode_pos'=>'40111'];
eqv(aoValidateAddress($valid), [], 'alamat lengkap → tanpa error');
ok(count(aoValidateAddress(array_merge($valid,['penerima'=>'']))) > 0, 'penerima kosong → error');
ok(count(aoValidateAddress(array_merge($valid,['kode_pos'=>'']))) > 0, 'kode_pos kosong → error');
ok(count(aoValidateAddress(array_merge($valid,['alamat'=>'']))) > 0, 'alamat kosong → error');
echo "OK test_addr_validate\n";
```

> Catatan implementer: `add-outlet.php` menjalankan logika saat di-request. Untuk bisa di-`require` di test tanpa efek samping, bungkus definisi `aoValidateAddress()` agar berdiri sendiri; bila `require` seluruh file memicu output/redirect, pindahkan HANYA fungsi `aoValidateAddress()` ke bagian atas file yang aman, atau gunakan pendekatan test yang meng-`include` dalam guard `php_sapi_name()==='cli'`. Tujuannya: fungsi validasi teruji tanpa menjalankan handler request. Jika sulit, definisikan `aoValidateAddress()` di file terpisah kecil `add-outlet-validate.php` yang di-`require` oleh add-outlet.php dan oleh test.

- [ ] **Step 2: Jalankan, pastikan GAGAL**

Run: `php tests/welcomekit/test_addr_validate.php`
Expected: FAIL — `aoValidateAddress` belum ada.

- [ ] **Step 3: Tambah fungsi validasi + pakai di handler**

Buat `add-outlet-validate.php`:

```php
<?php
/** Validasi alamat lengkap wajib untuk pengiriman welcome kit. Return list pesan error. */
function aoValidateAddress(array $post): array
{
    $errors = [];
    $penerima = trim($post['penerima'] ?? '');
    $telepon  = trim($post['telepon'] ?? '');
    $alamat   = trim($post['alamat'] ?? '');
    $kota     = trim($post['kota'] ?? '');
    $kodePos  = trim($post['kode_pos'] ?? '');
    if (strlen($penerima) < 2)          $errors[] = 'Nama penerima wajib diisi.';
    if (!preg_match('/\d{8,}/', preg_replace('/\D/','',$telepon))) $errors[] = 'No. HP penerima wajib (min 8 digit).';
    if (strlen($alamat) < 8)            $errors[] = 'Alamat lengkap wajib diisi (min 8 karakter).';
    if (strlen($kota) < 2)              $errors[] = 'Kota/Kabupaten wajib diisi.';
    if (!preg_match('/^\d{5}$/', $kodePos)) $errors[] = 'Kode pos wajib 5 digit.';
    return $errors;
}
```

Di `add-outlet.php` bagian atas (setelah require lain), tambah `require_once __DIR__ . '/add-outlet-validate.php';`.

Di handler `step1_submit` (setelah baca `$kota/$alamat/$telepon`, tambah baca `$penerima`, `$kodePos`), ganti blok validasi nama-only menjadi:

```php
    $penerima = trim($_POST['penerima'] ?? '');
    $kodePos  = trim($_POST['kode_pos'] ?? '');
    $addrErr  = aoValidateAddress($_POST);
    if (strlen($namaOutlet) < 3) {
        $error = 'Nama outlet minimal 3 karakter.';
    } elseif (strlen($namaOutlet) > 80) {
        $error = 'Nama outlet maksimal 80 karakter.';
    } elseif (!empty($addrErr)) {
        $error = implode(' ', $addrErr);
    } else {
        $d['nama_outlet'] = $namaOutlet;
        $d['kota']        = $kota;
        $d['alamat']      = $alamat;
        $d['telepon']     = $telepon;
        $d['penerima']    = $penerima;
        $d['kode_pos']    = $kodePos;
        $d['mode']        = $mode;
        $w['step'] = 2; $step = 2;
        $_SESSION['ao_csrf'] = bin2hex(random_bytes(32));
    }
```

Di INSERT outlet (step2), tambah kolom `penerima, kode_pos` dan nilainya `$d['penerima']`, `$d['kode_pos']` (non-null).

Di form (step1), tambah field Penerima & Kode Pos, dan ubah label Kota/Alamat/Telepon dari `(opsional)` menjadi wajib `<span class="req">*</span>` + atribut `required`:

```php
          <div class="field">
            <label>Nama Penerima <span class="req">*</span></label>
            <input type="text" name="penerima" required maxlength="120"
                   value="<?= htmlspecialchars($d['penerima'] ?? '') ?>" placeholder="cth: Budi (PIC outlet)">
          </div>
```
(dan `required` pada `kota`, `alamat`, `telepon`; tambah field `kode_pos` required `pattern="\d{5}"` inputmode numeric.)

- [ ] **Step 4: Jalankan test, pastikan LULUS + lint**

Run: `php tests/welcomekit/test_addr_validate.php` → semua PASS.
Run: `php -l add-outlet.php && php -l add-outlet-validate.php` → bersih.

- [ ] **Step 5: Commit**

```bash
git add add-outlet.php add-outlet-validate.php tests/welcomekit/test_addr_validate.php
git commit -m "feat(welcomekit): alamat outlet lengkap wajib di add-outlet (penerima+HP+alamat+kota+kodepos) + validate helper"
```

---

### Task 5: registration_wizard.php — alamat wajib

**Files:**
- Modify: `superadmin/registration_wizard.php` (field alamat + simpan ke outlet INSERT)

**Interfaces:**
- Consumes: `aoValidateAddress` (opsional) atau validasi inline setara.

- [ ] **Step 1: Baca struktur pembuatan outlet di wizard**

Run: `grep -n "INSERT INTO outlets\|name=\"kota\"\|name=\"alamat\"\|name=\"nama_outlet\"\|wiz\['kota'\]" superadmin/registration_wizard.php`
Expected: temukan step form outlet + INSERT (sekitar baris 275).

- [ ] **Step 2: Tambah field alamat wajib di form wizard**

Di step yang mengumpulkan data outlet (dekat `name="kota"`), tambah input **wajib**: `penerima`, `alamat`, `kode_pos`, dan pastikan `owner_wa`/telepon tersedia sebagai HP. Simpan ke `$wiz['penerima']`, `$wiz['alamat']`, `$wiz['kode_pos']`, `$wiz['kota']` (baca dari POST dgn `strip_tags`+`substr` seperti field lain di file).

- [ ] **Step 3: Validasi server sebelum lanjut step**

Di handler step outlet, tolak lanjut bila `penerima/alamat/kota/kode_pos` kosong (pola `require_once dirname(__DIR__).'/add-outlet-validate.php'; $e=aoValidateAddress([...]);` atau cek manual). Tampilkan error.

- [ ] **Step 4: Simpan ke INSERT outlets**

Ubah `INSERT INTO outlets (... kota ...)` menjadi menyertakan `alamat, telepon, penerima, kode_pos` dengan nilai dari `$wizard`.

- [ ] **Step 5: Lint + commit**

Run: `php -l superadmin/registration_wizard.php`
```bash
git add superadmin/registration_wizard.php
git commit -m "feat(welcomekit): alamat outlet lengkap wajib di wizard registrasi SA"
```

---

### Task 6: superadmin/welcome_kit.php — antrian + config + sidebar

**Files:**
- Create: `superadmin/welcome_kit.php`
- Modify: `superadmin/superadmin_components.php` (link sidebar)

**Interfaces:**
- Consumes: `WelcomeKit::listQueue/markShipped/markDelivered/items`, `BillingConfig::get/set`, `saVerifyCsrf()`, `superadmin_guard`, `superadmin_components`.

- [ ] **Step 1: Buat `superadmin/welcome_kit.php` (mirror packages.php)**

Struktur (guard + API layer + HTML). API actions:
- GET `list` (`?status=`) → `['ok'=>true,'rows'=>WelcomeKit::listQueue($status)]`.
- GET `get_config` → `['ok'=>true,'enabled'=>BillingConfig::getInt('welcome_kit_enabled',1),'items'=>WelcomeKit::items()]`.
- POST `mark_shipped` (setelah `saVerifyCsrf()`): body `{id,kurir,resi}` → validasi non-kosong → `WelcomeKit::markShipped` → `['ok'=>true]`.
- POST `mark_delivered`: `{id}` → `WelcomeKit::markDelivered`.
- POST `save_config`: `{enabled, items:[{nama,qty}]}` → validasi → `BillingConfig::set('welcome_kit_enabled', $enabled?'1':'0', $sa)` + `BillingConfig::set('welcome_kit_items', json_encode($cleanItems), $sa)`.

Contoh kerangka API (ikuti pola `packages.php`):

```php
<?php
if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';
require_once dirname(__DIR__) . '/core/BillingConfig.php';
require_once dirname(__DIR__) . '/core/WelcomeKit.php';
date_default_timezone_set('Asia/Jakarta');

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    if ($action === 'list') { echo json_encode(['ok'=>true,'rows'=>WelcomeKit::listQueue($_GET['status'] ?? null)]); exit; }
    if ($action === 'get_config') { echo json_encode(['ok'=>true,'enabled'=>BillingConfig::getInt('welcome_kit_enabled',1),'items'=>WelcomeKit::items()]); exit; }
    saVerifyCsrf();
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $sa = (int)($_SESSION['superadmin_id'] ?? 0) ?: null;
    if ($action === 'mark_shipped') {
        $id=(int)($d['id']??0); $kurir=trim($d['kurir']??''); $resi=trim($d['resi']??'');
        if (!$id || $kurir==='' || $resi==='') { echo json_encode(['error'=>'Kurir & resi wajib.']); exit; }
        WelcomeKit::markShipped($id,$kurir,$resi); echo json_encode(['ok'=>true,'msg'=>'Ditandai dikirim.']); exit;
    }
    if ($action === 'mark_delivered') { WelcomeKit::markDelivered((int)($d['id']??0)); echo json_encode(['ok'=>true,'msg'=>'Ditandai terkirim.']); exit; }
    if ($action === 'save_config') {
        $enabled = !empty($d['enabled']) ? '1':'0';
        $items=[]; foreach ((array)($d['items']??[]) as $it){ $n=trim($it['nama']??''); if($n==='')continue; $items[]=['nama'=>substr($n,0,120),'qty'=>max(1,(int)($it['qty']??1))]; }
        BillingConfig::set('welcome_kit_enabled',$enabled,$sa);
        BillingConfig::set('welcome_kit_items', json_encode($items, JSON_UNESCAPED_UNICODE), $sa);
        echo json_encode(['ok'=>true,'msg'=>'Config kit disimpan.']); exit;
    }
    echo json_encode(['error'=>'Aksi tak dikenal']); exit;
}

$activePage = 'welcome_kit';
// ... render header (mirror packages.php: renderSaHeader/echo layout), tab Antrian + tab Config,
//     tabel antrian (nama_outlet, tenant, alamat lengkap, isi kit, status, tombol Dikirim/Terkirim),
//     modal input kurir+resi, editor items (list nama+qty tambah/hapus), toggle enabled.
```

Untuk HTML/JS: mirror gaya `packages.php` (saFetch, saShowToast, modal). Tabel antrian **wajib** dibungkus `overflow-x:auto` + header nowrap (alamat panjang). Tampilkan `items_json` sebagai daftar ringkas. Tombol status: pending→"📦 Dikirim" (modal kurir+resi), shipped→"✅ Terkirim". Filter by status. Editor config: baris item (input nama + qty) dgn tombol tambah/hapus + toggle enabled + Simpan.

- [ ] **Step 2: Tambah link sidebar**

Di `superadmin/superadmin_components.php`, dekat link `packages.php` (baris ~723), tambah:
```php
        <a href="/superadmin/welcome_kit.php" class="sa-nav-link <?= $activePage === 'welcome_kit' ? 'active' : '' ?>">
          <span>📦</span> Welcome Kit
        </a>
```
(sesuaikan markup ikon/label dgn pola link lain di file.)

- [ ] **Step 3: Lint**

Run: `php -l superadmin/welcome_kit.php && php -l superadmin/superadmin_components.php`
Expected: bersih.

- [ ] **Step 4: Verifikasi manual (MCP/browser, saat integrasi)**

Login SA → Welcome Kit → tab Config: ubah item + simpan → reload persist. Tab Antrian tampil (kosong bila belum ada). Catat ke E2E.

- [ ] **Step 5: Commit**

```bash
git add superadmin/welcome_kit.php superadmin/superadmin_components.php
git commit -m "feat(welcomekit): halaman SA Welcome Kit — antrian fulfillment (dikirim/terkirim) + editor isi kit + sidebar"
```

---

### Task 7: Status kit sisi owner

**Files:**
- Modify: `add-outlet.php` (halaman sukses/pending setelah buat outlet) — tampil ringkasan kit yang akan diterima

**Interfaces:**
- Consumes: `WelcomeKit::enabled()`, `WelcomeKit::items()`, `WelcomeKit::statusForOutlet($outletId)`.

- [ ] **Step 1: Tampilkan info kit di konfirmasi owner**

Di `add-outlet.php` step 2 (blok "Yang kamu dapatkan" dari fitur activation-coin) tambah item kit bila `WelcomeKit::enabled()`:
```php
<?php require_once __DIR__.'/core/WelcomeKit.php'; if (WelcomeKit::enabled() && WelcomeKit::items()): ?>
<li><strong>🎁 Welcome kit fisik</strong> dikirim ke alamat outlet: <?= htmlspecialchars(implode(', ', array_map(fn($i)=>$i['qty'].'× '.$i['nama'], WelcomeKit::items()))) ?></li>
<?php endif; ?>
```

- [ ] **Step 2: Tampilkan status kit di halaman "menunggu konfirmasi"/detail outlet**

Di halaman setelah outlet dibuat (paid → pending pembayaran, atau outlet detail bila ada), tampilkan `WelcomeKit::statusForOutlet($outletId)` bila ada: pending→"Welcome kit sedang disiapkan", shipped→"Dikirim via {kurir}, resi {resi}", delivered→"Terkirim". (Bila tak ada halaman detail outlet yang cocok, cukup tampilkan di konfirmasi/aktivasi — catat sebagai keputusan.)

- [ ] **Step 3: Lint + commit**

Run: `php -l add-outlet.php`
```bash
git add add-outlet.php
git commit -m "feat(welcomekit): tampilkan welcome kit + status di konfirmasi/aktivasi outlet (owner)"
```

---

### Task 8: Integrasi — full test + deploy

- [ ] **Step 1: Pull latest**

Run: `git pull --rebase origin main` (selesaikan konflik hanya pada file plan ini bila ada).

- [ ] **Step 2: Full test + lint**

Run:
```bash
php tests/welcomekit/test_welcome_kit.php && php tests/welcomekit/test_addr_validate.php && \
php -l core/WelcomeKit.php && php -l core/PaymentSettler.php && php -l add-outlet.php && \
php -l add-outlet-validate.php && php -l superadmin/welcome_kit.php && php -l superadmin/registration_wizard.php && \
php -l superadmin/superadmin_components.php
```
Expected: semua `PASS` / `No syntax errors`.

- [ ] **Step 3: Push (deploy)**

```bash
git push origin main
```

- [ ] **Step 4: Verifikasi produksi**

SA → Welcome Kit muncul di sidebar + config editable. Add-outlet menolak alamat tak lengkap. (Aktivasi berbayar nyata → kit muncul di antrian: verifikasi saat ada transaksi.)

---

## Manual E2E (USER)

- [ ] SA atur isi kit → aktifkan outlet berbayar → kit muncul di antrian SA dgn alamat + isi benar → mark Dikirim (kurir+resi) → owner lihat status.
- [ ] add-outlet: submit tanpa penerima/kode pos → ditolak.
- [ ] welcome_kit_enabled = off → aktivasi tidak membuat record.

## Self-Review

**Spec coverage:**
- Kolom alamat + tabel kit + config → Task 1 ✓
- WelcomeKit domain (create idempoten, snapshot, status, queue, items) → Task 2 ✓
- Trigger settle (setup_fee resolve main outlet + outlet_activation, best-effort) → Task 3 ✓
- Alamat wajib add-outlet (+validate helper) → Task 4 ✓
- Alamat wajib wizard → Task 5 ✓
- SA page antrian + config + sidebar → Task 6 ✓
- Owner status view → Task 7 ✓
- Idempotency, disabled no-op, alamat belum lengkap catatan, kit-error tak gagalkan settle → Task 2/3 tests ✓
- Out of scope (ongkir/API/inventori/notif/per-tier/retroaktif/trial) → tidak ada task yang melanggar ✓

**Placeholder scan:** Tidak ada TBD. Catatan implementer (verifikasi skema sintetis, cara include add-outlet untuk test) = instruksi konkret, bukan placeholder logika. Task 6 HTML diberi kerangka API lengkap + arahan mirror packages.php; bila reviewer menilai kurang detail pada markup, itu bagian yang mengikuti pola file existing.

**Type consistency:** `WelcomeKit::createForOutlet(PDO,int,int,?int,string)`, `items()` (nama/qty), `statusForOutlet`, config keys `welcome_kit_enabled`/`welcome_kit_items`, status enum — konsisten Task 1–7. `aoValidateAddress(array):array` konsisten Task 4–5.

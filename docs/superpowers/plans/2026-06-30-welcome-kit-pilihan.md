# Welcome Kit — Model Pilihan Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Owner memilih welcome kit dari beberapa opsi gratis (dikonfigurasi SA) di konfirmasi Tambah Outlet; pilihan di-snapshot saat kit dibuat pada settle.

**Architecture:** Config `welcome_kit_options` (array opsi) menggantikan `welcome_kit_items` tunggal. Owner memilih key opsi → disimpan di `outlets.welcome_kit_choice`. `WelcomeKit::createForOutlet` membaca choice → snapshot nama+items opsi terpilih (fallback default) ke `saas_welcome_kit` (+kolom `kit_nama`). Idempotency & best-effort tak berubah.

**Tech Stack:** PHP 8, MariaDB (`saas_billing_config`, `saas_welcome_kit`, `outlets`), `core/WelcomeKit.php`, `core/BillingConfig.php`. Test: PHP CLI + `tests/_assert.php`.

## Global Constraints

- Config key `welcome_kit_options` (JSON array): tiap opsi `{"key":str,"nama":str,"items":[{"nama":str,"qty":int}],"default":bool}`. `welcome_kit_enabled` tetap.
- Migrasi: `welcome_kit_items` lama → 1 opsi `{"key":"standar","nama":"Standar","default":true,"items":<lama>}`.
- Owner pilih hanya mode **paid** (trial tak dapat kit). Pilihan disimpan `outlets.welcome_kit_choice` (= key), server-validasi key ada di options (else default).
- Snapshot saat settle: `kit_nama` + `items_json` dari opsi terpilih; fallback `defaultOption()`; bila tak ada opsi → tak buat record.
- Idempoten `payment_id` UNIQUE + best-effort post-commit — TIDAK diubah.
- Back-compat: bila hanya `welcome_kit_items` yang ada, `options()` membungkusnya jadi 1 opsi default.
- 1 opsi → picker auto-select (tanpa radio). `enabled=off`/trial → picker disembunyikan.
- Semua opsi gratis & setara (tanpa tier/harga).
- `php -l` semua PHP yang disentuh bersih. Kolom `trigger` (jika muncul di SQL) tetap di-backtick. Auto-deploy dari `main`; sesi paralel aktif → kerja di worktree terisolasi.

---

## File Structure

| File | Aksi | Tanggung jawab |
|---|---|---|
| `outlets` (DB) | ALTER | + `welcome_kit_choice` |
| `saas_welcome_kit` (DB) | ALTER | + `kit_nama` |
| `saas_billing_config` (DB) | DATA | migrasi `welcome_kit_items` → `welcome_kit_options` |
| `core/WelcomeKit.php` | MODIFY | `options/defaultOption/optionByKey/resolveChoiceKey`, createForOutlet snapshot choice |
| `add-outlet.php` | MODIFY | picker opsi di step 2 + simpan choice |
| `superadmin/welcome_kit.php` | MODIFY | editor multi-opsi + queue kit_nama |
| `superadmin/sql/welcome_kit_options_migration.sql` | CREATE | migrasi reproducible |
| `tests/welcomekit/test_welcome_kit_options.php` | CREATE | unit opsi + createForOutlet choice |

---

### Task 1: Schema + migrasi config

**Files:**
- Data: `outlets` ALTER, `saas_welcome_kit` ALTER, `saas_billing_config` migrasi
- Create: `superadmin/sql/welcome_kit_options_migration.sql`
- Test: `tests/welcomekit/test_welcome_kit_options.php` (bagian schema)

**Interfaces:**
- Produces: `outlets.welcome_kit_choice` (VARCHAR 40), `saas_welcome_kit.kit_nama` (VARCHAR 80), config `welcome_kit_options` (JSON array, ≥1 opsi default).

- [ ] **Step 1: Tulis test schema (gagal dulu)**

Buat `tests/welcomekit/test_welcome_kit_options.php`:

```php
<?php
require __DIR__ . '/../_assert.php';
if (!defined('DB_HOST')) {
    $mycnf = parse_ini_file(($_SERVER['HOME'] ?? getenv('HOME')) . '/.my.cnf') ?: [];
    define('DB_HOST', $mycnf['host'] ?? 'localhost');
    define('DB_USER', $mycnf['user'] ?? '');
    define('DB_PASS', $mycnf['password'] ?? '');
    define('DB_NAME', $mycnf['database'] ?? '');
    if (!defined('ROOT')) define('ROOT', dirname(__DIR__, 2));
}
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/BillingConfig.php';
$db = Database::get();

$oc = $db->query("SHOW COLUMNS FROM outlets")->fetchAll(PDO::FETCH_COLUMN);
ok(in_array('welcome_kit_choice', $oc), 'outlets.welcome_kit_choice ada');
$wc = $db->query("SHOW COLUMNS FROM saas_welcome_kit")->fetchAll(PDO::FETCH_COLUMN);
ok(in_array('kit_nama', $wc), 'saas_welcome_kit.kit_nama ada');

$opts = json_decode(BillingConfig::get('welcome_kit_options', '[]'), true);
ok(is_array($opts) && count($opts) >= 1, 'welcome_kit_options berisi >=1 opsi');
ok(!empty(array_filter($opts, fn($o) => !empty($o['default']))), 'ada opsi default');

echo "OK test_welcome_kit_options (schema)\n";
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

Run: `php tests/welcomekit/test_welcome_kit_options.php`
Expected: FAIL — `outlets.welcome_kit_choice ada` gagal.

- [ ] **Step 3: Buat migrasi SQL + jalankan**

Buat `superadmin/sql/welcome_kit_options_migration.sql`:

```sql
-- Welcome Kit model pilihan — migration. Idempoten.
ALTER TABLE outlets
  ADD COLUMN IF NOT EXISTS welcome_kit_choice VARCHAR(40) NULL AFTER kode_pos;
ALTER TABLE saas_welcome_kit
  ADD COLUMN IF NOT EXISTS kit_nama VARCHAR(80) NULL AFTER items_json;

-- Migrasi config: welcome_kit_items (tunggal) → welcome_kit_options (1 opsi default 'standar').
-- Hanya set welcome_kit_options jika belum ada.
INSERT INTO saas_billing_config (key_name, value_text, description)
SELECT 'welcome_kit_options',
       CONCAT('[{"key":"standar","nama":"Standar","default":true,"items":',
              COALESCE((SELECT value_text FROM (SELECT value_text FROM saas_billing_config WHERE key_name='welcome_kit_items') x), '[]'),
              '}]'),
       'Opsi welcome kit (JSON array: key/nama/items/default)'
WHERE NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM saas_billing_config WHERE key_name='welcome_kit_options') y);
```

Run: `/opt/homebrew/opt/mysql-client/bin/mysql < superadmin/sql/welcome_kit_options_migration.sql`
Expected: tidak ada error.

> Catatan: subquery dibungkus (`SELECT ... FROM (SELECT ...) x`) karena MySQL melarang subquery ke tabel yang sama dengan INSERT target. Bila migrasi ini gagal, jalankan bagian ALTER dan INSERT config secara terpisah, dan sesuaikan sintaks ke MariaDB versi live.

- [ ] **Step 4: Verifikasi + test lulus**

Run: `php tests/welcomekit/test_welcome_kit_options.php`
Expected: semua `PASS`; `OK test_welcome_kit_options (schema)`. Verifikasi `welcome_kit_options` berisi opsi "standar" dengan items lama.

- [ ] **Step 5: Commit**

```bash
git add superadmin/sql/welcome_kit_options_migration.sql tests/welcomekit/test_welcome_kit_options.php
git commit -m "feat(welcomekit): schema pilihan — outlets.welcome_kit_choice + saas_welcome_kit.kit_nama + migrasi welcome_kit_options"
```

---

### Task 2: WelcomeKit — options + snapshot choice

**Files:**
- Modify: `core/WelcomeKit.php`
- Test: `tests/welcomekit/test_welcome_kit_options.php` (tambah bagian logic)

**Interfaces:**
- Consumes: `BillingConfig::get('welcome_kit_options', ...)`, `welcome_kit_items` (back-compat), `outlets.welcome_kit_choice`.
- Produces:
  - `WelcomeKit::options(): array` — list opsi tervalidasi `['key'=>str,'nama'=>str,'items'=>[['nama','qty']],'default'=>bool]`; back-compat dari `welcome_kit_items`; invalid → `[]`
  - `WelcomeKit::defaultOption(): ?array`
  - `WelcomeKit::optionByKey(string $key): ?array`
  - `WelcomeKit::resolveChoiceKey(?string $key): ?string` — key valid, else key default, else null
  - `createForOutlet` snapshot `kit_nama` + items opsi terpilih (dari `outlets.welcome_kit_choice`, fallback default); bila tak ada opsi → skip.

- [ ] **Step 1: Tambah test logic (gagal dulu)**

Di `tests/welcomekit/test_welcome_kit_options.php`, sebelum `echo "OK ...`, tambah:

```php
require_once dirname(__DIR__, 2) . '/core/WelcomeKit.php';

// Set config opsi uji
$optsCfg = '[{"key":"standar","nama":"Standar","default":true,"items":[{"nama":"Roll thermal","qty":2}]},'
         . '{"key":"printer","nama":"Paket Printer","items":[{"nama":"Roll thermal","qty":4}]}]';
BillingConfig::set('welcome_kit_options', $optsCfg, null);

ok(count(WelcomeKit::options()) === 2, 'options() = 2 opsi');
eqv(WelcomeKit::defaultOption()['key'], 'standar', 'defaultOption = standar');
eqv(WelcomeKit::optionByKey('printer')['nama'], 'Paket Printer', 'optionByKey printer');
ok(WelcomeKit::optionByKey('nope') === null, 'optionByKey key tak ada → null');
eqv(WelcomeKit::resolveChoiceKey('printer'), 'printer', 'resolveChoiceKey valid');
eqv(WelcomeKit::resolveChoiceKey('nope'), 'standar', 'resolveChoiceKey invalid → default');
eqv(WelcomeKit::resolveChoiceKey(null), 'standar', 'resolveChoiceKey null → default');

// createForOutlet snapshot choice
$db->beginTransaction();
$db->prepare("INSERT INTO tenants (nama_perusahaan, owner_name, owner_wa, slug, status, coin_balance, created_at)
              VALUES ('WKO-TEST','t','0', ?, 'active', 0, NOW())")->execute(['wko-'.time().rand(100,999)]);
$tid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO outlets (tenant_id, nama_outlet, slug, status, coin_balance, is_main, setup_done, penerima, telepon, alamat, kota, kode_pos, welcome_kit_choice)
              VALUES (?, 'WKO-OUT', ?, 'active', 0, 1, 0, 'Budi', '08123', 'Jl 1', 'Bandung', '40111', 'printer')")
   ->execute([$tid, 'wko-out-'.$tid]);
$oid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO saas_payments (tenant_id, type, amount, status, ref_outlet_id, order_id, created_at, expires_at)
              VALUES (?, 'outlet_activation', 800000, 'paid', ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))")
   ->execute([$tid, $oid, 'WKO-ORD-'.$tid]);
$pid = (int)$db->lastInsertId();
$db->commit();

try {
    $r = WelcomeKit::createForOutlet($db, $tid, $oid, $pid, 'outlet_activation');
    ok(!empty($r['ok']), 'createForOutlet ok');
    $row = $db->query("SELECT kit_nama, items_json FROM saas_welcome_kit WHERE id=".(int)$r['id'])->fetch(PDO::FETCH_ASSOC);
    eqv($row['kit_nama'], 'Paket Printer', 'snapshot kit_nama = pilihan owner');
    ok(strpos($row['items_json'], '"qty":4') !== false, 'snapshot items = opsi printer (4)');

    // choice kosong → default
    $db->prepare("UPDATE outlets SET welcome_kit_choice=NULL WHERE id=?")->execute([$oid]);
    $db->prepare("INSERT INTO saas_payments (tenant_id, type, amount, status, ref_outlet_id, order_id, created_at, expires_at)
                  VALUES (?, 'outlet_activation', 800000, 'paid', ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))")
       ->execute([$tid, $oid, 'WKO-ORD2-'.$tid]);
    $pid2 = (int)$db->lastInsertId();
    $r2 = WelcomeKit::createForOutlet($db, $tid, $oid, $pid2, 'outlet_activation');
    $row2 = $db->query("SELECT kit_nama FROM saas_welcome_kit WHERE id=".(int)$r2['id'])->fetch(PDO::FETCH_ASSOC);
    eqv($row2['kit_nama'], 'Standar', 'choice kosong → opsi default');
} finally {
    $db->prepare("DELETE FROM saas_welcome_kit WHERE tenant_id=?")->execute([$tid]);
    $db->prepare("DELETE FROM saas_payments WHERE tenant_id=?")->execute([$tid]);
    $db->prepare("DELETE FROM outlets WHERE id=?")->execute([$oid]);
    $db->prepare("DELETE FROM tenants WHERE id=?")->execute([$tid]);
}
```

> Catatan implementer: verifikasi kolom `tenants`/`saas_payments`/`outlets` dgn `SHOW COLUMNS` (mirip test welcome_kit existing). Cleanup dalam `finally` (harness `_assert.php` `exit(1)` bypass finally → tambahkan `register_shutdown_function` restore bila menyentuh config global seperti test sebelumnya).

- [ ] **Step 2: Jalankan, pastikan GAGAL**

Run: `php tests/welcomekit/test_welcome_kit_options.php`
Expected: FAIL — `WelcomeKit::options()` belum ada / snapshot kit_nama salah.

- [ ] **Step 3: Implement di `core/WelcomeKit.php`**

Tambah method baru berikut. **Pertahankan `items()` sebagai shim back-compat** (`return self::defaultOption()['items'] ?? []`) agar pemanggil lama (`add-outlet.php`, `welcome_kit.php`, test lama) tidak fatal selama migrasi bertahap — Task 3/4 mengganti pemakaiannya ke `options()`. Blok yang ditambahkan/diubah:

```php
    /** @return array<int,array{key:string,nama:string,items:array,default:bool}> */
    public static function options(): array
    {
        $raw = BillingConfig::get('welcome_kit_options', '');
        $arr = $raw !== '' ? json_decode((string)$raw, true) : null;
        // Back-compat: fallback ke welcome_kit_items (single) → 1 opsi default
        if (!is_array($arr) || !$arr) {
            $legacy = json_decode((string)BillingConfig::get('welcome_kit_items', '[]'), true);
            $items  = self::cleanItems(is_array($legacy) ? $legacy : []);
            return $items ? [['key' => 'standar', 'nama' => 'Standar', 'items' => $items, 'default' => true]] : [];
        }
        $out = [];
        foreach ($arr as $o) {
            if (!is_array($o) || empty($o['nama'])) continue;
            $items = self::cleanItems($o['items'] ?? []);
            if (!$items) continue;
            $out[] = [
                'key'     => (string)($o['key'] ?? self::slugKey($o['nama'])),
                'nama'    => (string)$o['nama'],
                'items'   => $items,
                'default' => !empty($o['default']),
            ];
        }
        // Pastikan tepat satu default (kalau tak ada, opsi pertama)
        if ($out && !array_filter($out, fn($o) => $o['default'])) $out[0]['default'] = true;
        return $out;
    }

    private static function cleanItems($arr): array
    {
        if (!is_array($arr)) return [];
        $out = [];
        foreach ($arr as $it) {
            if (!is_array($it) || empty($it['nama'])) continue;
            $out[] = ['nama' => (string)$it['nama'], 'qty' => max(1, (int)($it['qty'] ?? 1))];
        }
        return $out;
    }

    private static function slugKey(string $nama): string
    {
        $s = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $nama));
        return trim($s, '_') ?: 'kit';
    }

    public static function defaultOption(): ?array
    {
        $opts = self::options();
        foreach ($opts as $o) if ($o['default']) return $o;
        return $opts[0] ?? null;
    }

    public static function optionByKey(string $key): ?array
    {
        foreach (self::options() as $o) if ($o['key'] === $key) return $o;
        return null;
    }

    public static function resolveChoiceKey(?string $key): ?string
    {
        if ($key !== null && $key !== '' && self::optionByKey($key)) return $key;
        $def = self::defaultOption();
        return $def['key'] ?? null;
    }

    // Back-compat shim: pemanggil lama yang butuh daftar item tunggal → item opsi default.
    public static function items(): array
    {
        return self::defaultOption()['items'] ?? [];
    }
```

Di `createForOutlet` (baris ~30): ubah SELECT outlet untuk menyertakan `welcome_kit_choice`, pilih opsi, dan INSERT `kit_nama` + items opsi. Ganti bagian SELECT + INSERT:

```php
        $o = $db->prepare("SELECT penerima, telepon, alamat, kota, kode_pos, welcome_kit_choice FROM outlets WHERE id=? AND tenant_id=?");
        $o->execute([$outletId, $tenantId]);
        $outlet = $o->fetch(PDO::FETCH_ASSOC);
        if (!$outlet) return ['ok' => false, 'id' => null, 'skipped' => true];

        $opt = self::optionByKey((string)($outlet['welcome_kit_choice'] ?? '')) ?? self::defaultOption();
        if (!$opt) return ['ok' => false, 'id' => null, 'skipped' => true]; // tak ada opsi kit
        $kitNama  = $opt['nama'];
        $itemsJson = json_encode($opt['items'], JSON_UNESCAPED_UNICODE);

        $incomplete = (empty($outlet['penerima']) || empty($outlet['alamat']) || empty($outlet['kota']) || empty($outlet['kode_pos']));
        $catatan = $incomplete ? 'alamat belum lengkap — lengkapi sebelum kirim' : null;

        $ins = $db->prepare(
            "INSERT INTO saas_welcome_kit
               (tenant_id, outlet_id, payment_id, `trigger`, penerima, hp, alamat, kota, kode_pos, items_json, kit_nama, status, catatan)
             VALUES (?,?,?,?,?,?,?,?,?,?,?, 'pending', ?)"
        );
        $ins->execute([
            $tenantId, $outletId, $paymentId, $trigger,
            $outlet['penerima'] ?: null, $outlet['telepon'] ?: null,
            $outlet['alamat'] ?: null, $outlet['kota'] ?: null, $outlet['kode_pos'] ?: null,
            $itemsJson, $kitNama, $catatan,
        ]);
        return ['ok' => true, 'id' => (int)$db->lastInsertId(), 'skipped' => false];
```

(Hapus/ubah pemanggilan `self::items()` yang lama di createForOutlet.) Di `statusForOutlet` (baris ~108), tambah `kit_nama` ke SELECT:

```php
        $st = $db->prepare("SELECT status, kurir, resi, items_json, kit_nama FROM saas_welcome_kit WHERE outlet_id=? ORDER BY id DESC LIMIT 1");
```

`listQueue` sudah `SELECT wk.*` → `kit_nama` otomatis ikut.

- [ ] **Step 4: Jalankan test, pastikan LULUS**

Run: `php tests/welcomekit/test_welcome_kit_options.php`
Expected: semua `PASS`; `OK test_welcome_kit_options`.

- [ ] **Step 5: Cek pemakai `items()` yang tersisa**

Run: `grep -rn "WelcomeKit::items(" .`
Expected: hanya di `add-outlet.php` (akan diubah Task 3) dan `superadmin/welcome_kit.php` (Task 4). Bila ada pemakai lain, catat untuk diubah. `items()` sudah dihapus → pemakai lama akan fatal sampai Task 3/4; itu sebabnya urutannya penting.

- [ ] **Step 6: Lint + commit**

Run: `php -l core/WelcomeKit.php`
```bash
git add core/WelcomeKit.php tests/welcomekit/test_welcome_kit_options.php
git commit -m "feat(welcomekit): options/defaultOption/optionByKey + snapshot kit terpilih (kit_nama) di createForOutlet"
```

---

### Task 3: add-outlet — picker + simpan choice

**Files:**
- Modify: `add-outlet.php` (step 2 form picker + `step2_submit` simpan choice + "Yang kamu dapatkan")

**Interfaces:**
- Consumes: `WelcomeKit::enabled()`, `WelcomeKit::options()`, `WelcomeKit::defaultOption()`, `WelcomeKit::resolveChoiceKey()`.

- [ ] **Step 1: Simpan choice di `step2_submit`**

Di `add-outlet.php`, dalam handler `step2_submit` (baris ~139), setelah `$outletId = (int)$db->lastInsertId();` (baris ~173), tambah (hanya mode paid + enabled):

```php
        // Simpan pilihan welcome kit (server-validasi key; else default)
        if (($d['mode'] ?? 'trial') === 'paid' && WelcomeKit::enabled()) {
            $choiceKey = WelcomeKit::resolveChoiceKey($_POST['welcome_kit_choice'] ?? null);
            if ($choiceKey !== null) {
                $db->prepare("UPDATE outlets SET welcome_kit_choice=? WHERE id=?")->execute([$choiceKey, $outletId]);
            }
        }
```

- [ ] **Step 2: Render picker di step 2 (dalam form konfirmasi)**

Cari blok "Yang kamu dapatkan" (baris ~703) dan `<form ...>` konfirmasi (yang memuat tombol `step2_submit`). Tambahkan picker **di dalam form** (sebelum tombol), hanya bila `paid && enabled && count(options) >= 1`:

```php
        <?php
          $wkOpts = (($d['mode'] ?? 'trial') === 'paid' && WelcomeKit::enabled()) ? WelcomeKit::options() : [];
          $wkDefault = WelcomeKit::defaultOption()['key'] ?? '';
        ?>
        <?php if ($wkOpts): ?>
        <div style="margin:14px 0">
          <div style="font-weight:700;color:#0F172A;margin-bottom:8px;font-size:13.5px">🎁 Pilih Welcome Kit (gratis)</div>
          <?php if (count($wkOpts) === 1): ?>
            <input type="hidden" name="welcome_kit_choice" value="<?= htmlspecialchars($wkOpts[0]['key']) ?>">
            <div style="font-size:12.5px;color:#374151">
              <strong><?= htmlspecialchars($wkOpts[0]['nama']) ?></strong>:
              <?= htmlspecialchars(implode(', ', array_map(fn($i)=>$i['qty'].'× '.$i['nama'], $wkOpts[0]['items']))) ?>
            </div>
          <?php else: foreach ($wkOpts as $opt): ?>
            <label style="display:flex;gap:8px;align-items:flex-start;padding:9px 11px;border:1px solid #E5E9F2;border-radius:8px;margin-bottom:6px;cursor:pointer">
              <input type="radio" name="welcome_kit_choice" value="<?= htmlspecialchars($opt['key']) ?>" <?= $opt['key'] === $wkDefault ? 'checked' : '' ?> style="margin-top:3px">
              <span style="font-size:12.5px;color:#374151">
                <strong><?= htmlspecialchars($opt['nama']) ?></strong><br>
                <span style="color:#6B7280"><?= htmlspecialchars(implode(', ', array_map(fn($i)=>$i['qty'].'× '.$i['nama'], $opt['items']))) ?></span>
              </span>
            </label>
          <?php endforeach; endif; ?>
        </div>
        <?php endif; ?>
```

Pastikan blok ini berada **di dalam** `<form method="POST">` konfirmasi (yang punya `name="step2_submit"`), agar `welcome_kit_choice` ikut ter-submit. Bila "Yang kamu dapatkan" saat ini di luar form, letakkan picker di dalam form (dekat tombol).

- [ ] **Step 3: Ganti baris kit di "Yang kamu dapatkan" agar tampilkan opsi (default) alih-alih items()**

Blok lama memanggil `WelcomeKit::items()` (sudah dihapus di Task 2). Ganti baris `<li>` welcome kit (baris ~713) menjadi memakai opsi default sebagai ringkasan (picker di atas yang jadi kontrol utama):

```php
            <?php if (($d['mode'] ?? 'trial') === 'paid' && WelcomeKit::enabled() && WelcomeKit::options()): ?>
            <li><strong>🎁 Welcome kit fisik</strong> dikirim ke alamat outlet (pilih paket di bawah)</li>
            <?php endif; ?>
```

- [ ] **Step 4: Lint + smoke**

Run: `php -l add-outlet.php`
Run: `grep -c "WelcomeKit::items(" add-outlet.php` → Expected `0` (tak ada lagi pemanggilan `items()` yang dihapus).

- [ ] **Step 5: Commit**

```bash
git add add-outlet.php
git commit -m "feat(welcomekit): picker opsi welcome kit di konfirmasi Tambah Outlet + simpan welcome_kit_choice"
```

---

### Task 4: SA welcome_kit.php — editor multi-opsi + queue kit_nama

**Files:**
- Modify: `superadmin/welcome_kit.php`

**Interfaces:**
- Consumes: `WelcomeKit::options()`, `BillingConfig::get/set('welcome_kit_options')`.

- [ ] **Step 1: `get_config` → kembalikan options**

Ganti action `get_config` (baris ~28) agar mengembalikan `options`:

```php
    if ($action === 'get_config') {
        echo json_encode(['ok'=>true, 'enabled'=>BillingConfig::getInt('welcome_kit_enabled',1), 'options'=>WelcomeKit::options()]);
        exit;
    }
```

- [ ] **Step 2: `save_config` → simpan welcome_kit_options**

Ganti action `save_config` (baris ~66) agar menerima `{enabled, options:[{key,nama,items,default}]}` dan menyimpan `welcome_kit_options`:

```php
    if ($action === 'save_config') {
        $enabled = !empty($d['enabled']) ? '1' : '0';
        $options = [];
        $seenKey = [];
        foreach ((array)($d['options'] ?? []) as $o) {
            $nama = trim($o['nama'] ?? '');
            if ($nama === '') continue;
            $items = [];
            foreach ((array)($o['items'] ?? []) as $it) {
                $n = trim($it['nama'] ?? ''); if ($n === '') continue;
                $items[] = ['nama'=>substr($n,0,120), 'qty'=>max(1,(int)($it['qty'] ?? 1))];
            }
            if (!$items) continue;
            $key = trim($o['key'] ?? '');
            if ($key === '' || isset($seenKey[$key])) $key = strtolower(preg_replace('/[^a-z0-9]+/i','_',$nama)).'_'.count($options);
            $seenKey[$key] = 1;
            $options[] = ['key'=>substr($key,0,40), 'nama'=>substr($nama,0,80), 'items'=>$items, 'default'=>!empty($o['default'])];
        }
        if (!$options) { echo json_encode(['error'=>'Minimal 1 opsi kit dengan 1 item.']); exit; }
        // pastikan tepat 1 default
        if (!array_filter($options, fn($o)=>$o['default'])) $options[0]['default'] = true;
        else { $seen=false; foreach ($options as &$o){ if($o['default']&&$seen)$o['default']=false; elseif($o['default'])$seen=true; } unset($o); }
        BillingConfig::set('welcome_kit_enabled', $enabled, $sa);
        BillingConfig::set('welcome_kit_options', json_encode($options, JSON_UNESCAPED_UNICODE), $sa);
        echo json_encode(['ok'=>true, 'msg'=>'Opsi kit disimpan.']); exit;
    }
```

- [ ] **Step 3: UI editor multi-opsi (JS)**

Ganti bagian config editor (fungsi yang memuat `get_config` + `addItemRow`, baris ~447-461) menjadi editor bertingkat: daftar **opsi**, tiap opsi punya nama + radio default + daftar item (nama/qty) + tombol tambah/hapus item, plus tombol "Tambah Opsi". `loadConfig()` render dari `d.options`; `saveConfig()` kumpulkan jadi `{enabled, options:[...]}`.

Contoh struktur JS (mirror pola existing, sesuaikan id/kelas):
```js
function loadConfig(){
  saFetch('welcome_kit.php?action=get_config').then(r=>r.json()).then(d=>{
    document.getElementById('cfgEnabled').checked = d.enabled == 1;
    const box = document.getElementById('optRows'); box.innerHTML='';
    (d.options||[]).forEach(o=>addOptionRow(o));
    if (!(d.options||[]).length) addOptionRow();
  });
}
function addOptionRow(o={}){
  const wrap=document.createElement('div'); wrap.className='opt-block';
  wrap.innerHTML = `
    <div class="opt-head">
      <input type="text" class="opt-nama" placeholder="Nama opsi (cth: Paket Printer)" maxlength="80"/>
      <label class="opt-def"><input type="radio" name="optDefault"/> Default</label>
      <button class="remove-btn" onclick="this.closest('.opt-block').remove()">✕ Opsi</button>
    </div>
    <div class="opt-items"></div>
    <button class="sa-btn sa-btn-outline sa-btn-sm" onclick="addKitItem(this)">+ Item</button>`;
  wrap.querySelector('.opt-nama').value = o.nama || '';
  if (o.default) wrap.querySelector('.opt-def input').checked = true;
  document.getElementById('optRows').appendChild(wrap);
  (o.items||[{}]).forEach(it=>addKitItem(wrap.querySelector('button'), it));
}
function addKitItem(btn, it={}){
  const cont = btn.closest('.opt-block').querySelector('.opt-items');
  const row=document.createElement('div'); row.className='item-row';
  row.innerHTML = `<input type="text" class="it-nama" maxlength="120" placeholder="Nama item"/>
                   <input type="number" class="it-qty" min="1" max="999" value="${parseInt(it.qty)||1}"/>
                   <button class="remove-btn" onclick="this.closest('.item-row').remove()">✕</button>`;
  row.querySelector('.it-nama').value = it.nama || '';
  cont.appendChild(row);
}
function saveConfig(){
  const options=[];
  document.querySelectorAll('#optRows .opt-block').forEach(b=>{
    const nama=b.querySelector('.opt-nama').value.trim(); if(!nama)return;
    const items=[]; b.querySelectorAll('.item-row').forEach(r=>{
      const n=r.querySelector('.it-nama').value.trim(); if(!n)return;
      items.push({nama:n, qty:parseInt(r.querySelector('.it-qty').value)||1});
    });
    if(!items.length)return;
    options.push({nama, items, default:b.querySelector('.opt-def input').checked});
  });
  saFetch('welcome_kit.php?action=save_config',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]')?.content||''},body:JSON.stringify({enabled:document.getElementById('cfgEnabled').checked?1:0, options})})
    .then(r=>r.json()).then(d=>{ if(d.error){saShowToast(d.error,'error');return;} saShowToast(d.msg,'success'); });
}
```
Sesuaikan container id (`optRows`), toolbar "Tambah Opsi" (`addOptionRow()`), dan CSS (`.opt-block`,`.opt-head`,`.opt-items`) mengikuti gaya file. Verifikasi cara file existing mengirim CSRF (mirror-nya).

- [ ] **Step 4: Antrian tampilkan `kit_nama`**

Di `renderQueue` (baris ~363), tambah kolom/kolom-teks **Kit** = `esc(r.kit_nama || '—')` di samping isi (`fmtItems(r.items_json)`). Tambah header `<th>Kit</th>` (nowrap) di tabel antrian.

- [ ] **Step 5: Lint + cek tak ada sisa items tunggal**

Run: `php -l superadmin/welcome_kit.php`
Run: `grep -n "welcome_kit_items" superadmin/welcome_kit.php` → Expected kosong (sudah pindah ke options).

- [ ] **Step 6: Commit**

```bash
git add superadmin/welcome_kit.php
git commit -m "feat(welcomekit): SA editor multi-opsi kit (welcome_kit_options) + kolom Kit di antrian"
```

---

### Task 5: Integrasi — full test + deploy

- [ ] **Step 1: Pull latest**

Run: `git pull --rebase origin main` (selesaikan konflik hanya file plan ini bila ada).

- [ ] **Step 2: Full test + lint**

Run:
```bash
php tests/welcomekit/test_welcome_kit.php && php tests/welcomekit/test_welcome_kit_options.php && \
php tests/welcomekit/test_addr_validate.php && \
php -l core/WelcomeKit.php && php -l add-outlet.php && php -l superadmin/welcome_kit.php
```
Expected: semua `PASS` / `No syntax errors`. (test_welcome_kit lama mungkin memakai `items()`/`welcome_kit_items` — bila gagal karena API berubah, sesuaikan test lama ke `options()` sebagai bagian Task 5.)

- [ ] **Step 3: Push (deploy)**

```bash
git push origin main
```

- [ ] **Step 4: Verifikasi produksi**

SA → Welcome Kit → tab Konfigurasi: buat 2-3 opsi + simpan → reload persist. Add-outlet (paid) → picker tampil opsi + preview. Antrian tampil kolom Kit.

---

## Manual E2E (USER)

- [ ] SA buat 3 opsi kit (Standar/Printer/Packing) → add-outlet paid → picker 3 opsi → pilih Printer → settle → antrian SA tampil "Paket Printer" + 4× roll thermal.
- [ ] 1 opsi → picker auto (tanpa radio). enabled=off → picker hilang.
- [ ] Owner tak ubah pilihan → dapat opsi default.

## Self-Review

**Spec coverage:**
- Config `welcome_kit_options` + migrasi lama→default → Task 1 (migrasi) + Task 2 (options back-compat) ✓
- `outlets.welcome_kit_choice` + `saas_welcome_kit.kit_nama` → Task 1 ✓
- options/defaultOption/optionByKey/resolveChoiceKey → Task 2 ✓
- createForOutlet snapshot choice (fallback default, tak ada opsi→skip) → Task 2 ✓
- Owner picker di step 2 (paid, 1-opsi auto) + simpan choice server-validasi → Task 3 ✓
- SA editor multi-opsi + queue kit_nama → Task 4 ✓
- Edge: enabled off/trial (picker hidden), opsi dihapus→fallback, snapshot beku, back-compat → Task 2/3 ✓
- Wizard pakai default (tak set choice) → otomatis (Task 2 fallback default; wizard tak diubah) ✓
- Testing options + createForOutlet choice → Task 2 ✓; migrasi/deploy → Task 5 ✓

**Placeholder scan:** Tidak ada TBD. Catatan "sesuaikan id/CSS/skema" = instruksi konkret ke pola file existing. Task 5 mencatat kemungkinan test lama perlu disesuaikan ke `options()` — itu tugas eksplisit, bukan placeholder.

**Type consistency:** `options()` bentuk `{key,nama,items:[{nama,qty}],default}`, `resolveChoiceKey(?string):?string`, `optionByKey(string):?array`, kolom `welcome_kit_choice`/`kit_nama`, config `welcome_kit_options` — konsisten Task 1–4. `createForOutlet` signature tak berubah (PDO,int,int,?int,string).

# Loyalty Referral (Ajak Teman) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pelanggan ajak teman via kode/link; saat order pertama teman LUNAS, pengajak & teman dapat bonus poin (besaran diatur owner). Opt-in per owner.

**Architecture:** Tabel baru `hl_referral` + kode di `hl_pelanggan` + config di `tenants`. Atribusi (kode manual di POS/self + link `?ref=`) → record `pending`. Payout idempoten saat order pertama teman LUNAS (hook di titik pelunasan: POS lahir-lunas + orders.php pelunasan), pakai `Loyalty::adjust` yang sudah ada. Class baru `core/Referral.php` memusatkan logika.

**Tech Stack:** PHP 8 / MariaDB. Test: skrip CLI standalone pakai `tests/_assert.php` (`ok()`, `eqv()`), DB nyata + rollback (pola `tests/offline/test_sync_endpoint.php`), `/opt/homebrew/bin/php`.

## Global Constraints

- **Opt-in per owner:** `tenants.referral_enabled` (default 0). Referral JUGA butuh `loyalty_enabled=1` (rewardnya poin). Semua entry (`attribute`, `payout`, UI) cek dua flag ini dulu; mati → no-op diam.
- **Reward:** poin pengajak (`referral_poin_pengajak`) + poin teman (`referral_poin_teman`), diatur owner. Cair HANYA saat order pertama teman **LUNAS** (`status_bayar='lunas'`).
- **Cap:** `referral_max_per_pengajak` (0 = tak terbatas). Saat cap penuh → referral tetap `paid` TAPI poin pengajak = 0, **teman tetap dapat poin**.
- **Anti-abuse:** teman harus pelanggan BARU (belum punya order lunas sebelumnya); tak bisa refer diri sendiri (telepon sama); satu teman sekali saja (UNIQUE referee); payout idempoten (status pending→paid sekali).
- **Payout via `Loyalty::adjust(int $tenantId, int $pelangganId, int $poinDelta, string $note, ?int $userId=null): int`** (signature verified — tulis ke hl_loyalty_log type 'adjust', pakai transaksi sendiri).
- **Multi-tenant:** semua query scoped `tenant_id` dari sesi (TenantResolver), bukan input. Kode di-resolve dalam scope tenant. Endpoint tulis lewat guard + `verifyCsrf()`.
- **Schema wajib diverifikasi:** sebelum commit task yang menyentuh kolom, jalankan `SHOW COLUMNS` (pola wajib repo). `hl_pelanggan` punya `telepon`, `poin_balance`, `referral_code` (Task 1). `hl_transaksi` punya `status_bayar`, `pelanggan_id`.
- DB: mysql client `/opt/homebrew/opt/mysql-client/bin/mysql` (~/.my.cnf → PROD). Migration dijalankan langsung. Parallel session aktif (pos.php/orders.php) → `git pull --no-edit` sebelum push; commit hanya file milik task.

---

### Task 1: Migration — config tenants + referral_code + tabel hl_referral

**Files:**
- Create: `migrations/2026-06-30-loyalty-referral.sql`
- Test: `tests/referral/test_schema.php`

**Interfaces:**
- Produces: `tenants.referral_enabled` (TINYINT default 0), `referral_poin_pengajak` (INT default 0), `referral_poin_teman` (INT default 0), `referral_max_per_pengajak` (INT default 0); `hl_pelanggan.referral_code` (VARCHAR(20) NULL, index); tabel `hl_referral` (lihat spec).

- [ ] **Step 1: Tulis migration**

`migrations/2026-06-30-loyalty-referral.sql`:
```sql
ALTER TABLE tenants
  ADD COLUMN referral_enabled        TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN referral_poin_pengajak  INT NOT NULL DEFAULT 0,
  ADD COLUMN referral_poin_teman     INT NOT NULL DEFAULT 0,
  ADD COLUMN referral_max_per_pengajak INT NOT NULL DEFAULT 0;

ALTER TABLE hl_pelanggan
  ADD COLUMN referral_code VARCHAR(20) NULL,
  ADD KEY idx_referral_code (tenant_id, referral_code);

CREATE TABLE hl_referral (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  referrer_pelanggan_id INT NOT NULL,
  referee_pelanggan_id  INT NOT NULL,
  kode VARCHAR(20) NOT NULL,
  status ENUM('pending','paid','void') NOT NULL DEFAULT 'pending',
  referee_first_order_id INT NULL,
  poin_pengajak INT NOT NULL DEFAULT 0,
  poin_teman    INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at    DATETIME NULL,
  UNIQUE KEY uniq_referee (tenant_id, referee_pelanggan_id),
  KEY idx_referrer (tenant_id, referrer_pelanggan_id),
  KEY idx_status (tenant_id, status)
);
```

- [ ] **Step 2: Tulis test schema (gagal dulu)**

`tests/referral/test_schema.php`:
```php
<?php
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../core/Database.php';
$db = Database::get();
$tcols = $db->query("SHOW COLUMNS FROM tenants")->fetchAll(PDO::FETCH_COLUMN);
foreach (['referral_enabled','referral_poin_pengajak','referral_poin_teman','referral_max_per_pengajak'] as $c)
    ok(in_array($c,$tcols), "tenants.$c ada");
$pcols = $db->query("SHOW COLUMNS FROM hl_pelanggan")->fetchAll(PDO::FETCH_COLUMN);
ok(in_array('referral_code',$pcols), 'hl_pelanggan.referral_code ada');
$rcols = $db->query("SHOW COLUMNS FROM hl_referral")->fetchAll(PDO::FETCH_COLUMN);
foreach (['id','tenant_id','referrer_pelanggan_id','referee_pelanggan_id','kode','status','referee_first_order_id','poin_pengajak','poin_teman','created_at','paid_at'] as $c)
    ok(in_array($c,$rcols), "hl_referral.$c ada");
$idx = array_column($db->query("SHOW INDEX FROM hl_referral")->fetchAll(PDO::FETCH_ASSOC),'Key_name');
ok(in_array('uniq_referee',$idx), 'unique referee ada');
echo "OK test_schema\n";
```

- [ ] **Step 3: Jalankan test → GAGAL**

Run: `/opt/homebrew/bin/php tests/referral/test_schema.php`
Expected: FAIL (kolom/tabel belum ada).

- [ ] **Step 4: Apply migration**

Run: `/opt/homebrew/opt/mysql-client/bin/mysql < migrations/2026-06-30-loyalty-referral.sql`
Expected: tanpa error.

- [ ] **Step 5: Jalankan test → LULUS**

Run: `/opt/homebrew/bin/php tests/referral/test_schema.php`
Expected: `OK test_schema`.

- [ ] **Step 6: Commit**

```bash
git add migrations/2026-06-30-loyalty-referral.sql tests/referral/test_schema.php
git commit -m "feat(referral): migration config tenants + referral_code + tabel hl_referral"
```

---

### Task 2: core/Referral.php — config + codeFor + resolveCode + statsFor

**Files:**
- Create: `core/Referral.php`
- Test: `tests/referral/test_code.php`

**Interfaces:**
- Consumes: `Database::get()`.
- Produces:
  - `Referral::config(int $tenantId): array` → `['enabled'=>bool,'poin_pengajak'=>int,'poin_teman'=>int,'max'=>int]` (enabled = referral_enabled==1 AND loyalty_enabled==1).
  - `Referral::codeFor(int $tenantId, int $pelangganId): string` — kalau `hl_pelanggan.referral_code` kosong, generate `<SLUG>-<3char>` (SLUG = huruf/angka dari nama, uppercase, maks 8; 3char acak A-Z0-9), pastikan unik per tenant, simpan; return kode.
  - `Referral::resolveCode(int $tenantId, string $kode): ?int` — return `referrer_pelanggan_id` dari kode (scoped tenant), null kalau tak ada.
  - `Referral::statsFor(int $tenantId, int $pelangganId): array` → `['sukses'=>int,'poin'=>int]` (jumlah hl_referral paid sebagai pengajak + total poin_pengajak).

- [ ] **Step 1: Tulis test (gagal dulu)**

`tests/referral/test_code.php`:
```php
<?php
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../core/Database.php';
require __DIR__ . '/../../core/Referral.php';
$db = Database::get();
$tid = (int)$db->query("SELECT tenant_id FROM hl_pelanggan LIMIT 1")->fetchColumn();
$pid = (int)$db->query("SELECT id FROM hl_pelanggan WHERE tenant_id=$tid LIMIT 1")->fetchColumn();
ok($tid>0 && $pid>0, 'ada pelanggan untuk test');

$db->beginTransaction();
$code1 = Referral::codeFor($tid, $pid);
ok(preg_match('/^[A-Z0-9]+-[A-Z0-9]{3}$/', $code1) === 1, "format kode valid: $code1");
$code2 = Referral::codeFor($tid, $pid);
eqv($code2, $code1, 'panggilan kedua kode stabil (tak regenerate)');
eqv(Referral::resolveCode($tid, $code1), $pid, 'resolveCode → pelanggan id benar');
ok(Referral::resolveCode($tid, 'NGAWUR-XYZ') === null, 'kode tak dikenal → null');
$db->rollBack();
echo "OK test_code\n";
```

- [ ] **Step 2: Jalankan test → GAGAL**

Run: `/opt/homebrew/bin/php tests/referral/test_code.php`
Expected: FAIL ("Class Referral not found").

- [ ] **Step 3: Implementasi (config + codeFor + resolveCode + statsFor)**

`core/Referral.php`:
```php
<?php
/** Referral (ajak teman) — opt-in per tenant, payout poin saat order pertama teman LUNAS. */
require_once __DIR__ . '/Database.php';

class Referral
{
    private static array $cfgCache = [];

    public static function config(int $tenantId): array
    {
        if (isset(self::$cfgCache[$tenantId])) return self::$cfgCache[$tenantId];
        $cfg = ['enabled'=>false,'poin_pengajak'=>0,'poin_teman'=>0,'max'=>0];
        try {
            $st = Database::get()->prepare(
                "SELECT referral_enabled, loyalty_enabled, referral_poin_pengajak, referral_poin_teman, referral_max_per_pengajak
                   FROM tenants WHERE id=?");
            $st->execute([$tenantId]);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $cfg = [
                    'enabled'       => (int)($r['referral_enabled'] ?? 0) === 1 && (int)($r['loyalty_enabled'] ?? 0) === 1,
                    'poin_pengajak' => max(0,(int)($r['referral_poin_pengajak'] ?? 0)),
                    'poin_teman'    => max(0,(int)($r['referral_poin_teman'] ?? 0)),
                    'max'           => max(0,(int)($r['referral_max_per_pengajak'] ?? 0)),
                ];
            }
        } catch (Throwable $e) {
            if (class_exists('ErrorLogger')) ErrorLogger::logException('referral_config', $e, $tenantId);
        }
        return self::$cfgCache[$tenantId] = $cfg;
    }

    public static function codeFor(int $tenantId, int $pelangganId): string
    {
        $db = Database::get();
        $cur = $db->prepare("SELECT referral_code, nama FROM hl_pelanggan WHERE id=? AND tenant_id=?");
        $cur->execute([$pelangganId, $tenantId]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) return '';
        if (!empty($row['referral_code'])) return $row['referral_code'];

        $slug = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$row['nama']));
        $slug = substr($slug !== '' ? $slug : 'REF', 0, 8);
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        for ($try = 0; $try < 20; $try++) {
            $rand = '';
            for ($i=0;$i<3;$i++) $rand .= $chars[random_int(0, strlen($chars)-1)];
            $code = $slug.'-'.$rand;
            $chk = $db->prepare("SELECT 1 FROM hl_pelanggan WHERE tenant_id=? AND referral_code=? LIMIT 1");
            $chk->execute([$tenantId, $code]);
            if (!$chk->fetchColumn()) {
                $db->prepare("UPDATE hl_pelanggan SET referral_code=? WHERE id=? AND tenant_id=?")
                   ->execute([$code, $pelangganId, $tenantId]);
                return $code;
            }
        }
        return $slug.'-'.substr(bin2hex(random_bytes(2)),0,3);
    }

    public static function resolveCode(int $tenantId, string $kode): ?int
    {
        $kode = trim($kode);
        if ($kode === '') return null;
        $st = Database::get()->prepare("SELECT id FROM hl_pelanggan WHERE tenant_id=? AND referral_code=? LIMIT 1");
        $st->execute([$tenantId, $kode]);
        $id = $st->fetchColumn();
        return $id ? (int)$id : null;
    }

    public static function statsFor(int $tenantId, int $pelangganId): array
    {
        $st = Database::get()->prepare(
            "SELECT COUNT(*) sukses, COALESCE(SUM(poin_pengajak),0) poin
               FROM hl_referral WHERE tenant_id=? AND referrer_pelanggan_id=? AND status='paid'");
        $st->execute([$tenantId, $pelangganId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['sukses'=>(int)($r['sukses'] ?? 0), 'poin'=>(int)($r['poin'] ?? 0)];
    }
}
```

- [ ] **Step 4: Jalankan test → LULUS**

Run: `/opt/homebrew/bin/php tests/referral/test_code.php`
Expected: `OK test_code`.

- [ ] **Step 5: Commit**

```bash
git add core/Referral.php tests/referral/test_code.php
git commit -m "feat(referral): Referral config + codeFor + resolveCode + statsFor"
```

---

### Task 3: Referral::attribute() — tag teman ke pengajak + guard anti-abuse

**Files:**
- Modify: `core/Referral.php` (tambah method)
- Test: `tests/referral/test_attribute.php`

**Interfaces:**
- Consumes: `config()`, `resolveCode()`.
- Produces: `Referral::attribute(int $tenantId, string $kode, int $refereePelangganId): array` → `['ok'=>true,'referrer_id'=>int]` atau `['ok'=>false,'error'=>string]`. Guard berurutan: referral aktif (config enabled, else `['ok'=>false,'error'=>'off']` diam); kode resolve ke referrer; referrer != referee (id beda DAN telepon beda); referee BARU (belum ada hl_transaksi dgn pelanggan_id ini); referee belum pernah jadi referee (UNIQUE — cek dulu, tangani duplicate). Sukses → INSERT hl_referral `pending` (poin_pengajak/poin_teman dari config) → return ok.

- [ ] **Step 1: Tulis test (gagal dulu)**

`tests/referral/test_attribute.php`:
```php
<?php
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../core/Database.php';
require __DIR__ . '/../../core/Referral.php';
$db = Database::get();
// pakai tenant yg referral-nya bisa diaktifkan sementara dalam transaksi
$tid = (int)$db->query("SELECT id FROM tenants LIMIT 1")->fetchColumn();
$db->beginTransaction();
$db->prepare("UPDATE tenants SET referral_enabled=1, loyalty_enabled=1, referral_poin_pengajak=50, referral_poin_teman=25 WHERE id=?")->execute([$tid]);
// reset cache config (statis) — pakai proses baru tak bisa; uji lewat nilai balik saja
// buat 2 pelanggan dummy
$db->prepare("INSERT INTO hl_pelanggan (tenant_id,nama,telepon,poin_balance) VALUES (?,?,?,0)")->execute([$tid,'PengajakX','0810000001']);
$refrId = (int)$db->lastInsertId();
$db->prepare("INSERT INTO hl_pelanggan (tenant_id,nama,telepon,poin_balance) VALUES (?,?,?,0)")->execute([$tid,'TemanY','0810000002']);
$refeId = (int)$db->lastInsertId();
$kode = Referral::codeFor($tid, $refrId);

// reset cfg cache via reflection (config di-cache statis)
$ref = new ReflectionClass('Referral'); $p = $ref->getProperty('cfgCache'); $p->setAccessible(true); $p->setValue([]);

$r = Referral::attribute($tid, $kode, $refeId);
ok($r['ok'] === true, 'teman baru → pending: '.json_encode($r));
$cnt = $db->prepare("SELECT COUNT(*) FROM hl_referral WHERE tenant_id=? AND referee_pelanggan_id=?");
$cnt->execute([$tid,$refeId]); eqv((int)$cnt->fetchColumn(),1,'1 record pending');

$dup = Referral::attribute($tid, $kode, $refeId);
ok($dup['ok'] === false, 'teman sudah direferral → tolak');

$self = Referral::attribute($tid, $kode, $refrId);
ok($self['ok'] === false, 'refer diri sendiri → tolak');

$db->rollBack();
echo "OK test_attribute\n";
```

- [ ] **Step 2: Jalankan test → GAGAL**

Run: `/opt/homebrew/bin/php tests/referral/test_attribute.php`
Expected: FAIL (method belum ada).

- [ ] **Step 3: Implementasi attribute()**

Tambah ke `core/Referral.php`:
```php
    public static function attribute(int $tenantId, string $kode, int $refereePelangganId): array
    {
        $cfg = self::config($tenantId);
        if (!$cfg['enabled']) return ['ok'=>false, 'error'=>'off'];

        $referrerId = self::resolveCode($tenantId, $kode);
        if (!$referrerId) return ['ok'=>false, 'error'=>'Kode referral tidak dikenal'];
        if ($referrerId === $refereePelangganId) return ['ok'=>false, 'error'=>'Tidak bisa refer diri sendiri'];

        $db = Database::get();
        // telepon sama → anggap diri sendiri
        $tel = $db->prepare("SELECT telepon FROM hl_pelanggan WHERE id=? AND tenant_id=?");
        $tel->execute([$referrerId, $tenantId]); $telR = trim((string)$tel->fetchColumn());
        $tel->execute([$refereePelangganId, $tenantId]); $telE = trim((string)$tel->fetchColumn());
        if ($telR !== '' && $telR === $telE) return ['ok'=>false, 'error'=>'Tidak bisa refer diri sendiri'];

        // teman harus BARU (belum punya transaksi)
        $ord = $db->prepare("SELECT 1 FROM hl_transaksi WHERE tenant_id=? AND pelanggan_id=? LIMIT 1");
        $ord->execute([$tenantId, $refereePelangganId]);
        if ($ord->fetchColumn()) return ['ok'=>false, 'error'=>'Hanya untuk pelanggan baru'];

        try {
            $db->prepare(
                "INSERT INTO hl_referral (tenant_id, referrer_pelanggan_id, referee_pelanggan_id, kode, status, poin_pengajak, poin_teman)
                 VALUES (?,?,?,?, 'pending', ?, ?)"
            )->execute([$tenantId, $referrerId, $refereePelangganId, trim($kode), $cfg['poin_pengajak'], $cfg['poin_teman']]);
        } catch (Throwable $e) {
            // UNIQUE(referee) → sudah pernah direferral
            return ['ok'=>false, 'error'=>'Teman sudah pernah pakai kode referral'];
        }
        return ['ok'=>true, 'referrer_id'=>$referrerId];
    }
```

- [ ] **Step 4: Jalankan test → LULUS**

Run: `/opt/homebrew/bin/php tests/referral/test_attribute.php`
Expected: `OK test_attribute`.

- [ ] **Step 5: Commit**

```bash
git add core/Referral.php tests/referral/test_attribute.php
git commit -m "feat(referral): attribute() — tag teman + guard new/self/dup"
```

---

### Task 4: Referral::payoutOnFirstLunas() — cair poin dua-duanya (idempoten + cap)

**Files:**
- Modify: `core/Referral.php` (tambah method)
- Test: `tests/referral/test_payout.php`

**Interfaces:**
- Consumes: `config()`, `Loyalty::adjust(int,int,int,string,?int): int`.
- Produces: `Referral::payoutOnFirstLunas(int $tenantId, int $refereePelangganId, int $orderId, ?int $userId=null): void` — kalau ada hl_referral `pending` utk referee ini: tandai paid + set referee_first_order_id+paid_at; `Loyalty::adjust` poin teman (referee) sesuai record; cek cap pengajak (COUNT paid referrer < max ATAU max==0) → kalau lolos `Loyalty::adjust` poin pengajak, else poin_pengajak di-set 0 di record. Idempoten: hanya proses kalau status `pending` (UPDATE ... WHERE status='pending' rowCount>0 sebagai lock). No-op kalau referral mati / tak ada pending.

- [ ] **Step 1: Tulis test (gagal dulu)**

`tests/referral/test_payout.php`:
```php
<?php
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../core/Database.php';
require __DIR__ . '/../../core/Loyalty.php';
require __DIR__ . '/../../core/Referral.php';
$db = Database::get();
$tid = (int)$db->query("SELECT id FROM tenants LIMIT 1")->fetchColumn();
$db->beginTransaction();
$db->prepare("UPDATE tenants SET referral_enabled=1, loyalty_enabled=1, referral_poin_pengajak=50, referral_poin_teman=25, referral_max_per_pengajak=0 WHERE id=?")->execute([$tid]);
$db->prepare("INSERT INTO hl_pelanggan (tenant_id,nama,telepon,poin_balance) VALUES (?,?,?,0)")->execute([$tid,'P','0811']); $refr=(int)$db->lastInsertId();
$db->prepare("INSERT INTO hl_pelanggan (tenant_id,nama,telepon,poin_balance) VALUES (?,?,?,0)")->execute([$tid,'T','0812']); $refe=(int)$db->lastInsertId();
$db->prepare("INSERT INTO hl_referral (tenant_id,referrer_pelanggan_id,referee_pelanggan_id,kode,status,poin_pengajak,poin_teman) VALUES (?,?,?,?, 'pending',50,25)")->execute([$tid,$refr,$refe,'P-ABC']);

Referral::payoutOnFirstLunas($tid, $refe, 9999, null);
$balR=(int)$db->query("SELECT poin_balance FROM hl_pelanggan WHERE id=$refr")->fetchColumn();
$balE=(int)$db->query("SELECT poin_balance FROM hl_pelanggan WHERE id=$refe")->fetchColumn();
eqv($balR,50,'pengajak +50'); eqv($balE,25,'teman +25');
$stt=$db->query("SELECT status FROM hl_referral WHERE tenant_id=$tid AND referee_pelanggan_id=$refe")->fetchColumn();
eqv($stt,'paid','status paid');

Referral::payoutOnFirstLunas($tid, $refe, 9999, null); // 2x → idempoten
eqv((int)$db->query("SELECT poin_balance FROM hl_pelanggan WHERE id=$refr")->fetchColumn(),50,'idempoten: pengajak tetap 50');
$db->rollBack();
echo "OK test_payout\n";
```

- [ ] **Step 2: Jalankan test → GAGAL**

Run: `/opt/homebrew/bin/php tests/referral/test_payout.php`
Expected: FAIL (method belum ada).

- [ ] **Step 3: Implementasi payoutOnFirstLunas()**

Tambah ke `core/Referral.php` (di atas pakai `require_once __DIR__.'/Loyalty.php';` di dalam method):
```php
    public static function payoutOnFirstLunas(int $tenantId, int $refereePelangganId, int $orderId, ?int $userId = null): void
    {
        $cfg = self::config($tenantId);
        if (!$cfg['enabled']) return;
        require_once __DIR__ . '/Loyalty.php';
        $db = Database::get();

        // Ambil referral pending utk referee ini
        $st = $db->prepare("SELECT id, referrer_pelanggan_id, poin_pengajak, poin_teman
                              FROM hl_referral WHERE tenant_id=? AND referee_pelanggan_id=? AND status='pending' LIMIT 1");
        $st->execute([$tenantId, $refereePelangganId]);
        $ref = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ref) return;

        // Lock idempoten: hanya satu proses yang berhasil flip pending→paid
        $lock = $db->prepare("UPDATE hl_referral SET status='paid', referee_first_order_id=?, paid_at=NOW()
                               WHERE id=? AND status='pending'");
        $lock->execute([$orderId, (int)$ref['id']]);
        if ($lock->rowCount() === 0) return; // sudah diproses proses lain

        // Cek cap pengajak (hitung paid LAIN, exclude record ini)
        $payPengajak = (int)$ref['poin_pengajak'];
        if ($cfg['max'] > 0) {
            $cnt = $db->prepare("SELECT COUNT(*) FROM hl_referral WHERE tenant_id=? AND referrer_pelanggan_id=? AND status='paid' AND id<>?");
            $cnt->execute([$tenantId, (int)$ref['referrer_pelanggan_id'], (int)$ref['id']]);
            if ((int)$cnt->fetchColumn() >= $cfg['max']) {
                $payPengajak = 0;
                $db->prepare("UPDATE hl_referral SET poin_pengajak=0 WHERE id=?")->execute([(int)$ref['id']]);
            }
        }

        try {
            if ((int)$ref['poin_teman'] > 0)
                Loyalty::adjust($tenantId, $refereePelangganId, (int)$ref['poin_teman'], 'Bonus referral (teman baru)', $userId);
            if ($payPengajak > 0)
                Loyalty::adjust($tenantId, (int)$ref['referrer_pelanggan_id'], $payPengajak, 'Bonus referral (ajak teman)', $userId);
        } catch (Throwable $e) {
            if (class_exists('ErrorLogger')) ErrorLogger::logException('referral_payout', $e, $tenantId);
        }
    }
```

> CATATAN IMPLEMENTER: `Loyalty::adjust` membuka transaksinya sendiri (`beginTransaction`). Pastikan `payoutOnFirstLunas` TIDAK dipanggil di dalam transaksi caller yang masih terbuka (kalau call-site Task 8 ada di tengah transaksi, panggil SETELAH `commit`). Lihat Task 8.

- [ ] **Step 4: Jalankan test → LULUS**

Run: `/opt/homebrew/bin/php tests/referral/test_payout.php`
Expected: `OK test_payout`.

- [ ] **Step 5: Commit**

```bash
git add core/Referral.php tests/referral/test_payout.php
git commit -m "feat(referral): payoutOnFirstLunas — poin dua-duanya, idempoten + cap"
```

---

### Task 5: HQ settings — section Referral di hq/loyalty.php

**Files:**
- Modify: `hq/loyalty.php`

**Interfaces:**
- Consumes: kolom config `tenants.referral_*` (Task 1).

- [ ] **Step 1: Tambah action simpan + render section**

Di `hq/loyalty.php`: tambah action handler (POST, pola action existing + `verifyCsrf()`) `action=save_referral` yang meng-UPDATE `tenants` SET `referral_enabled`, `referral_poin_pengajak`, `referral_poin_teman`, `referral_max_per_pengajak` WHERE id = TenantResolver::id(). Tambah section UI "🤝 Referral (Ajak Teman)" berisi: toggle enable, input poin pengajak, poin teman, max per pengajak (0=tak terbatas), tombol Simpan (POST ke save_referral). Muat nilai sekarang dari `tenants`. Ikuti pola form/section + CSRF + `hasPermission` yang sudah dipakai di hq/loyalty.php.

> IMPLEMENTER: baca hq/loyalty.php dulu — tiru pola action handler, verifyCsrf, hasPermission, dan struktur section/form yang ada. Jangan ubah section reward existing.

- [ ] **Step 2: Lint + smoke**

Run: `/opt/homebrew/bin/php -l hq/loyalty.php`
Expected: clean. Buka /hq/loyalty → section Referral tampil, simpan → nilai tersimpan di tenants (cek via mysql).

- [ ] **Step 3: Commit**

```bash
git add hq/loyalty.php
git commit -m "feat(referral): section pengaturan Referral di hq/loyalty (enable + poin + cap)"
```

---

### Task 6: Input kode referral di POS + self-booking

**Files:**
- Modify: `pos.php`
- Modify: `self.php`

**Interfaces:**
- Consumes: `Referral::config()`, `Referral::attribute()`.

- [ ] **Step 1: POS — field + attribute saat pelanggan baru**

Di `pos.php`: hanya kalau `Referral::config($tid)['enabled']`, tampilkan field opsional "Kode referral" di form order. Saat order disimpan untuk pelanggan yang BARU dibuat (pelanggan_id baru hasil insert di alur save), kalau kode diisi → `Referral::attribute($tid, $kode, $pelangganIdBaru)` (best-effort: kalau gagal, JANGAN batalkan order — telan errornya / tampilkan info ringan). Kirim kode dari klien di payload save.

> IMPLEMENTER: baca jalur save pos.php untuk tahu di mana pelanggan baru dibuat & id-nya tersedia. Panggil attribute SETELAH pelanggan terbuat, di luar transaksi inti order (attribute punya query sendiri). Hanya untuk pelanggan baru (kalau alur memilih pelanggan existing, skip).

- [ ] **Step 2: self-booking — field + attribute + prefill ?ref=**

Di `self.php`: kalau referral enabled, tampilkan field "Kode referral" (prefill dari query `?ref=` kalau ada). Saat booking membuat pelanggan baru → `Referral::attribute()` best-effort sama seperti POS.

> IMPLEMENTER: baca self.php untuk titik pembuatan pelanggan. Prefill: `$_GET['ref']` → sanitize → value field.

- [ ] **Step 3: Lint**

Run: `/opt/homebrew/bin/php -l pos.php && /opt/homebrew/bin/php -l self.php`
Expected: clean.

- [ ] **Step 4: Commit**

```bash
git add pos.php self.php
git commit -m "feat(referral): input kode referral di POS + self-booking (prefill ?ref=)"
```

---

### Task 7: Portal pelanggan — tampil kode + link share + stats

**Files:**
- Modify: `pelanggan.php`

**Interfaces:**
- Consumes: `Referral::config()`, `Referral::codeFor()`, `Referral::statsFor()`.

- [ ] **Step 1: Render section referral di portal**

Di `pelanggan.php`: kalau `Referral::config($tid)['enabled']`, render section "🤝 Ajak Teman": tampilkan `Referral::codeFor($tid, $pelangganId)` (kode), link share `<APP_URL>/self?...&ref=<kode>` (atau URL portal yang relevan — pakai pola URL existing di pelanggan.php), tombol "Salin"/share, dan `statsFor` ("N teman sukses · M poin didapat"). Pakai `esc()` untuk semua output.

> IMPLEMENTER: baca pelanggan.php untuk tahu variabel pelanggan login ($pelangganId/$tid) + pola URL share existing (struk pakai APP_URL). Sisipkan section tanpa ganggu yang lain.

- [ ] **Step 2: Lint**

Run: `/opt/homebrew/bin/php -l pelanggan.php`
Expected: clean.

- [ ] **Step 3: Commit**

```bash
git add pelanggan.php
git commit -m "feat(referral): portal pelanggan — kode + link share + stats referral"
```

---

### Task 8: Hook payout saat order pertama teman LUNAS

**Files:**
- Modify: `orders.php` (pelunasan ~552-580)
- Modify: `pos.php` (order lahir lunas saat save)

**Interfaces:**
- Consumes: `Referral::payoutOnFirstLunas(int $tenantId, int $refereePelangganId, int $orderId, ?int $userId=null): void`.

- [ ] **Step 1: orders.php — panggil payout setelah pelunasan commit**

Di blok pelunasan `orders.php` (sekitar 552-580, yang `UPDATE ... SET status_bayar='lunas'`): SETELAH commit transaksi, untuk tiap order yang baru di-lunasi & punya `pelanggan_id`, panggil `Referral::payoutOnFirstLunas($tid, (int)$pelangganId, (int)$orderId, $user['id'])` (best-effort try/catch + ErrorLogger). `require_once ROOT.'/core/Referral.php'`.

> IMPLEMENTER: payout HARUS setelah `$db->commit()` (payoutOnFirstLunas→Loyalty::adjust buka transaksi sendiri). Ambil pelanggan_id tiap order yang dilunasi dari row yang sudah di-query di blok itu.

- [ ] **Step 2: pos.php — panggil payout kalau order lahir lunas**

Di `pos.php` alur save: setelah order tersimpan & transaksi inti di-commit, kalau `status_bayar` order == 'lunas' dan ada `pelanggan_id`, panggil `Referral::payoutOnFirstLunas($tid, (int)$pelId, (int)$trxId, $user['id'])` (best-effort). `require_once` Referral.

> IMPLEMENTER: pastikan dipanggil SETELAH commit order (di luar transaksi). Idempoten + no-op aman kalau pelanggan ini bukan referee / referral mati.

- [ ] **Step 3: Lint**

Run: `/opt/homebrew/bin/php -l orders.php && /opt/homebrew/bin/php -l pos.php`
Expected: clean.

- [ ] **Step 4: Commit**

```bash
git add orders.php pos.php
git commit -m "feat(referral): payout saat order pertama teman LUNAS (orders pelunasan + POS lahir-lunas)"
```

---

## Manual E2E (setelah semua task)

1. Owner buka /hq/loyalty → aktifkan Referral, set poin pengajak 50 / teman 25, cap 0 → simpan.
2. Pelanggan A buka portal → lihat kode referral + link share.
3. Buat pelanggan B (baru) di POS, isi kode A → order → bayar lunas → cek poin A +50, B +25, `hl_referral` paid.
4. Ulang via self-booking pakai `?ref=<kodeA>` untuk pelanggan baru C.
5. Negatif: B (sudah pernah order) coba dipakaikan kode lagi → ditolak; A pakai kodenya sendiri → ditolak.
6. Cap: set cap=1, A ajak 2 teman lunas → teman ke-2: teman tetap dapat poin, A tidak.
7. Idempotency: lunasi ulang / re-trigger → tak ada poin dobel.
8. Referral OFF → field tak muncul, payout no-op.

## Catatan Verifikasi Implementer (pola wajib repo)

Sebelum commit task DB, `SHOW COLUMNS` tabel terkait. Konfirmasi `hl_pelanggan` punya `nama`,`telepon`,`poin_balance`,`referral_code`; `hl_transaksi` punya `pelanggan_id`,`status_bayar`; `tenants` punya kolom referral_* + `loyalty_enabled`. `Loyalty::adjust` signature: `(int $tenantId, int $pelangganId, int $poinDelta, string $note, ?int $userId=null): int`.

# Tata-Kelola Coin Per-Outlet Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Owner bisa ganti Mode Coin (shared↔per_outlet) dengan migrasi saldo aman, dan fitur HQ-level memotong coin dari outlet penanggung eksplisit (default UTAMA, bisa override) saat per_outlet; sekalian menutup bug ganti-mode-tanpa-migrasi.

**Architecture:** Kelas baru `CoinModeManager::switchMode()` jadi satu-satunya jalur ganti mode + migrasi saldo (transaksional + ledger), dipakai owner & SA. `CoinLedger` dapat `deductHq()`/`canAffordHq()`/`hqBillingOutletId()` yang di per_outlet membebankan outlet penanggung (`tenants.hq_coin_outlet_id`, default UTAMA). UI owner di `hq/billing.php`.

**Tech Stack:** PHP 8 / MariaDB, PDO (Database::get()), pola test `tests/_assert.php`, mysql client `/opt/homebrew/opt/mysql-client/bin/mysql` + ~/.my.cnf → PROD.

## Global Constraints

- Mode hanya `shared` / `per_outlet` (enum `tenants.coin_mode` sudah ada). Tidak ada mode ketiga.
- Migrasi shared→per_outlet: seluruh `tenants.coin_balance` → outlet UTAMA; outlet lain mulai 0.
- Migrasi per_outlet→shared: SUM semua `outlets.coin_balance` → `tenants.coin_balance`; outlet jadi 0.
- `trial_coin_balance` **tidak pernah** ikut migrasi dan tidak dipakai fitur HQ.
- Outlet penanggung HQ = `tenants.hq_coin_outlet_id` bila valid (milik tenant, status≠closed), else outlet UTAMA (`is_main DESC, id ASC`, status≠closed).
- Semua perpindahan saldo dicatat di `coin_ledger` (kolom: tenant_id, outlet_id, type['topup'|'deduct'], amount, feature_used, description, balance_after, ref_id).
- Aksi owner: `verifyCsrf()` + `TenantResolver::isOwnerLevel()`, tenant-scoped (`WHERE id = TenantResolver::id()`).
- Mode `per_outlet` belum dipakai tenant mana pun (semua shared) — forward-looking.
- Call-site coin **outlet-level JANGAN diubah**: `pos.php`, `api/voice_order_parse.php`, `api/kas_struk_scan.php`, `piutang.php`, `laporan.php`, `import.php`, `core/StrukGenerator.php`, `core/Notifier.php`.

---

### Task 1: Migration — `tenants.hq_coin_outlet_id`

**Files:**
- Modify (DB): tabel `tenants`
- Test: `tests/coin/test_schema.php` (NEW)

**Interfaces:**
- Produces: kolom `tenants.hq_coin_outlet_id INT NULL` dipakai Task 3 & Task 6.

- [ ] **Step 1: Tulis test schema (gagal dulu)**

Buat `tests/coin/test_schema.php`:

```php
<?php
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../master/config/db.php';
require __DIR__ . '/../../core/Database.php';
$db = Database::get();
$cols = $db->query("SHOW COLUMNS FROM tenants")->fetchAll(PDO::FETCH_COLUMN);
ok(in_array('hq_coin_outlet_id', $cols), 'kolom tenants.hq_coin_outlet_id ada');
echo "OK test_schema (coin governance)\n";
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php tests/coin/test_schema.php`
Expected: FAIL (assertion `hq_coin_outlet_id ada` gagal).

- [ ] **Step 3: Apply migration**

Run:
```bash
/opt/homebrew/opt/mysql-client/bin/mysql -e "ALTER TABLE tenants ADD COLUMN hq_coin_outlet_id INT NULL AFTER coin_mode;"
```
(Idempotensi: kalau sudah ada, MariaDB error "Duplicate column" — aman diabaikan; verifikasi via Step 4.)

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php tests/coin/test_schema.php`
Expected: `OK test_schema (coin governance)`, exit 0.

- [ ] **Step 5: Commit**

```bash
git add tests/coin/test_schema.php
git commit -m "feat(coin): migration tenants.hq_coin_outlet_id (outlet penanggung coin HQ) + test schema"
```

---

### Task 2: `core/CoinModeManager.php` — switchMode + migrasi saldo

**Files:**
- Create: `core/CoinModeManager.php`
- Test: `tests/coin/test_coin_mode_switch.php` (NEW)

**Interfaces:**
- Consumes: `Database::get()` (PDO), tabel `tenants`, `outlets`, `coin_ledger`.
- Produces: `CoinModeManager::switchMode(int $tenantId, string $newMode, string $actor=''): array` → `['ok'=>bool,'moved'=>int,'from'=>string,'to'=>string,'error'=>?string]`. Dipakai Task 5 (SA) & Task 6 (owner UI).

- [ ] **Step 1: Tulis test (gagal dulu)**

Buat `tests/coin/test_coin_mode_switch.php`:

```php
<?php
// Test CoinModeManager::switchMode dua arah + konservasi saldo.
// Pakai tenant+outlet TEMP (clone baris tenant existing utk penuhi NOT NULL), lalu hapus di akhir.
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../master/config/db.php';
require __DIR__ . '/../../core/Database.php';
require __DIR__ . '/../../core/CoinModeManager.php';

$db = Database::get();

// ── Setup: clone tenant existing → temp tenant ──
$src = $db->query("SELECT * FROM tenants LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$src) { echo "SKIP: tidak ada tenant\n"; exit(0); }
unset($src['id']);
$cols = array_keys($src);
$db->prepare("INSERT INTO tenants (".implode(',', $cols).") VALUES (".implode(',', array_fill(0,count($cols),'?')).")")
   ->execute(array_values($src));
$tid = (int)$db->lastInsertId();

// 2 outlet temp (clone baris outlet existing kalau ada, else minimal)
$osrc = $db->query("SELECT * FROM outlets LIMIT 1")->fetch(PDO::FETCH_ASSOC);
function mkOutlet(PDO $db, array $osrc, int $tid, string $nama, int $isMain, int $bal): int {
    unset($osrc['id']);
    $osrc['tenant_id'] = $tid; $osrc['nama_outlet'] = $nama; $osrc['is_main'] = $isMain;
    $osrc['coin_balance'] = $bal; $osrc['status'] = 'active';
    if (array_key_exists('trial_coin_balance', $osrc)) $osrc['trial_coin_balance'] = 0;
    $c = array_keys($osrc);
    $db->prepare("INSERT INTO outlets (".implode(',', $c).") VALUES (".implode(',', array_fill(0,count($c),'?')).")")
       ->execute(array_values($osrc));
    return (int)$db->lastInsertId();
}
$o1 = mkOutlet($db, $osrc, $tid, 'ZZ_TEST_MAIN', 1, 0);
$o2 = mkOutlet($db, $osrc, $tid, 'ZZ_TEST_2', 0, 0);

$cleanup = function() use ($db, $tid, $o1, $o2) {
    $db->prepare("DELETE FROM coin_ledger WHERE tenant_id=?")->execute([$tid]);
    $db->prepare("DELETE FROM outlets WHERE tenant_id=?")->execute([$tid]);
    $db->prepare("DELETE FROM tenants WHERE id=?")->execute([$tid]);
};

try {
    // ── (a) shared → per_outlet: pool tenant 300000 → outlet UTAMA ──
    $db->prepare("UPDATE tenants SET coin_mode='shared', coin_balance=300000 WHERE id=?")->execute([$tid]);
    $db->prepare("UPDATE outlets SET coin_balance=0 WHERE tenant_id=?")->execute([$tid]);
    $r = CoinModeManager::switchMode($tid, 'per_outlet', 'test');
    ok($r['ok'] === true && $r['moved'] === 300000, '(a) switch ke per_outlet ok, moved=300000');
    $mode = $db->query("SELECT coin_mode FROM tenants WHERE id=$tid")->fetchColumn();
    $tpool = (int)$db->query("SELECT coin_balance FROM tenants WHERE id=$tid")->fetchColumn();
    $b1 = (int)$db->query("SELECT coin_balance FROM outlets WHERE id=$o1")->fetchColumn();
    $b2 = (int)$db->query("SELECT coin_balance FROM outlets WHERE id=$o2")->fetchColumn();
    ok($mode === 'per_outlet', '(a) mode jadi per_outlet');
    ok($tpool === 0, '(a) tenant pool jadi 0');
    ok($b1 === 300000, '(a) outlet UTAMA dapat 300000');
    ok($b2 === 0, '(a) outlet non-utama tetap 0');
    ok((int)$db->query("SELECT COUNT(*) FROM coin_ledger WHERE tenant_id=$tid")->fetchColumn() === 2, '(a) 2 entri ledger');

    // ── (b) per_outlet → shared: SUM outlet (300000 + 50000) → tenant ──
    $db->prepare("UPDATE outlets SET coin_balance=50000 WHERE id=?")->execute([$o2]); // total outlet = 350000
    $r2 = CoinModeManager::switchMode($tid, 'shared', 'test');
    ok($r2['ok'] === true && $r2['moved'] === 350000, '(b) switch ke shared ok, moved=350000');
    $mode2 = $db->query("SELECT coin_mode FROM tenants WHERE id=$tid")->fetchColumn();
    $tpool2 = (int)$db->query("SELECT coin_balance FROM tenants WHERE id=$tid")->fetchColumn();
    $sumOut = (int)$db->query("SELECT COALESCE(SUM(coin_balance),0) FROM outlets WHERE tenant_id=$tid")->fetchColumn();
    ok($mode2 === 'shared', '(b) mode jadi shared');
    ok($tpool2 === 350000, '(b) tenant pool = 350000 (konservasi)');
    ok($sumOut === 0, '(b) semua outlet jadi 0');

    // ── (c) no-op saat mode sama ──
    $r3 = CoinModeManager::switchMode($tid, 'shared', 'test');
    ok($r3['ok'] === true && $r3['moved'] === 0, '(c) switch ke mode yang sama → no-op moved=0');

    // ── (d) mode tidak valid ──
    $r4 = CoinModeManager::switchMode($tid, 'bogus', 'test');
    ok($r4['ok'] === false, '(d) mode invalid ditolak');

    echo "OK test_coin_mode_switch\n";
} finally {
    $cleanup();
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php tests/coin/test_coin_mode_switch.php`
Expected: FAIL — `CoinModeManager` belum ada (fatal error class not found).

- [ ] **Step 3: Implement `core/CoinModeManager.php`**

Buat `core/CoinModeManager.php`:

```php
<?php
require_once __DIR__ . '/Database.php';

class CoinModeManager
{
    /**
     * Ganti coin_mode tenant + migrasi saldo (transaksional).
     * shared→per_outlet: seluruh tenants.coin_balance → outlet UTAMA.
     * per_outlet→shared: SUM outlets.coin_balance → tenants.coin_balance.
     * trial_coin_balance TIDAK ikut.
     */
    public static function switchMode(int $tenantId, string $newMode, string $actor = ''): array
    {
        if (!in_array($newMode, ['shared', 'per_outlet'], true)) {
            return ['ok'=>false,'moved'=>0,'from'=>'','to'=>$newMode,'error'=>'Mode tidak valid'];
        }
        $db = Database::get();
        $db->beginTransaction();
        try {
            $cur = $db->prepare("SELECT coin_mode FROM tenants WHERE id=? FOR UPDATE");
            $cur->execute([$tenantId]);
            $from = $cur->fetchColumn();
            if ($from === false) {
                $db->rollBack();
                return ['ok'=>false,'moved'=>0,'from'=>'','to'=>$newMode,'error'=>'Tenant tidak ditemukan'];
            }
            if ($from === $newMode) {
                $db->commit();
                return ['ok'=>true,'moved'=>0,'from'=>$from,'to'=>$newMode,'error'=>null];
            }

            $moved = 0;
            $desc  = 'Migrasi mode coin ' . $from . '→' . $newMode . ($actor ? " ({$actor})" : '');

            if ($newMode === 'per_outlet') {
                $tb = $db->prepare("SELECT coin_balance FROM tenants WHERE id=? FOR UPDATE");
                $tb->execute([$tenantId]);
                $pool = (int)$tb->fetchColumn();
                $mainId = self::mainOutletId($db, $tenantId);
                if ($pool > 0 && $mainId > 0) {
                    $ob = $db->prepare("SELECT coin_balance FROM outlets WHERE id=? AND tenant_id=? FOR UPDATE");
                    $ob->execute([$mainId, $tenantId]);
                    $newOut = (int)$ob->fetchColumn() + $pool;
                    $db->prepare("UPDATE outlets SET coin_balance=? WHERE id=? AND tenant_id=?")->execute([$newOut, $mainId, $tenantId]);
                    $db->prepare("UPDATE tenants SET coin_balance=0 WHERE id=?")->execute([$tenantId]);
                    self::ledger($db, $tenantId, null, 'deduct', $pool, $desc, 0);
                    self::ledger($db, $tenantId, $mainId, 'topup', $pool, $desc, $newOut);
                    $moved = $pool;
                }
                $db->prepare("UPDATE tenants SET coin_mode='per_outlet' WHERE id=?")->execute([$tenantId]);
            } else {
                $rows = $db->prepare("SELECT id, coin_balance FROM outlets WHERE tenant_id=? FOR UPDATE");
                $rows->execute([$tenantId]);
                $outlets = $rows->fetchAll(PDO::FETCH_ASSOC);
                $tb = $db->prepare("SELECT coin_balance FROM tenants WHERE id=? FOR UPDATE");
                $tb->execute([$tenantId]);
                $pool = (int)$tb->fetchColumn();
                $sum = 0;
                foreach ($outlets as $o) {
                    $bal = (int)$o['coin_balance'];
                    if ($bal <= 0) continue;
                    $db->prepare("UPDATE outlets SET coin_balance=0 WHERE id=? AND tenant_id=?")->execute([(int)$o['id'], $tenantId]);
                    self::ledger($db, $tenantId, (int)$o['id'], 'deduct', $bal, $desc, 0);
                    $sum += $bal;
                }
                if ($sum > 0) {
                    $newPool = $pool + $sum;
                    $db->prepare("UPDATE tenants SET coin_balance=? WHERE id=?")->execute([$newPool, $tenantId]);
                    self::ledger($db, $tenantId, null, 'topup', $sum, $desc, $newPool);
                    $moved = $sum;
                }
                $db->prepare("UPDATE tenants SET coin_mode='shared' WHERE id=?")->execute([$tenantId]);
            }

            $db->commit();
            return ['ok'=>true,'moved'=>$moved,'from'=>$from,'to'=>$newMode,'error'=>null];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return ['ok'=>false,'moved'=>0,'from'=>'','to'=>$newMode,'error'=>$e->getMessage()];
        }
    }

    private static function mainOutletId(PDO $db, int $tenantId): int
    {
        $s = $db->prepare("SELECT id FROM outlets WHERE tenant_id=? AND status<>'closed' ORDER BY is_main DESC, id ASC LIMIT 1");
        $s->execute([$tenantId]);
        return (int)($s->fetchColumn() ?: 0);
    }

    private static function ledger(PDO $db, int $tenantId, ?int $outletId, string $type, int $amount, string $desc, int $balanceAfter): void
    {
        $db->prepare("INSERT INTO coin_ledger
              (tenant_id, outlet_id, type, amount, feature_used, description, balance_after, ref_id)
            VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$tenantId, $outletId, $type, $amount, 'coin_mode_migration', $desc, $balanceAfter, null]);
    }
}
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php tests/coin/test_coin_mode_switch.php`
Expected: semua `ok`, `OK test_coin_mode_switch`, exit 0. (Temp tenant/outlet dihapus otomatis di `finally`.)

- [ ] **Step 5: Lint + Commit**

```bash
php -l core/CoinModeManager.php
git add core/CoinModeManager.php tests/coin/test_coin_mode_switch.php
git commit -m "feat(coin): CoinModeManager::switchMode — ganti mode + migrasi saldo transaksional + ledger"
```

---

### Task 3: `CoinLedger::deductHq` + `canAffordHq` + `hqBillingOutletId`

**Files:**
- Modify: `core/CoinLedger.php` (tambah 3 metode publik/statik; jangan ubah `deduct`/`canAfford` existing)
- Test: `tests/coin/test_deduct_hq.php` (NEW)

**Interfaces:**
- Consumes: `TenantResolver::id()`, `TenantResolver::isSharedCoin()`, `Database::get()`, `tenants.hq_coin_outlet_id`.
- Produces: `CoinLedger::deductHq(string $feature, ?string $refId=null, ?int $overrideCost=null): bool`, `CoinLedger::canAffordHq(string $feature): bool`, `CoinLedger::hqBillingOutletId(int $tenantId): int`. Dipakai Task 4.

- [ ] **Step 1: Tulis test (gagal dulu)**

Buat `tests/coin/test_deduct_hq.php`:

```php
<?php
// Test hqBillingOutletId resolution. deductHq/canAffordHq butuh sesi (TenantResolver) → diuji via MCP/manual.
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../master/config/db.php';
require __DIR__ . '/../../core/Database.php';
require __DIR__ . '/../../core/CoinLedger.php';

$db = Database::get();
$src = $db->query("SELECT * FROM tenants LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$src) { echo "SKIP: tidak ada tenant\n"; exit(0); }
unset($src['id']);
$c = array_keys($src);
$db->prepare("INSERT INTO tenants (".implode(',', $c).") VALUES (".implode(',', array_fill(0,count($c),'?')).")")->execute(array_values($src));
$tid = (int)$db->lastInsertId();
$osrc = $db->query("SELECT * FROM outlets LIMIT 1")->fetch(PDO::FETCH_ASSOC);
function mkO(PDO $db, array $o, int $tid, string $n, int $main, string $status): int {
    unset($o['id']); $o['tenant_id']=$tid; $o['nama_outlet']=$n; $o['is_main']=$main; $o['status']=$status; $o['coin_balance']=0;
    $c=array_keys($o);
    $db->prepare("INSERT INTO outlets (".implode(',', $c).") VALUES (".implode(',', array_fill(0,count($c),'?')).")")->execute(array_values($o));
    return (int)$db->lastInsertId();
}
$main = mkO($db, $osrc, $tid, 'ZZ_HQ_MAIN', 1, 'active');
$other= mkO($db, $osrc, $tid, 'ZZ_HQ_2',    0, 'active');
$closed=mkO($db, $osrc, $tid, 'ZZ_HQ_CLOSED',0,'closed');

$cleanup = function() use ($db,$tid){ $db->prepare("DELETE FROM outlets WHERE tenant_id=?")->execute([$tid]); $db->prepare("DELETE FROM tenants WHERE id=?")->execute([$tid]); };
try {
    // hq_coin_outlet_id NULL → outlet UTAMA
    $db->prepare("UPDATE tenants SET hq_coin_outlet_id=NULL WHERE id=?")->execute([$tid]);
    ok(CoinLedger::hqBillingOutletId($tid) === $main, 'NULL → outlet UTAMA');
    // valid override → outlet itu
    $db->prepare("UPDATE tenants SET hq_coin_outlet_id=? WHERE id=?")->execute([$other, $tid]);
    ok(CoinLedger::hqBillingOutletId($tid) === $other, 'override valid → outlet itu');
    // override ke outlet closed → fallback UTAMA
    $db->prepare("UPDATE tenants SET hq_coin_outlet_id=? WHERE id=?")->execute([$closed, $tid]);
    ok(CoinLedger::hqBillingOutletId($tid) === $main, 'override closed → fallback UTAMA');
    echo "OK test_deduct_hq\n";
} finally { $cleanup(); }
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php tests/coin/test_deduct_hq.php`
Expected: FAIL — `hqBillingOutletId` belum ada (fatal/Method not found).

- [ ] **Step 3: Implement 3 metode di `core/CoinLedger.php`**

Tambahkan tepat setelah method `canAfford()` (cari `public static function canAfford(string $feature): bool` dan blok return-nya yang berakhir `}`), sisipkan:

```php
    // ── Outlet penanggung coin HQ (per_outlet). Default outlet UTAMA. ──
    public static function hqBillingOutletId(int $tenantId): int
    {
        $db = Database::get();
        $s = $db->prepare("SELECT hq_coin_outlet_id FROM tenants WHERE id=? LIMIT 1");
        $s->execute([$tenantId]);
        $hq = $s->fetchColumn();
        if ($hq !== false && $hq !== null) {
            $chk = $db->prepare("SELECT id FROM outlets WHERE id=? AND tenant_id=? AND status<>'closed' LIMIT 1");
            $chk->execute([(int)$hq, $tenantId]);
            $valid = $chk->fetchColumn();
            if ($valid) return (int)$valid;
        }
        $m = $db->prepare("SELECT id FROM outlets WHERE tenant_id=? AND status<>'closed' ORDER BY is_main DESC, id ASC LIMIT 1");
        $m->execute([$tenantId]);
        return (int)($m->fetchColumn() ?: 0);
    }

    // ── Cek saldo utk fitur HQ-level (tidak pakai trial coin) ──
    public static function canAffordHq(string $feature): bool
    {
        if (!self::isFeatureActive($feature)) return false;
        $cost = self::getHarga($feature);
        if ($cost === 0) return true;
        $tenantId = TenantResolver::id();
        $db = Database::get();
        if (TenantResolver::isSharedCoin()) {
            $s = $db->prepare("SELECT coin_balance FROM tenants WHERE id=? LIMIT 1");
            $s->execute([$tenantId]);
            return ((int)$s->fetchColumn()) >= $cost;
        }
        $oid = self::hqBillingOutletId($tenantId);
        if ($oid <= 0) return false;
        $s = $db->prepare("SELECT coin_balance FROM outlets WHERE id=? AND tenant_id=? LIMIT 1");
        $s->execute([$oid, $tenantId]);
        return ((int)$s->fetchColumn()) >= $cost;
    }

    // ── Potong coin utk fitur HQ-level. shared→tenant pool; per_outlet→outlet penanggung. ──
    public static function deductHq(string $feature, ?string $refId = null, ?int $overrideCost = null): bool
    {
        if (!self::isFeatureActive($feature)) return false;
        $cost = $overrideCost !== null ? max(0, $overrideCost) : self::getHarga($feature);
        if ($cost === 0) return true;

        $tenantId = TenantResolver::id();
        $shared   = TenantResolver::isSharedCoin();
        $db = Database::get();
        $db->beginTransaction();
        try {
            if ($shared) {
                $stmt = $db->prepare("SELECT coin_balance FROM tenants WHERE id=? FOR UPDATE");
                $stmt->execute([$tenantId]);
                $current = (int)$stmt->fetch()['coin_balance'];
                if ($current < $cost) { $db->rollBack(); return false; }
                $newBalance = $current - $cost;
                $db->prepare("UPDATE tenants SET coin_balance=? WHERE id=?")->execute([$newBalance, $tenantId]);
                $ledgerOutlet = null;
            } else {
                $oid = self::hqBillingOutletId($tenantId);
                if ($oid <= 0) { $db->rollBack(); return false; }
                $stmt = $db->prepare("SELECT coin_balance FROM outlets WHERE id=? AND tenant_id=? FOR UPDATE");
                $stmt->execute([$oid, $tenantId]);
                $current = (int)$stmt->fetch()['coin_balance'];
                if ($current < $cost) { $db->rollBack(); return false; }
                $newBalance = $current - $cost;
                $db->prepare("UPDATE outlets SET coin_balance=? WHERE id=? AND tenant_id=?")->execute([$newBalance, $oid, $tenantId]);
                $ledgerOutlet = $oid;
            }
            $db->prepare("INSERT INTO coin_ledger
                  (tenant_id, outlet_id, type, amount, feature_used, description, balance_after, ref_id)
                VALUES (?,?, 'deduct', ?, ?, ?, ?, ?)")
               ->execute([$tenantId, $ledgerOutlet, $cost, $feature, 'Fitur HQ: '.$feature, $newBalance, $refId]);
            $db->commit();
            if ($shared) { $_SESSION['tenant_coin_balance'] = $newBalance; TenantResolver::refresh(); }
            return true;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return false;
        }
    }
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php tests/coin/test_deduct_hq.php`
Expected: 3 `ok`, `OK test_deduct_hq`, exit 0.

- [ ] **Step 5: Lint + Commit**

```bash
php -l core/CoinLedger.php
git add core/CoinLedger.php tests/coin/test_deduct_hq.php
git commit -m "feat(coin): CoinLedger::deductHq/canAffordHq/hqBillingOutletId — fitur HQ bebankan outlet penanggung di per_outlet"
```

---

### Task 4: Re-tag call-site HQ ke deductHq/canAffordHq

**Files:**
- Modify: `hq/broadcast.php`, `hq/laporan.php`, `hq/ai-chat.php`, `hq/ai-churning.php`, `ai.php`

**Interfaces:**
- Consumes: `CoinLedger::deductHq()`, `CoinLedger::canAffordHq()` (Task 3).

- [ ] **Step 1: Ganti pemanggilan di tiap file (hanya baris HQ)**

Lakukan substitusi berikut **pada call-site yang disebut saja** (jangan ubah file outlet-level):

- `hq/broadcast.php`: `$coin->deduct('wa_blast'` → catatan: ini pakai instance `$coin`. Ganti `$coin->deduct('wa_blast', (string)$bid)` menjadi `CoinLedger::deductHq('wa_blast', (string)$bid)`. (Periksa apakah ada `canAfford` di file ini; bila ada `$coin->canAfford('wa_blast')` ganti ke `CoinLedger::canAffordHq('wa_blast')`.)
- `hq/laporan.php`: `CoinLedger::deduct('ai_insight_laporan', AIInsight::COIN_PER_INSIGHT, ...)` → `CoinLedger::deductHq('ai_insight_laporan', AIInsight::COIN_PER_INSIGHT, ...)` (pertahankan argumen sama persis); dan `$coin->deduct('export_pdf')` → `CoinLedger::deductHq('export_pdf')`. Bila ada `canAfford('ai_insight_laporan')`/`canAfford('export_pdf')` di file → `canAffordHq(...)`.
- `hq/ai-chat.php`: `CoinLedger::canAfford('ai_chat_data')` → `CoinLedger::canAffordHq('ai_chat_data')`; `CoinLedger::deduct('ai_chat_data')` → `CoinLedger::deductHq('ai_chat_data')`.
- `hq/ai-churning.php`: `CoinLedger::canAfford('ai_churn_message')` → `canAffordHq`; `CoinLedger::deduct('ai_churn_message')` → `deductHq`.
- `ai.php`: `CoinLedger::deduct('ai_briefing_hq', ...)` → `deductHq`; `CoinLedger::canAfford('ai_chat_data')` → `canAffordHq`; `CoinLedger::deduct('ai_chat_data', ...)` → `deductHq`; `CoinLedger::canAfford('ai_upselling')` → `canAffordHq`; `CoinLedger::deduct('ai_upselling', ...)` → `deductHq`.

Catatan: argumen tiap pemanggilan **tetap sama** (deductHq/canAffordHq punya tanda tangan identik). Untuk file yang memakai instance `$coin` (mis. `$coin = new CoinLedger()` lalu `$coin->deduct(...)`), gunakan pemanggilan statik `CoinLedger::deductHq(...)` (metode baru statik) — jangan andalkan instance.

- [ ] **Step 2: Verifikasi tidak ada call-site HQ yang ketinggalan**

Run:
```bash
grep -nE "deduct\('(wa_blast|export_pdf|ai_briefing_hq|ai_upselling)'|canAfford\('(wa_blast|export_pdf|ai_briefing_hq|ai_upselling)'" hq/broadcast.php hq/laporan.php hq/ai-chat.php hq/ai-churning.php ai.php
```
Expected: kosong (semua sudah `deductHq`/`canAffordHq`). Untuk `ai_chat_data`/`ai_insight_laporan`/`ai_churn_message` yang juga muncul outlet-level, verifikasi manual per file bahwa **file HQ ini** sudah pakai `*Hq`.

- [ ] **Step 3: Lint semua file**

Run: `php -l hq/broadcast.php && php -l hq/laporan.php && php -l hq/ai-chat.php && php -l hq/ai-churning.php && php -l ai.php`
Expected: semua `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add hq/broadcast.php hq/laporan.php hq/ai-chat.php hq/ai-churning.php ai.php
git commit -m "feat(coin): re-tag call-site fitur HQ ke deductHq/canAffordHq (bebankan outlet penanggung)"
```

---

### Task 5: SA `set_coin_mode` → CoinModeManager (tutup bug migrasi)

**Files:**
- Modify: `superadmin/client_detail.php` (action `set_coin_mode`, sekitar baris 239–250)

**Interfaces:**
- Consumes: `CoinModeManager::switchMode()` (Task 2).

- [ ] **Step 1: Ganti UPDATE polos dengan switchMode**

Di `superadmin/client_detail.php`, ganti isi `if ($action === 'set_coin_mode') { ... }` bagian try menjadi:

```php
    if ($action === 'set_coin_mode') {
        saVerifyCsrf();
        $id   = (int)($_POST['tenant_id'] ?? 0);
        $mode = ($_POST['mode'] ?? '') === 'per_outlet' ? 'per_outlet' : 'shared';
        try {
            require_once dirname(__DIR__) . '/core/CoinModeManager.php';
            $res = CoinModeManager::switchMode($id, $mode, 'sa');
            if (!$res['ok']) { echo json_encode(['error' => $res['error'] ?? 'Gagal ganti mode']); exit; }
            logSuperAdminAction('set_coin_mode', $id, "Coin mode → {$mode} (saldo dipindah: {$res['moved']})");
            echo json_encode(['success' => true, 'mode' => $mode, 'moved' => $res['moved']]);
        } catch (Throwable $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
```

- [ ] **Step 2: Lint**

Run: `php -l superadmin/client_detail.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add superadmin/client_detail.php
git commit -m "fix(sa): set_coin_mode lewat CoinModeManager — migrasi saldo saat ganti mode (tutup bug coin nyangkut)"
```

---

### Task 6: UI owner di `hq/billing.php` — toggle mode + outlet penanggung

**Files:**
- Modify: `hq/billing.php` (2 action handler baru + kartu UI + modal)

**Interfaces:**
- Consumes: `CoinModeManager::switchMode()` (Task 2), `tenants.hq_coin_outlet_id` (Task 1), `TenantResolver::isOwnerLevel()`.

- [ ] **Step 1: Tambah 2 action handler (server)**

Di `hq/billing.php`, setelah blok `if ($action === 'transfer' ...) { ... }` (sebelum `require __DIR__ . '/_layout_open.php';`), sisipkan:

Catatan pola: file ini cek CSRF via **header `X-CSRF-Token`** + `hash_equals(getCsrfToken(), $given)` (lihat handler `set_budget`/`transfer`), dan `$tid` + `$db` + `getCsrfToken()` sudah tersedia di scope ini. Ikuti pola itu:

```php
if ($action === 'set_coin_mode' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!TenantResolver::isOwnerLevel()) { echo json_encode(['error'=>'Akses ditolak (owner saja)']); exit; }
    $csrfGiven = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$csrfGiven || !hash_equals(getCsrfToken(), $csrfGiven)) { http_response_code(403); echo json_encode(['error'=>'CSRF mismatch']); exit; }
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    require_once ROOT . '/core/CoinModeManager.php';
    $mode = ($d['mode'] ?? '') === 'per_outlet' ? 'per_outlet' : 'shared';
    $res = CoinModeManager::switchMode($tid, $mode, 'owner:'.(int)($_SESSION['user_id'] ?? 0));
    if (!$res['ok']) { echo json_encode(['error'=>$res['error'] ?? 'Gagal']); exit; }
    echo json_encode(['ok'=>true, 'mode'=>$mode, 'moved'=>$res['moved']]); exit;
}

if ($action === 'set_hq_coin_outlet' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!TenantResolver::isOwnerLevel()) { echo json_encode(['error'=>'Akses ditolak (owner saja)']); exit; }
    $csrfGiven = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$csrfGiven || !hash_equals(getCsrfToken(), $csrfGiven)) { http_response_code(403); echo json_encode(['error'=>'CSRF mismatch']); exit; }
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $oid = (int)($d['outlet_id'] ?? 0);
    if ($oid > 0) {
        $chk = $db->prepare("SELECT id FROM outlets WHERE id=? AND tenant_id=? AND status<>'closed' LIMIT 1");
        $chk->execute([$oid, $tid]);
        if (!$chk->fetchColumn()) { echo json_encode(['error'=>'Outlet tidak valid']); exit; }
    }
    $db->prepare("UPDATE tenants SET hq_coin_outlet_id=? WHERE id=?")->execute([$oid ?: null, $tid]);
    echo json_encode(['ok'=>true]); exit;
}
```

- [ ] **Step 2: Sediakan data mode + outlet utk render**

Di bagian PHP sebelum markup (setelah `require __DIR__ . '/_layout_open.php';` atau di area variabel render — cari tempat `$coinMode` di-set; di file ini ada query coin_mode di baris ~23 dalam fungsi, jadi muat ulang utk render), tambahkan:

`$tid` & `$db` sudah ada di file — JANGAN redefinisi `$tid`. Tambahkan blok render-data ini (sebelum markup kartu):

```php
<?php
$row = $db->prepare("SELECT coin_mode, hq_coin_outlet_id, coin_balance FROM tenants WHERE id=?");
$row->execute([$tid]);
$tinfo = $row->fetch(PDO::FETCH_ASSOC) ?: ['coin_mode'=>'shared','hq_coin_outlet_id'=>null,'coin_balance'=>0];
$curMode = $tinfo['coin_mode'];
$hqOutletId = (int)($tinfo['hq_coin_outlet_id'] ?? 0);
$cmOutlets = $db->prepare("SELECT id, nama_outlet, is_main, coin_balance FROM outlets WHERE tenant_id=? AND status<>'closed' ORDER BY is_main DESC, id ASC");
$cmOutlets->execute([$tid]);
$outletsList = $cmOutlets->fetchAll(PDO::FETCH_ASSOC);
$isOwner = TenantResolver::isOwnerLevel();
$csrf = getCsrfToken();
?>
```

- [ ] **Step 3: Tambah kartu UI "Mode Coin"**

Sisipkan markup ini di dalam body halaman (mis. setelah `<div class="bl-head">...</div>`, sebelum panel grid):

```html
<?php if ($isOwner): ?>
<div class="panel" style="margin-bottom:16px">
  <div class="panel-title">🪙 Mode Coin</div>
  <p style="font-size:13px;color:#64748B;margin:6px 0 12px">
    <strong><?= $curMode === 'shared' ? 'Shared' : 'Per-Outlet' ?></strong> —
    <?= $curMode === 'shared' ? 'semua outlet pakai 1 saldo coin tenant.' : 'tiap outlet punya saldo coin sendiri.' ?>
  </p>
  <button class="btn-export" onclick="toggleCoinMode()" style="background:#0F1C3A">
    Ganti ke <?= $curMode === 'shared' ? 'Per-Outlet' : 'Shared' ?>
  </button>

  <?php if ($curMode === 'per_outlet'): ?>
  <div style="margin-top:16px">
    <label style="font-size:13px;font-weight:700;display:block;margin-bottom:6px">Outlet penanggung coin fitur HQ</label>
    <select id="hqOutletSel" onchange="saveHqOutlet()" style="padding:8px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
      <?php foreach ($outletsList as $o): ?>
      <option value="<?= (int)$o['id'] ?>" <?= ($hqOutletId === (int)$o['id'] || ($hqOutletId === 0 && (int)$o['is_main'] === 1)) ? 'selected' : '' ?>>
        <?= htmlspecialchars($o['nama_outlet']) ?><?= (int)$o['is_main']===1?' (UTAMA)':'' ?> — <?= number_format((int)$o['coin_balance'],0,',','.') ?> coin
      </option>
      <?php endforeach; ?>
    </select>
    <p style="font-size:11px;color:#94A3B8;margin-top:6px">Fitur HQ (broadcast, laporan AI, dll) potong coin dari outlet ini.</p>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
```

- [ ] **Step 4: Tambah JS (toggle + konfirmasi + save penanggung)**

Sisipkan sebelum penutup script halaman (atau di blok `<script>` yang ada):

```html
<script>
const COIN_CSRF = <?= json_encode($csrf) ?>;
const CUR_MODE = <?= json_encode($curMode) ?>;
const TENANT_POOL = <?= (int)$tinfo['coin_balance'] ?>;
const OUTLETS_JSON = <?= json_encode(array_map(fn($o)=>['nama'=>$o['nama_outlet'],'bal'=>(int)$o['coin_balance'],'main'=>(int)$o['is_main']], $outletsList)) ?>;

function toggleCoinMode() {
  const to = CUR_MODE === 'shared' ? 'per_outlet' : 'shared';
  let msg;
  if (to === 'per_outlet') {
    const main = OUTLETS_JSON.find(o => o.main === 1) || OUTLETS_JSON[0];
    msg = `Ganti ke PER-OUTLET?\n\nSaldo tenant ${TENANT_POOL.toLocaleString('id-ID')} coin akan dipindah ke outlet UTAMA${main ? ' ('+main.nama+')' : ''}. Outlet lain mulai 0.`;
  } else {
    const sum = OUTLETS_JSON.reduce((a,o)=>a+o.bal,0);
    msg = `Ganti ke SHARED?\n\nTotal ${sum.toLocaleString('id-ID')} coin dari semua outlet akan digabung jadi saldo tenant.`;
  }
  if (!confirm(msg)) return;
  fetch('/hq/billing.php?action=set_coin_mode', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':COIN_CSRF},
    body: JSON.stringify({ mode: to })
  }).then(r=>r.json()).then(d=>{
    if (d.error) { alert(d.error); return; }
    alert('Mode coin diubah ke ' + d.mode + '. Saldo dipindah: ' + (d.moved||0).toLocaleString('id-ID') + ' coin.');
    location.reload();
  }).catch(()=>alert('Gagal menghubungi server'));
}

function saveHqOutlet() {
  const oid = document.getElementById('hqOutletSel').value;
  fetch('/hq/billing.php?action=set_hq_coin_outlet', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ outlet_id: parseInt(oid,10), _csrf: COIN_CSRF })
  }).then(r=>r.json()).then(d=>{
    if (d.error) { alert(d.error); return; }
  }).catch(()=>alert('Gagal menyimpan'));
}
</script>
```

- [ ] **Step 5: Lint**

Run: `php -l hq/billing.php`
Expected: `No syntax errors detected`.

- [ ] **Step 6: Commit**

```bash
git add hq/billing.php
git commit -m "feat(hq/billing): owner ganti Mode Coin (konfirmasi dampak saldo) + pilih outlet penanggung coin HQ"
```

---

## Self-Review

**Spec coverage:**
- Kolom `hq_coin_outlet_id` → Task 1 ✓
- `CoinModeManager::switchMode` + migrasi 2 arah + ledger + konservasi → Task 2 ✓
- `deductHq`/`canAffordHq`/`hqBillingOutletId` + fallback → Task 3 ✓
- Re-tag 5 file HQ → Task 4 ✓
- SA bug migrasi → Task 5 ✓
- UI owner (toggle + konfirmasi + penanggung) → Task 6 ✓
- Edge: penanggung closed → Task 3 (test c) ✓; trial tak ikut → Task 2 (komentar + tak menyentuh trial_coin_balance) ✓
- Out of scope (transfer manual, alokasi manual, trial migrasi) → tidak ada task melanggar ✓

**Placeholder scan:** Tidak ada TBD/TODO. Test pakai clone-row utk penuhi NOT NULL (bukan placeholder). Task 4 menyebut "periksa apakah ada canAfford di file" — itu instruksi verifikasi konkret (grep), bukan placeholder.

**Type consistency:** `switchMode(int,string,string):array` konsisten Task 2/5/6. `deductHq(string,?string,?int):bool` & `canAffordHq(string):bool` konsisten Task 3/4. `hqBillingOutletId(int):int` Task 3/(dipakai internal). Nama kolom `hq_coin_outlet_id` konsisten Task 1/3/6. Ledger `feature_used='coin_mode_migration'` (Task 2) & `'Fitur HQ: '` desc (Task 3).

**Catatan eksekusi:** Kerjakan di branch baru dari `main` (`feat/coin-governance`); sesi paralel ada di worktree-nya sendiri. Task 4 melibatkan beberapa file dengan nama fitur yang juga muncul di call-site outlet-level — wajib re-tag **hanya** di 5 file HQ tersebut.

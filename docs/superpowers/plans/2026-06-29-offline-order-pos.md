# Offline Order POS + Sync — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Kasir bisa membuat order di POS saat internet mati; order disimpan di IndexedDB, lalu otomatis ter-sync ke server (idempoten) saat koneksi kembali.

**Architecture:** App = Capacitor thin-shell remote-webview. Operational app SUDAH mendaftarkan `/sw-tenant.js` (scope `/`, via components.php) yang meng-cache read-pages dengan fallback offline — jadi shell POS sudah ke-cache saat pertama dibuka online. Tambahan: modul klien `assets/offline-pos.js` (IndexedDB: catalog snapshot + queue order + sync runner + connectivity UI), endpoint server `pos.php?action=sync_offline` (idempoten via `offline_uuid`), dan kolom alias `offline_ref` agar kode sementara tetap bisa dilacak.

**Tech Stack:** PHP 8 / MariaDB, vanilla JS + IndexedDB + Service Worker (sudah ada `sw-tenant.js`). Test: skrip CLI standalone pakai `tests/_assert.php` (`ok()`, `eqv()`) via `/opt/homebrew/bin/php`.

## Global Constraints

- **SW operasional = `sw-tenant.js`** (registered di components.php scope `/`). JANGAN sentuh `sw.js` (itu portal pelanggan, terpisah).
- **Boleh offline:** layanan + tier express (dari katalog ter-cache) + bayar **tunai/DP**. **Online-only (di-disable saat offline):** potong deposit, redeem poin/voucher/promo. Field online-only TIDAK disertakan di payload offline.
- **Kode sementara:** format `OFF-<dev>-<seq>` (dev = id device pendek 2 char, seq = counter lokal zero-pad 3). Saat sync server beri `no_order` asli + simpan `offline_ref` = tempCode (alias permanen).
- **Idempotency:** `offline_uuid` (UUID v4) UNIQUE di `hl_transaksi`. Sync uuid yang sudah sukses → server balikin hasil lama, tidak buat order baru.
- **Harga:** server HORMATI harga di payload (yang sudah dibayar pelanggan). Hanya tolak jika `layanan_id`/`tier_id` sudah tidak ada.
- **Keamanan:** server TIDAK percaya tenant/outlet dari payload — pakai `TenantResolver::id()`/`outletId()` dari sesi. Endpoint lewat `verifyCsrf()`. Katalog + antrian di klien dibersihkan saat logout/ganti scope.
- **Scope:** offline HANYA buat order baru di POS. Orders/kanban/laporan tetap online-only; edit/hapus offline out of scope.
- **DELIBERATE (bukan defect duplikasi):** `OrderCreator::createOffline()` menangani SUBSET offline secara terpisah dari jalur `pos.php?action=save` yang kaya (deposit/redeem/voucher). Dipisah SENGAJA — bukan refactor jalur save existing — karena ada sesi paralel lain yang aktif menyentuh `pos.php` (refactor jalur save = hazard). Reviewer: ini keputusan plan, bukan duplikasi tak sengaja.
- **Parallel-session safe:** selalu `git pull --no-edit` sebelum push; commit hanya file milik task ini. `pos.php` mungkin disentuh sesi lain — sebelum edit, baca state terkini.
- DB: mysql client `/opt/homebrew/opt/mysql-client/bin/mysql` (~/.my.cnf → PROD). Migration dijalankan langsung.

---

### Task 1: Migration — kolom offline di hl_transaksi

**Files:**
- Create: `migrations/2026-06-29-offline-order-cols.sql`
- Test: `tests/offline/test_schema.php`

**Interfaces:**
- Produces: kolom `hl_transaksi.offline_ref VARCHAR(40) NULL` (index `idx_offline_ref`), `hl_transaksi.offline_uuid CHAR(36) NULL` (unique `uniq_offline_uuid`). Order online biasa: kedua kolom NULL.

- [ ] **Step 1: Tulis file migration**

`migrations/2026-06-29-offline-order-cols.sql`:
```sql
ALTER TABLE hl_transaksi
  ADD COLUMN offline_ref  VARCHAR(40) NULL AFTER no_order,
  ADD COLUMN offline_uuid CHAR(36)    NULL AFTER offline_ref,
  ADD UNIQUE KEY uniq_offline_uuid (offline_uuid),
  ADD KEY idx_offline_ref (offline_ref);
```

- [ ] **Step 2: Tulis test schema (gagal dulu)**

`tests/offline/test_schema.php`:
```php
<?php
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../core/Database.php';
$db = Database::get();
$cols = $db->query("SHOW COLUMNS FROM hl_transaksi")->fetchAll(PDO::FETCH_COLUMN);
ok(in_array('offline_ref', $cols), 'kolom offline_ref ada');
ok(in_array('offline_uuid', $cols), 'kolom offline_uuid ada');
$idx = $db->query("SHOW INDEX FROM hl_transaksi")->fetchAll(PDO::FETCH_ASSOC);
$names = array_column($idx, 'Key_name');
ok(in_array('uniq_offline_uuid', $names), 'unique key offline_uuid ada');
ok(in_array('idx_offline_ref', $names), 'index offline_ref ada');
echo "OK test_schema\n";
```

- [ ] **Step 3: Jalankan test, pastikan GAGAL**

Run: `/opt/homebrew/bin/php tests/offline/test_schema.php`
Expected: FAIL (kolom belum ada) — assertion `kolom offline_ref ada` gagal.

- [ ] **Step 4: Apply migration ke DB**

Run: `/opt/homebrew/opt/mysql-client/bin/mysql < migrations/2026-06-29-offline-order-cols.sql`
Expected: tanpa error.

- [ ] **Step 5: Jalankan test, pastikan LULUS**

Run: `/opt/homebrew/bin/php tests/offline/test_schema.php`
Expected: `OK test_schema`.

- [ ] **Step 6: Commit**

```bash
git add migrations/2026-06-29-offline-order-cols.sql tests/offline/test_schema.php
git commit -m "feat(offline): migration kolom offline_ref + offline_uuid di hl_transaksi"
```

---

### Task 2: core/OrderCreator — validasi payload offline (pure)

**Files:**
- Create: `core/OrderCreator.php`
- Test: `tests/offline/test_validate.php`

**Interfaces:**
- Produces: `OrderCreator::validateOfflinePayload(array $payload, array $validLayananIds, array $validTierIds): array` — kembalikan array error string (kosong = valid). Aturan: harus ada `items` non-kosong; tiap item `layanan_id` harus ∈ `$validLayananIds`; jika `tier_id` di-set (>0) harus ∈ `$validTierIds`; `qty`>0; `total`>=0; `metode` ∈ {`cash`}; `dp`>=0 dan `dp`<=`total`. TIDAK boleh ada field online-only (`redeem_poin`,`voucher_id`,`promo_id`,`reward_id`,`pakai_deposit`) bernilai truthy → kalau ada → error.

- [ ] **Step 1: Tulis test (gagal dulu)**

`tests/offline/test_validate.php`:
```php
<?php
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../core/OrderCreator.php';

$validL = [12, 13];
$validT = [3, 4];
$base = ['items'=>[['layanan_id'=>12,'tier_id'=>3,'qty'=>2,'harga'=>8000,'subtotal'=>16000]],
         'total'=>16000,'metode'=>'cash','dp'=>16000];

eqv(OrderCreator::validateOfflinePayload($base, $validL, $validT), [], 'payload valid → no error');

$noItems = array_merge($base, ['items'=>[]]);
ok(count(OrderCreator::validateOfflinePayload($noItems, $validL, $validT)) > 0, 'items kosong → error');

$badL = ['items'=>[['layanan_id'=>99,'tier_id'=>3,'qty'=>1,'harga'=>1,'subtotal'=>1]],'total'=>1,'metode'=>'cash','dp'=>0];
ok(count(OrderCreator::validateOfflinePayload($badL, $validL, $validT)) > 0, 'layanan_id tak dikenal → error');

$badTier = ['items'=>[['layanan_id'=>12,'tier_id'=>77,'qty'=>1,'harga'=>1,'subtotal'=>1]],'total'=>1,'metode'=>'cash','dp'=>0];
ok(count(OrderCreator::validateOfflinePayload($badTier, $validL, $validT)) > 0, 'tier_id tak dikenal → error');

$dpGtTotal = array_merge($base, ['dp'=>99999]);
ok(count(OrderCreator::validateOfflinePayload($dpGtTotal, $validL, $validT)) > 0, 'dp > total → error');

$online = array_merge($base, ['voucher_id'=>5]);
ok(count(OrderCreator::validateOfflinePayload($online, $validL, $validT)) > 0, 'field online-only → error');

$nonCash = array_merge($base, ['metode'=>'qris']);
ok(count(OrderCreator::validateOfflinePayload($nonCash, $validL, $validT)) > 0, 'metode non-tunai → error');

echo "OK test_validate\n";
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

Run: `/opt/homebrew/bin/php tests/offline/test_validate.php`
Expected: FAIL — "Class OrderCreator not found".

- [ ] **Step 3: Implementasi validateOfflinePayload**

`core/OrderCreator.php`:
```php
<?php
/**
 * OrderCreator — pembuatan order. createOffline() menangani SUBSET offline
 * (layanan+tier+tunai/DP) — SENGAJA terpisah dari jalur pos.php?action=save
 * yang kaya (deposit/redeem/voucher). Lihat plan Global Constraints.
 */
class OrderCreator
{
    private const ONLINE_ONLY_FIELDS = ['redeem_poin','voucher_id','promo_id','reward_id','pakai_deposit'];

    /** @return string[] daftar error (kosong = valid) */
    public static function validateOfflinePayload(array $p, array $validLayananIds, array $validTierIds): array
    {
        $errs = [];
        $items = $p['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            $errs[] = 'Order tanpa item';
            return $errs; // tak lanjut cek item
        }
        foreach ($items as $i => $it) {
            $lid = (int)($it['layanan_id'] ?? 0);
            if (!in_array($lid, $validLayananIds, true)) {
                $errs[] = "Layanan tidak dikenal (item ".($i+1).")";
            }
            $tid = (int)($it['tier_id'] ?? 0);
            if ($tid > 0 && !in_array($tid, $validTierIds, true)) {
                $errs[] = "Tier tidak dikenal (item ".($i+1).")";
            }
            if ((float)($it['qty'] ?? 0) <= 0) {
                $errs[] = "Qty tidak valid (item ".($i+1).")";
            }
        }
        $total = (float)($p['total'] ?? 0);
        $dp    = (float)($p['dp'] ?? 0);
        if ($total < 0)         $errs[] = 'Total negatif';
        if ($dp < 0)            $errs[] = 'DP negatif';
        if ($dp > $total)       $errs[] = 'DP melebihi total';
        if (($p['metode'] ?? 'cash') !== 'cash') $errs[] = 'Metode offline harus tunai';
        foreach (self::ONLINE_ONLY_FIELDS as $f) {
            if (!empty($p[$f])) $errs[] = "Field online-only tidak diizinkan offline: $f";
        }
        return $errs;
    }
}
```

- [ ] **Step 4: Jalankan test, pastikan LULUS**

Run: `/opt/homebrew/bin/php tests/offline/test_validate.php`
Expected: `OK test_validate`.

- [ ] **Step 5: Commit**

```bash
git add core/OrderCreator.php tests/offline/test_validate.php
git commit -m "feat(offline): OrderCreator::validateOfflinePayload + test"
```

---

### Task 3: core/OrderCreator::createOffline — insert order + idempotency

**Files:**
- Modify: `core/OrderCreator.php` (tambah method)
- Test: manual (DB-heavy) + lint. Idempotency di-cek via query helper yang di-test di Task 4 endpoint.

**Interfaces:**
- Consumes: `validateOfflinePayload()` (Task 2); `NotaFormatter::next(int $tid, int $oid, ?string $tanggal): string`; `Database::get()`.
- Produces: `OrderCreator::createOffline(PDO $db, int $tid, int $oid, array $user, array $payload): array` — kembalikan `['ok'=>true,'no_order'=>string,'id'=>int,'offline_ref'=>string]` atau `['ok'=>false,'error'=>string]`. Idempoten: jika `offline_uuid` payload sudah ada di `hl_transaksi`, kembalikan order existing TANPA insert baru. Wajib dipanggil di dalam transaksi caller ATAU buka transaksi sendiri (lihat impl). Mencatat: INSERT hl_transaksi (dengan `offline_ref`,`offline_uuid`,`no_order`,`tanggal`= payload.tanggal), INSERT hl_transaksi_item per item, entri kas bila `dp>0`, audit. TIDAK menyentuh deposit/loyalty-redeem/voucher.

- [ ] **Step 1: Implementasi createOffline (idempoten)**

Tambahkan ke `core/OrderCreator.php` di dalam class:
```php
    public static function createOffline(PDO $db, int $tid, int $oid, array $user, array $payload): array
    {
        $uuid = (string)($payload['uuid'] ?? '');
        $tempCode = substr((string)($payload['tempCode'] ?? ''), 0, 40);
        if ($uuid === '' || $tempCode === '') {
            return ['ok'=>false, 'error'=>'uuid/tempCode kosong'];
        }

        // Idempotency: sudah pernah ter-sync?
        $chk = $db->prepare("SELECT id, no_order FROM hl_transaksi WHERE tenant_id=? AND offline_uuid=? LIMIT 1");
        $chk->execute([$tid, $uuid]);
        if ($row = $chk->fetch(PDO::FETCH_ASSOC)) {
            return ['ok'=>true, 'no_order'=>$row['no_order'], 'id'=>(int)$row['id'], 'offline_ref'=>$tempCode, 'dedup'=>true];
        }

        // Validasi terhadap katalog terkini
        $validL = array_map('intval', $db->query("SELECT id FROM hl_layanan WHERE tenant_id=".(int)$tid)->fetchAll(PDO::FETCH_COLUMN));
        $validT = array_map('intval', $db->query("SELECT id FROM hl_tier_express WHERE tenant_id=".(int)$tid)->fetchAll(PDO::FETCH_COLUMN));
        $errs = self::validateOfflinePayload($payload, $validL, $validT);
        if ($errs) return ['ok'=>false, 'error'=>implode('; ', $errs)];

        $tanggal = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($payload['tanggal'] ?? '')) ? $payload['tanggal'] : date('Y-m-d');
        $no = NotaFormatter::next($tid, $oid, $tanggal);

        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            $total = (float)($payload['total'] ?? 0);
            $dp    = (float)($payload['dp'] ?? 0);
            $nama  = substr(trim((string)($payload['nama_pelanggan'] ?? '')), 0, 100);
            $telp  = substr(trim((string)($payload['telepon'] ?? '')), 0, 30);
            $pelId = !empty($payload['pelanggan_id']) ? (int)$payload['pelanggan_id'] : null;
            $catatan = substr(trim((string)($payload['catatan'] ?? '')), 0, 500);
            $status = $dp >= $total && $total > 0 ? 'lunas' : ($dp > 0 ? 'dp' : 'belum_bayar');

            $ins = $db->prepare(
                "INSERT INTO hl_transaksi
                 (tenant_id, outlet_id, no_order, offline_ref, offline_uuid, tanggal,
                  pelanggan_id, nama_pelanggan, telepon, total, dp, status_bayar, catatan, created_by, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
            );
            $ins->execute([$tid,$oid,$no,$tempCode,$uuid,$tanggal,$pelId,$nama,$telp,$total,$dp,$status,$catatan,(int)$user['id']]);
            $trxId = (int)$db->lastInsertId();

            $insIt = $db->prepare(
                "INSERT INTO hl_transaksi_item (transaksi_id, layanan_id, tier_id, qty, harga, subtotal)
                 VALUES (?,?,?,?,?,?)"
            );
            foreach ($payload['items'] as $it) {
                $insIt->execute([
                    $trxId, (int)$it['layanan_id'], !empty($it['tier_id']) ? (int)$it['tier_id'] : null,
                    (float)$it['qty'], (float)($it['harga'] ?? 0), (float)($it['subtotal'] ?? 0)
                ]);
            }

            if ($dp > 0) {
                $insKas = $db->prepare(
                    "INSERT INTO hl_kas (tenant_id, outlet_id, tanggal, tipe, kategori, keterangan, jumlah, ref_order, created_by, created_at)
                     VALUES (?,?,?,'masuk','penjualan',?,?,?,?,NOW())"
                );
                $insKas->execute([$tid,$oid,$tanggal,"DP/Bayar order $no (offline)",$dp,$no,(int)$user['id']]);
            }

            if ($own) $db->commit();
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) $db->rollBack();
            return ['ok'=>false, 'error'=>'Gagal simpan: '.$e->getMessage()];
        }

        return ['ok'=>true, 'no_order'=>$no, 'id'=>$trxId, 'offline_ref'=>$tempCode];
    }
```

> CATATAN IMPLEMENTER: verifikasi nama kolom REAL di `hl_transaksi` (mis. `status_bayar` vs `status`, `dp` vs `bayar`) dan `hl_transaksi_item` lewat `SHOW COLUMNS` sebelum commit — sesuaikan jika beda. Ini pola wajib repo (verifikasi skema). `hl_tier_express` — konfirmasi nama tabel tier (bisa `hl_layanan_tier`).

- [ ] **Step 2: Lint**

Run: `/opt/homebrew/bin/php -l core/OrderCreator.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Verifikasi skema kolom (manual)**

Run: `/opt/homebrew/opt/mysql-client/bin/mysql -e "SHOW COLUMNS FROM hl_transaksi; SHOW COLUMNS FROM hl_transaksi_item; SHOW TABLES LIKE 'hl_tier%';"`
Expected: konfirmasi nama kolom yang dipakai createOffline benar; sesuaikan kode bila beda; lint ulang.

- [ ] **Step 4: Commit**

```bash
git add core/OrderCreator.php
git commit -m "feat(offline): OrderCreator::createOffline — insert order idempoten (offline subset)"
```

---

### Task 4: Endpoint pos.php?action=sync_offline + snapshot katalog

**Files:**
- Modify: `pos.php` (tambah 2 action di blok action handler, dekat `action==='save'`)
- Test: `tests/offline/test_sync_endpoint.php` (idempotency lewat OrderCreator langsung, bukan HTTP)

**Interfaces:**
- Consumes: `OrderCreator::createOffline()` (Task 3); `verifyCsrf()`; `TenantResolver::id()/outletId()`; `currentUser()`.
- Produces:
  - `GET pos.php?action=catalog_snapshot` → JSON `{layanan:[...], tier:[...], pelanggan:[...]}` (data ringkas untuk cache klien; layanan: id,nama,kategori,harga,satuan; tier: id,nama,multiplier/harga; pelanggan: id,nama,telepon — limit 200 terakhir).
  - `POST pos.php?action=sync_offline` body `{orders:[{uuid,tempCode,createdAt,payload:{...}}, ...]}` → JSON `{results:{<uuid>:{ok:true,no_order,id} | {ok:false,error}}}`. Tiap order diproses idempoten dalam transaksi sendiri; satu gagal tak membatalkan yang lain.

- [ ] **Step 1: Tulis test idempotency (lewat OrderCreator, DB nyata, lalu rollback manual)**

`tests/offline/test_sync_endpoint.php`:
```php
<?php
// Test idempotency createOffline: panggil 2x dgn uuid sama → 1 order.
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../core/Database.php';
require __DIR__ . '/../../core/NotaFormatter.php';
require __DIR__ . '/../../core/OrderCreator.php';
$db = Database::get();

// pakai tenant/outlet pertama yang ada layanan, jangan commit (rollback di akhir)
$tid = (int)$db->query("SELECT tenant_id FROM hl_layanan LIMIT 1")->fetchColumn();
$oid = (int)$db->query("SELECT id FROM outlets WHERE tenant_id=$tid LIMIT 1")->fetchColumn();
$lid = (int)$db->query("SELECT id FROM hl_layanan WHERE tenant_id=$tid LIMIT 1")->fetchColumn();
ok($tid>0 && $oid>0 && $lid>0, 'ada tenant/outlet/layanan utk test');

$uuid = 'test-'.bin2hex(random_bytes(8));
$payload = ['uuid'=>$uuid,'tempCode'=>'OFF-ZZ-999','tanggal'=>date('Y-m-d'),
  'nama_pelanggan'=>'TEST OFFLINE','items'=>[['layanan_id'=>$lid,'qty'=>1,'harga'=>1000,'subtotal'=>1000]],
  'total'=>1000,'dp'=>1000,'metode'=>'cash'];
$user = ['id'=>(int)$db->query("SELECT id FROM users WHERE tenant_id=$tid LIMIT 1")->fetchColumn()];

$db->beginTransaction();
$r1 = OrderCreator::createOffline($db, $tid, $oid, $user, $payload);
ok($r1['ok'] === true, 'create pertama sukses: '.json_encode($r1));
$r2 = OrderCreator::createOffline($db, $tid, $oid, $user, $payload);
ok($r2['ok'] === true && !empty($r2['dedup']), 'create kedua dedup (idempoten)');
eqv($r2['no_order'], $r1['no_order'], 'no_order sama (tak buat order baru)');
$cnt = $db->prepare("SELECT COUNT(*) FROM hl_transaksi WHERE tenant_id=? AND offline_uuid=?");
$cnt->execute([$tid,$uuid]);
eqv((int)$cnt->fetchColumn(), 1, 'hanya 1 baris untuk uuid');
$db->rollBack(); // jangan kotori prod

echo "OK test_sync_endpoint\n";
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

Run: `/opt/homebrew/bin/php tests/offline/test_sync_endpoint.php`
Expected: FAIL (createOffline ada dari Task 3, tapi idempotency dedup belum tervalidasi end-to-end / atau lulus bila Task 3 benar). Bila Task 3 benar, test ini LULUS — itu OK (test ini mengunci kontrak Task 3 + dasar endpoint). Jika gagal karena nama kolom, perbaiki Task 3.

- [ ] **Step 3: Tambah handler endpoint di pos.php**

Cari blok action handler (dekat `if ($action === 'save' ...)`, sekitar baris 171). Tambahkan SEBELUM blok save (urutan tak kritis), dalam scope yang sama (`$tid`,`$oid` sudah tersedia di blok action — verifikasi; jika belum, ambil `$tid=TenantResolver::id(); $oid=TenantResolver::outletId();`):
```php
    // ── Snapshot katalog untuk cache offline klien ──
    if ($action === 'catalog_snapshot') {
        header('Content-Type: application/json');
        $layanan = TenantQuery::raw("SELECT id,nama,kategori,harga,satuan FROM hl_layanan WHERE tenant_id=? AND outlet_id=? ORDER BY nama", [$tid,$oid]);
        $tier    = TenantQuery::raw("SELECT id,nama,multiplier FROM hl_tier_express WHERE tenant_id=? ORDER BY id", [$tid]);
        $pel     = TenantQuery::raw("SELECT id,nama,telepon FROM hl_pelanggan WHERE tenant_id=? AND outlet_id=? ORDER BY id DESC LIMIT 200", [$tid,$oid]);
        echo json_encode(['layanan'=>$layanan,'tier'=>$tier,'pelanggan'=>$pel]);
        exit;
    }

    // ── Sync batch order offline ──
    if ($action === 'sync_offline' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        verifyCsrf();
        require_once __DIR__ . '/core/OrderCreator.php';
        $body = json_decode(file_get_contents('php://input'), true);
        $orders = is_array($body['orders'] ?? null) ? $body['orders'] : [];
        $db = Database::get();
        $results = [];
        foreach ($orders as $o) {
            $uuid = (string)($o['uuid'] ?? '');
            if ($uuid === '') continue;
            $payload = is_array($o['payload'] ?? null) ? $o['payload'] : [];
            $payload['uuid']     = $uuid;
            $payload['tempCode'] = (string)($o['tempCode'] ?? '');
            try {
                $results[$uuid] = OrderCreator::createOffline($db, $tid, $oid, $user, $payload);
            } catch (Throwable $e) {
                ErrorLogger::logException('sync_offline', $e, $tid, $oid);
                $results[$uuid] = ['ok'=>false, 'error'=>'Gagal proses'];
            }
        }
        echo json_encode(['results'=>$results]);
        exit;
    }
```

> IMPLEMENTER: verifikasi `$tid`,`$oid`,`$user` benar tersedia di scope blok action pos.php (baca sekitar baris 165-175). Verifikasi nama tabel tier (`hl_tier_express` vs `hl_layanan_tier`) & kolom (`multiplier`) lewat SHOW COLUMNS; sesuaikan query catalog_snapshot.

- [ ] **Step 4: Lint + jalankan test idempotency lagi**

Run: `/opt/homebrew/bin/php -l pos.php && /opt/homebrew/bin/php tests/offline/test_sync_endpoint.php`
Expected: lint clean; `OK test_sync_endpoint`.

- [ ] **Step 5: Commit**

```bash
git add pos.php tests/offline/test_sync_endpoint.php
git commit -m "feat(offline): endpoint sync_offline (idempoten) + catalog_snapshot di pos.php"
```

---

### Task 5: assets/offline-pos.js — mesin IndexedDB + queue + sync runner

**Files:**
- Create: `assets/offline-pos.js`
- Test: manual E2E (tak ada JS harness di repo). Kontrak fungsi di bawah WAJIB diikuti persis agar Task 6 bisa memanggilnya.

**Interfaces (global `window.OfflinePOS`):**
- `OfflinePOS.init({tenantId, outletId, userId})` → buka/migrate IndexedDB `lamasy_offline` (stores: `catalog`, `queue`, `meta`); set stempel scope; generate/ambil `deviceId` (2 char) di `meta`; pasang listener `online`/`offline`; render banner + indikator.
- `OfflinePOS.snapshotCatalog()` → fetch `pos.php?action=catalog_snapshot`, simpan ke store `catalog` (key `data`, value {layanan,tier,pelanggan,ts}). Dipanggil saat online.
- `OfflinePOS.getCatalog()` → Promise<{layanan,tier,pelanggan,ts}|null> dari store `catalog`.
- `OfflinePOS.isOnline()` → boolean (navigator.onLine).
- `OfflinePOS.enqueueOrder(payload)` → buat `uuid` (crypto.randomUUID), `tempCode` = `OFF-<deviceId>-<seq3>` (seq dari meta, increment+persist), simpan {uuid,tempCode,createdAt,payload,status:'pending'} ke `queue`; return {uuid,tempCode}.
- `OfflinePOS.pendingCount()` → Promise<int> jumlah status pending|error.
- `OfflinePOS.listAttention()` → Promise<array> entri status `error` (untuk daftar "perlu perhatian").
- `OfflinePOS.sync()` → kumpulkan semua `pending`, POST batch ke `pos.php?action=sync_offline` (header `X-CSRF-Token` via interceptor global otomatis; body {orders:[...]}); untuk tiap hasil ok → markDone(uuid) (hapus dari queue, simpan map tempCode→no_order ke meta `synced`); error → markError(uuid, msg). Update indikator. Aman dipanggil berkali (idempoten server).
- `OfflinePOS.clearScope()` → hapus semua store (dipanggil saat logout/ganti outlet — Task 6 memanggil bila stempel scope berubah).

- [ ] **Step 1: Implementasi offline-pos.js**

`assets/offline-pos.js` — implementasi lengkap mengikuti kontrak di atas. Inti (struktur wajib; isi body sesuai kontrak):
```javascript
(function () {
  const DB_NAME = 'lamasy_offline', DB_VER = 1;
  const STORES = ['catalog', 'queue', 'meta'];
  let _db = null, _scope = null;

  function openDB() {
    return new Promise((res, rej) => {
      const r = indexedDB.open(DB_NAME, DB_VER);
      r.onupgradeneeded = (e) => {
        const db = e.target.result;
        if (!db.objectStoreNames.contains('catalog')) db.createObjectStore('catalog');
        if (!db.objectStoreNames.contains('queue'))   db.createObjectStore('queue', { keyPath: 'uuid' });
        if (!db.objectStoreNames.contains('meta'))    db.createObjectStore('meta');
      };
      r.onsuccess = () => res(r.result);
      r.onerror = () => rej(r.error);
    });
  }
  function tx(store, mode) { return _db.transaction(store, mode).objectStore(store); }
  function pput(store, val, key) { return new Promise((res, rej) => { const q = tx(store,'readwrite').put(val, key); q.onsuccess=()=>res(); q.onerror=()=>rej(q.error); }); }
  function pget(store, key) { return new Promise((res, rej) => { const q = tx(store,'readonly').get(key); q.onsuccess=()=>res(q.result); q.onerror=()=>rej(q.error); }); }
  function pall(store) { return new Promise((res, rej) => { const q = tx(store,'readonly').getAll(); q.onsuccess=()=>res(q.result||[]); q.onerror=()=>rej(q.error); }); }
  function pdel(store, key) { return new Promise((res, rej) => { const q = tx(store,'readwrite').delete(key); q.onsuccess=()=>res(); q.onerror=()=>rej(q.error); }); }

  const OfflinePOS = {
    async init(scope) {
      _db = await openDB();
      _scope = `${scope.tenantId}_${scope.outletId}_${scope.userId}`;
      const savedScope = await pget('meta', 'scope');
      if (savedScope && savedScope !== _scope) { await this.clearScope(); }
      await pput('meta', _scope, 'scope');
      let dev = await pget('meta', 'deviceId');
      if (!dev) { dev = Math.random().toString(36).slice(2, 4).toUpperCase(); await pput('meta', dev, 'deviceId'); }
      window.addEventListener('online',  () => { this._renderBanner(); this.sync(); });
      window.addEventListener('offline', () => this._renderBanner());
      this._renderBanner(); this._renderIndicator();
      if (this.isOnline()) { this.snapshotCatalog().catch(()=>{}); this.sync().catch(()=>{}); }
    },
    isOnline() { return navigator.onLine; },
    async snapshotCatalog() {
      const r = await fetch('pos.php?action=catalog_snapshot');
      if (!r.ok) throw new Error('snapshot fail');
      const data = await r.json(); data.ts = Date.now();
      await pput('catalog', data, 'data');
      return data;
    },
    async getCatalog() { return (await pget('catalog', 'data')) || null; },
    async enqueueOrder(payload) {
      const dev = await pget('meta', 'deviceId');
      let seq = (await pget('meta', 'seq')) || 0; seq++; await pput('meta', seq, 'seq');
      const uuid = (crypto.randomUUID ? crypto.randomUUID() : (Date.now()+'-'+Math.random()));
      const tempCode = `OFF-${dev}-${String(seq).padStart(3,'0')}`;
      const entry = { uuid, tempCode, createdAt: new Date().toISOString(), payload, status: 'pending', errorMsg: '' };
      await pput('queue', entry);
      this._renderIndicator();
      return { uuid, tempCode };
    },
    async pendingCount() { return (await pall('queue')).filter(e => e.status==='pending' || e.status==='error').length; },
    async listAttention() { return (await pall('queue')).filter(e => e.status==='error'); },
    async sync() {
      if (!this.isOnline()) return;
      const all = await pall('queue');
      const pending = all.filter(e => e.status === 'pending');
      if (!pending.length) { this._renderIndicator(); return; }
      const orders = pending.map(e => ({ uuid:e.uuid, tempCode:e.tempCode, createdAt:e.createdAt, payload:e.payload }));
      let resp;
      try {
        const r = await fetch('pos.php?action=sync_offline', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ orders }) });
        if (!r.ok) throw new Error('http '+r.status);
        resp = await r.json();
      } catch (e) { return; } // koneksi putus lagi — biarkan pending
      const results = (resp && resp.results) || {};
      for (const e of pending) {
        const res = results[e.uuid];
        if (!res) continue;
        if (res.ok) {
          const synced = (await pget('meta','synced')) || {};
          synced[e.tempCode] = res.no_order; await pput('meta', synced, 'synced');
          await pdel('queue', e.uuid);
        } else {
          e.status = 'error'; e.errorMsg = res.error || 'Gagal'; await pput('queue', e);
        }
      }
      this._renderIndicator();
    },
    async clearScope() {
      for (const s of STORES) { await new Promise((res)=>{ const q = tx(s,'readwrite').clear(); q.onsuccess=()=>res(); q.onerror=()=>res(); }); }
    },
    _renderBanner() {
      let el = document.getElementById('offlineBanner');
      if (!el) { el = document.createElement('div'); el.id = 'offlineBanner'; document.body.appendChild(el); }
      el.style.cssText = 'position:fixed;left:0;right:0;top:0;z-index:9999;text-align:center;padding:6px;font-size:13px;font-weight:700;'+
        (this.isOnline() ? 'display:none' : 'display:block;background:#F59E0B;color:#fff');
      el.textContent = '📴 Offline — order disimpan lokal, ter-sync saat online';
    },
    async _renderIndicator() {
      const n = await this.pendingCount();
      let el = document.getElementById('offlinePending');
      if (!el) { el = document.createElement('div'); el.id='offlinePending'; el.onclick=()=>OfflinePOS.sync(); document.body.appendChild(el); }
      el.style.cssText = 'position:fixed;right:12px;bottom:12px;z-index:9999;cursor:pointer;'+
        (n>0 ? 'display:block;background:#1B2D5A;color:#fff;padding:8px 12px;border-radius:20px;font-size:12px;font-weight:700;box-shadow:0 2px 8px rgba(0,0,0,.2)' : 'display:none');
      el.textContent = `⏳ ${n} order belum ter-sync — tap sync`;
    }
  };
  window.OfflinePOS = OfflinePOS;
})();
```

- [ ] **Step 2: Lint JS (sintaks via node)**

Run: `node --check assets/offline-pos.js`
Expected: tanpa output (sintaks OK). Jika `node` tak ada, lewati — verifikasi saat E2E.

- [ ] **Step 3: Commit**

```bash
git add assets/offline-pos.js
git commit -m "feat(offline): offline-pos.js — IndexedDB queue + catalog cache + sync runner + UI"
```

---

### Task 6: Integrasi pos.php — load script, snapshot, jalur submit offline

**Files:**
- Modify: `pos.php` (include script di footer; init; jalur submit; cetak struk tempCode offline)

**Interfaces:**
- Consumes: `window.OfflinePOS` (Task 5). Endpoint `catalog_snapshot`/`sync_offline` (Task 4).

- [ ] **Step 1: Sertakan script + init**

Di `pos.php` sebelum `</body>` (baris ~2760), tambah:
```html
<script src="/assets/offline-pos.js?v=1"></script>
<script>
  OfflinePOS.init({
    tenantId: <?= (int)TenantResolver::id() ?>,
    outletId: <?= (int)TenantResolver::outletId() ?>,
    userId:   <?= (int)$user['id'] ?>
  });
</script>
```

- [ ] **Step 2: Jalur submit POS — cabang offline**

Cari fungsi submit POS (sekitar baris 2287, `fetch('pos.php?action=save', ...)`). Bungkus: jika `!OfflinePOS.isOnline()` → susun `payload` (nama_pelanggan, telepon, pelanggan_id, items[{layanan_id,tier_id,qty,harga,subtotal}], total, metode:'cash', dp, catatan, tanggal) → `const {tempCode} = await OfflinePOS.enqueueOrder(payload)` → tampilkan struk pakai `tempCode` sebagai nomor order (panggil fungsi render struk existing dengan `{no_order: tempCode, ...}`) → toast "✅ Order offline tersimpan (tempCode), akan ter-sync saat online" → reset form. Jika online → alur existing.

Contoh pola (sesuaikan ke kode submit nyata):
```javascript
async function submitOrder() {
  const payload = buildOrderPayload(); // ekstrak builder dari kode submit existing
  if (!OfflinePOS.isOnline()) {
    payload.metode = 'cash';                 // offline = tunai only
    const { tempCode } = await OfflinePOS.enqueueOrder(payload);
    renderStruk({ ...payload, no_order: tempCode, offline: true });
    showToast('✅ Order offline tersimpan: ' + tempCode + ' — ter-sync saat online', 'success');
    resetForm();
    return;
  }
  // ... alur online existing (fetch action=save) ...
}
```

> IMPLEMENTER: baca kode submit existing dulu. Jangan ubah perilaku online. Kalau UI POS pakai deposit/redeem/voucher saat offline → DISABLE kontrol itu saat `!OfflinePOS.isOnline()` (sembunyikan/disable tombolnya) sesuai Global Constraints.

- [ ] **Step 3: Disable kontrol online-only saat offline**

Tambah listener: saat `offline`/`online`, toggle `disabled` pada kontrol deposit, redeem poin, voucher/promo, dan metode bayar non-tunai di form POS (cari id/class kontrol terkait). Saat offline: paksa metode = tunai.

- [ ] **Step 4: Lint + manual smoke**

Run: `/opt/homebrew/bin/php -l pos.php`
Expected: clean. Buka POS online → cek console tak ada error, `OfflinePOS` terdefinisi, snapshot katalog jalan (IndexedDB `catalog` terisi).

- [ ] **Step 5: Commit**

```bash
git add pos.php
git commit -m "feat(offline): POS submit offline → queue + struk tempCode + disable kontrol online-only"
```

---

### Task 7: sw-tenant.js — cache offline-pos.js + pastikan POS shell offline

**Files:**
- Modify: `sw-tenant.js`

**Interfaces:**
- Consumes: pola cache existing (`STATIC_ASSETS`, cache-first, network-first fallback).

- [ ] **Step 1: Tambah offline-pos.js ke STATIC_ASSETS + bump cache version**

Di `sw-tenant.js`: tambah `'/assets/offline-pos.js'` ke array `STATIC_ASSETS`; naikkan `CACHE` ke `'lamasy-tenant-v2'` (agar SW lama ter-refresh). POS page (`/pos.php` / `/pos`) sudah masuk jalur network-first + cache fallback (read page) → ke-cache otomatis saat dibuka online, tersaji saat offline. Verifikasi rute POS tidak tertangkap sebagai "write action" (cek matcher).

- [ ] **Step 2: Lint JS**

Run: `node --check sw-tenant.js`
Expected: tanpa output. (skip bila node tak ada)

- [ ] **Step 3: Commit**

```bash
git add sw-tenant.js
git commit -m "feat(offline): cache offline-pos.js di sw-tenant + bump cache v2"
```

---

### Task 8: Lacak via kode sementara — offline_ref di track.php + orders.php

**Files:**
- Modify: `track.php` (lookup by no_order → tambah offline_ref)
- Modify: `orders.php` (pencarian `q=` → ikutkan offline_ref)
- Test: `tests/offline/test_lookup.php`

**Interfaces:**
- Consumes: kolom `offline_ref` (Task 1).
- Produces: lookup order yang cocok bila `?order=<tempCode>` / pencarian orders pakai tempCode.

- [ ] **Step 1: Tulis test lookup (gagal dulu bila WHERE belum diubah — di sini test memverifikasi query string builder bila ada; jika lookup inline, test via DB)**

`tests/offline/test_lookup.php`:
```php
<?php
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../core/Database.php';
$db = Database::get();
// cari order yang punya offline_ref (kalau ada di prod) — uji query OR cocok
$sql = "SELECT id FROM hl_transaksi WHERE (no_order = :k OR offline_ref = :k) LIMIT 1";
$st = $db->prepare($sql);
ok($st !== false, 'query lookup OR offline_ref valid (prepare sukses)');
echo "OK test_lookup\n";
```

- [ ] **Step 2: Jalankan test**

Run: `/opt/homebrew/bin/php tests/offline/test_lookup.php`
Expected: `OK test_lookup` (memverifikasi SQL OR valid di MariaDB).

- [ ] **Step 3: Ubah lookup track.php**

Cari query yang ambil order by `no_order` di `track.php`. Ganti `WHERE no_order = ?` menjadi `WHERE (no_order = ? OR offline_ref = ?)` dengan parameter di-bind dua kali (atau named `:k`). Pastikan tetap scoped tenant/outlet sesuai pola existing.

- [ ] **Step 4: Ubah pencarian orders.php**

Cari pembentukan filter pencarian `q` di `orders.php` yang match `no_order`. Tambahkan `OR offline_ref LIKE ?` (atau `=`) agar kasir bisa cari pakai kode sementara.

- [ ] **Step 5: Lint**

Run: `/opt/homebrew/bin/php -l track.php && /opt/homebrew/bin/php -l orders.php`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add track.php orders.php tests/offline/test_lookup.php
git commit -m "feat(offline): lacak/cari order via offline_ref (alias kode sementara)"
```

---

## Manual E2E (setelah semua task — wajib sebelum dianggap selesai)

1. Buka POS online → cek IndexedDB `lamasy_offline.catalog` terisi.
2. Aktifkan mode pesawat (atau DevTools offline) → reload POS → POS tetap kebuka (dari cache SW).
3. Buat order: pilih layanan + tier + tunai → Simpan → banner offline tampil, struk cetak `OFF-XX-NNN`, indikator "1 order belum ter-sync".
4. Matikan mode pesawat → indikator auto-sync → hilang; cek di Orders muncul order dengan `no_order` asli.
5. Cari di Orders pakai `OFF-XX-NNN` → ketemu order yang sama. Buka `track.php?order=OFF-XX-NNN` → ketemu.
6. Cek kas: entri DP/bayar tercatat.
7. Idempotency: ulangi sync (tap indikator setelah online) → tak ada order dobel.
8. Negatif: hapus 1 layanan di server, buat order offline pakai layanan lain yang valid + (skenario) layanan terhapus → sync → yang valid sukses, yang invalid masuk "perlu perhatian".

---

## Catatan Verifikasi Implementer (pola wajib repo)

Sebelum commit task yang menyentuh DB/kolom, JALANKAN `SHOW COLUMNS` untuk tabel terkait dan sesuaikan nama kolom REAL:
- `hl_transaksi`: konfirmasi `status_bayar` (atau `status`), `dp` (atau `bayar`/`dibayar`), `nama_pelanggan`, `telepon`, `catatan`, `created_by`, `created_at`.
- `hl_transaksi_item`: konfirmasi `tier_id`, `harga`, `subtotal`, `qty`.
- Tabel tier express: konfirmasi nama (`hl_tier_express` vs `hl_layanan_tier`) + kolom (`multiplier`/`harga`).
- `hl_kas`: konfirmasi kolom (sudah dipakai di kas.php: `tanggal,tipe,kategori,keterangan,jumlah,ref_order,created_by`).

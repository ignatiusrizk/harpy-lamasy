# Biaya Lainnya sebagai Tier Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ganti "Biaya Lainnya" dari input manual per-order (free-text,
sudah live tapi belum pernah dipakai order nyata) jadi master data (mirip
Tier Express) yang dikonfigurasi sekali, lalu OTOMATIS diterapkan ke SEMUA
order baru di POS tanpa interaksi kasir.

**Architecture:** Tabel master baru `hl_biaya_lainnya_tier` (mirip
`hl_express_tier`, tanpa estimasi_jam) dikelola lewat CRUD baru di
`layanan.php`. Class baru `core/BiayaLainnyaTier.php` (mirip
`core/ExpressTier.php`) menghitung total biaya dari semua tier aktif
terhadap subtotal order — dipanggil server-side saat `pos.php` membuat
order (anti-tamper, tidak dipercaya dari klien). Hasilnya disimpan sbg
rollup di `hl_transaksi.biaya_lainnya` (kolom lama, TIDAK berubah makna)
+ breakdown per baris di tabel detail baru `hl_transaksi_biaya_lainnya`.
`orders.php` di-revert jadi read-only (tidak bisa diedit lagi, snapshot
permanen — sama prinsipnya dgn Biaya Express). `StrukGenerator.php`
me-render breakdown multi-baris dari tabel detail, bukan 1 baris
label+nominal seperti implementasi lama.

**Tech Stack:** PHP procedural, MySQL, vanilla JS. Test via `php
tests/**/test_*.php` (pola `tests/_assert.php`, bukan PHPUnit).

## Global Constraints

- **Tidak ada opsi ketik manual di POS** — biaya lain WAJIB dari daftar
  master yang sudah dikonfigurasi.
- **Nilai per tier: Flat (Rp) ATAU Percent** (dari SUBTOTAL ORDER, bukan
  per-item — beda dari Express Tier yang percent-nya per-item).
- **Otomatis, tanpa interaksi kasir** — semua tier `is_active=1` yang
  cocok outlet-nya langsung diterapkan ke SETIAP order baru di POS.
- **Boleh lebih dari 1 tier aktif sekaligus, dijumlah** — masing-masing
  tampil sbg baris terpisah di struk.
- **Murni snapshot, TIDAK BISA diedit per-order di Orders** — sama seperti
  Biaya Express, sekali ke-generate saat order dibuat, permanen.
- **Outlet-specific tier (nama sama) MENGGANTIKAN tier global**, bukan
  ditambahkan — mirror semantik override Express Tier: kalau ada 2 tier
  nama sama (1 global outlet_id=NULL, 1 khusus outlet ini), yang
  outlet-specific menang, bukan dijumlah dobel.
- `hl_transaksi.biaya_lainnya` (rollup) **TETAP DIPAKAI TANPA UBAH RUMUS**
  di semua tempat (`pos.php`, `orders.php`, struk) — hanya SUMBER nilainya
  yang berubah (dulu manual dari klien, sekarang hasil hitung server dari
  tier aktif).
- `hl_transaksi.biaya_lainnya_label` **DIHAPUS** (`DROP COLUMN`) — belum
  pernah terisi data nyata, aman dihapus tanpa migrasi data.

---

## File yang disentuh

- **Migrasi baru:** `migrations/2026-08-31-biaya-lainnya-tier.sql`
- **Create:** `core/BiayaLainnyaTier.php`
- **Test baru:** `tests/biaya_lainnya/test_biaya_lainnya_tier.php`
- **Modify:** `layanan.php` — CRUD baru "Jenis Biaya Lainnya"
- **Modify:** `pos.php` — hapus input manual, terapkan otomatis
- **Modify:** `orders.php` — revert ke read-only
- **Modify:** `core/StrukGenerator.php` — render breakdown multi-baris
- **Modify:** `tests/struk/test_payment_aid.php` — update test sesuai model baru
- **Modify:** `hq/export.php` — hapus kolom yg di-drop dari SELECT

---

### Task 1: Migrasi DB

**Files:**
- Create: `migrations/2026-08-31-biaya-lainnya-tier.sql`

**Interfaces:**
- Produces: tabel `hl_biaya_lainnya_tier`, tabel
  `hl_transaksi_biaya_lainnya` — dipakai Task 2-6. Kolom
  `hl_transaksi.biaya_lainnya_label` DIHAPUS.

- [ ] **Step 1: Tulis file migrasi**

```sql
-- migrations/2026-08-31-biaya-lainnya-tier.sql
-- Redesign Biaya Lainnya: dari free-text manual jadi master data (tier),
-- otomatis diterapkan ke semua order. Lihat spec
-- docs/superpowers/specs/2026-08-31-biaya-lainnya-tier-design.md

-- 1) Tabel master — pola identik hl_express_tier, tanpa estimasi_jam
CREATE TABLE hl_biaya_lainnya_tier (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NULL,
  nama VARCHAR(50) NOT NULL,
  tipe_biaya ENUM('flat','percent') NOT NULL DEFAULT 'flat',
  nilai_biaya DECIMAL(12,2) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  urutan INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tenant_active (tenant_id, is_active)
);

-- 2) Tabel detail snapshot per order (bisa >1 baris per order)
CREATE TABLE hl_transaksi_biaya_lainnya (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  transaksi_id INT NOT NULL,
  nama VARCHAR(50) NOT NULL,
  nominal DECIMAL(12,2) NOT NULL,
  INDEX idx_transaksi (transaksi_id)
);

-- 3) Kolom lama yg sudah tidak relevan (fitur free-text belum pernah
--    dipakai order nyata — aman dihapus tanpa migrasi data)
ALTER TABLE hl_transaksi DROP COLUMN biaya_lainnya_label;
```

- [ ] **Step 2: Jalankan migrasi**

Run: `mysql < migrations/2026-08-31-biaya-lainnya-tier.sql`

Expected: tidak ada output (sukses).

- [ ] **Step 3: Verifikasi**

Run:
```bash
mysql -e "SHOW TABLES LIKE 'hl_biaya_lainnya_tier'"
mysql -e "SHOW TABLES LIKE 'hl_transaksi_biaya_lainnya'"
mysql -e "SHOW COLUMNS FROM hl_transaksi LIKE 'biaya_lainnya%'"
```

Expected: 2 tabel baru muncul di 2 query pertama; query ketiga HANYA
menunjukkan `biaya_lainnya` (tanpa `biaya_lainnya_label` — sudah terhapus).

- [ ] **Step 4: Commit**

```bash
git add migrations/2026-08-31-biaya-lainnya-tier.sql
git commit -m "db: tabel hl_biaya_lainnya_tier + hl_transaksi_biaya_lainnya, drop biaya_lainnya_label"
```

---

### Task 2: `core/BiayaLainnyaTier.php` — class hitung tier

**Files:**
- Create: `core/BiayaLainnyaTier.php`
- Test: `tests/biaya_lainnya/test_biaya_lainnya_tier.php`

**Interfaces:**
- Consumes: tabel `hl_biaya_lainnya_tier` dari Task 1.
- Produces:
  - `BiayaLainnyaTier::activeForTenant(int $tenantId, ?int $outletId = null): array`
    — list tier aktif (global + outlet-specific, outlet-specific
    MENGGANTIKAN global dgn nama sama), tiap row:
    `['id'=>int,'nama'=>string,'tipe_biaya'=>'flat'|'percent','nilai_biaya'=>float,'urutan'=>int,'outlet_id'=>?int]`
  - `BiayaLainnyaTier::calcFee(array $tier, float $subtotal): float`
  - `BiayaLainnyaTier::calcAppliedFees(int $tenantId, ?int $outletId, float $subtotal): array`
    — `[['nama'=>string,'nominal'=>float], ...]`, hanya baris dgn
    `nominal > 0`. Dipakai Task 4 (`pos.php`).

- [ ] **Step 1: Tulis test (akan gagal — class belum ada)**

```php
<?php
// tests/biaya_lainnya/test_biaya_lainnya_tier.php
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
require_once dirname(__DIR__, 2) . '/core/BiayaLainnyaTier.php';

$db = Database::get();

// ── calcFee() murni, tanpa DB ───────────────────────────
eqv(BiayaLainnyaTier::calcFee(['tipe_biaya'=>'flat', 'nilai_biaya'=>5000], 100000), 5000.0, 'calcFee flat = nilai_biaya apa adanya');
eqv(BiayaLainnyaTier::calcFee(['tipe_biaya'=>'percent', 'nilai_biaya'=>2], 100000), 2000.0, 'calcFee percent = round(subtotal * nilai/100)');
eqv(BiayaLainnyaTier::calcFee(['tipe_biaya'=>'percent', 'nilai_biaya'=>2.5], 33333), 833.0, 'calcFee percent dibulatkan (round)');

// ── activeForTenant() + calcAppliedFees() — pakai tenant test terisolasi ──
// tenant_id 18 = Harpy Laundry (sudah dipakai test lain sesi ini), outlet 13.
// Bersihkan dulu supaya test idempoten kalau dijalankan berulang.
$tid = 18; $oid = 13;
$db->prepare("DELETE FROM hl_biaya_lainnya_tier WHERE tenant_id=? AND nama LIKE 'TEST_%'")->execute([$tid]);

$insTier = $db->prepare("INSERT INTO hl_biaya_lainnya_tier (tenant_id, outlet_id, nama, tipe_biaya, nilai_biaya, is_active) VALUES (?,?,?,?,?,?)");
$insTier->execute([$tid, null, 'TEST_Admin', 'flat', 2000, 1]);       // global, aktif
$insTier->execute([$tid, null, 'TEST_PPN', 'percent', 2, 1]);         // global, aktif
$insTier->execute([$tid, null, 'TEST_Nonaktif', 'flat', 9999, 0]);    // global, TIDAK aktif
$insTier->execute([$tid, $oid, 'TEST_Admin', 'flat', 3000, 1]);       // outlet-specific, override TEST_Admin

$tiers = BiayaLainnyaTier::activeForTenant($tid, $oid);
$namas = array_column($tiers, 'nama');
ok(in_array('TEST_PPN', $namas, true), 'activeForTenant include tier global aktif');
ok(!in_array('TEST_Nonaktif', $namas, true), 'activeForTenant EXCLUDE tier nonaktif');
$adminRows = array_values(array_filter($tiers, fn($t) => $t['nama'] === 'TEST_Admin'));
eqv(count($adminRows), 1, 'activeForTenant: outlet-specific MENGGANTIKAN global (nama sama), bukan dijumlah dobel');
eqv((float)$adminRows[0]['nilai_biaya'], 3000.0, 'activeForTenant: nilai yg dipakai adalah versi outlet-specific (3000), bukan global (2000)');

$fees = BiayaLainnyaTier::calcAppliedFees($tid, $oid, 100000);
$feeByNama = [];
foreach ($fees as $f) { $feeByNama[$f['nama']] = $f['nominal']; }
eqv($feeByNama['TEST_Admin'] ?? null, 3000.0, 'calcAppliedFees pakai override outlet-specific utk TEST_Admin');
eqv($feeByNama['TEST_PPN'] ?? null, 2000.0, 'calcAppliedFees hitung percent dari subtotal (100000*2%=2000)');
ok(!isset($feeByNama['TEST_Nonaktif']), 'calcAppliedFees TIDAK include tier nonaktif');

// Cleanup
$db->prepare("DELETE FROM hl_biaya_lainnya_tier WHERE tenant_id=? AND nama LIKE 'TEST_%'")->execute([$tid]);

echo "\nAll tests passed.\n";
```

- [ ] **Step 2: Buat direktori test & jalankan, pastikan GAGAL**

```bash
mkdir -p tests/biaya_lainnya
```

Run: `php tests/biaya_lainnya/test_biaya_lainnya_tier.php`

Expected: PHP Fatal error — `Class "BiayaLainnyaTier" not found`.

- [ ] **Step 3: Tulis `core/BiayaLainnyaTier.php`**

```php
<?php
// ══════════════════════════════════════════════════════
// core/BiayaLainnyaTier.php
//
// Master data "Biaya Lainnya" — mirip core/ExpressTier.php, tapi dihitung
// dari SUBTOTAL ORDER (bukan per-item, krn biaya ini level-order).
//
// Konsep:
// - Owner define daftar tier sekali (Biaya Admin, PPN, dll) di layanan.php.
// - Setiap tier aktif OTOMATIS diterapkan ke SEMUA order baru di POS —
//   tidak ada pilihan/interaksi kasir sama sekali.
// - Bisa >1 tier aktif sekaligus, dijumlah, masing2 jadi baris di struk.
// - Outlet-specific tier (nama sama dgn tier global) MENGGANTIKAN yg
//   global, bukan ditambahkan (sama semantik override Express Tier).
// - hl_transaksi.biaya_lainnya = SUM hasil hitung semua tier aktif.
// - hl_transaksi_biaya_lainnya = breakdown per baris (snapshot).
//
// Schema: hl_biaya_lainnya_tier (lihat migrations/2026-08-31-biaya-lainnya-tier.sql)
// ══════════════════════════════════════════════════════

class BiayaLainnyaTier
{
    /**
     * Tier aktif utk tenant + outlet ini. Include tier global (outlet_id
     * NULL = berlaku semua outlet). Outlet-specific MENGGANTIKAN global
     * kalau nama sama (bukan dijumlah dobel).
     */
    public static function activeForTenant(int $tenantId, ?int $outletId = null): array
    {
        try {
            $st = Database::get()->prepare(
                "SELECT id, nama, tipe_biaya, nilai_biaya, urutan, outlet_id
                   FROM hl_biaya_lainnya_tier
                  WHERE tenant_id = ? AND is_active = 1
                    AND (outlet_id IS NULL OR outlet_id = ?)
                  ORDER BY urutan ASC, id ASC"
            );
            $st->execute([$tenantId, $outletId ?? 0]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Dedupe by nama — 2 pass: global dulu, lalu outlet-specific
            // menimpa (urutan pass menjamin outlet-specific selalu menang,
            // apa pun urutan hasil query).
            $byNama = [];
            foreach ($rows as $r) {
                if ($r['outlet_id'] === null) $byNama[$r['nama']] = $r;
            }
            foreach ($rows as $r) {
                if ($r['outlet_id'] !== null) $byNama[$r['nama']] = $r;
            }
            return array_values($byNama);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Hitung fee 1 tier terhadap subtotal order.
     */
    public static function calcFee(array $tier, float $subtotal): float
    {
        if (($tier['tipe_biaya'] ?? '') === 'flat') {
            return (float)($tier['nilai_biaya'] ?? 0);
        }
        // percent
        return round($subtotal * ((float)($tier['nilai_biaya'] ?? 0) / 100));
    }

    /**
     * Hitung SEMUA tier aktif sekaligus terhadap subtotal order.
     * @return array [['nama'=>string, 'nominal'=>float], ...] — hanya yg nominal > 0.
     */
    public static function calcAppliedFees(int $tenantId, ?int $outletId, float $subtotal): array
    {
        $tiers = self::activeForTenant($tenantId, $outletId);
        $rows = [];
        foreach ($tiers as $t) {
            $nominal = self::calcFee($t, $subtotal);
            if ($nominal > 0) {
                $rows[] = ['nama' => $t['nama'], 'nominal' => $nominal];
            }
        }
        return $rows;
    }
}
```

- [ ] **Step 4: Jalankan test lagi, pastikan LULUS**

Run: `php tests/biaya_lainnya/test_biaya_lainnya_tier.php`

Expected: semua baris `PASS`, `All tests passed.`, exit code 0.

- [ ] **Step 5: Commit**

```bash
git add core/BiayaLainnyaTier.php tests/biaya_lainnya/test_biaya_lainnya_tier.php
git commit -m "feat(biaya-lainnya): BiayaLainnyaTier — hitung tier aktif thd subtotal order, outlet override"
```

---

### Task 3: `layanan.php` — CRUD "Jenis Biaya Lainnya"

**Files:**
- Modify: `layanan.php`

**Interfaces:**
- Consumes: tabel `hl_biaya_lainnya_tier` dari Task 1.
- Produces: owner bisa kelola daftar tier — dikonsumsi Task 4
  (`BiayaLainnyaTier::activeForTenant()` baca tabel yg sama).

- [ ] **Step 1: Tambah 3 action backend — list/save/delete**

Cari blok "Tier Express CRUD" (sekitar baris 199-281, mulai dari komentar
`// ── Tier Express CRUD ──` sampai sebelum `if ($action === 'stats')`).
Tambahkan 3 action BARU tepat SETELAH blok `tier_delete` yang sudah ada
(sebelum `if ($action === 'stats')`):

```php
    // ── Biaya Lainnya Tier CRUD (tenant-level, dgn opsional per-outlet override) ──
    if ($action === 'biaya_lainnya_list') {
        try {
            $st = Database::get()->prepare(
                "SELECT id, nama, tipe_biaya, nilai_biaya, is_active, urutan, outlet_id
                   FROM hl_biaya_lainnya_tier
                  WHERE tenant_id = ? ORDER BY urutan ASC, id ASC"
            );
            $st->execute([$tid]);
            echo json_encode(['tiers' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) {
            echo json_encode(['error' => 'Gagal load: ' . $e->getMessage(), 'tiers' => []]);
        }
        exit;
    }

    if ($action === 'biaya_lainnya_save' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('layanan.edit') && !hasPermission('layanan.create')) {
            echo json_encode(['error'=>'Akses ditolak']); exit;
        }
        verifyCsrf();
        $d      = json_decode(file_get_contents('php://input'), true);
        $nama   = substr(trim((string)($d['nama'] ?? '')), 0, 50);
        $tipe   = in_array($d['tipe_biaya'] ?? '', ['flat','percent'], true) ? $d['tipe_biaya'] : 'flat';
        $nilai  = max(0, (float)($d['nilai_biaya'] ?? 0));
        $aktif  = (int)($d['is_active'] ?? 1) ? 1 : 0;
        $urut   = (int)($d['urutan'] ?? 0);
        $tierOutletId = !empty($d['outlet_id']) ? (int)$d['outlet_id'] : null;
        if ($nama === '' || $nilai < 0) {
            echo json_encode(['error'=>'Nama & nilai wajib diisi']); exit;
        }
        if ($tierOutletId !== null) {
            $own = TenantQuery::rawOne("SELECT id FROM outlets WHERE id=? AND tenant_id=?", [$tierOutletId, $tid]);
            if (!$own) { echo json_encode(['error'=>'Outlet tidak valid']); exit; }
        }

        $db = Database::get();
        try {
            if (!empty($d['id'])) {
                $st = $db->prepare(
                    "UPDATE hl_biaya_lainnya_tier
                        SET nama=?, tipe_biaya=?, nilai_biaya=?, is_active=?, urutan=?, outlet_id=?
                      WHERE id=? AND tenant_id=?"
                );
                $st->execute([$nama, $tipe, $nilai, $aktif, $urut, $tierOutletId, (int)$d['id'], $tid]);
            } else {
                $st = $db->prepare(
                    "INSERT INTO hl_biaya_lainnya_tier
                        (tenant_id, outlet_id, nama, tipe_biaya, nilai_biaya, is_active, urutan)
                     VALUES (?,?,?,?,?,?,?)"
                );
                $st->execute([$tid, $tierOutletId, $nama, $tipe, $nilai, $aktif, $urut]);
            }
            echo json_encode(['success'=>true]);
        } catch (Throwable $e) {
            echo json_encode(['error' => 'Gagal simpan: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'biaya_lainnya_delete' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('layanan.delete') && !hasPermission('layanan.edit')) {
            echo json_encode(['error'=>'Akses ditolak']); exit;
        }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $tierId = (int)($d['id'] ?? 0);
        Database::get()->prepare("DELETE FROM hl_biaya_lainnya_tier WHERE id=? AND tenant_id=?")
                       ->execute([$tierId, $tid]);
        echo json_encode(['success'=>true]); exit;
    }
```

- [ ] **Step 2: Tambah tombol pembuka modal**

Cari (sekitar baris 364-370):

```php
  <?php if (hasPermission('layanan.create')): ?>
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px">
    <button class="hl-btn hl-btn-primary" onclick="openModal()" style="flex:1;min-width:150px">+ Tambah Layanan</button>
    <button class="hl-btn hl-btn-outline" onclick="openPresetModal()" style="flex:1;min-width:150px" title="Tambah cepat dari daftar layanan umum">📋 Dari Preset</button>
    <button class="hl-btn" onclick="openTierModal()" style="flex:1;min-width:150px;background:#F59E0B;color:#fff;border:none" title="Atur tier express: 12 jam, 6 jam, kilat, dll">⚡ Kelola Tier Express</button>
  </div>
  <?php endif; ?>
```

Tambahkan 1 tombol baru setelah tombol Tier Express:

```php
  <?php if (hasPermission('layanan.create')): ?>
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px">
    <button class="hl-btn hl-btn-primary" onclick="openModal()" style="flex:1;min-width:150px">+ Tambah Layanan</button>
    <button class="hl-btn hl-btn-outline" onclick="openPresetModal()" style="flex:1;min-width:150px" title="Tambah cepat dari daftar layanan umum">📋 Dari Preset</button>
    <button class="hl-btn" onclick="openTierModal()" style="flex:1;min-width:150px;background:#F59E0B;color:#fff;border:none" title="Atur tier express: 12 jam, 6 jam, kilat, dll">⚡ Kelola Tier Express</button>
    <button class="hl-btn" onclick="openBiayaLainnyaModal()" style="flex:1;min-width:150px;background:#0EA5E9;color:#fff;border:none" title="Atur biaya lain yg otomatis kena ke semua order (biaya admin, PPN, dll)">💰 Kelola Biaya Lainnya</button>
  </div>
  <?php endif; ?>
```

- [ ] **Step 3: Tambah modal HTML**

Cari penutup modal Tier Express (sekitar baris 549-553):

```php
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeTierModal()">Tutup</button>
    </div>
  </div>
</div>
```

Sisipkan modal baru TEPAT SETELAH `</div>` penutup terluar (baris terakhir
di atas):

```php
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeTierModal()">Tutup</button>
    </div>
  </div>
</div>

<!-- ════ Modal Biaya Lainnya GLOBAL ════ -->
<div class="hl-modal-overlay" id="modalBiayaLainnya">
  <div class="hl-modal" style="max-width:680px">
    <div class="hl-modal-header">
      <span class="hl-modal-title">💰 Kelola Biaya Lainnya</span>
      <button class="hl-modal-close" onclick="closeBiayaLainnyaModal()">✕</button>
    </div>
    <div class="hl-modal-body">

      <div style="background:#E0F2FE;border:1px solid #BAE6FD;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#0C4A6E;line-height:1.5;">
        💡 Biaya di sini OTOMATIS kena ke SETIAP order baru di POS — tidak
        ada pilihan apa pun buat kasir. Kalau lebih dari 1 status Aktif,
        semuanya dijumlah & tampil sbg baris terpisah di struk.
      </div>

      <!-- List tier -->
      <div id="biayaLainnyaList" style="margin-bottom:16px;"></div>

      <!-- Form tambah/edit -->
      <div style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:10px;padding:14px;">
        <div style="font-weight:600;font-size:13px;color:#374151;margin-bottom:10px;" id="blFormTitle">➕ Tambah Biaya Baru</div>
        <input type="hidden" id="bl_id"/>
        <div class="hl-form-row">
          <div class="hl-form-group">
            <label class="hl-label">Nama Biaya <span class="req">*</span></label>
            <input type="text" id="bl_nama" class="hl-input" placeholder="Biaya Admin" maxlength="50"/>
          </div>
          <div class="hl-form-group">
            <label class="hl-label">Tipe Biaya</label>
            <select id="bl_tipe" class="hl-input lm-cust" onchange="updateBlNilaiUnit()">
              <option value="flat">Flat (Rp tetap)</option>
              <option value="percent">Percent (% dari subtotal order)</option>
            </select>
          </div>
        </div>
        <div class="hl-form-row">
          <div class="hl-form-group">
            <label class="hl-label">Nilai <span class="req">*</span> <span id="blNilaiUnit" style="color:var(--gray);font-weight:400;">(Rp)</span></label>
            <input type="number" id="bl_nilai" class="hl-input" placeholder="2000" min="0" step="any"/>
          </div>
          <div class="hl-form-group">
            <label class="hl-label">Berlaku di Outlet <span style="font-size:11px;color:var(--gray);font-weight:400">— strategi per outlet</span></label>
            <select id="bl_outlet" class="hl-input lm-cust">
              <option value="">🌍 Semua outlet</option>
              <!-- populated by JS -->
            </select>
          </div>
        </div>
        <div class="hl-form-row">
          <div class="hl-form-group">
            <label class="hl-label">Status</label>
            <select id="bl_active" class="hl-input lm-cust">
              <option value="1">✅ Aktif</option>
              <option value="0">⏸️ Nonaktif</option>
            </select>
          </div>
          <div class="hl-form-group">
            <label class="hl-label">Urutan</label>
            <input type="number" id="bl_urutan" class="hl-input" value="0" min="0"/>
          </div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px;">
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="resetBiayaLainnyaForm()">↺ Reset</button>
          <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="saveBiayaLainnya()">💾 Simpan</button>
        </div>
      </div>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeBiayaLainnyaModal()">Tutup</button>
    </div>
  </div>
</div>
```

- [ ] **Step 4: Tambah JS CRUD**

Cari akhir fungsi `deleteTier()` (sekitar baris 878-892, sebelum komentar
`// ── Adjust harga (override) untuk layanan dari master ──`). Tambahkan
JS baru TEPAT SETELAHNYA (sebelum komentar itu):

```javascript
// ── Biaya Lainnya Tier GLOBAL CRUD ──
async function openBiayaLainnyaModal() {
  await loadOutletsForTier(); // reuse loader Tier Express — sama-sama isi #tf_outlet-style dropdown
  const sel = document.getElementById('bl_outlet');
  sel.innerHTML = '<option value="">🌍 Semua outlet</option>' +
    allOutlets.map(o => `<option value="${o.id}">🏪 ${esc(o.nama_outlet)}</option>`).join('');
  lmSyncSel('bl_outlet');
  resetBiayaLainnyaForm();
  document.getElementById('modalBiayaLainnya').classList.add('open');
  await loadBiayaLainnyaTiers();
}
function closeBiayaLainnyaModal() { document.getElementById('modalBiayaLainnya').classList.remove('open'); }

async function loadBiayaLainnyaTiers() {
  const list = document.getElementById('biayaLainnyaList');
  list.innerHTML = '<div style="text-align:center;padding:14px;color:var(--gray);font-size:12px;">Memuat...</div>';
  try {
    const r = await fetch(`?action=biaya_lainnya_list`);
    const d = await r.json();
    if (d.error && d.tiers === undefined) { showToast(d.error, 'error'); list.innerHTML = ''; return; }
    renderBiayaLainnyaList(d.tiers || []);
  } catch (e) {
    showToast('Gagal load: ' + e.message, 'error');
    list.innerHTML = '';
  }
}

function renderBiayaLainnyaList(tiers) {
  const list = document.getElementById('biayaLainnyaList');
  if (!tiers.length) {
    list.innerHTML = '<div style="text-align:center;padding:24px;color:var(--gray);font-size:13px;background:#F9FAFB;border:1px dashed #E5E7EB;border-radius:8px;">💰 Belum ada biaya lain. Tambah pakai form di bawah ↓</div>';
    return;
  }
  list.innerHTML = `
    <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:13px;">
      <thead>
        <tr style="background:#F3F4F6;text-align:left;">
          <th style="padding:8px 10px;">Nama</th>
          <th style="padding:8px 10px;">Outlet</th>
          <th style="padding:8px 10px;">Nilai</th>
          <th style="padding:8px 10px;">Status</th>
          <th style="padding:8px 10px;text-align:right;">Aksi</th>
        </tr>
      </thead>
      <tbody>
        ${tiers.map(t => {
          const outletLabel = t.outlet_id
            ? (allOutlets.find(o => o.id == t.outlet_id)?.nama_outlet || `Outlet #${t.outlet_id}`)
            : '🌍 Semua';
          return `
          <tr style="border-bottom:1px solid #F3F4F6;">
            <td style="padding:10px;">💰 <strong>${esc(t.nama)}</strong></td>
            <td style="padding:10px;font-size:11px;${t.outlet_id?'color:#0F7B6C;':'color:#6B7280;'}">${esc(outletLabel)}</td>
            <td style="padding:10px;">
              ${t.tipe_biaya === 'flat'
                ? '+Rp ' + grpRibu(t.nilai_biaya)
                : '+' + parseFloat(t.nilai_biaya) + '%'}
            </td>
            <td style="padding:10px;">${t.is_active == 1 ? '<span style="color:#059669;">● Aktif</span>' : '<span style="color:#9CA3AF;">○ Off</span>'}</td>
            <td style="padding:10px;text-align:right;white-space:nowrap;">
              <button class="hl-btn hl-btn-outline hl-btn-sm" onclick='editBiayaLainnya(${JSON.stringify(t)})'>✏️</button>
              <button class="hl-btn hl-btn-danger hl-btn-sm" onclick="deleteBiayaLainnya(${t.id})">🗑️</button>
            </td>
          </tr>`;
        }).join('')}
      </tbody>
    </table></div>`;
}

function updateBlNilaiUnit() {
  const tipe = document.getElementById('bl_tipe').value;
  document.getElementById('blNilaiUnit').textContent = tipe === 'flat' ? '(Rp)' : '(%)';
  const input = document.getElementById('bl_nilai');
  input.placeholder = tipe === 'flat' ? '2000' : '2';
}

function resetBiayaLainnyaForm() {
  document.getElementById('bl_id').value     = '';
  document.getElementById('bl_nama').value   = '';
  document.getElementById('bl_tipe').value   = 'flat';
  document.getElementById('bl_nilai').value  = '';
  document.getElementById('bl_urutan').value = 0;
  document.getElementById('bl_active').value = 1;
  document.getElementById('bl_outlet').value = '';
  lmSyncSel('bl_tipe','bl_active','bl_outlet');
  document.getElementById('blFormTitle').textContent = '➕ Tambah Biaya Baru';
  updateBlNilaiUnit();
}

function editBiayaLainnya(t) {
  document.getElementById('bl_id').value     = t.id;
  document.getElementById('bl_nama').value   = t.nama;
  document.getElementById('bl_tipe').value   = t.tipe_biaya;
  document.getElementById('bl_nilai').value  = t.nilai_biaya;
  document.getElementById('bl_urutan').value = t.urutan;
  document.getElementById('bl_active').value = t.is_active;
  document.getElementById('bl_outlet').value = t.outlet_id || '';
  lmSyncSel('bl_tipe','bl_active','bl_outlet');
  document.getElementById('blFormTitle').textContent = '✏️ Edit Biaya';
  updateBlNilaiUnit();
}

async function saveBiayaLainnya() {
  const payload = {
    id:          document.getElementById('bl_id').value || null,
    nama:        document.getElementById('bl_nama').value.trim(),
    tipe_biaya:  document.getElementById('bl_tipe').value,
    nilai_biaya: parseFloat(document.getElementById('bl_nilai').value) || 0,
    is_active:   parseInt(document.getElementById('bl_active').value),
    urutan:      parseInt(document.getElementById('bl_urutan').value) || 0,
    outlet_id:   document.getElementById('bl_outlet').value || null,
  };
  if (!payload.nama) {
    showToast('Nama biaya wajib diisi', 'error'); return;
  }
  try {
    const r = await fetch('?action=biaya_lainnya_save', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify(payload)
    });
    const d = await r.json();
    if (d.error) { showToast(d.error, 'error'); return; }
    showToast('Biaya tersimpan', 'success');
    resetBiayaLainnyaForm();
    await loadBiayaLainnyaTiers();
  } catch(e) {
    showToast('Gagal simpan: ' + e.message, 'error');
  }
}

async function deleteBiayaLainnya(id) {
  if (!await lmConfirm('Hapus biaya ini? Aksi tidak bisa di-undo.')) return;
  try {
    const r = await fetch('?action=biaya_lainnya_delete', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify({id})
    });
    const d = await r.json();
    if (d.error) { showToast(d.error, 'error'); return; }
    showToast('Biaya dihapus', 'success');
    await loadBiayaLainnyaTiers();
  } catch(e) {
    showToast('Gagal hapus: ' + e.message, 'error');
  }
}

```

(Fungsi `loadOutletsForTier()`, `allOutlets`, `esc()`, `grpRibu()`,
`lmSyncSel()`, `showToast()`, `lmConfirm()`, `csrfToken()` SEMUA sudah ada
di file ini dari fitur Tier Express — reuse langsung, jangan didefinisikan
ulang.)

- [ ] **Step 5: Verifikasi manual — simulasi CRUD via PHP CLI**

```bash
php -r '
require "master/config/db.php"; require "core/Database.php";
$db = Database::get();
$db->prepare("INSERT INTO hl_biaya_lainnya_tier (tenant_id, outlet_id, nama, tipe_biaya, nilai_biaya, is_active) VALUES (18, NULL, \"Verif CRUD\", \"flat\", 1500, 1)")->execute();
$id = $db->lastInsertId();
$row = $db->query("SELECT nama, nilai_biaya, is_active FROM hl_biaya_lainnya_tier WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
echo "Insert OK: " . json_encode($row) . "\n";
$db->prepare("DELETE FROM hl_biaya_lainnya_tier WHERE id=?")->execute([$id]);
echo "Cleanup OK\n";
'
```

Expected: `Insert OK: {"nama":"Verif CRUD","nilai_biaya":"1500.00","is_active":1}`
lalu `Cleanup OK`.

Lalu `php -l layanan.php` → "No syntax errors detected".

- [ ] **Step 6: Commit**

```bash
git add layanan.php
git commit -m "feat(layanan): CRUD Jenis Biaya Lainnya — mirip Tier Express, tanpa estimasi jam"
```

---

### Task 4: `pos.php` — hapus input manual, terapkan otomatis

**Files:**
- Modify: `pos.php`

**Interfaces:**
- Consumes: `BiayaLainnyaTier::calcAppliedFees()` dari Task 2.
- Produces: order baru otomatis punya breakdown
  `hl_transaksi_biaya_lainnya` + rollup `hl_transaksi.biaya_lainnya` —
  dikonsumsi Task 6 (struk).

- [ ] **Step 1: Require class baru**

Cari baris require di awal file (dekat `require_once ROOT . '/core/Loyalty.php';` atau require lain) — tambahkan:

```php
require_once ROOT . '/core/BiayaLainnyaTier.php';
```

- [ ] **Step 2: Hapus HTML input manual, ganti box read-only**

Cari (sekitar baris 1538-1559):

```php
            <div class="form-group" id="biayaTambahanBox" style="display:none;background:#FEF3C7;border:1px solid #FDE68A;border-radius:8px;padding:10px 14px;margin-bottom:8px;font-size:12.5px;color:#92400E;">
              ⚡ <strong>Total Biaya Express:</strong> Rp <span id="biayaTotalDisplay">0</span>
              <span style="display:block;font-size:11px;color:#A16207;margin-top:2px;">Otomatis dari pilihan tier di tiap baris item</span>
              <input type="hidden" id="f_biaya_tambahan" value="0"/>
              <input type="hidden" id="f_tipe_order" value="reguler"/>
            </div>

            <!-- Biaya Lainnya — manual, bebas apa saja, owner yang isi -->
            <div class="form-row cols3">
              <div class="form-group" style="flex:2">
                <label>Biaya Lainnya (opsional)</label>
                <input type="text" id="f_biaya_lainnya_label" maxlength="100"
                  placeholder="cth: Biaya Packing Kardus" oninput="recalc()"/>
              </div>
              <div class="form-group">
                <label>Nominal (Rp)</label>
                <input type="number" id="f_biaya_lainnya" value="0" min="0"
                  onfocus="this.value=''"
                  onblur="if(this.value===''){ this.value='0'; recalc(); }"
                  oninput="lmCleanNum(this,false);recalc()"/>
              </div>
            </div>
```

Ganti jadi (box Biaya Express TETAP SAMA, box Biaya Lainnya jadi
read-only, boleh multi-baris):

```php
            <div class="form-group" id="biayaTambahanBox" style="display:none;background:#FEF3C7;border:1px solid #FDE68A;border-radius:8px;padding:10px 14px;margin-bottom:8px;font-size:12.5px;color:#92400E;">
              ⚡ <strong>Total Biaya Express:</strong> Rp <span id="biayaTotalDisplay">0</span>
              <span style="display:block;font-size:11px;color:#A16207;margin-top:2px;">Otomatis dari pilihan tier di tiap baris item</span>
              <input type="hidden" id="f_biaya_tambahan" value="0"/>
              <input type="hidden" id="f_tipe_order" value="reguler"/>
            </div>

            <!-- Biaya Lainnya — OTOMATIS dari tier aktif, read-only, tidak ada input -->
            <div class="form-group" id="biayaLainnyaBox" style="display:none;background:#E0F2FE;border:1px solid #BAE6FD;border-radius:8px;padding:10px 14px;margin-bottom:8px;font-size:12.5px;color:#0C4A6E;">
              💰 <strong>Biaya Lainnya (otomatis):</strong>
              <div id="biayaLainnyaBreakdown" style="margin-top:4px"></div>
            </div>
```

- [ ] **Step 3: Tambah loader tier + hitung breakdown di JS**

Cari (sekitar baris 2037):

```javascript
let availableTiers = [];  // {nama_tier, estimasi_jam, tipe_biaya, nilai_biaya}
```

Tambahkan setelahnya:

```javascript
let availableTiers = [];  // {nama_tier, estimasi_jam, tipe_biaya, nilai_biaya}
let availableBiayaLainnyaTiers = [];  // {nama, tipe_biaya, nilai_biaya} — auto-apply, bukan pilihan
```

Cari fungsi `loadGlobalTiers()` (sekitar baris 2052-2060):

```javascript
async function loadGlobalTiers() {
  try {
    const r = await fetch('pos.php?action=express_tiers');
    const d = await r.json();
    availableTiers = d.tiers || [];
  } catch(e) {
    availableTiers = [];
  }
}
```

Tambahkan fungsi baru setelahnya:

```javascript
async function loadGlobalTiers() {
  try {
    const r = await fetch('pos.php?action=express_tiers');
    const d = await r.json();
    availableTiers = d.tiers || [];
  } catch(e) {
    availableTiers = [];
  }
}

async function loadBiayaLainnyaTiers() {
  try {
    const r = await fetch('pos.php?action=biaya_lainnya_tiers');
    const d = await r.json();
    availableBiayaLainnyaTiers = d.tiers || [];
  } catch(e) {
    availableBiayaLainnyaTiers = [];
  }
  recalc(); // breakdown baru siap, langsung refresh tampilan
}

// Hitung breakdown biaya lainnya dari tier aktif thd subtotal — CUMA
// utk PREVIEW tampilan, nilai final tetap dihitung ulang di server
// (anti-tamper, lihat action=save).
function calcBiayaLainnyaBreakdown(subtotal) {
  return availableBiayaLainnyaTiers
    .map(t => ({
      nama: t.nama,
      nominal: t.tipe_biaya === 'flat' ? parseFloat(t.nilai_biaya) : Math.round(subtotal * (parseFloat(t.nilai_biaya) / 100)),
    }))
    .filter(x => x.nominal > 0);
}
```

- [ ] **Step 4: Panggil loader saat halaman init**

Cari (sekitar baris 1796-1797):

```javascript
  loadGlobalTiers();
  loadParfumList();
```

Ganti jadi:

```javascript
  loadGlobalTiers();
  loadBiayaLainnyaTiers();
  loadParfumList();
```

- [ ] **Step 5: Update `recalc()` — hitung breakdown, render box, masuk ke total**

Cari (sekitar baris 2172-2174):

```javascript
  // Total = subtotal − diskon − redeem + biaya tambahan + biaya lainnya
  const biayaLainnya = parseFloat(document.getElementById('f_biaya_lainnya')?.value) || 0;
  const total    = Math.max(subtotal - diskon - redeemValue + biayaTbh + biayaLainnya, 0);
```

Ganti jadi:

```javascript
  // Total = subtotal − diskon − redeem + biaya tambahan + biaya lainnya
  const biayaLainnyaRows = calcBiayaLainnyaBreakdown(subtotal);
  const biayaLainnya = biayaLainnyaRows.reduce((s, r) => s + r.nominal, 0);
  const total    = Math.max(subtotal - diskon - redeemValue + biayaTbh + biayaLainnya, 0);

  // Render box breakdown (read-only, cuma display)
  const blBox = document.getElementById('biayaLainnyaBox');
  const blBreakdownEl = document.getElementById('biayaLainnyaBreakdown');
  if (blBox && blBreakdownEl) {
    if (biayaLainnyaRows.length > 0) {
      blBox.style.display = 'block';
      blBreakdownEl.innerHTML = biayaLainnyaRows.map(r =>
        `<div>${r.nama}: Rp ${r.nominal.toLocaleString('id-ID')}</div>`
      ).join('');
    } else {
      blBox.style.display = 'none';
    }
  }
```

- [ ] **Step 6: Hapus field biaya_lainnya dari payload**

Cari (sekitar baris 2665-2676):

```javascript
  const payload = {
    tanggal:        document.getElementById('f_tanggal').value,
    estimasi:       document.getElementById('f_estimasi').value,
    nama_pelanggan: nama,
    telepon:        document.getElementById('f_telepon').value,
    catatan:        document.getElementById('f_catatan').value,
    diskon:         document.getElementById('f_diskon').value,
    biaya_tambahan: document.getElementById('f_biaya_tambahan').value,
    tipe_order:     document.getElementById('f_tipe_order').value,
    biaya_lainnya:       document.getElementById('f_biaya_lainnya').value,
    biaya_lainnya_label: document.getElementById('f_biaya_lainnya_label').value,
    parfum:         document.getElementById('f_parfum')?.value || '',
```

Ganti jadi (hapus 2 baris biaya_lainnya — server tidak lagi menerima
apa pun dari klien soal ini, dihitung sendiri dari tier aktif):

```javascript
  const payload = {
    tanggal:        document.getElementById('f_tanggal').value,
    estimasi:       document.getElementById('f_estimasi').value,
    nama_pelanggan: nama,
    telepon:        document.getElementById('f_telepon').value,
    catatan:        document.getElementById('f_catatan').value,
    diskon:         document.getElementById('f_diskon').value,
    biaya_tambahan: document.getElementById('f_biaya_tambahan').value,
    tipe_order:     document.getElementById('f_tipe_order').value,
    parfum:         document.getElementById('f_parfum')?.value || '',
```

- [ ] **Step 7: Tambah endpoint `action=biaya_lainnya_tiers`**

Cari (sekitar baris 38-40):

```php
    if ($action === 'express_tiers') {
        echo json_encode(['tiers' => ExpressTier::forTenant($tid, $oid)]); exit;
    }
```

Tambahkan setelahnya:

```php
    if ($action === 'express_tiers') {
        echo json_encode(['tiers' => ExpressTier::forTenant($tid, $oid)]); exit;
    }

    if ($action === 'biaya_lainnya_tiers') {
        echo json_encode(['tiers' => BiayaLainnyaTier::activeForTenant($tid, $oid)]); exit;
    }
```

- [ ] **Step 8: Server — hitung ulang dari tier (anti-tamper), ganti INSERT**

Cari (sekitar baris 453-460):

```php
            // Biaya Lainnya — manual bebas, TIDAK di-recompute server (beda dgn
            // biaya_tambahan yg wajib re-derive dari tier demi anti-tamper).
            $biayaLainnya      = max(0, floatval($data['biaya_lainnya'] ?? 0));
            $biayaLainnyaLabel = substr(trim(strip_tags($data['biaya_lainnya_label'] ?? '')), 0, 100);

            // Total final (subtotal − diskon − member_diskon + biaya tambahan + biaya lainnya)
            $diskonTotal = $diskon + $redeemValue + $memberDiskon;
            $total    = max(0, $subtotal - $diskonTotal + $biayaTbh + $biayaLainnya);
```

Ganti jadi (server hitung sendiri dari tier aktif, TIDAK BACA `$data`
sama sekali — anti-tamper penuh, sama kelasnya dgn `biaya_tambahan`):

```php
            // Biaya Lainnya — dihitung server dari tier aktif (anti-tamper,
            // sama seperti biaya_tambahan), TIDAK dipercaya dari klien sama sekali.
            $biayaLainnyaRows  = BiayaLainnyaTier::calcAppliedFees($tid, $oid, $subtotal);
            $biayaLainnya      = array_sum(array_column($biayaLainnyaRows, 'nominal'));

            // Total final (subtotal − diskon − member_diskon + biaya tambahan + biaya lainnya)
            $diskonTotal = $diskon + $redeemValue + $memberDiskon;
            $total    = max(0, $subtotal - $diskonTotal + $biayaTbh + $biayaLainnya);
```

- [ ] **Step 9: Ganti guard kolom & INSERT dinamis**

Cari (sekitar baris 489-491):

```php
            $hasBiayaLainnya = true;
            try { $db->query("SELECT biaya_lainnya, biaya_lainnya_label FROM hl_transaksi LIMIT 1"); }
            catch (Throwable) { $hasBiayaLainnya = false; }
```

Ganti jadi (kolom `biaya_lainnya_label` sudah di-drop Task 1, jangan
di-SELECT lagi — akan error kalau masih disebut):

```php
            $hasBiayaLainnya = true;
            try { $db->query("SELECT biaya_lainnya FROM hl_transaksi LIMIT 1"); }
            catch (Throwable) { $hasBiayaLainnya = false; }
```

Cari (sekitar baris 544-546):

```php
            if ($hasBiayaLainnya && $biayaLainnya > 0) {
                $cols[] = 'biaya_lainnya';       $vals[] = $biayaLainnya;
                $cols[] = 'biaya_lainnya_label'; $vals[] = $biayaLainnyaLabel ?: null;
            }
```

Ganti jadi:

```php
            if ($hasBiayaLainnya && $biayaLainnya > 0) {
                $cols[] = 'biaya_lainnya'; $vals[] = $biayaLainnya;
            }
```

- [ ] **Step 10: Simpan breakdown ke tabel detail**

Cari baris `$trx_id = $db->lastInsertId();` (sekitar baris 560).
Tambahkan TEPAT SETELAHNYA:

```php
            $trx_id = $db->lastInsertId();

            // Simpan breakdown Biaya Lainnya (snapshot per baris)
            if (!empty($biayaLainnyaRows)) {
                $blStmt = $db->prepare(
                    "INSERT INTO hl_transaksi_biaya_lainnya (tenant_id, outlet_id, transaksi_id, nama, nominal) VALUES (?,?,?,?,?)"
                );
                foreach ($biayaLainnyaRows as $row) {
                    $blStmt->execute([$tid, $oid, $trx_id, $row['nama'], $row['nominal']]);
                }
            }
```

- [ ] **Step 11: Verifikasi manual — simulasi kalkulasi end-to-end**

```bash
php -r '
require "master/config/db.php"; require "core/Database.php"; require "core/BiayaLainnyaTier.php";
$db = Database::get();
$tid = 18; $oid = 13;

// Seed 1 tier aktif sementara
$db->prepare("INSERT INTO hl_biaya_lainnya_tier (tenant_id, outlet_id, nama, tipe_biaya, nilai_biaya, is_active) VALUES (?,?,?,?,?,?)")
   ->execute([$tid, null, "Verif Admin", "flat", 2500, 1]);

$rows = BiayaLainnyaTier::calcAppliedFees($tid, $oid, 100000);
echo "Rows: " . json_encode($rows) . "\n";
echo "Sum: " . array_sum(array_column($rows, "nominal")) . " (expected 2500)\n";

$db->prepare("DELETE FROM hl_biaya_lainnya_tier WHERE tenant_id=? AND nama=?")->execute([$tid, "Verif Admin"]);
echo "Cleanup OK\n";
'
```

Expected: `Sum: 2500 (expected 2500)` lalu `Cleanup OK`.

Lalu `php -l pos.php` → "No syntax errors detected".

- [ ] **Step 12: Commit**

```bash
git add pos.php
git commit -m "feat(pos): Biaya Lainnya otomatis dari tier aktif — hapus input manual, hitung server anti-tamper"
```

---

### Task 5: `orders.php` — revert ke read-only

**Files:**
- Modify: `orders.php`

**Interfaces:**
- Consumes: tabel `hl_transaksi_biaya_lainnya` dari Task 1 (breakdown
  read-only display).

- [ ] **Step 1: Kembalikan SELECT `$oldRow` — hapus `biaya_lainnya_label`**

Cari (sekitar baris 229-233):

```php
            $oldRow = $db->prepare("SELECT status_proses,status_bayar,catatan,dp,total,diskon,
                                           pelanggan_id,telepon,no_order,nama_pelanggan,
                                           biaya_tambahan,biaya_lainnya,biaya_lainnya_label
                                      FROM hl_transaksi
                                     WHERE tenant_id=? AND outlet_id=? AND id=? FOR UPDATE");
```

Ganti jadi (kolom `biaya_lainnya_label` sudah di-drop — jangan di-SELECT):

```php
            $oldRow = $db->prepare("SELECT status_proses,status_bayar,catatan,dp,total,diskon,
                                           pelanggan_id,telepon,no_order,nama_pelanggan,
                                           biaya_tambahan,biaya_lainnya
                                      FROM hl_transaksi
                                     WHERE tenant_id=? AND outlet_id=? AND id=? FOR UPDATE");
```

- [ ] **Step 2: Hapus logic baca/fallback + permission-gate + gate 'berubah'**

Cari (sekitar baris 249-265):

```php
            $diskonBerubah = abs((float)($data['diskon'] ?? 0) - (float)$oldRow['diskon']) > 0.001;

            // biaya_lainnya — manual bebas, kalau request ini TIDAK mengirim field-nya
            // (mis. request yang cuma ubah status_proses) pertahankan nilai lama,
            // JANGAN reset ke 0 diam-diam.
            $biayaLainnya = array_key_exists('biaya_lainnya', $data)
                ? max(0, floatval($data['biaya_lainnya']))
                : (float)($oldRow['biaya_lainnya'] ?? 0);
            $biayaLainnyaLabel = array_key_exists('biaya_lainnya_label', $data)
                ? substr(trim(strip_tags($data['biaya_lainnya_label'] ?? '')), 0, 100)
                : (string)($oldRow['biaya_lainnya_label'] ?? '');
            $biayaLainnyaBerubah = abs($biayaLainnya - (float)($oldRow['biaya_lainnya'] ?? 0)) > 0.001;

            if (($itemsChanged || $diskonBerubah || $biayaLainnyaBerubah) && !hasPermission('orders.edit')) {
                $db->rollBack();
                echo json_encode(['error' => 'Butuh izin edit order untuk mengubah layanan/diskon']); exit;
            }
```

Ganti jadi (balik ke kondisi SEBELUM fitur ini ada — `biaya_lainnya` sudah
tidak bisa diedit dari form ini sama sekali, jadi tidak perlu di-gate):

```php
            $diskonBerubah = abs((float)($data['diskon'] ?? 0) - (float)$oldRow['diskon']) > 0.001;

            if (($itemsChanged || $diskonBerubah) && !hasPermission('orders.edit')) {
                $db->rollBack();
                echo json_encode(['error' => 'Butuh izin edit order untuk mengubah layanan/diskon']); exit;
            }
```

- [ ] **Step 3: Kembalikan rumus total — `biaya_lainnya` jadi snapshot read-only**

Cari (sekitar baris 275-284):

```php
            // biaya_tambahan = snapshot dari saat order dibuat, TIDAK di-recompute
            // di sini (edit order tidak mengelola ulang express tier). BUGFIX: dulu
            // rumus di bawah cuma "subtotal - diskon", biaya_tambahan ikut hilang
            // dari total setiap kali item order diedit.
            $biayaTambahanLama = (float)($oldRow['biaya_tambahan'] ?? 0);

            $diskon = floatval($data['diskon'] ?? 0);
            $total  = $subtotal > 0
                ? max(0, $subtotal - $diskon + $biayaTambahanLama + $biayaLainnya)
                : max(0, floatval($data['total'] ?? 0));
```

Ganti jadi (`biaya_lainnya` SEKARANG treatment-nya sama persis dgn
`biaya_tambahan` — snapshot dari `$oldRow`, TIDAK dari `$data`):

```php
            // biaya_tambahan & biaya_lainnya = snapshot dari saat order dibuat,
            // TIDAK di-recompute di sini (Orders tidak mengelola ulang tier —
            // baik Express maupun Biaya Lainnya, keduanya murni auto-generate
            // saat order dibuat). BUGFIX lama: dulu rumus di bawah cuma
            // "subtotal - diskon", biaya_tambahan ikut hilang dari total
            // setiap kali item order diedit — sudah dibetulkan & TETAP begini.
            $biayaTambahanLama = (float)($oldRow['biaya_tambahan'] ?? 0);
            $biayaLainnyaLama  = (float)($oldRow['biaya_lainnya'] ?? 0);

            $diskon = floatval($data['diskon'] ?? 0);
            $total  = $subtotal > 0
                ? max(0, $subtotal - $diskon + $biayaTambahanLama + $biayaLainnyaLama)
                : max(0, floatval($data['total'] ?? 0));
```

- [ ] **Step 4: Kembalikan `'berubah'` di gate resolver**

Cari (sekitar baris ~295, di dalam `OrderEditResolver::resolve([...])`):

```php
                'berubah'         => $itemsChanged || $diskonBerubah || $biayaLainnyaBerubah,
```

Ganti jadi:

```php
                'berubah'         => $itemsChanged || $diskonBerubah,
```

- [ ] **Step 5: Hapus `biaya_lainnya`/`biaya_lainnya_label` dari `UPDATE SET`**

Cari (sekitar baris 332-343):

```php
            $setParts = [
                'status_proses=?', 'status_bayar=?', 'catatan=?', 'catatan_internal=?',
                'metode_bayar=?', 'dp=?', 'sisa_bayar=?', 'diskon=?', 'total=?',
                'subtotal=?', 'estimasi_selesai=?', 'biaya_lainnya=?', 'biaya_lainnya_label=?',
            ];
            $params = [
                $sp, $sbayar,
                $data['catatan'] ?? '', $data['catatan_internal'] ?? '',
                $data['metode_bayar'] ?? 'cash',
                $dp, $sisa, $diskon, $total, $subtotal > 0 ? $subtotal : null,
                $data['estimasi'] ?: null,
                $biayaLainnya, $biayaLainnyaLabel !== '' ? $biayaLainnyaLabel : null,
            ];
```

Ganti jadi (kolom `biaya_lainnya` TIDAK disentuh UPDATE sama sekali —
sama persis perlakuan `biaya_tambahan`, yang juga tidak ada di
`$setParts` ini):

```php
            $setParts = [
                'status_proses=?', 'status_bayar=?', 'catatan=?', 'catatan_internal=?',
                'metode_bayar=?', 'dp=?', 'sisa_bayar=?', 'diskon=?', 'total=?',
                'subtotal=?', 'estimasi_selesai=?',
            ];
            $params = [
                $sp, $sbayar,
                $data['catatan'] ?? '', $data['catatan_internal'] ?? '',
                $data['metode_bayar'] ?? 'cash',
                $dp, $sisa, $diskon, $total, $subtotal > 0 ? $subtotal : null,
                $data['estimasi'] ?: null,
            ];
```

- [ ] **Step 6: Hapus 2 input edit dari modal, ganti breakdown read-only**

Cari (sekitar baris 1996, 1 baris panjang):

```javascript
      <div class="tb-row"><span class="tb-label">Biaya Lainnya</span><span class="tb-value">${CAN_EDIT_ORDER ? `<input type="text" id="edit_biaya_lainnya_label" value="${(d.biaya_lainnya_label||'').replace(/"/g,'&quot;')}" placeholder="label" maxlength="100" oninput="recalcEdit()" style="width:90px;background:transparent;border:none;border-bottom:1px solid rgba(255,255,255,.3);color:white;font-size:12px;padding:0;outline:none;margin-right:4px"/><input type="number" id="edit_biaya_lainnya" value="${Math.round(d.biaya_lainnya||0)}" min="0" step="500" oninput="recalcEdit()" style="width:70px;background:transparent;border:none;border-bottom:1px solid rgba(255,255,255,.3);color:white;font-family:var(--mono);font-size:13px;padding:0;outline:none"/>` : (d.biaya_lainnya > 0 ? `<span style="font-family:var(--mono);color:white">${esc(d.biaya_lainnya_label||'Biaya Lainnya')}: Rp ${grpRibu(d.biaya_lainnya)}</span>` : '<span style="color:rgba(255,255,255,.5)">-</span>')}</span></div>
```

Ganti jadi (SELALU read-only, tidak ada mode edit sama sekali — akan
diisi breakdown asli via `renderBiayaLainnyaBreakdown(id)` di Step 7,
karena butuh fetch async terpisah ke tabel detail):

```javascript
      <div class="tb-row"><span class="tb-label">Biaya Lainnya</span><span class="tb-value" id="viewBiayaLainnya">${d.biaya_lainnya > 0 ? 'Rp ' + grpRibu(d.biaya_lainnya) : '-'}</span></div>
```

- [ ] **Step 7: Tambah endpoint + breakdown detail di modal**

Cari action `get` (dipakai `openDetail()` utk fetch data order) — sekitar
baris 156-165 sebelumnya di plan Biaya Lainnya lama tapi cari ulang string
literal `if ($action === 'get')`:

```php
    if ($action === 'get') {
        $id = intval($_GET['id']);
        $t  = TenantQuery::rawOne("SELECT * FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND id=?", [$tid, $oid, $id]);
        if (!$t) { echo json_encode(['error'=>'Not found']); exit; }
        $t['items'] = TenantQuery::raw("SELECT * FROM hl_transaksi_item WHERE tenant_id=? AND outlet_id=? AND transaksi_id=? ORDER BY id", [$tid, $oid, $id]);
        $t['logs']  = TenantQuery::raw("SELECT * FROM hl_proses_log WHERE transaksi_id=? ORDER BY created_at DESC LIMIT 10", [$id]);
        require_once ROOT . '/core/DeleteRequest.php';
        $t['pending_delete'] = DeleteRequest::isPending('transaksi', $id, $tid);
        echo json_encode($t); exit;
    }
```

Ganti jadi (tambah `biaya_lainnya_breakdown` ke response — `SELECT *`
sudah otomatis include kolom `biaya_lainnya` rollup, jadi cuma perlu
tambah query detail):

```php
    if ($action === 'get') {
        $id = intval($_GET['id']);
        $t  = TenantQuery::rawOne("SELECT * FROM hl_transaksi WHERE tenant_id=? AND outlet_id=? AND id=?", [$tid, $oid, $id]);
        if (!$t) { echo json_encode(['error'=>'Not found']); exit; }
        $t['items'] = TenantQuery::raw("SELECT * FROM hl_transaksi_item WHERE tenant_id=? AND outlet_id=? AND transaksi_id=? ORDER BY id", [$tid, $oid, $id]);
        $t['logs']  = TenantQuery::raw("SELECT * FROM hl_proses_log WHERE transaksi_id=? ORDER BY created_at DESC LIMIT 10", [$id]);
        $t['biaya_lainnya_breakdown'] = TenantQuery::raw("SELECT nama, nominal FROM hl_transaksi_biaya_lainnya WHERE tenant_id=? AND outlet_id=? AND transaksi_id=? ORDER BY id", [$tid, $oid, $id]);
        require_once ROOT . '/core/DeleteRequest.php';
        $t['pending_delete'] = DeleteRequest::isPending('transaksi', $id, $tid);
        echo json_encode($t); exit;
    }
```

Cari fungsi `openDetail(id)` (sekitar baris 1906-1913 sebelumnya — cari
ulang string literal `async function openDetail(id)`), tepat setelah
baris `const d = await r.json();` DAN setelah `document.getElementById('modalBody').innerHTML = ...` (render HTML modal selesai dulu, supaya elemen `#viewBiayaLainnya` sudah ada di DOM). Tambahkan panggilan baru di
akhir fungsi `openDetail`:

```javascript
  renderBiayaLainnyaBreakdown(d.biaya_lainnya_breakdown || []);
```

Tambahkan fungsi baru ini di dekat fungsi `openDetail` (taruh persis
sebelum atau sesudahnya):

```javascript
function renderBiayaLainnyaBreakdown(rows) {
  const el = document.getElementById('viewBiayaLainnya');
  if (!el) return;
  if (!rows.length) { el.textContent = '-'; return; }
  el.innerHTML = rows.map(r => `${esc(r.nama)}: Rp ${grpRibu(r.nominal)}`).join('<br>');
}
```

- [ ] **Step 8: Hapus field dari `editStateJSON()`**

Cari (sekitar baris 1620):

```javascript
    bl: g('edit_biaya_lainnya'), bll: g('edit_biaya_lainnya_label'),
```

HAPUS baris ini seluruhnya (tidak ada penggantinya — field ini sudah
tidak ada lagi di modal).

- [ ] **Step 9: Hapus dari `recalcEdit()`**

Cari (sekitar baris 2335-2336):

```javascript
  const biayaLainnya = parseFloat(document.getElementById('edit_biaya_lainnya')?.value) || 0;
  const tot  = Math.max(sub - dis + (currentOrderBiayaTambahan || 0) + biayaLainnya, 0);
```

Ganti jadi (`biaya_lainnya` TIDAK ikut preview recalc lagi di sini — sama
seperti `biaya_tambahan`, cuma `currentOrderBiayaTambahan` yang sudah ada
sebelumnya. Tambahkan variabel serupa `currentOrderBiayaLainnya` supaya
preview TOTAL di modal edit tetap akurat, konsisten dgn cara
`biaya_tambahan` diperlakukan):

```javascript
  const tot  = Math.max(sub - dis + (currentOrderBiayaTambahan || 0) + (currentOrderBiayaLainnya || 0), 0);
```

Cari deklarasi `let currentOrderBiayaTambahan = 0;` (baris 1577 area, dari
fix sebelumnya) — tambahkan variabel baru setelahnya:

```javascript
let currentEditId = null;
let currentOrderBiayaTambahan = 0;
let currentOrderBiayaLainnya = 0;
```

Cari baris `currentOrderBiayaTambahan = parseFloat(d.biaya_tambahan) || 0;`
di dalam `openDetail(id)` — tambahkan baris serupa setelahnya:

```javascript
  currentOrderBiayaTambahan = parseFloat(d.biaya_tambahan) || 0;
  currentOrderBiayaLainnya  = parseFloat(d.biaya_lainnya) || 0;
```

- [ ] **Step 10: Hapus dari payload `saveEdit()`**

Cari (sekitar baris 2371-2372):

```javascript
    biaya_lainnya:       document.getElementById('edit_biaya_lainnya')?.value ?? '0',
    biaya_lainnya_label: document.getElementById('edit_biaya_lainnya_label')?.value ?? '',
```

HAPUS kedua baris ini seluruhnya dari object `payload`.

- [ ] **Step 11: Verifikasi manual**

```bash
php -l orders.php
```

Expected: "No syntax errors detected".

Simulasi rumus (order lama dgn biaya_lainnya snapshot 5000, item diedit
tapi biaya_lainnya harus tetap 5000 di total, TIDAK bisa diubah dari
request):

```bash
php -r '
$oldRow = ["biaya_tambahan"=>0, "biaya_lainnya"=>5000];
$subtotal = 100000; $diskon = 10000;
$biayaTambahanLama = (float)($oldRow["biaya_tambahan"] ?? 0);
$biayaLainnyaLama  = (float)($oldRow["biaya_lainnya"] ?? 0);
$total = $subtotal > 0 ? max(0, $subtotal - $diskon + $biayaTambahanLama + $biayaLainnyaLama) : 0;
echo "Total: $total (expected 95000 = 100000-10000+0+5000)\n";
'
```

Expected: `Total: 95000 (expected 95000 = 100000-10000+0+5000)`.

- [ ] **Step 12: Commit**

```bash
git add orders.php
git commit -m "revert(orders): Biaya Lainnya balik jadi read-only snapshot — sudah tidak bisa diedit, otomatis dari tier"
```

---

### Task 6: `core/StrukGenerator.php` — render breakdown multi-baris

**Files:**
- Modify: `core/StrukGenerator.php`
- Modify: `tests/struk/test_payment_aid.php`

**Interfaces:**
- Consumes: tabel `hl_transaksi_biaya_lainnya` dari Task 1.

- [ ] **Step 1: Set `_biaya_lainnya_rows` di `generate()`**

Cari (sekitar baris 202-209, blok "Load items"):

```php
        // ── Load items ────────────────────────────────
        $itemSt = $db->prepare(
            "SELECT * FROM hl_transaksi_item
              WHERE transaksi_id = ? AND tenant_id = ?
              ORDER BY id ASC"
        );
        $itemSt->execute([$transaksiId, $tid]);
        $items = $itemSt->fetchAll(PDO::FETCH_ASSOC);
```

Tambahkan setelahnya:

```php
        // ── Load items ────────────────────────────────
        $itemSt = $db->prepare(
            "SELECT * FROM hl_transaksi_item
              WHERE transaksi_id = ? AND tenant_id = ?
              ORDER BY id ASC"
        );
        $itemSt->execute([$transaksiId, $tid]);
        $items = $itemSt->fetchAll(PDO::FETCH_ASSOC);

        // ── Load breakdown Biaya Lainnya (bisa >1 baris) ──
        $blSt = $db->prepare(
            "SELECT nama, nominal FROM hl_transaksi_biaya_lainnya
              WHERE transaksi_id = ? AND tenant_id = ?
              ORDER BY id ASC"
        );
        $blSt->execute([$transaksiId, $tid]);
        $trx['_biaya_lainnya_rows'] = $blSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
```

- [ ] **Step 2: Ganti render 1-baris jadi loop di `renderThermal()`**

Cari (sekitar baris 673-677):

```php
        $biayaLainnya = (float)($trx['biaya_lainnya'] ?? 0);
        if ($biayaLainnya > 0) {
            $lainnyaLabel = trim((string)($trx['biaya_lainnya_label'] ?? '')) ?: 'Biaya Lainnya';
            $h .= self::tRow($lainnyaLabel, 'Rp ' . self::rpNum($biayaLainnya), $maxChar);
        }
```

Ganti jadi:

```php
        foreach (($trx['_biaya_lainnya_rows'] ?? []) as $blRow) {
            $blNominal = (float)($blRow['nominal'] ?? 0);
            if ($blNominal <= 0) continue;
            $h .= self::tRow($blRow['nama'], 'Rp ' . self::rpNum($blNominal), $maxChar);
        }
```

- [ ] **Step 3: Ganti render 1-baris jadi loop di `renderPdf()`**

Cari (sekitar baris 1025-1029):

```php
        $biayaLainnyaPdf = (float)($trx['biaya_lainnya'] ?? 0);
        if ($biayaLainnyaPdf > 0) {
            $lainnyaLabelPdf = trim((string)($trx['biaya_lainnya_label'] ?? '')) ?: 'Biaya Lainnya';
            $h .= "  <tr><td>" . htmlspecialchars($lainnyaLabelPdf) . "</td><td class='r'>+Rp " . self::rpNum($biayaLainnyaPdf) . "</td></tr>\n";
        }
```

Ganti jadi:

```php
        foreach (($trx['_biaya_lainnya_rows'] ?? []) as $blRowPdf) {
            $blNominalPdf = (float)($blRowPdf['nominal'] ?? 0);
            if ($blNominalPdf <= 0) continue;
            $h .= "  <tr><td>" . htmlspecialchars($blRowPdf['nama']) . "</td><td class='r'>+Rp " . self::rpNum($blNominalPdf) . "</td></tr>\n";
        }
```

(`$hasBreakdown`/`$hasBreakdownPdf` — baris `|| (float)($trx['biaya_lainnya'] ?? 0) > 0`
yang sudah ada TIDAK PERLU diubah, karena `biaya_lainnya` rollup TETAP
diisi dgn benar oleh Task 4/5, walau breakdown detailnya sekarang dari
tabel terpisah.)

- [ ] **Step 4: Update test — ganti skenario scalar jadi array `_biaya_lainnya_rows`**

Cari blok test lama (sekitar baris 100-126 di `tests/struk/test_payment_aid.php`):

```php
// ── Biaya Lainnya muncul di struk (renderThermal & renderPdf) ──
$trxBiayaLainnya = array_merge($trxQris, [
    'metode_bayar' => 'cash',
    'biaya_lainnya' => 7000,
    'biaya_lainnya_label' => 'Biaya Packing Kardus',
]);
$htmlBl = StrukGenerator::renderThermal($trxBiayaLainnya, [], $tmpl, null, null, $outlet, 58);
ok(str_contains($htmlBl, 'Biaya Packing Kardus'), 'renderThermal tampilkan label biaya_lainnya custom');
ok(str_contains($htmlBl, 'Rp 7.000') || str_contains($htmlBl, 'Rp 7,000'), 'renderThermal tampilkan nominal biaya_lainnya');

$trxBiayaLainnyaNoLabel = array_merge($trxBiayaLainnya, ['biaya_lainnya_label' => '']);
$htmlBl2 = StrukGenerator::renderThermal($trxBiayaLainnyaNoLabel, [], $tmpl, null, null, $outlet, 58);
ok(str_contains($htmlBl2, 'Biaya Lainnya'), 'renderThermal fallback label generik "Biaya Lainnya" kalau kosong');

$trxNoBiayaLainnya = array_merge($trxQris, ['metode_bayar' => 'cash', 'biaya_lainnya' => 0]);
$htmlBl3 = StrukGenerator::renderThermal($trxNoBiayaLainnya, [], $tmpl, null, null, $outlet, 58);
ok(!str_contains($htmlBl3, 'Biaya Lainnya') && !str_contains($htmlBl3, 'Biaya Packing'), 'renderThermal TIDAK render baris biaya_lainnya kalau 0');

// ── renderPdf() coverage untuk Biaya Lainnya ──────────────
// Reuse: $trxBiayaLainnya (biaya_lainnya=7000, label='Biaya Packing Kardus')
$pdfBl = StrukGenerator::renderPdf($trxBiayaLainnya, [], $tmpl, null, null, $outlet, 'a4');
ok(str_contains($pdfBl, 'Biaya Packing Kardus'), 'renderPdf tampilkan label biaya_lainnya custom');
ok(str_contains($pdfBl, 'Rp 7.000') || str_contains($pdfBl, 'Rp 7,000'), 'renderPdf tampilkan nominal biaya_lainnya');

// Reuse: $trxNoBiayaLainnya (biaya_lainnya=0)
$pdfBl4 = StrukGenerator::renderPdf($trxNoBiayaLainnya, [], $tmpl, null, null, $outlet, 'a4');
ok(!str_contains($pdfBl4, 'Biaya Lainnya') && !str_contains($pdfBl4, 'Biaya Packing'), 'renderPdf TIDAK render baris biaya_lainnya kalau 0');
```

Ganti SELURUH blok itu jadi (test multi-baris — 2 baris breakdown
sekaligus, plus kasus kosong):

```php
// ── Biaya Lainnya multi-baris muncul di struk (renderThermal & renderPdf) ──
$trxBiayaLainnya = array_merge($trxQris, [
    'metode_bayar' => 'cash',
    'biaya_lainnya' => 2600, // rollup, dipakai $hasBreakdown check
    '_biaya_lainnya_rows' => [
        ['nama' => 'Biaya Admin', 'nominal' => 2000],
        ['nama' => 'PPN 2%', 'nominal' => 600],
    ],
]);
$htmlBl = StrukGenerator::renderThermal($trxBiayaLainnya, [], $tmpl, null, null, $outlet, 58);
ok(str_contains($htmlBl, 'Biaya Admin'), 'renderThermal tampilkan baris pertama breakdown');
ok(str_contains($htmlBl, 'PPN 2%'), 'renderThermal tampilkan baris kedua breakdown (multi-baris)');
ok(str_contains($htmlBl, 'Rp 2.000') || str_contains($htmlBl, 'Rp 2,000'), 'renderThermal tampilkan nominal baris pertama');
ok(str_contains($htmlBl, 'Rp 600'), 'renderThermal tampilkan nominal baris kedua');

$trxNoBiayaLainnya = array_merge($trxQris, ['metode_bayar' => 'cash', 'biaya_lainnya' => 0, '_biaya_lainnya_rows' => []]);
$htmlBl3 = StrukGenerator::renderThermal($trxNoBiayaLainnya, [], $tmpl, null, null, $outlet, 58);
ok(!str_contains($htmlBl3, 'Biaya Admin') && !str_contains($htmlBl3, 'PPN'), 'renderThermal TIDAK render apa pun kalau breakdown kosong');

// ── renderPdf() coverage — sama persis, multi-baris ──────────
$pdfBl = StrukGenerator::renderPdf($trxBiayaLainnya, [], $tmpl, null, null, $outlet, 'a4');
ok(str_contains($pdfBl, 'Biaya Admin'), 'renderPdf tampilkan baris pertama breakdown');
ok(str_contains($pdfBl, 'PPN 2%'), 'renderPdf tampilkan baris kedua breakdown');

$pdfBl4 = StrukGenerator::renderPdf($trxNoBiayaLainnya, [], $tmpl, null, null, $outlet, 'a4');
ok(!str_contains($pdfBl4, 'Biaya Admin') && !str_contains($pdfBl4, 'PPN'), 'renderPdf TIDAK render apa pun kalau breakdown kosong');
```

- [ ] **Step 5: Jalankan test, pastikan semua PASS**

Run: `php tests/struk/test_payment_aid.php`

Expected: semua baris `PASS`, `All tests passed.`, exit code 0. Hitung
jumlah baris `PASS` di output dan laporkan angka pastinya (jangan
ditebak).

Lalu `php -l core/StrukGenerator.php` → "No syntax errors detected".

- [ ] **Step 6: Commit**

```bash
git add core/StrukGenerator.php tests/struk/test_payment_aid.php
git commit -m "feat(struk): render breakdown Biaya Lainnya multi-baris dari hl_transaksi_biaya_lainnya"
```

---

### Task 7: `hq/export.php` — hapus kolom yang di-drop

**Files:**
- Modify: `hq/export.php`

**Interfaces:**
- Consumes: kolom `biaya_lainnya_label` sudah di-DROP di Task 1 — SELECT
  yang masih menyebutnya akan error.

- [ ] **Step 1: Hapus dari SELECT**

Cari (sekitar baris 125-128):

```php
        $sql = "SELECT id, no_order, tanggal, outlet_id, pelanggan_id, nama_pelanggan, telepon,
                       subtotal, diskon, biaya_tambahan, biaya_lainnya, biaya_lainnya_label, total,
                       dp, sisa_bayar, metode_bayar,
                       tipe_order, status_bayar, status_proses, estimasi_selesai, catatan,
                       parfum, created_by, created_at, updated_at
                  FROM hl_transaksi
```

Ganti jadi (hapus `, biaya_lainnya_label` — `biaya_lainnya` rollup
TETAP ada):

```php
        $sql = "SELECT id, no_order, tanggal, outlet_id, pelanggan_id, nama_pelanggan, telepon,
                       subtotal, diskon, biaya_tambahan, biaya_lainnya, total,
                       dp, sisa_bayar, metode_bayar,
                       tipe_order, status_bayar, status_proses, estimasi_selesai, catatan,
                       parfum, created_by, created_at, updated_at
                  FROM hl_transaksi
```

- [ ] **Step 2: Verifikasi**

Run: `php -l hq/export.php` → "No syntax errors detected".

Run:
```bash
mysql -e "SELECT id, biaya_tambahan, biaya_lainnya FROM hl_transaksi LIMIT 3"
```
Expected: query jalan tanpa error (kolom `biaya_lainnya_label` sudah tidak
disebut sama sekali).

- [ ] **Step 3: Commit**

```bash
git add hq/export.php
git commit -m "fix(export): hapus kolom biaya_lainnya_label dari SELECT (sudah di-drop)"
```

---

## Ringkasan Urutan Task

1. Migrasi DB (2 tabel baru + drop 1 kolom)
2. `core/BiayaLainnyaTier.php` (class hitung tier)
3. `layanan.php` (CRUD Jenis Biaya Lainnya)
4. `pos.php` (otomatis-terapkan saat create order)
5. `orders.php` (revert ke read-only)
6. `core/StrukGenerator.php` (render breakdown multi-baris)
7. `hq/export.php` (hapus kolom yang di-drop)

Task 1 wajib duluan (skema dulu). Task 2 wajib sebelum Task 3/4 (keduanya
pakai class-nya, meski Task 3 cuma tak langsung — CRUD-nya nulis ke tabel
yang class-nya baca). Task 4, 5, 7 semua bisa jalan sequential tanpa
saling bergantung fungsional (tapi tetap urut demi konsistensi, mengikuti
pola sesi sebelumnya). Task 6 idealnya SETELAH Task 4 (perlu tahu bentuk
data breakdown yang beneran disimpan) — walau secara teknis independen
krn skema tabelnya sudah fix di Task 1.

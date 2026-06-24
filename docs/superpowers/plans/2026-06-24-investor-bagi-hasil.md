# Investor & Bagi Hasil Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Owner kelola investor (pemodal) + hitung/distribusi bagi hasil dari laba periode (% kepemilikan × laba bersih scope), terintegrasi penuh dgn modul Keuangan (jurnal modal_disetor + prive + kas).

**Architecture:** 2 tabel baru (hl_investor, hl_bagi_hasil). core/BagiHasilCalculator.php (hitung + distribusi transaksional). hq/investor.php (2 tab CRUD + bagi hasil). Setoran modal → jurnal modal_disetor; distribusi → hl_kas keluar + jurnal prive — konsisten Neraca/Perubahan Modal/Arus Kas/Buku Besar, no double-count.

**Tech Stack:** PHP 8 vanilla, MariaDB, pola HQ page (hq_guard + requirePermission + AJAX X-Requested-With + verifyCsrf), FinancialCalculator::labaRugi() existing.

## Global Constraints

- **Schema:** hl_investor (scope ENUM tenant/outlet, outlet_id NULL, modal_disetor BIGINT, persentase DECIMAL(5,2)); hl_bagi_hasil (periode VARCHAR(7), laba_basis/jumlah BIGINT, persentase DECIMAL(5,2), status ENUM pending/dibayar, kas_id/jurnal_id NULL, UNIQUE(tenant_id,investor_id,periode)).
- **Permission:** `investor.manage` (modul `investor`, aksi `manage`) — owner-only, seed ke role owner.
- **Laba basis:** scope='tenant' → `labaRugi(tid, null, periode)['laba_bersih']`; scope='outlet' → `labaRugi(tid, outlet_id, periode)['laba_bersih']`.
- **Setoran modal → jurnal:** `hl_jurnal_manual` tipe='modal_disetor', arah='kredit', coa_id=COA kode '3-1001', keterangan "Setoran modal investor: {nama}".
- **Distribusi → kas + jurnal:** `hl_kas` (tipe='keluar', kategori='bagi_hasil', jumlah decimal); `hl_jurnal_manual` tipe='prive', arah='debit', coa_id=COA kode '3-1003', keterangan "Bagi hasil investor: {nama} {periode}".
- **Periode rugi (laba_basis ≤ 0):** distribusi disabled/ditolak.
- **% > 100% per scope-pool:** warning, tidak hard-block.
- **Snapshot:** hl_bagi_hasil simpan laba_basis + persentase + jumlah saat distribusi (history immutable).
- **Tenant scope:** semua query WHERE tenant_id=?. CSRF semua POST. Soft delete investor (is_active=0).
- **HQ page pattern:** `define('ROOT',dirname(__DIR__)); require hq_guard.php; requirePermission('investor.manage');` AJAX via `if(!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){ verifyCsrf(); ... }`.
- **mysql client:** /opt/homebrew/opt/mysql-client/bin/mysql. No php CLI lokal → smoke via deploy/browser.

---

## File Structure

**New:**
- `db/migrations/2026-06-24-investor-bagi-hasil.sql` — 2 tabel
- `core/BagiHasilCalculator.php` — hitung() + distribusi()
- `hq/investor.php` — UI 2 tab + AJAX CRUD + distribusi

**Modified:**
- `core/TenantProvisioner.php` — seed permission investor.manage + map ke owner
- `hq/_layout_open.php` — sidebar link
- `.htaccess` — route /hq/investor (301 + internal rewrite)

---

## Task 1: Schema Migration + Permission Seed

**Files:**
- Create: `db/migrations/2026-06-24-investor-bagi-hasil.sql`
- Modify: `core/TenantProvisioner.php` (seedPermissions array + role map)

**Interfaces:**
- Produces: tabel `hl_investor`, `hl_bagi_hasil`; permission `investor.manage` di-seed untuk tenant baru. Untuk tenant EXISTING, migration backfill permission + map ke role owner.

- [ ] **Step 1: Create migration**

Write `db/migrations/2026-06-24-investor-bagi-hasil.sql`:

```sql
-- Investor & Bagi Hasil
CREATE TABLE IF NOT EXISTS hl_investor (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  nama VARCHAR(100) NOT NULL,
  telepon VARCHAR(20) NULL,
  catatan TEXT NULL,
  scope ENUM('tenant','outlet') NOT NULL DEFAULT 'tenant',
  outlet_id INT NULL,
  modal_disetor BIGINT NOT NULL DEFAULT 0,
  persentase DECIMAL(5,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  joined_at DATE NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tenant_scope (tenant_id, scope, outlet_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_bagi_hasil (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  investor_id INT NOT NULL,
  periode VARCHAR(7) NOT NULL,
  laba_basis BIGINT NOT NULL,
  persentase DECIMAL(5,2) NOT NULL,
  jumlah BIGINT NOT NULL,
  status ENUM('pending','dibayar') NOT NULL DEFAULT 'pending',
  kas_id INT NULL,
  jurnal_id INT NULL,
  dibayar_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_inv_periode (tenant_id, investor_id, periode),
  INDEX idx_periode (tenant_id, periode, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backfill permission investor.manage untuk tenant existing + map ke role owner
INSERT IGNORE INTO hl_permissions (tenant_id, permission_key, modul, aksi, deskripsi)
SELECT id, 'investor.manage', 'investor', 'manage', 'Kelola investor & bagi hasil'
FROM tenants;

INSERT IGNORE INTO hl_role_permissions (tenant_id, role_id, permission_key)
SELECT r.tenant_id, r.id, 'investor.manage'
FROM hl_roles r WHERE r.nama = 'owner';
```

NOTE: verifikasi nama tabel permission + kolom (hl_permissions / hl_role_permissions / hl_roles) saat apply — sesuaikan dgn schema actual (lihat seedPermissions di TenantProvisioner untuk nama persis). Kalau beda, sesuaikan INSERT.

- [ ] **Step 2: Verify schema + permission table names**

```bash
/opt/homebrew/opt/mysql-client/bin/mysql -e "SHOW TABLES LIKE 'hl_permission%'; SHOW TABLES LIKE 'hl_role%'; DESC hl_roles" 2>&1 | head -20
```
Catat nama tabel + kolom permission yang benar. Sesuaikan SQL backfill Step 1 kalau beda (mis. kolom `permission_key` vs `key`).

- [ ] **Step 3: Apply migration**

```bash
/opt/homebrew/opt/mysql-client/bin/mysql < db/migrations/2026-06-24-investor-bagi-hasil.sql
/opt/homebrew/opt/mysql-client/bin/mysql -e "DESC hl_investor; DESC hl_bagi_hasil" 2>&1 | head -30
```
Expected: 2 tabel dgn kolom sesuai.

- [ ] **Step 4: Seed permission di TenantProvisioner (tenant baru)**

Di `core/TenantProvisioner.php` seedPermissions(), tambah ke array `$permissions` (dekat `settings.roles` atau `laporan`):
```php
            ['investor.manage',      'investor',  'manage',        'Kelola investor & bagi hasil'],
```
Lalu di blok role-mapping (cari bagian yang assign permission ke role owner — owner biasanya dapat semua), pastikan `investor.manage` ter-include untuk owner. Baca kode mapping existing; kalau owner pakai "semua permission", otomatis ke-include. Kalau eksplisit per-key, tambahkan.

- [ ] **Step 5: Verify permission seeded (existing tenant)**

```bash
/opt/homebrew/opt/mysql-client/bin/mysql -e "
SELECT COUNT(*) FROM hl_permissions WHERE permission_key='investor.manage';
SELECT COUNT(*) FROM hl_role_permissions WHERE permission_key='investor.manage';" 2>&1
```
Expected: ≥ jumlah tenant (permission) + ≥ jumlah role owner (mapping). Kalau nama kolom beda, sesuaikan query.

- [ ] **Step 6: Commit**

```bash
git add db/migrations/2026-06-24-investor-bagi-hasil.sql core/TenantProvisioner.php
git commit -m "feat(investor): schema hl_investor + hl_bagi_hasil + permission

2 tabel: hl_investor (scope tenant/outlet, modal, %) + hl_bagi_hasil
(snapshot laba/% per periode, link kas+jurnal). Permission investor.manage
seed ke role owner (TenantProvisioner + backfill tenant existing)."
```

---

## Task 2: BagiHasilCalculator

**Files:**
- Create: `core/BagiHasilCalculator.php`

**Interfaces:**
- Consumes: `FinancialCalculator::labaRugi(int,?int,string)`, `Database::get()`, `TenantResolver`
- Produces:
  - `BagiHasilCalculator::hitung(int $tenantId, string $periode): array` → list `[{investor_id, nama, scope, outlet_id, persentase, laba_basis, jumlah, status}]`
  - `BagiHasilCalculator::distribusi(int $tenantId, int $investorId, string $periode, int $userId): array` → `{ok:bool, jumlah?:int, error?:string}`

- [ ] **Step 1: Create class skeleton + hitung()**

Write `core/BagiHasilCalculator.php`:

```php
<?php
// core/BagiHasilCalculator.php — Hitung & distribusi bagi hasil investor.
require_once __DIR__ . '/FinancialCalculator.php';

class BagiHasilCalculator
{
    // Hitung bagi hasil semua investor aktif untuk periode (read-only).
    public static function hitung(int $tenantId, string $periode): array
    {
        $db = Database::get();
        $st = $db->prepare("SELECT id, nama, scope, outlet_id, persentase
                            FROM hl_investor
                            WHERE tenant_id=? AND is_active=1
                            ORDER BY nama");
        $st->execute([$tenantId]);
        $investors = $st->fetchAll(PDO::FETCH_ASSOC);

        // Cache laba per scope-key supaya tidak hitung konsolidasi berkali-kali
        $labaCache = [];
        $rows = [];
        foreach ($investors as $inv) {
            $oid = $inv['scope'] === 'outlet' ? (int)$inv['outlet_id'] : null;
            $key = $oid === null ? 'tenant' : ('outlet_' . $oid);
            if (!array_key_exists($key, $labaCache)) {
                try {
                    $labaCache[$key] = (int) FinancialCalculator::labaRugi($tenantId, $oid, $periode)['laba_bersih'];
                } catch (Throwable) {
                    $labaCache[$key] = 0;
                }
            }
            $laba = $labaCache[$key];
            $persen = (float)$inv['persentase'];
            $jumlah = (int) round($laba * $persen / 100);

            // status periode ini
            $s2 = $db->prepare("SELECT status FROM hl_bagi_hasil
                                WHERE tenant_id=? AND investor_id=? AND periode=? LIMIT 1");
            $s2->execute([$tenantId, $inv['id'], $periode]);
            $status = $s2->fetchColumn() ?: 'pending';

            $rows[] = [
                'investor_id' => (int)$inv['id'],
                'nama'        => $inv['nama'],
                'scope'       => $inv['scope'],
                'outlet_id'   => $inv['outlet_id'] !== null ? (int)$inv['outlet_id'] : null,
                'persentase'  => $persen,
                'laba_basis'  => $laba,
                'jumlah'      => $jumlah,
                'status'      => $status,
            ];
        }
        return $rows;
    }
}
```

- [ ] **Step 2: Add distribusi() — transaksional**

Tambah method ke class:

```php
    // Distribusi 1 investor: UPSERT bagi_hasil + INSERT kas keluar + jurnal prive.
    // Tolak kalau laba_basis <= 0 atau sudah 'dibayar'.
    public static function distribusi(int $tenantId, int $investorId, string $periode, int $userId): array
    {
        $db = Database::get();

        // Load investor (tenant scope)
        $s = $db->prepare("SELECT id, nama, scope, outlet_id, persentase
                           FROM hl_investor WHERE id=? AND tenant_id=? AND is_active=1 LIMIT 1");
        $s->execute([$investorId, $tenantId]);
        $inv = $s->fetch(PDO::FETCH_ASSOC);
        if (!$inv) return ['ok'=>false, 'error'=>'Investor tidak ditemukan'];

        // Sudah dibayar?
        $c = $db->prepare("SELECT status FROM hl_bagi_hasil WHERE tenant_id=? AND investor_id=? AND periode=? LIMIT 1");
        $c->execute([$tenantId, $investorId, $periode]);
        if ($c->fetchColumn() === 'dibayar') return ['ok'=>false, 'error'=>'Sudah didistribusi periode ini'];

        // Laba basis
        $oid = $inv['scope'] === 'outlet' ? (int)$inv['outlet_id'] : null;
        $laba = (int) FinancialCalculator::labaRugi($tenantId, $oid, $periode)['laba_bersih'];
        if ($laba <= 0) return ['ok'=>false, 'error'=>'Laba periode ≤ 0, tidak bisa distribusi'];

        $persen = (float)$inv['persentase'];
        $jumlah = (int) round($laba * $persen / 100);
        if ($jumlah <= 0) return ['ok'=>false, 'error'=>'Jumlah bagi hasil 0'];

        // COA id prive (3-1003) + outlet untuk kas/jurnal
        $kasOutlet = $oid ?: (int) TenantResolver::outletId();
        $coaPrive = self::coaIdByKode($db, $tenantId, '3-1003');
        $tgl = date('Y-m-d');

        $db->beginTransaction();
        try {
            // 1. UPSERT hl_bagi_hasil (snapshot)
            $db->prepare("INSERT INTO hl_bagi_hasil
                (tenant_id, investor_id, periode, laba_basis, persentase, jumlah, status, dibayar_at)
                VALUES (?,?,?,?,?,?, 'dibayar', NOW())
                ON DUPLICATE KEY UPDATE
                  laba_basis=VALUES(laba_basis), persentase=VALUES(persentase),
                  jumlah=VALUES(jumlah), status='dibayar', dibayar_at=NOW()")
               ->execute([$tenantId, $investorId, $periode, $laba, $persen, $jumlah]);
            $bagiHasilId = (int)$db->lastInsertId();
            if (!$bagiHasilId) {
                $g = $db->prepare("SELECT id FROM hl_bagi_hasil WHERE tenant_id=? AND investor_id=? AND periode=?");
                $g->execute([$tenantId, $investorId, $periode]);
                $bagiHasilId = (int)$g->fetchColumn();
            }

            // 2. INSERT kas keluar
            $ket = "Bagi hasil investor: {$inv['nama']} periode {$periode}";
            $db->prepare("INSERT INTO hl_kas
                (tenant_id, outlet_id, tanggal, tipe, kategori, keterangan, jumlah, created_by)
                VALUES (?,?,?, 'keluar', 'bagi_hasil', ?, ?, ?)")
               ->execute([$tenantId, $kasOutlet, $tgl, $ket, $jumlah, $userId]);
            $kasId = (int)$db->lastInsertId();

            // 3. INSERT jurnal prive
            $jurnalId = null;
            if ($coaPrive) {
                $db->prepare("INSERT INTO hl_jurnal_manual
                    (tenant_id, outlet_id, coa_id, tanggal, periode, keterangan, tipe, jumlah, arah, input_by)
                    VALUES (?,?,?,?,?,?, 'prive', ?, 'debit', ?)")
                   ->execute([$tenantId, $kasOutlet, $coaPrive, $tgl, $periode, $ket, $jumlah, $userId]);
                $jurnalId = (int)$db->lastInsertId();
            }

            // 4. Link
            $db->prepare("UPDATE hl_bagi_hasil SET kas_id=?, jurnal_id=? WHERE id=?")
               ->execute([$kasId, $jurnalId, $bagiHasilId]);

            $db->commit();
            return ['ok'=>true, 'jumlah'=>$jumlah];
        } catch (Throwable $e) {
            $db->rollBack();
            return ['ok'=>false, 'error'=>$e->getMessage()];
        }
    }

    // Helper: cari coa_id by kode (untuk prive/modal)
    private static function coaIdByKode(PDO $db, int $tenantId, string $kode): ?int
    {
        $s = $db->prepare("SELECT id FROM hl_coa WHERE tenant_id=? AND kode=? LIMIT 1");
        $s->execute([$tenantId, $kode]);
        $id = $s->fetchColumn();
        return $id ? (int)$id : null;
    }
```

NOTE: verifikasi kolom hl_jurnal_manual saat implementasi — brief schema: (tenant_id, outlet_id, coa_id, tanggal, periode, keterangan, tipe, jumlah, arah, input_by). Sesuaikan kalau urutan/nama beda (cek INSERT existing di keuangan.php action add_jurnal).

- [ ] **Step 3: Verify class via grep**

```bash
grep -nE "function hitung|function distribusi|function coaIdByKode" core/BagiHasilCalculator.php
```
Expected: 3 fungsi.

- [ ] **Step 4: Commit**

```bash
git add core/BagiHasilCalculator.php
git commit -m "feat(investor): BagiHasilCalculator hitung + distribusi

hitung(): laba bersih scope × % per investor aktif, cache laba per scope,
join status periode. distribusi(): transaksional UPSERT bagi_hasil +
kas keluar 'bagi_hasil' + jurnal prive (3-1003). Tolak laba≤0 / sudah dibayar."
```

---

## Task 3: investor.php — Tab Daftar Investor (CRUD)

**Files:**
- Create: `hq/investor.php`

**Interfaces:**
- Consumes: hq_guard, requirePermission, FinancialCalculator (coa modal), BagiHasilCalculator (Task 4 pakai)
- Produces: page `/hq/investor` + AJAX `list_investor`, `save_investor`, `delete_investor`

- [ ] **Step 1: Page skeleton + guard + layout**

Write `hq/investor.php`:

```php
<?php
// hq/investor.php — Investor & Bagi Hasil
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';
require_once ROOT . '/core/FinancialCalculator.php';
require_once ROOT . '/core/BagiHasilCalculator.php';

requirePermission('investor.manage');

$db   = Database::get();
$tid  = (int) TenantResolver::id();
$user = currentUser();
$uid  = (int) ($user['id'] ?? 0);
$csrf = getCsrfToken();

// ── AJAX Handler ──
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    verifyCsrf();
    try {
        // (actions ditambah di step berikut)
        echo json_encode(['ok'=>false, 'error'=>'Unknown action']);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
    }
    exit;
}
$activePage = 'investor';
?>
```

(Layout HTML render di Step 5. Verifikasi `currentUser()`, `getCsrfToken()`, `verifyCsrf()`, `requirePermission()` tersedia via hq_guard — konsisten hq/keuangan.php.)

- [ ] **Step 2: list_investor action**

Di blok try AJAX (ganti placeholder Unknown action dgn chain):
```php
        if ($action === 'list_investor') {
            $st = $db->prepare("SELECT i.*, o.nama_outlet
                                FROM hl_investor i
                                LEFT JOIN outlets o ON o.id=i.outlet_id AND o.tenant_id=i.tenant_id
                                WHERE i.tenant_id=? AND i.is_active=1
                                ORDER BY i.scope, i.nama");
            $st->execute([$tid]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            // total % per pool
            $pools = [];
            foreach ($rows as $r) {
                $key = $r['scope']==='outlet' ? ('outlet_'.$r['outlet_id']) : 'tenant';
                $pools[$key] = ($pools[$key] ?? 0) + (float)$r['persentase'];
            }
            echo json_encode(['ok'=>true, 'data'=>$rows, 'pools'=>$pools]);
            exit;
        }
```

- [ ] **Step 3: save_investor action (insert/update + jurnal modal delta)**

```php
        if ($action === 'save_investor') {
            $id      = (int)($_POST['id'] ?? 0);
            $nama    = substr(trim(strip_tags($_POST['nama'] ?? '')), 0, 100);
            $telepon = substr(preg_replace('/[^0-9+\-\s]/','', $_POST['telepon'] ?? ''), 0, 20);
            $scope   = in_array($_POST['scope'] ?? '', ['tenant','outlet'], true) ? $_POST['scope'] : 'tenant';
            $outletId= $scope==='outlet' ? (int)($_POST['outlet_id'] ?? 0) : null;
            $modal   = max(0, (int)($_POST['modal_disetor'] ?? 0));
            $persen  = max(0, min(100, (float)($_POST['persentase'] ?? 0)));
            $joined  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['joined_at'] ?? '') ? $_POST['joined_at'] : date('Y-m-d');
            $catatan = substr(trim(strip_tags($_POST['catatan'] ?? '')), 0, 500);
            if ($nama==='') throw new RuntimeException('Nama wajib diisi');
            if ($scope==='outlet' && !$outletId) throw new RuntimeException('Pilih outlet');

            // modal lama (untuk delta jurnal)
            $modalLama = 0;
            if ($id) {
                $g = $db->prepare("SELECT modal_disetor FROM hl_investor WHERE id=? AND tenant_id=?");
                $g->execute([$id, $tid]); $modalLama = (int)$g->fetchColumn();
            }

            $db->beginTransaction();
            try {
                if ($id) {
                    $db->prepare("UPDATE hl_investor SET nama=?, telepon=?, scope=?, outlet_id=?,
                                  modal_disetor=?, persentase=?, joined_at=?, catatan=?
                                  WHERE id=? AND tenant_id=?")
                       ->execute([$nama,$telepon,$scope,$outletId,$modal,$persen,$joined,$catatan,$id,$tid]);
                } else {
                    $db->prepare("INSERT INTO hl_investor
                                  (tenant_id, nama, telepon, scope, outlet_id, modal_disetor, persentase, joined_at, catatan)
                                  VALUES (?,?,?,?,?,?,?,?,?)")
                       ->execute([$tid,$nama,$telepon,$scope,$outletId,$modal,$persen,$joined,$catatan]);
                    $id = (int)$db->lastInsertId();
                }
                // jurnal modal delta (kredit modal_disetor)
                $delta = $modal - $modalLama;
                if ($delta !== 0) {
                    $coaModal = $db->prepare("SELECT id FROM hl_coa WHERE tenant_id=? AND kode='3-1001' LIMIT 1");
                    $coaModal->execute([$tid]); $coaId = (int)$coaModal->fetchColumn();
                    if ($coaId) {
                        $arah = $delta > 0 ? 'kredit' : 'debit';
                        $db->prepare("INSERT INTO hl_jurnal_manual
                            (tenant_id, outlet_id, coa_id, tanggal, periode, keterangan, tipe, jumlah, arah, input_by)
                            VALUES (?,?,?,?,?,?, 'modal_disetor', ?, ?, ?)")
                           ->execute([$tid, $outletId ?: (int)TenantResolver::outletId(), $coaId,
                                      $joined, substr($joined,0,7),
                                      "Setoran modal investor: $nama", abs($delta), $arah, $uid]);
                    }
                }
                $db->commit();
                echo json_encode(['ok'=>true, 'id'=>$id]); exit;
            } catch (Throwable $e) { $db->rollBack(); throw $e; }
        }
```

- [ ] **Step 4: delete_investor (soft)**

```php
        if ($action === 'delete_investor') {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare("UPDATE hl_investor SET is_active=0 WHERE id=? AND tenant_id=?")->execute([$id,$tid]);
            echo json_encode(['ok'=>true]); exit;
        }
```

- [ ] **Step 5: HTML render (tab shell + Daftar Investor + modal)**

Tambah setelah blok AJAX (sebelum `?>` penutup PHP, lalu HTML). Pakai layout HQ existing. Render: tab nav (Daftar Investor | Bagi Hasil), tabel investor (loaded via JS), tombol + Tambah, modal form (nama/telepon/scope radio→outlet dropdown/modal/%/joined/catatan), + JS loadInvestor()/saveInvestor()/deleteInvestor(). Outlet dropdown di-populate dari list outlet tenant (query inline PHP atau AJAX).

Gunakan pola layout + helper JS (escapeHtml, fmtRp, CSRF meta) yang sama dengan hq/keuangan.php. Sertakan `<div id="tabBagiHasil">` kosong (diisi Task 4).

NOTE: implementer baca hq/keuangan.php atau hq/investor sibling untuk pola layout HTML + cara include header/footer (renderHead/renderTopbar atau _layout_open). Ikuti yang dipakai HQ pages.

- [ ] **Step 6: Smoke test (curl + grep)**

```bash
grep -nE "list_investor|save_investor|delete_investor" hq/investor.php
curl -s -o /dev/null -w "%{http_code}\n" "https://lamasy.harpy.id/hq/investor"
```
Expected: 3 action ada; curl 302/404 (belum route — Task 5). Browser test full di Task 6.

- [ ] **Step 7: Commit**

```bash
git add hq/investor.php
git commit -m "feat(investor): page + tab Daftar Investor CRUD

hq/investor.php: guard investor.manage + AJAX list/save/delete_investor.
Save investor → jurnal modal_disetor delta (ekuitas naik). Soft delete.
Total % per scope-pool untuk warning. Tab Bagi Hasil placeholder."
```

---

## Task 4: investor.php — Tab Bagi Hasil + Distribusi

**Files:**
- Modify: `hq/investor.php`

**Interfaces:**
- Consumes: `BagiHasilCalculator::hitung()`, `distribusi()` (Task 2)
- Produces: AJAX `bagi_hasil_list`, `distribusi`, `distribusi_semua` + tab UI

- [ ] **Step 1: bagi_hasil_list action**

Tambah ke AJAX chain:
```php
        if ($action === 'bagi_hasil_list') {
            $periode = preg_replace('/[^0-9\-]/', '', $_GET['periode'] ?? date('Y-m'));
            echo json_encode(['ok'=>true, 'data'=>BagiHasilCalculator::hitung($tid, $periode), 'periode'=>$periode]);
            exit;
        }
```

- [ ] **Step 2: distribusi + distribusi_semua action**

```php
        if ($action === 'distribusi') {
            $invId   = (int)($_POST['investor_id'] ?? 0);
            $periode = preg_replace('/[^0-9\-]/', '', $_POST['periode'] ?? date('Y-m'));
            $r = BagiHasilCalculator::distribusi($tid, $invId, $periode, $uid);
            echo json_encode($r); exit;
        }
        if ($action === 'distribusi_semua') {
            $periode = preg_replace('/[^0-9\-]/', '', $_POST['periode'] ?? date('Y-m'));
            $list = BagiHasilCalculator::hitung($tid, $periode);
            $done = 0; $skip = 0;
            foreach ($list as $row) {
                if ($row['status']==='dibayar' || $row['jumlah'] <= 0) { $skip++; continue; }
                $r = BagiHasilCalculator::distribusi($tid, $row['investor_id'], $periode, $uid);
                if ($r['ok']) $done++; else $skip++;
            }
            echo json_encode(['ok'=>true, 'done'=>$done, 'skip'=>$skip]); exit;
        }
```

- [ ] **Step 3: Tab Bagi Hasil HTML + JS**

Isi `<div id="tabBagiHasil">`: periode picker, tombol "Distribusi Semua", tabel (investor/scope/laba basis/%/jumlah/status+aksi). JS `loadBagiHasil()` fetch bagi_hasil_list → render; tombol "Bayar" per row → POST distribusi → reload; periode rugi (jumlah≤0 atau laba_basis≤0) → tombol disabled. "Distribusi Semua" → POST distribusi_semua → toast {done} dibayar, {skip} dilewati.

Render escapeHtml semua string, fmtRp numerik, badge status (✓ Dibayar hijau / pending).

- [ ] **Step 4: Smoke verify**

```bash
grep -nE "bagi_hasil_list|distribusi_semua|function loadBagiHasil" hq/investor.php
```
Expected: actions + JS func ada.

- [ ] **Step 5: Commit**

```bash
git add hq/investor.php
git commit -m "feat(investor): tab Bagi Hasil + distribusi

AJAX bagi_hasil_list (BagiHasilCalculator::hitung), distribusi (1 investor),
distribusi_semua (loop pending laba>0). Tab UI: periode picker, tabel bagi
hasil, tombol Bayar/Distribusi Semua, periode rugi disabled."
```

---

## Task 5: Wiring — Sidebar + Route

**Files:**
- Modify: `hq/_layout_open.php` (sidebar), `.htaccess` (route)

**Interfaces:**
- Consumes: page hq/investor.php (Task 3-4)
- Produces: `/hq/investor` accessible + sidebar link

- [ ] **Step 1: Sidebar link**

Di `hq/_layout_open.php`, di group Keuangan (dekat link keuangan/billing), tambah:
```php
      <a href="/hq/investor"
         class="hq-side-link <?= $_aPage === 'investor' ? 'active' : '' ?>">
        <span class="ico">👥</span> Investor
      </a>
```
Verifikasi var activePage yang dipakai (`$_aPage` atau `$activePage`) — samakan dgn link existing di file.

- [ ] **Step 2: .htaccess route**

Tambah `investor` ke 2 RewriteRule HQ (canonical 301 + internal rewrite). Di baris `^hq/(dashboard|outlet|...|support)\.php$` dan `^hq/(...)$ hq/$1.php` — tambah `|investor`.

```bash
grep -n "hq/(dashboard|outlet" .htaccess
```
Edit kedua baris: sisip `|investor` ke dalam group.

- [ ] **Step 3: Verify route**

```bash
grep -n "investor" .htaccess
```
Expected: 2 hits (canonical + rewrite).

- [ ] **Step 4: Commit**

```bash
git add hq/_layout_open.php .htaccess
git commit -m "feat(investor): sidebar link + .htaccess route /hq/investor

Sidebar group Keuangan → 👥 Investor. Route clean URL /hq/investor
(canonical 301 + internal rewrite ke hq/investor.php)."
```

---

## Task 6: E2E + Production Deploy

**Files:** None (verification)

- [ ] **Step 1: Push + deploy**
```bash
git push origin main
```
Wait ~20s.

- [ ] **Step 2: Apply migration ke production**
```bash
/opt/homebrew/opt/mysql-client/bin/mysql < db/migrations/2026-06-24-investor-bagi-hasil.sql
/opt/homebrew/opt/mysql-client/bin/mysql -e "DESC hl_investor; SELECT COUNT(*) FROM hl_permissions WHERE permission_key='investor.manage'" 2>&1
```

- [ ] **Step 3: HTTP smoke**
```bash
curl -s -o /dev/null -w "GET /hq/investor %{http_code}\n" "https://lamasy.harpy.id/hq/investor"
```
Expected: 302 (auth gate).

- [ ] **Step 4: Browser E2E (login HQ owner)**

| # | Action | Expected |
|---|--------|----------|
| 1 | Sidebar → 👥 Investor | Page render, tab Daftar Investor |
| 2 | Tambah investor scope=tenant modal 50jt 40% | Tersimpan; cek Neraca ekuitas Modal Disetor +50jt |
| 3 | Tambah investor scope=outlet 30jt 50% | Tersimpan; warning % per pool |
| 4 | Tab Bagi Hasil periode berjalan | Laba basis tenant (konsolidasi) + outlet benar, jumlah = laba×% |
| 5 | Klik Bayar investor tenant (laba>0) | Kas keluar 'bagi_hasil' + jurnal prive + badge Dibayar |
| 6 | Cross-check keuangan | Neraca prive↑, Arus Kas keluar↑, Buku Besar 3-1003 ada entry, Perubahan Modal prive↑ |
| 7 | Distribusi 2× sama | Ditolak "sudah didistribusi" |
| 8 | Periode rugi | Tombol bayar disabled |

- [ ] **Step 5: DB cross-check**
```bash
/opt/homebrew/opt/mysql-client/bin/mysql -e "
SELECT b.investor_id, i.nama, b.periode, b.laba_basis, b.persentase, b.jumlah, b.status, b.kas_id, b.jurnal_id
FROM hl_bagi_hasil b JOIN hl_investor i ON i.id=b.investor_id
ORDER BY b.id DESC LIMIT 5;" 2>&1
```
Expected: row dibayar dgn kas_id + jurnal_id ter-link.

- [ ] **Step 6: Update progress ledger**
```bash
cat >> .superpowers/sdd/progress.md <<'EOF'

Investor & Bagi Hasil COMPLETE 2026-06-24 WIB.
Final state: <base>..<head>
EOF
```

---

## Self-Review Checklist

### Spec Coverage
- ✅ §3.2 schema → Task 1
- ✅ permission investor.manage → Task 1 (provisioner + backfill)
- ✅ §3.3 scope laba basis → Task 2 hitung()
- ✅ §4.1 CRUD + jurnal modal → Task 3
- ✅ §4.2 distribusi kas+prive → Task 2 distribusi() + Task 4
- ✅ §4.3 integrasi keuangan → Task 2 (jurnal/kas), Task 6 cross-check
- ✅ §5 UI 2 tab → Task 3 (daftar) + Task 4 (bagi hasil)
- ✅ §6 backend logic → Task 2-4
- ✅ §8 edge cases → Task 2 (rugi/sudah dibayar), Task 3 (%, soft delete)
- ✅ §9 testing → Task 6

### Placeholder Scan
✓ No TBD/TODO. Code lengkap tiap step (kecuali HTML render Task 3/4 Step yang mengarahkan ikut pola existing — acceptable karena layout HQ bervariasi, implementer baca sibling).

### Type/Name Consistency
- ✅ `BagiHasilCalculator::hitung(int,string):array` + `distribusi(int,int,string,int):array` — Task 2 def, Task 4 use
- ✅ hitung() return keys {investor_id,nama,scope,outlet_id,persentase,laba_basis,jumlah,status} — Task 2 → Task 4 JS render
- ✅ Action names: list_investor/save_investor/delete_investor (Task 3), bagi_hasil_list/distribusi/distribusi_semua (Task 4)
- ✅ COA kode 3-1001 modal / 3-1003 prive (seed verified earlier)
- ✅ hl_kas kolom (tipe/kategori/keterangan/jumlah/outlet_id/created_by) verified; hl_jurnal_manual kolom flagged untuk verify Task 2 Step 2

### Notes (risiko di-flag)
- Task 1 Step 1-2: nama tabel permission (hl_permissions/hl_role_permissions/hl_roles + kolom) ASUMSI — implementer WAJIB verify via grep seedPermissions + DESC, sesuaikan SQL backfill.
- Task 2 Step 2: urutan/nama kolom hl_jurnal_manual INSERT — verify dgn INSERT existing di keuangan.php add_jurnal.
- Task 3 Step 5 + Task 4 Step 3: HTML layout pattern (renderHead vs _layout_open) — implementer ikut pola HQ page existing (hq/keuangan.php / hq sibling).
- Permission role-map: owner mungkin auto-dapat semua permission; verify mekanisme di seedPermissions sebelum assume.

# Alamat Outlet Bertingkat (Wilayah Indonesia) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ganti input alamat outlet di `add-outlet.php` jadi dropdown wilayah bertingkat (Provinsi→Kota→Kecamatan→Kelurahan) dengan Kode Pos auto-terisi, di-back oleh tabel referensi lokal `ref_wilayah`.

**Architecture:** Tabel referensi global `ref_wilayah` (di-seed sekali dari dataset Permendagri cahyadsn) menyimpan hierarki wilayah + kode pos. Endpoint `api/wilayah.php` melayani cascade per-parent. Form `add-outlet.php` step 1 pakai 4 `<select>` yang saling memuat via fetch; server memvalidasi kode & resolve nama dari `ref_wilayah` saat simpan. Outlet menyimpan nama tiap level + kode kelurahan.

**Tech Stack:** PHP (PDO/MySQL), vanilla JS fetch, MySQL. Tanpa framework test — verifikasi = `php -l`, query DB, curl endpoint, manual E2E.

## Global Constraints
- Scope: **hanya `add-outlet.php`**. Registration & edit-outlet out of scope.
- `ref_wilayah` = referensi global (TIDAK ter-tenant-scope).
- Kolom baru `outlets` semua **nullable** → outlet lama tak terpengaruh.
- Kode wilayah divalidasi server-side; **nama di-resolve dari DB**, tidak dipercaya dari klien.
- Kode pos auto-fill dari kelurahan tapi **tetap editable**; kelurahan tanpa kodepos → kosong, isi manual.
- Level: 1=provinsi, 2=kota/kab, 3=kecamatan, 4=kelurahan. Format kode: `32`, `32.01`, `32.01.01`, `32.01.01.2001`.
- Deploy: commit → `git push origin main` (auto-deploy). Migrasi DB dijalankan via mysql client `/opt/homebrew/opt/mysql-client/bin/mysql` (kredensial di `~/.my.cnf` → PROD).
- Endpoint clean-URL harus didaftarkan di `.htaccess` (pola sama seperti rute lain).

---

### Task 1: Tabel `ref_wilayah` + seed + kolom baru `outlets`

**Files:**
- Create: `migrations/2026-07-01-ref-wilayah.sql`
- DB: PROD via mysql client

**Interfaces:**
- Produces: tabel `ref_wilayah(kode PK, nama, level TINYINT, parent_kode, kodepos)`; kolom `outlets.provinsi, outlets.kecamatan, outlets.kelurahan, outlets.wilayah_kode`.

- [ ] **Step 1: Tulis migration SQL**

Create `migrations/2026-07-01-ref-wilayah.sql`:
```sql
-- Tabel referensi wilayah (global, di-seed dari dataset Permendagri cahyadsn)
CREATE TABLE IF NOT EXISTS ref_wilayah (
  kode        VARCHAR(13)  NOT NULL PRIMARY KEY,
  nama        VARCHAR(120) NOT NULL,
  level       TINYINT      NOT NULL,     -- 1=prov,2=kota,3=kec,4=kel
  parent_kode VARCHAR(13)  NULL,
  kodepos     VARCHAR(5)   NULL,
  KEY idx_parent (parent_kode),
  KEY idx_level_parent (level, parent_kode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Kolom alamat terstruktur di outlets (nullable, backward-compatible)
-- Jalankan hanya jika belum ada (lihat Step 3 untuk guard).
ALTER TABLE outlets
  ADD COLUMN provinsi     VARCHAR(100) NULL AFTER kota,
  ADD COLUMN kecamatan    VARCHAR(100) NULL AFTER provinsi,
  ADD COLUMN kelurahan    VARCHAR(100) NULL AFTER kecamatan,
  ADD COLUMN wilayah_kode VARCHAR(13)  NULL AFTER kelurahan;
```

- [ ] **Step 2: Buat tabel & impor dataset staging ke PROD**

```bash
MYSQL=/opt/homebrew/opt/mysql-client/bin/mysql
cd /tmp
curl -s -o wilayah.sql "https://raw.githubusercontent.com/cahyadsn/wilayah/master/db/wilayah.sql"
curl -s -o wilayah_kodepos.sql "https://raw.githubusercontent.com/cahyadsn/wilayah_kodepos/master/db/wilayah_kodepos.sql"
# buat ref_wilayah
$MYSQL < /Users/rizky/Documents/lamasy/migrations/2026-07-01-ref-wilayah.sql   # (hanya bagian CREATE TABLE — lihat Step 3 utk ALTER)
# impor staging (kedua file membuat tabel `wilayah` dan `wilayah_kodepos`)
$MYSQL < wilayah.sql
$MYSQL < wilayah_kodepos.sql
```
Expected: tak ada error. `SELECT COUNT(*) FROM wilayah;` → ~91.000.

> Catatan eksekusi: file `.sql` migration di atas berisi CREATE TABLE **dan** ALTER. Jalankan CREATE TABLE dulu; ALTER dijalankan terpisah di Step 3 (dengan guard kolom). Pisahkan secara manual saat menjalankan, atau jalankan seluruh file lalu tangani error "duplicate column" bila kolom sudah ada.

- [ ] **Step 3: ALTER outlets (guard kolom sudah ada)**

Cek dulu; hanya tambah kolom yang belum ada:
```bash
MYSQL=/opt/homebrew/opt/mysql-client/bin/mysql
$MYSQL -N -e "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='outlets' AND COLUMN_NAME IN ('provinsi','kecamatan','kelurahan','wilayah_kode');"
```
Jika kosong, jalankan ALTER (dari migration file). Jika sebagian ada, tambahkan hanya yang kurang.

- [ ] **Step 4: Bangun ref_wilayah dari staging**

```bash
MYSQL=/opt/homebrew/opt/mysql-client/bin/mysql
$MYSQL <<'SQL'
TRUNCATE ref_wilayah;
INSERT INTO ref_wilayah (kode, nama, level, parent_kode)
SELECT w.kode, w.nama,
  (LENGTH(w.kode) - LENGTH(REPLACE(w.kode,'.','')) + 1) AS level,
  CASE WHEN LOCATE('.', w.kode)=0 THEN NULL
       ELSE LEFT(w.kode, LENGTH(w.kode) - LOCATE('.', REVERSE(w.kode))) END AS parent_kode
FROM wilayah w;

UPDATE ref_wilayah r
JOIN wilayah_kodepos k ON k.kode = r.kode
SET r.kodepos = LEFT(k.kodepos,5)
WHERE r.level = 4;

DROP TABLE wilayah;
DROP TABLE wilayah_kodepos;
SQL
```

- [ ] **Step 5: Verifikasi seed**

```bash
MYSQL=/opt/homebrew/opt/mysql-client/bin/mysql
$MYSQL -e "SELECT level, COUNT(*) c FROM ref_wilayah GROUP BY level ORDER BY level;"
$MYSQL -e "SELECT * FROM ref_wilayah WHERE level=1 AND nama LIKE '%JAWA BARAT%';"
$MYSQL -e "SELECT kode,nama,kodepos FROM ref_wilayah WHERE level=4 AND kodepos IS NOT NULL LIMIT 3;"
```
Expected: level 1 ≈ 34–38, level 2 ≈ 500+, level 3 ≈ 7.000+, level 4 ≈ 83.000+; ada baris kelurahan dengan kodepos terisi.

- [ ] **Step 6: Commit**

```bash
cd /Users/rizky/Documents/lamasy
git add migrations/2026-07-01-ref-wilayah.sql
git commit -m "feat(wilayah): tabel ref_wilayah + seed Permendagri + kolom alamat terstruktur di outlets"
```

---

### Task 2: Endpoint cascade `api/wilayah.php`

**Files:**
- Create: `api/wilayah.php`
- Modify: `.htaccess` (daftar rute clean-URL)

**Interfaces:**
- Consumes: tabel `ref_wilayah` (Task 1).
- Produces: `GET /api/wilayah.php?parent=<kode>` → `{ok:true, data:[{kode,nama,kodepos}]}`. `parent` kosong → daftar provinsi (level 1). Dipakai JS di Task 3.

- [ ] **Step 1: Tulis endpoint**

Create `api/wilayah.php` (bootstrap mengikuti `api/splash_seen.php`):
```php
<?php
// api/wilayah.php — cascade wilayah (Provinsi→Kota→Kecamatan→Kelurahan)
// GET ?parent=<kode> → anak langsung. parent kosong → daftar provinsi.
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/tenant_guard.php';   // sediakan Database + sesi login

header('Content-Type: application/json');

try {
    $db = Database::get();
    $parent = trim($_GET['parent'] ?? '');
    if ($parent === '') {
        $st = $db->prepare("SELECT kode, nama, kodepos FROM ref_wilayah WHERE level=1 ORDER BY nama");
        $st->execute();
    } else {
        if (!preg_match('/^[0-9.]{2,13}$/', $parent)) {
            echo json_encode(['ok' => true, 'data' => []]); exit;
        }
        $st = $db->prepare("SELECT kode, nama, kodepos FROM ref_wilayah WHERE parent_kode=? ORDER BY nama");
        $st->execute([$parent]);
    }
    echo json_encode(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Gagal memuat wilayah']);
}
```

- [ ] **Step 2: Lint**

Run: `php -l api/wilayah.php`
Expected: `No syntax errors detected in api/wilayah.php`

- [ ] **Step 3: Daftarkan rute clean-URL di `.htaccess`**

`api/wilayah.php` bisa diakses langsung (ada ekstensi), tapi untuk konsistensi pemanggilan `/api/wilayah` tanpa `.php`, tambahkan ke blok internal rewrite. Cari baris (blok "App pages", ~line 41):
```
RewriteRule ^(dashboard|pos|...|pembelian|billing-checkout|billing-success)$ $1.php [L,QSA]
```
Tambahkan aturan baru **di bawahnya**:
```
RewriteCond %{REQUEST_METHOD} GET
RewriteRule ^api/wilayah$ api/wilayah.php [L,QSA]
```
(JS di Task 3 memanggil `/api/wilayah.php` langsung — aturan ini opsional-aman; tetap ditambahkan agar `/api/wilayah` juga jalan.)

- [ ] **Step 4: Verifikasi via curl (butuh sesi login — uji manual di browser APK/desktop)**

Setelah deploy, di browser yang sudah login buka:
`/api/wilayah.php` → JSON daftar provinsi.
`/api/wilayah.php?parent=32` → daftar kota/kabupaten di Jawa Barat.
`/api/wilayah.php?parent=32.04` → kecamatan; `?parent=32.04.01` → kelurahan dengan `kodepos`.
Expected: `{"ok":true,"data":[...]}` tak kosong di tiap level.

- [ ] **Step 5: Commit**

```bash
git add api/wilayah.php .htaccess
git commit -m "feat(wilayah): endpoint api/wilayah.php cascade per-parent + rute .htaccess"
```

---

### Task 3: Form cascading di `add-outlet.php` step 1

**Files:**
- Modify: `add-outlet.php` (field alamat step 1, ~line 588-608; tambah blok `<script>` sebelum penutup form/step-1)

**Interfaces:**
- Consumes: `GET /api/wilayah.php?parent=` (Task 2).
- Produces: field form dengan `name`: `w_prov, w_kota, w_kec, w_kel` (berisi **kode**), `kode_pos`, `alamat`. Hidden field nama tiap level TIDAK dikirim (server resolve dari kode di Task 4).

- [ ] **Step 1: Ganti field alamat step 1**

Di `add-outlet.php`, ganti blok field "Alamat Lengkap" + "Kota / Kabupaten" + "Kode Pos" (baris ~588-607) menjadi:
```php
          <div class="field">
            <label>Provinsi <span class="req">*</span></label>
            <select name="w_prov" id="w_prov" required data-cur="<?= htmlspecialchars($d['w_prov'] ?? '') ?>">
              <option value="">⏳ memuat…</option>
            </select>
          </div>
          <div class="field">
            <label>Kota / Kabupaten <span class="req">*</span></label>
            <select name="w_kota" id="w_kota" required disabled data-cur="<?= htmlspecialchars($d['w_kota'] ?? '') ?>">
              <option value="">Pilih provinsi dulu</option>
            </select>
          </div>
          <div class="field">
            <label>Kecamatan <span class="req">*</span></label>
            <select name="w_kec" id="w_kec" required disabled data-cur="<?= htmlspecialchars($d['w_kec'] ?? '') ?>">
              <option value="">Pilih kota dulu</option>
            </select>
          </div>
          <div class="field">
            <label>Kelurahan / Desa <span class="req">*</span></label>
            <select name="w_kel" id="w_kel" required disabled data-cur="<?= htmlspecialchars($d['w_kel'] ?? '') ?>">
              <option value="">Pilih kecamatan dulu</option>
            </select>
          </div>
          <div class="field">
            <label>Alamat jalan (No, RT/RW) <span class="req">*</span></label>
            <textarea name="alamat" rows="2" maxlength="300" required
                      placeholder="cth: Jl. Merdeka No. 5 RT 01 RW 02"><?= htmlspecialchars($d['alamat'] ?? '') ?></textarea>
          </div>
          <div class="field">
            <label>Kode Pos <span class="req">*</span></label>
            <input type="text" name="kode_pos" id="kode_pos" required maxlength="5"
                   inputmode="numeric" pattern="\d{5}"
                   value="<?= htmlspecialchars($d['kode_pos'] ?? '') ?>"
                   placeholder="terisi otomatis dari kelurahan">
            <div class="hint">Terisi otomatis saat pilih kelurahan — bisa diedit bila perlu.</div>
          </div>
```

- [ ] **Step 2: Tambah JS cascade**

Tambahkan blok ini tepat sebelum `<?php endif; // step 1 ?>` (atau sebelum penutup form step 1). Gunakan `data-cur` untuk restore pilihan saat kembali dari step 2:
```html
<script>
(function(){
  const prov = document.getElementById('w_prov');
  const kota = document.getElementById('w_kota');
  const kec  = document.getElementById('w_kec');
  const kel  = document.getElementById('w_kel');
  const pos  = document.getElementById('kode_pos');
  if (!prov) return;

  async function loadInto(sel, parent, placeholder){
    sel.innerHTML = '<option value="">⏳ memuat…</option>';
    sel.disabled = true;
    try {
      const url = '/api/wilayah.php' + (parent ? ('?parent=' + encodeURIComponent(parent)) : '');
      const r = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const j = await r.json();
      const rows = (j && j.ok && j.data) ? j.data : [];
      let html = '<option value="">' + placeholder + '</option>';
      rows.forEach(o => {
        html += '<option value="' + o.kode + '"' + (o.kodepos ? ' data-pos="' + o.kodepos + '"' : '') + '>'
              + o.nama.replace(/</g,'&lt;') + '</option>';
      });
      sel.innerHTML = html;
      sel.disabled = false;
      return rows.length;
    } catch(e){
      sel.innerHTML = '<option value="">gagal memuat, pilih lagi</option>';
      sel.disabled = false;
      return 0;
    }
  }

  function resetBelow(){ 
    kota.innerHTML = '<option value="">Pilih provinsi dulu</option>'; kota.disabled = true;
    kec.innerHTML  = '<option value="">Pilih kota dulu</option>';      kec.disabled  = true;
    kel.innerHTML  = '<option value="">Pilih kecamatan dulu</option>'; kel.disabled  = true;
  }

  prov.addEventListener('change', async () => { resetBelow(); if (prov.value) await loadInto(kota, prov.value, 'Pilih Kota/Kabupaten'); });
  kota.addEventListener('change', async () => {
    kec.innerHTML='<option value="">Pilih kota dulu</option>'; kec.disabled=true;
    kel.innerHTML='<option value="">Pilih kecamatan dulu</option>'; kel.disabled=true;
    if (kota.value) await loadInto(kec, kota.value, 'Pilih Kecamatan');
  });
  kec.addEventListener('change', async () => {
    kel.innerHTML='<option value="">Pilih kecamatan dulu</option>'; kel.disabled=true;
    if (kec.value) await loadInto(kel, kec.value, 'Pilih Kelurahan/Desa');
  });
  kel.addEventListener('change', () => {
    const opt = kel.options[kel.selectedIndex];
    const p = opt && opt.getAttribute('data-pos');
    if (p) pos.value = p;   // auto-isi kode pos, tetap editable
  });

  // Restore berjenjang saat kembali dari step 2 (data-cur = kode tersimpan)
  (async function restore(){
    await loadInto(prov, '', 'Pilih Provinsi');
    if (prov.dataset.cur){ prov.value = prov.dataset.cur;
      if (await loadInto(kota, prov.value, 'Pilih Kota/Kabupaten') && kota.dataset.cur){ kota.value = kota.dataset.cur;
        if (await loadInto(kec, kota.value, 'Pilih Kecamatan') && kec.dataset.cur){ kec.value = kec.dataset.cur;
          if (await loadInto(kel, kec.value, 'Pilih Kelurahan/Desa') && kel.dataset.cur){ kel.value = kel.dataset.cur; }
        }
      }
    }
  })();
})();
</script>
```

- [ ] **Step 3: Lint**

Run: `php -l add-outlet.php`
Expected: `No syntax errors detected in add-outlet.php`

- [ ] **Step 4: Commit**

```bash
git add add-outlet.php
git commit -m "feat(add-outlet): form alamat bertingkat Provinsi→Kota→Kec→Kel + auto kode pos (JS cascade)"
```

---

### Task 4: Backend — validasi kode, resolve nama, simpan & tampil

**Files:**
- Modify: `add-outlet-validate.php` (fungsi validasi baru)
- Modify: `add-outlet.php` (handler step 1: baca kode + resolve nama; step 2 review rows; INSERT outlets)

**Interfaces:**
- Consumes: field `w_prov,w_kota,w_kec,w_kel` (kode) dari Task 3; tabel `ref_wilayah` (Task 1); kolom baru `outlets` (Task 1).
- Produces: `$d` berisi `provinsi,kota,kecamatan,kelurahan,wilayah_kode,kode_pos,alamat`; INSERT outlets terisi kolom baru.

- [ ] **Step 1: Fungsi validasi + resolver di `add-outlet-validate.php`**

Ganti isi `add-outlet-validate.php` menjadi:
```php
<?php
/** Validasi alamat lengkap wajib untuk pengiriman welcome kit. Return list pesan error. */
function aoValidateAddress(array $post): array
{
    $errors = [];
    $penerima = trim($post['penerima'] ?? '');
    $telepon  = trim($post['telepon'] ?? '');
    $alamat   = trim($post['alamat'] ?? '');
    $kodePos  = trim($post['kode_pos'] ?? '');
    if (strlen($penerima) < 2)          $errors[] = 'Nama penerima wajib diisi.';
    if (!preg_match('/\d{8,}/', preg_replace('/\D/','',$telepon))) $errors[] = 'No. HP penerima wajib (min 8 digit).';
    if (strlen($alamat) < 8)            $errors[] = 'Alamat jalan wajib diisi (min 8 karakter).';
    foreach (['w_prov'=>'Provinsi','w_kota'=>'Kota/Kabupaten','w_kec'=>'Kecamatan','w_kel'=>'Kelurahan'] as $k=>$label) {
        if (trim($post[$k] ?? '') === '') $errors[] = $label.' wajib dipilih.';
    }
    if (!preg_match('/^\d{5}$/', $kodePos)) $errors[] = 'Kode pos wajib 5 digit.';
    return $errors;
}

/**
 * Validasi & resolve wilayah dari kode POST. Pastikan hierarki benar
 * (kota anak prov, kec anak kota, kel anak kec) via ref_wilayah.
 * Return ['provinsi'=>nama,'kota'=>nama,'kecamatan'=>nama,'kelurahan'=>nama,'wilayah_kode'=>kodeKel]
 * atau null bila tidak valid.
 */
function aoResolveWilayah(PDO $db, array $post): ?array
{
    $prov = trim($post['w_prov'] ?? '');
    $kota = trim($post['w_kota'] ?? '');
    $kec  = trim($post['w_kec']  ?? '');
    $kel  = trim($post['w_kel']  ?? '');
    if ($prov==='' || $kota==='' || $kec==='' || $kel==='') return null;

    $get = function(string $kode, int $level, ?string $parent) use ($db): ?string {
        $st = $db->prepare("SELECT nama FROM ref_wilayah WHERE kode=? AND level=?"
                          . ($parent !== null ? " AND parent_kode=?" : ""));
        $st->execute($parent !== null ? [$kode,$level,$parent] : [$kode,$level]);
        $n = $st->fetchColumn();
        return $n === false ? null : (string)$n;
    };
    $nProv = $get($prov, 1, null);
    $nKota = $get($kota, 2, $prov);
    $nKec  = $get($kec,  3, $kota);
    $nKel  = $get($kel,  4, $kec);
    if ($nProv===null || $nKota===null || $nKec===null || $nKel===null) return null;
    return ['provinsi'=>$nProv,'kota'=>$nKota,'kecamatan'=>$nKec,'kelurahan'=>$nKel,'wilayah_kode'=>$kel];
}
```

- [ ] **Step 2: Handler step 1 — pakai resolver, simpan ke `$d`**

Di `add-outlet.php`, di dalam blok `if (... isset($_POST['step1_submit']))` (mulai ~line 108), ganti bagian setelah `$addrErr = aoValidateAddress($_POST);` menjadi:
```php
    $addrErr = aoValidateAddress($_POST);
    $wil = empty($addrErr) ? aoResolveWilayah(Database::get(), $_POST) : null;
    if (empty($addrErr) && $wil === null) {
        $addrErr[] = 'Wilayah tidak valid — pilih Provinsi→Kota→Kecamatan→Kelurahan dengan benar.';
    }
    if (strlen($namaOutlet) < 3) {
        $error = 'Nama outlet minimal 3 karakter.';
    } elseif (strlen($namaOutlet) > 80) {
        $error = 'Nama outlet maksimal 80 karakter.';
    } elseif (!empty($addrErr)) {
        $error = implode(' ', $addrErr);
    } else {
        $d['nama_outlet'] = $namaOutlet;
        $d['provinsi']    = $wil['provinsi'];
        $d['kota']        = $wil['kota'];
        $d['kecamatan']   = $wil['kecamatan'];
        $d['kelurahan']   = $wil['kelurahan'];
        $d['wilayah_kode']= $wil['wilayah_kode'];
        $d['alamat']      = $alamat;
        $d['telepon']     = $telepon;
        $d['penerima']    = $penerima;
        $d['kode_pos']    = $kodePos;
        $d['mode']        = $mode;
        // simpan kode utk restore dropdown saat balik ke step 1
        $d['w_prov']=$_POST['w_prov']; $d['w_kota']=$_POST['w_kota'];
        $d['w_kec']=$_POST['w_kec'];   $d['w_kel']=$_POST['w_kel'];
        $w['step'] = 2; $step = 2;
        $_SESSION['ao_csrf'] = bin2hex(random_bytes(32));
    }
```
(Hapus baris lama `$kota = trim($_POST['kota'] ?? '');` di awal handler bila menyebabkan duplikasi — `$kota` tak lagi dipakai; boleh dihapus. `$d['kota']` kini dari resolver.)

- [ ] **Step 3: Step 2 review — tampilkan Provinsi/Kecamatan/Kelurahan**

Di blok review step 2 `add-outlet.php` (~line 654-675), setelah baris "Kota" tambahkan baris review. Ganti/rapikan blok Kota + Alamat menjadi:
```php
          <div class="review-row">
            <span class="rv-label">Provinsi</span>
            <span class="rv-val"><?= htmlspecialchars($d['provinsi'] ?? '-') ?></span>
          </div>
          <div class="review-row">
            <span class="rv-label">Kota / Kabupaten</span>
            <span class="rv-val"><?= htmlspecialchars($d['kota'] ?? '-') ?></span>
          </div>
          <div class="review-row">
            <span class="rv-label">Kecamatan</span>
            <span class="rv-val"><?= htmlspecialchars($d['kecamatan'] ?? '-') ?></span>
          </div>
          <div class="review-row">
            <span class="rv-label">Kelurahan / Desa</span>
            <span class="rv-val"><?= htmlspecialchars($d['kelurahan'] ?? '-') ?></span>
          </div>
          <div class="review-row">
            <span class="rv-label">Alamat jalan</span>
            <span class="rv-val" style="max-width:60%;text-align:right"><?= htmlspecialchars($d['alamat'] ?? '-') ?></span>
          </div>
          <div class="review-row">
            <span class="rv-label">Kode Pos</span>
            <span class="rv-val"><?= htmlspecialchars($d['kode_pos'] ?? '-') ?></span>
          </div>
```
(Hapus baris review "Kota" & "Alamat" lama yang lama agar tidak dobel.)

- [ ] **Step 4: INSERT outlets — sertakan kolom baru**

Ganti statement INSERT (~line 150-168) menjadi:
```php
        $db->prepare("
            INSERT INTO outlets
              (tenant_id, nama_outlet, slug, kota, provinsi, kecamatan, kelurahan, wilayah_kode,
               alamat, telepon, penerima, kode_pos,
               status, trial_starts_at, trial_ends_at,
               trial_coin_balance, coin_balance, is_main, setup_done)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?,0,?,0)
        ")->execute([
            $tid,
            $d['nama_outlet'],
            $outletSlug,
            $d['kota']         ?: null,
            $d['provinsi']     ?? null,
            $d['kecamatan']    ?? null,
            $d['kelurahan']    ?? null,
            $d['wilayah_kode'] ?? null,
            $d['alamat']       ?: null,
            $d['telepon']      ?: null,
            $d['penerima']     ?? null,
            $d['kode_pos']     ?? null,
            $trialStatus,
            $trialEnds,
            $trialCoins,
            $isFirstOutlet ? 1 : 0,
        ]);
```
(Perhatikan: jumlah `?` = 18, cocok dgn urutan kolom & array.)

- [ ] **Step 5: Lint**

Run: `php -l add-outlet.php && php -l add-outlet-validate.php`
Expected: `No syntax errors detected` untuk keduanya.

- [ ] **Step 6: Commit**

```bash
git add add-outlet.php add-outlet-validate.php
git commit -m "feat(add-outlet): validasi+resolve wilayah server-side, simpan nama+kode kelurahan ke outlets, review step 2"
```

---

## Manual E2E (setelah deploy)
- [ ] Buka **Tambah Outlet** → dropdown **Provinsi** termuat otomatis.
- [ ] Pilih Provinsi → **Kota** termuat; pilih Kota → **Kecamatan** termuat; pilih Kecamatan → **Kelurahan** termuat.
- [ ] Pilih **Kelurahan** → **Kode Pos** terisi otomatis (bila dataset punya); ubah manual tetap bisa.
- [ ] Isi Alamat jalan, lanjut → **Step 2 (Konfirmasi)** menampilkan Provinsi/Kota/Kecamatan/Kelurahan/Alamat/Kode Pos benar.
- [ ] Kembali ke step 1 (Ubah) → dropdown ter-restore ke pilihan sebelumnya.
- [ ] Submit → outlet dibuat; cek DB: `SELECT nama_outlet,provinsi,kota,kecamatan,kelurahan,wilayah_kode,kode_pos FROM outlets ORDER BY id DESC LIMIT 1;` terisi.
- [ ] Coba submit dengan salah satu dropdown kosong / kombinasi kode dipalsukan (via devtools) → ditolak "Wilayah tidak valid".
- [ ] Outlet lama (tanpa kolom baru) tetap tampil normal di HQ/Outlet & daftar.
- [ ] Kelurahan tanpa kodepos → Kode Pos kosong, diisi manual, tersimpan.

---

## Self-Review

**Spec coverage:**
- Tabel ref_wilayah + seed dari cahyadsn → Task 1 ✓
- Kolom baru outlets (provinsi/kecamatan/kelurahan/wilayah_kode) → Task 1 ✓
- Endpoint cascade api/wilayah.php → Task 2 ✓
- Form 4 dropdown bertingkat + auto kode pos + alamat jalan → Task 3 ✓
- Kirim kode, resolve nama server-side, validasi hierarki → Task 4 (aoResolveWilayah) ✓
- Step 2 review menampilkan semua level → Task 4 Step 3 ✓
- INSERT kolom baru → Task 4 Step 4 ✓
- Backward-compat (kolom nullable, outlet lama utuh) → Task 1 + E2E ✓
- Keamanan: nama dari DB bukan klien, parent prepared, kode pos strip → Task 2/4 ✓
- Scope add-outlet only → tidak menyentuh registration/edit ✓

**Placeholder scan:** Tak ada TBD/TODO; semua step berisi kode/perintah konkret.

**Type consistency:** `aoResolveWilayah(PDO $db, array $post): ?array` mengembalikan key `provinsi/kota/kecamatan/kelurahan/wilayah_kode` — dipakai konsisten di handler step 1, review step 2, dan INSERT. Field form `w_prov/w_kota/w_kec/w_kel` konsisten antara Task 3 (markup) & Task 4 (baca POST). Endpoint mengembalikan `{kode,nama,kodepos}` — dipakai JS `o.kode/o.nama/o.kodepos` di Task 3.

**Catatan eksekusi:** Kerjakan di branch dari `main`. Task 1 menyentuh PROD DB (seed besar tapi idempoten via TRUNCATE + DROP staging). Task 2-4 murni web, deploy via push. Verifikasi endpoint (Task 2 Step 4) butuh sesi login → uji manual di browser.

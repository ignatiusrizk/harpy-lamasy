# Kelola Kategori Kas Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ganti daftar kategori transaksi Kas dari hardcode di kode
(`kas.php`) jadi data yang bisa dikelola owner (tambah/edit/hapus) lewat
UI, tanpa mengubah perilaku existing sedikit pun begitu fitur ini live.

**Architecture:** Tabel baru `hl_kas_kategori` (tenant-wide, TANPA
`outlet_id`) diseed otomatis dengan 12 kategori yang sudah ada utk semua
tenant existing. Backend `kas.php` dapat 3 action baru (list/save/delete,
pola CRUD sederhana pakai `TenantQuery`). Frontend: array JS hardcode
`KAT` diganti fetch dinamis dari tabel yang sama, plus modal CRUD baru
mengikuti konvensi modal LOKAL file ini (`.hl-modal` + `style.display`,
BUKAN `.hl-modal-overlay` yang dipakai `layanan.php` — file berbeda,
konvensi modal berbeda, ikuti yang ADA di file yang diedit).

**Tech Stack:** PHP procedural, MySQL, vanilla JS. Test via `php
tests/**/test_*.php` (pola `tests/_assert.php`).

## Global Constraints

- **Tenant-wide, TIDAK ADA kolom `outlet_id`** di `hl_kas_kategori` — beda
  dari `hl_express_tier`/`hl_biaya_lainnya_tier` yang punya opsi
  per-outlet. Satu daftar kategori berlaku sama utk semua outlet tenant.
- **Kategori = teks snapshot, bukan referensi hidup** — edit/hapus kategori
  di master TIDAK PERNAH menyentuh `hl_kas.kategori` pada baris histori
  yang sudah ada. Tidak ada foreign key antara `hl_kas.kategori` dan
  `hl_kas_kategori.id`.
- **Migrasi WAJIB seed 12 kategori existing** (persis nama & emoji yang
  sekarang ada di kode) ke SETIAP `tenant_id` yang ada di tabel `outlets`
  — dropdown Kas tidak boleh berubah/kosong begitu fitur ini live.
- **Validasi tipe Masuk/Keluar TIDAK BERUBAH PERILAKU**: kategori yang ADA
  di tabel dgn tipe beda dari transaksi → ditolak. Kategori custom/legacy
  yang TIDAK ADA di tabel sama sekali → TETAP DITERIMA (perilaku existing,
  jangan diperketat).
- Permission: `kas_kategori_save` pakai `hasPermission('kas.create')`,
  `kas_kategori_delete` pakai `hasPermission('kas.delete')` — modul kas
  cuma punya 2 permission ini, tidak ada `kas.edit` terpisah.
- `laporan.php` TIDAK disentuh — sudah otomatis bekerja (grouping teks).

---

## File yang disentuh

- **Migrasi baru:** `migrations/2026-08-31-kas-kategori.sql`
- **Modify:** `kas.php` — 3 action backend baru, validasi diganti,
  dropdown dinamis, tombol + modal CRUD baru.

---

### Task 1: Migrasi DB — tabel + seed 12 kategori existing

**Files:**
- Create: `migrations/2026-08-31-kas-kategori.sql`

**Interfaces:**
- Produces: tabel `hl_kas_kategori` terisi 12 baris × N tenant — dipakai
  Task 2 & 3.

- [ ] **Step 1: Tulis file migrasi**

```sql
-- migrations/2026-08-31-kas-kategori.sql
-- Kelola Kategori Kas: dari hardcode di kas.php jadi data terkelola.
-- Lihat spec: docs/superpowers/specs/2026-08-31-kas-kategori-design.md

CREATE TABLE hl_kas_kategori (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  nama VARCHAR(50) NOT NULL,
  tipe ENUM('masuk','keluar') NOT NULL,
  emoji VARCHAR(10) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  urutan INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tenant_tipe_active (tenant_id, tipe, is_active)
);

-- Seed 12 kategori existing (persis nama+emoji dari kas.php) ke SETIAP
-- tenant yang sudah ada, supaya dropdown tidak berubah begitu fitur live.
INSERT INTO hl_kas_kategori (tenant_id, nama, tipe, emoji, urutan)
SELECT t.tenant_id, k.nama, k.tipe, k.emoji, k.urutan
FROM (SELECT DISTINCT tenant_id FROM outlets) t
CROSS JOIN (
  SELECT 'Penjualan Laundry' AS nama, 'masuk' AS tipe, '💰' AS emoji, 1 AS urutan
  UNION ALL SELECT 'Pelunasan Order',   'masuk', '🧾', 2
  UNION ALL SELECT 'Pendapatan Lain',   'masuk', '➕', 3
  UNION ALL SELECT 'Modal',             'masuk', '🏦', 4
  UNION ALL SELECT 'Gaji Karyawan',     'keluar', '👥', 5
  UNION ALL SELECT 'Bahan & Deterjen',  'keluar', '🧴', 6
  UNION ALL SELECT 'Listrik & Air',     'keluar', '⚡', 7
  UNION ALL SELECT 'Sewa Tempat',       'keluar', '🏠', 8
  UNION ALL SELECT 'Peralatan',         'keluar', '🔧', 9
  UNION ALL SELECT 'Transportasi',      'keluar', '🛵', 10
  UNION ALL SELECT 'Operasional',       'keluar', '⚙️', 11
  UNION ALL SELECT 'Lain-lain',         'keluar', '📌', 12
) k;
```

- [ ] **Step 2: Jalankan migrasi**

Run: `mysql < migrations/2026-08-31-kas-kategori.sql`

Expected: tidak ada output (sukses).

- [ ] **Step 3: Verifikasi**

Run:
```bash
mysql -e "SHOW TABLES LIKE 'hl_kas_kategori'"
mysql -e "SELECT tenant_id, COUNT(*) FROM hl_kas_kategori GROUP BY tenant_id"
mysql -e "SELECT nama, tipe, emoji FROM hl_kas_kategori WHERE tenant_id=18 ORDER BY urutan"
```

Expected: tabel ada; setiap tenant_id yang ada di `outlets` punya PERSIS
12 baris; daftar tenant 18 cocok 12 nama+tipe+emoji sesuai tabel di spec
(Penjualan Laundry/masuk/💰, ... , Lain-lain/keluar/📌).

- [ ] **Step 4: Commit**

```bash
git add migrations/2026-08-31-kas-kategori.sql
git commit -m "db: tabel hl_kas_kategori + seed 12 kategori existing ke semua tenant"
```

---

### Task 2: `kas.php` backend — 3 action CRUD + ganti validasi

**Files:**
- Modify: `kas.php`

**Interfaces:**
- Consumes: tabel `hl_kas_kategori` dari Task 1.
- Produces: endpoint `action=kas_kategori_list/save/delete` — dikonsumsi
  Task 3 (frontend).

- [ ] **Step 1: Ganti blok validasi tipe di action `save` (transaksi Kas)**

Cari (sekitar baris 68-75):

```php
        // Kategori tak boleh milik tipe lawan (mis. 'Penjualan Laundry' pada kas keluar)
        // — nilai custom/legacy di luar dua daftar ini tetap diterima.
        $_katMasuk  = ['Penjualan Laundry','Pelunasan Order','Pendapatan Lain','Modal'];
        $_katKeluar = ['Gaji Karyawan','Bahan & Deterjen','Listrik & Air','Sewa Tempat','Peralatan','Transportasi','Operasional','Lain-lain'];
        if (($data['tipe'] === 'masuk'  && in_array($data['kategori'], $_katKeluar, true)) ||
            ($data['tipe'] === 'keluar' && in_array($data['kategori'], $_katMasuk,  true))) {
            echo json_encode(['error' => 'Kategori tidak sesuai tipe kas (masuk/keluar)']); exit;
        }
```

Ganti jadi (query tabel, BUKAN array hardcode — tapi PERILAKU SAMA PERSIS:
kategori yang tidak terdaftar sama sekali tetap lolos, cuma yang
terdaftar dgn tipe BEDA yang ditolak):

```php
        // Kategori tak boleh milik tipe lawan (mis. 'Penjualan Laundry' pada kas keluar)
        // — nilai custom/legacy yang TIDAK terdaftar di hl_kas_kategori tetap diterima.
        if ($data['kategori'] !== '') {
            $tipeLawan = $data['tipe'] === 'masuk' ? 'keluar' : 'masuk';
            $konflik = TenantQuery::rawOne(
                "SELECT id FROM hl_kas_kategori WHERE tenant_id=? AND nama=? AND tipe=?",
                [$tid, $data['kategori'], $tipeLawan]
            );
            if ($konflik) {
                echo json_encode(['error' => 'Kategori tidak sesuai tipe kas (masuk/keluar)']); exit;
            }
        }
```

- [ ] **Step 2: Tambah 3 action CRUD baru**

Cari (sekitar baris 133-139):

```php
    // KATEGORI LIST
    if ($action === 'kategori') {
        $rows = TenantQuery::raw("SELECT DISTINCT kategori FROM hl_kas WHERE tenant_id=? AND outlet_id=? ORDER BY kategori", [$tid, $oid]);
        echo json_encode(array_column($rows, 'kategori')); exit;
    }

    echo json_encode(['error'=>'Unknown']); exit;
```

Sisipkan 3 action baru SEBELUM baris `echo json_encode(['error'=>'Unknown']); exit;` (action `kategori` lama TIDAK disentuh — dead code, di luar scope):

```php
    // KATEGORI LIST
    if ($action === 'kategori') {
        $rows = TenantQuery::raw("SELECT DISTINCT kategori FROM hl_kas WHERE tenant_id=? AND outlet_id=? ORDER BY kategori", [$tid, $oid]);
        echo json_encode(array_column($rows, 'kategori')); exit;
    }

    // ── Kelola Kategori Kas (tenant-wide, TANPA outlet_id) ──
    if ($action === 'kas_kategori_list') {
        $rows = TenantQuery::raw(
            "SELECT id, nama, tipe, emoji, is_active, urutan
               FROM hl_kas_kategori
              WHERE tenant_id=?
              ORDER BY tipe ASC, urutan ASC, id ASC",
            [$tid]
        );
        echo json_encode(['kategori' => $rows]); exit;
    }

    if ($action === 'kas_kategori_save' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('kas.create')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d      = json_decode(file_get_contents('php://input'), true);
        $nama   = substr(trim(strip_tags((string)($d['nama'] ?? ''))), 0, 50);
        $tipe   = in_array($d['tipe'] ?? '', ['masuk','keluar'], true) ? $d['tipe'] : 'masuk';
        $emoji  = substr(trim((string)($d['emoji'] ?? '')), 0, 10) ?: null;
        $aktif  = (int)($d['is_active'] ?? 1) ? 1 : 0;
        $urut   = (int)($d['urutan'] ?? 0);
        if ($nama === '') {
            echo json_encode(['error'=>'Nama kategori wajib diisi']); exit;
        }

        $data = ['nama'=>$nama, 'tipe'=>$tipe, 'emoji'=>$emoji, 'is_active'=>$aktif, 'urutan'=>$urut];
        if (!empty($d['id'])) {
            TenantQuery::update('hl_kas_kategori', $data, 'id = ?', [(int)$d['id']]);
        } else {
            TenantQuery::insert('hl_kas_kategori', $data);
        }
        echo json_encode(['success'=>true]); exit;
    }

    if ($action === 'kas_kategori_delete' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('kas.delete')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        TenantQuery::delete('hl_kas_kategori', 'id = ?', [(int)($d['id'] ?? 0)]);
        echo json_encode(['success'=>true]); exit;
    }

    echo json_encode(['error'=>'Unknown']); exit;
```

- [ ] **Step 3: Verifikasi manual — simulasi CRUD via PHP CLI**

```bash
php -r '
require "master/config/db.php"; require "core/Database.php";
$db = Database::get();
$db->prepare("INSERT INTO hl_kas_kategori (tenant_id, nama, tipe, emoji, is_active) VALUES (18, "Verif CRUD", "masuk", "🧪", 1)")->execute();
$id = $db->lastInsertId();
$row = $db->query("SELECT nama, tipe, emoji FROM hl_kas_kategori WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
echo "Insert OK: " . json_encode($row) . "\n";
$db->prepare("DELETE FROM hl_kas_kategori WHERE id=?")->execute([$id]);
echo "Cleanup OK\n";
'
```

Expected: `Insert OK: {"nama":"Verif CRUD","tipe":"masuk","emoji":"🧪"}`
lalu `Cleanup OK`.

Lalu `php -l kas.php` → "No syntax errors detected".

- [ ] **Step 4: Verifikasi manual — validasi tipe tetap sama perilakunya**

```bash
php -r '
require "master/config/db.php"; require "core/Database.php";
$db = Database::get();
$tid = 18;

// Kasus 1: kategori terdaftar, tipe SESUAI → tidak ada konflik
$konflik = $db->prepare("SELECT id FROM hl_kas_kategori WHERE tenant_id=? AND nama=? AND tipe=?");
$konflik->execute([$tid, "Modal", "keluar"]); // Modal aslinya masuk, cek versus keluar (tipe lawan dari transaksi tipe=masuk)
echo "Kasus 1 (Modal, transaksi=masuk, cek lawan=keluar): " . ($konflik->fetchColumn() ? "ADA (salah, harusnya kosong)" : "KOSONG (benar)") . "\n";

$konflik->execute([$tid, "Modal", "masuk"]); // transaksi tipe=keluar, cek lawan=masuk -> Modal ADA di masuk -> konflik terdeteksi
echo "Kasus 2 (Modal, transaksi=keluar, cek lawan=masuk): " . ($konflik->fetchColumn() ? "ADA (benar, harus ditolak)" : "KOSONG (salah)") . "\n";

// Kasus 3: kategori custom/legacy TIDAK terdaftar sama sekali -> harus tetap diterima (KOSONG di kedua tipe)
$konflik->execute([$tid, "Kategori Ngasal Random", "keluar"]);
echo "Kasus 3 (custom, cek lawan=keluar): " . ($konflik->fetchColumn() ? "ADA (salah)" : "KOSONG (benar, custom tetap diterima)") . "\n";
'
```

Expected:
```
Kasus 1 (Modal, transaksi=masuk, cek lawan=keluar): KOSONG (benar)
Kasus 2 (Modal, transaksi=keluar, cek lawan=masuk): ADA (benar, harus ditolak)
Kasus 3 (custom, cek lawan=keluar): KOSONG (benar, custom tetap diterima)
```

- [ ] **Step 5: Commit**

```bash
git add kas.php
git commit -m "feat(kas): 3 action CRUD kategori + validasi tipe pakai tabel (bukan array hardcode)"
```

---

### Task 3: `kas.php` frontend — dropdown dinamis + tombol & modal Kelola Kategori

**Files:**
- Modify: `kas.php`

**Interfaces:**
- Consumes: `action=kas_kategori_list/save/delete` dari Task 2.

- [ ] **Step 1: Hapus array `KAT` hardcode, ganti jadi variabel diisi fetch**

Cari (sekitar baris 459-469):

```javascript
// ── Dropdown kategori kustom ──
const KAT = {
  masuk: [
    {v:'Penjualan Laundry', e:'💰'}, {v:'Pelunasan Order', e:'🧾'},
    {v:'Pendapatan Lain', e:'➕'},  {v:'Modal', e:'🏦'},
  ],
  keluar: [
    {v:'Gaji Karyawan', e:'👥'}, {v:'Bahan & Deterjen', e:'🧴'}, {v:'Listrik & Air', e:'⚡'},
    {v:'Sewa Tempat', e:'🏠'},   {v:'Peralatan', e:'🔧'},        {v:'Transportasi', e:'🛵'},
    {v:'Operasional', e:'⚙️'},   {v:'Lain-lain', e:'📌'},
  ],
};
```

Ganti jadi (variabel sama, awalnya kosong, diisi via fetch — SEMUA fungsi
lain yang baca `KAT.masuk`/`KAT.keluar` [`katMeta`, `katRender`, `setTipe`]
TIDAK PERLU diubah sama sekali, karena bentuk datanya dipertahankan sama:
array of `{v, e}`):

```javascript
// ── Dropdown kategori — diisi dari server (hl_kas_kategori), bukan
// hardcode lagi. loadKasKategori() dipanggil sekali saat halaman ready.
let KAT = { masuk: [], keluar: [] };
let currentKasKategoriRows = []; // dipakai modal Kelola Kategori (Step 5+)

async function loadKasKategori() {
  try {
    const r = await fetch('kas.php?action=kas_kategori_list');
    const d = await r.json();
    currentKasKategoriRows = (d.kategori || []).filter(k => k.is_active == 1);
    KAT = { masuk: [], keluar: [] };
    currentKasKategoriRows.forEach(k => {
      KAT[k.tipe].push({ v: k.nama, e: k.emoji || '🏷️' });
    });
  } catch (e) {
    KAT = { masuk: [], keluar: [] };
  }
  buildKategoriSelect();
  katSync();
}

// Isi ulang <select id="f_kategori"> dari KAT (dipanggil tiap loadKasKategori selesai)
function buildKategoriSelect() {
  const sel = document.getElementById('f_kategori');
  const cur = sel.value;
  let html = '<option value="">— Pilih Kategori —</option>';
  html += '<optgroup label="💚 Kas Masuk" id="optMasuk">';
  KAT.masuk.forEach(o => { html += `<option value="${katEsc(o.v)}">${o.e} ${katEsc(o.v)}</option>`; });
  html += '</optgroup><optgroup label="❤️ Kas Keluar" id="optKeluar">';
  KAT.keluar.forEach(o => { html += `<option value="${katEsc(o.v)}">${o.e} ${katEsc(o.v)}</option>`; });
  html += '</optgroup>';
  sel.innerHTML = html;
  sel.value = cur; // pertahankan pilihan kalau masih ada di daftar baru
}
```

- [ ] **Step 2: Kosongkan `<select id="f_kategori">` (isinya sekarang dari JS)**

Cari (sekitar baris 320-341):

```php
            <select id="f_kategori" style="display:none">
              <option value="">— Pilih Kategori —</option>
              <optgroup label="💚 Kas Masuk" id="optMasuk">
                <option value="Penjualan Laundry">💰 Penjualan Laundry</option>
                <option value="Pelunasan Order">🧾 Pelunasan Order</option>
                <option value="Pendapatan Lain">➕ Pendapatan Lain</option>
                <option value="Modal">🏦 Modal</option>
              </optgroup>
              <optgroup label="❤️ Kas Keluar" id="optKeluar">
                <option value="Gaji Karyawan">👥 Gaji Karyawan</option>
                <option value="Bahan & Deterjen">🧴 Bahan &amp; Deterjen</option>
                <option value="Listrik & Air">⚡ Listrik &amp; Air</option>
                <option value="Sewa Tempat">🏠 Sewa Tempat</option>
                <option value="Peralatan">🔧 Peralatan</option>
                <option value="Transportasi">🛵 Transportasi</option>
                <option value="Operasional">⚙️ Operasional</option>
                <option value="Lain-lain">📌 Lain-lain</option>
              </optgroup>
            </select>
```

Ganti jadi (opsi diisi JS `buildKategoriSelect()` dari Step 1 — biarkan
kosong di HTML supaya tidak ada 2 sumber data yang bisa berbeda):

```php
            <select id="f_kategori" style="display:none">
              <option value="">— Pilih Kategori —</option>
            </select>
```

- [ ] **Step 3: Panggil `loadKasKategori()` saat halaman ready**

Cari (sekitar baris 452-456):

```javascript
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('f_tanggal').value = localDateStr();
  setRange('bulan');
  loadSaldoHarian();
});
```

Ganti jadi:

```javascript
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('f_tanggal').value = localDateStr();
  setRange('bulan');
  loadSaldoHarian();
  loadKasKategori();
});
```

- [ ] **Step 4: Tambah tombol "⚙️ Kelola Kategori"**

Cari (sekitar baris 295-298):

```php
        <div class="hl-card-header">
          <div class="hl-card-title" id="formTitle">➕ Input Kas</div>
          <button type="button" class="hl-btn hl-btn-outline hl-btn-sm" onclick="document.getElementById('strukFile').click()">📸 Scan Struk</button>
          <input type="file" id="strukFile" accept="image/*" capture="environment" style="display:none" onchange="kasStrukUpload(this)">
        </div>
```

Ganti jadi:

```php
        <div class="hl-card-header">
          <div class="hl-card-title" id="formTitle">➕ Input Kas</div>
          <button type="button" class="hl-btn hl-btn-outline hl-btn-sm" onclick="document.getElementById('strukFile').click()">📸 Scan Struk</button>
          <input type="file" id="strukFile" accept="image/*" capture="environment" style="display:none" onchange="kasStrukUpload(this)">
          <button type="button" class="hl-btn hl-btn-outline hl-btn-sm" onclick="openKasKategoriModal()">⚙️ Kelola Kategori</button>
        </div>
```

- [ ] **Step 5: Tambah modal CRUD — HTML**

Cari penutup modal Scan Struk yang sudah ada (sekitar baris 441-442):

```php
  </div>
</div>

<script>
```

Sisipkan modal baru TEPAT SETELAH `</div>` penutup `#kasStrukModal` (baris
441), SEBELUM `<script>`:

```php
  </div>
</div>

<!-- MODAL KELOLA KATEGORI KAS -->
<div id="kasKategoriModal" class="hl-modal" style="display:none">
  <div class="hl-modal-box" style="max-width:520px">
    <h3 style="margin:0 0 6px">⚙️ Kelola Kategori Kas</h3>
    <p style="margin:0 0 14px;font-size:12px;color:#6b7280">
      Berlaku sama untuk semua outlet. Mengedit/menghapus kategori TIDAK
      mengubah transaksi lama yang sudah pakai nama kategori itu.
    </p>

    <div id="kasKategoriList" style="margin-bottom:16px;max-height:280px;overflow-y:auto"></div>

    <div style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:10px;padding:14px;">
      <div style="font-weight:600;font-size:13px;color:#374151;margin-bottom:10px" id="kkFormTitle">➕ Tambah Kategori Baru</div>
      <input type="hidden" id="kk_id" value=""/>
      <div class="hl-form-row">
        <div class="hl-form-group" style="flex:2">
          <label class="hl-label">Nama Kategori <span class="req">*</span></label>
          <input type="text" id="kk_nama" class="hl-input" placeholder="Sewa Tempat" maxlength="50"/>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Emoji</label>
          <input type="text" id="kk_emoji" class="hl-input" placeholder="🏠" maxlength="10"/>
        </div>
      </div>
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Tipe</label>
          <select id="kk_tipe" class="hl-input">
            <option value="masuk">💚 Kas Masuk</option>
            <option value="keluar">❤️ Kas Keluar</option>
          </select>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Status</label>
          <select id="kk_active" class="hl-input">
            <option value="1">✅ Aktif</option>
            <option value="0">⏸️ Nonaktif</option>
          </select>
        </div>
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px">
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="resetKasKategoriForm()">↺ Reset</button>
        <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="saveKasKategori()">💾 Simpan</button>
      </div>
    </div>

    <div style="display:flex;justify-content:flex-end;margin-top:14px">
      <button class="hl-btn hl-btn-outline" onclick="closeKasKategoriModal()">Tutup</button>
    </div>
  </div>
</div>

<script>
```

- [ ] **Step 6: Tambah modal CRUD — JS**

Cari akhir fungsi `buildKategoriSelect()` yang baru dibuat di Step 1 (ada
di dalam blok `<script>` yang sudah ada). Tambahkan fungsi-fungsi baru
berikut TEPAT SETELAHNYA (masih di dalam tag `<script>` yang sama):

```javascript
// ── Modal Kelola Kategori Kas ──
function openKasKategoriModal() {
  resetKasKategoriForm();
  document.getElementById('kasKategoriModal').style.display = 'flex';
  renderKasKategoriList();
}
function closeKasKategoriModal() {
  document.getElementById('kasKategoriModal').style.display = 'none';
}

function renderKasKategoriList() {
  const list = document.getElementById('kasKategoriList');
  if (!currentKasKategoriRows.length) {
    list.innerHTML = '<div style="text-align:center;padding:20px;color:var(--gray);font-size:13px">Belum ada kategori.</div>';
    return;
  }
  list.innerHTML = `
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead><tr style="background:#F3F4F6;text-align:left">
        <th style="padding:8px">Nama</th><th style="padding:8px">Tipe</th>
        <th style="padding:8px">Status</th><th style="padding:8px;text-align:right">Aksi</th>
      </tr></thead>
      <tbody>
        ${currentKasKategoriRows.map(k => `
          <tr style="border-bottom:1px solid #F3F4F6">
            <td style="padding:8px">${k.emoji || '🏷️'} <strong>${katEsc(k.nama)}</strong></td>
            <td style="padding:8px">${k.tipe === 'masuk' ? '💚 Masuk' : '❤️ Keluar'}</td>
            <td style="padding:8px">${k.is_active == 1 ? '<span style="color:#059669">● Aktif</span>' : '<span style="color:#9CA3AF">○ Off</span>'}</td>
            <td style="padding:8px;text-align:right;white-space:nowrap">
              <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="editKasKategori(${k.id})">✏️</button>
              <button class="hl-btn hl-btn-danger hl-btn-sm" onclick="deleteKasKategori(${k.id})">🗑️</button>
            </td>
          </tr>`).join('')}
      </tbody>
    </table>`;
}

function resetKasKategoriForm() {
  document.getElementById('kk_id').value = '';
  document.getElementById('kk_nama').value = '';
  document.getElementById('kk_emoji').value = '';
  document.getElementById('kk_tipe').value = 'masuk';
  document.getElementById('kk_active').value = '1';
  document.getElementById('kkFormTitle').textContent = '➕ Tambah Kategori Baru';
}

function editKasKategori(id) {
  const k = currentKasKategoriRows.find(x => x.id == id);
  if (!k) return;
  document.getElementById('kk_id').value = k.id;
  document.getElementById('kk_nama').value = k.nama;
  document.getElementById('kk_emoji').value = k.emoji || '';
  document.getElementById('kk_tipe').value = k.tipe;
  document.getElementById('kk_active').value = String(k.is_active);
  document.getElementById('kkFormTitle').textContent = '✏️ Edit Kategori';
}

async function saveKasKategori() {
  const payload = {
    id:        document.getElementById('kk_id').value || null,
    nama:      document.getElementById('kk_nama').value.trim(),
    tipe:      document.getElementById('kk_tipe').value,
    emoji:     document.getElementById('kk_emoji').value.trim(),
    is_active: parseInt(document.getElementById('kk_active').value),
  };
  if (!payload.nama) { showToast('Nama kategori wajib diisi', 'error'); return; }
  try {
    const r = await fetch('kas.php?action=kas_kategori_save', {
      method: 'POST', headers: {'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify(payload)
    });
    const d = await r.json();
    if (d.error) { showToast(d.error, 'error'); return; }
    showToast('Kategori tersimpan', 'success');
    resetKasKategoriForm();
    await loadKasKategori();
    renderKasKategoriList();
  } catch (e) {
    showToast('Gagal simpan: ' + e.message, 'error');
  }
}

async function deleteKasKategori(id) {
  if (!await lmConfirm('Hapus kategori ini? Transaksi lama yang sudah pakai kategori ini TIDAK ikut terhapus/berubah.')) return;
  try {
    const r = await fetch('kas.php?action=kas_kategori_delete', {
      method: 'POST', headers: {'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify({id})
    });
    const d = await r.json();
    if (d.error) { showToast(d.error, 'error'); return; }
    showToast('Kategori dihapus', 'success');
    await loadKasKategori();
    renderKasKategoriList();
  } catch (e) {
    showToast('Gagal hapus: ' + e.message, 'error');
  }
}
```

(Fungsi `katEsc()`, `showToast()`, `lmConfirm()`, `csrfToken()` SEMUA
sudah ada di file ini — reuse langsung, JANGAN didefinisikan ulang.)

- [ ] **Step 7: Verifikasi manual**

Run: `php -l kas.php` → "No syntax errors detected".

Verifikasi dropdown tetap terisi seperti sebelumnya (data hasil migrasi
Task 1):
```bash
mysql -e "SELECT COUNT(*) FROM hl_kas_kategori WHERE tenant_id=18 AND is_active=1"
```
Expected: `12` (semua kategori seed default aktif).

- [ ] **Step 8: Commit**

```bash
git add kas.php
git commit -m "feat(kas): dropdown kategori dinamis dari DB + modal Kelola Kategori (tambah/edit/hapus)"
```

---

## Ringkasan Urutan Task

1. Migrasi DB (tabel + seed 12 kategori × semua tenant)
2. Backend `kas.php` (3 action CRUD + ganti validasi tipe)
3. Frontend `kas.php` (dropdown dinamis + tombol & modal Kelola Kategori)

Urutan wajib sequential (Task 2 butuh tabel dari Task 1, Task 3 butuh
action dari Task 2) — file yang sama (`kas.php`) disentuh 2 kali di
task berbeda, jangan dikerjakan paralel.

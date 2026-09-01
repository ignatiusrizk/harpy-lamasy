# Mode Simple Self-Service via POS Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tambahkan jalur POS yang lebih pendek untuk transaksi self-service (customer pakai mesin sendiri, staf input di kasir) — kategori layanan `Self-Service` memicu tombol add-on tap-to-add (Sabun, Plastik, dst) dan membuat No. HP opsional, tanpa menyentuh sistem QR-booking (mesin.php/self.php) yang sudah ada.

**Architecture:** Deteksi berbasis string kategori `hl_layanan.kategori` (tidak ada kolom DB baru) — normalisasi lowercase+strip-non-alfanumerik di kedua sisi (JS & PHP) supaya "Self-Service"/"self service"/"SELFSERVICE" semua cocok. Backend (`pos.php` action `save`) dan frontend (`pos.php` inline script) masing-masing punya salinan logika deteksi & validasi kondisional — backend WAJIB validasi ulang (defense-in-depth), tidak boleh cuma percaya client.

**Tech Stack:** PHP (pos.php, layanan.php), vanilla JS inline di pos.php, MySQL (hl_layanan).

## Global Constraints

- Sistem Mesin Self-Service (QR-booking, `mesin.php`/`self.php`) TIDAK DIUBAH SAMA SEKALI di plan ini.
- TIDAK ADA kolom database baru. Semua deteksi kategori via string compare pada `hl_layanan.kategori` yang sudah ada.
- Kategori pemicu: **`Self-Service`**. Kategori add-on: **`Tambahan Self-Service`**. Perbandingan HARUS case-insensitive & mengabaikan spasi/tanda hubung (normalize: lowercase, strip `[^a-z0-9]`).
- Order self-service = keranjang punya **minimal 1 item** berkategori `Self-Service` (normalized). Tidak ada toggle/checkbox manual.
- Untuk order self-service: **Nama pelanggan tetap wajib**, **No. HP opsional**. Order non-self-service: validasi nama+telepon wajib tidak berubah sama sekali.
- Tombol add-on: tap-to-add (bukan auto-masuk keranjang). Kalau addon itu sudah ada di keranjang (match by `layanan_id`), tombolnya disabled.
- Server-side validation WAJIB ada untuk pengecualian telepon (bukan cuma client-side) — pola defense-in-depth yang sudah dipakai di file ini untuk validasi lain (qty_minimum, dsb).

---

### Task 1: Kategori baru di Layanan + migrasi data existing

**Files:**
- Modify: `layanan.php:812`
- Data migration: dijalankan manual sekali via script PHP CLI (bukan file baru yang di-commit — dijalankan langsung, hasilnya cuma perubahan data), lihat Step 3.

**Interfaces:**
- Produces: kategori string `'Self-Service'` dan `'Tambahan Self-Service'` sekarang muncul di datalist autocomplete kategori halaman Layanan. Task 2/3/4 mengasumsikan kedua string literal ini persis (sebelum normalisasi).

- [ ] **Step 1: Tambah 2 kategori ke rekomendasi**

Di `layanan.php:812`, ubah:

```php
const KAT_REKOMENDASI = ['Kiloan','Satuan','Express','Setrika','Cuci Kering','Dry Clean','Khusus','Sepatu','Bedcover & Selimut','Karpet & Gorden','B2B / Korporat'];
```

menjadi:

```php
const KAT_REKOMENDASI = ['Kiloan','Satuan','Express','Setrika','Cuci Kering','Dry Clean','Khusus','Sepatu','Bedcover & Selimut','Karpet & Gorden','B2B / Korporat','Self-Service','Tambahan Self-Service'];
```

- [ ] **Step 2: Verifikasi tampil di UI**

Buka halaman Layanan (`/layanan`) di browser, klik "+ Tambah Layanan", cek dropdown/datalist kategori — pastikan "Self-Service" dan "Tambahan Self-Service" muncul sebagai opsi (bisa diketik & autocomplete).

- [ ] **Step 3: Migrasi kategori layanan existing Harpy Laundry**

Jalankan sekali via `php -r` (pakai koneksi DB yang sudah dikonfigurasi di `master/config/db.php`) — update layanan self-service existing tenant 18/outlet 13 dari kategori "Khusus" ke "Self-Service":

```bash
php -r '
require "/Users/rizky/Documents/lamasy/master/config/db.php";
require "/Users/rizky/Documents/lamasy/core/Database.php";
$db = Database::get();
$stmt = $db->prepare("UPDATE hl_layanan SET kategori=? WHERE tenant_id=18 AND outlet_id=13 AND kategori=? AND LOWER(nama) LIKE ?");
$n = $stmt->execute(["Self-Service", "Khusus", "%selfservice%"]);
echo "rows updated: " . $stmt->rowCount() . "\n";
'
```

Expected output: `rows updated: 2` (Pencucian SelfService, Pengeringan SelfService).

- [ ] **Step 4: Verifikasi migrasi**

```bash
php -r '
require "/Users/rizky/Documents/lamasy/master/config/db.php";
require "/Users/rizky/Documents/lamasy/core/Database.php";
$db = Database::get();
$rows = $db->query("SELECT id, nama, kategori FROM hl_layanan WHERE tenant_id=18 AND outlet_id=13 AND kategori=\"Self-Service\"")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
'
```

Expected: 2 baris — "Pencucian SelfService" dan "Pengeringan SelfService", `kategori` = `Self-Service`.

- [ ] **Step 5: Commit**

```bash
git add layanan.php
git commit -m "feat(layanan): tambah kategori Self-Service & Tambahan Self-Service ke rekomendasi"
```

(Migrasi data di Step 3 tidak menghasilkan file untuk di-commit — cukup dicatat di laporan task bahwa migrasi sudah dijalankan.)

---

### Task 2: Backend — deteksi self-service & No. HP opsional

**Files:**
- Modify: `pos.php:27-29` (tambah helper function)
- Modify: `pos.php:259-260` (validasi kondisional)

**Interfaces:**
- Consumes: `$items` array (sudah ada di `pos.php:224`, `$items = $data['items'] ?? []`), tiap elemen punya key `layanan_id` (sudah dipakai di `pos.php:273`).
- Produces: function `lmNormKat(string $s): string` dan `lmIsSelfServiceOrder(array $items, int $tid): bool` — dipakai HANYA di task ini (self-contained di `pos.php`, tidak diekspos ke file lain).

- [ ] **Step 1: Tambah helper function**

Di `pos.php`, setelah baris 27 (`$oid = TenantResolver::outletId();`) dan sebelum baris 29 (`if ($action === 'get_layanan')`), sisipkan:

```php
    // Normalisasi kategori layanan (lowercase, strip non-alfanumerik) — dipakai
    // buat cocokkan kategori "Self-Service"/"self service"/dst secara toleran.
    // Salinan JS-nya: fungsi lmNormKat() di inline <script> bawah halaman.
    function lmNormKat(string $s): string {
        return preg_replace('/[^a-z0-9]/', '', strtolower($s));
    }

    // Order dianggap self-service kalau MINIMAL 1 item di keranjang layanan_id-nya
    // merujuk ke hl_layanan berkategori "Self-Service" (normalized match).
    function lmIsSelfServiceOrder(array $items, int $tid): bool {
        $db = Database::get();
        $stmt = $db->prepare("SELECT kategori FROM hl_layanan WHERE id=? AND tenant_id=? LIMIT 1");
        foreach ($items as $item) {
            $lid = (int)($item['layanan_id'] ?? 0);
            if ($lid <= 0) continue;
            $stmt->execute([$lid, $tid]);
            $kat = $stmt->fetchColumn();
            if ($kat && lmNormKat($kat) === 'selfservice') return true;
        }
        return false;
    }

```

- [ ] **Step 2: Pakai di validasi telepon**

Di `pos.php:259-260`, ubah:

```php
        if (!$nama_pel) { echo json_encode(['error'=>'Nama pelanggan wajib diisi']); exit; }
        if (!$telepon)  { echo json_encode(['error'=>'Nomor telepon wajib diisi']); exit; }
```

menjadi:

```php
        if (!$nama_pel) { echo json_encode(['error'=>'Nama pelanggan wajib diisi']); exit; }
        if (!$telepon && !lmIsSelfServiceOrder($items, $tid)) {
            echo json_encode(['error'=>'Nomor telepon wajib diisi']); exit;
        }
```

- [ ] **Step 3: Test isolasi — `lmIsSelfServiceOrder()` benar mendeteksi kategori**

Fungsi baru ini query DB langsung (bukan HTTP), jadi bisa ditest via PHP CLI tanpa login/CSRF. Pastikan dulu Task 1 sudah jalan (ada layanan tenant 18/outlet 13 berkategori persis "Self-Service" dan minimal 1 layanan lain berkategori BUKAN itu, mis. "Reguler" — cek dengan `SELECT id, nama, kategori FROM hl_layanan WHERE tenant_id=18 AND outlet_id=13 LIMIT 10`), lalu jalankan (ganti `ID_SELFSERVICE` dan `ID_REGULER` dengan id asli hasil query tadi):

```bash
php -r '
define("ROOT", "/Users/rizky/Documents/lamasy");
require ROOT."/master/config/db.php";
require ROOT."/core/Database.php";
function lmNormKat(string $s): string { return preg_replace("/[^a-z0-9]/", "", strtolower($s)); }
function lmIsSelfServiceOrder(array $items, int $tid): bool {
    $db = Database::get();
    $stmt = $db->prepare("SELECT kategori FROM hl_layanan WHERE id=? AND tenant_id=? LIMIT 1");
    foreach ($items as $item) {
        $lid = (int)($item["layanan_id"] ?? 0);
        if ($lid <= 0) continue;
        $stmt->execute([$lid, $tid]);
        $kat = $stmt->fetchColumn();
        if ($kat && lmNormKat($kat) === "selfservice") return true;
    }
    return false;
}
$tid = 18;
$selfServiceId = ID_SELFSERVICE; // ganti dgn id layanan kategori "Self-Service" asli
$regulerId     = ID_REGULER;     // ganti dgn id layanan kategori lain (bukan Self-Service) asli
var_dump(lmIsSelfServiceOrder([["layanan_id"=>$selfServiceId]], $tid)); // harus true
var_dump(lmIsSelfServiceOrder([["layanan_id"=>$regulerId]], $tid));     // harus false
var_dump(lmIsSelfServiceOrder([["layanan_id"=>$regulerId],["layanan_id"=>$selfServiceId]], $tid)); // harus true (campuran, minimal 1 cukup)
'
```

Expected: `bool(true)`, `bool(false)`, `bool(true)`.

- [ ] **Step 4: Test manual end-to-end (lewat browser, bukan curl)**

Backend action `save` butuh session login + CSRF token asli (bukan sesuatu yang praktis disimulasikan lewat curl tanpa kredensial live) — jadi verifikasi end-to-end jalur lengkap (server+client sekaligus) dilakukan di Task 3 Step 5 setelah frontend juga siap, lewat form POS beneran di browser. Task ini (Task 2) cukup dianggap selesai kalau Step 3 di atas lulus (logika inti-nya benar secara isolasi) — Step 5 di Task 3 adalah pembuktian akhirnya.

- [ ] **Step 5: Commit**

```bash
git add pos.php
git commit -m "feat(pos): No. HP opsional utk order self-service (server-side, defense-in-depth)"
```

---

### Task 3: Frontend — tracking kategori item & validasi kondisional

**Files:**
- Modify: `pos.php:1892-1919` (`addLayananItem`, 2 titik `items.push` — lihat Step 1)
- Modify: `pos.php:2577-2620` (`saveTransaksi`, `doSaveTransaksi`)
- Modify: `pos.php` di dekat fungsi-fungsi JS lain (tambah helper baru — lihat Step 2)

Catatan: ada satu `items.push(...)` LAIN di `pos.php` (sekitar baris 1977, dipanggil saat staf klik "+ Baris Manual" — item kosong tanpa `layanan_id`, murni ketik manual). Itu **SENGAJA TIDAK disentuh** di task ini — item manual tidak berasal dari `layananAll`, jadi tidak ada `kategori` sumber buat diambil (field `kategori` item itu otomatis `undefined`, dan `isSelfServiceKat(undefined)` akan return `false` — perilaku yang benar, baris manual tidak pernah dianggap self-service).

**Interfaces:**
- Consumes: `layananAll` (array global JS, sudah ada di baris 1766/1867, tiap elemen punya field `kategori` dari `SELECT *` server — sudah tersedia, tidak perlu ubah endpoint `get_layanan`).
- Produces: fungsi JS `lmNormKat(s)`, `isSelfServiceKat(kat)`, `isAddonKat(kat)`, `cartHasSelfService()` — dipakai Task 4. Item object sekarang punya field `kategori` (dipakai Task 4 buat deteksi baris mana yang tampilkan tombol addon).

- [ ] **Step 1: Tambah field `kategori` ke item saat ditambahkan**

Di `pos.php`, cari 3 baris `items.push({layanan_id:id,nama_layanan:nama,satuan,jumlah:defaultJml,harga_satuan:harga,catatan_item:'',express_tier_nama:null,biaya_express:0,qty_minimum:qMin,estimasi_jam:estJam});` (baris ~1911 dan ~1916, di dalam `addLayananItem`), ubah keduanya jadi:

```js
items.push({layanan_id:id,nama_layanan:nama,satuan,jumlah:defaultJml,harga_satuan:harga,catatan_item:'',express_tier_nama:null,biaya_express:0,qty_minimum:qMin,estimasi_jam:estJam,kategori:lyn.kategori||''});
```

(`lyn` sudah ada di scope — didefinisikan di baris awal `addLayananItem`: `const lyn = (layananAll || []).find(l => l.id == id) || {};`)

- [ ] **Step 2: Tambah helper JS**

Cari fungsi `lmCleanNum` di `pos.php` (ditambahkan sesi sebelumnya, dekat `grpRibu`). Tepat setelah definisi `lmCleanNum`, sisipkan:

```js
// Deteksi kategori Self-Service / Tambahan Self-Service — normalize toleran
// (samakan persis dgn lmNormKat() versi PHP di action=save).
function lmNormKat(s){ return String(s||'').toLowerCase().replace(/[^a-z0-9]/g,''); }
function isSelfServiceKat(kat){ return lmNormKat(kat) === 'selfservice'; }
function isAddonKat(kat){ return lmNormKat(kat) === 'tambahanselfservice'; }
function cartHasSelfService(){ return items.some(i => isSelfServiceKat(i.kategori)); }
```

- [ ] **Step 3: Validasi kondisional di `saveTransaksi()`**

Di `pos.php:2580-2581`, ubah:

```js
  if (!nama) { showToast('⚠️ Nama pelanggan wajib diisi', 'error'); return; }
  if (!telp) { showToast('⚠️ Nomor HP wajib diisi', 'error'); return; }
```

menjadi:

```js
  if (!nama) { showToast('⚠️ Nama pelanggan wajib diisi', 'error'); return; }
  if (!telp && !cartHasSelfService()) { showToast('⚠️ Nomor HP wajib diisi', 'error'); return; }
```

- [ ] **Step 4: Validasi kondisional di `doSaveTransaksi()`**

Di `pos.php:2616`, ubah:

```js
  if (!nama || !telp || !items.length) return;
```

menjadi:

```js
  if (!nama || (!telp && !cartHasSelfService()) || !items.length) return;
```

- [ ] **Step 5: Test manual — validasi client-side**

Di browser (`/pos`): tambah item layanan kategori "Self-Service" (mis. "Pencucian SelfService") ke keranjang, isi Nama, KOSONGKAN No. Telepon, klik Simpan — harus lanjut ke modal konfirmasi (tidak muncul toast error "Nomor HP wajib diisi"). Hapus item self-service dari keranjang, tambah layanan biasa (mis. Reguler), coba Simpan tanpa telepon lagi — harus muncul toast error seperti sebelumnya (validasi lama tidak rusak).

- [ ] **Step 6: Commit**

```bash
git add pos.php
git commit -m "feat(pos): tracking kategori item + No. HP opsional (client-side) utk order self-service"
```

---

### Task 4: Tombol saran add-on + indikator No. HP opsional

**Files:**
- Modify: `pos.php:1298` (label No. Telepon)
- Modify: `pos.php:1996-2037` (`renderItems`)
- Modify: `pos.php` CSS (dekat baris 942, `.layanan-btn`)

**Interfaces:**
- Consumes: `isSelfServiceKat`, `isAddonKat`, `cartHasSelfService` (Task 3), `layananAll` (sudah ada), `addLayananItem(id, nama, satuan, harga)` (sudah ada, di-reuse tanpa perubahan).
- Produces: fungsi JS `renderAddonRow(i)`, `updateTeleponOptionalUI()` — tidak dikonsumsi task lain (ini task terakhir).

- [ ] **Step 1: Tandai label No. Telepon supaya bisa di-toggle**

Di `pos.php:1297-1298`, ubah:

```html
              <label>No. Telepon <span class="req">*</span></label>
              <input type="tel" id="f_telepon" placeholder="08xxxxxxxxxx"/>
```

menjadi:

```html
              <label>No. Telepon <span class="req" id="f_telepon_req">*</span></label>
              <input type="tel" id="f_telepon" placeholder="08xxxxxxxxxx"/>
```

- [ ] **Step 2: Tambah CSS tombol addon**

Di `pos.php`, dekat baris 942 (`.layanan-btn{...}`), tambahkan:

```css
.addon-row td{padding:4px 8px 10px !important;background:transparent}
.addon-btn{display:inline-block;margin:2px 6px 2px 0;padding:5px 10px;font-size:11.5px;font-weight:600;
  border:1.5px dashed var(--teal-d);border-radius:100px;background:rgba(53,232,213,.08);color:var(--teal-d);
  cursor:pointer;font-family:var(--font)}
.addon-btn:disabled{opacity:.4;cursor:default;text-decoration:line-through}
.req.opt{color:var(--gray);font-weight:400;font-size:11px}
```

- [ ] **Step 3: Tambah fungsi render addon row + update label opsional**

Di `pos.php`, setelah fungsi `renderItems()` (setelah baris `}` penutup, sebelum komentar `// ── Express Tier ...`), tambahkan:

```js
function renderAddonRow(i) {
  const addons = (layananAll || []).filter(l => isAddonKat(l.kategori));
  if (!addons.length) return '';
  const btns = addons.map(a => {
    const already = items.some(it => it.layanan_id == a.id);
    return `<button type="button" class="addon-btn" ${already ? 'disabled' : ''}
      onclick="addLayananItem(${a.id},'${esc(a.nama)}','${a.satuan}',${a.harga})">
      + ${esc(a.nama)} Rp${Math.round(a.harga).toLocaleString('id-ID')}</button>`;
  }).join('');
  return `<tr class="addon-row"><td colspan="8">${btns}</td></tr>`;
}

function updateTeleponOptionalUI(){
  const req = document.getElementById('f_telepon_req');
  if (!req) return;
  if (cartHasSelfService()) { req.textContent = '(opsional)'; req.classList.add('opt'); }
  else { req.textContent = '*'; req.classList.remove('opt'); }
}
```

- [ ] **Step 4: Panggil render addon row di dalam `renderItems()` + panggil `updateTeleponOptionalUI()`**

Di `pos.php`, fungsi `renderItems()` (baris ~1996-2037), ubah:

```js
  empty.style.display = 'none';
  document.getElementById('btnSave').disabled = false;
  tbody.innerHTML = items.map((item, i) => `
    <tr>
```

menjadi (tambah `updateTeleponOptionalUI()` di awal & akhir + bungkus map callback jadi return gabungan tr+addon-row):

```js
  empty.style.display = 'none';
  document.getElementById('btnSave').disabled = false;
  tbody.innerHTML = items.map((item, i) => `
    <tr>
```

... (isi tengah `<tr>...</tr>` TETAP SAMA PERSIS, tidak berubah) ...

lalu di baris penutup fungsi (baris ~2036-2037), ubah:

```js
      <td><button class="btn-remove" onclick="removeItem(${i})">✕ Hapus</button></td>
    </tr>`).join('');
}
```

menjadi:

```js
      <td><button class="btn-remove" onclick="removeItem(${i})">✕ Hapus</button></td>
    </tr>${isSelfServiceKat(item.kategori) ? renderAddonRow(i) : ''}`).join('');
  updateTeleponOptionalUI();
}
```

Juga tambahkan `updateTeleponOptionalUI();` tepat sebelum `return;` di early-return block-nya (baris ~1999-2004, kondisi `if (!items.length)`):

```js
  if (!items.length) {
    tbody.innerHTML = '';
    empty.style.display = 'block';
    document.getElementById('btnSave').disabled = true;
    updateTeleponOptionalUI();
    return;
  }
```

- [ ] **Step 5: Test manual — tombol addon**

Buka `/pos`, tambah layanan kategori Self-Service ke keranjang — di bawah baris item itu harus muncul tombol dashed (`+ Sabun Rp3.000` dst, sesuai layanan berkategori "Tambahan Self-Service" yang sudah di-setup owner di Task 1/halaman Layanan — kalau belum ada layanan addon yang di-setup, strip tombol TIDAK muncul, itu perilaku yang benar). Tap salah satu tombol → item addon masuk keranjang sbg baris baru, tombolnya jadi disabled (coret). Cek label "No. Telepon" berubah dari `*` merah jadi `(opsional)` abu-abu begitu item self-service ada di keranjang, dan balik jadi `*` kalau item itu dihapus dari keranjang.

- [ ] **Step 6: Commit**

```bash
git add pos.php
git commit -m "feat(pos): tombol saran add-on self-service (tap-to-add) + indikator No. HP opsional"
```

---

## Deploy

Setiap task di-commit ke `main` dan di-push (`git push origin main`) segera setelah lulus test manual-nya — auto-deploy Hostinger aktif di push ke `main` (bukan menunggu semua 4 task selesai baru push sekaligus), supaya kalau ada masalah di satu task gampang dilacak commit mana penyebabnya.

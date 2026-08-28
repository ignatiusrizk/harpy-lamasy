# Biaya Lainnya Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Owner bisa menambahkan 1 biaya bebas (label + nominal, bebas apa
saja) per order — di POS saat buat order baru, dan di Orders saat edit order
yang sudah ada — plus perbaiki bug lama: `biaya_tambahan` (express fee)
hilang dari total saat order diedit.

**Architecture:** 2 kolom baru di `hl_transaksi` (`biaya_lainnya`,
`biaya_lainnya_label`). Field manual, TIDAK di-recompute/anti-tamper server
(sekelas `diskon`, bukan sekelas `biaya_tambahan` yang di-derive dari
tier). Masuk sejajar `biaya_tambahan` di rumus total di 2 tempat
(`pos.php` create, `orders.php` update) dan di 2 renderer struk
(`renderThermal`, `renderPdf`).

**Tech Stack:** PHP procedural (tanpa framework), MySQL, vanilla JS. Tidak
ada PHPUnit di repo ini — verifikasi lewat `php -l` + skrip PHP CLI
sekali-pakai yang mensimulasikan rumus (pola yang sama dipakai sesi
sebelumnya utk pos.php/orders.php, karena rumus totalnya inline, bukan
fungsi murni yang bisa di-`require` terpisah).

## Global Constraints

- **1 baris biaya per order** — 1 kolom `biaya_lainnya` (nominal) + 1 kolom
  `biaya_lainnya_label` (teks bebas), BUKAN tabel/list terpisah.
- Label BEBAS ketik apa saja, TIDAK ada daftar preset yang dikonfigurasi di
  settings.
- Rumus total di SEMUA tempat: `total = max(0, subtotal − diskon − redeem −
  member_diskon + biaya_tambahan + biaya_lainnya)` — `biaya_lainnya`
  SEJAJAR `biaya_tambahan`, sama-sama komponen PENAMBAH, ditambahkan
  setelah semua pengurang.
- `biaya_lainnya` kalau tak dikirim di request `orders.php action=update`
  (mis. request yang cuma ubah status), WAJIB fallback ke nilai lama
  (`$oldRow`) — JANGAN ke-reset ke 0 diam-diam.
- `biaya_tambahan` di `orders.php` (edit order) SELALU dipertahankan dari
  `$oldRow` (snapshot saat order dibuat) — TIDAK di-recompute dari item,
  karena edit order tidak mengelola ulang express tier sama sekali.
- Kalau `biaya_lainnya > 0` tapi `biaya_lainnya_label` kosong/null: semua
  tempat yang menampilkan WAJIB fallback ke teks **"Biaya Lainnya"**.
- Dashboard/laporan lain TIDAK disentuh — semua sudah baca kolom `total`.

---

### Task 1: Migrasi DB

**Files:**
- Create: `migrations/2026-08-28-biaya-lainnya.sql`

**Interfaces:**
- Produces: kolom `hl_transaksi.biaya_lainnya` (DECIMAL(12,2) NOT NULL
  DEFAULT 0) dan `hl_transaksi.biaya_lainnya_label` (VARCHAR(100) NULL) —
  dipakai Task 2-5.

- [ ] **Step 1: Tulis file migrasi**

```sql
-- migrations/2026-08-28-biaya-lainnya.sql
-- Biaya tambahan bebas (label+nominal manual per order), terpisah dari
-- biaya_tambahan (express/antar-jemput yang sudah ada & auto-derive tier).
ALTER TABLE hl_transaksi
  ADD COLUMN biaya_lainnya DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER biaya_tambahan,
  ADD COLUMN biaya_lainnya_label VARCHAR(100) NULL DEFAULT NULL AFTER biaya_lainnya;
```

- [ ] **Step 2: Jalankan migrasi**

Run: `mysql < migrations/2026-08-28-biaya-lainnya.sql`

Expected: tidak ada output (sukses).

- [ ] **Step 3: Verifikasi kolom masuk**

Run: `mysql -e "SHOW COLUMNS FROM hl_transaksi LIKE 'biaya_lainnya%'"`

Expected:
```
Field                  Type          Null  Key  Default  Extra
biaya_lainnya          decimal(12,2) NO         0.00
biaya_lainnya_label    varchar(100)  YES        NULL
```

- [ ] **Step 4: Commit**

```bash
git add migrations/2026-08-28-biaya-lainnya.sql
git commit -m "db: tambah kolom biaya_lainnya + biaya_lainnya_label di hl_transaksi"
```

---

### Task 2: POS — input & simpan Biaya Lainnya saat buat order baru

**Files:**
- Modify: `pos.php`

**Interfaces:**
- Consumes: kolom `biaya_lainnya`/`biaya_lainnya_label` dari Task 1.
- Produces: order baru yang dibuat dari POS punya `biaya_lainnya` &
  `biaya_lainnya_label` tersimpan, sudah masuk ke `total` — dikonsumsi
  Task 4 (struk) & Task 5 (export) via kolom DB yang sama.

- [ ] **Step 1: Tambah field input di form (HTML)**

Cari blok box "Biaya Tambahan" (sekitar baris 1512-1518):

```php
            <!-- Biaya Tambahan now auto-derived dari per-item tier (read-only display) -->
            <div class="form-group" id="biayaTambahanBox" style="display:none;background:#FEF3C7;border:1px solid #FDE68A;border-radius:8px;padding:10px 14px;margin-bottom:8px;font-size:12.5px;color:#92400E;">
              ⚡ <strong>Total Biaya Express:</strong> Rp <span id="biayaTotalDisplay">0</span>
              <span style="display:block;font-size:11px;color:#A16207;margin-top:2px;">Otomatis dari pilihan tier di tiap baris item</span>
              <input type="hidden" id="f_biaya_tambahan" value="0"/>
              <input type="hidden" id="f_tipe_order" value="reguler"/>
            </div>
```

Tambah section BARU tepat setelahnya (field manual, terpisah dari box
Express yang read-only supaya tidak membingungkan owner mana yang
otomatis mana yang manual):

```php
            <!-- Biaya Tambahan now auto-derived dari per-item tier (read-only display) -->
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

- [ ] **Step 2: Masukkan ke rumus total di JS `recalc()`**

Cari baris (sekitar baris 2131-2132):

```javascript
  // Total = subtotal − diskon − redeem + biaya tambahan
  const total    = Math.max(subtotal - diskon - redeemValue + biayaTbh, 0);
```

Ganti jadi:

```javascript
  // Total = subtotal − diskon − redeem + biaya tambahan + biaya lainnya
  const biayaLainnya = parseFloat(document.getElementById('f_biaya_lainnya')?.value) || 0;
  const total    = Math.max(subtotal - diskon - redeemValue + biayaTbh + biayaLainnya, 0);
```

- [ ] **Step 3: Kirim field baru di payload `submitCreate`**

Cari blok `payload` sebelum `fetch('pos.php?action=save')` (sekitar baris
2613-2636), tambah 2 field setelah `tipe_order`:

```javascript
    diskon:         document.getElementById('f_diskon').value,
    biaya_tambahan: document.getElementById('f_biaya_tambahan').value,
    tipe_order:     document.getElementById('f_tipe_order').value,
    biaya_lainnya:       document.getElementById('f_biaya_lainnya').value,
    biaya_lainnya_label: document.getElementById('f_biaya_lainnya_label').value,
```

- [ ] **Step 4: Baca & validasi di server (action `save`)**

Cari blok perhitungan total (sekitar baris 453-455):

```php
            // Total final (subtotal − diskon − member_diskon + biaya tambahan)
            $diskonTotal = $diskon + $redeemValue + $memberDiskon;
            $total    = max(0, $subtotal - $diskonTotal + $biayaTbh);
```

Ganti jadi (baca & sanitasi biaya lainnya SEBELUM baris ini, mengikuti
pola `$diskon` yang sudah ada — `floatval`, TIDAK di-recompute dari item
krn ini memang input manual bebas, bukan hasil kalkulasi):

```php
            // Biaya Lainnya — manual bebas, TIDAK di-recompute server (beda dgn
            // biaya_tambahan yg wajib re-derive dari tier demi anti-tamper).
            $biayaLainnya      = max(0, floatval($data['biaya_lainnya'] ?? 0));
            $biayaLainnyaLabel = substr(trim(strip_tags($data['biaya_lainnya_label'] ?? '')), 0, 100);

            // Total final (subtotal − diskon − member_diskon + biaya tambahan + biaya lainnya)
            $diskonTotal = $diskon + $redeemValue + $memberDiskon;
            $total    = max(0, $subtotal - $diskonTotal + $biayaTbh + $biayaLainnya);
```

- [ ] **Step 5: Simpan ke kolom baru (INSERT dinamis)**

Cari blok cek kolom opsional & INSERT (sekitar baris 474-536), tambah
pengecekan kolom baru mengikuti pola `$hasBiayaTipe` yang sudah ada:

```php
            // Cek apakah kolom biaya_tambahan & tipe_order sudah ada (migration applied?)
            $hasBiayaTipe = true;
            try { $db->query("SELECT biaya_tambahan, tipe_order FROM hl_transaksi LIMIT 1"); }
            catch (Throwable) { $hasBiayaTipe = false; }
```

Tambah setelahnya:

```php
            $hasBiayaLainnya = true;
            try { $db->query("SELECT biaya_lainnya, biaya_lainnya_label FROM hl_transaksi LIMIT 1"); }
            catch (Throwable) { $hasBiayaLainnya = false; }
```

Cari baris penambahan kolom dinamis (sekitar baris 533-536):

```php
            if ($hasEstJam)    { $cols[] = 'estimasi_jam';   $vals[] = $estimasiJam; }
            if ($hasBiayaTipe) { $cols[] = 'biaya_tambahan'; $vals[] = $biayaTbh;
                                 $cols[] = 'tipe_order';     $vals[] = $tipeOrder; }
```

Tambah setelahnya:

```php
            if ($hasBiayaLainnya && $biayaLainnya > 0) {
                $cols[] = 'biaya_lainnya';       $vals[] = $biayaLainnya;
                $cols[] = 'biaya_lainnya_label'; $vals[] = $biayaLainnyaLabel ?: null;
            }
```

(Guard `$biayaLainnya > 0` — kalau owner tidak isi apa-apa, biarkan kolom
pakai DEFAULT 0/NULL dari migrasi, tidak perlu eksplisit di INSERT.)

- [ ] **Step 6: Verifikasi manual end-to-end via PHP CLI**

Simulasikan langsung rumus PHP (tanpa perlu HTTP/session) utk pastikan
angka benar:

```bash
php -r '
$subtotal = 100000; $diskon = 10000; $redeemValue = 0; $memberDiskon = 0;
$biayaTbh = 15000; $biayaLainnya = 5000;
$diskonTotal = $diskon + $redeemValue + $memberDiskon;
$total = max(0, $subtotal - $diskonTotal + $biayaTbh + $biayaLainnya);
echo "Total: $total (expected 110000)\n";
'
```

Expected: `Total: 110000 (expected 110000)`.

Lalu `php -l pos.php` → harus "No syntax errors detected".

- [ ] **Step 7: Commit**

```bash
git add pos.php
git commit -m "feat(pos): tambah field Biaya Lainnya (manual, bebas) saat buat order baru"
```

---

### Task 3: Orders — edit Biaya Lainnya + bugfix biaya_tambahan hilang saat edit

**Files:**
- Modify: `orders.php`

**Interfaces:**
- Consumes: kolom `biaya_lainnya`/`biaya_lainnya_label` dari Task 1.
- Produces: order yang sudah ada bisa diedit biaya lainnya-nya, DAN
  `biaya_tambahan` tidak lagi hilang saat item diedit.

- [ ] **Step 1: Tambah kolom ke SELECT `$oldRow`**

Cari (sekitar baris 229-234):

```php
            $oldRow = $db->prepare("SELECT status_proses,status_bayar,catatan,dp,total,diskon,
                                           pelanggan_id,telepon,no_order,nama_pelanggan
                                      FROM hl_transaksi
                                     WHERE tenant_id=? AND outlet_id=? AND id=? FOR UPDATE");
```

Ganti jadi:

```php
            $oldRow = $db->prepare("SELECT status_proses,status_bayar,catatan,dp,total,diskon,
                                           pelanggan_id,telepon,no_order,nama_pelanggan,
                                           biaya_tambahan,biaya_lainnya,biaya_lainnya_label
                                      FROM hl_transaksi
                                     WHERE tenant_id=? AND outlet_id=? AND id=? FOR UPDATE");
```

- [ ] **Step 2: Baca & fallback Biaya Lainnya + preserve Biaya Tambahan**

Cari blok recalc total (sekitar baris 254-266):

```php
            // Recalc total jika ada items baru
            $subtotal = 0;
            if (!empty($data['items'])) {
                foreach ($data['items'] as $item) {
                    $subtotal += floatval($item['jumlah']) * floatval($item['harga_satuan']);
                }
            }

            $diskon = floatval($data['diskon'] ?? 0);
            $total  = $subtotal > 0 ? ($subtotal - $diskon) : floatval($data['total'] ?? 0);
            $dp     = floatval($data['dp'] ?? 0);
            $sisa   = $total - $dp;
            $sbayar = $dp >= $total && $total > 0 ? 'lunas' : ($dp > 0 ? 'dp' : 'belum_bayar');
```

Ganti jadi (bugfix `biaya_tambahan` + fitur `biaya_lainnya` dalam 1
perubahan rumus, karena baris yang sama):

```php
            // Recalc total jika ada items baru
            $subtotal = 0;
            if (!empty($data['items'])) {
                foreach ($data['items'] as $item) {
                    $subtotal += floatval($item['jumlah']) * floatval($item['harga_satuan']);
                }
            }

            // biaya_tambahan = snapshot dari saat order dibuat, TIDAK di-recompute
            // di sini (edit order tidak mengelola ulang express tier). BUGFIX: dulu
            // rumus di bawah cuma "subtotal - diskon", biaya_tambahan ikut hilang
            // dari total setiap kali item order diedit.
            $biayaTambahanLama = (float)($oldRow['biaya_tambahan'] ?? 0);

            // biaya_lainnya — manual bebas, kalau request ini TIDAK mengirim field-nya
            // (mis. request yang cuma ubah status_proses) pertahankan nilai lama,
            // JANGAN reset ke 0 diam-diam.
            $biayaLainnya = array_key_exists('biaya_lainnya', $data)
                ? max(0, floatval($data['biaya_lainnya']))
                : (float)($oldRow['biaya_lainnya'] ?? 0);
            $biayaLainnyaLabel = array_key_exists('biaya_lainnya_label', $data)
                ? substr(trim(strip_tags($data['biaya_lainnya_label'] ?? '')), 0, 100)
                : (string)($oldRow['biaya_lainnya_label'] ?? '');

            $diskon = floatval($data['diskon'] ?? 0);
            $total  = $subtotal > 0
                ? max(0, $subtotal - $diskon + $biayaTambahanLama + $biayaLainnya)
                : max(0, floatval($data['total'] ?? 0));
            $dp     = floatval($data['dp'] ?? 0);
            $sisa   = $total - $dp;
            $sbayar = $dp >= $total && $total > 0 ? 'lunas' : ($dp > 0 ? 'dp' : 'belum_bayar');
```

(Cabang `floatval($data['total'] ?? 0)` — dipakai kalau `$data['items']`
kosong — TIDAK diubah, krn di jalur UI yang ada `items` SELALU dikirim
[lihat `saveEdit()` Step 4 di bawah], cabang ini murni fallback defensif
yang sudah ada sebelum fitur ini dan di luar scope untuk diutak-atik lebih
jauh.)

- [ ] **Step 3: Tambah ke `UPDATE hl_transaksi SET ...`**

Cari (sekitar baris 311-322):

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

Ganti jadi:

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

- [ ] **Step 4: Tambah baris input di form edit modal (HTML)**

Cari (sekitar baris 1958-1963):

```javascript
    <div class="total-box" id="editTotalBox">
      <div class="tb-row"><span class="tb-label">Subtotal</span><span class="tb-value" id="etSubtotal">-</span></div>
      <div class="tb-row"><span class="tb-label">Diskon</span><span class="tb-value">- Rp ${CAN_EDIT_ORDER ? `<input type="number" id="edit_diskon" value="${Math.round(d.diskon||0)}" min="0" step="500" oninput="recalcEdit()" style="width:80px;background:transparent;border:none;border-bottom:1px solid rgba(255,255,255,.3);color:white;font-family:var(--mono);font-size:13px;padding:0;outline:none"/>` : `<span id="edit_diskon" style="font-family:var(--mono);color:white">${grpRibu(d.diskon||0)}</span>`}</span></div>
      <div class="tb-row tb-total"><span style="color:white;font-weight:700">TOTAL</span><span class="tb-value tb-big" id="etTotal">-</span></div>
      <div class="tb-row"><span class="tb-label">DP/Bayar</span><span class="tb-value">Rp ${CAN_EDIT_ORDER ? `<input class="lm-rp" type="number" id="edit_dp" value="${Math.round(d.dp||0)}" min="0" step="1000" oninput="recalcEdit()" style="width:90px;background:transparent;border:none;border-bottom:1px solid rgba(255,255,255,.3);color:white;font-family:var(--mono);font-size:13px;padding:0;outline:none"/>` : `<span id="edit_dp" style="font-family:var(--mono);color:white">${grpRibu(d.dp||0)}</span>`}</span></div>
      <div class="tb-row"><span class="tb-label">Sisa Bayar</span><span class="tb-value tb-sisa" id="etSisa">-</span></div>
    </div>
```

Ganti jadi (baris baru "Biaya Lainnya" disisipkan setelah Diskon, sebelum
TOTAL — 2 input kecil: label teks + nominal):

```javascript
    <div class="total-box" id="editTotalBox">
      <div class="tb-row"><span class="tb-label">Subtotal</span><span class="tb-value" id="etSubtotal">-</span></div>
      <div class="tb-row"><span class="tb-label">Diskon</span><span class="tb-value">- Rp ${CAN_EDIT_ORDER ? `<input type="number" id="edit_diskon" value="${Math.round(d.diskon||0)}" min="0" step="500" oninput="recalcEdit()" style="width:80px;background:transparent;border:none;border-bottom:1px solid rgba(255,255,255,.3);color:white;font-family:var(--mono);font-size:13px;padding:0;outline:none"/>` : `<span id="edit_diskon" style="font-family:var(--mono);color:white">${grpRibu(d.diskon||0)}</span>`}</span></div>
      <div class="tb-row"><span class="tb-label">Biaya Lainnya</span><span class="tb-value">${CAN_EDIT_ORDER ? `<input type="text" id="edit_biaya_lainnya_label" value="${(d.biaya_lainnya_label||'').replace(/"/g,'&quot;')}" placeholder="label" maxlength="100" oninput="recalcEdit()" style="width:90px;background:transparent;border:none;border-bottom:1px solid rgba(255,255,255,.3);color:white;font-size:12px;padding:0;outline:none;margin-right:4px"/><input type="number" id="edit_biaya_lainnya" value="${Math.round(d.biaya_lainnya||0)}" min="0" step="500" oninput="recalcEdit()" style="width:70px;background:transparent;border:none;border-bottom:1px solid rgba(255,255,255,.3);color:white;font-family:var(--mono);font-size:13px;padding:0;outline:none"/>` : (d.biaya_lainnya > 0 ? `<span style="font-family:var(--mono);color:white">${d.biaya_lainnya_label||'Biaya Lainnya'}: Rp ${grpRibu(d.biaya_lainnya)}</span>` : '<span style="color:rgba(255,255,255,.5)">-</span>')}</span></div>
      <div class="tb-row tb-total"><span style="color:white;font-weight:700">TOTAL</span><span class="tb-value tb-big" id="etTotal">-</span></div>
      <div class="tb-row"><span class="tb-label">DP/Bayar</span><span class="tb-value">Rp ${CAN_EDIT_ORDER ? `<input class="lm-rp" type="number" id="edit_dp" value="${Math.round(d.dp||0)}" min="0" step="1000" oninput="recalcEdit()" style="width:90px;background:transparent;border:none;border-bottom:1px solid rgba(255,255,255,.3);color:white;font-family:var(--mono);font-size:13px;padding:0;outline:none"/>` : `<span id="edit_dp" style="font-family:var(--mono);color:white">${grpRibu(d.dp||0)}</span>`}</span></div>
      <div class="tb-row"><span class="tb-label">Sisa Bayar</span><span class="tb-value tb-sisa" id="etSisa">-</span></div>
    </div>
```

(`d` sudah otomatis berisi `biaya_tambahan`/`biaya_lainnya`/
`biaya_lainnya_label` — endpoint `action=get` pakai `SELECT *`, jadi kolom
baru dari Task 1 otomatis ikut tanpa perlu ubah query itu.)

- [ ] **Step 5: Tambah ke rumus preview `recalcEdit()`**

Cari (sekitar baris 2296-2299):

```javascript
function recalcEdit() {
  const sub  = editItems.reduce((s,i) => s + i.jumlah * i.harga_satuan, 0);
  const dis  = parseFloat(document.getElementById('edit_diskon')?.value) || 0;
  const tot  = Math.max(sub - dis, 0);
```

Ganti jadi (bugfix: `biaya_tambahan` dari order yang sedang dibuka —
disimpan di variabel global `currentOrderBiayaTambahan`, di-set saat modal
dibuka — TIDAK diedit di modal ini, cuma dibaca):

```javascript
function recalcEdit() {
  const sub  = editItems.reduce((s,i) => s + i.jumlah * i.harga_satuan, 0);
  const dis  = parseFloat(document.getElementById('edit_diskon')?.value) || 0;
  const biayaLainnya = parseFloat(document.getElementById('edit_biaya_lainnya')?.value) || 0;
  const tot  = Math.max(sub - dis + (currentOrderBiayaTambahan || 0) + biayaLainnya, 0);
```

Cari baris `let currentEditId = null;` (baris 1576) — tambah deklarasi
baru TEPAT SETELAHNYA:

```javascript
let currentEditId = null;
let currentOrderBiayaTambahan = 0;
```

Lalu di `openDetail(id)` (sekitar baris 1906-1913), tepat setelah baris
`const d = await r.json();`, tambah:

```javascript
  currentOrderBiayaTambahan = parseFloat(d.biaya_tambahan) || 0;
```

- [ ] **Step 6: Kirim field baru di payload `saveEdit()`**

Cari (sekitar baris 2324-2336):

```javascript
  const payload = {
    id:               currentEditId,
    status_proses:    document.getElementById('edit_status_proses').value,
    catatan:          document.getElementById('edit_catatan').value,
    catatan_internal: document.getElementById('edit_catatan_internal').value,
    metode_bayar:     document.getElementById('edit_metode').value,
    diskon:           document.getElementById('edit_diskon').value,
    dp:               document.getElementById('edit_dp').value,
    estimasi:         document.getElementById('edit_estimasi').value,
    foto_pickup:      document.getElementById('edit_foto_pickup_path')?.value || '',
    items:            editItems,
    confirm_resolution: window.__editResolution || null,
  };
```

Ganti jadi:

```javascript
  const payload = {
    id:               currentEditId,
    status_proses:    document.getElementById('edit_status_proses').value,
    catatan:          document.getElementById('edit_catatan').value,
    catatan_internal: document.getElementById('edit_catatan_internal').value,
    metode_bayar:     document.getElementById('edit_metode').value,
    diskon:           document.getElementById('edit_diskon').value,
    dp:               document.getElementById('edit_dp').value,
    estimasi:         document.getElementById('edit_estimasi').value,
    foto_pickup:      document.getElementById('edit_foto_pickup_path')?.value || '',
    biaya_lainnya:       document.getElementById('edit_biaya_lainnya')?.value ?? '0',
    biaya_lainnya_label: document.getElementById('edit_biaya_lainnya_label')?.value ?? '',
    items:            editItems,
    confirm_resolution: window.__editResolution || null,
  };
```

- [ ] **Step 7: Verifikasi manual — simulasi rumus PHP**

```bash
php -r '
// Kasus: order lama biaya_tambahan=15000, biaya_lainnya=0. User edit,
// nambah biaya_lainnya=7000, tidak ubah item (subtotal tetap 100000, diskon 10000).
$oldRow = ["biaya_tambahan"=>15000, "biaya_lainnya"=>0, "biaya_lainnya_label"=>null];
$data = ["biaya_lainnya"=>7000, "biaya_lainnya_label"=>"Parkir", "diskon"=>10000];
$subtotal = 100000;
$biayaTambahanLama = (float)($oldRow["biaya_tambahan"] ?? 0);
$biayaLainnya = array_key_exists("biaya_lainnya",$data) ? max(0,floatval($data["biaya_lainnya"])) : (float)($oldRow["biaya_lainnya"]??0);
$diskon = floatval($data["diskon"] ?? 0);
$total = $subtotal>0 ? max(0,$subtotal-$diskon+$biayaTambahanLama+$biayaLainnya) : 0;
echo "Total: $total (expected 112000 = 100000-10000+15000+7000)\n";

// Kasus fallback: request TIDAK kirim biaya_lainnya (mis. cuma ubah status) → harus pertahankan nilai lama, bukan reset ke 0
$oldRow2 = ["biaya_tambahan"=>0, "biaya_lainnya"=>7000, "biaya_lainnya_label"=>"Parkir"];
$data2 = []; // tak kirim biaya_lainnya sama sekali
$biayaLainnya2 = array_key_exists("biaya_lainnya",$data2) ? max(0,floatval($data2["biaya_lainnya"])) : (float)($oldRow2["biaya_lainnya"]??0);
echo "Biaya lainnya (fallback, harus 7000): $biayaLainnya2\n";
'
```

Expected:
```
Total: 112000 (expected 112000 = 100000-10000+15000+7000)
Biaya lainnya (fallback, harus 7000): 7000
```

Lalu `php -l orders.php` → harus "No syntax errors detected".

- [ ] **Step 8: Commit**

```bash
git add orders.php
git commit -m "feat(orders): edit Biaya Lainnya + bugfix biaya_tambahan hilang saat edit order"
```

---

### Task 4: Struk — tampilkan baris Biaya Lainnya

**Files:**
- Modify: `core/StrukGenerator.php`

**Interfaces:**
- Consumes: kolom `biaya_lainnya`/`biaya_lainnya_label` dari Task 1
  (dibaca via `$trx` array — sudah otomatis ada di semua pemanggil
  `generate()` krn query `SELECT t.*` di method itu).

- [ ] **Step 1: Tambah baris di `renderThermal()` (struk retail)**

Cari (sekitar baris 662-672):

```php
        if ($biayaTbh > 0) {
            // Prioritas: express_tier_nama (snapshot tier) → tipe_order generic
            $tipeLabel = !empty($trx['express_tier_nama'])
                ? 'Biaya ' . $trx['express_tier_nama']
                : match($trx['tipe_order'] ?? 'reguler') {
                    'express' => 'Biaya Express',
                    'kilat'   => 'Biaya Kilat',
                    default   => 'Biaya Tambahan',
                  };
            $h .= self::tRow($tipeLabel, 'Rp ' . self::rpNum($biayaTbh), $maxChar);
        }
```

Tambah setelahnya:

```php
        $biayaLainnya = (float)($trx['biaya_lainnya'] ?? 0);
        if ($biayaLainnya > 0) {
            $lainnyaLabel = trim((string)($trx['biaya_lainnya_label'] ?? '')) ?: 'Biaya Lainnya';
            $h .= self::tRow($lainnyaLabel, 'Rp ' . self::rpNum($biayaLainnya), $maxChar);
        }
```

Lalu ubah baris `$hasBreakdown` (sekitar baris 655) dari:

```php
        $hasBreakdown = (float)($trx['diskon'] ?? 0) > 0 || $biayaTbh > 0;
```

menjadi:

```php
        $hasBreakdown = (float)($trx['diskon'] ?? 0) > 0 || $biayaTbh > 0 || (float)($trx['biaya_lainnya'] ?? 0) > 0;
```

- [ ] **Step 2: Tambah baris di `renderPdf()` (invoice B2B)**

Cari (sekitar baris 1010-1019):

```php
        if ($biayaTbhPdf > 0) {
            $tipeLabel = !empty($trx['express_tier_nama'])
                ? 'Biaya ' . $trx['express_tier_nama']
                : match($trx['tipe_order'] ?? 'reguler') {
                    'express' => 'Biaya Express',
                    'kilat'   => 'Biaya Kilat',
                    default   => 'Biaya Tambahan',
                  };
            $h .= "  <tr><td>" . htmlspecialchars($tipeLabel) . "</td><td class='r'>+Rp " . self::rpNum($biayaTbhPdf) . "</td></tr>\n";
        }
```

Tambah setelahnya:

```php
        $biayaLainnyaPdf = (float)($trx['biaya_lainnya'] ?? 0);
        if ($biayaLainnyaPdf > 0) {
            $lainnyaLabelPdf = trim((string)($trx['biaya_lainnya_label'] ?? '')) ?: 'Biaya Lainnya';
            $h .= "  <tr><td>" . htmlspecialchars($lainnyaLabelPdf) . "</td><td class='r'>+Rp " . self::rpNum($biayaLainnyaPdf) . "</td></tr>\n";
        }
```

Lalu ubah baris `$hasBreakdownPdf` (sekitar baris 1003) dari:

```php
        $hasBreakdownPdf = (float)($trx['diskon'] ?? 0) > 0 || $biayaTbhPdf > 0;
```

menjadi:

```php
        $hasBreakdownPdf = (float)($trx['diskon'] ?? 0) > 0 || $biayaTbhPdf > 0 || (float)($trx['biaya_lainnya'] ?? 0) > 0;
```

- [ ] **Step 3: Tambah kasus baru ke test yang sudah ada**

Tambahkan di akhir `tests/struk/test_payment_aid.php` (sebelum baris
`echo "\nAll tests passed.\n";` — HAPUS baris itu, tambahkan lagi di paling
akhir setelah kasus baru supaya tidak dobel):

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

echo "\nAll tests passed.\n";
```

(Variabel `$trxQris`, `$tmpl`, `$outlet` sudah ada di file test dari fitur
QRIS sebelumnya — dipakai ulang sbg basis, cuma override `metode_bayar` ke
`'cash'` supaya blok QRIS/Rekening tidak ikut muncul dan tidak
mengganggu assertion `str_contains` di atas.)

- [ ] **Step 4: Jalankan test, pastikan semua PASS**

Run: `php tests/struk/test_payment_aid.php`

Expected: semua baris `PASS`, `All tests passed.`, exit code 0.

Lalu `php -l core/StrukGenerator.php` → "No syntax errors detected".

- [ ] **Step 5: Commit**

```bash
git add core/StrukGenerator.php tests/struk/test_payment_aid.php
git commit -m "feat(struk): render baris Biaya Lainnya di renderThermal() & renderPdf()"
```

---

### Task 5: Export CSV — tambah kolom Biaya Lainnya

**Files:**
- Modify: `hq/export.php`

**Interfaces:**
- Consumes: kolom `biaya_lainnya`/`biaya_lainnya_label` dari Task 1.

- [ ] **Step 1: Tambah kolom ke SELECT**

Cari (sekitar baris 125-128):

```php
        $sql = "SELECT id, no_order, tanggal, outlet_id, pelanggan_id, nama_pelanggan, telepon,
                       subtotal, diskon, biaya_tambahan, total, dp, sisa_bayar, metode_bayar,
                       tipe_order, status_bayar, status_proses, estimasi_selesai, catatan,
                       parfum, created_by, created_at, updated_at
                  FROM hl_transaksi
```

Ganti jadi:

```php
        $sql = "SELECT id, no_order, tanggal, outlet_id, pelanggan_id, nama_pelanggan, telepon,
                       subtotal, diskon, biaya_tambahan, biaya_lainnya, biaya_lainnya_label, total,
                       dp, sisa_bayar, metode_bayar,
                       tipe_order, status_bayar, status_proses, estimasi_selesai, catatan,
                       parfum, created_by, created_at, updated_at
                  FROM hl_transaksi
```

(Header CSV otomatis ikut — file ini pakai `fputcsv($out,
array_keys($rows[0]))`, tidak ada daftar header terpisah yang perlu
diupdate manual.)

- [ ] **Step 2: Verifikasi**

Run: `php -l hq/export.php` → "No syntax errors detected".

Run (baca 1 baris hasil query langsung, pastikan kolom baru muncul):
```bash
mysql -e "SELECT id, biaya_tambahan, biaya_lainnya, biaya_lainnya_label FROM hl_transaksi LIMIT 3"
```
Expected: kolom `biaya_lainnya`/`biaya_lainnya_label` tampil (nilainya 0/NULL
utk order lama, wajar krn belum pernah diisi).

- [ ] **Step 3: Commit**

```bash
git add hq/export.php
git commit -m "feat(export): tambah kolom biaya_lainnya ke export CSV order"
```

---

## Ringkasan Urutan Task

1. Migrasi DB (kolom `biaya_lainnya` + `biaya_lainnya_label`)
2. POS — input & simpan saat buat order baru
3. Orders — edit + bugfix `biaya_tambahan` hilang saat edit
4. Struk — tampilkan di `renderThermal()` & `renderPdf()`
5. Export CSV — tambah kolom

Task 1 wajib duluan (kolom DB dulu). Task 2, 3, 4, 5 semua bergantung ke
Task 1 tapi independen satu sama lain — bisa dikerjakan sequential (lebih
aman krn Task 3 & 4 sama-sama baca kolom yang sama, minim risiko konflik
tapi tetap disarankan sequential mengikuti pola sesi sebelumnya).

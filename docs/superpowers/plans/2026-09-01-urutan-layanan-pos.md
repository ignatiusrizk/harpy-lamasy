# Urutan Layanan di POS Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Grid pilih-layanan di POS otomatis nampilkan layanan paling sering dipesan (30 hari terakhir) di posisi atas, dengan kemampuan owner "pin" layanan tertentu supaya selalu paling atas.

**Architecture:** Satu kolom DB baru (`hl_layanan.is_pinned`). Task 1 membangun sisi admin (toggle pin di halaman Layanan, persist ke DB). Task 2 membangun sisi konsumsi (query grid POS diurutkan pinned → frekuensi 30 hari real-time → abjad). Kedua task independen secara fungsional (Task 2 tidak butuh UI Task 1 buat dites — bisa pin manual lewat DB langsung saat testing Task 2).

**Tech Stack:** PHP (`layanan.php`, `pos.php`), vanilla JS inline, MySQL (`hl_layanan`, `hl_transaksi_item`, `hl_transaksi`).

## Global Constraints

- Perubahan urutan HANYA berlaku di grid layanan POS (`pos.php` action `get_layanan`). Halaman Layanan admin (`layanan.php`) listing order TIDAK diubah — tetap `kategori, urutan, nama`.
- SATU kolom database baru: `hl_layanan.is_pinned` (tinyint, default 0). Field `urutan` yang sudah ada DIPAKAI ULANG — bukan kolom baru, bukan dibuang.
- Urutan grid POS: `is_pinned DESC` → (kalau pinned) `urutan ASC` → `freq_30d DESC` (jumlah baris `hl_transaksi_item` yang match `layanan_id`, di-JOIN `hl_transaksi` filter `tanggal >= 30 hari lalu`, scoped tenant+outlet) → `nama ASC` fallback.
- `urutan` HANYA mempengaruhi urutan pengurutan untuk baris `is_pinned=1` — baris `is_pinned=0` TIDAK terpengaruh nilai `urutan` lama mereka (pakai `CASE WHEN is_pinned=1 THEN urutan ELSE 0 END` di ORDER BY, bukan `urutan` polos).
- Frekuensi dihitung REAL-TIME per request (JOIN di query, bukan kolom cache, bukan cron).

---

### Task 1: Kolom `is_pinned` + toggle pin di halaman Layanan

**Files:**
- Migration: dijalankan manual sekali via `php -r` (lihat Step 1), bukan file terpisah.
- Modify: `layanan.php:58-99` (action `save`, backend)
- Modify: `layanan.php:540-545` (form modal HTML)
- Modify: `layanan.php:888-899` (`openModal()`)
- Modify: `layanan.php:1245-1261` (`saveLayanan()`)
- Modify: `layanan.php:871-873` (kartu listing, badge)

**Interfaces:**
- Produces: kolom `hl_layanan.is_pinned` (tinyint 0/1) — Task 2 membaca kolom ini di query `pos.php`. Field JSON `is_pinned` pada respons `layanan.php?action=list` (endpoint sudah ada, `SELECT *` — otomatis ikut, tidak perlu diubah).

- [ ] **Step 1: Migrasi kolom database**

```bash
php -r '
require "/Users/rizky/Documents/lamasy/master/config/db.php";
require "/Users/rizky/Documents/lamasy/core/Database.php";
$db = Database::get();
$db->exec("ALTER TABLE hl_layanan ADD COLUMN is_pinned TINYINT(1) NOT NULL DEFAULT 0 AFTER urutan");
echo "kolom is_pinned ditambahkan\n";
'
```

Expected output: `kolom is_pinned ditambahkan`. Kalau error "Duplicate column name" — kolom sudah ada (skip, lanjut ke step berikut, jangan re-run ALTER).

- [ ] **Step 2: Verifikasi kolom**

```bash
php -r '
require "/Users/rizky/Documents/lamasy/master/config/db.php";
require "/Users/rizky/Documents/lamasy/core/Database.php";
$db = Database::get();
$rows = $db->query("SHOW COLUMNS FROM hl_layanan LIKE \"is_pinned\"")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
'
```

Expected: 1 baris, `Type` = `tinyint(1)`, `Null` = `NO`, `Default` = `0`.

- [ ] **Step 3: Simpan `is_pinned` di backend action `save`**

Di `layanan.php:78-88` (cabang UPDATE, `if (!empty($d['id']))`), ubah:

```php
        if (!empty($d['id'])) {
            TenantQuery::update('hl_layanan', [
                'nama'     => $nama,
                'kategori' => $kategori,
                'satuan'      => $d['satuan'] ?? 'kg',
                'harga'       => floatval($d['harga'] ?? 0),
                'qty_minimum' => max(0, floatval($d['qty_minimum'] ?? 0)),
                'estimasi_jam'=> $estimasiJam,
                'is_active'=> intval($d['is_active'] ?? 1),
                'urutan'   => intval($d['urutan'] ?? 0),
            ], 'id = ?', [intval($d['id'])]);
        } else {
```

menjadi:

```php
        if (!empty($d['id'])) {
            TenantQuery::update('hl_layanan', [
                'nama'     => $nama,
                'kategori' => $kategori,
                'satuan'      => $d['satuan'] ?? 'kg',
                'harga'       => floatval($d['harga'] ?? 0),
                'qty_minimum' => max(0, floatval($d['qty_minimum'] ?? 0)),
                'estimasi_jam'=> $estimasiJam,
                'is_active'=> intval($d['is_active'] ?? 1),
                'urutan'   => intval($d['urutan'] ?? 0),
                'is_pinned'=> intval($d['is_pinned'] ?? 0) ? 1 : 0,
            ], 'id = ?', [intval($d['id'])]);
        } else {
```

Lalu di cabang INSERT tepat di bawahnya (`layanan.php:89-99`), ubah:

```php
        } else {
            $newId = TenantQuery::insert('hl_layanan', [
                'nama'     => $nama,
                'kategori' => $kategori,
                'satuan'      => $d['satuan'] ?? 'kg',
                'harga'       => floatval($d['harga'] ?? 0),
                'qty_minimum' => max(0, floatval($d['qty_minimum'] ?? 0)),
                'estimasi_jam'=> $estimasiJam,
                'urutan'   => intval($d['urutan'] ?? 0),
                'is_active'=> 1,
            ]);
```

menjadi:

```php
        } else {
            $newId = TenantQuery::insert('hl_layanan', [
                'nama'     => $nama,
                'kategori' => $kategori,
                'satuan'      => $d['satuan'] ?? 'kg',
                'harga'       => floatval($d['harga'] ?? 0),
                'qty_minimum' => max(0, floatval($d['qty_minimum'] ?? 0)),
                'estimasi_jam'=> $estimasiJam,
                'urutan'   => intval($d['urutan'] ?? 0),
                'is_pinned'=> intval($d['is_pinned'] ?? 0) ? 1 : 0,
                'is_active'=> 1,
            ]);
```

- [ ] **Step 4: Checkbox pin di form modal**

Di `layanan.php:540-545`, ubah:

```html
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Urutan Tampil</label>
          <input type="number" id="f_urutan" class="hl-input" value="0" min="0"/>
        </div>
      </div>
```

menjadi:

```html
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Urutan Tampil <span style="color:var(--gray);font-weight:400;font-size:11px;">— khusus antar layanan yang di-pin</span></label>
          <input type="number" id="f_urutan" class="hl-input" value="0" min="0"/>
        </div>
        <div class="hl-form-group" style="display:flex;align-items:flex-end;padding-bottom:8px">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px">
            <input type="checkbox" id="f_pinned" style="width:18px;height:18px"/>
            📌 Pin ke atas di POS
          </label>
        </div>
      </div>
```

- [ ] **Step 5: Isi & kirim checkbox di JS**

Di `layanan.php:888-899` (`openModal`), ubah:

```js
  document.getElementById('f_urutan').value = data?.urutan || 0;
  document.getElementById('f_active').value = data?.is_active ?? 1;
```

menjadi:

```js
  document.getElementById('f_urutan').value = data?.urutan || 0;
  document.getElementById('f_pinned').checked = !!(data?.is_pinned == 1);
  document.getElementById('f_active').value = data?.is_active ?? 1;
```

Di `layanan.php:1245-1261` (`saveLayanan`), ubah:

```js
      urutan:      document.getElementById('f_urutan').value,
      is_active:   document.getElementById('f_active').value,
```

menjadi:

```js
      urutan:      document.getElementById('f_urutan').value,
      is_pinned:   document.getElementById('f_pinned').checked ? 1 : 0,
      is_active:   document.getElementById('f_active').value,
```

- [ ] **Step 6: Badge 📌 di kartu listing**

Di `layanan.php:871-873`, ubah:

```js
    return `
    <div class="layanan-card ${l.is_active==1?'':'inactive'}">
      <div class="layanan-kat">${esc(l.kategori||'Umum')} ${badge} ${ovTag}</div>
```

menjadi:

```js
    const pinBadge = l.is_pinned==1 ? `<span class="lyn-badge" title="Pin ke atas di POS">📌</span>` : '';
    return `
    <div class="layanan-card ${l.is_active==1?'':'inactive'}">
      <div class="layanan-kat">${esc(l.kategori||'Umum')} ${badge} ${ovTag} ${pinBadge}</div>
```

- [ ] **Step 7: Verifikasi PHP syntax**

```bash
php -l /Users/rizky/Documents/lamasy/layanan.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 8: Test manual**

Buka halaman Layanan (`/layanan`) di browser. Edit satu layanan (atau tambah baru), centang "📌 Pin ke atas di POS", isi "Urutan Tampil" = 1, simpan. Cek badge 📌 muncul di kartu listing layanan itu. Buka lagi form edit-nya (klik ✏️ Edit) — checkbox harus tetap tercentang (data ke-load balik dari server dengan benar).

- [ ] **Step 9: Commit**

```bash
git add layanan.php
git commit -m "feat(layanan): kolom is_pinned + toggle pin ke atas di POS"
```

---

### Task 2: Grid POS diurutkan pinned + frekuensi 30 hari

**Files:**
- Modify: `pos.php:51-57` (action `get_layanan`)

**Interfaces:**
- Consumes: kolom `hl_layanan.is_pinned` (Task 1). Task ini TIDAK butuh Task 1's UI selesai duluan buat dites — bisa langsung `UPDATE hl_layanan SET is_pinned=1 WHERE id=?` manual via `php -r` buat verifikasi.
- Produces: field `freq_30d` (integer) ikut di tiap object hasil `pos.php?action=get_layanan` — dipakai kalau nanti mau ditampilkan sbg info (opsional, tidak wajib dipakai UI manapun di task ini).

- [ ] **Step 1: Ubah query `get_layanan`**

Di `pos.php:51-57`, ubah:

```php
    if ($action === 'get_layanan') {
        $rows = TenantQuery::raw(
            "SELECT * FROM hl_layanan WHERE tenant_id=? AND outlet_id=? AND is_active=1 ORDER BY kategori,urutan",
            [$tid, $oid]
        );
        echo json_encode($rows); exit;
    }
```

menjadi:

```php
    if ($action === 'get_layanan') {
        $rows = TenantQuery::raw(
            "SELECT l.*,
                    COALESCE(freq.cnt, 0) AS freq_30d
             FROM hl_layanan l
             LEFT JOIN (
                 SELECT ti.layanan_id, COUNT(*) AS cnt
                   FROM hl_transaksi_item ti
                   JOIN hl_transaksi t ON t.id = ti.transaksi_id AND t.tenant_id = ti.tenant_id
                  WHERE ti.tenant_id = ? AND t.outlet_id = ? AND ti.layanan_id IS NOT NULL
                    AND t.tanggal >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                  GROUP BY ti.layanan_id
             ) freq ON freq.layanan_id = l.id
             WHERE l.tenant_id=? AND l.outlet_id=? AND l.is_active=1
             ORDER BY l.is_pinned DESC,
                      (CASE WHEN l.is_pinned=1 THEN l.urutan ELSE 0 END) ASC,
                      freq_30d DESC, l.nama ASC",
            [$tid, $oid, $tid, $oid]
        );
        echo json_encode($rows); exit;
    }
```

Catatan: `$tid`/`$oid` dipakai 2x (sekali di subquery JOIN, sekali di WHERE utama) — parameter array harus `[$tid, $oid, $tid, $oid]` sesuai urutan `?` muncul di SQL.

- [ ] **Step 2: Verifikasi PHP syntax**

```bash
php -l /Users/rizky/Documents/lamasy/pos.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Test query langsung via PHP CLI (sebelum test browser)**

Jalankan query yang sama persis lewat `php -r` buat tenant yg sudah ada data transaksi (tenant 18, outlet 13), cek urutannya masuk akal:

```bash
php -r '
require "/Users/rizky/Documents/lamasy/master/config/db.php";
require "/Users/rizky/Documents/lamasy/core/Database.php";
$db = Database::get();
$stmt = $db->prepare(
    "SELECT l.id, l.nama, l.is_pinned, l.urutan, COALESCE(freq.cnt,0) AS freq_30d
       FROM hl_layanan l
       LEFT JOIN (
           SELECT ti.layanan_id, COUNT(*) AS cnt
             FROM hl_transaksi_item ti
             JOIN hl_transaksi t ON t.id = ti.transaksi_id AND t.tenant_id = ti.tenant_id
            WHERE ti.tenant_id = ? AND t.outlet_id = ? AND ti.layanan_id IS NOT NULL
              AND t.tanggal >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY ti.layanan_id
       ) freq ON freq.layanan_id = l.id
      WHERE l.tenant_id=? AND l.outlet_id=? AND l.is_active=1
      ORDER BY l.is_pinned DESC,
               (CASE WHEN l.is_pinned=1 THEN l.urutan ELSE 0 END) ASC,
               freq_30d DESC, l.nama ASC"
);
$stmt->execute([18, 13, 18, 13]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "{$r["nama"]} | pinned={$r["is_pinned"]} | freq_30d={$r["freq_30d"]}\n";
}
'
```

Expected: baris dengan `pinned=1` muncul duluan di output, sisanya terurut `freq_30d` menurun (angka tertinggi di atas), lalu abjad untuk yang seri.

- [ ] **Step 4: Test manual browser**

Buka `/pos`, cek grid layanan — layanan yang di-pin (dari Task 1's test, atau `UPDATE hl_layanan SET is_pinned=1 WHERE id=? AND tenant_id=?` manual kalau Task 1's UI belum sempat dites) muncul duluan. Layanan yang paling sering dipesan dalam sebulan terakhir (bisa dicek manual dari hasil Step 3 di atas) ada di posisi lebih atas dibanding layanan yang jarang/tidak pernah dipesan.

- [ ] **Step 5: Commit**

```bash
git add pos.php
git commit -m "feat(pos): grid layanan diurutkan pinned + frekuensi pesan 30 hari terakhir"
```

---

## Deploy

Push tiap task ke `main` (`git push origin main`) segera setelah lulus test manual-nya — auto-deploy Hostinger aktif di push ke `main`. Selalu `git fetch origin main` dan cek `git log HEAD..origin/main --oneline` sebelum push — repo ini dipakai beberapa proses paralel, kalau ada commit lain masuk duluan, `git merge origin/main --no-edit` dulu sebelum push (bukan `git reset --hard`, itu destruktif).

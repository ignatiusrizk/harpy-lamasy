# Urutan Layanan di POS (Otomatis + Pin Manual) — Design Spec

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this from the eventual plan. This document is the spec, not the plan.

**Goal:** Grid pilih-layanan di POS otomatis nampilkan layanan yang paling sering dipesan (30 hari terakhir) di posisi atas, dengan opsi owner "pin" layanan tertentu supaya selalu paling atas terlepas dari frekuensi.

**Konteks:** Grid layanan POS (tombol layanan yang di-tap kasir buat nambah item ke keranjang) saat ini diurutkan `kategori, urutan` — `urutan` adalah angka manual yang jarang dipakai (default 0 semua, harus diisi manual satu-satu, kurang praktis). Owner minta cara lebih pintar: otomatis urut dari yang paling sering dipakai, dengan kemampuan pin manual buat layanan favorit/andalan.

## Global Constraints

- Perubahan urutan HANYA berlaku di grid layanan POS (`pos.php`, action `get_layanan` + `renderLayananGrid`). Halaman Layanan (admin, `layanan.php`) tetap urut `kategori, urutan, nama` seperti sekarang — TIDAK diubah.
- Kolom baru: `hl_layanan.is_pinned` (tinyint/boolean, default 0). Ini SATU-SATUNYA kolom database baru di fitur ini — field `urutan` yang sudah ada DIPAKAI ULANG sebagai urutan ANTAR item yang di-pin (bukan dibuang, bukan dobel kolom).
- Urutan grid POS: **(1) is_pinned=1 duluan** (diurutkan sesama pinned pakai `urutan` ASC), **(2) sisanya (is_pinned=0)** diurutkan dari **jumlah kali dipesan dalam 30 hari terakhir** (COUNT baris `hl_transaksi_item` yang `layanan_id`-nya cocok, JOIN `hl_transaksi` buat filter `tanggal >= tanggal 30 hari lalu`), paling sering duluan, **(3) fallback** nama A-Z untuk yang seri jumlah pesanan (termasuk yang belum pernah dipesan = 0).
- Perhitungan frekuensi dilakukan REAL-TIME di query (tiap kali grid di-load) — TIDAK ada cron/job terpisah, tidak ada kolom cache jumlah-pesanan yang perlu di-maintain.
- Toggle pin ada di form Layanan (`layanan.php`), disimpan lewat action `save` yang sudah ada (tidak bikin endpoint baru).

---

## 1. Database

Kolom baru `is_pinned` di `hl_layanan`:
```sql
ALTER TABLE hl_layanan ADD COLUMN is_pinned TINYINT(1) NOT NULL DEFAULT 0 AFTER urutan;
```

## 2. Halaman Layanan (`layanan.php`) — Toggle Pin

- **Form modal** (`layanan.php:540-545`, dekat field "Urutan Tampil"): tambah checkbox "📌 Pin ke atas di POS" + ubah label "Urutan Tampil" jadi "Urutan Tampil (khusus antar item yang di-pin)" supaya jelas fungsinya sekarang spesifik buat item pinned saja, bukan urutan global.
- **`openModal()`** (`layanan.php:888-899`): isi checkbox dari `data?.is_pinned` saat edit.
- **`saveLayanan()`** (`layanan.php:1245-1261`): kirim `is_pinned` di payload POST.
- **Backend action `save`** (`layanan.php:58-99`, kedua cabang update DAN insert): terima & simpan `is_pinned` (`intval($d['is_pinned'] ?? 0) ? 1 : 0`).
- **Kartu layanan di listing** (`layanan.php:871-873`, area `layanan-kat`): tampilkan badge kecil "📌" di sebelah kategori kalau `l.is_pinned==1`, biar owner gampang lihat mana yang sedang di-pin tanpa buka form.

## 3. POS — Query & Grid

- **`pos.php:51-57`** (action `get_layanan`): ganti `ORDER BY kategori,urutan` dengan query baru yang JOIN hitungan frekuensi 30 hari:

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

  Catatan: pakai `CASE WHEN l.is_pinned=1 THEN l.urutan ELSE 0 END` (bukan `l.urutan ASC` polos) supaya nilai `urutan` LAMA yang mungkin sudah kepakai owner sebelum fitur ini ada (non-zero, di layanan yang TIDAK di-pin) tidak diam-diam ikut mempengaruhi urutan grup non-pinned — grup non-pinned murni diurutkan dari `freq_30d DESC`, `urutan` cuma relevan buat yang di-pin.

- **`renderLayananGrid()`** (JS, `pos.php` — cari `function renderLayananGrid`): TIDAK perlu diubah — dia render dari array `list` apa adanya sesuai urutan yang dikirim server, jadi urutan baru otomatis kepakai tanpa sentuh kode render. (Opsional kecil: tambah badge "📌" di tombol layanan yang `l.is_pinned==1`, sama pola kayak badge di layanan.php — lihat Task nanti buat kode persisnya.)

## 4. Testing Manual

- Pin 1-2 layanan lewat halaman Layanan, cek badge 📌 muncul di kartu.
- Buka POS, cek layanan yang di-pin tadi ada paling atas di grid.
- Bikin beberapa order pakai layanan tertentu berkali-kali (atau cek data existing tenant 18 yang sudah ada riwayat transaksi), reload POS, cek layanan yang sering dipesan naik ke atas (di luar yang pinned).
- Layanan yang belum pernah dipesan tetap muncul (di bagian bawah, urut abjad), tidak hilang dari grid.
- Halaman Layanan (admin) urutannya TIDAK berubah (masih kategori→urutan→nama seperti sebelumnya).

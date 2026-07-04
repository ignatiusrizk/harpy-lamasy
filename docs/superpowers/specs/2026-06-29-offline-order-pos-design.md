# Offline Order POS + Sync — Design Spec

> LAMASY. Tanggal: 2026-06-29.

## Goal

Kasir tetap bisa **membuat order di POS saat internet mati**, order disimpan lokal, lalu **otomatis ter-sync** ke server saat koneksi kembali. App = Capacitor thin-shell remote-webview ke `lamasy.harpy.id`; server tetap *authoritative* (tenant/outlet scope, nomor order, kas, poin, coin gating).

## Keputusan Desain (terkunci)

- **Scope offline:** buat order offline + sync (bukan read-only).
- **Boleh offline:** pilih **layanan + tier express** (dari katalog ter-cache) + **bayar tunai / DP** (nominal ikut ke kas saat sync).
- **Online-only (di-disable saat offline):** potong **deposit** pelanggan, **redeem poin / voucher / promo**. Alasan: saldo & kuota server-authoritative → rawan double-spend / bentrok.
- **Nomor order & struk:** offline cetak **kode sementara** (`OFF-<dev>-<seq>`). Saat sync, server kasih **nomor asli** TAPI kode sementara disimpan sebagai **alias permanen** (`offline_ref`) → lacak/ambil cucian via kode lama tetap jalan selamanya.
- **Harga berubah di server setelah snapshot:** server **hormati harga yang tersimpan di payload** (yang sudah dibayar pelanggan). Hanya kalau layanan/tier sudah dihapus → masuk daftar "perlu perhatian".
- **Pendekatan teknis:** Service Worker app-shell + IndexedDB queue (POS-only). Bukan SQLite native, bukan mini-form terpisah.

## Arsitektur

Dua lapisan klien baru di dalam webview, **scoped per tenant + outlet + user**:

1. **Service Worker** (`sw.js`, diperluas): cache app-shell POS (HTML `pos.php` + CSS/JS aset) → POS tetap kebuka offline.
   - Strategi: `stale-while-revalidate` khusus shell + aset POS.
   - Sisa app tetap `network-first` / perilaku existing (portal pelanggan tetap seperti sekarang).
2. **IndexedDB `lamasy_offline`** — 3 object store:
   - `catalog` — snapshot layanan + tier express + pelanggan terakhir. Di-refresh tiap POS dibuka online. Distempel tenant/outlet.
   - `queue` — order pending: `{uuid, payload, tempCode, createdAt, status: 'pending'|'syncing'|'done'|'error', errorMsg}`.
   - `meta` — device short id, seq terakhir tempCode, stempel tenant/outlet/user.
3. **Deteksi koneksi:** `navigator.onLine` + heartbeat fetch ringan → banner "📴 Offline — order disimpan lokal" + indikator "Pending sync (N)".

## Komponen & File

| File | Aksi | Tanggung jawab |
|---|---|---|
| `sw.js` | MODIFY | Cache shell + aset POS (scope origin operasional), tanpa ganggu rute portal pelanggan existing |
| `assets/offline-pos.js` | **NEW** | Mesin offline: wrapper IndexedDB (open/migrate, CRUD store), snapshot/baca katalog, queue add/list/markDone/markError, generator tempCode (per device, seq increment), watch koneksi, sync runner (batch POST + handle hasil), render banner + indikator + daftar "perlu perhatian" |
| `pos.php` | MODIFY | Daftar SW + `offline-pos.js`; saat online snapshot katalog; jalur submit: offline → enqueue + cetak struk tempCode (skip POST), online → alur existing; tampilkan banner/indikator/daftar |
| `pos.php?action=sync_offline` | **NEW endpoint** | Terima batch order antri (JSON). Per order **idempoten via uuid**. Bikin order pakai logic save existing (INSERT hl_transaksi + item, kas tunai, push, audit, coin gating, poin-earn) seolah dibuat saat sync; assign nomor asli (`NotaFormatter::next`); simpan `offline_ref` alias + `offline_uuid`. Balikin map `uuid → {ok, no_order, id}` atau `uuid → {error}` |
| Migration `hl_transaksi` | **NEW** | `ADD COLUMN offline_ref VARCHAR(40) NULL` (index) + `ADD COLUMN offline_uuid CHAR(36) NULL UNIQUE` (idempotency). NULL untuk order online biasa |
| `track.php` + lookup ambil/order (`orders.php` cari) | MODIFY | Cocokkan `offline_ref` juga (selain `no_order`) → kode sementara lama tetap bisa dilacak |

**Prinsip kunci:** order offline = order biasa berstatus pending-sync di klien; **semua efek server-authoritative dijalankan server saat sync** (nomor, kas, poin-earn, push, coin), seolah order dibuat pada saat sync. Deposit & redeem poin/voucher tidak pernah jalan offline.

## Data — Format

Payload order di `queue` (dikirim ke `sync_offline`):
```json
{
  "uuid": "b3f1c2a4-...-uuid-v4",
  "tempCode": "OFF-A3-007",
  "createdAt": "2026-06-29T17:40:12+07:00",
  "payload": {
    "nama_pelanggan": "Budi", "telepon": "0812...", "pelanggan_id": null,
    "items": [{ "layanan_id": 12, "tier_id": 3, "qty": 2, "harga": 8000, "subtotal": 16000 }],
    "total": 16000, "metode": "cash", "dp": 16000,
    "catatan": "...", "tanggal": "2026-06-29"
  }
}
```
Field online-only (deposit, redeem poin, voucher_id, promo_id, reward_id) **tidak disertakan** saat offline.

Kolom baru `hl_transaksi`:
```sql
ALTER TABLE hl_transaksi
  ADD COLUMN offline_ref  VARCHAR(40) NULL,
  ADD COLUMN offline_uuid CHAR(36)    NULL,
  ADD UNIQUE KEY uniq_offline_uuid (offline_uuid),
  ADD KEY idx_offline_ref (offline_ref);
```

## Alur

### Normal
1. **POS dibuka (online)** → katalog (layanan + tier + pelanggan terakhir) di-snapshot ke IndexedDB dengan timestamp + stempel tenant/outlet.
2. **Offline** → kasir buat order (layanan+tier dari katalog lokal, tunai/DP) → masuk `queue` (uuid v4 + tempCode `OFF-<dev>-<seq>`) → struk cetak tempCode. Banner "📴 Offline".
3. **Koneksi balik** → sync runner POST batch ke `sync_offline` → server bikin order asli idempoten → klien tandai `done`, simpan map tempCode→no_order; indikator "Pending (N)" turun.

### Idempotency & multi-device
- `offline_uuid UNIQUE` → sync 2× aman (server balikin hasil sama, tak dobel order).
- TempCode unik per device (`<dev>` = id pendek device) → 2 kasir offline barengan tak tabrakan; nomor asli di-assign berurutan saat sync.

### Trigger sync
- Otomatis saat event koneksi balik (`online` event / heartbeat sukses) **dan** saat POS dibuka online.
- Tombol manual "Sync sekarang" di indikator (fallback).

## Error Handling

| Kondisi | Perilaku |
|---|---|
| Item wajib (layanan/tier) sudah dihapus di server saat sync | Order ditolak per-uuid → status `error` + masuk daftar "Perlu Perhatian", kasir re-input manual |
| Session mati saat sync | Sync ditahan, prompt re-login; antrian tetap aman sampai berhasil |
| Sebagian batch sukses sebagian gagal | Per-order: sukses `done`, gagal `error` — tak saling blokir |
| Harga server beda dari snapshot | Server hormati harga payload (sudah dibayar); tak di-flag |
| Sync uuid yang sudah pernah sukses | Server balikin hasil sama (idempoten), klien set `done` |
| Logout / ganti outlet/tenant | Katalog + antrian **dihapus** (anti bocor antar user/outlet) — peringatkan jika masih ada antrian pending sebelum logout |

## Keamanan (multi-tenant)

- Katalog + antrian distempel & dipakai hanya untuk tenant+outlet+user yang login; **dibersihkan saat logout & ganti scope**.
- `sync_offline` tetap lewat guard tenant/outlet existing + `verifyCsrf()`; server **tidak** percaya tenant/outlet dari payload — pakai sesi server.
- Sebelum logout, kalau ada antrian `pending`/`error` → tampilkan peringatan ("ada N order belum ter-sync").

## Testing

- **PHP unit (`sync_offline`):**
  (a) uuid baru → 1 order dibuat, no_order ke-assign, offline_ref tersimpan;
  (b) uuid sama 2× → tetap 1 order (idempoten), respons sama;
  (c) layanan dihapus → order itu `error`, order lain di batch tetap sukses;
  (d) tunai/DP → entri kas tercatat;
  (e) harga payload dihormati (beda dari harga server terkini).
- **PHP unit (lookup):** track/orders cari via `offline_ref` ketemu order yang benar.
- **Klien (offline-pos.js):** queue add/list/markDone/markError; sekuens tempCode per device; baca katalog dari IndexedDB; transisi online↔offline (mock `navigator.onLine`); pembersihan saat logout.
- **Lint** semua file PHP yang disentuh.
- **Manual E2E:** mode pesawat → buka POS (kebuka dari cache) → buat order layanan+tier+tunai → struk tempCode → matikan mode pesawat → auto-sync → no_order asli muncul, lacak via kode lama jalan, kas tercatat, indikator pending = 0.

## Out of Scope

- Edit/hapus order saat offline (hanya buat baru).
- Sinkron data selain order (mis. update status produksi/kanban offline) — fitur terpisah nanti.
- Deposit, redeem poin/voucher/promo offline (online-only by design).
- Pembayaran non-tunai (QRIS/transfer) offline — butuh konfirmasi online.
- Offline untuk halaman selain POS (orders, kanban, laporan tetap online-only).
- Konflik harga otomatis / approval flow harga (server hormati payload).
- Background Sync API murni tanpa user buka app (andalkan event online + buka app; bisa ditambah nanti).

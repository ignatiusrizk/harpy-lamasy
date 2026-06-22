# WA Link Notif — Design Spec

**Tanggal:** 2026-06-22
**Status:** Approved, ready for implementation plan
**Scope:** Transactional WhatsApp notification via `wa.me` link (no backend gateway)

## Tujuan

Memberi kasir cara cepat mengirim notifikasi WA ke pelanggan untuk 3 event utama, tanpa biaya infrastruktur dan tanpa integrasi backend gateway. Pelanggan WA datang dari handphone kasir, branding muncul dari nomor outlet sendiri.

## Non-tujuan

- Tidak menangani broadcast mass (1 click → ratusan pelanggan). Itu pekerjaan terpisah, butuh gateway berbayar.
- Tidak auto-send (zero kasir interaction). Kasir tetap harus tekan Send di WA — ini fitur, bukan bug (review human + branding nomor sendiri).
- Tidak attachment file. Struk PDF dikirim sebagai **link** ke halaman struk publik existing.

## Pendekatan

Generate URL `https://wa.me/{phone}?text={url_encoded_body}` dan buka di tab baru. WhatsApp Web (di desktop) atau WhatsApp app (di handphone) akan handle compose. Kasir tinggal tekan Send.

## Komponen

### 1. Helper di `components.php`

```php
function waLink(?string $phone, string $template, array $vars = []): ?string;
```

- Normalisasi `$phone`: hapus `-`, ` `, `(`, `)`, `+`; awalan `0` → `62`; awalan `8` → `628`; awalan `+62` → `62`. Kalau hasil bukan 9-15 digit angka, return `null`.
- Substitusi `{key}` di template dengan `$vars[key]`. Key tidak ditemukan = kosong.
- URL-encode body dengan `rawurlencode`.
- Return string URL.

### 2. Template default

Hardcoded di `components.php` sebagai array constant `WA_TEMPLATES`. 3 key:

| Key | Body |
|---|---|
| `order_diterima` | `Halo {nama} 👋\nPesanan #{kode} sudah kami terima di {outlet}.\nEstimasi selesai: {tgl_ambil}\nCek status: {link_track}\n\nTerima kasih!` |
| `order_ready` | `Halo {nama} ✨\nPesanan #{kode} sudah siap diambil di {outlet}.\nTotal: Rp {total}\n\nDitunggu ya!` |
| `struk_lunas` | `Terima kasih {nama} 🙏\nPembayaran #{kode} lunas. Total Rp {total}.\nStruk digital: {link_struk}` |

Future enhancement (out of scope): editable per outlet di `outlet-settings.php`.

### 3. Tombol di UI

#### A. `pos.php` — setelah create order

Di section yang sudah ada untuk "Cetak Struk" / "Order Baru", tambahkan tombol **"📱 WA Pelanggan"** kalau `pelanggan.no_hp` terisi. Klik → call helper JS `openWa(orderId, 'order_diterima')` → POST kecil ke endpoint yang return URL → `window.open(url)`.

#### B. `orders.php` / kanban — saat pindah ke status "ready"

Di modal/action saat status di-update ke `ready`, tampilkan checkbox **"Kirim WA otomatis"** (default checked kalau no_hp ada). Saat submit + status berhasil update → buka tab WA dengan template `order_ready`.

#### C. `pos.php` — saat payment success

Di flow yang sudah generate struk, tambah tombol **"WA Struk"** samping "Cetak Struk". Template `struk_lunas`.

### 4. Endpoint generator

Untuk menghindari URL yang panjang di HTML dan ekspos data di query string, buat:

```
GET /api/wa_link.php?order_id=123&t=order_ready
```

- Tenant-guard required.
- Validasi order milik tenant + outlet aktif.
- Build vars dari order (nama, kode, total, tgl_ambil, link_track, link_struk).
- Return JSON `{url: "https://wa.me/..."}` atau `{error: "..."}`.

Front-end buka `window.open(json.url, '_blank')`.

### 5. Logging (lightweight)

Tambah kolom di `hl_order` (atau table existing):
- `wa_sent_count INT DEFAULT 0`
- `wa_sent_last DATETIME NULL`

Increment via endpoint generator setiap dipanggil (catat intent — tidak konfirmasi delivery, tapi cukup untuk laporan "berapa order pakai WA notif"). **Bukan** table baru.

Alternatif lebih ringan: cukup `logAudit('wa_link', 'order', "order_id={$id} template={$t}")` di endpoint, skip kolom DB. **Pilih ini** untuk MVP.

## Tidak ada migration

Skip kolom DB. Audit log cukup.

## Edge cases

- Pelanggan tanpa no_hp → tombol disabled, tooltip "Pelanggan belum ada nomor WA".
- No_hp invalid format → tombol enabled, tapi `waLink()` return null → frontend show toast "Nomor tidak valid".
- Tenant pakai sub-outlet dengan nama berbeda → `{outlet}` ambil dari `outlets.nama` sesuai outlet aktif order.
- `{link_track}` dan `{link_struk}` pakai domain dari `currentTenant()['subdomain']` atau base URL config.

## Testing

Manual:
1. Create order untuk pelanggan dengan no_hp valid → klik WA → tab baru ke WhatsApp Web dengan body terisi benar.
2. Order tanpa no_hp → tombol disabled.
3. No_hp dengan format aneh (`0812-3456-7890`, `+62 812 3456 7890`) → normalisasi benar.
4. Body mengandung karakter spesial (apostrophe di nama, emoji) → URL-encode tidak rusak.

## Out of scope (deferred)

- Broadcast mass via gateway (Fonnte/WAHA) — paket terpisah saat ada justifikasi pricing.
- Auto-send tanpa interaksi kasir.
- Template editor per outlet — bisa nanti kalau ada permintaan.
- Delivery confirmation (read receipt) — wa.me tidak support.

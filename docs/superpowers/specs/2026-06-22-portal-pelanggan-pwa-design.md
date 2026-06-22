# Portal Pelanggan PWA (Read-Only) — Design Spec

**Tanggal:** 2026-06-22
**Status:** Approved, ready for implementation plan
**Scope:** Portal self-service pelanggan read-only via QR struk login + PWA shell (install-able, offline-capable). Tidak ada action — pelanggan cuma view history, status, saldo, poin.

## Tujuan

Beri pelanggan akses mandiri ke data order, saldo deposit, dan poin loyalty mereka tanpa harus tanya staff. Mengurangi beban kasir jawab pertanyaan "status order saya gimana?". Token persistent di QR struk = zero friction login. PWA = bisa di-install ke home screen seperti app native.

## Non-tujuan

- Tidak ada action menulis data (request pickup, redeem voucher, top-up deposit — semua tetap di outlet via kasir)
- Tidak ada OTP / SMS / email login (butuh gateway berbayar yang belum tersedia)
- Tidak ada push notification (Phase 2, defer)
- Tidak ada edit profile pelanggan (tetap staff yang kelola via /customer)
- Tidak ada force logout / session timeout (token persistent, regenerable manual)

## Pendekatan

QR token persistent per-pelanggan disimpan di kolom baru `hl_pelanggan.portal_token`. Struk QR existing diupdate untuk encode token + no_order. Endpoint `/p` validate token → set session `pelanggan_id` → redirect ke portal. 4 halaman PHP read-only (home, order detail, deposit, loyalty — atau 1 home dengan section). PWA shell via manifest.json + minimal service worker untuk install + offline fallback.

## Keamanan & Trust Model

**Threat model**: anyone yang punya struk fisik/foto struk = akses portal pelanggan tsb. **Mitigation**:
1. **Read-only scope** — tidak ada action destructive. Max risk = orang lain lihat history (data yang sudah print di struk anyway).
2. **Token regeneration** — pelanggan bisa regenerate via tombol di portal kalau curiga bocor. Owner juga bisa regenerate via `/customer` page. Struk lama dengan token lama jadi invalid.
3. **HTTPS only** — token in URL parameter, hanya di HTTPS (existing).
4. **Rate limiting** — endpoint `/p` rate-limit per IP (5/menit) untuk prevent brute force token guessing. 16-byte token = 128 bits entropy, secara teori tidak guessable.

## Komponen

### Data Model

**ALTER `hl_pelanggan`**:
```sql
ALTER TABLE hl_pelanggan ADD COLUMN IF NOT EXISTS portal_token VARCHAR(64) UNIQUE NULL AFTER catatan;
```

**Auto-generate token**:
- Saat insert pelanggan baru (di POS create order flow): `bin2hex(random_bytes(16))` = 32 char hex
- Backfill untuk pelanggan existing: SQL UPDATE single query, fill semua yang NULL

**Tidak ada tabel baru.** Session standar PHP (existing) digunakan untuk simpan `pelanggan_id` setelah login.

### Authentication Flow

```
1. Pelanggan scan QR struk
   ↓
2. Browser open: https://lamasy.harpy.id/p?t={token}&o={no_order}
   ↓
3. Endpoint /p:
   a. Validate token regex (hex 32 char)
   b. SELECT pelanggan_id FROM hl_pelanggan WHERE portal_token=? LIMIT 1
   c. Rate limit check (5 req/menit per IP)
   d. Kalau valid → $_SESSION['pelanggan_id'] = id
   e. Redirect ke /pelanggan/order/{no_order} (kalau o ada) ATAU /pelanggan
   ↓
4. Pelanggan masuk portal sebagai pelanggan tsb
   ↓
5. Token tetap valid sampai di-regenerate (tidak expire)
```

**Middleware `pelanggan_guard.php`**:
- Check `$_SESSION['pelanggan_id']` ada
- Kalau tidak → redirect ke `/p` (atau tampil "Scan QR struk untuk login")
- Provide function `currentPelanggan(): array`

### Halaman

#### `/p` — Login entry
- GET dengan `?t=TOKEN` (wajib) dan `?o=NO_ORDER` (opsional)
- Validate + set session + redirect
- Kalau token invalid → tampil pesan "Link tidak valid. Scan ulang QR dari struk terbaru."

#### `/pelanggan` — Portal home (read-only)

```
👋 Halo, [Nama Pelanggan]
[Logout · Regenerate Token]

┌─ 💰 Saldo & Poin ─────────────────┐
│ Deposit: Rp 50.000                  │
│ Poin Loyalty: 245 (Silver Tier)     │
└─────────────────────────────────────┘

┌─ 🧺 Order Aktif (3) ─────────────────┐
│ ┌──────────────────────────────────┐ │
│ │ #ORD123 · 3 item · Rp 75.000    │ │
│ │ 🟢 Sedang Setrika · Est. 22 Jun │ │
│ └──────────────────────────────────┘ │
│ ... (cards lain)                       │
└─────────────────────────────────────┘

┌─ 📋 Riwayat (20 terakhir) ───────────┐
│ Table compact: tgl, #, total, status  │
│ [Load More] (paginated)               │
└─────────────────────────────────────┘
```

#### `/pelanggan/order/{no_order}` — Detail order
- Reuse template `/track.php` existing (status pipeline + items + bukti antar)
- BUT auth via session (bukan public)
- Validate order belongs to current pelanggan_id sebelum render
- Kalau tidak match → 403

#### PWA Install Banner & Manifest
- `/assets/manifest.json` — name "LAMASY Pelanggan", icons, start_url=/pelanggan, display=standalone, theme_color=teal
- Meta tags di `<head>` portal pages: `<link rel="manifest">`, `<meta name="theme-color">`, apple-touch-icon
- Auto install banner via beforeinstallprompt event (Chrome) atau manual button "📲 Install App"

#### Service Worker (`/sw.js`)
- Minimal: cache `/assets/*`, `/harpy-erp.css`, manifest, fallback offline page
- Cache strategy: cache-first untuk static assets, network-first untuk pages (read-only ok kalau stale)
- Offline fallback: tampil "Tidak ada koneksi. Coba lagi saat online."

### Token Regeneration

**Pelanggan-side** (di /pelanggan): tombol "🔄 Regenerate Token" → konfirmasi → POST ke `/pelanggan?action=regen_token` (CSRF) → generate new token → invalidate session → tampil pesan "Token baru aktif. Scan QR dari struk berikutnya untuk login ulang."

**Owner-side** (di /customer): tombol baru "🔄 Regen Portal Token" per pelanggan → konfirmasi → same backend action → pelanggan harus pakai struk baru.

### Struk QR Update

Existing `core/StrukGenerator.php` generate QR dengan track URL. Update untuk encode portal URL:

```php
// Before:
$qrUrl = APP_URL . '/track.php?order=' . urlencode($order['no_order']);

// After:
$qrUrl = APP_URL . '/p?t=' . ($order['pelanggan_token'] ?? '') . '&o=' . urlencode($order['no_order']);
```

Untuk struk pelanggan walk-in (no_hp kosong / pelanggan_id=null) → fall back to track URL (existing behavior).

### Error handling

- Invalid token → tampil pesan + link ke /track (fallback public)
- Token sudah di-regenerate → tampil "Token expired, scan struk terbaru"
- Order tidak match pelanggan_id → 403 generic
- Rate limit exceeded → 429 "Terlalu banyak percobaan. Tunggu 1 menit."
- Network error di service worker → tampil offline fallback

### Audit

- `logAudit('portal_login', 'pelanggan', "pelanggan_id=$id")` di endpoint /p (saat success)
- `logAudit('portal_token_regen', 'pelanggan', "pelanggan_id=$id by=$by")` saat regenerate (pelanggan/owner)

### Testing

Manual:
1. Pelanggan baru di POS → cek `hl_pelanggan.portal_token` auto-generated (32-char hex)
2. Cetak struk → QR encode URL `/p?t=XXX&o=YYY`
3. Scan QR di HP → buka portal → session set, redirect ke `/pelanggan/order/YYY`
4. Lihat home `/pelanggan` → semua order pelanggan tsb muncul, saldo + poin correct
5. Klik order di list → detail order (reuse /track view)
6. Test 403: ganti `o=` di URL ke order pelanggan lain → 403 "Order tidak ditemukan/tidak punya akses"
7. Test logout → session cleared
8. Test regenerate token: klik regen → token DB berubah, struk lama (URL lama) tidak login lagi
9. Test rate limit: brute-force token random 10x → 429 setelah 5x
10. Test PWA install: buka Chrome mobile → "Install LAMASY Pelanggan" prompt muncul
11. Test offline: install PWA, matikan internet, buka → halaman offline fallback muncul

## Out of scope (Phase 2, defer)

- Push notification (web push API + service worker push handler + token storage)
- OTP via WA gateway (Fonnte) — butuh WA API yang belum tersedia
- Action capabilities: request pickup, redeem voucher, top-up deposit
- Edit profile (nama, no_hp, alamat) — tetap staff via /customer
- Pelanggan tier upgrade flow
- Referral / share link
- Multi-language (cukup Indonesia)
- Multi-outlet view kalau pelanggan order di outlet beda — hanya satu tenant (sudah default)

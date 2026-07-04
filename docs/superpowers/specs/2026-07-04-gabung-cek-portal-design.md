# Gabung `/cek` + Portal Pelanggan — Design

> LAMASY. Tanggal: 2026-07-04. Menyatukan pintu masuk publik `/cek` (cek status)
> dan portal pelanggan (`/p` → `/pelanggan`) jadi satu front door, tetap menjaga
> dua tier akses. Tanpa OTP, tanpa funnel.

## Tujuan

Satu pintu masuk publik supaya pelanggan tidak bingung antara dua URL berbeda
(`/cek` untuk cek status vs `/p` untuk portal). Bukan funnel konversi, bukan
menambah jalur login baru — murni penyatuan UI + konsolidasi routing.

## Keputusan yang sudah diambil (brainstorming)

1. **Tujuan = satu pintu masuk (kurangi bingung)**, bukan funnel guest→portal.
2. **Tampilan pintu = guest-first**: form cek status menonjol, link "Masuk Portal"
   sekunder & halus.
3. **Portal tetap QR-only** — TIDAK menambah login HP+OTP (diparkir sbagai fase
   terpisah kalau nanti perlu; WA OTP belum mungkin karena gateway belum ada).
4. **Pendekatan A**: `/cek` jadi front door; `/p` tanpa token redirect ke `/cek`;
   portal `/pelanggan` tak disentuh.

## Dua tier akses (tetap terpisah tegas — tak ada jembatan)

| Tier | Kunci identitas | Yang dibuka | Sesi? |
|---|---|---|---|
| Tamu | nota + 4 digit terakhir HP (~14 bit, rate-limited) | **1 order**: status, timeline, item, tagihan | Tidak |
| Portal member | QR token 32-hex (128-bit) di struk | **Akun penuh**: poin, deposit, semua riwayat, kupon, ajak teman | Ya (`portal_pelanggan_id`) |

Tamu yang berhasil cek status **tidak** mendapat akses portal. Alasan: 4 digit HP
terlalu lemah untuk membuka data akun penuh (saldo deposit, seluruh riwayat).

## Arsitektur & Routing

Front door tunggal = `/cek` (cek.php ditingkatkan). Jalur QR & portal tak berubah.

| URL | Perilaku |
|---|---|
| `/cek` | Pintu masuk publik. Alur 2 langkah tetap: input nota → verifikasi 4 digit HP → tampil status. **Ditambah** afordan "Masuk Portal" di state awal. Handle query `?msg=portal`. |
| `/p?t=TOKEN` | **TIDAK berubah** — validasi token → set sesi `portal_pelanggan_id` → redirect `/pelanggan` (atau `/pelanggan-order?o=` kalau ada param `o`). Ini yang tercetak di QR struk yang sudah beredar. |
| `/p` tanpa token | **Diubah** — dari halaman info "scan QR" jadi `302` redirect ke `/cek?msg=portal`. |
| `/p?t=` token invalid/format salah | `302` redirect ke `/cek?msg=portal` (tak membocorkan valid/tidaknya token). |
| `/pelanggan`, `/pelanggan-order` | **TIDAK berubah** — tetap di balik `middleware/pelanggan_guard.php`. |

**File yang disentuh:**
- `cek.php` — tambah afordan portal di state awal + handle `msg=portal` + hardening query nota (lihat bawah).
- `p.php` — ganti blok "no token" (baris ~72–102, halaman info) jadi redirect ke `/cek?msg=portal`; token invalid juga redirect.
- **Nol perubahan**: `pelanggan.php`, `pelanggan-order.php`, `middleware/pelanggan_guard.php`, jalur token valid di `p.php`, `core/StrukGenerator.php` (QR generator).

## Halaman `/cek` — perubahan UI (hanya state awal)

State verifikasi 4-digit dan state hasil status **tetap seperti sekarang**. Yang
berubah hanya state awal (belum ada param `n`).

Struktur state awal:
- Header brand "🧺 LAMASY / Cek status cucian Anda" (branding disamakan LAMASY).
- **Kartu utama (guest-first):** form "Masukkan Nomor Nota" + tombol "🔍 Cek Status"
  (sama seperti sekarang).
- **Pemisah** "── atau ──".
- **Afordan portal (sekunder, halus):** kartu berisi `🎫 Punya akun member? Masuk
  Portal Pelanggan →`. Di-tap → **membuka inline** (toggle JS, tanpa pindah
  halaman) menampilkan penjelasan:
  > 📷 Scan QR code di struk laundry kamu — otomatis masuk ke akun.
  > Di portal kamu bisa lihat: • Poin & deposit • Riwayat semua order • Kupon & ajak teman

**Saat `?msg=portal`** (redirect dari `/p`): blok portal ini tampil **terbuka &
diletakkan di atas** form cek-status (karena user memang niat ke portal tapi tak
bawa token), form cek-status tetap tersedia di bawah.

Tak ada backend/tabel baru untuk afordan ini — murni markup + JS toggle. Toggle
harus bisa diakses keyboard (pakai `<button>`/`<details>`, bukan `<div onclick>`).

## Hardening: query nota tak di-scope tenant

Masalah: `lookupOrder()` di cek.php melakukan `WHERE no_order=? LIMIT 1` tanpa
`tenant_id`. `no_order` hanya unik per-tenant (constraint `uq_tenant_no_order`),
bukan global. Dua tenant bisa punya `no_order` sama; `LIMIT 1` memilih sembarang
baris, lalu verifikasi 4-digit dilakukan pada baris sembarang itu. Skenario langka
tapi nyata: menampilkan order tenant lain kalau 4-digit HP kebetulan cocok
(bocor lintas-tenant).

Perbaikan: hapus `LIMIT 1`; ambil SEMUA baris dengan `no_order` tsb, lalu di PHP
kembalikan **hanya baris yang 4-digit HP-nya benar-benar cocok**. Kalau tak ada
yang cocok → not found. Kalau >1 cocok (sangat langka) → tetap ambil yang pertama
cocok (aman, karena verifikasi sudah lolos). Menutup celah tanpa perlu konteks
tenant di halaman publik.

## Error handling & edge case

- `/p` tanpa token → `302 /cek?msg=portal` langsung.
- `/p?t=` token invalid/format salah → tetap **catat percobaan gagal** (rate-limit
  anti-bruteforce 5/menit yang sudah ada di p.php) DULU, baru `302 /cek?msg=portal`.
  Tak membocorkan validitas token (baik habis-rate maupun tidak, tujuannya sama).
- `/cek` 4-digit salah → pesan generik "Nomor nota / 4 digit telepon tidak cocok"
  (perilaku sekarang, dipertahankan).
- Rate-limit dipertahankan apa adanya: `/cek` per IP (lihat implementasi
  existing), `/p` 5/menit per IP.
- Header `Cache-Control: no-store` dipertahankan di kedua file.
- QR deep-link cetak (`/p?t=TOKEN&o=NO_ORDER`) → tetap auto-login lalu ke detail
  order portal.

## Testing (manual E2E via browser — repo tak punya unit-test, sesuai pola rumah)

1. `/cek` (tanpa `n`) → form cek-status + afordan portal tampil; tap afordan →
   penjelasan QR terbuka inline; bisa di-toggle via keyboard.
2. `/cek` → input nota + 4-digit **benar** → tampil status order. 4-digit **salah**
   → pesan error, tak ada data order bocor.
3. `/p?t=<valid>` → auto-login → `/pelanggan` (tak berubah).
4. `/p` (tanpa token) → redirect `/cek?msg=portal`, blok portal tampil terbuka.
5. `/p?t=<invalid>` → redirect `/cek?msg=portal`.
6. `/p?t=<valid>&o=<no_order>` (deep-link QR cetak) → `/pelanggan-order` order tsb.
7. Lintas-tenant: siapkan 2 tenant dengan `no_order` identik & HP berbeda → tiap
   pelanggan hanya melihat order miliknya (match by HP), bukan pilihan sembarang.

## Non-goals (di luar scope, sengaja tidak dikerjakan)

- Login HP + OTP (email/WA) — diparkir; butuh channel OTP & pertimbangan email.
- Funnel/ajakan konversi guest → portal.
- Redesign penuh tampilan portal `/pelanggan`.
- Menyatukan tampilan status & portal dalam satu halaman (Pendekatan C ditolak).

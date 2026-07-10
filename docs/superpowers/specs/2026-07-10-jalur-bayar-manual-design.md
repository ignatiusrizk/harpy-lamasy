# Jalur Bayar Manual (Transfer Bank) — Design Spec

**Tanggal:** 10 Juli 2026 · **Untuk:** owner (Ignatius)
**Status:** disetujui untuk implementasi

## Latar Belakang & Tujuan

Midtrans Core API (QRIS/VA) di akun production **belum di-approve** — semua charge
dibalas HTTP 402 "Payment channel is not activated". Aktivasi menunggu proses di
sisi Midtrans (1×30 menit s/d beberapa hari kerja). Akibatnya **gerbang revenue
tertutup total**, termasuk gate `setup_fee` yang memaksa setiap tenant baru bayar
sebelum bisa memakai aplikasi — sekarang gate itu jadi jalan buntu (layar error 402).

**Tujuan:** jalur pembayaran manual (transfer bank) sebagai fallback sementara,
supaya LAMASY bisa menerima uang **hari ini** tanpa menunggu Midtrans. Owner transfer
ke rekening → SA mencocokkan di mutasi bank → konfirmasi lunas → coin/aktivasi
dikreditkan lewat mesin settlement yang sudah ada. Saat QRIS aktif nanti, kedua jalur
hidup berdampingan tanpa perubahan.

## Prinsip

- **Zero schema change.** Reuse tabel `saas_payments`, kolom `payment_type`
  (varchar(30), muat nilai baru `manual_transfer`), enum `status` yang sudah ada
  (`pending`→`paid`/`cancelled`), dan enum `type` yang sudah mencakup ketiga transaksi.
- **Reuse settlement.** `PaymentSettler::settle($id)` yang sudah ada — coin/aktivasi
  identik dengan jalur QRIS. Idempoten via `coin_ledger.payment_id`.
- **Hanya SA yang bisa flip ke `paid`.** Bukan owner. Via CSRF + audit log.
- **YAGNI.** Tanpa upload bukti transfer. Verifikasi lewat kode unik nominal + SA cek
  mutasi bank.

## Global Constraints

- Header CSRF WAJIB `X-CSRF-Token` (kapital-semua ditolak Hostinger prod).
- `apiErr($e)` untuk error API (log server + pesan generik) — jangan echo
  `$e->getMessage()` ke client.
- Timezone: PHP `date()` = WIB; MySQL `NOW()` = UTC. `expires_at` ditulis via PHP
  `date()` (WIB) supaya konsisten dengan pola `billing-checkout.php` yang ada
  (bandingkan `expires_at > date('Y-m-d H:i:s')`, bukan `NOW()`).
- `tenant_id` SELALU dari session, tak pernah dari input.
- Rupiah tampil dengan pemisah ribuan gaya Indonesia (`number_format($n,0,',','.')`).
- SA halaman pakai pola komponen SA yang ada (`saFetch`/`saPost`, `.lmx-btn`).

## Kode Unik Nominal

Saat row manual dibuat: `amount = harga_dasar + kode`, di mana `kode ∈ [1, 999]`
dipilih supaya **total `amount` unik di antara semua row `manual_transfer` yang masih
`pending`** (agar SA bisa mencocokkan satu-satu di mutasi bank). Algoritma: generate
kode acak, cek `SELECT 1 FROM saas_payments WHERE payment_type='manual_transfer' AND
status='pending' AND amount=?`; ulang s/d ~30 kali; kalau tetap bentrok (praktis
mustahil di volume rendah), pakai kode terakhir apa adanya.

**Kenapa aman:** coin (`settleTopupCoin` → `coin_didapat` bundle), setup
(`settleSetupFee` → `coin_awal`/`max_outlets` package), dan aktivasi
(`settleOutletActivation` → config `outlet_activation_coin`) semuanya dihitung dari
bundle/package/config — **tidak** dari kolom `amount`. Jadi kode unik hanya mengubah
nominal yang ditransfer & ditampilkan, tak mengubah coin/aktivasi yang didapat. Nilai
yang benar-benar diterima memang `harga_dasar + kode`, jadi pelaporan revenue tetap akurat.

`harga_dasar` dan `kode` disimpan di `raw_response` (JSON) untuk referensi tampilan.

## Config (SA)

Key baru di `saas_billing_config` (via `BillingConfig::get/set`):

| Key | Contoh | Fungsi |
|---|---|---|
| `manual_payment_enabled` | `1` | Master switch. `0` → jalur manual tak muncul di mana pun. |
| `manual_bank_name` | `BCA` | Nama bank rekening tujuan |
| `manual_bank_account_no` | `1234567890` | No rekening tujuan |
| `manual_bank_holder` | `Ignatius Rizky` | Nama pemilik rekening |
| `manual_payment_expiry_hours` | `24` | Masa berlaku row manual (default 24 jam) |

Jalur manual dianggap **aktif** hanya jika `manual_payment_enabled=1` DAN ketiga field
rekening terisi. Kalau tidak, opsi "Transfer Manual" tak dirender & endpoint manual
menolak (redirect balik).

Diedit di `superadmin/billing-config.php` (form field baru).

## Alur Data

```
Owner                          Server                         SA
  │  klik "Transfer Manual"       │                             │
  ├──────────────────────────────>│ billing-checkout            │
  │                               │  ?type=…&method=manual       │
  │                               │  - hitung harga_dasar        │
  │                               │  - generate kode unik        │
  │                               │  - insert saas_payments      │
  │                               │    (manual_transfer,pending) │
  │  <── kartu instruksi ─────────┤                             │
  │      (rekening, nominal+kode,  │                             │
  │       order_id, tombol         │                             │
  │       "Saya sudah transfer")   │                             │
  │                               │                             │
  │  transfer via m-banking        │                             │
  │  tap "Saya sudah transfer"     │                             │
  ├──────────────────────────────>│ action=notify_sa            │
  │                               ├── SaNotifier ──────────────>│ (notif "ada transfer
  │  <── "menunggu konfirmasi" ────┤    (best-effort)            │  manual masuk")
  │                               │                             │
  │  (polling billing-status)      │                    cek mutasi bank
  │                               │                    cocokkan nominal+kode
  │                               │  <── POST konfirmasi ───────┤ klik "Konfirmasi Lunas"
  │                               │  - status=paid,paid_at       │
  │                               │  - PaymentSettler::settle()  │
  │                               │    (coin/aktivasi)           │
  │  <── polling lihat 'paid' ─────┤                             │
  │      redirect billing-success  │                             │
```

## Komponen & File

### 1. `billing-checkout.php` (modifikasi)
- **Branch `method=manual`:** ketika `$_GET['method']==='manual'`:
  - Guard: jalur manual harus aktif (config). Kalau tidak → `die` ramah / redirect.
  - Hitung `amount` dasar seperti biasa (per `type`).
  - Cek pending manual yang masih hidup untuk (type + ref) yang sama → resume kalau ada.
    **Penting:** query resume harus menambah `AND payment_type='manual_transfer'` supaya
    tak me-resume row QRIS sebagai manual. Sebaliknya, cabang QRIS yang sudah ada harus
    ditambah `AND (payment_type IS NULL OR payment_type<>'manual_transfer')` supaya tak
    me-resume row manual sebagai QRIS. Tanpa filter ini, row manual & QRIS untuk bundle
    yang sama bisa saling bajak.
  - Kalau belum ada: generate kode unik, `amount = dasar + kode`, `expires_at =
    date('Y-m-d H:i:s', time() + manual_payment_expiry_hours*3600)`, insert row
    `payment_type='manual_transfer'`, `qr_string=NULL`, `raw_response` = JSON
    `{manual:true, base_amount, unique_code, bank:{...}}`.
  - Render **kartu instruksi manual** (bukan QR): nama bank, no rekening (tombol Salin),
    nama pemilik, **nominal persis** (tombol Salin), Order ID sebagai berita transfer,
    catatan "transfer nominal PERSIS termasuk 3 angka terakhir", tombol **"Saya sudah
    transfer"**, dan status polling.
- **Endpoint `action=notify_sa`:** dipanggil tombol "Saya sudah transfer". Scope tenant
  (row milik tenant session, status pending, manual). Fire `SaNotifier::manualPaymentSubmitted($paymentId)`
  best-effort. Balas JSON `{ok:true}`. Tidak mengubah status pembayaran.
- **Fallback QRIS graceful:** ganti `die('Gagal generate payment: …')` menjadi render
  kartu error ramah. Kalau jalur manual aktif, sertakan tombol **"Transfer Manual"**
  (link ke `?type=…&method=manual` mempertahankan param ref). Ini menyelamatkan gate
  `setup_fee` dari jalan buntu.

### 2. `superadmin/payments.php` (modifikasi)
- Untuk row `payment_type='manual_transfer'` & `status='pending'`: tampilkan badge
  "Transfer Manual" + 2 tombol **"Konfirmasi Lunas"** dan **"Tolak"**.
- **Handler Konfirmasi** (POST, CSRF `X-CSRF-Token`, audit): validasi row = manual &
  pending; set `status='paid', paid_at=NOW()`; panggil `PaymentSettler::settle($id)`;
  balas hasil (coin ditambah / aktivasi). Idempoten (double-click aman: settle no-op
  kalau ledger sudah ada; guard `status='pending'` di UPDATE mencegah re-settle).
- **Handler Tolak** (POST, CSRF, audit): set `status='cancelled'`. Tanpa kredit.
- Tampilkan `base_amount`/`unique_code` (dari `raw_response`) supaya SA lihat "Rp 20.000
  + kode 137 = Rp 20.137" untuk mempermudah pencocokan.

### 3. `superadmin/billing-config.php` (modifikasi)
- Section baru "Pembayaran Manual (Transfer Bank)": switch enabled + 3 field rekening +
  expiry hours. Simpan via `BillingConfig::set()`.

### 4. Entry points (modifikasi — tambah tombol "Transfer Manual")
Tombol hanya dirender jika jalur manual aktif (helper cek config).
- `hq/coin-info.php` (baris ~346): di tiap kartu bundle, tombol kedua
  `?type=topup_coin&bundle_id=X&method=manual`.
- `hq/outlet.php` (baris ~446-447): tombol aktivasi outlet tambah varian
  `&method=manual`.
- `add-outlet.php` (baris ~285): redirect setelah tambah outlet — biarkan ke checkout;
  tombol manual muncul di halaman checkout via fallback/pilihan.
- Gate `setup_fee`: ditangani lewat fallback QRIS graceful di `billing-checkout.php`
  (tombol "Transfer Manual" muncul saat QRIS gagal) — tenant baru tak buntu.

### 5. `core/SaNotifier.php` (modifikasi)
- Method baru `manualPaymentSubmitted(int $paymentId)`: best-effort, kirim notif ke SA
  ("Transfer manual masuk — {tenant} — Rp {amount} — order {order_id}, mohon dicek &
  konfirmasi"). Mengikuti pola method SA-notifier yang ada. Semua dibungkus try/catch
  agar tak menggagalkan request owner.

## Error Handling

- Charge QRIS gagal → kartu error ramah + tombol manual (bukan `die` mentah).
- Endpoint manual dengan config nonaktif → tolak (redirect / pesan).
- SA konfirmasi row yang bukan manual/pending → tolak dengan pesan, tak ada efek.
- `settle()` gagal setelah `status=paid` → tampilkan error ke SA; status tetap paid,
  SA bisa retry konfirmasi (settle idempoten, akan menuntaskan kredit yang belum jadi).
- Semua handler SA: CSRF wajib, audit log, `apiErr()` untuk exception.

## Testing (PHP, pola `tests/billing/`)

1. **Kode unik:** dua row manual pending untuk nominal dasar sama menghasilkan `amount`
   berbeda (total unik).
2. **Konfirmasi → kredit sekali:** buat row manual topup pending → konfirmasi → coin
   bertambah sesuai bundle; konfirmasi lagi (double) → coin **tidak** bertambah dua kali.
3. **Tolak → tanpa kredit:** row manual pending → tolak → `status=cancelled`, coin tak
   berubah, tak ada `coin_ledger`.
4. **Config nonaktif:** helper "jalur manual aktif" mengembalikan false saat
   `manual_payment_enabled=0` atau rekening kosong.
5. **Aktivasi outlet via manual:** row manual `outlet_activation` pending → konfirmasi →
   outlet `status=active` + bonus coin sesuai config (idempoten).

## Yang TIDAK termasuk (YAGNI)

- Upload bukti transfer (dipilih: verifikasi via kode unik + cek mutasi).
- Auto-match mutasi bank / integrasi rekening (SA cocokkan manual).
- Owner self-confirm (hanya SA).
- Refund manual (di luar scope fallback sementara).

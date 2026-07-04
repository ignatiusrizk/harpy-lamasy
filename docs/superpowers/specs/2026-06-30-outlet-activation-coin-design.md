# Outlet Activation: Server Config + Bonus Coin — Design Spec

> LAMASY. Tanggal: 2026-06-30.

## Goal

Jadikan **biaya aktivasi outlet, diskon, dan bonus coin** sebagai **satu config server** (di SuperAdmin), lalu pakai config itu secara konsisten di alur **owner self-serve "Tambah Outlet"** (outlet ke-2+): tampilkan biaya nyata (fee −diskon) + bonus coin + rincian yang didapat, dan **benar-benar kreditkan coin** saat outlet diaktifkan.

## Latar / Masalah

Ada dua jalur aktivasi yang tidak konsisten:

1. **Wizard Registrasi (SA onboarding klien)** — coin dikreditkan saat SA konfirmasi bayar (`coin_dikreditkan`). Nilai default ("Edit Default Aktivasi": fee, diskon, coin) disimpan di **localStorage browser SA** (`sa_activation_defaults`, [packages.php:333](../../superadmin/packages.php)) — bahkan tidak dirujuk oleh `registration_wizard.php` (config menggantung).
2. **Owner self-serve "Tambah Outlet"** ([add-outlet.php](../../add-outlet.php), outlet ke-2+) — [`settleOutletActivation`](../../core/PaymentSettler.php) cuma set `status='active'`, **0 coin**. Biaya tampil samar "Setup fee (sesuai paket)"; fee sebenarnya dari config server `outlet_activation_fee` (Rp 800.000), tanpa diskon, tanpa coin.

Akibatnya: nilai coin 100.000 yang di-set SA **tidak pernah sampai** ke aktivasi outlet owner. Janji "bonus coin aktivasi" (landing.php) tidak terimplementasi di jalur owner.

## Keputusan Desain (terkunci)

- **Satu sumber kebenaran = config server** di `saas_billing_config`:
  - `outlet_activation_fee` (sudah ada, default `800000`)
  - `outlet_activation_discount` (**baru**, default `0`, satuan persen 0–100)
  - `outlet_activation_coin` (**baru**, default `100000`)
- **Modal SA "Edit Default Aktivasi"** ([packages.php](../../superadmin/packages.php)) berhenti pakai localStorage → **load & save ke config server** via endpoint baru. localStorage `sa_activation_defaults` dihapus.
- **Owner flow** memakai ketiga nilai: biaya = `round(fee × (1 − diskon/100))`; coin = `outlet_activation_coin`.
- **Coin dikreditkan** di `settleOutletActivation` (idempoten via `coin_ledger.payment_id`, pola `settleSetupFee`).
- **billing-checkout** outlet_activation menerapkan diskon yang sama → tagihan = biaya net (tidak ada selisih dengan yang ditampilkan di konfirmasi).
- **Rincian "yang didapat"** di konfirmasi owner = fakta dari kode provisioning (bukan klaim mengarang).
- **Carry-over coin trial**: **di luar scope** versi ini (bisa menyusul). Yang dikreditkan hanya `outlet_activation_coin`.
- **Wizard registrasi**: pre-fill default Step 2 (fee/diskon/coin) dibaca dari config server yang sama (menggantikan default hardcoded `50000`). Perilaku override per-klien & alur kredit existing **tidak berubah**.

## Komponen & File

| File | Aksi | Tanggung jawab |
|---|---|---|
| `saas_billing_config` | DATA | Tambah 2 row: `outlet_activation_discount` (0), `outlet_activation_coin` (100000). Idempoten (INSERT IGNORE / ON DUPLICATE). |
| `superadmin/packages.php` | MODIFY | Modal "Edit Default Aktivasi": ganti load/save localStorage → endpoint server `?action=save_activation_defaults` (POST, CSRF) + `?action=get_activation_defaults`. Kartu display baca dari server. Hapus `DEF_KEY`/localStorage. |
| `billing-checkout.php` | MODIFY | Blok `outlet_activation`: `$fee = BillingConfig::getInt('outlet_activation_fee',800000); $disc = BillingConfig::getInt('outlet_activation_discount',0); $amount = (int)round($fee*(1-$disc/100));` |
| `core/PaymentSettler.php` | MODIFY | `settleOutletActivation`: setelah set `status='active'`, kreditkan `outlet_activation_coin` ke `tenants.coin_balance` + insert `coin_ledger` (type `topup`, `payment_id` idempotency, `feature_used='outlet_activation'`). Idempoten: skip kalau sudah ada ledger untuk `payment_id`. |
| `add-outlet.php` | MODIFY | Step 2 konfirmasi: baris "Biaya" → "Rp {net}" (atau "Gratis" jika 0) + (jika diskon>0) tampil coret fee asli + "−{disc}%"; tambah baris "Bonus coin" = `outlet_activation_coin`; tambah blok rincian "Yang kamu dapatkan". |
| `superadmin/registration_wizard.php` | MODIFY (kecil) | Default Step 2 (`coin_awal`, `discount_pct`, `setup_fee`) saat belum di-set → ambil dari config server (`outlet_activation_coin/_discount/_fee`) alih-alih hardcoded `50000`. |

## Data — Config server

```sql
INSERT INTO saas_billing_config (key_name, value_text, description)
VALUES
 ('outlet_activation_discount', '0',      'Diskon aktivasi outlet (%) 0-100'),
 ('outlet_activation_coin',     '100000', 'Bonus coin dikreditkan saat aktivasi outlet')
ON DUPLICATE KEY UPDATE key_name = key_name;  -- no-op kalau sudah ada
```

Baca via `BillingConfig::getInt('outlet_activation_coin', 100000)` dst.

## Alur

### Owner Tambah Outlet (outlet ke-2+, berbayar)
1. Step 2 konfirmasi tampil: Biaya = net (fee−diskon), Bonus coin = `outlet_activation_coin`, rincian fitur.
2. "Buat Outlet" → outlet `status='pending'` → redirect `billing-checkout?type=outlet_activation` (tagihan = net, **sama** dengan yang ditampilkan).
3. Pembayaran settle (Midtrans success / SA konfirmasi) → `settleOutletActivation`:
   - set `status='active'`, `activated_at=NOW()`;
   - kreditkan `outlet_activation_coin` ke `tenants.coin_balance`;
   - insert `coin_ledger` (topup, payment_id) — **idempoten**.

### SA atur default
1. SuperAdmin → Packages → "Edit Default Aktivasi" → ubah fee/diskon/coin → Simpan → tersimpan ke `saas_billing_config` (server).
2. Nilai langsung dipakai owner flow + jadi default pre-fill wizard registrasi.

## Idempotency & Edge Cases

| Kondisi | Perilaku |
|---|---|
| Settle dipanggil 2× untuk payment sama | `coin_ledger` sudah ada untuk `payment_id` → skip kredit (no double-coin). Pola identik `settleSetupFee` ([PaymentSettler.php:117](../../core/PaymentSettler.php)). |
| Outlet sudah `active` saat settle | Existing early-return "already active" — pastikan tetap tidak double-credit (cek ledger sebelum kredit). |
| `outlet_activation_coin` = 0 | Tidak ada kredit, tidak ada baris ledger; konfirmasi tidak menampilkan baris bonus coin. |
| `outlet_activation_fee` = 0 (gratis) | Biaya tampil "Gratis"; diskon diabaikan; coin tetap dikreditkan jika >0. |
| diskon di luar 0–100 | Clamp `max(0, min(100, disc))` saat baca & saat simpan. |
| Mode trial (outlet pertama) | Tidak berubah: "Gratis" + 10.000 coin trial (existing). Config ini hanya untuk aktivasi berbayar. |

## Keamanan

- Endpoint `save_activation_defaults` di `packages.php` = SuperAdmin only (guard existing) + `verifyCsrf()`. Validasi numerik (fee/coin ≥ 0 int, diskon 0–100).
- Kredit coin server-authoritative (settle), bukan dari input klien. Owner flow hanya **menampilkan**; nilai sebenarnya dibaca server saat settle.
- Tidak ada nilai sensitif baru; config angka publik-internal.

## Testing

### PHP unit (`tests/` pola `_assert.php`)
- `BillingConfig::getInt('outlet_activation_coin', 100000)` mengembalikan nilai tersimpan; default saat key absen.
- Diskon: `round(1000000 × (1−20/100)) == 800000`.
- `settleOutletActivation`: (a) outlet pending → active + coin_balance bertambah `outlet_activation_coin` + 1 baris `coin_ledger`; (b) settle 2× uuid/payment sama → coin tetap 1× (idempoten); (c) `outlet_activation_coin=0` → status active tanpa baris ledger.
- billing-checkout amount untuk outlet_activation == net (fee−diskon).

### Manual / MCP
- SA Packages → Edit Default Aktivasi → simpan fee/diskon/coin → reload → nilai persist (dari server, bukan localStorage).
- Owner Tambah Outlet → konfirmasi menampilkan biaya net + bonus coin + rincian; nominal == tagihan billing-checkout.
- Setelah pembayaran dikonfirmasi → saldo coin tenant bertambah sesuai config; tampil di ledger/client_detail.

### Lint
- `php -l` semua file PHP yang disentuh.

## Out of Scope

- Carry-over sisa coin trial ke saldo aktivasi (menyusul).
- Diskon per-klien di owner flow (owner pakai diskon global config; per-klien tetap hanya di wizard SA).
- Perubahan harga/diskon paket (`saas_packages`) — terpisah.
- Mengubah mekanisme settle setup_fee / topup.

## References
- [[project-lamasy]] — coin model & billing
- [add-outlet.php](../../add-outlet.php), [billing-checkout.php](../../billing-checkout.php), [core/PaymentSettler.php](../../core/PaymentSettler.php), [superadmin/packages.php](../../superadmin/packages.php), [core/BillingConfig.php](../../core/BillingConfig.php)
- Config: `saas_billing_config` (key_name/value_text)

# Tata-Kelola Coin Per-Outlet — Design Spec

> LAMASY. Tanggal: 2026-06-30.

## Goal

Beri owner kontrol penuh atas **Mode Coin** (`shared` ↔ `per_outlet`) dengan migrasi saldo yang aman, dan buat fitur **HQ-level** memotong coin dari **outlet penanggung yang eksplisit** (default UTAMA, bisa di-override) saat mode `per_outlet` — bukan dari "outlet yang kebetulan aktif di sesi". Sekaligus menutup bug: ganti mode saat ini tidak memindahkan saldo (coin nyangkut).

## Latar / Masalah

- `CoinLedger::deduct()` menentukan sumber dari `coin_mode` + outlet aktif sesi (`TenantResolver::outletId()`).
- Di `per_outlet`, fitur HQ tak punya outlet alami → sesi default ke outlet UTAMA, tapi bisa juga "outlet terakhir yang owner masuki" → **tidak prediktabel**.
- Ganti `coin_mode` (SA, `superadmin/client_detail.php:244` `UPDATE tenants SET coin_mode=?`) **tidak memigrasi saldo** → coin nyangkut di pool lama. **Bug laten.**
- `per_outlet` **belum dipakai tenant mana pun** (semua `shared`) → fitur ini forward-looking + perbaikan bug, bukan darurat.

## Keputusan Desain (terkunci)

- **Kontrol mode:** Owner boleh ganti **kapan saja** (bukan SA-only). Migrasi saldo wajib ditangani.
- **Migrasi `shared → per_outlet`:** seluruh `tenants.coin_balance` → outlet **UTAMA**; outlet lain mulai 0.
- **Migrasi `per_outlet → shared`:** jumlahkan semua `outlets.coin_balance` → `tenants.coin_balance`; outlet jadi 0.
- **Sumber coin fitur HQ (per_outlet):** outlet penanggung eksplisit (`tenants.hq_coin_outlet_id`), default UTAMA, owner bisa override. Di `shared` perilaku tak berubah (tenant pool).
- **Klasifikasi fitur** (terkunci dari audit call-site):
  - **HQ-level** (pakai outlet penanggung): `hq/broadcast.php` (`wa_blast`), `hq/laporan.php` (`ai_insight_laporan`, `export_pdf`), `hq/ai-chat.php` (`ai_chat_data`), `hq/ai-churning.php` (`ai_churn_message`), `ai.php` (`ai_briefing_hq`, `ai_chat_data`, `ai_upselling`).
  - **Outlet-level** (pakai outlet transaksi/sesi, **tak disentuh**): `pos.php` (`send_wa_nota`), `api/voice_order_parse.php` (`ai_voice_order`), `api/kas_struk_scan.php` (`ai_kas_struk`), `piutang.php` (`invoice_b2b`, `reminder_piutang`), `laporan.php` (`ai_insight_laporan` outlet), `import.php` (`ai_migration_mapping`), `core/StrukGenerator.php`, `core/Notifier.php`.

## Arsitektur

### Data model
- `tenants.coin_mode` ENUM('shared','per_outlet') — sudah ada.
- **`tenants.hq_coin_outlet_id` INT NULL** (baru) — outlet penanggung coin HQ. NULL = pakai outlet UTAMA.
- `tenants.coin_balance` (pool shared / target konsolidasi) + `outlets.coin_balance` (per-outlet) + `outlets.trial_coin_balance` — sudah ada.
- `coin_ledger` (sudah ada) — semua perpindahan migrasi dicatat di sini untuk audit.

### Komponen baru
**`core/CoinModeManager.php`** — satu jalur untuk ganti mode + migrasi:
```
CoinModeManager::switchMode(int $tenantId, string $newMode, string $actor): array
  // return ['ok'=>bool, 'moved'=>int, 'from'=>string, 'to'=>string, 'error'=>?string]
```
- Validasi `$newMode` ∈ {shared, per_outlet}; no-op bila sama dengan mode sekarang.
- Transaksional (`beginTransaction` + `FOR UPDATE` saldo terkait).
- **shared → per_outlet:** baca `tenants.coin_balance` (lock) → pilih outlet UTAMA (`is_main DESC, id ASC`, status≠closed) → tambah ke `outlets.coin_balance` UTAMA → set `tenants.coin_balance=0` → `UPDATE coin_mode`. Tulis 2 entri `coin_ledger`: `deduct` tenant (outlet_id=0, desc "Migrasi mode → per_outlet") + `topup` outlet UTAMA (desc sama). `moved` = jumlah dipindah.
- **per_outlet → shared:** SUM semua `outlets.coin_balance` (lock baris) → tambahkan ke `tenants.coin_balance` → set tiap `outlets.coin_balance=0` → `UPDATE coin_mode`. Tulis entri ledger per outlet (`deduct` outlet) + 1 `topup` tenant. (Catatan: `trial_coin_balance` **tidak** ikut dimigrasi — itu kuota trial per outlet, tetap di outletnya.)
- `actor` ('owner:<uid>' / 'sa:<uid>') masuk ke deskripsi ledger.

### Perubahan `core/CoinLedger.php`
- **`deductHq(string $feature, ?string $refId=null, ?int $overrideCost=null): bool`** dan **`canAffordHq(string $feature): bool`**.
  - Resolusi sumber: bila `shared` → identik `deduct()`/`canAfford()` sekarang (tenant pool). Bila `per_outlet` → sumber = outlet penanggung:
    - `hqBillingOutletId(tenantId)`: `tenants.hq_coin_outlet_id` bila tidak NULL **dan** outlet itu milik tenant & status≠closed; else outlet UTAMA (`is_main DESC, id ASC`, status≠closed); else outlet aktif pertama.
  - `deductHq` memotong `outlets.coin_balance` outlet penanggung (transaksional, `FOR UPDATE`), catat di `coin_ledger` (outlet_id = penanggung, feature_used = $feature).
  - **Tidak** menyentuh `trial_coin_balance` (fitur HQ tak pakai jatah trial outlet).
- `deduct()`/`canAfford()` existing **tidak diubah** (call-site outlet-level tetap).

### Perubahan call-site HQ (re-tag ke deductHq/canAffordHq)
- `hq/broadcast.php:47`, `hq/laporan.php:491` & `:535`, `hq/ai-chat.php:32` & `:48`, `hq/ai-churning.php:74` & `:111`, `ai.php:261` & `:287` & `:334` & `:371` & `:478`.
- Mengganti `CoinLedger::deduct(` → `CoinLedger::deductHq(` dan `canAfford(` → `canAffordHq(` pada baris-baris itu (tanda tangan argumen sama).

### UI owner — `hq/billing.php`
- Kartu **"Mode Coin"**: tampil mode aktif + toggle. Klik → **modal konfirmasi** yang menampilkan dampak persis:
  - shared→per_outlet: "Rp/`N` coin saldo tenant akan dipindah ke outlet UTAMA (`<nama>`). Outlet lain mulai 0."
  - per_outlet→shared: "Total `N` coin dari semua outlet akan digabung jadi saldo tenant."
- Action `set_coin_mode` (POST, `verifyCsrf()`, `isOwnerLevel`) → panggil `CoinModeManager::switchMode(..., 'owner:'.$uid)`.
- Saat mode `per_outlet`: tampil setelan **"Outlet penanggung coin HQ"** (dropdown outlet aktif, default UTAMA terpilih bila NULL). Action `set_hq_coin_outlet` (POST, CSRF, owner-only) → `UPDATE tenants SET hq_coin_outlet_id=?` (validasi outlet milik tenant & aktif).

### Perubahan SA — `superadmin/client_detail.php`
- Action `set_coin_mode` existing diganti memanggil `CoinModeManager::switchMode(..., 'sa:'.$saId)` (bukan `UPDATE` polos) → menutup bug migrasi.

## Alur

### Owner ganti mode
1. Owner buka `hq/billing` → kartu Mode Coin → toggle → modal konfirmasi. Angka "saldo yang dipindah" dihitung **dari data saldo yang sudah dimuat halaman** (`hq/billing` sudah menampilkan saldo tenant + saldo tiap outlet) — **tanpa endpoint baru**. Nilai final tetap dihitung ulang server-side di `switchMode` saat submit (otoritatif).
2. Submit → `switchMode` transaksional → migrasi saldo + ledger → mode berubah.
3. UI refresh: saldo & badge ter-update.

### Fitur HQ konsumsi coin (per_outlet)
1. Owner pakai fitur HQ (mis. broadcast) → `canAffordHq` cek saldo outlet penanggung → `deductHq` potong dari outlet penanggung.
2. Shared: identik perilaku sekarang (tenant pool).

## Error Handling / Edge Cases

| Kondisi | Perilaku |
|---|---|
| `hq_coin_outlet_id` menunjuk outlet closed/bukan milik tenant | Fallback outlet UTAMA |
| Outlet UTAMA closed | Pakai outlet aktif pertama (`is_main DESC, id ASC`, status≠closed) |
| Tidak ada outlet aktif sama sekali | `deductHq` return false (saldo dianggap 0); fitur HQ ditolak |
| Ganti mode saat saldo 0 | Migrasi no-op, mode tetap berubah, tak ada entri ledger |
| `trial_coin_balance` outlet | Tidak ikut migrasi; tetap diprioritaskan untuk fitur outlet-level (perilaku lama) |
| Ganti mode bolak-balik | Konservasi saldo (jumlah total kekal); aman diulang |
| Balapan (2 request) | `FOR UPDATE` + transaksi melindungi |

## Keamanan

- `set_coin_mode` & `set_hq_coin_outlet`: `verifyCsrf()` + `isOwnerLevel` (owner-only, bukan manajer). Tenant-scoped (`WHERE id = TenantResolver::id()`).
- `switchMode` & `deductHq` tenant-scoped; tak percaya outlet_id dari klien (resolusi server-side).
- SA path tetap lewat guard SA existing.

## Testing

### Unit (PHP, `tests/coin/`)
- `CoinModeManager::switchMode`:
  - (a) shared→per_outlet: tenant pool → outlet UTAMA, tenant=0, 2 ledger, `moved` benar.
  - (b) per_outlet→shared: SUM outlet → tenant, tiap outlet=0, ledger per outlet.
  - (c) konservasi: total saldo sebelum == sesudah (kedua arah).
  - (d) no-op saat mode sama; no-op migrasi saat saldo 0.
  - (e) `trial_coin_balance` tidak ikut termigrasi.
- `CoinLedger::deductHq`/`canAffordHq`:
  - (f) shared → potong `tenants.coin_balance` (identik deduct).
  - (g) per_outlet → potong outlet penanggung (`hq_coin_outlet_id`).
  - (h) penanggung NULL → outlet UTAMA.
  - (i) penanggung closed → fallback UTAMA.
  - (j) saldo penanggung < cost → false, tak memotong.
- Rollback DB di tiap test (pola `tests/_assert.php`).

### Lint
- `php -l` semua file disentuh.

### Manual (MCP/owner)
- Owner toggle mode di `hq/billing` → konfirmasi → saldo pindah sesuai.
- Set outlet penanggung → pakai fitur HQ → coin terpotong dari outlet itu.

## Out of Scope

- Transfer coin antar-outlet manual (fitur terpisah; "all to UTAMA" cukup untuk v1).
- Owner alokasi manual saat migrasi (dipilih: all-to-UTAMA).
- Mengubah harga/fitur coin.
- Mode ketiga (mis. hybrid). Hanya shared/per_outlet.
- Migrasi `trial_coin_balance`.

## Files Inventory

**New:**
- `core/CoinModeManager.php`
- `tests/coin/test_coin_mode_switch.php`, `tests/coin/test_deduct_hq.php`
- Migration: `ALTER TABLE tenants ADD COLUMN hq_coin_outlet_id INT NULL`.

**Modify:**
- `core/CoinLedger.php` (+`deductHq`, `canAffordHq`, `hqBillingOutletId`)
- `hq/billing.php` (UI mode + penanggung + 2 action)
- `superadmin/client_detail.php` (set_coin_mode → CoinModeManager)
- HQ call-sites: `hq/broadcast.php`, `hq/laporan.php`, `hq/ai-chat.php`, `hq/ai-churning.php`, `ai.php`

## References
- [[project-lamasy]], `core/CoinLedger.php`, `core/TenantResolver.php`
- Audit call-site coin (di Goal/Keputusan).

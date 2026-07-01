# Welcome Kit — Model Pilihan (Owner Pilih Kit) — Design Spec

> LaMaSy. Tanggal: 2026-06-30. Enhancement dari [[project-welcome-kit]].

## Goal

Owner memilih **welcome kit mana** yang ingin diterima (dari beberapa opsi yang disediakan SuperAdmin, **semua gratis, beda isi**) saat konfirmasi Tambah Outlet. Pilihan disimpan di outlet dan dipakai saat kit dibuat pada settle pembayaran. Menggantikan model kit tunggal.

## Latar

Welcome kit sudah live (single config `welcome_kit_items`, snapshot saat settle di `WelcomeKit::createForOutlet`, antrian SA). Sekarang jadi **multi-opsi**: SA definisikan beberapa paket kit, owner pilih satu. Semua opsi gratis (didanai fee aktivasi) — murni preferensi.

Kondisi existing yang dipakai/diubah:
- Config `saas_billing_config`: `welcome_kit_enabled` (tetap), `welcome_kit_items` (tunggal → diganti `welcome_kit_options`).
- `core/WelcomeKit.php`: `enabled()`, `items()`, `createForOutlet()` (snapshot), `listQueue/markShipped/markDelivered/statusForOutlet`.
- `outlets`: `penerima`, `kode_pos` sudah ada. Tambah `welcome_kit_choice`.
- Owner pilih di `add-outlet.php` step 2. SA kelola di `superadmin/welcome_kit.php`.

## Keputusan Desain (terkunci)

- **Opsi kit = daftar** di config `welcome_kit_options` (JSON): tiap opsi `{key, nama, items:[{nama,qty}]}`; satu `default:true`. Migrasi: kit lama → opsi `{key:"standar", nama:"Standar", items:<lama>, default:true}`.
- **Owner pilih di konfirmasi Tambah Outlet** (step 2), hanya mode **paid** (trial tak dapat kit). Disimpan ke `outlets.welcome_kit_choice` (= `key` opsi).
- **Snapshot saat settle**: `createForOutlet` baca `outlets.welcome_kit_choice` → snapshot **nama opsi + items** ke record (`items_json` + kolom baru `kit_nama`). Fallback ke opsi default bila choice kosong/tak valid. Snapshot dibekukan.
- **Idempotency & best-effort tidak berubah** (payment_id UNIQUE, post-commit try/catch).
- **Wizard registrasi SA** (outlet pertama SA-onboard): pakai opsi **default** (owner tak pilih di alur itu). SA-picker di luar scope versi ini.
- **1 opsi saja** → pemilih auto-select (tanpa radio). `enabled=off` → tak ada kit, pemilih disembunyikan.
- Semua opsi **gratis & setara** — tak ada tier/harga.

## Komponen & File

### Data
| Objek | Aksi | Detail |
|---|---|---|
| `saas_billing_config` | DATA | Tambah/ubah: `welcome_kit_options` (JSON array opsi). Migrasi nilai `welcome_kit_items` lama → 1 opsi default. `welcome_kit_items` boleh ditinggal (tak dibaca lagi) atau dihapus. |
| `outlets` | ALTER | `ADD COLUMN welcome_kit_choice VARCHAR(40) NULL` (key opsi terpilih). |
| `saas_welcome_kit` | ALTER | `ADD COLUMN kit_nama VARCHAR(80) NULL` (nama opsi ter-snapshot, utk tampil di antrian). |

### Kode
| File | Aksi | Tanggung jawab |
|---|---|---|
| `core/WelcomeKit.php` | MODIFY | Ganti `items()` → **`options(): array`** (list opsi tervalidasi, decode `welcome_kit_options`), `defaultOption(): ?array`, `optionByKey(string $key): ?array`. `enabled()` tetap. `createForOutlet(...)`: baca `outlets.welcome_kit_choice` → pilih opsi (choice→default), snapshot `kit_nama` + `items_json`. Sisakan back-compat: bila hanya config lama `welcome_kit_items` yang ada, bungkus jadi 1 opsi default. `statusForOutlet`/`listQueue` sertakan `kit_nama`. |
| `add-outlet.php` | MODIFY | Step 2 (mode paid, enabled): render **pemilih opsi** (radio, nama + preview items). Simpan pilihan: pada `step2_submit`, tulis `outlets.welcome_kit_choice` = key terpilih (validasi key ada di options; else default). 1 opsi → auto (hidden input). "Yang kamu dapatkan" tampil opsi terpilih. |
| `superadmin/welcome_kit.php` | MODIFY | Editor config: kelola **banyak opsi** (tiap opsi: nama + daftar item nama/qty + tandai default; tambah/hapus opsi). API `get_config`/`save_config` pakai `welcome_kit_options`. Antrian: kolom **Kit** tampil `kit_nama` + isi. |
| `superadmin/sql/welcome_kit_options_migration.sql` | **NEW** | ALTER outlets + saas_welcome_kit + migrasi config lama→options. |

## Data — Format

`welcome_kit_options` (config JSON):
```json
[
  {"key":"standar","nama":"Standar","default":true,
   "items":[{"nama":"Roll kertas thermal 58mm","qty":2},{"nama":"Plastik packing","qty":1},{"nama":"Solasi roll","qty":1}]},
  {"key":"printer","nama":"Paket Printer",
   "items":[{"nama":"Roll kertas thermal 58mm","qty":4}]},
  {"key":"packing","nama":"Paket Packing",
   "items":[{"nama":"Plastik packing","qty":3},{"nama":"Solasi roll","qty":2},{"nama":"Tali rafia","qty":1}]}
]
```

## Alur

1. **Add outlet (paid)** → step 2: pemilih kit (default terpilih awal). Owner pilih → `step2_submit` simpan `outlets.welcome_kit_choice`.
2. **Settle** → `createForOutlet`: opsi = `optionByKey(choice)` ?? `defaultOption()`; snapshot `kit_nama` + `items_json`; INSERT record `pending`. (idempoten, best-effort — tak berubah)
3. **SA antrian**: tampil `kit_nama` + isi → packing sesuai pilihan → Dikirim.
4. **Owner**: status kit + nama kit terpilih.

## Error Handling / Edge Cases

| Kondisi | Perilaku |
|---|---|
| `welcome_kit_choice` kosong/tak valid saat settle | Pakai `defaultOption()`. Bila tak ada opsi sama sekali → tak buat record (log). |
| Config masih format lama (`welcome_kit_items` saja) | `options()` bungkus jadi 1 opsi default (back-compat) sampai migrasi jalan. |
| Opsi dihapus SA setelah owner pilih tapi sebelum settle | choice tak match → fallback default. |
| Opsi dihapus setelah kit dibuat | Aman — snapshot `kit_nama`+`items_json` sudah beku. |
| 1 opsi | Pemilih auto-select opsi itu (radio disembunyikan / read-only). |
| `enabled=off` | Pemilih disembunyikan, tak buat kit. |
| Trial | Tak ada kit, tak ada pemilih. |
| Wizard SA (outlet pertama) | `welcome_kit_choice` tak di-set → settle pakai default. |

## Keamanan

- `save_config` SA: validasi tiap opsi (`nama` wajib, `items` valid, `qty≥1`, `key` unik/di-generate, tepat 1 default atau default = opsi pertama). Guard SA + `saVerifyCsrf()`.
- `outlets.welcome_kit_choice`: server validasi key ada di options saat simpan (else default) — tak percaya input mentah.
- Kit tetap gratis & server-authoritative; owner hanya memilih key.

## Testing

### PHP unit (`tests/welcomekit/`)
- `WelcomeKit::options()` decode multi-opsi; format lama → 1 opsi default (back-compat); JSON invalid → [].
- `defaultOption()`/`optionByKey()` benar; default fallback saat key tak match.
- `createForOutlet`: (a) outlet dgn `welcome_kit_choice='printer'` → record `kit_nama='Paket Printer'` + items opsi printer; (b) choice kosong → opsi default; (c) idempoten payment_id (tetap 1); (d) enabled=off → 0.
- Simpan choice di add-outlet: key valid tersimpan; key palsu → default.

### Manual / MCP
- SA buat 3 opsi → add-outlet paid → pemilih tampil 3 opsi + preview → pilih "Paket Printer" → bayar/settle → antrian SA tampil "Paket Printer" + 4 roll thermal.
- 1 opsi → pemilih auto. enabled=off → pemilih hilang.

### Lint
- `php -l` semua file yang disentuh.

## Out of Scope

- Tier/harga berbeda, upgrade berbayar, kit per-paket langganan.
- SA memilihkan kit di wizard registrasi (pakai default).
- Owner ganti pilihan setelah settle (snapshot beku).
- Retroaktif untuk kit yang sudah tercatat.

## References
- [[project-welcome-kit]] — fitur dasar
- [[project-outlet-activation-coin]] — pola config server + snapshot settle
- `core/WelcomeKit.php`, `add-outlet.php`, `superadmin/welcome_kit.php`, `saas_billing_config`, `saas_welcome_kit`, `outlets`

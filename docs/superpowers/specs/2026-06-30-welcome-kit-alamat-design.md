# Welcome Kit Fisik + Alamat Outlet Wajib — Design Spec

> LaMaSy. Tanggal: 2026-06-30.

## Goal

Saat outlet **jadi aktif (berbayar)**, sistem otomatis mencatat **welcome kit fisik terutang** (mis. roll kertas thermal, plastik packing, solasi) dengan snapshot alamat + isi kit, masuk **antrian fulfillment SuperAdmin** (pending → dikirim + resi → terkirim). Owner bisa melihat status kit-nya. Agar bisa dikirim, **alamat outlet dijadikan lengkap & wajib** saat pembuatan/aktivasi outlet.

Packing & pengiriman fisik tetap **manual** oleh tim; sistem hanya menyimpan data (alamat, isi kit) dan melacak status.

## Latar

- `outlets`: `alamat` (text, nullable), `kota` (nullable), `telepon` (nullable). **Belum** ada nama penerima / kode pos, dan alamat **tidak wajib** ([add-outlet.php](../../add-outlet.php) hanya `nama_outlet` required; [registration_wizard.php](../../superadmin/registration_wizard.php) tak punya field alamat lengkap).
- Bonus coin aktivasi baru saja dibangun (idempoten via `coin_ledger.payment_id` di `settleSetupFee`/`settleOutletActivation`) — welcome kit mengikuti pola trigger & idempotency yang sama. Lihat [[project-outlet-activation-coin]].

## Keputusan Desain (terkunci)

- **Trigger:** kit dibuat saat outlet **aktif berbayar** — di `PaymentSettler::settleSetupFee` (outlet pertama / pendaftaran setelah bayar) dan `PaymentSettler::settleOutletActivation` (outlet ke-2+). **Bukan** saat signup belum bayar. Trial tidak dapat kit.
- **Idempoten:** 1 kit per aktivasi, di-guard `saas_welcome_kit.payment_id` UNIQUE.
- **Snapshot:** alamat & isi kit dibekukan ke record saat dibuat; edit alamat/config setelahnya tidak mengubah kiriman yang sudah tercatat.
- **Isi kit dikonfigurasi SuperAdmin** (bukan hardcode, bukan per-paket): config server global.
- **Alamat lengkap wajib** untuk outlet baru: Nama penerima, No. HP, Alamat lengkap, Kota/Kabupaten, Kode Pos. Berlaku ke depan (tidak retroaktif memaksa outlet lama).
- **Fulfillment tracking:** antrian + status kirim (kurir + resi). Tanpa integrasi API ekspedisi/ongkir/inventori.
- Kit **gratis** (didanai fee aktivasi) — tidak ada charge/coin untuk kit.
- Toggle `welcome_kit_enabled`: kalau off, tidak buat record.

## Komponen & File

### Data
| Objek | Aksi | Detail |
|---|---|---|
| `outlets` | ALTER | `ADD COLUMN penerima VARCHAR(120) NULL`, `ADD COLUMN kode_pos VARCHAR(10) NULL`. (`alamat`/`kota`/`telepon` sudah ada; `telepon` dipakai sebagai No. HP.) |
| `saas_billing_config` | DATA | Row `welcome_kit_enabled` (default `1`), `welcome_kit_items` (default JSON `[{"nama":"Roll kertas thermal 58mm","qty":2},{"nama":"Plastik packing","qty":1},{"nama":"Solasi roll","qty":1}]`). |
| `saas_welcome_kit` | CREATE | `id PK, tenant_id, outlet_id, payment_id INT NULL UNIQUE, trigger VARCHAR(24) (setup_fee/outlet_activation), penerima VARCHAR(120), hp VARCHAR(20), alamat TEXT, kota VARCHAR(100), kode_pos VARCHAR(10), items_json TEXT, status ENUM('pending','shipped','delivered','cancelled') DEFAULT 'pending', kurir VARCHAR(60) NULL, resi VARCHAR(80) NULL, shipped_at DATETIME NULL, delivered_at DATETIME NULL, catatan VARCHAR(255) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP`. Index `tenant_id`, `outlet_id`, `status`. |

### Kode
| File | Aksi | Tanggung jawab |
|---|---|---|
| `core/WelcomeKit.php` | **NEW** | Helper: `enabled()`, `items()` (baca config, decode JSON), `createForOutlet($db, $tenantId, $outletId, $paymentId, $trigger)` — idempoten (cek `payment_id`), snapshot alamat dari `outlets` + items dari config, INSERT `saas_welcome_kit`; `listQueue($status)`, `markShipped($id, $kurir, $resi)`, `markDelivered($id)`, `statusForOutlet($outletId)`. |
| `core/PaymentSettler.php` | MODIFY | Setelah aktivasi sukses (best-effort try/catch, tidak menggagalkan settle), panggil `WelcomeKit::createForOutlet(...)` bila `WelcomeKit::enabled()`. **`settleOutletActivation`**: outletId = `$payment['ref_outlet_id']`. **`settleSetupFee`**: `ref_outlet_id` mungkin kosong (setup fee = tenant-level) → resolve outlet target = outlet `is_main=1` milik tenant (fallback: outlet pertama tenant); bila belum ada outlet, skip (kit menyusul saat outlet dibuat/aktif). |
| `add-outlet.php` | MODIFY | Tambah field form **wajib**: Penerima, No. HP (telepon), Alamat, Kota, Kode Pos. Validasi server (tolak simpan bila kosong). Simpan ke kolom outlet. |
| `superadmin/registration_wizard.php` | MODIFY | Tambah field alamat lengkap wajib di step yang bikin outlet; simpan ke outlet. |
| `superadmin/welcome_kit.php` | **NEW** | Halaman SA: tab **Antrian** (list pending/shipped: alamat + isi kit + aksi "Dikirim" [modal input kurir+resi] → "Terkirim") + tab **Konfigurasi** (toggle enabled + editor `welcome_kit_items`). Guard SA + `saVerifyCsrf()`. Endpoint JSON: `list`, `mark_shipped`, `mark_delivered`, `save_config`. |
| `superadmin/` sidebar/menu | MODIFY | Link ke `welcome_kit.php`. |
| Owner status view | MODIFY | Tampilkan status kit di konfirmasi/detail outlet (mis. `add-outlet.php` sukses page atau outlet detail): "🎁 Welcome kit: <isi> — <status + resi>". Sumber: `WelcomeKit::statusForOutlet`. |

## Alur

### Aktivasi → kit terutang
1. Outlet dibuat dengan alamat lengkap (wajib).
2. Pembayaran aktivasi settle (`settleSetupFee`/`settleOutletActivation`) → set outlet `active` + kredit coin (existing) → **`WelcomeKit::createForOutlet`**: kalau enabled & belum ada record utk `payment_id`, snapshot alamat + items → INSERT `saas_welcome_kit` status `pending`.
3. Muncul di **antrian SA**.

### SA fulfillment
1. SA → Welcome Kit → Antrian: lihat pending (alamat + isi kit).
2. Packing manual → klik **Dikirim** → isi kurir + no resi → status `shipped`, `shipped_at=NOW()`.
3. Saat sampai → klik **Terkirim** → status `delivered`, `delivered_at=NOW()`.

### Owner
Lihat status kit di halaman outlet: pending → "sedang disiapkan"; shipped → "Dikirim via {kurir}, resi {resi}"; delivered → "Terkirim".

## Error Handling / Edge Cases

| Kondisi | Perilaku |
|---|---|
| `welcome_kit_enabled=off` | `createForOutlet` no-op (tidak buat record). |
| Settle dipanggil 2× (payment sama) | `payment_id` UNIQUE → record kedua di-skip (idempoten). |
| Alamat outlet belum lengkap saat aktivasi | Seharusnya tidak terjadi (wajib di pembuatan). Bila ada outlet lama tanpa alamat → record tetap dibuat dengan field alamat apa adanya + `catatan` "alamat belum lengkap"; SA lengkapi manual sebelum kirim. |
| Kit gagal dibuat (exception) | Di-`try/catch` best-effort; **tidak** menggagalkan settle/aktivasi (uang & coin tetap masuk). Log error. |
| `welcome_kit_items` JSON invalid | `items()` fallback ke array kosong + log; SA perbaiki config. |
| Outlet trial (belum bayar) | Tidak lewat settle berbayar → tidak ada kit. |

## Keamanan

- Endpoint SA welcome_kit: guard SuperAdmin + `saVerifyCsrf()` (POST). Owner hanya **baca** status kit outlet miliknya (scope tenant/outlet).
- Alamat = data pribadi outlet; hanya tampil ke owner terkait + SA. Tidak diekspos publik.
- Snapshot alamat di record → hindari kebocoran perubahan lintas waktu.

## Testing

### PHP unit (`tests/` pola `_assert.php`)
- `WelcomeKit::createForOutlet`: (a) enabled → 1 record `pending`, snapshot alamat+items benar; (b) `payment_id` sama 2× → tetap 1 record (idempoten); (c) `welcome_kit_enabled=0` → tidak ada record.
- `WelcomeKit::markShipped`/`markDelivered` → status + timestamp + kurir/resi tersimpan.
- `WelcomeKit::items()` → decode JSON config; JSON invalid → array kosong.
- Validasi add-outlet: submit tanpa alamat lengkap → ditolak (field wajib).
- Settle: `settleOutletActivation` yang sukses juga membuat 1 welcome_kit (bila enabled) tanpa menggagalkan bila kit error (mock error → settle tetap ok).

### Manual / MCP
- SA atur isi kit → aktivasi outlet berbayar → record muncul di antrian dgn alamat+isi benar → mark shipped (kurir+resi) → owner lihat status "Dikirim".
- add-outlet tanpa alamat lengkap → tak bisa lanjut.

### Lint
- `php -l` semua file PHP yang disentuh.

## Out of Scope

- Hitung ongkir otomatis / integrasi API ekspedisi (JNE/J&T).
- Stok & inventori kit (SA pack manual).
- Notifikasi WA/push ke owner saat dikirim (fase lanjut).
- Kit berbeda per paket/tier atau owner memilih isi.
- Retroaktif memaksa alamat lengkap outlet lama.
- Kit untuk outlet trial.

## References
- [[project-outlet-activation-coin]] — pola trigger settle + idempotency + config server
- [add-outlet.php](../../add-outlet.php), [core/PaymentSettler.php](../../core/PaymentSettler.php), [superadmin/registration_wizard.php](../../superadmin/registration_wizard.php), [core/BillingConfig.php](../../core/BillingConfig.php)
- Config: `saas_billing_config`; tabel baru `saas_welcome_kit`

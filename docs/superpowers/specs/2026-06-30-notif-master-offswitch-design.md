# Notif Master Off-Switch — Design Spec

> LaMaSy. Tanggal: 2026-06-30.

## Goal

Beri owner **satu saklar** untuk mematikan **semua notifikasi otomatis** sekaligus (daily report, alert anomali, dan kategori lain yang lewat resolusi channel), tanpa harus mematikan satu per satu per-channel. Saat dimatikan kembali, setelan per-channel yang lama kembali berlaku apa adanya.

## Konteks (sistem notif existing — dipakai ulang)

- Konfigurasi notif disimpan di kolom JSON `tenants.notif_settings`.
- `core/NotifPrefs.php`:
  - `NotifPrefs::read(int $tenantId): array` — decode `notif_settings` (`[]` kalau NULL/invalid).
  - `NotifPrefs::channelsFor(array $cfg, string $kategori): array` — kembalikan daftar channel aktif untuk satu kategori (channel absen → default ON).
- Pengirim memanggil `channelsFor()` sebelum kirim; **kalau hasilnya `[]` → tidak ada yang terkirim**:
  - `core/DailyReport.php` (kategori `daily_report`).
  - `core/AnomalyDetector.php` (kategori `alert_anomali`).
- UI pengaturan notif owner ada di `owner_report.php` (toggle per-channel + `save_prefs`), pakai `NotifPrefs`.

**Gap yang ditutup:** belum ada master switch — owner harus matikan tiap channel tiap kategori satu-satu.

## Keputusan Desain

- **Satu titik logika:** master switch dicek di awal `channelsFor()`. Karena SEMUA pengirim lewat fungsi ini, satu guard membungkam semuanya. Tidak perlu menyentuh DailyReport/AnomalyDetector.
- **Non-destruktif:** master switch TIDAK menghapus setelan per-channel. Mematikan switch → perilaku per-channel sebelumnya kembali utuh.
- **Default OFF (master tidak aktif):** tenant existing tak berubah perilakunya (`master_off` absen → falsy → guard tak jalan).
- **Per-tenant** (owner-level), seperti setelan notif lain.

## Data

`tenants.notif_settings` (JSON, sudah ada) — tambah satu key top-level:
```json
{ "master_off": true, "...": "setelan per-channel existing tetap" }
```
- `master_off` truthy → bungkam semua. Absen/false → perilaku normal.
- Tanpa migrasi DB (kolom JSON sudah ada).

## Komponen & File

| File | Aksi | Tanggung jawab |
|---|---|---|
| `core/NotifPrefs.php` | MODIFY | Di awal `channelsFor()`: `if (!empty($cfg['master_off'])) return [];` (sebelum logika per-channel) |
| `owner_report.php` | MODIFY | Toggle menonjol di atas pengaturan notif: "🔕 Matikan semua notifikasi otomatis"; muat nilai `master_off` saat ini; simpan lewat `save_prefs` yang sudah ada (sertakan `master_off` di payload) |
| `tests/notif/test_master_off.php` | NEW | Unit `channelsFor` |

## Alur

1. Owner buka pengaturan notif (`owner_report.php`) → aktifkan "🔕 Matikan semua notifikasi otomatis" → simpan (`save_prefs` set `notif_settings.master_off=true`).
2. Pengirim mana pun (DailyReport, AnomalyDetector, dst) panggil `channelsFor($cfg, <kategori>)` → karena `master_off` true → `[]` → tidak kirim.
3. Owner matikan switch → `master_off=false` → `channelsFor` lanjut ke logika per-channel seperti semula.

## Error Handling

| Kondisi | Perilaku |
|---|---|
| `master_off` absen / false | `channelsFor` jalan normal (per-channel) — tenant existing tak terpengaruh |
| `master_off` true | semua kategori → `[]`, semua notif otomatis senyap |
| `notif_settings` NULL/invalid | `read()` balik `[]` → `master_off` absen → normal (default ON per-channel) |

## Testing

- **Unit `tests/notif/test_master_off.php`** (pure, array fixture — tanpa DB):
  - `channelsFor(['master_off'=>true], 'daily_report')` → `[]`.
  - `channelsFor(['master_off'=>true], 'alert_anomali')` → `[]` (kategori apa pun).
  - `channelsFor([], 'daily_report')` → perilaku default existing (tidak kosong / per-channel) — buktikan master tidak mengubah jalur normal.
  - `channelsFor(['master_off'=>false, ...per-channel...], 'daily_report')` → sama seperti tanpa master_off.
- **Lint** file PHP yang disentuh.
- **Manual:** owner aktifkan switch → trigger daily report / anomaly → tidak ada email/notif; matikan → kembali terkirim sesuai per-channel.

## Out of Scope

- Penjadwalan "matikan sementara sampai tanggal X" (snooze berjangka) — hanya on/off.
- Master switch per-kategori (sudah dicakup setelan per-channel existing).
- Notifikasi non-otomatis (mis. push event real-time per-role) — di luar `channelsFor`; tidak terpengaruh.
- Saklar global super-admin lintas semua tenant.

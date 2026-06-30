# Notif Auto-Email — Kontrol Per-Channel + Perbaikan Engine — Design Spec

> LaMaSy. Tanggal: 2026-06-30. Bugfix: disable notifikasi otomatis tidak berfungsi & tidak bisa diatur owner.

## Goal

Owner bisa mengatur notifikasi otomatis (Laporan Harian & Alert Anomali) **per-channel** (email & in-app terpisah) dari halaman owner sendiri, dan setelan itu **benar-benar dihormati engine** (saat ini disable diabaikan: in-app daily report hardcode nyala, master `enabled` tak pernah dibaca, toggle hanya ada di HQ/SuperAdmin).

## Konteks Existing (jangan rebuild)

- **Pseudo-cron** (bukan cron beneran): [middleware/tenant_guard.php:255-266] memanggil `AnomalyDetector::check()` + `DailyReport::maybeSend()` saat ada request halaman non-AJAX, throttle 30 menit/sesi. Reliabilitas harian = isu terpisah, **out of scope**.
- **Config**: `tenants.notif_settings` (kolom TEXT, JSON). UI satu-satunya = [hq/settings.php] (SuperAdmin) — matrix checkbox per-alert × (email/wa) + `daily_report_jam` + `daily_report_konten`.
- **DailyReport** ([core/DailyReport.php]): `maybeSend()` gate enabled → `date('H:i')>=jam` → `Notifier::sentToday` dedup → coin. Channel hardcode `['inapp']` + email kalau `daily_report.email=1`. `readConfig` hardcode `enabled=true` (tak pernah baca dari JSON). Manual: tombol di owner_report.php (`send_now`, subject suffix " (manual)").
- **AnomalyDetector** ([core/AnomalyDetector.php]): `check()` jalankan 5 cek (checkOmsetDrop/checkKasBelumDiinput/checkOrderMenumpuk/checkAbsensiRendah/checkCoinRendah). `isEnabled()` baca `alert_anomali.email` (default true kalau NULL). Tiap cek panggil `Notifier::notifyOwner` **tanpa** `channels` → default `['email','inapp']` → kirim dua-duanya. `coin_feature='alert_anomali'`.
- **Notifier** ([core/Notifier.php]): `notifyOwner($tid,$oid,$opts)` — `$channels=$opts['channels']??['email','inapp']`; coin di-deduct hanya kalau channel email/wa benar-benar terkirim; inapp = simpan log saja. Email via [core/Mailer.php] (SMTP, config konstanta di db.php server).
- **owner_report.php**: gate `TenantResolver::isAdminLevel()`. Sudah punya action `list`/`mark_read`/`send_now`/`preview`. `$activePage='owner_report'`. Pola fetch + CSRF mengikuti file ini.

## Arsitektur

- Helper baru `core/NotifPrefs.php`: **pure** `channelsFor(array $cfg, string $kategori): array` (resolusi channel dari config) + thin `read(int $tenantId): array` (baca+decode `tenants.notif_settings`). Dua konsumen (DailyReport, AnomalyDetector) pakai helper sama → satu sumber kebenaran, testable.
- Owner UI di `owner_report.php` (panel + action `get_prefs`/`save_prefs`) menulis `notif_settings` secara **merge** (tak menimpa key milik HQ).
- HQ matrix dibiarkan (kompatibel — tulis key yang sama; hanya tak punya toggle in-app).

## Data Model — `tenants.notif_settings` (JSON)

```json
{
  "daily_report":  {"email": 0|1, "inapp": 0|1},
  "alert_anomali": {"email": 0|1, "inapp": 0|1},
  "daily_report_jam": "21:00",
  "daily_report_konten": ["omset","order","kas","absensi","alert"]
}
```

- **Default**: key kategori / channel absen, atau `notif_settings` NULL → channel dianggap **ON** (`1`). Mempertahankan perilaku existing untuk tenant yang belum konfigurasi. (Tenant 1 sudah di-set `email=0` manual sebelumnya — tetap valid.)
- WA dikecualikan dari UI owner (belum diimplementasi). `coin_low`, `trial_ending`, `daily_report_jam`, `daily_report_konten` di luar cakupan toggle owner ini tapi **tidak boleh hilang** saat owner menyimpan (merge, bukan replace).

## Komponen & File

- `core/NotifPrefs.php` (NEW):
  - `NotifPrefs::channelsFor(array $cfg, string $kategori): array` — **pure**. Dari `$cfg[$kategori]['email']` & `['inapp']` (default `1` kalau absen) → kembalikan subset dari `['email','inapp']` yang aktif. Kategori tak dikenal / config kosong → default `['email','inapp']`.
  - `NotifPrefs::read(int $tenantId): array` — SELECT notif_settings, `json_decode` (array kosong kalau NULL/invalid).
- `tests/notif/test_notifprefs.php` (NEW): unit untuk `channelsFor`.
- `core/DailyReport.php` (MODIFY): `readConfig` baca channels via NotifPrefs (buang hardcode `enabled=true`). `maybeSend`: `$channels = NotifPrefs::channelsFor($cfg_raw,'daily_report')`; kalau `[]` → return `['skipped'=>'channel off']` (tak ada email & in-app). Hapus baris hardcode `$channels=['inapp']`.
- `core/AnomalyDetector.php` (MODIFY): `check()` hitung `$channels = NotifPrefs::channelsFor($cfg,'alert_anomali')`; kalau `[]` → return (skip semua cek, hemat coin). Teruskan `$channels` ke tiap cek → tiap `notifyOwner` opts tambah `'channels'=>$channels`. Buang `isEnabled()` lama (digantikan).
- `owner_report.php` (MODIFY): panel "⚙️ Pengaturan Notifikasi" + action `get_prefs` (GET, isAdminLevel) & `save_prefs` (POST, isAdminLevel, verifyCsrf, merge ke notif_settings).

## Alur

**Engine (clock pseudo-cron):**
- `DailyReport::maybeSend`: baca config → channels daily_report. `[]` → skip. Selain itu gate jam + dedup + coin seperti sekarang, lalu `Notifier::notifyOwner(..., 'channels'=>$channels)`.
- `AnomalyDetector::check`: channels alert_anomali. `[]` → return. Selain itu jalankan 5 cek, tiap `notifyOwner` pakai `$channels`.

**Owner UI (owner_report.php):**
- `get_prefs`: kembalikan `{daily_report:{email,inapp}, alert_anomali:{email,inapp}, daily_report_jam, daily_report_konten}` (default terisi 1 / nilai existing).
- Panel: 4 switch (Laporan Harian: Email, In-app; Alert Anomali: Email, In-app) + input jam + checkbox konten. Tombol Simpan → `save_prefs`.
- `save_prefs`: verifyCsrf + isAdminLevel. Baca notif_settings existing → **merge** field yang dikelola panel ini (daily_report, alert_anomali, daily_report_jam, daily_report_konten) → UPDATE. Jangan sentuh coin_low/trial_ending.

## Error Handling

| Kondisi | Perilaku |
|---|---|
| `notif_settings` NULL / invalid JSON | default semua channel ON (perilaku existing) |
| Kategori/channel key absen | channel itu default ON (`1`) |
| Dua channel suatu kategori off | kategori itu senyap total (email & in-app) — DailyReport skip, AnomalyDetector skip cek |
| save_prefs tanpa CSRF / bukan admin | tolak, JSON error, tak ubah data |
| save_prefs | merge — coin_low/trial_ending/HQ keys tak hilang |

## Testing

- **Unit (`NotifPrefs::channelsFor`, pure):**
  - `{email:1,inapp:1}` → `['email','inapp']`
  - `{email:0,inapp:1}` → `['inapp']`
  - `{email:1,inapp:0}` → `['email']`
  - `{email:0,inapp:0}` → `[]`
  - kategori absen di cfg → `['email','inapp']` (default)
  - cfg kosong `[]` → `['email','inapp']`
- **Manual E2E:** owner buka owner_report.php → matikan Email daily report → simpan → trigger laporan: in-app muncul, email tidak. Matikan dua-duanya → tak ada laporan sama sekali. Matikan Email alert anomali → alert masuk in-app saja. Pastikan menyimpan tidak menghapus setelan HQ (coin_low/trial_ending) yang sudah ada.

## Out of Scope

- Channel WhatsApp (belum diimplementasi).
- Toggle owner untuk coin_low / trial_ending (tetap HQ).
- Mengubah pseudo-cron jadi cron beneran (reliabilitas pengiriman harian).
- Mengubah perhitungan coin / harga fitur.

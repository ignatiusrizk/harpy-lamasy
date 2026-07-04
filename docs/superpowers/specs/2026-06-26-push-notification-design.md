# Push Notification (FCM) — Design Spec

> LAMASY native app (Capacitor 7 Android). Tanggal: 2026-06-26.

## Goal

Notifikasi push real-time ke HP staf/owner saat event penting terjadi (order baru, order siap, mesin selesai, antar-jemput, stok kritis) — **muncul walau app ditutup**. Langganan event diatur **per role** (checkbox di editor role), bukan global.

## Arsitektur (Opsi A — FCM)

- App native (Capacitor thin-shell, `server.url=https://lamasy.harpy.id`) pasang plugin `@capacitor/push-notifications` → dapat **FCM registration token** → kirim ke server.
- Server menyimpan token per user, dan saat event terjadi **mengirim push inline via FCM HTTP v1 API** (tanpa cron — sesuai prinsip "no auto-cron").
- Langganan event terikat ke `hl_roles` lewat junction table; penerima diresolusi dari role + cakupan outlet + device token.

Opsi B (Web Push/PWA) & C (local notif + polling) ditolak: B tak andal di Android WebView, C tak jalan saat app ditutup.

## Tech Stack

- App: `@capacitor/push-notifications` (Capacitor 7), Firebase Cloud Messaging, `google-services.json`.
- Server: PHP 8 / MariaDB. FCM HTTP v1 + OAuth2 service-account JWT (RS256 via `openssl_sign`, tanpa library Google). `cURL` untuk POST FCM.
- Existing: `core/ErrorLogger.php`, `middleware/tenant_guard.php`, RBAC (`hl_roles`/`hl_permissions`/`hl_role_permissions`), CSRF interceptor global (M1).

## Global Constraints

- Multi-tenant: semua query scoping `tenant_id` (+ `outlet_id` bila relevan).
- Service account JSON **di luar webroot, tak masuk git** (pola `master/config`). Project ID boleh di config biasa.
- Kegagalan push **TIDAK BOLEH** menggagalkan transaksi utama (order tetap tersimpan walau push error) — best-effort, bungkus try/catch + ErrorLogger.
- Tanpa cron / scheduler. Pengiriman inline di handler request event.
- Registrasi token hanya jalan di app (cek `window.Capacitor.Plugins.PushNotifications`); di browser di-skip diam-diam.
- CSRF: endpoint `/api/push_register.php` POST → token CSRF terbawa otomatis oleh interceptor global; tetap panggil `verifyCsrf()` di server.

## Katalog Event (v1)

Didefinisikan sebagai konstanta PHP (`PushSender::EVENTS`), tiap event: `kode`, `label`, `outlet_bound` (bool).

| kode | label | outlet_bound | dipicu di |
|------|-------|:---:|---|
| `order_baru` | Order baru masuk | true | POS / drop / self create order |
| `order_siap` | Order siap diambil | true | `orders.php` status_proses → `siap` |
| `mesin_selesai` | Mesin selesai | true | `mesin.php` siklus selesai |
| `antar_baru` | Tugas antar-jemput baru | true | `antar-jemput.php` action `assign` |
| `stok_kritis` | Stok bahan kritis | true | mutasi stok turun ≤ minimum |

## Data Model (2 tabel baru)

```sql
CREATE TABLE hl_device_token (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id    INT NOT NULL,
  user_id      INT NOT NULL,
  token        VARCHAR(255) NOT NULL,
  platform     VARCHAR(20) DEFAULT 'android',
  last_seen    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_token (token),
  KEY idx_user (tenant_id, user_id)
);

CREATE TABLE hl_role_push_event (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id    INT NOT NULL,
  role_id      INT NOT NULL,
  event_kode   VARCHAR(40) NOT NULL,
  UNIQUE KEY uq_role_event (role_id, event_kode),
  KEY idx_tenant (tenant_id)
);
```

- 1 user bisa banyak device → banyak token. `uq_token` bikin re-register idempotent; token yang pindah user di-reassign via `ON DUPLICATE KEY UPDATE`.
- `hl_role_push_event` pakai `role_id` (FK ke `hl_roles`, tenant-scoped). User dengan role itu otomatis ikut langganan. Owner/HQ TIDAK auto-dapat semua (beda dengan owner-bypass permission) — harus langganan eksplisit.

## Resolusi Penerima

Untuk event `outlet_bound` di outlet X, tenant T:

```
penerima = { user u :
    u.tenant_id = T
    AND (role u langganan event di hl_role_push_event)
    AND (
        u terhubung ke outlet X  (u.outlet_id = X ATAU ada di hl_karyawan_outlet utk X)
        OR  u adalah owner/HQ     (TenantResolver::canAccessHqV2 / role owner|manager)
    )
}
∩ { user yang punya ≥1 row di hl_device_token }
```

Staf outlet lain (bukan X, bukan HQ) tidak dapat. Token diambil dari semua device milik penerima.

## Flow Token (app → server)

Di blok init Capacitor `components.php` (~L148, tempat StatusBar init):

```js
var PN = window.Capacitor?.Plugins?.PushNotifications;
if (PN) {
  PN.requestPermissions().then(function(r){ if (r.receive === 'granted') PN.register(); });
  PN.addListener('registration', function(t){
    fetch('/api/push_register.php', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ token: t.value, platform:'android' })
    });
  });
  PN.addListener('pushNotificationActionPerformed', function(a){
    var url = a.notification?.data?.url; if (url) location.href = url;
  });
}
```

`/api/push_register.php`: `tenant_guard` → `verifyCsrf()` → baca JSON `{token, platform}` → `INSERT INTO hl_device_token (...) ON DUPLICATE KEY UPDATE user_id=?, tenant_id=?, last_seen=NOW()`. Validasi token non-empty.

## Pengiriman (`core/PushSender.php`)

API:
```php
PushSender::send(string $eventKode, int $tenantId, int $outletId, array $payload): void
// payload: ['title'=>string, 'body'=>string, 'url'=>string(optional, halaman tujuan saat notif di-tap)]
```

Alur internal (semua di dalam try/catch — error → ErrorLogger, return tanpa lempar):
1. Validasi `eventKode` ada di katalog; kalau tidak, return.
2. Resolusi penerima (query di atas) → daftar `user_id`. Kosong → return.
3. Ambil semua `token` aktif untuk user tsb.
4. `accessToken()`: mint OAuth2 token dari service account — JWT RS256 (`openssl_sign`) di-exchange ke `https://oauth2.googleapis.com/token`; cache hasil ~55 menit (file di luar webroot atau APCu).
5. Untuk tiap token: POST ke `https://fcm.googleapis.com/v1/projects/<PROJECT_ID>/messages:send` dengan body `{message:{token, notification:{title,body}, data:{url}, android:{priority:HIGH}}}`.
6. Respons `404` / error `UNREGISTERED` / `INVALID_ARGUMENT` (token) → `DELETE FROM hl_device_token WHERE token=?` (auto-cleanup).

Helper config: `PushSender::config()` baca `PUSH_FCM_PROJECT_ID` + path service-account JSON dari `master/config` (atau env). Jika config absen → `send()` no-op + log sekali.

## Integrasi Event (1 panggilan per titik)

| Event | File / lokasi | Payload |
|-------|---------------|---------|
| `order_baru` | handler create order (POS/drop/self) | title "Order baru", body "#{kode} • {nama}", url `/orders?q={kode}` |
| `order_siap` | `orders.php` saat `status_proses` → `siap` | title "Order siap", body "#{kode} siap diambil", url `/orders?q={kode}` |
| `mesin_selesai` | `mesin.php` saat siklus selesai | title "Mesin selesai", body "{nama_mesin} selesai", url `/mesin` |
| `antar_baru` | `antar-jemput.php` action `assign` | title "Tugas antar baru", body "{alamat}", url `/kurir` — penerima: kurir yang di-assign |
| `stok_kritis` | titik mutasi stok turun ≤ minimum | title "Stok kritis", body "{nama_bahan} sisa {stok}", url `/inventori` |

Catatan `antar_baru`: penerima spesifik kurir yang di-assign (bukan resolusi outlet umum) — `PushSender` terima optional `targetUserIds` untuk override resolusi role. (Tetap di-gate event langganan role kurir.)

## UI — Editor Role (`hq/roles.php`)

- Modal edit role: tambah grup **"🔔 Notifikasi Push"** sejajar grup permission yang sudah ada.
- Action AJAX baru `push_events_list` → kembalikan katalog event (`PushSender::EVENTS`).
- `detail` role diperluas mengembalikan `push_events` (array kode yang sudah dilanggan).
- `save` role diperluas: terima `push_events[]` → hapus + insert ulang di `hl_role_push_event` (tenant-scoped). Hanya owner (`hqCanManageRole`) boleh ubah.

## Error Handling

- Semua jalur FCM dibungkus try/catch; gagal → `ErrorLogger::log()`, user tak lihat error, transaksi utama lanjut.
- Config Firebase absen → `send()` diam (no-op) + log sekali, app tetap jalan.
- Token mati dibersihkan otomatis saat FCM tolak.
- `push_register.php` gagal (token kosong / DB) → balas JSON error, tak mengganggu app.

## Testing

- **Unit (mock PDO):** resolusi penerima — (a) staf outlet X dapat, staf outlet Y tidak; (b) owner/HQ langganan dapat walau beda outlet; (c) user tanpa device token tak masuk; (d) role tak langganan tak masuk.
- **Unit:** cleanup — token yang ditolak FCM terhapus.
- **Unit:** `push_register` idempotent (re-insert token sama tak duplikat, update last_seen).
- **Manual E2E (device):** buat order → HP staf bunyi; tap notif → buka `/orders`; uninstall app → token auto-hilang saat FCM 404.

## Prasyarat (user, sekali setup — dipandu)

1. Buat Firebase project (gratis) → Add Android app package `id.harpy.lamasy`.
2. Download `google-services.json` → `lamasy-app/android/app/`.
3. Generate service account key (Project Settings → Service accounts) → JSON → upload ke Hostinger di luar webroot, tak masuk git.
4. Beri Project ID untuk config server.

## Ringkas File

**Baru:** `core/PushSender.php`, `api/push_register.php`, migration (2 tabel), `lamasy-app` (plugin + `google-services.json`).
**Diubah:** `components.php` (init token), `hq/roles.php` (UI + save push events), `master/config` (project id + path), 5 titik event (`orders.php`, `mesin.php`, `antar-jemput.php`, POS create, titik mutasi stok), `lamasy-app/package.json`, `build-apk.sh`/README (catatan google-services).

## Out of Scope (v1)

- iOS push (APNs) — Android dulu.
- Per-user mute / quiet hours.
- Rich notification (gambar/aksi tombol).
- Notif ke pelanggan (portal) — fokus internal staf/owner.

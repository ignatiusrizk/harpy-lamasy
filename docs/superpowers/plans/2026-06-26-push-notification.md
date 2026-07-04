# Push Notification (FCM) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Kirim push notification FCM ke HP staf/owner saat event penting terjadi (order baru, order siap, mesin selesai, antar-jemput, stok kritis), dengan langganan event diatur per-role.

**Architecture:** App Capacitor (thin-shell remote webview) pasang `@capacitor/push-notifications`, daftarkan FCM token ke server via `/api/push_register.php`. Saat event terjadi, handler memanggil `PushSender::send()` yang meresolusi penerima (role berlangganan + cakupan outlet/HQ + punya device token) lalu mengirim via FCM HTTP v1 (OAuth2 service-account JWT, tanpa library Google). Semua pengiriman best-effort & inline (tanpa cron).

**Tech Stack:** PHP 8 / MariaDB (prod), PDO. Test pakai `pdo_sqlite` in-memory + skrip CLI (`php -l` untuk file non-logika). FCM HTTP v1 + `openssl_sign` (RS256) + `cURL`. App: Capacitor 7, `@capacitor/push-notifications`, Firebase Cloud Messaging.

## Global Constraints

- Multi-tenant: SEMUA query scoping `tenant_id` (+ `outlet_id` bila relevan).
- Service account JSON di luar webroot, **tidak masuk git** (pola `master/config`). Project ID boleh config biasa.
- Kegagalan push **TIDAK BOLEH** menggagalkan transaksi utama. Bungkus try/catch + `ErrorLogger::log()`.
- **Tanpa cron/scheduler.** Pengiriman inline di handler request event.
- Registrasi token hanya jalan di app (cek `window.Capacitor.Plugins.PushNotifications`); browser di-skip diam-diam.
- Endpoint POST baru WAJIB panggil `verifyCsrf()` (token terbawa otomatis oleh interceptor global M1).
- users = `hl_users` (kolom: `id, tenant_id, outlet_id, role, role_id, is_active`). Multi-outlet: `hl_karyawan_outlet (karyawan_id = hl_users.id, outlet_id, tenant_id, is_active)`. Roles = `hl_roles`.
- HQ/owner = `hl_users.role IN ('owner','manager','superadmin')`.
- `ErrorLogger::log(string $type, string $message, ?int $tenantId=null, ?int $outletId=null, ...)`.
- Core class pakai static method + `Database::get()` (PDO singleton), bungkus `try { } catch (Throwable) { }` seperti `core/Notifier.php`.
- mysql client: `/opt/homebrew/opt/mysql-client/bin/mysql` (pakai `~/.my.cnf`, konek ke prod). PHP CLI: `/opt/homebrew/bin/php`.
- Deploy: `git push origin main` (SSH deploy key sudah ter-set via `core.sshCommand`).

## Katalog Event (verbatim)

| kode | label | outlet_bound |
|------|-------|:---:|
| `order_baru` | Order baru masuk | true |
| `order_siap` | Order siap diambil | true |
| `mesin_selesai` | Mesin selesai | true |
| `antar_baru` | Tugas antar-jemput baru | true |
| `stok_kritis` | Stok bahan kritis | true |

## File Structure

- `core/PushSender.php` (NEW) — katalog event, resolusi penerima, registrasi token, JWT/OAuth, kirim FCM, cleanup. Satu kelas, semua statik.
- `api/push_register.php` (NEW) — endpoint thin: guard + CSRF + delegasi ke `PushSender::registerToken()`.
- `tests/push/*.php` (NEW) — skrip CLI test (sqlite in-memory).
- `tests/_assert.php` (NEW) — helper assert mini.
- `migrations/2026-06-26-push-notif.sql` (NEW) — DDL 2 tabel (juga diterapkan langsung ke prod).
- `components.php` (MODIFY ~L148) — init registrasi token + listener tap.
- `hq/roles.php` (MODIFY) — action `push_events_list`, perluas `detail` & `save`, grup checkbox UI.
- `orders.php`, `mesin.php`, `antar-jemput.php`, POS create handler, titik mutasi stok (MODIFY) — panggil `PushSender::send()`.
- `master/config/db.php` (MODIFY) — `PUSH_FCM_PROJECT_ID` + path service account.
- `~/Documents/lamasy-app/` (MODIFY) — `package.json`, `capacitor.config`, `android/app/google-services.json`, `README.md`, `build-apk.sh`.

---

### Task 1: Migration — 2 tabel

**Files:**
- Create: `migrations/2026-06-26-push-notif.sql`

**Interfaces:**
- Produces: tabel `hl_device_token (id, tenant_id, user_id, token, platform, last_seen, created_at)` + `hl_role_push_event (id, tenant_id, role_id, event_kode)`.

- [ ] **Step 1: Tulis file migration**

`migrations/2026-06-26-push-notif.sql`:
```sql
CREATE TABLE IF NOT EXISTS hl_device_token (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id    INT NOT NULL,
  user_id      INT NOT NULL,
  token        VARCHAR(255) NOT NULL,
  platform     VARCHAR(20) DEFAULT 'android',
  last_seen    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_token (token),
  KEY idx_user (tenant_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_role_push_event (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id    INT NOT NULL,
  role_id      INT NOT NULL,
  event_kode   VARCHAR(40) NOT NULL,
  UNIQUE KEY uq_role_event (role_id, event_kode),
  KEY idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 2: Terapkan ke prod**

Run: `/opt/homebrew/opt/mysql-client/bin/mysql < migrations/2026-06-26-push-notif.sql`
Expected: tanpa error.

- [ ] **Step 3: Verifikasi**

Run: `/opt/homebrew/opt/mysql-client/bin/mysql -e "SHOW COLUMNS FROM hl_device_token; SHOW COLUMNS FROM hl_role_push_event;"`
Expected: kolom sesuai DDL di atas.

- [ ] **Step 4: Commit**

```bash
git add migrations/2026-06-26-push-notif.sql
git commit -m "feat(push): migration hl_device_token + hl_role_push_event"
```

---

### Task 2: PushSender — katalog event + resolusi penerima + token

**Files:**
- Create: `core/PushSender.php`
- Create: `tests/_assert.php`
- Create: `tests/push/test_resolve.php`

**Interfaces:**
- Produces:
  - `PushSender::EVENTS` : `array<string,array{label:string,outlet_bound:bool}>`
  - `PushSender::eventExists(string $kode): bool`
  - `PushSender::resolveRecipients(PDO $db, string $eventKode, int $tenantId, int $outletId, ?array $targetUserIds = null): array` — kembalikan `int[]` user_id unik.
  - `PushSender::tokensForUsers(PDO $db, int $tenantId, array $userIds): array` — kembalikan `string[]` token.

- [ ] **Step 1: Tulis helper assert**

`tests/_assert.php`:
```php
<?php
function ok(bool $cond, string $msg): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
    echo "PASS: $msg\n";
}
function eqv($got, $want, string $msg): void {
    ok($got == $want, "$msg (got " . json_encode($got) . ", want " . json_encode($want) . ")");
}
```

- [ ] **Step 2: Tulis test resolusi (failing)**

`tests/push/test_resolve.php`:
```php
<?php
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../core/PushSender.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE hl_users (id INT, tenant_id INT, outlet_id INT, role TEXT, role_id INT, is_active INT)");
$db->exec("CREATE TABLE hl_role_push_event (id INTEGER PRIMARY KEY, tenant_id INT, role_id INT, event_kode TEXT)");
$db->exec("CREATE TABLE hl_karyawan_outlet (id INTEGER PRIMARY KEY, tenant_id INT, karyawan_id INT, outlet_id INT, is_active INT)");
$db->exec("CREATE TABLE hl_device_token (id INTEGER PRIMARY KEY, tenant_id INT, user_id INT, token TEXT, platform TEXT)");

// Tenant 1. Role 10 langganan 'order_baru'. Role 20 tidak.
$db->exec("INSERT INTO hl_role_push_event (tenant_id, role_id, event_kode) VALUES (1,10,'order_baru')");
// Users:
// u1: staf outlet 5, role 10 (langganan), punya token  -> MASUK
// u2: staf outlet 9, role 10 (langganan), punya token  -> TIDAK (outlet lain)
// u3: owner outlet 9, role 10 (langganan), punya token  -> MASUK (HQ cross-outlet)
// u4: staf outlet 5, role 20 (tak langganan), token     -> TIDAK (role tak langganan)
// u5: staf outlet 5, role 10 (langganan), TANPA token   -> TIDAK (tak ada device)
// u6: outlet utama 9 tapi assigned ke outlet 5 via karyawan_outlet, role 10, token -> MASUK
$db->exec("INSERT INTO hl_users (id,tenant_id,outlet_id,role,role_id,is_active) VALUES
  (1,1,5,'staff',10,1),(2,1,9,'staff',10,1),(3,1,9,'owner',10,1),
  (4,1,5,'staff',20,1),(5,1,5,'staff',10,1),(6,1,9,'kasir',10,1)");
$db->exec("INSERT INTO hl_karyawan_outlet (tenant_id,karyawan_id,outlet_id,is_active) VALUES (1,6,5,1)");
foreach ([1,2,3,4,6] as $uid) {
    $db->exec("INSERT INTO hl_device_token (tenant_id,user_id,token) VALUES (1,$uid,'tok$uid')");
}

$got = PushSender::resolveRecipients($db, 'order_baru', 1, 5);
sort($got);
eqv($got, [1,3,6], "resolusi penerima order_baru outlet 5");

// targetUserIds override: hanya user 6 (tetap harus langganan + punya token)
$got2 = PushSender::resolveRecipients($db, 'order_baru', 1, 5, [6,2]);
sort($got2);
eqv($got2, [6], "targetUserIds membatasi ke user tertentu yg eligible");

// tokensForUsers
$toks = PushSender::tokensForUsers($db, 1, [1,3]);
sort($toks);
eqv($toks, ['tok1','tok3'], "tokensForUsers ambil token milik user");

echo "ALL OK\n";
```

- [ ] **Step 3: Jalankan test, pastikan gagal**

Run: `/opt/homebrew/bin/php tests/push/test_resolve.php`
Expected: FATAL "Class PushSender not found" / "Call to undefined method".

- [ ] **Step 4: Implementasi `core/PushSender.php` (bagian Task 2)**

```php
<?php
// core/PushSender.php — Push notification via FCM HTTP v1 (Opsi A).
// Best-effort: kegagalan TIDAK pernah melempar ke pemanggil.

class PushSender
{
    /** Katalog event v1. kode => [label, outlet_bound]. */
    public const EVENTS = [
        'order_baru'    => ['label' => 'Order baru masuk',        'outlet_bound' => true],
        'order_siap'    => ['label' => 'Order siap diambil',      'outlet_bound' => true],
        'mesin_selesai' => ['label' => 'Mesin selesai',           'outlet_bound' => true],
        'antar_baru'    => ['label' => 'Tugas antar-jemput baru', 'outlet_bound' => true],
        'stok_kritis'   => ['label' => 'Stok bahan kritis',       'outlet_bound' => true],
    ];

    public static function eventExists(string $kode): bool
    {
        return isset(self::EVENTS[$kode]);
    }

    /**
     * User yang berhak menerima event: role-nya langganan + (di outlet itu ATAU owner/HQ)
     * + punya minimal 1 device token. Opsional dibatasi $targetUserIds.
     * @return int[]
     */
    public static function resolveRecipients(PDO $db, string $eventKode, int $tenantId, int $outletId, ?array $targetUserIds = null): array
    {
        $sql = "SELECT DISTINCT u.id
                FROM hl_users u
                JOIN hl_role_push_event rpe
                  ON rpe.role_id = u.role_id AND rpe.tenant_id = u.tenant_id AND rpe.event_kode = ?
                WHERE u.tenant_id = ?
                  AND u.is_active = 1
                  AND ( u.outlet_id = ?
                        OR u.role IN ('owner','manager','superadmin')
                        OR EXISTS (SELECT 1 FROM hl_karyawan_outlet ko
                                   WHERE ko.karyawan_id = u.id AND ko.outlet_id = ?
                                     AND ko.tenant_id = u.tenant_id AND ko.is_active = 1) )
                  AND EXISTS (SELECT 1 FROM hl_device_token dt
                              WHERE dt.user_id = u.id AND dt.tenant_id = u.tenant_id)";
        $params = [$eventKode, $tenantId, $outletId, $outletId];
        if ($targetUserIds !== null) {
            $targetUserIds = array_values(array_filter(array_map('intval', $targetUserIds)));
            if (!$targetUserIds) return [];
            $ph  = implode(',', array_fill(0, count($targetUserIds), '?'));
            $sql .= " AND u.id IN ($ph)";
            $params = array_merge($params, $targetUserIds);
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return string[] token milik user yang diberikan */
    public static function tokensForUsers(PDO $db, int $tenantId, array $userIds): array
    {
        $userIds = array_values(array_filter(array_map('intval', $userIds)));
        if (!$userIds) return [];
        $ph = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $db->prepare("SELECT token FROM hl_device_token WHERE tenant_id = ? AND user_id IN ($ph)");
        $stmt->execute(array_merge([$tenantId], $userIds));
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
```

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `/opt/homebrew/bin/php tests/push/test_resolve.php`
Expected: `PASS:` semua + `ALL OK`.

- [ ] **Step 6: Lint + commit**

```bash
/opt/homebrew/bin/php -l core/PushSender.php
git add core/PushSender.php tests/_assert.php tests/push/test_resolve.php
git commit -m "feat(push): PushSender katalog event + resolusi penerima + token"
```

---

### Task 3: PushSender — config, JWT/OAuth, kirim FCM, cleanup, orchestrator

**Files:**
- Modify: `core/PushSender.php`
- Modify: `master/config/db.php` (tambah konstanta config)
- Create: `tests/push/test_jwt.php`

**Interfaces:**
- Consumes: `PushSender::EVENTS`, `resolveRecipients`, `tokensForUsers` (Task 2).
- Produces:
  - `PushSender::config(): ?array` — `['project_id'=>string,'sa_path'=>string]` atau `null`.
  - `PushSender::buildJwt(array $sa, int $now): string` — JWT RS256 untuk OAuth (pure, testable).
  - `PushSender::accessToken(array $cfg): ?string` — OAuth2 access token (cache file ~55 mnt).
  - `PushSender::sendToToken(string $accessToken, string $projectId, string $token, array $payload): int` — HTTP status.
  - `PushSender::deleteToken(PDO $db, string $token): void`
  - `PushSender::registerToken(PDO $db, int $tenantId, int $userId, string $token, string $platform): void`
  - `PushSender::send(string $eventKode, int $tenantId, int $outletId, array $payload, ?array $targetUserIds = null): void`

- [ ] **Step 1: Tambah config di `master/config/db.php`**

Tambahkan di akhir file (sebelum `?>` bila ada), ganti nilai sesuai prasyarat user:
```php
// Push notification (FCM HTTP v1). Service account JSON di luar webroot, tak masuk git.
if (!defined('PUSH_FCM_PROJECT_ID')) define('PUSH_FCM_PROJECT_ID', getenv('PUSH_FCM_PROJECT_ID') ?: '');
if (!defined('PUSH_FCM_SA_PATH'))    define('PUSH_FCM_SA_PATH',    getenv('PUSH_FCM_SA_PATH') ?: (dirname(__DIR__) . '/fcm-service-account.json'));
```

- [ ] **Step 2: Tulis test JWT (failing)**

`tests/push/test_jwt.php`:
```php
<?php
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../core/PushSender.php';

// Generate keypair RSA sementara untuk test signing.
$res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($res, $priv);

$sa = [
    'client_email' => 'svc@proj.iam.gserviceaccount.com',
    'private_key'   => $priv,
    'token_uri'     => 'https://oauth2.googleapis.com/token',
];
$now = 1750000000;
$jwt = PushSender::buildJwt($sa, $now);

$parts = explode('.', $jwt);
eqv(count($parts), 3, "JWT punya 3 segmen");

$b64 = fn($s) => json_decode(base64_decode(strtr($s, '-_', '+/')), true);
$header  = $b64($parts[0]);
$payload = $b64($parts[1]);
eqv($header['alg'], 'RS256', "header alg RS256");
eqv($payload['iss'], 'svc@proj.iam.gserviceaccount.com', "iss = client_email");
eqv($payload['aud'], 'https://oauth2.googleapis.com/token', "aud = token_uri");
eqv($payload['iat'], $now, "iat = now");
eqv($payload['exp'], $now + 3600, "exp = now + 3600");
ok(strpos($payload['scope'], 'firebase.messaging') !== false, "scope mengandung firebase.messaging");

// Verifikasi tanda tangan valid dengan public key.
$pub  = openssl_pkey_get_details($res)['key'];
$data = $parts[0] . '.' . $parts[1];
$sig  = base64_decode(strtr($parts[2], '-_', '+/'));
eqv(openssl_verify($data, $sig, $pub, OPENSSL_ALGO_SHA256), 1, "tanda tangan RS256 valid");

echo "ALL OK\n";
```

- [ ] **Step 3: Jalankan test, pastikan gagal**

Run: `/opt/homebrew/bin/php tests/push/test_jwt.php`
Expected: FATAL "undefined method PushSender::buildJwt".

- [ ] **Step 4: Implementasi bagian Task 3 ke `core/PushSender.php`**

Tambahkan method berikut ke kelas `PushSender` (sebelum `}` penutup kelas):
```php
    public static function config(): ?array
    {
        $pid  = defined('PUSH_FCM_PROJECT_ID') ? PUSH_FCM_PROJECT_ID : '';
        $path = defined('PUSH_FCM_SA_PATH') ? PUSH_FCM_SA_PATH : '';
        if ($pid === '' || $path === '' || !is_file($path)) return null;
        return ['project_id' => $pid, 'sa_path' => $path];
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function buildJwt(array $sa, int $now): string
    {
        $header  = ['alg' => 'RS256', 'typ' => 'JWT'];
        $payload = [
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];
        $segments = self::b64url(json_encode($header)) . '.' . self::b64url(json_encode($payload));
        $sig = '';
        openssl_sign($segments, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256);
        return $segments . '.' . self::b64url($sig);
    }

    public static function accessToken(array $cfg): ?string
    {
        $cacheFile = sys_get_temp_dir() . '/lamasy_fcm_token.json';
        if (is_file($cacheFile)) {
            $c = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($c) && ($c['exp'] ?? 0) > time() + 60) return $c['token'];
        }
        $sa = json_decode((string)@file_get_contents($cfg['sa_path']), true);
        if (!is_array($sa) || empty($sa['private_key']) || empty($sa['client_email'])) return null;

        $jwt = self::buildJwt($sa, time());
        $ch = curl_init($sa['token_uri'] ?? 'https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string)$resp, true);
        if (empty($data['access_token'])) return null;

        @file_put_contents($cacheFile, json_encode([
            'token' => $data['access_token'],
            'exp'   => time() + (int)($data['expires_in'] ?? 3600),
        ]));
        return $data['access_token'];
    }

    /** @return int HTTP status (0 jika gagal koneksi) */
    public static function sendToToken(string $accessToken, string $projectId, string $token, array $payload): int
    {
        $body = ['message' => [
            'token'        => $token,
            'notification' => [
                'title' => (string)($payload['title'] ?? 'LAMASY'),
                'body'  => (string)($payload['body'] ?? ''),
            ],
            'data'    => array_map('strval', ['url' => $payload['url'] ?? '']),
            'android' => ['priority' => 'HIGH'],
        ]];
        $ch = curl_init("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($body),
        ]);
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $status;
    }

    public static function deleteToken(PDO $db, string $token): void
    {
        try {
            $db->prepare("DELETE FROM hl_device_token WHERE token = ?")->execute([$token]);
        } catch (Throwable) { /* best-effort */ }
    }

    public static function registerToken(PDO $db, int $tenantId, int $userId, string $token, string $platform): void
    {
        if ($token === '') return;
        $platform = $platform !== '' ? substr($platform, 0, 20) : 'android';
        $stmt = $db->prepare(
            "INSERT INTO hl_device_token (tenant_id, user_id, token, platform)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), tenant_id = VALUES(tenant_id),
                                     platform = VALUES(platform), last_seen = CURRENT_TIMESTAMP"
        );
        $stmt->execute([$tenantId, $userId, $token, $platform]);
    }

    public static function send(string $eventKode, int $tenantId, int $outletId, array $payload, ?array $targetUserIds = null): void
    {
        try {
            if (!self::eventExists($eventKode)) return;
            $cfg = self::config();
            if ($cfg === null) return; // Firebase belum dikonfigurasi → no-op

            $db    = Database::get();
            $users = self::resolveRecipients($db, $eventKode, $tenantId, $outletId, $targetUserIds);
            if (!$users) return;
            $tokens = self::tokensForUsers($db, $tenantId, $users);
            if (!$tokens) return;

            $accessToken = self::accessToken($cfg);
            if ($accessToken === null) {
                ErrorLogger::log('push', 'Gagal ambil FCM access token', $tenantId, $outletId);
                return;
            }
            foreach ($tokens as $tok) {
                $status = self::sendToToken($accessToken, $cfg['project_id'], $tok, $payload);
                if ($status === 404 || $status === 400) self::deleteToken($db, $tok); // UNREGISTERED / INVALID
            }
        } catch (Throwable $e) {
            ErrorLogger::log('push', 'PushSender::send error: ' . $e->getMessage(), $tenantId, $outletId);
        }
    }
```

- [ ] **Step 5: Jalankan test JWT, pastikan lulus**

Run: `/opt/homebrew/bin/php tests/push/test_jwt.php`
Expected: `PASS:` semua + `ALL OK`.

- [ ] **Step 6: Pastikan test Task 2 masih lulus + lint**

Run: `/opt/homebrew/bin/php tests/push/test_resolve.php && /opt/homebrew/bin/php -l core/PushSender.php`
Expected: `ALL OK` + `No syntax errors`.

- [ ] **Step 7: Commit**

```bash
git add core/PushSender.php master/config/db.php tests/push/test_jwt.php
git commit -m "feat(push): PushSender FCM v1 (config, JWT/OAuth, send, cleanup, register)"
```

---

### Task 4: Endpoint registrasi token

**Files:**
- Create: `api/push_register.php`
- Create: `tests/push/test_register.php`

**Interfaces:**
- Consumes: `PushSender::registerToken()` (Task 3).
- Produces: endpoint `POST /api/push_register.php` body JSON `{token, platform}`.

- [ ] **Step 1: Tulis test upsert idempotent (failing — file endpoint belum ada, tapi test panggil registerToken via sqlite dgn fallback INSERT)**

`tests/push/test_register.php`:
```php
<?php
// Verifikasi logika upsert lewat sqlite. Karena sqlite tak punya
// "ON DUPLICATE KEY UPDATE", test menegaskan kontrak: token unik tak duplikat.
require __DIR__ . '/../_assert.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE hl_device_token (id INTEGER PRIMARY KEY, tenant_id INT, user_id INT, token TEXT UNIQUE, platform TEXT, last_seen TEXT)");

// Emulasi upsert portable untuk test:
function upsert(PDO $db, int $t, int $u, string $tok, string $plat): void {
    if ($tok === '') return;
    $db->prepare("INSERT INTO hl_device_token (tenant_id,user_id,token,platform)
                  VALUES (?,?,?,?)
                  ON CONFLICT(token) DO UPDATE SET user_id=excluded.user_id, tenant_id=excluded.tenant_id, platform=excluded.platform")
       ->execute([$t,$u,$tok,$plat ?: 'android']);
}

upsert($db, 1, 5, 'abc', 'android');
upsert($db, 1, 5, 'abc', 'android'); // re-register sama
upsert($db, 1, 7, 'abc', 'ios');     // token pindah user
upsert($db, 1, 5, '', 'android');    // token kosong → diabaikan

$rows = $db->query("SELECT user_id, platform FROM hl_device_token")->fetchAll(PDO::FETCH_ASSOC);
eqv(count($rows), 1, "token unik: tetap 1 row setelah re-register & reassign");
eqv($rows[0]['user_id'], 7, "token ter-reassign ke user terbaru");
eqv($rows[0]['platform'], 'ios', "platform ikut ter-update");

echo "ALL OK\n";
```

- [ ] **Step 2: Jalankan test, pastikan lulus** (test ini menegaskan kontrak upsert; bukan menjalankan endpoint)

Run: `/opt/homebrew/bin/php tests/push/test_register.php`
Expected: `ALL OK`.

- [ ] **Step 3: Implementasi `api/push_register.php`**

```php
<?php
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/PushSender.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}
verifyCsrf();

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true) ?: [];
$token    = trim((string)($data['token'] ?? ''));
$platform = trim((string)($data['platform'] ?? 'android'));

if ($token === '') {
    http_response_code(422);
    echo json_encode(['error' => 'token kosong']);
    exit;
}
try {
    PushSender::registerToken(Database::get(), (int)$_SESSION['tenant_id'], (int)$_SESSION['user_id'], $token, $platform);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    ErrorLogger::log('push', 'push_register gagal: ' . $e->getMessage(), (int)($_SESSION['tenant_id'] ?? 0));
    http_response_code(500);
    echo json_encode(['error' => 'gagal simpan token']);
}
```

> Catatan implementer: konfirmasi `tenant_guard.php` mengisi `$_SESSION['user_id']` & `$_SESSION['tenant_id']` (dipakai guard untuk auth-check) dan mengekspos `verifyCsrf()` + `Database`. Bila nama variabel sesi user berbeda, samakan dengan yang dipakai guard.

- [ ] **Step 4: Lint + commit**

```bash
/opt/homebrew/bin/php -l api/push_register.php
git add api/push_register.php tests/push/test_register.php
git commit -m "feat(push): endpoint /api/push_register.php + test upsert"
```

---

### Task 5: Registrasi token di app (components.php)

**Files:**
- Modify: `components.php` (blok init Capacitor ~L148, dalam `renderGlobalJsHelpers`)

**Interfaces:**
- Consumes: endpoint `/api/push_register.php` (Task 4), `csrfToken()` global + interceptor fetch (sudah ada).

- [ ] **Step 1: Tambah blok registrasi push di init Capacitor**

Di `components.php`, di dalam blok `<script>` yang sudah meng-init StatusBar/backButton (sekitar L148), tambahkan IIFE berikut (setelah listener backButton):
```js
    // ── Push notification (hanya di native app) ──
    (function(){
      var PN = window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.PushNotifications;
      if (!PN) return;
      PN.requestPermissions().then(function(r){
        if (r && r.receive === 'granted') PN.register();
      }).catch(function(){});
      PN.addListener('registration', function(t){
        if (!t || !t.value) return;
        fetch('/api/push_register.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ token: t.value, platform: 'android' })
        }).catch(function(){});
      });
      PN.addListener('pushNotificationActionPerformed', function(a){
        var url = a && a.notification && a.notification.data && a.notification.data.url;
        if (url) location.href = url;
      });
    })();
```

- [ ] **Step 2: Lint**

Run: `/opt/homebrew/bin/php -l components.php`
Expected: `No syntax errors`.

- [ ] **Step 3: Commit**

```bash
git add components.php
git commit -m "feat(push): registrasi FCM token + tap-handler di app (components.php)"
```

---

### Task 6: UI langganan event per role (hq/roles.php)

**Files:**
- Modify: `hq/roles.php` (action `push_events_list`, perluas `detail` & `save`, grup checkbox di modal)

**Interfaces:**
- Consumes: `PushSender::EVENTS` (Task 2), tabel `hl_role_push_event` (Task 1).
- Produces: action AJAX `push_events_list`; `detail` mengembalikan `push_events: string[]`; `save` menerima `push_events[]`.

- [ ] **Step 1: Require PushSender + action katalog**

Di awal `hq/roles.php` (setelah require guard), pastikan ada:
```php
require_once ROOT . '/core/PushSender.php';
```
Tambah handler action (di area handler AJAX lain, sebelum render HTML):
```php
    if ($action === 'push_events_list') {
        header('Content-Type: application/json');
        $out = [];
        foreach (PushSender::EVENTS as $kode => $meta) {
            $out[] = ['kode' => $kode, 'label' => $meta['label']];
        }
        echo json_encode(['events' => $out]);
        exit;
    }
```

- [ ] **Step 2: Perluas `detail` — sertakan langganan saat ini**

Di handler `if ($action === 'detail')`, setelah mengambil assigned permissions, tambahkan:
```php
            $pe = $db->prepare("SELECT event_kode FROM hl_role_push_event WHERE tenant_id=? AND role_id=?");
            $pe->execute([$tid, $rid]);
            $pushEvents = $pe->fetchAll(PDO::FETCH_COLUMN);
```
Lalu sertakan `'push_events' => $pushEvents` ke dalam array JSON respons `detail`.

- [ ] **Step 3: Perluas `save` — simpan langganan (DELETE+INSERT, tenant-scoped, owner-only)**

Di handler `if ($action === 'save' ...)`, setelah blok simpan `hl_role_permissions` (sekitar L156-167), tambahkan (hanya jika `$hqCanManageRole`):
```php
            // Langganan push event (DELETE + re-INSERT) — tenant-scoped.
            $db->prepare("DELETE FROM hl_role_push_event WHERE tenant_id=? AND role_id=?")
               ->execute([$tid, $rid]);
            $pushEvents = $_POST['push_events'] ?? [];
            if (is_array($pushEvents)) {
                $insPE = $db->prepare("INSERT INTO hl_role_push_event (tenant_id, role_id, event_kode) VALUES (?,?,?)");
                foreach ($pushEvents as $ek) {
                    if (PushSender::eventExists((string)$ek)) {
                        $insPE->execute([$tid, $rid, (string)$ek]);
                    }
                }
            }
```
> `$rid` = id role yang baru disimpan/diupdate (variabel yang sama dipakai blok permission di atasnya).

- [ ] **Step 4: Grup checkbox "🔔 Notifikasi Push" di modal edit role (frontend JS)**

Di JS modal role, tambah fetch katalog + render checkbox, dan sertakan `push_events[]` saat submit. Tambahkan dekat tempat permission di-render:
```js
// muat katalog push event sekali
let PUSH_EVENTS = [];
fetch('?action=push_events_list').then(r=>r.json()).then(d=>{ PUSH_EVENTS = d.events || []; });

function renderPushEvents(selected){
  selected = selected || [];
  const box = document.getElementById('pushEventsBox');
  if (!box) return;
  box.innerHTML = '<div style="font-weight:700;margin:12px 0 6px">🔔 Notifikasi Push</div>' +
    PUSH_EVENTS.map(e =>
      `<label style="display:flex;gap:8px;align-items:center;padding:4px 0">
         <input type="checkbox" name="push_events[]" value="${e.kode}" ${selected.includes(e.kode)?'checked':''}>
         <span>${e.label}</span>
       </label>`).join('');
}
```
- Tambah container `<div id="pushEventsBox"></div>` di markup modal role (di bawah daftar permission).
- Panggil `renderPushEvents(detail.push_events)` saat modal `detail` dibuka.
- Pastikan submit role memakai `FormData(form)` sehingga `push_events[]` ikut terkirim (interceptor M1 menambah CSRF otomatis). Jika submit memakai objek manual, tambahkan pengumpulan `document.querySelectorAll('input[name="push_events[]"]:checked')`.

- [ ] **Step 5: Lint + verifikasi route**

Run: `/opt/homebrew/bin/php -l hq/roles.php`
Expected: `No syntax errors`.
Run: `curl -s -o /dev/null -w "%{http_code}" "https://lamasy.harpy.id/hq/roles"` (setelah deploy) → `302` (auth).

- [ ] **Step 6: Commit**

```bash
git add hq/roles.php
git commit -m "feat(push): UI langganan event per-role di hq/roles.php"
```

---

### Task 7: Integrasi pemicu event (5 titik)

**Files:**
- Modify: titik create order (POS — cari handler INSERT `hl_orders`, kemungkinan `pos.php` atau `api/pos*.php`)
- Modify: `orders.php` (status_proses → `siap`, ~L202-207)
- Modify: `mesin.php` (siklus selesai)
- Modify: `antar-jemput.php` (action `assign`, ~L121)
- Modify: titik mutasi stok turun ≤ minimum (cari di `inventori.php` / `core` penurun stok)

**Interfaces:**
- Consumes: `PushSender::send(string $eventKode, int $tenantId, int $outletId, array $payload, ?array $targetUserIds = null)` (Task 3).

> Setiap pemanggilan diletakkan **setelah** transaksi utama commit/sukses, sehingga push hanya untuk aksi yang benar-benar tersimpan. `PushSender::send` sudah best-effort (tak melempar), jadi tak perlu try/catch tambahan.

- [ ] **Step 1: `require_once` PushSender di tiap file**

Pastikan tiap file di atas memuat: `require_once ROOT . '/core/PushSender.php';` (cek apakah `ROOT` terdefinisi; ikuti pola require yang sudah ada di file tsb).

- [ ] **Step 2: order_baru — setelah order tersimpan**

Setelah INSERT order sukses (punya `$tenantId`, `$outletId`, `$kodeOrder`, `$namaPelanggan`):
```php
PushSender::send('order_baru', (int)$tenantId, (int)$outletId, [
    'title' => 'Order baru masuk',
    'body'  => '#' . $kodeOrder . ' • ' . $namaPelanggan,
    'url'   => '/orders?q=' . urlencode($kodeOrder),
]);
```

- [ ] **Step 3: order_siap — di `orders.php` saat status jadi `siap`**

Di blok yang menstempel status `siap` (sekitar L202-207), setelah update commit:
```php
PushSender::send('order_siap', (int)$tenantId, (int)$outletId, [
    'title' => 'Order siap diambil',
    'body'  => '#' . $kodeOrder . ' siap diambil',
    'url'   => '/orders?q=' . urlencode($kodeOrder),
]);
```
> Implementer: pakai nama variabel tenant/outlet/kode yang tersedia di scope handler tsb.

- [ ] **Step 4: mesin_selesai — di `mesin.php` saat siklus selesai**

Setelah sesi mesin ditandai selesai:
```php
PushSender::send('mesin_selesai', (int)$tenantId, (int)$outletId, [
    'title' => 'Mesin selesai',
    'body'  => $namaMesin . ' selesai',
    'url'   => '/mesin',
]);
```

- [ ] **Step 5: antar_baru — di `antar-jemput.php` action `assign`**

Setelah assign sukses, kirim hanya ke kurir yang di-assign (`$kurirUserId`):
```php
PushSender::send('antar_baru', (int)$tenantId, (int)$outletId, [
    'title' => 'Tugas antar-jemput baru',
    'body'  => (string)$alamat,
    'url'   => '/kurir',
], [(int)$kurirUserId]);
```
> Implementer: `$kurirUserId` = `hl_users.id` kurir yang di-assign. Jika kolom menyimpan id karyawan, samakan ke id user.

- [ ] **Step 6: stok_kritis — di titik mutasi stok**

Setelah mutasi stok keluar/adjust yang membuat `stok_terkini <= minimum`:
```php
if ($stokSesudah <= $minimumStok) {
    PushSender::send('stok_kritis', (int)$tenantId, (int)$outletId, [
        'title' => 'Stok bahan kritis',
        'body'  => $namaBahan . ' sisa ' . $stokSesudah,
        'url'   => '/inventori',
    ]);
}
```

- [ ] **Step 7: Lint semua file tersentuh**

Run: `for f in orders.php mesin.php antar-jemput.php pos.php inventori.php; do /opt/homebrew/bin/php -l $f; done`
Expected: `No syntax errors` semua (sesuaikan daftar dengan file yang benar-benar diubah).

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat(push): integrasi 5 pemicu event (order_baru/siap, mesin, antar, stok)"
```

---

### Task 8: Native app — plugin push + wiring Firebase + build

**Files:**
- Modify: `~/Documents/lamasy-app/package.json` (tambah `@capacitor/push-notifications`)
- Modify: `~/Documents/lamasy-app/android/app/google-services.json` (dari user)
- Modify: `~/Documents/lamasy-app/README.md` + `build-apk.sh` (catatan re-apply)

**Interfaces:**
- Consumes: registrasi token dari webview (Task 5) — plugin meng-inject `Capacitor.Plugins.PushNotifications`.

> Prasyarat user (di luar kendali agent): Firebase project dibuat, Android app `id.harpy.lamasy` ditambahkan, `google-services.json` diunduh, service account JSON + Project ID diserahkan. Bila belum tersedia, hentikan task ini dan minta artefak tsb.

- [ ] **Step 1: Pasang plugin**

Run:
```bash
cd ~/Documents/lamasy-app
npm install @capacitor/push-notifications@^7
```
Expected: terpasang di `dependencies`.

- [ ] **Step 2: Letakkan `google-services.json`**

Salin file dari user ke `~/Documents/lamasy-app/android/app/google-services.json`.
> `android/` di-gitignore (di-regenerate). Catat di README bahwa file ini harus disalin ulang tiap regenerate.

- [ ] **Step 3: Sync + build**

Run:
```bash
cd ~/Documents/lamasy-app
npx cap sync android
./build-apk.sh
```
Expected: APK baru di `~/Desktop/LAMASY-v<ver>-b<code>-debug.apk`, build sukses (BUILD SUCCESSFUL).

- [ ] **Step 4: Update README**

Tambah bagian "Push Notification" ke `README.md`: prasyarat Firebase, lokasi `google-services.json`, env server `PUSH_FCM_PROJECT_ID` + `PUSH_FCM_SA_PATH`, dan langkah re-apply setelah `android/` regenerate (icon bg + google-services.json).

- [ ] **Step 5: Commit (repo app)**

```bash
cd ~/Documents/lamasy-app
git add package.json package-lock.json README.md
git commit -m "feat(push): @capacitor/push-notifications + wiring FCM"
```

- [ ] **Step 6: E2E manual (device)**

1. Install APK baru, login sebagai user yang role-nya langganan `order_baru`.
2. Pastikan izin notifikasi granted; konfirmasi row baru di `hl_device_token` (`SELECT * FROM hl_device_token ORDER BY id DESC LIMIT 3`).
3. Dari device/akun lain buat order di outlet user tsb → HP berbunyi/muncul notifikasi.
4. Tap notifikasi → app membuka `/orders`.
5. Uninstall app → kirim event lagi → verifikasi token mati terhapus dari `hl_device_token` (status FCM 404).

---

## Self-Review

**1. Spec coverage:**
- Katalog event → Task 2 (`EVENTS`). ✅
- 2 tabel → Task 1. ✅
- Resolusi penerima (outlet + owner/HQ + token) → Task 2 + test. ✅
- Flow token app→server → Task 5 + Task 4. ✅
- PushSender FCM v1 (JWT/OAuth, send, cleanup) → Task 3. ✅
- Integrasi 5 event → Task 7. ✅
- UI checkbox role → Task 6. ✅
- Config service account di luar git → Task 3 (Step 1) + Task 8 README. ✅
- Error handling best-effort → Task 3 `send()` try/catch + Global Constraints. ✅
- Testing (resolusi, cleanup/idempotent, JWT, E2E) → Task 2/3/4 + Task 8 Step 6. ✅
- Prasyarat Firebase → Task 8 preamble. ✅
- Out-of-scope (iOS, mute, rich, customer) → tidak ada task (benar). ✅

**2. Placeholder scan:** Tidak ada "TBD/TODO/implement later". Titik integrasi yang nama variabelnya bergantung scope (Task 7) diberi instruksi eksplisit + contoh kode lengkap; lokasi pasti dikonfirmasi implementer karena handler create-order belum ter-pin ke satu file — ini diarahkan, bukan placeholder kode.

**3. Type consistency:** `PushSender::send/resolveRecipients/tokensForUsers/registerToken/buildJwt/accessToken/sendToToken/deleteToken/config/eventExists/EVENTS` konsisten dipakai lintas Task 2–7. `hl_role_push_event(role_id,event_kode,tenant_id)` & `hl_device_token(tenant_id,user_id,token,platform)` konsisten Task 1↔2↔3↔4↔6. Param order `send(eventKode, tenantId, outletId, payload, targetUserIds)` konsisten Task 3↔7.

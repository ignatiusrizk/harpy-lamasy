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
        @chmod($cacheFile, 0600);
        return $data['access_token'];
    }

    /** @return int HTTP status (0 jika gagal koneksi) */
    public static function sendToToken(string $accessToken, string $projectId, string $token, array $payload): int
    {
        $body = ['message' => [
            'token'        => $token,
            'notification' => [
                'title' => (string)($payload['title'] ?? 'LaMaSy'),
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
        $resp   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status < 200 || $status >= 300) {
            ErrorLogger::log('push', "FCM send status=$status resp=" . substr((string)$resp, 0, 300));
        }
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
            if ($cfg === null) { // Firebase belum dikonfigurasi → no-op
                ErrorLogger::log('push_debug', 'config null: pid="' . (defined('PUSH_FCM_PROJECT_ID') ? PUSH_FCM_PROJECT_ID : '(undef)') . '" sapath="' . (defined('PUSH_FCM_SA_PATH') ? PUSH_FCM_SA_PATH : '(undef)') . '" file_exists=' . ((defined('PUSH_FCM_SA_PATH') && is_file(PUSH_FCM_SA_PATH)) ? '1' : '0'), $tenantId, $outletId);
                return;
            }

            $db    = Database::get();
            $users = self::resolveRecipients($db, $eventKode, $tenantId, $outletId, $targetUserIds);
            $tokens = $users ? self::tokensForUsers($db, $tenantId, $users) : [];
            $accessToken = self::accessToken($cfg);
            ErrorLogger::log('push_debug', "evt=$eventKode users=" . count($users) . ' tokens=' . count($tokens) . ' token_ok=' . ($accessToken !== null ? '1' : '0'), $tenantId, $outletId);
            if (!$tokens || $accessToken === null) {
                if ($accessToken === null) ErrorLogger::log('push', 'Gagal ambil FCM access token', $tenantId, $outletId);
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
}

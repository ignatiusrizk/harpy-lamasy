<?php
// ══════════════════════════════════════════════════════
// core/Sa2FA.php
//
// Email-based 2FA untuk SuperAdmin accounts.
// Flow: generate 6-digit code → email → user input → verify.
// ══════════════════════════════════════════════════════

require_once __DIR__ . '/Mailer.php';

class Sa2FA
{
    private const CODE_TTL_MINUTES = 10;
    private const MAX_ATTEMPTS = 5;
    private const RATE_LIMIT_PER_HOUR = 3;

    /**
     * Generate + send OTP ke email SA. Return true kalau email terkirim.
     */
    public static function send(int $saId): array
    {
        $db = Database::get();

        // Rate-limit: max 3 codes per hour per SA (anti-abuse)
        $count = $db->prepare(
            "SELECT COUNT(*) FROM saas_sa_2fa_codes
             WHERE sa_id=? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $count->execute([$saId]);
        if ((int)$count->fetchColumn() >= self::RATE_LIMIT_PER_HOUR) {
            return ['ok' => false, 'error' => 'Terlalu banyak kode dikirim. Coba lagi 1 jam lagi.'];
        }

        // Get SA email + name
        $sa = $db->prepare("SELECT id, name, username, email FROM super_admins WHERE id=? AND is_active=1");
        $sa->execute([$saId]);
        $row = $sa->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['email'])) {
            return ['ok' => false, 'error' => 'Email SA tidak terdaftar. Hubungi owner untuk set email.'];
        }

        // Generate 6-digit code
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash = password_hash($code, PASSWORD_BCRYPT);

        // Invalidate previous active codes (single active code policy)
        $db->prepare(
            "UPDATE saas_sa_2fa_codes SET consumed_at=NOW()
             WHERE sa_id=? AND consumed_at IS NULL AND expires_at > NOW()"
        )->execute([$saId]);

        // Store new code
        $expiresAt = date('Y-m-d H:i:s', time() + self::CODE_TTL_MINUTES * 60);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $db->prepare(
            "INSERT INTO saas_sa_2fa_codes (sa_id, code_hash, expires_at, ip_address)
             VALUES (?, ?, ?, ?)"
        )->execute([$saId, $hash, $expiresAt, $ip]);

        // Send email
        $sent = Mailer::sendOtp($row['email'], $row['name'] ?: $row['username'], $code, self::CODE_TTL_MINUTES);
        if (!$sent) {
            return ['ok' => false, 'error' => 'Email gagal terkirim. Hubungi admin.'];
        }

        return [
            'ok' => true,
            'expires_in' => self::CODE_TTL_MINUTES * 60,
            'email_hint' => self::maskEmail($row['email']),
        ];
    }

    /**
     * Verify code. Return ['ok'=>bool, 'error'=>...?].
     */
    public static function verify(int $saId, string $code): array
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6) {
            return ['ok' => false, 'error' => 'Kode harus 6 digit angka.'];
        }

        $db = Database::get();
        $st = $db->prepare(
            "SELECT id, code_hash, attempts FROM saas_sa_2fa_codes
             WHERE sa_id=? AND consumed_at IS NULL AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1"
        );
        $st->execute([$saId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'error' => 'Kode expired atau tidak ditemukan. Minta kirim ulang.'];
        }

        if ((int)$row['attempts'] >= self::MAX_ATTEMPTS) {
            // Force-expire after max attempts
            $db->prepare("UPDATE saas_sa_2fa_codes SET consumed_at=NOW() WHERE id=?")
               ->execute([$row['id']]);
            return ['ok' => false, 'error' => 'Terlalu banyak percobaan salah. Minta kirim ulang kode.'];
        }

        // Increment attempts first (defense against timing-leak)
        $db->prepare("UPDATE saas_sa_2fa_codes SET attempts=attempts+1 WHERE id=?")
           ->execute([$row['id']]);

        if (!password_verify($code, $row['code_hash'])) {
            $remaining = self::MAX_ATTEMPTS - ((int)$row['attempts'] + 1);
            return [
                'ok' => false,
                'error' => "Kode salah. Sisa percobaan: $remaining",
                'remaining' => max(0, $remaining),
            ];
        }

        // Mark consumed
        $db->prepare("UPDATE saas_sa_2fa_codes SET consumed_at=NOW() WHERE id=?")
           ->execute([$row['id']]);

        return ['ok' => true];
    }

    /**
     * Cek apakah SA punya 2FA aktif.
     */
    public static function isEnabled(int $saId): bool
    {
        $db = Database::get();
        $st = $db->prepare("SELECT twofa_enabled FROM super_admins WHERE id=?");
        $st->execute([$saId]);
        return (int)$st->fetchColumn() === 1;
    }

    /**
     * Enable/disable 2FA untuk SA.
     */
    public static function setEnabled(int $saId, bool $enabled): void
    {
        $db = Database::get();
        $method = $enabled ? 'email' : 'none';
        $db->prepare("UPDATE super_admins SET twofa_enabled=?, twofa_method=? WHERE id=?")
           ->execute([$enabled ? 1 : 0, $method, $saId]);
    }

    /**
     * Mask email untuk display: r***y@example.com
     */
    private static function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) return '***';
        $name = $parts[0];
        $masked = strlen($name) <= 2
            ? str_repeat('*', strlen($name))
            : $name[0] . str_repeat('*', max(1, strlen($name) - 2)) . substr($name, -1);
        return $masked . '@' . $parts[1];
    }

    /**
     * Cleanup expired codes (call via cron atau on-demand).
     */
    public static function cleanupExpired(): int
    {
        $db = Database::get();
        $st = $db->prepare(
            "DELETE FROM saas_sa_2fa_codes
             WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)"
        );
        $st->execute();
        return $st->rowCount();
    }
}

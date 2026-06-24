<?php
// ══════════════════════════════════════════════════════
// core/EmailTemplate.php
//
// CRUD helper untuk email templates di saas_email_templates.
// Mailer functions check DB-first; fallback ke hardcode.
// ══════════════════════════════════════════════════════

class EmailTemplate
{
    /**
     * Get template row by slug. Return null kalau tidak ada / nonaktif.
     */
    public static function get(string $slug): ?array
    {
        try {
            $st = Database::get()->prepare(
                "SELECT * FROM saas_email_templates WHERE slug=? AND is_active=1 LIMIT 1"
            );
            $st->execute([$slug]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Render template: interpolate {{var}} placeholders.
     * Returns ['subject' => str, 'html' => str] atau null kalau template tidak ada.
     */
    public static function render(string $slug, array $vars = []): ?array
    {
        $row = self::get($slug);
        if (!$row) return null;
        return [
            'subject' => self::interpolate($row['subject'], $vars),
            'html'    => self::interpolate($row['body_html'], $vars),
        ];
    }

    /**
     * Simple {{var}} → value replacement. Empty string untuk missing var.
     */
    public static function interpolate(string $template, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($m) use ($vars) {
            return isset($vars[$m[1]]) ? (string)$vars[$m[1]] : '';
        }, $template);
    }

    /**
     * List semua template untuk SA UI.
     */
    public static function listAll(): array
    {
        try {
            return Database::get()->query(
                "SELECT id, slug, name, subject, description, is_active, updated_at
                 FROM saas_email_templates ORDER BY slug ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Save template (insert or update by slug).
     */
    public static function save(array $data, int $bySaId): bool
    {
        $db = Database::get();
        $slug    = preg_replace('/[^a-z0-9_]/', '', strtolower($data['slug'] ?? ''));
        $name    = trim($data['name'] ?? '');
        $subject = trim($data['subject'] ?? '');
        $body    = $data['body_html'] ?? '';
        $desc    = trim($data['description'] ?? '');
        $active  = !empty($data['is_active']) ? 1 : 0;

        // Variables: kalau frontend kirim '[]' or empty → keep existing (don't overwrite hint metadata)
        $varsRaw = $data['variables'] ?? null;
        $newVars = null;
        if (is_array($varsRaw) && !empty($varsRaw)) {
            $newVars = json_encode($varsRaw);
        } elseif (is_string($varsRaw) && trim($varsRaw, " []\n") !== '') {
            $newVars = $varsRaw;
        }

        if (!$slug || !$name || !$subject || !$body) {
            throw new InvalidArgumentException('Slug, nama, subject, dan body wajib diisi.');
        }

        $exists = $db->prepare("SELECT id, variables FROM saas_email_templates WHERE slug=?");
        $exists->execute([$slug]);
        $existingRow = $exists->fetch(PDO::FETCH_ASSOC);

        if ($existingRow) {
            // Preserve variables kalau frontend kirim kosong
            $finalVars = $newVars ?? $existingRow['variables'] ?? '[]';
            $db->prepare(
                "UPDATE saas_email_templates
                 SET name=?, subject=?, body_html=?, variables=?, description=?, is_active=?, updated_by=?
                 WHERE id=?"
            )->execute([$name, $subject, $body, $finalVars, $desc, $active, $bySaId, (int)$existingRow['id']]);
        } else {
            $finalVars = $newVars ?? '[]';
            $db->prepare(
                "INSERT INTO saas_email_templates
                 (slug, name, subject, body_html, variables, description, is_active, updated_by)
                 VALUES (?,?,?,?,?,?,?,?)"
            )->execute([$slug, $name, $subject, $body, $finalVars, $desc, $active, $bySaId]);
        }
        return true;
    }

    /**
     * Seed templates default — dipanggil sekali saat install /
     * waktu user klik "Reset to Default" di UI.
     */
    public static function seedDefaults(): int
    {
        $defaults = self::getDefaults();
        $count = 0;
        $db = Database::get();
        foreach ($defaults as $slug => $tpl) {
            $exists = $db->prepare("SELECT id FROM saas_email_templates WHERE slug=?");
            $exists->execute([$slug]);
            if (!$exists->fetchColumn()) {
                $db->prepare(
                    "INSERT INTO saas_email_templates
                     (slug, name, subject, body_html, variables, description, is_active)
                     VALUES (?,?,?,?,?,?,1)"
                )->execute([
                    $slug,
                    $tpl['name'],
                    $tpl['subject'],
                    $tpl['body_html'],
                    json_encode($tpl['variables'] ?? []),
                    $tpl['description'] ?? '',
                ]);
                $count++;
            }
        }
        return $count;
    }

    /**
     * Reset single template ke default (atau create kalau belum ada).
     */
    public static function resetToDefault(string $slug): bool
    {
        $defaults = self::getDefaults();
        if (!isset($defaults[$slug])) return false;
        $tpl = $defaults[$slug];
        $db = Database::get();
        $db->prepare(
            "INSERT INTO saas_email_templates (slug, name, subject, body_html, variables, description, is_active)
             VALUES (?,?,?,?,?,?,1)
             ON DUPLICATE KEY UPDATE
                name=VALUES(name), subject=VALUES(subject), body_html=VALUES(body_html),
                variables=VALUES(variables), description=VALUES(description)"
        )->execute([
            $slug, $tpl['name'], $tpl['subject'], $tpl['body_html'],
            json_encode($tpl['variables'] ?? []), $tpl['description'] ?? ''
        ]);
        return true;
    }

    /**
     * Default templates (juga jadi fallback kalau DB row gak ada).
     * Body assumes wrapping di Mailer::baseTemplate() — jadi just inner content.
     */
    public static function getDefaults(): array
    {
        return [
            'verification' => [
                'name' => 'Email Verifikasi Tenant Baru',
                'subject' => 'Verifikasi Email LAMASY',
                'description' => 'Dikirim saat tenant baru register. Mengandung link verifikasi.',
                'variables' => ['name', 'outlet_name', 'link'],
                'body_html' => "<h2 style='color:#0F1C3A;margin:0 0 8px'>Halo, {{name}}! 👋</h2>\n<p style='color:#555;margin:0 0 24px;line-height:1.65'>\n  Terima kasih sudah mendaftar <strong>LAMASY</strong> untuk outlet <strong>{{outlet_name}}</strong>.<br>\n  Klik tombol di bawah untuk mengaktifkan akun kamu.\n</p>\n<div style='text-align:center;margin:32px 0'>\n  <a href='{{link}}' style='background:#35E8D5;color:#0F1C3A;font-weight:700;text-decoration:none;padding:14px 32px;border-radius:8px;display:inline-block;font-size:16px'>✅ Verifikasi Email Sekarang</a>\n</div>\n<p style='color:#888;font-size:13px;text-align:center'>Link berlaku <strong>24 jam</strong>. Jika bukan kamu yang daftar, abaikan email ini.</p>\n<hr style='border:none;border-top:1px solid #eee;margin:24px 0'>\n<p style='color:#aaa;font-size:12px;text-align:center;word-break:break-all'>Atau salin link: <a href='{{link}}' style='color:#35E8D5'>{{link}}</a></p>",
            ],
            'password_reset' => [
                'name' => 'Reset Password',
                'subject' => 'Reset Password LAMASY',
                'description' => 'Dikirim saat user request reset password.',
                'variables' => ['name', 'link'],
                'body_html' => "<h2 style='color:#0F1C3A;margin:0 0 8px'>Reset Password 🔑</h2>\n<p style='color:#555;margin:0 0 24px;line-height:1.65'>\n  Halo <strong>{{name}}</strong>,<br>\n  Kami terima request reset password untuk akun LAMASY kamu. Klik tombol di bawah untuk set password baru.\n</p>\n<div style='text-align:center;margin:32px 0'>\n  <a href='{{link}}' style='background:#35E8D5;color:#0F1C3A;font-weight:700;text-decoration:none;padding:14px 32px;border-radius:8px;display:inline-block;font-size:16px'>🔑 Reset Password</a>\n</div>\n<p style='color:#888;font-size:13px;text-align:center'>Link berlaku <strong>1 jam</strong>. Kalau bukan kamu, abaikan email ini — password kamu aman.</p>",
            ],
            'welcome' => [
                'name' => 'Welcome (Setelah Verifikasi)',
                'subject' => 'Selamat datang di LAMASY! 🎉',
                'description' => 'Dikirim setelah email diverifikasi. Mengarahkan ke dashboard + onboarding.',
                'variables' => ['name', 'outlet_name', 'dashboard_link', 'coin_balance'],
                'body_html' => "<h2 style='color:#0F1C3A;margin:0 0 8px'>Selamat datang, {{name}}! 🎉</h2>\n<p style='color:#555;margin:0 0 18px;line-height:1.65'>\n  Outlet <strong>{{outlet_name}}</strong> sudah aktif dan siap dipakai.\n</p>\n<div style='background:#F0FDFA;border:1px solid #35E8D5;border-radius:10px;padding:16px;margin:24px 0;text-align:center'>\n  <div style='color:#888;font-size:13px;margin-bottom:4px'>Coin Trial Kamu</div>\n  <div style='color:#0F1C3A;font-size:32px;font-weight:800;font-family:monospace'>{{coin_balance}}</div>\n  <div style='color:#888;font-size:12px;margin-top:4px'>Berlaku 7 hari</div>\n</div>\n<div style='text-align:center;margin:32px 0'>\n  <a href='{{dashboard_link}}' style='background:#35E8D5;color:#0F1C3A;font-weight:700;text-decoration:none;padding:14px 32px;border-radius:8px;display:inline-block;font-size:16px'>🚀 Masuk Dashboard</a>\n</div>\n<p style='color:#888;font-size:13px;text-align:center'>Butuh bantuan? Reply email ini atau chat support di dalam dashboard.</p>",
            ],
            'otp' => [
                'name' => '2FA OTP Code (SuperAdmin)',
                'subject' => 'Kode Verifikasi LAMASY: {{code}}',
                'description' => 'OTP 6-digit untuk login 2FA SuperAdmin. Mengandung kode + warning.',
                'variables' => ['name', 'code', 'minutes_valid'],
                'body_html' => "<h2 style='color:#0F1C3A;margin:0 0 8px'>Kode Verifikasi Login 🔐</h2>\n<p style='color:#555;margin:0 0 24px;line-height:1.65'>\n  Halo <strong>{{name}}</strong>,<br>\n  Seseorang mencoba login ke akun SuperAdmin LAMASY kamu. Gunakan kode berikut untuk melanjutkan:\n</p>\n<div style='text-align:center;margin:32px 0'>\n  <div style='display:inline-block;background:#F0FDFA;border:2px solid #35E8D5;padding:18px 36px;border-radius:12px;font-family:Menlo,Monaco,monospace;font-size:36px;font-weight:800;letter-spacing:.4em;color:#0F1C3A'>{{code}}</div>\n</div>\n<p style='color:#888;font-size:13px;text-align:center'>Kode berlaku <strong>{{minutes_valid}} menit</strong>. Jangan share ke siapapun.</p>\n<p style='color:#aaa;font-size:12px;text-align:center;margin-top:20px'>Bukan kamu yang login? Segera ganti password — akun mungkin terkompromi.</p>",
            ],
            'test' => [
                'name' => 'Email Test',
                'subject' => 'Test Email dari LAMASY',
                'description' => 'Test email untuk verify SMTP config.',
                'variables' => ['timestamp'],
                'body_html' => "<h2 style='color:#0F1C3A;margin:0 0 8px'>Email Test Berhasil ✓</h2>\n<p style='color:#555;margin:0 0 18px;line-height:1.65'>\n  Ini email test dari LAMASY. Kalau kamu terima ini, SMTP config sudah benar.\n</p>\n<p style='color:#888;font-size:13px;background:#F9FAFB;padding:12px;border-radius:6px;font-family:monospace'>\n  Dikirim: {{timestamp}}\n</p>",
            ],
        ];
    }
}

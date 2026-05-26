<?php
// ══════════════════════════════════════════════════════
// master/config/db.php
// Konfigurasi koneksi & konstanta global harpy multi-tenant
// JANGAN COMMIT file ini — sudah di .gitignore
// ══════════════════════════════════════════════════════

define('ROOT', dirname(__DIR__, 2));  // /harpy/

// ── Database credentials (shared host) ───────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'u269895997_HL_Admin');
define('DB_PASS', '1Kq7um&p*b@');

// Single database — semua tenant dalam 1 DB
define('DB_NAME', 'u269895997_harpy_master');

// ── Anthropic API ─────────────────────────────────────
define('ANTHROPIC_API_KEY', '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');   // ← isi sebelum deploy

// ── Session config ────────────────────────────────────
define('SESSION_TIMEOUT',  1800);   // 30 menit inaktif
define('SESSION_LIFETIME', 43200);  // 12 jam max session

// ── Coin config ───────────────────────────────────────
define('COIN_TRIAL_BALANCE',  50000);
define('COIN_AI_BRIEFING',    10);
define('COIN_SEND_WA',         5);
define('COIN_GENERATE_NOTA',   1);

// ── App URL ───────────────────────────────────────────
define('APP_URL', 'https://harpy.id');

// ── Mailer config ─────────────────────────────────────
// Driver: 'smtp' atau 'mail' (fallback PHP mail())
define('MAILER_DRIVER',   'smtp');
define('SMTP_HOST',       'smtp.hostinger.com');
define('SMTP_PORT',       465);              // 465 = SSL, 587 = STARTTLS
define('SMTP_ENCRYPTION', 'ssl');            // 'ssl' atau 'tls'
define('SMTP_USER',       'noreply@harpy.id');   // ← isi email Hostinger kamu
define('SMTP_PASS',       '');               // ← isi password email Hostinger
define('SMTP_FROM_EMAIL', 'noreply@harpy.id');
define('SMTP_FROM_NAME',  'LAMASY by Harpy');

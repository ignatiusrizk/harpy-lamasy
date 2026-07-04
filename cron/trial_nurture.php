<?php
// ══════════════════════════════════════════════════════
// cron/trial_nurture.php — Runner harian Trial Nurturing (Brief 4)
//
// Jalankan tiap hari (disarankan 09:00 WIB) via cron Hostinger:
//   php /home/USER/domains/lamasy.harpy.id/public_html/cron/trial_nurture.php
//
// Atau via HTTP (kalau host hanya support wget/curl cron) — WAJIB pakai token:
//   curl "https://lamasy.harpy.id/cron/trial_nurture.php?key=<CRON_SECRET>"
//   (set: define('CRON_SECRET','...') di master/config/db.php)
//
// Idempoten: tiap touchpoint max 1x per outlet (guard di TrialNurture).
// Aman dijalankan berkali-kali sehari.
// ══════════════════════════════════════════════════════

define('ROOT', dirname(__DIR__));

require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';
require_once ROOT . '/core/ErrorLogger.php';
require_once ROOT . '/core/TrialNurture.php';

// ── Akses control: CLI selalu boleh; HTTP wajib token rahasia (default tolak) ──
$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    header('Content-Type: text/plain');
    $secret = defined('CRON_SECRET') ? CRON_SECRET : '';
    if ($secret === '' || !hash_equals($secret, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("Forbidden\n");
    }
}

$start   = microtime(true);
$summary = TrialNurture::runAll();
$elapsed = round((microtime(true) - $start) * 1000);

$line = sprintf(
    "[trial_nurture] %s WIB — processed=%d sent=%d types=%s (%dms)",
    date('Y-m-d H:i:s'),
    $summary['processed'],
    $summary['sent'],
    json_encode($summary['byType'] ?: (object)[]),
    $elapsed
);
error_log($line);
echo $line . "\n";

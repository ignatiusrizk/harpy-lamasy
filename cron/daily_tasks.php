<?php
// ══════════════════════════════════════════════════════
// cron/daily_tasks.php — Daily scheduled tasks (independent dari user activity)
//
// Jalankan setiap hari via Hostinger Cron Jobs:
//   0 18 * * * php /path/to/harpy/cron/daily_tasks.php
//
// Yang dilakukan:
//   1. DailyReport::maybeSend per outlet aktif (guarantee delivery walaupun
//      outlet idle / kasir tidak buka system hari itu)
//   2. Auto-expire deposit refund yang pending > 14 hari (status: expired)
//   3. RetentionManager::sendDormantReminders per outlet (customer reminder)
//
// Output di-log ke stdout (cron capture log file).
// ══════════════════════════════════════════════════════

// Proteksi: hanya boleh dijalankan dari CLI
if (php_sapi_name() !== 'cli' && !defined('ALLOW_CRON_WEB')) {
    http_response_code(403);
    die('CLI only.');
}

define('ROOT', dirname(__DIR__));
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';
require_once ROOT . '/core/ErrorLogger.php';
require_once ROOT . '/core/Notifier.php';
require_once ROOT . '/core/DailyReport.php';
require_once ROOT . '/core/RetentionManager.php';
require_once ROOT . '/core/Mailer.php';

$db = Database::get();
$startedAt = date('Y-m-d H:i:s');

function clog(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

clog('=== daily_tasks.php START ===');

// ── 1. DailyReport per outlet aktif ─────────────────
clog('--- Task 1: DailyReport::maybeSend ---');
$outlets = $db->query("
    SELECT o.id outlet_id, o.tenant_id, o.nama_outlet
    FROM outlets o
    JOIN tenants t ON t.id = o.tenant_id
    WHERE o.status IN ('trial','grace','active')
      AND t.status = 'active'
")->fetchAll(PDO::FETCH_ASSOC);

$reportSent = 0; $reportSkipped = 0; $reportErr = 0;
foreach ($outlets as $o) {
    try {
        $res = DailyReport::maybeSend((int)$o['tenant_id'], (int)$o['outlet_id']);
        if (!empty($res['sent'])) {
            $reportSent++;
            clog("  sent: outlet={$o['nama_outlet']} (tid={$o['tenant_id']} oid={$o['outlet_id']})");
        } else {
            $reportSkipped++; // mungkin sudah pernah send hari ini
        }
    } catch (Throwable $e) {
        $reportErr++;
        ErrorLogger::logException('cron_daily_report', $e, (int)$o['tenant_id'], (int)$o['outlet_id']);
        clog("  ERR: outlet={$o['nama_outlet']}: " . $e->getMessage());
    }
}
clog("Task 1 done — sent=$reportSent skipped=$reportSkipped err=$reportErr");

// ── 2. Auto-expire deposit refund pending > 14 hari ──
clog('--- Task 2: Expire pending deposit refunds ---');
try {
    $upd = $db->prepare("
        UPDATE hl_deposit_refund
        SET status = 'expired'
        WHERE status = 'pending'
          AND requested_at < DATE_SUB(NOW(), INTERVAL 14 DAY)
    ");
    $upd->execute();
    $expiredCount = $upd->rowCount();
    clog("Task 2 done — expired_count=$expiredCount");
} catch (Throwable $e) {
    ErrorLogger::logException('cron_refund_expire', $e);
    clog("Task 2 ERR: " . $e->getMessage());
}

// ── 3. Notify owner kalau ada due dormant reminders (admin-curated, not auto-send) ──
clog('--- Task 3: Dormant reminder count notification ---');
$remindNotified = 0; $remindErr = 0;
foreach ($outlets as $o) {
    try {
        $due = RetentionManager::dueReminders((int)$o['tenant_id'], (int)$o['outlet_id']);
        $count = count($due ?: []);
        if ($count >= 5) {
            // Threshold 5: notify owner there's a backlog to review
            Notifier::log(
                (int)$o['tenant_id'], (int)$o['outlet_id'],
                'retention_backlog', 'inapp', null,
                "Ada {$count} customer dormant menanti reminder",
                "Buka /retention untuk kirim reminder ke customer yang sudah lama tidak order."
            );
            $remindNotified++;
            clog("  notify: outlet={$o['nama_outlet']} due_count=$count");
        }
    } catch (Throwable $e) {
        $remindErr++;
        ErrorLogger::logException('cron_retention', $e, (int)$o['tenant_id'], (int)$o['outlet_id']);
        clog("  ERR: outlet={$o['nama_outlet']}: " . $e->getMessage());
    }
}
clog("Task 3 done — notified=$remindNotified err=$remindErr");

$finishedAt = date('Y-m-d H:i:s');
clog("=== daily_tasks.php END (started=$startedAt finished=$finishedAt) ===");

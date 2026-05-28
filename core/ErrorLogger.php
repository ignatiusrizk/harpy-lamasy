<?php
// ══════════════════════════════════════════════════════
// core/ErrorLogger.php — Platform-wide error logger
//
// Dipanggil di catch block seluruh sistem:
//   ErrorLogger::log('wa_error', $message, $tenantId, $outletId);
//   ErrorLogger::log('ai_error', $message, $tenantId);
//   ErrorLogger::log('db_error', $message);
//
// Error yang sama di hari yang sama di-deduplicate
// (occurrence_count++) — tidak spam baris baru.
//
// Types: php_error | db_error | wa_error | ai_error | system_error
// ══════════════════════════════════════════════════════

class ErrorLogger
{
    /** Panjang max error_message yang disimpan */
    const MAX_MSG_LEN   = 1000;
    /** Panjang max stack_trace */
    const MAX_TRACE_LEN = 3000;
    /** Dedup key: tanggal + type + 100 char pertama message */
    const DEDUP_MSG_LEN = 100;

    /**
     * Catat error ke saas_error_log dengan deduplication.
     * Aman dipanggil dari mana saja — exception di dalam tidak akan
     * propagate ke luar (best-effort logging).
     */
    public static function log(
        string  $type,
        string  $message,
        ?int    $tenantId  = null,
        ?int    $outletId  = null,
        ?string $url       = null,
        ?string $stackTrace = null,
        ?string $errorCode = null
    ): void {
        try {
            $db = Database::get();

            // Auto-detect URL jika tidak diberikan
            if ($url === null && !empty($_SERVER['REQUEST_URI'])) {
                $url = substr($_SERVER['REQUEST_URI'], 0, 500);
            }

            $method    = $_SERVER['REQUEST_METHOD'] ?? null;
            $msgTrunc  = substr($message, 0, self::MAX_MSG_LEN);
            $dedupMsg  = substr($message, 0, self::DEDUP_MSG_LEN);

            // Cek dedup: apakah error yang sama sudah ada hari ini?
            $chk = $db->prepare("
                SELECT id, occurrence_count
                FROM saas_error_log
                WHERE DATE(tanggal) = CURDATE()
                  AND error_type    = ?
                  AND LEFT(error_message, ?) = ?
                LIMIT 1
            ");
            $chk->execute([$type, self::DEDUP_MSG_LEN, $dedupMsg]);
            $row = $chk->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                // Update hitungan + last_seen
                $db->prepare("
                    UPDATE saas_error_log
                    SET occurrence_count = occurrence_count + 1,
                        last_seen        = NOW()
                    WHERE id = ?
                ")->execute([$row['id']]);
            } else {
                // Insert error baru
                $trace = $stackTrace
                    ? substr($stackTrace, 0, self::MAX_TRACE_LEN)
                    : null;

                $db->prepare("
                    INSERT INTO saas_error_log
                      (tanggal, jam, tenant_id, outlet_id, url, method,
                       error_type, error_code, error_message, stack_trace,
                       first_seen, last_seen)
                    VALUES
                      (CURDATE(), CURTIME(), ?, ?, ?, ?,
                       ?, ?, ?, ?,
                       NOW(), NOW())
                ")->execute([
                    $tenantId, $outletId, $url, $method,
                    $type, $errorCode, $msgTrunc, $trace,
                ]);
            }
        } catch (Throwable $e) {
            // Jangan biarkan error logger sendiri melempar exception
            error_log('[ErrorLogger] Failed to log: ' . $e->getMessage());
        }
    }

    /**
     * Helper: log dari Throwable / Exception langsung.
     */
    public static function logException(
        string    $type,
        Throwable $e,
        ?int      $tenantId = null,
        ?int      $outletId = null
    ): void {
        self::log(
            $type,
            $e->getMessage(),
            $tenantId,
            $outletId,
            null,
            $e->getTraceAsString(),
            (string)$e->getCode()
        );
    }

    /**
     * Tandai error sebagai acknowledged.
     */
    public static function acknowledge(int $errorId): bool
    {
        try {
            Database::get()->prepare("
                UPDATE saas_error_log
                SET status = 'acknowledged'
                WHERE id   = ? AND status = 'new'
            ")->execute([$errorId]);
            return true;
        } catch (Throwable) { return false; }
    }

    /**
     * Tandai error sebagai resolved.
     */
    public static function resolve(int $errorId, int $resolvedBy, string $note = ''): bool
    {
        try {
            Database::get()->prepare("
                UPDATE saas_error_log
                SET status          = 'resolved',
                    resolved_by     = ?,
                    resolved_at     = NOW(),
                    resolution_note = ?
                WHERE id = ?
            ")->execute([$resolvedBy, $note ?: null, $errorId]);
            return true;
        } catch (Throwable) { return false; }
    }
}

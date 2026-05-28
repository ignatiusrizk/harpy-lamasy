<?php
// ══════════════════════════════════════════════════════
// core/WaLogger.php — Log semua pengiriman/pembuatan WA
//
// Dipanggil setiap kali WA link dibuat atau dikirim:
//   WaLogger::log('order_notif', $phone, $preview, $tenantId, $outletId);
//   WaLogger::fail('order_notif', $phone, $error, $tenantId, $outletId);
//
// Status 'sent' = link berhasil dibuat & dikirim ke browser
// Status 'failed' = ada error saat memproses WA
// ══════════════════════════════════════════════════════

class WaLogger
{
    /**
     * Catat WA yang berhasil dibuat/terkirim.
     *
     * @param string $type     order_notif | poin_notif | tiket | broadcast | reminder
     * @param string $phone    Nomor tujuan (format 62xxx)
     * @param string $preview  200 char pertama pesan
     */
    public static function log(
        string $type,
        string $phone,
        string $preview    = '',
        ?int   $tenantId   = null,
        ?int   $outletId   = null
    ): void {
        try {
            Database::get()->prepare("
                INSERT INTO saas_wa_log
                  (tenant_id, outlet_id, type, wa_target, pesan_preview, status, sent_at)
                VALUES (?, ?, ?, ?, ?, 'sent', NOW())
            ")->execute([
                $tenantId,
                $outletId,
                $type,
                substr(preg_replace('/[^0-9]/', '', $phone), 0, 20),
                substr($preview, 0, 200),
            ]);
        } catch (Throwable $e) {
            error_log('[WaLogger] ' . $e->getMessage());
        }
    }

    /**
     * Catat WA yang gagal dikirim.
     */
    public static function fail(
        string $type,
        string $phone,
        string $errorMsg   = '',
        ?int   $tenantId   = null,
        ?int   $outletId   = null
    ): void {
        try {
            Database::get()->prepare("
                INSERT INTO saas_wa_log
                  (tenant_id, outlet_id, type, wa_target, status, error_message)
                VALUES (?, ?, ?, ?, 'failed', ?)
            ")->execute([
                $tenantId,
                $outletId,
                $type,
                substr(preg_replace('/[^0-9]/', '', $phone), 0, 20),
                substr($errorMsg, 0, 500),
            ]);

            // Juga catat ke error log
            if (class_exists('ErrorLogger')) {
                ErrorLogger::log('wa_error', "WA gagal ke $phone: $errorMsg", $tenantId, $outletId);
            }
        } catch (Throwable $e) {
            error_log('[WaLogger::fail] ' . $e->getMessage());
        }
    }
}

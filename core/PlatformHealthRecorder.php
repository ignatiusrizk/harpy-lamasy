<?php
// ══════════════════════════════════════════════════════
// core/PlatformHealthRecorder.php
//
// Rekam snapshot usage harian ke saas_platform_health.
// Dipanggil dari superadmin_guard.php sekali per hari.
//
// Usage:
//   PlatformHealthRecorder::recordYesterdayIfNeeded();
// ══════════════════════════════════════════════════════

class PlatformHealthRecorder
{
    /**
     * Insert snapshot kemarin jika belum ada.
     * Aman dipanggil tiap request — ada guard UNIQUE KEY.
     */
    public static function recordYesterdayIfNeeded(): void
    {
        try {
            $db        = Database::get();
            $yesterday = date('Y-m-d', strtotime('-1 day'));

            // Cek apakah sudah ada record untuk kemarin
            $exists = $db->prepare(
                "SELECT 1 FROM saas_platform_health WHERE tanggal = ? LIMIT 1"
            );
            $exists->execute([$yesterday]);
            if ($exists->fetchColumn()) return;

            self::recordDate($db, $yesterday);
        } catch (Throwable $e) {
            error_log('[PlatformHealthRecorder] ' . $e->getMessage());
        }
    }

    /**
     * Paksa record untuk tanggal tertentu (untuk backfill / testing).
     */
    /** Alias backward-compat — spec menyebut recordDaily, implementasi sebenarnya recordYesterdayIfNeeded */
    public static function recordDaily(): void
    {
        self::recordYesterdayIfNeeded();
    }

    public static function recordDate(\PDO $db, string $date): void
    {
        // Tenant stats
        $r = $db->prepare("
            SELECT
              SUM(status = 'active') AS aktif,
              SUM(status = 'trial')  AS trial
            FROM tenants
        ");
        $r->execute();
        $tenantStats = $r->fetch(PDO::FETCH_ASSOC) ?: [];

        // Unique tenant login hari itu (dari audit log)
        $loginQ = $db->prepare("
            SELECT COUNT(DISTINCT tenant_id)
            FROM hl_audit_log
            WHERE DATE(created_at) = ?
        ");
        $loginQ->execute([$date]);
        $loginCount = (int)$loginQ->fetchColumn();

        // Total transaksi
        $txQ = $db->prepare("
            SELECT COUNT(*) FROM hl_transaksi WHERE DATE(created_at) = ?
        ");
        $txQ->execute([$date]);
        $totalTx = (int)$txQ->fetchColumn();

        // WA stats
        $waQ = $db->prepare("
            SELECT
              SUM(status = 'sent')   AS sent,
              SUM(status = 'failed') AS failed
            FROM saas_wa_log
            WHERE DATE(created_at) = ?
        ");
        $waQ->execute([$date]);
        $waStats = $waQ->fetch(PDO::FETCH_ASSOC) ?: [];

        // AI calls & coin
        $aiQ = $db->prepare("
            SELECT COUNT(*) as calls FROM hl_ai_cache WHERE DATE(created_at) = ?
        ");
        $aiQ->execute([$date]);
        $aiCalls = (int)$aiQ->fetchColumn();

        $coinUsedQ = $db->prepare("
            SELECT COALESCE(SUM(amount), 0)
            FROM coin_ledger
            WHERE DATE(created_at) = ? AND type = 'deduct'
        ");
        $coinUsedQ->execute([$date]);
        $coinUsed = (int)$coinUsedQ->fetchColumn();

        $coinSoldQ = $db->prepare("
            SELECT COALESCE(SUM(coin_dikreditkan), 0)
            FROM saas_manual_payments
            WHERE DATE(tanggal_bayar) = ? AND status = 'confirmed'
        ");
        $coinSoldQ->execute([$date]);
        $coinSold = (int)$coinSoldQ->fetchColumn();

        $revenueQ = $db->prepare("
            SELECT COALESCE(SUM(nominal_dibayar), 0)
            FROM saas_manual_payments
            WHERE DATE(tanggal_bayar) = ? AND status = 'confirmed'
        ");
        $revenueQ->execute([$date]);
        $revenue = (int)$revenueQ->fetchColumn();

        // Error counts
        $errQ = $db->prepare("
            SELECT
              SUM(error_type = 'php_error')    AS php_err,
              SUM(error_type = 'wa_error')     AS wa_err,
              SUM(error_type = 'ai_error')     AS ai_err
            FROM saas_error_log
            WHERE tanggal = ?
        ");
        $errQ->execute([$date]);
        $errStats = $errQ->fetch(PDO::FETCH_ASSOC) ?: [];

        $db->prepare("
            INSERT INTO saas_platform_health
              (tanggal,
               total_tenant_aktif, total_tenant_trial, tenant_login_hari_ini,
               total_transaksi,
               total_wa_terkirim, total_wa_gagal,
               total_ai_calls, total_ai_cost_coin,
               total_coin_terjual, total_coin_dipakai,
               total_revenue_hari,
               total_error_php, total_ai_error)
            VALUES
              (?, ?, ?, ?,
               ?,
               ?, ?,
               ?, ?,
               ?, ?,
               ?,
               ?, ?)
            ON DUPLICATE KEY UPDATE
              total_tenant_aktif    = VALUES(total_tenant_aktif),
              total_tenant_trial    = VALUES(total_tenant_trial),
              tenant_login_hari_ini = VALUES(tenant_login_hari_ini),
              total_transaksi       = VALUES(total_transaksi),
              total_wa_terkirim     = VALUES(total_wa_terkirim),
              total_wa_gagal        = VALUES(total_wa_gagal),
              total_ai_calls        = VALUES(total_ai_calls),
              total_ai_cost_coin    = VALUES(total_ai_cost_coin),
              total_coin_terjual    = VALUES(total_coin_terjual),
              total_coin_dipakai    = VALUES(total_coin_dipakai),
              total_revenue_hari    = VALUES(total_revenue_hari),
              total_error_php       = VALUES(total_error_php),
              total_ai_error        = VALUES(total_ai_error)
        ")->execute([
            $date,
            (int)($tenantStats['aktif']   ?? 0),
            (int)($tenantStats['trial']   ?? 0),
            $loginCount,
            $totalTx,
            (int)($waStats['sent']   ?? 0),
            (int)($waStats['failed'] ?? 0),
            $aiCalls,
            $coinUsed,
            $coinSold,
            $coinUsed,
            $revenue,
            (int)($errStats['php_err'] ?? 0),
            (int)($errStats['ai_err']  ?? 0),
        ]);
    }
}

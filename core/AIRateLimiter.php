<?php
// ══════════════════════════════════════════════════════
// core/AIRateLimiter.php — Daily quota per AI feature per tenant
//
// Track usage dari coin_ledger (no new table needed).
// Limit baca dari saas_coin_pricing.daily_limit (DB-driven, no deploy).
// Fallback ke DAILY_LIMITS constant kalau pricing belum punya kolom.
//
// Reset otomatis 00:00 lokal karena query by DATE(created_at)=CURDATE().
//
// Usage:
//   if (!AIRateLimiter::canCall('ai_briefing')) {
//       echo json_encode(AIRateLimiter::errorResponse('ai_briefing'));
//       exit;
//   }
// ══════════════════════════════════════════════════════

class AIRateLimiter
{
    // Fallback limits (kalau saas_coin_pricing.daily_limit kosong/0
    // dan kita tetap mau ada cap default).
    // 0 = unlimited.
    const DAILY_LIMITS = [
        'ai_briefing'          => 1,    // 1x/hari per outlet
        'ai_briefing_hq'       => 1,    // 1x/hari HQ
        'ai_upselling'         => 200,  // tinggi karena cached
        'ai_analyst'           => 20,
        'ai_chat_data'         => 30,   // Q&A data
        'ai_churn_message'     => 3,
        'ai_migration_mapping' => 10,
        'ai_review'            => 10,
        'ai_insight_laporan'   => 30,
    ];

    // Cache per-request untuk avoid bolak-balik query pricing
    private static array $limitCache = [];

    // ─── Public API ─────────────────────────────────────

    public static function canCall(string $feature, ?int $tenantId = null): bool
    {
        $tenantId = $tenantId ?? (int) TenantResolver::id();
        if ($tenantId <= 0) return true; // tidak ada tenant → skip (jarang)

        $limit = self::getLimit($feature);
        if ($limit <= 0) return true; // 0 = unlimited

        return self::getUsageToday($feature, $tenantId) < $limit;
    }

    public static function getUsageToday(string $feature, ?int $tenantId = null): int
    {
        $tenantId = $tenantId ?? (int) TenantResolver::id();
        if ($tenantId <= 0) return 0;

        try {
            $stmt = Database::get()->prepare(
                "SELECT COUNT(*) FROM coin_ledger
                 WHERE tenant_id = ?
                   AND feature_used = ?
                   AND type = 'deduct'
                   AND DATE(created_at) = CURDATE()"
            );
            $stmt->execute([$tenantId, $feature]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    public static function getRemainingToday(string $feature, ?int $tenantId = null): int
    {
        $limit = self::getLimit($feature);
        if ($limit <= 0) return PHP_INT_MAX; // unlimited

        $usage = self::getUsageToday($feature, $tenantId);
        return max(0, $limit - $usage);
    }

    /** Combo helper: returns [limit, used, remaining, unlimited?] */
    public static function status(string $feature, ?int $tenantId = null): array
    {
        $limit = self::getLimit($feature);
        if ($limit <= 0) {
            return [
                'limit'     => 0,
                'used'      => self::getUsageToday($feature, $tenantId),
                'remaining' => PHP_INT_MAX,
                'unlimited' => true,
            ];
        }
        $used = self::getUsageToday($feature, $tenantId);
        return [
            'limit'     => $limit,
            'used'      => $used,
            'remaining' => max(0, $limit - $used),
            'unlimited' => false,
        ];
    }

    public static function errorResponse(string $feature): array
    {
        $limit  = self::getLimit($feature);
        $label  = self::featureLabel($feature);
        $resetH = self::secondsToReset();
        $resetText = $resetH > 60
            ? floor($resetH / 3600) . ' jam ' . floor(($resetH % 3600) / 60) . ' menit lagi'
            : 'Kurang dari 1 menit';

        // Log ke saas_error_log untuk monitoring abuse di SA dashboard
        self::logRateLimit($feature);

        return [
            'success' => false,
            'error'   => 'rate_limited',
            'feature' => $feature,
            'message' => "Limit harian {$label} sudah tercapai ({$limit}x/hari). Reset dalam {$resetText} (00:00 WIB).",
            'limit'   => $limit,
            'reset'   => '00:00 WIB (' . $resetText . ')',
        ];
    }

    /** Stats per feature untuk SA health dashboard */
    public static function getPlatformStats(string $feature, ?string $date = null): array
    {
        $date = $date ?? date('Y-m-d');
        try {
            $stmt = Database::get()->prepare(
                "SELECT COUNT(*)                  AS total_calls,
                        COUNT(DISTINCT tenant_id) AS unique_tenants,
                        COALESCE(SUM(amount), 0)  AS total_coin
                 FROM coin_ledger
                 WHERE feature_used = ?
                   AND type = 'deduct'
                   AND DATE(created_at) = ?"
            );
            $stmt->execute([$feature, $date]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            // Max calls oleh single tenant (untuk detect heavy user)
            $stmt2 = Database::get()->prepare(
                "SELECT MAX(cnt) AS max_single FROM (
                    SELECT COUNT(*) AS cnt FROM coin_ledger
                    WHERE feature_used = ? AND type='deduct' AND DATE(created_at)=?
                    GROUP BY tenant_id
                 ) sub"
            );
            $stmt2->execute([$feature, $date]);
            $stats['max_single_tenant'] = (int) $stmt2->fetchColumn();

            return [
                'feature'           => $feature,
                'total_calls'       => (int)($stats['total_calls'] ?? 0),
                'unique_tenants'    => (int)($stats['unique_tenants'] ?? 0),
                'total_coin'        => (int)($stats['total_coin'] ?? 0),
                'max_single_tenant' => (int)($stats['max_single_tenant'] ?? 0),
            ];
        } catch (Throwable) {
            return ['feature'=>$feature,'total_calls'=>0,'unique_tenants'=>0,'total_coin'=>0,'max_single_tenant'=>0];
        }
    }

    /** List tenants yg hit rate limit >=N kali hari ini (alert abuse) */
    public static function getAbusersToday(int $minHits = 3): array
    {
        try {
            $stmt = Database::get()->prepare(
                "SELECT tenant_id, error_code AS feature, SUM(occurrence_count) AS attempts
                 FROM saas_error_log
                 WHERE error_type = 'rate_limited'
                   AND tanggal = CURDATE()
                 GROUP BY tenant_id, error_code
                 HAVING attempts >= ?
                 ORDER BY attempts DESC
                 LIMIT 50"
            );
            $stmt->execute([$minHits]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    // ─── Internals ─────────────────────────────────────

    private static function getLimit(string $feature): int
    {
        if (array_key_exists($feature, self::$limitCache)) {
            return self::$limitCache[$feature];
        }

        // Coba ambil dari saas_coin_pricing.daily_limit (kalau kolom udh ada)
        $dbLimit = null;
        try {
            $stmt = Database::get()->prepare(
                "SELECT daily_limit FROM saas_coin_pricing
                 WHERE feature_key = ? AND is_active = 1 LIMIT 1"
            );
            $stmt->execute([$feature]);
            $val = $stmt->fetchColumn();
            if ($val !== false) $dbLimit = (int) $val;
        } catch (Throwable) {
            // kolom belum ada / pricing belum di-seed → fallback ke constant
        }

        // Fallback ke constant kalau dbLimit null
        if ($dbLimit === null) {
            $dbLimit = self::DAILY_LIMITS[$feature] ?? 50;
        }

        self::$limitCache[$feature] = $dbLimit;
        return $dbLimit;
    }

    private static function featureLabel(string $feature): string
    {
        $labels = [
            'ai_briefing'          => 'AI Briefing Harian',
            'ai_briefing_hq'       => 'AI Briefing HQ',
            'ai_upselling'         => 'AI Upselling',
            'ai_analyst'           => 'AI Analyst Laporan',
            'ai_chat_data'         => 'AI Chat Data',
            'ai_churn_message'     => 'AI Pesan Retensi',
            'ai_migration_mapping' => 'AI Migration Mapper',
            'ai_review'            => 'AI Review Responder',
            'ai_insight_laporan'   => 'AI Insight Laporan',
        ];
        return $labels[$feature] ?? str_replace('_', ' ', ucfirst($feature));
    }

    private static function secondsToReset(): int
    {
        // Detik sampai 00:00 berikutnya (server timezone)
        $now      = time();
        $tomorrow = strtotime('tomorrow midnight');
        return max(0, $tomorrow - $now);
    }

    private static function logRateLimit(string $feature): void
    {
        try {
            $tenantId = (int) TenantResolver::id();
            $userId   = (int) ($_SESSION['user_id'] ?? 0) ?: null;
            $outletId = (int) TenantResolver::outletId() ?: null;

            // Upsert pattern manual (saas_error_log gak punya UNIQUE)
            // Cari record hari ini dgn feature+tenant yg sama
            $stmt = Database::get()->prepare(
                "SELECT id FROM saas_error_log
                 WHERE error_type='rate_limited' AND error_code=? AND tenant_id=? AND tanggal=CURDATE()
                 LIMIT 1"
            );
            $stmt->execute([$feature, $tenantId]);
            $existing = (int) $stmt->fetchColumn();

            if ($existing) {
                Database::get()->prepare(
                    "UPDATE saas_error_log
                     SET occurrence_count = occurrence_count + 1, jam = CURRENT_TIME()
                     WHERE id = ?"
                )->execute([$existing]);
            } else {
                Database::get()->prepare(
                    "INSERT INTO saas_error_log
                       (tanggal, jam, tenant_id, outlet_id, user_id, error_type, error_code, error_message, occurrence_count)
                     VALUES (CURDATE(), CURRENT_TIME(), ?, ?, ?, 'rate_limited', ?, ?, 1)"
                )->execute([
                    $tenantId, $outletId, $userId, $feature,
                    'Tenant hit daily rate limit untuk ' . $feature,
                ]);
            }
        } catch (Throwable) { /* non-fatal */ }
    }
}

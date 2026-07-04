<?php
// core/AIRateLimiter.php — Daily quota per AI feature per tenant.
// Usage tracked via coin_ledger (no new table). Limit from saas_coin_pricing.daily_limit.
// Reset 00:00 server time via DATE(created_at)=CURDATE().

class AIRateLimiter
{
    // Fallback kalau pricing row tidak ada / daily_limit=0 tapi tetap mau ada cap.
    // 0 (atau key tidak ada di array) = truly unlimited.
    const FALLBACK_LIMITS = [
        'ai_briefing' => 1, 'ai_briefing_hq' => 1, 'ai_upselling' => 200,
        'ai_analyst' => 20, 'ai_chat_data' => 30, 'ai_churn_message' => 3,
        'ai_migration_mapping' => 10, 'ai_review' => 10, 'ai_insight_laporan' => 30,
    ];

    /** Single source of truth. Returns label/limit/used/remaining/ok/unlimited + tier info. */
    public static function status(string $feature, ?int $tenantId = null): array
    {
        $tid = $tenantId ?? (int) TenantResolver::id();
        [$limit, $label] = self::lookup($feature);
        // Trial AI Boost: gandakan limit harian selama trial (Brief 2).
        $boosted = class_exists('CoinLedger') && CoinLedger::isTrialBoost($feature);
        if ($boosted && $limit > 0) $limit *= 2;
        $used = self::countToday($feature, $tid);
        $unlimited = $limit <= 0;

        // Tier-aware pricing (kalau feature punya pricing_tiers)
        $tiers = null;
        $nextPrice = null;
        $currentPrice = null;
        if (class_exists('CoinLedger')) {
            $tiers = CoinLedger::getTiers($feature);
            if ($tiers && is_array($tiers)) {
                // Price untuk panggilan berikutnya (used = sudah dipakai N kali → next adalah ke-(N+1) → index N)
                $nextPrice = CoinLedger::getHargaForCall($feature, $used);
                if ($used > 0) {
                    $currentPrice = CoinLedger::getHargaForCall($feature, $used - 1);
                }
            }
        }

        return [
            'feature'       => $feature,
            'label'         => $label,
            'limit'         => $limit,
            'used'          => $used,
            'remaining'     => $unlimited ? PHP_INT_MAX : max(0, $limit - $used),
            'unlimited'     => $unlimited,
            'ok'            => $unlimited || $used < $limit,
            'tiers'         => $tiers,           // array of progressive prices atau null
            'next_price'    => $nextPrice,        // price untuk call berikutnya
            'current_price' => $currentPrice,     // price untuk last call (kalau ada)
            'boosted'       => $boosted,          // gratis selama trial (Trial AI Boost)
        ];
    }

    public static function canCall(string $feature, ?int $tenantId = null): bool
    {
        return self::status($feature, $tenantId)['ok'];
    }

    public static function getUsageToday(string $feature, ?int $tenantId = null): int
    {
        return self::status($feature, $tenantId)['used'];
    }

    public static function getRemainingToday(string $feature, ?int $tenantId = null): int
    {
        return self::status($feature, $tenantId)['remaining'];
    }

    /** Pakai di handler AI: echo errorResponse() lalu exit. Auto-log block utk abuse detection. */
    public static function errorResponse(string $feature): array
    {
        $s = self::status($feature);
        self::logBlock($feature);
        $secs    = strtotime('tomorrow midnight') - time();
        $resetIn = sprintf('%dj %dm', $secs / 3600, ($secs % 3600) / 60);
        $msg = $s['tiers']
            ? "{$s['label']} sudah dipakai {$s['used']} dari {$s['limit']}× hari ini (maksimum tercapai). Reset dalam {$resetIn}."
            : "Limit harian {$s['label']} tercapai ({$s['limit']}×/hari). Reset dalam {$resetIn}.";
        return [
            'success' => false,
            'error'   => 'rate_limited',
            'feature' => $feature,
            'message' => $msg,
            'limit'   => $s['limit'],
            'used'    => $s['used'],
            'tiers'   => $s['tiers'],
            'reset'   => "00:00 WIB ({$resetIn})",
        ];
    }

    public static function getPlatformStats(string $feature, ?string $date = null): array
    {
        $date = $date ?? date('Y-m-d');
        try {
            $s = Database::get()->prepare(
                "SELECT COUNT(*) calls, COUNT(DISTINCT tenant_id) tenants, COALESCE(SUM(amount),0) coin
                 FROM coin_ledger WHERE feature_used=? AND type='deduct' AND DATE(created_at)=?"
            );
            $s->execute([$feature, $date]);
            $r = $s->fetch() ?: ['calls'=>0,'tenants'=>0,'coin'=>0];
            $maxSingle = 0;
            if ($r['calls'] > 0) {
                $s2 = Database::get()->prepare(
                    "SELECT MAX(c) FROM (
                       SELECT COUNT(*) c FROM coin_ledger
                       WHERE feature_used=? AND type='deduct' AND DATE(created_at)=?
                       GROUP BY tenant_id
                     ) x"
                );
                $s2->execute([$feature, $date]);
                $maxSingle = (int) $s2->fetchColumn();
            }
            return [
                'feature'           => $feature,
                'total_calls'       => (int) $r['calls'],
                'unique_tenants'    => (int) $r['tenants'],
                'total_coin'        => (int) $r['coin'],
                'max_single_tenant' => $maxSingle,
            ];
        } catch (Throwable) {
            return ['feature'=>$feature,'total_calls'=>0,'unique_tenants'=>0,'total_coin'=>0,'max_single_tenant'=>0];
        }
    }

    public static function getAbusersToday(int $minHits = 3): array
    {
        try {
            $s = Database::get()->prepare(
                "SELECT tenant_id, error_code feature, SUM(occurrence_count) attempts
                 FROM saas_error_log
                 WHERE error_type='rate_limited' AND tanggal=CURDATE()
                 GROUP BY tenant_id, error_code
                 HAVING attempts >= ?
                 ORDER BY attempts DESC LIMIT 50"
            );
            $s->execute([$minHits]);
            return $s->fetchAll() ?: [];
        } catch (Throwable) { return []; }
    }

    // ─── Internals ─────

    /** @return array{0:int, 1:string} [limit, label] */
    private static function lookup(string $feature): array
    {
        // ponytail: 1 query/call. Add cache when profiler says so (pricing = 9 rows, cheap).
        try {
            $s = Database::get()->prepare(
                "SELECT daily_limit, nama_fitur FROM saas_coin_pricing
                 WHERE feature_key=? AND is_active=1 LIMIT 1"
            );
            $s->execute([$feature]);
            $r = $s->fetch();
            if ($r) {
                $limit = (int) $r['daily_limit'];
                if ($limit <= 0) $limit = self::FALLBACK_LIMITS[$feature] ?? 0;
                return [$limit, $r['nama_fitur'] ?: $feature];
            }
        } catch (Throwable) {}
        return [self::FALLBACK_LIMITS[$feature] ?? 0, ucwords(str_replace('_', ' ', $feature))];
    }

    private static function countToday(string $feature, int $tenantId): int
    {
        if ($tenantId <= 0) return 0;
        try {
            $s = Database::get()->prepare(
                "SELECT COUNT(*) FROM coin_ledger
                 WHERE tenant_id=? AND feature_used=? AND type='deduct' AND DATE(created_at)=CURDATE()"
            );
            $s->execute([$tenantId, $feature]);
            return (int) $s->fetchColumn();
        } catch (Throwable) { return 0; }
    }

    private static function logBlock(string $feature): void
    {
        // ponytail: INSERT only, no upsert dance. getAbusersToday() SUMs occurrence_count.
        // Volume small (1 row per blocked attempt) — promote to upsert if log size becomes issue.
        try {
            Database::get()->prepare(
                "INSERT INTO saas_error_log
                   (tanggal, jam, tenant_id, outlet_id, user_id, error_type, error_code, error_message, occurrence_count)
                 VALUES (CURDATE(), CURRENT_TIME(), ?, ?, ?, 'rate_limited', ?, ?, 1)"
            )->execute([
                (int) TenantResolver::id(),
                (int) TenantResolver::outletId() ?: null,
                (int) ($_SESSION['user_id'] ?? 0) ?: null,
                $feature,
                "Rate limit hit: $feature",
            ]);
        } catch (Throwable) { /* non-fatal */ }
    }
}

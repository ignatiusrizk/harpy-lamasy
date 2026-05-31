<?php
// ══════════════════════════════════════════════════════════════════
// core/AIBudget.php — Limit AI usage per tenant per hari + tracking
//
// USAGE PATTERN:
//
//   // Sebelum panggil AI:
//   try {
//       AIBudget::checkOrThrow($tenantId, 'ai_briefing');
//   } catch (RuntimeException $e) {
//       echo json_encode(['error' => $e->getMessage()]); exit;
//   }
//
//   // Setelah API call sukses:
//   AIBudget::record(
//       $tenantId, $outletId, 'ai_briefing',
//       $result['tokens_in'], $result['tokens_out'],
//       $coinCharged, $modelName, $fromCache
//   );
// ══════════════════════════════════════════════════════════════════

class AIBudget
{
    // Anthropic Claude Sonnet 4.5 pricing (per 1M tokens)
    const PRICE_INPUT_USD_PER_M  = 3.00;
    const PRICE_OUTPUT_USD_PER_M = 15.00;
    const USD_TO_IDR             = 16000; // approximation untuk display

    const DEFAULT_DAILY_BUDGET = 10000;   // 10k coin = ~60 AI calls

    // ── Ambil budget harian tenant ──────────────────────
    public static function getBudget(int $tenantId): int
    {
        static $cache = [];
        if (isset($cache[$tenantId])) return $cache[$tenantId];
        try {
            $stmt = Database::get()->prepare("SELECT ai_daily_budget_coin FROM tenants WHERE id = ?");
            $stmt->execute([$tenantId]);
            $val = (int)($stmt->fetchColumn() ?: self::DEFAULT_DAILY_BUDGET);
        } catch (Throwable) {
            $val = self::DEFAULT_DAILY_BUDGET; // tabel/kolom belum ada
        }
        return $cache[$tenantId] = $val;
    }

    // ── Ambil penggunaan hari ini (sum coin AI dari coin_ledger) ──
    public static function getTodayUsage(int $tenantId): int
    {
        try {
            $stmt = Database::get()->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM coin_ledger
                  WHERE tenant_id = ?
                    AND type = 'deduct'
                    AND feature_used LIKE 'ai\\_%'
                    AND DATE(created_at) = CURDATE()"
            );
            $stmt->execute([$tenantId]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    // ── Status budget {used, budget, remaining, exhausted} ──
    public static function status(int $tenantId): array
    {
        $budget = self::getBudget($tenantId);
        $used   = self::getTodayUsage($tenantId);
        $rem    = max(0, $budget - $used);
        return [
            'budget'    => $budget,
            'used'      => $used,
            'remaining' => $rem,
            'exhausted' => $budget > 0 && $used >= $budget,
        ];
    }

    // ── Cek apakah fitur AI bisa dipakai sekarang ──
    // Throw kalau budget habis (validate sebelum kirim ke Anthropic)
    public static function checkOrThrow(int $tenantId, string $feature): void
    {
        // Hanya cek untuk fitur AI
        if (strpos($feature, 'ai_') !== 0) return;

        $budget = self::getBudget($tenantId);
        if ($budget <= 0) return; // 0 = unlimited

        $used = self::getTodayUsage($tenantId);
        $cost = 0;
        if (class_exists('CoinLedger')) {
            try { $cost = CoinLedger::getHarga($feature); } catch (Throwable) {}
        }

        if (($used + $cost) > $budget) {
            $remaining = max(0, $budget - $used);
            throw new RuntimeException(
                "Budget AI hari ini sudah hampir habis ($used / $budget coin terpakai, sisa $remaining). "
              . "Reset otomatis jam 00:00. Kalau perlu naikkan budget, hubungi admin LaMaSy."
            );
        }
    }

    // ── Catat penggunaan AI ke hl_ai_usage ──
    public static function record(
        int     $tenantId,
        ?int    $outletId,
        string  $feature,
        int     $tokensIn,
        int     $tokensOut,
        int     $coinCharged = 0,
        ?string $model = null,
        bool    $fromCache = false
    ): void {
        try {
            $costIdr = $fromCache ? 0 : self::estimateCostIdr($tokensIn, $tokensOut);
            Database::get()->prepare(
                "INSERT INTO hl_ai_usage
                    (tenant_id, outlet_id, feature_key, tokens_in, tokens_out,
                     cost_estimated_idr, coin_charged, model, from_cache)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            )->execute([
                $tenantId, $outletId, $feature, $tokensIn, $tokensOut,
                $costIdr, $coinCharged, $model, $fromCache ? 1 : 0,
            ]);
        } catch (Throwable $e) {
            // Best-effort logging — jangan break AI flow
            error_log('[AIBudget::record] ' . $e->getMessage());
        }
    }

    // ── Estimate cost dalam Rupiah ─────────────────────
    public static function estimateCostIdr(int $tokensIn, int $tokensOut): int
    {
        $usd = ($tokensIn  / 1_000_000) * self::PRICE_INPUT_USD_PER_M
             + ($tokensOut / 1_000_000) * self::PRICE_OUTPUT_USD_PER_M;
        return (int)round($usd * self::USD_TO_IDR);
    }

    // ── Aggregate untuk dashboard superadmin ────────────
    public static function aggregateByTenant(string $startDate, string $endDate, int $limit = 50): array
    {
        $stmt = Database::get()->prepare(
            "SELECT u.tenant_id,
                    t.nama_perusahaan,
                    t.email AS owner_email,
                    t.ai_daily_budget_coin,
                    COUNT(*)                  AS total_calls,
                    SUM(u.tokens_in)          AS tokens_in,
                    SUM(u.tokens_out)         AS tokens_out,
                    SUM(u.cost_estimated_idr) AS cost_idr,
                    SUM(u.coin_charged)       AS coin_charged,
                    SUM(u.from_cache)         AS cache_hits
               FROM hl_ai_usage u
               LEFT JOIN tenants t ON t.id = u.tenant_id
              WHERE DATE(u.created_at) BETWEEN ? AND ?
              GROUP BY u.tenant_id
              ORDER BY cost_idr DESC
              LIMIT $limit"
        );
        $stmt->execute([$startDate, $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function aggregateByFeature(string $startDate, string $endDate): array
    {
        $stmt = Database::get()->prepare(
            "SELECT feature_key,
                    COUNT(*)                  AS total_calls,
                    SUM(tokens_in)            AS tokens_in,
                    SUM(tokens_out)           AS tokens_out,
                    SUM(cost_estimated_idr)   AS cost_idr,
                    SUM(coin_charged)         AS coin_charged,
                    SUM(from_cache)           AS cache_hits
               FROM hl_ai_usage
              WHERE DATE(created_at) BETWEEN ? AND ?
              GROUP BY feature_key
              ORDER BY cost_idr DESC"
        );
        $stmt->execute([$startDate, $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function aggregateByDate(string $startDate, string $endDate): array
    {
        $stmt = Database::get()->prepare(
            "SELECT DATE(created_at)          AS tanggal,
                    COUNT(*)                  AS total_calls,
                    SUM(tokens_in + tokens_out) AS tokens_total,
                    SUM(cost_estimated_idr)   AS cost_idr,
                    SUM(coin_charged)         AS coin_charged
               FROM hl_ai_usage
              WHERE DATE(created_at) BETWEEN ? AND ?
              GROUP BY DATE(created_at)
              ORDER BY tanggal ASC"
        );
        $stmt->execute([$startDate, $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

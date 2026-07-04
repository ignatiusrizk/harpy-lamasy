<?php
// ══════════════════════════════════════════════════════
// core/StickinessTracker.php — Stickiness Score (Strategi #5)
//
// 4 kriteria keterikatan per tenant (target ≥3 sebelum hari-7 trial):
//   1. data_ada        — ≥N transaksi & ≥N customer
//   2. laporan_dilihat — owner pernah buka laporan keuangan (event)
//   3. portal_token    — ≥1 customer punya portal_token
//   4. staf_aktif      — ≥1 staf non-owner sudah transaksi di POS
//
// Score internal (CS/SA), JANGAN ditampilkan literal ke tenant.
// ══════════════════════════════════════════════════════

require_once __DIR__ . '/Database.php';

class StickinessTracker
{
    const MIN_TRANSAKSI = 3;   // threshold kriteria 1 (sesuaikan setelah lihat data)
    const MIN_CUSTOMER  = 2;

    /** Catat event keterikatan (mis. 'viewed_financial_report'). Idempotent-ish: 1 baris/panggil. */
    public static function logEvent(int $tenantId, string $eventType): void
    {
        if ($tenantId <= 0 || $eventType === '') return;
        try {
            // Cukup catat sekali per tenant+event (hemat baris) — cek dulu.
            $db = Database::get();
            $chk = $db->prepare("SELECT 1 FROM hl_stickiness_events WHERE tenant_id=? AND event_type=? LIMIT 1");
            $chk->execute([$tenantId, $eventType]);
            if ($chk->fetchColumn()) return;
            $db->prepare("INSERT INTO hl_stickiness_events (tenant_id, event_type) VALUES (?,?)")
               ->execute([$tenantId, $eventType]);
        } catch (Throwable $e) { error_log('[StickinessTracker::logEvent] '.$e->getMessage()); }
    }

    public static function hasEvent(int $tenantId, string $eventType): bool
    {
        try {
            $s = Database::get()->prepare("SELECT 1 FROM hl_stickiness_events WHERE tenant_id=? AND event_type=? LIMIT 1");
            $s->execute([$tenantId, $eventType]);
            return (bool)$s->fetchColumn();
        } catch (Throwable) { return false; }
    }

    /**
     * Hitung 4 kriteria + score untuk 1 tenant.
     * @return array{score:int, target_met:bool, criteria:array<string,bool>}
     */
    public static function calculate(int $tenantId): array
    {
        $db = Database::get();
        $criteria = [
            'data_ada'        => false,
            'laporan_dilihat' => false,
            'portal_token'    => false,
            'staf_aktif'      => false,
        ];
        try {
            // 1. Data ada
            $nTrx  = (int)self::scalar($db, "SELECT COUNT(*) FROM hl_transaksi WHERE tenant_id=?", [$tenantId]);
            $nCust = (int)self::scalar($db, "SELECT COUNT(*) FROM hl_pelanggan WHERE tenant_id=?", [$tenantId]);
            $criteria['data_ada'] = ($nTrx >= self::MIN_TRANSAKSI && $nCust >= self::MIN_CUSTOMER);

            // 2. Laporan keuangan dilihat
            $criteria['laporan_dilihat'] = self::hasEvent($tenantId, 'viewed_financial_report');

            // 3. Customer punya portal token
            $criteria['portal_token'] = (int)self::scalar($db,
                "SELECT COUNT(*) FROM hl_pelanggan WHERE tenant_id=? AND portal_token IS NOT NULL AND portal_token<>''",
                [$tenantId]) >= 1;

            // 4. Staf non-owner sudah transaksi
            $criteria['staf_aktif'] = (int)self::scalar($db,
                "SELECT COUNT(DISTINCT t.created_by)
                   FROM hl_transaksi t JOIN hl_users u ON u.id=t.created_by
                  WHERE t.tenant_id=? AND t.created_by IS NOT NULL AND u.role<>'owner'",
                [$tenantId]) >= 1;
        } catch (Throwable $e) { error_log('[StickinessTracker::calculate] '.$e->getMessage()); }

        $score = count(array_filter($criteria));
        return ['score' => $score, 'target_met' => $score >= 3, 'criteria' => $criteria];
    }

    /** Simpan snapshot harian (upsert per tenant+tanggal). Return score. */
    public static function snapshot(int $tenantId): int
    {
        $r = self::calculate($tenantId);
        $c = $r['criteria'];
        try {
            Database::get()->prepare(
                "INSERT INTO hl_stickiness_score (tenant_id, tanggal, score, data_ada, laporan_dilihat, portal_token, staf_aktif)
                 VALUES (?, CURDATE(), ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE score=VALUES(score), data_ada=VALUES(data_ada),
                    laporan_dilihat=VALUES(laporan_dilihat), portal_token=VALUES(portal_token), staf_aktif=VALUES(staf_aktif)"
            )->execute([$tenantId, $r['score'], (int)$c['data_ada'], (int)$c['laporan_dilihat'],
                        (int)$c['portal_token'], (int)$c['staf_aktif']]);
        } catch (Throwable $e) { error_log('[StickinessTracker::snapshot] '.$e->getMessage()); }
        return $r['score'];
    }

    /** Snapshot semua tenant yang punya outlet trial. Return jumlah tenant diproses. */
    public static function snapshotAllTrials(): int
    {
        try {
            $ids = Database::get()->query(
                "SELECT DISTINCT tenant_id FROM outlets WHERE status='trial'"
            )->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) { error_log('[StickinessTracker::snapshotAll] '.$e->getMessage()); return 0; }
        foreach ($ids as $tid) { self::snapshot((int)$tid); }
        return count($ids);
    }

    /** Label + saran untuk kriteria yang belum terpenuhi (dipakai nudge & dashboard SA). */
    public static function missingLabels(array $criteria): array
    {
        $map = [
            'data_ada'        => 'Data transaksi/customer',
            'laporan_dilihat' => 'Laporan keuangan',
            'portal_token'    => 'Portal customer',
            'staf_aktif'      => 'Staf aktif',
        ];
        $out = [];
        foreach ($criteria as $k => $ok) { if (!$ok && isset($map[$k])) $out[$k] = $map[$k]; }
        return $out;
    }

    private static function scalar(PDO $db, string $sql, array $p)
    {
        $s = $db->prepare($sql); $s->execute($p); return $s->fetchColumn();
    }
}

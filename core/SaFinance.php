<?php
// core/SaFinance.php — Kalkulasi keuangan platform utk laporan SuperAdmin.
// Murni SELECT + return array. Tidak echo. Semua rentang tanggal 'Y-m-d' (null=tanpa batas).
if (!class_exists('Database')) require_once __DIR__ . '/Database.php';

class SaFinance
{
    const COIN_TO_IDR = 4.17; // samakan dgn superadmin/ai_usage.php

    // Klausa rentang tanggal inklusif (col >= from AND col <= to). Return [sqlFragment, params].
    private static function range(string $col, ?string $from, ?string $to): array
    {
        $sql = ''; $p = [];
        if ($from) { $sql .= " AND $col >= ?"; $p[] = $from; }
        if ($to)   { $sql .= " AND $col <= ?"; $p[] = $to; }
        return [$sql, $p];
    }

    // Tenant demo (is_demo=1) dikecualikan dari SEMUA angka keuangan platform —
    // aktivitas demo (coin seed 999rb, AI briefing pengunjung, dst.) bukan uang nyata.
    private static function notDemo(string $col): string
    {
        return " AND $col NOT IN (SELECT id FROM tenants WHERE is_demo=1)";
    }

    // Subquery revenue gabungan — sama persis dgn billing.php smpRevenueSource.
    // Kolom ternormalisasi: tenant_id, type(setup_fee|coin_topup|adjustment), nominal, coin, bayar(DATE).
    private static function revenueSource(): string
    {
        return "(
            SELECT tenant_id,
                   CASE WHEN type='custom' THEN 'adjustment' ELSE type END AS type,
                   nominal_dibayar AS nominal, coin_dikreditkan AS coin,
                   tanggal_bayar AS bayar
            FROM saas_manual_payments WHERE status='confirmed'" . self::notDemo('tenant_id') . "
            UNION ALL
            SELECT sp.tenant_id,
                   CASE WHEN sp.type='topup_coin' THEN 'coin_topup' ELSE 'setup_fee' END AS type,
                   sp.amount AS nominal,
                   COALESCE((SELECT cl.amount FROM coin_ledger cl
                             WHERE cl.payment_id=sp.id AND cl.type='topup' LIMIT 1),0) AS coin,
                   DATE(COALESCE(sp.paid_at, sp.created_at)) AS bayar
            FROM saas_payments sp WHERE sp.status='paid'" . self::notDemo('sp.tenant_id') . "
        ) rev";
    }

    public static function revenue(?string $from, ?string $to): array
    {
        [$r, $p] = self::range('bayar', $from, $to);
        try {
            $sql = "SELECT
                      COALESCE(SUM(CASE WHEN type='setup_fee'  THEN nominal END),0) AS setup_fee,
                      COALESCE(SUM(CASE WHEN type='coin_topup' THEN nominal END),0) AS coin_topup,
                      COALESCE(SUM(CASE WHEN type='adjustment' THEN nominal END),0) AS adjustment,
                      COALESCE(SUM(nominal),0) AS total,
                      COALESCE(SUM(coin),0)    AS coin_credited
                    FROM " . self::revenueSource() . " WHERE 1=1" . $r;
            $st = Database::get()->prepare($sql); $st->execute($p);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) { $row = []; }
        return [
            'setup_fee'     => (int)($row['setup_fee'] ?? 0),
            'coin_topup'    => (int)($row['coin_topup'] ?? 0),
            'adjustment'    => (int)($row['adjustment'] ?? 0),
            'total'         => (int)($row['total'] ?? 0),
            'coin_credited' => (int)($row['coin_credited'] ?? 0),
        ];
    }

    public static function aiCost(?string $from, ?string $to): int
    {
        [$r, $p] = self::range('DATE(created_at)', $from, $to);
        try {
            $st = Database::get()->prepare(
                "SELECT COALESCE(SUM(cost_estimated_idr),0) FROM hl_ai_usage WHERE 1=1" . self::notDemo('tenant_id') . $r);
            $st->execute($p);
            return (int)$st->fetchColumn();
        } catch (Throwable) { return 0; }
    }

    public static function affiliatePayout(?string $from, ?string $to): int
    {
        [$r, $p] = self::range('DATE(paid_at)', $from, $to);
        try {
            $st = Database::get()->prepare(
                "SELECT COALESCE(SUM(jumlah),0) FROM hl_affiliate_payout WHERE status='paid'" . $r);
            $st->execute($p);
            return (int)$st->fetchColumn();
        } catch (Throwable) { return 0; }
    }

    public static function coinConsumed(?string $from, ?string $to): int
    {
        [$r, $p] = self::range('DATE(created_at)', $from, $to);
        try {
            $st = Database::get()->prepare(
                "SELECT COALESCE(SUM(amount),0) FROM coin_ledger WHERE type='deduct'" . self::notDemo('tenant_id') . $r);
            $st->execute($p);
            return (int)$st->fetchColumn();
        } catch (Throwable) { return 0; }
    }

    public static function coinFloat(): array
    {
        try {
            $out = (int)Database::get()->query("SELECT COALESCE(SUM(coin_balance),0) FROM tenants WHERE is_demo=0")->fetchColumn();
        } catch (Throwable) { $out = 0; }
        return ['coin_outstanding' => $out, 'rp_estimate' => (int)round($out * self::COIN_TO_IDR)];
    }

    public static function revenueRows(?string $from, ?string $to): array
    {
        [$r, $p] = self::range('bayar', $from, $to);
        try {
            $sql = "SELECT rev.bayar AS tanggal, rev.tenant_id, rev.type AS tipe,
                           rev.nominal, rev.coin,
                           COALESCE(t.nama_perusahaan, CONCAT('Tenant #', rev.tenant_id)) AS tenant_nama
                    FROM " . self::revenueSource() . "
                    LEFT JOIN tenants t ON t.id = rev.tenant_id
                    WHERE 1=1" . $r . " ORDER BY rev.bayar DESC, rev.tenant_id";
            $st = Database::get()->prepare($sql); $st->execute($p);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) { $rows = []; }
        return array_map(fn($x) => [
            'tanggal'     => $x['tanggal'],
            'tenant_id'   => (int)$x['tenant_id'],
            'tenant_nama' => $x['tenant_nama'],
            'tipe'        => $x['tipe'],
            'nominal'     => (int)$x['nominal'],
            'coin'        => (int)$x['coin'],
        ], $rows);
    }

    public static function pnl(?string $from, ?string $to): array
    {
        $rev = self::revenue($from, $to);
        $ai  = self::aiCost($from, $to);
        $aff = self::affiliatePayout($from, $to);
        $totalCost = $ai + $aff;
        $laba = $rev['total'] - $totalCost;
        $margin = $rev['total'] > 0 ? round($laba / $rev['total'] * 100, 1) : 0.0;
        return [
            'revenue' => $rev, 'ai_cost' => $ai, 'affiliate' => $aff,
            'total_cost' => $totalCost, 'laba' => $laba, 'margin_pct' => $margin,
            'coin' => self::coinFloat(), 'coin_consumed' => self::coinConsumed($from, $to),
        ];
    }
}

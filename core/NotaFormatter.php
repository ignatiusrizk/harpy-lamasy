<?php
// ══════════════════════════════════════════════════════
// core/NotaFormatter.php
//
// Generate nomor nota custom per tenant. Inspired by Smartlink
// "Nomor Nota Premium" feature.
//
// Token yang didukung di template (saas_tenants.nota_format):
//   {PREFIX}    → saas_tenants.nota_prefix (mis. "HL-", "HARPY-", "JL-")
//   {YYYY}      → tahun 4 digit (2026)
//   {YY}        → tahun 2 digit (26)
//   {MM}        → bulan 2 digit (01-12)
//   {DD}        → tanggal 2 digit (01-31)
//   {YYMMDD}    → date 6-digit (260606)
//   {YYYYMMDD}  → date 8-digit (20260606)
//   {COUNTER}   → sequence per outlet per hari (1, 2, 3...)
//   {COUNTER:3} → padded sequence (001, 002, 003) — angka N = padding
//   {OUTLET}    → outlet kode (3 char dari nama, uppercase)
//
// Contoh format:
//   "{PREFIX}{YYMMDD}-{COUNTER:3}"  → HL-260606-001
//   "{PREFIX}{COUNTER:5}"           → HARPY-00001
//   "{PREFIX}{OUTLET}-{YYYY}-{COUNTER:4}" → JL-CBR-2026-0001
// ══════════════════════════════════════════════════════

class NotaFormatter
{
    /**
     * Generate nomor nota berikutnya untuk transaksi.
     *
     * @param int      $tenantId
     * @param int      $outletId
     * @param string   $tanggal  YYYY-MM-DD (default hari ini)
     * @return string  Nomor nota final (mis. "HL-260606-001")
     */
    public static function next(int $tenantId, int $outletId, ?string $tanggal = null): string
    {
        $tanggal = $tanggal ?: date('Y-m-d');
        $cfg     = self::loadTenantConfig($tenantId, $outletId);
        $prefix  = $cfg['prefix'];
        $format  = $cfg['format'];

        // Resolve semua token kecuali {COUNTER} dulu
        $rendered = self::renderTokens($format, $prefix, $tanggal, $cfg['outlet_kode']);

        // Hitung counter berikutnya berdasarkan POLA TANPA {COUNTER}
        // (LIKE pattern dgn % di tempat counter)
        $likePattern = self::renderTokens($format, $prefix, $tanggal, $cfg['outlet_kode'], true);
        $db = Database::get();
        $cnt = $db->prepare(
            "SELECT COUNT(*) FROM hl_transaksi
              WHERE tenant_id=? AND outlet_id=? AND no_order LIKE ?"
        );
        $cnt->execute([$tenantId, $outletId, $likePattern]);
        $next = (int)$cnt->fetchColumn() + 1;

        // Replace counter token di rendered (placeholder ###COUNTER:N###)
        return preg_replace_callback(
            '/###COUNTER:(\d+)###/',
            fn($m) => str_pad((string)$next, (int)$m[1], '0', STR_PAD_LEFT),
            $rendered
        );
    }

    /**
     * Render semua token kecuali COUNTER. Kalau $forLike=true, COUNTER
     * jadi '%' (utk LIKE pattern lookup). Else jadi placeholder
     * "###COUNTER:N###" yang di-replace setelah dapat next number.
     */
    private static function renderTokens(string $format, string $prefix, string $tanggal, string $outletKode, bool $forLike = false): string
    {
        $ts = strtotime($tanggal) ?: time();
        $tokens = [
            '{PREFIX}'   => $prefix,
            '{YYYY}'     => date('Y', $ts),
            '{YY}'       => date('y', $ts),
            '{MM}'       => date('m', $ts),
            '{DD}'       => date('d', $ts),
            '{YYMMDD}'   => date('ymd', $ts),
            '{YYYYMMDD}' => date('Ymd', $ts),
            '{OUTLET}'   => $outletKode,
        ];
        $out = strtr($format, $tokens);

        // {COUNTER} atau {COUNTER:N}
        $out = preg_replace_callback(
            '/\{COUNTER(?::(\d+))?\}/',
            function ($m) use ($forLike) {
                if ($forLike) return '%';
                $n = (int)($m[1] ?? 0);
                return '###COUNTER:' . ($n > 0 ? $n : 1) . '###';
            },
            $out
        );
        return $out;
    }

    /**
     * Load config nota dari saas_tenants. Fallback ke default kalau
     * kolom belum ada (migration belum dijalankan).
     */
    private static function loadTenantConfig(int $tenantId, int $outletId): array
    {
        $defaults = ['prefix' => 'HL-', 'format' => '{PREFIX}{YYYYMMDD}-{COUNTER:3}'];
        try {
            $st = Database::get()->prepare(
                "SELECT nota_prefix, nota_format FROM saas_tenants WHERE id=? LIMIT 1"
            );
            $st->execute([$tenantId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $defaults['prefix'] = $row['nota_prefix'] ?: 'HL-';
                $defaults['format'] = $row['nota_format'] ?: $defaults['format'];
            }
        } catch (Throwable) { /* kolom belum ada → pakai default */ }

        // Outlet kode (3 char dari nama, fallback ke ID)
        try {
            $st = Database::get()->prepare("SELECT nama FROM hl_outlets WHERE id=? LIMIT 1");
            $st->execute([$outletId]);
            $nm = (string)$st->fetchColumn();
            $defaults['outlet_kode'] = $nm !== ''
                ? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $nm), 0, 3))
                : 'O' . str_pad((string)$outletId, 2, '0', STR_PAD_LEFT);
        } catch (Throwable) {
            $defaults['outlet_kode'] = 'O' . str_pad((string)$outletId, 2, '0', STR_PAD_LEFT);
        }
        return $defaults;
    }

    /**
     * Preview format dengan counter hipotetis (utk UI settings).
     * Hasil: "HL-260606-001 (contoh)"
     */
    public static function previewFormat(string $prefix, string $format, string $outletKode = 'OUT'): string
    {
        $rendered = self::renderTokens($format, $prefix, date('Y-m-d'), $outletKode);
        return preg_replace_callback(
            '/###COUNTER:(\d+)###/',
            fn($m) => str_pad('1', (int)$m[1], '0', STR_PAD_LEFT),
            $rendered
        );
    }
}

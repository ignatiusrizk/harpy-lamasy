<?php
// ══════════════════════════════════════════════════════
// core/NotaFormatter.php
//
// Generate nomor nota custom per tenant. Inspired by Smartlink
// "Nomor Nota Premium" feature.
//
// Token yang didukung di template (outlets.nota_format):
//   {PREFIX}    → outlets.nota_prefix (mis. "HL-", "HARPY-", "JL-")
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
     * Load config nota dari `outlets` (per-outlet). Fallback ke default
     * kalau kolom belum ada (migration belum dijalankan).
     */
    private static function loadTenantConfig(int $tenantId, int $outletId): array
    {
        $defaults = [
            'prefix' => 'HL-',
            'format' => '{PREFIX}{YYYYMMDD}-{COUNTER:3}',
            'outlet_kode' => 'O' . str_pad((string)$outletId, 2, '0', STR_PAD_LEFT),
        ];
        // 1 query, ambil prefix + format + nama outlet sekaligus
        try {
            $st = Database::get()->prepare(
                "SELECT nota_prefix, nota_format, nama_outlet FROM outlets WHERE id=? AND tenant_id=? LIMIT 1"
            );
            $st->execute([$outletId, $tenantId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if (!empty($row['nota_prefix'])) $defaults['prefix'] = $row['nota_prefix'];
                if (!empty($row['nota_format'])) $defaults['format'] = $row['nota_format'];
                $nm = (string)($row['nama_outlet'] ?? '');
                if ($nm !== '') {
                    $defaults['outlet_kode'] = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $nm), 0, 3));
                }
            }
        } catch (Throwable) {
            // kolom belum ada → coba query tanpa nota_prefix/nota_format
            try {
                $st = Database::get()->prepare("SELECT nama_outlet FROM outlets WHERE id=? AND tenant_id=? LIMIT 1");
                $st->execute([$outletId, $tenantId]);
                $nm = (string)$st->fetchColumn();
                if ($nm !== '') {
                    $defaults['outlet_kode'] = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $nm), 0, 3));
                }
            } catch (Throwable) {}
        }
        return $defaults;
    }

    /**
     * Generate default nota_prefix dari nama outlet.
     * Auto-strip kata umum (Laundry, Wash, Cleaners, dll), ambil 1-2
     * kata pertama, uppercase, max 12 char.
     *
     * Contoh:
     *   "Harpy Laundry Johar Baru" → "HARPY-"
     *   "Bersih Wangi Kemang"      → "BERSIHWANGI-"
     *   "Laundry 24 Jam"           → "JAM-"  (cuma dari kata non-stopword)
     *   "Nene Laundry"             → "NENE-"
     */
    public static function generatePrefixFromName(string $namaOutlet): string
    {
        $stopwords = ['LAUNDRY','WASH','CLEAN','CLEANERS','EXPRESS','KILAT','PRO','SUPER','JAM','HARI','24','DRY','CITY','SHOP'];
        $words = preg_split('/[\s\-_]+/', strtoupper(trim($namaOutlet)));
        $words = array_map(fn($w) => preg_replace('/[^A-Z0-9]/', '', $w), $words);
        $words = array_filter($words, fn($w) => $w !== '' && !in_array($w, $stopwords, true));
        $words = array_values($words);

        // Default fallback
        if (empty($words)) return 'NOTA-';

        // 1 kata pendek → pakai utuh; 1 kata panjang → potong 6 char;
        // 2+ kata → gabung 2 kata pertama (max 12 char total)
        if (count($words) === 1) {
            $p = substr($words[0], 0, 8);
        } else {
            $p = substr($words[0], 0, 6) . substr($words[1], 0, 6);
            $p = substr($p, 0, 12);
        }
        return $p . '-';
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

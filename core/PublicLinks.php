<?php
/**
 * PublicLinks — link publik permanen (QRIS & daftar harga) untuk dibagikan lewat WhatsApp.
 *
 * Satu tenant/outlet tujuan per slug (tabel hl_public_link); URL tanpa ID.
 * Gambar daftar harga digambar dengan PHP GD (+FreeType) dari layanan AKTIF dan
 * ditulis ke assets/public-links/. Regenerasi hanya saat data berubah (hook di
 * layanan.php, tombol di Pengaturan Pembayaran, atau cek hash saat file diminta).
 */
final class PublicLinks
{
    public const SLUG = 'harpy';
    /** Naikkan angka ini kalau tampilan gambar diubah → paksa regenerasi. */
    private const RENDER_VERSION = 2;
    private const W = 1080;

    private const BULAN = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

    // ── Lokasi & target ─────────────────────────────────────────
    public static function dir(): string
    {
        return dirname(__DIR__) . '/assets/public-links';
    }

    public static function file(array $t, string $kind): string
    {
        $kind = $kind === 'satuan' ? 'satuan' : 'kiloan';
        return self::dir() . '/harga-' . $kind . '-' . (int)$t['tenant_id'] . '-' . (int)$t['outlet_id'] . '.png';
    }

    public static function target(PDO $db, string $slug = self::SLUG): ?array
    {
        $st = $db->prepare("SELECT * FROM hl_public_link WHERE slug=? LIMIT 1");
        $st->execute([$slug]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Base URL absolut HTTPS untuk og:image — hanya host harpy.id yang dipercaya. */
    public static function absBase(): string
    {
        $h = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        if (!preg_match('/^([a-z0-9-]+\.)*harpy\.id$/', $h)) $h = 'lamasy.harpy.id';
        return 'https://' . $h;
    }

    // ── Format ─────────────────────────────────────────────────
    public static function rupiah(float $n): string
    {
        return 'Rp' . number_format($n, 0, ',', '.');
    }

    public static function estimasi(int $jam): string
    {
        if ($jam <= 0) return '';
        if ($jam >= 24 && $jam % 24 === 0) return ($jam / 24) . ' hari';
        return $jam . ' jam';
    }

    public static function tanggalId(?int $ts = null): string
    {
        $ts = $ts ?? time();
        return (int)date('j', $ts) . ' ' . self::BULAN[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
    }

    public static function telepon(?string $raw): string
    {
        $d = preg_replace('/\D+/', '', (string)$raw);
        if (preg_match('/^(\d{4})(\d{4})(\d{2,})$/', $d, $m)) return "$m[1]-$m[2]-$m[3]";
        return $d;
    }

    // ── Pemilihan baris daftar harga (murni, bisa diuji) ────────
    /**
     * @param array[] $rows baris hl_layanan (nama,kategori,satuan,harga,qty_minimum,estimasi_jam,urutan,is_active)
     * @return array{kiloan: array[], satuan: array[]}
     */
    public static function pickRows(array $rows): array
    {
        $online = [];   // nama dasar yang punya varian "(Online)"
        foreach ($rows as $r) {
            if (preg_match('/^(.*?)\s*\(Online\)\s*$/iu', (string)$r['nama'], $m)) {
                $online[mb_strtolower(trim($m[1]))] = true;
            }
        }
        $kiloan = []; $satuan = [];
        foreach ($rows as $r) {
            if ((int)$r['is_active'] !== 1) continue;
            $nama = trim((string)$r['nama']);
            $kat  = mb_strtolower((string)$r['kategori']);
            if (str_contains($kat, 'b2b') || str_contains($kat, 'korporat') || str_contains($kat, 'self')) continue;
            if (stripos($nama, 'b2b') !== false || stripos($nama, 'packing') !== false) continue;
            if (preg_match('/\(Offline\)\s*$/iu', $nama)) continue;

            if (preg_match('/^(.*?)\s*\(Online\)\s*$/iu', $nama, $m)) {
                $nama = trim($m[1]);
            } elseif (isset($online[mb_strtolower($nama)])) {
                continue; // varian tanpa kanal kalah oleh varian Online
            }
            $nama = preg_replace('/^Satuan\s*-\s*/iu', '', $nama);
            $satuanU = (string)$r['satuan'];
            $item = [
                'nama'     => $nama,
                'harga'    => (float)$r['harga'],
                'satuan'   => $satuanU,
                'qty_min'  => (float)$r['qty_minimum'],
                'estimasi' => (int)$r['estimasi_jam'],
                'urutan'   => (int)$r['urutan'],
                'rank'     => ['reguler'=>1, 'express'=>2][$kat] ?? 3,
            ];
            if (mb_strtolower($satuanU) === 'kg') $kiloan[] = $item; else $satuan[] = $item;
        }
        usort($kiloan, fn($a, $b) => [$a['rank'], $a['harga'], $a['nama']] <=> [$b['rank'], $b['harga'], $b['nama']]);
        usort($satuan, fn($a, $b) => [$a['urutan'], $a['nama']] <=> [$b['urutan'], $b['nama']]);
        return ['kiloan' => $kiloan, 'satuan' => $satuan];
    }

    public static function fetchRows(PDO $db, array $t): array
    {
        $st = $db->prepare("SELECT nama,kategori,satuan,harga,qty_minimum,estimasi_jam,urutan,is_active
                              FROM hl_layanan WHERE tenant_id=? AND outlet_id=? ORDER BY id");
        $st->execute([(int)$t['tenant_id'], (int)$t['outlet_id']]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function outletInfo(PDO $db, array $t): array
    {
        $st = $db->prepare("SELECT nama_outlet, telepon FROM outlets WHERE id=? AND tenant_id=?");
        $st->execute([(int)$t['outlet_id'], (int)$t['tenant_id']]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: ['nama_outlet' => '', 'telepon' => ''];
    }

    private static function contentHash(array $rows, array $outlet): string
    {
        return md5(json_encode([$rows, $outlet['nama_outlet'], $outlet['telepon'], self::RENDER_VERSION]));
    }

    // ── Regenerasi ─────────────────────────────────────────────
    /** Tulis ulang kedua PNG. @return array{kiloan:int, satuan:int, bytes:array, at:string} */
    public static function regenerate(PDO $db, array $t): array
    {
        $rows   = self::fetchRows($db, $t);
        $outlet = self::outletInfo($db, $t);
        $pick   = self::pickRows($rows);
        $dir    = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('Folder assets/public-links tidak bisa dibuat');
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Options -Indexes\n<FilesMatch \"\\.(php|phtml|php5|php7|phar)$\">\n  Require all denied\n</FilesMatch>\n");
        }
        $now   = date('Y-m-d H:i:s');
        $bytes = [];
        foreach (['kiloan' => 'Kiloan', 'satuan' => 'Satuan'] as $kind => $label) {
            $png = self::render($label, $pick[$kind], $outlet, self::tanggalId());
            $dest = self::file($t, $kind);
            $tmp  = $dest . '.tmp' . getmypid();
            if (file_put_contents($tmp, $png) === false) throw new RuntimeException('Gagal menulis gambar harga');
            if (!@rename($tmp, $dest)) { @unlink($tmp); throw new RuntimeException('Gagal mengganti gambar harga'); }
            $bytes[$kind] = strlen($png);
        }
        $db->prepare("UPDATE hl_public_link SET harga_hash=?, harga_generated_at=? WHERE slug=?")
           ->execute([self::contentHash($rows, $outlet), $now, $t['slug']]);
        return ['kiloan' => count($pick['kiloan']), 'satuan' => count($pick['satuan']), 'bytes' => $bytes, 'at' => $now];
    }

    /** Dipanggil endpoint publik: regenerasi hanya kalau data berubah / file belum ada. Tidak pernah melempar. */
    public static function refreshIfStale(PDO $db, array $t): void
    {
        try {
            $missing = !is_file(self::file($t, 'kiloan')) || !is_file(self::file($t, 'satuan'));
            $fresh   = self::contentHash(self::fetchRows($db, $t), self::outletInfo($db, $t));
            if (!$missing && $fresh === ($t['harga_hash'] ?? null)) return;
            $dir = self::dir();
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $lock = @fopen($dir . '/.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;   // proses lain sedang membuat
            try { self::regenerate($db, $t); } finally { flock($lock, LOCK_UN); fclose($lock); }
        } catch (Throwable $e) {
            error_log('[PublicLinks] ' . $e->getMessage());
        }
    }

    /** Hook dari layanan.php: regenerasi bila tenant/outlet ini adalah tujuan link publik. */
    public static function refreshIfTarget(PDO $db, int $tid, int $oid): void
    {
        try {
            $t = self::target($db);
            if ($t && (int)$t['tenant_id'] === $tid && (int)$t['outlet_id'] === $oid) self::regenerate($db, $t);
        } catch (Throwable $e) {
            error_log('[PublicLinks hook] ' . $e->getMessage());
        }
    }

    // ── Gambar ─────────────────────────────────────────────────
    private static function textW(float $size, string $font, string $text): int
    {
        $b = imagettfbbox($size, 0, $font, $text);
        return (int)abs($b[2] - $b[0]);
    }

    private static function fit(string $text, string $font, float $size, float $min, int $maxW): array
    {
        while ($size > $min && self::textW($size, $font, $text) > $maxW) $size -= 1;
        if (self::textW($size, $font, $text) > $maxW) {
            while (mb_strlen($text) > 4 && self::textW($size, $font, $text . '…') > $maxW) $text = mb_substr($text, 0, -1);
            $text = rtrim($text) . '…';
        }
        return [$text, $size];
    }

    /** Nama 1 baris (ukuran 36→30) bila muat; kalau tidak, pecah 2 baris di '/' atau spasi terdekat tengah. @return array<int,array{0:string,1:float}> */
    private static function wrapName(string $text, string $font, int $maxW): array
    {
        foreach ([36, 33, 30] as $s) if (self::textW($s, $font, $text) <= $maxW) return [[$text, $s]];
        $best = null; $mid = mb_strlen($text) / 2;
        for ($i = 1; $i < mb_strlen($text) - 1; $i++) {
            $ch = mb_substr($text, $i, 1);
            if ($ch !== ' ' && $ch !== '/') continue;
            $a = rtrim(mb_substr($text, 0, $i + ($ch === '/' ? 1 : 0)));
            $b = ltrim(mb_substr($text, $i + 1));
            if ($best === null || abs($i - $mid) < abs($best[2] - $mid)) $best = [$a, $b, $i];
        }
        if ($best && self::textW(29, $font, $best[0]) <= $maxW && self::textW(29, $font, $best[1]) <= $maxW) return [[$best[0], 29], [$best[1], 29]];
        [$tr, $sz] = self::fit($text, $font, 30, 26, $maxW);
        return [[$tr, $sz]];
    }

    private static function render(string $section, array $items, array $outlet, string $tanggal): string
    {
        $reg  = dirname(__DIR__) . '/assets/fonts/NotoSans-Regular.ttf';
        $bold = dirname(__DIR__) . '/assets/fonts/NotoSans-Bold.ttf';
        if (!function_exists('imagettftext') || !function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('PHP GD dengan FreeType tidak tersedia di server');
        }
        if (!is_file($reg) || !is_file($bold)) throw new RuntimeException('Font assets/fonts/NotoSans-*.ttf tidak ditemukan');

        $W = self::W; $m = 64;
        $rows = [];
        foreach ($items as $it) {
            $unit   = '/' . $it['satuan'];
            $detail = [];
            if ($it['estimasi'] > 0) $detail[] = ($section === 'Satuan' ? '' : 'Estimasi ') . self::estimasi($it['estimasi']);
            $isKg = mb_strtolower($it['satuan']) === 'kg';
            if ($isKg && $it['qty_min'] > 0) $detail[] = 'min. ' . rtrim(rtrim(number_format($it['qty_min'], 1, ',', ''), '0'), ',') . ' ' . $it['satuan'];
            $rows[] = ['nama' => $it['nama'], 'price' => self::rupiah($it['harga']), 'unit' => $unit,
                       'detail' => implode(' · ', $detail), 'h' => $detail ? 108 : 80];
        }
        $twoCol = ($section === 'Satuan');
        $headH = 300; $secH = 110; $footH = 240;
        $cellH = 132; $gap = 40; $colW = (int)(($W - 2 * $m - $gap) / 2);
        $bodyH = $twoCol ? (int)ceil(count($rows) / 2) * $cellH : array_sum(array_column($rows, 'h'));
        $H = $headH + $secH + $bodyH + $footH;
        if (!$rows) $H += 120;

        $im = imagecreatetruecolor($W, $H);
        imageantialias($im, true);
        $c = fn(string $hex) => imagecolorallocate($im, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
        $bg = $c('1F3F66'); $cream = $c('FBE9D0'); $muted = $c('A9BBD0'); $teal = $c('5DB3B8'); $line = $c('34597F');
        imagefilledrectangle($im, 0, 0, $W, $H, $bg);

        // Header: lingkaran H + nama + judul
        imagefilledellipse($im, $m + 52, 104, 104, 104, $c('4FD1C5'));
        imagettftext($im, 56, 0, $m + 52 - 22, 104 + 22, $bg, $bold, 'H');
        imagettftext($im, 54, 0, $m + 140, 100, $cream, $bold, 'HARPY LAUNDRY');
        $loc = trim(preg_replace('/^Harpy Laundry\s*/i', '', (string)$outlet['nama_outlet']));
        imagettftext($im, 32, 0, $m + 140, 148, $muted, $reg, $loc !== '' ? $loc : (string)$outlet['nama_outlet']);
        imagettftext($im, 46, 0, $m, 236, $cream, $bold, 'Daftar Harga');
        imagettftext($im, 28, 0, $m, 280, $muted, $reg, 'Diperbarui ' . $tanggal);

        // Judul bagian
        $y = $headH;
        imagettftext($im, 44, 0, $m, $y + 62, $teal, $bold, mb_strtoupper($section));
        imagefilledrectangle($im, $m, $y + 82, $W - $m, $y + 85, $teal);
        $y += $secH;

        if ($twoCol) {
            // Dua kolom (urut turun per kolom): nama / harga / estimasi — supaya gambar tidak terlalu panjang
            $perCol = (int)ceil(count($rows) / 2);
            foreach ($rows as $i => $r) {
                $col = intdiv($i, $perCol); $row = $i % $perCol;
                $x0 = $m + $col * ($colW + $gap); $cy = $y + $row * $cellH;
                $lines = self::wrapName($r['nama'], $reg, $colW);
                if (count($lines) === 1) {
                    imagettftext($im, $lines[0][1], 0, $x0, $cy + 40, $cream, $reg, $lines[0][0]);
                    $py = $cy + 94;
                } else {
                    imagettftext($im, 29, 0, $x0, $cy + 28, $cream, $reg, $lines[0][0]);
                    imagettftext($im, 29, 0, $x0, $cy + 62, $cream, $reg, $lines[1][0]);
                    $py = $cy + 116;
                }
                $pw = self::textW(38, $bold, $r['price']);
                $uw = self::textW(27, $reg, ' ' . $r['unit']);
                imagettftext($im, 38, 0, $x0, $py, $cream, $bold, $r['price']);
                imagettftext($im, 27, 0, $x0 + $pw, $py, $muted, $reg, ' ' . $r['unit']);
                if ($r['detail'] !== '') imagettftext($im, 26, 0, $x0 + $pw + $uw + 22, $py, $muted, $reg, $r['detail']);
                imageline($im, $x0, $cy + $cellH - 6, $x0 + $colW, $cy + $cellH - 6, $line);
            }
        } else {
            foreach ($rows as $r) {
                $priceW = self::textW(40, $bold, $r['price']);
                $unitW  = self::textW(28, $reg, ' ' . $r['unit']);
                $total  = $priceW + $unitW;
                [$name, $ns] = self::fit($r['nama'], $reg, 40, 30, $W - 2 * $m - $total - 36);
                $base = $y + ($r['detail'] !== '' ? 50 : 54);
                imagettftext($im, $ns, 0, $m, $base, $cream, $reg, $name);
                imagettftext($im, 40, 0, $W - $m - $total, $base, $cream, $bold, $r['price']);
                imagettftext($im, 28, 0, $W - $m - $unitW, $base, $muted, $reg, ' ' . $r['unit']);
                if ($r['detail'] !== '') imagettftext($im, 27, 0, $m, $base + 40, $muted, $reg, $r['detail']);
                imageline($im, $m, $y + $r['h'] - 2, $W - $m, $y + $r['h'] - 2, $line);
                $y += $r['h'];
            }
        }
        if (!$rows) imagettftext($im, 34, 0, $m, $y + 70, $muted, $reg, 'Belum ada layanan di bagian ini.');

        // Footer
        $fy = $H - 150;
        $tel = self::telepon($outlet['telepon'] ?? '');
        if ($tel !== '') {
            imagettftext($im, 30, 0, $m, $fy, $muted, $reg, 'Pesan lewat WhatsApp');
            imagettftext($im, 44, 0, $m, $fy + 64, $cream, $bold, $tel);
        }
        imagettftext($im, 24, 0, $m, $H - 40, $muted, $reg, 'Harga dalam Rupiah.');

        imagetruecolortopalette($im, false, 96);
        ob_start();
        imagepng($im, null, 9);
        $png = (string)ob_get_clean();
        return $png;
    }
}

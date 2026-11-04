<?php
/**
 * PublicLinks — link publik permanen (QRIS & daftar harga) untuk dibagikan lewat WhatsApp.
 *
 * Satu tenant/outlet tujuan per slug (tabel hl_public_link); URL tanpa ID.
 * Kedua gambar (QRIS dan daftar harga) adalah berkas yang DIUNGGAH admin dan disajikan apa adanya —
 * tidak ada resize, kompres, atau pembuatan gambar otomatis.
 */
final class PublicLinks
{
    public const SLUG = 'harpy';
    public const MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

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

    /** Path absolut gambar harga (hanya di dalam assets/pricelist), atau null bila tidak ada. */
    public static function hargaFile(array $t): ?string
    {
        $rel = (string)($t['harga_image'] ?? '');
        $base = realpath(dirname(__DIR__) . '/assets/pricelist');
        $abs = $rel !== '' ? realpath(dirname(__DIR__) . $rel) : false;
        if (!$base || !$abs || strpos($abs, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($abs)) return null;
        return $abs;
    }

    /** Sajikan berkas gambar apa adanya (byte-per-byte) dengan cache & ETag. Mengakhiri request. */
    public static function serveImage(string $abs, int $maxAge, string $downloadName): never
    {
        $mime = mime_content_type($abs);
        if (!isset(self::MIME[$mime])) { http_response_code(404); exit; }
        $mtime = filemtime($abs); $size = filesize($abs);
        $etag  = '"' . md5($abs . $mtime . $size) . '"';
        header('Cache-Control: public, max-age=' . $maxAge);
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $size);
        header('Content-Disposition: inline; filename="' . $downloadName . '.' . self::MIME[$mime] . '"');
        readfile($abs);
        exit;
    }
}

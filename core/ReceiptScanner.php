<?php
// core/ReceiptScanner.php — baca struk belanja (AI vision) → field kas pengeluaran.
require_once __DIR__ . '/AnthropicClient.php';

class ReceiptScanner
{
    public static function buildPrompt(): string
    {
        return
            "Kamu pembaca struk belanja Indonesia. Dari GAMBAR struk ini, keluarkan JSON SAJA sesuai skema.\n" .
            "{\n" .
            "  \"is_receipt\": true|false,            // false kalau gambar jelas BUKAN struk belanja\n" .
            "  \"jumlah\": number,                    // TOTAL akhir yang dibayar, angka rupiah tanpa titik/koma\n" .
            "  \"tanggal\": \"YYYY-MM-DD\"|null,         // tanggal di struk; null kalau tak terbaca\n" .
            "  \"keterangan\": string,                // nama toko + ringkasan singkat item\n" .
            "  \"kategori\": string                   // tebakan kategori pengeluaran: Bahan|Operasional|Listrik|Perlengkapan|Lainnya\n" .
            "}\n" .
            "Aturan: jumlah = total akhir (bukan subtotal/kembalian). Jangan mengarang; kalau ragu set is_receipt sesuai keyakinan. Output JSON valid saja.";
    }

    /** Pure: validasi & normalisasi hasil AI. */
    public static function validate(array $raw): array
    {
        if (($raw['is_receipt'] ?? true) === false) return ['ok' => false];

        // jumlah: buang semua non-digit (titik ribuan, "Rp", spasi)
        $jr = $raw['jumlah'] ?? 0;
        if (is_string($jr)) $jr = preg_replace('/[^0-9]/', '', $jr);
        $jumlah = (int)$jr;
        if ($jumlah <= 0) return ['ok' => false];

        // tanggal: YYYY-MM-DD, valid, tak lebih dari besok
        $tgl = $raw['tanggal'] ?? null;
        if (!is_string($tgl) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl) || strtotime($tgl) === false || strtotime($tgl) > strtotime('+1 day')) {
            $tgl = null;
        }

        $ket = trim(strip_tags((string)($raw['keterangan'] ?? '')));
        if ($ket === '') $ket = 'Belanja (scan struk)';
        $ket = substr($ket, 0, 500);

        $kat = substr(trim((string)($raw['kategori'] ?? '')), 0, 50);

        return ['ok' => true, 'jumlah' => $jumlah, 'tanggal' => $tgl, 'keterangan' => $ket, 'kategori' => $kat];
    }

    /** Vision scan: panggil AI (atau mock) lalu validasi. */
    public static function scan(string $base64, string $mediaType, ?callable $aiFn = null): array
    {
        $prompt = self::buildPrompt();
        $raw = $aiFn !== null ? $aiFn($prompt) : AnthropicClient::askJsonWithImage($prompt, $base64, $mediaType);
        if (!is_array($raw)) throw new RuntimeException('Vision response bukan JSON object');
        return self::validate($raw);
    }
}

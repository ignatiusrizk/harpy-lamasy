<?php
// core/VoiceOrderParser.php — parse transcript suara → order terstruktur (validasi ke katalog).
require_once __DIR__ . '/AnthropicClient.php';

class VoiceOrderParser
{
    private const STATUS = ['belum_bayar', 'dp', 'lunas'];
    private const METODE = ['cash', 'transfer', 'qris'];

    public static function buildPrompt(string $transcript, array $catalog): string
    {
        $lines = [];
        foreach ($catalog as $c) {
            $lines[] = "- id={$c['id']} | {$c['nama']} (satuan {$c['satuan']})";
        }
        $katalogStr = implode("\n", $lines);
        return
            "Kamu parser order laundry. Dari transcript Bahasa Indonesia berikut, keluarkan JSON SAJA (tanpa teks lain) sesuai skema.\n\n" .
            "TRANSCRIPT: \"" . $transcript . "\"\n\n" .
            "KATALOG LAYANAN (WAJIB pilih layanan_id dari sini, cocokkan fuzzy ke nama):\n" . $katalogStr . "\n\n" .
            "SKEMA JSON:\n" .
            "{\n" .
            "  \"nama\": string|null,\n" .
            "  \"items\": [ { \"layanan_id\": number (HARUS dari katalog), \"qty\": number } ],\n" .
            "  \"bayar\": { \"status\": \"belum_bayar\"|\"dp\"|\"lunas\"|null, \"metode\": \"tunai\"|\"transfer\"|\"qris\"|null },\n" .
            "  \"unmatched\": [ string ]  // layanan yang disebut tapi tak ada di katalog\n" .
            "}\n\n" .
            "Aturan: JANGAN mengarang layanan/harga. Kalau layanan disebut tapi tak ada di katalog, masukkan teksnya ke unmatched (jangan ke items). qty default 1 kalau tak jelas. Output JSON valid saja.";
    }

    /** Pure: validasi & normalisasi hasil mentah AI terhadap katalog. */
    public static function validate(array $raw, array $catalog): array
    {
        $byId = [];
        foreach ($catalog as $c) { $byId[(int)$c['id']] = $c; }

        $items = [];
        foreach (($raw['items'] ?? []) as $it) {
            $id = (int)($it['layanan_id'] ?? 0);
            if (!isset($byId[$id])) continue; // buang yang di luar katalog
            $qty = (int)($it['qty'] ?? 1);
            if ($qty < 1) $qty = 1;
            $items[] = ['layanan_id' => $id, 'nama_katalog' => (string)$byId[$id]['nama'], 'qty' => $qty];
        }

        $status = $raw['bayar']['status'] ?? null;
        if (!in_array($status, self::STATUS, true)) $status = null;
        $metode = $raw['bayar']['metode'] ?? null;
        if (is_string($metode)) $metode = strtolower(trim($metode));
        if ($metode === 'tunai') $metode = 'cash'; // normalize Indonesian synonym → POS canonical
        if (!in_array($metode, self::METODE, true)) $metode = null;

        $nama = $raw['nama'] ?? null;
        $nama = is_string($nama) ? trim($nama) : null;
        if ($nama === '') $nama = null;

        $unmatched = [];
        foreach (($raw['unmatched'] ?? []) as $u) {
            if (is_string($u) && trim($u) !== '') $unmatched[] = trim($u);
        }

        return [
            'nama'      => $nama,
            'items'     => $items,
            'bayar'     => ['status' => $status, 'metode' => $metode],
            'unmatched' => $unmatched,
        ];
    }

    /** Panggil AI (atau aiFn mock) lalu validasi. Throw kalau AI gagal. */
    public static function parse(string $transcript, array $catalog, ?callable $aiFn = null): array
    {
        $prompt = self::buildPrompt($transcript, $catalog);
        if ($aiFn !== null) {
            $raw = $aiFn($prompt);
        } else {
            $raw = AnthropicClient::askJson($prompt, [
                'temperature' => 0,
                'max_tokens'  => 800,
            ]);
        }
        if (!is_array($raw)) throw new RuntimeException('AI response bukan JSON object');
        return self::validate($raw, $catalog);
    }
}

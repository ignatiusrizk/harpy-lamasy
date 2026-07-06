<?php
// ══════════════════════════════════════════════════════════════════
// scripts/leads-places.php — Cari leads laundry via Google Places API (New)
//
// PAKAI (CLI only):
//   php scripts/leads-places.php --test                 # 1 panggilan uji (validasi key)
//   php scripts/leads-places.php                        # full grid Jabodetabek → CSV
//   php scripts/leads-places.php --bbox=-6.30,106.70,-6.10,106.90 --cell=0.03
//   php scripts/leads-places.php --query="laundry kiloan" --out=/path/leads.csv
//
// Key: file ~/.config/lamasy/places.key (atau --key-file=...). JANGAN commit key.
// Biaya: Text Search dgn field telepon = SKU Enterprise (±$0.035/panggilan,
//   ada kuota gratis bulanan). Full grid default ≈ 170–450 panggilan.
//   --max-calls (default 900) rem pengaman.
// Data hasil = pemakaian internal prospecting; jangan dipublikasi ulang.
// ══════════════════════════════════════════════════════════════════

if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only.'); }

$opt = getopt('', ['test', 'bbox::', 'cell::', 'query::', 'out::', 'key-file::', 'max-calls::']);

$keyFile = $opt['key-file'] ?? (getenv('HOME') . '/.config/lamasy/places.key');
if (!is_readable($keyFile)) {
    fwrite(STDERR, "❌ Key tidak ditemukan: $keyFile\n" .
        "   Simpan dulu: mkdir -p ~/.config/lamasy && echo 'API_KEY_KAMU' > ~/.config/lamasy/places.key && chmod 600 ~/.config/lamasy/places.key\n");
    exit(1);
}
$KEY = trim((string)file_get_contents($keyFile));
if ($KEY === '') { fwrite(STDERR, "❌ File key kosong: $keyFile\n"); exit(1); }

// ── Konfigurasi ──
$bbox = array_map('floatval', explode(',', $opt['bbox'] ?? '-6.60,106.50,-5.95,107.15')); // latMin,lonMin,latMax,lonMax
if (count($bbox) !== 4) { fwrite(STDERR, "❌ --bbox harus latMin,lonMin,latMax,lonMax\n"); exit(1); }
[$latMin, $lonMin, $latMax, $lonMax] = $bbox;
$cell     = max(0.01, (float)($opt['cell'] ?? 0.05));      // ±5.5 km per sel
$query    = $opt['query'] ?? 'laundry';
$out      = $opt['out'] ?? (getenv('HOME') . '/Desktop/lamasy-marketing/leads-laundry-places.csv');
$maxCalls = max(1, (int)($opt['max-calls'] ?? 900));

$FIELDS = 'places.id,places.displayName,places.nationalPhoneNumber,places.internationalPhoneNumber,'
        . 'places.formattedAddress,places.location,places.rating,places.userRatingCount,places.googleMapsUri,nextPageToken';

function placesSearch(string $key, string $fields, array $body): array {
    $ch = curl_init('https://places.googleapis.com/v1/places:searchText');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Goog-Api-Key: ' . $key,
            'X-Goog-FieldMask: ' . $fields,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw  = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $j = json_decode((string)$raw, true) ?: [];
    if ($http !== 200) {
        $msg = $j['error']['message'] ?? substr((string)$raw, 0, 200);
        throw new RuntimeException("HTTP $http — $msg");
    }
    return $j;
}

function waNorm(?string $ph): string {
    $d = preg_replace('/[^0-9]/', '', (string)$ph);
    if ($d === '') return '';
    if ($d[0] === '0') return '62' . substr($d, 1);
    if (str_starts_with($d, '62')) return $d;
    if ($d[0] === '8') return '62' . $d;
    return $d;
}

// ── Mode --test: 1 panggilan kecil ──
if (isset($opt['test'])) {
    echo "Uji 1 panggilan Text Search…\n";
    try {
        $j = placesSearch($KEY, $FIELDS, [
            'textQuery' => $query, 'includedType' => 'laundry', 'pageSize' => 5,
            'locationRestriction' => ['rectangle' => [
                'low'  => ['latitude' => -6.25, 'longitude' => 106.78],
                'high' => ['latitude' => -6.15, 'longitude' => 106.88]]],
        ]);
        $n = count($j['places'] ?? []);
        echo "✅ Key valid. $n hasil sampel:\n";
        foreach (($j['places'] ?? []) as $p) {
            echo '  - ' . ($p['displayName']['text'] ?? '?') . ' | ' . ($p['nationalPhoneNumber'] ?? '—') . "\n";
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "❌ {$e->getMessage()}\n   Cek: Places API (New) sudah Enable? Billing aktif? Key restriction benar?\n");
        exit(1);
    }
    exit(0);
}

// ── Full grid ──
$t0 = microtime(true);
$found = [];   // placeId => row
$calls = 0; $cells = 0; $skippedCap = false;

$latSteps = (int)ceil(($latMax - $latMin) / $cell);
$lonSteps = (int)ceil(($lonMax - $lonMin) / $cell);
echo "Grid {$latSteps}×{$lonSteps} sel (~" . ($latSteps * $lonSteps) . "), query \"$query\", cap $maxCalls panggilan.\n";

for ($i = 0; $i < $latSteps; $i++) {
    for ($k = 0; $k < $lonSteps; $k++) {
        $lo = ['latitude' => $latMin + $i * $cell,       'longitude' => $lonMin + $k * $cell];
        $hi = ['latitude' => min($latMax, $lo['latitude'] + $cell), 'longitude' => min($lonMax, $lo['longitude'] + $cell)];
        $cells++;
        $pageToken = null; $page = 0;
        do {
            if ($calls >= $maxCalls) { $skippedCap = true; break 3; }
            $body = [
                'textQuery' => $query, 'includedType' => 'laundry', 'pageSize' => 20,
                'locationRestriction' => ['rectangle' => ['low' => $lo, 'high' => $hi]],
            ];
            if ($pageToken) $body['pageToken'] = $pageToken;
            try { $j = placesSearch($KEY, $FIELDS, $body); $calls++; }
            catch (Throwable $e) {
                fwrite(STDERR, "⚠️ sel[$i,$k] p$page: {$e->getMessage()}\n");
                if (str_contains($e->getMessage(), 'PERMISSION_DENIED') || str_contains($e->getMessage(), 'API key')) exit(1);
                usleep(800000); break; // sel ini dilewati, lanjut sel berikut
            }
            foreach (($j['places'] ?? []) as $p) {
                $id = $p['id'] ?? md5(json_encode($p));
                if (isset($found[$id])) continue;
                $ph = $p['nationalPhoneNumber'] ?? $p['internationalPhoneNumber'] ?? '';
                $found[$id] = [
                    'nama'    => $p['displayName']['text'] ?? '',
                    'wa_62'   => waNorm($ph),
                    'telepon' => $ph,
                    'alamat'  => $p['formattedAddress'] ?? '',
                    'rating'  => $p['rating'] ?? '',
                    'ulasan'  => $p['userRatingCount'] ?? '',
                    'link'    => $p['googleMapsUri'] ?? '',
                ];
            }
            $pageToken = $j['nextPageToken'] ?? null;
            $page++;
            usleep(200000);
        } while ($pageToken && $page < 3);
        if ($cells % 20 === 0) echo "  … {$cells} sel, {$calls} panggilan, " . count($found) . " tempat unik\n";
    }
}

// ── Tulis CSV (ber-telepon dulu) ──
$rows = array_values($found);
usort($rows, fn($a, $b) => ($b['wa_62'] !== '') <=> ($a['wa_62'] !== ''));
@mkdir(dirname($out), 0755, true);
$fp = fopen($out, 'w');
fputs($fp, "\xEF\xBB\xBF");
fputcsv($fp, ['nama', 'wa_62', 'telepon', 'alamat', 'rating', 'jml_ulasan', 'link_maps'], ',', '"', '\\');
foreach ($rows as $r) fputcsv($fp, array_values($r), ',', '"', '\\');
fclose($fp);

$wph = count(array_filter($rows, fn($r) => $r['wa_62'] !== ''));
$dur = round(microtime(true) - $t0);
echo "\n✅ Selesai {$dur}s — {$calls} panggilan API, {$cells} sel.\n";
echo "   " . count($rows) . " laundry unik | {$wph} ber-telepon (siap wa.me)\n";
echo "   CSV: $out\n";
if ($skippedCap) echo "⚠️ Berhenti di cap --max-calls=$maxCalls — perkecil bbox atau naikkan cap utk sisanya.\n";

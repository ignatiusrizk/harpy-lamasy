# Voice Order POS Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Kasir membuat order POS lewat suara (Bahasa Indonesia): tekan 🎤 → STT → AI parse jadi field order (nama, layanan+qty, bayar) → modal konfirmasi → isi form POS → kasir Simpan manual.

**Architecture:** STT native (`@capacitor-community/speech-recognition`, app-only) menghasilkan transcript → `POST /api/voice_order_parse.php` → `core/VoiceOrderParser.php` kirim transcript + katalog layanan tenant ke `AnthropicClient::askJson` → JSON tervalidasi terhadap katalog (anti-halusinasi) → potong coin hanya jika ≥1 item valid → app tampilkan modal konfirmasi → Terapkan isi form via `addLayananItem()`+`recalc()`.

**Tech Stack:** PHP 8 / MariaDB. `core/AnthropicClient.php` (askJson), `core/CoinLedger.php` (deduct), `core/AIRateLimiter.php` (canCall/errorResponse), katalog `hl_layanan`. App: Capacitor 7 + `@capacitor-community/speech-recognition`. Test: skrip CLI + `tests/_assert.php` (sudah ada), PDO sqlite tak perlu (pakai array fixture).

## Global Constraints

- Multi-tenant: katalog & query scoping `tenant_id` (+ `outlet_id` bila relevan).
- AI hanya boleh pilih `layanan_id` dari katalog yang dikirim; **server validasi ulang** (buang id di luar katalog tenant). Harga/total tak pernah dari AI.
- **Tak pernah auto-submit** — form diisi, kasir tekan Simpan sendiri.
- **Potong coin hanya jika sukses** (≥1 item valid). Gagal STT/AI/JSON/0-item → coin TIDAK dipotong.
- Rate limit via `AIRateLimiter::canCall('ai_voice_order')` sebelum panggil AI.
- Endpoint POST WAJIB `verifyCsrf()` (token auto via interceptor global).
- Tombol mic **app-only** (cek `window.Capacitor.Plugins.SpeechRecognition`); browser sembunyikan. Offline → disable + toast.
- Semua kegagalan best-effort + `ErrorLogger`; tak meng-crash POS.
- PHP CLI: `/opt/homebrew/bin/php`. Deploy: `git push origin main` (SSH key sudah set). mysql: `/opt/homebrew/opt/mysql-client/bin/mysql`.

## Signatures (verbatim, dari codebase)

- `AnthropicClient::askJson(string $prompt, array $opts = []): array` — opts: `system`, `model`, `max_tokens`, `temperature`. Return: parsed JSON array. Throw RuntimeException kalau API/parse gagal.
- `CoinLedger::deduct(string $feature, ?string $refId = null, ?int $overrideCost = null): bool`. COSTS adalah `const COSTS` array di `core/CoinLedger.php` (mis. `'ai_chat_data' => 50`).
- `AIRateLimiter::canCall(string $feature, ?int $tenantId = null): bool` + `AIRateLimiter::errorResponse(string $feature): array`.
- POS JS (di `pos.php`): `addLayananItem(id, nama, satuan, harga)` (push ke array `items` + render), `recalc()` (hitung total), global `layananAll` (katalog terload), field `#f_nama`, `showToast(msg,type)`.

## File Structure

- `core/CoinLedger.php` (MODIFY): tambah `'ai_voice_order'` ke `const COSTS`.
- `migrations/2026-06-26-voice-order-coin.sql` (NEW): seed `ai_voice_order` ke `saas_coin_pricing` (kalau tabel itu sumber harga) + apply.
- `core/VoiceOrderParser.php` (NEW): `buildPrompt`, `validate` (pure), `parse`.
- `api/voice_order_parse.php` (NEW): endpoint thin.
- `tests/voice/test_validate.php` (NEW): unit validate.
- `pos.php` (MODIFY): tombol 🎤 + rekam STT + modal konfirmasi + apply-to-form.
- `~/Documents/lamasy-app/` (MODIFY): plugin STT + build APK.

---

### Task 1: Coin cost `ai_voice_order`

**Files:**
- Modify: `core/CoinLedger.php` (`const COSTS`)
- Create: `migrations/2026-06-26-voice-order-coin.sql`

**Interfaces:**
- Produces: feature key `'ai_voice_order'` dikenali `CoinLedger::deduct`/`getHarga`.

- [ ] **Step 1: Tambah ke COSTS**

Di `core/CoinLedger.php`, dalam `const COSTS = [ ... ]`, tambahkan baris (setelah `'ai_chat_data' => 50,`):
```php
        'ai_voice_order'     =>  50,
```

- [ ] **Step 2: Lint**

Run: `/opt/homebrew/bin/php -l core/CoinLedger.php`
Expected: `No syntax errors`.

- [ ] **Step 3: Cek apakah saas_coin_pricing sumber harga (kalau ya, seed)**

Run: `/opt/homebrew/opt/mysql-client/bin/mysql -e "SHOW COLUMNS FROM saas_coin_pricing;"`
Lihat kolomnya (mis. `feature`/`kode`, `harga`/`cost`, `daily_limit`). Kalau `getHarga()` baca dari tabel ini, buat migration seed. Tulis `migrations/2026-06-26-voice-order-coin.sql` (sesuaikan nama kolom dengan hasil SHOW COLUMNS — contoh asumsi kolom `feature,harga,daily_limit,is_active`):
```sql
INSERT INTO saas_coin_pricing (feature, harga, daily_limit, is_active)
VALUES ('ai_voice_order', 50, 100, 1)
ON DUPLICATE KEY UPDATE harga = VALUES(harga);
```
> Implementer: konfirmasi nama kolom nyata dari SHOW COLUMNS sebelum menulis INSERT. Kalau `CoinLedger::getHarga` ternyata hanya baca dari `const COSTS` (bukan tabel), migration tidak perlu — catat itu di report dan lewati Step 4.

- [ ] **Step 4: Terapkan migration (kalau dibuat)**

Run: `/opt/homebrew/opt/mysql-client/bin/mysql < migrations/2026-06-26-voice-order-coin.sql`
Verify: `/opt/homebrew/opt/mysql-client/bin/mysql -e "SELECT * FROM saas_coin_pricing WHERE feature='ai_voice_order';"`

- [ ] **Step 5: Commit**

```bash
git add core/CoinLedger.php migrations/2026-06-26-voice-order-coin.sql
git commit -m "feat(voice): coin cost ai_voice_order"
```

---

### Task 2: `core/VoiceOrderParser.php` (buildPrompt + validate + parse)

**Files:**
- Create: `core/VoiceOrderParser.php`
- Create: `tests/voice/test_validate.php`

**Interfaces:**
- Consumes: `AnthropicClient::askJson` (hanya di `parse`, di-mock saat test).
- Produces:
  - `VoiceOrderParser::buildPrompt(string $transcript, array $catalog): string`
  - `VoiceOrderParser::validate(array $raw, array $catalog): array` — pure. `$catalog` = list `['id'=>int,'nama'=>string,'satuan'=>string]`. Return `['nama'=>string,'items'=>[['layanan_id'=>int,'nama_katalog'=>string,'qty'=>int]],'bayar'=>['status'=>?string,'metode'=>?string],'unmatched'=>string[]]`.
  - `VoiceOrderParser::parse(string $transcript, array $catalog, ?callable $aiFn = null): array` — `$aiFn` opsional `fn(string $prompt): array` untuk test; default pakai `AnthropicClient::askJson`. Return sama seperti `validate`.

- [ ] **Step 1: Tulis test validate (failing)**

`tests/voice/test_validate.php`:
```php
<?php
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../core/VoiceOrderParser.php';

$catalog = [
    ['id' => 12, 'nama' => 'Cuci Setrika Reguler', 'satuan' => 'kg'],
    ['id' => 5,  'nama' => 'Cuci Kering',          'satuan' => 'kg'],
];

// 1. item valid (di katalog) dipertahankan + qty di-cast int
$r = VoiceOrderParser::validate([
    'nama' => 'Heri',
    'items' => [['layanan_id' => 12, 'qty' => '3'], ['layanan_id' => 5, 'qty' => 2]],
    'bayar' => ['status' => 'lunas', 'metode' => 'tunai'],
    'unmatched' => ['pewangi premium'],
], $catalog);
eqv($r['nama'], 'Heri', 'nama lolos');
eqv(count($r['items']), 2, '2 item valid');
eqv($r['items'][0]['layanan_id'], 12, 'id 12 dipertahankan');
eqv($r['items'][0]['nama_katalog'], 'Cuci Setrika Reguler', 'nama_katalog dari katalog');
eqv($r['items'][0]['qty'], 3, 'qty string "3" → int 3');
eqv($r['bayar']['status'], 'lunas', 'status lolos');
eqv($r['bayar']['metode'], 'tunai', 'metode lolos');
eqv($r['unmatched'], ['pewangi premium'], 'unmatched passthrough');

// 2. item dengan id di luar katalog dibuang
$r2 = VoiceOrderParser::validate([
    'items' => [['layanan_id' => 999, 'qty' => 1], ['layanan_id' => 12, 'qty' => 1]],
], $catalog);
eqv(count($r2['items']), 1, 'id 999 (luar katalog) dibuang');
eqv($r2['items'][0]['layanan_id'], 12, 'sisa hanya id valid');

// 3. qty < 1 atau hilang → default 1
$r3 = VoiceOrderParser::validate(['items' => [['layanan_id' => 12]]], $catalog);
eqv($r3['items'][0]['qty'], 1, 'qty hilang → 1');

// 4. bayar.status/metode di luar enum → null
$r4 = VoiceOrderParser::validate([
    'items' => [['layanan_id' => 12, 'qty' => 1]],
    'bayar' => ['status' => 'ngutang', 'metode' => 'gopay'],
], $catalog);
eqv($r4['bayar']['status'], null, 'status invalid → null');
eqv($r4['bayar']['metode'], null, 'metode invalid → null');

// 5. items kosong setelah filter → array kosong (endpoint anggap no_match)
$r5 = VoiceOrderParser::validate(['items' => [['layanan_id' => 999, 'qty' => 1]]], $catalog);
eqv($r5['items'], [], 'semua item invalid → kosong');

// parse() dengan aiFn mock (tanpa jaringan)
$mock = function (string $prompt) {
    return ['nama' => 'Budi', 'items' => [['layanan_id' => 5, 'qty' => 4]], 'bayar' => ['status' => 'dp', 'metode' => 'transfer'], 'unmatched' => []];
};
$rp = VoiceOrderParser::parse('budi cuci kering 4 kilo dp transfer', $catalog, $mock);
eqv($rp['items'][0]['layanan_id'], 5, 'parse pakai aiFn mock → item valid');
eqv($rp['bayar']['status'], 'dp', 'parse bayar dp');

echo "ALL OK\n";
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `/opt/homebrew/bin/php tests/voice/test_validate.php`
Expected: FATAL "Class VoiceOrderParser not found".

- [ ] **Step 3: Implementasi `core/VoiceOrderParser.php`**

```php
<?php
// core/VoiceOrderParser.php — parse transcript suara → order terstruktur (validasi ke katalog).
require_once __DIR__ . '/AnthropicClient.php';

class VoiceOrderParser
{
    private const STATUS = ['belum_bayar', 'dp', 'lunas'];
    private const METODE = ['tunai', 'transfer', 'qris'];

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
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `/opt/homebrew/bin/php tests/voice/test_validate.php`
Expected: `PASS:` semua + `ALL OK`.

- [ ] **Step 5: Lint + commit**

```bash
/opt/homebrew/bin/php -l core/VoiceOrderParser.php
git add core/VoiceOrderParser.php tests/voice/test_validate.php
git commit -m "feat(voice): VoiceOrderParser (buildPrompt + validate ke katalog + parse)"
```

---

### Task 3: Endpoint `api/voice_order_parse.php`

**Files:**
- Create: `api/voice_order_parse.php`

**Interfaces:**
- Consumes: `VoiceOrderParser::parse`, `AIRateLimiter::canCall`, `CoinLedger::deduct`, katalog `hl_layanan`.
- Produces: `POST /api/voice_order_parse.php { transcript }` → JSON `{ok, heard, parsed, unmatched}` atau `{ok:false, reason}`.

- [ ] **Step 1: Implementasi endpoint**

`api/voice_order_parse.php`:
```php
<?php
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/VoiceOrderParser.php';
require_once ROOT . '/core/AIRateLimiter.php';
require_once ROOT . '/core/CoinLedger.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'reason'=>'method']); exit; }
verifyCsrf();

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$transcript = trim((string)($data['transcript'] ?? ''));
if (mb_strlen($transcript) < 2) { echo json_encode(['ok'=>false,'reason'=>'no_speech']); exit; }
if (mb_strlen($transcript) > 500) $transcript = mb_substr($transcript, 0, 500);

$tid = TenantResolver::id();
$oid = TenantResolver::outletId();

// Rate limit
if (!AIRateLimiter::canCall('ai_voice_order', $tid)) {
    echo json_encode(['ok'=>false,'reason'=>'rate_limited'] + AIRateLimiter::errorResponse('ai_voice_order'));
    exit;
}

try {
    // Katalog layanan aktif tenant+outlet
    $catalog = TenantQuery::raw(
        "SELECT id, nama, satuan FROM hl_layanan WHERE tenant_id=? AND outlet_id=? AND is_active=1 ORDER BY nama",
        [$tid, $oid]
    );
    if (!$catalog) { echo json_encode(['ok'=>false,'reason'=>'no_catalog']); exit; }

    $parsed = VoiceOrderParser::parse($transcript, $catalog);

    if (empty($parsed['items'])) {
        echo json_encode(['ok'=>false,'reason'=>'no_match','heard'=>$transcript,'unmatched'=>$parsed['unmatched']]);
        exit;
    }

    // Sukses ≥1 item → potong coin (best-effort; kalau gagal deduct, tetap balas tapi log)
    try { CoinLedger::deduct('ai_voice_order'); } catch (Throwable $e) { ErrorLogger::log('voice', 'deduct gagal: '.$e->getMessage(), $tid, $oid); }

    echo json_encode(['ok'=>true, 'heard'=>$transcript, 'parsed'=>$parsed, 'unmatched'=>$parsed['unmatched']]);
} catch (Throwable $e) {
    ErrorLogger::log('voice', 'parse error: '.$e->getMessage(), $tid, $oid);
    echo json_encode(['ok'=>false,'reason'=>'ai_error']);
}
```
> Implementer: konfirmasi `TenantQuery::raw` ada & dipakai begini di codebase (mis. di `layanan.php`). Kalau koin harus dipotong SEBELUM balas dan deduct false berarti coin habis, sesuaikan: panggil `CoinLedger::deduct` dan kalau return false → balas `{ok:false,reason:'insufficient_coin'}` tanpa hasil. (deduct return bool: false = fitur nonaktif/coin kurang.) Pilih perilaku: cek `deduct` hasilnya; kalau false → reason `insufficient_coin`.

- [ ] **Step 2: Sesuaikan logika coin sesuai return `deduct`**

Ubah blok deduct jadi:
```php
    if (!CoinLedger::deduct('ai_voice_order')) {
        echo json_encode(['ok'=>false,'reason'=>'insufficient_coin']);
        exit;
    }
    echo json_encode(['ok'=>true, 'heard'=>$transcript, 'parsed'=>$parsed, 'unmatched'=>$parsed['unmatched']]);
```
(Hapus versi try/catch deduct di Step 1 — pakai ini. Coin dipotong hanya di titik ini, setelah parse sukses ≥1 item.)

- [ ] **Step 3: Lint**

Run: `/opt/homebrew/bin/php -l api/voice_order_parse.php`
Expected: `No syntax errors`.

- [ ] **Step 4: Commit**

```bash
git add api/voice_order_parse.php
git commit -m "feat(voice): endpoint /api/voice_order_parse (rate-limit + parse + coin on success)"
```

---

### Task 4: Frontend POS — tombol mic + modal konfirmasi + apply

**Files:**
- Modify: `pos.php`

**Interfaces:**
- Consumes: `/api/voice_order_parse.php`, `addLayananItem()`, `recalc()`, `layananAll`, `#f_nama`, `showToast()`, plugin `window.Capacitor.Plugins.SpeechRecognition`.

- [ ] **Step 1: Tambah tombol 🎤 (app-only) di area form pelanggan**

Di `pos.php`, dekat field nama (`#f_nama`, sekitar baris 1065), tambahkan tombol (default hidden, ditampilkan kalau plugin ada):
```html
<button type="button" id="voiceOrderBtn" class="btn btn-teal-sm" style="display:none" onclick="voiceOrderStart()" title="Input order dengan suara">🎤 Voice Order</button>
```

- [ ] **Step 2: Reveal tombol + fungsi rekam STT**

Tambahkan script (dekat init lain di pos.php, dalam `<script>`):
```js
document.addEventListener('DOMContentLoaded', function () {
  var SR = window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.SpeechRecognition;
  var b = document.getElementById('voiceOrderBtn');
  if (SR && b) b.style.display = '';
});

async function voiceOrderStart() {
  var SR = window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.SpeechRecognition;
  if (!SR) { showToast('Voice order hanya di app', 'error'); return; }
  if (!navigator.onLine) { showToast('Butuh internet untuk voice order', 'error'); return; }
  try {
    var perm = await SR.requestPermissions();
    if (perm && perm.speechRecognition && perm.speechRecognition !== 'granted') {
      // beberapa versi balas {speechRecognition:'granted'}; kalau ditolak:
    }
  } catch (e) {}
  try {
    var avail = await SR.available();
    if (avail && avail.available === false) { showToast('STT tak tersedia di perangkat ini', 'error'); return; }
  } catch (e) {}
  showToast('🔴 Mendengarkan… ucapkan order', 'info');
  try {
    var res = await SR.start({ language: 'id-ID', maxResults: 1, partialResults: false, popup: false });
    var text = '';
    if (res && res.matches && res.matches.length) text = res.matches[0];
    else if (Array.isArray(res) && res.length) text = res[0];
    if (!text || !text.trim()) { showToast('Tak terdengar, coba lagi', 'error'); return; }
    voiceOrderParse(text.trim());
  } catch (e) {
    showToast('Gagal merekam: ' + (e && e.message ? e.message : 'mic error'), 'error');
  }
}

async function voiceOrderParse(transcript) {
  showToast('🧠 Memproses…', 'info');
  try {
    var r = await fetch('/api/voice_order_parse.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ transcript: transcript })
    });
    var d = await r.json();
    if (!d.ok) {
      var msg = ({
        no_speech: 'Tak terdengar, coba lagi',
        rate_limited: 'Limit AI harian tercapai',
        insufficient_coin: 'Coin tak cukup untuk fitur AI',
        ai_error: 'Gagal memproses suara, coba ucapkan lebih jelas',
        no_match: 'Layanan tak dikenali dari ucapan',
        no_catalog: 'Belum ada layanan di katalog'
      })[d.reason] || 'Gagal voice order';
      // no_match: tampilkan transcript + unmatched biar kasir paham
      if (d.reason === 'no_match') voiceOrderShowModal({ heard: d.heard, parsed: { nama:null, items:[], bayar:{} }, unmatched: d.unmatched || [] }, true);
      else showToast(msg, 'error');
      return;
    }
    voiceOrderShowModal(d, false);
  } catch (e) {
    showToast('Gagal koneksi voice: ' + (e.message || e), 'error');
  }
}
```

- [ ] **Step 3: Modal konfirmasi + apply ke form**

Tambahkan markup modal (di area modal lain pos.php):
```html
<div id="voiceModal" class="modal" style="display:none">
  <div class="modal-box" style="max-width:420px">
    <h3 style="margin:0 0 8px">🎤 Yang Saya Dengar</h3>
    <div id="voiceHeard" style="font-size:12px;color:#6B7280;font-style:italic;margin-bottom:10px"></div>
    <div id="voiceFields" style="font-size:14px"></div>
    <div id="voiceUnmatched" style="display:none;background:#FEF3C7;color:#92400E;padding:8px;border-radius:8px;font-size:12px;margin-top:8px"></div>
    <div style="display:flex;gap:8px;margin-top:14px">
      <button class="btn btn-outline" style="flex:1" onclick="voiceOrderRetry()">🔄 Ulangi</button>
      <button class="btn btn-outline" onclick="document.getElementById('voiceModal').style.display='none'">✕</button>
      <button id="voiceApplyBtn" class="btn btn-green" style="flex:2" onclick="voiceOrderApply()">✓ Terapkan</button>
    </div>
  </div>
</div>
```
JS:
```js
var _voiceData = null;
function voiceOrderShowModal(d, noMatch) {
  _voiceData = d;
  document.getElementById('voiceHeard').textContent = '“' + (d.heard || '') + '”';
  var p = d.parsed || {};
  var html = '';
  if (p.nama) html += '<div>👤 Nama: <b>' + esc(p.nama) + '</b></div>';
  (p.items || []).forEach(function (it) {
    html += '<div>🧺 ' + esc(it.nama_katalog) + ' × <b>' + it.qty + '</b></div>';
  });
  if (p.bayar && (p.bayar.status || p.bayar.metode))
    html += '<div>💳 ' + esc((p.bayar.status||'') + (p.bayar.metode ? ' / ' + p.bayar.metode : '')) + '</div>';
  if (!html) html = '<div style="color:#9CA3AF">Tak ada field terdeteksi</div>';
  document.getElementById('voiceFields').innerHTML = html;
  var um = document.getElementById('voiceUnmatched');
  if (d.unmatched && d.unmatched.length) { um.style.display = ''; um.textContent = '⚠️ Tak ada di katalog: ' + d.unmatched.join(', ') + '. Tambah manual.'; }
  else um.style.display = 'none';
  document.getElementById('voiceApplyBtn').style.display = (p.items && p.items.length) ? '' : 'none';
  document.getElementById('voiceModal').style.display = 'flex';
}
function voiceOrderRetry() { document.getElementById('voiceModal').style.display = 'none'; voiceOrderStart(); }
function voiceOrderApply() {
  var p = _voiceData && _voiceData.parsed; if (!p) return;
  if (p.nama) { var n = document.getElementById('f_nama'); if (n) n.value = p.nama; }
  (p.items || []).forEach(function (it) {
    var lyn = (layananAll || []).find(function (l) { return l.id == it.layanan_id; });
    if (lyn) { for (var i = 0; i < it.qty; i++) addLayananItem(lyn.id, lyn.nama, lyn.satuan, lyn.harga); }
  });
  // set qty langsung kalo addLayananItem default 1 (lihat catatan implementer)
  if (p.bayar) {
    if (p.bayar.status) { var s = document.getElementById('f_status_bayar') || document.querySelector('[name=status_bayar]'); }
    if (p.bayar.metode) { var m = document.getElementById('f_metode') || document.querySelector('[name=metode]'); if (m) m.value = p.bayar.metode; }
  }
  if (typeof recalc === 'function') recalc();
  document.getElementById('voiceModal').style.display = 'none';
  showToast('Order terisi dari suara — cek & Simpan', 'success');
}
```
> Implementer: konfirmasi signature `addLayananItem` & cara set qty (kalau ia menerima qty, panggil sekali dengan qty; kalau tidak, set field qty item setelah push). Konfirmasi field bayar nyata di pos.php (id/name `status_bayar`/`metode`/`f_dp`) dan sesuaikan selector. Pakai `esc()` global yang sudah ada. Jangan auto-submit.

- [ ] **Step 4: Lint + commit**

Run: `/opt/homebrew/bin/php -l pos.php`
Expected: `No syntax errors`.
```bash
git add pos.php
git commit -m "feat(voice): tombol mic POS + modal konfirmasi + apply ke form"
```

---

### Task 5: App — plugin STT + build APK

**Files:**
- Modify: `~/Documents/lamasy-app/package.json`, `~/Documents/lamasy-app/README.md`

**Interfaces:**
- Consumes: frontend `voiceOrderStart()` mengakses `Capacitor.Plugins.SpeechRecognition`.

> Prasyarat: dikerjakan di Mac (pola sama seperti push notification). Tanpa langkah ini, tombol mic tetap tersembunyi (browser) / tak berfungsi.

- [ ] **Step 1: Pasang plugin**

Run:
```bash
cd ~/Documents/lamasy-app
npm install @capacitor-community/speech-recognition
```

- [ ] **Step 2: Sync + cek permission**

Run:
```bash
cd ~/Documents/lamasy-app
npx cap sync android
grep -n "RECORD_AUDIO" android/app/src/main/AndroidManifest.xml || echo "PERLU tambah RECORD_AUDIO manual"
```
Kalau `RECORD_AUDIO` belum ada, tambahkan ke `android/app/src/main/AndroidManifest.xml` dalam `<manifest>`:
```xml
<uses-permission android:name="android.permission.RECORD_AUDIO" />
```
(Catatan: `android/` di-gitignore — dokumentasikan re-apply seperti google-services.json.)

- [ ] **Step 3: Build APK**

Run:
```bash
cd ~/Documents/lamasy-app
./build-apk.sh
```
Expected: `BUILD SUCCESSFUL`, APK baru di `~/Desktop/`.

- [ ] **Step 4: Update README + commit (repo app)**

Tambah bagian "Voice Order" ke `README.md`: plugin `@capacitor-community/speech-recognition`, permission `RECORD_AUDIO`, app-only, perlu internet (parse di server).
```bash
cd ~/Documents/lamasy-app
git add package.json package-lock.json README.md
git commit -m "feat(voice): @capacitor-community/speech-recognition + RECORD_AUDIO"
```

- [ ] **Step 5: E2E manual (device)**

1. Install APK baru, login, buka POS.
2. Tap 🎤 → izinkan mic → ucapkan "Pak Heri cuci setrika reguler 3 kilo lunas tunai".
3. Modal "Yang Saya Dengar" muncul dengan transcript + item + bayar.
4. Terapkan → form terisi: nama Heri, item Cuci Setrika Reguler ×3, total = harga katalog × 3.
5. Simpan → order tersimpan.
6. Tes negatif: izin mic ditolak (toast), ucapan ngawur (no_match modal), layanan tak ada di katalog (unmatched warning), coin habis (toast).

---

## Self-Review

**1. Spec coverage:**
- STT native plugin app-only → Task 5 + Task 4 (reveal app-only). ✅
- AI parse + anti-halusinasi (validasi ke katalog) → Task 2 (`validate`) + Task 3 (server re-filter). ✅
- Field: nama, layanan+qty, bayar → Task 2 schema + Task 4 apply. ✅
- Modal konfirmasi (heard + fields + unmatched + Terapkan/Ulangi/Batal) → Task 4. ✅
- Coin on success only + tipe `ai_voice_order` → Task 1 + Task 3 (deduct setelah ≥1 item valid). ✅
- Rate limit (AIRateLimiter) → Task 3. ✅
- CSRF → Task 3 (`verifyCsrf`). ✅
- Harga selalu dari katalog (addLayananItem) → Task 4. ✅
- Tak auto-submit → Task 4 (kasir Simpan manual). ✅
- Error handling table → Task 3 (reason codes) + Task 4 (mapping toast). ✅
- Offline guard → Task 4 (`navigator.onLine`). ✅
- Testing (unit validate + E2E) → Task 2 test + Task 5 Step 5. ✅
- Out of scope (telepon/parfum/iOS) → tak ada task. ✅

**2. Placeholder scan:** Tak ada "TBD/TODO". Beberapa langkah Task 3/4 minta implementer konfirmasi nama kolom/selector nyata (TenantQuery::raw, field bayar pos.php, addLayananItem qty) — diarahkan dengan instruksi eksplisit + kode contoh, bukan placeholder. Task 1 Step 3 punya cabang kondisional (tabel vs const) dengan instruksi jelas.

**3. Type consistency:** `VoiceOrderParser::validate/parse/buildPrompt` konsisten Task 2↔3. Bentuk `parsed` (`nama`/`items[{layanan_id,nama_katalog,qty}]`/`bayar{status,metode}`/`unmatched`) konsisten Task 2 (return) ↔ Task 3 (JSON) ↔ Task 4 (modal+apply). Reason codes (`no_speech|rate_limited|insufficient_coin|ai_error|no_match|no_catalog`) konsisten Task 3 (emit) ↔ Task 4 (mapping). `CoinLedger::deduct` return bool dipakai benar di Task 3 Step 2.

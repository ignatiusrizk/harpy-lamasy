# Tambah Kas via Foto Struk (AI Vision) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Admin foto/upload struk belanja → AI vision baca → ekstrak total/tanggal/keterangan/kategori → modal konfirmasi editable → isi form Tambah Kas (pengeluaran) → admin Simpan; foto disimpan sebagai bukti.

**Architecture:** Input file image (app+browser, tanpa plugin native) → upload via `FileUpload::uploadImage` → `POST /api/kas_struk_scan.php` → `ReceiptScanner` kirim base64 ke `AnthropicClient::askJsonWithImage` (vision) → JSON tervalidasi → coin on success → modal → isi `kas.php` form (tipe=keluar + bukti_foto) → admin Simpan.

**Tech Stack:** PHP 8 / MariaDB. `core/AnthropicClient.php` (+image), `core/ReceiptScanner.php` (new), `core/FileUpload.php` (uploadImage existing), `core/CoinLedger.php`, `core/AIRateLimiter.php`, tabel `hl_kas`, `kas.php`. Test: skrip CLI + `tests/_assert.php` (existing) + array fixture/mock.

## Global Constraints

- Multi-tenant: `hl_kas` & query scoping `tenant_id` (+ `outlet_id`). Upload prefix `t{tid}_o{oid}`.
- `jumlah` selalu numeric > 0; `tipe` selalu `keluar` (tak dari AI). Admin WAJIB review sebelum Simpan — **tak pernah auto-submit**.
- **Potong coin hanya jika sukses** (is_receipt && jumlah>0). Gagal upload/AI/JSON/not_receipt → coin TIDAK dipotong.
- Rate limit `AIRateLimiter::canCall('ai_kas_struk')` sebelum AI.
- Endpoint POST WAJIB `verifyCsrf()` (token auto via interceptor global).
- `foto_path` divalidasi: harus diawali folder upload tenant-prefixed milik tenant ini (anti path-traversal / cross-tenant).
- Semua kegagalan best-effort + `ErrorLogger`; tak crash halaman Kas.
- PHP CLI: `/opt/homebrew/bin/php`. mysql: `/opt/homebrew/opt/mysql-client/bin/mysql`. Deploy: `git push origin main`.

## Signatures (verbatim, dari codebase)

- `FileUpload::uploadImage(array $file, string $folder, string $prefix = ''): array` → `['path'=>string,'error'=>?string]`. Validasi 2MB + image (jpg/png/webp/gif). `path` relatif (mis. `uploads/kas_bukti/t1_o1_xxx.jpg`).
- `AnthropicClient::ask(string $prompt, array $opts=[]): array` → `{text,tokens_in,...,model}`. `$opts`: `system`,`model`,`max_tokens`,`temperature`. Payload `messages:[{role:user,content:$prompt}]` (string content). **askJson() membungkus hasil di key `['json']`** — method image baru akan kembalikan parsed JSON LANGSUNG (bukan wrapper) untuk kejelasan.
- `CoinLedger::deduct(string $feature, ?string $refId=null, ?int $overrideCost=null): bool` (false = nonaktif/coin kurang). COSTS di `const COSTS`. getHarga baca `saas_coin_pricing` (kolom `feature_key,harga_coin,daily_limit,is_active`) fallback COSTS.
- `AIRateLimiter::canCall(string $feature, ?int $tid=null): bool` + `errorResponse(string $feature): array`.
- `kas.php?action=save` POST JSON: `{tanggal,tipe,kategori,keterangan,jumlah,ref_order}` → insert/update `hl_kas`. Perm `kas.create`, `verifyCsrf()`.

## File Structure

- `migrations/2026-06-26-kas-bukti-foto.sql` (NEW) — ALTER hl_kas + seed coin.
- `core/CoinLedger.php` (MODIFY) — `'ai_kas_struk'` di COSTS.
- `core/AnthropicClient.php` (MODIFY) — `askJsonWithImage`.
- `core/ReceiptScanner.php` (NEW) — buildPrompt + validate + scan.
- `api/kas_struk_scan.php` (NEW) — endpoint.
- `kas.php` (MODIFY) — tombol Scan + modal + apply + save bukti_foto + tampil bukti.
- `tests/kas/test_receipt_validate.php` (NEW) — unit validate.

---

### Task 1: Schema (bukti_foto) + coin cost

**Files:**
- Create: `migrations/2026-06-26-kas-bukti-foto.sql`
- Modify: `core/CoinLedger.php`

**Interfaces:**
- Produces: kolom `hl_kas.bukti_foto VARCHAR(255) NULL`; feature key `'ai_kas_struk'`.

- [ ] **Step 1: Tulis migration**

`migrations/2026-06-26-kas-bukti-foto.sql`:
```sql
ALTER TABLE hl_kas ADD COLUMN IF NOT EXISTS bukti_foto VARCHAR(255) NULL AFTER keterangan;
INSERT INTO saas_coin_pricing (feature_key, harga_coin, daily_limit, is_active)
VALUES ('ai_kas_struk', 50, 100, 1)
ON DUPLICATE KEY UPDATE harga_coin = VALUES(harga_coin);
```
> Implementer: konfirmasi nama kolom `saas_coin_pricing` (SHOW COLUMNS) cocok (`feature_key,harga_coin,daily_limit,is_active`) — sama seperti yang dipakai migration `ai_voice_order`. Kalau tabel butuh kolom `nama_fitur` NOT NULL, sertakan juga (lihat baris ai_voice_order existing sebagai contoh).

- [ ] **Step 2: Terapkan + verifikasi**

Run: `/opt/homebrew/opt/mysql-client/bin/mysql < migrations/2026-06-26-kas-bukti-foto.sql`
Run: `/opt/homebrew/opt/mysql-client/bin/mysql -e "SHOW COLUMNS FROM hl_kas LIKE 'bukti_foto'; SELECT feature_key,harga_coin FROM saas_coin_pricing WHERE feature_key='ai_kas_struk';"`
Expected: kolom ada + row coin ada.

- [ ] **Step 3: Tambah COSTS**

Di `core/CoinLedger.php` `const COSTS`, setelah `'ai_voice_order' => 50,`:
```php
        'ai_kas_struk'       =>  50,
```

- [ ] **Step 4: Lint + commit**

```bash
/opt/homebrew/bin/php -l core/CoinLedger.php
git add migrations/2026-06-26-kas-bukti-foto.sql core/CoinLedger.php
git commit -m "feat(kas-struk): kolom hl_kas.bukti_foto + coin ai_kas_struk"
```

---

### Task 2: AnthropicClient vision — `askJsonWithImage`

**Files:**
- Modify: `core/AnthropicClient.php`

**Interfaces:**
- Produces: `AnthropicClient::askJsonWithImage(string $prompt, string $base64, string $mediaType, array $opts = []): array` — kirim image+text ke Claude vision, kembalikan **parsed JSON array LANGSUNG** (bukan wrapper). Throw RuntimeException kalau API/parse gagal.

- [ ] **Step 1: Implementasi method (tambah sebelum `}` penutup kelas)**

```php
    /**
     * Vision: kirim 1 gambar (base64) + prompt, minta JSON. Kembalikan parsed JSON array langsung.
     * @param string $mediaType image/jpeg|image/png|image/webp|image/gif
     */
    public static function askJsonWithImage(string $prompt, string $base64, string $mediaType, array $opts = []): array
    {
        if (!defined('ANTHROPIC_API_KEY') || empty(ANTHROPIC_API_KEY)) {
            throw new RuntimeException('ANTHROPIC_API_KEY belum di-set di config.');
        }
        $model     = $opts['model']      ?? self::DEFAULT_MODEL;
        $maxTokens = (int)($opts['max_tokens'] ?? 1024);
        $timeout   = (int)($opts['timeout']    ?? 40);
        $system    = trim(($opts['system'] ?? '') . "\n\nIMPORTANT: Respond ONLY with valid JSON. No markdown fences, no explanation. Start with {.");

        $payload = [
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'temperature'=> 0,
            'system'     => $system,
            'messages'   => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mediaType, 'data' => $base64]],
                    ['type' => 'text',  'text' => $prompt],
                ],
            ]],
        ];

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . ANTHROPIC_API_KEY,
                'anthropic-version: ' . self::API_VERSION,
                'content-type: application/json',
            ],
        ]);
        $raw  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            if (class_exists('ErrorLogger')) ErrorLogger::log('ai_error', "Anthropic vision cURL: $err");
            throw new RuntimeException("Anthropic vision cURL error: $err");
        }
        if ($http !== 200) {
            $eb = json_decode($raw, true);
            $em = $eb['error']['message'] ?? "HTTP $http";
            if (class_exists('ErrorLogger')) ErrorLogger::log('ai_error', "Anthropic vision ($http): $em", null, null, null, null, (string)$http);
            throw new RuntimeException("Anthropic vision error ($http): $em");
        }
        $data = json_decode($raw, true);
        $text = '';
        foreach (($data['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') $text .= $block['text'];
        }
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($text));
        $parsed = json_decode(trim($text), true);
        if (!is_array($parsed)) {
            throw new RuntimeException('Vision response bukan JSON valid: ' . substr($text, 0, 200));
        }
        return $parsed;
    }
```
> Implementer: konfirmasi konstanta `self::API_URL`, `self::API_VERSION`, `self::DEFAULT_MODEL` ada (dipakai `ask()`). Jangan ubah `ask()`/`askJson()` existing.

- [ ] **Step 2: Lint + commit**

```bash
/opt/homebrew/bin/php -l core/AnthropicClient.php
git add core/AnthropicClient.php
git commit -m "feat(kas-struk): AnthropicClient::askJsonWithImage (vision → JSON)"
```

---

### Task 3: `core/ReceiptScanner.php` (buildPrompt + validate + scan)

**Files:**
- Create: `core/ReceiptScanner.php`
- Create: `tests/kas/test_receipt_validate.php`

**Interfaces:**
- Consumes: `AnthropicClient::askJsonWithImage` (di `scan`, di-mock saat test).
- Produces:
  - `ReceiptScanner::buildPrompt(): string`
  - `ReceiptScanner::validate(array $raw): array` — pure. Return `['ok'=>bool, 'jumlah'=>int, 'tanggal'=>?string, 'keterangan'=>string, 'kategori'=>string]` (saat ok=false, field lain boleh absen).
  - `ReceiptScanner::scan(string $base64, string $mediaType, ?callable $aiFn = null): array` — `$aiFn` opsional `fn(string $prompt): array` (test); default `AnthropicClient::askJsonWithImage`. Return sama seperti `validate`.

- [ ] **Step 1: Tulis test validate (failing)**

`tests/kas/test_receipt_validate.php`:
```php
<?php
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../core/ReceiptScanner.php';

// 1. struk valid
$r = ReceiptScanner::validate(['is_receipt'=>true,'jumlah'=>85000,'tanggal'=>'2026-06-20','keterangan'=>'Toko Makmur deterjen','kategori'=>'Bahan']);
eqv($r['ok'], true, 'struk valid ok');
eqv($r['jumlah'], 85000, 'jumlah int');
eqv($r['tanggal'], '2026-06-20', 'tanggal valid kept');
eqv($r['kategori'], 'Bahan', 'kategori kept');

// 2. jumlah dengan pemisah ribuan / "Rp"
$r2 = ReceiptScanner::validate(['is_receipt'=>true,'jumlah'=>'Rp 85.000','keterangan'=>'x']);
eqv($r2['ok'], true, 'jumlah string berformat ok');
eqv($r2['jumlah'], 85000, 'Rp 85.000 → 85000');

// 3. is_receipt false → gagal
$r3 = ReceiptScanner::validate(['is_receipt'=>false,'jumlah'=>5000]);
eqv($r3['ok'], false, 'bukan struk → gagal');

// 4. jumlah 0/negatif → gagal
eqv(ReceiptScanner::validate(['is_receipt'=>true,'jumlah'=>0])['ok'], false, 'jumlah 0 gagal');
eqv(ReceiptScanner::validate(['is_receipt'=>true,'jumlah'=>-100])['ok'], false, 'jumlah negatif gagal');

// 5. tanggal invalid / masa depan jauh → null
$r5 = ReceiptScanner::validate(['is_receipt'=>true,'jumlah'=>1000,'tanggal'=>'bukan-tanggal','keterangan'=>'x']);
eqv($r5['tanggal'], null, 'tanggal invalid → null');
$r6 = ReceiptScanner::validate(['is_receipt'=>true,'jumlah'=>1000,'tanggal'=>'2099-01-01','keterangan'=>'x']);
eqv($r6['tanggal'], null, 'tanggal masa depan jauh → null');

// 6. keterangan kosong → fallback
$r7 = ReceiptScanner::validate(['is_receipt'=>true,'jumlah'=>1000,'keterangan'=>'']);
eqv($r7['keterangan'], 'Belanja (scan struk)', 'keterangan kosong → fallback');

// scan() dengan mock
$mock = fn(string $p) => ['is_receipt'=>true,'jumlah'=>'12.500','tanggal'=>'2026-06-25','keterangan'=>'Indomaret','kategori'=>'Operasional'];
$rs = ReceiptScanner::scan('BASE64', 'image/jpeg', $mock);
eqv($rs['ok'], true, 'scan mock ok');
eqv($rs['jumlah'], 12500, 'scan jumlah 12.500 → 12500');

echo "ALL OK\n";
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `/opt/homebrew/bin/php tests/kas/test_receipt_validate.php`
Expected: FATAL "Class ReceiptScanner not found".

- [ ] **Step 3: Implementasi `core/ReceiptScanner.php`**

```php
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
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `/opt/homebrew/bin/php tests/kas/test_receipt_validate.php`
Expected: `PASS:` semua + `ALL OK`.

- [ ] **Step 5: Lint + commit**

```bash
/opt/homebrew/bin/php -l core/ReceiptScanner.php
git add core/ReceiptScanner.php tests/kas/test_receipt_validate.php
git commit -m "feat(kas-struk): ReceiptScanner (prompt + validate + scan)"
```

---

### Task 4: Endpoint `api/kas_struk_scan.php`

**Files:**
- Create: `api/kas_struk_scan.php`

**Interfaces:**
- Consumes: `ReceiptScanner::scan`, `AIRateLimiter`, `CoinLedger`, file di `uploads/`.
- Produces: `POST /api/kas_struk_scan.php { foto_path }` → `{ok, parsed:{jumlah,tanggal,keterangan,kategori}, foto_path}` atau `{ok:false, reason}`.

- [ ] **Step 1: Implementasi endpoint**

`api/kas_struk_scan.php`:
```php
<?php
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/ReceiptScanner.php';
require_once ROOT . '/core/AIRateLimiter.php';
require_once ROOT . '/core/CoinLedger.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'reason'=>'method']); exit; }
verifyCsrf();
if (!hasPermission('kas.create')) { echo json_encode(['ok'=>false,'reason'=>'forbidden']); exit; }

$tid = TenantResolver::id();
$oid = TenantResolver::outletId();

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$fotoPath = (string)($data['foto_path'] ?? '');

// Validasi path: harus di folder bukti kas + prefix tenant ini (anti traversal/cross-tenant)
$prefix = 'uploads/kas_bukti/t' . $tid . '_o' . $oid;
$norm   = str_replace('\\', '/', $fotoPath);
if (strpos($norm, '..') !== false || strpos($norm, $prefix) !== 0) {
    echo json_encode(['ok'=>false,'reason'=>'bad_path']); exit;
}
$full = ROOT . '/' . $norm;
if (!is_file($full)) { echo json_encode(['ok'=>false,'reason'=>'bad_path']); exit; }

if (!AIRateLimiter::canCall('ai_kas_struk', $tid)) {
    echo json_encode(['ok'=>false,'reason'=>'rate_limited'] + AIRateLimiter::errorResponse('ai_kas_struk'));
    exit;
}

try {
    $bytes = file_get_contents($full);
    $mime  = function_exists('mime_content_type') ? (mime_content_type($full) ?: 'image/jpeg') : 'image/jpeg';
    if (!in_array($mime, ['image/jpeg','image/png','image/webp','image/gif'], true)) $mime = 'image/jpeg';
    $base64 = base64_encode($bytes);

    $res = ReceiptScanner::scan($base64, $mime);
    if (empty($res['ok'])) { echo json_encode(['ok'=>false,'reason'=>'not_receipt']); exit; }

    if (!CoinLedger::deduct('ai_kas_struk')) { echo json_encode(['ok'=>false,'reason'=>'insufficient_coin']); exit; }

    echo json_encode(['ok'=>true, 'parsed'=>[
        'jumlah'     => $res['jumlah'],
        'tanggal'    => $res['tanggal'],
        'keterangan' => $res['keterangan'],
        'kategori'   => $res['kategori'],
    ], 'foto_path'=>$norm]);
} catch (Throwable $e) {
    ErrorLogger::log('kas_struk', 'scan error: ' . $e->getMessage(), $tid, $oid);
    echo json_encode(['ok'=>false,'reason'=>'ai_error']);
}
```
> Implementer: konfirmasi `hasPermission`/`TenantResolver`/`ErrorLogger` tersedia via `tenant_guard` (ya — dipakai endpoint lain). Coin dipotong hanya setelah `scan` ok.

- [ ] **Step 2: Lint + commit**

```bash
/opt/homebrew/bin/php -l api/kas_struk_scan.php
git add api/kas_struk_scan.php
git commit -m "feat(kas-struk): endpoint /api/kas_struk_scan (upload→vision→coin on success)"
```

---

### Task 5: Frontend `kas.php` — tombol Scan + upload + modal + apply + simpan bukti

**Files:**
- Modify: `kas.php`

**Interfaces:**
- Consumes: `/api/kas_struk_scan.php`, `FileUpload` (via upload action), `kas.php?action=save` (perluas terima `bukti_foto`), `showToast`/`esc` global.

- [ ] **Step 1: Backend — upload action + perluas save terima bukti_foto**

Di `kas.php` area handler action, tambah action upload (sebelum render HTML):
```php
    if ($action === 'upload_bukti' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('kas.create')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        require_once ROOT . '/core/FileUpload.php';
        $up = FileUpload::uploadImage($_FILES['foto'] ?? [], 'uploads/kas_bukti', 't'.$tid.'_o'.$oid);
        if ($up['error']) { echo json_encode(['error'=>$up['error']]); exit; }
        echo json_encode(['path'=>$up['path']]); exit;
    }
```
Di handler `save`, tambahkan `bukti_foto` ke `$data` (setelah `'jumlah'`):
```php
            'bukti_foto' => !empty($d['bukti_foto']) ? substr(trim($d['bukti_foto']),0,255) : null,
```

- [ ] **Step 2: Frontend — tombol Scan + input file**

Dekat tombol "Tambah Kas" di `kas.php`, tambahkan:
```html
<button type="button" class="btn btn-teal-sm" onclick="document.getElementById('strukFile').click()">📸 Scan Struk</button>
<input type="file" id="strukFile" accept="image/*" capture="environment" style="display:none" onchange="kasStrukUpload(this)">
```

- [ ] **Step 3: Frontend — upload + scan + modal + apply**

Tambah JS di `kas.php`:
```js
let _strukParsed = null, _strukPath = null;
async function kasStrukUpload(input) {
  const f = input.files && input.files[0];
  input.value = '';
  if (!f) return;
  showToast('📤 Mengunggah…', 'info');
  try {
    const fd = new FormData(); fd.append('foto', f);
    const up = await fetch('kas.php?action=upload_bukti', { method:'POST', body: fd });
    const ud = await up.json();
    if (ud.error) { showToast(ud.error, 'error'); return; }
    showToast('🧠 Membaca struk…', 'info');
    const r = await fetch('/api/kas_struk_scan.php', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ foto_path: ud.path })
    });
    const d = await r.json();
    if (!d.ok) {
      const msg = ({ rate_limited:'Limit AI harian tercapai', insufficient_coin:'Coin tak cukup',
        ai_error:'Gagal membaca struk, coba foto lebih jelas', not_receipt:'Bukan struk / total tak terbaca',
        bad_path:'File tidak valid', forbidden:'Akses ditolak' })[d.reason] || 'Gagal scan struk';
      showToast(msg, 'error'); return;
    }
    _strukParsed = d.parsed; _strukPath = d.foto_path;
    kasStrukShowModal(d.parsed, d.foto_path);
  } catch (e) { showToast('Gagal koneksi: ' + (e.message||e), 'error'); }
}

function kasStrukShowModal(p, path) {
  document.getElementById('ksImg').src = '/' + path;
  document.getElementById('ksJumlah').value     = p.jumlah || '';
  document.getElementById('ksTanggal').value    = p.tanggal || new Date().toISOString().slice(0,10);
  document.getElementById('ksKeterangan').value = p.keterangan || '';
  document.getElementById('ksKategori').value   = p.kategori || '';
  document.getElementById('kasStrukModal').style.display = 'flex';
}
function kasStrukApply() {
  // isi form Tambah Kas (sesuaikan id field form kas existing) + bukti_foto, set tipe keluar, JANGAN auto-submit
  if (typeof openKasForm === 'function') openKasForm('keluar');   // buka form kas mode keluar bila ada
  const set = (id,v)=>{ const el=document.getElementById(id); if(el) el.value=v; };
  set('k_tanggal',   document.getElementById('ksTanggal').value);
  set('k_jumlah',    document.getElementById('ksJumlah').value);
  set('k_keterangan',document.getElementById('ksKeterangan').value);
  set('k_kategori',  document.getElementById('ksKategori').value);
  const tp = document.getElementById('k_tipe'); if (tp) tp.value = 'keluar';
  let bf = document.getElementById('k_bukti_foto');
  if (!bf) { bf = document.createElement('input'); bf.type='hidden'; bf.id='k_bukti_foto'; (document.getElementById('kasForm')||document.body).appendChild(bf); }
  bf.value = _strukPath || '';
  document.getElementById('kasStrukModal').style.display = 'none';
  showToast('Form terisi dari struk — cek & Simpan', 'success');
}
```
> Implementer: WAJIB baca `kas.php` dulu untuk identifier FORM KAS yang nyata (id field tanggal/jumlah/keterangan/kategori/tipe, nama fungsi buka form, dan cara `save` mengirim body — pastikan `bukti_foto` ikut terkirim dari `k_bukti_foto`). Ganti `k_*`/`openKasForm`/`kasForm` di atas dengan yang nyata. Pastikan submit kas menyertakan `bukti_foto`. JANGAN panggil fungsi simpan otomatis.

- [ ] **Step 4: Modal markup**

Tambah di `kas.php`:
```html
<div id="kasStrukModal" class="modal" style="display:none">
  <div class="modal-box" style="max-width:440px">
    <h3 style="margin:0 0 10px">🧾 Hasil Baca Struk</h3>
    <img id="ksImg" style="max-width:100%;max-height:180px;border-radius:8px;display:block;margin:0 auto 10px"/>
    <label>Jumlah (Rp)</label><input type="number" id="ksJumlah" class="input" min="1">
    <label>Tanggal</label><input type="date" id="ksTanggal" class="input">
    <label>Keterangan</label><input type="text" id="ksKeterangan" class="input" maxlength="500">
    <label>Kategori</label><input type="text" id="ksKategori" class="input" maxlength="50">
    <div style="display:flex;gap:8px;margin-top:14px">
      <button class="btn btn-outline" style="flex:1" onclick="document.getElementById('strukFile').click();document.getElementById('kasStrukModal').style.display='none'">🔄 Scan Ulang</button>
      <button class="btn btn-outline" onclick="document.getElementById('kasStrukModal').style.display='none'">✕</button>
      <button class="btn btn-green" style="flex:2" onclick="kasStrukApply()">✓ Terapkan ke Form</button>
    </div>
  </div>
</div>
```

- [ ] **Step 5: Tampilkan bukti di list/detail kas**

Di render baris kas (list), kalau `bukti_foto` ada, tampilkan ikon link:
```js
// dalam template baris: gunakan row.bukti_foto
${row.bukti_foto ? `<a href="/${row.bukti_foto}" target="_blank" title="Lihat bukti">🧾</a>` : ''}
```
> Implementer: sesuaikan ke fungsi render list kas yang ada (kalau list di-render server-side PHP, tambahkan kolom/ikon di sana). Pastikan `SELECT *` sudah mengambil `bukti_foto` (action `list` pakai `SELECT * FROM hl_kas` → otomatis ada).

- [ ] **Step 6: Lint + commit**

```bash
/opt/homebrew/bin/php -l kas.php
git add kas.php
git commit -m "feat(kas-struk): tombol Scan Struk + modal konfirmasi + isi form + simpan bukti"
```

---

## Self-Review

**1. Spec coverage:**
- Kolom bukti_foto → Task 1. ✅
- Coin ai_kas_struk → Task 1. ✅
- AI vision (image) → Task 2. ✅
- Prompt + validate (is_receipt, jumlah>0, tanggal, keterangan fallback, kategori) → Task 3 + test. ✅
- Endpoint (CSRF, rate-limit, path validation tenant-scoped, coin on success) → Task 4. ✅
- Tombol Scan (app+browser, input file) + upload + modal editable + apply + simpan bukti + tampil bukti → Task 5. ✅
- tipe=keluar tetap, tak auto-submit → Task 5. ✅
- Error reason mapping → Task 4 (emit) + Task 5 (toast). ✅
- Testing (unit validate + E2E) → Task 3 test + manual. ✅

**2. Placeholder scan:** Tak ada TBD/TODO. Task 1/2/4 minta konfirmasi kolom/konstanta nyata; Task 5 minta implementer baca id form kas nyata + ganti `k_*`/`openKasForm` — diarahkan eksplisit dengan kode contoh, bukan placeholder (form-kas internals tak bisa di-pin tanpa baca kas.php; ini integration yang sah diarahkan).

**3. Type consistency:** `ReceiptScanner::validate/scan/buildPrompt` konsisten Task 3↔4. `validate` return `{ok,jumlah,tanggal,keterangan,kategori}` konsisten Task 3 (return) ↔ Task 4 (parsed JSON) ↔ Task 5 (modal/apply). Reason codes (`method|forbidden|bad_path|rate_limited|insufficient_coin|not_receipt|ai_error`) konsisten Task 4 (emit) ↔ Task 5 (mapping). `askJsonWithImage` return = parsed JSON langsung (Task 2) dipakai `scan` Task 3. `bukti_foto` konsisten Task 1 (kolom) ↔ Task 4 (foto_path balikan) ↔ Task 5 (save).

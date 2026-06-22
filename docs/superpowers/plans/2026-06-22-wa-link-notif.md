# WA Link Notif Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Beri kasir tombol "📱 WA Pelanggan" di POS dan Orders yang buka WhatsApp dengan pesan ter-fill (no backend gateway, pakai `wa.me` link).

**Architecture:** Helper PHP normalisasi nomor + substitusi template → endpoint kecil yang ambil data order + build URL → frontend `window.open()`. Server hanya generate URL, kasir tetap yang tekan Send di WA.

**Tech Stack:** PHP 8.1 + PDO + vanilla JS. Tidak ada library baru. Tidak ada migration DB. Audit log lewat helper `logAudit()` existing.

## Global Constraints

- Semua endpoint baru pakai `tenant_guard.php` (auth + tenant scope).
- Pakai `verifyCsrf()` di POST endpoints; GET cukup tenant guard.
- Spec: [docs/superpowers/specs/2026-06-22-wa-link-notif-design.md](../specs/2026-06-22-wa-link-notif-design.md)
- Kolom DB existing: `hl_transaksi(no_order, nama_pelanggan, telepon, total, estimasi_selesai, outlet_id, tenant_id, status_proses)`, outlets join via `o.nama_outlet`.
- Base URL: `APP_URL` constant (`https://lamasy.harpy.id`) sudah ada di `master/config`.
- Tidak ada framework test PHP — verifikasi via curl + smoke test di browser.

## File Structure

- **Modify** `components.php` — tambah `WA_TEMPLATES` const + function `waLink()` + `waNormalizePhone()` + render helper `waButton()` untuk konsistensi UI.
- **Create** `api/wa_link.php` — endpoint GET: terima `order_id` + `t` (template key), return `{url}` atau `{error}`.
- **Modify** `pos.php` — tambah tombol "WA Pelanggan" di 2 lokasi: (a) modal sukses setelah create order, (b) area struk setelah pembayaran lunas.
- **Modify** `orders.php` — tambah checkbox "Kirim WA" di modal update status; saat status = `selesai` & checkbox aktif, fetch URL lalu `window.open()`.

---

### Task 1: Helper `waNormalizePhone()` + `waLink()` + templates

**Files:**
- Modify: `components.php` — tambah di bagian akhir, sebelum closing PHP tag (atau setelah block helper existing)

**Interfaces:**
- Produces:
  - `waNormalizePhone(?string $phone): ?string` — return E.164-ish (`628xxx`) atau `null` kalau invalid.
  - `waLink(?string $phone, string $template, array $vars = []): ?string` — return URL `https://wa.me/...?text=...` atau `null` kalau phone invalid / template tidak dikenal.
  - `WA_TEMPLATES` array constant — key: `order_diterima`, `order_ready`, `struk_lunas`, value: template body.

- [ ] **Step 1: Tulis smoke-test script ad-hoc**

Buat file scratch (tidak di-commit) untuk verifikasi cepat:

```bash
cat > /tmp/wa_test.php <<'EOF'
<?php
require_once '/Users/rizky/Documents/lamasy/components.php';

// normalize
var_dump(waNormalizePhone('081234567890'));   // expect: "6281234567890"
var_dump(waNormalizePhone('+62 812-3456-7890')); // expect: "6281234567890"
var_dump(waNormalizePhone('8123456789'));     // expect: "628123456789"
var_dump(waNormalizePhone(''));               // expect: NULL
var_dump(waNormalizePhone('abc'));            // expect: NULL
var_dump(waNormalizePhone('123'));            // expect: NULL (terlalu pendek)

// link
echo waLink('0812-3456-7890', 'order_ready', [
    'nama' => 'Budi', 'kode' => 'INV001', 'outlet' => 'Laundry XYZ', 'total' => '50.000'
]) . "\n";
// expect URL: https://wa.me/6281234567890?text=Halo%20Budi...

echo waLink(null, 'order_ready', []) . "\n";   // expect: empty (null)
echo waLink('0812', 'unknown_key', []) . "\n"; // expect: empty (null)
EOF
```

- [ ] **Step 2: Jalankan, konfirmasi semua FAIL (function belum ada)**

```bash
php /tmp/wa_test.php
```

Expected: `PHP Fatal error: Uncaught Error: Call to undefined function waNormalizePhone()`

- [ ] **Step 3: Implementasi di `components.php`**

Tambah sebelum closing PHP tag terakhir (atau di akhir file kalau file murni PHP tanpa close tag):

```php
// ── WA Link helpers ───────────────────────────────────
const WA_TEMPLATES = [
    'order_diterima' => "Halo {nama} 👋\nPesanan #{kode} sudah kami terima di {outlet}.\nEstimasi selesai: {tgl_ambil}\nCek status: {link_track}\n\nTerima kasih!",
    'order_ready'    => "Halo {nama} ✨\nPesanan #{kode} sudah siap diambil di {outlet}.\nTotal: Rp {total}\n\nDitunggu ya!",
    'struk_lunas'    => "Terima kasih {nama} 🙏\nPembayaran #{kode} lunas. Total Rp {total}.\nStruk digital: {link_struk}",
];

function waNormalizePhone(?string $phone): ?string {
    if (!$phone) return null;
    $clean = preg_replace('/[^0-9+]/', '', $phone);
    if (str_starts_with($clean, '+')) $clean = substr($clean, 1);
    if (str_starts_with($clean, '0'))  $clean = '62' . substr($clean, 1);
    if (str_starts_with($clean, '8'))  $clean = '62' . $clean;
    if (!preg_match('/^[0-9]{9,15}$/', $clean)) return null;
    return $clean;
}

function waLink(?string $phone, string $template, array $vars = []): ?string {
    $tpl = WA_TEMPLATES[$template] ?? null;
    if ($tpl === null) return null;
    $normalized = waNormalizePhone($phone);
    if ($normalized === null) return null;
    $body = preg_replace_callback('/\{(\w+)\}/', function($m) use ($vars) {
        return $vars[$m[1]] ?? '';
    }, $tpl);
    return 'https://wa.me/' . $normalized . '?text=' . rawurlencode($body);
}
```

- [ ] **Step 4: Jalankan test ulang, konfirmasi PASS**

```bash
php /tmp/wa_test.php
```

Expected output (cek manual):
- `string(13) "6281234567890"` 3x
- `NULL` 3x
- URL benar untuk `order_ready`
- 2 baris kosong untuk null/invalid template

Hapus file scratch: `rm /tmp/wa_test.php`

- [ ] **Step 5: Commit**

```bash
git add components.php
git commit -m "feat(wa-link): helper waNormalizePhone + waLink + templates"
```

---

### Task 2: Endpoint `api/wa_link.php`

**Files:**
- Create: `api/wa_link.php`

**Interfaces:**
- Consumes: `waLink()`, `waNormalizePhone()`, `WA_TEMPLATES` dari Task 1.
- Produces:
  - HTTP endpoint: `GET /api/wa_link.php?order_id={int}&t={template_key}`
  - Response 200 JSON: `{url: "https://wa.me/..."}` atau `{error: "..."}` (always JSON, never HTML).
  - Status 400 untuk input invalid, 404 untuk order tidak ditemukan, 403 untuk order milik tenant lain.

- [ ] **Step 1: Tulis smoke-test plan (manual)**

Tidak ada framework test. Plan-nya:
1. Login dulu di browser ke `/dashboard` (set session valid).
2. Cari `order_id` valid dari `hl_transaksi` (misal lewat orders.php).
3. Hit endpoint via curl pakai session cookie, atau via DevTools fetch().
4. Verifikasi response.

- [ ] **Step 2: Konfirmasi endpoint belum ada**

```bash
curl -s -o /dev/null -w "%{http_code}\n" "https://lamasy.harpy.id/api/wa_link.php?order_id=1&t=order_ready"
```

Expected: `404` (file belum ada)

- [ ] **Step 3: Implementasi `api/wa_link.php`**

```php
<?php
// ══════════════════════════════════════════════════════
// api/wa_link.php — Generate wa.me URL untuk order
//
// GET ?order_id=123&t={order_diterima|order_ready|struk_lunas}
//   → 200 {url}
//   → 400 {error: 'bad input'}
//   → 404 {error: 'order not found'}
// ══════════════════════════════════════════════════════

define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/components.php';
require_once ROOT . '/core/TenantResolver.php';

header('Content-Type: application/json');

$orderId  = (int)($_GET['order_id'] ?? 0);
$template = $_GET['t'] ?? '';

if ($orderId <= 0 || !array_key_exists($template, WA_TEMPLATES)) {
    http_response_code(400);
    echo json_encode(['error' => 'Parameter tidak valid.']);
    exit;
}

$tid = TenantResolver::id();
$oid = TenantResolver::outletId();

$db = Database::get();
$st = $db->prepare(
    "SELECT t.no_order, t.nama_pelanggan, t.telepon, t.total, t.estimasi_selesai,
            o.nama_outlet
       FROM hl_transaksi t
  LEFT JOIN outlets o ON o.id = t.outlet_id
      WHERE t.id = ? AND t.tenant_id = ? AND t.outlet_id = ?
      LIMIT 1"
);
$st->execute([$orderId, $tid, $oid]);
$order = $st->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    http_response_code(404);
    echo json_encode(['error' => 'Order tidak ditemukan.']);
    exit;
}

$tglAmbil = $order['estimasi_selesai']
    ? date('d M Y', strtotime($order['estimasi_selesai']))
    : '-';

$vars = [
    'nama'       => $order['nama_pelanggan'] ?: 'Pelanggan',
    'kode'       => $order['no_order'],
    'outlet'     => $order['nama_outlet'] ?: 'Laundry',
    'total'      => number_format((float)$order['total'], 0, ',', '.'),
    'tgl_ambil'  => $tglAmbil,
    'link_track' => APP_URL . '/track.php?order=' . urlencode($order['no_order']),
    'link_struk' => APP_URL . '/api/struk.php?action=generate&id=' . $orderId,
];

$url = waLink($order['telepon'], $template, $vars);

if ($url === null) {
    echo json_encode(['error' => 'Nomor WA pelanggan tidak valid atau kosong.']);
    exit;
}

logAudit('wa_link', 'order', "order_id={$orderId} template={$template}");

echo json_encode(['url' => $url]);
```

- [ ] **Step 4: Manual verify happy path**

Pastikan sudah login session valid di browser. Ambil cookie session lalu:

```bash
# Asumsi cookie session sudah di-export ke ~/.lamasy_cookie
# Atau hit via DevTools fetch() di tab yang sudah login:
fetch('/api/wa_link.php?order_id=<ID_VALID>&t=order_ready').then(r=>r.json()).then(console.log)
```

Expected: `{url: "https://wa.me/62xxx?text=..."}`. Buka URL → WhatsApp Web kebuka dengan body pesan benar.

- [ ] **Step 5: Manual verify error cases**

DevTools fetch:
- `?order_id=999999&t=order_ready` → `{error: "Order tidak ditemukan."}` + 404
- `?order_id=1&t=invalid` → `{error: "Parameter tidak valid."}` + 400
- `?order_id=0&t=order_ready` → `{error: "Parameter tidak valid."}` + 400
- Order milik tenant lain (kalau bisa test) → 404
- Order dengan telepon NULL/kosong → `{error: "Nomor WA pelanggan tidak valid atau kosong."}` (200, bukan error HTTP)

- [ ] **Step 6: Commit**

```bash
git add api/wa_link.php
git commit -m "feat(wa-link): endpoint /api/wa_link.php returning wa.me url"
```

---

### Task 3: Tombol WA di `pos.php`

**Files:**
- Modify: `pos.php` — di 2 lokasi (modal sukses create order + area struk lunas)

**Interfaces:**
- Consumes: endpoint `/api/wa_link.php` dari Task 2.

- [ ] **Step 1: Locate insertion points**

Cari di pos.php:
1. JS function/handler yang sukses create order → biasanya ada modal sukses atau redirect. Cari string `no_order` atau `order created` atau handler response create.
2. JS function yang render struk lunas → cari `struk-row` atau modal struk.

```bash
grep -n "order created\|order_sukses\|struk-row\|renderStruk\|showSukses" pos.php | head -20
```

Gunakan hasil grep untuk menentukan baris injection.

- [ ] **Step 2: Tambah JS helper `openWaForOrder()` di pos.php**

Cari `<script>` block utama (biasanya di akhir file sebelum `</body>` atau di awal). Tambah function global:

```js
async function openWaForOrder(orderId, template) {
  try {
    const r = await fetch(`/api/wa_link.php?order_id=${orderId}&t=${template}`);
    const j = await r.json();
    if (j.url) {
      window.open(j.url, '_blank', 'noopener');
    } else {
      alert(j.error || 'Gagal generate link WA.');
    }
  } catch (e) {
    alert('Network error: ' + e.message);
  }
}
```

- [ ] **Step 3: Tambah tombol WA setelah create order sukses**

Di modal sukses (atau area yang muncul setelah POST create order berhasil dan ada `no_order` + `order_id`), tambah button samping "Cetak Struk":

```html
<button class="btn btn-success" onclick="openWaForOrder(${data.id}, 'order_diterima')">
  📱 Kirim WA
</button>
```

Gantilah `${data.id}` sesuai variable yang sudah ada di template literal/render function. Kalau pelanggan tidak punya telepon, endpoint akan alert "Nomor WA tidak valid" — itu sudah cukup (tidak perlu disable tombol di klien).

- [ ] **Step 4: Tambah tombol WA Struk di area struk lunas**

Cari area render struk (setelah payment success). Tambah samping tombol "Cetak Struk":

```html
<button class="btn btn-secondary" onclick="openWaForOrder(${currentOrderId}, 'struk_lunas')">
  📱 WA Struk
</button>
```

Sesuaikan `${currentOrderId}` dengan variable order ID yang relevant di context tersebut.

- [ ] **Step 5: Manual smoke test**

1. Buka POS, buat order baru dengan pelanggan ber-telepon valid.
2. Setelah submit sukses, klik "📱 Kirim WA" → tab baru ke WhatsApp Web → body terisi template `order_diterima`.
3. Lanjut ke payment → setelah lunas, klik "📱 WA Struk" → tab baru → body terisi template `struk_lunas` dengan link struk.
4. Test edge: order tanpa telepon → alert error.

- [ ] **Step 6: Commit**

```bash
git add pos.php
git commit -m "feat(wa-link): tombol WA di pos (order baru + struk lunas)"
```

---

### Task 4: Checkbox WA di `orders.php` saat status → selesai

**Files:**
- Modify: `orders.php`

**Interfaces:**
- Consumes: endpoint `/api/wa_link.php`.

- [ ] **Step 1: Locate status update flow**

```bash
grep -n "status_proses\|updateStatus\|set.*selesai\|estimasi_selesai" orders.php | head -20
```

Cari handler JS yang submit perubahan status ke server (POST). Identifikasi modal/UI tempat user pilih status baru.

- [ ] **Step 2: Tambah checkbox di modal update status**

Di modal edit (yang sudah ada untuk update status_proses), tambah checkbox sebelum tombol Submit:

```html
<label class="check-row" id="waCheckRow" style="display:none">
  <input type="checkbox" id="waSendOnReady" checked>
  Kirim WA "siap diambil" ke pelanggan setelah simpan
</label>
```

Logic: tampilkan row ini hanya kalau status baru = `selesai` (atau apapun status yang artinya "siap diambil"). Konfirmasi nama status dari kolom existing — biasanya `selesai` atau `ready`. Cek dengan:

```bash
grep -n "status_proses.*=\|'selesai'\|'ready'" orders.php | head -10
```

JS toggle visibility on status change select:

```js
document.getElementById('edit_status').addEventListener('change', function() {
  const isReady = this.value === 'selesai'; // sesuaikan dengan nama status yang dipakai
  document.getElementById('waCheckRow').style.display = isReady ? 'flex' : 'none';
});
```

- [ ] **Step 3: Hook ke submit handler**

Di handler submit update status (setelah fetch ke endpoint update berhasil), tambah:

```js
// inside the .then(response) of update status fetch
if (response.ok && document.getElementById('edit_status').value === 'selesai') {
  const waSend = document.getElementById('waSendOnReady').checked;
  if (waSend) {
    openWaForOrder(currentEditingOrderId, 'order_ready');
  }
}
```

Pastikan `openWaForOrder()` tersedia — kalau belum ada di orders.php, copy helper-nya dari pos.php (atau pindahkan ke `components.php` sebagai inline JS via `renderGlobalJsHelpers()` kalau mau DRY).

**Pilihan DRY:** pindahkan `openWaForOrder` ke `components.php` `renderGlobalJsHelpers()` agar tersedia di semua page. Lakukan ini di step ini supaya tidak duplicate.

Tambah ke `renderGlobalJsHelpers()` di `components.php`:

```js
window.openWaForOrder = async function(orderId, template) {
  try {
    const r = await fetch(`/api/wa_link.php?order_id=${orderId}&t=${template}`);
    const j = await r.json();
    if (j.url) window.open(j.url, '_blank', 'noopener');
    else alert(j.error || 'Gagal generate link WA.');
  } catch (e) {
    alert('Network error: ' + e.message);
  }
};
```

Lalu hapus duplicate version yang ditambah ke pos.php di Task 3.

- [ ] **Step 4: Manual smoke test**

1. Buka orders.php, klik edit order yang status-nya bukan `selesai`.
2. Ubah status ke `selesai` → checkbox "Kirim WA siap diambil" muncul, default checked.
3. Submit → status ter-update → tab baru ke WhatsApp Web dengan template `order_ready`.
4. Repeat dengan checkbox di-uncheck → tab WA tidak muncul.
5. Edit order ke status selain `selesai` → checkbox tidak muncul.

- [ ] **Step 5: Commit**

```bash
git add orders.php components.php
git commit -m "feat(wa-link): checkbox kirim WA saat status order → selesai + DRY helper"
```

---

## Self-Review Checklist (untuk implementer setelah selesai)

- [ ] `waNormalizePhone()` cover format: `08xxx`, `+62xxx`, `62xxx`, `8xxx`, dengan dash/spasi.
- [ ] `waLink()` return `null` untuk phone invalid atau template tidak dikenal.
- [ ] Endpoint scope ke `tenant_id` + `outlet_id` aktif (jangan global query).
- [ ] Tidak ada hard-coded base URL — pakai `APP_URL`.
- [ ] Audit log ter-record per call endpoint.
- [ ] `openWaForOrder()` hanya didefinisikan **sekali** (di `components.php`), tidak duplicate di pos.php / orders.php.
- [ ] Smoke test di production: kirim WA ke nomor sendiri, verify body benar.

## Out of scope (tidak boleh dikerjakan di plan ini)

- Editor template per outlet.
- Broadcast mass via Fonnte.
- Auto-send tanpa interaksi kasir.
- Migration kolom DB untuk `wa_sent_count` (dihapus dari spec, audit log cukup).

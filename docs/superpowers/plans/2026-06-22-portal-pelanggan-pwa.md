# Portal Pelanggan PWA Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bangun portal pelanggan read-only via QR struk login + PWA shell (install-able, offline-capable).

**Architecture:** ALTER hl_pelanggan dengan kolom `portal_token`, auto-generate saat insert pelanggan. Struk QR encode `/p?t=TOKEN&o=NO_ORDER`. Endpoint `/p` validate token → set session. 2 halaman portal (`/pelanggan` home + `/pelanggan-order?o=...` detail). PWA shell via manifest.json + minimal service worker.

**Tech Stack:** PHP 8.1 + PDO + vanilla JS. Reuse `TenantQuery`, existing track.php template, StrukGenerator QR generator (api.qrserver.com). No new dependencies.

## Global Constraints

- All portal endpoints PUBLIC (no tenant_guard) tapi authenticated via `$_SESSION['portal_pelanggan_id']` (pisah dari session staff).
- Read-only: NO POST actions kecuali regenerate token (CSRF required).
- Rate limit `/p` endpoint: 5 req/menit per IP.
- Token format: 32-char hex via `bin2hex(random_bytes(16))`.
- Token storage: `hl_pelanggan.portal_token VARCHAR(64) UNIQUE NULL`.
- PWA: install-able via beforeinstallprompt, service worker offline fallback.
- mysql binary: `/opt/homebrew/opt/mysql-client/bin/mysql u269895997_harpy_master`.
- Spec: [docs/superpowers/specs/2026-06-22-portal-pelanggan-pwa-design.md](../specs/2026-06-22-portal-pelanggan-pwa-design.md)

## File Structure

- **Create** `superadmin/sql/pelanggan_portal_token_migration.sql` — ALTER + backfill existing pelanggan
- **Modify** `tenant/migrations/tenant_schema.sql` — append portal_token column to hl_pelanggan CREATE TABLE
- **Modify** `pos.php` — auto-generate portal_token saat INSERT hl_pelanggan
- **Modify** `customer.php` — auto-generate portal_token saat INSERT + tombol regenerate token per pelanggan
- **Create** `middleware/pelanggan_guard.php` — guard untuk portal pages (check session, redirect kalau tidak login)
- **Create** `p.php` — login entry endpoint (validate token, set session, redirect)
- **Create** `pelanggan.php` — portal home (saldo, poin, order aktif, riwayat)
- **Create** `pelanggan-order.php` — detail order (reuse track template, auth via session)
- **Create** `assets/manifest.json` — PWA manifest
- **Create** `sw.js` — minimal service worker (cache static assets + offline fallback)
- **Modify** `core/StrukGenerator.php` — update QR URL untuk encode portal URL kalau pelanggan punya token
- **Modify** `.htaccess` — whitelist routes `/p`, `/pelanggan`, `/pelanggan-order`

---

### Task 1: Migration `portal_token` + backfill

**Files:**
- Create: `superadmin/sql/pelanggan_portal_token_migration.sql`
- Modify: `tenant/migrations/tenant_schema.sql` (append portal_token ke hl_pelanggan CREATE TABLE)

**Interfaces:**
- Produces: kolom `hl_pelanggan.portal_token VARCHAR(64) UNIQUE NULL`

- [ ] **Step 1: Buat migration file**

```sql
-- superadmin/sql/pelanggan_portal_token_migration.sql
-- Tambah portal_token untuk auth pelanggan via QR struk

ALTER TABLE hl_pelanggan ADD COLUMN IF NOT EXISTS portal_token VARCHAR(64) UNIQUE NULL AFTER catatan;

-- Backfill existing pelanggan dengan token random
-- Pakai SHA2(UUID + random) untuk uniqueness; tidak pakai bin2hex(random_bytes) karena bukan MySQL native
UPDATE hl_pelanggan
   SET portal_token = SUBSTRING(SHA2(CONCAT(UUID(), RAND(), id), 256), 1, 32)
 WHERE portal_token IS NULL;
```

- [ ] **Step 2: Append ke tenant_schema.sql**

Find hl_pelanggan CREATE TABLE block (line ~73). Tambah kolom setelah `catatan`:

```sql
  catatan       TEXT         DEFAULT NULL,
  portal_token  VARCHAR(64)  DEFAULT NULL UNIQUE,
  saldo_deposit DECIMAL(12,2) DEFAULT 0,
```

- [ ] **Step 3: Apply ke prod DB**

```bash
/opt/homebrew/opt/mysql-client/bin/mysql u269895997_harpy_master < superadmin/sql/pelanggan_portal_token_migration.sql
/opt/homebrew/opt/mysql-client/bin/mysql u269895997_harpy_master -e "SHOW COLUMNS FROM hl_pelanggan LIKE 'portal_token'; SELECT COUNT(*) AS total, COUNT(portal_token) AS with_token FROM hl_pelanggan;"
```

Expected: column ada + `total == with_token` (semua existing punya token).

- [ ] **Step 4: Commit**

```bash
git add superadmin/sql/pelanggan_portal_token_migration.sql tenant/migrations/tenant_schema.sql
git commit -m "feat(portal): migration hl_pelanggan.portal_token + backfill"
```

---

### Task 2: Auto-generate token saat INSERT pelanggan + owner regenerate

**Files:**
- Modify: `pos.php` line ~268 (TenantQuery::insert hl_pelanggan)
- Modify: `customer.php` line ~98 (TenantQuery::insert hl_pelanggan) + tambah regenerate action

**Interfaces:**
- Produces: setiap pelanggan baru dapat portal_token auto-generated; owner bisa regenerate via /customer

- [ ] **Step 1: pos.php auto-gen token**

Locate `TenantQuery::insert('hl_pelanggan', [...])` di pos.php sekitar line 268. Tambah `'portal_token' => bin2hex(random_bytes(16))` ke array:

```php
TenantQuery::insert('hl_pelanggan', [
    'nama'    => $nama_pel,
    'telepon' => $tel,
    // ... existing fields
    'portal_token' => bin2hex(random_bytes(16)),
]);
```

- [ ] **Step 2: customer.php auto-gen token**

Locate `TenantQuery::insert('hl_pelanggan', [...])` di customer.php sekitar line 98. Same addition.

- [ ] **Step 3: customer.php regenerate action**

Tambah action handler baru (cari posisi action handlers, biasanya di top file):

```php
if ($action === 'regen_token' && $_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf();
    requirePermission('pelanggan.edit');
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($d['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['error'=>'Input invalid']); exit; }
    $newToken = bin2hex(random_bytes(16));
    $st = $db->prepare("UPDATE hl_pelanggan SET portal_token=? WHERE id=? AND tenant_id=?");
    $st->execute([$newToken, $id, $tid]);
    if (!$st->rowCount()) { echo json_encode(['error'=>'Tidak ditemukan']); exit; }
    logAudit('portal_token_regen', 'pelanggan', "pelanggan_id=$id");
    echo json_encode(['ok'=>true]);
    exit;
}
```

- [ ] **Step 4: customer.php tombol regenerate di list UI**

Find list rendering pelanggan (sekitar baris yang render card per pelanggan). Tambah tombol kecil:

```html
<button class="hl-btn hl-btn-outline hl-btn-sm" onclick="regenToken(${p.id})" title="Regenerate portal token (struk lama jadi invalid)">🔄</button>
```

JS handler:

```js
async function regenToken(id) {
  if (!confirm('Regenerate portal token? Struk lama dengan token lama tidak akan bisa login lagi.')) return;
  const r = await fetch('?action=regen_token', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({id})
  });
  const d = await r.json();
  if (d.error) { showToast(d.error,'error'); return; }
  showToast('✅ Token regenerated. Cetak struk baru untuk pelanggan ini.', 'success');
}
```

- [ ] **Step 5: Manual smoke test**

1. /pos buat order baru pelanggan baru → cek `hl_pelanggan` row baru punya `portal_token` (32-char hex)
2. /customer buat pelanggan baru → cek token ter-set
3. /customer klik 🔄 → konfirmasi → token DB berubah

- [ ] **Step 6: Commit**

```bash
git add pos.php customer.php
git commit -m "feat(portal): auto-gen portal_token + owner regenerate action"
```

---

### Task 3: Pelanggan guard middleware + `/p` login endpoint

**Files:**
- Create: `middleware/pelanggan_guard.php`
- Create: `p.php`

**Interfaces:**
- Produces:
  - `currentPelanggan(): ?array` — return pelanggan row atau null
  - `requirePelangganLogin(): void` — redirect ke `/p?msg=login` kalau tidak login
  - `$_SESSION['portal_pelanggan_id']` set saat login
  - Endpoint GET `/p?t=TOKEN[&o=NO_ORDER]` → validate + redirect ke `/pelanggan-order?o=NO_ORDER` atau `/pelanggan`

- [ ] **Step 1: Buat middleware/pelanggan_guard.php**

```php
<?php
// middleware/pelanggan_guard.php — Guard untuk portal pelanggan

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure',   1);
ini_set('session.cookie_samesite', 'Strict');
if (session_status() === PHP_SESSION_NONE) session_start();

if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';

// Security headers
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

function currentPelanggan(): ?array {
    static $cached = null;
    if ($cached !== null) return $cached ?: null;
    $id = (int)($_SESSION['portal_pelanggan_id'] ?? 0);
    if ($id <= 0) { $cached = []; return null; }
    try {
        $db = Database::get();
        $st = $db->prepare("SELECT * FROM hl_pelanggan WHERE id=? AND is_active=1 LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $cached = $row ?: [];
        return $row ?: null;
    } catch (Throwable) { $cached = []; return null; }
}

function requirePelangganLogin(): void {
    if (!currentPelanggan()) {
        header('Location: /p?msg=login');
        exit;
    }
}
```

- [ ] **Step 2: Buat p.php (login entry)**

```php
<?php
// p.php — Portal pelanggan login entry via QR token

define('ROOT', __DIR__);
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure',   1);
ini_set('session.cookie_samesite', 'Strict');
if (session_status() === PHP_SESSION_NONE) session_start();

$token = trim($_GET['t'] ?? '');
$nextOrder = trim($_GET['o'] ?? '');
$msg = $_GET['msg'] ?? '';

// Rate limit: 5/menit per IP via session
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateKey = 'p_rate_' . hash('sha256', $ip);
$now = time();
$attempts = $_SESSION[$rateKey] ?? [];
$attempts = array_filter($attempts, fn($t) => $t > $now - 60);
if (count($attempts) >= 5) {
    http_response_code(429);
    die('<!doctype html><meta charset=utf-8><div style="font-family:sans-serif;padding:40px;text-align:center;color:#666"><h2>Terlalu banyak percobaan</h2><p>Tunggu 1 menit lalu coba lagi.</p></div>');
}

$err = '';
if ($token) {
    // Validate token format (32-char hex)
    if (!preg_match('/^[a-f0-9]{32}$/i', $token)) {
        $err = 'Format token tidak valid.';
    } else {
        try {
            $db = Database::get();
            $st = $db->prepare("SELECT id, nama FROM hl_pelanggan WHERE portal_token=? AND is_active=1 LIMIT 1");
            $st->execute([$token]);
            $pel = $st->fetch(PDO::FETCH_ASSOC);
            if ($pel) {
                $_SESSION['portal_pelanggan_id'] = (int)$pel['id'];
                // Audit
                try {
                    $logSt = $db->prepare("INSERT INTO hl_audit_log (tenant_id, user_id, aksi, modul, keterangan, ip_address, created_at) VALUES (NULL, NULL, 'portal_login', 'pelanggan', ?, ?, NOW())");
                    $logSt->execute(["pelanggan_id=" . $pel['id'], $ip]);
                } catch (Throwable) { /* audit table beda schema, ignore */ }

                $redirect = $nextOrder && preg_match('/^[A-Z0-9\-]{3,30}$/i', $nextOrder)
                    ? '/pelanggan-order?o=' . urlencode($nextOrder)
                    : '/pelanggan';
                header('Location: ' . $redirect);
                exit;
            }
            $err = 'Token tidak ditemukan atau pelanggan tidak aktif.';
        } catch (Throwable $e) {
            error_log('[p login] ' . $e->getMessage());
            $err = 'Gagal validasi. Coba lagi.';
        }
    }
    // Record failed attempt
    $attempts[] = $now;
    $_SESSION[$rateKey] = $attempts;
}

?>
<!doctype html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Portal Pelanggan — LAMASY</title>
<style>
body{margin:0;padding:40px 20px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:linear-gradient(135deg,#0F1C3A 0%,#1E3A8A 50%,#312E81 100%);min-height:100vh;color:#fff}
.box{max-width:380px;margin:0 auto;background:#fff;color:#1E293B;border-radius:18px;padding:30px 24px;box-shadow:0 20px 60px rgba(0,0,0,.3);text-align:center}
.brand{font-weight:800;color:#0F1C3A;margin-bottom:6px}
.sub{color:#64748B;font-size:14px;margin-bottom:24px}
.err{background:#FEE2E2;color:#991B1B;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:16px}
.info{background:#EFF6FF;color:#1E40AF;padding:14px;border-radius:8px;font-size:13.5px;line-height:1.5;text-align:left}
</style>
</head>
<body>
<div class="box">
  <h1 class="brand">🧺 LAMASY</h1>
  <div class="sub">Portal Pelanggan</div>
  <?php if ($err): ?>
    <div class="err">❌ <?= htmlspecialchars($err) ?></div>
  <?php elseif ($msg === 'login'): ?>
    <div class="err">Silakan login dulu.</div>
  <?php endif; ?>
  <div class="info">
    📷 <strong>Cara Login:</strong><br>
    Scan QR code yang ada di struk laundry kamu. Otomatis masuk ke akun pelanggan.<br><br>
    Belum punya struk? Kunjungi outlet LAMASY terdekat.
  </div>
</div>
</body>
</html>
```

- [ ] **Step 3: Manual smoke test (post-deploy)**

1. Hit `/p?t=invalid` → tampil form dengan error "Format token tidak valid"
2. Hit `/p?t=00000000000000000000000000000000` (valid format, not in DB) → "Token tidak ditemukan"
3. Hit `/p?t={REAL_TOKEN}` → redirect ke `/pelanggan` + session set
4. Hit `/p?t={REAL_TOKEN}&o=ORD123` → redirect ke `/pelanggan-order?o=ORD123`
5. Brute force 6x → 429

- [ ] **Step 4: Commit**

```bash
git add middleware/pelanggan_guard.php p.php
git commit -m "feat(portal): /p login entry + pelanggan_guard middleware"
```

---

### Task 4: `/pelanggan` portal home

**Files:**
- Create: `pelanggan.php`

**Interfaces:**
- Consumes: `requirePelangganLogin()`, `currentPelanggan()` dari Task 3
- Produces:
  - GET `/pelanggan` → render home dengan sections (saldo, poin, order aktif, riwayat)
  - POST `?action=regen_token` (CSRF) → regenerate own token + logout
  - GET `?action=logout` → clear session

- [ ] **Step 1: Buat pelanggan.php**

```php
<?php
// pelanggan.php — Portal home read-only untuk pelanggan

define('ROOT', __DIR__);
require_once ROOT . '/middleware/pelanggan_guard.php';

requirePelangganLogin();
$pel = currentPelanggan();
$db = Database::get();

// CSRF helper (lokal — pelanggan_guard tidak provide karena PUBLIC)
function pelangganCsrf(): string {
    if (empty($_SESSION['portal_csrf'])) {
        $_SESSION['portal_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['portal_csrf'];
}
function verifyPortalCsrf(): void {
    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['portal_csrf'] ?? '', $token)) {
        http_response_code(403);
        die('CSRF mismatch');
    }
}

$action = $_GET['action'] ?? '';

if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: /p?msg=logout');
    exit;
}

if ($action === 'regen_token' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    verifyPortalCsrf();
    $newToken = bin2hex(random_bytes(16));
    $st = $db->prepare("UPDATE hl_pelanggan SET portal_token=? WHERE id=?");
    $st->execute([$newToken, (int)$pel['id']]);
    // Logout setelah regen
    $_SESSION['portal_pelanggan_id'] = 0;
    echo json_encode(['ok'=>true]);
    exit;
}

// Load data
$saldoDeposit = (float)($pel['saldo_deposit'] ?? 0);

// Loyalty (kalau tabel ada)
$poin = 0; $tier = '';
try {
    $st = $db->prepare("SELECT poin_saldo FROM hl_pelanggan WHERE id=?");
    $st->execute([(int)$pel['id']]);
    $poin = (int)($st->fetchColumn() ?: 0);
} catch (Throwable) {}

// Order aktif (status_proses bukan diambil/selesai)
$activeOrders = [];
try {
    $st = $db->prepare(
        "SELECT no_order, total, status_proses, tanggal, estimasi_selesai,
                (SELECT COUNT(*) FROM hl_transaksi_item WHERE transaksi_id=t.id) AS jml_item
           FROM hl_transaksi t
          WHERE pelanggan_id=? AND status_proses NOT IN ('diambil','selesai','batal')
          ORDER BY t.id DESC LIMIT 20"
    );
    $st->execute([(int)$pel['id']]);
    $activeOrders = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('[pelanggan active] ' . $e->getMessage()); }

// Riwayat (20 terakhir done)
$historyOrders = [];
try {
    $st = $db->prepare(
        "SELECT no_order, total, status_proses, tanggal
           FROM hl_transaksi
          WHERE pelanggan_id=? AND status_proses IN ('diambil','selesai')
          ORDER BY id DESC LIMIT 20"
    );
    $st->execute([(int)$pel['id']]);
    $historyOrders = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {}

$csrf = pelangganCsrf();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0F1C3A">
<meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
<link rel="manifest" href="/assets/manifest.json">
<link rel="apple-touch-icon" href="/assets/logo.png">
<title>Portal — <?= htmlspecialchars($pel['nama']) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#F8FAFC;min-height:100vh;padding:16px;color:#1E293B}
.wrap{max-width:520px;margin:0 auto}
.head{background:linear-gradient(135deg,#0F1C3A,#1E3A8A);color:#fff;border-radius:14px;padding:18px 20px;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center}
.head .name{font-weight:700;font-size:17px}
.head .sub{font-size:12px;opacity:.7;margin-top:2px}
.btn-logout{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.2);padding:6px 12px;border-radius:8px;font-size:12px;text-decoration:none}
.card{background:#fff;border-radius:14px;padding:16px 18px;margin-bottom:12px;box-shadow:0 2px 10px rgba(15,28,58,.06)}
.card h2{font-size:13px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px}
.kv{display:flex;justify-content:space-between;align-items:center;padding:6px 0}
.kv .lbl{color:#64748B;font-size:13.5px}
.kv .val{font-weight:700;font-size:16px}
.order-card{border:1px solid #E2E8F0;border-radius:10px;padding:12px;margin-bottom:8px;cursor:pointer;transition:border .15s}
.order-card:active{border-color:#35E8D5}
.order-card .row1{font-weight:700;font-size:14px}
.order-card .row2{color:#64748B;font-size:12.5px;margin-top:3px}
.pill{display:inline-block;padding:2px 8px;border-radius:100px;font-size:11px;font-weight:600}
.p-cuci{background:#CCFBF1;color:#0F766E}
.p-kering{background:#DBEAFE;color:#1E40AF}
.p-setrika{background:#EDE9FE;color:#5B21B6}
.p-siap{background:#D1FAE5;color:#065F46}
.p-masuk{background:#FEF3C7;color:#92400E}
.regen{margin-top:18px;text-align:center;font-size:12px;color:#64748B}
.regen a{color:#EF4444;text-decoration:underline;cursor:pointer}
.empty{padding:20px;text-align:center;color:#94A3B8;font-size:13.5px}
@media (max-width:480px){body{padding:12px}}
</style>
</head>
<body>
<div class="wrap">
  <div class="head">
    <div>
      <div class="name">👋 <?= htmlspecialchars($pel['nama']) ?></div>
      <div class="sub">Portal Pelanggan LAMASY</div>
    </div>
    <a href="?action=logout" class="btn-logout">Keluar</a>
  </div>

  <div class="card">
    <h2>💰 Saldo & Poin</h2>
    <div class="kv"><span class="lbl">Deposit</span><span class="val">Rp <?= number_format($saldoDeposit, 0, ',', '.') ?></span></div>
    <div class="kv"><span class="lbl">Poin Loyalty</span><span class="val"><?= number_format($poin, 0, ',', '.') ?></span></div>
  </div>

  <div class="card">
    <h2>🧺 Order Aktif (<?= count($activeOrders) ?>)</h2>
    <?php if (empty($activeOrders)): ?>
      <div class="empty">Tidak ada order aktif</div>
    <?php else: foreach ($activeOrders as $o): ?>
      <a href="/pelanggan-order?o=<?= urlencode($o['no_order']) ?>" style="text-decoration:none;color:inherit">
      <div class="order-card">
        <div class="row1">#<?= htmlspecialchars($o['no_order']) ?> · <?= (int)$o['jml_item'] ?> item · Rp <?= number_format((float)$o['total'], 0, ',', '.') ?></div>
        <div class="row2">
          <span class="pill p-<?= htmlspecialchars($o['status_proses']) ?>"><?= htmlspecialchars($o['status_proses']) ?></span>
          <?php if (!empty($o['estimasi_selesai'])): ?> · Est. <?= date('d M', strtotime($o['estimasi_selesai'])) ?><?php endif; ?>
        </div>
      </div>
      </a>
    <?php endforeach; endif; ?>
  </div>

  <div class="card">
    <h2>📋 Riwayat (<?= count($historyOrders) ?> terakhir)</h2>
    <?php if (empty($historyOrders)): ?>
      <div class="empty">Belum ada riwayat</div>
    <?php else: foreach ($historyOrders as $o): ?>
      <a href="/pelanggan-order?o=<?= urlencode($o['no_order']) ?>" style="text-decoration:none;color:inherit">
      <div class="order-card">
        <div class="row1">#<?= htmlspecialchars($o['no_order']) ?> · Rp <?= number_format((float)$o['total'], 0, ',', '.') ?></div>
        <div class="row2"><?= date('d M Y', strtotime($o['tanggal'])) ?> · <?= htmlspecialchars($o['status_proses']) ?></div>
      </div>
      </a>
    <?php endforeach; endif; ?>
  </div>

  <div class="regen">
    Curiga akun bocor? <a onclick="regenToken()">Regenerate token</a>
  </div>
</div>

<script>
const CSRF = '<?= $csrf ?>';
async function regenToken() {
  if (!confirm('Regenerate token akan invalidate semua struk lama. Lanjut?')) return;
  const r = await fetch('?action=regen_token', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}});
  const d = await r.json();
  if (d.ok) {
    alert('✅ Token baru. Untuk login kembali, scan QR struk terbaru dari outlet.');
    window.location = '/p?msg=logout';
  }
}
// Register service worker (PWA)
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/sw.js').catch(e => console.warn('SW fail', e));
}
</script>
</body>
</html>
```

- [ ] **Step 2: Manual smoke test**

1. Login via `/p?t=TOKEN` → masuk `/pelanggan`
2. Sections muncul: nama, saldo, poin, order aktif, riwayat
3. Klik order card → redirect ke `/pelanggan-order?o=NO_ORDER` (404 OK karena belum dibuat, Task 5)
4. Klik logout → session cleared, redirect ke /p
5. Klik regenerate → konfirmasi → token DB berubah, redirect ke /p

- [ ] **Step 3: Commit**

```bash
git add pelanggan.php
git commit -m "feat(portal): /pelanggan portal home (saldo, poin, order aktif, riwayat)"
```

---

### Task 5: `/pelanggan-order` detail page

**Files:**
- Create: `pelanggan-order.php`

**Interfaces:**
- Consumes: `requirePelangganLogin()`, `currentPelanggan()` dari Task 3
- Produces: GET `/pelanggan-order?o=NO_ORDER` → render detail order

- [ ] **Step 1: Buat pelanggan-order.php (reuse track template)**

```php
<?php
// pelanggan-order.php — Detail order untuk pelanggan login

define('ROOT', __DIR__);
require_once ROOT . '/middleware/pelanggan_guard.php';

requirePelangganLogin();
$pel = currentPelanggan();
$db = Database::get();

$noOrder = trim($_GET['o'] ?? '');
if (!$noOrder) { header('Location: /pelanggan'); exit; }

// Validate format
if (!preg_match('/^[A-Z0-9\-\/]{3,30}$/i', $noOrder)) {
    http_response_code(400);
    die('No order tidak valid');
}

// Load order — MUST belong to current pelanggan_id
$st = $db->prepare(
    "SELECT t.*, o.nama_outlet, o.alamat AS outlet_alamat, o.telepon AS outlet_wa
       FROM hl_transaksi t
  LEFT JOIN outlets o ON o.id = t.outlet_id
      WHERE t.no_order=? AND t.pelanggan_id=? LIMIT 1"
);
$st->execute([$noOrder, (int)$pel['id']]);
$order = $st->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    http_response_code(403);
    die('<!doctype html><meta charset=utf-8><div style="font-family:sans-serif;padding:40px;text-align:center"><h2>Tidak ditemukan</h2><p>Order tidak ada atau bukan milik akun Anda.</p><a href="/pelanggan">← Kembali ke portal</a></div>');
}

// Load items
$items = [];
try {
    $st = $db->prepare("SELECT nama_layanan, jumlah, satuan, harga_satuan, subtotal FROM hl_transaksi_item WHERE transaksi_id=? ORDER BY id");
    $st->execute([$order['id']]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable) {}

// Load antar info kalau ada (reuse query dari track.php)
$antar = null;
try {
    $as = $db->prepare("SELECT aj.*, k.nama AS kurir_nama, k.no_hp AS kurir_hp FROM hl_antar_jemput aj LEFT JOIN hl_kurir k ON k.id = aj.kurir_id WHERE aj.transaksi_id=? AND aj.tipe='antar' ORDER BY aj.id DESC LIMIT 1");
    $as->execute([(int)$order['id']]);
    $antar = $as->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable) {}

// Load bukti dari produksi
$bukti = null;
if (in_array(($order['status_proses'] ?? ''), ['diambil','selesai'], true)) {
    try {
        $bs = $db->prepare("SELECT data_json, foto_paths, catatan, created_at FROM hl_proses_input WHERE transaksi_id=? AND stage='diambil' ORDER BY id DESC LIMIT 1");
        $bs->execute([(int)$order['id']]);
        $bukti = $bs->fetch(PDO::FETCH_ASSOC);
        if ($bukti) {
            $bukti['data']  = json_decode($bukti['data_json'] ?: '{}', true) ?: [];
            $bukti['fotos'] = array_filter(array_map('trim', explode(',', $bukti['foto_paths'] ?? '')));
        }
    } catch (Throwable) { $bukti = null; }
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0F1C3A">
<link rel="manifest" href="/assets/manifest.json">
<title>#<?= htmlspecialchars($order['no_order']) ?> — Portal</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#F8FAFC;min-height:100vh;padding:16px;color:#1E293B}
.wrap{max-width:520px;margin:0 auto}
.back{display:inline-block;color:#64748B;text-decoration:none;font-size:13px;margin-bottom:14px}
.card{background:#fff;border-radius:14px;padding:16px 18px;margin-bottom:12px;box-shadow:0 2px 10px rgba(15,28,58,.06)}
.no-order{font-family:monospace;background:#F0FDFB;color:#0F766E;padding:3px 9px;border-radius:7px;display:inline-block;font-size:13px;margin-bottom:8px}
.status-pill{display:inline-block;padding:4px 12px;border-radius:100px;font-size:12.5px;font-weight:600;background:#DBEAFE;color:#1E40AF}
.row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #F1F5F9}
.row:last-child{border-bottom:none}
.row .l{color:#64748B;font-size:13px}
.row .v{font-weight:600;font-size:13.5px}
.total{font-size:18px;font-weight:800;color:#0F766E}
.item{padding:8px 0;border-bottom:1px solid #F1F5F9;font-size:13.5px}
.item:last-child{border-bottom:none}
.item .nm{font-weight:600}
.item .meta{color:#64748B;font-size:12px;margin-top:2px}
.bukti{margin-top:10px;padding:12px;background:#F0FDF4;border:1px solid #BBF7D0;border-radius:10px}
.bukti .lbl{font-size:12px;font-weight:700;color:#166534;margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em}
.bukti img{width:120px;height:120px;object-fit:cover;border-radius:8px;margin-right:6px;margin-bottom:6px;border:1px solid #BBF7D0}
</style>
</head>
<body>
<div class="wrap">
  <a href="/pelanggan" class="back">← Kembali ke Portal</a>

  <div class="card">
    <div class="no-order">#<?= htmlspecialchars($order['no_order']) ?></div>
    <div style="margin-top:6px"><span class="status-pill"><?= htmlspecialchars($order['status_proses']) ?></span></div>
    <div style="margin-top:10px;font-size:13px;color:#64748B"><?= htmlspecialchars($order['nama_outlet'] ?? '') ?> · <?= date('d M Y', strtotime($order['tanggal'])) ?></div>
    <?php if (!empty($order['estimasi_selesai'])): ?>
      <div style="margin-top:4px;font-size:13px;color:#64748B">Estimasi: <?= date('d M Y', strtotime($order['estimasi_selesai'])) ?></div>
    <?php endif; ?>
  </div>

  <?php if ($antar && in_array($antar['status'], ['assigned','menuju','sampai','done'], true)): ?>
  <div class="card" style="border-left:4px solid #1E40AF">
    <div style="font-weight:700;color:#1E40AF;margin-bottom:6px">🛵 Status Antar</div>
    <?php if (!empty($antar['kurir_nama'])): ?>
      <div style="font-size:13.5px">Kurir: <strong><?= htmlspecialchars($antar['kurir_nama']) ?></strong></div>
    <?php endif; ?>
    <div style="font-size:13px;margin-top:4px">Status: <strong><?= htmlspecialchars($antar['status']) ?></strong></div>
  </div>
  <?php endif; ?>

  <div class="card">
    <h3 style="margin:0 0 10px;font-size:14px;color:#0F1C3A">🧺 Item Cucian</h3>
    <?php foreach ($items as $it): ?>
      <div class="item">
        <div class="nm"><?= htmlspecialchars($it['nama_layanan']) ?></div>
        <div class="meta"><?= (float)$it['jumlah'] ?> <?= htmlspecialchars($it['satuan']) ?> × Rp <?= number_format((float)$it['harga_satuan'], 0, ',', '.') ?> = Rp <?= number_format((float)$it['subtotal'], 0, ',', '.') ?></div>
      </div>
    <?php endforeach; ?>
    <div class="row" style="margin-top:8px"><span class="l">Subtotal</span><span class="v">Rp <?= number_format((float)$order['subtotal'], 0, ',', '.') ?></span></div>
    <?php if ((float)$order['diskon'] > 0): ?>
      <div class="row"><span class="l">Diskon</span><span class="v">- Rp <?= number_format((float)$order['diskon'], 0, ',', '.') ?></span></div>
    <?php endif; ?>
    <div class="row"><span class="l">Total</span><span class="total">Rp <?= number_format((float)$order['total'], 0, ',', '.') ?></span></div>
    <div class="row"><span class="l">Status Bayar</span><span class="v"><?= htmlspecialchars($order['status_bayar']) ?></span></div>
  </div>

  <?php if (!empty($bukti['fotos'])): ?>
  <div class="card">
    <div class="bukti">
      <div class="lbl">📸 Bukti <?= ($bukti['data']['jenis'] ?? '') === 'diantarkan' ? 'Antar' : 'Serah Terima' ?></div>
      <div>
        <?php foreach ($bukti['fotos'] as $fp): if (!str_starts_with($fp, 'uploads/foto_proses/')) continue; ?>
          <a href="/<?= htmlspecialchars($fp) ?>" target="_blank">
            <img src="/<?= htmlspecialchars($fp) ?>" alt="Bukti">
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
```

- [ ] **Step 2: Manual smoke test**

1. Login `/p?t=TOKEN` → /pelanggan → klik order → /pelanggan-order?o=ORD123
2. Order detail tampil: items, total, status, bukti antar (kalau ada)
3. Ganti `o=` ke order pelanggan lain → 403
4. Tap "← Kembali" → /pelanggan

- [ ] **Step 3: Commit**

```bash
git add pelanggan-order.php
git commit -m "feat(portal): /pelanggan-order detail (auth via session, owner check)"
```

---

### Task 6: Struk QR update (encode portal URL kalau pelanggan punya token)

**Files:**
- Modify: `core/StrukGenerator.php` (~line 573, 880) + add helper

**Interfaces:**
- Consumes: `hl_pelanggan.portal_token` dari Task 1
- Produces: struk QR encode `/p?t=TOKEN&o=NO_ORDER` untuk pelanggan dengan token; fallback `/track.php?order=NO` untuk walk-in

- [ ] **Step 1: Tambah helper di StrukGenerator**

Cari method `trackingUrl()` di `core/StrukGenerator.php`. Tambah method baru di class:

```php
/**
 * Generate QR URL untuk struk:
 * - Kalau pelanggan punya portal_token → /p?t=TOKEN&o=NO_ORDER (auto-login portal)
 * - Walk-in (no pelanggan_id) → /track.php?order=NO_ORDER (public tracking)
 */
private static function qrUrlForStruk(array $trx): string {
    $base = defined('APP_URL') ? APP_URL : 'https://lamasy.harpy.id';
    $noOrder = urlencode($trx['no_order']);

    // Cek pelanggan token (kalau pelanggan_id ada)
    $pelangganId = (int)($trx['pelanggan_id'] ?? 0);
    if ($pelangganId > 0) {
        try {
            $db = Database::get();
            $st = $db->prepare("SELECT portal_token FROM hl_pelanggan WHERE id=? LIMIT 1");
            $st->execute([$pelangganId]);
            $token = $st->fetchColumn();
            if ($token) {
                return $base . '/p?t=' . urlencode($token) . '&o=' . $noOrder;
            }
        } catch (Throwable) {}
    }

    // Fallback: public tracking
    return $base . '/track.php?order=' . $noOrder;
}
```

- [ ] **Step 2: Ganti URL builder di kedua tempat QR generated**

Locate line ~573 dan ~880. Replace:

```php
// Before:
$trackUrl = self::trackingUrl($trx['no_order']);
$qrSrc = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' . urlencode($trackUrl);

// After:
$qrSrc = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' . urlencode(self::qrUrlForStruk($trx));
```

(Sama untuk line ~880 dengan size 140x140.)

- [ ] **Step 3: Manual smoke test**

1. Generate struk untuk order dengan pelanggan_id → cek QR-nya scan → URL `/p?t=...&o=...`
2. Generate struk untuk walk-in (pelanggan_id NULL) → QR URL `/track.php?order=...`
3. Scan struk pelanggan → auto-login portal

- [ ] **Step 4: Commit**

```bash
git add core/StrukGenerator.php
git commit -m "feat(portal): struk QR encode portal URL kalau pelanggan punya token"
```

---

### Task 7: PWA shell (manifest + service worker + install banner) + htaccess routes

**Files:**
- Create: `assets/manifest.json`
- Create: `sw.js`
- Modify: `.htaccess`

**Interfaces:**
- Produces:
  - PWA install-able: manifest + meta tags
  - Offline fallback via service worker
  - Routes `/p`, `/pelanggan`, `/pelanggan-order` accessible

- [ ] **Step 1: Buat assets/manifest.json**

```json
{
  "name": "LAMASY Pelanggan",
  "short_name": "LAMASY",
  "description": "Portal pelanggan LAMASY — cek status laundry, saldo, dan poin loyalty",
  "start_url": "/pelanggan",
  "scope": "/",
  "display": "standalone",
  "orientation": "portrait",
  "theme_color": "#0F1C3A",
  "background_color": "#F8FAFC",
  "icons": [
    {
      "src": "/assets/logo.png",
      "sizes": "192x192",
      "type": "image/png",
      "purpose": "any maskable"
    },
    {
      "src": "/assets/logo.png",
      "sizes": "512x512",
      "type": "image/png",
      "purpose": "any maskable"
    }
  ]
}
```

- [ ] **Step 2: Buat sw.js (root)**

```js
// sw.js — Service Worker minimal untuk PWA pelanggan
const CACHE = 'lamasy-pelanggan-v1';
const STATIC_ASSETS = [
  '/assets/logo.png',
  '/assets/manifest.json',
];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(STATIC_ASSETS)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  // Static assets: cache-first
  if (STATIC_ASSETS.some(a => url.pathname === a) || url.pathname.startsWith('/assets/')) {
    e.respondWith(
      caches.match(e.request).then(cached => cached || fetch(e.request).then(resp => {
        if (resp.ok) caches.open(CACHE).then(c => c.put(e.request, resp.clone()));
        return resp;
      }))
    );
    return;
  }
  // Portal pages: network-first, fallback offline message
  if (url.pathname === '/pelanggan' || url.pathname.startsWith('/pelanggan-order')) {
    e.respondWith(
      fetch(e.request).catch(() => new Response(
        '<!doctype html><meta charset=utf-8><div style="font-family:sans-serif;padding:40px;text-align:center;color:#666"><h2>📡 Offline</h2><p>Tidak ada koneksi internet.</p><a href="javascript:location.reload()" style="color:#35E8D5">Coba lagi</a></div>',
        { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
      ))
    );
    return;
  }
  // Other: default
});
```

- [ ] **Step 3: htaccess whitelist routes**

Edit `.htaccess`:

Add `p|pelanggan|pelanggan-order` to BOTH rewrite rules:

```
RewriteRule ^(...|antar-jemput|kurir|kurir-master|p|pelanggan|pelanggan-order)\.php$ /$1 [R=301,L]
RewriteRule ^(...|antar-jemput|kurir|kurir-master|p|pelanggan|pelanggan-order)$ $1.php [L,QSA]
```

Also: allow service worker scope. Add header rule kalau perlu:

```
<Files "sw.js">
  Header set Service-Worker-Allowed "/"
</Files>
```

- [ ] **Step 4: Manual smoke test (post-deploy)**

1. Hit /pelanggan (logged-in) → halaman load, manifest linked
2. Chrome DevTools → Application → Service Workers → sw.js registered
3. DevTools → Application → Manifest → "LAMASY Pelanggan" detected
4. Chrome mobile → buka /pelanggan → "Install LAMASY Pelanggan" banner muncul
5. Install → app muncul di home screen, buka → standalone mode
6. Offline test: enable airplane mode → reload /pelanggan → offline fallback page muncul

- [ ] **Step 5: Commit**

```bash
git add assets/manifest.json sw.js .htaccess
git commit -m "feat(portal): PWA shell — manifest + service worker + offline fallback + routes"
```

---

## Self-Review Checklist (untuk implementer)

- [ ] Migration applied + backfill semua existing pelanggan dapat token
- [ ] Tenant_schema.sql updated untuk tenant baru
- [ ] `pos.php` + `customer.php` auto-gen token saat INSERT
- [ ] `customer.php` punya tombol regenerate token per pelanggan
- [ ] `/p?t=TOKEN` validate + set session + redirect
- [ ] Rate limit 5/menit per IP via session
- [ ] `/pelanggan` home render saldo + poin + order aktif + riwayat
- [ ] Logout + regenerate token berfungsi
- [ ] `/pelanggan-order?o=NO_ORDER` validate ownership (pelanggan_id match)
- [ ] Struk QR encode portal URL kalau pelanggan punya token (walk-in fallback ke track URL)
- [ ] manifest.json + sw.js registered, install banner muncul di Chrome mobile
- [ ] Offline fallback HTML muncul saat network drop
- [ ] htaccess routes /p, /pelanggan, /pelanggan-order resolved extensionless

## Out of scope (Phase 2, defer)

- Push notification (web push subscription + server send)
- OTP via WA gateway
- Action capabilities: request pickup, redeem voucher, top-up deposit
- Edit profile pelanggan
- Multi-language
- Service worker advanced caching (stale-while-revalidate, dll)

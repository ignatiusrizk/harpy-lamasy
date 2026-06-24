# Business Affiliate Program Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Affiliate publik daftar → refer laundry baru ke LAMASY via link `?ref=KODE` → komisi flat Rp 100.000 saat referral bayar setup fee (Midtrans verified). Affiliate dashboard + payout manual via SA.

**Architecture:** Auth affiliate terisolasi (session ke-3). 3 tabel (hl_affiliate, hl_affiliate_referral, hl_affiliate_payout). Atribusi di register.php tenant → hl_affiliate_referral. Komisi trigger di PaymentSettler::settleSetupFee (idempotent). Affiliate dashboard ringan + SA panel 3 tab.

**Tech Stack:** PHP 8 vanilla, MariaDB, session auth (BCRYPT), Midtrans webhook (existing PaymentSettler), SA panel pattern (SaPermission + saRenderNav).

## Global Constraints

- **Komisi:** flat Rp 100.000, config `affiliate_commission` di saas_billing_config (BillingConfig::get). Cair 1× saat setup fee paid.
- **Trigger:** `PaymentSettler::settleSetupFee()` — referral=tenant baru, setup fee=pembayaran pertama. Bukan outlet_activation.
- **Auth affiliate:** session `$_SESSION['affiliate_id']` terpisah dari tenant/SA. Password BCRYPT. Email UNIQUE. Signup → status='active' (no email verify).
- **Idempotency:** referral status 'signup'→'activated' sekali; PaymentSettler sudah idempotent (status='active' short-circuit + coin_ledger check).
- **Anti-fraud:** komisi hanya saat pembayaran verified (di settleSetupFee, bukan signup). Affiliate suspended saat trigger → skip komisi.
- **Payout:** manual. Saldo turun saat status='paid' (bukan request). Min payout Rp 50.000. Reject → saldo tetap.
- **1 tenant 1 referrer:** UNIQUE(tenant_id) di hl_affiliate_referral.
- **CSRF:** semua POST. **XSS:** htmlspecialchars semua render.
- **SA:** SaPermission::require (pola existing), saRenderNav untuk sidebar.
- **mysql client:** /opt/homebrew/opt/mysql-client/bin/mysql. No php CLI lokal → smoke via deploy/browser.
- **register.php tenant:** insert tenant → `$tenantId = (int)$db->lastInsertId();` (line ~181), dalam transaksi. Atribusi referral di sini.

---

## File Structure

**New:**
- `db/migrations/2026-06-24-affiliate.sql` — 3 tabel + config
- `core/AffiliateAuth.php` — signup/login/session/guard
- `affiliate/register.php`, `affiliate/login.php`, `affiliate/dashboard.php`, `affiliate/logout.php`
- `superadmin/affiliates.php` — SA 3 tab

**Modified:**
- `register.php` (tenant) — capture ?ref + insert referral
- `core/PaymentSettler.php` — settleSetupFee komisi trigger
- `superadmin/superadmin_components.php` — sidebar link
- `.htaccess` — /affiliate/* route

---

## Task 1: Schema + Config

**Files:**
- Create: `db/migrations/2026-06-24-affiliate.sql`

**Interfaces:**
- Produces: tabel `hl_affiliate`, `hl_affiliate_referral`, `hl_affiliate_payout`; config `affiliate_commission`=100000.

- [ ] **Step 1: Create migration**

Write `db/migrations/2026-06-24-affiliate.sql`:

```sql
-- Business Affiliate Program
CREATE TABLE IF NOT EXISTS hl_affiliate (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nama VARCHAR(100) NOT NULL,
  email VARCHAR(120) NOT NULL UNIQUE,
  telepon VARCHAR(20) NULL,
  password_hash VARCHAR(255) NOT NULL,
  kode VARCHAR(20) NOT NULL UNIQUE,
  rekening_bank VARCHAR(50) NULL,
  rekening_nomor VARCHAR(40) NULL,
  rekening_atas_nama VARCHAR(100) NULL,
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  saldo_komisi BIGINT NOT NULL DEFAULT 0,
  total_dibayar BIGINT NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_kode (kode), INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_affiliate_referral (
  id INT AUTO_INCREMENT PRIMARY KEY,
  affiliate_id INT NOT NULL,
  tenant_id INT NOT NULL,
  status ENUM('signup','activated') NOT NULL DEFAULT 'signup',
  komisi BIGINT NOT NULL DEFAULT 0,
  activated_at DATETIME NULL,
  payment_id INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_tenant (tenant_id),
  INDEX idx_affiliate (affiliate_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_affiliate_payout (
  id INT AUTO_INCREMENT PRIMARY KEY,
  affiliate_id INT NOT NULL,
  jumlah BIGINT NOT NULL,
  status ENUM('requested','paid','rejected') NOT NULL DEFAULT 'requested',
  requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME NULL,
  catatan_sa TEXT NULL,
  INDEX idx_affiliate_status (affiliate_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Config komisi default (saas_billing_config). Verify kolom: key_name + value_text.
INSERT IGNORE INTO saas_billing_config (key_name, value_text)
VALUES ('affiliate_commission', '100000');
```

NOTE: verify kolom saas_billing_config (`key_name`/`value_text`) — grep BillingConfig::get/set untuk nama kolom persis; sesuaikan INSERT.

- [ ] **Step 2: Verify saas_billing_config schema**

```bash
/opt/homebrew/opt/mysql-client/bin/mysql -e "DESC saas_billing_config" 2>&1
grep -nE "key_name|value_text|INSERT INTO saas_billing_config|SELECT.*saas_billing_config" core/BillingConfig.php | head
```
Sesuaikan INSERT config kalau kolom beda.

- [ ] **Step 3: Apply + verify**

```bash
/opt/homebrew/opt/mysql-client/bin/mysql < db/migrations/2026-06-24-affiliate.sql
/opt/homebrew/opt/mysql-client/bin/mysql -e "DESC hl_affiliate; DESC hl_affiliate_referral; DESC hl_affiliate_payout; SELECT value_text FROM saas_billing_config WHERE key_name='affiliate_commission'" 2>&1 | head -40
```
Expected: 3 tabel + config 100000.

- [ ] **Step 4: Commit**

```bash
git add db/migrations/2026-06-24-affiliate.sql
git commit -m "feat(affiliate): schema 3 tabel + config komisi

hl_affiliate (auth + kode + saldo), hl_affiliate_referral (atribusi +
komisi snapshot, UNIQUE tenant), hl_affiliate_payout (request/paid/reject).
Config affiliate_commission=100000 di saas_billing_config."
```

---

## Task 2: AffiliateAuth

**Files:**
- Create: `core/AffiliateAuth.php`

**Interfaces:**
- Consumes: hl_affiliate (Task 1), Database::get()
- Produces:
  - `AffiliateAuth::signup(array $d): array` → `{ok:bool, id?:int, error?:string}`
  - `AffiliateAuth::login(string $email, string $password): array` → `{ok:bool, error?:string}`
  - `AffiliateAuth::current(): ?array` (affiliate row, null kalau belum login)
  - `AffiliateAuth::requireLogin(): array` (guard → redirect /affiliate/login kalau belum, return row)
  - `AffiliateAuth::logout(): void`
  - `AffiliateAuth::generateKode(): string` (private)

- [ ] **Step 1: Create class**

Write `core/AffiliateAuth.php`:

```php
<?php
// core/AffiliateAuth.php — Auth affiliate (session terpisah dari tenant/SA)
require_once __DIR__ . '/Database.php';

class AffiliateAuth
{
    public static function signup(array $d): array
    {
        $nama  = trim($d['nama'] ?? '');
        $email = strtolower(trim($d['email'] ?? ''));
        $telp  = trim($d['telepon'] ?? '');
        $pass  = (string)($d['password'] ?? '');
        if ($nama === '' || $email === '' || $pass === '') return ['ok'=>false,'error'=>'Nama, email, password wajib'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok'=>false,'error'=>'Email tidak valid'];
        if (strlen($pass) < 6) return ['ok'=>false,'error'=>'Password min 6 karakter'];

        $db = Database::get();
        $c = $db->prepare("SELECT id FROM hl_affiliate WHERE email=?");
        $c->execute([$email]);
        if ($c->fetchColumn()) return ['ok'=>false,'error'=>'Email sudah terdaftar'];

        $kode = self::generateKode();
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $db->prepare("INSERT INTO hl_affiliate
            (nama, email, telepon, password_hash, kode, rekening_bank, rekening_nomor, rekening_atas_nama)
            VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$nama, $email, $telp, $hash, $kode,
                      trim($d['rekening_bank'] ?? '') ?: null,
                      trim($d['rekening_nomor'] ?? '') ?: null,
                      trim($d['rekening_atas_nama'] ?? '') ?: null]);
        $id = (int)$db->lastInsertId();
        $_SESSION['affiliate_id'] = $id;   // auto-login
        return ['ok'=>true, 'id'=>$id];
    }

    public static function login(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        $db = Database::get();
        $s = $db->prepare("SELECT id, password_hash, status FROM hl_affiliate WHERE email=?");
        $s->execute([$email]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row || !password_verify($password, $row['password_hash'])) {
            return ['ok'=>false,'error'=>'Email atau password salah'];
        }
        if ($row['status'] === 'suspended') return ['ok'=>false,'error'=>'Akun ditangguhkan'];
        $_SESSION['affiliate_id'] = (int)$row['id'];
        return ['ok'=>true];
    }

    public static function current(): ?array
    {
        $id = (int)($_SESSION['affiliate_id'] ?? 0);
        if (!$id) return null;
        $s = Database::get()->prepare("SELECT * FROM hl_affiliate WHERE id=? AND status='active'");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function requireLogin(): array
    {
        $aff = self::current();
        if (!$aff) { header('Location: /affiliate/login'); exit; }
        return $aff;
    }

    public static function logout(): void
    {
        unset($_SESSION['affiliate_id']);
    }

    private static function generateKode(): string
    {
        $db = Database::get();
        do {
            $kode = 'AFF' . strtoupper(substr(base_convert((string)random_int(100000, PHP_INT_MAX), 10, 36), 0, 6));
            $c = $db->prepare("SELECT 1 FROM hl_affiliate WHERE kode=?");
            $c->execute([$kode]);
        } while ($c->fetchColumn());
        return $kode;
    }
}
```

- [ ] **Step 2: Verify**

```bash
grep -nE "function signup|function login|function current|function requireLogin|function logout|function generateKode" core/AffiliateAuth.php
```
Expected: 6 fungsi.

- [ ] **Step 3: Commit**

```bash
git add core/AffiliateAuth.php
git commit -m "feat(affiliate): AffiliateAuth — signup/login/session/guard

Session terpisah \$_SESSION['affiliate_id']. signup (email unik, BCRYPT,
auto kode AFF+random, auto-login), login (verify + status check),
current/requireLogin guard, logout. Kode collision-checked."
```

---

## Task 3: Affiliate Pages (register/login/dashboard/logout)

**Files:**
- Create: `affiliate/register.php`, `affiliate/login.php`, `affiliate/dashboard.php`, `affiliate/logout.php`

**Interfaces:**
- Consumes: AffiliateAuth (Task 2), hl_affiliate_referral/payout (Task 1)
- Produces: public affiliate UI. dashboard reads referral + komisi, POST request payout.

- [ ] **Step 1: affiliate/logout.php + login.php**

`affiliate/logout.php`:
```php
<?php
define('ROOT', dirname(__DIR__));
require_once ROOT . '/core/Database.php';
session_start();
require_once ROOT . '/core/AffiliateAuth.php';
AffiliateAuth::logout();
header('Location: /affiliate/login');
```

`affiliate/login.php`: session_start, kalau sudah login redirect dashboard. POST: CSRF check (gunakan pola CSRF sederhana — token di session), AffiliateAuth::login → redirect dashboard. Render form email+password + link ke register. Standalone HTML (tidak pakai tenant/SA layout — ini publik). Sertakan link "?ref tetap" tidak relevan di login.

NOTE: untuk CSRF di halaman publik affiliate, pakai pola token session sederhana: generate `$_SESSION['aff_csrf']` = bin2hex(random_bytes(16)), hidden field, verify saat POST. (Tenant pakai getCsrfToken/verifyCsrf dari middleware — tapi affiliate tidak load middleware itu. Implementer: bikin helper inline kecil atau reuse core/Csrf jika ada standalone. Verify core/ untuk CSRF helper yang tidak butuh tenant session.)

- [ ] **Step 2: affiliate/register.php**

session_start; kalau sudah login → dashboard. POST: CSRF + AffiliateAuth::signup($_POST) → kalau ok redirect dashboard, else tampil error. Form: nama, email, telepon, password, rekening_bank, rekening_nomor, rekening_atas_nama. Standalone HTML. Link ke login.

- [ ] **Step 3: affiliate/dashboard.php**

session_start; `$aff = AffiliateAuth::requireLogin();`. Tampilkan:
- Link referral: `https://{host}/register?ref={$aff['kode']}` + copy button
- Stats: total referral, jumlah activated, saldo_komisi
- Komisi: earned (saldo + total_dibayar), total_dibayar
- Tabel referral: JOIN tenants (nama_perusahaan), status, komisi, created_at
- Request Payout button (POST) — muncul kalau saldo_komisi ≥ 50000 dan tidak ada payout status='requested'

POST action `request_payout`:
```php
// CSRF check
$aff = AffiliateAuth::requireLogin();
$db = Database::get();
// cek pending
$p = $db->prepare("SELECT 1 FROM hl_affiliate_payout WHERE affiliate_id=? AND status='requested'");
$p->execute([$aff['id']]);
if ($p->fetchColumn()) { $err='Sudah ada permintaan payout pending'; }
elseif ((int)$aff['saldo_komisi'] < 50000) { $err='Saldo minimum payout Rp 50.000'; }
else {
    $db->prepare("INSERT INTO hl_affiliate_payout (affiliate_id, jumlah, status) VALUES (?,?, 'requested')")
       ->execute([$aff['id'], (int)$aff['saldo_komisi']]);
    $msg = 'Permintaan payout terkirim. Tim LAMASY akan proses transfer.';
}
```

Query referral:
```php
$r = $db->prepare("SELECT r.status, r.komisi, r.created_at, r.activated_at, t.nama_perusahaan
                   FROM hl_affiliate_referral r
                   LEFT JOIN tenants t ON t.id=r.tenant_id
                   WHERE r.affiliate_id=? ORDER BY r.created_at DESC");
$r->execute([$aff['id']]);
```
htmlspecialchars semua. Standalone HTML + simple CSS.

- [ ] **Step 4: Smoke (grep + curl)**

```bash
grep -nE "AffiliateAuth::|request_payout|requireLogin" affiliate/*.php
curl -s -o /dev/null -w "register %{http_code} login %{http_code}\n" "https://lamasy.harpy.id/affiliate/register"
```
(Route belum jalan sampai Task 7 .htaccess — curl bisa 404, browser test nanti.)

- [ ] **Step 5: Commit**

```bash
git add affiliate/
git commit -m "feat(affiliate): pages register/login/dashboard/logout

Public affiliate UI standalone (no tenant/SA layout). Signup auto-login,
dashboard: link referral + stats + tabel referral + request payout
(min 50rb, no double pending). CSRF token session. htmlspecialchars."
```

---

## Task 4: Atribusi Referral (register.php tenant)

**Files:**
- Modify: `register.php` (tenant signup, sekitar line 181 setelah $tenantId)

**Interfaces:**
- Consumes: hl_affiliate (kode lookup), hl_affiliate_referral (Task 1)
- Produces: hl_affiliate_referral row 'signup' saat tenant daftar pakai ?ref valid

- [ ] **Step 1: Tangkap ?ref di form render**

Di register.php, bagian render form (GET), tangkap `$_GET['ref']` → simpan ke hidden field supaya ikut submit:
```php
$refKode = preg_replace('/[^A-Z0-9]/', '', strtoupper($_GET['ref'] ?? ''));
```
Di form HTML tambah: `<input type="hidden" name="ref" value="<?= htmlspecialchars($refKode) ?>">`

- [ ] **Step 2: Insert referral saat provision (dalam transaksi existing)**

Di register.php, setelah `$tenantId = (int)$db->lastInsertId();` (line ~181) dan SEBELUM `$db->commit()`, tambah:
```php
// Atribusi affiliate referral (kalau daftar via ?ref)
$refKode = preg_replace('/[^A-Z0-9]/', '', strtoupper($_POST['ref'] ?? ''));
if ($refKode !== '') {
    $aff = $db->prepare("SELECT id FROM hl_affiliate WHERE kode=? AND status='active'");
    $aff->execute([$refKode]);
    $affId = (int)$aff->fetchColumn();
    if ($affId) {
        // self-referral guard: skip kalau email affiliate == email tenant
        $selfChk = $db->prepare("SELECT 1 FROM hl_affiliate WHERE id=? AND email=?");
        $selfChk->execute([$affId, $d['email']]);
        if (!$selfChk->fetchColumn()) {
            $db->prepare("INSERT IGNORE INTO hl_affiliate_referral (affiliate_id, tenant_id, status)
                          VALUES (?, ?, 'signup')")
               ->execute([$affId, $tenantId]);
        }
    }
}
```
(`$d['email']` = email tenant; verify nama var di register.php. INSERT IGNORE → UNIQUE tenant_id aman.)

- [ ] **Step 3: Verify**

```bash
grep -nE "ref|hl_affiliate_referral|refKode" register.php | head
```
Expected: hidden field + insert referral.

- [ ] **Step 4: Commit**

```bash
git add register.php
git commit -m "feat(affiliate): atribusi referral di register tenant

Tangkap ?ref=KODE → hidden field → saat provision, lookup affiliate active
+ self-referral guard (email) → INSERT IGNORE hl_affiliate_referral 'signup'.
Dalam transaksi registrasi existing. Kode invalid/kosong → registrasi jalan."
```

---

## Task 5: Komisi Trigger (PaymentSettler::settleSetupFee)

**Files:**
- Modify: `core/PaymentSettler.php` (settleSetupFee, dalam transaksi setelah UPDATE status='active')

**Interfaces:**
- Consumes: hl_affiliate_referral, hl_affiliate, BillingConfig::get
- Produces: komisi dicairkan ke affiliate saat referral aktivasi (idempotent)

- [ ] **Step 1: Tambah komisi block di settleSetupFee**

Di `core/PaymentSettler.php::settleSetupFee()`, SETELAH `UPDATE tenants SET status='active'...` dan SEBELUM `$db->commit()`, tambah:
```php
            // ── Komisi affiliate (kalau tenant ini hasil referral) ──
            $refSt = $db->prepare("SELECT id, affiliate_id, status FROM hl_affiliate_referral
                                   WHERE tenant_id=? LIMIT 1");
            $refSt->execute([$payment['tenant_id']]);
            $ref = $refSt->fetch(PDO::FETCH_ASSOC);
            if ($ref && $ref['status'] === 'signup') {
                // affiliate harus active
                $affSt = $db->prepare("SELECT id FROM hl_affiliate WHERE id=? AND status='active'");
                $affSt->execute([$ref['affiliate_id']]);
                if ($affSt->fetchColumn()) {
                    $komisi = (int) BillingConfig::get('affiliate_commission', 100000);
                    $db->prepare("UPDATE hl_affiliate_referral
                                  SET status='activated', komisi=?, activated_at=NOW(), payment_id=?
                                  WHERE id=? AND status='signup'")
                       ->execute([$komisi, $payment['id'], $ref['id']]);
                    $db->prepare("UPDATE hl_affiliate SET saldo_komisi = saldo_komisi + ? WHERE id=?")
                       ->execute([$komisi, $ref['affiliate_id']]);
                }
            }
```

NOTE: verify `BillingConfig` sudah di-require di PaymentSettler (kalau belum, `require_once`). Verify `$payment['tenant_id']` + `$payment['id']` tersedia (confirmed dari struktur existing).

- [ ] **Step 2: Verify**

```bash
grep -nE "hl_affiliate_referral|affiliate_commission|saldo_komisi" core/PaymentSettler.php
```
Expected: komisi block ada.

- [ ] **Step 3: Commit**

```bash
git add core/PaymentSettler.php
git commit -m "feat(affiliate): komisi trigger di settleSetupFee

Saat referral bayar setup fee (Midtrans verified): kalau tenant punya
referral 'signup' + affiliate active → komisi flat (config 100rb) →
referral 'activated' (snapshot) + saldo_komisi affiliate naik. Idempotent
(status signup→activated sekali, dalam transaksi settle existing)."
```

---

## Task 6: SA Panel (superadmin/affiliates.php)

**Files:**
- Create: `superadmin/affiliates.php`
- Modify: `superadmin/superadmin_components.php` (sidebar link)

**Interfaces:**
- Consumes: hl_affiliate, hl_affiliate_referral, hl_affiliate_payout, BillingConfig, SaPermission
- Produces: SA management 3 tab + payout approve + rate config

- [ ] **Step 1: Baca pola SA page**

```bash
sed -n '1,40p' superadmin/clients.php
grep -n "saRenderNav\|saRenderHead\|SaPermission::require\|saCsrf\|saVerifyCsrf\|getCsrfToken" superadmin/clients.php | head
```
Catat: guard (SaPermission), CSRF helper SA, render head/nav, AJAX pattern. IKUTI persis.

- [ ] **Step 2: Create affiliates.php — guard + AJAX actions**

`superadmin/affiliates.php`:
- Guard: `SaPermission::require('clients.view')` (reuse — affiliate = growth/CS, atau bikin perm baru `affiliate.manage` kalau pola SA permission mendukung; verify SaPermission. Default reuse 'clients.view' untuk read, 'billing.topup'-level untuk payout action).
- AJAX actions (pola SA existing):
  - `list_affiliate` — list + referral count + saldo
  - `toggle_affiliate` — suspend/activate
  - `list_referral` — semua referral (JOIN tenant + affiliate)
  - `list_payout` — payout requests
  - `mark_paid` — UPDATE payout paid + saldo turun + total_dibayar naik (transaksional)
  - `reject_payout` — UPDATE rejected + catatan
  - `set_commission` — update config affiliate_commission

mark_paid transaksional:
```php
// SaPermission::require(...) + CSRF
$id = (int)$_POST['id'];
$db->beginTransaction();
try {
  $p = $db->prepare("SELECT affiliate_id, jumlah, status FROM hl_affiliate_payout WHERE id=? FOR UPDATE");
  $p->execute([$id]); $po = $p->fetch(PDO::FETCH_ASSOC);
  if (!$po || $po['status'] !== 'requested') throw new RuntimeException('Payout tidak valid');
  $db->prepare("UPDATE hl_affiliate_payout SET status='paid', paid_at=NOW(), catatan_sa=? WHERE id=?")
     ->execute([$catatan, $id]);
  $db->prepare("UPDATE hl_affiliate SET saldo_komisi = GREATEST(0, saldo_komisi - ?), total_dibayar = total_dibayar + ? WHERE id=?")
     ->execute([(int)$po['jumlah'], (int)$po['jumlah'], (int)$po['affiliate_id']]);
  $db->commit();
} catch (Throwable $e) { $db->rollBack(); /* error json */ }
```

- [ ] **Step 3: HTML 3 tab + JS**

Render head/nav (saRenderHead/saRenderNav 'affiliates'). 3 tab (Affiliate/Referral/Payout) + rate config input di header. JS fetch list per tab + actions. IKUTI pola SA page existing (clients.php). htmlspecialchars/esc semua.

- [ ] **Step 4: Sidebar link**

Di `superadmin/superadmin_components.php` saRenderNav, group CS & Growth (dekat clients/registrasi), tambah:
```php
        <a href="/superadmin/affiliates.php" class="sa-nav-link <?= $activePage === 'affiliates' ? 'active' : '' ?>">
          <span class="icon">🤝</span> Affiliate
        </a>
```

- [ ] **Step 5: Verify**

```bash
grep -nE "list_affiliate|mark_paid|reject_payout|set_commission" superadmin/affiliates.php
grep -n "affiliates.php" superadmin/superadmin_components.php
```

- [ ] **Step 6: Commit**

```bash
git add superadmin/affiliates.php superadmin/superadmin_components.php
git commit -m "feat(affiliate): SA panel 3 tab + payout + rate config

superadmin/affiliates.php: tab Affiliate (list+suspend), Referral (semua),
Payout (mark_paid transaksional saldo turun / reject + catatan). Set
affiliate_commission. Guard SaPermission + CSRF. Sidebar CS & Growth link."
```

---

## Task 7: Routing + E2E + Deploy

**Files:**
- Modify: `.htaccess`

- [ ] **Step 1: .htaccess route /affiliate/***

affiliate/ pakai subfolder + .php. Cek apakah butuh clean URL. Tambah rule clean URL untuk affiliate pages:
```
grep -n "affiliate\|^hq/(dashboard" .htaccess
```
Tambah (kalau mau clean URL /affiliate/dashboard → affiliate/dashboard.php):
```apache
RewriteCond %{REQUEST_METHOD} GET
RewriteRule ^affiliate/(register|login|dashboard|logout)\.php$ /affiliate/$1 [R=301,L]
RewriteRule ^affiliate/(register|login|dashboard|logout)$ affiliate/$1.php [L,QSA]
```
(Letakkan di area clean-URL existing.)

- [ ] **Step 2: Commit + push**

```bash
git add .htaccess
git commit -m "feat(affiliate): .htaccess route /affiliate/*"
git push origin main
```

- [ ] **Step 3: Apply migration ke production**

```bash
/opt/homebrew/opt/mysql-client/bin/mysql < db/migrations/2026-06-24-affiliate.sql
/opt/homebrew/opt/mysql-client/bin/mysql -e "DESC hl_affiliate; SELECT value_text FROM saas_billing_config WHERE key_name='affiliate_commission'" 2>&1 | tail -5
```

- [ ] **Step 4: HTTP smoke**

```bash
curl -s -o /dev/null -w "register %{http_code}\n" "https://lamasy.harpy.id/affiliate/register"
curl -s -o /dev/null -w "dashboard %{http_code}\n" "https://lamasy.harpy.id/affiliate/dashboard"
```
Expected: register 200 (publik), dashboard 302 (redirect login).

- [ ] **Step 5: Browser E2E**

| # | Action | Expected |
|---|--------|----------|
| 1 | /affiliate/register → daftar | Auto-login → dashboard, kode+link muncul |
| 2 | Logout → login | Works |
| 3 | /register?ref={kode} → daftar tenant baru | hl_affiliate_referral 'signup' |
| 4 | Simulasi setup fee paid (atau settleSetupFee manual) | referral 'activated', komisi 100rb, saldo affiliate +100rb |
| 5 | Dashboard → saldo 100rb, referral ✓ Aktivasi | Tampil |
| 6 | Request payout | hl_affiliate_payout 'requested' |
| 7 | SA /superadmin/affiliates.php → Payout → Tandai Dibayar | saldo turun, total_dibayar naik, paid |
| 8 | SA suspend affiliate → /register?ref same | Tidak atribusi |

- [ ] **Step 6: DB cross-check**

```bash
/opt/homebrew/opt/mysql-client/bin/mysql -e "
SELECT a.nama, a.kode, a.saldo_komisi, a.total_dibayar,
  (SELECT COUNT(*) FROM hl_affiliate_referral r WHERE r.affiliate_id=a.id) refs
FROM hl_affiliate a LIMIT 5;" 2>&1
```

- [ ] **Step 7: Update ledger**

```bash
cat >> .superpowers/sdd/progress.md <<'EOF'

Affiliate Program COMPLETE 2026-06-24 WIB.
Final state: <base>..<head>
EOF
```

---

## Self-Review Checklist

### Spec Coverage
- ✅ §3.2 schema → Task 1
- ✅ §4 AffiliateAuth → Task 2
- ✅ §5.1 dashboard + §5.3 register/login → Task 3
- ✅ §3.3 A atribusi → Task 4
- ✅ §3.3 B komisi trigger → Task 5
- ✅ §3.3 C payout + §5.2 SA panel → Task 3 (request) + Task 6 (approve)
- ✅ §6 security → distributed (CSRF, BCRYPT, idempotent, anti-fraud)
- ✅ §7 edge cases → Task 4 (self-ref, invalid), Task 5 (suspended skip, idempotent), Task 3 (double payout)
- ✅ §8 testing → Task 7

### Placeholder Scan
✓ Code lengkap tiap step. Beberapa step (Task 3 HTML, Task 6 HTML) mengarahkan ikut pola existing — acceptable karena layout publik/SA bervariasi, implementer baca sibling. AJAX action bodies + transaksi diberikan eksplisit.

### Type/Name Consistency
- ✅ AffiliateAuth methods (signup/login/current/requireLogin/logout) — Task 2 def, Task 3 use
- ✅ Tabel + kolom konsisten Task 1 → 4/5/6
- ✅ Komisi config key `affiliate_commission` — Task 1 seed, Task 5 read, Task 6 set
- ✅ Referral status 'signup'/'activated' konsisten Task 4 (insert signup) → Task 5 (→activated)
- ✅ Payout status requested/paid/rejected konsisten Task 3 (request) → Task 6 (mark/reject)
- ✅ saldo turun saat paid (Task 6), naik saat komisi (Task 5)

### Notes (risiko di-flag — verify saat implementasi)
- Task 1: kolom saas_billing_config (key_name/value_text) — verify via BillingConfig.
- Task 3: CSRF helper untuk halaman publik affiliate (tidak load tenant middleware) — implementer cek core/Csrf standalone atau bikin token session inline.
- Task 4: nama var email tenant di register.php (`$d['email']`) — verify.
- Task 5: BillingConfig require di PaymentSettler — verify/add.
- Task 6: SA permission key (reuse clients.view atau perm baru) + CSRF helper SA (saCsrf/saVerifyCsrf) — verify pola clients.php.
- Task 7: clean URL affiliate opsional — kalau ribet, akses via .php langsung tetap jalan.

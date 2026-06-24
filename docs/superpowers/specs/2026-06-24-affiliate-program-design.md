# Business Affiliate Program — Design Spec

**Tanggal:** 24 Juni 2026
**Author:** Ignatius Rizky
**Status:** Draft → Awaiting user review

---

## 1. Tujuan

Program affiliate platform-level: siapa saja (publik) bisa daftar jadi affiliate, dapat kode/link referral, ajak bisnis laundry baru daftar LAMASY. Saat referral bayar aktivasi (setup fee), affiliate dapat **komisi flat Rp 100.000**. Tujuan: akuisisi tenant baru via channel afiliasi. Gap vs Smartlink ("Afiliasi").

**Scope:**
- Public affiliate signup + login (auth terpisah dari tenant/SA)
- Kode referral unik + link `?ref=KODE`
- Atribusi: referral tracked saat tenant baru daftar pakai kode
- Komisi flat Rp 100.000 cair 1× saat referral bayar setup fee (Midtrans verified)
- Affiliate dashboard ringan: lihat referral + komisi + request payout
- Payout manual: SA approve + transfer di luar sistem + tandai paid
- SA panel: kelola affiliate, referral, payout, set rate komisi

**Out of scope (Phase 1):**
- Payout otomatis (disbursement gateway) — manual
- Recurring commission — flat 1× saja
- Tier/level affiliate, multi-level (MLM)
- Klik/analytics tracking — cuma signup attribution
- Email verifikasi affiliate — langsung active, SA bisa suspend

---

## 2. Background

**Existing relevan:**
- `register.php` (tenant signup) → `TenantProvisioner` (line 184). Titik atribusi referral.
- `core/PaymentSettler.php::settleSetupFee()` (line 97) — settle saat tenant baru bayar setup fee via Midtrans. Idempotent (status='paid' short-circuit). Titik trigger komisi.
- `BillingConfig` (saas_billing_config) — config platform; tempat simpan `affiliate_commission`.
- SuperAdmin panel + `SaPermission::require` — pola SA page.
- Tenant auth + SA auth terpisah; affiliate butuh auth ke-3 (terisolasi).

**Greenfield:** tidak ada affiliate/referral existing (verified). Semua baru.

**Trigger komisi = setup_fee (bukan outlet_activation):** referral = tenant BARU. Pembayaran pertama mereka = setup fee (aktivasi tenant). `settleSetupFee` adalah titik yang benar. `outlet_activation` (outlet ke-2+) tidak relevan untuk komisi referral.

---

## 3. Arsitektur

### 3.1 Komponen

**New:**
```
db/migrations/2026-06-24-affiliate.sql       3 tabel + config seed (+ tenants kolom opsional)
core/AffiliateAuth.php                       signup/login/session/guard affiliate
affiliate/register.php                       signup affiliate publik
affiliate/login.php                          login affiliate
affiliate/dashboard.php                      dashboard (referral + komisi + payout)
affiliate/logout.php
superadmin/affiliates.php                    SA: 3 tab (affiliate/referral/payout) + rate config
```

**Modified:**
```
register.php (tenant)            tangkap ?ref=KODE → simpan saat provision
core/TenantProvisioner.php       hook insert hl_affiliate_referral kalau ada ref valid
core/PaymentSettler.php          settleSetupFee: trigger komisi
.htaccess                        route /affiliate/* (clean URL)
superadmin sidebar nav           link Affiliate (group CS & Growth)
```

### 3.2 Schema (3 tabel)

```sql
CREATE TABLE hl_affiliate (
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

CREATE TABLE hl_affiliate_referral (
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

CREATE TABLE hl_affiliate_payout (
  id INT AUTO_INCREMENT PRIMARY KEY,
  affiliate_id INT NOT NULL,
  jumlah BIGINT NOT NULL,
  status ENUM('requested','paid','rejected') NOT NULL DEFAULT 'requested',
  requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME NULL,
  catatan_sa TEXT NULL,
  INDEX idx_affiliate_status (affiliate_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Config seed di `saas_billing_config`: `affiliate_commission` = `100000` (SA bisa atur). Min payout: konstanta `50000` (hardcode atau config).

Optional: `ALTER tenants ADD COLUMN referred_by_affiliate INT NULL` (cepat lookup; source of truth tetap hl_affiliate_referral).

### 3.3 Data Flow

```
A. ATRIBUSI
  Affiliate share: /register?ref=AFF7X9K2
    → register.php simpan kode (hidden/cookie survive multi-step)
    → submit → TenantProvisioner provision tenant
    → lookup hl_affiliate by kode (status='active')
    → valid: INSERT hl_affiliate_referral (affiliate_id, tenant_id, status='signup')
    → invalid/kosong: skip atribusi, registrasi tetap jalan

B. KOMISI (saat referral bayar setup fee)
  Midtrans webhook → PaymentSettler::settleSetupFee (idempotent)
    → cek hl_affiliate_referral WHERE tenant_id=? AND status='signup'
    → ada + affiliate active (transaksional):
        komisi = BillingConfig::get('affiliate_commission', 100000)
        UPDATE referral SET status='activated', komisi=?, activated_at=NOW(), payment_id=?
        UPDATE hl_affiliate SET saldo_komisi += komisi
    → idempotent: skip kalau status sudah 'activated'

C. PAYOUT
  Affiliate dashboard → Request Payout (saldo ≥ 50000, no pending request)
    → INSERT hl_affiliate_payout (jumlah=saldo_komisi, status='requested')
  SA affiliates.php tab Payout → transfer manual → "Tandai Dibayar" + catatan
    → UPDATE payout status='paid', paid_at; UPDATE affiliate saldo_komisi -= jumlah, total_dibayar += jumlah
    → atau "Reject" + alasan → status='rejected' (saldo tetap, tidak turun)
```

---

## 4. Auth (AffiliateAuth)

`core/AffiliateAuth.php` — session namespace `$_SESSION['affiliate_id']` (isolasi dari tenant/SA).

```php
class AffiliateAuth {
    public static function signup(array $d): array;   // validasi+insert+kode+auto-login → {ok,id,error}
    public static function login(string $email, string $password): array; // {ok,error}
    public static function current(): ?array;          // affiliate row dari session, null kalau belum login
    public static function require(): array;           // guard: redirect /affiliate/login kalau belum
    public static function logout(): void;
    private static function generateKode(): string;    // "AFF" + base36 random, collision check
}
```

- Password BCRYPT. Email unik (cek sebelum insert).
- Signup → status='active' langsung (no email verify); SA bisa suspend.
- Guard di dashboard.php: `AffiliateAuth::require()`.

---

## 5. UI Spec

### 5.1 Affiliate Dashboard

```
🤝 Dashboard Affiliate — {nama}                    [Logout]
Link referral: https://lamasy.harpy.id/register?ref={kode}   [📋 Copy]
[Total Referral: N] [Aktivasi: N] [Saldo: Rp X]
Komisi: Earned X · Dibayar X            [💸 Request Payout]
Referral saya:
  Laundry        Status        Komisi    Tanggal
  Bersih Jaya    ✓ Aktivasi    100.000   05 Jun
  Wangi          Signup        −          08 Jun
```
- Request Payout: muncul kalau saldo ≥ 50.000 & tidak ada payout 'requested'.
- Tampil nama laundry (nama_perusahaan tenant) — bukan data sensitif.

### 5.2 SA Panel (superadmin/affiliates.php) — 3 tab

- **Affiliate**: list (nama/email/kode/jumlah referral/saldo/status) + suspend/aktifkan
- **Referral**: semua referral (tenant, affiliate, status, komisi, tanggal)
- **Payout**: request 'requested' → [Tandai Dibayar + catatan/bukti] / [Reject + alasan]; header: setting `affiliate_commission` (Rp)
- Akses: `SaPermission::require` (pola SA existing). Sidebar SA group CS & Growth → "🤝 Affiliate".

### 5.3 Affiliate Register/Login
- register.php: nama, email, telepon, password, rekening (bank/nomor/atas nama). Submit → auto-login → dashboard.
- login.php: email + password.

---

## 6. Security

| Aspek | Handling |
|-------|----------|
| Auth terpisah | Session affiliate isolasi; no akses tenant/SA data |
| Komisi anti-fraud | Cair HANYA saat setup fee paid (Midtrans verified), bukan saat signup |
| Self-referral | Best-effort: warning kalau email affiliate == email tenant didaftarkan |
| Idempotency | Referral status signup→activated sekali; PaymentSettler idempotent |
| Payout | SA-approved; saldo turun saat 'paid' (bukan request); reject kembalikan klaim |
| Kode referral | Random + collision check |
| CSRF | Semua POST (signup/login/payout/SA) |
| Rate limit | affiliate/register + login (anti spam akun) |
| Password | BCRYPT |
| XSS | htmlspecialchars semua render (nama, email, laundry) |

---

## 7. Edge Cases

| Skenario | Handler |
|----------|---------|
| ref kode invalid/suspended | Registrasi jalan tanpa atribusi |
| ref kosong | Normal signup |
| Tenant sudah punya referrer (UNIQUE) | INSERT IGNORE / skip — 1 tenant 1 affiliate |
| Referral signup tapi tak bayar | status 'signup', komisi 0 |
| Setup fee dibayar 2× (webhook retry) | Idempotent: komisi sekali (status check) |
| Affiliate suspended saat referral aktivasi | Komisi tetap dicatat? → Recommend: skip komisi kalau affiliate non-active saat trigger |
| Request payout 2× | Cek ada 'requested' pending → tolak |
| Payout di-reject | status='rejected', saldo tidak turun (tetap bisa request lagi) |
| Self-referral (affiliate daftar sendiri sbg tenant) | Warning, tidak hard-block (deteksi by email) |
| Email affiliate duplikat | UNIQUE → error "email terdaftar" |

---

## 8. Testing Plan

### 8.1 Smoke Test
1. Migration → 3 tabel + config affiliate_commission=100000
2. /affiliate/register → daftar affiliate → auto-login → dashboard, kode + link muncul
3. /affiliate/login → logout → login lagi works
4. Buka /register?ref={kode} → daftar tenant baru → hl_affiliate_referral row 'signup'
5. Simulasi referral bayar setup fee (atau manual settleSetupFee) → referral 'activated', komisi 100000, affiliate saldo_komisi +100000
6. Dashboard affiliate → saldo Rp 100.000, referral tampil ✓ Aktivasi
7. Request payout → hl_affiliate_payout 'requested'
8. SA affiliates.php → tab Payout → Tandai Dibayar → saldo turun, total_dibayar naik, status 'paid'
9. SA suspend affiliate → kode tidak atribusi referral baru

### 8.2 Edge Cases
| # | Test | Expected |
|---|------|----------|
| 1 | ref invalid | Tenant daftar tanpa atribusi |
| 2 | Setup fee webhook 2× | Komisi sekali |
| 3 | Payout request 2× | Tolak (pending ada) |
| 4 | Payout reject | Saldo tetap |
| 5 | Email affiliate duplikat | Error |
| 6 | Affiliate suspended saat aktivasi | Komisi di-skip |

---

## 9. Implementation Phasing

7 commits, ~5.5 jam:
1. Schema 3 tabel + config seed + (opsional kolom tenants)
2. AffiliateAuth (signup/login/session/guard)
3. affiliate/ pages (register/login/dashboard/logout)
4. Atribusi referral (register.php tenant + TenantProvisioner hook)
5. Komisi trigger (PaymentSettler::settleSetupFee)
6. SA affiliates.php (3 tab + payout + rate config) + sidebar
7. E2E + deploy + .htaccess route

---

## 10. Files Inventory

### New
- db/migrations/2026-06-24-affiliate.sql
- core/AffiliateAuth.php
- affiliate/register.php, login.php, dashboard.php, logout.php
- superadmin/affiliates.php

### Modified
- register.php (tenant) — ref capture
- core/TenantProvisioner.php — referral insert hook
- core/PaymentSettler.php — settleSetupFee komisi trigger
- .htaccess — /affiliate/* route
- superadmin sidebar nav — link

---

## 11. Success Criteria

- ✅ Affiliate signup publik + login + dashboard ringan
- ✅ Kode/link referral unik, atribusi saat tenant daftar pakai ref
- ✅ Komisi flat Rp 100.000 cair 1× saat referral bayar setup fee (verified)
- ✅ Idempotent (no double komisi), anti-fraud (harus bayar)
- ✅ Request payout + SA approve manual + saldo akurat
- ✅ SA kelola affiliate/referral/payout + set rate
- ✅ Auth terisolasi, zero impact ke tenant/SA login

---

## 12. References
- `register.php` (tenant signup), `core/TenantProvisioner.php::provision()` (line 184)
- `core/PaymentSettler.php::settleSetupFee()` (line 97) — komisi trigger
- `core/BillingConfig.php` (saas_billing_config) — affiliate_commission
- SA pattern: `superadmin/*.php` + `SaPermission::require`
- QRIS integration spec (sibling, PaymentSettler idempotency): `docs/superpowers/specs/2026-06-24-qris-integration-design.md`

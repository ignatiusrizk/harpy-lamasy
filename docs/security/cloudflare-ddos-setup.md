# Panduan Setting Cloudflare — Proteksi DDoS LAMASY

**Domain:** `lamasy.harpy.id` · **Origin:** Hostinger · **Tanggal:** 2026-07-03

Situs sudah di belakang Cloudflare (terkonfirmasi `server: cloudflare`). Cloudflare otomatis menyerap serangan volumetrik (L3/L4). Panduan ini menutup sisa celah yang **harus di-set manual di dashboard** — tak bisa diatur dari kode.

Login: https://dash.cloudflare.com → pilih domain **harpy.id**.

Urutan prioritas: **#4 (origin lock) dan #1 (rate limit) paling penting.** Sisanya pelengkap.

---

## #4 — Kunci Origin ke IP Cloudflare (PALING PENTING)

**Masalah:** kalau penyerang tahu IP asli server Hostinger, dia bisa hantam server langsung, **melewati** Cloudflare — semua proteksi Cloudflare jadi sia-sia.

**Solusi:** blokir semua koneksi ke origin kecuali dari rentang IP Cloudflare.

### Cara di Hostinger (hPanel)
1. hPanel → **Advanced → SSH Access / Firewall** (atau via `.htaccess` kalau tak ada firewall UI).
2. Kalau tak ada firewall IP, pakai **`.htaccess`** di root — tambah di paling atas:

```apache
# Hanya izinkan request dari Cloudflare (blokir akses langsung ke origin IP)
<RequireAll>
  Require ip 173.245.48.0/20 103.21.244.0/22 103.22.200.0/22 103.31.4.0/22 141.101.64.0/18 108.162.192.0/18 190.93.240.0/20 188.114.96.0/20 197.234.240.0/22 198.41.128.0/17 162.158.0.0/15 104.16.0.0/13 104.24.0.0/14 172.64.0.0/13 131.0.72.0/22
  Require ip 2400:cb00::/32 2606:4700::/32 2803:f800::/32 2405:b500::/32 2405:8100::/32 2a06:98c0::/29 2c0f:f248::/32
</RequireAll>
```
> Daftar IP Cloudflare terbaru: https://www.cloudflare.com/ips/ — cek berkala (jarang berubah).
> **PENTING:** pastikan Cloudflare mengirim header `CF-Connecting-IP` (untuk log IP asli visitor). Restore visitor IP: aktifkan **mod_cloudflare** atau pakai `CF-Connecting-IP` di kode (RateLimiter sudah baca via `getClientIp()`).

### Verifikasi
- Cari IP origin: `nslookup` **tak** akan kasih IP asli (dapat IP Cloudflare). IP asli bisa bocor via email header lama / DNS history — itu sebabnya lock ini penting.
- Test: `curl -H "Host: lamasy.harpy.id" https://<IP-ORIGIN>/login` → harus **403/blocked** setelah lock.

---

## #1 — Rate Limiting Rules (batasi request per-IP)

Cloudflare → **Security → WAF → Rate limiting rules → Create rule**.
(Free tier: 1 rule. Pro: lebih banyak — prioritaskan `/superadmin` & login.)

### Rule A — Lindungi login & SuperAdmin (prioritas)
- **Field:** URI Path · **operator:** contains · **value:** `/superadmin`
  - (kalau bisa OR: tambah `/login` dan `/register`)
- **When rate exceeds:** `20` requests per `1 minute` (per IP)
- **Action:** Block (atau Managed Challenge) · **Duration:** 10 menit

### Rule B (kalau punya kuota) — API umum
- **URI Path** contains `/api/`
- `60` requests / `1 minute` per IP → Managed Challenge

> App sudah punya rate-limit sendiri (login/register/AI/wilayah), ini lapisan ekstra di edge (request tak sampai ke server sama sekali).

---

## #2 — Bot Fight Mode (gratis, 1 klik)

Cloudflare → **Security → Bots** → aktifkan **Bot Fight Mode**.
- Otomatis tantang/blok bot jahat sebelum sampai origin.
- Pro tier: "Super Bot Fight Mode" (lebih granular).

---

## #3 — Under Attack Mode (toggle darurat)

Saat **sedang** diserang: Cloudflare → **Overview** (atau Security) → **Under Attack Mode: ON**.
- Semua pengunjung dapat halaman challenge JS ~5 detik sebelum masuk.
- **Matikan lagi** setelah serangan reda (mengganggu UX normal).
- Tips: bisa di-otomatiskan via Cloudflare API kalau perlu.

---

## #5 — Caching aset statis (kurangi beban origin)

Cloudflare → **Caching → Configuration** + **Rules → Cache Rules**.
- Buat Cache Rule: URI Path matches `\.(css|js|png|jpg|jpeg|webp|svg|woff2?)$` → **Cache eligibility: Eligible for cache**, Edge TTL 1 bulan.
- Aset (harpy-erp.css, assets/*) dilayani dari edge Cloudflare → origin lega saat traffic tinggi.
- **JANGAN** cache halaman PHP dinamis (default `cf-cache-status: DYNAMIC` sudah benar — biarkan).

---

## Setting pendukung (cepat, sekalian)

| Menu Cloudflare | Set | Alasan |
|---|---|---|
| **SSL/TLS → Overview** | **Full (strict)** | Enkripsi Cloudflare↔origin tervalidasi |
| **SSL/TLS → Edge Certificates** | **Always Use HTTPS: ON** + **Min TLS 1.2** | Selaras dgn HSTS yg baru dipasang |
| **Security → Settings** | **Security Level: Medium/High** | Challenge IP reputasi buruk |
| **Network** | **HTTP/2 + HTTP/3: ON** | Performa + resilience |
| **Scrape Shield** | **Email Obfuscation: ON** | Sembunyikan email dari scraper |

---

## Checklist ringkas

- [ ] **#4** Origin dikunci ke IP Cloudflare (`.htaccess` atau firewall Hostinger) — **wajib**
- [ ] **#1** Rate limiting rule untuk `/superadmin` + login — **wajib**
- [ ] **#2** Bot Fight Mode ON
- [ ] **#5** Cache Rule aset statis
- [ ] SSL Full (strict) + Always Use HTTPS + Min TLS 1.2
- [ ] Security Level Medium/High
- [ ] (darurat) tahu cara nyalakan Under Attack Mode

**Estimasi waktu:** ~15–20 menit. #4 + #1 saja sudah menutup risiko terbesar.

---

## Yang SUDAH ditangani di aplikasi (tak perlu Cloudflare)
- Rate-limit login/register (+ captcha), AI (kuota harian per tenant), wilayah (60/menit/sesi).
- CSRF, session `Secure+HttpOnly+SameSite=Strict`, HSTS, security headers, error-disclosure ditutup.
- Lihat [laporan QA & security](../qa/2026-07-03-deep-qa-report.md).

# Landing Page Optimization — Design Spec

**Date:** 2026-06-23 WIB
**Status:** Draft (awaiting user review)
**Author:** Brainstormed with Rizky via brainstorming skill

## Goal

Full revamp landing page `lamasy.harpy.id` untuk meningkatkan:

1. **SEO ranking organik** — muncul di top 10 Google untuk keyword target laundry SaaS
2. **Social sharing** — preview WA/FB/LinkedIn proper
3. **Conversion** — visitor → trial signup
4. **Performance** — Lighthouse mobile ≥ 85, LCP < 2.5s

Pendekatan: 1 combined spec, dipecah ke 4 phase implementasi independent shippable.

## Constraints & Assumptions

- **Audience prioritas:** owner laundry 1-3 outlet (UMKM), tapi page support semua tier (chain juga muncul di Use Case).
- **Social proof strategy:** TIDAK pakai testimoni fiktif dengan nama+foto user palsu (risiko UU Perlindungan Konsumen + UU ITE + brand). Pakai kombinasi:
  - Founder story (real, tentang Rizky)
  - Use case scenarios clearly labeled fictional
  - Trust signals (security/tech credibility)
- **Design system:** Ponytail pattern — vanilla CSS via `harpy-erp.css` (`hl-*` utility), mobile-first, dark navy `#0F1C3A` + teal `#35E8D5`, Plus Jakarta Sans font. No framework.
- **Existing 9 sections dipakai semua** (Hero, Masalah, Fitur, Perbandingan, Cara Kerja, Untuk Siapa, Harga, FAQ, Footer) — di-refine, bukan rebuild.
- **Honest marketing** — no dark patterns (fake countdown, fake social proof, fake "X people viewing"). Urgency hanya dari real beta program slot count.

## Architecture — 4 Phases

| Phase | Topic | Effort | Ships independently |
|-------|-------|--------|---------------------|
| **Phase 1** | SEO Foundation (meta tags + JSON-LD + sitemap) | 2-3 jam | Yes — benefit langsung di WA preview + Google crawl |
| **Phase 2** | Content Sections (Founder + Use Case + Trust + refine) | 4-6 jam | Yes — visitor paham value prop tanpa Phase 3-4 |
| **Phase 3** | Performance (Lighthouse, image, font, CSS, CF cache) | 3-4 jam | Yes — speed + SEO boost |
| **Phase 4** | Conversion Polish (sticky CTA, urgency, exit modal, analytics) | 3-4 jam | Yes — optimization layer di atas content + tech |

**Total effort:** ~15-20 jam, dipecah 4-5 hari.

---

## Phase 1 — SEO Foundation

### Files modified/created

- **Modified:** `landing.php` — `<head>` section: tambah meta tags + JSON-LD
- **Modified:** `landing.php` — hapus `<meta name="keywords">` (deprecated Google sejak 2009)
- **Created:** `sitemap.xml` (root level)
- **Created:** `robots.txt` (root level)
- **Created:** `assets/og-image.png` — 1200×630 PNG, navy bg + teal H logo + tagline

### Meta tags ditambah ke `<head>` landing.php

```html
<!-- Open Graph -->
<meta property="og:type" content="website">
<meta property="og:url" content="https://lamasy.harpy.id/">
<meta property="og:title" content="LAMASY — ERP Laundry Modern dengan AI Terintegrasi">
<meta property="og:description" content="POS, laporan SAK EMKM, AI briefing, integrasi WhatsApp — semua dalam 1 platform. Trial 7 hari gratis.">
<meta property="og:image" content="https://lamasy.harpy.id/assets/og-image.png">
<meta property="og:locale" content="id_ID">
<meta property="og:site_name" content="LAMASY">

<!-- Twitter Card -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="LAMASY — ERP Laundry Modern dengan AI Terintegrasi">
<meta name="twitter:description" content="POS, laporan SAK EMKM, AI briefing, integrasi WhatsApp — semua dalam 1 platform.">
<meta name="twitter:image" content="https://lamasy.harpy.id/assets/og-image.png">

<!-- Canonical + robots -->
<link rel="canonical" href="https://lamasy.harpy.id/">
<meta name="robots" content="index, follow">
<meta name="author" content="PT Harpy Sinergi Mandiri">
```

### JSON-LD Structured Data (4 schemas)

Inline `<script type="application/ld+json">` di bottom of `<head>`:

1. **Organization** — nama, logo URL, contact, sameAs (link sosmed kalau ada)
2. **SoftwareApplication** — name "LAMASY", applicationCategory "BusinessApplication", operatingSystem "Web", offers (price 0 Rp untuk trial 7 hari, priceCurrency "IDR")
3. **FAQPage** — auto-generate dari existing FAQ section (landing.php line 1650-1731). Tiap Q&A jadi `Question` + `acceptedAnswer`.
4. **BreadcrumbList** — minimal: Home → (untuk future multi-page)

### sitemap.xml

```xml
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc>https://lamasy.harpy.id/</loc>
    <lastmod>2026-06-23</lastmod>
    <changefreq>weekly</changefreq>
    <priority>1.0</priority>
  </url>
  <url>
    <loc>https://lamasy.harpy.id/tos</loc>
    <lastmod>2026-06-23</lastmod>
    <changefreq>yearly</changefreq>
    <priority>0.3</priority>
  </url>
  <url>
    <loc>https://lamasy.harpy.id/privacy</loc>
    <lastmod>2026-06-23</lastmod>
    <changefreq>yearly</changefreq>
    <priority>0.3</priority>
  </url>
</urlset>
```

### robots.txt

```
User-agent: *
Allow: /
Disallow: /superadmin/
Disallow: /api/
Disallow: /hq/
Disallow: /dashboard
Disallow: /pos
Disallow: /orders
Disallow: /kanban
Disallow: /produksi
Disallow: /antar-jemput
Disallow: /kurir
Disallow: /kurir-master
Disallow: /piutang
Disallow: /loyalty
Disallow: /customer
Disallow: /karyawan
Disallow: /absensi
Disallow: /import
Disallow: /support
Disallow: /pelanggan
Disallow: /pelanggan-order
Sitemap: https://lamasy.harpy.id/sitemap.xml
```

### OG Image design

- Format: PNG, target ~80-150KB (optimize via TinyPNG)
- Dimensions: 1200×630 (Twitter Card + Facebook standard)
- Background: `#0F1C3A` navy dengan subtle teal orb glow di pojok
- Center: teal H circle logo (scaled dari icon-512.png), ~280px
- Tagline white besar bold: "ERP Laundry Modern dengan AI Terintegrasi"
- Sub-tagline teal mono: "lamasy.harpy.id"

Generate via ImageMagick atau via design tool (Figma/Canva) — user pilih nanti di phase implementasi.

---

## Phase 2 — Content Sections

### Section order baru (urutan psikologis Awareness → Interest → Trust → Action)

```
1. Hero (existing — refine copy)
2. Masalah (existing — refine)
3. Use Case Scenarios ← BARU
4. Fitur (existing)
5. Perbandingan (existing)
6. Cara Kerja (existing)
7. Untuk Siapa (existing)
8. Founder Story ← BARU
9. Trust Signals ← BARU
10. Harga (existing — refine)
11. FAQ (existing — tambah 2-3 entry)
12. Footer (existing)
```

### Section "Use Case Scenarios" (BARU)

**Position:** Sebelum Fitur (line ~1140 di landing.php). User relate dulu, baru lihat fitur.

**Layout:** Grid 3 kolom desktop (`@media (min-width: 768px)`), 1 kolom stack mobile.

**Per card markup pattern:**
```html
<div class="hl-usecase-card">
  <div class="hl-usecase-header">
    <span class="hl-usecase-icon">🏪</span>
    <div>
      <div class="hl-usecase-name">Laundry Sari</div>
      <div class="hl-usecase-tier">1 outlet · Jakarta</div>
    </div>
  </div>
  <div class="hl-usecase-before">
    <strong>Sebelum:</strong> Rekap order pakai Excel + buku tulis. Sering salah hitung,
    pelanggan komplain.
  </div>
  <div class="hl-usecase-after">
    <strong>Setelah LAMASY:</strong> POS otomatis, struk WA langsung kirim, laporan SAK EMKM
    auto-generate.
  </div>
  <div class="hl-usecase-outcome">⏱️ Hemat 12 jam/minggu rekap manual</div>
</div>
```

**3 scenario:**

| Tier | Nama fictional | Pain | Solution | Outcome |
|------|----------------|------|----------|---------|
| 1 outlet | Laundry Sari | Excel/buku tulis ribet, salah hitung | POS otomatis + struk WA | Hemat 12 jam/minggu |
| 3 outlet | Bersih Express | Susah konsolidasi data antar outlet | HQ view + laporan multi-outlet | Visibility real-time semua outlet |
| Chain 5+ | Wash & Go Group | Audit komisi karyawan ribet, ga ada role-based | RBAC + audit log + AI briefing | Audit lengkap, owner fokus growth |

**Footer disclaimer** (kecil, font 11px, abu-abu):
> *Skenario ilustrasi penggunaan platform LAMASY, bukan testimoni dari customer real.

### Section "Founder Story" (BARU)

**Position:** Sebelum Trust Signals + Harga (build trust before ask).

**Layout:** 2-kolom desktop (kiri foto/avatar 1/3 width, kanan story 2/3 width). Stack mobile.

**Content (TEMPLATE — user revisi sebelum publish):**

```
Halo, saya Rizky.

Saya bikin LAMASY karena melihat banyak owner laundry yang masih ribet ngurus
order pakai Excel atau buku tulis — sering salah hitung, susah lacak status,
kasir cape rekap manual setiap malam.

Padahal, teknologi untuk bikin laundry jadi efisien udah ada. Tapi kebanyakan
software laundry sekarang either terlalu mahal untuk UMKM, atau terlalu ribet
buat dipelajari.

Saya membangun LAMASY untuk owner laundry kecil-menengah yang mau modernize
tanpa beban biaya bulanan tetap. Bayar sesuai pakai via Coin — kalau slow
business, biaya tetap rendah.

Sekarang LAMASY masih early adopter program. Saya respon WA langsung untuk
feedback dan support. Mau coba? Ada trial 7 hari gratis.

— Ignatius Rizky, Founder
```

Markup ponytail:
```html
<section class="section" id="founder">
  <h2>Kenapa LAMASY</h2>
  <div class="hl-founder-grid">
    <div class="hl-founder-photo">
      <!-- Placeholder: teal H circle besar atau foto user real -->
      <img src="/assets/founder.jpg" alt="Founder Rizky" />
    </div>
    <div class="hl-founder-text">
      <p>...</p>
      <p class="hl-founder-signature">— Ignatius Rizky, Founder</p>
    </div>
  </div>
</section>
```

### Section "Trust Signals" (BARU)

**Position:** Antara Founder Story & Harga.

**Layout:** Grid 4 kolom desktop (`@media (min-width: 1024px)`), 2 kolom tablet, 1 kolom mobile.

**6 trust signals (pilih dari list 8, top 6):**

1. 🔐 **Multi-Tenant Isolated** — Data outlet kamu, hanya kamu yang akses
2. 📊 **Audit Log Lengkap** — Semua aksi tercatat, transparan
3. 🛡️ **HTTPS + Encrypted** — Koneksi aman, data ter-enkripsi
4. 🇮🇩 **Server di Indonesia** — Low latency, support lokal
5. 🤝 **Bayar Sesuai Pakai** — Coin-based, no monthly lock-in
6. 💬 **Support WA Langsung** — Founder responsif, balas pribadi

**Per item markup:**
```html
<div class="hl-trust-item">
  <span class="hl-trust-icon">🔐</span>
  <div class="hl-trust-title">Multi-Tenant Isolated</div>
  <div class="hl-trust-desc">Data outlet kamu, hanya kamu yang akses</div>
</div>
```

### Refine existing sections

**Hero:**
- Tambahkan micro-trust di bawah CTA primary, font 13px, muted:
  > ✓ Tanpa kartu kredit · ✓ Setup 5 menit · ✓ Cancel anytime

**Harga:**
- Eksplisit framing trial vs paid: "**Trial 7 hari gratis** — lanjut bayar sesuai pakai via Coin"
- Tambah compare framing: "vs langganan bulanan kompetitor Rp 500K+/bulan, LAMASY bayar sesuai pakai"

**FAQ:**
- Tambah 3 entry dari objection umum:
  - **"Apa beda LAMASY dengan kompetitor X (mis: Smartlink)?"**
    A: Fokus AI-first + bayar coin (no monthly lock-in) + multi-tenant SaaS proper.
  - **"Apakah data saya aman?"**
    A: Multi-tenant isolated, HTTPS encrypted, audit log lengkap, backup harian (Hostinger).
  - **"Bagaimana kalau saya berhenti pakai?"**
    A: Export semua data via HQ → Export Data. No lock-in. Sisa coin refundable.

### CSS additions

Tambahkan di `harpy-erp.css` (akhir file, dalam comment header `/* ── LANDING PAGE: hl-usecase, hl-founder, hl-trust ── */`):

```css
/* Use Case */
.hl-usecase-card { /* card style */ }
.hl-usecase-header { display:flex; gap:12px; align-items:center; }
.hl-usecase-icon { font-size:32px; }
.hl-usecase-name { font-weight:700; }
.hl-usecase-tier { font-size:12px; opacity:.6; }
.hl-usecase-before, .hl-usecase-after { margin-top:12px; font-size:14px; }
.hl-usecase-outcome { margin-top:16px; padding:8px 12px; background:rgba(53,232,213,.1); border-left:3px solid #35E8D5; border-radius:4px; }

/* Founder */
.hl-founder-grid { display:grid; grid-template-columns:1fr; gap:24px; }
@media (min-width:768px) { .hl-founder-grid { grid-template-columns:1fr 2fr; } }
.hl-founder-photo img { width:100%; border-radius:16px; }
.hl-founder-signature { font-style:italic; margin-top:24px; }

/* Trust */
.hl-trust-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
@media (min-width:1024px) { .hl-trust-grid { grid-template-columns:repeat(4,1fr); } }
.hl-trust-item { padding:20px; background:rgba(255,255,255,.04); border-radius:12px; }
.hl-trust-icon { font-size:32px; display:block; margin-bottom:12px; }
.hl-trust-title { font-weight:700; margin-bottom:6px; }
.hl-trust-desc { font-size:13px; opacity:.7; }
```

---

## Phase 3 — Performance

### Audit baseline (sebelum optimize)

Run Lighthouse di Chrome DevTools (mobile + desktop) atau PageSpeed Insights. Record:

- LCP (Largest Contentful Paint)
- FCP (First Contentful Paint)
- CLS (Cumulative Layout Shift)
- TBT (Total Blocking Time)
- Total page size (in KB)
- Total requests

Save baseline di `docs/perf-baseline-2026-06-23.md` untuk comparison post-optimize.

### Image optimization

- Audit semua `<img>` di landing.php
- Untuk raster: convert ke WebP, fallback PNG via `<picture>` element
- Semua `<img>` below-the-fold: `loading="lazy"`
- Semua `<img>`: kasih `width` + `height` attribute (zero CLS)
- Hero mockup (kalau ada raster): kasih `fetchpriority="high"` supaya LCP cepat

### Font loading

**Audit:** weight Plus Jakarta Sans yang BENAR-BENAR dipakai di landing.php. Probably 400 (body), 600 (medium), 700 (bold), 800 (extrabold) cukup. Drop 300 + 500.

**Audit DM Mono:** kalau ga dipakai di landing → drop. Cek dengan grep `font-family.*Mono` di style block landing.php.

**Loading strategy:**
- Replace `<link href="...">` Google Fonts dengan async pattern:
  ```html
  <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap">
  <link rel="stylesheet" href="..." media="print" onload="this.media='all'">
  <noscript><link rel="stylesheet" href="..."></noscript>
  ```
- Atau self-host font (download woff2 dari Google → simpan di `/assets/fonts/`, reference via `@font-face`). Lebih cepat lagi, no extra DNS lookup.

### CSS strategy

**Audit:** apakah landing.php pakai class dari `harpy-erp.css`? Grep `class="hl-` di landing.php.

- Kalau **tidak**: drop reference ke `harpy-erp.css` di landing.php (saving ~50KB+ CSS yang ga dipakai). Landing punya `<style>` inline sendiri.
- Kalau **ya**: keep reference, tapi consider extract relevant rules ke `landing.css` terpisah.

Inline critical CSS (above-fold styles) di `<head>`, sisanya async load:
```html
<style>/* critical CSS inline */</style>
<link rel="stylesheet" href="/landing.css" media="print" onload="this.media='all'">
```

### JavaScript audit

- Grep `<script>` di landing.php
- Kalau hanya minor nav/scroll logic: keep inline
- Kalau ada lib heavy (jQuery dll): defer atau load on-demand

### Cloudflare cache headers

User check di Cloudflare dashboard:
- HTML: `Cache-Control: no-cache, must-revalidate` (default for PHP, OK)
- Assets (`.png`, `.css`, `.js`): `Cache-Control: public, max-age=31536000, immutable` — sudah di-cache-bust via `?v=`, jadi safe immutable
- Cloudflare panel: aktifkan **Auto Minify** untuk HTML/CSS/JS (Settings → Speed → Optimization)
- Aktifkan **Brotli compression** (Settings → Speed → Optimization)

### Service Worker scope check

Cek `sw-tenant.js` — pastikan landing page (`/` atau `/landing.php`) di-handle dengan strategy yang tepat:

- Option A: Skip landing (no cache) — visitor selalu dapat fresh HTML
- Option B: Cache-first dengan TTL pendek (1 hari) + auto-update on revalidate

Recommendation: **Option A** (skip) — landing content sering update, ga perlu offline.

Add `/landing.php` + root `/` ke skip list di fetch handler.

### Expected impact

| Metric | Before (est.) | After (target) |
|--------|--------------|----------------|
| Lighthouse mobile | 60-75 | ≥ 85 |
| LCP | 3-4s | < 2.5s |
| Page size | 200-400KB | < 150KB |
| Font requests | 3-5 (Google) | 2 (or 0 if self-hosted) |

---

## Phase 4 — Conversion Polish

### 1. Sticky Mobile CTA Bar

**Trigger:** show setelah scroll past hero (~600px from top), hide kalau scroll back up ke top.

**Layout (mobile only, `< 768px`):**
```html
<div class="hl-sticky-cta" id="hlStickyCta">
  <a href="https://wa.me/<owner-number>" class="hl-sticky-wa">💬 Tanya WA</a>
  <a href="/register" class="hl-sticky-primary">🚀 Coba Gratis →</a>
</div>
```

**CSS:**
```css
.hl-sticky-cta {
  position:fixed; bottom:0; left:0; right:0;
  background:#0F1C3A; padding:12px 16px;
  display:flex; gap:12px;
  box-shadow:0 -4px 20px rgba(0,0,0,.4);
  transform:translateY(100%); transition:transform .3s;
  z-index:100;
}
.hl-sticky-cta.show { transform:translateY(0); }
.hl-sticky-wa, .hl-sticky-primary { flex:1; padding:12px; border-radius:8px; text-align:center; }
.hl-sticky-wa { background:rgba(255,255,255,.08); color:#fff; }
.hl-sticky-primary { background:#35E8D5; color:#0F1C3A; font-weight:700; }
@media (min-width:768px) { .hl-sticky-cta { display:none; } }
```

**JS (inline vanilla, ~10 lines):**
```js
const stickyEl = document.getElementById('hlStickyCta');
let lastY = 0;
window.addEventListener('scroll', () => {
  const y = window.scrollY;
  if (y > 600) stickyEl.classList.add('show');
  else stickyEl.classList.remove('show');
  lastY = y;
});
```

### 2. Urgency Element — Early Adopter Beta Program

**Position:** Banner kecil di atas hero (atau di atas pricing section).

**Markup:**
```html
<div class="hl-beta-banner">
  🌱 <strong>Beta Access Program</strong> · 50 slot early adopter pertama dapat bonus 100K coin
  (≈3 bulan AI briefing gratis). Sisa: <span id="hlBetaSlots">47</span> slot
</div>
```

**Counter source:** Static JSON file `assets/beta-slots.json`:
```json
{ "total": 50, "remaining": 47, "updated_at": "2026-06-23" }
```

Fetch via fetch API on page load. User update manual (atau auto-decrement saat user register via webhook nanti — out of scope phase ini).

**No fake real-time** — banner displays static "47 slot" until user updates the JSON.

### 3. Exit-Intent Modal (Desktop only)

**Trigger:** `mouseout` event with `e.clientY <= 0` (mouse meninggalkan viewport ke atas, indicating close tab).

**Display logic:**
- Hanya tampil sekali per session (use `sessionStorage`)
- Hanya desktop (`window.innerWidth >= 1024`)

**Modal content:**
```html
<div class="hl-exit-modal" id="hlExitModal" style="display:none;">
  <div class="hl-exit-content">
    <button class="hl-exit-close">&times;</button>
    <h3>Tunggu, mau saya kirim panduan gratis?</h3>
    <p>Checklist 7 step setup laundry digital — PDF + link demo video.</p>
    <form>
      <input type="email" placeholder="email@kamu.com" required>
      <button type="submit">Kirim ke email saya</button>
    </form>
    <a href="#" class="hl-exit-skip">Lain kali</a>
  </div>
</div>
```

**Form handler:** POST ke `/api/lead.php` (create simple endpoint yang INSERT ke `hl_leads` table — schema migration di phase implementasi). Tampil success message.

Modal di-styling consistent dengan ponytail (dark navy bg, teal accent CTA).

**Note:** kalau user feel ini agresif, bisa di-drop di phase implementasi.

### 4. Inline Mini-FAQ di section Harga

Position: di bawah pricing cards, sebelum CTA besar.

**3 question accordion (collapsed by default):**

```html
<div class="hl-mini-faq">
  <details>
    <summary>Apa yang terjadi setelah daftar?</summary>
    <p>Setelah klik "Coba Gratis", kamu register dengan nama outlet + email + password. Setup outlet selesai dalam 5 menit, langsung bisa pakai.</p>
  </details>
  <details>
    <summary>Apakah saya wajib bayar setelah trial 7 hari?</summary>
    <p>Tidak wajib. Kalau tidak topup coin, akses dibatasi tapi data tersimpan. Anytime mau lanjut, tinggal topup coin.</p>
  </details>
  <details>
    <summary>Bagaimana cara cancel?</summary>
    <p>Tidak ada cancel — kamu bayar sesuai pakai. Stop topup = stop biaya. Data export bebas via HQ → Export Data.</p>
  </details>
</div>
```

Native HTML `<details>` element — zero JS, accessible, lightweight.

### 5. Analytics — Cloudflare Web Analytics

**Pilihan:** Cloudflare Web Analytics (FREE, sudah di-CDN).

**Setup:**
1. Cloudflare panel → Analytics & Logs → Web Analytics → Add site → lamasy.harpy.id
2. Cloudflare provide beacon JS snippet — paste di `<head>` landing.php
3. (Optional) Track custom events via beacon API:
   - `cf-beacon` event "click_register" saat user klik CTA "Coba Gratis"
   - `cf-beacon` event "click_wa" saat user klik tombol WA
   - `cf-beacon` event "scroll_75" saat user scroll past 75%

**Events tracked:**
- Page view (default)
- Click "Coba Gratis" (semua varian — hero, sticky, pricing)
- Click "Tanya WA"
- Submit lead form (exit-intent)
- Scroll depth milestones (25/50/75/100%)

### 6. Optional — Microsoft Clarity heat-map

Skip di phase ini. Nanti implement separate kalau perlu insight visual.

### Excluded by design (sengaja tidak dipakai)

- ❌ Pop-up subscribe form (annoying, high bounce)
- ❌ Auto-play video hero (bandwidth + accessibility)
- ❌ Live chat widget (heavy + perlu staff; pakai static WA link)
- ❌ Fake countdown timer
- ❌ Fake "X orang melihat sekarang" social proof
- ❌ Auto-subscribe newsletter without consent

---

## Acceptance Criteria

**Phase 1 done when:**
- [ ] Sharing `lamasy.harpy.id` di WA shows proper preview (OG image + title + desc)
- [ ] Google Search Console verify ownership + submit sitemap
- [ ] PageSpeed Insights / Rich Results Test confirm valid JSON-LD
- [ ] robots.txt accessible at `https://lamasy.harpy.id/robots.txt`
- [ ] sitemap.xml accessible at `https://lamasy.harpy.id/sitemap.xml`
- [ ] No `<meta name="keywords">` di landing.php

**Phase 2 done when:**
- [ ] 3 new sections live: Use Case (3 cards), Founder Story, Trust Signals (6 items)
- [ ] Hero punya micro-trust line
- [ ] Pricing punya framing trial vs paid + competitor comparison
- [ ] FAQ tambah 3 entry
- [ ] Disclaimer fictional di Use Case section visible
- [ ] Mobile responsive — semua section stack proper < 768px

**Phase 3 done when:**
- [ ] Lighthouse mobile ≥ 85
- [ ] LCP < 2.5s
- [ ] Total page size < 150KB (excluding fonts)
- [ ] No render-blocking CSS/JS
- [ ] All `<img>` punya width/height + lazy load below-fold
- [ ] Cloudflare Auto-Minify + Brotli active

**Phase 4 done when:**
- [ ] Sticky CTA bar muncul + hide proper di mobile
- [ ] Beta banner shows real slot count from JSON
- [ ] Exit-intent modal tampil sekali per session di desktop
- [ ] Mini-FAQ di Harga section accordion-able
- [ ] Cloudflare Web Analytics tracking page view + 5 custom events
- [ ] Zero false-claim copy di seluruh landing page

---

## Out of Scope (deferred)

- Multi-page landing (current single-page approach kept)
- Multi-bahasa (Indonesia only untuk sekarang)
- A/B testing infrastructure (set up nanti kalau traffic cukup)
- Email marketing integration (Mailchimp/Sendinblue) — capture leads dulu di DB, kirim manual
- Blog/content marketing (separate spec di future)
- Video hero (mungkin di future kalau ada budget produksi)
- Live chat (use static WA link)
- Customer testimonial real (tunggu sampai ada beta tester yang willing)

## Risk & Mitigation

| Risk | Mitigation |
|------|------------|
| Fake testimonial kalau lupa disclaimer | Wajib include `*Skenario ilustrasi` di bawah Use Case section |
| Exit-intent annoying user | Hanya sekali per session, mudah dismiss, no obstruction |
| Beta slot counter ga di-update | Set reminder; atau auto-decrement via webhook nanti |
| Performance regression dari new content | Run Lighthouse setelah Phase 2 sebelum Phase 3 |
| SEO meta typo / broken structured data | Validate via Google Rich Results Test sebelum push |

## References

- Existing landing.php structure (9 sections)
- Ponytail design system (`harpy-erp.css` `hl-*` utilities, mobile-first, navy+teal)
- Earlier brand session memory: `project_native_app_strategy.md`
- Schema.org docs: https://schema.org/SoftwareApplication, https://schema.org/Organization
- Cloudflare Web Analytics: https://www.cloudflare.com/web-analytics/

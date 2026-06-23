# Landing Page Optimization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Full landing page revamp (`lamasy.harpy.id`) — SEO, content, performance, conversion — dieksekusi dalam 4 phase yang masing-masing shippable independent.

**Architecture:** Modify existing `landing.php` (1837 lines, 9 sections) in-place. Tambah 3 section content baru, perbaiki SEO meta + JSON-LD, optimize asset loading, layering conversion features di atas. Tidak rebuild from scratch — refine + extend.

**Tech Stack:** PHP 8 (Hostinger), vanilla CSS (`harpy-erp.css` `hl-*` utilities), vanilla JS, Cloudflare CDN, Cloudflare Web Analytics, native HTML5 elements (`<details>` accordion, `<picture>` fallback).

## Global Constraints

- **Design system:** Ponytail — vanilla CSS via `harpy-erp.css` (`hl-*` utility classes), mobile-first, dark navy `#0F1C3A` + teal `#35E8D5`, Plus Jakarta Sans font, no framework.
- **Honest marketing:** No fake testimonial dengan nama+foto user real (UU Perlindungan Konsumen + UU ITE risk). No fake countdown. No fake "X people viewing".
- **Audience prioritas:** owner laundry 1-3 outlet, tapi page support semua tier.
- **Existing 9 sections kept:** Hero, Masalah, Fitur, Perbandingan, Cara Kerja, Untuk Siapa, Harga, FAQ, Footer — refine, don't rebuild.
- **No PHP test infra:** verification via curl/grep, online tools (PageSpeed, Rich Results Test, FB Sharing Debugger), MCP browser. Tidak ada PHPUnit suite untuk dibuat.
- **Auto-deploy:** git push → Hostinger auto-deploy ~15s. No manual deploy step.
- **Commit style:** ikuti pattern existing — Indonesian commit message, prefix `feat(landing)`, `fix(landing)`, `docs`, dll.
- **Subresource Integrity (SRI):** Untuk **versioned/static** external script tags, **wajib** tambah `integrity="sha384-..." crossorigin="anonymous"` — protect dari CDN compromise. Pengecualian (no SRI possible — dynamic resources):
  - Cloudflare Web Analytics beacon (`static.cloudflareinsights.com/beacon.min.js`) — CF rotates file, no version pinning
  - Google Fonts CSS (`fonts.googleapis.com/css2?...`) — browser-specific CSS dynamically generated
  - Untuk resource non-versioned di atas, gunakan `Content-Security-Policy` header sebagai backup defense (di-handle via Cloudflare panel atau .htaccess)

---

## File Structure

**Files yang akan di-modify/create:**

| File | Aksi | Phase | Owner |
|------|------|-------|-------|
| `landing.php` | Modify (multi-section) | 1, 2, 3, 4 | All phases touch ini |
| `harpy-erp.css` | Append CSS rules | 2, 4 | Add `hl-usecase-*`, `hl-founder-*`, `hl-trust-*`, `hl-sticky-cta`, `hl-beta-banner`, `hl-exit-modal`, `hl-mini-faq` |
| `sitemap.xml` | Create (root) | 1 | Phase 1 only |
| `robots.txt` | Create (root) | 1 | Phase 1 only |
| `assets/og-image.png` | Create | 1 | Phase 1 only |
| `assets/founder.jpg` | Create (placeholder) | 2 | Phase 2 only |
| `assets/beta-slots.json` | Create | 4 | Phase 4 only |
| `sw-tenant.js` | Modify (skip landing) | 3 | Phase 3 only |
| `api/lead.php` | Create | 4 | Phase 4 only |
| `superadmin/sql/leads_migration.sql` | Create | 4 | Phase 4 only |
| `docs/perf-baseline-2026-06-23.md` | Create | 3 | Phase 3 only |

---

# PHASE 1 — SEO Foundation

Target effort: 2-3 jam. Ship: WhatsApp/FB/LinkedIn share preview proper + Google rich snippets eligible.

## Task 1.1: Open Graph + Twitter Card + Canonical meta tags

**Files:**
- Modify: `landing.php:25-34` (head section — replace meta block)

**Interfaces:**
- Consumes: nothing (first task)
- Produces: standardized OG/Twitter meta tags yang dipakai task lain (JSON-LD pakai URL yang sama)

- [ ] **Step 1: Read current landing.php head section to confirm exact lines**

Run: `sed -n '25,40p' /Users/rizky/Documents/lamasy/landing.php`
Expected output mengandung: `<meta name="description"`, `<meta name="keywords"`, `<title>`

- [ ] **Step 2: Edit landing.php head — replace block**

Replace from `<meta charset="UTF-8"/>` through `<title>...</title>` (lines ~26-30) dengan block ini:

```html
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<meta name="description" content="LAMASY — ERP Laundry modern dengan AI terintegrasi. POS, Laporan Keuangan SAK EMKM, manajemen karyawan, integrasi WhatsApp lengkap. Trial 7 hari gratis, bayar sesuai pemakaian via Coin — tanpa langganan bulanan."/>
<meta name="author" content="PT Harpy Sinergi Mandiri"/>
<meta name="robots" content="index, follow"/>
<link rel="canonical" href="https://lamasy.harpy.id/"/>

<!-- Open Graph (WhatsApp, Facebook, LinkedIn) -->
<meta property="og:type" content="website"/>
<meta property="og:url" content="https://lamasy.harpy.id/"/>
<meta property="og:title" content="LAMASY — ERP Laundry Modern dengan AI Terintegrasi"/>
<meta property="og:description" content="POS, laporan SAK EMKM, AI briefing, integrasi WhatsApp — semua dalam 1 platform. Trial 7 hari gratis."/>
<meta property="og:image" content="https://lamasy.harpy.id/assets/og-image.png"/>
<meta property="og:image:width" content="1200"/>
<meta property="og:image:height" content="630"/>
<meta property="og:locale" content="id_ID"/>
<meta property="og:site_name" content="LAMASY"/>

<!-- Twitter Card -->
<meta name="twitter:card" content="summary_large_image"/>
<meta name="twitter:title" content="LAMASY — ERP Laundry Modern dengan AI Terintegrasi"/>
<meta name="twitter:description" content="POS, laporan SAK EMKM, AI briefing, integrasi WhatsApp — semua dalam 1 platform."/>
<meta name="twitter:image" content="https://lamasy.harpy.id/assets/og-image.png"/>

<link rel="icon" type="image/png" href="/assets/icon-192.png?v=<?= @filemtime(__DIR__.'/assets/icon-192.png') ?: '3' ?>"/>
<link rel="apple-touch-icon" href="/assets/apple-touch-icon-180.png?v=<?= @filemtime(__DIR__.'/assets/apple-touch-icon-180.png') ?: '3' ?>"/>
<meta name="theme-color" content="#0F1C3A"/>
<title>LAMASY — ERP Laundry Modern dengan AI Terintegrasi</title>
```

**Penting:** hapus baris `<meta name="keywords">` (deprecated by Google sejak 2009, currently ada di landing.php).

- [ ] **Step 3: Verify locally via grep**

Run:
```bash
grep -cE "og:type|og:url|og:title|og:image|twitter:card|canonical" landing.php
```
Expected: `7` atau lebih (semua tag ditemukan)

Run:
```bash
grep -c 'meta name="keywords"' landing.php
```
Expected: `0` (keywords meta removed)

- [ ] **Step 4: Commit**

```bash
git add landing.php
git commit -m "feat(landing): add OG + Twitter Card + canonical meta tags

Sebelumnya cuma punya description. Sekarang full social sharing meta
(WhatsApp/FB/LinkedIn preview) + Twitter Card + canonical untuk avoid
duplicate content. Hapus deprecated meta keywords."
```

- [ ] **Step 5: Push + wait deploy + verify production**

```bash
git push origin main
```

Wait ~15s untuk auto-deploy. Verify via curl:
```bash
curl -s https://lamasy.harpy.id/ | grep -oE 'property="og:(type|url|title|description|image)"' | sort -u
```
Expected: 5 lines (semua OG tag visible)

- [ ] **Step 6: Validate dengan Facebook Sharing Debugger**

Open di browser: `https://developers.facebook.com/tools/debug/?q=https%3A%2F%2Flamasy.harpy.id%2F`
Click "Scrape Again" → verify preview shows new OG title + description + image placeholder (image masih 404 sampai Task 1.4 selesai).

---

## Task 1.2: JSON-LD Structured Data (4 schemas)

**Files:**
- Modify: `landing.php` — add `<script type="application/ld+json">` block sebelum `</head>` (sekitar line 845)

**Interfaces:**
- Consumes: URL pattern dari Task 1.1 (`https://lamasy.harpy.id/`)
- Produces: schema.org metadata untuk Google rich snippets

- [ ] **Step 1: Read FAQ section di landing.php untuk extract Q&A pairs**

Run: `sed -n '1650,1731p' /Users/rizky/Documents/lamasy/landing.php | grep -oE "<h[34]>[^<]+|<p>[^<]+" | head -30`

Catat semua Q&A — akan dipakai di FAQPage schema.

- [ ] **Step 2: Insert JSON-LD block sebelum `</head>` di landing.php**

Cari `</head>` line di landing.php (sekitar line 846). Insert sebelum tag close:

```html
<!-- JSON-LD Structured Data -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Organization",
  "name": "LAMASY",
  "legalName": "PT Harpy Sinergi Mandiri",
  "url": "https://lamasy.harpy.id/",
  "logo": "https://lamasy.harpy.id/assets/icon-512.png",
  "description": "Platform ERP Laundry modern dengan AI terintegrasi untuk owner laundry Indonesia.",
  "contactPoint": {
    "@type": "ContactPoint",
    "contactType": "customer service",
    "areaServed": "ID",
    "availableLanguage": ["Indonesian"]
  }
}
</script>

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "SoftwareApplication",
  "name": "LAMASY",
  "operatingSystem": "Web",
  "applicationCategory": "BusinessApplication",
  "description": "ERP Laundry modern dengan AI terintegrasi. POS, Laporan SAK EMKM, manajemen karyawan, integrasi WhatsApp.",
  "offers": {
    "@type": "Offer",
    "price": "0",
    "priceCurrency": "IDR",
    "description": "Trial 7 hari gratis, lanjut bayar sesuai pemakaian via Coin"
  },
  "url": "https://lamasy.harpy.id/",
  "screenshot": "https://lamasy.harpy.id/assets/og-image.png"
}
</script>

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [
    {
      "@type": "Question",
      "name": "Apa itu LAMASY?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "LAMASY adalah platform ERP laundry modern dengan AI terintegrasi. Cocok untuk owner laundry 1-3 outlet sampai chain multi-outlet."
      }
    },
    {
      "@type": "Question",
      "name": "Berapa biaya pakai LAMASY?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "Trial 7 hari gratis tanpa kartu kredit. Setelah itu bayar sesuai pemakaian via Coin — tidak ada langganan bulanan."
      }
    },
    {
      "@type": "Question",
      "name": "Fitur apa saja yang ada di LAMASY?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "POS otomatis, laporan keuangan SAK EMKM, manajemen karyawan + absensi, integrasi WhatsApp, AI briefing harian, multi-outlet konsolidasi, dan 50+ fitur lainnya."
      }
    },
    {
      "@type": "Question",
      "name": "Apakah data saya aman di LAMASY?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "Data terisolasi per tenant (multi-tenant proper), HTTPS encrypted, audit log lengkap, backup harian. Hanya kamu yang akses data outlet kamu."
      }
    }
  ]
}
</script>

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    {
      "@type": "ListItem",
      "position": 1,
      "name": "Home",
      "item": "https://lamasy.harpy.id/"
    }
  ]
}
</script>
```

- [ ] **Step 3: Verify locally via grep**

Run:
```bash
grep -cE '"@type":\s*"(Organization|SoftwareApplication|FAQPage|BreadcrumbList)"' landing.php
```
Expected: `4` (semua 4 schemas present)

- [ ] **Step 4: Validate JSON syntax**

Run:
```bash
php -r '
$html = file_get_contents("landing.php");
preg_match_all("/<script type=\"application\/ld\+json\">(.*?)<\/script>/s", $html, $m);
foreach ($m[1] as $i => $json) {
  $parsed = json_decode(trim($json));
  echo "Block " . ($i+1) . ": " . (json_last_error() === JSON_ERROR_NONE ? "VALID" : "INVALID: " . json_last_error_msg()) . PHP_EOL;
}'
```
Expected: 4 blocks all VALID

If PHP not installed locally, use online validator: paste each JSON block ke https://jsonlint.com

- [ ] **Step 5: Commit + push + deploy**

```bash
git add landing.php
git commit -m "feat(landing): add 4 JSON-LD structured data schemas

Organization + SoftwareApplication + FAQPage + BreadcrumbList.
Eligible Google rich snippets — FAQ answers muncul di SERP."
git push origin main
```

- [ ] **Step 6: Validate dengan Google Rich Results Test**

Open: `https://search.google.com/test/rich-results?url=https%3A%2F%2Flamasy.harpy.id%2F`
Run test → verify "Eligible for rich results" untuk semua 4 schemas. Kalau ada warning, fix berdasarkan feedback.

---

## Task 1.3: sitemap.xml + robots.txt

**Files:**
- Create: `sitemap.xml` (root level)
- Create: `robots.txt` (root level)

**Interfaces:**
- Consumes: nothing
- Produces: crawl directives untuk Google + sitemap reference

- [ ] **Step 1: Create sitemap.xml di root**

Path: `/Users/rizky/Documents/lamasy/sitemap.xml`

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

- [ ] **Step 2: Create robots.txt di root**

Path: `/Users/rizky/Documents/lamasy/robots.txt`

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
Disallow: /self

Sitemap: https://lamasy.harpy.id/sitemap.xml
```

- [ ] **Step 3: Update .htaccess kalau perlu (cek file types block)**

Run:
```bash
grep -nE "FilesMatch.*xml|sql|md" /Users/rizky/Documents/lamasy/.htaccess | head -5
```

Check kalau `\.(sql|md|yml|yaml|log|bak|env|ini|conf|sh)$` block include `xml` — kalau iya, sitemap.xml akan 403. Currently `.xml` BUKAN di block list (sudah verified earlier). No change needed.

- [ ] **Step 4: Commit + push + deploy**

```bash
git add sitemap.xml robots.txt
git commit -m "feat(landing): add sitemap.xml + robots.txt

Sitemap minimal (home + tos + privacy). Robots block semua app
pages — Google hanya crawl public landing + legal pages."
git push origin main
```

- [ ] **Step 5: Verify production**

Run:
```bash
curl -s -o /dev/null -w "%{http_code}" https://lamasy.harpy.id/sitemap.xml
curl -s -o /dev/null -w "%{http_code}" https://lamasy.harpy.id/robots.txt
```
Expected: `200` + `200`

Run:
```bash
curl -s https://lamasy.harpy.id/robots.txt | grep -c "Sitemap:"
```
Expected: `1`

- [ ] **Step 6: Submit ke Google Search Console**

User action (manual):
1. Login Google Search Console
2. Add property `lamasy.harpy.id`
3. Verify ownership (HTML tag method paling cepat)
4. Sitemaps → Add sitemap → `sitemap.xml`
5. Submit

(Step ini opsional immediate — bisa di-defer. Pencatatan: kalau belum verify Search Console, indexing bakal lambat — manual submit accelerate.)

---

## Task 1.4: OG Image (1200×630)

**Files:**
- Create: `assets/og-image.png` (1200×630 PNG)

**Interfaces:**
- Consumes: existing `assets/icon-512.png` sebagai source logo
- Produces: OG image yang di-reference di Task 1.1 meta tags

- [ ] **Step 1: Generate OG image via ImageMagick**

Run dari `/Users/rizky/Documents/lamasy/`:
```bash
magick -size 1200x630 xc:'#0F1C3A' \
  \( assets/icon-512.png -resize 280x280 \) -gravity center -geometry +0-80 -composite \
  -font 'Plus-Jakarta-Sans-Bold' -pointsize 56 -fill white -gravity center \
  -annotate +0+150 "ERP Laundry Modern" \
  -font 'Plus-Jakarta-Sans-Bold' -pointsize 56 -fill '#35E8D5' -gravity center \
  -annotate +0+220 "dengan AI Terintegrasi" \
  -font 'Plus-Jakarta-Sans' -pointsize 24 -fill 'rgba(255,255,255,0.5)' -gravity center \
  -annotate +0+280 "lamasy.harpy.id" \
  assets/og-image.png
```

Kalau font 'Plus-Jakarta-Sans-Bold' tidak available di ImageMagick (likely tidak — system font), fallback ke `-font Helvetica-Bold` atau `-font Arial-Bold`. Atau path absolute `/System/Library/Fonts/Avenir-Heavy.ttc`.

Fallback command (simpler, no font dependency):
```bash
magick -size 1200x630 xc:'#0F1C3A' \
  \( assets/icon-512.png -resize 280x280 \) -gravity center -geometry +0-80 -composite \
  -font /System/Library/Fonts/Avenir-Heavy.ttc -pointsize 56 -fill white -gravity center \
  -annotate +0+150 "ERP Laundry Modern" \
  -font /System/Library/Fonts/Avenir-Heavy.ttc -pointsize 56 -fill '#35E8D5' -gravity center \
  -annotate +0+220 "dengan AI Terintegrasi" \
  -font /System/Library/Fonts/Avenir-Medium.ttc -pointsize 24 -fill '#888888' -gravity center \
  -annotate +0+290 "lamasy.harpy.id" \
  assets/og-image.png
```

- [ ] **Step 2: Verify dimensions + file size**

Run:
```bash
magick identify assets/og-image.png
```
Expected: `assets/og-image.png PNG 1200x630 1200x630+0+0 8-bit sRGB ...`

```bash
ls -la assets/og-image.png
```
Expected: file size 50-300KB (kalau > 300KB, optimize via tinypng.com).

- [ ] **Step 3: Optional optimize via tinypng**

If file size > 200KB, user upload ke https://tinypng.com → download optimized → replace.
Otherwise skip.

- [ ] **Step 4: Commit + push + deploy**

```bash
git add assets/og-image.png
git commit -m "feat(landing): add 1200x630 OG image untuk social sharing

Navy bg + teal H logo + 'ERP Laundry Modern dengan AI Terintegrasi'
tagline. Di-reference dari OG + Twitter Card meta tags."
git push origin main
```

- [ ] **Step 5: Verify deployed**

```bash
curl -s -o /dev/null -w "%{http_code} %{size_download} bytes\n" https://lamasy.harpy.id/assets/og-image.png
```
Expected: `200 <bytes> bytes` (bytes > 30000)

- [ ] **Step 6: Re-validate Facebook Sharing Debugger**

Open: `https://developers.facebook.com/tools/debug/?q=https%3A%2F%2Flamasy.harpy.id%2F`
Click "Scrape Again" → preview sekarang harus tampil dengan OG image visible.

Verify juga via Twitter Card Validator: `https://cards-dev.twitter.com/validator` → paste URL → preview check.

---

# PHASE 2 — Content Sections

Target effort: 4-6 jam. Ship: visitor paham value prop + trust building.

## Task 2.1: Use Case Scenarios section

**Files:**
- Modify: `landing.php:1140` (insert section BEFORE existing `<section id="fitur">`)
- Modify: `harpy-erp.css` (append CSS)

**Interfaces:**
- Consumes: ponytail color palette + Plus Jakarta Sans font
- Produces: `<section id="use-case">` di landing.php — section anchors untuk smooth scroll nav (kalau dipakai)

- [ ] **Step 1: Add CSS ke harpy-erp.css (append at bottom)**

Open `/Users/rizky/Documents/lamasy/harpy-erp.css`, append di akhir file:

```css
/* ── LANDING: Use Case Scenarios ───────────────────── */
.hl-usecase-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 20px;
  max-width: 1080px;
  margin: 0 auto;
}
@media (min-width: 768px) {
  .hl-usecase-grid { grid-template-columns: repeat(3, 1fr); gap: 24px; }
}
.hl-usecase-card {
  padding: 24px;
  background: rgba(255,255,255,.04);
  border: 1px solid rgba(255,255,255,.08);
  border-radius: 16px;
  display: flex;
  flex-direction: column;
}
.hl-usecase-header {
  display: flex;
  gap: 14px;
  align-items: center;
  margin-bottom: 16px;
}
.hl-usecase-icon { font-size: 36px; }
.hl-usecase-name { font-weight: 700; font-size: 16px; color: #fff; }
.hl-usecase-tier { font-size: 12px; color: rgba(255,255,255,.5); margin-top: 2px; }
.hl-usecase-before, .hl-usecase-after {
  font-size: 14px;
  line-height: 1.6;
  color: rgba(255,255,255,.75);
  margin-bottom: 10px;
}
.hl-usecase-outcome {
  margin-top: auto;
  padding: 10px 14px;
  background: rgba(53,232,213,.1);
  border-left: 3px solid #35E8D5;
  border-radius: 6px;
  font-size: 13px;
  color: #35E8D5;
  font-weight: 600;
}
.hl-usecase-disclaimer {
  text-align: center;
  font-size: 11px;
  color: rgba(255,255,255,.4);
  margin-top: 24px;
  font-style: italic;
}
```

- [ ] **Step 2: Insert Use Case section di landing.php**

Cari `<section class="section" id="fitur">` di landing.php (line ~1140). Insert sebelum line itu:

```html
<section class="section" id="use-case" style="background:rgba(255,255,255,.018)">
  <div class="section-head" style="text-align:center;margin-bottom:40px;">
    <h2 style="font-size:32px;font-weight:800;margin-bottom:12px;">Begini Cara Laundry Pakai LAMASY</h2>
    <p style="color:rgba(255,255,255,.6);max-width:600px;margin:0 auto;">3 skenario penggunaan platform untuk skala bisnis berbeda</p>
  </div>

  <div class="hl-usecase-grid">
    <div class="hl-usecase-card">
      <div class="hl-usecase-header">
        <span class="hl-usecase-icon">🏪</span>
        <div>
          <div class="hl-usecase-name">Laundry Sari</div>
          <div class="hl-usecase-tier">1 outlet · Jakarta</div>
        </div>
      </div>
      <div class="hl-usecase-before"><strong>Sebelum:</strong> Rekap order pakai Excel + buku tulis. Sering salah hitung, pelanggan komplain kemana cucian.</div>
      <div class="hl-usecase-after"><strong>Setelah LAMASY:</strong> POS otomatis, struk WA langsung kirim, laporan SAK EMKM auto-generate setiap bulan.</div>
      <div class="hl-usecase-outcome">⏱️ Hemat 12 jam/minggu rekap manual</div>
    </div>

    <div class="hl-usecase-card">
      <div class="hl-usecase-header">
        <span class="hl-usecase-icon">🧺</span>
        <div>
          <div class="hl-usecase-name">Bersih Express</div>
          <div class="hl-usecase-tier">3 outlet · Bandung</div>
        </div>
      </div>
      <div class="hl-usecase-before"><strong>Sebelum:</strong> Susah konsolidasi data antar outlet, owner harus telpon 3 kasir tiap pagi tanya laporan.</div>
      <div class="hl-usecase-after"><strong>Setelah LAMASY:</strong> HQ view real-time semua outlet, laporan konsolidasi otomatis, ranking outlet per hari.</div>
      <div class="hl-usecase-outcome">📊 Visibility real-time semua outlet</div>
    </div>

    <div class="hl-usecase-card">
      <div class="hl-usecase-header">
        <span class="hl-usecase-icon">🏢</span>
        <div>
          <div class="hl-usecase-name">Wash & Go Group</div>
          <div class="hl-usecase-tier">5+ outlet · Multi-kota</div>
        </div>
      </div>
      <div class="hl-usecase-before"><strong>Sebelum:</strong> Audit komisi karyawan ribet, gak ada role-based access, sering ada kesalahan otorisasi.</div>
      <div class="hl-usecase-after"><strong>Setelah LAMASY:</strong> RBAC proper, audit log lengkap, AI briefing harian per outlet, founder fokus growth.</div>
      <div class="hl-usecase-outcome">🚀 Audit lengkap, fokus scale</div>
    </div>
  </div>

  <div class="hl-usecase-disclaimer">
    *Skenario ilustrasi penggunaan platform LAMASY, bukan testimoni dari customer real.
  </div>
</section>
```

- [ ] **Step 3: Verify locally via grep**

Run:
```bash
grep -cE 'id="use-case"|hl-usecase-card|hl-usecase-disclaimer' landing.php
```
Expected: `≥ 5` (section anchor + 3 cards + disclaimer)

- [ ] **Step 4: Commit + push + deploy**

```bash
git add landing.php harpy-erp.css
git commit -m "feat(landing): add Use Case Scenarios section + CSS

3 fictional scenarios (1 outlet, 3 outlet, chain 5+) dengan
before/after framing + outcome. Disclaimer di footer section
*Skenario ilustrasi (bukan testimoni real)."
git push origin main
```

- [ ] **Step 5: Verify via MCP browser**

User open `https://lamasy.harpy.id/` (hard reload Cmd+Shift+R). Scroll past Masalah section → section Use Case visible dengan 3 cards + disclaimer di bawah.

Atau via MCP browser:
```
mcp__Claude_in_Chrome__navigate https://lamasy.harpy.id/
mcp__Claude_in_Chrome__get_page_text → cari "Begini Cara Laundry Pakai LAMASY"
```
Expected: text muncul di page.

---

## Task 2.2: Founder Story section

**Files:**
- Modify: `landing.php` (insert section sebelum `<section id="harga">`, sekitar line 1460)
- Modify: `harpy-erp.css` (append CSS)
- Create: `assets/founder.jpg` (placeholder atau real photo)

**Interfaces:**
- Consumes: ponytail palette
- Produces: `<section id="founder">` anchor

- [ ] **Step 1: Append CSS ke harpy-erp.css**

```css
/* ── LANDING: Founder Story ────────────────────────── */
.hl-founder-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 32px;
  max-width: 960px;
  margin: 0 auto;
  align-items: center;
}
@media (min-width: 768px) {
  .hl-founder-grid { grid-template-columns: 1fr 2fr; gap: 48px; }
}
.hl-founder-photo {
  width: 100%;
  max-width: 280px;
  margin: 0 auto;
}
.hl-founder-photo img {
  width: 100%;
  border-radius: 50%;
  aspect-ratio: 1/1;
  object-fit: cover;
  border: 3px solid #35E8D5;
}
.hl-founder-text p {
  font-size: 16px;
  line-height: 1.7;
  color: rgba(255,255,255,.85);
  margin-bottom: 16px;
}
.hl-founder-signature {
  font-style: italic;
  color: #35E8D5;
  font-weight: 600;
  margin-top: 24px !important;
}
```

- [ ] **Step 2: Create assets/founder.jpg placeholder**

Untuk placeholder (sebelum user upload foto real), generate via ImageMagick — circle gradient teal:
```bash
cd /Users/rizky/Documents/lamasy/assets
magick -size 400x400 \
  radial-gradient:'#35E8D5'-'#0F1C3A' \
  founder.jpg
```

Atau kalau user mau upload sendiri, copy foto real ke `assets/founder.jpg` (jpg/png, square aspect ratio).

- [ ] **Step 3: Insert Founder Story section di landing.php**

Cari `<section class="section" id="harga">` di landing.php (line ~1460). Insert sebelum:

```html
<section class="section" id="founder">
  <div class="section-head" style="text-align:center;margin-bottom:40px;">
    <h2 style="font-size:32px;font-weight:800;">Kenapa LAMASY</h2>
  </div>

  <div class="hl-founder-grid">
    <div class="hl-founder-photo">
      <img src="/assets/founder.jpg?v=<?= @filemtime(__DIR__.'/assets/founder.jpg') ?: '1' ?>" alt="Founder LAMASY" loading="lazy" width="280" height="280">
    </div>
    <div class="hl-founder-text">
      <p>Halo, saya Rizky.</p>
      <p>Saya bikin LAMASY karena melihat banyak owner laundry yang masih ribet ngurus order pakai Excel atau buku tulis — sering salah hitung, susah lacak status, kasir cape rekap manual setiap malam.</p>
      <p>Padahal, teknologi untuk bikin laundry jadi efisien udah ada. Tapi kebanyakan software laundry sekarang either terlalu mahal untuk UMKM, atau terlalu ribet buat dipelajari.</p>
      <p>Saya membangun LAMASY untuk owner laundry kecil-menengah yang mau modernize tanpa beban biaya bulanan tetap. Bayar sesuai pakai via Coin — kalau slow business, biaya tetap rendah.</p>
      <p>Sekarang LAMASY masih early adopter program. Saya respon WA langsung untuk feedback dan support.</p>
      <p class="hl-founder-signature">— Ignatius Rizky, Founder</p>
    </div>
  </div>
</section>
```

**Note:** User bebas revise text di Step 3 sebelum commit kalau mau adjust cerita.

- [ ] **Step 4: Verify**

Run:
```bash
grep -cE 'id="founder"|hl-founder-photo|Founder LAMASY' landing.php
```
Expected: `≥ 3`

- [ ] **Step 5: Commit + push + deploy**

```bash
git add landing.php harpy-erp.css assets/founder.jpg
git commit -m "feat(landing): add Founder Story section + CSS + placeholder photo

2-column layout (photo + story). Founder photo placeholder
teal radial gradient — user upload real photo nanti.
Trust-building section sebelum pricing."
git push origin main
```

- [ ] **Step 6: Verify deployment**

Open `https://lamasy.harpy.id/` di browser → scroll ke section "Kenapa LAMASY" → muncul photo bulat + text founder.

---

## Task 2.3: Trust Signals section

**Files:**
- Modify: `landing.php` (insert section setelah Founder, sebelum Harga)
- Modify: `harpy-erp.css` (append CSS)

**Interfaces:**
- Consumes: ponytail palette
- Produces: `<section id="trust">` anchor

- [ ] **Step 1: Append CSS**

```css
/* ── LANDING: Trust Signals ────────────────────────── */
.hl-trust-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  max-width: 1080px;
  margin: 0 auto;
}
@media (min-width: 1024px) {
  .hl-trust-grid { grid-template-columns: repeat(3, 1fr); gap: 20px; }
}
.hl-trust-item {
  padding: 24px 20px;
  background: rgba(255,255,255,.04);
  border: 1px solid rgba(255,255,255,.08);
  border-radius: 14px;
}
.hl-trust-icon {
  font-size: 36px;
  display: block;
  margin-bottom: 14px;
}
.hl-trust-title {
  font-weight: 700;
  font-size: 15px;
  color: #fff;
  margin-bottom: 6px;
}
.hl-trust-desc {
  font-size: 13px;
  line-height: 1.5;
  color: rgba(255,255,255,.6);
}
```

- [ ] **Step 2: Insert Trust Signals section di landing.php**

Insert SETELAH `</section>` Founder Story (yang baru dibuat Task 2.2) dan SEBELUM `<section id="harga">`:

```html
<section class="section" id="trust" style="background:rgba(255,255,255,.018)">
  <div class="section-head" style="text-align:center;margin-bottom:40px;">
    <h2 style="font-size:32px;font-weight:800;margin-bottom:12px;">Kenapa Percaya LAMASY</h2>
    <p style="color:rgba(255,255,255,.6);max-width:600px;margin:0 auto;">Built untuk reliability dan transparency — bukan janji marketing.</p>
  </div>

  <div class="hl-trust-grid">
    <div class="hl-trust-item">
      <span class="hl-trust-icon">🔐</span>
      <div class="hl-trust-title">Multi-Tenant Isolated</div>
      <div class="hl-trust-desc">Data outlet kamu, hanya kamu yang akses. Tenant scope di setiap query.</div>
    </div>
    <div class="hl-trust-item">
      <span class="hl-trust-icon">📊</span>
      <div class="hl-trust-title">Audit Log Lengkap</div>
      <div class="hl-trust-desc">Semua aksi tercatat dengan timestamp. Transparan untuk owner + accountant.</div>
    </div>
    <div class="hl-trust-item">
      <span class="hl-trust-icon">🛡️</span>
      <div class="hl-trust-title">HTTPS + Encrypted</div>
      <div class="hl-trust-desc">Koneksi aman dengan TLS 1.3. Password hashed dengan bcrypt.</div>
    </div>
    <div class="hl-trust-item">
      <span class="hl-trust-icon">🇮🇩</span>
      <div class="hl-trust-title">Server di Indonesia</div>
      <div class="hl-trust-desc">Hostinger SG/ID region. Low latency, support lokal jam kerja.</div>
    </div>
    <div class="hl-trust-item">
      <span class="hl-trust-icon">🤝</span>
      <div class="hl-trust-title">Bayar Sesuai Pakai</div>
      <div class="hl-trust-desc">Coin-based — no monthly lock-in. Stop topup, stop biaya.</div>
    </div>
    <div class="hl-trust-item">
      <span class="hl-trust-icon">💬</span>
      <div class="hl-trust-title">Support WA Langsung</div>
      <div class="hl-trust-desc">Founder responsif, balas pribadi. Bukan chatbot atau ticket system.</div>
    </div>
  </div>
</section>
```

- [ ] **Step 3: Verify**

Run:
```bash
grep -cE 'id="trust"|hl-trust-item' landing.php
```
Expected: `≥ 7` (section anchor + 6 items)

- [ ] **Step 4: Commit + push + deploy**

```bash
git add landing.php harpy-erp.css
git commit -m "feat(landing): add Trust Signals section + CSS

6 trust signals: multi-tenant, audit log, HTTPS, server ID,
pay-per-use, founder WA support. Grid 2/3 kolom responsive.
Position: setelah Founder Story, sebelum Harga."
git push origin main
```

- [ ] **Step 5: Visual verify**

Open `https://lamasy.harpy.id/` → scroll → section "Kenapa Percaya LAMASY" muncul dengan 6 cards.

---

## Task 2.4: Refine Hero + Pricing + FAQ

**Files:**
- Modify: `landing.php:899-905` (hero buttons area — add micro-trust)
- Modify: `landing.php` Harga section (sekitar line 1460-1530) — add framing
- Modify: `landing.php` FAQ section (line 1650-1731) — add 3 entries

**Interfaces:**
- Consumes: existing structure
- Produces: refined hero + pricing + FAQ

- [ ] **Step 1: Read current hero buttons area**

Run: `sed -n '895,920p' /Users/rizky/Documents/lamasy/landing.php`

Catat exact structure dari `<div class="hero-btns">`.

- [ ] **Step 2: Add micro-trust line di bawah hero CTA**

Find `<div class="hero-btns">...</div>` block di landing.php. Tambahkan SETELAH closing tag:

```html
<div style="margin-top:20px;font-size:13px;color:rgba(255,255,255,.5);text-align:center;">
  ✓ Tanpa kartu kredit · ✓ Setup 5 menit · ✓ Cancel anytime
</div>
```

- [ ] **Step 3: Read pricing section**

Run: `sed -n '1460,1534p' /Users/rizky/Documents/lamasy/landing.php`

Identify spot untuk insert framing line.

- [ ] **Step 4: Add pricing framing di section Harga**

Cari heading section Harga (`<h2>` atau similar) di landing.php sekitar line 1460. Tambahkan sub-line setelah heading utama:

```html
<p style="text-align:center;color:rgba(53,232,213,.9);font-weight:600;margin-bottom:8px;font-size:15px;">
  Trial 7 hari gratis · Lanjut bayar sesuai pakai via Coin
</p>
<p style="text-align:center;color:rgba(255,255,255,.5);max-width:600px;margin:0 auto 32px;font-size:13px;">
  vs langganan bulanan kompetitor Rp 500K+/bulan — LAMASY bayar sesuai pakai, bisnis slow biaya tetap rendah.
</p>
```

Adjust selector berdasarkan struktur existing — kalau heading wrapped dalam `<div class="section-head">`, insert di dalam div tersebut.

- [ ] **Step 5: Add 3 FAQ entries**

Read FAQ section: `sed -n '1650,1731p' /Users/rizky/Documents/lamasy/landing.php`. Identify struktur (probably `<details>` atau div pairs).

Insert 3 new FAQ items SEBELUM closing tag section FAQ. Adapt markup ke struktur existing — kalau pakai `<details>`:

```html
<details class="faq-item">
  <summary>Apa beda LAMASY dengan kompetitor seperti Smartlink?</summary>
  <p>LAMASY focus AI-first (briefing harian, smart notif, churn analysis) + bayar coin (no monthly lock-in) + multi-tenant SaaS proper (data isolated per outlet). Cocok untuk owner yang mau pakai teknologi modern tanpa commitment langganan.</p>
</details>

<details class="faq-item">
  <summary>Apakah data saya aman di LAMASY?</summary>
  <p>Multi-tenant isolated (data outlet kamu hanya bisa diakses akun kamu), HTTPS + TLS 1.3 encrypted, password hashed bcrypt, audit log lengkap untuk setiap aksi, backup harian otomatis di Hostinger.</p>
</details>

<details class="faq-item">
  <summary>Bagaimana kalau saya berhenti pakai LAMASY?</summary>
  <p>Tidak ada cancel process — kamu bayar sesuai pakai. Stop topup coin = stop biaya. Data tetap accessible (read-only). Kalau mau full export: HQ → Export Data → download semua data outlet kamu dalam format CSV/SQL. No vendor lock-in.</p>
</details>
```

Kalau struktur existing pakai div + class custom, copy pattern existing dan ganti content.

- [ ] **Step 6: Verify**

Run:
```bash
grep -cE "Tanpa kartu kredit|sesuai pakai via Coin|Apa beda LAMASY|berhenti pakai LAMASY" landing.php
```
Expected: `≥ 4`

- [ ] **Step 7: Commit + push + deploy**

```bash
git add landing.php
git commit -m "feat(landing): refine hero micro-trust + pricing framing + FAQ +3

- Hero: tambah '✓ Tanpa kartu kredit · ✓ Setup 5 menit · ✓ Cancel anytime'
- Pricing: framing trial vs paid + competitor comparison
- FAQ: tambah 3 entry (beda kompetitor, data security, cara berhenti)"
git push origin main
```

- [ ] **Step 8: Visual verify**

Open `https://lamasy.harpy.id/` → hero punya micro-trust line under CTA → pricing punya framing teal → FAQ punya 3 entry baru yang expandable.

---

# PHASE 3 — Performance

Target effort: 3-4 jam. Ship: Lighthouse mobile ≥ 85, LCP < 2.5s.

## Task 3.1: Baseline Lighthouse audit

**Files:**
- Create: `docs/perf-baseline-2026-06-23.md`

**Interfaces:**
- Consumes: production landing page state
- Produces: baseline metrics dokumen untuk comparison post-optimize

- [ ] **Step 1: Run Lighthouse audit via Chrome DevTools**

User action:
1. Open `https://lamasy.harpy.id/` di Chrome (incognito untuk skip extensions)
2. F12 → Lighthouse tab
3. Mode: Navigation
4. Device: Mobile + Desktop (run dua kali)
5. Categories: Performance, SEO, Accessibility, Best Practices
6. Click "Analyze page load"

Atau pakai PageSpeed Insights online: `https://pagespeed.web.dev/analysis?url=https%3A%2F%2Flamasy.harpy.id%2F`

- [ ] **Step 2: Record metrics ke baseline doc**

Create `/Users/rizky/Documents/lamasy/docs/perf-baseline-2026-06-23.md`:

```markdown
# Landing Page Performance Baseline
**Date:** 2026-06-23 WIB
**URL:** https://lamasy.harpy.id/
**After Phase 2 (content sections live)**

## Lighthouse Mobile

- Performance: __
- SEO: __
- Accessibility: __
- Best Practices: __

### Core Web Vitals
- LCP (Largest Contentful Paint): __ s
- FCP (First Contentful Paint): __ s
- CLS (Cumulative Layout Shift): __
- TBT (Total Blocking Time): __ ms
- Speed Index: __ s

### Network
- Total page size: __ KB
- Total requests: __
- HTML size: __ KB
- CSS size: __ KB
- JS size: __ KB
- Image size: __ KB
- Font size: __ KB

## Lighthouse Desktop

- Performance: __
- Same metrics di atas

## Issues identified (top 5)

1. __
2. __
3. __
4. __
5. __

## Target setelah Phase 3

- Lighthouse mobile ≥ 85
- LCP < 2.5s
- Page size < 150KB
```

User fill in setelah run Lighthouse.

- [ ] **Step 3: Commit baseline doc**

```bash
git add docs/perf-baseline-2026-06-23.md
git commit -m "docs(perf): landing page baseline pre-optimization

Lighthouse mobile + desktop scores recorded. Target post-Phase 3:
mobile ≥85, LCP <2.5s, page <150KB."
git push origin main
```

- [ ] **Step 4: Identify top issues**

Berdasarkan Lighthouse "Diagnostics" + "Opportunities" section, list top 5 perbaikan. Common items expected:
- Eliminate render-blocking resources (Google Fonts)
- Properly size images
- Defer offscreen images
- Enable text compression (Cloudflare Brotli)
- Reduce unused CSS

Update baseline doc dengan issues identified.

---

## Task 3.2: Image lazy load + dimensions

**Files:**
- Modify: `landing.php` — semua `<img>` tag

**Interfaces:**
- Consumes: existing img references
- Produces: optimized img loading

- [ ] **Step 1: Audit semua img di landing.php**

Run:
```bash
grep -nE "<img\s" landing.php
```
List semua img refs + line numbers.

- [ ] **Step 2: Add width/height + loading lazy to each img**

Untuk setiap `<img>`, tambahkan:
- `width="..."` dan `height="..."` (intrinsic dimensions)
- `loading="lazy"` (kalau below fold, bukan di hero)
- `loading="eager"` + `fetchpriority="high"` (kalau hero/above fold, untuk LCP)

Contoh transform:

Before:
```html
<img src="/assets/logo.png" alt="LAMASY" style="height:36px; vertical-align:middle; margin-right:8px;">
```

After:
```html
<img src="/assets/logo.png?v=<?= @filemtime(__DIR__.'/assets/logo.png') ?: '3' ?>" alt="LAMASY" width="36" height="36" loading="eager" fetchpriority="high" style="vertical-align:middle; margin-right:8px;">
```

(Pakai 36×36 karena style display height:36px; aspect ratio 1:1 untuk logo.png yang 256×256.)

Untuk founder.jpg yang ditambah di Task 2.2 — sudah punya `loading="lazy"` dari spec.

- [ ] **Step 3: Verify**

Run:
```bash
grep -cE '<img.*loading=' landing.php
```
Expected: count matches jumlah img tags total

```bash
grep -cE '<img.*width=.*height=' landing.php
```
Expected: same as above (semua img punya dimensions)

- [ ] **Step 4: Commit + push + deploy**

```bash
git add landing.php
git commit -m "perf(landing): add loading + dimensions ke semua img

Hero img: loading=eager + fetchpriority=high untuk LCP.
Below-fold img: loading=lazy. Width/height attribute zero CLS."
git push origin main
```

---

## Task 3.3: Font loading optimization

**Files:**
- Modify: `landing.php:31-33` (Google Fonts link)

**Interfaces:**
- Consumes: Plus Jakarta Sans + DM Mono dari Google Fonts
- Produces: optimized non-blocking font load

- [ ] **Step 1: Audit font usage di landing.php**

Run:
```bash
grep -ioE "font-family:\s*[^;]+|font-weight:\s*[0-9]+" landing.php | sort -u
```

Catat semua font-family + weight yang dipakai. Likely:
- Plus Jakarta Sans: weights 400, 600, 700, 800 (drop 300, 500 kalau ga dipakai)
- DM Mono: cek apakah dipakai — kalau ga, drop entirely

- [ ] **Step 2: Replace Google Fonts link dengan async load pattern**

Cari di landing.php:
```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
```

Replace dengan (assume DM Mono dropped, Jakarta Sans 400/600/700/800 only):
```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap"></noscript>
```

**Kalau DM Mono masih dipakai** (verify Step 1), keep dengan weight 400 only.

- [ ] **Step 3: Verify**

Run:
```bash
grep -oE "family=[^\"&]+" landing.php
```
Expected output minus 300, 500 weights.

- [ ] **Step 4: Commit + push + deploy**

```bash
git add landing.php
git commit -m "perf(landing): font loading async + drop unused weights

- Drop 300, 500 weight (tidak dipakai)
- Drop DM Mono (kalau memang tidak dipakai — re-add kalau perlu)
- Async load pattern (preload + media=print onload trick)
- Saves ~40-60KB font payload + unblocks render"
git push origin main
```

- [ ] **Step 5: Re-run Lighthouse + record improvement**

Update `docs/perf-baseline-2026-06-23.md` dengan section "After Task 3.3" — expected LCP improvement 200-500ms.

---

## Task 3.4: CSS audit + critical inline

**Files:**
- Modify: `landing.php` (CSS link strategy)

**Interfaces:**
- Consumes: existing landing.php style block + harpy-erp.css
- Produces: optimized CSS delivery

- [ ] **Step 1: Audit apakah landing.php pakai class dari harpy-erp.css**

Run:
```bash
grep -oE 'class="[^"]*"' landing.php | grep -oE 'hl-[a-z-]+' | sort -u
```

Kalau output kosong: landing.php TIDAK pakai class harpy-erp.css → bisa drop reference.
Kalau output ada items: landing pakai class → keep reference, atau ekstrak relevant CSS ke landing-specific file.

- [ ] **Step 2: Decision branch**

**IF landing.php tidak pakai harpy-erp.css class (skip drop):**

Cari di landing.php:
```html
<link rel="stylesheet" href="/harpy-erp.css?v=...">
```

Kalau ada, hapus baris itu. (Verify dulu via grep — kemungkinan landing.php memang ga reference harpy-erp.css karena dia pakai inline `<style>` sendiri.)

**IF landing.php pakai harpy-erp.css class (Task 2.x added hl-usecase/hl-founder/hl-trust):**

Keep reference. Tapi ke landing.php style block, pastikan critical above-fold CSS (hero + nav) ada di inline `<style>` di `<head>`. harpy-erp.css di-load sebagai non-blocking via async pattern:

```html
<link rel="preload" as="style" href="/harpy-erp.css?v=<?= @filemtime(__DIR__.'/harpy-erp.css') ?: '1' ?>">
<link rel="stylesheet" href="/harpy-erp.css?v=<?= @filemtime(__DIR__.'/harpy-erp.css') ?: '1' ?>" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="/harpy-erp.css?v=<?= @filemtime(__DIR__.'/harpy-erp.css') ?: '1' ?>"></noscript>
```

Karena Phase 2 udah tambah hl-usecase/founder/trust ke harpy-erp.css, landing memang pakai → keep dengan async load.

- [ ] **Step 3: Verify**

Run:
```bash
grep -E 'rel="stylesheet"|rel="preload"' landing.php | head -10
```
Expected: stylesheet + preload + noscript fallback.

- [ ] **Step 4: Commit + push + deploy**

```bash
git add landing.php
git commit -m "perf(landing): async load harpy-erp.css dengan preload

CSS ga blocking render lagi. Kombinasi preload + media=print onload
+ noscript fallback — best practice untuk non-critical CSS."
git push origin main
```

- [ ] **Step 5: Re-run Lighthouse**

Update baseline doc dengan "After Task 3.4" metrics. Expected: TBT decrease, FCP improvement.

---

## Task 3.5: Service Worker skip landing + Cloudflare config

**Files:**
- Modify: `sw-tenant.js` — add skip pattern

**Interfaces:**
- Consumes: existing SW
- Produces: SW yang gak cache landing page

- [ ] **Step 1: Read sw-tenant.js skip list**

Run: `sed -n '1,80p' /Users/rizky/Documents/lamasy/sw-tenant.js`

Identify skip pattern logic (probably `if (url.pathname.startsWith('/pelanggan')...) return;`).

- [ ] **Step 2: Add skip untuk landing page**

Di fetch handler sw-tenant.js, di awal handler, tambahkan:

```javascript
// Skip landing page — selalu fresh dari server
if (url.pathname === '/' || url.pathname === '/landing.php') {
  return; // network-only, no cache
}
```

(Adjust persis sesuai pattern existing — kalau pattern existing pakai `event.respondWith()`, return early sebelum itu.)

- [ ] **Step 3: Verify**

Run:
```bash
grep -cE "landing\.php|pathname === '/'" sw-tenant.js
```
Expected: `≥ 1`

- [ ] **Step 4: Commit + push + deploy**

```bash
git add sw-tenant.js
git commit -m "perf(landing): SW skip landing page (network-only)

Landing content sering update, ga perlu di-cache. Visitor selalu
dapat fresh HTML. App pages (/dashboard /pos dll) tetap di-cache."
git push origin main
```

- [ ] **Step 5: Cloudflare panel config (user manual action)**

User action:
1. Login Cloudflare dashboard
2. Site → lamasy.harpy.id
3. Speed → Optimization:
   - **Auto Minify:** Enable HTML, CSS, JS
   - **Brotli:** Enable
   - **Early Hints:** Enable (kalau available)
4. Speed → Tiered Cache: Enable
5. Caching → Configuration:
   - Browser Cache TTL: 4 hours (default OK)

Verify via curl:
```bash
curl -sI https://lamasy.harpy.id/ | grep -iE "cf-cache-status|content-encoding"
```
Expected: `content-encoding: br` (Brotli active) atau `gzip` (fallback).

- [ ] **Step 6: Re-run final Lighthouse + record**

Update baseline doc dengan "Final Phase 3" metrics. Expected: mobile ≥ 85, LCP < 2.5s, page size < 150KB.

```bash
git add docs/perf-baseline-2026-06-23.md
git commit -m "docs(perf): final Phase 3 Lighthouse metrics

Mobile ≥85, LCP <2.5s, page <150KB achieved (or document gap)."
git push origin main
```

---

# PHASE 4 — Conversion Polish

Target effort: 3-4 jam. Ship: friction reduction + analytics tracking.

## Task 4.1: Sticky Mobile CTA Bar

**Files:**
- Modify: `landing.php` (append `<div>` sebelum `</body>` + inline JS)
- Modify: `harpy-erp.css` (append CSS)

**Interfaces:**
- Consumes: WA contact number, `/register` URL
- Produces: `#hlStickyCta` DOM element

- [ ] **Step 1: Append CSS ke harpy-erp.css**

```css
/* ── LANDING: Sticky Mobile CTA ─────────────────────── */
.hl-sticky-cta {
  position: fixed;
  bottom: 0; left: 0; right: 0;
  background: #0F1C3A;
  padding: 12px 16px;
  display: flex;
  gap: 12px;
  box-shadow: 0 -4px 20px rgba(0,0,0,.5);
  transform: translateY(100%);
  transition: transform .3s ease;
  z-index: 100;
  border-top: 1px solid rgba(53,232,213,.15);
}
.hl-sticky-cta.show { transform: translateY(0); }
.hl-sticky-wa, .hl-sticky-primary {
  flex: 1;
  padding: 12px;
  border-radius: 10px;
  text-align: center;
  text-decoration: none;
  font-weight: 600;
  font-size: 14px;
  transition: opacity .2s;
}
.hl-sticky-wa { background: rgba(255,255,255,.08); color: #fff; }
.hl-sticky-primary { background: #35E8D5; color: #0F1C3A; font-weight: 700; }
.hl-sticky-wa:hover, .hl-sticky-primary:hover { opacity: .9; }
@media (min-width: 768px) { .hl-sticky-cta { display: none; } }
```

- [ ] **Step 2: Insert sticky CTA DOM + JS di landing.php**

Cari `</body>` di landing.php (line akhir, sekitar 1830-1837). Insert SEBELUM `</body>`:

```html
<!-- Sticky Mobile CTA -->
<div class="hl-sticky-cta" id="hlStickyCta">
  <a href="https://wa.me/628112345678?text=Halo%20saya%20mau%20tanya%20tentang%20LAMASY" class="hl-sticky-wa" rel="noopener" target="_blank">💬 Tanya WA</a>
  <a href="/register" class="hl-sticky-primary">🚀 Coba Gratis →</a>
</div>

<script>
(function(){
  var sticky = document.getElementById('hlStickyCta');
  if (!sticky) return;
  window.addEventListener('scroll', function() {
    if (window.scrollY > 600) sticky.classList.add('show');
    else sticky.classList.remove('show');
  }, { passive: true });
})();
</script>
```

**Note:** Ganti `628112345678` dengan nomor WA support real LAMASY.

- [ ] **Step 3: Verify**

Run:
```bash
grep -cE "hlStickyCta|hl-sticky-cta|Tanya WA" landing.php
```
Expected: `≥ 4`

- [ ] **Step 4: Commit + push + deploy**

```bash
git add landing.php harpy-erp.css
git commit -m "feat(landing): sticky mobile CTA bar (WA + Coba Gratis)

Muncul setelah scroll past hero (600px). Mobile only (<768px).
Vanilla JS + CSS, no framework. Total ~600B minified."
git push origin main
```

- [ ] **Step 5: Visual verify di mobile viewport**

Open Chrome DevTools → Toggle device toolbar → set ke iPhone 12. Navigate ke landing → scroll past hero → sticky bar muncul di bottom dengan 2 tombol.

---

## Task 4.2: Beta Banner + JSON counter

**Files:**
- Modify: `landing.php` (insert banner di atas hero)
- Modify: `harpy-erp.css` (append CSS)
- Create: `assets/beta-slots.json`

**Interfaces:**
- Consumes: JSON file remote
- Produces: `#hlBetaSlots` span element with slot count

- [ ] **Step 1: Create assets/beta-slots.json**

Path: `/Users/rizky/Documents/lamasy/assets/beta-slots.json`

```json
{
  "total": 50,
  "remaining": 47,
  "updated_at": "2026-06-23"
}
```

- [ ] **Step 2: Append CSS**

```css
/* ── LANDING: Beta Banner ────────────────────────────── */
.hl-beta-banner {
  background: linear-gradient(135deg, rgba(53,232,213,.12), rgba(99,102,241,.12));
  border-bottom: 1px solid rgba(53,232,213,.25);
  padding: 10px 16px;
  text-align: center;
  font-size: 13px;
  color: rgba(255,255,255,.85);
  line-height: 1.5;
}
.hl-beta-banner strong { color: #35E8D5; }
.hl-beta-banner span#hlBetaSlots {
  font-weight: 700;
  color: #fff;
  background: rgba(53,232,213,.15);
  padding: 2px 8px;
  border-radius: 4px;
}
@media (max-width: 480px) {
  .hl-beta-banner { font-size: 12px; padding: 8px 12px; }
}
```

- [ ] **Step 3: Insert banner di landing.php (atas hero, dalam `<body>`)**

Cari `<body>` di landing.php. Insert SETELAH `<body>` (sebelum nav atau hero):

```html
<div class="hl-beta-banner">
  🌱 <strong>Beta Access Program</strong> · 50 slot early adopter pertama dapat bonus 100K coin (≈3 bulan AI briefing gratis). Sisa: <span id="hlBetaSlots">47</span> slot
</div>

<script>
(function(){
  fetch('/assets/beta-slots.json?t=' + Date.now())
    .then(function(r){ return r.json(); })
    .then(function(d){
      var el = document.getElementById('hlBetaSlots');
      if (el && d.remaining !== undefined) el.textContent = d.remaining;
    })
    .catch(function(){ /* fallback to static 47 */ });
})();
</script>
```

- [ ] **Step 4: Verify**

Run:
```bash
grep -cE "hl-beta-banner|hlBetaSlots|Beta Access Program" landing.php
test -f assets/beta-slots.json && echo "JSON exists"
```
Expected: count ≥ 3 + "JSON exists"

- [ ] **Step 5: Commit + push + deploy**

```bash
git add landing.php harpy-erp.css assets/beta-slots.json
git commit -m "feat(landing): beta access banner dengan slot counter dari JSON

Banner kecil di top landing. Counter dari assets/beta-slots.json,
user update manual (atau auto-decrement webhook di future). Honest
urgency — no fake countdown."
git push origin main
```

---

## Task 4.3: Exit-Intent Modal + Lead Capture API

**Files:**
- Modify: `landing.php` (insert modal + JS)
- Modify: `harpy-erp.css` (append CSS)
- Create: `api/lead.php`
- Create: `superadmin/sql/leads_migration.sql`

**Interfaces:**
- Consumes: email input
- Produces: `hl_leads` table row per submission

- [ ] **Step 1: Create migration SQL**

Path: `/Users/rizky/Documents/lamasy/superadmin/sql/leads_migration.sql`

```sql
-- Lead capture dari exit-intent modal landing page
CREATE TABLE IF NOT EXISTS hl_leads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  source VARCHAR(50) DEFAULT 'exit_intent',
  user_agent VARCHAR(500),
  ip_address VARCHAR(45),
  referrer VARCHAR(500),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_email (email),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

User run via phpMyAdmin nanti.

- [ ] **Step 2: Create api/lead.php**

Path: `/Users/rizky/Documents/lamasy/api/lead.php`

```php
<?php
// api/lead.php — Lead capture endpoint dari landing page exit-intent
//
// POST { email, source } → INSERT hl_leads

define('ROOT', dirname(__DIR__));
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$email = trim($input['email'] ?? '');
$source = preg_replace('/[^a-z_]/', '', $input['source'] ?? 'exit_intent');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Email tidak valid']);
    exit;
}

if (strlen($email) > 255) {
    http_response_code(400);
    echo json_encode(['error' => 'Email terlalu panjang']);
    exit;
}

try {
    $db = Database::get();
    $stmt = $db->prepare(
        "INSERT INTO hl_leads (email, source, user_agent, ip_address, referrer)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $email,
        $source,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500),
    ]);
    echo json_encode(['ok' => true, 'message' => 'Terima kasih! Cek email kamu untuk panduan.']);
} catch (Throwable $e) {
    error_log('[api/lead.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Gagal simpan. Coba lagi sebentar.']);
}
```

- [ ] **Step 3: Append CSS untuk modal**

```css
/* ── LANDING: Exit-Intent Modal ──────────────────────── */
.hl-exit-modal {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.7);
  z-index: 200;
  display: none;
  align-items: center;
  justify-content: center;
  padding: 20px;
  backdrop-filter: blur(4px);
}
.hl-exit-modal.show { display: flex; }
.hl-exit-content {
  max-width: 460px;
  width: 100%;
  background: #0F1C3A;
  border: 1px solid rgba(53,232,213,.25);
  border-radius: 16px;
  padding: 32px;
  position: relative;
}
.hl-exit-close {
  position: absolute;
  top: 12px; right: 16px;
  background: none;
  border: none;
  color: rgba(255,255,255,.5);
  font-size: 28px;
  cursor: pointer;
  line-height: 1;
}
.hl-exit-content h3 {
  margin: 0 0 12px;
  font-size: 22px;
  font-weight: 800;
  color: #fff;
}
.hl-exit-content p {
  margin: 0 0 20px;
  color: rgba(255,255,255,.7);
  font-size: 14px;
  line-height: 1.5;
}
.hl-exit-content input[type="email"] {
  width: 100%;
  padding: 12px 14px;
  background: rgba(255,255,255,.06);
  border: 1.5px solid rgba(255,255,255,.1);
  border-radius: 8px;
  color: #fff;
  font-size: 14px;
  margin-bottom: 12px;
  box-sizing: border-box;
}
.hl-exit-content input[type="email"]:focus {
  outline: none;
  border-color: #35E8D5;
}
.hl-exit-content button[type="submit"] {
  width: 100%;
  padding: 12px;
  background: #35E8D5;
  color: #0F1C3A;
  border: none;
  border-radius: 8px;
  font-weight: 700;
  cursor: pointer;
  font-size: 14px;
}
.hl-exit-skip {
  display: block;
  margin-top: 12px;
  text-align: center;
  color: rgba(255,255,255,.5);
  font-size: 13px;
  text-decoration: none;
}
.hl-exit-success {
  color: #35E8D5;
  font-weight: 600;
  text-align: center;
  padding: 20px 0;
}
```

- [ ] **Step 4: Insert modal DOM + JS di landing.php**

Sebelum `</body>` (setelah sticky CTA dari Task 4.1):

```html
<!-- Exit-Intent Modal (desktop only) -->
<div class="hl-exit-modal" id="hlExitModal">
  <div class="hl-exit-content">
    <button class="hl-exit-close" type="button" onclick="document.getElementById('hlExitModal').classList.remove('show')">&times;</button>
    <h3>Tunggu, mau saya kirim panduan gratis?</h3>
    <p>Checklist 7 step setup laundry digital — PDF + link demo video, langsung ke email kamu.</p>
    <form id="hlExitForm">
      <input type="email" name="email" placeholder="email@kamu.com" required>
      <button type="submit">Kirim ke email saya</button>
    </form>
    <a href="#" class="hl-exit-skip" onclick="document.getElementById('hlExitModal').classList.remove('show');return false;">Lain kali</a>
  </div>
</div>

<script>
(function(){
  // Skip mobile
  if (window.innerWidth < 1024) return;
  // Skip kalau udah tampil di session ini
  if (sessionStorage.getItem('hl_exit_shown')) return;

  var modal = document.getElementById('hlExitModal');
  var form = document.getElementById('hlExitForm');
  if (!modal || !form) return;

  // Trigger: mouse leave viewport ke atas
  document.addEventListener('mouseout', function(e) {
    if (e.clientY <= 0 && !sessionStorage.getItem('hl_exit_shown')) {
      modal.classList.add('show');
      sessionStorage.setItem('hl_exit_shown', '1');
    }
  });

  // Form submit
  form.addEventListener('submit', function(e) {
    e.preventDefault();
    var email = form.email.value.trim();
    if (!email) return;
    fetch('/api/lead.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: email, source: 'exit_intent' })
    })
    .then(function(r){ return r.json(); })
    .then(function(d) {
      var content = modal.querySelector('.hl-exit-content');
      if (d.ok) {
        content.innerHTML = '<div class="hl-exit-success">✓ Terima kasih! Cek email kamu sebentar lagi.</div>';
        setTimeout(function(){ modal.classList.remove('show'); }, 2500);
      } else {
        alert(d.error || 'Gagal kirim. Coba lagi.');
      }
    })
    .catch(function(){ alert('Gagal kirim. Cek koneksi internet.'); });
  });
})();
</script>
```

- [ ] **Step 5: Run migration di production (user manual via phpMyAdmin)**

User action:
1. phpMyAdmin → DB `u269895997_harpy_master` → SQL tab
2. Paste isi `superadmin/sql/leads_migration.sql`
3. Run → verify `hl_leads` table created

- [ ] **Step 6: Verify endpoint accessible**

```bash
curl -X POST -H "Content-Type: application/json" -d '{"email":"test@test.com"}' https://lamasy.harpy.id/api/lead.php
```
Expected: `{"ok":true,"message":"..."}`

- [ ] **Step 7: Commit + push + deploy**

```bash
git add landing.php harpy-erp.css api/lead.php superadmin/sql/leads_migration.sql
git commit -m "feat(landing): exit-intent modal + lead capture API

- Modal triggers ke mouse leave viewport top (desktop ≥1024px only)
- Sekali per session (sessionStorage)
- Lead capture POST /api/lead.php → INSERT hl_leads
- Email validation + length limit + error handling via error_log
- Migration di superadmin/sql/leads_migration.sql (user run manual)"
git push origin main
```

- [ ] **Step 8: E2E test via MCP browser**

Run via MCP browser:
1. Navigate `https://lamasy.harpy.id/`
2. Trigger exit intent (mouse to top of viewport) — atau force-trigger via console: `document.getElementById('hlExitModal').classList.add('show')`
3. Submit form dengan test email
4. Verify success message muncul
5. Check phpMyAdmin: row baru di `hl_leads`

---

## Task 4.4: Mini-FAQ accordion di section Harga

**Files:**
- Modify: `landing.php` Harga section (sekitar line 1460-1534) — insert mini-FAQ
- Modify: `harpy-erp.css` (append minimal CSS)

**Interfaces:**
- Consumes: native HTML `<details>` element
- Produces: 3-question accordion

- [ ] **Step 1: Append CSS**

```css
/* ── LANDING: Mini FAQ ─────────────────────────────── */
.hl-mini-faq {
  max-width: 720px;
  margin: 32px auto 0;
}
.hl-mini-faq details {
  background: rgba(255,255,255,.04);
  border: 1px solid rgba(255,255,255,.08);
  border-radius: 10px;
  padding: 14px 18px;
  margin-bottom: 10px;
}
.hl-mini-faq summary {
  font-weight: 600;
  font-size: 14px;
  color: #fff;
  cursor: pointer;
  list-style: none;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.hl-mini-faq summary::after {
  content: '+';
  color: #35E8D5;
  font-size: 20px;
  font-weight: 400;
}
.hl-mini-faq details[open] summary::after { content: '−'; }
.hl-mini-faq summary::-webkit-details-marker { display: none; }
.hl-mini-faq details p {
  margin: 12px 0 0;
  font-size: 13px;
  color: rgba(255,255,255,.7);
  line-height: 1.6;
}
```

- [ ] **Step 2: Insert mini-FAQ di section Harga**

Cari ending `</section>` Harga di landing.php (sekitar line 1534 yang sebelum next section). Insert SEBELUM closing tag:

```html
<div class="hl-mini-faq">
  <details>
    <summary>Apa yang terjadi setelah klik "Coba Gratis"?</summary>
    <p>Kamu register dengan nama outlet + email + password (5 menit). Setup outlet langsung selesai, bisa input order pertama dalam menit yang sama. Trial 7 hari otomatis aktif — no kartu kredit.</p>
  </details>
  <details>
    <summary>Apakah saya wajib bayar setelah trial 7 hari?</summary>
    <p>Tidak wajib. Kalau tidak topup coin setelah trial, akses dibatasi (view-only) tapi data tetap tersimpan. Anytime mau lanjut, topup coin → akses penuh lagi.</p>
  </details>
  <details>
    <summary>Bagaimana cara cancel atau export data?</summary>
    <p>Tidak ada cancel — kamu bayar sesuai pakai. Stop topup = stop biaya. Untuk export data: HQ → Export Data → download semua dalam CSV/SQL. No lock-in.</p>
  </details>
</div>
```

- [ ] **Step 3: Verify**

Run:
```bash
grep -cE "hl-mini-faq|Apa yang terjadi setelah" landing.php
```
Expected: `≥ 2`

- [ ] **Step 4: Commit + push + deploy**

```bash
git add landing.php harpy-erp.css
git commit -m "feat(landing): mini-FAQ accordion di section Harga

3 question native HTML <details>: setelah daftar, after trial,
cara cancel/export. Zero JS, accessible, lightweight."
git push origin main
```

---

## Task 4.5: Cloudflare Web Analytics + custom events

**Files:**
- Modify: `landing.php` (head section — add beacon)

**Interfaces:**
- Consumes: Cloudflare CDN
- Produces: pageview + event tracking

- [ ] **Step 1: Setup Cloudflare Web Analytics (user manual)**

User action:
1. Cloudflare dashboard → Analytics & Logs → Web Analytics
2. Add a site → masukin `lamasy.harpy.id`
3. Copy beacon snippet (looks like):
   ```html
   <!-- Cloudflare Web Analytics -->
   <script defer src='https://static.cloudflareinsights.com/beacon.min.js' data-cf-beacon='{"token": "YOUR_TOKEN_HERE"}'></script>
   ```
4. Catat token

- [ ] **Step 2: Insert beacon di landing.php head**

Sebelum `</head>`, insert:

```html
<!-- Cloudflare Web Analytics -->
<script defer src='https://static.cloudflareinsights.com/beacon.min.js' data-cf-beacon='{"token": "YOUR_TOKEN_HERE"}'></script>
```

User ganti `YOUR_TOKEN_HERE` dengan token dari Step 1.

- [ ] **Step 3: Add custom event tracking di JS yang udah ada**

Modify JS untuk sticky CTA (Task 4.1) — tambah event tracking saat klik:

Cari di landing.php script block sticky CTA. Ubah:

```html
<a href="/register" class="hl-sticky-primary" onclick="if(window.cfBeacon)cfBeacon.track('click_register_sticky')">🚀 Coba Gratis →</a>
```

Tambahkan tracking ke CTA hero juga. Cari hero CTA primary button, tambah `onclick="if(window.cfBeacon)cfBeacon.track('click_register_hero')"`.

Tambahkan tracking ke pricing CTA: `onclick="if(window.cfBeacon)cfBeacon.track('click_register_pricing')"`.

Tambahkan tracking WA: `onclick="if(window.cfBeacon)cfBeacon.track('click_wa')"`.

Tambahkan scroll depth tracking (script terpisah sebelum `</body>`):

```html
<script>
(function(){
  var milestones = { 25: false, 50: false, 75: false, 100: false };
  window.addEventListener('scroll', function() {
    var pct = Math.round((window.scrollY + window.innerHeight) / document.body.scrollHeight * 100);
    for (var m in milestones) {
      if (pct >= parseInt(m) && !milestones[m]) {
        milestones[m] = true;
        if (window.cfBeacon) cfBeacon.track('scroll_' + m);
      }
    }
  }, { passive: true });
})();
</script>
```

**Note:** Cloudflare Web Analytics free tier tidak support custom events di RUM beacon. Custom events butuh **Cloudflare Workers Analytics Engine** (paid tier) atau alternative: pakai Plausible (paid $9/mo) atau Google Analytics 4 (free tapi privacy concern).

**Fallback:** kalau CF Web Analytics ga support custom events, fallback ke server-side analytics — POST tracking event ke `/api/track.php` (out of scope phase ini). Atau pakai standard CF pageview only, custom event skip.

Simplification: untuk Phase 4, deliver Cloudflare pageview tracking saja. Custom events di-defer ke phase tambahan kalau perlu.

- [ ] **Step 4: Verify beacon loaded**

```bash
curl -s https://lamasy.harpy.id/ | grep -o "cloudflareinsights"
```
Expected: `cloudflareinsights`

Open Chrome DevTools → Network tab → reload landing → cari request `beacon.min.js` → status 200.

- [ ] **Step 5: Commit + push + deploy**

```bash
git add landing.php
git commit -m "feat(landing): Cloudflare Web Analytics pageview tracking

Beacon di-load defer. Privacy-friendly, no cookie banner, no PII.
Track: page views + referrers + countries. Custom events di-defer
ke phase tambahan (CF free tier limitation)."
git push origin main
```

- [ ] **Step 6: Verify tracking in Cloudflare dashboard**

Wait 5-10 menit setelah deploy, lalu Cloudflare → Web Analytics → site → verify pageview muncul.

---

# Acceptance & Final Verification

## Per-Phase Acceptance

**Phase 1 done:**
- [ ] Sharing `lamasy.harpy.id` di WA shows OG preview (image + title + desc)
- [ ] Facebook Sharing Debugger: scrape success, all OG tags present
- [ ] Twitter Card Validator: preview shows summary_large_image
- [ ] Google Rich Results Test: all 4 schemas eligible
- [ ] `https://lamasy.harpy.id/sitemap.xml` returns 200
- [ ] `https://lamasy.harpy.id/robots.txt` returns 200
- [ ] No `<meta name="keywords">` di landing.php
- [ ] OG image (1200×630) accessible at `/assets/og-image.png`

**Phase 2 done:**
- [ ] 3 new sections live: Use Case (3 cards), Founder Story, Trust Signals (6 items)
- [ ] Disclaimer `*Skenario ilustrasi` visible di Use Case section
- [ ] Hero punya micro-trust line: `✓ Tanpa kartu kredit · ✓ Setup 5 menit · ✓ Cancel anytime`
- [ ] Pricing punya framing trial vs paid + competitor comparison
- [ ] FAQ tambah 3 entry baru (beda kompetitor, data security, cara berhenti)
- [ ] Semua section responsive mobile (`< 768px`) — stack 1 kolom

**Phase 3 done:**
- [ ] Lighthouse mobile ≥ 85 (recorded di `docs/perf-baseline-2026-06-23.md`)
- [ ] LCP < 2.5s
- [ ] Total page size < 150KB (excluding fonts)
- [ ] No render-blocking CSS/JS
- [ ] Semua `<img>` punya width/height + lazy load below-fold
- [ ] Cloudflare Auto-Minify + Brotli active

**Phase 4 done:**
- [ ] Sticky CTA bar muncul + hide proper di mobile (`< 768px`)
- [ ] Beta banner shows slot count dari `assets/beta-slots.json`
- [ ] Exit-intent modal tampil sekali per session di desktop
- [ ] Mini-FAQ accordion di Harga section expandable
- [ ] Cloudflare Web Analytics tracking pageview
- [ ] `hl_leads` table created di production, capture form berfungsi
- [ ] Zero false-claim copy di seluruh landing page

## Spec Coverage Check

| Spec Section | Covered by Task |
|--------------|-----------------|
| Phase 1: Meta tags | Task 1.1 |
| Phase 1: JSON-LD 4 schemas | Task 1.2 |
| Phase 1: sitemap + robots | Task 1.3 |
| Phase 1: OG image | Task 1.4 |
| Phase 2: Use Case Scenarios | Task 2.1 |
| Phase 2: Founder Story | Task 2.2 |
| Phase 2: Trust Signals | Task 2.3 |
| Phase 2: Refine hero/pricing/FAQ | Task 2.4 |
| Phase 3: Lighthouse baseline | Task 3.1 |
| Phase 3: Image optimization | Task 3.2 |
| Phase 3: Font loading | Task 3.3 |
| Phase 3: CSS strategy | Task 3.4 |
| Phase 3: SW + Cloudflare | Task 3.5 |
| Phase 4: Sticky CTA | Task 4.1 |
| Phase 4: Beta banner | Task 4.2 |
| Phase 4: Exit-intent modal | Task 4.3 |
| Phase 4: Mini-FAQ | Task 4.4 |
| Phase 4: Analytics | Task 4.5 |

All spec sections covered ✓

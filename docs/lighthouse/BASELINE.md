# Lighthouse Baseline — LAMASY Landing Page

**Date:** 24 Juni 2026
**URL:** https://lamasy.harpy.id/
**Tool:** Lighthouse 13.4.0 CLI (headless Chrome)
**Form Factor:** Mobile (Moto G Power, simulated 4G throttling)

---

## Scores

| Category | Score | Grade |
|----------|-------|-------|
| **Performance** | **94/100** | 🟢 Excellent |
| **Accessibility** | **90/100** | 🟢 Good |
| **Best Practices** | **96/100** | 🟢 Excellent |
| **SEO** | **100/100** | 🟢 Perfect |

## Core Web Vitals

| Metric | Value | Target | Status |
|--------|-------|--------|--------|
| **LCP** (Largest Contentful Paint) | 2.3s | < 2.5s | ✅ Good |
| **FCP** (First Contentful Paint) | 2.3s | < 1.8s | ⚠️ OK |
| **TBT** (Total Blocking Time) | 0ms | < 200ms | ✅ Excellent |
| **CLS** (Cumulative Layout Shift) | 0.064 | < 0.1 | ✅ Good |
| **TTI** (Time to Interactive) | 2.3s | < 3.8s | ✅ Good |
| **Speed Index** | 3.9s | < 3.4s | ⚠️ Close |

## Improvement Opportunities

### Quick Wins (Accessibility 90 → 95+)

1. **Add `<main>` landmark** — wrap main content in `<main>` element untuk screen readers
2. **Color contrast** — beberapa text:bg combination kurang kontras (kemungkinan footer/secondary text)
3. **Links rely on color** — tambahkan underline atau icon untuk distinguish links (selain warna)

### Performance (94 → 97+)

1. **Unused CSS rules** — prune CSS rules yang tidak terpakai (manual atau pakai PurgeCSS)
2. **Unminified CSS** — minify build-time (CF Auto-Minify deprecated). Atau accept — Brotli sudah handle bulk
3. **FCP 2.3s** — slightly tinggi. Bisa pakai inline critical CSS untuk above-the-fold

### Best Practices (96 → 100)

1. **Console errors** — ada error di browser console. Need investigation, kemungkinan dari third-party script atau missing resource

### SEO Already 100/100

No action needed — meta tags, structured data, mobile-friendly semua good.

---

## Context

- Mobile-first audit (mayoritas user laundry pakai HP)
- Simulated 4G connection (real Indonesia connection bisa lebih lambat di daerah terpencil)
- Production URL behind Cloudflare (Brotli + cache active)
- No Auto-Minify (CF deprecated Aug 2024) — Brotli compensates ~80%

## How to Re-Run

```bash
lighthouse https://lamasy.harpy.id/ \
  --output html --output json \
  --output-path "docs/lighthouse/landing-$(date +%Y-%m-%d)" \
  --chrome-flags="--headless" \
  --form-factor=mobile \
  --quiet
```

## Files

- `landing-2026-06-24.report.html` — full visual report (open in browser)
- `landing-2026-06-24.report.json` — raw data untuk programmatic analysis
- `BASELINE.md` — this summary

## Tracking Improvement

Baseline ini di-snapshot per-tanggal di repo. Re-run setiap quarter (atau setelah major refactor) untuk track improvement:

| Date | Performance | Accessibility | BP | SEO | LCP |
|------|-------------|---------------|----|----|-----|
| 2026-06-24 | 94 | 90 | 96 | 100 | 2.3s |
| _next_ | — | — | — | — | — |

---

## Conclusion

LAMASY landing page **production-ready** dengan score di atas baseline industri (90+). Top priorities kalau mau push ke 100:

1. Add `<main>` landmark (5 menit) — accessibility quick win
2. Investigate console errors (15 menit) — best practices
3. Inline critical CSS (1-2 jam) — FCP improvement

Lainnya optional — current state sudah sangat baik untuk launch.

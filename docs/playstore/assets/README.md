# Aset Grafis Play Store — LAMASY

| File | Ukuran | Untuk | Status |
|------|--------|-------|--------|
| `feature-graphic.png` | 1024×500 | Feature graphic (banner listing) | ✅ siap upload |
| `feature-graphic.svg` | — | Sumber (edit di browser/design tool, ekspor ulang bila perlu) | — |
| `app-icon-512.png` | 512×512 | App icon (high-res) | ✅ siap upload |

## Screenshot HP (WAJIB min 2, maks 8) — kamu tangkap sendiri

Play butuh screenshot dari **perangkat asli**. Spesifikasi:
- Rasio: potret, mis. **1080×1920** (atau 1080×2400). Min sisi 320px, maks 3840px.
- Format PNG/JPG, tanpa alpha.
- **Jangan** ada bezel/frame HP palsu yang menyesatkan; screenshot murni saja.

**Rekomendasi 5 screenshot (urutan cerita):**
1. **Dashboard** — ringkasan omzet/order hari ini.
2. **POS / Kasir** — buat order, pilih layanan.
3. **Kanban Order** — papan status (antrian → siap).
4. **Laporan Keuangan** — grafik/laba-rugi.
5. **Absensi** — clock-in selfie + geofence (sekaligus dukung Location Declaration).

Cara ambil di Android: buka tiap halaman → tombol Power + Volume Down.
Lalu pindahkan ke folder ini (`screenshots/`), beri nama `01-dashboard.png`, dst.

## Regenerasi feature graphic (kalau edit SVG)
```bash
cd docs/playstore/assets
FONT="/System/Library/Fonts/Supplemental/Arial Bold.ttf"
magick -size 1024x500 -define gradient:angle=130 gradient:'#0F1C3A-#0F7B6C' bg.png
magick -background none -density 200 -font "$FONT" feature-graphic.svg -resize 1024x500 fg.png
magick bg.png fg.png -composite feature-graphic.png
rm bg.png fg.png
```
> Catatan: ImageMagick tak render gradient/emoji di dalam SVG — makanya background
> dibuat terpisah & foreground SVG dijaga solid (tanpa emoji).

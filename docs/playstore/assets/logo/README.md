# Logo LAMASY

Bagian dari **keluarga Harpy**. Meneruskan bahasa visual **Harpy Laundry**:
lingkaran **teal `#35E7D5`** + cincin ganda putih + **pakaian & sparkle** putih.
Baca cerita lengkapnya di [FILOSOFI.md](FILOSOFI.md) · lembar visual: `filosofi-sheet.png`.

## File
| File | Untuk |
|------|-------|
| `logo-badge-1024.png` / `logo-badge-512.png` | **Logo utama** (badge, ada teks LAMASY di dalam lingkaran). Marketing, listing, splash |
| `logo-icon-1024.png` / `logo-icon-512.png` | **App icon** (tanpa teks — pakaian+sparkle saja, terbaca kecil). Untuk launcher / icon Play |
| `logo-badge.svg` / `logo-icon.svg` | Sumber vektor (edit di browser/design tool, render ulang) |
| `filosofi-sheet.(svg\|png)` | Lembar filosofi beranotasi |

## Palet
| Warna | Hex |
|-------|-----|
| Teal Harpy | `#35E7D5` |
| Putih | `#FFFFFF` |

## Mengganti logo LAMA (Harpy "H") jadi ini di APK
App icon di APK sekarang masih **Harpy "H"** (`lamasy-app/resources/icon.png`).
Untuk adopsi logo LAMASY:
1. Ganti `lamasy-app/resources/icon.png` (1024×1024) dengan `logo-icon-1024.png`.
2. `cd lamasy-app && npx @capacitor/assets generate --android`
3. `./build-aab.sh` → icon baru ikut bundle.
> Selama APK belum di-rebuild, upload icon Play tetap yang lama agar konsisten
> (Play bisa reject kalau icon listing ≠ launcher).

## Render ulang (kalau edit SVG)
```bash
FONT="/System/Library/Fonts/Supplemental/Arial Bold.ttf"
# icon polos
magick -background none logo-icon.svg -resize 1024x1024 logo-icon-1024.png
# badge (teks LAMASY ditempel via annotate — magick lemah render text SVG)
magick -background none logo-badge.svg -resize 1024x1024 base.png   # abaikan teks yg mungkin ke-clip
# atau render badge tanpa teks lalu:
magick base.png -gravity South -font "$FONT" -fill white -pointsize 116 -kerning 12 -annotate +0+188 "LAMASY" logo-badge-1024.png
```
> Catatan: ImageMagick tak render `text-anchor`/`letter-spacing`/emoji di SVG dengan
> baik — teks wordmark dibuat via `-annotate`. Di browser, SVG-nya tampil benar.

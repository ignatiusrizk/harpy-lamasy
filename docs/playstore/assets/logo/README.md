# Logo LAMASY

## Konsep
Huruf **L** (LAMASY) berbentuk **tetesan air yang jatuh ke permukaan** — inti dunia
laundry (air, bersih, segar). "Bead" **aqua** di kepala huruf berfungsi ganda:
jendela mesin cuci + percikan (spark) yang menandakan **AI terintegrasi**. Diletakkan
pada tile gradient **navy → teal**, palet brand yang sudah dipakai di seluruh app.

Satu risiko yang diambil: bead aqua off-center di kepala L — memberi karakter
(porthole/tetesan) alih-alih monogram L polos.

## Palet
| Nama | Hex | Pakai |
|------|-----|-------|
| Navy Deep | `#0F1C3A` | base gelap / wordmark di bg terang |
| Teal | `#0F7B6C` | tile / aksen |
| Aqua Glow | `#35E8D5` | bead / spark |
| White | `#FFFFFF` | mark / wordmark di bg gelap |

## File
| File | Untuk |
|------|-------|
| `logo-icon-1024.png` / `logo-icon-512.png` | App icon (tile gradient + mark). 512 = upload Play Console |
| `logo-mark.svg` | Mark saja (transparan) — untuk adaptive-icon foreground / favicon |
| `logo-horizontal-dark.(svg\|png)` | Lockup untuk background GELAP (tile teal, wordmark putih) — mis. login/topbar |
| `logo-horizontal-light.(svg\|png)` | Lockup untuk background TERANG (tile & wordmark navy) |

Wordmark: Arial Bold, letter-spacing lebar (gaya SaaS modern). Ganti ke font brand
kalau punya lisensi (mis. Poppins/Montserrat) dengan mengedit SVG lalu render ulang.

## Mengganti logo LAMA (Harpy "H") jadi ini
App icon di APK sekarang masih **Harpy "H"** (`lamasy-app/resources/icon.png`).
Play mensyaratkan icon 512 = icon launcher. Untuk mengadopsi logo baru:
1. Ganti `lamasy-app/resources/icon.png` (1024×1024) dengan `logo-icon-1024.png`.
   Untuk adaptive icon: `resources/icon-foreground.png` = `logo-mark.svg` (render PNG,
   beri padding aman ~18%), `resources/icon-background.png` = warna/gradient tile.
2. Regenerasi: `cd lamasy-app && npx @capacitor/assets generate --android` (atau tool aset yg dipakai).
3. `./build-aab.sh` → icon baru ikut di bundle.
> Selama APK belum di-rebuild dgn icon baru, upload icon Play = tetap yang lama (Harpy H)
> agar konsisten. Jangan campur (Play bisa reject kalau icon listing ≠ launcher).

## Render ulang (kalau edit SVG)
```bash
FONT="/System/Library/Fonts/Supplemental/Arial Bold.ttf"
# icon
magick -size 1024x1024 -define gradient:angle=135 gradient:'#0F1C3A-#0F7B6C' tile.png
magick -background none -density 300 logo-mark.svg -resize 1024x1024 m.png
magick tile.png m.png -composite logo-icon-1024.png; rm tile.png m.png
# lockup
magick -background none -density 300 -font "$FONT" logo-horizontal-dark.svg -resize 1360x300 logo-horizontal-dark.png
```

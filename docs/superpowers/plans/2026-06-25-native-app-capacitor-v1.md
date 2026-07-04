# Native App v1 (Capacitor Android) Scaffold — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Scaffold project Capacitor thin-shell di `~/Documents/lamasy-app/` (folder terpisah) — webview ke `https://lamasy.harpy.id`, siap user build APK & submit Play Store.

**Architecture:** Thin-shell: Capacitor `server.url` remote ke produksi, nol custom JS. Scaffold semua file config + www stub + placeholder asset + README. Android project (`android/`) + build + submit = user (butuh Android SDK + Play Console).

**Tech Stack:** Capacitor 6 (core/cli/android), Node v26, npm. Target Android.

## Global Constraints

- Lokasi: `~/Documents/lamasy-app/` — DI LUAR repo PHP `/Users/rizky/Documents/lamasy` (jangan scaffold di dalam repo PHP; hindari deploy Hostinger).
- App ID: `id.harpy.lamasy` (permanen). App name: `LAMASY`.
- `server.url`: `https://lamasy.harpy.id`, `cleartext: false`, `allowNavigation: ["lamasy.harpy.id"]`.
- v1 = shell-only: tanpa plugin offline/print/push, tanpa custom shell JS.
- `.gitignore` WAJIB exclude: `node_modules/`, `android/`, `*.apk`, `*.keystore`, `*.jks`.
- External link otomatis via allowNavigation (no custom JS). Back-button = default Capacitor.
- Claude: scaffold + `npm install`. User: install Android SDK → `npx cap add android` → build → submit.
- Capacitor version: pin `^6` (stabil terbaru per 2026). `@capacitor/assets` devDependency untuk generate icon/splash.

---

## Task 1: Project Scaffold + npm install

**Files (semua di `~/Documents/lamasy-app/`):**
- Create: `package.json`
- Create: `capacitor.config.json`
- Create: `www/index.html`
- Create: `.gitignore`

**Interfaces:**
- Produces: project Capacitor valid, deps terinstall, siap `npx cap add android` (user)

- [ ] **Step 1: Buat folder + package.json**

```bash
mkdir -p ~/Documents/lamasy-app/www ~/Documents/lamasy-app/resources
```

`~/Documents/lamasy-app/package.json`:
```json
{
  "name": "lamasy-app",
  "version": "1.0.0",
  "description": "LAMASY — aplikasi manajemen laundry (Capacitor thin-shell)",
  "private": true,
  "scripts": {
    "sync": "cap sync",
    "add:android": "cap add android",
    "open:android": "cap open android",
    "assets": "capacitor-assets generate --android"
  },
  "dependencies": {
    "@capacitor/android": "^6.2.0",
    "@capacitor/core": "^6.2.0"
  },
  "devDependencies": {
    "@capacitor/assets": "^3.0.5",
    "@capacitor/cli": "^6.2.0"
  }
}
```

- [ ] **Step 2: capacitor.config.json**

`~/Documents/lamasy-app/capacitor.config.json`:
```json
{
  "appId": "id.harpy.lamasy",
  "appName": "LAMASY",
  "webDir": "www",
  "server": {
    "url": "https://lamasy.harpy.id",
    "cleartext": false,
    "androidScheme": "https",
    "allowNavigation": ["lamasy.harpy.id"]
  },
  "android": {
    "allowMixedContent": false
  }
}
```

- [ ] **Step 3: www/index.html (loading stub)**

`~/Documents/lamasy-app/www/index.html` — tampil sekejap sebelum webview pindah ke remote:
```html
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>LAMASY</title>
  <style>
    html,body{margin:0;height:100%;background:#0F1C3A;display:flex;align-items:center;justify-content:center;
      font-family:-apple-system,Roboto,sans-serif}
    .box{text-align:center;color:#fff}
    .logo{font-size:34px;font-weight:800;letter-spacing:.02em}
    .logo span{color:#35E8D5}
    .sp{margin-top:18px;width:30px;height:30px;border:3px solid rgba(255,255,255,.2);
      border-top-color:#35E8D5;border-radius:50%;animation:spin .8s linear infinite;display:inline-block}
    @keyframes spin{to{transform:rotate(360deg)}}
  </style>
</head>
<body>
  <div class="box">
    <div class="logo">La<span>Ma</span>Sy</div>
    <div class="sp"></div>
  </div>
</body>
</html>
```

- [ ] **Step 4: .gitignore**

`~/Documents/lamasy-app/.gitignore`:
```
node_modules/
android/
ios/
*.apk
*.aab
*.keystore
*.jks
.DS_Store
.gradle/
*.log
```

- [ ] **Step 5: npm install + verify**

```bash
cd ~/Documents/lamasy-app && npm install 2>&1 | tail -5
```
Expected: deps terinstall, `node_modules/@capacitor/cli` ada.

Verify config valid:
```bash
cd ~/Documents/lamasy-app && node -e "JSON.parse(require('fs').readFileSync('capacitor.config.json','utf8')); console.log('config OK')"
npx cap --version 2>&1 | head -1
```
Expected: "config OK" + versi cap tampil.

- [ ] **Step 6: git init + commit**

```bash
cd ~/Documents/lamasy-app && git init -q && git add -A && git commit -q -m "chore: scaffold Capacitor thin-shell project

appId id.harpy.lamasy, server.url lamasy.harpy.id, www loading stub,
.gitignore (node_modules/android/keystore). v1 shell-only."
git log --oneline -1
```
Verify `.gitignore` bekerja: `git ls-files | grep -E "node_modules|android/" || echo "clean (no node_modules/android committed)"`

---

## Task 2: Placeholder Icon & Splash

**Files (di `~/Documents/lamasy-app/resources/`):**
- Create: `resources/icon.png` (1024×1024)
- Create: `resources/splash.png` (2732×2732)

**Interfaces:**
- Consumes: project Task 1
- Produces: source asset untuk `npx cap add android` + `capacitor-assets generate` (user)

- [ ] **Step 1: Generate placeholder PNG dari SVG**

Buat SVG sumber lalu konversi ke PNG via tool yang ada (sips/rsvg/ImageMagick). Coba `sips` (bawaan macOS) atau `rsvg-convert`/`magick` kalau ada.

`/tmp/lamasy-icon.svg` (icon 1024² — background brand + teks):
```svg
<svg xmlns="http://www.w3.org/2000/svg" width="1024" height="1024" viewBox="0 0 1024 1024">
  <rect width="1024" height="1024" fill="#0F1C3A"/>
  <text x="512" y="560" font-family="Helvetica,Arial,sans-serif" font-size="240" font-weight="800"
        text-anchor="middle" fill="#FFFFFF">La<tspan fill="#35E8D5">Ma</tspan>Sy</text>
</svg>
```

`/tmp/lamasy-splash.svg` (splash 2732² — logo di tengah):
```svg
<svg xmlns="http://www.w3.org/2000/svg" width="2732" height="2732" viewBox="0 0 2732 2732">
  <rect width="2732" height="2732" fill="#0F1C3A"/>
  <text x="1366" y="1430" font-family="Helvetica,Arial,sans-serif" font-size="360" font-weight="800"
        text-anchor="middle" fill="#FFFFFF">La<tspan fill="#35E8D5">Ma</tspan>Sy</text>
</svg>
```

Konversi (coba berurutan, pakai yang tersedia):
```bash
cd ~/Documents/lamasy-app/resources
# Opsi A: rsvg-convert (kalau ada: brew install librsvg)
rsvg-convert -w 1024 -h 1024 /tmp/lamasy-icon.svg -o icon.png 2>/dev/null && \
rsvg-convert -w 2732 -h 2732 /tmp/lamasy-splash.svg -o splash.png 2>/dev/null && echo "via rsvg" || \
# Opsi B: ImageMagick
( magick -background none /tmp/lamasy-icon.svg -resize 1024x1024 icon.png && \
  magick -background none /tmp/lamasy-splash.svg -resize 2732x2732 splash.png && echo "via magick" ) 2>/dev/null || \
echo "MANUAL: tidak ada rsvg/magick — lihat fallback Step 2"
```

- [ ] **Step 2: Fallback kalau tak ada konverter**

Kalau Step 1 print "MANUAL": pakai Python PIL (cek `python3 -c "import PIL"`), atau biarkan placeholder dibuat user. Fallback Python:
```bash
python3 - <<'PY'
from PIL import Image, ImageDraw, ImageFont
for name,size,fs in [("icon",1024,240),("splash",2732,360)]:
    img=Image.new("RGB",(size,size),"#0F1C3A"); d=ImageDraw.Draw(img)
    try: f=ImageFont.truetype("/System/Library/Fonts/Helvetica.ttc",fs)
    except: f=ImageFont.load_default()
    t="LAMASY"; b=d.textbbox((0,0),t,font=f); w=b[2]-b[0]; h=b[3]-b[1]
    d.text(((size-w)/2,(size-h)/2-b[1]),t,font=f,fill="#FFFFFF")
    img.save(f"/Users/rizky/Documents/lamasy-app/resources/{name}.png")
print("PIL OK")
PY
```
Kalau semua gagal: catat di report, user sediakan PNG manual (README jelaskan). JANGAN blokir — asset bisa di-swap kapan saja.

- [ ] **Step 3: Verify dimensi**

```bash
cd ~/Documents/lamasy-app/resources
sips -g pixelWidth -g pixelHeight icon.png splash.png 2>&1 | grep -E "pixel|png" || ls -la *.png
```
Expected: icon 1024×1024, splash 2732×2732 (atau file ada).

- [ ] **Step 4: Commit**

```bash
cd ~/Documents/lamasy-app && git add resources/ && git commit -q -m "chore: placeholder icon + splash (brand bg + LAMASY)

Sumber 1024/2732 untuk capacitor-assets generate. Swap logo asli nanti."
```

---

## Task 3: README (Setup + Build + Submit)

**Files:**
- Create: `~/Documents/lamasy-app/README.md`

**Interfaces:**
- Consumes: Task 1+2
- Produces: panduan lengkap user build & submit

- [ ] **Step 1: Tulis README.md**

`~/Documents/lamasy-app/README.md`:
```markdown
# LAMASY — Android App (Capacitor)

Thin-shell webview ke https://lamasy.harpy.id. Semua fitur jalan dari server (PHP);
update konten = deploy backend, TANPA rebuild app.

## Prasyarat (sekali setup)

1. **Node** (sudah ada): `node -v`
2. **JDK 17**: `brew install openjdk@17` lalu ikuti instruksi `brew info openjdk@17` untuk symlink
3. **Android SDK** — salah satu:
   - **Android Studio** (termudah): https://developer.android.com/studio → install → buka sekali → SDK Manager pasang "Android SDK Platform 34" + "Build-Tools"
   - **Command-line tools** (tanpa GUI): https://developer.android.com/studio#command-tools
4. Set env (tambah ke `~/.zshrc`):
   ```
   export ANDROID_HOME="$HOME/Library/Android/sdk"
   export PATH="$PATH:$ANDROID_HOME/platform-tools:$ANDROID_HOME/cmdline-tools/latest/bin"
   ```
   lalu `source ~/.zshrc`

## Build APK (test)

```bash
cd ~/Documents/lamasy-app
npm install                      # sekali (sudah dilakukan saat scaffold)
npx cap add android              # generate folder android/ (sekali)
npx capacitor-assets generate --android   # generate icon + splash dari resources/
npx cap sync android             # sync config
npx cap open android             # buka Android Studio → Run, ATAU build CLI:
cd android && ./gradlew assembleDebug
# hasil: android/app/build/outputs/apk/debug/app-debug.apk
```
Install ke HP: aktifkan USB debugging → `adb install android/app/build/outputs/apk/debug/app-debug.apk`

## Test checklist
- App buka → load lamasy.harpy.id
- Login + POS + navigasi semua jalan
- Tombol WhatsApp buka WA eksternal
- Pembayaran Midtrans buka halaman bayar
- Icon & splash tampil

## Submit ke Google Play

1. Daftar **Google Play Console** ($25 sekali): https://play.google.com/console
2. Buat **signing keystore** (sekali, SIMPAN AMAN — hilang = tak bisa update app):
   ```
   keytool -genkey -v -keystore lamasy-release.jks -keyalg RSA -keysize 2048 -validity 10000 -alias lamasy
   ```
   (file .jks SUDAH di-gitignore — jangan commit)
3. Build **release AAB**: `cd android && ./gradlew bundleRelease` (konfigurasi signing di `android/app/build.gradle` — lihat docs Capacitor "Signing")
4. Upload `.aab` ke Play Console → isi store listing (deskripsi, screenshot, privacy policy URL) → submit review.

## Update app
- **Ubah fitur/UI/bug** (95% kasus): deploy backend PHP biasa — app auto dapat update (webview remote). TANPA rebuild/submit.
- **Ubah config native/plugin** (jarang): rebuild + submit Play Console.

## Fase berikutnya (belum di v1)
- Plugin offline SQLite (write offline)
- Plugin thermal print ESC/POS (Bluetooth)
- Push notification (FCM)
- iOS

## Catatan
- appId `id.harpy.lamasy` permanen — jangan ubah setelah publish.
- Icon/splash placeholder di `resources/` — ganti PNG logo asli lalu `npx capacitor-assets generate --android`.
```

- [ ] **Step 2: Commit**

```bash
cd ~/Documents/lamasy-app && git add README.md && git commit -q -m "docs: README setup toolchain + build APK + submit Play Store"
git log --oneline
```

---

## Task 4: Verifikasi Final (Claude-side)

**Files:** None

- [ ] **Step 1: Struktur lengkap**
```bash
cd ~/Documents/lamasy-app && ls -la && echo "---" && ls resources www
```
Expected: package.json, capacitor.config.json, .gitignore, README.md, www/index.html, resources/icon.png, resources/splash.png, node_modules/.

- [ ] **Step 2: Config + deps sanity**
```bash
cd ~/Documents/lamasy-app
node -e "const c=require('./capacitor.config.json'); console.log(c.appId, c.server.url, c.server.allowNavigation.join(','))"
ls node_modules/@capacitor/cli >/dev/null && echo "cli ok"
git ls-files | grep -Ec "node_modules|^android/" | grep -q '^0$' && echo "gitignore clean"
```
Expected: `id.harpy.lamasy https://lamasy.harpy.id lamasy.harpy.id` + "cli ok" + "gitignore clean".

- [ ] **Step 3: Catat hasil**

Konfirmasi ke user: scaffold selesai, langkah build/submit ada di README. Tegaskan yang user lakukan: install Android SDK → `npx cap add android` → build → test → submit.

---

## Self-Review Checklist

### Spec Coverage
- ✅ §3.1 struktur folder terpisah → Task 1
- ✅ §3.2 capacitor.config (appId/server.url/allowNavigation/cleartext) → Task 1 Step 2
- ✅ §3.3 www stub, no custom JS, external link via allowNavigation → Task 1 Step 3 + config
- ✅ §3.4 placeholder icon/splash → Task 2
- ✅ §4 pembagian kerja (Claude scaffold + npm install; user build/submit) → Task 1-3 + README
- ✅ §6 security (HTTPS, allowNavigation whitelist, keystore gitignore) → Task 1
- ✅ §7.1 verifikasi Claude-side → Task 4
- ✅ §7.2 user test checklist → README

### Placeholder Scan
✓ Semua file isi lengkap. Icon/splash punya 3 fallback (rsvg/magick/PIL) + manual note — tidak blokir kalau converter absent.

### Konsistensi
- ✅ appId `id.harpy.lamasy`, appName `LAMASY`, server.url `https://lamasy.harpy.id` konsisten config + README
- ✅ Capacitor `^6.2.0` konsisten (core/cli/android), @capacitor/assets `^3`
- ✅ .gitignore exclude android/ + keystore → verify Task 1 Step 6 + Task 4

### Notes
- Node v26 ada → npm install dijalankan Claude. Android SDK TIDAK ada → `npx cap add android` + build = user (README).
- Folder di luar repo PHP → tidak ada commit ke repo lamasy (cuma spec+plan di docs/).
- Icon/splash: kalau converter tak ada di mesin, fallback PIL; kalau itupun tak ada, user sediakan manual (non-blocking).

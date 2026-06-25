# LaMaSy Native App v1 (Capacitor Android) — Design Spec

**Tanggal:** 25 Juni 2026
**Author:** Ignatius Rizky
**Status:** Draft → Awaiting user review

---

## 1. Tujuan

Bungkus PWA LaMaSy jadi aplikasi Android native pakai **Capacitor thin-shell** — webview yang load `https://lamasy.harpy.id`, releasable ke Google Play. v1 = shell-only (tanpa plugin native); plugin (offline/print/push) fase berikutnya. Sesuai strategi [[project-native-app-strategy]] (Capacitor, bukan rewrite; reuse 95% kode).

**Scope v1:**
- Project Capacitor di folder terpisah (`~/Documents/lamasy-app/`, git repo sendiri)
- Webview load URL produksi
- Native config: appId, appName, icon, splash, permission, status bar
- External link (wa.me, Midtrans, tel:) buka di app/browser eksternal — **otomatis via `allowNavigation` whitelist** (link di luar domain produksi dibuka Capacitor ke browser sistem, tanpa custom JS)
- Hardware back-button: **pakai default Capacitor v1** (history-back, exit di root). Custom confirm-exit defer ke fase 2 (butuh JS di remote page / native MainActivity)
- README build & submit lengkap
- Placeholder icon/splash (swap logo asli nanti)

**Out of scope v1:**
- Plugin offline SQLite, thermal print ESC/POS, push notification (fase 2+)
- iOS (Android dulu)
- Live-update (Capgo/Appflow)
- Build/sign/submit (di mesin + akun user)

---

## 2. Background

- PWA LaMaSy sudah jalan di `https://lamasy.harpy.id` (feature-complete cukup: POS, orders, inventori, mesin, antar-jemput, portal, loyalty, keuangan, dll)
- Strategi native diputuskan 2026-06-23: Capacitor thin-shell + remote webview
- Native solve yang PWA tak bisa: offline WRITE, silent thermal print, push iOS reliable, background sync — **semua fase 2+**, v1 cukup wrapper
- Mesin user: Mac + Xcode, **belum ada Android Studio / Android SDK**
- Repo PHP auto-deploy ke Hostinger tiap push → native project HARUS terpisah (hindari deploy node_modules/android ke server)

---

## 3. Arsitektur

### 3.1 Lokasi & Struktur

Folder terpisah `~/Documents/lamasy-app/` (git repo sendiri, TIDAK di dalam repo PHP):
```
lamasy-app/
├── package.json              # @capacitor/core, @capacitor/cli, @capacitor/android
├── capacitor.config.json     # appId, appName, server.url
├── www/
│   └── index.html            # stub loading page (webDir WAJIB walau pakai server.url remote)
├── resources/
│   ├── icon.png              # 1024×1024 placeholder
│   └── splash.png            # 2732×2732 placeholder
├── android/                  # GENERATED `npx cap add android` (di mesin user, butuh SDK)
├── .gitignore                # node_modules/, android/, *.apk, *.keystore
└── README.md                 # setup toolchain + build + submit
```

Catatan teknis: saat `server.url` di-set, webview LANGSUNG load URL remote — isi `www/` cuma fallback (webDir tetap wajib ada). Semua JS aplikasi berjalan dari page remote (PHP), bukan dari `www/` lokal. Karena itu tak ada `src/shell.js` — handling back-button custom (kalau perlu) harus di remote page atau native, defer ke fase 2.

### 3.2 capacitor.config.json
```json
{
  "appId": "id.harpy.lamasy",
  "appName": "LaMaSy",
  "webDir": "www",
  "server": {
    "url": "https://lamasy.harpy.id",
    "cleartext": false,
    "androidScheme": "https",
    "allowNavigation": ["lamasy.harpy.id"]
  },
  "android": { "allowMixedContent": false }
}
```

### 3.3 Webview behavior (tanpa custom shell JS)

`www/index.html` — loading stub sederhana (splash/spinner). Capacitor butuh `webDir` lokal walau `server.url` di-set; begitu app start, webview langsung ke URL remote, stub cuma tampil sekejap/fallback.

**External links** — ditangani **otomatis oleh config**: link ke domain di luar `allowNavigation` (mis. `wa.me`, `app.midtrans.com`, `tel:`, `mailto:`) dibuka Capacitor ke browser/app sistem secara default. Tidak perlu custom JS. Cukup pastikan `allowNavigation` hanya berisi `lamasy.harpy.id`.

**Hardware back-button** — v1 pakai perilaku default Capacitor (back = history-back webview; di root = exit app). Cukup untuk rilis pertama. Custom confirm-exit perlu JS di remote page (cek `window.Capacitor`) atau override native MainActivity — **defer ke fase 2**.

Konsekuensi: v1 murni wrapper, **nol custom JS di shell**. Semua logic tetap di PHP/JS server-side (remote). Ini sesuai prinsip thin-shell.

### 3.4 Icon & Splash
- Placeholder: background brand `#0F1C3A` + teks "LaMaSy" warna `#35E8D5` (icon 1024², splash 2732²). Di-generate via `@capacitor/assets`.
- User swap PNG logo asli kapan saja → re-generate.

---

## 4. Pembagian Kerja

| Langkah | Siapa | Catatan |
|---------|-------|---------|
| Scaffold semua file (package.json, config, www, shell.js, .gitignore, README) | **Claude** | Di folder `~/Documents/lamasy-app/` |
| Placeholder icon/splash PNG | **Claude** | Generate sederhana (atau SVG→PNG) |
| Install Node (jika belum) + Android SDK/Studio | **User** | README pandu |
| `npm install` | **User/Claude** | Claude jalankan jika Node ada |
| `npx cap add android` + `npx cap sync` | **User** | Butuh Android SDK |
| Generate icon/splash final (`npx @capacitor/assets generate`) | **User** | Command disediakan |
| Build APK debug → test HP | **User** | Android Studio / Gradle |
| Play Console ($25) + keystore + signing + upload | **User** | README pandu |

---

## 5. Edge Cases / Keputusan

| Hal | Keputusan |
|-----|-----------|
| Hardware back di root webview | Confirm exit / minimize (bukan langsung keluar) |
| External link (WA/Midtrans/tel) | Buka eksternal, bukan dalam webview |
| Cleartext HTTP | false (produksi HTTPS) |
| allowNavigation | hanya `lamasy.harpy.id` (cegah webview ke domain lain) |
| Offline total | v1: tampil error webview default (offline mode = fase 2 plugin) |
| Node belum ada di mesin Claude | scaffold file manual; user `npm install` di mesin |
| App ID | `id.harpy.lamasy` (permanen di Play Store) |
| node_modules/android ke-commit | dicegah `.gitignore` |

---

## 6. Security
- HTTPS only (cleartext false)
- allowNavigation whitelist domain produksi (cegah open-redirect dalam webview)
- Tidak ada kredensial di repo native (keystore di-gitignore)
- Webview = remote produksi (auth/session ditangani PHP server seperti biasa)

---

## 7. Testing Plan

### 7.1 Yang Claude verifikasi (di sini)
1. Semua file ter-scaffold, struktur benar
2. `capacitor.config.json` JSON valid + server.url benar
3. `package.json` deps benar (versi Capacitor terbaru stabil)
4. `.gitignore` mencakup node_modules/, android/, *.apk, *.keystore
5. `npm install` sukses (jika Node tersedia di mesin)

### 7.2 Yang user verifikasi (di mesin/device)
| # | Test | Expected |
|---|------|----------|
| 1 | `npx cap add android` | folder android/ ter-generate |
| 2 | Build APK debug | sukses, no error Gradle |
| 3 | Install APK di HP | app terbuka, load lamasy.harpy.id |
| 4 | Login + POS + navigasi | semua fitur PWA jalan dalam app |
| 5 | Hardware back | navigate back, root → confirm exit |
| 6 | Klik tombol WA | buka WhatsApp eksternal |
| 7 | Icon & splash | tampil saat launch |

---

## 8. Implementation Phasing

Bagian Claude (scaffold), ~1 jam:
1. Struktur folder + package.json + capacitor.config.json + .gitignore
2. www/index.html loading stub (tanpa custom shell JS — thin-shell murni)
3. Placeholder icon/splash
4. README lengkap (toolchain setup + build + submit)
5. `npm install` (jika Node ada) + verifikasi JSON/struktur

Bagian user (di luar scope tugas Claude): toolchain → build → test → submit.

---

## 9. Files Inventory

### New (di `~/Documents/lamasy-app/`)
- package.json, capacitor.config.json, .gitignore, README.md
- www/index.html (loading stub)
- resources/icon.png, resources/splash.png

### Generated by user (tidak di-scaffold Claude)
- android/ (via `npx cap add android`)
- node_modules/ (via npm install)

### Repo PHP ini
- Tidak ada perubahan kode. Hanya tambah spec+plan di docs/superpowers/.

---

## 10. Success Criteria
- ✅ Project Capacitor ter-scaffold lengkap di folder terpisah
- ✅ Config benar (appId id.harpy.lamasy, server.url produksi, HTTPS, allowNavigation)
- ✅ Shell handler: back-button + external-link
- ✅ Placeholder icon/splash
- ✅ README cukup detail untuk user build & submit sendiri
- ✅ Zero perubahan/risk ke repo PHP & deploy Hostinger
- ✅ (User) APK build + jalan di device, semua fitur PWA accessible

---

## 11. References
- [[project-native-app-strategy]] — keputusan Capacitor thin-shell
- Capacitor docs: server.url remote, @capacitor/app backButton, @capacitor/assets
- PWA produksi: https://lamasy.harpy.id
- App ID: id.harpy.lamasy

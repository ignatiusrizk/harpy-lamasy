# LAMASY Tenant App — Install Guide

Sistem LAMASY (POS, Orders, Kanban, Dashboard) sekarang bisa di-install sebagai aplikasi ke home screen Android & iOS — tanpa native app, pakai Progressive Web App (PWA).

## 📱 Cara Install

### Android (Chrome / Edge / Samsung Internet)

1. Buka **https://lamasy.harpy.id/** di browser
2. Login dengan akun owner / kasir
3. Setelah masuk dashboard, tunggu **3 detik** — banner "Install LAMASY" muncul di bawah
4. Tap **"Install"** → icon LAMASY muncul di home screen

**Atau via menu browser:**
- Tap ⋮ (titik tiga kanan atas) → "Install LAMASY..." / "Add to Home Screen"

### iOS Safari (iPhone / iPad)

iOS Safari **tidak punya** auto-install button — harus manual.

1. Buka **https://lamasy.harpy.id/** di **Safari** (BUKAN Chrome iOS — Apple block install di Chrome iOS)
2. Login dengan akun owner / kasir
3. Tap tombol **⎘ Share** di bar bawah Safari
4. Scroll ke bawah → tap **"Tambah ke Layar Utama"** / **"Add to Home Screen"**
5. Beri nama (default "LAMASY") → tap **"Tambah"** kanan atas

## ✅ Verify Install Berhasil

- Icon LAMASY muncul di home screen (background teal, huruf H putih)
- Buka dari icon → app full-screen tanpa browser UI / address bar
- Indikator: banner install di dashboard tidak muncul lagi

## 📡 Offline Mode

Setelah install + pernah buka, beberapa halaman bisa diakses **tanpa internet**:

| Page | Offline? | Cara kerja |
|------|----------|------------|
| `/dashboard` | ✅ Ya | Cache versi terakhir |
| `/orders` | ✅ Ya | Cache list orders terakhir |
| `/customer` | ✅ Ya | Cache customer list |
| `/kanban` | ✅ Ya | Cache board state terakhir |
| `/pos` (create order) | ❌ Tidak | Butuh internet — transaksi submit ke server real-time |
| Action lain (Bayar, Print, Update status) | ❌ Tidak | Butuh internet |

**Saat offline:**
- Pages yang ada di cache: tetap render (data mungkin stale)
- Pages baru: muncul halaman fallback "📡 Tidak ada koneksi" dengan tombol Retry

## 🔄 Update App

Service worker auto-detect versi baru:
1. Saat user buka app, SW check ada versi baru?
2. Kalau ada, download di background
3. Reload page → versi baru aktif

User tidak perlu uninstall + reinstall manual.

## 🗑️ Uninstall

**Android:** Long-press icon di home screen → "Uninstall" / "Remove"
**iOS:** Long-press icon → "Hapus Aplikasi" / "Remove App"

## 🛠️ Troubleshooting

**Banner install tidak muncul di Android:**
- Pastikan pakai Chrome / Edge (bukan in-app browser)
- Pastikan sudah HTTPS (lamasy.harpy.id ✅)
- Coba clear browser cache + refresh

**iOS banner instruksi tidak muncul:**
- Pastikan pakai **Safari**, bukan Chrome iOS / Firefox iOS
- Pastikan belum pernah dismiss banner-nya (clear localStorage kalau perlu)

**App stuck di old version:**
- Force-quit app dari recent apps
- Buka lagi — SW auto-update

**Offline page selalu muncul padahal ada internet:**
- Service worker cache bisa stuck. Solution: uninstall + reinstall app.

## 📊 Technical Notes

- **Scope**: `/` (semua tenant pages kecuali /pelanggan, /kurir, /droppoint, /superadmin)
- **Cache name**: `lamasy-tenant-v1` (versioning kalau update major)
- **Manifest**: `/assets/manifest-tenant.json`
- **Service worker**: `/sw-tenant.js`
- **Theme color**: `#0F1C3A` (LAMASY navy)
- **Background**: `#0F1C3A`

Portal pelanggan (`/pelanggan`) punya PWA terpisah (`/sw.js` + `/assets/manifest.json`).

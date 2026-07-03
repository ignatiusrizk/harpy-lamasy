# LAMASY — Persiapan Play Console (Listing + Data Safety + Permission)

Dokumen siap-tempel ke Google Play Console. Semua teks bisa langsung disalin.
Penerbit: **PT Harpy Sinergi Mandiri** · Privacy policy: `https://lamasy.harpy.id/privacy`
App ID: `id.harpy.lamasy`

---

## 1. Store Listing

**App name** (maks 30 karakter)
```
LAMASY - ERP Laundry
```

**Short description** (maks 80 karakter)
```
Kasir & manajemen laundry: order, keuangan, absensi, laporan, dalam 1 app.
```

**Full description** (maks 4000 karakter)
```
LAMASY adalah aplikasi manajemen usaha laundry (ERP) untuk pemilik dan karyawan outlet laundry. Kelola seluruh operasional dari satu tempat: kasir (POS), order, keuangan, inventori, karyawan, hingga laporan — dengan bantuan AI.

FITUR UTAMA

• Kasir / POS
  Buat order cepat, multi-layanan, diskon & DP, cetak struk & label ke printer thermal Bluetooth. Input order lewat suara (voice order) dibantu AI.

• Manajemen Order
  Pantau status order (antrian → proses → siap → selesai) lewat papan Kanban. Kirim notifikasi status ke pelanggan via WhatsApp.

• Keuangan
  Catat kas masuk/keluar, kelola piutang B2B, dan lihat laporan keuangan (harian, bulanan, laba-rugi) sesuai standar SAK EMKM.

• Inventori & Pembelian
  Kelola stok bahan, mutasi, dan purchase order.

• Karyawan & Absensi
  Absensi dengan selfie + verifikasi lokasi (geofence), jadwal shift, dan penggajian otomatis.

• Loyalitas Pelanggan
  Program poin, member tier, deposit wallet, dan referral.

• Antar-Jemput
  Atur kurir, zona, dan lacak status antar-jemput.

• Laporan & Analitik
  Dashboard ringkas, laporan lengkap, dan ekspor data.

• Multi-Outlet (HQ)
  Pemilik dengan banyak cabang dapat memantau semua outlet secara terpusat.

Cocok untuk usaha laundry kiloan, satuan, express, hingga B2B/korporat. Dibuat oleh PT Harpy Sinergi Mandiri.

Butuh akun untuk masuk. Hubungi kami untuk berlangganan.
```

**Detail lain:**
- **App category:** Business (alternatif: Productivity)
- **Tags:** laundry, POS, kasir, ERP, UMKM
- **Contact email:** (email support kamu)
- **Website:** `https://lamasy.harpy.id`
- **Privacy policy:** `https://lamasy.harpy.id/privacy`

**Aset grafis (wajib):**
| Aset | Ukuran | Catatan |
|------|--------|---------|
| App icon | 512×512 PNG | dari `resources/icon.png` |
| Feature graphic | 1024×500 PNG/JPG | banner atas listing |
| Phone screenshots | min 2, maks 8 (mis. 1080×1920) | POS, dashboard, laporan, kanban |
| (opsional) Tablet screenshots | — | kalau target tablet |

---

## 2. Justifikasi Permission

Untuk setiap izin yang ditanya reviewer:

| Permission | Alasan | Foreground/Background |
|------------|--------|-----------------------|
| `INTERNET` | Inti aplikasi — memuat & sinkron data ERP dari server. | — |
| `ACCESS_FINE_LOCATION` / `ACCESS_COARSE_LOCATION` | Verifikasi lokasi saat karyawan clock-in absensi (geofence — harus di area outlet) + pemindaian perangkat Bluetooth (printer) di Android lama. | **Foreground saja**, tidak ada akses lokasi latar belakang. |
| `RECORD_AUDIO` | Fitur "Voice Order": kasir mengucapkan pesanan, diubah jadi teks. **Pengenalan suara dilakukan di perangkat; audio TIDAK direkam/dikirim/disimpan** — hanya teks hasilnya. | Foreground saja, saat tombol voice ditekan. |
| `BLUETOOTH`, `BLUETOOTH_ADMIN`, `BLUETOOTH_CONNECT`, `BLUETOOTH_SCAN` | Menghubungkan ke printer struk thermal Bluetooth (58/80mm) untuk cetak nota & label. | Foreground saja. |

> Kamera: dipakai untuk scan QR (produksi) & foto (selfie absensi, struk kas) lewat **file-input capture bawaan sistem** — tidak memerlukan deklarasi izin `CAMERA` khusus.

**Rekomendasi (opsional, mengurangi pertanyaan reviewer):**
Kalau ke depan lokasi HANYA dipakai untuk absensi (bukan derive dari BT scan), tambahkan flag `neverForLocation` di `BLUETOOTH_SCAN` pada AndroidManifest:
```xml
<uses-permission android:name="android.permission.BLUETOOTH_SCAN"
    android:usesPermissionFlags="neverForLocation" tools:targetApi="s" />
```

---

## 3. Data Safety Form (jawaban siap-isi)

**Pertanyaan awal:**
- Apakah app mengumpulkan atau membagikan data pengguna? → **Ya, mengumpulkan.**
- Semua data dienkripsi saat transit? → **Ya** (HTTPS).
- Pengguna bisa minta data dihapus? → **Ya** (retensi: aktif → nonaktif → hapus setelah 90 hari, lihat privacy policy).

**Jenis data yang dikumpulkan** (untuk tiap baris: Collected = Ya, Processed ephemeral = tidak kecuali disebut, Shared = tidak dijual):

| Kategori | Tipe data | Dikumpulkan | Tujuan | Wajib? |
|----------|-----------|-------------|--------|--------|
| Info pribadi | Nama | Ya | Fungsi app, Manajemen akun | Wajib |
| Info pribadi | Nomor telepon | Ya | Fungsi app (order/notifikasi WA pelanggan) | Wajib |
| Info pribadi | Email | Ya | Manajemen akun (login tenant) | Wajib |
| Info pribadi | Alamat | Ya | Fungsi app (alamat outlet/antar-jemput) | Opsional |
| Info finansial | Riwayat pembelian/transaksi | Ya | Fungsi app | Wajib |
| Info finansial | Info pembayaran (kartu) | **Tidak** | Pembayaran lewat gateway, app tidak menyimpan | — |
| Lokasi | Lokasi presisi & perkiraan | Ya | Fungsi app (verifikasi absensi geofence) | Opsional (per fitur) |
| Foto/video | Foto | Ya | Fungsi app (selfie absensi, bukti struk, foto produksi) | Opsional |
| Audio | Rekaman suara | **Tidak** | STT di perangkat; hanya teks dikirim, audio tak disimpan | — |
| Aktivitas app | Interaksi dalam app (audit log) | Ya | Fungsi app, Keamanan/audit | Wajib |
| ID perangkat | Token notifikasi (FCM) | Ya | Fungsi app (push notifikasi) | Opsional |

**Dibagikan ke pihak ketiga?**
Data TIDAK dijual. Diproses oleh penyedia layanan (processor) atas nama kami:
- **Anthropic (Claude API)** — memproses teks untuk fitur AI (parse voice order, scan struk, saran). Teks tidak disimpan Anthropic untuk training.
- **Fonnte / WhatsApp Business API** — mengirim notifikasi ke pelanggan (nomor + isi pesan).
- **Hostinger** (hosting), **Cloudflare** (CDN/keamanan) — infrastruktur.

> Di form Data Safety, transfer ke penyedia layanan yang memproses atas nama kita umumnya **bukan "sharing"**. Tetap sebutkan di privacy policy (sudah ada).

**Praktik keamanan:**
- Data dienkripsi saat transit (HTTPS/TLS): **Ya**
- Ada cara pengguna minta hapus data: **Ya**
- Password di-hash (bcrypt), isolasi multi-tenant.

---

## 4. Deklarasi Permission Sensitif (form khusus di Console)

**Location permission declaration** (muncul karena ada FINE/COARSE_LOCATION):
- Fitur inti yang butuh lokasi: **Verifikasi kehadiran karyawan (clock-in absensi) berbasis geofence — memastikan karyawan berada di lokasi outlet saat absen.**
- Akses lokasi latar belakang? **Tidak.**
- Siapkan **video demo singkat** (screen recording) fitur absensi yang memakai lokasi — reviewer biasanya minta ini.

**Microphone (RECORD_AUDIO):** jelaskan di catatan reviewer bahwa audio diproses on-device (STT) dan tidak dikirim/disimpan; hanya transkrip teks yang dikirim ke server.

---

## 5. Content Rating & Lainnya

- **Content rating questionnaire:** app bisnis, tanpa konten kekerasan/dewasa/judi → hasil kemungkinan **Everyone / 3+**.
- **Target audience:** dewasa (pemilik & karyawan usaha) — bukan anak-anak.
- **Ads:** tidak ada iklan → deklarasikan "No ads".
- **App access (login):** app butuh login. **Sediakan kredensial demo** untuk reviewer (buat akun uji khusus review, jangan akun produksi) di bagian "App access → All functionality restricted → provide credentials".

---

## Checklist Rilis

- [ ] Upload keystore dibuat & `android/keystore.properties` diisi
- [ ] `.aab` di-build via `./build-aab.sh` (signed)
- [ ] Play Console: app dibuat, Play App Signing aktif
- [ ] Store listing (teks di atas) + aset grafis
- [ ] Data Safety form (jawaban di atas)
- [ ] Location declaration + video demo absensi
- [ ] Content rating questionnaire
- [ ] Kredensial demo untuk reviewer (akun uji)
- [ ] Upload `.aab` ke Internal testing → tes → promote ke Production

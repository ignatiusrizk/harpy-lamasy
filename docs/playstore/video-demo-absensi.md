# Script Video Demo — Fitur Lokasi (Absensi Geofence)

Untuk **Location Permission Declaration** di Play Console. Google minta bukti visual
bahwa izin lokasi (`ACCESS_FINE_LOCATION`) dipakai untuk fitur inti yang jelas.
Rekam layar HP (screen recording), durasi **30–60 detik**, tanpa audio pun boleh
(pakai caption). Upload ke YouTube (unlisted) lalu tempel link di declaration.

**Pesan inti yang harus terlihat reviewer:**
> Lokasi dipakai untuk memverifikasi karyawan berada di area outlet saat clock-in
> absensi (geofence). Foreground only, dipicu aksi user.

---

## Storyboard (per scene)

| # | Durasi | Yang di layar | Caption / narasi |
|---|--------|---------------|------------------|
| 1 | 0–5s | Buka app LAMASY, tampil dashboard | "LAMASY — aplikasi manajemen laundry" |
| 2 | 5–12s | Owner buka **Settings → Outlet & Nota → Absensi & Geofence**, aktifkan geofence, set titik outlet di peta + radius (mis. 100 m), Simpan | "Owner mengatur lokasi outlet & radius absensi" |
| 3 | 12–18s | Login sebagai **karyawan**, buka menu **Absensi** | "Karyawan membuka fitur absensi" |
| 4 | 18–26s | Tekan tombol **Clock In**. Muncul **dialog izin lokasi Android** → pilih **While using the app**. | "Izin lokasi diminta saat clock-in — foreground saja" |
| 5 | 26–38s | App membaca GPS, tampil status "berada dalam radius" → clock-in **berhasil**. (Opsional: tampilkan selfie step.) | "Lokasi memverifikasi karyawan ada di outlet → absensi tercatat" |
| 6 | 38–48s | (Opsional) Tunjukkan skenario **di luar radius** → clock-in **ditolak** dengan pesan | "Di luar area outlet, absensi ditolak — mencegah titip absen" |
| 7 | 48–55s | Kembali ke dashboard | "Lokasi TIDAK diakses di latar belakang. Selesai." |

---

## Teks jawaban Location Declaration (siap tempel)

**Which of your app's core features require location?**
```
Employee attendance verification (clock-in). When a staff member clocks in, the app
reads the device's current location once to confirm they are physically within a
geofence radius around their assigned laundry outlet. This prevents buddy-punching
(clocking in from elsewhere). Owners set the outlet's location point and radius in
Settings.
```

**Does your app access location in the background?**
```
No. Location is accessed only in the foreground, and only at the moment the user
taps "Clock In". There is no background location access, no tracking, and location
is not shared with third parties.
```

**Why FINE (precise) location?**
```
Attendance verification needs precise location to reliably check whether the staff
member is inside a small radius (e.g. 50-200 m) of the outlet. Approximate location
is not accurate enough for this radius.
```

---

## Tips rekaman
- Rekam di HP asli (fitur GPS & printer BT tak jalan di emulator).
- Pastikan dialog izin Android **terlihat jelas** di scene 4 (ini bukti utamanya).
- Kalau bisa, tunjukkan sekali kasus "di luar radius ditolak" — memperkuat justifikasi.
- Simpan sebagai unlisted YouTube; jangan private (reviewer harus bisa buka).

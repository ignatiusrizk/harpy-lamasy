# Antar Jemput — Design Spec

**Tanggal:** 2026-06-22
**Status:** Approved, ready for implementation plan
**Scope:** Sistem antar jemput laundry (jemput dari rumah pelanggan + antar ke rumah pelanggan), dengan akun kurir, dispatcher view, mobile kurir page, report harian, dan permissions.

## Tujuan

Berikan operasional UMKM laundry kemampuan kelola layanan antar jemput end-to-end: input request jemput, assign ke kurir, kurir mobile update status, antar setelah cucian siap, bukti serah terima, dan rekap harian. Bahasa familiar laundry (jemput/antar) bukan istilah teknis (pickup/dispatch).

## Non-tujuan

- Tidak real-time map tracking (Plan B kalau ada permintaan)
- Tidak geo lokasi periodik dari kurir (status text saja)
- Tidak route optimization multi-stop
- Tidak pelanggan self-service form request jemput (kasir/staff yang input)
- Tidak fee per km (cukup free atau zona fixed)

## Pendekatan

3 halaman baru + integrasi ke `/pos`, `/produksi`, `/track` existing. 3 tabel baru (master kurir, antar jemput unified jemput+antar, master zona). 1 ALTER outlets untuk mode fee. Permission terpisah agar role-based access. Sidebar item di group Operasional.

## Terminologi (penting — konsisten di seluruh UI)

| Konsep | Istilah |
|---|---|
| Jemput cucian dari rumah pelanggan | **Jemput** |
| Antar cucian ke rumah pelanggan | **Antar** |
| Halaman + sistem keseluruhan | **Antar Jemput** |
| Worker yang mengantar | **Kurir** |
| Dispatcher / yang assign | (tetap istilah internal; UI tidak pakai) |

## Komponen

### Data Model

**Tabel baru `hl_kurir`** — master kurir per outlet:
```sql
CREATE TABLE hl_kurir (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  user_id INT NULL,                    -- FK hl_users.id (nullable kalau belum punya akun)
  nama VARCHAR(100) NOT NULL,
  no_hp VARCHAR(20),
  kendaraan VARCHAR(50),               -- 'Motor Beat', 'Mobil Avanza', dll (free text)
  aktif TINYINT(1) DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX (tenant_id, outlet_id, aktif),
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Tabel baru `hl_antar_jemput`** — unified jemput + antar:
```sql
CREATE TABLE hl_antar_jemput (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  tipe ENUM('jemput','antar') NOT NULL,
  transaksi_id INT NULL,               -- jemput: nullable; antar: required
  pelanggan_id INT NULL,
  nama VARCHAR(100) NOT NULL,          -- snapshot
  telepon VARCHAR(20),
  alamat TEXT NULL,                    -- opsional (sering tidak lengkap)
  zona_id INT NULL,                    -- FK hl_zona_antar
  fee INT DEFAULT 0,                   -- snapshot fee saat dibuat
  slot_waktu DATETIME NULL,            -- jemput: jadwal; antar: target waktu
  kurir_id INT NULL,                   -- null = belum assigned
  status ENUM('pending','assigned','menuju','sampai','done','cancel') DEFAULT 'pending',
  catatan TEXT,                        -- patokan, alamat lengkap, instruksi kurir
  foto_bukti VARCHAR(255),             -- foto saat done
  signature_path VARCHAR(255),         -- signature pelanggan saat antar done
  created_by INT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  done_at DATETIME NULL,
  INDEX (tenant_id, outlet_id, status, created_at),
  INDEX (kurir_id, status),
  INDEX (transaksi_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Tabel baru `hl_zona_antar`** — opsional, kalau outlet pakai mode 'zona':
```sql
CREATE TABLE hl_zona_antar (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  nama VARCHAR(60) NOT NULL,
  fee INT NOT NULL DEFAULT 0,
  aktif TINYINT(1) DEFAULT 1,
  INDEX (tenant_id, outlet_id, aktif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**ALTER outlets**:
```sql
ALTER TABLE outlets ADD COLUMN IF NOT EXISTS antar_mode ENUM('free','zona') DEFAULT 'free' AFTER label_size;
```

Status flow (sama untuk jemput & antar):
```
pending → assigned → menuju → sampai → done
                                         ↓
                            cancel (state apa saja)
```

### Halaman 1: `/antar-jemput` (dispatcher view)

Akses: permission `antar.view` (owner, manager, kasir). Manage actions butuh `antar.manage`.

Layout:
```
🚚 Antar Jemput                       [+ Jemput Baru] [📊 Report]
─────────────────────────────────────────────────────────────
[Pending] [Assigned] [Menuju] [Sampai] [Selesai] · counts
─────────────────────────────────────────────────────────────
TAB: [📥 Jemput]  [📤 Antar]
─────────────────────────────────────────────────────────────
[Card per dispatch dengan status pill warna semantik]
```

Card content:
```
┌─────────────────────────────────────────┐
│ Budi Susilo · 0812-xxx                   │
│ Jl. Mawar 12, Tebet                      │
│ Slot 14:00–15:00 · Zona 1 (Rp 10k)       │
│ 🟡 Pending                                │
│ [Assign Kurir ▾] [Detail]                 │
└─────────────────────────────────────────┘
```

Status pill warna: pending=amber, assigned=blue, menuju=teal, sampai=purple, done=green, cancel=gray.
"5 menit lalu" timestamp auto-refresh tiap 30 detik client-side.

### Halaman 2: `/kurir` (mobile worker page)

Akses: permission `antar.kurir` (role `kurir`).

Layout mobile-first:
```
👋 Pak Joko · Outlet Tebet            [Logout]
─────────────────────────────────────
Tugas Hari Ini (4)

┌─────────────────────────────┐
│ 📥 JEMPUT                    │
│ Budi Susilo                  │
│ Jl. Mawar 12, Tebet           │
│ Slot: 14:00–15:00             │
│ Patokan: dekat warung Bu Inah │
│ [📍 Buka Maps] (kalau alamat) │
│ [▶ Saya Menuju]               │
└─────────────────────────────┘
```

`[📍 Buka Maps]` = `https://maps.google.com/?q={alamat_encoded}` — native open di app Maps. Tombol hidden kalau `alamat` kosong.

Tap action button = status maju 1 step:
- pending/assigned → "▶ Saya Menuju" → status=menuju
- menuju → "✅ Sampai Lokasi" → status=sampai
- sampai → "🏁 Selesai" → buka modal konfirmasi (foto bukti + signature kalau antar) → status=done

Done modal:
- Field foto bukti (kamera, wajib untuk antar; opsional untuk jemput)
- Signature canvas (wajib untuk antar; tidak untuk jemput)
- Field catatan (opsional)
- Save → POST ke `/kurir.php?action=mark_done`

### Halaman 3: `/kurir-master` (master kurir)

Akses: permission `antar.manage`.

Pattern sama dengan `/karyawan` existing:
- List tabel kurir (nama, no_hp, kendaraan, status aktif, akun login y/n)
- Tombol "+ Tambah Kurir" → modal: nama + no_hp + kendaraan
- Tombol "Buat Akun" → generate user_id di `hl_users` dengan role=`kurir`, password random 8 karakter, ditampilkan sekali (catat oleh owner)
- Toggle aktif

### Settings Antar (di Outlet Settings)

Tambah section di `/outlet-settings` modal Edit (existing) — radio mode + CRUD zona:

```
🚚 Mode Antar
○ Free (tidak ada fee)
● Zona (fee per zona)

[CRUD zona — list nama + fee, tambah/edit/hapus]
```

### Integrasi `/pos` (saat create order)

Field baru di form input order: checkbox "🛵 Antar ke pelanggan" (default off). Saat dicentang:
- Show field alamat (opsional) + catatan (patokan) + zona pilih (kalau mode=zona) + slot waktu (opsional)
- Auto-tambah fee zona ke total order (sebagai item layanan "Biaya Antar" — atau separate field `biaya_antar` kalau perlu, MVP: pakai layanan)
- On save order: create `hl_antar_jemput` row tipe=`antar` status=`pending` linked ke transaksi

### Integrasi `/produksi` stage 'diambil'

Saat jenis_penyerahan='diantarkan' dipilih di stage form:
- Auto-create `hl_antar_jemput` row tipe=`antar` jika belum ada untuk transaksi tersebut
- Status modal: "Order akan masuk antrian Antar Jemput. Assign kurir di /antar-jemput."
- Stage diambil tetap update status_proses ke `diambil` (existing behavior)

### Integrasi `/track` (pelanggan)

Section baru kalau ada `hl_antar_jemput` row untuk order:

```
🛵 Sedang Diantar
Kurir: Pak Joko (0812-xxx)
Status: 🟢 Menuju (5 menit lalu)
```

Untuk delivery selesai, tampil bukti foto + signature (sudah implemented di /track existing).

### Report Harian

URL: `/antar-jemput?view=report` atau button "📊 Report".

Konten:
```
📊 Report — 22 Jun 2026 [picker tanggal]

Total Antar Jemput: 18  (jemput 12 / antar 6)
Selesai:            14  (78%)
Pending+On-Going:    3
Cancel:              1
Avg waktu selesai:  47 menit (dari assigned ke done)

Performance Kurir:
┌─────────────────────────────────────┐
│ Pak Joko    8 selesai · avg 41 min   │
│ Pak Budi    5 selesai · avg 53 min   │
│ Pak Eko     1 selesai · avg 38 min   │
└─────────────────────────────────────┘

Fee terkumpul: Rp 95.000 (antar saja)

[Export CSV] (opsional MVP)
```

Query aggregate dari `hl_antar_jemput` WHERE `tenant_id+outlet_id` AND `DATE(created_at) = picker_date`.

### Permissions baru

| Kode | Deskripsi | Default role dapat |
|---|---|---|
| `antar.view` | Lihat list + report antar jemput | owner, manager, kasir, staff |
| `antar.manage` | Create antar jemput, assign kurir, kelola master kurir + zona | owner, manager |
| `antar.kurir` | Akses `/kurir` mobile page | role `kurir` saja |

Seed di `TenantProvisioner::seedPermissions()` + backfill ke tenant existing via mysql.

**Role baru `kurir`**: hanya dapat `antar.kurir`. Login → redirect ke `/kurir` (di `login.php`).

### Sidebar update

`components.php` navGroups:

**Group Operasional**, tambah setelah `produksi`:
```php
'antar-jemput' => ['label'=>'Antar Jemput', 'url'=>'/antar-jemput', 'perm'=>'antar.view'],
```

**Group Master**, tambah setelah `customer`:
```php
'kurir-master' => ['label'=>'Kurir', 'url'=>'/kurir-master', 'perm'=>'antar.manage'],
```

iconMap update:
```php
'antar-jemput'=>'🚚','kurir-master'=>'🛵',
```

### Concurrency & error handling

- `FOR UPDATE` lock saat assign kurir (prevent 2 dispatcher race)
- Validasi status transition: assigned hanya boleh dari pending; menuju hanya dari assigned; dll
- Foto upload via existing `core/FileUpload.php` → folder `uploads/foto_antar/`
- Signature canvas → decode base64 → save PNG (same pattern as produksi)
- Audit log per action: `logAudit('antar_create', 'antar_jemput', "id=X tipe=Y")`, `_assign`, `_status`, `_done`
- Generic error message (no internal exception leak)

### Testing (manual)

1. Owner buat 2 kurir + generate akun login
2. Kurir A login → /kurir → kosong (belum ada tugas)
3. Kasir buat order baru di /pos, centang "Antar ke pelanggan", isi alamat, save
4. Kasir buka /antar-jemput → row antar pending muncul → assign ke Kurir A
5. Kurir A refresh /kurir → row muncul → tap "Saya Menuju" → status berubah
6. Kurir A tap "Sampai" → "Selesai" → modal foto + signature → save
7. Pelanggan buka /track?order=KODE → lihat status kurir realtime + bukti antar setelah done
8. Owner buka /antar-jemput?view=report → lihat statistik harian
9. Test permission: kasir tanpa `antar.manage` tidak lihat tombol assign
10. Race: 2 dispatcher assign sama bersamaan → 1 success, 1 error

## Out of scope (deferred)

- Real-time GPS tracking kurir
- Map view posisi kurir
- Multi-stop route optimization
- Customer self-service form request jemput
- Fee per km
- WA broadcast notifikasi pelanggan status kurir
- Settle komisi kurir (kalau ada model komisi)
- Multi-outlet dispatch (kurir handle order outlet lain)

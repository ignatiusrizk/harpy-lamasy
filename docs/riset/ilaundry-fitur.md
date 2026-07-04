# Riset Fitur: iLaundry (benchmark kompetitor)

Sumber:
- **i-laundry.com** — "Dry Cleaning Management Software & POS" (matang, berat hardware). https://i-laundry.com/features
- **iLaundry (CodeCanyon)** — "Dry Cleaning & Laundry Service Booking with POS, Single/Multi Branch" (SaaS Node.js, model mirip LAMASY). https://codecanyon.net/item/ilaundry-.../43366743

> Catatan: "iLaundry" ada beberapa produk. Dua ini paling relevan. i-laundry.com = POS
> dry-clean klasik; CodeCanyon = SaaS multi-branch (lebih dekat ke LAMASY).

---

## A. Fitur i-laundry.com (per kategori)

**POS & Sistem**
- Auto log-out, Multi cash drawer, Terminal groups, Fully customisable (payment type/service/currency), Trading hours & holidays + surcharge, Touch-screen.

**Pelanggan**
- Multiple price lists (per segmen), Tax rates (include/exclude/exempt), Customer notes, **Corporate invoicing** (akun bisnis + sub-customer).

**Order & Item**
- **Item & weight control**, Pre-payments (DP), **Service attributes** (warna/merek/motif + surcharge), **Garment photos** (bukti kondisi/noda), Discount/surcharge on-the-fly, Order/item notes, **Rail position** (posisi rak garment siap), **Disclaimers** (surat pernyataan/release form).

**Notifikasi**
- Email & SMS status, **Auto reminder cucian belum diambil**, Email-to-SMS, **SMS marketing** (kampanye tertarget).

**Inventori & Operasi**
- Inventory management (bahan/jasa), **Inter-branch tracking**, **RFID** (hitung linen massal — hotel/institusi).

**Staf & Keamanan**
- Staff tracking (aktivitas/edit/login/absensi), User access control, **Employee performance** (produksi per staf), User roles & functions, **Biometrics/fingerprint** login, Transaction audit.

**Laporan**
- Payout tracking, Shift close, Export Excel/PDF, **Sales leads** (efektivitas marketing), **Loyalty partners** (komisi).

**Integrasi & Data**
- Sinkron akuntansi (Sage One / Pastel), Sureswipe/Zapper/Snapscan (payment), OneDrive/GDrive/Dropbox, **Offsite cloud backup** + 1-click restore, Thermal/tag/label/report printer, barcode scanner.

## B. Fitur iLaundry CodeCanyon (SaaS)
- Dashboard statistik, **Laundry POS** (billing cepat, diskon, invoice), Orders (barcode label, notif), Services (harga/add-on/gambar), **Multi-branch centralized**, Customer + ledger, Reports (sales/order/expense/tax), **Role-based** (Super Admin/Store Admin/Staff/Customer), **Multi-language & RTL**, Dark/Light theme, Email & SMS notif, POS walk-in, **Bulk service upload**, Order editing, page-level permission, **Payout + commission**, payment methods configurable.

---

## C. Pemetaan ke LAMASY

### ✅ LAMASY sudah punya (setara/lebih)
POS + billing/diskon/DP · Orders + status/Kanban · barcode/label + thermal print · multi-outlet (HQ) · customer + ledger/deposit · laporan (SAK EMKM, export) · **role-based + page permission** · notif WhatsApp · walk-in POS · inventori bahan + mutasi + inter-outlet transfer · staff + absensi + payroll · **loyalty/poin + referral + member tier** · **piutang B2B** (~corporate invoicing) · express tier (~multiple price list) · voice order + scan struk AI (**AI = keunggulan LAMASY, mereka tak punya**).

### 💡 Ide baru dari iLaundry (belum ada di LAMASY)
| Ide | Nilai untuk LAMASY | Effort |
|-----|--------------------|--------|
| **Foto kondisi item saat intake** (garment photo + catat noda) | Kurangi sengketa "baju rusak/hilang"; bukti. LAMASY sudah punya infra foto (produksi/kas) → tinggal di POS intake | Sedang |
| **Rail/rak position** garment siap | Kasir cepat temukan order siap ambil (rak A-03) | Kecil |
| **Auto reminder cucian belum diambil** (X hari) | Kurangi tumpukan; dorong pengambilan; WA otomatis | Kecil-Sedang |
| **Service attributes** (warna/merek/motif per item) | Nota lebih detail; hindari tertukar | Sedang |
| **Disclaimer / surat pernyataan** (release form) di nota | Perlindungan hukum usaha (luntur/susut) | Kecil |
| **Trading hours + surcharge hari libur/kilat** | Harga otomatis naik di luar jam/hari libur | Sedang |
| **Employee performance** (produksi per staf) | Insentif & evaluasi; LAMASY punya data produksi → tinggal rekap | Sedang |
| **Sales leads** (tag sumber pelanggan) | Ukur efektivitas promosi | Kecil |
| **RFID linen massal** | Segmen hotel/rumah sakit (institusi) | Besar |
| **Sinkron akuntansi eksternal** (mis. Accurate/Jurnal) | Untuk klien yang sudah pakai akuntansi lain | Besar |
| **Multi-language / dark theme** | Nilai tambah UX (RTL kurang relevan utk ID) | Kecil |

### 🏆 Keunggulan LAMASY yang mereka TIDAK punya
- **AI terintegrasi** (voice order, scan struk, saran) — pembeda utama.
- Model **coin bayar-sesuai-pakai** (tanpa langganan bulanan).
- **Antar-jemput + kurir** in-app + tracking.
- Portal pelanggan + tracking publik via QR struk.

---

## Rekomendasi cepat (quick win, effort kecil)
1. **Auto reminder cucian belum diambil** (WA otomatis, sudah ada infra WA).
2. **Rail/rak position** di order (1 kolom teks).
3. **Foto kondisi item saat intake** (reuse infra foto).
4. **Disclaimer di nota** (1 field teks di struk config).

# Loyalty Reward Multi-Outlet + Catalog UX — Design Spec

**Tanggal:** 2026-06-22
**Status:** Approved, ready for implementation plan
**Scope:** Reward catalog yang bisa dikelola di HQ (multi-outlet checkbox) atau outlet-specific. POS kasir browse catalog visual (replace numeric input). Portal pelanggan display rewards available (read-only, action di outlet).

## Tujuan

Buat reward loyalty lebih useable untuk owner dan pelanggan:
- Owner kelola reward dari HQ dengan target ke specific outlets via checkbox (atau "semua outlet")
- Outlet manager/kasir tetap bisa buat reward outlet-specific dari halaman /loyalty existing
- Kasir POS lihat catalog reward yang eligible untuk pelanggan + 1-klik apply (bukan input angka manual)
- Pelanggan lihat daftar reward yang tersedia di portal + saldo poin (read-only)

## Non-tujuan

- Tidak menambah action di portal pelanggan (per kebijakan portal Phase 1 = read-only)
- Tidak ada kupon kode self-service (Phase 2, defer)
- Tidak ada WA share kupon (Phase 2)
- Tidak mengubah konfigurasi loyalty (rupiah_per_poin, poin_value, expiry_months) — already exists
- Tidak mengubah earn flow — tetap auto via existing logic saat order siap

## Pendekatan

Tambah tabel junction `hl_poin_reward_outlet` untuk many-to-many reward↔outlet. Kolom `hl_poin_reward.outlet_id` legacy di-keep (nullable) tapi tidak dipakai logic baru. Query rewards-applicable-to-outlet: union global (no junction rows) + specific (junction match outlet). Backfill existing rewards via INSERT SELECT.

2 halaman UI: `/hq/loyalty` (NEW, owner-only) dengan multi-outlet checkbox saat create/edit. `/loyalty` (existing, kasir/manager) extend dengan read-only display rewards yang dimanage HQ + tetap CRUD outlet-specific. POS gain catalog browser saat pelanggan terpilih. Portal `/pelanggan` gain section "Hadiah Tersedia".

## Komponen

### Data Model

**Tabel baru `hl_poin_reward_outlet`** — junction reward↔outlet:
```sql
CREATE TABLE hl_poin_reward_outlet (
  reward_id INT NOT NULL,
  outlet_id INT NOT NULL,
  PRIMARY KEY (reward_id, outlet_id),
  INDEX idx_outlet (outlet_id),
  FOREIGN KEY (reward_id) REFERENCES hl_poin_reward(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**ALTER `hl_poin_reward`** — outlet_id legacy, nullable:
```sql
ALTER TABLE hl_poin_reward MODIFY outlet_id INT NULL;
```

**Backfill** — migrate existing rewards ke junction:
```sql
INSERT IGNORE INTO hl_poin_reward_outlet (reward_id, outlet_id)
SELECT id, outlet_id FROM hl_poin_reward WHERE outlet_id IS NOT NULL;
```

**Convention**:
- Junction empty (0 rows) untuk suatu reward = berlaku **semua outlet** dalam tenant
- Junction N rows = berlaku di N outlet spesifik

**Query rewards for outlet X**:
```sql
SELECT r.* FROM hl_poin_reward r
WHERE r.tenant_id=? AND r.is_active=1
  AND (
    NOT EXISTS (SELECT 1 FROM hl_poin_reward_outlet WHERE reward_id=r.id)
    OR EXISTS (SELECT 1 FROM hl_poin_reward_outlet WHERE reward_id=r.id AND outlet_id=?)
  )
```

### Halaman 1: `/hq/loyalty` (NEW, owner-only)

Pattern sama dengan `/hq/inventori`, `/hq/keuangan` existing.

Akses: `hq_guard.php` + role owner/superadmin (atau permission `loyalty.manage_hq` baru).

Layout:
```
🎁 Loyalty Reward — HQ View

[+ Tambah Reward Baru]                    [Filter outlet ▾]
─────────────────────────────────────────────────────────
Card per reward (semua tenant):
┌────────────────────────────────────────┐
│ Diskon Rp 5.000                          │
│ 50 poin · diskon_nominal Rp 5.000        │
│ Berlaku: ✅ Tebet · ✅ Pondok Indah · ⏸ Mall │
│ [✏ Edit] [🗑 Hapus]                       │
└────────────────────────────────────────┘
```

Modal create/edit:
- Nama, Deskripsi (textarea), Poin Dibutuhkan, Tipe (enum), Nilai
- Min Transaksi (rupiah), Max Redeem per Bulan
- **Berlaku di outlet** (radio + checkbox):
  - `( )` Semua outlet (default)
  - `(•)` Outlet tertentu:
    - Checkbox list semua outlet aktif di tenant
- Status aktif (toggle)

Save: insert/update `hl_poin_reward` + DELETE + INSERT junction rows sesuai pilihan.

### Halaman 2: `/loyalty` (existing outlet page, extend)

Existing: settings loyalty + CRUD reward outlet-specific.

Changes:
- List reward sekarang gabungan: outlet-specific (junction row = current outlet only) + global (no junction) + multi-outlet yang include current outlet
- Tampil badge per reward: 🏢 "Dikelola HQ" (kalau di-manage HQ — bisa global atau multi-outlet) vs 🏪 "Outlet ini" (kalau junction tunggal current outlet)
- Reward dengan badge HQ: read-only (Edit/Hapus disabled) untuk role kasir/manager. Owner tetap bisa edit (sama dengan HQ akses).
- Reward outlet-specific: CRUD penuh untuk owner + manager (existing).
- Create new di outlet page: insert reward + junction row (current outlet_id only).

### POS UX upgrade

Saat kasir pilih pelanggan dengan saldo poin > 0 di order modal:

Tampilkan section "🎁 Tukarkan Poin" (collapsible, default collapsed kalau pelanggan tidak ada reward eligible):

```
🎁 Tukarkan Poin (Saldo: 245 poin)
┌─────────────────────────────────────────┐
│ ✅ Diskon Rp 5.000    50 poin    [Pakai] │
│ ✅ Free Cuci Express  100 poin   [Pakai] │
│ ⏳ Voucher Rp 25.000  200 poin   (kurang) │
└─────────────────────────────────────────┘

ATAU input manual: Redeem [____] poin (advanced)
```

Klik [Pakai]:
- Frontend: set hidden field `reward_id` + auto-fill `redeem_poin` = reward.poin_dibutuhkan
- Recalc total preview client-side (poin × poin_value atau nilai_reward tergantung tipe)
- Highlight selected reward, disable lain
- Tombol "Batal Reward" untuk un-select

Backend handling di `pos.php` save action:
- Kalau `reward_id` ada: validate (reward exists + applicable di outlet + saldo cukup + tidak exceed max_redeem_per_bulan)
- Hitung discount sesuai `tipe`:
  - `diskon_nominal` → discount = `nilai` (rupiah)
  - `diskon_persen` → discount = subtotal × (`nilai`/100)
  - `gratis_layanan` → discount = harga layanan tertentu (need select layanan, atau total subtotal kalau ambiguous)
- Log ke `hl_loyalty_log` dengan `reward_id` (column sudah ada)
- Kalau hanya numeric `redeem_poin` tanpa `reward_id`: pakai logic existing (poin × poin_value)

### Portal pelanggan display

Tambah section di `/pelanggan` home setelah Saldo & Poin section:

```
🎁 Hadiah Tersedia
┌─────────────────────────────────────────┐
│ ✅ Diskon Rp 5.000    50 poin             │
│ ✅ Free Cuci Express  100 poin            │
│ ⏳ Voucher Rp 25.000  200 poin (-155)     │
└─────────────────────────────────────────┘
💡 Kunjungi outlet untuk menukarkan hadiah
```

Query: `Loyalty::availableRewards($tid, $oid_pel_terakhir_order, $saldo_poin)` — daftar reward yang apply di outlet last order pelanggan + status eligible (saldo cukup) atau not.

Read-only display. Tidak ada button "Tukarkan" di portal — sesuai kebijakan portal Phase 1.

### Backend: `core/Loyalty.php` updates

Method existing:
- `availableRewards($tid, $oid, $saldoPoin)` — update query pakai junction logic
- `redeem(...)` — already accepts reward_id, no change needed
- Tambah method baru: `applicableOutlets(int $rewardId): array` — return list outlet_id yang apply (untuk HQ UI display)

### Permissions

Reuse existing:
- `pelanggan.view` — lihat /loyalty + reward list
- `pelanggan.edit` — kelola reward (CRUD)

HQ-level: gating via `hq_guard.php` + role check `owner|superadmin`. Tidak ada permission baru.

Kasir/manager edit lock untuk HQ-managed rewards: backend `requirePermission('pelanggan.edit')` + check di handler "kalau reward punya junction multi-outlet atau global → require owner/superadmin role".

### Error handling

- Apply reward di POS gagal (saldo kurang, exceed max_redeem, reward tidak applicable) → toast error spesifik, form tidak submit
- Edit reward HQ-managed dari outlet page tanpa role owner → 403 generic
- Junction inconsistency (reward orphan tanpa parent row) → ON DELETE CASCADE handle otomatis
- Concurrent edit reward di HQ + outlet → last-write wins (no FOR UPDATE, low concurrency)

### Audit

- `logAudit('reward_create', 'loyalty', "id=$id outlets=...")` saat HQ create
- `logAudit('reward_edit_outlets', 'loyalty', "id=$id from=[...] to=[...]")` saat ubah scope
- `logAudit('reward_apply', 'loyalty', "transaksi_id=$tid reward_id=$rid pelanggan_id=$pid")` saat redeem di POS

### Testing

Manual:
1. Owner buat 2 reward di /hq/loyalty: A = semua outlet, B = outlet Tebet only
2. Cek /loyalty di Outlet Tebet → kedua reward tampil dengan badge 🏢 HQ
3. Cek /loyalty di Outlet Mall → hanya reward A tampil
4. Kasir di Outlet Tebet buat reward C local → tampil di /loyalty dengan badge 🏪 Outlet
5. Cek /loyalty di Outlet Mall → reward C tidak tampil (junction = Tebet only)
6. Kasir di Tebet POS buat order pelanggan punya 100 poin → catalog tampil reward A + C; B disabled kalau poin < 50
7. Klik Pakai reward A → redeem_poin auto-fill 50, total kepotong diskon Rp 5.000, log entry created dengan reward_id=A
8. Pelanggan buka /pelanggan portal → section Hadiah Tersedia tampil rewards yang apply di outlet last order
9. Manager edit reward A dari /loyalty (HQ-managed) → 403 atau tombol Edit disabled
10. Owner edit reward A dari /loyalty → boleh
11. Edge: reward B dengan junction Tebet+Mall (multi-outlet), backfill correct, query outlet Pondok Indah → reward B tidak muncul

## Out of scope (Phase 2, defer)

- Kupon kode self-service (pelanggan generate kupon dari portal)
- WA share kupon ke teman
- Reward dengan tipe "free product" yang butuh stock tracking
- Stacking multiple rewards di 1 order
- Reward expiry per redeem (sekarang only poin yang punya expiry)
- Analytics: reward most-redeemed, conversion rate per reward

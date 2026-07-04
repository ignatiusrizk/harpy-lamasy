# Loyalty Referral (Ajak Teman) — Design Spec

> LAMASY. Tanggal: 2026-06-30.

## Goal

Tambah kapabilitas **referral** ke sistem loyalty yang sudah ada: pelanggan mengajak teman; saat **order pertama teman LUNAS**, pengajak & teman sama-sama dapat **bonus poin** (besaran diatur owner). Dibangun **opt-in per owner** (default mati).

## Konteks (sistem loyalty existing — dipakai ulang)

- Poin per pelanggan: `hl_pelanggan.poin_balance`; log: `hl_loyalty_log`.
- `Loyalty::adjust(int $tenantId, int $pelangganId, int $poinDelta, string $note, ?int $userId=null): int` — grant/kurang poin manual + tulis log. **Dipakai untuk payout referral.**
- Earn poin terjadi saat order **selesai/lunas** via `Loyalty::earnForTransaction(...)` di `kanban.php` (~baris 138) & `orders.php` (~baris 338). **Payout referral nebeng di titik yang sama.**
- Config loyalty: `tenants.loyalty_enabled / loyalty_rupiah_per_poin / loyalty_poin_value` (lihat `Loyalty::config`).
- Portal pelanggan (`/pelanggan` via `hl_pelanggan.portal_token`). POS (`pos.php`) + self-booking (`self.php`) bikin/pilih pelanggan.

## Keputusan Desain (terkunci)

- **Atribusi: dua jalur** — (a) kode referral di-input manual saat order pertama (kasir POS / pelanggan self-booking); (b) link share portal `?ref=KODE`. Keduanya memanggil `Referral::attribute()`.
- **Reward: poin dua-duanya, cair saat order pertama teman LUNAS.** Besaran pengajak & teman diatur owner.
- **Opt-in per owner** (`tenants.referral_enabled`, default 0). **Referral butuh `loyalty_enabled=1`** (rewardnya poin).
- **Batas per pengajak:** owner atur `referral_max_per_pengajak` (0 = tak terbatas). Saat cap penuh → referral tetap di-`paid` tapi **poin pengajak = 0, teman tetap dapat**.
- **Anti-abuse:** teman harus pelanggan baru (belum pernah order), tak bisa refer diri sendiri (telepon sama), satu teman sekali saja (UNIQUE), payout hanya saat lunas + idempoten.
- **Pendekatan:** hook di titik order→lunas existing (bukan cron — owner menolak fitur auto; bukan bayar-saat-dibuat — rawan abuse).

## Data

### `tenants` (ALTER — config)
- `referral_enabled` TINYINT(1) DEFAULT 0
- `referral_poin_pengajak` INT DEFAULT 0
- `referral_poin_teman` INT DEFAULT 0
- `referral_max_per_pengajak` INT DEFAULT 0  (0 = tak terbatas)

### `hl_pelanggan` (ALTER)
- `referral_code` VARCHAR(20) NULL, index. Di-generate lazy (mis. slug nama + 3 char acak, mis. `BUDI-7F3`). Unik per tenant.

### `hl_referral` (tabel baru)
```sql
CREATE TABLE hl_referral (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  referrer_pelanggan_id INT NOT NULL,
  referee_pelanggan_id  INT NOT NULL,
  kode VARCHAR(20) NOT NULL,
  status ENUM('pending','paid','void') NOT NULL DEFAULT 'pending',
  referee_first_order_id INT NULL,
  poin_pengajak INT NOT NULL DEFAULT 0,
  poin_teman    INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at    DATETIME NULL,
  UNIQUE KEY uniq_referee (tenant_id, referee_pelanggan_id),
  KEY idx_referrer (tenant_id, referrer_pelanggan_id),
  KEY idx_status (tenant_id, status)
);
```
`UNIQUE(tenant_id, referee_pelanggan_id)` menjamin satu teman hanya bisa direferral sekali.

## Komponen & File

| File | Aksi | Tanggung jawab |
|---|---|---|
| Migration (baru) | ALTER tenants + ALTER hl_pelanggan + CREATE hl_referral | Skema di atas |
| `core/Referral.php` | **NEW** | `config(tenantId)`; `codeFor(tenantId, pelangganId): string` (generate+simpan kalau belum ada); `resolveCode(tenantId, kode): ?int` (→ referrer pelanggan id); `attribute(tenantId, kode, refereePelangganId): array` (guard new/self/dup + buat record pending); `payoutOnFirstLunas(PDO $db, tenantId, outletId, refereePelangganId, orderId): void` (cek pending + first-lunas + cap → `Loyalty::adjust` dua-duanya → mark paid, idempoten); `statsFor(tenantId, pelangganId): array` (jumlah sukses + poin didapat) |
| Migration/seed + **hq/loyalty.php** | MODIFY | Section "Referral": toggle enable + input poin pengajak/teman + cap |
| `pos.php` + `self.php` | MODIFY | Field "Kode referral (opsional)" saat pelanggan baru → panggil `Referral::attribute()` (no-op kalau referral mati / kode invalid) |
| `pelanggan.php` (portal) | MODIFY | Tampil kode referral + link share `?ref=KODE` + statsFor (jumlah sukses + poin) |
| `kanban.php` + `orders.php` | MODIFY | Di titik yang sama `earnForTransaction` dipanggil (order→lunas), panggil `Referral::payoutOnFirstLunas(...)` |

**Opt-in:** semua entry (`attribute`, `payout`, UI) cek `referral_enabled` + `loyalty_enabled` dulu; mati → no-op diam.

## Alur

1. **Kode pengajak** — `Referral::codeFor()` generate `<slug-nama>-<3char>` unik, simpan `hl_pelanggan.referral_code`. Tampil di portal + link share.
2. **Teman diajak** — kasir/self-booking input kode ATAU teman buka `?ref=KODE` (prefill) → `attribute(kode, refereeId)` → `hl_referral` `pending`.
3. **Order pertama teman LUNAS** — hook order→lunas panggil `payoutOnFirstLunas()`: ada pending utk referee? order ini order pertamanya yang lunas? cap pengajak? → `Loyalty::adjust` poin teman + (kalau cap belum penuh) poin pengajak → status `paid`, simpan `referee_first_order_id` + `paid_at`.

## Error Handling / Anti-Abuse

| Kondisi | Perilaku |
|---|---|
| Teman bukan pelanggan baru (sudah pernah order) | `attribute` tolak (return error), order tetap normal |
| Refer diri sendiri (telepon referee == referrer) | `attribute` tolak |
| Teman sudah pernah direferral | UNIQUE → `attribute` tolak (diam) |
| Kode tak dikenal / referral mati / loyalty mati | `attribute` no-op diam; order jalan normal |
| `payout` dipanggil 2× (re-sync / re-trigger) | Idempoten: hanya proses kalau status `pending`; sekali `paid` tak diulang |
| Cap pengajak penuh | referral tetap `paid`, poin pengajak 0, **teman tetap dapat poin** |
| Order lunas lalu dibatalkan | Di luar scope (poin terlanjur cair, konsisten perilaku earn poin existing) |

## Keamanan (multi-tenant)

- Semua query `Referral` scoped `tenant_id` (dari sesi/guard, bukan input). Kode referral di-resolve dalam scope tenant.
- Endpoint yang menulis (input kode, payout) lewat guard + CSRF existing. `referrer_pelanggan_id`/`referee_pelanggan_id` tak pernah dipercaya dari input mentah — di-resolve server.

## Testing

- **Unit `Referral` (fixture array/SQLite in-memory):**
  - `attribute`: pelanggan baru → record `pending`; existing customer → tolak; self-refer (telepon sama) → tolak; referee sudah direferral → tolak; referral disabled → no-op.
  - `payoutOnFirstLunas`: pending + order pertama lunas → poin pengajak + teman (sesuai config) + status `paid`; dipanggil 2× → idempoten (tak dobel poin); cap penuh → poin pengajak 0, teman tetap dapat; tak ada pending → no-op.
  - `codeFor`: generate sekali lalu stabil (panggilan kedua kode sama).
- **Lint** semua file PHP yang disentuh.
- **Manual E2E:** owner aktifkan referral + set poin → pelanggan A ambil kode dari portal → B (baru) order pakai kode A (via kasir & via `?ref=`) → B bayar lunas → A & B dapat poin, `hl_referral` `paid`, portal A tampil "1 referral sukses + N poin".

## Out of Scope

- Referral berjenjang / multi-level (teman-of-teman).
- Reward selain poin (voucher diskon) — bisa nyusul.
- Leaderboard / gamifikasi referral.
- Notif push otomatis saat referral cair (hook tersedia, tambah nanti).
- Pembatalan/clawback poin saat order lunas dibatalkan.
- Kadaluwarsa referral pending (pending tak punya expiry di versi ini).

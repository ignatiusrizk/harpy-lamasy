-- ══════════════════════════════════════════════════════
-- Migration: Self-Service Laundry (Mesin Koin) — Approach A
--
-- Model: laundromat / koin laundry. Customer pakai mesin sendiri,
-- bayar per cycle, staff konfirmasi manual untuk nyalakan mesin
-- (tanpa IoT controller — itu Approach B nanti).
--
-- Flow:
--  1. Customer scan QR di mesin → /self?m=KODE
--  2. Pilih cycle (durasi+tarif), input nama+telepon, bayar
--  3. Staff lihat sesi 'booked' di /mesin → klik "Konfirmasi Mulai"
--  4. Status sesi: booked → running, mesin nyala fisik (manual)
--  5. Timer countdown durasi_menit, status auto running → done
--  6. Customer ambil baju, sesi closed
--
-- Tabel:
--  - hl_mesin            : master mesin per outlet
--  - hl_mesin_cycle      : opsi durasi+tarif per mesin (multi-cycle)
--  - hl_mesin_sesi       : sesi pemakaian (booking → done)
-- ══════════════════════════════════════════════════════

-- ─────────────────────────────────────────────
-- 1. MASTER MESIN
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS hl_mesin (
  id            INT          AUTO_INCREMENT PRIMARY KEY,
  tenant_id     INT          NOT NULL,
  outlet_id     INT          NOT NULL,
  nama          VARCHAR(80)  NOT NULL                COMMENT 'Misal: Mesin Cuci 1',
  kode          VARCHAR(20)  NOT NULL                COMMENT 'Slug untuk QR URL, misal WC1, DR2',
  tipe          ENUM('cuci','kering') NOT NULL DEFAULT 'cuci',
  kapasitas     DECIMAL(5,2) NOT NULL DEFAULT 0     COMMENT 'kg muat',
  status        ENUM('idle','booked','running','maintenance') NOT NULL DEFAULT 'idle',
  catatan       VARCHAR(200) NULL,
  is_active     TINYINT      NOT NULL DEFAULT 1,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uk_mesin_kode (tenant_id, outlet_id, kode),
  INDEX idx_tenant_outlet (tenant_id, outlet_id),
  INDEX idx_status (tenant_id, outlet_id, status, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- 2. CYCLE OPTIONS PER MESIN
-- (1 mesin bisa punya beberapa pilihan cycle: 30min/45min/60min)
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS hl_mesin_cycle (
  id            INT          AUTO_INCREMENT PRIMARY KEY,
  mesin_id      INT          NOT NULL,
  label         VARCHAR(60)  NOT NULL                COMMENT 'Misal: Cycle Standar 30 menit',
  durasi_menit  INT          NOT NULL                COMMENT 'Durasi cycle dalam menit',
  tarif         INT          NOT NULL                COMMENT 'Rp per cycle',
  urutan        INT          NOT NULL DEFAULT 0,
  is_active     TINYINT      NOT NULL DEFAULT 1,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_mesin (mesin_id, is_active),

  CONSTRAINT fk_mesin_cycle_mesin
    FOREIGN KEY (mesin_id) REFERENCES hl_mesin(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- 3. SESI PEMAKAIAN (booking → done)
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS hl_mesin_sesi (
  id                  INT          AUTO_INCREMENT PRIMARY KEY,
  tenant_id           INT          NOT NULL,
  outlet_id           INT          NOT NULL,
  mesin_id            INT          NOT NULL,
  cycle_id            INT          NOT NULL,

  -- Customer (optional, public booking gak wajib login)
  pelanggan_nama      VARCHAR(80)  NOT NULL,
  pelanggan_telepon   VARCHAR(20)  NULL,
  pelanggan_id        INT          NULL                  COMMENT 'Kalau registered customer, link ke hl_pelanggan',

  -- Cycle snapshot (denorm — biar tarif/durasi gak berubah kalau master di-edit)
  durasi_menit        INT          NOT NULL,
  tarif               INT          NOT NULL,
  cycle_label         VARCHAR(60)  NULL,

  -- Payment
  metode_bayar        ENUM('cash','qris','transfer','koin') NOT NULL DEFAULT 'cash',
  status_bayar        ENUM('belum','lunas')                 NOT NULL DEFAULT 'belum',
  paid_at             TIMESTAMP    NULL,

  -- Lifecycle
  status              ENUM('booked','running','done','batal') NOT NULL DEFAULT 'booked',
  booked_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at          TIMESTAMP    NULL                     COMMENT 'Saat staff klik Konfirmasi Mulai',
  estimated_done_at   TIMESTAMP    NULL                     COMMENT 'started_at + durasi_menit',
  done_at             TIMESTAMP    NULL,

  -- Audit
  confirmed_by        INT          NULL                     COMMENT 'user_id kasir yg konfirmasi mulai',
  catatan             VARCHAR(200) NULL,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_mesin (mesin_id, status),
  INDEX idx_tenant_outlet (tenant_id, outlet_id, status),
  INDEX idx_telepon (pelanggan_telepon),
  INDEX idx_date (created_at),
  INDEX idx_estimated_done (estimated_done_at),

  CONSTRAINT fk_mesin_sesi_mesin
    FOREIGN KEY (mesin_id) REFERENCES hl_mesin(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- 4. PERMISSIONS (untuk tenant existing)
-- Tenant baru dapet otomatis lewat TenantProvisioner update
-- ─────────────────────────────────────────────
INSERT INTO hl_permissions (tenant_id, kode, modul, aksi, deskripsi)
SELECT DISTINCT t.tenant_id, 'mesin.view', 'mesin', 'view', 'Lihat status mesin self-service'
FROM hl_permissions t
WHERE NOT EXISTS (
  SELECT 1 FROM hl_permissions p WHERE p.tenant_id = t.tenant_id AND p.kode = 'mesin.view'
);

INSERT INTO hl_permissions (tenant_id, kode, modul, aksi, deskripsi)
SELECT DISTINCT t.tenant_id, 'mesin.operate', 'mesin', 'operate', 'Konfirmasi mulai/selesai sesi mesin'
FROM hl_permissions t
WHERE NOT EXISTS (
  SELECT 1 FROM hl_permissions p WHERE p.tenant_id = t.tenant_id AND p.kode = 'mesin.operate'
);

INSERT INTO hl_permissions (tenant_id, kode, modul, aksi, deskripsi)
SELECT DISTINCT t.tenant_id, 'mesin.manage', 'mesin', 'manage', 'Tambah/edit/hapus mesin & atur cycle'
FROM hl_permissions t
WHERE NOT EXISTS (
  SELECT 1 FROM hl_permissions p WHERE p.tenant_id = t.tenant_id AND p.kode = 'mesin.manage'
);

-- Map ke Owner (semua 3)
INSERT INTO hl_role_permissions (tenant_id, role_id, permission_id, filter_data)
SELECT p.tenant_id, r.id, p.id, 'all'
FROM hl_permissions p
JOIN hl_roles r ON r.tenant_id = p.tenant_id AND r.nama = 'Owner'
WHERE p.kode IN ('mesin.view','mesin.operate','mesin.manage')
  AND NOT EXISTS (
    SELECT 1 FROM hl_role_permissions rp
    WHERE rp.tenant_id = p.tenant_id AND rp.role_id = r.id AND rp.permission_id = p.id
  );

-- Map ke Admin (semua 3)
INSERT INTO hl_role_permissions (tenant_id, role_id, permission_id, filter_data)
SELECT p.tenant_id, r.id, p.id, 'all'
FROM hl_permissions p
JOIN hl_roles r ON r.tenant_id = p.tenant_id AND r.nama = 'Admin'
WHERE p.kode IN ('mesin.view','mesin.operate','mesin.manage')
  AND NOT EXISTS (
    SELECT 1 FROM hl_role_permissions rp
    WHERE rp.tenant_id = p.tenant_id AND rp.role_id = r.id AND rp.permission_id = p.id
  );

-- Map ke Kasir (cuma view + operate, gak manage)
INSERT INTO hl_role_permissions (tenant_id, role_id, permission_id, filter_data)
SELECT p.tenant_id, r.id, p.id, 'all'
FROM hl_permissions p
JOIN hl_roles r ON r.tenant_id = p.tenant_id AND r.nama = 'Kasir'
WHERE p.kode IN ('mesin.view','mesin.operate')
  AND NOT EXISTS (
    SELECT 1 FROM hl_role_permissions rp
    WHERE rp.tenant_id = p.tenant_id AND rp.role_id = r.id AND rp.permission_id = p.id
  );

-- ─────────────────────────────────────────────
-- 5. VERIFY
-- ─────────────────────────────────────────────
-- SELECT 'hl_mesin' AS tbl, COUNT(*) AS cnt FROM hl_mesin
-- UNION ALL SELECT 'hl_mesin_cycle', COUNT(*) FROM hl_mesin_cycle
-- UNION ALL SELECT 'hl_mesin_sesi',  COUNT(*) FROM hl_mesin_sesi;

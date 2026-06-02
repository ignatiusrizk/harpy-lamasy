-- ══════════════════════════════════════════════════════════════════
-- FIX: hl_karyawan_outlet — pastikan tabel & semua kolom ada
-- Penyebab bug #7 (Riwayat Mutasi tidak jalan): kolom unassigned_at /
-- notes / assigned_by mungkin belum ada, bikin mutasi gagal rollback
-- sehingga record historis tidak terbentuk.
-- ══════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS hl_karyawan_outlet (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id     INT NOT NULL,
    karyawan_id   INT NOT NULL,
    outlet_id     INT NOT NULL,
    is_active     TINYINT(1) DEFAULT 1,
    assigned_at   DATETIME   DEFAULT NULL,
    unassigned_at DATETIME   DEFAULT NULL,
    assigned_by   INT        DEFAULT NULL,
    notes         TEXT       DEFAULT NULL,
    created_at    TIMESTAMP  DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenant_karyawan (tenant_id, karyawan_id),
    INDEX idx_tenant_outlet   (tenant_id, outlet_id),
    INDEX idx_active          (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Defensive: tambah kolom kalau tabel sudah ada tapi belum lengkap
ALTER TABLE hl_karyawan_outlet ADD COLUMN IF NOT EXISTS is_active     TINYINT(1) DEFAULT 1;
ALTER TABLE hl_karyawan_outlet ADD COLUMN IF NOT EXISTS assigned_at   DATETIME   DEFAULT NULL;
ALTER TABLE hl_karyawan_outlet ADD COLUMN IF NOT EXISTS unassigned_at DATETIME   DEFAULT NULL;
ALTER TABLE hl_karyawan_outlet ADD COLUMN IF NOT EXISTS assigned_by   INT        DEFAULT NULL;
ALTER TABLE hl_karyawan_outlet ADD COLUMN IF NOT EXISTS notes         TEXT       DEFAULT NULL;

-- Backfill: karyawan yang punya hl_users.outlet_id tapi belum ada
-- record assignment aktif → buatkan, supaya mutasi & history konsisten
INSERT INTO hl_karyawan_outlet (tenant_id, karyawan_id, outlet_id, is_active, assigned_at)
SELECT u.tenant_id, u.id, u.outlet_id, 1, COALESCE(u.created_at, NOW())
  FROM hl_users u
 WHERE u.outlet_id > 0
   AND u.is_active = 1
   AND NOT EXISTS (
       SELECT 1 FROM hl_karyawan_outlet ko
        WHERE ko.tenant_id = u.tenant_id
          AND ko.karyawan_id = u.id
          AND ko.outlet_id = u.outlet_id
   );

SELECT 'Fix hl_karyawan_outlet OK. Backfilled assignments untuk karyawan tanpa record.' AS info;

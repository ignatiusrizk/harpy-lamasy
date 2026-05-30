-- ══════════════════════════════════════════════════════════════
-- keuangan_migration.sql
-- TASK: Laporan Keuangan Lengkap HQ (Opsi A – Lightweight SAK EMKM)
--
-- CARA EKSEKUSI DI phpMyAdmin:
--   1. Pilih database yang sama dengan hl_transaksi / hl_kas
--      (bukan database master/superadmin)
--   2. Klik tab "SQL"
--   3. Paste SELURUH isi file ini
--   4. Klik "Go" / "Eksekusi"
--
-- Semua tabel memakai CREATE TABLE IF NOT EXISTS → aman diulang.
-- Tidak ada GENERATED COLUMN (kompatibel semua versi MySQL/MariaDB).
-- ══════════════════════════════════════════════════════════════

-- Verifikasi database: pastikan ada tabel hl_transaksi di database ini
-- Jika query berikut error, berarti database yang dipilih salah:
-- SELECT COUNT(*) FROM hl_transaksi LIMIT 1;


-- ════════════════════════════════════════════════════
-- BAGIAN 1: CREATE TABLE (jalankan dulu)
-- ════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS hl_coa (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id  INT          NOT NULL,
  outlet_id  INT          NULL,
  kode       VARCHAR(20)  NOT NULL,
  nama       VARCHAR(100) NOT NULL,
  tipe       ENUM(
               'aset_lancar',
               'aset_tetap',
               'liabilitas_lancar',
               'liabilitas_jangka_panjang',
               'ekuitas',
               'pendapatan',
               'beban_pokok',
               'beban_operasional',
               'beban_lain',
               'pendapatan_lain'
             ) NOT NULL,
  is_auto    TINYINT      DEFAULT 0,
  is_active  TINYINT      DEFAULT 1,
  urutan     INT          DEFAULT 0,
  created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant (tenant_id),
  UNIQUE KEY unique_kode (tenant_id, kode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_aset_tetap (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id          INT          NOT NULL,
  outlet_id          INT          NOT NULL,
  coa_id             INT          NOT NULL,
  nama               VARCHAR(100) NOT NULL,
  deskripsi          TEXT         NULL,
  tanggal_perolehan  DATE         NOT NULL,
  nilai_perolehan    BIGINT       NOT NULL,
  nilai_sisa         BIGINT       DEFAULT 0,
  umur_ekonomis      INT          NOT NULL,
  metode_penyusutan  ENUM('garis_lurus','saldo_menurun') DEFAULT 'garis_lurus',
  status             ENUM('aktif','dijual','rusak','disposed') DEFAULT 'aktif',
  tanggal_dispose    DATE         NULL,
  nilai_jual         BIGINT       NULL,
  keterangan         TEXT         NULL,
  created_by         INT          NULL,
  created_at         TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tenant_outlet (tenant_id, outlet_id),
  INDEX idx_status        (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_liabilitas (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id           INT          NOT NULL,
  outlet_id           INT          NOT NULL,
  coa_id              INT          NOT NULL,
  nama                VARCHAR(100) NOT NULL,
  kreditur            VARCHAR(100) NULL,
  tanggal_mulai       DATE         NOT NULL,
  tanggal_jatuh_tempo DATE         NOT NULL,
  pokok_pinjaman      BIGINT       NOT NULL,
  cicilan_per_bulan   BIGINT       NOT NULL,
  bunga_per_bulan     DECIMAL(5,2) DEFAULT 0,
  saldo_awal          BIGINT       NOT NULL,
  saldo_terbayar      BIGINT       DEFAULT 0,
  status              ENUM('aktif','lunas') DEFAULT 'aktif',
  lunas_at            DATE         NULL,
  keterangan          TEXT         NULL,
  created_by          INT          NULL,
  created_at          TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tenant_outlet (tenant_id, outlet_id),
  INDEX idx_status        (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_jurnal_manual (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id    INT          NOT NULL,
  outlet_id    INT          NOT NULL,
  coa_id       INT          NOT NULL,
  tanggal      DATE         NOT NULL,
  periode      VARCHAR(7)   NOT NULL,
  keterangan   VARCHAR(200) NOT NULL,
  tipe         ENUM(
                 'modal_disetor',
                 'prive',
                 'kas_bank',
                 'persediaan',
                 'biaya_dimuka',
                 'pembayaran_hutang',
                 'penerimaan_pinjaman',
                 'beban_manual',
                 'koreksi',
                 'lainnya'
               ) NOT NULL,
  jumlah       BIGINT       NOT NULL,
  arah         ENUM('debit','kredit') NOT NULL,
  liabilitas_id INT         NULL,
  aset_id       INT         NULL,
  input_by     INT          NOT NULL,
  created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenant_outlet (tenant_id, outlet_id),
  INDEX idx_periode       (periode),
  INDEX idx_tanggal       (tanggal),
  INDEX idx_tipe          (tipe)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_kas_bank (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id          INT          NOT NULL,
  outlet_id          INT          NOT NULL,
  nama_rekening      VARCHAR(100) NOT NULL,
  bank               VARCHAR(50)  NOT NULL,
  nomor_rekening     VARCHAR(50)  NULL,
  saldo_awal         BIGINT       DEFAULT 0,
  saldo_awal_tanggal DATE         NOT NULL,
  is_primary         TINYINT      DEFAULT 0,
  is_active          TINYINT      DEFAULT 1,
  created_by         INT          NULL,
  created_at         TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tenant_outlet (tenant_id, outlet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_kas_bank_mutasi (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id   INT          NOT NULL,
  outlet_id   INT          NOT NULL,
  kas_bank_id INT          NOT NULL,
  tanggal     DATE         NOT NULL,
  periode     VARCHAR(7)   NOT NULL,
  keterangan  VARCHAR(200) NOT NULL,
  tipe        ENUM('masuk','keluar') NULL,
  jumlah      BIGINT       NULL,
  saldo_akhir BIGINT       NULL,
  input_by    INT          NOT NULL,
  created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_kas_bank (kas_bank_id),
  INDEX idx_periode  (periode),
  INDEX idx_tanggal  (tanggal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_laporan_cache (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id     INT          NOT NULL,
  outlet_id     INT          NOT NULL DEFAULT 0,
  periode       VARCHAR(7)   NOT NULL,
  tipe          ENUM('laba_rugi','neraca','arus_kas','rasio') NOT NULL,
  data          JSON         NOT NULL,
  calculated_at DATETIME     NOT NULL,
  is_final      TINYINT      DEFAULT 0,
  INDEX idx_tenant (tenant_id),
  UNIQUE KEY unique_cache (tenant_id, outlet_id, periode, tipe)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ════════════════════════════════════════════════════
-- BAGIAN 2: SEED COA DEFAULT
-- Ganti nilai di bawah sesuai tenant_id yang aktif
-- (cek: SELECT id, nama_bisnis FROM tenants LIMIT 10)
-- ════════════════════════════════════════════════════

SET @tid = 1;

INSERT IGNORE INTO hl_coa
  (tenant_id, outlet_id, kode, nama, tipe, is_auto, urutan)
VALUES
-- ASET LANCAR
(@tid, NULL, '1-1001', 'Kas Tunai',               'aset_lancar', 1,  1),
(@tid, NULL, '1-1002', 'Kas Bank / Rekening',      'aset_lancar', 0,  2),
(@tid, NULL, '1-1003', 'Piutang Usaha',            'aset_lancar', 1,  3),
(@tid, NULL, '1-1004', 'Persediaan Bahan',         'aset_lancar', 0,  4),
(@tid, NULL, '1-1005', 'Biaya Dibayar Dimuka',     'aset_lancar', 0,  5),
-- ASET TETAP
(@tid, NULL, '1-2001', 'Mesin Cuci',               'aset_tetap',  0, 10),
(@tid, NULL, '1-2002', 'Mesin Pengering',          'aset_tetap',  0, 11),
(@tid, NULL, '1-2003', 'Peralatan Setrika',        'aset_tetap',  0, 12),
(@tid, NULL, '1-2004', 'Kendaraan / Motor',        'aset_tetap',  0, 13),
(@tid, NULL, '1-2005', 'Inventaris Kantor',        'aset_tetap',  0, 14),
-- LIABILITAS LANCAR
(@tid, NULL, '2-1001', 'Hutang Usaha',             'liabilitas_lancar', 0, 20),
(@tid, NULL, '2-1002', 'Hutang Gaji',              'liabilitas_lancar', 0, 21),
(@tid, NULL, '2-1003', 'Cicilan Jatuh Tempo',      'liabilitas_lancar', 0, 22),
-- LIABILITAS JANGKA PANJANG
(@tid, NULL, '2-2001', 'Pinjaman Bank / KUR',      'liabilitas_jangka_panjang', 0, 30),
(@tid, NULL, '2-2002', 'Cicilan Kendaraan',        'liabilitas_jangka_panjang', 0, 31),
(@tid, NULL, '2-2003', 'Cicilan Mesin',            'liabilitas_jangka_panjang', 0, 32),
-- EKUITAS
(@tid, NULL, '3-1001', 'Modal Disetor',            'ekuitas', 0, 40),
(@tid, NULL, '3-1002', 'Laba Ditahan',             'ekuitas', 1, 41),
(@tid, NULL, '3-1003', 'Prive / Penarikan Owner',  'ekuitas', 0, 42),
-- PENDAPATAN
(@tid, NULL, '4-1001', 'Pendapatan Kiloan',        'pendapatan', 1, 50),
(@tid, NULL, '4-1002', 'Pendapatan B2B',           'pendapatan', 1, 51),
(@tid, NULL, '4-1003', 'Pendapatan Drop Point',    'pendapatan', 1, 52),
(@tid, NULL, '4-1099', 'Pendapatan Lain-lain',     'pendapatan', 0, 53),
-- BEBAN
(@tid, NULL, '5-1001', 'Beban Gaji Karyawan',      'beban_operasional', 1, 60),
(@tid, NULL, '5-1002', 'Beban Bahan Habis Pakai',  'beban_operasional', 0, 61),
(@tid, NULL, '5-1003', 'Beban Sewa',               'beban_operasional', 0, 62),
(@tid, NULL, '5-1004', 'Beban Utilitas',           'beban_operasional', 0, 63),
(@tid, NULL, '5-1005', 'Beban Penyusutan',         'beban_operasional', 1, 64),
(@tid, NULL, '5-1006', 'Beban Bunga Pinjaman',     'beban_operasional', 0, 65),
(@tid, NULL, '5-1007', 'Beban Pemasaran',          'beban_operasional', 0, 66),
(@tid, NULL, '5-1008', 'Beban Komisi Mitra',       'beban_operasional', 1, 67),
(@tid, NULL, '5-1099', 'Beban Operasional Lain',   'beban_operasional', 1, 68);

-- ════════════════════════════════════════════════════
-- VERIFIKASI (jalankan setelah selesai)
-- ════════════════════════════════════════════════════
-- SELECT 'hl_coa'            t, COUNT(*) n FROM hl_coa            WHERE tenant_id=@tid
-- UNION ALL
-- SELECT 'hl_aset_tetap'     t, COUNT(*) n FROM hl_aset_tetap     WHERE tenant_id=@tid
-- UNION ALL
-- SELECT 'hl_liabilitas'     t, COUNT(*) n FROM hl_liabilitas     WHERE tenant_id=@tid
-- UNION ALL
-- SELECT 'hl_jurnal_manual'  t, COUNT(*) n FROM hl_jurnal_manual  WHERE tenant_id=@tid
-- UNION ALL
-- SELECT 'hl_kas_bank'       t, COUNT(*) n FROM hl_kas_bank       WHERE tenant_id=@tid
-- UNION ALL
-- SELECT 'hl_kas_bank_mutasi't, COUNT(*) n FROM hl_kas_bank_mutasi WHERE tenant_id=@tid
-- UNION ALL
-- SELECT 'hl_laporan_cache'  t, COUNT(*) n FROM hl_laporan_cache  WHERE tenant_id=@tid;

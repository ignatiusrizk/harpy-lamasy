-- ══════════════════════════════════════════════════════════════════
-- COIN PRICING MANAGEMENT — Migration
-- Pindahkan harga coin dari CoinLedger::COSTS (hardcoded) ke DB
-- supaya bisa di-manage dari super admin tanpa edit kode.
-- ══════════════════════════════════════════════════════════════════

-- 1. Tabel pricing utama
CREATE TABLE IF NOT EXISTS saas_coin_pricing (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    feature_key       VARCHAR(50)  UNIQUE NOT NULL,
    nama_fitur        VARCHAR(100) NOT NULL,
    deskripsi         TEXT         NULL,
    kategori          ENUM('dokumen','whatsapp','ai','export','lainnya') DEFAULT 'lainnya',
    harga_coin        INT          NOT NULL DEFAULT 0,
    harga_minimum     INT          DEFAULT 0,
    is_active         TINYINT      DEFAULT 1,
    catatan_internal  TEXT         NULL,
    updated_by        INT          NULL,
    updated_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_kategori (kategori),
    INDEX idx_active   (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Tabel history perubahan harga (audit trail)
CREATE TABLE IF NOT EXISTS saas_coin_pricing_history (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    feature_key  VARCHAR(50)  NOT NULL,
    harga_lama   INT          NOT NULL,
    harga_baru   INT          NOT NULL,
    is_active_lama TINYINT    NULL,
    is_active_baru TINYINT    NULL,
    changed_by   INT          NOT NULL,
    alasan       TEXT         NULL,
    changed_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_feature (feature_key),
    INDEX idx_date    (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ──────────────────────────────────────────────────────────────────
-- 3. Seed data — gabungan dari CoinLedger::COSTS existing + key baru
-- ──────────────────────────────────────────────────────────────────
-- Catatan: feature_key dipertahankan sama dengan yang dipakai di kode
-- supaya backward compatible. Tidak ada call site yang perlu diubah.

INSERT IGNORE INTO saas_coin_pricing
    (feature_key, nama_fitur, kategori, harga_coin, harga_minimum, deskripsi, catatan_internal)
VALUES
-- ── DOKUMEN ──────────────────────────────────────────────
('generate_nota',      'Generate Nota / Struk',         'dokumen',  50,  10,
    'Cetak ulang nota / struk transaksi',
    NULL),
('generate_invoice',   'Generate Invoice B2B',          'dokumen', 200,  50,
    'Generate invoice formal untuk pelanggan korporat',
    NULL),
('invoice_b2b',        'Invoice B2B (legacy alias)',    'dokumen', 200,  50,
    'Alias lama untuk generate_invoice — tetap aktif untuk backward compat',
    'DEPRECATED: pakai generate_invoice'),

-- ── WHATSAPP ─────────────────────────────────────────────
('send_wa_notif',      'Kirim WA Notifikasi Status',    'whatsapp', 100, 20,
    'Notifikasi update status order ke pelanggan',
    'Termasuk WA API cost'),
('send_wa_nota',       'Kirim Nota PDF via WA',         'whatsapp', 150, 30,
    'Kirim nota lengkap saat order baru tersimpan',
    NULL),
('wa_blast',           'WA Blast (per penerima)',       'whatsapp', 100, 20,
    'Broadcast pesan ke banyak pelanggan/staff',
    NULL),
('daily_report',       'WA Laporan Harian Owner',       'whatsapp', 100, 20,
    'Kirim laporan harian otomatis ke WA owner',
    NULL),
('alert_anomali',      'WA Alert Anomali ke Owner',     'whatsapp', 50,  20,
    'Alert otomatis saat ada anomali transaksi',
    NULL),
('reminder_piutang',   'WA Reminder Piutang B2B',       'whatsapp', 100, 20,
    'Reminder pembayaran untuk pelanggan korporat',
    NULL),

-- ── AI ───────────────────────────────────────────────────
('ai_briefing',        'AI Briefing Harian Outlet',     'ai', 500, 300,
    'Briefing AI per outlet di awal hari',
    'Claude API ~Rp 160/call, margin 3x'),
('ai_briefing_hq',     'AI Briefing HQ (lintas outlet)','ai', 80,  50,
    'Briefing AI untuk owner — semua outlet',
    'Cost lebih kecil karena dipotong di HQ level'),
('ai_upselling',       'AI Upselling di POS',           'ai', 50,  20,
    'Saran upselling otomatis saat kasir POS',
    'Di-cache 24h, cost efektif rendah'),
('ai_analyst',         'AI Analyst Laporan',            'ai', 200, 100,
    'Tanya jawab AI ke data laporan',
    'Per query Claude API'),
('ai_review',          'AI Review Responder',           'ai', 300, 200,
    'AI bantu balas review pelanggan',
    NULL),
('ai_insight_laporan', 'AI Insight di Laporan',         'ai', 100, 50,
    'Insight cerdas otomatis di halaman laporan',
    NULL),
('ai_chat_data',       'AI Chat dengan Data',           'ai', 50,  20,
    'Chat AI dengan akses data tenant',
    NULL),
('ai_churn_message',   'AI Pesan Retensi Pelanggan',    'ai', 30,  10,
    'Generate pesan personal untuk pelanggan dormant',
    NULL),
('ai_migration_mapping','AI Migration Mapper',          'ai', 1000, 500,
    'Map data import dari aplikasi lama (one-time per file)',
    'Skip kalau pakai assisted migration'),

-- ── EXPORT ───────────────────────────────────────────────
('export_pdf',         'Export Laporan PDF',            'export', 500, 200,
    'Export laporan ke PDF (HTML2PDF)',
    NULL);

-- ──────────────────────────────────────────────────────────────────
-- 4. Verifikasi
-- ──────────────────────────────────────────────────────────────────
SELECT COUNT(*) AS total_fitur,
       SUM(is_active = 1) AS aktif,
       SUM(kategori='ai') AS ai,
       SUM(kategori='whatsapp') AS wa,
       SUM(kategori='dokumen') AS dok,
       SUM(kategori='export') AS exp
FROM saas_coin_pricing;

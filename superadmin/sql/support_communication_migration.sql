-- ══════════════════════════════════════════════════════════════
-- support_communication_migration.sql
-- Support & Komunikasi Tenant — LaMaSy SuperAdmin
--
-- Jalankan SETELAH:
--   schema.sql (support_tickets & tenant_notes sudah ada)
--   billing_system_migration.sql
--
-- Aman dijalankan ulang — semua pakai IF NOT EXISTS / INSERT IGNORE
-- ══════════════════════════════════════════════════════════════

SET FOREIGN_KEY_CHECKS = 0;

-- ════════════════════════════════════════════════════════
-- BAGIAN 1: ALTER support_tickets
-- Tabel sudah ada (schema.sql) tapi sangat minimal.
-- Tambahkan semua kolom yang dibutuhkan sistem baru.
-- Kolom lama (superadmin_id, channel, type) dipertahankan
-- untuk backward compat — tidak dihapus.
-- ════════════════════════════════════════════════════════

ALTER TABLE support_tickets
  -- Outlet origin (tiket dari outlet tertentu atau HQ)
  ADD COLUMN IF NOT EXISTS outlet_id        INT           NULL
    COMMENT 'outlets.id — tiket dari outlet spesifik, NULL = HQ/tenant level',
  -- Siapa yang submit (hl_users tenant, bukan superadmin)
  ADD COLUMN IF NOT EXISTS submitted_by     INT           NULL
    COMMENT 'hl_users.id yang submit tiket (NULL = dibuat superadmin)',
  -- Lampiran
  ADD COLUMN IF NOT EXISTS attachment_url   VARCHAR(255)  NULL
    COMMENT 'Path/URL screenshot atau foto pendukung',
  -- Kategorisasi (berbeda dari kolom `type` lama)
  ADD COLUMN IF NOT EXISTS category         ENUM(
                                              'billing',
                                              'teknis',
                                              'fitur',
                                              'akun',
                                              'lainnya'
                                            )             NOT NULL DEFAULT 'lainnya'
    COMMENT 'Kategori tiket untuk routing & reporting',
  -- Prioritas
  ADD COLUMN IF NOT EXISTS priority         ENUM('low','normal','high','critical')
                                                          NOT NULL DEFAULT 'normal',
  -- Siapa yang handle
  ADD COLUMN IF NOT EXISTS assigned_to      INT           NULL
    COMMENT 'super_admins.id yang di-assign handle tiket ini',
  -- Status flow
  ADD COLUMN IF NOT EXISTS status           ENUM(
                                              'open',
                                              'in_progress',
                                              'waiting_tenant',
                                              'resolved',
                                              'closed'
                                            )             NOT NULL DEFAULT 'open'
    COMMENT 'open→in_progress→waiting_tenant↔in_progress→resolved→closed',
  -- SLA timing
  ADD COLUMN IF NOT EXISTS first_response_at DATETIME     NULL
    COMMENT 'Kapan superadmin pertama kali balas tiket ini',
  ADD COLUMN IF NOT EXISTS resolved_at      DATETIME      NULL,
  ADD COLUMN IF NOT EXISTS closed_at        DATETIME      NULL,
  -- Rating dari tenant setelah resolved
  ADD COLUMN IF NOT EXISTS rating           TINYINT       NULL
    COMMENT '1-5 bintang, diisi tenant setelah tiket resolved',
  ADD COLUMN IF NOT EXISTS rating_comment   TEXT          NULL,
  -- updated_at
  ADD COLUMN IF NOT EXISTS updated_at       TIMESTAMP     NOT NULL
                                            DEFAULT CURRENT_TIMESTAMP
                                            ON UPDATE CURRENT_TIMESTAMP;

-- Index (CREATE INDEX IF NOT EXISTS tersedia di MariaDB 10.1.4+)
CREATE INDEX IF NOT EXISTS idx_st_status   ON support_tickets (status);
CREATE INDEX IF NOT EXISTS idx_st_assigned ON support_tickets (assigned_to);
CREATE INDEX IF NOT EXISTS idx_st_priority ON support_tickets (priority);
CREATE INDEX IF NOT EXISTS idx_st_updated  ON support_tickets (updated_at);

-- Migrate baris lama: set status = 'closed' agar tidak muncul sebagai open
UPDATE support_tickets
   SET status = 'closed'
 WHERE status = 'open'
   AND submitted_by IS NULL
   AND created_at < DATE_SUB(NOW(), INTERVAL 1 DAY);


-- ════════════════════════════════════════════════════════
-- BAGIAN 2: support_ticket_replies
-- Thread percakapan per tiket
-- ════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS support_ticket_replies (
  id             INT           AUTO_INCREMENT PRIMARY KEY,
  ticket_id      INT           NOT NULL
    COMMENT 'FK → support_tickets.id',

  -- Pengirim — tepat salah satu terisi
  superadmin_id  INT           NULL
    COMMENT 'super_admins.id jika pengirim adalah superadmin',
  user_id        INT           NULL
    COMMENT 'hl_users.id jika pengirim adalah tenant/user',

  message        TEXT          NOT NULL,
  attachment_url VARCHAR(255)  NULL,

  -- Internal note: tidak terlihat tenant, tidak mengirim notif
  is_internal    TINYINT(1)    NOT NULL DEFAULT 0
    COMMENT '1 = catatan internal superadmin, tidak ditampilkan ke tenant',

  created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_str_ticket  (ticket_id),
  INDEX idx_str_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ════════════════════════════════════════════════════════
-- BAGIAN 3: saas_announcements
-- Changelog, maintenance notice, promo, info platform
-- ════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS saas_announcements (
  id               INT           AUTO_INCREMENT PRIMARY KEY,
  superadmin_id    INT           NOT NULL
    COMMENT 'super_admins.id yang membuat announcement',

  -- Konten
  title            VARCHAR(200)  NOT NULL,
  content          TEXT          NOT NULL,

  -- Tipe
  type             ENUM(
                     'fitur_baru',   -- fitur/update baru
                     'maintenance',  -- planned downtime
                     'penting',      -- info penting / urgent
                     'promo',        -- promo / penawaran
                     'umum'          -- info umum
                   )             NOT NULL DEFAULT 'umum',

  -- Target audience
  target_audience  ENUM(
                     'semua',    -- semua tenant aktif
                     'trial',    -- tenant yang outletnya masih trial
                     'active',   -- tenant dengan outlet aktif
                     'grace',    -- tenant dalam grace period
                     'chain'     -- tenant dengan 2+ outlet
                   )             NOT NULL DEFAULT 'semua',

  -- Tampilan
  is_pinned        TINYINT(1)    NOT NULL DEFAULT 0
    COMMENT 'Tampil di urutan paling atas',
  show_as_banner   TINYINT(1)    NOT NULL DEFAULT 0
    COMMENT 'Tampil sebagai banner dismissible di dashboard tenant',
  banner_color     ENUM('blue','green','amber','red')
                                 NOT NULL DEFAULT 'blue',

  -- Publish control
  status           ENUM('draft','published','archived')
                                 NOT NULL DEFAULT 'draft',
  published_at     DATETIME      NULL,
  expires_at       DATETIME      NULL
    COMMENT 'Auto-archive setelah tanggal ini; NULL = tidak expire',

  -- Engagement stats
  total_views      INT           NOT NULL DEFAULT 0,
  total_reads      INT           NOT NULL DEFAULT 0,

  created_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_ann_status    (status),
  INDEX idx_ann_published (published_at),
  INDEX idx_ann_pinned    (is_pinned),
  INDEX idx_ann_expires   (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ════════════════════════════════════════════════════════
-- BAGIAN 4: saas_announcement_reads
-- Tracking per-tenant siapa yang sudah baca announcement
-- PK gabungan → satu tenant hanya bisa baca 1x per announcement
-- ════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS saas_announcement_reads (
  announcement_id  INT           NOT NULL,
  tenant_id        INT           NOT NULL,
  read_at          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (announcement_id, tenant_id),
  INDEX idx_sar_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ════════════════════════════════════════════════════════
-- BAGIAN 5: ALTER tenant_notes
-- Tambah kolom tag untuk kategorisasi catatan
-- ════════════════════════════════════════════════════════

ALTER TABLE tenant_notes
  ADD COLUMN IF NOT EXISTS tag ENUM(
                                 'onboarding',
                                 'billing',
                                 'teknis',
                                 'followup',
                                 'umum'
                               )         NOT NULL DEFAULT 'umum'
    COMMENT 'Kategori catatan untuk filtering di client_detail.php';


-- ════════════════════════════════════════════════════════
-- SELESAI
-- Verifikasi:
--   DESCRIBE support_tickets;
--   DESCRIBE support_ticket_replies;
--   DESCRIBE saas_announcements;
--   DESCRIBE saas_announcement_reads;
--   SHOW COLUMNS FROM tenant_notes LIKE 'tag';
-- ════════════════════════════════════════════════════════

SET FOREIGN_KEY_CHECKS = 1;

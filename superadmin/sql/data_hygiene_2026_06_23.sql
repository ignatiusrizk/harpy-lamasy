-- ════════════════════════════════════════════════════════════════════
-- Data hygiene cleanup — 2026-06-23
--
-- 1. Stale 'siap' orders dari import lama (tanggal sebelum Juni 2026)
--    → mark sebagai 'diambil' dgn catatan migration.
--    Rationale: orders dari Mei 2026 yang masih 'siap' di Juni 2026
--    pasti sudah selesai diambil customer — status tidak ter-update karena
--    flow Produksi/Kanban belum ada saat itu. Tampil di dashboard
--    Pipeline ("869 Siap Diambil") membingungkan operator.
--
-- 2. Customer dgn registered_outlet_id NULL tapi punya outlet_id (legacy
--    column) → backfill registered_outlet_id = outlet_id.
--    Rationale: tampil 'Outlet tidak diketahui'/'Belum di-assign' di
--    /hq/pelanggan padahal sebenarnya ada outlet asal.
-- ════════════════════════════════════════════════════════════════════

START TRANSACTION;

-- ── Step 1: Close stale 'siap' orders (cutoff: bulan ini) ────────────
UPDATE hl_transaksi
   SET status_proses = 'diambil',
       catatan_internal = CONCAT(COALESCE(catatan_internal,''),
                                 IF(catatan_internal IS NULL OR catatan_internal='', '', ' | '),
                                 '[auto-cleanup 2026-06-23] status siap > 30 hari, asumsi sudah diambil')
 WHERE status_proses = 'siap'
   AND tanggal < DATE_SUB(CURDATE(), INTERVAL 30 DAY);

SELECT ROW_COUNT() AS stale_orders_closed;

-- ── Step 2: Backfill registered_outlet_id ────────────────────────────
UPDATE hl_pelanggan
   SET registered_outlet_id = outlet_id
 WHERE (registered_outlet_id IS NULL OR registered_outlet_id = 0)
   AND outlet_id IS NOT NULL
   AND outlet_id > 0;

SELECT ROW_COUNT() AS customers_backfilled;

-- Log to audit (1 row per tenant yg affected)
INSERT INTO hl_audit_log (tenant_id, user_id, user_nama, user_role, modul, aksi, keterangan, created_at)
SELECT DISTINCT tenant_id, NULL, 'system', 'system', 'system', 'data_hygiene',
       'Cleanup: close stale siap orders >30d + backfill registered_outlet_id', NOW()
FROM hl_transaksi
WHERE status_proses = 'diambil'
  AND catatan_internal LIKE '%[auto-cleanup 2026-06-23]%';

COMMIT;

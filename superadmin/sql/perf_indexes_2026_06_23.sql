-- ════════════════════════════════════════════════════════════════════
-- Performance: composite index untuk hot-path query
-- Tanggal: 2026-06-23 (Asia/Jakarta)
--
-- Existing indexes cover banyak skenario, tapi 3 query pattern paling
-- sering dipakai masih full-scan / partial-scan karena composite key
-- yang dipakai filter belum ada index-nya.
--
-- EXPLAIN sebelum migration:
--   - hl_transaksi tenant+outlet+status_proses → type=ALL, rows=872
--   - hl_transaksi tenant+outlet+tanggal       → type=range (partial)
--   - hl_audit_log tenant+created_at           → type=ALL
--
-- Indexes ditambah:
--   1. hl_transaksi(tenant_id, outlet_id, status_proses) — dashboard
--      pipeline + orders list filter status per outlet
--   2. hl_transaksi(tenant_id, outlet_id, tanggal)       — orders/laporan
--      list per outlet per date range
--   3. hl_audit_log(tenant_id, created_at)               — audit filter
--      date range per tenant
--
-- Trade-off: insert/update jadi sedikit lebih lambat (~5% per index).
-- Acceptable karena tabel write rate rendah (laundry = ratusan order/hari).
-- ════════════════════════════════════════════════════════════════════

-- Step 1: hl_transaksi composite — outlet + status_proses
ALTER TABLE hl_transaksi
  ADD INDEX idx_tenant_outlet_status (tenant_id, outlet_id, status_proses);

-- Step 2: hl_transaksi composite — outlet + tanggal
ALTER TABLE hl_transaksi
  ADD INDEX idx_tenant_outlet_tanggal (tenant_id, outlet_id, tanggal);

-- Step 3: hl_audit_log composite — tenant + created_at
ALTER TABLE hl_audit_log
  ADD INDEX idx_tenant_created (tenant_id, created_at);

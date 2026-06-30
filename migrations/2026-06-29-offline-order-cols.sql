ALTER TABLE hl_transaksi
  ADD COLUMN offline_ref  VARCHAR(40) NULL AFTER no_order,
  ADD COLUMN offline_uuid CHAR(36)    NULL AFTER offline_ref,
  ADD UNIQUE KEY uniq_offline_uuid (offline_uuid),
  ADD KEY idx_offline_ref (offline_ref);

-- Invoice B2B Enhancement — PPN + nomor invoice formal
ALTER TABLE hl_piutang
  ADD COLUMN invoice_no VARCHAR(40) NULL AFTER id,
  ADD COLUMN pajak_persen DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER total_tagihan;

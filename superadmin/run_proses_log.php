<?php
// ONE-TIME MIGRATION: buat tabel hl_proses_log
// Hapus file ini setelah berhasil dijalankan!
if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';

header('Content-Type: text/plain; charset=utf-8');

$sql = "CREATE TABLE IF NOT EXISTS hl_proses_log (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id      INT          NULL,
  transaksi_id   INT          NOT NULL,
  status_lama    VARCHAR(50)  NULL,
  status_baru    VARCHAR(50)  NOT NULL,
  tipe           VARCHAR(50)  NULL DEFAULT 'manual',
  catatan        TEXT         NULL,
  oleh           VARCHAR(100) NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_transaksi (transaksi_id),
  INDEX idx_tenant    (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

try {
    $db = Database::get();
    $db->exec($sql);
    echo "✅ Berhasil: tabel hl_proses_log sudah dibuat (atau sudah ada).\n";
    echo "PENTING: Hapus file ini setelah migrasi selesai!\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

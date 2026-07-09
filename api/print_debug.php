<?php
// api/print_debug.php — terima trace diagnostik cetak thermal dari APK (posTestPrint)
// dan simpan ke saas_error_log supaya bisa dibaca dari server tanpa akses device.
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/ErrorLogger.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error' => 'POST only']); exit; }
verifyCsrf();

$d = json_decode(file_get_contents('php://input'), true) ?: [];
$trace = trim((string)($d['trace'] ?? ''));
if ($trace === '') { echo json_encode(['error' => 'trace kosong']); exit; }

ErrorLogger::log('print_debug', substr($trace, 0, 4000), TenantResolver::id(), TenantResolver::outletId());
echo json_encode(['ok' => true]);

<?php
// api/lead.php — Lead capture endpoint dari landing page exit-intent
//
// POST { email, source } → INSERT hl_leads

define('ROOT', dirname(__DIR__));
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$email = trim($input['email'] ?? '');
$source = preg_replace('/[^a-z_]/', '', $input['source'] ?? 'exit_intent');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Email tidak valid']);
    exit;
}

if (strlen($email) > 255) {
    http_response_code(400);
    echo json_encode(['error' => 'Email terlalu panjang']);
    exit;
}

try {
    $db = Database::get();
    $stmt = $db->prepare(
        "INSERT INTO hl_leads (email, source, user_agent, ip_address, referrer)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $email,
        $source,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500),
    ]);
    echo json_encode(['ok' => true, 'message' => 'Terima kasih! Cek email kamu untuk panduan.']);
} catch (Throwable $e) {
    error_log('[api/lead.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Gagal simpan. Coba lagi sebentar.']);
}

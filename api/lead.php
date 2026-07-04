<?php
// api/lead.php — Lead capture endpoint dari landing page exit-intent
//
// POST { email, source } → INSERT hl_leads

define('ROOT', dirname(__DIR__));
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';
require_once ROOT . '/core/Mailer.php';

header('Content-Type: application/json');

// Kirim email sambutan (best-effort — tak boleh gagalkan capture)
function leadSendWelcome(string $email): void {
    try {
        $reg = 'https://lamasy.harpy.id/register.php';
        $content = "
          <h2 style='margin:0 0 10px;color:#0A0F1F'>Terima kasih sudah gabung! 👋</h2>
          <p style='font-size:15px;line-height:1.6;color:#334155'>Kamu sekarang di daftar LAMASY —
             kami bakal kirim tips & update seputar bikin bisnis laundry lebih rapi &amp; cuan
             (POS, laporan keuangan otomatis, AI briefing, WhatsApp).</p>
          <p style='font-size:15px;line-height:1.6;color:#334155'>Mau langsung coba? Trial
             <strong>gratis 7 hari</strong>, tanpa kartu kredit.</p>
          <p style='text-align:center;margin:26px 0'>
            <a href='{$reg}' style='display:inline-block;background:#14b8a6;color:#fff;text-decoration:none;
               font-weight:700;padding:13px 28px;border-radius:10px;font-size:15px'>Coba Gratis 7 Hari →</a>
          </p>
          <p style='font-size:12px;color:#94a3b8'>Kamu menerima email ini karena mendaftar di lamasy.harpy.id.</p>";
        Mailer::send($email, 'Calon Juragan Laundry', 'Terima kasih sudah gabung LAMASY 👋',
                     Mailer::baseTemplate('Selamat datang di LAMASY', $content));
    } catch (Throwable $e) { error_log('[api/lead welcome] '.$e->getMessage()); }
}

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

// Prefer CF real IP, truncate to 45 chars (IPv6 max)
$ip = substr($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '', 0, 45);

try {
    $db = Database::get();

    // Rate limit: max 3 submissions per IP per minute
    $rl = $db->prepare(
        "SELECT COUNT(*) FROM hl_leads WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)"
    );
    $rl->execute([$ip]);
    if ((int)$rl->fetchColumn() >= 3) {
        http_response_code(429);
        echo json_encode(['error' => 'Terlalu cepat, coba lagi nanti.']);
        exit;
    }

    $stmt = $db->prepare(
        "INSERT INTO hl_leads (email, source, user_agent, ip_address, referrer)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $email,
        $source,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        $ip,
        substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500),
    ]);
    leadSendWelcome($email);
    echo json_encode(['ok' => true, 'message' => 'Terima kasih! Cek email kamu sebentar lagi.']);
} catch (PDOException $e) {
    // UNIQUE constraint violation — email already registered
    if ($e->getCode() === '23000') {
        echo json_encode(['ok' => true, 'message' => 'Email sudah terdaftar, terima kasih!']);
        exit;
    }
    error_log('[api/lead.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Gagal simpan. Coba lagi sebentar.']);
} catch (Throwable $e) {
    error_log('[api/lead.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Gagal simpan. Coba lagi sebentar.']);
}

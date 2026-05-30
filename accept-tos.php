<?php
// accept-tos.php — Tenant wajib accept ToS versi baru sebelum lanjut
define('ROOT', __DIR__);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure',   1);
ini_set('session.cookie_samesite', 'Strict');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';

// Harus login
if (empty($_SESSION['user_id']) || empty($_SESSION['tenant_id'])) {
    header('Location: /login');
    exit;
}

$tenantId = (int)$_SESSION['tenant_id'];
$db       = Database::get();

// Ambil versi ToS aktif
$tosRow   = $db->query("SELECT version, effective_date FROM saas_tos_versions WHERE is_current=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$tosVer   = $tosRow['version']        ?? '1.0';
$tosDate  = $tosRow['effective_date'] ?? date('Y-m-d');

// CSRF sederhana
if (empty($_SESSION['accept_tos_token'])) {
    $_SESSION['accept_tos_token'] = bin2hex(random_bytes(16));
}
$csrfToken = $_SESSION['accept_tos_token'];

$error = '';

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $given = $_POST['_csrf'] ?? '';
    if (!hash_equals($csrfToken, $given)) {
        $error = 'Request tidak valid. Coba lagi.';
    } elseif (empty($_POST['accept_tos'])) {
        $error = 'Anda harus menyetujui Syarat & Ketentuan untuk melanjutkan.';
    } else {
        $db->prepare(
            "UPDATE tenants SET tos_accepted_at=NOW(), tos_version=?, tos_ip=? WHERE id=?"
        )->execute([$tosVer, $_SERVER['REMOTE_ADDR'] ?? null, $tenantId]);

        unset($_SESSION['accept_tos_token']);
        header('Location: /dashboard');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Syarat &amp; Ketentuan Diperbarui — LaMaSy</title>
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{
      font-family:'Segoe UI',Arial,sans-serif;
      background:linear-gradient(135deg,#0F1C3A,#1F3864);
      min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;
    }
    .card{background:#fff;border-radius:20px;padding:48px 40px;max-width:520px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.25)}
    .badge{display:inline-block;background:#FEF3C7;color:#92400E;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;margin-bottom:20px}
    h1{font-size:22px;font-weight:800;color:#1F3864;margin-bottom:10px}
    p{font-size:14px;color:#5a6a8a;line-height:1.7;margin-bottom:14px}
    .tos-preview{
      background:#f5f7fa;border:1px solid #e0e8f0;border-radius:10px;
      padding:16px;max-height:200px;overflow-y:auto;font-size:12.5px;color:#5a6a8a;
      line-height:1.6;margin:16px 0;
    }
    .check-label{display:flex;gap:10px;align-items:flex-start;margin:20px 0;cursor:pointer}
    .check-label input{width:18px;height:18px;accent-color:#2E5FA3;flex-shrink:0;margin-top:2px}
    .check-label span{font-size:13.5px;color:#1a2540;line-height:1.5}
    .check-label a{color:#2E5FA3}
    .btn{
      width:100%;padding:14px;background:#1F3864;color:#fff;border:none;border-radius:10px;
      font-size:15px;font-weight:700;cursor:pointer;margin-top:6px;
    }
    .btn:hover{background:#2E5FA3}
    .error{background:#FEE2E2;color:#991B1B;padding:12px 14px;border-radius:8px;font-size:13px;margin-bottom:16px}
    .logout{text-align:center;margin-top:20px;font-size:12px;color:#aab}
    .logout a{color:#2E5FA3;text-decoration:none}
    @media(max-width:480px){.card{padding:32px 20px}}
  </style>
</head>
<body>
<div class="card">
  <div class="badge">📋 Pembaruan Ketentuan</div>
  <h1>Syarat &amp; Ketentuan Diperbarui</h1>
  <p>
    LaMaSy telah memperbarui Syarat &amp; Ketentuan Penggunaan ke
    <strong>versi <?= htmlspecialchars($tosVer) ?></strong>
    (berlaku <?= htmlspecialchars(date('d M Y', strtotime($tosDate))) ?>).
    Anda perlu menyetujui ketentuan baru sebelum dapat melanjutkan.
  </p>

  <div class="tos-preview">
    <strong>Ringkasan perubahan:</strong><br><br>
    Versi ini merupakan ketentuan resmi pertama LaMaSy yang mencakup definisi layanan,
    kewajiban pengguna, sistem Coin, kebijakan data, dan pembatasan tanggung jawab.
    Silakan baca ketentuan lengkap sebelum menyetujui.
  </div>

  <?php if ($error): ?>
  <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
    <label class="check-label">
      <input type="checkbox" name="accept_tos" value="1" required>
      <span>
        Saya telah membaca dan menyetujui
        <a href="/tos" target="_blank">Syarat &amp; Ketentuan</a>
        dan
        <a href="/privacy" target="_blank">Kebijakan Privasi</a>
        LaMaSy versi <?= htmlspecialchars($tosVer) ?>.
      </span>
    </label>
    <button type="submit" class="btn">✓ Setuju &amp; Lanjutkan</button>
  </form>

  <div class="logout">
    Tidak ingin melanjutkan? <a href="/logout">Logout</a>
  </div>
</div>
</body>
</html>

<?php
// ══════════════════════════════════════════════════════
// superadmin/login-2fa.php — Verifikasi OTP 2FA
// ══════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/../master/config/db.php';
require_once SA_ROOT . '/../core/Database.php';
require_once SA_ROOT . '/../core/Sa2FA.php';

date_default_timezone_set('Asia/Jakarta');
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Strict');

if (session_status() === PHP_SESSION_NONE) session_start();

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
}

// Validate pending state
$pending = $_SESSION['sa_pending_2fa'] ?? null;
if (!$pending || empty($pending['sa_id'])) {
    header('Location: login.php');
    exit;
}

// Timeout: pending state berlaku max 15 menit
if (time() - ($pending['started'] ?? 0) > 900) {
    unset($_SESSION['sa_pending_2fa']);
    header('Location: login.php?msg=2fa_timeout');
    exit;
}

function sa2faCsrf(): string {
    if (empty($_SESSION['sa_csrf'])) {
        $_SESSION['sa_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['sa_csrf'];
}

$error = '';
$msg = '';

// Get email hint untuk display
$saId = (int)$pending['sa_id'];
$row = Database::get()->prepare("SELECT username, name, email, password FROM super_admins WHERE id=?");
$row->execute([$saId]);
$admin = $row->fetch(PDO::FETCH_ASSOC);
if (!$admin) {
    unset($_SESSION['sa_pending_2fa']);
    header('Location: login.php');
    exit;
}

$emailParts = explode('@', $admin['email'] ?? '');
$emailHint = count($emailParts) === 2
    ? (strlen($emailParts[0]) <= 2 ? '***' : $emailParts[0][0] . str_repeat('*', max(1, strlen($emailParts[0]) - 2)) . substr($emailParts[0], -1)) . '@' . $emailParts[1]
    : 'email terdaftar';

// Action: resend code
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend') {
    if (!hash_equals(sa2faCsrf(), $_POST['_csrf'] ?? '')) {
        $error = 'Request tidak valid.';
    } else {
        $send = Sa2FA::send($saId);
        if ($send['ok']) {
            $msg = 'Kode baru dikirim ke ' . htmlspecialchars($emailHint);
        } else {
            $error = $send['error'] ?? 'Gagal kirim ulang.';
        }
    }
}

// Action: verify code
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');
    if (!hash_equals(sa2faCsrf(), $_POST['_csrf'] ?? '')) {
        $error = 'Request tidak valid.';
    } else {
        $result = Sa2FA::verify($saId, $code);
        if ($result['ok']) {
            // Activate full session
            Database::get()->prepare("UPDATE super_admins SET last_login=NOW() WHERE id=?")->execute([$saId]);
            $_SESSION['superadmin_id'] = $saId;
            $_SESSION['sa_user'] = [
                'id'       => $saId,
                'username' => $admin['username'],
                'name'     => $admin['name'],
            ];
            require_once SA_ROOT . '/../core/SaPermission.php';
            SaPermission::loadIntoSession($saId);

            try {
                Database::get()->prepare(
                    "INSERT INTO superadmin_logs (superadmin_id, action, description, ip_address)
                     VALUES (?, 'login_2fa', '2FA email verified', ?)"
                )->execute([$saId, $pending['ip'] ?? '0.0.0.0']);
            } catch (Throwable $e) { /* ignore */ }

            unset($_SESSION['sa_pending_2fa']);
            session_regenerate_id(true);
            header('Location: dashboard.php');
            exit;
        } else {
            $error = $result['error'] ?? 'Kode tidak valid.';
        }
    }
}

// Action: cancel — back to login
if (($_GET['cancel'] ?? '') === '1') {
    unset($_SESSION['sa_pending_2fa']);
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<link rel="icon" type="image/png" href="/assets/icon-192.png"/>
<meta name="theme-color" content="#0F1C3A"/>
<title>Verifikasi 2FA — LAMASY Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Inter+Tight:wght@500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --obsidian: #0A0F1F; --slate: #141B2D; --slate-elev: #1C2540;
  --crease: #252D45; --glow: #E2E8F0; --ash: #94A3B8; --ash-dim: #64748B;
  --teal: #35E8D5; --teal-deep: #0BC3B0; --teal-faint: rgba(53,232,213,.08);
  --teal-glow: rgba(53,232,213,.22); --ai-violet: #A78BFA;
  --coral: #F43F5E; --sage: #84CC16;
}
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;font-family:'Inter',system-ui,sans-serif;color:var(--glow);
  background:var(--obsidian);
  background-image:
    radial-gradient(circle at 20% 0%, rgba(53,232,213,.06) 0%, transparent 40%),
    radial-gradient(circle at 80% 100%, rgba(167,139,250,.06) 0%, transparent 40%),
    radial-gradient(rgba(53,232,213,.06) 0.5px, transparent 0.5px);
  background-size: 100% 100%, 100% 100%, 24px 24px;
  -webkit-font-smoothing:antialiased; letter-spacing:-.005em}
.wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.card{
  width:100%;max-width:440px;
  background:linear-gradient(180deg, rgba(28,37,64,.7) 0%, rgba(20,27,45,.5) 100%);
  backdrop-filter:blur(20px);
  border:1px solid var(--crease);
  border-radius:18px;padding:38px 36px;
  box-shadow:0 24px 60px rgba(0,0,0,.4)
}
.logo{display:flex;align-items:center;gap:10px;margin-bottom:28px}
.logo-mark{width:36px;height:36px;background:linear-gradient(135deg,var(--teal),var(--teal-deep));
  border-radius:9px;display:flex;align-items:center;justify-content:center;
  font-weight:800;color:var(--obsidian);box-shadow:0 4px 16px var(--teal-glow)}
.logo-text{font-weight:800;font-size:14px;letter-spacing:.02em}
.logo-text small{display:block;font-family:'JetBrains Mono',monospace;font-size:9px;
  letter-spacing:.14em;text-transform:uppercase;color:var(--teal);font-weight:600}
h1{font-family:'Inter Tight',sans-serif;font-size:24px;font-weight:700;
  letter-spacing:-.025em;margin-bottom:8px}
.sub{font-size:13px;color:var(--ash);line-height:1.5;margin-bottom:6px}
.email-hint{font-family:'JetBrains Mono',monospace;font-size:13px;color:var(--teal);
  background:var(--teal-faint);padding:6px 12px;border-radius:6px;display:inline-block;
  margin-bottom:24px;border:1px solid rgba(53,232,213,.2)}
.code-input{
  width:100%;padding:18px;text-align:center;
  font-family:'JetBrains Mono',monospace;
  font-size:32px;font-weight:700;letter-spacing:.6em;
  background:rgba(28,37,64,.5);
  border:1.5px solid var(--crease);border-radius:10px;color:var(--glow);
  outline:none;margin-bottom:16px;transition:border-color .15s,box-shadow .15s;
  text-indent:.3em
}
.code-input:focus{border-color:var(--teal);box-shadow:0 0 0 4px var(--teal-faint)}
.code-input::placeholder{color:var(--ash-dim);letter-spacing:.4em}
.btn{
  width:100%;padding:13px;border:none;border-radius:10px;
  font-family:inherit;font-size:14px;font-weight:700;
  cursor:pointer;transition:all .15s
}
.btn-primary{
  background:linear-gradient(135deg,var(--teal),var(--teal-deep));
  color:var(--obsidian);box-shadow:0 4px 16px var(--teal-glow)
}
.btn-primary:hover{box-shadow:0 6px 22px var(--teal-glow);transform:translateY(-1px)}
.btn-secondary{
  background:transparent;color:var(--ash);font-size:12.5px;
  padding:10px;margin-top:8px;font-weight:600
}
.btn-secondary:hover{color:var(--teal)}
.alert{
  padding:11px 14px;border-radius:8px;margin-bottom:16px;font-size:12.5px;font-weight:500
}
.alert.err{background:rgba(244,63,94,.10);border:1px solid rgba(244,63,94,.30);color:var(--coral)}
.alert.ok{background:rgba(132,204,22,.10);border:1px solid rgba(132,204,22,.30);color:var(--sage)}
.divider{height:1px;background:var(--crease);margin:20px 0}
.actions-row{display:flex;justify-content:space-between;align-items:center;font-size:12px;margin-top:6px}
.actions-row a, .actions-row button.linkbtn{color:var(--ash);text-decoration:none;background:none;border:none;cursor:pointer;font-family:inherit;font-size:12px;padding:0}
.actions-row a:hover, .actions-row button.linkbtn:hover{color:var(--teal)}
.timer{font-family:'JetBrains Mono',monospace;color:var(--ash-dim);font-size:11px}
.hint{font-size:11px;color:var(--ash-dim);text-align:center;margin-top:14px;line-height:1.5}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="logo">
      <div class="logo-mark">L</div>
      <div class="logo-text">LAMASY Admin<small>Super Admin Panel</small></div>
    </div>

    <h1>🔐 Verifikasi Login</h1>
    <p class="sub">Kode 6-digit dikirim ke:</p>
    <span class="email-hint"><?= htmlspecialchars($emailHint) ?></span>

    <?php if ($error): ?>
      <div class="alert err"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($msg): ?>
      <div class="alert ok"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(sa2faCsrf()) ?>"/>
      <input type="text" name="code" class="code-input" inputmode="numeric" pattern="\d{6}"
             maxlength="6" placeholder="• • • • • •" autofocus required
             oninput="this.value=this.value.replace(/\D/g,'')"/>
      <button type="submit" class="btn btn-primary">Verifikasi</button>
    </form>

    <div class="actions-row">
      <form method="POST" style="display:inline">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(sa2faCsrf()) ?>"/>
        <input type="hidden" name="action" value="resend"/>
        <button type="submit" class="linkbtn">📧 Kirim ulang kode</button>
      </form>
      <a href="?cancel=1">← Kembali ke login</a>
    </div>

    <div class="hint">
      Kode berlaku 10 menit. Cek folder spam kalau tidak masuk.
    </div>
  </div>
</div>
</body>
</html>

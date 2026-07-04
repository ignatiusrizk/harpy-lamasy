<?php
// ══════════════════════════════════════════════════════
// reset-password.php — Lupa / Reset Password (publik)
//   /reset-password            → form minta link (input email)
//   /reset-password?token=XXXX → form set password baru
// Token disimpan di email_verifications (type='password_reset'), sekali pakai,
// kedaluwarsa 1 jam. Respons request selalu generik (anti email enumeration).
// ══════════════════════════════════════════════════════
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/master/config/db.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Mailer.php';

$db = Database::get();
if (empty($_SESSION['rp_csrf'])) $_SESSION['rp_csrf'] = bin2hex(random_bytes(16));
function rp_csrf_ok(): bool { return hash_equals($_SESSION['rp_csrf'] ?? '', $_POST['_csrf'] ?? ''); }

$token   = trim($_GET['token'] ?? $_POST['token'] ?? '');
$mode    = $token !== '' ? 'reset' : 'request';
$err     = '';
$sent    = false;   // request: link terkirim (generik)
$tokenRow = null;

// ── Validasi token (mode reset) ────────────────────────
if ($mode === 'reset') {
    try {
        $s = $db->prepare("SELECT id, tenant_id, email FROM email_verifications
                           WHERE token=? AND type='password_reset' AND used_at IS NULL AND expires_at > NOW()
                           LIMIT 1");
        $s->execute([$token]);
        $tokenRow = $s->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { error_log('[reset-password] validate: '.$e->getMessage()); }
    if (!$tokenRow) $err = 'Link reset tidak valid atau sudah kedaluwarsa. Silakan minta link baru.';
}

// ── POST: minta link reset ─────────────────────────────
if ($mode === 'request' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'request') {
    if (!rp_csrf_ok()) { $err = 'Sesi tidak valid, muat ulang halaman.'; }
    else {
        // Rate-limit sederhana per sesi: max 3 permintaan / 15 menit
        $now = time();
        $_SESSION['rp_hits'] = array_values(array_filter($_SESSION['rp_hits'] ?? [], fn($t)=>$t > $now-900));
        if (count($_SESSION['rp_hits']) >= 3) {
            $err = 'Terlalu banyak permintaan. Coba lagi beberapa menit.';
        } else {
            $_SESSION['rp_hits'][] = $now;
            $email = trim(strtolower($_POST['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $err = 'Format email tidak valid.';
            } else {
                try {
                    $u = $db->prepare("SELECT id, tenant_id, email, nama FROM hl_users
                                       WHERE email=? AND is_active=1 LIMIT 1");
                    $u->execute([$email]);
                    $user = $u->fetch(PDO::FETCH_ASSOC);
                    if ($user) {
                        // Nonaktifkan token reset lama yang masih aktif
                        $db->prepare("UPDATE email_verifications SET used_at=NOW()
                                      WHERE tenant_id=? AND email=? AND type='password_reset' AND used_at IS NULL")
                           ->execute([$user['tenant_id'], $user['email']]);
                        $tok = bin2hex(random_bytes(32));
                        $exp = date('Y-m-d H:i:s', time() + 3600);
                        $db->prepare("INSERT INTO email_verifications (tenant_id, email, token, type, expires_at)
                                      VALUES (?,?,?, 'password_reset', ?)")
                           ->execute([$user['tenant_id'], $user['email'], $tok, $exp]);
                        Mailer::sendPasswordReset($user['email'], $user['nama'] ?: 'Owner', $tok);
                    }
                } catch (Throwable $e) { error_log('[reset-password] request: '.$e->getMessage()); }
                $sent = true; // selalu generik walau email tak ditemukan
            }
        }
    }
}

// ── POST: set password baru (mode reset) ───────────────
if ($mode === 'reset' && $tokenRow && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'reset') {
    if (!rp_csrf_ok()) { $err = 'Sesi tidak valid, muat ulang halaman.'; }
    else {
        $p1 = (string)($_POST['password'] ?? '');
        $p2 = (string)($_POST['password2'] ?? '');
        if (strlen($p1) < 8)        $err = 'Password minimal 8 karakter.';
        elseif ($p1 !== $p2)        $err = 'Konfirmasi password tidak cocok.';
        else {
            try {
                $hash = password_hash($p1, PASSWORD_DEFAULT);
                $db->prepare("UPDATE hl_users SET password=? WHERE tenant_id=? AND email=?")
                   ->execute([$hash, $tokenRow['tenant_id'], $tokenRow['email']]);
                $db->prepare("UPDATE email_verifications SET used_at=NOW() WHERE id=?")
                   ->execute([$tokenRow['id']]);
                header('Location: /login?msg=reset_ok');
                exit;
            } catch (Throwable $e) {
                error_log('[reset-password] set: '.$e->getMessage());
                $err = 'Terjadi kesalahan sistem. Silakan coba lagi.';
            }
        }
    }
}

$csrf = htmlspecialchars($_SESSION['rp_csrf']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Reset Password — LAMASY</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
:root{ --navy:#0A0F1F; --teal:#14b8a6; --teal2:#0ea5a4; }
*{box-sizing:border-box}
body{margin:0;min-height:100vh;font-family:'Plus Jakarta Sans',system-ui,sans-serif;
  background:radial-gradient(1000px 500px at 80% -10%,rgba(20,184,166,.18),transparent 60%),
             radial-gradient(800px 400px at -10% 110%,rgba(59,130,246,.14),transparent 55%),var(--navy);
  color:#e6edf6;display:flex;align-items:center;justify-content:center;padding:24px}
.card{width:100%;max-width:400px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);
  border-radius:18px;padding:28px 24px}
.logo{font-weight:800;font-size:22px;color:#5eead4;text-align:center;margin:0 0 4px;letter-spacing:.02em}
h1{font-size:19px;font-weight:800;margin:14px 0 6px;text-align:center}
.sub{font-size:13px;color:#9fb0c3;text-align:center;margin:0 0 20px;line-height:1.5}
label{display:block;font-size:12px;font-weight:600;color:#9fb0c3;margin:0 0 5px}
input[type=email],input[type=password]{width:100%;padding:12px 14px;margin-bottom:14px;border-radius:10px;
  border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.05);color:#fff;font-size:14px;font-family:inherit;outline:none}
input:focus{border-color:var(--teal)}
button{width:100%;padding:13px;border:none;border-radius:10px;font-family:inherit;font-weight:700;font-size:15px;
  color:#04211d;background:linear-gradient(90deg,var(--teal2),var(--teal));cursor:pointer}
.msg{padding:11px 14px;border-radius:10px;font-size:13px;line-height:1.5;margin-bottom:16px}
.msg.err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.35);color:#fca5a5}
.msg.ok{background:rgba(20,184,166,.12);border:1px solid rgba(20,184,166,.35);color:#5eead4}
.foot{text-align:center;margin-top:18px;font-size:13px}
.foot a{color:#5eead4;text-decoration:none}
</style>
</head>
<body>
<div class="card">
  <div class="logo">LAMASY</div>

  <?php if ($err): ?><div class="msg err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

  <?php if ($mode === 'request'): ?>
    <?php if ($sent): ?>
      <h1>Cek email kamu 📧</h1>
      <div class="msg ok">Kalau email terdaftar, kami sudah mengirim link reset password. Cek inbox (dan folder spam/promosi). Link berlaku 1 jam.</div>
      <div class="foot"><a href="/login">← Kembali ke login</a></div>
    <?php else: ?>
      <h1>Lupa password?</h1>
      <p class="sub">Masukkan email akunmu. Kami kirim link untuk membuat password baru.</p>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="request">
        <label>Email</label>
        <input type="email" name="email" placeholder="email@kamu.com" required autofocus>
        <button type="submit">Kirim link reset</button>
      </form>
      <div class="foot"><a href="/login">← Kembali ke login</a></div>
    <?php endif; ?>

  <?php else: /* mode reset */ ?>
    <?php if (!$tokenRow): ?>
      <h1>Link tidak berlaku</h1>
      <div class="foot"><a href="/reset-password">Minta link reset baru →</a></div>
    <?php else: ?>
      <h1>Buat password baru</h1>
      <p class="sub">Untuk <strong><?= htmlspecialchars($tokenRow['email']) ?></strong></p>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="reset">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <label>Password baru (min 8 karakter)</label>
        <input type="password" name="password" minlength="8" placeholder="••••••••" required autofocus>
        <label>Ulangi password baru</label>
        <input type="password" name="password2" minlength="8" placeholder="••••••••" required>
        <button type="submit">Simpan password baru</button>
      </form>
      <div class="foot"><a href="/login">← Kembali ke login</a></div>
    <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>

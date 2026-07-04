<?php
// demo.php — Landing demo, set session & redirect ke dashboard
define('ROOT', __DIR__);

require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure',   1);
ini_set('session.cookie_samesite', 'Strict');
if (session_status() === PHP_SESSION_NONE) session_start();

// Kalau sudah login sebagai user nyata — redirect ke dashboard
if (!empty($_SESSION['user_id']) && empty($_SESSION['is_demo'])) {
    header('Location: /dashboard');
    exit;
}

$db = Database::get();

// Ambil demo IDs dari config
$cfg = $db->query(
    "SELECT config_key, config_value FROM saas_platform_config
     WHERE config_key IN ('demo_tenant_id','demo_outlet_id','demo_user_id')"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$demoTid = (int)($cfg['demo_tenant_id'] ?? 0);
$demoOid = (int)($cfg['demo_outlet_id'] ?? 0);
$demoUid = (int)($cfg['demo_user_id']   ?? 0);

if (!$demoTid || !$demoOid || !$demoUid) {
    // Demo belum dikonfigurasi
    header('Location: /landing');
    exit;
}

// Cek maintenance mode — jangan masuk demo kalau maintenance aktif
$cacheFile = ROOT . '/storage/maintenance.json';
if (file_exists($cacheFile)) {
    $mCfg = json_decode(file_get_contents($cacheFile), true) ?: [];
    if (!empty($mCfg['active'])) {
        header('Location: /maintenance');
        exit;
    }
}

// Handle klik "Mulai Demo"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_demo'])) {
    // Ambil data demo tenant & outlet
    $t = $db->prepare("SELECT id, slug, nama_perusahaan FROM tenants WHERE id=? AND is_demo=1 LIMIT 1");
    $t->execute([$demoTid]);
    $tenant = $t->fetch(PDO::FETCH_ASSOC);

    $u = $db->prepare("SELECT id, username, nama, role FROM hl_users WHERE id=? AND tenant_id=? LIMIT 1");
    $u->execute([$demoUid, $demoTid]);
    $demoUser = $u->fetch(PDO::FETCH_ASSOC);

    if (!$tenant || !$demoUser) {
        $error = 'Demo tidak tersedia saat ini. Silakan coba beberapa saat lagi.';
    } else {
        // Set session demo — mirip login normal
        session_regenerate_id(true);
        $_SESSION['user_id']             = $demoUser['id'];
        $_SESSION['tenant_id']           = $demoTid;
        $_SESSION['tenant_slug']         = $tenant['slug'];
        $_SESSION['tenant_coin_balance'] = 999999;
        $_SESSION['outlet_id']           = $demoOid;
        $_SESSION['hq_mode']             = false;
        $_SESSION['hl_login_time']       = time();
        $_SESSION['hl_last_activity']    = time();
        $_SESSION['hl_user']             = [
            'id'          => $demoUser['id'],
            'username'    => $demoUser['username'],
            'nama'        => $demoUser['nama'],
            'role'        => $demoUser['role'],
            'role_id'     => null,
            'role_nama'   => 'Owner',
            'nama_outlet' => $tenant['nama_perusahaan'],
        ];
        $_SESSION['hl_permissions']      = ['*' => 'all'];
        $_SESSION['is_demo']             = true;
        $_SESSION['demo_actions']        = 0; // counter aksi untuk CTA trigger

        // Catat sesi demo
        try {
            $db->prepare(
                "INSERT INTO saas_demo_sessions (started_at, ip_address, user_agent)
                 VALUES (NOW(), ?, ?)"
            )->execute([
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300),
            ]);
            $_SESSION['demo_session_id'] = (int)$db->lastInsertId();
        } catch (Throwable) {}

        header('Location: /dashboard');
        exit;
    }
}

// Tampilkan landing page demo
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Coba Demo — LAMASY Laundry Management System</title>
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Segoe UI',Arial,sans-serif;background:linear-gradient(135deg,#0F1C3A,#1F3864);min-height:100vh;color:#fff}
    .hero{text-align:center;padding:60px 24px 40px}
    .logo{font-size:28px;font-weight:800;letter-spacing:-1px;margin-bottom:8px}
    .logo span{color:#35E8D5}
    .tagline{font-size:13px;color:rgba(255,255,255,.5);margin-bottom:40px}
    h1{font-size:clamp(26px,5vw,42px);font-weight:800;line-height:1.2;margin-bottom:16px}
    h1 span{color:#FAC775}
    .sub{font-size:16px;color:rgba(255,255,255,.7);max-width:520px;margin:0 auto 40px;line-height:1.6}
    .features{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;max-width:800px;margin:0 auto 40px;padding:0 16px}
    .feat{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:20px;text-align:left}
    .feat-ico{font-size:28px;margin-bottom:10px}
    .feat-title{font-size:14px;font-weight:700;margin-bottom:4px}
    .feat-desc{font-size:12px;color:rgba(255,255,255,.5);line-height:1.5}
    .cta-box{max-width:440px;margin:0 auto;padding:0 16px 60px}
    .notice{background:rgba(250,199,117,.1);border:1px solid rgba(250,199,117,.3);border-radius:10px;padding:14px 16px;font-size:13px;color:#FAC775;margin-bottom:24px;text-align:left;line-height:1.6}
    form button{
      width:100%;padding:16px;background:linear-gradient(135deg,#35E8D5,#2E5FA3);
      color:#fff;border:none;border-radius:12px;font-size:17px;font-weight:800;cursor:pointer;
      margin-bottom:14px;letter-spacing:0.3px;
    }
    form button:hover{opacity:.9}
    .or{font-size:13px;color:rgba(255,255,255,.4);text-align:center;margin-bottom:14px}
    .register-btn{display:block;text-align:center;padding:14px;border:1.5px solid rgba(255,255,255,.2);border-radius:12px;color:#fff;text-decoration:none;font-size:14px;font-weight:600}
    .register-btn:hover{background:rgba(255,255,255,.05)}
    .fine-print{text-align:center;font-size:12px;color:rgba(255,255,255,.3);margin-top:16px}
    .error{background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.3);color:#FCA5A5;padding:12px 14px;border-radius:8px;font-size:13px;margin-bottom:16px}
    @media(max-width:480px){.hero{padding:40px 16px 28px}}
  </style>
</head>
<body>
<div class="hero">
  <div class="logo">LA<span>MA</span>SY</div>
  <div class="tagline">Laundry Management System</div>
  <h1>Kelola Laundry Lebih<br><span>Cerdas & Efisien</span></h1>
  <p class="sub">Coba semua fitur LAMASY tanpa perlu daftar. Data demo di-reset otomatis setiap 24 jam.</p>

  <div class="features">
    <div class="feat">
      <div class="feat-ico">🧾</div>
      <div class="feat-title">Kasir & Order</div>
      <div class="feat-desc">Input order, cetak struk termal, tracking status laundry</div>
    </div>
    <div class="feat">
      <div class="feat-ico">📲</div>
      <div class="feat-title">WA Otomatis</div>
      <div class="feat-desc">Notifikasi pelanggan otomatis saat order masuk & selesai</div>
    </div>
    <div class="feat">
      <div class="feat-ico">📊</div>
      <div class="feat-title">Laporan Bisnis</div>
      <div class="feat-desc">Laporan harian, omzet, piutang B2B, analitik pelanggan</div>
    </div>
    <div class="feat">
      <div class="feat-ico">✨</div>
      <div class="feat-title">AI Briefing</div>
      <div class="feat-desc">Insight bisnis otomatis dari Claude AI setiap pagi</div>
    </div>
    <div class="feat">
      <div class="feat-ico">👥</div>
      <div class="feat-title">Manajemen Tim</div>
      <div class="feat-desc">Karyawan, absensi, penggajian, role & akses per outlet</div>
    </div>
    <div class="feat">
      <div class="feat-ico">🎁</div>
      <div class="feat-title">Loyalitas</div>
      <div class="feat-desc">Program poin, reward pelanggan, retensi otomatis</div>
    </div>
  </div>
</div>

<div class="cta-box">
  <div class="notice">
    🎮 <strong>Mode Demo:</strong> Data dummy sudah tersedia untuk semua fitur.
    Data akan di-reset setiap 24 jam. Tidak perlu daftar atau kartu kredit.
  </div>

  <?php if (!empty($error)): ?>
  <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST">
    <input type="hidden" name="start_demo" value="1">
    <button type="submit">🚀 Mulai Demo Sekarang</button>
  </form>

  <div class="or">— atau —</div>

  <a href="/register" class="register-btn">Daftar Gratis — Trial 14 Hari →</a>
  <div class="fine-print">Tidak perlu kartu kredit &nbsp;·&nbsp; Setup &lt;5 menit &nbsp;·&nbsp; Batalkan kapan saja</div>
</div>
</body>
</html>

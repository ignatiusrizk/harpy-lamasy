<?php
// maintenance.php — Halaman maintenance (publik, tanpa login)
// Dibaca dari storage/maintenance.json (cache) atau DB sebagai fallback

$cacheFile = __DIR__ . '/storage/maintenance.json';
$message   = 'Sistem sedang dalam pemeliharaan terjadwal. Kami akan kembali segera.';
$until     = null;

if (file_exists($cacheFile)) {
    $cfg = json_decode(file_get_contents($cacheFile), true) ?: [];
    // Jika cache bilang tidak aktif (superadmin akses langsung URL) — redirect home
    if (empty($cfg['active'])) {
        header('Location: /login');
        exit;
    }
    $message = $cfg['message'] ?? $message;
    $until   = $cfg['until']   ?? null;
} else {
    // Cache tidak ada → maintenance sudah selesai atau tidak aktif
    header('Location: /login');
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta http-equiv="refresh" content="60">
  <title>LAMASY — Maintenance</title>
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{
      font-family:'Segoe UI',Arial,sans-serif;
      background:linear-gradient(135deg,#0F1C3A 0%,#1F3864 100%);
      min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;
    }
    .card{
      background:#fff;border-radius:20px;padding:52px 44px;
      text-align:center;max-width:500px;width:100%;
      box-shadow:0 20px 60px rgba(0,0,0,.25);
    }
    .icon{font-size:64px;margin-bottom:24px;display:block;animation:spin 4s linear infinite}
    @keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}
    h1{font-size:24px;font-weight:800;color:#1F3864;margin-bottom:12px}
    p{color:#5a6a8a;line-height:1.7;font-size:15px;margin-bottom:8px}
    .until{
      background:#EBF3FF;color:#0C447C;padding:12px 18px;
      border-radius:10px;font-size:14px;margin-top:20px;font-weight:500;
    }
    .refresh{font-size:12px;color:#aab;margin-top:20px}
    .logo{
      display:inline-block;margin-top:36px;
      font-size:22px;font-weight:800;color:#1F3864;letter-spacing:-0.5px;
    }
    .logo span{color:#2E5FA3}
    .wa-link{display:inline-block;margin-top:16px;font-size:13px;color:#2E5FA3;text-decoration:none}
    .wa-link:hover{text-decoration:underline}
    @media(max-width:480px){.card{padding:36px 24px}h1{font-size:20px}}
  </style>
</head>
<body>
  <div class="card">
    <span class="icon">🔧</span>
    <h1>Sistem Sedang Maintenance</h1>
    <p><?= htmlspecialchars($message) ?></p>
    <?php if ($until): ?>
    <div class="until">
      ⏰ Estimasi selesai: <?= htmlspecialchars(date('d M Y, H:i', strtotime($until))) ?> WIB
    </div>
    <?php endif; ?>
    <p class="refresh">Halaman ini akan refresh otomatis setiap 60 detik.</p>
    <a href="https://wa.me/6285121519302?text=<?= urlencode('Halo LAMASY, kapan maintenance selesai?') ?>"
       class="wa-link">💬 Hubungi Tim LAMASY via WhatsApp</a>
    <div><div class="logo">La<span>Ma</span>Sy</div></div>
  </div>
</body>
</html>

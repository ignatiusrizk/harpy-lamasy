<?php
// ══════════════════════════════════════════════════════
// logout.php — Session destroy & redirect to login
// ══════════════════════════════════════════════════════

session_start();

// Log audit sebelum destroy session (jika user masih ada)
if (!empty($_SESSION['user_id']) && !empty($_SESSION['tenant_id'])) {
    define('ROOT', __DIR__);
    try {
        require_once ROOT . '/master/config/db.php';
        require_once ROOT . '/core/Database.php';
        $db = Database::get();
        $stmt = $db->prepare(
            "INSERT INTO hl_audit_log (tenant_id, user_id, modul, aksi, keterangan, ip_address, created_at)
             VALUES (?, ?, 'auth', 'logout', 'Logout', ?, NOW())"
        );
        $stmt->execute([
            $_SESSION['tenant_id'],
            $_SESSION['user_id'],
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        // Abaikan error log — tetap lanjut logout
    }
}

// Hapus semua session data
$_SESSION = [];

// Hapus cookie session
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

session_destroy();

// Bersihkan cache shell POS (anti bocor antar user di device bersama) lalu redirect.
// Cache API hanya bisa diakses dari browser → render halaman kecil yang purge → redirect.
header('Content-Type: text/html; charset=utf-8');
?><!doctype html><html lang="id"><head><meta charset="utf-8">
<title>Logout…</title><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="background:#0F1C3A">
<script>
(function(){
  function go(){ location.replace('/login'); }
  try {
    if (typeof caches !== 'undefined' && caches.keys) {
      caches.keys().then(function(keys){
        return Promise.all(keys.filter(function(k){ return k.indexOf('lamasy-tenant') === 0; })
          .map(function(k){ return caches.open(k).then(function(c){ return Promise.all([c.delete('/pos'), c.delete('/pos.php')]); }); }));
      }).then(go, go);
    } else { go(); }
  } catch (e) { go(); }
})();
</script>
</body></html><?php
exit;

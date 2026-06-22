<?php
// pelanggan.php — Portal home read-only untuk pelanggan

define('ROOT', __DIR__);
require_once ROOT . '/middleware/pelanggan_guard.php';

requirePelangganLogin();
$pel = currentPelanggan();
$db = Database::get();

// CSRF helper (lokal — pelanggan_guard tidak provide karena PUBLIC)
function pelangganCsrf(): string {
    if (empty($_SESSION['portal_csrf'])) {
        $_SESSION['portal_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['portal_csrf'];
}
function verifyPortalCsrf(): void {
    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['portal_csrf'] ?? '', $token)) {
        http_response_code(403);
        die('CSRF mismatch');
    }
}

$action = $_GET['action'] ?? '';

if ($action === 'logout') {
    unset($_SESSION['portal_pelanggan_id'], $_SESSION['portal_csrf']);
    header('Location: /p?msg=logout');
    exit;
}

if ($action === 'regen_token' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    verifyPortalCsrf();
    $newToken = bin2hex(random_bytes(16));
    $st = $db->prepare("UPDATE hl_pelanggan SET portal_token=? WHERE id=?");
    $st->execute([$newToken, (int)$pel['id']]);
    // Logout setelah regen
    unset($_SESSION['portal_pelanggan_id'], $_SESSION['portal_csrf']);
    echo json_encode(['ok'=>true]);
    exit;
}

// Load data
$saldoDeposit = (float)($pel['saldo_deposit'] ?? 0);

// Loyalty (kalau tabel ada)
$poin = 0; $tier = '';
try {
    $st = $db->prepare("SELECT poin_balance FROM hl_pelanggan WHERE id=?");
    $st->execute([(int)$pel['id']]);
    $poin = (int)($st->fetchColumn() ?: 0);
} catch (Throwable) {}

// Cari outlet last order pelanggan untuk scope rewards
$lastOutletId = 0;
try {
    $st = $db->prepare("SELECT outlet_id FROM hl_transaksi WHERE pelanggan_id=? ORDER BY id DESC LIMIT 1");
    $st->execute([(int)$pel['id']]);
    $lastOutletId = (int)($st->fetchColumn() ?: 0);
} catch (Throwable) {}

// Load rewards yang apply di outlet tsb + tenant scope
$rewards = [];
if ($lastOutletId > 0) {
    require_once ROOT . '/core/Loyalty.php';
    // Tenant_id dari outlet
    try {
        $st = $db->prepare("SELECT tenant_id FROM outlets WHERE id=? LIMIT 1");
        $st->execute([$lastOutletId]);
        $tid = (int)($st->fetchColumn() ?: 0);
        if ($tid > 0) {
            $rewards = Loyalty::availableRewards($tid, $lastOutletId, $poin);
        }
    } catch (Throwable) {}
}

// Order aktif (status_proses bukan diambil/selesai)
$activeOrders = [];
try {
    $st = $db->prepare(
        "SELECT no_order, total, status_proses, tanggal, estimasi_selesai,
                (SELECT COUNT(*) FROM hl_transaksi_item WHERE transaksi_id=t.id) AS jml_item
           FROM hl_transaksi t
          WHERE pelanggan_id=? AND status_proses NOT IN ('diambil','selesai','batal')
          ORDER BY t.id DESC LIMIT 20"
    );
    $st->execute([(int)$pel['id']]);
    $activeOrders = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('[pelanggan active] ' . $e->getMessage()); }

// Riwayat (20 terakhir done)
$historyOrders = [];
try {
    $st = $db->prepare(
        "SELECT no_order, total, status_proses, tanggal
           FROM hl_transaksi
          WHERE pelanggan_id=? AND status_proses IN ('diambil','selesai')
          ORDER BY id DESC LIMIT 20"
    );
    $st->execute([(int)$pel['id']]);
    $historyOrders = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {}

$csrf = pelangganCsrf();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0F1C3A">
<meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
<link rel="manifest" href="/assets/manifest.json">
<link rel="apple-touch-icon" href="/assets/logo.png">
<title>Portal — <?= htmlspecialchars($pel['nama']) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#F8FAFC;min-height:100vh;padding:16px;color:#1E293B}
.wrap{max-width:520px;margin:0 auto}
.head{background:linear-gradient(135deg,#0F1C3A,#1E3A8A);color:#fff;border-radius:14px;padding:18px 20px;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center}
.head .name{font-weight:700;font-size:17px}
.head .sub{font-size:12px;opacity:.7;margin-top:2px}
.btn-logout{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.2);padding:6px 12px;border-radius:8px;font-size:12px;text-decoration:none}
.card{background:#fff;border-radius:14px;padding:16px 18px;margin-bottom:12px;box-shadow:0 2px 10px rgba(15,28,58,.06)}
.card h2{font-size:13px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px}
.kv{display:flex;justify-content:space-between;align-items:center;padding:6px 0}
.kv .lbl{color:#64748B;font-size:13.5px}
.kv .val{font-weight:700;font-size:16px}
.order-card{border:1px solid #E2E8F0;border-radius:10px;padding:12px;margin-bottom:8px;cursor:pointer;transition:border .15s}
.order-card:active{border-color:#35E8D5}
.order-card .row1{font-weight:700;font-size:14px}
.order-card .row2{color:#64748B;font-size:12.5px;margin-top:3px}
.pill{display:inline-block;padding:2px 8px;border-radius:100px;font-size:11px;font-weight:600}
.p-cuci{background:#CCFBF1;color:#0F766E}
.p-kering{background:#DBEAFE;color:#1E40AF}
.p-setrika{background:#EDE9FE;color:#5B21B6}
.p-siap{background:#D1FAE5;color:#065F46}
.p-masuk{background:#FEF3C7;color:#92400E}
.regen{margin-top:18px;text-align:center;font-size:12px;color:#64748B}
.regen a{color:#EF4444;text-decoration:underline;cursor:pointer}
.empty{padding:20px;text-align:center;color:#94A3B8;font-size:13.5px}
@media (max-width:480px){body{padding:12px}}
</style>
</head>
<body>
<div class="wrap">
  <div class="head">
    <div>
      <div class="name">👋 <?= htmlspecialchars($pel['nama']) ?></div>
      <div class="sub">Portal Pelanggan LAMASY</div>
    </div>
    <a href="?action=logout" class="btn-logout">Keluar</a>
  </div>

  <div class="card">
    <h2>💰 Saldo & Poin</h2>
    <div class="kv"><span class="lbl">Deposit</span><span class="val">Rp <?= number_format($saldoDeposit, 0, ',', '.') ?></span></div>
    <div class="kv"><span class="lbl">Poin Loyalty</span><span class="val"><?= number_format($poin, 0, ',', '.') ?></span></div>
  </div>

  <?php if (!empty($rewards)): ?>
  <div class="card">
    <h2>🎁 Hadiah Tersedia</h2>
    <?php foreach ($rewards as $r): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #F1F5F9">
        <div style="flex:1">
          <div style="font-weight:600;font-size:14px;<?= $r['bisa_redeem'] ? '' : 'color:#94A3B8' ?>">
            <?= $r['bisa_redeem'] ? '✅' : '⏳' ?> <?= htmlspecialchars($r['nama_reward']) ?>
          </div>
          <div style="font-size:11px;color:#64748B;margin-top:2px"><?= (int)$r['poin_dibutuhkan'] ?> poin<?= $r['bisa_redeem'] ? '' : ' (butuh ' . (int)$r['kurang'] . ' lagi)' ?></div>
        </div>
      </div>
    <?php endforeach; ?>
    <div style="margin-top:10px;font-size:12px;color:#64748B;font-style:italic">💡 Kunjungi outlet untuk menukarkan hadiah</div>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>🧺 Order Aktif (<?= count($activeOrders) ?>)</h2>
    <?php if (empty($activeOrders)): ?>
      <div class="empty">Tidak ada order aktif</div>
    <?php else: foreach ($activeOrders as $o): ?>
      <a href="/pelanggan-order?o=<?= urlencode($o['no_order']) ?>" style="text-decoration:none;color:inherit">
      <div class="order-card">
        <div class="row1">#<?= htmlspecialchars($o['no_order']) ?> · <?= (int)$o['jml_item'] ?> item · Rp <?= number_format((float)$o['total'], 0, ',', '.') ?></div>
        <div class="row2">
          <span class="pill p-<?= htmlspecialchars($o['status_proses']) ?>"><?= htmlspecialchars($o['status_proses']) ?></span>
          <?php if (!empty($o['estimasi_selesai'])): ?> · Est. <?= date('d M', strtotime($o['estimasi_selesai'])) ?><?php endif; ?>
        </div>
      </div>
      </a>
    <?php endforeach; endif; ?>
  </div>

  <div class="card">
    <h2>📋 Riwayat (<?= count($historyOrders) ?> terakhir)</h2>
    <?php if (empty($historyOrders)): ?>
      <div class="empty">Belum ada riwayat</div>
    <?php else: foreach ($historyOrders as $o): ?>
      <a href="/pelanggan-order?o=<?= urlencode($o['no_order']) ?>" style="text-decoration:none;color:inherit">
      <div class="order-card">
        <div class="row1">#<?= htmlspecialchars($o['no_order']) ?> · Rp <?= number_format((float)$o['total'], 0, ',', '.') ?></div>
        <div class="row2"><?= date('d M Y', strtotime($o['tanggal'])) ?> · <?= htmlspecialchars($o['status_proses']) ?></div>
      </div>
      </a>
    <?php endforeach; endif; ?>
  </div>

  <div class="regen">
    Curiga akun bocor? <a onclick="regenToken()">Regenerate token</a>
  </div>
</div>

<script>
const CSRF = '<?= $csrf ?>';
async function regenToken() {
  if (!confirm('Regenerate token akan invalidate semua struk lama. Lanjut?')) return;
  const r = await fetch('?action=regen_token', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}});
  const d = await r.json();
  if (d.ok) {
    alert('✅ Token baru. Untuk login kembali, scan QR struk terbaru dari outlet.');
    window.location = '/p?msg=logout';
  }
}
// Register service worker (PWA)
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/sw.js').catch(e => console.warn('SW fail', e));
}
</script>
</body>
</html>

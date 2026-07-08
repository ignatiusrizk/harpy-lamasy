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

// Phase 2: generate kupon kode dari reward
if ($action === 'generate_coupon' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    verifyPortalCsrf();
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $rewardId = (int)($d['reward_id'] ?? 0);
    if ($rewardId <= 0) { echo json_encode(['error'=>'Reward tidak valid']); exit; }
    require_once ROOT . '/core/Loyalty.php';
    // Resolve tenant dari pelanggan
    $tidStmt = $db->prepare("SELECT tenant_id FROM hl_pelanggan WHERE id=? LIMIT 1");
    $tidStmt->execute([(int)$pel['id']]);
    $pelTid = (int)$tidStmt->fetchColumn();
    if ($pelTid <= 0) { echo json_encode(['error'=>'Tenant tidak ditemukan']); exit; }
    // Cek outlet last order untuk default outlet_id (optional)
    $oidStmt = $db->prepare("SELECT outlet_id FROM hl_transaksi WHERE pelanggan_id=? ORDER BY id DESC LIMIT 1");
    $oidStmt->execute([(int)$pel['id']]);
    $defaultOid = (int)($oidStmt->fetchColumn() ?: 0) ?: null;
    try {
        $result = Loyalty::createCoupon($pelTid, (int)$pel['id'], $rewardId, $defaultOid, null);
        echo json_encode(['ok'=>true, 'kupon'=>$result]);
    } catch (Throwable $e) {
        apiErr($e);
    }
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

// Loyalty (kalau tabel ada) — defensive tenant_id filter (defense-in-depth)
$poin = 0; $tier = '';
$pelTid = (int)($pel['tenant_id'] ?? 0);
try {
    $st = $db->prepare("SELECT poin_balance FROM hl_pelanggan WHERE id=? AND tenant_id=?");
    $st->execute([(int)$pel['id'], $pelTid]);
    $poin = (int)($st->fetchColumn() ?: 0);
} catch (Throwable) {}

// Cari outlet last order pelanggan untuk scope rewards
$lastOutletId = 0;
try {
    $st = $db->prepare("SELECT outlet_id FROM hl_transaksi WHERE pelanggan_id=? AND tenant_id=? ORDER BY id DESC LIMIT 1");
    $st->execute([(int)$pel['id'], $pelTid]);
    $lastOutletId = (int)($st->fetchColumn() ?: 0);
} catch (Throwable) {}

// Load rewards yang apply di outlet tsb + tenant scope
$rewards = [];
$loyaltyOn = false; // program poin aktif? (gate empty-state — jangan janji poin kalau earning OFF)
if ($lastOutletId > 0) {
    require_once ROOT . '/core/Loyalty.php';
    // Tenant_id dari outlet
    try {
        $st = $db->prepare("SELECT tenant_id FROM outlets WHERE id=? LIMIT 1");
        $st->execute([$lastOutletId]);
        $tid = (int)($st->fetchColumn() ?: 0);
        if ($tid > 0) {
            $loyaltyOn = Loyalty::isEnabled($tid);
            $rewards   = Loyalty::availableRewards($tid, $lastOutletId, $poin);
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

// Phase 2: Kupon aktif pelanggan
$myCoupons = [];
try {
    require_once ROOT . '/core/Loyalty.php';
    $tidStmt = $db->prepare("SELECT tenant_id FROM hl_pelanggan WHERE id=? LIMIT 1");
    $tidStmt->execute([(int)$pel['id']]);
    $pelTid = (int)$tidStmt->fetchColumn();
    if ($pelTid > 0) {
        $myCoupons = Loyalty::myCoupons($pelTid, (int)$pel['id']);
    }
} catch (Throwable) {}

// Referral (ajak teman) — load config + code + stats kalau enabled
$referralEnabled = false;
$referralCode    = '';
$referralStats   = ['sukses'=>0,'poin'=>0];
$referralShareUrl = '';
try {
    require_once ROOT . '/core/Referral.php';
    $refCfg = Referral::config($pelTid);
    if (!empty($refCfg['enabled'])) {
        $referralEnabled  = true;
        $referralCode     = Referral::codeFor($pelTid, (int)$pel['id']);
        $referralStats    = Referral::statsFor($pelTid, (int)$pel['id']);
        $appBase          = defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://lamasy.harpy.id';
        $referralShareUrl = $appBase . '/self?ref=' . urlencode($referralCode);
    }
} catch (Throwable) {}

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
<link rel="icon" type="image/png" href="/assets/icon-192.png?v=<?= @filemtime(__DIR__.'/assets/icon-192.png') ?: '3' ?>">
<link rel="apple-touch-icon" href="/assets/apple-touch-icon-180.png?v=<?= @filemtime(__DIR__.'/assets/apple-touch-icon-180.png') ?: '3' ?>">
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
      <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #F1F5F9;gap:8px">
        <div style="flex:1;min-width:0">
          <div style="font-weight:600;font-size:14px;<?= $r['bisa_redeem'] ? '' : 'color:#94A3B8' ?>">
            <?= $r['bisa_redeem'] ? '✅' : '⏳' ?> <?= htmlspecialchars($r['nama_reward']) ?>
          </div>
          <div style="font-size:11px;color:#64748B;margin-top:2px"><?= number_format((int)$r['poin_dibutuhkan'], 0, ',', '.') ?> poin<?= $r['bisa_redeem'] ? '' : ' (butuh ' . number_format((int)$r['kurang'], 0, ',', '.') . ' lagi)' ?></div>
        </div>
        <?php if ($r['bisa_redeem']): ?>
          <button onclick="generateKupon(<?= (int)$r['id'] ?>, this)"
                  style="background:#14B8A6;color:#fff;border:none;padding:6px 12px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap">
            🎟️ Tukar Kupon
          </button>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <div style="margin-top:10px;font-size:12px;color:#64748B;font-style:italic">💡 Tukar jadi kupon kode untuk dipakai sendiri atau di-share via WA</div>
  </div>
  <?php elseif ($loyaltyOn): ?>
  <!-- Program poin AKTIF tapi katalog hadiah belum diisi owner — kasih tahu poin tetap terkumpul.
       Kalau loyalty OFF, section disembunyikan total (jangan janjikan poin yg tak pernah bertambah). -->
  <div class="card">
    <h2>🎁 Hadiah Tersedia</h2>
    <div style="text-align:center;padding:14px 6px;color:#64748B;font-size:13.5px;line-height:1.6">
      <div style="font-size:26px;margin-bottom:6px">🎁</div>
      Poin kamu terus terkumpul di setiap transaksi.<br>
      Daftar hadiah sedang disiapkan outlet — pantau terus di sini!
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($myCoupons)): ?>
  <div class="card">
    <h2>🎟️ Kupon Saya (<?= count($myCoupons) ?>)</h2>
    <?php foreach ($myCoupons as $c):
      $isUsed = (int)$c['is_used'] === 1;
      $isExpired = !$isUsed && $c['expired_at'] && $c['expired_at'] < date('Y-m-d');
      $statusClass = $isUsed ? '#94A3B8' : ($isExpired ? '#DC2626' : '#0F766E');
    ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 12px;background:<?= $isUsed||$isExpired ? '#F1F5F9' : '#F0FDFA' ?>;border:1px solid <?= $isUsed||$isExpired ? '#E2E8F0' : '#99F6E4' ?>;border-radius:8px;margin-bottom:8px;gap:8px">
        <div style="flex:1;min-width:0">
          <div style="font-family:var(--mono,monospace);font-weight:700;font-size:16px;letter-spacing:1px;color:<?= $statusClass ?>">
            <?= htmlspecialchars($c['kode']) ?>
          </div>
          <div style="font-size:12px;color:#64748B;margin-top:2px">
            <?= htmlspecialchars($c['nama_reward'] ?: 'Reward') ?> · expired <?= htmlspecialchars($c['expired_at'] ?: '-') ?>
            <?php if ($isUsed): ?>
              <span style="color:#94A3B8"> · ✓ Sudah dipakai (<?= htmlspecialchars($c['used_by_order'] ?: '-') ?>)</span>
            <?php elseif ($isExpired): ?>
              <span style="color:#DC2626"> · ⌛ Kadaluwarsa</span>
            <?php endif; ?>
          </div>
        </div>
        <?php if (!$isUsed && !$isExpired): ?>
          <button onclick="shareKupon('<?= htmlspecialchars($c['kode']) ?>', '<?= htmlspecialchars(addslashes($c['nama_reward'] ?: 'reward')) ?>', '<?= htmlspecialchars($c['expired_at']) ?>')"
                  style="background:#22C55E;color:#fff;border:none;padding:6px 10px;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;white-space:nowrap">
            💬 Share
          </button>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($referralEnabled && $referralCode !== ''): ?>
  <div class="card">
    <h2>🤝 Ajak Teman</h2>
    <div class="kv">
      <span class="lbl">Kode kamu</span>
      <span class="val" style="font-family:monospace;letter-spacing:.1em"><?= htmlspecialchars($referralCode) ?></span>
    </div>
    <div style="margin:10px 0 6px;display:flex;gap:8px;align-items:center">
      <input id="refShareUrl" type="text" readonly
             value="<?= htmlspecialchars($referralShareUrl) ?>"
             style="flex:1;font-size:11.5px;color:#475569;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:7px;padding:6px 10px;font-family:monospace;min-width:0">
      <button onclick="salinReferral()" id="btnSalin"
              style="background:#14B8A6;color:#fff;border:none;padding:7px 14px;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap">
        Salin
      </button>
    </div>
    <div style="font-size:12.5px;color:#64748B;margin-top:6px">
      <?= (int)$referralStats['sukses'] ?> teman sukses &middot; <?= (int)$referralStats['poin'] ?> poin didapat
    </div>
    <div style="margin-top:8px;font-size:11.5px;color:#94A3B8">Bagikan link ini ke teman — poin masuk setelah order pertama mereka lunas.</div>
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
  if (!await lmConfirm('Regenerate token akan invalidate semua struk lama. Lanjut?')) return;
  const r = await fetch('?action=regen_token', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}});
  const d = await r.json();
  if (d.ok) {
    alert('✅ Token baru. Untuk login kembali, scan QR struk terbaru dari outlet.');
    window.location = '/p?msg=logout';
  }
}

async function generateKupon(rewardId, btn) {
  if (!await lmConfirm('Tukar poin sekarang? Kupon akan keluar dengan kode unik (berlaku 90 hari).')) return;
  btn.disabled = true; btn.textContent = '⏳ Generating...';
  try {
    const r = await fetch('?action=generate_coupon', {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-CSRF-Token':CSRF},
      body: JSON.stringify({reward_id: rewardId})
    });
    const d = await r.json();
    if (d.error) { alert('Gagal: ' + d.error); btn.disabled=false; btn.textContent='🎟️ Tukar Kupon'; return; }
    alert('✅ Kupon berhasil dibuat!\n\nKode: ' + d.kupon.kode + '\nBerlaku sampai: ' + d.kupon.expired_at + '\n\nKupon Saya muncul di bawah — bisa di-share lewat WA.');
    location.reload();
  } catch (e) {
    alert('Network error: ' + e.message);
    btn.disabled=false; btn.textContent='🎟️ Tukar Kupon';
  }
}

async function salinReferral() {
  const url = document.getElementById('refShareUrl').value;
  const btn = document.getElementById('btnSalin');
  try {
    await navigator.clipboard.writeText(url);
    btn.textContent = '✅ Disalin!';
    setTimeout(() => { btn.textContent = 'Salin'; }, 2000);
  } catch(e) {
    // Fallback: select + prompt
    document.getElementById('refShareUrl').select();
    alert('Salin URL ini:\n' + url);
  }
}

function shareKupon(kode, namaReward, expiredAt) {
  const msg = `Halo! Ini kupon laundry LAMASY untuk kamu:\n\n*${kode}*\nReward: ${namaReward}\nBerlaku sampai: ${expiredAt}\n\nKasih kode ini ke kasir saat order. Otomatis dapat reward.`;
  const url = 'https://wa.me/?text=' + encodeURIComponent(msg);
  window.open(url, '_blank');
}
// Register service worker (PWA)
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/sw.js').catch(e => console.warn('SW fail', e));
}
</script>
<?php /* dialog lmConfirm/lmAlert — portal standalone (tak lewat renderToast tenant), tanpa ini
         tombol Tukar Kupon & regen token mati (ReferenceError lmConfirm) */
require __DIR__ . '/ui_dialog.php'; ?>
</body>
</html>

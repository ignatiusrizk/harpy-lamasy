<?php
require_once __DIR__ . '/middleware/tenant_guard.php';
require_once __DIR__ . '/core/MidtransClient.php';
require_once __DIR__ . '/core/BillingConfig.php';

date_default_timezone_set('Asia/Jakarta');

$tenantId = (int)($_SESSION['tenant_id'] ?? 0);
if (!$tenantId) { header('Location: /login'); exit; }

$type = $_GET['type'] ?? '';
$validTypes = ['topup_coin', 'setup_fee', 'outlet_activation'];
if (!in_array($type, $validTypes, true)) {
    die('Invalid payment type');
}

$db = Database::get();

// Compute amount + ref based on type
$amount = 0;
$refBundleId = null;
$refPackageId = null;
$refOutletId = null;
$itemName = '';

if ($type === 'topup_coin') {
    $bundleId = (int)($_GET['bundle_id'] ?? 0);
    $b = $db->prepare("SELECT id, nama, harga, coin_didapat FROM saas_coin_bundles WHERE id=? AND is_active=1");
    $b->execute([$bundleId]);
    $bundle = $b->fetch(PDO::FETCH_ASSOC);
    if (!$bundle) die('Bundle tidak valid');
    $amount = (int)$bundle['harga'];
    $refBundleId = $bundle['id'];
    $itemName = "Top-up Coin — {$bundle['nama']} ({$bundle['coin_didapat']} coin)";
}
elseif ($type === 'setup_fee') {
    $t = $db->prepare("SELECT package_id FROM tenants WHERE id=?");
    $t->execute([$tenantId]);
    $packageId = (int)$t->fetchColumn();
    if (!$packageId) die('Package belum di-assign ke tenant ini');
    $p = $db->prepare("SELECT id, nama, setup_fee FROM saas_packages WHERE id=?");
    $p->execute([$packageId]);
    $package = $p->fetch(PDO::FETCH_ASSOC);
    if (!$package) die('Package tidak ditemukan');
    $amount = (int)$package['setup_fee'];
    $refPackageId = $package['id'];
    $itemName = "Setup Fee — Paket {$package['nama']}";
}
elseif ($type === 'outlet_activation') {
    $outletId = (int)($_GET['outlet_id'] ?? 0);
    $o = $db->prepare("SELECT id, nama_outlet, status, tenant_id FROM outlets WHERE id=? AND tenant_id=?");
    $o->execute([$outletId, $tenantId]);
    $outlet = $o->fetch(PDO::FETCH_ASSOC);
    if (!$outlet) die('Outlet tidak valid');
    $amount = BillingConfig::getInt('outlet_activation_fee', 800000);
    $refOutletId = $outlet['id'];
    $itemName = "Aktivasi Outlet — {$outlet['nama_outlet']}";
}

// Check existing pending payment (resume kalau ada)
// Strict AND-clause: semua ref harus cocok — hindari false match antar bundle/outlet berbeda
$existing = $db->prepare(
    "SELECT * FROM saas_payments
     WHERE tenant_id=? AND type=? AND status='pending' AND expires_at > NOW()
       AND COALESCE(ref_bundle_id, 0) = COALESCE(?, 0)
       AND COALESCE(ref_outlet_id, 0) = COALESCE(?, 0)
       AND COALESCE(ref_package_id, 0) = COALESCE(?, 0)
     ORDER BY id DESC LIMIT 1"
);
$existing->execute([
    $tenantId, $type,
    $refBundleId,
    $refOutletId,
    $refPackageId,
]);
$payment = $existing->fetch(PDO::FETCH_ASSOC);

// Kalau gak ada pending, create baru
if (!$payment) {
    $orderId = MidtransClient::generateOrderId($type, $tenantId);

    // Get tenant info untuk customer_details
    $tn = $db->prepare("SELECT nama_perusahaan, owner_name, email, owner_wa FROM tenants WHERE id=?");
    $tn->execute([$tenantId]);
    $tenant = $tn->fetch(PDO::FETCH_ASSOC);

    $customer = [
        'first_name' => $tenant['owner_name'] ?: $tenant['nama_perusahaan'],
        'email'      => $tenant['email'] ?: 'noreply@harpy.id',
        'phone'      => $tenant['owner_wa'] ?: '',
    ];

    // Call Midtrans Charge — QRIS dulu (default), VA via tab di UI bisa add later
    $method = $_GET['method'] ?? 'qris';
    if (!in_array($method, ['qris', 'bank_transfer'], true)) $method = 'qris';

    $res = MidtransClient::charge($orderId, $amount, $method, $customer);
    if (!$res['ok']) {
        die('Gagal generate payment: ' . htmlspecialchars($res['error'] ?? 'Unknown error'));
    }

    $mtData = $res['data'];
    $expiryMin = BillingConfig::getInt('payment_expiry_minutes', 15);
    $expiresAt = date('Y-m-d H:i:s', time() + $expiryMin * 60);

    // Extract QR / VA dari response
    $qrString = null;
    $vaBank = null;
    $vaNumber = null;
    if ($method === 'qris') {
        foreach ($mtData['actions'] ?? [] as $a) {
            if (($a['name'] ?? '') === 'generate-qr-code') {
                $qrString = $a['url'] ?? null; break;
            }
        }
    } elseif ($method === 'bank_transfer') {
        $vaBank = $mtData['va_numbers'][0]['bank'] ?? null;
        $vaNumber = $mtData['va_numbers'][0]['va_number'] ?? null;
    }

    $db->prepare(
        "INSERT INTO saas_payments
            (order_id, tenant_id, type, amount, ref_bundle_id, ref_package_id, ref_outlet_id,
             midtrans_tx_id, payment_type, va_bank, va_number, qr_string, expires_at, raw_response)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    )->execute([
        $orderId, $tenantId, $type, $amount,
        $refBundleId, $refPackageId, $refOutletId,
        $mtData['transaction_id'] ?? null,
        $method,
        $vaBank, $vaNumber, $qrString,
        $expiresAt,
        json_encode($mtData),
    ]);
    $paymentId = (int)$db->lastInsertId();
    $p = $db->prepare("SELECT * FROM saas_payments WHERE id=?");
    $p->execute([$paymentId]);
    $payment = $p->fetch(PDO::FETCH_ASSOC);
}

$secondsRemaining = max(0, strtotime($payment['expires_at']) - time());
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pembayaran — LAMASY</title>
<link rel="stylesheet" href="/harpy-erp.css?v=<?= date('Ymd') ?>">
<style>
  body { font-family: 'Plus Jakarta Sans', sans-serif; background: #0F1C3A; color: #fff; padding: 20px; }
  .wrap { max-width: 480px; margin: 0 auto; }
  .card { background: #162348; border: 1px solid rgba(255,255,255,.1); border-radius: 16px; padding: 28px; margin-bottom: 16px; }
  h1 { font-size: 22px; margin-bottom: 6px; }
  .item { color: #94A3B8; font-size: 13px; margin-bottom: 24px; }
  .amount { font-size: 32px; font-weight: 800; font-family: 'JetBrains Mono', monospace; color: #35E8D5; margin: 18px 0; }
  .timer { background: rgba(245,158,11,.1); border: 1px solid rgba(245,158,11,.3); color: #FCD34D; padding: 10px 14px; border-radius: 8px; font-size: 13px; text-align: center; }
  .qr-wrap { text-align: center; padding: 20px; background: #fff; border-radius: 12px; }
  .qr-wrap img { max-width: 260px; }
  .va-box { display: flex; align-items: center; justify-content: space-between; background: rgba(53,232,213,.06); border: 1px solid rgba(53,232,213,.25); padding: 14px; border-radius: 10px; margin: 8px 0; }
  .va-num { font-family: 'JetBrains Mono', monospace; font-size: 18px; font-weight: 700; color: #35E8D5; }
  button.copy { background: #35E8D5; color: #0F1C3A; border: none; padding: 6px 12px; border-radius: 6px; font-weight: 700; cursor: pointer; }
  .status { text-align: center; padding: 14px; font-size: 13px; color: #94A3B8; }
  .status.paid { color: #35E8D5; font-weight: 700; }
  .topbar { display:flex; align-items:center; margin-bottom:16px; }
  .back-btn { display:inline-flex; align-items:center; gap:6px; background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.14); color:#E2E8F0; padding:9px 14px; border-radius:10px; font-size:14px; font-weight:600; cursor:pointer; text-decoration:none; }
  .back-btn:hover { background:rgba(255,255,255,.12); }
  .ref-row { display:flex; align-items:center; justify-content:space-between; gap:10px; background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.08); padding:10px 12px; border-radius:10px; margin-top:12px; }
  .ref-row .lbl { font-size:11px; color:#94A3B8; }
  .ref-row .val { font-family:'JetBrains Mono', monospace; font-size:13px; color:#E2E8F0; word-break:break-all; }
  .mini-btn { background:rgba(53,232,213,.12); color:#35E8D5; border:1px solid rgba(53,232,213,.3); padding:6px 12px; border-radius:8px; font-weight:700; font-size:12px; cursor:pointer; white-space:nowrap; }
  .mini-btn:hover { background:rgba(53,232,213,.2); }
  .qr-actions { display:flex; gap:10px; margin-top:16px; }
  .qr-actions .act-btn { flex:1; display:inline-flex; align-items:center; justify-content:center; gap:7px; background:#35E8D5; color:#0F1C3A; border:none; padding:12px; border-radius:10px; font-weight:700; font-size:14px; cursor:pointer; }
  .qr-actions .act-btn.alt { background:rgba(255,255,255,.08); color:#E2E8F0; border:1px solid rgba(255,255,255,.16); }
  .qr-actions .act-btn:active { transform:scale(.98); }
  #toast { position:fixed; left:50%; bottom:28px; transform:translateX(-50%) translateY(20px); background:#1E293B; color:#fff; border:1px solid rgba(255,255,255,.15); padding:11px 18px; border-radius:10px; font-size:13px; opacity:0; pointer-events:none; transition:opacity .2s, transform .2s; z-index:50; }
  #toast.show { opacity:1; transform:translateX(-50%) translateY(0); }
</style>
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <a class="back-btn" href="#" onclick="goBack();return false;">&larr; Kembali</a>
  </div>
  <div class="card">
    <h1>Pembayaran QRIS / VA</h1>
    <div class="item"><?= htmlspecialchars($itemName) ?></div>
    <div>Total Pembayaran:</div>
    <div class="amount">Rp <?= number_format($amount, 0, ',', '.') ?></div>
    <div class="timer">&#x23F1; Selesaikan pembayaran dalam <span id="timer"><?= floor($secondsRemaining / 60) ?> menit</span></div>
    <div class="ref-row">
      <div>
        <div class="lbl">Nominal</div>
        <div class="val">Rp <?= number_format($amount, 0, ',', '.') ?></div>
      </div>
      <button class="mini-btn" onclick="copyText('<?= (int)$amount ?>', this, 'Nominal disalin')">Salin</button>
    </div>
    <div class="ref-row">
      <div>
        <div class="lbl">Order ID</div>
        <div class="val"><?= htmlspecialchars($payment['order_id']) ?></div>
      </div>
      <button class="mini-btn" onclick="copyText('<?= htmlspecialchars($payment['order_id'], ENT_QUOTES) ?>', this, 'Order ID disalin')">Salin</button>
    </div>
  </div>

  <?php if ($payment['payment_type'] === 'qris' && $payment['qr_string']): ?>
  <div class="card">
    <h3 style="margin-bottom: 14px;">Scan QRIS</h3>
    <div class="qr-wrap">
      <img src="<?= htmlspecialchars($payment['qr_string']) ?>" alt="QRIS QR Code">
    </div>
    <p style="font-size: 12px; color: #94A3B8; margin-top: 14px; text-align: center;">
      Buka GoPay / OVO / Dana / Banking App &rarr; Scan QR ini
    </p>
    <div class="qr-actions">
      <button class="act-btn" onclick="downloadQR()">&#x2B07; Simpan QR</button>
      <button class="act-btn alt" onclick="shareQR()">&#x1F517; Bagikan</button>
    </div>
  </div>
  <?php elseif ($payment['payment_type'] === 'bank_transfer' && $payment['va_number']): ?>
  <div class="card">
    <h3 style="margin-bottom: 14px;">Transfer Bank &mdash; <?= strtoupper(htmlspecialchars($payment['va_bank'])) ?></h3>
    <div class="va-box">
      <div>
        <div style="font-size: 11px; color: #94A3B8;">Nomor Virtual Account</div>
        <div class="va-num"><?= htmlspecialchars($payment['va_number']) ?></div>
      </div>
      <button class="copy" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($payment['va_number']) ?>'); this.textContent='Copied'">Copy</button>
    </div>
    <p style="font-size: 12px; color: #94A3B8; margin-top: 14px;">
      Transfer dari rekening manapun ke VA di atas. Auto-confirm setelah pembayaran berhasil.
    </p>
  </div>
  <?php endif; ?>

  <div class="status" id="status">Menunggu pembayaran...</div>
</div>

<script>
let polling = true;
const orderId = <?= json_encode($payment['order_id']) ?>;
const expiresAt = <?= strtotime($payment['expires_at']) * 1000 ?>;

function fmtTime(secs) {
  if (secs <= 0) return 'Expired';
  const m = Math.floor(secs / 60);
  const s = secs % 60;
  return m + ' menit ' + (s < 10 ? '0' : '') + s + ' detik';
}

function tick() {
  const remaining = Math.floor((expiresAt - Date.now()) / 1000);
  document.getElementById('timer').textContent = fmtTime(remaining);
  if (remaining <= 0) {
    polling = false;
    document.getElementById('status').textContent = 'Payment expired. Silakan refresh untuk create payment baru.';
  }
}
setInterval(tick, 1000);
tick();

async function poll() {
  if (!polling) return;
  try {
    const r = await fetch('/api/billing-status.php?order_id=' + encodeURIComponent(orderId));
    const d = await r.json();
    if (d.status === 'paid') {
      polling = false;
      document.getElementById('status').innerHTML = '<span class="paid">Pembayaran berhasil! Redirecting...</span>';
      setTimeout(() => location.href = '/billing-success.php?order_id=' + encodeURIComponent(orderId), 1500);
    } else if (['expired', 'failed', 'cancelled'].includes(d.status)) {
      polling = false;
      document.getElementById('status').textContent = 'Pembayaran ' + d.status + '. Refresh untuk retry.';
    }
  } catch (e) { /* network error — keep polling */ }
}
setInterval(poll, 5000);

// ── Navigasi + aksi QR ──
const qrUrl = <?= json_encode($payment['qr_string'] ?? '') ?>;

function goBack() {
  if (document.referrer && history.length > 1) { history.back(); }
  else { location.href = '/dashboard'; }
}

function showToast(msg) {
  let t = document.getElementById('toast');
  if (!t) { t = document.createElement('div'); t.id = 'toast'; document.body.appendChild(t); }
  t.textContent = msg; t.classList.add('show');
  clearTimeout(t._h); t._h = setTimeout(() => t.classList.remove('show'), 2200);
}

function copyText(txt, btn, msg) {
  const done = () => {
    showToast(msg || 'Tersalin');
    if (btn) { const o = btn.textContent; btn.textContent = 'Tersalin'; setTimeout(() => { btn.textContent = o; }, 1500); }
  };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(txt).then(done, () => fallbackCopy(txt, done));
  } else { fallbackCopy(txt, done); }
}
function fallbackCopy(txt, done) {
  const ta = document.createElement('textarea');
  ta.value = txt; ta.style.position = 'fixed'; ta.style.opacity = '0';
  document.body.appendChild(ta); ta.focus(); ta.select();
  try { document.execCommand('copy'); done(); } catch (e) { showToast('Gagal menyalin'); }
  document.body.removeChild(ta);
}

async function fetchQrBlob() {
  if (!qrUrl) return null;
  try {
    const r = await fetch(qrUrl, { mode: 'cors' });
    if (!r.ok) return null;
    return await r.blob();
  } catch (e) { return null; }
}

async function downloadQR() {
  if (!qrUrl) { showToast('QR tidak tersedia'); return; }
  const blob = await fetchQrBlob();
  if (blob) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = 'qris-' + orderId + '.png';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    showToast('QR tersimpan');
  } else {
    window.open(qrUrl, '_blank', 'noopener');
    showToast('QR dibuka di tab baru — tahan gambar untuk simpan');
  }
}

async function shareQR() {
  const amtTxt = 'Pembayaran QRIS LAMASY Rp ' + (<?= (int)$amount ?>).toLocaleString('id-ID');
  const blob = await fetchQrBlob();
  if (blob && navigator.canShare) {
    const file = new File([blob], 'qris-' + orderId + '.png', { type: blob.type || 'image/png' });
    if (navigator.canShare({ files: [file] })) {
      try { await navigator.share({ files: [file], title: 'QRIS Pembayaran', text: amtTxt }); return; }
      catch (e) { if (e && e.name === 'AbortError') return; }
    }
  }
  if (navigator.share) {
    try { await navigator.share({ title: 'QRIS Pembayaran', text: amtTxt }); return; }
    catch (e) { if (e && e.name === 'AbortError') return; }
  }
  if (qrUrl) window.open(qrUrl, '_blank', 'noopener');
  showToast('Bagikan tidak didukung — QR dibuka di tab baru');
}
</script>
</body>
</html>

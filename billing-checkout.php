<?php
require_once __DIR__ . '/middleware/tenant_guard.php';
require_once __DIR__ . '/core/MidtransClient.php';
require_once __DIR__ . '/core/BillingConfig.php';

date_default_timezone_set('Asia/Jakarta');

$tenantId = (int)($_SESSION['tenant_id'] ?? 0);
if (!$tenantId) { header('Location: /login'); exit; }

// ── Proxy gambar QR (same-origin) supaya bisa di-share sbg file via navigator.share ──
// Scope tenant + hanya domain Midtrans (anti-SSRF). Baca qr_string milik payment sendiri.
if (($_GET['action'] ?? '') === 'qr_img') {
    $pid = (int)($_GET['pid'] ?? 0);
    $db  = Database::get();
    $st  = $db->prepare("SELECT qr_string FROM saas_payments WHERE id=? AND tenant_id=?");
    $st->execute([$pid, $tenantId]);
    $qr  = (string)$st->fetchColumn();
    if ($qr === '') { http_response_code(404); exit; }
    $host = parse_url($qr, PHP_URL_HOST) ?: '';
    if (!preg_match('/(^|\.)midtrans\.com$/i', $host)) { http_response_code(400); exit; }
    $ch = curl_init($qr);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => true]);
    $img  = curl_exec($ch);
    $ct   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'image/png';
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || $img === false || $img === '') { http_response_code(502); exit; }
    header('Content-Type: ' . $ct);
    header('Cache-Control: private, max-age=300');
    echo $img; exit;
}

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
    $fee  = BillingConfig::getInt('outlet_activation_fee', 800000);
    $disc = max(0, min(100, BillingConfig::getInt('outlet_activation_discount', 0)));
    $amount = (int)round($fee * (1 - $disc / 100));
    $refOutletId = $outlet['id'];
    $itemName = "Aktivasi Outlet — {$outlet['nama_outlet']}";
}

// Check existing pending payment (resume kalau ada)
// Strict AND-clause: semua ref harus cocok — hindari false match antar bundle/outlet berbeda
$existing = $db->prepare(
    // expires_at dibandingkan dgn waktu PHP (WIB) — sama dgn cara expires_at ditulis
    // (date() Asia/Jakarta). MySQL NOW() = UTC → dulu bikin pending "hidup" 7 jam ekstra.
    "SELECT * FROM saas_payments
     WHERE tenant_id=? AND type=? AND status='pending' AND expires_at > ?
       AND COALESCE(ref_bundle_id, 0) = COALESCE(?, 0)
       AND COALESCE(ref_outlet_id, 0) = COALESCE(?, 0)
       AND COALESCE(ref_package_id, 0) = COALESCE(?, 0)
     ORDER BY id DESC LIMIT 1"
);
$existing->execute([
    $tenantId, $type,
    date('Y-m-d H:i:s'),
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
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#000000">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title>Pembayaran — LAMASY</title>
<link rel="stylesheet" href="/harpy-erp.css?v=<?= date('Ymd') ?>">
<style>
  :root{ --off:#F3F4F6; --navy:#0F1C3A; --teal:#35E8D5; --teal-d:#1CC4B2; --ink:#1F2937; --ash:#6B7280; }
  *{box-sizing:border-box}
  body{ font-family:'Plus Jakarta Sans',system-ui,sans-serif; background:var(--off); color:var(--ink); margin:0; padding:0;
        min-height:100vh; -webkit-font-smoothing:antialiased; }
  /* Bar hitam area status bar / notch */
  body::before{ content:""; position:fixed; top:0; left:0; right:0; height:env(safe-area-inset-top,0px); background:#000; z-index:1000; pointer-events:none; }
  /* Header sticky */
  .bc-top{ position:sticky; top:0; z-index:90; background:var(--navy); color:#fff;
           padding:calc(env(safe-area-inset-top,0px) + 12px) 16px 12px; display:flex; align-items:center; gap:12px; }
  .bc-top .back-btn{ display:inline-flex; align-items:center; gap:6px; background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.18);
                     color:#fff; padding:8px 12px; border-radius:10px; font-size:13.5px; font-weight:600; text-decoration:none; cursor:pointer; }
  .bc-top .back-btn:active{ transform:scale(.97); }
  .bc-top .bc-title{ font-size:15px; font-weight:800; }
  .wrap{ max-width:480px; margin:0 auto; padding:16px 16px 40px; }
  .card{ background:#fff; border:1px solid #EEF1F8; border-radius:16px; padding:20px; margin-bottom:14px; box-shadow:0 1px 6px rgba(15,28,58,.06); }
  h1{ font-size:20px; font-weight:800; color:var(--navy); margin:0 0 4px; }
  h3{ font-size:15px; font-weight:800; color:var(--navy); margin:0 0 14px; }
  .item{ color:var(--ash); font-size:13px; margin-bottom:18px; }
  .amount{ font-size:clamp(18px,7vw,30px); white-space:nowrap;letter-spacing:-0.02em; font-weight:800; font-family:'DM Mono',monospace; color:var(--navy); margin:14px 0; }
  .timer{ background:#FFF7ED; border:1px solid #FED7AA; color:#B45309; padding:10px 14px; border-radius:10px; font-size:13px; text-align:center; font-weight:600; }
  .timer.expired{ background:#FEE2E2; border-color:#FCA5A5; color:#B91C1C; }
  .pay-dim{ opacity:.35; pointer-events:none; filter:grayscale(.6); }
  .retry-btn{ display:inline-flex; align-items:center; gap:7px; margin-top:10px; background:var(--navy); color:#fff; border:none; padding:11px 18px; border-radius:11px; font-weight:800; font-size:14px; cursor:pointer; }
  .qr-wrap{ text-align:center; padding:18px; background:#fff; border:1px solid #E5E9F2; border-radius:14px; }
  .qr-wrap img{ max-width:240px; width:100%; height:auto; }
  .va-box{ display:flex; align-items:center; justify-content:space-between; gap:10px; background:#F0FDFA; border:1px solid #99F6E4; padding:14px; border-radius:12px; margin:8px 0; }
  .va-num{ font-family:'DM Mono',monospace; font-size:18px; font-weight:800; color:var(--teal-d); word-break:break-all; }
  button.copy{ background:var(--teal); color:var(--navy); border:none; padding:8px 14px; border-radius:8px; font-weight:800; cursor:pointer; white-space:nowrap; }
  .status{ text-align:center; padding:14px; font-size:13px; color:var(--ash); }
  .status.paid{ color:var(--teal-d); font-weight:800; }
  .ref-row{ display:flex; align-items:center; justify-content:space-between; gap:10px; background:#F7F8FC; border:1px solid #EEF1F8; padding:11px 12px; border-radius:12px; margin-top:12px; }
  .ref-row .lbl{ font-size:11px; color:var(--ash); font-weight:600; }
  .ref-row .val{ font-family:'DM Mono',monospace; font-size:13px; color:var(--navy); word-break:break-all; font-weight:700; }
  .mini-btn{ background:#EAFBF8; color:var(--teal-d); border:1px solid #99F6E4; padding:7px 13px; border-radius:9px; font-weight:800; font-size:12px; cursor:pointer; white-space:nowrap; }
  .mini-btn:active{ transform:scale(.97); }
  .qr-actions{ display:flex; gap:10px; margin-top:16px; }
  .qr-actions .act-btn{ flex:1; display:inline-flex; align-items:center; justify-content:center; gap:7px; background:var(--navy); color:#fff; border:none; padding:13px; border-radius:12px; font-weight:800; font-size:14px; cursor:pointer; }
  .qr-actions .act-btn.alt{ background:var(--teal); color:var(--navy); }
  .qr-actions .act-btn:active{ transform:scale(.98); }
  #toast{ position:fixed; left:50%; bottom:28px; transform:translateX(-50%) translateY(20px); background:var(--navy); color:#fff; padding:11px 18px; border-radius:10px; font-size:13px; opacity:0; pointer-events:none; transition:opacity .2s,transform .2s; z-index:1100; }
  #toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }
</style>
</head>
<body>
<header class="bc-top">
  <a class="back-btn" href="#" onclick="goBack();return false;">&larr; Kembali</a>
  <span class="bc-title">Pembayaran</span>
</header>
<div class="wrap">
  <div class="card">
    <h1>Pembayaran QRIS / VA</h1>
    <div class="item"><?= htmlspecialchars($itemName) ?></div>
    <div>Total Pembayaran:</div>
    <div class="amount">Rp <?= number_format($amount, 0, ',', '.') ?></div>
    <div class="timer" id="timerBox">&#x23F1; Selesaikan pembayaran dalam <span id="timer"><?= floor($secondsRemaining / 60) ?> menit</span></div>
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
  <div class="card" id="payCard">
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
  <div class="card" id="payCard">
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

let expiredHandled = false;

function fmtTime(secs) {
  const m = Math.floor(secs / 60);
  const s = secs % 60;
  return m + ' menit ' + (s < 10 ? '0' : '') + s + ' detik';
}

function markExpired() {
  if (expiredHandled) return;
  expiredHandled = true;
  polling = false;
  // Ubah kotak timer jadi status kedaluwarsa (bukan teks "Expired" mentah)
  const box = document.getElementById('timerBox');
  if (box) { box.classList.add('expired'); box.innerHTML = '&#x23F1; Pembayaran kedaluwarsa'; }
  // Redupkan QR/VA supaya tak discan lagi
  const pay = document.getElementById('payCard');
  if (pay) pay.classList.add('pay-dim');
  // Status + tombol buat pembayaran baru (reload → server buat pembayaran baru)
  const st = document.getElementById('status');
  if (st) {
    st.innerHTML = 'Pembayaran kedaluwarsa. Kode QR tidak berlaku lagi.<br>'
      + '<button class="retry-btn" onclick="location.reload()">&#x1F504; Buat Pembayaran Baru</button>';
  }
}

function tick() {
  const remaining = Math.floor((expiresAt - Date.now()) / 1000);
  if (remaining <= 0) { document.getElementById('timer').textContent = ''; markExpired(); return; }
  document.getElementById('timer').textContent = fmtTime(remaining);
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
const qrProxy = 'billing-checkout.php?action=qr_img&pid=<?= (int)$payment['id'] ?>'; // same-origin, bisa di-fetch utk share file

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
    const r = await fetch(qrProxy); // proxy same-origin → tak kena CORS, blob bisa dipakai share file
    if (!r.ok) return null;
    return await r.blob();
  } catch (e) { return null; }
}

// ── Kartu QR ber-bingkai (brand + nominal + QR + order) untuk share/simpan ──
const bcFont = 'system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif';
function bcLoadImg(src){
  return new Promise(function(res, rej){ var im = new Image(); im.onload = function(){ res(im); }; im.onerror = rej; im.src = src; });
}
function bcRoundRect(ctx, x, y, w, h, r){
  ctx.beginPath();
  ctx.moveTo(x+r, y); ctx.arcTo(x+w, y, x+w, y+h, r); ctx.arcTo(x+w, y+h, x, y+h, r);
  ctx.arcTo(x, y+h, x, y, r); ctx.arcTo(x, y, x+w, y, r); ctx.closePath();
}
function bcFit(ctx, text, maxW){ // potong + ellipsis kalau kepanjangan (1 baris)
  if (ctx.measureText(text).width <= maxW) return text;
  var t = text; while (t.length > 1 && ctx.measureText(t + '…').width > maxW) t = t.slice(0, -1);
  return t + '…';
}
function bcWrap(ctx, text, x, y, maxW, lh){ // bungkus ke beberapa baris (center)
  var words = String(text).split(' '), line = '', yy = y;
  for (var i = 0; i < words.length; i++){
    var test = line ? line + ' ' + words[i] : words[i];
    if (ctx.measureText(test).width > maxW && line){ ctx.fillText(line, x, yy); line = words[i]; yy += lh; }
    else line = test;
  }
  if (line) ctx.fillText(line, x, yy);
}
// Susun kartu QR → Blob PNG. Kalau apa pun gagal, fallback ke QR mentah (fetchQrBlob).
async function composeQrBlob(){
  if (!qrUrl) return null;
  try {
    var qr = await bcLoadImg(qrProxy); // same-origin → canvas tak ter-taint
    var S = 2, W = 680, H = 960;
    var cv = document.createElement('canvas'); cv.width = W * S; cv.height = H * S;
    var ctx = cv.getContext('2d'); ctx.scale(S, S); ctx.textBaseline = 'alphabetic';
    // latar putih + header navy
    ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, W, H);
    ctx.fillStyle = '#0F1C3A'; ctx.fillRect(0, 0, W, 150);
    ctx.textAlign = 'left';
    ctx.fillStyle = '#35E8D5'; ctx.font = '700 44px ' + bcFont; ctx.fillText('LAMASY', 40, 82);
    ctx.fillStyle = '#ffffff'; ctx.font = '500 22px ' + bcFont; ctx.fillText('Pembayaran QRIS', 40, 116);
    ctx.textAlign = 'center';
    // nama item (1 baris, dipotong bila panjang)
    var item = <?= json_encode($itemName ?? '') ?>;
    if (item){ ctx.fillStyle = '#6B7280'; ctx.font = '500 20px ' + bcFont; ctx.fillText(bcFit(ctx, item, W - 80), W/2, 198); }
    // nominal
    ctx.fillStyle = '#1F2937'; ctx.font = '800 46px ' + bcFont;
    ctx.fillText('Rp ' + (<?= (int)$amount ?>).toLocaleString('id-ID'), W/2, 258);
    // kotak QR
    var qsz = 380, bw = qsz + 40, bh = qsz + 40, bx = (W - bw)/2, byy = 296;
    ctx.fillStyle = '#ffffff'; ctx.strokeStyle = '#E5E7EB'; ctx.lineWidth = 2;
    bcRoundRect(ctx, bx, byy, bw, bh, 18); ctx.fill(); ctx.stroke();
    ctx.drawImage(qr, bx + 20, byy + 20, qsz, qsz);
    // order id
    ctx.fillStyle = '#6B7280'; ctx.font = '500 18px ' + bcFont;
    ctx.fillText('Order: ' + orderId, W/2, byy + bh + 42);
    // footer
    var fy = byy + bh + 74;
    ctx.fillStyle = '#F3F4F6'; ctx.fillRect(0, fy, W, H - fy);
    ctx.fillStyle = '#6B7280'; ctx.font = '500 18px ' + bcFont;
    bcWrap(ctx, 'Scan dengan GoPay • OVO • DANA • ShopeePay • m-Banking', W/2, fy + 42, W - 90, 24);
    ctx.fillStyle = '#1CC4B2'; ctx.font = '600 16px ' + bcFont;
    ctx.fillText('lamasy.harpy.id', W/2, H - 26);
    var blob = await new Promise(function(res){ cv.toBlob(function(b){ res(b); }, 'image/png'); });
    if (blob) return blob;
  } catch (e) { /* fallback ke QR mentah */ }
  return await fetchQrBlob();
}

function bcIsNative(){ try { return !!(window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform()); } catch(e){ return false; } }
function bcPlugins(){ return (window.Capacitor && window.Capacitor.Plugins) || {}; }
function bcAmtTxt(){ return 'Pembayaran QRIS LAMASY Rp ' + (<?= (int)$amount ?>).toLocaleString('id-ID') + ' — Order ' + orderId; }
async function blobToBase64(blob){
  return new Promise(function(res, rej){ var r=new FileReader(); r.onloadend=function(){ res(String(r.result).split(',')[1]||''); }; r.onerror=rej; r.readAsDataURL(blob); });
}
// Return true kalau native menangani; false → caller fallback web.
async function nativeQr(mode){ // mode: 'share' | 'save'
  if (!bcIsNative()) return false;
  var P = bcPlugins(); var Filesystem = P.Filesystem, Share = P.Share;
  if (!Filesystem) return false;
  var blob = await composeQrBlob();
  if (!blob) {
    if (mode==='share' && Share) { try { await Share.share({ title:'QRIS Pembayaran', text:bcAmtTxt() }); return true; } catch(e){ if(e&&e.name==='AbortError') return true; } }
    return false;
  }
  var b64 = await blobToBase64(blob);
  var name = 'qris-' + orderId + '.png';
  try {
    if (mode==='save') {
      await Filesystem.writeFile({ path:name, data:b64, directory:'DOCUMENTS' });
      showToast('QR tersimpan di Files (Documents)');
      return true;
    }
    var w = await Filesystem.writeFile({ path:name, data:b64, directory:'CACHE' });
    var uri = (w && w.uri) ? w.uri : null;
    if (!uri && Filesystem.getUri) { try { var g = await Filesystem.getUri({ path:name, directory:'CACHE' }); uri = g && g.uri; } catch(e){} }
    if (Share && uri) { try { await Share.share({ title:'QRIS Pembayaran', text:bcAmtTxt(), files:[uri] }); return true; } catch(e){ if(e&&e.name==='AbortError') return true; } }
    if (Share) { try { await Share.share({ title:'QRIS Pembayaran', text:bcAmtTxt() }); return true; } catch(e){ if(e&&e.name==='AbortError') return true; } }
  } catch(e){ /* fallback web */ }
  return false;
}

async function downloadQR() {
  if (!qrUrl) { showToast('QR tidak tersedia'); return; }
  if (await nativeQr('save')) return;
  // Web/PWA: simpan kartu QR ber-bingkai via blob URL (native APK sudah ditangani nativeQr).
  const blob = await composeQrBlob();
  if (blob) {
    try {
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url; a.download = 'qris-' + orderId + '.png'; a.rel = 'noopener';
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 4000);
      showToast('Menyimpan QR…');
      return;
    } catch (e) { /* fallback URL asli */ }
  }
  try {
    const a = document.createElement('a');
    a.href = qrUrl; a.download = 'qris-' + orderId + '.png';
    a.rel = 'noopener';
    document.body.appendChild(a); a.click(); a.remove();
    showToast('Menyimpan QR…');
  } catch (e) {
    window.open(qrUrl, '_blank', 'noopener');
    showToast('QR dibuka — tahan gambar untuk simpan');
  }
}

async function shareQR() {
  if (await nativeQr('share')) return;
  const amtTxt = 'Pembayaran QRIS LAMASY Rp ' + (<?= (int)$amount ?>).toLocaleString('id-ID');
  const blob = await composeQrBlob();
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

<?php
// ══════════════════════════════════════════════════════
// qris.php — PUBLIC /qris: halaman QRIS untuk dibuka pelanggan dari WhatsApp.
// Tanpa login. TIDAK menampilkan nomor rekening / tenant_id / data pelanggan
// (label QRIS internal sengaja tidak dipakai — bisa memuat nomor rekening).
// Halaman ringan: tanpa font/skrip eksternal, hanya CSS + SVG inline.
// ══════════════════════════════════════════════════════
define('ROOT', __DIR__);
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';
require_once ROOT . '/core/PublicLinks.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: public, max-age=300');
$db = Database::get();
$t  = PublicLinks::target($db);
$qris = null; $outletNama = 'Harpy Laundry'; $wa = '';
if ($t) {
    $st = $db->prepare("SELECT nama_outlet, qris_image, telepon FROM outlets WHERE id=? AND tenant_id=?");
    $st->execute([(int)$t['outlet_id'], (int)$t['tenant_id']]);
    if ($o = $st->fetch(PDO::FETCH_ASSOC)) {
        $outletNama = $o['nama_outlet'] ?: $outletNama;
        $abs = $o['qris_image'] ? realpath(ROOT . $o['qris_image']) : false;
        if ($abs && is_file($abs)) $qris = $abs;
        $d = preg_replace('/\D+/', '', (string)$o['telepon']);       // nomor WA bisnis (tertera di brosur)
        if ($d !== '') $wa = preg_match('/^0/', $d) ? '62' . substr($d, 1) : $d;
    }
}
$merchant = $t['merchant_nama'] ?? 'Harpy Laundry';
$lokasi   = trim(preg_replace('/^Harpy Laundry\s*/i', '', $outletNama));
$ver  = $qris ? filemtime($qris) : 0;
$img  = '/qris.png?v=' . $ver;
$abs_img = PublicLinks::absBase() . $img;
$waUrl = $wa !== '' ? 'https://wa.me/' . $wa . '?text=' . rawurlencode('Halo Harpy Laundry, ini bukti pembayaran saya.') : '';
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
if (!$qris) http_response_code(404);
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#1F3F66">
<title>QRIS Pembayaran <?= $e($outletNama) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="QRIS Pembayaran <?= $e($outletNama) ?>">
<meta property="og:description" content="Scan QRIS untuk membayar. Isi nominal sesuai nota, lalu kirim bukti bayar lewat WhatsApp.">
<?php if ($qris): ?><meta property="og:image" content="<?= $e($abs_img) ?>">
<meta property="og:image:alt" content="QRIS <?= $e($merchant) ?>"><?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<style>
:root{--navy:#1F3F66;--navy-d:#152E4C;--cream:#FBE9D0;--teal:#4FD1C5;--teal-d:#2C9C93;--ink:#14213D;--mute:#A9BBD0;--wa:#1FAF5A}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;min-height:100vh;color:var(--cream);font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
  background:
    radial-gradient(120% 60% at 50% -10%,rgba(79,209,197,.28),transparent 60%),
    radial-gradient(circle at 1px 1px,rgba(251,233,208,.07) 1px,transparent 0) 0 0/22px 22px,
    linear-gradient(180deg,var(--navy) 0%,var(--navy-d) 100%);
  background-color:var(--navy-d);
  padding:max(18px,env(safe-area-inset-top)) max(16px,env(safe-area-inset-right)) max(28px,env(safe-area-inset-bottom)) max(16px,env(safe-area-inset-left))}
main{max-width:440px;margin:0 auto;display:flex;flex-direction:column;gap:20px}
.brand{display:flex;align-items:center;gap:12px}
.logo{width:46px;height:46px;border-radius:50%;background:var(--teal);color:var(--navy);display:grid;place-items:center;
  font:800 26px/1 Georgia,"Times New Roman",serif;box-shadow:0 0 0 4px rgba(79,209,197,.25)}
.brand b{display:block;font-size:19px;letter-spacing:.09em;line-height:1.1}
.brand span{font-size:13px;color:var(--mute)}
.hero h1{margin:0;font-size:clamp(28px,8vw,36px);line-height:1.1;font-weight:800;letter-spacing:-.01em;text-wrap:balance}
.hero h1 em{font-style:normal;color:var(--teal)}
.merchant{display:inline-flex;align-items:center;gap:8px;margin-top:12px;padding:7px 14px 7px 10px;border-radius:999px;
  background:rgba(251,233,208,.1);border:1px solid rgba(251,233,208,.22);font-size:14px;font-weight:600}
.merchant svg{flex:none}
.qr{position:relative;background:#fff;border-radius:26px;padding:26px;box-shadow:0 18px 40px rgba(6,18,36,.45),0 0 0 6px rgba(251,233,208,.08)}
.qr i{position:absolute;width:34px;height:34px;border:5px solid var(--teal-d);pointer-events:none}
.qr i:nth-child(1){top:12px;left:12px;border-right:0;border-bottom:0;border-top-left-radius:14px}
.qr i:nth-child(2){top:12px;right:12px;border-left:0;border-bottom:0;border-top-right-radius:14px}
.qr i:nth-child(3){bottom:12px;left:12px;border-right:0;border-top:0;border-bottom-left-radius:14px}
.qr i:nth-child(4){bottom:12px;right:12px;border-left:0;border-top:0;border-bottom-right-radius:14px}
.qr img{display:block;width:100%;height:auto;border-radius:6px;background:#fff}
.qr p{margin:12px 0 0;text-align:center;color:#4A5568;font-size:13px}
.acts{display:flex;flex-direction:column;gap:10px}
.btn{display:flex;align-items:center;justify-content:center;gap:10px;min-height:52px;padding:12px 18px;border-radius:14px;
  font-weight:700;font-size:16px;text-decoration:none;border:0;cursor:pointer}
.btn.save{background:var(--teal);color:var(--navy-d)}
.btn.wa{background:var(--wa);color:#fff}
.btn:focus-visible{outline:3px solid var(--cream);outline-offset:3px}
.steps{list-style:none;margin:0;padding:6px 18px;background:var(--cream);color:var(--ink);border-radius:20px}
.steps li{display:grid;grid-template-columns:36px 1fr;gap:14px;align-items:center;padding:14px 0}
.steps li+li{border-top:1px dashed rgba(20,33,61,.2)}
.steps b{display:grid;place-items:center;width:36px;height:36px;border-radius:50%;background:var(--navy);color:var(--cream);font-size:16px}
.steps span{font-size:15.5px;line-height:1.4}
.steps strong{display:block;font-size:16px}
.foot{text-align:center;color:var(--mute);font-size:12.5px;margin-top:2px}
.empty{background:var(--cream);color:var(--ink);border-radius:20px;padding:28px 20px;text-align:center}
</style>
</head>
<body>
<main>
  <header class="brand">
    <div class="logo" aria-hidden="true">H</div>
    <div><b>HARPY LAUNDRY</b><?php if ($lokasi !== ''): ?><span><?= $e($lokasi) ?></span><?php endif; ?></div>
  </header>

  <section class="hero">
    <h1>Bayar dengan <em>QRIS</em></h1>
    <div class="merchant">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="#4FD1C5"/><path d="M7 12.5l3.2 3.2L17 9" stroke="#152E4C" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
      <?= $e($merchant) ?>
    </div>
  </section>

<?php if ($qris): ?>
  <section class="qr" aria-label="Kode QRIS">
    <i></i><i></i><i></i><i></i>
    <img src="<?= $e($img) ?>" alt="QRIS <?= $e($merchant) ?>" width="600" height="600">
    <p>Bisa dibayar lewat semua e-wallet dan mobile banking yang mendukung QRIS.</p>
  </section>

  <div class="acts">
    <a class="btn save" href="/qris.png" download>
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 4v11m0 0l-4.5-4.5M12 15l4.5-4.5M5 19h14" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Simpan gambar QRIS
    </a>
    <?php if ($waUrl): ?>
    <a class="btn wa" href="<?= $e($waUrl) ?>" target="_blank" rel="noopener">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 00-8.6 15.1L2 22l5-1.3A10 10 0 1012 2zm5.2 13.9c-.2.6-1.3 1.2-1.8 1.2-.5.1-1 .2-3.2-.7-2.7-1.1-4.4-3.8-4.6-4-.1-.2-1.1-1.5-1.1-2.8s.7-2 1-2.3c.2-.3.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.8 2c.1.2.1.4 0 .5l-.4.6c-.1.2-.3.3-.1.6.2.3.8 1.3 1.8 2.1 1.2 1.1 2.2 1.4 2.5 1.5.3.1.4.1.6-.1l.8-1c.2-.3.4-.2.6-.1l1.9.9c.3.1.5.2.5.3.1.2.1.8-.1 1.4z"/></svg>
      Kirim bukti bayar ke WhatsApp
    </a>
    <?php endif; ?>
  </div>

  <ol class="steps">
    <li><b>1</b><span><strong>Scan QRIS</strong>Buka e-wallet atau mobile banking, lalu pilih scan.</span></li>
    <li><b>2</b><span><strong>Isi nominal</strong>Masukkan jumlah sesuai nota.</span></li>
    <li><b>3</b><span><strong>Kirim bukti bayar</strong>Kirim ke chat WhatsApp kami setelah transfer berhasil.</span></li>
  </ol>
<?php else: ?>
  <div class="empty">QRIS belum tersedia. Silakan hubungi kami lewat WhatsApp.</div>
<?php endif; ?>
  <div class="foot">Harpy Laundry · <?= $e($merchant) ?></div>
</main>
</body>
</html>

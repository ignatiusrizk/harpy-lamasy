<?php
// ══════════════════════════════════════════════════════
// harga.php — PUBLIC /harga: halaman daftar harga (gambar yang diunggah, apa adanya) + tombol
// "Mau laundry" (WhatsApp) dan "Bagikan". Tanpa login. Tanpa rekening / tenant_id / data pelanggan.
// Halaman ringan: tanpa font/skrip eksternal, hanya CSS + SVG + sedikit JS inline.
// ══════════════════════════════════════════════════════
define('ROOT', __DIR__);
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';
require_once ROOT . '/core/PublicLinks.php';

$db = Database::get();
$t  = PublicLinks::target($db);
$abs = $t ? PublicLinks::hargaFile($t) : null;
$outletNama = 'Harpy Laundry'; $wa = '';
if ($t) {
    $st = $db->prepare("SELECT nama_outlet, telepon FROM outlets WHERE id=? AND tenant_id=?");
    $st->execute([(int)$t['outlet_id'], (int)$t['tenant_id']]);
    if ($o = $st->fetch(PDO::FETCH_ASSOC)) {
        $outletNama = $o['nama_outlet'] ?: $outletNama;
        $d = preg_replace('/\D+/', '', (string)$o['telepon']);       // nomor WA bisnis (tertera di brosur)
        if ($d !== '') $wa = preg_match('/^0/', $d) ? '62' . substr($d, 1) : $d;
    }
}
header('Cache-Control: public, max-age=300');
if (!$abs) http_response_code($t ? 503 : 404);
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$lokasi = trim(preg_replace('/^Harpy Laundry\s*/i', '', $outletNama));
$img    = '/harga.png?v=' . ($abs ? filemtime($abs) : 0);
$title  = 'Daftar Harga ' . $outletNama;
$pageUrl = PublicLinks::absBase() . '/harga';
$waUrl  = $wa !== '' ? 'https://wa.me/' . $wa . '?text=' . rawurlencode('Halo Harpy Laundry, saya mau laundry. Boleh dibantu?') : '';
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#1F3F66">
<title><?= $e($title) ?></title>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= $e($title) ?>">
<meta property="og:description" content="Daftar harga laundry kiloan, express, dan satuan.">
<meta property="og:url" content="<?= $e($pageUrl) ?>">
<?php if ($abs): ?><meta property="og:image" content="<?= $e(PublicLinks::absBase() . $img) ?>">
<meta property="og:image:alt" content="<?= $e($title) ?>"><?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<style>
:root{--navy:#1F3F66;--navy-d:#152E4C;--cream:#FBE9D0;--teal:#4FD1C5;--ink:#14213D;--mute:#A9BBD0;--wa:#1FAF5A}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;min-height:100vh;color:var(--cream);font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
  background:
    radial-gradient(120% 50% at 50% -8%,rgba(79,209,197,.26),transparent 60%),
    radial-gradient(circle at 1px 1px,rgba(251,233,208,.07) 1px,transparent 0) 0 0/22px 22px,
    linear-gradient(180deg,var(--navy) 0%,var(--navy-d) 100%);
  background-color:var(--navy-d);
  padding:max(18px,env(safe-area-inset-top)) max(16px,env(safe-area-inset-right)) calc(112px + env(safe-area-inset-bottom)) max(16px,env(safe-area-inset-left))}
main{max-width:520px;margin:0 auto;display:flex;flex-direction:column;gap:18px}
.brand{display:flex;align-items:center;gap:12px}
.logo{width:46px;height:46px;border-radius:50%;background:var(--teal);color:var(--navy);display:grid;place-items:center;
  font:800 26px/1 Georgia,"Times New Roman",serif;box-shadow:0 0 0 4px rgba(79,209,197,.25)}
.brand b{display:block;font-size:19px;letter-spacing:.09em;line-height:1.1}
.brand span{font-size:13px;color:var(--mute)}
.hero h1{margin:0;font-size:clamp(28px,8vw,36px);line-height:1.1;font-weight:800;letter-spacing:-.01em;text-wrap:balance}
.hero h1 em{font-style:normal;color:var(--teal)}
.hero p{margin:8px 0 0;color:var(--mute);font-size:15px}
.sheet{position:relative;background:#fff;border-radius:22px;padding:8px;box-shadow:0 18px 40px rgba(6,18,36,.45),0 0 0 6px rgba(251,233,208,.08)}
.sheet img{display:block;width:100%;height:auto;border-radius:16px}
.zoom{align-self:center;color:var(--teal);font-weight:600;font-size:14px;text-decoration:none;padding:6px 10px}
.empty{background:var(--cream);color:var(--ink);border-radius:20px;padding:28px 20px;text-align:center}
.bar{position:fixed;left:0;right:0;bottom:0;z-index:10;
  padding:12px max(16px,env(safe-area-inset-right)) calc(12px + env(safe-area-inset-bottom)) max(16px,env(safe-area-inset-left));
  background:linear-gradient(180deg,rgba(21,46,76,0),rgba(21,46,76,.94) 28%)}
.bar div{max-width:520px;margin:0 auto;display:flex;gap:10px}
.btn{display:flex;align-items:center;justify-content:center;gap:9px;min-height:54px;padding:12px 18px;border-radius:15px;
  font:700 16px/1 inherit;font-family:inherit;text-decoration:none;border:0;cursor:pointer}
.btn.wa{flex:1;background:var(--wa);color:#fff;box-shadow:0 8px 22px rgba(31,175,90,.35)}
.btn.share{background:var(--cream);color:var(--navy-d)}
.btn:focus-visible{outline:3px solid var(--cream);outline-offset:3px}
.toast{position:fixed;left:50%;bottom:calc(92px + env(safe-area-inset-bottom));transform:translate(-50%,8px);opacity:0;pointer-events:none;
  background:var(--cream);color:var(--ink);padding:10px 16px;border-radius:999px;font-weight:600;font-size:14px;transition:.2s;z-index:20}
.toast.on{opacity:1;transform:translate(-50%,0)}
@media (prefers-reduced-motion:reduce){.toast{transition:none}}
</style>
</head>
<body>
<main>
  <header class="brand">
    <div class="logo" aria-hidden="true">H</div>
    <div><b>HARPY LAUNDRY</b><?php if ($lokasi !== ''): ?><span><?= $e($lokasi) ?></span><?php endif; ?></div>
  </header>

  <section class="hero">
    <h1>Daftar <em>Harga</em></h1>
    <p>Kiloan, express, dan satuan. Siap pesan? Tekan tombol hijau di bawah.</p>
  </section>

<?php if ($abs): ?>
  <section class="sheet" id="daftar" aria-label="Daftar harga">
    <img src="<?= $e($img) ?>" alt="<?= $e($title) ?>" width="1131" height="1600">
  </section>
  <a class="zoom" href="<?= $e($img) ?>" target="_blank" rel="noopener">Buka gambar ukuran penuh</a>
<?php else: ?>
  <div class="empty">Daftar harga belum tersedia. Silakan hubungi kami lewat WhatsApp.</div>
<?php endif; ?>
</main>

<div class="bar">
  <div>
<?php if ($waUrl): ?>
    <a class="btn wa" href="<?= $e($waUrl) ?>" target="_blank" rel="noopener">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 00-8.6 15.1L2 22l5-1.3A10 10 0 1012 2zm5.2 13.9c-.2.6-1.3 1.2-1.8 1.2-.5.1-1 .2-3.2-.7-2.7-1.1-4.4-3.8-4.6-4-.1-.2-1.1-1.5-1.1-2.8s.7-2 1-2.3c.2-.3.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.8 2c.1.2.1.4 0 .5l-.4.6c-.1.2-.3.3-.1.6.2.3.8 1.3 1.8 2.1 1.2 1.1 2.2 1.4 2.5 1.5.3.1.4.1.6-.1l.8-1c.2-.3.4-.2.6-.1l1.9.9c.3.1.5.2.5.3.1.2.1.8-.1 1.4z"/></svg>
      Mau laundry
    </a>
<?php endif; ?>
    <button type="button" class="btn share" id="shareBtn" aria-label="Bagikan daftar harga">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="18" cy="5" r="3" stroke="currentColor" stroke-width="2.2"/><circle cx="6" cy="12" r="3" stroke="currentColor" stroke-width="2.2"/><circle cx="18" cy="19" r="3" stroke="currentColor" stroke-width="2.2"/><path d="M8.6 10.5l6.8-4M8.6 13.5l6.8 4" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
      Bagikan
    </button>
  </div>
</div>
<div class="toast" id="toast" role="status" aria-live="polite"></div>

<script>
(function(){
  var url = <?= json_encode($pageUrl, JSON_UNESCAPED_SLASHES) ?>, title = <?= json_encode($title) ?>;
  var toast = document.getElementById('toast'), timer;
  function say(msg){ toast.textContent = msg; toast.classList.add('on'); clearTimeout(timer); timer = setTimeout(function(){ toast.classList.remove('on'); }, 2200); }
  function copy(){
    var ta = document.createElement('textarea'); ta.value = url; ta.setAttribute('readonly',''); ta.style.cssText = 'position:fixed;opacity:0';
    document.body.appendChild(ta); ta.select();
    var ok = false; try { ok = document.execCommand('copy'); } catch(e){}
    document.body.removeChild(ta); say(ok ? 'Link tersalin' : 'Salin link: ' + url);
  }
  document.getElementById('shareBtn').addEventListener('click', function(){
    if (navigator.share) {
      navigator.share({title: title, text: 'Daftar harga Harpy Laundry', url: url}).catch(function(e){ if (e && e.name !== 'AbortError') copy(); });
    } else if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(function(){ say('Link tersalin'); }, copy);
    } else { copy(); }
  });
})();
</script>
</body>
</html>

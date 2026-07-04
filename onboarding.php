<?php
// ══════════════════════════════════════════════════════
// onboarding.php — Hari-1 Onboarding Flow (Brief 1)
// Full-screen checklist yang memandu tenant baru menyelesaikan
// transaksi pertama. Step di-track di tenants.onboarding_step dan
// diverifikasi ulang dari data nyata (layanan / pelanggan / order)
// setiap kali halaman dibuka — jadi robust walau step tak ke-update.
// ══════════════════════════════════════════════════════
if (session_status() === PHP_SESSION_NONE) session_start();

// Cegah WebView APK sajikan versi lama halaman
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$activePage = 'onboarding';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once __DIR__ . '/components.php';

// Logout shortcut
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /login?msg=logout');
    exit;
}

$tid = TenantResolver::id();
$oid = TenantResolver::outletId();
$db  = Database::get();

// ── Hitung progres nyata dari data ─────────────────────
function _obCount(PDO $db, string $sql, array $p): int {
    try { $s = $db->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); }
    catch (Throwable $e) { error_log('[onboarding] '.$e->getMessage()); return 0; }
}
$nLayanan   = _obCount($db, "SELECT COUNT(*) FROM hl_layanan WHERE tenant_id=? AND outlet_id=?", [$tid, $oid]);
$nPelanggan = _obCount($db, "SELECT COUNT(*) FROM hl_pelanggan WHERE tenant_id=?", [$tid]);
$nOrder     = _obCount($db, "SELECT COUNT(*) FROM hl_transaksi WHERE tenant_id=? AND outlet_id=?", [$tid, $oid]);

$step1 = $nLayanan   > 0;   // Tambah layanan
$step2 = $nPelanggan > 0;   // Tambah customer (opsional — bisa pakai customer umum)
$step3 = $nOrder     > 0;   // Buat order pertama = garis finish

// ── Sinkronkan onboarding_step ke DB sesuai progres ────
$curStep    = TenantResolver::onboardingStep();
$justDone   = false;
$targetStep = $curStep;
if ($step3)              $targetStep = 'activated';
elseif ($step1)          $targetStep = 'setup_done';
else                     $targetStep = 'registered';

if ($targetStep !== $curStep && !in_array($curStep, ['activated'], true)) {
    try {
        if ($targetStep === 'activated') {
            $db->prepare("UPDATE tenants SET onboarding_step='activated', onboarding_completed_at=NOW() WHERE id=?")
               ->execute([$tid]);
            $justDone = true;
        } else {
            $db->prepare("UPDATE tenants SET onboarding_step=? WHERE id=?")->execute([$targetStep, $tid]);
        }
        TenantResolver::refresh();
    } catch (Throwable $e) { error_log('[onboarding] update step: '.$e->getMessage()); }
}

// Kalau semua sudah selesai & step activated dari sebelumnya (bukan baru) → langsung dashboard.
$allDone = $step3;
if ($allDone && !$justDone) {
    header('Location: /dashboard');
    exit;
}

$doneCount = ($step1 ? 1 : 0) + ($step2 ? 1 : 0) + ($step3 ? 1 : 0);
$namaOutlet = TenantResolver::namaOutlet();
$ownerName  = htmlspecialchars(TenantResolver::getTenant()['owner_name'] ?? '');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Mulai Cepat'); ?>
<style>
:root{ --ob-navy:#0A0F1F; --ob-teal:#14b8a6; --ob-teal2:#0ea5a4; }
*{box-sizing:border-box}
body.ob-body{margin:0;min-height:100vh;background:
  radial-gradient(1200px 600px at 80% -10%, rgba(20,184,166,.18), transparent 60%),
  radial-gradient(900px 500px at -10% 110%, rgba(59,130,246,.14), transparent 55%),
  var(--ob-navy);
  color:#e6edf6;font-family:'Plus Jakarta Sans',system-ui,sans-serif;
  padding:calc(env(safe-area-inset-top,0px) + 24px) 16px 40px;
  display:flex;align-items:flex-start;justify-content:center;}
.ob-wrap{width:100%;max-width:520px;margin:0 auto}
.ob-head{text-align:center;margin-bottom:24px}
.ob-badge{display:inline-block;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;
  color:#5eead4;background:rgba(20,184,166,.12);border:1px solid rgba(20,184,166,.3);
  padding:5px 12px;border-radius:999px;margin-bottom:14px}
.ob-h1{font-size:26px;font-weight:800;line-height:1.2;margin:0 0 8px}
.ob-sub{font-size:14px;color:#9fb0c3;margin:0;line-height:1.5}
.ob-progress{display:flex;align-items:center;gap:10px;margin:22px 2px 20px}
.ob-bar{flex:1;height:8px;border-radius:999px;background:rgba(255,255,255,.08);overflow:hidden}
.ob-bar>i{display:block;height:100%;border-radius:999px;
  background:linear-gradient(90deg,var(--ob-teal2),var(--ob-teal));
  width:<?= (int)round($doneCount/3*100) ?>%;transition:width .5s cubic-bezier(.4,0,.2,1)}
.ob-pct{font-size:13px;font-weight:700;color:#5eead4;min-width:64px;text-align:right}
.ob-steps{display:flex;flex-direction:column;gap:12px}
.ob-step{display:flex;gap:14px;align-items:flex-start;background:rgba(255,255,255,.04);
  border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:16px;transition:.2s}
.ob-step.done{background:rgba(20,184,166,.07);border-color:rgba(20,184,166,.25)}
.ob-step.active{border-color:rgba(20,184,166,.55);box-shadow:0 0 0 3px rgba(20,184,166,.12)}
.ob-num{flex:none;width:34px;height:34px;border-radius:50%;display:grid;place-items:center;
  font-weight:800;font-size:15px;background:rgba(255,255,255,.08);color:#9fb0c3}
.ob-step.done .ob-num{background:var(--ob-teal);color:#04211d}
.ob-step.active .ob-num{background:#fff;color:var(--ob-navy)}
.ob-body-txt{flex:1;min-width:0}
.ob-title{font-weight:700;font-size:15px;margin:0 0 3px}
.ob-desc{font-size:13px;color:#9fb0c3;margin:0 0 10px;line-height:1.45}
.ob-cta{display:inline-block;font-weight:700;font-size:13px;text-decoration:none;
  padding:9px 16px;border-radius:10px;background:linear-gradient(90deg,var(--ob-teal2),var(--ob-teal));
  color:#04211d;border:none;cursor:pointer}
.ob-cta.ghost{background:transparent;border:1px solid rgba(255,255,255,.18);color:#cbd5e1}
.ob-done-tag{font-size:12px;font-weight:700;color:#5eead4}
.ob-foot{text-align:center;margin-top:22px;font-size:12px;color:#64748b}
.ob-foot a{color:#94a3b8}
/* Success state */
.ob-success{text-align:center;padding:20px 0}
.ob-check{width:76px;height:76px;border-radius:50%;margin:0 auto 18px;display:grid;place-items:center;
  background:linear-gradient(135deg,var(--ob-teal2),var(--ob-teal));font-size:38px;
  animation:obpop .5s cubic-bezier(.2,1.4,.4,1) both}
@keyframes obpop{0%{transform:scale(0);opacity:0}100%{transform:scale(1);opacity:1}}
.ob-big-cta{display:inline-block;margin-top:18px;font-weight:800;font-size:15px;text-decoration:none;
  padding:14px 28px;border-radius:12px;background:linear-gradient(90deg,var(--ob-teal2),var(--ob-teal));color:#04211d}
canvas.ob-confetti{position:fixed;inset:0;pointer-events:none;z-index:50}
@media(prefers-reduced-motion:reduce){.ob-check{animation:none}.ob-bar>i{transition:none}}
</style>
</head>
<body class="ob-body">
<div class="ob-wrap">
<?php if ($justDone): ?>
  <canvas class="ob-confetti" id="obConfetti"></canvas>
  <div class="ob-success">
    <div class="ob-check">🎉</div>
    <h1 class="ob-h1">Order pertama berhasil!</h1>
    <p class="ob-sub">Keren, <?= $ownerName ?: 'Kak' ?>! <?= htmlspecialchars($namaOutlet) ?> resmi jalan.<br>
       Sekarang semua fitur LaMaSy terbuka untukmu.</p>
    <a class="ob-big-cta" href="/dashboard">Masuk Dashboard →</a>
  </div>
<?php else: ?>
  <div class="ob-head">
    <span class="ob-badge">✨ Mulai Cepat · ± 5 menit</span>
    <h1 class="ob-h1">Yuk selesaikan 3 langkah ini<?= $ownerName ? ', '.$ownerName : '' ?></h1>
    <p class="ob-sub">Biar outlet <strong><?= htmlspecialchars($namaOutlet) ?></strong> langsung siap terima order.
       Selesaikan order pertama untuk membuka dashboard penuh.</p>
  </div>

  <div class="ob-progress">
    <div class="ob-bar"><i></i></div>
    <div class="ob-pct"><?= $doneCount ?>/3 selesai</div>
  </div>

  <div class="ob-steps">
    <?php
    // Langkah aktif = langkah pertama yang belum selesai
    $activeIdx = !$step1 ? 1 : (!$step3 ? 3 : 0); // step2 opsional → langsung ke 3 kalau layanan sudah ada
    $steps = [
      [1, $step1, 'Tambah 1 layanan', 'Misal “Cuci Kiloan” atau “Setrika”. Ini yang nanti dipilih saat buat order.', '/layanan.php', 'Tambah layanan'],
      [2, $step2, 'Tambah customer (opsional)', 'Simpan data pelanggan tetap. Kalau mau cepat, bisa langsung pakai “customer umum” saat buat order.', '/pelanggan.php', 'Tambah customer'],
      [3, $step3, 'Buat order pertama', 'Buka kasir, pilih layanan & customer, simpan. Inilah transaksi pertamamu!', '/pos.php', 'Buka Kasir →'],
    ];
    foreach ($steps as [$n, $done, $title, $desc, $href, $label]):
      $isActive = ($n === $activeIdx);
      $cls = $done ? 'done' : ($isActive ? 'active' : '');
    ?>
    <div class="ob-step <?= $cls ?>">
      <div class="ob-num"><?= $done ? '✓' : $n ?></div>
      <div class="ob-body-txt">
        <p class="ob-title"><?= htmlspecialchars($title) ?></p>
        <p class="ob-desc"><?= $desc ?></p>
        <?php if ($done): ?>
          <span class="ob-done-tag">✓ Selesai</span>
        <?php else: ?>
          <a class="ob-cta <?= $isActive ? '' : 'ghost' ?>" href="<?= $href ?>"><?= htmlspecialchars($label) ?></a>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <p class="ob-foot">
    Butuh bantuan? <a href="https://wa.me/6285121519302?text=Halo+Tim+LaMaSy%2C+saya+butuh+bantuan+setup+outlet+pertama." target="_blank" rel="noopener">Chat CS via WhatsApp</a>
    · <a href="/onboarding.php">Segarkan</a>
    · <a href="/logout.php">Keluar</a>
  </p>
<?php endif; ?>
</div>

<?php if ($justDone): ?>
<script>
(function(){
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  var c = document.getElementById('obConfetti'); if(!c) return;
  var ctx = c.getContext('2d'), W, H, parts = [];
  function resize(){ W=c.width=innerWidth; H=c.height=innerHeight; }
  resize(); addEventListener('resize', resize);
  var colors = ['#14b8a6','#5eead4','#3b82f6','#f59e0b','#ef4444','#ffffff'];
  for (var i=0;i<130;i++) parts.push({
    x:Math.random()*W, y:-20-Math.random()*H*.5,
    r:4+Math.random()*6, c:colors[i%colors.length],
    vy:2+Math.random()*4, vx:-1.5+Math.random()*3, rot:Math.random()*6, vr:-.2+Math.random()*.4
  });
  var t0=Date.now();
  (function frame(){
    ctx.clearRect(0,0,W,H);
    parts.forEach(function(p){
      p.y+=p.vy; p.x+=p.vx; p.rot+=p.vr;
      ctx.save(); ctx.translate(p.x,p.y); ctx.rotate(p.rot);
      ctx.fillStyle=p.c; ctx.fillRect(-p.r/2,-p.r/2,p.r,p.r*.6); ctx.restore();
      if(p.y>H+20){ p.y=-20; p.x=Math.random()*W; }
    });
    if (Date.now()-t0 < 3500) requestAnimationFrame(frame);
    else ctx.clearRect(0,0,W,H);
  })();
})();
</script>
<?php endif; ?>
</body>
</html>

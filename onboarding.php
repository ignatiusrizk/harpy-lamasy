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

// ── Preset layanan untuk quick-setup wizard (Komponen 2 · Data Accumulation) ──
// Dikelola SuperAdmin di saas_layanan_presets (Opsi D). Fallback ke hardcoded
// kalau tabel belum ada / kosong.
$LAYANAN_PRESETS = [];
try {
    $ps = $db->query("SELECT nama, satuan, kategori, default_checked FROM saas_layanan_presets
                      WHERE is_active=1 ORDER BY urutan, id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ps as $p) {
        $LAYANAN_PRESETS[] = ['nama'=>$p['nama'], 'satuan'=>$p['satuan'],
                              'kategori'=>$p['kategori'], 'checked'=>(bool)$p['default_checked']];
    }
} catch (Throwable $e) { error_log('[onboarding presets] '.$e->getMessage()); }
if (!$LAYANAN_PRESETS) {
    $LAYANAN_PRESETS = [
        ['nama'=>'Cuci Kering Lipat',    'satuan'=>'kg',     'kategori'=>'Kiloan', 'checked'=>true],
        ['nama'=>'Cuci Setrika',         'satuan'=>'kg',     'kategori'=>'Kiloan', 'checked'=>true],
        ['nama'=>'Setrika Saja',         'satuan'=>'kg',     'kategori'=>'Kiloan', 'checked'=>true],
        ['nama'=>'Cuci Express (1 hari)','satuan'=>'kg',     'kategori'=>'Kiloan', 'checked'=>false],
        ['nama'=>'Bed Cover',            'satuan'=>'pcs',    'kategori'=>'Satuan', 'checked'=>false],
        ['nama'=>'Selimut',              'satuan'=>'pcs',    'kategori'=>'Satuan', 'checked'=>false],
        ['nama'=>'Sepatu',               'satuan'=>'pasang', 'kategori'=>'Satuan', 'checked'=>false],
        ['nama'=>'Karpet',               'satuan'=>'m²',     'kategori'=>'Satuan', 'checked'=>false],
    ];
}

// ── Handler: simpan wizard layanan (min 1 layanan + harga) ──
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'save_layanan') {
    verifyCsrf();
    // Nama layanan yang sudah ada → jangan dobel
    $existing = [];
    try {
        $st = $db->prepare("SELECT LOWER(nama) FROM hl_layanan WHERE tenant_id=? AND outlet_id=?");
        $st->execute([$tid, $oid]);
        $existing = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { error_log('[onboarding wizard] '.$e->getMessage()); }

    $toInsert = [];
    foreach (($_POST['p_on'] ?? []) as $i) {
        $i = (int)$i;
        if (!isset($LAYANAN_PRESETS[$i])) continue;
        $harga = (int)($_POST['p_harga'][$i] ?? 0);
        if ($harga <= 0) continue;
        $toInsert[] = $LAYANAN_PRESETS[$i] + ['harga'=>$harga];
    }
    foreach (($_POST['c_nama'] ?? []) as $k => $nm) {
        $nm = trim(strip_tags((string)$nm));
        $harga = (int)($_POST['c_harga'][$k] ?? 0);
        if ($nm === '' || $harga <= 0) continue;
        $sat = trim(strip_tags((string)($_POST['c_satuan'][$k] ?? 'kg'))) ?: 'kg';
        $toInsert[] = ['nama'=>mb_substr($nm,0,100), 'satuan'=>mb_substr($sat,0,30), 'kategori'=>'Lainnya', 'harga'=>$harga];
    }

    if ($toInsert) {
        $ins = $db->prepare("INSERT INTO hl_layanan (tenant_id, outlet_id, nama, kategori, satuan, harga, is_active, urutan)
                             VALUES (?,?,?,?,?,?,1,?)");
        $u = 0;
        foreach ($toInsert as $r) {
            if (isset($existing[mb_strtolower($r['nama'])])) continue; // skip duplikat
            try { $ins->execute([$tid, $oid, $r['nama'], $r['kategori'], $r['satuan'], $r['harga'], $u++]); }
            catch (Throwable $e) { error_log('[onboarding wizard insert] '.$e->getMessage()); }
        }
    }
    header('Location: /onboarding.php'); // PRG — reload hitung ulang progres
    exit;
}

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
/* Wizard layanan (Komponen 2) */
.ob-wiz{margin-top:6px}
.ob-wrow{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid rgba(255,255,255,.06)}
.ob-wrow input[type=checkbox]{width:18px;height:18px;accent-color:var(--ob-teal);flex:none}
.ob-wname{flex:1;font-size:13px;color:#e6edf6}
.ob-wname small{color:#7c8ba0;font-weight:400}
.ob-wprice{display:inline-flex;align-items:center;gap:5px;font-size:12px;color:#9fb0c3}
.ob-wprice input{width:92px;padding:7px 9px;border-radius:8px;border:1px solid rgba(255,255,255,.14);
  background:rgba(255,255,255,.05);color:#fff;font-size:13px;font-family:inherit;text-align:right;outline:none}
.ob-wprice input:focus{border-color:var(--ob-teal)}
.ob-addcustom{margin-top:12px;background:none;border:1px dashed rgba(255,255,255,.2);color:#9fb0c3;
  font-size:12px;font-weight:600;padding:8px 12px;border-radius:9px;cursor:pointer;font-family:inherit}
.ob-hint{font-size:11.5px;color:#7c8ba0;text-align:center;margin:8px 0 0}
.ob-hint.err{color:#fca5a5}
.ob-step2cta{display:flex;flex-direction:column;gap:8px}
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
       Sekarang semua fitur LAMASY terbuka untukmu.</p>
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
      [1, $step1, 'Atur layanan & harga', 'Centang layanan yang kamu jual, isi harganya. Ini yang nanti dipilih saat buat order.', '/layanan.php', 'Tambah layanan'],
      [2, $step2, 'Tambah customer (opsional)', 'Punya data pelanggan lama di Excel? Import biar langsung lengkap. Atau lewati — bisa pakai “customer umum” saat buat order.', '/customer.php', 'Tambah manual'],
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
        <?php elseif ($n === 1): ?>
          <!-- Komponen 2: Wizard layanan quick-setup -->
          <form method="post" class="ob-wiz" onsubmit="return obWizCheck()">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="save_layanan">
            <?php foreach ($LAYANAN_PRESETS as $i => $p): ?>
            <label class="ob-wrow">
              <input type="checkbox" name="p_on[]" value="<?= $i ?>" <?= $p['checked'] ? 'checked' : '' ?>>
              <span class="ob-wname"><?= htmlspecialchars($p['nama']) ?> <small>/ <?= htmlspecialchars($p['satuan']) ?></small></span>
              <span class="ob-wprice">Rp <input type="number" name="p_harga[<?= $i ?>]" min="0" step="500" placeholder="0" inputmode="numeric"></span>
            </label>
            <?php endforeach; ?>
            <div id="obCustom"></div>
            <button type="button" class="ob-addcustom" onclick="obAddCustom()">+ Tambah layanan sendiri</button>
            <button type="submit" class="ob-cta" style="width:100%;margin-top:14px">Simpan &amp; lanjut →</button>
            <p class="ob-hint" id="obWizHint">Centang minimal 1 layanan &amp; isi harganya.</p>
          </form>
        <?php elseif ($n === 2): ?>
          <div class="ob-step2cta">
            <a class="ob-cta" href="/import.php?entity=pelanggan">📥 Import dari Excel/CSV</a>
            <a class="ob-cta ghost" href="<?= $href ?>"><?= htmlspecialchars($label) ?></a>
          </div>
        <?php else: ?>
          <a class="ob-cta <?= $isActive ? '' : 'ghost' ?>" href="<?= $href ?>"><?= htmlspecialchars($label) ?></a>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <p class="ob-foot">
    Butuh bantuan? <a href="https://wa.me/6285121519302?text=Halo+Tim+LAMASY%2C+saya+butuh+bantuan+setup+outlet+pertama." target="_blank" rel="noopener">Chat CS via WhatsApp</a>
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
<script>
// Wizard layanan (Komponen 2) — tambah baris custom + validasi min 1 layanan berharga
function obAddCustom(){
  var wrap=document.getElementById('obCustom'); if(!wrap) return;
  var row=document.createElement('label'); row.className='ob-wrow';
  row.innerHTML=
    '<span class="ob-wname"><input type="text" name="c_nama[]" placeholder="Nama layanan sendiri" '
    +'style="width:100%;padding:7px 9px;border-radius:8px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.05);color:#fff;font-size:13px;font-family:inherit;outline:none"></span>'
    +'<span class="ob-wprice"><select name="c_satuan[]" style="padding:7px;border-radius:8px;border:1px solid rgba(255,255,255,.14);background:#0A0F1F;color:#fff;font-size:12px;font-family:inherit">'
    +'<option value="kg">kg</option><option value="pcs">pcs</option><option value="pasang">pasang</option><option value="m²">m²</option></select> '
    +'Rp <input type="number" name="c_harga[]" min="0" step="500" placeholder="0" inputmode="numeric"></span>';
  wrap.appendChild(row);
}
function obWizCheck(){
  var ok=false;
  document.querySelectorAll('.ob-wiz input[name="p_on[]"]').forEach(function(cb){
    if(cb.checked){ var h=cb.closest('.ob-wrow').querySelector('input[name^="p_harga"]'); if(h&&parseInt(h.value||0,10)>0) ok=true; }
  });
  document.querySelectorAll('.ob-wiz input[name="c_nama[]"]').forEach(function(n){
    var h=n.closest('.ob-wrow').querySelector('input[name="c_harga[]"]');
    if(n.value.trim()&&h&&parseInt(h.value||0,10)>0) ok=true;
  });
  if(!ok){ var hint=document.getElementById('obWizHint'); if(hint){ hint.textContent='Centang minimal 1 layanan dan isi harganya (lebih dari 0).'; hint.classList.add('err'); } return false; }
  return true;
}
</script>
</body>
</html>

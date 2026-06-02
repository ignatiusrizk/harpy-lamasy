<?php
// ══════════════════════════════════════════════════════
// add-outlet.php — Wizard tambah outlet
// Outlet 1: trial 7 hari gratis, 10000 coin
// Outlet 2+: (fase berikutnya — butuh payment)
// ══════════════════════════════════════════════════════

$activePage = 'add-outlet';
define('ROOT', __DIR__);

// ── Auth check sebelum tenant_guard ───────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['tenant_id'])) {
    header('Location: /login?msg=not_logged_in');
    exit;
}

// tenant_guard memberikan: currentUser(), getCsrfToken(), Database, TenantResolver, dll
// TenantResolver sudah tolerate no-outlet untuk add-outlet.php
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/StrukGenerator.php';

$tid  = TenantResolver::id();
$user     = currentUser() ?? [];
$isHqMode = !empty($_SESSION['hq_mode']);

// Hanya owner yang boleh tambah outlet
if (($user['role'] ?? '') !== 'owner') {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:60px;text-align:center;background:#0F1C3A;color:#fff;min-height:100vh">
        <h2 style="color:#35E8D5">🔒 Akses Ditolak</h2>
        <p style="color:rgba(255,255,255,.6)">Hanya owner yang bisa menambah outlet.</p>
        <a href="/dashboard" style="color:#35E8D5">← Kembali</a>
    </div>');
}

// CSRF
function aoCsrf(): string {
    if (empty($_SESSION['ao_csrf'])) $_SESSION['ao_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['ao_csrf'];
}
function aoVerifyCsrf(): void {
    if (!hash_equals(aoCsrf(), $_POST['_csrf'] ?? '')) {
        http_response_code(403); die('CSRF mismatch.');
    }
}

// Helper
function aoSlugify(string $str): string {
    $str = strtolower(trim($str));
    $str = preg_replace('/[^a-z0-9\s-]/', '', $str);
    $str = preg_replace('/[\s-]+/', '_', $str);
    return preg_replace('/^_+|_+$/', '', $str) ?: 'outlet';
}
function aoUniqueSlug(string $base, int $tenantId): string {
    $db = Database::get();
    $slug = aoSlugify($base);
    $i = 1;
    $candidate = $tenantId . '_' . $slug;
    while (true) {
        $s = $db->prepare("SELECT id FROM outlets WHERE slug=? LIMIT 1");
        $s->execute([$candidate]);
        if (!$s->fetch()) return $candidate;
        $candidate = $tenantId . '_' . $slug . '_' . $i++;
    }
}

// Hitung outlet aktif yang sudah ada (exclude closed dan pending — pending belum dikonfirmasi)
$cntQ = Database::get()->prepare("SELECT COUNT(*) FROM outlets WHERE tenant_id=? AND status NOT IN ('closed','pending')");
$cntQ->execute([$tid]);
$outletCount = (int)$cntQ->fetchColumn();

$isFirstOutlet = $outletCount === 0;

// Outlet 2+: langsung aktivasi berbayar (tidak ada trial gratis)
// Variabel ini dipakai di wizard untuk menentukan mode default
$forcePaid = !$isFirstOutlet;

// Wizard state
if (isset($_GET['reset'])) unset($_SESSION['ao']);
if (empty($_SESSION['ao'])) {
    // Pre-fill nama_outlet & kota dari data registrasi (hanya outlet pertama)
    $prefill = [];
    if ($isFirstOutlet) {
        $db = Database::get();
        $pf = $db->prepare(
            "SELECT nama_outlet, kota FROM registration_requests WHERE tenant_id=? ORDER BY id DESC LIMIT 1"
        );
        $pf->execute([$tid]);
        $rr = $pf->fetch(PDO::FETCH_ASSOC);
        if ($rr) {
            if (!empty($rr['nama_outlet'])) $prefill['nama_outlet'] = $rr['nama_outlet'];
            if (!empty($rr['kota']))        $prefill['kota']        = $rr['kota'];
        }
    }
    $_SESSION['ao'] = ['step' => 1, 'data' => $prefill];
}
$w    = &$_SESSION['ao'];
$step = (int)($w['step'] ?? 1);
$d    = &$w['data'];
$error = '';
$success = false;

// ── Step 1 submit ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step1_submit'])) {
    aoVerifyCsrf();
    $namaOutlet = trim($_POST['nama_outlet'] ?? '');
    $kota       = trim($_POST['kota'] ?? '');
    $alamat     = trim($_POST['alamat'] ?? '');
    $telepon    = trim($_POST['telepon'] ?? '');
    $mode       = $forcePaid ? 'paid' : (($_POST['mode'] ?? 'trial') === 'paid' ? 'paid' : 'trial');

    if (strlen($namaOutlet) < 3) {
        $error = 'Nama outlet minimal 3 karakter.';
    } elseif (strlen($namaOutlet) > 80) {
        $error = 'Nama outlet maksimal 80 karakter.';
    } else {
        $d['nama_outlet'] = $namaOutlet;
        $d['kota']        = $kota;
        $d['alamat']      = $alamat;
        $d['telepon']     = $telepon;
        $d['mode']        = $mode;
        $w['step'] = 2; $step = 2;
        $_SESSION['ao_csrf'] = bin2hex(random_bytes(32));
    }
}

// ── Step 2: confirm & create ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step2_submit'])) {
    aoVerifyCsrf();

    $db = Database::get();
    $db->beginTransaction();
    try {
        $outletSlug  = aoUniqueSlug($d['nama_outlet'], $tid);
        $isPaid      = ($d['mode'] ?? 'trial') === 'paid';
        // Paid outlet = pending (menunggu konfirmasi pembayaran superadmin)
        // Trial outlet = trial (langsung aktif dengan masa trial 7 hari)
        $trialStatus = $isPaid ? 'pending' : 'trial';
        $trialEnds   = $isPaid ? null : date('Y-m-d H:i:s', time() + 7 * 86400);
        $trialCoins  = (!$isPaid && $isFirstOutlet) ? 10000 : 0;

        $db->prepare("
            INSERT INTO outlets
              (tenant_id, nama_outlet, slug, kota, alamat, telepon,
               status, trial_starts_at, trial_ends_at,
               trial_coin_balance, coin_balance, is_main, setup_done)
            VALUES (?,?,?,?,?,?,?,NOW(),?,?,0,?,0)
        ")->execute([
            $tid,
            $d['nama_outlet'],
            $outletSlug,
            $d['kota']    ?: null,
            $d['alamat']  ?: null,
            $d['telepon'] ?: null,
            $trialStatus,
            $trialEnds,
            $trialCoins,
            $isFirstOutlet ? 1 : 0,
        ]);
        $outletId = (int)$db->lastInsertId();

        // Update total_outlets di tenant
        $db->prepare(
            "UPDATE tenants SET total_outlets = total_outlets + 1 WHERE id=?"
        )->execute([$tid]);

        // Set is_main jika outlet pertama
        if ($isFirstOutlet) {
            $db->prepare("UPDATE outlets SET is_main=1 WHERE id=?")->execute([$outletId]);
        }

        // Untuk outlet berbayar: buat permintaan verifikasi pembayaran di superadmin
        if ($isPaid) {
            // Ambil data owner dari tenants
            $ownerRow = $db->prepare(
                "SELECT owner_name, owner_wa FROM tenants WHERE id=? LIMIT 1"
            );
            $ownerRow->execute([$tid]);
            $ownerRow = $ownerRow->fetch(PDO::FETCH_ASSOC) ?: [];

            $db->prepare("
                INSERT INTO registration_requests
                    (source, request_type, nama_outlet, owner_name, owner_wa, kota,
                     status, tenant_id, outlet_id, created_at)
                VALUES (?, 'add_outlet', ?, ?, ?, ?, 'payment_pending', ?, ?, NOW())
            ")->execute([
                'self_service',
                $d['nama_outlet'],
                $ownerRow['owner_name'] ?? '-',
                $ownerRow['owner_wa']   ?? '-',
                $d['kota'] ?: null,
                $tid,
                $outletId,
            ]);
        }

        $db->commit();

        // Seed default struk templates untuk outlet baru
        try {
            foreach (['retail', 'b2b'] as $_tipe) {
                StrukGenerator::saveTemplate($tid, $outletId, $_tipe,
                    StrukGenerator::defaultTemplate($_tipe)
                );
            }
        } catch (Throwable $e) {
            error_log('[add-outlet.php] Gagal seed struk template: ' . $e->getMessage());
            // Non-fatal — outlet tetap terbuat, template akan dibuat on-demand
        }

        if ($isPaid) {
            // Paid/pending outlet: JANGAN switch session ke outlet baru
            // Tenant tetap di outlet yang sedang aktif
            // (sesi tidak berubah, TenantResolver tidak perlu di-reset)
            $successMode = 'pending_payment';
        } else {
            // Trial outlet: switch ke outlet baru langsung
            $_SESSION['outlet_id'] = $outletId;
            $_SESSION['has_outlet'] = true;
            TenantResolver::reset();
            $successMode = 'trial';
        }

        $successName = $d['nama_outlet'];
        unset($_SESSION['ao']);

        $success    = true;
        $outletName = $successName;

    } catch (Throwable $e) {
        $db->rollBack();
        error_log('[add-outlet.php] Error: ' . $e->getMessage());
        $error = 'Terjadi kesalahan teknis. Coba lagi atau hubungi support.';
    }
}

// Back
if (isset($_POST['go_back'])) {
    $w['step'] = max(1, $step - 1);
    $step = $w['step'];
    $_SESSION['ao_csrf'] = bin2hex(random_bytes(32));
}

$csrf = aoCsrf();

require_once __DIR__ . '/components.php';

if ($isHqMode) {
    // ── HQ mode: gunakan HQ sidebar + topbar ──────────────
    $tenantSt = Database::get()->prepare("SELECT * FROM tenants WHERE id=? LIMIT 1");
    $tenantSt->execute([$tid]);
    $hqTenant    = $tenantSt->fetch(PDO::FETCH_ASSOC) ?: [];
    $hqUser      = $user;
    $hqIsOwner   = ($user['role'] ?? '') === 'owner';
    $hqIsManager = ($user['role'] ?? '') === 'manager';
    $pageTitle   = 'Tambah Outlet';
    $activePage  = 'hq-outlet';
    require __DIR__ . '/hq/_layout_open.php';
} else {
    // ── Standalone mode: minimal outlet shell ─────────────
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
    <?php renderHead('Tambah Outlet'); ?>
<?php } ?>
<style>
.ao-wrap {
  max-width: 560px;
  margin: 0 auto;
  padding: 32px 16px 60px;
}
.ao-brand {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 32px;
}
.ao-brand a { text-decoration:none; color: var(--gray); font-size:13px; }
.ao-brand a:hover { color: var(--navy); }
.stepper {
  display: flex; align-items: center;
  margin-bottom: 28px;
}
.step-item {
  display: flex; flex-direction: column; align-items: center;
  flex: 1; position: relative;
}
.step-item:not(:last-child)::after {
  content: ''; position: absolute; top: 16px; left: 50%;
  width: 100%; height: 2px; background: rgba(27,45,90,.12); z-index: 0;
}
.step-item.done:not(:last-child)::after { background: var(--teal); }
.step-dot {
  width: 32px; height: 32px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; font-weight: 700; position: relative; z-index: 1;
  background: #f1f5f9; border: 2px solid rgba(27,45,90,.12); color: var(--gray);
  transition: all .3s;
}
.step-item.active .step-dot { background: var(--teal); color: var(--navy); border-color: var(--teal); }
.step-item.done   .step-dot { background: var(--teal-bg); color: var(--teal-d); border-color: var(--teal); }
.step-label { font-size: 11px; margin-top: 6px; color: var(--gray); text-align: center; }
.step-item.active .step-label { color: var(--teal-d); font-weight: 600; }
.ao-card {
  background: var(--white); border-radius: var(--r-lg);
  border: 1px solid rgba(27,45,90,.08);
  box-shadow: var(--shadow); padding: 32px;
}
.ao-title { font-size: 1.2rem; font-weight: 800; color: var(--navy); margin-bottom: 6px; }
.ao-sub { font-size: 14px; color: var(--gray); margin-bottom: 24px; line-height: 1.5; }
.field { margin-bottom: 18px; }
.field label { display:block; font-size:13px; font-weight:600; margin-bottom:6px; color:var(--dark); }
.req { color: var(--teal); } .opt { color: var(--gray); font-weight:400; font-size:12px; }
.field input, .field textarea {
  width: 100%; padding: 10px 14px;
  border: 1.5px solid rgba(27,45,90,.12); border-radius: 8px;
  font-size: 14px; font-family: inherit; color: var(--dark);
  transition: border-color .2s; outline: none; background: #fff;
}
.field input:focus, .field textarea:focus { border-color: var(--teal); }
.field textarea { resize: vertical; min-height: 72px; }
.hint { font-size: 12px; color: var(--gray); margin-top: 5px; }
.alert-error {
  background: #FEF2F2; border: 1px solid #FECACA;
  color: #991B1B; padding: 12px 16px; border-radius: 8px;
  font-size: 14px; margin-bottom: 20px;
}
.btn-row { display: flex; gap: 10px; margin-top: 24px; }
.review-row {
  display: flex; justify-content: space-between; align-items: center;
  padding: 10px 0; border-bottom: 1px solid rgba(27,45,90,.07);
  font-size: 14px;
}
.review-row:last-child { border-bottom: none; }
.rv-label { color: var(--gray); } .rv-val { font-weight: 600; text-align: right; }
.trial-box {
  background: var(--teal-bg); border: 1.5px solid rgba(53,232,213,.25);
  border-radius: 10px; padding: 16px; margin-top: 20px;
}
.trial-box .trial-title { font-size: 13px; font-weight: 700; color: var(--teal-d); margin-bottom: 8px; }
.trial-box ul { font-size: 13px; color: var(--dark); padding-left: 18px; line-height: 1.9; }

/* Success screen */
.ao-success {
  text-align: center; padding: 40px 20px;
}
.ao-success .big-icon { font-size: 72px; margin-bottom: 16px; }
.ao-success h1 { font-size: 1.5rem; font-weight: 800; color: var(--navy); margin-bottom: 10px; }
.ao-success p  { font-size: 15px; color: var(--gray); margin-bottom: 28px; line-height: 1.6; }

/* Mode selector */
.mode-card {
  display: flex; align-items: flex-start; gap: 12px;
  border: 2px solid rgba(27,45,90,.12); border-radius: 10px;
  padding: 14px 16px; margin-bottom: 10px; cursor: pointer;
  transition: border-color .15s, background .15s;
}
.mode-card input[type=radio] { margin-top: 3px; accent-color: var(--teal); flex-shrink: 0; }
.mode-card.selected { border-color: var(--teal); background: var(--teal-bg); }
.mode-title { font-size: 14px; font-weight: 700; color: var(--navy); margin-bottom: 4px; }
.mode-desc  { font-size: 12px; color: var(--gray); line-height: 1.5; }
</style>
<?php if (!$isHqMode): ?>
</head>
<body>
<?php
    // Standalone: minimal mode (tanpa sidebar nav) ─ wizard tidak butuh nav lengkap
    renderTopbar('add-outlet', true);
?>
<?php endif; ?>
<div class="ao-wrap">

  <?php if ($success): ?>
    <!-- ══ SUCCESS ══ -->
    <div class="hl-card">
      <div class="ao-success">
        <?php if (($successMode ?? 'trial') === 'pending_payment'): ?>
          <!-- Outlet berbayar: menunggu konfirmasi pembayaran superadmin -->
          <div class="big-icon">⏳</div>
          <h1>Outlet Menunggu Konfirmasi</h1>
          <p>
            <strong><?= htmlspecialchars($outletName ?? '') ?></strong> berhasil didaftarkan.<br>
            Outlet akan aktif setelah tim LAMASY mengkonfirmasi pembayaran setup fee kamu.
          </p>
          <div style="background:#FEF3C7;border:1px solid #FCD34D;color:#92400E;
                      padding:14px 16px;border-radius:10px;font-size:13px;
                      margin-bottom:24px;text-align:left">
            <div style="font-weight:700;margin-bottom:8px">💳 Langkah selanjutnya:</div>
            <ol style="margin:0;padding-left:18px;line-height:2">
              <li>Hubungi tim LAMASY via WhatsApp di bawah</li>
              <li>Transfer setup fee sesuai paket yang dipilih</li>
              <li>Tim kami akan konfirmasi &amp; aktifkan outlet dalam 1×24 jam</li>
            </ol>
          </div>
          <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
            <a href="https://wa.me/6285121519302?text=<?= urlencode('Halo Tim LAMASY, saya baru mendaftarkan outlet baru bernama "' . ($outletName ?? '') . '" dan ingin melakukan pembayaran setup fee. Mohon info rekening / prosedurnya. Terima kasih.') ?>"
               target="_blank" rel="noopener"
               class="hl-btn hl-btn-primary" style="padding:13px 28px;background:#25D366;border-color:#25D366">
              💬 Hubungi LAMASY via WA
            </a>
            <a href="/dashboard" class="hl-btn hl-btn-outline" style="padding:13px 24px">
              Kembali ke Dashboard
            </a>
          </div>
        <?php else: ?>
          <div class="big-icon">🎉</div>
          <h1>Outlet Berhasil Ditambahkan!</h1>
          <p>
            <strong><?= htmlspecialchars($outletName ?? '') ?></strong> sudah aktif dan siap digunakan.<br>
            Kamu mendapat <strong>10.000 coin trial</strong> gratis untuk 7 hari ke depan.
          </p>
          <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
            <a href="/dashboard" class="hl-btn hl-btn-primary" style="padding:13px 32px">
              🚀 Mulai Kelola Laundry
            </a>
            <a href="/layanan" class="hl-btn hl-btn-outline" style="padding:13px 24px">
              Atur Layanan & Harga
            </a>
          </div>
        <?php endif; ?>
      </div>
    </div>

  <?php else: ?>

    <!-- Breadcrumb -->
    <div class="ao-brand">
      <a href="/dashboard">← Dashboard</a>
      <span style="color:rgba(27,45,90,.2)">/</span>
      <span style="font-size:13px;font-weight:600;color:var(--navy)">Tambah Outlet</span>
    </div>

    <!-- Stepper -->
    <div class="stepper">
      <?php
      $stepsMeta = [1=>'Info Outlet', 2=>'Konfirmasi'];
      foreach ($stepsMeta as $sn => $sl):
        $cls = $sn < $step ? 'done' : ($sn === $step ? 'active' : '');
        $dot = $sn < $step ? '✓' : $sn;
      ?>
        <div class="step-item <?= $cls ?>">
          <div class="step-dot"><?= $dot ?></div>
          <div class="step-label"><?= $sl ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Card -->
    <div class="hl-card ao-card">

      <?php if ($error): ?>
        <div class="alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <?php // ═══ STEP 1 ═════════════════════════════════
      if ($step === 1): ?>

        <div class="ao-title">
          <?= $isFirstOutlet ? '🏪 Outlet Pertama Kamu' : '➕ Tambah Outlet Baru' ?>
        </div>
        <p class="ao-sub">
          <?= $isFirstOutlet
            ? 'Lengkapi info outlet untuk mulai menggunakan LAMASY.'
            : 'Outlet baru akan mulai dengan periode trial.' ?>
        </p>

        <form method="POST" autocomplete="off">
          <input type="hidden" name="_csrf" value="<?= $csrf ?>">
          <div class="field">
            <label>Nama Outlet <span class="req">*</span></label>
            <input type="text" name="nama_outlet" required maxlength="80"
                   value="<?= htmlspecialchars($d['nama_outlet'] ?? '') ?>"
                   placeholder="cth: Laundry Bersih Jaya – Cabang Utama"
                   autofocus>
            <?php if ($isFirstOutlet && !empty($d['nama_outlet'])): ?>
            <div class="hint">Diisi otomatis dari pendaftaran. Bisa kamu ubah jika perlu.</div>
            <?php endif; ?>
          </div>
          <div class="field">
            <label>Kota <span class="opt">(opsional)</span></label>
            <input type="text" name="kota" maxlength="100"
                   value="<?= htmlspecialchars($d['kota'] ?? '') ?>"
                   placeholder="cth: Bandung">
          </div>
          <div class="field">
            <label>Alamat <span class="opt">(opsional)</span></label>
            <textarea name="alamat" rows="2" maxlength="300"
                      placeholder="Jl. Contoh No. 1, Kel. ..."><?= htmlspecialchars($d['alamat'] ?? '') ?></textarea>
          </div>
          <div class="field">
            <label>Nomor Telepon Outlet <span class="opt">(opsional)</span></label>
            <input type="tel" name="telepon" maxlength="20"
                   value="<?= htmlspecialchars($d['telepon'] ?? '') ?>"
                   placeholder="cth: 022-1234567">
            <div class="hint">Untuk keperluan nota & profil outlet</div>
          </div>

          <?php if ($isFirstOutlet): ?>
          <!-- Mode pilihan: trial vs langsung aktivasi (hanya outlet pertama) -->
          <div style="margin-top:20px">
            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:10px;color:var(--dark)">Pilih mode aktivasi</label>
            <label class="mode-card <?= ($d['mode'] ?? 'trial') !== 'paid' ? 'selected' : '' ?>" id="cardTrial">
              <input type="radio" name="mode" value="trial"
                     <?= ($d['mode'] ?? 'trial') !== 'paid' ? 'checked' : '' ?>
                     onchange="switchMode(this.value)">
              <div class="mode-body">
                <div class="mode-title">🎁 Trial 7 Hari — Gratis</div>
                <div class="mode-desc">Coba semua fitur selama 7 hari tanpa biaya. Dapat 10.000 coin gratis.</div>
              </div>
            </label>
            <label class="mode-card <?= ($d['mode'] ?? '') === 'paid' ? 'selected' : '' ?>" id="cardPaid">
              <input type="radio" name="mode" value="paid"
                     <?= ($d['mode'] ?? '') === 'paid' ? 'checked' : '' ?>
                     onchange="switchMode(this.value)">
              <div class="mode-body">
                <div class="mode-title">⚡ Aktivasi Langsung</div>
                <div class="mode-desc">Outlet langsung aktif tanpa trial. Setup fee berlaku sesuai paket.</div>
              </div>
            </label>
          </div>
          <?php else: ?>
          <div style="background:#FEF3C7;border:1px solid #FCD34D;color:#92400E;
                      padding:12px 16px;border-radius:10px;font-size:13px;margin-top:20px">
            ⚡ <strong>Aktivasi Langsung</strong> — outlet ke-<?= $outletCount + 1 ?> tidak bisa trial.
            Setup fee berlaku sesuai paket yang kamu gunakan.
          </div>
          <input type="hidden" name="mode" value="paid">
          <?php endif; ?>

          <div class="btn-row">
            <a href="/dashboard" class="hl-btn hl-btn-outline">Batal</a>
            <button type="submit" name="step1_submit" class="hl-btn hl-btn-primary" style="flex:1">
              Lanjut →
            </button>
          </div>
        </form>

      <?php // ═══ STEP 2: Review & Confirm ════════════════
      elseif ($step === 2): ?>

        <div class="ao-title">✅ Konfirmasi Outlet</div>
        <p class="ao-sub">Periksa kembali sebelum outlet dibuat.</p>

        <div style="margin-bottom:20px">
          <div class="review-row">
            <span class="rv-label">Nama Outlet</span>
            <span class="rv-val"><?= htmlspecialchars($d['nama_outlet'] ?? '-') ?></span>
          </div>
          <?php if (!empty($d['kota'])): ?>
          <div class="review-row">
            <span class="rv-label">Kota</span>
            <span class="rv-val"><?= htmlspecialchars($d['kota']) ?></span>
          </div>
          <?php endif; ?>
          <?php if (!empty($d['alamat'])): ?>
          <div class="review-row">
            <span class="rv-label">Alamat</span>
            <span class="rv-val" style="max-width:65%;text-align:right"><?= htmlspecialchars($d['alamat']) ?></span>
          </div>
          <?php endif; ?>
          <?php if (!empty($d['telepon'])): ?>
          <div class="review-row">
            <span class="rv-label">Telepon</span>
            <span class="rv-val"><?= htmlspecialchars($d['telepon']) ?></span>
          </div>
          <?php endif; ?>
          <div class="review-row">
            <span class="rv-label">Mode Aktivasi</span>
            <?php if (($d['mode'] ?? 'trial') === 'paid'): ?>
              <span class="rv-val" style="color:#F59E0B">⚡ Aktivasi Langsung</span>
            <?php else: ?>
              <span class="rv-val" style="color:var(--teal-d)">🎁 Trial 7 Hari + 10.000 Coin</span>
            <?php endif; ?>
          </div>
          <div class="review-row">
            <span class="rv-label">Biaya</span>
            <?php if (($d['mode'] ?? 'trial') === 'paid'): ?>
              <span class="rv-val" style="color:#DC2626">Setup fee (sesuai paket)</span>
            <?php else: ?>
              <span class="rv-val" style="color:var(--green)">Gratis</span>
            <?php endif; ?>
          </div>
        </div>

        <form method="POST">
          <input type="hidden" name="_csrf" value="<?= $csrf ?>">
          <div class="btn-row">
            <button type="submit" name="go_back" class="hl-btn hl-btn-outline">← Kembali</button>
            <button type="submit" name="step2_submit" class="hl-btn hl-btn-primary" style="flex:1">
              🏪 Buat Outlet
            </button>
          </div>
        </form>

      <?php endif; ?>

    </div><!-- /ao-card -->

  <?php endif; // success ?>

</div><!-- /ao-wrap -->

<script>
function switchMode(val) {
  document.getElementById('cardTrial').classList.toggle('selected', val === 'trial');
  document.getElementById('cardPaid').classList.toggle('selected', val === 'paid');
}
</script>
<?php if ($isHqMode):
    require __DIR__ . '/hq/_layout_close.php';
else: ?>
<?php renderToast(); ?>
</body>
</html>
<?php endif; ?>

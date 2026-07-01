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
require_once ROOT . '/core/BillingConfig.php';
require_once ROOT . '/core/WelcomeKit.php';
require_once ROOT . '/add-outlet-validate.php';

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
    $alamat     = trim($_POST['alamat'] ?? '');
    $telepon    = trim($_POST['telepon'] ?? '');
    $penerima   = trim($_POST['penerima'] ?? '');
    $kodePos    = trim($_POST['kode_pos'] ?? '');
    $mode       = $forcePaid ? 'paid' : (($_POST['mode'] ?? 'trial') === 'paid' ? 'paid' : 'trial');

    $addrErr = aoValidateAddress($_POST);
    $wil = empty($addrErr) ? aoResolveWilayah(Database::get(), $_POST) : null;
    if (empty($addrErr) && $wil === null) {
        $addrErr[] = 'Wilayah tidak valid — pilih Provinsi→Kota→Kecamatan→Kelurahan dengan benar.';
    }
    if (strlen($namaOutlet) < 3) {
        $error = 'Nama outlet minimal 3 karakter.';
    } elseif (strlen($namaOutlet) > 80) {
        $error = 'Nama outlet maksimal 80 karakter.';
    } elseif (!empty($addrErr)) {
        $error = implode(' ', $addrErr);
    } else {
        $d['nama_outlet'] = $namaOutlet;
        $d['provinsi']    = $wil['provinsi'];
        $d['kota']        = $wil['kota'];
        $d['kecamatan']   = $wil['kecamatan'];
        $d['kelurahan']   = $wil['kelurahan'];
        $d['wilayah_kode']= $wil['wilayah_kode'];
        $d['alamat']      = $alamat;
        $d['telepon']     = $telepon;
        $d['penerima']    = $penerima;
        $d['kode_pos']    = $kodePos;
        $d['mode']        = $mode;
        // simpan kode utk restore dropdown saat balik ke step 1
        $d['w_prov']=$_POST['w_prov']; $d['w_kota']=$_POST['w_kota'];
        $d['w_kec']=$_POST['w_kec'];   $d['w_kel']=$_POST['w_kel'];
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
              (tenant_id, nama_outlet, slug, kota, provinsi, kecamatan, kelurahan, wilayah_kode,
               alamat, telepon, penerima, kode_pos,
               status, trial_starts_at, trial_ends_at,
               trial_coin_balance, coin_balance, is_main, setup_done)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?,0,?,0)
        ")->execute([
            $tid,
            $d['nama_outlet'],
            $outletSlug,
            $d['kota']         ?: null,
            $d['provinsi']     ?? null,
            $d['kecamatan']    ?? null,
            $d['kelurahan']    ?? null,
            $d['wilayah_kode'] ?? null,
            $d['alamat']       ?: null,
            $d['telepon']      ?: null,
            $d['penerima']     ?? null,
            $d['kode_pos']     ?? null,
            $trialStatus,
            $trialEnds,
            $trialCoins,
            $isFirstOutlet ? 1 : 0,
        ]);
        $outletId = (int)$db->lastInsertId();

        // Simpan pilihan welcome kit (server-validasi key; else default)
        if (($d['mode'] ?? 'trial') === 'paid' && WelcomeKit::enabled()) {
            $choiceKey = WelcomeKit::resolveChoiceKey($_POST['welcome_kit_choice'] ?? null);
            if ($choiceKey !== null) {
                $db->prepare("UPDATE outlets SET welcome_kit_choice=? WHERE id=?")->execute([$choiceKey, $outletId]);
            }
        }

        // Seed default payment methods (cash/transfer/qris) untuk outlet baru
        try {
            require_once ROOT . '/core/TenantProvisioner.php';
            TenantProvisioner::seedPaymentMethods($db, $tid, $outletId);
        } catch (Throwable $e) {
            error_log('seedPaymentMethods failed for outlet ' . $outletId . ': ' . $e->getMessage());
            // Non-fatal: migration backfill or first POS access will compensate
        }

        // Auto-set nota_prefix dari nama outlet (kalau kolom sudah ada)
        try {
            require_once ROOT . '/core/NotaFormatter.php';
            $autoPrefix = NotaFormatter::generatePrefixFromName($d['nama_outlet']);
            $db->prepare(
                "UPDATE outlets SET nota_prefix=?, nota_format='{PREFIX}{YYMMDD}-{COUNTER:3}' WHERE id=?"
            )->execute([$autoPrefix, $outletId]);
        } catch (Throwable) { /* kolom belum ada → skip, pakai default 'HL-' */ }

        // Update total_outlets di tenant
        $db->prepare(
            "UPDATE tenants SET total_outlets = total_outlets + 1 WHERE id=?"
        )->execute([$tid]);

        // Notify super admin: outlet activated (paid = revenue captured, trial = signup)
        try {
            require_once ROOT . '/core/SaNotifier.php';
            SaNotifier::outletActivated($outletId, $isPaid);
        } catch (Throwable $e) { error_log('[SaNotify outletActivated] ' . $e->getMessage()); }

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

        // Seed default bahan baku inventori
        try {
            require_once ROOT . '/core/TenantProvisioner.php';
            TenantProvisioner::seedDefaultBahan($db, $tid, $outletId);
        } catch (Throwable $e) {
            error_log('[add-outlet.php] Gagal seed bahan default: ' . $e->getMessage());
        }

        if ($isPaid) {
            // Paid/pending outlet: JANGAN switch session ke outlet baru
            // Tenant tetap di outlet yang sedang aktif
            // (sesi tidak berubah, TenantResolver tidak perlu di-reset)
            // Redirect ke billing-checkout untuk pembayaran outlet activation
            unset($_SESSION['ao']);
            header('Location: /billing-checkout.php?type=outlet_activation&outlet_id=' . $outletId);
            exit;
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
    $hqIsOwner   = in_array($user['role'] ?? '', ['owner','superadmin'], true);
    $hqIsManager = !$hqIsOwner && TenantResolver::canAccessHqV2();
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
.field input, .field textarea, .field select {
  width: 100%; padding: 10px 14px;
  border: 1.5px solid rgba(27,45,90,.12); border-radius: 8px;
  font-size: 14px; font-family: inherit; color: var(--dark);
  transition: border-color .2s, box-shadow .2s; outline: none; background: #fff;
}
.field input:focus, .field textarea:focus, .field select:focus {
  border-color: var(--teal); box-shadow: 0 0 0 3px rgba(53,232,213,.18);
}
.field textarea { resize: vertical; min-height: 72px; }
/* Dropdown wilayah — chevron kustom + rapi seragam dgn input */
.field select {
  -webkit-appearance: none; appearance: none; cursor: pointer;
  padding-right: 40px; line-height: 1.3;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%231CC4B2' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 14px center;
}
.field select:disabled {
  background-color: #F3F4F6; color: #9CA3AF; cursor: not-allowed; opacity: 1;
  border-color: rgba(27,45,90,.08);
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23CBD5E1' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
}
/* Combobox wilayah ber-search */
.combo { position: relative; }
.combo-input {
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%231CC4B2' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 14px center; padding-right: 40px;
}
.combo-input:disabled { background-color: #F3F4F6; color: #9CA3AF; cursor: not-allowed; border-color: rgba(27,45,90,.08); background-image: none; }
.combo-list {
  display: none; position: absolute; left: 0; right: 0; top: calc(100% + 4px); z-index: 60;
  background: #fff; border: 1.5px solid var(--teal); border-radius: 8px;
  box-shadow: 0 10px 28px rgba(15,28,58,.16); max-height: 260px; overflow-y: auto;
  -webkit-overflow-scrolling: touch;
}
.combo-list.open { display: block; }
.combo-opt { padding: 10px 14px; font-size: 14px; color: var(--dark); cursor: pointer; border-bottom: 1px solid #F3F4F6; }
.combo-opt:last-child { border-bottom: none; }
.combo-opt:hover, .combo-opt.active { background: var(--teal-bg); color: var(--teal-d); }
.combo-empty { padding: 11px 14px; font-size: 13px; color: var(--gray); }
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
          <?php
          $wkStatus = isset($outletId) ? WelcomeKit::statusForOutlet($outletId) : null;
          if ($wkStatus): ?>
          <div style="background:#F0FDFA;border:1px solid #99F6E4;border-radius:10px;
                      padding:12px 16px;font-size:13px;text-align:left;margin-bottom:20px">
            <div style="font-weight:700;color:#0F766E;margin-bottom:4px">🎁 Status Welcome Kit</div>
            <?php if ($wkStatus['status'] === 'shipped'): ?>
              Dikirim via <?= htmlspecialchars($wkStatus['kurir'] ?? '') ?>, resi <?= htmlspecialchars($wkStatus['resi'] ?? '') ?>
            <?php elseif ($wkStatus['status'] === 'delivered'): ?>
              <span style="color:#065F46;font-weight:600">Terkirim ✓</span>
            <?php else: ?>
              Welcome kit sedang disiapkan
            <?php endif; ?>
          </div>
          <?php endif; ?>
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
          <?php
          $wkStatus = isset($outletId) ? WelcomeKit::statusForOutlet($outletId) : null;
          if ($wkStatus): ?>
          <div style="background:#F0FDFA;border:1px solid #99F6E4;border-radius:10px;
                      padding:12px 16px;font-size:13px;text-align:left;margin-bottom:20px">
            <div style="font-weight:700;color:#0F766E;margin-bottom:4px">🎁 Status Welcome Kit</div>
            <?php if ($wkStatus['status'] === 'shipped'): ?>
              Dikirim via <?= htmlspecialchars($wkStatus['kurir'] ?? '') ?>, resi <?= htmlspecialchars($wkStatus['resi'] ?? '') ?>
            <?php elseif ($wkStatus['status'] === 'delivered'): ?>
              <span style="color:#065F46;font-weight:600">Terkirim ✓</span>
            <?php else: ?>
              Welcome kit sedang disiapkan
            <?php endif; ?>
          </div>
          <?php endif; ?>
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

      <?php
        // Fee/diskon/bonus-coin aktivasi — dihitung SEBELUM cabang step
        // agar tersedia baik di STEP 1 maupun STEP 2 (konfirmasi).
        $ao_fee  = BillingConfig::getInt('outlet_activation_fee', 800000);
        $ao_disc = max(0, min(100, BillingConfig::getInt('outlet_activation_discount', 0)));
        $ao_net  = (int)round($ao_fee * (1 - $ao_disc / 100));
        $ao_coin = max(0, BillingConfig::getInt('outlet_activation_coin', 100000));
      ?>
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
            <label>Nama Penerima <span class="req">*</span></label>
            <input type="text" name="penerima" required maxlength="120"
                   value="<?= htmlspecialchars($d['penerima'] ?? '') ?>"
                   placeholder="cth: Budi (PIC outlet)">
            <div class="hint">Nama penerima untuk pengiriman welcome kit</div>
          </div>
          <div class="field">
            <label>No. HP Penerima <span class="req">*</span></label>
            <input type="tel" name="telepon" required maxlength="20"
                   value="<?= htmlspecialchars($d['telepon'] ?? '') ?>"
                   placeholder="cth: 08123456789">
            <div class="hint">Minimal 8 digit — untuk keperluan nota, profil outlet &amp; pengiriman</div>
          </div>
          <div class="field">
            <label>Provinsi <span class="req">*</span></label>
            <div class="combo" id="cb_prov">
              <input type="text" class="combo-input" autocomplete="off" placeholder="Ketik / pilih provinsi…"
                     value="<?= htmlspecialchars($d['provinsi'] ?? '') ?>">
              <input type="hidden" name="w_prov" value="<?= htmlspecialchars($d['w_prov'] ?? '') ?>">
              <div class="combo-list"></div>
            </div>
          </div>
          <div class="field">
            <label>Kota / Kabupaten <span class="req">*</span></label>
            <div class="combo" id="cb_kota">
              <input type="text" class="combo-input" autocomplete="off" placeholder="Pilih provinsi dulu"
                     value="<?= htmlspecialchars($d['kota'] ?? '') ?>">
              <input type="hidden" name="w_kota" value="<?= htmlspecialchars($d['w_kota'] ?? '') ?>">
              <div class="combo-list"></div>
            </div>
          </div>
          <div class="field">
            <label>Kecamatan <span class="req">*</span></label>
            <div class="combo" id="cb_kec">
              <input type="text" class="combo-input" autocomplete="off" placeholder="Pilih kota dulu"
                     value="<?= htmlspecialchars($d['kecamatan'] ?? '') ?>">
              <input type="hidden" name="w_kec" value="<?= htmlspecialchars($d['w_kec'] ?? '') ?>">
              <div class="combo-list"></div>
            </div>
          </div>
          <div class="field">
            <label>Kelurahan / Desa <span class="req">*</span></label>
            <div class="combo" id="cb_kel">
              <input type="text" class="combo-input" autocomplete="off" placeholder="Pilih kecamatan dulu"
                     value="<?= htmlspecialchars($d['kelurahan'] ?? '') ?>">
              <input type="hidden" name="w_kel" value="<?= htmlspecialchars($d['w_kel'] ?? '') ?>">
              <div class="combo-list"></div>
            </div>
          </div>
          <div class="field">
            <label>Alamat jalan (No, RT/RW) <span class="req">*</span></label>
            <textarea name="alamat" rows="2" maxlength="300" required
                      placeholder="cth: Jl. Merdeka No. 5 RT 01 RW 02"><?= htmlspecialchars($d['alamat'] ?? '') ?></textarea>
          </div>
          <div class="field">
            <label>Kode Pos <span class="req">*</span></label>
            <input type="text" name="kode_pos" id="kode_pos" required maxlength="5"
                   inputmode="numeric" pattern="\d{5}"
                   value="<?= htmlspecialchars($d['kode_pos'] ?? '') ?>"
                   placeholder="terisi otomatis dari kelurahan">
            <div class="hint">Terisi otomatis saat pilih kelurahan — bisa diedit bila perlu.</div>
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

<script>
// Combobox wilayah ber-search (ketik untuk cari) — pengganti native <select>
// yang memuntahkan ratusan opsi. Kirim ke server tetap kode (hidden w_*).
(function(){
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  const posEl = document.getElementById('kode_pos');
  if (!document.getElementById('cb_prov')) return;

  function Combo(id, phEnabled, phDisabled){
    const el = document.getElementById(id);
    this.el = el;
    this.input  = el.querySelector('.combo-input');
    this.hidden = el.querySelector('input[type=hidden]');
    this.list   = el.querySelector('.combo-list');
    this.phEnabled = phEnabled; this.phDisabled = phDisabled;
    this.items = []; this.loaded = false; this.child = null; this.parent = null; this.isRoot = false;
  }
  Combo.prototype.parentCode = function(){ return this.isRoot ? '' : (this.parent ? this.parent.hidden.value : ''); };
  Combo.prototype.enabled = function(){ return this.isRoot || this.parentCode() !== ''; };
  Combo.prototype.syncDisabled = function(){
    const en = this.enabled();
    this.input.disabled = !en;
    this.input.placeholder = en ? this.phEnabled : this.phDisabled;
  };
  Combo.prototype.load = async function(){
    const pc = this.parentCode();
    if (!this.isRoot && pc === '') { this.items = []; this.loaded = false; return; }
    try {
      const url = '/api/wilayah.php' + (pc ? ('?parent=' + encodeURIComponent(pc)) : '');
      const r = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const j = await r.json();
      this.items = (j && j.ok && j.data) ? j.data : [];
      this.loaded = true;
    } catch(e){ this.items = []; this.loaded = false; }
  };
  Combo.prototype.render = function(){
    const q = this.input.value.trim().toLowerCase();
    if (!this.loaded){ this.list.innerHTML = '<div class="combo-empty">⏳ memuat…</div>'; }
    else {
      const m = this.items.filter(o => o.nama.toLowerCase().indexOf(q) !== -1).slice(0, 300);
      this.list.innerHTML = m.length
        ? m.map(o => '<div class="combo-opt" data-kode="'+esc(o.kode)+'"'+(o.kodepos?' data-pos="'+esc(o.kodepos)+'"':'')+'>'+esc(o.nama)+'</div>').join('')
        : '<div class="combo-empty">Tak ada hasil</div>';
    }
    this.list.classList.add('open');
  };
  Combo.prototype.close = function(){ this.list.classList.remove('open'); };
  Combo.prototype.open = async function(){
    if (!this.enabled()) return;
    if (!this.loaded){ this.render(); await this.load(); }
    this.render();
  };
  Combo.prototype.pick = function(kode, nama, pos){
    this.hidden.value = kode; this.input.value = nama; this.close();
    if (this.child) this.child.reset();
    if (pos && posEl) posEl.value = pos;        // level kelurahan → auto kode pos
  };
  Combo.prototype.reset = function(){
    this.hidden.value = ''; this.input.value = ''; this.items = []; this.loaded = false;
    this.close(); this.syncDisabled();
    if (this.child) this.child.reset();
  };
  Combo.prototype.bind = function(){
    const self = this;
    this.input.addEventListener('focus', function(){ self.open(); });
    this.input.addEventListener('input', function(){
      self.hidden.value = '';                    // teks berubah → pilihan batal sampai re-pick
      if (self.child) self.child.reset();
      self.open();
    });
    this.list.addEventListener('mousedown', function(e){   // mousedown: sebelum blur
      const opt = e.target.closest('.combo-opt');
      if (!opt) return;
      e.preventDefault();
      self.pick(opt.getAttribute('data-kode'), opt.textContent, opt.getAttribute('data-pos'));
      if (self.child && self.child.input) self.child.input.focus();
    });
    this.input.addEventListener('blur', function(){
      setTimeout(function(){
        self.close();
        if (self.input.value.trim() && !self.hidden.value){
          const m = self.items.find(o => o.nama.trim().toLowerCase() === self.input.value.trim().toLowerCase());
          if (m) self.pick(m.kode, m.nama, m.kodepos);
          else self.input.value = '';           // tak ada yang dipilih → kosongkan biar tak menyesatkan
        }
      }, 160);
    });
  };

  const prov = new Combo('cb_prov', 'Ketik / pilih provinsi…', '');
  const kota = new Combo('cb_kota', 'Ketik / pilih kota/kabupaten…', 'Pilih provinsi dulu');
  const kec  = new Combo('cb_kec',  'Ketik / pilih kecamatan…',     'Pilih kota dulu');
  const kel  = new Combo('cb_kel',  'Ketik / pilih kelurahan/desa…', 'Pilih kecamatan dulu');
  prov.isRoot = true;
  prov.child = kota; kota.parent = prov;
  kota.child = kec;  kec.parent  = kota;
  kec.child  = kel;  kel.parent  = kec;
  [prov,kota,kec,kel].forEach(function(c){ c.bind(); c.syncDisabled(); });

  document.addEventListener('click', function(e){
    [prov,kota,kec,kel].forEach(function(c){ if (!c.el.contains(e.target)) c.close(); });
  });
})();
})();
</script>

      <?php // ═══ STEP 2: Review & Confirm ════════════════
      elseif ($step === 2): ?>

        <div class="ao-title">✅ Konfirmasi Outlet</div>
        <p class="ao-sub">Periksa kembali sebelum outlet dibuat.</p>

        <div style="margin-bottom:20px">
          <div class="review-row">
            <span class="rv-label">Nama Outlet</span>
            <span class="rv-val"><?= htmlspecialchars($d['nama_outlet'] ?? '-') ?></span>
          </div>
          <div class="review-row">
            <span class="rv-label">Provinsi</span>
            <span class="rv-val"><?= htmlspecialchars($d['provinsi'] ?? '-') ?></span>
          </div>
          <div class="review-row">
            <span class="rv-label">Kota / Kabupaten</span>
            <span class="rv-val"><?= htmlspecialchars($d['kota'] ?? '-') ?></span>
          </div>
          <div class="review-row">
            <span class="rv-label">Kecamatan</span>
            <span class="rv-val"><?= htmlspecialchars($d['kecamatan'] ?? '-') ?></span>
          </div>
          <div class="review-row">
            <span class="rv-label">Kelurahan / Desa</span>
            <span class="rv-val"><?= htmlspecialchars($d['kelurahan'] ?? '-') ?></span>
          </div>
          <div class="review-row">
            <span class="rv-label">Alamat jalan</span>
            <span class="rv-val" style="max-width:60%;text-align:right"><?= htmlspecialchars($d['alamat'] ?? '-') ?></span>
          </div>
          <div class="review-row">
            <span class="rv-label">Kode Pos</span>
            <span class="rv-val"><?= htmlspecialchars($d['kode_pos'] ?? '-') ?></span>
          </div>
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
              <?php if ($ao_net <= 0): ?>
                <span class="rv-val" style="color:var(--green)">Gratis</span>
              <?php elseif ($ao_disc > 0): ?>
                <span class="rv-val" style="color:#DC2626">
                  <span style="text-decoration:line-through;color:#9CA3AF;font-weight:500">Rp <?= number_format($ao_fee,0,',','.') ?></span>
                  Rp <?= number_format($ao_net,0,',','.') ?>
                  <span style="color:#F59E0B;font-size:12px">(−<?= $ao_disc ?>%)</span>
                </span>
              <?php else: ?>
                <span class="rv-val" style="color:#DC2626">Rp <?= number_format($ao_net,0,',','.') ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span class="rv-val" style="color:var(--green)">Gratis</span>
            <?php endif; ?>
          </div>
          <?php if (($d['mode'] ?? 'trial') === 'paid' && $ao_coin > 0): ?>
          <div class="review-row">
            <span class="rv-label">Bonus Coin</span>
            <span class="rv-val" style="color:var(--teal-d)">🪙 <?= number_format($ao_coin,0,',','.') ?> coin</span>
          </div>
          <?php endif; ?>
        </div>

        <div style="background:#F0FDFA;border:1px solid #99F6E4;border-radius:12px;padding:14px 16px;margin-bottom:18px">
          <div style="font-weight:700;color:#0F766E;margin-bottom:8px;font-size:13.5px">🏪 Yang kamu dapatkan</div>
          <ul style="margin:0;padding-left:18px;color:#374151;font-size:12.5px;line-height:1.8">
            <li>Outlet aktif penuh — semua fitur (POS, Order &amp; Kanban, Inventori, Mesin, Antar-Jemput, Laporan Keuangan, Absensi, Loyalty)</li>
            <li>Data &amp; pengaturan terpisah per outlet (pelanggan, layanan, harga, staf sendiri)</li>
            <li>Metode pembayaran siap pakai (Tunai, Transfer, QRIS)</li>
            <li>Bahan/inventori default sudah ter-seed</li>
            <li>Penomoran nota otomatis khas outlet</li>
            <?php if (($d['mode'] ?? 'trial') === 'paid' && $ao_coin > 0): ?>
            <li><strong><?= number_format($ao_coin,0,',','.') ?> coin bonus</strong> dikreditkan saat outlet aktif</li>
            <?php endif; ?>
            <?php if (($d['mode'] ?? 'trial') === 'paid' && WelcomeKit::enabled() && WelcomeKit::options()): ?>
            <li><strong>🎁 Welcome kit fisik</strong> dikirim ke alamat outlet (pilih paket di bawah)</li>
            <?php endif; ?>
          </ul>
        </div>

        <form method="POST">
          <input type="hidden" name="_csrf" value="<?= $csrf ?>">
          <?php
            $wkOpts = (($d['mode'] ?? 'trial') === 'paid' && WelcomeKit::enabled()) ? WelcomeKit::options() : [];
            $wkDefault = WelcomeKit::defaultOption()['key'] ?? '';
          ?>
          <?php if ($wkOpts): ?>
          <div style="margin:14px 0">
            <div style="font-weight:700;color:#0F172A;margin-bottom:8px;font-size:13.5px">🎁 Pilih Welcome Kit (gratis)</div>
            <?php if (count($wkOpts) === 1): ?>
              <input type="hidden" name="welcome_kit_choice" value="<?= htmlspecialchars($wkOpts[0]['key']) ?>">
              <div style="font-size:12.5px;color:#374151">
                <strong><?= htmlspecialchars($wkOpts[0]['nama']) ?></strong>:
                <?= htmlspecialchars(implode(', ', array_map(fn($i)=>$i['qty'].'× '.$i['nama'], $wkOpts[0]['items']))) ?>
              </div>
            <?php else: foreach ($wkOpts as $opt): ?>
              <label style="display:flex;gap:8px;align-items:flex-start;padding:9px 11px;border:1px solid #E5E9F2;border-radius:8px;margin-bottom:6px;cursor:pointer">
                <input type="radio" name="welcome_kit_choice" value="<?= htmlspecialchars($opt['key']) ?>" <?= $opt['key'] === $wkDefault ? 'checked' : '' ?> style="margin-top:3px">
                <span style="font-size:12.5px;color:#374151">
                  <strong><?= htmlspecialchars($opt['nama']) ?></strong><br>
                  <span style="color:#6B7280"><?= htmlspecialchars(implode(', ', array_map(fn($i)=>$i['qty'].'× '.$i['nama'], $opt['items']))) ?></span>
                </span>
              </label>
            <?php endforeach; endif; ?>
          </div>
          <?php endif; ?>
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

<?php
// ══════════════════════════════════════════════════════
// hq/_layout_open.php — HQ shell opener
//
// Usage di setiap HQ page:
//   $activePage = 'hq-dashboard';
//   $pageTitle  = 'Dashboard Eksekutif';
//   require __DIR__ . '/_layout_open.php';
//   // ... isi konten ...
//   require __DIR__ . '/_layout_close.php';
// ══════════════════════════════════════════════════════

$_aPage      = $activePage ?? '';
$_pageTitle  = $pageTitle ?? 'HQ';
$_ownerNama  = $hqUser['nama'] ?? ($ownerNama ?? 'Owner');
$_tenantNama = $hqTenant['nama_perusahaan'] ?? 'Kantor Pusat';

// Group active state
$_inTim = in_array($_aPage, ['hq-karyawan','hq-mutasi','hq-sdm','hq-penggajian','hq-roles'], true);
$_inCrm = in_array($_aPage, ['hq-pelanggan','hq-promo','hq-loyalty'], true);
$_inKeu = in_array($_aPage, ['hq-keuangan','hq-laporan'], true);

// Switch button visibility (owner & manager only)
$_canSwitch = !empty($hqIsOwner) || !empty($hqIsManager);
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="icon" type="image/png" href="/assets/logo.png">
  <meta name="theme-color" content="#0F1C3A">
  <title><?= htmlspecialchars($_pageTitle) ?> · LaMaSy HQ</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/harpy-erp.css?v=<?= @filemtime(dirname(__DIR__).'/harpy-erp.css') ?: date('Ymd') ?>">
  <link rel="stylesheet" href="/harpy-hq.css?v=<?= @filemtime(dirname(__DIR__).'/harpy-hq.css') ?: date('Ymd') ?>">
  <?php if (function_exists('getCsrfToken')): ?>
  <meta name="csrf-token" content="<?= htmlspecialchars(getCsrfToken()) ?>">
  <?php endif; ?>
  <?php
    require_once dirname(__DIR__) . '/components.php';
    renderGlobalJsHelpers();
  ?>
</head>
<body>

<div class="hq-shell" id="hqShell">
  <div class="hq-shell-backdrop" onclick="document.getElementById('hqShell').classList.remove('open')"></div>

  <!-- ── SIDEBAR ── -->
  <aside class="hq-side">
    <div class="hq-side-brand">
      <div class="hq-side-logo">LAMASY</div>
      <div class="hq-side-sub" title="<?= htmlspecialchars($_tenantNama) ?>">
        <?= htmlspecialchars($_tenantNama) ?>
      </div>
    </div>

    <nav class="hq-side-nav">
      <div class="hq-side-label">Eksekutif</div>

      <a href="/dashboard?to=hq"
         class="hq-side-link <?= $_aPage === 'hq-dashboard' ? 'active' : '' ?>">
        <span class="ico">📊</span> Dashboard
      </a>
      <a href="/hq/outlet"
         class="hq-side-link <?= $_aPage === 'hq-outlet' ? 'active' : '' ?>">
        <span class="ico">🏪</span> Outlet
      </a>
      <a href="/hq/droppoint"
         class="hq-side-link <?= $_aPage === 'hq-droppoint' ? 'active' : '' ?>">
        <span class="ico">📦</span> Drop Point
      </a>
      <a href="/hq/layanan"
         class="hq-side-link <?= $_aPage === 'hq-layanan' ? 'active' : '' ?>">
        <span class="ico">🧺</span> Layanan & Harga
      </a>
      <a href="/hq/inventori"
         class="hq-side-link <?= $_aPage === 'hq-inventori' ? 'active' : '' ?>">
        <span class="ico">📦</span> Inventori
      </a>
      <a href="/hq/mesin"
         class="hq-side-link <?= $_aPage === 'hq-mesin' ? 'active' : '' ?>">
        <span class="ico">🪙</span> Mesin Koin
      </a>

      <div class="hq-side-divider"></div>
      <div class="hq-side-label">Tim & Pelanggan</div>

      <!-- Tim & Akses group -->
      <div class="hq-side-group <?= $_inTim ? 'open' : '' ?>">
        <button type="button" class="hq-side-link hq-side-group-btn <?= $_inTim ? 'active' : '' ?>"
                onclick="this.parentElement.classList.toggle('open')">
          <span class="ico">👥</span> Tim & Akses
          <span class="arr">▼</span>
        </button>
        <div class="hq-side-submenu">
          <a href="/hq/karyawan"
             class="hq-side-link <?= $_aPage === 'hq-karyawan' ? 'active' : '' ?>">Karyawan</a>
          <a href="/hq/mutasi"
             class="hq-side-link <?= $_aPage === 'hq-mutasi' ? 'active' : '' ?>">Riwayat Mutasi</a>
          <a href="/hq/sdm"
             class="hq-side-link <?= $_aPage === 'hq-sdm' ? 'active' : '' ?>">SDM Analytics</a>
          <a href="/hq/penggajian"
             class="hq-side-link <?= $_aPage === 'hq-penggajian' ? 'active' : '' ?>">Penggajian</a>
          <a href="/hq/roles"
             class="hq-side-link <?= $_aPage === 'hq-roles' ? 'active' : '' ?>">Role & Akses</a>
        </div>
      </div>

      <!-- Pelanggan & Promo group -->
      <div class="hq-side-group <?= $_inCrm ? 'open' : '' ?>">
        <button type="button" class="hq-side-link hq-side-group-btn <?= $_inCrm ? 'active' : '' ?>"
                onclick="this.parentElement.classList.toggle('open')">
          <span class="ico">🛍️</span> CRM
          <span class="arr">▼</span>
        </button>
        <div class="hq-side-submenu">
          <a href="/hq/pelanggan"
             class="hq-side-link <?= $_aPage === 'hq-pelanggan' ? 'active' : '' ?>">Pelanggan</a>
          <a href="/hq/promo"
             class="hq-side-link <?= $_aPage === 'hq-promo' ? 'active' : '' ?>">Promo & Voucher</a>
          <a href="/hq/loyalty"
             class="hq-side-link <?= $_aPage === 'hq-loyalty' ? 'active' : '' ?>">⭐ Sistem Poin</a>
        </div>
      </div>

      <div class="hq-side-divider"></div>
      <div class="hq-side-label">Analitik</div>

      <a href="/hq/laporan"
         class="hq-side-link <?= $_aPage === 'hq-laporan' ? 'active' : '' ?>">
        <span class="ico">📈</span> Laporan
      </a>
      <a href="/hq/keuangan"
         class="hq-side-link <?= $_aPage === 'hq-keuangan' ? 'active' : '' ?>">
        <span class="ico">📒</span> Keuangan
      </a>
      <a href="/hq/billing"
         class="hq-side-link <?= $_aPage === 'hq-billing' ? 'active' : '' ?>">
        <span class="ico">💳</span> Coin & Billing
      </a>
      <a href="/hq/coin-info"
         class="hq-side-link <?= $_aPage === 'hq-coin-info' ? 'active' : '' ?>">
        <span class="ico">💲</span> Harga Fitur
      </a>
      <a href="/hq/checklist"
         class="hq-side-link <?= $_aPage === 'hq-checklist' ? 'active' : '' ?>">
        <span class="ico">✅</span> Checklist
      </a>
      <a href="/hq/broadcast"
         class="hq-side-link <?= $_aPage === 'hq-broadcast' ? 'active' : '' ?>">
        <span class="ico">📢</span> Broadcast
      </a>
      <a href="/hq/audit"
         class="hq-side-link <?= $_aPage === 'hq-audit' ? 'active' : '' ?>">
        <span class="ico">📋</span> Audit
      </a>

      <div class="hq-side-divider"></div>
      <div class="hq-side-label">AI Tools</div>

      <a href="/hq/ai-chat"
         class="hq-side-link <?= $_aPage === 'hq-ai-chat' ? 'active' : '' ?>">
        <span class="ico">✨</span> AI Chat
      </a>
      <a href="/hq/ai-churning"
         class="hq-side-link <?= $_aPage === 'hq-ai-churning' ? 'active' : '' ?>">
        <span class="ico">🎯</span> Smart Notif
      </a>

      <div class="hq-side-divider"></div>
      <div class="hq-side-label">Bantuan</div>

      <a href="/support"
         class="hq-side-link <?= $_aPage === 'hq-support' ? 'active' : '' ?>">
        <span class="ico">🎧</span> Support & Tiket
      </a>

      <div class="hq-side-divider"></div>

      <a href="/hq/struk"
         class="hq-side-link <?= $_aPage === 'hq-struk' ? 'active' : '' ?>">
        <span class="ico">🧾</span> Struk & Invoice
      </a>
      <a href="/hq/settings"
         class="hq-side-link <?= $_aPage === 'hq-settings' ? 'active' : '' ?>">
        <span class="ico">⚙️</span> Settings
      </a>
    </nav>
  </aside>

  <!-- ── MAIN AREA ── -->
  <div class="hq-main">
    <!-- Topbar -->
    <div class="hq-top">
      <div class="hq-top-left">
        <button type="button" class="hq-side-toggle"
                onclick="document.getElementById('hqShell').classList.toggle('open')">☰</button>
        <span class="hq-top-badge">🏢 HQ</span>
        <span class="hq-top-title"><?= htmlspecialchars($_pageTitle) ?></span>
      </div>
      <div class="hq-top-right">
        <span class="hq-top-user"><?= htmlspecialchars($_ownerNama) ?></span>
        <?php if ($_canSwitch): ?>
          <a href="/dashboard?to=outlet" class="hq-top-switch" title="Pindah ke Outlet View">Ke Outlet →</a>
        <?php endif; ?>
        <a href="/logout" class="hq-top-logout" onclick="return confirm('Yakin logout?')">Logout</a>
      </div>
    </div>

    <!-- Content area starts -->
    <main class="hq-content">
      <div class="hq-content-inner">

<?php
// ══════════════════════════════════════════════════════
// components.php — UI Components Harpy SaaS
// Pastikan tenant_guard.php sudah di-include sebelum file ini.
// ══════════════════════════════════════════════════════

// ════════════════════════════════════════
// OBSERVER / IMPERSONATION HELPERS
// ════════════════════════════════════════

/**
 * True jika superadmin sedang mengobservasi tenant ini.
 */
function isObserverMode(): bool
{
    return !empty($_SESSION['impersonating_tenant_id']);
}

/**
 * Render banner observer yang selalu muncul di atas halaman.
 * Panggil tepat setelah <body> atau setelah topbar.
 */
function renderObserverBanner(): void
{
    if (!isObserverMode()) return;

    $adminName  = htmlspecialchars($_SESSION['impersonation_admin_name']  ?? 'Superadmin');
    $tenantName = htmlspecialchars($_SESSION['impersonation_tenant_name'] ?? 'Tenant');
    ?>
    <div id="observerBanner" style="
        position: sticky; top: 0; z-index: 9999;
        background: linear-gradient(90deg, #4338CA, #6366F1);
        color: #fff; padding: 10px 20px;
        display: flex; align-items: center; justify-content: space-between; gap: 12px;
        font-family: 'Plus Jakarta Sans', sans-serif; font-size: 13px;
        box-shadow: 0 2px 12px rgba(99,102,241,.5);
    ">
      <div style="display:flex;align-items:center;gap:10px;">
        <span style="background:rgba(255,255,255,.2);border-radius:6px;padding:3px 8px;font-size:11px;font-weight:700;letter-spacing:.06em;">
          🔍 OBSERVER MODE
        </span>
        <span>
          <strong><?= $adminName ?></strong> sedang mengobservasi tenant
          <strong><?= $tenantName ?></strong> — <em>read-only</em>, tidak ada aksi yang berefek.
        </span>
      </div>
      <a href="/superadmin/stop_impersonate.php?t=<?= htmlspecialchars($_SESSION['stop_impersonate_token'] ?? '') ?>"
         style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);
                color:#fff;padding:6px 14px;border-radius:7px;font-weight:700;
                text-decoration:none;font-size:12px;white-space:nowrap;transition:background .15s;"
         onmouseover="this.style.background='rgba(255,255,255,.25)'"
         onmouseout="this.style.background='rgba(255,255,255,.15)'"
         onclick="return confirm('Akhiri sesi observasi?')">
        🚪 Akhiri Observasi
      </a>
    </div>
    <?php
}

// ── Demo Mode Banner ──────────────────────────────────
function renderDemoBanner(): void {
    if (empty($_SESSION['is_demo'])) return; ?>
    <div id="demoBanner" style="
        background:linear-gradient(135deg,#1F3864,#2E5FA3);
        color:#fff;padding:10px 16px;
        display:flex;align-items:center;justify-content:space-between;
        gap:10px;font-size:13px;flex-wrap:wrap;
        position:sticky;top:0;z-index:900;
        box-shadow:0 2px 8px rgba(0,0,0,.2);
    ">
      <span style="display:flex;align-items:center;gap:8px">
        🎮 <strong>Mode Demo</strong>
        <span style="color:rgba(255,255,255,.6)">— Data akan direset setiap 24 jam. Fitur write dibatasi.</span>
      </span>
      <span style="display:flex;align-items:center;gap:8px">
        <a href="/demo-exit?convert=1"
           style="background:#FAC775;color:#1F3864;padding:6px 14px;border-radius:6px;text-decoration:none;font-size:12px;font-weight:700;white-space:nowrap">
          Daftar Gratis — Trial 30 Hari →
        </a>
        <a href="/demo-exit"
           onclick="return confirm('Keluar dari mode demo?')"
           style="color:rgba(255,255,255,.5);text-decoration:none;font-size:18px;line-height:1;padding:2px 4px"
           title="Keluar demo">✕</a>
      </span>
    </div>
    <style>
      .has-demo-banner .ol-top { top: 45px; }
      #demoBanner + * { /* ruang untuk banner */ }
    </style>
    <?php
}

function renderHead(string $title = 'LAMASY'): void {
    $csrf = getCsrfToken();
    // Skip PWA tenant register di portal pelanggan (punya SW + manifest sendiri)
    $_uri = $_SERVER['REQUEST_URI'] ?? '';
    $isPortalPelanggan = strpos($_uri, '/pelanggan') === 0 || strpos($_uri, '/p?') === 0 || strpos($_uri, '/p/') === 0;
    ?>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>"/>
    <link rel="icon" type="image/png" href="/assets/icon-192.png?v=<?= @filemtime(__DIR__.'/assets/icon-192.png') ?: '3' ?>"/>
    <link rel="apple-touch-icon" href="/assets/apple-touch-icon-180.png?v=<?= @filemtime(__DIR__.'/assets/apple-touch-icon-180.png') ?: '3' ?>"/>
    <meta name="theme-color" content="#0F1C3A"/>
    <?php if (!$isPortalPelanggan): ?>
    <!-- PWA tenant -->
    <link rel="manifest" href="/assets/manifest-tenant.json">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="LAMASY">
    <script>
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw-tenant.js', { scope: '/' })
          .catch(err => console.warn('SW tenant register failed:', err));
      });
    }
    </script>
    <?php endif; ?>
    <title><?= htmlspecialchars($title) ?> — LAMASY</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/harpy-erp.css?v=<?= @filemtime(__DIR__.'/harpy-erp.css') ?: date('Ymd') ?>">
    <?php renderGlobalJsHelpers(); ?>
    <?php
}

/**
 * Global JS helpers — dipakai semua page (outlet & HQ).
 * Dipanggil dari renderHead() dan hq/_layout_open.php.
 */
function renderGlobalJsHelpers(): void { ?>
    <script>
    // Global UI helpers — tersedia sebelum body scripts execute.
    window.esc    = s => (s||'').toString().replace(/[<>&"]/g, c=>({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c]));
    window.fmtNum = n => new Intl.NumberFormat('id-ID').format(n||0);
    window.fmtRp  = n => 'Rp ' + window.fmtNum(n);
    window.fmtDate = s => { if (!s) return '-'; return new Date(s.replace(' ','T')).toLocaleString('id-ID', {day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'}); };
    window.fmtTime = s => { if (!s) return '-'; return new Date(s.replace(' ','T')).toLocaleTimeString('id-ID', {hour:'2-digit',minute:'2-digit'}); };
    window.fmtTanggal = s => { if (!s) return '-'; return new Date(s.replace(' ','T')).toLocaleDateString('id-ID', {day:'2-digit',month:'short',year:'numeric'}); };
    window.capitalize = s => s ? s.charAt(0).toUpperCase() + s.slice(1) : '';
    window.katLabelInventori = k => ({deterjen:'🧴 Deterjen', parfum:'🌸 Parfum', pewangi:'💧 Pewangi', plastik_kemasan:'📦 Plastik', peralatan:'🔧 Peralatan', lainnya:'📋 Lainnya'}[k] || k);

    // ══════════════════════════════════════════════════════
    // PRODUCT TOUR — sidebar walkthrough untuk first-time user
    // Activates after outlet ready. Targets data-tour="<key>" elements.
    // ══════════════════════════════════════════════════════
    window.HarpyTour = {
      storageKey: 'harpy_tour_main_v1',
      steps: [
        { tour: 'dashboard', title: '📊 Dashboard',       body: 'Ringkasan omset, order aktif, pipeline produksi hari ini. Cek pagi-pagi sebelum mulai operasional.' },
        { tour: 'pos',       title: '🛒 POS (Kasir)',      body: 'Input order baru dari pelanggan: pilih layanan, foto cucian, atur DP, cetak struk. Shortcut F3 untuk simpan.' },
        { tour: 'orders',    title: '📋 Daftar Order',     body: 'Track semua order: status proses, pembayaran, riwayat. Bisa bulk-bayar lunas + bulk-kirim WA.' },
        { tour: 'karyawan',  title: '👥 Karyawan',         body: 'Data karyawan, role akses, absensi, dan penggajian. Cocok untuk outlet dengan 2+ staff.' },
        { tour: 'kas',       title: '💰 Kas',              body: 'Catat kas masuk/keluar harian. Auto-sync dengan order POS. Dasar laporan SAK EMKM.' },
        { tour: 'laporan',   title: '📈 Laporan',          body: 'Laporan omset, kas, dan keuangan SAK EMKM bulanan. Export PDF/Excel untuk akuntan.' },
      ],
      visibleSteps: [],
      current: 0,
      active: false,

      shouldAutoStart() {
        if (localStorage.getItem(this.storageKey)) return false;
        if (window.innerWidth < 900) return false; // skip mobile (sidebar collapsed)
        return !!document.querySelector('[data-tour]');
      },

      start() {
        if (this.active) return;
        this.visibleSteps = this.steps.filter(s => document.querySelector(`[data-tour="${s.tour}"]`));
        if (!this.visibleSteps.length) return;
        this.current = 0;
        this.active = true;
        this.render();
      },

      render() {
        this.destroy();
        const step = this.visibleSteps[this.current];
        const target = document.querySelector(`[data-tour="${step.tour}"]`);
        if (!target) { this.next(); return; }

        // Auto-expand parent collapsible group jika ada
        const groupItems = target.closest('[data-group-items]');
        if (groupItems) {
          const gKey = groupItems.dataset.groupItems;
          const toggle = document.querySelector(`.ol-side-group-toggle[data-group="${gKey}"]`);
          if (toggle) {
            const items = document.querySelector(`[data-group-items="${gKey}"]`);
            if (items && items.style.maxHeight === '0px') {
              items.style.maxHeight = items.scrollHeight + 'px';
            }
          }
        }

        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        // Tunggu scroll selesai, baru position card
        setTimeout(() => {
          const rect = target.getBoundingClientRect();
          const overlay = document.createElement('div');
          overlay.className = 'hl-tour-overlay';
          overlay.id = 'hlTourOverlay';
          overlay.innerHTML = `
            <div class="hl-tour-spotlight" style="top:${rect.top-6}px;left:${rect.left-6}px;width:${rect.width+12}px;height:${rect.height+12}px"></div>
            <div class="hl-tour-card" style="top:${rect.top}px;left:${rect.right+20}px">
              <div class="hl-tour-arrow"></div>
              <div class="hl-tour-title">${step.title}</div>
              <div class="hl-tour-body">${step.body}</div>
              <div class="hl-tour-actions">
                <button class="hl-tour-skip" onclick="HarpyTour.skip()">Skip</button>
                <div class="hl-tour-dots">
                  ${this.visibleSteps.map((_,i) => `<span class="hl-tour-dot${i===this.current?' active':''}"></span>`).join('')}
                </div>
                ${this.current < this.visibleSteps.length-1
                  ? `<button class="hl-tour-next" onclick="HarpyTour.next()">Next →</button>`
                  : `<button class="hl-tour-next" onclick="HarpyTour.finish()">Selesai ✓</button>`}
              </div>
            </div>`;
          document.body.appendChild(overlay);
        }, 250);
      },

      next() { if (this.current < this.visibleSteps.length-1) { this.current++; this.render(); } else { this.finish(); } },

      skip() {
        localStorage.setItem(this.storageKey, '1');
        this.destroy();
        this.active = false;
      },

      finish() {
        localStorage.setItem(this.storageKey, '1');
        this.destroy();
        this.active = false;
        // Toast konfirmasi
        if (typeof showToast === 'function') showToast('Tour selesai ✓ Replay kapan saja via tombol "Tour Sistem"', 'success');
      },

      replay() {
        localStorage.removeItem(this.storageKey);
        this.start();
      },

      destroy() { const o = document.getElementById('hlTourOverlay'); if (o) o.remove(); }
    };

    // Auto-start setelah load + delay (kasih waktu sidebar render)
    document.addEventListener('DOMContentLoaded', () => {
      if (HarpyTour.shouldAutoStart()) {
        setTimeout(() => HarpyTour.start(), 1000);
      }
    });

    // ── PWA install prompt (Android Chrome) ──
    window._deferredInstallPrompt = null;
    window.addEventListener('beforeinstallprompt', (e) => {
      e.preventDefault();
      window._deferredInstallPrompt = e;
      if (localStorage.getItem('pwa_install_dismissed')) return;
      if (window.matchMedia('(display-mode: standalone)').matches) return;
      setTimeout(() => {
        const el = document.getElementById('pwaInstallBanner');
        if (el) el.style.display = 'flex';
      }, 3000);
    });
    window.installPWA = function() {
      const p = window._deferredInstallPrompt;
      if (!p) return;
      p.prompt();
      p.userChoice.then(choice => {
        document.getElementById('pwaInstallBanner').style.display = 'none';
        if (choice.outcome === 'accepted') {
          localStorage.setItem('pwa_install_dismissed', 'installed');
        }
        window._deferredInstallPrompt = null;
      });
    };
    window.dismissInstallBanner = function() {
      localStorage.setItem('pwa_install_dismissed', String(Date.now()));
      ['pwaInstallBanner','pwaIosBanner'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
      });
    };
    // iOS Safari (no beforeinstallprompt event)
    window.addEventListener('load', () => {
      const ua = navigator.userAgent;
      const isIOS = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
      const isSafari = /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS/.test(ua);
      const isStandalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;
      if (isIOS && isSafari && !isStandalone && !localStorage.getItem('pwa_install_dismissed')) {
        setTimeout(() => {
          const el = document.getElementById('pwaIosBanner');
          if (el) el.style.display = 'flex';
        }, 5000);
      }
    });
    </script>
    <?php
}

/**
 * PWA install banner — Android (auto-prompt) + iOS Safari (manual instruksi).
 * Render di body bawah. Display di-control via JS dari renderGlobalJsHelpers.
 */
function renderPwaInstallBanners(): void {
    $_uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($_uri, '/pelanggan') === 0 || strpos($_uri, '/p?') === 0) return;
    ?>
    <div id="pwaInstallBanner" class="pwa-banner" style="display:none">
      <img src="/assets/icon-192.png" width="40" height="40" class="pwa-banner-icon" alt="LAMASY">
      <div class="pwa-banner-text">
        <div class="pwa-banner-title">Install LAMASY</div>
        <div class="pwa-banner-sub">Akses cepat dari home screen, tanpa buka browser.</div>
      </div>
      <button onclick="installPWA()" class="hl-btn hl-btn-primary hl-btn-sm">Install</button>
      <button onclick="dismissInstallBanner()" class="pwa-banner-dismiss-btn" aria-label="Tutup">✕</button>
    </div>
    <div id="pwaIosBanner" class="pwa-banner" style="display:none">
      <img src="/assets/icon-192.png" width="40" height="40" class="pwa-banner-icon" alt="LAMASY">
      <div class="pwa-banner-text">
        <div class="pwa-banner-title">Pasang ke Home Screen</div>
        <div class="pwa-banner-sub">Tap <strong>⎘</strong> Safari → <strong>"Tambah ke Layar Utama"</strong></div>
      </div>
      <button onclick="dismissInstallBanner()" class="pwa-banner-dismiss-btn" aria-label="Tutup">✕</button>
    </div>
    <?php
}

function renderTopbar(string $activePage = '', bool $minimalMode = false): void {
    $user   = currentUser();
    $tenant = currentTenant();
    if (!$user) return;

    // Menu visibility berbasis PERMISSION — bukan role hardcoded.
    // hasPermission() sudah handle owner/superadmin bypass (return true).
    //
    // perm: null  → selalu tampil untuk semua user login
    // perm: 'x.y' → tampil jika user punya permission x.y
    // perms: ['a','b'] → tampil jika user punya SALAH SATU permission
    // roles: [...]  → fallback role-based untuk fitur tanpa permission spesifik
    $navGroups = [
        'dashboard' => [
            'label' => 'Dashboard',
            'items' => [
                'dashboard' => ['label'=>'Dashboard', 'url'=>'/dashboard', 'perm'=>null],
            ],
        ],
        'operasional' => [
            'label' => 'Operasional',
            'items' => [
                'pos'       => ['label'=>'POS',       'url'=>'/pos',       'perm'=>'pos.view'],
                'orders'    => ['label'=>'Order',     'url'=>'/orders',    'perms'=>['orders.view_all','orders.view_own']],
                'kanban'    => ['label'=>'Kanban',    'url'=>'/kanban',    'perms'=>['orders.view_all','orders.view_own']],
                'kas'       => ['label'=>'Kas',       'url'=>'/kas',       'perm'=>'kas.view'],
                'inventori' => ['label'=>'Inventori', 'url'=>'/inventori', 'perms'=>['inventori.view','kas.view']],
                'mesin'     => ['label'=>'Mesin Koin', 'url'=>'/mesin',     'perms'=>['mesin.view','pos.view']],
                'produksi'     => ['label'=>'Produksi',     'url'=>'/produksi',     'perm'=>'produksi.work'],
                'antar-jemput' => ['label'=>'Antar Jemput', 'url'=>'/antar-jemput', 'perm'=>'antar.view'],
                'checklist'    => ['label'=>'Checklist',    'url'=>'/checklist',    'perm'=>null],
            ],
        ],
        'keuangan' => [
            'label' => 'Keuangan',
            'items' => [
                'laporan' => ['label'=>'Laporan',    'url'=>'/laporan', 'perm'=>'laporan.view'],
                'piutang' => ['label'=>'Piutang B2B','url'=>'/piutang', 'perm'=>'laporan.view'],
            ],
        ],
        'master' => [
            'label' => 'Master',
            'items' => [
                'layanan'  => ['label'=>'Layanan',        'url'=>'/layanan',   'perm'=>'layanan.view'],
                'promo'    => ['label'=>'Promo',          'url'=>'/promo',     'perm'=>'promo.view'],
                'customer'     => ['label'=>'Customer',       'url'=>'/customer',     'perm'=>'pelanggan.view'],
                'member'   => ['label'=>'Member Tier',    'url'=>'/member',    'perm'=>'pelanggan.view'],
                'deposit'  => ['label'=>'Deposit Wallet', 'url'=>'/deposit',   'perm'=>'pelanggan.view'],
                'approval-inbox' => ['label'=>'⏳ Approval Inbox', 'url'=>'/approval-inbox', 'perm'=>'owner'],
                'loyalty'  => ['label'=>'Sistem Poin',    'url'=>'/loyalty',   'perm'=>'pelanggan.view'],
                'retention'=> ['label'=>'Retensi Dormant','url'=>'/retention', 'perm'=>'pelanggan.view'],
            ],
        ],
        'hr' => [
            'label' => 'HR',
            'items' => [
                // Owner kelola karyawan terpusat di HQ (assign multi-outlet) → sembunyikan
                // di app outlet supaya tidak redundant. Manager (tanpa akses HQ) tetap lihat.
                'karyawan'  => ['label'=>'Karyawan',  'url'=>'/karyawan',  'perm'=>'karyawan.view', 'hide_roles'=>['owner']],
                'absensi'   => ['label'=>'Absensi',   'url'=>'/absensi',   'perms'=>['absensi.view','absensi.clock']],
                'droppoint' => ['label'=>'Drop Point','url'=>'/droppoint',
                                'roles'=>['owner','superadmin','admin','manager']],
            ],
        ],
        'settings' => [
            'label' => 'Settings',
            'items' => [
                'outlet-settings' => ['label'=>'Outlet & Nota',  'url'=>'/outlet-settings', 'perm'=>'settings.roles'],
                'settings'     => ['label'=>'Role & Permission', 'url'=>'/settings',     'perm'=>'settings.roles'],
                'audit'        => ['label'=>'Audit Log',         'url'=>'/audit',         'perm'=>'audit.view'],
                'owner_report' => ['label'=>'Notifikasi Owner',  'url'=>'/owner-report',
                                   'roles'=>['owner','superadmin','admin','manager']],
            ],
        ],
        'bantuan' => [
            'label' => 'Bantuan',
            'items' => [
                'import'  => ['label'=>'Import Data',    'url'=>'/import',  'perm'=>'settings.roles'],
                'support' => ['label'=>'Support & Tiket','url'=>'/support', 'perm'=>'bantuan.view'],
            ],
        ],
    ];

    // Cek visibilitas satu menu item: permission-based dulu, role-based sebagai fallback
    function navItemVisible(array $item, array $user): bool {
        // hide_roles: sembunyikan untuk role tertentu walau punya permission
        // (mis. owner — karena ada menu setara di HQ)
        if (!empty($item['hide_roles']) && in_array($user['role'] ?? '', $item['hide_roles'], true)) {
            return false;
        }
        if (array_key_exists('perm', $item)) {
            if ($item['perm'] === null) return true;           // selalu tampil
            return hasPermission($item['perm']);
        }
        if (isset($item['perms'])) {                           // cukup salah satu
            foreach ($item['perms'] as $p) {
                if (hasPermission($p)) return true;
            }
            return false;
        }
        return in_array($user['role'], $item['roles'] ?? []); // fallback role
    }

    function groupVisible(array $group, array $user): bool {
        foreach ($group['items'] as $item) {
            if (navItemVisible($item, $user)) return true;
        }
        return false;
    }
    function groupHasActive(array $group, string $activePage): bool {
        return array_key_exists($activePage, $group['items']);
    }
    ?>

    <?php
    // ════════════════════════════════════════════════════════
    // Outlet Shell — sidebar + topbar tipis (Section 11.3)
    // ════════════════════════════════════════════════════════
    // Sidebar brand: prioritas nama_perusahaan (brand), fallback ke nama_outlet
    // Badge "📍 OUTLET" pakai nama outlet aktif dari TenantResolver
    $brandNama        = $tenant['nama_perusahaan'] ?: TenantResolver::namaOutlet() ?: 'Outlet';
    $outletNama       = $brandNama; // backward compat untuk kode lain yang pakai $outletNama
    $activeOutletNama = TenantResolver::namaOutlet() ?: $brandNama;
    $emphasisKeys = ['pos','orders']; // nav yang ditandai (POS/Order)
    $iconMap = [
      'dashboard'=>'🏠','pos'=>'🛒','orders'=>'📋','kas'=>'💰',
      'laporan'=>'📊','layanan'=>'🧺','promo'=>'🎟️','customer'=>'👥','member'=>'⭐','approval-inbox'=>'📥','deposit'=>'💳',
      'karyawan'=>'👤','absensi'=>'📅','settings'=>'⚙️','audit'=>'🔍','outlet-settings'=>'🏪',
      'checklist'=>'✅','droppoint'=>'📦','owner_report'=>'📨','piutang'=>'💼','kanban'=>'🗂️','struk'=>'🧾',
      'loyalty'=>'⭐','retention'=>'😴','support'=>'🎧','import'=>'📥',
      'inventori'=>'🧴','mesin'=>'🪙','produksi'=>'🧺',
      'antar-jemput'=>'🚚','kurir-master'=>'🛵',
    ];
    ?>
    <div class="ol-shell" id="olShell">
      <div class="ol-shell-backdrop" onclick="document.getElementById('olShell').classList.remove('open')"></div>

      <!-- ── SIDEBAR ── -->
      <aside class="ol-side">
        <div class="ol-side-brand">
          <div class="ol-side-logo"><img src="/assets/logo.png?v=<?= @filemtime(__DIR__.'/assets/logo.png') ?: '3' ?>" alt="LAMASY" style="height:22px;max-width:24px;object-fit:contain;vertical-align:middle;margin-right:6px;flex-shrink:0">LAMASY</div>
          <div class="ol-side-sub" title="<?= htmlspecialchars($brandNama) ?>">
            <?= htmlspecialchars($brandNama) ?>
          </div>
          <?php if ($activeOutletNama !== $brandNama): ?>
          <div class="ol-side-outlet" title="<?= htmlspecialchars($activeOutletNama) ?>">
            📍 <?= htmlspecialchars($activeOutletNama) ?>
          </div>
          <?php endif; ?>
        </div>

        <?php if (!$minimalMode): ?>
        <nav class="ol-side-nav">
          <?php foreach ($navGroups as $groupKey => $group):
            if (!groupVisible($group, $user)) continue;
            $visibleItems = array_filter($group['items'], fn($i) => navItemVisible($i, $user));
            if (!$visibleItems) continue;
            // Group 'dashboard' single-item — gak perlu header collapsible
            $isSingle = count($visibleItems) === 1 && $groupKey === 'dashboard';
            // Cek apakah active page ada di group ini → group auto-expanded
            $hasActive = isset($visibleItems[$activePage]);
          ?>
          <?php if (!$isSingle): ?>
          <button type="button" class="ol-side-label ol-side-group-toggle <?= $hasActive ? 'has-active' : '' ?>"
                  data-group="<?= htmlspecialchars($groupKey) ?>" aria-expanded="true">
            <span class="ol-side-label-text"><?= htmlspecialchars($group['label']) ?></span>
            <span class="ol-side-chevron">▾</span>
          </button>
          <?php endif; ?>
          <div class="ol-side-group-items<?= $isSingle ? ' ol-side-group-single' : '' ?>" data-group-items="<?= htmlspecialchars($groupKey) ?>">
          <?php foreach ($visibleItems as $key => $item):
            $isEmph = in_array($key, $emphasisKeys, true);
            $isActive = $activePage === $key;
          ?>
          <a href="<?= $item['url'] ?>"
             data-tour="<?= htmlspecialchars($key) ?>"
             class="ol-side-link <?= $isEmph ? 'emphasis' : '' ?> <?= $isActive ? 'active' : '' ?>">
            <span class="ico"><?= $iconMap[$key] ?? '•' ?></span> <?= htmlspecialchars($item['label']) ?>
          </a>
          <?php endforeach; ?>
          </div>
          <?php endforeach; ?>
        </nav>

        <?php if (!$minimalMode): ?>
        <button type="button" class="hl-tour-replay-btn" onclick="HarpyTour.replay()" title="Walkthrough fitur sidebar">
          <span>💡</span> Tour Sistem
        </button>
        <?php endif; ?>

        <script>
        // ── Side menu collapsible groups (persist in localStorage) ──
        (function() {
          const STORAGE_KEY = 'lamasy_sidemenu_collapsed_v1';
          // Get collapsed set
          function getCollapsed() {
            try { return new Set(JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]')); }
            catch (e) { return new Set(); }
          }
          function saveCollapsed(set) {
            try { localStorage.setItem(STORAGE_KEY, JSON.stringify([...set])); } catch (e) {}
          }
          // Apply state pada load
          const collapsed = getCollapsed();
          document.querySelectorAll('.ol-side-group-toggle').forEach(btn => {
            const groupKey = btn.dataset.group;
            const items = document.querySelector(`[data-group-items="${groupKey}"]`);
            if (!items) return;
            // Auto-expand kalau group punya active page (override saved collapsed)
            const hasActive = btn.classList.contains('has-active');
            const isCollapsed = collapsed.has(groupKey) && !hasActive;
            if (isCollapsed) {
              items.classList.add('ol-side-collapsed');
              btn.setAttribute('aria-expanded', 'false');
              btn.classList.add('is-collapsed');
            }
            btn.addEventListener('click', () => {
              const willCollapse = !items.classList.contains('ol-side-collapsed');
              items.classList.toggle('ol-side-collapsed', willCollapse);
              btn.classList.toggle('is-collapsed', willCollapse);
              btn.setAttribute('aria-expanded', willCollapse ? 'false' : 'true');
              const s = getCollapsed();
              willCollapse ? s.add(groupKey) : s.delete(groupKey);
              saveCollapsed(s);
            });
          });
        })();
        </script>
        <?php endif; ?>
      </aside>

      <!-- ── MAIN AREA ── -->
      <div class="ol-main <?= !empty($_SESSION['is_demo']) ? 'has-demo-banner' : '' ?>">
        <?php renderObserverBanner(); ?>
        <?php renderDemoBanner(); ?>
        <header class="ol-top">
          <div class="ol-top-left">
            <?php if (!$minimalMode): ?>
            <button class="ol-side-toggle" type="button"
                    onclick="document.getElementById('olShell').classList.toggle('open')">☰</button>
            <?php endif; ?>
            <?php if ($minimalMode): ?>
              <span class="ol-top-badge" style="background:rgba(53,232,213,.12);color:#1BC4B3;">🏢 HQ</span>
            <?php else: ?>
              <span class="ol-top-badge">📍 OUTLET</span>
            <?php endif; ?>
            <span class="ol-top-title"><?= htmlspecialchars($activeOutletNama) ?></span>
          </div>
          <div class="ol-top-right">
            <?php
            // ── Compute notif items (trial/grace/low-coin/unread-owner-notif) ──
            $notifItems = [];
            if (!$minimalMode && TenantResolver::hasOutlet()):
              $isTrial   = TenantResolver::isTrial();
              $trialDays = $isTrial ? TenantResolver::trialDaysLeft() : 0;
              $coin      = TenantResolver::coinBalance();
              $isGrace   = TenantResolver::isGraceMode();
              $coinFmt   = number_format($coin, 0, ',', '.');

              if ($isTrial) {
                $sev = $trialDays <= 3 ? 'danger' : ($trialDays <= 7 ? 'warn' : 'info');
                $notifItems[] = [
                  'icon'  => '⏰', 'sev' => $sev,
                  'title' => 'Masa Trial',
                  'desc'  => "Sisa <strong>{$trialDays} hari</strong> sebelum akun aktif penuh.",
                  'cta'   => ['url' => '/hq/billing.php', 'label' => 'Top Up'],
                ];
              } elseif ($isGrace) {
                $g = TenantResolver::graceDaysLeft();
                $notifItems[] = [
                  'icon'  => '⚠️', 'sev' => 'danger',
                  'title' => 'Grace Period — Bayar Segera',
                  'desc'  => "Layanan terhenti dalam <strong>{$g} hari</strong> kalau tidak bayar.",
                  'cta'   => ['url' => '/hq/billing.php', 'label' => 'Bayar'],
                ];
              }
              if ($coin < 500 && !$isTrial) {
                $notifItems[] = [
                  'icon'  => '🪙', 'sev' => $coin < 100 ? 'danger' : 'warn',
                  'title' => 'Saldo Coin Rendah',
                  'desc'  => "Tinggal <strong>{$coinFmt}</strong> coin. Top up sebelum habis.",
                  'cta'   => ['url' => '/hq/billing.php', 'label' => 'Top Up'],
                ];
              }
              // Unread owner_report (hanya untuk role owner/manager/admin)
              $unreadOwnerReport = 0;
              if (TenantResolver::isAdminLevel()) {
                try {
                  require_once ROOT . '/core/Notifier.php';
                  $unreadOwnerReport = (int)Notifier::unreadCount((int)TenantResolver::id(), (int)TenantResolver::outletId());
                } catch (Throwable) {}
                if ($unreadOwnerReport > 0) {
                  $notifItems[] = [
                    'icon'  => '📨', 'sev' => 'info',
                    'title' => "{$unreadOwnerReport} notifikasi baru",
                    'desc'  => "Daily report & alert anomali menanti dibaca.",
                    'cta'   => ['url' => '/owner_report.php', 'label' => 'Lihat'],
                  ];
                }
              }

              // ── Tiket support dengan balasan baru dari superadmin ─────────
              try {
                $mDb = Database::get();
                // Tiket yang ada reply superadmin lebih baru dari reply terakhir tenant
                $ticketNotifSt = $mDb->prepare(
                  "SELECT COUNT(DISTINCT st.id) AS cnt
                   FROM support_tickets st
                   INNER JOIN support_ticket_replies r
                     ON r.ticket_id = st.id
                     AND r.superadmin_id IS NOT NULL
                     AND r.is_internal = 0
                   WHERE st.tenant_id = ?
                     AND st.status NOT IN ('closed')
                     AND r.created_at > COALESCE(
                       (SELECT MAX(r2.created_at) FROM support_ticket_replies r2
                        WHERE r2.ticket_id = st.id AND r2.user_id IS NOT NULL),
                       st.created_at
                     )"
                );
                $ticketNotifSt->execute([(int)TenantResolver::id()]);
                $unreadTicketReplies = (int)$ticketNotifSt->fetchColumn();
                if ($unreadTicketReplies > 0) {
                  $notifItems[] = [
                    'icon'  => '🎧', 'sev' => 'info',
                    'title' => "Balasan tiket support ({$unreadTicketReplies})",
                    'desc'  => "Tim LaMaSy sudah membalas tiket kamu.",
                    'cta'   => ['url' => '/support.php', 'label' => 'Lihat Tiket'],
                  ];
                }
              } catch (Throwable) {}

              // ── Announcement baru yang belum dibaca ───────────────────────
              try {
                $tenantStatus = TenantResolver::outletStatus() ?? 'active';
                $annSt = $mDb->prepare(
                  "SELECT a.id, a.title, a.type
                   FROM saas_announcements a
                   LEFT JOIN saas_announcement_reads ar
                     ON ar.announcement_id = a.id AND ar.tenant_id = ?
                   WHERE a.status = 'published'
                     AND (a.expires_at IS NULL OR a.expires_at > NOW())
                     AND (a.target_audience = 'semua' OR a.target_audience = ?)
                     AND ar.announcement_id IS NULL
                   ORDER BY a.is_pinned DESC, a.published_at DESC
                   LIMIT 3"
                );
                $annSt->execute([(int)TenantResolver::id(), $tenantStatus]);
                $unreadAnns = $annSt->fetchAll(PDO::FETCH_ASSOC);
                $annTypeIcon = ['fitur_baru'=>'✨','maintenance'=>'🔧','penting'=>'⚠️','promo'=>'🎁','umum'=>'🔔'];
                foreach ($unreadAnns as $ann) {
                  $notifItems[] = [
                    'icon'  => $annTypeIcon[$ann['type']] ?? '📢', 'sev' => 'info',
                    'title' => htmlspecialchars($ann['title']),
                    'desc'  => 'Tap untuk baca pengumuman terbaru.',
                    'cta'   => ['url' => '/support.php?ann=' . $ann['id'], 'label' => 'Baca'],
                  ];
                }
              } catch (Throwable) {}
            endif;

            $notifCount = count($notifItems);
            $hasDanger  = false;
            foreach ($notifItems as $n) { if ($n['sev'] === 'danger') { $hasDanger = true; break; } }
            ?>

            <?php if (!$minimalMode && TenantResolver::hasOutlet()): ?>
              <!-- Bell button + popover -->
              <div class="hl-notif" id="hlNotif">
                <button type="button" class="ol-top-bell <?= $hasDanger ? 'has-danger' : '' ?>"
                        id="hlNotifBtn"
                        title="Pemberitahuan"
                        aria-label="Pemberitahuan">
                  🔔
                  <?php if ($notifCount > 0): ?>
                    <span class="ol-top-bell-dot <?= $hasDanger ? 'danger' : '' ?>" style="pointer-events:none"><?= $notifCount > 9 ? '9+' : $notifCount ?></span>
                  <?php endif; ?>
                </button>
                <div class="hl-notif-pop" id="hlNotifPop">
                  <div class="hl-notif-head">
                    <span>🔔 Pemberitahuan</span>
                    <button type="button" id="hlNotifClose" aria-label="Tutup">✕</button>
                  </div>
                  <div class="hl-notif-body">
                    <?php if (empty($notifItems)): ?>
                      <div class="hl-notif-empty">
                        <div style="font-size:2rem;margin-bottom:6px">✅</div>
                        <div style="font-weight:700;color:var(--navy)">Semua aman</div>
                        <div style="font-size:12px;color:var(--gray);margin-top:2px">Tidak ada pemberitahuan untuk akun & outlet ini.</div>
                      </div>
                    <?php else: ?>
                      <?php foreach ($notifItems as $n): ?>
                        <div class="hl-notif-item sev-<?= htmlspecialchars($n['sev']) ?>">
                          <div class="hl-notif-icon"><?= $n['icon'] ?></div>
                          <div class="hl-notif-content">
                            <div class="hl-notif-title"><?= htmlspecialchars($n['title']) ?></div>
                            <div class="hl-notif-desc"><?= $n['desc'] ?></div>
                            <?php if (!empty($n['cta'])): ?>
                              <a href="<?= htmlspecialchars($n['cta']['url']) ?>" class="hl-notif-cta">
                                <?= htmlspecialchars($n['cta']['label']) ?> →
                              </a>
                            <?php endif; ?>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <!-- Coin chip tetap inline (info operasional, selalu terlihat) -->
              <span class="ol-top-chip" title="Saldo coin">🪙 <?= $coinFmt ?></span>
            <?php endif; ?>

            <?php
            // ── Outlet switcher ──
            if (!$minimalMode && TenantResolver::hasOutlet()):
              $currentOutletId = TenantResolver::outletId();
              $currentOutletNm = TenantResolver::namaOutlet();
              $tdb  = Database::get();
              $stmt = $tdb->prepare(
                "SELECT id, nama_outlet, status FROM outlets
                 WHERE tenant_id = ? AND status IN ('trial','grace','active')
                 ORDER BY is_main DESC, nama_outlet ASC"
              );
              $stmt->execute([TenantResolver::id()]);
              $allOutlets = $stmt->fetchAll();
              $hasMulti = count($allOutlets) > 1;
            ?>
            <div class="hl-outlet-switch" style="position:relative;min-width:0">
              <button class="ol-top-chip" type="button"
                      onclick="this.nextElementSibling.classList.toggle('open')"
                      style="border:none;cursor:pointer;font-family:inherit;min-width:0;max-width:100%">
                <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-block;min-width:0;max-width:36vw"><?= htmlspecialchars($currentOutletNm) ?></span>
                <span style="font-size:9px;opacity:.6;flex-shrink:0">▼</span>
              </button>
              <div class="hl-outlet-dropdown" style="display:none;position:absolute;top:calc(100% + 6px);
                           right:0;background:#fff;border:1px solid #D5DAE8;border-radius:10px;
                           box-shadow:0 8px 24px rgba(27,45,90,.12);min-width:240px;z-index:1000;
                           padding:6px;max-height:380px;overflow-y:auto">
                <div style="font-size:10px;color:var(--gray);font-weight:700;padding:8px 12px 4px;
                            text-transform:uppercase;letter-spacing:.06em">
                  <?= $hasMulti ? 'Pilih Outlet' : 'Outlet Aktif' ?>
                </div>
                <?php
                  // Secret sama dengan switch-outlet.php
                  $swSecret = hash('sha256', session_id() . ($user['id'] ?? '') . 'switch_outlet_v1');
                ?>
                <?php foreach ($allOutlets as $o):
                  $isActive = (int)$o['id'] === $currentOutletId;
                  $swToken  = substr(hash_hmac('sha256', 'so:' . ($user['id'] ?? '') . ':' . (int)$o['id'], $swSecret), 0, 16);
                ?>
                <a href="/switch-outlet?id=<?= (int)$o['id'] ?>&t=<?= $swToken ?>"
                   style="display:block;padding:8px 12px;border-radius:6px;text-decoration:none;
                          color:<?= $isActive ? 'var(--navy)' : 'var(--dark)' ?>;font-size:13px;
                          font-weight:<?= $isActive ? '700' : '500' ?>;
                          background:<?= $isActive ? 'var(--teal-bg)' : 'transparent' ?>">
                  <?= $isActive ? '✓ ' : '' ?><?= htmlspecialchars($o['nama_outlet']) ?>
                  <span style="float:right;font-size:10px;color:var(--gray);text-transform:uppercase">
                    <?= $o['status'] ?>
                  </span>
                </a>
                <?php endforeach; ?>
              </div>
              <script>
              document.addEventListener('click',function(e){
                if(!e.target.closest('.hl-outlet-switch')){
                  document.querySelectorAll('.hl-outlet-dropdown.open').forEach(function(el){el.classList.remove('open')});
                }
              });
              </script>
              <style>.hl-outlet-dropdown.open{display:block!important}</style>
            </div>
            <?php endif; ?>

            <span class="ol-top-user"><?= htmlspecialchars($user['nama']) ?></span>
            <?php if (!$minimalMode && TenantResolver::canAccessHqV2()): ?>
              <a href="/dashboard?to=hq" class="ol-top-switch"
                 title="Pindah ke HQ konsolidasi">HQ →</a>
            <?php endif; ?>
            <a href="/logout" class="ol-top-logout"
               onclick="return confirm('Yakin logout?')">Logout</a>
          </div>
        </header>

        <main class="ol-content">
          <div class="ol-content-inner">
    <?php // Konten page mulai di sini — ditutup di renderToast(). ?>

    <?php
}

function renderToast(): void { ?>
          </div><!-- /.ol-content-inner -->
        </main><!-- /.ol-content -->
      </div><!-- /.ol-main -->
    </div><!-- /.ol-shell -->
    <div class="hl-toast" id="toast"></div>

    <?php renderPwaInstallBanners(); ?>

    <?php
    // ── Splash Screen Edukatif (jika ada pending) ─────────
    if (!empty($_SESSION['pending_splash']) && empty($_SESSION['is_demo'])) {
        $splash = $_SESSION['pending_splash'];
        unset($_SESSION['pending_splash']);
        // Set session flag SEKARANG supaya kalau user nav cepat dan POST
        // /api/splash_seen.php belum sempat insert DB row, splash tetap
        // gak muncul lagi dalam sesi ini. DB row jadi guard cross-session.
        $_SESSION['splash_shown'] = true;
        renderSplash($splash);
    }
    ?>

    <?php if (!empty($_SESSION['is_demo'])): ?>
    <!-- Demo CTA Modal -->
    <div id="demoCta" style="
        display:none;position:fixed;inset:0;background:rgba(15,28,58,.7);
        z-index:9999;align-items:center;justify-content:center;padding:20px;
    ">
      <div style="
          background:#fff;border-radius:20px;padding:40px 36px;
          max-width:420px;width:100%;text-align:center;
          box-shadow:0 20px 60px rgba(0,0,0,.3);
          animation:ctaIn .25s ease;
      ">
        <style>@keyframes ctaIn{from{transform:scale(.9);opacity:0}to{transform:scale(1);opacity:1}}</style>
        <div id="demoCtaIcon" style="font-size:48px;margin-bottom:16px">🎉</div>
        <h3 id="demoCtaTitle" style="font-size:20px;font-weight:800;color:#1F3864;margin-bottom:10px"></h3>
        <p id="demoCtaBody" style="font-size:14px;color:#5a6a8a;line-height:1.6;margin-bottom:24px"></p>
        <a id="demoCtaBtn" href="/demo-exit?convert=1"
           style="display:block;padding:14px;background:#1F3864;color:#fff;border-radius:10px;font-weight:700;font-size:15px;text-decoration:none;margin-bottom:12px">
          Daftar Gratis Sekarang →
        </a>
        <button onclick="document.getElementById('demoCta').style.display='none'"
                style="background:none;border:none;color:#aab;font-size:13px;cursor:pointer">
          Lanjut explore demo
        </button>
      </div>
    </div>
    <script>
    window._demoMode = true;
    window._demoActionsCount = <?= (int)($_SESSION['demo_actions'] ?? 0) ?>;
    function showDemoCTA(opts){
      var m = document.getElementById('demoCta');
      if (!m || sessionStorage.getItem('demoCta_shown')) return;
      document.getElementById('demoCtaIcon').textContent  = opts.icon  || '🎉';
      document.getElementById('demoCtaTitle').textContent = opts.title || 'Suka fitur ini?';
      document.getElementById('demoCtaBody').textContent  = opts.body  || '';
      if (opts.url) document.getElementById('demoCtaBtn').href = opts.url;
      m.style.display = 'flex';
      sessionStorage.setItem('demoCta_shown','1');
    }
    // Auto-trigger CTA setelah 3 aksi
    function _demoTrackAction(){
      window._demoActionsCount++;
      fetch('/dashboard?action=demo_track_action',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'}}).catch(()=>{});
      if (window._demoActionsCount === 3) {
        setTimeout(function(){
          showDemoCTA({
            icon:'🚀',
            title:'Kamu sudah explore 3 fitur!',
            body:'Akun nyata memberikan data real bisnis kamu, notifikasi WA ke pelanggan, dan laporan yang tidak di-reset. Daftar sekarang — gratis 30 hari!',
            url:'/demo-exit?convert=1',
          });
        }, 1500);
      }
    }
    // Patch fetch untuk tracking di demo
    (function(){
      var _origFetch = window.fetch;
      window.fetch = function(url, opts){
        if (_demoMode && opts && opts.method === 'POST') {
          var action = (typeof url === 'string' ? url : '').split('action=')[1];
          if (action && !action.startsWith('demo_')) _demoTrackAction();
        }
        return _origFetch.apply(this, arguments);
      };
    })();
    </script>
    <?php endif; ?>
    <script>
    function csrfToken(){return document.querySelector('meta[name="csrf-token"]')?.content||'';}
    // ── Notification bell ──
    (function(){
      function init(){
        var btn = document.getElementById('hlNotifBtn');
        var pop = document.getElementById('hlNotifPop');
        var closeBtn = document.getElementById('hlNotifClose');
        if (!btn || !pop) return;
        btn.addEventListener('click', function(e){
          e.stopPropagation(); e.preventDefault();
          pop.classList.toggle('open');
        });
        if (closeBtn) {
          closeBtn.addEventListener('click', function(e){
            e.stopPropagation();
            pop.classList.remove('open');
          });
        }
        pop.addEventListener('click', function(e){ e.stopPropagation(); });
        document.addEventListener('click', function(e){
          if (!pop.classList.contains('open')) return;
          if (e.target.closest('#hlNotif')) return;
          pop.classList.remove('open');
        });
      }
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
      } else {
        init();
      }
    })();
    function toggleFilter(id){
      var bar=document.getElementById(id),btn=document.getElementById(id+'Btn');
      if(!bar||!btn)return;
      var collapsed=bar.classList.toggle('collapsed');
      btn.classList.toggle('open',!collapsed);
      try{localStorage.setItem('hlFilter_'+id,collapsed?'0':'1');}catch(e){}
    }
    function initFilter(id,defaultOpen){
      var bar=document.getElementById(id),btn=document.getElementById(id+'Btn');
      if(!bar||!btn)return;
      var saved=null;
      try{saved=localStorage.getItem('hlFilter_'+id);}catch(e){}
      var open=saved!==null?saved==='1':(defaultOpen!==false);
      if(open){btn.classList.add('open');}else{bar.classList.add('collapsed');}
    }
    // Skeleton helper — renderSkel('container', {rows:5, type:'row'|'card'|'table'})
    function renderSkel(containerOrId, opts){
      const el = typeof containerOrId === 'string'
                 ? document.getElementById(containerOrId)
                 : containerOrId;
      if (!el) return;
      const o = Object.assign({rows:4, type:'row'}, opts||{});
      let html = '';
      if (o.type === 'card') {
        for (let i=0; i<o.rows; i++) {
          html += `<div class="hl-skel-card">
            <span class="hl-skel lg" style="width:60%"></span><br>
            <span class="hl-skel" style="width:80%;margin-top:8px"></span><br>
            <span class="hl-skel" style="width:40%;margin-top:6px"></span>
          </div>`;
        }
      } else if (o.type === 'table') {
        for (let i=0; i<o.rows; i++) {
          html += `<div class="hl-skel-row">
            <span class="hl-skel" style="width:90px"></span>
            <span class="hl-skel" style="width:140px"></span>
            <span class="hl-skel" style="width:60px;margin-left:auto"></span>
          </div>`;
        }
      } else {
        for (let i=0; i<o.rows; i++) {
          html += `<div class="hl-skel-row">
            <span class="hl-skel round" style="width:36px;height:36px"></span>
            <div style="flex:1">
              <span class="hl-skel" style="width:55%;display:block"></span>
              <span class="hl-skel" style="width:30%;display:block;margin-top:6px;height:9px"></span>
            </div>
          </div>`;
        }
      }
      el.innerHTML = html;
    }

    // Empty state v2 — renderEmpty('container', {icon:'📭', title:'...', sub:'...', cta:{label,onclick}})
    function renderEmpty(containerOrId, opts){
      const el = typeof containerOrId === 'string'
                 ? document.getElementById(containerOrId)
                 : containerOrId;
      if (!el) return;
      const o = Object.assign({icon:'📭', title:'Tidak ada data', sub:'', cta:null}, opts||{});
      el.innerHTML = `<div class="hl-empty-v2">
        <div class="e-icon">${o.icon}</div>
        <div class="e-title">${o.title}</div>
        ${o.sub ? `<div class="e-sub">${o.sub}</div>` : ''}
        ${o.cta ? `<button class="hl-btn hl-btn-primary hl-btn-sm" onclick="${o.cta.onclick||''}">${o.cta.label||'Tambah'}</button>` : ''}
      </div>`;
    }

    function showToast(msg,type='success'){
      const t=document.getElementById('toast');
      t.textContent=msg;t.className='hl-toast '+type+' show';
      setTimeout(()=>t.className='hl-toast',3500);
    }
    </script>
    <?php
}

// ══════════════════════════════════════════════════════
// SPLASH SCREEN EDUKATIF — render functions
// Dipanggil dari renderToast() kalau ada $_SESSION['pending_splash']
// ══════════════════════════════════════════════════════
function renderSplash(array $splash): void
{
    switch ($splash['type'] ?? '') {
        case 'onboarding': renderOnboardingSplash($splash); break;
        case 'whats_new':  renderWhatsNewSplash($splash);   break;
        case 'tips':       renderTipsSplash($splash);       break;
    }
    renderSplashScripts();
}

function renderOnboardingSplash(array $data): void
{
    $steps     = $data['steps'] ?? [];
    $completed = (int)($data['completed'] ?? 0);
    $total     = (int)($data['total'] ?? 3);
    $pct       = $total > 0 ? round($completed / $total * 100) : 0;

    // Next undone step → arah CTA utama
    $cta = '/dashboard';
    if (empty($steps['layanan']))        $cta = '/layanan';
    elseif (empty($steps['karyawan']))   $cta = '/karyawan';
    elseif (empty($steps['transaksi']))  $cta = '/pos';

    $stepRows = [
        ['layanan',   '/layanan',  'Tambah layanan & harga',   1],
        ['karyawan',  '/karyawan', 'Tambah karyawan',          2],
        ['transaksi', '/pos',      'Coba transaksi pertama',   3],
    ];
    ?>
    <div id="splash-overlay" class="splash-overlay" onclick="if(event.target===this)closeSplash('onboarding',null)">
      <div class="splash-card splash-onboarding">
        <button class="splash-close" onclick="closeSplash('onboarding', null)" aria-label="Tutup">×</button>
        <div class="splash-icon">🎉</div>
        <h2>Selamat datang di LAMASY!</h2>
        <p>Yuk selesaikan setup outlet kamu supaya bisa langsung terima order.</p>

        <div class="splash-progress">
          <div class="splash-progress-bar" style="width:<?= $pct ?>%"></div>
        </div>
        <div class="splash-progress-label"><?= $completed ?> dari <?= $total ?> langkah selesai · <?= $pct ?>%</div>

        <div class="splash-steps">
          <?php foreach ($stepRows as [$key, $url, $label, $num]):
            $done = !empty($steps[$key]); ?>
            <a href="<?= $url ?>" class="splash-step<?= $done ? ' done' : '' ?>"
               onclick="markSplashSeen('onboarding', null)">
              <span class="splash-step-num"><?= $done ? '✓' : $num ?></span>
              <span><?= htmlspecialchars($label) ?></span>
              <?php if (!$done): ?><span class="splash-step-arrow">→</span><?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>

        <div class="splash-actions">
          <a href="<?= $cta ?>" class="btn-splash-primary"
             onclick="markSplashSeen('onboarding', null)">Lanjut Setup →</a>
          <button class="btn-splash-skip" onclick="closeSplash('onboarding', null)">Nanti saja</button>
        </div>
      </div>
    </div>
    <?php
}

function renderWhatsNewSplash(array $data): void
{
    $a = $data['announcement'] ?? [];
    $id = (int)($a['id'] ?? 0);
    if (!$id) return;
    ?>
    <div id="splash-overlay" class="splash-overlay" onclick="if(event.target===this)closeSplash('whats_new','<?= $id ?>')">
      <div class="splash-card splash-whatsnew">
        <button class="splash-close" onclick="closeSplash('whats_new', '<?= $id ?>')" aria-label="Tutup">×</button>
        <div class="splash-badge">✨ Update Baru</div>
        <h2><?= htmlspecialchars($a['title'] ?? 'Fitur Baru') ?></h2>
        <div class="splash-content"><?= nl2br(htmlspecialchars($a['content'] ?? '')) ?></div>
        <div class="splash-actions">
          <button class="btn-splash-primary" onclick="closeSplash('whats_new', '<?= $id ?>')">Mengerti!</button>
        </div>
      </div>
    </div>
    <?php
}

function renderTipsSplash(array $data): void
{
    $tip = $data['tip'] ?? [];
    if (empty($tip['id'])) return;
    $refId = $tip['id'] . '_' . date('Y-m-d');
    $hasCta = !empty($tip['cta_label']) && !empty($tip['cta_url']);
    ?>
    <div id="splash-overlay" class="splash-overlay" onclick="if(event.target===this)closeSplash('tips','<?= htmlspecialchars($refId) ?>')">
      <div class="splash-card splash-tips">
        <button class="splash-close" onclick="closeSplash('tips', '<?= htmlspecialchars($refId) ?>')" aria-label="Tutup">×</button>
        <div class="splash-icon"><?= htmlspecialchars($tip['icon'] ?? '💡') ?></div>
        <div class="splash-badge">💡 Tips Hari Ini</div>
        <h2><?= htmlspecialchars($tip['judul']) ?></h2>
        <p><?= htmlspecialchars($tip['konten']) ?></p>
        <div class="splash-actions">
          <?php if ($hasCta): ?>
            <a href="<?= htmlspecialchars($tip['cta_url']) ?>" class="btn-splash-primary"
               onclick="markSplashSeen('tips', '<?= htmlspecialchars($refId) ?>')">
              <?= htmlspecialchars($tip['cta_label']) ?>
            </a>
          <?php endif; ?>
          <button class="btn-splash-skip" onclick="closeSplash('tips', '<?= htmlspecialchars($refId) ?>')">Tutup</button>
        </div>
      </div>
    </div>
    <?php
}

function renderSplashScripts(): void
{
    ?>
    <script>
    function markSplashSeen(type, refId) {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
      // keepalive: true supaya request tetap di-flush kalau user nav cepat
      fetch('/api/splash_seen.php', {
        method: 'POST',
        headers: { 'Content-Type':'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ type, ref_id: refId }),
        keepalive: true
      }).catch(() => {});
    }
    function closeSplash(type, refId) {
      markSplashSeen(type, refId);
      const overlay = document.getElementById('splash-overlay');
      if (overlay) {
        overlay.style.animation = 'splashFadeOut .2s ease forwards';
        setTimeout(() => overlay.remove(), 200);
      }
    }
    // ESC untuk tutup
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape') {
        const o = document.getElementById('splash-overlay');
        if (o) {
          // Default tutup tanpa mark seen (biar muncul lagi besok)
          o.style.animation = 'splashFadeOut .2s ease forwards';
          setTimeout(() => o.remove(), 200);
        }
      }
    });
    </script>
    <?php
}

function statusProsesBadge(string $status): string {
    $map = [
        'masuk'   => ['Masuk',       'masuk'],
        'cuci'    => ['🫧 Cuci',     'cuci'],
        'kering'  => ['💨 Kering',   'kering'],
        'setrika' => ['👔 Setrika',  'setrika'],
        'siap'    => ['✅ Siap',      'siap'],
        'diambil' => ['📦 Diambil',  'diambil'],
    ];
    [$label, $type] = $map[$status] ?? [$status, 'gray'];
    return '<span class="hl-badge hl-badge-' . $type . '">' . htmlspecialchars($label) . '</span>';
}

function statusBayarBadge(string $status): string {
    $map = [
        'lunas'       => ['✅ Lunas',      'lunas'],
        'dp'          => ['⚡ DP',          'dp'],
        'belum_bayar' => ['⏳ Belum Bayar','belum'],
    ];
    [$label, $type] = $map[$status] ?? [$status, 'gray'];
    return '<span class="hl-badge hl-badge-' . $type . '">' . htmlspecialchars($label) . '</span>';
}

function formatRupiah(float $amount): string {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

function formatTanggal(string $date, bool $withDay = false): string {
    if (!$date) return '-';
    return date($withDay ? 'l, d M Y' : 'd M Y', strtotime($date));
}

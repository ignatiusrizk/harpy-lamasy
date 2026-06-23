<?php
// ══════════════════════════════════════════════════════
// superadmin/superadmin_components.php
// Shared head & nav for Super Admin Panel
// ══════════════════════════════════════════════════════

function saRenderHead(string $title = 'Super Admin'): void {
    $csrf = saGetCsrf(); ?>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>"/>
    <link rel="icon" type="image/png" href="/assets/icon-192.png?v=<?= @filemtime(dirname(__DIR__).'/assets/icon-192.png') ?: '3' ?>"/>
    <link rel="apple-touch-icon" href="/assets/apple-touch-icon-180.png?v=<?= @filemtime(dirname(__DIR__).'/assets/apple-touch-icon-180.png') ?: '3' ?>"/>
    <meta name="theme-color" content="#0F1C3A"/>
    <title><?= htmlspecialchars($title) ?> — LAMASY Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Newsreader:opsz,wght@6..72,400;6..72,500;6..72,600;6..72,700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/harpy-erp.css?v=<?= date('Ymd') ?>">
    <style>
    /* ══════════════════════════════════════════════════════
       LAMASY SuperAdmin — "Folded Paper" design system
       Palette: Linen page · Paper surface · Indigo accent
       Type:    Inter-flavored sans body + Newsreader serif display
       Signature: Status thread (2px vertical color stripe)
       ══════════════════════════════════════════════════════ */
    :root {
      /* ── Folded Paper palette ── */
      --linen:   #FAFAF7;   /* page bg — warm off-white */
      --paper:   #FFFFFF;   /* raised surface */
      --crease:  #E8E5DC;   /* hairline borders, dividers */
      --crease-soft: #F0EDE6; /* subtler border for nested cards */
      --ink:     #1A1F2E;   /* primary text — deep blue-black */
      --ink-soft:#374151;   /* soft body text */
      --ash:     #6B7280;   /* muted/secondary text */
      --ash-dim: #9CA3AF;   /* tertiary/placeholder */
      --indigo:  #3730A3;   /* primary accent — "laundry blueing" */
      --indigo-d:#312E81;   /* hover */
      --indigo-l:rgba(55,48,163,.08);
      --indigo-glow:rgba(55,48,163,.18);
      --amber:   #D97706;   /* warning */
      --coral:   #DC2626;   /* error */
      --sage:    #059669;   /* success */

      /* ── Back-compat aliases (existing classes still work) ── */
      --sa:      var(--indigo);
      --sa-d:    var(--indigo-d);
      --sa-l:    var(--indigo-l);
      --sa-glow: var(--indigo-glow);
      --navy:    var(--ink);
      --navy-d:  var(--linen);     /* was page bg, now linen */
      --navy-m:  var(--paper);     /* was sidebar bg, now paper white */
      --white:   var(--paper);
      --gray:    var(--ash);
      --red:     var(--coral);
      --green:   var(--sage);
      --yellow:  var(--amber);
      --card-bg: var(--paper);
      --card-border: var(--crease);
      --hover-bg: var(--crease-soft);
      --text-muted: var(--ash-dim);
      --text-dim: var(--ash);
      --text-soft: var(--ink-soft);

      /* ── Type ── */
      --font:    'Plus Jakarta Sans', system-ui, sans-serif;
      --display: 'Newsreader', Georgia, serif;
      --mono:    'JetBrains Mono', ui-monospace, monospace;

      /* ── Layout ── */
      --r:       10px;
      --sidebar: 220px;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; font-family: var(--font); background: var(--linen); color: var(--ink); -webkit-font-smoothing: antialiased; }

    /* Display headings — selective serif accent */
    .sa-display, .sa-display * { font-family: var(--display); font-weight: 500; letter-spacing: -.012em; }

    /* ── Scrollbar (light theme) ───────────────── */
    ::-webkit-scrollbar { width: 8px; height: 8px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: var(--crease); border-radius: 8px; }
    ::-webkit-scrollbar-thumb:hover { background: var(--ash-dim); }

    /* ── Sidebar ─────────────────────────────── */
    .sa-layout { display: flex; min-height: 100vh; }

    .sa-sidebar {
      width: var(--sidebar);
      background: var(--paper);
      border-right: 1px solid var(--crease);
      display: flex; flex-direction: column;
      position: fixed; top: 0; left: 0; height: 100vh;
      z-index: 100; transition: transform .25s ease;
    }
    .sa-sidebar-brand {
      padding: 20px 18px 16px;
      display: flex; align-items: center; gap: 10px;
      border-bottom: 1px solid var(--crease);
      flex-shrink: 0;
    }
    .sa-sidebar-brand .logo-icon {
      width: 36px; height: 36px;
      background: var(--ink);
      border-radius: 9px;
      display: flex; align-items: center; justify-content: center;
      font-size: 18px; color: var(--paper);
      flex-shrink: 0;
    }
    .sa-sidebar-brand .brand-text { font-size: 13px; font-weight: 800; color: var(--ink); letter-spacing: .02em; line-height: 1.2; }
    .sa-sidebar-brand .brand-text small { font-size: 9px; font-family: var(--mono); font-weight: 600; letter-spacing: .12em; color: var(--indigo); text-transform: uppercase; display: block; }

    .sa-sidebar-nav { flex: 1; overflow-y: auto; padding: 10px 8px; }
    .sa-nav-section {
      font-size: 9px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase;
      color: var(--ash-dim); padding: 14px 10px 4px;
    }
    .sa-nav-link {
      display: flex; align-items: center; gap: 10px;
      padding: 8px 10px; font-size: 13px; font-weight: 500;
      color: var(--ash); text-decoration: none;
      border-radius: 8px; transition: background .12s, color .12s;
      position: relative; margin-bottom: 1px;
    }
    .sa-nav-link .icon { font-size: 15px; width: 20px; text-align: center; flex-shrink: 0; opacity: .85; }
    .sa-nav-link:hover { color: var(--ink); background: var(--crease-soft); }
    .sa-nav-link.active {
      color: var(--indigo);
      background: var(--indigo-l);
      font-weight: 600;
    }
    .sa-nav-link.active::before {
      content: '';
      position: absolute; left: -8px; top: 6px; bottom: 6px;
      width: 3px; background: var(--indigo); border-radius: 0 3px 3px 0;
    }
    .sa-nav-link.active .icon { opacity: 1; }

    .sa-sidebar-footer {
      padding: 12px 14px;
      border-top: 1px solid var(--crease);
      flex-shrink: 0;
    }
    .sa-admin-info { font-size: 12px; color: var(--ash); margin-bottom: 10px; }
    .sa-admin-info strong { display: block; color: var(--ink); font-size: 13px; font-weight: 700; }
    .sa-logout-btn {
      display: block; width: 100%;
      padding: 8px 12px;
      background: var(--paper); border: 1px solid var(--crease);
      color: var(--coral); font-size: 12.5px; font-weight: 600;
      border-radius: var(--r); text-align: center; text-decoration: none;
      transition: all .15s; cursor: pointer; letter-spacing: .01em;
    }
    .sa-logout-btn:hover { background: #FEF2F2; border-color: #FECACA; }

    /* ── Main content ────────────────────────── */
    .sa-main {
      flex: 1;
      min-width: 0;            /* CRITICAL: flex item default min-width=auto bikin content overflow parent */
      margin-left: var(--sidebar);
      min-height: 100vh;
      display: flex; flex-direction: column;
    }
    .sa-content { min-width: 0; }   /* defensive — prevent inner overflow */
    .sa-topbar {
      height: 60px;
      background: var(--paper);
      border-bottom: 1px solid var(--crease);
      display: flex; align-items: center; justify-content: space-between;
      padding: 0 28px;
      position: sticky; top: 0; z-index: 50;
    }
    .sa-topbar-title {
      font-family: var(--display);
      font-size: 22px; font-weight: 500; color: var(--ink);
      letter-spacing: -.018em;
    }
    .sa-topbar-right { display: flex; align-items: center; gap: 14px; font-size: 13px; color: var(--ash); }
    .sa-topbar-right a { transition: color .15s; color: var(--ash); }
    .sa-topbar-right a:hover { color: var(--coral); }
    .sa-hamburger {
      display: none; background: none; border: none; color: var(--ink);
      font-size: 22px; cursor: pointer; padding: 4px;
    }

    .sa-content { flex: 1; padding: 32px 32px 48px; background: var(--linen); }

    /* ── Page header ─────────────────────────── */
    .sa-page-header { margin-bottom: 28px; }
    .sa-page-header h1 {
      font-family: var(--display);
      font-size: 32px; font-weight: 500; color: var(--ink);
      letter-spacing: -.022em; line-height: 1.1;
    }
    .sa-page-header p { font-size: 14px; color: var(--ash); margin-top: 6px; max-width: 60ch; }

    /* ── Stat cards ──────────────────────────── */
    .sa-stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 14px; margin-bottom: 28px; }
    .sa-stat-card {
      background: var(--paper);
      border: 1px solid var(--crease);
      border-radius: 12px; padding: 18px 20px 20px;
      position: relative; overflow: hidden;
      transition: border-color .15s ease, transform .15s ease;
    }
    .sa-stat-card:hover {
      border-color: var(--ash-dim);
      transform: translateY(-1px);
    }
    /* ── SIGNATURE: Status thread (2px left vertical stripe) ── */
    .sa-stat-card::before {
      content: '';
      position: absolute; left: 0; top: 16px; bottom: 16px;
      width: 2px; background: var(--ink);
      border-radius: 0 2px 2px 0;
    }
    .sa-stat-card.thread-indigo::before { background: var(--indigo); }
    .sa-stat-card.thread-amber::before  { background: var(--amber); }
    .sa-stat-card.thread-coral::before  { background: var(--coral); }
    .sa-stat-card.thread-sage::before   { background: var(--sage); }
    .sa-stat-card.thread-ash::before    { background: var(--ash-dim); }
    .sa-stat-card .label {
      font-size: 10.5px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
      color: var(--ash); margin-bottom: 12px;
    }
    .sa-stat-card .value {
      font-family: var(--display);
      font-size: 32px; font-weight: 500; color: var(--ink);
      letter-spacing: -.025em; line-height: 1;
    }
    .sa-stat-card .sub { font-size: 11.5px; color: var(--ash-dim); margin-top: 6px; font-weight: 500; }
    .sa-stat-card .icon-bg {
      position: absolute; right: 14px; top: 14px;
      font-size: 30px; opacity: .12;
      pointer-events: none;
    }
    /* Status-themed stat cards (override left-thread color) */
    .sa-stat-card.indigo { background: rgba(55,48,163,.04); }
    .sa-stat-card.indigo::before { background: var(--indigo); }
    .sa-stat-card.green  { background: rgba(5,150,105,.04); }
    .sa-stat-card.green::before { background: var(--sage); }
    .sa-stat-card.yellow { background: rgba(217,119,6,.05); }
    .sa-stat-card.yellow::before { background: var(--amber); }
    .sa-stat-card.red    { background: rgba(220,38,38,.04); }
    .sa-stat-card.red::before { background: var(--coral); }
    .sa-stat-card.blue   { background: rgba(55,48,163,.04); }
    .sa-stat-card.blue::before { background: var(--indigo); }

    /* ── sa-mini-grid: inline metric rows ── */
    .sa-mini-grid { display: grid; gap: 12px; margin-bottom: 14px; }
    .sa-mini-stat { text-align: center; }
    .sa-mini-stat .val {
      font-family: var(--display);
      font-size: 28px; font-weight: 500; color: var(--ink);
      letter-spacing: -.025em; line-height: 1.05;
    }
    .sa-mini-stat .lbl {
      font-size: 11px; color: var(--ash);
      margin-top: 4px; line-height: 1.2; font-weight: 500;
    }
    .sa-mini-stat.red    .val { color: var(--coral); }
    .sa-mini-stat.indigo .val { color: var(--indigo); }
    .sa-mini-stat.green  .val { color: var(--sage); }
    .sa-mini-stat.yellow .val { color: var(--amber); }

    /* ── Cards ───────────────────────────────── */
    .sa-card {
      background: var(--paper);
      border: 1px solid var(--crease);
      border-radius: 12px; overflow: hidden;
      margin-bottom: 24px;
    }
    .sa-card-header {
      padding: 16px 22px;
      border-bottom: 1px solid var(--crease);
      display: flex; align-items: center; justify-content: space-between;
      gap: 12px; flex-wrap: wrap;
    }
    .sa-card-header h3 {
      font-family: var(--display);
      font-size: 18px; font-weight: 500; color: var(--ink);
      letter-spacing: -.012em;
    }
    .sa-card-body { padding: 22px; }

    /* chart-style card (billing, health) */
    .sa-chart-card {
      background: var(--paper);
      border: 1px solid var(--crease);
      border-radius: 12px; padding: 22px 24px;
      margin-bottom: 24px;
    }
    .sa-chart-card h3 {
      font-family: var(--display);
      font-size: 17px; font-weight: 500; color: var(--ink);
      margin-bottom: 16px; letter-spacing: -.012em;
    }

    /* ── Tables ──────────────────────────────── */
    .sa-table-wrap { overflow-x: auto; }
    .sa-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .sa-table th {
      padding: 10px 16px; text-align: left;
      font-size: 10px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
      color: var(--ash);
      border-bottom: 1px solid var(--crease);
      background: var(--linen);
    }
    .sa-table td {
      padding: 13px 16px;
      border-bottom: 1px solid var(--crease-soft);
      color: var(--ink-soft);
      vertical-align: middle;
    }
    .sa-table tr:last-child td { border-bottom: none; }
    .sa-table tr:hover td { background: var(--linen); }
    .sa-table a { color: var(--indigo); transition: color .12s; text-decoration: none; }
    .sa-table a:hover { color: var(--indigo-d); text-decoration: underline; text-underline-offset: 2px; }
    .sa-table .num, .sa-table .mono { font-family: var(--mono); font-variant-numeric: tabular-nums; font-size: 12.5px; color: var(--ink); }

    /* ── SIGNATURE: Status thread on table rows ── */
    .sa-table tr[class*="thread-"] td:first-child {
      position: relative; padding-left: 22px;
    }
    .sa-table tr[class*="thread-"] td:first-child::before {
      content: ''; position: absolute; left: 6px; top: 9px; bottom: 9px;
      width: 2px; border-radius: 0 2px 2px 0;
    }
    .sa-table tr.thread-indigo td:first-child::before { background: var(--indigo); }
    .sa-table tr.thread-amber  td:first-child::before { background: var(--amber); }
    .sa-table tr.thread-coral  td:first-child::before { background: var(--coral); }
    .sa-table tr.thread-sage   td:first-child::before { background: var(--sage); }
    .sa-table tr.thread-ash    td:first-child::before { background: var(--ash-dim); }

    /* ── Badges (light theme) ─────────────────── */
    .sa-badge {
      display: inline-flex; align-items: center; gap: 4px;
      padding: 3px 9px; border-radius: 20px;
      font-size: 11px; font-weight: 600; letter-spacing: .02em;
      white-space: nowrap;
    }
    .sa-badge-active    { background: #ECFDF5; color: #065F46; border: 1px solid #A7F3D0; }
    .sa-badge-trial     { background: #EEF2FF; color: var(--indigo); border: 1px solid #C7D2FE; }
    .sa-badge-suspended { background: #FEF2F2; color: #991B1B; border: 1px solid #FECACA; }
    .sa-badge-indigo    { background: #EEF2FF; color: var(--indigo); border: 1px solid #C7D2FE; }
    .sa-badge-yellow    { background: #FFFBEB; color: #92400E; border: 1px solid #FDE68A; }
    .sa-badge-red       { background: #FEF2F2; color: #991B1B; border: 1px solid #FECACA; }
    .sa-badge-blue      { background: #EFF6FF; color: #1E40AF; border: 1px solid #BFDBFE; }

    /* ── Buttons ─────────────────────────────── */
    .sa-btn {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 8px 16px; border-radius: var(--r);
      font-family: var(--font); font-size: 13px; font-weight: 600;
      border: none; cursor: pointer; text-decoration: none;
      transition: transform .12s, background .15s, border-color .15s, color .15s;
      white-space: nowrap; line-height: 1;
    }
    .sa-btn:focus-visible { outline: 2px solid var(--indigo); outline-offset: 2px; }
    .sa-btn-primary {
      background: var(--ink);
      color: var(--paper);
    }
    .sa-btn-primary:hover { background: var(--indigo); }
    .sa-btn-outline {
      background: var(--paper); border: 1px solid var(--crease);
      color: var(--ink-soft);
    }
    .sa-btn-outline:hover { border-color: var(--indigo); color: var(--indigo); background: var(--indigo-l); }
    .sa-btn-sm { padding: 6px 12px; font-size: 11.5px; border-radius: 7px; }
    .sa-btn-danger { background: var(--paper); border: 1px solid #FECACA; color: var(--coral); }
    .sa-btn-danger:hover { background: #FEF2F2; border-color: #FCA5A5; }
    .sa-btn-green { background: var(--paper); border: 1px solid #A7F3D0; color: var(--sage); }
    .sa-btn-green:hover { background: #ECFDF5; border-color: #6EE7B7; }
    .sa-btn-wa { background: var(--paper); border: 1px solid #BBF7D0; color: #15803D; }
    .sa-btn-wa:hover { background: #F0FDF4; border-color: #86EFAC; }

    /* ── Filter bar ──────────────────────────── */
    .sa-filter-bar {
      display: flex; flex-wrap: wrap; gap: 10px;
      padding: 14px 22px;
      background: var(--linen);
      border-bottom: 1px solid var(--crease);
    }
    .sa-filter-bar input, .sa-filter-bar select {
      padding: 8px 12px;
      background: var(--paper); border: 1px solid var(--crease);
      border-radius: 8px; color: var(--ink);
      font-family: var(--font); font-size: 13px; outline: none;
      transition: border-color .15s, box-shadow .15s;
    }
    .sa-filter-bar input:focus, .sa-filter-bar select:focus {
      border-color: var(--indigo); box-shadow: 0 0 0 3px var(--indigo-l);
    }
    .sa-filter-bar input::placeholder { color: var(--ash-dim); }

    /* ── Alert banner (inline) ───────────────── */
    .sa-alert-banner {
      display: flex; align-items: center; gap: 12px;
      padding: 13px 18px; border-radius: 10px;
      font-size: 13px; font-weight: 500; margin-bottom: 18px;
      border: 1px solid;
    }
    .sa-alert-banner.warn   { background: #FFFBEB; border-color: #FDE68A; color: #92400E; }
    .sa-alert-banner.danger { background: #FEF2F2; border-color: #FECACA; color: #991B1B; }
    .sa-alert-banner.info   { background: var(--indigo-l); border-color: #C7D2FE; color: var(--indigo-d); }
    .sa-alert-banner a { color: inherit; font-weight: 700; text-decoration: underline; text-underline-offset: 2px; }

    /* ── Modals ──────────────────────────────── */
    .sa-modal-overlay {
      display: none; position: fixed; inset: 0; z-index: 1000;
      background: rgba(26,31,46,.42); backdrop-filter: blur(4px);
      -webkit-backdrop-filter: blur(4px);
      align-items: center; justify-content: center;
    }
    .sa-modal-overlay.open { display: flex; }
    .sa-modal {
      background: var(--paper); border: 1px solid var(--crease);
      border-radius: 14px; padding: 28px; width: 100%; max-width: 480px;
      margin: 20px; box-shadow: 0 24px 56px rgba(26,31,46,.18);
      animation: saModalIn .2s cubic-bezier(.17,.67,.35,1.1);
    }
    @keyframes saModalIn { from { opacity:0; transform: scale(.96) translateY(8px); } }
    .sa-modal h3 {
      font-family: var(--display);
      font-size: 20px; font-weight: 500; color: var(--ink);
      margin-bottom: 18px; letter-spacing: -.015em;
    }
    .sa-modal .form-group { display: flex; flex-direction: column; gap: 7px; margin-bottom: 16px; }
    .sa-modal label {
      font-size: 11px; font-weight: 700; letter-spacing: .08em;
      text-transform: uppercase; color: var(--ash);
    }
    .sa-modal input, .sa-modal textarea, .sa-modal select {
      padding: 10px 14px;
      background: var(--paper); border: 1px solid var(--crease);
      border-radius: 8px; color: var(--ink);
      font-family: var(--font); font-size: 14px; outline: none;
      transition: border-color .15s, box-shadow .15s;
    }
    .sa-modal input:focus, .sa-modal textarea:focus, .sa-modal select:focus {
      border-color: var(--indigo); box-shadow: 0 0 0 3px var(--indigo-l);
    }
    .sa-modal input[readonly] { background: var(--linen); color: var(--ash); cursor: not-allowed; }
    .sa-modal textarea { resize: vertical; min-height: 90px; }
    .sa-modal-footer { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }

    /* ── Toast ───────────────────────────────── */
    .sa-toast {
      position: fixed; bottom: 28px; right: 24px; z-index: 9999;
      padding: 12px 20px; border-radius: 12px;
      font-size: 13.5px; font-weight: 600;
      opacity: 0; transform: translateY(16px) scale(.97);
      transition: all .25s cubic-bezier(.17,.67,.35,1.1);
      pointer-events: none; max-width: 380px;
      box-shadow: 0 8px 24px rgba(0,0,0,.35);
      display: flex; align-items: center; gap: 8px;
    }
    .sa-toast.show { opacity: 1; transform: translateY(0) scale(1); }
    .sa-toast.success { background: rgba(16,185,129,.92); color: #fff; }
    .sa-toast.error   { background: rgba(239,68,68,.92);  color: #fff; }
    .sa-toast.info    { background: rgba(99,102,241,.92); color: #fff; }

    /* ── Alert list items ────────────────────── */
    .sa-alert-item {
      display: flex; align-items: center; gap: 12px;
      padding: 12px 16px; border-radius: 10px;
      background: var(--linen); border: 1px solid var(--crease);
      margin-bottom: 8px; font-size: 13px;
      transition: background .12s, border-color .12s;
    }
    .sa-alert-item:hover { background: var(--paper); border-color: var(--ash-dim); }
    .sa-alert-item .alert-icon { font-size: 18px; flex-shrink: 0; }
    .sa-alert-item .alert-text { flex: 1; color: var(--ink-soft); line-height: 1.4; }
    .sa-alert-item .alert-action { font-size: 12px; flex-shrink: 0; display: flex; gap: 4px; }

    /* ── Tabs ────────────────────────────────── */
    .sa-tabs {
      display: flex; gap: 4px; border-bottom: 1px solid var(--crease);
      margin-bottom: 24px; overflow-x: auto;
    }
    .sa-tabs::-webkit-scrollbar { height: 0; }
    .sa-tab {
      padding: 10px 16px; font-size: 13px; font-weight: 600;
      color: var(--ash); border: none; background: none;
      cursor: pointer; white-space: nowrap; border-bottom: 2px solid transparent;
      transition: color .15s, border-color .15s; margin-bottom: -1px;
      border-radius: 6px 6px 0 0;
    }
    .sa-tab:hover { color: var(--ink); }
    .sa-tab.active { color: var(--indigo); border-bottom-color: var(--indigo); }

    .sa-tab-panel { display: none; }
    .sa-tab-panel.active { display: block; }

    /* ── Grid helpers ────────────────────────── */
    .sa-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    .sa-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
    .sa-grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }

    /* ── Pagination ──────────────────────────── */
    .sa-pagination { display: flex; align-items: center; gap: 6px; padding: 16px 22px; justify-content: flex-end; flex-wrap: wrap; }
    .sa-pagination .sa-btn-sm.disabled { opacity: .35; pointer-events: none; }

    /* ── Onboarding steps ────────────────────── */
    .step-done { color: var(--sage); font-size: 16px; }
    .step-fail { color: var(--coral); font-size: 16px; }

    /* ── Coin color (light theme) ───────────── */
    .coin-kritis { color: var(--coral); font-weight: 700; }
    .coin-rendah  { color: var(--amber); font-weight: 600; }
    .coin-ok      { color: var(--sage); font-weight: 600; }

    /* ── Risk badges ─────────────────────────── */
    .sa-risk-badge {
      display: inline-flex; align-items: center; gap: 4px;
      padding: 2px 8px; border-radius: 20px;
      font-size: 10.5px; font-weight: 600; margin: 2px;
      border: 1px solid;
    }
    .risk-tidak-login { background: #FEF2F2; color: #991B1B; border-color: #FECACA; }
    .risk-coin        { background: #FFFBEB; color: #92400E; border-color: #FDE68A; }
    .risk-trial       { background: #EEF2FF; color: var(--indigo); border-color: #C7D2FE; }
    .risk-no-topup    { background: var(--linen); color: var(--ash); border-color: var(--crease); }
    .risk-order-turun { background: #FEF2F2; color: #991B1B; border-color: #FECACA; }

    /* ── Mobile ──────────────────────────────── */
    @media (max-width: 900px) {
      .sa-sidebar { transform: translateX(-100%); }
      .sa-sidebar.open { transform: translateX(0); box-shadow: 8px 0 32px rgba(26,31,46,.18); }
      .sa-main { margin-left: 0; }
      .sa-hamburger { display: flex; }
      .sa-content { padding: 18px 18px 36px; }
      .sa-stats-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
      .sa-grid-2, .sa-grid-3, .sa-grid-4 { grid-template-columns: 1fr; }
      .sa-topbar { padding: 0 18px; }
      .sa-page-header h1 { font-size: 26px; }
    }
    @media (max-width: 480px) {
      .sa-stats-grid { grid-template-columns: 1fr; }
      .sa-filter-bar { padding: 12px; }
      .sa-page-header h1 { font-size: 24px; }
    }

    .sa-overlay-mobile {
      display: none; position: fixed; inset: 0;
      background: rgba(26,31,46,.35); z-index: 99;
      backdrop-filter: blur(2px);
    }
    .sa-overlay-mobile.open { display: block; }

    /* ── Loading skeleton ────────────────────── */
    @keyframes saSkeleton { from { opacity:.4; } to { opacity:.8; } }
    .sa-skeleton {
      background: var(--crease); border-radius: 6px;
      animation: saSkeleton .9s ease-in-out alternate infinite;
    }
    </style>
    <?php
}

function saRenderNav(string $activePage = '', string $pageTitle = ''): void {
    $admin = saCurrentAdmin();
    ?>
    <div class="sa-overlay-mobile" id="saOverlay" onclick="saCloseNav()"></div>

    <aside class="sa-sidebar" id="saSidebar">
      <div class="sa-sidebar-brand">
        <img src="/assets/logo.png?v=<?= @filemtime(dirname(__DIR__).'/assets/logo.png') ?: '2' ?>" alt="LAMASY" style="height:28px; flex-shrink:0;">
        <div class="brand-text">
          LAMASY <span style="color:var(--sa)">Admin</span>
          <small>Super Admin Panel</small>
        </div>
      </div>

      <nav class="sa-sidebar-nav">
        <div class="sa-nav-section">Platform</div>
        <a href="/superadmin/dashboard.php" class="sa-nav-link <?= $activePage === 'dashboard' ? 'active' : '' ?>">
          <span class="icon">🏠</span> Dashboard
        </a>
        <a href="/superadmin/health.php" class="sa-nav-link <?= $activePage === 'health' ? 'active' : '' ?>">
          <span class="icon">📡</span> Platform Health
        </a>
        <a href="/superadmin/clients.php" class="sa-nav-link <?= $activePage === 'clients' ? 'active' : '' ?>">
          <span class="icon">🏪</span> Clients
        </a>

        <div class="sa-nav-section">CS & Growth</div>
        <a href="/superadmin/registrations.php" class="sa-nav-link <?= $activePage === 'registrations' ? 'active' : '' ?>">
          <span class="icon">&#x1F4DD;</span> Registrasi
        </a>
        <a href="/superadmin/migrations.php" class="sa-nav-link <?= $activePage === 'migrations' ? 'active' : '' ?>">
          <span class="icon">📦</span> Migrations
        </a>

        <div class="sa-nav-section">Finance</div>
        <a href="/superadmin/billing.php" class="sa-nav-link <?= $activePage === 'billing' ? 'active' : '' ?>">
          <span class="icon">💳</span> Billing
        </a>
        <a href="/superadmin/payments.php" class="sa-nav-link <?= $activePage === 'payments' ? 'active' : '' ?>">
          <span class="icon">💰</span> Pembayaran
        </a>
        <a href="/superadmin/packages.php" class="sa-nav-link <?= $activePage === 'packages' ? 'active' : '' ?>">
          <span class="icon">🪙</span> Coin &amp; Aktivasi
        </a>
        <a href="/superadmin/coin_pricing.php" class="sa-nav-link <?= $activePage === 'coin_pricing' ? 'active' : '' ?>">
          <span class="icon">💲</span> Coin Pricing
        </a>
        <a href="/superadmin/ai_usage.php" class="sa-nav-link <?= $activePage === 'ai_usage' ? 'active' : '' ?>">
          <span class="icon">🤖</span> AI Usage & Margin
        </a>
        <div class="sa-nav-section">Support</div>
        <a href="/superadmin/support.php" class="sa-nav-link <?= $activePage === 'support' ? 'active' : '' ?>">
          <span class="icon">🎧</span> Tiket Support
        </a>
        <a href="/superadmin/announcements.php" class="sa-nav-link <?= $activePage === 'announcements' ? 'active' : '' ?>">
          <span class="icon">📢</span> Announcement
        </a>
        <a href="/superadmin/banners.php" class="sa-nav-link <?= $activePage === 'banners' ? 'active' : '' ?>">
          <span class="icon">🎨</span> Dashboard Banners
        </a>

        <div class="sa-nav-section">Konfigurasi</div>
        <a href="/superadmin/settings.php" class="sa-nav-link <?= $activePage === 'settings' ? 'active' : '' ?>">
          <span class="icon">⚙️</span> Platform Settings
        </a>
      </nav>

      <div class="sa-sidebar-footer">
        <div class="sa-admin-info">
          <strong><?= htmlspecialchars($admin['name'] ?? 'Admin') ?></strong>
          <?= htmlspecialchars($admin['username'] ?? '') ?>
        </div>
        <a href="/superadmin/logout.php" class="sa-logout-btn"
           onclick="return confirm('Yakin logout?')">🚪 Logout</a>
      </div>
    </aside>

    <div class="sa-main">
      <div class="sa-topbar">
        <div style="display:flex;align-items:center;gap:12px;">
          <button class="sa-hamburger" onclick="saOpenNav()">☰</button>
          <span class="sa-topbar-title"><?= htmlspecialchars($pageTitle) ?></span>
        </div>
        <div class="sa-topbar-right">
          <span><?= htmlspecialchars($admin['name'] ?? '') ?></span>
          <a href="/superadmin/logout.php" style="color:#FCA5A5;font-size:12px;text-decoration:none;"
             onclick="return confirm('Yakin logout?')">Logout</a>
        </div>
      </div>
      <div class="sa-content">
    <?php
}

function saRenderNavClose(): void { ?>
      </div><!-- /.sa-content -->
    </div><!-- /.sa-main -->

    <div class="sa-toast" id="saToast"></div>
    <script>
    function saCsrf(){ return document.querySelector('meta[name="csrf-token"]')?.content||''; }
    function saShowToast(msg, type='success'){
      const t=document.getElementById('saToast');
      t.textContent=msg; t.className='sa-toast '+type+' show';
      setTimeout(()=>{t.className='sa-toast';},3500);
    }
    function saOpenNav(){
      document.getElementById('saSidebar').classList.add('open');
      document.getElementById('saOverlay').classList.add('open');
      document.body.style.overflow='hidden';
    }
    function saCloseNav(){
      document.getElementById('saSidebar').classList.remove('open');
      document.getElementById('saOverlay').classList.remove('open');
      document.body.style.overflow='';
    }
    function saFetch(url, opts={}){
      // ⚠️ Spread opts DULU, lalu headers — supaya CSRF header tidak ter-overwrite
      return fetch(url, {
        ...opts,
        headers: { 'X-CSRF-Token': saCsrf(), 'X-Requested-With': 'XMLHttpRequest', ...(opts.headers||{}) },
      });
    }
    function saPost(url, data){
      const fd = new FormData();
      fd.append('_csrf', saCsrf());
      Object.entries(data).forEach(([k,v])=>fd.append(k,v));
      return fetch(url, { method:'POST', body: fd,
        headers:{ 'X-Requested-With': 'XMLHttpRequest' }
      });
    }
    </script>
    <?php
}

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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Inter+Tight:wght@500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/harpy-erp.css?v=<?= date('Ymd') ?>">
    <style>
    /* ══════════════════════════════════════════════════════
       LAMASY SuperAdmin — "AI Command Center" design system
       Palette: Obsidian + Slate, Brand Teal + AI Violet accents
       Type:    Inter body, Inter Tight display, JetBrains Mono data
       Signature: AI shimmer (teal→violet gradient) + live pulse
       ══════════════════════════════════════════════════════ */
    :root {
      /* ── Obsidian palette ── */
      --linen:        #0A0F1F;   /* page bg (legacy var name — now obsidian) */
      --obsidian:     #0A0F1F;
      --paper:        #141B2D;   /* card surface (was paper white) */
      --slate:        #141B2D;
      --slate-elev:   #1C2540;   /* hover/active surface */
      --crease:       #252D45;   /* hairline borders */
      --crease-soft:  #1C2540;   /* softer borders for nested */
      --ink:          #E2E8F0;   /* primary text (was dark, now glow) */
      --glow:         #E2E8F0;
      --ink-soft:     #CBD5E1;   /* secondary text */
      --ash:          #94A3B8;   /* muted text */
      --ash-dim:      #64748B;   /* tertiary / placeholder */
      --teal:         #35E8D5;   /* BRAND primary */
      --teal-deep:    #0BC3B0;   /* hover */
      --teal-glow:    rgba(53,232,213,.22);
      --teal-faint:   rgba(53,232,213,.08);
      --indigo:       #35E8D5;   /* alias — was indigo, now teal */
      --indigo-d:     #0BC3B0;
      --indigo-l:     rgba(53,232,213,.10);
      --indigo-glow:  rgba(53,232,213,.22);
      --ai-violet:    #A78BFA;   /* AI-feature accent */
      --ai-glow:      rgba(167,139,250,.22);
      --amber:        #F59E0B;
      --amber-l:      rgba(245,158,11,.12);
      --coral:        #F43F5E;
      --coral-l:      rgba(244,63,94,.12);
      --sage:         #84CC16;   /* lime — more vivid for dark bg */
      --sage-l:       rgba(132,204,22,.12);

      /* ── Back-compat aliases ── */
      --sa:      var(--teal);
      --sa-d:    var(--teal-deep);
      --sa-l:    var(--teal-faint);
      --sa-glow: var(--teal-glow);
      --navy:    var(--obsidian);
      --navy-d:  var(--obsidian);
      --navy-m:  var(--slate);
      --white:   var(--glow);
      --gray:    var(--ash);
      --red:     var(--coral);
      --green:   var(--sage);
      --yellow:  var(--amber);
      --card-bg: var(--slate);
      --card-border: var(--crease);
      --hover-bg: var(--slate-elev);
      --text-muted: var(--ash-dim);
      --text-dim: var(--ash);
      --text-soft: var(--ink-soft);

      /* ── Type ── */
      --font:    'Inter', system-ui, sans-serif;
      --display: 'Inter Tight', 'Inter', system-ui, sans-serif;
      --mono:    'JetBrains Mono', ui-monospace, monospace;

      /* ── Layout ── */
      --r:       10px;
      --sidebar: 220px;

      /* ── Gradients ── */
      --grad-ai:    linear-gradient(135deg, var(--teal), var(--ai-violet));
      --grad-brand: linear-gradient(135deg, var(--teal), var(--teal-deep));
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body {
      height: 100%; font-family: var(--font); color: var(--ink);
      background: var(--obsidian);
      background-image:
        radial-gradient(circle at 20% 0%, rgba(53,232,213,.04) 0%, transparent 40%),
        radial-gradient(circle at 80% 100%, rgba(167,139,250,.04) 0%, transparent 40%),
        radial-gradient(rgba(53,232,213,.05) 0.5px, transparent 0.5px);
      background-size: 100% 100%, 100% 100%, 24px 24px;
      background-attachment: fixed;
      -webkit-font-smoothing: antialiased;
      letter-spacing: -.005em;
    }

    /* Display headings — Inter Tight, modern tight tracking */
    .sa-display, .sa-display * {
      font-family: var(--display); font-weight: 700;
      letter-spacing: -.022em;
    }

    /* ── AI shimmer signature ── */
    @keyframes saAiShimmer {
      0%   { background-position: 0% 50%; }
      100% { background-position: 200% 50%; }
    }
    /* Gradient border (teal→violet→teal shimmer) — for AI cards */
    .sa-ai-border {
      position: relative; border-radius: 14px;
      border: 1px solid transparent;
      background:
        linear-gradient(var(--slate), var(--slate)) padding-box,
        linear-gradient(110deg, var(--teal) 0%, var(--ai-violet) 50%, var(--teal) 100%) border-box;
      background-size: 100% 100%, 200% 100%;
      animation: saAiShimmer 6s linear infinite;
    }
    /* Top accent strip — for page-level AI hero */
    .sa-ai-strip {
      height: 2px; border-radius: 999px;
      background: linear-gradient(110deg, var(--teal) 0%, var(--ai-violet) 50%, var(--teal) 100%);
      background-size: 200% 100%;
      animation: saAiShimmer 6s linear infinite;
      margin-bottom: 16px;
    }
    .sa-ai-pill {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 3px 10px; border-radius: 20px;
      background: var(--ai-glow); color: var(--ai-violet);
      border: 1px solid rgba(167,139,250,.32);
      font-size: 10.5px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
      box-shadow: 0 0 12px rgba(167,139,250,.18);
    }
    .sa-ai-pill::before {
      content: '✦'; font-size: 10px;
    }
    /* Subtle AI glow tint (for alerts/cards) */
    .sa-ai-glow {
      box-shadow: 0 0 0 1px rgba(167,139,250,.18) inset, 0 8px 24px rgba(167,139,250,.08);
    }

    /* ── Live pulse dot ── */
    @keyframes saPulse {
      0%, 100% { box-shadow: 0 0 0 0 var(--teal-glow); opacity: 1; }
      50%      { box-shadow: 0 0 0 5px transparent; opacity: .85; }
    }
    .sa-pulse {
      display: inline-block; width: 8px; height: 8px; border-radius: 50%;
      background: var(--teal);
      animation: saPulse 2s ease-in-out infinite;
    }
    .sa-pulse.violet { background: var(--ai-violet); animation-name: saPulse; }
    .sa-pulse.amber  { background: var(--amber); }
    .sa-pulse.coral  { background: var(--coral); }

    /* ── Scrollbar (dark theme) ───────────────── */
    ::-webkit-scrollbar { width: 8px; height: 8px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: var(--crease); border-radius: 8px; }
    ::-webkit-scrollbar-thumb:hover { background: var(--teal-deep); }

    /* ── Sidebar ─────────────────────────────── */
    .sa-layout { display: flex; min-height: 100vh; }

    .sa-sidebar {
      width: var(--sidebar);
      background: rgba(20,27,45,.6);
      backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
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
      background: var(--grad-brand);
      border-radius: 9px;
      display: flex; align-items: center; justify-content: center;
      font-size: 18px; color: var(--obsidian); font-weight: 800;
      box-shadow: 0 4px 16px var(--teal-glow);
      flex-shrink: 0;
    }
    .sa-sidebar-brand .brand-text { font-size: 13px; font-weight: 800; color: var(--glow); letter-spacing: .02em; line-height: 1.2; }
    .sa-sidebar-brand .brand-text small { font-size: 9px; font-family: var(--mono); font-weight: 600; letter-spacing: .12em; color: var(--teal); text-transform: uppercase; display: block; }

    .sa-sidebar-nav { flex: 1; overflow-y: auto; padding: 10px 8px; }
    .sa-nav-section {
      font-size: 9px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase;
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
    .sa-nav-link:hover { color: var(--glow); background: var(--slate-elev); }
    .sa-nav-link.active {
      color: var(--teal);
      background: var(--teal-faint);
      font-weight: 600;
    }
    .sa-nav-link.active::before {
      content: '';
      position: absolute; left: -8px; top: 6px; bottom: 6px;
      width: 3px; background: var(--teal); border-radius: 0 3px 3px 0;
      box-shadow: 0 0 8px var(--teal-glow);
    }
    .sa-nav-link.active .icon { opacity: 1; }

    .sa-sidebar-footer {
      padding: 12px 14px;
      border-top: 1px solid var(--crease);
      flex-shrink: 0;
    }
    .sa-admin-info { font-size: 12px; color: var(--ash); margin-bottom: 10px; }
    .sa-admin-info strong { display: block; color: var(--glow); font-size: 13px; font-weight: 700; }
    .sa-logout-btn {
      display: block; width: 100%;
      padding: 8px 12px;
      background: rgba(244,63,94,.08); border: 1px solid rgba(244,63,94,.22);
      color: var(--coral); font-size: 12.5px; font-weight: 600;
      border-radius: var(--r); text-align: center; text-decoration: none;
      transition: all .15s; cursor: pointer; letter-spacing: .01em;
    }
    .sa-logout-btn:hover { background: rgba(244,63,94,.16); border-color: rgba(244,63,94,.4); }

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
      background: rgba(10,15,31,.75);
      backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
      border-bottom: 1px solid var(--crease);
      display: flex; align-items: center; justify-content: space-between;
      padding: 0 28px;
      position: sticky; top: 0; z-index: 50;
    }
    .sa-topbar-title {
      font-family: var(--display);
      font-size: 18px; font-weight: 700; color: var(--glow);
      letter-spacing: -.022em;
    }
    .sa-topbar-right { display: flex; align-items: center; gap: 14px; font-size: 13px; color: var(--ash); }
    .sa-topbar-right a { transition: color .15s; color: var(--ash); }
    .sa-topbar-right a:hover { color: var(--coral); }
    .sa-hamburger {
      display: none; background: none; border: none; color: var(--glow);
      font-size: 22px; cursor: pointer; padding: 4px;
    }

    .sa-content { flex: 1; padding: 32px 32px 48px; }

    /* ── Page header ─────────────────────────── */
    .sa-page-header { margin-bottom: 28px; }
    .sa-page-header h1 {
      font-family: var(--display);
      font-size: 30px; font-weight: 700; color: var(--glow);
      letter-spacing: -.028em; line-height: 1.1;
    }
    .sa-page-header p { font-size: 14px; color: var(--ash); margin-top: 6px; max-width: 60ch; }

    /* ── Stat cards ──────────────────────────── */
    .sa-stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 14px; margin-bottom: 28px; }
    .sa-stat-card {
      background: linear-gradient(180deg, rgba(28,37,64,.6) 0%, rgba(20,27,45,.4) 100%);
      backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
      border: 1px solid var(--crease);
      border-radius: 14px; padding: 18px 20px 20px;
      position: relative; overflow: hidden;
      transition: border-color .2s ease, transform .2s ease, box-shadow .2s ease;
    }
    .sa-stat-card:hover {
      border-color: rgba(53,232,213,.4);
      transform: translateY(-2px);
      box-shadow: 0 12px 32px rgba(0,0,0,.32), 0 0 0 1px rgba(53,232,213,.16) inset;
    }
    /* Status thread accent strip — luminous */
    .sa-stat-card::before {
      content: '';
      position: absolute; left: 0; top: 16px; bottom: 16px;
      width: 2px; background: var(--teal);
      border-radius: 0 2px 2px 0;
      box-shadow: 0 0 8px var(--teal-glow);
    }
    .sa-stat-card.thread-indigo::before { background: var(--teal); box-shadow: 0 0 8px var(--teal-glow); }
    .sa-stat-card.thread-amber::before  { background: var(--amber); box-shadow: 0 0 8px rgba(245,158,11,.4); }
    .sa-stat-card.thread-coral::before  { background: var(--coral); box-shadow: 0 0 8px rgba(244,63,94,.4); }
    .sa-stat-card.thread-sage::before   { background: var(--sage); box-shadow: 0 0 8px rgba(132,204,22,.4); }
    .sa-stat-card.thread-ash::before    { background: var(--ash-dim); box-shadow: none; }
    .sa-stat-card.thread-ai::before     { background: var(--ai-violet); box-shadow: 0 0 8px var(--ai-glow); }
    .sa-stat-card .label {
      font-size: 10.5px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
      color: var(--ash); margin-bottom: 12px;
    }
    .sa-stat-card .value {
      font-family: var(--display);
      font-size: 30px; font-weight: 700; color: var(--glow);
      letter-spacing: -.028em; line-height: 1;
    }
    .sa-stat-card .sub { font-size: 11.5px; color: var(--ash); margin-top: 6px; font-weight: 500; }
    .sa-stat-card .icon-bg {
      position: absolute; right: 14px; top: 14px;
      font-size: 30px; opacity: .12;
      pointer-events: none;
    }
    /* Status-themed stat cards (luminous tint on dark) */
    .sa-stat-card.indigo { background: linear-gradient(180deg, rgba(53,232,213,.08) 0%, rgba(20,27,45,.4) 100%); }
    .sa-stat-card.indigo::before { background: var(--teal); box-shadow: 0 0 8px var(--teal-glow); }
    .sa-stat-card.green  { background: linear-gradient(180deg, rgba(132,204,22,.08) 0%, rgba(20,27,45,.4) 100%); }
    .sa-stat-card.green::before { background: var(--sage); box-shadow: 0 0 8px rgba(132,204,22,.4); }
    .sa-stat-card.yellow { background: linear-gradient(180deg, rgba(245,158,11,.08) 0%, rgba(20,27,45,.4) 100%); }
    .sa-stat-card.yellow::before { background: var(--amber); box-shadow: 0 0 8px rgba(245,158,11,.4); }
    .sa-stat-card.red    { background: linear-gradient(180deg, rgba(244,63,94,.08) 0%, rgba(20,27,45,.4) 100%); }
    .sa-stat-card.red::before { background: var(--coral); box-shadow: 0 0 8px rgba(244,63,94,.4); }
    .sa-stat-card.blue   { background: linear-gradient(180deg, rgba(167,139,250,.08) 0%, rgba(20,27,45,.4) 100%); }
    .sa-stat-card.blue::before { background: var(--ai-violet); box-shadow: 0 0 8px var(--ai-glow); }

    /* ── sa-mini-grid: inline metric rows ── */
    .sa-mini-grid { display: grid; gap: 12px; margin-bottom: 14px; }
    .sa-mini-stat { text-align: center; }
    .sa-mini-stat .val {
      font-family: var(--display);
      font-size: 26px; font-weight: 700; color: var(--glow);
      letter-spacing: -.028em; line-height: 1.05;
    }
    .sa-mini-stat .lbl {
      font-size: 11px; color: var(--ash);
      margin-top: 4px; line-height: 1.2; font-weight: 500;
    }
    .sa-mini-stat.red    .val { color: var(--coral); }
    .sa-mini-stat.indigo .val { color: var(--teal); }
    .sa-mini-stat.green  .val { color: var(--sage); }
    .sa-mini-stat.yellow .val { color: var(--amber); }

    /* ── Cards ───────────────────────────────── */
    .sa-card {
      background: rgba(20,27,45,.5);
      backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
      border: 1px solid var(--crease);
      border-radius: 14px; overflow: hidden;
      margin-bottom: 24px;
    }
    .sa-card-header, .sa-card-head {
      padding: 18px 24px;
      border-bottom: 1px solid var(--crease);
      display: flex; align-items: center; justify-content: space-between;
      gap: 12px; flex-wrap: wrap;
    }
    .sa-card-header h3, .sa-card-head h3 {
      font-family: var(--display);
      font-size: 16px; font-weight: 700; color: var(--glow);
      letter-spacing: -.022em;
      display: inline-flex; align-items: center; gap: 8px;
    }
    .sa-card-body { padding: 22px 24px; }
    /* Description paragraph following card-head — match horizontal padding */
    .sa-card-head + p { padding: 14px 24px 4px; font-size: 13px; color: var(--ash); line-height: 1.5; margin: 0 !important; }

    /* chart-style card */
    .sa-chart-card {
      background: rgba(20,27,45,.5);
      backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
      border: 1px solid var(--crease);
      border-radius: 14px; padding: 22px 24px;
      margin-bottom: 24px;
    }
    .sa-chart-card h3 {
      font-family: var(--display);
      font-size: 15px; font-weight: 700; color: var(--glow);
      margin-bottom: 16px; letter-spacing: -.022em;
    }

    /* ── Tables ──────────────────────────────── */
    .sa-table-wrap { overflow-x: auto; }
    .sa-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .sa-table th {
      padding: 12px 16px; text-align: left;
      font-size: 10px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
      color: var(--ash);
      border-bottom: 1px solid var(--crease);
      background: rgba(10,15,31,.4);
    }
    .sa-table td {
      padding: 14px 16px;
      border-bottom: 1px solid rgba(37,45,69,.5);
      color: var(--ink-soft);
      vertical-align: middle;
    }
    /* Breathing room: edge cells get extra outer padding */
    .sa-table th:first-child, .sa-table td:first-child { padding-left: 24px; }
    .sa-table th:last-child,  .sa-table td:last-child  { padding-right: 24px; }
    .sa-table tr:last-child td { border-bottom: none; }
    .sa-table tr:hover td { background: rgba(53,232,213,.04); }
    .sa-table a { color: var(--teal); transition: color .12s; text-decoration: none; }
    .sa-table a:hover { color: var(--teal-deep); text-decoration: underline; text-underline-offset: 3px; }
    .sa-table .num, .sa-table .mono { font-family: var(--mono); font-variant-numeric: tabular-nums; font-size: 12.5px; color: var(--glow); }

    /* Status thread on table rows */
    .sa-table tr[class*="thread-"] td:first-child {
      position: relative; padding-left: 22px;
    }
    .sa-table tr[class*="thread-"] td:first-child::before {
      content: ''; position: absolute; left: 6px; top: 9px; bottom: 9px;
      width: 2px; border-radius: 0 2px 2px 0;
    }
    .sa-table tr.thread-indigo td:first-child::before { background: var(--teal); box-shadow: 0 0 6px var(--teal-glow); }
    .sa-table tr.thread-amber  td:first-child::before { background: var(--amber); }
    .sa-table tr.thread-coral  td:first-child::before { background: var(--coral); }
    .sa-table tr.thread-sage   td:first-child::before { background: var(--sage); }
    .sa-table tr.thread-ash    td:first-child::before { background: var(--ash-dim); }

    /* ── Badges (dark theme glow) ─────────────── */
    .sa-badge {
      display: inline-flex; align-items: center; gap: 4px;
      padding: 3px 9px; border-radius: 20px;
      font-size: 11px; font-weight: 600; letter-spacing: .02em;
      white-space: nowrap;
    }
    .sa-badge-active    { background: var(--sage-l); color: var(--sage); border: 1px solid rgba(132,204,22,.32); }
    .sa-badge-trial     { background: var(--teal-faint); color: var(--teal); border: 1px solid rgba(53,232,213,.32); }
    .sa-badge-suspended { background: var(--coral-l); color: var(--coral); border: 1px solid rgba(244,63,94,.32); }
    .sa-badge-indigo    { background: var(--teal-faint); color: var(--teal); border: 1px solid rgba(53,232,213,.32); }
    .sa-badge-yellow    { background: var(--amber-l); color: var(--amber); border: 1px solid rgba(245,158,11,.32); }
    .sa-badge-red       { background: var(--coral-l); color: var(--coral); border: 1px solid rgba(244,63,94,.32); }
    .sa-badge-blue      { background: var(--ai-glow); color: var(--ai-violet); border: 1px solid rgba(167,139,250,.32); }
    .sa-badge-ai        { background: var(--ai-glow); color: var(--ai-violet); border: 1px solid rgba(167,139,250,.32); }

    /* ── Buttons ─────────────────────────────── */
    .sa-btn {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 8px 16px; border-radius: var(--r);
      font-family: var(--font); font-size: 13px; font-weight: 600;
      border: none; cursor: pointer; text-decoration: none;
      transition: transform .12s, background .15s, border-color .15s, color .15s, box-shadow .15s;
      white-space: nowrap; line-height: 1;
    }
    .sa-btn:focus-visible { outline: 2px solid var(--teal); outline-offset: 2px; }
    .sa-btn-primary {
      background: var(--grad-brand);
      color: var(--obsidian);
      font-weight: 700;
      box-shadow: 0 4px 14px var(--teal-glow);
    }
    .sa-btn-primary:hover { box-shadow: 0 6px 22px var(--teal-glow); transform: translateY(-1px); }
    .sa-btn-outline {
      background: rgba(28,37,64,.4); border: 1px solid var(--crease);
      color: var(--ink-soft);
    }
    .sa-btn-outline:hover { border-color: var(--teal); color: var(--teal); background: var(--teal-faint); }
    .sa-btn-sm { padding: 6px 12px; font-size: 11.5px; border-radius: 7px; }
    .sa-btn-danger { background: rgba(244,63,94,.10); border: 1px solid rgba(244,63,94,.30); color: var(--coral); }
    .sa-btn-danger:hover { background: rgba(244,63,94,.18); border-color: rgba(244,63,94,.5); }
    .sa-btn-green { background: rgba(132,204,22,.10); border: 1px solid rgba(132,204,22,.30); color: var(--sage); }
    .sa-btn-green:hover { background: rgba(132,204,22,.18); border-color: rgba(132,204,22,.5); }
    .sa-btn-wa { background: rgba(34,197,94,.10); border: 1px solid rgba(34,197,94,.30); color: #4ADE80; }
    .sa-btn-wa:hover { background: rgba(34,197,94,.18); border-color: rgba(34,197,94,.5); }
    .sa-btn-ai {
      background: linear-gradient(135deg, var(--teal-faint), var(--ai-glow));
      border: 1px solid rgba(167,139,250,.35);
      color: var(--ai-violet);
    }
    .sa-btn-ai:hover { border-color: var(--ai-violet); box-shadow: 0 0 0 3px var(--ai-glow); }

    /* ── Filter bar ──────────────────────────── */
    .sa-filter-bar {
      display: flex; flex-wrap: wrap; gap: 10px;
      padding: 14px 22px;
      background: rgba(10,15,31,.3);
      border-bottom: 1px solid var(--crease);
    }
    .sa-filter-bar input, .sa-filter-bar select {
      padding: 8px 12px;
      background: rgba(28,37,64,.5); border: 1px solid var(--crease);
      border-radius: 8px; color: var(--glow);
      font-family: var(--font); font-size: 13px; outline: none;
      transition: border-color .15s, box-shadow .15s;
    }
    .sa-filter-bar input:focus, .sa-filter-bar select:focus {
      border-color: var(--teal); box-shadow: 0 0 0 3px var(--teal-faint);
    }
    .sa-filter-bar input::placeholder { color: var(--ash-dim); }
    .sa-filter-bar select option { background: var(--slate); color: var(--glow); }

    /* ── Alert banner ───────────────── */
    .sa-alert-banner {
      display: flex; align-items: center; gap: 12px;
      padding: 13px 18px; border-radius: 10px;
      font-size: 13px; font-weight: 500; margin-bottom: 18px;
      border: 1px solid;
    }
    .sa-alert-banner.warn   { background: var(--amber-l); border-color: rgba(245,158,11,.32); color: var(--amber); }
    .sa-alert-banner.danger { background: var(--coral-l); border-color: rgba(244,63,94,.32); color: var(--coral); }
    .sa-alert-banner.info   { background: var(--teal-faint); border-color: rgba(53,232,213,.32); color: var(--teal); }
    .sa-alert-banner.ai     { background: var(--ai-glow); border-color: rgba(167,139,250,.32); color: var(--ai-violet); }
    .sa-alert-banner a { color: inherit; font-weight: 700; text-decoration: underline; text-underline-offset: 2px; }

    /* ── Modals ──────────────────────────────── */
    .sa-modal-overlay {
      display: none; position: fixed; inset: 0; z-index: 1000;
      background: rgba(10,15,31,.7); backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      align-items: center; justify-content: center;
    }
    .sa-modal-overlay.open, .sa-modal-overlay.show { display: flex; }
    .sa-modal {
      background: linear-gradient(180deg, var(--slate-elev) 0%, var(--slate) 100%);
      border: 1px solid var(--crease);
      border-radius: 16px; padding: 28px; width: 100%; max-width: 480px;
      margin: 20px;
      box-shadow: 0 24px 64px rgba(0,0,0,.5), 0 0 0 1px rgba(53,232,213,.06) inset;
      animation: saModalIn .25s cubic-bezier(.17,.67,.35,1.1);
    }
    @keyframes saModalIn { from { opacity:0; transform: scale(.96) translateY(8px); } }
    .sa-modal h3 {
      font-family: var(--display);
      font-size: 19px; font-weight: 700; color: var(--glow);
      margin-bottom: 18px; letter-spacing: -.022em;
    }
    .sa-modal .form-group { display: flex; flex-direction: column; gap: 7px; margin-bottom: 16px; }
    .sa-modal label {
      font-size: 11px; font-weight: 700; letter-spacing: .1em;
      text-transform: uppercase; color: var(--ash);
    }
    .sa-modal input, .sa-modal textarea, .sa-modal select {
      padding: 10px 14px;
      background: rgba(28,37,64,.5); border: 1px solid var(--crease);
      border-radius: 8px; color: var(--glow);
      font-family: var(--font); font-size: 14px; outline: none;
      transition: border-color .15s, box-shadow .15s;
    }
    .sa-modal input:focus, .sa-modal textarea:focus, .sa-modal select:focus {
      border-color: var(--teal); box-shadow: 0 0 0 3px var(--teal-faint);
    }
    .sa-modal input[readonly] { background: rgba(10,15,31,.5); color: var(--ash); cursor: not-allowed; }
    .sa-modal textarea { resize: vertical; min-height: 90px; }
    .sa-modal select option { background: var(--slate); color: var(--glow); }
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
      background: rgba(28,37,64,.4); border: 1px solid var(--crease);
      margin-bottom: 8px; font-size: 13px;
      transition: background .12s, border-color .12s;
    }
    .sa-alert-item:hover { background: var(--slate-elev); border-color: rgba(53,232,213,.3); }
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
    .sa-tab:hover { color: var(--glow); }
    .sa-tab.active { color: var(--teal); border-bottom-color: var(--teal); }

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

    /* ── Coin color ───────────── */
    .coin-kritis { color: var(--coral); font-weight: 700; }
    .coin-rendah { color: var(--amber); font-weight: 600; }
    .coin-ok     { color: var(--sage); font-weight: 600; }

    /* ── Risk badges (dark glow) ──────────────── */
    .sa-risk-badge {
      display: inline-flex; align-items: center; gap: 4px;
      padding: 2px 8px; border-radius: 20px;
      font-size: 10.5px; font-weight: 600; margin: 2px;
      border: 1px solid;
    }
    .risk-tidak-login { background: var(--coral-l); color: var(--coral); border-color: rgba(244,63,94,.3); }
    .risk-coin        { background: var(--amber-l); color: var(--amber); border-color: rgba(245,158,11,.3); }
    .risk-trial       { background: var(--teal-faint); color: var(--teal); border-color: rgba(53,232,213,.3); }
    .risk-no-topup    { background: rgba(28,37,64,.5); color: var(--ash); border-color: var(--crease); }
    .risk-order-turun { background: var(--coral-l); color: var(--coral); border-color: rgba(244,63,94,.3); }

    /* ── Mobile ──────────────────────────────── */
    @media (max-width: 900px) {
      .sa-sidebar { transform: translateX(-100%); background: rgba(10,15,31,.92); }
      .sa-sidebar.open { transform: translateX(0); box-shadow: 8px 0 40px rgba(0,0,0,.5); }
      .sa-main { margin-left: 0; }
      .sa-hamburger { display: flex; }
      .sa-content { padding: 18px 18px 36px; }
      .sa-stats-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
      .sa-grid-2, .sa-grid-3, .sa-grid-4 { grid-template-columns: 1fr; }
      .sa-topbar { padding: 0 18px; }
      .sa-page-header h1 { font-size: 24px; }
    }
    @media (max-width: 480px) {
      .sa-stats-grid { grid-template-columns: 1fr; }
      .sa-filter-bar { padding: 12px; }
      .sa-page-header h1 { font-size: 22px; }
    }

    .sa-overlay-mobile {
      display: none; position: fixed; inset: 0;
      background: rgba(10,15,31,.6); z-index: 99;
      backdrop-filter: blur(4px);
    }
    .sa-overlay-mobile.open { display: block; }

    /* ── Loading skeleton ────────────────────── */
    @keyframes saSkeleton { 0% { opacity:.4; } 100% { opacity:.8; } }
    .sa-skeleton {
      background: linear-gradient(90deg, var(--crease) 0%, var(--slate-elev) 50%, var(--crease) 100%);
      background-size: 200% 100%;
      border-radius: 6px;
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
        <a href="/superadmin/audit.php" class="sa-nav-link <?= $activePage === 'audit' ? 'active' : '' ?>">
          <span class="icon">📜</span> Audit Log
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
    async function saFetch(url, opts={}){
      // ⚠️ Spread opts DULU, lalu headers — supaya CSRF header tidak ter-overwrite
      // Auto-parses JSON. Returns: {ok:bool, ...payload} on success, {ok:false, error:msg} on failure.
      try {
        const res = await fetch(url, {
          ...opts,
          headers: { 'X-CSRF-Token': saCsrf(), 'X-Requested-With': 'XMLHttpRequest', ...(opts.headers||{}) },
        });
        const json = await res.json().catch(() => ({ error: 'Response bukan JSON valid' }));
        // Normalize: kalau backend gak set "ok" tapi gak ada "error" — anggap ok:true.
        if (typeof json.ok === 'undefined') json.ok = !json.error;
        return json;
      } catch (e) {
        return { ok: false, error: 'Network error: ' + e.message };
      }
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

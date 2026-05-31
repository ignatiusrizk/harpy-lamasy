<?php
// ══════════════════════════════════════════════════════
// harpy/landing.php — Marketing Landing Page
// Harpy Laundry ERP — Halaman Utama
// ══════════════════════════════════════════════════════
date_default_timezone_set('Asia/Jakarta');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<meta name="description" content="LAMASY — ERP Laundry modern dengan AI terintegrasi. POS, Laporan Keuangan SAK EMKM, manajemen karyawan, integrasi WhatsApp lengkap. Trial 30 hari gratis, bayar sesuai pemakaian via Coin — tanpa langganan bulanan."/>
<meta name="keywords" content="software laundry, ERP laundry, aplikasi laundry, POS laundry, manajemen laundry, AI laundry, laporan keuangan laundry, SAK EMKM laundry"/>
<title>LAMASY — ERP Laundry Modern dengan AI Terintegrasi</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
/* ══════════════════════════════════════════════════════
   CSS Variables & Reset
══════════════════════════════════════════════════════ */
:root {
  --teal:    #35E8D5;
  --teal-d:  #1BC4B3;
  --teal-l:  rgba(53,232,213,.12);
  --teal-g:  rgba(53,232,213,.25);
  --navy:    #1B2D5A;
  --navy-d:  #0F1C3A;
  --navy-m:  #162348;
  --navy-c:  #0B1630;
  --white:   #FFFFFF;
  --gray:    #6C7A8D;
  --font:    'Plus Jakarta Sans', sans-serif;
  --mono:    'DM Mono', monospace;
  --r:       12px;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
  font-family: var(--font);
  background: var(--navy-d);
  color: var(--white);
  overflow-x: hidden;
}
a { text-decoration: none; color: inherit; }
img { max-width: 100%; }

/* ══════════════════════════════════════════════════════
   Background Grid
══════════════════════════════════════════════════════ */
.bg-grid {
  position: fixed; inset: 0; pointer-events: none; z-index: 0;
  background-image:
    linear-gradient(rgba(255,255,255,.022) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,.022) 1px, transparent 1px);
  background-size: 52px 52px;
}

/* ══════════════════════════════════════════════════════
   Animated Orbs
══════════════════════════════════════════════════════ */
.orb {
  position: absolute; border-radius: 50%; filter: blur(90px); pointer-events: none;
}
.orb-teal {
  width: 500px; height: 500px;
  background: radial-gradient(circle, rgba(53,232,213,.18) 0%, transparent 70%);
  animation: floatOrb 14s ease-in-out infinite;
}
.orb-blue {
  width: 400px; height: 400px;
  background: radial-gradient(circle, rgba(27,45,90,.6) 0%, transparent 70%);
  animation: floatOrb 18s ease-in-out infinite reverse;
}
@keyframes floatOrb {
  0%,100% { transform: translate(0,0); }
  33%      { transform: translate(30px,-25px); }
  66%      { transform: translate(-20px,20px); }
}

/* ══════════════════════════════════════════════════════
   Navbar
══════════════════════════════════════════════════════ */
.navbar {
  position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
  padding: 0 24px;
  height: 68px;
  display: flex; align-items: center; justify-content: space-between;
  background: rgba(15,28,58,.85);
  backdrop-filter: blur(20px);
  border-bottom: 1px solid rgba(255,255,255,.07);
  transition: background .3s;
}
.nav-logo {
  display: flex; align-items: center; gap: 10px;
  font-size: 20px; font-weight: 800; color: var(--white); letter-spacing: .01em;
}
.nav-logo .logo-badge {
  width: 38px; height: 38px;
  background: linear-gradient(135deg, var(--teal), var(--teal-d));
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 20px;
  box-shadow: 0 4px 16px rgba(53,232,213,.3);
  flex-shrink: 0;
}
.nav-logo span { color: var(--teal); }
.nav-links {
  display: flex; align-items: center; gap: 6px;
  list-style: none;
}
.nav-links a {
  padding: 8px 14px;
  font-size: 14px; font-weight: 500;
  color: rgba(255,255,255,.65);
  border-radius: 8px;
  transition: all .15s;
}
.nav-links a:hover { color: var(--white); background: rgba(255,255,255,.06); }
.nav-actions {
  display: flex; align-items: center; gap: 10px;
}
.btn-login-nav {
  padding: 8px 18px;
  font-size: 14px; font-weight: 600;
  color: rgba(255,255,255,.7);
  border: 1.5px solid rgba(255,255,255,.15);
  border-radius: 9px; cursor: pointer;
  transition: all .15s;
}
.btn-login-nav:hover { color: var(--white); border-color: rgba(255,255,255,.35); background: rgba(255,255,255,.05); }
.btn-cta-nav {
  padding: 9px 20px;
  font-size: 14px; font-weight: 700;
  color: var(--navy-d);
  background: linear-gradient(135deg, var(--teal), var(--teal-d));
  border-radius: 9px; cursor: pointer;
  box-shadow: 0 4px 16px rgba(53,232,213,.25);
  transition: all .15s;
}
.btn-cta-nav:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(53,232,213,.4); }
.nav-toggle {
  display: none;
  background: none; border: none;
  font-size: 22px; cursor: pointer; color: var(--white);
}
.nav-mobile-menu {
  display: none;
  position: fixed; top: 68px; left: 0; right: 0;
  background: rgba(15,28,58,.97);
  backdrop-filter: blur(20px);
  border-bottom: 1px solid rgba(255,255,255,.08);
  padding: 16px 24px 20px;
  flex-direction: column; gap: 4px;
  z-index: 999;
}
.nav-mobile-menu.open { display: flex; }
.nav-mobile-menu a {
  padding: 12px 14px;
  font-size: 15px; font-weight: 500;
  color: rgba(255,255,255,.7);
  border-radius: 9px; transition: all .15s;
}
.nav-mobile-menu a:hover { color: var(--white); background: rgba(255,255,255,.06); }
.nav-mobile-divider { height: 1px; background: rgba(255,255,255,.07); margin: 8px 0; }

/* ══════════════════════════════════════════════════════
   Hero Section
══════════════════════════════════════════════════════ */
.hero {
  position: relative; z-index: 1;
  padding: 140px 24px 80px;
  text-align: center;
  overflow: hidden;
}
.hero-orb-wrap {
  position: absolute; inset: 0; pointer-events: none;
  overflow: hidden;
}
.hero-badge {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 6px 16px; border-radius: 100px;
  background: rgba(53,232,213,.1);
  border: 1px solid rgba(53,232,213,.25);
  font-size: 12px; font-weight: 600; color: var(--teal);
  margin-bottom: 24px;
  letter-spacing: .04em;
}
.hero-badge .dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: var(--teal);
  box-shadow: 0 0 8px var(--teal);
  animation: pulse 2s infinite;
}
@keyframes pulse {
  0%,100% { opacity: 1; transform: scale(1); }
  50%      { opacity: .5; transform: scale(1.3); }
}
.hero h1 {
  font-size: clamp(32px, 5.5vw, 62px);
  font-weight: 800;
  line-height: 1.12;
  max-width: 760px; margin: 0 auto 22px;
  letter-spacing: -.02em;
}
.hero h1 .accent { color: var(--teal); }
.hero-sub {
  font-size: clamp(15px, 2vw, 18px);
  color: rgba(255,255,255,.55);
  max-width: 540px; margin: 0 auto 36px;
  line-height: 1.6;
}
.hero-btns {
  display: flex; align-items: center; justify-content: center;
  gap: 14px; flex-wrap: wrap;
  margin-bottom: 60px;
}
.btn-primary {
  padding: 14px 30px;
  font-family: var(--font); font-size: 15px; font-weight: 700;
  color: var(--navy-d);
  background: linear-gradient(135deg, var(--teal), var(--teal-d));
  border: none; border-radius: 11px; cursor: pointer;
  box-shadow: 0 6px 24px rgba(53,232,213,.3);
  transition: all .2s;
  display: inline-flex; align-items: center; gap: 8px;
}
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 32px rgba(53,232,213,.45); }
.btn-secondary {
  padding: 13px 28px;
  font-family: var(--font); font-size: 15px; font-weight: 600;
  color: rgba(255,255,255,.8);
  background: rgba(255,255,255,.06);
  border: 1.5px solid rgba(255,255,255,.15);
  border-radius: 11px; cursor: pointer;
  transition: all .2s;
  display: inline-flex; align-items: center; gap: 8px;
}
.btn-secondary:hover { color: var(--white); border-color: rgba(255,255,255,.3); background: rgba(255,255,255,.1); transform: translateY(-1px); }

/* Hero mockup dashboard */
.hero-mockup {
  max-width: 860px; margin: 0 auto;
  background: rgba(255,255,255,.04);
  border: 1px solid rgba(255,255,255,.1);
  border-radius: 20px;
  overflow: hidden;
  box-shadow: 0 40px 100px rgba(0,0,0,.5), 0 0 0 1px rgba(53,232,213,.1);
  position: relative;
}
.mockup-topbar {
  padding: 14px 18px;
  background: rgba(255,255,255,.04);
  border-bottom: 1px solid rgba(255,255,255,.07);
  display: flex; align-items: center; gap: 8px;
}
.mockup-dot { width: 11px; height: 11px; border-radius: 50%; }
.mockup-dot:nth-child(1) { background: #FF5F57; }
.mockup-dot:nth-child(2) { background: #FEBC2E; }
.mockup-dot:nth-child(3) { background: #28C840; }
.mockup-url {
  margin-left: 12px;
  flex: 1; max-width: 280px;
  padding: 5px 14px;
  background: rgba(255,255,255,.06);
  border-radius: 6px;
  font-family: var(--mono); font-size: 11px;
  color: rgba(255,255,255,.35);
}
.mockup-body {
  display: grid; grid-template-columns: 180px 1fr;
  min-height: 300px;
}
.mockup-sidebar {
  background: rgba(255,255,255,.025);
  border-right: 1px solid rgba(255,255,255,.06);
  padding: 16px 12px;
}
.mockup-sidebar-logo {
  display: flex; align-items: center; gap: 8px;
  margin-bottom: 20px; padding: 0 4px;
}
.mockup-sidebar-logo .mb { width: 24px; height: 24px; border-radius: 6px; background: linear-gradient(135deg, var(--teal), var(--teal-d)); }
.mockup-sidebar-logo span { font-size: 12px; font-weight: 800; }
.mockup-nav-item {
  padding: 8px 10px; border-radius: 7px;
  font-size: 11px; font-weight: 500;
  color: rgba(255,255,255,.4);
  margin-bottom: 3px;
  display: flex; align-items: center; gap: 8px;
}
.mockup-nav-item.active { background: var(--teal-l); color: var(--teal); }
.mockup-nav-item .ni { font-size: 13px; }
.mockup-content { padding: 18px; }
.mockup-content-title { font-size: 13px; font-weight: 800; margin-bottom: 14px; }
.mockup-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 14px; }
.mockup-stat-card {
  background: rgba(255,255,255,.04);
  border: 1px solid rgba(255,255,255,.07);
  border-radius: 10px; padding: 10px 12px;
}
.mockup-stat-card .ms-label { font-size: 9px; color: rgba(255,255,255,.35); margin-bottom: 4px; font-weight: 600; }
.mockup-stat-card .ms-val { font-size: 14px; font-weight: 800; }
.mockup-stat-card .ms-val.green { color: var(--teal); }
.mockup-stat-card .ms-val.blue  { color: #7DD3FC; }
.mockup-stat-card .ms-val.orange { color: #FCD34D; }
.mockup-orders { background: rgba(255,255,255,.02); border: 1px solid rgba(255,255,255,.06); border-radius: 10px; overflow: hidden; }
.mockup-order-row {
  display: grid; grid-template-columns: 1fr 70px 70px;
  padding: 8px 12px; font-size: 10px; font-weight: 500;
  border-bottom: 1px solid rgba(255,255,255,.04);
  color: rgba(255,255,255,.6);
  align-items: center;
}
.mockup-order-row.header { font-size: 9px; font-weight: 700; color: rgba(255,255,255,.3); text-transform: uppercase; letter-spacing: .06em; }
.status-badge { padding: 2px 7px; border-radius: 5px; font-size: 9px; font-weight: 700; }
.status-badge.done   { background: rgba(53,232,213,.15); color: var(--teal); }
.status-badge.proc   { background: rgba(253,224,71,.12); color: #FDE047; }
.status-badge.ready  { background: rgba(99,102,241,.15); color: #A5B4FC; }

/* ══════════════════════════════════════════════════════
   Stats Bar
══════════════════════════════════════════════════════ */
.stats-bar {
  position: relative; z-index: 1;
  padding: 0 24px 70px;
}
.stats-inner {
  max-width: 860px; margin: 0 auto;
  background: rgba(53,232,213,.06);
  border: 1px solid rgba(53,232,213,.15);
  border-radius: 16px;
  display: grid; grid-template-columns: repeat(3, 1fr);
  overflow: hidden;
}
.stat-item {
  padding: 28px 24px;
  text-align: center;
  border-right: 1px solid rgba(53,232,213,.1);
}
.stat-item:last-child { border-right: none; }
.stat-num {
  font-size: 36px; font-weight: 800;
  color: var(--teal); font-family: var(--mono);
  margin-bottom: 6px; line-height: 1;
}
.stat-label {
  font-size: 13px; font-weight: 500;
  color: rgba(255,255,255,.55);
}

/* ══════════════════════════════════════════════════════
   Section Shared
══════════════════════════════════════════════════════ */
.section {
  position: relative; z-index: 1;
  padding: 80px 24px;
  max-width: 1100px; margin: 0 auto;
}
.section-header {
  text-align: center;
  margin-bottom: 56px;
}
.section-tag {
  display: inline-block;
  font-family: var(--mono); font-size: 11px; font-weight: 500;
  letter-spacing: .14em; text-transform: uppercase;
  color: var(--teal); margin-bottom: 14px;
}
.section-header h2 {
  font-size: clamp(26px, 4vw, 40px);
  font-weight: 800; letter-spacing: -.02em;
  margin-bottom: 14px;
}
.section-header p {
  font-size: 16px; color: rgba(255,255,255,.5);
  max-width: 520px; margin: 0 auto; line-height: 1.65;
}

/* ══════════════════════════════════════════════════════
   AI Hero Section
══════════════════════════════════════════════════════ */
.ai-section {
  position: relative; z-index: 1;
  padding: 0 24px 80px;
}
.ai-inner {
  max-width: 1100px; margin: 0 auto;
}
.ai-badge-row {
  display: flex; align-items: center; justify-content: center;
  gap: 10px; margin-bottom: 20px;
}
.ai-badge {
  display: inline-flex; align-items: center; gap: 6px;
  background: linear-gradient(135deg, rgba(139,92,246,.18), rgba(99,102,241,.12));
  border: 1px solid rgba(139,92,246,.35);
  border-radius: 100px;
  padding: 5px 14px; font-size: 11px; font-weight: 700;
  letter-spacing: .1em; text-transform: uppercase; color: #C4B5FD;
  font-family: var(--mono);
}
.ai-badge .pulse {
  width: 7px; height: 7px; border-radius: 50%;
  background: #8B5CF6;
  box-shadow: 0 0 6px rgba(139,92,246,.8);
  animation: pulse 1.8s ease-in-out infinite;
}
@keyframes pulse {
  0%,100% { opacity: 1; transform: scale(1); }
  50%      { opacity: .5; transform: scale(.75); }
}
.ai-hero-card {
  background: linear-gradient(135deg,
    rgba(139,92,246,.1) 0%,
    rgba(99,102,241,.08) 40%,
    rgba(27,45,90,.3) 100%);
  border: 1.5px solid rgba(139,92,246,.25);
  border-radius: 24px;
  padding: 56px 48px;
  position: relative; overflow: hidden;
  display: grid; grid-template-columns: 1fr 1fr;
  gap: 48px; align-items: center;
}
.ai-hero-card::before {
  content: '';
  position: absolute; top: -80px; right: -80px;
  width: 360px; height: 360px; border-radius: 50%;
  background: radial-gradient(circle, rgba(139,92,246,.15) 0%, transparent 70%);
  pointer-events: none;
}
.ai-hero-card::after {
  content: '';
  position: absolute; bottom: -60px; left: -60px;
  width: 260px; height: 260px; border-radius: 50%;
  background: radial-gradient(circle, rgba(99,102,241,.12) 0%, transparent 70%);
  pointer-events: none;
}
.ai-hero-text { position: relative; z-index: 1; }
.ai-hero-text h2 {
  font-size: clamp(24px, 3.5vw, 38px);
  font-weight: 800; letter-spacing: -.02em;
  line-height: 1.2; margin-bottom: 16px;
}
.ai-hero-text h2 em {
  font-style: normal;
  background: linear-gradient(135deg, #C4B5FD, #8B5CF6);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}
.ai-hero-text p {
  font-size: 15px; color: rgba(255,255,255,.55);
  line-height: 1.7; margin-bottom: 28px;
}
.ai-features-list {
  display: flex; flex-direction: column; gap: 12px;
}
.ai-feat-item {
  display: flex; align-items: flex-start; gap: 12px;
}
.ai-feat-icon {
  width: 32px; height: 32px; border-radius: 9px; flex-shrink: 0;
  background: rgba(139,92,246,.15);
  border: 1px solid rgba(139,92,246,.25);
  display: flex; align-items: center; justify-content: center;
  font-size: 15px;
}
.ai-feat-text strong { font-size: 13.5px; font-weight: 700; display: block; margin-bottom: 2px; }
.ai-feat-text span   { font-size: 12px; color: rgba(255,255,255,.4); line-height: 1.4; }

/* AI mockup chat */
.ai-mockup {
  position: relative; z-index: 1;
  background: rgba(15,28,58,.7);
  border: 1px solid rgba(255,255,255,.1);
  border-radius: 16px; overflow: hidden;
  box-shadow: 0 24px 60px rgba(0,0,0,.4);
}
.ai-mockup-header {
  padding: 12px 16px;
  background: rgba(139,92,246,.12);
  border-bottom: 1px solid rgba(139,92,246,.2);
  display: flex; align-items: center; gap: 8px;
  font-size: 12px; font-weight: 700; color: #C4B5FD;
}
.ai-mockup-header .ai-dot {
  width: 8px; height: 8px; border-radius: 50%;
  background: #8B5CF6;
  box-shadow: 0 0 8px rgba(139,92,246,.8);
  animation: pulse 1.8s ease-in-out infinite;
}
.ai-chat-body { padding: 16px; display: flex; flex-direction: column; gap: 12px; }
.ai-msg {
  display: flex; gap: 8px; align-items: flex-start;
}
.ai-msg.user { flex-direction: row-reverse; }
.ai-avatar {
  width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0;
  background: rgba(139,92,246,.2);
  border: 1px solid rgba(139,92,246,.3);
  display: flex; align-items: center; justify-content: center;
  font-size: 12px;
}
.ai-msg.user .ai-avatar { background: rgba(53,232,213,.15); border-color: rgba(53,232,213,.3); }
.ai-bubble {
  max-width: 82%;
  padding: 9px 13px; border-radius: 12px;
  font-size: 12px; line-height: 1.5; color: rgba(255,255,255,.85);
  background: rgba(139,92,246,.12);
  border: 1px solid rgba(139,92,246,.18);
}
.ai-msg.user .ai-bubble {
  background: rgba(53,232,213,.08);
  border-color: rgba(53,232,213,.2);
}
.ai-bubble strong { color: #C4B5FD; }
.ai-bubble .teal  { color: var(--teal); font-weight: 600; }
.ai-typing {
  display: flex; align-items: center; gap: 6px;
  padding: 9px 13px; border-radius: 12px;
  background: rgba(139,92,246,.08);
  border: 1px solid rgba(139,92,246,.15);
  width: fit-content;
}
.typing-dot {
  width: 5px; height: 5px; border-radius: 50%;
  background: rgba(139,92,246,.6);
  animation: typingBounce .8s ease-in-out infinite;
}
.typing-dot:nth-child(2) { animation-delay: .15s; }
.typing-dot:nth-child(3) { animation-delay: .30s; }
@keyframes typingBounce {
  0%,100% { transform: translateY(0); opacity: .4; }
  50%      { transform: translateY(-4px); opacity: 1; }
}
.ai-stats-row {
  display: grid; grid-template-columns: repeat(3,1fr);
  gap: 8px; padding: 0 16px 16px;
}
.ai-stat-chip {
  background: rgba(139,92,246,.08);
  border: 1px solid rgba(139,92,246,.15);
  border-radius: 9px; padding: 9px 10px; text-align: center;
}
.ai-stat-chip .val { font-size: 15px; font-weight: 800; color: #C4B5FD; font-family: var(--mono); }
.ai-stat-chip .lbl { font-size: 10px; color: rgba(255,255,255,.35); margin-top: 2px; }

@media (max-width: 768px) {
  .ai-hero-card { grid-template-columns: 1fr; gap: 32px; padding: 36px 24px; }
}

/* ══════════════════════════════════════════════════════
   Features Grid
══════════════════════════════════════════════════════ */
.features-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 20px;
}
.feature-card {
  background: rgba(255,255,255,.03);
  border: 1px solid rgba(255,255,255,.08);
  border-radius: 16px;
  padding: 28px 24px;
  transition: all .25s;
  cursor: default;
}
.feature-card:hover {
  border-color: rgba(53,232,213,.25);
  background: rgba(53,232,213,.04);
  transform: translateY(-3px);
  box-shadow: 0 16px 40px rgba(0,0,0,.25);
}
.feature-icon {
  font-size: 32px; margin-bottom: 16px; display: block;
}
.feature-card h3 {
  font-size: 16px; font-weight: 700;
  margin-bottom: 10px;
}
.feature-card p {
  font-size: 13.5px; color: rgba(255,255,255,.5); line-height: 1.6;
}

/* ══════════════════════════════════════════════════════
   How it Works
══════════════════════════════════════════════════════ */
.how-it-works { background: rgba(255,255,255,.015); }
.steps-row {
  display: grid; grid-template-columns: repeat(3, 1fr);
  gap: 24px; position: relative;
}
.steps-row::before {
  content: '';
  position: absolute; top: 42px; left: 16.5%; right: 16.5%;
  height: 2px;
  background: linear-gradient(90deg, transparent, var(--teal), transparent);
  opacity: .3;
}
.step-card { text-align: center; padding: 32px 20px; }
.step-num-wrap {
  width: 56px; height: 56px; border-radius: 50%;
  background: var(--teal-l);
  border: 2px solid rgba(53,232,213,.25);
  display: flex; align-items: center; justify-content: center;
  font-size: 20px; font-weight: 800; color: var(--teal);
  margin: 0 auto 20px;
  font-family: var(--mono);
  box-shadow: 0 0 24px rgba(53,232,213,.15);
}
.step-card h3 { font-size: 17px; font-weight: 700; margin-bottom: 10px; }
.step-card p  { font-size: 13.5px; color: rgba(255,255,255,.5); line-height: 1.6; }

/* ══════════════════════════════════════════════════════
   Pricing
══════════════════════════════════════════════════════ */
.pricing-grid {
  display: grid; grid-template-columns: repeat(2, 1fr);
  gap: 24px; max-width: 740px; margin: 0 auto;
}
.pricing-card {
  background: rgba(255,255,255,.03);
  border: 1.5px solid rgba(255,255,255,.09);
  border-radius: 20px; padding: 36px 30px;
  position: relative; transition: all .25s;
}
.pricing-card.featured {
  border-color: rgba(53,232,213,.3);
  background: rgba(53,232,213,.04);
  box-shadow: 0 0 0 1px rgba(53,232,213,.12), 0 24px 60px rgba(0,0,0,.25);
}
.pricing-card:hover { transform: translateY(-3px); }
.pricing-badge {
  position: absolute; top: -12px; left: 50%; transform: translateX(-50%);
  padding: 4px 16px; border-radius: 100px;
  background: linear-gradient(135deg, var(--teal), var(--teal-d));
  color: var(--navy-d); font-size: 11px; font-weight: 700; white-space: nowrap;
  letter-spacing: .04em;
}
.pricing-name {
  font-size: 13px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
  color: rgba(255,255,255,.45); margin-bottom: 12px;
}
.pricing-price {
  font-size: 40px; font-weight: 800;
  color: var(--white); margin-bottom: 6px;
  font-family: var(--mono); line-height: 1;
}
.pricing-price sup { font-size: 18px; vertical-align: top; margin-top: 8px; }
.pricing-price .per { font-size: 14px; font-weight: 500; color: rgba(255,255,255,.4); font-family: var(--font); }
.pricing-desc {
  font-size: 13px; color: rgba(255,255,255,.45);
  margin-bottom: 24px; line-height: 1.55;
}
.pricing-divider {
  height: 1px; background: rgba(255,255,255,.07); margin-bottom: 20px;
}
.pricing-features { list-style: none; display: flex; flex-direction: column; gap: 11px; margin-bottom: 28px; }
.pricing-features li {
  font-size: 13.5px; color: rgba(255,255,255,.7);
  display: flex; align-items: flex-start; gap: 10px;
}
.pricing-features li::before {
  content: '✓';
  color: var(--teal); font-weight: 800; flex-shrink: 0;
}
.btn-pricing {
  display: block; width: 100%; text-align: center;
  padding: 13px;
  font-family: var(--font); font-size: 14px; font-weight: 700;
  border-radius: 11px; cursor: pointer; transition: all .2s;
  border: none;
}
.btn-pricing-primary {
  color: var(--navy-d);
  background: linear-gradient(135deg, var(--teal), var(--teal-d));
  box-shadow: 0 4px 18px rgba(53,232,213,.3);
}
.btn-pricing-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(53,232,213,.45); }
.btn-pricing-outline {
  color: rgba(255,255,255,.75);
  background: rgba(255,255,255,.05);
  border: 1.5px solid rgba(255,255,255,.15) !important;
}
.btn-pricing-outline:hover { color: var(--white); background: rgba(255,255,255,.1); border-color: rgba(255,255,255,.3) !important; }

/* ══════════════════════════════════════════════════════
   Testimonials
══════════════════════════════════════════════════════ */
.testimonials-grid {
  display: grid; grid-template-columns: repeat(3, 1fr);
  gap: 20px;
}
.testi-card {
  background: rgba(255,255,255,.03);
  border: 1px solid rgba(255,255,255,.08);
  border-radius: 16px; padding: 26px 22px;
  transition: all .2s;
}
.testi-card:hover {
  border-color: rgba(53,232,213,.15);
  transform: translateY(-2px);
}
.testi-stars { color: #FCD34D; font-size: 14px; margin-bottom: 14px; letter-spacing: 3px; }
.testi-text  {
  font-size: 14px; line-height: 1.7;
  color: rgba(255,255,255,.65);
  margin-bottom: 20px; font-style: italic;
}
.testi-author { display: flex; align-items: center; gap: 12px; }
.testi-avatar {
  width: 40px; height: 40px; border-radius: 50%;
  background: linear-gradient(135deg, var(--teal-l), rgba(53,232,213,.25));
  border: 2px solid rgba(53,232,213,.2);
  display: flex; align-items: center; justify-content: center;
  font-size: 18px; flex-shrink: 0;
}
.testi-name  { font-size: 13px; font-weight: 700; }
.testi-role  { font-size: 11px; color: rgba(255,255,255,.4); margin-top: 2px; }

/* ══════════════════════════════════════════════════════
   CTA Banner
══════════════════════════════════════════════════════ */
.cta-banner {
  position: relative; z-index: 1;
  padding: 80px 24px;
}
.cta-inner {
  max-width: 760px; margin: 0 auto;
  background: linear-gradient(135deg, rgba(53,232,213,.12) 0%, rgba(27,45,90,.4) 100%);
  border: 1.5px solid rgba(53,232,213,.2);
  border-radius: 24px;
  padding: 60px 40px; text-align: center;
  position: relative; overflow: hidden;
}
.cta-inner::before {
  content: '';
  position: absolute; inset: 0;
  background: radial-gradient(ellipse at 50% 0%, rgba(53,232,213,.1), transparent 60%);
  pointer-events: none;
}
.cta-inner h2 {
  font-size: clamp(24px, 4vw, 38px);
  font-weight: 800; margin-bottom: 14px;
  letter-spacing: -.02em;
}
.cta-inner p {
  font-size: 16px; color: rgba(255,255,255,.55);
  margin-bottom: 36px; line-height: 1.6;
}
.cta-btns {
  display: flex; justify-content: center; gap: 14px; flex-wrap: wrap;
}

/* ══════════════════════════════════════════════════════
   Footer
══════════════════════════════════════════════════════ */
.footer {
  position: relative; z-index: 1;
  background: var(--navy-c);
  border-top: 1px solid rgba(255,255,255,.06);
  padding: 50px 24px 30px;
}
.footer-grid {
  max-width: 1100px; margin: 0 auto;
  display: grid; grid-template-columns: 2fr 1fr 1fr 1fr;
  gap: 40px; margin-bottom: 40px;
}
.footer-logo {
  display: flex; align-items: center; gap: 10px;
  font-size: 18px; font-weight: 800; margin-bottom: 14px;
}
.footer-logo .lb {
  width: 32px; height: 32px; border-radius: 8px;
  background: linear-gradient(135deg, var(--teal), var(--teal-d));
  display: flex; align-items: center; justify-content: center;
  font-size: 16px;
}
.footer-tagline { font-size: 13.5px; color: rgba(255,255,255,.4); line-height: 1.65; max-width: 220px; }
.footer-col-title {
  font-size: 11px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
  color: rgba(255,255,255,.3); margin-bottom: 16px;
}
.footer-links { list-style: none; display: flex; flex-direction: column; gap: 10px; }
.footer-links a { font-size: 13.5px; color: rgba(255,255,255,.5); transition: color .15s; }
.footer-links a:hover { color: var(--teal); }
.footer-bottom {
  max-width: 1100px; margin: 0 auto;
  padding-top: 24px;
  border-top: 1px solid rgba(255,255,255,.06);
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 12px;
  font-size: 12px; color: rgba(255,255,255,.3);
}
.footer-bottom a { color: rgba(255,255,255,.4); transition: color .15s; }
.footer-bottom a:hover { color: var(--teal); }

/* ══════════════════════════════════════════════════════
   Responsive
══════════════════════════════════════════════════════ */
@media (max-width: 900px) {
  .features-grid      { grid-template-columns: repeat(2, 1fr); }
  .testimonials-grid  { grid-template-columns: repeat(2, 1fr); }
  .footer-grid        { grid-template-columns: 1fr 1fr; }
  .mockup-body        { grid-template-columns: 1fr; }
  .mockup-sidebar     { display: none; }
  .mockup-stats       { grid-template-columns: repeat(3, 1fr); }
  .steps-row::before  { display: none; }
}
@media (max-width: 640px) {
  .nav-links, .btn-login-nav, .btn-cta-nav { display: none; }
  .nav-toggle    { display: block; }
  .hero          { padding: 120px 20px 60px; }
  .features-grid { grid-template-columns: 1fr; }
  .steps-row     { grid-template-columns: 1fr; }
  .pricing-grid  { grid-template-columns: 1fr; }
  .testimonials-grid { grid-template-columns: 1fr; }
  .footer-grid   { grid-template-columns: 1fr; }
  .stats-inner   { grid-template-columns: 1fr; }
  .stat-item     { border-right: none; border-bottom: 1px solid rgba(53,232,213,.1); }
  .stat-item:last-child { border-bottom: none; }
  .cta-inner     { padding: 40px 24px; }
  .section       { padding: 60px 20px; }
  .mockup-stats  { grid-template-columns: 1fr; }
  .pricing-grid  { grid-template-columns: 1fr !important; }
}

/* ── FAQ ── */
.faq-item button:hover span:first-child { color: #35E8D5; }
</style>
</head>
<body>
<div class="bg-grid"></div>

<!-- ── NAVBAR ──────────────────────────────────────── -->
<nav class="navbar" id="navbar">
  <a href="/landing.php" class="nav-logo">
    <img src="/assets/logo.png" alt="LAMASY" style="height:36px; vertical-align:middle; margin-right:8px;">
    LAMASY
  </a>
  <ul class="nav-links">
    <li><a href="#ai" style="color:#C4B5FD;font-weight:700;">✦ AI</a></li>
    <li><a href="#fitur">Fitur</a></li>
    <li><a href="#harga">Harga</a></li>
    <li><a href="#faq">FAQ</a></li>
    <li><a href="#kontak">Kontak</a></li>
  </ul>
  <div class="nav-actions">
    <a href="/login.php" class="btn-login-nav">Masuk</a>
    <a href="/register.php" class="btn-cta-nav">Mulai Gratis</a>
  </div>
  <button class="nav-toggle" onclick="toggleMobileMenu()" id="navToggle" aria-label="Menu">&#9776;</button>
</nav>

<!-- Mobile Menu -->
<div class="nav-mobile-menu" id="mobileMenu">
  <a href="#fitur" onclick="closeMobileMenu()">Fitur</a>
  <a href="#harga" onclick="closeMobileMenu()">Harga</a>
  <a href="#faq" onclick="closeMobileMenu()">FAQ</a>
  <a href="#kontak" onclick="closeMobileMenu()">Kontak</a>
  <div class="nav-mobile-divider"></div>
  <a href="/login.php">Masuk ke Akun</a>
  <a href="/register.php" style="color:var(--teal);font-weight:700;">&#128640; Mulai Gratis</a>
</div>

<!-- ── HERO ────────────────────────────────────────── -->
<section class="hero">
  <div class="hero-orb-wrap">
    <div class="orb orb-teal" style="top:-100px;right:-80px;"></div>
    <div class="orb orb-blue" style="bottom:-100px;left:-80px;"></div>
  </div>

  <div class="hero-badge">
    <span class="dot"></span>
    Platform Laundry #1 di Indonesia
  </div>

  <h1>ERP Laundry Modern dengan <span class="accent">Kecerdasan AI</span> Terintegrasi</h1>
  <p class="hero-sub">POS, laporan keuangan SAK EMKM, AI briefing harian, integrasi WhatsApp lengkap — semua dalam 1 platform. Bayar sesuai pemakaian, tanpa langganan bulanan yang mengikat.</p>

  <div class="hero-btns">
    <a href="/register.php" class="btn-primary">&#128640; Coba Gratis 7 Hari</a>
    <a href="#fitur" class="btn-secondary">&#9654; Lihat Fitur</a>
  </div>

  <!-- Dashboard Mockup -->
  <div class="hero-mockup">
    <div class="mockup-topbar">
      <div class="mockup-dot"></div>
      <div class="mockup-dot"></div>
      <div class="mockup-dot"></div>
      <div class="mockup-url">lamasy.harpy.id/</div>
    </div>
    <div class="mockup-body">
      <div class="mockup-sidebar">
        <div class="mockup-sidebar-logo">
          <div class="mb"></div>
          <span>LAMASY</span>
        </div>
        <div class="mockup-nav-item active"><span class="ni">📊</span> Dashboard</div>
        <div class="mockup-nav-item"><span class="ni">🛒</span> Order</div>
        <div class="mockup-nav-item"><span class="ni">👥</span> Karyawan</div>
        <div class="mockup-nav-item"><span class="ni">💰</span> Keuangan</div>
        <div class="mockup-nav-item"><span class="ni">📦</span> Layanan</div>
        <div class="mockup-nav-item"><span class="ni">🎟️</span> Promo</div>
        <div class="mockup-nav-item"><span class="ni">⚙️</span> Pengaturan</div>
      </div>
      <div class="mockup-content">
        <div class="mockup-content-title">Dashboard Hari Ini</div>
        <div class="mockup-stats">
          <div class="mockup-stat-card">
            <div class="ms-label">Pendapatan</div>
            <div class="ms-val green">Rp 2,4 Jt</div>
          </div>
          <div class="mockup-stat-card">
            <div class="ms-label">Order Baru</div>
            <div class="ms-val blue">47</div>
          </div>
          <div class="mockup-stat-card">
            <div class="ms-label">Siap Ambil</div>
            <div class="ms-val orange">12</div>
          </div>
        </div>
        <div class="mockup-orders">
          <div class="mockup-order-row header">
            <span>Nama Pelanggan</span>
            <span>Total</span>
            <span>Status</span>
          </div>
          <div class="mockup-order-row">
            <span>Budi Santoso</span>
            <span>Rp 48k</span>
            <span class="status-badge done">Selesai</span>
          </div>
          <div class="mockup-order-row">
            <span>Sari Dewi</span>
            <span>Rp 72k</span>
            <span class="status-badge proc">Proses</span>
          </div>
          <div class="mockup-order-row">
            <span>Ahmad Fauzi</span>
            <span>Rp 35k</span>
            <span class="status-badge ready">Siap</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ── STATS BAR ────────────────────────────────────── -->
<div class="stats-bar">
  <div class="stats-inner">
    <div class="stat-item">
      <div class="stat-num">7 Hari</div>
      <div class="stat-label">Trial Gratis</div>
    </div>
    <div class="stat-item">
      <div class="stat-num">99.9%</div>
      <div class="stat-label">Uptime Terjamin</div>
    </div>
    <div class="stat-item">
      <div class="stat-num">3 Jam</div>
      <div class="stat-label">Hemat per Hari</div>
    </div>
    <div class="stat-item">
      <div class="stat-num" style="color:#C4B5FD;">AI</div>
      <div class="stat-label">Briefing Harian Otomatis</div>
    </div>
  </div>
</div>

<!-- ── PROBLEM SECTION ──────────────────────────────── -->
<section class="section" id="masalah" style="background:rgba(255,255,255,.018)">
  <div class="section-header">
    <div class="section-tag" style="background:rgba(239,68,68,.12);color:#f87171;border-color:rgba(239,68,68,.2)">Kenali Masalahnya</div>
    <h2>Apakah Ini Cerita Bisnis Laundry Kamu?</h2>
    <p>Banyak pemilik laundry masih bergulat dengan masalah yang sama setiap hari.</p>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;max-width:1000px;margin:0 auto">
    <?php
    $problems = [
      ['🗓️', 'Laporan Masih Manual', 'Rekap order, kas, dan gaji karyawan dilakukan di buku atau Excel. Rawan salah, buang waktu berjam-jam.'],
      ['📱', 'Lupa Kabari Pelanggan', 'Pelanggan komplain cuciannya sudah jadi tapi tidak ada notifikasi. Reputasi outlet jadi taruhan.'],
      ['👀', 'Susah Pantau Karyawan', 'Tidak tahu siapa yang terlambat, siapa yang produktif, dan berapa upah yang harus dibayar bulan ini.'],
      ['📊', 'Tidak Tahu Kondisi Bisnis', 'Apakah outlet untung atau rugi? Layanan mana yang paling laku? Semua terasa gelap tanpa data.'],
      ['🏪', 'Kelola Banyak Cabang Ribet', 'Setiap cabang pakai sistem berbeda. Pemilik harus keliling atau telepon satu-satu untuk tahu kondisi terkini.'],
      ['💸', 'Piutang Menumpuk', 'Banyak pelanggan yang belum bayar lunas tapi lupa ditagih. Cashflow jadi terganggu.'],
    ];
    foreach ($problems as [$icon, $title, $desc]):
    ?>
    <div style="background:rgba(239,68,68,.04);border:1.5px solid rgba(239,68,68,.12);
                border-radius:12px;padding:20px;transition:transform .2s"
         onmouseenter="this.style.transform='translateY(-3px)'"
         onmouseleave="this.style.transform=''">
      <div style="font-size:28px;margin-bottom:10px"><?= $icon ?></div>
      <div style="font-size:14px;font-weight:700;color:#fff;margin-bottom:6px"><?= $title ?></div>
      <div style="font-size:13px;color:rgba(255,255,255,.55);line-height:1.6"><?= $desc ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <div style="text-align:center;margin-top:40px">
    <div style="font-size:1.1rem;color:rgba(255,255,255,.7);font-weight:500">
      Semua masalah di atas bisa diselesaikan dengan <strong style="color:#35E8D5">LAMASY</strong> — mulai hari ini.
    </div>
    <a href="/register.php"
       style="display:inline-block;margin-top:20px;background:#35E8D5;color:#0F1C3A;
              font-weight:700;padding:13px 32px;border-radius:10px;text-decoration:none;
              font-size:15px;transition:opacity .2s"
       onmouseenter="this.style.opacity='.85'" onmouseleave="this.style.opacity='1'">
      Coba Gratis 7 Hari →
    </a>
  </div>
</section>

<!-- ── AI SECTION ─────────────────────────────────── -->
<div class="ai-section" id="ai">
  <div class="ai-inner">
    <div class="ai-badge-row">
      <div class="ai-badge"><span class="pulse"></span> Powered by AI</div>
    </div>
    <div class="ai-hero-card">
      <!-- Left: text -->
      <div class="ai-hero-text">
        <h2>Bukan Sekadar Software —<br><em>LAMASY Punya Otak AI</em></h2>
        <p>Teknologi kecerdasan buatan terintegrasi langsung di dalam sistem. LAMASY menganalisis data bisnis Anda secara otomatis dan memberikan insight yang actionable — setiap hari.</p>
        <div class="ai-features-list">
          <div class="ai-feat-item">
            <div class="ai-feat-icon">📋</div>
            <div class="ai-feat-text">
              <strong>Briefing Harian Otomatis</strong>
              <span>Setiap pagi, AI merangkum performa outlet Anda — pendapatan, order pending, karyawan hadir, dan rekomendasi prioritas hari ini.</span>
            </div>
          </div>
          <div class="ai-feat-item">
            <div class="ai-feat-icon">📈</div>
            <div class="ai-feat-text">
              <strong>Analisis Tren & Prediksi</strong>
              <span>AI mendeteksi pola pendapatan, jam puncak order, dan memperingatkan potensi penurunan sebelum terjadi.</span>
            </div>
          </div>
          <div class="ai-feat-item">
            <div class="ai-feat-icon">💡</div>
            <div class="ai-feat-text">
              <strong>Rekomendasi Bisnis Cerdas</strong>
              <span>Saran konkret berbasis data: kapan waktu terbaik promosi, layanan mana yang paling profitable, dan lebih banyak lagi.</span>
            </div>
          </div>
          <div class="ai-feat-item">
            <div class="ai-feat-icon">🔔</div>
            <div class="ai-feat-text">
              <strong>Notifikasi WA dengan AI</strong>
              <span>Pesan WhatsApp ke pelanggan dibuat otomatis oleh AI — personal, tepat waktu, dan natural. Bukan template kaku.</span>
            </div>
          </div>
        </div>
      </div>
      <!-- Right: AI chat mockup -->
      <div>
        <div class="ai-mockup">
          <div class="ai-mockup-header">
            <div class="ai-dot"></div>
            LAMASY AI Assistant
          </div>
          <div class="ai-chat-body">
            <div class="ai-msg user">
              <div class="ai-avatar">👤</div>
              <div class="ai-bubble">Gimana performa outlet hari ini?</div>
            </div>
            <div class="ai-msg">
              <div class="ai-avatar">🤖</div>
              <div class="ai-bubble">
                Halo! Berikut ringkasan hari ini (Kamis, 15 Mei):<br><br>
                <strong>📦 Order masuk:</strong> <span class="teal">47 order</span> (+12% vs kemarin)<br>
                <strong>💰 Pendapatan:</strong> <span class="teal">Rp 2,4 Juta</span><br>
                <strong>⚠️ Perhatian:</strong> 8 order sudah 2 hari belum diambil — perlu WA reminder.<br><br>
                <strong>💡 Rekomendasi:</strong> Aktifkan promo "Cuci Kilat" di jam 14.00–17.00, biasanya sepi tapi demand ada.
              </div>
            </div>
            <div class="ai-msg user">
              <div class="ai-avatar">👤</div>
              <div class="ai-bubble">Kirim WA reminder ke yang belum ambil dong</div>
            </div>
            <div class="ai-msg">
              <div class="ai-avatar">🤖</div>
              <div class="ai-typing">
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
              </div>
            </div>
          </div>
          <div class="ai-stats-row">
            <div class="ai-stat-chip">
              <div class="val">47</div>
              <div class="lbl">Order Hari Ini</div>
            </div>
            <div class="ai-stat-chip">
              <div class="val">2.4 Jt</div>
              <div class="lbl">Pendapatan</div>
            </div>
            <div class="ai-stat-chip">
              <div class="val">+12%</div>
              <div class="lbl">vs Kemarin</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── FITUR ────────────────────────────────────────── -->
<section class="section" id="fitur">
  <div class="section-header">
    <div class="section-tag">Fitur Lengkap</div>
    <h2>Semua yang Anda Butuhkan dalam Satu Platform</h2>
    <p>Dari kasir, laporan keuangan formal SAK EMKM, AI yang membantu pengambilan keputusan, sampai integrasi WhatsApp lengkap. Semua ada di LAMASY.</p>
  </div>
  <div class="features-grid">
    <div class="feature-card">
      <span class="feature-icon">🛒</span>
      <h3>POS & Order Management</h3>
      <p>Kasir digital cepat — terima order, hitung diskon, redeem poin loyalty, cetak nota termal, dan kirim nota PDF via WA dalam 1 klik. Status cucian tertrack real-time.</p>
    </div>
    <div class="feature-card">
      <span class="feature-icon">📒</span>
      <h3>Laporan Keuangan SAK EMKM</h3>
      <p>Laporan formal lengkap: Laba Rugi, Neraca, Arus Kas, dan Rasio Keuangan — siap pajak & investor. Otomatis dari data transaksi, tanpa perlu akuntan.</p>
    </div>
    <div class="feature-card">
      <span class="feature-icon">👥</span>
      <h3>Karyawan, Absensi & Penggajian</h3>
      <p>Multi-outlet assignment (Area Coordinator, perbantuan). Absensi digital, hitung gaji otomatis dari jam kerja, role & permission per posisi.</p>
    </div>
    <div class="feature-card">
      <span class="feature-icon">📱</span>
      <h3>WhatsApp Terintegrasi</h3>
      <p>Notif WA otomatis: nota saat order masuk, status cucian, reminder lunas, link tracking real-time untuk pelanggan. Plus WA blast & retensi pelanggan dormant.</p>
    </div>
    <div class="feature-card" style="border-color:rgba(139,92,246,.25);background:rgba(139,92,246,.04);">
      <span class="feature-icon" style="color:#C4B5FD;">🤖</span>
      <h3 style="color:#C4B5FD;">AI Briefing Harian</h3>
      <p>Setiap pagi dapat ringkasan kondisi outlet — omzet kemarin, order belum bayar, anomali, peluang upselling. Owner cepat ambil keputusan tanpa scroll laporan.</p>
    </div>
    <div class="feature-card" style="border-color:rgba(139,92,246,.25);background:rgba(139,92,246,.04);">
      <span class="feature-icon" style="color:#C4B5FD;">💬</span>
      <h3 style="color:#C4B5FD;">AI Chat dengan Data Bisnis</h3>
      <p>Tanya pakai bahasa natural: "top 5 pelanggan bulan ini" atau "omzet hari Sabtu vs Minggu". AI yang baca data laporan kamu, kasih jawaban langsung.</p>
    </div>
    <div class="feature-card">
      <span class="feature-icon">🏢</span>
      <h3>Multi-Outlet HQ Dashboard</h3>
      <p>Owner punya 2+ outlet? Dashboard HQ tunjukin omzet konsolidasi, drilldown per outlet, transfer coin antar-outlet, mutasi karyawan, audit log lengkap.</p>
    </div>
    <div class="feature-card">
      <span class="feature-icon">🤝</span>
      <h3>Drop Point & Mitra Komisi</h3>
      <p>Kerjasama dengan warung/toko sekitar jadi drop point. Hitung komisi otomatis (per-kg / persentase / kombinasi), rekap bulanan, bayar mitra tinggal klik.</p>
    </div>
    <div class="feature-card">
      <span class="feature-icon">💵</span>
      <h3>Piutang B2B & Korporat</h3>
      <p>Layani hotel, kost, restoran dengan kategori bulanan. Generate invoice formal, kirim reminder WA otomatis, alert piutang yang jatuh tempo — cashflow nggak bocor.</p>
    </div>
    <div class="feature-card">
      <span class="feature-icon">🎟️</span>
      <h3>Loyalty, Promo & Voucher</h3>
      <p>Poin loyalty (earn & redeem), promo persen/nominal, voucher dengan kuota & expired. Bikin pelanggan datang lagi tanpa effort manual.</p>
    </div>
    <div class="feature-card">
      <span class="feature-icon">🔍</span>
      <h3>Tracking Order Publik</h3>
      <p>Pelanggan cek status cucian via link unik (tanpa login). Mengurangi "kapan selesai kak?" di WA. Branded sesuai nama outlet kamu.</p>
    </div>
    <div class="feature-card" style="border-color:rgba(139,92,246,.25);background:rgba(139,92,246,.04);">
      <span class="feature-icon" style="color:#C4B5FD;">📥</span>
      <h3 style="color:#C4B5FD;">AI Migration dari Aplikasi Lama</h3>
      <p>Pindah dari Excel atau aplikasi lain? Upload file lama, AI map kolom otomatis ke format LAMASY. Data pelanggan & transaksi historis langsung ke-import.</p>
    </div>
  </div>
</section>

<!-- ── COMPARISON TABLE ─────────────────────────────── -->
<section class="section" id="perbandingan">
  <div class="section-header">
    <div class="section-tag">Kenapa LAMASY?</div>
    <h2>LAMASY vs Cara Lama</h2>
    <p>Lihat perbedaan nyata antara mengelola laundry dengan LAMASY dan cara konvensional.</p>
  </div>
  <div style="max-width:820px;margin:0 auto;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:14px">
      <thead>
        <tr>
          <th style="padding:14px 16px;text-align:left;color:rgba(255,255,255,.5);font-weight:500;font-size:13px;border-bottom:1px solid rgba(255,255,255,.08);width:40%">Aspek</th>
          <th style="padding:14px 16px;text-align:center;color:#f87171;font-weight:700;border-bottom:1px solid rgba(255,255,255,.08);width:30%">❌ Cara Lama</th>
          <th style="padding:14px 16px;text-align:center;color:#35E8D5;font-weight:700;border-bottom:1px solid rgba(255,255,255,.08);width:30%">✅ LAMASY</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $rows = [
          ['Pencatatan Order',     'Buku tulis / Excel manual',          'Digital real-time, auto-tersimpan'],
          ['Notifikasi Pelanggan', 'Telepon satu-satu, sering lupa',     'WhatsApp otomatis, tanpa effort'],
          ['Laporan Keuangan',     'Rekap manual, lambat, rawan salah',  'Dashboard otomatis, seketika'],
          ['Pantau Karyawan',      'Tidak ada data, perkiraan saja',     'Absensi digital + laporan gaji'],
          ['Analisis Bisnis',      'Harus baca data sendiri, subjektif', 'AI briefing harian, insight akurat'],
          ['Multi Outlet',         'Masing-masing sistem sendiri',       'Satu dashboard, semua cabang'],
          ['Piutang',              'Sering terlewat, cashflow bocor',    'Alert otomatis, tidak ada yang lolos'],
          ['Waktu yang Dihemat',   'Habis untuk admin & laporan',        '3+ jam/hari untuk fokus bisnis'],
        ];
        foreach ($rows as $i => [$aspect, $bad, $good]):
          $bg = $i % 2 === 0 ? 'rgba(255,255,255,.015)' : 'transparent';
        ?>
        <tr style="background:<?= $bg ?>">
          <td style="padding:13px 16px;color:rgba(255,255,255,.8);font-weight:500;border-bottom:1px solid rgba(255,255,255,.05)"><?= $aspect ?></td>
          <td style="padding:13px 16px;text-align:center;color:rgba(248,113,113,.8);border-bottom:1px solid rgba(255,255,255,.05)"><?= $bad ?></td>
          <td style="padding:13px 16px;text-align:center;color:rgba(53,232,213,.9);font-weight:600;border-bottom:1px solid rgba(255,255,255,.05)"><?= $good ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- ── HOW IT WORKS ─────────────────────────────────── -->
<div class="how-it-works">
  <section class="section" id="cara-kerja">
    <div class="section-header">
      <div class="section-tag">Cara Kerja</div>
      <h2>Mulai dalam 3 Langkah Mudah</h2>
      <p>Tidak perlu keahlian teknis. LAMASY dirancang agar siapapun bisa langsung pakai.</p>
    </div>
    <div class="steps-row">
      <div class="step-card">
        <div class="step-num-wrap">1</div>
        <h3>Daftar Akun</h3>
        <p>Isi form registrasi sederhana. Akun trial 7 hari langsung aktif dengan 10.000 coin gratis. Tidak perlu kartu kredit.</p>
      </div>
      <div class="step-card">
        <div class="step-num-wrap">2</div>
        <h3>Setup Outlet</h3>
        <p>Konfigurasikan layanan laundry, harga, karyawan, dan pengaturan outlet Anda dipandu wizard setup yang mudah.</p>
      </div>
      <div class="step-card">
        <div class="step-num-wrap">3</div>
        <h3>Mulai Operasional</h3>
        <p>Terima order pertama, lihat laporan real-time, dan kirim notifikasi WA ke pelanggan. Bisnis makin rapi!</p>
      </div>
    </div>
  </section>
</div>

<!-- ── TARGET AUDIENCE ─────────────────────────────── -->
<section class="section" id="untuk-siapa">
  <div class="section-header">
    <div class="section-tag">Untuk Siapa?</div>
    <h2>LAMASY Cocok untuk Kamu yang...</h2>
    <p>Kami rancang LAMASY khusus untuk kebutuhan bisnis laundry Indonesia.</p>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;max-width:900px;margin:0 auto">
    <?php
    $audiences = [
      ['🏠', 'Laundry Rumahan', 'Baru mulai atau masih kecil? LAMASY justru ideal untuk Anda. Setup 10 menit, langsung produktif.'],
      ['🏪', 'Outlet 1-5 Cabang', 'Kelola semua cabang dari satu dashboard. Tidak perlu WhatsApp bolak-balik ke masing-masing cabang.'],
      ['🏢', 'Waralaba Laundry', 'Standarisasi operasional dan pantau performa seluruh mitra dari satu pusat kontrol.'],
      ['📈', 'Yang Ingin Berkembang', 'Punya target buka cabang baru? LAMASY siap scale bersama bisnis Anda tanpa migrasi sistem.'],
    ];
    foreach ($audiences as [$icon, $title, $desc]):
    ?>
    <div style="background:rgba(53,232,213,.04);border:1.5px solid rgba(53,232,213,.15);
                border-radius:14px;padding:24px 20px;text-align:center;transition:transform .2s,border-color .2s"
         onmouseenter="this.style.transform='translateY(-4px)';this.style.borderColor='rgba(53,232,213,.4)'"
         onmouseleave="this.style.transform='';this.style.borderColor='rgba(53,232,213,.15)'">
      <div style="font-size:40px;margin-bottom:12px"><?= $icon ?></div>
      <div style="font-size:14px;font-weight:700;color:#fff;margin-bottom:8px"><?= $title ?></div>
      <div style="font-size:13px;color:rgba(255,255,255,.55);line-height:1.6"><?= $desc ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <div style="text-align:center;margin-top:32px;font-size:14px;color:rgba(255,255,255,.45)">
    Tidak yakin LAMASY cocok untuk bisnis kamu?
    <a href="https://wa.me/6285121519302?text=Halo+saya+mau+tanya+soal+LAMASY"
       target="_blank" style="color:#35E8D5;margin-left:4px">Chat kami dulu →</a>
  </div>
</section>

<!-- ── HARGA ────────────────────────────────────────── -->
<section class="section" id="harga">
  <div class="section-header">
    <div class="section-tag">Harga</div>
    <h2>Bayar Sesuai Pemakaian — Bukan Langganan Mengikat</h2>
    <p>Tidak ada paket bertingkat. Tidak ada biaya bulanan wajib. Cuma <strong>1× setup fee per outlet</strong>, sisanya kamu yang kontrol via Coin sesuai pemakaian fitur.</p>
  </div>

  <!-- Main Pricing Card -->
  <div style="max-width:880px;margin:0 auto 32px;display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px;">

    <!-- Trial Card -->
    <div class="pricing-card" style="display:flex;flex-direction:column;">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(53,232,213,.7);margin-bottom:8px;">Coba Dulu</div>
      <div class="pricing-name">Trial Gratis</div>
      <div class="pricing-price">Rp 0<div class="per">7 hari, semua fitur terbuka</div></div>
      <div class="pricing-desc">Daftar 3 langkah, langsung pakai. Tidak perlu kartu kredit, tidak ada komitmen.</div>
      <div class="pricing-divider"></div>
      <ul class="pricing-features" style="flex:1;">
        <li>Semua fitur (POS, Keuangan, Karyawan)</li>
        <li><strong>10.000 coin</strong> gratis untuk coba AI &amp; WA</li>
        <li>1 outlet, unlimited transaksi</li>
        <li>Coin sisa trial <strong>di-carry over</strong> kalau aktivasi</li>
        <li>Support via WhatsApp</li>
      </ul>
      <a href="/register.php" class="btn-pricing btn-pricing-outline" style="margin-top:auto;">Mulai Trial Gratis</a>
    </div>

    <!-- Aktivasi Card -->
    <div class="pricing-card featured" style="display:flex;flex-direction:column;">
      <div class="pricing-badge">⭐ Promo Launching</div>
      <div class="pricing-name">Aktivasi Outlet</div>
      <div class="pricing-price">
        <div style="font-size:14px;color:rgba(255,255,255,.4);text-decoration:line-through;font-weight:500;margin-bottom:4px;">Rp 1.000.000</div>
        <sup>Rp</sup>800<span style="font-size:24px;font-weight:600;">rb</span>
        <div class="per">1× per outlet · permanen, tanpa langganan</div>
      </div>
      <div class="pricing-desc">Aktivasi outlet permanen — dapat <strong>100.000 coin</strong>. Sisa coin trial otomatis ditambahkan ke saldo aktivasi.</div>
      <div class="pricing-divider"></div>
      <ul class="pricing-features" style="flex:1;">
        <li><strong>Semua fitur Trial</strong> + AI tools lanjutan</li>
        <li><strong>100.000 coin</strong> bonus aktivasi</li>
        <li>+ <strong>sisa coin trial</strong> ditransfer otomatis</li>
        <li>Outlet aktif permanen (no expiry)</li>
        <li>Onboarding 1-on-1 (30 menit via WA/Zoom)</li>
        <li>Support prioritas + dedicated WA</li>
      </ul>
      <a href="/register.php" class="btn-pricing btn-pricing-primary" style="margin-top:auto;">Aktivasi Outlet Saya</a>
    </div>

  </div>

  <!-- Multi-outlet note -->
  <div style="max-width:880px;margin:0 auto 36px;background:rgba(139,92,246,.05);
              border:1px solid rgba(139,92,246,.18);border-radius:12px;padding:18px 24px;
              display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
    <div style="font-size:32px;flex-shrink:0;">🏢</div>
    <div style="flex:1;min-width:200px;">
      <div style="font-size:14px;font-weight:700;color:#C4B5FD;margin-bottom:4px;">Punya 2+ outlet atau franchise?</div>
      <div style="font-size:13px;color:rgba(255,255,255,.7);line-height:1.55;">
        Aktivasi tetap Rp 800rb/outlet (promo launching), plus akses HQ Dashboard konsolidasi gratis. Diskon volume mulai 3 outlet — diskusi dengan tim kami.
      </div>
    </div>
    <a href="https://wa.me/6285121519302?text=Halo+Tim+LAMASY%2C+saya+ingin+diskusi+pricing+untuk+multi-outlet."
       target="_blank"
       style="background:rgba(139,92,246,.15);color:#C4B5FD;border:1px solid rgba(139,92,246,.4);
              padding:10px 18px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;flex-shrink:0;">
      💬 Diskusi Volume
    </a>
  </div>

  <!-- Coin transparency -->
  <div style="max-width:880px;margin:0 auto;">
    <div style="text-align:center;margin-bottom:20px;">
      <div style="display:inline-block;background:rgba(53,232,213,.08);color:#35E8D5;
                  padding:4px 12px;border-radius:6px;font-size:11px;font-weight:700;
                  letter-spacing:.1em;text-transform:uppercase;margin-bottom:10px;">
        Transparansi Coin
      </div>
      <h3 style="font-size:22px;font-weight:700;color:#fff;margin-bottom:8px;">Tahu Persis Setiap Coin Dipakai untuk Apa</h3>
      <p style="font-size:14px;color:rgba(255,255,255,.55);max-width:560px;margin:0 auto;">
        Tidak ada biaya tersembunyi. Setiap fitur premium punya harga coin yang jelas — kamu yang putuskan mau pakai atau skip.
      </p>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <?php
      $coinExamples = [
        ['📄', 'Generate Nota Termal',  '50 coin',  'per cetak'],
        ['📱', 'WA Notif Status Order', '50 coin',  'per kirim'],
        ['📱', 'WA Nota PDF Lengkap',   '100 coin', 'per kirim'],
        ['🤖', 'AI Briefing Harian',    '500 coin', 'per outlet/hari'],
        ['🤖', 'AI Chat dengan Data',   '50 coin',  'per pertanyaan'],
        ['📤', 'Export Laporan PDF',    '200 coin', 'per export'],
      ];
      foreach ($coinExamples as [$ico, $nama, $coin, $unit]):
      ?>
        <div style="background:rgba(255,255,255,.025);border:1px solid rgba(255,255,255,.06);
                    border-radius:10px;padding:14px 16px;display:flex;align-items:center;gap:12px;">
          <div style="font-size:24px;flex-shrink:0;"><?= $ico ?></div>
          <div style="flex:1;min-width:0;">
            <div style="font-size:13px;font-weight:600;color:#fff;"><?= htmlspecialchars($nama) ?></div>
            <div style="font-size:11px;color:rgba(255,255,255,.45);"><?= htmlspecialchars($unit) ?></div>
          </div>
          <div style="font-size:13px;font-weight:700;color:#FCD34D;font-family:var(--mono);white-space:nowrap;"><?= $coin ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <p style="text-align:center;margin-top:18px;font-size:12px;color:rgba(255,255,255,.4);">
      *Contoh harga. Lihat semua harga fitur di dashboard kamu setelah login. Harga bisa berubah, dan setiap perubahan tercatat di history transparan.
    </p>
  </div>

  <!-- Topup Pack Example -->
  <div style="max-width:720px;margin:36px auto 0;background:rgba(255,255,255,.025);
              border:1px solid rgba(255,255,255,.06);border-radius:12px;padding:20px 24px;text-align:center;">
    <div style="font-size:12px;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.1em;margin-bottom:8px;">Topup Coin (kapan saja)</div>
    <div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap;font-size:13px;color:rgba(255,255,255,.8);">
      <span><strong>Rp 50.000</strong> → 10.000 coin</span>
      <span style="opacity:.4;">·</span>
      <span><strong>Rp 100.000</strong> → 22.000 coin <span style="color:#86EFAC;font-size:11px;">+10%</span></span>
      <span style="opacity:.4;">·</span>
      <span><strong>Rp 250.000</strong> → 60.000 coin <span style="color:#86EFAC;font-size:11px;">+20%</span></span>
      <span style="opacity:.4;">·</span>
      <span><strong>Rp 500.000</strong> → 130.000 coin <span style="color:#86EFAC;font-size:11px;">+30%</span></span>
    </div>
    <div style="margin-top:10px;font-size:11px;color:rgba(255,255,255,.4);">
      Coin tidak ada masa berlaku. Berhenti pakai? Coin tetap tersimpan sampai kamu aktif lagi.
    </div>
  </div>
</section>

<!-- ── TESTIMONIALS ─────────────────────────────────── -->
<div style="background:rgba(255,255,255,.015);position:relative;z-index:1;">
  <section class="section">
    <div class="section-header">
      <div class="section-tag">Testimoni</div>
      <h2>Dipercaya Ratusan Pemilik Laundry</h2>
      <p>Lihat apa kata mereka yang sudah merasakan manfaat LAMASY untuk bisnis mereka.</p>
    </div>
    <div class="testimonials-grid">
      <div class="testi-card">
        <div class="testi-stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
        <div class="testi-text">"Sejak pakai LAMASY, pelaporan keuangan outlet saya jauh lebih rapi. Dulu harus rekap manual di Excel, sekarang tinggal buka dashboard sudah keliatan semua. Hemat banget!"</div>
        <div class="testi-author">
          <div class="testi-avatar">&#x1F464;</div>
          <div>
            <div class="testi-name">Ibu Ratna Sari</div>
            <div class="testi-role">Pemilik Fresh Laundry, Semarang</div>
          </div>
        </div>
      </div>
      <div class="testi-card">
        <div class="testi-stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
        <div class="testi-text">"Fitur notifikasi WA-nya luar biasa! Pelanggan langsung tahu kalau cuciannya sudah selesai tanpa perlu saya telepon satu-satu. Komplain berkurang drastis."</div>
        <div class="testi-author">
          <div class="testi-avatar">&#x1F464;</div>
          <div>
            <div class="testi-name">Pak Hendra Wijaya</div>
            <div class="testi-role">Owner Bersih Laundry, Surabaya</div>
          </div>
        </div>
      </div>
      <div class="testi-card">
        <div class="testi-stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
        <div class="testi-text">"Saya punya 3 outlet dan sebelumnya kesulitan monitor semua dari satu tempat. Dengan LAMASY, semua data terpusat. Setup-nya juga mudah, tim CS sangat responsif."</div>
        <div class="testi-author">
          <div class="testi-avatar">&#x1F464;</div>
          <div>
            <div class="testi-name">Budi Prasetiyo</div>
            <div class="testi-role">Direktur Bersinar Laundry Group, Jakarta</div>
          </div>
        </div>
      </div>
      <div class="testi-card" style="border-color:rgba(139,92,246,.25);background:rgba(139,92,246,.05);">
        <div class="testi-stars" style="color:#C4B5FD;">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
        <div class="testi-text">"Fitur AI Briefing-nya yang paling bikin saya takjub. Setiap pagi ada ringkasan otomatis — berapa order kemarin, mana yang belum dibayar, karyawan siapa yang sering telat. Saya jadi lebih cepat ambil keputusan tanpa harus buka banyak laporan."</div>
        <div class="testi-author">
          <div class="testi-avatar" style="background:rgba(139,92,246,.2);border-color:rgba(139,92,246,.3);">&#x1F464;</div>
          <div>
            <div class="testi-name">Dewi Kusuma</div>
            <div class="testi-role" style="color:#C4B5FD;">Owner Kilat Bersih Laundry, Bandung — <em>pengguna fitur AI</em></div>
          </div>
        </div>
      </div>
    </div>
  </section>
</div>

<!-- ── FAQ ──────────────────────────────────────────── -->
<section class="section" id="faq" style="background:rgba(255,255,255,.018)">
  <div class="section-header">
    <div class="section-tag">FAQ</div>
    <h2>Pertanyaan yang Sering Ditanyakan</h2>
    <p>Tidak menemukan jawaban yang kamu cari? <a href="https://wa.me/6285121519302?text=Halo+Tim+LAMASY%2C+saya+ada+pertanyaan+yang+tidak+ada+di+FAQ." target="_blank" style="color:#35E8D5">Chat langsung dengan tim kami.</a></p>
  </div>
  <div style="max-width:720px;margin:0 auto" id="faqList">
    <?php
    $faqs = [
      ['Apakah LAMASY benar-benar gratis di awal?',
       'Trial <strong>7 hari</strong> benar-benar gratis — tidak perlu kartu kredit, tidak ada biaya tersembunyi. Semua fitur (POS, Keuangan, Karyawan, AI, WA) terbuka. Dapat <strong>10.000 coin gratis</strong> untuk coba fitur AI &amp; WA. Setelah trial, aktivasi outlet permanen Rp 800rb (promo launching, normal Rp 1jt) sekali bayar.'],

      ['Bagaimana sistem pricing-nya? Saya bingung soal coin.',
       'Simpel: <strong>1× aktivasi Rp 800rb per outlet</strong> (promo launching) untuk akses permanen, sisanya pay-per-use via Coin. Saat aktivasi dapat <strong>100.000 coin bonus</strong> — plus sisa coin trial kamu otomatis ditambahkan ke saldo. Contoh harga fitur: WA notif = 50 coin, AI Chat = 50 coin, AI Briefing = 500 coin. Topup self-service mulai Rp 50rb / 10.000 coin. <strong>Tidak ada langganan bulanan wajib</strong> — kamu yang kontrol pemakaian.'],

      ['Apa bedanya dengan aplikasi laundry lain?',
       'Tiga hal utama: <strong>(1)</strong> Pricing transparan pay-per-use — tidak ada paket bertingkat yang membatasi fitur. <strong>(2)</strong> AI terintegrasi — briefing pagi, chat dengan data laporan, deteksi pelanggan dormant. <strong>(3)</strong> Laporan keuangan formal SAK EMKM (Laba Rugi, Neraca, Arus Kas) — siap pajak, tanpa perlu akuntan.'],

      ['Sudah ada data di Excel / aplikasi lama. Bisa di-import?',
       'Bisa! Pakai fitur <strong>AI Migration Mapper</strong> — upload file Excel atau export dari aplikasi lama, AI baca header & sample lalu map otomatis ke format LAMASY. Data pelanggan, layanan, transaksi historis bisa langsung masuk. Kami juga sediakan assisted migration berbayar untuk file kompleks.'],

      ['Apakah data saya aman?',
       'Ya. Data disimpan di server terenkripsi dengan backup harian. Setiap tenant punya isolasi data penuh — data kamu hanya bisa diakses oleh kamu dan karyawan yang kamu undang. Bahkan AI Chat di-batasi via SQL allowlist + tenant_id binding — AI mustahil leak data tenant lain. Password kamu di-hash bcrypt, tidak tersimpan plain text.'],

      ['Bisa diakses dari HP?',
       'LAMASY dirancang <strong>mobile-first</strong> — semua halaman responsif. Kasir bisa pakai HP, owner cek dashboard dari mana saja, kurir update status order via HP. Tidak perlu install aplikasi, cukup buka browser dan login.'],

      ['Punya 2+ outlet. Bagaimana managementnya?',
       'Owner dapat akses HQ Dashboard otomatis — konsolidasi omzet semua outlet, drilldown per outlet, transfer coin antar-outlet, mutasi karyawan, audit log lintas-outlet. Karyawan bisa di-assign multi-outlet (Area Coordinator, kurir antar-outlet, perbantuan weekend). Aktivasi tetap Rp 800rb/outlet (promo launching) — diskon volume mulai 3 outlet, diskusi langsung dengan tim kami.'],

      ['Bisa tambah karyawan dengan role berbeda?',
       'Ya. Role bawaan: Owner, Manager, Kasir, Staff (operator cuci), Kurir, Mitra (drop point). Setiap role punya akses & tampilan sesuai tugas — kasir cuma lihat POS+orders, manager lihat laporan outlet, owner lihat semua. Custom role + permission juga bisa di-atur.'],

      ['Apakah AI-nya beneran berguna atau cuma marketing?',
       'AI di LAMASY fokus ke 4 hal konkret: <strong>(1) Briefing pagi</strong> — ringkasan kondisi outlet otomatis. <strong>(2) Chat dengan Data</strong> — tanya pakai bahasa natural, AI baca laporan. <strong>(3) Pesan retensi</strong> — generate WA personal untuk pelanggan dormant. <strong>(4) Upselling di POS</strong> — saran cross-sell saat kasir input order. Semua opt-in, bisa di-skip kalau nggak perlu.'],

      ['Bagaimana jika trial habis dan belum aktivasi?',
       'Setelah trial 7 hari, outlet masuk periode <strong>grace 7 hari</strong> — data masih bisa dilihat (read-only) tapi tidak bisa input order baru. Setelah grace berakhir, outlet ditangguhkan tapi data tetap tersimpan 30 hari. Kamu bisa aktivasi kapan saja tanpa kehilangan data — sisa coin trial juga tetap tersimpan.'],

      ['Apakah ada kontrak jangka panjang?',
       'Tidak ada. Tidak ada kontrak bulanan/tahunan. Setup fee dibayar sekali untuk aktivasi outlet permanen, sisanya kamu yang kontrol via topup coin. Berhenti pakai? Coin tersimpan sampai kamu aktif lagi, tidak ada penalti.'],

      ['Bagaimana support kalau ada masalah?',
       'Support via WhatsApp untuk semua tenant aktif (jam kerja 9-21 WIB, response &lt;2 jam). Tenant dengan multi-outlet dapat dedicated WA group. Trial dan tenant baru dapat onboarding 1-on-1 (30 menit) untuk setup outlet, layanan, dan training kasir.'],
    ];
    foreach ($faqs as $i => [$q, $a]):
    ?>
    <div class="faq-item" style="border-bottom:1px solid rgba(255,255,255,.07);overflow:hidden">
      <button onclick="toggleFaq(<?= $i ?>)"
              style="width:100%;background:none;border:none;padding:18px 0;
                     display:flex;align-items:center;justify-content:space-between;
                     cursor:pointer;text-align:left;gap:16px">
        <span style="font-size:15px;font-weight:600;color:#fff;line-height:1.4"><?= $q ?></span>
        <span id="faq-icon-<?= $i ?>"
              style="font-size:20px;color:#35E8D5;flex-shrink:0;
                     transition:transform .25s;line-height:1">+</span>
      </button>
      <div id="faq-body-<?= $i ?>"
           style="max-height:0;overflow:hidden;transition:max-height .3s ease">
        <div style="padding:0 0 18px;font-size:14px;color:rgba(255,255,255,.62);line-height:1.75">
          <?= $a ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- ── CTA BANNER ───────────────────────────────────── -->
<div class="cta-banner">
  <div class="cta-inner">
    <h2>Siap Digitalisasi Laundry Anda?</h2>
    <p>Mulai trial 7 hari sekarang dengan 10.000 coin gratis — semua fitur, tanpa kartu kredit. Setelah cocok, aktivasi outlet permanen <strong>Rp 800rb</strong> (promo launching, normal Rp 1jt) + 100rb coin bonus.</p>
    <div class="cta-btns">
      <a href="/register.php" class="btn-primary">&#128640; Mulai Trial 7 Hari Gratis</a>
      <a href="https://wa.me/6285121519302?text=Halo+saya+ingin+tahu+lebih+lanjut+tentang+LAMASY" target="_blank" class="btn-secondary">&#128172; Chat WhatsApp</a>
    </div>
  </div>
</div>

<!-- ── FOOTER ───────────────────────────────────────── -->
<footer class="footer" id="kontak">
  <div class="footer-grid">
    <div>
      <div class="footer-logo">
        <img src="/assets/logo.png" alt="LAMASY" style="height:40px; vertical-align:middle; margin-right:8px;">
        LAMASY <span style="font-size:13px;font-weight:500;color:rgba(255,255,255,.45);">by Harpy</span>
      </div>
      <div class="footer-tagline">Platform manajemen laundry modern untuk bisnis yang lebih rapi.</div>
    </div>
    <div>
      <div class="footer-col-title">Produk</div>
      <ul class="footer-links">
        <li><a href="#fitur">Fitur</a></li>
        <li><a href="#harga">Harga</a></li>
        <li><a href="#faq">FAQ</a></li>
        <li><a href="/register.php">Daftar Gratis</a></li>
      </ul>
    </div>
    <div>
      <div class="footer-col-title">Akun</div>
      <ul class="footer-links">
        <li><a href="/login.php">Login</a></li>
        <li><a href="/register.php">Daftar Gratis</a></li>
        <li><a href="#">Reset Password</a></li>
      </ul>
    </div>
    <div>
      <div class="footer-col-title">Kontak</div>
      <ul class="footer-links">
        <li><a href="https://wa.me/6285121519302?text=Halo+Tim+LAMASY%2C+saya+ingin+tahu+lebih+lanjut+tentang+LAMASY." target="_blank">&#128172; WhatsApp</a></li>
        <li><a href="mailto:halo@harpy.id">&#9993; halo@harpy.id</a></li>
        <li><a href="#">&#127968; Jakarta, Indonesia</a></li>
      </ul>
    </div>
  </div>
  <div class="footer-bottom">
    <span>&copy; <?= date('Y') ?> PT Harpy Sinergi Mandiri. All rights reserved.</span>
    <div style="display:flex;gap:20px;">
      <a href="#">Privasi</a>
      <a href="#">Syarat & Ketentuan</a>
      <!-- Super admin link - kecil, tidak mencolok -->
      <a href="/superadmin/login.php" style="opacity:.4;font-size:11px;">Admin</a>
    </div>
  </div>
</footer>

<script>
// ── FAQ accordion ─────────────────────────────────
function toggleFaq(i) {
  const body = document.getElementById('faq-body-' + i);
  const icon = document.getElementById('faq-icon-' + i);
  const isOpen = body.style.maxHeight && body.style.maxHeight !== '0px';

  // Tutup semua dulu
  document.querySelectorAll('[id^="faq-body-"]').forEach(el => { el.style.maxHeight = '0px'; });
  document.querySelectorAll('[id^="faq-icon-"]').forEach(el => { el.textContent = '+'; el.style.transform = ''; });

  if (!isOpen) {
    body.style.maxHeight = body.scrollHeight + 'px';
    icon.textContent = '−';
    icon.style.transform = 'rotate(180deg)';
  }
}

// ── Mobile nav toggle ──────────────────────────────
function toggleMobileMenu() {
  const menu = document.getElementById('mobileMenu');
  const toggle = document.getElementById('navToggle');
  const isOpen = menu.classList.toggle('open');
  toggle.innerHTML = isOpen ? '&#x2715;' : '&#9776;';
}
function closeMobileMenu() {
  document.getElementById('mobileMenu').classList.remove('open');
  document.getElementById('navToggle').innerHTML = '&#9776;';
}

// ── Navbar scroll effect ───────────────────────────
window.addEventListener('scroll', function() {
  const nav = document.getElementById('navbar');
  if (window.scrollY > 40) {
    nav.style.background = 'rgba(11,22,48,.97)';
    nav.style.boxShadow = '0 2px 30px rgba(0,0,0,.4)';
  } else {
    nav.style.background = 'rgba(15,28,58,.85)';
    nav.style.boxShadow = 'none';
  }
});

// ── Scroll-reveal animation ────────────────────────
const observer = new IntersectionObserver((entries) => {
  entries.forEach(e => {
    if (e.isIntersecting) {
      e.target.style.opacity = '1';
      e.target.style.transform = 'translateY(0)';
    }
  });
}, { threshold: 0.1 });

document.querySelectorAll('.feature-card, .step-card, .pricing-card, .testi-card').forEach(el => {
  el.style.opacity = '0';
  el.style.transform = 'translateY(24px)';
  el.style.transition = 'opacity .5s ease, transform .5s ease';
  observer.observe(el);
});
</script>
</body>
</html>

<?php
// ══════════════════════════════════════════════════════
// kalkulator-laundry.php — Kalkulator Untung Laundry (PUBLIK)
// Magnet konten organik (P0 Distribusi): owner hitung untung/margin →
// insight → CTA "laporan seperti ini otomatis di LAMASY (SAK EMKM)".
// Hitung 100% client-side; "kirim hasil ke email" = lead capture (api/lead.php).
// ══════════════════════════════════════════════════════
header('Cache-Control: public, max-age=3600');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kalkulator Untung Laundry — Hitung Laba Bersih & Margin Usahamu | LAMASY</title>
<meta name="description" content="Hitung untung bersih & margin laundry kiloanmu dalam 1 menit — gratis. Masukkan omset & biaya, langsung kelihatan laba, BEP, dan saran perbaikan.">
<meta property="og:title" content="Kalkulator Untung Laundry — berapa laba bersihmu sebenarnya?">
<meta property="og:description" content="Alat gratis untuk owner laundry: hitung laba bersih, margin, dan titik impas usahamu dalam 1 menit.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&family=DM+Mono:wght@500&display=swap" rel="stylesheet">
<style>
:root{ --navy:#0A0F1F; --navy2:#0F1C3A; --teal:#35E8D5; --teal-d:#14b8a6; }
*{box-sizing:border-box}
html{overflow-x:hidden;scroll-behavior:smooth}
body{margin:0;font-family:'Plus Jakarta Sans',system-ui,sans-serif;background:
  radial-gradient(900px 500px at 85% -5%,rgba(53,232,213,.14),transparent 60%),var(--navy);
  color:#e6edf6;min-height:100vh;padding:32px 16px 60px}
.wrap{max-width:640px;margin:0 auto}
.brand{text-align:center;font-weight:800;letter-spacing:.03em;color:var(--teal);margin-bottom:6px}
.brand a{color:inherit;text-decoration:none}
h1{font-size:clamp(24px,5vw,34px);font-weight:800;text-align:center;line-height:1.2;margin:8px 0 6px}
.sub{text-align:center;color:#9fb0c3;font-size:14px;margin:0 0 28px;line-height:1.5}
.card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.09);border-radius:16px;padding:22px 20px;margin-bottom:16px}
.card h2{font-size:15px;margin:0 0 14px;color:var(--teal)}
.row{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}
.row label{font-size:13.5px;color:#cbd5e1;flex:1}
.row small{display:block;color:#64748b;font-size:11px}
.row input{width:130px;padding:10px 12px;border-radius:9px;border:1px solid rgba(255,255,255,.15);
  background:rgba(255,255,255,.06);color:#fff;font-size:14px;font-family:'DM Mono',monospace;text-align:right;outline:none}
.row input:focus{border-color:var(--teal)}
.result{background:linear-gradient(135deg,rgba(20,184,166,.12),rgba(59,130,246,.08));border:1px solid rgba(53,232,213,.3)}
.big{font-family:'DM Mono',monospace;font-size:clamp(26px,6vw,36px);font-weight:800;text-align:center;margin:6px 0}
.big.pos{color:var(--teal)} .big.neg{color:#f87171}
.metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:14px}
.m{background:rgba(255,255,255,.05);border-radius:10px;padding:10px;text-align:center}
.m .v{font-family:'DM Mono',monospace;font-weight:700;font-size:15px}
.m .l{font-size:10.5px;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;margin-top:2px}
.insight{font-size:13.5px;line-height:1.65;color:#cbd5e1;margin-top:14px;border-top:1px dashed rgba(255,255,255,.12);padding-top:14px}
.cta{display:block;text-align:center;background:linear-gradient(90deg,var(--teal-d),var(--teal));color:#04211d;
  font-weight:800;font-size:15px;text-decoration:none;padding:14px;border-radius:12px;margin-top:16px}
.cta-sub{text-align:center;font-size:12px;color:#94a3b8;margin-top:8px}
.emailrow{display:flex;gap:8px;margin-top:12px}
.emailrow input{flex:1;padding:11px 12px;border-radius:9px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.06);color:#fff;font-size:13px;font-family:inherit;outline:none}
.emailrow button{padding:11px 16px;border:none;border-radius:9px;background:rgba(53,232,213,.15);color:var(--teal);font-weight:700;font-size:13px;cursor:pointer;font-family:inherit;border:1px solid rgba(53,232,213,.35)}
.foot{text-align:center;font-size:12px;color:#64748b;margin-top:26px}
.foot a{color:#94a3b8}
@media(max-width:480px){ .metrics{grid-template-columns:repeat(3,1fr)} .row input{width:110px} }
</style>
</head>
<body>
<div class="wrap">
  <div class="brand"><a href="/">LAMASY</a></div>
  <h1>Kalkulator Untung Laundry 🧺</h1>
  <p class="sub">Isi angka usahamu — langsung kelihatan <strong>laba bersih, margin, & titik impas</strong>.<br>Gratis, tanpa daftar.</p>

  <div class="card">
    <h2>📈 Pemasukan</h2>
    <div class="row"><label>Rata-rata cucian per hari <small>kg (kiloan + satuan dikonversi)</small></label><input type="number" id="kg" value="40" min="0" inputmode="decimal"></div>
    <div class="row"><label>Harga per kg <small>Rp — rata-rata semua layanan</small></label><input type="number" id="harga" value="7000" min="0" step="500" inputmode="numeric"></div>
    <div class="row"><label>Hari operasional per bulan</label><input type="number" id="hari" value="26" min="1" max="31" inputmode="numeric"></div>
  </div>

  <div class="card">
    <h2>📉 Biaya Bulanan</h2>
    <div class="row"><label>Sewa tempat <small>Rp/bulan (0 kalau milik sendiri)</small></label><input type="number" id="sewa" value="1500000" min="0" step="100000" inputmode="numeric"></div>
    <div class="row"><label>Gaji karyawan <small>total semua karyawan</small></label><input type="number" id="gaji" value="3000000" min="0" step="100000" inputmode="numeric"></div>
    <div class="row"><label>Listrik + air + gas</label><input type="number" id="util" value="1200000" min="0" step="100000" inputmode="numeric"></div>
    <div class="row"><label>Bahan per kg <small>deterjen, pewangi, plastik (Rp/kg)</small></label><input type="number" id="bahan" value="700" min="0" step="50" inputmode="numeric"></div>
    <div class="row"><label>Biaya lain <small>internet, servis mesin, dll</small></label><input type="number" id="lain" value="500000" min="0" step="50000" inputmode="numeric"></div>
  </div>

  <div class="card result">
    <h2>💰 Hasil Hitunganmu</h2>
    <div style="text-align:center;font-size:12px;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em">Laba bersih per bulan</div>
    <div class="big pos" id="laba">Rp 0</div>
    <div class="metrics">
      <div class="m"><div class="v" id="omset">–</div><div class="l">Omset/bln</div></div>
      <div class="m"><div class="v" id="margin">–</div><div class="l">Margin</div></div>
      <div class="m"><div class="v" id="bep">–</div><div class="l">BEP kg/hari</div></div>
    </div>
    <div class="insight" id="insight"></div>

    <a class="cta" href="/register.php?ref=kalkulator" id="ctaBtn">🚀 Rapikan angka ini otomatis — Coba LAMASY Gratis 14 Hari</a>
    <p class="cta-sub">Laba Rugi, Neraca &amp; Arus Kas standar akuntansi (SAK EMKM) tersusun sendiri dari transaksi kasirmu — tanpa hitung manual seperti ini lagi.</p>

    <div class="emailrow">
      <input type="email" id="leadEmail" placeholder="Kirim hasil + tips margin ke email…">
      <button onclick="sendLead()" id="leadBtn">Kirim</button>
    </div>
  </div>

  <p class="foot">Dibuat oleh <a href="/">LAMASY</a> — ERP laundry dengan AI &amp; laporan SAK EMKM · <a href="/demo">Coba demo</a></p>
</div>

<script>
const F = id => parseFloat(document.getElementById(id).value) || 0;
const rp = n => 'Rp ' + Math.round(n).toLocaleString('id-ID');

function hitung(){
  const kg=F('kg'), harga=F('harga'), hari=F('hari');
  const omset = kg*harga*hari;
  const varCost = F('bahan')*kg*hari;
  const fixCost = F('sewa')+F('gaji')+F('util')+F('lain');
  const laba = omset - varCost - fixCost;
  const margin = omset>0 ? (laba/omset*100) : 0;
  // BEP: kg/hari agar laba = 0 → fix / ((harga-bahan)*hari)
  const kontribusi = harga - F('bahan');
  const bep = (kontribusi>0 && hari>0) ? (fixCost/(kontribusi*hari)) : 0;

  const el=document.getElementById('laba');
  el.textContent = rp(laba); el.className = 'big ' + (laba>=0?'pos':'neg');
  document.getElementById('omset').textContent = rp(omset);
  document.getElementById('margin').textContent = margin.toFixed(1)+'%';
  document.getElementById('bep').textContent = bep>0 ? bep.toFixed(1)+' kg' : '–';

  let tip;
  if (laba < 0) tip = '⚠️ <strong>Usahamu rugi '+rp(Math.abs(laba))+'/bulan.</strong> Kamu butuh minimal <strong>'+bep.toFixed(1)+' kg/hari</strong> untuk balik modal (sekarang '+kg+' kg). Cek: harga per kg terlalu murah, atau biaya tetap terlalu berat?';
  else if (margin < 15) tip = '🟡 Margin '+margin.toFixed(1)+'% tergolong tipis untuk laundry (sehat: 20–35%). Naikkan harga Rp 500–1000/kg atau tambah layanan satuan (bed cover, sepatu) yang marginnya lebih tebal.';
  else if (margin < 35) tip = '🟢 Margin '+margin.toFixed(1)+'% — sehat! Titik impasmu '+bep.toFixed(1)+' kg/hari, kamu di '+kg+' kg. Fokus berikutnya: jaga pelanggan balik lagi (poin/member) & pantau biaya bahan per kg.';
  else tip = '🔥 Margin '+margin.toFixed(1)+'% — luar biasa. Pastikan angka biayamu lengkap (penyusutan mesin sering kelupaan). Kalau beneran setebal ini, saatnya buka cabang ke-2.';
  tip += '<br><br>💡 Angka di atas cuma estimasi kasar. Laba <em>sebenarnya</em> baru kelihatan kalau tiap transaksi & pengeluaran tercatat — itu yang LAMASY kerjakan otomatis.';
  document.getElementById('insight').innerHTML = tip;
}
document.querySelectorAll('input[type=number]').forEach(i=>i.addEventListener('input',hitung));
hitung();

async function sendLead(){
  const em=document.getElementById('leadEmail').value.trim();
  const btn=document.getElementById('leadBtn');
  if(!em) return;
  btn.disabled=true; btn.textContent='…';
  try{
    const r=await fetch('/api/lead.php',{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({email:em,source:'kalkulator'})});
    const d=await r.json();
    btn.textContent = d.ok ? '✓ Terkirim' : 'Gagal';
    if(!d.ok){ btn.disabled=false; }
  }catch(e){ btn.textContent='Gagal'; btn.disabled=false; }
}
</script>
</body>
</html>

<?php
// ══════════════════════════════════════════════════════════════════
// hq/coin-info.php — Tampilkan harga coin per fitur (view-only)
// Transparansi pricing untuk owner — tahu berapa coin per fitur
// ══════════════════════════════════════════════════════════════════

$activePage = 'hq-coin-info';
$pageTitle  = 'Harga Fitur Coin';

define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';
require_once ROOT . '/core/CoinLedger.php';

$db   = Database::get();
$tid  = (int)$hqTenant['id'];

// Ambil semua pricing aktif dari DB
try {
    $pricing = $db->query(
        "SELECT feature_key, nama_fitur, kategori, harga_coin, deskripsi
           FROM saas_coin_pricing
          WHERE is_active = 1
          ORDER BY kategori, harga_coin DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $pricing = []; // tabel belum ada (migration belum dijalankan)
}

// Group by kategori
$grouped = [];
foreach ($pricing as $p) {
    $grouped[$p['kategori']][] = $p;
}

// Ambil coin bundles untuk top-up
try {
    $bundles = $db->query(
        "SELECT id, nama, harga, coin_didapat, bonus_pct, is_featured
           FROM saas_coin_bundles
          WHERE is_active = 1
          ORDER BY urutan ASC, id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $bundles = [];
}

// Saldo coin sekarang
$saldo = (int)($hqTenant['coin_balance'] ?? 0);

// ── AJAX: Riwayat & Pemakaian Coin (read-only, tenant scope) ──
$action = $_GET['action'] ?? '';
if ($action !== '') {
    header('Content-Type: application/json');
    // Validasi periode YYYY-MM, fallback bulan ini (Asia/Jakarta)
    $bulan = (string)($_GET['bulan'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}$/', $bulan)) $bulan = date('Y-m');
    $periodeStart = $bulan . '-01 00:00:00';
    $periodeEnd   = date('Y-m-01 00:00:00', strtotime($bulan . '-01 +1 month'));
    try {
        if ($action === 'coin_summary') {
            $s = $db->prepare(
                "SELECT
                   COALESCE(SUM(CASE WHEN type='topup'  THEN amount END),0) AS topup,
                   COALESCE(SUM(CASE WHEN type='deduct' THEN amount END),0) AS deduct,
                   COUNT(*) AS cnt
                 FROM coin_ledger
                 WHERE tenant_id=? AND created_at >= ? AND created_at < ?");
            $s->execute([$tid, $periodeStart, $periodeEnd]);
            $r = $s->fetch(PDO::FETCH_ASSOC) ?: ['topup'=>0,'deduct'=>0,'cnt'=>0];
            echo json_encode(['ok'=>true, 'saldo'=>$saldo,
                'topup'=>(int)$r['topup'], 'deduct'=>(int)$r['deduct'], 'count'=>(int)$r['cnt']]);
            exit;
        }
        if ($action === 'coin_breakdown') {
            $s = $db->prepare(
                "SELECT cl.feature_used,
                        COALESCE(p.nama_fitur, cl.feature_used, 'Lainnya') AS nama,
                        COALESCE(p.kategori, 'lainnya') AS kategori,
                        SUM(cl.amount) AS total
                 FROM coin_ledger cl
                 LEFT JOIN saas_coin_pricing p ON p.feature_key = cl.feature_used
                 WHERE cl.tenant_id=? AND cl.type='deduct'
                   AND cl.created_at >= ? AND cl.created_at < ?
                 GROUP BY cl.feature_used
                 ORDER BY total DESC");
            $s->execute([$tid, $periodeStart, $periodeEnd]);
            $perFitur = $s->fetchAll(PDO::FETCH_ASSOC);
            $perKat = []; $totalDeduct = 0;
            foreach ($perFitur as &$f) {
                $f['total'] = (int)$f['total'];
                $totalDeduct += $f['total'];
                $k = $f['kategori'];
                $perKat[$k] = ($perKat[$k] ?? 0) + $f['total'];
            }
            unset($f);
            $katArr = [];
            foreach ($perKat as $k => $v) $katArr[] = ['kategori'=>$k, 'total'=>$v];
            usort($katArr, fn($a,$b) => $b['total'] - $a['total']);
            echo json_encode(['ok'=>true, 'per_fitur'=>$perFitur,
                'per_kategori'=>$katArr, 'total_deduct'=>$totalDeduct]);
            exit;
        }
        if ($action === 'coin_ledger') {
            $type = $_GET['type'] ?? 'semua';
            $page = max(1, (int)($_GET['page'] ?? 1));
            $per  = 30; $off = ($page - 1) * $per;
            $typeSql = ''; $params = [$tid, $periodeStart, $periodeEnd];
            if ($type === 'topup' || $type === 'deduct') { $typeSql = ' AND cl.type=?'; }
            // total count
            $cParams = $params; if ($typeSql) $cParams[] = $type;
            $c = $db->prepare("SELECT COUNT(*) FROM coin_ledger cl
                               WHERE cl.tenant_id=? AND cl.created_at >= ? AND cl.created_at < ?{$typeSql}");
            $c->execute($cParams);
            $total = (int)$c->fetchColumn();
            // rows
            $lParams = $params; if ($typeSql) $lParams[] = $type;
            $lParams[] = $per; $lParams[] = $off;
            $l = $db->prepare(
                "SELECT cl.type, cl.amount, cl.feature_used, cl.description, cl.balance_after, cl.created_at,
                        COALESCE(p.nama_fitur, cl.feature_used, '-') AS nama_fitur,
                        o.nama_outlet
                 FROM coin_ledger cl
                 LEFT JOIN saas_coin_pricing p ON p.feature_key = cl.feature_used
                 LEFT JOIN outlets o ON o.id = cl.outlet_id AND o.tenant_id = cl.tenant_id
                 WHERE cl.tenant_id=? AND cl.created_at >= ? AND cl.created_at < ?{$typeSql}
                 ORDER BY cl.created_at DESC
                 LIMIT ? OFFSET ?");
            $l->execute($lParams);
            echo json_encode(['ok'=>true, 'rows'=>$l->fetchAll(PDO::FETCH_ASSOC),
                'total'=>$total, 'page'=>$page, 'pages'=>(int)ceil($total / $per)]);
            exit;
        }
        echo json_encode(['ok'=>false, 'error'=>'Unknown action']); exit;
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]); exit;
    }
}

$katMeta = [
    'dokumen'  => ['ico' => '📄', 'label' => 'Dokumen & Nota'],
    'whatsapp' => ['ico' => '📱', 'label' => 'WhatsApp Notifikasi'],
    'ai'       => ['ico' => '🤖', 'label' => 'AI Tools'],
    'export'   => ['ico' => '📤', 'label' => 'Export Laporan'],
    'lainnya'  => ['ico' => '⚙️', 'label' => 'Lainnya'],
];
?>
<?php require __DIR__ . '/_layout_open.php'; ?>

<style>
  h1 { font-size:1.4rem;font-weight:800;color:#0F1C3A;margin-bottom:6px; }
  .info-banner {
    background:linear-gradient(135deg,#0F1C3A 0%,#1B2D5A 100%);
    color:#fff; padding:18px 22px; border-radius:14px; margin-bottom:20px;
    display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;
  }
  .info-banner .saldo-label { font-size:12px; color:rgba(255,255,255,.6); text-transform:uppercase; letter-spacing:.05em; }
  .info-banner .saldo-num { font-size:28px; font-weight:800; color:#35E8D5; font-family:'DM Mono',monospace; margin-top:2px; }
  .info-banner .info-text { font-size:13px; color:rgba(255,255,255,.7); max-width:380px; }

  .kat-section { margin-bottom:24px; }
  .kat-header {
    display:flex; align-items:center; gap:10px; margin-bottom:10px;
    padding-bottom:8px; border-bottom:2px solid #E8FBF9;
  }
  .kat-header .ico { font-size:20px; }
  .kat-header h3 { font-size:14px; font-weight:700; color:#0F1C3A; margin:0; }

  .pricing-grid {
    display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:10px;
  }
  .pricing-card {
    background:#fff; border:1px solid #E5E7EB; border-radius:10px; padding:14px;
    display:flex; justify-content:space-between; align-items:flex-start; gap:10px;
    transition:all .15s;
  }
  .pricing-card:hover { border-color:#35E8D5; box-shadow:0 2px 8px rgba(53,232,213,.15); }
  .pricing-card .nama { font-size:13px; font-weight:600; color:#1B2D5A; }
  .pricing-card .desk { font-size:11px; color:#6C7A8D; margin-top:2px; line-height:1.4; }
  .pricing-card .key  { font-size:10px; font-family:'DM Mono',monospace; color:#9CA3AF; margin-top:4px; }
  .pricing-card .harga {
    font-size:18px; font-weight:800; color:#0F1C3A; font-family:'DM Mono',monospace;
    white-space:nowrap; text-align:right;
  }
  .pricing-card .harga small { display:block; font-size:10px; font-weight:500; color:#9CA3AF; font-family:'Plus Jakarta Sans',sans-serif; margin-top:2px; }
  .pricing-card .harga.gratis { color:#10B981; }

  .empty-state {
    text-align:center; padding:60px 20px; background:#fff; border-radius:14px; border:1px dashed #E5E7EB;
    color:#6C7A8D;
  }
  .empty-state .ico { font-size:40px; margin-bottom:10px; opacity:.4; }

  .note-footer {
    margin-top:24px; padding:14px; background:#F7F8FC; border-radius:10px;
    font-size:12px; color:#6C7A8D; line-height:1.5;
  }

  .bundle-section { margin-bottom:30px; }
  .bundle-section h2 {
    font-size:16px; font-weight:700; color:#0F1C3A; margin-bottom:16px; display:flex; align-items:center; gap:8px;
  }
  .bundle-grid {
    display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:14px;
  }
  .bundle-card {
    background:#fff; border:1.5px solid #E5E7EB; border-radius:12px; padding:16px;
    display:flex; flex-direction:column; gap:12px;
    transition:all .15s;
    position:relative;
  }
  .bundle-card:hover { border-color:#35E8D5; box-shadow:0 4px 12px rgba(53,232,213,.12); }
  .bundle-card.featured { border-color:#35E8D5; background:linear-gradient(135deg,rgba(53,232,213,.05) 0%,rgba(53,232,213,.02) 100%); }
  .bundle-card.featured::before { content:'⭐ REKOMENDASI'; position:absolute; top:8px; right:8px; font-size:9px; font-weight:700; background:#35E8D5; color:#0F1C3A; padding:3px 8px; border-radius:4px; letter-spacing:.05em; }
  .bundle-card .nama { font-size:14px; font-weight:700; color:#0F1C3A; }
  .bundle-card .info { font-size:12px; color:#6C7A8D; line-height:1.4; }
  .bundle-card .harga-section { display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-top:1px solid #E5E7EB; border-bottom:1px solid #E5E7EB; }
  .bundle-card .harga-section .harga { font-size:16px; font-weight:800; color:#0F1C3A; font-family:'DM Mono',monospace; }
  .bundle-card .harga-section .harga small { display:block; font-size:11px; font-weight:500; color:#9CA3AF; }
  .bundle-card .coin-amount { font-size:13px; font-weight:700; color:#35E8D5; text-align:right; font-family:'DM Mono',monospace; }
  .bundle-card .bonus { font-size:11px; color:#10B981; font-weight:600; margin-top:4px; }
  .bundle-card .btn-topup {
    display:block; text-align:center; padding:10px 14px; background:#0F1C3A; color:#fff;
    border-radius:8px; border:none; cursor:pointer; font-weight:600; font-size:13px;
    transition:all .15s; text-decoration:none;
  }
  .bundle-card .btn-topup:hover { background:#1B2D5A; transform:translateY(-1px); box-shadow:0 4px 12px rgba(15,28,58,.2); }

  /* ── Coin Tabs ── */
  .coin-tabs { display:flex; gap:8px; margin-bottom:18px; border-bottom:2px solid #F0F0F3; }
  .coin-tab { background:none; border:none; padding:10px 16px; font-size:14px; font-weight:600; color:#6B7280; cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-2px; }
  .coin-tab.active { color:#0F1C3A; border-bottom-color:#35E8D5; }
  .cu-toolbar { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px; }
  .cu-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:20px; }
  .cu-card { background:#fff; border:1px solid #EEF0F3; border-radius:12px; padding:14px 16px; }
  .cu-card .lbl { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#9CA3AF; }
  .cu-card .val { font-size:22px; font-weight:800; font-family:'DM Mono',monospace; margin-top:4px; }
  .cu-section { margin-bottom:22px; }
  .cu-bar-row { display:flex; align-items:center; gap:10px; margin-bottom:8px; font-size:13px; }
  .cu-bar-row .cu-bar-lbl { width:150px; flex-shrink:0; }
  .cu-bar-track { flex:1; background:#F0F0F3; border-radius:6px; height:14px; overflow:hidden; }
  .cu-bar-fill { height:100%; background:linear-gradient(90deg,#35E8D5,#1B9E92); }
  .cu-bar-val { width:130px; text-align:right; flex-shrink:0; color:#6B7280; font-family:'DM Mono',monospace; }
  .cu-ledger-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
  .cu-table { width:100%; border-collapse:collapse; font-size:13px; }
  .cu-table th { text-align:left; padding:8px; color:#9CA3AF; font-size:11px; text-transform:uppercase; border-bottom:1px solid #EEF0F3; }
  .cu-table td { padding:8px; border-bottom:1px solid #F5F5F7; }
  .cu-amt-deduct { color:#DC2626; font-weight:600; }
  .cu-amt-topup { color:#059669; font-weight:600; }
  .cu-pager { display:flex; gap:8px; justify-content:center; margin-top:14px; }
  .cu-pager button { padding:6px 14px; border:1px solid #E5E7EB; border-radius:8px; background:#fff; cursor:pointer; font-size:13px; }
  .cu-pager button:disabled { opacity:.4; cursor:default; }
  .cu-empty { text-align:center; padding:30px; color:#9CA3AF; font-size:14px; }
</style>

<h1>💲 Harga Fitur Coin
  <small style="display:block;font-size:13px;font-weight:400;color:#6B7280;margin-top:2px;">
    Daftar harga coin per fitur. Update otomatis dari sistem.
  </small>
</h1>

<div class="coin-tabs">
  <button class="coin-tab active" data-tab="harga" onclick="switchCoinTab('harga',this)">💰 Harga Fitur</button>
  <button class="coin-tab" data-tab="riwayat" onclick="switchCoinTab('riwayat',this)">📊 Riwayat &amp; Pemakaian</button>
</div>

<div id="tab-harga" class="coin-tab-pane">

<!-- Saldo Banner -->
<div class="info-banner">
  <div>
    <div class="saldo-label">Saldo Coin Anda</div>
    <div class="saldo-num"><?= number_format($saldo, 0, ',', '.') ?></div>
  </div>
  <div class="info-text">
    <strong>Transparansi pricing:</strong> setiap fitur memotong coin sesuai harga di bawah.
    Harga dapat berubah sewaktu-waktu — selalu cek halaman ini untuk pricing terbaru.
  </div>
</div>

<?php if (empty($pricing)): ?>
  <div class="empty-state">
    <div class="ico">💲</div>
    <p>Belum ada data pricing.<br>
    <small style="color:#9CA3AF">Hubungi admin platform jika ini error.</small></p>
  </div>
<?php else: ?>

  <?php foreach ($katMeta as $kat => $meta): ?>
    <?php if (empty($grouped[$kat])) continue; ?>
    <div class="kat-section">
      <div class="kat-header">
        <span class="ico"><?= $meta['ico'] ?></span>
        <h3><?= htmlspecialchars($meta['label']) ?></h3>
      </div>
      <div class="pricing-grid">
        <?php foreach ($grouped[$kat] as $p): ?>
          <div class="pricing-card">
            <div style="flex:1;min-width:0;">
              <div class="nama"><?= htmlspecialchars($p['nama_fitur']) ?></div>
              <?php if (!empty($p['deskripsi'])): ?>
                <div class="desk"><?= htmlspecialchars($p['deskripsi']) ?></div>
              <?php endif; ?>
              <div class="key"><?= htmlspecialchars($p['feature_key']) ?></div>
            </div>
            <div class="harga <?= (int)$p['harga_coin'] === 0 ? 'gratis' : '' ?>">
              <?php if ((int)$p['harga_coin'] === 0): ?>
                GRATIS
              <?php else: ?>
                <?= number_format($p['harga_coin'], 0, ',', '.') ?>
                <small>coin</small>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>

<?php endif; ?>

<!-- Coin Bundles Top-up Section -->
<?php if (!empty($bundles)): ?>
  <div class="bundle-section">
    <h2>💳 Top-up Coin Sekarang</h2>
    <div class="bundle-grid">
      <?php foreach ($bundles as $b): ?>
        <div class="bundle-card <?= !empty($b['is_featured']) ? 'featured' : '' ?>">
          <div class="nama"><?= htmlspecialchars($b['nama']) ?></div>
          <div class="info">
            Beli paket coin dan dapatkan akses ke fitur premium
          </div>
          <div class="harga-section">
            <div>
              <div class="harga">
                Rp <?= number_format($b['harga'], 0, ',', '.') ?>
                <small>via QRIS/VA</small>
              </div>
            </div>
            <div>
              <div class="coin-amount"><?= number_format($b['coin_didapat'], 0, ',', '.') ?></div>
              <div style="font-size:10px;color:#6C7A8D;">coin</div>
            </div>
          </div>
          <?php if (!empty($b['bonus_pct']) && (float)$b['bonus_pct'] > 0): ?>
            <div class="bonus">✨ Bonus <?= number_format($b['bonus_pct'], 0) ?>% coin!</div>
          <?php endif; ?>
          <a href="/billing-checkout.php?type=topup_coin&bundle_id=<?= (int)$b['id'] ?>"
             class="btn-topup">
            💳 Top-up Sekarang
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<div class="note-footer">
  💡 <strong>Tips hemat coin:</strong>
  Manfaatkan fitur AI di outlet yang traffic-nya tinggi (lebih cost-effective).
  WA notif otomatis bisa di-disable per pelanggan jika tidak perlu.
  Beli paket coin Popular (250rb / 60.000 coin + bonus 20%) untuk discount terbaik.
  <br><br>
  ⚠️ <strong>Catatan:</strong>
  Harga dapat berubah sewaktu-waktu sesuai kebijakan platform.
  Cek halaman ini secara berkala untuk pricing terbaru. Setiap perubahan harga didokumentasikan di history platform.
</div>

</div><!-- /#tab-harga -->

<div id="tab-riwayat" class="coin-tab-pane" style="display:none">
  <div class="cu-toolbar">
    <h2 style="font-size:1.1rem;font-weight:700;color:#0F1C3A;margin:0">📊 Riwayat &amp; Pemakaian Coin</h2>
    <label style="font-size:13px;color:#6B7280">Periode:
      <input type="month" id="cuBulan" value="<?= date('Y-m') ?>" style="padding:6px 8px;border:1px solid #E5E7EB;border-radius:8px">
    </label>
  </div>
  <div id="cuCards" class="cu-cards"></div>
  <div id="cuBreakdown" class="cu-section"></div>
  <div class="cu-section">
    <div class="cu-ledger-head">
      <strong>Rincian Transaksi</strong>
      <select id="cuType" style="padding:6px 8px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
        <option value="semua">Semua</option><option value="deduct">Pemakaian</option><option value="topup">Top-up</option>
      </select>
    </div>
    <div id="cuLedger"></div>
    <div id="cuPager" class="cu-pager"></div>
  </div>
</div><!-- /#tab-riwayat -->

<script>
const CU_KAT = {
  dokumen:{ico:'📄',label:'Dokumen'}, whatsapp:{ico:'📱',label:'WhatsApp'},
  ai:{ico:'🤖',label:'AI Tools'}, export:{ico:'📤',label:'Export'}, lainnya:{ico:'⚙️',label:'Lainnya'}
};
let cuPage = 1, cuLoaded = false;

function switchCoinTab(tab, el) {
  document.querySelectorAll('.coin-tab').forEach(b => b.classList.toggle('active', b === el));
  document.getElementById('tab-harga').style.display   = tab === 'harga'   ? '' : 'none';
  document.getElementById('tab-riwayat').style.display = tab === 'riwayat' ? '' : 'none';
  if (tab === 'riwayat' && !cuLoaded) { cuLoaded = true; cuLoadAll(); }
}
function cuBulan() { return document.getElementById('cuBulan').value || ''; }

async function cuFetch(action, extra='') {
  const r = await fetch(`/hq/coin-info?action=${action}&bulan=${cuBulan()}${extra}`,
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
  return r.json();
}

async function cuLoadAll() { cuPage = 1; await Promise.all([cuLoadSummary(), cuLoadBreakdown(), cuLoadLedger()]); }

async function cuLoadSummary() {
  const d = await cuFetch('coin_summary');
  if (!d.ok) return;
  document.getElementById('cuCards').innerHTML =
    `<div class="cu-card"><div class="lbl">Saldo Coin</div><div class="val" style="color:#0F1C3A">${fmtNum(d.saldo)}</div></div>
     <div class="cu-card"><div class="lbl">Terpakai (periode)</div><div class="val" style="color:#DC2626">−${fmtNum(d.deduct)}</div></div>
     <div class="cu-card"><div class="lbl">Top-up (periode)</div><div class="val" style="color:#059669">+${fmtNum(d.topup)}</div></div>`;
}

async function cuLoadBreakdown() {
  const d = await cuFetch('coin_breakdown');
  const box = document.getElementById('cuBreakdown');
  if (!d.ok || !d.total_deduct) { box.innerHTML = '<div class="cu-empty">Belum ada pemakaian periode ini</div>'; return; }
  let html = '<strong style="display:block;margin-bottom:12px">Pemakaian per Kategori</strong>';
  d.per_kategori.forEach(k => {
    const meta = CU_KAT[k.kategori] || CU_KAT.lainnya;
    const pct = Math.round(k.total / d.total_deduct * 100);
    html += `<div class="cu-bar-row">
      <span class="cu-bar-lbl">${meta.ico} ${esc(meta.label)}</span>
      <span class="cu-bar-track"><span class="cu-bar-fill" style="width:${pct}%"></span></span>
      <span class="cu-bar-val">${fmtNum(k.total)} (${pct}%)</span></div>`;
  });
  box.innerHTML = html;
}

async function cuLoadLedger() {
  const type = document.getElementById('cuType').value;
  const d = await cuFetch('coin_ledger', `&type=${type}&page=${cuPage}`);
  const box = document.getElementById('cuLedger');
  if (!d.ok || !d.rows.length) { box.innerHTML = '<div class="cu-empty">Belum ada transaksi periode ini</div>'; document.getElementById('cuPager').innerHTML=''; return; }
  let rows = d.rows.map(r => {
    const isDed = r.type === 'deduct';
    const amt = (isDed ? '−' : '+') + fmtNum(r.amount);
    const cls = isDed ? 'cu-amt-deduct' : 'cu-amt-topup';
    const tgl = new Date(r.created_at.replace(' ','T')).toLocaleString('id-ID',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'});
    return `<tr><td>${esc(tgl)}</td><td>${esc(r.nama_fitur||'-')}</td><td>${esc(r.nama_outlet||'—')}</td>
      <td class="${cls}">${amt}</td><td style="font-family:'DM Mono',monospace">${fmtNum(r.balance_after)}</td></tr>`;
  }).join('');
  box.innerHTML = `<table class="cu-table"><thead><tr><th>Tanggal</th><th>Fitur</th><th>Outlet</th><th>Coin</th><th>Saldo</th></tr></thead><tbody>${rows}</tbody></table>`;
  document.getElementById('cuPager').innerHTML =
    `<button onclick="cuPage--;cuLoadLedger()" ${d.page<=1?'disabled':''}>‹ Prev</button>
     <span style="align-self:center;font-size:13px;color:#6B7280">Hal ${d.page}/${d.pages||1}</span>
     <button onclick="cuPage++;cuLoadLedger()" ${d.page>=d.pages?'disabled':''}>Next ›</button>`;
}

document.addEventListener('DOMContentLoaded', () => {
  const b = document.getElementById('cuBulan'); if (b) b.addEventListener('change', cuLoadAll);
  const t = document.getElementById('cuType'); if (t) t.addEventListener('change', () => { cuPage=1; cuLoadLedger(); });
});
</script>

<?php require __DIR__ . '/_layout_close.php'; ?>

<?php
// ══════════════════════════════════════════════════════
// hq/billing.php — Manajemen Coin & Billing Konsolidasi
//   #7 topup distribusi · #8 monitor per outlet/fitur
//   #9 transfer antar outlet · #10 budget per outlet
// ══════════════════════════════════════════════════════

$activePage = 'hq-billing';
$pageTitle  = 'Coin & Billing';

define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';
require_once ROOT . '/core/CoinLedger.php';

$db   = Database::get();
$tid  = (int)$hqTenant['id'];
$action = $_GET['action'] ?? '';
$canManage = !empty($hqCanBilling);

// Coin mode tenant
$coinMode = 'shared';
try {
    $cm = $db->prepare("SELECT coin_mode FROM tenants WHERE id=?");
    $cm->execute([$tid]);
    $coinMode = $cm->fetchColumn() ?: 'shared';
} catch (Throwable) {}

function dateRange(): array {
    $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start'] ?? '') ? $_GET['start'] : date('Y-m-01');
    $end   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end'] ?? '')   ? $_GET['end']   : date('Y-m-d');
    return [$start, $end];
}

// ── API: monitor data ────────────────────────────────
if ($action === 'monitor') {
    header('Content-Type: application/json');
    [$start, $end] = dateRange();
    $outletId = (int)($_GET['outlet_id'] ?? 0);
    try {
        echo json_encode([
            'ok' => true,
            'by_outlet'  => CoinLedger::usageByOutlet($tid, $start, $end),
            'by_feature' => CoinLedger::usageByFeature($tid, $start, $end, $outletId),
            'coin_mode'  => $coinMode,
            'tenant_balance' => CoinLedger::balance($tid),
        ]);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ── API: set budget ──────────────────────────────────
if ($action === 'set_budget' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!$canManage) { echo json_encode(['error'=>'Akses ditolak']); exit; }
    // CSRF: token dikirim via header X-CSRF-Token
    $csrfGiven = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$csrfGiven || !hash_equals(getCsrfToken(), $csrfGiven)) {
        http_response_code(403);
        echo json_encode(['error'=>'CSRF mismatch']); exit;
    }
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    try {
        CoinLedger::setBudget($tid, (int)($d['outlet_id'] ?? 0), (int)($d['budget'] ?? 0));
        echo json_encode(['ok'=>true]);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ── API: transfer antar outlet ───────────────────────
if ($action === 'transfer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!$canManage) { echo json_encode(['error'=>'Akses ditolak']); exit; }
    // CSRF: token dikirim via header X-CSRF-Token
    $csrfGiven = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$csrfGiven || !hash_equals(getCsrfToken(), $csrfGiven)) {
        http_response_code(403);
        echo json_encode(['error'=>'CSRF mismatch']); exit;
    }
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    try {
        CoinLedger::transferBetweenOutlets($tid,
            (int)($d['from'] ?? 0), (int)($d['to'] ?? 0), (int)($d['amount'] ?? 0), $d['desc'] ?? '');
        echo json_encode(['ok'=>true]);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

// ── API: set coin mode ───────────────────────────────
if ($action === 'set_coin_mode' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!TenantResolver::isOwnerLevel()) { echo json_encode(['error'=>'Akses ditolak (owner saja)']); exit; }
    $csrfGiven = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$csrfGiven || !hash_equals(getCsrfToken(), $csrfGiven)) { http_response_code(403); echo json_encode(['error'=>'CSRF mismatch']); exit; }
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    require_once ROOT . '/core/CoinModeManager.php';
    $mode = ($d['mode'] ?? '') === 'per_outlet' ? 'per_outlet' : 'shared';
    $res = CoinModeManager::switchMode($tid, $mode, 'owner:'.(int)($_SESSION['user_id'] ?? 0));
    if (!$res['ok']) { echo json_encode(['error'=>$res['error'] ?? 'Gagal']); exit; }
    echo json_encode(['ok'=>true, 'mode'=>$mode, 'moved'=>$res['moved']]); exit;
}

// ── API: set outlet penanggung coin HQ ──────────────
if ($action === 'set_hq_coin_outlet' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!TenantResolver::isOwnerLevel()) { echo json_encode(['error'=>'Akses ditolak (owner saja)']); exit; }
    $csrfGiven = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$csrfGiven || !hash_equals(getCsrfToken(), $csrfGiven)) { http_response_code(403); echo json_encode(['error'=>'CSRF mismatch']); exit; }
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $oid = (int)($d['outlet_id'] ?? 0);
    if ($oid > 0) {
        $chk = $db->prepare("SELECT id FROM outlets WHERE id=? AND tenant_id=? AND status<>'closed' LIMIT 1");
        $chk->execute([$oid, $tid]);
        if (!$chk->fetchColumn()) { echo json_encode(['error'=>'Outlet tidak valid']); exit; }
    }
    $db->prepare("UPDATE tenants SET hq_coin_outlet_id=? WHERE id=?")->execute([$oid ?: null, $tid]);
    echo json_encode(['ok'=>true]); exit;
}

$outlets = $db->prepare("SELECT id, nama_outlet, status, coin_balance, trial_coin_balance FROM outlets WHERE tenant_id=? AND status IN ('trial','grace','active') ORDER BY is_main DESC, nama_outlet");
$outlets->execute([$tid]);
$outletList = $outlets->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/_layout_open.php';
?>
<?php
$row = $db->prepare("SELECT coin_mode, hq_coin_outlet_id, coin_balance FROM tenants WHERE id=?");
$row->execute([$tid]);
$tinfo = $row->fetch(PDO::FETCH_ASSOC) ?: ['coin_mode'=>'shared','hq_coin_outlet_id'=>null,'coin_balance'=>0];
$curMode = $tinfo['coin_mode'];
$hqOutletId = (int)($tinfo['hq_coin_outlet_id'] ?? 0);
$cmOutlets = $db->prepare("SELECT id, nama_outlet, is_main, coin_balance FROM outlets WHERE tenant_id=? AND status<>'closed' ORDER BY is_main DESC, id ASC");
$cmOutlets->execute([$tid]);
$outletsList = $cmOutlets->fetchAll(PDO::FETCH_ASSOC);
$isOwner = TenantResolver::isOwnerLevel();
$csrf = getCsrfToken();
?>
<style>
.bl-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:10px}
.bl-head h1{font-size:1.3rem;font-weight:800;color:#0F1C3A}
.bl-mode{font-size:11px;font-weight:700;padding:3px 10px;border-radius:100px;background:#E0F2FE;color:#0369A1}
.panel{background:#fff;border:1px solid #EEF1F8;border-radius:14px;padding:20px;box-shadow:0 1px 6px rgba(0,0,0,.05);margin-bottom:16px}
.panel-title{font-size:14px;font-weight:700;color:#0F1C3A;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px}
.btn{padding:8px 14px;border-radius:8px;font-weight:700;font-size:13px;border:none;cursor:pointer;font-family:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.btn-primary{background:#0F1C3A;color:#fff}.btn-primary:hover{background:#1a2d52}
.btn-light{background:#fff;color:#0F1C3A;border:1px solid #E5E9F2}.btn-sm{padding:6px 11px;font-size:12px}
.filter{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:16px}
.filter input,.filter select{padding:7px 11px;border:1px solid #E5E9F2;border-radius:8px;font-family:inherit;font-size:13px}
.tbl{width:100%;border-collapse:collapse;font-size:13px}
.tbl th{background:#F7F8FC;padding:9px 11px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;color:#6B7280}
.tbl td{padding:10px 11px;border-top:1px solid #F0F1F4}
.tbl .num{font-family:var(--mono);font-weight:700;text-align:right}
/* ≤700px: tabel pemakaian per outlet jadi kartu bertumpuk (tak ada yg terpotong) */
@media(max-width:700px){
  #outletBox table.tbl,#outletBox thead,#outletBox tbody,#outletBox tr,#outletBox td{display:block;width:100%;box-sizing:border-box}
  #outletBox thead{display:none}
  #outletBox tr{border:1px solid #EEF1F8;border-radius:10px;padding:4px 2px;margin-bottom:10px;background:#fff}
  #outletBox td{border:0;padding:7px 12px;display:flex;justify-content:space-between;align-items:center;gap:12px;text-align:right}
  #outletBox td::before{content:attr(data-label);font-weight:700;color:#6B7280;font-size:11px;text-transform:uppercase;letter-spacing:.03em;text-align:left;flex:0 0 auto}
  #outletBox td.cell-name{justify-content:flex-start;font-size:15px;padding-top:9px;border-bottom:1px solid #F3F4F6;margin-bottom:3px}
  #outletBox td.cell-name::before{display:none}
  #outletBox td .bar{flex:1 1 auto;max-width:140px;margin-top:0}
  #outletBox td.cell-act{justify-content:flex-end}
  #outletBox td.cell-act::before{display:none}
  #outletBox td.cell-act .btn{width:100%;justify-content:center}
}
.bar{background:#EEF1F8;border-radius:100px;height:7px;overflow:hidden;margin-top:3px}
.bar-fill{height:100%;background:#35E8D5}
.bar-fill.over{background:#EF4444}
.budget-warn{color:#EF4444;font-weight:700}
.grid2{display:grid;grid-template-columns:1.4fr 1fr;gap:16px}
@media(max-width:900px){.grid2{grid-template-columns:1fr}}
.modal-bg{display:none;position:fixed;inset:0;background:rgba(15,28,58,.5);z-index:1000;align-items:flex-start;justify-content:center;padding:40px 16px;overflow-y:auto}
.modal-bg.open{display:flex}
.modal{background:#fff;border-radius:14px;width:100%;max-width:440px;padding:24px}
.modal h3{font-size:16px;font-weight:800;color:#0F1C3A;margin-bottom:16px}
.fld{margin-bottom:14px}.fld label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px}
.fld input,.fld select{width:100%;padding:9px 12px;border:1px solid #E5E9F2;border-radius:8px;font-family:inherit;font-size:14px}
.modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:18px}
.empty{text-align:center;padding:30px;color:#9CA3AF;font-size:13px}
@media(max-width:900px){
  .filter{gap:8px}
  .filter select,.filter input{flex:1 1 auto;min-width:0}
}
</style>

<div class="bl-head">
  <h1>💳 Coin & Billing</h1>
  <div style="display:flex;gap:8px;align-items:center">
    <span class="bl-mode">Mode: <?= $coinMode === 'per_outlet' ? 'Per-Outlet' : 'Shared' ?></span>
    <?php if ($canManage && $coinMode === 'per_outlet'): ?>
      <button class="btn btn-primary btn-sm" onclick="openTransfer()">🔄 Transfer Coin</button>
    <?php endif; ?>
  </div>
</div>
<p style="font-size:13px;color:#6B7280;margin-bottom:16px">Monitor pemakaian coin tiap outlet & fitur, atur budget bulanan<?= $coinMode==='per_outlet'?', transfer saldo antar outlet':'' ?>.</p>

<?php if ($isOwner): ?>
<div class="panel" style="margin-bottom:16px">
  <div class="panel-title">🪙 Mode Coin</div>
  <p style="font-size:13px;color:#64748B;margin:6px 0 12px">
    <strong><?= $curMode === 'shared' ? 'Shared' : 'Per-Outlet' ?></strong> —
    <?= $curMode === 'shared' ? 'semua outlet pakai 1 saldo coin tenant.' : 'tiap outlet punya saldo coin sendiri.' ?>
  </p>
  <button class="btn btn-primary" onclick="toggleCoinMode()">
    🔄 Ganti ke <?= $curMode === 'shared' ? 'Per-Outlet' : 'Shared' ?>
  </button>

  <?php if ($curMode === 'per_outlet'): ?>
  <div style="margin-top:16px">
    <label style="font-size:13px;font-weight:700;display:block;margin-bottom:6px">Outlet penanggung coin fitur HQ</label>
    <select id="hqOutletSel" onchange="saveHqOutlet()" style="padding:8px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
      <?php foreach ($outletsList as $o): ?>
      <option value="<?= (int)$o['id'] ?>" <?= ($hqOutletId === (int)$o['id'] || ($hqOutletId === 0 && (int)$o['is_main'] === 1)) ? 'selected' : '' ?>>
        <?= htmlspecialchars($o['nama_outlet']) ?><?= (int)$o['is_main']===1?' (UTAMA)':'' ?> — <?= number_format((int)$o['coin_balance'],0,',','.') ?> coin
      </option>
      <?php endforeach; ?>
    </select>
    <p style="font-size:11px;color:#94A3B8;margin-top:6px">Fitur HQ (broadcast, laporan AI, dll) potong coin dari outlet ini.</p>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="filter">
  <label style="font-size:12px;color:#6B7280;font-weight:600">Periode:</label>
  <input type="date" id="fStart" value="<?= date('Y-m-01') ?>">
  <input type="date" id="fEnd" value="<?= date('Y-m-d') ?>">
  <button class="btn btn-light btn-sm" onclick="loadMonitor()">↻ Terapkan</button>
</div>

<div class="grid2">
  <!-- PER OUTLET -->
  <div class="panel">
    <div class="panel-title">📊 Pemakaian Coin per Outlet</div>
    <div id="outletBox"><div class="empty">⏳ Memuat…</div></div>
  </div>
  <!-- PER FITUR -->
  <div class="panel">
    <div class="panel-title">🧩 Pemakaian per Fitur</div>
    <div id="featureBox"><div class="empty">⏳ Memuat…</div></div>
  </div>
</div>

<!-- TRANSFER MODAL -->
<div class="modal-bg" id="transferModal">
  <div class="modal">
    <h3>🔄 Transfer Coin Antar Outlet</h3>
    <div class="fld">
      <label>Dari Outlet</label>
      <select id="trFrom"><?php foreach ($outletList as $o): ?>
        <?php
          // Trial outlet: tampilkan trial_coin_balance; outlet aktif: coin_balance
          $oCoinShow = ($o['status'] === 'trial')
            ? number_format((int)$o['trial_coin_balance']) . ' (trial)'
            : number_format((int)$o['coin_balance']);
        ?>
        <option value="<?= (int)$o['id'] ?>"><?= htmlspecialchars($o['nama_outlet']) ?> — <?= $oCoinShow ?> coin</option>
      <?php endforeach; ?></select>
    </div>
    <div class="fld">
      <label>Ke Outlet</label>
      <select id="trTo"><?php foreach ($outletList as $o): ?><option value="<?= (int)$o['id'] ?>"><?= htmlspecialchars($o['nama_outlet']) ?></option><?php endforeach; ?></select>
    </div>
    <div class="fld">
      <label>Jumlah Coin</label>
      <input type="number" id="trAmount" min="1" placeholder="1000">
    </div>
    <div class="fld">
      <label>Catatan (opsional)</label>
      <input type="text" id="trDesc" placeholder="Realokasi saldo">
    </div>
    <div class="modal-actions">
      <button class="btn btn-light" onclick="closeModal('transferModal')">Batal</button>
      <button class="btn btn-primary" onclick="doTransfer()">Transfer</button>
    </div>
  </div>
</div>

<!-- BUDGET MODAL -->
<div class="modal-bg" id="budgetModal">
  <div class="modal">
    <h3>🎯 Set Budget Coin Bulanan</h3>
    <input type="hidden" id="bdOutlet">
    <p id="bdOutletName" style="font-size:13px;color:#6B7280;margin-bottom:12px"></p>
    <div class="fld">
      <label>Budget per Bulan (coin) — 0 = unlimited</label>
      <input type="number" id="bdBudget" min="0" placeholder="0">
    </div>
    <div class="modal-actions">
      <button class="btn btn-light" onclick="closeModal('budgetModal')">Batal</button>
      <button class="btn btn-primary" onclick="saveBudget()">Simpan</button>
    </div>
  </div>
</div>

<script>
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const fmt = n => Number(n||0).toLocaleString('id-ID');
const CAN_MANAGE = <?= $canManage ? 'true':'false' ?>;
const COIN_MODE = '<?= $coinMode ?>';
const CSRF_TOKEN = <?= json_encode(getCsrfToken()) ?>;

function apiFetch(url, body) {
  return fetch(url, {
    method: 'POST',
    headers: {'Content-Type':'application/json', 'X-CSRF-Token': CSRF_TOKEN},
    body: JSON.stringify(body),
  });
}
const FEATURE_LABEL = {
  generate_nota:'🧾 Nota', send_wa_notif:'💬 WA Notif', send_wa_nota:'💬 WA Nota',
  ai_briefing:'✨ AI Briefing', ai_briefing_hq:'✨ AI Briefing HQ', ai_insight_laporan:'✨ AI Insight',
  ai_chat_data:'✨ AI Chat', ai_churn_message:'🎯 Smart Notif', ai_analyst:'✨ AI Analyst',
  generate_invoice:'📄 Invoice', wa_blast:'📢 WA Blast', export_pdf:'🖨️ Export PDF',
  transfer_out:'🔄 Transfer Keluar', transfer_in:'🔄 Transfer Masuk', topup:'➕ Topup',
};

async function loadMonitor(){
  const start = document.getElementById('fStart').value;
  const end = document.getElementById('fEnd').value;
  try {
    const r = await fetch(`/hq/billing.php?action=monitor&start=${start}&end=${end}`);
    const d = await r.json();
    if (d.error){ document.getElementById('outletBox').innerHTML = `<div class="empty">⚠️ ${esc(d.error)}</div>`; return; }
    renderOutlets(d.by_outlet, d.tenant_balance);
    renderFeatures(d.by_feature);
  } catch(e){ document.getElementById('outletBox').innerHTML = `<div class="empty">⚠️ ${esc(e.message)}</div>`; }
}

// Simpan tenant_balance dari response monitor untuk dipakai di renderOutlets
let _tenantBalance = 0;

function renderOutlets(rows, tenantBalance){
  _tenantBalance = tenantBalance || 0;
  const box = document.getElementById('outletBox');
  if (!rows.length){ box.innerHTML = '<div class="empty">Belum ada data.</div>'; return; }
  const maxUsed = Math.max(...rows.map(r=>Number(r.used)), 1);
  const isShared = COIN_MODE === 'shared';
  let html = '<table class="tbl"><thead><tr><th>Outlet</th><th style="text-align:right">Saldo</th><th style="text-align:right">Terpakai</th><th>Budget/bln</th>'+(CAN_MANAGE?'<th></th>':'')+'</tr></thead><tbody>';
  rows.forEach(o => {
    const used = Number(o.used), budget = Number(o.coin_budget_monthly||0);
    const pct = Math.round(used/maxUsed*100);
    const overBudget = budget > 0 && used > budget;
    const budgetTxt = budget > 0
      ? `<div style="font-size:12px">${fmt(budget)}${overBudget?' <span class="budget-warn">⚠ lewat</span>':''}</div>
         <div class="bar"><div class="bar-fill ${overBudget?'over':''}" style="width:${Math.min(100,Math.round(used/budget*100))}%"></div></div>`
      : '<span style="color:#9CA3AF;font-size:12px">unlimited</span>';
    // Di mode shared: saldo ditampilkan dari pool bersama (tenants.coin_balance)
    // Di mode per_outlet: saldo outlet masing-masing
    const saldoCell = isShared
      ? `<span style="color:#9CA3AF;font-size:11px;font-style:italic">(shared)</span>`
      : fmt(o.coin_balance);
    html += `<tr>
      <td class="cell-name"><strong>${esc(o.nama_outlet)}</strong></td>
      <td class="num" data-label="Saldo">${saldoCell}</td>
      <td class="num" data-label="Terpakai">${fmt(used)}<div class="bar"><div class="bar-fill" style="width:${pct}%"></div></div></td>
      <td data-label="Budget/bln">${budgetTxt}</td>
      ${CAN_MANAGE?`<td class="cell-act" data-label="Atur budget"><button class="btn btn-light btn-sm" onclick="openBudget(${o.outlet_id}, ${JSON.stringify(o.nama_outlet)}, ${budget})">🎯 Budget</button></td>`:''}
    </tr>`;
  });
  html += '</tbody></table>';
  if (isShared) {
    html += `<div style="margin-top:8px;font-size:12px;color:#6B7280;text-align:right">
      Pool bersama: <strong style="color:#0F1C3A">${fmt(_tenantBalance)} coin</strong>
    </div>`;
  }
  box.innerHTML = html;
}

function renderFeatures(rows){
  const box = document.getElementById('featureBox');
  if (!rows.length){ box.innerHTML = '<div class="empty">Belum ada pemakaian.</div>'; return; }
  const total = rows.reduce((a,r)=>a+Number(r.used),0) || 1;
  box.innerHTML = rows.map(r => {
    const pct = Math.round(Number(r.used)/total*100);
    return `<div style="margin-bottom:9px">
      <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:2px">
        <span>${FEATURE_LABEL[r.feature]||esc(r.feature)} <span style="color:#9CA3AF;font-size:11px">(${r.cnt}×)</span></span>
        <span style="font-family:var(--mono);font-weight:700">${fmt(r.used)} <span style="color:#9CA3AF;font-weight:400">${pct}%</span></span>
      </div>
      <div class="bar"><div class="bar-fill" style="width:${pct}%"></div></div>
    </div>`;
  }).join('');
}

// Budget
function openBudget(oid, nama, cur){
  document.getElementById('bdOutlet').value = oid;
  document.getElementById('bdOutletName').textContent = '📍 ' + nama;
  document.getElementById('bdBudget').value = cur || 0;
  document.getElementById('budgetModal').classList.add('open');
}
async function saveBudget(){
  const body = {outlet_id: document.getElementById('bdOutlet').value, budget: document.getElementById('bdBudget').value};
  const r = await apiFetch('/hq/billing.php?action=set_budget', body);
  const d = await r.json();
  if (d.error){ alert('⚠️ '+d.error); return; }
  closeModal('budgetModal'); loadMonitor();
}

// Transfer
function openTransfer(){ document.getElementById('transferModal').classList.add('open'); }
async function doTransfer(){
  const body = {
    from: document.getElementById('trFrom').value, to: document.getElementById('trTo').value,
    amount: document.getElementById('trAmount').value, desc: document.getElementById('trDesc').value,
  };
  const r = await apiFetch('/hq/billing.php?action=transfer', body);
  const d = await r.json();
  if (d.error){ alert('⚠️ '+d.error); return; }
  alert('✅ Transfer berhasil!');
  closeModal('transferModal'); location.reload();
}
function closeModal(id){ document.getElementById(id).classList.remove('open'); }

loadMonitor();

const COIN_CSRF = <?= json_encode($csrf) ?>;
const CUR_MODE = <?= json_encode($curMode) ?>;
const TENANT_POOL = <?= (int)$tinfo['coin_balance'] ?>;
const OUTLETS_JSON = <?= json_encode(array_map(fn($o)=>['nama'=>$o['nama_outlet'],'bal'=>(int)$o['coin_balance'],'main'=>(int)$o['is_main']], $outletsList)) ?>;

async function toggleCoinMode() {
  const to = CUR_MODE === 'shared' ? 'per_outlet' : 'shared';
  let msg;
  if (to === 'per_outlet') {
    const main = OUTLETS_JSON.find(o => o.main === 1) || OUTLETS_JSON[0];
    msg = `Ganti ke PER-OUTLET?\n\nSaldo tenant ${TENANT_POOL.toLocaleString('id-ID')} coin akan dipindah ke outlet UTAMA${main ? ' ('+main.nama+')' : ''}. Outlet lain mulai 0.`;
  } else {
    const sum = OUTLETS_JSON.reduce((a,o)=>a+o.bal,0);
    msg = `Ganti ke SHARED?\n\nTotal ${sum.toLocaleString('id-ID')} coin dari semua outlet akan digabung jadi saldo tenant.`;
  }
  if (!await lmConfirm(msg)) return;
  fetch('/hq/billing.php?action=set_coin_mode', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':COIN_CSRF},
    body: JSON.stringify({ mode: to })
  }).then(r=>r.json()).then(d=>{
    if (d.error) { alert(d.error); return; }
    alert('Mode coin diubah ke ' + d.mode + '. Saldo dipindah: ' + (d.moved||0).toLocaleString('id-ID') + ' coin.');
    location.reload();
  }).catch(()=>alert('Gagal menghubungi server'));
}

function saveHqOutlet() {
  const oid = document.getElementById('hqOutletSel').value;
  fetch('/hq/billing.php?action=set_hq_coin_outlet', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':COIN_CSRF},
    body: JSON.stringify({ outlet_id: parseInt(oid,10) })
  }).then(r=>r.json()).then(d=>{
    if (d.error) { alert(d.error); return; }
  }).catch(()=>alert('Gagal menyimpan'));
}
</script>

<?php require __DIR__ . '/_layout_close.php'; ?>

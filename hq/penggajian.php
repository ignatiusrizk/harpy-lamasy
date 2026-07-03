<?php
// ══════════════════════════════════════════════════════
// hq/penggajian.php — Penggajian Konsolidasi (#4)
//   Total beban gaji semua outlet + generate slip massal
// ══════════════════════════════════════════════════════

$activePage = 'hq-penggajian';
$pageTitle  = 'Penggajian Konsolidasi';

define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';

$db   = Database::get();
$tid  = (int)$hqTenant['id'];
$action = $_GET['action'] ?? '';
$canManage = !empty($hqIsOwner) || !empty($hqIsManager);

function gajiBulan(): string {
    return preg_match('/^\d{4}-\d{2}$/', $_GET['bulan'] ?? ($_POST['bulan'] ?? '')) ? ($_GET['bulan'] ?? $_POST['bulan']) : date('Y-m');
}

// ── API: konsolidasi per outlet ──────────────────────
if ($action === 'data') {
    header('Content-Type: application/json');
    $bulan = gajiBulan();
    try {
        $oStmt = $db->prepare("SELECT id, nama_outlet FROM outlets
                                WHERE tenant_id=? AND status IN ('trial','grace','active')
                                ORDER BY is_main DESC, nama_outlet");
        $oStmt->execute([$tid]);
        $rows = [];
        $totBeban=0; $totPending=0; $totDibayar=0; $totSlip=0; $totKar=0;
        foreach ($oStmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $oid = (int)$o['id'];
            // Slip gaji bulan ini
            $g = $db->prepare("SELECT COUNT(*) slip, COALESCE(SUM(total),0) beban,
                                      COALESCE(SUM(CASE WHEN status='dibayar' THEN total ELSE 0 END),0) dibayar,
                                      COALESCE(SUM(CASE WHEN status='pending'  THEN total ELSE 0 END),0) pending,
                                      SUM(status='dibayar') cnt_dibayar, SUM(status='pending') cnt_pending
                                 FROM hl_gaji WHERE tenant_id=? AND outlet_id=? AND bulan=?");
            $g->execute([$tid,$oid,$bulan]);
            $gr = $g->fetch(PDO::FETCH_ASSOC);
            // Karyawan aktif di outlet
            $k = $db->prepare("SELECT COUNT(DISTINCT karyawan_id) FROM hl_karyawan_outlet
                                WHERE tenant_id=? AND outlet_id=? AND is_active=1");
            $k->execute([$tid,$oid]);
            $kary = (int)$k->fetchColumn();

            $beban=(int)$gr['beban'];
            $rows[] = [
                'outlet_id'=>$oid, 'nama_outlet'=>$o['nama_outlet'],
                'karyawan'=>$kary, 'slip'=>(int)$gr['slip'],
                'beban'=>$beban, 'pending'=>(int)$gr['pending'], 'dibayar'=>(int)$gr['dibayar'],
                'cnt_pending'=>(int)$gr['cnt_pending'], 'cnt_dibayar'=>(int)$gr['cnt_dibayar'],
                'belum_generate'=>max(0, $kary - (int)$gr['slip']),
            ];
            $totBeban+=$beban; $totPending+=(int)$gr['pending']; $totDibayar+=(int)$gr['dibayar'];
            $totSlip+=(int)$gr['slip']; $totKar+=$kary;
        }
        echo json_encode(['ok'=>true, 'bulan'=>$bulan, 'rows'=>$rows,
            'total'=>['beban'=>$totBeban,'pending'=>$totPending,'dibayar'=>$totDibayar,'slip'=>$totSlip,'karyawan'=>$totKar]]);
    } catch (Throwable $e) { apiErr($e); }
    exit;
}

// ── API: generate slip massal (semua / 1 outlet) ─────
if ($action === 'generate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!$canManage) { echo json_encode(['error'=>'Akses ditolak']); exit; }
    verifyCsrf();
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $bulan = preg_match('/^\d{4}-\d{2}$/', $d['bulan'] ?? '') ? $d['bulan'] : date('Y-m');
    $targetOutlet = (int)($d['outlet_id'] ?? 0); // 0 = semua
    $u = currentUser();
    try {
        $oSql = "SELECT id FROM outlets WHERE tenant_id=? AND status IN ('trial','grace','active')" . ($targetOutlet>0?" AND id=?":"");
        $oStmt = $db->prepare($oSql);
        $oStmt->execute($targetOutlet>0 ? [$tid,$targetOutlet] : [$tid]);
        $outletIds = array_column($oStmt->fetchAll(PDO::FETCH_ASSOC), 'id');

        // Jumlah outlet aktif per karyawan → untuk split proporsional gaji
        $cntStmt = $db->prepare("SELECT karyawan_id, COUNT(DISTINCT outlet_id) c
                                   FROM hl_karyawan_outlet
                                  WHERE tenant_id=? AND is_active=1 GROUP BY karyawan_id");
        $cntStmt->execute([$tid]);
        $outletCount = [];
        foreach ($cntStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $outletCount[(int)$row['karyawan_id']] = max(1,(int)$row['c']);

        $ins = $db->prepare("INSERT IGNORE INTO hl_gaji (tenant_id,outlet_id,user_id,bulan,gaji_pokok,total,status,catatan,created_by,created_at)
                             VALUES (?,?,?,?,?,?,'pending',?,NOW())");
        $created = 0; $splitCount = 0;
        foreach ($outletIds as $oid) {
            $oid = (int)$oid;
            $users = $db->prepare("SELECT u.id, u.gaji_pokok FROM hl_users u
                                    JOIN hl_karyawan_outlet ko ON ko.karyawan_id=u.id AND ko.tenant_id=u.tenant_id
                                         AND ko.outlet_id=? AND ko.is_active=1
                                   WHERE u.tenant_id=? AND u.is_active=1");
            $users->execute([$oid,$tid]);
            foreach ($users->fetchAll(PDO::FETCH_ASSOC) as $usr) {
                $uid2 = (int)$usr['id'];
                $nOutlet = $outletCount[$uid2] ?? 1;
                $gpFull = (float)($usr['gaji_pokok'] ?? 0);
                $gp = $nOutlet > 1 ? round($gpFull / $nOutlet) : $gpFull;
                $note = $nOutlet > 1 ? "Gaji di-split $nOutlet outlet (porsi 1/$nOutlet dari Rp ".number_format($gpFull,0,',','.').")" : null;
                $ins->execute([$tid,$oid,$uid2,$bulan,$gp,$gp,$note, $u?(int)$u['id']:null]);
                if ($ins->rowCount() > 0) { $created++; if ($nOutlet>1) $splitCount++; }
            }
        }
        // Step 1: call BonusEvaluator if checkbox checked
        $evalBonus = !empty($d['eval_bonus']);
        if ($evalBonus) {
            require_once ROOT . '/core/BonusEvaluator.php';
            if ($targetOutlet > 0) {
                $gajis = $db->prepare("SELECT id FROM hl_gaji WHERE tenant_id=? AND bulan=? AND outlet_id=?");
                $gajis->execute([$tid, $bulan, $targetOutlet]);
            } else {
                $gajis = $db->prepare("SELECT id FROM hl_gaji WHERE tenant_id=? AND bulan=?");
                $gajis->execute([$tid, $bulan]);
            }
            foreach ($gajis->fetchAll(PDO::FETCH_COLUMN) as $gid) {
                BonusEvaluator::applyToGaji((int)$gid);
            }
        }
        try { logAudit('generate_gaji','penggajian',"Generate slip massal $bulan ($created baru, $splitCount split multi-outlet)".($evalBonus?' +eval_bonus':'')); } catch (Throwable) {}
        echo json_encode(['ok'=>true, 'created'=>$created, 'split'=>$splitCount]);
    } catch (Throwable $e) { apiErr($e); }
    exit;
}

// ── API: data_karyawan (gaji list per outlet) ─────────
if ($action === 'data_karyawan') {
    header('Content-Type: application/json');
    if (!$canManage) { echo json_encode(['error'=>'Akses ditolak']); exit; }
    $oid   = (int)($_GET['outlet_id'] ?? 0);
    $bulan = preg_match('/^\d{4}-\d{2}$/', $_GET['bulan'] ?? '') ? $_GET['bulan'] : date('Y-m');
    if ($oid <= 0) { echo json_encode(['error'=>'Input invalid']); exit; }
    try {
        $st = $db->prepare("SELECT g.id, g.user_id, u.name, g.gaji_pokok, g.bonus, g.potongan, g.total, g.status
                              FROM hl_gaji g JOIN hl_users u ON u.id=g.user_id AND u.tenant_id=g.tenant_id
                             WHERE g.tenant_id=? AND g.outlet_id=? AND g.bulan=?
                             ORDER BY u.name");
        $st->execute([$tid, $oid, $bulan]);
        echo json_encode(['rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Throwable $e) { apiErr($e); }
    exit;
}

// ── API: komponen_list ────────────────────────────────
if ($action === 'komponen_list') {
    header('Content-Type: application/json');
    if (!$canManage) { echo json_encode(['error'=>'Akses ditolak']); exit; }
    $gajiId = (int)($_GET['gaji_id'] ?? 0);
    if ($gajiId <= 0) { echo json_encode(['error'=>'Input invalid']); exit; }
    $own = $db->prepare("SELECT 1 FROM hl_gaji WHERE id=? AND tenant_id=?");
    $own->execute([$gajiId, $tid]);
    if (!$own->fetchColumn()) { echo json_encode(['error'=>'Forbidden']); exit; }
    $rows = $db->prepare("SELECT * FROM hl_gaji_komponen WHERE gaji_id=? ORDER BY jenis='pokok' DESC, amount DESC, id");
    $rows->execute([$gajiId]);
    echo json_encode(['rows' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ── API: komponen_add ─────────────────────────────────
if ($action === 'komponen_add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!$canManage) { echo json_encode(['error'=>'Akses ditolak']); exit; }
    verifyCsrf();
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $gajiId   = (int)($d['gaji_id'] ?? 0);
    $nama     = substr(trim($d['nama'] ?? ''), 0, 100);
    $amount   = (int)($d['amount'] ?? 0);
    $ket      = trim($d['keterangan'] ?? '');
    if ($gajiId <= 0 || !$nama || $amount === 0) { echo json_encode(['error'=>'Input invalid']); exit; }
    $own = $db->prepare("SELECT 1 FROM hl_gaji WHERE id=? AND tenant_id=?");
    $own->execute([$gajiId, $tid]);
    if (!$own->fetchColumn()) { echo json_encode(['error'=>'Forbidden']); exit; }
    try {
        $ins = $db->prepare("INSERT INTO hl_gaji_komponen (gaji_id, jenis, rule_id, nama, amount, keterangan) VALUES (?, 'manual', NULL, ?, ?, ?)");
        $ins->execute([$gajiId, $nama, $amount, $ket]);
        // Recompute totals from komponen
        $sumSt = $db->prepare("SELECT COALESCE(SUM(CASE WHEN jenis='pokok' THEN amount ELSE 0 END),0) sp,
                                      COALESCE(SUM(CASE WHEN amount>0 AND jenis!='pokok' THEN amount ELSE 0 END),0) sb,
                                      COALESCE(SUM(CASE WHEN amount<0 THEN ABS(amount) ELSE 0 END),0) sd
                                 FROM hl_gaji_komponen WHERE gaji_id=?");
        $sumSt->execute([$gajiId]);
        $s = $sumSt->fetch(PDO::FETCH_ASSOC);
        $db->prepare("UPDATE hl_gaji SET bonus=?, potongan=?, total=? WHERE id=?")
           ->execute([(int)$s['sb'], (int)$s['sd'], (int)$s['sp'] + (int)$s['sb'] - (int)$s['sd'], $gajiId]);
        try { logAudit('komponen_add','gaji',"gaji_id=$gajiId nama=$nama amount=$amount"); } catch (Throwable) {}
        echo json_encode(['ok'=>true]);
    } catch (Throwable $e) { apiErr($e); }
    exit;
}

// ── API: re_evaluate ──────────────────────────────────
if ($action === 're_evaluate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!$canManage) { echo json_encode(['error'=>'Akses ditolak']); exit; }
    verifyCsrf();
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $gajiId = (int)($d['gaji_id'] ?? 0);
    if ($gajiId <= 0) { echo json_encode(['error'=>'Input invalid']); exit; }
    $own = $db->prepare("SELECT 1 FROM hl_gaji WHERE id=? AND tenant_id=?");
    $own->execute([$gajiId, $tid]);
    if (!$own->fetchColumn()) { echo json_encode(['error'=>'Forbidden']); exit; }
    try {
        require_once ROOT . '/core/BonusEvaluator.php';
        BonusEvaluator::applyToGaji($gajiId);
        try { logAudit('re_evaluate','gaji',"gaji_id=$gajiId"); } catch (Throwable) {}
        echo json_encode(['ok'=>true]);
    } catch (Throwable $e) { apiErr($e); }
    exit;
}

// ── API: tandai semua dibayar (1 outlet / semua) ─────
if ($action === 'mark_paid' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!$canManage) { echo json_encode(['error'=>'Akses ditolak']); exit; }
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $bulan = preg_match('/^\d{4}-\d{2}$/', $d['bulan'] ?? '') ? $d['bulan'] : date('Y-m');
    $oid = (int)($d['outlet_id'] ?? 0);
    try {
        $sql = "UPDATE hl_gaji SET status='dibayar', dibayar_at=NOW()
                 WHERE tenant_id=? AND bulan=? AND status='pending'" . ($oid>0?" AND outlet_id=?":"");
        $stmt = $db->prepare($sql);
        $stmt->execute($oid>0 ? [$tid,$bulan,$oid] : [$tid,$bulan]);
        try { logAudit('mark_paid','penggajian',"Tandai gaji dibayar $bulan".($oid?" outlet#$oid":" semua")); } catch (Throwable) {}
        echo json_encode(['ok'=>true, 'affected'=>$stmt->rowCount()]);
    } catch (Throwable $e) { apiErr($e); }
    exit;
}

require __DIR__ . '/_layout_open.php';
?>
<style>
.pg-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:10px}
.pg-head h1{font-size:1.3rem;font-weight:800;color:#0F1C3A}
.metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:18px}
.metric{background:#fff;border:1px solid #EEF1F8;border-radius:12px;padding:16px;box-shadow:0 1px 6px rgba(0,0,0,.05);border-top:3px solid #35E8D5;text-align:center}
.metric.amber{border-top-color:#F59E0B}.metric.green{border-top-color:#10B981}.metric.blue{border-top-color:#3B82F6}
.metric-num{font-size:clamp(0.8rem,3.4vw,1.4rem);white-space:nowrap;letter-spacing:-0.02em;font-weight:800;color:#0F1C3A;font-family:var(--mono)}
.metric-label{font-size:12px;color:#6B7280;font-weight:600;margin-top:2px}
.panel{background:#fff;border:1px solid #EEF1F8;border-radius:14px;padding:20px;box-shadow:0 1px 6px rgba(0,0,0,.05)}
.panel-title{font-size:14px;font-weight:700;color:#0F1C3A;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px}
.filter{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:16px}
.filter input{padding:7px 11px;border:1px solid #E5E9F2;border-radius:8px;font-family:inherit;font-size:13px}
.btn{padding:8px 14px;border-radius:8px;font-weight:700;font-size:13px;border:none;cursor:pointer;font-family:inherit;background:#0F1C3A;color:#fff}
.btn:hover{background:#1a2d52}.btn-sm{padding:6px 11px;font-size:12px}
.btn-light{background:#fff;color:#0F1C3A;border:1px solid #E5E9F2}
.btn-green{background:#10B981}.btn-green:hover{background:#059669}
.tbl{width:100%;border-collapse:collapse;font-size:13px}
.tbl th{background:#F7F8FC;padding:9px 11px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;color:#6B7280}
.tbl td{padding:10px 11px;border-top:1px solid #F0F1F4}
.tbl .num{font-family:var(--mono);font-weight:700;text-align:right}
.pill{font-size:11px;font-weight:700;padding:2px 8px;border-radius:100px}
.pill-warn{background:#FEF3C7;color:#92400E}.pill-ok{background:#D1FAE5;color:#065F46}.pill-gray{background:#F3F4F6;color:#6B7280}
.empty{text-align:center;padding:30px;color:#9CA3AF;font-size:13px}

@media(max-width:900px){
  h1,.pg-head h1{font-size:1.15rem}
  .pg-head .btn{width:100%;justify-content:center}
  /* Kartu ringkasan 2×2, tak meluber ke kanan */
  .metrics{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
  .metric{padding:12px}
  .metric-num{font-size:clamp(0.75rem,3.4vw,1.2rem)}
  .filter{gap:8px}
  .filter select,.filter input{flex:1 1 auto;min-width:0}
  /* Tabel beban gaji & tabel detail modal bisa digeser mendatar */
  #tblBox{overflow-x:auto;-webkit-overflow-scrolling:touch}
  #tblBox table.tbl{min-width:520px}
  .tbl th,.tbl td{white-space:nowrap}
  .hq-modal-body{overflow-x:auto}
  .hq-modal-body table{min-width:480px}
}
</style>

<div class="pg-head">
  <h1>💵 Penggajian Konsolidasi</h1>
  <?php if ($canManage): ?>
  <div style="display:flex;gap:8px">
    <button class="btn" onclick="generateAll()">⚙️ Generate Slip Semua Outlet</button>
  </div>
  <?php endif; ?>
</div>
<p style="font-size:13px;color:#6B7280;margin-bottom:16px">Total beban gaji semua outlet + generate slip massal per bulan.</p>

<div class="filter">
  <label style="font-size:12px;color:#6B7280;font-weight:600">Bulan:</label>
  <input type="month" id="fBulan" value="<?= date('Y-m') ?>">
  <button class="btn btn-light btn-sm" onclick="loadData()">↻ Terapkan</button>
  <?php if ($canManage): ?>
  <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;margin-left:8px">
    <input type="checkbox" id="eval_bonus" checked> Evaluate auto-bonus
  </label>
  <?php endif; ?>
</div>

<div class="metrics">
  <div class="metric"><div class="metric-num" id="mBeban">-</div><div class="metric-label">Total Beban Gaji</div></div>
  <div class="metric amber"><div class="metric-num" id="mPending">-</div><div class="metric-label">Belum Dibayar</div></div>
  <div class="metric green"><div class="metric-num" id="mDibayar">-</div><div class="metric-label">Sudah Dibayar</div></div>
  <div class="metric blue"><div class="metric-num" id="mSlip">-</div><div class="metric-label">Total Slip / Karyawan</div></div>
</div>

<div class="panel">
  <div class="panel-title">📍 Beban Gaji per Outlet</div>
  <div id="tblBox"><div class="empty">⏳ Memuat…</div></div>
</div>

<script>
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const fmtRp = n => 'Rp ' + Number(n||0).toLocaleString('id-ID');
const CAN_MANAGE = <?= $canManage ? 'true':'false' ?>;
const CSRF = '<?= htmlspecialchars(getCsrfToken()) ?>';

async function loadData(){
  const bulan = document.getElementById('fBulan').value;
  const box = document.getElementById('tblBox');
  box.innerHTML = '<div class="empty">⏳ Memuat…</div>';
  try {
    const r = await fetch(`/hq/penggajian.php?action=data&bulan=${bulan}`);
    const d = await r.json();
    if (d.error){ box.innerHTML = `<div class="empty">⚠️ ${esc(d.error)}</div>`; return; }
    document.getElementById('mBeban').textContent = fmtRp(d.total.beban);
    document.getElementById('mPending').textContent = fmtRp(d.total.pending);
    document.getElementById('mDibayar').textContent = fmtRp(d.total.dibayar);
    // Owner / user tanpa assignment outlet tidak masuk karyawan count, jadi
    // slip bisa > karyawan. Display label adaptif supaya tidak misleading "1 / 0".
    document.getElementById('mSlip').textContent = d.total.karyawan > 0
      ? `${d.total.slip} / ${d.total.karyawan}`
      : `${d.total.slip} slip`;
    if (!d.rows.length){ box.innerHTML = '<div class="empty">Belum ada outlet.</div>'; return; }
    let html = '<table class="tbl"><thead><tr><th>Outlet</th><th style="text-align:center">Karyawan</th><th style="text-align:center">Slip</th><th style="text-align:right">Beban</th><th style="text-align:center">Status</th><th></th></tr></thead><tbody>';
    d.rows.forEach(o => {
      let status;
      if (o.slip === 0) status = '<span class="pill pill-gray">belum generate</span>';
      else if (o.cnt_pending === 0) status = '<span class="pill pill-ok">lunas</span>';
      else status = `<span class="pill pill-warn">${o.cnt_pending} pending</span>`;
      const belumGen = o.belum_generate > 0 ? ` <span style="font-size:10px;color:#EF4444">(${o.belum_generate} blm)</span>` : '';
      html += `<tr>
        <td><strong>${esc(o.nama_outlet)}</strong></td>
        <td style="text-align:center">${o.karyawan}</td>
        <td style="text-align:center">${o.slip}${belumGen}</td>
        <td class="num">${fmtRp(o.beban)}</td>
        <td style="text-align:center">${status}</td>
        <td style="white-space:nowrap">
          ${o.slip>0?`<button class="btn btn-light btn-sm" onclick="toggleKaryawan(this,${o.outlet_id})">▾ Detail</button>`:''}
          ${CAN_MANAGE && o.belum_generate>0?`<button class="btn btn-light btn-sm" onclick="genOutlet(${o.outlet_id})">⚙️ Generate</button>`:''}
          ${CAN_MANAGE && o.cnt_pending>0?`<button class="btn btn-green btn-sm" onclick="markPaid(${o.outlet_id}, ${JSON.stringify(o.nama_outlet)})">✓ Bayar</button>`:''}
        </td>
      </tr>
      <tr id="sub-${o.outlet_id}" style="display:none"><td colspan="6" style="padding:0 12px 12px 24px;background:#F9FAFB"><div class="sub-loading">⏳</div></td></tr>`;
    });
    html += '</tbody></table>';
    box.innerHTML = html;
  } catch(e){ box.innerHTML = `<div class="empty">⚠️ ${esc(e.message)}</div>`; }
}

async function generateAll(){
  const bulan = document.getElementById('fBulan').value;
  if (!await lmConfirm(`Generate slip gaji untuk SEMUA outlet bulan ${bulan}?\nKaryawan yang sudah punya slip tidak akan dobel.`)) return;
  await doGenerate(bulan, 0);
}
async function genOutlet(oid){
  const bulan = document.getElementById('fBulan').value;
  await doGenerate(bulan, oid);
}
async function doGenerate(bulan, oid){
  const evalBonus = document.getElementById('eval_bonus')?.checked ?? true;
  try {
    const r = await fetch('/hq/penggajian.php?action=generate', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, body:JSON.stringify({bulan, outlet_id:oid, eval_bonus: evalBonus})});
    const d = await r.json();
    if (d.error){ alert('⚠️ '+d.error); return; }
    let msg = `✅ ${d.created} slip gaji baru dibuat.`;
    if (d.split > 0) msg += `\n${d.split} karyawan multi-outlet → gaji di-split proporsional.`;
    alert(msg);
    loadData();
  } catch(e){ alert('Gagal: '+e.message); }
}
async function markPaid(oid, nama){
  const bulan = document.getElementById('fBulan').value;
  if (!await lmConfirm(`Tandai semua slip pending di "${nama}" bulan ${bulan} sebagai DIBAYAR?`)) return;
  try {
    const r = await fetch('/hq/penggajian.php?action=mark_paid', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, body:JSON.stringify({bulan, outlet_id:oid})});
    const d = await r.json();
    if (d.error){ alert('⚠️ '+d.error); return; }
    alert(`✅ ${d.affected} slip ditandai dibayar.`);
    loadData();
  } catch(e){ alert('Gagal: '+e.message); }
}

async function toggleKaryawan(btn, oid) {
  const row = document.getElementById('sub-' + oid);
  if (row.style.display !== 'none') { row.style.display = 'none'; btn.textContent = '▾ Detail'; return; }
  btn.textContent = '▴ Detail';
  row.style.display = '';
  const bulan = document.getElementById('fBulan').value;
  const cell = row.querySelector('td');
  cell.innerHTML = '<div style="font-size:12px;color:#6B7280">⏳ Memuat…</div>';
  try {
    const r = await fetch(`/hq/penggajian.php?action=data_karyawan&outlet_id=${oid}&bulan=${bulan}`);
    const d = await r.json();
    if (d.error) { cell.innerHTML = `<span style="color:#EF4444">${esc(d.error)}</span>`; return; }
    if (!d.rows.length) { cell.innerHTML = '<span style="font-size:12px;color:#9CA3AF">Belum ada slip.</span>'; return; }
    let h = '<table style="width:100%;font-size:12px;border-collapse:collapse"><thead><tr style="background:#EEF1F8"><th align="left" style="padding:6px 8px">Karyawan</th><th align="right" style="padding:6px 8px">Pokok</th><th align="right" style="padding:6px 8px">Bonus</th><th align="right" style="padding:6px 8px">Potongan</th><th align="right" style="padding:6px 8px">Total</th><th align="center" style="padding:6px 8px">Status</th>';
    if (CAN_MANAGE) h += '<th style="padding:6px 8px"></th>';
    h += '</tr></thead><tbody>';
    d.rows.forEach(g => {
      h += `<tr style="border-top:1px solid #E5E9F2">
        <td style="padding:6px 8px">${esc(g.name)}</td>
        <td style="padding:6px 8px;text-align:right;font-family:var(--mono)">${fmtRp(g.gaji_pokok)}</td>
        <td style="padding:6px 8px;text-align:right;font-family:var(--mono);color:#065F46">${fmtRp(g.bonus||0)}</td>
        <td style="padding:6px 8px;text-align:right;font-family:var(--mono);color:#991B1B">${fmtRp(g.potongan||0)}</td>
        <td style="padding:6px 8px;text-align:right;font-family:var(--mono);font-weight:700">${fmtRp(g.total)}</td>
        <td style="padding:6px 8px;text-align:center"><span class="pill ${g.status==='dibayar'?'pill-ok':'pill-warn'}">${esc(g.status)}</span></td>
        ${CAN_MANAGE?`<td style="padding:6px 8px;white-space:nowrap">
          <button class="btn btn-light btn-sm" style="padding:3px 8px;font-size:11px" onclick="showKomponen(${g.id})">▾ Komponen</button>
          <button class="btn btn-light btn-sm" style="padding:3px 8px;font-size:11px" onclick="reEvaluate(${g.id},${oid})">🔄 Re-eval</button>
          <button class="btn btn-light btn-sm" style="padding:3px 8px;font-size:11px" onclick="openAddKomponen(${g.id},${oid})">+ Manual</button>
        </td>`:''}
      </tr>`;
    });
    h += '</tbody></table>';
    cell.innerHTML = h;
  } catch(e) { cell.innerHTML = `<span style="color:#EF4444">${esc(e.message)}</span>`; }
}

async function showKomponen(gajiId) {
  const r = await fetch('/hq/penggajian.php?action=komponen_list&gaji_id=' + gajiId);
  const d = await r.json();
  if (d.error) { alert(d.error); return; }
  const modal = document.getElementById('modalKomponen') || createKomponenModal();
  const html = d.rows.map(k => `<tr>
    <td style="padding:6px 8px">${esc(k.jenis)}</td>
    <td style="padding:6px 8px">${esc(k.nama)}</td>
    <td style="padding:6px 8px;text-align:right;font-family:var(--mono);font-weight:600;color:${Number(k.amount)>=0?'#065F46':'#991B1B'}">${fmtRp(k.amount)}</td>
    <td style="padding:6px 8px;font-size:11px;color:#64748B">${esc(k.keterangan||'')}</td>
  </tr>`).join('') || '<tr><td colspan="4" style="padding:12px;text-align:center;color:#9CA3AF">Belum ada komponen.</td></tr>';
  modal.querySelector('#komponenTbody').innerHTML = html;
  modal.classList.add('open');
}

async function reEvaluate(gajiId, oid) {
  if (!await lmConfirm('Re-evaluate rules untuk gaji ini? Komponen manual tetap dipertahankan.')) return;
  try {
    const r = await fetch('/hq/penggajian.php?action=re_evaluate', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, body:JSON.stringify({gaji_id: gajiId})});
    const d = await r.json();
    if (d.error) { alert('⚠️ ' + d.error); return; }
    alert('✅ Re-evaluated');
    loadData();
  } catch(e) { alert('Gagal: ' + e.message); }
}

async function openAddKomponen(gajiId, oid) {
  const nama = await lmPrompt('Nama komponen (e.g. THR, Bonus Project, Potongan Pinjaman):');
  if (!nama) return;
  const amountStr = await lmPrompt('Amount (positif=bonus, negatif=potongan, e.g. 500000 atau -200000):');
  if (amountStr === null) return;
  const amount = parseInt(amountStr);
  if (isNaN(amount) || amount === 0) { alert('Amount harus angka non-zero'); return; }
  const ket = await lmPrompt('Keterangan (opsional):') || '';
  try {
    const r = await fetch('/hq/penggajian.php?action=komponen_add', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, body:JSON.stringify({gaji_id: gajiId, nama, amount, keterangan: ket})});
    const d = await r.json();
    if (d.error) { alert('⚠️ ' + d.error); return; }
    alert('✅ Komponen ditambah');
    loadData();
  } catch(e) { alert('Gagal: ' + e.message); }
}

function createKomponenModal() {
  const div = document.createElement('div');
  div.id = 'modalKomponen';
  div.className = 'hq-modal-overlay';
  div.innerHTML = `<div class="hq-modal" style="max-width:640px">
    <div class="hq-modal-header"><span>Breakdown Komponen Gaji</span>
      <button onclick="document.getElementById('modalKomponen').classList.remove('open')">✕</button></div>
    <div class="hq-modal-body">
      <table style="width:100%;font-size:13px;border-collapse:collapse">
        <thead><tr style="background:#F9FAFB"><th align="left" style="padding:8px">Jenis</th><th align="left" style="padding:8px">Nama</th><th align="right" style="padding:8px">Amount</th><th align="left" style="padding:8px">Ket</th></tr></thead>
        <tbody id="komponenTbody"></tbody>
      </table>
    </div>
  </div>`;
  document.body.appendChild(div);
  return div;
}

loadData();
</script>

<?php require __DIR__ . '/_layout_close.php'; ?>

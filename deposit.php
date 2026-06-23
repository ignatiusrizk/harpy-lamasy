<?php
// ══════════════════════════════════════════════════════
// deposit.php — Customer Deposit/Wallet Management
//
// Owner/kasir kelola:
// - Topup saldo pelanggan
// - Lihat history & balance per customer
// - Atur bonus tier (Topup 100k+ → +10%)
// - Total liability (saldo deposit semua customer)
// ══════════════════════════════════════════════════════
$activePage = 'deposit';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/DepositManager.php';
require_once __DIR__ . '/components.php';
$user = currentUser();
requirePermission('pelanggan.view');

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $tid = TenantResolver::id();
    $oid = TenantResolver::outletId();
    $db  = Database::get();

    // ── Stats ──
    if ($action === 'stats') {
        try {
            $st = $db->prepare(
                "SELECT
                   (SELECT COALESCE(SUM(saldo_deposit),0) FROM hl_pelanggan WHERE tenant_id=?) AS liability,
                   (SELECT COUNT(*) FROM hl_pelanggan WHERE tenant_id=? AND saldo_deposit > 0) AS active_customers,
                   (SELECT COALESCE(SUM(jumlah),0) FROM hl_deposit_topup WHERE tenant_id=? AND DATE(created_at)=CURDATE()) AS topup_today,
                   (SELECT COALESCE(SUM(jumlah),0) FROM hl_deposit_usage WHERE tenant_id=? AND DATE(created_at)=CURDATE()) AS usage_today"
            );
            $st->execute([$tid, $tid, $tid, $tid]);
            echo json_encode($st->fetch(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable) {
            echo json_encode(['liability'=>0,'active_customers'=>0,'topup_today'=>0,'usage_today'=>0,'error'=>'Tabel belum ada. Run deposit_wallet_migration.sql']);
        }
        exit;
    }

    // ── List pelanggan dgn saldo > 0 ──
    if ($action === 'customers') {
        $q = '%' . substr(trim($_GET['q'] ?? ''), 0, 50) . '%';
        $minSaldo = isset($_GET['saldo_only']) && $_GET['saldo_only'] === '1';
        try {
            $sql = "SELECT id, nama, telepon, saldo_deposit, total_order
                      FROM hl_pelanggan
                     WHERE tenant_id=? AND (nama LIKE ? OR telepon LIKE ?)";
            if ($minSaldo) $sql .= " AND saldo_deposit > 0";
            $sql .= " ORDER BY saldo_deposit DESC, nama ASC LIMIT 100";
            $st = $db->prepare($sql);
            $st->execute([$tid, $q, $q]);
            echo json_encode(['rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) {
            ErrorLogger::logException('db_error', $e, $tid);
            echo json_encode(['rows' => []]);
        }
        exit;
    }

    // ── History per pelanggan ──
    if ($action === 'history') {
        $pid = (int)($_GET['pelanggan_id'] ?? 0);
        if (!$pid) { echo json_encode(['rows'=>[]]); exit; }
        $rows = DepositManager::history($tid, $pid, 50);
        echo json_encode(['rows' => $rows, 'balance' => DepositManager::balance($tid, $pid)]);
        exit;
    }

    // ── Topup ──
    if ($action === 'topup' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d   = json_decode(file_get_contents('php://input'), true);
        $pid = (int)($d['pelanggan_id'] ?? 0);
        $jml = max(0, (float)($d['jumlah'] ?? 0));
        $met = $d['metode_bayar'] ?? 'cash';
        $cat = (string)($d['catatan'] ?? '');
        if (!$pid || $jml <= 0) { echo json_encode(['error'=>'Pelanggan & jumlah wajib']); exit; }
        // Preview bonus
        $bonusInfo = DepositManager::calcBonus($tid, $oid, $jml);
        [$id, $err] = DepositManager::topup($tid, $oid, $pid, $jml, $met, $cat, (int)$user['id']);
        if ($err) { echo json_encode(['error'=>$err]); exit; }
        echo json_encode([
            'success'=>true,
            'id'=>$id,
            'bonus'=>$bonusInfo['bonus'],
            'total_kredit'=>$jml + $bonusInfo['bonus'],
            'new_balance'=>DepositManager::balance($tid, $pid),
        ]);
        exit;
    }

    // ── Preview bonus saat user ketik jumlah topup ──
    if ($action === 'preview_bonus') {
        $jml = max(0, (float)($_GET['jumlah'] ?? 0));
        $info = DepositManager::calcBonus($tid, $oid, $jml);
        echo json_encode([
            'bonus' => $info['bonus'],
            'tier'  => $info['tier'] ? [
                'label' => $info['tier']['label'] ?: 'Bonus',
                'min'   => $info['tier']['min_topup'],
            ] : null,
            'total' => $jml + $info['bonus'],
        ]);
        exit;
    }

    // ── Refund Request (submit, owner approve di approval-inbox) ──
    if ($action === 'request_refund' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $pid    = (int)($d['pelanggan_id'] ?? 0);
        $jml    = (float)($d['jumlah'] ?? 0);
        $alasan = (string)($d['alasan'] ?? '');
        $metode = (string)($d['metode'] ?? 'cash');
        [$id, $err] = DepositManager::requestRefund($tid, $oid, $pid, $jml, $alasan, (int)$user['id'], $metode);
        echo json_encode($err ? ['error'=>$err] : ['success'=>true, 'id'=>$id]);
        exit;
    }

    // ── Process expired (manual trigger atau cron) ──
    if ($action === 'process_expired' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        if (!hasPermission('owner')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        $total = DepositManager::processExpired($tid);
        echo json_encode(['success'=>true, 'expired_amount'=>$total]);
        exit;
    }

    // ── Bonus Tier CRUD ──
    if ($action === 'tier_list') {
        try {
            $st = $db->prepare(
                "SELECT t.*, o.nama_outlet
                   FROM hl_deposit_bonus_tier t
              LEFT JOIN outlets o ON o.id = t.outlet_id
                  WHERE t.tenant_id = ?
                  ORDER BY t.is_active DESC, t.min_topup ASC"
            );
            $st->execute([$tid]);
            echo json_encode(['rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable) { echo json_encode(['rows'=>[]]); }
        exit;
    }

    if ($action === 'tier_save' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $min   = max(0, (float)($d['min_topup'] ?? 0));
        $tipe  = in_array($d['bonus_tipe'] ?? '', ['persen','nominal'], true) ? $d['bonus_tipe'] : 'persen';
        $nilai = max(0, (float)($d['bonus_nilai'] ?? 0));
        $label = substr(trim((string)($d['label'] ?? '')), 0, 80);
        $oid_t = !empty($d['outlet_id']) ? (int)$d['outlet_id'] : null;
        $aktif = (int)($d['is_active'] ?? 1);
        if ($min <= 0 || $nilai <= 0) { echo json_encode(['error'=>'Min topup & nilai bonus wajib > 0']); exit; }
        // Verifikasi outlet
        if ($oid_t !== null) {
            $own = TenantQuery::rawOne("SELECT id FROM outlets WHERE id=? AND tenant_id=?", [$oid_t, $tid]);
            if (!$own) { echo json_encode(['error'=>'Outlet tidak valid']); exit; }
        }
        try {
            if (!empty($d['id'])) {
                $db->prepare("UPDATE hl_deposit_bonus_tier SET min_topup=?, bonus_tipe=?, bonus_nilai=?, label=?, outlet_id=?, is_active=? WHERE id=? AND tenant_id=?")
                   ->execute([$min, $tipe, $nilai, $label, $oid_t, $aktif, (int)$d['id'], $tid]);
            } else {
                $db->prepare("INSERT INTO hl_deposit_bonus_tier (tenant_id, outlet_id, min_topup, bonus_tipe, bonus_nilai, label, is_active) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$tid, $oid_t, $min, $tipe, $nilai, $label, $aktif]);
            }
            echo json_encode(['success'=>true]);
        } catch (Throwable $e) {
            echo json_encode(['error'=>'Gagal: '.$e->getMessage()]);
        }
        exit;
    }

    if ($action === 'tier_delete' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $db->prepare("DELETE FROM hl_deposit_bonus_tier WHERE id=? AND tenant_id=?")
           ->execute([(int)$d['id'], $tid]);
        echo json_encode(['success'=>true]);
        exit;
    }

    exit;
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Deposit Wallet'); ?>
</head>
<body>
<?php renderTopbar('deposit'); ?>

<div class="hl-main">
  <!-- Stats -->
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px" id="statsCards">
    <div class="hl-stat-card teal"><div class="hl-stat-num" id="sLiability">-</div><div class="hl-stat-label">💰 Liability Total</div></div>
    <div class="hl-stat-card purple"><div class="hl-stat-num" id="sActive">-</div><div class="hl-stat-label">👥 Customer Aktif</div></div>
    <div class="hl-stat-card green"><div class="hl-stat-num" id="sTopupToday">-</div><div class="hl-stat-label">⬆️ Topup Hari Ini</div></div>
    <div class="hl-stat-card navy"><div class="hl-stat-num" id="sUsageToday">-</div><div class="hl-stat-label">⬇️ Pemakaian Hari Ini</div></div>
  </div>

  <!-- Action bar -->
  <div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap">
    <input type="text" id="searchCust" placeholder="🔍 Cari nama/telepon pelanggan..."
           style="flex:1;min-width:200px;padding:8px 14px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px"
           oninput="searchCustomer(this.value)"/>
    <label style="display:flex;align-items:center;gap:6px;font-size:13px;color:#4B5563">
      <input type="checkbox" id="filterSaldo" onchange="loadCustomers()"/> Punya saldo saja
    </label>
    <button class="hl-btn hl-btn-outline" onclick="openBonusTierModal()">⭐ Atur Bonus Tier</button>
  </div>

  <!-- Customer list -->
  <div id="custList" style="min-height:200px">⏳ Memuat...</div>
</div>

<!-- ════ Modal Topup ════ -->
<div class="hl-modal-overlay" id="modalTopup">
  <div class="hl-modal hl-modal-sm">
    <div class="hl-modal-header">
      <span class="hl-modal-title">⬆️ Topup Saldo — <span id="topupCustNama"></span></span>
      <button class="hl-modal-close" onclick="closeTopupModal()">✕</button>
    </div>
    <div class="hl-modal-body">
      <input type="hidden" id="tu_pelanggan_id"/>
      <div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:#166534">
        Saldo current: <strong id="tu_currentBalance">Rp 0</strong>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Jumlah Topup (Rp) <span class="req">*</span></label>
        <input type="number" id="tu_jumlah" class="hl-input" min="1000" step="10000" placeholder="100000" oninput="previewBonus()"/>
        <div style="display:flex;gap:6px;margin-top:6px;flex-wrap:wrap">
          <button type="button" class="hl-btn hl-btn-outline hl-btn-sm" onclick="setJml(50000)">50k</button>
          <button type="button" class="hl-btn hl-btn-outline hl-btn-sm" onclick="setJml(100000)">100k</button>
          <button type="button" class="hl-btn hl-btn-outline hl-btn-sm" onclick="setJml(250000)">250k</button>
          <button type="button" class="hl-btn hl-btn-outline hl-btn-sm" onclick="setJml(500000)">500k</button>
          <button type="button" class="hl-btn hl-btn-outline hl-btn-sm" onclick="setJml(1000000)">1jt</button>
        </div>
      </div>
      <!-- Bonus preview -->
      <div id="bonusBox" style="display:none;background:#FEF3C7;border:1px solid #FDE68A;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:#92400E"></div>
      <div class="hl-form-group">
        <label class="hl-label">Metode Bayar</label>
        <select id="tu_metode" class="hl-input">
          <option value="cash">💵 Cash</option>
          <option value="transfer">🏦 Transfer</option>
          <option value="qris">📱 QRIS</option>
        </select>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Catatan (opsional)</label>
        <textarea id="tu_catatan" class="hl-input" rows="2" placeholder="Mis. Topup promo grand opening"></textarea>
      </div>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeTopupModal()">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="doTopup()">✅ Topup</button>
    </div>
  </div>
</div>

<!-- ════ Modal Refund ════ -->
<div class="hl-modal-overlay" id="modalRefund">
  <div class="hl-modal hl-modal-sm">
    <div class="hl-modal-header">
      <span class="hl-modal-title">↩️ Refund Saldo — <span id="refundCustNama"></span></span>
      <button class="hl-modal-close" onclick="closeRefundModal()">✕</button>
    </div>
    <div class="hl-modal-body">
      <input type="hidden" id="rf_pelanggan_id"/>
      <div style="background:#FEF3C7;border:1px solid #FDE68A;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12.5px;color:#92400E;line-height:1.5">
        ⚠️ Refund butuh <strong>approval owner</strong> di Approval Inbox. Setelah disetujui, saldo dipotong & cash dikembalikan ke customer.
      </div>
      <div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:#166534">
        Saldo customer: <strong id="rf_currentBalance">Rp 0</strong>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Jumlah Refund (Rp) <span class="req">*</span></label>
        <input type="number" id="rf_jumlah" class="hl-input" min="1000" step="1000" placeholder="50000"/>
        <div style="font-size:11px;color:#6B7280;margin-top:4px">Max: saldo customer</div>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Metode Refund</label>
        <select id="rf_metode" class="hl-input">
          <option value="cash">💵 Cash</option>
          <option value="transfer">🏦 Transfer Bank</option>
          <option value="qris">📱 QRIS</option>
        </select>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Alasan Refund <span class="req">*</span></label>
        <textarea id="rf_alasan" class="hl-input" rows="3" placeholder="Mis. customer pindah kota, komplain layanan, dll" maxlength="1000"></textarea>
      </div>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeRefundModal()">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="submitRefund()">📤 Submit Refund</button>
    </div>
  </div>
</div>

<!-- ════ Modal History ════ -->
<div class="hl-modal-overlay" id="modalHistory">
  <div class="hl-modal" style="max-width:760px">
    <div class="hl-modal-header">
      <span class="hl-modal-title">📜 History Saldo — <span id="histCustNama"></span></span>
      <button class="hl-modal-close" onclick="closeHistoryModal()">✕</button>
    </div>
    <div class="hl-modal-body">
      <div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:14px;color:#166534">
        Saldo Sekarang: <strong id="hist_balance">Rp 0</strong>
      </div>
      <div id="histList" style="max-height:400px;overflow-y:auto">⏳ Memuat...</div>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeHistoryModal()">Tutup</button>
    </div>
  </div>
</div>

<!-- ════ Modal Bonus Tier ════ -->
<div class="hl-modal-overlay" id="modalBonusTier">
  <div class="hl-modal" style="max-width:680px">
    <div class="hl-modal-header">
      <span class="hl-modal-title">⭐ Atur Bonus Tier Topup</span>
      <button class="hl-modal-close" onclick="closeBonusTierModal()">✕</button>
    </div>
    <div class="hl-modal-body">
      <div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12.5px;color:#1E40AF;line-height:1.5">
        💡 Bonus otomatis di-apply saat customer topup. Tier dengan min_topup tertinggi yg ≤ jumlah topup yang dipilih.
        <br>Contoh: tier "100k+ bonus 5%" + "500k+ bonus 10%" → topup 200k dapat 5%, topup 600k dapat 10%.
      </div>
      <div id="tierListUI" style="margin-bottom:16px">⏳ Memuat...</div>

      <div style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:10px;padding:14px">
        <div style="font-weight:600;font-size:13px;color:#374151;margin-bottom:10px" id="bonusTierFormTitle">➕ Tambah Tier Baru</div>
        <input type="hidden" id="bt_id"/>
        <div class="hl-form-row">
          <div class="hl-form-group">
            <label class="hl-label">Min Topup (Rp) <span class="req">*</span></label>
            <input type="number" id="bt_min" class="hl-input" placeholder="100000" min="1000" step="10000"/>
          </div>
          <div class="hl-form-group">
            <label class="hl-label">Label (opsional)</label>
            <input type="text" id="bt_label" class="hl-input" placeholder="Bonus 10% Topup 100k+" maxlength="80"/>
          </div>
        </div>
        <div class="hl-form-row">
          <div class="hl-form-group">
            <label class="hl-label">Tipe Bonus</label>
            <select id="bt_tipe" class="hl-input" onchange="updateBtUnit()">
              <option value="persen">Persen (%)</option>
              <option value="nominal">Nominal (Rp)</option>
            </select>
          </div>
          <div class="hl-form-group">
            <label class="hl-label">Nilai <span id="btUnit" style="color:var(--gray)">(%)</span></label>
            <input type="number" id="bt_nilai" class="hl-input" placeholder="10" min="0" step="0.5"/>
          </div>
        </div>
        <div class="hl-form-row">
          <div class="hl-form-group">
            <label class="hl-label">Berlaku di Outlet</label>
            <select id="bt_outlet" class="hl-input">
              <option value="">🌍 Semua outlet</option>
            </select>
          </div>
          <div class="hl-form-group">
            <label class="hl-label">Status</label>
            <select id="bt_active" class="hl-input">
              <option value="1">✅ Aktif</option>
              <option value="0">⏸️ Nonaktif</option>
            </select>
          </div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end">
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="resetBonusTierForm()">↺ Reset</button>
          <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="saveBonusTier()">💾 Simpan</button>
        </div>
      </div>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeBonusTierModal()">Tutup</button>
    </div>
  </div>
</div>

<?php renderToast(); ?>

<script>
const fmt = n => 'Rp ' + Math.round(Number(n||0)).toLocaleString('id-ID');
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function fmtDT(d){if(!d)return '-';const dt=new Date(d);return dt.toLocaleString('id-ID',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});}

async function loadStats() {
  const r = await fetch('?action=stats');
  const d = await r.json();
  document.getElementById('sLiability').textContent = fmt(d.liability);
  document.getElementById('sActive').textContent = (d.active_customers||0) + ' org';
  document.getElementById('sTopupToday').textContent = fmt(d.topup_today);
  document.getElementById('sUsageToday').textContent = fmt(d.usage_today);
}

let searchTimer = null;
function searchCustomer(q) {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => loadCustomers(q), 300);
}

async function loadCustomers(q='') {
  if (q === '') q = document.getElementById('searchCust').value;
  const list = document.getElementById('custList');
  list.innerHTML = '<div style="padding:30px;text-align:center;color:var(--gray)">⏳ Memuat...</div>';
  const filterSaldo = document.getElementById('filterSaldo').checked ? '1' : '0';
  const r = await fetch(`?action=customers&q=${encodeURIComponent(q)}&saldo_only=${filterSaldo}`);
  const d = await r.json();
  const rows = d.rows || [];
  if (!rows.length) {
    list.innerHTML = '<div style="padding:60px;text-align:center;color:var(--gray)">Tidak ada pelanggan</div>';
    return;
  }
  list.innerHTML = `<table style="width:100%;background:#fff;border-collapse:collapse;font-size:13px;border-radius:10px;overflow:hidden">
    <thead><tr style="background:#F3F4F6;text-align:left">
      <th style="padding:10px 12px">Pelanggan</th>
      <th style="padding:10px 12px">Telepon</th>
      <th style="padding:10px 12px;text-align:right">Saldo</th>
      <th style="padding:10px 12px;text-align:right">Total Order</th>
      <th style="padding:10px 12px;text-align:right">Aksi</th>
    </tr></thead>
    <tbody>${rows.map(r => `
      <tr style="border-top:1px solid #F3F4F6">
        <td style="padding:10px 12px"><strong>${esc(r.nama)}</strong></td>
        <td style="padding:10px 12px;color:#6B7280">${esc(r.telepon||'-')}</td>
        <td style="padding:10px 12px;text-align:right;font-family:var(--mono,monospace);font-weight:700;color:${r.saldo_deposit>0?'#0F7B6C':'#9CA3AF'}">${fmt(r.saldo_deposit)}</td>
        <td style="padding:10px 12px;text-align:right;color:#6B7280">${r.total_order||0}</td>
        <td style="padding:10px 12px;text-align:right;white-space:nowrap">
          <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="openTopup(${r.id}, ${JSON.stringify(r.nama).replace(/"/g,'&quot;')}, ${r.saldo_deposit})">⬆️ Topup</button>
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="openHistory(${r.id}, ${JSON.stringify(r.nama).replace(/"/g,'&quot;')})">📜</button>
          ${r.saldo_deposit > 0 ? `<button class="hl-btn hl-btn-sm" style="background:#FEE2E2;color:#991B1B;border:1px solid #FCA5A5" onclick="openRefund(${r.id}, ${JSON.stringify(r.nama).replace(/"/g,'&quot;')}, ${r.saldo_deposit})">↩️ Refund</button>` : ''}
        </td>
      </tr>
    `).join('')}</tbody></table>`;
}

// ── Topup ──
let currentPid = null;
function openTopup(id, nama, balance) {
  currentPid = id;
  document.getElementById('topupCustNama').textContent = nama;
  document.getElementById('tu_pelanggan_id').value = id;
  document.getElementById('tu_currentBalance').textContent = fmt(balance);
  document.getElementById('tu_jumlah').value = '';
  document.getElementById('tu_catatan').value = '';
  document.getElementById('tu_metode').value = 'cash';
  document.getElementById('bonusBox').style.display = 'none';
  document.getElementById('modalTopup').classList.add('open');
}
function closeTopupModal() { document.getElementById('modalTopup').classList.remove('open'); }
function setJml(v) { document.getElementById('tu_jumlah').value = v; previewBonus(); }

let bonusTimer;
async function previewBonus() {
  clearTimeout(bonusTimer);
  bonusTimer = setTimeout(async () => {
    const jml = parseFloat(document.getElementById('tu_jumlah').value)||0;
    if (jml <= 0) { document.getElementById('bonusBox').style.display = 'none'; return; }
    const r = await fetch('?action=preview_bonus&jumlah=' + jml);
    const d = await r.json();
    const box = document.getElementById('bonusBox');
    if (d.bonus > 0) {
      box.style.display = 'block';
      box.innerHTML = `🎉 <strong>Bonus ${fmt(d.bonus)}</strong>${d.tier?.label?' — '+esc(d.tier.label):''}<br>Total kredit ke saldo: <strong>${fmt(d.total)}</strong>`;
    } else {
      box.style.display = 'block';
      box.style.background = '#F3F4F6';
      box.style.borderColor = '#E5E7EB';
      box.style.color = '#6B7280';
      box.innerHTML = `Tidak ada bonus utk jumlah ini. <a href="#" onclick="closeTopupModal();openBonusTierModal();return false">Atur tier bonus</a> kalau mau.`;
    }
  }, 250);
}

async function doTopup() {
  const payload = {
    pelanggan_id: currentPid,
    jumlah: parseFloat(document.getElementById('tu_jumlah').value)||0,
    metode_bayar: document.getElementById('tu_metode').value,
    catatan: document.getElementById('tu_catatan').value,
  };
  if (payload.jumlah <= 0) { showToast('Jumlah wajib > 0','error'); return; }
  const r = await fetch('?action=topup', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify(payload)
  });
  const d = await r.json();
  if (d.error) { showToast(d.error,'error'); return; }
  showToast(`✅ Topup berhasil. ${d.bonus>0?'Bonus '+fmt(d.bonus)+'. ':''}Saldo baru: ${fmt(d.new_balance)}`,'success');
  closeTopupModal();
  loadStats();
  loadCustomers();
}

// ── Refund ──
let refundPid = null;
function openRefund(id, nama, balance) {
  refundPid = id;
  document.getElementById('refundCustNama').textContent = nama;
  document.getElementById('rf_pelanggan_id').value = id;
  document.getElementById('rf_currentBalance').textContent = fmt(balance);
  document.getElementById('rf_jumlah').value = '';
  document.getElementById('rf_jumlah').max = balance;
  document.getElementById('rf_alasan').value = '';
  document.getElementById('rf_metode').value = 'cash';
  document.getElementById('modalRefund').classList.add('open');
}
function closeRefundModal() { document.getElementById('modalRefund').classList.remove('open'); }

async function submitRefund() {
  const payload = {
    pelanggan_id: refundPid,
    jumlah: parseFloat(document.getElementById('rf_jumlah').value)||0,
    alasan: document.getElementById('rf_alasan').value.trim(),
    metode: document.getElementById('rf_metode').value,
  };
  if (payload.jumlah <= 0) { showToast('Jumlah wajib > 0','error'); return; }
  if (payload.alasan.length < 5) { showToast('Alasan minimal 5 karakter','error'); return; }
  const r = await fetch('?action=request_refund', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify(payload)
  });
  const d = await r.json();
  if (d.error) { showToast(d.error,'error'); return; }
  showToast('✅ Refund request terkirim. Menunggu approval owner di Approval Inbox.','success');
  closeRefundModal();
}

// ── History ──
async function openHistory(id, nama) {
  document.getElementById('histCustNama').textContent = nama;
  document.getElementById('histList').innerHTML = '<div style="padding:30px;text-align:center;color:var(--gray)">⏳ Memuat...</div>';
  document.getElementById('modalHistory').classList.add('open');
  const r = await fetch('?action=history&pelanggan_id=' + id);
  const d = await r.json();
  document.getElementById('hist_balance').textContent = fmt(d.balance);
  const rows = d.rows || [];
  if (!rows.length) {
    document.getElementById('histList').innerHTML = '<div style="padding:30px;text-align:center;color:var(--gray)">Belum ada transaksi saldo</div>';
    return;
  }
  document.getElementById('histList').innerHTML = `<table style="width:100%;border-collapse:collapse;font-size:13px">
    <thead><tr style="background:#F3F4F6;text-align:left">
      <th style="padding:8px 10px">Tanggal</th>
      <th style="padding:8px 10px">Jenis</th>
      <th style="padding:8px 10px;text-align:right">Delta</th>
      <th style="padding:8px 10px;text-align:right">Saldo</th>
      <th style="padding:8px 10px">Catatan</th>
    </tr></thead>
    <tbody>${rows.map(r => `
      <tr style="border-top:1px solid #F3F4F6">
        <td style="padding:8px 10px;font-size:11.5px;color:#6B7280">${fmtDT(r.created_at)}</td>
        <td style="padding:8px 10px">${r.jenis==='topup'?'⬆️ Topup'+(r.bonus>0?` <span style="font-size:10px;color:#92400E">+bonus ${fmt(r.bonus)}</span>`:''):'⬇️ Pakai'+(r.transaksi_id?` <span style="font-size:10px;color:#0F7B6C">#trx ${r.transaksi_id}</span>`:'')}</td>
        <td style="padding:8px 10px;text-align:right;font-family:var(--mono,monospace);font-weight:700;color:${r.delta>0?'#059669':'#DC2626'}">${r.delta>0?'+':''}${fmt(r.delta)}</td>
        <td style="padding:8px 10px;text-align:right;font-family:var(--mono,monospace);color:#6B7280">${fmt(r.saldo_sesudah)}</td>
        <td style="padding:8px 10px;font-size:11.5px;color:#6B7280">${esc(r.catatan||'-')}</td>
      </tr>
    `).join('')}</tbody></table>`;
}
function closeHistoryModal() { document.getElementById('modalHistory').classList.remove('open'); }

// ── Bonus Tier CRUD ──
let allOutletsForBt = [];
async function openBonusTierModal() {
  document.getElementById('modalBonusTier').classList.add('open');
  await loadOutletsForBt();
  resetBonusTierForm();
  loadBonusTiers();
}
function closeBonusTierModal() { document.getElementById('modalBonusTier').classList.remove('open'); }

async function loadOutletsForBt() {
  if (allOutletsForBt.length > 0) return;
  try {
    const r = await fetch('/promo.php?action=outlets');
    const d = await r.json();
    allOutletsForBt = d.outlets || [];
    const sel = document.getElementById('bt_outlet');
    sel.innerHTML = '<option value="">🌍 Semua outlet</option>' +
      allOutletsForBt.map(o => `<option value="${o.id}">🏪 ${esc(o.nama_outlet)}</option>`).join('');
  } catch(e) {}
}

function updateBtUnit() {
  document.getElementById('btUnit').textContent = document.getElementById('bt_tipe').value === 'nominal' ? '(Rp)' : '(%)';
}

function resetBonusTierForm() {
  document.getElementById('bt_id').value = '';
  document.getElementById('bt_min').value = '';
  document.getElementById('bt_label').value = '';
  document.getElementById('bt_tipe').value = 'persen';
  document.getElementById('bt_nilai').value = '';
  document.getElementById('bt_outlet').value = '';
  document.getElementById('bt_active').value = 1;
  document.getElementById('bonusTierFormTitle').textContent = '➕ Tambah Tier Baru';
  updateBtUnit();
}

async function loadBonusTiers() {
  const r = await fetch('?action=tier_list');
  const d = await r.json();
  const list = document.getElementById('tierListUI');
  const rows = d.rows || [];
  if (!rows.length) {
    list.innerHTML = '<div style="padding:14px;text-align:center;color:var(--gray);font-size:12.5px;background:#F9FAFB;border:1px dashed #E5E7EB;border-radius:8px">Belum ada bonus tier. Tambah pakai form di bawah.</div>';
    return;
  }
  list.innerHTML = `<table style="width:100%;font-size:12.5px;border-collapse:collapse">
    <thead><tr style="background:#F3F4F6;text-align:left">
      <th style="padding:7px 10px">Min Topup</th><th style="padding:7px 10px">Bonus</th><th style="padding:7px 10px">Outlet</th><th style="padding:7px 10px">Label</th><th style="padding:7px 10px">Status</th><th style="padding:7px 10px;text-align:right"></th>
    </tr></thead>
    <tbody>${rows.map(r => `
      <tr style="border-top:1px solid #F3F4F6">
        <td style="padding:7px 10px;font-family:var(--mono,monospace)">${fmt(r.min_topup)}</td>
        <td style="padding:7px 10px;color:#92400E">${r.bonus_tipe==='persen'?'+'+parseFloat(r.bonus_nilai)+'%':'+'+fmt(r.bonus_nilai)}</td>
        <td style="padding:7px 10px;font-size:11px;${r.outlet_id?'color:#0F7B6C':'color:#6B7280'}">${r.outlet_id?'🏪 '+esc(r.nama_outlet||'#'+r.outlet_id):'🌍 Semua'}</td>
        <td style="padding:7px 10px;font-size:11px;color:#6B7280">${esc(r.label||'-')}</td>
        <td style="padding:7px 10px">${r.is_active==1?'<span style="color:#059669">●</span>':'<span style="color:#9CA3AF">○</span>'}</td>
        <td style="padding:7px 10px;text-align:right;white-space:nowrap">
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick='editBonusTier(${JSON.stringify(r)})'>✏️</button>
          <button class="hl-btn hl-btn-danger hl-btn-sm" onclick="deleteBonusTier(${r.id})">🗑️</button>
        </td>
      </tr>`).join('')}</tbody></table>`;
}

function editBonusTier(t) {
  document.getElementById('bt_id').value = t.id;
  document.getElementById('bt_min').value = t.min_topup;
  document.getElementById('bt_label').value = t.label || '';
  document.getElementById('bt_tipe').value = t.bonus_tipe;
  document.getElementById('bt_nilai').value = t.bonus_nilai;
  document.getElementById('bt_outlet').value = t.outlet_id || '';
  document.getElementById('bt_active').value = t.is_active;
  document.getElementById('bonusTierFormTitle').textContent = '✏️ Edit Tier';
  updateBtUnit();
}

async function saveBonusTier() {
  const payload = {
    id: document.getElementById('bt_id').value || null,
    min_topup: parseFloat(document.getElementById('bt_min').value)||0,
    label: document.getElementById('bt_label').value,
    bonus_tipe: document.getElementById('bt_tipe').value,
    bonus_nilai: parseFloat(document.getElementById('bt_nilai').value)||0,
    outlet_id: document.getElementById('bt_outlet').value || null,
    is_active: parseInt(document.getElementById('bt_active').value),
  };
  const r = await fetch('?action=tier_save', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify(payload)
  });
  const d = await r.json();
  if (d.error) { showToast(d.error,'error'); return; }
  showToast('Bonus tier disimpan','success');
  resetBonusTierForm();
  loadBonusTiers();
}

async function deleteBonusTier(id) {
  if (!confirm('Hapus tier ini?')) return;
  const r = await fetch('?action=tier_delete', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({id})
  });
  await r.json();
  showToast('Dihapus','success');
  loadBonusTiers();
}

document.addEventListener('DOMContentLoaded', () => { loadStats(); loadCustomers(); });
</script>

</body>
</html>

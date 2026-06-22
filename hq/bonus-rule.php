<?php
// hq/bonus-rule.php — HQ kelola bonus & penalti rule
$activePage = 'hq-bonus-rule';
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';

if (!$hqIsOwner) { http_response_code(403); die('Akses hanya untuk owner.'); }
requirePermission('bonus_rule.manage');

$tid = (int)TenantResolver::id();
$db  = Database::get();

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');

    if ($action === 'list') {
        $rows = $db->prepare("SELECT * FROM hl_bonus_rule WHERE tenant_id=? ORDER BY is_active DESC, tipe, id");
        $rows->execute([$tid]);
        $list = $rows->fetchAll(PDO::FETCH_ASSOC);
        foreach ($list as &$r) {
            $st = $db->prepare("SELECT outlet_id FROM hl_bonus_rule_outlet WHERE rule_id=? ORDER BY outlet_id");
            $st->execute([(int)$r['id']]);
            $r['outlets'] = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        }
        echo json_encode(['rows' => $list]);
        exit;
    }

    if ($action === 'outlets_list') {
        $rows = $db->prepare("SELECT id, nama_outlet FROM outlets WHERE tenant_id=? AND status='active' ORDER BY is_main DESC, nama_outlet");
        $rows->execute([$tid]);
        echo json_encode(['rows' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'save' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $id          = (int)($d['id'] ?? 0);
        $nama        = substr(trim($d['nama'] ?? ''), 0, 100);
        $tipe        = in_array(($d['tipe'] ?? ''), ['hadir_penuh','tepat_waktu','lembur','zero_izin','penalti_telat'], true) ? $d['tipe'] : '';
        $threshold   = (int)($d['threshold'] ?? 0);
        $amount      = (int)($d['amount'] ?? 0);
        $perUnit     = !empty($d['amount_per_unit']) ? 1 : 0;
        $isActive    = !empty($d['is_active']) ? 1 : 0;
        $scope       = in_array(($d['scope'] ?? ''), ['all','selected'], true) ? $d['scope'] : 'all';
        $outletIds   = array_map('intval', (array)($d['outlet_ids'] ?? []));

        if (!$nama || !$tipe) { echo json_encode(['error'=>'Nama + tipe wajib']); exit; }

        try {
            $db->beginTransaction();
            if ($id > 0) {
                $st = $db->prepare("UPDATE hl_bonus_rule SET nama=?, tipe=?, threshold=?, amount=?, amount_per_unit=?, is_active=? WHERE id=? AND tenant_id=?");
                $st->execute([$nama, $tipe, $threshold, $amount, $perUnit, $isActive, $id, $tid]);
            } else {
                $st = $db->prepare("INSERT INTO hl_bonus_rule (tenant_id, nama, tipe, threshold, amount, amount_per_unit, is_active) VALUES (?,?,?,?,?,?,?)");
                $st->execute([$tid, $nama, $tipe, $threshold, $amount, $perUnit, $isActive]);
                $id = (int)$db->lastInsertId();
            }

            // Rewrite junction (validate outlet_id belongs to tenant)
            $del = $db->prepare("DELETE FROM hl_bonus_rule_outlet WHERE rule_id=?");
            $del->execute([$id]);
            if ($scope === 'selected' && !empty($outletIds)) {
                $ins = $db->prepare("INSERT IGNORE INTO hl_bonus_rule_outlet (rule_id, outlet_id) SELECT ?, id FROM outlets WHERE id=? AND tenant_id=?");
                foreach ($outletIds as $oId) { if ($oId > 0) $ins->execute([$id, $oId, $tid]); }
            }

            logAudit('bonus_rule_save', 'bonus_rule', "id=$id tipe=$tipe scope=$scope outlets=" . implode(',', $outletIds));
            $db->commit();
            echo json_encode(['ok' => true, 'id' => $id]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[hq/bonus-rule save] ' . $e->getMessage());
            echo json_encode(['error' => 'Gagal simpan']);
        }
        exit;
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($d['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['error'=>'Input invalid']); exit; }
        $st = $db->prepare("UPDATE hl_bonus_rule SET is_active=0 WHERE id=? AND tenant_id=?");
        $st->execute([$id, $tid]);
        if (!$st->rowCount()) { echo json_encode(['error'=>'Tidak ditemukan']); exit; }
        logAudit('bonus_rule_delete', 'bonus_rule', "id=$id");
        echo json_encode(['ok'=>true]);
        exit;
    }

    echo json_encode(['error'=>'Unknown action']);
    exit;
}

$pageTitle = '🎯 Bonus Rule';
require ROOT . '/hq/_layout_open.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:18px">
  <h1 style="margin:0">🎯 Bonus & Penalti Rule</h1>
  <button class="hl-btn hl-btn-primary" onclick="openEdit()">+ Tambah Rule</button>
</div>

<div id="ruleList" style="min-height:200px">⏳ Memuat...</div>

<!-- Modal edit -->
<div class="hl-modal-overlay" id="modalEdit">
  <div class="hl-modal" style="max-width:560px">
    <div class="hl-modal-header"><span>Tambah/Edit Rule</span></div>
    <div class="hl-modal-body">
      <input type="hidden" id="e_id" value="0">
      <label>Nama Rule</label>
      <input type="text" id="e_nama" class="hl-input" maxlength="100" placeholder="Bonus Hadir Penuh">
      <label style="margin-top:10px">Tipe</label>
      <select id="e_tipe" class="hl-input" onchange="updateThresholdLabel()">
        <option value="hadir_penuh">Hadir Penuh</option>
        <option value="tepat_waktu">Tepat Waktu (min N hari)</option>
        <option value="lembur">Lembur (menit excess)</option>
        <option value="zero_izin">Zero Izin/Sakit</option>
        <option value="penalti_telat">Penalti Telat</option>
      </select>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px">
        <div>
          <label id="e_threshold_label">Threshold</label>
          <input type="number" id="e_threshold" class="hl-input" min="0" value="0">
        </div>
        <div>
          <label>Amount (Rp)</label>
          <input type="number" id="e_amount" class="hl-input" value="0">
        </div>
      </div>
      <label style="margin-top:10px;display:flex;align-items:center;gap:8px;cursor:pointer">
        <input type="checkbox" id="e_per_unit"> Per unit excess (untuk lembur/penalti)
      </label>

      <div style="margin-top:14px;padding:12px;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px">
        <label style="font-weight:700;margin-bottom:8px;display:block">Berlaku di Outlet</label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:8px">
          <input type="radio" name="e_scope" value="all" checked onchange="toggleOutletPicker()"> Semua outlet
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="radio" name="e_scope" value="selected" onchange="toggleOutletPicker()"> Outlet tertentu
        </label>
        <div id="outletPicker" style="display:none;margin-top:10px;padding:10px;background:#fff;border:1px solid #E5E7EB;border-radius:6px;max-height:200px;overflow-y:auto"></div>
      </div>

      <label style="margin-top:10px;display:flex;align-items:center;gap:8px;cursor:pointer">
        <input type="checkbox" id="e_active" checked> Aktif
      </label>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn" onclick="closeEdit()">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="saveRule()">💾 Simpan</button>
    </div>
  </div>
</div>

<script>
const CSRF = '<?= htmlspecialchars(getCsrfToken()) ?>';
let outletsCache = null;

const THRESHOLD_LABEL = {
  hadir_penuh: 'Threshold (n/a)',
  tepat_waktu: 'Min hari tepat waktu',
  lembur: 'Menit per hari (e.g. 480)',
  zero_izin: 'Threshold (n/a)',
  penalti_telat: 'Max telat diperbolehkan'
};

function updateThresholdLabel() {
  const tipe = document.getElementById('e_tipe').value;
  document.getElementById('e_threshold_label').textContent = THRESHOLD_LABEL[tipe] || 'Threshold';
}

async function loadList() {
  const r = await fetch('?action=list');
  const d = await r.json();
  const list = document.getElementById('ruleList');
  if (!d.rows.length) { list.innerHTML = '<div style="padding:40px;text-align:center;color:#94A3B8">Belum ada rule</div>'; return; }
  list.innerHTML = d.rows.map(r => {
    const outletsLabel = r.outlets.length === 0
      ? '<span style="background:#D1FAE5;color:#065F46;padding:2px 8px;border-radius:6px;font-size:11px">🌐 Semua outlet</span>'
      : '<span style="background:#DBEAFE;color:#1E40AF;padding:2px 8px;border-radius:6px;font-size:11px">🏪 ' + r.outlets.length + ' outlet</span>';
    const tipeLabel = {hadir_penuh:'Hadir Penuh', tepat_waktu:'Tepat Waktu', lembur:'Lembur', zero_izin:'Zero Izin', penalti_telat:'Penalti Telat'}[r.tipe] || r.tipe;
    const amountStr = r.amount_per_unit==1 ? `Rp ${r.amount}/unit` : `Rp ${Number(r.amount).toLocaleString('id-ID')}`;
    return `
      <div style="background:#fff;border-radius:10px;padding:14px 16px;margin-bottom:10px;display:flex;justify-content:space-between;gap:12px;border:1px solid #E5E7EB ${r.is_active==0?';opacity:.5':''}">
        <div style="flex:1;min-width:0">
          <div style="font-weight:700;font-size:15px">🎯 ${esc(r.nama)}</div>
          <div style="font-size:13px;color:#64748B;margin-top:3px">${esc(tipeLabel)} · threshold ${r.threshold} · ${amountStr}</div>
          <div style="margin-top:6px">${outletsLabel}${r.is_active==0 ? '<span style="background:#FEE;color:#991B1B;font-size:11px;padding:2px 8px;border-radius:6px;margin-left:6px">Non-aktif</span>' : ''}</div>
        </div>
        <div style="display:flex;gap:6px;align-items:center">
          <button class="hl-btn-sm" onclick='openEdit(${JSON.stringify(r)})'>✏️</button>
          <button class="hl-btn-sm" onclick="deleteRule(${r.id})" style="color:#EF4444">🗑</button>
        </div>
      </div>`;
  }).join('');
}

async function toggleOutletPicker() {
  const scope = document.querySelector('input[name=e_scope]:checked')?.value;
  const picker = document.getElementById('outletPicker');
  if (scope !== 'selected') { picker.style.display = 'none'; return; }
  picker.style.display = 'block';
  if (!outletsCache) {
    const r = await fetch('?action=outlets_list');
    const d = await r.json();
    outletsCache = d.rows || [];
  }
  picker.innerHTML = outletsCache.map(o => `
    <label style="display:flex;align-items:center;gap:8px;padding:4px 0;cursor:pointer">
      <input type="checkbox" name="e_outlet" value="${o.id}"> ${esc(o.nama_outlet)}
    </label>`).join('');
}

function openEdit(r) {
  document.getElementById('e_id').value        = r?.id || 0;
  document.getElementById('e_nama').value      = r?.nama || '';
  document.getElementById('e_tipe').value      = r?.tipe || 'hadir_penuh';
  document.getElementById('e_threshold').value = r?.threshold || 0;
  document.getElementById('e_amount').value    = r?.amount || 0;
  document.getElementById('e_per_unit').checked = r ? (r.amount_per_unit==1) : false;
  document.getElementById('e_active').checked  = r ? (r.is_active==1) : true;
  updateThresholdLabel();

  const scope = (!r || r.outlets.length === 0) ? 'all' : 'selected';
  document.querySelectorAll('input[name=e_scope]').forEach(el => el.checked = (el.value === scope));
  toggleOutletPicker().then(() => {
    if (r?.outlets?.length) {
      r.outlets.forEach(oId => {
        const cb = document.querySelector(`input[name=e_outlet][value="${oId}"]`);
        if (cb) cb.checked = true;
      });
    }
  });

  document.getElementById('modalEdit').classList.add('open');
}
function closeEdit() { document.getElementById('modalEdit').classList.remove('open'); }

async function saveRule() {
  const id = parseInt(document.getElementById('e_id').value);
  const scope = document.querySelector('input[name=e_scope]:checked')?.value || 'all';
  const outletIds = [...document.querySelectorAll('input[name=e_outlet]:checked')].map(el => parseInt(el.value));
  const payload = {
    id, scope, outlet_ids: outletIds,
    nama: document.getElementById('e_nama').value,
    tipe: document.getElementById('e_tipe').value,
    threshold: parseInt(document.getElementById('e_threshold').value) || 0,
    amount: parseInt(document.getElementById('e_amount').value) || 0,
    amount_per_unit: document.getElementById('e_per_unit').checked ? 1 : 0,
    is_active: document.getElementById('e_active').checked ? 1 : 0,
  };
  const r = await fetch('?action=save', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify(payload)});
  const d = await r.json();
  if (d.error) { alert(d.error); return; }
  closeEdit(); loadList();
}

async function deleteRule(id) {
  if (!confirm('Non-aktifkan rule ini?')) return;
  const r = await fetch('?action=delete', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify({id})});
  const d = await r.json();
  if (d.error) { alert(d.error); return; }
  loadList();
}

function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
loadList();
</script>

<?php require ROOT . '/hq/_layout_close.php'; ?>

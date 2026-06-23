<?php
// hq/loyalty.php — HQ kelola reward loyalty (multi-outlet)
$activePage = 'hq-loyalty';
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';
require_once ROOT . '/core/Loyalty.php';

// Owner-level only — defensive check setelah hq_guard (F1 RBAC v2)
if (!TenantResolver::isOwnerLevel()) {
    http_response_code(403);
    die('Akses hanya untuk owner.');
}

$tid = (int)TenantResolver::id();
$db  = Database::get();

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');

    if ($action === 'list') {
        $rows = $db->prepare("SELECT * FROM hl_poin_reward WHERE tenant_id=? ORDER BY is_active DESC, poin_dibutuhkan");
        $rows->execute([$tid]);
        $list = $rows->fetchAll(PDO::FETCH_ASSOC);
        foreach ($list as &$r) {
            $r['outlets'] = Loyalty::applicableOutlets((int)$r['id']);
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
        $nama        = substr(trim($d['nama_reward'] ?? ''), 0, 100);
        $deskripsi   = trim($d['deskripsi'] ?? '');
        $poin        = max(1, (int)($d['poin_dibutuhkan'] ?? 0));
        $tipe        = in_array(($d['tipe'] ?? ''), ['diskon_nominal','diskon_persen','gratis_layanan'], true) ? $d['tipe'] : 'diskon_nominal';
        $nilai       = max(0, (int)($d['nilai'] ?? 0));
        $minTransaksi = max(0, (int)($d['min_transaksi'] ?? 0));
        $maxRedeem   = max(0, (int)($d['max_redeem_per_bulan'] ?? 0));
        $isActive    = !empty($d['is_active']) ? 1 : 0;
        $scope       = in_array(($d['scope'] ?? ''), ['all', 'selected'], true) ? $d['scope'] : 'all';
        $outletIds   = array_map('intval', (array)($d['outlet_ids'] ?? []));

        if (!$nama) { echo json_encode(['error'=>'Nama wajib']); exit; }

        try {
            $db->beginTransaction();
            if ($id > 0) {
                $st = $db->prepare("UPDATE hl_poin_reward SET nama_reward=?, deskripsi=?, poin_dibutuhkan=?, tipe=?, nilai=?, min_transaksi=?, max_redeem_per_bulan=?, is_active=?, is_hq_managed=1 WHERE id=? AND tenant_id=?");
                $st->execute([$nama, $deskripsi, $poin, $tipe, $nilai, $minTransaksi, $maxRedeem, $isActive, $id, $tid]);
            } else {
                $st = $db->prepare("INSERT INTO hl_poin_reward (tenant_id, outlet_id, nama_reward, deskripsi, poin_dibutuhkan, tipe, nilai, min_transaksi, max_redeem_per_bulan, is_active, is_hq_managed) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                $st->execute([$tid, $nama, $deskripsi, $poin, $tipe, $nilai, $minTransaksi, $maxRedeem, $isActive]);
                $id = (int)$db->lastInsertId();
            }

            // Rewrite junction
            $del = $db->prepare("DELETE FROM hl_poin_reward_outlet WHERE reward_id=?");
            $del->execute([$id]);
            if ($scope === 'selected' && !empty($outletIds)) {
                $ins = $db->prepare(
                    "INSERT IGNORE INTO hl_poin_reward_outlet (reward_id, outlet_id)
                     SELECT ?, id FROM outlets WHERE id=? AND tenant_id=?"
                );
                foreach ($outletIds as $oId) {
                    if ($oId > 0) $ins->execute([$id, $oId, $tid]);
                }
            }
            // scope='all' → junction kept empty (0 rows = berlaku semua)

            logAudit('reward_save', 'loyalty', "id=$id scope=$scope outlets=" . implode(',', $outletIds));
            $db->commit();
            echo json_encode(['ok' => true, 'id' => $id]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[hq/loyalty save] ' . $e->getMessage());
            echo json_encode(['error' => 'Gagal simpan']);
        }
        exit;
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($d['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['error'=>'Input invalid']); exit; }
        $st = $db->prepare("UPDATE hl_poin_reward SET is_active=0 WHERE id=? AND tenant_id=?");
        $st->execute([$id, $tid]);
        if (!$st->rowCount()) { echo json_encode(['error'=>'Tidak ditemukan']); exit; }
        logAudit('reward_delete', 'loyalty', "id=$id");
        echo json_encode(['ok'=>true]);
        exit;
    }

    echo json_encode(['error'=>'Unknown action']);
    exit;
}

$pageTitle = '⭐ Sistem Poin (HQ)';
require ROOT . '/hq/_layout_open.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:18px">
  <h1 style="margin:0">⭐ Reward Loyalty (HQ)</h1>
  <button class="hl-btn hl-btn-primary" onclick="openEdit()">+ Tambah Reward</button>
</div>

<div id="rewardList" style="min-height:200px">⏳ Memuat...</div>

<!-- Modal edit -->
<div class="hl-modal-overlay" id="modalEdit">
  <div class="hl-modal" style="max-width:560px">
    <div class="hl-modal-header"><span>Tambah/Edit Reward</span></div>
    <div class="hl-modal-body">
      <input type="hidden" id="e_id" value="0">
      <label>Nama Reward</label>
      <input type="text" id="e_nama" class="hl-input" maxlength="100">
      <label>Deskripsi (opsional)</label>
      <textarea id="e_desk" class="hl-input" rows="2"></textarea>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px">
        <div>
          <label>Poin Dibutuhkan</label>
          <input type="number" id="e_poin" class="hl-input" min="1" value="50">
        </div>
        <div>
          <label>Tipe</label>
          <select id="e_tipe" class="hl-input">
            <option value="diskon_nominal">Diskon Rp</option>
            <option value="diskon_persen">Diskon %</option>
            <option value="gratis_layanan">Gratis Layanan</option>
          </select>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px">
        <div>
          <label>Nilai</label>
          <input type="number" id="e_nilai" class="hl-input" min="0">
        </div>
        <div>
          <label>Min Transaksi (Rp)</label>
          <input type="number" id="e_min" class="hl-input" min="0" value="0">
        </div>
      </div>
      <label style="margin-top:10px">Max Redeem per Bulan (0 = unlimited)</label>
      <input type="number" id="e_max" class="hl-input" min="0" value="0">

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
      <button class="hl-btn hl-btn-primary" onclick="saveReward()">💾 Simpan</button>
    </div>
  </div>
</div>

<script>
const CSRF = '<?= htmlspecialchars(getCsrfToken()) ?>';
let outletsCache = null;

async function loadList() {
  const r = await fetch('?action=list');
  const d = await r.json();
  const list = document.getElementById('rewardList');
  if (!d.rows.length) { list.innerHTML = '<div style="padding:60px 20px;text-align:center;background:#fff;border-radius:12px;border:1px dashed #CBD5E1"><div style="font-size:48px;margin-bottom:8px">🎁</div><div style="font-weight:700;font-size:16px;color:#0F1C3A;margin-bottom:4px">Belum ada reward</div><div style="color:#64748B;font-size:13px;margin-bottom:14px">Buat reward pertama untuk dipakai di semua outlet atau outlet tertentu</div><button class="hl-btn hl-btn-primary" onclick="openModal()">+ Tambah Reward</button></div>'; return; }
  list.innerHTML = d.rows.map(r => {
    const outletsLabel = r.outlets.length === 0
      ? '<span style="background:#D1FAE5;color:#065F46;padding:2px 8px;border-radius:6px;font-size:11px">🌐 Semua outlet</span>'
      : '<span style="background:#DBEAFE;color:#1E40AF;padding:2px 8px;border-radius:6px;font-size:11px">🏪 ' + r.outlets.length + ' outlet</span>';
    const tipeLabel = {diskon_nominal:'Diskon Rp', diskon_persen:'Diskon %', gratis_layanan:'Gratis Layanan'}[r.tipe] || r.tipe;
    return `
      <div style="background:#fff;border-radius:10px;padding:14px 16px;margin-bottom:10px;display:flex;justify-content:space-between;gap:12px;border:1px solid #E5E7EB ${r.is_active==0?';opacity:.5':''}">
        <div style="flex:1;min-width:0">
          <div style="font-weight:700;font-size:15px">⭐ ${esc(r.nama_reward)}</div>
          <div style="font-size:13px;color:#64748B;margin-top:3px">${r.poin_dibutuhkan} poin · ${esc(tipeLabel)} ${r.nilai}</div>
          <div style="margin-top:6px">${outletsLabel}${r.is_active==0 ? '<span style="background:#FEE;color:#991B1B;font-size:11px;padding:2px 8px;border-radius:6px;margin-left:6px">Non-aktif</span>' : ''}</div>
        </div>
        <div style="display:flex;gap:6px;align-items:center">
          <button class="hl-btn-sm" onclick='openEdit(${JSON.stringify(r)})'>✏️</button>
          <button class="hl-btn-sm" onclick="deleteReward(${r.id})" style="color:#EF4444">🗑</button>
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
    </label>
  `).join('');
}

function openEdit(r) {
  document.getElementById('e_id').value     = r?.id || 0;
  document.getElementById('e_nama').value   = r?.nama_reward || '';
  document.getElementById('e_desk').value   = r?.deskripsi || '';
  document.getElementById('e_poin').value   = r?.poin_dibutuhkan || 50;
  document.getElementById('e_tipe').value   = r?.tipe || 'diskon_nominal';
  document.getElementById('e_nilai').value  = r?.nilai || 0;
  document.getElementById('e_min').value    = r?.min_transaksi || 0;
  document.getElementById('e_max').value    = r?.max_redeem_per_bulan || 0;
  document.getElementById('e_active').checked = r ? (r.is_active==1) : true;

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

async function saveReward() {
  const id = parseInt(document.getElementById('e_id').value);
  const scope = document.querySelector('input[name=e_scope]:checked')?.value || 'all';
  const outletIds = [...document.querySelectorAll('input[name=e_outlet]:checked')].map(el => parseInt(el.value));
  const payload = {
    id, scope, outlet_ids: outletIds,
    nama_reward: document.getElementById('e_nama').value,
    deskripsi: document.getElementById('e_desk').value,
    poin_dibutuhkan: parseInt(document.getElementById('e_poin').value),
    tipe: document.getElementById('e_tipe').value,
    nilai: parseInt(document.getElementById('e_nilai').value),
    min_transaksi: parseInt(document.getElementById('e_min').value),
    max_redeem_per_bulan: parseInt(document.getElementById('e_max').value),
    is_active: document.getElementById('e_active').checked ? 1 : 0,
  };
  const r = await fetch('?action=save', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify(payload)});
  const d = await r.json();
  if (d.error) { alert(d.error); return; }
  closeEdit();
  loadList();
}

async function deleteReward(id) {
  if (!confirm('Non-aktifkan reward ini?')) return;
  await fetch('?action=delete', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify({id})});
  loadList();
}

function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
loadList();
</script>

<?php require ROOT . '/hq/_layout_close.php'; ?>

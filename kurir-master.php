<?php
// kurir-master.php — DEPRECATED 2026-06-24
// Kurir sekarang dikelola via /karyawan (assign role "Kurir" ke karyawan).
// Page ini di-redirect untuk legacy bookmark/link.
header('Location: /karyawan');
exit;

$activePage = 'kurir-master';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/TenantQuery.php';
require_once ROOT . '/core/TenantResolver.php';
require_once __DIR__ . '/components.php';

requirePermission('antar.manage');
$tid = TenantResolver::id();
$oid = TenantResolver::outletId();
$db  = Database::get();

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');

    if ($action === 'list') {
        $rows = TenantQuery::raw(
            "SELECT k.*, u.username AS akun_username
               FROM hl_kurir k
          LEFT JOIN hl_users u ON u.id = k.user_id AND u.tenant_id = k.tenant_id
              WHERE k.tenant_id=? AND k.outlet_id=?
              ORDER BY k.aktif DESC, k.nama ASC",
            [$tid, $oid]
        );
        echo json_encode(['rows' => $rows]);
        exit;
    }

    if ($action === 'save' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $id   = (int)($d['id'] ?? 0);
        $nama = substr(trim($d['nama'] ?? ''), 0, 100);
        $hp   = substr(trim($d['no_hp'] ?? ''), 0, 20);
        $kdr  = substr(trim($d['kendaraan'] ?? ''), 0, 50);

        if (!$nama) { echo json_encode(['error'=>'Nama wajib']); exit; }

        if ($id > 0) {
            $st = $db->prepare("UPDATE hl_kurir SET nama=?, no_hp=?, kendaraan=? WHERE id=? AND tenant_id=? AND outlet_id=?");
            $st->execute([$nama, $hp, $kdr, $id, $tid, $oid]);
            logAudit('update', 'kurir', "id=$id nama=$nama");
        } else {
            TenantQuery::insert('hl_kurir', ['nama'=>$nama, 'no_hp'=>$hp, 'kendaraan'=>$kdr, 'outlet_id'=>$oid]);
            logAudit('create', 'kurir', "nama=$nama");
        }
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'toggle_aktif' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($d['id'] ?? 0);
        $st = $db->prepare("UPDATE hl_kurir SET aktif=1-aktif WHERE id=? AND tenant_id=? AND outlet_id=?");
        $st->execute([$id, $tid, $oid]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'create_account' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($d['id'] ?? 0);

        $kurir = TenantQuery::rawOne("SELECT id, nama, no_hp, user_id FROM hl_kurir WHERE id=? AND tenant_id=? AND outlet_id=?", [$id, $tid, $oid]);
        if (!$kurir) { echo json_encode(['error'=>'Kurir tidak ditemukan']); exit; }
        if ($kurir['user_id']) { echo json_encode(['error'=>'Kurir sudah punya akun']); exit; }

        // Generate username dari nama (slugify) + 3 digit random
        $base = preg_replace('/[^a-z0-9]/', '', strtolower($kurir['nama']));
        $username = substr($base, 0, 8) . bin2hex(random_bytes(3));
        $password = bin2hex(random_bytes(4)); // 8 char
        $hash     = password_hash($password, PASSWORD_DEFAULT);

        // Insert ke hl_users — kolom: password (bukan password_hash), outlet_id ada
        try {
            $db->beginTransaction();
            $st = $db->prepare("INSERT INTO hl_users (tenant_id, outlet_id, username, password, nama, role, is_active, created_at) VALUES (?,?,?,?,?,?,1,NOW())");
            $st->execute([$tid, $oid, $username, $hash, $kurir['nama'], 'kurir']);
            $uid = (int)$db->lastInsertId();

            $upd = $db->prepare("UPDATE hl_kurir SET user_id=? WHERE id=? AND tenant_id=? AND outlet_id=?");
            $upd->execute([$uid, $id, $tid, $oid]);

            logAudit('create_account', 'kurir', "kurir_id=$id user_id=$uid username=$username");
            $db->commit();
            echo json_encode(['ok'=>true, 'username'=>$username, 'password'=>$password]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[kurir create_account] ' . $e->getMessage());
            echo json_encode(['error' => 'Gagal membuat akun. Coba lagi.']);
        }
        exit;
    }

    echo json_encode(['error'=>'Unknown action']);
    exit;
}

$pageTitle = '🛵 Kurir';
renderHead($pageTitle);
renderTopbar($activePage);
?>
<div class="hl-main">
  <h1 style="margin:0 0 14px">🛵 Master Kurir</h1>
  <button class="hl-btn hl-btn-primary" onclick="openEdit()">+ Tambah Kurir</button>
  <div id="kurirList" style="margin-top:18px;min-height:120px">⏳ Memuat...</div>
</div>

<!-- Modal edit -->
<div class="hl-modal-overlay" id="modalEdit">
  <div class="hl-modal">
    <div class="hl-modal-header"><span class="hl-modal-title">Tambah/Edit Kurir</span></div>
    <div class="hl-modal-body">
      <input type="hidden" id="ed_id" value="0">
      <label class="hl-label">Nama</label>
      <input type="text" id="ed_nama" class="hl-input" maxlength="100">
      <label class="hl-label">No HP</label>
      <input type="text" id="ed_hp" class="hl-input" maxlength="20">
      <label class="hl-label">Kendaraan</label>
      <input type="text" id="ed_kendaraan" class="hl-input" maxlength="50" placeholder="Motor Beat / Mobil Avanza">
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeEdit()">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="saveKurir()">💾 Simpan</button>
    </div>
  </div>
</div>

<?php renderToast(); ?>

<script>
const CSRF = document.querySelector('meta[name=csrf-token]')?.content || '';
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}

async function loadList() {
  const list = document.getElementById('kurirList');
  list.innerHTML = '⏳ Memuat...';
  const r = await fetch('?action=list');
  const d = await r.json();
  if (!d.rows.length) { list.innerHTML = '<div style="padding:30px;text-align:center;color:var(--gray)">Belum ada kurir</div>'; return; }
  list.innerHTML = d.rows.map(k => `
    <div class="hl-card" style="margin-bottom:10px;padding:14px 16px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap">
      <div style="flex:1;min-width:200px">
        <div style="font-weight:700;font-size:15px">🛵 ${esc(k.nama)}
          ${k.aktif==1 ? '' : '<span style="background:#FEE;color:#991B1B;font-size:10px;padding:2px 7px;border-radius:100px;margin-left:6px">NON-AKTIF</span>'}
        </div>
        <div style="color:var(--gray);font-size:13px;margin-top:3px">${esc(k.no_hp||'-')} · ${esc(k.kendaraan||'-')}</div>
        <div style="font-size:12px;margin-top:5px">
          ${k.akun_username
            ? `<span style="background:#D1FAE5;color:#065F46;padding:2px 8px;border-radius:6px">✓ Akun: ${esc(k.akun_username)}</span>`
            : `<button class="hl-btn hl-btn-outline hl-btn-sm" onclick="createAkun(${k.id})">🔑 Buat Akun</button>`}
        </div>
      </div>
      <div style="display:flex;gap:6px;align-items:center">
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick='openEdit(${JSON.stringify(k)})'>✏️</button>
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="toggleAktif(${k.id})">${k.aktif==1?'Nonaktifkan':'Aktifkan'}</button>
      </div>
    </div>
  `).join('');
}

function openEdit(k) {
  document.getElementById('ed_id').value        = k?.id || 0;
  document.getElementById('ed_nama').value      = k?.nama || '';
  document.getElementById('ed_hp').value        = k?.no_hp || '';
  document.getElementById('ed_kendaraan').value = k?.kendaraan || '';
  document.getElementById('modalEdit').classList.add('open');
}
function closeEdit() { document.getElementById('modalEdit').classList.remove('open'); }

async function saveKurir() {
  const payload = {
    id: parseInt(document.getElementById('ed_id').value),
    nama: document.getElementById('ed_nama').value,
    no_hp: document.getElementById('ed_hp').value,
    kendaraan: document.getElementById('ed_kendaraan').value,
  };
  const r = await fetch('?action=save', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify(payload)});
  const d = await r.json();
  if (d.error) { showToast(d.error,'error'); return; }
  showToast('✅ Tersimpan','success'); closeEdit(); loadList();
}

async function createAkun(id) {
  if (!confirm('Buat akun login untuk kurir ini?')) return;
  const r = await fetch('?action=create_account', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify({id})});
  const d = await r.json();
  if (d.error) { showToast(d.error,'error'); return; }
  alert(`✅ Akun dibuat:\n\nUsername: ${d.username}\nPassword: ${d.password}\n\n⚠ CATAT SEKARANG! Tidak ditampilkan lagi.`);
  loadList();
}

async function toggleAktif(id) {
  const r = await fetch('?action=toggle_aktif', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},body:JSON.stringify({id})});
  await r.json();
  loadList();
}

loadList();
</script>

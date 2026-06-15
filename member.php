<?php
// ══════════════════════════════════════════════════════
// member.php — Member Tier Management
// Inspired by Smartlink "Jenis Member" feature.
//
// Tenant kelola tier (Gold/Silver/dll), lihat daftar pelanggan member,
// expiry tracking.
// ══════════════════════════════════════════════════════
$activePage = 'member';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/MemberTier.php';
require_once __DIR__ . '/components.php';
$user = currentUser();
requirePermission('pelanggan.view');

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $tid = TenantResolver::id();
    $oid = TenantResolver::outletId();
    $db  = Database::get();

    // ── Tier CRUD ──
    if ($action === 'tier_list') {
        try {
            $st = $db->prepare("SELECT * FROM hl_member_tier WHERE tenant_id=? ORDER BY urutan, id");
            $st->execute([$tid]);
            $tiers = $st->fetchAll(PDO::FETCH_ASSOC);
            // Count enrolled per tier
            foreach ($tiers as &$t) {
                $c = $db->prepare("SELECT COUNT(*) FROM hl_pelanggan_member WHERE tenant_id=? AND member_tier_id=? AND status='aktif'");
                $c->execute([$tid, $t['id']]);
                $t['active_members'] = (int)$c->fetchColumn();
            }
            echo json_encode(['tiers' => $tiers]);
        } catch (Throwable $e) {
            echo json_encode(['error'=>'Tabel belum ada. Run member_tier_migration.sql', 'tiers'=>[]]);
        }
        exit;
    }

    if ($action === 'tier_save' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('pelanggan.view')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d     = json_decode(file_get_contents('php://input'), true);
        $nama  = substr(trim((string)($d['nama_tier'] ?? '')), 0, 50);
        $tipe  = in_array($d['masa_aktif_tipe'] ?? '', ['bulan','tahun','seumur'], true) ? $d['masa_aktif_tipe'] : 'bulan';
        $nilai = max(1, (int)($d['masa_aktif_nilai'] ?? 12));
        $biaya = max(0, (float)($d['biaya_pendaftaran'] ?? 0));
        $disk  = min(100, max(0, (float)($d['diskon_persen'] ?? 0)));
        $aktif = (int)($d['is_active'] ?? 1) ? 1 : 0;
        $urut  = (int)($d['urutan'] ?? 0);
        if ($nama === '') { echo json_encode(['error'=>'Nama tier wajib diisi']); exit; }

        try {
            if (!empty($d['id'])) {
                $db->prepare(
                    "UPDATE hl_member_tier
                        SET nama_tier=?, masa_aktif_tipe=?, masa_aktif_nilai=?,
                            biaya_pendaftaran=?, diskon_persen=?, is_active=?, urutan=?
                      WHERE id=? AND tenant_id=?"
                )->execute([$nama, $tipe, $nilai, $biaya, $disk, $aktif, $urut, (int)$d['id'], $tid]);
            } else {
                $db->prepare(
                    "INSERT INTO hl_member_tier
                       (tenant_id, outlet_id, nama_tier, masa_aktif_tipe, masa_aktif_nilai,
                        biaya_pendaftaran, diskon_persen, is_active, urutan)
                     VALUES (?,?,?,?,?,?,?,?,?)"
                )->execute([$tid, $oid, $nama, $tipe, $nilai, $biaya, $disk, $aktif, $urut]);
            }
            echo json_encode(['success'=>true]);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            echo json_encode(['error' => str_contains($msg, 'uniq_tenant_tier') || str_contains($msg, 'Duplicate')
                ? "Nama tier \"$nama\" sudah ada" : 'Gagal: '.$msg]);
        }
        exit;
    }

    if ($action === 'tier_delete' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $tierId = (int)($d['id'] ?? 0);
        try {
            // Cek apakah ada pelanggan aktif di tier ini
            $c = $db->prepare("SELECT COUNT(*) FROM hl_pelanggan_member WHERE tenant_id=? AND member_tier_id=? AND status='aktif'");
            $c->execute([$tid, $tierId]);
            if ((int)$c->fetchColumn() > 0) {
                echo json_encode(['error'=>'Tidak bisa dihapus: masih ada pelanggan aktif di tier ini. Nonaktifkan saja.']);
                exit;
            }
            $db->prepare("DELETE FROM hl_member_tier WHERE id=? AND tenant_id=?")->execute([$tierId, $tid]);
            echo json_encode(['success'=>true]);
        } catch (Throwable $e) {
            echo json_encode(['error'=>'Gagal: '.$e->getMessage()]);
        }
        exit;
    }

    // ── List pelanggan member ──
    if ($action === 'enrollments') {
        try {
            $st = $db->prepare(
                "SELECT m.id, m.pelanggan_id, m.tgl_mulai, m.tgl_kadaluarsa,
                        m.biaya_dibayar, m.status, m.created_at,
                        p.nama AS nama_pelanggan, p.telepon,
                        t.nama_tier, t.diskon_persen
                   FROM hl_pelanggan_member m
                   JOIN hl_pelanggan p ON p.id = m.pelanggan_id
                   JOIN hl_member_tier t ON t.id = m.member_tier_id
                  WHERE m.tenant_id = ?
                  ORDER BY m.created_at DESC LIMIT 200"
            );
            $st->execute([$tid]);
            echo json_encode(['rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable) {
            echo json_encode(['rows' => []]);
        }
        exit;
    }

    // ── Enroll pelanggan ke tier ──
    if ($action === 'enroll' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $pid = (int)($d['pelanggan_id'] ?? 0);
        $tier = (int)($d['tier_id'] ?? 0);
        [$id, $err] = MemberTier::enroll($tid, $oid, $pid, $tier, (int)$user['id'], (string)($d['catatan'] ?? ''));
        echo json_encode($err ? ['error'=>$err] : ['success'=>true, 'id'=>$id]);
        exit;
    }

    // ── Search pelanggan utk enrollment dropdown ──
    if ($action === 'search_pelanggan') {
        $q = '%' . substr(trim($_GET['q'] ?? ''), 0, 50) . '%';
        $st = $db->prepare(
            "SELECT id, nama, telepon FROM hl_pelanggan
              WHERE tenant_id=? AND (nama LIKE ? OR telepon LIKE ?)
              ORDER BY nama LIMIT 20"
        );
        $st->execute([$tid, $q, $q]);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    exit;
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Member Tier'); ?>
</head>
<body>
<?php renderTopbar('member'); ?>

<div class="hl-main">
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:18px;">
    <button class="hl-btn hl-btn-primary" onclick="openTierModal()">⭐ Tambah Tier</button>
    <button class="hl-btn hl-btn-outline" onclick="openEnrollModal()">+ Daftar Pelanggan ke Tier</button>
    <div style="margin-left:auto;font-size:12px;color:var(--gray)">
      Tier menentukan diskon otomatis di POS untuk pelanggan member.
    </div>
  </div>

  <!-- Tier list -->
  <div class="hl-card" style="margin-bottom:18px">
    <div class="hl-card-header"><strong>Daftar Tier</strong></div>
    <div class="hl-card-body" style="padding:0">
      <div id="tierList" style="min-height:80px">⏳ Memuat...</div>
    </div>
  </div>

  <!-- Enrollment list -->
  <div class="hl-card">
    <div class="hl-card-header"><strong>Pendaftaran Member Terbaru (200 terakhir)</strong></div>
    <div class="hl-card-body" style="padding:0">
      <div id="enrollList" style="min-height:80px;overflow-x:auto">⏳ Memuat...</div>
    </div>
  </div>
</div>

<!-- ════ Modal Tier ════ -->
<div class="hl-modal-overlay" id="modalTier">
  <div class="hl-modal hl-modal-sm">
    <div class="hl-modal-header">
      <span class="hl-modal-title" id="tierModalTitle">⭐ Tambah Tier</span>
      <button class="hl-modal-close" onclick="closeTierModal()">✕</button>
    </div>
    <div class="hl-modal-body">
      <input type="hidden" id="tf_id"/>
      <div class="hl-form-group">
        <label class="hl-label">Nama Tier <span class="req">*</span></label>
        <input type="text" id="tf_nama" class="hl-input" placeholder="Gold, Silver, VIP" maxlength="50"/>
      </div>
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Masa Aktif</label>
          <select id="tf_tipe" class="hl-input" onchange="updateMasaAktifInput()">
            <option value="bulan">Bulan</option>
            <option value="tahun">Tahun</option>
            <option value="seumur">Seumur Hidup</option>
          </select>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Lama <span id="lamaUnit" style="color:var(--gray);font-size:11px;">(bulan)</span></label>
          <input type="number" id="tf_nilai" class="hl-input" value="12" min="1"/>
        </div>
      </div>
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Biaya Pendaftaran (Rp) <span style="font-size:11px;color:var(--gray);font-weight:400">— 0 = gratis</span></label>
          <input type="number" id="tf_biaya" class="hl-input" value="0" min="0" step="1000"/>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Diskon Otomatis (%)</label>
          <input type="number" id="tf_diskon" class="hl-input" value="0" min="0" max="100" step="0.5"/>
        </div>
      </div>
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Urutan</label>
          <input type="number" id="tf_urutan" class="hl-input" value="0" min="0"/>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Status</label>
          <select id="tf_active" class="hl-input">
            <option value="1">✅ Aktif</option>
            <option value="0">⏸️ Nonaktif</option>
          </select>
        </div>
      </div>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeTierModal()">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="saveTier()">💾 Simpan</button>
    </div>
  </div>
</div>

<!-- ════ Modal Enroll ════ -->
<div class="hl-modal-overlay" id="modalEnroll">
  <div class="hl-modal hl-modal-sm">
    <div class="hl-modal-header">
      <span class="hl-modal-title">+ Daftarkan Pelanggan ke Tier</span>
      <button class="hl-modal-close" onclick="closeEnrollModal()">✕</button>
    </div>
    <div class="hl-modal-body">
      <div class="hl-form-group">
        <label class="hl-label">Pilih Pelanggan <span class="req">*</span></label>
        <input type="text" id="ef_pelanggan_search" class="hl-input" placeholder="🔍 Cari nama/telepon..." oninput="searchPelangganDebounced(this.value)" autocomplete="off"/>
        <div id="pelangganResults" style="border:1px solid #E5E7EB;border-radius:6px;max-height:160px;overflow-y:auto;margin-top:4px;display:none;font-size:13px;"></div>
        <div id="selectedPelanggan" style="margin-top:6px;font-size:13px;color:#0F7B6C;font-weight:600;"></div>
        <input type="hidden" id="ef_pelanggan_id"/>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Pilih Tier <span class="req">*</span></label>
        <select id="ef_tier" class="hl-input"></select>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Catatan (opsional)</label>
        <textarea id="ef_catatan" class="hl-input" rows="2" placeholder="Mis. promo launch"></textarea>
      </div>
      <div style="background:#FEF3C7;border:1px solid #FDE68A;border-radius:6px;padding:8px 12px;font-size:11.5px;color:#92400E;">
        💡 Membership lama (kalau ada) akan auto-cancel. 1 pelanggan = 1 active tier.
      </div>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeEnrollModal()">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="doEnroll()">✅ Daftarkan</button>
    </div>
  </div>
</div>

<?php renderToast(); ?>

<script>
const fmt = n => Number(n||0).toLocaleString('id-ID');
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

let allTiers = [];

// ── Tier CRUD ──
async function loadTiers() {
  const list = document.getElementById('tierList');
  list.innerHTML = '<div style="padding:12px;color:var(--gray)">⏳ Memuat...</div>';
  const r = await fetch('?action=tier_list');
  const d = await r.json();
  if (d.error) { showToast(d.error, 'info'); list.innerHTML = ''; return; }
  allTiers = d.tiers || [];
  renderTiers(allTiers);
  // Populate enroll modal select
  const sel = document.getElementById('ef_tier');
  sel.innerHTML = allTiers.filter(t => t.is_active==1).map(t => {
    const masa = t.masa_aktif_tipe === 'seumur' ? 'seumur hidup' : `${t.masa_aktif_nilai} ${t.masa_aktif_tipe}`;
    const fee = t.biaya_pendaftaran > 0 ? `Rp ${fmt(t.biaya_pendaftaran)}` : 'gratis';
    return `<option value="${t.id}">${esc(t.nama_tier)} — ${masa}, ${fee}${t.diskon_persen>0?', -'+t.diskon_persen+'%':''}</option>`;
  }).join('');
}

function renderTiers(tiers) {
  const list = document.getElementById('tierList');
  if (!tiers.length) {
    list.innerHTML = '<div style="padding:24px;text-align:center;color:var(--gray)">⭐ Belum ada tier. Klik "Tambah Tier" untuk mulai.</div>';
    return;
  }
  list.innerHTML = `<table class="hl-table" style="width:100%;font-size:13px;">
    <thead><tr>
      <th>Nama Tier</th><th>Masa Aktif</th><th>Biaya</th><th>Diskon Otomatis</th><th>Member Aktif</th><th>Status</th><th></th>
    </tr></thead>
    <tbody>${tiers.map(t => `
      <tr>
        <td><strong>⭐ ${esc(t.nama_tier)}</strong></td>
        <td>${t.masa_aktif_tipe === 'seumur' ? 'Seumur hidup' : `${t.masa_aktif_nilai} ${t.masa_aktif_tipe}`}</td>
        <td>${t.biaya_pendaftaran > 0 ? 'Rp ' + fmt(t.biaya_pendaftaran) : 'Gratis'}</td>
        <td>${t.diskon_persen > 0 ? '-' + parseFloat(t.diskon_persen) + '%' : '-'}</td>
        <td>${t.active_members||0} orang</td>
        <td>${t.is_active==1?'<span style="color:#059669">●Aktif</span>':'<span style="color:#9CA3AF">○Off</span>'}</td>
        <td style="text-align:right;white-space:nowrap">
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick='editTier(${JSON.stringify(t)})'>✏️</button>
          <button class="hl-btn hl-btn-danger hl-btn-sm" onclick="deleteTier(${t.id})">🗑️</button>
        </td>
      </tr>`).join('')}
    </tbody></table>`;
}

function updateMasaAktifInput() {
  const tipe = document.getElementById('tf_tipe').value;
  const lama = document.getElementById('lamaUnit');
  const input = document.getElementById('tf_nilai');
  if (tipe === 'seumur') { lama.textContent = '(N/A)'; input.disabled = true; input.value = 0; }
  else { lama.textContent = `(${tipe})`; input.disabled = false; }
}

function openTierModal() {
  document.getElementById('tierModalTitle').textContent = '⭐ Tambah Tier';
  document.getElementById('tf_id').value = '';
  document.getElementById('tf_nama').value = '';
  document.getElementById('tf_tipe').value = 'bulan';
  document.getElementById('tf_nilai').value = 12;
  document.getElementById('tf_biaya').value = 0;
  document.getElementById('tf_diskon').value = 0;
  document.getElementById('tf_urutan').value = 0;
  document.getElementById('tf_active').value = 1;
  updateMasaAktifInput();
  document.getElementById('modalTier').classList.add('open');
}
function closeTierModal() { document.getElementById('modalTier').classList.remove('open'); }

function editTier(t) {
  document.getElementById('tierModalTitle').textContent = '✏️ Edit Tier';
  document.getElementById('tf_id').value     = t.id;
  document.getElementById('tf_nama').value   = t.nama_tier;
  document.getElementById('tf_tipe').value   = t.masa_aktif_tipe;
  document.getElementById('tf_nilai').value  = t.masa_aktif_nilai;
  document.getElementById('tf_biaya').value  = t.biaya_pendaftaran;
  document.getElementById('tf_diskon').value = t.diskon_persen;
  document.getElementById('tf_urutan').value = t.urutan;
  document.getElementById('tf_active').value = t.is_active;
  updateMasaAktifInput();
  document.getElementById('modalTier').classList.add('open');
}

async function saveTier() {
  const payload = {
    id: document.getElementById('tf_id').value || null,
    nama_tier: document.getElementById('tf_nama').value.trim(),
    masa_aktif_tipe: document.getElementById('tf_tipe').value,
    masa_aktif_nilai: parseInt(document.getElementById('tf_nilai').value)||1,
    biaya_pendaftaran: parseFloat(document.getElementById('tf_biaya').value)||0,
    diskon_persen: parseFloat(document.getElementById('tf_diskon').value)||0,
    urutan: parseInt(document.getElementById('tf_urutan').value)||0,
    is_active: parseInt(document.getElementById('tf_active').value),
  };
  if (!payload.nama_tier) { showToast('Nama tier wajib', 'error'); return; }
  const r = await fetch('?action=tier_save', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify(payload)
  });
  const d = await r.json();
  if (d.error) { showToast(d.error, 'error'); return; }
  showToast('Tier disimpan', 'success');
  closeTierModal();
  loadTiers();
}

async function deleteTier(id) {
  if (!confirm('Hapus tier ini?')) return;
  const r = await fetch('?action=tier_delete', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({id})
  });
  const d = await r.json();
  if (d.error) { showToast(d.error, 'error'); return; }
  showToast('Tier dihapus', 'success');
  loadTiers();
}

// ── Enrollment list ──
async function loadEnrollments() {
  const list = document.getElementById('enrollList');
  list.innerHTML = '<div style="padding:12px;color:var(--gray)">⏳ Memuat...</div>';
  const r = await fetch('?action=enrollments');
  const d = await r.json();
  const rows = d.rows || [];
  if (!rows.length) {
    list.innerHTML = '<div style="padding:24px;text-align:center;color:var(--gray)">Belum ada pelanggan yang terdaftar member.</div>';
    return;
  }
  list.innerHTML = `<table class="hl-table" style="width:100%;font-size:13px;">
    <thead><tr>
      <th>Pelanggan</th><th>Tier</th><th>Mulai</th><th>Kadaluarsa</th><th>Biaya</th><th>Status</th>
    </tr></thead>
    <tbody>${rows.map(r => {
      const statusColor = {aktif:'#059669', expired:'#9CA3AF', dibatalkan:'#DC2626'}[r.status] || '#9CA3AF';
      const statusIcon  = {aktif:'●', expired:'○', dibatalkan:'✕'}[r.status] || '·';
      return `<tr>
        <td><strong>${esc(r.nama_pelanggan)}</strong><br><span style="font-size:11px;color:var(--gray)">${esc(r.telepon||'-')}</span></td>
        <td>⭐ ${esc(r.nama_tier)}${r.diskon_persen>0?` <span style="font-size:11px;color:#0F7B6C">-${parseFloat(r.diskon_persen)}%</span>`:''}</td>
        <td>${r.tgl_mulai||'-'}</td>
        <td>${r.tgl_kadaluarsa||'<em style="color:#0F7B6C">seumur hidup</em>'}</td>
        <td>Rp ${fmt(r.biaya_dibayar)}</td>
        <td style="color:${statusColor};text-transform:capitalize">${statusIcon} ${r.status}</td>
      </tr>`;
    }).join('')}</tbody></table>`;
}

// ── Enroll modal ──
let selectedPelangganId = null;
function openEnrollModal() {
  selectedPelangganId = null;
  document.getElementById('ef_pelanggan_id').value = '';
  document.getElementById('ef_pelanggan_search').value = '';
  document.getElementById('selectedPelanggan').textContent = '';
  document.getElementById('pelangganResults').style.display = 'none';
  document.getElementById('ef_catatan').value = '';
  if (!allTiers.filter(t => t.is_active==1).length) {
    showToast('Buat tier dulu sebelum mendaftarkan pelanggan', 'error');
    return;
  }
  document.getElementById('modalEnroll').classList.add('open');
}
function closeEnrollModal() { document.getElementById('modalEnroll').classList.remove('open'); }

let searchTimer = null;
function searchPelangganDebounced(q) {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => searchPelanggan(q), 250);
}
async function searchPelanggan(q) {
  const box = document.getElementById('pelangganResults');
  if (!q || q.length < 2) { box.style.display = 'none'; return; }
  const r = await fetch('?action=search_pelanggan&q=' + encodeURIComponent(q));
  const rows = await r.json();
  if (!rows.length) {
    box.innerHTML = '<div style="padding:8px 12px;color:var(--gray)">Tidak ditemukan</div>';
  } else {
    box.innerHTML = rows.map(p => `
      <div style="padding:6px 10px;cursor:pointer;border-bottom:1px solid #F3F4F6"
           onclick="selectPelanggan(${p.id}, ${JSON.stringify(p.nama).replace(/"/g,'&quot;')}, ${JSON.stringify(p.telepon||'').replace(/"/g,'&quot;')})">
        <strong>${esc(p.nama)}</strong> <span style="color:var(--gray);font-size:11px">${esc(p.telepon||'-')}</span>
      </div>`).join('');
  }
  box.style.display = 'block';
}
function selectPelanggan(id, nama, telepon) {
  selectedPelangganId = id;
  document.getElementById('ef_pelanggan_id').value = id;
  document.getElementById('selectedPelanggan').textContent = `✅ ${nama} (${telepon||'-'})`;
  document.getElementById('pelangganResults').style.display = 'none';
  document.getElementById('ef_pelanggan_search').value = nama;
}

async function doEnroll() {
  const pid = document.getElementById('ef_pelanggan_id').value;
  const tier = document.getElementById('ef_tier').value;
  if (!pid) { showToast('Pilih pelanggan dulu', 'error'); return; }
  if (!tier) { showToast('Pilih tier', 'error'); return; }
  const r = await fetch('?action=enroll', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({
      pelanggan_id: pid, tier_id: tier,
      catatan: document.getElementById('ef_catatan').value
    })
  });
  const d = await r.json();
  if (d.error) { showToast(d.error, 'error'); return; }
  showToast('Pelanggan terdaftar sebagai member', 'success');
  closeEnrollModal();
  loadTiers();
  loadEnrollments();
}

// Init
document.addEventListener('DOMContentLoaded', () => {
  loadTiers();
  loadEnrollments();
});
</script>

</body>
</html>

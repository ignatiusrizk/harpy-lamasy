<?php
$activePage = 'layanan';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/ServiceCatalog.php';
require_once __DIR__ . '/components.php';
$user = currentUser();
requirePermission('layanan.view');

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $tid = TenantResolver::id();
    $oid = TenantResolver::outletId();

    if ($action === 'list') {
        // JOIN master untuk expose aturan override (kalau kolom master ada)
        try {
            $rows = TenantQuery::raw(
                "SELECT l.*, m.allow_override, m.override_max_pct, m.harga_default
                   FROM hl_layanan l
                   LEFT JOIN hl_layanan_master m ON m.id = l.master_id
                  WHERE l.tenant_id=? AND l.outlet_id=? ORDER BY l.kategori,l.urutan,l.nama",
                [$tid, $oid]
            );
        } catch (Throwable) {
            // Fallback kalau migration master belum dijalankan
            $rows = TenantQuery::raw(
                "SELECT * FROM hl_layanan WHERE tenant_id=? AND outlet_id=? ORDER BY kategori,urutan,nama",
                [$tid, $oid]
            );
        }
        echo json_encode($rows); exit;
    }

    // ── Override harga layanan dari master (outlet adjust ±max_pct) ──
    if ($action === 'override_harga' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('layanan.edit')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $masterId = (int)($d['master_id'] ?? 0);
        $harga    = (float)($d['harga'] ?? 0);
        if (!$masterId) { echo json_encode(['error'=>'Layanan bukan dari master']); exit; }
        try {
            ServiceCatalog::setOutletOverride($tid, $oid, $masterId, $harga);
            logAudit('override','layanan',"Adjust harga layanan master #$masterId jadi Rp ".number_format($harga,0,',','.'));
            echo json_encode(['success'=>true]);
        } catch (Throwable $e) {
            echo json_encode(['error'=>$e->getMessage()]);
        }
        exit;
    }
    if ($action === 'save' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('layanan.create') && !hasPermission('layanan.edit')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $nama    = substr(trim(strip_tags($d['nama'] ?? '')), 0, 100);
        $kategori= substr(trim(strip_tags($d['kategori'] ?? '')), 0, 50);
        if (!$nama) { echo json_encode(['error'=>'Nama wajib diisi']); exit; }
        // Layanan dari master katalog: nama/kategori/satuan dikunci HQ.
        // Harga harus lewat override (action=override_harga).
        if (!empty($d['id'])) {
            try {
                $chk = TenantQuery::raw("SELECT master_id FROM hl_layanan WHERE id=? AND tenant_id=? AND outlet_id=?",
                    [intval($d['id']), $tid, $oid]);
                if (!empty($chk[0]['master_id'])) {
                    echo json_encode(['error'=>'Layanan ini dari master katalog HQ. Hanya harga yang bisa di-adjust (jika diizinkan).']);
                    exit;
                }
            } catch (Throwable) {}
        }
        if (!empty($d['id'])) {
            TenantQuery::update('hl_layanan', [
                'nama'     => $nama,
                'kategori' => $kategori,
                'satuan'   => $d['satuan'] ?? 'kg',
                'harga'    => floatval($d['harga'] ?? 0),
                'is_active'=> intval($d['is_active'] ?? 1),
                'urutan'   => intval($d['urutan'] ?? 0),
            ], 'id = ?', [intval($d['id'])]);
        } else {
            TenantQuery::insert('hl_layanan', [
                'nama'     => $nama,
                'kategori' => $kategori,
                'satuan'   => $d['satuan'] ?? 'kg',
                'harga'    => floatval($d['harga'] ?? 0),
                'urutan'   => intval($d['urutan'] ?? 0),
                'is_active'=> 1,
            ]);
        }
        logAudit(!empty($d['id'])?'update':'create','layanan',(!empty($d['id'])?'Edit':'Tambah').' layanan: '.$nama);
        echo json_encode(['success'=>true]); exit;
    }
    if ($action === 'delete' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('layanan.delete')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        TenantQuery::update('hl_layanan', ['is_active'=>0], 'id = ?', [intval($d['id'])]);
        echo json_encode(['success'=>true]); exit;
    }
    if ($action === 'toggle' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('layanan.edit')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        TenantQuery::update('hl_layanan', ['is_active'=>intval($d['is_active'])], 'id = ?', [intval($d['id'])]);
        echo json_encode(['success'=>true]); exit;
    }

    // ── Tier Express CRUD ──
    if ($action === 'tier_list') {
        $lid = (int)($_GET['layanan_id'] ?? 0);
        // Verifikasi layanan milik tenant ini sebelum return tier-nya
        $own = TenantQuery::rawOne("SELECT id FROM hl_layanan WHERE id=? AND tenant_id=? AND outlet_id=?",
                                    [$lid, $tid, $oid]);
        if (!$own) { echo json_encode(['error'=>'Layanan tidak ditemukan']); exit; }
        try {
            $st = Database::get()->prepare(
                "SELECT id, nama_tier, estimasi_jam, tipe_biaya, nilai_biaya, is_active, urutan
                   FROM hl_layanan_express_tier
                  WHERE layanan_id = ? ORDER BY urutan ASC, estimasi_jam DESC"
            );
            $st->execute([$lid]);
            echo json_encode(['tiers' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) {
            echo json_encode(['error' => 'Tabel tier belum ada. Run migration: layanan_express_tier_migration.sql', 'tiers' => []]);
        }
        exit;
    }

    if ($action === 'tier_save' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('layanan.edit') && !hasPermission('layanan.create')) {
            echo json_encode(['error'=>'Akses ditolak']); exit;
        }
        verifyCsrf();
        $d   = json_decode(file_get_contents('php://input'), true);
        $lid = (int)($d['layanan_id'] ?? 0);
        $own = TenantQuery::rawOne("SELECT id FROM hl_layanan WHERE id=? AND tenant_id=? AND outlet_id=?",
                                    [$lid, $tid, $oid]);
        if (!$own) { echo json_encode(['error'=>'Layanan tidak valid']); exit; }

        $nama   = substr(trim((string)($d['nama_tier'] ?? '')), 0, 50);
        $jam    = max(1, (int)($d['estimasi_jam'] ?? 0));
        $tipe   = in_array($d['tipe_biaya'] ?? '', ['flat','percent'], true) ? $d['tipe_biaya'] : 'percent';
        $nilai  = max(0, (float)($d['nilai_biaya'] ?? 0));
        $aktif  = (int)($d['is_active'] ?? 1) ? 1 : 0;
        $urut   = (int)($d['urutan'] ?? 0);
        if ($nama === '' || $jam <= 0 || $nilai < 0) {
            echo json_encode(['error'=>'Nama tier, estimasi jam, dan nilai wajib diisi (jam > 0)']); exit;
        }

        $db = Database::get();
        try {
            if (!empty($d['id'])) {
                $st = $db->prepare("UPDATE hl_layanan_express_tier
                                       SET nama_tier=?, estimasi_jam=?, tipe_biaya=?, nilai_biaya=?,
                                           is_active=?, urutan=?
                                     WHERE id=? AND layanan_id=?");
                $st->execute([$nama, $jam, $tipe, $nilai, $aktif, $urut, (int)$d['id'], $lid]);
            } else {
                $st = $db->prepare("INSERT INTO hl_layanan_express_tier
                                       (tenant_id, outlet_id, layanan_id, nama_tier, estimasi_jam,
                                        tipe_biaya, nilai_biaya, is_active, urutan)
                                     VALUES (?,?,?,?,?,?,?,?,?)");
                $st->execute([$tid, $oid, $lid, $nama, $jam, $tipe, $nilai, $aktif, $urut]);
            }
            echo json_encode(['success'=>true]);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'uniq_layanan_tier') || str_contains($msg, 'Duplicate')) {
                echo json_encode(['error'=>'Nama tier "'.$nama.'" sudah ada di layanan ini']);
            } else {
                echo json_encode(['error'=>'Gagal simpan: '.$msg]);
            }
        }
        exit;
    }

    if ($action === 'tier_delete' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('layanan.delete') && !hasPermission('layanan.edit')) {
            echo json_encode(['error'=>'Akses ditolak']); exit;
        }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $tierId = (int)($d['id'] ?? 0);
        // Verifikasi tier milik layanan tenant ini
        $own = TenantQuery::rawOne(
            "SELECT t.id FROM hl_layanan_express_tier t
               JOIN hl_layanan l ON l.id = t.layanan_id
              WHERE t.id=? AND l.tenant_id=? AND l.outlet_id=?",
            [$tierId, $tid, $oid]
        );
        if (!$own) { echo json_encode(['error'=>'Tier tidak ditemukan']); exit; }
        Database::get()->prepare("DELETE FROM hl_layanan_express_tier WHERE id=?")->execute([$tierId]);
        echo json_encode(['success'=>true]); exit;
    }
    if ($action === 'stats') {
        $total    = TenantQuery::count('hl_layanan', 'is_active=1');
        $kat      = TenantQuery::raw("SELECT COUNT(DISTINCT kategori) as c FROM hl_layanan WHERE tenant_id=? AND is_active=1", [$tid]);
        $terlaris = TenantQuery::raw(
            "SELECT i.nama_layanan, COUNT(*) as c FROM hl_transaksi_item i
             WHERE i.tenant_id=? GROUP BY i.nama_layanan ORDER BY c DESC LIMIT 1",
            [$tid]
        );
        echo json_encode([
            'total'    => $total,
            'kategori' => intval($kat[0]['c'] ?? 0),
            'terlaris' => $terlaris[0]['nama_layanan'] ?? '-',
        ]); exit;
    }
    echo json_encode(['error'=>'Unknown']); exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Master Layanan'); ?>
<style>
.layanan-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
.layanan-card{background:var(--white);border-radius:var(--r-lg);border:2px solid rgba(27,45,90,.07);padding:18px;transition:all .2s;position:relative}
.layanan-card:hover{box-shadow:var(--shadow-lg);transform:translateY(-2px)}
.layanan-card.inactive{opacity:.5}
.layanan-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:var(--r-lg) var(--r-lg) 0 0;background:var(--teal)}
.layanan-harga{font-family:var(--mono);font-size:1.3rem;font-weight:800;color:var(--navy);margin:6px 0 4px}
.lyn-badge{font-size:9px;font-weight:700;padding:2px 7px;border-radius:100px;margin-left:4px;white-space:nowrap}
.lyn-badge.adj{background:#E0F2FE;color:#0369A1}
.lyn-badge.lock{background:#F3F4F6;color:#6B7280}
.lyn-badge.ov{background:#FEF3C7;color:#92400E}
.layanan-nama{font-size:15px;font-weight:700;color:var(--navy);margin-bottom:4px}
.layanan-kat{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:var(--gray);margin-bottom:10px}
.layanan-actions{display:flex;gap:6px;margin-top:12px}
.toggle-switch{position:relative;width:40px;height:22px;cursor:pointer}
.toggle-switch input{opacity:0;width:0;height:0}
.toggle-slider{position:absolute;inset:0;background:#CBD5E1;border-radius:100px;transition:.3s}
.toggle-slider::before{content:'';position:absolute;width:16px;height:16px;left:3px;bottom:3px;background:white;border-radius:50%;transition:.3s}
input:checked + .toggle-slider{background:var(--green)}
input:checked + .toggle-slider::before{transform:translateX(18px)}
@media(max-width:680px){
  .layanan-grid{grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px}
  .layanan-harga{font-size:1.1rem}
}
@media(max-width:400px){.layanan-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php renderTopbar('layanan'); ?>
<div class="hl-main">

  <!-- #3 Flag penjelas hierarki master → outlet -->
  <div style="display:flex;align-items:flex-start;gap:10px;background:#EFF6FF;border:1px solid #BFDBFE;
              border-radius:10px;padding:11px 14px;margin-bottom:16px;font-size:13px;color:#1E40AF;line-height:1.55">
    <span style="font-size:16px;flex-shrink:0">🧺</span>
    <div>
      <strong>Layanan khusus outlet ini.</strong>
      Daftar &amp; harga dasar dikelola terpusat di <strong>Master Katalog (HQ)</strong> lalu di-push ke outlet.
      Di sini kamu bisa lihat &amp; sesuaikan harga khusus outlet ini.
      <?php if (($user['role'] ?? '') === 'owner'): ?>
        <a href="/hq/layanan" style="color:#1D4ED8;font-weight:700;text-decoration:underline">Buka Master Katalog →</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="hl-stat-grid-4" style="margin-bottom:20px">
    <div class="hl-stat-card teal"><div class="hl-stat-num" id="sTotal">-</div><div class="hl-stat-label">🧺 Layanan Aktif</div></div>
    <div class="hl-stat-card navy"><div class="hl-stat-num" id="sKat">-</div><div class="hl-stat-label">📂 Kategori</div></div>
    <div class="hl-stat-card green"><div class="hl-stat-num" id="sTerlaris" style="font-size:1rem">-</div><div class="hl-stat-label">🏆 Terlaris</div></div>
    <div class="hl-stat-card purple">
      <?php if (hasPermission('layanan.create')): ?>
      <button class="hl-btn hl-btn-primary hl-btn-full" onclick="openModal()" style="margin-top:4px">+ Tambah Layanan</button>
      <?php else: ?>
      <span style="font-size:12px;color:rgba(255,255,255,.55)">View Only</span>
      <?php endif; ?>
    </div>
  </div>

  <div class="hl-filter-collapsible">
    <button class="hl-filter-toggle-btn" id="layananFilterBtn" onclick="toggleFilter('layananFilter')">
      🔍 Filter &amp; Pencarian <span class="hl-filter-active-dot" id="layananFilterDot"></span>
      <span class="hl-toggle-arrow">▼</span>
    </button>
    <div class="hl-filter-bar collapsed" id="layananFilter">
      <span class="hl-filter-label">Filter</span>
      <select id="fKat" class="hl-input" style="width:auto" onchange="renderLayanan()">
        <option value="">Semua Kategori</option>
      </select>
      <select id="fStatus" class="hl-input" style="width:auto" onchange="renderLayanan()">
        <option value="">Semua Status</option>
        <option value="1">Aktif</option>
        <option value="0">Nonaktif</option>
      </select>
      <input type="text" id="fSearch" class="hl-input" placeholder="🔍 Cari layanan..." style="max-width:240px" oninput="renderLayanan()"/>
      <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadLayanan()">↻</button>
    </div>
  </div>

  <div class="layanan-grid" id="layananGrid">
    <div class="hl-loading">⏳ Memuat...</div>
  </div>
</div>

<!-- MODAL -->
<div class="hl-modal-overlay" id="modalLayanan">
  <div class="hl-modal hl-modal-sm">
    <div class="hl-modal-header">
      <span class="hl-modal-title" id="modalTitle">➕ Tambah Layanan</span>
      <button class="hl-modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="hl-modal-body">
      <input type="hidden" id="f_id"/>
      <div class="hl-form-group">
        <label class="hl-label">Nama Layanan <span class="req">*</span></label>
        <input type="text" id="f_nama" class="hl-input" placeholder="Contoh: Kiloan Reguler"/>
      </div>
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Kategori <span class="req">*</span></label>
          <input type="text" id="f_kat" class="hl-input" placeholder="Kiloan, Satuan, dll" list="katList"/>
          <datalist id="katList"></datalist>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Satuan</label>
          <select id="f_satuan" class="hl-input">
            <option value="kg">kg (kiloan)</option>
            <option value="pcs">pcs (potong/satuan)</option>
            <option value="item">item</option>
            <option value="pasang">pasang (sepatu/sandal)</option>
            <option value="set">set</option>
            <option value="lembar">lembar (selimut/sprei)</option>
            <option value="meter">meter (gorden/karpet)</option>
            <option value="kodi">kodi</option>
          </select>
        </div>
      </div>
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Harga / Satuan (Rp) <span class="req">*</span></label>
          <input type="number" id="f_harga" class="hl-input" placeholder="0" min="0" step="500"/>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Urutan Tampil</label>
          <input type="number" id="f_urutan" class="hl-input" value="0" min="0"/>
        </div>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Status</label>
        <select id="f_active" class="hl-input">
          <option value="1">✅ Aktif</option>
          <option value="0">⏸️ Nonaktif</option>
        </select>
      </div>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeModal()">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="saveLayanan()">💾 Simpan</button>
    </div>
  </div>
</div>
<!-- ════ Modal Tier Express ════ -->
<div class="hl-modal-overlay" id="modalTier">
  <div class="hl-modal" style="max-width:680px">
    <div class="hl-modal-header">
      <span class="hl-modal-title">⚡ Tier Express — <span id="tierLayananNama"></span></span>
      <button class="hl-modal-close" onclick="closeTierModal()">✕</button>
    </div>
    <div class="hl-modal-body">
      <input type="hidden" id="tier_layanan_id"/>

      <div style="background:#FEF3C7;border:1px solid #FDE68A;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#92400E;line-height:1.5;">
        💡 Atur tier express untuk layanan ini. Saat POS membuat nota dengan layanan ini, kasir bisa pilih tier yang sesuai → biaya tambahan & estimasi selesai dihitung otomatis.
      </div>

      <!-- List tier -->
      <div id="tierList" style="margin-bottom:16px;"></div>

      <!-- Form tambah/edit tier -->
      <div style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:10px;padding:14px;">
        <div style="font-weight:600;font-size:13px;color:#374151;margin-bottom:10px;" id="tierFormTitle">➕ Tambah Tier Baru</div>
        <input type="hidden" id="tf_id"/>
        <div class="hl-form-row">
          <div class="hl-form-group">
            <label class="hl-label">Nama Tier <span class="req">*</span></label>
            <input type="text" id="tf_nama" class="hl-input" placeholder="Express 12 Jam" maxlength="50"/>
          </div>
          <div class="hl-form-group">
            <label class="hl-label">Estimasi Selesai (jam) <span class="req">*</span></label>
            <input type="number" id="tf_jam" class="hl-input" placeholder="12" min="1" max="168"/>
          </div>
        </div>
        <div class="hl-form-row">
          <div class="hl-form-group">
            <label class="hl-label">Tipe Biaya</label>
            <select id="tf_tipe" class="hl-input" onchange="updateNilaiUnit()">
              <option value="percent">Percent (% dari subtotal item)</option>
              <option value="flat">Flat (Rp tetap)</option>
            </select>
          </div>
          <div class="hl-form-group">
            <label class="hl-label">Nilai <span class="req">*</span> <span id="nilaiUnit" style="color:var(--gray);font-weight:400;">(%)</span></label>
            <input type="number" id="tf_nilai" class="hl-input" placeholder="30" min="0" step="any"/>
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
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px;">
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="resetTierForm()">↺ Reset</button>
          <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="saveTier()">💾 Simpan Tier</button>
        </div>
      </div>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeTierModal()">Tutup</button>
    </div>
  </div>
</div>

<?php renderToast(); ?>
<script>
let allLayanan = [];
const CAN_CREATE = <?= hasPermission('layanan.create') ? 'true' : 'false' ?>;
const CAN_EDIT   = <?= hasPermission('layanan.edit')   ? 'true' : 'false' ?>;
const CAN_DELETE = <?= hasPermission('layanan.delete') ? 'true' : 'false' ?>;

document.addEventListener('DOMContentLoaded', () => { loadLayanan(); loadStats(); });

async function loadStats() {
  const r = await fetch('layanan.php?action=stats');
  const d = await r.json();
  document.getElementById('sTotal').textContent    = d.total;
  document.getElementById('sKat').textContent      = d.kategori;
  document.getElementById('sTerlaris').textContent = d.terlaris;
}

// Rekomendasi kategori umum laundry — digabung dengan kategori yang sudah dipakai
const KAT_REKOMENDASI = ['Kiloan','Satuan','Express','Setrika','Cuci Kering','Dry Clean','Khusus','Sepatu','Bedcover & Selimut','Karpet & Gorden','B2B / Korporat'];

async function loadLayanan() {
  const r = await fetch('layanan.php?action=list');
  allLayanan = await r.json();
  const kats = [...new Set(allLayanan.map(l=>l.kategori).filter(Boolean))].sort();
  const fKat = document.getElementById('fKat');
  fKat.innerHTML = '<option value="">Semua Kategori</option>' + kats.map(k=>`<option>${k}</option>`).join('');
  // datalist input kategori: gabung rekomendasi + yang sudah dipakai (unik)
  const katOpts = [...new Set([...kats, ...KAT_REKOMENDASI])];
  document.getElementById('katList').innerHTML = katOpts.map(k=>`<option value="${k}">`).join('');
  renderLayanan();
}

function renderLayanan() {
  const q      = document.getElementById('fSearch').value.toLowerCase();
  const kat    = document.getElementById('fKat').value;
  const status = document.getElementById('fStatus').value;

  let list = allLayanan;
  if (q)      list = list.filter(l => l.nama.toLowerCase().includes(q) || (l.kategori||'').toLowerCase().includes(q));
  if (kat)    list = list.filter(l => l.kategori === kat);
  if (status !== '') list = list.filter(l => String(l.is_active) === status);

  const grid = document.getElementById('layananGrid');
  if (!list.length) { grid.innerHTML = `<div style="grid-column:1/-1"><div class="hl-empty-v2">
    <div class="e-icon">🧺</div>
    <div class="e-title">Belum ada layanan</div>
    <div class="e-sub">Tambah layanan supaya bisa dipakai di POS</div>
  </div></div>`; return; }

  grid.innerHTML = list.map(l => {
    const isMaster = !!l.master_id;
    const canAdjust = isMaster && String(l.allow_override) === '1';
    const isOverridden = String(l.harga_overridden) === '1';

    // Badge sumber
    let badge = '';
    if (isMaster) {
      badge = canAdjust
        ? `<span class="lyn-badge adj" title="Dari HQ, boleh adjust ±${l.override_max_pct}%">🏢 HQ · ±${l.override_max_pct}%</span>`
        : `<span class="lyn-badge lock" title="Harga dikunci HQ">🔒 HQ</span>`;
    }
    const ovTag = isOverridden ? `<span class="lyn-badge ov">harga custom</span>` : '';

    // Tombol aksi: master → adjust/locked; non-master → edit/delete penuh
    let actions;
    if (isMaster) {
      actions = canAdjust
        ? `<button class="hl-btn hl-btn-outline hl-btn-sm" onclick='openAdjust(${JSON.stringify(l)})'>💲 Adjust Harga</button>`
        : `<span style="font-size:11px;color:var(--gray)">dikelola HQ</span>`;
    } else {
      actions = '';
      if (CAN_EDIT)   actions += `<button class="hl-btn hl-btn-outline hl-btn-sm" onclick="editLayanan(${l.id})">✏️ Edit</button>`;
      if (CAN_EDIT)   actions += `<button class="hl-btn hl-btn-outline hl-btn-sm" onclick="openTierModal(${l.id}, ${JSON.stringify(l.nama).replace(/"/g,'&quot;')})" title="Atur tier express (12 jam, 6 jam, dll)">⚡ Tier</button>`;
      if (CAN_DELETE) actions += `<button class="hl-btn hl-btn-danger hl-btn-sm" onclick="deleteLayanan(${l.id})">🗑️</button>`;
      if (!actions)   actions  = `<span style="font-size:11px;color:var(--gray)">view only</span>`;
    }

    return `
    <div class="layanan-card ${l.is_active==1?'':'inactive'}">
      <div class="layanan-kat">${esc(l.kategori||'Umum')} ${badge} ${ovTag}</div>
      <div class="layanan-nama">${esc(l.nama)}</div>
      <div class="layanan-harga">Rp ${parseFloat(l.harga).toLocaleString('id-ID')} <span style="font-size:13px;font-weight:400;color:var(--gray)">/ ${l.satuan}</span></div>
      ${canAdjust ? `<div style="font-size:11px;color:var(--gray);margin-top:2px">Default HQ: Rp ${parseFloat(l.harga_default).toLocaleString('id-ID')}</div>` : ''}
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px">
        <label class="toggle-switch" title="${l.is_active==1?'Nonaktifkan':'Aktifkan'}">
          <input type="checkbox" ${l.is_active==1?'checked':''} onchange="toggleLayanan(${l.id},this.checked)"/>
          <span class="toggle-slider"></span>
        </label>
        <div class="layanan-actions">${actions}</div>
      </div>
    </div>`;
  }).join('');
}

function openModal(data=null) {
  document.getElementById('f_id').value     = data?.id || '';
  document.getElementById('f_nama').value   = data?.nama || '';
  document.getElementById('f_kat').value    = data?.kategori || '';
  document.getElementById('f_satuan').value = data?.satuan || 'kg';
  document.getElementById('f_harga').value  = data?.harga || '';
  document.getElementById('f_urutan').value = data?.urutan || 0;
  document.getElementById('f_active').value = data?.is_active ?? 1;
  document.getElementById('modalTitle').textContent = data ? '✏️ Edit Layanan' : '➕ Tambah Layanan';
  document.getElementById('modalLayanan').classList.add('open');
}
function editLayanan(id) { openModal(allLayanan.find(l=>l.id==id)); }
function closeModal() { document.getElementById('modalLayanan').classList.remove('open'); }

// ── Tier Express CRUD ──
async function openTierModal(layananId, layananNama) {
  document.getElementById('tier_layanan_id').value = layananId;
  document.getElementById('tierLayananNama').textContent = layananNama || '';
  resetTierForm();
  document.getElementById('modalTier').classList.add('open');
  await loadTiers(layananId);
}
function closeTierModal() { document.getElementById('modalTier').classList.remove('open'); }

async function loadTiers(layananId) {
  const list = document.getElementById('tierList');
  list.innerHTML = '<div style="text-align:center;padding:14px;color:var(--gray);font-size:12px;">Memuat...</div>';
  try {
    const r = await fetch(`?action=tier_list&layanan_id=${layananId}`);
    const d = await r.json();
    if (d.error && d.tiers === undefined) { showToast(d.error, 'error'); list.innerHTML = ''; return; }
    if (d.error) showToast(d.error, 'info');
    renderTierList(d.tiers || []);
  } catch (e) {
    showToast('Gagal load tier: ' + e.message, 'error');
    list.innerHTML = '';
  }
}

function renderTierList(tiers) {
  const list = document.getElementById('tierList');
  if (!tiers.length) {
    list.innerHTML = '<div style="text-align:center;padding:24px;color:var(--gray);font-size:13px;background:#F9FAFB;border:1px dashed #E5E7EB;border-radius:8px;">⚡ Belum ada tier. Tambah pakai form di bawah ↓</div>';
    return;
  }
  list.innerHTML = `
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
      <thead>
        <tr style="background:#F3F4F6;text-align:left;">
          <th style="padding:8px 10px;">Nama Tier</th>
          <th style="padding:8px 10px;">Estimasi</th>
          <th style="padding:8px 10px;">Biaya</th>
          <th style="padding:8px 10px;">Status</th>
          <th style="padding:8px 10px;text-align:right;">Aksi</th>
        </tr>
      </thead>
      <tbody>
        ${tiers.map(t => `
          <tr style="border-bottom:1px solid #F3F4F6;">
            <td style="padding:10px;">⚡ <strong>${esc(t.nama_tier)}</strong></td>
            <td style="padding:10px;color:#4B5563;">${t.estimasi_jam} jam</td>
            <td style="padding:10px;">
              ${t.tipe_biaya === 'flat'
                ? '+Rp ' + Math.round(t.nilai_biaya).toLocaleString('id-ID')
                : '+' + parseFloat(t.nilai_biaya) + '%'}
            </td>
            <td style="padding:10px;">${t.is_active == 1 ? '<span style="color:#059669;">● Aktif</span>' : '<span style="color:#9CA3AF;">○ Off</span>'}</td>
            <td style="padding:10px;text-align:right;white-space:nowrap;">
              <button class="hl-btn hl-btn-outline hl-btn-sm" onclick='editTier(${JSON.stringify(t)})'>✏️</button>
              <button class="hl-btn hl-btn-danger hl-btn-sm" onclick="deleteTier(${t.id})">🗑️</button>
            </td>
          </tr>
        `).join('')}
      </tbody>
    </table>`;
}

function updateNilaiUnit() {
  const tipe = document.getElementById('tf_tipe').value;
  document.getElementById('nilaiUnit').textContent = tipe === 'flat' ? '(Rp)' : '(%)';
  const input = document.getElementById('tf_nilai');
  input.placeholder = tipe === 'flat' ? '5000' : '30';
}

function resetTierForm() {
  document.getElementById('tf_id').value     = '';
  document.getElementById('tf_nama').value   = '';
  document.getElementById('tf_jam').value    = '';
  document.getElementById('tf_tipe').value   = 'percent';
  document.getElementById('tf_nilai').value  = '';
  document.getElementById('tf_urutan').value = 0;
  document.getElementById('tf_active').value = 1;
  document.getElementById('tierFormTitle').textContent = '➕ Tambah Tier Baru';
  updateNilaiUnit();
}

function editTier(t) {
  document.getElementById('tf_id').value     = t.id;
  document.getElementById('tf_nama').value   = t.nama_tier;
  document.getElementById('tf_jam').value    = t.estimasi_jam;
  document.getElementById('tf_tipe').value   = t.tipe_biaya;
  document.getElementById('tf_nilai').value  = t.nilai_biaya;
  document.getElementById('tf_urutan').value = t.urutan;
  document.getElementById('tf_active').value = t.is_active;
  document.getElementById('tierFormTitle').textContent = '✏️ Edit Tier';
  updateNilaiUnit();
}

async function saveTier() {
  const lid = document.getElementById('tier_layanan_id').value;
  const payload = {
    layanan_id:   parseInt(lid),
    id:           document.getElementById('tf_id').value || null,
    nama_tier:    document.getElementById('tf_nama').value.trim(),
    estimasi_jam: parseInt(document.getElementById('tf_jam').value) || 0,
    tipe_biaya:   document.getElementById('tf_tipe').value,
    nilai_biaya:  parseFloat(document.getElementById('tf_nilai').value) || 0,
    is_active:    parseInt(document.getElementById('tf_active').value),
    urutan:       parseInt(document.getElementById('tf_urutan').value) || 0,
  };
  if (!payload.nama_tier || payload.estimasi_jam <= 0) {
    showToast('Nama tier & estimasi jam wajib diisi', 'error'); return;
  }
  try {
    const r = await fetch('?action=tier_save', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify(payload)
    });
    const d = await r.json();
    if (d.error) { showToast(d.error, 'error'); return; }
    showToast('Tier tersimpan', 'success');
    resetTierForm();
    await loadTiers(lid);
  } catch(e) {
    showToast('Gagal simpan: ' + e.message, 'error');
  }
}

async function deleteTier(id) {
  if (!confirm('Hapus tier ini? Aksi tidak bisa di-undo.')) return;
  try {
    const r = await fetch('?action=tier_delete', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify({id})
    });
    const d = await r.json();
    if (d.error) { showToast(d.error, 'error'); return; }
    showToast('Tier dihapus', 'success');
    await loadTiers(document.getElementById('tier_layanan_id').value);
  } catch(e) {
    showToast('Gagal hapus: ' + e.message, 'error');
  }
}

// ── Adjust harga (override) untuk layanan dari master ──
async function openAdjust(l){
  const base = parseFloat(l.harga_default) || 0;
  const pct  = parseFloat(l.override_max_pct) || 0;
  const min = base > 0 && pct > 0 ? Math.round(base * (1 - pct/100)) : 0;
  const max = base > 0 && pct > 0 ? Math.round(base * (1 + pct/100)) : 0;
  const rangeTxt = (min && max)
    ? `Rentang diizinkan: Rp ${min.toLocaleString('id-ID')} – Rp ${max.toLocaleString('id-ID')} (±${pct}%)`
    : `Default HQ: Rp ${base.toLocaleString('id-ID')}`;

  const harga = prompt(
    `Adjust harga "${l.nama}"\n${rangeTxt}\n\nHarga sekarang: Rp ${parseFloat(l.harga).toLocaleString('id-ID')}\nMasukkan harga baru:`,
    l.harga
  );
  if (harga === null) return;
  const val = parseFloat(harga);
  if (isNaN(val) || val < 0) { showToast('⚠️ Harga tidak valid','error'); return; }

  const r = await fetch('layanan.php?action=override_harga', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({ master_id: l.master_id, harga: val })
  });
  const d = await r.json();
  if (d.success) { showToast('✅ Harga di-adjust!','success'); loadLayanan(); }
  else showToast('❌ '+(d.error||'Gagal'),'error');
}

async function saveLayanan() {
  const nama  = document.getElementById('f_nama').value.trim();
  const harga = document.getElementById('f_harga').value;
  if (!nama)  { showToast('⚠️ Nama wajib diisi','error'); return; }
  if (!harga) { showToast('⚠️ Harga wajib diisi','error'); return; }

  const r = await fetch('layanan.php?action=save', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({
      id: document.getElementById('f_id').value, nama, harga,
      kategori: document.getElementById('f_kat').value,
      satuan:   document.getElementById('f_satuan').value,
      urutan:   document.getElementById('f_urutan').value,
      is_active:document.getElementById('f_active').value,
    })
  });
  const d = await r.json();
  if (d.success) { showToast('✅ Layanan disimpan!','success'); closeModal(); loadLayanan(); loadStats(); }
  else showToast('❌ '+(d.error||'Gagal'),'error');
}

async function toggleLayanan(id, active) {
  await fetch('layanan.php?action=toggle', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({id, is_active: active ? 1 : 0})
  });
  loadLayanan(); loadStats();
}

async function deleteLayanan(id) {
  if (!confirm('Nonaktifkan layanan ini?')) return;
  await fetch('layanan.php?action=delete', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({id})
  });
  showToast('✅ Layanan dinonaktifkan','success'); loadLayanan(); loadStats();
}

function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
</script>
</body>
</html>

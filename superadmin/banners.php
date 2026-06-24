<?php
// ══════════════════════════════════════════════════════
// superadmin/banners.php — Manage Dashboard Banner Carousel
// ══════════════════════════════════════════════════════
if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';

$activePage = 'banners';

$db = Database::get();
$action = $_GET['action'] ?? '';

if ($action) {
    header('Content-Type: application/json');

    if ($action === 'list') {
        try {
            $rows = $db->query(
                "SELECT * FROM saas_banners ORDER BY urutan ASC, id DESC"
            )->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['rows' => $rows]);
        } catch (Throwable $e) {
            echo json_encode(['error' => 'Tabel belum ada. Run banner_migration.sql', 'rows' => []]);
        }
        exit;
    }

    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        $id      = (int)($d['id'] ?? 0);
        $judul   = substr(trim((string)($d['judul'] ?? '')), 0, 100);
        $desk    = substr(trim((string)($d['deskripsi'] ?? '')), 0, 255);
        $ctaL    = substr(trim((string)($d['cta_label'] ?? '')), 0, 40);
        $ctaU    = substr(trim((string)($d['cta_url']   ?? '')), 0, 255);
        $bg      = substr(trim((string)($d['bg_gradient'] ?? 'linear-gradient(135deg,#0F7B6C,#10B981)')), 0, 80);
        $color   = substr(trim((string)($d['text_color'] ?? '#FFFFFF')), 0, 20);
        $icon    = substr(trim((string)($d['icon'] ?? '')), 0, 20);
        $aktif   = (int)($d['is_active'] ?? 1) ? 1 : 0;
        $urut    = (int)($d['urutan'] ?? 0);
        $starts  = !empty($d['starts_at']) ? $d['starts_at'] : null;
        $ends    = !empty($d['ends_at'])   ? $d['ends_at']   : null;

        if ($judul === '') { echo json_encode(['error'=>'Judul wajib']); exit; }

        try {
            if ($id > 0) {
                $st = $db->prepare("UPDATE saas_banners
                    SET judul=?, deskripsi=?, cta_label=?, cta_url=?, bg_gradient=?,
                        text_color=?, icon=?, is_active=?, urutan=?, starts_at=?, ends_at=?
                    WHERE id=?");
                $st->execute([$judul, $desk, $ctaL, $ctaU, $bg, $color, $icon, $aktif, $urut, $starts, $ends, $id]);
            } else {
                $st = $db->prepare("INSERT INTO saas_banners
                    (judul, deskripsi, cta_label, cta_url, bg_gradient,
                     text_color, icon, is_active, urutan, starts_at, ends_at)
                    VALUES (?,?,?,?,?, ?,?,?,?, ?,?)");
                $st->execute([$judul, $desk, $ctaL, $ctaU, $bg, $color, $icon, $aktif, $urut, $starts, $ends]);
            }
            echo json_encode(['success'=>true]);
        } catch (Throwable $e) {
            echo json_encode(['error'=>'Gagal: '.$e->getMessage()]);
        }
        exit;
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        $id = (int)($d['id'] ?? 0);
        $db->prepare("DELETE FROM saas_banners WHERE id=?")->execute([$id]);
        echo json_encode(['success'=>true]);
        exit;
    }

    exit;
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php saRenderHead('Dashboard Banners'); ?>
</head>
<body>
<div class="sa-layout">
<?php saRenderNav('banners', 'Dashboard Banners'); ?>

<div class="sa-content">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px">
    <div>
      <h1 style="margin:0;font-size:22px;color:var(--glow)">🎨 Dashboard Banners</h1>
      <p style="font-size:13px;color:var(--ash);margin-top:4px">Banner carousel yg tampil di dashboard semua tenant. Push fitur baru / promo / pengumuman.</p>
    </div>
    <button class="sa-btn sa-btn-primary" onclick="openModal()">+ Tambah Banner</button>
  </div>

  <div id="bannerList" style="min-height:200px">⏳ Memuat...</div>
</div>

<!-- Modal -->
<div class="sa-modal-overlay" id="bannerModal">
  <div class="sa-modal" style="max-width:680px">
    <h3 id="modalTitle">+ Tambah Banner</h3>
    <input type="hidden" id="f_id"/>

    <div class="form-row">
      <div class="fld" style="flex:2">
        <label>Judul <span style="color:red">*</span></label>
        <input type="text" id="f_judul" maxlength="100" placeholder="Member Tier System Baru!"/>
      </div>
      <div class="fld">
        <label>Icon Emoji</label>
        <input type="text" id="f_icon" maxlength="20" placeholder="⭐ 🎉 🚀"/>
      </div>
    </div>

    <div class="fld">
      <label>Deskripsi</label>
      <textarea id="f_deskripsi" maxlength="255" rows="2" placeholder="Bikin tier Gold/Silver/VIP untuk customer royal..."></textarea>
    </div>

    <div class="form-row">
      <div class="fld">
        <label>CTA Label</label>
        <input type="text" id="f_cta_label" maxlength="40" placeholder="Atur Tier →"/>
      </div>
      <div class="fld">
        <label>CTA URL</label>
        <input type="text" id="f_cta_url" maxlength="255" placeholder="/member"/>
      </div>
    </div>

    <div class="form-row">
      <div class="fld">
        <label>Background Gradient (CSS)</label>
        <input type="text" id="f_bg" maxlength="80" placeholder="linear-gradient(135deg,#0F7B6C,#10B981)"/>
      </div>
      <div class="fld">
        <label>Text Color</label>
        <input type="text" id="f_color" maxlength="20" placeholder="#FFFFFF"/>
      </div>
    </div>

    <div style="background:rgba(28,37,64,.5);border:1px solid var(--crease);border-radius:8px;padding:8px 12px;margin-bottom:14px;font-size:11px;color:var(--ash)">
      🎨 Quick gradient:
      <button class="chip" onclick="setBg('linear-gradient(135deg,#0F7B6C,#10B981)')" type="button">Teal</button>
      <button class="chip" onclick="setBg('linear-gradient(135deg,#7C3AED,#EC4899)')" type="button">Purple</button>
      <button class="chip" onclick="setBg('linear-gradient(135deg,#F59E0B,#EF4444)')" type="button">Sunset</button>
      <button class="chip" onclick="setBg('linear-gradient(135deg,#1E40AF,#0891B2)')" type="button">Ocean</button>
      <button class="chip" onclick="setBg('linear-gradient(135deg,#111827,#374151)')" type="button">Dark</button>
    </div>

    <div class="form-row">
      <div class="fld">
        <label>Mulai Tampil</label>
        <input type="datetime-local" id="f_starts"/>
      </div>
      <div class="fld">
        <label>Berhenti Tampil</label>
        <input type="datetime-local" id="f_ends"/>
      </div>
    </div>

    <div class="form-row">
      <div class="fld">
        <label>Urutan</label>
        <input type="number" id="f_urutan" value="0"/>
      </div>
      <div class="fld">
        <label>Status</label>
        <select id="f_active">
          <option value="1">✅ Aktif</option>
          <option value="0">⏸️ Nonaktif</option>
        </select>
      </div>
    </div>

    <!-- Preview -->
    <div style="margin-top:14px">
      <label style="font-size:12px;color:#6B7280;font-weight:600">Preview:</label>
      <div id="banPreview" style="border-radius:12px;padding:18px 24px;margin-top:6px;display:flex;align-items:center;gap:18px;background:linear-gradient(135deg,#0F7B6C,#10B981);color:var(--glow)">
        <span style="font-size:32px" id="prevIcon">⭐</span>
        <div style="flex:1">
          <div style="font-weight:800;font-size:16px" id="prevJudul">Judul Banner</div>
          <div style="font-size:13px;opacity:.9;margin-top:2px" id="prevDesk">Deskripsi singkat...</div>
        </div>
        <span id="prevCta" style="background:rgba(255,255,255,.2);padding:6px 14px;border-radius:100px;font-size:12px;font-weight:700">CTA →</span>
      </div>
    </div>

    <div class="sa-modal-footer">
      <button class="sa-btn sa-btn-outline" onclick="closeModal()">Batal</button>
      <button class="sa-btn sa-btn-primary" onclick="saveBanner()">💾 Simpan</button>
    </div>
  </div>
</div>

<style>
/* Quick gradient chip (di dlm modal) */
.chip{background:rgba(28,37,64,.5);border:1px solid var(--crease);border-radius:100px;padding:3px 10px;font-size:10px;color:var(--ash);cursor:pointer;margin-right:4px;font-family:inherit}
.chip:hover{border-color:var(--teal);color:var(--teal)}

/* Banner list card (di luar modal, di sa-content) */
.banner-card{border:1px solid var(--crease);border-radius:10px;padding:14px;margin-bottom:10px;display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:center;background:var(--paper)}
.b-prev{padding:14px 18px;border-radius:10px;flex:1;min-width:260px;display:flex;gap:14px;align-items:center}
.b-prev .icon{font-size:24px}
.b-prev .title{font-weight:700;font-size:14px}
.b-prev .desc{font-size:11px;opacity:.85}

/* Modal form layout */
.sa-modal .form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.sa-modal .fld{display:flex;flex-direction:column;gap:6px;margin-bottom:14px}
.sa-modal .fld label{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--ash)}
.sa-modal .fld input,.sa-modal .fld textarea,.sa-modal .fld select{width:100%;padding:10px 14px;background:rgba(28,37,64,.5);border:1px solid var(--crease);border-radius:8px;color:var(--glow);font-family:var(--font);font-size:14px;outline:none;transition:border-color .15s,box-shadow .15s}
.sa-modal .fld input:focus,.sa-modal .fld textarea:focus,.sa-modal .fld select:focus{border-color:var(--teal);box-shadow:0 0 0 3px var(--teal-faint)}
.sa-modal .fld textarea{resize:vertical;min-height:80px}
.sa-modal .fld select option{background:var(--slate);color:var(--glow)}
</style>

<script>
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

async function loadBanners() {
  const list = document.getElementById('bannerList');
  const r = await fetch('?action=list');
  const d = await r.json();
  if (d.error) { list.innerHTML = `<div style="background:rgba(244,63,94,.10);border:1px solid rgba(244,63,94,.40);padding:14px;border-radius:8px;color:#F43F5E">${esc(d.error)}</div>`; return; }
  const rows = d.rows || [];
  if (!rows.length) { list.innerHTML = '<div style="padding:40px;text-align:center;color:#9CA3AF">Belum ada banner. Klik "Tambah Banner" untuk mulai.</div>'; return; }
  list.innerHTML = rows.map(r => `
    <div class="banner-card">
      <div class="b-prev" style="background:${esc(r.bg_gradient)};color:${esc(r.text_color)}">
        <span class="icon">${esc(r.icon||'📌')}</span>
        <div style="flex:1">
          <div class="title">${esc(r.judul)} ${r.is_active==1?'':'<span style="font-size:10px;background:rgba(0,0,0,.2);padding:1px 6px;border-radius:100px">OFF</span>'}</div>
          <div class="desc">${esc(r.deskripsi||'')}</div>
        </div>
        ${r.cta_label?`<span style="background:rgba(255,255,255,.2);padding:4px 10px;border-radius:100px;font-size:10px;font-weight:700">${esc(r.cta_label)}</span>`:''}
      </div>
      <div style="display:flex;gap:6px;flex-shrink:0">
        <button class="sa-btn sa-btn-outline sa-btn-sm" onclick='openEdit(${JSON.stringify(r)})'>✏️</button>
        <button class="sa-btn sa-btn-danger sa-btn-sm" onclick="delBanner(${r.id})">🗑️</button>
      </div>
    </div>
  `).join('');
}

function openModal() {
  document.getElementById('modalTitle').textContent = '+ Tambah Banner';
  document.getElementById('f_id').value = '';
  document.getElementById('f_judul').value = '';
  document.getElementById('f_deskripsi').value = '';
  document.getElementById('f_icon').value = '⭐';
  document.getElementById('f_cta_label').value = '';
  document.getElementById('f_cta_url').value = '';
  document.getElementById('f_bg').value = 'linear-gradient(135deg,#0F7B6C,#10B981)';
  document.getElementById('f_color').value = '#FFFFFF';
  document.getElementById('f_starts').value = '';
  document.getElementById('f_ends').value = '';
  document.getElementById('f_urutan').value = 0;
  document.getElementById('f_active').value = 1;
  document.getElementById('bannerModal').classList.add('open');
  updatePreview();
  document.querySelectorAll('#bannerModal input, #bannerModal textarea').forEach(el => el.addEventListener('input', updatePreview));
}
function closeModal() { document.getElementById('bannerModal').classList.remove('open'); }
function setBg(g) { document.getElementById('f_bg').value = g; updatePreview(); }

function openEdit(r) {
  document.getElementById('modalTitle').textContent = '✏️ Edit Banner';
  document.getElementById('f_id').value = r.id;
  document.getElementById('f_judul').value = r.judul;
  document.getElementById('f_deskripsi').value = r.deskripsi || '';
  document.getElementById('f_icon').value = r.icon || '';
  document.getElementById('f_cta_label').value = r.cta_label || '';
  document.getElementById('f_cta_url').value = r.cta_url || '';
  document.getElementById('f_bg').value = r.bg_gradient;
  document.getElementById('f_color').value = r.text_color;
  document.getElementById('f_starts').value = (r.starts_at||'').replace(' ','T').substring(0,16);
  document.getElementById('f_ends').value = (r.ends_at||'').replace(' ','T').substring(0,16);
  document.getElementById('f_urutan').value = r.urutan;
  document.getElementById('f_active').value = r.is_active;
  document.getElementById('bannerModal').classList.add('open');
  updatePreview();
  document.querySelectorAll('#bannerModal input, #bannerModal textarea').forEach(el => el.addEventListener('input', updatePreview));
}

function updatePreview() {
  const p = document.getElementById('banPreview');
  p.style.background = document.getElementById('f_bg').value;
  p.style.color = document.getElementById('f_color').value;
  document.getElementById('prevIcon').textContent = document.getElementById('f_icon').value || '📌';
  document.getElementById('prevJudul').textContent = document.getElementById('f_judul').value || 'Judul Banner';
  document.getElementById('prevDesk').textContent = document.getElementById('f_deskripsi').value || 'Deskripsi singkat...';
  const ctaL = document.getElementById('f_cta_label').value;
  document.getElementById('prevCta').style.display = ctaL ? 'inline-block' : 'none';
  document.getElementById('prevCta').textContent = ctaL || '';
}

async function saveBanner() {
  const payload = {
    id: document.getElementById('f_id').value || null,
    judul: document.getElementById('f_judul').value,
    deskripsi: document.getElementById('f_deskripsi').value,
    icon: document.getElementById('f_icon').value,
    cta_label: document.getElementById('f_cta_label').value,
    cta_url: document.getElementById('f_cta_url').value,
    bg_gradient: document.getElementById('f_bg').value,
    text_color: document.getElementById('f_color').value,
    starts_at: document.getElementById('f_starts').value ? document.getElementById('f_starts').value.replace('T',' ') + ':00' : null,
    ends_at: document.getElementById('f_ends').value ? document.getElementById('f_ends').value.replace('T',' ') + ':00' : null,
    urutan: parseInt(document.getElementById('f_urutan').value)||0,
    is_active: parseInt(document.getElementById('f_active').value),
  };
  if (!payload.judul) { alert('Judul wajib'); return; }
  const r = await fetch('?action=save', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
  const d = await r.json();
  if (d.error) { alert(d.error); return; }
  closeModal();
  loadBanners();
}

async function delBanner(id) {
  if (!confirm('Hapus banner ini?')) return;
  const r = await fetch('?action=delete', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({id}) });
  const d = await r.json();
  if (d.error) { alert(d.error); return; }
  loadBanners();
}

document.addEventListener('DOMContentLoaded', loadBanners);
</script>

<?php saRenderNavClose(); ?>
</body>
</html>

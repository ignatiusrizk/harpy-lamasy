<?php
// ══════════════════════════════════════════════════════
// superadmin/announcements.php — CRUD Announcement & Changelog
// ══════════════════════════════════════════════════════
if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';

date_default_timezone_set('Asia/Jakarta');

$saId = (int)($_SESSION['superadmin_id'] ?? 0);
$db   = Database::get();
$action = $_GET['action'] ?? '';

if ($action) {
    header('Content-Type: application/json');

    // ── list ─────────────────────────────────────────
    if ($action === 'list') {
        $status = $_GET['status'] ?? '';
        $where  = $status ? 'WHERE status=?' : '';
        $params = $status ? [$status] : [];
        $rows   = $db->prepare(
            "SELECT a.*, sa.name AS author_name
             FROM saas_announcements a
             LEFT JOIN super_admins sa ON sa.id=a.superadmin_id
             $where
             ORDER BY a.is_pinned DESC, a.created_at DESC
             LIMIT 100"
        );
        $rows->execute($params);
        echo json_encode($rows->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // ── get ───────────────────────────────────────────
    if ($action === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        $s  = $db->prepare("SELECT * FROM saas_announcements WHERE id=? LIMIT 1");
        $s->execute([$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        echo $row ? json_encode($row) : json_encode(['error'=>'Tidak ditemukan.']);
        exit;
    }

    // ── stats ─────────────────────────────────────────
    if ($action === 'stats') {
        $pub   = (int)$db->query("SELECT COUNT(*) FROM saas_announcements WHERE status='published'")->fetchColumn();
        $draft = (int)$db->query("SELECT COUNT(*) FROM saas_announcements WHERE status='draft'")->fetchColumn();
        $views = (int)$db->query("SELECT COALESCE(SUM(total_views),0) FROM saas_announcements WHERE status='published'")->fetchColumn();
        $reads = (int)$db->query("SELECT COALESCE(SUM(total_reads),0) FROM saas_announcements WHERE status='published'")->fetchColumn();
        echo json_encode(compact('pub','draft','views','reads'));
        exit;
    }

    // ── POST actions ──────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        saVerifyCsrf();

        // ── save (create/update) ──────────────────────
        if ($action === 'save') {
            $id          = (int)($_POST['id'] ?? 0);
            $title       = substr(trim($_POST['title']   ?? ''), 0, 200);
            $content     = trim($_POST['content'] ?? '');
            $type        = in_array($_POST['type'] ?? '', ['fitur_baru','maintenance','penting','promo','umum']) ? $_POST['type'] : 'umum';
            $target      = in_array($_POST['target_audience'] ?? '', ['semua','trial','active','grace','chain']) ? $_POST['target_audience'] : 'semua';
            $isPinned    = (int)($_POST['is_pinned'] ?? 0);
            $showBanner  = (int)($_POST['show_as_banner'] ?? 0);
            $bannerColor = in_array($_POST['banner_color'] ?? '', ['blue','green','amber','red']) ? $_POST['banner_color'] : 'blue';
            $status      = in_array($_POST['status'] ?? '', ['draft','published','archived']) ? $_POST['status'] : 'draft';
            $publishedAt = $status === 'published' ? ($_POST['published_at'] ?: date('Y-m-d H:i:s')) : null;
            $expiresAt   = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;

            if (!$title || !$content) { echo json_encode(['error'=>'Judul dan konten wajib diisi.']); exit; }

            if ($id) {
                // Update
                $db->prepare(
                    "UPDATE saas_announcements SET
                       title=?, content=?, type=?, target_audience=?,
                       is_pinned=?, show_as_banner=?, banner_color=?,
                       status=?, published_at=?, expires_at=?, updated_at=NOW()
                     WHERE id=?"
                )->execute([$title,$content,$type,$target,$isPinned,$showBanner,$bannerColor,$status,$publishedAt,$expiresAt,$id]);
            } else {
                // Insert
                $db->prepare(
                    "INSERT INTO saas_announcements
                       (superadmin_id, title, content, type, target_audience,
                        is_pinned, show_as_banner, banner_color, status, published_at, expires_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)"
                )->execute([$saId,$title,$content,$type,$target,$isPinned,$showBanner,$bannerColor,$status,$publishedAt,$expiresAt]);
                $id = (int)$db->lastInsertId();
            }

            // If published: insert notification for matching tenants
            if ($status === 'published') {
                _notifyTenantsAnnouncement($db, $id, $target, $title, $type);
            }

            logSuperAdminAction('announcement_save', null, "Announcement #{$id}: {$title} [{$status}]");
            echo json_encode(['success'=>true, 'id'=>$id]);
            exit;
        }

        // ── delete ────────────────────────────────────
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare("DELETE FROM saas_announcement_reads WHERE announcement_id=?")->execute([$id]);
            $db->prepare("DELETE FROM saas_announcements WHERE id=?")->execute([$id]);
            echo json_encode(['success'=>true]);
            exit;
        }

        // ── toggle_pin ────────────────────────────────
        if ($action === 'toggle_pin') {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare("UPDATE saas_announcements SET is_pinned=IF(is_pinned=1,0,1), updated_at=NOW() WHERE id=?")->execute([$id]);
            echo json_encode(['success'=>true]);
            exit;
        }
    }

    echo json_encode(['error'=>'Action tidak dikenal.']);
    exit;
}

// ── Helper: notify matching tenants ──────────────────
function _notifyTenantsAnnouncement(PDO $db, int $annId, string $target, string $title, string $type): void
{
    // We just insert into hl_notifications (the existing notif table) for matching tenants
    // The bell will query this. For now just record the announcement as published.
    // Actual per-tenant bell injection done in components.php bell query.
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php saRenderHead('Announcement'); ?>
<style>
.ann-card {
  background:var(--navy-m);border:1px solid var(--crease-soft);
  border-radius:var(--r);padding:16px 18px;cursor:pointer;
  transition:border-color .15s;
}
.ann-card:hover { border-color:rgba(99,102,241,.35); }
.ann-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;margin-bottom:8px; }

.ann-type { display:inline-block;font-size:10px;font-weight:700;letter-spacing:.08em;
  text-transform:uppercase;padding:2px 7px;border-radius:20px;margin-bottom:8px; }
.ann-type-fitur_baru  { background:rgba(53,232,213,.08);color:var(--indigo); }
.ann-type-maintenance { background:rgba(244,63,94,.18); color:#F43F5E; }
.ann-type-penting     { background:rgba(245,158,11,.10);color:#F59E0B; }
.ann-type-promo       { background:rgba(132,204,22,.10);color:var(--sage); }
.ann-type-umum        { background:var(--slate-elev);color:var(--ash); }

.ann-status-published { color:var(--sage); }
.ann-status-draft     { color:var(--ash-dim); }
.ann-status-archived  { color:var(--ash-dim); }

/* ── Banner preview ─────────────────────────────── */
.banner-preview {
  border-radius:8px;padding:10px 14px;display:flex;align-items:center;
  justify-content:space-between;margin:12px 0;font-size:13px;font-weight:600;
}
.banner-preview.blue  { background:rgba(167,139,250,.10);color:#1D4ED8;border:1px solid rgba(167,139,250,.30); }
.banner-preview.green { background:rgba(132,204,22,.08);color:#166534;border:1px solid rgba(132,204,22,.30); }
.banner-preview.amber { background:rgba(245,158,11,.10);color:#F59E0B;border:1px solid rgba(245,158,11,.30); }
.banner-preview.red   { background:rgba(244,63,94,.10);color:#F43F5E;border:1px solid rgba(244,63,94,.30); }

/* ── Form modal ─────────────────────────────────── */
.ann-form-group { margin-bottom:14px; }
.ann-label { font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;
  color:var(--ash);display:block;margin-bottom:5px; }
.ann-form-row { display:grid;grid-template-columns:1fr 1fr;gap:12px; }

/* Toggle switch */
.sw2 { position:relative;display:inline-block;width:38px;height:20px; }
.sw2 input { opacity:0;width:0;height:0; }
.sw2-slider { position:absolute;inset:0;border-radius:20px;
  background:var(--slate-elev);cursor:pointer;transition:.2s; }
.sw2 input:checked+.sw2-slider { background:var(--sa); }
.sw2-slider::before { content:'';position:absolute;width:14px;height:14px;
  border-radius:50%;background:#fff;left:3px;top:3px;transition:.2s; }
.sw2 input:checked+.sw2-slider::before { transform:translateX(18px); }
</style>
</head>
<body>
<div class="sa-layout">
<?php saRenderNav('announcements', 'Announcement & Changelog'); ?>

<div class="sa-page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
  <div>
    <h1>📢 Announcement</h1>
    <p>Update fitur, maintenance, promo — tampil ke tenant via notif bell & banner dashboard</p>
  </div>
  <button class="sa-btn sa-btn-primary" onclick="openForm()">+ Buat Announcement</button>
</div>

<!-- Stats -->
<div class="sa-stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr));margin-bottom:24px;">
  <div class="sa-stat-card green">
    <div class="label">Published</div>
    <div class="value" id="s-pub" style="font-size:22px;">—</div>
    <span class="icon-bg">✅</span>
  </div>
  <div class="sa-stat-card yellow">
    <div class="label">Draft</div>
    <div class="value" id="s-draft" style="font-size:22px;">—</div>
    <span class="icon-bg">📝</span>
  </div>
  <div class="sa-stat-card blue">
    <div class="label">Total Views</div>
    <div class="value" id="s-views" style="font-size:18px;">—</div>
    <span class="icon-bg">👁</span>
  </div>
  <div class="sa-stat-card indigo">
    <div class="label">Total Reads</div>
    <div class="value" id="s-reads" style="font-size:18px;">—</div>
    <span class="icon-bg">📖</span>
  </div>
</div>

<!-- Filter + List -->
<div class="sa-card">
  <div class="sa-card-header" style="display:flex;align-items:center;gap:10px;">
    <h3>Semua Announcement</h3>
    <div style="margin-left:auto;display:flex;gap:8px;">
      <?php foreach([''=>'Semua','published'=>'Published','draft'=>'Draft','archived'=>'Archived'] as $v=>$l): ?>
      <button class="sa-btn sa-btn-sm sa-btn-outline" id="fBtn_<?= $v ?>"
              onclick="filterList('<?= $v ?>')"><?= $l ?></button>
      <?php endforeach; ?>
    </div>
  </div>
  <div style="padding:20px;" id="annList">
    <div style="text-align:center;padding:28px;color:var(--ash-dim);">Memuat…</div>
  </div>
</div>

<!-- ══ Form Modal ════════════════════════════════════ -->
<div class="sa-modal-overlay" id="formOverlay" onclick="closeForm(event)">
  <div class="sa-modal" style="max-width:640px;max-height:90vh;overflow-y:auto;">
    <div class="sa-modal-header">
      <span class="sa-modal-title" id="formTitle">Buat Announcement</span>
      <button class="sa-modal-close" onclick="closeForm()">✕</button>
    </div>
    <div class="sa-modal-body">
      <input type="hidden" id="fId"/>

      <div class="ann-form-row">
        <div class="ann-form-group">
          <label class="ann-label">Tipe</label>
          <select id="fType" class="sa-input">
            <option value="umum">🔔 Umum</option>
            <option value="fitur_baru">✨ Fitur Baru</option>
            <option value="maintenance">🔧 Maintenance</option>
            <option value="penting">⚠️ Penting</option>
            <option value="promo">🎁 Promo</option>
          </select>
        </div>
        <div class="ann-form-group">
          <label class="ann-label">Target Audience</label>
          <select id="fTarget" class="sa-input">
            <option value="semua">Semua Tenant</option>
            <option value="active">Active Only</option>
            <option value="trial">Trial Only</option>
            <option value="grace">Grace Period</option>
            <option value="chain">Chain (2+ outlet)</option>
          </select>
        </div>
      </div>

      <div class="ann-form-group">
        <label class="ann-label">Judul <span style="color:var(--red)">*</span></label>
        <input type="text" id="fTitle" class="sa-input" maxlength="200"
               placeholder="Judul singkat dan jelas" oninput="updatePreview()"/>
      </div>

      <div class="ann-form-group">
        <label class="ann-label">Konten <span style="color:var(--red)">*</span></label>
        <textarea id="fContent" class="sa-input" rows="6"
                  style="resize:vertical;min-height:100px;"
                  placeholder="Detail announcement. Markdown ringan: **tebal**, - list, [link](url)"
                  oninput="updatePreview()"></textarea>
        <div style="font-size:11px;color:var(--ash-dim);margin-top:3px;">
          Tip: **tebal**, *miring*, - list item, https://... untuk link
        </div>
      </div>

      <!-- Banner + Pin options -->
      <div style="background:rgba(10,15,31,.4);border:1px solid var(--crease-soft);border-radius:10px;padding:14px;margin-bottom:14px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
          <div>
            <div style="font-size:13px;font-weight:600;color:var(--white);">📌 Pin di atas</div>
            <div style="font-size:11px;color:var(--ash-dim);">Tampil paling atas di daftar</div>
          </div>
          <label class="sw2"><input type="checkbox" id="fPinned" onchange="updatePreview()"/><span class="sw2-slider"></span></label>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;">
          <div>
            <div style="font-size:13px;font-weight:600;color:var(--white);">🎨 Tampil sebagai Banner</div>
            <div style="font-size:11px;color:var(--ash-dim);">Banner dismissible di dashboard tenant</div>
          </div>
          <label class="sw2"><input type="checkbox" id="fBanner" onchange="toggleBannerOpts()"/><span class="sw2-slider"></span></label>
        </div>
        <div id="bannerOpts" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid var(--crease-soft);">
          <label class="ann-label">Warna Banner</label>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php foreach(['blue'=>'🔵 Blue','green'=>'🟢 Green','amber'=>'🟡 Amber','red'=>'🔴 Red'] as $v=>$l): ?>
            <label style="cursor:pointer;display:flex;align-items:center;gap:5px;font-size:13px;">
              <input type="radio" name="bannerColor" value="<?= $v ?>" <?= $v==='blue'?'checked':'' ?> onchange="updatePreview()"/>
              <?= $l ?>
            </label>
            <?php endforeach; ?>
          </div>
          <!-- Banner preview -->
          <div id="bannerPreview" style="margin-top:10px;"></div>
        </div>
      </div>

      <div class="ann-form-row">
        <div class="ann-form-group">
          <label class="ann-label">Status</label>
          <select id="fStatus" class="sa-input" onchange="togglePublishDate()">
            <option value="draft">Draft</option>
            <option value="published">Publish Sekarang</option>
            <option value="archived">Archived</option>
          </select>
        </div>
        <div class="ann-form-group" id="publishDateGroup" style="display:none;">
          <label class="ann-label">Tanggal Publish</label>
          <input type="datetime-local" id="fPublishedAt" class="sa-input"
                 value="<?= date('Y-m-d\TH:i') ?>"/>
        </div>
      </div>

      <div class="ann-form-group">
        <label class="ann-label">Expire (opsional)</label>
        <input type="datetime-local" id="fExpiresAt" class="sa-input" placeholder="Kosong = tidak expire"/>
        <div style="font-size:11px;color:var(--ash-dim);margin-top:3px;">Akan di-archive otomatis setelah tanggal ini</div>
      </div>

    </div>
    <div class="sa-modal-footer">
      <button class="sa-btn sa-btn-outline" onclick="closeForm()">Batal</button>
      <button class="sa-btn sa-btn-primary" onclick="saveAnn()" id="saveBtn">Simpan</button>
    </div>
  </div>
</div>

<?php saRenderNavClose(); ?>
<script>
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content||'';
const esc  = s=>{const d=document.createElement('div');d.textContent=s||'';return d.innerHTML;};
const fmtDT = s => s ? new Date(s).toLocaleString('id-ID',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '-';

const typeIcon  = {fitur_baru:'✨',maintenance:'🔧',penting:'⚠️',promo:'🎁',umum:'🔔'};
const typeLabel = {fitur_baru:'Fitur Baru',maintenance:'Maintenance',penting:'Penting',promo:'Promo',umum:'Umum'};
const targetLabel = {semua:'Semua',trial:'Trial',active:'Active',grace:'Grace',chain:'Chain'};

// ── Load stats ────────────────────────────────────────
fetch('announcements.php?action=stats',{headers:{'X-Requested-With':'XMLHttpRequest'}})
  .then(r=>r.json()).then(d=>{
    document.getElementById('s-pub').textContent   = d.pub;
    document.getElementById('s-draft').textContent = d.draft;
    document.getElementById('s-views').textContent = parseInt(d.views||0).toLocaleString('id-ID');
    document.getElementById('s-reads').textContent = parseInt(d.reads||0).toLocaleString('id-ID');
  });

// ── Load list ─────────────────────────────────────────
let _activeFilter = '';
function filterList(status) {
  _activeFilter = status;
  document.querySelectorAll('[id^=fBtn_]').forEach(b=>{
    b.classList.toggle('sa-btn-primary', b.id === 'fBtn_'+status);
    b.classList.toggle('sa-btn-outline', b.id !== 'fBtn_'+status);
  });
  loadList();
}

function loadList() {
  const p = _activeFilter ? `?action=list&status=${_activeFilter}` : '?action=list';
  fetch('announcements.php'+p,{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(rows=>{
      const el = document.getElementById('annList');
      if(!rows||!rows.length){
        el.innerHTML='<div style="text-align:center;padding:28px;color:var(--ash-dim);">Belum ada announcement.</div>';
        return;
      }
      el.innerHTML = '<div class="ann-grid">'+rows.map(r=>`
        <div class="ann-card" onclick="openForm(${r.id})">
          <span class="ann-type ann-type-${r.type}">${typeIcon[r.type]||'🔔'} ${typeLabel[r.type]||r.type}</span>
          ${r.is_pinned ? '<span style="float:right;font-size:14px;" title="Pinned">📌</span>' : ''}
          <div style="font-size:14px;font-weight:700;color:var(--white);margin-bottom:4px;line-height:1.3">${esc(r.title)}</div>
          <div style="font-size:11.5px;color:var(--ash-dim);margin-bottom:8px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">${esc(r.content)}</div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;font-size:11px;color:var(--ash);">
            <span class="ann-status-${r.status}">● ${r.status}</span>
            <span>👥 ${targetLabel[r.target_audience]||r.target_audience}</span>
            ${r.show_as_banner?'<span>🎨 Banner</span>':''}
            <span style="margin-left:auto;">👁 ${r.total_views||0} / 📖 ${r.total_reads||0}</span>
          </div>
          <div style="font-size:10.5px;color:var(--ash-dim);margin-top:6px;">
            ${r.published_at ? '📅 ' + fmtDT(r.published_at) : '📝 Draft'} &nbsp;·&nbsp; ${esc(r.author_name||'-')}
          </div>
          <div style="display:flex;gap:6px;margin-top:10px;" onclick="event.stopPropagation()">
            <button class="sa-btn sa-btn-sm sa-btn-outline" onclick="togglePin(${r.id})">
              ${r.is_pinned?'📌 Unpin':'📌 Pin'}
            </button>
            <button class="sa-btn sa-btn-sm sa-btn-outline" style="color:#F43F5E;border-color:rgba(239,68,68,.3);"
                    onclick="deleteAnn(${r.id},'${esc(r.title)}')">🗑</button>
          </div>
        </div>`).join('')+'</div>';
    });
}

// ── Form open/close ───────────────────────────────────
function openForm(id) {
  document.getElementById('fId').value       = id || '';
  document.getElementById('formTitle').textContent = id ? 'Edit Announcement' : 'Buat Announcement';
  if (!id) {
    // Reset form
    ['fTitle','fContent'].forEach(x=>document.getElementById(x).value='');
    document.getElementById('fType').value   = 'umum';
    document.getElementById('fTarget').value = 'semua';
    document.getElementById('fStatus').value = 'draft';
    document.getElementById('fPinned').checked = false;
    document.getElementById('fBanner').checked = false;
    document.getElementById('fExpiresAt').value = '';
    document.querySelector('[name=bannerColor][value=blue]').checked = true;
    toggleBannerOpts();
    togglePublishDate();
    updatePreview();
    document.getElementById('formOverlay').classList.add('open');
  } else {
    fetch(`announcements.php?action=get&id=${id}`,{headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(r=>r.json()).then(d=>{
        if(d.error){showToast(d.error,'error');return;}
        document.getElementById('fTitle').value   = d.title;
        document.getElementById('fContent').value = d.content;
        document.getElementById('fType').value    = d.type;
        document.getElementById('fTarget').value  = d.target_audience;
        document.getElementById('fStatus').value  = d.status;
        document.getElementById('fPinned').checked = !!parseInt(d.is_pinned);
        document.getElementById('fBanner').checked = !!parseInt(d.show_as_banner);
        document.getElementById('fExpiresAt').value = d.expires_at ? d.expires_at.slice(0,16) : '';
        if(d.published_at) document.getElementById('fPublishedAt').value = d.published_at.slice(0,16);
        const bc = document.querySelector(`[name=bannerColor][value=${d.banner_color||'blue'}]`);
        if(bc) bc.checked=true;
        toggleBannerOpts();
        togglePublishDate();
        updatePreview();
        document.getElementById('formOverlay').classList.add('open');
      });
  }
}
function closeForm(e) {
  if(e&&e.target!==document.getElementById('formOverlay'))return;
  document.getElementById('formOverlay').classList.remove('open');
}
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeForm();});

function toggleBannerOpts() {
  const on = document.getElementById('fBanner').checked;
  document.getElementById('bannerOpts').style.display = on ? 'block' : 'none';
  updatePreview();
}
function togglePublishDate() {
  const pub = document.getElementById('fStatus').value==='published';
  document.getElementById('publishDateGroup').style.display = pub?'block':'none';
}

function updatePreview() {
  const showBanner = document.getElementById('fBanner').checked;
  const color = document.querySelector('[name=bannerColor]:checked')?.value || 'blue';
  const title = document.getElementById('fTitle').value || 'Judul announcement…';
  const type  = document.getElementById('fType').value;
  const bp    = document.getElementById('bannerPreview');
  if(bp && showBanner) {
    bp.innerHTML = `<div class="banner-preview ${color}">${typeIcon[type]||'🔔'} ${esc(title)} <span style="opacity:.5;font-size:11px;cursor:pointer">✕ Dismiss</span></div>`;
  } else if(bp) {
    bp.innerHTML='';
  }
}

// ── Save ──────────────────────────────────────────────
function saveAnn() {
  const title   = document.getElementById('fTitle').value.trim();
  const content = document.getElementById('fContent').value.trim();
  if(!title||!content){showToast('Judul dan konten wajib diisi.','error');return;}

  const btn = document.getElementById('saveBtn');
  btn.disabled=true; btn.textContent='Menyimpan…';

  const body = new FormData();
  body.append('action','save'); body.append('_csrf',CSRF);
  body.append('id',document.getElementById('fId').value||'0');
  body.append('title',title); body.append('content',content);
  body.append('type',document.getElementById('fType').value);
  body.append('target_audience',document.getElementById('fTarget').value);
  body.append('is_pinned',document.getElementById('fPinned').checked?1:0);
  body.append('show_as_banner',document.getElementById('fBanner').checked?1:0);
  body.append('banner_color',document.querySelector('[name=bannerColor]:checked')?.value||'blue');
  body.append('status',document.getElementById('fStatus').value);
  body.append('published_at',document.getElementById('fPublishedAt').value||'');
  body.append('expires_at',document.getElementById('fExpiresAt').value||'');

  fetch('announcements.php',{method:'POST',body,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
      if(d.error){showToast(d.error,'error');return;}
      showToast('Announcement disimpan!','success');
      closeForm();
      loadList();
    }).finally(()=>{btn.disabled=false;btn.textContent='Simpan';});
}

// ── Toggle pin ────────────────────────────────────────
function togglePin(id) {
  const body=new FormData();body.append('action','toggle_pin');body.append('_csrf',CSRF);body.append('id',id);
  fetch('announcements.php',{method:'POST',body,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{if(d.success){loadList();}});
}

// ── Delete ────────────────────────────────────────────
async function deleteAnn(id, title) {
  if(!await lmConfirm(`Hapus announcement "${title}"?`))return;
  const body=new FormData();body.append('action','delete');body.append('_csrf',CSRF);body.append('id',id);
  fetch('announcements.php',{method:'POST',body,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{if(d.success){showToast('Dihapus.'); loadList();}});
}

// ── Toast ─────────────────────────────────────────────
function showToast(msg,type='success'){
  let t=document.getElementById('toast');
  if(!t){t=document.createElement('div');t.id='toast';t.className='sa-toast';document.body.appendChild(t);}
  t.textContent=msg;t.className=`sa-toast ${type} show`;
  clearTimeout(t._t);t._t=setTimeout(()=>t.className='sa-toast',3000);
}

filterList('');
</script>
</body>
</html>

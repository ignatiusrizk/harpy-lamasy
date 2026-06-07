<?php
// ══════════════════════════════════════════════════════
// outlet-settings.php — Outlet & Nota Settings
//
// Edit nota_prefix & nota_format per outlet via UI (no SQL).
// Live preview format → "HARPY-260607-001"
// ══════════════════════════════════════════════════════
$activePage = 'outlet-settings';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/NotaFormatter.php';
require_once __DIR__ . '/components.php';
$user = currentUser();
requirePermission('settings.roles');

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $tid = TenantResolver::id();
    $db  = Database::get();

    if ($action === 'list') {
        $hasNotaCols = true;
        try { $db->query("SELECT nota_prefix FROM outlets LIMIT 1"); }
        catch (Throwable) { $hasNotaCols = false; }

        $cols = "id, tenant_id, nama_outlet, slug, kota, telepon, status, is_main";
        if ($hasNotaCols) $cols .= ", nota_prefix, nota_format";
        $st = $db->prepare("SELECT $cols FROM outlets WHERE tenant_id=? ORDER BY is_main DESC, id ASC");
        $st->execute([$tid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        // Tambah preview format utk masing-masing
        foreach ($rows as &$r) {
            $prefix = $r['nota_prefix'] ?? 'HL-';
            $format = $r['nota_format'] ?? '{PREFIX}{YYMMDD}-{COUNTER:3}';
            $r['preview'] = NotaFormatter::previewFormat($prefix, $format,
                strtoupper(substr(preg_replace('/[^A-Za-z]/','',$r['nama_outlet']),0,3))
            );
        }
        echo json_encode(['rows' => $rows, 'has_cols' => $hasNotaCols]);
        exit;
    }

    if ($action === 'save' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d  = json_decode(file_get_contents('php://input'), true);
        $id = (int)($d['id'] ?? 0);
        $prefix = substr(trim((string)($d['nota_prefix'] ?? '')), 0, 20);
        $format = substr(trim((string)($d['nota_format'] ?? '')), 0, 60) ?: '{PREFIX}{YYMMDD}-{COUNTER:3}';

        // Validasi: format harus punya minimal {COUNTER} (kalau gak ada,
        // nota_no duplicate setiap hari)
        if (!str_contains($format, '{COUNTER')) {
            echo json_encode(['error'=>'Format wajib pakai {COUNTER} atau {COUNTER:N} supaya nomor unik per hari']);
            exit;
        }

        try {
            $st = $db->prepare("UPDATE outlets SET nota_prefix=?, nota_format=? WHERE id=? AND tenant_id=?");
            $st->execute([$prefix, $format, $id, $tid]);
            logAudit('update', 'outlet', "Update format nota outlet #$id: prefix=$prefix, format=$format");
            echo json_encode(['success'=>true]);
        } catch (Throwable $e) {
            echo json_encode(['error'=>'Gagal: '.$e->getMessage()]);
        }
        exit;
    }

    if ($action === 'preview' && $_SERVER['REQUEST_METHOD']==='POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        $prefix = (string)($d['nota_prefix'] ?? 'HL-');
        $format = (string)($d['nota_format'] ?? '{PREFIX}{YYMMDD}-{COUNTER:3}');
        $outletKode = (string)($d['outlet_kode'] ?? 'OUT');
        echo json_encode(['preview' => NotaFormatter::previewFormat($prefix, $format, $outletKode)]);
        exit;
    }

    exit;
}

renderHeader('🏪 Outlet & Nota Settings', [
    'breadcrumbs' => [
        ['label'=>'Dashboard','url'=>'/dashboard'],
        ['label'=>'Outlet & Nota'],
    ],
]);
?>

<div class="hl-container">
  <div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;padding:14px 18px;margin-bottom:18px;font-size:13.5px;color:#1E40AF;line-height:1.55">
    💡 <strong>Format Nomor Nota</strong> — atur prefix & template format nota per outlet. Default tiap outlet otomatis dapat prefix dari nama (mis. "Harpy Laundry" → <code>HARPY-</code>).
    Bisa di-customize untuk konsistensi branding (mis. <code>HL-2024-00001</code>, <code>JKT001/2026/06/</code>, dll).
  </div>

  <div id="outletList" style="min-height:150px">⏳ Memuat...</div>
</div>

<!-- Modal edit format -->
<div class="hl-modal-overlay" id="modalEdit">
  <div class="hl-modal" style="max-width:620px">
    <div class="hl-modal-header">
      <span class="hl-modal-title">✏️ Edit Format Nota — <span id="edOutletNama"></span></span>
      <button class="hl-modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="hl-modal-body">
      <input type="hidden" id="ed_id"/>
      <input type="hidden" id="ed_outlet_kode"/>

      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Prefix Nota</label>
          <input type="text" id="ed_prefix" class="hl-input" maxlength="20" oninput="livePreview()"
                 placeholder="HL-, HARPY-, JKT-, dll"/>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Template Format</label>
          <input type="text" id="ed_format" class="hl-input" maxlength="60" oninput="livePreview()"
                 placeholder="{PREFIX}{YYMMDD}-{COUNTER:3}"/>
        </div>
      </div>

      <!-- Live preview -->
      <div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:12px 16px;margin:8px 0 14px;font-size:14px;color:#166534;">
        Preview nota baru: <strong style="font-family:var(--mono,monospace);font-size:16px;color:#0F7B6C" id="livePreview">HL-260607-001</strong>
      </div>

      <!-- Quick templates -->
      <div style="font-size:12px;color:#6B7280;margin-bottom:6px;font-weight:600">⚡ Quick Template:</div>
      <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px">
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="applyTemplate('{PREFIX}{YYMMDD}-{COUNTER:3}')" type="button">Standar (HL-260607-001)</button>
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="applyTemplate('{PREFIX}{YYYYMMDD}-{COUNTER:4}')" type="button">Tahun Penuh (HL-20260607-0001)</button>
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="applyTemplate('{PREFIX}{OUTLET}-{YY}{MM}{DD}-{COUNTER:3}')" type="button">Multi-outlet (HL-HAR-260607-001)</button>
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="applyTemplate('{PREFIX}{COUNTER:5}')" type="button">Counter Only (HL-00001)</button>
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="applyTemplate('{PREFIX}{YYYY}/{MM}/{COUNTER:4}')" type="button">Slash (HL-2026/06/0001)</button>
      </div>

      <!-- Token reference -->
      <details style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;padding:8px 14px;font-size:12px;color:#4B5563">
        <summary style="cursor:pointer;font-weight:600;color:#374151">📖 Token yang Tersedia</summary>
        <table style="margin-top:8px;width:100%;font-size:12px;border-collapse:collapse">
          <tr><td style="padding:3px 8px;font-family:var(--mono,monospace)"><strong>{PREFIX}</strong></td><td>Isi dari "Prefix" di atas</td></tr>
          <tr><td style="padding:3px 8px;font-family:var(--mono,monospace)"><strong>{YYYY}</strong> / <strong>{YY}</strong></td><td>Tahun 4 digit (2026) / 2 digit (26)</td></tr>
          <tr><td style="padding:3px 8px;font-family:var(--mono,monospace)"><strong>{MM}</strong> / <strong>{DD}</strong></td><td>Bulan / tanggal 2 digit</td></tr>
          <tr><td style="padding:3px 8px;font-family:var(--mono,monospace)"><strong>{YYMMDD}</strong></td><td>Date 6 digit (260607)</td></tr>
          <tr><td style="padding:3px 8px;font-family:var(--mono,monospace)"><strong>{YYYYMMDD}</strong></td><td>Date 8 digit (20260607)</td></tr>
          <tr><td style="padding:3px 8px;font-family:var(--mono,monospace)"><strong>{OUTLET}</strong></td><td>3 huruf pertama nama outlet (HAR, NEN)</td></tr>
          <tr><td style="padding:3px 8px;font-family:var(--mono,monospace)"><strong>{COUNTER:N}</strong></td><td>Counter per hari, padded N digit (001, 0001)</td></tr>
        </table>
      </details>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeModal()">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="saveFormat()">💾 Simpan</button>
    </div>
  </div>
</div>

<?php renderToast(); ?>

<script>
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

async function loadOutlets() {
  const list = document.getElementById('outletList');
  list.innerHTML = '<div style="padding:20px;text-align:center;color:var(--gray)">⏳ Memuat...</div>';
  const r = await fetch('?action=list');
  const d = await r.json();
  const rows = d.rows || [];
  if (!d.has_cols) {
    list.innerHTML = '<div style="background:#FEF2F2;border:1px solid #FCA5A5;border-radius:8px;padding:14px 18px;color:#991B1B">⚠️ Kolom nota_prefix/nota_format belum ada di tabel outlets. Run migration <code>superadmin/sql/parfum_nota_format_migration.sql</code> dulu.</div>';
    return;
  }
  if (!rows.length) {
    list.innerHTML = '<div style="padding:40px;text-align:center;color:var(--gray)">Belum ada outlet</div>';
    return;
  }
  list.innerHTML = rows.map(r => `
    <div class="hl-card" style="margin-bottom:12px;padding:16px 18px;display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:center">
      <div style="flex:1;min-width:220px">
        <div style="font-weight:700;font-size:15px;color:#111827">
          🏪 ${esc(r.nama_outlet)}
          ${r.is_main == 1 ? '<span style="background:#F0FDF4;color:#166534;font-size:10px;font-weight:700;padding:2px 8px;border-radius:100px;margin-left:6px">UTAMA</span>' : ''}
          ${r.status === 'active' ? '' : `<span style="background:#FEF3C7;color:#92400E;font-size:10px;font-weight:700;padding:2px 8px;border-radius:100px;margin-left:6px">${esc(r.status)}</span>`}
        </div>
        <div style="font-size:12px;color:var(--gray);margin-top:2px">${esc(r.kota||'-')} · ${esc(r.telepon||'no phone')}</div>
        <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;font-size:12.5px">
          <span style="color:#6B7280">Prefix:</span>
          <code style="background:#F3F4F6;padding:2px 8px;border-radius:4px;font-weight:600;color:#0F7B6C">${esc(r.nota_prefix||'(kosong)')}</code>
          <span style="color:#6B7280">Format:</span>
          <code style="background:#F3F4F6;padding:2px 8px;border-radius:4px;font-size:11px;color:#4B5563">${esc(r.nota_format||'(default)')}</code>
        </div>
        <div style="margin-top:6px;font-size:12.5px;color:#6B7280">
          Preview nota: <strong style="font-family:var(--mono,monospace);color:#0F7B6C">${esc(r.preview||'-')}</strong>
        </div>
      </div>
      <button class="hl-btn hl-btn-outline" onclick='openEdit(${JSON.stringify(r)})'>✏️ Edit Format</button>
    </div>
  `).join('');
}

function openEdit(r) {
  document.getElementById('ed_id').value     = r.id;
  document.getElementById('ed_outlet_kode').value = (r.nama_outlet||'').replace(/[^A-Za-z]/g,'').toUpperCase().substring(0,3) || 'OUT';
  document.getElementById('edOutletNama').textContent = r.nama_outlet;
  document.getElementById('ed_prefix').value = r.nota_prefix || 'HL-';
  document.getElementById('ed_format').value = r.nota_format || '{PREFIX}{YYMMDD}-{COUNTER:3}';
  document.getElementById('modalEdit').classList.add('open');
  livePreview();
}
function closeModal() { document.getElementById('modalEdit').classList.remove('open'); }

function applyTemplate(format) {
  document.getElementById('ed_format').value = format;
  livePreview();
}

async function livePreview() {
  const prefix = document.getElementById('ed_prefix').value;
  const format = document.getElementById('ed_format').value;
  const ok = document.getElementById('ed_outlet_kode').value;
  try {
    const r = await fetch('?action=preview', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify({nota_prefix: prefix, nota_format: format, outlet_kode: ok})
    });
    const d = await r.json();
    document.getElementById('livePreview').textContent = d.preview || '-';
  } catch(e) {
    document.getElementById('livePreview').textContent = '(error)';
  }
}

async function saveFormat() {
  const id = document.getElementById('ed_id').value;
  const prefix = document.getElementById('ed_prefix').value;
  const format = document.getElementById('ed_format').value;
  const r = await fetch('?action=save', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({id, nota_prefix: prefix, nota_format: format})
  });
  const d = await r.json();
  if (d.error) { showToast(d.error, 'error'); return; }
  showToast('Format nota tersimpan', 'success');
  closeModal();
  loadOutlets();
}

document.addEventListener('DOMContentLoaded', loadOutlets);
</script>

<?php renderFooter(); ?>

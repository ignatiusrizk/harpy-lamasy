<?php
// ══════════════════════════════════════════════════════
// superadmin/audit.php — Audit Log Viewer
// ══════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';
require_once SA_ROOT . '/../core/SaPermission.php';

// Permission: audit.view (default ke owner, bisa di-grant ke role lain)
if (!SaPermission::has('audit.view') && !SaPermission::has('super_admins.manage')) {
    http_response_code(403);
    die('Tidak punya akses ke audit log.');
}

$activePage = 'audit';
$db = Database::get();
$action = $_GET['action'] ?? '';

// ─── AJAX handlers ───────────────────────────────────────
if ($action) {
    header('Content-Type: application/json');

    // Parse filters dari GET
    $tab    = $_GET['tab']    ?? 'sa';        // sa | tenant | notif
    $search = trim($_GET['search'] ?? '');
    $dateFrom = $_GET['from'] ?? '';
    $dateTo   = $_GET['to']   ?? '';
    $actionFilter = $_GET['action_filter'] ?? '';
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 50;
    $offset = ($page - 1) * $perPage;

    try {
        if ($action === 'list') {
            $where = [];
            $params = [];

            if ($tab === 'sa') {
                $sql = "SELECT l.id, l.superadmin_id, sa.username AS sa_username, sa.name AS sa_name,
                               l.action, l.target_tenant_id, t.namaBisnis AS tenant_name,
                               l.description, l.ip_address, l.created_at
                        FROM superadmin_logs l
                        LEFT JOIN super_admins sa ON sa.id = l.superadmin_id
                        LEFT JOIN saas_tenants t ON t.id = l.target_tenant_id";
                if ($search) {
                    $where[] = "(sa.username LIKE ? OR sa.name LIKE ? OR l.description LIKE ? OR l.action LIKE ?)";
                    $like = "%$search%";
                    array_push($params, $like, $like, $like, $like);
                }
                if ($actionFilter) {
                    $where[] = "l.action = ?";
                    $params[] = $actionFilter;
                }
                if ($dateFrom) { $where[] = "l.created_at >= ?"; $params[] = $dateFrom . ' 00:00:00'; }
                if ($dateTo)   { $where[] = "l.created_at <= ?"; $params[] = $dateTo . ' 23:59:59'; }
                if ($where) $sql .= " WHERE " . implode(' AND ', $where);
                $sql .= " ORDER BY l.id DESC LIMIT $perPage OFFSET $offset";
            }
            elseif ($tab === 'tenant') {
                $sql = "SELECT a.id, a.tenant_id, t.namaBisnis AS tenant_name,
                               a.user_nama, a.user_role, a.modul, a.aksi,
                               a.keterangan, a.ref_id, a.ip_address, a.outlet_id, a.created_at
                        FROM hl_audit_log a
                        LEFT JOIN saas_tenants t ON t.id = a.tenant_id";
                if ($search) {
                    $where[] = "(a.user_nama LIKE ? OR a.keterangan LIKE ? OR a.modul LIKE ? OR a.aksi LIKE ? OR t.namaBisnis LIKE ?)";
                    $like = "%$search%";
                    array_push($params, $like, $like, $like, $like, $like);
                }
                if ($actionFilter) {
                    $where[] = "a.aksi = ?";
                    $params[] = $actionFilter;
                }
                if ($dateFrom) { $where[] = "a.created_at >= ?"; $params[] = $dateFrom . ' 00:00:00'; }
                if ($dateTo)   { $where[] = "a.created_at <= ?"; $params[] = $dateTo . ' 23:59:59'; }
                if ($where) $sql .= " WHERE " . implode(' AND ', $where);
                $sql .= " ORDER BY a.id DESC LIMIT $perPage OFFSET $offset";
            }
            elseif ($tab === 'notif') {
                $sql = "SELECT id, event_type, ref_id, subject, recipients, sent_at AS created_at
                        FROM saas_sa_notif_log";
                if ($search) {
                    $where[] = "(subject LIKE ? OR event_type LIKE ? OR recipients LIKE ?)";
                    $like = "%$search%";
                    array_push($params, $like, $like, $like);
                }
                if ($actionFilter) {
                    $where[] = "event_type = ?";
                    $params[] = $actionFilter;
                }
                if ($dateFrom) { $where[] = "sent_at >= ?"; $params[] = $dateFrom . ' 00:00:00'; }
                if ($dateTo)   { $where[] = "sent_at <= ?"; $params[] = $dateTo . ' 23:59:59'; }
                if ($where) $sql .= " WHERE " . implode(' AND ', $where);
                $sql .= " ORDER BY id DESC LIMIT $perPage OFFSET $offset";
            }
            else {
                echo json_encode(['error' => 'Tab tidak valid']);
                exit;
            }

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['ok' => true, 'rows' => $rows, 'page' => $page, 'per_page' => $perPage]);
            exit;
        }

        // Action options (untuk dropdown filter)
        if ($action === 'actions') {
            $tab = $_GET['tab'] ?? 'sa';
            if ($tab === 'sa') {
                $list = $db->query("SELECT DISTINCT action FROM superadmin_logs WHERE action IS NOT NULL ORDER BY action")
                           ->fetchAll(PDO::FETCH_COLUMN);
            } elseif ($tab === 'tenant') {
                $list = $db->query("SELECT DISTINCT aksi FROM hl_audit_log WHERE aksi IS NOT NULL ORDER BY aksi LIMIT 100")
                           ->fetchAll(PDO::FETCH_COLUMN);
            } elseif ($tab === 'notif') {
                $list = $db->query("SELECT DISTINCT event_type FROM saas_sa_notif_log WHERE event_type IS NOT NULL ORDER BY event_type")
                           ->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $list = [];
            }
            echo json_encode(['ok' => true, 'actions' => $list]);
            exit;
        }

        // Export CSV
        if ($action === 'export') {
            $tab = $_GET['tab'] ?? 'sa';
            $where = [];
            $params = [];

            if ($tab === 'sa') {
                $sql = "SELECT sa.username, sa.name, l.action, l.target_tenant_id,
                               t.namaBisnis AS tenant_name, l.description, l.ip_address, l.created_at
                        FROM superadmin_logs l
                        LEFT JOIN super_admins sa ON sa.id = l.superadmin_id
                        LEFT JOIN saas_tenants t ON t.id = l.target_tenant_id";
                $headers = ['Username', 'Name', 'Action', 'Tenant ID', 'Tenant Name', 'Description', 'IP', 'Created'];
                if ($search) {
                    $where[] = "(sa.username LIKE ? OR sa.name LIKE ? OR l.description LIKE ? OR l.action LIKE ?)";
                    $like = "%$search%";
                    array_push($params, $like, $like, $like, $like);
                }
                if ($actionFilter) { $where[] = "l.action = ?"; $params[] = $actionFilter; }
                if ($dateFrom) { $where[] = "l.created_at >= ?"; $params[] = $dateFrom . ' 00:00:00'; }
                if ($dateTo)   { $where[] = "l.created_at <= ?"; $params[] = $dateTo . ' 23:59:59'; }
                if ($where) $sql .= " WHERE " . implode(' AND ', $where);
                $sql .= " ORDER BY l.id DESC LIMIT 5000";
            }
            elseif ($tab === 'tenant') {
                $sql = "SELECT t.namaBisnis AS tenant_name, a.user_nama, a.user_role, a.modul, a.aksi,
                               a.keterangan, a.ref_id, a.ip_address, a.outlet_id, a.created_at
                        FROM hl_audit_log a
                        LEFT JOIN saas_tenants t ON t.id = a.tenant_id";
                $headers = ['Tenant', 'User', 'Role', 'Modul', 'Aksi', 'Keterangan', 'Ref ID', 'IP', 'Outlet ID', 'Created'];
                if ($search) {
                    $where[] = "(a.user_nama LIKE ? OR a.keterangan LIKE ? OR a.modul LIKE ? OR a.aksi LIKE ? OR t.namaBisnis LIKE ?)";
                    $like = "%$search%";
                    array_push($params, $like, $like, $like, $like, $like);
                }
                if ($actionFilter) { $where[] = "a.aksi = ?"; $params[] = $actionFilter; }
                if ($dateFrom) { $where[] = "a.created_at >= ?"; $params[] = $dateFrom . ' 00:00:00'; }
                if ($dateTo)   { $where[] = "a.created_at <= ?"; $params[] = $dateTo . ' 23:59:59'; }
                if ($where) $sql .= " WHERE " . implode(' AND ', $where);
                $sql .= " ORDER BY a.id DESC LIMIT 5000";
            }
            else { // notif
                $sql = "SELECT event_type, ref_id, subject, recipients, sent_at FROM saas_sa_notif_log";
                $headers = ['Event Type', 'Ref ID', 'Subject', 'Recipients', 'Sent At'];
                if ($search) {
                    $where[] = "(subject LIKE ? OR event_type LIKE ? OR recipients LIKE ?)";
                    $like = "%$search%";
                    array_push($params, $like, $like, $like);
                }
                if ($actionFilter) { $where[] = "event_type = ?"; $params[] = $actionFilter; }
                if ($dateFrom) { $where[] = "sent_at >= ?"; $params[] = $dateFrom . ' 00:00:00'; }
                if ($dateTo)   { $where[] = "sent_at <= ?"; $params[] = $dateTo . ' 23:59:59'; }
                if ($where) $sql .= " WHERE " . implode(' AND ', $where);
                $sql .= " ORDER BY id DESC LIMIT 5000";
            }

            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            // Output CSV
            header_remove('Content-Type');
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="audit-' . $tab . '-' . date('Ymd-His') . '.csv"');
            $out = fopen('php://output', 'w');
            // UTF-8 BOM untuk Excel
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            while ($r = $stmt->fetch(PDO::FETCH_NUM)) {
                fputcsv($out, $r);
            }
            fclose($out);

            // Log export action
            logSuperAdminAction('audit_export', null, "Export audit log: $tab" .
                ($dateFrom ? " from=$dateFrom" : '') . ($dateTo ? " to=$dateTo" : '') .
                ($search ? " search=$search" : ''));
            exit;
        }
    } catch (Throwable $e) {
        echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
        exit;
    }
}

// ─── Stats untuk header cards ────────────────────────────
$stats = [];
try {
    $stats['sa_today']     = (int)$db->query("SELECT COUNT(*) FROM superadmin_logs WHERE DATE(created_at)=CURDATE()")->fetchColumn();
    $stats['sa_total']     = (int)$db->query("SELECT COUNT(*) FROM superadmin_logs")->fetchColumn();
    $stats['tenant_today'] = (int)$db->query("SELECT COUNT(*) FROM hl_audit_log WHERE DATE(created_at)=CURDATE()")->fetchColumn();
    $stats['tenant_total'] = (int)$db->query("SELECT COUNT(*) FROM hl_audit_log")->fetchColumn();
    $stats['notif_today']  = (int)$db->query("SELECT COUNT(*) FROM saas_sa_notif_log WHERE DATE(sent_at)=CURDATE()")->fetchColumn();
    $stats['notif_total']  = (int)$db->query("SELECT COUNT(*) FROM saas_sa_notif_log")->fetchColumn();
} catch (Throwable $e) {
    $stats = array_fill_keys(['sa_today','sa_total','tenant_today','tenant_total','notif_today','notif_total'], 0);
}
?>
<?php saRenderHead('Audit Log'); ?>
<body>
<div class="sa-layout">
<?php saRenderNav('audit', 'Audit Log'); ?>

<div class="sa-content">
  <div class="sa-page-header">
    <h1>Audit Log</h1>
    <p>Tracking semua action SA, tenant, dan notifikasi platform. Filter, search, export untuk compliance + debugging.</p>
  </div>

  <!-- Stats -->
  <div class="sa-stats-grid">
    <div class="sa-stat-card thread-indigo">
      <div class="label">SA Actions · Hari Ini</div>
      <div class="value"><?= number_format($stats['sa_today']) ?></div>
      <div class="sub">total: <?= number_format($stats['sa_total']) ?></div>
    </div>
    <div class="sa-stat-card thread-sage">
      <div class="label">Tenant Audit · Hari Ini</div>
      <div class="value"><?= number_format($stats['tenant_today']) ?></div>
      <div class="sub">total: <?= number_format($stats['tenant_total']) ?></div>
    </div>
    <div class="sa-stat-card thread-amber">
      <div class="label">SA Notif Sent · Hari Ini</div>
      <div class="value"><?= number_format($stats['notif_today']) ?></div>
      <div class="sub">total: <?= number_format($stats['notif_total']) ?></div>
    </div>
  </div>

  <!-- Tabs -->
  <div class="sa-tabs">
    <button class="sa-tab active" data-tab="sa" onclick="switchAuditTab('sa', this)">🔧 SA Actions</button>
    <button class="sa-tab" data-tab="tenant" onclick="switchAuditTab('tenant', this)">🏪 Tenant Audit</button>
    <button class="sa-tab" data-tab="notif" onclick="switchAuditTab('notif', this)">📬 SA Notifications</button>
  </div>

  <!-- Card with filter + table -->
  <div class="sa-card">
    <div class="sa-filter-bar" style="gap:10px">
      <input type="search" id="f_search" placeholder="Search…" style="min-width:200px;flex:1"/>
      <input type="date" id="f_from" title="Dari tanggal"/>
      <input type="date" id="f_to" title="Sampai tanggal"/>
      <select id="f_action" style="min-width:160px">
        <option value="">Semua action</option>
      </select>
      <button class="sa-btn sa-btn-outline sa-btn-sm" onclick="resetFilters()">Reset</button>
      <button class="sa-btn sa-btn-primary sa-btn-sm" onclick="applyFilters()">🔍 Apply</button>
      <button class="sa-btn sa-btn-outline sa-btn-sm" onclick="exportCsv()" title="Export CSV (max 5000 baris)">📥 Export CSV</button>
    </div>

    <div class="sa-table-wrap">
      <table class="sa-table" id="auditTable">
        <thead id="auditThead"></thead>
        <tbody id="auditBody">
          <tr><td colspan="10" style="text-align:center;padding:32px;color:var(--ash)">Memuat…</td></tr>
        </tbody>
      </table>
    </div>

    <div class="sa-pagination">
      <button class="sa-btn sa-btn-outline sa-btn-sm" id="prevBtn" onclick="changePage(-1)">‹ Prev</button>
      <span style="font-size:12px;color:var(--ash);padding:0 12px" id="pageInfo">Halaman 1</span>
      <button class="sa-btn sa-btn-outline sa-btn-sm" id="nextBtn" onclick="changePage(1)">Next ›</button>
    </div>
  </div>
</div>

<?php saRenderNavClose(); ?>

<script>
let curTab = 'sa';
let curPage = 1;

const headers = {
  sa: ['Waktu', 'SA', 'Action', 'Tenant', 'Detail', 'IP'],
  tenant: ['Waktu', 'Tenant', 'User', 'Modul · Aksi', 'Keterangan', 'IP'],
  notif: ['Waktu', 'Event', 'Subject', 'Recipients', 'Ref ID'],
};

function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}

function fmtDate(s) {
  if (!s) return '—';
  const d = new Date(s.replace(' ', 'T'));
  if (isNaN(d)) return s;
  return d.toLocaleString('id-ID', { day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' });
}

function switchAuditTab(tab, btn) {
  document.querySelectorAll('.sa-tab').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  curTab = tab;
  curPage = 1;
  loadActions();
  loadData();
}

function renderHead() {
  const cols = headers[curTab];
  document.getElementById('auditThead').innerHTML =
    '<tr>' + cols.map(c => `<th>${c}</th>`).join('') + '</tr>';
}

async function loadActions() {
  const r = await fetch(`?action=actions&tab=${curTab}`).then(r => r.json());
  const sel = document.getElementById('f_action');
  sel.innerHTML = '<option value="">Semua action</option>' +
    (r.actions || []).map(a => `<option value="${escapeHtml(a)}">${escapeHtml(a)}</option>`).join('');
}

function getFilters() {
  return {
    search: document.getElementById('f_search').value.trim(),
    from: document.getElementById('f_from').value,
    to: document.getElementById('f_to').value,
    action_filter: document.getElementById('f_action').value,
  };
}

async function loadData() {
  renderHead();
  const tbody = document.getElementById('auditBody');
  tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:32px;color:var(--ash)">Memuat…</td></tr>';
  const f = getFilters();
  const qs = new URLSearchParams({
    action: 'list',
    tab: curTab,
    page: curPage,
    search: f.search,
    from: f.from,
    to: f.to,
    action_filter: f.action_filter,
  });
  const r = await fetch('?' + qs).then(r => r.json());
  if (r.error) {
    tbody.innerHTML = `<tr><td colspan="10" style="text-align:center;padding:32px;color:var(--coral)">${escapeHtml(r.error)}</td></tr>`;
    return;
  }
  if (!r.rows || !r.rows.length) {
    tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:32px;color:var(--ash)">Tidak ada data.</td></tr>';
    return;
  }

  tbody.innerHTML = r.rows.map(row => {
    if (curTab === 'sa') {
      return `<tr>
        <td style="white-space:nowrap;font-family:var(--mono);font-size:12px;color:var(--ash)">${fmtDate(row.created_at)}</td>
        <td>
          <div style="font-weight:600;color:var(--glow)">${escapeHtml(row.sa_name || '—')}</div>
          <div style="font-size:11px;color:var(--ash)">@${escapeHtml(row.sa_username || '?')}</div>
        </td>
        <td><code style="color:var(--teal);font-size:12px">${escapeHtml(row.action || '—')}</code></td>
        <td style="font-size:12px">${row.target_tenant_id ? `#${row.target_tenant_id} ${escapeHtml(row.tenant_name || '')}` : '—'}</td>
        <td style="font-size:12.5px;color:var(--ink-soft);max-width:380px">${escapeHtml(row.description || '—')}</td>
        <td style="font-family:var(--mono);font-size:11px;color:var(--ash-dim)">${escapeHtml(row.ip_address || '—')}</td>
      </tr>`;
    }
    if (curTab === 'tenant') {
      return `<tr>
        <td style="white-space:nowrap;font-family:var(--mono);font-size:12px;color:var(--ash)">${fmtDate(row.created_at)}</td>
        <td style="font-size:12px">${row.tenant_id ? `#${row.tenant_id} ${escapeHtml(row.tenant_name || '')}` : '—'}</td>
        <td>
          <div style="font-weight:600;color:var(--glow);font-size:13px">${escapeHtml(row.user_nama || '—')}</div>
          <div style="font-size:10.5px;color:var(--ash)">${escapeHtml(row.user_role || '')}</div>
        </td>
        <td>
          <div style="color:var(--teal);font-size:11px;font-family:var(--mono)">${escapeHtml(row.modul || '')}</div>
          <div style="font-size:12px;font-weight:600">${escapeHtml(row.aksi || '—')}</div>
        </td>
        <td style="font-size:12.5px;color:var(--ink-soft);max-width:340px">${escapeHtml(row.keterangan || '—')}</td>
        <td style="font-family:var(--mono);font-size:11px;color:var(--ash-dim)">${escapeHtml(row.ip_address || '—')}</td>
      </tr>`;
    }
    if (curTab === 'notif') {
      let recipientsDisplay = '—';
      try {
        const parsed = JSON.parse(row.recipients || '[]');
        if (Array.isArray(parsed) && parsed.length) {
          recipientsDisplay = parsed.slice(0, 3).map(r => escapeHtml(r)).join(', ') +
            (parsed.length > 3 ? ` <span style="color:var(--ash-dim)">+${parsed.length - 3} lagi</span>` : '');
        } else if (typeof row.recipients === 'string') {
          recipientsDisplay = escapeHtml(row.recipients.slice(0, 80));
        }
      } catch (_) {
        recipientsDisplay = escapeHtml((row.recipients || '').slice(0, 80));
      }
      return `<tr>
        <td style="white-space:nowrap;font-family:var(--mono);font-size:12px;color:var(--ash)">${fmtDate(row.created_at)}</td>
        <td><code style="color:var(--ai-violet);font-size:12px">${escapeHtml(row.event_type || '—')}</code></td>
        <td style="font-size:12.5px;color:var(--ink-soft);max-width:300px">${escapeHtml(row.subject || '—')}</td>
        <td style="font-size:12px;color:var(--ash)">${recipientsDisplay}</td>
        <td style="font-family:var(--mono);font-size:11px;color:var(--ash-dim)">${escapeHtml(row.ref_id || '—')}</td>
      </tr>`;
    }
  }).join('');

  document.getElementById('pageInfo').textContent = `Halaman ${curPage} · ${r.rows.length} baris`;
  document.getElementById('prevBtn').classList.toggle('disabled', curPage <= 1);
  document.getElementById('nextBtn').classList.toggle('disabled', r.rows.length < r.per_page);
}

function applyFilters() {
  curPage = 1;
  loadData();
}

function resetFilters() {
  document.getElementById('f_search').value = '';
  document.getElementById('f_from').value = '';
  document.getElementById('f_to').value = '';
  document.getElementById('f_action').value = '';
  curPage = 1;
  loadData();
}

function changePage(delta) {
  curPage = Math.max(1, curPage + delta);
  loadData();
}

function exportCsv() {
  const f = getFilters();
  const qs = new URLSearchParams({
    action: 'export',
    tab: curTab,
    search: f.search,
    from: f.from,
    to: f.to,
    action_filter: f.action_filter,
  });
  window.location.href = '?' + qs;
}

// Enter di search → apply
document.getElementById('f_search').addEventListener('keydown', e => {
  if (e.key === 'Enter') applyFilters();
});

// Init
loadActions();
loadData();
</script>
</body>
</html>

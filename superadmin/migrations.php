<?php
// ══════════════════════════════════════════════════════
// superadmin/migrations.php — Migration Jobs Overview
//
// Tampilan semua migration job di semua tenant.
// Filter: status, entity_type, assisted, tanggal.
// Stats: total, assisted revenue, success rate.
// ══════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';
require_once SA_ROOT . '/../core/MigrationImporter.php';
require_once SA_ROOT . '/../core/AIMigrationMapper.php';

date_default_timezone_set('Asia/Jakarta');

$db     = Database::get();
$action = $_GET['action'] ?? '';

// ── API ACTIONS ───────────────────────────────────────
if ($action) {
    header('Content-Type: application/json');

    // ── Stats ─────────────────────────────────────────
    if ($action === 'stats') {
        $total = (int)$db->query("SELECT COUNT(*) FROM hl_migration_jobs")->fetchColumn();
        $assisted = (int)$db->query("SELECT COUNT(*) FROM hl_migration_jobs WHERE is_assisted=1")->fetchColumn();
        $self     = $total - $assisted;

        $revQ = $db->query("SELECT COALESCE(SUM(assisted_fee),0) FROM hl_migration_jobs WHERE is_assisted=1 AND assisted_paid=1");
        $revenue = (int)$revQ->fetchColumn();

        $successQ = $db->query("
            SELECT AVG(success_rows / NULLIF(total_rows,0)) * 100
            FROM hl_migration_jobs WHERE status IN ('completed','partial') AND total_rows > 0
        ");
        $successRate = round((float)$successQ->fetchColumn(), 1);

        $inProgress = (int)$db->query("SELECT COUNT(*) FROM hl_migration_jobs WHERE status IN ('uploaded','ai_mapping','mapped','importing')")->fetchColumn();

        echo json_encode([
            'total'        => $total,
            'assisted'     => $assisted,
            'self'         => $self,
            'revenue'      => $revenue,
            'success_rate' => $successRate,
            'in_progress'  => $inProgress,
        ]);
        exit;
    }

    // ── List jobs ─────────────────────────────────────
    if ($action === 'list') {
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 25;
        $offset = ($page - 1) * $limit;

        $where  = ['1=1'];
        $params = [];

        if ($_GET['status'] ?? '') { $where[] = 'j.status = ?'; $params[] = $_GET['status']; }
        if ($_GET['entity'] ?? '') { $where[] = 'j.entity_type = ?'; $params[] = $_GET['entity']; }
        if ($_GET['assisted'] ?? '') { $where[] = 'j.is_assisted = ?'; $params[] = (int)$_GET['assisted']; }
        if ($_GET['tenant_id'] ?? '') { $where[] = 'j.tenant_id = ?'; $params[] = (int)$_GET['tenant_id']; }

        $whereStr = implode(' AND ', $where);

        $countQ = $db->prepare("SELECT COUNT(*) FROM hl_migration_jobs j WHERE $whereStr");
        $countQ->execute($params);
        $total = (int)$countQ->fetchColumn();

        $rowsQ = $db->prepare("
            SELECT j.*,
                   t.nama_outlet, t.nama_perusahaan, t.slug,
                   u.nama AS user_nama,
                   sa.name AS admin_nama
            FROM hl_migration_jobs j
            LEFT JOIN tenants t      ON t.id = j.tenant_id
            LEFT JOIN hl_users u     ON u.id = j.imported_by_user AND u.tenant_id = j.tenant_id
            LEFT JOIN super_admins sa ON sa.id = j.imported_by_admin
            WHERE $whereStr
            ORDER BY j.created_at DESC
            LIMIT $limit OFFSET $offset
        ");
        $rowsQ->execute($params);
        echo json_encode([
            'total' => $total,
            'pages' => (int)ceil($total / $limit),
            'page'  => $page,
            'rows'  => $rowsQ->fetchAll(PDO::FETCH_ASSOC),
        ]);
        exit;
    }

    // ── Assisted: create job untuk tenant ────────────
    if ($action === 'create_assisted' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        saVerifyCsrf();
        $admin    = saCurrentAdmin();
        $tenantId = (int)($_POST['tenant_id'] ?? 0);
        $outletId = (int)($_POST['outlet_id']  ?? 0);
        $entity   = $_POST['entity_type'] ?? '';
        $allowed  = ['layanan','pelanggan','karyawan','transaksi','poin_pelanggan'];

        if (!in_array($entity, $allowed, true)) { echo json_encode(['error'=>'Entitas tidak valid.']); exit; }
        if (!$tenantId || !$outletId) { echo json_encode(['error'=>'Tenant/outlet tidak valid.']); exit; }

        if (empty($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['error'=>'File tidak berhasil diupload.']); exit;
        }

        $file = $_FILES['import_file'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv','xlsx','xls'], true)) {
            echo json_encode(['error'=>'Format tidak valid. Gunakan CSV/Excel.']); exit;
        }

        // Simpan file
        $uploadDir = SA_ROOT . '/../uploads/migrations/' . $tenantId . '/';
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
        $safeName = 'assisted_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $filePath = $uploadDir . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            echo json_encode(['error'=>'Gagal menyimpan file.']); exit;
        }

        // Buat job
        $db->prepare("
            INSERT INTO hl_migration_jobs
              (tenant_id, outlet_id, imported_by_admin, is_assisted, entity_type,
               file_name, file_path, file_size, file_type,
               assisted_fee, status)
            VALUES (?,?,?,1,?, ?,?,?,?, 200000, 'uploaded')
        ")->execute([
            $tenantId, $outletId, $admin['id'], $entity,
            $file['name'], $filePath, $file['size'], $ext,
        ]);
        $jobId = (int)$db->lastInsertId();

        // Parse + simpan headers
        try {
            $all     = MigrationImporter::parseFile($filePath, $ext);
            $headers = !empty($all) ? array_keys($all[0]) : [];
            $db->prepare("UPDATE hl_migration_jobs SET raw_headers=?, total_rows=? WHERE id=?")
               ->execute([json_encode($headers), count($all), $jobId]);
        } catch (Throwable $e) {
            echo json_encode(['error'=>'Parse file gagal: '.$e->getMessage()]); exit;
        }

        logSuperAdminAction('migration_assisted_create', $tenantId,
            "Buat assisted migration job #{$jobId} ({$entity}) untuk tenant #{$tenantId}");

        echo json_encode(['ok'=>true, 'job_id'=>$jobId]);
        exit;
    }

    // ── AI Map untuk assisted job ─────────────────────
    if ($action === 'ai_map_assisted' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        saVerifyCsrf();
        $jobId = (int)($_POST['job_id'] ?? 0);
        $jobQ  = $db->prepare("SELECT * FROM hl_migration_jobs WHERE id=? AND is_assisted=1 LIMIT 1");
        $jobQ->execute([$jobId]);
        $job = $jobQ->fetch(PDO::FETCH_ASSOC);
        if (!$job) { echo json_encode(['error'=>'Job tidak ditemukan.']); exit; }

        $headers    = json_decode($job['raw_headers'], true) ?: [];
        $sampleRows = [];
        try {
            $all        = MigrationImporter::parseFile($job['file_path'], $job['file_type']);
            $sampleRows = array_slice($all, 0, 5);
        } catch (Throwable) {}

        // Assisted: gratis (tidak deduct coin)
        $mapResult = AIMigrationMapper::map($job['entity_type'], $headers, $sampleRows);

        $db->prepare("UPDATE hl_migration_jobs SET ai_mapping=?, status='mapped' WHERE id=?")
           ->execute([json_encode($mapResult), $jobId]);

        echo json_encode(array_merge($mapResult, ['job_id'=>$jobId]));
        exit;
    }

    // ── Confirm + import assisted ─────────────────────
    if ($action === 'import_assisted' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        saVerifyCsrf();
        $admin     = saCurrentAdmin();
        $jobId     = (int)($_POST['job_id'] ?? 0);
        $customMap = $_POST['custom_mapping'] ?? null;

        $jobQ = $db->prepare("SELECT * FROM hl_migration_jobs WHERE id=? AND is_assisted=1 LIMIT 1");
        $jobQ->execute([$jobId]);
        $job  = $jobQ->fetch(PDO::FETCH_ASSOC);
        if (!$job || !in_array($job['status'],['mapped','uploaded'],true)) {
            echo json_encode(['error'=>'Job tidak siap untuk diimport.']); exit;
        }

        // Apply custom mapping jika ada
        if ($customMap) {
            $cd = json_decode($customMap, true);
            if (is_array($cd)) {
                $ex = json_decode($job['ai_mapping'], true);
                $ex['mapping'] = $cd;
                $db->prepare("UPDATE hl_migration_jobs SET ai_mapping=? WHERE id=?")
                   ->execute([json_encode($ex), $jobId]);
            }
        }

        $db->prepare("UPDATE hl_migration_jobs SET mapping_confirmed=1, mapping_confirmed_at=NOW() WHERE id=?")
           ->execute([$jobId]);

        $result = MigrationImporter::process($jobId);
        if (isset($result['error'])) { echo json_encode(['error'=>$result['error']]); exit; }

        // Charge billing ke tenant
        try {
            $db->prepare("
                INSERT INTO saas_manual_payments
                  (tenant_id, superadmin_id, type, nominal_dibayar,
                   coin_dikreditkan, status, tanggal_bayar, catatan)
                VALUES (?,?,  'assisted_migration', 200000,
                        0, 'confirmed', CURDATE(), ?)
            ")->execute([
                $job['tenant_id'], $admin['id'],
                "Assisted migration: {$job['entity_type']} (job #{$jobId})",
            ]);
            $db->prepare("UPDATE hl_migration_jobs SET assisted_paid=1 WHERE id=?")->execute([$jobId]);
        } catch (Throwable $e) {
            error_log('[migrations] billing error: '.$e->getMessage());
        }

        logSuperAdminAction('migration_assisted_done', $job['tenant_id'],
            "Assisted migration #{$jobId} selesai: {$result['success']} berhasil / {$result['failed']} gagal");

        echo json_encode(['ok'=>true,'job_id'=>$jobId] + $result);
        exit;
    }

    // ── Error report download ─────────────────────────
    if ($action === 'error_report') {
        $jobId = (int)($_GET['job_id'] ?? 0);
        $jobQ  = $db->prepare("SELECT * FROM hl_migration_jobs WHERE id=? LIMIT 1");
        $jobQ->execute([$jobId]);
        $job = $jobQ->fetch(PDO::FETCH_ASSOC);
        if (!$job) { http_response_code(404); echo 'Not found'; exit; }

        $errors = json_decode($job['error_log'], true) ?: [];
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="error_migration_' . $jobId . '.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Baris', 'Error', 'Data']);
        foreach ($errors as $e) {
            $ds = is_array($e['data'] ?? null) ? implode(' | ', $e['data']) : ($e['data'] ?? '');
            fputcsv($out, [$e['baris'] ?? '-', $e['error'] ?? '-', $ds]);
        }
        fclose($out); exit;
    }

    echo json_encode(['error'=>'Unknown action']); exit;
}

// ── PAGE RENDER ───────────────────────────────────────
// Get outlets for assisted migration form
$allTenants = $db->query("SELECT id, nama_outlet, nama_perusahaan FROM tenants WHERE status IN ('active','trial') ORDER BY nama_outlet LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php saRenderHead('Migration Jobs'); ?>
<style>
.prog-bar { height: 6px; background: rgba(255,255,255,.07); border-radius: 3px; overflow: hidden; }
.prog-fill { height: 100%; background: linear-gradient(90deg, #35E8D5, #10B981); border-radius: 3px; transition: width .4s; }
</style>
</head>
<body>
<?php saRenderNav('migrations', 'Migration Jobs'); ?>

<div class="sa-page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div>
    <h1>📦 Migration Jobs</h1>
    <p>Semua proses import data tenant — self-service & assisted</p>
  </div>
  <button class="sa-btn sa-btn-primary" onclick="openAssistedModal()">
    ＋ Assisted Migration
  </button>
</div>

<!-- Stats -->
<div class="sa-stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr));margin-bottom:24px;" id="statsGrid">
  <div class="sa-stat-card indigo"><div class="label">Total Migration</div><div class="value" id="stTotal">—</div><span class="icon-bg">📦</span></div>
  <div class="sa-stat-card green"><div class="label">Assisted</div><div class="value" id="stAssisted">—</div><span class="icon-bg">👨‍💼</span></div>
  <div class="sa-stat-card blue"><div class="label">Self-Service</div><div class="value" id="stSelf">—</div><span class="icon-bg">🤝</span></div>
  <div class="sa-stat-card green"><div class="label">Revenue Assisted</div><div class="value" id="stRevenue" style="font-size:16px;">—</div><span class="icon-bg">💰</span></div>
  <div class="sa-stat-card"><div class="label">Rata-rata Success</div><div class="value" id="stSuccessRate">—</div><span class="icon-bg">✅</span></div>
  <div class="sa-stat-card yellow"><div class="label">Sedang Berjalan</div><div class="value" id="stInProgress">—</div><span class="icon-bg">⏳</span></div>
</div>

<!-- Filter -->
<div class="sa-card">
  <div class="sa-filter-bar">
    <select id="fStatus" onchange="loadJobs(1)">
      <option value="">Semua Status</option>
      <option value="completed">Completed</option>
      <option value="partial">Partial</option>
      <option value="failed">Failed</option>
      <option value="importing">Importing</option>
      <option value="mapped">Mapped</option>
      <option value="uploaded">Uploaded</option>
    </select>
    <select id="fEntity" onchange="loadJobs(1)">
      <option value="">Semua Entitas</option>
      <option value="layanan">Layanan</option>
      <option value="pelanggan">Pelanggan</option>
      <option value="karyawan">Karyawan</option>
      <option value="transaksi">Transaksi</option>
      <option value="poin_pelanggan">Poin</option>
    </select>
    <select id="fAssisted" onchange="loadJobs(1)">
      <option value="">Self + Assisted</option>
      <option value="1">Assisted Only</option>
      <option value="0">Self-Service Only</option>
    </select>
    <button class="sa-btn sa-btn-outline sa-btn-sm" onclick="loadJobs(1)">⟳ Refresh</button>
  </div>

  <div class="sa-table-wrap">
    <table class="sa-table">
      <thead>
        <tr>
          <th>ID</th><th>Tenant</th><th>Entitas</th><th>File</th>
          <th>Progress</th><th>Tipe</th><th>Status</th><th>Waktu</th><th>Aksi</th>
        </tr>
      </thead>
      <tbody id="jobsTbody">
        <tr><td colspan="9" style="text-align:center;color:rgba(255,255,255,.3);padding:32px;">Memuat...</td></tr>
      </tbody>
    </table>
  </div>
  <div id="jobsPagination" class="sa-pagination"></div>
</div>

<!-- Assisted Migration Modal -->
<div class="sa-modal-overlay" id="assistedModal">
  <div class="sa-modal" style="max-width:560px;">
    <h3>➕ Assisted Migration Baru</h3>
    <p style="font-size:12.5px;color:rgba(255,255,255,.5);margin-bottom:20px;">
      Upload file data atas nama tenant. Biaya <strong>Rp 200.000</strong> akan dicharge ke billing tenant setelah import berhasil.
    </p>

    <div id="assistedStep1">
      <div class="form-group" style="margin-bottom:12px;">
        <label>Tenant</label>
        <select id="asTenantId" class="sa-modal input" style="width:100%;" onchange="loadTenantOutlets()">
          <option value="">— Pilih Tenant —</option>
          <?php foreach ($allTenants as $t): ?>
          <option value="<?= $t['id'] ?>" data-nama="<?= htmlspecialchars($t['nama_perusahaan'] ?: $t['nama_outlet']) ?>">
            <?= htmlspecialchars($t['nama_perusahaan'] ?: $t['nama_outlet']) ?> (#<?= $t['id'] ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin-bottom:12px;">
        <label>Outlet</label>
        <select id="asOutletId" style="width:100%;padding:10px;background:rgba(255,255,255,.06);border:1.5px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-family:var(--font);font-size:13px;outline:none;">
          <option value="">— Pilih outlet —</option>
        </select>
      </div>
      <div class="form-group" style="margin-bottom:12px;">
        <label>Jenis Data</label>
        <select id="asEntity" style="width:100%;padding:10px;background:rgba(255,255,255,.06);border:1.5px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-family:var(--font);font-size:13px;outline:none;">
          <option value="pelanggan">Pelanggan</option>
          <option value="layanan">Layanan</option>
          <option value="karyawan">Karyawan</option>
          <option value="transaksi">Transaksi Histori</option>
          <option value="poin_pelanggan">Poin Pelanggan</option>
        </select>
      </div>
      <div class="form-group" style="margin-bottom:16px;">
        <label>File (CSV / Excel)</label>
        <input type="file" id="asFile" accept=".csv,.xlsx,.xls"
               style="width:100%;padding:10px;background:rgba(255,255,255,.06);border:1.5px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-size:13px;">
      </div>
      <div class="sa-modal-footer">
        <button class="sa-btn sa-btn-outline" onclick="closeModal('assistedModal')">Batal</button>
        <button class="sa-btn sa-btn-primary" onclick="submitAssistedUpload()">📤 Upload & Analisa AI →</button>
      </div>
    </div>

    <!-- Step 2: Konfirmasi mapping -->
    <div id="assistedStep2" style="display:none;">
      <div style="font-size:12px;color:rgba(255,255,255,.4);margin-bottom:12px;" id="asMapMeta"></div>
      <div style="overflow-x:auto;max-height:300px;overflow-y:auto;">
        <table class="sa-table" id="asMapTable" style="font-size:12px;">
          <thead><tr><th>Kolom File</th><th>→ Target</th><th>Conf.</th></tr></thead>
          <tbody id="asMapTbody"></tbody>
        </table>
      </div>
      <div style="margin-top:12px;font-size:12px;color:rgba(255,255,255,.4);" id="asMapWarning"></div>
      <div class="sa-modal-footer" style="margin-top:16px;">
        <button class="sa-btn sa-btn-outline" onclick="document.getElementById('assistedStep1').style.display='';document.getElementById('assistedStep2').style.display='none';">← Ubah File</button>
        <button class="sa-btn sa-btn-primary" onclick="submitAssistedImport()" id="asImportBtn">▶ Mulai Import (Rp 200.000)</button>
      </div>
    </div>

    <!-- Step 3: Hasil -->
    <div id="assistedStep3" style="display:none;">
      <div id="asResultContent"></div>
      <div class="sa-modal-footer" style="margin-top:16px;">
        <button class="sa-btn sa-btn-outline" onclick="closeModal('assistedModal');loadJobs(1);loadStats();">Tutup & Refresh</button>
        <button class="sa-btn sa-btn-primary" onclick="resetAssistedModal()">Import Lagi</button>
      </div>
    </div>
  </div>
</div>

<?php saRenderNavClose(); ?>

<script>
const CSRF = saCsrf();
let asCurrentJobId = null;
let asCurrentMap   = null;
const ENTITY_LABELS = {
    layanan:'Layanan', pelanggan:'Pelanggan', karyawan:'Karyawan',
    transaksi:'Transaksi', poin_pelanggan:'Poin Pelanggan'
};
const STATUS_ICONS = { completed:'✅', partial:'⚠️', failed:'❌', importing:'⏳', mapped:'🔍', uploaded:'📁', ai_mapping:'🤖' };

// ── Stats ──────────────────────────────────────────
async function loadStats() {
    try {
        const d = await (await fetch('?action=stats')).json();
        document.getElementById('stTotal').textContent = d.total;
        document.getElementById('stAssisted').textContent = d.assisted;
        document.getElementById('stSelf').textContent = d.self;
        document.getElementById('stRevenue').textContent = 'Rp ' + d.revenue.toLocaleString('id-ID');
        document.getElementById('stSuccessRate').textContent = d.success_rate + '%';
        document.getElementById('stInProgress').textContent = d.in_progress;
    } catch {}
}

// ── Job list ──────────────────────────────────────
async function loadJobs(page) {
    const tbody = document.getElementById('jobsTbody');
    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:rgba(255,255,255,.3);padding:24px;">Memuat...</td></tr>';

    const status   = document.getElementById('fStatus').value;
    const entity   = document.getElementById('fEntity').value;
    const assisted = document.getElementById('fAssisted').value;

    try {
        const resp = await fetch(`?action=list&page=${page}&status=${status}&entity=${entity}&assisted=${assisted}`);
        const d    = await resp.json();

        if (!d.rows.length) {
            tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:rgba(255,255,255,.3);padding:24px;">Tidak ada data.</td></tr>';
            document.getElementById('jobsPagination').innerHTML = '';
            return;
        }

        tbody.innerHTML = d.rows.map(r => {
            const tenantName = r.nama_perusahaan || r.nama_outlet || r.slug || '#'+r.tenant_id;
            const pct = r.total_rows > 0 ? (r.success_rows / r.total_rows * 100).toFixed(0) : 0;
            const progColor = pct >= 95 ? '#10B981' : pct >= 60 ? '#F59E0B' : '#EF4444';

            return `<tr>
                <td style="font-family:var(--mono);font-size:11px;color:rgba(255,255,255,.4);">#${r.id}</td>
                <td>
                    <a href="/superadmin/client_detail.php?id=${r.tenant_id}" style="color:var(--sa);font-size:13px;font-weight:600;text-decoration:none;">${esc(tenantName)}</a>
                    <div style="font-size:11px;color:rgba(255,255,255,.3);">outlet #${r.outlet_id}</div>
                </td>
                <td><span class="sa-badge sa-badge-indigo" style="font-size:10px;">${esc(ENTITY_LABELS[r.entity_type]||r.entity_type)}</span></td>
                <td style="font-size:11.5px;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(r.file_name)}">${esc(r.file_name)}</td>
                <td style="min-width:120px;">
                    <div style="font-size:11.5px;margin-bottom:4px;color:rgba(255,255,255,.6);">${r.success_rows}/${r.total_rows} baris</div>
                    <div class="prog-bar"><div class="prog-fill" style="width:${pct}%;background:${progColor};"></div></div>
                </td>
                <td style="font-size:11.5px;">
                    ${r.is_assisted ? '<span class="sa-badge sa-badge-yellow" style="font-size:10px;">👨‍💼 Assisted</span>' : '<span class="sa-badge sa-badge-indigo" style="font-size:10px;">🤝 Self</span>'}
                </td>
                <td>${STATUS_ICONS[r.status]||'?'} <span style="font-size:11.5px;">${r.status}</span></td>
                <td style="font-size:11px;color:rgba(255,255,255,.3);">${r.created_at?.substring(0,16)||'—'}</td>
                <td>
                    ${(r.status==='failed'||r.status==='partial') && r.failed_rows>0 ?
                        `<a href="?action=error_report&job_id=${r.id}" class="sa-btn sa-btn-sm sa-btn-danger">⬇ Error</a>` : ''}
                </td>
            </tr>`;
        }).join('');

        // Pagination
        const pg = document.getElementById('jobsPagination');
        if (d.pages <= 1) { pg.innerHTML=''; return; }
        let html = `<span style="font-size:12px;color:rgba(255,255,255,.35);">Hal ${d.page}/${d.pages}</span>`;
        if (d.page > 1) html += `<button class="sa-btn sa-btn-sm sa-btn-outline" onclick="loadJobs(${d.page-1})">← Prev</button>`;
        if (d.page < d.pages) html += `<button class="sa-btn sa-btn-sm sa-btn-outline" onclick="loadJobs(${d.page+1})">Next →</button>`;
        pg.innerHTML = html;

    } catch(e) {
        tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;color:#FCA5A5;padding:24px;">Gagal: ${esc(e.message)}</td></tr>`;
    }
}

// ── Tenant outlets ────────────────────────────────
async function loadTenantOutlets() {
    const tenantId = document.getElementById('asTenantId').value;
    const sel = document.getElementById('asOutletId');
    sel.innerHTML = '<option value="">Memuat...</option>';
    if (!tenantId) { sel.innerHTML = '<option value="">— Pilih outlet —</option>'; return; }

    try {
        const resp = await fetch(`/superadmin/client_detail.php?action=get_outlets&id=${tenantId}`);
        const d    = await resp.json();
        sel.innerHTML = d.map(o => `<option value="${o.id}">${o.nama_outlet} (#${o.id})</option>`).join('');
    } catch {
        sel.innerHTML = '<option value="">Gagal load outlets</option>';
    }
}

// ── Assisted upload ───────────────────────────────
async function submitAssistedUpload() {
    const tenantId = document.getElementById('asTenantId').value;
    const outletId = document.getElementById('asOutletId').value;
    const entity   = document.getElementById('asEntity').value;
    const file     = document.getElementById('asFile').files[0];

    if (!tenantId || !outletId) { saShowToast('Pilih tenant dan outlet.', 'error'); return; }
    if (!file) { saShowToast('Upload file terlebih dahulu.', 'error'); return; }

    const fd = new FormData();
    fd.append('tenant_id', tenantId);
    fd.append('outlet_id', outletId);
    fd.append('entity_type', entity);
    fd.append('import_file', file);
    fd.append('_csrf', CSRF);

    try {
        const resp = await fetch('?action=create_assisted', { method:'POST', body:fd });
        const d    = await resp.json();
        if (d.error) { saShowToast(d.error, 'error'); return; }

        asCurrentJobId = d.job_id;
        saShowToast('File diupload. Menjalankan AI mapping...', 'info');

        // AI mapping
        const fd2 = new FormData();
        fd2.append('job_id', asCurrentJobId);
        fd2.append('_csrf', CSRF);
        const resp2 = await fetch('?action=ai_map_assisted', { method:'POST', body:fd2 });
        const d2    = await resp2.json();
        if (d2.error) { saShowToast(d2.error, 'error'); return; }

        asCurrentMap = d2;
        renderAssistedMapping(d2);
        document.getElementById('assistedStep1').style.display = 'none';
        document.getElementById('assistedStep2').style.display = '';

    } catch(e) {
        saShowToast('Error: ' + e.message, 'error');
    }
}

function renderAssistedMapping(d) {
    const conf = ((d.overall_confidence||0) * 100).toFixed(0);
    document.getElementById('asMapMeta').innerHTML =
        `Sumber: <strong>${d.source_system_detected||'—'}</strong> &nbsp;|&nbsp; Confidence: <strong>${conf}%</strong>
        ${d.from_cache ? '&nbsp;|&nbsp; <span style="color:#6EE7B7;">✓ Cache (gratis)</span>' : ''}`;

    if (d.missing_required?.length) {
        document.getElementById('asMapWarning').innerHTML =
            `⚠️ Field wajib belum terpetakan: <strong>${d.missing_required.join(', ')}</strong>`;
    }

    const tbody = document.getElementById('asMapTbody');
    const entity = document.getElementById('asEntity').value;
    const fields = { layanan:['nama','harga','satuan','kategori'], pelanggan:['nama','telepon','alamat','tipe_bayar','catatan'], karyawan:['nama','telepon','role','gaji_pokok'], transaksi:['nama_pelanggan','telepon','nama_layanan','total','tanggal'], poin_pelanggan:['telepon','nama_pelanggan','saldo_poin'] };
    const targetFields = fields[entity] || [];
    const mapDef = d.mapping || {};

    tbody.innerHTML = Object.entries(mapDef).map(([src, info]) => {
        const cf   = ((info.confidence||0)*100).toFixed(0);
        const opts = ['', ...targetFields].map(f => `<option value="${f}" ${f===(info.target_field||'')?'selected':''}>${f||'(skip)'}</option>`).join('');
        return `<tr>
            <td style="font-family:var(--mono);font-size:11px;">${esc(src)}</td>
            <td><select style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:6px;color:#fff;padding:4px 8px;font-size:11.5px;" data-src="${esc(src)}">${opts}</select></td>
            <td style="font-size:11px;color:rgba(255,255,255,.35);">${cf}%</td>
        </tr>`;
    }).join('');
}

async function submitAssistedImport() {
    const btn = document.getElementById('asImportBtn');
    btn.disabled = true; btn.textContent = '⏳ Mengimport...';

    // Collect custom mapping
    const customMap = {};
    document.querySelectorAll('#asMapTbody select').forEach(sel => {
        const src    = sel.dataset.src;
        const target = sel.value;
        const orig   = asCurrentMap?.mapping?.[src] || {};
        customMap[src] = { target_field: target||null, action: target?'map':'skip', transform_note: orig.transform_note||'', confidence: orig.confidence||0.8 };
    });

    const fd = new FormData();
    fd.append('job_id', asCurrentJobId);
    fd.append('custom_mapping', JSON.stringify(customMap));
    fd.append('_csrf', CSRF);

    try {
        const resp = await fetch('?action=import_assisted', { method:'POST', body:fd });
        const d    = await resp.json();
        if (d.error) { saShowToast(d.error, 'error'); btn.disabled=false; btn.textContent='▶ Mulai Import (Rp 200.000)'; return; }

        document.getElementById('assistedStep2').style.display = 'none';
        document.getElementById('assistedStep3').style.display = '';
        document.getElementById('asResultContent').innerHTML = `
            <div style="display:flex;gap:20px;text-align:center;margin-bottom:16px;">
                <div style="flex:1;"><div style="font-size:28px;font-weight:800;color:#6EE7B7;">${d.success}</div><div style="font-size:11.5px;color:rgba(255,255,255,.4);">✓ Berhasil</div></div>
                <div style="flex:1;"><div style="font-size:28px;font-weight:800;color:#FCA5A5;">${d.failed}</div><div style="font-size:11.5px;color:rgba(255,255,255,.4);">✗ Gagal</div></div>
                <div style="flex:1;"><div style="font-size:28px;font-weight:800;color:#D1D5DB;">${d.skipped}</div><div style="font-size:11.5px;color:rgba(255,255,255,.4);">⊘ Skip</div></div>
            </div>
            <div style="background:rgba(16,185,129,.07);border:1px solid rgba(16,185,129,.2);border-radius:8px;padding:10px 14px;font-size:12.5px;color:#6EE7B7;">
                ✅ Billing <strong>Rp 200.000</strong> sudah dicatat ke saas_manual_payments.
            </div>
            ${d.failed > 0 ? `<div style="margin-top:10px;"><a href="?action=error_report&job_id=${d.job_id}" class="sa-btn sa-btn-sm sa-btn-danger">⬇ Download Error Report</a></div>` : ''}
        `;

    } catch(e) {
        saShowToast('Error: ' + e.message, 'error');
        btn.disabled=false; btn.textContent='▶ Mulai Import (Rp 200.000)';
    }
}

function openAssistedModal() {
    resetAssistedModal();
    document.getElementById('assistedModal').classList.add('open');
}

function resetAssistedModal() {
    asCurrentJobId = null; asCurrentMap = null;
    document.getElementById('assistedStep1').style.display = '';
    document.getElementById('assistedStep2').style.display = 'none';
    document.getElementById('assistedStep3').style.display = 'none';
    document.getElementById('asTenantId').value = '';
    document.getElementById('asOutletId').innerHTML = '<option value="">— Pilih outlet —</option>';
    document.getElementById('asEntity').value = 'pelanggan';
    document.getElementById('asFile').value = '';
    document.getElementById('asMapWarning').innerHTML = '';
    const btn = document.getElementById('asImportBtn');
    btn.disabled=false; btn.textContent='▶ Mulai Import (Rp 200.000)';
}

function closeModal(id) { document.getElementById(id).classList.remove('open'); }
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

document.addEventListener('DOMContentLoaded', () => { loadStats(); loadJobs(1); });
</script>
</body>
</html>

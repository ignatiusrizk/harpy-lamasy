<?php
// ══════════════════════════════════════════════════════
// superadmin/settings.php — Platform Settings
// Tab: Maintenance | Demo | ToS Versions
// ══════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';

$db    = Database::get();
$admin = saCurrentAdmin();
$csrf  = saGetCsrf();

// ── Helper: baca/tulis platform config ────────────────
function getConfig(string $key, string $default = ''): string {
    global $db;
    $r = $db->prepare("SELECT config_value FROM saas_platform_config WHERE config_key=? LIMIT 1");
    $r->execute([$key]);
    $val = $r->fetchColumn();
    return $val !== false ? (string)$val : $default;
}
function setConfig(string $key, ?string $value): void {
    global $db, $admin;
    $db->prepare(
        "INSERT INTO saas_platform_config (config_key, config_value, updated_by)
         VALUES (?,?,?) ON DUPLICATE KEY UPDATE config_value=?, updated_by=?"
    )->execute([$key, $value, $admin['id'] ?? null, $value, $admin['id'] ?? null]);
}

// ── Cache file path ────────────────────────────────────
$cacheFile = dirname(__DIR__) . '/storage/maintenance.json';

// ── AJAX Actions ───────────────────────────────────────
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? '';
    saVerifyCsrf(false); // lempar exception jika invalid

    // ── Maintenance: toggle ──────────────────────────
    if ($action === 'maintenance_toggle') {
        $enable  = (int)($_POST['enable']  ?? 0);
        $message = trim($_POST['message']  ?? '') ?: 'Sistem sedang dalam pemeliharaan terjadwal.';
        $until   = trim($_POST['until']    ?? '') ?: null;
        $myIP    = $_SERVER['REMOTE_ADDR'] ?? '';

        // Ambil whitelist IPs (selalu sertakan IP superadmin saat ini)
        $existingIPs = json_decode(getConfig('maintenance_whitelist_ips', '[]'), true) ?: [];
        if ($myIP && !in_array($myIP, $existingIPs, true)) {
            $existingIPs[] = $myIP;
        }

        setConfig('maintenance_mode',    $enable ? '1' : '0');
        setConfig('maintenance_message', $message);
        setConfig('maintenance_until',   $until);
        setConfig('maintenance_by',      (string)($admin['id'] ?? 0));
        setConfig('maintenance_whitelist_ips', json_encode($existingIPs));

        // Tulis / hapus cache file
        if ($enable) {
            @file_put_contents($cacheFile, json_encode([
                'active'        => true,
                'message'       => $message,
                'until'         => $until,
                'whitelist_ips' => $existingIPs,
                'activated_at'  => date('c'),
            ]));
        } else {
            @unlink($cacheFile);
        }

        logSuperAdminAction('maintenance_' . ($enable ? 'on' : 'off'), null,
            $enable ? "Maintenance aktif: $message" : 'Maintenance dimatikan');

        // WA Broadcast ke semua tenant aktif
        $tenants = $db->query(
            "SELECT owner_wa, owner_name FROM tenants
              WHERE status IN ('active','trial','grace') AND is_demo=0
                AND owner_wa IS NOT NULL AND owner_wa != ''"
        )->fetchAll(PDO::FETCH_ASSOC);

        $waMsg = $enable
            ? "🔧 *LaMaSy Maintenance*\n\n{$message}" . ($until ? "\n⏰ Estimasi selesai: " . date('d M Y H:i', strtotime($until)) . " WIB" : "") . "\n\nMohon maaf atas ketidaknyamanannya. 🙏"
            : "✅ *LaMaSy Sudah Kembali Normal!*\n\nSistem sudah bisa digunakan kembali.\nTerima kasih atas kesabarannya! 🙏\n\n_Tim LaMaSy_";

        $waSent = 0;
        foreach ($tenants as $t) {
            $no = preg_replace('/[^0-9]/', '', $t['owner_wa'] ?? '');
            if (!$no) continue;
            $url = "https://wa.me/{$no}?text=" . urlencode($waMsg);
            // Log WA (actual send via WA API jika tersedia)
            try {
                $db->prepare(
                    "INSERT INTO saas_wa_log (tenant_id, type, wa_target, pesan_preview, status, sent_at)
                     SELECT t.id, 'maintenance_notif', ?, ?, 'sent', NOW()
                     FROM tenants t WHERE t.owner_wa=? LIMIT 1"
                )->execute([$no, mb_substr($waMsg, 0, 200), $t['owner_wa']]);
            } catch (Throwable) {}
            $waSent++;
        }

        echo json_encode(['ok' => true, 'wa_sent' => $waSent, 'active' => (bool)$enable]);
        exit;
    }

    // ── Maintenance: status ──────────────────────────
    if ($action === 'maintenance_status') {
        echo json_encode([
            'active'  => getConfig('maintenance_mode') === '1',
            'message' => getConfig('maintenance_message'),
            'until'   => getConfig('maintenance_until'),
            'my_ip'   => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
        exit;
    }

    // ── Demo: stats ──────────────────────────────────
    if ($action === 'demo_stats') {
        $lastReset = $db->prepare("SELECT demo_reset_at FROM tenants WHERE is_demo=1 LIMIT 1");
        $lastReset->execute();
        $resetAt   = $lastReset->fetchColumn() ?: null;

        $today = $db->query(
            "SELECT COUNT(*) FROM saas_demo_sessions WHERE DATE(started_at)=CURDATE()"
        )->fetchColumn() ?: 0;

        $convToday = $db->query(
            "SELECT COUNT(*) FROM saas_demo_sessions WHERE DATE(started_at)=CURDATE() AND converted=1"
        )->fetchColumn() ?: 0;

        $total = $db->query("SELECT COUNT(*) FROM saas_demo_sessions")->fetchColumn() ?: 0;
        $conv  = $db->query("SELECT COUNT(*) FROM saas_demo_sessions WHERE converted=1")->fetchColumn() ?: 0;

        echo json_encode([
            'last_reset'   => $resetAt,
            'sessions_today' => (int)$today,
            'conv_today'   => (int)$convToday,
            'sessions_total'=> (int)$total,
            'conv_total'   => (int)$conv,
        ]);
        exit;
    }

    // ── Demo: manual reset ───────────────────────────
    if ($action === 'demo_reset') {
        $demoTid = (int)getConfig('demo_tenant_id', '0');
        $demoOid = (int)getConfig('demo_outlet_id', '0');
        if (!$demoTid || !$demoOid) {
            echo json_encode(['error' => 'Demo tenant tidak dikonfigurasi.']); exit;
        }

        require_once dirname(__DIR__) . '/core/PlatformHealthRecorder.php';

        $tables = [
            'hl_transaksi','hl_transaksi_item','hl_kas',
            'hl_absensi','hl_order_notes','hl_loyalty_log',
        ];
        foreach ($tables as $tbl) {
            try {
                $db->prepare("DELETE FROM {$tbl} WHERE tenant_id=? AND outlet_id=?")->execute([$demoTid, $demoOid]);
            } catch (Throwable) {}
        }
        // Reset pelanggan demo (keep skeleton, delete extras)
        try {
            $db->prepare("DELETE FROM hl_pelanggan WHERE tenant_id=? AND outlet_id=? AND nama NOT LIKE 'Demo%'")->execute([$demoTid, $demoOid]);
        } catch (Throwable) {}

        $db->prepare("UPDATE tenants SET demo_reset_at=NOW() WHERE id=?")->execute([$demoTid]);

        logSuperAdminAction('demo_reset', null, 'Manual reset data demo tenant');
        echo json_encode(['ok' => true, 'reset_at' => date('c')]);
        exit;
    }

    // ── ToS: release new version ─────────────────────
    if ($action === 'tos_release') {
        $version = trim($_POST['version'] ?? '');
        $summary = trim($_POST['summary'] ?? '');
        $date    = trim($_POST['effective_date'] ?? '') ?: date('Y-m-d');

        if (!preg_match('/^\d+\.\d+$/', $version)) {
            echo json_encode(['error' => 'Format versi tidak valid (contoh: 1.1)']); exit;
        }

        $db->prepare("UPDATE saas_tos_versions SET is_current=0 WHERE is_current=1")->execute();
        $db->prepare(
            "INSERT IGNORE INTO saas_tos_versions (version, effective_date, summary, is_current, created_by)
             VALUES (?,?,?,1,?)"
        )->execute([$version, $date, $summary, $admin['id'] ?? null]);

        // Reset cache ToS di session semua pengguna → mereka akan di-prompt saat login berikutnya
        // (PHP session-based — tidak bisa invalidate semua session, tapi _tenant_tos_ver di session
        // akan mismatch saat mereka login kembali)

        logSuperAdminAction('tos_release', null, "Rilis ToS versi $version — berlaku $date");
        echo json_encode(['ok' => true, 'version' => $version]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Action tidak dikenal']);
    exit;
}

// ── GET: Load data untuk render ───────────────────────
$maintenanceActive = getConfig('maintenance_mode') === '1';
$myIP              = $_SERVER['REMOTE_ADDR'] ?? '';

$tosVersions = $db->query(
    "SELECT * FROM saas_tos_versions ORDER BY id DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$activePage = 'settings';
$pageTitle  = 'Platform Settings';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php saRenderHead('Platform Settings'); ?>
</head>
<body>
<div class="sa-layout">
<?php saRenderNav('settings', 'Platform Settings'); ?>

<style>
  .set-tabs{display:flex;gap:4px;margin-bottom:28px;border-bottom:2px solid rgba(255,255,255,.06);padding-bottom:0}
  .set-tab{padding:10px 20px;font-size:13.5px;font-weight:600;color:rgba(255,255,255,.4);border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;border-radius:8px 8px 0 0}
  .set-tab.active{color:#35E8D5;border-bottom-color:#35E8D5;background:rgba(53,232,213,.06)}
  .set-panel{display:none}.set-panel.active{display:block}
  .set-card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:24px;margin-bottom:20px}
  .set-card h3{font-size:15px;font-weight:700;margin-bottom:6px}
  .set-card p{font-size:13px;color:rgba(255,255,255,.5);margin-bottom:16px;line-height:1.5}
  .set-field{margin-bottom:16px}
  .set-field label{display:block;font-size:12px;font-weight:600;color:rgba(255,255,255,.5);margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px}
  .set-field input,.set-field textarea,.set-field select{
    width:100%;padding:10px 12px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);
    border-radius:8px;color:#fff;font-size:14px;font-family:inherit;
  }
  .set-field textarea{resize:vertical;min-height:72px}
  .set-field input:focus,.set-field textarea:focus{outline:none;border-color:rgba(53,232,213,.4)}
  .toggle-row{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
  .toggle-switch{position:relative;display:inline-block;width:52px;height:28px;flex-shrink:0}
  .toggle-switch input{opacity:0;width:0;height:0}
  .toggle-slider{position:absolute;inset:0;background:rgba(255,255,255,.12);border-radius:28px;cursor:pointer;transition:.2s}
  .toggle-slider:before{content:'';position:absolute;width:20px;height:20px;left:4px;bottom:4px;background:#fff;border-radius:50%;transition:.2s}
  .toggle-switch input:checked + .toggle-slider{background:#E24B4A}
  .toggle-switch input:checked + .toggle-slider:before{transform:translateX(24px)}
  .status-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
  .stat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:12px}
  .stat-box{background:rgba(255,255,255,.05);border-radius:10px;padding:14px;text-align:center}
  .stat-val{font-size:24px;font-weight:800;color:#35E8D5}
  .stat-label{font-size:11px;color:rgba(255,255,255,.4);margin-top:4px}
  .tos-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 0;border-bottom:1px solid rgba(255,255,255,.06)}
  .tos-badge{font-size:11px;padding:3px 10px;border-radius:20px;font-weight:700}
  .tos-badge.current{background:rgba(53,232,213,.15);color:#35E8D5}
  .tos-badge.old{background:rgba(255,255,255,.06);color:rgba(255,255,255,.3)}
  .sa-btn{padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;transition:.15s}
  .sa-btn-primary{background:#35E8D5;color:#0F1C3A}
  .sa-btn-primary:hover{background:#2dd4c4}
  .sa-btn-danger{background:#E24B4A;color:#fff}
  .sa-btn-danger:hover{background:#c43b3a}
  .sa-btn-outline{background:transparent;border:1px solid rgba(255,255,255,.2);color:rgba(255,255,255,.7)}
  .sa-btn-outline:hover{background:rgba(255,255,255,.05)}
  .alert-box{padding:12px 16px;border-radius:10px;font-size:13px;margin-bottom:16px}
  .alert-warn{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:#FCA5A5}
  .alert-ok{background:rgba(53,232,213,.08);border:1px solid rgba(53,232,213,.2);color:#35E8D5}
  #toast-set{position:fixed;bottom:24px;right:24px;background:#1e2d4a;color:#fff;padding:12px 20px;border-radius:10px;font-size:13px;display:none;z-index:9999;box-shadow:0 4px 20px rgba(0,0,0,.3)}
</style>

<div class="set-tabs">
  <button class="set-tab active" onclick="switchTab('maintenance',this)">🔧 Maintenance</button>
  <button class="set-tab" onclick="switchTab('demo',this)">🎮 Demo</button>
  <button class="set-tab" onclick="switchTab('tos',this)">📋 ToS Versions</button>
</div>

<!-- ══════════════════════════ MAINTENANCE TAB ═══════════════════════════ -->
<div class="set-panel active" id="tab-maintenance">

  <div id="maintAlert"></div>

  <div class="set-card">
    <div class="toggle-row" style="margin-bottom:20px">
      <div>
        <h3 style="margin-bottom:4px">Maintenance Mode</h3>
        <p style="margin-bottom:0">Saat aktif, semua tenant di-redirect ke halaman maintenance. Superadmin tetap bisa akses.</p>
      </div>
      <label class="toggle-switch">
        <input type="checkbox" id="maintToggle" <?= $maintenanceActive ? 'checked' : '' ?>>
        <span class="toggle-slider"></span>
      </label>
    </div>

    <div class="set-field">
      <label>Pesan untuk Tenant</label>
      <textarea id="maintMessage" rows="3" placeholder="Sistem sedang dalam pemeliharaan..."><?= htmlspecialchars(getConfig('maintenance_message', 'Sistem sedang dalam pemeliharaan terjadwal.')) ?></textarea>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div class="set-field">
        <label>Estimasi Selesai (opsional)</label>
        <input type="datetime-local" id="maintUntil" value="<?= htmlspecialchars(getConfig('maintenance_until') ? date('Y-m-d\TH:i', strtotime(getConfig('maintenance_until'))) : '') ?>">
      </div>
      <div class="set-field">
        <label>IP Anda (otomatis di-whitelist)</label>
        <input type="text" value="<?= htmlspecialchars($myIP) ?>" readonly style="opacity:.5">
      </div>
    </div>

    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:8px">
      <button class="sa-btn sa-btn-danger" id="maintActivateBtn" onclick="toggleMaintenance(true)">🔴 Aktifkan Maintenance</button>
      <button class="sa-btn sa-btn-primary" id="maintDeactivateBtn" onclick="toggleMaintenance(false)">🟢 Nonaktifkan</button>
    </div>
  </div>

  <div class="set-card">
    <h3>📊 Status Saat Ini</h3>
    <p>Status maintenance dan info terkini.</p>
    <div id="maintStatus" style="font-size:14px;color:rgba(255,255,255,.6)">Memuat...</div>
  </div>
</div>

<!-- ══════════════════════════ DEMO TAB ═══════════════════════════ -->
<div class="set-panel" id="tab-demo">

  <div class="set-card">
    <h3>🎮 Demo Tenant</h3>
    <p>Akun demo shared yang bisa diakses dari <strong>/demo</strong> tanpa registrasi. Data di-reset otomatis setiap 24 jam.</p>

    <div class="stat-grid" id="demoStats">
      <div class="stat-box"><div class="stat-val" id="demoToday">–</div><div class="stat-label">Sesi Hari Ini</div></div>
      <div class="stat-box"><div class="stat-val" id="demoConvToday">–</div><div class="stat-label">Konversi Hari Ini</div></div>
      <div class="stat-box"><div class="stat-val" id="demoTotal">–</div><div class="stat-label">Total Sesi</div></div>
      <div class="stat-box"><div class="stat-val" id="demoConvTotal">–</div><div class="stat-label">Total Konversi</div></div>
    </div>

    <div style="margin-top:16px;font-size:13px;color:rgba(255,255,255,.4)">
      Last reset: <span id="demoLastReset">memuat...</span>
    </div>
  </div>

  <div class="set-card">
    <h3>♻️ Reset Manual</h3>
    <p>Hapus semua data operasional demo (transaksi, kas, absensi) dan kembalikan ke state awal. Pelanggan demo tetap dipertahankan.</p>
    <button class="sa-btn sa-btn-outline" onclick="resetDemo()">♻️ Reset Data Demo Sekarang</button>
    <div style="margin-top:12px;font-size:12px;color:rgba(255,255,255,.3)">
      URL Demo: <a href="/demo" target="_blank" style="color:#35E8D5">/demo</a>
    </div>
  </div>
</div>

<!-- ══════════════════════════ TOS TAB ═══════════════════════════ -->
<div class="set-panel" id="tab-tos">

  <div class="set-card">
    <h3>📋 Riwayat Versi ToS</h3>
    <p>Saat versi baru dirilis, semua tenant wajib accept ulang sebelum bisa lanjut menggunakan platform.</p>

    <?php foreach ($tosVersions as $tv): ?>
    <div class="tos-row">
      <div>
        <div style="font-weight:700;font-size:14px">Versi <?= htmlspecialchars($tv['version']) ?></div>
        <div style="font-size:12px;color:rgba(255,255,255,.4);margin-top:2px">
          Berlaku: <?= htmlspecialchars(date('d M Y', strtotime($tv['effective_date']))) ?>
          <?= $tv['summary'] ? ' · ' . htmlspecialchars(mb_substr($tv['summary'], 0, 80)) : '' ?>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:10px">
        <?php
        $accepted = $db->prepare("SELECT COUNT(*) FROM tenants WHERE tos_version=? AND is_demo=0");
        $accepted->execute([$tv['version']]);
        $cnt = (int)$accepted->fetchColumn();
        ?>
        <span style="font-size:12px;color:rgba(255,255,255,.4)"><?= $cnt ?> tenant</span>
        <span class="tos-badge <?= $tv['is_current'] ? 'current' : 'old' ?>">
          <?= $tv['is_current'] ? '✓ Aktif' : 'Lama' ?>
        </span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="set-card">
    <h3>➕ Rilis Versi Baru</h3>
    <p>Semua tenant akan diminta accept ulang saat login berikutnya. Pastikan halaman <a href="/tos" target="_blank" style="color:#35E8D5">/tos</a> sudah diperbarui terlebih dahulu.</p>

    <div class="set-field">
      <label>Nomor Versi</label>
      <input type="text" id="tosNewVersion" placeholder="contoh: 1.1" style="max-width:200px">
    </div>
    <div class="set-field">
      <label>Tanggal Berlaku</label>
      <input type="date" id="tosEffectiveDate" value="<?= date('Y-m-d') ?>" style="max-width:200px">
    </div>
    <div class="set-field">
      <label>Ringkasan Perubahan</label>
      <textarea id="tosSummary" rows="3" placeholder="Apa yang berubah di versi ini..."></textarea>
    </div>
    <div style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:8px;padding:12px;font-size:12.5px;color:#FCA5A5;margin-bottom:16px">
      ⚠️ Setelah rilis, <strong>semua tenant</strong> wajib accept ulang ToS sebelum bisa lanjut. Pastikan ini perlu dilakukan.
    </div>
    <button class="sa-btn sa-btn-outline" onclick="releaseTos()">📋 Rilis Versi Baru</button>
  </div>
</div>

<div id="toast-set"></div>

<?php saRenderNavClose(); ?>
<script>
const CSRF = '<?= htmlspecialchars($csrf) ?>';

function switchTab(name, btn) {
    document.querySelectorAll('.set-tab').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.set-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab-' + name).classList.add('active');
    if (name === 'demo')    loadDemoStats();
    if (name === 'maintenance') loadMaintStatus();
}

function showToast(msg, ok=true) {
    var t = document.getElementById('toast-set');
    t.textContent = (ok ? '✓ ' : '✗ ') + msg;
    t.style.display = 'block';
    t.style.borderLeft = '3px solid ' + (ok ? '#35E8D5' : '#E24B4A');
    clearTimeout(t._to);
    t._to = setTimeout(() => t.style.display='none', 3500);
}

async function api(action, body={}) {
    const fd = new FormData();
    Object.entries(body).forEach(([k,v]) => fd.append(k, v));
    fd.append('_csrf', CSRF);
    const r = await fetch('settings.php?action=' + action, {
        method:'POST', body:fd,
        headers:{'X-Requested-With':'XMLHttpRequest'}
    });
    return r.json();
}

// ── Maintenance ────────────────────────────────────────
async function loadMaintStatus() {
    const d = await (await fetch('settings.php?action=maintenance_status', {headers:{'X-Requested-With':'XMLHttpRequest'}})).json();
    const el = document.getElementById('maintStatus');
    const dot = d.active
        ? '<span class="status-dot" style="background:#E24B4A;display:inline-block"></span> <strong style="color:#FCA5A5">AKTIF</strong>'
        : '<span class="status-dot" style="background:#35E8D5;display:inline-block"></span> <strong style="color:#35E8D5">NONAKTIF</strong>';
    el.innerHTML = dot + (d.active && d.message ? `<br><span style="font-size:12px;opacity:.6;margin-top:4px;display:block">${d.message}</span>` : '');
    document.getElementById('maintToggle').checked = d.active;
}

async function toggleMaintenance(enable) {
    const message = document.getElementById('maintMessage').value.trim();
    const until   = document.getElementById('maintUntil').value || '';
    if (enable && !message) { alert('Isi pesan maintenance terlebih dahulu.'); return; }
    if (enable && !confirm(`Aktifkan maintenance mode?\nSemua tenant akan di-redirect ke halaman maintenance.`)) return;
    if (!enable && !confirm('Nonaktifkan maintenance mode? Tenant akan bisa akses kembali.')) return;

    const d = await api('maintenance_toggle', {enable: enable ? 1 : 0, message, until});
    if (d.ok) {
        showToast(enable ? `Maintenance aktif. WA terkirim ke ${d.wa_sent} tenant.` : 'Maintenance dinonaktifkan.');
        loadMaintStatus();
    } else {
        showToast(d.error || 'Gagal', false);
    }
}

// ── Demo ───────────────────────────────────────────────
async function loadDemoStats() {
    const d = await (await fetch('settings.php?action=demo_stats', {headers:{'X-Requested-With':'XMLHttpRequest'}})).json();
    document.getElementById('demoToday').textContent     = d.sessions_today;
    document.getElementById('demoConvToday').textContent = d.conv_today;
    document.getElementById('demoTotal').textContent     = d.sessions_total;
    document.getElementById('demoConvTotal').textContent = d.conv_total;
    document.getElementById('demoLastReset').textContent = d.last_reset
        ? new Date(d.last_reset).toLocaleString('id-ID') : 'Belum pernah';
}

async function resetDemo() {
    if (!confirm('Reset data demo sekarang?\nSemua transaksi, kas, absensi demo akan dihapus.')) return;
    const d = await api('demo_reset');
    if (d.ok) {
        showToast('Data demo berhasil di-reset.');
        loadDemoStats();
    } else {
        showToast(d.error || 'Gagal reset', false);
    }
}

// ── ToS ────────────────────────────────────────────────
async function releaseTos() {
    const version = document.getElementById('tosNewVersion').value.trim();
    const date    = document.getElementById('tosEffectiveDate').value;
    const summary = document.getElementById('tosSummary').value.trim();
    if (!version) { alert('Isi nomor versi.'); return; }
    if (!confirm(`Rilis ToS versi ${version}?\n\nSemua tenant akan diminta accept ulang saat login berikutnya.`)) return;

    const d = await api('tos_release', {version, effective_date: date, summary});
    if (d.ok) {
        showToast(`ToS versi ${d.version} berhasil dirilis.`);
        setTimeout(() => location.reload(), 1500);
    } else {
        showToast(d.error || 'Gagal', false);
    }
}

// Init
loadMaintStatus();
</script>
</body>
</html>

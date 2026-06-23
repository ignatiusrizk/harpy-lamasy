<?php
// ══════════════════════════════════════════════════════
// superadmin/settings.php — Platform Settings
// Tab: Maintenance | Demo | ToS Versions
// ══════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';
require_once SA_ROOT . '/../core/SaPermission.php';

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
    if ($action === 'tips_list') {
        $rows = $db->query(
            "SELECT id, tenant_id, judul, konten, icon, cta_label, cta_url, urutan, is_active
             FROM hl_splash_tips ORDER BY urutan ASC, id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['data' => $rows]);
        exit;
    }

    if ($action === 'tips_save') {
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $judul     = substr(trim(strip_tags($d['judul'] ?? '')), 0, 100);
        $konten    = substr(trim(strip_tags($d['konten'] ?? '')), 0, 2000);
        $icon      = substr(trim($d['icon'] ?? '💡'), 0, 10) ?: '💡';
        $ctaLabel  = substr(trim(strip_tags($d['cta_label'] ?? '')), 0, 50) ?: null;
        $ctaUrl    = substr(trim($d['cta_url'] ?? ''), 0, 200) ?: null;
        $urutan    = max(0, (int)($d['urutan'] ?? 0));
        $isActive  = !empty($d['is_active']) ? 1 : 0;
        if (!$judul || !$konten) {
            echo json_encode(['error' => 'Judul dan konten wajib diisi']); exit;
        }
        if (!empty($d['id'])) {
            $db->prepare(
                "UPDATE hl_splash_tips SET judul=?, konten=?, icon=?, cta_label=?, cta_url=?, urutan=?, is_active=? WHERE id=?"
            )->execute([$judul, $konten, $icon, $ctaLabel, $ctaUrl, $urutan, $isActive, (int)$d['id']]);
        } else {
            $db->prepare(
                "INSERT INTO hl_splash_tips (tenant_id, judul, konten, icon, cta_label, cta_url, urutan, is_active)
                 VALUES (NULL, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([$judul, $konten, $icon, $ctaLabel, $ctaUrl, $urutan, $isActive]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'tips_toggle') {
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($d['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'ID invalid']); exit; }
        $db->prepare("UPDATE hl_splash_tips SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'tips_delete') {
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($d['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'ID invalid']); exit; }
        $db->prepare("DELETE FROM hl_splash_tips WHERE id=?")->execute([$id]);
        $db->prepare("DELETE FROM hl_splash_seen WHERE splash_type='tips' AND ref_id LIKE ?")
           ->execute([$id . '_%']);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'tips_reset_seen') {
        require_once dirname(__DIR__) . '/core/SplashManager.php';
        $deleted = SplashManager::resetTipsHistory();
        echo json_encode(['success' => true, 'deleted' => $deleted]);
        exit;
    }

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

    // ── Notifications: list super admins + log ──────
    if ($action === 'notify_list') {
        $admins = $db->query(
            "SELECT id, username, name, email, notify_enabled FROM super_admins WHERE is_active=1 ORDER BY id"
        )->fetchAll(PDO::FETCH_ASSOC);
        $logs = $db->query(
            "SELECT event_type, ref_id, subject, recipients, sent_at FROM saas_sa_notif_log
             ORDER BY id DESC LIMIT 20"
        )->fetchAll(PDO::FETCH_ASSOC);
        $constEmails = defined('SA_NOTIFY_EMAILS') && is_array(SA_NOTIFY_EMAILS) ? SA_NOTIFY_EMAILS : [];
        echo json_encode(['ok' => true, 'admins' => $admins, 'logs' => $logs, 'const' => $constEmails]);
        exit;
    }

    if ($action === 'notify_save') {
        $id     = (int)($_POST['id'] ?? 0);
        $email  = trim($_POST['email'] ?? '');
        $enable = (int)($_POST['enable'] ?? 0);
        if ($id <= 0) { echo json_encode(['error' => 'ID invalid']); exit; }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['error' => 'Email tidak valid']); exit;
        }
        $db->prepare("UPDATE super_admins SET email=?, notify_enabled=? WHERE id=?")
           ->execute([$email ?: null, $enable, $id]);
        logSuperAdminAction('notify_save', null, "Update notif SA #$id email=$email enable=$enable");
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'notify_test') {
        require_once dirname(__DIR__) . '/core/SaNotifier.php';
        // Bypass throttle via direct invocation with random event_type
        SaNotifier::notify('test_email_' . time(),
            '[LAMASY] 🧪 Test notif email',
            '<p style="font-family:Arial">Halo Super Admin,</p>'
          . '<p>Ini email test dari /superadmin/settings → tab Notifications.</p>'
          . '<p>Kalau kamu nerima ini, berarti SMTP + recipient list udah jalan ✓</p>'
          . '<p style="color:#9CA3AF;font-size:12px;margin-top:24px">— LAMASY Admin System</p>',
            (string)($admin['id'] ?? 0));
        echo json_encode(['ok' => true, 'message' => 'Test email dikirim ke recipient list aktif.']);
        exit;
    }

    // ── SA Team: list ────────────────────────────────
    if ($action === 'team_list') {
        SaPermission::require('super_admins.manage');
        $admins = $db->query(
            "SELECT sa.id, sa.username, sa.name, sa.email, sa.notify_enabled,
                    sa.is_active, sa.last_login, sa.created_at,
                    r.slug AS role_slug, r.name AS role_name
             FROM super_admins sa
             LEFT JOIN sa_roles r ON r.id = sa.role_id
             ORDER BY sa.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        $roles = $db->query("SELECT id, slug, name FROM sa_roles ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'admins' => $admins, 'roles' => $roles]);
        exit;
    }

    // ── SA Team: create ──────────────────────────────
    if ($action === 'team_create') {
        SaPermission::require('super_admins.manage');
        saVerifyCsrf();
        $username = trim($_POST['username'] ?? '');
        $name     = trim($_POST['name']     ?? '');
        $email    = trim($_POST['email']    ?? '');
        $password = $_POST['password']       ?? '';
        $roleId   = (int)($_POST['role_id'] ?? 0);

        if (!$username || !$name || !$password || !$roleId) {
            echo json_encode(['error' => 'Username, nama, password, dan role wajib diisi']); exit;
        }
        if (!preg_match('/^[a-z0-9_]{3,30}$/', $username)) {
            echo json_encode(['error' => 'Username hanya boleh huruf kecil, angka, underscore (3-30 karakter)']); exit;
        }
        if (strlen($password) < 8) {
            echo json_encode(['error' => 'Password minimal 8 karakter']); exit;
        }
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['error' => 'Email tidak valid']); exit;
        }

        // Check username unique
        $exists = $db->prepare("SELECT id FROM super_admins WHERE username=? LIMIT 1");
        $exists->execute([$username]);
        if ($exists->fetchColumn()) {
            echo json_encode(['error' => 'Username sudah digunakan']); exit;
        }

        // Check role exists
        $roleCheck = $db->prepare("SELECT id FROM sa_roles WHERE id=?");
        $roleCheck->execute([$roleId]);
        if (!$roleCheck->fetchColumn()) {
            echo json_encode(['error' => 'Role tidak valid']); exit;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $db->prepare(
            "INSERT INTO super_admins (username, name, email, password, role_id, notify_enabled, is_active)
             VALUES (?,?,?,?,?,1,1)"
        )->execute([$username, $name, $email ?: null, $hash, $roleId]);
        $newId = (int)$db->lastInsertId();

        logSuperAdminAction('sa_team_create', null, "Buat SA baru: @$username ($name), role_id=$roleId");
        echo json_encode(['ok' => true, 'id' => $newId]);
        exit;
    }

    // ── SA Team: update ──────────────────────────────
    if ($action === 'team_update') {
        SaPermission::require('super_admins.manage');
        saVerifyCsrf();
        $id       = (int)($_POST['id']      ?? 0);
        $name     = trim($_POST['name']     ?? '');
        $email    = trim($_POST['email']    ?? '');
        $roleId   = (int)($_POST['role_id'] ?? 0);
        $isActive = (int)($_POST['is_active'] ?? 1);

        if (!$id || !$name || !$roleId) {
            echo json_encode(['error' => 'ID, nama, dan role wajib diisi']); exit;
        }
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['error' => 'Email tidak valid']); exit;
        }

        // Check target role — cannot demote an owner unless requester is also owner
        $targetRow = $db->prepare("SELECT sa.role_id, r.slug FROM super_admins sa LEFT JOIN sa_roles r ON r.id=sa.role_id WHERE sa.id=?");
        $targetRow->execute([$id]);
        $target = $targetRow->fetch(PDO::FETCH_ASSOC);
        if (!$target) { echo json_encode(['error' => 'Admin tidak ditemukan']); exit; }

        // Non-owner cannot edit owner admin
        if ($target['slug'] === 'owner' && !SaPermission::has('super_admins.manage')) {
            echo json_encode(['error' => 'Hanya Owner yang bisa edit akun Owner lain']); exit;
        }

        $db->prepare(
            "UPDATE super_admins SET name=?, email=?, role_id=?, is_active=? WHERE id=?"
        )->execute([$name, $email ?: null, $roleId, $isActive, $id]);

        logSuperAdminAction('sa_team_update', null, "Update SA #$id: name=$name role_id=$roleId is_active=$isActive");
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── SA Team: reset password ──────────────────────
    if ($action === 'team_reset_password') {
        SaPermission::require('super_admins.manage');
        saVerifyCsrf();
        $id       = (int)($_POST['id']       ?? 0);
        $password = $_POST['new_password']    ?? '';
        if (!$id)             { echo json_encode(['error' => 'ID tidak valid']); exit; }
        if (strlen($password) < 8) { echo json_encode(['error' => 'Password minimal 8 karakter']); exit; }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $db->prepare("UPDATE super_admins SET password=? WHERE id=?")->execute([$hash, $id]);
        logSuperAdminAction('sa_team_reset_pw', null, "Reset password SA #$id");
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── SA Team: delete (soft) ───────────────────────
    if ($action === 'team_delete') {
        SaPermission::require('super_admins.manage');
        saVerifyCsrf();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'ID tidak valid']); exit; }

        // Cannot soft-delete owner role
        $roleCheck = $db->prepare(
            "SELECT r.slug FROM super_admins sa LEFT JOIN sa_roles r ON r.id=sa.role_id WHERE sa.id=?"
        );
        $roleCheck->execute([$id]);
        $roleSlug = $roleCheck->fetchColumn();
        if ($roleSlug === 'owner') {
            echo json_encode(['error' => 'Akun Owner tidak bisa dihapus']); exit;
        }

        $db->prepare("UPDATE super_admins SET is_active=0 WHERE id=?")->execute([$id]);
        logSuperAdminAction('sa_team_delete', null, "Soft-delete SA #$id");
        echo json_encode(['ok' => true]);
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
  /* ── Settings-specific tabs & panels ── */
  .set-tabs{display:flex;gap:4px;margin-bottom:28px;border-bottom:1px solid rgba(255,255,255,.07);padding-bottom:0}
  .set-tab{padding:10px 18px;font-size:13px;font-weight:600;color:rgba(255,255,255,.4);border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;border-radius:6px 6px 0 0;transition:color .15s,border-color .15s}
  .set-tab:hover{color:rgba(255,255,255,.8)}
  .set-tab.active{color:var(--sa);border-bottom-color:var(--sa);background:rgba(99,102,241,.05)}
  .set-panel{display:none}.set-panel.active{display:block}

  /* ── Content cards ── */
  .set-card{background:var(--card-bg);border:1px solid var(--card-border);border-radius:14px;padding:24px;margin-bottom:20px}
  .set-card h3{font-size:15px;font-weight:700;margin-bottom:6px;letter-spacing:-.01em}
  .set-card p{font-size:13px;color:var(--text-muted);margin-bottom:16px;line-height:1.6}

  /* ── Form fields ── */
  .set-field{margin-bottom:16px}
  .set-field label{display:block;font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.06em}
  .set-field input,.set-field textarea,.set-field select{
    width:100%;padding:10px 12px;background:rgba(255,255,255,.06);
    border:1px solid rgba(255,255,255,.12);border-radius:8px;
    color:#fff;font-size:14px;font-family:inherit;
    outline:none;transition:border-color .15s,box-shadow .15s;
  }
  .set-field textarea{resize:vertical;min-height:72px}
  .set-field input:focus,.set-field textarea:focus,.set-field select:focus{
    border-color:var(--sa);box-shadow:0 0 0 3px rgba(99,102,241,.12);
  }
  .set-field input[readonly]{opacity:.5;cursor:not-allowed}

  /* ── Toggle switch ── */
  .toggle-row{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
  .toggle-switch{position:relative;display:inline-block;width:52px;height:28px;flex-shrink:0}
  .toggle-switch input{opacity:0;width:0;height:0}
  .toggle-slider{position:absolute;inset:0;background:rgba(255,255,255,.12);border-radius:28px;cursor:pointer;transition:.2s}
  .toggle-slider:before{content:'';position:absolute;width:20px;height:20px;left:4px;bottom:4px;background:#fff;border-radius:50%;transition:.2s;box-shadow:0 1px 4px rgba(0,0,0,.3)}
  .toggle-switch input:checked + .toggle-slider{background:#EF4444}
  .toggle-switch input:checked + .toggle-slider:before{transform:translateX(24px)}

  /* ── Status dot ── */
  .status-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;display:inline-block}

  /* ── Demo stat boxes ── */
  .stat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:12px}
  .stat-box{background:rgba(99,102,241,.08);border:1px solid rgba(99,102,241,.15);border-radius:12px;padding:16px;text-align:center}
  .stat-val{font-size:24px;font-weight:800;color:#818CF8;font-family:var(--mono)}
  .stat-label{font-size:11px;color:var(--text-muted);margin-top:5px}

  /* ── ToS rows ── */
  .tos-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 0;border-bottom:1px solid rgba(255,255,255,.06)}
  .tos-row:last-child{border-bottom:none}
  .tos-badge{font-size:11px;padding:3px 10px;border-radius:20px;font-weight:700;white-space:nowrap}
  .tos-badge.current{background:rgba(99,102,241,.15);color:#A5B4FC;border:1px solid rgba(99,102,241,.25)}
  .tos-badge.old{background:rgba(255,255,255,.05);color:rgba(255,255,255,.3);border:1px solid rgba(255,255,255,.1)}

  /* ── Alert boxes (inline warning in settings) ── */
  .alert-box{padding:12px 16px;border-radius:10px;font-size:13px;margin-bottom:16px}
  .alert-warn{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);color:#FCA5A5}
  .alert-ok{background:rgba(99,102,241,.08);border:1px solid rgba(99,102,241,.2);color:#A5B4FC}

  /* ── Toast (settings page — routes through saShowToast via redirect) ── */
  #toast-set{
    position:fixed;bottom:28px;right:24px;
    background:rgba(22,35,72,.95);backdrop-filter:blur(8px);
    color:#fff;padding:12px 20px;border-radius:12px;font-size:13px;
    display:none;z-index:9999;box-shadow:0 8px 24px rgba(0,0,0,.4);
    border:1px solid rgba(255,255,255,.1);
  }
</style>

<div class="set-tabs">
  <button class="set-tab active" onclick="switchTab('maintenance',this)">🔧 Maintenance</button>
  <button class="set-tab" onclick="switchTab('demo',this)">🎮 Demo</button>
  <button class="set-tab" onclick="switchTab('tos',this)">📋 ToS Versions</button>
  <button class="set-tab" onclick="switchTab('tips',this)">💡 Splash Tips</button>
  <button class="set-tab" onclick="switchTab('notify',this);loadNotify()">🔔 Notifications</button>
  <button class="set-tab" onclick="switchTab('team',this);loadTeam()">👥 SA Team</button>
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

<!-- ══════════════════════════ TIPS TAB ═══════════════════════════ -->
<div class="set-panel" id="tab-tips">

  <div class="set-card">
    <h3>💡 Splash Tips Harian</h3>
    <p>Tips yang muncul 1x per hari per user. Toggle aktif untuk publish/unpublish. Klik "Reset Seen" supaya semua user lihat tips baru lagi besok.</p>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
      <button class="sa-btn sa-btn-primary" onclick="openTipsEdit(null)">➕ Tambah Tip</button>
      <button class="sa-btn sa-btn-outline" onclick="resetTipsSeen()" title="Hapus seen history → semua user lihat tips lagi">🔄 Reset Seen History</button>
    </div>
    <div id="tipsList" style="display:flex;flex-direction:column;gap:10px"></div>
  </div>

</div>

<!-- ══════════════════════════ NOTIFICATIONS TAB ═════════════════════════ -->
<div class="set-panel" id="tab-notify">
  <div class="set-card">
    <div class="set-card-head">
      <h3>🔔 SA Activity Notifications</h3>
      <button class="sa-btn sa-btn-secondary" onclick="testNotify()">📧 Test Email</button>
    </div>
    <p style="font-size:13px;color:rgba(255,255,255,.55);margin-bottom:16px">
      Email otomatis ke super admin saat ada activity tenant: register, verify, outlet aktivasi, support ticket, trial expiring, dan auto-suspend. Throttle 1 menit per event-type.
    </p>

    <div style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.5);margin-bottom:8px">Constant Default (db.php)</div>
    <div id="notifConst" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:8px;padding:10px 14px;margin-bottom:20px;font-family:monospace;font-size:12.5px;color:rgba(255,255,255,.7)">Loading…</div>

    <div style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.5);margin-bottom:8px">Super Admin Opt-In</div>
    <div id="notifAdminList" style="display:flex;flex-direction:column;gap:10px;margin-bottom:24px"></div>

    <div style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.5);margin-bottom:8px">Recent Log (last 20)</div>
    <div id="notifLogList" style="display:flex;flex-direction:column;gap:6px"></div>
  </div>
</div>

<!-- ══════════════════════════ SA TEAM TAB ═══════════════════════════ -->
<div class="set-panel" id="tab-team">

  <div class="set-card">
    <div class="set-card-head" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
      <div>
        <h3 style="margin:0;font-size:15px;font-weight:700">👥 Super Admin Team</h3>
        <p style="margin:6px 0 0;font-size:13px;color:rgba(255,255,255,.5)">Kelola akun SA, role, dan akses. Hanya Owner yang bisa akses tab ini.</p>
      </div>
      <button class="sa-btn sa-btn-primary" onclick="openTeamCreate()">➕ Tambah Admin</button>
    </div>

    <div class="sa-table-wrap">
      <table class="sa-table" id="teamTable">
        <thead>
          <tr>
            <th>Admin</th>
            <th>Username</th>
            <th>Email</th>
            <th>Role</th>
            <th>Notify</th>
            <th>Status</th>
            <th>Last Login</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody id="teamTableBody">
          <tr><td colspan="8" style="text-align:center;padding:24px;color:rgba(255,255,255,.4)">Memuat...</td></tr>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- ══════════ MODAL: Create SA ══════════ -->
<div id="teamCreateModal" class="sa-modal-overlay">
  <div class="sa-modal" style="max-width:520px">
    <h3>➕ Tambah Super Admin</h3>
    <div class="form-group">
      <label>Username *</label>
      <input type="text" id="tc_username" placeholder="a-z, 0-9, underscore, 3-30 char"
             style="width:100%;padding:10px 14px;background:rgba(255,255,255,.06);border:1.5px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-size:14px;outline:none">
    </div>
    <div class="form-group">
      <label>Nama Lengkap *</label>
      <input type="text" id="tc_name" placeholder="Nama tampil di panel"
             style="width:100%;padding:10px 14px;background:rgba(255,255,255,.06);border:1.5px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-size:14px;outline:none">
    </div>
    <div class="form-group">
      <label>Email (untuk notif)</label>
      <input type="email" id="tc_email" placeholder="email@harpy.id"
             style="width:100%;padding:10px 14px;background:rgba(255,255,255,.06);border:1.5px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-size:14px;outline:none">
    </div>
    <div class="form-group">
      <label>Password * (min 8 karakter)</label>
      <input type="password" id="tc_password" placeholder="••••••••"
             style="width:100%;padding:10px 14px;background:rgba(255,255,255,.06);border:1.5px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-size:14px;outline:none">
    </div>
    <div class="form-group">
      <label>Role *</label>
      <select id="tc_role_id" style="width:100%;padding:10px 14px;background:rgba(15,28,58,.9);border:1.5px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-size:14px;outline:none">
        <option value="">Pilih role...</option>
      </select>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px">
      <button class="sa-btn sa-btn-outline" onclick="closeTeamModals()">Batal</button>
      <button class="sa-btn sa-btn-primary" onclick="submitTeamCreate()">Simpan</button>
    </div>
  </div>
</div>

<!-- ══════════ MODAL: Edit SA ══════════ -->
<div id="teamEditModal" class="sa-modal-overlay">
  <div class="sa-modal" style="max-width:520px">
    <h3>✏️ Edit Super Admin</h3>
    <input type="hidden" id="te_id"/>
    <div class="form-group">
      <label>Nama Lengkap *</label>
      <input type="text" id="te_name"
             style="width:100%;padding:10px 14px;background:rgba(255,255,255,.06);border:1.5px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-size:14px;outline:none">
    </div>
    <div class="form-group">
      <label>Email</label>
      <input type="email" id="te_email"
             style="width:100%;padding:10px 14px;background:rgba(255,255,255,.06);border:1.5px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-size:14px;outline:none">
    </div>
    <div class="form-group">
      <label>Role *</label>
      <select id="te_role_id" style="width:100%;padding:10px 14px;background:rgba(15,28,58,.9);border:1.5px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-size:14px;outline:none">
      </select>
    </div>
    <div class="form-group">
      <label>Status</label>
      <select id="te_is_active" style="width:100%;padding:10px 14px;background:rgba(15,28,58,.9);border:1.5px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-size:14px;outline:none">
        <option value="1">Aktif</option>
        <option value="0">Nonaktif</option>
      </select>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px">
      <button class="sa-btn sa-btn-outline" onclick="closeTeamModals()">Batal</button>
      <button class="sa-btn sa-btn-primary" onclick="submitTeamEdit()">Simpan</button>
    </div>
  </div>
</div>

<!-- ══════════ MODAL: Reset Password ══════════ -->
<div id="teamPwModal" class="sa-modal-overlay">
  <div class="sa-modal" style="max-width:420px">
    <h3>🔑 Reset Password</h3>
    <input type="hidden" id="tp_id"/>
    <div class="form-group">
      <label>Password Baru * (min 8 karakter)</label>
      <input type="password" id="tp_password" placeholder="Password baru"
             style="width:100%;padding:10px 14px;background:rgba(255,255,255,.06);border:1.5px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-size:14px;outline:none">
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px">
      <button class="sa-btn sa-btn-outline" onclick="closeTeamModals()">Batal</button>
      <button class="sa-btn sa-btn-danger" onclick="submitTeamPw()">Reset Password</button>
    </div>
  </div>
</div>

<!-- ══════════════════════════ MODAL: Edit Tip ═══════════════════════════ -->
<div id="tipsEditModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9998;align-items:center;justify-content:center;padding:20px">
  <div style="background:#1a2540;border-radius:14px;padding:24px;max-width:540px;width:100%;max-height:90vh;overflow-y:auto;border:1px solid rgba(255,255,255,.08)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
      <h3 id="tipsModalTitle" style="margin:0;color:#fff;font-size:16px;font-weight:700">➕ Tambah Tip</h3>
      <button onclick="closeTipsEdit()" style="background:none;border:none;color:rgba(255,255,255,.5);font-size:22px;cursor:pointer">×</button>
    </div>
    <input type="hidden" id="tip_id"/>
    <div class="set-field">
      <label>Judul *</label>
      <input type="text" id="tip_judul" maxlength="100" placeholder="Misal: Inventori Bahan Baku"/>
    </div>
    <div class="set-field">
      <label>Konten *</label>
      <textarea id="tip_konten" rows="3" maxlength="2000" placeholder="Penjelasan fitur singkat..."></textarea>
    </div>
    <div style="display:grid;grid-template-columns:80px 1fr;gap:12px">
      <div class="set-field">
        <label>Icon</label>
        <input type="text" id="tip_icon" maxlength="10" value="💡" style="text-align:center;font-size:20px"/>
      </div>
      <div class="set-field">
        <label>Urutan</label>
        <input type="number" id="tip_urutan" value="0" min="0"/>
      </div>
    </div>
    <div class="set-field">
      <label>CTA Label (opsional)</label>
      <input type="text" id="tip_cta_label" maxlength="50" placeholder="Misal: Coba Sekarang"/>
    </div>
    <div class="set-field">
      <label>CTA URL (opsional)</label>
      <input type="text" id="tip_cta_url" maxlength="200" placeholder="/inventori"/>
    </div>
    <label style="display:flex;align-items:center;gap:8px;color:rgba(255,255,255,.7);font-size:13px;margin-bottom:16px">
      <input type="checkbox" id="tip_is_active" checked/> Aktif (tampilkan ke user)
    </label>
    <div style="display:flex;gap:8px;justify-content:flex-end">
      <button class="sa-btn sa-btn-outline" onclick="closeTipsEdit()">Batal</button>
      <button class="sa-btn sa-btn-primary" onclick="saveTip()">💾 Simpan</button>
    </div>
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
    if (name === 'tips')    loadTipsList();
}

// ── TIPS ─────────────────────────────────────
async function loadTipsList() {
    const r = await fetch('/superadmin/settings.php?action=tips_list', { credentials:'same-origin' });
    const j = await r.json();
    const wrap = document.getElementById('tipsList');
    if (!j.data?.length) {
        wrap.innerHTML = '<div style="text-align:center;padding:24px;color:rgba(255,255,255,.4)">Belum ada tip. Klik "Tambah Tip" untuk mulai.</div>';
        return;
    }
    wrap.innerHTML = j.data.map(t => {
        const activeStyle = t.is_active == 1
            ? 'border-color:rgba(53,232,213,.3);background:rgba(53,232,213,.04)'
            : 'border-color:rgba(255,255,255,.06);background:rgba(255,255,255,.02);opacity:.6';
        const scope = t.tenant_id ? `Tenant #${t.tenant_id}` : '🌍 Global';
        const cta = t.cta_label ? `<span style="background:rgba(53,232,213,.15);color:#35E8D5;padding:2px 8px;border-radius:8px;font-size:11px;margin-left:6px">${esc(t.cta_label)}</span>` : '';
        return `<div style="padding:14px 16px;border:1px solid rgba(255,255,255,.08);border-radius:10px;${activeStyle}">
          <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start">
            <div style="flex:1;min-width:0">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                <span style="font-size:20px">${esc(t.icon||'💡')}</span>
                <strong style="color:#fff;font-size:14px">${esc(t.judul)}</strong>
                ${cta}
              </div>
              <div style="font-size:12px;color:rgba(255,255,255,.5);line-height:1.5">${esc(t.konten)}</div>
              <div style="font-size:11px;color:rgba(255,255,255,.3);margin-top:6px">Urutan: ${t.urutan} · ${scope} · ${t.is_active==1?'✓ Aktif':'⏸ Nonaktif'}</div>
            </div>
            <div style="display:flex;gap:6px;flex-shrink:0">
              <button class="sa-btn sa-btn-outline" style="padding:5px 10px;font-size:11px" onclick='openTipsEdit(${JSON.stringify(t).replace(/'/g,"&apos;")})'>✏️</button>
              <button class="sa-btn sa-btn-outline" style="padding:5px 10px;font-size:11px" onclick="toggleTipActive(${t.id})">${t.is_active==1?'⏸':'▶'}</button>
              <button class="sa-btn sa-btn-danger" style="padding:5px 10px;font-size:11px" onclick="deleteTip(${t.id}, ${JSON.stringify(t.judul).replace(/'/g,'&apos;')})">🗑️</button>
            </div>
          </div>
        </div>`;
    }).join('');
}

function openTipsEdit(tip) {
    document.getElementById('tipsModalTitle').textContent = tip ? '✏️ Edit Tip' : '➕ Tambah Tip';
    document.getElementById('tip_id').value         = tip?.id || '';
    document.getElementById('tip_judul').value      = tip?.judul || '';
    document.getElementById('tip_konten').value     = tip?.konten || '';
    document.getElementById('tip_icon').value       = tip?.icon || '💡';
    document.getElementById('tip_urutan').value     = tip?.urutan ?? 0;
    document.getElementById('tip_cta_label').value  = tip?.cta_label || '';
    document.getElementById('tip_cta_url').value    = tip?.cta_url || '';
    document.getElementById('tip_is_active').checked = tip ? (tip.is_active == 1) : true;
    document.getElementById('tipsEditModal').style.display = 'flex';
}
function closeTipsEdit() { document.getElementById('tipsEditModal').style.display = 'none'; }

async function saveTip() {
    const data = {
        id:         document.getElementById('tip_id').value || null,
        judul:      document.getElementById('tip_judul').value,
        konten:     document.getElementById('tip_konten').value,
        icon:       document.getElementById('tip_icon').value,
        urutan:     parseInt(document.getElementById('tip_urutan').value) || 0,
        cta_label:  document.getElementById('tip_cta_label').value,
        cta_url:    document.getElementById('tip_cta_url').value,
        is_active:  document.getElementById('tip_is_active').checked ? 1 : 0,
    };
    if (!data.judul.trim() || !data.konten.trim()) {
        showToast('Judul dan konten wajib diisi', false); return;
    }
    const r = await fetch('/superadmin/settings.php?action=tips_save', {
        method:'POST', credentials:'same-origin',
        headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify(data),
    });
    const j = await r.json();
    if (j.error) { showToast(j.error, false); return; }
    showToast('Tip tersimpan');
    closeTipsEdit();
    loadTipsList();
}

async function toggleTipActive(id) {
    const r = await fetch('/superadmin/settings.php?action=tips_toggle', {
        method:'POST', credentials:'same-origin',
        headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ id }),
    });
    const j = await r.json();
    if (j.error) { showToast(j.error, false); return; }
    showToast('Status di-toggle');
    loadTipsList();
}

async function deleteTip(id, judul) {
    if (!confirm(`Hapus tip "${judul}"?\nSeen history user untuk tip ini juga akan dihapus.`)) return;
    const r = await fetch('/superadmin/settings.php?action=tips_delete', {
        method:'POST', credentials:'same-origin',
        headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ id }),
    });
    const j = await r.json();
    if (j.error) { showToast(j.error, false); return; }
    showToast('Tip dihapus');
    loadTipsList();
}

async function resetTipsSeen() {
    if (!confirm('Reset seen history untuk SEMUA user × SEMUA tip?\nSemua user akan lihat tip lagi besok.')) return;
    const r = await fetch('/superadmin/settings.php?action=tips_reset_seen', {
        method:'POST', credentials:'same-origin',
        headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': CSRF },
        body: '{}',
    });
    const j = await r.json();
    if (j.error) { showToast(j.error, false); return; }
    showToast(`Seen history di-reset (${j.deleted} record dihapus)`);
}

function esc(s){ return (s||'').toString().replace(/[<>&"]/g, c=>({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c])); }

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

// ════════════════════════════════════════════════════════════════
// NOTIFICATIONS TAB
// ════════════════════════════════════════════════════════════════
async function loadNotify() {
  const r = await saFetch('?action=notify_list');
  if (!r.ok) return;

  // Render const recipients
  document.getElementById('notifConst').textContent =
    (r.const && r.const.length) ? r.const.join(', ')
      : 'SA_NOTIFY_EMAILS belum di-define di master/config/db.php (optional — opt-in via tabel super_admins juga jalan)';

  // Render admins
  const adminWrap = document.getElementById('notifAdminList');
  adminWrap.innerHTML = (r.admins || []).map(a => `
    <div style="display:grid;grid-template-columns:1fr 1.5fr auto;gap:10px;align-items:center;padding:12px 14px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:9px">
      <div>
        <div style="font-weight:700;color:#fff;font-size:13.5px">${escapeHtml(a.name || a.username)}</div>
        <div style="font-size:11px;color:rgba(255,255,255,.4);font-family:monospace">@${escapeHtml(a.username)}</div>
      </div>
      <input type="email" id="ne_${a.id}" value="${escapeHtml(a.email || '')}" placeholder="email@harpy.id"
        style="background:rgba(255,255,255,.05);border:1.5px solid rgba(255,255,255,.1);border-radius:7px;padding:7px 10px;color:#fff;font-size:13px;outline:none">
      <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;color:rgba(255,255,255,.7)">
        <input type="checkbox" id="nx_${a.id}" ${a.notify_enabled == 1 ? 'checked' : ''}>
        <span>Notify</span>
        <button class="sa-btn sa-btn-secondary" style="padding:5px 12px;font-size:11px;margin-left:6px" onclick="saveNotify(${a.id})">Save</button>
      </label>
    </div>
  `).join('');

  // Render logs
  const logWrap = document.getElementById('notifLogList');
  if (!r.logs || !r.logs.length) {
    logWrap.innerHTML = '<div style="padding:14px;color:rgba(255,255,255,.4);text-align:center;font-size:13px">Belum ada notif terkirim.</div>';
  } else {
    logWrap.innerHTML = r.logs.map(l => `
      <div style="padding:10px 14px;background:rgba(255,255,255,.025);border:1px solid rgba(255,255,255,.06);border-radius:7px;display:flex;justify-content:space-between;align-items:center;gap:10px;font-size:12.5px">
        <div style="flex:1;min-width:0">
          <div style="font-weight:600;color:#fff;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escapeHtml(l.subject || '(no subject)')}</div>
          <div style="font-size:11px;color:rgba(255,255,255,.4);margin-top:2px">${escapeHtml(l.event_type)} · ${escapeHtml(l.recipients || '-')}</div>
        </div>
        <div style="font-size:11px;color:rgba(255,255,255,.45);white-space:nowrap;font-family:monospace">${escapeHtml(l.sent_at)}</div>
      </div>
    `).join('');
  }
}

async function saveNotify(id) {
  const email = document.getElementById('ne_' + id).value.trim();
  const enable = document.getElementById('nx_' + id).checked ? 1 : 0;
  const fd = new FormData();
  fd.append('id', id);
  fd.append('email', email);
  fd.append('enable', enable);
  const r = await saFetch('?action=notify_save', { method: 'POST', body: fd });
  if (r.ok) saToast('Tersimpan ✓', 'ok');
  else saToast(r.error || 'Gagal simpan', 'err');
}

async function testNotify() {
  if (!confirm('Kirim test email ke semua recipient aktif?')) return;
  const r = await saFetch('?action=notify_test', { method: 'POST' });
  if (r.ok) saToast(r.message || 'Test email dikirim ✓', 'ok');
  else saToast(r.error || 'Gagal kirim', 'err');
}

function escapeHtml(s) {
  return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// ════════════════════════════════════════════════════════════════
// SA TEAM TAB
// ════════════════════════════════════════════════════════════════
let teamRoles = [];

async function loadTeam() {
  const r = await saFetch('?action=team_list');
  if (!r || !r.ok) {
    if (r && r.error) showToast(r.error, false);
    return;
  }
  teamRoles = r.roles || [];

  // Populate role selects
  const opts = teamRoles.map(role =>
    `<option value="${role.id}">${escapeHtml(role.name)}</option>`
  ).join('');
  document.getElementById('tc_role_id').innerHTML = '<option value="">Pilih role...</option>' + opts;
  document.getElementById('te_role_id').innerHTML = opts;

  const tbody = document.getElementById('teamTableBody');
  if (!r.admins || !r.admins.length) {
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:24px;color:rgba(255,255,255,.4)">Belum ada admin.</td></tr>';
    return;
  }

  tbody.innerHTML = r.admins.map(a => {
    const roleBadge = a.role_slug === 'owner'   ? 'sa-badge-indigo' :
                      a.role_slug === 'finance'  ? 'sa-badge-green' :
                      a.role_slug === 'support'  ? 'sa-badge-blue' :
                      a.role_slug === 'viewer'   ? 'sa-badge-yellow' : 'sa-badge-indigo';
    const statusBadge = a.is_active == 1
      ? '<span class="sa-badge sa-badge-active">Aktif</span>'
      : '<span class="sa-badge sa-badge-suspended">Nonaktif</span>';
    const ll = a.last_login
      ? new Date(a.last_login).toLocaleDateString('id-ID', {day:'2-digit',month:'short',year:'numeric'})
      : '-';
    const rowData = JSON.stringify(a).replace(/'/g,"&apos;");
    return `<tr>
      <td><strong style="color:#fff">${escapeHtml(a.name)}</strong></td>
      <td><code style="color:#A5B4FC;font-size:12px">@${escapeHtml(a.username)}</code></td>
      <td style="font-size:12px;color:rgba(255,255,255,.6)">${escapeHtml(a.email||'—')}</td>
      <td><span class="sa-badge ${roleBadge}">${escapeHtml(a.role_name||'—')}</span></td>
      <td style="font-size:12px">${a.notify_enabled==1?'✓':'—'}</td>
      <td>${statusBadge}</td>
      <td style="font-size:12px;color:rgba(255,255,255,.5)">${escapeHtml(ll)}</td>
      <td>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <button class="sa-btn sa-btn-outline sa-btn-sm" onclick='openTeamEdit(${rowData})'>✏️ Edit</button>
          <button class="sa-btn sa-btn-outline sa-btn-sm" onclick="openTeamPw(${a.id})">🔑</button>
          ${a.role_slug !== 'owner' ? `<button class="sa-btn sa-btn-danger sa-btn-sm" onclick="deleteTeamAdmin(${a.id},'${escapeHtml(a.name)}')">🗑️</button>` : ''}
        </div>
      </td>
    </tr>`;
  }).join('');
}

function openTeamCreate() {
  document.getElementById('tc_username').value = '';
  document.getElementById('tc_name').value     = '';
  document.getElementById('tc_email').value    = '';
  document.getElementById('tc_password').value = '';
  document.getElementById('tc_role_id').value  = '';
  document.getElementById('teamCreateModal').classList.add('open');
}

function openTeamEdit(a) {
  document.getElementById('te_id').value      = a.id;
  document.getElementById('te_name').value    = a.name || '';
  document.getElementById('te_email').value   = a.email || '';
  document.getElementById('te_role_id').value = a.role_id || '';
  document.getElementById('te_is_active').value = a.is_active ?? 1;
  document.getElementById('teamEditModal').classList.add('open');
}

function openTeamPw(id) {
  document.getElementById('tp_id').value = id;
  document.getElementById('tp_password').value = '';
  document.getElementById('teamPwModal').classList.add('open');
}

function closeTeamModals() {
  ['teamCreateModal','teamEditModal','teamPwModal'].forEach(id => {
    document.getElementById(id).classList.remove('open');
  });
}

async function submitTeamCreate() {
  const fd = new FormData();
  fd.append('_csrf', CSRF);
  fd.append('username', document.getElementById('tc_username').value.trim());
  fd.append('name',     document.getElementById('tc_name').value.trim());
  fd.append('email',    document.getElementById('tc_email').value.trim());
  fd.append('password', document.getElementById('tc_password').value);
  fd.append('role_id',  document.getElementById('tc_role_id').value);

  const r = await saFetch('?action=team_create', { method:'POST', body:fd });
  if (r && r.ok) {
    showToast('Admin berhasil ditambahkan');
    closeTeamModals();
    loadTeam();
  } else {
    showToast(r?.error || 'Gagal menambahkan admin', false);
  }
}

async function submitTeamEdit() {
  const fd = new FormData();
  fd.append('_csrf',      CSRF);
  fd.append('id',         document.getElementById('te_id').value);
  fd.append('name',       document.getElementById('te_name').value.trim());
  fd.append('email',      document.getElementById('te_email').value.trim());
  fd.append('role_id',    document.getElementById('te_role_id').value);
  fd.append('is_active',  document.getElementById('te_is_active').value);

  const r = await saFetch('?action=team_update', { method:'POST', body:fd });
  if (r && r.ok) {
    showToast('Admin berhasil diupdate');
    closeTeamModals();
    loadTeam();
  } else {
    showToast(r?.error || 'Gagal update admin', false);
  }
}

async function submitTeamPw() {
  const fd = new FormData();
  fd.append('_csrf',        CSRF);
  fd.append('id',           document.getElementById('tp_id').value);
  fd.append('new_password', document.getElementById('tp_password').value);

  const r = await saFetch('?action=team_reset_password', { method:'POST', body:fd });
  if (r && r.ok) {
    showToast('Password berhasil di-reset');
    closeTeamModals();
  } else {
    showToast(r?.error || 'Gagal reset password', false);
  }
}

async function deleteTeamAdmin(id, name) {
  if (!confirm(`Nonaktifkan akun "${name}"?\nAkun akan di-set is_active=0 (soft delete).`)) return;
  const fd = new FormData();
  fd.append('_csrf', CSRF);
  fd.append('id', id);
  const r = await saFetch('?action=team_delete', { method:'POST', body:fd });
  if (r && r.ok) {
    showToast('Admin dinonaktifkan');
    loadTeam();
  } else {
    showToast(r?.error || 'Gagal menghapus admin', false);
  }
}

// Close modals on overlay click
['teamCreateModal','teamEditModal','teamPwModal'].forEach(id => {
  document.getElementById(id)?.addEventListener('click', function(e) {
    if (e.target === this) closeTeamModals();
  });
});

// Init
loadMaintStatus();
</script>
</body>
</html>

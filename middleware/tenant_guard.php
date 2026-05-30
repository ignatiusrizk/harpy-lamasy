<?php
// ══════════════════════════════════════════════════════
// middleware/tenant_guard.php
// Include di baris PERTAMA setiap halaman operasional.
//
// Usage:
//   define('ROOT', dirname(__DIR__));   // sesuaikan jika berbeda
//   require_once ROOT . '/middleware/tenant_guard.php';
//
//   // Setelah ini langsung bisa pakai:
//   TenantQuery::fetch('hl_transaksi', 'status_proses = ?', ['masuk'])
//   CoinLedger::deduct('send_wa_notif')
//   TenantResolver::id()          // tenant_id saat ini
//   TenantResolver::namaOutlet()  // nama outlet
//   currentUser()                 // data user yang login
//   hasPermission('orders.edit')  // cek permission
// ══════════════════════════════════════════════════════

// ── Session security (sebelum session_start) ──────────
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure',   1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Security headers ──────────────────────────────────
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// ── Load config & core ────────────────────────────────
if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__));
}
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';
require_once ROOT . '/core/TenantResolver.php';
require_once ROOT . '/core/TenantQuery.php';
require_once ROOT . '/core/CoinLedger.php';

// ── Maintenance Mode Check ─────────────────────────────
// Baca dari file cache (performa — tidak query DB setiap request)
(function () {
    $cacheFile = ROOT . '/storage/maintenance.json';
    if (!file_exists($cacheFile)) return;

    $cfg = json_decode(@file_get_contents($cacheFile), true) ?: [];
    if (empty($cfg['active'])) return;

    // Whitelist: superadmin paths & maintenance page sendiri
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (str_starts_with($uri, '/superadmin/') ||
        str_starts_with($uri, '/maintenance')  ||
        str_starts_with($uri, '/logout')) {
        return;
    }

    // Whitelist IP (superadmin bisa tetap akses)
    $whitelistIPs = $cfg['whitelist_ips'] ?? [];
    if (in_array($_SERVER['REMOTE_ADDR'] ?? '', $whitelistIPs, true)) return;

    // AJAX → JSON error
    if (!empty($_GET['action']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Sistem sedang maintenance. Silakan coba beberapa saat lagi.', 'maintenance' => true]);
        exit;
    }

    header('Location: /maintenance');
    exit;
})();

// ── Cek login ─────────────────────────────────────────
if (empty($_SESSION['user_id']) || empty($_SESSION['tenant_id'])) {
    if (!empty($_GET['action']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Sesi habis. Silakan login kembali.', 'redirect' => '/login']);
    } else {
        header('Location: /login?msg=session_expired');
    }
    exit;
}

// ── Cross-redirect: mitra TIDAK boleh akses outlet pages ──
// (brief acceptance #3 & #9)
if (($_SESSION['role'] ?? '') === 'mitra') {
    if (!empty($_GET['action']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Akses ditolak — Anda mitra drop point.', 'redirect' => '/droppoint/dashboard.php']);
    } else {
        header('Location: /droppoint/dashboard.php');
    }
    exit;
}

// ── Resolve & validasi tenant ─────────────────────────
TenantResolver::resolve();

// ── Session timeout ───────────────────────────────────
$_now = time();
if (isset($_SESSION['hl_last_activity'])) {
    if ($_now - $_SESSION['hl_last_activity'] > SESSION_TIMEOUT) {
        session_destroy();
        if (!empty($_GET['action']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Sesi habis. Silakan login kembali.', 'redirect' => '/login']);
        } else {
            header('Location: /login?msg=session_expired');
        }
        exit;
    }
}
if (isset($_SESSION['hl_login_time'])) {
    if ($_now - $_SESSION['hl_login_time'] > SESSION_LIFETIME) {
        session_destroy();
        header('Location: /login?msg=session_expired');
        exit;
    }
}
$_SESSION['hl_last_activity'] = $_now;

// ── ToS Version Check (skip demo & impersonation) ─────
// Hanya halaman penuh (bukan AJAX, bukan accept-tos sendiri)
if (empty($_SESSION['is_demo']) &&
    empty($_SESSION['impersonating_tenant_id']) &&
    empty($_GET['action']) &&
    empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    $currentScript = $_SERVER['PHP_SELF'] ?? '';
    $tosAllowed    = ['/accept-tos.php','/logout.php','/tos.php','/privacy.php'];
    if (!in_array($currentScript, $tosAllowed, true)) {
        try {
            $_currentTosVer = Database::get()->query(
                "SELECT version FROM saas_tos_versions WHERE is_current=1 LIMIT 1"
            )->fetchColumn();

            // Ambil tos_version tenant (cache di session agar tidak query setiap request)
            if (!isset($_SESSION['_tenant_tos_ver'])) {
                $_tenantTosVer = Database::get()->prepare(
                    "SELECT tos_version FROM tenants WHERE id=? LIMIT 1"
                );
                $_tenantTosVer->execute([(int)$_SESSION['tenant_id']]);
                $_SESSION['_tenant_tos_ver'] = $_tenantTosVer->fetchColumn() ?: null;
            }

            if ($_currentTosVer && $_SESSION['_tenant_tos_ver'] !== $_currentTosVer) {
                header('Location: /accept-tos');
                exit;
            }
        } catch (Throwable) {
            // Non-fatal — jangan block akses jika tabel belum ada
        }
    }
}

// ── Demo Mode: block aksi write tertentu ───────────────
if (!empty($_SESSION['is_demo'])) {
    define('DEMO_MODE', true);
    // Track page views
    if (empty($_GET['action']) && empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        $_SESSION['demo_pages_viewed'] = ($_SESSION['demo_pages_viewed'] ?? 0) + 1;
    }
    // Block aksi yang merusak atau tidak relevan di demo
    $blockedDemoActions = [
        'delete_pelanggan','delete_karyawan','delete_layanan',
        'update_settings','topup_coin','export_data',
        'delete_order','purge','reset_data',
    ];
    $_demoAction = $_POST['action'] ?? $_GET['action'] ?? '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_demoAction, $blockedDemoActions, true)) {
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Fitur ini tidak tersedia di mode demo.',
            'demo'  => true,
            'cta'   => 'Daftar sekarang untuk akses penuh!',
        ]);
        exit;
    }
}

// ── Anomaly Detector + Daily Report (1x per 30 menit per session) ──
// Pseudo-cron: skip AJAX supaya tidak nambah latency response.
if (empty($_GET['action']) && empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    if (!isset($_SESSION['last_anomaly_check']) || ($_now - $_SESSION['last_anomaly_check']) > 1800) {
        $_SESSION['last_anomaly_check'] = $_now;
        try {
            if (TenantResolver::hasOutlet()) {
                $_tid = (int)TenantResolver::id();
                $_oid = (int)TenantResolver::outletId();
                require_once ROOT . '/core/AnomalyDetector.php';
                AnomalyDetector::check($_tid, $_oid);
                require_once ROOT . '/core/DailyReport.php';
                DailyReport::maybeSend($_tid, $_oid);
                require_once ROOT . '/core/SegmentasiManager.php';
                SegmentasiManager::updateAll($_tid, $_oid);
            }
        } catch (Throwable $e) { error_log('[pseudocron] '.$e->getMessage()); }
    }
}

// ════════════════════════════════════════
// HELPER FUNCTIONS
// ════════════════════════════════════════

// ── User yang sedang login ────────────────────────────
function currentUser(): ?array
{
    return $_SESSION['hl_user'] ?? null;
}

// ── Cek permission ────────────────────────────────────
function hasPermission(string $kode): bool
{
    // Delegasi ke TenantResolver::can() supaya alias permission konsisten
    if (class_exists('TenantResolver') && method_exists('TenantResolver', 'can')) {
        return TenantResolver::can($kode);
    }
    // Fallback kalau resolver belum loaded
    $perms = $_SESSION['hl_permissions'] ?? [];
    if (isset($perms['*'])) return true;
    $role = $_SESSION['hl_user']['role'] ?? '';
    if (in_array($role, ['owner','superadmin'], true)) return true;
    return isset($perms[$kode]);
}

function requirePermission(string $kode): void
{
    if (!hasPermission($kode)) {
        if (!empty($_GET['action']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Akses ditolak — permission tidak cukup.']);
        } else {
            http_response_code(403);
            die('<div style="font-family:sans-serif;padding:40px;text-align:center;background:#0F1C3A;color:#fff;min-height:100vh">
                <h2 style="color:#35E8D5">🔒 Akses Ditolak</h2>
                <p style="color:rgba(255,255,255,.6)">Anda tidak memiliki izin untuk halaman ini.</p>
                <a href="javascript:history.back()" style="color:#35E8D5">← Kembali</a>
            </div>');
        }
        exit;
    }
}

// ── Observer / Impersonation mode ────────────────────
// Blokir semua POST ketika superadmin sedang mengobservasi (read-only).
// Dipanggil otomatis di sini sehingga seluruh halaman tenants terlindungi.
if (!empty($_SESSION['impersonating_tenant_id'])
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && empty($_GET['action'] === '__bypass__')) // tidak ada bypass — ini hanya dokumentasi
{
    // Izinkan hanya request JSON agar halaman AJAX mendapat pesan jelas
    if (!empty($_GET['action']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Observer mode: operasi tulis tidak diizinkan.', 'observer' => true]);
    } else {
        http_response_code(403);
        $adminName = htmlspecialchars($_SESSION['impersonation_admin_name'] ?? 'Superadmin');
        die('<div style="font-family:sans-serif;padding:40px;text-align:center;background:#0F1C3A;color:#fff;min-height:100vh">
            <h2 style="color:#F59E0B">🔍 Observer Mode</h2>
            <p style="color:rgba(255,255,255,.6);max-width:380px;margin:0 auto 24px">
              Sesi ini diobservasi oleh <strong>' . $adminName . '</strong>.<br>
              Operasi tulis tidak diizinkan selama mode observasi.
            </p>
            <a href="/superadmin/stop_impersonate.php" style="background:#6366F1;color:#fff;padding:12px 28px;border-radius:8px;font-weight:700;text-decoration:none">
              🚪 Akhiri Observasi
            </a>
        </div>');
    }
    exit;
}

// ── Grace mode: blokir operasi tulis ─────────────────
// Dipanggil di action handler yang memodifikasi data.
// Di grace period, user hanya boleh baca (view), tidak bisa create/update/delete.
function requireNotGrace(string $message = ''): void
{
    if (!TenantResolver::isGraceMode()) return;

    $daysLeft = TenantResolver::graceDaysLeft();
    $msg = $message ?: "Outlet dalam grace period ($daysLeft hari tersisa). "
                     . "Operasi ini tidak tersedia. Aktifkan outlet untuk melanjutkan.";

    if (!empty($_GET['action']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $msg, 'grace_mode' => true]);
    } else {
        http_response_code(403);
        die('<div style="font-family:sans-serif;padding:40px;text-align:center;background:#0F1C3A;color:#fff;min-height:100vh">
            <h2 style="color:#F59E0B">⏰ Grace Period</h2>
            <p style="color:rgba(255,255,255,.6);max-width:380px;margin:0 auto 24px">' . htmlspecialchars($msg) . '</p>
            <a href="/billing.php" style="background:#35E8D5;color:#0F1C3A;padding:12px 28px;border-radius:8px;font-weight:700;text-decoration:none">Aktifkan Outlet</a>
            &nbsp;
            <a href="javascript:history.back()" style="color:#35E8D5;margin-left:12px">← Kembali</a>
        </div>');
    }
    exit;
}

// ── Shorthand DB (untuk backward compat) ─────────────
function getDB(): PDO
{
    return Database::get();
}

// ── Info tenant saat ini ──────────────────────────────
function currentTenant(): array
{
    return TenantResolver::get();
}

// ── Info outlet saat ini ──────────────────────────────
function currentOutlet(): array
{
    return TenantResolver::getOutlet();
}

// ── Coin balance (dari session, tanpa query DB) ────────
function tenantCoinBalance(): int
{
    return (int) ($_SESSION['tenant_coin_balance'] ?? 0);
}

// ── Audit log ─────────────────────────────────────────
function logAudit(
    string  $aksi,
    string  $modul,
    string  $keterangan = '',
    ?string $refId      = null
): void {
    $user = currentUser();
    try {
        TenantQuery::insert('hl_audit_log', [
            'user_id'    => $user['id']        ?? null,
            'user_nama'  => $user['nama']      ?? null,
            'user_role'  => $user['role_nama'] ?? $user['role'] ?? null,
            'modul'      => $modul,
            'aksi'       => $aksi,
            'keterangan' => $keterangan,
            'ref_id'     => $refId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '-', 0, 255),
        ]);
    } catch (Throwable) {
        // Jangan gagalkan request karena audit gagal
    }
}

// ── CSRF ──────────────────────────────────────────────
function getCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void
{
    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(getCsrfToken(), $token)) {
        http_response_code(403);
        if (!empty($_GET['action']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'CSRF token tidak valid.']);
        } else {
            die('Request tidak valid (CSRF).');
        }
        exit;
    }
}

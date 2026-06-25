<?php
// ══════════════════════════════════════════════════════
// middleware/hq_guard.php — Guard untuk halaman HQ view
//
// HQ view = mode konsolidasi lintas outlet (kantor pusat).
// Berbeda dengan tenant_guard (yang scope-nya outlet aktif).
//
// Usage:
//   define('ROOT', dirname(__DIR__));
//   require_once ROOT . '/middleware/hq_guard.php';
//
// Yang di-provide:
//   $hqTenant   — data tenant aktif
//   $hqUser     — data user yang login
//   hqCsrf()    — token CSRF
//   verifyHqCsrf()
//   currentTenant() — alias agar components.php tetap kompatibel
//   currentUser()
// ══════════════════════════════════════════════════════

// ── Timezone: WIB Jakarta ─────────────────────────────
date_default_timezone_set('Asia/Jakarta');

// ── Session security ──────────────────────────────────
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure',   1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_lifetime', 86400); // tetap login ~1 hari (refresh cookie tiap page)
ini_set('session.gc_maxlifetime',  86400);

if (session_status() === PHP_SESSION_NONE) session_start();

// ── Security headers ──────────────────────────────────
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/master/config/db.php';
require_once ROOT . '/core/Database.php';
require_once ROOT . '/core/TenantResolver.php';

// ── Auth check ────────────────────────────────────────
if (empty($_SESSION['user_id']) || empty($_SESSION['tenant_id'])) {
    if ((!empty($_GET['action']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) && ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '') !== 'navigate') {
        header('Content-Type: application/json');
        echo json_encode(['error'=>'Sesi habis. Silakan login kembali.', 'redirect'=>'/login']);
    } else {
        header('Location: /login?msg=not_logged_in');
    }
    exit;
}

// ── Session timeout (sama dengan tenant_guard) ────────
$_now = time();
if (isset($_SESSION['hl_last_activity'])
    && defined('SESSION_TIMEOUT')
    && ($_now - $_SESSION['hl_last_activity'] > SESSION_TIMEOUT)) {
    session_destroy();
    if ((!empty($_GET['action']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) && ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '') !== 'navigate') {
        header('Content-Type: application/json');
        echo json_encode(['error'=>'Sesi habis. Silakan login kembali.', 'redirect'=>'/login']);
    } else {
        header('Location: /login?msg=session_expired');
    }
    exit;
}
if (isset($_SESSION['hl_login_time'])
    && defined('SESSION_LIFETIME')
    && ($_now - $_SESSION['hl_login_time'] > SESSION_LIFETIME)) {
    session_destroy();
    header('Location: /login?msg=session_expired');
    exit;
}
$_SESSION['hl_last_activity'] = $_now;

$db = Database::get();

// ── Load tenant ───────────────────────────────────────
$_stmt = $db->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
$_stmt->execute([$_SESSION['tenant_id']]);
$hqTenant = $_stmt->fetch();

if (!$hqTenant) {
    session_destroy();
    header('Location: /login?error=tenant_not_found');
    exit;
}

// ── Tenant status check ───────────────────────────────
if ($hqTenant['status'] === 'pending_verification') {
    header('Location: /pending-verify');
    exit;
}

if (in_array($hqTenant['status'], ['suspended', 'closed'])) {
    header('Location: /account-suspended');
    exit;
}

// ── Akses HQ: via permission hq.access ATAU role string fallback (F1 RBAC v2) ──
$hqUser = $_SESSION['hl_user'] ?? [];
$hqRole = $hqUser['role'] ?? '';

if (!TenantResolver::canAccessHqV2()) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:60px;text-align:center;background:#0F1C3A;color:#fff;min-height:100vh">
        <h2 style="color:#35E8D5">🔒 Akses HQ Ditolak</h2>
        <p style="color:rgba(255,255,255,.6)">HQ view hanya untuk Owner & Manager.</p>
        <a href="/dashboard" style="color:#35E8D5">← Kembali ke Dashboard</a>
    </div>');
}

// ── Set HQ mode flag di session ───────────────────────
$_SESSION['hq_mode'] = true;

// ── Permission helpers untuk HQ (brief 3.2 & 6.8) ─────
// Manager scope: bisa akses HQ tapi bukan owner — terbatas (tidak boleh
// billing, manage outlets, role settings sensitif). Post-F1: $hqIsManager
// = siapapun yang punya hq.access permission tapi role string-nya bukan
// owner/superadmin. Ini mencakup role custom yang dibuat owner via /hq/roles.
$hqIsOwner   = in_array($hqRole, ['owner','superadmin'], true);
$hqIsManager = !$hqIsOwner && TenantResolver::canAccessHqV2();
$hqCanBilling      = $hqIsOwner;   // topup, coin mode, paket, settings password
$hqCanManageOutlet = $hqIsOwner;   // tambah outlet, edit outlet, set main
$hqCanManageRole   = $hqIsOwner;   // create/edit/delete role
$hqCanViewAudit    = true;          // owner + manager boleh lihat audit

// ── Helpers (compatible dengan components.php) ────────
if (!function_exists('currentUser')) {
    function currentUser(): ?array {
        return $_SESSION['hl_user'] ?? null;
    }
}
if (!function_exists('currentTenant')) {
    function currentTenant(): array {
        global $hqTenant;
        return $hqTenant ?? [];
    }
}
if (!function_exists('getCsrfToken')) {
    function getCsrfToken(): string {
        if (empty($_SESSION['hl_csrf'])) {
            $_SESSION['hl_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['hl_csrf'];
    }
}
if (!function_exists('verifyCsrf')) {
    function verifyCsrf(): void {
        $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals($_SESSION['hl_csrf'] ?? '', $token)) {
            http_response_code(403);
            die('CSRF mismatch');
        }
    }
}

// HQ pages tidak melewati tenant_guard, jadi `hasPermission()`/`requirePermission()`
// dan `logAudit()` perlu di-stub. Konsultasi `$_SESSION['hl_permissions']` yang
// sudah di-load oleh `loadPermissions()` di login.php — sumber kebenaran sama
// dengan tenant_guard/TenantResolver::can().
//
// Owner role di-bypass shortcut karena seed sudah grant semua perm + safety net
// untuk permission baru yang ditambah post-provisioning (migrasi backfill kadang
// belum jalan di semua tenant).
if (!function_exists('hasPermission')) {
    function hasPermission(string $kode): bool {
        global $hqIsOwner;
        if (!empty($hqIsOwner)) return true;
        $perms = $_SESSION['hl_permissions'] ?? [];
        return isset($perms['*']) || isset($perms[$kode]);
    }
}
if (!function_exists('requirePermission')) {
    function requirePermission(string $kode): void {
        if (hasPermission($kode)) return;
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || !empty($_GET['action'])) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Akses ditolak — permission tidak cukup.']);
        } else {
            http_response_code(403);
            echo 'Akses ditolak — permission tidak cukup.';
        }
        exit;
    }
}
if (!function_exists('logAudit')) {
    function logAudit(string $aksi, string $modul, string $ket = ''): void {
        // HQ context tidak punya audit log per-outlet — silent no-op.
    }
}
if (!function_exists('requireNotGrace')) {
    function requireNotGrace(string $message = ''): void {
        global $hqTenant;
        if (($hqTenant['status'] ?? '') === 'grace') {
            header('Content-Type: application/json');
            echo json_encode(['error' => $message ?: 'Akun dalam masa tenggang (grace). Perbarui paket terlebih dahulu.']);
            exit;
        }
    }
}

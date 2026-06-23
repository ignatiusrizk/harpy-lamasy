<?php
// ══════════════════════════════════════════════════════
// superadmin/middleware/superadmin_guard.php
// Auth guard khusus Super Admin — terpisah dari tenant auth
// ══════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', dirname(__DIR__));
require_once SA_ROOT . '/../master/config/db.php';
require_once SA_ROOT . '/../core/Database.php';
require_once SA_ROOT . '/../core/ErrorLogger.php';
require_once SA_ROOT . '/../core/WaLogger.php';
require_once SA_ROOT . '/../core/PlatformHealthRecorder.php';
require_once SA_ROOT . '/../core/SaPermission.php';

// ══════════════════════════════════════════════════════
// LAYER 0 — HTTP Basic Auth (second factor sebelum login PHP)
// Set SA_BASIC_USER dan SA_BASIC_PASS di master/config/db.php
// Tanpa kedua konstanta ini, basic auth dilewati (backward compat).
// ══════════════════════════════════════════════════════
(function () {
    $requiredUser = defined('SA_BASIC_USER') ? SA_BASIC_USER : '';
    $requiredPass = defined('SA_BASIC_PASS') ? SA_BASIC_PASS : '';

    // Lewati jika belum dikonfigurasi
    if ($requiredUser === '' || $requiredPass === '') return;

    // PHP-FPM: Authorization header tidak otomatis diparsing.
    // superadmin/.htaccess meneruskan header via RewriteRule.
    if (!isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $parts = explode(' ', $_SERVER['HTTP_AUTHORIZATION'], 2);
        if (isset($parts[1]) && strtolower($parts[0]) === 'basic') {
            [$u, $p] = explode(':', base64_decode($parts[1]), 2) + ['', ''];
            $_SERVER['PHP_AUTH_USER'] = $u;
            $_SERVER['PHP_AUTH_PW']   = $p ?? '';
        }
    }

    $givenUser = $_SERVER['PHP_AUTH_USER'] ?? '';
    $givenPass = $_SERVER['PHP_AUTH_PW']   ?? '';

    $ok = hash_equals($requiredUser, $givenUser)
       && hash_equals(hash('sha256', $requiredPass), hash('sha256', $givenPass));

    if (!$ok) {
        header('WWW-Authenticate: Basic realm="LAMASY Internal"');
        http_response_code(401);
        // Kembalikan 404-like body agar scanner tidak tahu path ini ada
        exit('Not Found');
    }
})();

// ── Session security ──────────────────────────────────
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure',   1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);

if (session_status() === PHP_SESSION_NONE) session_start();

// ── Security headers ──────────────────────────────────
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// Rekam snapshot harian (best-effort, sekali per hari)
PlatformHealthRecorder::recordYesterdayIfNeeded();

if (!isset($_SESSION['superadmin_id'])) {
    if (!empty($_GET['action']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Sesi habis.', 'redirect' => '/superadmin/login.php']);
    } else {
        header('Location: /superadmin/login.php');
    }
    exit;
}

// ── Load perm cache into session (once per request) ──
if (isset($_SESSION['superadmin_id']) && !array_key_exists('sa_perms', $_SESSION)) {
    SaPermission::loadIntoSession((int)$_SESSION['superadmin_id']);
}

function saCurrentAdmin(): array {
    return $_SESSION['sa_user'] ?? [];
}

function logSuperAdminAction(string $action, ?int $tenantId, string $desc): void {
    try {
        Database::get()->prepare(
            "INSERT INTO superadmin_logs (superadmin_id, action, target_tenant_id, description, ip_address)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([
            $_SESSION['superadmin_id'],
            $action,
            $tenantId,
            $desc,
            $_SERVER['REMOTE_ADDR'] ?? '-'
        ]);
    } catch (Throwable $e) {
        if (class_exists('ErrorLogger')) ErrorLogger::logException('sa_audit', $e);
    }
}

function saGetCsrf(): string {
    if (empty($_SESSION['sa_csrf'])) {
        $_SESSION['sa_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['sa_csrf'];
}

function saVerifyCsrf(): void {
    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(saGetCsrf(), $token)) {
        if (!empty($_GET['action']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'CSRF invalid.']);
        } else {
            die('Request tidak valid.');
        }
        exit;
    }
}

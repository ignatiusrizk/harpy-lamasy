<?php
// ══════════════════════════════════════════════════════
// core/Database.php — Single PDO connection manager
// Semua tabel (platform + operasional) dalam 1 database:
// DB_NAME = u269895997_harpy_master
// ══════════════════════════════════════════════════════

// ── Hardening keamanan: jangan tampilkan error PHP ke output (cegah kebocoran
//    path/SQL/schema ke client); tetap dicatat ke log server. ──
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

// Helper: balas error API generik ke client + catat detail ke log server.
// Ganti pola lama `echo json_encode(['error'=>$e->getMessage()])` yang membocorkan
// pesan exception (mis. SQLSTATE/nama kolom) ke pemanggil.
if (!function_exists('apiErr')) {
    function apiErr(Throwable $e, string $msg = 'Terjadi kesalahan sistem. Silakan coba lagi.'): void {
        error_log('[apiErr] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        echo json_encode(['error' => $msg]);
    }
}

class Database
{
    private static ?PDO $conn = null;

    // ── Satu-satunya koneksi yang dipakai seluruh app ──
    public static function get(): PDO
    {
        if (self::$conn === null) {
            self::$conn = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        }
        return self::$conn;
    }

    // ── Reset koneksi (untuk CLI scripts / testing) ───
    public static function reset(): void
    {
        self::$conn = null;
    }
}

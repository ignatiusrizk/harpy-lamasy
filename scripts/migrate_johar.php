<?php
// ══════════════════════════════════════════════════════
// scripts/migrate_johar.php — One-time migration
// Pindahkan data Harpy Johar dari DB lama ke DB baru
//
// Source : u269895997_Laundry_Masuk  (DB lama, single-tenant)
// Target : u269895997_harpy_master   (DB baru, multi-tenant)
// Tenant : tenant_id = 1 (Harpy Johar)
//
// CARA JALANKAN:
//   Via CLI (aman, tanpa timeout):
//     php scripts/migrate_johar.php
//
//   Via browser (dengan token di URL):
//     https://domain.com/harpy/scripts/migrate_johar.php?token=GANTI_TOKEN_INI
//
// PENTING:
//   1. Backup kedua DB sebelum jalankan
//   2. Jalankan SEKALI saja
//   3. Hapus atau block akses file ini setelah selesai
//   4. Data existing di target (tenant_id=1) akan DIHAPUS dulu
// ══════════════════════════════════════════════════════

define('ROOT', dirname(__DIR__));
require_once ROOT . '/master/config/db.php';

// ══════════════════════════════════════════════════════
// CREDENTIALS — ISI SEBELUM JALANKAN
// Cek di /public_html/ERP/config/ (atau file koneksi ERP lama)
// ══════════════════════════════════════════════════════
define('SRC_HOST', 'localhost');
define('SRC_DB',   'u269895997_Laundry_Masuk');
define('SRC_USER', 'GANTI_USER_DB_LAMA');   // ← cek di config ERP
define('SRC_PASS', 'GANTI_PASS_DB_LAMA');   // ← cek di config ERP

// Target pakai credentials dari db.php (sudah ter-load di atas)
define('TGT_HOST', DB_HOST);
define('TGT_DB',   DB_NAME);   // u269895997_harpy_master
define('TGT_USER', DB_USER);
define('TGT_PASS', DB_PASS);
// ══════════════════════════════════════════════════════

// ── Security ──────────────────────────────────────────
$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    $token = $_GET['token'] ?? '';
    if ($token !== 'GANTI_TOKEN_INI') {
        http_response_code(403);
        die('Akses ditolak.');
    }
}

set_time_limit(600);
ini_set('memory_limit', '256M');

const TENANT_ID  = 1;
const SOURCE_DB  = 'u269895997_Laundry_Masuk';
const TARGET_DB  = 'u269895997_harpy_master';

out('══════════════════════════════════════════');
out('  Harpy Johar Migration Script');
out('  Source : ' . SOURCE_DB);
out('  Target : ' . TARGET_DB . ' (tenant_id=' . TENANT_ID . ')');
out('══════════════════════════════════════════');

// ── Koneksi ───────────────────────────────────────────
function connectDb(string $host, string $db, string $user, string $pass): PDO
{
    return new PDO(
        "mysql:host={$host};dbname={$db};charset=utf8mb4",
        $user, $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
}

try {
    $src = connectDb(SRC_HOST, SRC_DB, SRC_USER, SRC_PASS);
    out('✅ Koneksi source OK (' . SRC_DB . ')');
} catch (Exception $e) {
    die('❌ Koneksi SOURCE gagal: ' . $e->getMessage() . "\n"
      . "   Cek SRC_USER dan SRC_PASS di bagian atas script.\n");
}

try {
    $tgt = connectDb(TGT_HOST, TGT_DB, TGT_USER, TGT_PASS);
    out('✅ Koneksi target OK (' . TGT_DB . ')');
} catch (Exception $e) {
    die('❌ Koneksi TARGET gagal: ' . $e->getMessage() . "\n"
      . "   Cek DB_USER dan DB_PASS di master/config/db.php.\n");
}

$tgt->exec('SET FOREIGN_KEY_CHECKS = 0');
$src->exec('SET FOREIGN_KEY_CHECKS = 0');

// ── Mapping: nama tabel lama → nama tabel baru ────────
// Jika nama tabel sama di source dan target, tulis satu kali.
// Jika berbeda, tulis ['source_table' => 'target_table']
//
// SESUAIKAN dengan nama tabel di DB lama kamu.
// Cek via: SHOW TABLES di phpMyAdmin pada SOURCE_DB
$tableMap = [
    // [source_table => target_table]
    'hl_roles'            => 'hl_roles',
    'hl_permissions'      => 'hl_permissions',
    'hl_role_permissions' => 'hl_role_permissions',
    'hl_users'            => 'hl_users',
    'hl_pelanggan'        => 'hl_pelanggan',
    'hl_layanan'          => 'hl_layanan',
    'hl_transaksi'        => 'hl_transaksi',
    'hl_transaksi_item'   => 'hl_transaksi_item',
    'hl_kas'              => 'hl_kas',
    'hl_absensi'          => 'hl_absensi',
    'hl_izin'             => 'hl_izin',
    'hl_gaji'             => 'hl_gaji',
    'hl_promo'            => 'hl_promo',
    'hl_voucher'          => 'hl_voucher',
    'hl_audit_log'        => 'hl_audit_log',
];

// Kolom yang di-skip per tabel (tidak ada di target)
$skipCols = [
    // contoh: 'hl_users' => ['old_column_name']
];

$results = [];
$errors  = [];

// ── Proses per tabel ──────────────────────────────────
foreach ($tableMap as $srcTable => $tgtTable) {
    out("\n--- {$srcTable} → {$tgtTable} ---");

    // Cek apakah tabel ada di source
    $exists = $src->query("SHOW TABLES LIKE '{$srcTable}'")->fetch();
    if (!$exists) {
        out("⚠️  Tidak ada di source, skip.");
        continue;
    }

    // Ambil kolom yang ada di TARGET
    $tgtColsRaw = $tgt->query("SHOW COLUMNS FROM `{$tgtTable}`")->fetchAll();
    $tgtCols    = array_column($tgtColsRaw, 'Field');

    // Ambil semua data dari source
    $rows = $src->query("SELECT * FROM `{$srcTable}`")->fetchAll();

    if (empty($rows)) {
        out("   (kosong, skip)");
        $results[$srcTable] = 0;
        continue;
    }

    // Hapus data lama di target untuk tenant ini
    $deleted = $tgt->exec("DELETE FROM `{$tgtTable}` WHERE tenant_id = " . TENANT_ID);
    out("   🗑  Hapus {$deleted} rows lama di target");

    // Tentukan kolom yang akan di-copy
    $srcCols   = array_keys($rows[0]);
    $skipList  = $skipCols[$srcTable] ?? [];
    $availCols = array_diff(
        array_intersect($srcCols, $tgtCols),
        $skipList,
        ['tenant_id']   // exclude — kita inject sendiri
    );
    $availCols = array_values($availCols);

    if (empty($availCols)) {
        out("⚠️  Tidak ada kolom yang cocok, skip.");
        continue;
    }

    // Tambahkan tenant_id ke kolom yang akan di-insert
    $insertCols   = array_merge(['tenant_id'], $availCols);
    $colList      = implode(',', array_map(fn($c) => "`{$c}`", $insertCols));
    $placeholder  = implode(',', array_fill(0, count($insertCols), '?'));
    $stmt         = $tgt->prepare("INSERT INTO `{$tgtTable}` ({$colList}) VALUES ({$placeholder})");

    $count  = 0;
    $failed = 0;
    foreach ($rows as $row) {
        $values = array_merge(
            [TENANT_ID],
            array_map(fn($c) => $row[$c] ?? null, $availCols)
        );
        try {
            $stmt->execute($values);
            $count++;
        } catch (Exception $e) {
            $failed++;
            if ($failed <= 5) {
                out("   ⚠️  Row gagal: " . $e->getMessage());
            }
        }
    }

    $results[$srcTable] = $count;
    out("   ✅ {$count} rows migrated" . ($failed > 0 ? ", ⚠️ {$failed} gagal" : ''));
}

$tgt->exec('SET FOREIGN_KEY_CHECKS = 1');
$src->exec('SET FOREIGN_KEY_CHECKS = 1');

// ── Verifikasi tenant di target ───────────────────────
out("\n--- Verifikasi tenant ---");
$tenant = $tgt->query("SELECT * FROM tenants WHERE id = " . TENANT_ID)->fetch();
if ($tenant) {
    out("✅ Tenant OK: {$tenant['nama_outlet']} | status: {$tenant['status']} | coin: {$tenant['coin_balance']}");
} else {
    out("❌ Tenant id=" . TENANT_ID . " tidak ditemukan di target!");
}

// ── Summary ───────────────────────────────────────────
out("\n══════════════════════════════════════════");
out("  MIGRATION SUMMARY");
out("══════════════════════════════════════════");
foreach ($results as $table => $count) {
    out(str_pad($table, 28) . ": {$count} rows");
}
if (!empty($errors)) {
    out("\nErrors:");
    foreach ($errors as $e) out("  ✗ {$e}");
}
out("\n✅ Selesai!");
out("⚠️  Verifikasi data di phpMyAdmin sebelum go-live.");
out("⚠️  Hapus atau block akses file ini setelah selesai.");

// ── Helper ────────────────────────────────────────────
function out(string $msg): void
{
    if (php_sapi_name() === 'cli') {
        echo $msg . "\n";
    } else {
        echo nl2br(htmlspecialchars($msg)) . "<br>\n";
        ob_flush();
        flush();
    }
}

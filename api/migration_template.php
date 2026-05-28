<?php
// ══════════════════════════════════════════════════════
// api/migration_template.php
// Download template CSV per entitas.
// Bisa diakses tenant (dengan auth) maupun superadmin.
//
// GET /api/migration_template.php?entity=pelanggan
// ══════════════════════════════════════════════════════

define('ROOT', dirname(__DIR__));

// Auth minimal — cukup session valid
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id']) && empty($_SESSION['superadmin_id'])) {
    http_response_code(401);
    die('Unauthorized');
}

$entity = strtolower(trim($_GET['entity'] ?? ''));

$templates = [
    'layanan' => [
        'filename' => 'template_layanan_lamasy.csv',
        'headers'  => ['nama', 'harga', 'satuan', 'kategori', 'keterangan'],
        'sample'   => [
            ['Kiloan Reguler', '7000', 'kg', 'Kiloan', ''],
            ['Cuci Sprei', '8000', 'kg', 'Sprei', ''],
            ['Bedcover', '30000', 'pcs', 'Bedcover', 'Per lembar'],
            ['Express 6 Jam', '12000', 'kg', 'Express', 'Selesai dalam 6 jam'],
        ],
        'notes' => [
            '# PETUNJUK PENGISIAN TEMPLATE LAYANAN',
            '# - nama    : nama layanan/paket (wajib)',
            '# - harga   : harga satuan dalam Rupiah, angka saja tanpa titik/koma (wajib)',
            '# - satuan  : kg / pcs / item / lembar (opsional, default: kg)',
            '# - kategori: pengelompokan layanan, bebas (opsional)',
            '# - keterangan: keterangan tambahan (opsional)',
            '# Hapus baris komentar (#) dan baris contoh sebelum upload.',
            '#',
        ],
    ],

    'pelanggan' => [
        'filename' => 'template_pelanggan_lamasy.csv',
        'headers'  => ['nama', 'telepon', 'alamat', 'tipe_bayar', 'catatan'],
        'sample'   => [
            ['Bu Sari Dewi', '08121234567', 'Jl. Mawar No.5 RT.02/03', 'langsung', 'Suka parfum lavender'],
            ['Pak Budi', '08129876543', '', 'bulanan', ''],
            ['Ibu Ani Santoso', '08135551234', 'Perum Griya Indah B-12', 'langsung', 'Antar jemput'],
        ],
        'notes' => [
            '# PETUNJUK PENGISIAN TEMPLATE PELANGGAN',
            '# - nama      : nama lengkap pelanggan (wajib)',
            '# - telepon   : nomor HP/WA format 08xx (wajib, untuk identitas unik)',
            '# - alamat    : alamat lengkap (opsional)',
            '# - tipe_bayar: langsung / bulanan (opsional, default: langsung)',
            '# - catatan   : preferensi atau catatan khusus (opsional)',
            '# File dari Smartlink/iLaundry/Excel bisa langsung diupload — AI akan mapping otomatis.',
            '#',
        ],
    ],

    'karyawan' => [
        'filename' => 'template_karyawan_lamasy.csv',
        'headers'  => ['nama', 'telepon', 'role', 'gaji_pokok', 'tgl_masuk'],
        'sample'   => [
            ['Melati Sari', '08121111111', 'kasir', '2500000', '2024-01-15'],
            ['Budi Santoso', '08122222222', 'staff', '1800000', '2024-03-01'],
            ['Rina Wijaya', '08133333333', 'staff', '1800000', '2024-06-10'],
        ],
        'notes' => [
            '# PETUNJUK PENGISIAN TEMPLATE KARYAWAN',
            '# - nama      : nama karyawan (wajib)',
            '# - telepon   : nomor HP/WA (opsional)',
            '# - role      : kasir / staff / manager / admin / owner (opsional)',
            '# - gaji_pokok: gaji pokok per bulan dalam Rupiah (opsional)',
            '# - tgl_masuk : tanggal mulai kerja format YYYY-MM-DD (opsional)',
            '# Password default semua karyawan hasil import: lamasy123 (wajib diganti)',
            '#',
        ],
    ],

    'transaksi' => [
        'filename' => 'template_transaksi_lamasy.csv',
        'headers'  => ['nama_pelanggan', 'telepon', 'nama_layanan', 'berat_kg', 'total', 'tanggal', 'metode_bayar', 'catatan'],
        'sample'   => [
            ['Bu Sari', '08121234567', 'Kiloan Reguler', '3.5', '24500', '2024-01-15', 'cash', ''],
            ['Pak Budi', '08129876543', 'Cuci Sprei', '2', '16000', '2024-01-15', 'transfer', 'BCA'],
            ['Ibu Ani', '08135551234', 'Express 6 Jam', '1.5', '18000', '2024-01-16', 'cash', ''],
        ],
        'notes' => [
            '# PETUNJUK PENGISIAN TEMPLATE TRANSAKSI',
            '# - nama_pelanggan: nama pelanggan (wajib)',
            '# - telepon       : nomor HP/WA (opsional, untuk match pelanggan)',
            '# - nama_layanan  : nama layanan yang dibeli (wajib)',
            '# - berat_kg      : berat dalam kg, pakai titik untuk desimal (opsional)',
            '# - total         : total harga Rupiah, angka saja (wajib)',
            '# - tanggal       : tanggal transaksi format YYYY-MM-DD (wajib)',
            '# - metode_bayar  : cash / transfer / qris dll (opsional)',
            '# - catatan       : catatan order (opsional)',
            '# Semua transaksi import otomatis ditandai sebagai histori (sudah selesai).',
            '#',
        ],
    ],

    'poin_pelanggan' => [
        'filename' => 'template_poin_pelanggan_lamasy.csv',
        'headers'  => ['telepon', 'nama_pelanggan', 'saldo_poin'],
        'sample'   => [
            ['08121234567', 'Bu Sari', '1250'],
            ['08129876543', 'Pak Budi', '800'],
            ['08135551234', 'Ibu Ani', '3400'],
        ],
        'notes' => [
            '# PETUNJUK PENGISIAN TEMPLATE POIN PELANGGAN',
            '# - telepon        : nomor HP/WA pelanggan (wajib, harus sudah ada di sistem)',
            '# - nama_pelanggan : nama pelanggan (opsional, sebagai fallback jika WA tidak cocok)',
            '# - saldo_poin     : jumlah poin yang akan ditambahkan (wajib)',
            '# PENTING: Import poin hanya bisa dilakukan SETELAH import data pelanggan.',
            '# Pelanggan yang tidak ditemukan di sistem akan di-skip.',
            '#',
        ],
    ],
];

if (!isset($templates[$entity])) {
    http_response_code(400);
    die('Entitas tidak valid. Gunakan: ' . implode(', ', array_keys($templates)));
}

$tpl = $templates[$entity];

// Output CSV
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $tpl['filename'] . '"');
header('Cache-Control: no-cache, no-store');

// BOM untuk Excel agar baca UTF-8 dengan benar
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// Baris komentar petunjuk
foreach ($tpl['notes'] as $note) {
    fputcsv($out, [$note]);
}

// Header kolom
fputcsv($out, $tpl['headers']);

// Sample data
foreach ($tpl['sample'] as $row) {
    fputcsv($out, $row);
}

fclose($out);
exit;

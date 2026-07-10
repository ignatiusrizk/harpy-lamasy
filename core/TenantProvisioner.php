<?php
// ══════════════════════════════════════════════════════
// core/TenantProvisioner.php — Daftarkan tenant baru
// Single-database approach: tidak CREATE DATABASE,
// cukup INSERT ke tabel tenants + seed data default
// ══════════════════════════════════════════════════════

if (!class_exists('FinancialCalculator')) {
    require_once __DIR__ . '/FinancialCalculator.php';
}

class TenantProvisioner
{
    // ── Provision tenant baru ─────────────────────────
    // Return: ['success'=>true, 'tenant_id'=>int, 'slug'=>string, 'password'=>string]
    //      OR ['success'=>false, 'error'=>string]
    public static function provision(array $data): array
    {
        $slug = self::generateSlug($data['nama_outlet']);
        $db   = Database::get();

        $db->beginTransaction();
        try {
            // ── Step 1: Insert tenant ─────────────────
            $trialEnd = date('Y-m-d H:i:s', strtotime('+14 days'));
            $db->prepare("
                INSERT INTO tenants
                  (slug, nama_perusahaan, owner_name, owner_wa,
                   status, coin_balance, trial_ends_at, provisioned_at)
                VALUES (?, ?, ?, ?, 'trial', 50000, ?, NOW())
            ")->execute([
                $slug,
                $data['nama_perusahaan'] ?? $data['nama_outlet'] ?? '',
                $data['owner_name'] ?? '',
                $data['owner_wa']   ?? '',
                $trialEnd,
            ]);
            $tenantId = (int) $db->lastInsertId();

            // ── Step 2: Seed roles default ────────────
            $roleIds = self::seedRoles($db, $tenantId);

            // ── Step 3: Seed permissions + mapping ────
            self::seedPermissions($db, $tenantId, $roleIds);

            // ── Step 4: Layanan default TIDAK di-seed di sini ──
            // provision() belum punya outlet, sedangkan hl_layanan.outlet_id wajib
            // (default kolom 1 = outlet orang lain → layanan tak tampil di POS).
            // Layanan di-seed saat outlet dibuat (add-outlet → ServiceCatalog),
            // atau panggil self::seedLayanan($db, $tenantId, $outletId) eksplisit.

            // ── Step 5: Buat user owner ───────────────
            $tempPassword = self::generatePassword();
            $db->prepare("
                INSERT INTO hl_users
                  (tenant_id, username, password, nama, role, role_id, is_active, created_at)
                VALUES (?, ?, ?, ?, 'superadmin', ?, 1, NOW())
            ")->execute([
                $tenantId,
                self::generateUsername($data['owner_name'] ?? $slug),
                password_hash($tempPassword, PASSWORD_BCRYPT),
                $data['owner_name'] ?? 'Owner',
                $roleIds['owner'],
            ]);

            $db->commit();

            // ── Step 6: Kirim WA selamat datang ───────
            self::sendWelcomeWA($data['owner_wa'] ?? '', [
                'nama'     => $data['owner_name'] ?? 'Owner',
                'outlet'   => $data['nama_outlet'],
                'url'      => APP_URL . '/login',
                'password' => $tempPassword,
                'trial'    => '14 hari',
            ]);

            return [
                'success'   => true,
                'tenant_id' => $tenantId,
                'slug'      => $slug,
                'password'  => $tempPassword,
            ];

        } catch (Throwable $e) {
            $db->rollBack();
            self::logError($data, $e->getMessage());
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    // ── Suspend tenant ────────────────────────────────
    public static function suspend(int $tenantId, string $reason = ''): void
    {
        Database::get()->prepare(
            "UPDATE tenants SET status = 'suspended' WHERE id = ?"
        )->execute([$tenantId]);
    }

    // ── Aktifkan tenant ───────────────────────────────
    public static function activate(int $tenantId): void
    {
        Database::get()->prepare(
            "UPDATE tenants SET status = 'active' WHERE id = ?"
        )->execute([$tenantId]);
    }

    // ── Internal: seed roles ──────────────────────────
    /**
     * Public: seed roles + permissions default untuk tenant baru.
     * Dipanggil dari register.php (self-registration) supaya owner punya
     * role_id terhubung ke hl_roles, dan role lain (admin/kasir/karyawan)
     * siap di-assign tanpa setup manual.
     *
     * @return int|null role_id 'owner' kalau berhasil seed, null kalau gagal.
     */
    public static function seedDefaultsForTenant(PDO $db, int $tenantId): ?int
    {
        try {
            // Cek apakah sudah pernah di-seed (idempotent)
            $check = $db->prepare("SELECT id FROM hl_roles WHERE tenant_id=? AND nama='Owner' LIMIT 1");
            $check->execute([$tenantId]);
            $existingOwner = $check->fetchColumn();
            if ($existingOwner) return (int)$existingOwner;

            $roleIds = self::seedRoles($db, $tenantId);
            self::seedPermissions($db, $tenantId, $roleIds);
            FinancialCalculator::seedCoa($db, $tenantId);
            return $roleIds['owner'] ?? null;
        } catch (Throwable $e) {
            error_log('[seedDefaultsForTenant] ' . $e->getMessage());
            return null;
        }
    }

    private static function seedRoles(PDO $db, int $tenantId): array
    {
        // 5 system role default. NOTE: 'manager' role di-check di beberapa
        // tempat (hq_guard.php, login.php, components.php) sebagai role
        // "HQ-limited" yang seharusnya bisa akses HQ tapi tidak bisa
        // billing/manage outlet. Tapi belum di-seed di sini — itu dead path
        // sampai feature manager benar-benar di-implement. Owner yang butuh
        // manager-style access sementara bisa bikin custom role via /hq/roles
        // dgn nama "Manager" + permission terbatas (string enum akan jadi
        // 'staff' per karyawan.php mapping, tapi permission table dipakai
        // untuk check sebenarnya post-F2 fix).
        $roles = [
            'owner'    => ['Owner',    'Akses penuh ke semua fitur',          1],
            'admin'    => ['Admin',    'Kelola order, kas, laporan, karyawan', 1],
            'kasir'    => ['Kasir',    'Input order & pembayaran saja',        1],
            'karyawan' => ['Karyawan', 'Absensi & update status order',        1],
            'kurir'    => ['Kurir',    'Akses /kurir untuk update antar jemput', 1],
        ];

        $stmt   = $db->prepare("INSERT INTO hl_roles (tenant_id, nama, deskripsi, is_system) VALUES (?,?,?,?)");
        $result = [];
        foreach ($roles as $key => [$nama, $desc, $sys]) {
            $stmt->execute([$tenantId, $nama, $desc, $sys]);
            $result[$key] = (int) $db->lastInsertId();
        }
        return $result;
    }

    // ── Internal: seed permissions & role mapping ─────
    private static function seedPermissions(PDO $db, int $tenantId, array $roleIds): void
    {
        $permissions = [
            ['pos.view',             'pos',       'view',          'Lihat halaman POS'],
            ['pos.create',           'pos',       'create',        'Buat order baru via POS'],
            ['orders.view_all',      'orders',    'view_all',      'Lihat semua order'],
            ['orders.view_own',      'orders',    'view_own',      'Lihat order milik sendiri'],
            ['orders.create',        'orders',    'create',        'Buat order baru'],
            ['orders.edit',          'orders',    'edit',          'Edit detail order'],
            ['orders.update_status', 'orders',    'update_status', 'Update status proses'],
            ['orders.bayar',         'orders',    'bayar',         'Update pembayaran order'],
            ['orders.delete',        'orders',    'delete',        'Hapus order'],
            ['kas.view',             'kas',       'view',          'Lihat halaman kas'],
            ['kas.create',           'kas',       'create',        'Input kas masuk/keluar'],
            ['kas.delete',           'kas',       'delete',        'Hapus entri kas'],
            ['inventori.view',       'inventori', 'view',          'Lihat stok & riwayat bahan baku'],
            ['inventori.manage',     'inventori', 'manage',        'Tambah/edit/hapus bahan & input mutasi stok'],
            ['mesin.view',           'mesin',     'view',          'Lihat status mesin self-service'],
            ['mesin.operate',        'mesin',     'operate',       'Konfirmasi mulai/selesai sesi mesin'],
            ['mesin.manage',         'mesin',     'manage',        'Tambah/edit/hapus mesin & atur cycle'],
            ['antar.view',           'antar',     'view',          'Lihat list antar jemput & report'],
            ['antar.manage',         'antar',     'manage',        'Create antar jemput, assign kurir, kelola master'],
            ['antar.kurir',          'antar',     'kurir',         'Akses /kurir mobile (untuk role kurir)'],
            ['laporan.view',         'laporan',   'view',          'Lihat laporan'],
            ['laporan.export',       'laporan',   'export',        'Export laporan'],
            ['karyawan.view',        'karyawan',  'view',          'Lihat data karyawan'],
            ['karyawan.create',      'karyawan',  'create',        'Tambah karyawan'],
            ['karyawan.edit',        'karyawan',  'edit',          'Edit data karyawan'],
            ['karyawan.delete',      'karyawan',  'delete',        'Hapus karyawan'],
            ['karyawan.gaji',        'karyawan',  'gaji',          'Kelola penggajian'],
            ['bonus_rule.manage',    'bonus_rule','manage',        'Kelola master bonus & penalti rule (HQ)'],
            ['absensi.view',         'absensi',   'view',          'Lihat data absensi'],
            ['absensi.clock',        'absensi',   'clock',         'Clock in/out'],
            ['absensi.approve',      'absensi',   'approve',       'Approve izin karyawan'],
            ['pelanggan.view',       'pelanggan', 'view',          'Lihat data pelanggan'],
            ['pelanggan.create',     'pelanggan', 'create',        'Tambah pelanggan'],
            ['pelanggan.edit',       'pelanggan', 'edit',          'Edit pelanggan'],
            ['layanan.view',         'layanan',   'view',          'Lihat katalog layanan'],
            ['layanan.create',       'layanan',   'create',        'Tambah layanan'],
            ['layanan.edit',         'layanan',   'edit',          'Edit layanan'],
            ['layanan.delete',       'layanan',   'delete',        'Hapus layanan'],
            ['promo.view',           'promo',     'view',          'Lihat promo & voucher'],
            ['promo.create',         'promo',     'create',        'Buat promo baru'],
            ['promo.edit',           'promo',     'edit',          'Edit promo existing'],
            ['promo.delete',         'promo',     'delete',        'Hapus promo'],
            ['settings.roles',       'settings',  'roles',         'Kelola role & permission'],
            ['settings.outlet',      'settings',  'outlet',        'Edit info outlet'],
            ['audit.view',           'audit',     'view',          'Lihat audit log'],
            ['keuangan.view',        'keuangan',  'view',          'Lihat data keuangan formal (aset, pinjaman, kas bank, jurnal)'],
            ['keuangan.edit',        'keuangan',  'edit',          'Kelola data keuangan formal (HQ)'],
            ['export.data',          'export',    'data',          'Download data tenant (orders, customers, gaji, dll) ke ZIP CSV'],
            ['hq.access',            'hq',        'access',        'Akses halaman HQ view (konsolidasi multi-outlet)'],
            // Bantuan & Support
            ['bantuan.view',         'bantuan',   'view',          'Akses halaman Support & Tiket'],
            ['bantuan.submit',       'bantuan',   'submit',        'Kirim tiket support baru'],
            ['bantuan.reply',        'bantuan',   'reply',         'Balas tiket support'],
            ['bantuan.close',        'bantuan',   'close',         'Tutup & beri rating tiket'],
            // Investor & Bagi Hasil
            ['investor.manage',      'investor',  'manage',        'Kelola investor & bagi hasil'],
        ];

        $stmtPerm = $db->prepare(
            "INSERT INTO hl_permissions (tenant_id, kode, modul, aksi, deskripsi) VALUES (?,?,?,?,?)"
        );
        // Sertakan filter_data='all' agar permission muncul checked di UI settings
        $stmtMap  = $db->prepare(
            "INSERT INTO hl_role_permissions (tenant_id, role_id, permission_id, filter_data) VALUES (?,?,?,?)"
        );

        $ownerExclude   = [];
        // Admin default: ops manager scope. Tidak boleh ubah master katalog/marketing,
        // tidak boleh konfigurasi sistem, tidak boleh hapus karyawan.
        // Owner masih bisa custom via /hq/roles kalau butuh skema lain.
        $adminExclude   = [
            'settings.roles', 'audit.view', 'karyawan.delete',
            'layanan.create', 'layanan.edit', 'layanan.delete',
            'promo.create', 'promo.edit', 'promo.delete',
            'inventori.manage', 'mesin.manage',
            'bonus_rule.manage',
            'keuangan.edit',
            'export.data', // sensitif: data exfiltration risk
            'hq.access',   // sensitif: konsolidasi multi-outlet — owner-only by default
        ];
        $kasirInclude   = ['pos.view','pos.create','orders.view_all','orders.create',
                           'orders.update_status','orders.bayar','pelanggan.view',
                           'pelanggan.create','absensi.clock','absensi.view','layanan.view',
                           'mesin.view','mesin.operate','antar.view',
                           'bantuan.view','bantuan.submit','bantuan.reply','bantuan.close'];
        $karyawanInclude = ['absensi.clock','absensi.view','orders.view_own','orders.update_status',
                            'antar.view','bantuan.view','bantuan.submit','bantuan.reply','bantuan.close'];
        $kurirInclude   = ['antar.kurir'];

        foreach ($permissions as [$kode, $modul, $aksi, $desc]) {
            $stmtPerm->execute([$tenantId, $kode, $modul, $aksi, $desc]);
            $permId = (int) $db->lastInsertId();

            // Owner: semua
            $stmtMap->execute([$tenantId, $roleIds['owner'], $permId, 'all']);

            // Admin: semua kecuali daftar excluded
            if (!in_array($kode, $adminExclude)) {
                $stmtMap->execute([$tenantId, $roleIds['admin'], $permId, 'all']);
            }

            // Kasir: hanya yang included
            if (in_array($kode, $kasirInclude)) {
                $stmtMap->execute([$tenantId, $roleIds['kasir'], $permId, 'all']);
            }

            // Karyawan: hanya yang included
            if (in_array($kode, $karyawanInclude)) {
                $stmtMap->execute([$tenantId, $roleIds['karyawan'], $permId, 'all']);
            }

            // Kurir: hanya antar.kurir
            if (in_array($kode, $kurirInclude)) {
                $stmtMap->execute([$tenantId, $roleIds['kurir'], $permId, 'all']);
            }
        }
    }

    // ── Internal: seed layanan default ────────────────
    public static function seedLayanan(PDO $db, int $tenantId, int $outletId): void
    {
        $layanan = [
            ['Cuci + Kering Reguler',  'Reguler', 'kg',   5000,  1],
            ['Cuci + Kering Express',  'Express', 'kg',   8000,  2],
            ['Cuci + Setrika Reguler', 'Reguler', 'kg',   8000,  3],
            ['Cuci + Setrika Express', 'Express', 'kg',  12000,  4],
            ['Setrika Saja',           'Satuan',  'kg',   4000,  5],
            ['Cuci Saja',              'Satuan',  'kg',   4000,  6],
            ['Selimut / Bed Cover',    'Khusus',  'pcs', 25000,  7],
            ['Sepatu',                 'Khusus',  'pcs', 35000,  8],
            ['Tas',                    'Khusus',  'pcs', 30000,  9],
            ['Dry Cleaning Jas',       'Premium', 'pcs', 75000, 10],
        ];

        $stmt = $db->prepare(
            "INSERT INTO hl_layanan (tenant_id, outlet_id, nama, kategori, satuan, harga, urutan, created_at)
             VALUES (?,?,?,?,?,?,?,NOW())"
        );
        foreach ($layanan as [$nama, $kat, $sat, $harga, $urut]) {
            $stmt->execute([$tenantId, $outletId, $nama, $kat, $sat, $harga, $urut]);
        }
    }

    /**
     * Seed bahan baku default untuk outlet baru.
     * Stok awal = 0 (tenant isi sendiri), stok_minimum sesuai konvensi laundry kecil.
     *
     * @return int jumlah bahan yang ter-seed
     */
    public static function seedDefaultBahan(PDO $db, int $tenantId, int $outletId): int
    {
        $defaults = [
            // [nama, kategori, satuan, stok_minimum]
            ['Deterjen Bubuk',        'deterjen',         'kg',    5],
            ['Deterjen Cair',         'deterjen',         'liter', 5],
            ['Softener / Pelembut',   'pewangi',          'liter', 3],
            ['Parfum Laundry',        'parfum',           'pcs',   5],
            ['Plastik Kemasan S',     'plastik_kemasan',  'rol',   3],
            ['Plastik Kemasan L',     'plastik_kemasan',  'rol',   3],
            ['Hanger',                'peralatan',        'pcs',  20],
        ];

        $stmt = $db->prepare(
            "INSERT INTO hl_bahan (tenant_id, outlet_id, nama, kategori, satuan, stok_awal, stok_minimum, is_active)
             VALUES (?,?,?,?,?,0,?,1)"
        );
        $count = 0;
        foreach ($defaults as [$nama, $kat, $sat, $min]) {
            try {
                $stmt->execute([$tenantId, $outletId, $nama, $kat, $sat, $min]);
                $count++;
            } catch (Throwable) { /* skip duplikat / table belum ada */ }
        }
        return $count;
    }

    /**
     * Seed 3 default built-in payment methods untuk outlet baru.
     * Cash, Transfer, QRIS — all is_builtin=1, is_active=1.
     *
     * Idempotent: INSERT IGNORE skip kalau rows sudah ada (UNIQUE constraint).
     */
    public static function seedPaymentMethods(PDO $db, int $tenantId, int $outletId): void
    {
        $db->prepare("
            INSERT IGNORE INTO hl_payment_methods
                (tenant_id, outlet_id, code, label, emoji, is_builtin, is_active, sort_order)
            VALUES
                (?, ?, 'cash',     'Tunai',         '💵', 1, 1, 1),
                (?, ?, 'transfer', 'Transfer Bank', '🏦', 1, 1, 2),
                (?, ?, 'qris',     'QRIS',          '📱', 1, 1, 3)
        ")->execute([
            $tenantId, $outletId,
            $tenantId, $outletId,
            $tenantId, $outletId,
        ]);
    }

    // ── Internal helpers ──────────────────────────────
    private static function generateSlug(string $namaOutlet): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $namaOutlet));
        $slug = trim(preg_replace('/_+/', '_', $slug), '_');
        $base = $slug;
        $i    = 1;
        while (self::slugExists($slug)) {
            $slug = $base . '_' . $i++;
        }
        return $slug;
    }

    private static function slugExists(string $slug): bool
    {
        $stmt = Database::get()->prepare(
            "SELECT id FROM tenants WHERE slug = ? LIMIT 1"
        );
        $stmt->execute([$slug]);
        return (bool) $stmt->fetch();
    }

    private static function generateUsername(string $name): string
    {
        $parts = explode(' ', strtolower(trim($name)));
        $base  = implode('.', array_slice($parts, 0, 2));
        $base  = preg_replace('/[^a-z0-9.]/', '', $base) ?: 'owner';
        return substr($base, 0, 40);
    }

    private static function generatePassword(int $length = 10): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789!@#';
        $pw    = '';
        for ($i = 0; $i < $length; $i++) {
            $pw .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $pw;
    }

    private static function sendWelcomeWA(string $phone, array $info): void
    {
        $msg = "Halo {$info['nama']}! 👋\n\n"
             . "Selamat datang di *Harpy Laundry System* 🎉\n\n"
             . "Outlet: *{$info['outlet']}*\n"
             . "Login: {$info['url']}\n"
             . "Password: *{$info['password']}*\n\n"
             . "Trial gratis {$info['trial']} sudah aktif.\n"
             . "Saldo coin awal: *50.000 coin*\n\n"
             . "Segera ganti password setelah login pertama. 🔐";

        // Log untuk sekarang — ganti dengan WA API saat siap
        $logFile = ROOT . '/logs/wa_outbox.log';
        if (!is_dir(dirname($logFile))) {
            mkdir(dirname($logFile), 0755, true);
        }
        file_put_contents(
            $logFile,
            date('[Y-m-d H:i:s]') . " TO:{$phone}\n{$msg}\n---\n",
            FILE_APPEND
        );
    }

    private static function logError(array $data, string $error): void
    {
        $logFile = ROOT . '/logs/provision_errors.log';
        if (!is_dir(dirname($logFile))) {
            mkdir(dirname($logFile), 0755, true);
        }
        file_put_contents(
            $logFile,
            date('[Y-m-d H:i:s]') . ' FAILED: ' . json_encode($data) . "\nError: {$error}\n---\n",
            FILE_APPEND
        );
    }
}

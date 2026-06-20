<?php
// ══════════════════════════════════════════════════════
// core/SplashManager.php — Educational splash screen logic
//
// 3 skenario, prioritas:
//   1. onboarding  — first login, sampai 3 setup step done
//   2. whats_new   — saas_announcements type=fitur_baru
//   3. tips        — random 1x/hari, rotate semua tips
//
// Output: array yang siap di-render via renderSplash() di components.php
//   ['type' => 'onboarding', 'steps' => [...], 'completed' => N, 'total' => 3]
//   ['type' => 'whats_new',  'announcement' => [...]]
//   ['type' => 'tips',       'tip' => [...]]
//
// Dipanggil 1x per session di tenant_guard.php; hasilnya disimpan di
// $_SESSION['pending_splash'] dan di-clear setelah render.
// ══════════════════════════════════════════════════════

class SplashManager
{
    /** Cek splash mana yang perlu ditampilkan (1 per session) */
    public static function check(): ?array
    {
        if (empty($_SESSION['user_id'])) return null;
        if (!empty($_SESSION['splash_shown'])) return null;
        if (!empty($_SESSION['is_demo'])) return null; // skip demo mode

        $userId   = (int) $_SESSION['user_id'];
        $tenantId = (int) TenantResolver::id();
        if ($tenantId <= 0) return null;

        // Prioritas: onboarding > whats_new > tips
        if ($s = self::checkOnboarding($userId, $tenantId)) return $s;
        if ($s = self::checkWhatsNew($userId, $tenantId))   return $s;
        if ($s = self::checkTips($userId, $tenantId))       return $s;
        return null;
    }

    // ─── Skenario 1: Onboarding ────────────────────────
    private static function checkOnboarding(int $userId, int $tenantId): ?array
    {
        $db = Database::get();

        // Sudah pernah lihat onboarding (dan tandai complete)?
        $stmt = $db->prepare(
            "SELECT id FROM hl_splash_seen
             WHERE user_id = ? AND splash_type = 'onboarding' AND ref_id IS NULL
             LIMIT 1"
        );
        $stmt->execute([$userId]);
        if ($stmt->fetch()) return null;

        // Cek progress 3 step setup
        $outletId = (int) TenantResolver::outletId();

        try {
            $layanan = $db->prepare("SELECT COUNT(*) FROM hl_layanan WHERE tenant_id=? AND outlet_id=?");
            $layanan->execute([$tenantId, $outletId]);
            $layananCount = (int) $layanan->fetchColumn();
        } catch (Throwable) { $layananCount = 0; }

        try {
            $karyawan = $db->prepare(
                "SELECT COUNT(*) FROM hl_users WHERE tenant_id=? AND role != 'owner' AND role != 'superadmin'"
            );
            $karyawan->execute([$tenantId]);
            $karyawanCount = (int) $karyawan->fetchColumn();
        } catch (Throwable) { $karyawanCount = 0; }

        try {
            $trx = $db->prepare("SELECT COUNT(*) FROM hl_transaksi WHERE tenant_id=? AND outlet_id=?");
            $trx->execute([$tenantId, $outletId]);
            $trxCount = (int) $trx->fetchColumn();
        } catch (Throwable) { $trxCount = 0; }

        $steps = [
            'layanan'   => $layananCount > 0,
            'karyawan'  => $karyawanCount > 0,
            'transaksi' => $trxCount > 0,
        ];
        $completed = count(array_filter($steps));
        $total = count($steps);

        // Semua selesai → tandai seen permanent, skip
        if ($completed >= $total) {
            self::markSeen($userId, 'onboarding', null);
            return null;
        }

        return [
            'type'      => 'onboarding',
            'steps'     => $steps,
            'completed' => $completed,
            'total'     => $total,
        ];
    }

    // ─── Skenario 2: What's New ────────────────────────
    private static function checkWhatsNew(int $userId, int $tenantId): ?array
    {
        try {
            $stmt = Database::get()->prepare(
                "SELECT a.id, a.title, a.content, a.type, a.published_at
                 FROM saas_announcements a
                 LEFT JOIN hl_splash_seen s
                        ON s.user_id = ?
                       AND s.splash_type = 'whats_new'
                       AND s.ref_id = CAST(a.id AS CHAR)
                 WHERE a.status = 'published'
                   AND a.type   = 'fitur_baru'
                   AND (a.expires_at IS NULL OR a.expires_at > NOW())
                   AND s.id IS NULL
                 ORDER BY a.published_at DESC
                 LIMIT 1"
            );
            $stmt->execute([$userId]);
            $ann = $stmt->fetch();
            if (!$ann) return null;
            return ['type' => 'whats_new', 'announcement' => $ann];
        } catch (Throwable) {
            return null;
        }
    }

    // ─── Skenario 3: Tips Harian ───────────────────────
    private static function checkTips(int $userId, int $tenantId): ?array
    {
        $db = Database::get();
        $today = date('Y-m-d');

        // Sudah lihat tip apapun hari ini?
        $stmt = $db->prepare(
            "SELECT id FROM hl_splash_seen
             WHERE user_id = ? AND splash_type = 'tips' AND ref_id LIKE ?
             LIMIT 1"
        );
        $stmt->execute([$userId, "%\\_{$today}"]);
        if ($stmt->fetch()) return null;

        // Pilih tip yang belum pernah dilihat user, random
        $stmt = $db->prepare(
            "SELECT t.* FROM hl_splash_tips t
             LEFT JOIN hl_splash_seen s
                    ON s.user_id = ?
                   AND s.splash_type = 'tips'
                   AND s.ref_id LIKE CONCAT(CAST(t.id AS CHAR), '\\_%')
             WHERE t.is_active = 1
               AND (t.tenant_id IS NULL OR t.tenant_id = ?)
               AND s.id IS NULL
             ORDER BY RAND() LIMIT 1"
        );
        $stmt->execute([$userId, $tenantId]);
        $tip = $stmt->fetch();

        // Semua tips sudah dilihat → reset history + pick random
        if (!$tip) {
            $db->prepare(
                "DELETE FROM hl_splash_seen WHERE user_id=? AND splash_type='tips'"
            )->execute([$userId]);

            $stmt = $db->prepare(
                "SELECT * FROM hl_splash_tips
                 WHERE is_active=1 AND (tenant_id IS NULL OR tenant_id=?)
                 ORDER BY RAND() LIMIT 1"
            );
            $stmt->execute([$tenantId]);
            $tip = $stmt->fetch();
        }

        if (!$tip) return null;
        return ['type' => 'tips', 'tip' => $tip];
    }

    // ─── Mark sebagai sudah dilihat ─────────────────────
    /**
     * @param int         $userId
     * @param string      $type   onboarding|whats_new|tips
     * @param string|null $refId  null untuk onboarding, ann_id untuk whats_new,
     *                            "tipsId_YYYY-MM-DD" untuk tips
     */
    public static function markSeen(int $userId, string $type, ?string $refId): void
    {
        if (!in_array($type, ['onboarding','whats_new','tips'], true)) return;

        try {
            Database::get()->prepare(
                "INSERT IGNORE INTO hl_splash_seen (tenant_id, user_id, splash_type, ref_id)
                 VALUES (?, ?, ?, ?)"
            )->execute([
                (int) TenantResolver::id(),
                $userId, $type, $refId,
            ]);
        } catch (Throwable) { /* swallow — non-critical */ }

        $_SESSION['splash_shown'] = true;
    }

    // ─── Util untuk SuperAdmin: reset history tips ──────
    public static function resetTipsHistory(): int
    {
        try {
            $stmt = Database::get()->prepare(
                "DELETE FROM hl_splash_seen WHERE splash_type='tips'"
            );
            $stmt->execute();
            return $stmt->rowCount();
        } catch (Throwable) {
            return 0;
        }
    }
}

<?php
/** Referral (ajak teman) — opt-in per tenant, payout poin saat order pertama teman LUNAS. */
require_once __DIR__ . '/Database.php';

class Referral
{
    private static array $cfgCache = [];

    public static function config(int $tenantId): array
    {
        if (isset(self::$cfgCache[$tenantId])) return self::$cfgCache[$tenantId];
        $cfg = ['enabled'=>false,'poin_pengajak'=>0,'poin_teman'=>0,'max'=>0];
        try {
            $st = Database::get()->prepare(
                "SELECT referral_enabled, loyalty_enabled, referral_poin_pengajak, referral_poin_teman, referral_max_per_pengajak
                   FROM tenants WHERE id=?");
            $st->execute([$tenantId]);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $cfg = [
                    'enabled'       => (int)($r['referral_enabled'] ?? 0) === 1 && (int)($r['loyalty_enabled'] ?? 0) === 1,
                    'poin_pengajak' => max(0,(int)($r['referral_poin_pengajak'] ?? 0)),
                    'poin_teman'    => max(0,(int)($r['referral_poin_teman'] ?? 0)),
                    'max'           => max(0,(int)($r['referral_max_per_pengajak'] ?? 0)),
                ];
            }
        } catch (Throwable $e) {
            if (class_exists('ErrorLogger')) ErrorLogger::logException('referral_config', $e, $tenantId);
        }
        return self::$cfgCache[$tenantId] = $cfg;
    }

    public static function codeFor(int $tenantId, int $pelangganId): string
    {
        $db = Database::get();
        $cur = $db->prepare("SELECT referral_code, nama FROM hl_pelanggan WHERE id=? AND tenant_id=?");
        $cur->execute([$pelangganId, $tenantId]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) return '';
        if (!empty($row['referral_code'])) return $row['referral_code'];

        $slug = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$row['nama']));
        $slug = substr($slug !== '' ? $slug : 'REF', 0, 8);
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        for ($try = 0; $try < 20; $try++) {
            $rand = '';
            for ($i=0;$i<3;$i++) $rand .= $chars[random_int(0, strlen($chars)-1)];
            $code = $slug.'-'.$rand;
            $chk = $db->prepare("SELECT 1 FROM hl_pelanggan WHERE tenant_id=? AND referral_code=? LIMIT 1");
            $chk->execute([$tenantId, $code]);
            if (!$chk->fetchColumn()) {
                $db->prepare("UPDATE hl_pelanggan SET referral_code=? WHERE id=? AND tenant_id=?")
                   ->execute([$code, $pelangganId, $tenantId]);
                return $code;
            }
        }
        return $slug.'-'.substr(bin2hex(random_bytes(2)),0,3);
    }

    public static function resolveCode(int $tenantId, string $kode): ?int
    {
        $kode = trim($kode);
        if ($kode === '') return null;
        $st = Database::get()->prepare("SELECT id FROM hl_pelanggan WHERE tenant_id=? AND referral_code=? LIMIT 1");
        $st->execute([$tenantId, $kode]);
        $id = $st->fetchColumn();
        return $id ? (int)$id : null;
    }

    public static function statsFor(int $tenantId, int $pelangganId): array
    {
        $st = Database::get()->prepare(
            "SELECT COUNT(*) sukses, COALESCE(SUM(poin_pengajak),0) poin
               FROM hl_referral WHERE tenant_id=? AND referrer_pelanggan_id=? AND status='paid'");
        $st->execute([$tenantId, $pelangganId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['sukses'=>(int)($r['sukses'] ?? 0), 'poin'=>(int)($r['poin'] ?? 0)];
    }

    public static function attribute(int $tenantId, string $kode, int $refereePelangganId): array
    {
        $cfg = self::config($tenantId);
        if (!$cfg['enabled']) return ['ok'=>false, 'error'=>'off'];

        $referrerId = self::resolveCode($tenantId, $kode);
        if (!$referrerId) return ['ok'=>false, 'error'=>'Kode referral tidak dikenal'];
        if ($referrerId === $refereePelangganId) return ['ok'=>false, 'error'=>'Tidak bisa refer diri sendiri'];

        $db = Database::get();
        // telepon sama → anggap diri sendiri
        $tel = $db->prepare("SELECT telepon FROM hl_pelanggan WHERE id=? AND tenant_id=?");
        $tel->execute([$referrerId, $tenantId]); $telR = trim((string)$tel->fetchColumn());
        $tel->execute([$refereePelangganId, $tenantId]); $telE = trim((string)$tel->fetchColumn());
        if ($telR !== '' && $telR === $telE) return ['ok'=>false, 'error'=>'Tidak bisa refer diri sendiri'];

        // teman harus BARU (belum punya transaksi)
        $ord = $db->prepare("SELECT 1 FROM hl_transaksi WHERE tenant_id=? AND pelanggan_id=? LIMIT 1");
        $ord->execute([$tenantId, $refereePelangganId]);
        if ($ord->fetchColumn()) return ['ok'=>false, 'error'=>'Hanya untuk pelanggan baru'];

        try {
            $db->prepare(
                "INSERT INTO hl_referral (tenant_id, referrer_pelanggan_id, referee_pelanggan_id, kode, status, poin_pengajak, poin_teman)
                 VALUES (?,?,?,?, 'pending', ?, ?)"
            )->execute([$tenantId, $referrerId, $refereePelangganId, trim($kode), $cfg['poin_pengajak'], $cfg['poin_teman']]);
        } catch (Throwable $e) {
            // UNIQUE(referee) → sudah pernah direferral
            return ['ok'=>false, 'error'=>'Teman sudah pernah pakai kode referral'];
        }
        return ['ok'=>true, 'referrer_id'=>$referrerId];
    }

    public static function payoutOnFirstLunas(int $tenantId, int $refereePelangganId, int $orderId, ?int $userId = null): void
    {
        $cfg = self::config($tenantId);
        if (!$cfg['enabled']) return;
        require_once __DIR__ . '/Loyalty.php';
        $db = Database::get();

        // Ambil referral pending utk referee ini
        $st = $db->prepare("SELECT id, referrer_pelanggan_id, poin_pengajak, poin_teman
                              FROM hl_referral WHERE tenant_id=? AND referee_pelanggan_id=? AND status='pending' LIMIT 1");
        $st->execute([$tenantId, $refereePelangganId]);
        $ref = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ref) return;

        // Lock idempoten: hanya satu proses yang berhasil flip pending→paid
        $lock = $db->prepare("UPDATE hl_referral SET status='paid', referee_first_order_id=?, paid_at=NOW()
                               WHERE id=? AND status='pending'");
        $lock->execute([$orderId, (int)$ref['id']]);
        if ($lock->rowCount() === 0) return; // sudah diproses proses lain

        // Cek cap pengajak (hitung paid LAIN, exclude record ini)
        $payPengajak = (int)$ref['poin_pengajak'];
        if ($cfg['max'] > 0) {
            $cnt = $db->prepare("SELECT COUNT(*) FROM hl_referral WHERE tenant_id=? AND referrer_pelanggan_id=? AND status='paid' AND id<>?");
            $cnt->execute([$tenantId, (int)$ref['referrer_pelanggan_id'], (int)$ref['id']]);
            if ((int)$cnt->fetchColumn() >= $cfg['max']) {
                $payPengajak = 0;
                $db->prepare("UPDATE hl_referral SET poin_pengajak=0 WHERE id=?")->execute([(int)$ref['id']]);
            }
        }

        try {
            if ((int)$ref['poin_teman'] > 0)
                Loyalty::adjust($tenantId, $refereePelangganId, (int)$ref['poin_teman'], 'Bonus referral (teman baru)', $userId);
            if ($payPengajak > 0)
                Loyalty::adjust($tenantId, (int)$ref['referrer_pelanggan_id'], $payPengajak, 'Bonus referral (ajak teman)', $userId);
        } catch (Throwable $e) {
            if (class_exists('ErrorLogger')) ErrorLogger::logException('referral_payout', $e, $tenantId);
        }
    }
}

<?php
// ══════════════════════════════════════════════════════
// core/ManualPay.php
// Jalur bayar manual (transfer bank) — fallback selama Midtrans
// Core API belum di-approve. Owner transfer → SA konfirmasi → settle.
// ══════════════════════════════════════════════════════

require_once __DIR__ . '/BillingConfig.php';

class ManualPay
{
    /** Jalur manual aktif hanya jika switch on DAN rekening lengkap. */
    public static function isEnabled(): bool
    {
        if (BillingConfig::get('manual_payment_enabled') !== '1') return false;
        $i = self::bankInfo();
        return $i['name'] !== '' && $i['account_no'] !== '' && $i['holder'] !== '';
    }

    /** Info rekening tujuan + expiry. */
    public static function bankInfo(): array
    {
        return [
            'enabled'      => BillingConfig::get('manual_payment_enabled') === '1',
            'name'         => trim((string) BillingConfig::get('manual_bank_name', '')),
            'account_no'   => trim((string) BillingConfig::get('manual_bank_account_no', '')),
            'holder'       => trim((string) BillingConfig::get('manual_bank_holder', '')),
            'expiry_hours' => max(1, BillingConfig::getInt('manual_payment_expiry_hours', 24)),
        ];
    }

    /**
     * Kode unik nominal: amount = base + code (1..999), unik di antara
     * row manual_transfer yang masih pending (agar SA cocokkan di mutasi).
     */
    public static function uniqueAmount(PDO $db, int $base): array
    {
        $check = $db->prepare(
            "SELECT 1 FROM saas_payments
             WHERE payment_type='manual_transfer' AND status='pending' AND amount=? LIMIT 1"
        );
        $code = 0;
        for ($i = 0; $i < 30; $i++) {
            $code = random_int(1, 999);
            $check->execute([$base + $code]);
            if (!$check->fetchColumn()) break; // total belum dipakai → aman
        }
        return ['amount' => $base + $code, 'code' => $code];
    }

    /**
     * Resume row manual pending yang cocok (type + ref) atau buat baru.
     * $refs = ['bundle'=>?int,'package'=>?int,'outlet'=>?int]
     */
    public static function createPayment(PDO $db, int $tenantId, string $type, array $refs, int $base): array
    {
        require_once __DIR__ . '/MidtransClient.php';

        $refBundle  = $refs['bundle']  ?? null;
        $refPackage = $refs['package'] ?? null;
        $refOutlet  = $refs['outlet']  ?? null;

        // Resume: pending manual yang masih hidup, type + semua ref cocok.
        // FILTER payment_type='manual_transfer' supaya tak membajak row QRIS.
        $ex = $db->prepare(
            "SELECT * FROM saas_payments
             WHERE tenant_id=? AND type=? AND status='pending'
               AND payment_type='manual_transfer' AND expires_at > ?
               AND COALESCE(ref_bundle_id,0)=COALESCE(?,0)
               AND COALESCE(ref_outlet_id,0)=COALESCE(?,0)
               AND COALESCE(ref_package_id,0)=COALESCE(?,0)
             ORDER BY id DESC LIMIT 1"
        );
        $ex->execute([$tenantId, $type, date('Y-m-d H:i:s'), $refBundle, $refOutlet, $refPackage]);
        if ($row = $ex->fetch(PDO::FETCH_ASSOC)) return $row;

        $u        = self::uniqueAmount($db, $base);
        $info     = self::bankInfo();
        $orderId  = MidtransClient::generateOrderId($type, $tenantId);
        $expires  = date('Y-m-d H:i:s', time() + $info['expiry_hours'] * 3600);
        $raw      = json_encode([
            'manual'      => true,
            'base_amount' => $base,
            'unique_code' => $u['code'],
            'bank'        => ['name' => $info['name'], 'account_no' => $info['account_no'], 'holder' => $info['holder']],
        ]);

        $db->prepare(
            "INSERT INTO saas_payments
                (order_id, tenant_id, type, amount, ref_bundle_id, ref_package_id, ref_outlet_id,
                 payment_type, qr_string, status, expires_at, raw_response)
             VALUES (?,?,?,?,?,?,?, 'manual_transfer', NULL, 'pending', ?, ?)"
        )->execute([$orderId, $tenantId, $type, $u['amount'], $refBundle, $refPackage, $refOutlet, $expires, $raw]);

        $pid = (int)$db->lastInsertId();
        $g = $db->prepare("SELECT * FROM saas_payments WHERE id=?");
        $g->execute([$pid]);
        return $g->fetch(PDO::FETCH_ASSOC);
    }
}

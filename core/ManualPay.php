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
}

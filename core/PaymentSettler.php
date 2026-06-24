<?php
// ══════════════════════════════════════════════════════
// core/PaymentSettler.php
// Dispatch settlement berdasarkan saas_payments.type.
// Transactional + idempotent.
// ══════════════════════════════════════════════════════

class PaymentSettler
{
    /**
     * Main entry — settle payment by ID.
     * Idempotent: kalau sudah settled (coin already added / status active), no-op.
     */
    public static function settle(int $paymentId): array
    {
        $db = Database::get();
        $st = $db->prepare("SELECT * FROM saas_payments WHERE id=?");
        $st->execute([$paymentId]);
        $payment = $st->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            return ['ok' => false, 'error' => "Payment #$paymentId tidak ditemukan"];
        }
        if ($payment['status'] !== 'paid') {
            return ['ok' => false, 'error' => "Payment status bukan 'paid' (status: {$payment['status']})"];
        }

        return match ($payment['type']) {
            'topup_coin'        => self::settleTopupCoin($payment),
            'setup_fee'         => self::settleSetupFee($payment),
            'outlet_activation' => self::settleOutletActivation($payment),
            default             => ['ok' => false, 'error' => "Unknown type: {$payment['type']}"],
        };
    }

    /**
     * Top-up coin: add coin balance + insert ledger.
     */
    public static function settleTopupCoin(array $payment): array
    {
        if (empty($payment['ref_bundle_id'])) {
            return ['ok' => false, 'error' => 'ref_bundle_id missing'];
        }
        $db = Database::get();

        try {
            $db->beginTransaction();

            // Idempotency: check apakah sudah ada coin_ledger entry untuk payment ini
            $exists = $db->prepare("SELECT id FROM coin_ledger WHERE payment_id=?");
            $exists->execute([$payment['id']]);
            if ($exists->fetchColumn()) {
                $db->rollBack();
                return ['ok' => true, 'note' => 'Already settled (ledger exists)'];
            }

            // Get bundle
            $b = $db->prepare("SELECT coin_didapat, bonus_pct, nama FROM saas_coin_bundles WHERE id=?");
            $b->execute([$payment['ref_bundle_id']]);
            $bundle = $b->fetch(PDO::FETCH_ASSOC);
            if (!$bundle) throw new RuntimeException('Bundle not found');

            $coinAmount = (int)$bundle['coin_didapat'];

            // Add coin
            $db->prepare("UPDATE tenants SET coin_balance = coin_balance + ? WHERE id=?")
               ->execute([$coinAmount, $payment['tenant_id']]);

            // Get new balance
            $bal = $db->prepare("SELECT coin_balance FROM tenants WHERE id=?");
            $bal->execute([$payment['tenant_id']]);
            $newBal = (int)$bal->fetchColumn();

            // Insert ledger
            $db->prepare(
                "INSERT INTO coin_ledger (tenant_id, outlet_id, type, amount, feature_used, description, balance_after, payment_id)
                 VALUES (?, NULL, 'topup', ?, 'qris_midtrans', ?, ?, ?)"
            )->execute([
                $payment['tenant_id'],
                $coinAmount,
                "Top-up via Midtrans — {$bundle['nama']} ({$bundle['bonus_pct']}% bonus)",
                $newBal,
                $payment['id'],
            ]);

            $db->commit();
            return ['ok' => true, 'coin_added' => $coinAmount, 'new_balance' => $newBal];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Setup fee: activate tenant + assign package limits + seed coin_awal + send welcome.
     */
    public static function settleSetupFee(array $payment): array
    {
        $db = Database::get();
        try {
            $db->beginTransaction();

            // Idempotency: kalau tenant sudah active, no-op
            $st = $db->prepare("SELECT status, email, owner_name, nama_perusahaan FROM tenants WHERE id=?");
            $st->execute([$payment['tenant_id']]);
            $tenant = $st->fetch(PDO::FETCH_ASSOC);

            if (!$tenant) throw new RuntimeException('Tenant not found');

            if ($tenant['status'] === 'active') {
                $db->rollBack();
                return ['ok' => true, 'note' => 'Tenant already active'];
            }

            // Idempotency: check apakah coin_ledger sudah ada untuk payment ini (setup_fee awal)
            $ledgerExists = $db->prepare("SELECT id FROM coin_ledger WHERE payment_id=? AND type='topup'");
            $ledgerExists->execute([$payment['id']]);
            $alreadySeeded = (bool)$ledgerExists->fetchColumn();

            // Get package (coin_awal + max_outlets)
            $coinAwal = 0;
            $maxOutlets = 1;
            $pkgNama = '';
            if (!empty($payment['ref_package_id'])) {
                $pkg = $db->prepare("SELECT coin_awal, max_outlets, nama FROM saas_packages WHERE id=?");
                $pkg->execute([$payment['ref_package_id']]);
                $pkgRow = $pkg->fetch(PDO::FETCH_ASSOC);
                if ($pkgRow) {
                    $coinAwal   = (int)$pkgRow['coin_awal'];
                    $maxOutlets = (int)$pkgRow['max_outlets'];
                    $pkgNama    = $pkgRow['nama'];
                }
            }

            // UPDATE tenants: status active + package limits
            $db->prepare(
                "UPDATE tenants
                    SET status='active', package_assigned_at=NOW(), max_outlets=?
                  WHERE id=? AND status IN ('pending_verification','trial','suspended')"
            )->execute([$maxOutlets, $payment['tenant_id']]);

            // Seed coin_awal kalau belum pernah di-seed + coin_awal > 0
            $newBal = null;
            if (!$alreadySeeded && $coinAwal > 0) {
                $db->prepare("UPDATE tenants SET coin_balance = coin_balance + ? WHERE id=?")
                   ->execute([$coinAwal, $payment['tenant_id']]);

                $balSt = $db->prepare("SELECT coin_balance FROM tenants WHERE id=?");
                $balSt->execute([$payment['tenant_id']]);
                $newBal = (int)$balSt->fetchColumn();

                $db->prepare(
                    "INSERT INTO coin_ledger (tenant_id, outlet_id, type, amount, feature_used, description, balance_after, payment_id)
                     VALUES (?, NULL, 'topup', ?, 'setup_fee', ?, ?, ?)"
                )->execute([
                    $payment['tenant_id'],
                    $coinAwal,
                    "Coin awal package " . ($pkgNama ?: "#{$payment['ref_package_id']}"),
                    $newBal,
                    $payment['id'],
                ]);
            }

            $db->commit();

            // Side effects: Mailer + SaNotifier (best-effort, after commit)
            @require_once dirname(__DIR__) . '/core/Mailer.php';
            @require_once dirname(__DIR__) . '/core/SaNotifier.php';

            $email    = $tenant['email']        ?? '';
            $name     = $tenant['owner_name']   ?? $tenant['nama_perusahaan'] ?? '';
            $outletName = $tenant['nama_perusahaan'] ?? '';

            if ($email && class_exists('Mailer') && method_exists('Mailer', 'sendWelcome')) {
                try { Mailer::sendWelcome($email, $name, $outletName); } catch (Throwable) {}
            }
            if (class_exists('SaNotifier') && method_exists('SaNotifier', 'tenantActivated')) {
                try { SaNotifier::tenantActivated($payment['tenant_id']); } catch (Throwable) {}
            }

            return [
                'ok'               => true,
                'tenant_activated' => $payment['tenant_id'],
                'coin_seeded'      => $coinAwal,
                'new_balance'      => $newBal,
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Outlet activation: activate outlet ke-2+.
     */
    public static function settleOutletActivation(array $payment): array
    {
        if (empty($payment['ref_outlet_id'])) {
            return ['ok' => false, 'error' => 'ref_outlet_id missing'];
        }
        $db = Database::get();
        try {
            $db->beginTransaction();

            // Verify ownership
            $st = $db->prepare("SELECT tenant_id, status FROM outlets WHERE id=?");
            $st->execute([$payment['ref_outlet_id']]);
            $outlet = $st->fetch(PDO::FETCH_ASSOC);
            if (!$outlet) throw new RuntimeException('Outlet not found');
            if ((int)$outlet['tenant_id'] !== (int)$payment['tenant_id']) {
                throw new RuntimeException('Outlet ownership mismatch');
            }

            if ($outlet['status'] === 'active') {
                $db->rollBack();
                return ['ok' => true, 'note' => 'Outlet already active'];
            }

            $db->prepare("UPDATE outlets SET status='active', activated_at=NOW() WHERE id=?")
               ->execute([$payment['ref_outlet_id']]);

            $db->commit();

            // Side effect: notif (best-effort, after commit)
            @require_once dirname(__DIR__) . '/core/SaNotifier.php';
            if (class_exists('SaNotifier') && method_exists('SaNotifier', 'outletActivated')) {
                try { SaNotifier::outletActivated($payment['ref_outlet_id'], true); } catch (Throwable) {}
            }

            return ['ok' => true, 'outlet_activated' => $payment['ref_outlet_id']];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}

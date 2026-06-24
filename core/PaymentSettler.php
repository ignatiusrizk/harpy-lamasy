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
     * Setup fee: activate tenant.
     */
    public static function settleSetupFee(array $payment): array
    {
        $db = Database::get();
        try {
            $db->beginTransaction();

            // Idempotency: kalau tenant sudah active, no-op
            $st = $db->prepare("SELECT status FROM tenants WHERE id=?");
            $st->execute([$payment['tenant_id']]);
            $currentStatus = $st->fetchColumn();

            if ($currentStatus === 'active') {
                $db->rollBack();
                return ['ok' => true, 'note' => 'Tenant already active'];
            }

            $db->prepare(
                "UPDATE tenants SET status='active' WHERE id=? AND status IN ('pending_verification','trial','suspended')"
            )->execute([$payment['tenant_id']]);

            $db->commit();
            return ['ok' => true, 'tenant_activated' => $payment['tenant_id']];
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
            return ['ok' => true, 'outlet_activated' => $payment['ref_outlet_id']];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}

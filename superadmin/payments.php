<?php
// ══════════════════════════════════════════════════════
// superadmin/payments.php — Kelola Pembayaran Manual
// Konfirmasi topup coin & setup fee dari tenant
// ══════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';
require_once SA_ROOT . '/../core/CoinLedger.php';
require_once SA_ROOT . '/../core/SaPermission.php';

date_default_timezone_set('Asia/Jakarta');

$action = $_GET['action'] ?? '';

// ── API LAYER ─────────────────────────────────────────
if ($action) {
    header('Content-Type: application/json');
    $db = Database::get();

    // ── GET: list payments ────────────────────────────
    if ($action === 'list') {
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 25;
        $offset = ($page - 1) * $limit;
        $type   = $_GET['type'] ?? '';
        $status = $_GET['status'] ?? '';
        $q      = trim($_GET['q'] ?? '');
        $from   = $_GET['from'] ?? '';
        $to     = $_GET['to'] ?? '';

        $where = ['1=1']; $params = [];

        if ($type && in_array($type, ['setup_fee','coin_topup','adjustment','custom'])) {
            $where[] = 'p.type = ?'; $params[] = $type;
        }
        if ($status && in_array($status, ['pending','confirmed','cancelled'])) {
            $where[] = 'p.status = ?'; $params[] = $status;
        }
        if ($q) {
            $where[] = '(t.nama_perusahaan LIKE ? OR t.owner_name LIKE ? OR p.ref_transfer LIKE ?)';
            $like = "%$q%"; array_push($params, $like, $like, $like);
        }
        if ($from) { $where[] = 'p.tanggal_bayar >= ?'; $params[] = $from; }
        if ($to)   { $where[] = 'p.tanggal_bayar <= ?'; $params[] = $to;   }

        $w = implode(' AND ', $where);

        $cnt = $db->prepare("SELECT COUNT(*) FROM saas_manual_payments p
                              LEFT JOIN tenants t ON t.id = p.tenant_id WHERE $w");
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT p.*,
                    t.nama_perusahaan AS nama_outlet, t.owner_name, t.owner_wa,
                    pkg.nama AS package_nama,
                    bdl.nama AS bundle_nama,
                    sa.name  AS superadmin_nama
             FROM saas_manual_payments p
             LEFT JOIN tenants      t   ON t.id   = p.tenant_id
             LEFT JOIN saas_packages     pkg ON pkg.id = p.package_id
             LEFT JOIN saas_coin_bundles bdl ON bdl.id = p.bundle_id
             LEFT JOIN super_admins  sa  ON sa.id  = p.superadmin_id
             WHERE $w
             ORDER BY p.id DESC
             LIMIT $limit OFFSET $offset"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Stats
        $stats = $db->query(
            "SELECT
               COUNT(*) total,
               SUM(CASE WHEN status='confirmed' THEN nominal_dibayar ELSE 0 END) revenue,
               SUM(CASE WHEN status='pending'   THEN 1 ELSE 0 END) pending_count,
               SUM(CASE WHEN DATE(created_at)=CURDATE() AND status='confirmed' THEN nominal_dibayar ELSE 0 END) revenue_today,
               SUM(CASE WHEN DATE(created_at)=CURDATE() AND status='confirmed' THEN 1 ELSE 0 END) txn_today
             FROM saas_manual_payments"
        )->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'ok'    => true,
            'rows'  => $rows,
            'total' => $total,
            'pages' => max(1, (int)ceil($total / $limit)),
            'page'  => $page,
            'stats' => $stats,
        ]);
        exit;
    }

    // ── GET: tenant autocomplete search ──────────────
    if ($action === 'tenant_search') {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) { echo json_encode([]); exit; }
        $stmt = $db->prepare(
            "SELECT id, nama_perusahaan AS nama_outlet, owner_name, owner_wa, coin_balance, status, package_id
             FROM tenants WHERE nama_perusahaan LIKE ? OR owner_name LIKE ? OR owner_wa LIKE ?
             ORDER BY nama_perusahaan LIMIT 10"
        );
        $like = "%$q%";
        $stmt->execute([$like, $like, $like]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // ── GET: active packages ──────────────────────────
    if ($action === 'get_packages') {
        $rows = $db->query(
            "SELECT id, nama, setup_fee, coin_awal, trial_hari, max_outlets, is_custom
             FROM saas_packages WHERE is_active = 1 ORDER BY urutan ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
        exit;
    }

    // ── GET: active bundles ───────────────────────────
    if ($action === 'get_bundles') {
        $rows = $db->query(
            "SELECT id, nama, harga, coin_didapat, bonus_pct, is_featured
             FROM saas_coin_bundles WHERE is_active = 1 ORDER BY urutan ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
        exit;
    }

    // ── GET: list Midtrans payments ───────────────────
    if ($action === 'list_midtrans') {
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 25;
        $offset = ($page - 1) * $limit;
        $type   = $_GET['type']   ?? '';
        $status = $_GET['status'] ?? '';
        $from   = $_GET['from']   ?? '';
        $to     = $_GET['to']     ?? '';
        $q      = trim($_GET['q'] ?? '');

        $where = ['1=1']; $params = [];

        if ($type && in_array($type, ['topup_coin','setup_fee','outlet_activation'])) {
            $where[] = 'sp.type = ?'; $params[] = $type;
        }
        if ($status && in_array($status, ['pending','paid','expired','failed','cancelled'])) {
            $where[] = 'sp.status = ?'; $params[] = $status;
        }
        if ($from) { $where[] = 'DATE(sp.created_at) >= ?'; $params[] = $from; }
        if ($to)   { $where[] = 'DATE(sp.created_at) <= ?'; $params[] = $to;   }
        if ($q) {
            $where[] = '(t.nama_perusahaan LIKE ? OR sp.order_id LIKE ?)';
            $like = "%$q%"; array_push($params, $like, $like);
        }

        $w = implode(' AND ', $where);

        $cnt = $db->prepare("SELECT COUNT(*) FROM saas_payments sp
                              LEFT JOIN tenants t ON t.id = sp.tenant_id WHERE $w");
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT sp.*,
                    t.nama_perusahaan,
                    bdl.nama AS bundle_nama
             FROM saas_payments sp
             LEFT JOIN tenants           t   ON t.id   = sp.tenant_id
             LEFT JOIN saas_coin_bundles bdl ON bdl.id = sp.ref_bundle_id
             WHERE $w
             ORDER BY sp.id DESC
             LIMIT $limit OFFSET $offset"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'ok'    => true,
            'rows'  => $rows,
            'total' => $total,
            'pages' => max(1, (int)ceil($total / $limit)),
            'page'  => $page,
        ]);
        exit;
    }

    // ── POST actions — CSRF required ──────────────────
    saVerifyCsrf();

    // ── CONFIRM PAYMENT ───────────────────────────────
    if ($action === 'confirm') {
        SaPermission::require('payments.approve');
        $d = json_decode(file_get_contents('php://input'), true) ?: [];

        $tenantId      = (int)($d['tenant_id'] ?? 0);
        $type          = $d['type'] ?? '';
        $packageId     = (int)($d['package_id'] ?? 0) ?: null;
        $bundleId      = (int)($d['bundle_id'] ?? 0) ?: null;
        $nominal       = (int)($d['nominal_dibayar'] ?? 0);
        $coin          = (int)($d['coin_dikreditkan'] ?? 0);
        $metode        = $d['metode'] ?? 'transfer_bca';
        $namaPengirim  = substr(trim($d['nama_pengirim'] ?? ''), 0, 100);
        $refTransfer   = substr(trim($d['ref_transfer'] ?? ''), 0, 100);
        $tanggalBayar  = $d['tanggal_bayar'] ?? date('Y-m-d');
        $catatan       = trim($d['catatan'] ?? '');
        $adjReason     = $d['adjustment_reason'] ?? null;
        $kirimWa       = !empty($d['kirim_wa']);

        // Validation
        if (!$tenantId)                              { echo json_encode(['error' => 'Pilih tenant terlebih dahulu.']); exit; }
        if (!in_array($type, ['setup_fee','coin_topup','adjustment','custom'])) { echo json_encode(['error' => 'Tipe pembayaran tidak valid.']); exit; }
        if ($nominal < 0)                            { echo json_encode(['error' => 'Nominal tidak boleh negatif.']); exit; }
        if ($type !== 'adjustment' && $nominal < 1)  { echo json_encode(['error' => 'Nominal harus diisi.']); exit; }

        $metodeAllowed = ['transfer_bca','transfer_mandiri','transfer_bri','transfer_bni','qris','cash','lainnya'];
        if (!in_array($metode, $metodeAllowed))       { $metode = 'lainnya'; }

        $adjReasonAllowed = ['kompensasi_downtime','bonus_referral','koreksi_error','promo','lainnya',null];
        if (!in_array($adjReason, $adjReasonAllowed)) { $adjReason = null; }

        // Fetch tenant
        $tenantRow = $db->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
        $tenantRow->execute([$tenantId]);
        $tenant = $tenantRow->fetch(PDO::FETCH_ASSOC);
        if (!$tenant) { echo json_encode(['error' => 'Tenant tidak ditemukan.']); exit; }

        $db->beginTransaction();
        try {
            // 1. INSERT payment record
            $db->prepare(
                "INSERT INTO saas_manual_payments
                   (tenant_id, superadmin_id, type, package_id, bundle_id,
                    nominal_dibayar, coin_dikreditkan, metode,
                    nama_pengirim, ref_transfer, tanggal_bayar, catatan,
                    adjustment_reason, status, notif_wa_sent)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'confirmed',?)"
            )->execute([
                $tenantId, $_SESSION['superadmin_id'], $type, $packageId, $bundleId,
                $nominal, $coin, $metode,
                $namaPengirim ?: null, $refTransfer ?: null, $tanggalBayar,
                $catatan ?: null, $adjReason ?: null, 0,
            ]);
            $paymentId = (int)$db->lastInsertId();

            // 2. Kredit coin ke tenant (jika ada coin)
            $newBalance = (int)$tenant['coin_balance'];
            if ($coin > 0) {
                // Inline topup (tidak butuh TenantResolver context)
                $db->prepare("SELECT coin_balance FROM tenants WHERE id=? FOR UPDATE")->execute([$tenantId]);
                $_csStmt = $db->prepare("SELECT coin_balance FROM tenants WHERE id=?");
                $_csStmt->execute([$tenantId]);
                $cur = (int)$_csStmt->fetchColumn();

                // Fresh query after lock
                $lockStmt = $db->prepare("SELECT coin_balance FROM tenants WHERE id=? FOR UPDATE");
                $lockStmt->execute([$tenantId]);
                $cur = (int)$lockStmt->fetchColumn();
                $newBalance = $cur + $coin;

                $db->prepare("UPDATE tenants SET coin_balance=? WHERE id=?")->execute([$newBalance, $tenantId]);

                $ledgerType = ($coin < 0) ? 'deduct' : 'topup';
                $db->prepare(
                    "INSERT INTO coin_ledger
                       (tenant_id, outlet_id, type, amount, feature_used, description, balance_after, ref_id)
                     VALUES (?, NULL, ?, ?, ?, ?, ?, ?)"
                )->execute([
                    $tenantId, $ledgerType, abs($coin),
                    'manual_payment',
                    "Pembayaran manual #$paymentId — $type",
                    $newBalance,
                    "PAY-$paymentId",
                ]);
            } elseif ($coin < 0) {
                // Deduction adjustment
                $lockStmt = $db->prepare("SELECT coin_balance FROM tenants WHERE id=? FOR UPDATE");
                $lockStmt->execute([$tenantId]);
                $cur = (int)$lockStmt->fetchColumn();
                $newBalance = max(0, $cur + $coin); // coin is negative

                $db->prepare("UPDATE tenants SET coin_balance=? WHERE id=?")->execute([$newBalance, $tenantId]);
                $db->prepare(
                    "INSERT INTO coin_ledger
                       (tenant_id, outlet_id, type, amount, feature_used, description, balance_after, ref_id)
                     VALUES (?, NULL, 'deduct', ?, 'manual_adjustment', ?, ?, ?)"
                )->execute([
                    $tenantId, abs($coin),
                    "Adjustment manual #$paymentId — " . ($adjReason ?? 'lainnya'),
                    $newBalance, "PAY-$paymentId",
                ]);
            }

            // 3. Jika setup_fee → update tenant package, status, max_outlets
            if ($type === 'setup_fee' && $packageId) {
                $pkg = $db->prepare("SELECT * FROM saas_packages WHERE id=?");
                $pkg->execute([$packageId]);
                $pkgRow = $pkg->fetch(PDO::FETCH_ASSOC);
                if ($pkgRow) {
                    $db->prepare(
                        "UPDATE tenants SET
                           package_id = ?, package_assigned_at = NOW(),
                           max_outlets = ?, status = 'active'
                         WHERE id = ?"
                    )->execute([$packageId, $pkgRow['max_outlets'], $tenantId]);
                }
            }

            $db->commit();

            // 4. Audit log
            logSuperAdminAction('confirm_payment', $tenantId,
                "Konfirmasi pembayaran #$paymentId — $type — Rp " . number_format($nominal, 0, ',', '.') .
                " — +$coin coin" . ($refTransfer ? " — ref: $refTransfer" : '')
            );

            // 5. Build WA link jika diminta
            $waLink = null;
            if ($kirimWa && !empty($tenant['owner_wa'])) {
                $pkgBdlNama = '';
                if ($packageId) {
                    $p = $db->prepare("SELECT nama FROM saas_packages WHERE id=?"); $p->execute([$packageId]);
                    $pkgBdlNama = $p->fetchColumn() ?: '';
                } elseif ($bundleId) {
                    $p = $db->prepare("SELECT nama FROM saas_coin_bundles WHERE id=?"); $p->execute([$bundleId]);
                    $pkgBdlNama = $p->fetchColumn() ?: '';
                }

                $rp = 'Rp ' . number_format($nominal, 0, ',', '.');
                $coinFmt = number_format(abs($coin), 0, ',', '.');
                $tgl = date('d/m/Y', strtotime($tanggalBayar));
                $ref = $refTransfer ?: '-';

                $waMsg = "✅ *Pembayaran Diterima — LAMASY*\n\n"
                       . "Halo {$tenant['owner_name']}!\n\n"
                       . "Pembayaran kamu sudah kami terima dan diproses.\n\n"
                       . ($pkgBdlNama ? "📦 Paket/Bundle : $pkgBdlNama\n" : '')
                       . "💰 Nominal      : $rp\n"
                       . ($coin > 0 ? "🪙 Coin         : +{$coinFmt} coin\n" : '')
                       . "💼 Saldo coin   : " . number_format($newBalance, 0, ',', '.') . " coin\n\n"
                       . "Ref: $ref\n"
                       . "Tanggal: $tgl\n\n"
                       . "Terima kasih sudah menggunakan LAMASY! 🙏";

                $phone = preg_replace('/[^0-9]/', '', $tenant['owner_wa']);
                if (str_starts_with($phone, '0')) $phone = '62' . substr($phone, 1);
                $waLink = 'https://wa.me/' . $phone . '?text=' . rawurlencode($waMsg);

                // Tandai notif wa
                $db->prepare("UPDATE saas_manual_payments SET notif_wa_sent=1, notif_wa_sent_at=NOW() WHERE id=?")
                   ->execute([$paymentId]);
            }

            echo json_encode([
                'ok'          => true,
                'payment_id'  => $paymentId,
                'new_balance' => $newBalance,
                'wa_link'     => $waLink,
                'msg'         => "Pembayaran #$paymentId dikonfirmasi. Saldo coin baru: " . number_format($newBalance, 0, ',', '.'),
            ]);

        } catch (Throwable $e) {
            $db->rollBack();
            apiErr($e, 'Gagal konfirmasi. Silakan coba lagi.');
        }
        exit;
    }

    // ── CANCEL PAYMENT ────────────────────────────────
    if ($action === 'cancel') {
        $d  = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($d['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'ID tidak valid.']); exit; }

        // Fetch payment
        $pay = $db->prepare("SELECT * FROM saas_manual_payments WHERE id=?");
        $pay->execute([$id]);
        $payRow = $pay->fetch(PDO::FETCH_ASSOC);
        if (!$payRow) { echo json_encode(['error' => 'Pembayaran tidak ditemukan.']); exit; }
        if ($payRow['status'] !== 'confirmed') { echo json_encode(['error' => 'Hanya pembayaran confirmed yang bisa dibatalkan.']); exit; }

        // Rollback coin jika ada
        if ((int)$payRow['coin_dikreditkan'] > 0) {
            $db->beginTransaction();
            try {
                $lockS = $db->prepare("SELECT coin_balance FROM tenants WHERE id=? FOR UPDATE");
                $lockS->execute([$payRow['tenant_id']]);
                $cur = (int)$lockS->fetchColumn();
                $newBal = max(0, $cur - (int)$payRow['coin_dikreditkan']);

                $db->prepare("UPDATE tenants SET coin_balance=? WHERE id=?")->execute([$newBal, $payRow['tenant_id']]);
                $db->prepare(
                    "INSERT INTO coin_ledger (tenant_id, type, amount, feature_used, description, balance_after, ref_id)
                     VALUES (?, 'deduct', ?, 'cancel_payment', ?, ?, ?)"
                )->execute([$payRow['tenant_id'], $payRow['coin_dikreditkan'], "Pembatalan pembayaran #{$id}", $newBal, "CANCEL-$id"]);

                $db->prepare("UPDATE saas_manual_payments SET status='cancelled' WHERE id=?")->execute([$id]);
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                apiErr($e); exit;
            }
        } else {
            $db->prepare("UPDATE saas_manual_payments SET status='cancelled' WHERE id=?")->execute([$id]);
        }

        logSuperAdminAction('cancel_payment', $payRow['tenant_id'], "Batalkan pembayaran #$id");
        echo json_encode(['ok' => true, 'msg' => 'Pembayaran berhasil dibatalkan dan coin sudah di-rollback.']);
        exit;
    }

    // ── REFUND MIDTRANS PAYMENT ───────────────────────
    if ($action === 'refund') {
        SaPermission::require('billing.topup');
        $orderId = trim($_POST['order_id'] ?? '');
        $reason  = trim($_POST['reason']   ?? 'SA manual refund');

        if (!$orderId) { echo json_encode(['error' => 'order_id wajib diisi.']); exit; }

        $p = $db->prepare("SELECT * FROM saas_payments WHERE order_id=? AND status='paid'");
        $p->execute([$orderId]);
        $payment = $p->fetch(PDO::FETCH_ASSOC);
        if (!$payment) { echo json_encode(['error' => 'Payment tidak ditemukan atau belum paid.']); exit; }

        // Guard: refund hanya untuk topup_coin — setup_fee/outlet_activation butuh flow manual
        if ($payment['type'] !== 'topup_coin') {
            echo json_encode(['error' => 'Refund hanya didukung untuk top-up coin. Untuk setup_fee / outlet_activation, gunakan flow manual.']);
            exit;
        }

        require_once SA_ROOT . '/../core/MidtransClient.php';
        $res = MidtransClient::refund($orderId, (int)$payment['amount'], $reason);
        if (!$res['ok']) {
            echo json_encode(['error' => $res['error'] ?? 'Refund gagal di Midtrans.']); exit;
        }

        $db->beginTransaction();
        try {
            $db->prepare("UPDATE saas_payments SET status='cancelled', updated_at=NOW() WHERE id=?")
               ->execute([$payment['id']]);

            if ($payment['type'] === 'topup_coin' && $payment['ref_bundle_id']) {
                $b = $db->prepare("SELECT coin_didapat FROM saas_coin_bundles WHERE id=?");
                $b->execute([$payment['ref_bundle_id']]);
                $coinAmt = (int)$b->fetchColumn();

                if ($coinAmt > 0) {
                    $lockS = $db->prepare("SELECT coin_balance FROM tenants WHERE id=? FOR UPDATE");
                    $lockS->execute([$payment['tenant_id']]);
                    $cur    = (int)$lockS->fetchColumn();
                    $newBal = max(0, $cur - $coinAmt);

                    $db->prepare("UPDATE tenants SET coin_balance=? WHERE id=?")
                       ->execute([$newBal, $payment['tenant_id']]);
                    $db->prepare(
                        "INSERT INTO coin_ledger
                           (tenant_id, type, amount, feature_used, description, balance_after, payment_id)
                         VALUES (?, 'deduct', ?, 'refund', ?, ?, ?)"
                    )->execute([
                        $payment['tenant_id'], $coinAmt,
                        'Refund Midtrans payment ' . $orderId,
                        $newBal, $payment['id'],
                    ]);
                }
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            apiErr($e, 'DB error. Silakan coba lagi.'); exit;
        }

        logSuperAdminAction('payment_refund', (int)$payment['tenant_id'], "Refund $orderId");
        echo json_encode(['ok' => true, 'msg' => "Refund $orderId berhasil diproses."]);
        exit;
    }

    // ── KONFIRMASI TRANSFER MANUAL (saas_payments) ────
    if ($action === 'confirm_manual') {
        SaPermission::require('payments.approve');
        require_once SA_ROOT . '/../core/ManualPay.php';
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'ID tidak valid.']); exit; }
        try {
            $res = ManualPay::confirm($id);
            if (empty($res['ok'])) { echo json_encode(['error' => $res['error'] ?? 'Konfirmasi gagal.']); exit; }
            logSuperAdminAction('confirm_manual_transfer', null, "Konfirmasi transfer manual saas_payments #$id");
            echo json_encode(['ok' => true, 'msg' => 'Pembayaran manual dikonfirmasi. Coin/aktivasi diproses.']);
        } catch (Throwable $e) { apiErr($e, 'Gagal konfirmasi. Coba lagi.'); }
        exit;
    }

    // ── TOLAK TRANSFER MANUAL ─────────────────────────
    if ($action === 'reject_manual') {
        SaPermission::require('payments.approve');
        require_once SA_ROOT . '/../core/ManualPay.php';
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'ID tidak valid.']); exit; }
        try {
            $res = ManualPay::reject($id);
            if (empty($res['ok'])) { echo json_encode(['error' => $res['error'] ?? 'Tolak gagal.']); exit; }
            logSuperAdminAction('reject_manual_transfer', null, "Tolak transfer manual saas_payments #$id");
            echo json_encode(['ok' => true, 'msg' => 'Pembayaran manual ditolak.']);
        } catch (Throwable $e) { apiErr($e, 'Gagal menolak. Coba lagi.'); }
        exit;
    }

    // ── MARK WA SENT ──────────────────────────────────
    if ($action === 'mark_wa_sent') {
        $d  = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($d['id'] ?? 0);
        $db->prepare("UPDATE saas_manual_payments SET notif_wa_sent=1, notif_wa_sent_at=NOW() WHERE id=?")
           ->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['error' => 'Action tidak dikenal.']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php saRenderHead('Pembayaran Manual'); ?>
<style>
/* ── Payments page extras ── */
.pay-type-badge {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700;
}
.pay-type-setup    { background: rgba(53,232,213,.08); color: #A5B4FC; border: 1px solid #C7D2FE; }
.pay-type-topup    { background: rgba(132,204,22,.10);  color: #6EE7B7; border: 1px solid #A7F3D0; }
.pay-type-adj      { background: rgba(245,158,11,.10);  color: #FCD34D; border: 1px solid #FDE68A; }
.pay-type-custom   { background: rgba(107,114,128,.15); color: #D1D5DB; border: 1px solid rgba(107,114,128,.2); }
.pay-status-confirmed { color: #6EE7B7; font-size: 12px; }
.pay-status-pending   { color: #FCD34D; font-size: 12px; }
.pay-status-cancelled { color: var(--ash-dim); font-size: 12px; text-decoration: line-through; }
.pay-nominal { font-family: var(--mono); font-size: 13px; font-weight: 600; color: var(--glow); }
.pay-coin    { font-family: var(--mono); font-size: 12px; color: #FCD34D; }

/* ── Stats mini bar ── */
.pay-stats { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
.pay-stat  {
  background: rgba(10,15,31,.4); border: 1px solid var(--crease);
  border-radius: 12px; padding: 14px 20px; flex: 1; min-width: 140px;
}
.pay-stat .label { font-size: 10px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase;
  color: var(--ash-dim); margin-bottom: 6px; }
.pay-stat .value { font-size: 22px; font-weight: 800; font-family: var(--mono); color: var(--glow); }
.pay-stat .sub   { font-size: 11px; color: var(--ash-dim); margin-top: 3px; }
.pay-stat.green  { border-color: #A7F3D0; background: rgba(16,185,129,.07); }
.pay-stat.yellow { border-color: #FDE68A; background: rgba(245,158,11,.07); }
.pay-stat.indigo { border-color: #C7D2FE; background: rgba(99,102,241,.07); }

/* ── Form groups ── */
.fg  { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
.fg label { font-size: 10px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: var(--ash); }
.fg input, .fg textarea, .fg select {
  padding: 9px 12px; background: var(--crease-soft);
  border: 1.5px solid var(--crease); border-radius: 8px;
  color: var(--white); font-family: var(--font); font-size: 13px; outline: none;
  transition: border-color .15s;
}
.fg input:focus, .fg textarea:focus, .fg select:focus {
  border-color: var(--sa); box-shadow: 0 0 0 3px #EEF2FF;
}
.fg textarea { resize: vertical; min-height: 64px; }
.fg select option { background: var(--navy); }
.fg-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.fg-hint { font-size: 11px; color: var(--ash-dim); }
.fg-check { display: flex; align-items: center; gap: 8px; }
.fg-check input[type=checkbox] { width: 16px; height: 16px; cursor: pointer; accent-color: var(--sa); }
.fg-check label { font-size: 13px; color: var(--ink-soft); font-weight: 500; }

/* ── Tenant autocomplete ── */
.tenant-search-wrap { position: relative; }
.tenant-dropdown {
  position: absolute; top: calc(100% + 4px); left: 0; right: 0; z-index: 100;
  background: #1B2D5A; border: 1px solid var(--crease); border-radius: 10px;
  overflow: hidden; box-shadow: 0 8px 24px rgba(0,0,0,.4);
  max-height: 260px; overflow-y: auto;
}
.tenant-option {
  padding: 10px 14px; cursor: pointer; border-bottom: 1px solid var(--crease-soft);
  display: flex; align-items: center; justify-content: space-between; gap: 10px;
}
.tenant-option:hover { background: rgba(53,232,213,.08); }
.tenant-option:last-child { border-bottom: none; }
.tenant-option .t-name { font-size: 13px; font-weight: 600; color: var(--glow); }
.tenant-option .t-meta { font-size: 11px; color: var(--ash); }
.tenant-selected {
  display: flex; align-items: center; justify-content: space-between;
  background: rgba(53,232,213,.08); border: 1.5px solid rgba(99,102,241,.3);
  border-radius: 8px; padding: 10px 14px;
}
.tenant-selected .t-info { flex: 1; }
.tenant-selected .t-info strong { font-size: 13px; color: var(--glow); display: block; }
.tenant-selected .t-info small  { font-size: 11px; color: var(--ash); }
.tenant-selected button { background: none; border: none; color: var(--ash);
  cursor: pointer; font-size: 16px; padding: 0 4px; }
.tenant-selected button:hover { color: var(--glow); }

/* ── Package/bundle selector ── */
.pkg-options { display: flex; flex-direction: column; gap: 6px; }
.pkg-option {
  display: flex; align-items: center; justify-content: space-between;
  padding: 10px 14px; border-radius: 8px; cursor: pointer;
  border: 1.5px solid var(--crease); background: rgba(10,15,31,.4);
  transition: border-color .15s, background .15s;
}
.pkg-option:hover { border-color: rgba(99,102,241,.4); background: rgba(99,102,241,.07); }
.pkg-option.selected { border-color: var(--sa); background: rgba(53,232,213,.08); }
.pkg-option .p-name { font-size: 13px; font-weight: 600; color: var(--glow); }
.pkg-option .p-meta { font-size: 11px; color: var(--ash); }
.pkg-option .p-price { font-family: var(--mono); font-size: 13px; font-weight: 700; color: #6EE7B7; text-align: right; }
.pkg-option .p-price small { display: block; font-size: 10px; color: var(--ash); font-family: var(--font); }

/* ── Tabs ── */
.pay-tabs { display:flex; gap:4px; margin-bottom:20px; border-bottom:1px solid var(--crease); padding-bottom:0; }
.pay-tab  {
  padding: 10px 20px; font-size:13px; font-weight:600; cursor:pointer;
  border: 1px solid transparent; border-bottom:none; border-radius:8px 8px 0 0;
  color: var(--ash); background:transparent; transition: color .15s, background .15s;
  position:relative; bottom:-1px;
}
.pay-tab:hover { color:var(--glow); background:rgba(53,232,213,.05); }
.pay-tab.active { color:var(--glow); background:var(--navy); border-color:var(--crease); border-bottom-color:var(--navy); }

/* ── Midtrans status badges ── */
.mt-status-paid      { color:#6EE7B7; font-size:12px; font-weight:700; }
.mt-status-pending   { color:#FCD34D; font-size:12px; }
.mt-status-expired   { color:var(--ash-dim); font-size:12px; }
.mt-status-failed    { color:#F87171; font-size:12px; }
.mt-status-cancelled { color:var(--ash-dim); font-size:12px; text-decoration:line-through; }

/* ── Midtrans type badges ── */
.mt-type-topup    { background:rgba(132,204,22,.10);  color:#6EE7B7; border:1px solid #A7F3D0; }
.mt-type-setup    { background:rgba(53,232,213,.08);  color:#A5B4FC; border:1px solid #C7D2FE; }
.mt-type-outlet   { background:rgba(245,158,11,.10);  color:#FCD34D; border:1px solid #FDE68A; }

/* ── Separator ── */
.modal-section-title {
  font-size: 10px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
  color: var(--ash-dim); margin: 16px 0 10px; padding-top: 14px;
  border-top: 1px solid var(--crease-soft);
}
</style>
</head>
<body>
<div class="sa-layout">
<?php saRenderNav('payments', 'Pembayaran Manual'); ?>

<div class="sa-page-header">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <h1>💰 Pembayaran Manual</h1>
      <p>Konfirmasi setup fee & topup coin dari tenant</p>
    </div>
    <button class="sa-btn sa-btn-primary" onclick="openConfirmModal()">＋ Konfirmasi Pembayaran</button>
  </div>
</div>

<!-- Tabs -->
<div class="pay-tabs">
  <button class="pay-tab active" id="tabManualBtn" onclick="switchTab('manual')">💰 Pembayaran Manual</button>
  <button class="pay-tab"        id="tabMidtransBtn" onclick="switchTab('midtrans')">🏦 Midtrans Payments</button>
</div>

<!-- ══ PANEL: PEMBAYARAN MANUAL ══════════════════════ -->
<div id="panelManual">

<!-- Stats -->
<div class="pay-stats" id="statsBar">
  <div class="pay-stat green"><div class="label">Revenue Hari Ini</div><div class="value" id="st-today">—</div><div class="sub" id="st-today-txn"></div></div>
  <div class="pay-stat indigo"><div class="label">Total Revenue</div><div class="value" id="st-total">—</div></div>
  <div class="pay-stat yellow"><div class="label">Menunggu Konfirmasi</div><div class="value" id="st-pending">—</div><div class="sub">status pending</div></div>
</div>

<!-- Filter -->
<div class="sa-card" style="margin-bottom:20px">
  <div class="sa-filter-bar">
    <input type="text" id="fSearch" placeholder="Nama tenant / ref transfer..." style="flex:1;min-width:180px" oninput="debounceLoad()"/>
    <select id="fType" onchange="loadPayments()">
      <option value="">Semua Tipe</option>
      <option value="setup_fee">Setup Fee</option>
      <option value="coin_topup">Topup Coin</option>
      <option value="adjustment">Adjustment</option>
    </select>
    <select id="fStatus" onchange="loadPayments()">
      <option value="confirmed">Confirmed</option>
      <option value="">Semua Status</option>
      <option value="pending">Pending</option>
      <option value="cancelled">Cancelled</option>
    </select>
    <input type="date" id="fFrom" onchange="loadPayments()" style="width:140px" title="Dari tanggal"/>
    <input type="date" id="fTo"   onchange="loadPayments()" style="width:140px" title="Sampai tanggal"/>
  </div>

  <div class="sa-table-wrap">
    <table class="sa-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Tenant</th>
          <th>Tipe</th>
          <th>Paket / Bundle</th>
          <th>Nominal</th>
          <th>Coin</th>
          <th>Metode</th>
          <th>Ref / Pengirim</th>
          <th>Tanggal</th>
          <th>Status</th>
          <th>WA</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody id="paymentsBody">
        <tr><td colspan="12" style="text-align:center;padding:32px;color:var(--ash-dim);">Memuat...</td></tr>
      </tbody>
    </table>
  </div>
  <div class="sa-pagination" id="paginationWrap"></div>
</div>

</div><!-- /panelManual -->

<!-- ══ PANEL: MIDTRANS PAYMENTS ═══════════════════════ -->
<div id="panelMidtrans" style="display:none">

<!-- Filter Midtrans -->
<div class="sa-card" style="margin-bottom:20px">
  <div class="sa-filter-bar">
    <input type="text" id="mtSearch" placeholder="Nama tenant / order_id..." style="flex:1;min-width:180px" oninput="mtDebounceLoad()"/>
    <select id="mtType" onchange="loadMidtrans()">
      <option value="">Semua Tipe</option>
      <option value="topup_coin">Topup Coin</option>
      <option value="setup_fee">Setup Fee</option>
      <option value="outlet_activation">Outlet Activation</option>
    </select>
    <select id="mtStatus" onchange="loadMidtrans()">
      <option value="">Semua Status</option>
      <option value="paid">Paid</option>
      <option value="pending">Pending</option>
      <option value="expired">Expired</option>
      <option value="failed">Failed</option>
      <option value="cancelled">Cancelled</option>
    </select>
    <input type="date" id="mtFrom" onchange="loadMidtrans()" style="width:140px" title="Dari tanggal"/>
    <input type="date" id="mtTo"   onchange="loadMidtrans()" style="width:140px" title="Sampai tanggal"/>
  </div>

  <div class="sa-table-wrap">
    <table class="sa-table">
      <thead>
        <tr>
          <th>Order ID</th>
          <th>Tenant</th>
          <th>Tipe</th>
          <th>Bundle</th>
          <th>Amount</th>
          <th>Payment Type</th>
          <th>Status</th>
          <th>Paid At</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody id="midtransBody">
        <tr><td colspan="9" style="text-align:center;padding:32px;color:var(--ash-dim);">Memuat...</td></tr>
      </tbody>
    </table>
  </div>
  <div class="sa-pagination" id="mtPaginationWrap"></div>
</div>

</div><!-- /panelMidtrans -->

<!-- ══ MODAL: REFUND ══════════════════════════════════ -->
<div class="sa-modal-overlay" id="refundModal">
  <div class="sa-modal" style="max-width:480px;">
    <h3>↩ Refund Midtrans Payment</h3>
    <input type="hidden" id="refundOrderId"/>
    <div class="fg" style="margin-top:16px;">
      <label>Order ID</label>
      <input type="text" id="refundOrderIdDisplay" disabled style="opacity:.7;font-family:var(--mono);font-size:12px;"/>
    </div>
    <div class="fg">
      <label>Tenant</label>
      <input type="text" id="refundTenant" disabled style="opacity:.7;font-size:13px;"/>
    </div>
    <div class="fg">
      <label>Amount</label>
      <input type="text" id="refundAmount" disabled style="opacity:.7;font-family:var(--mono);font-size:13px;color:var(--glow);"/>
    </div>
    <div class="fg">
      <label>Alasan Refund *</label>
      <textarea id="refundReason" placeholder="Jelaskan alasan refund..." rows="3"></textarea>
    </div>
    <p style="font-size:12px;color:#F87171;margin-top:4px;">
      ⚠️ Refund tidak bisa dibatalkan. Coin akan di-rollback otomatis jika topup_coin.
    </p>
    <div class="sa-modal-footer">
      <button class="sa-btn sa-btn-outline" onclick="closeModal('refundModal')">Batal</button>
      <button class="sa-btn sa-btn-danger"  onclick="submitRefund()">↩ Proses Refund</button>
    </div>
  </div>
</div>

<!-- ══ MODAL: KONFIRMASI PEMBAYARAN ══════════════════ -->
<div class="sa-modal-overlay" id="confirmModal">
  <div class="sa-modal" style="max-width:620px;max-height:90vh;overflow-y:auto;">
    <h3>💰 Konfirmasi Pembayaran Manual</h3>

    <!-- 1. Tenant -->
    <div class="modal-section-title">① Tenant</div>
    <div class="fg">
      <label>Cari Tenant *</label>
      <div class="tenant-search-wrap" id="tenantSearchWrap">
        <input type="text" id="tenantSearchInput" placeholder="Ketik nama outlet atau owner..." autocomplete="off" oninput="searchTenant()"/>
        <div class="tenant-dropdown" id="tenantDropdown" style="display:none"></div>
      </div>
      <div id="tenantSelected" style="display:none"></div>
      <input type="hidden" id="selectedTenantId"/>
    </div>

    <!-- 2. Tipe -->
    <div class="modal-section-title">② Tipe Pembayaran</div>
    <div class="fg">
      <label>Tipe *</label>
      <select id="payType" onchange="onTypeChange()">
        <option value="">— Pilih tipe —</option>
        <option value="setup_fee">🏷️ Setup Fee (aktivasi paket)</option>
        <option value="coin_topup">🪙 Topup Coin</option>
        <option value="adjustment">⚖️ Adjustment (bonus/kompensasi)</option>
        <option value="custom">📝 Custom</option>
      </select>
    </div>

    <!-- 2a. Pilih paket (setup_fee) -->
    <div id="sectionPackage" style="display:none">
      <div class="fg">
        <label>Pilih Paket</label>
        <div class="pkg-options" id="packageOptions">
          <div style="color:var(--ash-dim);font-size:12px;padding:8px;">Memuat paket...</div>
        </div>
        <input type="hidden" id="selectedPackageId"/>
      </div>
    </div>

    <!-- 2b. Pilih bundle (coin_topup) -->
    <div id="sectionBundle" style="display:none">
      <div class="fg">
        <label>Pilih Bundle Coin</label>
        <div class="pkg-options" id="bundleOptions">
          <div style="color:var(--ash-dim);font-size:12px;padding:8px;">Memuat bundle...</div>
        </div>
        <input type="hidden" id="selectedBundleId"/>
      </div>
    </div>

    <!-- 2c. Alasan adjustment -->
    <div id="sectionAdj" style="display:none">
      <div class="fg">
        <label>Alasan Adjustment</label>
        <select id="adjReason">
          <option value="kompensasi_downtime">Kompensasi downtime</option>
          <option value="bonus_referral">Bonus referral</option>
          <option value="koreksi_error">Koreksi error</option>
          <option value="promo">Promo</option>
          <option value="lainnya">Lainnya</option>
        </select>
      </div>
    </div>

    <!-- 3. Nominal & Coin -->
    <div class="modal-section-title">③ Nominal & Coin</div>
    <div class="fg-row">
      <div class="fg">
        <label>Nominal Dibayar (Rp)</label>
        <input type="number" id="payNominal" placeholder="100000" min="0" step="1000"/>
        <span class="fg-hint" id="payNominalHint"></span>
      </div>
      <div class="fg">
        <label>Coin Dikreditkan</label>
        <input type="number" id="payCoin" placeholder="0" min="0"/>
        <span class="fg-hint" id="payCoinHint">Isi 0 jika tidak ada kredit coin</span>
      </div>
    </div>

    <!-- 4. Detail pembayaran -->
    <div class="modal-section-title" id="sectionPayDetail">④ Detail Pembayaran</div>
    <div id="sectionPayDetailFields">
      <div class="fg-row">
        <div class="fg">
          <label>Metode</label>
          <select id="payMetode">
            <option value="transfer_bca">Transfer BCA</option>
            <option value="transfer_mandiri">Transfer Mandiri</option>
            <option value="transfer_bri">Transfer BRI</option>
            <option value="transfer_bni">Transfer BNI</option>
            <option value="qris">QRIS</option>
            <option value="cash">Cash</option>
            <option value="lainnya">Lainnya</option>
          </select>
        </div>
        <div class="fg">
          <label>Tanggal Bayar *</label>
          <input type="date" id="payTanggal"/>
        </div>
      </div>
      <div class="fg-row">
        <div class="fg">
          <label>Nama Pengirim</label>
          <input type="text" id="payPengirim" placeholder="Nama pemilik rekening..."/>
        </div>
        <div class="fg">
          <label>No. Referensi / Kode Unik</label>
          <input type="text" id="payRef" placeholder="REF123456..." style="font-family:var(--mono);font-size:12px;"/>
        </div>
      </div>
    </div>

    <!-- 5. Catatan & notif -->
    <div class="modal-section-title">⑤ Catatan & Notifikasi</div>
    <div class="fg">
      <label>Catatan Internal</label>
      <textarea id="payCatatan" placeholder="Catatan untuk tim (tidak dikirim ke tenant)..."></textarea>
    </div>
    <div class="fg">
      <div class="fg-check">
        <input type="checkbox" id="payKirimWa" checked/>
        <label for="payKirimWa">📲 Kirim notifikasi WA ke owner tenant setelah konfirmasi</label>
      </div>
    </div>

    <div class="sa-modal-footer">
      <button class="sa-btn sa-btn-outline" onclick="closeModal('confirmModal')">Batal</button>
      <button class="sa-btn sa-btn-primary" onclick="submitConfirm()">✅ Konfirmasi Pembayaran</button>
    </div>
  </div>
</div>

<!-- ══ WA POPUP setelah konfirmasi ═══════════════════ -->
<div class="sa-modal-overlay" id="waModal">
  <div class="sa-modal" style="max-width:420px;text-align:center;">
    <div style="font-size:48px;margin-bottom:12px;">✅</div>
    <h3 style="margin-bottom:8px;" id="waModalTitle">Pembayaran Dikonfirmasi!</h3>
    <p id="waModalSub" style="font-size:13px;color:var(--ash);margin-bottom:20px;"></p>
    <a id="waModalLink" href="#" target="_blank" class="sa-btn sa-btn-wa"
       style="width:100%;justify-content:center;padding:12px;font-size:14px;margin-bottom:10px;"
       onclick="markWaSent()">
      📲 Buka WhatsApp & Kirim Notif ke Tenant
    </a>
    <button class="sa-btn sa-btn-outline" style="width:100%;justify-content:center;"
            onclick="closeModal('waModal');loadPayments()">Tutup (lewati WA)</button>
  </div>
</div>

<?php saRenderNavClose(); ?>

<script>
const rp   = n => 'Rp ' + parseInt(n||0).toLocaleString('id-ID');
const coin = n => parseInt(n||0).toLocaleString('id-ID');
function esc(s){ const d=document.createElement('div'); d.textContent=String(s??''); return d.innerHTML; }

const typeLabel = { setup_fee:'Setup Fee', coin_topup:'Topup Coin', adjustment:'Adjustment', custom:'Custom' };
const typeCls   = { setup_fee:'pay-type-setup', coin_topup:'pay-type-topup', adjustment:'pay-type-adj', custom:'pay-type-custom' };
const metodeLabel = {
  transfer_bca:'BCA', transfer_mandiri:'Mandiri', transfer_bri:'BRI', transfer_bni:'BNI',
  qris:'QRIS', cash:'Cash', lainnya:'Lainnya'
};

let currentPage = 1;
let debTimer = null;
let _packages = [], _bundles = [];
let _selectedPaymentId = null;

// ── Load payments ─────────────────────────────────────
function debounceLoad(){ clearTimeout(debTimer); debTimer = setTimeout(() => { currentPage=1; loadPayments(); }, 380); }

function loadPayments(){
  const params = new URLSearchParams({
    action: 'list', page: currentPage,
    q:      document.getElementById('fSearch').value,
    type:   document.getElementById('fType').value,
    status: document.getElementById('fStatus').value,
    from:   document.getElementById('fFrom').value,
    to:     document.getElementById('fTo').value,
  });
  saFetch('payments.php?' + params)
    .then(r => r.json()).then(d => {
      if (!d.ok) return;
      renderStats(d.stats);
      renderRows(d.rows);
      renderPagination(d.page, d.pages, d.total);
    });
}

function renderStats(s){
  if (!s) return;
  document.getElementById('st-today').textContent     = rp(s.revenue_today || 0);
  document.getElementById('st-today-txn').textContent = (s.txn_today || 0) + ' transaksi hari ini';
  document.getElementById('st-total').textContent     = rp(s.revenue || 0);
  document.getElementById('st-pending').textContent   = s.pending_count || 0;
}

function renderRows(rows){
  const tb = document.getElementById('paymentsBody');
  if (!rows.length){
    tb.innerHTML = '<tr><td colspan="12" style="text-align:center;padding:32px;color:var(--ash-dim);">Belum ada data.</td></tr>';
    return;
  }
  tb.innerHTML = rows.map(p => {
    const statusHtml = p.status === 'confirmed'
      ? '<span class="pay-status-confirmed">✓ Confirmed</span>'
      : p.status === 'pending'
        ? '<span class="pay-status-pending">⏳ Pending</span>'
        : '<span class="pay-status-cancelled">✗ Cancelled</span>';
    const pkgBdl = p.package_nama || p.bundle_nama || '—';
    const coinHtml = p.coin_dikreditkan > 0 ? `<span class="pay-coin">+${coin(p.coin_dikreditkan)}</span>` : '<span style="color:var(--ash-dim)">—</span>';
    const waHtml = p.notif_wa_sent
      ? '<span title="WA terkirim" style="color:var(--sage);font-size:14px;">📲</span>'
      : (p.owner_wa ? `<a href="#" onclick="openWaLink(${p.id}, '${esc(p.owner_wa)}');return false;" title="Kirim WA" style="color:var(--ash-dim);font-size:14px;">💬</a>` : '—');
    return `<tr>
      <td style="font-family:var(--mono);font-size:11px;color:var(--ash-dim);">#${p.id}</td>
      <td>
        <strong style="font-size:13px;">${esc(p.nama_outlet)}</strong>
        <br><small style="color:var(--ash);">${esc(p.owner_name)}</small>
      </td>
      <td><span class="pay-type-badge ${typeCls[p.type]||'pay-type-custom'}">${typeLabel[p.type]||p.type}</span></td>
      <td style="font-size:12px;color:var(--ash);">${esc(pkgBdl)}</td>
      <td><span class="pay-nominal">${p.nominal_dibayar > 0 ? rp(p.nominal_dibayar) : '—'}</span></td>
      <td>${coinHtml}</td>
      <td style="font-size:12px;color:var(--ash);">${metodeLabel[p.metode]||p.metode}</td>
      <td style="font-size:11px;font-family:var(--mono);color:var(--ash);">
        ${p.ref_transfer ? esc(p.ref_transfer) : ''}
        ${p.nama_pengirim ? `<br><span style="font-family:var(--font);color:var(--ash-dim);">${esc(p.nama_pengirim)}</span>` : ''}
        ${(!p.ref_transfer && !p.nama_pengirim) ? '—' : ''}
      </td>
      <td style="font-size:12px;color:var(--ash);">${p.tanggal_bayar ? new Date(p.tanggal_bayar).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'}) : '—'}</td>
      <td>${statusHtml}</td>
      <td>${waHtml}</td>
      <td>
        ${p.status === 'confirmed'
          ? `<button class="sa-btn sa-btn-sm sa-btn-danger" onclick="cancelPayment(${p.id})">Batal</button>`
          : ''}
      </td>
    </tr>`;
  }).join('');
}

function renderPagination(page, pages, total){
  const el = document.getElementById('paginationWrap');
  let html = `<span style="font-size:12px;color:var(--ash-dim);margin-right:10px;">Total: ${total}</span>`;
  html += `<button class="sa-btn sa-btn-sm sa-btn-outline ${page<=1?'disabled':''}" onclick="gotoPage(${page-1})">‹ Prev</button>`;
  for(let i=Math.max(1,page-2);i<=Math.min(pages,page+2);i++)
    html += `<button class="sa-btn sa-btn-sm ${i===page?'sa-btn-primary':'sa-btn-outline'}" onclick="gotoPage(${i})">${i}</button>`;
  html += `<button class="sa-btn sa-btn-sm sa-btn-outline ${page>=pages?'disabled':''}" onclick="gotoPage(${page+1})">Next ›</button>`;
  el.innerHTML = html;
}
function gotoPage(p){ currentPage=p; loadPayments(); }

// ── Tenant autocomplete ───────────────────────────────
let tenantDebTimer = null;
let _selectedTenant = null;

function searchTenant(){
  clearTimeout(tenantDebTimer);
  tenantDebTimer = setTimeout(() => {
    const q = document.getElementById('tenantSearchInput').value.trim();
    if (q.length < 2) { document.getElementById('tenantDropdown').style.display='none'; return; }
    saFetch('payments.php?action=tenant_search&q=' + encodeURIComponent(q))
      .then(r => r.json()).then(results => {
        const dd = document.getElementById('tenantDropdown');
        if (!results.length){ dd.style.display='none'; return; }
        dd.innerHTML = results.map(t => `
          <div class="tenant-option" onclick="selectTenant(${JSON.stringify(t).replace(/"/g,'&quot;')})">
            <div>
              <div class="t-name">${esc(t.nama_outlet)}</div>
              <div class="t-meta">${esc(t.owner_name)} · ${esc(t.owner_wa)} · ${t.coin_balance ? coin(t.coin_balance)+' coin' : '0 coin'}</div>
            </div>
            <span class="sa-badge sa-badge-${t.status==='active'?'active':'trial'}">${t.status}</span>
          </div>`).join('');
        dd.style.display = 'block';
      });
  }, 300);
}

function selectTenant(t){
  _selectedTenant = t;
  document.getElementById('selectedTenantId').value = t.id;
  document.getElementById('tenantSearchWrap').style.display = 'none';
  document.getElementById('tenantDropdown').style.display = 'none';
  document.getElementById('tenantSelected').style.display = '';
  document.getElementById('tenantSelected').innerHTML = `
    <div class="tenant-selected">
      <div class="t-info">
        <strong>${esc(t.nama_outlet)}</strong>
        <small>${esc(t.owner_name)} · ${esc(t.owner_wa)} · Saldo: ${coin(t.coin_balance)} coin</small>
      </div>
      <button onclick="clearTenant()" title="Ganti tenant">✕</button>
    </div>`;
}

function clearTenant(){
  _selectedTenant = null;
  document.getElementById('selectedTenantId').value = '';
  document.getElementById('tenantSearchWrap').style.display = '';
  document.getElementById('tenantSearchInput').value = '';
  document.getElementById('tenantSelected').style.display = 'none';
}

document.addEventListener('click', e => {
  if (!e.target.closest('#tenantSearchWrap'))
    document.getElementById('tenantDropdown').style.display = 'none';
});

// ── Payment type change ───────────────────────────────
function onTypeChange(){
  const t = document.getElementById('payType').value;
  document.getElementById('sectionPackage').style.display = (t === 'setup_fee')  ? '' : 'none';
  document.getElementById('sectionBundle').style.display  = (t === 'coin_topup') ? '' : 'none';
  document.getElementById('sectionAdj').style.display     = (t === 'adjustment') ? '' : 'none';

  // For adjustment: hide payment detail section
  const isAdj = (t === 'adjustment');
  document.getElementById('sectionPayDetailFields').style.opacity = isAdj ? '.5' : '1';

  // Reset selects
  document.getElementById('selectedPackageId').value = '';
  document.getElementById('selectedBundleId').value  = '';
  document.querySelectorAll('.pkg-option').forEach(el => el.classList.remove('selected'));
  document.getElementById('payNominal').value = '';
  document.getElementById('payCoin').value = '';
  document.getElementById('payNominalHint').textContent = '';
  document.getElementById('payCoinHint').textContent = 'Isi 0 jika tidak ada kredit coin';
}

// ── Package options ───────────────────────────────────
function renderPackageOptions(){
  const el = document.getElementById('packageOptions');
  if (!_packages.length){ el.innerHTML = '<div style="color:var(--ash-dim);font-size:12px;padding:8px;">Tidak ada paket aktif.</div>'; return; }
  el.innerHTML = _packages.map(p => `
    <div class="pkg-option" data-id="${p.id}" onclick="selectPackage(this, ${JSON.stringify(p).replace(/"/g,'&quot;')})">
      <div>
        <div class="p-name">${esc(p.nama)} ${p.is_custom ? '<span style="font-size:10px;color:#F59E0B;">(Custom)</span>' : ''}</div>
        <div class="p-meta">Coin awal: ${coin(p.coin_awal)} · Max outlet: ${p.max_outlets||'∞'} · Trial: ${p.trial_hari} hari</div>
      </div>
      <div class="p-price">
        ${p.is_custom ? '<span style="color:#F59E0B;">Nego</span>' : rp(p.setup_fee)}
        <small>${p.is_custom ? '' : 'setup fee'}</small>
      </div>
    </div>`).join('');
}

function selectPackage(el, pkg){
  document.querySelectorAll('#packageOptions .pkg-option').forEach(e => e.classList.remove('selected'));
  el.classList.add('selected');
  document.getElementById('selectedPackageId').value = pkg.id;
  if (!pkg.is_custom) document.getElementById('payNominal').value = pkg.setup_fee;
  document.getElementById('payCoin').value = pkg.coin_awal;
  document.getElementById('payNominalHint').textContent = pkg.is_custom ? 'Isi nominal sesuai kesepakatan.' : 'Pre-filled dari paket.';
  document.getElementById('payCoinHint').textContent = 'Dari paket — bisa di-override.';
}

// ── Bundle options ────────────────────────────────────
function renderBundleOptions(){
  const el = document.getElementById('bundleOptions');
  if (!_bundles.length){ el.innerHTML = '<div style="color:var(--ash-dim);font-size:12px;padding:8px;">Tidak ada bundle aktif.</div>'; return; }
  el.innerHTML = _bundles.map(b => {
    const bonus = parseFloat(b.bonus_pct) > 0 ? ` +${b.bonus_pct}% bonus` : '';
    return `
    <div class="pkg-option" data-id="${b.id}" onclick="selectBundle(this, ${JSON.stringify(b).replace(/"/g,'&quot;')})">
      <div>
        <div class="p-name">${esc(b.nama)} ${b.is_featured ? '⭐' : ''}</div>
        <div class="p-meta">${coin(b.coin_didapat)} coin${bonus}</div>
      </div>
      <div class="p-price">${rp(b.harga)}</div>
    </div>`;
  }).join('');
}

function selectBundle(el, bdl){
  document.querySelectorAll('#bundleOptions .pkg-option').forEach(e => e.classList.remove('selected'));
  el.classList.add('selected');
  document.getElementById('selectedBundleId').value = bdl.id;
  document.getElementById('payNominal').value = bdl.harga;
  document.getElementById('payCoin').value    = bdl.coin_didapat;
  document.getElementById('payNominalHint').textContent = 'Pre-filled dari bundle.';
  document.getElementById('payCoinHint').textContent    = 'Pre-filled dari bundle — bisa di-override.';
}

// ── Open confirm modal ────────────────────────────────
function openConfirmModal(){
  clearTenant();
  document.getElementById('payType').value = '';
  document.getElementById('payNominal').value = '';
  document.getElementById('payCoin').value = '';
  document.getElementById('payMetode').value = 'transfer_bca';
  document.getElementById('payRef').value = '';
  document.getElementById('payPengirim').value = '';
  document.getElementById('payTanggal').value = new Date().toISOString().slice(0,10);
  document.getElementById('payCatatan').value = '';
  document.getElementById('payKirimWa').checked = true;
  document.getElementById('payNominalHint').textContent = '';
  document.getElementById('payCoinHint').textContent = 'Isi 0 jika tidak ada kredit coin';
  document.getElementById('sectionPackage').style.display = 'none';
  document.getElementById('sectionBundle').style.display  = 'none';
  document.getElementById('sectionAdj').style.display     = 'none';
  document.getElementById('selectedPackageId').value = '';
  document.getElementById('selectedBundleId').value  = '';

  // Load packages & bundles if not yet loaded
  if (!_packages.length) {
    saFetch('payments.php?action=get_packages').then(d => {
      _packages = d;
      renderPackageOptions();
    });
  } else renderPackageOptions();
  if (!_bundles.length) {
    saFetch('payments.php?action=get_bundles').then(d => {
      _bundles = d;
      renderBundleOptions();
    });
  } else renderBundleOptions();

  document.getElementById('confirmModal').classList.add('open');
  setTimeout(() => document.getElementById('tenantSearchInput').focus(), 150);
}

// ── Submit payment ────────────────────────────────────
function submitConfirm(){
  const tenantId = document.getElementById('selectedTenantId').value;
  const type     = document.getElementById('payType').value;
  const nominal  = document.getElementById('payNominal').value;
  const coin     = document.getElementById('payCoin').value;

  if (!tenantId) { saShowToast('Pilih tenant terlebih dahulu.', 'error'); return; }
  if (!type)     { saShowToast('Pilih tipe pembayaran.', 'error'); return; }
  if (type !== 'adjustment' && (!nominal || nominal < 1)) { saShowToast('Isi nominal pembayaran.', 'error'); return; }

  const payload = {
    tenant_id:         tenantId,
    type,
    package_id:        document.getElementById('selectedPackageId').value || 0,
    bundle_id:         document.getElementById('selectedBundleId').value  || 0,
    nominal_dibayar:   nominal  || 0,
    coin_dikreditkan:  coin     || 0,
    metode:            document.getElementById('payMetode').value,
    nama_pengirim:     document.getElementById('payPengirim').value,
    ref_transfer:      document.getElementById('payRef').value,
    tanggal_bayar:     document.getElementById('payTanggal').value,
    catatan:           document.getElementById('payCatatan').value,
    adjustment_reason: document.getElementById('adjReason').value,
    kirim_wa:          document.getElementById('payKirimWa').checked,
  };

  saFetch('payments.php?action=confirm', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  }).then(r => r.json()).then(d => {
    if (d.error) { saShowToast(d.error, 'error'); return; }
    closeModal('confirmModal');
    _selectedPaymentId = d.payment_id;

    if (d.wa_link) {
      document.getElementById('waModalTitle').textContent = 'Pembayaran #' + d.payment_id + ' Dikonfirmasi!';
      document.getElementById('waModalSub').textContent  = d.msg;
      document.getElementById('waModalLink').href = d.wa_link;
      document.getElementById('waModal').classList.add('open');
    } else {
      saShowToast(d.msg, 'success');
      loadPayments();
    }
  });
}

// ── WA helpers ───────────────────────────────────────
function openWaLink(payId, ownerWa){
  // Buka modal konfirmasi WA untuk payment existing
  _selectedPaymentId = payId;
  saFetch('payments.php?action=list&page=1').then(d => {
    const p = (d.rows||[]).find(r => r.id == payId);
    if (!p) return;
    const phone = ownerWa.replace(/[^0-9]/g,'').replace(/^0/, '62');
    const msg = `✅ *Pembayaran Diterima — LAMASY*\n\nHalo ${p.owner_name}!\n\nPembayaran kamu sudah kami terima dan diproses.\n\n`
              + (p.bundle_nama||p.package_nama ? `📦 Paket/Bundle : ${p.bundle_nama||p.package_nama}\n` : '')
              + (p.nominal_dibayar > 0 ? `💰 Nominal      : ${rp(p.nominal_dibayar)}\n` : '')
              + (p.coin_dikreditkan > 0 ? `🪙 Coin         : +${coin(p.coin_dikreditkan)} coin\n` : '')
              + `\nRef: ${p.ref_transfer||'-'}\nTanggal: ${p.tanggal_bayar||'-'}\n\nTerima kasih sudah menggunakan LAMASY! 🙏`;

    document.getElementById('waModalTitle').textContent = 'Kirim Notifikasi WA';
    document.getElementById('waModalSub').textContent   = p.nama_outlet + ' · ' + ownerWa;
    document.getElementById('waModalLink').href = 'https://wa.me/' + phone + '?text=' + encodeURIComponent(msg);
    document.getElementById('waModal').classList.add('open');
  });
}

function markWaSent(){
  if (!_selectedPaymentId) return;
  saFetch('payments.php?action=mark_wa_sent', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id: _selectedPaymentId }),
  });
}

// ── Cancel payment ────────────────────────────────────
async function cancelPayment(id){
  if (!await lmConfirm(`Batalkan pembayaran #${id}?\n\nCoin yang sudah dikreditkan akan di-rollback dari saldo tenant.`)) return;
  saFetch('payments.php?action=cancel', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id }),
  }).then(r=>r.json()).then(d => {
    if (d.error) { saShowToast(d.error, 'error'); return; }
    saShowToast(d.msg, 'success');
    loadPayments();
  });
}

function closeModal(id){
  document.getElementById(id).classList.remove('open');
  if (id === 'waModal') { loadPayments(); }
}

// Backdrop + Escape
document.querySelectorAll('.sa-modal-overlay').forEach(el => {
  el.addEventListener('click', e => { if (e.target === el) closeModal(el.id); });
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') document.querySelectorAll('.sa-modal-overlay.open').forEach(el => closeModal(el.id));
});

// ── Tab switching ─────────────────────────────────────
function switchTab(tab) {
  const isManual = (tab === 'manual');
  document.getElementById('panelManual').style.display    = isManual ? '' : 'none';
  document.getElementById('panelMidtrans').style.display  = isManual ? 'none' : '';
  document.getElementById('tabManualBtn').classList.toggle('active', isManual);
  document.getElementById('tabMidtransBtn').classList.toggle('active', !isManual);
  if (!isManual && !_mtLoaded) { loadMidtrans(); }
}

// ── Midtrans Payments ─────────────────────────────────
let mtCurrentPage = 1;
let mtDebTimer    = null;
let _mtLoaded     = false;

const mtTypeLabel   = { topup_coin:'Topup Coin', setup_fee:'Setup Fee', outlet_activation:'Outlet Activation' };
const mtTypeCls     = { topup_coin:'mt-type-topup', setup_fee:'mt-type-setup', outlet_activation:'mt-type-outlet' };
const payTypeLabel  = { qris:'QRIS', bank_transfer:'VA Bank', gopay:'GoPay', shopeepay:'ShopeePay', manual_transfer:'🏦 Transfer Manual' };

function mtDebounceLoad(){ clearTimeout(mtDebTimer); mtDebTimer = setTimeout(() => { mtCurrentPage=1; loadMidtrans(); }, 380); }

function loadMidtrans(){
  _mtLoaded = true;
  const params = new URLSearchParams({
    action: 'list_midtrans', page: mtCurrentPage,
    q:      document.getElementById('mtSearch').value,
    type:   document.getElementById('mtType').value,
    status: document.getElementById('mtStatus').value,
    from:   document.getElementById('mtFrom').value,
    to:     document.getElementById('mtTo').value,
  });
  saFetch('payments.php?' + params)
    .then(r => r.json()).then(d => {
      if (!d.ok) return;
      renderMidtransRows(d.rows);
      renderMtPagination(d.page, d.pages, d.total);
    });
}

function renderMidtransRows(rows){
  const tb = document.getElementById('midtransBody');
  if (!rows.length){
    tb.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:32px;color:var(--ash-dim);">Belum ada data.</td></tr>';
    return;
  }
  const now = Date.now();
  tb.innerHTML = rows.map(p => {
    const statusCls = 'mt-status-' + (p.status || 'pending');
    const statusIcon = { paid:'✓', pending:'⏳', expired:'⌛', failed:'✗', cancelled:'✗' }[p.status] || '';
    const statusLabel = { paid:'Paid', pending:'Pending', expired:'Expired', failed:'Failed', cancelled:'Cancelled' }[p.status] || p.status;

    const paidAt = p.paid_at
      ? new Date(p.paid_at).toLocaleDateString('id-ID', {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'})
      : '—';

    // Show refund button only for paid, within 90-day window (informational check);
    // manual_transfer pending rows get confirm/reject buttons instead
    let actionBtn = '';
    if (p.status === 'paid') {
      const paidTs = p.paid_at ? new Date(p.paid_at).getTime() : 0;
      const daysOld = (now - paidTs) / 86400000;
      const withinWindow = daysOld <= 90;
      actionBtn = `<button class="sa-btn sa-btn-sm sa-btn-danger" ${withinWindow ? '' : 'title="Mungkin di luar 90-hari refund window Midtrans"'}
                    onclick="openRefundModal(${JSON.stringify(p).replace(/"/g,'&quot;')})">↩ Refund</button>`;
    } else if (p.payment_type === 'manual_transfer' && p.status === 'pending') {
      actionBtn = `<button class="sa-btn sa-btn-sm sa-btn-primary" onclick="confirmManual(${p.id}, '${esc(p.order_id)}')">✓ Konfirmasi Lunas</button>
                   <button class="sa-btn sa-btn-sm sa-btn-outline" onclick="rejectManual(${p.id})">✗ Tolak</button>`;
    }

    return `<tr>
      <td style="font-family:var(--mono);font-size:11px;color:var(--ash-dim);" title="${esc(p.order_id)}">${esc(p.order_id.length > 28 ? p.order_id.slice(0,25)+'…' : p.order_id)}</td>
      <td style="font-size:13px;font-weight:600;">${esc(p.nama_perusahaan || '—')}</td>
      <td><span class="pay-type-badge ${mtTypeCls[p.type]||''}">${mtTypeLabel[p.type]||p.type}</span></td>
      <td style="font-size:12px;color:var(--ash);">${esc(p.bundle_nama || '—')}</td>
      <td><span class="pay-nominal">${rp(p.amount)}</span></td>
      <td style="font-size:12px;color:var(--ash);">${payTypeLabel[p.payment_type] || (p.payment_type ? esc(p.payment_type) : '—')}${p.va_bank ? ' · '+p.va_bank.toUpperCase() : ''}</td>
      <td><span class="${statusCls}">${statusIcon} ${statusLabel}</span></td>
      <td style="font-size:12px;color:var(--ash);">${paidAt}</td>
      <td>${actionBtn}</td>
    </tr>`;
  }).join('');
}

function renderMtPagination(page, pages, total){
  const el = document.getElementById('mtPaginationWrap');
  let html = `<span style="font-size:12px;color:var(--ash-dim);margin-right:10px;">Total: ${total}</span>`;
  html += `<button class="sa-btn sa-btn-sm sa-btn-outline ${page<=1?'disabled':''}" onclick="mtGotoPage(${page-1})">‹ Prev</button>`;
  for(let i=Math.max(1,page-2);i<=Math.min(pages,page+2);i++)
    html += `<button class="sa-btn sa-btn-sm ${i===page?'sa-btn-primary':'sa-btn-outline'}" onclick="mtGotoPage(${i})">${i}</button>`;
  html += `<button class="sa-btn sa-btn-sm sa-btn-outline ${page>=pages?'disabled':''}" onclick="mtGotoPage(${page+1})">Next ›</button>`;
  el.innerHTML = html;
}
function mtGotoPage(p){ mtCurrentPage=p; loadMidtrans(); }

// ── Refund modal ──────────────────────────────────────
function openRefundModal(p){
  document.getElementById('refundOrderId').value        = p.order_id;
  document.getElementById('refundOrderIdDisplay').value = p.order_id;
  document.getElementById('refundTenant').value         = p.nama_perusahaan || '—';
  document.getElementById('refundAmount').value         = rp(p.amount);
  document.getElementById('refundReason').value         = '';
  document.getElementById('refundModal').classList.add('open');
}

function submitRefund(){
  const orderId = document.getElementById('refundOrderId').value;
  const reason  = document.getElementById('refundReason').value.trim();
  if (!reason) { saShowToast('Alasan refund wajib diisi.', 'error'); return; }

  const form = new FormData();
  form.append('order_id', orderId);
  form.append('reason',   reason);

  saFetch('payments.php?action=refund', { method:'POST', body: form })
    .then(r => r.json()).then(d => {
      if (d.error) { saShowToast(d.error, 'error'); return; }
      saShowToast(d.msg || 'Refund berhasil.', 'success');
      closeModal('refundModal');
      loadMidtrans();
    });
}

function confirmManual(id, orderId){
  if (!confirm('Konfirmasi transfer manual sudah masuk untuk order ' + orderId + '?\nCoin/aktivasi akan langsung dikreditkan.')) return;
  const form = new FormData(); form.append('id', id);
  saFetch('payments.php?action=confirm_manual', { method:'POST', body: form })
    .then(r => r.json()).then(d => {
      if (d.error) { saShowToast(d.error, 'error'); return; }
      saShowToast(d.msg || 'Dikonfirmasi.', 'success');
      loadMidtrans();
    });
}
function rejectManual(id){
  if (!confirm('Tolak pembayaran manual ini? Status jadi cancelled.')) return;
  const form = new FormData(); form.append('id', id);
  saFetch('payments.php?action=reject_manual', { method:'POST', body: form })
    .then(r => r.json()).then(d => {
      if (d.error) { saShowToast(d.error, 'error'); return; }
      saShowToast(d.msg || 'Ditolak.', 'success');
      loadMidtrans();
    });
}

// Initial load
loadPayments();
</script>
</body>
</html>

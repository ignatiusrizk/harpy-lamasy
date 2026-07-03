<?php
// ══════════════════════════════════════════════════════
// superadmin/client_detail.php — Full Tenant Detail
// ══════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';

date_default_timezone_set('Asia/Jakarta');

$tenantId = intval($_GET['id'] ?? 0);
$action   = $_GET['action'] ?? '';

$db = Database::get();

// ── API ACTIONS ───────────────────────────────────────
if ($action) {
    header('Content-Type: application/json');

    if ($action === 'get_coin_history') {
        $id = (int)($_GET['id'] ?? 0);
        $rows = $db->prepare(
            "SELECT * FROM coin_ledger WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 50"
        );
        $rows->execute([$id]);
        echo json_encode($rows->fetchAll()); exit;
    }

    if ($action === 'get_payments') {
        $id = (int)($_GET['id'] ?? 0);
        $rows = $db->prepare(
            "SELECT * FROM payments WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 50"
        );
        $rows->execute([$id]);
        echo json_encode($rows->fetchAll()); exit;
    }

    // ── GET: billing summary + saas_manual_payments ──
    if ($action === 'get_billing') {
        $id = (int)($_GET['id'] ?? 0);

        $stats = $db->prepare(
            "SELECT
               COALESCE(SUM(nominal_dibayar), 0)                                           AS grand_total,
               COALESCE(SUM(CASE WHEN type='setup_fee'  THEN nominal_dibayar END), 0)      AS setup_total,
               COALESCE(SUM(CASE WHEN type='coin_topup' THEN nominal_dibayar END), 0)      AS topup_total,
               COALESCE(SUM(coin_dikreditkan), 0)                                          AS total_coin_in,
               COUNT(*)                                                                     AS total_txn
             FROM saas_manual_payments WHERE tenant_id=? AND status='confirmed'"
        );
        $stats->execute([$id]);
        $billingStats = $stats->fetch(PDO::FETCH_ASSOC);

        $rows = $db->prepare(
            "SELECT p.*,
                    pkg.nama AS package_nama,
                    bdl.nama AS bundle_nama,
                    sa.name  AS superadmin_nama
             FROM saas_manual_payments p
             LEFT JOIN saas_packages     pkg ON pkg.id = p.package_id
             LEFT JOIN saas_coin_bundles bdl ON bdl.id = p.bundle_id
             LEFT JOIN super_admins      sa  ON sa.id  = p.superadmin_id
             WHERE p.tenant_id = ? AND p.status != 'cancelled'
             ORDER BY p.id DESC LIMIT 10"
        );
        $rows->execute([$id]);

        echo json_encode([
            'stats' => $billingStats,
            'rows'  => $rows->fetchAll(PDO::FETCH_ASSOC),
        ]);
        exit;
    }

    // ── POST: coin adjustment (bonus/kompensasi) ─────
    if ($action === 'adjustment') {
        saVerifyCsrf();
        $id     = (int)($_POST['tenant_id'] ?? 0);
        $coin   = (int)($_POST['coin'] ?? 0);
        $reason = $_POST['reason'] ?? 'lainnya';
        $note   = trim($_POST['note'] ?? '');

        if ($coin === 0) { echo json_encode(['error' => 'Jumlah coin tidak boleh 0.']); exit; }

        $allowed = ['kompensasi_downtime','bonus_referral','koreksi_error','promo','lainnya'];
        if (!in_array($reason, $allowed)) $reason = 'lainnya';

        try {
            $db->beginTransaction();
            $lockS = $db->prepare("SELECT coin_balance FROM tenants WHERE id=? FOR UPDATE");
            $lockS->execute([$id]);
            $cur     = (int)$lockS->fetchColumn();
            $newBal  = max(0, $cur + $coin);
            $ledType = $coin > 0 ? 'topup' : 'deduct';

            $db->prepare("UPDATE tenants SET coin_balance=? WHERE id=?")->execute([$newBal, $id]);

            // Catat di saas_manual_payments sebagai adjustment
            $db->prepare(
                "INSERT INTO saas_manual_payments
                   (tenant_id, superadmin_id, type, nominal_dibayar, coin_dikreditkan,
                    metode, tanggal_bayar, catatan, adjustment_reason, status)
                 VALUES (?, ?, 'adjustment', 0, ?, 'lainnya', CURDATE(), ?, ?, 'confirmed')"
            )->execute([$id, $_SESSION['superadmin_id'], $coin, $note ?: null, $reason]);
            $payId = $db->lastInsertId();

            $db->prepare(
                "INSERT INTO coin_ledger
                   (tenant_id, outlet_id, type, amount, feature_used, description, balance_after, ref_id)
                 VALUES (?, NULL, ?, ?, 'manual_adjustment', ?, ?, ?)"
            )->execute([$id, $ledType, abs($coin),
                "Adjustment: $reason" . ($note ? " — $note" : ''), $newBal, "ADJ-$payId"]);

            $db->commit();
            logSuperAdminAction('adjustment_coin', $id,
                "Adjustment " . ($coin > 0 ? "+$coin" : "$coin") . " coin — $reason");
            echo json_encode(['success' => true, 'new_balance' => $newBal,
                'msg' => ($coin > 0 ? "+$coin" : "$coin") . " coin. Saldo baru: " . number_format($newBal)]);
        } catch (Throwable $e) {
            $db->rollBack();
            apiErr($e);
        }
        exit;
    }

    if ($action === 'get_notes') {
        $id = (int)($_GET['id'] ?? 0);
        $rows = $db->prepare(
            "SELECT n.*, s.name as sa_nama FROM tenant_notes n
             LEFT JOIN super_admins s ON s.id = n.superadmin_id
             WHERE n.tenant_id = ? ORDER BY n.is_pinned DESC, n.created_at DESC"
        );
        $rows->execute([$id]);
        echo json_encode($rows->fetchAll()); exit;
    }

    if ($action === 'add_note') {
        saVerifyCsrf();
        $id   = (int)($_POST['tenant_id'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        if (!$note) { echo json_encode(['error' => 'Note kosong.']); exit; }
        $db->prepare(
            "INSERT INTO tenant_notes (tenant_id, superadmin_id, note) VALUES (?,?,?)"
        )->execute([$id, $_SESSION['superadmin_id'], $note]);
        logSuperAdminAction('add_note', $id, 'Tambah catatan: ' . substr($note, 0, 80));
        echo json_encode(['success' => true, 'id' => $db->lastInsertId()]); exit;
    }

    if ($action === 'delete_note') {
        saVerifyCsrf();
        $nid = (int)($_POST['note_id'] ?? 0);
        $db->prepare("DELETE FROM tenant_notes WHERE id = ? AND superadmin_id = ?")->execute([$nid, $_SESSION['superadmin_id']]);
        echo json_encode(['success' => true]); exit;
    }

    if ($action === 'pin_note') {
        saVerifyCsrf();
        $nid = (int)($_POST['note_id'] ?? 0);
        $db->prepare("UPDATE tenant_notes SET is_pinned = 1 - is_pinned WHERE id = ?")->execute([$nid]);
        echo json_encode(['success' => true]); exit;
    }

    if ($action === 'get_comms') {
        $id = (int)($_GET['id'] ?? 0);
        $rows = $db->prepare(
            "SELECT s.*, sa.name as sa_nama FROM support_tickets s
             LEFT JOIN super_admins sa ON sa.id = s.superadmin_id
             WHERE s.tenant_id = ? ORDER BY s.created_at DESC LIMIT 50"
        );
        $rows->execute([$id]);
        echo json_encode($rows->fetchAll()); exit;
    }

    if ($action === 'add_comm') {
        saVerifyCsrf();
        $id      = (int)($_POST['tenant_id'] ?? 0);
        $channel = $_POST['channel'] ?? 'wa';
        $subject = trim($_POST['subject'] ?? '');
        $msg     = trim($_POST['message'] ?? '');
        $type    = $_POST['type'] ?? 'support';
        $db->prepare(
            "INSERT INTO support_tickets (tenant_id, superadmin_id, channel, subject, message, type)
             VALUES (?,?,?,?,?,?)"
        )->execute([$id, $_SESSION['superadmin_id'], $channel, $subject, $msg, $type]);
        logSuperAdminAction('add_comm', $id, "$channel: $subject");
        echo json_encode(['success' => true]); exit;
    }

    if ($action === 'topup') {
        saVerifyCsrf();
        $id       = (int)($_POST['tenant_id'] ?? 0);
        $amount   = (int)($_POST['amount'] ?? 0);
        $note     = trim($_POST['note'] ?? '');
        $outletId = (int)($_POST['outlet_id'] ?? 0);
        if ($amount <= 0) { echo json_encode(['error' => 'Jumlah tidak valid.']); exit; }
        try {
            $db->beginTransaction();
            // Tentukan mode: per_outlet → topup ke outlets.coin_balance jika outlet_id diberikan
            $modeRow = $db->prepare("SELECT coin_mode FROM tenants WHERE id=? LIMIT 1");
            $modeRow->execute([$id]);
            $coinMode = $modeRow->fetchColumn() ?: 'shared';

            if ($coinMode === 'per_outlet' && $outletId > 0) {
                // Validasi outlet milik tenant ini
                $oVal = $db->prepare("SELECT coin_balance FROM outlets WHERE id=? AND tenant_id=? FOR UPDATE");
                $oVal->execute([$outletId, $id]);
                $oRow = $oVal->fetch();
                if (!$oRow) {
                    $db->rollBack();
                    echo json_encode(['error' => 'Outlet tidak valid.']); exit;
                }
                $newBal = (int)$oRow['coin_balance'] + $amount;
                $db->prepare("UPDATE outlets SET coin_balance=? WHERE id=? AND tenant_id=?")->execute([$newBal, $outletId, $id]);
                $db->prepare("INSERT INTO coin_ledger (tenant_id, outlet_id, type, amount, feature_used, description, balance_after)
                              VALUES (?, ?, 'topup', ?, 'manual_topup', ?, ?)")
                   ->execute([$id, $outletId, $amount, $note ?: 'Manual topup (per-outlet) by super admin', $newBal]);
                $db->commit();
                logSuperAdminAction('topup_coin', $id, "Topup $amount coin → outlet #$outletId");
                echo json_encode(['success' => true, 'new_balance' => $newBal, 'mode' => 'per_outlet']);
            } else {
                // Shared mode → topup ke tenants.coin_balance
                $stm = $db->prepare("SELECT coin_balance FROM tenants WHERE id=? FOR UPDATE");
                $stm->execute([$id]);
                $bal    = (int)$stm->fetchColumn();
                $newBal = $bal + $amount;
                $db->prepare("UPDATE tenants SET coin_balance=? WHERE id=?")->execute([$newBal, $id]);
                $db->prepare("INSERT INTO coin_ledger (tenant_id, type, amount, feature_used, description, balance_after)
                              VALUES (?, 'topup', ?, 'manual_topup', ?, ?)")
                   ->execute([$id, $amount, $note ?: 'Manual topup by super admin', $newBal]);
                $db->commit();
                logSuperAdminAction('topup_coin', $id, "Topup $amount coin (shared)");
                echo json_encode(['success' => true, 'new_balance' => $newBal, 'mode' => 'shared']);
            }
        } catch (Throwable $e) { $db->rollBack(); apiErr($e); }
        exit;
    }

    if ($action === 'set_coin_mode') {
        saVerifyCsrf();
        $id   = (int)($_POST['tenant_id'] ?? 0);
        $mode = ($_POST['mode'] ?? '') === 'per_outlet' ? 'per_outlet' : 'shared';
        try {
            require_once dirname(__DIR__) . '/core/CoinModeManager.php';
            $res = CoinModeManager::switchMode($id, $mode, 'sa');
            if (!$res['ok']) { echo json_encode(['error' => $res['error'] ?? 'Gagal ganti mode']); exit; }
            logSuperAdminAction('set_coin_mode', $id, "Coin mode → {$mode} (saldo dipindah: {$res['moved']})");
            echo json_encode(['success' => true, 'mode' => $mode, 'moved' => $res['moved']]);
        } catch (Throwable $e) {
            apiErr($e);
        }
        exit;
    }

    if ($action === 'suspend') {
        saVerifyCsrf();
        $id = (int)($_POST['tenant_id'] ?? 0);
        $db->prepare("UPDATE tenants SET status='suspended' WHERE id=?")->execute([$id]);
        logSuperAdminAction('suspend', $id, 'Tenant disuspend');
        echo json_encode(['success' => true]); exit;
    }

    if ($action === 'activate') {
        saVerifyCsrf();
        $id = (int)($_POST['tenant_id'] ?? 0);
        $db->prepare("UPDATE tenants SET status='active' WHERE id=?")->execute([$id]);
        logSuperAdminAction('activate', $id, 'Tenant diaktifkan');
        echo json_encode(['success' => true]); exit;
    }

    if ($action === 'extend_trial') {
        saVerifyCsrf();
        $id   = (int)($_POST['tenant_id'] ?? 0);
        $days = max(1, (int)($_POST['days'] ?? 7));
        $db->prepare("UPDATE tenants SET trial_ends_at = DATE_ADD(GREATEST(IFNULL(trial_ends_at, NOW()), NOW()), INTERVAL ? DAY) WHERE id=?")
           ->execute([$days, $id]);
        logSuperAdminAction('extend_trial', $id, "Trial diperpanjang $days hari");
        echo json_encode(['success' => true]); exit;
    }

    if ($action === 'reset_password') {
        saVerifyCsrf();
        $id     = (int)($_POST['tenant_id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        $pw     = $_POST['new_password'] ?? '';
        if (strlen($pw) < 6) { echo json_encode(['error' => 'Password minimal 6 karakter.']); exit; }
        $hash = password_hash($pw, PASSWORD_BCRYPT);
        $db->prepare("UPDATE hl_users SET password=? WHERE id=? AND tenant_id=?")->execute([$hash, $userId, $id]);
        logSuperAdminAction('reset_password', $id, "Reset password user #$userId");
        echo json_encode(['success' => true]); exit;
    }

    // ── GET: tickets for this tenant ─────────────────────
    if ($action === 'get_tickets') {
        $id = (int)($_GET['id'] ?? 0);

        $statsS = $db->prepare(
            "SELECT
               COUNT(*) AS total,
               SUM(CASE WHEN status IN ('open','in_progress','waiting_tenant') THEN 1 ELSE 0 END) AS open_count,
               SUM(CASE WHEN status='resolved' AND MONTH(resolved_at)=MONTH(NOW()) AND YEAR(resolved_at)=YEAR(NOW()) THEN 1 ELSE 0 END) AS resolved_month,
               ROUND(AVG(CASE WHEN first_response_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, first_response_at) END), 0) AS avg_resp_min
             FROM support_tickets WHERE tenant_id=?"
        );
        $statsS->execute([$id]);
        $tStats = $statsS->fetch(PDO::FETCH_ASSOC);

        $rowsS = $db->prepare(
            "SELECT t.id, t.subject, t.status, t.priority, t.category,
                    t.created_at, t.updated_at, t.resolved_at,
                    sa.name AS assigned_nama,
                    (SELECT COUNT(*) FROM support_ticket_replies r WHERE r.ticket_id=t.id AND r.is_internal=0) AS reply_count
             FROM support_tickets t
             LEFT JOIN super_admins sa ON sa.id = t.assigned_to
             WHERE t.tenant_id = ?
             ORDER BY t.updated_at DESC, t.created_at DESC
             LIMIT 30"
        );
        $rowsS->execute([$id]);

        echo json_encode([
            'stats' => $tStats,
            'rows'  => $rowsS->fetchAll(PDO::FETCH_ASSOC),
        ]);
        exit;
    }

    // ── GET: outlet list untuk modal migrations ──────
    if ($action === 'get_outlets') {
        $id    = (int)($_GET['id'] ?? 0);
        $oRows = $db->prepare("SELECT id, nama_outlet, status FROM outlets WHERE tenant_id=? ORDER BY id");
        $oRows->execute([$id]);
        echo json_encode($oRows->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // ── GET: migration history untuk tenant ini ──────
    if ($action === 'get_migrations') {
        $id    = (int)($_GET['id'] ?? 0);
        $limit = 10;
        $mjQ   = $db->prepare("
            SELECT j.id, j.entity_type, j.file_name, j.status,
                   j.total_rows, j.success_rows, j.failed_rows, j.skipped_rows,
                   j.is_assisted, j.assisted_paid, j.created_at, j.completed_at,
                   sa.name AS admin_nama
            FROM hl_migration_jobs j
            LEFT JOIN super_admins sa ON sa.id = j.imported_by_admin
            WHERE j.tenant_id = ?
            ORDER BY j.created_at DESC
            LIMIT $limit
        ");
        $mjQ->execute([$id]);
        echo json_encode($mjQ->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    echo json_encode(['error' => 'Action tidak dikenal.']); exit;
}

// ── Load tenant data ──────────────────────────────────
if (!$tenantId) { header('Location: clients.php'); exit; }

$stm = $db->prepare("SELECT * FROM tenants WHERE id=?");
$stm->execute([$tenantId]);
$tenant = $stm->fetch();
if (!$tenant) { header('Location: clients.php'); exit; }

// Effective coin = tenants.coin_balance (shared pool) + trial_coin_balance dari outlet trial
// Trial coins disimpan terpisah di outlets.trial_coin_balance, bukan di tenants.coin_balance
$trialCoinQ = $db->prepare(
    "SELECT COALESCE(SUM(trial_coin_balance),0) FROM outlets WHERE tenant_id=? AND status='trial'"
);
$trialCoinQ->execute([$tenantId]);
$trialCoinTotal = (int)$trialCoinQ->fetchColumn();
$effectiveCoin  = (int)$tenant['coin_balance'] + $trialCoinTotal;

$stm2 = $db->prepare("SELECT MAX(last_login) FROM hl_users WHERE tenant_id=?");
$stm2->execute([$tenantId]);
$lastLogin = $stm2->fetchColumn();

// Stats
$statsStm = $db->prepare(
    "SELECT
       COUNT(*) as total_orders,
       COUNT(CASE WHEN tanggal >= NOW() - INTERVAL 30 DAY THEN 1 END) as orders_30d
     FROM hl_transaksi WHERE tenant_id=?"
);
$statsStm->execute([$tenantId]);
$orderStats = $statsStm->fetch();

$coinStats = $db->prepare(
    "SELECT
       COALESCE(SUM(CASE WHEN type='deduct' THEN amount END),0) as total_used,
       COALESCE(SUM(CASE WHEN type='topup'  THEN amount END),0) as total_topup
     FROM coin_ledger WHERE tenant_id=?"
);
$coinStats->execute([$tenantId]);
$coinStat = $coinStats->fetch();

$coinByFeature = $db->prepare(
    "SELECT feature_used, SUM(amount) as total
     FROM coin_ledger WHERE tenant_id=? AND type='deduct'
     GROUP BY feature_used ORDER BY total DESC"
);
$coinByFeature->execute([$tenantId]);
$featureStat = $coinByFeature->fetchAll();

// Health
$loginOk   = $lastLogin && strtotime($lastLogin) > strtotime('-7 days');
$txWeek    = (int)$db->prepare("SELECT COUNT(*) FROM hl_transaksi WHERE tenant_id=? AND tanggal >= NOW()-INTERVAL 7 DAY")->execute([$tenantId]) ? 0 : 0;
$txStm     = $db->prepare("SELECT COUNT(*) FROM hl_transaksi WHERE tenant_id=? AND tanggal >= NOW()-INTERVAL 7 DAY");
$txStm->execute([$tenantId]);
$txWeek    = (int)$txStm->fetchColumn();
$coinOk    = $effectiveCoin > 20000;
$layCount  = (int)$db->prepare("SELECT COUNT(*) FROM hl_layanan WHERE tenant_id=?")->execute([$tenantId]) ? 0 : 0;
$laySt     = $db->prepare("SELECT COUNT(*) FROM hl_layanan WHERE tenant_id=?");
$laySt->execute([$tenantId]);
$layCount  = (int)$laySt->fetchColumn();
$onboardOk = $layCount > 0 && (int)$orderStats['total_orders'] > 0;

// Users
$users = $db->prepare("SELECT id, username, nama, role, last_login FROM hl_users WHERE tenant_id=? ORDER BY role, nama")->execute([$tenantId]) ? [] : [];
$usrSt = $db->prepare("SELECT id, username, nama, role, last_login FROM hl_users WHERE tenant_id=? ORDER BY role, nama");
$usrSt->execute([$tenantId]);
$users = $usrSt->fetchAll();

// Coin mode & outlet list (untuk topup per-outlet + tab Outlets)
$coinMode = $tenant['coin_mode'] ?? 'shared';
$outletListQ = $db->prepare(
    "SELECT id, nama_outlet, slug, alamat, kota, telepon, status,
            coin_balance, trial_coin_balance, is_main, setup_done, created_at
       FROM outlets WHERE tenant_id=? AND status NOT IN ('closed')
      ORDER BY is_main DESC, nama_outlet ASC"
);
$outletListQ->execute([$tenantId]);
$outletList = $outletListQ->fetchAll(PDO::FETCH_ASSOC);

// Billing stats (lifetime)
$bsSt = $db->prepare(
    "SELECT COALESCE(SUM(nominal_dibayar), 0) AS grand_total,
            COALESCE(SUM(coin_dikreditkan), 0) AS total_coin_in,
            COUNT(*) AS total_txn
     FROM saas_manual_payments WHERE tenant_id=? AND status='confirmed'"
);
$bsSt->execute([$tenantId]);
$billingStat = $bsSt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php saRenderHead('Detail: ' . htmlspecialchars($tenant['nama_perusahaan'] ?? $tenant['slug'])); ?>
</head>
<body>
<div class="sa-layout">
<?php saRenderNav('clients', 'Detail Client'); ?>

<div class="sa-page-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div>
    <a href="clients.php" style="font-size:12.5px;color:var(--ash);text-decoration:none;margin-bottom:6px;display:block;">← Kembali ke Clients</a>
    <h1><?= htmlspecialchars($tenant['nama_perusahaan'] ?: $tenant['slug']) ?></h1>
    <p>
      <span class="sa-badge sa-badge-<?= $tenant['status'] === 'active' ? 'active' : ($tenant['status'] === 'pending_verification' ? 'trial' : 'suspended') ?>">
        <?= ucfirst(str_replace('_',' ',$tenant['status'])) ?>
      </span>
      <span style="color:var(--ash-dim);margin-left:10px;font-family:var(--mono);font-size:12px;"><?= htmlspecialchars($tenant['slug']) ?></span>
    </p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a href="https://wa.me/<?= htmlspecialchars($tenant['owner_wa']) ?>" target="_blank" class="sa-btn sa-btn-wa">💬 WA Owner</a>
    <?php if ($tenant['status'] !== 'suspended'): ?>
    <button class="sa-btn sa-btn-danger" onclick="doAction('suspend')">🔒 Suspend</button>
    <?php else: ?>
    <button class="sa-btn sa-btn-green" onclick="doAction('activate')">✅ Aktifkan</button>
    <?php endif; ?>
    <button class="sa-btn sa-btn-primary" onclick="openSection('topup')">🪙 Topup Coin</button>
    <button class="sa-btn sa-btn-outline" onclick="openImpersonateModal()" title="Observasi tenant sebagai read-only">🔍 Observasi</button>
  </div>
</div>

<!-- Tabs -->
<div class="sa-tabs">
  <button class="sa-tab active" onclick="showTab('profil')">👤 Profil</button>
  <button class="sa-tab" onclick="showTab('outlets')">🏪 Outlets <span style="font-size:10px;background:var(--slate-elev);padding:1px 6px;border-radius:10px;margin-left:3px;"><?= count($outletList) ?></span></button>
  <button class="sa-tab" onclick="showTab('health')">💊 Health</button>
  <button class="sa-tab" onclick="showTab('stats')">📊 Stats</button>
  <button class="sa-tab" onclick="showTab('coins')">🪙 Coin History</button>
  <button class="sa-tab" onclick="showTab('billing')">💳 Billing</button>
  <button class="sa-tab" onclick="showTab('tickets')">🎧 Tiket</button>
  <button class="sa-tab" onclick="showTab('notes')">📝 Notes</button>
  <button class="sa-tab" onclick="showTab('comms')">💬 Komunikasi</button>
  <button class="sa-tab" onclick="showTab('aktivitas')">📅 Aktivitas</button>
  <button class="sa-tab" onclick="showTab('tenant_errors');loadTenantErrors(1)">🚨 Errors</button>
  <button class="sa-tab" onclick="showTab('migrations');loadMigrations()">📦 Migrations</button>
  <button class="sa-tab" onclick="showTab('aksi')">⚙️ Aksi Manual</button>
</div>

<!-- Tab: Profil -->
<div class="sa-tab-panel active" id="tab-profil">
  <div class="sa-grid-2">
    <div class="sa-card">
      <div class="sa-card-header"><h3>Informasi Tenant</h3></div>
      <div class="sa-card-body">
        <table style="width:100%;font-size:13.5px;border-collapse:collapse;">
          <?php $rows = [
            ['Nama Perusahaan', $tenant['nama_perusahaan'] ?: '-'],
            ['Slug / ID', $tenant['slug'] . ' (#' . $tenant['id'] . ')'],
            ['Owner', $tenant['owner_name']],
            ['WA Owner', $tenant['owner_wa']],
            ['Status', ucfirst($tenant['status'])],
            ['Coin Balance', number_format($effectiveCoin) . ' coin' . ($trialCoinTotal > 0 ? ' (incl. ' . number_format($trialCoinTotal) . ' trial)' : '')],
            ['Trial Ends', $tenant['trial_ends_at'] ?: '-'],
            ['Provisioned', $tenant['provisioned_at'] ?: '-'],
            ['Bergabung', $tenant['created_at']],
            ['Last Login', $lastLogin ?: 'Belum pernah'],
          ];
          foreach ($rows as [$k, $v]): ?>
          <tr>
            <td style="padding:8px 0;color:var(--ash);width:140px;vertical-align:top;"><?= $k ?></td>
            <td style="padding:8px 0;color:var(--white);font-weight:500;"><?= htmlspecialchars((string)$v) ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>

    <div class="sa-card">
      <div class="sa-card-header"><h3>Users</h3></div>
      <div class="sa-card-body">
        <table class="sa-table">
          <thead><tr><th>Nama</th><th>Username</th><th>Role</th><th>Last Login</th></tr></thead>
          <tbody>
          <?php foreach ($users as $u): ?>
          <tr>
            <td><?= htmlspecialchars($u['name']) ?></td>
            <td style="font-family:var(--mono);font-size:11px;"><?= htmlspecialchars($u['username']) ?></td>
            <td><span class="sa-badge sa-badge-indigo" style="font-size:10px;"><?= htmlspecialchars($u['role']) ?></span></td>
            <td style="font-size:12px;color:var(--ash);"><?= $u['last_login'] ? date('d M Y', strtotime($u['last_login'])) : 'Belum pernah' ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Tab: Outlets -->
<div class="sa-tab-panel" id="tab-outlets">
  <?php
  $outletActive    = count(array_filter($outletList, fn($o) => $o['status'] === 'active'));
  $outletInactive  = count(array_filter($outletList, fn($o) => $o['status'] === 'inactive'));
  $outletSuspended = count(array_filter($outletList, fn($o) => $o['status'] === 'suspended'));
  $outletTrial     = count(array_filter($outletList, fn($o) => $o['status'] === 'trial'));
  ?>
  <!-- Summary stats -->
  <div class="sa-grid-4" style="margin-bottom:20px;">
    <div class="sa-stat-card indigo">
      <div class="label">Total Outlet</div>
      <div class="value"><?= count($outletList) ?></div>
      <span class="icon-bg">🏪</span>
    </div>
    <div class="sa-stat-card green">
      <div class="label">Aktif</div>
      <div class="value"><?= $outletActive ?></div>
      <span class="icon-bg">✅</span>
    </div>
    <?php if ($outletTrial > 0): ?>
    <div class="sa-stat-card blue">
      <div class="label">Trial</div>
      <div class="value"><?= $outletTrial ?></div>
      <span class="icon-bg">🔬</span>
    </div>
    <?php else: ?>
    <div class="sa-stat-card" style="opacity:.4;">
      <div class="label">Trial</div>
      <div class="value">0</div>
      <span class="icon-bg">🔬</span>
    </div>
    <?php endif; ?>
    <?php if ($outletSuspended + $outletInactive > 0): ?>
    <div class="sa-stat-card red">
      <div class="label">Non-aktif</div>
      <div class="value"><?= $outletSuspended + $outletInactive ?></div>
      <span class="icon-bg">⛔</span>
    </div>
    <?php else: ?>
    <div class="sa-stat-card" style="opacity:.4;">
      <div class="label">Non-aktif</div>
      <div class="value">0</div>
      <span class="icon-bg">⛔</span>
    </div>
    <?php endif; ?>
  </div>

  <!-- Outlet table -->
  <div class="sa-card">
    <div class="sa-card-header">
      <h3>Daftar Outlet</h3>
      <span style="font-size:12px;color:var(--ash-dim);">Coin mode: <strong style="color:<?= $coinMode === 'per_outlet' ? '#7DD3FC' : 'var(--ink-soft)' ?>"><?= $coinMode ?></strong></span>
    </div>
    <?php if (empty($outletList)): ?>
    <div class="sa-card-body" style="text-align:center;padding:40px;color:var(--ash-dim);">
      Belum ada outlet yang terdaftar.
    </div>
    <?php else: ?>
    <div class="sa-table-wrap">
      <table class="sa-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Nama Outlet</th>
            <th>Slug</th>
            <th>Kota</th>
            <th>Telepon</th>
            <th>Status</th>
            <th>Main</th>
            <th>Setup</th>
            <th>Coin</th>
            <th>Dibuat</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($outletList as $o):
            $statusColor = match($o['status']) {
                'active'    => 'active',
                'trial'     => 'trial',
                'suspended' => 'suspended',
                default     => 'suspended',
            };
            $coinBal = $coinMode === 'per_outlet'
                ? (int)$o['coin_balance'] + (int)$o['trial_coin_balance']
                : null; // shared mode — coin di tenants, bukan per outlet
          ?>
          <tr>
            <td style="font-family:var(--mono);font-size:11px;color:var(--ash-dim);"><?= $o['id'] ?></td>
            <td>
              <span style="font-weight:600;color:var(--white);"><?= htmlspecialchars($o['nama_outlet']) ?></span>
              <?php if ($o['is_main']): ?>
              <span style="font-size:9px;background:rgba(139,92,246,.25);color:#C4B5FD;padding:1px 5px;border-radius:6px;margin-left:5px;vertical-align:middle;">MAIN</span>
              <?php endif; ?>
            </td>
            <td style="font-family:var(--mono);font-size:11px;color:var(--ash);"><?= htmlspecialchars($o['slug']) ?></td>
            <td style="font-size:13px;color:var(--ink-soft);"><?= htmlspecialchars($o['kota'] ?: '—') ?></td>
            <td style="font-size:12px;color:var(--ash);"><?= htmlspecialchars($o['telepon'] ?: '—') ?></td>
            <td><span class="sa-badge sa-badge-<?= $statusColor ?>"><?= ucfirst($o['status']) ?></span></td>
            <td style="text-align:center;"><?= $o['is_main'] ? '⭐' : '' ?></td>
            <td style="text-align:center;"><?= $o['setup_done'] ? '<span style="color:#4ADE80;">✓</span>' : '<span style="color:var(--ash-dim);">—</span>' ?></td>
            <td style="font-family:var(--mono);font-size:12px;">
              <?php if ($coinMode === 'per_outlet'): ?>
                <?= number_format($coinBal) ?>
              <?php else: ?>
                <span style="color:var(--ash-dim);font-size:11px;">shared</span>
              <?php endif; ?>
            </td>
            <td style="font-size:11px;color:var(--ash-dim);font-family:var(--mono);"><?= substr($o['created_at'], 0, 10) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (!empty($outletList)): ?>
    <div style="padding:10px 16px 14px;font-size:11.5px;color:var(--ash-dim);">
      * Outlet dengan status <em>closed</em> tidak ditampilkan.
      <?php if ($coinMode === 'shared'): ?>
      Coin ditampilkan sebagai "shared" karena tenant menggunakan mode coin bersama.
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div><!-- /#tab-outlets -->

<!-- Tab: Health -->
<div class="sa-tab-panel" id="tab-health">
  <div class="sa-grid-4">
    <?php
    $healthCards = [
      ['Login 7 Hari', $loginOk, $loginOk ? 'Login: ' . ($lastLogin ? date('d M', strtotime($lastLogin)) : '-') : 'Belum login 7 hari', '🔐'],
      ['Transaksi Aktif', $txWeek > 0, "$txWeek transaksi minggu ini", '📋'],
      ['Coin Cukup', $coinOk, number_format($effectiveCoin) . ' coin', '🪙'],
      ['Onboarding Done', $onboardOk, $layCount . ' layanan, ' . $orderStats['total_orders'] . ' order', '🚀'],
    ];
    foreach ($healthCards as [$label, $ok, $sub, $icon]):
    ?>
    <div class="sa-stat-card <?= $ok ? 'green' : 'red' ?>">
      <div class="label"><?= $label ?></div>
      <div class="value" style="font-size:32px;"><?= $ok ? '✅' : '❌' ?></div>
      <div class="sub"><?= htmlspecialchars($sub) ?></div>
      <span class="icon-bg"><?= $icon ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Tab: Stats -->
<div class="sa-tab-panel" id="tab-stats">
  <div class="sa-grid-4" style="margin-bottom:20px;">
    <div class="sa-stat-card indigo">
      <div class="label">Total Order</div>
      <div class="value"><?= number_format($orderStats['total_orders']) ?></div>
      <span class="icon-bg">📋</span>
    </div>
    <div class="sa-stat-card blue">
      <div class="label">Avg Order/hari (30d)</div>
      <div class="value" style="font-size:20px;"><?= round($orderStats['orders_30d'] / 30, 1) ?></div>
      <span class="icon-bg">📈</span>
    </div>
    <div class="sa-stat-card red">
      <div class="label">Coin Digunakan</div>
      <div class="value" style="font-size:18px;"><?= number_format($coinStat['total_used']) ?></div>
      <span class="icon-bg">🪙</span>
    </div>
    <div class="sa-stat-card green">
      <div class="label">Total Topup</div>
      <div class="value" style="font-size:18px;"><?= number_format($coinStat['total_topup']) ?></div>
      <span class="icon-bg">💳</span>
    </div>
  </div>
  <?php if ($featureStat): ?>
  <div class="sa-card">
    <div class="sa-card-header"><h3>Coin per Fitur</h3></div>
    <div class="sa-card-body">
      <table class="sa-table">
        <thead><tr><th>Fitur</th><th>Total Coin Digunakan</th></tr></thead>
        <tbody>
        <?php foreach ($featureStat as $f): ?>
        <tr>
          <td style="font-family:var(--mono);font-size:12px;"><?= htmlspecialchars($f['feature_used'] ?: '-') ?></td>
          <td><?= number_format($f['total']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Tab: Coin History -->
<div class="sa-tab-panel" id="tab-coins">
  <div class="sa-card">
    <div class="sa-card-header"><h3>Coin Ledger (50 terakhir)</h3></div>
    <div class="sa-table-wrap">
      <table class="sa-table">
        <thead><tr><th>Tanggal</th><th>Tipe</th><th>Jumlah</th><th>Fitur</th><th>Keterangan</th><th>Balance Setelah</th></tr></thead>
        <tbody id="coinHistoryBody"><tr><td colspan="6" style="text-align:center;padding:20px;color:var(--ash-dim);">Memuat...</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

<!-- Tab: Billing -->
<div class="sa-tab-panel" id="tab-billing">

  <!-- Summary card -->
  <div class="sa-card" style="margin-bottom:20px;">
    <div class="sa-card-header">
      <h3>💳 Billing & Coin</h3>
      <div style="display:flex;gap:8px;">
        <a href="payments.php?tenant_id=<?= $tenantId ?>" class="sa-btn sa-btn-primary sa-btn-sm">
          ＋ Konfirmasi Pembayaran
        </a>
        <button class="sa-btn sa-btn-outline sa-btn-sm" onclick="openAdjustModal()">
          ⚖️ Adjustment Coin
        </button>
      </div>
    </div>
    <div class="sa-card-body">
      <div class="sa-grid-4" style="margin-bottom:0;">
        <div>
          <div style="font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--ash-dim);margin-bottom:6px;">Saldo Coin</div>
          <div style="font-size:22px;font-weight:800;font-family:var(--mono);color:#F59E0B;">
            <?= number_format($effectiveCoin) ?>
          </div>
          <div style="font-size:11px;color:var(--ash-dim);">
            coin tersedia
            <?php if ($trialCoinTotal > 0): ?>
              <span style="color:#F59E0B;background:#FEF3C7;border-radius:3px;padding:1px 5px;margin-left:4px;">
                🎁 <?= number_format($trialCoinTotal) ?> trial
              </span>
            <?php endif; ?>
          </div>
        </div>
        <div>
          <div style="font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--ash-dim);margin-bottom:6px;">Total Topup</div>
          <div style="font-size:18px;font-weight:700;font-family:var(--mono);color:var(--sage);">
            Rp <?= number_format($billingStat['grand_total'] ?? 0) ?>
          </div>
          <div style="font-size:11px;color:var(--ash-dim);"><?= $billingStat['total_txn'] ?? 0 ?> transaksi (lifetime)</div>
        </div>
        <div>
          <div style="font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--ash-dim);margin-bottom:6px;">Total Coin Masuk</div>
          <div style="font-size:18px;font-weight:700;font-family:var(--mono);color:var(--indigo);">
            <?= number_format($billingStat['total_coin_in'] ?? 0) ?>
          </div>
          <div style="font-size:11px;color:var(--ash-dim);">coin dikreditkan</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Recent payments -->
  <div class="sa-card">
    <div class="sa-card-header">
      <h3>Riwayat Pembayaran (10 terakhir)</h3>
      <a href="payments.php?tenant_id=<?= $tenantId ?>" class="sa-btn sa-btn-sm sa-btn-outline">Lihat Semua →</a>
    </div>
    <div class="sa-table-wrap">
      <table class="sa-table">
        <thead>
          <tr>
            <th>Tanggal</th><th>Tipe</th><th>Paket / Bundle</th>
            <th>Nominal</th><th>Coin</th><th>Ref</th><th>Oleh</th><th>WA</th>
          </tr>
        </thead>
        <tbody id="billingBody">
          <tr><td colspan="8" style="text-align:center;padding:20px;color:var(--ash-dim);">Memuat...</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Tab: Tiket Support -->
<div class="sa-tab-panel" id="tab-tickets">

  <!-- Stats row -->
  <div class="sa-grid-4" style="margin-bottom:20px;" id="ticketStatsRow">
    <div class="sa-stat-card indigo">
      <div class="label">Total Tiket</div>
      <div class="value" id="tkTotal">—</div>
      <span class="icon-bg">🎧</span>
    </div>
    <div class="sa-stat-card red">
      <div class="label">Tiket Open</div>
      <div class="value" id="tkOpen">—</div>
      <span class="icon-bg">🔴</span>
    </div>
    <div class="sa-stat-card green">
      <div class="label">Resolved Bulan Ini</div>
      <div class="value" id="tkResolvedMonth">—</div>
      <span class="icon-bg">✅</span>
    </div>
    <div class="sa-stat-card blue">
      <div class="label">Avg First Response</div>
      <div class="value" id="tkAvgResp" style="font-size:18px;">—</div>
      <span class="icon-bg">⏱️</span>
    </div>
  </div>

  <!-- Table -->
  <div class="sa-card">
    <div class="sa-card-header">
      <h3>Tiket Support (30 terakhir)</h3>
      <a href="/superadmin/support.php?tenant_id=<?= $tenantId ?>" target="_blank" class="sa-btn sa-btn-primary sa-btn-sm">
        ＋ Buat Tiket Manual
      </a>
    </div>
    <div class="sa-table-wrap">
      <table class="sa-table">
        <thead>
          <tr>
            <th>#</th><th>Subjek</th><th>Kategori</th><th>Prioritas</th>
            <th>Status</th><th>Assigned</th><th>Balasan</th><th>Terakhir Update</th>
          </tr>
        </thead>
        <tbody id="ticketsBody">
          <tr><td colspan="8" style="text-align:center;padding:20px;color:var(--ash-dim);">Memuat...</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Tab: Notes -->
<div class="sa-tab-panel" id="tab-notes">
  <div class="sa-card" style="margin-bottom:16px;">
    <div class="sa-card-body">
      <div style="display:flex;gap:10px;">
        <textarea id="newNoteText" placeholder="Tulis catatan..." style="flex:1;padding:10px;background:var(--slate-elev);border:1px solid var(--crease);border-radius:8px;color:var(--glow);font-family:var(--font);font-size:13.5px;resize:vertical;min-height:80px;"></textarea>
        <button class="sa-btn sa-btn-primary" onclick="addNote()" style="align-self:flex-end;">Simpan</button>
      </div>
    </div>
  </div>
  <div id="notesContainer"></div>
</div>

<!-- Tab: Komunikasi -->
<div class="sa-tab-panel" id="tab-comms">
  <div class="sa-card" style="margin-bottom:16px;">
    <div class="sa-card-header"><h3>Catat Komunikasi</h3></div>
    <div class="sa-card-body">
      <div class="sa-grid-2" style="gap:10px;margin-bottom:10px;">
        <div>
          <label style="font-size:11px;color:var(--ash);font-weight:600;letter-spacing:.06em;text-transform:uppercase;">Channel</label>
          <select id="commChannel" style="width:100%;margin-top:6px;padding:8px;background:var(--slate-elev);border:1px solid var(--crease);border-radius:8px;color:var(--glow);font-family:var(--font);">
            <option value="wa">WhatsApp</option>
            <option value="email">Email</option>
            <option value="call">Telepon</option>
            <option value="system">System</option>
          </select>
        </div>
        <div>
          <label style="font-size:11px;color:var(--ash);font-weight:600;letter-spacing:.06em;text-transform:uppercase;">Tipe</label>
          <select id="commType" style="width:100%;margin-top:6px;padding:8px;background:var(--slate-elev);border:1px solid var(--crease);border-radius:8px;color:var(--glow);font-family:var(--font);">
            <option value="support">Support</option>
            <option value="onboarding">Onboarding</option>
            <option value="billing">Billing</option>
            <option value="churn_risk">Churn Risk</option>
            <option value="info">Info</option>
          </select>
        </div>
      </div>
      <input type="text" id="commSubject" placeholder="Subjek..." style="width:100%;padding:9px 12px;background:var(--slate-elev);border:1px solid var(--crease);border-radius:8px;color:var(--glow);font-family:var(--font);font-size:13.5px;margin-bottom:10px;"/>
      <textarea id="commMessage" placeholder="Pesan / catatan komunikasi..." style="width:100%;padding:10px;background:var(--slate-elev);border:1px solid var(--crease);border-radius:8px;color:var(--glow);font-family:var(--font);font-size:13.5px;resize:vertical;min-height:80px;"></textarea>
      <div style="margin-top:10px;text-align:right;">
        <button class="sa-btn sa-btn-primary" onclick="addComm()">Simpan Komunikasi</button>
      </div>
    </div>
  </div>
  <div id="commsTimeline"></div>
</div>

<!-- Tab: Migrations -->
<div class="sa-tab-panel" id="tab-migrations">
  <div class="sa-card">
    <div class="sa-card-header">
      <h3>📦 Riwayat Migration</h3>
      <div style="display:flex;gap:8px;">
        <button class="sa-btn sa-btn-outline sa-btn-sm" onclick="loadMigrations()">⟳ Refresh</button>
        <a href="/superadmin/migrations.php" class="sa-btn sa-btn-outline sa-btn-sm">Kelola semua →</a>
      </div>
    </div>
    <div class="sa-table-wrap">
      <table class="sa-table">
        <thead>
          <tr><th>ID</th><th>Entitas</th><th>File</th><th>Progress</th><th>Tipe</th><th>Status</th><th>Waktu</th><th>Aksi</th></tr>
        </thead>
        <tbody id="migTbody">
          <tr><td colspan="8" style="text-align:center;color:var(--ash-dim);padding:24px;">Klik tab untuk memuat...</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div><!-- /#tab-migrations -->

<!-- Tab: Aksi Manual -->
<div class="sa-tab-panel" id="tab-aksi">
  <div class="sa-grid-2">
    <!-- Topup Coin -->
    <div class="sa-card" id="topupSection">
      <div class="sa-card-header">
        <h3>🪙 Topup Coin</h3>
        <span style="font-size:11px;background:<?= $coinMode === 'per_outlet' ? '#7C3AED' : '#0369A1' ?>;color:var(--glow);padding:3px 10px;border-radius:100px;font-weight:700;letter-spacing:.04em;">
          <?= $coinMode === 'per_outlet' ? 'PER-OUTLET' : 'SHARED' ?>
        </span>
      </div>
      <div class="sa-card-body">
        <p style="font-size:13px;color:var(--ash);margin-bottom:14px;">
          Saldo shared: <strong style="color:#F59E0B;"><?= number_format($tenant['coin_balance']) ?> coin</strong>
          <?php if ($trialCoinTotal > 0): ?>
            <span style="font-size:11px;background:#FEF3C7;color:#F59E0B;border-radius:4px;padding:1px 6px;margin-left:4px;">
              🎁 <?= number_format($trialCoinTotal) ?> trial
            </span>
          <?php endif; ?>
        </p>
        <?php if ($coinMode === 'per_outlet'): ?>
        <div class="form-group" style="margin-bottom:12px;">
          <label style="font-size:11px;color:var(--ash);font-weight:600;letter-spacing:.06em;text-transform:uppercase;">Outlet Tujuan</label>
          <select id="topupOutletId" style="width:100%;margin-top:6px;padding:10px;background:var(--slate-elev);border:1px solid var(--crease);border-radius:8px;color:var(--glow);font-family:var(--font);font-size:13px;">
            <?php foreach ($outletList as $o): ?>
              <option value="<?= (int)$o['id'] ?>">
                <?= htmlspecialchars($o['nama_outlet']) ?>
                (<?= ucfirst($o['status']) ?>, <?= number_format((int)$o['coin_balance']) ?> coin)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php else: ?>
        <input type="hidden" id="topupOutletId" value="0">
        <?php endif; ?>
        <div class="form-group" style="margin-bottom:12px;">
          <label style="font-size:11px;color:var(--ash);font-weight:600;letter-spacing:.06em;text-transform:uppercase;">Jumlah Coin</label>
          <input type="number" id="topupAmt" placeholder="Contoh: 50000" style="width:100%;margin-top:6px;padding:10px;background:var(--slate-elev);border:1px solid var(--crease);border-radius:8px;color:var(--glow);font-family:var(--font);font-size:14px;"/>
        </div>
        <div class="form-group" style="margin-bottom:14px;">
          <label style="font-size:11px;color:var(--ash);font-weight:600;letter-spacing:.06em;text-transform:uppercase;">Keterangan</label>
          <input type="text" id="topupNoteAksi" placeholder="Alasan topup..." style="width:100%;margin-top:6px;padding:10px;background:var(--slate-elev);border:1px solid var(--crease);border-radius:8px;color:var(--glow);font-family:var(--font);font-size:14px;"/>
        </div>
        <button class="sa-btn sa-btn-primary" onclick="doTopup()">Topup Sekarang</button>
      </div>
    </div>

    <!-- Coin Mode Toggle -->
    <div class="sa-card">
      <div class="sa-card-header"><h3>⚙️ Mode Coin</h3></div>
      <div class="sa-card-body">
        <p style="font-size:13px;color:var(--ash);margin-bottom:14px;">
          Mode aktif saat ini: <strong style="color:#F59E0B;"><?= $coinMode === 'per_outlet' ? 'Per-Outlet' : 'Shared' ?></strong>
        </p>
        <p style="font-size:12px;color:var(--ash-dim);margin-bottom:14px;line-height:1.5;">
          <strong style="color:var(--ash)">Shared</strong>: semua outlet pakai 1 pool coin bersama (tenants.coin_balance).<br>
          <strong style="color:var(--ash)">Per-Outlet</strong>: setiap outlet punya saldo sendiri (outlets.coin_balance). Topup harus per outlet.
        </p>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <button class="sa-btn <?= $coinMode === 'shared' ? 'sa-btn-primary' : 'sa-btn-outline' ?>"
                  onclick="setCoinMode('shared')" <?= $coinMode === 'shared' ? 'disabled' : '' ?>>
            🔗 Shared
          </button>
          <button class="sa-btn <?= $coinMode === 'per_outlet' ? 'sa-btn-primary' : 'sa-btn-outline' ?>"
                  onclick="setCoinMode('per_outlet')" <?= $coinMode === 'per_outlet' ? 'disabled' : '' ?>>
            📍 Per-Outlet
          </button>
        </div>
        <p style="font-size:11px;color:var(--ash-dim);margin-top:10px;">
          ⚠️ Mengubah mode tidak memindahkan saldo secara otomatis. Pastikan saldo outlet sudah benar setelah mengganti mode.
        </p>
      </div>
    </div>

    <!-- Suspend / Activate -->
    <div class="sa-card">
      <div class="sa-card-header"><h3>🔒 Status Tenant</h3></div>
      <div class="sa-card-body">
        <p style="font-size:13px;color:var(--ash);margin-bottom:14px;">Status saat ini: <span class="sa-badge sa-badge-<?= $tenant['status'] === 'active' ? 'active' : 'suspended' ?>"><?= ucfirst($tenant['status']) ?></span></p>
        <?php if ($tenant['status'] !== 'suspended'): ?>
        <button class="sa-btn sa-btn-danger" onclick="doAction('suspend')" style="margin-right:8px;">🔒 Suspend Tenant</button>
        <?php else: ?>
        <button class="sa-btn sa-btn-green" onclick="doAction('activate')" style="margin-right:8px;">✅ Aktifkan Tenant</button>
        <?php endif; ?>
      </div>
    </div>

    <!-- Extend Trial -->
    <div class="sa-card">
      <div class="sa-card-header"><h3>⏰ Perpanjang Trial</h3></div>
      <div class="sa-card-body">
        <p style="font-size:13px;color:var(--ash);margin-bottom:14px;">Trial berakhir: <strong><?= $tenant['trial_ends_at'] ? date('d M Y', strtotime($tenant['trial_ends_at'])) : '-' ?></strong></p>
        <div style="display:flex;gap:10px;align-items:center;">
          <input type="number" id="extendDays" value="7" min="1" max="30" style="width:80px;padding:9px;background:var(--slate-elev);border:1px solid var(--crease);border-radius:8px;color:var(--glow);font-family:var(--font);"/>
          <span style="color:var(--ash);font-size:13px;">hari</span>
          <button class="sa-btn sa-btn-primary" onclick="doExtendTrial()">Perpanjang</button>
        </div>
      </div>
    </div>

    <!-- Reset Password -->
    <div class="sa-card">
      <div class="sa-card-header"><h3>🔑 Reset Password User</h3></div>
      <div class="sa-card-body">
        <div style="display:flex;flex-direction:column;gap:10px;">
          <select id="resetUserId" style="padding:9px;background:var(--slate-elev);border:1px solid var(--crease);border-radius:8px;color:var(--glow);font-family:var(--font);">
            <?php foreach ($users as $u): ?>
            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name'] . ' (' . $u['username'] . ')') ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" id="newPassword" placeholder="Password baru (min 6 karakter)" style="padding:9px 12px;background:var(--slate-elev);border:1px solid var(--crease);border-radius:8px;color:var(--glow);font-family:var(--font);"/>
          <button class="sa-btn sa-btn-outline" onclick="doResetPassword()">Reset Password</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Adjustment Coin -->
<div class="sa-modal-overlay" id="adjustModal">
  <div class="sa-modal" style="max-width:420px;">
    <h3>⚖️ Adjustment Coin</h3>
    <p style="font-size:13px;color:var(--ash);margin-bottom:4px;">
      Saldo saat ini: <strong style="color:#F59E0B;" id="adjCurrentBal"><?= number_format($tenant['coin_balance']) ?> coin</strong>
      <?php if ($trialCoinTotal > 0): ?>
        <span style="font-size:11px;background:#FEF3C7;color:#F59E0B;border-radius:4px;padding:1px 6px;margin-left:4px;">+ <?= number_format($trialCoinTotal) ?> trial (tidak terpengaruh)</span>
      <?php endif; ?>
    </p>
    <p style="font-size:11px;color:var(--ash-dim);margin-bottom:14px;">Adjustment hanya mengubah saldo shared pool (bukan coin trial).</p>

    <div style="display:flex;flex-direction:column;gap:14px;">
      <div>
        <label style="font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--ash);display:block;margin-bottom:6px;">
          Jumlah Coin *
        </label>
        <input type="number" id="adjCoin" placeholder="Positif = tambah, negatif = kurangi"
               style="width:100%;padding:10px 12px;background:var(--slate-elev);border:1.5px solid var(--crease);border-radius:8px;color:var(--glow);font-family:var(--mono);font-size:14px;outline:none;"
               oninput="previewAdj()"/>
        <div id="adjPreview" style="font-size:12px;color:var(--ash-dim);margin-top:5px;"></div>
      </div>

      <div>
        <label style="font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--ash);display:block;margin-bottom:6px;">
          Alasan *
        </label>
        <select id="adjReason" style="width:100%;padding:9px 12px;background:var(--slate-elev);border:1.5px solid var(--crease);border-radius:8px;color:var(--glow);font-family:var(--font);font-size:13px;outline:none;">
          <option value="kompensasi_downtime">Kompensasi downtime</option>
          <option value="bonus_referral">Bonus referral</option>
          <option value="koreksi_error">Koreksi error</option>
          <option value="promo">Promo</option>
          <option value="lainnya">Lainnya</option>
        </select>
      </div>

      <div>
        <label style="font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--ash);display:block;margin-bottom:6px;">
          Catatan Internal
        </label>
        <input type="text" id="adjNote" placeholder="Keterangan tambahan (opsional)"
               style="width:100%;padding:10px 12px;background:var(--slate-elev);border:1.5px solid var(--crease);border-radius:8px;color:var(--glow);font-family:var(--font);font-size:13px;outline:none;"/>
      </div>
    </div>

    <div style="font-size:11px;color:var(--ash-dim);margin-top:14px;">
      ⚠️ Adjustment tidak mengirim notifikasi WA ke tenant.
    </div>

    <div class="sa-modal-footer" style="margin-top:20px;">
      <button class="sa-btn sa-btn-outline" onclick="closeAdjustModal()">Batal</button>
      <button class="sa-btn sa-btn-primary" onclick="submitAdjustment()">✅ Simpan Adjustment</button>
    </div>
  </div>
</div>

<!-- Tab: Aktivitas -->
<div class="sa-tab-panel" id="tab-aktivitas">
  <div class="sa-card">
    <div class="sa-card-header">
      <h3>📅 Aktivitas Terakhir (30 Hari)</h3>
      <span style="font-size:12px;color:var(--ash-dim);">Dari hl_audit_log</span>
    </div>
    <div class="sa-card-body">
      <?php
      try {
          $actQ = Database::get()->prepare("
              SELECT al.action, al.outlet_id, al.created_at,
                     o.nama_outlet AS outlet_nama
              FROM hl_audit_log al
              LEFT JOIN outlets o ON o.id = al.outlet_id
              WHERE al.tenant_id = ?
                AND al.created_at >= NOW() - INTERVAL 30 DAY
              ORDER BY al.created_at DESC
              LIMIT 50
          ");
          $actQ->execute([$tenantId]);
          $acts = $actQ->fetchAll(PDO::FETCH_ASSOC);
          if ($acts): ?>
          <div class="sa-table-wrap">
            <table class="sa-table">
              <thead><tr><th>Waktu</th><th>Aksi</th><th>Outlet</th></tr></thead>
              <tbody>
              <?php foreach ($acts as $a): ?>
              <tr>
                <td style="font-size:12px;font-family:var(--mono);color:var(--ash);"><?= htmlspecialchars(substr($a['created_at'],0,16)) ?></td>
                <td style="font-size:13px;"><?= htmlspecialchars($a['action']) ?></td>
                <td style="font-size:12px;color:var(--ash);"><?= htmlspecialchars($a['outlet_nama'] ?? '#'.$a['outlet_id']) ?></td>
              </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php else: ?>
          <div style="color:var(--ash-dim);font-size:13px;padding:16px 0;">Belum ada aktivitas yang tercatat.</div>
          <?php endif;
      } catch (Throwable $e) {
          echo '<div style="color:#F43F5E;font-size:13px;">Gagal memuat aktivitas: ' . htmlspecialchars($e->getMessage()) . '</div>';
      } ?>
    </div>
  </div>
</div><!-- /#tab-aktivitas -->

<!-- Tab: Errors -->
<div class="sa-tab-panel" id="tab-tenant_errors">
  <div class="sa-card">
    <div class="sa-filter-bar">
      <select id="tenantErrType" onchange="loadTenantErrors(1)">
        <option value="">Semua Tipe</option>
        <option value="php_error">php_error</option>
        <option value="wa_error">wa_error</option>
        <option value="ai_error">ai_error</option>
        <option value="db_error">db_error</option>
      </select>
      <select id="tenantErrStatus" onchange="loadTenantErrors(1)">
        <option value="new">New</option>
        <option value="acknowledged">Acknowledged</option>
        <option value="resolved">Resolved</option>
        <option value="">Semua</option>
      </select>
      <button class="sa-btn sa-btn-outline sa-btn-sm" onclick="loadTenantErrors(1)">⟳ Refresh</button>
    </div>
    <div class="sa-table-wrap">
      <table class="sa-table" id="tenantErrTable">
        <thead>
          <tr>
            <th>Tipe</th><th>Pesan</th><th>Occurrences</th><th>Terakhir</th><th>Status</th>
          </tr>
        </thead>
        <tbody id="tenantErrTbody">
          <tr><td colspan="5" style="text-align:center;color:var(--ash-dim);padding:32px;">Klik tab untuk memuat...</td></tr>
        </tbody>
      </table>
    </div>
    <div id="tenantErrPagination" class="sa-pagination"></div>
  </div>
</div><!-- /#tab-tenant_errors -->

<!-- Impersonate modal -->
<div class="sa-modal-overlay" id="impersonateModal">
  <div class="sa-modal" style="max-width:460px;">
    <h3>🔍 Observasi Tenant</h3>
    <p style="font-size:13px;color:var(--ash);line-height:1.6;margin-bottom:20px;">
      Anda akan masuk ke dashboard tenant <strong><?= htmlspecialchars($tenant['nama_perusahaan'] ?: $tenant['slug']) ?></strong>
      dalam <strong>mode read-only</strong>. Semua aksi tulis akan diblokir.<br><br>
      Sesi ini akan dicatat di audit log.
    </p>
    <div style="background:rgba(245,158,11,.10);border:1px solid rgba(245,158,11,.30);border-radius:10px;padding:12px 16px;margin-bottom:20px;font-size:12.5px;color:#F59E0B;">
      ⚠️ Jangan gunakan fitur ini untuk mengakses data sensitif tenant tanpa keperluan yang jelas.
    </div>
    <form method="POST" action="/superadmin/impersonate.php" onsubmit="return validateImpersonateForm(this)">
      <input type="hidden" name="_csrf" value="<?= saGetCsrf() ?>">
      <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
      <div style="margin-bottom:16px;">
        <label style="display:block;font-size:12px;font-weight:600;color:var(--ink-soft);margin-bottom:6px;">
          Alasan Observasi <span style="color:var(--coral)">*</span>
        </label>
        <textarea name="reason" id="impersonateReason" required
          placeholder="Contoh: Investigasi laporan bug kasir tidak bisa input order..."
          style="width:100%;padding:10px 12px;background:var(--slate-elev);border:1px solid var(--crease);
                 border-radius:8px;color:var(--glow);font-size:13px;resize:vertical;min-height:72px;box-sizing:border-box;
                 font-family:inherit;outline:none;"
          maxlength="200" rows="3"></textarea>
        <div style="text-align:right;font-size:11px;color:var(--ash-dim);margin-top:3px;">
          Maks. 200 karakter
        </div>
      </div>
      <div class="sa-modal-footer">
        <button type="button" class="sa-btn sa-btn-outline" onclick="document.getElementById('impersonateModal').classList.remove('open')">Batal</button>
        <button type="submit" class="sa-btn sa-btn-primary">🔍 Mulai Observasi</button>
      </div>
    </form>
  </div>
</div>

<?php saRenderNavClose(); ?>

<script>
const TENANT_ID = <?= $tenantId ?>;

function showTab(name) {
  document.querySelectorAll('.sa-tab').forEach((t, i) => {
    const tabs = ['profil','outlets','health','stats','coins','billing','tickets','notes','comms','aksi'];
    t.classList.toggle('active', tabs[i] === name);
  });
  document.querySelectorAll('.sa-tab-panel').forEach(p => p.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');

  if (name === 'coins')   loadCoinHistory();
  if (name === 'billing') loadBilling();
  if (name === 'tickets') loadTickets();
  if (name === 'notes')   loadNotes();
  if (name === 'comms')   loadComms();
}

function openSection(s) { showTab(s === 'topup' ? 'aksi' : s); }

function esc(s) { const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
function rupiah(n) { return 'Rp '+parseInt(n).toLocaleString('id-ID'); }
function fmtDate(s) { return s ? new Date(s).toLocaleString('id-ID',{dateStyle:'short',timeStyle:'short'}) : '-'; }

// Coin History
function loadCoinHistory() {
  fetch(`client_detail.php?action=get_coin_history&id=${TENANT_ID}`, {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(rows => {
      const tbody = document.getElementById('coinHistoryBody');
      if (!rows.length) { tbody.innerHTML='<tr><td colspan="6" style="text-align:center;padding:20px;color:var(--ash-dim);">Belum ada riwayat.</td></tr>'; return; }
      tbody.innerHTML = rows.map(r => `<tr>
        <td style="font-size:12px;">${fmtDate(r.created_at)}</td>
        <td><span class="sa-badge ${r.type==='topup'?'sa-badge-active':'sa-badge-red'}">${r.type}</span></td>
        <td style="font-family:var(--mono);">${parseInt(r.amount).toLocaleString('id-ID')}</td>
        <td style="font-size:12px;color:var(--ash);">${esc(r.feature_used||'-')}</td>
        <td style="font-size:12px;color:var(--ash);">${esc(r.description||'-')}</td>
        <td style="font-family:var(--mono);color:#F59E0B;">${parseInt(r.balance_after).toLocaleString('id-ID')}</td>
      </tr>`).join('');
    });
}

// Billing
const typeLabel = {setup_fee:'Setup Fee', coin_topup:'Topup Coin', adjustment:'Adjustment', custom:'Custom'};
const typeCls   = {setup_fee:'sa-badge-indigo', coin_topup:'sa-badge-active', adjustment:'sa-badge-yellow', custom:'sa-badge-indigo'};

function loadBilling() {
  fetch(`client_detail.php?action=get_billing&id=${TENANT_ID}`, {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r => r.json()).then(d => {
      const tbody = document.getElementById('billingBody');
      if (!d.rows || !d.rows.length) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:20px;color:var(--ash-dim);">Belum ada pembayaran.</td></tr>';
        return;
      }
      tbody.innerHTML = d.rows.map(p => {
        const pkgBdl = p.package_nama || p.bundle_nama || '—';
        const waHtml = p.notif_wa_sent
          ? '<span style="color:var(--sage);" title="WA terkirim">📲</span>'
          : '<span style="color:var(--ash-dim);">—</span>';
        return `<tr>
          <td style="font-size:12px;">${p.tanggal_bayar ? new Date(p.tanggal_bayar).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'}) : '—'}</td>
          <td><span class="sa-badge ${typeCls[p.type]||'sa-badge-indigo'}" style="font-size:10px;">${typeLabel[p.type]||p.type}</span></td>
          <td style="font-size:12px;color:var(--ash);">${esc(pkgBdl)}</td>
          <td style="font-family:var(--mono);font-size:13px;font-weight:600;">${p.nominal_dibayar > 0 ? rupiah(p.nominal_dibayar) : '—'}</td>
          <td style="font-family:var(--mono);color:#F59E0B;">${p.coin_dikreditkan > 0 ? '+' + parseInt(p.coin_dikreditkan).toLocaleString('id-ID') : (p.coin_dikreditkan < 0 ? parseInt(p.coin_dikreditkan).toLocaleString('id-ID') : '—')}</td>
          <td style="font-family:var(--mono);font-size:11px;color:var(--ash);">${esc(p.ref_transfer||'—')}</td>
          <td style="font-size:11px;color:var(--ash-dim);">${esc(p.superadmin_nama||'—')}</td>
          <td>${waHtml}</td>
        </tr>`;
      }).join('');
    });
}

// ── Adjustment modal ──────────────────────────────────
let _currentBal = <?= (int)$tenant['coin_balance'] ?>;

function openAdjustModal() {
  document.getElementById('adjCoin').value   = '';
  document.getElementById('adjNote').value   = '';
  document.getElementById('adjReason').value = 'kompensasi_downtime';
  document.getElementById('adjPreview').textContent = '';
  document.getElementById('adjCurrentBal').textContent = _currentBal.toLocaleString('id-ID') + ' coin';
  document.getElementById('adjustModal').classList.add('open');
  setTimeout(() => document.getElementById('adjCoin').focus(), 100);
}

function closeAdjustModal() {
  document.getElementById('adjustModal').classList.remove('open');
}

function previewAdj() {
  const val = parseInt(document.getElementById('adjCoin').value) || 0;
  const newBal = Math.max(0, _currentBal + val);
  const el = document.getElementById('adjPreview');
  if (!val) { el.textContent = ''; return; }
  const sign = val > 0 ? '+' : '';
  el.innerHTML = `${sign}${val.toLocaleString('id-ID')} coin → saldo baru: <strong style="color:#F59E0B;">${newBal.toLocaleString('id-ID')} coin</strong>`;
}

async function submitAdjustment() {
  const coin   = document.getElementById('adjCoin').value;
  const reason = document.getElementById('adjReason').value;
  const note   = document.getElementById('adjNote').value;

  if (!coin || parseInt(coin) === 0) { saShowToast('Jumlah coin tidak boleh 0.', 'error'); return; }
  if (!await lmConfirm(`${parseInt(coin) > 0 ? 'Tambah' : 'Kurangi'} ${Math.abs(parseInt(coin)).toLocaleString('id-ID')} coin dari tenant ini?`)) return;

  saPost(`client_detail.php?action=adjustment`, {
    tenant_id: TENANT_ID, coin, reason, note
  }).then(r => r.json()).then(d => {
    if (d.error) { saShowToast(d.error, 'error'); return; }
    _currentBal = d.new_balance;
    saShowToast('✅ ' + d.msg, 'success');
    closeAdjustModal();
    loadBilling();
    loadCoinHistory();
  });
}

// Notes
function loadNotes() {
  fetch(`client_detail.php?action=get_notes&id=${TENANT_ID}`, {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(notes => {
      const el = document.getElementById('notesContainer');
      if (!notes.length) { el.innerHTML='<p style="color:var(--ash-dim);font-size:13px;">Belum ada catatan.</p>'; return; }
      el.innerHTML = notes.map(n => `
        <div style="background:rgba(10,15,31,.4);border:1px solid rgba(255,255,255,${n.is_pinned?'.2':'.07'});border-radius:10px;padding:14px 16px;margin-bottom:10px;position:relative;">
          ${n.is_pinned ? '<span style="color:#F59E0B;font-size:12px;">📌 Pinned</span><br>' : ''}
          <div style="font-size:14px;color:var(--glow);white-space:pre-wrap;margin-bottom:8px;">${esc(n.note)}</div>
          <div style="font-size:11px;color:var(--ash-dim);">${esc(n.sa_nama||'Admin')} · ${fmtDate(n.created_at)}</div>
          <div style="position:absolute;top:12px;right:12px;display:flex;gap:6px;">
            <button class="sa-btn sa-btn-sm sa-btn-outline" onclick="pinNote(${n.id})">${n.is_pinned?'Unpin':'Pin'}</button>
            <button class="sa-btn sa-btn-sm sa-btn-danger" onclick="deleteNote(${n.id})">Hapus</button>
          </div>
        </div>`).join('');
    });
}

function addNote() {
  const text = document.getElementById('newNoteText').value.trim();
  if (!text) return;
  saPost(`client_detail.php?action=add_note`, { tenant_id: TENANT_ID, note: text })
    .then(r=>r.json()).then(d => {
      if (d.error) { saShowToast(d.error, 'error'); return; }
      document.getElementById('newNoteText').value = '';
      saShowToast('Catatan ditambahkan.', 'success');
      loadNotes();
    });
}

function pinNote(id) {
  saPost(`client_detail.php?action=pin_note`, { note_id: id })
    .then(r=>r.json()).then(() => loadNotes());
}

async function deleteNote(id) {
  if (!await lmConfirm('Hapus catatan ini?')) return;
  saPost(`client_detail.php?action=delete_note`, { note_id: id })
    .then(r=>r.json()).then(() => { saShowToast('Catatan dihapus.'); loadNotes(); });
}

// Communications
function loadComms() {
  fetch(`client_detail.php?action=get_comms&id=${TENANT_ID}`, {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(items => {
      const el = document.getElementById('commsTimeline');
      if (!items.length) { el.innerHTML='<p style="color:var(--ash-dim);font-size:13px;">Belum ada riwayat komunikasi.</p>'; return; }
      const chIcons = { wa:'💬', email:'📧', call:'📞', system:'⚙️' };
      el.innerHTML = items.map(c => `
        <div style="display:flex;gap:12px;margin-bottom:14px;">
          <div style="width:36px;height:36px;border-radius:50%;background:rgba(53,232,213,.18);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:16px;">${chIcons[c.channel]||'💬'}</div>
          <div style="flex:1;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
              <strong style="font-size:13.5px;">${esc(c.subject||'(tanpa subjek)')}</strong>
              <span class="sa-badge sa-badge-indigo" style="font-size:10px;">${esc(c.type)}</span>
              <span class="sa-badge sa-badge-indigo" style="font-size:10px;">${esc(c.channel)}</span>
            </div>
            <div style="font-size:13px;color:var(--ink-soft);margin-top:4px;white-space:pre-wrap;">${esc(c.message||'')}</div>
            <div style="font-size:11px;color:var(--ash-dim);margin-top:4px;">${esc(c.sa_nama||'Admin')} · ${fmtDate(c.created_at)}</div>
          </div>
        </div>`).join('');
    });
}

function addComm() {
  const data = {
    tenant_id: TENANT_ID,
    channel:  document.getElementById('commChannel').value,
    type:     document.getElementById('commType').value,
    subject:  document.getElementById('commSubject').value,
    message:  document.getElementById('commMessage').value,
  };
  saPost(`client_detail.php?action=add_comm`, data)
    .then(r=>r.json()).then(d => {
      if (d.error) { saShowToast(d.error, 'error'); return; }
      document.getElementById('commSubject').value = '';
      document.getElementById('commMessage').value = '';
      saShowToast('Komunikasi dicatat.', 'success');
      loadComms();
    });
}

// Tickets
const tkStatusLabel = {open:'Open', in_progress:'In Progress', waiting_tenant:'Menunggu Tenant', resolved:'Resolved', closed:'Closed'};
const tkStatusCls   = {open:'sa-badge-red', in_progress:'sa-badge-indigo', waiting_tenant:'sa-badge-yellow', resolved:'sa-badge-active', closed:'sa-badge-indigo'};
const tkPriorCls    = {low:'sa-badge-indigo', medium:'sa-badge-yellow', high:'sa-badge-red', urgent:'sa-badge-red'};

function loadTickets() {
  fetch(`client_detail.php?action=get_tickets&id=${TENANT_ID}`, {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r => r.json()).then(d => {
      const st = d.stats || {};
      document.getElementById('tkTotal').textContent        = (st.total ?? '0').toLocaleString('id-ID');
      document.getElementById('tkOpen').textContent         = (st.open_count ?? '0').toLocaleString('id-ID');
      document.getElementById('tkResolvedMonth').textContent= (st.resolved_month ?? '0').toLocaleString('id-ID');

      const avgMin = parseInt(st.avg_resp_min ?? 0);
      if (!avgMin) {
        document.getElementById('tkAvgResp').textContent = '—';
      } else if (avgMin < 60) {
        document.getElementById('tkAvgResp').textContent = avgMin + ' mnt';
      } else {
        document.getElementById('tkAvgResp').textContent = (avgMin / 60).toFixed(1) + ' jam';
      }

      const tbody = document.getElementById('ticketsBody');
      if (!d.rows || !d.rows.length) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:20px;color:var(--ash-dim);">Belum ada tiket.</td></tr>';
        return;
      }
      tbody.innerHTML = d.rows.map(t => `<tr>
        <td style="font-family:var(--mono);font-size:11px;color:var(--ash);">#${t.id}</td>
        <td style="max-width:220px;">
          <a href="/superadmin/support.php?ticket_id=${t.id}" target="_blank" style="color:var(--indigo);text-decoration:none;font-size:13px;">${esc(t.subject||'(tanpa subjek)')}</a>
        </td>
        <td><span class="sa-badge sa-badge-indigo" style="font-size:10px;">${esc(t.category||'—')}</span></td>
        <td><span class="sa-badge ${tkPriorCls[t.priority]||'sa-badge-indigo'}" style="font-size:10px;">${esc(t.priority||'—')}</span></td>
        <td><span class="sa-badge ${tkStatusCls[t.status]||'sa-badge-indigo'}" style="font-size:10px;">${tkStatusLabel[t.status]||t.status}</span></td>
        <td style="font-size:12px;color:var(--ash);">${esc(t.assigned_nama||'—')}</td>
        <td style="font-family:var(--mono);font-size:12px;color:var(--ash);">${t.reply_count||0}</td>
        <td style="font-size:11px;color:var(--ash-dim);">${fmtDate(t.updated_at||t.created_at)}</td>
      </tr>`).join('');
    }).catch(() => {
      document.getElementById('ticketsBody').innerHTML =
        '<tr><td colspan="8" style="text-align:center;padding:20px;color:var(--ash-dim);">Gagal memuat tiket.</td></tr>';
    });
}

// Actions
async function doAction(act) {
  const labels = { suspend: 'suspend', activate: 'aktifkan' };
  if (!await lmConfirm(`Yakin ${labels[act]} tenant ini?`)) return;
  saPost(`client_detail.php?action=${act}`, { tenant_id: TENANT_ID })
    .then(r=>r.json()).then(d => {
      if (d.error) { saShowToast(d.error, 'error'); return; }
      saShowToast('Status berhasil diubah.', 'success');
      setTimeout(() => location.reload(), 1200);
    });
}

function doTopup() {
  const amt      = document.getElementById('topupAmt').value;
  const note     = document.getElementById('topupNoteAksi').value;
  const outletEl = document.getElementById('topupOutletId');
  const outlet_id = outletEl ? outletEl.value : 0;
  if (!amt || amt < 1) { saShowToast('Jumlah harus > 0', 'error'); return; }
  saPost(`client_detail.php?action=topup`, { tenant_id: TENANT_ID, amount: amt, note, outlet_id })
    .then(r=>r.json()).then(d => {
      if (d.error) { saShowToast(d.error, 'error'); return; }
      const label = d.mode === 'per_outlet' ? 'Outlet saldo baru' : 'Pool saldo baru';
      saShowToast('Topup berhasil! ' + label + ': ' + parseInt(d.new_balance).toLocaleString('id-ID'), 'success');
      setTimeout(() => location.reload(), 1500);
    });
}

async function setCoinMode(mode) {
  if (!await lmConfirm('Ubah coin mode ke "' + mode + '"?\n\n⚠️ Saldo tidak dipindahkan otomatis. Pastikan saldo outlet sudah benar.')) return;
  saPost(`client_detail.php?action=set_coin_mode`, { tenant_id: TENANT_ID, mode })
    .then(r=>r.json()).then(d => {
      if (d.error) { saShowToast(d.error, 'error'); return; }
      saShowToast('Mode coin diubah ke: ' + d.mode, 'success');
      setTimeout(() => location.reload(), 1200);
    });
}

function doExtendTrial() {
  const days = document.getElementById('extendDays').value;
  if (!days || days < 1) { saShowToast('Hari harus > 0', 'error'); return; }
  saPost(`client_detail.php?action=extend_trial`, { tenant_id: TENANT_ID, days })
    .then(r=>r.json()).then(d => {
      if (d.error) { saShowToast(d.error, 'error'); return; }
      saShowToast('Trial diperpanjang.', 'success');
      setTimeout(() => location.reload(), 1200);
    });
}

async function doResetPassword() {
  const userId = document.getElementById('resetUserId').value;
  const pw     = document.getElementById('newPassword').value;
  if (!pw || pw.length < 6) { saShowToast('Password minimal 6 karakter.', 'error'); return; }
  if (!await lmConfirm('Reset password user ini?')) return;
  saPost(`client_detail.php?action=reset_password`, { tenant_id: TENANT_ID, user_id: userId, new_password: pw })
    .then(r=>r.json()).then(d => {
      if (d.error) { saShowToast(d.error, 'error'); return; }
      saShowToast('Password berhasil direset.', 'success');
      document.getElementById('newPassword').value = '';
    });
}

// ── Migration history ─────────────────────────────
const MIG_ENTITY = { layanan:'Layanan', pelanggan:'Pelanggan', karyawan:'Karyawan', transaksi:'Transaksi', poin_pelanggan:'Poin' };
const MIG_STATUS = { completed:'✅', partial:'⚠️', failed:'❌', importing:'⏳', mapped:'🔍', uploaded:'📁' };

async function loadMigrations() {
    const tbody = document.getElementById('migTbody');
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--ash-dim);padding:24px;">Memuat...</td></tr>';
    try {
        const resp = await fetch(`client_detail.php?action=get_migrations&id=${TENANT_ID}`);
        const rows = await resp.json();
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--ash-dim);padding:24px;">Belum ada migration job.</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map(r => {
            const pct = r.total_rows > 0 ? Math.round(r.success_rows/r.total_rows*100) : 0;
            const c   = pct>=95?'#10B981':pct>=60?'#F59E0B':'#EF4444';
            return `<tr>
                <td style="font-size:11px;font-family:var(--mono);color:var(--ash);">#${r.id}</td>
                <td><span class="sa-badge sa-badge-indigo" style="font-size:10px;">${MIG_ENTITY[r.entity_type]||r.entity_type}</span></td>
                <td style="font-size:11.5px;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${r.file_name}">${r.file_name}</td>
                <td style="min-width:110px;">
                    <div style="font-size:11.5px;color:var(--ink-soft);margin-bottom:3px;">${r.success_rows}/${r.total_rows}</div>
                    <div style="height:4px;background:var(--slate-elev);border-radius:2px;overflow:hidden;"><div style="height:100%;background:${c};width:${pct}%;"></div></div>
                </td>
                <td style="font-size:11.5px;">${r.is_assisted?'<span class="sa-badge sa-badge-yellow" style="font-size:10px;">Assisted</span>':'<span style="font-size:11px;color:var(--ash-dim);">Self</span>'}</td>
                <td>${MIG_STATUS[r.status]||'?'} <span style="font-size:11.5px;">${r.status}</span></td>
                <td style="font-size:11px;color:var(--ash-dim);">${(r.created_at||'').substring(0,16)}</td>
                <td>${r.failed_rows>0?`<a href="/superadmin/migrations.php?action=error_report&job_id=${r.id}" class="sa-btn sa-btn-sm sa-btn-danger">⬇</a>`:''}</td>
            </tr>`;
        }).join('');
    } catch(e) {
        tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;color:#F43F5E;padding:24px;">Gagal: ${e.message}</td></tr>`;
    }
}

// ── Impersonate modal ──────────────────────────────
function openImpersonateModal() {
    const modal = document.getElementById('impersonateModal');
    const ta = document.getElementById('impersonateReason');
    if (ta) ta.value = '';
    modal.classList.add('open');
    setTimeout(() => { if (ta) ta.focus(); }, 100);
}

function validateImpersonateForm(form) {
    const reason = (document.getElementById('impersonateReason')?.value || '').trim();
    if (!reason) {
        lmAlert('Alasan observasi wajib diisi.');
        document.getElementById('impersonateReason')?.focus();
        return false;
    }
    if (reason.length < 10) {
        lmAlert('Alasan terlalu singkat (minimal 10 karakter).');
        document.getElementById('impersonateReason')?.focus();
        return false;
    }
    lmConfirm('Yakin mulai observasi tenant ini?\n\nSemua aksi tulis akan diblokir selama mode observasi.', {danger:true, icon:'👁️', okText:'Mulai Observasi'})
        .then(function(ok){ if (ok) HTMLFormElement.prototype.submit.call(form); });
    return false;
}

// ── Tenant error log ──────────────────────────────
let tenantErrPage = 1;
async function loadTenantErrors(page) {
    tenantErrPage = page;
    const tbody  = document.getElementById('tenantErrTbody');
    const type   = document.getElementById('tenantErrType').value;
    const status = document.getElementById('tenantErrStatus').value;
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--ash-dim);padding:24px;">Memuat...</td></tr>';

    try {
        const url = `/superadmin/health.php?action=errors&page=${page}&type=${encodeURIComponent(type)}&status=${encodeURIComponent(status)}&tenant_id=${TENANT_ID}`;
        const resp = await fetch(url);
        const d    = await resp.json();

        if (!d.rows || !d.rows.length) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--ash-dim);padding:24px;">Tidak ada error untuk tenant ini.</td></tr>';
            document.getElementById('tenantErrPagination').innerHTML = '';
            return;
        }

        const ETC = { php_error:'sa-badge-red', wa_error:'sa-badge-yellow', ai_error:'sa-badge-indigo', db_error:'sa-badge-red' };
        tbody.innerHTML = d.rows.map(r => {
            const badge = ETC[r.error_type] || 'sa-badge-yellow';
            const sta = r.status === 'resolved' ? '✅ Resolved' : r.status === 'acknowledged' ? '👁 Acked' : '🔴 New';
            return `<tr>
              <td><span class="sa-badge ${badge}">${r.error_type}</span></td>
              <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${r.error_message}">${r.error_message.substring(0,80)}${r.error_message.length>80?'…':''}</td>
              <td style="text-align:center;font-family:var(--mono);">${r.occurrence_count}</td>
              <td style="font-size:11.5px;color:var(--ash);">${(r.last_seen||'').substring(0,16)}</td>
              <td>${sta}</td>
            </tr>`;
        }).join('');

        // Pagination
        const pg = document.getElementById('tenantErrPagination');
        if (d.pages <= 1) { pg.innerHTML=''; return; }
        let html = `<span style="font-size:12px;color:var(--ash-dim);">Hal ${d.page} / ${d.pages}</span>`;
        if (d.page > 1)     html += `<button class="sa-btn sa-btn-sm sa-btn-outline" onclick="loadTenantErrors(${d.page-1})">← Prev</button>`;
        if (d.page < d.pages) html += `<button class="sa-btn sa-btn-sm sa-btn-outline" onclick="loadTenantErrors(${d.page+1})">Next →</button>`;
        pg.innerHTML = html;

    } catch(e) {
        tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;color:#F43F5E;padding:24px;">Gagal: ${e.message}</td></tr>`;
    }
}
</script>
</body>
</html>

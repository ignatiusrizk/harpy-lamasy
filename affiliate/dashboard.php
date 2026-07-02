<?php
define('ROOT', dirname(__DIR__));
require_once ROOT . '/core/Database.php';
session_start();
require_once ROOT . '/core/AffiliateAuth.php';

$aff = AffiliateAuth::requireLogin();
$db  = Database::get();

$err = '';
$msg = '';

// ── POST: Request Payout ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_payout') {
    $csrf = trim($_POST['aff_csrf'] ?? '');
    if (!$csrf || !hash_equals($_SESSION['aff_csrf'] ?? '', $csrf)) {
        $err = 'Token keamanan tidak valid.';
    } else {
        // Refresh affiliate data setelah POST
        $aff = AffiliateAuth::requireLogin();
        // Cek pending
        $p = $db->prepare("SELECT 1 FROM hl_affiliate_payout WHERE affiliate_id=? AND status='requested'");
        $p->execute([$aff['id']]);
        if ($p->fetchColumn()) {
            $err = 'Sudah ada permintaan payout pending.';
        } elseif ((int)$aff['saldo_komisi'] < 50000) {
            $err = 'Saldo minimum payout Rp 50.000.';
        } else {
            $db->prepare("INSERT INTO hl_affiliate_payout (affiliate_id, jumlah, status) VALUES (?,?,'requested')")
               ->execute([$aff['id'], (int)$aff['saldo_komisi']]);
            $msg = 'Permintaan payout terkirim. Tim LAMASY akan proses transfer.';
            // Refresh lagi setelah insert
            $aff = AffiliateAuth::requireLogin();
        }
    }
}

// Generate CSRF token
if (empty($_SESSION['aff_csrf'])) {
    $_SESSION['aff_csrf'] = bin2hex(random_bytes(16));
}
$csrfToken = $_SESSION['aff_csrf'];

// ── Stats ─────────────────────────────────────────────────────────────────────
$stats = $db->prepare("SELECT
    COUNT(*)                                    AS total_referral,
    SUM(status='activated')                     AS total_activated,
    SUM(CASE WHEN status='activated' THEN komisi ELSE 0 END) AS total_earned
  FROM hl_affiliate_referral
  WHERE affiliate_id=?");
$stats->execute([$aff['id']]);
$s = $stats->fetch(PDO::FETCH_ASSOC);

$totalReferral  = (int)($s['total_referral']  ?? 0);
$totalActivated = (int)($s['total_activated'] ?? 0);
$totalEarned    = (int)($s['total_earned']    ?? 0);
$saldoKomisi    = (int)($aff['saldo_komisi']  ?? 0);

// Total dibayar = earned - saldo
$totalDibayar = max(0, $totalEarned - $saldoKomisi);

// Cek pending payout
$hasPending = false;
$pp = $db->prepare("SELECT 1 FROM hl_affiliate_payout WHERE affiliate_id=? AND status='requested'");
$pp->execute([$aff['id']]);
$hasPending = (bool)$pp->fetchColumn();

// ── Tabel Referral ────────────────────────────────────────────────────────────
$r = $db->prepare("SELECT r.status, r.komisi, r.created_at, r.activated_at, t.nama_perusahaan
                   FROM hl_affiliate_referral r
                   LEFT JOIN tenants t ON t.id = r.tenant_id
                   WHERE r.affiliate_id = ?
                   ORDER BY r.created_at DESC");
$r->execute([$aff['id']]);
$referrals = $r->fetchAll(PDO::FETCH_ASSOC);

// ── Referral link ─────────────────────────────────────────────────────────────
$host = $_SERVER['HTTP_HOST'] ?? 'lamasy.harpy.id';
$refLink = 'https://' . $host . '/register?ref=' . urlencode($aff['kode']);

function fmt_idr(int $n): string {
    return 'Rp ' . number_format($n, 0, ',', '.');
}
function fmt_date(?string $d): string {
    if (!$d) return '-';
    return date('d/m/Y', strtotime($d));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard Affiliate — LAMASY</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
         background: #f5f6fa; color: #1f2937; min-height: 100vh; }

  /* Header */
  .header { background: #1a1a2e; color: #fff; padding: 16px 24px;
            display: flex; align-items: center; justify-content: space-between; }
  .header h1 { font-size: 18px; font-weight: 700; }
  .header-right { display: flex; align-items: center; gap: 16px; font-size: 14px; }
  .header-right a { color: #a5b4fc; text-decoration: none; }
  .header-right a:hover { text-decoration: underline; }

  /* Container */
  .container { max-width: 900px; margin: 0 auto; padding: 24px 16px; }

  /* Alert */
  .alert-ok  { background: #d1fae5; color: #065f46; border-radius: 8px;
               padding: 12px 16px; font-size: 14px; margin-bottom: 20px; }
  .alert-err { background: #fee2e2; color: #b91c1c; border-radius: 8px;
               padding: 12px 16px; font-size: 14px; margin-bottom: 20px; }

  /* Referral link card */
  .ref-card { background: #fff; border-radius: 12px; box-shadow: 0 1px 8px rgba(0,0,0,.08);
              padding: 20px 24px; margin-bottom: 24px; }
  .ref-card h2 { font-size: 15px; font-weight: 700; color: #4f46e5; margin-bottom: 12px; }
  .ref-row { display: flex; gap: 10px; align-items: center; }
  .ref-input { flex: 1; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px;
               font-size: 14px; color: #374151; background: #f9fafb; outline: none; }
  .copy-btn { padding: 10px 18px; background: #4f46e5; color: #fff; border: none;
              border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer;
              white-space: nowrap; }
  .copy-btn:hover { background: #4338ca; }
  .copy-hint { font-size: 12px; color: #6b7280; margin-top: 8px; }

  /* Stats grid */
  .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px;
           margin-bottom: 24px; }
  .stat-card { background: #fff; border-radius: 12px; box-shadow: 0 1px 8px rgba(0,0,0,.08);
               padding: 20px 20px 18px; }
  .stat-label { font-size: 12px; color: #6b7280; font-weight: 600; text-transform: uppercase;
                letter-spacing: .5px; margin-bottom: 6px; }
  .stat-value { font-size: 24px; font-weight: 700; color: #1f2937; }
  .stat-value.green { color: #059669; }
  .stat-value.indigo { color: #4f46e5; }

  /* Payout section */
  .payout-card { background: #fff; border-radius: 12px; box-shadow: 0 1px 8px rgba(0,0,0,.08);
                 padding: 20px 24px; margin-bottom: 24px; }
  .payout-card h2 { font-size: 15px; font-weight: 700; margin-bottom: 14px; }
  .payout-info { font-size: 14px; color: #374151; margin-bottom: 12px; }
  .payout-info span { font-weight: 700; color: #059669; }
  .payout-btn { padding: 11px 24px; background: #059669; color: #fff; border: none;
                border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; }
  .payout-btn:hover { background: #047857; }
  .payout-disabled { font-size: 13px; color: #9ca3af; }
  .badge-pending { display: inline-block; background: #fef3c7; color: #92400e;
                   border-radius: 6px; padding: 4px 10px; font-size: 12px; font-weight: 600; }

  /* Referral table */
  .table-card { background: #fff; border-radius: 12px; box-shadow: 0 1px 8px rgba(0,0,0,.08);
                padding: 20px 24px; }
  .table-card h2 { font-size: 15px; font-weight: 700; margin-bottom: 16px; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th { text-align: left; padding: 10px 12px; font-size: 12px; font-weight: 700; color: #6b7280;
       text-transform: uppercase; letter-spacing: .4px; border-bottom: 2px solid #e5e7eb; }
  td { padding: 11px 12px; border-bottom: 1px solid #f3f4f6; color: #374151; }
  tr:last-child td { border-bottom: none; }
  .status-activated { color: #059669; font-weight: 600; }
  .status-pending   { color: #d97706; font-weight: 600; }
  .empty-state { text-align: center; padding: 32px; color: #9ca3af; font-size: 14px; }
</style>
</head>
<body>

<div class="header">
  <h1>LAMASY Affiliate</h1>
  <div class="header-right">
    <span>Halo, <?= htmlspecialchars($aff['nama']) ?></span>
    <a href="/affiliate/logout">Keluar</a>
  </div>
</div>

<div class="container">

  <?php if ($msg): ?>
  <div class="alert-ok"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>
  <?php if ($err): ?>
  <div class="alert-err"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <!-- Referral Link -->
  <div class="ref-card">
    <h2>Link Referral Anda</h2>
    <div class="ref-row">
      <input class="ref-input" id="refLink" type="text"
             value="<?= htmlspecialchars($refLink) ?>" readonly>
      <button class="copy-btn" onclick="copyRefLink()">Salin</button>
    </div>
    <p class="copy-hint">Kode referral: <strong><?= htmlspecialchars($aff['kode']) ?></strong></p>
  </div>

  <!-- Stats -->
  <div class="stats">
    <div class="stat-card">
      <div class="stat-label">Total Referral</div>
      <div class="stat-value"><?= $totalReferral ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Aktivasi</div>
      <div class="stat-value green"><?= $totalActivated ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Saldo</div>
      <div class="stat-value indigo"><?= fmt_idr($saldoKomisi) ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Total Komisi</div>
      <div class="stat-value"><?= fmt_idr($totalEarned) ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Total Dibayar</div>
      <div class="stat-value"><?= fmt_idr($totalDibayar) ?></div>
    </div>
  </div>

  <!-- Payout Request -->
  <div class="payout-card">
    <h2>Request Payout</h2>
    <p class="payout-info">Saldo saat ini: <span><?= fmt_idr($saldoKomisi) ?></span></p>
    <?php if ($hasPending): ?>
      <span class="badge-pending">Ada permintaan payout sedang diproses</span>
    <?php elseif ($saldoKomisi >= 50000): ?>
      <form method="POST" action="/affiliate/dashboard"
            onsubmit="return lmAskSubmit(event,'Kirim permintaan payout Rp ' + '<?= number_format($saldoKomisi,0,',','.') ?>' + '?')">
        <input type="hidden" name="aff_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="action" value="request_payout">
        <button type="submit" class="payout-btn">
          Request Payout <?= fmt_idr($saldoKomisi) ?>
        </button>
      </form>
    <?php else: ?>
      <p class="payout-disabled">Saldo minimum payout adalah Rp 50.000.</p>
    <?php endif; ?>
  </div>

  <!-- Referral Table -->
  <div class="table-card">
    <h2>Daftar Referral</h2>
    <?php if (empty($referrals)): ?>
      <div class="empty-state">Belum ada referral. Bagikan link Anda!</div>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Tenant / Bisnis</th>
          <th>Status</th>
          <th>Komisi</th>
          <th>Tanggal Daftar</th>
          <th>Tanggal Aktivasi</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($referrals as $row): ?>
        <tr>
          <td><?= htmlspecialchars($row['nama_perusahaan'] ?? '(belum diisi)') ?></td>
          <td>
            <?php if ($row['status'] === 'activated'): ?>
              <span class="status-activated">Aktif</span>
            <?php else: ?>
              <span class="status-pending">Pending</span>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($row['status'] === 'activated' ? fmt_idr((int)$row['komisi']) : '-') ?></td>
          <td><?= htmlspecialchars(fmt_date($row['created_at'])) ?></td>
          <td><?= htmlspecialchars(fmt_date($row['activated_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

</div><!-- /container -->

<script>
function copyRefLink() {
  var el = document.getElementById('refLink');
  el.select();
  el.setSelectionRange(0, 9999);
  try {
    navigator.clipboard.writeText(el.value).then(function() {
      alert('Link referral berhasil disalin!');
    });
  } catch(e) {
    document.execCommand('copy');
    alert('Link referral berhasil disalin!');
  }
}
</script>
<?php require dirname(__DIR__) . '/ui_dialog.php'; ?>
</body>
</html>

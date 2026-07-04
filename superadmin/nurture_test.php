<?php
// ══════════════════════════════════════════════════════
// superadmin/nurture_test.php — Trigger tes Trial Nurturing (Brief 4)
//
// SA-only. Kirim SATU touchpoint nurturing ke outlet terpilih secara paksa
// (bypass kill-switch global) untuk uji coba — email dikirim via SMTP server.
// Preview touchpoint yang AKAN terkirim ditampilkan dulu (dry, tanpa kirim).
// ══════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';
require_once SA_ROOT . '/../core/ErrorLogger.php';
require_once SA_ROOT . '/../core/TrialNurture.php';

$db = Database::get();

// ── Handle kirim tes (POST) ────────────────────────────
$flash = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'send') {
    saVerifyCsrf();
    $oid = (int)($_POST['outlet_id'] ?? 0);
    try {
        $sent = TrialNurture::runOutlet($oid, true); // force = bypass kill-switch
        if ($sent) {
            $flash = ['ok' => true, 'msg' => "Terkirim: touchpoint <strong>$sent</strong> untuk outlet #$oid. Cek inbox + tabel di bawah."];
        } else {
            $flash = ['ok' => false, 'msg' => "Tidak ada yang dikirim untuk outlet #$oid — kemungkinan touchpoint sudah pernah terkirim (anti-spam), outlet tak eligible, atau email kosong."];
        }
    } catch (Throwable $e) {
        error_log('[nurture_test] '.$e->getMessage());
        $flash = ['ok' => false, 'msg' => 'Error: '.htmlspecialchars($e->getMessage())];
    }
}

// ── Kandidat outlet (trial/grace/suspended) + preview touchpoint ──
$rows = $db->query(
    "SELECT o.id, o.tenant_id, o.status, o.nama_outlet, o.trial_starts_at, o.trial_ends_at, o.grace_ends_at,
            t.email owner_email, t.owner_name, t.owner_wa, t.nama_perusahaan, t.onboarding_step
       FROM outlets o JOIN tenants t ON t.id=o.tenant_id
      WHERE o.status IN ('trial','grace','suspended') ORDER BY o.tenant_id"
)->fetchAll(PDO::FETCH_ASSOC);

$pick = new ReflectionMethod('TrialNurture', 'pickTouchpoint');

// Riwayat nurturing terakhir (hl_notif_log)
$recent = $db->query(
    "SELECT tenant_id, outlet_id, type, channel, target, status, sent_at
       FROM hl_notif_log WHERE type LIKE 'trial_%'
      ORDER BY sent_at DESC LIMIT 15"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php saRenderHead('Test Nurturing'); ?>
</head>
<body>
<?php saRenderNav('', 'Test Trial Nurturing'); ?>

<div class="sa-page-header">
  <div>
    <h1>📨 Test Trial Nurturing</h1>
    <p>Kirim satu touchpoint nurturing ke outlet terpilih (paksa, bypass kill-switch) untuk uji coba.</p>
  </div>
</div>

<?php if ($flash): ?>
<div style="margin:16px 0;padding:12px 16px;border-radius:10px;font-size:14px;
     background:<?= $flash['ok'] ? 'rgba(16,185,129,.12)' : 'rgba(226,75,74,.12)' ?>;
     border:1px solid <?= $flash['ok'] ? 'rgba(16,185,129,.4)' : 'rgba(226,75,74,.4)' ?>;
     color:<?= $flash['ok'] ? '#10B981' : '#E24B4A' ?>">
  <?= $flash['msg'] ?>
</div>
<?php endif; ?>

<div class="sa-ai-border" style="padding:20px;margin-top:8px">
  <h3 style="margin:0 0 12px;font-size:15px;color:var(--glow)">Outlet trial / grace / suspended</h3>
  <table class="sa-table">
    <thead><tr>
      <th>Outlet</th><th>Status</th><th>Email tenant</th>
      <th>Touchpoint (preview)</th><th style="text-align:right">Aksi</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $tp = $pick->invoke(null, $r);
        $tpLabel = $tp ? $tp['type'] : '(tidak ada)';
        $noEmail = empty($r['owner_email']);
    ?>
      <tr>
        <td style="font-size:12px">#<?= (int)$r['id'] ?> · <?= htmlspecialchars($r['nama_outlet']) ?></td>
        <td style="font-size:12px"><?= htmlspecialchars($r['status']) ?></td>
        <td style="font-size:12px;<?= $noEmail ? 'color:var(--amber)' : '' ?>"><?= $noEmail ? '(kosong → hanya in-app)' : htmlspecialchars($r['owner_email']) ?></td>
        <td style="font-family:'DM Mono',monospace;font-size:12px"><?= htmlspecialchars($tpLabel) ?>
            <?php if ($tp): ?><br><span style="color:var(--ash);font-size:11px"><?= htmlspecialchars($tp['subject']) ?></span><?php endif; ?></td>
        <td style="text-align:right">
          <?php if ($tp): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('Kirim tes <?= htmlspecialchars($tpLabel) ?> ke outlet #<?= (int)$r['id'] ?>?')">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(saGetCsrf()) ?>">
            <input type="hidden" name="action" value="send">
            <input type="hidden" name="outlet_id" value="<?= (int)$r['id'] ?>">
            <button type="submit" class="lmx-btn" style="font-size:12px;padding:6px 12px">Kirim tes →</button>
          </form>
          <?php else: ?><span style="color:var(--ash);font-size:12px">—</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p style="color:var(--ash);font-size:12px;margin-top:10px">Catatan: tiap touchpoint anti-spam (max 1×/outlet). Kalau mau kirim ulang touchpoint yang sama, hapus dulu barisnya di <code>hl_notif_log</code>.</p>
</div>

<div class="sa-ai-border" style="padding:20px;margin-top:16px">
  <h3 style="margin:0 0 12px;font-size:15px;color:var(--glow)">15 log nurturing terakhir (hl_notif_log)</h3>
  <?php if (empty($recent)): ?>
    <div style="color:var(--ash);font-size:13px">Belum ada.</div>
  <?php else: ?>
  <table class="sa-table">
    <thead><tr><th>Waktu</th><th>Tenant/Outlet</th><th>Type</th><th>Channel</th><th>Target</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $l): ?>
      <tr>
        <td style="font-size:11px;font-family:'DM Mono',monospace"><?= htmlspecialchars(substr((string)$l['sent_at'],0,16)) ?></td>
        <td style="font-size:12px">t<?= (int)$l['tenant_id'] ?>/o<?= (int)$l['outlet_id'] ?></td>
        <td style="font-family:'DM Mono',monospace;font-size:12px"><?= htmlspecialchars($l['type']) ?></td>
        <td style="font-size:12px"><?= htmlspecialchars($l['channel']) ?></td>
        <td style="font-size:11px"><?= htmlspecialchars((string)$l['target']) ?></td>
        <td style="font-size:12px;color:<?= $l['status']==='sent'?'#10B981':'#E24B4A' ?>"><?= htmlspecialchars($l['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php saRenderNavClose(); ?>
</body>
</html>

<?php
if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';
require_once SA_ROOT . '/../core/SaPermission.php';
require_once SA_ROOT . '/../core/BillingConfig.php';
require_once SA_ROOT . '/../core/ManualPay.php';

SaPermission::require('super_admins.manage');

$activePage = 'billing-config';
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    saVerifyCsrf();
    $saId = (int)($_SESSION['superadmin_id'] ?? 0);

    $fields = [
        'midtrans_env'           => $_POST['midtrans_env']           ?? 'sandbox',
        'midtrans_server_key'    => $_POST['midtrans_server_key']    ?? '',
        'midtrans_client_key'    => $_POST['midtrans_client_key']    ?? '',
        'outlet_activation_fee'  => $_POST['outlet_activation_fee']  ?? '800000',
        'payment_expiry_minutes' => $_POST['payment_expiry_minutes'] ?? '15',
    ];

    foreach ($fields as $key => $val) {
        // Untuk server_key/client_key: kalau kosong, jangan overwrite (allow blank submit untuk update field lain)
        if (in_array($key, ['midtrans_server_key', 'midtrans_client_key'], true) && trim($val) === '') {
            continue;
        }
        BillingConfig::set($key, trim($val), $saId);
    }

    // ── Field jalur bayar manual ──
    BillingConfig::set('manual_payment_enabled', isset($_POST['manual_payment_enabled']) ? '1' : '0', $saId);
    foreach (['manual_bank_name','manual_bank_account_no','manual_bank_holder','manual_payment_expiry_hours'] as $mk) {
        BillingConfig::set($mk, trim($_POST[$mk] ?? ''), $saId);
    }

    logSuperAdminAction('billing_config_update', null, 'Update Midtrans config');
    $msg = 'Config berhasil disimpan.';
}

$conf = [];
foreach (BillingConfig::all() as $row) {
    $conf[$row['key_name']] = $row;
}

function maskKey(?string $key): string {
    if (!$key || strlen($key) < 8) return '';
    return str_repeat('•', max(8, strlen($key) - 4)) . substr($key, -4);
}
?>
<?php saRenderHead('Billing Config'); ?>
<body>
<div class="sa-layout">
<?php saRenderNav('billing-config', 'Billing Config'); ?>

<div class="sa-content">
  <div class="sa-page-header">
    <h1>💳 Billing Config</h1>
    <p>Konfigurasi Midtrans + fee untuk billing tenant ke LAMASY platform.</p>
  </div>

  <?php if ($msg): ?><div class="sa-alert-banner info"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="sa-alert-banner danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

  <form method="POST">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(saGetCsrf()) ?>">

    <div class="sa-card">
      <div class="sa-card-head">
        <h3>🔧 Midtrans Credentials</h3>
      </div>
      <div class="sa-card-body" style="padding: 22px 24px;">
        <div class="form-group" style="margin-bottom: 16px;">
          <label>Environment</label>
          <select name="midtrans_env" style="width:100%;padding:10px 14px;background:var(--slate-elev);border:1px solid var(--crease);border-radius:8px;color:var(--glow);">
            <option value="sandbox" <?= ($conf['midtrans_env']['value_text'] ?? '') === 'sandbox' ? 'selected' : '' ?>>Sandbox (testing)</option>
            <option value="production" <?= ($conf['midtrans_env']['value_text'] ?? '') === 'production' ? 'selected' : '' ?>>Production (real money!)</option>
          </select>
        </div>

        <div class="form-group" style="margin-bottom: 16px;">
          <label>Server Key <span style="color:var(--ash-dim);font-weight:400;font-size:11px">(masked — kosongkan untuk tidak ubah)</span></label>
          <input type="password" name="midtrans_server_key" placeholder="<?= htmlspecialchars(maskKey($conf['midtrans_server_key']['value_text'] ?? '') ?: 'Belum di-set') ?>"
                 style="width:100%;padding:10px 14px;background:var(--slate-elev);border:1px solid var(--crease);border-radius:8px;color:var(--glow);font-family:var(--mono);">
          <small style="color:var(--ash-dim);font-size:11px;">Ambil di Midtrans Dashboard → Settings → Access Keys</small>
        </div>

        <div class="form-group" style="margin-bottom: 0;">
          <label>Client Key <span style="color:var(--ash-dim);font-weight:400;font-size:11px">(kosongkan untuk tidak ubah)</span></label>
          <input type="text" name="midtrans_client_key" placeholder="<?= htmlspecialchars(maskKey($conf['midtrans_client_key']['value_text'] ?? '') ?: 'Belum di-set') ?>"
                 style="width:100%;padding:10px 14px;background:var(--slate-elev);border:1px solid var(--crease);border-radius:8px;color:var(--glow);font-family:var(--mono);">
        </div>
      </div>
    </div>

    <div class="sa-card">
      <div class="sa-card-head">
        <h3>💰 Fee Configuration</h3>
      </div>
      <div class="sa-card-body" style="padding: 22px 24px;">
        <div class="form-group" style="margin-bottom: 16px;">
          <label>Outlet Activation Fee (IDR)</label>
          <input type="number" name="outlet_activation_fee" value="<?= htmlspecialchars($conf['outlet_activation_fee']['value_text'] ?? '800000') ?>" min="0" required
                 style="width:100%;padding:10px 14px;background:var(--slate-elev);border:1px solid var(--crease);border-radius:8px;color:var(--glow);">
          <small style="color:var(--ash-dim);font-size:11px;">Fee aktivasi outlet ke-2 dan seterusnya. Default Rp 800.000.</small>
        </div>

        <div class="form-group" style="margin-bottom: 0;">
          <label>Payment Expiry (menit)</label>
          <input type="number" name="payment_expiry_minutes" value="<?= htmlspecialchars($conf['payment_expiry_minutes']['value_text'] ?? '15') ?>" min="5" max="1440" required
                 style="width:100%;padding:10px 14px;background:var(--slate-elev);border:1px solid var(--crease);border-radius:8px;color:var(--glow);">
          <small style="color:var(--ash-dim);font-size:11px;">Berapa menit sampai payment expire. Default 15 menit.</small>
        </div>
      </div>
    </div>

    <div class="sa-card">
      <div class="sa-card-head">
        <h3>🏦 Pembayaran Manual (Transfer Bank)</h3>
      </div>
      <div class="sa-card-body" style="padding: 22px 24px;">
        <div class="form-group" style="margin-bottom:16px;">
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
            <input type="checkbox" name="manual_payment_enabled" value="1"
                   <?= (($conf['manual_payment_enabled']['value_text'] ?? '0') === '1') ? 'checked' : '' ?>>
            Aktifkan jalur transfer manual (fallback saat QRIS/Midtrans belum aktif)
          </label>
          <small style="color:var(--ash-dim);font-size:11px;">Muncul hanya jika switch ini ON dan ketiga field rekening di bawah terisi.</small>
        </div>
        <div class="form-group" style="margin-bottom:16px;">
          <label>Nama Bank</label>
          <input type="text" name="manual_bank_name" value="<?= htmlspecialchars($conf['manual_bank_name']['value_text'] ?? '') ?>" placeholder="BCA"
                 style="width:100%;padding:10px 14px;background:var(--slate-elev);border:1px solid var(--crease);border-radius:8px;color:var(--glow);">
        </div>
        <div class="form-group" style="margin-bottom:16px;">
          <label>No Rekening</label>
          <input type="text" name="manual_bank_account_no" value="<?= htmlspecialchars($conf['manual_bank_account_no']['value_text'] ?? '') ?>" placeholder="1234567890"
                 style="width:100%;padding:10px 14px;background:var(--slate-elev);border:1px solid var(--crease);border-radius:8px;color:var(--glow);font-family:var(--mono);">
        </div>
        <div class="form-group" style="margin-bottom:16px;">
          <label>Atas Nama</label>
          <input type="text" name="manual_bank_holder" value="<?= htmlspecialchars($conf['manual_bank_holder']['value_text'] ?? '') ?>" placeholder="Ignatius Rizky"
                 style="width:100%;padding:10px 14px;background:var(--slate-elev);border:1px solid var(--crease);border-radius:8px;color:var(--glow);">
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label>Masa Berlaku (jam)</label>
          <input type="number" name="manual_payment_expiry_hours" value="<?= htmlspecialchars($conf['manual_payment_expiry_hours']['value_text'] ?? '24') ?>" min="1" max="168"
                 style="width:100%;padding:10px 14px;background:var(--slate-elev);border:1px solid var(--crease);border-radius:8px;color:var(--glow);">
          <small style="color:var(--ash-dim);font-size:11px;">Berapa jam row manual berlaku sebelum kedaluwarsa. Default 24.</small>
        </div>
      </div>
    </div>

    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
      <button type="submit" class="sa-btn sa-btn-primary">💾 Simpan Config</button>
    </div>
  </form>

  <div class="sa-card" style="margin-top:20px;">
    <div class="sa-card-head"><h3>📡 Webhook URL</h3></div>
    <div class="sa-card-body" style="padding: 22px 24px;">
      <p>Set webhook URL berikut di Midtrans Dashboard → Settings → Configuration → <strong>Payment Notification URL</strong>:</p>
      <code style="display:block;padding:12px;background:var(--slate-elev);border:1px solid var(--crease);border-radius:8px;font-family:var(--mono);color:var(--teal);margin-top:8px;">
        https://lamasy.harpy.id/api/midtrans-webhook.php
      </code>
    </div>
  </div>
</div>

<?php saRenderNavClose(); ?>
</body>
</html>

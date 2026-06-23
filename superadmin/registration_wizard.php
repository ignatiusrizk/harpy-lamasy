<?php
// ══════════════════════════════════════════════════════
// superadmin/registration_wizard.php — 5-step provisioning wizard
// v2: saas_packages integration, saas_manual_payments, CoinLedger inline
// ══════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';
require_once SA_ROOT . '/../core/Database.php';
require_once SA_ROOT . '/../core/StrukGenerator.php';

date_default_timezone_set('Asia/Jakarta');

$db = Database::get();

// ── Load existing registration request jika ada ───────
$regId  = (int)($_GET['id'] ?? 0);
$regRow = null;
if ($regId) {
    $s = $db->prepare("SELECT * FROM registration_requests WHERE id=? LIMIT 1");
    $s->execute([$regId]);
    $regRow = $s->fetch();
}

// ── Init wizard session ───────────────────────────────
if (isset($_GET['new']) || empty($_SESSION['sa_wizard'])) {
    $_SESSION['sa_wizard'] = ['step' => 1, 'reg_id' => $regId];
    if ($regRow) {
        $_SESSION['sa_wizard']['nama_outlet']      = $regRow['nama_outlet'] ?? '';
        $_SESSION['sa_wizard']['nama_perusahaan']  = $regRow['nama_perusahaan'] ?? '';
        $_SESSION['sa_wizard']['owner_name']       = $regRow['owner_name'] ?? '';
        $_SESSION['sa_wizard']['owner_wa']         = $regRow['owner_wa'] ?? '';
        $_SESSION['sa_wizard']['kota']             = $regRow['kota'] ?? '';
        $_SESSION['sa_wizard']['source']           = $regRow['source'] ?? 'assisted';
        $_SESSION['sa_wizard']['notes']            = $regRow['notes'] ?? '';
        // Kalau registrasi sudah punya tenant/outlet (self-service yang sudah daftar
        // & buat trial outlet) → simpan supaya provisioning AKTIFKAN, bukan buat baru.
        $_SESSION['sa_wizard']['existing_tenant_id'] = (int)($regRow['tenant_id'] ?? 0);
        $_SESSION['sa_wizard']['existing_outlet_id'] = (int)($regRow['outlet_id'] ?? 0);
    }
}

$wiz   = &$_SESSION['sa_wizard'];
$step  = (int)($wiz['step'] ?? 1);
$error = '';
$result = null;

// ── Handle POST ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    saVerifyCsrf();
    $pStep = $_POST['step'] ?? '1';

    if ($pStep === '1') {
        $wiz['nama_outlet']     = substr(trim(strip_tags($_POST['nama_outlet'] ?? '')), 0, 100);
        $wiz['nama_perusahaan'] = substr(trim(strip_tags($_POST['nama_perusahaan'] ?? '')), 0, 100);
        $wiz['owner_name']      = substr(trim(strip_tags($_POST['owner_name'] ?? '')), 0, 100);
        $wiz['owner_wa']        = substr(trim(preg_replace('/[^0-9+\-\s]/', '', $_POST['owner_wa'] ?? '')), 0, 20);
        $wiz['kota']            = substr(trim(strip_tags($_POST['kota'] ?? '')), 0, 100);
        $wiz['source']          = in_array($_POST['source'] ?? '', ['self_service','assisted']) ? $_POST['source'] : 'assisted';
        $wiz['notes']           = substr(trim(strip_tags($_POST['notes'] ?? '')), 0, 500);

        if (!$wiz['nama_outlet'] || !$wiz['owner_name'] || !$wiz['owner_wa']) {
            $error = 'Nama outlet, nama owner, dan nomor WA wajib diisi.';
        } else {
            $wiz['step'] = 2; $step = 2;
        }
    }

    elseif ($pStep === '2') {
        $rawFee              = max(0, (int)preg_replace('/\D/', '', $_POST['setup_fee'] ?? '0'));
        $discount            = max(0, min(100, (float)($_POST['discount_pct'] ?? 0)));
        $wiz['setup_fee']    = (int)round($rawFee * (1 - $discount / 100));
        $wiz['setup_fee_ori']= $rawFee;
        $wiz['discount_pct'] = $discount;
        $wiz['coin_awal']    = max(0, (int)preg_replace('/\D/', '', $_POST['coin_awal'] ?? '0'));
        $wiz['trial_days']   = 0; // tidak ada trial — bayar dulu baru aktif
        $wiz['coin_mode']    = in_array($_POST['coin_mode'] ?? '', ['shared','per_outlet']) ? $_POST['coin_mode'] : 'shared';
        $wiz['package_id']   = null;
        $wiz['package_nama'] = null;
        $wiz['max_outlets']  = 1;
        $wiz['step'] = 3; $step = 3;
    }

    elseif ($pStep === '3') {
        $ps = $_POST['payment_status'] ?? 'belum_bayar';
        $wiz['payment_status']    = in_array($ps, ['belum_bayar','sudah_bayar','gratis']) ? $ps : 'belum_bayar';
        $wiz['metode']            = $_POST['metode'] ?? 'transfer_bca';
        $wiz['nama_pengirim']     = substr(trim($_POST['nama_pengirim'] ?? ''), 0, 100);
        $wiz['ref_transfer']      = substr(trim($_POST['ref_transfer'] ?? ''), 0, 100);
        $wiz['tanggal_bayar']     = $_POST['tanggal_bayar'] ?: date('Y-m-d');
        $wiz['catatan']           = substr(trim($_POST['catatan'] ?? ''), 0, 500);
        $wiz['adjustment_reason'] = $_POST['adjustment_reason'] ?? 'promo';
        $wiz['step'] = 4; $step = 4;
    }

    elseif ($pStep === '4') {
        $provResult = provisionTenant($wiz);
        if ($provResult['success']) {
            $wiz['step'] = 5; $step = 5;
            $result = $provResult;
            $wiz['result'] = $provResult;
        } else {
            $error = 'Provisioning gagal: ' . htmlspecialchars($provResult['error']);
            $step  = 4;
        }
    }

    elseif ($pStep === 'back') {
        $backTo = max(1, (int)($_POST['back_to'] ?? ($step - 1)));
        $wiz['step'] = $backTo; $step = $backTo;
    }
}

if ($step === 5 && !$result && !empty($wiz['result'])) {
    $result = $wiz['result'];
}

// ── Provisioning ──────────────────────────────────────
/**
 * Aktifkan tenant + outlet yang SUDAH ADA (self-service registration).
 * Tidak membuat tenant/outlet baru — hanya ubah status → active, topup coin,
 * catat pembayaran, tandai registrasi selesai.
 */
function activateExistingTenant(PDO $db, int $saId, array $wizard, int $tenantId): array
{
    $db->beginTransaction();
    try {
        // Cari outlet: prioritas outlet dari registrasi, lalu outlet utama, lalu pertama
        $outletId = (int)($wizard['existing_outlet_id'] ?? 0);
        if ($outletId <= 0) {
            $o = $db->prepare("SELECT id FROM outlets WHERE tenant_id=? ORDER BY is_main DESC, id ASC LIMIT 1");
            $o->execute([$tenantId]);
            $outletId = (int)$o->fetchColumn();
        }
        if ($outletId <= 0) {
            $db->rollBack();
            return ['success' => false, 'error' => 'Outlet untuk tenant ini tidak ditemukan.'];
        }

        $payStatus    = $wizard['payment_status'] ?? 'belum_bayar';
        $isActiveNow  = ($payStatus === 'sudah_bayar' || $payStatus === 'gratis');
        $outletStatus = $isActiveNow ? 'active' : 'pending';

        // Aktifkan outlet + tenant (idempotent)
        $db->prepare("UPDATE outlets SET status=?, activated_at=COALESCE(activated_at,NOW()) WHERE id=? AND tenant_id=?")
           ->execute([$outletStatus, $outletId, $tenantId]);
        $db->prepare("UPDATE tenants SET status=? WHERE id=?")
           ->execute([$isActiveNow ? 'active' : 'trial', $tenantId]);

        // Tandai registrasi selesai
        if (!empty($wizard['reg_id'])) {
            $db->prepare("UPDATE registration_requests SET status='completed', tenant_id=?, outlet_id=?, handled_by=?, updated_at=NOW() WHERE id=?")
               ->execute([$tenantId, $outletId, $saId ?: null, (int)$wizard['reg_id']]);
        }

        logSuperAdminAction('activate_existing_tenant', $tenantId,
            "Aktivasi self-service: outlet #$outletId, payment=$payStatus");

        $db->commit();

        // ── Coin topup + payment record (setelah commit utama) ──
        $coinAwal = (int)($wizard['coin_awal'] ?? 0);
        if ($payStatus === 'sudah_bayar') {
            $smp = $db->prepare(
                "INSERT INTO saas_manual_payments
                   (tenant_id,superadmin_id,type,nominal_dibayar,coin_dikreditkan,metode,
                    nama_pengirim,ref_transfer,tanggal_bayar,catatan,status,created_at)
                 VALUES (?,?,'setup_fee',?,?,?,?,?,?,?,'confirmed',NOW())"
            );
            $smp->execute([$tenantId,$saId,(int)($wizard['setup_fee']??0),$coinAwal,
                $wizard['metode']??'transfer_bca',$wizard['nama_pengirim']??null,
                $wizard['ref_transfer']?:null,$wizard['tanggal_bayar']?:date('Y-m-d'),$wizard['catatan']?:null]);
            if ($coinAwal > 0) {
                _inlineCoinTopup($db,$tenantId,$outletId,$coinAwal,$wizard['coin_mode']??'shared','SMP-'.$db->lastInsertId(),'Coin aktivasi outlet');
            }
        } elseif ($payStatus === 'gratis') {
            $adjReason = $wizard['adjustment_reason'] ?? 'promo';
            $smp = $db->prepare(
                "INSERT INTO saas_manual_payments
                   (tenant_id,superadmin_id,type,nominal_dibayar,coin_dikreditkan,metode,
                    catatan,adjustment_reason,status,tanggal_bayar,created_at)
                 VALUES (?,?,'adjustment',0,?,'lainnya',?,?,'confirmed',?,NOW())"
            );
            $smp->execute([$tenantId,$saId,$coinAwal,$wizard['catatan']?:'Gratis/promo aktivasi',$adjReason,date('Y-m-d')]);
            if ($coinAwal > 0) {
                _inlineCoinTopup($db,$tenantId,$outletId,$coinAwal,$wizard['coin_mode']??'shared','SMP-'.$db->lastInsertId(),'Coin gratis/promo — '.$adjReason);
            }
        }

        // ── WA message (akun sudah ada, tidak kirim password baru) ──
        $tn = $db->prepare("SELECT owner_name, owner_wa FROM tenants WHERE id=?");
        $tn->execute([$tenantId]);
        $t = $tn->fetch(PDO::FETCH_ASSOC) ?: [];
        $coinLine = ($payStatus !== 'belum_bayar' && $coinAwal > 0)
            ? "\n🪙 Coin       : +" . number_format($coinAwal,0,',','.') . " coin" : "";
        $waMsg = "Halo *" . ($t['owner_name'] ?? '') . "*!\n\n"
            . "Outlet Anda di *LaMaSy* sudah *AKTIF* 🎉" . $coinLine . "\n\n"
            . "Silakan lanjut operasional di " . (defined('APP_URL') ? APP_URL : 'https://lamasy.harpy.id') . "/login\n"
            . "(pakai akun yang sudah Anda daftarkan)\n\n_Tim LaMaSy — Harpy Group_";

        return [
            'success'           => true,
            'tenant_id'         => $tenantId,
            'outlet_id'         => $outletId,
            'username'          => '(akun sudah terdaftar)',
            'password'          => '(tidak berubah)',
            'coin_credited'     => ($payStatus !== 'belum_bayar') ? $coinAwal : 0,
            'payment_status'    => $payStatus,
            'activated_existing'=> true,
            'wa_message'        => $waMsg,
            'wa_number'         => preg_replace('/[^0-9]/', '', $t['owner_wa'] ?? ''),
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[activateExistingTenant] ' . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function provisionTenant(array $wizard): array
{
    $db = Database::get();
    $saId = (int)($_SESSION['superadmin_id'] ?? 0);

    // ── GUARD: registrasi self-service yang TENANT-nya sudah ada (sudah daftar
    // + buat trial outlet). JANGAN buat tenant/outlet baru → aktifkan yang ada.
    // Tanpa ini, wizard bikin outlet duplikat.
    $existingTenantId = (int)($wizard['existing_tenant_id'] ?? 0);
    if ($existingTenantId > 0) {
        return activateExistingTenant($db, $saId, $wizard, $existingTenantId);
    }

    $db->beginTransaction();
    try {
        // 1. Unique slug
        $slugBase = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $wizard['nama_outlet']));
        $slugBase = trim($slugBase, '_') ?: 'outlet';
        $slug = $slugBase; $i = 2;
        while (true) {
            $chk = $db->prepare("SELECT COUNT(*) FROM tenants WHERE slug=?");
            $chk->execute([$slug]);
            if ((int)$chk->fetchColumn() === 0) break;
            $slug = $slugBase . '_' . $i++;
        }

        $trialEnds  = date('Y-m-d H:i:s', strtotime('+' . max(0,(int)$wizard['trial_days']) . ' days'));
        $maxOutlets = (int)($wizard['max_outlets'] ?? 1);

        // 2. Insert tenant (coin_balance = 0; will topup via ledger if paid)
        $db->prepare(
            "INSERT INTO tenants
               (slug, db_name, nama_perusahaan, owner_name, owner_wa, status, coin_balance, coin_mode,
                total_outlets, trial_ends_at, max_outlets, provisioned_at)
             VALUES (?,?,?,?,?,?,0,?,0,?,?,NOW())"
        )->execute([
            $slug,
            'u269895997_harpy_master',
            $wizard['nama_perusahaan'] ?: $wizard['nama_outlet'],
            $wizard['owner_name'],
            $wizard['owner_wa'],
            'active',
            $wizard['coin_mode'] ?? 'shared',
            $trialEnds,
            $maxOutlets,
        ]);
        $tenantId = (int)$db->lastInsertId();

        // 3. Insert outlet
        $outletSlug = $slug . '_outlet1';
        $payStatus = $wizard['payment_status'] ?? 'belum_bayar';
        $outletStatus = ($payStatus === 'sudah_bayar' || $payStatus === 'gratis') ? 'active' : 'pending';
        $db->prepare(
            "INSERT INTO outlets (tenant_id, nama_outlet, slug, kota, status, coin_balance, is_main, setup_done)
             VALUES (?,?,?,?,?,0,1,0)"
        )->execute([
            $tenantId,
            $wizard['nama_outlet'],
            $outletSlug,
            $wizard['kota'] ?? '',
            $outletStatus,
        ]);
        $newOutletId = (int)$db->lastInsertId();

        // Auto-set nota_prefix dari nama outlet (best-effort, skip kalau kolom belum ada)
        try {
            require_once dirname(__DIR__) . '/core/NotaFormatter.php';
            $autoPrefix = NotaFormatter::generatePrefixFromName($wizard['nama_outlet']);
            $db->prepare(
                "UPDATE outlets SET nota_prefix=?, nota_format='{PREFIX}{YYMMDD}-{COUNTER:3}' WHERE id=?"
            )->execute([$autoPrefix, $newOutletId]);
        } catch (Throwable) { /* kolom belum ada → skip */ }
        $outletId = (int)$db->lastInsertId();
        $db->prepare("UPDATE tenants SET total_outlets=1 WHERE id=?")->execute([$tenantId]);

        // Seed bahan baku default
        try {
            require_once dirname(__DIR__) . '/core/TenantProvisioner.php';
            TenantProvisioner::seedDefaultBahan($db, $tenantId, $newOutletId);
        } catch (Throwable $e) {
            error_log('[registration_wizard] Gagal seed bahan default: ' . $e->getMessage());
        }

        // 4. Credentials
        $password = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 8);
        $hashedPw = password_hash($password, PASSWORD_BCRYPT);
        $username = strtolower(preg_replace('/[^a-z0-9]/i', '', $wizard['owner_name']));
        if (!$username) $username = 'owner' . $tenantId;
        $uChk = $db->prepare("SELECT COUNT(*) FROM hl_users WHERE username=?");
        $uChk->execute([$username]);
        if ((int)$uChk->fetchColumn() > 0) $username .= $tenantId;

        // 5. Admin user
        $db->prepare(
            "INSERT INTO hl_users (tenant_id, username, password, nama, role, is_active, created_at)
             VALUES (?,?,?,?,'admin',1,NOW())"
        )->execute([$tenantId, $username, $hashedPw, $wizard['owner_name']]);

        // 6. Default services
        $lStmt = $db->prepare(
            "INSERT INTO hl_layanan (tenant_id, outlet_id, nama, harga, satuan, kategori, is_active, urutan, created_at)
             VALUES (?,?,?,?,?,?,1,0,NOW())"
        );
        foreach ([
            ['Cuci Kering',  6000,  'kg', 'reguler'],
            ['Cuci Setrika', 8000,  'kg', 'reguler'],
            ['Express',      15000, 'kg', 'express'],
        ] as [$nm, $harga, $sat, $kat]) {
            $lStmt->execute([$tenantId, $outletId, $nm, $harga, $sat, $kat]);
        }

        // 7. Registration request
        if (!empty($wizard['reg_id'])) {
            $db->prepare(
                "UPDATE registration_requests SET status='completed', tenant_id=?, outlet_id=?, updated_at=NOW() WHERE id=?"
            )->execute([$tenantId, $outletId, (int)$wizard['reg_id']]);
        } else {
            $db->prepare(
                "INSERT INTO registration_requests
                   (source, nama_outlet, owner_name, owner_wa, kota, status, payment_status,
                    setup_fee, coin_awal, trial_days, coin_mode, tenant_id, outlet_id, handled_by, created_at)
                 VALUES (?,?,?,?,?,'completed',?,?,?,?,?,?,?,?,NOW())"
            )->execute([
                $wizard['source'] ?? 'assisted',
                $wizard['nama_outlet'],
                $wizard['owner_name'],
                $wizard['owner_wa'],
                $wizard['kota'] ?? '',
                $payStatus === 'belum_bayar' ? 'pending' : 'paid',
                (int)($wizard['setup_fee'] ?? 0),
                (int)($wizard['coin_awal'] ?? 0),
                (int)($wizard['trial_days'] ?? 30),
                $wizard['coin_mode'] ?? 'shared',
                $tenantId, $outletId,
                $saId ?: null,
            ]);
        }

        // 8. Log
        logSuperAdminAction('provision_tenant', $tenantId,
            "Provisioned: {$wizard['nama_outlet']} | Owner: {$wizard['owner_name']} | Fee: Rp " .
            number_format((int)($wizard['setup_fee'] ?? 0)) . " | Payment: $payStatus"
        );

        $db->commit();

        // ── 9. Seed struk templates untuk outlet baru ──
        foreach (['retail', 'b2b'] as $_tipe) {
            try {
                StrukGenerator::saveTemplate($tenantId, $outletId, $_tipe,
                    StrukGenerator::defaultTemplate($_tipe)
                );
            } catch (Throwable $e) {
                error_log('[registration_wizard] Gagal seed struk template: ' . $e->getMessage());
            }
        }

        // ── 10. Coin topup & payment record (AFTER main commit) ──
        $coinAwal = (int)($wizard['coin_awal'] ?? 0);
        $smpId = null;

        if ($payStatus === 'sudah_bayar') {
            // Record confirmed payment
            $smpStmt = $db->prepare(
                "INSERT INTO saas_manual_payments
                   (tenant_id, superadmin_id, type, nominal_dibayar, coin_dikreditkan,
                    metode, nama_pengirim, ref_transfer, tanggal_bayar, catatan, status, created_at)
                 VALUES (?,?,'setup_fee',?,?,?,?,?,?,?,'confirmed',NOW())"
            );
            $smpStmt->execute([
                $tenantId, $saId,
                (int)$wizard['setup_fee'],
                $coinAwal,
                $wizard['metode'] ?? 'transfer_bca',
                $wizard['nama_pengirim'] ?? null,
                $wizard['ref_transfer'] ?: null,
                $wizard['tanggal_bayar'] ?: date('Y-m-d'),
                $wizard['catatan'] ?: null,
            ]);
            $smpId = $db->lastInsertId();

            // Credit coin
            if ($coinAwal > 0) {
                _inlineCoinTopup($db, $tenantId, $outletId, $coinAwal,
                    $wizard['coin_mode'] ?? 'shared',
                    'SMP-' . $smpId,
                    "Coin awal aktivasi outlet"
                );
            }

        } elseif ($payStatus === 'gratis') {
            // Free/promo — record as adjustment with nominal=0
            $adjReason = $wizard['adjustment_reason'] ?? 'promo';
            $smpStmt = $db->prepare(
                "INSERT INTO saas_manual_payments
                   (tenant_id, superadmin_id, type, nominal_dibayar, coin_dikreditkan,
                    metode, catatan, adjustment_reason, status, tanggal_bayar, created_at)
                 VALUES (?,?,'adjustment',0,?,'lainnya',?,?,'confirmed',?,NOW())"
            );
            $smpStmt->execute([
                $tenantId, $saId,
                $coinAwal,
                $wizard['catatan'] ?: 'Gratis/promo saat registrasi',
                $adjReason,
                date('Y-m-d'),
            ]);
            $smpId = $db->lastInsertId();

            // Credit coin
            if ($coinAwal > 0) {
                _inlineCoinTopup($db, $tenantId, $outletId, $coinAwal,
                    $wizard['coin_mode'] ?? 'shared',
                    'SMP-' . $smpId,
                    "Coin gratis/promo — " . $adjReason
                );
            }
        }
        // belum_bayar → no payment record, no coin

        // ── WA message ──
        $coinLine = ($payStatus !== 'belum_bayar' && $coinAwal > 0)
            ? "\n🪙 Coin Awal    : +" . number_format($coinAwal, 0, ',', '.') . " coin"
            : "";

        $waMsg = "Halo *{$wizard['owner_name']}*!\n\n"
            . "Selamat datang di *LaMaSy — Laundry Management System* 🎉\n\n"
            . "Akun Anda telah aktif:\n\n"
            . "🔗 Login  : " . (defined('APP_URL') ? APP_URL : 'https://lamasy.harpy.id') . "/login\n"
            . "👤 Username : *{$username}*\n"
            . "🔑 Password : *{$password}*"
            . $coinLine . "\n\n"
            . "Silakan login dan mulai setup outlet Anda.\n"
            . "Ada pertanyaan? Hubungi kami kapan saja.\n\n_Tim LaMaSy — Harpy Group_";

        return [
            'success'       => true,
            'tenant_id'     => $tenantId,
            'outlet_id'     => $outletId,
            'username'      => $username,
            'password'      => $password,
            'coin_credited' => ($payStatus !== 'belum_bayar') ? $coinAwal : 0,
            'payment_status'=> $payStatus,
            'wa_message'    => $waMsg,
            'wa_number'     => preg_replace('/[^0-9]/', '', $wizard['owner_wa']),
        ];

    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ── Inline coin topup (no nested transaction) ─────────
function _inlineCoinTopup(PDO $db, int $tenantId, int $outletId, int $amount, string $coinMode, string $ref, string $desc): void
{
    $db->beginTransaction();
    try {
        if ($coinMode === 'per_outlet' && $outletId > 0) {
            $s = $db->prepare("SELECT coin_balance FROM outlets WHERE id=? AND tenant_id=? FOR UPDATE");
            $s->execute([$outletId, $tenantId]);
            $newBal = (int)($s->fetchColumn() ?? 0) + $amount;
            $db->prepare("UPDATE outlets SET coin_balance=? WHERE id=? AND tenant_id=?")->execute([$newBal, $outletId, $tenantId]);
            $ledgerOutlet = $outletId;
        } else {
            $s = $db->prepare("SELECT coin_balance FROM tenants WHERE id=? FOR UPDATE");
            $s->execute([$tenantId]);
            $newBal = (int)($s->fetchColumn() ?? 0) + $amount;
            $db->prepare("UPDATE tenants SET coin_balance=? WHERE id=?")->execute([$newBal, $tenantId]);
            $ledgerOutlet = null;
        }
        $db->prepare(
            "INSERT INTO coin_ledger (tenant_id, outlet_id, type, amount, feature_used, description, balance_after, ref_id)
             VALUES (?,?,'topup',?,'topup',?,?,?)"
        )->execute([$tenantId, $ledgerOutlet, $amount, $desc, $newBal, $ref]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

$csrf = saGetCsrf();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php saRenderHead('Wizard Registrasi'); ?>
<style>
/* ── Wizard steps ───────────────────────────────── */
.wizard-steps {
  display: flex; align-items: center;
  margin-bottom: 32px; overflow-x: auto; padding-bottom: 4px;
}
.wstep {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 16px; border-radius: 10px;
  font-size: 13px; font-weight: 600; color: var(--ash-dim);
  flex-shrink: 0;
}
.wstep.done   { color: #6EE7B7; }
.wstep.active { color: var(--white); background: rgba(53,232,213,.08); border: 1px solid #C7D2FE; }
.wstep-num {
  width: 28px; height: 28px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 800;
  background: var(--crease-soft); border: 1.5px solid var(--crease);
  flex-shrink: 0;
}
.wstep.done .wstep-num   { background: #A7F3D0; border-color: rgba(16,185,129,.4); color: #6EE7B7; }
.wstep.active .wstep-num { background: var(--sa-l); border-color: var(--sa); color: var(--sa); }
.wstep-connector {
  width: 24px; height: 2px; background: var(--crease); flex-shrink: 0; margin: 0 -2px;
}
.wstep-connector.done { background: rgba(16,185,129,.3); }

/* ── Form card ──────────────────────────────────── */
.wiz-card {
  background: rgba(10,15,31,.4);
  border: 1px solid var(--crease);
  border-radius: 16px; padding: 28px;
  max-width: 640px;
}
.wiz-card h2 { font-size: 18px; font-weight: 800; margin-bottom: 6px; }
.wiz-card .sub { font-size: 13px; color: var(--ash-dim); margin-bottom: 24px; }

.wiz-field { margin-bottom: 16px; }
.wiz-label { font-size: 11px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase;
  color: var(--ash); display: block; margin-bottom: 6px; }
.wiz-label .req { color: var(--red); }
.wiz-input, .wiz-select, .wiz-textarea {
  width: 100%; padding: 10px 14px;
  background: var(--crease-soft); border: 1.5px solid var(--crease);
  border-radius: var(--r); color: var(--white); font-family: var(--font); font-size: 14px; outline: none;
  transition: border-color .15s;
}
.wiz-input:focus, .wiz-select:focus, .wiz-textarea:focus {
  border-color: var(--sa); box-shadow: 0 0 0 3px #EEF2FF;
}
.wiz-textarea { resize: vertical; min-height: 80px; }
.wiz-select option { background: var(--navy); }
.field-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media(max-width:540px) { .field-grid-2 { grid-template-columns: 1fr; } }

/* ── Radio options ──────────────────────────────── */
.radio-group { display: flex; flex-direction: column; gap: 8px; }
.radio-opt {
  display: flex; align-items: flex-start; gap: 10px;
  padding: 12px 14px; border-radius: 10px; cursor: pointer;
  border: 1.5px solid var(--crease); background: rgba(10,15,31,.4);
  transition: all .15s;
}
.radio-opt:hover  { border-color: rgba(99,102,241,.3); background: rgba(53,232,213,.08); }
.radio-opt input[type=radio] { margin-top: 2px; accent-color: var(--sa); flex-shrink: 0; }
.radio-opt .opt-label { font-size: 13px; font-weight: 600; color: var(--white); }
.radio-opt .opt-sub   { font-size: 11px; color: var(--ash-dim); margin-top: 2px; }
.radio-opt.selected   { border-color: var(--sa); background: var(--sa-l); }

/* ── Pay status info box ────────────────────────── */
.pay-info-box {
  background: rgba(16,185,129,.06); border: 1px solid #A7F3D0;
  border-radius: 10px; padding: 12px 14px; font-size: 13px; color: var(--ink-soft);
  margin-top: 12px; line-height: 1.6;
}
.pay-info-box.yellow { background: rgba(245,158,11,.06); border-color: #FDE68A; }
.pay-info-box.blue   { background: rgba(53,232,213,.08); border-color: #C7D2FE; }

/* ── Review table ───────────────────────────────── */
.review-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 20px; }
.review-table td { padding: 10px 14px; border-bottom: 1px solid var(--crease-soft); }
.review-table tr:last-child td { border-bottom: none; }
.review-table td:first-child { color: var(--ash); font-weight: 600; width: 45%; }
.review-table td:last-child { color: var(--white); font-weight: 500; }
.review-table .section-head td {
  padding-top: 14px; font-weight: 700; color: var(--ash);
  font-size: 10px; letter-spacing: .1em; text-transform: uppercase;
}

/* ── Done screen ────────────────────────────────── */
.done-screen { text-align: center; padding: 20px 0; }
.done-icon { font-size: 56px; margin-bottom: 16px; }
.done-creds {
  background: rgba(53,232,213,.08); border: 1.5px solid #C7D2FE;
  border-radius: 12px; padding: 18px 20px; text-align: left;
  margin: 20px 0; font-family: var(--mono);
}
.done-creds .cred-row { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; font-size: 13px; }
.done-creds .cred-key   { color: var(--ash); }
.done-creds .cred-value { color: var(--white); font-weight: 700; }
.wa-preview {
  background: rgba(37,211,102,.06); border: 1.5px solid rgba(37,211,102,.15);
  border-radius: 12px; padding: 16px 18px; text-align: left; margin: 16px 0;
  font-size: 13px; color: var(--glow); white-space: pre-wrap; line-height: 1.6;
}
.copy-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 600;
  background: rgba(37,211,102,.12); border: 1px solid rgba(37,211,102,.25);
  color: #86efac; cursor: pointer; transition: all .15s;
}
.copy-btn:hover { background: rgba(37,211,102,.25); color: var(--glow); }
.error-box {
  background: #FECACA; border: 1px solid rgba(239,68,68,.3);
  color: #FCA5A5; padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; font-size: 13px;
}
.wiz-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 28px; gap: 12px; }
</style>
</head>
<body>
<?php saRenderNav('registrations', 'Wizard Registrasi Klien'); ?>

<div style="max-width: 700px;">

  <div class="sa-page-header" style="display:flex;align-items:center;gap:12px;">
    <a href="registrations.php" class="sa-btn sa-btn-outline sa-btn-sm">&#x2190; Kembali</a>
    <div>
      <h1>Wizard Registrasi</h1>
      <p>Provisioning tenant baru <?= $regRow ? '— Ref #' . $regId : '' ?></p>
    </div>
  </div>

  <!-- Step indicators -->
  <div class="wizard-steps">
    <?php
    $stepLabels = ['Data Klien','Aktivasi','Pembayaran','Review','Selesai'];
    foreach ($stepLabels as $i => $label):
        $n = $i + 1;
        $cls = $n < $step ? 'done' : ($n === $step ? 'active' : '');
    ?>
      <?php if ($i > 0): ?>
        <div class="wstep-connector <?= $n <= $step ? 'done' : '' ?>"></div>
      <?php endif; ?>
      <div class="wstep <?= $cls ?>">
        <div class="wstep-num"><?= $n < $step ? '✓' : $n ?></div>
        <?= htmlspecialchars($label) ?>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($error): ?>
    <div class="error-box"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- ══ STEP 1: DATA KLIEN ══════════════════════════ -->
  <?php if ($step === 1): ?>
  <div class="wiz-card">
    <h2>Data Klien</h2>
    <div class="sub">Informasi dasar outlet dan pemilik</div>
    <form method="POST">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>"/>
      <input type="hidden" name="step" value="1"/>
      <div class="field-grid-2">
        <div class="wiz-field">
          <label class="wiz-label">Nama Outlet <span class="req">*</span></label>
          <input type="text" name="nama_outlet" class="wiz-input" required
                 placeholder="Harpy Laundry Semarang" value="<?= htmlspecialchars($wiz['nama_outlet'] ?? '') ?>"/>
        </div>
        <div class="wiz-field">
          <label class="wiz-label">Nama Perusahaan / Brand</label>
          <input type="text" name="nama_perusahaan" class="wiz-input"
                 placeholder="Opsional" value="<?= htmlspecialchars($wiz['nama_perusahaan'] ?? '') ?>"/>
        </div>
      </div>
      <div class="field-grid-2">
        <div class="wiz-field">
          <label class="wiz-label">Nama Owner <span class="req">*</span></label>
          <input type="text" name="owner_name" class="wiz-input" required
                 placeholder="Budi Santoso" value="<?= htmlspecialchars($wiz['owner_name'] ?? '') ?>"/>
        </div>
        <div class="wiz-field">
          <label class="wiz-label">No WA Owner <span class="req">*</span></label>
          <input type="text" name="owner_wa" class="wiz-input" required
                 placeholder="081234567890" value="<?= htmlspecialchars($wiz['owner_wa'] ?? '') ?>"/>
        </div>
      </div>
      <div class="field-grid-2">
        <div class="wiz-field">
          <label class="wiz-label">Kota</label>
          <input type="text" name="kota" class="wiz-input"
                 placeholder="Semarang" value="<?= htmlspecialchars($wiz['kota'] ?? '') ?>"/>
        </div>
        <div class="wiz-field">
          <label class="wiz-label">Sumber</label>
          <select name="source" class="wiz-select">
            <option value="assisted" <?= ($wiz['source'] ?? 'assisted') === 'assisted' ? 'selected' : '' ?>>Assisted (oleh CS)</option>
            <option value="self_service" <?= ($wiz['source'] ?? '') === 'self_service' ? 'selected' : '' ?>>Self Service</option>
          </select>
        </div>
      </div>
      <div class="wiz-field">
        <label class="wiz-label">Catatan Internal</label>
        <textarea name="notes" class="wiz-textarea" placeholder="Opsional..."><?= htmlspecialchars($wiz['notes'] ?? '') ?></textarea>
      </div>
      <div class="wiz-footer">
        <span></span>
        <button type="submit" class="sa-btn sa-btn-primary">Lanjut &rarr;</button>
      </div>
    </form>
  </div>

  <!-- ══ STEP 2: BIAYA AKTIVASI ══════════════════════ -->
  <?php elseif ($step === 2): ?>
  <div class="wiz-card">
    <h2>Biaya Aktivasi Outlet</h2>
    <div class="sub">Tentukan biaya aktivasi dan konfigurasi awal akun</div>
    <form method="POST">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>"/>
      <input type="hidden" name="step" value="2"/>

      <div class="field-grid-2">
        <div class="wiz-field">
          <label class="wiz-label">Biaya Aktivasi Outlet (Rp)</label>
          <input type="text" name="setup_fee" id="wiz_fee" class="wiz-input" inputmode="numeric"
                 value="<?= number_format((int)($wiz['setup_fee_ori'] ?? $wiz['setup_fee'] ?? 300000), 0, ',', '.') ?>"
                 placeholder="300.000" oninput="wizRibuan(this);wizPreviewFee()" autocomplete="off"/>
          <div style="font-size:11px;color:var(--ash-dim);margin-top:4px;">0 = gratis / promo</div>
        </div>
        <div class="wiz-field">
          <label class="wiz-label">Diskon (%)</label>
          <input type="number" name="discount_pct" id="wiz_disc" class="wiz-input" min="0" max="100" step="1"
                 value="<?= (float)($wiz['discount_pct'] ?? 0) ?>"
                 placeholder="0" oninput="wizPreviewFee()"/>
          <div id="wiz_fee_preview" style="font-size:11px;color:var(--sage);margin-top:4px;min-height:16px;"></div>
        </div>
      </div>

      <div class="field-grid-2">
        <div class="wiz-field">
          <label class="wiz-label">Coin Awal Dikreditkan</label>
          <input type="text" name="coin_awal" class="wiz-input" inputmode="numeric"
                 value="<?= number_format((int)($wiz['coin_awal'] ?? 50000), 0, ',', '.') ?>"
                 placeholder="50.000" oninput="wizRibuan(this)" autocomplete="off"/>
          <div style="font-size:11px;color:var(--ash-dim);margin-top:4px;">Dikreditkan saat aktivasi</div>
        </div>
      </div>

      <div class="wiz-field">
        <label class="wiz-label">Mode Coin</label>
        <div class="radio-group">
          <?php $cm = $wiz['coin_mode'] ?? 'shared'; ?>
          <label class="radio-opt <?= $cm === 'shared' ? 'selected' : '' ?>" onclick="selectRadio(this,'coin_mode','shared')">
            <input type="radio" name="coin_mode" value="shared" <?= $cm === 'shared' ? 'checked' : '' ?>/>
            <div><div class="opt-label">Shared</div><div class="opt-sub">Semua outlet berbagi 1 saldo coin tenant</div></div>
          </label>
          <label class="radio-opt <?= $cm === 'per_outlet' ? 'selected' : '' ?>" onclick="selectRadio(this,'coin_mode','per_outlet')">
            <input type="radio" name="coin_mode" value="per_outlet" <?= $cm === 'per_outlet' ? 'checked' : '' ?>/>
            <div><div class="opt-label">Per Outlet</div><div class="opt-sub">Setiap outlet punya saldo coin sendiri</div></div>
          </label>
        </div>
      </div>

      <div class="wiz-footer">
        <button type="button" class="sa-btn sa-btn-outline" onclick="goBack(1)">&larr; Kembali</button>
        <button type="submit" class="sa-btn sa-btn-primary">Lanjut &rarr;</button>
      </div>
    </form>
  </div>

  <!-- ══ STEP 3: PEMBAYARAN ══════════════════════════ -->
  <?php elseif ($step === 3): ?>
  <div class="wiz-card">
    <h2>Status Pembayaran</h2>
    <div class="sub">
      Biaya aktivasi:
      <?php if ((float)($wiz['discount_pct'] ?? 0) > 0 && (int)($wiz['setup_fee_ori'] ?? 0) > 0): ?>
        <span style="text-decoration:line-through;color:var(--ash-dim);">Rp <?= number_format((int)$wiz['setup_fee_ori'], 0, ',', '.') ?></span>
        <strong style="color:var(--sage);margin-left:6px;">Rp <?= number_format((int)$wiz['setup_fee'], 0, ',', '.') ?></strong>
        <span style="color:#F59E0B;font-size:12px;margin-left:4px;">(−<?= (float)$wiz['discount_pct'] ?>%)</span>
      <?php else: ?>
        <strong style="color:var(--sage);"><?= (int)($wiz['setup_fee'] ?? 0) > 0 ? 'Rp ' . number_format((int)$wiz['setup_fee'], 0, ',', '.') : 'Gratis' ?></strong>
      <?php endif; ?>
    </div>
    <form method="POST" id="step3Form">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>"/>
      <input type="hidden" name="step" value="3"/>

      <?php $ps = $wiz['payment_status'] ?? 'sudah_bayar'; ?>
      <div class="wiz-field">
        <label class="wiz-label">Status Pembayaran</label>
        <div class="radio-group">
          <label class="radio-opt <?= $ps === 'sudah_bayar' ? 'selected' : '' ?>"
                 onclick="selectRadio(this,'payment_status','sudah_bayar');togglePayMode('sudah_bayar')">
            <input type="radio" name="payment_status" value="sudah_bayar" <?= $ps === 'sudah_bayar' ? 'checked' : '' ?>
                   onchange="togglePayMode('sudah_bayar')"/>
            <div>
              <div class="opt-label">✅ Sudah Bayar</div>
              <div class="opt-sub">Setup fee sudah diterima — coin awal langsung dikreditkan</div>
            </div>
          </label>
          <label class="radio-opt <?= $ps === 'belum_bayar' ? 'selected' : '' ?>"
                 onclick="selectRadio(this,'payment_status','belum_bayar');togglePayMode('belum_bayar')">
            <input type="radio" name="payment_status" value="belum_bayar" <?= $ps === 'belum_bayar' ? 'checked' : '' ?>
                   onchange="togglePayMode('belum_bayar')"/>
            <div>
              <div class="opt-label">⏳ Belum Bayar</div>
              <div class="opt-sub">Akun dibuat, coin belum dikreditkan, konfirmasi nanti</div>
            </div>
          </label>
          <label class="radio-opt <?= $ps === 'gratis' ? 'selected' : '' ?>"
                 onclick="selectRadio(this,'payment_status','gratis');togglePayMode('gratis')">
            <input type="radio" name="payment_status" value="gratis" <?= $ps === 'gratis' ? 'checked' : '' ?>
                   onchange="togglePayMode('gratis')"/>
            <div>
              <div class="opt-label">🎁 Gratis / Promo</div>
              <div class="opt-sub">Setup fee di-waive, coin awal tetap dikreditkan</div>
            </div>
          </label>
        </div>
      </div>

      <!-- Sudah bayar: detail transfer -->
      <div id="payFieldsSudah" style="<?= $ps !== 'sudah_bayar' ? 'display:none' : '' ?>">
        <div class="wiz-field">
          <label class="wiz-label">Metode Pembayaran</label>
          <select name="metode" class="wiz-select">
            <?php
            $metodes = ['transfer_bca'=>'Transfer BCA','transfer_mandiri'=>'Transfer Mandiri','transfer_bri'=>'Transfer BRI','transfer_bni'=>'Transfer BNI','qris'=>'QRIS','cash'=>'Cash','lainnya'=>'Lainnya'];
            foreach ($metodes as $v => $l):
            ?>
            <option value="<?= $v ?>" <?= ($wiz['metode'] ?? 'transfer_bca') === $v ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field-grid-2">
          <div class="wiz-field">
            <label class="wiz-label">Nama Pengirim</label>
            <input type="text" name="nama_pengirim" class="wiz-input"
                   placeholder="Nama di rekening / QRIS" value="<?= htmlspecialchars($wiz['nama_pengirim'] ?? '') ?>"/>
          </div>
          <div class="wiz-field">
            <label class="wiz-label">No. Referensi / Kode Transfer</label>
            <input type="text" name="ref_transfer" class="wiz-input"
                   placeholder="TRF-2025-001" value="<?= htmlspecialchars($wiz['ref_transfer'] ?? '') ?>"/>
          </div>
        </div>
        <div class="wiz-field">
          <label class="wiz-label">Tanggal Bayar</label>
          <input type="date" name="tanggal_bayar" class="wiz-input"
                 value="<?= htmlspecialchars($wiz['tanggal_bayar'] ?? date('Y-m-d')) ?>"/>
        </div>
        <div class="pay-info-box">
          🪙 Setelah diconfirm, <strong style="color:var(--sage);"><?= number_format((int)($wiz['coin_awal'] ?? 0),0,',','.') ?> coin</strong> akan dikreditkan ke saldo tenant.
        </div>
      </div>

      <!-- Belum bayar: info box -->
      <div id="payFieldsBelum" style="<?= $ps !== 'belum_bayar' ? 'display:none' : '' ?>">
        <div class="pay-info-box yellow">
          ⏳ Akun tenant akan dibuat tanpa kredit coin. Setelah pembayaran diterima, gunakan
          <strong>Konfirmasi Pembayaran</strong> di halaman detail client untuk mengkredit coin.
        </div>
      </div>

      <!-- Gratis: alasan -->
      <div id="payFieldsGratis" style="<?= $ps !== 'gratis' ? 'display:none' : '' ?>">
        <div class="wiz-field">
          <label class="wiz-label">Alasan</label>
          <select name="adjustment_reason" class="wiz-select">
            <?php
            $reasons = ['promo'=>'Promo','bonus_referral'=>'Bonus Referral','kompensasi_downtime'=>'Kompensasi Downtime','koreksi_error'=>'Koreksi Error','lainnya'=>'Lainnya'];
            foreach ($reasons as $v => $l):
            ?>
            <option value="<?= $v ?>" <?= ($wiz['adjustment_reason'] ?? 'promo') === $v ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="pay-info-box">
          🎁 Setup fee di-waive. <strong style="color:var(--sage);"><?= number_format((int)($wiz['coin_awal'] ?? 0),0,',','.') ?> coin</strong> tetap dikreditkan sebagai bonus/promo.
        </div>
      </div>

      <!-- Shared catatan -->
      <div class="wiz-field" id="catatanField" style="margin-top:8px;">
        <label class="wiz-label">Catatan Internal <span style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--ash-dim)">(opsional)</span></label>
        <input type="text" name="catatan" class="wiz-input"
               placeholder="Catatan untuk internal superadmin" value="<?= htmlspecialchars($wiz['catatan'] ?? '') ?>"/>
      </div>

      <div class="wiz-footer">
        <button type="button" class="sa-btn sa-btn-outline" onclick="goBack(2)">&larr; Kembali</button>
        <button type="submit" class="sa-btn sa-btn-primary">Lanjut &rarr;</button>
      </div>
    </form>
  </div>

  <!-- ══ STEP 4: REVIEW ══════════════════════════════ -->
  <?php elseif ($step === 4): ?>
  <div class="wiz-card">
    <h2>Review & Konfirmasi</h2>
    <div class="sub">Periksa semua data sebelum provisioning dijalankan</div>

    <table class="review-table">
      <tr class="section-head"><td colspan="2">Klien</td></tr>
      <tr><td>Nama Outlet</td><td><?= htmlspecialchars($wiz['nama_outlet'] ?? '-') ?></td></tr>
      <?php if (!empty($wiz['nama_perusahaan'])): ?>
      <tr><td>Perusahaan</td><td><?= htmlspecialchars($wiz['nama_perusahaan']) ?></td></tr>
      <?php endif; ?>
      <tr><td>Owner</td><td><?= htmlspecialchars($wiz['owner_name'] ?? '-') ?></td></tr>
      <tr><td>WhatsApp</td><td><?= htmlspecialchars($wiz['owner_wa'] ?? '-') ?></td></tr>
      <tr><td>Kota</td><td><?= htmlspecialchars($wiz['kota'] ?: '-') ?></td></tr>
      <tr><td>Sumber</td><td><?= ($wiz['source'] ?? '') === 'self_service' ? 'Self Service' : 'Assisted' ?></td></tr>

      <tr class="section-head"><td colspan="2">Aktivasi</td></tr>
      <tr><td>Biaya Aktivasi</td><td>
        <?php
          $feeOri  = (int)($wiz['setup_fee_ori'] ?? $wiz['setup_fee'] ?? 0);
          $feeFin  = (int)($wiz['setup_fee'] ?? 0);
          $disc    = (float)($wiz['discount_pct'] ?? 0);
          if ($disc > 0 && $feeOri > $feeFin):
        ?>
          <span style="text-decoration:line-through;color:var(--ash-dim);font-size:12px;margin-right:6px;">Rp <?= number_format($feeOri, 0, ',', '.') ?></span>
          <strong style="color:var(--sage);">Rp <?= number_format($feeFin, 0, ',', '.') ?></strong>
          <span style="color:#F59E0B;font-size:12px;margin-left:4px;">(−<?= $disc ?>%)</span>
        <?php else: ?>
          Rp <?= number_format($feeFin, 0, ',', '.') ?>
        <?php endif; ?>
      </td></tr>
      <tr><td>Coin Awal</td><td><?= number_format((int)($wiz['coin_awal'] ?? 0), 0, ',', '.') ?> coin</td></tr>
      <tr><td>Mode Coin</td><td><?= ($wiz['coin_mode'] ?? '') === 'per_outlet' ? 'Per Outlet' : 'Shared' ?></td></tr>

      <tr class="section-head"><td colspan="2">Pembayaran</td></tr>
      <?php
        $psMap = ['belum_bayar'=>'⏳ Belum Bayar','sudah_bayar'=>'✅ Sudah Bayar','gratis'=>'🎁 Gratis / Promo'];
        $ps    = $wiz['payment_status'] ?? 'belum_bayar';
      ?>
      <tr><td>Status</td><td><?= $psMap[$ps] ?? $ps ?></td></tr>
      <?php if ($ps === 'sudah_bayar'): ?>
        <?php $metodeLabels = ['transfer_bca'=>'Transfer BCA','transfer_mandiri'=>'Transfer Mandiri','transfer_bri'=>'Transfer BRI','transfer_bni'=>'Transfer BNI','qris'=>'QRIS','cash'=>'Cash','lainnya'=>'Lainnya']; ?>
        <tr><td>Metode</td><td><?= $metodeLabels[$wiz['metode'] ?? 'transfer_bca'] ?? '-' ?></td></tr>
        <?php if ($wiz['nama_pengirim'] ?? ''): ?><tr><td>Pengirim</td><td><?= htmlspecialchars($wiz['nama_pengirim']) ?></td></tr><?php endif; ?>
        <?php if ($wiz['ref_transfer'] ?? ''): ?><tr><td>Ref Transfer</td><td><?= htmlspecialchars($wiz['ref_transfer']) ?></td></tr><?php endif; ?>
        <tr><td>Tanggal</td><td><?= htmlspecialchars($wiz['tanggal_bayar'] ?? date('Y-m-d')) ?></td></tr>
        <tr><td>Coin Dikreditkan</td><td style="color:var(--sage);font-weight:700;">+<?= number_format((int)($wiz['coin_awal'] ?? 0),0,',','.') ?> coin</td></tr>
      <?php elseif ($ps === 'gratis'): ?>
        <?php $adjLabels = ['promo'=>'Promo','bonus_referral'=>'Bonus Referral','kompensasi_downtime'=>'Kompensasi Downtime','koreksi_error'=>'Koreksi Error','lainnya'=>'Lainnya']; ?>
        <tr><td>Alasan</td><td><?= $adjLabels[$wiz['adjustment_reason'] ?? 'promo'] ?? '-' ?></td></tr>
        <tr><td>Coin Dikreditkan</td><td style="color:var(--sage);font-weight:700;">+<?= number_format((int)($wiz['coin_awal'] ?? 0),0,',','.') ?> coin</td></tr>
      <?php else: ?>
        <tr><td>Coin Dikreditkan</td><td style="color:var(--ash)">0 coin (menyusul)</td></tr>
      <?php endif; ?>
      <?php if ($wiz['catatan'] ?? ''): ?>
        <tr><td>Catatan</td><td style="color:var(--ash)"><?= htmlspecialchars($wiz['catatan']) ?></td></tr>
      <?php endif; ?>
    </table>

    <div style="background:rgba(53,232,213,.08);border:1px solid #EEF2FF;border-radius:10px;padding:14px 16px;font-size:13px;color:var(--ink-soft);margin-bottom:20px;">
      Sistem akan membuat: <strong style="color:var(--white)">1 tenant &bull; 1 outlet &bull; 1 user admin &bull; 3 layanan default</strong>
    </div>

    <form method="POST" id="provisionForm">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>"/>
      <input type="hidden" name="step" value="4"/>
      <div class="wiz-footer">
        <button type="button" class="sa-btn sa-btn-outline" onclick="goBack(3)">&larr; Kembali</button>
        <button type="button" class="sa-btn sa-btn-primary" id="provBtn" onclick="startProvision()">
          🚀 Proses Sekarang
        </button>
      </div>
    </form>
  </div>

  <!-- ══ STEP 5: DONE ════════════════════════════════ -->
  <?php elseif ($step === 5 && $result): ?>
  <div class="wiz-card" style="max-width:700px;">
    <div class="done-screen">
      <div class="done-icon">🎉</div>
      <h2 style="font-size:22px;margin-bottom:8px;">Provisioning Berhasil!</h2>
      <p style="color:var(--ash);margin-bottom:24px;">
        Tenant <strong><?= htmlspecialchars($wiz['nama_outlet'] ?? '') ?></strong> sudah aktif.
        <?php if ($result['coin_credited'] > 0): ?>
          <br><span style="color:var(--sage);">+<?= number_format($result['coin_credited'],0,',','.') ?> coin dikreditkan.</span>
        <?php elseif ($result['payment_status'] === 'belum_bayar'): ?>
          <br><span style="color:#F59E0B;">Coin belum dikreditkan — konfirmasi pembayaran setelah diterima.</span>
        <?php endif; ?>
      </p>

      <div class="done-creds">
        <div style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--ash-dim);margin-bottom:10px;">Kredensial Login (tampil sekali)</div>
        <div class="cred-row">
          <span class="cred-key">URL Login</span>
          <span class="cred-value">lamasy.harpy.id/login</span>
        </div>
        <div class="cred-row">
          <span class="cred-key">Username</span>
          <span class="cred-value"><?= htmlspecialchars($result['username']) ?></span>
        </div>
        <div class="cred-row">
          <span class="cred-key">Password</span>
          <span class="cred-value" style="color:var(--sage)"><?= htmlspecialchars($result['password']) ?></span>
        </div>
        <div class="cred-row">
          <span class="cred-key">Tenant ID</span>
          <span class="cred-value">#<?= $result['tenant_id'] ?></span>
        </div>
      </div>

      <div style="text-align:left;margin-bottom:8px;display:flex;align-items:center;justify-content:space-between;">
        <span style="font-size:13px;font-weight:700;color:var(--ash)">Pesan WA untuk klien</span>
        <button class="copy-btn" onclick="copyWa()">📋 Copy Pesan</button>
      </div>
      <div class="wa-preview" id="waPreview"><?= htmlspecialchars($result['wa_message']) ?></div>

      <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:center;margin-top:20px;">
        <a href="https://wa.me/<?= htmlspecialchars($result['wa_number']) ?>?text=<?= urlencode($result['wa_message']) ?>"
           target="_blank" class="sa-btn sa-btn-wa">
          💬 Kirim via WA
        </a>
        <?php if ($result['payment_status'] === 'belum_bayar'): ?>
        <a href="client_detail.php?id=<?= $result['tenant_id'] ?>&tab=billing" class="sa-btn sa-btn-primary">
          💳 Konfirmasi Pembayaran
        </a>
        <?php else: ?>
        <a href="client_detail.php?id=<?= $result['tenant_id'] ?>" class="sa-btn sa-btn-outline">
          Lihat Detail Client
        </a>
        <?php endif; ?>
        <a href="registration_wizard.php?new=1" class="sa-btn sa-btn-primary">
          Daftarkan Lagi
        </a>
        <a href="registrations.php" class="sa-btn sa-btn-outline">
          Kembali ke Daftar
        </a>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /.max-width -->
</div></div>

<script>
function goBack(toStep) {
  const f = document.createElement('form');
  f.method = 'POST';
  f.innerHTML = `
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>"/>
    <input type="hidden" name="step" value="back"/>
    <input type="hidden" name="back_to" value="${toStep}"/>
  `;
  document.body.appendChild(f);
  f.submit();
}

function selectRadio(label, name, val) {
  document.querySelectorAll('.radio-opt').forEach(l => {
    if (l.querySelector(`[name="${name}"]`)) l.classList.remove('selected');
  });
  label.classList.add('selected');
  const inp = label.querySelector('input[type=radio]');
  if (inp) inp.checked = true;
}

// ── Thousand-separator formatter (wizard) ───────────
function wizRibuan(el) {
  const pos = el.selectionStart;
  const raw = el.value.replace(/\D/g, '');
  const fmt = raw ? parseInt(raw).toLocaleString('id-ID') : '';
  el.value  = fmt;
  // Pertahankan posisi cursor setelah format
  try { el.setSelectionRange(fmt.length, fmt.length); } catch(e) {}
}

// ── Preview harga setelah diskon (Step 2) ───────────
function wizPreviewFee() {
  const fee  = parseInt((document.getElementById('wiz_fee')?.value || '').replace(/\D/g,''))  || 0;
  const disc = parseFloat(document.getElementById('wiz_disc')?.value) || 0;
  const el   = document.getElementById('wiz_fee_preview');
  if (!el) return;
  if (fee > 0 && disc > 0) {
    const final = Math.round(fee * (1 - disc / 100));
    el.textContent = 'Harga setelah diskon: Rp ' + final.toLocaleString('id-ID');
  } else {
    el.textContent = '';
  }
}

// ── Payment mode toggle ─────────────────────────────
function togglePayMode(mode) {
  document.getElementById('payFieldsSudah').style.display  = mode === 'sudah_bayar' ? 'block' : 'none';
  document.getElementById('payFieldsBelum').style.display  = mode === 'belum_bayar' ? 'block' : 'none';
  document.getElementById('payFieldsGratis').style.display = mode === 'gratis'      ? 'block' : 'none';
}

function startProvision() {
  const btn  = document.getElementById('provBtn');
  const form = document.getElementById('provisionForm');
  if (!form) return;
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Memproses…'; }
  form.submit();
}

async function copyWa() {
  const txt = document.getElementById('waPreview')?.textContent || '';
  try {
    await navigator.clipboard.writeText(txt);
    showToast('Pesan WA berhasil di-copy!', 'success');
  } catch {
    showToast('Gagal copy — select manual', 'error');
  }
}

function showToast(msg, type='success') {
  let t = document.getElementById('toast');
  if (!t) { t = document.createElement('div'); t.id='toast'; t.className='sa-toast'; document.body.appendChild(t); }
  t.textContent = msg;
  t.className = `sa-toast ${type} show`;
  setTimeout(() => t.classList.remove('show'), 3000);
}

function saOpenNav()  { document.getElementById('saSidebar').classList.add('open'); document.getElementById('saOverlay').classList.add('open'); }
function saCloseNav() { document.getElementById('saSidebar').classList.remove('open'); document.getElementById('saOverlay').classList.remove('open'); }
</script>
</body>
</html>

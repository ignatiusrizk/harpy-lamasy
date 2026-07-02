<?php
// Test SaFinance: seed baris temp bertanda 'zzfin' ke tabel sumber, assert agregat, cleanup.
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../master/config/db.php';
require __DIR__ . '/../../core/Database.php';
require __DIR__ . '/../../core/SaFinance.php';

$db = Database::get();
$tag = 'zzfin_' . time();

// ── Setup: tenant temp (clone baris existing utk penuhi NOT NULL) ──
$src = $db->query("SELECT * FROM tenants LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$src) { echo "SKIP: tidak ada tenant\n"; exit(0); }
unset($src['id']);
$src['slug']            = $tag;
$src['email']           = $tag . '@test.invalid';
$src['nama_perusahaan'] = 'ZZFIN Test';
$src['coin_balance']    = 10000;
$cols = array_keys($src);
$db->prepare("INSERT INTO tenants (".implode(',', $cols).") VALUES (".implode(',', array_fill(0,count($cols),'?')).")")
   ->execute(array_values($src));
$tid = (int)$db->lastInsertId();

$from = '2026-01-01'; $to = '2026-12-31';

// Revenue: 1 manual confirmed (setup_fee 150000) + 1 midtrans paid (coin_topup 50000)
$db->prepare("INSERT INTO saas_manual_payments
  (tenant_id, superadmin_id, type, nominal_dibayar, coin_dikreditkan, metode, tanggal_bayar, status, notif_wa_sent, created_at)
  VALUES (?,1,'setup_fee',150000,0,'qris','2026-03-01','confirmed',0,NOW())")->execute([$tid]);
$db->prepare("INSERT INTO saas_payments
  (order_id, tenant_id, type, amount, status, paid_at, expires_at, created_at)
  VALUES (?,?,'topup_coin',50000,'paid','2026-03-02 10:00:00','2026-03-03 00:00:00',NOW())")
  ->execute([$tag.'-P', $tid]);

// Biaya AI 20000 + komisi affiliate payout 30000 (paid)
$db->prepare("INSERT INTO hl_ai_usage (tenant_id, tokens_in, tokens_out, cost_estimated_idr, coin_charged, created_at)
  VALUES (?,100,200,20000,50,'2026-03-05 09:00:00')")->execute([$tid]);
$affSrc = $db->query("SELECT id FROM hl_affiliate LIMIT 1")->fetchColumn();
$affId = null;
if ($affSrc) {
    $db->prepare("INSERT INTO hl_affiliate_payout (affiliate_id, jumlah, status, requested_at, paid_at)
      VALUES (?,30000,'paid','2026-03-04 08:00:00','2026-03-06 08:00:00')")->execute([(int)$affSrc]);
    $affId = (int)$db->lastInsertId();
}

// ── Assert ──
$rev = SaFinance::revenue($from, $to);
ok($rev['setup_fee'] >= 150000, "revenue setup_fee mencakup 150000");
ok($rev['coin_topup'] >= 50000, "revenue coin_topup mencakup 50000");
ok($rev['total'] >= 200000, "revenue total mencakup 200000");

$ai = SaFinance::aiCost($from, $to);
ok($ai >= 20000, "aiCost mencakup 20000");

if ($affId) { ok(SaFinance::affiliatePayout($from, $to) >= 30000, "affiliatePayout mencakup 30000"); }

$float = SaFinance::coinFloat();
ok($float['coin_outstanding'] >= 10000, "coinFloat outstanding mencakup 10000");
eqv($float['rp_estimate'], (int)round($float['coin_outstanding'] * 4.17), "coinFloat rp_estimate = outstanding*4.17");

$pnl = SaFinance::pnl($from, $to);
eqv($pnl['total_cost'], $pnl['ai_cost'] + $pnl['affiliate'], "total_cost = ai+affiliate");
eqv($pnl['laba'], $pnl['revenue']['total'] - $pnl['total_cost'], "laba = revenue - cost");
ok($pnl['margin_pct'] >= 0, "margin_pct non-negatif");

// Edge: periode kosong → 0, margin 0 (tak div-by-zero)
$empty = SaFinance::pnl('1990-01-01', '1990-01-02');
eqv($empty['revenue']['total'], 0, "periode kosong revenue 0");
eqv($empty['margin_pct'], 0, "periode kosong margin 0 (no div-by-zero)");

$rows = SaFinance::revenueRows($from, $to);
ok(count($rows) >= 2, "revenueRows >= 2 baris");
ok(isset($rows[0]['tenant_nama'], $rows[0]['nominal'], $rows[0]['tipe']), "revenueRows punya kolom lengkap");

// ── Cleanup ──
$db->prepare("DELETE FROM saas_manual_payments WHERE tenant_id=?")->execute([$tid]);
$db->prepare("DELETE FROM saas_payments WHERE tenant_id=?")->execute([$tid]);
$db->prepare("DELETE FROM hl_ai_usage WHERE tenant_id=?")->execute([$tid]);
if ($affId) $db->prepare("DELETE FROM hl_affiliate_payout WHERE id=?")->execute([$affId]);
$db->prepare("DELETE FROM tenants WHERE id=?")->execute([$tid]);
echo "ALL PASS\n";

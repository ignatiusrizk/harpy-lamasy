<?php
// superadmin/laporan-keuangan-export.inc.php — stream CSV laporan keuangan.
// Dipanggil dari laporan-keuangan.php (action=export) SETELAH saVerifyCsrf().
// Var $from, $to sudah tersedia dari page.
if (!defined('SA_ROOT')) { http_response_code(400); exit; }
$pnl  = SaFinance::pnl($from, $to);
$rows = SaFinance::revenueRows($from, $to);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="laporan-keuangan_' . $from . '_' . $to . '.csv"');
$out = fopen('php://output', 'w');
fprintf($out, "\xEF\xBB\xBF"); // BOM utk Excel

fputcsv($out, ['Laporan Keuangan Platform', $from . ' s/d ' . $to]);
fputcsv($out, []);
fputcsv($out, ['RINGKASAN P&L']);
fputcsv($out, ['Setup Fee',        $pnl['revenue']['setup_fee']]);
fputcsv($out, ['Top-up Coin',      $pnl['revenue']['coin_topup']]);
fputcsv($out, ['Adjustment',       $pnl['revenue']['adjustment']]);
fputcsv($out, ['Total Pendapatan', $pnl['revenue']['total']]);
fputcsv($out, ['Biaya AI',         -$pnl['ai_cost']]);
fputcsv($out, ['Komisi Affiliate', -$pnl['affiliate']]);
fputcsv($out, ['Total Biaya',      -$pnl['total_cost']]);
fputcsv($out, ['Laba Kotor',       $pnl['laba']]);
fputcsv($out, ['Margin %',         $pnl['margin_pct']]);
fputcsv($out, []);
fputcsv($out, ['COIN FLOAT']);
fputcsv($out, ['Coin Outstanding',      $pnl['coin']['coin_outstanding']]);
fputcsv($out, ['Estimasi Nilai (IDR)',  $pnl['coin']['rp_estimate']]);
fputcsv($out, ['Coin Terpakai Periode', $pnl['coin_consumed']]);
fputcsv($out, []);
fputcsv($out, ['RINCIAN TRANSAKSI']);
fputcsv($out, ['Tanggal', 'Tenant', 'Tipe', 'Nominal', 'Coin']);
foreach ($rows as $r) {
    fputcsv($out, [$r['tanggal'], $r['tenant_nama'], $r['tipe'], $r['nominal'], $r['coin']]);
}
fclose($out);

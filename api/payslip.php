<?php
// ══════════════════════════════════════════════════════
// api/payslip.php — Print/PDF slip gaji per karyawan
//
// URL: /api/payslip.php?id=N (&auto_print=1)
//
// Output: HTML print-friendly + auto window.print() kalau auto_print=1.
// User klik "Cetak Slip" di /karyawan → buka tab baru → print/save as PDF.
// ══════════════════════════════════════════════════════
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/tenant_guard.php';

$user = currentUser();
$tid  = TenantResolver::id();
$oid  = TenantResolver::outletId();

if (!hasPermission('karyawan.gaji') && !hasPermission('karyawan.view')) {
    http_response_code(403);
    echo 'Akses ditolak.';
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo 'ID slip tidak valid.';
    exit;
}

$row = TenantQuery::rawOne(
    "SELECT g.*, u.nama, u.nik, u.jabatan, u.telepon, o.nama_outlet, o.alamat AS outlet_alamat
     FROM hl_gaji g
     JOIN hl_users u ON u.id = g.user_id AND u.tenant_id = g.tenant_id
     LEFT JOIN outlets o ON o.id = g.outlet_id
     WHERE g.id = ? AND g.tenant_id = ? AND g.outlet_id = ?",
    [$id, $tid, $oid]
);
if (!$row) {
    http_response_code(404);
    echo 'Slip tidak ditemukan.';
    exit;
}

$tStmt = Database::get()->prepare("SELECT nama_perusahaan FROM tenants WHERE id = ?");
$tStmt->execute([$tid]);
$tenant = $tStmt->fetch(PDO::FETCH_ASSOC) ?: ['nama_perusahaan' => 'LaMaSy'];

// Format periode bulan jadi "Juni 2026"
$periode = '-';
if (preg_match('/^(\d{4})-(\d{2})$/', $row['bulan'] ?? '', $m)) {
    $bulanNames = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    $periode = $bulanNames[(int)$m[2] - 1] . ' ' . $m[1];
}

$pokok    = (float) ($row['gaji_pokok'] ?? 0);
$bonus    = (float) ($row['bonus'] ?? 0);
$potongan = (float) ($row['potongan'] ?? 0);
$total    = (float) ($row['total'] ?? 0);

// Load komponen kalau ada
$komponen = [];
try {
    $st = Database::get()->prepare("SELECT jenis, nama, amount, keterangan FROM hl_gaji_komponen WHERE gaji_id=? ORDER BY jenis='pokok' DESC, amount DESC, id");
    $st->execute([(int)$row['id']]);
    $komponen = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {}

$autoPrint = !empty($_GET['auto_print']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Slip Gaji — <?= htmlspecialchars($row['nama']) ?> · <?= htmlspecialchars($periode) ?></title>
<style>
* { box-sizing: border-box; }
body { font-family: 'Helvetica', Arial, sans-serif; margin: 0; padding: 32px 40px; color: #1F2937; font-size: 13px; line-height: 1.5; max-width: 720px; margin-inline: auto; }
.ps-header { border-bottom: 2px solid #0F1C3A; padding-bottom: 16px; margin-bottom: 22px; display: flex; justify-content: space-between; align-items: flex-end; }
.ps-title { font-size: 22px; font-weight: 800; color: #0F1C3A; margin: 0 0 4px; letter-spacing: -.3px; }
.ps-sub { font-size: 13px; color: #6B7280; margin: 0; }
.ps-period { text-align: right; font-size: 14px; font-weight: 700; color: #0F1C3A; }
.ps-period small { display: block; font-size: 11px; color: #6B7280; font-weight: 500; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 2px; }

.ps-info { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px; background: #F9FAFB; padding: 14px 18px; border-radius: 8px; }
.ps-info-row { font-size: 12px; }
.ps-info-row small { display: block; color: #6B7280; text-transform: uppercase; letter-spacing: .3px; margin-bottom: 2px; font-weight: 600; font-size: 10px; }
.ps-info-row strong { color: #0F1C3A; font-weight: 700; }

.ps-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 18px; }
.ps-table th { text-align: left; background: #0F1C3A; color: #fff; padding: 9px 12px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .3px; }
.ps-table th.num, .ps-table td.num { text-align: right; }
.ps-table td { padding: 10px 12px; border-bottom: 1px solid #E5E7EB; }
.ps-table tr.section td { background: #F3F4F6; font-weight: 700; color: #0F1C3A; font-size: 11px; text-transform: uppercase; letter-spacing: .3px; }
.ps-table .neg { color: #DC2626; }
.ps-table .pos { color: #059669; }
.ps-total { background: #0F1C3A; color: #fff; padding: 14px 18px; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; margin-top: 8px; }
.ps-total-label { font-size: 12px; text-transform: uppercase; letter-spacing: .5px; opacity: .85; }
.ps-total-value { font-size: 22px; font-weight: 800; font-family: 'DM Mono', 'Menlo', monospace; }

.ps-footer { margin-top: 36px; display: grid; grid-template-columns: 1fr 1fr; gap: 40px; }
.sig-box { text-align: center; font-size: 12px; }
.sig-line { border-bottom: 1px solid #1F2937; height: 56px; margin-bottom: 6px; }
.sig-label { color: #6B7280; }
.sig-name { color: #0F1C3A; font-weight: 700; margin-top: 2px; }

.note-box { margin-top: 22px; font-size: 11px; color: #6B7280; line-height: 1.6; padding: 10px 14px; background: #FFFBEB; border-left: 3px solid #F59E0B; border-radius: 4px; }
.status-pill { display: inline-block; font-size: 10px; font-weight: 700; padding: 3px 10px; border-radius: 20px; text-transform: uppercase; letter-spacing: .3px; }
.status-paid { background: #D1FAE5; color: #065F46; }
.status-pending { background: #FEF3C7; color: #92400E; }

.print-bar { background: #0F1C3A; color: #fff; padding: 10px 16px; display: flex; gap: 12px; align-items: center; margin: -32px -40px 24px; }
.print-bar button { background: #14B8A6; color: #0F1C3A; border: none; padding: 7px 16px; border-radius: 4px; cursor: pointer; font-weight: 700; }
.print-bar a { color: #fff; text-decoration: none; font-size: 12px; opacity: .85; }

@media print {
  .print-bar { display: none !important; }
  body { padding: 16px 20px; max-width: 100%; }
  .ps-table th { background: #0F1C3A !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .ps-total  { background: #0F1C3A !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .ps-table tr.section td { background: #F3F4F6 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
</head>
<body>

<div class="print-bar">
  <button onclick="window.print()">🖨️ Print / Save as PDF</button>
  <a href="/karyawan">← Kembali</a>
  <span style="margin-left:auto;font-size:11px;opacity:.7">Klik Print → di dialog, pilih "Save as PDF" untuk simpan.</span>
</div>

<div class="ps-header">
  <div>
    <h1 class="ps-title">SLIP GAJI</h1>
    <p class="ps-sub"><?= htmlspecialchars($tenant['nama_perusahaan']) ?> · <?= htmlspecialchars($row['nama_outlet'] ?? '') ?></p>
  </div>
  <div class="ps-period">
    <small>Periode</small>
    <?= htmlspecialchars($periode) ?>
  </div>
</div>

<div class="ps-info">
  <div class="ps-info-row">
    <small>Nama Karyawan</small>
    <strong><?= htmlspecialchars($row['nama']) ?></strong>
  </div>
  <div class="ps-info-row">
    <small>NIK</small>
    <strong><?= htmlspecialchars($row['nik'] ?: '-') ?></strong>
  </div>
  <div class="ps-info-row">
    <small>Jabatan</small>
    <strong><?= htmlspecialchars($row['jabatan'] ?: '-') ?></strong>
  </div>
  <div class="ps-info-row">
    <small>Status</small>
    <span class="status-pill <?= $row['status'] === 'dibayar' ? 'status-paid' : 'status-pending' ?>">
      <?= $row['status'] === 'dibayar' ? '✓ Dibayar ' . date('d M Y', strtotime($row['dibayar_at'] ?? 'now')) : '⏳ Pending' ?>
    </span>
  </div>
</div>

<?php if (!empty($komponen)): ?>
<table style="width:100%;border-collapse:collapse;margin-top:10px;font-size:13px">
  <thead><tr style="background:#F3F4F6"><th align="left" style="padding:6px">Komponen</th><th align="right" style="padding:6px">Jumlah</th></tr></thead>
  <tbody>
    <?php foreach ($komponen as $k): ?>
    <tr>
      <td style="padding:5px 6px;border-bottom:1px solid #E5E7EB">
        <?= htmlspecialchars($k['nama']) ?>
        <?php if (!empty($k['keterangan'])): ?>
          <div style="font-size:11px;color:#6B7280"><?= htmlspecialchars($k['keterangan']) ?></div>
        <?php endif; ?>
      </td>
      <td align="right" style="padding:5px 6px;border-bottom:1px solid #E5E7EB;font-weight:600;color:<?= $k['amount']>=0 ? '#065F46' : '#991B1B' ?>">
        Rp <?= number_format((int)$k['amount'], 0, ',', '.') ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <tr style="background:#F0FDF4">
      <td style="padding:8px 6px;font-weight:700">TOTAL</td>
      <td align="right" style="padding:8px 6px;font-weight:700;font-size:15px;color:#0F766E">Rp <?= number_format((int)$total, 0, ',', '.') ?></td>
    </tr>
  </tbody>
</table>
<?php else: ?>
<table class="ps-table">
  <thead>
    <tr>
      <th>Komponen</th>
      <th class="num">Jumlah</th>
    </tr>
  </thead>
  <tbody>
    <tr class="section"><td colspan="2">Pendapatan</td></tr>
    <tr>
      <td>Gaji Pokok</td>
      <td class="num">Rp <?= number_format($pokok, 0, ',', '.') ?></td>
    </tr>
    <?php if ($bonus > 0): ?>
    <tr>
      <td>Bonus / Tunjangan</td>
      <td class="num pos">+ Rp <?= number_format($bonus, 0, ',', '.') ?></td>
    </tr>
    <?php endif; ?>

    <?php if ($potongan > 0): ?>
    <tr class="section"><td colspan="2">Potongan</td></tr>
    <tr>
      <td>Potongan</td>
      <td class="num neg">- Rp <?= number_format($potongan, 0, ',', '.') ?></td>
    </tr>
    <?php endif; ?>
  </tbody>
</table>
<?php endif; ?>

<div class="ps-total">
  <span class="ps-total-label">Gaji Bersih (Take Home Pay)</span>
  <span class="ps-total-value">Rp <?= number_format($total, 0, ',', '.') ?></span>
</div>

<?php if (!empty($row['catatan'])): ?>
<div class="note-box">
  <strong>Catatan:</strong> <?= nl2br(htmlspecialchars($row['catatan'])) ?>
</div>
<?php endif; ?>

<div class="ps-footer">
  <div class="sig-box">
    <div class="sig-label">Penerima</div>
    <div class="sig-line"></div>
    <div class="sig-name"><?= htmlspecialchars($row['nama']) ?></div>
  </div>
  <div class="sig-box">
    <div class="sig-label">Disiapkan oleh</div>
    <div class="sig-line"></div>
    <div class="sig-name"><?= htmlspecialchars($user['nama'] ?? '-') ?></div>
  </div>
</div>

<p style="font-size:10px;color:#9CA3AF;text-align:center;margin-top:24px;border-top:1px dashed #E5E7EB;padding-top:12px">
  Slip ini di-generate otomatis dari LaMaSy · <?= date('d M Y, H:i') ?> WIB
</p>

<?php if ($autoPrint): ?>
<script>setTimeout(() => window.print(), 300);</script>
<?php endif; ?>

</body>
</html>

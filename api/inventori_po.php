<?php
// ══════════════════════════════════════════════════════
// api/inventori_po.php — Generate Daftar Belanja (PO)
//
// Output: HTML print-friendly + auto window.print() trigger.
// User klik tombol "Cetak Daftar Belanja" → buka di tab baru → print/save as PDF.
//
// Sumber data: hl_bahan_stok WHERE stok_terkini <= stok_minimum
// Perlu beli = MAX(stok_minimum * 2, stok_minimum) - stok_terkini
// ══════════════════════════════════════════════════════
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/tenant_guard.php';

$user = currentUser();
$tid  = TenantResolver::id();
$oid  = TenantResolver::outletId();

if (!hasPermission('inventori.view') && !hasPermission('kas.view')) {
    http_response_code(403);
    echo 'Akses ditolak.';
    exit;
}

// Outlet info
$outletRow = TenantQuery::rawOne(
    "SELECT nama_outlet, alamat, telepon FROM outlets WHERE id = ? AND tenant_id = ?",
    [$oid, $tid]
);
$tStmt = Database::get()->prepare("SELECT nama_perusahaan FROM tenants WHERE id = ?");
$tStmt->execute([$tid]);
$tenant = $tStmt->fetch(PDO::FETCH_ASSOC) ?: ['nama_perusahaan' => 'LAMASY'];

// Bahan kritis
$rows = TenantQuery::raw(
    "SELECT * FROM hl_bahan_stok
     WHERE tenant_id = ? AND outlet_id = ? AND is_active = 1
       AND stok_terkini <= stok_minimum
     ORDER BY (stok_terkini <= 0) DESC, supplier ASC, nama ASC",
    [$tid, $oid]
);

// Group by supplier untuk daftar belanja per toko
$bySupplier = [];
foreach ($rows as $r) {
    $sup = $r['supplier'] ?: '— Tanpa Supplier —';
    $bySupplier[$sup][] = $r;
}

$estTotal = 0;
foreach ($rows as $r) {
    $perluBeli = max(($r['stok_minimum'] * 2) - $r['stok_terkini'], $r['stok_minimum']);
    if ($perluBeli > 0) {
        $estTotal += $perluBeli * (int)$r['harga_beli'];
    }
}

$autoPrint = !empty($_GET['auto_print']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Daftar Belanja — <?= htmlspecialchars($outletRow['nama_outlet'] ?? 'Outlet') ?></title>
<style>
* { box-sizing: border-box; }
body { font-family: 'Helvetica', Arial, sans-serif; margin: 0; padding: 28px 36px; color: #1F2937; font-size: 13px; line-height: 1.5; }
.po-header { border-bottom: 2px solid #0F1C3A; padding-bottom: 14px; margin-bottom: 20px; }
.po-title { font-size: 22px; font-weight: 800; color: #0F1C3A; margin: 0 0 4px; }
.po-sub { font-size: 13px; color: #6B7280; margin: 0; }
.po-meta { display: flex; justify-content: space-between; margin: 14px 0 22px; font-size: 12px; }
.po-meta div { color: #4B5563; }
.po-meta strong { color: #0F1C3A; }
.supplier-section { margin-bottom: 22px; page-break-inside: avoid; }
.supplier-name { font-size: 14px; font-weight: 700; color: #0F1C3A; background: #F3F4F6; padding: 8px 12px; border-radius: 4px; margin-bottom: 0; }
.po-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.po-table th { background: #0F1C3A; color: white; padding: 8px 10px; text-align: left; font-weight: 600; font-size: 11px; text-transform: uppercase; }
.po-table th.num, .po-table td.num { text-align: right; }
.po-table td { padding: 8px 10px; border-bottom: 1px solid #E5E7EB; }
.po-table tr.habis td { background: #FEF2F2; }
.po-table tr.habis .status { color: #DC2626; font-weight: 700; }
.po-table tr.minim .status { color: #D97706; font-weight: 600; }
.po-footer { margin-top: 24px; padding-top: 14px; border-top: 1px solid #E5E7EB; display: flex; justify-content: space-between; font-size: 12px; }
.po-total { font-weight: 800; font-size: 16px; color: #0F1C3A; }
.empty { text-align: center; padding: 60px 20px; color: #10B981; font-size: 16px; }
.print-bar { background: #0F1C3A; color: white; padding: 10px 16px; display: flex; gap: 10px; align-items: center; margin: -28px -36px 22px; }
.print-bar button { background: #14B8A6; color: #0F1C3A; border: none; padding: 6px 14px; border-radius: 4px; cursor: pointer; font-weight: 700; }
.print-bar a { color: white; text-decoration: none; font-size: 12px; opacity: .85; }
@media print {
  .print-bar { display: none !important; }
  body { padding: 16px 20px; }
  .supplier-section { page-break-inside: avoid; }
}
</style>
</head>
<body>

<div class="print-bar">
  <button onclick="window.print()">🖨️ Print / Save as PDF</button>
  <a href="/inventori">← Kembali ke Inventori</a>
  <span style="margin-left:auto;font-size:11px;opacity:.7">Klik Print → di dialog, pilih "Save as PDF" untuk simpan.</span>
</div>

<div class="po-header">
  <h1 class="po-title">📦 DAFTAR BELANJA BAHAN BAKU</h1>
  <p class="po-sub"><?= htmlspecialchars($tenant['nama_perusahaan']) ?> — <?= htmlspecialchars($outletRow['nama_outlet'] ?? '') ?></p>
</div>

<div class="po-meta">
  <div><strong>Tanggal cetak:</strong> <?= date('d F Y, H:i') ?> WIB</div>
  <div><strong>Disiapkan oleh:</strong> <?= htmlspecialchars($user['nama'] ?? '-') ?></div>
  <div><strong>Total item:</strong> <?= count($rows) ?> bahan</div>
</div>

<?php if (empty($rows)): ?>
  <div class="empty">
    ✅ Tidak ada bahan yang perlu di-restock saat ini. Stok semua aman.
  </div>
<?php else: ?>
  <?php foreach ($bySupplier as $sup => $items): ?>
    <div class="supplier-section">
      <div class="supplier-name">🏪 <?= htmlspecialchars($sup) ?> <span style="color:#6B7280;font-weight:400;font-size:11px">(<?= count($items) ?> item)</span></div>
      <table class="po-table">
        <thead>
          <tr>
            <th style="width:32px">#</th>
            <th>Nama Bahan</th>
            <th>Kategori</th>
            <th class="num">Stok Skg</th>
            <th class="num">Stok Min</th>
            <th class="num">Perlu Beli</th>
            <th class="num">Harga/Sat</th>
            <th class="num">Subtotal</th>
            <th style="width:60px">Status</th>
          </tr>
        </thead>
        <tbody>
        <?php
        $no = 0;
        $supTotal = 0;
        foreach ($items as $r):
          $no++;
          $perluBeli = max(($r['stok_minimum'] * 2) - $r['stok_terkini'], $r['stok_minimum']);
          $subtotal = $perluBeli * (int)$r['harga_beli'];
          $supTotal += $subtotal;
          $cls = $r['status_stok'] === 'habis' ? 'habis' : 'minim';
        ?>
          <tr class="<?= $cls ?>">
            <td><?= $no ?></td>
            <td><strong><?= htmlspecialchars($r['nama']) ?></strong></td>
            <td><?= htmlspecialchars(ucwords(str_replace('_',' ',$r['kategori']))) ?></td>
            <td class="num"><?= $r['stok_terkini'] ?> <?= htmlspecialchars($r['satuan']) ?></td>
            <td class="num"><?= $r['stok_minimum'] ?></td>
            <td class="num"><strong><?= $perluBeli ?> <?= htmlspecialchars($r['satuan']) ?></strong></td>
            <td class="num"><?= $r['harga_beli'] > 0 ? 'Rp ' . number_format($r['harga_beli'], 0, ',', '.') : '-' ?></td>
            <td class="num"><strong><?= $subtotal > 0 ? 'Rp ' . number_format($subtotal, 0, ',', '.') : '-' ?></strong></td>
            <td class="status"><?= $r['status_stok'] === 'habis' ? '🔴 HABIS' : '⚠️ MINIM' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($supTotal > 0): ?>
        <tfoot>
          <tr style="background:#F9FAFB;font-weight:700">
            <td colspan="7" style="text-align:right;padding:8px 10px">Subtotal <?= htmlspecialchars($sup) ?>:</td>
            <td class="num" style="padding:8px 10px">Rp <?= number_format($supTotal, 0, ',', '.') ?></td>
            <td></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
  <?php endforeach; ?>

  <div class="po-footer">
    <div>
      <strong>Catatan:</strong> "Perlu Beli" dihitung dari (2× stok minimum) − stok sekarang, agar buffer stok memadai.<br>
      Harga bisa berbeda dengan supplier saat pembelian aktual.
    </div>
    <div style="text-align:right">
      <div style="margin-bottom:4px">Estimasi total belanja:</div>
      <div class="po-total">Rp <?= number_format($estTotal, 0, ',', '.') ?></div>
    </div>
  </div>
<?php endif; ?>

<?php if ($autoPrint && !empty($rows)): ?>
<script>setTimeout(() => window.print(), 300);</script>
<?php endif; ?>

</body>
</html>

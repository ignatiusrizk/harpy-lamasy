<?php
// ══════════════════════════════════════════════════════════════════
// hq/coin-info.php — Tampilkan harga coin per fitur (view-only)
// Transparansi pricing untuk owner — tahu berapa coin per fitur
// ══════════════════════════════════════════════════════════════════

$activePage = 'hq-coin-info';
$pageTitle  = 'Harga Fitur Coin';

define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';
require_once ROOT . '/core/CoinLedger.php';

$db   = Database::get();
$tid  = (int)$hqTenant['id'];

// Ambil semua pricing aktif dari DB
try {
    $pricing = $db->query(
        "SELECT feature_key, nama_fitur, kategori, harga_coin, deskripsi
           FROM saas_coin_pricing
          WHERE is_active = 1
          ORDER BY kategori, harga_coin DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $pricing = []; // tabel belum ada (migration belum dijalankan)
}

// Group by kategori
$grouped = [];
foreach ($pricing as $p) {
    $grouped[$p['kategori']][] = $p;
}

// Saldo coin sekarang
$saldo = (int)($hqTenant['coin_balance'] ?? 0);

$katMeta = [
    'dokumen'  => ['ico' => '📄', 'label' => 'Dokumen & Nota'],
    'whatsapp' => ['ico' => '📱', 'label' => 'WhatsApp Notifikasi'],
    'ai'       => ['ico' => '🤖', 'label' => 'AI Tools'],
    'export'   => ['ico' => '📤', 'label' => 'Export Laporan'],
    'lainnya'  => ['ico' => '⚙️', 'label' => 'Lainnya'],
];
?>
<?php require __DIR__ . '/_layout_open.php'; ?>

<style>
  h1 { font-size:1.4rem;font-weight:800;color:#0F1C3A;margin-bottom:6px; }
  .info-banner {
    background:linear-gradient(135deg,#0F1C3A 0%,#1B2D5A 100%);
    color:#fff; padding:18px 22px; border-radius:14px; margin-bottom:20px;
    display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;
  }
  .info-banner .saldo-label { font-size:12px; color:rgba(255,255,255,.6); text-transform:uppercase; letter-spacing:.05em; }
  .info-banner .saldo-num { font-size:28px; font-weight:800; color:#35E8D5; font-family:'DM Mono',monospace; margin-top:2px; }
  .info-banner .info-text { font-size:13px; color:rgba(255,255,255,.7); max-width:380px; }

  .kat-section { margin-bottom:24px; }
  .kat-header {
    display:flex; align-items:center; gap:10px; margin-bottom:10px;
    padding-bottom:8px; border-bottom:2px solid #E8FBF9;
  }
  .kat-header .ico { font-size:20px; }
  .kat-header h3 { font-size:14px; font-weight:700; color:#0F1C3A; margin:0; }

  .pricing-grid {
    display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:10px;
  }
  .pricing-card {
    background:#fff; border:1px solid #E5E7EB; border-radius:10px; padding:14px;
    display:flex; justify-content:space-between; align-items:flex-start; gap:10px;
    transition:all .15s;
  }
  .pricing-card:hover { border-color:#35E8D5; box-shadow:0 2px 8px rgba(53,232,213,.15); }
  .pricing-card .nama { font-size:13px; font-weight:600; color:#1B2D5A; }
  .pricing-card .desk { font-size:11px; color:#6C7A8D; margin-top:2px; line-height:1.4; }
  .pricing-card .key  { font-size:10px; font-family:'DM Mono',monospace; color:#9CA3AF; margin-top:4px; }
  .pricing-card .harga {
    font-size:18px; font-weight:800; color:#0F1C3A; font-family:'DM Mono',monospace;
    white-space:nowrap; text-align:right;
  }
  .pricing-card .harga small { display:block; font-size:10px; font-weight:500; color:#9CA3AF; font-family:'Plus Jakarta Sans',sans-serif; margin-top:2px; }
  .pricing-card .harga.gratis { color:#10B981; }

  .empty-state {
    text-align:center; padding:60px 20px; background:#fff; border-radius:14px; border:1px dashed #E5E7EB;
    color:#6C7A8D;
  }
  .empty-state .ico { font-size:40px; margin-bottom:10px; opacity:.4; }

  .note-footer {
    margin-top:24px; padding:14px; background:#F7F8FC; border-radius:10px;
    font-size:12px; color:#6C7A8D; line-height:1.5;
  }
</style>

<h1>💲 Harga Fitur Coin
  <small style="display:block;font-size:13px;font-weight:400;color:#6B7280;margin-top:2px;">
    Daftar harga coin per fitur. Update otomatis dari sistem.
  </small>
</h1>

<!-- Saldo Banner -->
<div class="info-banner">
  <div>
    <div class="saldo-label">Saldo Coin Anda</div>
    <div class="saldo-num"><?= number_format($saldo, 0, ',', '.') ?></div>
  </div>
  <div class="info-text">
    <strong>Transparansi pricing:</strong> setiap fitur memotong coin sesuai harga di bawah.
    Harga dapat berubah sewaktu-waktu — selalu cek halaman ini untuk pricing terbaru.
  </div>
</div>

<?php if (empty($pricing)): ?>
  <div class="empty-state">
    <div class="ico">💲</div>
    <p>Belum ada data pricing.<br>
    <small style="color:#9CA3AF">Hubungi admin platform jika ini error.</small></p>
  </div>
<?php else: ?>

  <?php foreach ($katMeta as $kat => $meta): ?>
    <?php if (empty($grouped[$kat])) continue; ?>
    <div class="kat-section">
      <div class="kat-header">
        <span class="ico"><?= $meta['ico'] ?></span>
        <h3><?= htmlspecialchars($meta['label']) ?></h3>
      </div>
      <div class="pricing-grid">
        <?php foreach ($grouped[$kat] as $p): ?>
          <div class="pricing-card">
            <div style="flex:1;min-width:0;">
              <div class="nama"><?= htmlspecialchars($p['nama_fitur']) ?></div>
              <?php if (!empty($p['deskripsi'])): ?>
                <div class="desk"><?= htmlspecialchars($p['deskripsi']) ?></div>
              <?php endif; ?>
              <div class="key"><?= htmlspecialchars($p['feature_key']) ?></div>
            </div>
            <div class="harga <?= (int)$p['harga_coin'] === 0 ? 'gratis' : '' ?>">
              <?php if ((int)$p['harga_coin'] === 0): ?>
                GRATIS
              <?php else: ?>
                <?= number_format($p['harga_coin'], 0, ',', '.') ?>
                <small>coin</small>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>

<?php endif; ?>

<div class="note-footer">
  💡 <strong>Tips hemat coin:</strong>
  Manfaatkan fitur AI di outlet yang traffic-nya tinggi (lebih cost-effective).
  WA notif otomatis bisa di-disable per pelanggan jika tidak perlu.
  Beli paket coin Popular (250rb / 60.000 coin + bonus 20%) untuk discount terbaik.
  <br><br>
  ⚠️ <strong>Catatan:</strong>
  Harga dapat berubah sewaktu-waktu sesuai kebijakan platform.
  Cek halaman ini secara berkala untuk pricing terbaru. Setiap perubahan harga didokumentasikan di history platform.
</div>

<?php require __DIR__ . '/_layout_close.php'; ?>

<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pembayaran — LAMASY</title>
<style>
  body{font-family:system-ui,sans-serif;background:#F3F4F6;color:#1F2937;margin:0;padding:24px;}
  .card{max-width:440px;margin:40px auto;background:#fff;border:1px solid #EEF1F8;border-radius:16px;padding:24px;text-align:center;box-shadow:0 1px 6px rgba(15,28,58,.06);}
  h1{font-size:18px;color:#0F1C3A;margin:0 0 8px;}
  p{color:#6B7280;font-size:14px;line-height:1.5;}
  .btn{display:inline-block;margin-top:16px;background:#0F1C3A;color:#fff;text-decoration:none;padding:13px 22px;border-radius:12px;font-weight:800;font-size:14px;}
  .btn.teal{background:#1CC4B2;}
  .muted{margin-top:14px;font-size:12px;color:#94A3B8;}
</style></head><body>
  <div class="card">
    <h1>Pembayaran otomatis belum tersedia</h1>
    <p><?= htmlspecialchars($errMsg) ?></p>
    <?php if (!empty($showManual)): ?>
      <a class="btn teal" href="<?= htmlspecialchars($manualUrl) ?>">🏦 Bayar via Transfer Manual</a>
      <p class="muted">Transfer ke rekening kami, admin konfirmasi, coin/aktivasi langsung masuk.</p>
    <?php else: ?>
      <a class="btn" href="/dashboard">← Kembali</a>
    <?php endif; ?>
  </div>
</body></html>

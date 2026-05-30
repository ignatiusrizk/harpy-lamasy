<?php
// tos.php — Syarat & Ketentuan LaMaSy (publik, tanpa login)
$tosVersion = '1.0';
$lastUpdated = '1 Juli 2026';
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Syarat &amp; Ketentuan — LaMaSy</title>
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Segoe UI',Arial,sans-serif;background:#f5f7fa;color:#1a2540;line-height:1.7}
    .wrap{max-width:820px;margin:0 auto;padding:48px 24px 80px}
    .brand{display:flex;align-items:center;gap:12px;margin-bottom:40px}
    .brand-logo{font-size:22px;font-weight:800;color:#1F3864;letter-spacing:-0.5px}
    .brand-logo span{color:#2E5FA3}
    .brand-sub{font-size:12px;color:#888}
    h1{font-size:26px;font-weight:800;color:#1F3864;margin-bottom:6px}
    .meta{font-size:13px;color:#888;margin-bottom:36px;padding-bottom:24px;border-bottom:2px solid #e8ecf4}
    h2{font-size:16px;font-weight:700;color:#1F3864;margin:32px 0 10px;counter-increment:section}
    h2::before{content:counter(section)". ";color:#2E5FA3}
    body{counter-reset:section}
    p{margin-bottom:12px;font-size:14.5px;color:#3a4a6b}
    ul{margin:8px 0 14px 20px;font-size:14.5px;color:#3a4a6b}
    ul li{margin-bottom:6px}
    .highlight{background:#EBF3FF;border-left:3px solid #2E5FA3;padding:12px 16px;border-radius:0 8px 8px 0;margin:16px 0;font-size:14px;color:#1F3864}
    .footer-nav{text-align:center;margin-top:48px;padding-top:24px;border-top:1px solid #e8ecf4;font-size:13px;color:#888}
    .footer-nav a{color:#2E5FA3;text-decoration:none;margin:0 12px}
    .footer-nav a:hover{text-decoration:underline}
    @media(max-width:600px){.wrap{padding:32px 16px 60px}h1{font-size:22px}}
  </style>
</head>
<body>
<div class="wrap">
  <div class="brand">
    <div>
      <div class="brand-logo">La<span>Ma</span>Sy</div>
      <div class="brand-sub">Laundry Management System</div>
    </div>
  </div>

  <h1>Syarat &amp; Ketentuan Penggunaan</h1>
  <div class="meta">
    Versi <?= htmlspecialchars($tosVersion) ?> &nbsp;·&nbsp;
    Terakhir diperbarui: <?= htmlspecialchars($lastUpdated) ?> &nbsp;·&nbsp;
    <a href="/privacy" style="color:#2E5FA3">Kebijakan Privasi →</a>
  </div>

  <p>Dengan mendaftar dan menggunakan layanan LaMaSy ("Platform"), Anda ("Pengguna" atau "Tenant") menyatakan telah membaca, memahami, dan menyetujui seluruh ketentuan di bawah ini. Jika Anda tidak menyetujui ketentuan ini, harap tidak menggunakan layanan kami.</p>

  <h2>Definisi &amp; Pihak Yang Terlibat</h2>
  <ul>
    <li><strong>LaMaSy / Platform</strong> — Sistem manajemen laundry berbasis SaaS yang dikelola oleh Harpy Group.</li>
    <li><strong>Tenant</strong> — Pemilik usaha laundry yang mendaftar dan menggunakan Platform.</li>
    <li><strong>Outlet</strong> — Gerai laundry milik Tenant yang terdaftar di Platform.</li>
    <li><strong>Karyawan</strong> — Staf Tenant yang diberikan akses ke Platform oleh Tenant.</li>
    <li><strong>Pelanggan Akhir</strong> — Konsumen yang menggunakan jasa laundry milik Tenant.</li>
    <li><strong>Coin</strong> — Satuan kredit digital di dalam Platform yang digunakan untuk mengakses fitur berbayar.</li>
  </ul>

  <h2>Layanan Yang Disediakan</h2>
  <p>LaMaSy menyediakan platform manajemen laundry yang mencakup:</p>
  <ul>
    <li>Sistem kasir (POS) dan manajemen order laundry</li>
    <li>Manajemen karyawan, absensi, dan penggajian</li>
    <li>Manajemen pelanggan dan program loyalitas</li>
    <li>Notifikasi WhatsApp otomatis kepada pelanggan</li>
    <li>Laporan bisnis dan analitik</li>
    <li>Fitur AI untuk insight bisnis (menggunakan API Claude dari Anthropic)</li>
    <li>Manajemen multi-outlet dan drop point</li>
  </ul>
  <p>Platform disediakan dalam model berlangganan berbasis Coin. Fitur tertentu membutuhkan saldo Coin aktif.</p>

  <h2>Kewajiban Pengguna</h2>
  <p>Sebagai Tenant, Anda bertanggung jawab untuk:</p>
  <ul>
    <li>Memberikan informasi yang akurat dan terkini saat mendaftar</li>
    <li>Menjaga kerahasiaan kredensial akun (username dan password)</li>
    <li>Bertanggung jawab atas seluruh aktivitas yang dilakukan di bawah akun Anda</li>
    <li>Tidak menggunakan Platform untuk aktivitas ilegal, penipuan, atau yang merugikan pihak lain</li>
    <li>Memastikan data pelanggan akhir yang dimasukkan ke Platform diperoleh secara sah</li>
    <li>Melaporkan potensi kebocoran keamanan kepada tim LaMaSy segera setelah diketahui</li>
  </ul>
  <div class="highlight">
    ⚠️ Penggunaan Platform untuk kegiatan ilegal, spam, atau pelanggaran hukum Indonesia dapat menyebabkan pemutusan akun seketika tanpa pengembalian dana.
  </div>

  <h2>Pembayaran &amp; Coin System</h2>
  <ul>
    <li>Coin dibeli melalui mekanisme pembayaran yang tersedia di Platform</li>
    <li>Coin tidak memiliki masa kedaluwarsa selama akun aktif</li>
    <li>Coin bersifat non-refundable kecuali terjadi kesalahan teknis dari pihak LaMaSy</li>
    <li>Harga per Coin dan biaya fitur dapat berubah dengan pemberitahuan minimal 14 hari sebelumnya</li>
    <li>Selama periode trial, Tenant mendapatkan Coin gratis dengan jumlah yang ditentukan oleh LaMaSy</li>
    <li>Akun yang kehabisan saldo Coin akan masuk ke mode terbatas (grace period) sesuai ketentuan yang berlaku</li>
  </ul>

  <h2>Data &amp; Privasi</h2>
  <p>LaMaSy berkomitmen melindungi data Tenant dan pelanggan akhir. Detail lengkap mengenai pengelolaan data tercantum dalam <a href="/privacy" style="color:#2E5FA3">Kebijakan Privasi</a> kami.</p>
  <ul>
    <li>Data operasional laundry Anda disimpan di server yang berlokasi di Indonesia</li>
    <li>LaMaSy tidak menjual data Tenant kepada pihak ketiga</li>
    <li>Beberapa fitur menggunakan layanan pihak ketiga (Anthropic untuk AI, WhatsApp Business API) yang tunduk pada kebijakan privasi masing-masing penyedia</li>
    <li>Tenant bertanggung jawab atas legalitas pengumpulan dan penggunaan data pelanggan akhir mereka</li>
  </ul>

  <h2>Pembatasan Tanggung Jawab</h2>
  <p>LaMaSy tidak bertanggung jawab atas:</p>
  <ul>
    <li>Kerugian bisnis akibat gangguan layanan yang disebabkan oleh faktor di luar kendali LaMaSy (force majeure, gangguan infrastruktur pihak ketiga)</li>
    <li>Kesalahan penggunaan Platform oleh Tenant atau karyawannya</li>
    <li>Kegagalan notifikasi WhatsApp yang disebabkan oleh perubahan kebijakan Meta/WhatsApp</li>
    <li>Kehilangan data akibat tindakan Tenant sendiri</li>
  </ul>
  <p>Tanggung jawab maksimal LaMaSy terbatas pada nilai Coin yang dimiliki Tenant saat kejadian berlangsung.</p>

  <h2>Penghentian Layanan</h2>
  <ul>
    <li>Tenant dapat menghentikan penggunaan Platform kapan saja melalui menu Settings</li>
    <li>LaMaSy berhak menangguhkan atau menghentikan akun yang melanggar ketentuan ini tanpa pemberitahuan sebelumnya</li>
    <li>Data Tenant akan disimpan selama 90 hari setelah penghentian akun, kemudian dihapus secara permanen</li>
    <li>Coin yang tersisa saat penghentian akun oleh Tenant tidak dapat dikembalikan</li>
  </ul>

  <h2>Perubahan Ketentuan</h2>
  <p>LaMaSy berhak mengubah Syarat &amp; Ketentuan ini sewaktu-waktu. Perubahan signifikan akan diberitahukan melalui:</p>
  <ul>
    <li>Notifikasi di dalam Platform saat Tenant login</li>
    <li>Email ke alamat terdaftar (jika fitur email aktif)</li>
    <li>Pesan WhatsApp (jika tersedia)</li>
  </ul>
  <p>Penggunaan Platform setelah pemberitahuan dianggap sebagai persetujuan terhadap ketentuan yang diperbarui.</p>

  <h2>Hukum Yang Berlaku</h2>
  <p>Syarat &amp; Ketentuan ini tunduk pada hukum yang berlaku di Republik Indonesia. Segala sengketa yang timbul akan diselesaikan melalui mediasi terlebih dahulu, dan jika tidak tercapai kesepakatan, akan diselesaikan melalui pengadilan yang berwenang di Indonesia.</p>

  <h2>Kontak</h2>
  <p>Untuk pertanyaan terkait Syarat &amp; Ketentuan ini, hubungi kami:</p>
  <ul>
    <li>WhatsApp: <a href="https://wa.me/6285121519302" style="color:#2E5FA3">+62 851-2151-9302</a></li>
    <li>Email: support@lamasy.harpy.id</li>
    <li>Melalui fitur Tiket Support di dalam Platform</li>
  </ul>

  <div class="footer-nav">
    <a href="/landing">← Beranda</a>
    <a href="/privacy">Kebijakan Privasi</a>
    <a href="/login">Login</a>
    <a href="/register">Daftar Gratis</a>
  </div>
</div>
</body>
</html>

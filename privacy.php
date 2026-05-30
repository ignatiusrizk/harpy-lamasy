<?php
// privacy.php — Kebijakan Privasi LaMaSy (publik, tanpa login)
$lastUpdated = '1 Juli 2026';
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Kebijakan Privasi — LaMaSy</title>
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
    table{width:100%;border-collapse:collapse;font-size:13.5px;margin:16px 0}
    th{background:#1F3864;color:#fff;padding:10px 14px;text-align:left}
    td{padding:10px 14px;border-bottom:1px solid #e8ecf4;color:#3a4a6b;vertical-align:top}
    tr:nth-child(even) td{background:#f9fafb}
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

  <h1>Kebijakan Privasi</h1>
  <div class="meta">
    Terakhir diperbarui: <?= htmlspecialchars($lastUpdated) ?> &nbsp;·&nbsp;
    <a href="/tos" style="color:#2E5FA3">Syarat &amp; Ketentuan →</a>
  </div>

  <p>Kebijakan Privasi ini menjelaskan bagaimana LaMaSy (dikelola oleh Harpy Group) mengumpulkan, menggunakan, dan melindungi informasi Anda saat menggunakan Platform kami.</p>

  <h2>Data Yang Kami Kumpulkan</h2>
  <table>
    <tr><th>Kategori Data</th><th>Contoh</th><th>Sumber</th></tr>
    <tr>
      <td>Data Akun Tenant</td>
      <td>Nama, nomor WhatsApp, nama perusahaan, alamat outlet</td>
      <td>Diisi Tenant saat registrasi</td>
    </tr>
    <tr>
      <td>Data Operasional</td>
      <td>Order laundry, data pelanggan akhir, data karyawan, laporan kas</td>
      <td>Dimasukkan Tenant &amp; karyawan saat operasional</td>
    </tr>
    <tr>
      <td>Data Penggunaan</td>
      <td>Log aktivitas, fitur yang digunakan, waktu login</td>
      <td>Dikumpulkan otomatis oleh sistem</td>
    </tr>
    <tr>
      <td>Data Teknis</td>
      <td>Alamat IP, jenis browser, perangkat</td>
      <td>Dikumpulkan otomatis untuk keamanan</td>
    </tr>
    <tr>
      <td>Data Pembayaran</td>
      <td>Nominal transfer, nama pengirim, tanggal pembayaran Coin</td>
      <td>Dimasukkan saat pembelian Coin</td>
    </tr>
  </table>
  <div class="highlight">
    🔒 LaMaSy <strong>tidak</strong> menyimpan data kartu kredit atau informasi rekening bank Tenant.
  </div>

  <h2>Bagaimana Data Digunakan</h2>
  <ul>
    <li>Menjalankan dan meningkatkan layanan Platform</li>
    <li>Memproses dan mengirim notifikasi WhatsApp kepada pelanggan akhir Tenant</li>
    <li>Menghasilkan laporan dan analitik bisnis untuk Tenant</li>
    <li>Mendeteksi dan mencegah penipuan serta penyalahgunaan</li>
    <li>Memberikan dukungan teknis dan layanan pelanggan</li>
    <li>Mengirim pembaruan penting terkait layanan (bukan pemasaran)</li>
    <li>Fitur AI: data operasional dikirim ke API Claude (Anthropic) untuk analisis — data tidak disimpan oleh Anthropic untuk training</li>
  </ul>

  <h2>Penyimpanan &amp; Keamanan Data</h2>
  <ul>
    <li>Data disimpan di server yang berlokasi di Indonesia dengan enkripsi standar industri</li>
    <li>Akses ke database dibatasi hanya untuk tim teknis LaMaSy dengan autentikasi berlapis</li>
    <li>Password disimpan dalam bentuk hash bcrypt — tidak dapat dibaca bahkan oleh tim LaMaSy</li>
    <li>Seluruh komunikasi antara browser dan server menggunakan protokol HTTPS</li>
    <li>Log aktivitas sensitif diaudit secara berkala</li>
    <li>Backup data dilakukan secara otomatis setiap hari</li>
  </ul>

  <h2>Berbagi Data Dengan Pihak Ketiga</h2>
  <p>LaMaSy hanya berbagi data dengan pihak ketiga dalam kondisi berikut:</p>
  <table>
    <tr><th>Pihak Ketiga</th><th>Data Yang Dibagikan</th><th>Tujuan</th></tr>
    <tr>
      <td>Anthropic (Claude API)</td>
      <td>Data operasional yang relevan (tanpa nama lengkap atau ID unik)</td>
      <td>Analisis AI &amp; insight bisnis</td>
    </tr>
    <tr>
      <td>WhatsApp Business API</td>
      <td>Nomor WA pelanggan, isi pesan notifikasi</td>
      <td>Pengiriman notifikasi otomatis</td>
    </tr>
    <tr>
      <td>Penyedia hosting / infrastruktur</td>
      <td>Data tersimpan di infrastruktur mereka (terenkripsi)</td>
      <td>Operasional server</td>
    </tr>
  </table>
  <p>LaMaSy <strong>tidak menjual</strong> data Tenant atau pelanggan akhir kepada pihak manapun untuk tujuan pemasaran.</p>

  <h2>Retensi Data</h2>
  <ul>
    <li>Selama akun aktif: semua data operasional disimpan penuh</li>
    <li>Setelah akun dinonaktifkan: data dipertahankan selama 90 hari untuk keperluan pemulihan</li>
    <li>Setelah 90 hari: data dihapus secara permanen dari server produksi</li>
    <li>Log audit keamanan disimpan selama 1 tahun untuk keperluan investigasi</li>
    <li>Tenant dapat meminta penghapusan data lebih awal melalui Tiket Support</li>
  </ul>

  <h2>Hak Pengguna</h2>
  <p>Anda memiliki hak untuk:</p>
  <ul>
    <li><strong>Akses</strong> — Meminta salinan data yang kami simpan tentang Anda</li>
    <li><strong>Koreksi</strong> — Memperbarui data yang tidak akurat melalui menu Settings</li>
    <li><strong>Penghapusan</strong> — Meminta penghapusan akun dan data Anda</li>
    <li><strong>Portabilitas</strong> — Meminta ekspor data dalam format yang dapat dibaca mesin</li>
    <li><strong>Keberatan</strong> — Menolak pemrosesan data untuk tujuan tertentu</li>
  </ul>
  <p>Untuk menggunakan hak-hak ini, hubungi kami melalui Tiket Support atau WhatsApp kami.</p>

  <h2>Cookie &amp; Tracking</h2>
  <ul>
    <li>LaMaSy menggunakan cookie sesi (session cookie) untuk autentikasi — dihapus saat browser ditutup</li>
    <li>Kami tidak menggunakan cookie iklan atau pixel tracking pihak ketiga</li>
    <li>Log akses server mencatat alamat IP dan user-agent untuk keamanan — tidak digunakan untuk profiling</li>
  </ul>

  <h2>Kontak untuk Pertanyaan Privasi</h2>
  <p>Jika Anda memiliki pertanyaan atau kekhawatiran terkait privasi data Anda:</p>
  <ul>
    <li>WhatsApp: <a href="https://wa.me/6285121519302" style="color:#2E5FA3">+62 851-2151-9302</a></li>
    <li>Email: support@lamasy.harpy.id</li>
    <li>Melalui fitur Tiket Support di dalam Platform</li>
  </ul>
  <p>Kami berkomitmen merespons pertanyaan privasi dalam 3 hari kerja.</p>

  <div class="footer-nav">
    <a href="/landing">← Beranda</a>
    <a href="/tos">Syarat &amp; Ketentuan</a>
    <a href="/login">Login</a>
    <a href="/register">Daftar Gratis</a>
  </div>
</div>
</body>
</html>

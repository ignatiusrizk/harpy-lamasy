<?php
// tos.php — Syarat & Ketentuan LAMASY (publik, tanpa login)
$tosVersion = '1.1';
$lastUpdated = '23 Juni 2026';
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="description" content="Syarat & Ketentuan penggunaan platform LAMASY — ERP Laundry modern dengan AI terintegrasi.">
  <meta name="robots" content="index, follow">
  <link rel="canonical" href="https://lamasy.harpy.id/tos">
  <link rel="icon" type="image/png" href="/assets/icon-192.png?v=<?= @filemtime(__DIR__.'/assets/icon-192.png') ?: '3' ?>">
  <link rel="apple-touch-icon" href="/assets/apple-touch-icon-180.png?v=<?= @filemtime(__DIR__.'/assets/apple-touch-icon-180.png') ?: '3' ?>">
  <meta name="theme-color" content="#0F1C3A">
  <meta property="og:type" content="article">
  <meta property="og:title" content="Syarat & Ketentuan — LAMASY">
  <meta property="og:description" content="Syarat & ketentuan penggunaan platform LAMASY">
  <meta property="og:url" content="https://lamasy.harpy.id/tos">
  <title>Syarat &amp; Ketentuan — LAMASY</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    :root {
      --navy-d: #0F1C3A;
      --navy-c: #0B1630;
      --navy-m: #162348;
      --teal:   #35E8D5;
      --teal-d: #1BC4B3;
      --white:  #F8FAFF;
      --font:   'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
    }
    html { scroll-behavior: smooth; }
    body {
      font-family: var(--font);
      background: var(--navy-d);
      color: rgba(255,255,255,.8);
      line-height: 1.7;
      min-height: 100vh;
      background-image:
        radial-gradient(circle at 15% 5%, rgba(53,232,213,.06), transparent 40%),
        radial-gradient(circle at 85% 15%, rgba(99,102,241,.05), transparent 35%);
      background-attachment: fixed;
    }

    /* Top nav */
    .topnav {
      position: sticky; top: 0; z-index: 50;
      background: rgba(11,22,48,.85);
      backdrop-filter: blur(12px);
      border-bottom: 1px solid rgba(255,255,255,.06);
      padding: 14px 24px;
    }
    .topnav-inner {
      max-width: 1200px; margin: 0 auto;
      display: flex; align-items: center; justify-content: space-between;
    }
    .topnav-brand {
      display: flex; align-items: center; gap: 10px;
      text-decoration: none; color: var(--white);
      font-weight: 800; font-size: 16px; letter-spacing: -.01em;
    }
    .topnav-brand img { border-radius: 50%; }
    .topnav-brand .sub { font-size: 11px; font-weight: 500; color: rgba(255,255,255,.4); margin-left: 4px; }
    .topnav-back {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 8px 14px;
      background: rgba(53,232,213,.08);
      border: 1px solid rgba(53,232,213,.2);
      border-radius: 8px;
      color: var(--teal); font-size: 13px; font-weight: 600;
      text-decoration: none;
      transition: all .15s;
    }
    .topnav-back:hover { background: rgba(53,232,213,.15); }

    /* Layout */
    .wrap {
      max-width: 1200px; margin: 0 auto;
      padding: 48px 24px 80px;
      display: grid; grid-template-columns: 220px 1fr;
      gap: 48px;
    }

    /* TOC sidebar */
    .toc {
      position: sticky; top: 90px;
      align-self: start;
      max-height: calc(100vh - 110px);
      overflow-y: auto;
      padding-right: 8px;
    }
    .toc-title {
      font-size: 11px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase;
      color: rgba(255,255,255,.35);
      margin-bottom: 14px;
    }
    .toc ol {
      list-style: none; counter-reset: tocsection;
      display: flex; flex-direction: column; gap: 4px;
    }
    .toc ol li { counter-increment: tocsection; }
    .toc a {
      display: block;
      padding: 7px 12px;
      font-size: 13px;
      color: rgba(255,255,255,.55);
      text-decoration: none;
      border-radius: 6px;
      border-left: 2px solid transparent;
      transition: all .15s;
      line-height: 1.4;
    }
    .toc a:hover { color: var(--teal); background: rgba(53,232,213,.05); }
    .toc a::before { content: counter(tocsection) ". "; color: rgba(255,255,255,.3); font-weight: 700; }

    /* Content */
    .content { max-width: 760px; }
    .doc-meta {
      display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: center;
      padding: 12px 16px;
      background: rgba(53,232,213,.05);
      border: 1px solid rgba(53,232,213,.15);
      border-radius: 10px;
      font-size: 12.5px;
      margin-bottom: 32px;
    }
    .doc-meta .label { color: rgba(255,255,255,.5); }
    .doc-meta .val { color: var(--white); font-weight: 600; }
    .doc-meta a { color: var(--teal); text-decoration: none; font-weight: 600; }
    .doc-meta a:hover { text-decoration: underline; }

    h1 {
      font-size: clamp(28px, 4vw, 38px);
      font-weight: 800;
      color: var(--white);
      letter-spacing: -.02em;
      line-height: 1.15;
      margin-bottom: 16px;
    }
    .intro {
      font-size: 15px;
      color: rgba(255,255,255,.7);
      margin-bottom: 32px;
      padding-bottom: 32px;
      border-bottom: 1px solid rgba(255,255,255,.08);
    }

    section { counter-increment: section; }
    body { counter-reset: section; }
    h2 {
      font-size: 19px; font-weight: 700;
      color: var(--white);
      margin: 40px 0 14px;
      scroll-margin-top: 90px;
      display: flex; align-items: baseline; gap: 12px;
    }
    h2::before {
      content: counter(section);
      color: var(--teal);
      font-size: 14px;
      font-weight: 800;
      padding: 4px 9px;
      background: rgba(53,232,213,.1);
      border-radius: 6px;
      flex-shrink: 0;
    }
    p {
      margin-bottom: 12px;
      font-size: 14.5px;
      color: rgba(255,255,255,.7);
    }
    ul {
      margin: 8px 0 14px 0; padding-left: 0;
      list-style: none;
      font-size: 14.5px;
    }
    ul li {
      position: relative;
      padding-left: 24px;
      margin-bottom: 8px;
      color: rgba(255,255,255,.7);
    }
    ul li::before {
      content: "›";
      position: absolute; left: 4px; top: 0;
      color: var(--teal); font-weight: 700;
    }
    strong { color: var(--white); font-weight: 700; }
    a { color: var(--teal); }
    a:hover { color: var(--teal-d); }

    .highlight {
      background: rgba(53,232,213,.06);
      border: 1px solid rgba(53,232,213,.22);
      border-left: 3px solid var(--teal);
      padding: 14px 18px;
      border-radius: 10px;
      margin: 18px 0;
      font-size: 13.5px;
      color: rgba(255,255,255,.85);
    }

    .footer-doc {
      max-width: 760px;
      margin: 56px 0 0;
      padding: 28px;
      background: linear-gradient(135deg, rgba(53,232,213,.04), rgba(99,102,241,.04));
      border: 1px solid rgba(53,232,213,.15);
      border-radius: 16px;
      text-align: center;
    }
    .footer-doc h3 {
      color: var(--white); font-size: 16px; font-weight: 700; margin-bottom: 10px;
    }
    .footer-doc p { font-size: 13.5px; margin-bottom: 16px; }
    .footer-doc-btns {
      display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;
    }
    .btn-doc {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 10px 18px;
      border-radius: 9px;
      font-size: 13.5px; font-weight: 600;
      text-decoration: none;
      transition: all .15s;
    }
    .btn-doc-primary { background: var(--teal); color: var(--navy-d); }
    .btn-doc-primary:hover { opacity: .9; transform: translateY(-1px); }
    .btn-doc-secondary { background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.12); color: var(--white); }
    .btn-doc-secondary:hover { background: rgba(255,255,255,.1); color: var(--teal); }

    /* Mobile */
    @media (max-width: 900px) {
      .wrap { grid-template-columns: 1fr; gap: 24px; padding: 32px 20px 60px; }
      .toc {
        position: static;
        max-height: none;
        padding-right: 0;
      }
      .toc-collapse {
        display: block;
        cursor: pointer;
        padding: 12px 16px;
        background: rgba(255,255,255,.04);
        border: 1px solid rgba(255,255,255,.08);
        border-radius: 10px;
        list-style: none;
        font-weight: 700;
        color: var(--white);
      }
      .toc-collapse::-webkit-details-marker { display: none; }
      .toc-collapse::after { content: '▾'; float: right; color: var(--teal); }
      details[open] .toc-collapse::after { content: '▴'; }
      .toc ol { padding: 12px 0 4px; }
      .topnav-brand .sub { display: none; }
    }
  </style>
</head>
<body>

<!-- Top nav -->
<nav class="topnav">
  <div class="topnav-inner">
    <a href="/" class="topnav-brand">
      <img src="/assets/logo.png?v=<?= @filemtime(__DIR__.'/assets/logo.png') ?: '3' ?>" alt="LAMASY" width="32" height="32">
      LAMASY <span class="sub">by Harpy</span>
    </a>
    <a href="/" class="topnav-back">← Beranda</a>
  </div>
</nav>

<div class="wrap">
  <!-- TOC sidebar -->
  <aside class="toc">
    <details open>
      <summary class="toc-collapse">📋 Daftar Isi</summary>
      <div class="toc-title" style="margin-top:14px;">Bagian</div>
      <ol>
        <li><a href="#definisi">Definisi &amp; Pihak Terlibat</a></li>
        <li><a href="#layanan">Layanan Yang Disediakan</a></li>
        <li><a href="#kewajiban">Kewajiban Pengguna</a></li>
        <li><a href="#pembayaran">Pembayaran &amp; Coin System</a></li>
        <li><a href="#data">Data &amp; Privasi</a></li>
        <li><a href="#tanggung-jawab">Pembatasan Tanggung Jawab</a></li>
        <li><a href="#penghentian">Penghentian Layanan</a></li>
        <li><a href="#perubahan">Perubahan Ketentuan</a></li>
        <li><a href="#hukum">Hukum Yang Berlaku</a></li>
        <li><a href="#kontak">Kontak</a></li>
      </ol>
    </details>
  </aside>

  <!-- Content -->
  <main class="content">
    <h1>Syarat &amp; Ketentuan Penggunaan</h1>
    <div class="doc-meta">
      <span><span class="label">Versi</span> <span class="val"><?= htmlspecialchars($tosVersion) ?></span></span>
      <span>·</span>
      <span><span class="label">Diperbarui</span> <span class="val"><?= htmlspecialchars($lastUpdated) ?></span></span>
      <span>·</span>
      <a href="/privacy">Lihat Kebijakan Privasi →</a>
    </div>

    <p class="intro">Dengan mendaftar dan menggunakan layanan LAMASY (<strong>"Platform"</strong>), Anda (<strong>"Pengguna"</strong> atau <strong>"Tenant"</strong>) menyatakan telah membaca, memahami, dan menyetujui seluruh ketentuan di bawah ini. Jika Anda tidak menyetujui ketentuan ini, harap tidak menggunakan layanan kami.</p>

    <section>
      <h2 id="definisi">Definisi &amp; Pihak Yang Terlibat</h2>
      <ul>
        <li><strong>LAMASY / Platform</strong> — Sistem manajemen laundry berbasis SaaS yang dikelola oleh PT Harpy Sinergi Mandiri.</li>
        <li><strong>Tenant</strong> — Pemilik usaha laundry yang mendaftar dan menggunakan Platform.</li>
        <li><strong>Outlet</strong> — Gerai laundry milik Tenant yang terdaftar di Platform.</li>
        <li><strong>Karyawan</strong> — Staf Tenant yang diberikan akses ke Platform oleh Tenant.</li>
        <li><strong>Pelanggan Akhir</strong> — Konsumen yang menggunakan jasa laundry milik Tenant.</li>
        <li><strong>Coin</strong> — Satuan kredit digital di dalam Platform yang digunakan untuk mengakses fitur berbayar.</li>
      </ul>
    </section>

    <section>
      <h2 id="layanan">Layanan Yang Disediakan</h2>
      <p>LAMASY menyediakan platform manajemen laundry yang mencakup:</p>
      <ul>
        <li>Sistem kasir (POS) dan manajemen order laundry</li>
        <li>Manajemen karyawan, absensi, dan penggajian</li>
        <li>Manajemen pelanggan dan program loyalitas</li>
        <li>Notifikasi WhatsApp otomatis kepada pelanggan</li>
        <li>Laporan bisnis dan analitik (termasuk SAK EMKM)</li>
        <li>Fitur AI untuk insight bisnis (menggunakan API Claude dari Anthropic)</li>
        <li>Manajemen multi-outlet, drop point, dan antar-jemput</li>
        <li>Mesin self-service (booking via QR untuk pelanggan akhir)</li>
      </ul>
      <p>Platform disediakan dalam model berlangganan berbasis <strong>Coin</strong>. Fitur tertentu membutuhkan saldo Coin aktif.</p>
    </section>

    <section>
      <h2 id="kewajiban">Kewajiban Pengguna</h2>
      <p>Sebagai Tenant, Anda bertanggung jawab untuk:</p>
      <ul>
        <li>Memberikan informasi yang akurat dan terkini saat mendaftar</li>
        <li>Menjaga kerahasiaan kredensial akun (username dan password)</li>
        <li>Bertanggung jawab atas seluruh aktivitas yang dilakukan di bawah akun Anda</li>
        <li>Tidak menggunakan Platform untuk aktivitas ilegal, penipuan, atau yang merugikan pihak lain</li>
        <li>Memastikan data pelanggan akhir yang dimasukkan ke Platform diperoleh secara sah</li>
        <li>Melaporkan potensi kebocoran keamanan kepada tim LAMASY segera setelah diketahui</li>
      </ul>
      <div class="highlight">
        ⚠️ Penggunaan Platform untuk kegiatan ilegal, spam, atau pelanggaran hukum Indonesia dapat menyebabkan pemutusan akun seketika tanpa pengembalian dana.
      </div>
    </section>

    <section>
      <h2 id="pembayaran">Pembayaran &amp; Coin System</h2>
      <ul>
        <li>Coin dibeli melalui mekanisme pembayaran yang tersedia di Platform</li>
        <li>Coin tidak memiliki masa kedaluwarsa selama akun aktif</li>
        <li>Coin bersifat non-refundable kecuali terjadi kesalahan teknis dari pihak LAMASY</li>
        <li>Harga per Coin dan biaya fitur dapat berubah dengan pemberitahuan minimal 14 hari sebelumnya</li>
        <li>Selama periode trial, Tenant mendapatkan Coin gratis dengan jumlah yang ditentukan oleh LAMASY</li>
        <li>Akun yang kehabisan saldo Coin akan masuk ke mode terbatas (grace period) sesuai ketentuan yang berlaku</li>
      </ul>
    </section>

    <section>
      <h2 id="data">Data &amp; Privasi</h2>
      <p>LAMASY berkomitmen melindungi data Tenant dan pelanggan akhir. Detail lengkap mengenai pengelolaan data tercantum dalam <a href="/privacy">Kebijakan Privasi</a> kami.</p>
      <ul>
        <li>Data operasional laundry Anda disimpan di server dengan infrastruktur Hostinger</li>
        <li>LAMASY tidak menjual data Tenant kepada pihak ketiga</li>
        <li>Beberapa fitur menggunakan layanan pihak ketiga (Anthropic untuk AI, WhatsApp Business API via Fonnte) yang tunduk pada kebijakan privasi masing-masing penyedia</li>
        <li>Tenant bertanggung jawab atas legalitas pengumpulan dan penggunaan data pelanggan akhir mereka</li>
        <li>Data Tenant <strong>terisolasi per akun</strong> (multi-tenant architecture) — hanya Tenant terkait yang bisa mengakses datanya</li>
      </ul>
    </section>

    <section>
      <h2 id="tanggung-jawab">Pembatasan Tanggung Jawab</h2>
      <p>LAMASY tidak bertanggung jawab atas:</p>
      <ul>
        <li>Kerugian bisnis akibat gangguan layanan yang disebabkan oleh faktor di luar kendali LAMASY (force majeure, gangguan infrastruktur pihak ketiga)</li>
        <li>Kesalahan penggunaan Platform oleh Tenant atau karyawannya</li>
        <li>Kegagalan notifikasi WhatsApp yang disebabkan oleh perubahan kebijakan Meta/WhatsApp atau penyedia gateway</li>
        <li>Kehilangan data akibat tindakan Tenant sendiri (misalnya delete order/customer secara manual)</li>
      </ul>
      <p>Tanggung jawab maksimal LAMASY terbatas pada nilai Coin yang dimiliki Tenant saat kejadian berlangsung.</p>
    </section>

    <section>
      <h2 id="penghentian">Penghentian Layanan</h2>
      <ul>
        <li>Tenant dapat menghentikan penggunaan Platform kapan saja melalui menu Settings</li>
        <li>LAMASY berhak menangguhkan atau menghentikan akun yang melanggar ketentuan ini tanpa pemberitahuan sebelumnya</li>
        <li>Data Tenant akan disimpan selama <strong>90 hari</strong> setelah penghentian akun, kemudian dihapus secara permanen</li>
        <li>Coin yang tersisa saat penghentian akun oleh Tenant tidak dapat dikembalikan</li>
        <li>Tenant dapat melakukan ekspor data secara penuh sebelum penghentian melalui menu HQ → Export Data</li>
      </ul>
    </section>

    <section>
      <h2 id="perubahan">Perubahan Ketentuan</h2>
      <p>LAMASY berhak mengubah Syarat &amp; Ketentuan ini sewaktu-waktu. Perubahan signifikan akan diberitahukan melalui:</p>
      <ul>
        <li>Notifikasi di dalam Platform saat Tenant login</li>
        <li>Email ke alamat terdaftar (jika fitur email aktif)</li>
        <li>Pesan WhatsApp (jika tersedia)</li>
      </ul>
      <p>Penggunaan Platform setelah pemberitahuan dianggap sebagai persetujuan terhadap ketentuan yang diperbarui.</p>
    </section>

    <section>
      <h2 id="hukum">Hukum Yang Berlaku</h2>
      <p>Syarat &amp; Ketentuan ini tunduk pada hukum yang berlaku di Republik Indonesia, khususnya:</p>
      <ul>
        <li>UU No. 8 Tahun 1999 tentang Perlindungan Konsumen</li>
        <li>UU No. 11 Tahun 2008 jo. UU No. 19 Tahun 2016 tentang Informasi dan Transaksi Elektronik (ITE)</li>
        <li>UU No. 27 Tahun 2022 tentang Pelindungan Data Pribadi</li>
      </ul>
      <p>Segala sengketa yang timbul akan diselesaikan melalui mediasi terlebih dahulu, dan jika tidak tercapai kesepakatan, akan diselesaikan melalui pengadilan yang berwenang di Indonesia.</p>
    </section>

    <section>
      <h2 id="kontak">Kontak</h2>
      <p>Untuk pertanyaan terkait Syarat &amp; Ketentuan ini, hubungi kami:</p>
      <ul>
        <li><strong>WhatsApp:</strong> <a href="https://wa.me/6285121519302">+62 851-2151-9302</a></li>
        <li><strong>Email:</strong> <a href="mailto:harpy@harpy.id">harpy@harpy.id</a></li>
        <li><strong>Tiket Support:</strong> Melalui fitur Support &amp; Tiket di dalam Platform</li>
      </ul>
    </section>

    <!-- Bottom CTA -->
    <div class="footer-doc">
      <h3>Siap mulai gunakan LAMASY?</h3>
      <p>Trial 7 hari gratis · Tanpa kartu kredit · Cancel anytime</p>
      <div class="footer-doc-btns">
        <a href="/register.php" class="btn-doc btn-doc-primary">🚀 Daftar Gratis</a>
        <a href="/privacy" class="btn-doc btn-doc-secondary">Kebijakan Privasi →</a>
      </div>
    </div>
  </main>
</div>

</body>
</html>

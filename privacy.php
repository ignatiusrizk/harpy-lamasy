<?php
// privacy.php — Kebijakan Privasi LAMASY (publik, tanpa login)
$lastUpdated = '23 Juni 2026';
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="description" content="Kebijakan Privasi LAMASY — bagaimana kami mengumpulkan, menggunakan, dan melindungi data Anda.">
  <meta name="robots" content="index, follow">
  <link rel="canonical" href="https://lamasy.harpy.id/privacy">
  <link rel="icon" type="image/png" href="/assets/icon-192.png?v=<?= @filemtime(__DIR__.'/assets/icon-192.png') ?: '3' ?>">
  <link rel="apple-touch-icon" href="/assets/apple-touch-icon-180.png?v=<?= @filemtime(__DIR__.'/assets/apple-touch-icon-180.png') ?: '3' ?>">
  <meta name="theme-color" content="#0F1C3A">
  <meta property="og:type" content="article">
  <meta property="og:title" content="Kebijakan Privasi — LAMASY">
  <meta property="og:description" content="Kebijakan privasi pengelolaan data LAMASY">
  <meta property="og:url" content="https://lamasy.harpy.id/privacy">
  <title>Kebijakan Privasi — LAMASY</title>
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
        radial-gradient(circle at 85% 5%, rgba(53,232,213,.06), transparent 40%),
        radial-gradient(circle at 15% 15%, rgba(99,102,241,.05), transparent 35%);
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

    /* Table */
    .table-wrap { overflow-x: auto; margin: 18px 0; border-radius: 10px; border: 1px solid rgba(255,255,255,.08); }
    table {
      width: 100%; border-collapse: collapse;
      font-size: 13px;
    }
    th {
      background: rgba(53,232,213,.08);
      color: var(--white);
      padding: 12px 14px;
      text-align: left;
      font-weight: 700;
      font-size: 12px;
      letter-spacing: .04em;
      border-bottom: 1px solid rgba(53,232,213,.2);
    }
    td {
      padding: 12px 14px;
      border-bottom: 1px solid rgba(255,255,255,.05);
      color: rgba(255,255,255,.7);
      vertical-align: top;
    }
    tr:last-child td { border-bottom: none; }
    tr:nth-child(even) td { background: rgba(255,255,255,.02); }

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
  <aside class="toc">
    <details open>
      <summary class="toc-collapse">📋 Daftar Isi</summary>
      <div class="toc-title" style="margin-top:14px;">Bagian</div>
      <ol>
        <li><a href="#data-dikumpulkan">Data Yang Kami Kumpulkan</a></li>
        <li><a href="#penggunaan">Bagaimana Data Digunakan</a></li>
        <li><a href="#keamanan">Penyimpanan &amp; Keamanan</a></li>
        <li><a href="#pihak-ketiga">Berbagi Data Pihak Ketiga</a></li>
        <li><a href="#retensi">Retensi Data</a></li>
        <li><a href="#hak-pengguna">Hak Pengguna</a></li>
        <li><a href="#cookie">Cookie &amp; Tracking</a></li>
        <li><a href="#kontak">Kontak Privasi</a></li>
      </ol>
    </details>
  </aside>

  <main class="content">
    <h1>Kebijakan Privasi</h1>
    <div class="doc-meta">
      <span><span class="label">Diperbarui</span> <span class="val"><?= htmlspecialchars($lastUpdated) ?></span></span>
      <span>·</span>
      <span><span class="label">Tunduk pada</span> <span class="val">UU PDP No. 27/2022</span></span>
      <span>·</span>
      <a href="/tos">Lihat Syarat &amp; Ketentuan →</a>
    </div>

    <p class="intro">Kebijakan Privasi ini menjelaskan bagaimana LAMASY (dikelola oleh <strong>PT Harpy Sinergi Mandiri</strong>) mengumpulkan, menggunakan, dan melindungi informasi Anda saat menggunakan Platform kami. Kebijakan ini tunduk pada UU No. 27 Tahun 2022 tentang Pelindungan Data Pribadi.</p>

    <section>
      <h2 id="data-dikumpulkan">Data Yang Kami Kumpulkan</h2>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Kategori Data</th><th>Contoh</th><th>Sumber</th></tr>
          </thead>
          <tbody>
            <tr>
              <td><strong>Data Akun Tenant</strong></td>
              <td>Nama, nomor WhatsApp, nama perusahaan, alamat outlet</td>
              <td>Diisi Tenant saat registrasi</td>
            </tr>
            <tr>
              <td><strong>Data Operasional</strong></td>
              <td>Order laundry, data pelanggan akhir, data karyawan, laporan kas</td>
              <td>Dimasukkan Tenant &amp; karyawan saat operasional</td>
            </tr>
            <tr>
              <td><strong>Data Penggunaan</strong></td>
              <td>Log aktivitas, fitur yang digunakan, waktu login</td>
              <td>Dikumpulkan otomatis oleh sistem</td>
            </tr>
            <tr>
              <td><strong>Data Teknis</strong></td>
              <td>Alamat IP, jenis browser, perangkat</td>
              <td>Dikumpulkan otomatis untuk keamanan</td>
            </tr>
            <tr>
              <td><strong>Data Pembayaran</strong></td>
              <td>Nominal transfer, nama pengirim, tanggal pembayaran Coin</td>
              <td>Dimasukkan saat pembelian Coin</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="highlight">
        🔒 LAMASY <strong>tidak menyimpan</strong> data kartu kredit, nomor rekening bank, atau informasi pembayaran sensitif Tenant. Pembayaran dilakukan via transfer bank manual atau payment gateway pihak ketiga.
      </div>
    </section>

    <section>
      <h2 id="penggunaan">Bagaimana Data Digunakan</h2>
      <ul>
        <li>Menjalankan dan meningkatkan layanan Platform</li>
        <li>Memproses dan mengirim notifikasi WhatsApp kepada pelanggan akhir Tenant</li>
        <li>Menghasilkan laporan dan analitik bisnis untuk Tenant</li>
        <li>Mendeteksi dan mencegah penipuan serta penyalahgunaan</li>
        <li>Memberikan dukungan teknis dan layanan pelanggan</li>
        <li>Mengirim pembaruan penting terkait layanan (bukan pemasaran)</li>
        <li><strong>Fitur AI:</strong> data operasional dikirim ke API Claude (Anthropic) untuk analisis — data <strong>tidak disimpan</strong> oleh Anthropic untuk training model</li>
      </ul>
    </section>

    <section>
      <h2 id="keamanan">Penyimpanan &amp; Keamanan Data</h2>
      <ul>
        <li>Data disimpan di infrastruktur Hostinger dengan enkripsi standar industri (TLS 1.3 at-transit)</li>
        <li>Akses ke database dibatasi hanya untuk tim teknis LAMASY dengan autentikasi berlapis</li>
        <li>Password disimpan dalam bentuk hash <strong>bcrypt</strong> — tidak dapat dibaca bahkan oleh tim LAMASY</li>
        <li>Seluruh komunikasi antara browser dan server menggunakan protokol HTTPS</li>
        <li>Log aktivitas sensitif diaudit secara berkala (audit log lengkap)</li>
        <li>Backup data dilakukan secara otomatis setiap hari oleh penyedia hosting</li>
        <li>Arsitektur <strong>multi-tenant isolated</strong> — query setiap Tenant otomatis di-scope ke tenant_id, mencegah cross-tenant data leak</li>
      </ul>
    </section>

    <section>
      <h2 id="pihak-ketiga">Berbagi Data Dengan Pihak Ketiga</h2>
      <p>LAMASY hanya berbagi data dengan pihak ketiga dalam kondisi berikut:</p>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Pihak Ketiga</th><th>Data Yang Dibagikan</th><th>Tujuan</th></tr>
          </thead>
          <tbody>
            <tr>
              <td><strong>Anthropic (Claude API)</strong></td>
              <td>Data operasional yang relevan (tanpa nama lengkap atau ID unik)</td>
              <td>Analisis AI &amp; insight bisnis</td>
            </tr>
            <tr>
              <td><strong>Fonnte / WhatsApp Business API</strong></td>
              <td>Nomor WA pelanggan, isi pesan notifikasi</td>
              <td>Pengiriman notifikasi otomatis</td>
            </tr>
            <tr>
              <td><strong>Hostinger</strong></td>
              <td>Data tersimpan di infrastruktur mereka (terenkripsi at-rest)</td>
              <td>Operasional server</td>
            </tr>
            <tr>
              <td><strong>Cloudflare</strong></td>
              <td>Alamat IP, user-agent, metadata request</td>
              <td>CDN, security (DDoS protection), analytics aggregated</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p>LAMASY <strong>tidak menjual</strong> data Tenant atau pelanggan akhir kepada pihak manapun untuk tujuan pemasaran.</p>
    </section>

    <section>
      <h2 id="retensi">Retensi Data</h2>
      <ul>
        <li><strong>Selama akun aktif:</strong> semua data operasional disimpan penuh</li>
        <li><strong>Setelah akun dinonaktifkan:</strong> data dipertahankan selama 90 hari untuk keperluan pemulihan</li>
        <li><strong>Setelah 90 hari:</strong> data dihapus secara permanen dari server produksi</li>
        <li><strong>Log audit keamanan:</strong> disimpan selama 1 tahun untuk keperluan investigasi</li>
        <li>Tenant dapat meminta penghapusan data lebih awal melalui Tiket Support</li>
        <li>Tenant dapat melakukan export data sendiri kapan saja via menu HQ → Export Data</li>
      </ul>
    </section>

    <section>
      <h2 id="hak-pengguna">Hak Pengguna (sesuai UU PDP)</h2>
      <p>Berdasarkan UU No. 27 Tahun 2022, Anda memiliki hak untuk:</p>
      <ul>
        <li><strong>Akses</strong> — Meminta salinan data yang kami simpan tentang Anda</li>
        <li><strong>Koreksi</strong> — Memperbarui data yang tidak akurat melalui menu Settings</li>
        <li><strong>Penghapusan</strong> — Meminta penghapusan akun dan data Anda (right to be forgotten)</li>
        <li><strong>Portabilitas</strong> — Meminta ekspor data dalam format yang dapat dibaca mesin (CSV/JSON)</li>
        <li><strong>Keberatan</strong> — Menolak pemrosesan data untuk tujuan tertentu</li>
        <li><strong>Penarikan Persetujuan</strong> — Mencabut persetujuan kapan saja</li>
      </ul>
      <p>Untuk menggunakan hak-hak ini, hubungi kami melalui <a href="https://wa.me/6285121519302">WhatsApp</a> atau email <a href="mailto:halo@harpy.id">halo@harpy.id</a>. Kami akan merespons dalam waktu maksimal 3 hari kerja.</p>
    </section>

    <section>
      <h2 id="cookie">Cookie &amp; Tracking</h2>
      <ul>
        <li>LAMASY menggunakan cookie sesi (session cookie) untuk autentikasi — dihapus saat browser ditutup atau setelah 12 jam</li>
        <li>Kami <strong>tidak menggunakan</strong> cookie iklan atau pixel tracking pihak ketiga</li>
        <li>Cloudflare Web Analytics dipakai untuk statistik visitor anonymous — <strong>tanpa cookie</strong>, tanpa fingerprinting</li>
        <li>Log akses server mencatat alamat IP dan user-agent untuk keamanan — tidak digunakan untuk profiling</li>
        <li>Service Worker (PWA) menyimpan cache asset statis di browser — bisa di-clear via uninstall PWA</li>
      </ul>
    </section>

    <section>
      <h2 id="kontak">Kontak untuk Pertanyaan Privasi</h2>
      <p>Jika Anda memiliki pertanyaan, kekhawatiran, atau ingin menggunakan hak Anda terkait privasi data:</p>
      <ul>
        <li><strong>WhatsApp:</strong> <a href="https://wa.me/6285121519302">+62 851-2151-9302</a></li>
        <li><strong>Email:</strong> <a href="mailto:halo@harpy.id">halo@harpy.id</a></li>
        <li><strong>Tiket Support:</strong> Melalui fitur Support &amp; Tiket di dalam Platform</li>
      </ul>
      <p>Kami berkomitmen merespons pertanyaan privasi dalam <strong>3 hari kerja</strong>.</p>
    </section>

    <div class="footer-doc">
      <h3>Punya pertanyaan tentang data Anda?</h3>
      <p>Tim LAMASY responsif via WhatsApp — bukan chatbot, founder responsif langsung.</p>
      <div class="footer-doc-btns">
        <a href="https://wa.me/6285121519302?text=Halo+LAMASY%2C+saya+mau+tanya+tentang+kebijakan+privasi" target="_blank" rel="noopener" class="btn-doc btn-doc-primary">💬 Tanya via WhatsApp</a>
        <a href="/tos" class="btn-doc btn-doc-secondary">Syarat &amp; Ketentuan →</a>
      </div>
    </div>
  </main>
</div>

</body>
</html>

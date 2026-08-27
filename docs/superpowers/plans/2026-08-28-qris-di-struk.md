# QRIS & Rekening di Struk — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tampilkan QRIS outlet atau nomor rekening di struk (cetak thermal,
modal POS, halaman lacak pesanan, pesan WA nota) saat order belum lunas dan
metode bayarnya cocok (QRIS→gambar QRIS, Transfer→rekening), plus alat crop
manual untuk gambar QRIS supaya tidak kekecilan saat dicetak.

**Architecture:** Satu fungsi murni baru `StrukGenerator::paymentAidFor()`
jadi satu-satunya sumber kebenaran untuk kondisi "kapan & apa yang
ditampilkan" — dikonsumsi oleh 3 titik render (struk cetak thermal, halaman
lacak pesanan, pesan WA). Toggle on/off & isian rekening ditambahkan ke
sistem Kustomisasi Struk yang sudah ada (`hl_struk_template`). Crop QRIS
dikerjakan manual di browser pakai Canvas API (tanpa library eksternal),
menggantikan file asli sebelum ke-upload — bukan auto-detect (lihat spec
untuk alasan).

**Tech Stack:** PHP procedural existing (tidak ada framework/ORM), MySQL
langsung, vanilla JS (tanpa build step, tanpa library eksternal), test
manual via `php tests/**/test_*.php` (pola `tests/_assert.php` yang sudah
ada di repo, bukan PHPUnit).

## Global Constraints

- Spec lengkap: `docs/superpowers/specs/2026-08-28-qris-di-struk-design.md`
  — WAJIB dibaca dulu sebelum mengerjakan task manapun di bawah.
- QRIS gambar statis (bukan gateway) — nominal TIDAK ter-encode di kode QR,
  selalu tulis "Sisa Bayar: Rp X" eksplisit di sebelah gambar/info rekening.
- Kondisi tampil (BERLAKU DI SEMUA CHANNEL, jangan didekati beda-beda per
  channel): `status_bayar IN ('belum_bayar','dp')` DAN `sisa_bayar > 0` DAN
  `metode_bayar` cocok (`qris`→QRIS, `transfer`→Rekening) DAN toggle terkait
  ON DAN data sumbernya ada (outlet punya `qris_image` / template punya
  `rekening_bank`+`rekening_nomor`).
- TIDAK ADA integrasi payment gateway otomatis (Midtrans/Tripay) — di luar
  scope, sudah sengaja di-hold sebelumnya.
- TIDAK ADA auto-crop/auto-deteksi posisi QR di gambar twibon — crop QRIS
  WAJIB manual (drag kotak) di browser, karena server cuma punya GD (bukan
  Imagick) dan salah crop otomatis bisa bikin QRIS gagal di-scan.
- Invoice PDF B2B (`StrukGenerator::renderPdf`, format a4/a5) TIDAK disentuh
  di plan ini — scope-nya struk retail thermal + channel digital customer
  (track.php, WA).
- Field `rekening_bank`/`rekening_nomor`/`rekening_atas_nama`/`show_rekening`
  SUDAH ADA di `hl_struk_template` (dipakai invoice B2B) — jangan bikin
  kolom baru untuk itu, cukup dibuka aksesnya untuk tipe retail juga.

---

## File yang disentuh

- **Migrasi baru:** `migrations/2026-08-28-qris-di-struk.sql` — 1 kolom baru.
- **Modify:** `core/StrukGenerator.php` — fungsi baru `paymentAidFor()` +
  `waPaymentNudgeLine()`, plus edit `defaultTemplate()`, `saveTemplate()`,
  `renderThermal()`.
- **Modify:** `struk.php` — toggle baru + buka section rekening untuk tab
  retail.
- **Modify:** `track.php` — 2 query SELECT + blok render baru.
- **Modify:** `pos.php` — action `wa_nota` tambah 1 baris pesan kondisional.
- **Modify:** `payment-settings.php` — alat crop manual (HTML+CSS+JS) di
  form upload QRIS.
- **Test baru:** `tests/struk/test_payment_aid.php`.

---

### Task 1: Migrasi DB — kolom `show_qris`

**Files:**
- Create: `migrations/2026-08-28-qris-di-struk.sql`

**Interfaces:**
- Produces: kolom `hl_struk_template.show_qris` (TINYINT(1), default 1),
  dipakai oleh Task 2 dst.

- [ ] **Step 1: Tulis file migrasi**

```sql
-- migrations/2026-08-28-qris-di-struk.sql
-- Toggle "Tampilkan QRIS" di Kustomisasi Struk (retail).
-- Kolom show_rekening/rekening_bank/rekening_nomor/rekening_atas_nama
-- SUDAH ADA (dipakai invoice B2B) — tidak perlu migrasi utk itu.
ALTER TABLE hl_struk_template
  ADD COLUMN show_qris TINYINT(1) NULL DEFAULT 1 AFTER show_sisa_bayar;
```

- [ ] **Step 2: Jalankan migrasi**

Run: `mysql < migrations/2026-08-28-qris-di-struk.sql`

Expected: tidak ada output (sukses tanpa error).

- [ ] **Step 3: Verifikasi kolom masuk**

Run: `mysql -e "SHOW COLUMNS FROM hl_struk_template LIKE 'show_qris'"`

Expected:
```
Field       Type      Null  Key  Default  Extra
show_qris   tinyint(1) YES       1
```

- [ ] **Step 4: Commit**

```bash
git add migrations/2026-08-28-qris-di-struk.sql
git commit -m "db: tambah kolom show_qris di hl_struk_template"
```

---

### Task 2: `StrukGenerator::paymentAidFor()` + `waPaymentNudgeLine()` (core logic)

**Files:**
- Modify: `core/StrukGenerator.php`
- Test: `tests/struk/test_payment_aid.php`

**Interfaces:**
- Consumes: kolom `show_qris` dari Task 1.
- Produces:
  - `StrukGenerator::paymentAidFor(array $trx, array $tmpl, array $outlet): ?array`
    — return `null` kalau tidak ada alat bayar yang perlu ditampilkan, atau:
    - `['type'=>'qris', 'image'=>string, 'label'=>?string, 'sisa_bayar'=>float]`
    - `['type'=>'rekening', 'bank'=>string, 'nomor'=>string, 'atas_nama'=>?string, 'sisa_bayar'=>float]`
  - `StrukGenerator::waPaymentNudgeLine(?array $aid): string` — return `''`
    kalau `$aid` null, atau 1 baris kalimat + newline siap ditempel ke pesan
    WA. Dipakai Task 6.
  - Kedua fungsi ini dipakai Task 4 (renderThermal) dan Task 5 (track.php).

- [ ] **Step 1: Tulis test (akan gagal — fungsi belum ada)**

```php
<?php
// tests/struk/test_payment_aid.php
require __DIR__ . '/../_assert.php';
require dirname(__DIR__, 2) . '/core/StrukGenerator.php';

// ── Helper bikin data dasar ────────────────────────────
function baseTrx(array $over = []): array {
    return array_merge([
        'status_bayar' => 'belum_bayar',
        'sisa_bayar'   => 50000,
        'metode_bayar' => 'qris',
    ], $over);
}
function baseTmpl(array $over = []): array {
    return array_merge([
        'show_qris'          => 1,
        'show_rekening'      => 1,
        'rekening_bank'      => 'BCA',
        'rekening_nomor'     => '1234567890',
        'rekening_atas_nama' => 'Budi Laundry',
    ], $over);
}
function baseOutlet(array $over = []): array {
    return array_merge([
        'qris_image' => '/assets/outlet-qris/foo.png',
        'qris_label' => 'BCA - Budi Laundry',
    ], $over);
}

// ── Kasus: Lunas → tidak ada alat bayar ────────────────
$aid = StrukGenerator::paymentAidFor(baseTrx(['status_bayar' => 'lunas']), baseTmpl(), baseOutlet());
ok($aid === null, 'Lunas => null');

// ── Kasus: belum_bayar tapi sisa_bayar 0 → null ────────
$aid = StrukGenerator::paymentAidFor(baseTrx(['sisa_bayar' => 0]), baseTmpl(), baseOutlet());
ok($aid === null, 'sisa_bayar=0 => null walau status belum_bayar');

// ── Kasus: metode qris, semua syarat terpenuhi → qris ──
$aid = StrukGenerator::paymentAidFor(baseTrx(), baseTmpl(), baseOutlet());
ok($aid !== null && $aid['type'] === 'qris', 'metode qris lengkap => type qris');
eqv($aid['image'], '/assets/outlet-qris/foo.png', 'qris image benar');
eqv($aid['sisa_bayar'], 50000, 'sisa_bayar ikut kebawa (qris)');

// ── Kasus: metode qris tapi outlet belum upload qris_image → null ──
$aid = StrukGenerator::paymentAidFor(baseTrx(), baseTmpl(), baseOutlet(['qris_image' => null]));
ok($aid === null, 'metode qris tapi qris_image kosong => null');

// ── Kasus: metode qris tapi toggle show_qris OFF → null ────
$aid = StrukGenerator::paymentAidFor(baseTrx(), baseTmpl(['show_qris' => 0]), baseOutlet());
ok($aid === null, 'metode qris tapi show_qris=0 => null');

// ── Kasus: metode transfer, semua syarat terpenuhi → rekening ──
$aid = StrukGenerator::paymentAidFor(baseTrx(['metode_bayar' => 'transfer']), baseTmpl(), baseOutlet());
ok($aid !== null && $aid['type'] === 'rekening', 'metode transfer lengkap => type rekening');
eqv($aid['bank'], 'BCA', 'rekening bank benar');
eqv($aid['nomor'], '1234567890', 'rekening nomor benar');

// ── Kasus: metode transfer tapi rekening_nomor kosong di template → null ──
$aid = StrukGenerator::paymentAidFor(
    baseTrx(['metode_bayar' => 'transfer']),
    baseTmpl(['rekening_nomor' => '']),
    baseOutlet()
);
ok($aid === null, 'metode transfer tapi rekening_nomor kosong => null');

// ── Kasus: metode cash → null (tidak ada alat bayar digital relevan) ──
$aid = StrukGenerator::paymentAidFor(baseTrx(['metode_bayar' => 'cash']), baseTmpl(), baseOutlet());
ok($aid === null, 'metode cash => null');

// ── waPaymentNudgeLine() ────────────────────────────────
eqv(StrukGenerator::waPaymentNudgeLine(null), '', 'nudge kosong kalau aid null');
$qrisAid = ['type' => 'qris', 'image' => 'x', 'label' => null, 'sisa_bayar' => 1000];
ok(str_contains(StrukGenerator::waPaymentNudgeLine($qrisAid), 'QRIS'), 'nudge qris mention QRIS');
$rekAid = ['type' => 'rekening', 'bank' => 'BCA', 'nomor' => '111', 'atas_nama' => null, 'sisa_bayar' => 1000];
$nudge = StrukGenerator::waPaymentNudgeLine($rekAid);
ok(str_contains($nudge, 'BCA') && str_contains($nudge, '111'), 'nudge rekening mention bank+nomor');

echo "\nAll tests passed.\n";
```

- [ ] **Step 2: Buat direktori test & jalankan, pastikan GAGAL (fungsi belum ada)**

```bash
mkdir -p tests/struk
```

Run: `php tests/struk/test_payment_aid.php`

Expected: PHP Fatal error — `Call to undefined method StrukGenerator::paymentAidFor()`.

- [ ] **Step 3: Tambah `paymentAidFor()` dan `waPaymentNudgeLine()` di `core/StrukGenerator.php`**

Tempatkan 2 method baru ini tepat SETELAH `metodeBayarLabel()` (setelah baris
101 di kondisi saat ini, sebelum komentar `// ── Coin cost per tipe ──`):

```php
    /**
     * Tentukan alat bayar digital yang relevan ditampilkan utk 1 transaksi,
     * berdasarkan metode_bayar yang dipilih & status pelunasannya. Satu
     * sumber kebenaran dipakai oleh renderThermal(), track.php, & wa_nota
     * (pos.php) — supaya kondisinya tidak didekati beda-beda per channel.
     *
     * @param array $trx    Perlu: status_bayar, sisa_bayar, metode_bayar
     * @param array $tmpl   Hasil loadTemplate() — perlu: show_qris,
     *                      show_rekening, rekening_bank, rekening_nomor,
     *                      rekening_atas_nama
     * @param array $outlet Baris outlets — perlu: qris_image, qris_label
     * @return array{type:string}|null
     */
    public static function paymentAidFor(array $trx, array $tmpl, array $outlet): ?array
    {
        $statusBayar = $trx['status_bayar'] ?? '';
        $sisaBayar   = (float)($trx['sisa_bayar'] ?? 0);
        if (!in_array($statusBayar, ['belum_bayar', 'dp'], true) || $sisaBayar <= 0) {
            return null;
        }

        $metode = $trx['metode_bayar'] ?? '';

        if ($metode === 'qris' && !empty($tmpl['show_qris']) && !empty($outlet['qris_image'])) {
            return [
                'type'       => 'qris',
                'image'      => $outlet['qris_image'],
                'label'      => $outlet['qris_label'] ?? null,
                'sisa_bayar' => $sisaBayar,
            ];
        }

        if ($metode === 'transfer' && !empty($tmpl['show_rekening'])
            && !empty($tmpl['rekening_bank']) && !empty($tmpl['rekening_nomor'])) {
            return [
                'type'       => 'rekening',
                'bank'       => $tmpl['rekening_bank'],
                'nomor'      => $tmpl['rekening_nomor'],
                'atas_nama'  => $tmpl['rekening_atas_nama'] ?? null,
                'sisa_bayar' => $sisaBayar,
            ];
        }

        return null;
    }

    /**
     * 1 baris kalimat penunjuk pembayaran utk pesan WA (teks polos, tidak
     * bisa embed gambar) — kosong kalau $aid null.
     */
    public static function waPaymentNudgeLine(?array $aid): string
    {
        if (!$aid) return '';
        if ($aid['type'] === 'qris') {
            return "💳 Bayar via QRIS: buka link di atas\n";
        }
        if ($aid['type'] === 'rekening') {
            return "🏦 Transfer ke {$aid['bank']} {$aid['nomor']}: buka link di atas utk detail\n";
        }
        return '';
    }

```

- [ ] **Step 4: Jalankan test lagi, pastikan LULUS**

Run: `php tests/struk/test_payment_aid.php`

Expected: setiap baris `PASS: ...`, diakhiri `All tests passed.`, exit code 0.

- [ ] **Step 5: Tambah `show_qris` ke whitelist `saveTemplate()`**

Di `core/StrukGenerator.php`, method `saveTemplate()`, array `$allowed`
(sekitar baris 386-399) — tambah `'show_qris'` setelah `'show_sisa_bayar'`:

```php
        $allowed = [
            'format','show_logo','logo_url','logo_size','nama_outlet','tagline',
            'show_alamat','alamat_override','show_telp','show_email','header_extra',
            'show_no_order','show_tanggal','show_nama_kasir','show_nama_pelanggan',
            'show_telp_pelanggan','show_alamat_pelanggan','show_detail_item',
            'show_subtotal','show_diskon','show_dp','show_total','show_metode_bayar',
            'show_sisa_bayar','show_qris','show_estimasi','show_catatan',
            'show_poin_earned','show_saldo_poin',
            'show_periode_invoice','show_jatuh_tempo','show_rekening',
            'rekening_bank','rekening_nomor','rekening_atas_nama',
            'footer_ucapan','show_footer_ucapan','footer_syarat','show_footer_syarat',
            'footer_sosmed','show_footer_sosmed','show_qr_wa','footer_extra',
            'font_size','show_border','show_watermark',
        ];
```

- [ ] **Step 6: Tambah default `show_qris` di `defaultTemplate()`**

Di method `defaultTemplate()` (sekitar baris 1014-1067), tambah baris
setelah `'show_metode_bayar' => 1,` (sebelum `'show_sisa_bayar'`):

```php
            'show_metode_bayar'      => 1,
            'show_qris'              => $isB2b ? 0 : 1,
            'show_sisa_bayar'        => 1,
```

- [ ] **Step 7: Verifikasi manual defaultTemplate()**

Run:
```bash
php -r '
require "core/StrukGenerator.php";
$t = StrukGenerator::defaultTemplate("retail");
echo "retail show_qris: " . $t["show_qris"] . "\n";
$t = StrukGenerator::defaultTemplate("b2b");
echo "b2b show_qris: " . $t["show_qris"] . "\n";
'
```

Expected:
```
retail show_qris: 1
b2b show_qris: 0
```

- [ ] **Step 8: Commit**

```bash
git add core/StrukGenerator.php tests/struk/test_payment_aid.php
git commit -m "feat(struk): StrukGenerator::paymentAidFor() + waPaymentNudgeLine() — core logic QRIS/Rekening di struk"
```

---

### Task 3: Toggle & field rekening di Kustomisasi Struk (`struk.php`)

**Files:**
- Modify: `struk.php`

**Interfaces:**
- Consumes: `StrukGenerator::saveTemplate()`/`loadTemplate()` dari Task 2
  (whitelist sudah termasuk `show_qris`).
- Produces: owner bisa toggle `show_qris` & isi/toggle rekening dari tab
  retail — dikonsumsi Task 4/5/6 lewat `loadTemplate()`.

- [ ] **Step 1: Tambah `show_qris` ke whitelist PHP (server-side save)**

Di `struk.php`, action `save`, array `$bools` (sekitar baris 44-54) — tambah
`'show_qris'` setelah `'show_sisa_bayar'`:

```php
        $bools = [
            'show_logo','show_alamat','show_telp','show_email',
            'show_no_order','show_tanggal','show_nama_kasir','show_nama_pelanggan',
            'show_telp_pelanggan','show_alamat_pelanggan','show_detail_item',
            'show_subtotal','show_diskon','show_dp','show_total',
            'show_metode_bayar','show_sisa_bayar','show_qris','show_estimasi','show_catatan',
            'show_poin_earned','show_saldo_poin',
            'show_periode_invoice','show_jatuh_tempo','show_rekening',
            'show_footer_ucapan','show_footer_syarat','show_footer_sosmed',
            'show_qr_wa','show_border','show_watermark',
        ];
```

- [ ] **Step 2: Tambah `show_qris` ke whitelist JS (client-side collectForm)**

Di `struk.php`, function `collectForm()`, array `bools` (sekitar baris
577-587) — tambah `'show_qris'` di posisi yang sama:

```javascript
  const bools = [
    'show_logo','show_alamat','show_telp','show_email',
    'show_no_order','show_tanggal','show_nama_kasir','show_nama_pelanggan',
    'show_telp_pelanggan','show_alamat_pelanggan','show_detail_item',
    'show_subtotal','show_diskon','show_dp','show_total',
    'show_metode_bayar','show_sisa_bayar','show_qris','show_estimasi','show_catatan',
    'show_poin_earned','show_saldo_poin',
    'show_periode_invoice','show_jatuh_tempo','show_rekening',
    'show_footer_ucapan','show_footer_syarat','show_footer_sosmed',
    'show_qr_wa','show_border','show_watermark',
  ];
```

- [ ] **Step 3: Tambah checkbox "Tampilkan QRIS" di form (retail only)**

Di `struk.php`, dalam template literal form (sekitar baris 490-491), ganti:

```javascript
  ${checkRow('show_metode_bayar','Metode Bayar', t)}
  ${checkRow('show_sisa_bayar',  'Sisa Bayar', t)}
```

menjadi:

```javascript
  ${checkRow('show_metode_bayar','Metode Bayar', t)}
  ${checkRow('show_sisa_bayar',  'Sisa Bayar', t)}
  ${!isB2b ? checkRow('show_qris', 'Tampilkan QRIS (saat belum lunas & metode QRIS)', t) : ''}
```

- [ ] **Step 4: Pindahkan section Rekening keluar dari blok khusus B2B**

Section ini SAAT INI (sekitar baris 497-517) dibungkus `isB2b ? \`...\` :
''` — pisahkan jadi 2 bagian: `show_jatuh_tempo` TETAP di dalam blok B2B
saja, sedangkan checkbox+input rekening dipindah jadi section baru yang
SELALU muncul (retail maupun B2B), dengan label checkbox dibedakan sesuai
tab. Ganti blok:

```javascript
  ${isB2b ? `
  <!-- ── B2B EXTRA ── -->
  <div class="section-title">🏢 Khusus B2B / Invoice</div>
  ${checkRow('show_jatuh_tempo', 'Tanggal Jatuh Tempo', t)}
  ${checkRow('show_rekening',    'Info Rekening Pembayaran', t)}
  <div class="form-field" style="margin-top:8px">
    <label>Nama Bank</label>
    <input type="text" id="f_rekening_bank" value="${escHtml(v('rekening_bank'))}" maxlength="50"
           placeholder="cth: BCA" oninput="onFieldChange()">
  </div>
  <div class="form-field">
    <label>Nomor Rekening</label>
    <input type="text" id="f_rekening_nomor" value="${escHtml(v('rekening_nomor'))}" maxlength="50"
           placeholder="cth: 5520513584" oninput="onFieldChange()">
  </div>
  <div class="form-field">
    <label>Atas Nama</label>
    <input type="text" id="f_rekening_atas_nama" value="${escHtml(v('rekening_atas_nama'))}" maxlength="100"
           placeholder="cth: Bersih Laundry" oninput="onFieldChange()">
  </div>
  ` : ''}
```

menjadi:

```javascript
  ${isB2b ? `
  <!-- ── B2B EXTRA ── -->
  <div class="section-title">🏢 Khusus B2B / Invoice</div>
  ${checkRow('show_jatuh_tempo', 'Tanggal Jatuh Tempo', t)}
  ` : ''}

  <!-- ── REKENING (retail & B2B) ── -->
  <div class="section-title">🏦 Info Rekening</div>
  ${checkRow('show_rekening', isB2b ? 'Info Rekening Pembayaran' : 'Tampilkan Rekening (saat belum lunas & metode Transfer)', t)}
  <div class="form-field" style="margin-top:8px">
    <label>Nama Bank</label>
    <input type="text" id="f_rekening_bank" value="${escHtml(v('rekening_bank'))}" maxlength="50"
           placeholder="cth: BCA" oninput="onFieldChange()">
  </div>
  <div class="form-field">
    <label>Nomor Rekening</label>
    <input type="text" id="f_rekening_nomor" value="${escHtml(v('rekening_nomor'))}" maxlength="50"
           placeholder="cth: 5520513584" oninput="onFieldChange()">
  </div>
  <div class="form-field">
    <label>Atas Nama</label>
    <input type="text" id="f_rekening_atas_nama" value="${escHtml(v('rekening_atas_nama'))}" maxlength="100"
           placeholder="cth: Bersih Laundry" oninput="onFieldChange()">
  </div>
```

- [ ] **Step 5: Verifikasi manual — load halaman & cek toggle tersimpan**

Halaman ini butuh login session (tidak bisa di-curl langsung tanpa cookie
valid). Verifikasi lewat DB langsung setelah simulasi save manual:

Run:
```bash
php -r '
require "master/config/db.php"; require "core/Database.php"; require "core/StrukGenerator.php";
StrukGenerator::saveTemplate(18, 13, "retail", ["show_qris" => 0]);
$t = StrukGenerator::loadTemplate(18, 13, "retail");
echo "show_qris after save=0: " . $t["show_qris"] . "\n";
StrukGenerator::saveTemplate(18, 13, "retail", ["show_qris" => 1, "show_rekening" => 1, "rekening_bank" => "BCA", "rekening_nomor" => "999", "rekening_atas_nama" => "Test"]);
$t = StrukGenerator::loadTemplate(18, 13, "retail");
echo "show_qris after save=1: " . $t["show_qris"] . "\n";
echo "rekening_bank: " . $t["rekening_bank"] . "\n";
'
```

Expected:
```
show_qris after save=0: 0
show_qris after save=1: 1
rekening_bank: BCA
```

(Ganti tenant_id=18/outlet_id=13 sesuai outlet nyata yang dipakai buat
testing — lihat [[project_lamasy]] utk konteks tenant.)

- [ ] **Step 6: Commit**

```bash
git add struk.php
git commit -m "feat(struk): toggle Tampilkan QRIS + buka Info Rekening utk tab retail"
```

---

### Task 4: Render blok QRIS/Rekening di struk cetak thermal

**Files:**
- Modify: `core/StrukGenerator.php` (method `renderThermal()`)
- Test: `tests/struk/test_payment_aid.php` (tambah kasus baru)

**Interfaces:**
- Consumes: `StrukGenerator::paymentAidFor()` dari Task 2.

- [ ] **Step 1: Tambah test render (akan gagal — blok belum ada)**

Tambahkan di akhir `tests/struk/test_payment_aid.php` (sebelum baris
`echo "\nAll tests passed.\n";`):

```php
// ── renderThermal() render blok QRIS/Rekening ──────────
$trxQris = [
    'no_order' => 'TEST-001', 'total' => 50000, 'subtotal' => 50000,
    'diskon' => 0, 'biaya_tambahan' => 0, 'dp' => 0,
    'status_bayar' => 'belum_bayar', 'sisa_bayar' => 50000,
    'metode_bayar' => 'qris', 'tipe_order' => 'reguler',
    'created_at' => date('Y-m-d H:i:s'), 'tanggal' => date('Y-m-d H:i:s'),
];
$tmpl = array_merge(StrukGenerator::defaultTemplate('retail'), baseTmpl());
$outlet = baseOutlet(['nama_outlet' => 'Test Outlet']);
$html = StrukGenerator::renderThermal($trxQris, [], $tmpl, null, null, $outlet, 58);
ok(str_contains($html, '/assets/outlet-qris/foo.png'), 'renderThermal render gambar QRIS saat metode qris+belum lunas');
ok(str_contains($html, 'Sisa Bayar: Rp 50.000') || str_contains($html, 'Sisa Bayar: Rp 50,000'), 'renderThermal tampilkan sisa bayar dekat QRIS');

$trxTransfer = array_merge($trxQris, ['metode_bayar' => 'transfer']);
$html2 = StrukGenerator::renderThermal($trxTransfer, [], $tmpl, null, null, $outlet, 58);
ok(str_contains($html2, 'BCA') && str_contains($html2, '1234567890'), 'renderThermal render info rekening saat metode transfer+belum lunas');

$trxLunas = array_merge($trxQris, ['status_bayar' => 'lunas', 'sisa_bayar' => 0]);
$html3 = StrukGenerator::renderThermal($trxLunas, [], $tmpl, null, null, $outlet, 58);
ok(!str_contains($html3, '/assets/outlet-qris/foo.png'), 'renderThermal TIDAK render QRIS kalau sudah lunas');

echo "\nAll tests passed.\n";
```

(Hapus baris `echo "\nAll tests passed.\n";` yang lama di atasnya supaya
tidak dobel.)

- [ ] **Step 2: Jalankan, pastikan 3 assertion baru GAGAL**

Run: `php tests/struk/test_payment_aid.php`

Expected: baris `FAIL: renderThermal render gambar QRIS ...` (dan 2 lainnya),
exit code 1.

- [ ] **Step 3: Tambah blok render di `renderThermal()`**

Di `core/StrukGenerator.php`, method `renderThermal()`, cari blok:

```php
        if (!empty($tmpl['show_sisa_bayar']) && (float)($trx['sisa_bayar'] ?? 0) > 0) {
            $h .= "<div class='row b'>"
                . "<span class='l'>SISA BAYAR</span>"
                . "<span class='rv'>Rp " . self::rpNum($trx['sisa_bayar']) . "</span>"
                . "</div>\n";
        }

        // ── Estimasi ──────────────────────────────────
```

Sisipkan blok baru di antaranya (setelah SISA BAYAR, sebelum komentar
Estimasi):

```php
        if (!empty($tmpl['show_sisa_bayar']) && (float)($trx['sisa_bayar'] ?? 0) > 0) {
            $h .= "<div class='row b'>"
                . "<span class='l'>SISA BAYAR</span>"
                . "<span class='rv'>Rp " . self::rpNum($trx['sisa_bayar']) . "</span>"
                . "</div>\n";
        }

        // ── Alat Bayar (QRIS / Rekening) — cuma kalau belum lunas & ──
        // ── metode_bayar cocok, lihat StrukGenerator::paymentAidFor ──
        $aid = self::paymentAidFor($trx, $tmpl, $outlet);
        if ($aid) {
            $h .= "<hr class='sep'>\n";
            if ($aid['type'] === 'qris') {
                $h .= "<div class='c' style='margin-top:4px'>"
                    . "<img src='" . self::esc($aid['image']) . "' alt='QRIS' style='width:140px;max-width:90%;height:auto'/>"
                    . "</div>\n";
                if (!empty($aid['label'])) {
                    $h .= "<div class='c sm'>" . self::esc($aid['label']) . "</div>\n";
                }
                $h .= "<div class='c b' style='margin-top:2px'>Sisa Bayar: Rp " . self::rpNum($aid['sisa_bayar']) . "</div>\n";
                $h .= "<div class='c sm'>Scan lalu masukkan nominal di atas</div>\n";
            } elseif ($aid['type'] === 'rekening') {
                $h .= "<div class='c b'>Transfer ke:</div>\n";
                $h .= "<div class='c b'>" . self::esc($aid['bank']) . " — " . self::esc($aid['nomor']) . "</div>\n";
                if (!empty($aid['atas_nama'])) {
                    $h .= "<div class='c sm'>a.n. " . self::esc($aid['atas_nama']) . "</div>\n";
                }
                $h .= "<div class='c b' style='margin-top:2px'>Sisa Bayar: Rp " . self::rpNum($aid['sisa_bayar']) . "</div>\n";
            }
        }

        // ── Estimasi ──────────────────────────────────
```

- [ ] **Step 4: Jalankan test lagi, pastikan LULUS**

Run: `php tests/struk/test_payment_aid.php`

Expected: semua `PASS`, `All tests passed.`, exit code 0.

- [ ] **Step 5: Commit**

```bash
git add core/StrukGenerator.php tests/struk/test_payment_aid.php
git commit -m "feat(struk): render blok QRIS/Rekening di renderThermal() (struk cetak + modal POS)"
```

---

### Task 5: Blok QRIS/Rekening di Halaman Lacak Pesanan (`track.php`)

**Files:**
- Modify: `track.php`

**Interfaces:**
- Consumes: `StrukGenerator::paymentAidFor()` + `StrukGenerator::loadTemplate()`
  dari Task 2.

- [ ] **Step 1: Require StrukGenerator.php**

Di `track.php`, setelah baris:
```php
require_once ROOT . '/core/Database.php';
```
tambah:
```php
require_once ROOT . '/core/StrukGenerator.php';
```

- [ ] **Step 2: Tambah kolom qris ke 2 query SELECT**

Query pertama (by `no_order`, sekitar baris 26-29) — ganti:
```php
        $st = $db->prepare("SELECT t.*, o.nama_outlet, o.alamat AS outlet_alamat, o.telepon AS outlet_wa
                              FROM hl_transaksi t
                         LEFT JOIN outlets o ON o.id=t.outlet_id
                             WHERE (t.no_order=? OR t.offline_ref=?) LIMIT 1");
```
menjadi:
```php
        $st = $db->prepare("SELECT t.*, o.nama_outlet, o.alamat AS outlet_alamat, o.telepon AS outlet_wa,
                                    o.qris_image, o.qris_label
                              FROM hl_transaksi t
                         LEFT JOIN outlets o ON o.id=t.outlet_id
                             WHERE (t.no_order=? OR t.offline_ref=?) LIMIT 1");
```

Query kedua (by `hp`, sekitar baris 39-43) — ganti:
```php
        $st = $db->prepare("SELECT t.*, o.nama_outlet, o.alamat AS outlet_alamat, o.telepon AS outlet_wa
                              FROM hl_transaksi t
                         LEFT JOIN outlets o ON o.id=t.outlet_id
                             WHERE REPLACE(REPLACE(REPLACE(t.telepon,'-',''),' ',''),'+','') LIKE ?
                             ORDER BY t.id DESC LIMIT 1");
```
menjadi:
```php
        $st = $db->prepare("SELECT t.*, o.nama_outlet, o.alamat AS outlet_alamat, o.telepon AS outlet_wa,
                                    o.qris_image, o.qris_label
                              FROM hl_transaksi t
                         LEFT JOIN outlets o ON o.id=t.outlet_id
                             WHERE REPLACE(REPLACE(REPLACE(t.telepon,'-',''),' ',''),'+','') LIKE ?
                             ORDER BY t.id DESC LIMIT 1");
```

- [ ] **Step 3: Render blok di bawah section "Pembayaran"**

Cari blok penutup section Pembayaran (sekitar baris 305-311):
```php
        <div class="detail-row">
          <span class="lbl">Pembayaran</span>
          <span class="val">
            <?= ['lunas'=>'✅ Lunas','dp'=>'⚡ DP','belum_bayar'=>'⏳ Bayar saat ambil'][$order['status_bayar']] ?? $order['status_bayar'] ?>
          </span>
        </div>
      </div>
```

Sisipkan blok baru TEPAT SETELAH `</div>` penutup (baris terakhir di atas),
sebelum komentar `<!-- POIN INFO -->`:

```php
        <div class="detail-row">
          <span class="lbl">Pembayaran</span>
          <span class="val">
            <?= ['lunas'=>'✅ Lunas','dp'=>'⚡ DP','belum_bayar'=>'⏳ Bayar saat ambil'][$order['status_bayar']] ?? $order['status_bayar'] ?>
          </span>
        </div>
      </div>

      <?php
        $trackTmpl = StrukGenerator::loadTemplate((int)$order['tenant_id'], (int)$order['outlet_id'], 'retail');
        $trackAid  = StrukGenerator::paymentAidFor($order, $trackTmpl, $order);
      ?>
      <?php if ($trackAid): ?>
      <div style="margin-top:14px;padding:14px 16px;background:#F0FDF4;border:1px solid #86EFAC;border-radius:12px;text-align:center">
        <?php if ($trackAid['type'] === 'qris'): ?>
          <img src="<?= htmlspecialchars($trackAid['image']) ?>" alt="QRIS"
               style="max-width:200px;width:100%;border-radius:8px;margin-bottom:8px">
          <?php if (!empty($trackAid['label'])): ?>
            <div style="font-size:13px;color:#166534;margin-bottom:4px"><?= htmlspecialchars($trackAid['label']) ?></div>
          <?php endif; ?>
          <div style="font-weight:700;color:#166534">Sisa Bayar: Rp <?= number_format($trackAid['sisa_bayar'], 0, ',', '.') ?></div>
          <div style="font-size:12px;color:#166534;margin-top:4px">Scan lalu masukkan nominal di atas secara manual</div>
        <?php else: ?>
          <div style="font-weight:700;color:#166534">Transfer ke <?= htmlspecialchars($trackAid['bank']) ?> — <?= htmlspecialchars($trackAid['nomor']) ?></div>
          <?php if (!empty($trackAid['atas_nama'])): ?>
            <div style="font-size:13px;color:#166534">a.n. <?= htmlspecialchars($trackAid['atas_nama']) ?></div>
          <?php endif; ?>
          <div style="font-weight:700;color:#166534;margin-top:4px">Sisa Bayar: Rp <?= number_format($trackAid['sisa_bayar'], 0, ',', '.') ?></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
```

(`$order` sudah mengandung `tenant_id`/`outlet_id` dari `t.*`, dan
`qris_image`/`qris_label` dari SELECT baru di Step 2 — dipassing 2x sebagai
`$trx` dan `$outlet` ke `paymentAidFor()` karena hasil JOIN sudah flat jadi
satu array yang mengandung kedua sisi data.)

- [ ] **Step 4: Verifikasi manual via curl (setelah deploy ke prod)**

Butuh 1 order nyata yang statusnya belum lunas dengan metode_bayar='qris'
DAN outlet-nya sudah punya `qris_image`. Cari salah satu:

```bash
mysql -e "SELECT no_order, status_bayar, sisa_bayar, metode_bayar, outlet_id
          FROM hl_transaksi WHERE status_bayar IN ('belum_bayar','dp')
          AND sisa_bayar > 0 AND metode_bayar='qris' LIMIT 1"
```

Lalu:
```bash
curl -s "https://lamasy.harpy.id/track.php?order=<NO_ORDER_HASIL_QUERY>" | grep -o 'Sisa Bayar: Rp [0-9.,]*'
```

Expected: muncul 1 baris `Sisa Bayar: Rp ...` sesuai nominal `sisa_bayar` di
DB. Kalau tidak ada order qris yang cocok, uji manual dengan mengubah
`metode_bayar` 1 order test jadi `'qris'` via UPDATE langsung, cek, lalu
kembalikan nilainya.

- [ ] **Step 5: Commit**

```bash
git add track.php
git commit -m "feat(track): tampilkan QRIS/Rekening di halaman Lacak Pesanan saat belum lunas"
```

---

### Task 6: Nudge pembayaran di pesan WA Nota (`pos.php`)

**Files:**
- Modify: `pos.php` (action `wa_nota`)

**Interfaces:**
- Consumes: `StrukGenerator::paymentAidFor()` + `waPaymentNudgeLine()` dari
  Task 2.

- [ ] **Step 1: Require StrukGenerator.php di dalam action wa_nota**

Di `pos.php`, action `wa_nota` (sekitar baris 727-729), ganti:
```php
    if ($action === 'wa_nota') {
        require_once ROOT . '/core/CoinLedger.php';
        require_once ROOT . '/core/WaLogger.php';
```
menjadi:
```php
    if ($action === 'wa_nota') {
        require_once ROOT . '/core/CoinLedger.php';
        require_once ROOT . '/core/WaLogger.php';
        require_once ROOT . '/core/StrukGenerator.php';
```

- [ ] **Step 2: Hitung nudge & sisipkan ke pesan**

Cari blok pembentukan `$msg` (sekitar baris 774-787):
```php
        $msg = "Halo *{$t['nama_pelanggan']}*,\n\n"
             . "Pesanan Anda di *{$brandName}* sudah kami terima.\n\n"
             . "*No. Order:* {$t['no_order']}\n"
             . "*Tanggal:* {$tgl}\n"
             . "*Layanan:*{$itemList}\n\n"
             . "*Total:* {$totalFmt}\n"
             . ($t['metode_bayar']
                 ? ("*Bayar ({$metode}):* {$dpFmt}\n" . ($t['sisa_bayar'] > 0 ? "*Sisa Bayar:* {$sisaFmt}\n" : "*Status Bayar:* Lunas\n"))
                 : "*Status Bayar:* Belum Bayar\n")
             . "*Est. Selesai:* {$est}\n\n"
             . "Cek status real-time:\n{$trackUrl}\n\n"
             . ($alamat ? "*Alamat outlet:*\n{$outletNama}\n{$alamat}\n\n" : "")
             . "Terima kasih sudah mempercayakan cucian Anda kepada kami.\n"
             . "_" . $brandName . "_";
```

Tambah perhitungan `$aid`/`$nudge` SEBELUM blok itu, dan sisipkan
`$nudge` setelah baris `Cek status real-time`:

```php
        $waTmpl  = StrukGenerator::loadTemplate($tid, $oid, 'retail');
        $waAid   = StrukGenerator::paymentAidFor($t, $waTmpl, $outlet);
        $waNudge = StrukGenerator::waPaymentNudgeLine($waAid);

        $msg = "Halo *{$t['nama_pelanggan']}*,\n\n"
             . "Pesanan Anda di *{$brandName}* sudah kami terima.\n\n"
             . "*No. Order:* {$t['no_order']}\n"
             . "*Tanggal:* {$tgl}\n"
             . "*Layanan:*{$itemList}\n\n"
             . "*Total:* {$totalFmt}\n"
             . ($t['metode_bayar']
                 ? ("*Bayar ({$metode}):* {$dpFmt}\n" . ($t['sisa_bayar'] > 0 ? "*Sisa Bayar:* {$sisaFmt}\n" : "*Status Bayar:* Lunas\n"))
                 : "*Status Bayar:* Belum Bayar\n")
             . "*Est. Selesai:* {$est}\n\n"
             . "Cek status real-time:\n{$trackUrl}\n"
             . $waNudge . "\n"
             . ($alamat ? "*Alamat outlet:*\n{$outletNama}\n{$alamat}\n\n" : "")
             . "Terima kasih sudah mempercayakan cucian Anda kepada kami.\n"
             . "_" . $brandName . "_";
```

(`$outlet` sudah ada di scope — dideklarasikan di baris 756 lewat
`TenantResolver::getOutlet()`, sebelum blok `$msg` ini.)

- [ ] **Step 3: Verifikasi manual — cek pesan via curl sesi POS**

Login sbg staf outlet yang punya order belum lunas metode qris (pola curl
sama seperti yg dipakai sesi sebelumnya utk verifikasi HQ Karyawan — lihat
riwayat kerja tenant 18/outlet 13), lalu:

```bash
curl -s -b cookies.txt "https://lamasy.harpy.id/pos.php?action=wa_nota&id=<ID_TRANSAKSI_QRIS_BELUM_LUNAS>" | php -r 'echo json_decode(file_get_contents("php://stdin"),true)["message"] ?? "ERROR";'
```

Expected: output mengandung baris `💳 Bayar via QRIS: buka link di atas`.

- [ ] **Step 4: Commit**

```bash
git add pos.php
git commit -m "feat(pos): tambah nudge pembayaran QRIS/Rekening di pesan WA nota"
```

---

### Task 7: Alat crop manual QRIS saat upload (`payment-settings.php`)

**Files:**
- Modify: `payment-settings.php`

**Interfaces:**
- Tidak konsumsi/produce interface PHP baru — murni penambahan UI/JS di
  form upload yang sudah ada. Hasil akhirnya file yang ke-`$_FILES['qris_image']`
  di server SAMA seperti sebelumnya (validasi server-side TIDAK berubah).

- [ ] **Step 1: Tambah elemen crop modal (HTML) — taruh sebelum `</div>` penutup form upload**

Di `payment-settings.php`, cari blok input file (sekitar baris 347-351):
```php
        <div style="margin-bottom:16px">
          <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151">Upload Gambar QRIS *</label>
          <input type="file" name="qris_image" accept="image/jpeg,image/png,image/webp" required
                 style="width:100%;padding:10px;border:1px dashed #d1d5db;border-radius:8px;background:#f9fafb">
        </div>
```

Ganti jadi (tambah `id` di input file + preview thumbnail + tombol buka
crop):
```php
        <div style="margin-bottom:16px">
          <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151">Upload Gambar QRIS *</label>
          <input type="file" name="qris_image" id="qrisFileInput" accept="image/jpeg,image/png,image/webp" required
                 style="width:100%;padding:10px;border:1px dashed #d1d5db;border-radius:8px;background:#f9fafb">
          <div id="qrisCropPreview" style="display:none;margin-top:10px;padding:10px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px">
            <div style="font-size:12px;color:#166534;margin-bottom:6px">✓ Sudah di-crop:</div>
            <img id="qrisCropPreviewImg" style="max-width:120px;border-radius:6px;display:block;margin-bottom:8px">
            <button type="button" onclick="openQrisCrop()" style="background:#fff;border:1px solid #d1d5db;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:12px">
              Crop ulang
            </button>
          </div>
        </div>
```

- [ ] **Step 2: Tambah modal crop (HTML) — taruh setelah `</form>` penutup form upload QRIS**

Cari penutup form upload (sekitar baris 363-367):
```php
        <button type="submit" style="background:#0d9488;color:#fff;border:0;padding:12px 20px;border-radius:8px;cursor:pointer;font-weight:600;font-size:14px">
          💾 Simpan QRIS
        </button>
      </form>
    </div>
  </div>
</div>
```

Sisipkan modal crop TEPAT SETELAH `</div>` penutup terluar (baris terakhir):

```html
</div>

<!-- ═══ Modal Crop QRIS ═══ -->
<div id="qrisCropModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:2000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;padding:20px;max-width:420px;width:92%;box-shadow:0 20px 60px rgba(0,0,0,0.3)">
    <h3 style="margin:0 0 6px 0;font-size:16px">✂️ Crop area QRIS</h3>
    <p style="margin:0 0 14px 0;font-size:12.5px;color:#6b7280">
      Geser & perbesar-kecilkan kotak putih supaya pas menutupi kode QR-nya
      saja (buang twibon/border/logo di sekelilingnya). Kalau gambar sudah
      cuma QR polos, langsung klik "Pakai Crop Ini" tanpa perlu digeser.
    </p>
    <div id="qrisCropStage" style="position:relative;width:100%;aspect-ratio:1;background:#111;border-radius:8px;overflow:hidden;touch-action:none">
      <img id="qrisCropImg" style="position:absolute;top:0;left:0;max-width:none;pointer-events:none">
      <div id="qrisCropBox" style="position:absolute;border:2px solid #fff;box-shadow:0 0 0 2000px rgba(0,0,0,0.5);cursor:move">
        <div class="qris-crop-handle" data-corner="nw" style="position:absolute;top:-6px;left:-6px;width:16px;height:16px;background:#fff;border-radius:50%;cursor:nwse-resize"></div>
        <div class="qris-crop-handle" data-corner="ne" style="position:absolute;top:-6px;right:-6px;width:16px;height:16px;background:#fff;border-radius:50%;cursor:nesw-resize"></div>
        <div class="qris-crop-handle" data-corner="sw" style="position:absolute;bottom:-6px;left:-6px;width:16px;height:16px;background:#fff;border-radius:50%;cursor:nesw-resize"></div>
        <div class="qris-crop-handle" data-corner="se" style="position:absolute;bottom:-6px;right:-6px;width:16px;height:16px;background:#fff;border-radius:50%;cursor:nwse-resize"></div>
      </div>
    </div>
    <div style="display:flex;gap:8px;margin-top:16px">
      <button type="button" onclick="closeQrisCrop()" style="flex:1;background:#fff;border:1px solid #d1d5db;padding:10px;border-radius:8px;cursor:pointer;font-weight:600">
        Batal
      </button>
      <button type="button" onclick="applyQrisCrop()" style="flex:1;background:#0d9488;color:#fff;border:0;padding:10px;border-radius:8px;cursor:pointer;font-weight:600">
        ✓ Pakai Crop Ini
      </button>
    </div>
  </div>
</div>
```

- [ ] **Step 3: Tulis JS crop tool**

Cari tag `<script>` yang sudah ada di `payment-settings.php` (untuk logic
`openMethodModal`/dsb — cari string `function openMethodModal`), dan tambah
fungsi-fungsi berikut DI DALAM tag `<script>` yang sama (di akhir, sebelum
`</script>`):

```javascript
// ═══════════════════════════════════════════════════════
// Crop QRIS manual — vanilla Canvas, tanpa library eksternal
// ═══════════════════════════════════════════════════════
let qrisNaturalImg = null;   // Image() object, dimensi asli
let qrisCroppedBlob = null;  // hasil crop terakhir (dipakai submit)
let qrisBox = { x: 0, y: 0, size: 0 }; // posisi kotak crop, px relatif ke stage
let qrisDrag = null; // {mode:'move'|'resize', corner, startX, startY, startBox}

document.getElementById('qrisFileInput').addEventListener('change', function (e) {
  const file = e.target.files[0];
  if (!file) return;
  const img = new Image();
  img.onload = function () {
    qrisNaturalImg = img;
    openQrisCrop();
  };
  img.src = URL.createObjectURL(file);
});

function openQrisCrop() {
  if (!qrisNaturalImg) return;
  const stage = document.getElementById('qrisCropStage');
  const imgEl = document.getElementById('qrisCropImg');
  const stageSize = stage.clientWidth; // stage persegi (aspect-ratio:1)

  // Skala gambar biar sisi TERPENDEK pas dengan stage (contain-cover style)
  const iw = qrisNaturalImg.naturalWidth, ih = qrisNaturalImg.naturalHeight;
  const scale = stageSize / Math.min(iw, ih);
  imgEl.style.width = (iw * scale) + 'px';
  imgEl.style.height = (ih * scale) + 'px';
  imgEl.style.left = ((stageSize - iw * scale) / 2) + 'px';
  imgEl.style.top = ((stageSize - ih * scale) / 2) + 'px';
  imgEl.src = qrisNaturalImg.src;

  // Kotak crop awal = full stage (persegi penuh)
  qrisBox = { x: 0, y: 0, size: stageSize };
  drawQrisBox();

  document.getElementById('qrisCropModal').style.display = 'flex';
}

function closeQrisCrop() {
  document.getElementById('qrisCropModal').style.display = 'none';
}

function drawQrisBox() {
  const box = document.getElementById('qrisCropBox');
  box.style.left = qrisBox.x + 'px';
  box.style.top = qrisBox.y + 'px';
  box.style.width = qrisBox.size + 'px';
  box.style.height = qrisBox.size + 'px';
}

// ── Drag kotak (move) ──────────────────────────────────
document.getElementById('qrisCropBox').addEventListener('pointerdown', function (e) {
  if (e.target.classList.contains('qris-crop-handle')) return; // ditangani listener resize
  qrisDrag = { mode: 'move', startX: e.clientX, startY: e.clientY, startBox: { ...qrisBox } };
  e.preventDefault();
});

// ── Resize dari handle sudut ────────────────────────────
document.querySelectorAll('.qris-crop-handle').forEach(function (h) {
  h.addEventListener('pointerdown', function (e) {
    qrisDrag = { mode: 'resize', corner: h.dataset.corner, startX: e.clientX, startY: e.clientY, startBox: { ...qrisBox } };
    e.preventDefault();
    e.stopPropagation();
  });
});

document.addEventListener('pointermove', function (e) {
  if (!qrisDrag) return;
  const stage = document.getElementById('qrisCropStage');
  const stageSize = stage.clientWidth;
  const dx = e.clientX - qrisDrag.startX;
  const dy = e.clientY - qrisDrag.startY;

  if (qrisDrag.mode === 'move') {
    let x = qrisDrag.startBox.x + dx;
    let y = qrisDrag.startBox.y + dy;
    x = Math.max(0, Math.min(x, stageSize - qrisDrag.startBox.size));
    y = Math.max(0, Math.min(y, stageSize - qrisDrag.startBox.size));
    qrisBox = { x, y, size: qrisDrag.startBox.size };
  } else if (qrisDrag.mode === 'resize') {
    // Semua handle: pertahankan kotak persegi, ambil delta terbesar (x atau y)
    const delta = qrisDrag.corner.includes('e') || qrisDrag.corner.includes('s')
      ? Math.max(dx, dy) : Math.max(-dx, -dy);
    let size = qrisDrag.startBox.size + delta;
    size = Math.max(40, Math.min(size, stageSize));

    let x = qrisDrag.startBox.x, y = qrisDrag.startBox.y;
    if (qrisDrag.corner.includes('w')) x = qrisDrag.startBox.x + (qrisDrag.startBox.size - size);
    if (qrisDrag.corner.includes('n')) y = qrisDrag.startBox.y + (qrisDrag.startBox.size - size);
    x = Math.max(0, Math.min(x, stageSize - size));
    y = Math.max(0, Math.min(y, stageSize - size));
    qrisBox = { x, y, size };
  }
  drawQrisBox();
});

document.addEventListener('pointerup', function () { qrisDrag = null; });

// ── Terapkan crop: hitung koordinat asli, gambar ke canvas, jadi Blob ──
function applyQrisCrop() {
  const stage = document.getElementById('qrisCropStage');
  const imgEl = document.getElementById('qrisCropImg');
  const stageSize = stage.clientWidth;

  const iw = qrisNaturalImg.naturalWidth, ih = qrisNaturalImg.naturalHeight;
  const scale = stageSize / Math.min(iw, ih);
  const imgLeft = parseFloat(imgEl.style.left);
  const imgTop  = parseFloat(imgEl.style.top);

  // Koordinat kotak crop RELATIF ke gambar asli (bukan ke stage)
  const srcX = (qrisBox.x - imgLeft) / scale;
  const srcY = (qrisBox.y - imgTop) / scale;
  const srcSize = qrisBox.size / scale;

  const OUT = 600; // ukuran output persegi, cukup besar utk cetak thermal
  const canvas = document.createElement('canvas');
  canvas.width = OUT; canvas.height = OUT;
  const ctx = canvas.getContext('2d');
  ctx.drawImage(qrisNaturalImg, srcX, srcY, srcSize, srcSize, 0, 0, OUT, OUT);

  canvas.toBlob(function (blob) {
    qrisCroppedBlob = blob;

    // Ganti isi <input type=file> dgn hasil crop (DataTransfer API)
    const file = new File([blob], 'qris-cropped.png', { type: 'image/png' });
    const dt = new DataTransfer();
    dt.items.add(file);
    document.getElementById('qrisFileInput').files = dt.files;

    // Tampilkan preview hasil crop
    document.getElementById('qrisCropPreviewImg').src = URL.createObjectURL(blob);
    document.getElementById('qrisCropPreview').style.display = 'block';

    closeQrisCrop();
  }, 'image/png');
}
```

- [ ] **Step 4: Verifikasi manual di browser**

1. Buka halaman Pembayaran (`/payment-settings`) sbg owner/admin outlet.
2. Klik "Upload Gambar QRIS", pilih file gambar apa saja (min 400×400px).
3. Modal crop harus muncul otomatis dgn kotak putih menutupi seluruh
   gambar (persegi).
4. Geser kotak (klik-tahan di tengah) — kotak harus ikut bergerak, tidak
   bisa keluar batas gambar.
5. Perbesar/perkecil lewat salah satu handle bundar di sudut — kotak harus
   tetap persegi (bukan jadi persegi panjang).
6. Klik "✓ Pakai Crop Ini" — modal tertutup, muncul preview thumbnail hasil
   crop dgn tulisan "✓ Sudah di-crop".
7. Klik "💾 Simpan QRIS" — submit form seperti biasa. Cek DB:
   ```bash
   mysql -e "SELECT qris_image FROM outlets WHERE id=<OUTLET_ID>"
   ```
   lalu buka file hasilnya (`https://lamasy.harpy.id{qris_image}`) — harus
   persegi (600×600) dan cuma berisi area yang di-crop, bukan gambar asli
   utuh.
8. Klik "Crop ulang" di preview — modal harus terbuka lagi dgn gambar yang
   SAMA (bukan file kosong), siap di-crop ulang sebelum submit final.

- [ ] **Step 5: Commit**

```bash
git add payment-settings.php
git commit -m "feat(payment-settings): alat crop manual (Canvas) utk gambar QRIS saat upload"
```

---

## Ringkasan Urutan Task

1. Migrasi DB (`show_qris`)
2. Core logic `paymentAidFor()` + `waPaymentNudgeLine()` + tes
3. UI toggle di Kustomisasi Struk
4. Render di struk cetak thermal (+ modal POS otomatis ikut)
5. Render di Halaman Lacak Pesanan
6. Nudge di pesan WA Nota
7. Alat crop manual QRIS di upload

Task 1→2 wajib berurutan (kolom DB dulu baru whitelist-nya). Task 3, 4, 5, 6
semua bergantung ke Task 2 tapi independen satu sama lain (bisa dikerjakan
paralel kalau pakai subagent-driven, TAPI Task 4/5 saling tumpang tindih
sedikit di StrukGenerator.php jadi lebih aman sequential). Task 7 sepenuhnya
independen, bisa dikerjakan kapan saja bahkan sebelum Task 1.

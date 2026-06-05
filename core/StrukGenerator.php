<?php
// ══════════════════════════════════════════════════════
// core/StrukGenerator.php — Generate HTML struk & invoice
//
// CARA PAKAI:
//   // Retail thermal (potong coin)
//   $html = StrukGenerator::generate($transaksiId, 'retail');
//
//   // B2B invoice A4 (potong coin)
//   $html = StrukGenerator::generate($transaksiId, 'b2b', 'a4');
//
//   // Preview (tidak potong coin)
//   $html = StrukGenerator::generate($transaksiId, 'retail', null, false);
//
//   // Preview dengan template dummy (tanpa transaksi real)
//   $html = StrukGenerator::preview($tmplArray, 'retail');
// ══════════════════════════════════════════════════════

class StrukGenerator
{
    // ── Coin cost per tipe ─────────────────────────────
    const COIN_RETAIL  = 'generate_nota';    // 50 coin
    const COIN_B2B     = 'generate_invoice'; // 200 coin

    // ══════════════════════════════════════════════════
    // PUBLIC: Generate dari transaksi real
    // ══════════════════════════════════════════════════
    public static function generate(
        int     $transaksiId,
        string  $tipe        = 'retail',
        ?string $format      = null,
        bool    $deductCoin  = true
    ): string {
        $db  = Database::get();
        $tid = TenantResolver::id();
        $oid = TenantResolver::outletId();

        // ── Load transaksi ────────────────────────────
        $trx = $db->prepare(
            "SELECT t.*,
                    u.nama AS kasir_nama
               FROM hl_transaksi t
               LEFT JOIN hl_users u ON u.id = t.created_by AND u.tenant_id = ?
              WHERE t.id = ? AND t.tenant_id = ? AND t.outlet_id = ?
              LIMIT 1"
        );
        $trx->execute([$tid, $transaksiId, $tid, $oid]);
        $trx = $trx->fetch(PDO::FETCH_ASSOC);
        if (!$trx) {
            throw new RuntimeException("Transaksi #{$transaksiId} tidak ditemukan.");
        }

        // ── Load items ────────────────────────────────
        $itemSt = $db->prepare(
            "SELECT * FROM hl_transaksi_item
              WHERE transaksi_id = ? AND tenant_id = ?
              ORDER BY id ASC"
        );
        $itemSt->execute([$transaksiId, $tid]);
        $items = $itemSt->fetchAll(PDO::FETCH_ASSOC);

        // ── Load pelanggan (nullable) ─────────────────
        $pelanggan = null;
        if (!empty($trx['pelanggan_id'])) {
            $pSt = $db->prepare(
                "SELECT * FROM hl_pelanggan WHERE id = ? AND tenant_id = ? LIMIT 1"
            );
            $pSt->execute([(int)$trx['pelanggan_id'], $tid]);
            $pelanggan = $pSt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        // ── Load poin earned (loyalty_log) ────────────
        $poin = null;
        try {
            $pSt = $db->prepare(
                "SELECT poin, balance_after FROM hl_loyalty_log
                  WHERE transaksi_id = ? AND tenant_id = ? AND type = 'earn'
                  ORDER BY id DESC LIMIT 1"
            );
            $pSt->execute([$transaksiId, $tid]);
            $poin = $pSt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable) {}

        // ── Load template ─────────────────────────────
        $tmpl   = self::loadTemplate($tid, $oid, $tipe);
        $format = $format ?? $tmpl['format'];

        // ── Render dulu ───────────────────────────────
        $outlet = TenantResolver::getOutlet();
        $html = self::render($trx, $items, $tmpl, $pelanggan, $poin, $outlet, $format);

        // ── Deduct coin SETELAH render sukses ─────────
        // (kalau render gagal/exception, baris ini tidak tercapai → tidak potong)
        if ($deductCoin) {
            $feature = ($tipe === 'b2b') ? self::COIN_B2B : self::COIN_RETAIL;
            try { CoinLedger::deduct($feature, (string)$transaksiId); } catch (Throwable) {}
        }
        return $html;
    }

    // ══════════════════════════════════════════════════
    // PUBLIC: Preview dengan data dummy (tanpa transaksi)
    // Untuk live preview di halaman /struk.php
    // ══════════════════════════════════════════════════
    public static function preview(array $tmpl, string $tipe = 'retail'): string
    {
        $format = $tmpl['format'] ?? ($tipe === 'b2b' ? 'a4' : 'thermal_80');

        $trx = [
            'no_order'          => 'HL-2025-001',
            'tanggal'           => date('Y-m-d'),
            'created_at'        => date('Y-m-d H:i:s'),
            'nama_pelanggan'    => 'Bu Sari Dewi',
            'telepon'           => '08123456789',
            'subtotal'          => 43000,
            'diskon'            => 3000,
            'total'             => 40000,
            'dp'                => 20000,
            'sisa_bayar'        => 20000,
            'metode_bayar'      => 'Cash',
            'status_bayar'      => 'dp',
            'estimasi_selesai'  => date('Y-m-d', strtotime('+2 days')),
            'catatan'           => 'Pisahkan baju putih',
            'kasir_nama'        => 'Melati',
        ];
        $items = [
            ['nama_layanan'=>'Kiloan Express', 'jumlah'=>3,   'satuan'=>'kg',  'harga_satuan'=>7000, 'subtotal'=>21000],
            ['nama_layanan'=>'Setrika Saja',   'jumlah'=>1,   'satuan'=>'pcs', 'harga_satuan'=>15000,'subtotal'=>15000],
            ['nama_layanan'=>'Jas Hujan',      'jumlah'=>1,   'satuan'=>'pcs', 'harga_satuan'=>7000, 'subtotal'=>7000],
        ];
        $pelanggan = [
            'nama'    => 'Bu Sari Dewi',
            'telepon' => '08123456789',
            'alamat'  => 'Jl. Merdeka No. 5, Jakarta Selatan',
        ];
        $poin = ['poin' => 4, 'balance_after' => 47];
        $outlet = TenantResolver::getOutlet() ?: [
            'nama_outlet' => TenantResolver::getTenant()['nama_perusahaan'] ?? 'Outlet Laundry',
            'alamat'      => 'Jl. Contoh No. 1',
            'kota'        => 'Indonesia',
            'telepon'     => '',
            'email'       => '',
        ];

        return self::render($trx, $items, $tmpl, $pelanggan, $poin, $outlet, $format);
    }

    // ══════════════════════════════════════════════════
    // PUBLIC: Generate Invoice B2B dari hl_piutang
    // ══════════════════════════════════════════════════
    public static function generateInvoice(
        int  $piutangId,
        bool $deductCoin = true
    ): string {
        $db  = Database::get();
        $tid = TenantResolver::id();
        $oid = TenantResolver::outletId();

        // Load piutang + pelanggan
        $p = $db->prepare(
            "SELECT p.*, pl.nama AS pelanggan_nama, pl.telepon AS pelanggan_telp,
                    pl.alamat AS pelanggan_alamat
               FROM hl_piutang p
               JOIN hl_pelanggan pl ON pl.id = p.pelanggan_id AND pl.tenant_id = p.tenant_id
              WHERE p.id = ? AND p.tenant_id = ? AND p.outlet_id = ?
              LIMIT 1"
        );
        $p->execute([$piutangId, $tid, $oid]);
        $piu = $p->fetch(PDO::FETCH_ASSOC);
        if (!$piu) throw new RuntimeException("Piutang #{$piutangId} tidak ditemukan.");

        // Load transaksi dalam periode (sebagai line items invoice)
        $trxSt = $db->prepare(
            "SELECT t.id, t.no_order, t.tanggal, t.total, t.subtotal, t.diskon,
                    t.metode_bayar, t.status_bayar, t.catatan,
                    t.dp, t.sisa_bayar, t.created_at
               FROM hl_transaksi t
              WHERE t.tenant_id = ? AND t.outlet_id = ?
                AND t.pelanggan_id = ?
                AND t.tanggal BETWEEN ? AND ?
              ORDER BY t.tanggal ASC"
        );
        $trxSt->execute([$tid, $oid, $piu['pelanggan_id'], $piu['periode_start'], $piu['periode_end']]);
        $transactions = $trxSt->fetchAll(PDO::FETCH_ASSOC);

        // Bangun synthetic transaksi & items untuk renderPdf
        $bulanId = $tid . '-INV-' . str_pad($piutangId, 5, '0', STR_PAD_LEFT);
        $trx = [
            'no_order'       => $bulanId,
            'tanggal'        => $piu['periode_start'],
            'created_at'     => $piu['created_at'],
            'nama_pelanggan' => $piu['pelanggan_nama'],
            'telepon'        => $piu['pelanggan_telp'],
            'subtotal'       => $piu['total_tagihan'],
            'diskon'         => 0,
            'total'          => $piu['total_tagihan'],
            'dp'             => $piu['total_dibayar'],
            'sisa_bayar'     => $piu['sisa_tagihan'],
            'metode_bayar'   => 'Transfer',
            'catatan'        => $piu['catatan'],
            'jatuh_tempo'    => $piu['jatuh_tempo'],
            'kasir_nama'     => null,
        ];

        // Setiap transaksi jadi satu line item
        $items = [];
        foreach ($transactions as $t) {
            $fmt = self::fmtDate($t['tanggal'], 'd/m/Y');
            $items[] = [
                'nama_layanan' => "Order #{$t['no_order']} ({$fmt})",
                'jumlah'       => 1,
                'satuan'       => 'invoice',
                'harga_satuan' => (float)$t['total'],
                'subtotal'     => (float)$t['total'],
            ];
        }
        if (empty($items)) {
            // Fallback: satu baris total
            $periodeTxt = self::fmtDate($piu['periode_start'], 'd M Y') . ' – ' . self::fmtDate($piu['periode_end'], 'd M Y');
            $items[] = [
                'nama_layanan' => "Layanan Laundry Periode {$periodeTxt}",
                'jumlah'       => 1,
                'satuan'       => 'periode',
                'harga_satuan' => (float)$piu['total_tagihan'],
                'subtotal'     => (float)$piu['total_tagihan'],
            ];
        }

        $pelanggan = [
            'nama'    => $piu['pelanggan_nama'],
            'telepon' => $piu['pelanggan_telp'],
            'alamat'  => $piu['pelanggan_alamat'],
        ];

        $tmpl   = self::loadTemplate($tid, $oid, 'b2b');
        $outlet = TenantResolver::getOutlet();

        // Render dulu — deduct hanya kalau invoice berhasil dibuat
        $html = self::render($trx, $items, $tmpl, $pelanggan, null, $outlet, $tmpl['format'] ?? 'a4');
        if ($deductCoin) {
            try { CoinLedger::deduct(self::COIN_B2B, (string)$piutangId); } catch (Throwable) {}
        }
        return $html;
    }

    // ══════════════════════════════════════════════════
    // PUBLIC: Load template (atau default) untuk outlet
    // ══════════════════════════════════════════════════
    public static function loadTemplate(int $tenantId, int $outletId, string $tipe): array
    {
        $db = Database::get();
        $st = $db->prepare(
            "SELECT * FROM hl_struk_template
              WHERE tenant_id = ? AND outlet_id = ? AND tipe = ? AND is_active = 1
              LIMIT 1"
        );
        $st->execute([$tenantId, $outletId, $tipe]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: self::defaultTemplate($tipe);
    }

    // ══════════════════════════════════════════════════
    // PUBLIC: Simpan / upsert template dari form
    // ══════════════════════════════════════════════════
    public static function saveTemplate(int $tenantId, int $outletId, string $tipe, array $data): void
    {
        $db = Database::get();

        // Field-field yang boleh disimpan (whitelist)
        $allowed = [
            'format','show_logo','logo_url','logo_size','nama_outlet','tagline',
            'show_alamat','alamat_override','show_telp','show_email','header_extra',
            'show_no_order','show_tanggal','show_nama_kasir','show_nama_pelanggan',
            'show_telp_pelanggan','show_alamat_pelanggan','show_detail_item',
            'show_subtotal','show_diskon','show_dp','show_total','show_metode_bayar',
            'show_sisa_bayar','show_estimasi','show_catatan',
            'show_poin_earned','show_saldo_poin',
            'show_periode_invoice','show_jatuh_tempo','show_rekening',
            'rekening_bank','rekening_nomor','rekening_atas_nama',
            'footer_ucapan','show_footer_ucapan','footer_syarat','show_footer_syarat',
            'footer_sosmed','show_footer_sosmed','show_qr_wa','footer_extra',
            'font_size','show_border','show_watermark',
        ];

        $set = []; $vals = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $set[]  = "$col = ?";
                $vals[] = $data[$col];
            }
        }
        if (!$set) return;

        // Upsert: update jika ada, insert jika belum
        $existing = $db->prepare(
            "SELECT id FROM hl_struk_template WHERE tenant_id=? AND outlet_id=? AND tipe=? LIMIT 1"
        );
        $existing->execute([$tenantId, $outletId, $tipe]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $vals[] = $tenantId; $vals[] = $outletId; $vals[] = $tipe;
            $db->prepare(
                "UPDATE hl_struk_template SET " . implode(', ', $set) .
                " WHERE tenant_id=? AND outlet_id=? AND tipe=?"
            )->execute($vals);
        } else {
            $cols   = ['tenant_id','outlet_id','tipe'];
            $phs    = ['?','?','?'];
            $ivals  = [$tenantId, $outletId, $tipe];
            foreach ($allowed as $col) {
                if (array_key_exists($col, $data)) {
                    $cols[]  = $col;
                    $phs[]   = '?';
                    $ivals[] = $data[$col];
                }
            }
            $db->prepare(
                "INSERT INTO hl_struk_template (" . implode(',', $cols) . ")
                 VALUES (" . implode(',', $phs) . ")"
            )->execute($ivals);
        }
    }

    // ══════════════════════════════════════════════════
    // PRIVATE: Route ke renderer yang tepat
    // ══════════════════════════════════════════════════
    private static function render(
        array  $trx,
        array  $items,
        array  $tmpl,
        ?array $pelanggan,
        ?array $poin,
        array  $outlet,
        string $format
    ): string {
        return match ($format) {
            'thermal_58' => self::renderThermal($trx, $items, $tmpl, $pelanggan, $poin, $outlet, 58),
            'a4'         => self::renderPdf($trx, $items, $tmpl, $pelanggan, $poin, $outlet, 'a4'),
            'a5'         => self::renderPdf($trx, $items, $tmpl, $pelanggan, $poin, $outlet, 'a5'),
            default      => self::renderThermal($trx, $items, $tmpl, $pelanggan, $poin, $outlet, 80),
        };
    }

    // ══════════════════════════════════════════════════
    // PRIVATE: Render Thermal (58mm / 80mm)
    // ══════════════════════════════════════════════════
    public static function renderThermal(
        array  $trx,
        array  $items,
        array  $tmpl,
        ?array $pel,
        ?array $poin,
        array  $outlet,
        int    $width
    ): string {
        $maxChar  = $width === 58 ? 32 : 42;
        $fontSize = match ($tmpl['font_size'] ?? 'normal') {
            'small' => '10px', 'large' => '14px', default => '12px',
        };
        $namaOutlet = self::esc($tmpl['nama_outlet'] ?: ($outlet['nama_outlet'] ?? 'Outlet'));
        $sep        = str_repeat('-', $maxChar);

        $h = "<!DOCTYPE html>
<html lang='id'>
<head>
<meta charset='UTF-8'>
<title>Struk {$trx['no_order']}</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body {
  font-family: 'Courier New', Courier, monospace;
  font-size: {$fontSize};
  width: {$width}mm;
  padding: 3mm 4mm 6mm;
  color: #000;
  background: #fff;
}
.c  { text-align:center; }
.r  { text-align:right; }
.b  { font-weight:bold; }
.sm { font-size:0.85em; }
.sep { border:none; border-top:1px dashed #000; margin:3px 0; }
.row { display:flex; justify-content:space-between; align-items:baseline; gap:4px; }
.row .l { flex:1; overflow:hidden; white-space:nowrap; text-overflow:clip; }
.row .rv { white-space:nowrap; flex-shrink:0; }
.logo { max-width:100%; max-height:24mm; margin:0 auto 3px; display:block; }
@media print {
  body { margin:0; padding:3mm 4mm 6mm; }
  @page { margin:0; size:{$width}mm auto; }
  .no-print { display:none !important; }
}
</style>
</head>
<body>\n";

        // ── HEADER ────────────────────────────────────
        if (!empty($tmpl['show_logo']) && !empty($tmpl['logo_url'])) {
            $logoW = match ($tmpl['logo_size'] ?? 'medium') {
                'small' => '20mm', 'large' => '40mm', default => '30mm',
            };
            $h .= "<img src='" . self::esc($tmpl['logo_url']) . "'
                        style='max-width:{$logoW};max-height:24mm'
                        class='logo'>\n";
        }

        $h .= "<div class='c b'>{$namaOutlet}</div>\n";

        if (!empty($tmpl['tagline'])) {
            $h .= "<div class='c sm'>" . self::esc($tmpl['tagline']) . "</div>\n";
        }
        if (!empty($tmpl['show_alamat'])) {
            $alamat = $tmpl['alamat_override'] ?: (($outlet['alamat'] ?? '') . ($outlet['kota'] ? ', ' . $outlet['kota'] : ''));
            if ($alamat) {
                foreach (str_split(trim($alamat), $maxChar) as $line) {
                    $h .= "<div class='c sm'>" . self::esc($line) . "</div>\n";
                }
            }
        }
        if (!empty($tmpl['show_telp']) && !empty($outlet['telepon'])) {
            $h .= "<div class='c sm'>Telp: " . self::esc($outlet['telepon']) . "</div>\n";
        }
        if (!empty($tmpl['header_extra'])) {
            $h .= "<div class='c sm'>" . self::esc($tmpl['header_extra']) . "</div>\n";
        }

        $h .= "<hr class='sep'>\n";

        // ── BODY: Order Info ──────────────────────────
        if (!empty($tmpl['show_no_order'])) {
            $h .= self::tRow('No. Order', $trx['no_order'], $maxChar);
        }
        if (!empty($tmpl['show_tanggal'])) {
            $h .= self::tRow('Tanggal', self::fmtDate($trx['created_at'] ?: $trx['tanggal'], 'd/m/Y H:i'), $maxChar);
        }
        if (!empty($tmpl['show_nama_kasir']) && !empty($trx['kasir_nama'])) {
            $h .= self::tRow('Kasir', $trx['kasir_nama'], $maxChar);
        }

        $h .= "<hr class='sep'>\n";

        // ── Pelanggan ─────────────────────────────────
        if (!empty($tmpl['show_nama_pelanggan'])) {
            $nama = $pel ? ($pel['nama'] ?? '') : ($trx['nama_pelanggan'] ?? '');
            if ($nama) $h .= "<div class='b'>" . self::esc($nama) . "</div>\n";
        }
        if (!empty($tmpl['show_telp_pelanggan'])) {
            $telp = $pel['telepon'] ?? $trx['telepon'] ?? '';
            if ($telp) $h .= "<div class='sm'>" . self::esc($telp) . "</div>\n";
        }

        $h .= "<hr class='sep'>\n";

        // ── Items ─────────────────────────────────────
        if (!empty($tmpl['show_detail_item'])) {
            foreach ($items as $item) {
                $nama  = self::esc($item['nama_layanan']);
                $harga = self::rpNum($item['harga_satuan']);
                $sub   = self::rpNum($item['subtotal']);
                $qty   = rtrim(rtrim(number_format((float)$item['jumlah'], 2), '0'), '.') . ' ' . ($item['satuan'] ?? 'kg');

                $h .= "<div>{$nama}</div>\n";
                $h .= self::tRow("  {$qty} × Rp {$harga}", "Rp {$sub}", $maxChar);
            }
        }

        $h .= "<hr class='sep'>\n";

        // ── Totals ────────────────────────────────────
        $biayaTbh = (float)($trx['biaya_tambahan'] ?? 0);
        $hasBreakdown = (float)($trx['diskon'] ?? 0) > 0 || $biayaTbh > 0;
        if (!empty($tmpl['show_subtotal']) && $hasBreakdown) {
            $h .= self::tRow('Subtotal', 'Rp ' . self::rpNum($trx['subtotal']), $maxChar);
        }
        if (!empty($tmpl['show_diskon']) && (float)($trx['diskon'] ?? 0) > 0) {
            $h .= self::tRow('Diskon', '-Rp ' . self::rpNum($trx['diskon']), $maxChar);
        }
        if ($biayaTbh > 0) {
            // Prioritas: express_tier_nama (snapshot tier) → tipe_order generic
            $tipeLabel = !empty($trx['express_tier_nama'])
                ? 'Biaya ' . $trx['express_tier_nama']
                : match($trx['tipe_order'] ?? 'reguler') {
                    'express' => 'Biaya Express',
                    'kilat'   => 'Biaya Kilat',
                    default   => 'Biaya Tambahan',
                  };
            $h .= self::tRow($tipeLabel, 'Rp ' . self::rpNum($biayaTbh), $maxChar);
        }
        if (!empty($tmpl['show_total'])) {
            $h .= "<div class='row b'>"
                . "<span class='l'>TOTAL</span>"
                . "<span class='rv'>Rp " . self::rpNum($trx['total']) . "</span>"
                . "</div>\n";
        }
        if (!empty($tmpl['show_dp']) && (float)($trx['dp'] ?? 0) > 0) {
            $h .= self::tRow('DP', 'Rp ' . self::rpNum($trx['dp']), $maxChar);
        }
        if (!empty($tmpl['show_metode_bayar']) && !empty($trx['metode_bayar'])) {
            $h .= self::tRow('Bayar', ucfirst($trx['metode_bayar']), $maxChar);
        }
        if (!empty($tmpl['show_sisa_bayar']) && (float)($trx['sisa_bayar'] ?? 0) > 0) {
            $h .= "<div class='row b'>"
                . "<span class='l'>SISA BAYAR</span>"
                . "<span class='rv'>Rp " . self::rpNum($trx['sisa_bayar']) . "</span>"
                . "</div>\n";
        }

        // ── Estimasi ──────────────────────────────────
        if (!empty($tmpl['show_estimasi']) && !empty($trx['estimasi_selesai'])) {
            $h .= "<hr class='sep'>\n";
            $h .= self::tRow('Est. Selesai', self::fmtDate($trx['estimasi_selesai'], 'd/m/Y'), $maxChar);
        }

        // ── Catatan ───────────────────────────────────
        if (!empty($tmpl['show_catatan']) && !empty($trx['catatan'])) {
            $h .= "<hr class='sep'>\n";
            $h .= "<div class='sm'>Catatan: " . self::esc($trx['catatan']) . "</div>\n";
        }

        // ── Poin ──────────────────────────────────────
        if ($poin && (!empty($tmpl['show_poin_earned']) || !empty($tmpl['show_saldo_poin']))) {
            $h .= "<hr class='sep'>\n";
            if (!empty($tmpl['show_poin_earned'])) {
                $h .= self::tRow('Poin Didapat', '+' . (int)$poin['poin'] . ' poin', $maxChar);
            }
            if (!empty($tmpl['show_saldo_poin'])) {
                $h .= self::tRow('Total Poin', (int)$poin['balance_after'] . ' poin', $maxChar);
            }
        }

        // ── FOOTER ────────────────────────────────────
        $h .= "<hr class='sep'>\n";
        if (!empty($tmpl['show_footer_ucapan']) && !empty($tmpl['footer_ucapan'])) {
            foreach (str_split($tmpl['footer_ucapan'], $maxChar) as $line) {
                $h .= "<div class='c'>" . self::esc($line) . "</div>\n";
            }
        }
        if (!empty($tmpl['show_footer_sosmed']) && !empty($tmpl['footer_sosmed'])) {
            $h .= "<div class='c sm'>" . self::esc($tmpl['footer_sosmed']) . "</div>\n";
        }
        if (!empty($tmpl['show_footer_syarat']) && !empty($tmpl['footer_syarat'])) {
            $h .= "<hr class='sep'>\n";
            $h .= "<div class='sm'>" . nl2br(self::esc($tmpl['footer_syarat'])) . "</div>\n";
        }
        if (!empty($tmpl['footer_extra'])) {
            $h .= "<div class='c sm'>" . self::esc($tmpl['footer_extra']) . "</div>\n";
        }
        if (!empty($tmpl['show_watermark'])) {
            $h .= "<div class='c b' style='opacity:.3;font-size:2em;margin-top:4px'>COPY</div>\n";
        }

        $h .= "\n<!-- print trigger -->
<script>
window.strucLoaded = true;
if (window.autoPrint) { window.print(); }
</script>
</body></html>";

        return $h;
    }

    // ══════════════════════════════════════════════════
    // PRIVATE: Render PDF A4 / A5 (formal, untuk B2B)
    // HTML output → dicetak via browser / html2pdf.js
    // ══════════════════════════════════════════════════
    public static function renderPdf(
        array  $trx,
        array  $items,
        array  $tmpl,
        ?array $pel,
        ?array $poin,
        array  $outlet,
        string $size  // 'a4' | 'a5'
    ): string {
        $isInvoice  = ($tmpl['tipe'] ?? 'retail') === 'b2b';
        $judulDoc   = $isInvoice ? 'INVOICE' : 'NOTA';
        $namaOutlet = self::esc($tmpl['nama_outlet'] ?: ($outlet['nama_outlet'] ?? 'Outlet'));

        $pageSize   = strtoupper($size);   // A4 | A5
        $margin     = $size === 'a5' ? '12mm' : '18mm';

        $h = "<!DOCTYPE html>
<html lang='id'>
<head>
<meta charset='UTF-8'>
<title>{$judulDoc} {$trx['no_order']}</title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body {
  font-family: Arial, Helvetica, sans-serif;
  font-size: " . ($size === 'a5' ? '11px' : '12px') . ";
  color: #1a1a1a;
  background: #fff;
}
.page {
  width: 100%;
  max-width: " . ($size === 'a5' ? '148mm' : '210mm') . ";
  margin: 0 auto;
  padding: {$margin};
}
/* ── Header ── */
.doc-header {
  display: flex; justify-content: space-between;
  align-items: flex-start; gap: 20px;
  padding-bottom: 12px;
  border-bottom: 2.5px solid #1F3864;
  margin-bottom: 16px;
}
.doc-logo img { max-height: 55px; max-width: 120px; }
.doc-logo-text { font-size: 1.6em; font-weight: 800; color: #1F3864; }
.doc-outlet { text-align: right; }
.doc-outlet-name { font-size: 1.15em; font-weight: 700; color: #1F3864; }
.doc-outlet small { color: #555; font-size: 0.88em; display:block; margin-top:1px; }
/* ── Title ── */
.doc-title {
  text-align: center; font-size: 1.25em; font-weight: 700;
  color: #1F3864; text-transform: uppercase;
  margin: 12px 0 14px;
  letter-spacing: 2px;
}
/* ── Meta grid ── */
.meta-grid {
  display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
  margin-bottom: 16px;
}
.meta-box {
  background: #f4f6fb; border-radius: 6px; padding: 10px 12px;
}
.meta-box .lbl {
  font-size: 0.78em; color: #777;
  text-transform: uppercase; font-weight: 600;
  margin-bottom: 4px;
}
.meta-box .val { font-size: 0.95em; }
.meta-box .val strong { font-size: 1.05em; }
/* ── Items table ── */
table { width:100%; border-collapse:collapse; margin-bottom:14px; }
thead th {
  background: #1F3864; color: #fff;
  padding: 8px 10px; font-size: 0.9em;
  text-align: left;
}
thead th.r { text-align:right; }
tbody td { padding: 7px 10px; border-bottom: 1px solid #eee; font-size:0.93em; }
tbody td.r { text-align:right; }
tbody tr:nth-child(even) td { background: #f8faff; }
/* ── Totals ── */
.totals { margin-left: auto; width: 55%; margin-bottom: 16px; }
.totals table { margin-bottom:0; }
.totals td { padding: 5px 8px; border:none; }
.totals .grand { font-size: 1.1em; font-weight: 700; color:#1F3864; }
/* ── Rekening ── */
.rekening-box {
  border: 1px solid #c8d4e8; border-radius: 6px;
  padding: 12px 14px; margin-bottom: 16px;
  background: #f0f4fb;
}
.rekening-box .title { font-weight: 700; color:#1F3864; margin-bottom:6px; }
.rekening-box table td { padding:2px 10px 2px 0; border:none; }
/* ── Footer ── */
.doc-footer {
  text-align: center; color: #777; font-size: 0.82em;
  border-top: 1px solid #ddd; padding-top: 10px; margin-top: 16px;
}
/* ── Watermark ── */
.watermark {
  position: fixed; top: 50%; left: 50%;
  transform: translate(-50%,-50%) rotate(-35deg);
  font-size: 100px; font-weight: 900;
  color: rgba(0,0,0,.07); pointer-events:none; z-index:999;
}
@media print {
  @page { size: {$pageSize}; margin: 0; }
  body { margin: 0; }
  .no-print { display:none !important; }
}
</style>
</head>
<body>
" . (!empty($tmpl['show_watermark']) ? "<div class='watermark'>COPY</div>" : "") . "
<div class='page'>

<!-- ── HEADER ── -->
<div class='doc-header'>
  <div class='doc-logo'>\n";

        if (!empty($tmpl['show_logo']) && !empty($tmpl['logo_url'])) {
            $h .= "    <img src='" . self::esc($tmpl['logo_url']) . "' alt='logo'>\n";
        } else {
            $h .= "    <div class='doc-logo-text'>" . mb_substr($namaOutlet, 0, 1) . "</div>\n";
        }

        $h .= "  </div>
  <div class='doc-outlet'>
    <div class='doc-outlet-name'>{$namaOutlet}</div>\n";

        if (!empty($tmpl['tagline'])) {
            $h .= "    <small>" . self::esc($tmpl['tagline']) . "</small>\n";
        }
        if (!empty($tmpl['show_alamat'])) {
            $alamat = $tmpl['alamat_override'] ?: trim(($outlet['alamat'] ?? '') . ($outlet['kota'] ? ', ' . $outlet['kota'] : ''));
            if ($alamat) $h .= "    <small>" . self::esc($alamat) . "</small>\n";
        }
        if (!empty($tmpl['show_telp']) && !empty($outlet['telepon'])) {
            $h .= "    <small>Telp: " . self::esc($outlet['telepon']) . "</small>\n";
        }

        $h .= "  </div>
</div><!-- /doc-header -->

<div class='doc-title'>{$judulDoc}</div>

<!-- ── META GRID ── -->
<div class='meta-grid'>
  <div class='meta-box'>
    <div class='lbl'>Kepada</div>
    <div class='val'><strong>" . self::esc($pel['nama'] ?? $trx['nama_pelanggan'] ?? '-') . "</strong></div>\n";

        if (!empty($tmpl['show_telp_pelanggan']) && !empty($pel['telepon'])) {
            $h .= "    <div class='val'>" . self::esc($pel['telepon']) . "</div>\n";
        }
        if (!empty($tmpl['show_alamat_pelanggan']) && !empty($pel['alamat'])) {
            $h .= "    <div class='val'>" . self::esc($pel['alamat']) . "</div>\n";
        }

        $h .= "  </div>
  <div class='meta-box'>
    <div class='lbl'>Detail Dokumen</div>
    <div class='val'>No: <strong>" . self::esc($trx['no_order']) . "</strong></div>
    <div class='val'>Tanggal: " . self::fmtDate($trx['created_at'] ?: $trx['tanggal'], 'd F Y') . "</div>\n";

        if (!empty($tmpl['show_jatuh_tempo']) && !empty($trx['jatuh_tempo'])) {
            $h .= "    <div class='val'>Jatuh Tempo: " . self::fmtDate($trx['jatuh_tempo'], 'd F Y') . "</div>\n";
        }
        if (!empty($tmpl['show_nama_kasir']) && !empty($trx['kasir_nama'])) {
            $h .= "    <div class='val'>Dibuat oleh: " . self::esc($trx['kasir_nama']) . "</div>\n";
        }

        $h .= "  </div>
</div><!-- /meta-grid -->

<!-- ── ITEMS TABLE ── -->
<table>
  <thead>
    <tr>
      <th>#</th>
      <th>Layanan</th>
      <th class='r'>Qty</th>
      <th class='r'>Harga Satuan</th>
      <th class='r'>Subtotal</th>
    </tr>
  </thead>
  <tbody>\n";

        $no = 1;
        foreach ($items as $item) {
            $qty = rtrim(rtrim(number_format((float)$item['jumlah'], 2), '0'), '.') . ' ' . ($item['satuan'] ?? 'kg');
            $h .= "    <tr>
      <td>{$no}</td>
      <td>" . self::esc($item['nama_layanan']) . "</td>
      <td class='r'>{$qty}</td>
      <td class='r'>Rp " . self::rpNum($item['harga_satuan']) . "</td>
      <td class='r'>Rp " . self::rpNum($item['subtotal']) . "</td>
    </tr>\n";
            $no++;
        }

        $h .= "  </tbody>
</table>

<!-- ── TOTALS ── -->
<div class='totals'>
<table>\n";

        $biayaTbhPdf = (float)($trx['biaya_tambahan'] ?? 0);
        $hasBreakdownPdf = (float)($trx['diskon'] ?? 0) > 0 || $biayaTbhPdf > 0;
        if (!empty($tmpl['show_subtotal']) && $hasBreakdownPdf) {
            $h .= "  <tr><td>Subtotal</td><td class='r'>Rp " . self::rpNum($trx['subtotal']) . "</td></tr>\n";
        }
        if (!empty($tmpl['show_diskon']) && (float)($trx['diskon'] ?? 0) > 0) {
            $h .= "  <tr><td>Diskon</td><td class='r'>−Rp " . self::rpNum($trx['diskon']) . "</td></tr>\n";
        }
        if ($biayaTbhPdf > 0) {
            $tipeLabel = !empty($trx['express_tier_nama'])
                ? 'Biaya ' . $trx['express_tier_nama']
                : match($trx['tipe_order'] ?? 'reguler') {
                    'express' => 'Biaya Express',
                    'kilat'   => 'Biaya Kilat',
                    default   => 'Biaya Tambahan',
                  };
            $h .= "  <tr><td>" . htmlspecialchars($tipeLabel) . "</td><td class='r'>+Rp " . self::rpNum($biayaTbhPdf) . "</td></tr>\n";
        }
        if (!empty($tmpl['show_total'])) {
            $h .= "  <tr class='grand'><td>TOTAL</td><td class='r'>Rp " . self::rpNum($trx['total']) . "</td></tr>\n";
        }
        if (!empty($tmpl['show_dp']) && (float)($trx['dp'] ?? 0) > 0) {
            $h .= "  <tr><td>DP</td><td class='r'>Rp " . self::rpNum($trx['dp']) . "</td></tr>\n";
        }
        if (!empty($tmpl['show_metode_bayar']) && !empty($trx['metode_bayar'])) {
            $h .= "  <tr><td>Metode Bayar</td><td class='r'>" . self::esc(ucfirst($trx['metode_bayar'])) . "</td></tr>\n";
        }
        if (!empty($tmpl['show_sisa_bayar']) && (float)($trx['sisa_bayar'] ?? 0) > 0) {
            $h .= "  <tr class='grand'><td>SISA BAYAR</td><td class='r'>Rp " . self::rpNum($trx['sisa_bayar']) . "</td></tr>\n";
        }

        $h .= "</table>
</div><!-- /totals -->\n";

        // ── Rekening B2B ──────────────────────────────
        if (!empty($tmpl['show_rekening']) && !empty($tmpl['rekening_bank'])) {
            $h .= "<div class='rekening-box'>
  <div class='title'>💳 Informasi Transfer Pembayaran</div>
  <table>
    <tr><td>Bank</td><td><strong>" . self::esc($tmpl['rekening_bank']) . "</strong></td></tr>
    <tr><td>No. Rekening</td><td><strong>" . self::esc($tmpl['rekening_nomor']) . "</strong></td></tr>
    <tr><td>Atas Nama</td><td><strong>" . self::esc($tmpl['rekening_atas_nama']) . "</strong></td></tr>
  </table>
</div>\n";
        }

        // ── Catatan ───────────────────────────────────
        if (!empty($tmpl['show_catatan']) && !empty($trx['catatan'])) {
            $h .= "<div style='margin-bottom:12px;font-size:0.9em;color:#555;'>
  <strong>Catatan:</strong> " . self::esc($trx['catatan']) . "
</div>\n";
        }

        // ── Footer ────────────────────────────────────
        $h .= "<div class='doc-footer'>\n";
        if (!empty($tmpl['show_footer_ucapan']) && !empty($tmpl['footer_ucapan'])) {
            $h .= "  <div>" . self::esc($tmpl['footer_ucapan']) . "</div>\n";
        }
        if (!empty($tmpl['show_footer_sosmed']) && !empty($tmpl['footer_sosmed'])) {
            $h .= "  <div>" . self::esc($tmpl['footer_sosmed']) . "</div>\n";
        }
        if (!empty($tmpl['show_footer_syarat']) && !empty($tmpl['footer_syarat'])) {
            $h .= "  <hr style='margin:8px 0;border-color:#ddd'>\n";
            $h .= "  <div>" . nl2br(self::esc($tmpl['footer_syarat'])) . "</div>\n";
        }
        $h .= "</div><!-- /doc-footer -->

</div><!-- /page -->
<script>
window.strucLoaded = true;
if (window.autoPrint) { window.print(); }
</script>
</body></html>";

        return $h;
    }

    // ══════════════════════════════════════════════════
    // PRIVATE: Default template (fallback)
    // ══════════════════════════════════════════════════
    public static function defaultTemplate(string $tipe): array
    {
        $isB2b = $tipe === 'b2b';
        return [
            'tipe'                   => $tipe,
            'format'                 => $isB2b ? 'a4' : 'thermal_80',
            'show_logo'              => 0,
            'logo_url'               => null,
            'logo_size'              => 'medium',
            'nama_outlet'            => null,
            'tagline'                => null,
            'show_alamat'            => 1,
            'alamat_override'        => null,
            'show_telp'              => 1,
            'show_email'             => 0,
            'header_extra'           => null,
            'show_no_order'          => 1,
            'show_tanggal'           => 1,
            'show_nama_kasir'        => 1,
            'show_nama_pelanggan'    => 1,
            'show_telp_pelanggan'    => 0,
            'show_alamat_pelanggan'  => $isB2b ? 1 : 0,
            'show_detail_item'       => 1,
            'show_subtotal'          => 1,
            'show_diskon'            => 1,
            'show_dp'                => 1,
            'show_total'             => 1,
            'show_metode_bayar'      => 1,
            'show_sisa_bayar'        => 1,
            'show_estimasi'          => $isB2b ? 0 : 1,
            'show_catatan'           => 1,
            'show_poin_earned'       => $isB2b ? 0 : 1,
            'show_saldo_poin'        => $isB2b ? 0 : 1,
            'show_periode_invoice'   => $isB2b ? 1 : 0,
            'show_jatuh_tempo'       => $isB2b ? 1 : 0,
            'show_rekening'          => $isB2b ? 1 : 0,
            'rekening_bank'          => null,
            'rekening_nomor'         => null,
            'rekening_atas_nama'     => null,
            'footer_ucapan'          => $isB2b
                ? 'Terima kasih atas kepercayaan Anda. Mohon transfer sebelum jatuh tempo.'
                : 'Terima kasih telah mempercayakan laundry Anda kepada kami!',
            'show_footer_ucapan'     => 1,
            'footer_syarat'          => null,
            'show_footer_syarat'     => 0,
            'footer_sosmed'          => null,
            'show_footer_sosmed'     => 0,
            'show_qr_wa'             => 0,
            'footer_extra'           => null,
            'font_size'              => 'normal',
            'show_border'            => 1,
            'show_watermark'         => 0,
        ];
    }

    // ══════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════

    /** Thermal: baris label-nilai rata kanan */
    private static function tRow(string $label, string $value, int $maxChar): string
    {
        $label = self::esc($label);
        $value = self::esc($value);
        return "<div class='row'><span class='l'>{$label}</span><span class='rv'>{$value}</span></div>\n";
    }

    /** Format angka Rupiah tanpa prefix "Rp" */
    private static function rpNum(float|int|string $n): string
    {
        return number_format((float)$n, 0, ',', '.');
    }

    /** Format tanggal dari string datetime/date */
    private static function fmtDate(string $s, string $fmt = 'd/m/Y H:i'): string
    {
        if (!$s || $s === '0000-00-00' || $s === '0000-00-00 00:00:00') return '-';
        try {
            $dt = new DateTime($s);
            // Lokalisasi bulan Indonesia untuk format 'd F Y'
            if (str_contains($fmt, 'F')) {
                $bulan = ['January'=>'Januari','February'=>'Februari','March'=>'Maret',
                    'April'=>'April','May'=>'Mei','June'=>'Juni','July'=>'Juli',
                    'August'=>'Agustus','September'=>'September','October'=>'Oktober',
                    'November'=>'November','December'=>'Desember'];
                return str_replace(array_keys($bulan), array_values($bulan), $dt->format($fmt));
            }
            return $dt->format($fmt);
        } catch (Throwable) {
            return $s;
        }
    }

    /** HTML-safe escape */
    private static function esc(mixed $s): string
    {
        return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

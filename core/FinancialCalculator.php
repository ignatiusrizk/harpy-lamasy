<?php
// core/FinancialCalculator.php
// Kalkulasi laporan keuangan SAK EMKM (Opsi A – Lightweight).
// Semua kalkulasi dilakukan real-time dari data transaksi + input manual.

class FinancialCalculator
{
    // ── Safe outlet filter (int-cast, SQL-injection proof) ───
    private static function o(?int $oid): string
    {
        return $oid ? " AND outlet_id = " . (int)$oid : "";
    }

    // ── Penyusutan per bulan (PHP, no GENERATED COLUMN) ──────
    private static function penyusutanBulan(array $a): int
    {
        if ($a['umur_ekonomis'] <= 0) return 0;
        if ($a['metode_penyusutan'] === 'garis_lurus') {
            return (int) round(
                ($a['nilai_perolehan'] - $a['nilai_sisa']) / $a['umur_ekonomis']
            );
        }
        // saldo_menurun: 20% p.a. flat per bulan (simplified)
        return (int) round($a['nilai_perolehan'] * 0.20 / 12);
    }

    // ════════════════════════════════════════════════════════════
    // LABA RUGI
    // ════════════════════════════════════════════════════════════
    public static function labaRugi(
        int    $tenantId,
        ?int   $outletId,
        string $periode   // 'YYYY-MM'
    ): array {
        $db    = Database::get();
        $start = $periode . '-01';
        $end   = date('Y-m-t', strtotime($start));
        $of    = self::o($outletId);

        // ── PENDAPATAN ──────────────────────────────────────
        // Kiloan & reguler (bukan B2B bulanan, bukan drop point)
        $pendapatanKiloan = 0;
        try {
            $s = $db->prepare("
                SELECT COALESCE(SUM(t.total), 0)
                FROM hl_transaksi t
                LEFT JOIN hl_pelanggan p ON p.id = t.pelanggan_id
                WHERE t.tenant_id = ?
                  AND t.status_bayar = 'lunas'
                  AND DATE(t.tanggal) BETWEEN ? AND ?
                  AND t.drop_point_id IS NULL
                  AND (p.id IS NULL OR p.metode_bayar != 'bulanan')
                  $of
            ");
            $s->execute([$tenantId, $start, $end]);
            $pendapatanKiloan = (int)$s->fetchColumn();
        } catch (Throwable) {}

        // B2B (pelanggan metode_bayar = bulanan)
        $pendapatanB2b = 0;
        try {
            $s = $db->prepare("
                SELECT COALESCE(SUM(t.total), 0)
                FROM hl_transaksi t
                JOIN hl_pelanggan p ON p.id = t.pelanggan_id
                WHERE t.tenant_id = ?
                  AND t.status_bayar = 'lunas'
                  AND DATE(t.tanggal) BETWEEN ? AND ?
                  AND p.metode_bayar = 'bulanan'
                  AND t.drop_point_id IS NULL
                  $of
            ");
            $s->execute([$tenantId, $start, $end]);
            $pendapatanB2b = (int)$s->fetchColumn();
        } catch (Throwable) {}

        // Drop Point
        $pendapatanDp = 0;
        try {
            $s = $db->prepare("
                SELECT COALESCE(SUM(total), 0)
                FROM hl_transaksi
                WHERE tenant_id = ?
                  AND status_bayar = 'lunas'
                  AND DATE(tanggal) BETWEEN ? AND ?
                  AND drop_point_id IS NOT NULL
                  $of
            ");
            $s->execute([$tenantId, $start, $end]);
            $pendapatanDp = (int)$s->fetchColumn();
        } catch (Throwable) {}

        // Pendapatan lain (jurnal manual tipe=lainnya, kredit)
        $pendapatanLain = 0;
        try {
            $s = $db->prepare("
                SELECT COALESCE(SUM(jumlah), 0)
                FROM hl_jurnal_manual
                WHERE tenant_id = ?
                  AND periode = ?
                  AND tipe = 'lainnya'
                  AND arah = 'kredit'
                  $of
            ");
            $s->execute([$tenantId, $periode]);
            $pendapatanLain = (int)$s->fetchColumn();
        } catch (Throwable) {}

        $totalPendapatan = $pendapatanKiloan + $pendapatanB2b + $pendapatanDp + $pendapatanLain;

        // ── BEBAN ───────────────────────────────────────────
        // Gaji (dari hl_gaji, sudah pakai `bulan` & `status`)
        $bebanGaji = 0;
        try {
            $s = $db->prepare("
                SELECT COALESCE(SUM(total), 0)
                FROM hl_gaji
                WHERE tenant_id = ?
                  AND bulan = ?
                  AND status = 'dibayar'
                  $of
            ");
            $s->execute([$tenantId, $periode]);
            $bebanGaji = (int)$s->fetchColumn();
        } catch (Throwable) {}

        // Komisi mitra drop point (dari hl_komisi_rekap)
        $bebanKomisi = 0;
        try {
            $s = $db->prepare("
                SELECT COALESCE(SUM(total_komisi), 0)
                FROM hl_komisi_rekap
                WHERE tenant_id = ?
                  AND periode_start >= ?
                  AND periode_end   <= ?
                  $of
            ");
            $s->execute([$tenantId, $start, $end]);
            $bebanKomisi = (int)$s->fetchColumn();
        } catch (Throwable) {}

        // Kas keluar operasional (selain gaji — gaji ada di hl_gaji)
        $bebanKasKeluar   = 0;
        $detailKasKeluar  = [];
        try {
            $s = $db->prepare("
                SELECT kategori, COALESCE(SUM(jumlah), 0) total
                FROM hl_kas
                WHERE tenant_id = ?
                  AND tipe = 'keluar'
                  AND DATE(tanggal) BETWEEN ? AND ?
                  AND kategori NOT IN ('gaji', 'komisi_mitra', 'komisi mitra')
                  $of
                GROUP BY kategori
            ");
            $s->execute([$tenantId, $start, $end]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $detailKasKeluar[$row['kategori']] = (int)$row['total'];
                $bebanKasKeluar += (int)$row['total'];
            }
        } catch (Throwable) {}

        // Penyusutan aset tetap
        $bebanPenyusutan = self::hitungPenyusutan($tenantId, $outletId, $periode);

        // Bunga pinjaman
        $bebanBunga = self::hitungBunga($tenantId, $outletId, $periode);

        // Beban manual (jurnal: tipe=beban_manual, arah=debit)
        $bebanManual      = [];
        $totalBebanManual = 0;
        try {
            $s = $db->prepare("
                SELECT c.nama, COALESCE(SUM(j.jumlah), 0) total
                FROM hl_jurnal_manual j
                JOIN hl_coa c ON c.id = j.coa_id AND c.tenant_id = j.tenant_id
                WHERE j.tenant_id = ?
                  AND j.periode   = ?
                  AND j.tipe      = 'beban_manual'
                  AND j.arah      = 'debit'
                  $of
                GROUP BY c.id, c.nama
            ");
            $s->execute([$tenantId, $periode]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $bebanManual[$row['nama']] = (int)$row['total'];
                $totalBebanManual += (int)$row['total'];
            }
        } catch (Throwable) {}

        $totalBeban = $bebanGaji + $bebanKomisi + $bebanKasKeluar
                    + $bebanPenyusutan + $bebanBunga + $totalBebanManual;

        $labaBersih = $totalPendapatan - $totalBeban;

        return [
            'periode'           => $periode,
            'pendapatan'        => [
                'kiloan'     => $pendapatanKiloan,
                'b2b'        => $pendapatanB2b,
                'drop_point' => $pendapatanDp,
                'lain'       => $pendapatanLain,
            ],
            'total_pendapatan'  => $totalPendapatan,
            'beban'             => [
                'gaji'             => $bebanGaji,
                'komisi_mitra'     => $bebanKomisi,
                'operasional_kas'  => $bebanKasKeluar,
                'detail_kas'       => $detailKasKeluar,
                'penyusutan'       => $bebanPenyusutan,
                'bunga'            => $bebanBunga,
                'manual'           => $bebanManual,
                'total_manual'     => $totalBebanManual,
            ],
            'total_beban'       => $totalBeban,
            'laba_bersih'       => $labaBersih,
            'margin'            => $totalPendapatan > 0
                ? round($labaBersih / $totalPendapatan * 100, 1) : 0,
        ];
    }

    // ════════════════════════════════════════════════════════════
    // NERACA
    // ════════════════════════════════════════════════════════════
    public static function neraca(
        int    $tenantId,
        ?int   $outletId,
        string $periode
    ): array {
        $db      = Database::get();
        $endDate = date('Y-m-t', strtotime($periode . '-01'));
        $of      = self::o($outletId);

        // ── ASET LANCAR ──────────────────────────────────
        $kasTunai = 0;
        try {
            $masuk  = (int) $db->prepare("SELECT COALESCE(SUM(jumlah),0) FROM hl_kas
                WHERE tenant_id=? AND tipe='masuk' AND DATE(tanggal)<=? $of")
                ->execute([$tenantId, $endDate]) && false ?: 0;
            // manual execute + fetchColumn
            $s = $db->prepare("SELECT COALESCE(SUM(jumlah),0) FROM hl_kas WHERE tenant_id=? AND tipe='masuk' AND DATE(tanggal)<=? $of");
            $s->execute([$tenantId, $endDate]);
            $masuk = (int)$s->fetchColumn();

            $s = $db->prepare("SELECT COALESCE(SUM(jumlah),0) FROM hl_kas WHERE tenant_id=? AND tipe='keluar' AND DATE(tanggal)<=? $of");
            $s->execute([$tenantId, $endDate]);
            $keluar = (int)$s->fetchColumn();

            $kasTunai = $masuk - $keluar;
        } catch (Throwable) {}

        $kasBank  = self::hitungSaldoKasBank($tenantId, $outletId, $endDate);
        $piutang  = 0;
        try {
            $s = $db->prepare("SELECT COALESCE(SUM(sisa_tagihan),0) FROM hl_piutang
                WHERE tenant_id=? AND status NOT IN ('lunas') $of");
            $s->execute([$tenantId]);
            $piutang = (int)$s->fetchColumn();
        } catch (Throwable) {}

        $persediaan  = self::getSaldoManual($tenantId, $outletId, 'persediaan',  $endDate);
        $biayaDimuka = self::getSaldoManual($tenantId, $outletId, 'biaya_dimuka', $endDate);

        // Jangan floor kas ke 0 — saldo kas negatif (kas keluar > masuk) harus
        // tetap dihitung apa adanya supaya neraca tidak timpang. Floor ke 0
        // sebelumnya bikin selisih = besarnya saldo kas negatif.
        $totalAsetLancar = $kasTunai + $kasBank + $piutang + $persediaan + $biayaDimuka;

        // Aset tetap (nilai buku)
        $asetTetap = self::hitungNilaiBukuAset($tenantId, $outletId, $endDate);
        $totalAset = $totalAsetLancar + $asetTetap['total_nilai_buku'];

        // ── LIABILITAS ───────────────────────────────────
        $hutangUsaha = self::getSaldoManual($tenantId, $outletId, 'hutang_usaha_manual', $endDate);

        $pinjaman = [];
        $totalPinjaman = 0;
        $totalCicilan  = 0;
        try {
            $s = $db->prepare("SELECT nama, pokok_pinjaman, saldo_awal, saldo_terbayar,
                                      cicilan_per_bulan, kreditur
                               FROM hl_liabilitas
                               WHERE tenant_id=? AND status='aktif' $of");
            $s->execute([$tenantId]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $saldoHutang = (int)$p['saldo_awal'] - (int)$p['saldo_terbayar'];
                $p['saldo_hutang'] = $saldoHutang;
                $pinjaman[] = $p;
                $totalPinjaman += $saldoHutang;
                $totalCicilan  += (int)$p['cicilan_per_bulan'];
            }
        } catch (Throwable) {}

        // Cicilan jatuh tempo = 12 bulan ke depan (current portion)
        $cicilanLancar = min($totalCicilan * 12, $totalPinjaman);
        $pinjamanPanjang = max(0, $totalPinjaman - $cicilanLancar);

        $totalLiabLancar = $hutangUsaha + $cicilanLancar;
        $totalLiab       = $totalLiabLancar + $pinjamanPanjang;

        // ── EKUITAS ──────────────────────────────────────
        $modalDisetor = self::getSaldoManual($tenantId, $outletId, 'modal_disetor', $endDate);
        $prive        = self::getSaldoManual($tenantId, $outletId, 'prive',         $endDate);
        $labaDitahan  = self::hitungLabaDitahan($tenantId, $outletId, $periode);
        $labaPeriode  = self::labaRugi($tenantId, $outletId, $periode)['laba_bersih'];
        $ekuitasReal  = $modalDisetor - $prive + $labaDitahan + $labaPeriode;

        // Neraca ini disusun (constructed) dari beberapa sumber, bukan ledger
        // double-entry penuh. Aset (kas/piutang/aset tetap) & liabilitas bisa
        // diinput tanpa entry pasangannya, jadi sisi tidak otomatis seimbang.
        // Solusi standar tool akuntansi ringan (Opening Balance Equity): selisih
        // dimasukkan sebagai "Modal Awal / Penyesuaian" supaya neraca seimbang,
        // sekaligus jadi sinyal kalau angkanya besar (ada entry yang belum lengkap).
        $penyesuaian  = $totalAset - $totalLiab - $ekuitasReal;
        $totalEkuitas = $ekuitasReal + $penyesuaian;

        $totalLiabEkuitas = $totalLiab + $totalEkuitas;
        $selisih = $totalAset - $totalLiabEkuitas; // ≈ 0 setelah penyesuaian

        return [
            'periode'       => $periode,
            'aset'          => [
                'kas_tunai'          => $kasTunai,
                'kas_bank'           => $kasBank,
                'piutang'            => $piutang,
                'persediaan'         => $persediaan,
                'biaya_dimuka'       => $biayaDimuka,
                'total_aset_lancar'  => $totalAsetLancar,
                'aset_tetap_detail'  => $asetTetap['detail'],
                'aset_tetap_buku'    => $asetTetap['total_nilai_buku'],
                'total_aset'         => $totalAset,
            ],
            'liabilitas'    => [
                'hutang_usaha'          => $hutangUsaha,
                'pinjaman_detail'       => $pinjaman,
                'cicilan_lancar'        => $cicilanLancar,
                'pinjaman_jangka_panjang' => $pinjamanPanjang,
                'total_liabilitas_lancar' => $totalLiabLancar,
                'total_liabilitas'      => $totalLiab,
            ],
            'ekuitas'       => [
                'modal_disetor'  => $modalDisetor,
                'prive'          => $prive,
                'laba_ditahan'   => $labaDitahan,
                'laba_periode'   => $labaPeriode,
                'penyesuaian'    => $penyesuaian,
                'total_ekuitas'  => $totalEkuitas,
            ],
            'total_liab_ekuitas' => $totalLiabEkuitas,
            'is_balanced'        => abs($selisih) < 1000,
            'selisih'            => $selisih,
            // Sinyal ke UI: kalau |penyesuaian| besar, ada entry belum lengkap
            'penyesuaian_warning' => abs($penyesuaian) >= 1000,
        ];
    }

    // ════════════════════════════════════════════════════════════
    // ARUS KAS
    // ════════════════════════════════════════════════════════════
    public static function arusKas(
        int    $tenantId,
        ?int   $outletId,
        string $periode
    ): array {
        $db    = Database::get();
        $start = $periode . '-01';
        $end   = date('Y-m-t', strtotime($start));
        $of    = self::o($outletId);

        // OPERASIONAL
        $penerimaanPelanggan = 0;
        try {
            $s = $db->prepare("SELECT COALESCE(SUM(jumlah),0) FROM hl_kas
                WHERE tenant_id=? AND tipe='masuk' AND DATE(tanggal) BETWEEN ? AND ? $of");
            $s->execute([$tenantId, $start, $end]);
            $penerimaanPelanggan = (int)$s->fetchColumn();
        } catch (Throwable) {}

        $pembayaranOp = 0;
        try {
            $s = $db->prepare("SELECT COALESCE(SUM(jumlah),0) FROM hl_kas
                WHERE tenant_id=? AND tipe='keluar' AND DATE(tanggal) BETWEEN ? AND ? $of");
            $s->execute([$tenantId, $start, $end]);
            $pembayaranOp = (int)$s->fetchColumn();
        } catch (Throwable) {}

        $netOperasional = $penerimaanPelanggan - $pembayaranOp;

        // INVESTASI
        $pembelianAset = 0;
        try {
            $s = $db->prepare("SELECT COALESCE(SUM(nilai_perolehan),0) FROM hl_aset_tetap
                WHERE tenant_id=? AND DATE(tanggal_perolehan) BETWEEN ? AND ? $of");
            $s->execute([$tenantId, $start, $end]);
            $pembelianAset = (int)$s->fetchColumn();
        } catch (Throwable) {}

        $penjualanAset = 0;
        try {
            $s = $db->prepare("SELECT COALESCE(SUM(nilai_jual),0) FROM hl_aset_tetap
                WHERE tenant_id=? AND status='dijual' AND DATE(tanggal_dispose) BETWEEN ? AND ? $of");
            $s->execute([$tenantId, $start, $end]);
            $penjualanAset = (int)$s->fetchColumn();
        } catch (Throwable) {}

        $netInvestasi = $penjualanAset - $pembelianAset;

        // PENDANAAN (dari jurnal manual)
        $penerimaPinjaman  = self::sumJurnal($tenantId, $outletId, $periode, 'penerimaan_pinjaman', 'kredit');
        $pembayaranCicilan = self::sumJurnal($tenantId, $outletId, $periode, 'pembayaran_hutang',   'debit');
        $setorModal        = self::sumJurnal($tenantId, $outletId, $periode, 'modal_disetor',        'kredit');
        $prive             = self::sumJurnal($tenantId, $outletId, $periode, 'prive',                'debit');
        $netPendanaan      = $penerimaPinjaman + $setorModal - $pembayaranCicilan - $prive;

        return [
            'periode'      => $periode,
            'operasional'  => [
                'penerimaan_pelanggan'   => $penerimaanPelanggan,
                'pembayaran_operasional' => $pembayaranOp,
                'net'                    => $netOperasional,
            ],
            'investasi'    => [
                'pembelian_aset' => $pembelianAset,
                'penjualan_aset' => $penjualanAset,
                'net'            => $netInvestasi,
            ],
            'pendanaan'    => [
                'penerimaan_pinjaman' => $penerimaPinjaman,
                'pembayaran_cicilan'  => $pembayaranCicilan,
                'setor_modal'         => $setorModal,
                'prive'               => $prive,
                'net'                 => $netPendanaan,
            ],
            'kenaikan_kas' => $netOperasional + $netInvestasi + $netPendanaan,
        ];
    }

    // ════════════════════════════════════════════════════════════
    // RASIO KEUANGAN
    // ════════════════════════════════════════════════════════════
    public static function rasioKeuangan(
        int    $tenantId,
        ?int   $outletId,
        string $periode
    ): array {
        $lr = self::labaRugi($tenantId, $outletId, $periode);
        $nr = self::neraca($tenantId, $outletId, $periode);

        $pendapatan   = $lr['total_pendapatan'];
        $labaBersih   = $lr['laba_bersih'];
        $totalAset    = $nr['aset']['total_aset'];
        $asetLancar   = $nr['aset']['total_aset_lancar'];
        $liabLancar   = $nr['liabilitas']['total_liabilitas_lancar'];
        $totalLiab    = $nr['liabilitas']['total_liabilitas'];
        $totalEkuitas = $nr['ekuitas']['total_ekuitas'];
        $kas          = $nr['aset']['kas_tunai'] + $nr['aset']['kas_bank'];

        $bep = self::hitungBEP($lr['beban'], $pendapatan);

        return [
            'periode' => $periode,

            // Profitabilitas
            'net_profit_margin' => $pendapatan > 0
                ? round($labaBersih / $pendapatan * 100, 1) : 0,
            'roa' => $totalAset > 0
                ? round($labaBersih / $totalAset * 100, 1) : 0,
            'roe' => $totalEkuitas > 0
                ? round($labaBersih / $totalEkuitas * 100, 1) : 0,

            // Likuiditas
            'current_ratio' => $liabLancar > 0
                ? round($asetLancar / $liabLancar, 2) : null,
            'cash_ratio' => $liabLancar > 0
                ? round($kas / $liabLancar, 2) : null,

            // Solvabilitas
            'debt_to_equity' => $totalEkuitas > 0
                ? round($totalLiab / $totalEkuitas, 2) : null,
            'debt_ratio' => $totalAset > 0
                ? round($totalLiab / $totalAset, 2) : null,

            // Aktivitas
            'asset_turnover' => $totalAset > 0
                ? round($pendapatan / $totalAset, 2) : null,

            // BEP
            'bep_rupiah' => $bep,
            'bep_coverage' => $bep > 0 && $pendapatan > 0
                ? round($pendapatan / $bep * 100, 1) : null,

            // Context untuk benchmark
            'omset'       => $pendapatan,
            'laba_bersih' => $labaBersih,
        ];
    }

    // ════════════════════════════════════════════════════════════
    // HELPER: Penyusutan bulan ini
    // ════════════════════════════════════════════════════════════
    public static function hitungPenyusutan(
        int $tenantId, ?int $outletId, string $periode
    ): int {
        $of = self::o($outletId);
        $periodeDate = $periode . '-01';
        $total = 0;
        try {
            $s = Database::get()->prepare("
                SELECT nilai_perolehan, nilai_sisa, umur_ekonomis,
                       metode_penyusutan, tanggal_perolehan
                FROM hl_aset_tetap
                WHERE tenant_id=? AND status='aktif'
                  AND tanggal_perolehan <= ?
                  $of
            ");
            $s->execute([$tenantId, $periodeDate]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $bulanPakai = (int) floor(
                    (strtotime($periodeDate) - strtotime($a['tanggal_perolehan']))
                    / (30.44 * 86400)
                );
                if ($bulanPakai < $a['umur_ekonomis']) {
                    $total += self::penyusutanBulan($a);
                }
            }
        } catch (Throwable) {}
        return $total;
    }

    // ════════════════════════════════════════════════════════════
    // HELPER: Nilai buku semua aset tetap s/d tanggal
    // ════════════════════════════════════════════════════════════
    public static function hitungNilaiBukuAset(
        int $tenantId, ?int $outletId, string $endDate
    ): array {
        $of = self::o($outletId);
        $detail = [];
        $totalBuku = 0;
        try {
            $s = Database::get()->prepare("
                SELECT id, nama, nilai_perolehan, nilai_sisa,
                       umur_ekonomis, metode_penyusutan, tanggal_perolehan
                FROM hl_aset_tetap
                WHERE tenant_id=? AND status='aktif'
                  AND tanggal_perolehan <= ?
                  $of
                ORDER BY tanggal_perolehan
            ");
            $s->execute([$tenantId, $endDate]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $bulanPakai = (int) min(
                    floor((strtotime($endDate) - strtotime($a['tanggal_perolehan'])) / (30.44 * 86400)),
                    $a['umur_ekonomis']
                );
                $dep = self::penyusutanBulan($a) * $bulanPakai;
                $buku = max((int)$a['nilai_sisa'], (int)$a['nilai_perolehan'] - $dep);
                $detail[] = [
                    'id'               => $a['id'],
                    'nama'             => $a['nama'],
                    'nilai_perolehan'  => (int)$a['nilai_perolehan'],
                    'akum_penyusutan'  => $dep,
                    'nilai_buku'       => $buku,
                    'umur_terpakai'    => $bulanPakai,
                    'umur_ekonomis'    => (int)$a['umur_ekonomis'],
                    'penyusutan_bulan' => self::penyusutanBulan($a),
                ];
                $totalBuku += $buku;
            }
        } catch (Throwable) {}
        return ['detail' => $detail, 'total_nilai_buku' => $totalBuku];
    }

    // ════════════════════════════════════════════════════════════
    // HELPER: Saldo kas bank (rekening manual)
    // ════════════════════════════════════════════════════════════
    private static function hitungSaldoKasBank(
        int $tenantId, ?int $outletId, string $endDate
    ): int {
        $of = self::o($outletId);
        $total = 0;
        try {
            $banks = Database::get()->prepare("
                SELECT id, saldo_awal, saldo_awal_tanggal
                FROM hl_kas_bank
                WHERE tenant_id=? AND is_active=1 $of
            ");
            $banks->execute([$tenantId]);
            foreach ($banks->fetchAll(PDO::FETCH_ASSOC) as $b) {
                // Cari saldo_akhir terakhir s/d endDate
                $sAkhir = Database::get()->prepare("
                    SELECT saldo_akhir FROM hl_kas_bank_mutasi
                    WHERE kas_bank_id=? AND saldo_akhir IS NOT NULL
                      AND DATE(tanggal) <= ?
                    ORDER BY tanggal DESC LIMIT 1
                ");
                $sAkhir->execute([$b['id'], $endDate]);
                $lastSaldo = $sAkhir->fetchColumn();

                if ($lastSaldo !== false) {
                    // Ada snapshot saldo_akhir: pakai itu + mutasi setelahnya
                    $sAkhirTgl = Database::get()->prepare("
                        SELECT tanggal FROM hl_kas_bank_mutasi
                        WHERE kas_bank_id=? AND saldo_akhir IS NOT NULL
                          AND DATE(tanggal) <= ?
                        ORDER BY tanggal DESC LIMIT 1
                    ");
                    $sAkhirTgl->execute([$b['id'], $endDate]);
                    $snapTgl = $sAkhirTgl->fetchColumn();

                    $mutasiSetelah = Database::get()->prepare("
                        SELECT COALESCE(SUM(CASE WHEN tipe='masuk' THEN jumlah ELSE -jumlah END), 0)
                        FROM hl_kas_bank_mutasi
                        WHERE kas_bank_id=? AND saldo_akhir IS NULL
                          AND DATE(tanggal) > ? AND DATE(tanggal) <= ?
                    ");
                    $mutasiSetelah->execute([$b['id'], $snapTgl, $endDate]);
                    $total += (int)$lastSaldo + (int)$mutasiSetelah->fetchColumn();
                } else {
                    // Hitung dari saldo_awal + semua mutasi
                    $mut = Database::get()->prepare("
                        SELECT COALESCE(SUM(CASE WHEN tipe='masuk' THEN jumlah ELSE -jumlah END), 0)
                        FROM hl_kas_bank_mutasi
                        WHERE kas_bank_id=? AND saldo_akhir IS NULL
                          AND DATE(tanggal) <= ?
                    ");
                    $mut->execute([$b['id'], $endDate]);
                    $total += (int)$b['saldo_awal'] + (int)$mut->fetchColumn();
                }
            }
        } catch (Throwable) {}
        return max(0, $total);
    }

    // ════════════════════════════════════════════════════════════
    // HELPER: Saldo akun dari jurnal manual (akumulatif s/d tanggal)
    // ════════════════════════════════════════════════════════════
    private static function getSaldoManual(
        int $tenantId, ?int $outletId, string $tipe, string $endDate
    ): int {
        $of = self::o($outletId);
        try {
            $s = Database::get()->prepare("
                SELECT COALESCE(
                    SUM(CASE WHEN arah='kredit' THEN jumlah ELSE -jumlah END), 0
                )
                FROM hl_jurnal_manual
                WHERE tenant_id=? AND tipe=?
                  AND DATE(tanggal) <= ?
                  $of
            ");
            $s->execute([$tenantId, $tipe, $endDate]);
            return max(0, (int)$s->fetchColumn());
        } catch (Throwable) {
            return 0;
        }
    }

    // ════════════════════════════════════════════════════════════
    // HELPER: Laba ditahan (akumulasi L/R s/d periode sebelumnya)
    // ════════════════════════════════════════════════════════════
    private static function hitungLabaDitahan(
        int $tenantId, ?int $outletId, string $periode
    ): int {
        // Cari periode paling awal transaksi tenant
        $of = self::o($outletId);
        try {
            $s = Database::get()->prepare("
                SELECT MIN(DATE_FORMAT(tanggal,'%Y-%m'))
                FROM hl_transaksi
                WHERE tenant_id=? AND tanggal IS NOT NULL $of
            ");
            $s->execute([$tenantId]);
            $awal = $s->fetchColumn();
        } catch (Throwable) {
            $awal = null;
        }
        if (!$awal || $awal >= $periode) return 0;

        $total = 0;
        $cur = $awal;
        while ($cur < $periode) {
            try {
                $lr = self::labaRugi($tenantId, $outletId, $cur);
                $total += $lr['laba_bersih'];
            } catch (Throwable) {}
            $cur = date('Y-m', strtotime($cur . '-01 +1 month'));
        }
        return $total;
    }

    // ════════════════════════════════════════════════════════════
    // HELPER: Bunga pinjaman bulan ini
    // ════════════════════════════════════════════════════════════
    private static function hitungBunga(
        int $tenantId, ?int $outletId, string $periode
    ): int {
        $of = self::o($outletId);
        $total = 0;
        try {
            $s = Database::get()->prepare("
                SELECT (saldo_awal - saldo_terbayar) saldo, bunga_per_bulan
                FROM hl_liabilitas
                WHERE tenant_id=? AND status='aktif' AND bunga_per_bulan > 0 $of
            ");
            $s->execute([$tenantId]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $total += (int) round($p['saldo'] * $p['bunga_per_bulan'] / 100);
            }
        } catch (Throwable) {}
        return $total;
    }

    // ════════════════════════════════════════════════════════════
    // HELPER: Sum jurnal manual per tipe + arah
    // ════════════════════════════════════════════════════════════
    private static function sumJurnal(
        int $tenantId, ?int $outletId,
        string $periode, string $tipe, string $arah
    ): int {
        $of = self::o($outletId);
        try {
            $s = Database::get()->prepare("
                SELECT COALESCE(SUM(jumlah), 0)
                FROM hl_jurnal_manual
                WHERE tenant_id=? AND periode=? AND tipe=? AND arah=? $of
            ");
            $s->execute([$tenantId, $periode, $tipe, $arah]);
            return (int)$s->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    // ════════════════════════════════════════════════════════════
    // HELPER: Break Even Point
    // ════════════════════════════════════════════════════════════
    private static function hitungBEP(array $beban, int $pendapatan): int
    {
        // Biaya tetap: gaji + penyusutan + bunga + manual (sewa, dll)
        $tetap = ($beban['gaji'] ?? 0)
               + ($beban['penyusutan'] ?? 0)
               + ($beban['bunga'] ?? 0)
               + ($beban['total_manual'] ?? 0);

        // Biaya variabel: komisi + operasional kas
        $variabel = ($beban['komisi_mitra'] ?? 0)
                  + ($beban['operasional_kas'] ?? 0);

        $rasioVar = $pendapatan > 0 ? $variabel / $pendapatan : 0;
        $margin   = 1 - $rasioVar;

        return $margin > 0 ? (int) round($tetap / $margin) : 0;
    }

    // ════════════════════════════════════════════════════════════
    // Seed COA default untuk tenant baru
    // ════════════════════════════════════════════════════════════
    public static function seedCoa(PDO $db, int $tenantId): void
    {
        $rows = [
            ['1-1001','Kas Tunai',               'aset_lancar',              1, 1],
            ['1-1002','Kas Bank / Rekening',      'aset_lancar',              0, 2],
            ['1-1003','Piutang Usaha',            'aset_lancar',              1, 3],
            ['1-1004','Persediaan Bahan',         'aset_lancar',              0, 4],
            ['1-1005','Biaya Dibayar Dimuka',     'aset_lancar',              0, 5],
            ['1-2001','Mesin Cuci',               'aset_tetap',               0,10],
            ['1-2002','Mesin Pengering',          'aset_tetap',               0,11],
            ['1-2003','Peralatan Setrika',        'aset_tetap',               0,12],
            ['1-2004','Kendaraan / Motor',        'aset_tetap',               0,13],
            ['1-2005','Inventaris Kantor',        'aset_tetap',               0,14],
            ['2-1001','Hutang Usaha',             'liabilitas_lancar',        0,20],
            ['2-1002','Hutang Gaji',              'liabilitas_lancar',        0,21],
            ['2-1003','Cicilan Jatuh Tempo',      'liabilitas_lancar',        0,22],
            ['2-2001','Pinjaman Bank / KUR',      'liabilitas_jangka_panjang',0,30],
            ['2-2002','Cicilan Kendaraan',        'liabilitas_jangka_panjang',0,31],
            ['2-2003','Cicilan Mesin',            'liabilitas_jangka_panjang',0,32],
            ['3-1001','Modal Disetor',            'ekuitas',                  0,40],
            ['3-1002','Laba Ditahan',             'ekuitas',                  1,41],
            ['3-1003','Prive / Penarikan Owner',  'ekuitas',                  0,42],
            ['4-1001','Pendapatan Kiloan',        'pendapatan',               1,50],
            ['4-1002','Pendapatan B2B',           'pendapatan',               1,51],
            ['4-1003','Pendapatan Drop Point',    'pendapatan',               1,52],
            ['4-1099','Pendapatan Lain-lain',     'pendapatan',               0,53],
            ['5-1001','Beban Gaji Karyawan',      'beban_operasional',        1,60],
            ['5-1002','Beban Bahan Habis Pakai',  'beban_operasional',        0,61],
            ['5-1003','Beban Sewa',               'beban_operasional',        0,62],
            ['5-1004','Beban Utilitas',           'beban_operasional',        0,63],
            ['5-1005','Beban Penyusutan',         'beban_operasional',        1,64],
            ['5-1006','Beban Bunga Pinjaman',     'beban_operasional',        0,65],
            ['5-1007','Beban Pemasaran',          'beban_operasional',        0,66],
            ['5-1008','Beban Komisi Mitra',       'beban_operasional',        1,67],
            ['5-1099','Beban Operasional Lain',   'beban_operasional',        1,68],
        ];
        $st = $db->prepare(
            "INSERT IGNORE INTO hl_coa
             (tenant_id, outlet_id, kode, nama, tipe, is_auto, urutan)
             VALUES (?, NULL, ?, ?, ?, ?, ?)"
        );
        foreach ($rows as [$kode, $nama, $tipe, $auto, $urutan]) {
            $st->execute([$tenantId, $kode, $nama, $tipe, $auto, $urutan]);
        }
    }
}

<?php
// ══════════════════════════════════════════════════════
// core/AIMigrationMapper.php
//
// AI-powered column mapper untuk import data dari sistem lain.
// Mengirim header + sample rows ke Claude API,
// menerima mapping JSON yang memetakan kolom file ke
// field target LaMaSy.
//
// Mapping di-cache di hl_migration_mapping_templates —
// file dengan header yang sama tidak perlu re-call AI.
//
// Usage:
//   $result = AIMigrationMapper::map('pelanggan', $headers, $rows);
//   // $result['mapping'] → array header → {target_field, action, confidence}
//   // $result['source']  → 'cache' | 'ai' | 'ai_failed'
//   // $result['overall_confidence'] → 0.0–1.0
// ══════════════════════════════════════════════════════

class AIMigrationMapper
{
    // Bump kalau prompt/schema berubah — cache lama auto-invalidate.
    // v7: heuristic kenal kolom item 'Total'/'Subtotal' → subtotal_item, dan
    //     izinkan >1 kolom biaya → biaya_tambahan (summable).
    const MAPPER_VERSION = 7;

    // ── Target schema per entitas ─────────────────────
    // Deskripsi dipakai sebagai konteks untuk Claude.
    // required: true → wajib ada mapping-nya.
    // ─────────────────────────────────────────────────
    const SCHEMAS = [
        'layanan' => [
            'nama'       => ['required'=>true,  'type'=>'string',  'desc'=>'Nama layanan/paket'],
            'harga'      => ['required'=>true,  'type'=>'integer', 'desc'=>'Harga dalam Rupiah (angka saja)'],
            'satuan'     => ['required'=>false, 'type'=>'string',  'desc'=>'Satuan: kg, pcs, item, lembar'],
            'kategori'   => ['required'=>false, 'type'=>'string',  'desc'=>'Kategori layanan'],
            'keterangan' => ['required'=>false, 'type'=>'string',  'desc'=>'Keterangan atau deskripsi layanan'],
        ],

        'pelanggan' => [
            'nama'       => ['required'=>true,  'type'=>'string',  'desc'=>'Nama lengkap pelanggan'],
            'telepon'    => ['required'=>true,  'type'=>'phone',   'desc'=>'Nomor WhatsApp / HP (format 08xx)'],
            'alamat'     => ['required'=>false, 'type'=>'string',  'desc'=>'Alamat lengkap pelanggan'],
            'tipe_bayar' => ['required'=>false, 'type'=>'enum',    'desc'=>'Tipe bayar: langsung atau bulanan'],
            'catatan'    => ['required'=>false, 'type'=>'string',  'desc'=>'Catatan / preferensi khusus pelanggan'],
        ],

        'karyawan' => [
            'nama'       => ['required'=>true,  'type'=>'string',  'desc'=>'Nama karyawan'],
            'telepon'    => ['required'=>false, 'type'=>'phone',   'desc'=>'Nomor WhatsApp / HP karyawan'],
            'role'       => ['required'=>false, 'type'=>'string',  'desc'=>'Role: kasir, staff, owner, manager, admin'],
            'gaji_pokok' => ['required'=>false, 'type'=>'integer', 'desc'=>'Gaji pokok per bulan dalam Rupiah'],
            'tgl_masuk'  => ['required'=>false, 'type'=>'date',    'desc'=>'Tanggal mulai kerja (YYYY-MM-DD)'],
        ],

        'transaksi' => [
            // ── Identitas transaksi ──
            'no_order'         => ['required'=>false, 'type'=>'string',  'desc'=>'Nomor nota/invoice (mis. "No Nota", "No Order") — PENTING utk multi-item per nota'],
            'nama_pelanggan'   => ['required'=>true,  'type'=>'string',  'desc'=>'Nama pelanggan (mis. "Customer", "Pelanggan")'],
            'telepon'          => ['required'=>false, 'type'=>'phone',   'desc'=>'Nomor HP/WA pelanggan'],
            // ── Tanggal ──
            'tanggal'          => ['required'=>true,  'type'=>'date',    'desc'=>'Tanggal transaksi masuk (mis. "Tgl Terima", "Tanggal Order")'],
            'estimasi_selesai' => ['required'=>false, 'type'=>'date',    'desc'=>'Estimasi/tanggal selesai cucian (mis. "Tgl Selesai", "Estimasi Selesai")'],
            'tgl_selesai'      => ['required'=>false, 'type'=>'date',    'desc'=>'Tanggal nota DIAMBIL pelanggan (mis. "Tgl Pengambilan", "Tgl Diambil")'],
            // ── Nominal uang (transaksi-level) ──
            'subtotal'         => ['required'=>false, 'type'=>'integer', 'desc'=>'Subtotal sebelum diskon (mis. kolom "Subtotal") — BUKAN subtotal per item'],
            'diskon'           => ['required'=>false, 'type'=>'integer', 'desc'=>'Diskon transaksi (mis. "Diskon", "Potongan")'],
            'biaya_tambahan'   => ['required'=>false, 'type'=>'integer', 'desc'=>'Biaya tambahan: express, biaya service, jemput, antar (mis. "Tambahan Express", "Biaya Service", "Biaya Antar"). Kalau ada beberapa kolom biaya tambahan, jumlahkan semua ke field ini.'],
            'total'            => ['required'=>false, 'type'=>'integer', 'desc'=>'Total tagihan akhir (mis. "Total Tagihan", "Grand Total")'],
            'dp'               => ['required'=>false, 'type'=>'integer', 'desc'=>'Uang muka / DP yang sudah dibayar'],
            'tipe_order'       => ['required'=>false, 'type'=>'string',  'desc'=>'Tipe/kategori order (mis. "Jenis": Reguler/Express/Kilat). Map: "Reguler"→reguler, "Express"→express, "Kilat"→kilat, lainnya→custom.'],
            // ── Status & metode ──
            'status_bayar'     => ['required'=>false, 'type'=>'enum',    'desc'=>'Status pembayaran. Map: "Lunas"→lunas, "Belum Lunas"/"Belum Bayar"→belum_bayar, "DP"→dp. Kolom "Pembayaran" biasanya isi ini.'],
            'status_proses'    => ['required'=>false, 'type'=>'enum',    'desc'=>'Status proses. Map: "Sudah Diambil"/"Diambil"→diambil, "Belum Diambil"+ada tgl selesai→siap, lainnya→masuk. Kolom "Pengambilan" biasanya isi ini.'],
            'metode_bayar'     => ['required'=>false, 'type'=>'string',  'desc'=>'Metode bayar (cash/transfer/qris)'],
            'catatan'          => ['required'=>false, 'type'=>'string',  'desc'=>'Catatan order (mis. "Keterangan Nota")'],
            // ── Detail item (per baris layanan dalam nota) ──
            'nama_layanan'     => ['required'=>false, 'type'=>'string',  'desc'=>'Nama layanan per baris item (kolom "Nama" di bagian detail layanan, mis. "Selimut Single", "Cuci Setrika")'],
            'jumlah_item'      => ['required'=>false, 'type'=>'decimal', 'desc'=>'Jumlah/qty per item (kolom "Jumlah" / "Qty")'],
            'satuan_item'      => ['required'=>false, 'type'=>'string',  'desc'=>'Satuan per item (kg, pcs, set, lembar)'],
            'subtotal_item'    => ['required'=>false, 'type'=>'integer', 'desc'=>'Subtotal PER ITEM (kolom "Total" di bagian detail — BUKAN Total Tagihan transaksi)'],
        ],

        'poin_pelanggan' => [
            'telepon'        => ['required'=>true,  'type'=>'phone',   'desc'=>'Nomor HP/WA pelanggan (untuk match)'],
            'nama_pelanggan' => ['required'=>false, 'type'=>'string',  'desc'=>'Nama pelanggan (fallback jika WA tidak ditemukan)'],
            'saldo_poin'     => ['required'=>true,  'type'=>'integer', 'desc'=>'Saldo poin yang akan diimport'],
        ],
    ];

    /**
     * Map header file ke schema target menggunakan AI.
     * Fallback ke cache jika header signature sama sudah pernah berhasil.
     *
     * @param string $entityType   layanan|pelanggan|karyawan|transaksi|poin_pelanggan
     * @param array  $headers      Array nama kolom dari file
     * @param array  $sampleRows   Array of assoc arrays — 3–5 baris pertama
     * @param string|null $sourceSystem  Hint sumber sistem jika diketahui
     *
     * @return array {
     *   mapping: {header => {target_field, action, transform_note, confidence}},
     *   missing_required: [],
     *   warnings: [],
     *   source_system_detected: string,
     *   overall_confidence: float,
     *   source: 'cache'|'ai'|'ai_failed',
     *   from_cache: bool,
     * }
     */
    public static function map(
        string  $entityType,
        array   $headers,
        array   $sampleRows,
        ?string $sourceSystem = null,
        bool    $ignoreCache = false
    ): array
    {
        // 1. Cek cache — header signature yang sama tidak perlu re-call AI.
        //    Bisa di-skip (ignoreCache=true) ketika user paksa "Run ulang AI".
        $cached = $ignoreCache ? null : self::findCached($entityType, $headers);
        if ($cached) {
            $cached['mapping'] = self::fillUnmappedHeaders($cached['mapping'] ?? [], $headers);
            return array_merge($cached, [
                'source'     => 'cache',
                'from_cache' => true,
                'missing_required' => self::checkMissing($entityType, $cached['mapping']),
                'warnings'   => [],
            ]);
        }

        // Heuristic dihitung sebagai JARING PENGAMAN — dipakai kalau AI gagal
        // (API down) supaya import tetap jalan. Untuk format baru, AI tetap
        // jadi engine utama (Opsi A — analisa data genuine via Claude).
        $heuristic = self::heuristicMap($entityType, $headers);

        // 2. Pastikan ada AnthropicClient
        if (!class_exists('AnthropicClient')) {
            require_once dirname(__FILE__) . '/AnthropicClient.php';
            require_once dirname(__FILE__) . '/AIPersona.php';
        }

        $schema    = self::SCHEMAS[$entityType] ?? [];
        $sampleStr = self::formatSample($headers, $sampleRows);

        // Required fields untuk konteks AI
        $requiredList = implode(', ', array_keys(array_filter($schema, fn($f) => $f['required'])));

        $systemPrompt = AIPersona::system(
            'asisten migrasi data yang memetakan kolom dari file upload ke schema target. '
          . 'Selalu respond dengan JSON valid saja — tidak ada teks lain.'
        );

        $prompt = "Kamu menganalisa file data laundry untuk migrasi ke sistem LaMaSy.\n\n"
            . "Headers (nama kolom): " . implode(', ', array_map(fn($h) => "\"$h\"", $headers)) . "\n\n"
            . "Sample data (per kolom, 5 nilai pertama — format `[nama_kolom]: nilai1 | nilai2 | ...`):\n$sampleStr\n"
            . "Target schema untuk entitas [{$entityType}]:\n"
            . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n"
            . ($sourceSystem ? "Petunjuk: file ini kemungkinan dari sistem \"$sourceSystem\".\n\n" : '')
            . "Field yang wajib ada: $requiredList\n\n"
            . "═══ PRINSIP UTAMA ═══\n"
            . "ANALISA ISI DATA, JANGAN CUMA LIHAT NAMA KOLOM. Banyak file ekspor pakai header singkat/asing/kosong.\n\n"
            . "Pola nilai → field:\n"
            . "- '08xxx', '+62xxx', '62 8xx' → telepon (transform ke 08xx).\n"
            . "- '15/03/2024', '2024-03-15', '15 Mar 2024 14:30' → tanggal (transform YYYY-MM-DD).\n"
            . "- 'Rp 7.000', '7000', '7.000', '13130.00' → field harga/total/subtotal (transform → integer).\n"
            . "- 'UIM260...', 'TKJ123...', 'INV/2024/001' → no_order/invoice number.\n"
            . "- 'Selimut/Sprei Single', 'Cuci Setrika', 'Bedcover King' → nama_layanan.\n"
            . "- 'kg', 'pcs', 'PCS', 'lembar', 'set' → satuan/satuan_item.\n"
            . "- '1.00', '2.5', '0.75' (angka kecil, decimal) → jumlah_item/jumlah.\n\n"
            . "═══ KOLOM BERNAMA GENERIK (kolom_1, kolom_23, dll) ═══\n"
            . "Kolom yang headernya 'kolom_N' artinya header file kosong/merged-cell.\n"
            . "WAJIB infer 100% dari sample data. JANGAN langsung skip.\n"
            . "Contoh: kolom_23 dengan sample ['Selimut/Sprei Single','Bedcover King','Selimut Queen'] → ini jelas nama_layanan.\n"
            . "kolom_24 dengan sample ['1.00','2.00','1.50'] → jumlah_item.\n"
            . "Hanya skip kalau sample benar-benar kosong atau tidak relevan untuk schema target.\n\n"
            . "═══ ANTI-PATTERN (HINDARI) ═══\n"
            . "- 'Progres Pengerjaan' dengan sample '11%', '50%', '100%' → ini PERCENTAGE, JANGAN map ke status. Skip atau catatan.\n"
            . "- 'Subtotal' kolom transaksi-level (sebelum diskon) ≠ subtotal_item (per layanan). Lihat konteks.\n"
            . "- 'Tambahan Express', 'Biaya Service', 'Pajak' → biasanya tidak ada di schema target → skip (atau catat di transform_note).\n"
            . "- 'Outlet', 'Pembuat Nota', 'Kasir' → metadata sumber, skip (sudah ada outlet_id otomatis).\n\n"
            . ($entityType === 'transaksi' ? "═══ TRANSAKSI — PETUNJUK KHUSUS ═══\n"
                . "File transaksi sering punya BANYAK kolom yang bisa di-map. Map SEMUA yang ada padanannya di schema, jangan skip kalau ada field target yang cocok.\n\n"
                . "Identitas & tanggal:\n"
                . "- 'No Nota', 'No Order', 'Invoice' → no_order (WAJIB cari — kunci grouping multi-item).\n"
                . "- 'Customer', 'Pelanggan' → nama_pelanggan.\n"
                . "- 'Tgl Terima', 'Tanggal' → tanggal (transaksi masuk).\n"
                . "- 'Tgl Selesai', 'Estimasi Selesai' → estimasi_selesai (target selesai cucian).\n"
                . "- 'Tgl Pengambilan', 'Tgl Diambil' → tgl_selesai (kapan pelanggan ambil).\n\n"
                . "Nominal (HATI-HATI bedakan transaksi-level vs item-level):\n"
                . "- 'Subtotal' (kolom di main, sebelum diskon/biaya) → subtotal (transaksi-level).\n"
                . "- 'Diskon', 'Potongan' → diskon.\n"
                . "- 'Tambahan Express', 'Biaya Service', 'Biaya Jemput', 'Biaya Antar' → biaya_tambahan. Kalau ADA BEBERAPA kolom biaya, gabung mapping-nya ke biaya_tambahan (importer akan menjumlahkan).\n"
                . "- 'Total Tagihan', 'Grand Total' → total (final tagihan transaksi).\n"
                . "- 'DP', 'Uang Muka' → dp.\n"
                . "- 'Subtotal'/'Total' di BAGIAN DETAIL ITEM (biasanya kolom paling kanan, dalam grup detail layanan) → subtotal_item.\n"
                . "  → Tanda kolom 'item-level': muncul SETELAH header parent 'Detail Transaksi' atau berdekatan dgn 'Nama'/'Jumlah'/'Satuan' item.\n\n"
                . "Tipe order:\n"
                . "- 'Jenis', 'Tipe Order', 'Kategori' dgn sample 'Reguler'/'Express'/'Kilat' → tipe_order.\n\n"
                . "Status (penting — ada 2 status terpisah):\n"
                . "- 'Pembayaran' / 'Status Bayar' (sample: 'Lunas', 'Belum Lunas', 'DP') → status_bayar.\n"
                . "- 'Pengambilan' / 'Status Proses' (sample: 'Belum Diambil', 'Sudah Diambil') → status_proses.\n\n"
                . "Detail item (per baris layanan):\n"
                . "- 'Nama' / sample nama-layanan (Selimut, Bedcover, Cuci Setrika) → nama_layanan.\n"
                . "- 'Jumlah' / 'Qty' per item → jumlah_item.\n"
                . "- 'Satuan' (pcs, kg, set) → satuan_item.\n\n"
                . "Yang boleh skip (tidak ada field target):\n"
                . "- 'Progres Pengerjaan' kalau isinya % → skip.\n"
                . "- 'Pajak' / 'PPN' → skip (tidak ada field terpisah; sudah include di total).\n"
                . "- 'Outlet', 'Pembuat Nota', 'Kasir', 'No' (row number), 'Alamat Customer' → skip (metadata sumber atau bukan field transaksi).\n\n" : '')
            . "Instruksi mapping:\n"
            . "1. Untuk setiap header, tentukan target_field paling cocok (atau null kalau benar-benar tidak ada).\n"
            . "2. action: \"map\" = langsung, \"transform\" = butuh konversi, \"skip\" = abaikan.\n"
            . "3. Nomor HP: normalkan ke 08xx (hapus +62, 62, spasi, tanda baca).\n"
            . "4. Harga/uang: hapus Rp, titik ribuan, koma → integer.\n"
            . "5. Tanggal: konversi ke YYYY-MM-DD.\n"
            . "6. confidence: 0.0–1.0 berdasar kecocokan header DAN nilai data.\n"
            . "7. Di warnings: catat masalah data (nilai kosong, format tidak konsisten, kolom yang sengaja di-skip & alasannya).\n\n"
            . "Respond HANYA JSON ini:\n"
            . "{\n"
            . "  \"mapping\": {\n"
            . "    \"[nama_header]\": {\n"
            . "      \"target_field\": \"[field_target atau null]\",\n"
            . "      \"action\": \"map|transform|skip\",\n"
            . "      \"transform_note\": \"[jelaskan jika perlu transformasi, kosong jika tidak]\",\n"
            . "      \"confidence\": 0.95\n"
            . "    }\n"
            . "  },\n"
            . "  \"missing_required\": [\"field1\"],\n"
            . "  \"warnings\": [\"peringatan jika ada\"],\n"
            . "  \"source_system_detected\": \"smartlink|ilaundy|excel|unknown\",\n"
            . "  \"overall_confidence\": 0.9\n"
            . "}";

        try {
            $resp = AnthropicClient::askJson($prompt, [
                'system'      => $systemPrompt,
                'max_tokens'  => 1200,
                'temperature' => 0,
            ]);
            $result = $resp['json'];
        } catch (Throwable $e) {
            error_log('[AIMigrationMapper] AI error: ' . $e->getMessage());
            // AI gagal → tetap kembalikan hasil heuristik (kolom yang sempat dikenali),
            // supaya user bisa lanjut mapping manual untuk sisanya, bukan tabel kosong.
            return [
                'mapping'               => self::fillUnmappedHeaders($heuristic, $headers),
                'missing_required'      => self::checkMissing($entityType, $heuristic),
                'warnings'              => ['AI mapping gagal, dipakai pengenalan kolom otomatis. Lengkapi mapping manual di bawah.'],
                'source_system_detected'=> 'unknown',
                'overall_confidence'    => 0.0,
                'source'                => 'ai_failed',
                'from_cache'            => false,
            ];
        }

        // Sanitasi hasil AI
        $result['source']     = 'ai';
        $result['from_cache'] = false;
        $result['mapping']    = $result['mapping'] ?? [];
        $result['tokens_in']  = (int)($resp['tokens_in'] ?? 0);
        $result['tokens_out'] = (int)($resp['tokens_out'] ?? 0);
        $result['model']      = $resp['model'] ?? null;

        // Gap-filler: kalau AI masih ada field wajib yang kelewat, tambal dgn
        // heuristik (best of both — AI utama, heuristik jaring pengaman).
        $missingAfterAi = self::checkMissing($entityType, $result['mapping']);
        if (!empty($missingAfterAi) && !empty($heuristic)) {
            $aiTargets = array_filter(array_column(array_values($result['mapping']), 'target_field'));
            foreach ($heuristic as $hdr => $hinfo) {
                $tf = $hinfo['target_field'] ?? null;
                if ($tf && in_array($tf, $missingAfterAi, true) && !in_array($tf, $aiTargets, true)) {
                    $result['mapping'][$hdr] = $hinfo;       // tambal kolom yang AI lewatkan
                    $aiTargets[] = $tf;
                }
            }
        }

        // Pastikan SEMUA kolom file muncul (yang AI lewatkan = skip) → user bisa map manual
        $result['mapping']            = self::fillUnmappedHeaders($result['mapping'], $headers);
        $result['missing_required']   = self::checkMissing($entityType, $result['mapping']);
        $result['warnings']           = $result['warnings'] ?? [];
        $result['overall_confidence'] = (float)($result['overall_confidence'] ?? 0.0);

        // 3. Cache jika confidence tinggi
        if ($result['overall_confidence'] >= 0.80 && !empty($result['mapping'])) {
            self::saveCache(
                $entityType,
                $headers,
                $result['mapping'],
                $result['source_system_detected'] ?? null
            );
        }

        return $result;
    }

    /**
     * Cek apakah header signature ini sudah pernah berhasil di-map (cached).
     * Dipakai UI untuk minta approval coin sebelum panggil AI: kalau cache
     * hit → mapping gratis, kalau miss → tenant prompt konfirmasi 1.000 coin.
     */
    public static function hasCachedMapping(string $entityType, array $headers): bool
    {
        return self::findCached($entityType, $headers) !== null;
    }

    // ─────────────────────────────────────────────────
    // Cek missing required fields dari mapping
    // ─────────────────────────────────────────────────
    private static function checkMissing(string $entityType, array $mapping): array
    {
        $schema   = self::SCHEMAS[$entityType] ?? [];
        $required = array_keys(array_filter($schema, fn($f) => $f['required']));
        $mapped   = array_filter(
            array_column(array_values($mapping), 'target_field'),
            fn($v) => !empty($v)
        );
        return array_values(array_diff($required, $mapped));
    }

    /**
     * Pastikan SEMUA header punya entri di mapping — kolom yang AI lewatkan
     * tetap muncul (action 'skip') supaya user bisa map manual di UI.
     */
    private static function fillUnmappedHeaders(array $mapping, array $headers): array
    {
        foreach ($headers as $h) {
            $h = (string)$h;
            if ($h === '') continue;
            if (!isset($mapping[$h])) {
                $mapping[$h] = ['target_field' => null, 'action' => 'skip', 'transform_note' => '', 'confidence' => 0];
            }
        }
        return $mapping;
    }

    /**
     * Heuristic gratis: cocokkan header ke target field via alias umum
     * (Indonesia + Inggris). Tanpa AI, tanpa coin.
     */
    private static function heuristicMap(string $entityType, array $headers): array
    {
        $schema = self::SCHEMAS[$entityType] ?? [];

        // Alias per target field (lowercase). Urutan exact-match diutamakan.
        // Catatan: untuk transaksi multi-item, 'nama' polos LEBIH cocok ke
        // nama_layanan (col detail) daripada nama_pelanggan (kolom Customer).
        $aliases = [
            'nama'           => ['nama','name','nama layanan','nama_layanan','layanan','service','produk','item'],
            'harga'          => ['harga','price','tarif','harga satuan','harga_default','harga jual','cost'],
            'satuan'         => ['satuan','unit','uom'],
            'kategori'       => ['kategori','category','jenis','kelompok','grup','group'],
            'keterangan'     => ['keterangan','deskripsi','description','catatan','note','notes'],
            'telepon'        => ['telepon','telp','hp','no hp','no. hp','nomor hp','wa','whatsapp','no wa','phone','no_hp','nohp','kontak','no telp customer','no telp pelanggan'],
            'alamat'         => ['alamat','address','almt','alamat customer','alamat pelanggan'],
            'tipe_bayar'     => ['tipe bayar','tipe_bayar','payment'],
            'catatan'        => ['catatan','note','notes','keterangan','remark','keterangan nota'],
            'role'           => ['role','jabatan','posisi','position','peran'],
            'gaji_pokok'     => ['gaji','gaji pokok','gaji_pokok','salary','upah'],
            'tgl_masuk'      => ['tgl masuk','tanggal masuk','tgl_masuk','tanggal_masuk','join date','mulai kerja'],
            // Transaksi — identitas
            'nama_pelanggan'   => ['nama pelanggan','nama_pelanggan','customer','pelanggan','nama customer'],
            'no_order'         => ['no order','no_order','no nota','no_nota','nota','invoice','invoice no','no invoice','order id','no. nota','no order'],
            // Tanggal
            'tanggal'          => ['tanggal','tgl','date','tgl terima','tanggal terima','tgl order','tanggal order'],
            'estimasi_selesai' => ['tgl selesai','tanggal selesai','estimasi selesai','estimasi','est selesai','target selesai'],
            'tgl_selesai'      => ['tgl pengambilan','tanggal pengambilan','tgl diambil','tanggal diambil','pickup date','tgl ambil'],
            // Nominal transaksi-level
            'subtotal'         => ['subtotal','sub total','sub_total','subtotal nota'],
            'diskon'           => ['diskon','discount','potongan','disc'],
            'biaya_tambahan'   => ['tambahan express','biaya service','biaya tambahan','biaya jemput','biaya antar','tambahan','express fee','service fee'],
            'total'            => ['total tagihan','total_tagihan','grand total','total bayar','total harga','amount','tagihan','total'],
            'dp'               => ['dp','uang muka','down payment','um','panjar'],
            'tipe_order'       => ['jenis','tipe','tipe order','jenis order','kategori order','type','order type'],
            // Status & metode
            'status_bayar'     => ['pembayaran','status bayar','status pembayaran','status_bayar','payment status'],
            'status_proses'    => ['pengambilan','status proses','status order','status_proses','progress','status pengerjaan'],
            'metode_bayar'     => ['metode bayar','metode_bayar','payment method','cara bayar'],
            'status'           => ['status'], // generic fallback
            // Item detail per baris (sub-row) — 'nama' polos akan disambiguasi
            // di disambiguateHeuristic() setelah loop: kalau ada 'customer' di
            // file, "Nama" → nama_layanan; kalau tidak, → nama_pelanggan.
            'nama_layanan'     => ['nama layanan','nama_layanan','layanan','service','paket','detail layanan','nama'],
            'jumlah_item'      => ['jumlah','qty','quantity','kuantitas','jumlah item'],
            'satuan_item'      => ['satuan','satuan item','satuan_item','unit'],
            // 'total' & 'subtotal' di sini SENGAJA — kalau file punya 'Total Tagihan'
            // (→ total) lalu kolom item bernama polos 'Total'/'Subtotal' di bagian
            // detail, kolom item jatuh ke subtotal_item krn target 'total'/'subtotal'
            // sudah keburu kepakai (urutan schema + $used guard).
            'subtotal_item'    => ['total item','total_item','subtotal_item','subtotal layanan','harga total','total harga item','total','subtotal'],
            'berat_kg'       => ['berat','berat kg','berat_kg','weight','qty kg'],
            'saldo_poin'     => ['saldo poin','poin','point','saldo_poin','points','poin pelanggan'],
        ];

        // Field nominal yang akumulatif: boleh dipetakan dari >1 kolom file
        // (importer menjumlahkannya — lihat $sumableFields di MigrationImporter).
        // Mis. "Tambahan Express" + "Biaya Service" dua-duanya → biaya_tambahan.
        $sumable = ['biaya_tambahan' => true];

        $mapping = [];
        $used    = [];
        foreach ($headers as $h) {
            $hl = strtolower(trim((string)$h));
            if ($hl === '') continue;
            $matched = null;
            foreach ($schema as $field => $def) {
                if (isset($used[$field]) && !isset($sumable[$field])) continue;
                $al = $aliases[$field] ?? [$field, str_replace('_', ' ', $field)];
                if (in_array($hl, $al, true)) { $matched = $field; break; }
            }
            if ($matched) {
                $mapping[$h] = [
                    'target_field'  => $matched,
                    'action'        => 'map',
                    'transform_note'=> '',
                    'confidence'    => 0.92,
                ];
                $used[$matched] = true;
            }
        }
        return $mapping;
    }

    // ─────────────────────────────────────────────────
    // Format sample rows sebagai teks untuk prompt
    // ─────────────────────────────────────────────────
    private static function formatSample(array $headers, array $rows): string
    {
        // Format kolom-by-kolom (lebih mudah dianalisa AI daripada baris-by-baris)
        // — AI lebih mudah lihat pola "kolom A semuanya nomor telepon" kalau
        // disajikan vertikal per kolom.
        $sample = array_slice($rows, 0, 5);
        if (empty($sample)) return '(tidak ada data sample)';

        $out = '';
        foreach ($headers as $h) {
            $vals = [];
            foreach ($sample as $row) {
                $v = trim((string)($row[$h] ?? ''));
                if ($v === '') $v = '(kosong)';
                if (mb_strlen($v) > 40) $v = mb_substr($v, 0, 37) . '...';
                $vals[] = $v;
            }
            $out .= "  [{$h}]: " . implode(' | ', $vals) . "\n";
        }
        return $out;
    }

    // ─────────────────────────────────────────────────
    // Cache lookup
    // ─────────────────────────────────────────────────
    private static function findCached(string $entityType, array $headers): ?array
    {
        try {
            $sig  = self::signature($headers);
            $stmt = Database::get()->prepare("
                SELECT mapping, source_system, usage_count
                FROM hl_migration_mapping_templates
                WHERE entity_type = ? AND header_signature = ?
                LIMIT 1
            ");
            $stmt->execute([$entityType, $sig]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;

            // Validasi versi mapper — kalau cache dari versi prompt/schema
            // lama, ignore (akan di-overwrite dengan hasil AI run baru).
            $mappingArr = json_decode($row['mapping'], true) ?: [];
            $cachedVer  = (int)($mappingArr['_v'] ?? 0);
            if ($cachedVer !== self::MAPPER_VERSION) return null;
            unset($mappingArr['_v']);

            // Increment usage_count
            Database::get()->prepare("
                UPDATE hl_migration_mapping_templates
                SET usage_count = usage_count + 1
                WHERE entity_type = ? AND header_signature = ?
            ")->execute([$entityType, $sig]);

            return [
                'mapping'               => $mappingArr,
                'source_system_detected'=> $row['source_system'] ?? 'unknown',
                'overall_confidence'    => 1.0,
            ];
        } catch (Throwable $e) {
            error_log('[AIMigrationMapper] Cache lookup error: ' . $e->getMessage());
            return null;
        }
    }

    // ─────────────────────────────────────────────────
    // Simpan mapping ke cache
    // ─────────────────────────────────────────────────
    private static function saveCache(
        string  $entityType,
        array   $headers,
        array   $mapping,
        ?string $sourceSystem
    ): void {
        try {
            $sig = self::signature($headers);
            // Embed versi mapper di mapping JSON utk invalidasi cache otomatis
            $mapping['_v'] = self::MAPPER_VERSION;
            Database::get()->prepare("
                INSERT INTO hl_migration_mapping_templates
                  (entity_type, source_system, header_signature, mapping, usage_count)
                VALUES (?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                  mapping      = VALUES(mapping),
                  source_system= VALUES(source_system),
                  usage_count  = usage_count + 1
            ")->execute([
                $entityType,
                $sourceSystem,
                $sig,
                json_encode($mapping, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            error_log('[AIMigrationMapper] Cache save error: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────
    // Hitung signature dari daftar header
    // ─────────────────────────────────────────────────
    private static function signature(array $headers): string
    {
        $normalized = array_map(fn($h) => strtolower(trim($h)), $headers);
        sort($normalized);
        return md5(implode(',', $normalized));
    }
}

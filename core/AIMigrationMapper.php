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
            // Identitas transaksi
            'no_order'       => ['required'=>false, 'type'=>'string',  'desc'=>'Nomor nota/invoice — PENTING untuk multi-item per nota (1 nota bisa punya beberapa layanan/baris)'],
            'nama_pelanggan' => ['required'=>true,  'type'=>'string',  'desc'=>'Nama pelanggan'],
            'telepon'        => ['required'=>false, 'type'=>'phone',   'desc'=>'Nomor HP/WA pelanggan'],
            'total'          => ['required'=>false, 'type'=>'integer', 'desc'=>'Total tagihan transaksi (mis. "Total Tagihan", "Grand Total") — BUKAN total per item'],
            'tanggal'        => ['required'=>true,  'type'=>'date',    'desc'=>'Tanggal transaksi (mis. "Tgl Terima")'],
            'status'         => ['required'=>false, 'type'=>'string',  'desc'=>'Status order'],
            'metode_bayar'   => ['required'=>false, 'type'=>'string',  'desc'=>'Metode bayar'],
            'catatan'        => ['required'=>false, 'type'=>'string',  'desc'=>'Catatan order'],
            // Detail item (per baris layanan dalam nota)
            'nama_layanan'   => ['required'=>false, 'type'=>'string',  'desc'=>'Nama layanan per baris item (mis. kolom "Nama" pada detail layanan)'],
            'jumlah_item'    => ['required'=>false, 'type'=>'decimal', 'desc'=>'Jumlah/qty per item (mis. kolom "Jumlah")'],
            'satuan_item'    => ['required'=>false, 'type'=>'string',  'desc'=>'Satuan per item (kg, pcs, M2, dll)'],
            'subtotal_item'  => ['required'=>false, 'type'=>'integer', 'desc'=>'Subtotal PER ITEM (bukan Total Tagihan — ini total layanan tsb saja)'],
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
        ?string $sourceSystem = null
    ): array
    {
        // 1. Cek cache — header signature yang sama tidak perlu re-call AI
        $cached = self::findCached($entityType, $headers);
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
            . "Sample data (3 baris pertama):\n$sampleStr\n\n"
            . "Target schema untuk entitas [{$entityType}]:\n"
            . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n"
            . ($sourceSystem ? "Petunjuk: file ini kemungkinan dari sistem \"$sourceSystem\".\n\n" : '')
            . "Field yang wajib ada: $requiredList\n\n"
            . "ANALISA DULU ISI DATANYA, bukan cuma nama kolom:\n"
            . "- Lihat NILAI di sample. Kolom berisi '08xxx'/'+62xxx' = telepon, walau headernya aneh/kosong.\n"
            . "- Nilai '15/03/2024', '2024-03-15', '15 Mar 2024' = tanggal → action transform.\n"
            . "- Nilai 'Rp 7.000', '7000', '7.000' = harga/uang → action transform.\n"
            . "- Header singkatan/typo/bahasa lain (nm_lyn, service_name, hrg) → pahami dari nilainya.\n"
            . "- Kalau 1 kolom berisi gabungan (mis. 'Budi - 08123'), tetap map ke field paling relevan + catat di transform_note.\n\n"
            . ($entityType === 'transaksi' ? "PENTING — file transaksi sering punya STRUKTUR MULTI-ITEM (1 nota = beberapa layanan):\n"
                . "- Bedakan 'Total Tagihan' / 'Grand Total' (= total transaksi) vs 'Total' / 'Subtotal' kolom item (= subtotal_item per layanan).\n"
                . "- 'No Nota' / 'Invoice' → no_order (kunci grouping multi-item).\n"
                . "- 'Customer' / 'Pelanggan' → nama_pelanggan; 'Nama' (di bagian detail layanan) → nama_layanan.\n"
                . "- 'Jumlah' / 'Qty' (per item) → jumlah_item; 'Satuan' item → satuan_item.\n\n" : '')
            . "Instruksi mapping:\n"
            . "1. Untuk setiap header, tentukan target_field paling cocok (atau null kalau tidak ada).\n"
            . "2. action: \"map\" = langsung, \"transform\" = butuh konversi, \"skip\" = abaikan.\n"
            . "3. Nomor HP: normalkan ke 08xx (hapus +62, 62, spasi, tanda baca).\n"
            . "4. Harga/uang: hapus Rp, titik ribuan, koma → integer.\n"
            . "5. Tanggal: konversi ke YYYY-MM-DD.\n"
            . "6. confidence: 0.0–1.0 berdasar kecocokan header DAN nilai data.\n"
            . "7. Di warnings: catat masalah data yang terlihat (nilai kosong, format tidak konsisten, kemungkinan duplikat).\n\n"
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
            // Transaksi — Customer & No Nota dipisah jelas
            'nama_pelanggan' => ['nama pelanggan','nama_pelanggan','customer','pelanggan','nama customer'],
            'no_order'       => ['no order','no_order','no nota','no_nota','nota','invoice','invoice no','no invoice','order id'],
            'total'          => ['total tagihan','total_tagihan','grand total','total bayar','total harga','amount','tagihan'],
            'tanggal'        => ['tanggal','tgl','date','tgl terima','tanggal terima','tgl order','tanggal order'],
            'status'         => ['status','status order','progres pengerjaan','progres','pembayaran','status bayar'],
            'metode_bayar'   => ['metode bayar','metode_bayar','payment method','cara bayar'],
            // Item detail per baris (sub-row)
            'nama_layanan'   => ['nama layanan','nama_layanan','layanan','service','paket','detail layanan'],
            'jumlah_item'    => ['jumlah','qty','quantity','kuantitas','jumlah item'],
            'satuan_item'    => ['satuan item','satuan_item'],
            'subtotal_item'  => ['subtotal','total item','total_item','subtotal_item','sub_total','subtotal layanan'],
            'berat_kg'       => ['berat','berat kg','berat_kg','weight','qty kg'],
            'saldo_poin'     => ['saldo poin','poin','point','saldo_poin','points','poin pelanggan'],
        ];

        $mapping = [];
        $used    = [];
        foreach ($headers as $h) {
            $hl = strtolower(trim((string)$h));
            if ($hl === '') continue;
            $matched = null;
            foreach ($schema as $field => $def) {
                if (isset($used[$field])) continue;
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
        $out = '';
        foreach (array_slice($rows, 0, 3) as $i => $row) {
            $vals = [];
            foreach ($headers as $h) {
                $vals[] = ($row[$h] ?? $row[array_keys($row)[$i] ?? 0] ?? '');
            }
            $out .= 'Baris ' . ($i + 1) . ': ' . implode(' | ', $vals) . "\n";
        }
        return $out ?: '(tidak ada data sample)';
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

            // Increment usage_count
            Database::get()->prepare("
                UPDATE hl_migration_mapping_templates
                SET usage_count = usage_count + 1
                WHERE entity_type = ? AND header_signature = ?
            ")->execute([$entityType, $sig]);

            return [
                'mapping'               => json_decode($row['mapping'], true) ?: [],
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

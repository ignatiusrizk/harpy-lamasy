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
            'nama_pelanggan' => ['required'=>true,  'type'=>'string',  'desc'=>'Nama pelanggan'],
            'telepon'        => ['required'=>false, 'type'=>'phone',   'desc'=>'Nomor HP / WA pelanggan'],
            'nama_layanan'   => ['required'=>true,  'type'=>'string',  'desc'=>'Nama layanan yang dibeli'],
            'berat_kg'       => ['required'=>false, 'type'=>'decimal', 'desc'=>'Berat dalam kg (angka, bisa desimal)'],
            'total'          => ['required'=>true,  'type'=>'integer', 'desc'=>'Total harga transaksi dalam Rupiah'],
            'tanggal'        => ['required'=>true,  'type'=>'date',    'desc'=>'Tanggal transaksi (YYYY-MM-DD)'],
            'status'         => ['required'=>false, 'type'=>'string',  'desc'=>'Status transaksi: selesai, proses, dll'],
            'metode_bayar'   => ['required'=>false, 'type'=>'string',  'desc'=>'Metode bayar: cash, transfer, dll'],
            'catatan'        => ['required'=>false, 'type'=>'string',  'desc'=>'Catatan order'],
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
            return array_merge($cached, [
                'source'     => 'cache',
                'from_cache' => true,
                'missing_required' => self::checkMissing($entityType, $cached['mapping']),
                'warnings'   => [],
            ]);
        }

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

        $prompt = "File data laundry ini memiliki kolom-kolom berikut:\n"
            . "Headers: " . implode(', ', array_map(fn($h) => "\"$h\"", $headers)) . "\n\n"
            . "Sample data (3 baris pertama):\n$sampleStr\n\n"
            . "Target schema untuk entitas [{$entityType}]:\n"
            . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n"
            . ($sourceSystem ? "Petunjuk: file ini kemungkinan dari sistem \"$sourceSystem\".\n\n" : '')
            . "Field yang wajib ada: $requiredList\n\n"
            . "Instruksi mapping:\n"
            . "1. Untuk setiap header, tentukan target_field yang paling cocok (atau null jika tidak ada yang sesuai)\n"
            . "2. action: \"map\" = petakan langsung, \"transform\" = butuh konversi, \"skip\" = abaikan\n"
            . "3. Nomor HP: normalkan ke format 08xx (hapus +62, 62, spasi, tanda baca)\n"
            . "4. Harga/uang: hapus Rp, titik ribuan, koma desimal → integer\n"
            . "5. Tanggal: konversi ke YYYY-MM-DD\n"
            . "6. confidence: 0.0–1.0 seberapa yakin mapping ini benar\n\n"
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
            return [
                'mapping'               => [],
                'missing_required'      => array_keys(array_filter($schema, fn($f) => $f['required'])),
                'warnings'              => ['AI mapping gagal: ' . $e->getMessage()],
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
        $result['missing_required'] = $result['missing_required'] ?? self::checkMissing($entityType, $result['mapping']);
        $result['warnings']   = $result['warnings'] ?? [];
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

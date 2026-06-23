<?php
// ══════════════════════════════════════════════════════
// core/MigrationImporter.php
//
// Processor untuk menjalankan import data baris per baris.
// Dipanggil setelah AI mapping dikonfirmasi.
//
// Usage:
//   $result = MigrationImporter::process($jobId);
//   // $result['success'], $result['failed'], $result['skipped']
// ══════════════════════════════════════════════════════

class MigrationImporter
{
    /**
     * Jalankan proses import untuk satu migration job.
     * Satu baris gagal tidak menghentikan baris lain.
     */
    // ── State antar-row utk transaksi multi-item ──
    // Smartlink (dan banyak format lain) sering pakai SUB-ROW: baris
    // setelah parent yg cuma punya detail item, tanpa no_order/customer.
    // Kita track last transaksi id selama loop & pakai sbg fallback.
    private static int $lastTrxId = 0;

    public static function process(int $jobId): array
    {
        $db = Database::get();
        self::$lastTrxId = 0; // reset state setiap process()

        // Ambil job
        $jobQ = $db->prepare("SELECT * FROM hl_migration_jobs WHERE id = ? LIMIT 1");
        $jobQ->execute([$jobId]);
        $job = $jobQ->fetch(PDO::FETCH_ASSOC);

        if (!$job || !$job['mapping_confirmed']) {
            return ['error' => 'Job tidak ditemukan atau mapping belum dikonfirmasi.'];
        }

        $mapping  = json_decode($job['ai_mapping'], true);
        $tenantId = (int)$job['tenant_id'];
        $outletId = (int)$job['outlet_id'];

        // Update status → importing
        $db->prepare("UPDATE hl_migration_jobs SET status='importing', started_at=NOW() WHERE id=?")->execute([$jobId]);

        // Parse file
        try {
            $rows = self::parseFile($job['file_path'], $job['file_type']);
        } catch (Throwable $e) {
            $db->prepare("UPDATE hl_migration_jobs SET status='failed', error_log=?, completed_at=NOW() WHERE id=?")
               ->execute([json_encode([['baris'=>0,'error'=>'Parse file gagal: '.$e->getMessage()]]), $jobId]);
            return ['error' => 'Parse file gagal: ' . $e->getMessage()];
        }

        $results = ['success'=>0, 'failed'=>0, 'skipped'=>0, 'errors'=>[]];

        foreach ($rows as $i => $row) {
            $lineNo = $i + 2; // baris 1 = header
            try {
                // Transform sesuai mapping
                $mapped = self::applyMapping($row, $mapping);

                // Validasi
                $errs = self::validate($mapped, $job['entity_type']);
                if (!empty($errs)) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'baris' => $lineNo,
                        'data'  => $row,
                        'error' => implode('; ', $errs),
                    ];
                    continue;
                }

                // Import
                $skipResult = self::importRow(
                    $job['entity_type'], $mapped, $tenantId, $outletId, $jobId
                );

                if ($skipResult === 'skip') {
                    $results['skipped']++;
                } else {
                    $results['success']++;
                }

            } catch (Throwable $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'baris' => $lineNo,
                    'data'  => $row,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $total  = $results['success'] + $results['failed'] + $results['skipped'];
        $status = match(true) {
            $results['failed'] === 0 && $results['skipped'] === 0 => 'completed',
            $results['success'] === 0                              => 'failed',
            default                                                => 'partial',
        };

        $db->prepare("
            UPDATE hl_migration_jobs SET
              status       = ?,
              total_rows   = ?,
              success_rows = ?,
              failed_rows  = ?,
              skipped_rows = ?,
              error_log    = ?,
              completed_at = NOW()
            WHERE id = ?
        ")->execute([
            $status, $total,
            $results['success'], $results['failed'], $results['skipped'],
            json_encode($results['errors'], JSON_UNESCAPED_UNICODE),
            $jobId,
        ]);

        // Cleanup file kalau status 'completed' (success penuh).
        // Keep file kalau 'partial' atau 'failed' untuk forensic / re-import.
        if ($status === 'completed' && !empty($job['file_path']) && is_file($job['file_path'])) {
            @unlink($job['file_path']);
        }

        return $results;
    }

    // ─────────────────────────────────────────────────
    // Apply mapping AI ke satu row
    // ─────────────────────────────────────────────────
    private static function applyMapping(array $row, array $mapping): array
    {
        $out = [];
        $mapDef = $mapping['mapping'] ?? $mapping; // support both wrapper and flat

        foreach ($mapDef as $srcCol => $info) {
            $action      = $info['action']         ?? 'skip';
            $targetField = $info['target_field']   ?? null;
            $transformNote = $info['transform_note'] ?? '';

            if ($action === 'skip' || empty($targetField)) continue;

            // Toleran: cari kolom di row secara case-insensitive jika tidak ketemu exact
            $val = $row[$srcCol] ?? null;
            if ($val === null) {
                foreach ($row as $k => $v) {
                    if (strtolower(trim($k)) === strtolower(trim($srcCol))) {
                        $val = $v; break;
                    }
                }
            }
            if ($val === null) continue;

            $val = trim((string)$val);
            if ($val === '') continue;

            if ($action === 'transform') {
                $val = self::transform($val, $transformNote);
            }

            // Sumable fields: kalau beberapa kolom file di-map ke target yang
            // sama (mis. "Tambahan Express" + "Biaya Service" → biaya_tambahan),
            // jumlahkan, bukan overwrite. Berlaku utk field nominal yang
            // semantik-nya akumulatif.
            $sumableFields = ['biaya_tambahan'];
            if (in_array($targetField, $sumableFields, true) && isset($out[$targetField])) {
                $prev = self::parseAmount((string)$out[$targetField]);
                $curr = self::parseAmount((string)$val);
                $out[$targetField] = (string)($prev + $curr);
            } else {
                $out[$targetField] = $val;
            }
        }
        return $out;
    }

    // ─────────────────────────────────────────────────
    // Transform nilai (phone, currency, date)
    // ─────────────────────────────────────────────────
    private static function transform(string $value, string $note): mixed
    {
        $note = strtolower($note);

        // Currency / rupiah
        if (str_contains($note, 'currency') || str_contains($note, 'rupiah')
            || str_contains($note, 'harga') || str_contains($note, 'rp')) {
            return self::parseAmount($value);
        }

        // Phone normalization
        if (str_contains($note, 'phone') || str_contains($note, 'wa')
            || str_contains($note, 'telepon') || str_contains($note, 'hp')) {
            return self::normalizePhone($value);
        }

        // Date parsing
        if (str_contains($note, 'date') || str_contains($note, 'tanggal')) {
            return self::normalizeDate($value);
        }

        return $value;
    }

    // ─────────────────────────────────────────────────
    // Parse nominal uang → integer (handle ribuan & desimal)
    //   '13130.00'      → 13130   (. = desimal)
    //   '31.994.426,50' → 31994426 (. ribuan, , desimal)
    //   '7.000' / 'Rp 7.000' → 7000 (. ribuan)
    // ─────────────────────────────────────────────────
    public static function parseAmount(string $v): int
    {
        $v = preg_replace('/[^0-9.,]/', '', $v);
        if ($v === '') return 0;
        $lastDot   = strrpos($v, '.');
        $lastComma = strrpos($v, ',');
        $decPos    = max($lastDot === false ? -1 : $lastDot, $lastComma === false ? -1 : $lastComma);
        if ($decPos >= 0) {
            $decimals = substr($v, $decPos + 1);
            // 1-2 digit setelah separator paling kanan = desimal → buang
            $intPart  = (strlen($decimals) <= 2 && ctype_digit($decimals))
                        ? substr($v, 0, $decPos) : $v;
        } else {
            $intPart = $v;
        }
        return (int)preg_replace('/[^0-9]/', '', $intPart);
    }

    // ─────────────────────────────────────────────────
    // Normalisasi nomor HP → format 08xx
    // ─────────────────────────────────────────────────
    public static function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($phone, '62')) {
            $phone = '0' . substr($phone, 2);
        }
        return $phone;
    }

    // ─────────────────────────────────────────────────
    // Normalisasi tanggal → Y-m-d
    // ─────────────────────────────────────────────────
    public static function normalizeDate(string $val): ?string
    {
        if (empty($val)) return null;

        // Format DD/MM/YYYY atau DD-MM-YYYY
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $val, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        // ISO YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $val)) {
            return substr($val, 0, 10);
        }

        // Translate nama bulan Indonesia → Inggris supaya strtotime bisa parse
        // "2 Jun 2026" sudah OK, tapi "Mei", "Agu", "Okt", "Des" perlu translate
        $idMonths = [
            'januari'=>'January','pebruari'=>'February','februari'=>'February','maret'=>'March',
            'april'=>'April','mei'=>'May','juni'=>'June','juli'=>'July','agustus'=>'August',
            'september'=>'September','oktober'=>'October','nopember'=>'November','november'=>'November','desember'=>'December',
            'jan'=>'Jan','peb'=>'Feb','feb'=>'Feb','mar'=>'Mar','apr'=>'Apr','jun'=>'Jun','jul'=>'Jul',
            'agu'=>'Aug','agt'=>'Aug','sep'=>'Sep','okt'=>'Oct','nop'=>'Nov','nov'=>'Nov','des'=>'Dec',
        ];
        $valEn = $val;
        foreach ($idMonths as $id => $en) {
            $valEn = preg_replace('/\b' . preg_quote($id, '/') . '\b/i', $en, $valEn);
        }

        $ts = strtotime($valEn);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    /**
     * Normalisasi status pembayaran dari teks file → enum DB.
     * Map: "Lunas"→lunas, "Belum Lunas"/"Belum Bayar"→belum_bayar, "DP"→dp.
     */
    public static function normalizeStatusBayar(string $raw): string
    {
        $raw = strtolower(trim($raw));
        if ($raw === '') return 'lunas'; // historis dianggap selesai
        if (str_contains($raw, 'belum') || str_contains($raw, 'unpaid')) return 'belum_bayar';
        if (str_contains($raw, 'dp') || str_contains($raw, 'down')) return 'dp';
        if (str_contains($raw, 'lunas') || str_contains($raw, 'paid') || str_contains($raw, 'selesai')) return 'lunas';
        return 'lunas';
    }

    /**
     * Normalisasi tipe order: 'reguler' (default) / 'express' / 'kilat' / 'custom'.
     */
    public static function normalizeTipeOrder(string $raw): string
    {
        $raw = strtolower(trim($raw));
        if ($raw === '') return 'reguler';
        if (str_contains($raw, 'reguler') || str_contains($raw, 'regular') || str_contains($raw, 'standar')) return 'reguler';
        if (str_contains($raw, 'express') || str_contains($raw, 'expres')) return 'express';
        if (str_contains($raw, 'kilat') || str_contains($raw, 'super')) return 'kilat';
        return 'custom';
    }

    /**
     * Normalisasi status proses dari teks file → enum DB.
     * Map: "Sudah Diambil"/"Selesai"/"Diambil"→diambil,
     *      "Belum Diambil" + ada tgl_selesai estimasi→siap,
     *      default historis dgn tgl_selesai filled→diambil, lainnya→masuk.
     */
    public static function normalizeStatusProses(string $raw, bool $tglDiambilFilled, bool $estSelesaiFilled): string
    {
        $raw = strtolower(trim($raw));
        if ($tglDiambilFilled) return 'diambil';
        if ($raw === '') return $estSelesaiFilled ? 'siap' : 'diambil'; // historis
        if (str_contains($raw, 'belum')) return $estSelesaiFilled ? 'siap' : 'masuk';
        if (str_contains($raw, 'sudah') || str_contains($raw, 'diambil') || str_contains($raw, 'selesai') || str_contains($raw, 'done')) return 'diambil';
        if (str_contains($raw, 'cuci')) return 'cuci';
        if (str_contains($raw, 'kering')) return 'kering';
        if (str_contains($raw, 'setrika')) return 'setrika';
        if (str_contains($raw, 'siap')) return 'siap';
        return 'diambil'; // safe default utk import historis
    }

    // ─────────────────────────────────────────────────
    // Validasi mapped row
    // ─────────────────────────────────────────────────
    private static function validate(array $data, string $entityType): array
    {
        $errors = [];
        $schema = AIMigrationMapper::SCHEMAS[$entityType] ?? [];

        // Untuk transaksi, baris ini bisa jadi:
        // a) Sub-item dgn no_order yg sama → importer dedup
        // b) Sub-row Smartlink: no_order kosong, customer kosong, hanya
        //    nama_layanan terisi → importer pakai $lastTrxId
        // Keduanya: skip required check, biar importer yang handle.
        $isMultiItem = ($entityType === 'transaksi') && (
            !empty($data['no_order']) ||
            (empty($data['no_order']) && empty($data['nama_pelanggan']) && !empty($data['nama_layanan']))
        );

        foreach ($schema as $field => $def) {
            if (!$def['required']) continue;
            if ($isMultiItem) continue; // sub-row → importer yang validasi
            if (empty($data[$field])) {
                $errors[] = "Field '$field' wajib ada dan tidak boleh kosong";
            }
        }

        // Validasi tipe tambahan
        if (!empty($data['telepon'])) {
            $phone = preg_replace('/[^0-9]/', '', $data['telepon']);
            if (strlen($phone) < 9 || strlen($phone) > 15) {
                $errors[] = "Nomor telepon '{$data['telepon']}' tidak valid";
            }
        }

        if (isset($data['harga']) && ((int)$data['harga']) < 0) {
            $errors[] = 'Harga tidak boleh negatif';
        }

        if (isset($data['total']) && ((int)$data['total']) < 0) {
            $errors[] = 'Total tidak boleh negatif';
        }

        return $errors;
    }

    // ─────────────────────────────────────────────────
    // Parse file CSV atau Excel
    // ─────────────────────────────────────────────────
    public static function parseFile(string $path, string $type): array
    {
        if (!file_exists($path)) {
            throw new RuntimeException("File tidak ditemukan: $path");
        }

        $type = strtolower($type);

        if (in_array($type, ['xlsx', 'xls'])) {
            return self::parseExcel($path, $type);
        }

        return self::parseCsv($path);
    }

    private static function parseCsv(string $path): array
    {
        $enc    = self::detectEncoding($path);
        $handle = fopen($path, 'r');
        if (!$handle) throw new RuntimeException("Tidak bisa buka file CSV.");

        // Skip BOM jika ada (UTF-8 BOM = EF BB BF)
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);

        $raw   = [];
        $first = true;
        while (($data = fgetcsv($handle, 4096, ',')) !== false) {
            // Kalau baris pertama cuma 1 kolom → kemungkinan delimiter ';'
            if ($first && count($data) === 1) {
                fclose($handle);
                return self::parseCsvDelimited($path, ';');
            }
            $first = false;
            if ($enc !== 'UTF-8') {
                $data = array_map(fn($v) => mb_convert_encoding((string)$v, 'UTF-8', $enc), $data);
            }
            $raw[] = $data;
        }
        fclose($handle);
        if (empty($raw)) return [];
        // Auto-deteksi header (lewati preamble laporan) — sama dgn xlsx
        return self::rowsToAssoc($raw);
    }

    private static function parseCsvDelimited(string $path, string $delim): array
    {
        $handle = fopen($path, 'r');
        if (!$handle) throw new RuntimeException("Tidak bisa buka file CSV.");

        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);

        $raw = [];
        while (($data = fgetcsv($handle, 4096, $delim)) !== false) {
            $raw[] = $data;
        }
        fclose($handle);
        if (empty($raw)) return [];
        return self::rowsToAssoc($raw);
    }

    private static function parseExcel(string $path, string $type): array
    {
        // Coba SimpleXLSX dulu (lebih ringan, tersedia di Hostinger)
        // fallback ke parsing manual ZIP kalau tidak tersedia
        if (class_exists('SimpleXLSX')) {
            $xlsx = SimpleXLSX::parse($path);
            if (!$xlsx) throw new RuntimeException('File Excel tidak bisa dibaca (SimpleXLSX).');
            $raw = array_map(fn($r) => array_map('strval', (array)$r), $xlsx->rows());
            if (empty($raw)) return [];
            // Auto-deteksi header (lewati preamble laporan)
            return self::rowsToAssoc($raw);
        }

        // Minimal XLSX parser tanpa library
        if ($type === 'xlsx') {
            return self::parseXlsxZip($path);
        }

        throw new RuntimeException('Library SimpleXLSX tidak ditemukan. Upload file CSV sebagai alternatif.');
    }

    /**
     * Minimal XLSX parser via ZIP + XML (tanpa library eksternal).
     * Hanya baca sheet pertama, teks saja (tidak support formula/date serial).
     */
    private static function parseXlsxZip(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('File XLSX tidak bisa dibuka (ZIP error).');
        }

        // Baca shared strings
        $sharedStrings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml) {
            $ss = simplexml_load_string($ssXml, 'SimpleXMLElement', LIBXML_NOCDATA);
            if ($ss) {
                foreach ($ss->si as $si) {
                    $text = '';
                    if (isset($si->t)) {
                        $text = (string)$si->t;
                    } elseif (isset($si->r)) {
                        foreach ($si->r as $r) {
                            $text .= (string)($r->t ?? '');
                        }
                    }
                    $sharedStrings[] = $text;
                }
            }
        }

        // Baca sheet pertama
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if (!$sheetXml) {
            throw new RuntimeException('Sheet 1 tidak ditemukan dalam file XLSX.');
        }

        $sheet = simplexml_load_string($sheetXml, 'SimpleXMLElement', LIBXML_NOCDATA);
        if (!$sheet) throw new RuntimeException('Sheet XML tidak bisa diparse.');

        $rowsData = [];
        foreach ($sheet->sheetData->row ?? [] as $row) {
            // PENTING: Excel menghilangkan <c> untuk sel kosong. Kalau dibaca
            // positional, sel kosong di tengah bikin kolom geser & misalign
            // dengan header (gejala: "harga/nama tidak terbaca"). Maka kita
            // pakai atribut `r` (mis. "B2") untuk taruh nilai di kolom yang benar.
            $rowArr = [];
            foreach ($row->c as $cell) {
                $colIdx = self::colRefToIndex((string)($cell['r'] ?? ''));
                $t = (string)($cell['t'] ?? '');
                $v = (string)($cell->v ?? '');
                if ($t === 's') {
                    $val = $sharedStrings[(int)$v] ?? '';
                } elseif ($t === 'inlineStr') {
                    $val = (string)($cell->is->t ?? '');
                } else {
                    $val = $v;
                }
                if ($colIdx >= 0) $rowArr[$colIdx] = $val;
                else              $rowArr[] = $val;
            }
            // Isi gap kolom kosong dengan '' lalu re-index 0..N
            if ($rowArr) {
                $maxCol = max(array_keys($rowArr));
                for ($i = 0; $i <= $maxCol; $i++) {
                    if (!isset($rowArr[$i])) $rowArr[$i] = '';
                }
                ksort($rowArr);
                $rowArr = array_values($rowArr);
            }
            $rowsData[] = $rowArr;
        }

        if (empty($rowsData)) return [];
        return self::rowsToAssoc($rowsData);
    }

    /**
     * Ubah array baris mentah → array asosiatif (header => nilai).
     * Auto-deteksi baris header: lewati preamble (judul/metadata/ringkasan)
     * yang umum di file export laporan. Header = baris pertama yang "lebar"
     * & mayoritas teks, diikuti baris data.
     */
    private static function rowsToAssoc(array $rowsData): array
    {
        $headerIdx = self::detectHeaderRow($rowsData);
        $headerRow = $rowsData[$headerIdx] ?? [];
        $dataStart = $headerIdx + 1;

        // ── Deteksi header 2-tingkat ──
        // Banyak export laundry pakai parent-header (mis. "Detail Transaksi"
        // merged) di row N + sub-header per kolom di row N+1. Kalau parent
        // banyak slot kosong DAN row berikutnya semua teks → merge: slot
        // kosong di parent diisi sub-header, lalu data mulai N+2.
        if (isset($rowsData[$headerIdx + 1])) {
            $subRow   = $rowsData[$headerIdx + 1];
            $maxCols  = max(count($headerRow), count($subRow));
            $emptyInParentWithSub = 0;
            $colsInSub = 0;
            $textInSub = 0;
            for ($i = 0; $i < $maxCols; $i++) {
                $p = trim((string)($headerRow[$i] ?? ''));
                $s = trim((string)($subRow[$i]    ?? ''));
                if ($p === '' && $s !== '') $emptyInParentWithSub++;
                if ($s !== '') {
                    $colsInSub++;
                    if (!is_numeric(str_replace(['Rp','.',',',' '], '', $s))) $textInSub++;
                }
            }
            $subIsHeaderish = $colsInSub >= 3
                && ($textInSub / max(1, $colsInSub)) >= 0.7;
            // Butuh ≥2 slot parent kosong yang diisi sub → tanda multi-tier nyata,
            // bukan kebetulan baris pertama data mayoritas teks.
            if ($subIsHeaderish && $emptyInParentWithSub >= 2) {
                for ($i = 0; $i < $maxCols; $i++) {
                    $p = trim((string)($headerRow[$i] ?? ''));
                    $s = trim((string)($subRow[$i]    ?? ''));
                    if ($p === '' && $s !== '') $headerRow[$i] = $s;
                    // kalau parent & sub sama-sama ada → tetap pakai parent
                    // (parent biasanya label utama; sub fallback)
                }
                $dataStart = $headerIdx + 2;
            }
        }

        $headers = array_map('trim', $headerRow);

        // Pastikan nama header unik & tidak kosong (kolom kosong → "kolom_N")
        $seen = [];
        foreach ($headers as $i => $h) {
            $h = trim((string)$h);
            if ($h === '') $h = 'kolom_' . ($i + 1);
            if (isset($seen[$h])) { $seen[$h]++; $h .= '_' . $seen[$h]; }
            else $seen[$h] = 0;
            $headers[$i] = $h;
        }

        $out = [];
        $n   = count($rowsData);
        for ($r = $dataStart; $r < $n; $r++) {
            $row = $rowsData[$r];
            // skip baris kosong total
            if (!array_filter($row, fn($v) => trim((string)$v) !== '')) continue;
            while (count($row) < count($headers)) $row[] = '';
            $out[] = array_combine($headers, array_slice($row, 0, count($headers)));
        }
        return $out;
    }

    /**
     * Deteksi index baris header: scan 30 baris pertama, pilih baris dengan
     * banyak sel terisi DAN mayoritas teks (bukan angka) — ciri baris header,
     * bukan judul (1 sel) / metadata (2 sel) / data (banyak angka).
     */
    private static function detectHeaderRow(array $rows): int
    {
        $bestIdx = 0; $bestScore = -1.0;
        $scan = min(30, count($rows));
        for ($i = 0; $i < $scan; $i++) {
            $nonEmpty = array_values(array_filter($rows[$i], fn($v) => trim((string)$v) !== ''));
            $cnt = count($nonEmpty);
            if ($cnt < 3) continue; // judul/metadata → lewati
            $textCnt = 0;
            foreach ($nonEmpty as $v) {
                $vs = trim((string)$v);
                // anggap teks kalau bukan angka murni (setelah buang Rp/titik/koma)
                if (!is_numeric(str_replace(['Rp','.',',',' '], '', $vs))) $textCnt++;
            }
            $textRatio = $textCnt / $cnt;
            // header: banyak kolom + mayoritas teks. Data row biasanya banyak angka.
            $score = $textRatio >= 0.6 ? $cnt * (1 + $textRatio) : $cnt * 0.2;
            if ($score > $bestScore) { $bestScore = $score; $bestIdx = $i; }
        }
        return $bestIdx;
    }

    /** Konversi referensi kolom Excel ("A","B",...,"AA") → index 0-based. -1 kalau invalid. */
    private static function colRefToIndex(string $ref): int
    {
        if (!preg_match('/^([A-Za-z]+)/', $ref, $m)) return -1;
        $letters = strtoupper($m[1]);
        $idx = 0;
        for ($i = 0, $n = strlen($letters); $i < $n; $i++) {
            $idx = $idx * 26 + (ord($letters[$i]) - 64);
        }
        return $idx - 1;
    }

    private static function detectEncoding(string $path): string
    {
        $sample = file_get_contents($path, false, null, 0, 4096);
        if (mb_check_encoding($sample, 'UTF-8')) return 'UTF-8';
        if (mb_check_encoding($sample, 'ISO-8859-1')) return 'ISO-8859-1';
        return 'UTF-8';
    }

    // ─────────────────────────────────────────────────
    // Import satu row sesuai entitas
    // Returns 'skip' jika duplikat/tidak ada match, atau void
    // ─────────────────────────────────────────────────
    private static function importRow(
        string $entityType,
        array  $data,
        int    $tenantId,
        int    $outletId,
        int    $jobId
    ): string {
        switch ($entityType) {
            case 'layanan':
                return self::importLayanan($data, $tenantId, $outletId);

            case 'pelanggan':
                return self::importPelanggan($data, $tenantId, $outletId);

            case 'karyawan':
                return self::importKaryawan($data, $tenantId, $outletId);

            case 'transaksi':
                return self::importTransaksi($data, $tenantId, $outletId, $jobId);

            case 'poin_pelanggan':
                return self::importPoinPelanggan($data, $tenantId, $outletId);

            default:
                throw new RuntimeException("Entitas '$entityType' tidak dikenal.");
        }
    }

    // ── Layanan ───────────────────────────────────────
    private static function importLayanan(array $d, int $tid, int $oid): string
    {
        $nama = trim($d['nama'] ?? '');
        if (empty($nama)) return 'skip';

        // Skip duplikat nama di outlet ini
        if (TenantQuery::exists('hl_layanan', 'LOWER(nama) = LOWER(?)', [$nama])) {
            return 'skip';
        }

        // Kolom hl_layanan: nama, kategori, satuan, harga, urutan, is_active
        // (TIDAK ada 'keterangan' — jangan di-insert)
        TenantQuery::insert('hl_layanan', [
            'nama'      => substr($nama, 0, 100),
            'harga'     => self::parseAmount($d['harga'] ?? '0'),
            'satuan'    => substr($d['satuan'] ?? 'kg', 0, 20),
            'kategori'  => substr($d['kategori'] ?? 'Umum', 0, 50) ?: 'Umum',
            'is_active' => 1,
        ]);
        return 'ok';
    }

    // ── Pelanggan ─────────────────────────────────────
    private static function importPelanggan(array $d, int $tid, int $oid): string
    {
        $nama    = substr(trim($d['nama'] ?? ''), 0, 100);
        $telepon = self::normalizePhone($d['telepon'] ?? '');

        if (empty($nama)) return 'skip';

        // Skip duplikat nomor telepon
        if (!empty($telepon) && TenantQuery::exists('hl_pelanggan', 'telepon = ?', [$telepon])) {
            return 'skip'; // duplikat WA — skip bukan error (AC#7)
        }

        // Metode bayar: normalkan ke 'langsung' atau 'bulanan' (kolom yang dibaca app)
        $metodeBayar = 'langsung';
        $rawTipe     = strtolower(($d['tipe_bayar'] ?? '') . ' ' . ($d['metode_bayar'] ?? ''));
        if (str_contains($rawTipe, 'bulanan') || str_contains($rawTipe, 'monthly')) {
            $metodeBayar = 'bulanan';
        }

        TenantQuery::insert('hl_pelanggan', [
            'nama'         => $nama,
            'telepon'      => $telepon ?: null,
            'alamat'       => substr($d['alamat'] ?? '', 0, 300) ?: null,
            'metode_bayar' => $metodeBayar,
            'catatan'      => substr($d['catatan'] ?? '', 0, 300) ?: null,
            'is_active'    => 1,
        ]);
        return 'ok';
    }

    // ── Karyawan ──────────────────────────────────────
    private static function importKaryawan(array $d, int $tid, int $oid): string
    {
        $nama = substr(trim($d['nama'] ?? ''), 0, 100);
        if (empty($nama)) return 'skip';

        // Normalisasi role
        $roleMap = [
            'kasir'=>'kasir', 'staff'=>'staff', 'owner'=>'owner',
            'manager'=>'manager', 'admin'=>'admin', 'supervisor'=>'manager',
        ];
        $rawRole = strtolower(trim($d['role'] ?? 'staff'));
        $role    = $roleMap[$rawRole] ?? 'staff';

        $telepon = self::normalizePhone($d['telepon'] ?? '');

        // username WAJIB & UNIK (global) — generate dari nama + suffix unik.
        $base     = preg_replace('/[^a-z0-9]/', '', strtolower($nama)) ?: 'staff';
        $username = substr($base, 0, 16) . '_' . substr(md5($tid . $nama . microtime(true)), 0, 5);

        $newId = TenantQuery::insert('hl_users', [
            'outlet_id'  => $oid,
            'username'   => $username,
            'nama'       => $nama,
            'telepon'    => $telepon ?: null,
            'role'       => $role,
            'gaji_pokok' => self::parseAmount($d['gaji_pokok'] ?? '0'),
            'tgl_masuk'  => self::normalizeDate($d['tgl_masuk'] ?? '') ?: null,
            'password'   => password_hash('lamasy123', PASSWORD_DEFAULT),
            'is_active'  => 1,
        ]);

        // Assign ke outlet aktif supaya muncul di daftar karyawan outlet (best-effort)
        try {
            $kid = (int)(is_numeric($newId) ? $newId : Database::get()->lastInsertId());
            if ($kid > 0) {
                Database::get()->prepare(
                    "INSERT INTO hl_karyawan_outlet (tenant_id, karyawan_id, outlet_id, is_active, assigned_at)
                     VALUES (?,?,?,1,NOW())"
                )->execute([$tid, $kid, $oid]);
            }
        } catch (Throwable) { /* tabel mungkin belum ada */ }

        return 'ok';
    }

    // ── Transaksi ─────────────────────────────────────
    // Mendukung file MULTI-ITEM (1 nota = beberapa baris layanan):
    //   • Baris parent: ada nama_pelanggan + total + nama_layanan (item 1 inline)
    //   • Baris sub-item: nama_pelanggan ada (terisi gabungan dari Excel),
    //     no_order sama dgn parent, tapi total=0; punya nama_layanan + jumlah_item
    // Dedupe via no_order: baris pertama buat hl_transaksi, baris berikutnya
    // hanya menambah hl_transaksi_item ke transaksi yang sudah ada.
    private static function importTransaksi(array $d, int $tid, int $oid, int $jobId): string
    {
        $db      = Database::get();
        $namaPel = substr(trim($d['nama_pelanggan'] ?? ''), 0, 100);
        $noOrder = substr(trim($d['no_order'] ?? ''), 0, 30);
        $total   = self::parseAmount($d['total'] ?? '0');
        $tanggal = self::normalizeDate($d['tanggal'] ?? '') ?: date('Y-m-d');

        // Field baru dari schema yang diperluas
        $subtotalTrx = self::parseAmount($d['subtotal'] ?? '0');
        $diskon      = self::parseAmount($d['diskon']   ?? '0');
        $biayaTbh    = self::parseAmount($d['biaya_tambahan'] ?? '0');
        $dp          = self::parseAmount($d['dp']       ?? '0');
        $estSelesai  = self::normalizeDate($d['estimasi_selesai'] ?? '');
        $tglSelesai  = self::normalizeDate($d['tgl_selesai']      ?? '');
        $tipeOrder   = self::normalizeTipeOrder($d['tipe_order']  ?? '');

        // Status: kalau eksplisit di file, pakai. Kalau tidak, derive.
        $sbRaw = strtolower(trim((string)($d['status_bayar'] ?? $d['status'] ?? '')));
        $spRaw = strtolower(trim((string)($d['status_proses'] ?? '')));
        $statusBayar = self::normalizeStatusBayar($sbRaw);
        $statusProses= self::normalizeStatusProses($spRaw, !empty($tglSelesai), !empty($estSelesai));

        // ── Cari transaksi yang sudah ada (lewat no_order) ──
        $trxId  = 0;
        $trxRow = null;
        if ($noOrder !== '') {
            $q = $db->prepare("SELECT id, pelanggan_id, nama_pelanggan, total
                                 FROM hl_transaksi
                                WHERE tenant_id=? AND outlet_id=? AND no_order=? LIMIT 1");
            $q->execute([$tid, $oid, $noOrder]);
            $trxRow = $q->fetch(PDO::FETCH_ASSOC);
            $trxId  = (int)($trxRow['id'] ?? 0);
        }

        $namaLayanan = substr(trim($d['nama_layanan'] ?? ''), 0, 100);

        // ── SUB-ROW handling: kalau row ini gak punya no_order & namaPel
        //    tapi punya nama_layanan → ini SUB-ROW dari transaksi sebelumnya
        //    (pattern Smartlink dll). Attach item ke last transaksi id.
        if ($trxId === 0 && $noOrder === '' && $namaPel === '' && $namaLayanan !== '' && self::$lastTrxId > 0) {
            $trxId = self::$lastTrxId;
        }

        // ── Kalau belum ada transaksi: butuh pelanggan minimal ──
        if ($trxId === 0) {
            if ($namaPel === '') return 'skip'; // sub-baris tanpa pelanggan & tanpa context
            $telepon = self::normalizePhone($d['telepon'] ?? '');
            // Pelanggan: cari by telepon → nama → buat baru
            $pelId = 0;
            if ($telepon !== '') {
                $p = $db->prepare("SELECT id FROM hl_pelanggan WHERE tenant_id=? AND telepon=? LIMIT 1");
                $p->execute([$tid, $telepon]);
                $pelId = (int)$p->fetchColumn();
            }
            if ($pelId === 0) {
                $p = $db->prepare("SELECT id FROM hl_pelanggan WHERE tenant_id=? AND nama=? LIMIT 1");
                $p->execute([$tid, $namaPel]);
                $pelId = (int)$p->fetchColumn();
            }
            if ($pelId === 0) {
                TenantQuery::insert('hl_pelanggan', [
                    'nama' => $namaPel, 'telepon' => $telepon ?: null, 'is_active' => 1,
                ]);
                $pelId = (int)$db->lastInsertId();
            }

            // Auto-generate no_order kalau file tidak menyediakan
            if ($noOrder === '') {
                $noOrder = 'IMP-' . strtoupper(substr(md5($tid.$oid.$tanggal.$namaPel.microtime()), 0, 10));
            }

            $metodeBayar  = strtolower($d['metode_bayar'] ?? 'cash') ?: 'cash';
            // Subtotal: kalau file gak isi, fallback ke total
            $subtotalUse  = $subtotalTrx > 0 ? $subtotalTrx : $total;
            // DP & sisa: kalau lunas → DP=total; kalau dp explicit dari file → pakai itu
            if ($statusBayar === 'lunas') {
                $dpUse    = $total;
                $sisa     = 0;
            } elseif ($dp > 0) {
                $dpUse    = min($dp, $total);
                $sisa     = max(0, $total - $dpUse);
            } else {
                $dpUse    = 0;
                $sisa     = $total;
            }

            $db->prepare("
                INSERT INTO hl_transaksi
                  (tenant_id, outlet_id, no_order, tanggal,
                   pelanggan_id, nama_pelanggan, telepon,
                   subtotal, diskon, biaya_tambahan, total, dp, sisa_bayar,
                   metode_bayar, tipe_order, status_bayar, status_proses,
                   estimasi_selesai, tgl_selesai,
                   catatan, is_imported, migration_job_id, created_by)
                VALUES (?,?,?,?, ?,?,?, ?,?,?,?,?,?, ?,?,?,?, ?,?, ?,1,?,0)
            ")->execute([
                $tid, $oid, $noOrder, $tanggal,
                $pelId, $namaPel, $telepon ?: null,
                $subtotalUse, $diskon, $biayaTbh, $total, $dpUse, $sisa,
                $metodeBayar, $tipeOrder, $statusBayar, $statusProses,
                $estSelesai ?: null, $tglSelesai ?: null,
                substr($d['catatan'] ?? '', 0, 255) ?: null,
                $jobId,
            ]);
            $trxId = (int)$db->lastInsertId();
        } else {
            // Transaksi sudah ada (multi-item lanjutan). Update total kalau
            // baris ini bawa total > yang tersimpan (baris parent kemungkinan
            // diproses setelah sub-baris).
            if ($total > 0 && $total > (int)($trxRow['total'] ?? 0)) {
                $subtotalUse = $subtotalTrx > 0 ? $subtotalTrx : $total;
                $sisa = $statusBayar === 'lunas' ? 0 : max(0, $total - $dp);
                $db->prepare("UPDATE hl_transaksi
                                 SET total=?, subtotal=?, diskon=?, dp=?, sisa_bayar=?
                               WHERE id=? AND tenant_id=?")
                   ->execute([$total, $subtotalUse, $diskon, $dp, $sisa, $trxId, $tid]);
            }
        }

        // ── Insert ITEM kalau ada nama_layanan di baris ini ──
        // ($namaLayanan sudah di-declare di atas utk sub-row detection)
        if ($namaLayanan !== '') {
            $jumlah   = (float)str_replace(',', '.', (string)($d['jumlah_item'] ?? '1')) ?: 1;
            $satuan   = strtolower(substr(trim($d['satuan_item'] ?? 'pcs'), 0, 20)) ?: 'pcs';
            $subtotal = self::parseAmount($d['subtotal_item'] ?? '0');
            // Fallback: subtotal item = total transaksi kalau item-level total kosong
            if ($subtotal <= 0 && $total > 0) $subtotal = $total;
            $harga = $jumlah > 0 ? (int)round($subtotal / $jumlah) : $subtotal;

            $db->prepare("
                INSERT INTO hl_transaksi_item
                  (tenant_id, outlet_id, transaksi_id, nama_layanan,
                   satuan, jumlah, harga_satuan, subtotal)
                VALUES (?,?,?,?, ?,?,?,?)
            ")->execute([
                $tid, $oid, $trxId, $namaLayanan,
                $satuan, $jumlah, $harga, $subtotal,
            ]);
        }

        // Track last trx id utk sub-row di baris berikutnya
        if ($trxId > 0) self::$lastTrxId = $trxId;

        return 'ok';
    }

    // ── Poin Pelanggan ────────────────────────────────
    private static function importPoinPelanggan(array $d, int $tid, int $oid): string
    {
        $telepon   = self::normalizePhone($d['telepon'] ?? '');
        $saldoPoin = self::parseAmount($d['saldo_poin'] ?? '0');

        if (empty($telepon) || $saldoPoin <= 0) return 'skip';

        $db = Database::get();
        $q  = $db->prepare("SELECT id, poin_balance FROM hl_pelanggan WHERE tenant_id=? AND telepon=? LIMIT 1");
        $q->execute([$tid, $telepon]);
        $pel = $q->fetch(PDO::FETCH_ASSOC);

        if (!$pel) {
            // Tidak ketemu — skip (tidak buat pelanggan baru untuk import poin)
            $nama = trim($d['nama_pelanggan'] ?? '');
            if ($nama) {
                $q2 = $db->prepare("SELECT id, poin_balance FROM hl_pelanggan WHERE tenant_id=? AND nama=? LIMIT 1");
                $q2->execute([$tid, $nama]);
                $pel = $q2->fetch(PDO::FETCH_ASSOC);
            }
            if (!$pel) return 'skip';
        }

        $pelId      = (int)$pel['id'];
        $newBalance = (int)$pel['poin_balance'] + $saldoPoin;

        $db->prepare("UPDATE hl_pelanggan SET poin_balance=? WHERE id=? AND tenant_id=?")
           ->execute([$newBalance, $pelId, $tid]);

        // Catat di loyalty log
        try {
            $db->prepare("
                INSERT INTO hl_loyalty_log
                  (tenant_id, outlet_id, pelanggan_id, type, poin, balance_after, keterangan)
                VALUES (?,?,?, 'earn', ?,?,?)
            ")->execute([
                $tid, $oid, $pelId,
                $saldoPoin, $newBalance,
                'Import saldo poin dari sistem lama',
            ]);
        } catch (Throwable) {
            // tabel mungkin punya kolom wajib lain — skip log saja
        }

        return 'ok';
    }
}

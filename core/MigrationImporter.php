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
    public static function process(int $jobId): array
    {
        $db = Database::get();

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

            $out[$targetField] = $val;
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
            return (int)preg_replace('/[^0-9]/', '', $value);
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

        $ts = strtotime($val);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    // ─────────────────────────────────────────────────
    // Validasi mapped row
    // ─────────────────────────────────────────────────
    private static function validate(array $data, string $entityType): array
    {
        $errors = [];
        $schema = AIMigrationMapper::SCHEMAS[$entityType] ?? [];

        foreach ($schema as $field => $def) {
            if (!$def['required']) continue;
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
        $rows    = [];
        $headers = null;
        $enc     = self::detectEncoding($path);

        $handle = fopen($path, 'r');
        if (!$handle) throw new RuntimeException("Tidak bisa buka file CSV.");

        // Skip BOM jika ada (UTF-8 BOM = EF BB BF)
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);

        while (($data = fgetcsv($handle, 4096, ',')) !== false) {
            // Coba delimiter ; jika baris pertama hanya 1 kolom
            if ($headers === null && count($data) === 1) {
                rewind($handle);
                fread($handle, 3); // skip BOM again if needed
                // Restart dengan delimiter ;
                fclose($handle);
                return self::parseCsvDelimited($path, ';');
            }

            if ($headers === null) {
                $headers = array_map('trim', $data);
                // Convert encoding jika perlu
                if ($enc !== 'UTF-8') {
                    $headers = array_map(fn($h) => mb_convert_encoding($h, 'UTF-8', $enc), $headers);
                }
                continue;
            }

            // Pad row jika kolom kurang
            while (count($data) < count($headers)) $data[] = '';
            $row = array_combine($headers, array_slice($data, 0, count($headers)));

            if ($enc !== 'UTF-8') {
                $row = array_map(fn($v) => mb_convert_encoding((string)$v, 'UTF-8', $enc), $row);
            }

            $rows[] = $row;
        }
        fclose($handle);
        return $rows;
    }

    private static function parseCsvDelimited(string $path, string $delim): array
    {
        $rows    = [];
        $headers = null;
        $handle  = fopen($path, 'r');
        if (!$handle) throw new RuntimeException("Tidak bisa buka file CSV.");

        // Skip BOM
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);

        while (($data = fgetcsv($handle, 4096, $delim)) !== false) {
            if ($headers === null) {
                $headers = array_map('trim', $data);
                continue;
            }
            while (count($data) < count($headers)) $data[] = '';
            $rows[] = array_combine($headers, array_slice($data, 0, count($headers)));
        }
        fclose($handle);
        return $rows;
    }

    private static function parseExcel(string $path, string $type): array
    {
        // Coba SimpleXLSX dulu (lebih ringan, tersedia di Hostinger)
        // fallback ke parsing manual ZIP kalau tidak tersedia
        if (class_exists('SimpleXLSX')) {
            $xlsx = SimpleXLSX::parse($path);
            if (!$xlsx) throw new RuntimeException('File Excel tidak bisa dibaca (SimpleXLSX).');
            $data    = $xlsx->rows();
            $headers = array_map('trim', (array)array_shift($data));
            $rows    = [];
            foreach ($data as $row) {
                while (count($row) < count($headers)) $row[] = '';
                $r = array_combine($headers, array_slice($row, 0, count($headers)));
                $rows[] = array_map('strval', $r);
            }
            return $rows;
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

        $headers = array_map('trim', array_shift($rowsData));
        $out     = [];
        foreach ($rowsData as $row) {
            while (count($row) < count($headers)) $row[] = '';
            $out[] = array_combine($headers, array_slice($row, 0, count($headers)));
        }
        return $out;
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

        TenantQuery::insert('hl_layanan', [
            'nama'      => substr($nama, 0, 100),
            'harga'     => (int)preg_replace('/[^0-9]/', '', $d['harga'] ?? '0'),
            'satuan'    => substr($d['satuan'] ?? 'kg', 0, 20),
            'kategori'  => substr($d['kategori'] ?? '', 0, 50) ?: null,
            'keterangan'=> substr($d['keterangan'] ?? '', 0, 255) ?: null,
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

        // Tipe bayar: normalkan ke 'langsung' atau 'bulanan'
        $tipeBayar = 'langsung';
        $rawTipe   = strtolower($d['tipe_bayar'] ?? '');
        if (str_contains($rawTipe, 'bulanan') || str_contains($rawTipe, 'monthly')) {
            $tipeBayar = 'bulanan';
        }

        TenantQuery::insert('hl_pelanggan', [
            'nama'          => $nama,
            'telepon'       => $telepon ?: null,
            'alamat'        => substr($d['alamat'] ?? '', 0, 300) ?: null,
            'tipe_bayar'    => $tipeBayar,
            'catatan_tetap' => substr($d['catatan'] ?? '', 0, 255) ?: null,
            'is_active'     => 1,
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

        TenantQuery::insert('hl_users', [
            'nama'       => $nama,
            'telepon'    => $telepon ?: null,
            'role'       => $role,
            'gaji_pokok' => (int)preg_replace('/[^0-9]/', '', $d['gaji_pokok'] ?? '0'),
            'tgl_masuk'  => self::normalizeDate($d['tgl_masuk'] ?? '') ?: null,
            'password'   => password_hash('lamasy123', PASSWORD_DEFAULT),
            'is_active'  => 1,
        ]);
        return 'ok';
    }

    // ── Transaksi ─────────────────────────────────────
    private static function importTransaksi(array $d, int $tid, int $oid, int $jobId): string
    {
        $namaPel   = substr(trim($d['nama_pelanggan'] ?? ''), 0, 100);
        $total     = (int)preg_replace('/[^0-9]/', '', $d['total'] ?? '0');
        $tanggal   = self::normalizeDate($d['tanggal'] ?? '') ?: date('Y-m-d');
        $namaLayanan = substr(trim($d['nama_layanan'] ?? ''), 0, 100);

        if (empty($namaPel) || empty($namaLayanan)) return 'skip';

        $db      = Database::get();
        $telepon = self::normalizePhone($d['telepon'] ?? '');

        // Cari atau buat pelanggan
        $pel = null;
        if (!empty($telepon)) {
            $q = $db->prepare("SELECT id FROM hl_pelanggan WHERE tenant_id=? AND telepon=? LIMIT 1");
            $q->execute([$tid, $telepon]);
            $pel = $q->fetch(PDO::FETCH_ASSOC);
        }
        if (!$pel) {
            $q2 = $db->prepare("SELECT id FROM hl_pelanggan WHERE tenant_id=? AND nama=? LIMIT 1");
            $q2->execute([$tid, $namaPel]);
            $pel = $q2->fetch(PDO::FETCH_ASSOC);
        }
        if (!$pel) {
            TenantQuery::insert('hl_pelanggan', [
                'nama'      => $namaPel,
                'telepon'   => $telepon ?: null,
                'is_active' => 1,
            ]);
            $pelId = (int)$db->lastInsertId();
        } else {
            $pelId = (int)$pel['id'];
        }

        // Buat no_order unik untuk data import
        $noOrder = 'IMP-' . strtoupper(substr(md5($tid.$oid.$tanggal.$namaPel.$namaLayanan.microtime()), 0, 8));

        // Berat
        $beratKg = isset($d['berat_kg']) ? (float)str_replace(',', '.', $d['berat_kg']) : null;

        // Status proses — semua data lama = sudah selesai
        $statusProses = 'diambil';
        $statusBayar  = 'lunas';
        $metodeBayar  = strtolower($d['metode_bayar'] ?? 'cash');

        $db->prepare("
            INSERT INTO hl_transaksi
              (tenant_id, outlet_id, no_order, tanggal,
               pelanggan_id, nama_pelanggan, telepon,
               subtotal, diskon, total, dp, sisa_bayar,
               metode_bayar, status_bayar, status_proses,
               catatan, is_imported, migration_job_id, created_by)
            VALUES
              (?,?,?,?, ?,?,?, ?,?,?,?,?, ?,?,?, ?,1,?,0)
        ")->execute([
            $tid, $oid, $noOrder, $tanggal,
            $pelId, $namaPel, $telepon ?: null,
            $total, 0, $total, $total, 0,
            $metodeBayar ?: 'cash', $statusBayar, $statusProses,
            substr($d['catatan'] ?? '', 0, 255) ?: null,
            $jobId,
        ]);

        $trxId = (int)$db->lastInsertId();

        // Insert item layanan
        if ($trxId && !empty($namaLayanan)) {
            $db->prepare("
                INSERT INTO hl_transaksi_item
                  (tenant_id, outlet_id, transaksi_id, nama_layanan,
                   qty, berat_kg, harga_satuan, subtotal)
                VALUES (?,?,?,?, 1,?,?,?)
            ")->execute([
                $tid, $oid, $trxId, $namaLayanan,
                $beratKg, $total, $total,
            ]);
        }

        return 'ok';
    }

    // ── Poin Pelanggan ────────────────────────────────
    private static function importPoinPelanggan(array $d, int $tid, int $oid): string
    {
        $telepon   = self::normalizePhone($d['telepon'] ?? '');
        $saldoPoin = (int)preg_replace('/[^0-9]/', '', $d['saldo_poin'] ?? '0');

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

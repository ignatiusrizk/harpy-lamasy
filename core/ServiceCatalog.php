<?php
// ══════════════════════════════════════════════════════
// core/ServiceCatalog.php — Master katalog layanan terpusat
//
// HQ kelola master (hl_layanan_master), lalu push/sync ke baris
// per-outlet (hl_layanan) via master_id.
//
// Aturan sync:
//   - Push ke outlet → upsert baris hl_layanan (match master_id+outlet_id)
//   - Field nama/kategori/satuan selalu di-sync dari master
//   - Harga: di-set ke harga_default KECUALI baris outlet di-override
//     (harga_overridden=1) dan overwriteOverrides=false
// ══════════════════════════════════════════════════════

class ServiceCatalog
{
    /** List master katalog tenant */
    public static function listMaster(int $tenantId, bool $activeOnly = false): array
    {
        $db = Database::get();
        $sql = "SELECT * FROM hl_layanan_master WHERE tenant_id=?";
        if ($activeOnly) $sql .= " AND is_active=1";
        $sql .= " ORDER BY urutan ASC, nama ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Get satu master */
    public static function getMaster(int $tenantId, int $id): ?array
    {
        $db = Database::get();
        $stmt = $db->prepare("SELECT * FROM hl_layanan_master WHERE tenant_id=? AND id=?");
        $stmt->execute([$tenantId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Create / update master. Return id. */
    public static function saveMaster(int $tenantId, array $data, ?int $id = null): int
    {
        $db = Database::get();
        $validSegmen = ['kiloan','self_service','b2b','satuan','lainnya'];
        $segmen = in_array($data['segmen'] ?? '', $validSegmen, true) ? $data['segmen'] : 'kiloan';
        $fields = [
            'nama'             => trim($data['nama'] ?? ''),
            'kategori'         => trim($data['kategori'] ?? 'Umum'),
            'satuan'           => trim($data['satuan'] ?? 'kg'),
            'harga_default'    => (float)($data['harga_default'] ?? 0),
            'urutan'           => (int)($data['urutan'] ?? 0),
            'is_active'        => (int)($data['is_active'] ?? 1),
            'allow_override'   => (int)($data['allow_override'] ?? 0),
            'override_max_pct' => (float)($data['override_max_pct'] ?? 0),
            'segmen'           => $segmen,
        ];
        if ($fields['nama'] === '') {
            throw new RuntimeException('Nama layanan wajib diisi.');
        }

        if ($id) {
            $sql = "UPDATE hl_layanan_master SET nama=?, kategori=?, satuan=?, harga_default=?,
                       urutan=?, is_active=?, allow_override=?, override_max_pct=?, segmen=?
                     WHERE id=? AND tenant_id=?";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                $fields['nama'], $fields['kategori'], $fields['satuan'], $fields['harga_default'],
                $fields['urutan'], $fields['is_active'], $fields['allow_override'], $fields['override_max_pct'],
                $fields['segmen'], $id, $tenantId,
            ]);
            return $id;
        } else {
            $sql = "INSERT INTO hl_layanan_master
                      (tenant_id, nama, kategori, satuan, harga_default, urutan, is_active, allow_override, override_max_pct, segmen)
                    VALUES (?,?,?,?,?,?,?,?,?,?)";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                $tenantId, $fields['nama'], $fields['kategori'], $fields['satuan'], $fields['harga_default'],
                $fields['urutan'], $fields['is_active'], $fields['allow_override'], $fields['override_max_pct'],
                $fields['segmen'],
            ]);
            return (int)$db->lastInsertId();
        }
    }

    /**
     * Import massal master layanan dari array baris (hasil parse Excel/CSV).
     * Kolom dikenali fleksibel (nama/name, harga/price, kategori/category, satuan/unit, segmen).
     *
     * @return array {imported:int, errors:[{baris:int, error:string, data:array}]}
     */
    public static function importMaster(int $tenantId, array $rows): array
    {
        $imported = 0;
        $errors   = [];

        // Peta header fleksibel → field internal
        $map = [
            'nama'     => ['nama','name','layanan','nama layanan','nama_layanan','service'],
            'kategori' => ['kategori','category','jenis','kelompok'],
            'satuan'   => ['satuan','unit','uom'],
            'harga'    => ['harga','price','harga_default','harga default','tarif','harga satuan'],
            'segmen'   => ['segmen','segment','tipe','type'],
        ];

        // Normalisasi: ambil nilai dari baris berdasarkan kemungkinan nama kolom
        $pick = function(array $row, array $aliases) {
            foreach ($row as $k => $v) {
                $key = strtolower(trim((string)$k));
                if (in_array($key, $aliases, true)) return trim((string)$v);
            }
            return '';
        };

        $validSegmen = ['kiloan','self_service','b2b','satuan','lainnya'];

        foreach ($rows as $i => $row) {
            $baris = $i + 2; // +1 header, +1 ke 1-based
            if (!is_array($row)) continue;

            $nama  = $pick($row, $map['nama']);
            $hargaRaw = $pick($row, $map['harga']);
            // Bersihkan harga: buang Rp, titik ribuan, spasi
            $harga = (float)preg_replace('/[^0-9.]/', '', str_replace(',', '', $hargaRaw));

            // Skip baris kosong total
            if ($nama === '' && $hargaRaw === '') continue;

            if ($nama === '') {
                $errors[] = ['baris'=>$baris, 'error'=>'Nama layanan kosong', 'data'=>$row];
                continue;
            }
            if ($harga <= 0) {
                $errors[] = ['baris'=>$baris, 'error'=>'Harga tidak valid / kosong', 'data'=>$row];
                continue;
            }

            $segmen = strtolower($pick($row, $map['segmen']));
            if (!in_array($segmen, $validSegmen, true)) $segmen = 'kiloan';

            try {
                self::saveMaster($tenantId, [
                    'nama'          => $nama,
                    'kategori'      => $pick($row, $map['kategori']) ?: 'Umum',
                    'satuan'        => $pick($row, $map['satuan']) ?: 'kg',
                    'harga_default' => $harga,
                    'segmen'        => $segmen,
                    'is_active'     => 1,
                ]);
                $imported++;
            } catch (Throwable $e) {
                $errors[] = ['baris'=>$baris, 'error'=>$e->getMessage(), 'data'=>$row];
            }
        }

        return ['imported'=>$imported, 'errors'=>$errors];
    }

    /**
     * Push BANYAK master ke BANYAK outlet sekaligus.
     * @return array {total_push:int, created:int, updated:int, skipped_override:int}
     */
    public static function pushManyToOutlets(int $tenantId, array $masterIds, array $outletIds, bool $overwriteOverrides = false): array
    {
        $sum = ['total_push'=>0, 'created'=>0, 'updated'=>0, 'skipped_override'=>0];
        foreach ($masterIds as $mid) {
            $mid = (int)$mid;
            if ($mid <= 0) continue;
            try {
                $r = self::pushToOutlets($tenantId, $mid, $outletIds, $overwriteOverrides);
                $sum['total_push']++;
                $sum['created']          += $r['created'];
                $sum['updated']          += $r['updated'];
                $sum['skipped_override'] += $r['skipped_override'];
            } catch (Throwable) { /* skip master invalid */ }
        }
        return $sum;
    }

    /** Soft-delete master + (opsional) cabut dari outlet */
    public static function deleteMaster(int $tenantId, int $id, bool $removeFromOutlets = true): void
    {
        $db = Database::get();
        $db->prepare("UPDATE hl_layanan_master SET is_active=0 WHERE id=? AND tenant_id=?")
           ->execute([$id, $tenantId]);
        if ($removeFromOutlets) {
            $db->prepare("UPDATE hl_layanan SET is_active=0 WHERE master_id=? AND tenant_id=?")
               ->execute([$id, $tenantId]);
        }
    }

    /**
     * Push master ke daftar outlet (upsert baris hl_layanan).
     *
     * @param int   $tenantId
     * @param int   $masterId
     * @param int[] $outletIds          outlet tujuan
     * @param bool  $overwriteOverrides true = timpa harga walaupun outlet sudah override
     * @return array {created:int, updated:int, skipped_override:int}
     */
    public static function pushToOutlets(int $tenantId, int $masterId, array $outletIds, bool $overwriteOverrides = false): array
    {
        $master = self::getMaster($tenantId, $masterId);
        if (!$master) throw new RuntimeException('Master layanan tidak ditemukan.');

        $db = Database::get();
        $created = 0; $updated = 0; $skippedOverride = 0;

        foreach ($outletIds as $oid) {
            $oid = (int)$oid;
            if ($oid <= 0) continue;

            // Cek baris existing untuk master+outlet
            $chk = $db->prepare("SELECT id, harga_overridden FROM hl_layanan
                                  WHERE tenant_id=? AND outlet_id=? AND master_id=? LIMIT 1");
            $chk->execute([$tenantId, $oid, $masterId]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $isOverridden = (int)$existing['harga_overridden'] === 1;
                if ($isOverridden && !$overwriteOverrides) {
                    // Sync metadata tapi JANGAN sentuh harga
                    $db->prepare("UPDATE hl_layanan SET nama=?, kategori=?, satuan=?, segmen=?, is_active=?
                                   WHERE id=? AND tenant_id=?")
                       ->execute([$master['nama'], $master['kategori'], $master['satuan'],
                                  $master['segmen'] ?? 'kiloan', $master['is_active'],
                                  $existing['id'], $tenantId]);
                    $skippedOverride++;
                } else {
                    $db->prepare("UPDATE hl_layanan SET nama=?, kategori=?, satuan=?, segmen=?, harga=?,
                                     is_active=?, harga_overridden=0
                                   WHERE id=? AND tenant_id=?")
                       ->execute([$master['nama'], $master['kategori'], $master['satuan'],
                                  $master['segmen'] ?? 'kiloan', $master['harga_default'],
                                  $master['is_active'], $existing['id'], $tenantId]);
                    $updated++;
                }
            } else {
                // Insert baru
                $db->prepare("INSERT INTO hl_layanan
                                (tenant_id, outlet_id, master_id, nama, kategori, satuan, segmen, harga, urutan, is_active, harga_overridden)
                              VALUES (?,?,?,?,?,?,?,?,?,?,0)")
                   ->execute([$tenantId, $oid, $masterId, $master['nama'], $master['kategori'],
                              $master['satuan'], $master['segmen'] ?? 'kiloan',
                              $master['harga_default'], $master['urutan'], $master['is_active']]);
                $created++;
            }
        }

        return ['created'=>$created, 'updated'=>$updated, 'skipped_override'=>$skippedOverride];
    }

    /**
     * Coverage: outlet mana yang sudah punya master ini + harga aktualnya.
     * @return array [outlet_id => {nama_outlet, harga, harga_overridden, has}]
     */
    public static function coverage(int $tenantId, int $masterId): array
    {
        $db = Database::get();
        $stmt = $db->prepare("
            SELECT o.id outlet_id, o.nama_outlet, l.harga, l.harga_overridden, l.is_active
              FROM outlets o
              LEFT JOIN hl_layanan l ON l.outlet_id=o.id AND l.master_id=? AND l.tenant_id=?
             WHERE o.tenant_id=? AND o.status IN ('trial','grace','active')
             ORDER BY o.is_main DESC, o.nama_outlet ASC
        ");
        $stmt->execute([$masterId, $tenantId, $tenantId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['outlet_id']] = [
                'nama_outlet'      => $r['nama_outlet'],
                'harga'            => $r['harga'] !== null ? (int)$r['harga'] : null,
                'harga_overridden' => (int)($r['harga_overridden'] ?? 0),
                'has'              => $r['harga'] !== null && (int)$r['is_active'] === 1,
            ];
        }
        return $out;
    }

    /**
     * Set harga override untuk 1 outlet (dipakai outlet view, validasi ±max_pct).
     * @return bool
     */
    public static function setOutletOverride(int $tenantId, int $outletId, int $masterId, float $hargaBaru): bool
    {
        $master = self::getMaster($tenantId, $masterId);
        if (!$master) throw new RuntimeException('Master tidak ditemukan.');
        if ((int)$master['allow_override'] !== 1) {
            throw new RuntimeException('Outlet tidak diizinkan override harga layanan ini.');
        }

        $base = (float)$master['harga_default'];
        $maxPct = (float)$master['override_max_pct'];
        if ($base > 0 && $maxPct > 0) {
            $min = $base * (1 - $maxPct / 100);
            $max = $base * (1 + $maxPct / 100);
            if ($hargaBaru < $min || $hargaBaru > $max) {
                throw new RuntimeException(sprintf(
                    'Harga harus di rentang Rp %s – Rp %s (±%s%% dari default Rp %s).',
                    number_format($min,0,',','.'), number_format($max,0,',','.'),
                    rtrim(rtrim(number_format($maxPct,1),'0'),'.'), number_format($base,0,',','.')
                ));
            }
        }

        $db = Database::get();
        $stmt = $db->prepare("UPDATE hl_layanan SET harga=?, harga_overridden=1
                               WHERE tenant_id=? AND outlet_id=? AND master_id=?");
        $stmt->execute([$hargaBaru, $tenantId, $outletId, $masterId]);
        return $stmt->rowCount() > 0;
    }
}

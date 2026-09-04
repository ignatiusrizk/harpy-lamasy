<?php
// core/BagiHasilCalculator.php — Hitung & distribusi bagi hasil investor.
require_once __DIR__ . '/FinancialCalculator.php';

class BagiHasilCalculator
{
    // Hitung bagi hasil semua investor aktif untuk periode (read-only).
    public static function hitung(int $tenantId, string $periode): array
    {
        $db = Database::get();
        $st = $db->prepare("SELECT id, nama, scope, outlet_id, persentase
                            FROM hl_investor
                            WHERE tenant_id=? AND is_active=1
                            ORDER BY nama");
        $st->execute([$tenantId]);
        $investors = $st->fetchAll(PDO::FETCH_ASSOC);

        // Cache laba per scope-key supaya tidak hitung konsolidasi berkali-kali
        $labaCache = [];
        $rows = [];
        foreach ($investors as $inv) {
            $oid = $inv['scope'] === 'outlet' ? (int)$inv['outlet_id'] : null;
            $key = $oid === null ? 'tenant' : ('outlet_' . $oid);
            if (!array_key_exists($key, $labaCache)) {
                try {
                    $labaCache[$key] = (int) FinancialCalculator::labaRugi($tenantId, $oid, $periode)['laba_bersih'];
                } catch (Throwable) {
                    $labaCache[$key] = 0;
                }
            }
            $laba = $labaCache[$key];
            $persen = (float)$inv['persentase'];
            $jumlah = (int) round($laba * $persen / 100);

            // status periode ini
            $s2 = $db->prepare("SELECT status FROM hl_bagi_hasil
                                WHERE tenant_id=? AND investor_id=? AND periode=? LIMIT 1");
            $s2->execute([$tenantId, $inv['id'], $periode]);
            $status = $s2->fetchColumn() ?: 'pending';

            $rows[] = [
                'investor_id' => (int)$inv['id'],
                'nama'        => $inv['nama'],
                'scope'       => $inv['scope'],
                'outlet_id'   => $inv['outlet_id'] !== null ? (int)$inv['outlet_id'] : null,
                'persentase'  => $persen,
                'laba_basis'  => $laba,
                'jumlah'      => $jumlah,
                'status'      => $status,
            ];
        }
        return $rows;
    }

    // Distribusi 1 investor: UPSERT bagi_hasil + INSERT kas keluar + jurnal prive.
    // Tolak kalau laba_basis <= 0 atau sudah 'dibayar'.
    public static function distribusi(int $tenantId, int $investorId, string $periode, int $userId): array
    {
        $db = Database::get();

        // Load investor (tenant scope)
        $s = $db->prepare("SELECT id, nama, scope, outlet_id, persentase
                           FROM hl_investor WHERE id=? AND tenant_id=? AND is_active=1 LIMIT 1");
        $s->execute([$investorId, $tenantId]);
        $inv = $s->fetch(PDO::FETCH_ASSOC);
        if (!$inv) return ['ok'=>false, 'error'=>'Investor tidak ditemukan'];

        // Sudah dibayar?
        $c = $db->prepare("SELECT status FROM hl_bagi_hasil WHERE tenant_id=? AND investor_id=? AND periode=? LIMIT 1");
        $c->execute([$tenantId, $investorId, $periode]);
        if ($c->fetchColumn() === 'dibayar') return ['ok'=>false, 'error'=>'Sudah didistribusi periode ini'];

        // Laba basis
        $oid = $inv['scope'] === 'outlet' ? (int)$inv['outlet_id'] : null;
        $laba = (int) FinancialCalculator::labaRugi($tenantId, $oid, $periode)['laba_bersih'];
        if ($laba <= 0) return ['ok'=>false, 'error'=>'Laba periode ≤ 0, tidak bisa distribusi'];

        $persen = (float)$inv['persentase'];
        $jumlah = (int) round($laba * $persen / 100);
        if ($jumlah <= 0) return ['ok'=>false, 'error'=>'Jumlah bagi hasil 0'];

        // COA id prive (3-1003) + outlet untuk kas/jurnal
        $kasOutlet = $oid ?: (int) TenantResolver::outletId();
        $coaPrive = self::coaIdByKode($db, $tenantId, '3-1003');

        // Anchor tanggal ke periode (akhir bulan periode), tapi jangan future-date
        $lastDayPeriode = date('Y-m-t', strtotime($periode . '-01'));
        $tgl = min($lastDayPeriode, date('Y-m-d'));

        $db->beginTransaction();
        try {
            // 1. UPSERT hl_bagi_hasil (snapshot)
            $dibayarAt = date('Y-m-d H:i:s');
            $db->prepare("INSERT INTO hl_bagi_hasil
                (tenant_id, investor_id, periode, laba_basis, persentase, jumlah, status, dibayar_at)
                VALUES (?,?,?,?,?,?, 'dibayar', ?)
                ON DUPLICATE KEY UPDATE
                  laba_basis=VALUES(laba_basis), persentase=VALUES(persentase),
                  jumlah=VALUES(jumlah), status='dibayar', dibayar_at=VALUES(dibayar_at)")
               ->execute([$tenantId, $investorId, $periode, $laba, $persen, $jumlah, $dibayarAt]);
            $bagiHasilId = (int)$db->lastInsertId();
            if (!$bagiHasilId) {
                $g = $db->prepare("SELECT id FROM hl_bagi_hasil WHERE tenant_id=? AND investor_id=? AND periode=?");
                $g->execute([$tenantId, $investorId, $periode]);
                $bagiHasilId = (int)$g->fetchColumn();
            }

            // 2. INSERT kas keluar
            $ket = "Bagi hasil investor: {$inv['nama']} periode {$periode}";
            $db->prepare("INSERT INTO hl_kas
                (tenant_id, outlet_id, tanggal, tipe, kategori, keterangan, jumlah, created_by, created_at)
                VALUES (?,?,?, 'keluar', 'bagi_hasil', ?, ?, ?, ?)")
               ->execute([$tenantId, $kasOutlet, $tgl, $ket, $jumlah, $userId, date('Y-m-d H:i:s')]);
            $kasId = (int)$db->lastInsertId();

            // 3. INSERT jurnal prive
            $jurnalId = null;
            if ($coaPrive) {
                $db->prepare("INSERT INTO hl_jurnal_manual
                    (tenant_id, outlet_id, coa_id, tanggal, periode, keterangan, tipe, jumlah, arah, input_by)
                    VALUES (?,?,?,?,?,?, 'prive', ?, 'debit', ?)")
                   ->execute([$tenantId, $kasOutlet, $coaPrive, $tgl, $periode, $ket, $jumlah, $userId]);
                $jurnalId = (int)$db->lastInsertId();
            }

            // 4. Link
            $db->prepare("UPDATE hl_bagi_hasil SET kas_id=?, jurnal_id=? WHERE id=?")
               ->execute([$kasId, $jurnalId, $bagiHasilId]);

            $db->commit();
            return ['ok'=>true, 'jumlah'=>$jumlah];
        } catch (Throwable $e) {
            $db->rollBack();
            return ['ok'=>false, 'error'=>$e->getMessage()];
        }
    }

    // Helper: cari coa_id by kode (untuk prive/modal)
    private static function coaIdByKode(PDO $db, int $tenantId, string $kode): ?int
    {
        $s = $db->prepare("SELECT id FROM hl_coa WHERE tenant_id=? AND kode=? LIMIT 1");
        $s->execute([$tenantId, $kode]);
        $id = $s->fetchColumn();
        return $id ? (int)$id : null;
    }
}

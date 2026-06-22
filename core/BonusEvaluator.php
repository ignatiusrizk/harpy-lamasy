<?php
// core/BonusEvaluator.php — Evaluate bonus & penalti rule untuk gaji bulanan

class BonusEvaluator
{
    /** Hitung jumlah hari kerja dalam bulan (MVP: Senin-Sabtu, skip Minggu). */
    public static function workdays(string $bulan): int
    {
        // $bulan format 'YYYY-MM'
        $start = strtotime($bulan . '-01');
        if (!$start) return 26;
        $daysInMonth = (int)date('t', $start);
        $count = 0;
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $ts = strtotime($bulan . '-' . str_pad($d, 2, '0', STR_PAD_LEFT));
            if (date('N', $ts) < 7) $count++; // 1-6 = Senin-Sabtu
        }
        return $count;
    }

    /** Return rules yang apply untuk karyawan di outlet tertentu. */
    private static function rulesForOutlet(int $tid, int $outletId): array
    {
        $db = Database::get();
        $st = $db->prepare(
            "SELECT r.* FROM hl_bonus_rule r
              WHERE r.tenant_id=? AND r.is_active=1
                AND (NOT EXISTS (SELECT 1 FROM hl_bonus_rule_outlet WHERE rule_id=r.id)
                     OR EXISTS (SELECT 1 FROM hl_bonus_rule_outlet WHERE rule_id=r.id AND outlet_id=?))
              ORDER BY r.tipe"
        );
        $st->execute([$tid, $outletId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Evaluate rules, return array komponen (NOT yet persisted). */
    public static function evaluate(int $tid, int $userId, string $bulan, int $gajiPokok): array
    {
        $db = Database::get();

        // Resolve karyawan outlet
        $u = $db->prepare("SELECT outlet_id FROM hl_users WHERE id=? AND tenant_id=? LIMIT 1");
        $u->execute([$userId, $tid]);
        $outletId = (int)($u->fetchColumn() ?: 0);
        if ($outletId === 0) return [];

        // Outlet config (jam_buka)
        $oRow = $db->prepare("SELECT jam_buka FROM outlets WHERE id=? AND tenant_id=? LIMIT 1");
        $oRow->execute([$outletId, $tid]);
        $jamBuka = $oRow->fetchColumn() ?: '08:00:00';

        // Absensi bulan
        $absSt = $db->prepare("SELECT tanggal, jam_masuk, jam_keluar, durasi_menit, status
                                 FROM hl_absensi
                                WHERE tenant_id=? AND user_id=? AND tanggal LIKE ?");
        $absSt->execute([$tid, $userId, $bulan . '-%']);
        $absen = $absSt->fetchAll(PDO::FETCH_ASSOC);

        // Stats
        $workdays = self::workdays($bulan);
        $hadirCount = 0;
        $tepatWaktuCount = 0;
        $telatCount = 0;
        $izinSakitCount = 0;
        foreach ($absen as $a) {
            if ($a['status'] === 'hadir') {
                $hadirCount++;
                if ($a['jam_masuk'] && $a['jam_masuk'] <= $jamBuka) $tepatWaktuCount++;
                if ($a['jam_masuk'] && $a['jam_masuk'] > $jamBuka)  $telatCount++;
            } elseif (in_array($a['status'], ['izin','sakit'], true)) {
                $izinSakitCount++;
            }
        }

        // Apply rules
        $rules = self::rulesForOutlet($tid, $outletId);
        $komponen = [];
        foreach ($rules as $r) {
            $thr = (int)$r['threshold'];
            $amt = (int)$r['amount'];
            $perUnit = (int)$r['amount_per_unit'] === 1;
            $name = $r['nama'];
            switch ($r['tipe']) {
                case 'hadir_penuh':
                    if ($hadirCount >= $workdays) {
                        $komponen[] = ['jenis'=>'bonus_hadir_penuh','rule_id'=>$r['id'],'nama'=>$name,'amount'=>$amt,'keterangan'=>"Hadir $hadirCount/$workdays"];
                    }
                    break;
                case 'tepat_waktu':
                    if ($tepatWaktuCount >= $thr) {
                        $komponen[] = ['jenis'=>'bonus_tepat_waktu','rule_id'=>$r['id'],'nama'=>$name,'amount'=>$amt,'keterangan'=>"Tepat waktu $tepatWaktuCount hari"];
                    }
                    break;
                case 'lembur':
                    // Lembur = sum(durasi excess di atas threshold per hari)
                    $excess = 0;
                    foreach ($absen as $a) {
                        if ($a['status'] === 'hadir') {
                            $d = (int)($a['durasi_menit'] ?? 0);
                            if ($d > $thr) $excess += ($d - $thr);
                        }
                    }
                    if ($excess > 0) {
                        $bonus = $perUnit ? ($excess * $amt) : $amt;
                        $komponen[] = ['jenis'=>'bonus_lembur','rule_id'=>$r['id'],'nama'=>$name,'amount'=>$bonus,'keterangan'=>"Lembur $excess menit excess"];
                    }
                    break;
                case 'zero_izin':
                    if ($izinSakitCount === 0 && $hadirCount > 0) {
                        $komponen[] = ['jenis'=>'bonus_zero_izin','rule_id'=>$r['id'],'nama'=>$name,'amount'=>$amt,'keterangan'=>"Tidak ada izin/sakit"];
                    }
                    break;
                case 'penalti_telat':
                    if ($telatCount > $thr) {
                        $excess = $telatCount - $thr;
                        $penalti = $perUnit ? ($excess * $amt) : $amt;
                        // Negative untuk potongan
                        $komponen[] = ['jenis'=>'penalti_telat','rule_id'=>$r['id'],'nama'=>$name,'amount'=>-abs($penalti),'keterangan'=>"Telat $telatCount kali (max $thr)"];
                    }
                    break;
            }
        }

        return $komponen;
    }

    /** Evaluate + persist komponen + recompute hl_gaji.bonus/potongan/total. */
    public static function applyToGaji(int $gajiId): void
    {
        $db = Database::get();
        $gj = $db->prepare("SELECT * FROM hl_gaji WHERE id=? LIMIT 1");
        $gj->execute([$gajiId]);
        $gaji = $gj->fetch(PDO::FETCH_ASSOC);
        if (!$gaji) return;

        $tid = (int)$gaji['tenant_id'];
        $userId = (int)$gaji['user_id'];
        $bulan = (string)$gaji['bulan'];
        $gajiPokok = (int)$gaji['gaji_pokok'];

        try {
            $db->beginTransaction();

            // DELETE komponen non-manual (preserve owner manual adjustments)
            $del = $db->prepare("DELETE FROM hl_gaji_komponen WHERE gaji_id=? AND jenis != 'manual'");
            $del->execute([$gajiId]);

            // INSERT komponen pokok
            $insPokok = $db->prepare("INSERT INTO hl_gaji_komponen (gaji_id, jenis, rule_id, nama, amount, keterangan) VALUES (?, 'pokok', NULL, 'Gaji Pokok', ?, NULL)");
            $insPokok->execute([$gajiId, $gajiPokok]);

            // Evaluate + INSERT rule-driven komponen
            $komponen = self::evaluate($tid, $userId, $bulan, $gajiPokok);
            $insK = $db->prepare("INSERT INTO hl_gaji_komponen (gaji_id, jenis, rule_id, nama, amount, keterangan) VALUES (?,?,?,?,?,?)");
            foreach ($komponen as $k) {
                $insK->execute([$gajiId, $k['jenis'], $k['rule_id'], $k['nama'], (int)$k['amount'], $k['keterangan']]);
            }

            // Recompute gaji totals (sum semua komponen including manual)
            $sumSt = $db->prepare("SELECT SUM(CASE WHEN amount>0 AND jenis!='pokok' THEN amount ELSE 0 END) AS sum_bonus,
                                          SUM(CASE WHEN amount<0 THEN ABS(amount) ELSE 0 END) AS sum_pot
                                     FROM hl_gaji_komponen WHERE gaji_id=?");
            $sumSt->execute([$gajiId]);
            $sums = $sumSt->fetch(PDO::FETCH_ASSOC);
            $bonus = (int)($sums['sum_bonus'] ?? 0);
            $potongan = (int)($sums['sum_pot'] ?? 0);
            $total = $gajiPokok + $bonus - $potongan;

            $upd = $db->prepare("UPDATE hl_gaji SET bonus=?, potongan=?, total=? WHERE id=?");
            $upd->execute([$bonus, $potongan, $total, $gajiId]);

            try { logAudit('gaji_bonus_eval', 'gaji', "id=$gajiId komponen=" . count($komponen) . " bonus=$bonus pot=$potongan"); } catch (Throwable $e) {}
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[BonusEvaluator applyToGaji] ' . $e->getMessage());
        }
    }
}

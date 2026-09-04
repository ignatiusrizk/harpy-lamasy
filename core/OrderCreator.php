<?php
/**
 * OrderCreator — pembuatan order. createOffline() menangani SUBSET offline
 * (layanan+tier+tunai/DP) — SENGAJA terpisah dari jalur pos.php?action=save
 * yang kaya (deposit/redeem/voucher). Lihat plan Global Constraints.
 */
class OrderCreator
{
    private const ONLINE_ONLY_FIELDS = ['redeem_poin','voucher_id','promo_id','reward_id','pakai_deposit'];

    /** @return string[] daftar error (kosong = valid) */
    public static function validateOfflinePayload(array $p, array $validLayananIds, array $validTierNames = []): array
    {
        $errs = [];
        $items = $p['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            $errs[] = 'Order tanpa item';
            return $errs; // tak lanjut cek item
        }
        foreach ($items as $i => $it) {
            $lid = (int)($it['layanan_id'] ?? 0);
            if (!in_array($lid, $validLayananIds, true)) {
                $errs[] = "Layanan tidak dikenal (item ".($i+1).")";
            }
            $qty = (float)($it['jumlah'] ?? $it['qty'] ?? 0);
            if ($qty <= 0) {
                $errs[] = "Qty tidak valid (item ".($i+1).")";
            }
            $tn = trim((string)($it['express_tier_nama'] ?? ''));
            if ($tn !== '' && $validTierNames !== [] && !in_array($tn, $validTierNames, true)) {
                $errs[] = "Tier tidak dikenal (item ".($i+1).")";
            }
        }
        $total = (float)($p['total'] ?? 0);
        $dp    = (float)($p['dp'] ?? 0);
        if ($total < 0)         $errs[] = 'Total negatif';
        if ($dp < 0)            $errs[] = 'DP negatif';
        if ($dp > $total)       $errs[] = 'DP melebihi total';
        if (($p['metode_bayar'] ?? $p['metode'] ?? 'cash') !== 'cash') $errs[] = 'Metode offline harus tunai';
        foreach (self::ONLINE_ONLY_FIELDS as $f) {
            if (!empty($p[$f])) $errs[] = "Field online-only tidak diizinkan offline: $f";
        }
        return $errs;
    }

    public static function createOffline(PDO $db, int $tid, int $oid, array $user, array $payload): array
    {
        $uuid     = (string)($payload['uuid'] ?? '');
        $tempCode = substr((string)($payload['tempCode'] ?? ''), 0, 40);
        if ($uuid === '' || $tempCode === '') {
            return ['ok'=>false, 'error'=>'uuid/tempCode kosong'];
        }

        // Idempotency: sudah pernah ter-sync?
        $chk = $db->prepare("SELECT id, no_order FROM hl_transaksi WHERE tenant_id=? AND offline_uuid=? LIMIT 1");
        $chk->execute([$tid, $uuid]);
        if ($row = $chk->fetch(PDO::FETCH_ASSOC)) {
            return ['ok'=>true, 'no_order'=>$row['no_order'], 'id'=>(int)$row['id'], 'offline_ref'=>$tempCode, 'dedup'=>true];
        }

        // Validasi terhadap katalog terkini (outlet-scoped + active only)
        $stmtL = $db->prepare("SELECT id FROM hl_layanan WHERE tenant_id=? AND outlet_id=? AND is_active=1");
        $stmtL->execute([$tid, $oid]);
        $validL = array_map('intval', $stmtL->fetchAll(PDO::FETCH_COLUMN));
        $stmt = $db->prepare("SELECT nama_tier FROM hl_express_tier WHERE tenant_id=? AND (outlet_id IS NULL OR outlet_id=?)");
        $stmt->execute([$tid, $oid]);
        $validTierNames = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $errs = self::validateOfflinePayload($payload, $validL, $validTierNames);
        if ($errs) return ['ok'=>false, 'error'=>implode('; ', $errs)];

        $tanggal = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($payload['tanggal'] ?? ''))
            ? $payload['tanggal']
            : date('Y-m-d');
        $no = NotaFormatter::next($tid, $oid, $tanggal);

        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            $total   = (float)($payload['total'] ?? 0);
            $dp      = (float)($payload['dp'] ?? 0);
            $sisa    = max(0, $total - $dp);
            $nama    = substr(trim((string)($payload['nama_pelanggan'] ?? '')), 0, 100);
            $telp    = substr(trim((string)($payload['telepon'] ?? '')), 0, 20);
            $pelId   = !empty($payload['pelanggan_id']) ? (int)$payload['pelanggan_id'] : null;
            $catatan = substr(trim((string)($payload['catatan'] ?? '')), 0, 500);
            $status  = $dp >= $total && $total > 0 ? 'lunas' : ($dp > 0 ? 'dp' : 'belum_bayar');

            $ins = $db->prepare(
                "INSERT INTO hl_transaksi
                 (tenant_id, outlet_id, no_order, offline_ref, offline_uuid, tanggal,
                  pelanggan_id, nama_pelanggan, telepon, total, dp, sisa_bayar,
                  status_bayar, catatan, created_by, created_at,
                  status_proses, metode_bayar, subtotal)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?,?)"
            );
            $ins->execute([
                $tid, $oid, $no, $tempCode, $uuid, $tanggal,
                $pelId, $nama, $telp, $total, $dp, $sisa,
                $status, $catatan, (int)$user['id'],
                'masuk', 'cash', $total
            ]);
            $trxId = (int)$db->lastInsertId();

            $insIt = $db->prepare(
                "INSERT INTO hl_transaksi_item
                 (tenant_id, outlet_id, transaksi_id, layanan_id, nama_layanan,
                  satuan, jumlah, harga_satuan, subtotal, express_tier_nama, biaya_express)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)"
            );
            foreach ($payload['items'] as $it) {
                $tierNama   = substr(trim((string)($it['express_tier_nama'] ?? '')), 0, 50);
                $biayaExpr  = (float)($it['biaya_express'] ?? 0);
                $insIt->execute([
                    $tid,
                    $oid,
                    $trxId,
                    (int)$it['layanan_id'],
                    substr(trim((string)($it['nama_layanan'] ?? '')), 0, 100),
                    (string)($it['satuan'] ?? 'kg'),
                    (float)($it['jumlah'] ?? $it['qty'] ?? 0),
                    (float)($it['harga_satuan'] ?? $it['harga'] ?? 0),
                    (float)($it['subtotal'] ?? 0),
                    $tierNama !== '' ? $tierNama : null,
                    $biayaExpr,
                ]);
            }

            if ($dp > 0) {
                $insKas = $db->prepare(
                    "INSERT INTO hl_kas
                     (tenant_id, outlet_id, tanggal, tipe, kategori, keterangan, jumlah, ref_order, created_by, created_at)
                     VALUES (?,?,?,'masuk','penjualan',?,?,?,?,?)"
                );
                $insKas->execute([
                    $tid, $oid, $tanggal,
                    "DP/Bayar order $no (offline)",
                    $dp, $no, (int)$user['id'], date('Y-m-d H:i:s')
                ]);
            }

            if ($own) $db->commit();
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) $db->rollBack();
            return ['ok'=>false, 'error'=>'Gagal simpan: '.$e->getMessage()];
        }

        return ['ok'=>true, 'no_order'=>$no, 'id'=>$trxId, 'offline_ref'=>$tempCode];
    }
}

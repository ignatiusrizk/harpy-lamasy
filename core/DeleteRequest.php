<?php
// ══════════════════════════════════════════════════════
// core/DeleteRequest.php
//
// Approval-based deletion (Tier 2 — Smartlink-inspired).
// Kasir submit request → owner approve → entity di-soft-delete
// dengan snapshot tersimpan untuk audit.
//
// Usage:
//   DeleteRequest::submit('transaksi', $id, $alasan, $userId);
//   DeleteRequest::approve($reqId, $reviewerId);
//   DeleteRequest::reject($reqId, $reviewerId, $note);
// ══════════════════════════════════════════════════════

class DeleteRequest
{
    /** Submit request — return [id, ?error]. */
    public static function submit(
        string $entityType,
        int    $entityId,
        string $alasan,
        int    $userId
    ): array {
        $tid = TenantResolver::id();
        $oid = TenantResolver::outletId();
        $alasan = substr(trim($alasan), 0, 1000);
        if ($alasan === '') return [0, 'Alasan wajib diisi'];

        $db = Database::get();
        try {
            // Cek tidak ada pending request lain utk entity sama
            $chk = $db->prepare(
                "SELECT id FROM hl_delete_request
                  WHERE tenant_id=? AND entity_type=? AND entity_id=? AND status='pending' LIMIT 1"
            );
            $chk->execute([$tid, $entityType, $entityId]);
            if ($chk->fetchColumn()) return [0, 'Sudah ada permintaan hapus pending untuk item ini'];

            // Snapshot entity (untuk audit/undo)
            $snapshot = self::snapshot($entityType, $entityId, $tid, $oid);

            $st = $db->prepare(
                "INSERT INTO hl_delete_request
                  (tenant_id, outlet_id, entity_type, entity_id, entity_snapshot,
                   alasan, requested_by)
                 VALUES (?,?,?,?, ?,?,?)"
            );
            $st->execute([
                $tid, $oid, $entityType, $entityId,
                $snapshot ? json_encode($snapshot, JSON_UNESCAPED_UNICODE) : null,
                $alasan, $userId,
            ]);
            return [(int)$db->lastInsertId(), null];
        } catch (Throwable $e) {
            return [0, 'Gagal submit: ' . $e->getMessage()];
        }
    }

    /** Approve & execute deletion. */
    public static function approve(int $reqId, int $reviewerId, string $note = ''): ?string
    {
        $tid = TenantResolver::id();
        $db  = Database::get();
        try {
            $st = $db->prepare(
                "SELECT * FROM hl_delete_request
                  WHERE id=? AND tenant_id=? AND status='pending' LIMIT 1"
            );
            $st->execute([$reqId, $tid]);
            $req = $st->fetch(PDO::FETCH_ASSOC);
            if (!$req) return 'Request tidak ditemukan atau sudah di-review';

            $db->beginTransaction();
            // Execute deletion sesuai entity_type
            switch ($req['entity_type']) {
                case 'transaksi':
                    // Soft delete via flag is_deleted (kalau ada), else hard delete cascade
                    try {
                        $db->prepare("UPDATE hl_transaksi SET is_deleted=1, deleted_at=NOW(), deleted_by=? WHERE id=? AND tenant_id=?")
                           ->execute([$reviewerId, (int)$req['entity_id'], $tid]);
                    } catch (Throwable) {
                        // Kolom is_deleted belum ada → hard delete
                        $db->prepare("DELETE FROM hl_transaksi_item WHERE transaksi_id=? AND tenant_id=?")
                           ->execute([(int)$req['entity_id'], $tid]);
                        $db->prepare("DELETE FROM hl_transaksi WHERE id=? AND tenant_id=?")
                           ->execute([(int)$req['entity_id'], $tid]);
                    }
                    break;
                case 'kas':
                    $db->prepare("DELETE FROM hl_kas WHERE id=? AND tenant_id=?")
                       ->execute([(int)$req['entity_id'], $tid]);
                    break;
                case 'pelanggan':
                    $db->prepare("UPDATE hl_pelanggan SET is_active=0 WHERE id=? AND tenant_id=?")
                       ->execute([(int)$req['entity_id'], $tid]);
                    break;
            }

            // Update request status
            $db->prepare(
                "UPDATE hl_delete_request
                    SET status='approved', reviewed_by=?, reviewed_at=NOW(), review_note=?
                  WHERE id=?"
            )->execute([$reviewerId, substr($note, 0, 500) ?: null, $reqId]);
            $db->commit();
            return null;
        } catch (Throwable $e) {
            try { $db->rollBack(); } catch (Throwable) {}
            return 'Gagal approve: ' . $e->getMessage();
        }
    }

    /** Reject request — tidak ada deletion. */
    public static function reject(int $reqId, int $reviewerId, string $note = ''): ?string
    {
        $tid = TenantResolver::id();
        try {
            Database::get()->prepare(
                "UPDATE hl_delete_request
                    SET status='rejected', reviewed_by=?, reviewed_at=NOW(), review_note=?
                  WHERE id=? AND tenant_id=? AND status='pending'"
            )->execute([$reviewerId, substr($note, 0, 500) ?: null, $reqId, $tid]);
            return null;
        } catch (Throwable $e) {
            return 'Gagal reject: ' . $e->getMessage();
        }
    }

    /** Apakah entity punya permintaan hapus yg masih pending (utk kunci edit). */
    public static function isPending(string $type, int $id, int $tenantId): bool
    {
        try {
            $st = Database::get()->prepare(
                "SELECT 1 FROM hl_delete_request
                  WHERE tenant_id=? AND entity_type=? AND entity_id=? AND status='pending' LIMIT 1"
            );
            $st->execute([$tenantId, $type, $id]);
            return (bool)$st->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    /** Count pending request untuk tenant ini (utk badge owner inbox). */
    public static function pendingCount(int $tenantId): int
    {
        try {
            $st = Database::get()->prepare(
                "SELECT COUNT(*) FROM hl_delete_request
                  WHERE tenant_id=? AND status='pending'"
            );
            $st->execute([$tenantId]);
            return (int)$st->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    /** Snapshot data untuk audit. */
    private static function snapshot(string $type, int $id, int $tid, int $oid): ?array
    {
        try {
            $db = Database::get();
            switch ($type) {
                case 'transaksi':
                    $st = $db->prepare("SELECT * FROM hl_transaksi WHERE id=? AND tenant_id=? LIMIT 1");
                    $st->execute([$id, $tid]);
                    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
                case 'kas':
                    $st = $db->prepare("SELECT * FROM hl_kas WHERE id=? AND tenant_id=? LIMIT 1");
                    $st->execute([$id, $tid]);
                    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
                case 'pelanggan':
                    $st = $db->prepare("SELECT * FROM hl_pelanggan WHERE id=? AND tenant_id=? LIMIT 1");
                    $st->execute([$id, $tid]);
                    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        } catch (Throwable) {}
        return null;
    }
}

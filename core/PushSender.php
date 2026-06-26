<?php
// core/PushSender.php — Push notification via FCM HTTP v1 (Opsi A).
// Best-effort: kegagalan TIDAK pernah melempar ke pemanggil.

class PushSender
{
    /** Katalog event v1. kode => [label, outlet_bound]. */
    public const EVENTS = [
        'order_baru'    => ['label' => 'Order baru masuk',        'outlet_bound' => true],
        'order_siap'    => ['label' => 'Order siap diambil',      'outlet_bound' => true],
        'mesin_selesai' => ['label' => 'Mesin selesai',           'outlet_bound' => true],
        'antar_baru'    => ['label' => 'Tugas antar-jemput baru', 'outlet_bound' => true],
        'stok_kritis'   => ['label' => 'Stok bahan kritis',       'outlet_bound' => true],
    ];

    public static function eventExists(string $kode): bool
    {
        return isset(self::EVENTS[$kode]);
    }

    /**
     * User yang berhak menerima event: role-nya langganan + (di outlet itu ATAU owner/HQ)
     * + punya minimal 1 device token. Opsional dibatasi $targetUserIds.
     * @return int[]
     */
    public static function resolveRecipients(PDO $db, string $eventKode, int $tenantId, int $outletId, ?array $targetUserIds = null): array
    {
        $sql = "SELECT DISTINCT u.id
                FROM hl_users u
                JOIN hl_role_push_event rpe
                  ON rpe.role_id = u.role_id AND rpe.tenant_id = u.tenant_id AND rpe.event_kode = ?
                WHERE u.tenant_id = ?
                  AND u.is_active = 1
                  AND ( u.outlet_id = ?
                        OR u.role IN ('owner','manager','superadmin')
                        OR EXISTS (SELECT 1 FROM hl_karyawan_outlet ko
                                   WHERE ko.karyawan_id = u.id AND ko.outlet_id = ?
                                     AND ko.tenant_id = u.tenant_id AND ko.is_active = 1) )
                  AND EXISTS (SELECT 1 FROM hl_device_token dt
                              WHERE dt.user_id = u.id AND dt.tenant_id = u.tenant_id)";
        $params = [$eventKode, $tenantId, $outletId, $outletId];
        if ($targetUserIds !== null) {
            $targetUserIds = array_values(array_filter(array_map('intval', $targetUserIds)));
            if (!$targetUserIds) return [];
            $ph  = implode(',', array_fill(0, count($targetUserIds), '?'));
            $sql .= " AND u.id IN ($ph)";
            $params = array_merge($params, $targetUserIds);
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return string[] token milik user yang diberikan */
    public static function tokensForUsers(PDO $db, int $tenantId, array $userIds): array
    {
        $userIds = array_values(array_filter(array_map('intval', $userIds)));
        if (!$userIds) return [];
        $ph = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $db->prepare("SELECT token FROM hl_device_token WHERE tenant_id = ? AND user_id IN ($ph)");
        $stmt->execute(array_merge([$tenantId], $userIds));
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

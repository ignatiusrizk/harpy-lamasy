<?php
// ══════════════════════════════════════════════════════
// core/BillingConfig.php
// Wrapper untuk saas_billing_config — credentials + settings.
// ══════════════════════════════════════════════════════

class BillingConfig
{
    private static array $cache = [];

    /**
     * Get value untuk key tertentu. Returns null kalau tidak ada.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key] ?? $default;
        }
        try {
            $st = Database::get()->prepare("SELECT value_text FROM saas_billing_config WHERE key_name=?");
            $st->execute([$key]);
            $val = $st->fetchColumn();
            self::$cache[$key] = $val !== false ? $val : null;
            return self::$cache[$key] ?? $default;
        } catch (Throwable) {
            return $default;
        }
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return $v === null || $v === '' ? $default : (int)$v;
    }

    /**
     * Set value untuk key tertentu. Insert kalau belum ada.
     */
    public static function set(string $key, string $value, ?int $bySaId = null): void
    {
        $db = Database::get();
        $db->prepare(
            "INSERT INTO saas_billing_config (key_name, value_text, updated_by) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE value_text=VALUES(value_text), updated_by=VALUES(updated_by)"
        )->execute([$key, $value, $bySaId]);
        self::$cache[$key] = $value;
    }

    /**
     * Return semua config sebagai assoc array.
     */
    public static function all(): array
    {
        try {
            $rows = Database::get()->query(
                "SELECT key_name, value_text, description, updated_at FROM saas_billing_config ORDER BY key_name"
            )->fetchAll(PDO::FETCH_ASSOC);
            return $rows ?: [];
        } catch (Throwable) {
            return [];
        }
    }
}

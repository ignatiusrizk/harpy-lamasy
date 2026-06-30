<?php
// core/NotifPrefs.php — resolusi channel notifikasi otomatis dari tenants.notif_settings.
// Channel default ON kalau key/config absen (backward-compat).
class NotifPrefs
{
    private const CHANNELS = ['email', 'inapp'];

    /** Channel aktif untuk satu kategori. Default keduanya kalau key absen. Pure. */
    public static function channelsFor(array $cfg, string $kategori): array
    {
        $kat = $cfg[$kategori] ?? null;
        if (!is_array($kat)) return self::CHANNELS; // kategori belum dikonfigurasi → default ON
        $out = [];
        foreach (self::CHANNELS as $ch) {
            if ((int)($kat[$ch] ?? 1) === 1) $out[] = $ch; // channel absen → default ON
        }
        return $out;
    }

    /** Baca + decode notif_settings tenant. [] kalau NULL/invalid. */
    public static function read(int $tenantId): array
    {
        try {
            $db = Database::get();
            $s = $db->prepare("SELECT notif_settings FROM tenants WHERE id=?");
            $s->execute([$tenantId]);
            $raw = $s->fetchColumn();
            if ($raw) {
                $j = json_decode($raw, true);
                if (is_array($j)) return $j;
            }
        } catch (Throwable) {}
        return [];
    }
}

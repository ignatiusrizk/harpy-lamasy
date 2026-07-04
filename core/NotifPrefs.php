<?php
// core/NotifPrefs.php — resolusi channel notifikasi otomatis dari tenants.notif_settings.
// Channel default ON kalau key/config absen (backward-compat).
class NotifPrefs
{
    private const CHANNELS = ['email', 'inapp'];

    // Kategori notif OTOMATIS yang MEMOTONG COIN saat email terkirim
    // (alert_anomali 50, daily_report 100 — via Notifier coin_feature).
    // Audit coin 2026-07-04: email berbayar TIDAK boleh default ON —
    // nol potongan tanpa persetujuan owner. In-app tetap ON (gratis).
    private const PAID_AUTO = ['alert_anomali', 'daily_report'];

    /**
     * Channel aktif untuk satu kategori. Pure.
     * Default: kategori gratis → email+inapp ON; kategori BERBAYAR yang belum
     * pernah dikonfigurasi owner → inapp saja (email = opt-in di Notifikasi Owner).
     * Kalau owner SUDAH konfigurasi kategori itu → hormati pilihannya apa adanya.
     */
    public static function channelsFor(array $cfg, string $kategori): array
    {
        $kat = $cfg[$kategori] ?? null;
        if (!is_array($kat)) {
            // kategori belum dikonfigurasi
            return in_array($kategori, self::PAID_AUTO, true) ? ['inapp'] : self::CHANNELS;
        }
        $out = [];
        foreach (self::CHANNELS as $ch) {
            if ((int)($kat[$ch] ?? 1) === 1) $out[] = $ch; // konfigurasi eksplisit → key absen = ON
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

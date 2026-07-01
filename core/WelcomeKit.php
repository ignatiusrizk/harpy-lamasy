<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/BillingConfig.php';

class WelcomeKit
{
    public static function enabled(): bool
    {
        return BillingConfig::getInt('welcome_kit_enabled', 1) === 1;
    }

    /** @return array<int,array{key:string,nama:string,items:array,default:bool}> */
    public static function options(): array
    {
        $raw = BillingConfig::get('welcome_kit_options', '');
        $arr = $raw !== '' ? json_decode((string)$raw, true) : null;
        // Back-compat: fallback ke welcome_kit_items (single) → 1 opsi default
        if (!is_array($arr) || !$arr) {
            $legacy = json_decode((string)BillingConfig::get('welcome_kit_items', '[]'), true);
            $items  = self::cleanItems(is_array($legacy) ? $legacy : []);
            return $items ? [['key' => 'standar', 'nama' => 'Standar', 'items' => $items, 'default' => true]] : [];
        }
        $out = [];
        foreach ($arr as $o) {
            if (!is_array($o) || empty($o['nama'])) continue;
            $items = self::cleanItems($o['items'] ?? []);
            if (!$items) continue;
            $out[] = [
                'key'     => (string)($o['key'] ?? self::slugKey($o['nama'])),
                'nama'    => (string)$o['nama'],
                'items'   => $items,
                'default' => !empty($o['default']),
            ];
        }
        // Pastikan tepat satu default (kalau tak ada, opsi pertama)
        if ($out && !array_filter($out, fn($o) => $o['default'])) $out[0]['default'] = true;
        return $out;
    }

    private static function cleanItems($arr): array
    {
        if (!is_array($arr)) return [];
        $out = [];
        foreach ($arr as $it) {
            if (!is_array($it) || empty($it['nama'])) continue;
            $out[] = ['nama' => (string)$it['nama'], 'qty' => max(1, (int)($it['qty'] ?? 1))];
        }
        return $out;
    }

    private static function slugKey(string $nama): string
    {
        $s = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $nama));
        return trim($s, '_') ?: 'kit';
    }

    public static function defaultOption(): ?array
    {
        $opts = self::options();
        foreach ($opts as $o) if ($o['default']) return $o;
        return $opts[0] ?? null;
    }

    public static function optionByKey(string $key): ?array
    {
        foreach (self::options() as $o) if ($o['key'] === $key) return $o;
        return null;
    }

    public static function resolveChoiceKey(?string $key): ?string
    {
        if ($key !== null && $key !== '' && self::optionByKey($key)) return $key;
        $def = self::defaultOption();
        return $def['key'] ?? null;
    }

    // Back-compat shim: pemanggil lama yang butuh daftar item tunggal → item opsi default.
    /** @return array<int,array{nama:string,qty:int}> */
    public static function items(): array
    {
        return self::defaultOption()['items'] ?? [];
    }

    /**
     * Idempoten via payment_id. Snapshot alamat outlet + isi kit.
     * @return array{ok:bool,id:?int,skipped:bool}
     */
    public static function createForOutlet(PDO $db, int $tenantId, int $outletId, ?int $paymentId, string $trigger): array
    {
        if (!self::enabled()) return ['ok' => false, 'id' => null, 'skipped' => true];

        if ($paymentId !== null) {
            $ex = $db->prepare("SELECT id FROM saas_welcome_kit WHERE payment_id=?");
            $ex->execute([$paymentId]);
            if ($id = $ex->fetchColumn()) {
                return ['ok' => true, 'id' => (int)$id, 'skipped' => true];
            }
        }

        $o = $db->prepare("SELECT penerima, telepon, alamat, kota, kode_pos, welcome_kit_choice FROM outlets WHERE id=? AND tenant_id=?");
        $o->execute([$outletId, $tenantId]);
        $outlet = $o->fetch(PDO::FETCH_ASSOC);
        if (!$outlet) return ['ok' => false, 'id' => null, 'skipped' => true];

        $opt = self::optionByKey((string)($outlet['welcome_kit_choice'] ?? '')) ?? self::defaultOption();
        if (!$opt) return ['ok' => false, 'id' => null, 'skipped' => true]; // tak ada opsi kit
        $kitNama   = $opt['nama'];
        $itemsJson = json_encode($opt['items'], JSON_UNESCAPED_UNICODE);

        $incomplete = (empty($outlet['penerima']) || empty($outlet['alamat']) || empty($outlet['kota']) || empty($outlet['kode_pos']));
        $catatan = $incomplete ? 'alamat belum lengkap — lengkapi sebelum kirim' : null;

        $ins = $db->prepare(
            "INSERT INTO saas_welcome_kit
               (tenant_id, outlet_id, payment_id, `trigger`, penerima, hp, alamat, kota, kode_pos, items_json, kit_nama, status, catatan)
             VALUES (?,?,?,?,?,?,?,?,?,?,?, 'pending', ?)"
        );
        $ins->execute([
            $tenantId, $outletId, $paymentId, $trigger,
            $outlet['penerima'] ?: null, $outlet['telepon'] ?: null,
            $outlet['alamat'] ?: null, $outlet['kota'] ?: null, $outlet['kode_pos'] ?: null,
            $itemsJson, $kitNama, $catatan,
        ]);
        return ['ok' => true, 'id' => (int)$db->lastInsertId(), 'skipped' => false];
    }

    public static function listQueue(?string $status = null): array
    {
        $db = Database::get();
        if ($status && in_array($status, ['pending','shipped','delivered','cancelled'], true)) {
            $st = $db->prepare(
                "SELECT wk.*, o.nama_outlet, t.nama_perusahaan
                   FROM saas_welcome_kit wk
                   JOIN outlets o ON o.id = wk.outlet_id
                   JOIN tenants t ON t.id = wk.tenant_id
                  WHERE wk.status=? ORDER BY wk.created_at DESC"
            );
            $st->execute([$status]);
        } else {
            $st = $db->query(
                "SELECT wk.*, o.nama_outlet, t.nama_perusahaan
                   FROM saas_welcome_kit wk
                   JOIN outlets o ON o.id = wk.outlet_id
                   JOIN tenants t ON t.id = wk.tenant_id
                  ORDER BY FIELD(wk.status,'pending','shipped','delivered','cancelled'), wk.created_at DESC"
            );
        }
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function markShipped(int $id, string $kurir, string $resi): bool
    {
        $db = Database::get();
        return $db->prepare(
            "UPDATE saas_welcome_kit SET status='shipped', kurir=?, resi=?, shipped_at=NOW() WHERE id=? AND status IN ('pending','shipped')"
        )->execute([substr(trim($kurir),0,60), substr(trim($resi),0,80), $id]);
    }

    public static function markDelivered(int $id): bool
    {
        $db = Database::get();
        return $db->prepare(
            "UPDATE saas_welcome_kit SET status='delivered', delivered_at=NOW() WHERE id=? AND status IN ('shipped','pending')"
        )->execute([$id]);
    }

    public static function statusForOutlet(int $outletId): ?array
    {
        $db = Database::get();
        $st = $db->prepare("SELECT status, kurir, resi, items_json, kit_nama FROM saas_welcome_kit WHERE outlet_id=? ORDER BY id DESC LIMIT 1");
        $st->execute([$outletId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

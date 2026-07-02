# Edit Layanan Order LUNAS — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Gerbang uang eksplisit saat order LUNAS di-edit hingga totalnya berubah: naik → status DP + WA tagih selisih; turun → kasir pilih refund tunai (kas keluar) atau masuk deposit; `sisa_bayar` tak pernah negatif.

**Architecture:** Logika keputusan diekstrak ke helper murni `core/OrderEditResolver.php` (unit-testable CLI tanpa sesi/DB). Endpoint `orders.php action=update` memanggil resolver sebelum menulis; eksekusi uang (kas/deposit) di transaksi yang sama. `DepositManager::topup` diberi dua penyesuaian backward-compatible: `$applyBonus` (refund tak boleh dapat bonus) dan transaction-aware (dipanggil dari dalam transaksi endpoint).

**Tech Stack:** PHP 8, MariaDB (PDO), test harness `tests/_assert.php` (`php tests/...`), JS vanilla + `lmConfirm` (ui_dialog).

## Global Constraints

- Gerbang aktif hanya bila: `status_bayar` lama `'lunas'` DAN (items berubah ATAU diskon berubah) DAN `total_baru != total_lama`.
- Response gerbang tanpa resolusi: `{"need_confirm":"kurang_bayar"|"kelebihan","selisih":<int>,"total_baru":<float>,"bisa_deposit":<bool>}` — belum menulis apa pun (rollback).
- Resolusi valid: `tagih` (hanya naik), `refund_tunai`/`ke_deposit` (hanya turun); mismatch → `{"error":"Resolusi tidak sesuai"}`.
- `sisa_bayar = max(0, ...)` di semua jalur (clamp global).
- Refund tunai: INSERT `hl_kas` tipe `keluar`, kategori `Refund Order`, keterangan `"Refund koreksi order {no_order} — {nama}"`, `ref_order`, `created_by`.
- Ke deposit: `DepositManager::topup(...)` dengan **bonus TIDAK diberikan** (`$applyBonus=false`), metode `'refund_order'`, catatan `"Refund koreksi order {no_order}"`; hanya bila `pelanggan_id` ada. Kas TIDAK dicatat keluar.
- Perubahan item/diskon wajib `hasPermission('orders.edit')`; update status murni tetap boleh `orders.update_status`.
- Baris order dikunci `SELECT ... FOR UPDATE` dalam transaksi (anti dobel-submit).
- Template WA (urlencode, wa.me): `"Halo {nama}, saat penimbangan cucian order {no_order} terdapat layanan tambahan sehingga total menjadi Rp {total}. Kekurangan Rp {selisih} dapat dibayar saat pengambilan. Terima kasih 🙏 — {outlet}"`.
- Poin loyalty TIDAK di-adjust (out of scope). Tidak ada perubahan skema DB.
- Deviasi spec yang disengaja (ditemukan saat planning): `core/DepositManager.php` DIUBAH minimal — `topup(..., bool $applyBonus = true)` + transaction-aware (`inTransaction()` guard) — karena topup existing selalu auto-bonus dan selalu `beginTransaction()` (nesting fatal). Default param mempertahankan perilaku lama untuk semua caller existing.

---

### Task 1: `core/OrderEditResolver.php` + unit test murni

**Files:**
- Create: `core/OrderEditResolver.php`
- Test: `tests/orders/test_edit_lunas_resolver.php`

**Interfaces:**
- Consumes: — (murni, tanpa DB/sesi)
- Produces: `OrderEditResolver::resolve(array $ctx): array`
  - `$ctx`: `['sbayar_lama'=>string, 'total_lama'=>float, 'dp_lama'=>float, 'total_baru'=>float, 'berubah'=>bool, 'resolusi'=>?string, 'punya_pelanggan'=>bool]`
  - Return salah satu:
    - `['gate'=>false]`
    - `['gate'=>true, 'need_confirm'=>'kurang_bayar'|'kelebihan', 'selisih'=>float, 'total_baru'=>float, 'bisa_deposit'=>bool]`
    - `['gate'=>true, 'apply'=>['dp'=>float, 'sisa'=>float, 'status'=>'dp'|'lunas', 'aksi'=>'tagih'|'refund_tunai'|'ke_deposit', 'selisih'=>float]]`
    - `['gate'=>true, 'error'=>string]`

- [ ] **Step 1: Tulis test yang gagal** — create `tests/orders/test_edit_lunas_resolver.php`:

```php
<?php
// Test OrderEditResolver::resolve — murni, tanpa DB/sesi.
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../core/OrderEditResolver.php';

$base = ['sbayar_lama'=>'lunas','total_lama'=>50000.0,'dp_lama'=>50000.0,
         'total_baru'=>75000.0,'berubah'=>true,'resolusi'=>null,'punya_pelanggan'=>true];

// ── Tanpa gerbang ──
$r = OrderEditResolver::resolve(['sbayar_lama'=>'dp'] + $base);
eqv($r['gate'], false, "bukan lunas → tanpa gerbang");
$r = OrderEditResolver::resolve(['total_baru'=>50000.0] + $base);
eqv($r['gate'], false, "total sama → tanpa gerbang");
$r = OrderEditResolver::resolve(['berubah'=>false] + $base);
eqv($r['gate'], false, "item/diskon tak berubah → tanpa gerbang");

// ── Naik: butuh konfirmasi ──
$r = OrderEditResolver::resolve($base);
eqv($r['need_confirm'] ?? '', 'kurang_bayar', "naik tanpa resolusi → need_confirm kurang_bayar");
eqv($r['selisih'], 25000.0, "selisih naik = total_baru - dp_lama");
eqv($r['bisa_deposit'], true, "bisa_deposit ikut punya_pelanggan");

// ── Naik + tagih ──
$r = OrderEditResolver::resolve(['resolusi'=>'tagih'] + $base);
eqv($r['apply']['dp'], 50000.0, "tagih: dp dipertahankan");
eqv($r['apply']['sisa'], 25000.0, "tagih: sisa = selisih");
eqv($r['apply']['status'], 'dp', "tagih: status turun ke dp");
eqv($r['apply']['aksi'], 'tagih', "tagih: aksi tercatat");

// ── Naik + resolusi salah arah ──
$r = OrderEditResolver::resolve(['resolusi'=>'refund_tunai'] + $base);
ok(isset($r['error']), "refund saat naik → error resolusi tidak sesuai");

// ── Turun: butuh konfirmasi ──
$turun = ['total_baru'=>40000.0] + $base;
$r = OrderEditResolver::resolve($turun);
eqv($r['need_confirm'] ?? '', 'kelebihan', "turun tanpa resolusi → need_confirm kelebihan");
eqv($r['selisih'], 10000.0, "selisih turun = dp_lama - total_baru");
$r = OrderEditResolver::resolve(['punya_pelanggan'=>false] + $turun);
eqv($r['bisa_deposit'], false, "tanpa pelanggan → bisa_deposit false");

// ── Turun + refund tunai ──
$r = OrderEditResolver::resolve(['resolusi'=>'refund_tunai'] + $turun);
eqv($r['apply']['dp'], 40000.0, "refund: dp = total baru");
eqv($r['apply']['sisa'], 0.0, "refund: sisa 0 (tak pernah negatif)");
eqv($r['apply']['status'], 'lunas', "refund: tetap lunas");
eqv($r['apply']['aksi'], 'refund_tunai', "refund: aksi tercatat");
eqv($r['apply']['selisih'], 10000.0, "refund: selisih utk kas keluar");

// ── Turun + ke_deposit ──
$r = OrderEditResolver::resolve(['resolusi'=>'ke_deposit'] + $turun);
eqv($r['apply']['aksi'], 'ke_deposit', "ke_deposit: aksi tercatat");
$r = OrderEditResolver::resolve(['resolusi'=>'ke_deposit','punya_pelanggan'=>false] + $turun);
ok(isset($r['error']), "ke_deposit tanpa pelanggan → error");

// ── Turun + tagih (salah arah) ──
$r = OrderEditResolver::resolve(['resolusi'=>'tagih'] + $turun);
ok(isset($r['error']), "tagih saat turun → error");

echo "ALL PASS\n";
```

- [ ] **Step 2: Run — pastikan gagal**: `php tests/orders/test_edit_lunas_resolver.php` → FAIL (file `core/OrderEditResolver.php` belum ada).

- [ ] **Step 3: Implementasi** `core/OrderEditResolver.php`:

```php
<?php
// core/OrderEditResolver.php — keputusan murni utk edit order LUNAS yang mengubah total.
// Tanpa DB/sesi: input konteks angka → output keputusan (need_confirm / apply / error).
// Eksekusi uangnya (kas/deposit) dilakukan pemanggil (orders.php) di transaksinya sendiri.

class OrderEditResolver
{
    public static function resolve(array $ctx): array
    {
        $sbayarLama = (string)($ctx['sbayar_lama'] ?? '');
        $totalLama  = (float)($ctx['total_lama'] ?? 0);
        $dpLama     = (float)($ctx['dp_lama'] ?? 0);
        $totalBaru  = (float)($ctx['total_baru'] ?? 0);
        $berubah    = (bool)($ctx['berubah'] ?? false);
        $resolusi   = $ctx['resolusi'] ?? null;
        $punyaPel   = (bool)($ctx['punya_pelanggan'] ?? false);

        // Gerbang hanya utk order lunas yang komposisinya berubah & totalnya bergeser
        if ($sbayarLama !== 'lunas' || !$berubah || $totalBaru == $totalLama) {
            return ['gate' => false];
        }

        $naik    = $totalBaru > $dpLama;
        $selisih = $naik ? $totalBaru - $dpLama : $dpLama - $totalBaru;

        if ($resolusi === null || $resolusi === '') {
            return [
                'gate'         => true,
                'need_confirm' => $naik ? 'kurang_bayar' : 'kelebihan',
                'selisih'      => $selisih,
                'total_baru'   => $totalBaru,
                'bisa_deposit' => $punyaPel,
            ];
        }

        if ($naik) {
            if ($resolusi !== 'tagih') return ['gate' => true, 'error' => 'Resolusi tidak sesuai'];
            return ['gate' => true, 'apply' => [
                'dp' => $dpLama, 'sisa' => max(0.0, $totalBaru - $dpLama),
                'status' => 'dp', 'aksi' => 'tagih', 'selisih' => $selisih,
            ]];
        }

        // Turun (kelebihan bayar)
        if ($resolusi === 'ke_deposit' && !$punyaPel) {
            return ['gate' => true, 'error' => 'Order tanpa pelanggan terdaftar — pilih refund tunai'];
        }
        if (!in_array($resolusi, ['refund_tunai', 'ke_deposit'], true)) {
            return ['gate' => true, 'error' => 'Resolusi tidak sesuai'];
        }
        return ['gate' => true, 'apply' => [
            'dp' => $totalBaru, 'sisa' => 0.0,
            'status' => 'lunas', 'aksi' => $resolusi, 'selisih' => $selisih,
        ]];
    }
}
```

- [ ] **Step 4: Run — pastikan lolos**: `php tests/orders/test_edit_lunas_resolver.php` → `ALL PASS` (20 assert).

- [ ] **Step 5: Lint + commit**

```bash
php -l core/OrderEditResolver.php
git add core/OrderEditResolver.php tests/orders/test_edit_lunas_resolver.php
git commit -m "feat(orders): OrderEditResolver — keputusan murni edit order lunas (tagih/refund/deposit) + unit test"
```

---

### Task 2: `DepositManager::topup` — `$applyBonus` + transaction-aware

**Files:**
- Modify: `core/DepositManager.php` (method `topup`, mulai baris 62)
- Test: `tests/orders/test_topup_refund_mode.php`

**Interfaces:**
- Consumes: signature existing `topup(int $tenantId, int $outletId, int $pelangganId, float $jumlah, string $metode='cash', string $catatan='', ?int $createdBy=null, ?string $buktiBayar=null): array` — return `[float kredit, ?string error]`.
- Produces: signature baru `topup(..., ?string $buktiBayar = null, bool $applyBonus = true): array`. Perilaku lama utuh bila param tak diisi. Bila `$applyBonus=false` → bonus 0 (tanpa `calcBonus`). Bila dipanggil saat `$db->inTransaction()` → TIDAK begin/commit/rollback sendiri (numpang transaksi luar; error dilempar sebagai exception ke pemanggil agar transaksi luar bisa rollback utuh).

- [ ] **Step 1: Tulis test yang gagal** — create `tests/orders/test_topup_refund_mode.php`:

```php
<?php
// Test topup applyBonus=false + transaction-aware. Seed pelanggan temp, cleanup shutdown.
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../master/config/db.php';
require __DIR__ . '/../../core/Database.php';
require __DIR__ . '/../../core/DepositManager.php';

$db  = Database::get();
$src = $db->query("SELECT * FROM hl_pelanggan LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$src) { echo "SKIP: tidak ada pelanggan\n"; exit(0); }
$tid = (int)$src['tenant_id']; $oid = (int)$src['outlet_id'];
unset($src['id']);
$src['nama'] = 'zzrefund_' . time();
$src['saldo_deposit'] = 0;
$cols = array_keys($src);
$db->prepare("INSERT INTO hl_pelanggan (".implode(',', $cols).") VALUES (".implode(',', array_fill(0,count($cols),'?')).")")
   ->execute(array_values($src));
$pid = (int)$db->lastInsertId();

$cleanup = function() use ($db, $pid) {
    $db->prepare("DELETE FROM hl_deposit_topup WHERE pelanggan_id=?")->execute([$pid]);
    $db->prepare("DELETE FROM hl_pelanggan WHERE id=?")->execute([$pid]);
};
register_shutdown_function($cleanup);

// applyBonus=false → kredit = jumlah persis, bonus row = 0
[$kredit, $err] = DepositManager::topup($tid, $oid, $pid, 10000.0, 'refund_order', 'zzrefund test', null, null, false);
eqv($err, null, "topup refund tanpa error");
eqv($kredit, 10000.0, "kredit = jumlah persis (bonus tidak diberikan)");
$row = $db->prepare("SELECT bonus, total_kredit FROM hl_deposit_topup WHERE pelanggan_id=? ORDER BY id DESC LIMIT 1");
$row->execute([$pid]); $row = $row->fetch(PDO::FETCH_ASSOC);
eqv((float)$row['bonus'], 0.0, "bonus tercatat 0");

// Transaction-aware: panggil dari dalam transaksi luar → tidak fatal, saldo ikut commit luar
$db->beginTransaction();
[$kredit2, $err2] = DepositManager::topup($tid, $oid, $pid, 5000.0, 'refund_order', 'zzrefund tx', null, null, false);
eqv($err2, null, "topup dalam transaksi luar tidak error");
$db->commit();
$saldo = $db->prepare("SELECT saldo_deposit FROM hl_pelanggan WHERE id=?");
$saldo->execute([$pid]);
eqv((float)$saldo->fetchColumn(), 15000.0, "saldo akhir 15000 (10000+5000)");

echo "ALL PASS\n";
```

- [ ] **Step 2: Run — pastikan gagal**: `php tests/orders/test_topup_refund_mode.php` → FAIL ("already an active transaction" atau kredit 10000+bonus).

- [ ] **Step 3: Modifikasi `topup`** di `core/DepositManager.php` — ubah signature & bagian transaksi/bonus (pertahankan body lain apa adanya):

```php
    public static function topup(
        int    $tenantId,
        int    $outletId,
        int    $pelangganId,
        float  $jumlah,
        string $metode      = 'cash',
        string $catatan     = '',
        ?int   $createdBy   = null,
        ?string $buktiBayar = null,
        bool   $applyBonus  = true   // false utk refund/koreksi — tanpa bonus tier
    ): array {
        if ($jumlah <= 0) return [0, 'Jumlah topup harus > 0'];
        $db = Database::get();
        // Transaction-aware: bila pemanggil sudah dalam transaksi (mis. orders.php
        // action=update), numpang — jangan begin/commit sendiri; error dilempar
        // sebagai exception supaya transaksi luar rollback utuh.
        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) $db->beginTransaction();

            // Hitung bonus (skip utk refund)
            if ($applyBonus) {
                $bonusInfo = self::calcBonus($tenantId, $outletId, $jumlah);
                $bonus     = (float)$bonusInfo['bonus'];
            } else {
                $bonus = 0.0;
            }
            $kredit = $jumlah + $bonus;
```

dan di bagian akhir method (commit/rollback existing):

```php
            if ($ownTx) $db->commit();
            return [$kredit, null];
        } catch (Throwable $e) {
            if ($ownTx) {
                try { $db->rollBack(); } catch (Throwable $rbErr) { /* keep original error */ }
                return [0, 'Gagal topup: ' . $e->getMessage()];
            }
            throw $e; // dalam transaksi luar → biarkan pemanggil rollback utuh
        }
```

(Sesuaikan dengan struktur try/catch existing di file — jangan duplikat blok; `$db->commit()` dan `rollBack()` yang sudah ada diganti bentuk ber-guard `$ownTx` di atas. Baris `expired_at`/insert existing tidak diubah.)

- [ ] **Step 4: Run test — lolos**: `php tests/orders/test_topup_refund_mode.php` → `ALL PASS`. Lalu regresi deposit existing: `php -l core/DepositManager.php` + `ls tests/ | grep -i deposit` (kalau ada test deposit lama, jalankan juga).

- [ ] **Step 5: Commit**

```bash
git add core/DepositManager.php tests/orders/test_topup_refund_mode.php
git commit -m "feat(deposit): topup applyBonus=false (refund tanpa bonus) + transaction-aware utk dipanggil dari transaksi luar"
```

---

### Task 3: Integrasi `orders.php` — gerbang server + dialog klien + WA + banner

**Files:**
- Modify: `orders.php` (server: blok `action=update` mulai baris 172; klien: `saveEdit()` baris 1992 + area detail)

**Interfaces:**
- Consumes: `OrderEditResolver::resolve($ctx)` (Task 1), `DepositManager::topup(..., applyBonus: false)` (Task 2), `hasPermission()`, `verifyCsrf()`, `lmConfirm` (ui_dialog, global), `showToast`.
- Produces: response `action=update` bisa berisi `need_confirm/selisih/total_baru/bisa_deposit`, atau `success + wa_url` (jalur tagih).

- [ ] **Step 1: Server — require + perluas oldRow + FOR UPDATE + guard permission.** Di `orders.php` dekat require atas (ikuti pola require existing) tambah:

```php
require_once __DIR__ . '/core/OrderEditResolver.php';
require_once __DIR__ . '/core/DepositManager.php';
```

Ganti query oldRow (baris ~184) menjadi (tambah kolom + FOR UPDATE):

```php
            $oldRow = $db->prepare("SELECT status_proses,status_bayar,catatan,dp,total,diskon,
                                           pelanggan_id,telepon,no_order,nama_pelanggan
                                      FROM hl_transaksi
                                     WHERE tenant_id=? AND outlet_id=? AND id=? FOR UPDATE");
```

Setelah `$itemsChanged` dihitung, tambah deteksi diskon & guard permission:

```php
            $diskonBerubah = abs((float)($data['diskon'] ?? 0) - (float)$oldRow['diskon']) > 0.001;
            if (($itemsChanged || $diskonBerubah) && !hasPermission('orders.edit')) {
                $db->rollBack();
                echo json_encode(['error' => 'Butuh izin edit order untuk mengubah layanan/diskon']); exit;
            }
```

- [ ] **Step 2: Server — gerbang resolver SEBELUM update header.** Setelah `$total`, `$dp`, `$sisa`, `$sbayar` dihitung (baris ~211) sisipkan:

```php
            // ── Gerbang order LUNAS yang totalnya berubah ─────────────
            $gate = OrderEditResolver::resolve([
                'sbayar_lama'     => $oldRow['status_bayar'],
                'total_lama'      => (float)$oldRow['total'],
                'dp_lama'         => (float)$oldRow['dp'],
                'total_baru'      => (float)$total,
                'berubah'         => $itemsChanged || $diskonBerubah,
                'resolusi'        => $data['confirm_resolution'] ?? null,
                'punya_pelanggan' => !empty($oldRow['pelanggan_id']),
            ]);
            $gateAksi = null; $gateSelisih = 0.0;
            if ($gate['gate']) {
                if (isset($gate['need_confirm'])) {
                    $db->rollBack();
                    echo json_encode([
                        'need_confirm' => $gate['need_confirm'],
                        'selisih'      => (int)round($gate['selisih']),
                        'total_baru'   => (float)$gate['total_baru'],
                        'bisa_deposit' => $gate['bisa_deposit'],
                    ]); exit;
                }
                if (isset($gate['error'])) {
                    $db->rollBack();
                    echo json_encode(['error' => $gate['error']]); exit;
                }
                // Override hasil hitung default dgn keputusan resolver
                $dp     = $gate['apply']['dp'];
                $sisa   = $gate['apply']['sisa'];
                $sbayar = $gate['apply']['status'];
                $gateAksi    = $gate['apply']['aksi'];
                $gateSelisih = (float)$gate['apply']['selisih'];
            }
            $sisa = max(0.0, $sisa); // clamp global — sisa_bayar tak pernah negatif
```

- [ ] **Step 3: Server — eksekusi uang + log + wa_url.** Setelah blok update items (sesudah `UPDATE hl_transaksi SET subtotal=...`, sebelum `$logs_to_insert`), sisipkan:

```php
            // ── Eksekusi uang utk resolusi gerbang lunas ──────────────
            $waUrl = null;
            if ($gateAksi === 'refund_tunai') {
                $db->prepare("INSERT INTO hl_kas
                    (tenant_id, outlet_id, tanggal, tipe, kategori, keterangan, jumlah, ref_order, created_by, created_at)
                    VALUES (?,?,CURDATE(),'keluar','Refund Order',?,?,?,?,NOW())")
                   ->execute([$tid, $oid,
                       'Refund koreksi order ' . $oldRow['no_order'] . ' — ' . $oldRow['nama_pelanggan'],
                       $gateSelisih, $oldRow['no_order'], (int)$user['id']]);
            } elseif ($gateAksi === 'ke_deposit') {
                // applyBonus=false + numpang transaksi ini; exception → rollback utuh di catch existing
                [, $depErr] = DepositManager::topup(
                    $tid, $oid, (int)$oldRow['pelanggan_id'], $gateSelisih,
                    'refund_order', 'Refund koreksi order ' . $oldRow['no_order'],
                    (int)$user['id'], null, false
                );
                if ($depErr) { throw new RuntimeException('Gagal ke deposit: ' . $depErr); }
            } elseif ($gateAksi === 'tagih' && !empty($oldRow['telepon'])) {
                $p = preg_replace('/[^0-9]/', '', $oldRow['telepon']);
                if (strpos($p, '0') === 0) $p = '62' . substr($p, 1);
                elseif (strpos($p, '62') !== 0) $p = '62' . $p;
                $outletNama = TenantResolver::getOutlet()['nama_outlet'] ?? 'kami';
                $waMsg = "Halo {$oldRow['nama_pelanggan']}, saat penimbangan cucian order {$oldRow['no_order']} "
                       . "terdapat layanan tambahan sehingga total menjadi Rp " . number_format($total, 0, ',', '.') . ". "
                       . "Kekurangan Rp " . number_format($gateSelisih, 0, ',', '.') . " dapat dibayar saat pengambilan. "
                       . "Terima kasih 🙏 — {$outletNama}";
                $waUrl = 'https://wa.me/' . $p . '?text=' . rawurlencode($waMsg);
            }
            if ($gateAksi !== null) {
                $aksiLabel = ['tagih' => 'Lunas → DP: penambahan layanan, kurang Rp ',
                              'refund_tunai' => 'Koreksi turun: refund tunai Rp ',
                              'ke_deposit' => 'Koreksi turun: masuk deposit Rp '][$gateAksi];
                $logs_gate = $aksiLabel . number_format($gateSelisih, 0, ',', '.');
            }
```

Lalu di array `$logs_to_insert` yang sudah ada (blok `if ($oldRow['status_bayar'] !== $sbayar)`) — biarkan; tambah SETELAHNYA:

```php
            if ($gateAksi !== null) {
                $logs_to_insert[] = [
                    'transaksi_id' => $id,
                    'status_lama'  => $oldRow['status_bayar'],
                    'status_baru'  => $sbayar,
                    'tipe'         => 'bayar',
                    'catatan'      => '💰 ' . $logs_gate,
                    'oleh'         => $user['nama'],
                ];
            }
```

Dan pada `echo json_encode(['success' => true, ...])` akhir action=update: tambahkan `'wa_url' => $waUrl`.

- [ ] **Step 4: Klien — dialog konfirmasi/pilihan di `saveEdit()`** (orders.php:1992). Ubah bagian setelah `const d = await r.json();` menjadi:

```javascript
  if (d.need_confirm === 'kurang_bayar') {
    btn.disabled = false; btn.textContent = '💾 Simpan Perubahan';
    const okTagih = await lmConfirm(
      'Order ini LUNAS. Perubahan membuat kurang bayar Rp ' + Number(d.selisih).toLocaleString('id-ID') +
      ' (total baru Rp ' + Number(d.total_baru).toLocaleString('id-ID') + '). Lanjutkan? Status akan turun ke DP.',
      {icon:'⚠️'});
    if (okTagih) return saveEditWithResolution('tagih');
    return;
  }
  if (d.need_confirm === 'kelebihan') {
    btn.disabled = false; btn.textContent = '💾 Simpan Perubahan';
    const pilih = await lmKelebihanDialog(d.selisih, d.bisa_deposit);
    if (pilih) return saveEditWithResolution(pilih);
    return;
  }
```

dan setelah `if (d.success) { showToast(...)` tambahkan (sebelum `closeModal()`):

```javascript
    if (d.wa_url) {
      if (await lmConfirm('Kirim WA pemberitahuan kekurangan bayar ke pelanggan?', {icon:'📲'})) {
        window.open(d.wa_url, '_blank');
      }
    }
```

Tambah dua fungsi helper (dekat `saveEdit`):

```javascript
async function saveEditWithResolution(res) {
  window.__editResolution = res;
  await saveEdit();
  window.__editResolution = null;
}
// Dialog pilihan kelebihan bayar: refund tunai / masuk deposit / batal
function lmKelebihanDialog(selisih, bisaDeposit) {
  return new Promise(resolve => {
    const rp = 'Rp ' + Number(selisih).toLocaleString('id-ID');
    const wrap = document.createElement('div');
    wrap.style.cssText = 'position:fixed;inset:0;background:rgba(15,28,58,.6);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px';
    wrap.innerHTML = `
      <div style="background:#fff;border-radius:16px;padding:24px;max-width:360px;width:100%;text-align:center">
        <div style="font-size:40px;margin-bottom:8px">💸</div>
        <div style="font-weight:800;color:#0F1C3A;margin-bottom:6px">Kelebihan bayar ${rp}</div>
        <div style="font-size:13px;color:#6B7280;margin-bottom:18px">Order ini sudah lunas. Kelebihan pembayaran mau diapakan?</div>
        <button data-v="refund_tunai" style="display:block;width:100%;padding:12px;margin-bottom:8px;background:#0F1C3A;color:#fff;border:0;border-radius:10px;font-weight:700;cursor:pointer">💵 Refund Tunai ${rp}</button>
        ${bisaDeposit ? `<button data-v="ke_deposit" style="display:block;width:100%;padding:12px;margin-bottom:8px;background:#EAFBF8;color:#0F766E;border:1px solid #99F6E4;border-radius:10px;font-weight:700;cursor:pointer">💳 Masukkan ke Deposit</button>` : ''}
        <button data-v="" style="background:none;border:0;color:#9CA3AF;font-size:13px;cursor:pointer;margin-top:4px">Batal</button>
      </div>`;
    wrap.addEventListener('click', e => {
      const v = e.target?.dataset?.v;
      if (v === undefined && e.target !== wrap) return;
      wrap.remove(); resolve(v || null);
    });
    document.body.appendChild(wrap);
  });
}
```

Dan di payload `saveEdit()` tambahkan properti:

```javascript
    confirm_resolution: window.__editResolution || null,
```

Catatan: `saveEdit` punya guard `editSnapshot` "tidak ada perubahan" di awal — saat dipanggil ulang via `saveEditWithResolution`, state form tidak berubah tapi HARUS tetap terkirim. Solusi: di awal `saveEdit`, skip guard snapshot bila `window.__editResolution` terisi:

```javascript
  if (!window.__editResolution && editSnapshot !== null && editStateJSON() === editSnapshot) {
```

- [ ] **Step 5: Banner detail order.** Di render detail (fungsi yang menampilkan status bayar di modal detail), bila `status_bayar==='dp' && Number(sisa_bayar)>0` sudah ada tampilan sisa — tambah badge kecil konteks: cari lokasi render `status_bayar` di detail (`grep -n "sisa_bayar" orders.php` bagian JS render detail) dan tambahkan setelahnya:

```javascript
    // Banner konteks: order pernah lunas lalu jadi kurang bayar (deteksi dari log terakhir tipe bayar)
    // Sederhana & tanpa query tambahan: tampilkan hanya bila ada log "Lunas → DP" di riwayat yang sudah di-load.
```

Implementasi minimal: di fungsi render riwayat/log detail existing, bila catatan log mengandung `'Lunas → DP'` → di area status bayar detail tampilkan `<div class="hl-badge hl-badge-dp" style="margin-top:4px">⚠️ Kurang bayar (sebelumnya lunas)</div>`. (Ikuti struktur render detail yang ada; jangan bikin fetch baru.)

- [ ] **Step 6: Lint + test regresi**

```bash
php -l orders.php
php tests/orders/test_edit_lunas_resolver.php   # tetap ALL PASS
php tests/orders/test_topup_refund_mode.php     # tetap ALL PASS
```

- [ ] **Step 7: Commit**

```bash
git add orders.php
git commit -m "feat(orders): gerbang edit order LUNAS — konfirmasi kurang bayar + WA tagih, kelebihan → refund tunai/deposit, guard orders.edit, sisa tak pernah negatif"
```

---

## Verifikasi Manual E2E (controller/user, via /demo)

1. Login `/demo` → Orders → order LUNAS (mis. DMO-0002) → Edit → tambah item → Simpan → dialog "kurang bayar" → Ya → status DP, tawaran WA muncul, log tercatat.
2. Edit order lunas lain → kurangi/perkecil item → Simpan → dialog pilihan → Refund Tunai → cek `/kas` ada baris keluar "Refund Order".
3. Ulangi pilih "Masukkan ke Deposit" (order dgn pelanggan terdaftar) → cek saldo deposit pelanggan naik, kas TIDAK berkurang.
4. Login role kasir/karyawan tanpa `orders.edit` → coba ubah item → ditolak.

## Catatan integrasi (parallel session)

Eksekusi di git worktree terisolasi dari `origin/main`; `git fetch && git rebase origin/main` sebelum push; commit hanya file plan ini; JANGAN commit apa pun di `.superpowers/`; jangan gabung push dgn cleanup. Auto-deploy saat push `main`.

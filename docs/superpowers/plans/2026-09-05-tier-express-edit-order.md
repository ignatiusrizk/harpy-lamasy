# Tier Express di Edit Order Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tambah dropdown Tier Express per item saat edit order via `orders.php`, dan bikin `biaya_tambahan` (Tier Express) + `biaya_lainnya` (Biaya Lainnya Tier) direcompute dari kondisi terbaru setiap kali item order diedit — bukan snapshot beku lagi.

**Architecture:** Backend `action=update` di `orders.php` memanggil static method yang SUDAH ADA di `core/ExpressTier.php` (`findByNama`, `calcItemFee`, `dominantTier` — belum ada pemanggilnya di codebase manapun) dan `core/BiayaLainnyaTier.php` (`calcAppliedFees` — sudah dipakai `pos.php`) untuk recompute per-item fee + total, lalu refresh `hl_transaksi_item` dan `hl_transaksi_biaya_lainnya`. Frontend dapat kolom "Express" baru di tabel edit item, dropdown-nya diisi dari endpoint baru `action=express_tiers`.

**Tech Stack:** PHP 8 (vanilla, no framework), MySQL, vanilla JS (no build step) — mengikuti pola exact yang sudah dipakai `pos.php` untuk Tier Express.

## Global Constraints

- Server-side HARUS recompute fee dari tier yang di-submit — tidak boleh percaya nilai `biaya_express`/nominal dari klien begitu saja (pola yang sudah dipakai `pos.php`, cegah tamper).
- Recompute (`biaya_tambahan`, `biaya_lainnya`, item breakdown, `hl_transaksi_biaya_lainnya` breakdown) HANYA jalan di dalam blok existing `if (!empty($data['items']) && $itemsChanged)` — kalau item tidak berubah sama sekali, tidak ada yang direcompute (perilaku persis sebelumnya untuk kasus itu).
- Tidak ada perubahan pada `pos.php` — semua perubahan di `orders.php` (+ pemakaian pertama method existing di `core/ExpressTier.php`).
- Total order yang berubah akibat recompute tier tetap lewat gerbang `OrderEditResolver` yang sudah ada (tidak ada mekanisme gate baru).
- Test tenant/outlet yang dipakai buat verifikasi: `tenant_id=18`, `outlet_id=13` (Harpy Laundry Johar Baru — outlet nyata yang sudah dipakai berulang kali sepanjang sesi ini buat testing, aman karena order test selalu dibersihkan setelah verifikasi).
- Akun test yang sudah ada dan bisa dipakai ulang buat verifikasi HTTP (login): username `admintest`, password `123456` (dibuat user di percakapan sebelumnya, role Admin, tenant 18/outlet 13). **Jangan bikin akun test baru** — pola sesi ini menunjukkan pembuatan akun `hl_users` berulang kena block classifier keamanan; pakai akun ini, atau kalau sudah dihapus, minta user buatkan ulang lewat halaman Karyawan (jangan coba INSERT `hl_users` langsung berkali-kali).

---

### Task 1: Backend — recompute Tier Express + Biaya Lainnya di action `update`

**Files:**
- Modify: `orders.php:257-381` (blok recalc total + update items di action `update`)
- Test: `tests/orders/test_edit_tier_recompute.php` (baru)

**Interfaces:**
- Consumes: `ExpressTier::findByNama(int $tenantId, string $namaTier, ?int $outletId): ?array` (sudah ada, `core/ExpressTier.php:59`), `ExpressTier::calcItemFee(float $itemSubtotal, ?array $tier): float` (sudah ada, `core/ExpressTier.php:88`), `ExpressTier::dominantTier(array $itemsWithTier): array` → `['nama' => string|null, 'tipe_order' => string]` (sudah ada, `core/ExpressTier.php:106`), `BiayaLainnyaTier::calcAppliedFees(int $tenantId, ?int $outletId, float $subtotal): array` → `[['nama'=>string, 'nominal'=>float], ...]` (sudah ada & sudah dipakai `pos.php`, `core/BiayaLainnyaTier.php:70`).
- Produces: kolom `hl_transaksi_item.express_tier_nama`/`biaya_express` terisi benar setiap edit; `hl_transaksi.biaya_tambahan`/`biaya_lainnya`/`tipe_order`/`express_tier_nama` ter-recompute; `hl_transaksi_biaya_lainnya` ter-refresh. Task 2 (endpoint `express_tiers`) dan Task 3 (frontend) tidak bergantung ke detail internal task ini — mereka cuma kirim `express_tier_nama` per item di payload `items[]`, format yang SUDAH diterima action `update` (field baru, bukan breaking change ke payload existing).

- [ ] **Step 1: Baca ulang blok yang mau diubah, catat baseline**

Baca `orders.php` baris 226-381 (dari `$db->beginTransaction()` sampai penutup blok update items) — ini konteks penuh yang akan diedit di step berikut. Jangan ubah apapun dulu, cuma pastikan paham alurnya: `$oldRow` diambil dgn `FOR UPDATE`, `$itemsChanged` dihitung dari signature item lama vs baru, `$subtotal` dihitung dari `$data['items']`, lalu (SEBELUM perubahan) `$biayaTambahanLama`/`$biayaLainnyaLama` dipakai mentah ke `$total`, lalu gerbang `OrderEditResolver::resolve()` jalan pakai `$total` itu, lalu header di-UPDATE, lalu (kalau `$itemsChanged`) item di-DELETE+INSERT ulang TANPA kolom tier.

- [ ] **Step 2: Ganti blok snapshot-beku jadi recompute**

Cari blok ini di `orders.php` (sekitar baris 264-277):

```php
            // biaya_tambahan & biaya_lainnya = snapshot dari saat order dibuat,
            // TIDAK di-recompute di sini (Orders tidak mengelola ulang tier —
            // baik Express maupun Biaya Lainnya, keduanya murni auto-generate
            // saat order dibuat). BUGFIX lama: dulu rumus di bawah cuma
            // "subtotal - diskon", biaya_tambahan ikut hilang dari total
            // setiap kali item order diedit — sudah dibetulkan & TETAP begini.
            $biayaTambahanLama = (float)($oldRow['biaya_tambahan'] ?? 0);
            $biayaLainnyaLama  = (float)($oldRow['biaya_lainnya'] ?? 0);

            $diskon = floatval($data['diskon'] ?? 0);
            $total  = $subtotal > 0
                ? max(0, $subtotal - $diskon + $biayaTambahanLama + $biayaLainnyaLama)
                : max(0, floatval($data['total'] ?? 0));
```

Ganti PERSIS jadi:

```php
            // biaya_tambahan (Tier Express) & biaya_lainnya (Biaya Lainnya Tier)
            // DIRECOMPUTE dari kondisi terbaru setiap kali item berubah — server-side,
            // tidak percaya nilai fee dari klien (sama pola pos.php). Kalau item TIDAK
            // berubah, tetap dari row lama (persis sebelumnya, tidak disentuh).
            // Lihat docs/superpowers/specs/2026-09-05-tier-express-edit-order-design.md
            require_once ROOT . '/core/ExpressTier.php';
            require_once ROOT . '/core/BiayaLainnyaTier.php';
            $itemsWithTier    = [];
            $biayaLainnyaRows = [];
            $dom = ['nama' => null, 'tipe_order' => 'reguler'];
            if ($itemsChanged && !empty($data['items'])) {
                $biayaTambahanBaru = 0.0;
                foreach ($data['items'] as $i => $item) {
                    $namaTier = trim((string)($item['express_tier_nama'] ?? ''));
                    $tier = $namaTier !== '' ? ExpressTier::findByNama($tid, $namaTier, $oid) : null;
                    $itSub = floatval($item['jumlah']) * floatval($item['harga_satuan']);
                    $itFee = ExpressTier::calcItemFee($itSub, $tier);
                    $itemsWithTier[$i] = [
                        'express_tier_nama' => $tier ? $tier['nama_tier'] : null,
                        'biaya_express'     => $itFee,
                    ];
                    $biayaTambahanBaru += $itFee;
                }
                $dom = ExpressTier::dominantTier(array_map(
                    fn($it, $x) => array_merge($it, $x), $data['items'], $itemsWithTier
                ));
                $biayaLainnyaRows = BiayaLainnyaTier::calcAppliedFees($tid, $oid, $subtotal);
                $biayaLainnyaBaru = array_sum(array_column($biayaLainnyaRows, 'nominal'));
            } else {
                $biayaTambahanBaru = (float)($oldRow['biaya_tambahan'] ?? 0);
                $biayaLainnyaBaru  = (float)($oldRow['biaya_lainnya'] ?? 0);
            }

            $diskon = floatval($data['diskon'] ?? 0);
            $total  = $subtotal > 0
                ? max(0, $subtotal - $diskon + $biayaTambahanBaru + $biayaLainnyaBaru)
                : max(0, floatval($data['total'] ?? 0));
```

- [ ] **Step 3: `php -l` cek syntax**

Run: `php -l orders.php`
Expected: `No syntax errors detected in orders.php`

- [ ] **Step 4: Update blok DELETE+INSERT item — sertakan kolom tier**

Cari blok ini (sekitar baris 360-381, sudah bergeser sedikit karena Step 2 nambah baris — cari via isi teksnya, bukan nomor baris):

```php
            // Update items HANYA jika benar-benar berubah (hindari churn + log palsu)
            if (!empty($data['items']) && $itemsChanged) {
                $db->prepare("DELETE FROM hl_transaksi_item WHERE tenant_id=? AND outlet_id=? AND transaksi_id=?")->execute([$tid, $oid, $id]);
                $istmt = $db->prepare("INSERT INTO hl_transaksi_item
                    (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal,catatan_item)
                    VALUES (?,?,?,?,?,?,?,?,?,?)");
                foreach ($data['items'] as $item) {
                    $sub = floatval($item['jumlah']) * floatval($item['harga_satuan']);
                    $istmt->execute([
                        $tid, $oid, $id,
                        $item['layanan_id'] ?: null,
                        $item['nama_layanan'],
                        $item['satuan'],
                        $item['jumlah'],
                        $item['harga_satuan'],
                        $sub,
                        $item['catatan_item'] ?? ''
                    ]);
                }
                // Update subtotal di header
                $db->prepare("UPDATE hl_transaksi SET subtotal=? WHERE tenant_id=? AND outlet_id=? AND id=?")->execute([$subtotal, $tid, $oid, $id]);
            }
```

Ganti PERSIS jadi:

```php
            // Update items HANYA jika benar-benar berubah (hindari churn + log palsu)
            if (!empty($data['items']) && $itemsChanged) {
                $db->prepare("DELETE FROM hl_transaksi_item WHERE tenant_id=? AND outlet_id=? AND transaksi_id=?")->execute([$tid, $oid, $id]);
                $istmt = $db->prepare("INSERT INTO hl_transaksi_item
                    (tenant_id,outlet_id,transaksi_id,layanan_id,nama_layanan,satuan,jumlah,harga_satuan,subtotal,catatan_item,express_tier_nama,biaya_express)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                foreach ($data['items'] as $i => $item) {
                    $sub = floatval($item['jumlah']) * floatval($item['harga_satuan']);
                    $istmt->execute([
                        $tid, $oid, $id,
                        $item['layanan_id'] ?: null,
                        $item['nama_layanan'],
                        $item['satuan'],
                        $item['jumlah'],
                        $item['harga_satuan'],
                        $sub,
                        $item['catatan_item'] ?? '',
                        $itemsWithTier[$i]['express_tier_nama'] ?? null,
                        $itemsWithTier[$i]['biaya_express'] ?? 0,
                    ]);
                }
                // Update subtotal + biaya_tambahan + biaya_lainnya + ringkasan tier di header
                $db->prepare("UPDATE hl_transaksi SET subtotal=?, biaya_tambahan=?, biaya_lainnya=?, tipe_order=?, express_tier_nama=? WHERE tenant_id=? AND outlet_id=? AND id=?")
                   ->execute([$subtotal, $biayaTambahanBaru, $biayaLainnyaBaru, $dom['tipe_order'], $dom['nama'], $tid, $oid, $id]);

                // Refresh breakdown Biaya Lainnya (persis pola insert di pos.php:592)
                $db->prepare("DELETE FROM hl_transaksi_biaya_lainnya WHERE tenant_id=? AND outlet_id=? AND transaksi_id=?")->execute([$tid, $oid, $id]);
                if (!empty($biayaLainnyaRows)) {
                    $blstmt = $db->prepare("INSERT INTO hl_transaksi_biaya_lainnya (tenant_id, outlet_id, transaksi_id, nama, nominal) VALUES (?,?,?,?,?)");
                    foreach ($biayaLainnyaRows as $r) {
                        $blstmt->execute([$tid, $oid, $id, $r['nama'], $r['nominal']]);
                    }
                }
            }
```

- [ ] **Step 5: `php -l` cek syntax lagi**

Run: `php -l orders.php`
Expected: `No syntax errors detected in orders.php`

- [ ] **Step 6: Tulis test integrasi (PHP CLI, hit endpoint asli via HTTP)**

Buat file `tests/orders/test_edit_tier_recompute.php`:

```php
<?php
// Test integrasi: action=update di orders.php recompute biaya_tambahan
// (Tier Express) & biaya_lainnya (Biaya Lainnya Tier) saat item order
// diedit. Hit endpoint ASLI via HTTP (curl+session), bukan simulasi —
// supaya benar2 nge-test wiring-nya, bukan cuma algoritma.
//
// Run: php tests/orders/test_edit_tier_recompute.php
// Butuh: server sudah live di https://lamasy.harpy.id, akun test
// admintest/123456 (tenant 18, outlet 13) masih aktif.

require_once __DIR__ . '/../../master/config/db.php';
$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$BASE = 'https://lamasy.harpy.id';
$TID = 18; $OID = 13;
$pass = 0; $fail = 0;
function check($label, $cond) {
    global $pass, $fail;
    if ($cond) { echo "PASS: $label\n"; $pass++; }
    else       { echo "FAIL: $label\n"; $fail++; }
}

// ── 1. Login, ambil cookie jar + csrf token ──
$cookieFile = tempnam(sys_get_temp_dir(), 'lmcookie');
$ch = curl_init("$BASE/login.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['username'=>'admintest','password'=>'123456']),
    CURLOPT_FOLLOWLOCATION => true,
]);
curl_exec($ch); curl_close($ch);

$ch = curl_init("$BASE/orders.php");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookieFile]);
$html = curl_exec($ch); curl_close($ch);
preg_match('/csrfToken\s*=\s*[\'"]([^\'"]+)[\'"]/', $html, $m);
$csrf = $m[1] ?? '';
check('login berhasil & dapat csrf token', $csrf !== '');

// ── 2. Cari 1 Tier Express aktif & 1 Biaya Lainnya Tier aktif (persen) di tenant ini ──
$tier = $pdo->prepare("SELECT nama_tier, tipe_biaya, nilai_biaya FROM hl_express_tier WHERE tenant_id=? AND is_active=1 AND (outlet_id IS NULL OR outlet_id=?) LIMIT 1");
$tier->execute([$TID, $OID]);
$tier = $tier->fetch(PDO::FETCH_ASSOC);
check('ada minimal 1 Tier Express aktif di tenant test', $tier !== false);

$blTier = $pdo->prepare("SELECT nama, tipe_biaya, nilai_biaya FROM hl_biaya_lainnya_tier WHERE tenant_id=? AND is_active=1 AND (outlet_id IS NULL OR outlet_id=?) LIMIT 1");
$blTier->execute([$TID, $OID]);
$blTier = $blTier->fetch(PDO::FETCH_ASSOC);

if (!$tier) { echo "SKIP sisanya — gak ada Tier Express aktif buat ditest.\n"; exit(1); }

// ── 3. Seed order test langsung ke DB (2 item, subtotal awal 50.000, tanpa tier) ──
$pdo->exec("INSERT INTO hl_transaksi (tenant_id, outlet_id, no_order, tanggal, nama_pelanggan, telepon, status_proses, status_bayar, subtotal, diskon, total, dp, sisa_bayar, biaya_tambahan, biaya_lainnya, created_at, updated_at)
    VALUES ($TID, $OID, 'TESTTIER-" . time() . "', CURDATE(), 'Test Tier Recompute', '', 'masuk', 'belum_bayar', 50000, 0, 50000, 0, 50000, 0, 0, NOW(), NOW())");
$orderId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO hl_transaksi_item (tenant_id, outlet_id, transaksi_id, nama_layanan, satuan, jumlah, harga_satuan, subtotal, catatan_item) VALUES (?,?,?,?,?,?,?,?,?)")
    ->execute([$TID, $OID, $orderId, 'Cuci Reguler', 'kg', 5, 10000, 50000, '']);

// ── 4. PUT action=update: ubah item jadi pakai tier Express ──
$itemSub = 5 * 10000;
$expectedFee = $tier['tipe_biaya'] === 'flat' ? (float)$tier['nilai_biaya'] : round($itemSub * ((float)$tier['nilai_biaya']/100));
$expectedBiayaLainnya = 0.0;
if ($blTier) {
    $expectedBiayaLainnya = $blTier['tipe_biaya'] === 'flat' ? (float)$blTier['nilai_biaya'] : round($itemSub * ((float)$blTier['nilai_biaya']/100));
}
$payload = json_encode([
    'id' => $orderId,
    'status_proses' => 'masuk',
    'diskon' => 0,
    'dp' => 0,
    'items' => [[
        'layanan_id' => null, 'nama_layanan' => 'Cuci Reguler', 'satuan' => 'kg',
        'jumlah' => 5, 'harga_satuan' => 10000, 'catatan_item' => '',
        'express_tier_nama' => $tier['nama_tier'],
    ]],
]);
$ch = curl_init("$BASE/orders.php?action=update");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-Token: $csrf"],
]);
$resp = json_decode(curl_exec($ch), true); curl_close($ch);
check('action=update tidak error', empty($resp['error']));

// ── 5. Verifikasi DB langsung ──
$row = $pdo->prepare("SELECT biaya_tambahan, biaya_lainnya, tipe_order, express_tier_nama FROM hl_transaksi WHERE id=?");
$row->execute([$orderId]); $row = $row->fetch(PDO::FETCH_ASSOC);
check("biaya_tambahan header = $expectedFee (got {$row['biaya_tambahan']})", abs((float)$row['biaya_tambahan'] - $expectedFee) < 0.01);
check("express_tier_nama header = '{$tier['nama_tier']}' (got '{$row['express_tier_nama']}')", $row['express_tier_nama'] === $tier['nama_tier']);
if ($blTier) {
    check("biaya_lainnya header = $expectedBiayaLainnya (got {$row['biaya_lainnya']})", abs((float)$row['biaya_lainnya'] - $expectedBiayaLainnya) < 0.01);
}

$item = $pdo->prepare("SELECT express_tier_nama, biaya_express FROM hl_transaksi_item WHERE transaksi_id=?");
$item->execute([$orderId]); $item = $item->fetch(PDO::FETCH_ASSOC);
check("item.express_tier_nama = '{$tier['nama_tier']}' (got '{$item['express_tier_nama']}')", $item['express_tier_nama'] === $tier['nama_tier']);
check("item.biaya_express = $expectedFee (got {$item['biaya_express']})", abs((float)$item['biaya_express'] - $expectedFee) < 0.01);

// ── 6. Cleanup ──
$pdo->prepare("DELETE FROM hl_transaksi_biaya_lainnya WHERE transaksi_id=?")->execute([$orderId]);
$pdo->prepare("DELETE FROM hl_transaksi_item WHERE transaksi_id=?")->execute([$orderId]);
$pdo->prepare("DELETE FROM hl_transaksi WHERE id=?")->execute([$orderId]);
unlink($cookieFile);

echo "\n$pass PASS, $fail FAIL\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 7: Jalankan test, pastikan semua PASS**

Run: `php tests/orders/test_edit_tier_recompute.php`
Expected: semua baris `PASS:`, diakhiri `N PASS, 0 FAIL`. Kalau `SKIP` (tidak ada Tier Express aktif di tenant test), laporkan itu ke controller — bukan kegagalan implementasi, tapi data test perlu disiapkan dulu (tanya user/controller, jangan bikin tier baru sendiri tanpa izin).

- [ ] **Step 8: Verifikasi item yang TIDAK diubah tetap bawa tier lamanya**

Tambahkan ke test script yang sama (sebelum blok cleanup Step 6), skenario ke-2: seed order baru dengan 2 item — item A sudah pakai tier Express (`express_tier_nama`/`biaya_express` terisi manual di INSERT), item B tanpa tier. Panggil `action=update` dgn payload yang cuma mengubah `jumlah` item B (item A dikirim identik persis). Assert item A di DB tetap `express_tier_nama`/`biaya_express` sama seperti sebelum update (karena `editItems` di frontend akan submit apa adanya kalau user gak sentuh dropdown-nya — test ini simulasikan payload yang sama).

```php
// ── Skenario 2: item yang gak diubah tetap bawa tier lamanya ──
$pdo->exec("INSERT INTO hl_transaksi (tenant_id, outlet_id, no_order, tanggal, nama_pelanggan, telepon, status_proses, status_bayar, subtotal, diskon, total, dp, sisa_bayar, biaya_tambahan, biaya_lainnya, created_at, updated_at)
    VALUES ($TID, $OID, 'TESTTIER2-" . time() . "', CURDATE(), 'Test Preserve Tier', '', 'masuk', 'belum_bayar', 80000, 0, 80000, 0, 80000, $expectedFee, 0, NOW(), NOW())");
$orderId2 = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO hl_transaksi_item (tenant_id, outlet_id, transaksi_id, nama_layanan, satuan, jumlah, harga_satuan, subtotal, catatan_item, express_tier_nama, biaya_express) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([$TID, $OID, $orderId2, 'Cuci Express', 'kg', 5, 10000, 50000, '', $tier['nama_tier'], $expectedFee]);
$pdo->prepare("INSERT INTO hl_transaksi_item (tenant_id, outlet_id, transaksi_id, nama_layanan, satuan, jumlah, harga_satuan, subtotal, catatan_item) VALUES (?,?,?,?,?,?,?,?,?)")
    ->execute([$TID, $OID, $orderId2, 'Setrika', 'kg', 3, 10000, 30000, '']);

$payload2 = json_encode([
    'id' => $orderId2, 'status_proses' => 'masuk', 'diskon' => 0, 'dp' => 0,
    'items' => [
        ['layanan_id'=>null,'nama_layanan'=>'Cuci Express','satuan'=>'kg','jumlah'=>5,'harga_satuan'=>10000,'catatan_item'=>'','express_tier_nama'=>$tier['nama_tier']],
        ['layanan_id'=>null,'nama_layanan'=>'Setrika','satuan'=>'kg','jumlah'=>4,'harga_satuan'=>10000,'catatan_item'=>''], // qty berubah 3→4, tanpa tier
    ],
]);
$ch = curl_init("$BASE/orders.php?action=update");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload2,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-Token: $csrf"],
]);
$resp2 = json_decode(curl_exec($ch), true); curl_close($ch);
check('skenario 2: action=update tidak error', empty($resp2['error']));

$items2 = $pdo->prepare("SELECT nama_layanan, express_tier_nama, biaya_express FROM hl_transaksi_item WHERE transaksi_id=? ORDER BY id");
$items2->execute([$orderId2]);
$items2 = $items2->fetchAll(PDO::FETCH_ASSOC);
$itemA = $items2[0]; $itemB = $items2[1];
check("item A (gak diubah) tetap tier '{$tier['nama_tier']}'", $itemA['express_tier_nama'] === $tier['nama_tier']);
check("item A (gak diubah) tetap biaya_express $expectedFee", abs((float)$itemA['biaya_express'] - $expectedFee) < 0.01);
check("item B (qty berubah, tetap tanpa tier) express_tier_nama NULL", $itemB['express_tier_nama'] === null);

$pdo->prepare("DELETE FROM hl_transaksi_biaya_lainnya WHERE transaksi_id=?")->execute([$orderId2]);
$pdo->prepare("DELETE FROM hl_transaksi_item WHERE transaksi_id=?")->execute([$orderId2]);
$pdo->prepare("DELETE FROM hl_transaksi WHERE id=?")->execute([$orderId2]);
```

- [ ] **Step 9: Jalankan test lagi, pastikan semua PASS termasuk skenario 2**

Run: `php tests/orders/test_edit_tier_recompute.php`
Expected: semua `PASS:`, `0 FAIL`.

- [ ] **Step 10: Regresi cepat — order TANPA perubahan item tidak kena recompute**

Verifikasi manual (bukan bagian file test, cukup dicek sekali): panggil `action=update` dgn `items` PERSIS SAMA seperti item existing sebuah order test (tidak ada perubahan apapun) → cek `$itemsChanged` di kode tetap `false` untuk kasus ini (baca ulang logic `$sigItems` di Step 1 — TIDAK diubah oleh task ini, jadi harus tetap konsisten) → `biaya_tambahan`/`biaya_lainnya` di DB TIDAK berubah. Ini cuma re-affirm perilaku existing yang tidak boleh regresi, tidak perlu kode test baru.

- [ ] **Step 11: Regresi cepat — order LUNAS yang totalnya berubah gara-gara tier tetap kena gerbang**

Verifikasi manual: seed 1 order test dgn `status_bayar='lunas'` (dp=total), lalu panggil `action=update` dgn item yang ditambah tier Express (bikin `biaya_tambahan` naik, otomatis `$total` baru > `$total` lama). Karena `$total` sekarang dihitung dari `$biayaTambahanBaru` SEBELUM `$gate = OrderEditResolver::resolve(...)` dipanggil (urutan baris tidak diubah oleh Step 2 — recompute cuma mengganti ISI variabelnya, bukan posisinya), gerbang otomatis kebagian nilai baru. Expected: response `action=update` berisi `need_confirm` (bukan langsung sukses) — persis perilaku existing kalau total berubah krn sebab lain (item/diskon). Kalau ternyata langsung sukses tanpa gerbang, itu bug — cek ulang bahwa blok recompute di Step 2 benar2 ditaruh SEBELUM baris `$gate = OrderEditResolver::resolve(...)`, bukan sesudahnya.

- [ ] **Step 12: Commit**

```bash
git add orders.php tests/orders/test_edit_tier_recompute.php
git commit -m "feat(orders): recompute biaya_tambahan (Tier Express) & biaya_lainnya saat edit order

Sebelumnya keduanya snapshot beku dari saat order dibuat (sengaja,
lihat komentar lama yg dihapus). Sekarang direcompute server-side
dari items yang di-submit setiap kali item order berubah, pakai
ExpressTier::findByNama/calcItemFee/dominantTier +
BiayaLainnyaTier::calcAppliedFees (method2 yg sudah ada, belum ada
pemanggilnya sebelum ini). Item yg gak disentuh user otomatis tetap
bawa tier lamanya krn payload dari frontend submit apa adanya.

Bugfix bareng: DELETE+INSERT hl_transaksi_item saat edit dulu gak
nyertain express_tier_nama/biaya_express sama sekali — data tier
per-item hilang tiap kali order diedit walau totalnya tetap benar
(snapshot beku di header). Sekarang ikut di-carry.

Lihat docs/superpowers/specs/2026-09-05-tier-express-edit-order-design.md

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 2: Backend endpoint + Frontend UI — dropdown Tier Express di edit item

**Files:**
- Modify: `orders.php` (endpoint baru dekat `get_layanan`, + JS: `loadLayanan()` call site, `renderEditItems()`, `addEditRow()`, `addEditLayanan()`, `recalcEdit()`)

**Interfaces:**
- Consumes: `ExpressTier::forTenant(int $tenantId, ?int $outletId): array` (sudah ada, dipakai `pos.php`) — return array of `{id, nama_tier, estimasi_jam, tipe_biaya, nilai_biaya, urutan, outlet_id}`. Format `editItems[i]` object dari Task 1 (field `express_tier_nama`/`biaya_express` sudah readonly-preserved oleh backend Task 1, task ini yang bikin usernya bisa UBAH field itu dari UI).
- Produces: `editItems[i].express_tier_nama`/`biaya_express` bisa diubah lewat UI, ikut ke payload `action=update` (dikonsumsi Task 1, sudah selesai/independent — task ini TIDAK perlu Task 1 selesai duluan buat jalan sendiri, tapi hasil end-to-end-nya baru lengkap kalau dua2nya ada).

- [ ] **Step 1: Tambah endpoint `action=express_tiers`**

Cari blok ini di `orders.php` (action `get_layanan`, sekitar baris 914):

```php
    if ($action === 'get_layanan') {
```

Baca isi lengkapnya dulu (`orders.php` baris 914-920-an) buat tau pola return-nya, lalu tambahkan blok BARU persis SEBELUM baris itu:

```php
    if ($action === 'express_tiers') {
        require_once ROOT . '/core/ExpressTier.php';
        echo json_encode(ExpressTier::forTenant($tid, $oid)); exit;
    }

    if ($action === 'get_layanan') {
```

- [ ] **Step 2: `php -l` cek syntax**

Run: `php -l orders.php`
Expected: `No syntax errors detected in orders.php`

- [ ] **Step 3: Load tier list ke variabel global JS**

Cari (sekitar baris 1618):

```javascript
let layananAll = [];
```

Tambahkan PERSIS setelahnya:

```javascript
let availableTiersEdit = [];  // {id, nama_tier, estimasi_jam, tipe_biaya, nilai_biaya, urutan, outlet_id}
```

Cari fungsi `loadLayanan()` (sekitar baris 1653):

```javascript
async function loadLayanan() {
  const r = await fetch('orders.php?action=get_layanan');
  layananAll = await r.json();
}
```

Tambahkan fungsi BARU persis setelahnya:

```javascript
async function loadExpressTiersEdit() {
  const r = await fetch('orders.php?action=express_tiers');
  availableTiersEdit = await r.json();
}
```

Cari titik pemanggilan `await loadLayanan();` di `DOMContentLoaded` (sekitar baris 1634):

```javascript
  await loadLayanan();
```

Ganti jadi:

```javascript
  await loadLayanan();
  await loadExpressTiersEdit();
```

- [ ] **Step 4: Tambah kolom Express di `renderEditItems()`**

Cari fungsi ini di `orders.php` (sekitar baris 2247-2260):

```javascript
function renderEditItems() {
  const tbody = document.getElementById('editItemsBody');
  if (!tbody) return;
  tbody.innerHTML = editItems.map((item, i) => `
    <tr>
      <td data-lbl="Layanan">${CAN_EDIT_ORDER ? `<input class="item-input" value="${esc(item.nama_layanan)}" style="width:110px" oninput="editItems[${i}].nama_layanan=this.value;recalcEdit()"/>` : `<span style="font-size:13px">${esc(item.nama_layanan)}</span>`}</td>
      <td data-lbl="Satuan">${CAN_EDIT_ORDER ? `<select class="item-input" style="width:52px" onchange="editItems[${i}].satuan=this.value">${['kg','pcs','set','pasang'].map(s=>`<option value="${s}" ${item.satuan===s?'selected':''}>${s}</option>`).join('')}</select>` : `<span style="font-size:13px">${item.satuan}</span>`}</td>
      <td data-lbl="Jumlah">${CAN_EDIT_ORDER ? `<input class="item-input" type="number" value="${item.jumlah}" step="0.1" min="0" style="width:52px" oninput="editItems[${i}].jumlah=parseFloat(this.value)||0;recalcEdit()"/>` : `<span style="font-family:var(--mono);font-size:13px">${item.jumlah}</span>`}</td>
      <td data-lbl="Harga">${CAN_EDIT_ORDER ? `<input class="item-input" type="text" inputmode="numeric" value="${grpRibu(item.harga_satuan)}" style="width:80px" oninput="const v=parseInt(this.value.replace(/\\D/g,''))||0;editItems[${i}].harga_satuan=v;this.value=grpRibu(v);recalcEdit()"/>` : `<span style="font-family:var(--mono);font-size:13px">Rp ${grpRibu(item.harga_satuan)}</span>`}</td>
      <td data-lbl="Subtotal" class="item-sub">Rp ${grpRibu(item.jumlah*item.harga_satuan)}</td>
      <td data-lbl="Ket">${CAN_EDIT_ORDER ? `<input class="item-input" value="${esc(item.catatan_item||'')}" placeholder="..." style="width:60px" oninput="editItems[${i}].catatan_item=this.value"/>` : `<span style="font-size:12px;color:var(--gray)">${esc(item.catatan_item||'-')}</span>`}</td>
      <td>${CAN_EDIT_ORDER ? `<button class="btn-remove" onclick="removeEditItem(${i})">✕ Hapus</button>` : ''}</td>
    </tr>`).join('');
}
```

Ganti PERSIS jadi (nambah `<td data-lbl="Express">` di antara Ket dan tombol Hapus):

```javascript
function renderEditItems() {
  const tbody = document.getElementById('editItemsBody');
  if (!tbody) return;
  tbody.innerHTML = editItems.map((item, i) => `
    <tr>
      <td data-lbl="Layanan">${CAN_EDIT_ORDER ? `<input class="item-input" value="${esc(item.nama_layanan)}" style="width:110px" oninput="editItems[${i}].nama_layanan=this.value;recalcEdit()"/>` : `<span style="font-size:13px">${esc(item.nama_layanan)}</span>`}</td>
      <td data-lbl="Satuan">${CAN_EDIT_ORDER ? `<select class="item-input" style="width:52px" onchange="editItems[${i}].satuan=this.value">${['kg','pcs','set','pasang'].map(s=>`<option value="${s}" ${item.satuan===s?'selected':''}>${s}</option>`).join('')}</select>` : `<span style="font-size:13px">${item.satuan}</span>`}</td>
      <td data-lbl="Jumlah">${CAN_EDIT_ORDER ? `<input class="item-input" type="number" value="${item.jumlah}" step="0.1" min="0" style="width:52px" oninput="editItems[${i}].jumlah=parseFloat(this.value)||0;recalcEdit()"/>` : `<span style="font-family:var(--mono);font-size:13px">${item.jumlah}</span>`}</td>
      <td data-lbl="Harga">${CAN_EDIT_ORDER ? `<input class="item-input" type="text" inputmode="numeric" value="${grpRibu(item.harga_satuan)}" style="width:80px" oninput="const v=parseInt(this.value.replace(/\\D/g,''))||0;editItems[${i}].harga_satuan=v;this.value=grpRibu(v);recalcEdit()"/>` : `<span style="font-family:var(--mono);font-size:13px">Rp ${grpRibu(item.harga_satuan)}</span>`}</td>
      <td data-lbl="Subtotal" class="item-sub">Rp ${grpRibu(item.jumlah*item.harga_satuan)}</td>
      <td data-lbl="Express">${CAN_EDIT_ORDER ? `
        <select class="item-input" style="width:110px;font-size:11px" onchange="onEditItemTierChange(${i}, this.value)">
          <option value="">⏱️ Reguler</option>
          ${availableTiersEdit.map(t => `<option value="${esc(t.nama_tier)}" ${item.express_tier_nama===t.nama_tier?'selected':''}>⚡ ${esc(t.nama_tier)}</option>`).join('')}
        </select>
        ${item.biaya_express > 0 ? `<div style="font-size:10px;color:#92400E;margin-top:2px;">+Rp ${grpRibu(item.biaya_express)}</div>` : ''}
      ` : `<span style="font-size:12px;color:var(--gray)">${item.express_tier_nama ? '⚡ ' + esc(item.express_tier_nama) : 'Reguler'}</span>`}</td>
      <td data-lbl="Ket">${CAN_EDIT_ORDER ? `<input class="item-input" value="${esc(item.catatan_item||'')}" placeholder="..." style="width:60px" oninput="editItems[${i}].catatan_item=this.value"/>` : `<span style="font-size:12px;color:var(--gray)">${esc(item.catatan_item||'-')}</span>`}</td>
      <td>${CAN_EDIT_ORDER ? `<button class="btn-remove" onclick="removeEditItem(${i})">✕ Hapus</button>` : ''}</td>
    </tr>`).join('');
}

function onEditItemTierChange(idx, tierName) {
  const item = editItems[idx];
  if (!item) return;
  item.express_tier_nama = tierName || null;
  const tier = availableTiersEdit.find(t => t.nama_tier === tierName);
  const itSub = item.jumlah * item.harga_satuan;
  item.biaya_express = tier ? (tier.tipe_biaya === 'flat' ? parseFloat(tier.nilai_biaya) : Math.round(itSub * (parseFloat(tier.nilai_biaya)/100))) : 0;
  renderEditItems();
  recalcEdit();
}
```

Catatan: `onEditItemTierChange` cuma buat LIVE PREVIEW di modal (subtotal/total kelihatan update begitu user pilih tier) — bukan sumber kebenaran. Backend (Task 1) tetap recompute ulang dari nol pakai `ExpressTier::calcItemFee`/`findByNama` yang sebenarnya saat `action=update` diproses, jadi walau ada bug di rumus preview client-side ini, angka yang TERSIMPAN tetap benar.

- [ ] **Step 5: Tambah field default di item baru — `addEditRow()`/`addEditLayanan()`**

Cari (sekitar baris 2262-2266):

```javascript
function addEditRow() {
  editItems.push({layanan_id:null,nama_layanan:'',satuan:'kg',jumlah:1,harga_satuan:0,catatan_item:''});
  renderEditItems(); recalcEdit();
}
```

Ganti jadi:

```javascript
function addEditRow() {
  editItems.push({layanan_id:null,nama_layanan:'',satuan:'kg',jumlah:1,harga_satuan:0,catatan_item:'',express_tier_nama:null,biaya_express:0});
  renderEditItems(); recalcEdit();
}
```

Cari (sekitar baris 2285-2288):

```javascript
function addEditLayanan(id, nama, satuan, harga) {
  editItems.push({layanan_id:id,nama_layanan:nama,satuan,jumlah:1,harga_satuan:harga,catatan_item:''});
  renderEditItems(); recalcEdit();
}
```

Ganti jadi:

```javascript
function addEditLayanan(id, nama, satuan, harga) {
  editItems.push({layanan_id:id,nama_layanan:nama,satuan,jumlah:1,harga_satuan:harga,catatan_item:'',express_tier_nama:null,biaya_express:0});
  renderEditItems(); recalcEdit();
}
```

- [ ] **Step 6: `recalcEdit()` ikut sum `biaya_express` per item buat preview Total**

Cari (sekitar baris 2334-2337):

```javascript
function recalcEdit() {
  const sub  = editItems.reduce((s,i) => s + i.jumlah * i.harga_satuan, 0);
  const dis  = parseFloat(document.getElementById('edit_diskon')?.value) || 0;
  const tot  = Math.max(sub - dis + (currentOrderBiayaTambahan || 0) + (currentOrderBiayaLainnya || 0), 0);
```

Ganti jadi:

```javascript
function recalcEdit() {
  const sub  = editItems.reduce((s,i) => s + i.jumlah * i.harga_satuan, 0);
  const dis  = parseFloat(document.getElementById('edit_diskon')?.value) || 0;
  // Preview Express dari editItems (live, ikut pilihan tier user) — Biaya Lainnya
  // tetap pakai nilai lama (currentOrderBiayaLainnya) sbg preview, krn recompute
  // persen-nya butuh subtotal final yg baru pasti di backend saat submit.
  const biayaExprPreview = editItems.reduce((s,i) => s + (i.biaya_express||0), 0);
  const tot  = Math.max(sub - dis + biayaExprPreview + (currentOrderBiayaLainnya || 0), 0);
```

- [ ] **Step 7: `php -l` cek syntax final**

Run: `php -l orders.php`
Expected: `No syntax errors detected in orders.php`

- [ ] **Step 8: Verifikasi live di browser (production, sudah di-deploy)**

Login pakai akun `admintest`/`123456` (tenant 18/outlet 13). Buka halaman Order, buka detail SALAH SATU order yang statusnya masih bisa diedit (bukan yg lunas biar gak kena gerbang konfirmasi dulu di test awal), klik Edit. Screenshot form edit item — pastikan kolom "Express" muncul dgn dropdown "⏱️ Reguler" + daftar tier aktif tenant. Pilih 1 tier utk salah satu item, pastikan subtotal/total preview di modal ikut naik. Klik Simpan, pastikan tersimpan tanpa error. Buka lagi detail order yang sama — pastikan dropdown tier item itu balik ke posisi yang barusan dipilih (bukti round-trip: data tersimpan & terbaca lagi dgn benar). Hapus/reset perubahan test ini di akhir kalau order yang dipakai bukan murni data test (lebih baik pakai order test yang di-buat sendiri lewat POS lalu dihapus lagi, bukan order asli tenant).

- [ ] **Step 9: Commit**

```bash
git add orders.php
git commit -m "feat(orders): dropdown Tier Express di form edit item order

Kolom baru 'Express' di tabel edit item, mirror pola pos.php — dropdown
diisi dari endpoint baru action=express_tiers (ExpressTier::forTenant,
sudah dipakai pos.php, sekarang dipakai juga di sini). Perubahan
tier di dropdown cuma live-preview client-side (onEditItemTierChange);
backend (commit sebelumnya) yang jadi sumber kebenaran nilai
tersimpan.

Lihat docs/superpowers/specs/2026-09-05-tier-express-edit-order-design.md

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Setelah kedua task selesai

Jalankan `superpowers:finishing-a-development-branch` — verifikasi test Task 1 (`tests/orders/test_edit_tier_recompute.php`) PASS semua sekali lagi di kondisi akhir (setelah Task 2 juga masuk, walau Task 2 gak nyentuh logic backend, good practice re-run), lalu tawarkan opsi merge/push seperti biasa.

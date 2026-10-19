# Antri Setrika Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tambah status `antri_setrika` (antara Kering dan Setrika) dengan kolom Kanban yang mengurutkan express dulu, lalu FIFO.

**Architecture:** Nilai ENUM baru di `hl_transaksi.status_proses` (`hl_proses_log` memakai VARCHAR, tidak perlu ALTER). Logika urutan antrean ada di satu kelas murni `core/AntriSetrika.php` supaya bisa diuji lewat CLI; `kanban.php` memakainya untuk kolom baru. File lain hanya menambah label/warna/daftar status. Halaman pelanggan memetakan status baru ke langkah "Kering".

**Tech Stack:** PHP 8.3, MySQL (ENUM), JS vanilla. Tidak ada framework test: pengujian logika lewat skrip CLI `php tests/antri_setrika_test.php`.

## Global Constraints

- Alur: `masuk → cuci → kering → antri_setrika → setrika → siap → diambil`. Kunci status persis `antri_setrika`.
- **Urutan rilis wajib: jalankan migrasi ENUM DULU, verifikasi `SHOW COLUMNS`, baru `git push` kode.** Terbalik = MySQL menyimpan `''` tanpa error.
- Order yang kini `kering` TIDAK dipindahkan otomatis.
- Express = order punya minimal satu `hl_transaksi_item.express_tier_nama` terisi (tanpa kolom baru).
- Urutan kolom: express dulu (deadline terdekat); lalu biasa FIFO menurut `MAX(hl_proses_log.created_at)` dengan `status_baru='antri_setrika'`.
- Header kolom antrean berwarna peringatan jika jumlah > 10 (konstanta `AntriSetrika::THRESHOLD`).
- Timestamp selalu `date('Y-m-d H:i:s')` PHP, jangan `NOW()` (bug timezone UTC vs WIB).
- Pelanggan (`track.php`, `cek.php`, pesan WA) tidak melihat istilah `antri_setrika`; tampil sebagai "Kering, menunggu disetrika" di langkah Kering.
- Notifikasi/loyalty tetap dipicu `siap`.
- Tidak ada drag di Kanban (hanya tombol lanjut); mundur status dilakukan dari `orders.php`.

## File Structure

| File | Tanggung jawab |
|---|---|
| `migrations/2026-10-19-antri-setrika.sql` (baru) | ALTER ENUM |
| `tenant/migrations/tenant_schema.sql` | ENUM utk tenant baru |
| `core/AntriSetrika.php` (baru) | `THRESHOLD`, `deadline()`, `sort()` |
| `tests/antri_setrika_test.php` (baru) | uji urutan via CLI |
| `kanban.php` | kolom baru, query, urutan, ⚡, header peringatan |
| `orders.php`, `dashboard.php`, `laporan.php`, `components.php`, `harpy-erp.css`, `absensi.php`, `core/AIChatData.php` | label, warna, daftar status |
| `track.php`, `cek.php` | tampilan pelanggan |

---

### Task 1: Migrasi ENUM

**Files:**
- Create: `migrations/2026-10-19-antri-setrika.sql`
- Modify: `tenant/migrations/tenant_schema.sql:156`

**Interfaces:**
- Produces: nilai ENUM `antri_setrika` valid di `hl_transaksi.status_proses`.

- [ ] **Step 1: Cek definisi kolom saat ini (butuh koneksi DB)**

Run: `mysql -e "SHOW COLUMNS FROM hl_transaksi LIKE 'status_proses'"`
Expected: `enum('masuk','cuci','kering','setrika','siap','diambil')` dan Default `masuk`. Jika koneksi gagal (`ERROR 2002`), minta user ganti jaringan; jika Null=NO, tambahkan `NOT NULL` di ALTER di Step 2.

- [ ] **Step 2: Tulis file migrasi**

```sql
-- migrations/2026-10-19-antri-setrika.sql
-- Status baru 'antri_setrika' antara kering dan setrika.
-- Spec: docs/superpowers/specs/2026-10-19-antri-setrika-design.md
-- hl_proses_log.status_lama/status_baru = VARCHAR(50), tidak perlu diubah.
-- WAJIB dijalankan SEBELUM kode di-push.
ALTER TABLE hl_transaksi
  MODIFY COLUMN status_proses
  ENUM('masuk','cuci','kering','antri_setrika','setrika','siap','diambil') DEFAULT 'masuk';
```

- [ ] **Step 3: Update skema tenant baru**

Di `tenant/migrations/tenant_schema.sql` baris 156 ganti:
`status_proses    ENUM('masuk','cuci','kering','setrika','siap','diambil') DEFAULT 'masuk',`
menjadi:
`status_proses    ENUM('masuk','cuci','kering','antri_setrika','setrika','siap','diambil') DEFAULT 'masuk',`

- [ ] **Step 4: Jalankan migrasi dan verifikasi**

Run: `mysql < migrations/2026-10-19-antri-setrika.sql && mysql -e "SHOW COLUMNS FROM hl_transaksi LIKE 'status_proses'; SELECT status_proses, COUNT(*) FROM hl_transaksi GROUP BY status_proses"`
Expected: ENUM memuat `antri_setrika` di antara `kering` dan `setrika`; jumlah per status sama seperti sebelum ALTER, tidak ada baris dengan status kosong `''`.

- [ ] **Step 5: Commit**

```bash
git add migrations/2026-10-19-antri-setrika.sql tenant/migrations/tenant_schema.sql
git commit -m "feat(antri-setrika): migrasi ENUM status_proses + skema tenant baru

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Kelas urutan antrean + tes

**Files:**
- Create: `core/AntriSetrika.php`
- Create: `tests/antri_setrika_test.php`

**Interfaces:**
- Produces: `AntriSetrika::THRESHOLD` (int 10); `AntriSetrika::deadline(array $row): int` (timestamp; pakai `estimasi_selesai`, fallback `created_at + estimasi_jam` jam, default 24); `AntriSetrika::sort(array $rows): array`. Baris berisi kunci: `id`, `created_at`, `estimasi_selesai`, `estimasi_jam`, `is_express` (0/1), `antri_at` (string|null).

- [ ] **Step 1: Tulis tes yang gagal**

```php
<?php
// tests/antri_setrika_test.php — jalankan: php tests/antri_setrika_test.php
require __DIR__ . '/../core/AntriSetrika.php';

function t(string $name, bool $ok): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) { $GLOBALS['fail'] = true; }
}
$row = fn(int $id, int $exp, ?string $antri, ?string $est, string $created = '2026-10-19 08:00:00', $jam = 24) =>
    ['id'=>$id,'is_express'=>$exp,'antri_at'=>$antri,'estimasi_selesai'=>$est,'created_at'=>$created,'estimasi_jam'=>$jam];

$rows = [
    $row(1, 0, '2026-10-19 10:00:00', null),
    $row(2, 0, '2026-10-19 09:00:00', null),
    $row(3, 1, '2026-10-19 11:00:00', '2026-10-19 18:00:00'),
    $row(4, 1, '2026-10-19 12:00:00', '2026-10-19 15:00:00'),
    $row(5, 0, null, null, '2026-10-19 07:00:00'),
];
$ids = array_column(AntriSetrika::sort($rows), 'id');
t('express dulu, deadline terdekat', array_slice($ids, 0, 2) === [4, 3]);
t('biasa FIFO (antri_at; null pakai created_at)', array_slice($ids, 2) === [5, 2, 1]);
t('deadline fallback created_at + estimasi_jam',
    AntriSetrika::deadline($row(9, 0, null, null, '2026-10-19 08:00:00', 6)) === strtotime('2026-10-19 14:00:00'));
t('deadline default 24 jam bila estimasi_jam kosong',
    AntriSetrika::deadline($row(9, 0, null, null, '2026-10-19 08:00:00', 0)) === strtotime('2026-10-20 08:00:00'));
t('threshold 10', AntriSetrika::THRESHOLD === 10);
exit(empty($GLOBALS['fail']) ? 0 : 1);
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php tests/antri_setrika_test.php`
Expected: fatal error file `core/AntriSetrika.php` tidak ditemukan.

- [ ] **Step 3: Implementasi**

```php
<?php
/**
 * AntriSetrika — aturan urutan antrean setrika (kolom Kanban 'antri_setrika').
 * Express dulu (deadline terdekat), lalu order biasa FIFO menurut waktu masuk antrean.
 */
final class AntriSetrika
{
    /** Jumlah antrean di atas ini → header kolom diberi warna peringatan. */
    public const THRESHOLD = 10;

    public static function deadline(array $r): int
    {
        if (!empty($r['estimasi_selesai'])) return (int)strtotime($r['estimasi_selesai']);
        $jam = (int)($r['estimasi_jam'] ?? 0) ?: 24;
        return (int)strtotime($r['created_at']) + $jam * 3600;
    }

    public static function sort(array $rows): array
    {
        usort($rows, function (array $a, array $b): int {
            $ea = !empty($a['is_express']);
            $eb = !empty($b['is_express']);
            if ($ea !== $eb) return $ea ? -1 : 1;
            if ($ea) {
                $c = self::deadline($a) <=> self::deadline($b);
            } else {
                $c = strtotime($a['antri_at'] ?: $a['created_at']) <=> strtotime($b['antri_at'] ?: $b['created_at']);
            }
            return $c ?: ((int)$a['id'] <=> (int)$b['id']);
        });
        return $rows;
    }
}
```

- [ ] **Step 4: Jalankan tes, pastikan lulus**

Run: `php tests/antri_setrika_test.php && php -l core/AntriSetrika.php`
Expected: lima baris `PASS`, exit 0, `No syntax errors`.

- [ ] **Step 5: Commit**

```bash
git add core/AntriSetrika.php tests/antri_setrika_test.php
git commit -m "feat(antri-setrika): aturan urutan antrean (express dulu, lalu FIFO) + tes

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Kanban — kolom Antri Setrika

**Files:**
- Modify: `kanban.php` (komentar baris 4; `data` ±40-75; `allowed` ±89; CSS ±205-214; markup ±270-272; JS `NEXT`/`LABEL_NEXT`/`renderCard`/`loadKanban` ±301-365)

**Interfaces:**
- Consumes: `AntriSetrika::sort()`, `AntriSetrika::THRESHOLD`, `AntriSetrika::deadline()` dari Task 2.
- Produces: respons `action=data` memuat `columns.antri_setrika` (sudah terurut), `antri_threshold`, dan tiap baris `is_express`.

- [ ] **Step 1: Require kelas**

Di dekat require bagian atas `kanban.php` (setelah require lain, sebelum blok `if ($action ...)`), tambahkan:
`require_once __DIR__ . '/core/AntriSetrika.php';`

- [ ] **Step 2: Ubah query data**

Ganti `$statuses = ['masuk','cuci','kering','setrika','siap'];` dengan:
`$statuses = ['masuk','cuci','kering','antri_setrika','setrika','siap'];`

Di SELECT, setelah `t.estimasi_selesai, t.estimasi_jam,` sisipkan dua kolom turunan (sebelum subquery `items_summary`):

```php
                           EXISTS (SELECT 1 FROM hl_transaksi_item xi
                                    WHERE xi.transaksi_id=t.id AND xi.tenant_id=t.tenant_id
                                      AND xi.express_tier_nama IS NOT NULL AND xi.express_tier_nama <> '') AS is_express,
                           (SELECT MAX(pl.created_at) FROM hl_proses_log pl
                             WHERE pl.transaksi_id=t.id AND pl.status_baru='antri_setrika') AS antri_at,
```

Ganti `AND t.status_proses IN ('masuk','cuci','kering','setrika','siap')` dengan `AND t.status_proses IN ('masuk','cuci','kering','antri_setrika','setrika','siap')`.

Setelah loop pengelompokan (`foreach ($rows as $r) {...}`) tambahkan:

```php
            $cols['antri_setrika'] = AntriSetrika::sort($cols['antri_setrika']);
```

Di `echo json_encode([...])` tambahkan `'antri_threshold' => AntriSetrika::THRESHOLD,`.

- [ ] **Step 3: Izinkan status di handler**

`$allowed = ['cuci','kering','setrika','siap','diambil'];` → `$allowed = ['cuci','kering','antri_setrika','setrika','siap','diambil'];`

Ubah komentar baris 4 menjadi `// 6 kolom status: masuk → cuci → kering → antri_setrika → setrika → siap`.

- [ ] **Step 4: CSS dan markup**

Ganti `.kb-wrap{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;padding:0 4px}` dengan `repeat(6,1fr)`.
Tambah setelah aturan `.kb-col.col-kering ...`:

```css
.kb-col.col-antri_setrika .kb-col-count{background:#D946EF}
.kb-col.col-antri_setrika.kb-warn .kb-col-count{background:#DC2626}
.kb-col.col-antri_setrika.kb-warn .kb-col-head{border-bottom-color:#DC2626}
.kb-exp{display:inline-block;margin-left:6px;font-size:11px;font-weight:800;color:#B45309;background:#FEF3C7;border-radius:6px;padding:1px 6px}
```

Sisipkan setelah baris kolom Kering di markup:

```html
    <div class="kb-col col-antri_setrika"><div class="kb-col-head"><span class="kb-col-title">⏳ Antri Setrika</span><span class="kb-col-count" id="cnt-antri_setrika">0</span></div><div id="col-antri_setrika"><div class="kb-empty">Memuat…</div></div></div>
```

- [ ] **Step 5: JS**

```js
const NEXT = {masuk:'cuci', cuci:'kering', kering:'antri_setrika', antri_setrika:'setrika', setrika:'siap', siap:'diambil'};
const LABEL_NEXT = {masuk:'→ Cuci', cuci:'→ Kering', kering:'→ Antri Setrika', antri_setrika:'→ Setrika', setrika:'→ Siap', siap:'✓ Ambil'};
const LABEL_STATUS = {cuci:'Cuci', kering:'Kering', antri_setrika:'Antri Setrika', setrika:'Setrika', siap:'Siap', diambil:'Diambil'};
```

Di `renderCard`, ganti baris `<div class="kb-no">${esc(r.no_order)}</div>` dengan:
`<div class="kb-no">${esc(r.no_order)}${Number(r.is_express) ? '<span class="kb-exp">⚡ EXPRESS</span>' : ''}</div>`

Di `loadKanban`, ganti daftar `['masuk','cuci','kering','setrika','siap']` dengan `['masuk','cuci','kering','antri_setrika','setrika','siap']`, dan setelah forEach tambahkan:

```js
    const antri = (d.columns.antri_setrika || []).length;
    document.querySelector('.kb-col.col-antri_setrika').classList.toggle('kb-warn', antri > (d.antri_threshold || 10));
```

Di `advanceStatus`, ganti `showToast('✅ Status → ' + nextStatus, 'success');` dengan `showToast('✅ Status → ' + (LABEL_STATUS[nextStatus] || nextStatus), 'success');`

- [ ] **Step 6: Verifikasi sintaks dan tampilan**

Run: `php -l kanban.php`. Lalu setelah Task 1 sudah dimigrasi: buka `kanban.php` di browser pane, pindahkan satu order Kering → Antri Setrika → Setrika, cek kolom baru muncul, tombol & toast berlabel benar, kartu express bertanda ⚡ dan di atas, header merah bila >10.
Expected: `No syntax errors`; perpindahan sukses; `SELECT status_baru, created_at FROM hl_proses_log ORDER BY id DESC LIMIT 2` menunjukkan `antri_setrika` dengan jam WIB benar.

- [ ] **Step 7: Commit**

```bash
git add kanban.php
git commit -m "feat(antri-setrika): kolom Antri Setrika di Kanban, express dulu lalu FIFO

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Label, warna, dan daftar status di halaman internal

**Files:**
- Modify: `orders.php` (±718, ±731, ±947, ±1066, ±1173, ±1403, ±2805, ±2808), `dashboard.php` (±1125, ±1153, ±1646, ±1917, ±2037), `laporan.php:1690`, `components.php` (±1884), `harpy-erp.css` (±304), `absensi.php` (±346), `core/AIChatData.php:74`

**Interfaces:**
- Consumes: nilai ENUM dari Task 1.

- [ ] **Step 1: orders.php**

1. Dua daftar `['masuk','cuci','kering','setrika','siap','diambil']` (±718 `$allowed`, ±947 `$statuses`) → sisipkan `'antri_setrika'` setelah `'kering'`.
2. ±731: `['cuci','kering','setrika','siap','diambil','selesai']` → `['cuci','kering','antri_setrika','setrika','siap','diambil','selesai']`.
3. ±1066 `$statusLabel` pesan WA: tambah `'antri_setrika'=>'Kering, Menunggu Disetrika',` setelah entri `kering`.
4. CSS ±1173: tambah `.b-antri_setrika{background:#FAE8FF;color:#86198F}` setelah `.b-kering`.
5. ±1403 filter: tambah `<option value="antri_setrika">Antri Setrika</option>` setelah opsi `kering`.
6. ±2805 `statusLabel`: tambah `'antri_setrika':'⏳ Antri Setrika',` setelah `'kering':...`.
7. ±2808 `prosesPercent`: tambah `'antri_setrika':60,` setelah `'kering':50,`.

- [ ] **Step 2: dashboard.php**

1. ±1125 dan ±1153: `IN ('cuci','kering','setrika')` → `IN ('cuci','kering','antri_setrika','setrika')`; ubah teks ±1138 menjadi `Status: cuci / kering / antri setrika / setrika`.
2. ±1646 `STATUS_PILL_BG`: tambah `antri_setrika:'#FAE8FF/#86198F',` setelah entri `kering`.
3. ±1917 `steps`: sisipkan `{key:'antri_setrika',label:'Antri Setrika'},` setelah `kering`.
4. ±2037 `statusLabel`: tambah `antri_setrika:'Antri Setrika',` setelah `kering:'Kering',`.

- [ ] **Step 3: laporan, components, css, absensi, AI**

- `laporan.php:1690`: tambah `'antri_setrika':'Antri Setrika',` setelah `'kering':'Kering',`.
- `components.php` `statusProsesBadge`: tambah `'antri_setrika' => ['⏳ Antri Setrika', 'antri_setrika'],` setelah `kering`.
- `harpy-erp.css` setelah `.hl-badge-kering`: `.hl-badge-antri_setrika { background: #FAE8FF; color: #86198F; }`.
- `absensi.php` ±346: `IN ('masuk','cuci','kering','setrika')` → `IN ('masuk','cuci','kering','antri_setrika','setrika')`.
- `core/AIChatData.php:74`: ENUM di teks → `ENUM('masuk','cuci','kering','antri_setrika','setrika','siap','diambil')`.

- [ ] **Step 4: Cari sisa daftar status yang terlewat**

Run: `grep -rn "'kering'" --include='*.php' . | grep -v "antri_setrika" | grep -v "superadmin/sql/seed\|core/MigrationImporter"`
Expected: hanya entri yang memang hanya menyebut kering sebagai satu elemen peta (mis. `NEXT`/label yang sudah ditangani); tidak ada daftar status lain yang belum memuat `antri_setrika`. Perbaiki yang tersisa.

- [ ] **Step 5: Lint**

Run: `for f in orders.php dashboard.php laporan.php components.php absensi.php core/AIChatData.php; do php -l $f; done`
Expected: `No syntax errors` untuk semuanya.

- [ ] **Step 6: Commit**

```bash
git add orders.php dashboard.php laporan.php components.php harpy-erp.css absensi.php core/AIChatData.php
git commit -m "feat(antri-setrika): label, warna, filter & daftar status di orders/dashboard/laporan

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Tampilan pelanggan (track.php, cek.php)

**Files:**
- Modify: `track.php` (±84-88, ±245, ±402), `cek.php` (±67, ±105-111, ±271)

**Interfaces:**
- Consumes: nilai `antri_setrika` dari DB.
- Produces: pelanggan melihat langkah Kering aktif dengan keterangan "menunggu disetrika".

- [ ] **Step 1: track.php**

Di blok `if ($order) { $st = ...` tambahkan baris pertama setelah `$st = ...`:
`if ($st === 'antri_setrika') $st = 'kering'; // istilah internal, pelanggan lihat langkah Kering`
Pada dua `in_array(... ['masuk','cuci','kering','setrika'], true)` (±245 dan ±402) tambahkan `'antri_setrika'` ke array.

- [ ] **Step 2: cek.php**

- ±67 `$progresMap`: tambah `'antri_setrika'=>60,`.
- `statusLabel`: tambah `'antri_setrika'=>'💨 Kering, menunggu disetrika',`.
- `statusColor`: tambah `'antri_setrika'=>'#F97316',`.
- ±271: setelah `$allSteps = [...]` ganti `array_search($order['status_proses'], $allSteps)` menjadi `array_search($order['status_proses'] === 'antri_setrika' ? 'kering' : $order['status_proses'], $allSteps)`.

- [ ] **Step 3: Verifikasi**

Run: `php -l track.php && php -l cek.php`. Lalu set satu order uji ke `antri_setrika` dan buka halaman tracking-nya di browser pane.
Expected: langkah Kering menyala, tidak ada teks `antri_setrika` di halaman (`grep` page text), countdown tetap tampil. Kembalikan order uji ke status semula.

- [ ] **Step 4: Commit**

```bash
git add track.php cek.php
git commit -m "feat(antri-setrika): pelanggan melihat Antri Setrika sebagai Kering (menunggu disetrika)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Rilis dan verifikasi akhir

**Files:** tidak ada perubahan kode.

- [ ] **Step 1: Pastikan migrasi sudah terpasang di produksi**

Run: `mysql -e "SHOW COLUMNS FROM hl_transaksi LIKE 'status_proses'"`
Expected: memuat `antri_setrika`. Jika belum, STOP dan jalankan Task 1 Step 4 lebih dulu.

- [ ] **Step 2: Push**

Run: `git push origin main`
Expected: exit 0.

- [ ] **Step 3: Cek produksi**

Buka `https://lamasy.harpy.id/kanban.php`, pastikan 6 kolom tampil tanpa error, pindahkan satu order uji Kering → Antri Setrika → Setrika, cek `hl_proses_log` ber-jam WIB benar, lalu kembalikan order uji. Pastikan `SELECT COUNT(*) FROM hl_transaksi WHERE status_proses=''` = 0.
Expected: semua sesuai; tidak ada status kosong.

# Production App `/produksi.php` — Design Spec

**Tanggal:** 2026-06-22
**Status:** Approved, ready for implementation plan
**Scope:** Mobile-first worker-facing app untuk update order per stage (cuci, kering, setrika, dll) dengan template input spesifik dan QR scanner.

## Tujuan

Beri karyawan (kasir/staff/produksi) interface dedicated untuk update order di setiap stage proses laundry. Setiap stage punya form input spesifik (mesin, durasi, foto, dll). Worker scan QR di struk untuk shortcut load order.

Mirip aplikasi produksi SmartLink, tapi terintegrasi langsung di LaMaSy (no separate install).

## Non-tujuan

- Tidak full PWA offline-capable (online-only MVP)
- Tidak push notification (skip, butuh service worker)
- Tidak assign-mode (admin assign worker per order) — pull-mode only
- Tidak dynamic field schema (hardcoded static, deploy ulang kalau ubah)
- Tidak label sticker print baru (gunakan QR existing di struk; label sticker = separate plan kalau insufficient)

## Pendekatan

Single PHP file `/produksi.php` mengikuti pola existing (pos.php, orders.php): action handler di top, UI inline, JS inline. Mobile-first CSS reuse existing `harpy-erp.css`. Scanner pakai html5-qrcode CDN. 1 tabel baru `hl_proses_input`. Reuse existing FileUpload, TenantQuery, hl_proses_log infrastructure.

## Komponen

### Page structure

```
/produksi.php
  ├─ <head> standard tenant page (sidebar layout)
  ├─ Top bar: judul "Produksi" + tombol [📷 Scan QR]
  ├─ Stage tab strip: [Terima] [Cuci] [Kering] [Setrika] [Siap] [Diambil]
  │   - badge angka count per stage
  │   - click tab → filter cards
  ├─ Card list (mobile-first, 1 column):
  │   ┌─────────────────────────────┐
  │   │ #ORD123 • Nama Pelanggan    │
  │   │ 3 item · 5kg · Masuk 2 jam  │
  │   │ [Action button stage aktif] │
  │   └─────────────────────────────┘
  ├─ Stage form modal (per-stage, see below)
  └─ Scanner modal (kamera fullscreen)
```

### Sidebar navigasi

Di [components.php](../../components.php) `renderSidebar()`, tambah item baru di section "Operasional":

```php
<a href="/produksi" class="side-link <?= ($activePage==='produksi'?'active':'') ?>">
  <span class="ico">🧺</span> Produksi
</a>
```

Visible kalau user punya permission `produksi.work` (atau role owner/manager/kasir).

### Stage forms (6 stages)

Semua mobile-first 1 kolom. Submit button full-width di bawah.

| # | Stage | Trigger transisi | Fields (data_json) | Foto | Catatan |
|---|---|---|---|---|---|
| 0 | Terima Cucian | none (dokumentasi) | `{}` | multi (max 3) | ya |
| 1 | Mulai Cuci | masuk → cuci | `{mesin_id, berat, program}` | tidak | opsional |
| 2 | Mulai Kering | cuci → kering | `{mesin_id, durasi, suhu}` | tidak | opsional |
| 3 | Mulai Setrika | kering → setrika | `{}` | opsional | opsional |
| 4 | Tandai Siap | setrika → siap | `{lokasi}` | opsional | tidak |
| 5 | Tandai Diambil | siap → diambil | `{signature_dataurl}` | wajib | opsional |

Detail field type:
- `mesin_id`: dropdown dari `hl_mesin` where `tenant_id`, `outlet_id`, `jenis` cocok, `status != 'maintenance'`
- `berat`: number step 0.1, min 0
- `program`: enum select [putih, berwarna, halus, jeans]
- `durasi`: number (menit), min 1
- `suhu`: enum select [rendah, sedang, tinggi]
- `lokasi`: text 50 char max
- `signature_dataurl`: hidden field diisi dari canvas via `toDataURL('image/png')`. **Backend** decode base64 → simpan file di `uploads/foto_proses/sig_<random>.png` → append path-nya ke `foto_paths` (jangan simpan dataURL ke DB — bulky).

### Data Model

**Tabel baru `hl_proses_input`:**

```sql
CREATE TABLE hl_proses_input (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  outlet_id INT NOT NULL,
  transaksi_id INT NOT NULL,
  stage VARCHAR(20) NOT NULL,
  karyawan_id INT NOT NULL,
  data_json JSON,
  foto_paths TEXT,
  catatan TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX (tenant_id, outlet_id, transaksi_id),
  INDEX (stage, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Migration baru: `master/migrations/2026-06-22-hl_proses_input.sql`. Setelah deploy, jalankan via mysql CLI.

**Tidak diubah:** `hl_transaksi`, `hl_proses_log`, `hl_users`, `hl_mesin`, file upload pattern.

### Backend endpoints (di `/produksi.php` action top)

| Action | Method | Purpose |
|---|---|---|
| `list` | GET | Return cards per stage. Query: `SELECT t.* FROM hl_transaksi WHERE tenant_id+outlet_id+status_proses=?` |
| `get_by_kode` | GET | Lookup `no_order` → order detail (untuk scanner) |
| `mesin_list` | GET | Dropdown mesin: `?jenis=cuci\|kering` |
| `save_stage` | POST | Submit form. CSRF required. |
| `upload_foto` | POST | Upload single foto, return path. CSRF required. Pakai `core/FileUpload.php`. |

**`save_stage` transaction logic:**

```php
verifyCsrf();
$d = json_decode(file_get_contents('php://input'), true);
$transaksiId = (int)$d['transaksi_id'];
$stage = $d['stage'];

$db->beginTransaction();
try {
  // Lock row
  $st = $db->prepare("SELECT status_proses FROM hl_transaksi WHERE id=? AND tenant_id=? AND outlet_id=? FOR UPDATE");
  $st->execute([$transaksiId, $tid, $oid]);
  $current = $st->fetchColumn();
  if (!$current) throw new Exception('Order tidak ditemukan');

  // Validate transition
  $expectedFrom = STAGE_FROM[$stage] ?? null; // map stage → expected current status
  if ($expectedFrom !== null && $current !== $expectedFrom) {
    throw new Exception('Order sudah diupdate worker lain, refresh halaman.');
  }

  // Insert input record
  TenantQuery::insert('hl_proses_input', [
    'transaksi_id' => $transaksiId,
    'stage'        => $stage,
    'karyawan_id'  => $userId,
    'data_json'    => json_encode($d['data'] ?? []),
    'foto_paths'   => implode(',', $d['foto'] ?? []),
    'catatan'      => $d['catatan'] ?? '',
  ]);

  // Update status (kecuali stage 'terima')
  $newStatus = STAGE_TO[$stage] ?? null;
  if ($newStatus !== null) {
    $upd = $db->prepare("UPDATE hl_transaksi SET status_proses=?, updated_at=NOW() WHERE id=? AND tenant_id=? AND outlet_id=?");
    $upd->execute([$newStatus, $transaksiId, $tid, $oid]);
    // proses_log triggers auto via existing app logic at orders.php — atau panggil internal helper
  }

  logAudit('proses_stage', 'transaksi', "id={$transaksiId} stage={$stage}");
  $db->commit();
  echo json_encode(['ok' => true]);
} catch (Throwable $e) {
  if ($db->inTransaction()) $db->rollBack();
  error_log('[produksi save_stage] ' . $e->getMessage());
  echo json_encode(['error' => $e->getMessage()]);
}
```

Constants:
```php
const STAGE_FROM = [
  'terima'   => null,        // no transition guard
  'cuci'     => 'masuk',
  'kering'   => 'cuci',
  'setrika'  => 'kering',
  'siap'     => 'setrika',
  'diambil'  => 'siap',
];
const STAGE_TO = [
  'terima'   => null,        // no status change
  'cuci'     => 'cuci',
  'kering'   => 'kering',
  'setrika'  => 'setrika',
  'siap'     => 'siap',
  'diambil'  => 'diambil',
];
```

### Scanner integration

```html
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"
        integrity="sha384-..."
        crossorigin="anonymous"></script>
```

**SRI requirement**: integrity hash WAJIB. Implementer lookup hash via:
```
curl -s https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js | \
  openssl dgst -sha384 -binary | openssl base64 -A
```
Atau host file lokal di `/assets/html5-qrcode.min.js` (alternative paling aman — tidak depend CDN). Recommend host lokal untuk MVP, hash lookup kalau tetap CDN.

JS implementasi:
```js
async function startScan() {
  document.getElementById('scanModal').classList.add('open');
  const qr = new Html5Qrcode("scanArea");
  await qr.start(
    {facingMode: "environment"},
    {fps: 10, qrbox: 250},
    async (decoded) => {
      await qr.stop();
      document.getElementById('scanModal').classList.remove('open');
      const m = decoded.match(/order=([A-Z0-9-]+)/i) || decoded.match(/^([A-Z0-9-]{3,})$/i);
      if (!m) { showToast('QR tidak dikenali', 'error'); return; }
      const r = await fetch('/produksi.php?action=get_by_kode&kode=' + encodeURIComponent(m[1]));
      const order = await r.json();
      if (order.error) { showToast(order.error, 'error'); return; }
      openStageModalFor(order);
    },
    () => {} // ignore scan errors silently
  );
}
```

Fallback: tombol "✏ Input No Order" → prompt → fetch sama.

### Permissions

- Tambah permission `produksi.work` di seed permissions (TenantProvisioner).
- Default role yang dapat: owner, manager, kasir, staff.
- Side menu link hidden via `hasPermission('produksi.work')` check.
- `/produksi.php` page guard: `requirePermission('produksi.work')` di atas.

### Concurrency

Sudah dibahas di `save_stage` logic: `FOR UPDATE` lock + status transition validation. Pattern sama dengan fix self.php booking.

### Error handling

| Skenario | Handling |
|---|---|
| Foto upload gagal | Toast error spesifik, form tetap open, foto removed dari state |
| Mesin sudah occupied | Warning toast "Mesin sedang dipakai sesi lain", allow submit |
| Network drop saat submit | Submit button reset, "Coba Lagi" muncul, form data preserved |
| Scanner kamera ditolak | Fallback manual input visible |
| Order status sudah berubah (race) | "Order sudah diupdate worker lain, refresh" — auto reload list |
| Order outlet lain | 404 (tenant + outlet scope) |
| Order sudah diambil/selesai | "Tidak ada stage tersisa untuk order ini" |

### Testing

Manual smoke test:
1. Login sebagai kasir, akses /produksi → halaman load, stage tab muncul
2. Buat order baru di /pos → muncul di tab "Masuk"
3. Tap order → modal Terima Cucian → upload foto + catatan → simpan → muncul di hl_proses_input
4. Tap order lagi → tombol "Mulai Cuci" → form mesin + program → submit → status berubah 'cuci', card pindah ke tab Cuci
5. Repeat stages: kering, setrika, siap, diambil
6. Stage 5: signature canvas berfungsi, foto kamera berfungsi
7. Scanner: scan QR struk valid → modal stage aktif terbuka
8. Race test: 2 browser submit stage sama bersamaan → 1 success, 1 error "diupdate worker lain"
9. Permission: login sebagai role tanpa `produksi.work` → side menu tidak muncul, akses langsung URL → 403

## Out of scope (deferred)

- Push notification ke worker saat order baru masuk
- PWA install + offline mode
- Assign mode (admin pilih worker per order)
- Dynamic field configuration per tenant
- Label sticker print baru (Plan B terpisah)
- Worker performance dashboard (stage duration analytics)
- Bulk action (process multiple orders sekaligus)

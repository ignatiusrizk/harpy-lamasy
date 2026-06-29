# Absensi Clock-in Hardening (Selfie + Geofence) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tambah aturan opsional per-outlet ke clock-in absensi: selfie wajib (toggle owner) + geofence strict (titik+radius via peta), ditegakkan server-side. Membangun di atas `absensi.php` yang sudah ada.

**Architecture:** Owner set config (toggle + titik via Leaflet map picker + radius) di `outlet-settings.php` → kolom `outlets`. Karyawan clock-in: frontend ambil GPS (kalau geofence) + selfie kamera (kalau wajib) → kirim ke `absensi.php?action=clock_in`. Server baca config dari `outlets`, hitung jarak haversine (`core/Geo.php`), tegakkan strict (blokir kalau GPS gagal / di luar radius / selfie kurang).

**Tech Stack:** PHP 8 / MariaDB. Leaflet (vendored `assets/vendor/`) + OSM tiles untuk map picker. `navigator.geolocation` + `<input capture=user>` kamera. `FileUpload::uploadImage`. Test: CLI + `tests/_assert.php` (haversine).

## Global Constraints

- Multi-tenant: config & query scope `tenant_id` (+ `outlet_id`); selfie upload prefix `t{tid}_o{oid}`.
- **Server-side enforcement** dari kolom `outlets` (bukan klaim client). Jarak via haversine server.
- Geofence **strict**: GPS kosong/ditolak ATAU jarak > radius → tolak. Hanya saat `absensi_geofence_aktif=1`.
- Selfie wajib hanya saat `absensi_selfie_wajib=1`; tak ada selfie → tolak.
- `selfie_path` validasi prefix `uploads/absensi_selfie/t{tid}_o{oid}` + no `..`. Render rekap pakai `esc()`.
- Config save: `verifyCsrf()` + perm yang dipakai `outlet-settings` existing. clock_in/upload_selfie: perm `absensi.clock` + `verifyCsrf()`.
- Default semua toggle 0 → clock-in backward-compatible (perilaku lama).
- Best-effort + ErrorLogger; tak crash. PHP CLI `/opt/homebrew/bin/php`; mysql `/opt/homebrew/opt/mysql-client/bin/mysql`; deploy `git push origin main`.

## Existing (jangan rebuild)

- `absensi.php` clock_in handler (~baris 20-52): cek sudah-clock-in, `TenantQuery::insert('hl_absensi', [...])`, terima `$d['lokasi']`. `clockIn()` JS (~876) POST body kosong. `updateClockUI` (~836). Tombol `#btnClockIn`.
- `outlet-settings.php` save (~45-70): `UPDATE outlets SET ... WHERE id=? AND tenant_id=?`.
- `FileUpload::uploadImage($file,$folder,$prefix): ['path'=>,'error'=>]`.
- `TenantQuery::rawOne(sql,params)`, `TenantResolver::id()`/`outletId()`.

## File Structure

- `migrations/2026-06-26-absensi-hardening.sql` (NEW) — ALTER outlets + hl_absensi.
- `core/Geo.php` (NEW) — haversine (pure).
- `tests/absensi/test_geo.php` (NEW) — unit haversine.
- `assets/vendor/leaflet.js` + `assets/vendor/leaflet.css` (NEW) — vendored.
- `outlet-settings.php` (MODIFY) — config section + map picker + `save_absensi`.
- `absensi.php` (MODIFY) — clock_in enforce + upload_selfie + config inject + clockIn frontend + rekap selfie icon.

---

### Task 1: Migration — kolom config + selfie

**Files:**
- Create: `migrations/2026-06-26-absensi-hardening.sql`

**Interfaces:**
- Produces: `outlets.absensi_selfie_wajib/absensi_geofence_aktif/absensi_lat/absensi_lng/absensi_radius_m`; `hl_absensi.selfie_masuk`.

- [ ] **Step 1: Tulis migration**

`migrations/2026-06-26-absensi-hardening.sql`:
```sql
ALTER TABLE outlets
  ADD COLUMN IF NOT EXISTS absensi_selfie_wajib   TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS absensi_geofence_aktif TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS absensi_lat            DECIMAL(10,7) NULL,
  ADD COLUMN IF NOT EXISTS absensi_lng            DECIMAL(10,7) NULL,
  ADD COLUMN IF NOT EXISTS absensi_radius_m       INT NOT NULL DEFAULT 100;

ALTER TABLE hl_absensi
  ADD COLUMN IF NOT EXISTS selfie_masuk VARCHAR(255) NULL AFTER lokasi_masuk;
```

- [ ] **Step 2: Terapkan + verifikasi**

Run: `/opt/homebrew/opt/mysql-client/bin/mysql < migrations/2026-06-26-absensi-hardening.sql`
Run: `/opt/homebrew/opt/mysql-client/bin/mysql -e "SHOW COLUMNS FROM outlets LIKE 'absensi%'; SHOW COLUMNS FROM hl_absensi LIKE 'selfie_masuk';"`
Expected: 5 kolom outlets + selfie_masuk muncul.

- [ ] **Step 3: Commit**

```bash
git add migrations/2026-06-26-absensi-hardening.sql
git commit -m "feat(absensi): kolom config geofence/selfie di outlets + hl_absensi.selfie_masuk"
```

---

### Task 2: `core/Geo.php` (haversine) + test

**Files:**
- Create: `core/Geo.php`
- Create: `tests/absensi/test_geo.php`

**Interfaces:**
- Produces: `Geo::haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float` — jarak meter.

- [ ] **Step 1: Tulis test (failing)**

`tests/absensi/test_geo.php`:
```php
<?php
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../core/Geo.php';

// titik sama → 0
eqv(round(Geo::haversineMeters(-6.2, 106.8, -6.2, 106.8)), 0.0, 'titik sama = 0 m');

// 1 derajat lintang ≈ 111.19 km (toleransi ±0.5km)
$d = Geo::haversineMeters(0.0, 0.0, 1.0, 0.0);
ok($d > 110690 && $d < 111690, '1° lintang ≈ 111km (got ' . round($d) . ')');

// ~100m: 0.0009° lintang ≈ 100m (toleransi ±10m)
$d2 = Geo::haversineMeters(-6.200000, 106.800000, -6.199100, 106.800000);
ok($d2 > 90 && $d2 < 110, '~0.0009° lintang ≈ 100m (got ' . round($d2,1) . ')');

// simetris
$a = Geo::haversineMeters(-6.21, 106.81, -6.20, 106.80);
$b = Geo::haversineMeters(-6.20, 106.80, -6.21, 106.81);
eqv(round($a,3), round($b,3), 'simetris');

echo "ALL OK\n";
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `/opt/homebrew/bin/php tests/absensi/test_geo.php`
Expected: FATAL "Class Geo not found".

- [ ] **Step 3: Implementasi `core/Geo.php`**

```php
<?php
// core/Geo.php — utilitas geospasial.
class Geo
{
    /** Jarak antar dua koordinat (derajat) dalam meter. Haversine, R=6371000m. */
    public static function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $R * $c;
    }
}
```

- [ ] **Step 4: Jalankan, pastikan lulus**

Run: `/opt/homebrew/bin/php tests/absensi/test_geo.php`
Expected: `PASS:` semua + `ALL OK`.

- [ ] **Step 5: Lint + commit**

```bash
/opt/homebrew/bin/php -l core/Geo.php
git add core/Geo.php tests/absensi/test_geo.php
git commit -m "feat(absensi): core/Geo haversine + test"
```

---

### Task 3: Outlet Settings — config section + Leaflet map picker

**Files:**
- Create: `assets/vendor/leaflet.js`, `assets/vendor/leaflet.css`
- Modify: `outlet-settings.php`

**Interfaces:**
- Consumes: kolom `outlets` absensi (Task 1).
- Produces: action `save_absensi` (POST) → UPDATE outlets config; UI map picker.

- [ ] **Step 1: Vendor Leaflet**

Run (unduh Leaflet 1.9.4 ke vendor lokal):
```bash
cd /Users/rizky/Documents/lamasy
curl -sL https://unpkg.com/leaflet@1.9.4/dist/leaflet.js  -o assets/vendor/leaflet.js
curl -sL https://unpkg.com/leaflet@1.9.4/dist/leaflet.css -o assets/vendor/leaflet.css
ls -la assets/vendor/leaflet.js assets/vendor/leaflet.css
```
Expected: dua file ter-download (leaflet.js ~140KB). Catatan: marker icon Leaflet default load dari CDN; kita pakai `L.circleMarker`/`L.circle` (vector, tanpa image) supaya tak butuh aset gambar eksternal.

- [ ] **Step 2: Backend — `save_absensi` action + sertakan config di `list`**

Di `outlet-settings.php`, tambah action (di area handler action lain):
```php
    if ($action === 'save_absensi' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d  = json_decode(file_get_contents('php://input'), true);
        $id = (int)($d['id'] ?? 0);
        $selfie   = !empty($d['selfie_wajib']) ? 1 : 0;
        $geofence = !empty($d['geofence_aktif']) ? 1 : 0;
        $lat = isset($d['lat']) && $d['lat'] !== '' ? (float)$d['lat'] : null;
        $lng = isset($d['lng']) && $d['lng'] !== '' ? (float)$d['lng'] : null;
        $radius = max(20, min(5000, (int)($d['radius_m'] ?? 100)));
        if ($geofence && ($lat === null || $lng === null)) {
            echo json_encode(['error'=>'Set titik lokasi outlet di peta dulu']); exit;
        }
        try {
            $st = $db->prepare("UPDATE outlets SET absensi_selfie_wajib=?, absensi_geofence_aktif=?, absensi_lat=?, absensi_lng=?, absensi_radius_m=? WHERE id=? AND tenant_id=?");
            $st->execute([$selfie, $geofence, $lat, $lng, $radius, $id, $tid]);
            logAudit('update', 'outlet', "Absensi config #$id: selfie=$selfie geofence=$geofence r=$radius");
            echo json_encode(['success'=>true]);
        } catch (Throwable $e) { echo json_encode(['error'=>'Gagal: '.$e->getMessage()]); }
        exit;
    }
```
Pastikan action `list` (atau yang me-render data outlet) mengembalikan kolom absensi. Cari SELECT outlet di `list` dan tambahkan kolom `absensi_selfie_wajib, absensi_geofence_aktif, absensi_lat, absensi_lng, absensi_radius_m` (kalau `SELECT *`, sudah otomatis).

- [ ] **Step 3: Frontend — section + map picker**

Tambah di markup `outlet-settings.php` (include Leaflet di head/atas):
```html
<link rel="stylesheet" href="/assets/vendor/leaflet.css">
<script src="/assets/vendor/leaflet.js"></script>
```
Section UI (sesuaikan dengan pola card/section yang ada di outlet-settings.php):
```html
<div class="card" style="margin-top:18px">
  <h3>📍 Absensi & Geofence</h3>
  <label style="display:flex;gap:8px;align-items:center;margin:10px 0">
    <input type="checkbox" id="abSelfie"> Wajib selfie saat clock-in
  </label>
  <label style="display:flex;gap:8px;align-items:center;margin:10px 0">
    <input type="checkbox" id="abGeofence" onchange="abToggleGeofence()"> Aktifkan geofence (batasi clock-in dalam radius)
  </label>
  <div id="abGeoBox" style="display:none">
    <div id="abMap" style="height:280px;border-radius:10px;margin:10px 0"></div>
    <button type="button" class="btn btn-teal-sm" onclick="abUseMyLocation()">📍 Pakai lokasi saya</button>
    <div style="display:flex;gap:10px;margin-top:10px;align-items:center">
      <span>Radius:</span>
      <input type="range" id="abRadius" min="20" max="1000" step="10" value="100" oninput="abRadiusChanged()">
      <span id="abRadiusLbl">100 m</span>
    </div>
    <div style="font-size:12px;color:var(--gray);margin-top:6px">Titik: <span id="abLatLng">—</span></div>
  </div>
  <button class="btn btn-green" style="margin-top:14px" onclick="saveAbsensiConfig()">💾 Simpan Absensi</button>
</div>
```
JS:
```js
let abMap=null, abMarker=null, abCircle=null, abLat=null, abLng=null;
function abInitMap(lat, lng, radius) {
  const c = [lat ?? -6.2, lng ?? 106.816]; // default Jakarta
  abMap = L.map('abMap').setView(c, lat ? 17 : 12);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(abMap);
  abCircle = L.circle(c, { radius: radius||100, color:'#35E8D5', fillOpacity:.15 }).addTo(abMap);
  abMarker = L.circleMarker(c, { radius:8, color:'#0F1C3A', fillColor:'#35E8D5', fillOpacity:1 }).addTo(abMap);
  if (lat) { abLat=lat; abLng=lng; abUpdateLbl(); }
  abMap.on('click', e => abSetPoint(e.latlng.lat, e.latlng.lng));
  setTimeout(()=>abMap.invalidateSize(), 200);
}
function abSetPoint(lat,lng){ abLat=lat; abLng=lng; const ll=[lat,lng]; abMarker.setLatLng(ll); abCircle.setLatLng(ll); abUpdateLbl(); }
function abUpdateLbl(){ document.getElementById('abLatLng').textContent = abLat.toFixed(6)+', '+abLng.toFixed(6); }
function abRadiusChanged(){ const r=+document.getElementById('abRadius').value; document.getElementById('abRadiusLbl').textContent=r+' m'; if(abCircle) abCircle.setRadius(r); }
function abToggleGeofence(){ const on=document.getElementById('abGeofence').checked; document.getElementById('abGeoBox').style.display=on?'block':'none'; if(on && !abMap) abInitMap(abLat,abLng,+document.getElementById('abRadius').value); else if(on) setTimeout(()=>abMap.invalidateSize(),200); }
function abUseMyLocation(){ if(!navigator.geolocation){showToast('GPS tak tersedia','error');return;} navigator.geolocation.getCurrentPosition(p=>{ abMap.setView([p.coords.latitude,p.coords.longitude],17); abSetPoint(p.coords.latitude,p.coords.longitude); },()=>showToast('Gagal ambil lokasi','error'),{enableHighAccuracy:true}); }
async function saveAbsensiConfig(){
  const body = {
    id: OUTLET_ID,
    selfie_wajib: document.getElementById('abSelfie').checked,
    geofence_aktif: document.getElementById('abGeofence').checked,
    lat: abLat, lng: abLng, radius_m: +document.getElementById('abRadius').value
  };
  const r = await fetch('outlet-settings.php?action=save_absensi', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) });
  const d = await r.json();
  if (d.success) showToast('Setting absensi tersimpan','success'); else showToast(d.error||'Gagal','error');
}
```
- Saat data outlet ter-load, inisialisasi state: centang `abSelfie`/`abGeofence` sesuai config, set `abRadius`, dan kalau geofence aktif panggil `abInitMap(absensi_lat, absensi_lng, absensi_radius_m)`.
> Implementer: konfirmasi nama var outlet id yang dipakai (`OUTLET_ID` / dari data list) + cara outlet-settings memuat data (fetch list vs render PHP) dan sambungkan pengisian state ke situ. Pakai `showToast` global. Jangan ganggu setting nota/antar yang sudah ada.

- [ ] **Step 4: Lint + commit**

Run: `/opt/homebrew/bin/php -l outlet-settings.php`
```bash
git add assets/vendor/leaflet.js assets/vendor/leaflet.css outlet-settings.php
git commit -m "feat(absensi): outlet settings — toggle selfie/geofence + Leaflet map picker + save_absensi"
```

---

### Task 4: absensi.php backend — enforce clock_in + upload_selfie

**Files:**
- Modify: `absensi.php`

**Interfaces:**
- Consumes: `Geo::haversineMeters` (Task 2), kolom config outlets (Task 1), `FileUpload`.
- Produces: action `upload_selfie`; `clock_in` menegakkan config + simpan `selfie_masuk`.

- [ ] **Step 1: require Geo + tambah action `upload_selfie`**

Pastikan `require_once ROOT . '/core/Geo.php';` ada di atas absensi.php (ikuti pola require existing). Tambah action (dekat clock_in):
```php
    if ($action === 'upload_selfie' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hasPermission('absensi.clock')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        require_once ROOT . '/core/FileUpload.php';
        $up = FileUpload::uploadImage($_FILES['foto'] ?? [], 'uploads/absensi_selfie', 't'.$tid.'_o'.$oid);
        if ($up['error']) { echo json_encode(['error'=>$up['error']]); exit; }
        echo json_encode(['path'=>$up['path']]); exit;
    }
```

- [ ] **Step 2: Perkuat handler `clock_in`**

Ganti isi handler `clock_in` (setelah cek sudah-clock-in, sebelum INSERT) supaya baca config & enforce. Final bentuk INSERT block:
```php
        // Baca config absensi outlet ini
        $cfg = TenantQuery::rawOne(
            "SELECT absensi_selfie_wajib, absensi_geofence_aktif, absensi_lat, absensi_lng, absensi_radius_m
               FROM outlets WHERE id=? AND tenant_id=? LIMIT 1",
            [$oid, $tid]
        ) ?: [];

        $lokasi = substr(trim(strip_tags($d['lokasi'] ?? '')), 0, 255) ?: null;

        // Geofence (strict)
        if (!empty($cfg['absensi_geofence_aktif'])) {
            $lat = isset($d['lat']) && $d['lat'] !== '' ? (float)$d['lat'] : null;
            $lng = isset($d['lng']) && $d['lng'] !== '' ? (float)$d['lng'] : null;
            if ($lat === null || $lng === null || ($cfg['absensi_lat'] === null || $cfg['absensi_lng'] === null)) {
                echo json_encode(['error' => 'Lokasi tak terdeteksi. Aktifkan izin lokasi untuk clock-in.']); exit;
            }
            $dist = Geo::haversineMeters($lat, $lng, (float)$cfg['absensi_lat'], (float)$cfg['absensi_lng']);
            if ($dist > (int)$cfg['absensi_radius_m']) {
                echo json_encode(['error' => 'Kamu di luar area outlet (' . round($dist) . ' m > ' . (int)$cfg['absensi_radius_m'] . ' m).']); exit;
            }
            $lokasi = round($lat, 7) . ',' . round($lng, 7);
        }

        // Selfie wajib
        $selfie = null;
        if (!empty($cfg['absensi_selfie_wajib'])) {
            $sp = trim((string)($d['selfie_path'] ?? ''));
            $pre = 'uploads/absensi_selfie/t' . $tid . '_o' . $oid;
            if ($sp === '' || strpos($sp, '..') !== false || strpos($sp, $pre) !== 0) {
                echo json_encode(['error' => 'Selfie wajib untuk clock-in.']); exit;
            }
            $selfie = substr($sp, 0, 255);
        }

        TenantQuery::insert('hl_absensi', [
            'user_id'      => $user['id'],
            'tanggal'      => $tgl,
            'jam_masuk'    => $jam,
            'lokasi_masuk' => $lokasi,
            'selfie_masuk' => $selfie,
            'status'       => 'hadir',
        ]);

        logAudit('clock_in', 'absensi', 'Tanggal: ' . $tgl);
        echo json_encode(['success' => true, 'jam' => substr($jam, 0, 5), 'tanggal' => $tgl]);
        exit;
```
(Hapus INSERT lama yang hanya pakai `lokasi_masuk`/tanpa enforce — diganti blok di atas.)

- [ ] **Step 3: Lint + commit**

Run: `/opt/homebrew/bin/php -l absensi.php`
```bash
git add absensi.php
git commit -m "feat(absensi): clock_in enforce geofence strict + selfie wajib + upload_selfie"
```

---

### Task 5: absensi.php frontend — config inject + clockIn GPS/kamera + rekap selfie

**Files:**
- Modify: `absensi.php`

**Interfaces:**
- Consumes: `/absensi?action=clock_in` (lat/lng/selfie_path) + `upload_selfie` (Task 4).

- [ ] **Step 1: Inject config outlet ke JS (server-render)**

Di bagian PHP `absensi.php` yang me-render halaman (setelah guard, sebelum/di dalam `<script>`), ambil config outlet & cetak:
```php
<?php
$abCfgRow = TenantQuery::rawOne("SELECT absensi_selfie_wajib, absensi_geofence_aktif, absensi_radius_m FROM outlets WHERE id=? AND tenant_id=? LIMIT 1", [TenantResolver::outletId(), TenantResolver::id()]) ?: [];
?>
<script>
window.ABSENSI_CFG = {
  selfie_wajib: <?= !empty($abCfgRow['absensi_selfie_wajib']) ? 'true' : 'false' ?>,
  geofence:     <?= !empty($abCfgRow['absensi_geofence_aktif']) ? 'true' : 'false' ?>,
  radius:       <?= (int)($abCfgRow['absensi_radius_m'] ?? 100) ?>
};
</script>
```
(lat/lng outlet TIDAK dikirim ke client — server yang validasi.)

- [ ] **Step 2: Hidden file input selfie + helper GPS/selfie**

Tambah dekat tombol clock-in:
```html
<input type="file" id="selfieFile" accept="image/*" capture="user" style="display:none">
```
JS helper:
```js
function getGPS() {
  return new Promise((res, rej) => {
    if (!navigator.geolocation) return rej(new Error('no-geo'));
    navigator.geolocation.getCurrentPosition(res, rej, { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });
  });
}
function captureSelfie() {
  return new Promise((resolve, reject) => {
    const inp = document.getElementById('selfieFile');
    inp.value = '';
    inp.onchange = async () => {
      const f = inp.files && inp.files[0];
      if (!f) return reject(new Error('no-file'));
      const fd = new FormData(); fd.append('foto', f);
      const r = await fetch('absensi.php?action=upload_selfie', { method:'POST', headers:{'X-CSRF-Token':csrfToken()}, body: fd });
      const d = await r.json();
      if (d.path) resolve(d.path); else reject(new Error(d.error || 'upload gagal'));
    };
    inp.click();
  });
}
```

- [ ] **Step 3: Perkuat `clockIn()`**

Ganti `clockIn()`:
```js
async function clockIn() {
  const cfg = window.ABSENSI_CFG || {};
  const btn = document.getElementById('btnClockIn');
  let lat = null, lng = null, selfiePath = null;

  if (cfg.geofence) {
    try { const pos = await getGPS(); lat = pos.coords.latitude; lng = pos.coords.longitude; }
    catch (e) { showToast('Aktifkan izin lokasi (GPS) untuk clock-in', 'error'); return; }
  }
  if (cfg.selfie_wajib) {
    try { showToast('📸 Ambil selfie…', 'info'); selfiePath = await captureSelfie(); }
    catch (e) { showToast('Selfie wajib untuk clock-in' + (e.message ? ': ' + e.message : ''), 'error'); return; }
  }

  btn.disabled = true; btn.textContent = '⏳...';
  const r = await fetch('absensi.php?action=clock_in', {
    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify({ lat: lat, lng: lng, selfie_path: selfiePath })
  });
  const d = await r.json();
  if (d.success) {
    showToast('✅ Clock In berhasil! Jam ' + d.jam, 'success');
    loadStatusHariIni(); loadKalender();
  } else {
    showToast('❌ ' + (d.error || 'Gagal'), 'error');
    btn.disabled = false;
  }
  btn.textContent = '▶ Clock In';
}
```
> Implementer: pastикан `loadKalender` benar nama fungsinya (cek; kalau beda, pakai yang ada). Pertahankan signature `clockOut()` apa adanya (tak diubah).

- [ ] **Step 4: Rekap — tampilkan ikon selfie**

Di render rekap (rekap_personal/rekap_all yang menampilkan baris absensi), kalau `selfie_masuk` ada tampilkan link ikon (escaped):
```js
${row.selfie_masuk ? `<a href="/${esc(row.selfie_masuk)}" target="_blank" title="Lihat selfie">🤳</a>` : ''}
```
> Implementer: cari fungsi render baris rekap yang ada, sisipkan kolom/ikon ini; `rekap_personal` query `SELECT a.*` → `selfie_masuk` sudah ada. Pakai `esc()` global.

- [ ] **Step 5: Lint + commit**

Run: `/opt/homebrew/bin/php -l absensi.php`
```bash
git add absensi.php
git commit -m "feat(absensi): frontend clock-in GPS+selfie sesuai config + ikon selfie di rekap"
```

---

## Self-Review

**1. Spec coverage:**
- Kolom config outlets + selfie_masuk → Task 1. ✅
- Haversine server-side → Task 2. ✅
- Owner UI toggle + map picker (Leaflet) + radius → Task 3. ✅
- Clock-in enforce (geofence strict + selfie wajib, server-side, baca config DB) → Task 4. ✅
- upload_selfie + path validation prefix → Task 4. ✅
- Frontend GPS + kamera per config + config inject → Task 5. ✅
- Rekap ikon selfie (esc) → Task 5. ✅
- Backward-compatible (toggle off) → Task 4 (config kosong → tak enforce). ✅
- Clock-in only (clock-out tak disentuh) → Task 4/5 (hanya clock_in/clockIn). ✅

**2. Placeholder scan:** Tak ada TBD/TODO. Task 3/5 minta implementer konfirmasi var outlet-id & nama fungsi render rekap/loadKalender (integration ke file existing) — diarahkan eksplisit + kode contoh, bukan placeholder.

**3. Type consistency:** Config keys (`absensi_selfie_wajib/geofence_aktif/lat/lng/radius_m`) konsisten Task 1↔3↔4↔5. `Geo::haversineMeters(lat1,lng1,lat2,lng2)` Task 2↔4. clock_in body `{lat,lng,selfie_path}` Task 4 (server) ↔ Task 5 (client). `selfie_masuk` kolom Task 1 ↔ insert Task 4 ↔ render Task 5. upload_selfie return `{path}` Task 4 ↔ captureSelfie Task 5. `ABSENSI_CFG{selfie_wajib,geofence,radius}` Task 5.

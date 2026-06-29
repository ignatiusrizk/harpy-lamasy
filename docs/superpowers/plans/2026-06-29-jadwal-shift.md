# Jadwal Shift Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Owner definisikan shift (configurable) + pola jadwal mingguan; clock-in/out hitung telat/lembur vs shift terjadwal (snapshot ke hl_absensi), tampil di rekap absensi.

**Architecture:** `hl_shift` (master, owner-configurable) + `hl_jadwal_shift` (pola mingguan karyawan×hari→shift). Clock-in cari jadwal hari ini → `ShiftCalc::hitungTelat` → snapshot shift_id+telat_menit di hl_absensi; clock-out → `hitungLembur` → lembur_menit. Tab "Jadwal" di absensi.php untuk kelola. Rekap tampilkan telat/lembur.

**Tech Stack:** PHP 8 / MariaDB. `absensi.php`, `core/ShiftCalc.php` (baru), tabel `hl_shift`+`hl_jadwal_shift`+kolom hl_absensi. Test CLI + `tests/_assert.php`.

## Global Constraints

- Multi-tenant: semua tabel & query scope `tenant_id` (+ `outlet_id`).
- Kelola shift/jadwal: perm `absensi.view` + `verifyCsrf()` di action mutasi.
- Telat/lembur **di-snapshot saat clock-in/out** ke hl_absensi (bukan recompute dari config).
- Karyawan tanpa jadwal hari itu → shift_id null, telat/lembur 0 (tanpa penalti). Backward-compat: tanpa jadwal = perilaku lama.
- Parameter (jam, toleransi_telat_menit, lembur_after_menit) configurable owner per shift. Shift lintas tengah malam (jam_selesai ≤ jam_mulai) DITOLAK saat simpan.
- Tak merusak laporan.php / BonusEvaluator (kolom additive).
- Best-effort + ErrorLogger. PHP CLI `/opt/homebrew/bin/php`; mysql `/opt/homebrew/opt/mysql-client/bin/mysql`; deploy `git push origin main`.

## Existing (integrate, jangan rebuild)

- `absensi.php`: tab via `.hl-tab` + JS switch; `clock_in` handler (Spec A: enforce geofence/selfie, then `TenantQuery::insert('hl_absensi',[...])`); `clock_out` (UPDATE jam_keluar/durasi/lokasi_keluar WHERE id=?); `rekap_personal` returns `SELECT a.*,u.nama`; `loadKalender()` JS renders calendar from rekap data (selfie icon added Spec A); `list_users` returns outlet karyawan `{id,nama,role}`. `$tid`/`$oid`/`$user` in scope in action handlers. `TenantQuery::raw/rawOne/insert/update`, `verifyCsrf`, `hasPermission`, `esc`, `showToast`.

## File Structure

- `migrations/2026-06-29-jadwal-shift.sql` (NEW) — 2 tabel + 3 kolom hl_absensi.
- `core/ShiftCalc.php` (NEW) — hitungTelat/hitungLembur (pure).
- `tests/absensi/test_shiftcalc.php` (NEW).
- `absensi.php` (MODIFY) — backend CRUD (T3) + clock integration (T4) + frontend tab & rekap (T5).

---

### Task 1: Migration

**Files:** Create `migrations/2026-06-29-jadwal-shift.sql`
**Interfaces:** Produces tables `hl_shift`, `hl_jadwal_shift`; columns `hl_absensi.shift_id/telat_menit/lembur_menit`.

- [ ] **Step 1: Tulis migration**
```sql
CREATE TABLE IF NOT EXISTS hl_shift (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL, outlet_id INT NOT NULL,
  nama VARCHAR(50) NOT NULL,
  jam_mulai TIME NOT NULL, jam_selesai TIME NOT NULL,
  toleransi_telat_menit INT NOT NULL DEFAULT 15,
  lembur_after_menit INT NOT NULL DEFAULT 30,
  is_active TINYINT(1) NOT NULL DEFAULT 1, urutan INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_outlet (tenant_id, outlet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hl_jadwal_shift (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL, outlet_id INT NOT NULL,
  user_id INT NOT NULL, hari TINYINT NOT NULL, shift_id INT NOT NULL,
  UNIQUE KEY uq_user_hari (tenant_id, outlet_id, user_id, hari),
  KEY idx_outlet (tenant_id, outlet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE hl_absensi
  ADD COLUMN IF NOT EXISTS shift_id INT NULL AFTER status,
  ADD COLUMN IF NOT EXISTS telat_menit INT NOT NULL DEFAULT 0 AFTER shift_id,
  ADD COLUMN IF NOT EXISTS lembur_menit INT NOT NULL DEFAULT 0 AFTER telat_menit;
```

- [ ] **Step 2: Terapkan + verifikasi**
Run: `/opt/homebrew/opt/mysql-client/bin/mysql < migrations/2026-06-29-jadwal-shift.sql`
Run: `/opt/homebrew/opt/mysql-client/bin/mysql -e "SHOW TABLES LIKE 'hl_shift'; SHOW TABLES LIKE 'hl_jadwal_shift'; SHOW COLUMNS FROM hl_absensi LIKE 'telat_menit';"`
Expected: 2 tabel + telat_menit muncul.

- [ ] **Step 3: Commit**
```bash
git add migrations/2026-06-29-jadwal-shift.sql
git commit -m "feat(shift): tabel hl_shift + hl_jadwal_shift + kolom telat/lembur di hl_absensi"
```

---

### Task 2: `core/ShiftCalc.php` + test

**Files:** Create `core/ShiftCalc.php`, `tests/absensi/test_shiftcalc.php`
**Interfaces:** Produces `ShiftCalc::hitungTelat(string $jamMasuk, string $jamMulai, int $toleransiMenit): int` + `ShiftCalc::hitungLembur(string $jamKeluar, string $jamSelesai, int $lemburAfterMenit): int` (menit).

- [ ] **Step 1: Tulis test (failing)**
`tests/absensi/test_shiftcalc.php`:
```php
<?php
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../core/ShiftCalc.php';

// TELAT
eqv(ShiftCalc::hitungTelat('08:00:00','08:00:00',15), 0, 'tepat jam mulai → 0');
eqv(ShiftCalc::hitungTelat('08:10:00','08:00:00',15), 0, 'dalam toleransi (+10, tol 15) → 0');
eqv(ShiftCalc::hitungTelat('08:20:00','08:00:00',15), 5, 'lewat toleransi (+20, tol 15) → 5');
eqv(ShiftCalc::hitungTelat('07:50:00','08:00:00',15), 0, 'datang lebih awal → 0');
eqv(ShiftCalc::hitungTelat('08:46:00','08:00:00',15), 31, '+46 tol15 → 31');

// LEMBUR
eqv(ShiftCalc::hitungLembur('16:00:00','16:00:00',30), 0, 'pulang tepat → 0');
eqv(ShiftCalc::hitungLembur('16:20:00','16:00:00',30), 0, 'dalam ambang (+20, after 30) → 0');
eqv(ShiftCalc::hitungLembur('16:45:00','16:00:00',30), 45, 'lewat ambang (+45) → 45');
eqv(ShiftCalc::hitungLembur('15:30:00','16:00:00',30), 0, 'pulang awal → 0');

echo "ALL OK\n";
```

- [ ] **Step 2: Jalankan, pastikan gagal**
Run: `/opt/homebrew/bin/php tests/absensi/test_shiftcalc.php` → FATAL "Class ShiftCalc not found".

- [ ] **Step 3: Implementasi `core/ShiftCalc.php`**
```php
<?php
// core/ShiftCalc.php — hitung telat & lembur vs jam shift (menit). Asumsi shift dalam 1 hari.
class ShiftCalc
{
    /** Menit telat: clock-in lewat (jam mulai + toleransi). 0 kalau tepat/dalam toleransi/lebih awal. */
    public static function hitungTelat(string $jamMasuk, string $jamMulai, int $toleransiMenit): int
    {
        $selisih = strtotime($jamMasuk) - strtotime($jamMulai) - max(0, $toleransiMenit) * 60;
        return $selisih <= 0 ? 0 : (int)ceil($selisih / 60);
    }

    /** Menit lembur: clock-out lewat jam selesai, hanya kalau overshoot >= ambang. */
    public static function hitungLembur(string $jamKeluar, string $jamSelesai, int $lemburAfterMenit): int
    {
        $overshoot = strtotime($jamKeluar) - strtotime($jamSelesai);
        return ($overshoot >= max(0, $lemburAfterMenit) * 60) ? (int)floor($overshoot / 60) : 0;
    }
}
```

- [ ] **Step 4: Jalankan, pastikan lulus**
Run: `/opt/homebrew/bin/php tests/absensi/test_shiftcalc.php` → `ALL OK`.

- [ ] **Step 5: Lint + commit**
```bash
/opt/homebrew/bin/php -l core/ShiftCalc.php
git add core/ShiftCalc.php tests/absensi/test_shiftcalc.php
git commit -m "feat(shift): ShiftCalc hitung telat/lembur + test"
```

---

### Task 3: Backend CRUD shift + jadwal (absensi.php)

**Files:** Modify `absensi.php`
**Interfaces:** Produces actions `shift_list`, `shift_save`, `shift_delete`, `shift_seed_template`, `jadwal_get`, `jadwal_save`.

- [ ] **Step 1: Tambah action shift CRUD**
Di area handler action `absensi.php` (`$tid`/`$oid` sudah di-set), tambahkan:
```php
    if ($action === 'shift_list') {
        if (!hasPermission('absensi.view')) { echo json_encode([]); exit; }
        echo json_encode(TenantQuery::raw(
            "SELECT * FROM hl_shift WHERE tenant_id=? AND outlet_id=? ORDER BY urutan, jam_mulai", [$tid,$oid]
        )); exit;
    }
    if ($action === 'shift_save' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('absensi.view')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $nama = substr(trim(strip_tags($d['nama'] ?? '')), 0, 50);
        $jm = $d['jam_mulai'] ?? ''; $js = $d['jam_selesai'] ?? '';
        if (!$nama || !preg_match('/^\d{2}:\d{2}/', $jm) || !preg_match('/^\d{2}:\d{2}/', $js)) { echo json_encode(['error'=>'Nama & jam wajib (HH:MM)']); exit; }
        if (strtotime($js) <= strtotime($jm)) { echo json_encode(['error'=>'Jam selesai harus setelah jam mulai (shift lintas malam belum didukung)']); exit; }
        $data = [
            'nama'=>$nama,
            'jam_mulai'=>substr($jm,0,8) ?: $jm.':00',
            'jam_selesai'=>substr($js,0,8) ?: $js.':00',
            'toleransi_telat_menit'=>max(0,(int)($d['toleransi_telat_menit'] ?? 15)),
            'lembur_after_menit'=>max(0,(int)($d['lembur_after_menit'] ?? 30)),
            'is_active'=>!empty($d['is_active'])?1:1,
            'urutan'=>(int)($d['urutan'] ?? 0),
        ];
        if (!empty($d['id'])) TenantQuery::update('hl_shift', $data, 'id=?', [(int)$d['id']]);
        else TenantQuery::insert('hl_shift', $data);
        echo json_encode(['success'=>true]); exit;
    }
    if ($action === 'shift_delete' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('absensi.view')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $sid = (int)($d['id'] ?? 0);
        $used = TenantQuery::rawOne("SELECT COUNT(*) c FROM hl_jadwal_shift WHERE tenant_id=? AND outlet_id=? AND shift_id=?", [$tid,$oid,$sid]);
        if (($used['c'] ?? 0) > 0) { echo json_encode(['error'=>'Shift masih dipakai di jadwal. Hapus dari jadwal dulu.']); exit; }
        TenantQuery::delete('hl_shift', 'id=?', [$sid]);
        echo json_encode(['success'=>true]); exit;
    }
    if ($action === 'shift_seed_template' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('absensi.view')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $ada = TenantQuery::rawOne("SELECT COUNT(*) c FROM hl_shift WHERE tenant_id=? AND outlet_id=?", [$tid,$oid]);
        if (($ada['c'] ?? 0) > 0) { echo json_encode(['error'=>'Sudah ada shift, template tidak dibuat']); exit; }
        foreach ([['Pagi','08:00:00','16:00:00',1],['Sore','14:00:00','22:00:00',2],['Full','08:00:00','20:00:00',3]] as $t) {
            TenantQuery::insert('hl_shift', ['nama'=>$t[0],'jam_mulai'=>$t[1],'jam_selesai'=>$t[2],'toleransi_telat_menit'=>15,'lembur_after_menit'=>30,'is_active'=>1,'urutan'=>$t[3]]);
        }
        echo json_encode(['success'=>true]); exit;
    }
```
> Implementer: konfirmasi `TenantQuery::insert/update/delete` auto-inject tenant_id+outlet_id (dipakai begitu di file lain). Kalau `insert` tak auto-set outlet_id, tambahkan 'outlet_id'=>$oid eksplisit ke $data.

- [ ] **Step 2: Tambah action jadwal**
```php
    if ($action === 'jadwal_get') {
        if (!hasPermission('absensi.view')) { echo json_encode([]); exit; }
        echo json_encode(TenantQuery::raw(
            "SELECT user_id, hari, shift_id FROM hl_jadwal_shift WHERE tenant_id=? AND outlet_id=?", [$tid,$oid]
        )); exit;
    }
    if ($action === 'jadwal_save' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!hasPermission('absensi.view')) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $uid = (int)($d['user_id'] ?? 0); $hari = (int)($d['hari'] ?? 0); $sid = (int)($d['shift_id'] ?? 0);
        if ($uid < 1 || $hari < 1 || $hari > 7) { echo json_encode(['error'=>'Data tidak valid']); exit; }
        if ($sid < 1) {
            // libur → hapus baris
            TenantQuery::delete('hl_jadwal_shift', 'user_id=? AND hari=?', [$uid,$hari]);
        } else {
            // upsert (UNIQUE user+hari) — hapus lalu insert (sederhana & tenant-scoped)
            TenantQuery::delete('hl_jadwal_shift', 'user_id=? AND hari=?', [$uid,$hari]);
            TenantQuery::insert('hl_jadwal_shift', ['user_id'=>$uid,'hari'=>$hari,'shift_id'=>$sid]);
        }
        echo json_encode(['success'=>true]); exit;
    }
```
> Implementer: pastikan `TenantQuery::delete` & `insert` tenant+outlet-scoped. Validasi `shift_id` milik outlet ini (opsional tapi aman: cek shift ada di hl_shift outlet sebelum insert) — tambahkan kalau mudah.

- [ ] **Step 3: Lint + commit**
Run: `/opt/homebrew/bin/php -l absensi.php`
```bash
git add absensi.php
git commit -m "feat(shift): backend CRUD shift + jadwal mingguan di absensi.php"
```

---

### Task 4: Integrasi clock-in/out (snapshot telat/lembur)

**Files:** Modify `absensi.php`
**Interfaces:** Consumes `ShiftCalc` (Task 2), `hl_jadwal_shift`/`hl_shift` (Task 1).

- [ ] **Step 1: require ShiftCalc**
Pastikan `require_once ROOT . '/core/ShiftCalc.php';` di atas absensi.php (dekat require Geo.php yang sudah ada).

- [ ] **Step 2: clock_in — snapshot shift_id + telat_menit**
Di handler `clock_in` (setelah blok geofence/selfie Spec A, sebelum `TenantQuery::insert('hl_absensi',...)`), tambahkan lookup + hitung, dan masukkan ke array insert:
```php
        // Jadwal shift hari ini → telat
        $shiftId = null; $telatMenit = 0;
        $hari = (int)date('N'); // 1=Senin..7=Minggu
        $jd = TenantQuery::rawOne(
            "SELECT s.id, s.jam_mulai, s.toleransi_telat_menit
               FROM hl_jadwal_shift j JOIN hl_shift s ON s.id=j.shift_id AND s.tenant_id=j.tenant_id
              WHERE j.tenant_id=? AND j.outlet_id=? AND j.user_id=? AND j.hari=? LIMIT 1",
            [$tid, $oid, $user['id'], $hari]
        );
        if ($jd) {
            $shiftId = (int)$jd['id'];
            $telatMenit = ShiftCalc::hitungTelat($jam, $jd['jam_mulai'], (int)$jd['toleransi_telat_menit']);
        }
```
Lalu pada array `TenantQuery::insert('hl_absensi', [...])` tambahkan:
```php
            'shift_id'     => $shiftId,
            'telat_menit'  => $telatMenit,
```
(jangan ubah field lain dari Spec A: lokasi_masuk, selfie_masuk, dst tetap.)

- [ ] **Step 3: clock_out — snapshot lembur_menit**
Di handler `clock_out`, setelah ambil `$row` (absensi hari ini) dan sebelum/saat `TenantQuery::update('hl_absensi', [...])`, hitung lembur kalau ada shift:
```php
        $lemburMenit = 0;
        if (!empty($row['shift_id'])) {
            $sh = TenantQuery::rawOne("SELECT jam_selesai, lembur_after_menit FROM hl_shift WHERE id=? AND tenant_id=? LIMIT 1", [(int)$row['shift_id'], $tid]);
            if ($sh) $lemburMenit = ShiftCalc::hitungLembur($jam, $sh['jam_selesai'], (int)$sh['lembur_after_menit']);
        }
```
Tambahkan `'lembur_menit' => $lemburMenit,` ke array update jam_keluar/durasi (jangan hapus field existing).

- [ ] **Step 4: Lint + commit**
Run: `/opt/homebrew/bin/php -l absensi.php`
```bash
git add absensi.php
git commit -m "feat(shift): clock-in/out snapshot telat/lembur vs jadwal"
```

---

### Task 5: Frontend — tab "Jadwal" (kelola shift + grid mingguan) + rekap telat/lembur

**Files:** Modify `absensi.php`
**Interfaces:** Consumes actions shift_*/jadwal_* (T3), `list_users`, kolom telat/lembur di rekap (T1+T4).

- [ ] **Step 1: Tambah tab "Jadwal" (hanya manajer/owner)**
Di markup tabs (`.hl-tabs`), tambah tombol tab "📅 Jadwal" + panel (tampil kalau `hasPermission('absensi.view')`). Ikuti pola tab existing (cek bagaimana tab lain di-switch — fungsi switch + `.hl-tab.active` + panel show/hide). Panel berisi dua kartu: "Kelola Shift" + "Jadwal Mingguan".

- [ ] **Step 2: JS — Kelola Shift (CRUD)**
```js
async function loadShifts() {
  const r = await fetch('absensi.php?action=shift_list'); const list = await r.json();
  const box = document.getElementById('shiftList');
  if (!list.length) { box.innerHTML = '<p style="color:var(--gray)">Belum ada shift. <button class="hl-btn hl-btn-teal-sm" onclick="seedTemplate()">Buat template (Pagi/Sore/Full)</button></p>'; window._shifts=[]; return; }
  window._shifts = list;
  box.innerHTML = list.map(s => `<div style="display:flex;justify-content:space-between;align-items:center;padding:8px;border-bottom:1px solid var(--light)">
    <span><b>${esc(s.nama)}</b> ${s.jam_mulai.substring(0,5)}–${s.jam_selesai.substring(0,5)} · tol ${s.toleransi_telat_menit}m · lembur >${s.lembur_after_menit}m</span>
    <span><button class="hl-btn hl-btn-outline hl-btn-sm" onclick='editShift(${JSON.stringify(s)})'>Edit</button>
    <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="deleteShift(${s.id})">Hapus</button></span></div>`).join('');
  renderJadwalGrid();
}
async function seedTemplate(){ const r=await fetch('absensi.php?action=shift_seed_template',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},body:'{}'}); const d=await r.json(); if(d.success){showToast('Template dibuat','success');loadShifts();}else showToast(d.error||'Gagal','error'); }
function editShift(s){ /* isi form modal shift dari s (atau form inline) */ openShiftForm(s); }
function openShiftForm(s){ s=s||{}; document.getElementById('sf_id').value=s.id||''; document.getElementById('sf_nama').value=s.nama||''; document.getElementById('sf_mulai').value=(s.jam_mulai||'').substring(0,5); document.getElementById('sf_selesai').value=(s.jam_selesai||'').substring(0,5); document.getElementById('sf_tol').value=s.toleransi_telat_menit??15; document.getElementById('sf_lembur').value=s.lembur_after_menit??30; document.getElementById('shiftFormModal').style.display='flex'; }
async function saveShift(){
  const body={ id:document.getElementById('sf_id').value||null, nama:document.getElementById('sf_nama').value, jam_mulai:document.getElementById('sf_mulai').value, jam_selesai:document.getElementById('sf_selesai').value, toleransi_telat_menit:+document.getElementById('sf_tol').value, lembur_after_menit:+document.getElementById('sf_lembur').value };
  const r=await fetch('absensi.php?action=shift_save',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},body:JSON.stringify(body)});
  const d=await r.json(); if(d.success){showToast('Shift tersimpan','success');document.getElementById('shiftFormModal').style.display='none';loadShifts();}else showToast(d.error||'Gagal','error');
}
async function deleteShift(id){ if(!confirm('Hapus shift ini?'))return; const r=await fetch('absensi.php?action=shift_delete',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},body:JSON.stringify({id})}); const d=await r.json(); if(d.success){showToast('Dihapus','success');loadShifts();}else showToast(d.error||'Gagal','error'); }
```
Tambah markup form modal shift (`shiftFormModal`) dengan field `sf_id`(hidden)/sf_nama/sf_mulai(time)/sf_selesai(time)/sf_tol(number)/sf_lembur(number) + tombol Simpan (`saveShift()`).

- [ ] **Step 3: JS — Grid Jadwal Mingguan**
```js
const HARI = {1:'Sen',2:'Sel',3:'Rab',4:'Kam',5:'Jum',6:'Sab',7:'Min'};
async function renderJadwalGrid(){
  const [uRes, jRes] = await Promise.all([fetch('absensi.php?action=list_users'), fetch('absensi.php?action=jadwal_get')]);
  const users = await uRes.json(); const jad = await jRes.json();
  const map = {}; jad.forEach(j => map[j.user_id+'_'+j.hari] = j.shift_id);
  const shifts = window._shifts || [];
  const opts = sel => '<option value="0">Libur</option>' + shifts.map(s=>`<option value="${s.id}" ${s.id==sel?'selected':''}>${esc(s.nama)}</option>`).join('');
  let html = '<table class="hl-table"><thead><tr><th>Karyawan</th>' + [1,2,3,4,5,6,7].map(h=>`<th>${HARI[h]}</th>`).join('') + '</tr></thead><tbody>';
  html += users.map(u => `<tr><td>${esc(u.nama)}</td>` + [1,2,3,4,5,6,7].map(h=>`<td><select onchange="saveJadwal(${u.id},${h},this.value)">${opts(map[u.id+'_'+h]||0)}</select></td>`).join('') + '</tr>').join('');
  html += '</tbody></table>';
  document.getElementById('jadwalGrid').innerHTML = users.length ? html : '<p style="color:var(--gray)">Belum ada karyawan di outlet ini.</p>';
}
async function saveJadwal(uid,hari,shiftId){
  const r=await fetch('absensi.php?action=jadwal_save',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},body:JSON.stringify({user_id:uid,hari:hari,shift_id:+shiftId})});
  const d=await r.json(); if(d.success) showToast('Jadwal disimpan','success'); else showToast(d.error||'Gagal','error');
}
```
Markup panel: `<div id="shiftList"></div>` + tombol "Tambah Shift" (`onclick="openShiftForm()"`) + `<div id="jadwalGrid"></div>`. Panggil `loadShifts()` saat tab Jadwal dibuka.

- [ ] **Step 4: Rekap — tampilkan telat/lembur**
Di `loadKalender()` (render kalender rekap_personal), tiap hari yang ada absensi tampilkan badge telat (kalau `telat_menit>0`) + lembur (kalau `lembur_menit>0`). Bangun map dari `data` seperti `selfieMap` (Spec A): `telatMap[tgl]=row.telat_menit`, `lemburMap[tgl]=row.lembur_menit`. Di sel kalender:
```js
${telat>0?`<span style="background:#FEF3C7;color:#92400E;font-size:9px;padding:1px 4px;border-radius:6px">telat ${telat}m</span>`:''}
${lembur>0?`<span style="background:#DBEAFE;color:#1E40AF;font-size:9px;padding:1px 4px;border-radius:6px">+${lembur}m</span>`:''}
```
Ringkasan bulan: tambahkan total telat (menit) + total lembur (menit) ke panel summary rekap_personal (jumlahkan dari `data`).
> Implementer: ikuti cara `selfieMap` di-build & ditampilkan di `loadKalender` (pola Spec A) untuk konsistensi. rekap_personal sudah `SELECT a.*` → telat_menit/lembur_menit tersedia.

- [ ] **Step 5: Lint + commit**
Run: `/opt/homebrew/bin/php -l absensi.php`
```bash
git add absensi.php
git commit -m "feat(shift): tab Jadwal (kelola shift + grid mingguan) + telat/lembur di rekap"
```

---

## Self-Review

**1. Spec coverage:**
- hl_shift + hl_jadwal_shift + kolom hl_absensi → Task 1. ✅
- ShiftCalc hitung telat/lembur (toleransi/ambang) → Task 2 + test. ✅
- Owner kelola shift (CRUD, configurable, tolak lintas-malam, template seed on-demand) → Task 3. ✅
- Jadwal mingguan (karyawan×hari, libur=hapus) → Task 3 + grid Task 5. ✅
- Snapshot telat saat clock-in, lembur saat clock-out → Task 4. ✅
- Tab Jadwal UI + rekap telat/lembur → Task 5. ✅
- Backward-compat (tanpa jadwal → 0, tak rusak laporan/BonusEvaluator) → Task 4 (lookup null → 0) + additive cols Task 1. ✅
- Multi-tenant scope + CSRF + perm absensi.view → Task 3 (semua action). ✅

**2. Placeholder scan:** Tak ada TBD/TODO. Task 3/5 minta implementer konfirmasi TenantQuery auto-scope outlet_id + pola tab/switch & selfieMap existing (integration ke file) — diarahkan eksplisit + kode contoh, bukan placeholder.

**3. Type consistency:** `ShiftCalc::hitungTelat(jamMasuk,jamMulai,toleransi)` & `hitungLembur(jamKeluar,jamSelesai,lemburAfter)` konsisten Task 2↔4. Kolom `shift_id/telat_menit/lembur_menit` konsisten Task 1↔4↔5. Actions shift_list/shift_save/shift_delete/shift_seed_template/jadwal_get/jadwal_save konsisten Task 3 (emit) ↔ Task 5 (fetch). `hari` 1–7 (date('N')) konsisten Task 1/3/4/5. Grid pakai `list_users` (existing).

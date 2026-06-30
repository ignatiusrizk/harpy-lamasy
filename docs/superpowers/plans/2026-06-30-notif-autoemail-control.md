# Notif Auto-Email Kontrol Per-Channel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Owner bisa atur Laporan Harian & Alert Anomali per-channel (email/in-app) dari owner_report.php, dan setelan itu benar-benar dihormati engine (saat ini disable diabaikan).

**Architecture:** Helper pure `core/NotifPrefs.php` (resolusi channel dari `tenants.notif_settings`) dipakai DailyReport + AnomalyDetector → satu sumber kebenaran. Owner UI di owner_report.php menulis notif_settings secara merge.

**Tech Stack:** PHP 8 / MariaDB. Test: CLI + `tests/_assert.php`. Tak ada migration (kolom `tenants.notif_settings` sudah ada).

## Global Constraints

- Config di `tenants.notif_settings` (JSON). Skema: `daily_report:{email,inapp}`, `alert_anomali:{email,inapp}`, `daily_report_jam`, `daily_report_konten`.
- **Default channel = ON (1)** kalau key/notif_settings absen/NULL/invalid (backward-compat).
- Dua channel suatu kategori off → kategori senyap total (email & in-app).
- `save_prefs` = **merge** (tak hapus `coin_low`/`trial_ending`/key HQ lain). Gate `isAdminLevel()` + `verifyCsrf()`.
- WA dikecualikan. Tombol manual "Kirim Sekarang" (`send_now`) **tak diubah** (aksi eksplisit, tetap kirim email+inapp).
- Multi-tenant: notif_settings per-tenant. PHP CLI `/opt/homebrew/bin/php`. Deploy `git push origin main`.

## File Structure

- `core/NotifPrefs.php` (NEW) — `channelsFor()` pure + `read()` thin DB.
- `tests/notif/test_notifprefs.php` (NEW) — unit channelsFor.
- `core/DailyReport.php` (MODIFY) — readConfig + maybeSend pakai NotifPrefs.
- `core/AnomalyDetector.php` (MODIFY) — check + 5 cek pass channels; buang isEnabled.
- `owner_report.php` (MODIFY) — panel + get_prefs/save_prefs.

---

### Task 1: `core/NotifPrefs.php` + test

**Files:** Create `core/NotifPrefs.php`, `tests/notif/test_notifprefs.php`
**Interfaces:**
- Produces `NotifPrefs::channelsFor(array $cfg, string $kategori): array` (subset `['email','inapp']`, default keduanya).
- Produces `NotifPrefs::read(int $tenantId): array` (decoded notif_settings atau []).

- [ ] **Step 1: Tulis test (failing)** — `tests/notif/test_notifprefs.php`:
```php
<?php
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../core/NotifPrefs.php';

$c = fn($cfg,$kat) => NotifPrefs::channelsFor($cfg,$kat);

eqv($c(['daily_report'=>['email'=>1,'inapp'=>1]], 'daily_report'), ['email','inapp'], 'dua on');
eqv($c(['daily_report'=>['email'=>0,'inapp'=>1]], 'daily_report'), ['inapp'],          'email off');
eqv($c(['daily_report'=>['email'=>1,'inapp'=>0]], 'daily_report'), ['email'],          'inapp off');
eqv($c(['daily_report'=>['email'=>0,'inapp'=>0]], 'daily_report'), [],                 'dua off');
eqv($c(['alert_anomali'=>['email'=>0,'inapp'=>0]], 'daily_report'), ['email','inapp'], 'kategori absen → default');
eqv($c([], 'alert_anomali'), ['email','inapp'], 'cfg kosong → default');
eqv($c(['daily_report'=>['email'=>1]], 'daily_report'), ['email','inapp'], 'inapp key absen → default on');

echo "ALL OK\n";
```

- [ ] **Step 2: Jalankan, pastikan gagal**
Run: `/opt/homebrew/bin/php tests/notif/test_notifprefs.php` → FATAL "Class NotifPrefs not found".

- [ ] **Step 3: Implementasi `core/NotifPrefs.php`**
```php
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
```

- [ ] **Step 4: Jalankan, pastikan lulus**
Run: `/opt/homebrew/bin/php tests/notif/test_notifprefs.php` → `ALL OK`.

- [ ] **Step 5: Lint + commit**
```bash
/opt/homebrew/bin/php -l core/NotifPrefs.php
git add core/NotifPrefs.php tests/notif/test_notifprefs.php
git commit -m "feat(notif): NotifPrefs channelsFor + read + test"
```

---

### Task 2: DailyReport pakai NotifPrefs

**Files:** Modify `core/DailyReport.php`
**Interfaces:** Consumes `NotifPrefs::read/channelsFor` (Task 1).

- [ ] **Step 1: require NotifPrefs**
Pastikan di atas `core/DailyReport.php` ada `require_once __DIR__ . '/NotifPrefs.php';` (dekat require Notifier).

- [ ] **Step 2: Ganti `readConfig`**
Ganti isi method `readConfig` (saat ini default `enabled=true`/`channel_email`) jadi — kembalikan `jam`, `konten`, `channels`:
```php
    /** Baca konfigurasi dari tenants.notif_settings JSON */
    private static function readConfig(int $tenantId): array
    {
        $settings = NotifPrefs::read($tenantId);
        $cfg = [
            'jam'      => '21:00',
            'konten'   => ['omset','order','kas','absensi','alert'],
            'channels' => NotifPrefs::channelsFor($settings, 'daily_report'),
        ];
        if (isset($settings['daily_report_jam']) && preg_match('/^\d{2}:\d{2}$/', $settings['daily_report_jam']))
            $cfg['jam'] = $settings['daily_report_jam'];
        if (isset($settings['daily_report_konten']) && is_array($settings['daily_report_konten']))
            $cfg['konten'] = $settings['daily_report_konten'];
        return $cfg;
    }
```

- [ ] **Step 3: Ganti gate + channel di `maybeSend`**
Di `maybeSend`, ganti blok awal (cek `enabled`, jam) dan blok channel. Hasil akhir bagian relevan:
```php
            $cfg = self::readConfig($tenantId);

            // Channel off semua → senyap total (tak ada email & in-app)
            if (empty($cfg['channels'])) return ['ok'=>false, 'skipped'=>'channel off'];

            // Jam check — only send setelah jam yang ditentukan
            if (date('H:i') < $cfg['jam']) return ['ok'=>false, 'skipped'=>'jam belum'];

            // Sudah dikirim hari ini?
            if (Notifier::sentToday($tenantId, $outletId, 'daily_report')) {
                return ['ok'=>false, 'skipped'=>'sudah dikirim'];
            }

            // Build report
            $report = self::build($tenantId, $outletId, $cfg['konten']);

            $res = Notifier::notifyOwner($tenantId, $outletId, [
                'type'           => 'daily_report',
                'subject'        => $report['subject'],
                'body_html'      => $report['html'],
                'body_summary'   => $report['summary'],
                'channels'       => $cfg['channels'],
                'coin_feature'   => 'daily_report',
            ]);
            return $res;
```
> Hapus baris lama: `if (!$cfg['enabled']) return [...'disabled'];`, `$channels = ['inapp'];`, dan `if (!empty($cfg['channel_email'])) $channels[] = 'email';`. Jangan ubah method `build()`.

- [ ] **Step 4: Lint + smoke + commit**
Run: `/opt/homebrew/bin/php -l core/DailyReport.php`
Run (pastikan tak fatal saat di-include): `/opt/homebrew/bin/php -r "define('ROOT','.'); require 'core/NotifPrefs.php'; require 'core/DailyReport.php'; echo 'loaded ok\n';"`
Expected: `loaded ok`
```bash
git add core/DailyReport.php
git commit -m "feat(notif): DailyReport hormati channel per-config (buang hardcode inapp/enabled)"
```

---

### Task 3: AnomalyDetector pass channels per-config

**Files:** Modify `core/AnomalyDetector.php`
**Interfaces:** Consumes `NotifPrefs::read/channelsFor` (Task 1).

- [ ] **Step 1: require NotifPrefs**
Pastikan ada `require_once __DIR__ . '/NotifPrefs.php';` di atas `core/AnomalyDetector.php`.

- [ ] **Step 2: Ganti `check()` — hitung channels, skip kalau kosong, teruskan ke tiap cek**
```php
    public static function check(int $tenantId, int $outletId): void
    {
        try {
            // Channel aktif utk alert anomali. Kosong → owner matikan semuanya → skip (hemat coin).
            $channels = NotifPrefs::channelsFor(NotifPrefs::read($tenantId), 'alert_anomali');
            if (!$channels) return;

            self::checkOmsetDrop($tenantId, $outletId, $channels);
            self::checkKasBelumDiinput($tenantId, $outletId, $channels);
            self::checkOrderMenumpuk($tenantId, $outletId, $channels);
            self::checkAbsensiRendah($tenantId, $outletId, $channels);
            self::checkCoinRendah($tenantId, $outletId, $channels);
        } catch (Throwable $e) {
            error_log('[AnomalyDetector::check] ' . $e->getMessage());
        }
    }
```

- [ ] **Step 3: Hapus method `isEnabled()`**
Hapus seluruh method `private static function isEnabled(int $tenantId): bool { ... }` (tak dipakai lagi).

- [ ] **Step 4: Tambah param `array $channels` ke 5 cek + sisipkan ke opts notifyOwner**
Untuk tiap method `checkOmsetDrop`, `checkKasBelumDiinput`, `checkOrderMenumpuk`, `checkAbsensiRendah`, `checkCoinRendah`:
1. Ubah signature jadi `private static function NAMA(int $tenantId, int $outletId, array $channels): void`.
2. Pada tiap pemanggilan `Notifier::notifyOwner($tenantId, $outletId, [ ... ])` di dalamnya, tambahkan elemen `'channels' => $channels,` ke array opts (di samping `'coin_feature'=>'alert_anomali'`).
Contoh hasil (checkAbsensiRendah):
```php
        Notifier::notifyOwner($tenantId, $outletId, [
            'type'=>'alert_absensi_rendah', 'subject'=>$subject,
            'body_html'=>$body, 'body_summary'=>"Absensi {$hadir}/{$total} ({$pct}%)",
            'channels'=>$channels,
            'coin_feature'=>'alert_anomali',
        ]);
```
> Ada 5 method, masing-masing minimal satu `notifyOwner`. Pastikan SEMUA pemanggilan notifyOwner di file ini dapat `'channels'=>$channels`. Jangan ubah logika deteksi/threshold.

- [ ] **Step 5: Lint + smoke + commit**
Run: `/opt/homebrew/bin/php -l core/AnomalyDetector.php`
Run: `/opt/homebrew/bin/php -r "define('ROOT','.'); require 'core/NotifPrefs.php'; require 'core/AnomalyDetector.php'; echo 'loaded ok\n';"`
Expected: `loaded ok`
Verifikasi tak ada sisa `isEnabled`: `grep -n isEnabled core/AnomalyDetector.php` → kosong.
```bash
git add core/AnomalyDetector.php
git commit -m "feat(notif): AnomalyDetector kirim hanya via channel aktif + skip kalau off"
```

---

### Task 4: Owner UI — panel + get_prefs/save_prefs

**Files:** Modify `owner_report.php`
**Interfaces:** Consumes notif_settings skema (Global Constraints).

- [ ] **Step 1: Tambah action `get_prefs`**
Di area action handler `owner_report.php` (`$tid`/`$oid` sudah di-scope, dekat action `send_now`), tambahkan:
```php
if ($action === 'get_prefs') {
    header('Content-Type: application/json');
    if (!TenantResolver::isAdminLevel()) { echo json_encode(['error'=>'Akses ditolak']); exit; }
    $db = Database::get();
    $s = $db->prepare("SELECT notif_settings FROM tenants WHERE id=?");
    $s->execute([$tid]);
    $raw = $s->fetchColumn();
    $cfg = $raw ? (json_decode($raw, true) ?: []) : [];
    $g = function($cat,$ch) use ($cfg) { return (int)($cfg[$cat][$ch] ?? 1); }; // default 1
    echo json_encode([
        'dr_email'=>$g('daily_report','email'), 'dr_inapp'=>$g('daily_report','inapp'),
        'an_email'=>$g('alert_anomali','email'), 'an_inapp'=>$g('alert_anomali','inapp'),
        'jam'=>$cfg['daily_report_jam'] ?? '21:00',
        'konten'=>$cfg['daily_report_konten'] ?? ['omset','order','kas','absensi','alert'],
    ]); exit;
}
```

- [ ] **Step 2: Tambah action `save_prefs` (merge)**
```php
if ($action === 'save_prefs' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!TenantResolver::isAdminLevel()) { echo json_encode(['error'=>'Akses ditolak']); exit; }
    verifyCsrf();
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $db = Database::get();
    $s = $db->prepare("SELECT notif_settings FROM tenants WHERE id=?");
    $s->execute([$tid]);
    $raw = $s->fetchColumn();
    $cur = $raw ? (json_decode($raw, true) ?: []) : [];   // merge — jaga key HQ (coin_low/trial_ending)
    $cur['daily_report']  = ['email'=>!empty($d['dr_email'])?1:0, 'inapp'=>!empty($d['dr_inapp'])?1:0];
    $cur['alert_anomali'] = ['email'=>!empty($d['an_email'])?1:0, 'inapp'=>!empty($d['an_inapp'])?1:0];
    $jam = $d['jam'] ?? '21:00';
    if (!preg_match('/^\d{2}:\d{2}$/', $jam)) $jam = '21:00';
    $cur['daily_report_jam'] = $jam;
    $valid = ['omset','order','kas','absensi','alert']; $konten = [];
    foreach ((array)($d['konten'] ?? []) as $k) { if (in_array($k, $valid, true)) $konten[] = $k; }
    if (!$konten) $konten = $valid;
    $cur['daily_report_konten'] = $konten;
    try {
        $db->prepare("UPDATE tenants SET notif_settings=? WHERE id=?")->execute([json_encode($cur), $tid]);
        echo json_encode(['success'=>true]);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}
```
> Implementer: konfirmasi `verifyCsrf()` tersedia di owner_report.php (lewat components.php). Kalau tak ada, ikuti cara file lain (mis. absensi.php) meng-include/memanggilnya.

- [ ] **Step 3: Tambah panel UI + tombol pembuka**
Di markup, dekat tombol header (`onclick="sendNow()"` ~line 158), tambah tombol:
```html
<button class="hl-btn hl-btn-outline hl-btn-sm" onclick="openPrefs()">⚙️ Pengaturan Notifikasi</button>
```
Lalu tambahkan markup panel (modal) — ikuti pola modal existing di file (cek elemen `modal-card` yg sudah ada untuk styling):
```html
<div id="prefsModal" class="modal-overlay" style="display:none">
  <div class="modal-card" role="dialog" aria-modal="true">
    <h2 style="font-size:1.1rem;font-weight:800;color:var(--navy);margin-bottom:12px">⚙️ Pengaturan Notifikasi</h2>
    <div style="display:flex;flex-direction:column;gap:14px">
      <div>
        <div style="font-weight:700;color:var(--navy);font-size:13px;margin-bottom:6px">📊 Laporan Harian</div>
        <label style="display:flex;gap:8px;align-items:center;font-size:13px;margin-bottom:4px"><input type="checkbox" id="pf_dr_email"> Email</label>
        <label style="display:flex;gap:8px;align-items:center;font-size:13px"><input type="checkbox" id="pf_dr_inapp"> In-app (feed)</label>
        <div style="margin-top:8px;font-size:12px;color:var(--gray)">Jam kirim: <input type="time" id="pf_jam" style="padding:4px 8px;border:1px solid #E5E9F2;border-radius:6px"></div>
        <div style="margin-top:8px;display:flex;gap:10px;flex-wrap:wrap">
          <?php foreach (['omset'=>'💰 Omset','order'=>'📦 Order','kas'=>'💵 Kas','absensi'=>'👥 Absensi','alert'=>'⚠️ Alert'] as $k=>$lbl): ?>
            <label style="display:flex;gap:5px;align-items:center;font-size:12px"><input type="checkbox" class="pf_konten" value="<?= $k ?>"> <?= $lbl ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div>
        <div style="font-weight:700;color:var(--navy);font-size:13px;margin-bottom:6px">⚠️ Alert Anomali</div>
        <label style="display:flex;gap:8px;align-items:center;font-size:13px;margin-bottom:4px"><input type="checkbox" id="pf_an_email"> Email</label>
        <label style="display:flex;gap:8px;align-items:center;font-size:13px"><input type="checkbox" id="pf_an_inapp"> In-app (feed)</label>
      </div>
    </div>
    <div style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end">
      <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="document.getElementById('prefsModal').style.display='none'">Batal</button>
      <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="savePrefs()">💾 Simpan</button>
    </div>
  </div>
</div>
```

- [ ] **Step 4: Tambah JS openPrefs/savePrefs**
Di blok `<script>` owner_report.php:
```javascript
async function openPrefs(){
  const r = await fetch('owner_report.php?action=get_prefs');
  const d = await r.json();
  if (d.error) { showToast(d.error,'error'); return; }
  document.getElementById('pf_dr_email').checked = !!d.dr_email;
  document.getElementById('pf_dr_inapp').checked = !!d.dr_inapp;
  document.getElementById('pf_an_email').checked = !!d.an_email;
  document.getElementById('pf_an_inapp').checked = !!d.an_inapp;
  document.getElementById('pf_jam').value = d.jam || '21:00';
  document.querySelectorAll('.pf_konten').forEach(c => { c.checked = (d.konten||[]).includes(c.value); });
  document.getElementById('prefsModal').style.display = 'flex';
}
async function savePrefs(){
  const konten = Array.from(document.querySelectorAll('.pf_konten')).filter(c=>c.checked).map(c=>c.value);
  const body = {
    dr_email: document.getElementById('pf_dr_email').checked ? 1 : 0,
    dr_inapp: document.getElementById('pf_dr_inapp').checked ? 1 : 0,
    an_email: document.getElementById('pf_an_email').checked ? 1 : 0,
    an_inapp: document.getElementById('pf_an_inapp').checked ? 1 : 0,
    jam: document.getElementById('pf_jam').value || '21:00',
    konten: konten,
  };
  const r = await fetch('owner_report.php?action=save_prefs', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()}, body: JSON.stringify(body)
  });
  const d = await r.json();
  if (d.success) { showToast('Pengaturan disimpan','success'); document.getElementById('prefsModal').style.display='none'; }
  else showToast(d.error || 'Gagal','error');
}
```
> Implementer: pastikan `csrfToken()` & `showToast()` tersedia di owner_report.php (dipakai action lain di file). Kalau class overlay beda (mis. bukan `modal-overlay`/`flex`), samakan dengan modal existing di file.

- [ ] **Step 5: Lint + commit**
Run: `/opt/homebrew/bin/php -l owner_report.php`
```bash
git add owner_report.php
git commit -m "feat(notif): panel pengaturan notifikasi owner (per-channel) + get/save_prefs"
```

---

## Self-Review

**1. Spec coverage:**
- NotifPrefs channelsFor (pure) + read → Task 1 + test. ✅
- Default channel ON kalau absen → Task 1 (`?? 1`) + tested. ✅
- DailyReport hormati channel, buang hardcode inapp/enabled, dua off → skip → Task 2. ✅
- AnomalyDetector kirim via channel aktif, skip kalau off, buang isEnabled → Task 3. ✅
- Owner UI per-channel (4 switch) + jam + konten, get/save_prefs merge, isAdminLevel + CSRF → Task 4. ✅
- Manual send_now tak diubah → tidak disentuh task mana pun. ✅
- coin_low/trial_ending tak hilang → Task 4 merge (`$cur` dipertahankan). ✅

**2. Placeholder scan:** Tak ada TBD/TODO. Catatan "konfirmasi verifyCsrf/csrfToken/modal class tersedia" = integrasi ke file existing, diarahkan eksplisit + kode contoh.

**3. Type consistency:** `channelsFor(array,string):array` & `read(int):array` konsisten Task 1↔2↔3. Kunci config `daily_report`/`alert_anomali` + sub-key `email`/`inapp` konsisten Task 1/2/3/4. Field JSON owner UI (`dr_email`/`dr_inapp`/`an_email`/`an_inapp`/`jam`/`konten`) konsisten Task 4 get↔save↔JS. `'channels'=>$channels` ditambahkan (bukan diganti) di opts notifyOwner Task 3.

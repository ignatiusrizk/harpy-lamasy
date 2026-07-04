# Gabung /cek + Portal Pelanggan — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Jadikan `/cek` satu-satunya pintu masuk publik pelanggan; `/p` tanpa/invalid token diarahkan ke situ; QR struk & portal tak berubah.

**Architecture:** Pendekatan A dari spec — `cek.php` ditingkatkan (afordan "Masuk Portal" + handle `?msg=portal` + hardening query nota lintas-tenant); `p.php` mengganti halaman info "scan QR" jadi redirect `302` ke `/cek?msg=portal` (rate-limit dipertahankan). `pelanggan.php`, `pelanggan-order.php`, `middleware/pelanggan_guard.php`, `core/StrukGenerator.php`, dan jalur token-valid di `p.php` NOL perubahan.

**Tech Stack:** PHP (prosedural, tanpa framework test — verifikasi = `php -S` lokal + `curl` + `mysql` client), MariaDB (via `master/config/db.php` → `Database::get()`).

## Global Constraints

- Portal tetap **QR-only** — TIDAK menambah login HP/OTP.
- Dua tier akses tetap **terpisah**: tamu (nota + 4 digit HP) hanya lihat 1 order; portal (QR token) lihat akun penuh. Tak ada jembatan tamu→portal.
- Branding tampilan = **"LAMASY"** (bukan "LaMaSy").
- Header `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` di `cek.php` (baris 17) dipertahankan. (`p.php` tak set header ini; setelah jadi redirect murni, tak relevan.)
- Rate-limit dipertahankan: `cek.php` per IP+nota (existing), `p.php` 5/menit per IP (existing). Percobaan token gagal WAJIB tetap tercatat sebelum redirect.
- NOL perubahan: `pelanggan.php`, `pelanggan-order.php`, `middleware/pelanggan_guard.php`, `core/StrukGenerator.php`, jalur token-valid `p.php` (baris 35–58).
- QR cetak `/p?t=TOKEN[&o=NO_ORDER]` harus tetap auto-login seperti sekarang.
- MySQL client: `/opt/homebrew/opt/mysql-client/bin/mysql` (kredensial di `~/.my.cnf`).

---

## File Structure

- `p.php` (modify) — hapus HTML halaman info no-token (baris 71–103), ganti jadi redirect `302 /cek?msg=portal` setelah blok rate-limit. Tanggung jawab: gerbang QR-token; kalau tak ada token valid, lempar ke front door.
- `cek.php` (modify) — (a) `lookupOrder()` hardening lintas-tenant; (b) baca `?msg=portal`; (c) tambah afordan portal + CSS di state awal. Tanggung jawab: front door publik (cek status + arahkan ke portal).

---

### Task 1: p.php — redirect no/invalid token ke /cek?msg=portal

**Files:**
- Modify: `p.php:66-103`

**Interfaces:**
- Consumes: variabel `$attempts`, `$now`, `$rateKey` (sudah ada di p.php baris 24–33); `$token`, `$err` (baris 19, 34).
- Produces: tak ada (perilaku HTTP redirect).

Catatan: kalau token valid, p.php sudah `header('Location: ...'); exit;` di baris 57–58 — jadi eksekusi hanya sampai baris 66+ kalau **tak ada token** atau **token invalid/not-found**. Kedua kasus ini yang di-redirect. Blok rate-limit (baris 66–68) hanya jalan di dalam `if ($token)`, jadi percobaan token gagal tetap tercatat; no-token tak mencatat (memang tak ada percobaan).

- [ ] **Step 1: Ganti blok akhir p.php (baris 66 sampai akhir file) jadi redirect**

Buka `p.php`. Ganti mulai dari baris 66 (komentar `// Record failed attempt`) sampai akhir file (baris 103 `</html>`) dengan:

```php
    // Record failed attempt (token ada tapi invalid/not-found)
    $attempts[] = $now;
    $_SESSION[$rateKey] = $attempts;
}

// Tak ada token valid (token kosong / invalid / pelanggan tak aktif) → arahkan ke
// front door publik. Portal tetap QR-only; /cek?msg=portal menampilkan instruksi scan QR.
header('Location: /cek?msg=portal');
exit;
```

Setelah edit, baris 71 ke bawah (blok `?>` + seluruh HTML `<!doctype html>...</html>`) HILANG — file berakhir tepat di `exit;` di atas.

- [ ] **Step 2: Cek sintaks PHP**

Run: `php -l p.php`
Expected: `No syntax errors detected in p.php`

- [ ] **Step 3: Jalankan server lokal & uji 3 kasus redirect**

Run:
```bash
php -S 127.0.0.1:8899 >/tmp/lm_srv.log 2>&1 &
sleep 1
echo "--- no token:";      curl -sI 'http://127.0.0.1:8899/p.php'                | grep -i '^location'
echo "--- invalid format:";curl -sI 'http://127.0.0.1:8899/p.php?t=xxx'          | grep -i '^location'
echo "--- 32hex not-found:";curl -sI 'http://127.0.0.1:8899/p.php?t=00000000000000000000000000000000' | grep -i '^location'
```
Expected (ketiganya):
```
--- no token:
location: /cek?msg=portal
--- invalid format:
location: /cek?msg=portal
--- 32hex not-found:
location: /cek?msg=portal
```

- [ ] **Step 4: Pastikan jalur token-valid TAK rusak (regresi)**

Ambil satu token portal nyata lalu cek redirect-nya ke `/pelanggan`:
```bash
TOK=$(/opt/homebrew/opt/mysql-client/bin/mysql -Nse "SELECT portal_token FROM hl_pelanggan WHERE portal_token IS NOT NULL AND is_active=1 LIMIT 1")
curl -sI "http://127.0.0.1:8899/p.php?t=$TOK" | grep -i '^location'
```
Expected: `location: /pelanggan` (BUKAN `/cek?msg=portal`).

- [ ] **Step 5: Matikan server & commit**

```bash
kill %1 2>/dev/null
git add p.php
git commit -m "feat(portal): /p tanpa/invalid token redirect ke /cek?msg=portal (front door tunggal); token valid & rate-limit tak berubah"
```

---

### Task 2: cek.php — hardening lookupOrder lintas-tenant

**Files:**
- Modify: `cek.php:39-58`

**Interfaces:**
- Consumes: `$noOrder`, `$phoneLast4` (parameter fungsi `lookupOrder`).
- Produces: `lookupOrder(string $noOrder, string $phoneLast4): ?array` — signature TAK berubah; hanya internal query + pemilihan baris.

Masalah: `WHERE t.no_order = ? LIMIT 1` tak di-scope tenant; `no_order` cuma unik per-tenant. `LIMIT 1` bisa memilih baris tenant lain lalu verifikasi 4-digit pada baris salah. Perbaikan: ambil SEMUA baris ber-`no_order` itu, kembalikan hanya baris yang 4-digit HP-nya cocok.

- [ ] **Step 1: Ganti query + verifikasi di `lookupOrder()`**

Di `cek.php`, di dalam fungsi `lookupOrder()`, ganti blok ini (baris ~39–58):

```php
        $st = $db->prepare(
            "SELECT t.*,
                    (SELECT GROUP_CONCAT(CONCAT(nama_layanan,' (',jumlah,' ',satuan,')') SEPARATOR ', ')
                       FROM hl_transaksi_item WHERE transaksi_id=t.id) AS items_summary,
                    o.nama_outlet, o.alamat AS outlet_alamat, o.telepon AS outlet_telp,
                    (SELECT logo_path FROM tenants WHERE id=t.tenant_id) AS tenant_logo,
                    (SELECT nama_perusahaan FROM tenants WHERE id=t.tenant_id) AS tenant_nama
               FROM hl_transaksi t
          LEFT JOIN outlets o ON o.id = t.outlet_id
              WHERE t.no_order = ? LIMIT 1"
        );
        $st->execute([$noOrder]);
        $trx = $st->fetch(PDO::FETCH_ASSOC);
        if (!$trx) return null;

        // Verify phone last 4 digits
        $tPhone = preg_replace('/[^0-9]/', '', (string)($trx['telepon'] ?? ''));
        if (substr($tPhone, -4) !== preg_replace('/[^0-9]/','',$phoneLast4)) {
            return null;
        }
```

dengan (hapus `LIMIT 1`, ambil semua baris, pilih yang HP-nya cocok):

```php
        $st = $db->prepare(
            "SELECT t.*,
                    (SELECT GROUP_CONCAT(CONCAT(nama_layanan,' (',jumlah,' ',satuan,')') SEPARATOR ', ')
                       FROM hl_transaksi_item WHERE transaksi_id=t.id) AS items_summary,
                    o.nama_outlet, o.alamat AS outlet_alamat, o.telepon AS outlet_telp,
                    (SELECT logo_path FROM tenants WHERE id=t.tenant_id) AS tenant_logo,
                    (SELECT nama_perusahaan FROM tenants WHERE id=t.tenant_id) AS tenant_nama
               FROM hl_transaksi t
          LEFT JOIN outlets o ON o.id = t.outlet_id
              WHERE t.no_order = ?"
        );
        $st->execute([$noOrder]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return null;

        // no_order hanya unik PER-tenant → bisa >1 baris lintas-tenant. Jangan pilih
        // sembarang: kembalikan hanya baris yang 4 digit terakhir HP-nya cocok.
        $want = preg_replace('/[^0-9]/', '', $phoneLast4);
        $trx  = null;
        foreach ($rows as $r) {
            $tPhone = preg_replace('/[^0-9]/', '', (string)($r['telepon'] ?? ''));
            if (substr($tPhone, -4) === $want) { $trx = $r; break; }
        }
        if (!$trx) return null;
```

Sisa fungsi (progress_percent, items_detail, timeline, `return $trx;`) TETAP — tetap pakai `$trx`.

- [ ] **Step 2: Cek sintaks PHP**

Run: `php -l cek.php`
Expected: `No syntax errors detected in cek.php`

- [ ] **Step 3: Seed skenario lintas-tenant (dua tenant, nota sama, HP beda)**

Run (pakai tenant demo id 2 + tenant lain id 3 yang sudah ada):
```bash
M=/opt/homebrew/opt/mysql-client/bin/mysql
$M -e "INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,nama_pelanggan,telepon,subtotal,total,status_bayar,status_proses,created_by,created_at)
       VALUES (2,2,'ZZZTEST-001',CURDATE(),'Tenant A Cust','081200001111',10000,10000,'lunas','masuk',NULL,NOW());"
OID3=$($M -Nse "SELECT id FROM outlets WHERE tenant_id=3 LIMIT 1")
$M -e "INSERT INTO hl_transaksi (tenant_id,outlet_id,no_order,tanggal,nama_pelanggan,telepon,subtotal,total,status_bayar,status_proses,created_by,created_at)
       VALUES (3,$OID3,'ZZZTEST-001',CURDATE(),'Tenant B Cust','081200002222',20000,20000,'lunas','masuk',NULL,NOW());"
$M -e "SELECT tenant_id,no_order,nama_pelanggan,telepon FROM hl_transaksi WHERE no_order='ZZZTEST-001';"
```
Expected: dua baris — Tenant A (…1111) dan Tenant B (…2222).

- [ ] **Step 4: Uji lookup mengembalikan pemilik HP yang benar (bukan sembarang)**

Run:
```bash
php -S 127.0.0.1:8899 >/tmp/lm_srv.log 2>&1 &
sleep 1
echo "--- p=2222 harus Tenant B:"; curl -s 'http://127.0.0.1:8899/cek.php?action=status&n=ZZZTEST-001&p=2222' | grep -o '"nama_pelanggan":"[^"]*"'
echo "--- p=1111 harus Tenant A:"; curl -s 'http://127.0.0.1:8899/cek.php?action=status&n=ZZZTEST-001&p=1111' | grep -o '"nama_pelanggan":"[^"]*"'
echo "--- p=9999 harus not found:"; curl -s 'http://127.0.0.1:8899/cek.php?action=status&n=ZZZTEST-001&p=9999' | grep -o '"error":"[^"]*"'
kill %1 2>/dev/null
```
Expected:
```
--- p=2222 harus Tenant B:
"nama_pelanggan":"Tenant B Cust"
--- p=1111 harus Tenant A:
"nama_pelanggan":"Tenant A Cust"
--- p=9999 harus not found:
"error":"Order tidak ditemukan / verifikasi gagal"
```
(Sebelum fix: `p=2222` bisa mengembalikan Tenant A atau "verifikasi gagal" karena `LIMIT 1` memilih baris pertama.)

- [ ] **Step 5: Hapus seed & commit**

```bash
/opt/homebrew/opt/mysql-client/bin/mysql -e "DELETE FROM hl_transaksi WHERE no_order='ZZZTEST-001' AND tenant_id IN (2,3);"
git add cek.php
git commit -m "fix(cek): lookup nota tak lagi bisa salah-ambil order lintas-tenant. no_order unik per-tenant → ambil semua baris, kembalikan hanya yg 4-digit HP cocok (bukan LIMIT 1 sembarang)"
```

---

### Task 3: cek.php — afordan "Masuk Portal" + handle ?msg=portal + CSS

**Files:**
- Modify: `cek.php:19-21` (baca param `msg`), `cek.php:118-162` (CSS), `cek.php:167-182` (state awal)

**Interfaces:**
- Consumes: `$noOrder` (baris 19), `$_GET['msg']`.
- Produces: variabel `$portalMsg` (bool) untuk dipakai di state awal.

- [ ] **Step 1: Tambah pembacaan param `msg` di dekat baris 19–21**

Di `cek.php`, setelah baris:
```php
$noOrder = trim($_GET['n'] ?? '');
$phoneLast4 = trim($_POST['phone'] ?? '');
$ajaxAction = $_GET['action'] ?? '';
```
tambahkan:
```php
$portalMsg = ($_GET['msg'] ?? '') === 'portal';  // datang dari /p tanpa token valid
```

- [ ] **Step 2: Tambah CSS afordan portal di dalam `<style>` (sebelum `</style>` baris 162)**

Tepat sebelum `</style>`, tambahkan:
```css
.or-divider { text-align:center; color:var(--gray); font-size:12px; margin:16px 0; }
.portal-affordance { background:#fff; border:1px solid var(--border); border-radius:16px; padding:0; margin-bottom:14px; box-shadow:0 1px 3px rgba(0,0,0,0.06); overflow:hidden; }
.portal-affordance > summary { list-style:none; cursor:pointer; padding:16px 18px; font-size:14px; font-weight:700; color:var(--teal); display:flex; justify-content:space-between; align-items:center; gap:8px; }
.portal-affordance > summary::-webkit-details-marker { display:none; }
.portal-affordance > summary span { color:var(--gray); font-weight:600; font-size:13px; }
.portal-affordance[open] > summary { border-bottom:1px solid #F3F4F6; }
.portal-body { padding:14px 18px 18px; font-size:13.5px; color:#374151; line-height:1.6; }
.portal-body ul { margin:8px 0 0; padding-left:18px; color:var(--gray); }
```

- [ ] **Step 3: Ganti blok state awal (baris 167–182) dengan versi ber-afordan portal**

Ganti blok:
```php
  <?php if (!$noOrder): ?>
  <!-- ════════ STATE: NO INPUT — Form input nomor nota ════════ -->
  <div class="brand">
    <h1>🧺 LAMASY Tracking</h1>
    <p>Cek status cucian Anda</p>
  </div>
  <div class="card">
    <h2 class="h2">Masukkan Nomor Nota</h2>
    <form method="GET" action="/cek.php">
      <input type="text" name="n" placeholder="HARPY-260607-001" required autofocus autocomplete="off"/>
      <button type="submit" class="btn">🔍 Cek Status</button>
    </form>
  </div>
  <div class="contact">
    Belum punya nota? Hubungi outlet laundry Anda.
  </div>
```
dengan:
```php
  <?php if (!$noOrder): ?>
  <!-- ════════ STATE: NO INPUT — Form nota + afordan portal ════════ -->
  <div class="brand">
    <h1>🧺 LAMASY</h1>
    <p>Cek status cucian Anda</p>
  </div>

  <?php
  // Blok afordan portal — dirender sekali, dipakai di ATAS (kalau datang dari /p
  // redirect) atau di BAWAH form (default). <details> = toggle aksesibel keyboard.
  ob_start(); ?>
    <details class="portal-affordance"<?= $portalMsg ? ' open' : '' ?>>
      <summary>🎫 Punya akun member? <span>Masuk Portal Pelanggan →</span></summary>
      <div class="portal-body">
        📷 <strong>Scan QR code di struk</strong> laundry kamu — otomatis masuk ke akun.
        <ul>
          <li>Poin &amp; deposit</li>
          <li>Riwayat semua order</li>
          <li>Kupon &amp; ajak teman</li>
        </ul>
      </div>
    </details>
  <?php $portalBlock = ob_get_clean(); ?>

  <?php if ($portalMsg) echo $portalBlock; ?>

  <div class="card">
    <h2 class="h2">Masukkan Nomor Nota</h2>
    <form method="GET" action="/cek.php">
      <input type="text" name="n" placeholder="HARPY-260607-001" required<?= $portalMsg ? '' : ' autofocus' ?> autocomplete="off"/>
      <button type="submit" class="btn">🔍 Cek Status</button>
    </form>
  </div>

  <?php if (!$portalMsg): ?>
    <div class="or-divider">— atau —</div>
    <?= $portalBlock ?>
  <?php endif; ?>

  <div class="contact">
    Belum punya nota? Hubungi outlet laundry Anda.
  </div>
```

- [ ] **Step 4: Cek sintaks PHP**

Run: `php -l cek.php`
Expected: `No syntax errors detected in cek.php`

- [ ] **Step 5: Uji render dua kondisi (default vs msg=portal)**

Run:
```bash
php -S 127.0.0.1:8899 >/tmp/lm_srv.log 2>&1 &
sleep 1
echo "--- default: afordan ADA & tertutup, form autofocus ADA:"
curl -s 'http://127.0.0.1:8899/cek.php' | grep -c 'portal-affordance'
curl -s 'http://127.0.0.1:8899/cek.php' | grep -o 'details class="portal-affordance"[^>]*>'
curl -s 'http://127.0.0.1:8899/cek.php' | grep -o 'required autofocus'
echo "--- msg=portal: afordan TERBUKA (open), autofocus HILANG:"
curl -s 'http://127.0.0.1:8899/cek.php?msg=portal' | grep -o 'details class="portal-affordance"[^>]*>'
curl -s 'http://127.0.0.1:8899/cek.php?msg=portal' | grep -c 'required autofocus'
kill %1 2>/dev/null
```
Expected:
```
--- default: afordan ADA & tertutup, form autofocus ADA:
1
details class="portal-affordance">
required autofocus
--- msg=portal: afordan TERBUKA (open), autofocus HILANG:
details class="portal-affordance" open>
0
```

- [ ] **Step 6: Verifikasi visual (mobile) via gstack**

Run:
```bash
BR="$HOME/.claude/skills/gstack/browse/dist/browse"
"$BR" viewport 390x780
"$BR" goto "http://127.0.0.1:8899/cek.php?msg=portal"   # jalankan php -S dulu bila perlu
"$BR" screenshot "/private/tmp/claude-501/-Users-rizky-Documents-lamasy/196a6e26-184a-4a5d-9c10-023f3abe8144/scratchpad/cek_portal.png"
```
Expected: blok "🎫 Masuk Portal Pelanggan" tampil TERBUKA di atas, form "Masukkan Nomor Nota" di bawahnya; tanpa overflow horizontal. (Baca screenshot untuk konfirmasi.)

- [ ] **Step 7: Commit**

```bash
git add cek.php
git commit -m "feat(cek): afordan 'Masuk Portal' (details toggle, aksesibel) + handle ?msg=portal (tampil terbuka di atas saat datang dari /p). Front door tunggal siap"
```

---

## Manual E2E Akhir (setelah 3 task selesai; sesuai spec bagian Testing)

Jalankan `php -S 127.0.0.1:8899` lalu:

1. `GET /cek.php` → form cek-status + afordan portal (tertutup). ✅ Task 3 Step 5.
2. `GET /cek.php?action=status&n=<nota_valid>&p=<4digit_benar>` → JSON order; `p=<salah>` → `{"error":...}`. ✅ Task 2 Step 4 pola sama.
3. `GET /p.php?t=<valid>` → `Location: /pelanggan`. ✅ Task 1 Step 4.
4. `GET /p.php` (tanpa token) → `Location: /cek?msg=portal`. ✅ Task 1 Step 3.
5. `GET /p.php?t=<invalid>` → `Location: /cek?msg=portal`. ✅ Task 1 Step 3.
6. `GET /cek.php?msg=portal` → afordan portal terbuka di atas. ✅ Task 3 Step 5–6.
7. Lintas-tenant: nota sama HP beda → tiap pelanggan hanya lihat miliknya. ✅ Task 2 Step 4.

# Checklist Wajib-Foto per Item — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tiap item template checklist bisa ditandai "wajib foto"; saat staff isi, item wajib-foto yang dicentang harus melampirkan foto (blok simpan kalau belum).

**Architecture:** Tanpa migrasi DB — `photo:0|1` per item di `items_json`, `foto_url` per jawaban di `answers_json`. Upload foto via endpoint baru `checklist.php?action=upload_foto` (reuse `FileUpload::uploadImage`). Validasi blok-simpan di klien (UX) + server (`Checklist::validateAnswers`, otoritatif terhadap template).

**Tech Stack:** PHP 8 / MariaDB. `core/Checklist.php`, `core/FileUpload.php` (existing), `hq/checklist.php` (builder), `checklist.php` (fill). Test: skrip CLI + `tests/_assert.php` (sudah ada).

## Global Constraints

- Multi-tenant: query scoping `tenant_id` (+ `outlet_id`). Tetap pakai pola existing.
- **Tanpa migrasi DB** — `photo` di items_json, `foto_url` di answers_json.
- **Foto wajib HANYA untuk item yang `checked`** — item tak dicentang tak butuh foto.
- **Server otoritatif**: validasi wajib-foto di `Checklist::submit` terhadap `items_json` template (jangan percaya klien). Klien validasi hanya untuk UX.
- Upload: hanya terima image; path tersimpan WAJIB diawali `uploads/foto_checklist/` (validasi anti-XSS, pola produksi.php).
- Endpoint POST WAJIB `verifyCsrf()`.
- PHP CLI `/opt/homebrew/bin/php`. Deploy `git push origin main`. **Selalu `git pull --no-edit` sebelum push** (ada sesi paralel lain di repo).

## Signatures (verbatim dari codebase)

- `Checklist::saveTemplate(int $tenantId, array $data, ?int $id=null): int` — menormalkan `$data['items']` jadi `['text'=>, 'required'=>]` (DROP field lain → harus ditambah `photo`).
- `Checklist::submit(int $tenantId, int $outletId, int $templateId, string $tanggal, array $answers, ?int $userId, ?string $userNama): void` — punya loop validasi required + hitung `checked`.
- `Checklist::getTemplate(int $tenantId, int $id): ?array` — return template dgn `items` (array hasil decode items_json).
- `FileUpload::uploadImage($file, string $dir, string $prefix): array` — return `['path'=>string]` atau `['error'=>string]` (lihat `produksi.php` action upload_foto).
- Fill UI (`checklist.php`): item render index `i`; checkbox `ck_${tid}_${i}`, note `note_${tid}_${i}`; `submitCk(tid,itemCount)` kirim `answers[i]={checked,note}`; `showToast`, `csrfToken`, `esc` global.
- Builder (`hq/checklist.php`): `addItem(text, required)` render `.item-row` (input `.item-text`, checkbox `.item-req`); `saveTpl()` map item `{text, required}`.

## File Structure

- `core/Checklist.php` (MODIFY): `saveTemplate` simpan `photo`; tambah pure `validateAnswers(array $items, array $answers): int`; `submit` pakai `validateAnswers`.
- `tests/checklist/test_validate.php` (NEW): unit `validateAnswers`.
- `hq/checklist.php` (MODIFY): builder — checkbox "📷 Wajib foto" per item.
- `checklist.php` (MODIFY): endpoint `upload_foto`; fill UI kontrol foto + thumbnail + validasi klien.

---

### Task 1: Server — `photo` di template + validasi foto (Checklist.php)

**Files:**
- Modify: `core/Checklist.php`
- Create: `tests/checklist/test_validate.php`

**Interfaces:**
- Produces:
  - `saveTemplate` menyimpan item `{text, required, photo}` (photo 0|1).
  - `Checklist::validateAnswers(array $items, array $answers): int` — pure; throw `RuntimeException` kalau (a) item `required` tak `checked`, atau (b) item `photo` `checked` tapi `foto_url` kosong; return jumlah item `checked`.
  - `submit` memakai `validateAnswers` (perilaku required tetap sama + tambah cek foto).

- [ ] **Step 1: Tulis test (failing)**

`tests/checklist/test_validate.php`:
```php
<?php
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../core/Checklist.php';

$items = [
    ['text' => 'Sapu lantai',        'required' => 1, 'photo' => 0],
    ['text' => 'Foto mesin bersih',  'required' => 0, 'photo' => 1],
    ['text' => 'Cek stok',           'required' => 0, 'photo' => 0],
];

// 1. semua valid: required dicentang, photo dicentang + ada foto_url
$n = Checklist::validateAnswers($items, [
    0 => ['checked' => 1],
    1 => ['checked' => 1, 'foto_url' => '/uploads/foto_checklist/a.jpg'],
    2 => ['checked' => 0],
]);
eqv($n, 2, 'checked count = 2');

// 2. required (idx0) tak dicentang → throw
$threw = false;
try { Checklist::validateAnswers($items, [1=>['checked'=>0],2=>['checked'=>0]]); }
catch (Throwable $e) { $threw = true; }
ok($threw, 'required tak dicentang → throw');

// 3. photo item dicentang TANPA foto_url → throw
$threw = false;
try { Checklist::validateAnswers($items, [0=>['checked'=>1], 1=>['checked'=>1]]); }
catch (Throwable $e) { $threw = true; }
ok($threw, 'wajib-foto dicentang tanpa foto → throw');

// 4. photo item TIDAK dicentang tanpa foto → lolos
$n4 = Checklist::validateAnswers($items, [0=>['checked'=>1], 1=>['checked'=>0]]);
eqv($n4, 1, 'wajib-foto tak dicentang → tak perlu foto, lolos');

// 5. foto_url kosong string dianggap kosong
$threw = false;
try { Checklist::validateAnswers($items, [0=>['checked'=>1], 1=>['checked'=>1,'foto_url'=>'  ']]); }
catch (Throwable $e) { $threw = true; }
ok($threw, 'foto_url whitespace → dianggap kosong → throw');

echo "ALL OK\n";
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `/opt/homebrew/bin/php tests/checklist/test_validate.php`
Expected: FATAL "undefined method Checklist::validateAnswers".

- [ ] **Step 3: Tambah `photo` di `saveTemplate`**

Di `core/Checklist.php` `saveTemplate`, di blok normalisasi item, ubah agar simpan `photo`:
```php
            } else {
                $text = trim($it['text'] ?? '');
                $req  = !empty($it['required']) ? 1 : 0;
            }
            if ($text !== '') $items[] = ['text'=>$text, 'required'=>$req, 'photo'=> (!empty($it['photo']) ? 1 : 0)];
```
(ganti baris `if ($text !== '') $items[] = ['text'=>$text, 'required'=>$req];` jadi versi di atas; untuk cabang `is_string($it)` set photo 0 — pakai variabel `$photo`:)
```php
            if (is_string($it)) {
                $text = trim($it);
                $req = 0; $photo = 0;
            } else {
                $text  = trim($it['text'] ?? '');
                $req   = !empty($it['required']) ? 1 : 0;
                $photo = !empty($it['photo']) ? 1 : 0;
            }
            if ($text !== '') $items[] = ['text'=>$text, 'required'=>$req, 'photo'=>$photo];
```

- [ ] **Step 4: Tambah `validateAnswers` + pakai di `submit`**

Tambahkan method pure (mis. sebelum `submit`):
```php
    /** Validasi jawaban vs item template. Throw kalau required tak dicentang / wajib-foto tanpa foto. Return jumlah checked. */
    public static function validateAnswers(array $items, array $answers): int
    {
        $checked = 0;
        foreach ($items as $idx => $item) {
            $ans = $answers[$idx] ?? $answers[(string)$idx] ?? null;
            $isChecked = !empty($ans['checked']);
            if ($isChecked) $checked++;
            if (!empty($item['required']) && !$isChecked) {
                throw new RuntimeException('Item wajib belum dicentang: "' . $item['text'] . '"');
            }
            if (!empty($item['photo']) && $isChecked && trim((string)($ans['foto_url'] ?? '')) === '') {
                throw new RuntimeException('Item wajib lampirkan foto: "' . $item['text'] . '"');
            }
        }
        return $checked;
    }
```
Lalu di `submit`, ganti loop validasi required+hitung checked yang lama dengan:
```php
        $checked = self::validateAnswers($tpl['items'], $answers);
```
(hapus loop lama `$checked = 0; foreach (...) { ... }` yang menghitung checked + cek required — sekarang ditangani `validateAnswers`).

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `/opt/homebrew/bin/php tests/checklist/test_validate.php`
Expected: `PASS` semua + `ALL OK`.

- [ ] **Step 6: Lint + commit**

```bash
/opt/homebrew/bin/php -l core/Checklist.php
git add core/Checklist.php tests/checklist/test_validate.php
git commit -m "feat(checklist): photo flag di template + validateAnswers (blok submit kalau wajib-foto tanpa foto)"
```

---

### Task 2: Builder UI — checkbox "Wajib foto" (hq/checklist.php)

**Files:**
- Modify: `hq/checklist.php`

**Interfaces:**
- Consumes: `saveTemplate` (Task 1) yang sudah simpan `photo`.

- [ ] **Step 1: `addItem` terima + render checkbox photo**

Ganti fungsi `addItem` jadi:
```js
function addItem(text='', required=0, photo=0){
  const div = document.createElement('div');
  div.className = 'item-row';
  div.innerHTML = `
    <input type="text" class="item-text" placeholder="Item checklist…" value="${esc(text)}">
    <label><input type="checkbox" class="item-req" ${required?'checked':''}> wajib</label>
    <label><input type="checkbox" class="item-photo" ${photo?'checked':''}> 📷 foto</label>
    <button class="btn btn-light btn-sm" onclick="this.parentElement.remove()">✕</button>`;
  document.getElementById('itemsWrap').appendChild(div);
}
```

- [ ] **Step 2: Muat `photo` saat edit template**

Cari pemanggilan `addItem(it.text, it.required)` (saat buka modal edit, sekitar baris 196) → ubah jadi:
```js
items.forEach(it => addItem(it.text, it.required, it.photo));
```

- [ ] **Step 3: Sertakan `photo` saat simpan**

Di `saveTpl`, map item tambah `photo`:
```js
  const items = [...document.querySelectorAll('#itemsWrap .item-row')].map(row => ({
    text: row.querySelector('.item-text').value.trim(),
    required: row.querySelector('.item-req').checked ? 1 : 0,
    photo: row.querySelector('.item-photo').checked ? 1 : 0,
  })).filter(it => it.text);
```

- [ ] **Step 4: Lint + commit**

Run: `/opt/homebrew/bin/php -l hq/checklist.php`
```bash
git add hq/checklist.php
git commit -m "feat(checklist): checkbox 'wajib foto' per item di template builder"
```

---

### Task 3: Fill UI — upload foto + enforce + thumbnail (checklist.php)

**Files:**
- Modify: `checklist.php`

**Interfaces:**
- Consumes: `FileUpload::uploadImage`, item `photo` flag, `validateAnswers` (server, Task 1).
- Produces: endpoint `checklist.php?action=upload_foto`; fill UI dgn kontrol foto.

- [ ] **Step 1: Endpoint `upload_foto`**

Di `checklist.php`, di area handler action (dekat `action==='submit'`), tambahkan:
```php
    if ($action === 'upload_foto' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        verifyCsrf();
        require_once ROOT . '/core/FileUpload.php';
        $f = $_FILES['foto'] ?? null;
        if (!$f) { echo json_encode(['error'=>'File foto tidak ditemukan']); exit; }
        $res = FileUpload::uploadImage($f, 'uploads/foto_checklist', 't' . $tid . '_o' . $oid);
        if (!empty($res['error'])) { echo json_encode(['error'=>$res['error']]); exit; }
        echo json_encode(['ok'=>true, 'path'=>$res['path']]);
        exit;
    }
```
> Implementer: konfirmasi `ROOT` terdefinisi & `$tid`/`$oid` tersedia di scope ini (lihat handler `submit` di file yang sama). Kalau nama beda, samakan.

- [ ] **Step 2: Server submit — validasi foto path + biarkan validateAnswers jalan**

Di handler `action==='submit'`, sebelum memanggil `Checklist::submit`, bersihkan `foto_url` tiap answer agar hanya menerima path dari upload kita (anti-XSS), lalu submit (validateAnswers di server sudah otoritatif):
```php
        $answers = $d['answers'] ?? [];
        foreach ($answers as $i => &$a) {
            if (isset($a['foto_url'])) {
                $p = (string)$a['foto_url'];
                $a['foto_url'] = (strpos($p, 'uploads/foto_checklist/') !== false) ? $p : '';
            }
        }
        unset($a);
```
(Letakkan tepat setelah `$answers` diambil, sebelum `Checklist::submit(...)`.)

- [ ] **Step 3: Fill render — kontrol foto untuk item `photo`**

Di fungsi render item (map `t.items`), ubah agar item `it.photo` menampilkan badge + tombol foto + thumbnail. Ganti blok `return \`...\`` item jadi:
```js
  const items = t.items.map((it,i) => {
    const ans = sub && sub.answers ? (sub.answers[i] || sub.answers[String(i)] || {}) : {};
    const checked = ans.checked ? 'checked' : '';
    const note = ans.note ? esc(ans.note) : '';
    const fotoUrl = ans.foto_url || '';
    const photoCtrl = it.photo ? `
      <div class="ck-photo" id="photowrap_${t.id}_${i}">
        <input type="hidden" id="foto_${t.id}_${i}" value="${esc(fotoUrl)}">
        <img id="fotoimg_${t.id}_${i}" src="${esc(fotoUrl)}" style="${fotoUrl?'':'display:none;'}max-width:90px;max-height:90px;border-radius:8px;border:1px solid #E5E9F2;margin-top:6px">
        <label class="hl-btn hl-btn-light btn-sm" style="margin-top:6px;display:inline-flex;cursor:pointer">📷 ${fotoUrl?'Ganti':'Ambil'} Foto
          <input type="file" accept="image/*" capture="environment" style="display:none" onchange="ckUploadFoto(${t.id},${i},this)">
        </label>
      </div>` : '';
    return `
      <div class="ck-item">
        <input type="checkbox" id="ck_${t.id}_${i}" ${checked}>
        <div class="ck-item-body">
          <div class="ck-item-text">${esc(it.text)}${it.required?'<span class="req">*wajib</span>':''}${it.photo?'<span class="req" style="background:#DBEAFE;color:#1E40AF">📷 wajib foto</span>':''}</div>
          <input type="text" class="ck-item-note" id="note_${t.id}_${i}" placeholder="Catatan (opsional)…" value="${note}">
          ${photoCtrl}
        </div>
      </div>`;
  }).join('');
```

- [ ] **Step 4: Fungsi upload + submit enforce**

Tambah `ckUploadFoto` + ubah `submitCk`:
```js
async function ckUploadFoto(tid, i, input){
  const file = input.files && input.files[0];
  if (!file) return;
  const fd = new FormData(); fd.append('foto', file);
  showToast('📤 Mengupload foto…','info');
  try {
    const r = await fetch('checklist.php?action=upload_foto', { method:'POST', body: fd });
    const d = await r.json();
    if (d.error){ showToast('❌ '+d.error,'error'); return; }
    document.getElementById(`foto_${tid}_${i}`).value = d.path;
    const img = document.getElementById(`fotoimg_${tid}_${i}`);
    img.src = '/' + d.path.replace(/^\//,''); img.style.display = '';
    showToast('✅ Foto terlampir','success');
  } catch(e){ showToast('❌ Gagal upload: '+e.message,'error'); }
}

async function submitCk(tid, itemCount){
  const answers = {};
  const items = (window.__ckTemplates && window.__ckTemplates[tid]) ? window.__ckTemplates[tid].items : [];
  for (let i=0;i<itemCount;i++){
    const checked = document.getElementById(`ck_${tid}_${i}`).checked ? 1 : 0;
    const fotoEl = document.getElementById(`foto_${tid}_${i}`);
    const foto_url = fotoEl ? fotoEl.value : '';
    // enforce klien: item wajib-foto yg dicentang harus ada foto
    if (checked && items[i] && items[i].photo && !foto_url){
      showToast(`❌ Item "${items[i].text}" wajib lampirkan foto`,'error');
      return;
    }
    answers[i] = { checked, note: document.getElementById(`note_${tid}_${i}`).value.trim(), foto_url };
  }
  try {
    const r = await fetch('checklist.php?action=submit', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify({template_id: tid, answers})
    });
    const d = await r.json();
    if (d.error){ showToast('❌ '+d.error,'error'); return; }
    showToast('✅ Checklist tersimpan!','success');
    loadToday();
  } catch(e){ showToast('❌ '+e.message,'error'); }
}
```
> Implementer: `submitCk` butuh akses `items[i].photo`/`.text`. Cari di `checklist.php` di mana data template di-render (fungsi yang map `t.items`) dan simpan template ke `window.__ckTemplates[t.id] = t` saat render, supaya `submitCk` bisa baca. Kalau sudah ada variabel template global, pakai itu — sesuaikan nama. Jangan ubah perilaku item non-foto.

- [ ] **Step 5: CSS kecil (opsional, kalau perlu)**

Kalau perlu jarak, tambahkan ke `<style>` checklist.php: `.ck-photo{margin-top:2px}`. (Lewati kalau tampilan sudah rapi.)

- [ ] **Step 6: Lint + commit**

Run: `/opt/homebrew/bin/php -l checklist.php`
```bash
git add checklist.php
git commit -m "feat(checklist): upload foto per item + enforce wajib-foto (klien+server) + thumbnail"
```

---

### Task 4: Deploy + verifikasi

- [ ] **Step 1: Pull + push**

```bash
git pull --no-edit
git push origin main
```

- [ ] **Step 2: Smoke (auth 302, no 500)**

Run: `for p in /checklist /hq/checklist; do curl -s -o /dev/null -w "$p %{http_code}\n" "https://lamasy.harpy.id$p"; done`
Expected: `302` (auth), bukan 500.

- [ ] **Step 3: E2E manual (user)**

1. HQ → Checklist → buat template, centang "📷 foto" di 1 item, simpan.
2. Outlet → Checklist → isi: centang item wajib-foto TANPA foto → Simpan → diblok ("wajib lampirkan foto").
3. Lampirkan foto → thumbnail muncul → Simpan → tersimpan.
4. Cek foto tampil saat buka ulang checklist hari itu.

---

## Self-Review

**1. Spec coverage:**
- `photo` per item di items_json → Task 1 (saveTemplate). ✅
- `foto_url` di answers_json → Task 3 (submitCk). ✅
- Builder checkbox wajib-foto + load saat edit → Task 2. ✅
- Upload endpoint reuse FileUpload + path `uploads/foto_checklist/` → Task 3 Step 1. ✅
- Enforce klien (blok simpan) → Task 3 Step 4. ✅
- Enforce server (validateAnswers otoritatif vs template) → Task 1 Step 4 + Task 3 Step 2 (sanitasi path). ✅
- Foto wajib hanya item checked → `validateAnswers` (`$isChecked && empty foto_url`) + klien (`checked && photo && !foto_url`). ✅
- Anti-XSS path (awalan uploads/foto_checklist/) → Task 3 Step 2. ✅
- CSRF → upload + submit kirim X-CSRF-Token / verifyCsrf. ✅
- Thumbnail di tampilan → Task 3 Step 3 (render fotoUrl). ✅
- Testing (unit validateAnswers + E2E) → Task 1 test + Task 4 Step 3. ✅
- Out of scope (multi-foto, crop, kompresi) → tak ada task. ✅

**2. Placeholder scan:** Tak ada "TBD/TODO". Beberapa "Implementer: konfirmasi ROOT/$tid/$oid/nama variabel template global" → arahan eksplisit cek nama nyata, bukan placeholder kode (kode contoh lengkap diberikan).

**3. Type consistency:** `photo` (0|1) konsisten Task 1 (save) ↔ Task 2 (builder) ↔ Task 3 (render `it.photo`). `foto_url` konsisten Task 3 (answers) ↔ Task 1 (`validateAnswers` baca `ans['foto_url']`) ↔ Task 3 Step 2 (sanitasi). `validateAnswers(items,answers):int` dipakai Task 1 submit. Endpoint `upload_foto` return `{path}` ↔ `ckUploadFoto` baca `d.path`. Path awalan `uploads/foto_checklist/` konsisten Step 1 (upload dir) ↔ Step 2 (validasi).

# Checklist — Opsi "Wajib Foto" per Item — Design Spec

> LaMaSy. Tanggal: 2026-06-29.

## Goal

Saat owner/manager membuat/edit template checklist, tiap item bisa ditandai **"wajib foto"**. Saat staff mengisi checklist, item wajib-foto yang **dicentang** harus melampirkan foto — kalau belum, simpan diblok. Memberi bukti visual (mis. "foto mesin bersih", "foto area kering").

## Keputusan Desain

- **Granularitas: per item** (konsisten dengan flag `required` yang sudah ada per item).
- **Enforcement: blok simpan** — item wajib-foto yang dicentang tapi tanpa foto → tak bisa Simpan (validasi klien + server).
- **Foto wajib hanya untuk item yang DICENTANG** — item tak dicentang (tak dikerjakan) tak perlu foto.
- **Tanpa migrasi DB** — pakai `items_json` (template) & `answers_json` (submission) yang sudah ada.

## Arsitektur

- **Template** `hl_checklist_template.items_json`: array item, tiap item `{text, required, photo}` (tambah `photo: 0|1`).
- **Submission** `hl_checklist_submission.answers_json`: objek keyed index item, tiap entry `{checked, foto_url}` (tambah `foto_url`).
- **Upload foto:** endpoint baru `checklist.php?action=upload_foto` (reuse pola `produksi.php` upload_foto: terima file gambar, simpan ke folder upload, balikin URL). Tenant/outlet-scoped.
- **Builder UI:** `hq/checklist.php` — checkbox "📷 Wajib foto" per baris item.
- **Fill UI:** `checklist.php` — kontrol upload foto + thumbnail per item wajib-foto.

## Komponen & File

- `core/Checklist.php` (MODIFY): `saveTemplate` simpan `photo` per item (kalau saat ini menyimpan array item apa adanya, mungkin tak perlu ubah — verifikasi); `submit` validasi server: tolak kalau item `photo==1` & `checked` & `foto_url` kosong (cek terhadap `items_json` template, bukan dari klien).
- `hq/checklist.php` (MODIFY): `addItem(text, required, photo)` + checkbox 📷 di item-row + sertakan `photo` saat kumpulkan items untuk save.
- `checklist.php` (MODIFY): render item wajib-foto dengan tombol upload + thumbnail + badge; `submitCk` kumpulkan `foto_url` per item + validasi klien (blok kalau wajib-foto tercentang tanpa foto); endpoint `action=upload_foto`.

## Data — Format

Template item (items_json):
```json
{ "text": "Mesin cuci bersih", "required": 1, "photo": 1 }
```
Submission answer (answers_json), keyed by item index:
```json
{ "0": { "checked": true, "foto_url": "/uploads/checklist/abc123.jpg" }, "1": { "checked": false } }
```

## Alur

1. **Buat/edit template** (hq/checklist.php): tiap item ada 2 checkbox — "wajib" (required) & "📷 wajib foto" (photo). Simpan → items_json menyimpan `photo`.
2. **Isi checklist** (checklist.php, staff): item `photo:1` tampil badge "📷 wajib foto" + tombol "Ambil/Upload Foto". Pilih foto → POST ke `action=upload_foto` → dapat URL → simpan ke state `answers[i].foto_url` + tampil thumbnail.
3. **Simpan checklist**: klien cek tiap item `photo:1` yang `checked` — kalau `foto_url` kosong → tolak + toast "Item '<text>' wajib lampirkan foto". Lolos → POST `action=submit` dengan answers (termasuk foto_url).
4. **Server** `Checklist::submit`: muat `items_json` template; untuk tiap item `photo==1`, kalau `answers[i].checked` true & `foto_url` kosong → throw error (tak tersimpan). Lolos → simpan answers_json.
5. **Laporan/compliance**: tampilkan thumbnail `foto_url` di detail submission.

## Upload Endpoint (`checklist.php?action=upload_foto`)

- Guard tenant + `verifyCsrf()` (CSRF auto via interceptor).
- Terima `multipart/form-data` file `foto`. Validasi: tipe image (jpg/png/webp), ukuran maks (mis. 5MB).
- Simpan ke folder upload (ikuti pola produksi — folder + nama acak), balikin `{ok:true, url}`.
- Best-effort error → JSON `{error}`.

## Error Handling

| Kondisi | Perilaku |
|---|---|
| Item wajib-foto dicentang, foto kosong (klien) | Toast "Item '<text>' wajib foto", simpan dibatalkan |
| Sama, lolos klien tapi server cek | `submit` throw → JSON error, tak tersimpan |
| Upload gagal (tipe/ukuran/IO) | Toast error, foto tak ter-set |
| Item wajib-foto TIDAK dicentang | Tak perlu foto (lolos) |
| Item bukan wajib-foto | Tak ada kontrol foto |

## Testing

- **Unit (`Checklist::submit` validasi foto):** (a) item photo+checked+ada foto_url → lolos; (b) item photo+checked+TANPA foto_url → throw/error; (c) item photo+TIDAK checked tanpa foto → lolos; (d) item non-photo → lolos apa adanya. Pakai mock/array template items + answers.
- **Lint** file yang disentuh.
- **Manual E2E:** buat template item wajib-foto → isi checklist: centang tanpa foto → diblok; lampirkan foto → tersimpan; lihat thumbnail di laporan.

## Out of Scope

- Multi-foto per item (cukup 1 foto/item).
- Edit/crop foto in-app.
- Foto wajib untuk item yang tak dicentang.
- Kompresi foto sisi server (andalkan ukuran upload wajar; bisa ditambah nanti).

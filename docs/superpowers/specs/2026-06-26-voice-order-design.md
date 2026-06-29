# Voice Order (POS) — Design Spec

> LaMaSy native app (Capacitor 7). Tanggal: 2026-06-26.

## Goal

Kasir bisa membuat order POS lewat **suara** (Bahasa Indonesia): tekan tombol 🎤 → ngomong → AI ubah jadi field order terstruktur → modal konfirmasi → isi form POS → kasir cek & Simpan manual. Mempercepat input & hands-free saat sibuk. **Tidak pernah auto-submit.**

## Arsitektur

- **STT (suara→teks):** plugin native `@capacitor-community/speech-recognition` (`id-ID`). App-only — WebView biasa tak punya STT andal; di browser tombol mic disembunyikan, kasir ketik manual.
- **Parse (teks→order):** AI server-side. Transcript + katalog layanan tenant dikirim ke AI → JSON terstruktur. AI **hanya** boleh pilih `layanan_id` dari katalog (anti-halusinasi harga).
- **Konfirmasi:** modal "Yang Saya Dengar" (transcript + field terdeteksi + peringatan unmatched) → Terapkan/Ulangi/Batal.
- **Isi form:** Terapkan → isi `f_nama`, push item via `addLayananItem()` (harga dari katalog lokal), set bayar → `recalc()`. Kasir Simpan manual.

## Tech Stack

- App: `@capacitor-community/speech-recognition` (Capacitor 7), permission `RECORD_AUDIO`.
- Server: PHP 8 / MariaDB. Reuse infra AI yang ada (`core/AIChatData.php` / HttpClient AI), `core/AIRateLimiter.php`, `CoinLedger::deduct`, katalog `hl_layanan`.
- Frontend: `pos.php` (tombol mic + modal + isi-form), `components.php` bila perlu helper global.

## Global Constraints

- Multi-tenant: katalog & semua query scoping `tenant_id` (+ `outlet_id` bila relevan).
- AI **tidak boleh mengarang layanan/harga**: hanya pilih `layanan_id` dari katalog yang dikirim; server validasi ulang (buang id tak valid, tenant-scoped).
- Harga & total **selalu** dari katalog DB via `addLayananItem()`+`recalc()`, tak pernah dari AI.
- **Tak pernah auto-submit** — form diisi, kasir review & tekan Simpan sendiri.
- **Potong coin hanya kalau sukses** (≥1 item valid). Gagal STT/AI/JSON/0-item → coin TIDAK dipotong.
- Rate limit: cek `AIRateLimiter` sebelum panggil AI (limit harian per tenant, pola endpoint AI yang ada).
- Endpoint POST baru WAJIB `verifyCsrf()` (token auto via interceptor global).
- Tombol mic **app-only** (cek `window.Capacitor.Plugins.SpeechRecognition`); browser disembunyikan. Offline → mic disable + toast "Butuh internet".

## Scope (MVP)

Voice mengisi: **nama pelanggan**, **layanan + qty** (dari katalog), **pembayaran (status + metode)**.
Di luar scope MVP: no. telepon, parfum/pewangi, foto, express tier, diskon, antar-jemput, pemilihan pelanggan lama otomatis (hanya isi teks nama).

## Komponen & File

- `~/Documents/lamasy-app/` (MODIFY): tambah `@capacitor-community/speech-recognition`, `cap sync`, build APK. Verifikasi `RECORD_AUDIO` di manifest.
- `api/voice_order_parse.php` (NEW): endpoint thin — guard + CSRF + rate-limit + ambil katalog + panggil AI + validasi + potong coin + balas JSON.
- `core/VoiceOrderParser.php` (NEW): logika parse — build prompt, panggil AI, decode + validasi JSON terhadap katalog tenant. Testable (terima katalog + transcript + closure AI, atau method statik yang bisa di-unit-test untuk validasi/filter).
- `pos.php` (MODIFY): tombol 🎤 (app-only), fungsi rekam (start/stop STT), modal konfirmasi, fungsi Terapkan (isi form).
- Coin pricing: daftarkan tipe biaya `ai_voice_order` (CoinLedger COSTS + seed `saas_coin_pricing`, nilai diatur SA).

## Alur Detail

1. Tap 🎤 → `SpeechRecognition.requestPermissions()` → kalau granted `SpeechRecognition.start({language:'id-ID', popup:false, partialResults:false})`.
2. Transcript didapat → kalau kosong: toast "Tak terdengar, coba lagi" (stop, no coin).
3. `POST /api/voice_order_parse.php { transcript }`.
4. Server: `tenant_guard` → `verifyCsrf()` → `AIRateLimiter` check (limit/coin) → ambil katalog layanan aktif tenant → `VoiceOrderParser::parse(transcript, katalog)` → AI → decode JSON → validasi (filter `layanan_id` ke katalog tenant) → jika ≥1 item valid: `CoinLedger::deduct('ai_voice_order')` → balas `{ok:true, heard, parsed, unmatched}`. Jika gagal/0-item: `{ok:false, reason}` (coin tak dipotong).
5. App: tampilkan modal konfirmasi.
6. Terapkan → isi `f_nama` = parsed.nama; tiap item → `addLayananItem(layanan_id, nama, satuan, harga)` (harga dari katalog lokal `layananAll`); set field bayar (status+metode) bila ada → `recalc()` → tutup modal.
7. Kasir cek form & **Simpan** (alur normal POS).

## Kontrak JSON (server ↔ app)

Respons sukses:
```json
{
  "ok": true,
  "heard": "Pak Heri cuci setrika reguler 3 kilo lunas tunai",
  "parsed": {
    "nama": "Heri",
    "items": [ { "layanan_id": 12, "nama_katalog": "Cuci Setrika Reguler", "qty": 3 } ],
    "bayar": { "status": "lunas", "metode": "tunai" }
  },
  "unmatched": ["pewangi premium"]
}
```
- `bayar.status` ∈ `belum_bayar|dp|lunas`; `metode` ∈ daftar metode tenant atau `null`.
- `items[].layanan_id` dijamin valid (sudah difilter server terhadap katalog tenant).
- Respons gagal: `{ "ok": false, "reason": "no_speech|ai_error|invalid_json|no_match|rate_limited|insufficient_coin" }`.

## Error Handling

| Kondisi | Perilaku | Coin |
|---|---|---|
| Izin mic ditolak | Toast "Izinkan akses mikrofon di Settings" | — |
| Transcript kosong | Toast "Tak terdengar, coba lagi" | tak dipotong |
| Rate limit habis | Toast "Limit AI harian tercapai" | tak dipotong |
| Coin tak cukup | Toast "Coin tak cukup untuk fitur AI" | tak dipotong |
| AI gagal / JSON invalid | Toast "Gagal memproses suara, coba lebih jelas" | tak dipotong |
| 0 item cocok | Modal tampilkan unmatched + saran manual | tak dipotong |
| Offline | Mic disable + toast "Butuh internet untuk voice order" | — |
| Sukses (≥1 item) | Modal konfirmasi | dipotong (`ai_voice_order`) |

Semua kegagalan best-effort + `ErrorLogger`; tak pernah meng-crash POS.

## Testing

- **Unit (`VoiceOrderParser`):** (a) JSON valid + semua item ada di katalog → lolos; (b) item dengan `layanan_id` di luar katalog tenant → difilter keluar; (c) JSON invalid → throw/ok=false; (d) 0 item valid → ok=false; (e) `bayar.status`/`metode` di luar enum → di-null-kan. Pakai AI-call yang di-mock (closure/stub) + katalog fixture (PDO sqlite atau array).
- **Manual E2E (device):** ucapkan order → modal muncul transcript + item benar → Terapkan → form terisi, total = harga katalog × qty → Simpan → order tersimpan. Tes: izin mic ditolak, transcript ngawur, layanan tak ada di katalog (unmatched), coin habis.

## Prasyarat

- Build APK baru dengan plugin STT (kamu/agent build di Mac; pola sama seperti push notification).
- Konfigurasi AI sudah aktif (infra `AIChatData` existing).

## Out of Scope (v1)

- Pemilihan pelanggan lama otomatis dari suara (hanya isi teks nama).
- No. telepon / parfum / diskon / express tier via suara.
- Voice untuk halaman selain POS.
- iOS (Android dulu).
- Koreksi/edit transcript manual di modal (cukup Ulangi).

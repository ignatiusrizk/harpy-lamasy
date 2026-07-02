# Laporan Keuangan SuperAdmin — Design Spec

> LaMaSy. Tanggal: 2026-07-02.

## Goal

Satu halaman **Laporan Keuangan** di panel SuperAdmin yang menyatukan angka finansial platform yang sekarang tersebar: **P&L sederhana** (Pendapatan − Biaya AI − Komisi Affiliate = Laba Kotor), **laporan Coin Float** (kewajiban layanan), **rincian transaksi**, dan **export CSV**. Filter periode preset + custom range. Semua biaya diambil otomatis dari DB (tanpa input manual).

## Latar

Panel SA sudah punya **Billing** (Revenue Overview: kartu revenue + grafik batang 6 bulan) dan **Payments** (daftar transaksi + revenue today/total), tapi keduanya **income-only** — tak ada penggabungan pendapatan vs biaya (margin), tak ada laporan coin float, tak ada export. Biaya AI ke-track terpisah di **AI Usage** (`hl_ai_usage.cost_estimated_idr`), komisi affiliate ada di tabelnya sendiri. Halaman ini menyatukannya menjadi laporan laba-rugi ringkas + kewajiban coin.

## Keputusan Desain (terkunci)

- **Sisi biaya P&L: otomatis saja** — Biaya AI + Komisi Affiliate dibayar. Biaya manual (kit/server/gaji/marketing) OUT of scope.
- **Coin float: ringkas** — total coin outstanding + estimasi Rp (rate 4,17/coin) + coin terpakai periode. Tidak memisah coin berbayar vs bonus.
- **Periode: preset + custom range** (Bulan Ini / Bulan Lalu / Tahun Ini + dari–sampai).
- **Export: CSV** berisi ringkasan P&L + coin float + rincian transaksi periode.
- **`billing.php` TIDAK dirombak** — halaman baru berdiri sendiri, memakai helper baru.

## Komponen & File

| File | Aksi | Tanggung jawab |
|---|---|---|
| `core/SaFinance.php` | CREATE | Kelas kalkulasi murni: `revenue()`, `aiCost()`, `affiliatePayout()`, `coinFloat()`, `revenueRows()`. Semua terima `?string $from, ?string $to` (YYYY-MM-DD), kecuali `coinFloat()` (snapshot saat ini). Tidak echo apa pun — return array. |
| `superadmin/laporan-keuangan.php` | CREATE | Page: guard SA, filter periode, render blok P&L + coin float + rincian, handler `action=export` (CSV). Pakai shell `superadmin_components.php`. |
| `superadmin/superadmin_components.php` | MODIFY | Tambah 1 nav link "📑 Laporan Keuangan" di sidebar grup Keuangan (dekat Billing/Payments). |
| `tests/SaFinanceTest.php` | CREATE | Unit test kalkulasi agregat dari row dummy (PDO in-memory / mock). |

## Interface: `core/SaFinance.php`

```php
class SaFinance {
  // Pendapatan gabungan manual(confirmed)+Midtrans(paid) dlm rentang tanggal bayar.
  // Return: ['setup_fee'=>int, 'coin_topup'=>int, 'adjustment'=>int, 'total'=>int, 'coin_credited'=>int]
  public static function revenue(?string $from, ?string $to): array;

  // Biaya AI (hl_ai_usage.cost_estimated_idr) by created_at. Return int (IDR).
  public static function aiCost(?string $from, ?string $to): int;

  // Komisi affiliate status=paid by paid_at (hl_affiliate_payout). Return int (IDR).
  public static function affiliatePayout(?string $from, ?string $to): int;

  // Snapshot kewajiban coin (bukan per periode).
  // Return: ['coin_outstanding'=>int, 'rp_estimate'=>int]  (rate 4.17/coin)
  public static function coinFloat(): array;

  // Coin terpakai (coin_ledger type=deduct) dlm periode. Return int (coin).
  public static function coinConsumed(?string $from, ?string $to): int;

  // Rincian transaksi revenue periode utk tabel & CSV.
  // Return: array<['tanggal','tenant_id','tenant_nama','tipe','nominal','coin']>
  public static function revenueRows(?string $from, ?string $to): array;

  // P&L rollup gabungan (panggil semua di atas sekali).
  // Return: ['revenue'=>array, 'ai_cost'=>int, 'affiliate'=>int,
  //          'total_cost'=>int, 'laba'=>int, 'margin_pct'=>float,
  //          'coin'=>array, 'coin_consumed'=>int]
  public static function pnl(?string $from, ?string $to): array;
}
```

## Sumber Data (terverifikasi ada)

| Angka | Sumber | Filter |
|---|---|---|
| Revenue setup_fee/coin_topup/adjustment | `saas_manual_payments` (status='confirmed', kolom `type`,`nominal_dibayar`,`coin_dikreditkan`,`tanggal_bayar`) **UNION** `saas_payments` (status='paid', `type`,`amount`,`paid_at`; type outlet_activation dipetakan ke 'setup_fee') | tanggal bayar ∈ [from,to] |
| Biaya AI | `hl_ai_usage.cost_estimated_idr` | `created_at` ∈ [from,to] |
| Komisi affiliate | `hl_affiliate_payout` (status='paid', `jumlah`) | `paid_at` ∈ [from,to] |
| Coin outstanding | `SUM(tenants.coin_balance)` | snapshot |
| Coin terpakai | `coin_ledger` (type='deduct', `amount`) | `created_at` ∈ [from,to] |

Rate coin→IDR = **4.17** (samakan dgn `ai_usage.php`; definisikan konstan `SaFinance::COIN_TO_IDR`).

## Alur

1. SA buka `/superadmin/laporan-keuangan.php`. Guard `superadmin_guard` jalan.
2. Default periode = Bulan Ini. Query param `?from=&to=` override; tombol preset set nilai via JS lalu reload.
3. Page panggil `SaFinance::pnl($from,$to)` + `revenueRows()` → render blok P&L, Coin Float, tabel rincian.
4. Tombol **Export CSV** → `?action=export&from=&to=` → `saVerifyCsrf()` (kirim token via header) → stream CSV (ringkasan + rincian), `Content-Disposition: attachment`.

## P&L View (layout)

```
Pendapatan
  Setup Fee ............  Rp xxx
  Top-up Coin ..........  Rp xxx
  Adjustment ...........  Rp xxx
  ── Total Pendapatan ..  Rp XXX
Biaya
  Biaya AI (Anthropic) .  (Rp xxx)
  Komisi Affiliate .....  (Rp xxx)
  ── Total Biaya .......  (Rp XXX)
════════════════════════════════
Laba Kotor Platform ....  Rp XXX   (margin XX%)

Coin Float (estimasi kewajiban layanan)
  Coin outstanding .....  X.XXX.XXX coin
  Estimasi nilai .......  ± Rp xxx
  Coin terpakai periode   X.XXX coin
```

## Error Handling / Edge Cases

| Kondisi | Perilaku |
|---|---|
| Tabel sumber belum ada (mis. `hl_affiliate_payout`) | try/catch → angka 0 (bukan fatal), pola sama `billing.php`. |
| Periode kosong (nol transaksi) | Semua Rp 0, tabel rincian tampil empty-state. |
| `from`/`to` invalid / from>to | Fallback ke Bulan Ini; validasi format `Y-m-d` via `DateTime::createFromFormat`. |
| margin saat revenue 0 | margin_pct = 0 (hindari div-by-zero). |
| CSV tanpa CSRF token | 403 JSON (pola `saVerifyCsrf`). |
| coin rate berubah | Konstanta tunggal `SaFinance::COIN_TO_IDR`, mudah diubah. |

## Keamanan

- Guard `superadmin_guard.php` (auth SA + 2FA) di page & handler export.
- Export pakai `saVerifyCsrf()` + `X-CSRF-Token` dari klien.
- Read-only: page tak menulis DB sama sekali (murni SELECT). Tak ada input user masuk query selain tanggal (di-sanitasi `Y-m-d`).
- Angka revenue = FIKTIF di lingkungan QA (data seed "QA "); di produksi angka riil. Laporan ini internal SA, bukan halaman investor.

## Testing

- **Unit (`SaFinanceTest`)**: seed row dummy ke tabel sumber (atau mock PDO), assert `revenue()`/`aiCost()`/`affiliatePayout()`/`coinFloat()`/`pnl()` menghitung agregat & margin benar; edge: periode kosong (0), revenue 0 (margin 0), tabel absent (0).
- **Manual**: buka page dgn data QA yang sudah di-seed → cek angka konsisten dgn Billing/AI Usage; ganti periode preset & custom; export CSV → buka di Excel, cek ringkasan + rincian.
- **Lint**: `php -l` semua file baru/diubah.

## Out of Scope

- Biaya manual (kit fulfillment, server, gaji, marketing) + UI input-nya.
- MRR/ARR & langganan berulang (belum ada model langganan).
- Pemisahan coin berbayar vs bonus/trial.
- Grafik time-series (sudah ada batang 6 bulan di Billing).
- Refactor `billing.php`/`payments.php`.
- Export PDF/Excel (hanya CSV).

## References

- `superadmin/billing.php` (`smpRevenueSource()` — pola union revenue), `superadmin/payments.php`, `superadmin/ai_usage.php` (rate 4.17, `cost_estimated_idr`), `superadmin/coin_pricing.php` (pola CSRF klien+server).
- [[project-coin-monetization]] — konteks coin & biaya WA.

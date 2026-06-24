# Buku Besar + Laporan Perubahan Modal — Design Spec

**Tanggal:** 24 Juni 2026
**Author:** Ignatius Rizky
**Status:** Draft → Awaiting user review

---

## 1. Tujuan

Lengkapi modul Keuangan LAMASY dengan 2 laporan SAK EMKM yang belum ada (vs Smartlink):

1. **Buku Besar** (General Ledger) — mutasi per akun COA dalam periode
2. **Laporan Perubahan Modal** (Statement of Changes in Equity) — roll-forward ekuitas

Keduanya **additive** — data sudah ada di `hl_jurnal_manual` + COA + hasil `FinancialCalculator`. Tidak ada perubahan schema.

**Scope:**
- 2 method baru di `FinancialCalculator`: `bukuBesar()`, `perubahanModal()`
- 3 action endpoint baru di `hq/keuangan.php`
- 2 sub-tab baru di `hq/laporan.php` (tab Keuangan)
- Buku Besar: pilih akun via dropdown, hybrid (manual per-entry, operasional agregat)

**Out of scope:**
- Posting double-entry penuh semua transaksi operasional ke jurnal (LAMASY tetap model "lightweight" compute-on-read)
- Jurnal Umum view chronological semua posting (beda dari buku besar per-akun)
- Export PDF buku besar (pakai render HTML existing; PDF bisa via print browser kalau perlu, tidak di-scope)
- Buku besar all-account accordion (dipilih: dropdown per-akun)
- Perubahan schema / tabel baru

---

## 2. Background

**Modul Keuangan existing (`core/FinancialCalculator.php` + `hq/keuangan.php` + `hq/laporan.php`):**

Sudah ada (completed 2026-05-30, "Opsi A Lightweight SAK EMKM"):
- Tabel: `hl_coa` (30 seed), `hl_aset_tetap`, `hl_liabilitas`, `hl_jurnal_manual`, `hl_kas_bank`, `hl_kas_bank_mutasi`, `hl_laporan_cache`
- Method: `labaRugi()`, `neraca()`, `arusKas()`, `rasioKeuangan()`, `hitungPenyusutan()`, `hitungNilaiBukuAset()`, `seedCoa()`
- UI sub-tab: Laba Rugi, Neraca, Arus Kas, Rasio
- Helper: `getSaldoManual()` (baca hl_jurnal_manual by tipe), `hitungLabaDitahan()`, `hitungSaldoKasBank()`

**Arsitektur kunci — compute-on-read (bukan double-entry penuh):**
- Transaksi operasional (pendapatan, beban gaji, beban bahan, kas) **TIDAK** di-posting sebagai jurnal. Tersebar di `hl_transaksi`, `hl_kas`, `hl_gaji`, `hl_bahan_mutasi`, lalu di-agregat real-time oleh `labaRugi()`.
- Hanya **adjustment manual** masuk `hl_jurnal_manual` (tipe: modal_disetor, prive, kas_bank, persediaan, biaya_dimuka, pembayaran_hutang, penerimaan_pinjaman, beban_manual, koreksi, lainnya) dengan `coa_id`, `arah` (debit/kredit), `jumlah`, `tanggal`, `periode`.

**`neraca()` sudah hitung seksi ekuitas lengkap** (line 321-335):
- `modal_disetor` = getSaldoManual('modal_disetor', endDate)
- `prive` = getSaldoManual('prive', endDate)
- `laba_ditahan` = hitungLabaDitahan(periode)
- `laba_periode` = labaRugi(periode)['laba_bersih']
- `penyesuaian` = total_aset − total_liab − ekuitas_real (opening balance equity, supaya neraca balance)

**Gap vs Smartlink:**
- Smartlink punya "Buku Besar" + "Perubahan Modal" (mereka full double-entry, semua posting ke jurnal).
- LAMASY belum punya 2 laporan ini. Tapi datanya ada — tinggal disajikan.

---

## 3. Arsitektur

### 3.1 Komponen

**Modified (3 file, no new file, no schema):**
```
core/FinancialCalculator.php  ← + bukuBesar(), + perubahanModal()
hq/keuangan.php               ← + action: laporan_buku_besar, laporan_perubahan_modal, coa_options
hq/laporan.php                ← + 2 sub-tab panel (📖 Buku Besar, 📈 Perubahan Modal) + JS
```

### 3.2 `perubahanModal($tenantId, $outletId, $periode): array`

Re-use komponen ekuitas dari `neraca()`, dipecah jadi "s/d bulan lalu" (modal awal) vs "bulan ini" (mutasi).

```php
public static function perubahanModal(int $tenantId, ?int $outletId, string $periode): array
{
    $start    = $periode . '-01';
    $endDate  = date('Y-m-t', strtotime($start));
    $prevEnd  = date('Y-m-t', strtotime($start . ' -1 month')); // akhir bulan lalu
    $prevPeriode = date('Y-m', strtotime($start . ' -1 month'));

    // Modal awal = akumulasi s/d akhir bulan lalu
    $modalDisetorAwal = self::getSaldoManual($tenantId, $outletId, 'modal_disetor', $prevEnd);
    $priveAwal        = self::getSaldoManual($tenantId, $outletId, 'prive',         $prevEnd);
    $labaDitahanAwal  = self::hitungLabaDitahan($tenantId, $outletId, $periode); // s/d bulan-1
    $modalAwal = $modalDisetorAwal - $priveAwal + $labaDitahanAwal;

    // Mutasi periode ini (delta antara s/d endDate dan s/d prevEnd)
    $setoranPeriode = self::getSaldoManual($tenantId, $outletId, 'modal_disetor', $endDate) - $modalDisetorAwal;
    $privePeriode   = self::getSaldoManual($tenantId, $outletId, 'prive',         $endDate) - $priveAwal;
    $labaPeriode    = self::labaRugi($tenantId, $outletId, $periode)['laba_bersih'];

    $modalAkhir = $modalAwal + $setoranPeriode - $privePeriode + $labaPeriode;

    return [
        'periode'         => $periode,
        'modal_awal'      => $modalAwal,
        'setoran_modal'   => $setoranPeriode,
        'prive'           => $privePeriode,
        'laba_bersih'     => $labaPeriode,
        'modal_akhir'     => $modalAkhir,
    ];
}
```

**Catatan:** `hitungLabaDitahan()` existing me-loop dari transaksi paling awal s/d periode−1 (lihat memori). Untuk modal awal, laba ditahan "s/d bulan lalu" = `hitungLabaDitahan(periode)` itu sendiri (karena fungsi itu sudah exclude periode berjalan). Verifikasi saat implementasi: pastikan definisi `hitungLabaDitahan` cocok (kalau ternyata inklusif periode, sesuaikan).

### 3.3 `bukuBesar($tenantId, $outletId, $periode, $coaId): array`

Input 1 akun COA. Output: info akun + saldo awal + mutasi[] + saldo akhir.

```php
public static function bukuBesar(int $tenantId, ?int $outletId, string $periode, int $coaId): array
{
    $db       = Database::get();
    $start    = $periode . '-01';
    $endDate  = date('Y-m-t', strtotime($start));
    $prevEnd  = date('Y-m-t', strtotime($start . ' -1 month'));

    // Load COA
    $coa = /* SELECT kode, nama, tipe FROM hl_coa WHERE id=? AND tenant_id=? */;
    if (!$coa) throw new RuntimeException('Akun tidak ditemukan');

    $isPnL = in_array($coa['tipe'], ['pendapatan','pendapatan_lain','beban_pokok','beban_operasional','beban_lain'], true);

    $mutasi = [];
    $saldoAwal = 0;

    if ($isPnL) {
        // Akun P&L: saldo awal 0, 1 baris agregat dari labaRugi
        $saldoAwal = 0;
        $agg = self::aggregatePnLForCoa($tenantId, $outletId, $periode, $coa); // helper map labaRugi → akun
        if ($agg !== 0) {
            $mutasi[] = [
                'tanggal'    => $endDate,
                'keterangan' => 'Akumulasi ' . $coa['nama'] . ' periode ' . $periode,
                'debit'      => $coa['tipe'] === 'pendapatan' ? 0 : $agg,
                'kredit'     => $coa['tipe'] === 'pendapatan' ? $agg : 0,
                'saldo'      => $agg,
            ];
        }
    } else {
        // Akun neraca (manual/kas): saldo awal = akumulasi s/d prevEnd, mutasi per-entry
        $saldoAwal = self::saldoAkunManual($tenantId, $outletId, $coaId, $prevEnd);
        $entries = /* SELECT tanggal, keterangan, jumlah, arah FROM hl_jurnal_manual
                      WHERE coa_id=? AND tenant_id=? AND DATE(tanggal) BETWEEN $start AND $endDate
                      [+ outlet filter] ORDER BY tanggal, id */;
        $running = $saldoAwal;
        foreach ($entries as $e) {
            $debit  = $e['arah'] === 'debit'  ? (int)$e['jumlah'] : 0;
            $kredit = $e['arah'] === 'kredit' ? (int)$e['jumlah'] : 0;
            // Saldo berjalan: normal balance per tipe akun (aset/beban naik di debit; liab/ekuitas/pendapatan naik di kredit)
            $running += self::normalSign($coa['tipe']) === 'debit' ? ($debit - $kredit) : ($kredit - $debit);
            $mutasi[] = [
                'tanggal'    => $e['tanggal'],
                'keterangan' => $e['keterangan'],
                'debit'      => $debit,
                'kredit'     => $kredit,
                'saldo'      => $running,
            ];
        }
    }

    $saldoAkhir = $isPnL
        ? array_sum(array_map(fn($m) => $m['saldo'], $mutasi))  // P&L: total agregat
        : ($mutasi ? end($mutasi)['saldo'] : $saldoAwal);

    return [
        'periode'     => $periode,
        'akun'        => $coa,        // kode, nama, tipe
        'is_pnl'      => $isPnL,
        'saldo_awal'  => $saldoAwal,
        'mutasi'      => $mutasi,
        'saldo_akhir' => $saldoAkhir,
    ];
}
```

**Helper baru kecil:**
- `normalSign(string $tipe): string` — return 'debit' untuk aset/beban, 'kredit' untuk liabilitas/ekuitas/pendapatan.
- `saldoAkunManual($tenantId, $outletId, $coaId, $endDate): int` — akumulasi jurnal_manual akun s/d tanggal (untuk saldo awal). Pakai normalSign.
- `aggregatePnLForCoa(...)` — map akun pendapatan/beban ke nilai dari `labaRugi()`. **Catatan:** mapping COA→komponen labaRugi belum ada relasi eksplisit; gunakan pendekatan praktis (lihat §6 Edge Cases). Untuk akun pendapatan utama → total pendapatan; beban → cocokkan by tipe/kode. Kalau tidak ada mapping pasti, tampilkan agregat by tipe akun (semua pendapatan jadi 1, semua beban operasional jadi 1).

### 3.4 `coa_options` (keuangan.php)

Return list COA aktif tenant untuk dropdown:
```sql
SELECT id, kode, nama, tipe FROM hl_coa
WHERE tenant_id=? AND is_active=1 ORDER BY kode
```
Grouped by tipe di UI (optgroup).

---

## 4. UI Spec

### 4.1 Sub-tab Baru

Existing: 📊 Laba Rugi · ⚖️ Neraca · 💧 Arus Kas · 📐 Rasio. Tambah:
```
... │ 📖 Buku Besar │ 📈 Perubahan Modal
```

### 4.2 Buku Besar Panel

```
┌──────────────────────────────────────────────────────────┐
│ 📖 Buku Besar          Periode: [Juni 2026 ▼]            │
│ Pilih Akun: [4-101 · Pendapatan Jasa Laundry ▼]          │
│ ──────────────────────────────────────────────────────── │
│ Saldo Awal (1 Jun 2026)                      Rp 0         │
│ Tgl      Keterangan              Debit   Kredit   Saldo   │
│ 30 Jun   Akumulasi pendapatan…     −    2.450.000 2.450rb │
│ ──────────────────────────────────────────────────────── │
│ Saldo Akhir (30 Jun 2026)                  Rp 2.450.000   │
└──────────────────────────────────────────────────────────┘
```

Akun manual (Prive):
```
│ Saldo Awal                                  Rp 500.000    │
│ 05 Jun  Ambil kas pribadi          −  200.000   700.000   │
│ 20 Jun  Transfer rek pribadi       −  300.000 1.000.000   │
│ Saldo Akhir                                Rp 1.000.000   │
```

Empty: "Tidak ada mutasi pada periode ini." (saldo awal = saldo akhir).

### 4.3 Perubahan Modal Panel

```
┌──────────────────────────────────────────────────────────┐
│ 📈 Laporan Perubahan Modal     Periode: [Juni 2026 ▼]    │
│ Modal Awal (1 Jun 2026)                    Rp 10.000.000  │
│   + Setoran Modal periode ini              Rp  2.000.000  │
│   − Prive periode ini                      Rp    500.000  │
│   + Laba Bersih periode ini                Rp  3.200.000  │
│ ──────────────────────────────────────────────────────── │
│ = Modal Akhir (30 Jun 2026)                Rp 14.700.000  │
│ ℹ️ Modal akhir = total ekuitas di Neraca periode sama     │
└──────────────────────────────────────────────────────────┘
```

Baris dengan nilai 0 tetap ditampilkan (transparansi roll-forward). Negatif (mis. rugi) ditampilkan dengan tanda/warna merah.

### 4.4 AJAX Flow (konsisten existing)

```
laporan.php (sub-tab) → fetch:
  /hq/keuangan.php?action=laporan_buku_besar&periode=YYYY-MM&outlet_id=N&coa_id=X
  /hq/keuangan.php?action=laporan_perubahan_modal&periode=YYYY-MM&outlet_id=N
  /hq/keuangan.php?action=coa_options
Headers: X-Requested-With: XMLHttpRequest, X-CSRF-Token
Returns: {ok: true, data: {...}}
```

---

## 5. Backend Logic (keuangan.php)

Tambah ke handler existing (~line 28, blok `in_array($action, [...])`):

```php
// Tambah ke whitelist action laporan
if (in_array($action, ['laporan_lr','laporan_neraca','laporan_arus_kas','laporan_rasio','laporan_aset',
                       'laporan_buku_besar','laporan_perubahan_modal'], true)) {
    // ... existing periode/outlet parse ...
    switch ($action) {
        // ... existing cases ...
        case 'laporan_buku_besar':
            $coaId = (int)($_GET['coa_id'] ?? 0);
            $data = FinancialCalculator::bukuBesar($tid, $outletId, $periode, $coaId);
            break;
        case 'laporan_perubahan_modal':
            $data = FinancialCalculator::perubahanModal($tid, $outletId, $periode);
            break;
    }
}

// coa_options (terpisah, tidak butuh periode)
if ($action === 'coa_options') {
    $rows = TenantQuery::raw("SELECT id, kode, nama, tipe FROM hl_coa
                              WHERE tenant_id=? AND is_active=1 ORDER BY kode", [$tid]);
    echo json_encode(['ok'=>true, 'data'=>$rows]); exit;
}
```

Permission + CSRF: ikut pola existing di keuangan.php (sudah ada guard).

---

## 6. Edge Cases

| Skenario | Handler |
|----------|---------|
| Akun tanpa mutasi periode | "Tidak ada mutasi", saldo awal = saldo akhir |
| Akun P&L (pendapatan/beban) | Saldo awal 0, 1 baris agregat, saldo akhir = total periode |
| Mapping COA→labaRugi tidak pasti | Agregat by **tipe akun**: semua akun pendapatan share total pendapatan? **NO** — supaya tidak dobel, hanya akun pendapatan utama (kode standar dari seed COA, mis. 4-xxx) yang dapat agregat; akun pendapatan custom tanpa data = 0. Beban: cocokkan by tipe (beban_pokok→beban bahan, beban_operasional→gaji+operasional). Dokumentasikan mapping di method. |
| Periode pertama (belum ada bulan lalu) | Modal awal dihitung dari akumulasi s/d bulan lalu = 0 atau setoran awal yang tanggalnya sebelum periode |
| Perubahan Modal vs Neraca | Modal akhir = ekuitas_real di neraca (TANPA penyesuaian). Tampilkan note. Kalau owner mau cocok 100% dgn neraca yg ada penyesuaian, itu beda konsep — penyesuaian bukan bagian perubahan modal formal |
| Outlet filter | Hormati outlet_id (konsisten LR/Neraca); outlet_id=0/null = konsolidasi |
| Saldo berjalan arah salah | `normalSign()` per tipe akun memastikan debit/kredit naik-turun benar |
| Laba bersih negatif (rugi) | Perubahan modal: laba_bersih negatif → modal turun, tampil merah |

---

## 7. Testing Plan

### 7.1 Smoke Test

1. Buka /hq/laporan → tab Keuangan → sub-tab "📖 Buku Besar" muncul
2. Dropdown akun ter-populate dari COA (grouped by tipe)
3. Pilih akun **Prive** (manual) → tampil saldo awal + entry per baris + saldo berjalan + saldo akhir
4. Pilih akun **Pendapatan** (P&L) → saldo awal 0 + 1 baris agregat + saldo akhir = total pendapatan periode (cocok dgn Laba Rugi)
5. Pilih akun tanpa mutasi → "Tidak ada mutasi"
6. Sub-tab "📈 Perubahan Modal" → roll-forward: modal awal + setoran − prive + laba = modal akhir
7. Verify: modal akhir Perubahan Modal = ekuitas_real Neraca periode sama (tanpa penyesuaian)
8. Ganti periode → kedua laporan update
9. Outlet filter (kalau multi-outlet) → angka per outlet konsisten

### 7.2 Edge Cases

| # | Test | Expected |
|---|------|----------|
| 1 | Akun P&L pendapatan | Saldo akhir = total pendapatan di Laba Rugi |
| 2 | Akun manual prive 2 entry | Saldo berjalan akumulasi benar |
| 3 | Periode rugi | Perubahan modal: laba negatif, modal turun |
| 4 | Akun kosong | "Tidak ada mutasi" |
| 5 | Periode pertama tenant | Modal awal = 0 / setoran awal |
| 6 | Cross-tenant coa_id manipulation | WHERE tenant_id → akun tidak ditemukan / 404 |

---

## 8. Implementation Phasing

3 commits, ~3 jam:

**Commit 1 — FinancialCalculator methods (~90 menit):**
- `perubahanModal()` + `bukuBesar()` + helper `normalSign()`, `saldoAkunManual()`, `aggregatePnLForCoa()`

**Commit 2 — keuangan.php endpoints (~25 menit):**
- 3 action: laporan_buku_besar, laporan_perubahan_modal, coa_options

**Commit 3 — laporan.php UI (~45 menit):**
- 2 sub-tab + panel + JS fetch + render
- Smoke test E2E

---

## 9. Files Inventory

### Modified
- `core/FinancialCalculator.php` — +2 public method +3 private helper
- `hq/keuangan.php` — +3 action endpoint
- `hq/laporan.php` — +2 sub-tab panel + JS

### New
- (none)

### Schema
- (none — semua data sudah ada)

---

## 10. Out of Scope

- Double-entry posting semua transaksi operasional
- Jurnal Umum (chronological all-postings view)
- Buku besar all-account accordion (dipilih dropdown per-akun)
- Export PDF dedicated (bisa print browser)
- Tabel/schema baru

---

## 11. Success Criteria

- ✅ Buku Besar per akun: pilih dropdown → saldo awal + mutasi + saldo akhir
- ✅ Akun manual tampil per-entry dgn saldo berjalan; akun P&L tampil agregat
- ✅ Perubahan Modal: roll-forward modal awal → akhir benar
- ✅ Modal akhir konsisten dengan ekuitas di Neraca (tanpa penyesuaian)
- ✅ Zero regression: LR/Neraca/Arus Kas/Rasio existing tidak terpengaruh
- ✅ No schema change, no new table

---

## 12. References

- `core/FinancialCalculator.php` — `neraca()` (ekuitas section, line 321-374), `labaRugi()`, `getSaldoManual()`, `hitungLabaDitahan()`
- `hq/keuangan.php` — action handler pattern (line 28+)
- `hq/laporan.php` — sub-tab Keuangan + `switchKeuTab()` JS (line 927+)
- Schema: `hl_jurnal_manual` (coa_id, arah, jumlah, tanggal, tipe), `hl_coa` (kode, nama, tipe)
- Memori: `project_keuangan.md` (arsitektur lightweight, compute-on-read)

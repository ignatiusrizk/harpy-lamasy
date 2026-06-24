# Buku Besar + Laporan Perubahan Modal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tambah 2 laporan ke modul Keuangan: Buku Besar (general ledger per akun COA, pilih via dropdown) + Laporan Perubahan Modal (roll-forward ekuitas). Additive, no schema change.

**Architecture:** 2 method baru di FinancialCalculator (re-use `getSaldoManual`/`hitungLabaDitahan`/`labaRugi` existing). 3 action endpoint di keuangan.php. 2 sub-tab di laporan.php. Buku Besar hybrid: akun manual per-entry dari hl_jurnal_manual by coa_id, akun P&L agregat dari labaRugi via explicit kode-map.

**Tech Stack:** PHP 8 vanilla, MariaDB, pola AJAX existing (keuangan.php action + laporan.php switchKeuTab JS).

## Global Constraints

- **No schema change, no new file.** Edit 3 file: core/FinancialCalculator.php, hq/keuangan.php, hq/laporan.php.
- **Data source Buku Besar:** akun manual → `hl_jurnal_manual` WHERE `coa_id`. Akun P&L → `labaRugi()` via kode-map.
- **`hl_jurnal_manual` punya `coa_id` + `arah` (debit/kredit) + `jumlah` + `tanggal` + `tipe`.** getSaldoManual existing query by `tipe`; Buku Besar query by `coa_id`.
- **`hitungLabaDitahan($t,$o,$periode)`** = akumulasi laba s/d periode−1 (exclude periode berjalan). Verified.
- **`getSaldoManual($t,$o,$tipe,$endDate)`** = SUM(kredit − debit) s/d endDate, floored `max(0,...)`.
- **`labaRugi()` return:** `['total_pendapatan', 'pendapatan'=>['kiloan','b2b','drop_point','lain'], 'total_beban', 'beban'=>['gaji','komisi_mitra','operasional_kas','penyusutan','bunga','bahan_baku','manual',...], 'laba_bersih']`.
- **COA kode standar (seed):** 1-xxxx aset, 2-xxxx liabilitas, 3-1001 modal disetor / 3-1002 laba ditahan / 3-1003 prive, 4-xxxx pendapatan, 5-xxxx beban.
- **Outlet scope:** semua method terima `?int $outletId` (null/0 = konsolidasi), konsisten LR/Neraca.
- **Modal akhir Perubahan Modal = ekuitas_real (TANPA penyesuaian).**
- **Smoke test** (no unit framework): browser + DB query + cross-check dgn LR/Neraca.

---

## File Structure

**Modified:**
- `core/FinancialCalculator.php` — +`perubahanModal()`, +`bukuBesar()`, +helper `normalSign()`, `saldoAkunByCoa()`, `aggregatePnLForCoa()`
- `hq/keuangan.php` — +action `laporan_buku_besar`, `laporan_perubahan_modal`, `coa_options`
- `hq/laporan.php` — +2 sub-tab (📖 Buku Besar, 📈 Perubahan Modal) + panel + JS

---

## Task 1: FinancialCalculator — perubahanModal + bukuBesar

**Files:**
- Modify: `core/FinancialCalculator.php`

**Interfaces:**
- Consumes: existing `getSaldoManual()`, `hitungLabaDitahan()`, `labaRugi()`, `o()` (outlet filter)
- Produces:
  - `perubahanModal(int $tenantId, ?int $outletId, string $periode): array` → `{periode, modal_awal, setoran_modal, prive, laba_bersih, modal_akhir}`
  - `bukuBesar(int $tenantId, ?int $outletId, string $periode, int $coaId): array` → `{periode, akun:{id,kode,nama,tipe}, is_pnl, saldo_awal, mutasi:[{tanggal,keterangan,debit,kredit,saldo}], saldo_akhir}`
  - private `normalSign(string $tipe): string`, `saldoAkunByCoa(int $t, ?int $o, int $coaId, string $endDate, string $normalSign): int`, `aggregatePnLForCoa(int $t, ?int $o, string $periode, string $kode): int`

- [ ] **Step 1: Add `normalSign()` private helper**

Letakkan di FinancialCalculator (dekat helper `o()` di atas). Akun aset & beban normal balance di debit; liabilitas/ekuitas/pendapatan di kredit:

```php
// Normal balance side per tipe akun COA
private static function normalSign(string $tipe): string
{
    return in_array($tipe, ['aset_lancar','aset_tetap','beban_pokok','beban_operasional','beban_lain'], true)
        ? 'debit' : 'kredit';
}
```

- [ ] **Step 2: Add `saldoAkunByCoa()` private helper**

Akumulasi saldo akun (by coa_id) dari hl_jurnal_manual s/d endDate, mengikuti normal balance:

```php
// Saldo akun (by coa_id) s/d endDate dari jurnal manual, sesuai normal balance.
private static function saldoAkunByCoa(int $tenantId, ?int $outletId, int $coaId, string $endDate, string $normalSign): int
{
    $of = self::o($outletId);
    try {
        // kredit-debit kalau normal kredit; debit-kredit kalau normal debit
        $expr = $normalSign === 'debit'
            ? "SUM(CASE WHEN arah='debit' THEN jumlah ELSE -jumlah END)"
            : "SUM(CASE WHEN arah='kredit' THEN jumlah ELSE -jumlah END)";
        $s = Database::get()->prepare("
            SELECT COALESCE($expr, 0)
            FROM hl_jurnal_manual
            WHERE tenant_id=? AND coa_id=? AND DATE(tanggal) <= ? $of
        ");
        $s->execute([$tenantId, $coaId, $endDate]);
        return (int)$s->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}
```

- [ ] **Step 3: Add `aggregatePnLForCoa()` private helper (explicit kode-map)**

Map kode COA P&L → komponen `labaRugi()`. Kode tanpa sumber langsung (sewa/utilitas/pemasaran) → ambil dari `operasional_kas` hanya untuk catch-all `5-1099`, lainnya 0 (hindari dobel hitung):

```php
// Nilai agregat periode untuk akun P&L (pendapatan/beban) by kode COA.
// Map ke komponen labaRugi(). Kode tanpa sumber langsung → 0 (kecuali catch-all).
private static function aggregatePnLForCoa(int $tenantId, ?int $outletId, string $periode, string $kode): int
{
    $lr = self::labaRugi($tenantId, $outletId, $periode);
    $map = [
        '4-1001' => $lr['pendapatan']['kiloan']      ?? 0,
        '4-1002' => $lr['pendapatan']['b2b']         ?? 0,
        '4-1003' => $lr['pendapatan']['drop_point']  ?? 0,
        '4-1099' => $lr['pendapatan']['lain']        ?? 0,
        '5-1001' => $lr['beban']['gaji']             ?? 0,
        '5-1002' => $lr['beban']['bahan_baku']       ?? 0,
        '5-1005' => $lr['beban']['penyusutan']       ?? 0,
        '5-1006' => $lr['beban']['bunga']            ?? 0,
        '5-1008' => $lr['beban']['komisi_mitra']     ?? 0,
        // 5-1099 catch-all: kas keluar operasional + beban manual (yang tak terpetakan)
        '5-1099' => ($lr['beban']['operasional_kas'] ?? 0) + ($lr['beban']['manual'] ?? 0),
        // 5-1003 sewa, 5-1004 utilitas, 5-1007 pemasaran → tidak terpisah di labaRugi → 0
    ];
    return (int)($map[$kode] ?? 0);
}
```

- [ ] **Step 4: Add `perubahanModal()` public method**

```php
// ════════════════════════════════════════════════════════════
// LAPORAN PERUBAHAN MODAL
// ════════════════════════════════════════════════════════════
public static function perubahanModal(int $tenantId, ?int $outletId, string $periode): array
{
    $start   = $periode . '-01';
    $endDate  = date('Y-m-t', strtotime($start));
    $prevEnd  = date('Y-m-t', strtotime($start . ' -1 month'));

    // Akumulasi s/d akhir bulan lalu (modal awal)
    $modalDisetorAwal = self::getSaldoManual($tenantId, $outletId, 'modal_disetor', $prevEnd);
    $priveAwal        = self::getSaldoManual($tenantId, $outletId, 'prive',         $prevEnd);
    $labaDitahanAwal  = self::hitungLabaDitahan($tenantId, $outletId, $periode); // s/d periode-1
    $modalAwal = $modalDisetorAwal - $priveAwal + $labaDitahanAwal;

    // Mutasi periode ini (delta s/d endDate vs s/d prevEnd)
    $setoranPeriode = self::getSaldoManual($tenantId, $outletId, 'modal_disetor', $endDate) - $modalDisetorAwal;
    $privePeriode   = self::getSaldoManual($tenantId, $outletId, 'prive',         $endDate) - $priveAwal;
    $labaPeriode    = self::labaRugi($tenantId, $outletId, $periode)['laba_bersih'];

    $modalAkhir = $modalAwal + $setoranPeriode - $privePeriode + $labaPeriode;

    return [
        'periode'       => $periode,
        'modal_awal'    => $modalAwal,
        'setoran_modal' => $setoranPeriode,
        'prive'         => $privePeriode,
        'laba_bersih'   => $labaPeriode,
        'modal_akhir'   => $modalAkhir,
    ];
}
```

- [ ] **Step 5: Add `bukuBesar()` public method**

```php
// ════════════════════════════════════════════════════════════
// BUKU BESAR (per akun COA)
// ════════════════════════════════════════════════════════════
public static function bukuBesar(int $tenantId, ?int $outletId, string $periode, int $coaId): array
{
    $db      = Database::get();
    $start   = $periode . '-01';
    $endDate = date('Y-m-t', strtotime($start));
    $prevEnd = date('Y-m-t', strtotime($start . ' -1 month'));
    $of      = self::o($outletId);

    // Load COA (tenant scope)
    $c = $db->prepare("SELECT id, kode, nama, tipe FROM hl_coa WHERE id=? AND tenant_id=? LIMIT 1");
    $c->execute([$coaId, $tenantId]);
    $coa = $c->fetch(PDO::FETCH_ASSOC);
    if (!$coa) throw new RuntimeException('Akun tidak ditemukan');

    $isPnL = in_array($coa['tipe'], ['pendapatan','pendapatan_lain','beban_pokok','beban_operasional','beban_lain'], true);
    $normalSign = self::normalSign($coa['tipe']);

    $mutasi = [];
    $saldoAwal = 0;

    if ($isPnL) {
        // P&L: saldo awal 0, 1 baris agregat
        $agg = self::aggregatePnLForCoa($tenantId, $outletId, $periode, $coa['kode']);
        if ($agg != 0) {
            $isPendapatan = in_array($coa['tipe'], ['pendapatan','pendapatan_lain'], true);
            $mutasi[] = [
                'tanggal'    => $endDate,
                'keterangan' => 'Akumulasi ' . $coa['nama'] . ' periode ' . $periode,
                'debit'      => $isPendapatan ? 0 : $agg,
                'kredit'     => $isPendapatan ? $agg : 0,
                'saldo'      => $agg,
            ];
        }
        $saldoAkhir = $agg;
    } else {
        // Neraca (manual): saldo awal = akumulasi s/d prevEnd, mutasi per-entry
        $saldoAwal = self::saldoAkunByCoa($tenantId, $outletId, $coaId, $prevEnd, $normalSign);
        $st = $db->prepare("
            SELECT tanggal, keterangan, jumlah, arah
            FROM hl_jurnal_manual
            WHERE tenant_id=? AND coa_id=? AND DATE(tanggal) BETWEEN ? AND ? $of
            ORDER BY tanggal ASC, id ASC
        ");
        $st->execute([$tenantId, $coaId, $start, $endDate]);
        $running = $saldoAwal;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $debit  = $e['arah'] === 'debit'  ? (int)$e['jumlah'] : 0;
            $kredit = $e['arah'] === 'kredit' ? (int)$e['jumlah'] : 0;
            $running += $normalSign === 'debit' ? ($debit - $kredit) : ($kredit - $debit);
            $mutasi[] = [
                'tanggal'    => $e['tanggal'],
                'keterangan' => $e['keterangan'],
                'debit'      => $debit,
                'kredit'     => $kredit,
                'saldo'      => $running,
            ];
        }
        $saldoAkhir = $running;
    }

    return [
        'periode'     => $periode,
        'akun'        => $coa,
        'is_pnl'      => $isPnL,
        'saldo_awal'  => $saldoAwal,
        'mutasi'      => $mutasi,
        'saldo_akhir' => $saldoAkhir,
    ];
}
```

- [ ] **Step 6: Syntax check via DB-driven smoke (no php CLI lokal)**

Karena tidak ada `php` CLI lokal (verified sesi ini), verifikasi via deploy + browser di Task 4. Untuk sekarang, re-baca method yang ditulis: pastikan kurung tutup balance, return array keys sesuai Interfaces, tidak ada typo nama helper.

Run grep konsistensi:
```bash
grep -nE "function perubahanModal|function bukuBesar|function normalSign|function saldoAkunByCoa|function aggregatePnLForCoa" core/FinancialCalculator.php
```
Expected: 5 baris (3 helper + 2 public).

- [ ] **Step 7: Commit**

```bash
git add core/FinancialCalculator.php
git commit -m "feat(keuangan): perubahanModal + bukuBesar di FinancialCalculator

perubahanModal(): roll-forward ekuitas — modal awal (s/d bulan lalu) +
setoran - prive + laba bersih = modal akhir. Re-use getSaldoManual +
hitungLabaDitahan + labaRugi existing.

bukuBesar(): per akun COA. Akun manual (aset/liab/ekuitas) → per-entry
dari hl_jurnal_manual by coa_id + saldo berjalan (normalSign). Akun P&L
(pendapatan/beban) → 1 baris agregat via aggregatePnLForCoa (explicit
kode-map ke komponen labaRugi).

Helper: normalSign, saldoAkunByCoa, aggregatePnLForCoa. No schema change."
```

---

## Task 2: keuangan.php — 3 Action Endpoint

**Files:**
- Modify: `hq/keuangan.php`

**Interfaces:**
- Consumes: `FinancialCalculator::bukuBesar()`, `perubahanModal()` (Task 1)
- Produces: AJAX endpoints `laporan_buku_besar`, `laporan_perubahan_modal`, `coa_options` (JSON `{ok, data}`)

- [ ] **Step 1: Tambah ke whitelist action laporan**

Di hq/keuangan.php (~line 28), find:
```php
if (in_array($action, ['laporan_lr','laporan_neraca','laporan_arus_kas','laporan_rasio','laporan_aset'], true)) {
```
Ganti jadi:
```php
if (in_array($action, ['laporan_lr','laporan_neraca','laporan_arus_kas','laporan_rasio','laporan_aset','laporan_buku_besar','laporan_perubahan_modal'], true)) {
```

- [ ] **Step 2: Tambah 2 case di switch**

Di switch action existing (setelah `case 'laporan_aset':`), tambah:
```php
                case 'laporan_buku_besar':
                    $coaId = (int)($_GET['coa_id'] ?? 0);
                    $data = FinancialCalculator::bukuBesar($tid, $outletId, $periode, $coaId);
                    break;
                case 'laporan_perubahan_modal':
                    $data = FinancialCalculator::perubahanModal($tid, $outletId, $periode);
                    break;
```

- [ ] **Step 3: Tambah action `coa_options` (terpisah, tanpa periode)**

Tambah sebagai blok action baru (mis. setelah blok `list_coa` existing). Pakai pola query existing di file:
```php
if ($action === 'coa_options') {
    header('Content-Type: application/json');
    try {
        $s = $db->prepare("SELECT id, kode, nama, tipe FROM hl_coa
                           WHERE tenant_id=? AND is_active=1 ORDER BY kode");
        $s->execute([$tid]);
        echo json_encode(['ok'=>true, 'data'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
    }
    exit;
}
```
(Verifikasi: `$db` + `$tid` sudah tersedia di scope action keuangan.php — konsisten dgn action lain seperti `list_coa`.)

- [ ] **Step 4: Verify via grep**

```bash
grep -nE "laporan_buku_besar|laporan_perubahan_modal|coa_options" hq/keuangan.php
```
Expected: whitelist (2) + switch case (2) + coa_options block (1+) hits.

- [ ] **Step 5: Commit**

```bash
git add hq/keuangan.php
git commit -m "feat(keuangan): endpoint buku besar + perubahan modal + coa_options

3 action AJAX baru: laporan_buku_besar (butuh coa_id), laporan_perubahan_modal,
coa_options (dropdown akun). Ikut pola action + CSRF + tenant scope existing."
```

---

## Task 3: laporan.php — 2 Sub-tab UI + JS

**Files:**
- Modify: `hq/laporan.php`

**Interfaces:**
- Consumes: endpoints Task 2 (`laporan_buku_besar`, `laporan_perubahan_modal`, `coa_options`), JS helper existing `keuParams()`, `switchKeuTab()`, `fmtRp()`/`esc()`
- Produces: 2 sub-tab + panel render

- [ ] **Step 1: Tambah 2 tombol sub-tab**

Di blok `.keu-subtabs` (~line 927-931), setelah tombol Rasio, tambah:
```php
      <button class="keu-stab" onclick="switchKeuTab('bukubesar',this)">📖 Buku Besar</button>
      <button class="keu-stab" onclick="switchKeuTab('perubahanmodal',this)">📈 Perubahan Modal</button>
```

- [ ] **Step 2: Tambah 2 panel div**

Setelah panel Rasio/Aset existing (sekitar line 969), tambah:
```php
    <!-- Buku Besar panel -->
    <div id="keuPanelBukubesar" class="panel" style="display:none;margin-bottom:18px">
      <div class="panel-title">📖 Buku Besar
        <span style="font-size:11px;font-weight:400;color:#9CA3AF">Mutasi per akun</span>
      </div>
      <div style="margin:10px 0">
        <label style="font-size:13px;color:#6B7280;margin-right:8px">Pilih Akun:</label>
        <select id="bbCoaSelect" onchange="loadBukuBesar()" style="padding:8px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;min-width:280px">
          <option value="">— Memuat akun… —</option>
        </select>
      </div>
      <div id="keuBukubesarContent"><div style="color:#9CA3AF;text-align:center;padding:30px">Pilih akun untuk lihat mutasi.</div></div>
    </div>

    <!-- Perubahan Modal panel -->
    <div id="keuPanelPerubahanmodal" class="panel" style="display:none;margin-bottom:18px">
      <div class="panel-title">📈 Laporan Perubahan Modal</div>
      <div id="keuPerubahanmodalContent"><div style="color:#9CA3AF;text-align:center;padding:30px">Memuat…</div></div>
    </div>
```

- [ ] **Step 3: Update `switchKeuTab` panel map**

Find (~line 1376):
```javascript
  const map = {lr:'keuPanelLr',neraca:'keuPanelNeraca',arus:'keuPanelArus',rasio:'keuPanelRasio',aset:'keuPanelAset'};
```
Ganti:
```javascript
  const map = {lr:'keuPanelLr',neraca:'keuPanelNeraca',arus:'keuPanelArus',rasio:'keuPanelRasio',aset:'keuPanelAset',bukubesar:'keuPanelBukubesar',perubahanmodal:'keuPanelPerubahanmodal'};
```

- [ ] **Step 4: Tambah dispatch fetch di switchKeuTab**

Di switchKeuTab, setelah panel ditampilkan + sebelum/sesudah fetch existing, tambah handler untuk 2 tab baru. Find pola existing yang panggil `loadKeuLaporan(action)` atau sejenis (lihat sekitar line 1388 `fetch(.../keuangan.php?action=...)`). Tambah cabang:
```javascript
  if (tab === 'bukubesar') { initBukuBesarDropdown(); return; }
  if (tab === 'perubahanmodal') { loadPerubahanModal(); return; }
```
(Letakkan sebelum blok fetch generik existing supaya tidak dobel-fetch dengan action lama.)

- [ ] **Step 5: Tambah JS functions**

Tambah di blok `<script>` laporan.php (dekat fungsi keu lain):
```javascript
let _bbCoaLoaded = false;
async function initBukuBesarDropdown() {
  if (!_bbCoaLoaded) {
    try {
      const r = await fetch('/hq/keuangan.php?action=coa_options', {headers:{'X-Requested-With':'XMLHttpRequest','X-CSRF-Token':KEU_CSRF}});
      const d = await r.json();
      if (d.ok) {
        const sel = document.getElementById('bbCoaSelect');
        const groups = {};
        d.data.forEach(c => { (groups[c.tipe] = groups[c.tipe] || []).push(c); });
        let html = '<option value="">— Pilih akun —</option>';
        Object.keys(groups).forEach(tipe => {
          html += `<optgroup label="${esc(tipe)}">`;
          groups[tipe].forEach(c => { html += `<option value="${c.id}">${esc(c.kode)} · ${esc(c.nama)}</option>`; });
          html += '</optgroup>';
        });
        sel.innerHTML = html;
        _bbCoaLoaded = true;
      }
    } catch(e) {}
  }
}

async function loadBukuBesar() {
  const coaId = document.getElementById('bbCoaSelect').value;
  const box = document.getElementById('keuBukubesarContent');
  if (!coaId) { box.innerHTML = '<div style="color:#9CA3AF;text-align:center;padding:30px">Pilih akun untuk lihat mutasi.</div>'; return; }
  box.innerHTML = '<div style="color:#9CA3AF;text-align:center;padding:30px">Memuat…</div>';
  try {
    const r = await fetch(`/hq/keuangan.php?action=laporan_buku_besar&coa_id=${coaId}&${keuParams()}`, {headers:{'X-Requested-With':'XMLHttpRequest','X-CSRF-Token':KEU_CSRF}});
    const d = await r.json();
    if (!d.ok) { box.innerHTML = `<div style="color:#DC2626;padding:20px">${esc(d.error||'Gagal')}</div>`; return; }
    const x = d.data;
    let rows = x.mutasi.map(m => `<tr>
      <td>${new Date(m.tanggal).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'})}</td>
      <td>${esc(m.keterangan)}</td>
      <td class="num">${m.debit?fmtRp(m.debit):'−'}</td>
      <td class="num">${m.kredit?fmtRp(m.kredit):'−'}</td>
      <td class="num"><strong>${fmtRp(m.saldo)}</strong></td>
    </tr>`).join('');
    if (!x.mutasi.length) rows = '<tr><td colspan="5" style="text-align:center;color:#9CA3AF;padding:20px">Tidak ada mutasi pada periode ini.</td></tr>';
    box.innerHTML = `
      <div style="margin-bottom:8px;font-size:13px;color:#6B7280">Saldo Awal: <strong>${fmtRp(x.saldo_awal)}</strong></div>
      <table class="lap-table" style="width:100%"><thead><tr><th>Tgl</th><th>Keterangan</th><th class="num">Debit</th><th class="num">Kredit</th><th class="num">Saldo</th></tr></thead>
      <tbody>${rows}</tbody></table>
      <div style="margin-top:10px;text-align:right;font-size:14px"><strong>Saldo Akhir: ${fmtRp(x.saldo_akhir)}</strong></div>`;
  } catch(e) { box.innerHTML = `<div style="color:#DC2626;padding:20px">${esc(e.message)}</div>`; }
}

async function loadPerubahanModal() {
  const box = document.getElementById('keuPerubahanmodalContent');
  box.innerHTML = '<div style="color:#9CA3AF;text-align:center;padding:30px">Memuat…</div>';
  try {
    const r = await fetch(`/hq/keuangan.php?action=laporan_perubahan_modal&${keuParams()}`, {headers:{'X-Requested-With':'XMLHttpRequest','X-CSRF-Token':KEU_CSRF}});
    const d = await r.json();
    if (!d.ok) { box.innerHTML = `<div style="color:#DC2626;padding:20px">${esc(d.error||'Gagal')}</div>`; return; }
    const x = d.data;
    const sign = (n) => n < 0 ? `<span style="color:#DC2626">−${fmtRp(Math.abs(n))}</span>` : fmtRp(n);
    box.innerHTML = `
      <table class="lap-table" style="width:100%">
        <tr><td>Modal Awal</td><td class="num"><strong>${fmtRp(x.modal_awal)}</strong></td></tr>
        <tr><td>+ Setoran Modal periode ini</td><td class="num">${fmtRp(x.setoran_modal)}</td></tr>
        <tr><td>− Prive periode ini</td><td class="num">${fmtRp(x.prive)}</td></tr>
        <tr><td>+ Laba Bersih periode ini</td><td class="num">${sign(x.laba_bersih)}</td></tr>
        <tr style="border-top:2px solid #1F3864"><td><strong>Modal Akhir</strong></td><td class="num"><strong>${fmtRp(x.modal_akhir)}</strong></td></tr>
      </table>
      <div style="margin-top:10px;font-size:12px;color:#9CA3AF">ℹ️ Modal akhir = total ekuitas di Neraca periode yang sama.</div>`;
  } catch(e) { box.innerHTML = `<div style="color:#DC2626;padding:20px">${esc(e.message)}</div>`; }
}
```

(Verifikasi nama helper: `KEU_CSRF`, `keuParams()`, `fmtRp()`, `esc()` — sudah dipakai fungsi keu existing. Kalau nama beda, samakan.)

- [ ] **Step 6: Update panelIds array (kalau dipakai untuk reset)**

Find (~line 1752):
```javascript
  const panelIds   = ['keuPanelLr','keuPanelNeraca','keuPanelArus','keuPanelRasio','keuPanelAset'];
```
Tambah 2:
```javascript
  const panelIds   = ['keuPanelLr','keuPanelNeraca','keuPanelArus','keuPanelRasio','keuPanelAset','keuPanelBukubesar','keuPanelPerubahanmodal'];
```

- [ ] **Step 7: Commit**

```bash
git add hq/laporan.php
git commit -m "feat(keuangan): UI sub-tab Buku Besar + Perubahan Modal

2 sub-tab di tab Keuangan laporan: 📖 Buku Besar (dropdown pilih akun →
saldo awal + mutasi + saldo akhir) + 📈 Perubahan Modal (roll-forward).
Pola AJAX + switchKeuTab existing. Dropdown akun grouped by tipe."
```

---

## Task 4: E2E + Production Deploy

**Files:** None (verification)

- [ ] **Step 1: Push + deploy**
```bash
git push origin main
```
Wait ~20s.

- [ ] **Step 2: HTTP smoke**
```bash
curl -s -o /dev/null -w "GET /hq/laporan %{http_code}\n" "https://lamasy.harpy.id/hq/laporan"
curl -s -o /tmp/coa.json -w "coa_options %{http_code}\n" "https://lamasy.harpy.id/hq/keuangan.php?action=coa_options"
```
Expected: 302 (auth gate) untuk keduanya.

- [ ] **Step 3: Browser E2E (login HQ owner)**

| # | Action | Expected |
|---|--------|----------|
| 1 | /hq/laporan → tab Keuangan | Sub-tab 📖 Buku Besar + 📈 Perubahan Modal muncul |
| 2 | Buku Besar → dropdown | Akun ter-populate, grouped by tipe |
| 3 | Pilih akun Pendapatan Kiloan (4-1001) | Saldo awal 0 + 1 baris agregat = pendapatan kiloan di Laba Rugi periode sama |
| 4 | Pilih akun Prive (3-1003) | Saldo awal + mutasi per-entry + saldo berjalan + saldo akhir |
| 5 | Pilih akun tanpa mutasi | "Tidak ada mutasi pada periode ini" |
| 6 | Perubahan Modal | Roll-forward: modal awal + setoran − prive + laba = modal akhir |
| 7 | Cross-check | Modal akhir = ekuitas_real Neraca (tanpa penyesuaian) periode sama |
| 8 | Ganti periode | Kedua laporan re-fetch + update |

- [ ] **Step 4: Cross-check angka via DB**

```bash
/opt/homebrew/opt/mysql-client/bin/mysql -e "
SELECT j.coa_id, c.kode, c.nama, j.arah, SUM(j.jumlah) total
FROM hl_jurnal_manual j JOIN hl_coa c ON c.id=j.coa_id
WHERE j.tenant_id=(SELECT id FROM tenants LIMIT 1)
GROUP BY j.coa_id, j.arah LIMIT 20;"
```
Bandingkan dgn saldo Buku Besar untuk akun manual yang sama.

- [ ] **Step 5: Update progress ledger**
```bash
cat >> .superpowers/sdd/progress.md <<'EOF'

Keuangan Buku Besar + Perubahan Modal COMPLETE 2026-06-24 WIB.
Final state: <base>..<head>
EOF
```

---

## Self-Review Checklist

### Spec Coverage
- ✅ §3.2 perubahanModal → Task 1 Step 4
- ✅ §3.3 bukuBesar (hybrid manual+P&L) → Task 1 Step 5 + helper Step 1-3
- ✅ §3.4 coa_options → Task 2 Step 3
- ✅ §4.1-4.3 UI sub-tab + panel → Task 3
- ✅ §4.4 AJAX flow → Task 2 + Task 3
- ✅ §5 keuangan.php endpoints → Task 2
- ✅ §6 Edge cases → Task 1 (empty mutasi, P&L agregat, normalSign), Task 4 (cross-check)
- ✅ §7 Testing → Task 4

### Placeholder Scan
✓ No TBD/TODO. Full code di tiap step. Commands + expected output.

### Type/Name Consistency
- ✅ `perubahanModal(int, ?int, string): array` — Task 1 def, Task 2 use
- ✅ `bukuBesar(int, ?int, string, int): array` — Task 1 def, Task 2 use (coa_id param)
- ✅ Return keys: perubahanModal `{modal_awal, setoran_modal, prive, laba_bersih, modal_akhir}` — Task 1 → Task 3 JS render konsisten
- ✅ bukuBesar `{akun, is_pnl, saldo_awal, mutasi[{tanggal,keterangan,debit,kredit,saldo}], saldo_akhir}` — Task 1 → Task 3 JS render konsisten
- ✅ Action names `laporan_buku_besar`/`laporan_perubahan_modal`/`coa_options` — Task 2 → Task 3 fetch konsisten
- ✅ Panel id `keuPanelBukubesar`/`keuPanelPerubahanmodal` + tab key `bukubesar`/`perubahanmodal` — Task 3 konsisten (map + switchKeuTab + panelIds)
- ✅ kode-map (4-1001 dll) sesuai seed COA verified

### Notes (risiko di-flag)
- Task 1 Step 3: mapping `aggregatePnLForCoa` pakai kode COA seed standar. Kalau tenant punya COA custom dgn kode beda → return 0 (akun custom tidak terpetakan, acceptable). Akun 5-1003/4/7 (sewa/utilitas/pemasaran) tidak terpisah di labaRugi → 0; total operasional_kas masuk catch-all 5-1099. Ini trade-off model lightweight, bukan bug.
- Task 3 Step 4-5: nama JS helper (`KEU_CSRF`, `keuParams`, `fmtRp`, `esc`) diasumsikan dari fungsi keu existing — implementer verify exact nama saat baca laporan.php.
- Task 1 Step 6: tidak ada php CLI lokal → syntax verify via deploy/browser Task 4.

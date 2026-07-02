# Laporan Keuangan SuperAdmin — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Halaman `superadmin/laporan-keuangan.php` yang menampilkan P&L platform (Pendapatan − Biaya AI − Komisi Affiliate), laporan Coin Float ringkas, rincian transaksi, dan export CSV, dengan filter periode preset + custom range.

**Architecture:** Logika kalkulasi diisolasi di kelas statis `core/SaFinance.php` (murni SELECT, return array, tidak echo). Page tipis memanggil helper + render pakai shell `superadmin_components.php`. Reuse union revenue yang sama persis dengan `billing.php` (`smpRevenueSource`).

**Tech Stack:** PHP 8, MariaDB (PDO via `core/Database.php`), shell SuperAdmin (`superadmin_components.php`), test harness custom `tests/_assert.php` (dijalankan `php tests/...php`).

## Global Constraints

- Rate coin→IDR = **4.17** (konstanta `SaFinance::COIN_TO_IDR`), samakan dengan `superadmin/ai_usage.php`.
- Revenue **tidak double-count**: `saas_manual_payments` (confirmed) dan `saas_payments` (paid) adalah dua sumber terpisah (webhook hanya tulis `saas_payments`).
- Semua fungsi terima `?string $from, ?string $to` format `YYYY-MM-DD`; `null` = tanpa batas. `coinFloat()` snapshot (tak berperiode).
- Page & handler export dilindungi `superadmin_guard.php`. Export pakai `saVerifyCsrf()` + `X-CSRF-Token`.
- Read-only: tidak ada query tulis. Tanggal disanitasi via `DateTime::createFromFormat('Y-m-d', ...)`.
- `date_default_timezone_set('Asia/Jakarta')` di page (pola SA existing).
- Timezone catatan: `saas_manual_payments.tanggal_bayar` = DATE; `saas_payments.paid_at` = DATETIME → pembanding pakai `DATE(...)`. AI & ledger pakai `created_at` (bandingkan `DATE(created_at)`).
- Angka bisa besar → cast `(int)` hasil `SUM`, hindari div-by-zero pada margin.
- `billing.php`/`payments.php` TIDAK diubah.

---

### Task 1: `core/SaFinance.php` + unit test

**Files:**
- Create: `core/SaFinance.php`
- Test: `tests/finance/test_sa_finance.php`

**Interfaces:**
- Consumes: `core/Database.php` (`Database::get(): PDO`), `master/config/db.php` (bootstrap koneksi).
- Produces:
  - `SaFinance::COIN_TO_IDR` (float 4.17)
  - `SaFinance::revenue(?string,?string): array` → `['setup_fee','coin_topup','adjustment','total','coin_credited']` (semua int)
  - `SaFinance::aiCost(?string,?string): int`
  - `SaFinance::affiliatePayout(?string,?string): int`
  - `SaFinance::coinConsumed(?string,?string): int`
  - `SaFinance::coinFloat(): array` → `['coin_outstanding'=>int,'rp_estimate'=>int]`
  - `SaFinance::revenueRows(?string,?string): array` → list `['tanggal','tenant_id','tenant_nama','tipe','nominal','coin']`
  - `SaFinance::pnl(?string,?string): array` → `['revenue'=>array,'ai_cost'=>int,'affiliate'=>int,'total_cost'=>int,'laba'=>int,'margin_pct'=>float,'coin'=>array,'coin_consumed'=>int]`

- [ ] **Step 1: Tulis test yang gagal**

Create `tests/finance/test_sa_finance.php`:

```php
<?php
// Test SaFinance: seed baris temp bertanda 'zzfin' ke tabel sumber, assert agregat, cleanup.
require __DIR__ . '/../_assert.php';
require __DIR__ . '/../../master/config/db.php';
require __DIR__ . '/../../core/Database.php';
require __DIR__ . '/../../core/SaFinance.php';

$db = Database::get();
$tag = 'zzfin_' . time();

// ── Setup: tenant temp (clone baris existing utk penuhi NOT NULL) ──
$src = $db->query("SELECT * FROM tenants LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$src) { echo "SKIP: tidak ada tenant\n"; exit(0); }
unset($src['id']);
$src['slug']            = $tag;
$src['email']           = $tag . '@test.invalid';
$src['nama_perusahaan'] = 'ZZFIN Test';
$src['coin_balance']    = 10000;
$cols = array_keys($src);
$db->prepare("INSERT INTO tenants (".implode(',', $cols).") VALUES (".implode(',', array_fill(0,count($cols),'?')).")")
   ->execute(array_values($src));
$tid = (int)$db->lastInsertId();

$from = '2026-01-01'; $to = '2026-12-31';

// Revenue: 1 manual confirmed (setup_fee 150000) + 1 midtrans paid (coin_topup 50000)
$db->prepare("INSERT INTO saas_manual_payments
  (tenant_id, superadmin_id, type, nominal_dibayar, coin_dikreditkan, metode, tanggal_bayar, status, notif_wa_sent, created_at)
  VALUES (?,1,'setup_fee',150000,0,'qris','2026-03-01','confirmed',0,NOW())")->execute([$tid]);
$db->prepare("INSERT INTO saas_payments
  (order_id, tenant_id, type, amount, status, paid_at, expires_at, created_at)
  VALUES (?,?,'topup_coin',50000,'paid','2026-03-02 10:00:00','2026-03-03 00:00:00',NOW())")
  ->execute([$tag.'-P', $tid]);

// Biaya AI 20000 + komisi affiliate payout 30000 (paid)
$db->prepare("INSERT INTO hl_ai_usage (tenant_id, tokens_in, tokens_out, cost_estimated_idr, coin_charged, created_at)
  VALUES (?,100,200,20000,50,'2026-03-05 09:00:00')")->execute([$tid]);
$affSrc = $db->query("SELECT id FROM hl_affiliate LIMIT 1")->fetchColumn();
$affId = null;
if ($affSrc) {
    $db->prepare("INSERT INTO hl_affiliate_payout (affiliate_id, jumlah, status, requested_at, paid_at)
      VALUES (?,30000,'paid','2026-03-04 08:00:00','2026-03-06 08:00:00')")->execute([(int)$affSrc]);
    $affId = (int)$db->lastInsertId();
}

// ── Assert ──
$rev = SaFinance::revenue($from, $to);
ok($rev['setup_fee'] >= 150000, "revenue setup_fee mencakup 150000");
ok($rev['coin_topup'] >= 50000, "revenue coin_topup mencakup 50000");
ok($rev['total'] >= 200000, "revenue total mencakup 200000");

$ai = SaFinance::aiCost($from, $to);
ok($ai >= 20000, "aiCost mencakup 20000");

if ($affId) { ok(SaFinance::affiliatePayout($from, $to) >= 30000, "affiliatePayout mencakup 30000"); }

$float = SaFinance::coinFloat();
ok($float['coin_outstanding'] >= 10000, "coinFloat outstanding mencakup 10000");
eqv($float['rp_estimate'], (int)round($float['coin_outstanding'] * 4.17), "coinFloat rp_estimate = outstanding*4.17");

$pnl = SaFinance::pnl($from, $to);
eqv($pnl['total_cost'], $pnl['ai_cost'] + $pnl['affiliate'], "total_cost = ai+affiliate");
eqv($pnl['laba'], $pnl['revenue']['total'] - $pnl['total_cost'], "laba = revenue - cost");
ok($pnl['margin_pct'] >= 0, "margin_pct non-negatif");

// Edge: periode kosong → 0, margin 0 (tak div-by-zero)
$empty = SaFinance::pnl('1990-01-01', '1990-01-02');
eqv($empty['revenue']['total'], 0, "periode kosong revenue 0");
eqv($empty['margin_pct'], 0, "periode kosong margin 0 (no div-by-zero)");

$rows = SaFinance::revenueRows($from, $to);
ok(count($rows) >= 2, "revenueRows >= 2 baris");
ok(isset($rows[0]['tenant_nama'], $rows[0]['nominal'], $rows[0]['tipe']), "revenueRows punya kolom lengkap");

// ── Cleanup ──
$db->prepare("DELETE FROM saas_manual_payments WHERE tenant_id=?")->execute([$tid]);
$db->prepare("DELETE FROM saas_payments WHERE tenant_id=?")->execute([$tid]);
$db->prepare("DELETE FROM hl_ai_usage WHERE tenant_id=?")->execute([$tid]);
if ($affId) $db->prepare("DELETE FROM hl_affiliate_payout WHERE id=?")->execute([$affId]);
$db->prepare("DELETE FROM tenants WHERE id=?")->execute([$tid]);
echo "ALL PASS\n";
```

- [ ] **Step 2: Jalankan test — pastikan gagal**

Run: `php tests/finance/test_sa_finance.php`
Expected: FAIL/fatal — `SaFinance` belum ada (`Failed opening required '.../core/SaFinance.php'`).

- [ ] **Step 3: Implementasi `core/SaFinance.php`**

```php
<?php
// core/SaFinance.php — Kalkulasi keuangan platform utk laporan SuperAdmin.
// Murni SELECT + return array. Tidak echo. Semua rentang tanggal 'Y-m-d' (null=tanpa batas).
if (!class_exists('Database')) require_once __DIR__ . '/Database.php';

class SaFinance
{
    const COIN_TO_IDR = 4.17; // samakan dgn superadmin/ai_usage.php

    // Klausa BETWEEN aman utk kolom tanggal. Return [sqlFragment, params].
    private static function range(string $col, ?string $from, ?string $to): array
    {
        $sql = ''; $p = [];
        if ($from) { $sql .= " AND $col >= ?"; $p[] = $from; }
        if ($to)   { $sql .= " AND $col <= ?"; $p[] = $to; }
        return [$sql, $p];
    }

    // Subquery revenue gabungan — sama persis dgn billing.php smpRevenueSource.
    // Kolom ternormalisasi: tenant_id, type(setup_fee|coin_topup|adjustment), nominal, coin, bayar(DATE).
    private static function revenueSource(): string
    {
        return "(
            SELECT tenant_id,
                   CASE WHEN type='custom' THEN 'adjustment' ELSE type END AS type,
                   nominal_dibayar AS nominal, coin_dikreditkan AS coin,
                   tanggal_bayar AS bayar
            FROM saas_manual_payments WHERE status='confirmed'
            UNION ALL
            SELECT sp.tenant_id,
                   CASE WHEN sp.type='topup_coin' THEN 'coin_topup' ELSE 'setup_fee' END AS type,
                   sp.amount AS nominal,
                   COALESCE((SELECT cl.amount FROM coin_ledger cl
                             WHERE cl.payment_id=sp.id AND cl.type='topup' LIMIT 1),0) AS coin,
                   DATE(COALESCE(sp.paid_at, sp.created_at)) AS bayar
            FROM saas_payments sp WHERE sp.status='paid'
        ) rev";
    }

    public static function revenue(?string $from, ?string $to): array
    {
        [$r, $p] = self::range('bayar', $from, $to);
        try {
            $sql = "SELECT
                      COALESCE(SUM(CASE WHEN type='setup_fee'  THEN nominal END),0) AS setup_fee,
                      COALESCE(SUM(CASE WHEN type='coin_topup' THEN nominal END),0) AS coin_topup,
                      COALESCE(SUM(CASE WHEN type='adjustment' THEN nominal END),0) AS adjustment,
                      COALESCE(SUM(nominal),0) AS total,
                      COALESCE(SUM(coin),0)    AS coin_credited
                    FROM " . self::revenueSource() . " WHERE 1=1" . $r;
            $st = Database::get()->prepare($sql); $st->execute($p);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) { $row = []; }
        return [
            'setup_fee'     => (int)($row['setup_fee'] ?? 0),
            'coin_topup'    => (int)($row['coin_topup'] ?? 0),
            'adjustment'    => (int)($row['adjustment'] ?? 0),
            'total'         => (int)($row['total'] ?? 0),
            'coin_credited' => (int)($row['coin_credited'] ?? 0),
        ];
    }

    public static function aiCost(?string $from, ?string $to): int
    {
        [$r, $p] = self::range('DATE(created_at)', $from, $to);
        try {
            $st = Database::get()->prepare(
                "SELECT COALESCE(SUM(cost_estimated_idr),0) FROM hl_ai_usage WHERE 1=1" . $r);
            $st->execute($p);
            return (int)$st->fetchColumn();
        } catch (Throwable) { return 0; }
    }

    public static function affiliatePayout(?string $from, ?string $to): int
    {
        [$r, $p] = self::range('DATE(paid_at)', $from, $to);
        try {
            $st = Database::get()->prepare(
                "SELECT COALESCE(SUM(jumlah),0) FROM hl_affiliate_payout WHERE status='paid'" . $r);
            $st->execute($p);
            return (int)$st->fetchColumn();
        } catch (Throwable) { return 0; }
    }

    public static function coinConsumed(?string $from, ?string $to): int
    {
        [$r, $p] = self::range('DATE(created_at)', $from, $to);
        try {
            $st = Database::get()->prepare(
                "SELECT COALESCE(SUM(amount),0) FROM coin_ledger WHERE type='deduct'" . $r);
            $st->execute($p);
            return (int)$st->fetchColumn();
        } catch (Throwable) { return 0; }
    }

    public static function coinFloat(): array
    {
        try {
            $out = (int)Database::get()->query("SELECT COALESCE(SUM(coin_balance),0) FROM tenants")->fetchColumn();
        } catch (Throwable) { $out = 0; }
        return ['coin_outstanding' => $out, 'rp_estimate' => (int)round($out * self::COIN_TO_IDR)];
    }

    public static function revenueRows(?string $from, ?string $to): array
    {
        [$r, $p] = self::range('bayar', $from, $to);
        try {
            $sql = "SELECT rev.bayar AS tanggal, rev.tenant_id, rev.type AS tipe,
                           rev.nominal, rev.coin,
                           COALESCE(t.nama_perusahaan, CONCAT('Tenant #', rev.tenant_id)) AS tenant_nama
                    FROM " . self::revenueSource() . "
                    LEFT JOIN tenants t ON t.id = rev.tenant_id
                    WHERE 1=1" . $r . " ORDER BY rev.bayar DESC, rev.tenant_id";
            $st = Database::get()->prepare($sql); $st->execute($p);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) { $rows = []; }
        return array_map(fn($x) => [
            'tanggal'     => $x['tanggal'],
            'tenant_id'   => (int)$x['tenant_id'],
            'tenant_nama' => $x['tenant_nama'],
            'tipe'        => $x['tipe'],
            'nominal'     => (int)$x['nominal'],
            'coin'        => (int)$x['coin'],
        ], $rows);
    }

    public static function pnl(?string $from, ?string $to): array
    {
        $rev = self::revenue($from, $to);
        $ai  = self::aiCost($from, $to);
        $aff = self::affiliatePayout($from, $to);
        $totalCost = $ai + $aff;
        $laba = $rev['total'] - $totalCost;
        $margin = $rev['total'] > 0 ? round($laba / $rev['total'] * 100, 1) : 0.0;
        return [
            'revenue' => $rev, 'ai_cost' => $ai, 'affiliate' => $aff,
            'total_cost' => $totalCost, 'laba' => $laba, 'margin_pct' => $margin,
            'coin' => self::coinFloat(), 'coin_consumed' => self::coinConsumed($from, $to),
        ];
    }
}
```

- [ ] **Step 4: Jalankan test — pastikan lolos**

Run: `php tests/finance/test_sa_finance.php`
Expected: baris `PASS: ...` lalu `ALL PASS`. (Kalau tak ada tenant/affiliate di DB → sebagian di-skip; minimal `ALL PASS`.)

- [ ] **Step 5: Lint + commit**

```bash
php -l core/SaFinance.php
git add core/SaFinance.php tests/finance/test_sa_finance.php
git commit -m "feat(sa-finance): SaFinance helper (P&L, coin float, revenue rows) + unit test"
```

---

### Task 2: Page `laporan-keuangan.php` (render P&L + coin float + rincian) + nav link

**Files:**
- Create: `superadmin/laporan-keuangan.php`
- Modify: `superadmin/superadmin_components.php` (sisip nav link setelah link Billing)

**Interfaces:**
- Consumes: `SaFinance::pnl()`, `SaFinance::revenueRows()` (Task 1); `saRenderHead()`, `saRenderNav('laporan-keuangan', ...)`, `saRenderNavClose()`, `saGetCsrf()` (shell).
- Produces: route `/superadmin/laporan-keuangan.php`; `activePage='laporan-keuangan'`.

- [ ] **Step 1: Sisip nav link** — di `superadmin/superadmin_components.php`, tepat setelah blok link Billing (link `href="/superadmin/billing.php"` ... `</a>`), tambah:

```php
        <a href="/superadmin/laporan-keuangan.php" class="sa-nav-link <?= $activePage === 'laporan-keuangan' ? 'active' : '' ?>">
          <span class="icon">📑</span> Laporan Keuangan
        </a>
```

- [ ] **Step 2: Buat page** `superadmin/laporan-keuangan.php`:

```php
<?php
// superadmin/laporan-keuangan.php — Laporan Keuangan platform (P&L + coin float + rincian + export CSV).
if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';
require_once SA_ROOT . '/../core/SaFinance.php';
date_default_timezone_set('Asia/Jakarta');

// ── Sanitasi periode (default: bulan ini) ──
function lkDate(?string $v, string $fallback): string {
    if ($v) { $d = DateTime::createFromFormat('Y-m-d', $v); if ($d && $d->format('Y-m-d') === $v) return $v; }
    return $fallback;
}
$from = lkDate($_GET['from'] ?? null, date('Y-m-01'));
$to   = lkDate($_GET['to']   ?? null, date('Y-m-t'));
if ($from > $to) { $from = date('Y-m-01'); $to = date('Y-m-t'); }

// ── Export CSV (Task 3 mengisi handler ini) ──
if (($_GET['action'] ?? '') === 'export') {
    saVerifyCsrf();
    require SA_ROOT . '/laporan-keuangan-export.inc.php';
    exit;
}

$pnl  = SaFinance::pnl($from, $to);
$rows = SaFinance::revenueRows($from, $to);
$rp = fn($n) => 'Rp ' . number_format((int)$n, 0, ',', '.');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php saRenderHead('Laporan Keuangan'); ?>
<style>
  .lk-filter { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:20px; }
  .lk-filter input[type=date]{ padding:8px 10px; border:1px solid var(--crease,#e5e7eb); border-radius:8px; font-size:13px; }
  .lk-pnl { max-width:520px; }
  .lk-row { display:flex; justify-content:space-between; padding:7px 0; font-size:14px; }
  .lk-row.sub { color:var(--ash,#6B7280); padding-left:14px; font-size:13px; }
  .lk-row.total { font-weight:800; border-top:1px solid var(--crease,#e5e7eb); margin-top:4px; }
  .lk-row.laba { font-weight:800; font-size:16px; border-top:2px solid #0F1C3A; margin-top:6px; padding-top:10px; }
  .lk-neg { color:#DC2626; }
</style>
</head>
<body>
<div class="sa-layout">
<?php saRenderNav('laporan-keuangan', 'Laporan Keuangan'); ?>

<div class="sa-page-header">
  <h1>📑 Laporan Keuangan</h1>
</div>

<div class="lk-filter">
  <button class="sa-btn sa-btn-sm sa-btn-outline" onclick="lkPreset('month')">Bulan Ini</button>
  <button class="sa-btn sa-btn-sm sa-btn-outline" onclick="lkPreset('lastmonth')">Bulan Lalu</button>
  <button class="sa-btn sa-btn-sm sa-btn-outline" onclick="lkPreset('year')">Tahun Ini</button>
  <input type="date" id="lkFrom" value="<?= htmlspecialchars($from) ?>">
  <span>s/d</span>
  <input type="date" id="lkTo" value="<?= htmlspecialchars($to) ?>">
  <button class="sa-btn sa-btn-sm" onclick="lkApply()">Terapkan</button>
  <button class="sa-btn sa-btn-sm sa-btn-outline" onclick="lkExport()">⬇ Export CSV</button>
</div>

<div class="sa-card lk-pnl">
  <div class="sa-card-header"><h3>Laba / Rugi Platform</h3></div>
  <div class="sa-card-body">
    <div class="lk-row"><span><strong>Pendapatan</strong></span><span></span></div>
    <div class="lk-row sub"><span>Setup Fee</span><span><?= $rp($pnl['revenue']['setup_fee']) ?></span></div>
    <div class="lk-row sub"><span>Top-up Coin</span><span><?= $rp($pnl['revenue']['coin_topup']) ?></span></div>
    <div class="lk-row sub"><span>Adjustment</span><span><?= $rp($pnl['revenue']['adjustment']) ?></span></div>
    <div class="lk-row total"><span>Total Pendapatan</span><span><?= $rp($pnl['revenue']['total']) ?></span></div>
    <div class="lk-row" style="margin-top:10px"><span><strong>Biaya</strong></span><span></span></div>
    <div class="lk-row sub"><span>Biaya AI (Anthropic)</span><span class="lk-neg">(<?= $rp($pnl['ai_cost']) ?>)</span></div>
    <div class="lk-row sub"><span>Komisi Affiliate</span><span class="lk-neg">(<?= $rp($pnl['affiliate']) ?>)</span></div>
    <div class="lk-row total"><span>Total Biaya</span><span class="lk-neg">(<?= $rp($pnl['total_cost']) ?>)</span></div>
    <div class="lk-row laba"><span>Laba Kotor Platform</span><span><?= $rp($pnl['laba']) ?> <small style="color:var(--ash,#6B7280);font-weight:600">(margin <?= $pnl['margin_pct'] ?>%)</small></span></div>
  </div>
</div>

<div class="sa-card lk-pnl" style="margin-top:18px">
  <div class="sa-card-header"><h3>Coin Float — estimasi kewajiban layanan</h3></div>
  <div class="sa-card-body">
    <div class="lk-row"><span>Coin outstanding (semua tenant)</span><span><?= number_format($pnl['coin']['coin_outstanding'],0,',','.') ?> coin</span></div>
    <div class="lk-row"><span>Estimasi nilai (rate <?= SaFinance::COIN_TO_IDR ?>/coin)</span><span>± <?= $rp($pnl['coin']['rp_estimate']) ?></span></div>
    <div class="lk-row"><span>Coin terpakai periode</span><span><?= number_format($pnl['coin_consumed'],0,',','.') ?> coin</span></div>
  </div>
</div>

<div class="sa-card" style="margin-top:18px">
  <div class="sa-card-header"><h3>Rincian Transaksi Pendapatan</h3></div>
  <div class="sa-table-wrap">
    <table class="sa-table">
      <thead><tr><th>Tanggal</th><th>Tenant</th><th>Tipe</th><th style="text-align:right">Nominal</th><th style="text-align:right">Coin</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="5" style="text-align:center;color:var(--ash,#6B7280);padding:24px">Belum ada transaksi pada periode ini</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><?= htmlspecialchars((string)$r['tanggal']) ?></td>
          <td><?= htmlspecialchars($r['tenant_nama']) ?></td>
          <td><?= htmlspecialchars($r['tipe']) ?></td>
          <td style="text-align:right"><?= $rp($r['nominal']) ?></td>
          <td style="text-align:right"><?= $r['coin'] ? number_format($r['coin'],0,',','.') : '—' ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php saRenderNavClose(); ?>
<script>
const CSRF = <?= json_encode(saGetCsrf()) ?>;
function lkApply(){
  const f = document.getElementById('lkFrom').value, t = document.getElementById('lkTo').value;
  location.href = '?from=' + encodeURIComponent(f) + '&to=' + encodeURIComponent(t);
}
function lkPreset(kind){
  const now = new Date(); let f, t;
  const iso = d => d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');
  if (kind==='month'){ f=new Date(now.getFullYear(),now.getMonth(),1); t=new Date(now.getFullYear(),now.getMonth()+1,0); }
  else if (kind==='lastmonth'){ f=new Date(now.getFullYear(),now.getMonth()-1,1); t=new Date(now.getFullYear(),now.getMonth(),0); }
  else { f=new Date(now.getFullYear(),0,1); t=new Date(now.getFullYear(),11,31); }
  document.getElementById('lkFrom').value=iso(f); document.getElementById('lkTo').value=iso(t); lkApply();
}
async function lkExport(){
  const f = document.getElementById('lkFrom').value, t = document.getElementById('lkTo').value;
  const r = await fetch('?action=export&from='+encodeURIComponent(f)+'&to='+encodeURIComponent(t), { headers:{'X-CSRF-Token':CSRF} });
  if (!r.ok){ alert('Export gagal (' + r.status + ')'); return; }
  const blob = await r.blob(); const url = URL.createObjectURL(blob);
  const a = document.createElement('a'); a.href=url; a.download='laporan-keuangan_'+f+'_'+t+'.csv';
  document.body.appendChild(a); a.click(); a.remove(); setTimeout(()=>URL.revokeObjectURL(url),4000);
}
</script>
</body>
</html>
```

- [ ] **Step 3: Verifikasi lint + guard**

Run: `php -l superadmin/laporan-keuangan.php && php -l superadmin/superadmin_components.php`
Expected: `No syntax errors detected` untuk keduanya.

- [ ] **Step 4: Verifikasi manual (data QA sudah di-seed)**

Buka `/superadmin/laporan-keuangan.php` (butuh login SA). Cek: blok P&L terisi (setup_fee/coin_topup/adjustment, biaya AI, komisi, laba+margin), Coin Float 3 angka, tabel rincian ada baris "QA ...", nav "Laporan Keuangan" aktif. Klik preset Bulan Ini/Lalu/Tahun Ini → URL & angka berubah. (Kalau tak bisa login SA: minimal lint hijau + `curl -s -o /dev/null -w '%{http_code}' https://lamasy.harpy.id/superadmin/laporan-keuangan.php` → 302/redirect ke login = route hidup.)

- [ ] **Step 5: Commit**

```bash
git add superadmin/laporan-keuangan.php superadmin/superadmin_components.php
git commit -m "feat(sa-finance): halaman Laporan Keuangan (P&L + coin float + rincian) + nav link"
```

---

### Task 3: Export CSV

**Files:**
- Create: `superadmin/laporan-keuangan-export.inc.php`

**Interfaces:**
- Consumes: `$from`, `$to` (di-scope dari page sebelum `require`), `SaFinance::pnl()`, `SaFinance::revenueRows()`.
- Produces: response CSV (`text/csv`, `Content-Disposition: attachment`) berisi ringkasan P&L + coin float + rincian.

- [ ] **Step 1: Buat handler export** `superadmin/laporan-keuangan-export.inc.php`:

```php
<?php
// superadmin/laporan-keuangan-export.inc.php — stream CSV laporan keuangan.
// Dipanggil dari laporan-keuangan.php (action=export) SETELAH saVerifyCsrf().
// Var $from, $to sudah tersedia dari page.
if (!defined('SA_ROOT')) { http_response_code(400); exit; }
$pnl  = SaFinance::pnl($from, $to);
$rows = SaFinance::revenueRows($from, $to);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="laporan-keuangan_' . $from . '_' . $to . '.csv"');
$out = fopen('php://output', 'w');
fprintf($out, "\xEF\xBB\xBF"); // BOM utk Excel

fputcsv($out, ['Laporan Keuangan Platform', $from . ' s/d ' . $to]);
fputcsv($out, []);
fputcsv($out, ['RINGKASAN P&L']);
fputcsv($out, ['Setup Fee',        $pnl['revenue']['setup_fee']]);
fputcsv($out, ['Top-up Coin',      $pnl['revenue']['coin_topup']]);
fputcsv($out, ['Adjustment',       $pnl['revenue']['adjustment']]);
fputcsv($out, ['Total Pendapatan', $pnl['revenue']['total']]);
fputcsv($out, ['Biaya AI',         -$pnl['ai_cost']]);
fputcsv($out, ['Komisi Affiliate', -$pnl['affiliate']]);
fputcsv($out, ['Total Biaya',      -$pnl['total_cost']]);
fputcsv($out, ['Laba Kotor',       $pnl['laba']]);
fputcsv($out, ['Margin %',         $pnl['margin_pct']]);
fputcsv($out, []);
fputcsv($out, ['COIN FLOAT']);
fputcsv($out, ['Coin Outstanding',      $pnl['coin']['coin_outstanding']]);
fputcsv($out, ['Estimasi Nilai (IDR)',  $pnl['coin']['rp_estimate']]);
fputcsv($out, ['Coin Terpakai Periode', $pnl['coin_consumed']]);
fputcsv($out, []);
fputcsv($out, ['RINCIAN TRANSAKSI']);
fputcsv($out, ['Tanggal', 'Tenant', 'Tipe', 'Nominal', 'Coin']);
foreach ($rows as $r) {
    fputcsv($out, [$r['tanggal'], $r['tenant_nama'], $r['tipe'], $r['nominal'], $r['coin']]);
}
fclose($out);
```

- [ ] **Step 2: Lint**

Run: `php -l superadmin/laporan-keuangan-export.inc.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Verifikasi CSV secara terisolasi** (tanpa perlu login, buktikan handler menghasilkan CSV valid):

Run:
```bash
php -r '
define("SA_ROOT", getcwd()."/superadmin");
require "master/config/db.php"; require "core/Database.php"; require "core/SaFinance.php";
$from="2026-01-01"; $to="2026-12-31";
require "superadmin/laporan-keuangan-export.inc.php";
' | head -20
```
Expected: output CSV (baris `Laporan Keuangan Platform,...`, `RINGKASAN P&L`, angka, `RINCIAN TRANSAKSI`, baris "QA ..."). Membuktikan komposisi CSV benar.

- [ ] **Step 4: Verifikasi manual di page** — buka page, klik **⬇ Export CSV** → file `laporan-keuangan_<from>_<to>.csv` terunduh, buka di Excel: ringkasan + rincian sesuai tampilan. (Skip bila tak bisa login SA; Step 3 sudah membuktikan handler.)

- [ ] **Step 5: Commit**

```bash
git add superadmin/laporan-keuangan-export.inc.php
git commit -m "feat(sa-finance): export CSV laporan keuangan (ringkasan P&L + rincian)"
```

---

## Catatan integrasi (parallel session)

Repo ini sering dikerjakan sesi paralel. Eksekusi plan ini di **git worktree terisolasi** dari `origin/main`; sebelum push `git fetch && git rebase origin/main`; commit hanya file milik plan ini; jangan gabung push dengan cleanup worktree. Auto-deploy aktif saat push ke `main`.

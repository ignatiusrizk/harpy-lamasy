# Riwayat & Analitik Pemakaian Coin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tab "Riwayat & Pemakaian" di hq/coin-info.php — riwayat transaksi coin + breakdown per kategori/fitur + filter periode + ringkasan saldo.

**Architecture:** Modify hq/coin-info.php only. Tambah AJAX block (3 read action) sebelum `require _layout_open.php`, lalu tabs (Harga existing → tab 1, Riwayat → tab 2) + JS. Query langsung di page (CoinLedger write-only, tak diubah). No schema/file baru.

**Tech Stack:** PHP 8 vanilla, MariaDB, HQ page (hq_guard, $hqTenant), AJAX JSON read-only.

## Global Constraints

- Modify `hq/coin-info.php` SAJA. No schema, no file baru, no view.
- Page pakai `$hqTenant['id']` (bukan TenantResolver), `$saldo = (int)($hqTenant['coin_balance'] ?? 0)`. `$katMeta` sudah ada (dokumen/whatsapp/ai/export/lainnya → ico+label).
- AJAX block HARUS sebelum `require __DIR__ . '/_layout_open.php'` (line 58) + `exit` per action (HTML tak boleh ke-render).
- Tenant scope: `WHERE tenant_id=?` (atau `cl.tenant_id=?`) SEMUA query coin_ledger.
- Read-only (GET) → no CSRF. XSS: `esc()` semua data DB di render (global helper dari renderGlobalJsHelpers — tersedia di HQ via _layout_open).
- Periode param `bulan` regex `^\d{4}-\d{2}$`, fallback bulan ini (Asia/Jakarta, date('Y-m')).
- Pagination ledger 30/halaman (LIMIT/OFFSET).
- coin_ledger kolom: type ENUM(topup/deduct), amount, feature_used, description, balance_after, outlet_id, created_at. saas_coin_pricing: feature_key, nama_fitur, kategori. LEFT JOIN (feature_used bisa null/legacy → COALESCE fallback).
- mysql client: /opt/homebrew/opt/mysql-client/bin/mysql. No php CLI → smoke via grep + deploy/browser.

---

## Task 1: AJAX Backend (3 read actions)

**Files:**
- Modify: `hq/coin-info.php` (sisip AJAX block setelah `$saldo` line ~48, sebelum `$katMeta`/`require _layout_open`)

**Interfaces:**
- Produces: AJAX `?action=coin_summary|coin_breakdown|coin_ledger` → JSON

- [ ] **Step 1: Sisip AJAX block**

Setelah baris `$saldo = (int)($hqTenant['coin_balance'] ?? 0);` (sekitar line 48), SEBELUM `$katMeta`, sisipkan:

```php
// ── AJAX: Riwayat & Pemakaian Coin (read-only, tenant scope) ──
$action = $_GET['action'] ?? '';
if ($action !== '') {
    header('Content-Type: application/json');
    // Validasi periode YYYY-MM, fallback bulan ini (Asia/Jakarta)
    $bulan = (string)($_GET['bulan'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}$/', $bulan)) $bulan = date('Y-m');
    $periodeStart = $bulan . '-01 00:00:00';
    $periodeEnd   = date('Y-m-01 00:00:00', strtotime($bulan . '-01 +1 month'));
    try {
        if ($action === 'coin_summary') {
            $s = $db->prepare(
                "SELECT
                   COALESCE(SUM(CASE WHEN type='topup'  THEN amount END),0) AS topup,
                   COALESCE(SUM(CASE WHEN type='deduct' THEN amount END),0) AS deduct,
                   COUNT(*) AS cnt
                 FROM coin_ledger
                 WHERE tenant_id=? AND created_at >= ? AND created_at < ?");
            $s->execute([$tid, $periodeStart, $periodeEnd]);
            $r = $s->fetch(PDO::FETCH_ASSOC) ?: ['topup'=>0,'deduct'=>0,'cnt'=>0];
            echo json_encode(['ok'=>true, 'saldo'=>$saldo,
                'topup'=>(int)$r['topup'], 'deduct'=>(int)$r['deduct'], 'count'=>(int)$r['cnt']]);
            exit;
        }
        if ($action === 'coin_breakdown') {
            $s = $db->prepare(
                "SELECT cl.feature_used,
                        COALESCE(p.nama_fitur, cl.feature_used, 'Lainnya') AS nama,
                        COALESCE(p.kategori, 'lainnya') AS kategori,
                        SUM(cl.amount) AS total
                 FROM coin_ledger cl
                 LEFT JOIN saas_coin_pricing p ON p.feature_key = cl.feature_used
                 WHERE cl.tenant_id=? AND cl.type='deduct'
                   AND cl.created_at >= ? AND cl.created_at < ?
                 GROUP BY cl.feature_used
                 ORDER BY total DESC");
            $s->execute([$tid, $periodeStart, $periodeEnd]);
            $perFitur = $s->fetchAll(PDO::FETCH_ASSOC);
            $perKat = []; $totalDeduct = 0;
            foreach ($perFitur as &$f) {
                $f['total'] = (int)$f['total'];
                $totalDeduct += $f['total'];
                $k = $f['kategori'];
                $perKat[$k] = ($perKat[$k] ?? 0) + $f['total'];
            }
            unset($f);
            $katArr = [];
            foreach ($perKat as $k => $v) $katArr[] = ['kategori'=>$k, 'total'=>$v];
            usort($katArr, fn($a,$b) => $b['total'] - $a['total']);
            echo json_encode(['ok'=>true, 'per_fitur'=>$perFitur,
                'per_kategori'=>$katArr, 'total_deduct'=>$totalDeduct]);
            exit;
        }
        if ($action === 'coin_ledger') {
            $type = $_GET['type'] ?? 'semua';
            $page = max(1, (int)($_GET['page'] ?? 1));
            $per  = 30; $off = ($page - 1) * $per;
            $typeSql = ''; $params = [$tid, $periodeStart, $periodeEnd];
            if ($type === 'topup' || $type === 'deduct') { $typeSql = ' AND cl.type=?'; }
            // total count
            $cParams = $params; if ($typeSql) $cParams[] = $type;
            $c = $db->prepare("SELECT COUNT(*) FROM coin_ledger cl
                               WHERE cl.tenant_id=? AND cl.created_at >= ? AND cl.created_at < ?{$typeSql}");
            $c->execute($cParams);
            $total = (int)$c->fetchColumn();
            // rows
            $lParams = $params; if ($typeSql) $lParams[] = $type;
            $lParams[] = $per; $lParams[] = $off;
            $l = $db->prepare(
                "SELECT cl.type, cl.amount, cl.feature_used, cl.description, cl.balance_after, cl.created_at,
                        COALESCE(p.nama_fitur, cl.feature_used, '-') AS nama_fitur,
                        o.nama_outlet
                 FROM coin_ledger cl
                 LEFT JOIN saas_coin_pricing p ON p.feature_key = cl.feature_used
                 LEFT JOIN outlets o ON o.id = cl.outlet_id AND o.tenant_id = cl.tenant_id
                 WHERE cl.tenant_id=? AND cl.created_at >= ? AND cl.created_at < ?{$typeSql}
                 ORDER BY cl.created_at DESC
                 LIMIT ? OFFSET ?");
            $l->execute($lParams);
            echo json_encode(['ok'=>true, 'rows'=>$l->fetchAll(PDO::FETCH_ASSOC),
                'total'=>$total, 'page'=>$page, 'pages'=>(int)ceil($total / $per)]);
            exit;
        }
        echo json_encode(['ok'=>false, 'error'=>'Unknown action']); exit;
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]); exit;
    }
}
```

CATATAN: LIMIT/OFFSET via placeholder — pastikan PDO emulation prepares ON (default Hostinger) supaya `?` di LIMIT diterima. Kalau error, cast & inline (int): aman karena sudah `(int)`.

- [ ] **Step 2: Verify**
```bash
grep -nE "coin_summary|coin_breakdown|coin_ledger|periodeStart" hq/coin-info.php
php -l hq/coin-info.php 2>/dev/null || echo "no php cli — grep only"
```
Expected: 3 action names + periodeStart muncul.

- [ ] **Step 3: Commit**
```bash
git add hq/coin-info.php
git commit -m "feat(coin-usage): AJAX backend coin_summary/breakdown/ledger

3 read action di coin-info.php (sebelum layout): ringkasan periode,
breakdown per fitur+kategori (deduct), riwayat paginated + filter type.
Tenant scope, periode YYYY-MM validasi, LEFT JOIN pricing fallback."
```

---

## Task 2: Tabs + UI Riwayat + JS

**Files:**
- Modify: `hq/coin-info.php` (wrap konten pricing existing jadi tab 1, tambah tab 2 + JS)

**Interfaces:**
- Consumes: AJAX coin_summary/breakdown/ledger (Task 1), `$katMeta`, global `esc`/`fmtNum`
- Produces: UI tab Riwayat

- [ ] **Step 1: Tambah tab nav + wrap konten existing**

Setelah `require _layout_open.php` (line 58) / setelah `<h1>` heading, tambahkan tab nav:
```html
<div class="coin-tabs">
  <button class="coin-tab active" data-tab="harga" onclick="switchCoinTab('harga',this)">💰 Harga Fitur</button>
  <button class="coin-tab" data-tab="riwayat" onclick="switchCoinTab('riwayat',this)">📊 Riwayat &amp; Pemakaian</button>
</div>
```
Bungkus SELURUH konten pricing existing (info-banner + grouped pricing + bundles) dalam:
```html
<div id="tab-harga" class="coin-tab-pane">
  ... konten existing ...
</div>
```
Lalu tambah pane riwayat (kosong, diisi JS):
```html
<div id="tab-riwayat" class="coin-tab-pane" style="display:none">
  <div class="cu-toolbar">
    <h2 style="font-size:1.1rem;font-weight:700;color:#0F1C3A;margin:0">📊 Riwayat &amp; Pemakaian Coin</h2>
    <label style="font-size:13px;color:#6B7280">Periode:
      <input type="month" id="cuBulan" value="<?= date('Y-m') ?>" style="padding:6px 8px;border:1px solid #E5E7EB;border-radius:8px">
    </label>
  </div>
  <div id="cuCards" class="cu-cards"></div>
  <div id="cuBreakdown" class="cu-section"></div>
  <div class="cu-section">
    <div class="cu-ledger-head">
      <strong>Rincian Transaksi</strong>
      <select id="cuType" style="padding:6px 8px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
        <option value="semua">Semua</option><option value="deduct">Pemakaian</option><option value="topup">Top-up</option>
      </select>
    </div>
    <div id="cuLedger"></div>
    <div id="cuPager" class="cu-pager"></div>
  </div>
</div>
```

- [ ] **Step 2: CSS (di `<style>` existing)**

Tambah di blok `<style>`:
```css
.coin-tabs { display:flex; gap:8px; margin-bottom:18px; border-bottom:2px solid #F0F0F3; }
.coin-tab { background:none; border:none; padding:10px 16px; font-size:14px; font-weight:600; color:#6B7280; cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-2px; }
.coin-tab.active { color:#0F1C3A; border-bottom-color:#35E8D5; }
.cu-toolbar { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px; }
.cu-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:20px; }
.cu-card { background:#fff; border:1px solid #EEF0F3; border-radius:12px; padding:14px 16px; }
.cu-card .lbl { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#9CA3AF; }
.cu-card .val { font-size:22px; font-weight:800; font-family:'DM Mono',monospace; margin-top:4px; }
.cu-section { margin-bottom:22px; }
.cu-bar-row { display:flex; align-items:center; gap:10px; margin-bottom:8px; font-size:13px; }
.cu-bar-row .cu-bar-lbl { width:150px; flex-shrink:0; }
.cu-bar-track { flex:1; background:#F0F0F3; border-radius:6px; height:14px; overflow:hidden; }
.cu-bar-fill { height:100%; background:linear-gradient(90deg,#35E8D5,#1B9E92); }
.cu-bar-val { width:130px; text-align:right; flex-shrink:0; color:#6B7280; font-family:'DM Mono',monospace; }
.cu-ledger-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
.cu-table { width:100%; border-collapse:collapse; font-size:13px; }
.cu-table th { text-align:left; padding:8px; color:#9CA3AF; font-size:11px; text-transform:uppercase; border-bottom:1px solid #EEF0F3; }
.cu-table td { padding:8px; border-bottom:1px solid #F5F5F7; }
.cu-amt-deduct { color:#DC2626; font-weight:600; }
.cu-amt-topup { color:#059669; font-weight:600; }
.cu-pager { display:flex; gap:8px; justify-content:center; margin-top:14px; }
.cu-pager button { padding:6px 14px; border:1px solid #E5E7EB; border-radius:8px; background:#fff; cursor:pointer; font-size:13px; }
.cu-pager button:disabled { opacity:.4; cursor:default; }
.cu-empty { text-align:center; padding:30px; color:#9CA3AF; font-size:14px; }
```

- [ ] **Step 3: JS**

Sebelum `</body>` / di blok script bawah (cek apakah coin-info punya `<script>`; kalau tidak, tambah `<script>...</script>` sebelum `_layout_close` include atau akhir file):
```html
<script>
const CU_KAT = {
  dokumen:{ico:'📄',label:'Dokumen'}, whatsapp:{ico:'📱',label:'WhatsApp'},
  ai:{ico:'🤖',label:'AI Tools'}, export:{ico:'📤',label:'Export'}, lainnya:{ico:'⚙️',label:'Lainnya'}
};
let cuPage = 1, cuLoaded = false;

function switchCoinTab(tab, el) {
  document.querySelectorAll('.coin-tab').forEach(b => b.classList.toggle('active', b === el));
  document.getElementById('tab-harga').style.display   = tab === 'harga'   ? '' : 'none';
  document.getElementById('tab-riwayat').style.display = tab === 'riwayat' ? '' : 'none';
  if (tab === 'riwayat' && !cuLoaded) { cuLoaded = true; cuLoadAll(); }
}
function cuBulan() { return document.getElementById('cuBulan').value || ''; }

async function cuFetch(action, extra='') {
  const r = await fetch(`/hq/coin-info?action=${action}&bulan=${cuBulan()}${extra}`,
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
  return r.json();
}

async function cuLoadAll() { cuPage = 1; await Promise.all([cuLoadSummary(), cuLoadBreakdown(), cuLoadLedger()]); }

async function cuLoadSummary() {
  const d = await cuFetch('coin_summary');
  if (!d.ok) return;
  document.getElementById('cuCards').innerHTML =
    `<div class="cu-card"><div class="lbl">Saldo Coin</div><div class="val" style="color:#0F1C3A">${fmtNum(d.saldo)}</div></div>
     <div class="cu-card"><div class="lbl">Terpakai (periode)</div><div class="val" style="color:#DC2626">−${fmtNum(d.deduct)}</div></div>
     <div class="cu-card"><div class="lbl">Top-up (periode)</div><div class="val" style="color:#059669">+${fmtNum(d.topup)}</div></div>`;
}

async function cuLoadBreakdown() {
  const d = await cuFetch('coin_breakdown');
  const box = document.getElementById('cuBreakdown');
  if (!d.ok || !d.total_deduct) { box.innerHTML = '<div class="cu-empty">Belum ada pemakaian periode ini</div>'; return; }
  let html = '<strong style="display:block;margin-bottom:12px">Pemakaian per Kategori</strong>';
  d.per_kategori.forEach(k => {
    const meta = CU_KAT[k.kategori] || CU_KAT.lainnya;
    const pct = Math.round(k.total / d.total_deduct * 100);
    html += `<div class="cu-bar-row">
      <span class="cu-bar-lbl">${meta.ico} ${esc(meta.label)}</span>
      <span class="cu-bar-track"><span class="cu-bar-fill" style="width:${pct}%"></span></span>
      <span class="cu-bar-val">${fmtNum(k.total)} (${pct}%)</span></div>`;
  });
  box.innerHTML = html;
}

async function cuLoadLedger() {
  const type = document.getElementById('cuType').value;
  const d = await cuFetch('coin_ledger', `&type=${type}&page=${cuPage}`);
  const box = document.getElementById('cuLedger');
  if (!d.ok || !d.rows.length) { box.innerHTML = '<div class="cu-empty">Belum ada transaksi periode ini</div>'; document.getElementById('cuPager').innerHTML=''; return; }
  let rows = d.rows.map(r => {
    const isDed = r.type === 'deduct';
    const amt = (isDed ? '−' : '+') + fmtNum(r.amount);
    const cls = isDed ? 'cu-amt-deduct' : 'cu-amt-topup';
    const tgl = new Date(r.created_at.replace(' ','T')).toLocaleString('id-ID',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'});
    return `<tr><td>${esc(tgl)}</td><td>${esc(r.nama_fitur||'-')}</td><td>${esc(r.nama_outlet||'—')}</td>
      <td class="${cls}">${amt}</td><td style="font-family:'DM Mono',monospace">${fmtNum(r.balance_after)}</td></tr>`;
  }).join('');
  box.innerHTML = `<table class="cu-table"><thead><tr><th>Tanggal</th><th>Fitur</th><th>Outlet</th><th>Coin</th><th>Saldo</th></tr></thead><tbody>${rows}</tbody></table>`;
  document.getElementById('cuPager').innerHTML =
    `<button onclick="cuPage--;cuLoadLedger()" ${d.page<=1?'disabled':''}>‹ Prev</button>
     <span style="align-self:center;font-size:13px;color:#6B7280">Hal ${d.page}/${d.pages||1}</span>
     <button onclick="cuPage++;cuLoadLedger()" ${d.page>=d.pages?'disabled':''}>Next ›</button>`;
}

document.addEventListener('DOMContentLoaded', () => {
  const b = document.getElementById('cuBulan'); if (b) b.addEventListener('change', cuLoadAll);
  const t = document.getElementById('cuType'); if (t) t.addEventListener('change', () => { cuPage=1; cuLoadLedger(); });
});
</script>
```

- [ ] **Step 4: Verify**
```bash
grep -nE "switchCoinTab|cuLoadAll|tab-riwayat|coin-tab|cu-card" hq/coin-info.php
```
Expected: tabs + JS funcs muncul. Konten pricing existing terbungkus #tab-harga.

- [ ] **Step 5: Commit**
```bash
git add hq/coin-info.php
git commit -m "feat(coin-usage): tab Riwayat & Pemakaian UI + JS

Tabs (Harga existing jadi tab 1, Riwayat tab 2). Cards (saldo/terpakai/
topup), bar breakdown per kategori, tabel riwayat paginated + filter
type/periode. esc semua data, empty states, /0 guard."
```

---

## Task 3: E2E + Deploy

**Files:** None

- [ ] **Step 1: Push + smoke**
```bash
git push origin main
sleep 16
curl -s -o /dev/null -w "GET /hq/coin-info %{http_code}\n" "https://lamasy.harpy.id/hq/coin-info"
```
Expected: 302 (auth gate).

- [ ] **Step 2: Browser E2E (login owner)**

| # | Action | Expected |
|---|--------|----------|
| 1 | /hq/coin-info | 2 tab; tab Harga = pricing existing (no regression) |
| 2 | Klik tab Riwayat | 3 card (saldo match), breakdown bar, tabel |
| 3 | Ganti periode (bulan lain) | Data reload sesuai bulan |
| 4 | Filter type deduct/topup | Tabel ter-filter |
| 5 | Next/Prev pager | Halaman ganti |
| 6 | Bulan kosong transaksi | Empty state, card 0 |

- [ ] **Step 3: DB cross-check**
```bash
/opt/homebrew/opt/mysql-client/bin/mysql -e "
SELECT type, COUNT(*), SUM(amount) FROM coin_ledger
WHERE tenant_id=1 AND DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m')
GROUP BY type;" 2>&1
```
Bandingkan dgn card summary di UI.

- [ ] **Step 4: Update ledger**
```bash
cat >> .superpowers/sdd/progress.md <<'EOF'

Coin Usage History COMPLETE 2026-06-25 WIB.
Final state: <base>..<head>
EOF
```

---

## Self-Review Checklist

### Spec Coverage
- ✅ §3.2 coin_summary/breakdown/ledger → Task 1
- ✅ §4.1 tabs → Task 2 Step 1
- ✅ §4.2 cards/bar/tabel/filter → Task 2
- ✅ §5 edge (null feature COALESCE, /0 guard, empty, periode invalid) → Task 1+2
- ✅ §6 security (tenant scope, read-only, esc, regex periode) → Task 1+2
- ✅ §7 testing → Task 3

### Placeholder Scan
✓ Code lengkap (AJAX + HTML + CSS + JS). Step 2 Task 2 "wrap konten existing" → instruksi konkret (bungkus dlm #tab-harga), implementer baca file existing untuk batas konten.

### Type/Name Consistency
- ✅ Action: coin_summary/coin_breakdown/coin_ledger konsisten T1↔T2 (cuFetch)
- ✅ Response shape: summary{saldo,topup,deduct,count}, breakdown{per_fitur,per_kategori,total_deduct}, ledger{rows,total,page,pages} — JS baca persis
- ✅ Periode param `bulan` (YYYY-MM), `type` (semua/topup/deduct), `page`
- ✅ $tid dari $hqTenant['id'], $saldo existing — dipakai di summary
- ✅ esc/fmtNum global (renderGlobalJsHelpers via _layout_open, sama coin-info HQ)
- ✅ CU_KAT (JS) vs $katMeta (PHP) — JS standalone, kategori keys match (dokumen/whatsapp/ai/export/lainnya)

### Notes (verify saat implementasi)
- Task 1: AJAX block sisip SEBELUM `require _layout_open.php` (line 58). $db + $tid + $saldo sudah ke-set di atasnya.
- Task 2: cek coin-info.php apakah sudah ada `<script>` block + lokasi _layout_close — sisip JS sebelum penutup. Wrap konten pricing existing (info-banner + grouped + bundles) dalam #tab-harga.
- LIMIT/OFFSET placeholder: kalau PDO native prepare error, inline (int)-cast value (sudah aman karena (int)).

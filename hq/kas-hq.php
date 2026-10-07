<?php
// hq/kas-hq.php — Kas HQ: pencatatan pengeluaran/pemasukan level kantor pusat (gaji, sewa ruko,
// cicilan, dll) yang TERPISAH dari Kas outlet.
//
// Sengaja disimpan di tabel sendiri (hl_hq_kas) TANPA outlet_id: tidak dibaca oleh halaman Kas
// outlet, Laporan, Dashboard, L/R, AI chat, maupun FinancialCalculator → tidak muncul di outlet
// dan tidak mempengaruhi angka keuangan outlet. Akses: keuangan.view (lihat) / keuangan.edit (ubah).
$activePage = 'hq-kas';
$pageTitle  = 'Kas HQ';
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';

requirePermission('keuangan.view');

$db      = Database::get();
$tid     = (int) TenantResolver::id();
$user    = currentUser();
$uid     = (int) ($user['id'] ?? 0);
$canEdit = hasPermission('keuangan.edit');

// Tabel dibuat otomatis saat pertama dibuka (tanpa perlu migrasi manual).
try {
    $db->query("SELECT 1 FROM hl_hq_kas LIMIT 1");
} catch (Throwable $e) {
    $db->exec("CREATE TABLE IF NOT EXISTS hl_hq_kas (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id   INT NOT NULL,
        tanggal     DATE NOT NULL,
        tipe        ENUM('masuk','keluar') NOT NULL,
        kategori    VARCHAR(60) NOT NULL,
        keterangan  VARCHAR(500) NOT NULL,
        jumlah      DECIMAL(15,2) NOT NULL,
        created_by  INT NULL,
        updated_by  INT NULL,
        created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_tenant_tgl (tenant_id, tanggal),
        INDEX idx_tenant_kat (tenant_id, kategori)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

const HQ_KAS_DEFAULT_KATEGORI = [
    'Gaji Karyawan', 'Sewa Ruko', 'Listrik & Air', 'Cicilan & Pinjaman', 'Pajak & Perizinan',
    'Marketing & Iklan', 'Langganan Software', 'Operasional HQ', 'Setoran Outlet',
    'Modal / Investor', 'Pendapatan Lain', 'Lain-lain',
];

// ── AJAX Handler ───────────────────────────────────────────────
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '') !== 'navigate') {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    try {
        if ($action === 'list') {
            $dari   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['dari'] ?? '')   ? $_GET['dari']   : date('Y-m-01');
            $sampai = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['sampai'] ?? '') ? $_GET['sampai'] : date('Y-m-d');
            $tipe   = in_array($_GET['tipe'] ?? '', ['masuk', 'keluar'], true) ? $_GET['tipe'] : '';
            $kat    = substr(trim((string)($_GET['kategori'] ?? '')), 0, 60);

            $where = 'tenant_id = ? AND tanggal BETWEEN ? AND ?';
            $par   = [$tid, $dari, $sampai];
            if ($tipe !== '') { $where .= ' AND tipe = ?';     $par[] = $tipe; }
            if ($kat  !== '') { $where .= ' AND kategori = ?'; $par[] = $kat; }

            $st = $db->prepare("SELECT id, tanggal, tipe, kategori, keterangan, jumlah FROM hl_hq_kas
                                 WHERE $where ORDER BY tanggal DESC, id DESC LIMIT 1000");
            $st->execute($par);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            $st = $db->prepare("SELECT COALESCE(SUM(CASE WHEN tipe='masuk'  THEN jumlah END),0) masuk,
                                       COALESCE(SUM(CASE WHEN tipe='keluar' THEN jumlah END),0) keluar,
                                       COUNT(*) n
                                  FROM hl_hq_kas WHERE $where");
            $st->execute($par);
            $sum = $st->fetch(PDO::FETCH_ASSOC);

            $st = $db->prepare("SELECT tipe, kategori, SUM(jumlah) total, COUNT(*) n FROM hl_hq_kas
                                 WHERE $where GROUP BY tipe, kategori ORDER BY tipe, total DESC");
            $st->execute($par);
            $perKat = $st->fetchAll(PDO::FETCH_ASSOC);

            $st = $db->prepare("SELECT DISTINCT kategori FROM hl_hq_kas WHERE tenant_id=? ORDER BY kategori");
            $st->execute([$tid]);
            $kategori = array_values(array_unique(array_merge(
                HQ_KAS_DEFAULT_KATEGORI, array_column($st->fetchAll(PDO::FETCH_ASSOC), 'kategori')
            )));
            sort($kategori, SORT_NATURAL | SORT_FLAG_CASE);

            echo json_encode(['ok' => true, 'rows' => $rows, 'summary' => $sum, 'per_kategori' => $perKat, 'kategori' => $kategori]);
            exit;
        }

        if ($action === 'save') {
            if (!$canEdit) throw new RuntimeException('Akses ditolak (butuh izin keuangan.edit)');
            verifyCsrf();
            $id      = (int)($_POST['id'] ?? 0);
            $isUpdate = $id > 0;
            $tanggal = $_POST['tanggal'] ?? '';
            $tipe    = $_POST['tipe'] ?? '';
            $kat     = substr(trim(strip_tags((string)($_POST['kategori'] ?? ''))), 0, 60);
            $ket     = substr(trim(strip_tags((string)($_POST['keterangan'] ?? ''))), 0, 500);
            $jumlah  = (float)($_POST['jumlah'] ?? 0);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) || !strtotime($tanggal)) throw new RuntimeException('Tanggal tidak valid');
            if (!in_array($tipe, ['masuk', 'keluar'], true)) throw new RuntimeException('Tipe harus masuk atau keluar');
            if ($kat === '') throw new RuntimeException('Kategori wajib diisi');
            if ($ket === '') throw new RuntimeException('Keterangan wajib diisi');
            if ($jumlah <= 0) throw new RuntimeException('Jumlah harus lebih dari 0');
            if ($jumlah > 999999999999) throw new RuntimeException('Jumlah terlalu besar (maks Rp 999.999.999.999)');

            if ($id) {
                $st = $db->prepare("UPDATE hl_hq_kas SET tanggal=?, tipe=?, kategori=?, keterangan=?, jumlah=?, updated_by=?
                                     WHERE id=? AND tenant_id=?");
                $st->execute([$tanggal, $tipe, $kat, $ket, $jumlah, $uid, $id, $tid]);
                $exists = $db->prepare("SELECT 1 FROM hl_hq_kas WHERE id=? AND tenant_id=?");
                $exists->execute([$id, $tid]);
                if (!$exists->fetchColumn()) throw new RuntimeException('Data tidak ditemukan');
            } else {
                $db->prepare("INSERT INTO hl_hq_kas (tenant_id, tanggal, tipe, kategori, keterangan, jumlah, created_by)
                              VALUES (?,?,?,?,?,?,?)")
                   ->execute([$tid, $tanggal, $tipe, $kat, $ket, $jumlah, $uid]);
                $id = (int)$db->lastInsertId();
            }
            logAudit($isUpdate ? 'update' : 'create', 'kas_hq', "$tipe $kat Rp " . number_format($jumlah, 0, ',', '.') . ": $ket", (string)$id);
            echo json_encode(['ok' => true, 'id' => $id]);
            exit;
        }

        if ($action === 'delete') {
            if (!$canEdit) throw new RuntimeException('Akses ditolak (butuh izin keuangan.edit)');
            verifyCsrf();
            $id = (int)($_POST['id'] ?? 0);
            $st = $db->prepare("SELECT tipe, kategori, jumlah, keterangan FROM hl_hq_kas WHERE id=? AND tenant_id=?");
            $st->execute([$id, $tid]);
            $old = $st->fetch(PDO::FETCH_ASSOC);
            if (!$old) throw new RuntimeException('Data tidak ditemukan');
            $db->prepare("DELETE FROM hl_hq_kas WHERE id=? AND tenant_id=?")->execute([$id, $tid]);
            logAudit('delete', 'kas_hq', "Hapus {$old['tipe']} {$old['kategori']} Rp " . number_format((float)$old['jumlah'], 0, ',', '.') . ": {$old['keterangan']}", (string)$id);
            echo json_encode(['ok' => true]);
            exit;
        }

        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    } catch (RuntimeException $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('[hq/kas-hq] ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Terjadi kesalahan sistem. Silakan coba lagi.']);
    }
    exit;
}

// ── HTML Render ────────────────────────────────────────────────
require __DIR__ . '/_layout_open.php';
?>

<style>
.kh-toolbar { display:flex; gap:.5rem; flex-wrap:wrap; align-items:flex-end; margin-bottom:1rem; }
.kh-toolbar .fld { display:flex; flex-direction:column; gap:.2rem; }
.kh-toolbar label { font-size:.75rem; font-weight:600; color:var(--text-muted,#64748b); }
.kh-toolbar input, .kh-toolbar select { padding:.4rem .6rem; border:1px solid var(--border-color,#e2e8f0); border-radius:.4rem; font-size:.88rem; font-family:inherit; background:#fff; }
.kh-grow { flex:1; }
.kh-cards { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:.75rem; margin-bottom:1rem; }
.kh-card { background:#fff; border:1px solid var(--border-color,#e2e8f0); border-radius:.75rem; padding:.9rem 1rem; text-align:center; }
.kh-card .v { font-size:1.15rem; font-weight:800; font-family:var(--mono,monospace); }
.kh-card .l { font-size:.78rem; color:var(--text-muted,#64748b); margin-top:.2rem; }
.kh-in { color:#059669; } .kh-out { color:#dc2626; }
.kh-kat { display:flex; flex-wrap:wrap; gap:.4rem; margin-bottom:1rem; }
.kh-chip { font-size:.78rem; padding:.25rem .65rem; border-radius:999px; background:#f1f5f9; border:1px solid #e2e8f0; cursor:pointer; }
.kh-chip b { font-family:var(--mono,monospace); }
.kh-chip.out b { color:#dc2626; } .kh-chip.in b { color:#059669; }
.kh-wrap { overflow-x:auto; }
.kh-table { width:100%; border-collapse:collapse; font-size:.9rem; }
.kh-table th { background:var(--bg-subtle,#f4f6fa); font-weight:600; padding:.6rem .75rem; text-align:left; white-space:nowrap; border-bottom:2px solid var(--border-color,#e2e8f0); }
.kh-table td { padding:.6rem .75rem; border-bottom:1px solid var(--border-color,#e2e8f0); vertical-align:middle; }
.kh-table .num { text-align:right; font-family:var(--mono,monospace); white-space:nowrap; }
.kh-badge { display:inline-block; padding:.12rem .5rem; border-radius:999px; font-size:.74rem; font-weight:700; }
.kh-badge.masuk { background:#d1fae5; color:#065f46; } .kh-badge.keluar { background:#fee2e2; color:#991b1b; }
.kh-empty { text-align:center; color:var(--text-muted,#64748b); padding:2rem; }
.kh-note { background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; border-radius:.6rem; padding:.6rem .9rem; font-size:.82rem; margin-bottom:1rem; }
#khAlert { display:none; padding:.65rem 1rem; border-radius:.5rem; margin-bottom:1rem; font-size:.9rem; }
#khAlert.success { background:#d1fae5; color:#065f46; display:block; } #khAlert.error { background:#fee2e2; color:#991b1b; display:block; }
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:200; align-items:center; justify-content:center; padding:1rem; }
.modal-overlay.open { display:flex; }
.modal-box { background:#fff; border-radius:.75rem; padding:1.5rem; width:100%; max-width:480px; max-height:90vh; overflow-y:auto; box-shadow:0 8px 40px rgba(0,0,0,.18); }
.modal-box h3 { margin:0 0 1rem; font-size:1.1rem; }
.form-row { margin-bottom:.85rem; }
.form-row label { display:block; font-size:.82rem; font-weight:600; margin-bottom:.3rem; color:var(--text-muted,#64748b); }
.form-row input, .form-row textarea, .form-row select { width:100%; padding:.45rem .65rem; border:1px solid var(--border-color,#e2e8f0); border-radius:.4rem; font-size:.9rem; box-sizing:border-box; font-family:inherit; }
.form-row textarea { resize:vertical; min-height:64px; }
.tipe-sw { display:flex; gap:.5rem; }
.tipe-sw button { flex:1; padding:.5rem; border:1.5px solid #e2e8f0; border-radius:.5rem; background:#fff; font-weight:700; cursor:pointer; font-family:inherit; }
.tipe-sw button.on.keluar { background:#fee2e2; border-color:#dc2626; color:#991b1b; }
.tipe-sw button.on.masuk  { background:#d1fae5; border-color:#059669; color:#065f46; }
.modal-actions { display:flex; justify-content:flex-end; gap:.5rem; margin-top:1rem; }
.btn { display:inline-flex; align-items:center; gap:.35rem; padding:.45rem 1rem; border:none; border-radius:.45rem; font-size:.88rem; font-weight:600; cursor:pointer; }
.btn:hover { opacity:.85; }
.btn-primary { background:var(--color-primary,#1e40af); color:#fff; } .btn-danger { background:#dc2626; color:#fff; }
.btn-ghost { background:transparent; border:1px solid var(--border-color,#e2e8f0); color:var(--text-muted,#64748b); }
@media (max-width:700px) {
  .kh-cards { grid-template-columns:repeat(2,minmax(0,1fr)); }
  .kh-wrap { overflow-x:visible; }
  .kh-table thead { display:none; }
  .kh-table, .kh-table tbody, .kh-table tr, .kh-table td { display:block; width:100%; box-sizing:border-box; }
  .kh-table tr { border:1px solid var(--border-color,#e2e8f0); border-radius:12px; margin-bottom:.75rem; padding:.35rem .25rem; background:#fff; }
  .kh-table td { border:none; padding:.35rem .9rem; display:flex; justify-content:space-between; align-items:center; gap:1rem; text-align:right; }
  .kh-table td::before { content:attr(data-label); font-weight:600; color:var(--text-muted,#64748b); text-align:left; font-size:.8rem; }
  .kh-table td.cell-aksi::before { display:none; }
  .kh-table td.cell-aksi > div { display:flex; gap:.5rem; width:100%; }
  .kh-table td.cell-aksi .btn { flex:1 1 0; justify-content:center; }
  .kh-table .kh-empty { display:block; } .kh-table .kh-empty::before { display:none; }
}
</style>

<div class="hq-page-header" style="margin-bottom:1.25rem;">
  <h1 class="hq-page-title">Kas HQ</h1>
  <p class="hq-page-sub">Pengeluaran &amp; pemasukan kantor pusat (gaji, sewa ruko, cicilan, dll).</p>
</div>

<div class="kh-note">🔒 Data di sini <b>hanya terlihat di HQ</b> — tidak muncul di Kas, Laporan, maupun dashboard outlet, dan tidak mengubah angka keuangan outlet.</div>

<div id="khAlert"></div>

<div class="kh-toolbar">
  <div class="fld"><label>Dari</label><input type="date" id="fDari" onchange="loadKas()"></div>
  <div class="fld"><label>Sampai</label><input type="date" id="fSampai" onchange="loadKas()"></div>
  <div class="fld"><label>Tipe</label>
    <select id="fTipe" onchange="loadKas()"><option value="">Semua</option><option value="keluar">Keluar</option><option value="masuk">Masuk</option></select></div>
  <div class="fld"><label>Kategori</label>
    <select id="fKat" onchange="loadKas()"><option value="">Semua</option></select></div>
  <div class="kh-grow"></div>
  <?php if ($canEdit): ?><button class="btn btn-primary" onclick="openModal()">+ Tambah Transaksi</button><?php endif; ?>
</div>

<div class="kh-cards">
  <div class="kh-card"><div class="v kh-in" id="sMasuk">–</div><div class="l">💚 Total Masuk</div></div>
  <div class="kh-card"><div class="v kh-out" id="sKeluar">–</div><div class="l">❤️ Total Keluar</div></div>
  <div class="kh-card"><div class="v" id="sSaldo">–</div><div class="l">💎 Saldo</div></div>
  <div class="kh-card"><div class="v" id="sN">–</div><div class="l">📋 Transaksi</div></div>
</div>

<div class="kh-kat" id="khKat"></div>

<div class="kh-wrap">
  <table class="kh-table">
    <thead><tr><th>Tanggal</th><th>Tipe</th><th>Kategori</th><th>Keterangan</th><th class="num">Jumlah</th><?php if ($canEdit): ?><th>Aksi</th><?php endif; ?></tr></thead>
    <tbody id="khBody"><tr><td colspan="6" class="kh-empty">Memuat data…</td></tr></tbody>
  </table>
</div>

<?php if ($canEdit): ?>
<div class="modal-overlay" id="khModal" onclick="if(event.target===this)closeModal()">
  <div class="modal-box">
    <h3 id="khModalTitle">Tambah Transaksi</h3>
    <form id="khForm" onsubmit="submitForm(event)">
      <input type="hidden" id="mId" value="0">
      <input type="hidden" id="mTipe" value="keluar">
      <div class="form-row"><div class="tipe-sw">
        <button type="button" class="keluar on" id="swKeluar" onclick="setTipe('keluar')">❤️ Keluar</button>
        <button type="button" class="masuk" id="swMasuk" onclick="setTipe('masuk')">💚 Masuk</button>
      </div></div>
      <div class="form-row"><label for="mTanggal">Tanggal *</label><input type="date" id="mTanggal" required></div>
      <div class="form-row"><label for="mKat">Kategori *</label>
        <input type="text" id="mKat" list="khKatList" maxlength="60" required placeholder="Pilih atau ketik kategori baru">
        <datalist id="khKatList"></datalist></div>
      <div class="form-row"><label for="mJumlah">Jumlah (Rp) *</label>
        <input type="number" id="mJumlah" min="1" step="any" inputmode="decimal" required placeholder="0"></div>
      <div class="form-row"><label for="mKet">Keterangan *</label>
        <textarea id="mKet" maxlength="500" required placeholder="Contoh: Gaji karyawan bulan Oktober"></textarea></div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal()">Batal</button>
        <button type="submit" class="btn btn-primary" id="mSave">Simpan</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
const KH_CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const KH_CAN_EDIT = <?= $canEdit ? 'true' : 'false' ?>;
let KH_ROWS = [];

const khEsc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const khRp  = n => 'Rp ' + Math.round(Number(n||0)).toLocaleString('id-ID');
const khDate = d => { const p = String(d).split('-'); return p.length===3 ? p[2]+'/'+p[1]+'/'+p[0] : d; };
const khLocal = d => d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');

async function khJson(r) {
  const t = await r.text();
  try { return JSON.parse(t); }
  catch (e) { throw new Error(r.status === 403 ? 'Sesi/token kedaluwarsa — muat ulang halaman lalu coba lagi.' : 'Respons server tidak valid (' + r.status + ').'); }
}

function khAlert(msg, type='success') {
  const el = document.getElementById('khAlert');
  el.textContent = msg; el.className = type;
  setTimeout(() => { el.className = ''; el.style.display = 'none'; }, 3500);
}

async function loadKas() {
  const q = new URLSearchParams({
    action:'list', dari:document.getElementById('fDari').value, sampai:document.getElementById('fSampai').value,
    tipe:document.getElementById('fTipe').value, kategori:document.getElementById('fKat').value
  });
  try {
    const r = await fetch('/hq/kas-hq?' + q, { headers:{'X-Requested-With':'XMLHttpRequest'} });
    const j = await khJson(r);
    if (!j.ok) throw new Error(j.error || 'Gagal memuat data');
    KH_ROWS = j.rows;

    const masuk = +j.summary.masuk, keluar = +j.summary.keluar, saldo = masuk - keluar;
    document.getElementById('sMasuk').textContent  = khRp(masuk);
    document.getElementById('sKeluar').textContent = khRp(keluar);
    const sEl = document.getElementById('sSaldo'); sEl.textContent = khRp(saldo); sEl.style.color = saldo >= 0 ? '#059669' : '#dc2626';
    document.getElementById('sN').textContent = j.summary.n;

    // dropdown filter kategori + datalist form
    const fKat = document.getElementById('fKat'), cur = fKat.value;
    fKat.innerHTML = '<option value="">Semua</option>' + j.kategori.map(k => `<option value="${khEsc(k)}">${khEsc(k)}</option>`).join('');
    fKat.value = cur;
    const dl = document.getElementById('khKatList');
    if (dl) dl.innerHTML = j.kategori.map(k => `<option value="${khEsc(k)}">`).join('');

    document.getElementById('khKat').innerHTML = j.per_kategori.map(p =>
      `<span class="kh-chip ${p.tipe==='keluar'?'out':'in'}" onclick="filterKat(this.dataset.k)" data-k="${khEsc(p.kategori)}">${khEsc(p.kategori)} · <b>${khRp(p.total)}</b> (${p.n}×)</span>`).join('');

    const cols = KH_CAN_EDIT ? 6 : 5;
    if (!j.rows.length) {
      document.getElementById('khBody').innerHTML = `<tr><td colspan="${cols}" class="kh-empty">Belum ada transaksi di periode ini.</td></tr>`;
      return;
    }
    document.getElementById('khBody').innerHTML = j.rows.map((t, i) => `<tr>
      <td data-label="Tanggal">${khDate(t.tanggal)}</td>
      <td data-label="Tipe"><span class="kh-badge ${t.tipe}">${t.tipe === 'masuk' ? 'Masuk' : 'Keluar'}</span></td>
      <td data-label="Kategori">${khEsc(t.kategori)}</td>
      <td data-label="Keterangan">${khEsc(t.keterangan)}</td>
      <td class="num ${t.tipe==='masuk'?'kh-in':'kh-out'}" data-label="Jumlah">${t.tipe==='masuk'?'+':'−'} ${khRp(t.jumlah)}</td>
      ${KH_CAN_EDIT ? `<td class="cell-aksi"><div>
        <button class="btn btn-ghost" style="padding:.3rem .6rem;font-size:.8rem" data-i="${i}" onclick="openModal(KH_ROWS[this.dataset.i])">Edit</button>
        <button class="btn btn-danger" style="padding:.3rem .6rem;font-size:.8rem" data-i="${i}" onclick="delRow(KH_ROWS[this.dataset.i])">Hapus</button>
      </div></td>` : ''}
    </tr>`).join('');
  } catch (e) {
    document.getElementById('khBody').innerHTML = `<tr><td colspan="6" class="kh-empty" style="color:#dc2626">Gagal memuat: ${khEsc(e.message)}</td></tr>`;
  }
}

function filterKat(k) { document.getElementById('fKat').value = k; loadKas(); }

function setTipe(t) {
  document.getElementById('mTipe').value = t;
  document.getElementById('swKeluar').className = 'keluar' + (t==='keluar' ? ' on' : '');
  document.getElementById('swMasuk').className  = 'masuk'  + (t==='masuk'  ? ' on' : '');
}

function openModal(row) {
  document.getElementById('khForm').reset();
  if (row) {
    document.getElementById('khModalTitle').textContent = 'Edit Transaksi';
    document.getElementById('mId').value = row.id;
    setTipe(row.tipe);
    document.getElementById('mTanggal').value = row.tanggal;
    document.getElementById('mKat').value = row.kategori;
    document.getElementById('mJumlah').value = Math.round(row.jumlah);
    document.getElementById('mKet').value = row.keterangan;
  } else {
    document.getElementById('khModalTitle').textContent = 'Tambah Transaksi';
    document.getElementById('mId').value = '0';
    setTipe('keluar');
    document.getElementById('mTanggal').value = khLocal(new Date());
  }
  document.getElementById('khModal').classList.add('open');
}
function closeModal() { document.getElementById('khModal').classList.remove('open'); }

async function submitForm(e) {
  e.preventDefault();
  const btn = document.getElementById('mSave'); btn.disabled = true; btn.textContent = 'Menyimpan…';
  try {
    const fd = new FormData();
    fd.append('id', document.getElementById('mId').value);
    fd.append('tipe', document.getElementById('mTipe').value);
    fd.append('tanggal', document.getElementById('mTanggal').value);
    fd.append('kategori', document.getElementById('mKat').value);
    fd.append('jumlah', document.getElementById('mJumlah').value);
    fd.append('keterangan', document.getElementById('mKet').value);
    const r = await fetch('/hq/kas-hq?action=save', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest','X-CSRF-Token':KH_CSRF}, body:fd });
    const j = await khJson(r);
    if (!j.ok) throw new Error(j.error || 'Gagal menyimpan');
    closeModal(); khAlert('Transaksi tersimpan.'); loadKas();
  } catch (err) { khAlert(err.message, 'error'); }
  finally { btn.disabled = false; btn.textContent = 'Simpan'; }
}

async function delRow(row) {
  if (!await lmConfirm(`Hapus transaksi "${row.kategori} — ${khRp(row.jumlah)}" tanggal ${khDate(row.tanggal)}? Tidak bisa dibatalkan.`)) return;
  try {
    const fd = new FormData(); fd.append('id', row.id);
    const r = await fetch('/hq/kas-hq?action=delete', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest','X-CSRF-Token':KH_CSRF}, body:fd });
    const j = await khJson(r);
    if (!j.ok) throw new Error(j.error || 'Gagal menghapus');
    khAlert('Transaksi dihapus.'); loadKas();
  } catch (err) { khAlert(err.message, 'error'); }
}

(function init() {
  const now = new Date();
  document.getElementById('fDari').value   = khLocal(new Date(now.getFullYear(), now.getMonth(), 1));
  document.getElementById('fSampai').value = khLocal(now);
  loadKas();
})();
</script>

<?php require __DIR__ . '/_layout_close.php'; ?>

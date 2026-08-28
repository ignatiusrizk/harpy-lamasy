<?php
// ════════════════════════════════════════════════════════════════════
// hq/export.php — Self-serve Export Data
// Owner/admin pilih modul + periode + outlet, download ZIP berisi CSV.
//
// Rate limit: max 5 export/hari/user. Audit log per export.
// ════════════════════════════════════════════════════════════════════

$activePage = 'hq-export';
$pageTitle  = '⬇️ Export Data';
define('ROOT', dirname(__DIR__));
require_once ROOT . '/middleware/hq_guard.php';

$db  = Database::get();
$tid = (int)$hqTenant['id'];
$uid = (int)($_SESSION['user_id'] ?? 0);

requirePermission('export.data');

// Tabel yang available untuk export
$AVAILABLE = [
    'orders'    => ['🧺 Orders + Items', 'hl_transaksi', 'tanggal'],
    'pelanggan' => ['👥 Customers',       'hl_pelanggan', 'created_at'],
    'kas'       => ['💰 Kas Masuk/Keluar', 'hl_kas',       'tanggal'],
    'gaji'      => ['💵 Gaji + Komponen',  'hl_gaji',      'created_at'],
    'absensi'   => ['📅 Absensi',          'hl_absensi',   'tanggal'],
    'mutasi'    => ['📦 Mutasi Inventori', 'hl_bahan_mutasi', 'created_at'],
    'audit'     => ['📋 Audit Log',        'hl_audit_log', 'created_at'],
];

const MAX_EXPORTS_PER_DAY = 5;
const MAX_ROWS_PER_TABLE  = 50000;

// ── API: status (rate limit info) ───────────────────────
if (($_GET['action'] ?? '') === 'status') {
    header('Content-Type: application/json');
    $st = $db->prepare(
        "SELECT COUNT(*) FROM hl_audit_log
          WHERE tenant_id=? AND user_id=? AND modul='export' AND aksi='export_data'
            AND DATE(created_at)=CURDATE()"
    );
    $st->execute([$tid, $uid]);
    $used = (int)$st->fetchColumn();
    echo json_encode([
        'used'      => $used,
        'remaining' => max(0, MAX_EXPORTS_PER_DAY - $used),
        'limit'     => MAX_EXPORTS_PER_DAY,
    ]);
    exit;
}

// ── POST: generate ZIP ──────────────────────────────────
if (($_GET['action'] ?? '') === 'generate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    // Rate limit
    $rl = $db->prepare(
        "SELECT COUNT(*) FROM hl_audit_log
          WHERE tenant_id=? AND user_id=? AND modul='export' AND aksi='export_data'
            AND DATE(created_at)=CURDATE()"
    );
    $rl->execute([$tid, $uid]);
    if ((int)$rl->fetchColumn() >= MAX_EXPORTS_PER_DAY) {
        http_response_code(429);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Limit export hari ini sudah habis ('.MAX_EXPORTS_PER_DAY.'/hari). Coba lagi besok.']);
        exit;
    }

    // Parse input
    $tables = array_intersect((array)($_POST['tables'] ?? []), array_keys($AVAILABLE));
    $dari   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['dari'] ?? '') ? $_POST['dari'] : date('Y-m-01');
    $sampai = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['sampai'] ?? '') ? $_POST['sampai'] : date('Y-m-d');
    $outletId = (int)($_POST['outlet_id'] ?? 0); // 0 = semua outlet

    if (empty($tables)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Pilih minimal 1 modul untuk di-export.']);
        exit;
    }

    // Buat temp file untuk ZIP
    $tmpZip = tempnam(sys_get_temp_dir(), 'lmexp_');
    $zip = new ZipArchive();
    if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmpZip);
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Gagal buat ZIP.']);
        exit;
    }

    $rowSummary = [];

    // Helper: stream rows ke CSV string
    $toCsv = function (array $rows) {
        if (empty($rows)) return "\xEF\xBB\xBF(no data)\n";
        $out = fopen('php://temp', 'r+');
        // BOM untuk Excel UTF-8
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $r) fputcsv($out, $r);
        rewind($out);
        return stream_get_contents($out);
    };

    // README
    $zip->addFromString('README.txt',
        "LAMASY Export\n" .
        "===========\n" .
        "Tenant ID  : $tid\n" .
        "Generated  : " . date('Y-m-d H:i:s') . "\n" .
        "Periode    : $dari sampai $sampai\n" .
        "Outlet     : " . ($outletId ? "id=$outletId" : "semua") . "\n" .
        "Modul      : " . implode(', ', $tables) . "\n\n" .
        "File CSV pakai UTF-8 BOM, kompatibel Excel/Numbers/Google Sheets.\n" .
        "Max " . MAX_ROWS_PER_TABLE . " baris per tabel — kalau data lebih dari itu, sempitkan periode.\n"
    );

    $outletFilter = $outletId > 0 ? " AND outlet_id = $outletId" : ""; // $outletId sudah (int)

    // ── Orders + Items ─────
    if (in_array('orders', $tables, true)) {
        $sql = "SELECT id, no_order, tanggal, outlet_id, pelanggan_id, nama_pelanggan, telepon,
                       subtotal, diskon, biaya_tambahan, biaya_lainnya, biaya_lainnya_label, total,
                       dp, sisa_bayar, metode_bayar,
                       tipe_order, status_bayar, status_proses, estimasi_selesai, catatan,
                       parfum, created_by, created_at, updated_at
                  FROM hl_transaksi
                 WHERE tenant_id=? AND tanggal BETWEEN ? AND ? $outletFilter
                 ORDER BY tanggal, id
                 LIMIT " . MAX_ROWS_PER_TABLE;
        $st = $db->prepare($sql);
        $st->execute([$tid, $dari, $sampai]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $zip->addFromString('orders.csv', $toCsv($rows));
        $rowSummary['orders'] = count($rows);

        // Items (anak dari orders dalam periode)
        if (!empty($rows)) {
            $ids = array_column($rows, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sqlI = "SELECT id, transaksi_id, layanan_id, nama_layanan, satuan, jumlah,
                            harga_satuan, subtotal, biaya_express, express_tier_nama, catatan_item
                       FROM hl_transaksi_item
                      WHERE tenant_id=? AND transaksi_id IN ($placeholders)
                      ORDER BY transaksi_id, id";
            $stI = $db->prepare($sqlI);
            $stI->execute(array_merge([$tid], $ids));
            $rowsI = $stI->fetchAll(PDO::FETCH_ASSOC);
            $zip->addFromString('order_items.csv', $toCsv($rowsI));
            $rowSummary['order_items'] = count($rowsI);
        }
    }

    // ── Pelanggan ─────
    if (in_array('pelanggan', $tables, true)) {
        // Customer = tenant-scoped (sesuai unique phone per tenant policy)
        $sql = "SELECT id, nama, telepon, alamat, tipe, segmen, tier, registered_outlet_id,
                       poin_balance, total_order, total_visit_count, last_transaksi,
                       saldo_deposit, is_active, created_at
                  FROM hl_pelanggan
                 WHERE tenant_id=? AND created_at BETWEEN ? AND ?
                 ORDER BY created_at, id
                 LIMIT " . MAX_ROWS_PER_TABLE;
        $st = $db->prepare($sql);
        $st->execute([$tid, $dari . ' 00:00:00', $sampai . ' 23:59:59']);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $zip->addFromString('pelanggan.csv', $toCsv($rows));
        $rowSummary['pelanggan'] = count($rows);
    }

    // ── Kas ─────
    if (in_array('kas', $tables, true)) {
        $sql = "SELECT id, tanggal, outlet_id, tipe, kategori, keterangan, jumlah,
                       ref_order, created_by, created_at
                  FROM hl_kas
                 WHERE tenant_id=? AND tanggal BETWEEN ? AND ? $outletFilter
                 ORDER BY tanggal, id
                 LIMIT " . MAX_ROWS_PER_TABLE;
        $st = $db->prepare($sql);
        $st->execute([$tid, $dari, $sampai]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $zip->addFromString('kas.csv', $toCsv($rows));
        $rowSummary['kas'] = count($rows);
    }

    // ── Gaji + Komponen ─────
    if (in_array('gaji', $tables, true)) {
        $sql = "SELECT g.id, g.outlet_id, g.user_id, u.nama AS karyawan_nama, g.bulan,
                       g.gaji_pokok, g.bonus, g.potongan, g.total, g.status, g.dibayar_at,
                       g.created_at
                  FROM hl_gaji g
                  LEFT JOIN hl_users u ON u.id=g.user_id AND u.tenant_id=g.tenant_id
                 WHERE g.tenant_id=? AND g.created_at BETWEEN ? AND ? "
                . ($outletId > 0 ? " AND g.outlet_id = $outletId" : "") .
                " ORDER BY g.bulan, g.outlet_id, g.id
                  LIMIT " . MAX_ROWS_PER_TABLE;
        $st = $db->prepare($sql);
        $st->execute([$tid, $dari . ' 00:00:00', $sampai . ' 23:59:59']);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $zip->addFromString('gaji.csv', $toCsv($rows));
        $rowSummary['gaji'] = count($rows);

        // Komponen (breakdown)
        if (!empty($rows)) {
            $ids = array_column($rows, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stK = $db->prepare(
                "SELECT id, gaji_id, jenis, rule_id, nama, amount, keterangan
                   FROM hl_gaji_komponen
                  WHERE gaji_id IN ($placeholders)
                  ORDER BY gaji_id, id"
            );
            $stK->execute($ids);
            $rowsK = $stK->fetchAll(PDO::FETCH_ASSOC);
            $zip->addFromString('gaji_komponen.csv', $toCsv($rowsK));
            $rowSummary['gaji_komponen'] = count($rowsK);
        }
    }

    // ── Absensi ─────
    if (in_array('absensi', $tables, true)) {
        $sql = "SELECT a.id, a.tanggal, a.outlet_id, a.user_id, u.nama AS karyawan_nama,
                       a.jam_masuk, a.jam_keluar, a.durasi_menit, a.status, a.catatan, a.created_at
                  FROM hl_absensi a
                  LEFT JOIN hl_users u ON u.id=a.user_id AND u.tenant_id=a.tenant_id
                 WHERE a.tenant_id=? AND a.tanggal BETWEEN ? AND ? "
                . ($outletId > 0 ? " AND a.outlet_id = $outletId" : "") .
                " ORDER BY a.tanggal, a.outlet_id, a.id
                  LIMIT " . MAX_ROWS_PER_TABLE;
        $st = $db->prepare($sql);
        $st->execute([$tid, $dari, $sampai]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $zip->addFromString('absensi.csv', $toCsv($rows));
        $rowSummary['absensi'] = count($rows);
    }

    // ── Mutasi bahan ─────
    if (in_array('mutasi', $tables, true)) {
        $sql = "SELECT m.id, m.outlet_id, m.bahan_id, b.nama AS bahan_nama, b.kategori,
                       m.tipe, m.jumlah, m.stok_sebelum, m.stok_sesudah, m.harga_beli,
                       m.supplier, m.catatan, m.outlet_tujuan_id, m.input_by, m.created_at
                  FROM hl_bahan_mutasi m
                  LEFT JOIN hl_bahan b ON b.id=m.bahan_id AND b.tenant_id=m.tenant_id
                 WHERE m.tenant_id=? AND m.created_at BETWEEN ? AND ? "
                . ($outletId > 0 ? " AND m.outlet_id = $outletId" : "") .
                " ORDER BY m.created_at, m.id
                  LIMIT " . MAX_ROWS_PER_TABLE;
        $st = $db->prepare($sql);
        $st->execute([$tid, $dari . ' 00:00:00', $sampai . ' 23:59:59']);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $zip->addFromString('mutasi_bahan.csv', $toCsv($rows));
        $rowSummary['mutasi'] = count($rows);
    }

    // ── Audit log ─────
    if (in_array('audit', $tables, true)) {
        $sql = "SELECT id, user_id, user_nama, user_role, modul, aksi, keterangan, ref_id,
                       ip_address, created_at, outlet_id
                  FROM hl_audit_log
                 WHERE tenant_id=? AND created_at BETWEEN ? AND ? "
                . ($outletId > 0 ? " AND (outlet_id = $outletId OR outlet_id IS NULL)" : "") .
                " ORDER BY created_at, id
                  LIMIT " . MAX_ROWS_PER_TABLE;
        $st = $db->prepare($sql);
        $st->execute([$tid, $dari . ' 00:00:00', $sampai . ' 23:59:59']);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $zip->addFromString('audit_log.csv', $toCsv($rows));
        $rowSummary['audit'] = count($rows);
    }

    $zip->close();

    // ── Log to audit (sebelum send, biar idempotent) ──────
    $totalRows = array_sum($rowSummary);
    $keterangan = "tables=" . implode(',', $tables)
                . " periode={$dari}..{$sampai}"
                . " outlet=" . ($outletId ?: 'all')
                . " rows=" . $totalRows;
    $userNama = $_SESSION['hl_user']['nama'] ?? 'Unknown';
    $userRole = $_SESSION['hl_user']['role'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $logSt = $db->prepare(
        "INSERT INTO hl_audit_log (tenant_id, user_id, user_nama, user_role, modul, aksi,
                                    keterangan, ip_address, user_agent, created_at)
         VALUES (?, ?, ?, ?, 'export', 'export_data', ?, ?, ?, NOW())"
    );
    $logSt->execute([$tid, $uid, $userNama, $userRole, $keterangan, $ip, $ua]);

    // ── Stream ZIP ke browser ──────
    $filename = "lamasy-export-{$dari}-to-{$sampai}-" . date('YmdHis') . ".zip";
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmpZip));
    header('Cache-Control: no-store');
    readfile($tmpZip);
    @unlink($tmpZip);
    exit;
}

// ── Render UI ──────
require ROOT . '/hq/_layout_open.php';

// Outlet list for filter
$outlets = $db->prepare("SELECT id, nama_outlet FROM outlets WHERE tenant_id=? AND status IN ('trial','grace','active') ORDER BY is_main DESC, nama_outlet");
$outlets->execute([$tid]);
$outletList = $outlets->fetchAll(PDO::FETCH_ASSOC);
?>

<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:18px">
  <div>
    <h1 style="margin:0">⬇️ Export Data</h1>
    <p style="margin:6px 0 0;color:#64748B;font-size:13px">
      Download data tenant ke ZIP berisi CSV. Maks <strong><?= MAX_EXPORTS_PER_DAY ?> export/hari</strong>,
      <?= number_format(MAX_ROWS_PER_TABLE, 0, ',', '.') ?> baris/tabel.
    </p>
  </div>
  <div id="rateInfo" style="font-size:12px;color:#64748B;background:#F1F5F9;padding:8px 12px;border-radius:8px">
    ⏳ memuat kuota...
  </div>
</div>

<div class="hl-card" style="padding:20px">
  <form id="expForm">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(getCsrfToken()) ?>">

    <style>
    .exp-filter{display:grid;grid-template-columns:1fr 1fr 2fr;gap:14px;margin-bottom:18px}
    @media(max-width:640px){.exp-filter{grid-template-columns:1fr 1fr}.exp-filter>div:last-child{grid-column:1/-1}}
    </style>
    <div class="exp-filter">
      <div>
        <label style="display:block;font-weight:600;font-size:13px;color:#0F1C3A;margin-bottom:4px">Dari Tanggal</label>
        <input type="date" name="dari" class="hl-input" value="<?= date('Y-m-01') ?>" required>
      </div>
      <div>
        <label style="display:block;font-weight:600;font-size:13px;color:#0F1C3A;margin-bottom:4px">Sampai Tanggal</label>
        <input type="date" name="sampai" class="hl-input" value="<?= date('Y-m-d') ?>" required>
      </div>
      <div>
        <label style="display:block;font-weight:600;font-size:13px;color:#0F1C3A;margin-bottom:4px">Outlet</label>
        <select name="outlet_id" class="hl-input">
          <option value="0">📍 Semua Outlet</option>
          <?php foreach ($outletList as $o): ?>
            <option value="<?= (int)$o['id'] ?>">🏪 <?= htmlspecialchars($o['nama_outlet']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div style="font-weight:600;font-size:13px;color:#0F1C3A;margin-bottom:8px">Pilih Modul:</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;margin-bottom:20px">
      <?php foreach ($AVAILABLE as $key => [$label]): ?>
        <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;cursor:pointer">
          <input type="checkbox" name="tables[]" value="<?= htmlspecialchars($key) ?>">
          <span style="font-size:13px;color:#0F1C3A"><?= htmlspecialchars($label) ?></span>
        </label>
      <?php endforeach; ?>
    </div>

    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding-top:14px;border-top:1px solid #E5E7EB">
      <div style="font-size:12px;color:#64748B">
        💡 File ZIP berisi CSV (UTF-8 BOM, kompatibel Excel). Buka README.txt di dalam ZIP untuk metadata.
      </div>
      <button type="submit" id="btnExport" class="hl-btn hl-btn-primary">
        ⬇️ Generate ZIP
      </button>
    </div>
  </form>
</div>

<div id="msg" style="margin-top:14px"></div>

<script>
async function loadStatus() {
  try {
    const r = await fetch('/hq/export?action=status');
    const d = await r.json();
    document.getElementById('rateInfo').innerHTML =
      `📊 Kuota: <strong>${d.remaining}/${d.limit}</strong> export sisa hari ini`;
    if (d.remaining === 0) {
      document.getElementById('btnExport').disabled = true;
      document.getElementById('btnExport').textContent = '🚫 Kuota Habis';
    }
  } catch (e) {}
}
loadStatus();

document.getElementById('expForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const form = e.target;
  const tables = form.querySelectorAll('input[name="tables[]"]:checked');
  if (tables.length === 0) {
    showMsg('error', 'Pilih minimal 1 modul untuk di-export.');
    return;
  }

  const btn = document.getElementById('btnExport');
  btn.disabled = true;
  btn.textContent = '⏳ Generating ZIP...';
  showMsg('info', 'Mengumpulkan data... jangan tutup tab. Bisa makan beberapa detik untuk data besar.');

  try {
    const fd = new FormData(form);
    const r = await fetch('/hq/export?action=generate', { method: 'POST', body: fd });
    if (!r.ok) {
      const err = await r.json().catch(() => ({error: 'Gagal export.'}));
      showMsg('error', err.error || 'Gagal export.');
      btn.disabled = false;
      btn.textContent = '⬇️ Generate ZIP';
      return;
    }
    // Download file
    const blob = await r.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    const cd = r.headers.get('Content-Disposition') || '';
    const m = cd.match(/filename="([^"]+)"/);
    a.download = m ? m[1] : 'lamasy-export.zip';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
    showMsg('success', '✅ Export berhasil. File ZIP sudah diunduh.');
    btn.textContent = '✅ Selesai';
    setTimeout(() => {
      btn.disabled = false;
      btn.textContent = '⬇️ Generate ZIP';
      loadStatus();
    }, 2000);
  } catch (e) {
    showMsg('error', 'Network error: ' + e.message);
    btn.disabled = false;
    btn.textContent = '⬇️ Generate ZIP';
  }
});

function showMsg(type, text) {
  const colors = {
    success: ['#D1FAE5', '#065F46'],
    error:   ['#FEE2E2', '#991B1B'],
    info:    ['#DBEAFE', '#1E40AF'],
  };
  const [bg, fg] = colors[type] || colors.info;
  document.getElementById('msg').innerHTML =
    `<div style="background:${bg};color:${fg};padding:12px 14px;border-radius:8px;font-size:13px">${text}</div>`;
}
</script>

<?php require ROOT . '/hq/_layout_close.php'; ?>

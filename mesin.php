<?php
// ══════════════════════════════════════════════════════
// mesin.php — Self-Service Mesin (Approach A: Manual Confirm)
//
// Tab Live    : grid real-time semua mesin + status + countdown
// Tab Master  : CRUD mesin + opsi cycle (durasi/tarif)
// Tab Riwayat : daftar sesi (booked/running/done/batal) + filter
//
// Lifecycle sesi:
//   booked  → customer baru book via /self?m=KODE
//   running → staff klik "Konfirmasi Mulai" (+ mesin dinyalakan manual)
//   done    → durasi habis (auto via JS countdown atau klik "Tandai Selesai")
//   batal   → dibatalkan staff
// ══════════════════════════════════════════════════════
$activePage = 'mesin';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/PushSender.php';
require_once __DIR__ . '/components.php';

$user = currentUser();

if (!hasPermission('mesin.view') && !hasPermission('pos.view')) {
    requirePermission('mesin.view');
}
$canOperate = hasPermission('mesin.operate') || hasPermission('pos.create');
$canManage  = hasPermission('mesin.manage') || hasPermission('layanan.edit');

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $tid = TenantResolver::id();
    $oid = TenantResolver::outletId();
    $db  = Database::get();

    // ─── LIST LIVE (semua mesin + sesi aktif terakhir) ─
    if ($action === 'list_live') {
        $rows = TenantQuery::raw(
            "SELECT m.*,
                    s.id            AS sesi_id,
                    s.pelanggan_nama,
                    s.pelanggan_telepon,
                    s.durasi_menit  AS sesi_durasi,
                    s.tarif         AS sesi_tarif,
                    s.cycle_label,
                    s.status_bayar,
                    s.metode_bayar,
                    s.started_at,
                    s.estimated_done_at,
                    s.booked_at,
                    s.status        AS sesi_status
             FROM hl_mesin m
             LEFT JOIN hl_mesin_sesi s
                    ON s.mesin_id = m.id
                   AND s.status IN ('booked','running')
                   AND s.id = (
                       SELECT MAX(s2.id) FROM hl_mesin_sesi s2
                       WHERE s2.mesin_id = m.id AND s2.status IN ('booked','running')
                   )
             WHERE m.tenant_id = ? AND m.outlet_id = ? AND m.is_active = 1
             ORDER BY m.tipe ASC, m.nama ASC",
            [$tid, $oid]
        );
        // Auto-expire: sesi running yg sudah lewat estimated_done_at + 5 menit toleransi → kasih flag
        $now = time();
        foreach ($rows as &$r) {
            $r['need_attention'] = 0;
            if ($r['sesi_status'] === 'running' && !empty($r['estimated_done_at'])) {
                $est = strtotime($r['estimated_done_at']);
                if ($now > $est) $r['need_attention'] = 1;
            }
        }
        unset($r);
        echo json_encode(['data' => $rows, 'server_time' => date('Y-m-d H:i:s')]);
        exit;
    }

    // ─── LIST MASTER (semua mesin + cycle list) ───────
    if ($action === 'list_master') {
        $mesinList = TenantQuery::raw(
            "SELECT * FROM hl_mesin WHERE tenant_id=? AND outlet_id=? ORDER BY tipe, nama",
            [$tid, $oid]
        );
        $mesinIds = array_column($mesinList, 'id');
        $cycles = [];
        if ($mesinIds) {
            $place = implode(',', array_fill(0, count($mesinIds), '?'));
            $cycles = $db->prepare("SELECT * FROM hl_mesin_cycle WHERE mesin_id IN ($place) ORDER BY mesin_id, urutan, durasi_menit");
            $cycles->execute($mesinIds);
            $cycles = $cycles->fetchAll(PDO::FETCH_ASSOC);
        }
        $cyclesByMesin = [];
        foreach ($cycles as $c) $cyclesByMesin[$c['mesin_id']][] = $c;
        foreach ($mesinList as &$m) $m['cycles'] = $cyclesByMesin[$m['id']] ?? [];
        unset($m);
        echo json_encode(['data' => $mesinList]);
        exit;
    }

    // ─── LIST RIWAYAT SESI ─────────────────────────────
    if ($action === 'list_sesi') {
        $dari   = $_GET['dari']   ?? date('Y-m-01');
        $sampai = $_GET['sampai'] ?? date('Y-m-d');
        $status = $_GET['status'] ?? '';
        $where = ['s.tenant_id=?', 's.outlet_id=?', 'DATE(s.booked_at) BETWEEN ? AND ?'];
        $params = [$tid, $oid, $dari, $sampai];
        if ($status) { $where[] = 's.status=?'; $params[] = $status; }
        $whereStr = implode(' AND ', $where);

        $rows = TenantQuery::raw(
            "SELECT s.*, m.nama AS mesin_nama, m.kode AS mesin_kode, m.tipe AS mesin_tipe,
                    u.nama AS confirmed_by_nama
             FROM hl_mesin_sesi s
             JOIN hl_mesin m ON m.id = s.mesin_id AND m.tenant_id = s.tenant_id
             LEFT JOIN hl_users u ON u.id = s.confirmed_by AND u.tenant_id = s.tenant_id
             WHERE $whereStr
             ORDER BY s.id DESC LIMIT 300",
            $params
        );

        // Aggregates
        $totalRev = 0; $cntDone = 0; $cntBatal = 0;
        foreach ($rows as $r) {
            if ($r['status'] === 'done' && $r['status_bayar'] === 'lunas') { $totalRev += (int)$r['tarif']; $cntDone++; }
            if ($r['status'] === 'batal') $cntBatal++;
        }
        echo json_encode(['data' => $rows, 'summary' => [
            'total' => count($rows),
            'done'  => $cntDone,
            'batal' => $cntBatal,
            'revenue' => $totalRev,
        ]]);
        exit;
    }

    // ─── SAVE MESIN (CRUD master) ─────────────────────
    if ($action === 'save_mesin' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!$canManage) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);

        $nama = substr(trim(strip_tags($d['nama'] ?? '')), 0, 80);
        $kode = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $d['kode'] ?? ''));
        if (!$nama) { echo json_encode(['error'=>'Nama mesin wajib diisi']); exit; }
        if (!$kode) { echo json_encode(['error'=>'Kode mesin wajib diisi (huruf+angka, untuk QR)']); exit; }
        $kode = substr($kode, 0, 20);
        $tipe = in_array($d['tipe'] ?? '', ['cuci','kering']) ? $d['tipe'] : 'cuci';

        $data = [
            'nama'      => $nama,
            'kode'      => $kode,
            'tipe'      => $tipe,
            'kapasitas' => max(0, floatval($d['kapasitas'] ?? 0)),
            'catatan'   => substr(trim($d['catatan'] ?? ''), 0, 200) ?: null,
            'is_active' => intval($d['is_active'] ?? 1) ? 1 : 0,
        ];

        try {
            if (!empty($d['id'])) {
                TenantQuery::update('hl_mesin', $data, 'id=?', [intval($d['id'])]);
                $id = intval($d['id']);
                logAudit('update','mesin',"Edit mesin: $nama ($kode)");
            } else {
                $data['status'] = 'idle';
                $id = TenantQuery::insert('hl_mesin', $data);
                logAudit('create','mesin',"Tambah mesin: $nama ($kode)");
            }
            echo json_encode(['success'=>true, 'id'=>$id]);
        } catch (Throwable $e) {
            // Likely UNIQUE collision di (tenant, outlet, kode)
            if (str_contains($e->getMessage(), 'Duplicate')) {
                echo json_encode(['error'=>"Kode '$kode' sudah dipakai mesin lain di outlet ini"]);
            } else {
                echo json_encode(['error'=>'Gagal: '.$e->getMessage()]);
            }
        }
        exit;
    }

    // ─── DELETE MESIN (soft via is_active=0) ──────────
    if ($action === 'delete_mesin' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!$canManage) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $id = intval($d['id'] ?? 0);
        TenantQuery::update('hl_mesin', ['is_active'=>0, 'status'=>'maintenance'], 'id=?', [$id]);
        logAudit('delete','mesin',"Nonaktifkan mesin ID:$id");
        echo json_encode(['success'=>true]);
        exit;
    }

    // ─── SAVE CYCLE (CRUD opsi per mesin) ─────────────
    if ($action === 'save_cycle' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!$canManage) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $mesinId = intval($d['mesin_id'] ?? 0);
        $label   = substr(trim(strip_tags($d['label'] ?? '')), 0, 60);
        $durasi  = max(1, intval($d['durasi_menit'] ?? 0));
        $tarif   = max(0, intval($d['tarif'] ?? 0));
        if (!$mesinId || !$label || $durasi <= 0) {
            echo json_encode(['error'=>'Mesin, label, dan durasi wajib diisi']); exit;
        }
        // Verify mesin milik tenant ini
        $check = TenantQuery::rawOne("SELECT id FROM hl_mesin WHERE id=? AND tenant_id=? AND outlet_id=?", [$mesinId, $tid, $oid]);
        if (!$check) { echo json_encode(['error'=>'Mesin tidak valid']); exit; }

        $data = ['mesin_id'=>$mesinId, 'label'=>$label, 'durasi_menit'=>$durasi, 'tarif'=>$tarif,
                 'urutan'=>intval($d['urutan'] ?? 0), 'is_active'=>intval($d['is_active'] ?? 1)?1:0];
        if (!empty($d['id'])) {
            $db->prepare("UPDATE hl_mesin_cycle SET label=?, durasi_menit=?, tarif=?, urutan=?, is_active=? WHERE id=? AND mesin_id=?")
               ->execute([$data['label'], $data['durasi_menit'], $data['tarif'], $data['urutan'], $data['is_active'], (int)$d['id'], $mesinId]);
        } else {
            $db->prepare("INSERT INTO hl_mesin_cycle (mesin_id, label, durasi_menit, tarif, urutan, is_active) VALUES (?,?,?,?,?,?)")
               ->execute([$mesinId, $data['label'], $data['durasi_menit'], $data['tarif'], $data['urutan'], $data['is_active']]);
        }
        echo json_encode(['success'=>true]);
        exit;
    }

    // ─── DELETE CYCLE ──────────────────────────────────
    if ($action === 'delete_cycle' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!$canManage) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $db->prepare("DELETE c FROM hl_mesin_cycle c JOIN hl_mesin m ON m.id=c.mesin_id WHERE c.id=? AND m.tenant_id=? AND m.outlet_id=?")
           ->execute([(int)$d['id'], $tid, $oid]);
        echo json_encode(['success'=>true]);
        exit;
    }

    // ─── CONFIRM MULAI (booked → running) ─────────────
    if ($action === 'start_sesi' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!$canOperate) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $sesiId = intval($d['sesi_id'] ?? 0);
        if (!$sesiId) { echo json_encode(['error'=>'Sesi tidak valid']); exit; }

        $sesi = TenantQuery::rawOne(
            "SELECT s.*, m.nama AS mesin_nama FROM hl_mesin_sesi s JOIN hl_mesin m ON m.id=s.mesin_id WHERE s.id=? AND s.tenant_id=? AND s.outlet_id=?",
            [$sesiId, $tid, $oid]
        );
        if (!$sesi) { echo json_encode(['error'=>'Sesi tidak ditemukan']); exit; }
        if ($sesi['status'] !== 'booked') { echo json_encode(['error'=>'Sesi ini status: '.$sesi['status'].' (bukan booked)']); exit; }
        if ($sesi['status_bayar'] !== 'lunas') {
            // Auto-mark lunas kalau staff yang konfirmasi (mereka terima cash di counter)
            $db->prepare("UPDATE hl_mesin_sesi SET status_bayar='lunas', paid_at=NOW() WHERE id=?")->execute([$sesiId]);
        }

        $estDone = date('Y-m-d H:i:s', time() + intval($sesi['durasi_menit']) * 60);
        $db->prepare("UPDATE hl_mesin_sesi SET status='running', started_at=NOW(), estimated_done_at=?, confirmed_by=? WHERE id=?")
           ->execute([$estDone, $user['id'], $sesiId]);
        $db->prepare("UPDATE hl_mesin SET status='running' WHERE id=?")->execute([(int)$sesi['mesin_id']]);

        logAudit('update','mesin',"Mulai sesi: {$sesi['mesin_nama']} oleh {$sesi['pelanggan_nama']}");
        echo json_encode(['success'=>true, 'estimated_done_at'=>$estDone]);
        exit;
    }

    // ─── TANDAI SELESAI (running → done) ──────────────
    if ($action === 'done_sesi' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!$canOperate) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $sesiId = intval($d['sesi_id'] ?? 0);
        $sesi = TenantQuery::rawOne("SELECT mesin_id, status FROM hl_mesin_sesi WHERE id=? AND tenant_id=? AND outlet_id=?", [$sesiId, $tid, $oid]);
        if (!$sesi) { echo json_encode(['error'=>'Sesi tidak ditemukan']); exit; }
        if (!in_array($sesi['status'], ['running','booked'])) { echo json_encode(['error'=>'Sesi sudah '.$sesi['status']]); exit; }

        $db->prepare("UPDATE hl_mesin_sesi SET status='done', done_at=NOW() WHERE id=?")->execute([$sesiId]);
        $db->prepare("UPDATE hl_mesin SET status='idle' WHERE id=?")->execute([(int)$sesi['mesin_id']]);
        logAudit('update','mesin',"Sesi selesai: sesi ID $sesiId");
        $mesinRow = TenantQuery::rawOne("SELECT nama FROM hl_mesin WHERE id=? AND tenant_id=? AND outlet_id=?", [(int)$sesi['mesin_id'], $tid, $oid]);
        $namaMesin = $mesinRow['nama'] ?? ('Mesin #' . $sesi['mesin_id']);
        PushSender::send('mesin_selesai', (int)$tid, (int)$oid, [
            'title' => 'Mesin selesai',
            'body'  => $namaMesin . ' selesai',
            'url'   => '/mesin',
        ]);
        echo json_encode(['success'=>true]);
        exit;
    }

    // ─── BATALKAN SESI ─────────────────────────────────
    if ($action === 'cancel_sesi' && $_SERVER['REQUEST_METHOD']==='POST') {
        if (!$canOperate) { echo json_encode(['error'=>'Akses ditolak']); exit; }
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $sesiId = intval($d['sesi_id'] ?? 0);
        $sesi = TenantQuery::rawOne("SELECT mesin_id, status FROM hl_mesin_sesi WHERE id=? AND tenant_id=? AND outlet_id=?", [$sesiId, $tid, $oid]);
        if (!$sesi) { echo json_encode(['error'=>'Sesi tidak ditemukan']); exit; }
        $db->prepare("UPDATE hl_mesin_sesi SET status='batal' WHERE id=?")->execute([$sesiId]);
        // Cek apakah ini sesi aktif terakhir → balikin status mesin ke idle
        $stillActive = TenantQuery::rawOne(
            "SELECT id FROM hl_mesin_sesi WHERE mesin_id=? AND tenant_id=? AND outlet_id=? AND status IN ('booked','running')",
            [(int)$sesi['mesin_id'], $tid, $oid]
        );
        if (!$stillActive) {
            $db->prepare("UPDATE hl_mesin SET status='idle' WHERE id=?")->execute([(int)$sesi['mesin_id']]);
        }
        logAudit('update','mesin',"Batalkan sesi ID $sesiId");
        echo json_encode(['success'=>true]);
        exit;
    }

    echo json_encode(['error'=>'Unknown action']);
    exit;
}

// Base URL untuk QR (penting!) — pakai schema://host dari request
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'http';
$baseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'lamasy.harpy.id');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Mesin Self-Service'); ?>
<style>
/* Summary */
.ms-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:20px}
@media(max-width:680px){.ms-summary{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}}
.ms-card{background:var(--white);border-radius:14px;padding:14px 16px;border:1px solid rgba(27,45,90,.07);position:relative;overflow:hidden;text-align:center}
.ms-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px}
.ms-card.idle::before{background:linear-gradient(90deg,#9CA3AF,#D1D5DB)}
.ms-card.running::before{background:linear-gradient(90deg,#3B82F6,#60A5FA)}
.ms-card.booked::before{background:linear-gradient(90deg,#F59E0B,#FBBF24)}
.ms-card.rev::before{background:linear-gradient(90deg,#10B981,#34D399)}
.ms-num{font-size:clamp(0.8rem,3.4vw,1.5rem);white-space:nowrap;letter-spacing:-0.02em;font-weight:800;color:var(--navy);font-family:var(--mono);margin-bottom:4px}
.ms-num.blue{color:#3B82F6}.ms-num.amber{color:#D97706}.ms-num.green{color:#10B981}
.ms-label{font-size:11px;color:var(--gray);font-weight:600;text-transform:uppercase;letter-spacing:.3px}

/* Tabs */
.ms-tabs{display:flex;gap:4px;margin-bottom:18px;border-bottom:2px solid rgba(27,45,90,.08)}
.ms-tab{padding:10px 18px;cursor:pointer;font-weight:600;font-size:14px;color:var(--gray);background:none;border:none;border-bottom:3px solid transparent;margin-bottom:-2px}
.ms-tab.active{color:var(--teal-d);border-bottom-color:var(--teal)}

/* Mesin grid */
.mesin-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
.mesin-tile{background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:16px;position:relative;transition:all .2s}
.mesin-tile.status-idle    {border-color:#D1D5DB;background:#F9FAFB}
.mesin-tile.status-running {border-color:#3B82F6;background:#EFF6FF}
.mesin-tile.status-booked  {border-color:#F59E0B;background:#FFFBEB}
.mesin-tile.status-maintenance{border-color:#9CA3AF;background:#F3F4F6;opacity:.6}
.mesin-tile.attention {border-color:#EF4444;background:#FEF2F2;animation:pulse 2s infinite}
@keyframes pulse {0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.4)}50%{box-shadow:0 0 0 6px rgba(239,68,68,0)}}
.mt-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px}
.mt-nama{font-size:15px;font-weight:800;color:var(--navy);margin:0}
.mt-kode{font-family:var(--mono);font-size:11px;color:var(--gray);background:#fff;padding:2px 6px;border-radius:4px;border:1px solid #E5E7EB}
.mt-tipe{display:inline-block;font-size:11px;font-weight:700;padding:2px 8px;border-radius:10px;text-transform:uppercase}
.mt-tipe.cuci{background:#DBEAFE;color:#1E40AF}
.mt-tipe.kering{background:#FEF3C7;color:#92400E}
.mt-status{font-size:11px;font-weight:700;text-transform:uppercase;padding:3px 9px;border-radius:10px;letter-spacing:.3px}
.mt-status.idle    {background:#E5E7EB;color:#4B5563}
.mt-status.running {background:#DBEAFE;color:#1E40AF}
.mt-status.booked  {background:#FEF3C7;color:#92400E}
.mt-status.maintenance{background:#F3F4F6;color:#6B7280}
.mt-body{font-size:13px;color:var(--navy)}
.mt-body strong{font-family:var(--mono)}
.mt-countdown{font-family:var(--mono);font-weight:800;font-size:18px;color:#1E40AF}
.mt-countdown.over{color:#EF4444}
.mt-actions{margin-top:12px;display:flex;gap:6px;flex-wrap:wrap}
.mt-empty{font-size:13px;color:var(--gray);font-style:italic}

/* Cycle chips */
.cycle-chip{display:inline-block;font-size:11px;padding:3px 8px;border-radius:10px;background:#F3F4F6;color:var(--navy);margin:2px 4px 2px 0;font-family:var(--mono)}

/* QR display */
.qr-mini{width:80px;height:80px}
</style>
</head>
<body>
<?php renderTopbar('mesin'); ?>
<div class="hl-main">

  <div style="margin-bottom:16px">
    <h1 style="font-size:22px;font-weight:800;color:var(--navy);margin:0 0 4px">🪙 Mesin Self-Service</h1>
    <p style="color:var(--gray);font-size:13px;margin:0">Laundry koin / self-service. Pelanggan scan QR di mesin → pesan → staf konfirmasi → mesin dinyalakan manual.</p>
  </div>

  <div class="ms-summary">
    <div class="ms-card idle"><div class="ms-num" id="sumIdle">-</div><div class="ms-label">⚪ Mesin Siap</div></div>
    <div class="ms-card running"><div class="ms-num blue" id="sumRunning">-</div><div class="ms-label">🔵 Berjalan</div></div>
    <div class="ms-card booked"><div class="ms-num amber" id="sumBooked">-</div><div class="ms-label">🟡 Perlu Konfirmasi</div></div>
    <div class="ms-card rev"><div class="ms-num green" id="sumRevToday">Rp 0</div><div class="ms-label">💰 Pendapatan Hari Ini</div></div>
  </div>

  <div class="ms-tabs">
    <button class="ms-tab active" onclick="switchMsTab('live',this)">🔴 LIVE</button>
    <button class="ms-tab" onclick="switchMsTab('master',this)">⚙️ Master Mesin & Cycle</button>
    <button class="ms-tab" onclick="switchMsTab('riwayat',this)">📜 Riwayat Sesi</button>
  </div>

  <!-- ═════ TAB: LIVE ═════ -->
  <div id="ms-tab-live" class="ms-tab-content">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <p style="margin:0;color:var(--gray);font-size:13px">Auto-refresh tiap 10 detik. Klik "Konfirmasi Mulai" setelah pelanggan bayar di kasir.</p>
      <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadLive()">🔄 Refresh</button>
    </div>
    <div class="mesin-grid" id="liveGrid">
      <div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--gray)">⏳ Memuat...</div>
    </div>
  </div>

  <!-- ═════ TAB: MASTER ═════ -->
  <div id="ms-tab-master" class="ms-tab-content" style="display:none">
    <div style="display:flex;justify-content:flex-end;align-items:center;margin-bottom:14px">
      <?php if ($canManage): ?>
      <button class="hl-btn hl-btn-primary" onclick="openMesinModal()">➕ Tambah Mesin</button>
      <?php endif; ?>
    </div>
    <div id="masterList">
      <div style="text-align:center;padding:40px;color:var(--gray)">⏳ Memuat...</div>
    </div>
  </div>

  <!-- ═════ TAB: RIWAYAT ═════ -->
  <div id="ms-tab-riwayat" class="ms-tab-content" style="display:none">
    <div class="hl-filter-bar" style="margin-bottom:14px">
      <label style="font-size:12px;font-weight:700;color:var(--navy)">Dari</label>
      <input type="date" id="rDari" class="hl-input" style="width:auto" onchange="loadSesi()"/>
      <label style="font-size:12px;font-weight:700;color:var(--navy)">s/d</label>
      <input type="date" id="rSampai" class="hl-input" style="width:auto" onchange="loadSesi()"/>
      <select id="rStatus" class="hl-input" style="width:auto" onchange="loadSesi()">
        <option value="">Semua Status</option>
        <option value="booked">Booked</option>
        <option value="running">Running</option>
        <option value="done">Done</option>
        <option value="batal">Batal</option>
      </select>
      <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadSesi()" style="margin-left:auto">🔄</button>
    </div>
    <div class="hl-card">
      <div class="hl-table-wrap">
        <table class="hl-table">
          <thead>
            <tr>
              <th>Waktu Book</th>
              <th>Mesin</th>
              <th>Customer</th>
              <th>Cycle</th>
              <th class="td-num">Tarif</th>
              <th>Bayar</th>
              <th>Status</th>
              <th>Confirmed by</th>
            </tr>
          </thead>
          <tbody id="sesiBody"><tr><td colspan="8" style="text-align:center;padding:40px;color:var(--gray)">⏳ Memuat...</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<!-- ═════ MODAL: MESIN ═════ -->
<div class="hl-modal-overlay" id="modalMesin">
  <div class="hl-modal hl-modal-sm">
    <div class="hl-modal-header">
      <span class="hl-modal-title" id="mesinModalTitle">➕ Tambah Mesin</span>
      <button class="hl-modal-close" onclick="closeModal('modalMesin')">×</button>
    </div>
    <div class="hl-modal-body">
      <input type="hidden" id="mm_id" value=""/>
      <div class="hl-form-row">
        <div class="hl-form-group" style="flex:2">
          <label class="hl-label">Nama <span class="req">*</span></label>
          <input type="text" id="mm_nama" class="hl-input" placeholder="Misal: Mesin Cuci 1" maxlength="80"/>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Kode (QR) <span class="req">*</span></label>
          <input type="text" id="mm_kode" class="hl-input" placeholder="WC1" maxlength="20" style="font-family:var(--mono);text-transform:uppercase"/>
        </div>
      </div>
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Tipe</label>
          <select id="mm_tipe" class="hl-input">
            <option value="cuci">🧺 Cuci</option>
            <option value="kering">🌬️ Kering</option>
          </select>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Kapasitas (kg)</label>
          <input type="number" id="mm_kap" class="hl-input" min="0" step="0.5" value="0"/>
        </div>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Catatan</label>
        <input type="text" id="mm_catatan" class="hl-input" maxlength="200" placeholder="Opsional"/>
      </div>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeModal('modalMesin')">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="saveMesin()">💾 Simpan</button>
    </div>
  </div>
</div>

<!-- ═════ MODAL: CYCLE ═════ -->
<div class="hl-modal-overlay" id="modalCycle">
  <div class="hl-modal hl-modal-sm">
    <div class="hl-modal-header">
      <span class="hl-modal-title">⏱️ Tambah/Edit Cycle</span>
      <button class="hl-modal-close" onclick="closeModal('modalCycle')">×</button>
    </div>
    <div class="hl-modal-body">
      <input type="hidden" id="cy_id" value=""/>
      <input type="hidden" id="cy_mesin_id" value=""/>
      <div class="hl-form-group">
        <label class="hl-label">Label <span class="req">*</span></label>
        <input type="text" id="cy_label" class="hl-input" placeholder="Misal: Cycle Standar 30 min" maxlength="60"/>
      </div>
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Durasi (menit) <span class="req">*</span></label>
          <input type="number" id="cy_durasi" class="hl-input" min="1" placeholder="30"/>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Tarif (Rp) <span class="req">*</span></label>
          <input type="number" id="cy_tarif" class="hl-input" min="0" step="500" placeholder="5000"/>
        </div>
      </div>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeModal('modalCycle')">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="saveCycle()">💾 Simpan</button>
    </div>
  </div>
</div>

<script>
const BASE_URL    = <?= json_encode($baseUrl) ?>;
const CAN_OPERATE = <?= $canOperate ? 'true' : 'false' ?>;
const CAN_MANAGE  = <?= $canManage ? 'true' : 'false' ?>;
let liveTimer = null;
let currentTab = 'live';

function switchMsTab(tab, el) {
  currentTab = tab;
  document.querySelectorAll('.ms-tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  document.querySelectorAll('.ms-tab-content').forEach(t => t.style.display = 'none');
  document.getElementById('ms-tab-' + tab).style.display = 'block';
  if (tab === 'live')    { loadLive(); startLiveTimer(); }
  else                   { stopLiveTimer(); }
  if (tab === 'master')  loadMaster();
  if (tab === 'riwayat') loadSesi();
}

function startLiveTimer() { stopLiveTimer(); liveTimer = setInterval(loadLive, 10000); }
function stopLiveTimer()  { if (liveTimer) { clearInterval(liveTimer); liveTimer = null; } }

// ── LOAD LIVE ───────────────────────────────────────
async function loadLive() {
  const r = await fetch('/mesin.php?action=list_live');
  const j = await r.json();
  const grid = document.getElementById('liveGrid');

  // Summary aggregates
  let idle = 0, running = 0, booked = 0;
  let revToday = 0;
  j.data.forEach(m => {
    if (m.status === 'idle' || m.status === 'maintenance') idle++;
    if (m.sesi_status === 'running') running++;
    if (m.sesi_status === 'booked')  booked++;
  });
  // Revenue today via separate call (simpler: compute in load_sesi summary)
  document.getElementById('sumIdle').textContent    = idle;
  document.getElementById('sumRunning').textContent = running;
  document.getElementById('sumBooked').textContent  = booked;
  // Revenue placeholder — load via sesi summary
  loadRevToday();

  if (!j.data.length) {
    grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--gray)">Belum ada mesin terdaftar. Buka tab <strong>Master</strong> untuk tambah.</div>';
    return;
  }

  grid.innerHTML = j.data.map(m => renderMesinTile(m, j.server_time)).join('');
}

async function loadRevToday() {
  const today = new Date().toISOString().split('T')[0];
  const r = await fetch(`/mesin.php?action=list_sesi&dari=${today}&sampai=${today}&status=done`);
  const j = await r.json();
  document.getElementById('sumRevToday').textContent = 'Rp ' + fmtNum(j.summary.revenue || 0);
}

function renderMesinTile(m, serverTime) {
  const attentionCls = m.need_attention ? 'attention' : '';
  let body = '';
  let actions = '';

  if (m.sesi_status === 'running') {
    const est = m.estimated_done_at ? new Date(m.estimated_done_at.replace(' ','T')) : null;
    body = `
      <div style="margin-bottom:6px"><strong>${esc(m.pelanggan_nama)}</strong> · <small style="color:var(--gray)">${esc(m.cycle_label||m.sesi_durasi+'min')}</small></div>
      ${est ? `<div class="mt-countdown" data-est="${est.toISOString()}">--:--</div>` : ''}
      <div style="font-size:11px;color:var(--gray);margin-top:4px">Mulai: ${m.started_at ? fmtTime(m.started_at) : '-'}</div>
      ${m.need_attention ? '<div style="margin-top:8px;color:#EF4444;font-weight:700;font-size:12px">⚠️ Estimasi sudah lewat!</div>' : ''}
    `;
    if (CAN_OPERATE) actions = `<button class="hl-btn hl-btn-sm hl-btn-green" onclick="doneSesi(${m.sesi_id})">✅ Tandai Selesai</button>
                                <button class="hl-btn hl-btn-sm hl-btn-outline" onclick="cancelSesi(${m.sesi_id})">Batal</button>`;
  } else if (m.sesi_status === 'booked') {
    body = `
      <div style="margin-bottom:6px"><strong>${esc(m.pelanggan_nama)}</strong> ${m.pelanggan_telepon?'· '+esc(m.pelanggan_telepon):''}</div>
      <div>Cycle: <strong>${esc(m.cycle_label||'')}</strong> · ${m.sesi_durasi} min · Rp ${fmtNum(m.sesi_tarif)}</div>
      <div style="font-size:11px;color:var(--gray);margin-top:4px">Booked: ${fmtTime(m.booked_at)} · ${m.status_bayar==='lunas' ? '✅ Lunas' : '⏳ Belum bayar'}</div>
    `;
    if (CAN_OPERATE) actions = `<button class="hl-btn hl-btn-sm hl-btn-primary" onclick="startSesi(${m.sesi_id})">▶️ Konfirmasi Mulai</button>
                                <button class="hl-btn hl-btn-sm hl-btn-outline" onclick="cancelSesi(${m.sesi_id})">Batal</button>`;
  } else {
    body = `<div class="mt-empty">Idle — tunggu customer book lewat QR</div>`;
    actions = `<a href="/self?m=${esc(m.kode)}" target="_blank" class="hl-btn hl-btn-sm hl-btn-outline">🔗 Test page customer</a>`;
  }

  return `
    <div class="mesin-tile status-${m.status||'idle'} ${attentionCls}">
      <div class="mt-head">
        <div>
          <div class="mt-nama">${esc(m.nama)} <span class="mt-tipe ${m.tipe}">${m.tipe}</span></div>
          <div style="margin-top:4px"><span class="mt-kode">${esc(m.kode)}</span> ${m.kapasitas>0?'· '+m.kapasitas+'kg':''}</div>
        </div>
        <span class="mt-status ${m.sesi_status||m.status}">${m.sesi_status||m.status}</span>
      </div>
      <div class="mt-body">${body}</div>
      <div class="mt-actions">${actions}</div>
    </div>`;
}

// Countdown updater
setInterval(() => {
  document.querySelectorAll('.mt-countdown[data-est]').forEach(el => {
    const est = new Date(el.dataset.est).getTime();
    const now = Date.now();
    const diff = Math.floor((est - now) / 1000);
    if (diff <= 0) {
      el.textContent = '00:00';
      el.classList.add('over');
    } else {
      const m = Math.floor(diff / 60);
      const s = diff % 60;
      el.textContent = String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
    }
  });
}, 1000);

// ── ACTIONS ─────────────────────────────────────────
async function startSesi(id) {
  if (!await lmConfirm('Konfirmasi customer sudah bayar dan mesin sudah dinyalakan?')) return;
  const r = await fetch('/mesin.php?action=start_sesi', {
    method:'POST', headers:csrfHeaders(),
    body: JSON.stringify({ sesi_id: id })
  });
  const j = await r.json();
  if (j.error) { showToast(j.error, 'error'); return; }
  showToast('Sesi dimulai. Countdown jalan.', 'success');
  loadLive();
}
async function doneSesi(id) {
  if (!await lmConfirm('Tandai sesi selesai? Mesin akan kembali idle.')) return;
  const r = await fetch('/mesin.php?action=done_sesi', {
    method:'POST', headers:csrfHeaders(),
    body: JSON.stringify({ sesi_id: id })
  });
  const j = await r.json();
  if (j.error) { showToast(j.error, 'error'); return; }
  showToast('Sesi selesai.', 'success');
  loadLive();
}
async function cancelSesi(id) {
  if (!await lmConfirm('Batalkan sesi ini?')) return;
  const r = await fetch('/mesin.php?action=cancel_sesi', {
    method:'POST', headers:csrfHeaders(),
    body: JSON.stringify({ sesi_id: id })
  });
  const j = await r.json();
  if (j.error) { showToast(j.error, 'error'); return; }
  showToast('Sesi dibatalkan.', 'info');
  loadLive();
}

// ── MASTER MESIN ────────────────────────────────────
async function loadMaster() {
  const r = await fetch('/mesin.php?action=list_master');
  const j = await r.json();
  const wrap = document.getElementById('masterList');
  if (!j.data.length) {
    wrap.innerHTML = '<div style="text-align:center;padding:40px;color:var(--gray)">Belum ada mesin terdaftar.</div>';
    return;
  }
  wrap.innerHTML = j.data.map(m => {
    const cycles = (m.cycles || []).map(c =>
      `<span class="cycle-chip">${esc(c.label)} · ${c.durasi_menit}min · Rp ${fmtNum(c.tarif)}
        ${CAN_MANAGE ? `<a href="#" onclick="editCycle(${c.id},${m.id},'${escAttr(c.label)}',${c.durasi_menit},${c.tarif});return false" style="margin-left:6px;text-decoration:none">✏️</a>
                       <a href="#" onclick="deleteCycle(${c.id});return false" style="margin-left:2px;text-decoration:none">🗑️</a>` : ''}
      </span>`).join('') || '<em style="color:var(--gray)">Belum ada cycle. </em>';
    const qrUrl = BASE_URL + '/self?m=' + m.kode;
    const qrImg = `https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=${encodeURIComponent(qrUrl)}`;
    return `
      <div class="hl-card" style="margin-bottom:14px">
        <div style="padding:16px;display:flex;gap:16px;align-items:flex-start">
          <img src="${qrImg}" class="qr-mini" alt="QR ${esc(m.kode)}" title="${qrUrl}"/>
          <div style="flex:1;min-width:0">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;flex-wrap:wrap;gap:8px">
              <div>
                <div style="font-size:16px;font-weight:800;color:var(--navy)">${esc(m.nama)} <span class="mt-tipe ${m.tipe}">${m.tipe}</span></div>
                <div style="font-size:12px;color:var(--gray);margin-top:2px">Kode: <code>${esc(m.kode)}</code> · Kapasitas: ${m.kapasitas}kg · ${m.is_active==1?'Aktif':'Nonaktif'}</div>
              </div>
              ${CAN_MANAGE ? `<div style="display:flex;gap:6px">
                <button class="hl-btn hl-btn-sm hl-btn-outline" onclick="editMesin(${m.id})">✏️ Edit</button>
                <button class="hl-btn hl-btn-sm hl-btn-outline" onclick="addCycle(${m.id})">+ Cycle</button>
                <button class="hl-btn hl-btn-sm hl-btn-danger" onclick="deleteMesin(${m.id},'${escAttr(m.nama)}')">🗑️</button>
              </div>` : ''}
            </div>
            <div>${cycles}</div>
          </div>
        </div>
      </div>`;
  }).join('');
}

function openMesinModal() {
  document.getElementById('mesinModalTitle').textContent = '➕ Tambah Mesin';
  ['mm_id','mm_nama','mm_kode','mm_catatan'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('mm_tipe').value = 'cuci';
  document.getElementById('mm_kap').value = '0';
  document.getElementById('modalMesin').classList.add('open');
}
function editMesin(id) {
  fetch('/mesin.php?action=list_master').then(r=>r.json()).then(j => {
    const m = j.data.find(x => x.id === id);
    if (!m) return;
    document.getElementById('mesinModalTitle').textContent = '✏️ Edit Mesin';
    document.getElementById('mm_id').value = m.id;
    document.getElementById('mm_nama').value = m.nama;
    document.getElementById('mm_kode').value = m.kode;
    document.getElementById('mm_tipe').value = m.tipe;
    document.getElementById('mm_kap').value = m.kapasitas;
    document.getElementById('mm_catatan').value = m.catatan || '';
    document.getElementById('modalMesin').classList.add('open');
  });
}
async function saveMesin() {
  const data = {
    id: document.getElementById('mm_id').value,
    nama: document.getElementById('mm_nama').value,
    kode: document.getElementById('mm_kode').value,
    tipe: document.getElementById('mm_tipe').value,
    kapasitas: document.getElementById('mm_kap').value,
    catatan: document.getElementById('mm_catatan').value,
  };
  if (!data.nama.trim() || !data.kode.trim()) { showToast('Nama dan kode wajib diisi', 'error'); return; }
  const r = await fetch('/mesin.php?action=save_mesin', { method:'POST', headers:csrfHeaders(), body: JSON.stringify(data) });
  const j = await r.json();
  if (j.error) { showToast(j.error, 'error'); return; }
  showToast('Mesin tersimpan', 'success');
  closeModal('modalMesin');
  loadMaster();
}
async function deleteMesin(id, nama) {
  if (!await lmConfirm(`Nonaktifkan mesin "${nama}"? Data riwayat tidak dihapus.`)) return;
  const r = await fetch('/mesin.php?action=delete_mesin', { method:'POST', headers:csrfHeaders(), body: JSON.stringify({ id }) });
  const j = await r.json();
  if (j.error) { showToast(j.error, 'error'); return; }
  showToast('Mesin dinonaktifkan', 'info');
  loadMaster();
}

// Cycle
function addCycle(mesinId) {
  document.getElementById('cy_id').value = '';
  document.getElementById('cy_mesin_id').value = mesinId;
  document.getElementById('cy_label').value = '';
  document.getElementById('cy_durasi').value = '30';
  document.getElementById('cy_tarif').value = '5000';
  document.getElementById('modalCycle').classList.add('open');
}
function editCycle(id, mesinId, label, durasi, tarif) {
  document.getElementById('cy_id').value = id;
  document.getElementById('cy_mesin_id').value = mesinId;
  document.getElementById('cy_label').value = label;
  document.getElementById('cy_durasi').value = durasi;
  document.getElementById('cy_tarif').value = tarif;
  document.getElementById('modalCycle').classList.add('open');
}
async function saveCycle() {
  const data = {
    id: document.getElementById('cy_id').value,
    mesin_id: parseInt(document.getElementById('cy_mesin_id').value),
    label: document.getElementById('cy_label').value,
    durasi_menit: parseInt(document.getElementById('cy_durasi').value),
    tarif: parseInt(document.getElementById('cy_tarif').value),
  };
  if (!data.label.trim() || data.durasi_menit <= 0) { showToast('Label & durasi wajib diisi', 'error'); return; }
  const r = await fetch('/mesin.php?action=save_cycle', { method:'POST', headers:csrfHeaders(), body: JSON.stringify(data) });
  const j = await r.json();
  if (j.error) { showToast(j.error, 'error'); return; }
  showToast('Cycle tersimpan', 'success');
  closeModal('modalCycle');
  loadMaster();
}
async function deleteCycle(id) {
  if (!await lmConfirm('Hapus cycle ini?')) return;
  const r = await fetch('/mesin.php?action=delete_cycle', { method:'POST', headers:csrfHeaders(), body: JSON.stringify({ id }) });
  const j = await r.json();
  if (j.error) { showToast(j.error, 'error'); return; }
  showToast('Cycle dihapus', 'info');
  loadMaster();
}

// ── RIWAYAT SESI ────────────────────────────────────
async function loadSesi() {
  const dari = document.getElementById('rDari').value;
  const sampai = document.getElementById('rSampai').value;
  const status = document.getElementById('rStatus').value;
  const r = await fetch(`/mesin.php?action=list_sesi&dari=${dari}&sampai=${sampai}&status=${status}`);
  const j = await r.json();
  const tbody = document.getElementById('sesiBody');
  if (!j.data.length) {
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:40px;color:var(--gray)">Tidak ada sesi di periode ini.</td></tr>';
    return;
  }
  tbody.innerHTML = j.data.map(s => {
    const statusBadge = {
      'booked': '<span class="hl-badge" style="background:#FEF3C7;color:#92400E">Booked</span>',
      'running': '<span class="hl-badge" style="background:#DBEAFE;color:#1E40AF">Running</span>',
      'done':   '<span class="hl-badge hl-badge-green">Done</span>',
      'batal':  '<span class="hl-badge hl-badge-red">Batal</span>',
    }[s.status] || s.status;
    return `<tr>
      <td>${fmtDate(s.booked_at)}</td>
      <td><strong>${esc(s.mesin_nama)}</strong> <small style="color:var(--gray)">${esc(s.mesin_kode)}</small></td>
      <td>${esc(s.pelanggan_nama)}${s.pelanggan_telepon?'<br><small style="color:var(--gray)">'+esc(s.pelanggan_telepon)+'</small>':''}</td>
      <td>${esc(s.cycle_label||'')} <small style="color:var(--gray)">(${s.durasi_menit}min)</small></td>
      <td class="td-num">Rp ${fmtNum(s.tarif)}</td>
      <td>${s.status_bayar==='lunas' ? '✅' : '⏳'} ${s.metode_bayar}</td>
      <td>${statusBadge}</td>
      <td>${esc(s.confirmed_by_nama || '-')}</td>
    </tr>`;
  }).join('');
}

// ── HELPERS ─────────────────────────────────────────
function csrfHeaders() {
  return { 'Content-Type':'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' };
}
// esc/fmtNum/fmtDate/fmtTime sudah global di components.php
function escAttr(s) { return esc(s).replace(/'/g,"\\'"); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// ── INIT ───────────────────────────────────────────
(function init() {
  const today = new Date().toISOString().split('T')[0];
  document.getElementById('rDari').value = today.substring(0,8) + '01';
  document.getElementById('rSampai').value = today;
  loadLive();
  startLiveTimer();
})();
</script>

<?php renderToast(); ?>
</body>
</html>

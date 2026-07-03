<?php
// ══════════════════════════════════════════════════════
// outlet-settings.php — Outlet & Nota Settings
//
// Edit nota_prefix & nota_format per outlet via UI (no SQL).
// Live preview format → "HARPY-260607-001"
// ══════════════════════════════════════════════════════
$activePage = 'outlet-settings';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/NotaFormatter.php';
require_once __DIR__ . '/components.php';
$user = currentUser();
requirePermission('settings.roles');

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    $tid = TenantResolver::id();
    $db  = Database::get();

    if ($action === 'list') {
        $hasNotaCols = true;
        try { $db->query("SELECT nota_prefix FROM outlets LIMIT 1"); }
        catch (Throwable) { $hasNotaCols = false; }

        $cols = "id, tenant_id, nama_outlet, slug, kota, telepon, status, is_main";
        if ($hasNotaCols) $cols .= ", nota_prefix, nota_format, label_size, antar_mode, absensi_selfie_wajib, absensi_geofence_aktif, absensi_lat, absensi_lng, absensi_radius_m";
        $st = $db->prepare("SELECT $cols FROM outlets WHERE tenant_id=? ORDER BY is_main DESC, id ASC");
        $st->execute([$tid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        // Tambah preview format utk masing-masing
        foreach ($rows as &$r) {
            $prefix = $r['nota_prefix'] ?? 'HL-';
            $format = $r['nota_format'] ?? '{PREFIX}{YYMMDD}-{COUNTER:3}';
            $r['preview'] = NotaFormatter::previewFormat($prefix, $format,
                strtoupper(substr(preg_replace('/[^A-Za-z]/','',$r['nama_outlet']),0,3))
            );
        }
        echo json_encode(['rows' => $rows, 'has_cols' => $hasNotaCols]);
        exit;
    }

    if ($action === 'save' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d  = json_decode(file_get_contents('php://input'), true);
        $id = (int)($d['id'] ?? 0);
        $prefix = substr(trim((string)($d['nota_prefix'] ?? '')), 0, 20);
        $format = substr(trim((string)($d['nota_format'] ?? '')), 0, 60) ?: '{PREFIX}{YYMMDD}-{COUNTER:3}';
        $labelSize = in_array(($d['label_size'] ?? '80'), ['58','80'], true) ? $d['label_size'] : '80';
        $antarMode = in_array(($d['antar_mode'] ?? 'free'), ['free','zona'], true) ? $d['antar_mode'] : 'free';

        // Validasi: format harus punya minimal {COUNTER} (kalau gak ada,
        // nota_no duplicate setiap hari)
        if (!str_contains($format, '{COUNTER')) {
            echo json_encode(['error'=>'Format wajib pakai {COUNTER} atau {COUNTER:N} supaya nomor unik per hari']);
            exit;
        }

        try {
            $st = $db->prepare("UPDATE outlets SET nota_prefix=?, nota_format=?, label_size=?, antar_mode=? WHERE id=? AND tenant_id=?");
            $st->execute([$prefix, $format, $labelSize, $antarMode, $id, $tid]);
            logAudit('update', 'outlet', "Update outlet #$id: prefix=$prefix, format=$format, label=$labelSize");
            echo json_encode(['success'=>true]);
        } catch (Throwable $e) {
            echo json_encode(['error'=>'Gagal: '.$e->getMessage()]);
        }
        exit;
    }

    if ($action === 'preview' && $_SERVER['REQUEST_METHOD']==='POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        $prefix = (string)($d['nota_prefix'] ?? 'HL-');
        $format = (string)($d['nota_format'] ?? '{PREFIX}{YYMMDD}-{COUNTER:3}');
        $outletKode = (string)($d['outlet_kode'] ?? 'OUT');
        echo json_encode(['preview' => NotaFormatter::previewFormat($prefix, $format, $outletKode)]);
        exit;
    }

    // ── Parfum CRUD (per outlet atau global) ──
    if ($action === 'parfum_list') {
        try {
            $st = $db->prepare(
                "SELECT p.*, o.nama_outlet
                   FROM hl_parfum p
              LEFT JOIN outlets o ON o.id = p.outlet_id
                  WHERE p.tenant_id = ?
                  ORDER BY p.outlet_id IS NULL DESC, p.urutan ASC, p.nama ASC"
            );
            $st->execute([$tid]);
            echo json_encode(['rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) {
            echo json_encode(['error'=>'Tabel hl_parfum belum ada. Run migration parfum.', 'rows'=>[]]);
        }
        exit;
    }

    if ($action === 'parfum_save' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d    = json_decode(file_get_contents('php://input'), true);
        $nama = substr(trim((string)($d['nama'] ?? '')), 0, 50);
        $oid_p = !empty($d['outlet_id']) ? (int)$d['outlet_id'] : null;
        $aktif = (int)($d['is_active'] ?? 1) ? 1 : 0;
        $urut  = (int)($d['urutan'] ?? 0);
        if ($nama === '') { echo json_encode(['error'=>'Nama parfum wajib']); exit; }
        // Verifikasi outlet
        if ($oid_p !== null) {
            $own = TenantQuery::rawOne("SELECT id FROM outlets WHERE id=? AND tenant_id=?", [$oid_p, $tid]);
            if (!$own) { echo json_encode(['error'=>'Outlet tidak valid']); exit; }
        }
        try {
            if (!empty($d['id'])) {
                $st = $db->prepare("UPDATE hl_parfum SET nama=?, outlet_id=?, is_active=?, urutan=? WHERE id=? AND tenant_id=?");
                $st->execute([$nama, $oid_p, $aktif, $urut, (int)$d['id'], $tid]);
            } else {
                $st = $db->prepare("INSERT INTO hl_parfum (tenant_id, outlet_id, nama, is_active, urutan) VALUES (?,?,?,?,?)");
                $st->execute([$tid, $oid_p, $nama, $aktif, $urut]);
            }
            echo json_encode(['success'=>true]);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            echo json_encode(['error' => str_contains($msg, 'uniq_tenant_parfum') || str_contains($msg, 'Duplicate')
                ? "Parfum \"$nama\" sudah ada" : 'Gagal: '.$msg]);
        }
        exit;
    }

    if ($action === 'parfum_delete' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $db->prepare("DELETE FROM hl_parfum WHERE id=? AND tenant_id=?")->execute([(int)($d['id']??0), $tid]);
        echo json_encode(['success'=>true]);
        exit;
    }

    if ($action === 'zona_list') {
        $outletId = (int)($_GET['outlet_id'] ?? 0);
        $rows = TenantQuery::raw(
            "SELECT id, nama, fee, aktif FROM hl_zona_antar WHERE tenant_id=? AND outlet_id=? AND aktif=1 ORDER BY nama",
            [$tid, $outletId]
        );
        echo json_encode(['rows'=>$rows]);
        exit;
    }

    if ($action === 'zona_save' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $id   = (int)($d['id'] ?? 0);
        $outletId = (int)($d['outlet_id'] ?? 0);
        $nama = substr(trim($d['nama'] ?? ''), 0, 60);
        $fee  = (int)($d['fee'] ?? 0);
        if (!$nama || $outletId <= 0) { echo json_encode(['error'=>'Nama + outlet wajib']); exit; }

        if ($id > 0) {
            $st = $db->prepare("UPDATE hl_zona_antar SET nama=?, fee=? WHERE id=? AND tenant_id=? AND outlet_id=?");
            $st->execute([$nama, $fee, $id, $tid, $outletId]);
        } else {
            TenantQuery::insert('hl_zona_antar', ['nama'=>$nama, 'fee'=>$fee, 'outlet_id'=>$outletId]);
        }
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'zona_delete' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($d['id'] ?? 0);
        $outletId = (int)($d['outlet_id'] ?? 0);
        if ($outletId <= 0) { echo json_encode(['error'=>'outlet_id wajib']); exit; }
        $st = $db->prepare("UPDATE hl_zona_antar SET aktif=0 WHERE id=? AND tenant_id=? AND outlet_id=?");
        $st->execute([$id, $tid, $outletId]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'save_absensi' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d  = json_decode(file_get_contents('php://input'), true);
        $id = (int)($d['id'] ?? 0);
        $selfie   = !empty($d['selfie_wajib']) ? 1 : 0;
        $geofence = !empty($d['geofence_aktif']) ? 1 : 0;
        $lat = isset($d['lat']) && $d['lat'] !== '' ? (float)$d['lat'] : null;
        $lng = isset($d['lng']) && $d['lng'] !== '' ? (float)$d['lng'] : null;
        $radius = max(20, min(5000, (int)($d['radius_m'] ?? 100)));
        if ($geofence && ($lat === null || $lng === null)) {
            echo json_encode(['error'=>'Set titik lokasi outlet di peta dulu']); exit;
        }
        try {
            $st = $db->prepare("UPDATE outlets SET absensi_selfie_wajib=?, absensi_geofence_aktif=?, absensi_lat=?, absensi_lng=?, absensi_radius_m=? WHERE id=? AND tenant_id=?");
            $st->execute([$selfie, $geofence, $lat, $lng, $radius, $id, $tid]);
            logAudit('update', 'outlet', "Absensi config #$id: selfie=$selfie geofence=$geofence r=$radius");
            echo json_encode(['success'=>true]);
        } catch (Throwable $e) { echo json_encode(['error'=>'Gagal: '.$e->getMessage()]); }
        exit;
    }

    exit;
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Outlet & Nota Settings'); ?>
<link rel="stylesheet" href="/assets/vendor/leaflet.css">
<script src="/assets/vendor/leaflet.js"></script>
<script src="/assets/vendor/html2canvas.min.js?v=<?= @filemtime(__DIR__.'/assets/vendor/html2canvas.min.js') ?: '1' ?>"></script>
<script src="/assets/js/thermal-print.js?v=<?= @filemtime(__DIR__.'/assets/js/thermal-print.js') ?: '1' ?>"></script>
</head>
<body>
<?php renderTopbar('outlet-settings'); ?>

<div class="hl-main">
  <div class="settings-tabs" style="display:flex;gap:2px;margin-bottom:18px;border-bottom:1px solid var(--off)">
    <a href="/outlet-settings" class="settings-tab active" style="padding:11px 18px;border-bottom:3px solid var(--teal);color:var(--navy-d);font-weight:700;font-size:14px;text-decoration:none">🏢 Outlet<span class="st-x"> & Nota</span></a>
    <a href="/struk" class="settings-tab" style="padding:11px 18px;border-bottom:3px solid transparent;color:var(--gray);font-weight:600;font-size:14px;text-decoration:none">🧾 Struk<span class="st-x"> & Invoice</span></a>
    <a href="/payment-settings" class="settings-tab" style="padding:11px 18px;border-bottom:3px solid transparent;color:var(--gray);font-weight:600;font-size:14px;text-decoration:none">💳 Pembayaran</a>
  </div>
  <div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;padding:12px 14px;margin-bottom:16px;font-size:12.5px;color:#1E40AF;line-height:1.5">
    💡 <strong>Format Nomor Nota</strong> — atur prefix & template format nota per outlet. Default tiap outlet otomatis dapat prefix dari nama (mis. "Harpy Laundry" → <code>HARPY-</code>).
    Bisa di-customize untuk konsistensi branding (mis. <code>HL-2024-00001</code>, <code>JKT001/2026/06/</code>, dll).
  </div>

  <!-- ════ Printer Thermal (per perangkat) ════ -->
  <div class="hl-card" style="margin-bottom:18px;padding:16px 18px">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
      <div>
        <div style="font-weight:700;color:var(--navy);font-size:15px">🖨 Printer Thermal
          <span style="font-size:11px;color:var(--gray);font-weight:500">— pengaturan per perangkat ini</span></div>
        <div style="font-size:12.5px;color:var(--gray);margin-top:3px">Terpilih: <strong id="prnCurrent" style="color:var(--navy)">—</strong></div>
      </div>
      <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="prnPick()">🔍 Cari / Ganti Printer</button>
    </div>
    <div id="prnList" style="margin-top:10px"></div>
    <label style="display:flex;gap:8px;align-items:center;margin-top:12px;font-size:13px;cursor:pointer;color:var(--navy)">
      <input type="checkbox" id="prnAuto" onchange="if(window.ThermalPrint)ThermalPrint.setAuto(this.checked)"> Auto-cetak struk setelah simpan order (POS)
    </label>
    <div style="font-size:11px;color:#9CA3AF;margin-top:8px">Printer harus sudah di-pair di Setelan Bluetooth HP. Cetak thermal hanya berfungsi di aplikasi (APK). Pilihan ini dipakai untuk Print Struk (POS) & Cetak Ulang Nota.</div>
  </div>

  <div id="outletList" style="min-height:150px">⏳ Memuat...</div>

  <!-- ════ Master Parfum ════ -->
  <div style="background:#FEF3C7;border:1px solid #FDE68A;border-radius:10px;padding:14px 18px;margin:24px 0 14px;font-size:13px;color:#92400E;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <div>
      🌸 <strong>Master Parfum</strong> — daftar pilihan parfum yg muncul di POS. Bisa di-scope ke outlet tertentu (mis. outlet mall punya parfum premium beda).
    </div>
    <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="openParfumModal()">+ Tambah Parfum</button>
  </div>
  <div id="parfumList" style="min-height:80px">⏳ Memuat...</div>
</div>

<!-- Modal Parfum -->
<div class="hl-modal-overlay" id="modalParfum">
  <div class="hl-modal hl-modal-sm">
    <div class="hl-modal-header">
      <span class="hl-modal-title" id="parfumModalTitle">🌸 Tambah Parfum</span>
      <button class="hl-modal-close" onclick="closeParfumModal()">✕</button>
    </div>
    <div class="hl-modal-body">
      <input type="hidden" id="pf_id"/>
      <div class="hl-form-group">
        <label class="hl-label">Nama Parfum <span class="req">*</span></label>
        <input type="text" id="pf_nama" class="hl-input" placeholder="Lavender, Rose, Apple, dll" maxlength="50"/>
      </div>
      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Berlaku di Outlet</label>
          <select id="pf_outlet" class="hl-input">
            <option value="">🌍 Semua outlet</option>
          </select>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Urutan</label>
          <input type="number" id="pf_urutan" class="hl-input" value="0"/>
        </div>
      </div>
      <div class="hl-form-group">
        <label class="hl-label">Status</label>
        <select id="pf_active" class="hl-input">
          <option value="1">✅ Aktif</option>
          <option value="0">⏸️ Nonaktif</option>
        </select>
      </div>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeParfumModal()">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="saveParfum()">💾 Simpan</button>
    </div>
  </div>
</div>

<!-- Modal edit format -->
<div class="hl-modal-overlay" id="modalEdit">
  <div class="hl-modal" style="max-width:620px">
    <div class="hl-modal-header">
      <span class="hl-modal-title">✏️ Edit Format Nota — <span id="edOutletNama"></span></span>
      <button class="hl-modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="hl-modal-body">
      <input type="hidden" id="ed_id"/>
      <input type="hidden" id="ed_outlet_kode"/>

      <div class="hl-form-row">
        <div class="hl-form-group">
          <label class="hl-label">Prefix Nota</label>
          <input type="text" id="ed_prefix" class="hl-input" maxlength="20" oninput="livePreview()"
                 placeholder="HL-, HARPY-, JKT-, dll"/>
        </div>
        <div class="hl-form-group">
          <label class="hl-label">Template Format</label>
          <input type="text" id="ed_format" class="hl-input" maxlength="60" oninput="livePreview()"
                 placeholder="{PREFIX}{YYMMDD}-{COUNTER:3}"/>
        </div>
      </div>

      <!-- Live preview -->
      <div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:12px 16px;margin:8px 0 14px;font-size:14px;color:#166534;">
        Preview nota baru: <strong style="font-family:var(--mono,monospace);font-size:16px;color:#0F7B6C" id="livePreview">HL-260607-001</strong>
      </div>

      <!-- Label printer size -->
      <div style="margin:8px 0 14px;padding:12px 14px;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px">
        <label class="hl-label" style="margin-bottom:8px">🏷 Ukuran Printer Label (stiker produksi)</label>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;padding:6px 12px;border:1px solid #E5E7EB;border-radius:8px;background:#fff">
            <input type="radio" name="ed_label_size" value="58"> 58mm (thermal mini)
          </label>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;padding:6px 12px;border:1px solid #E5E7EB;border-radius:8px;background:#fff">
            <input type="radio" name="ed_label_size" value="80" checked> 80mm (thermal standar)
          </label>
        </div>
      </div>

      <!-- Antar Jemput Mode + Zona -->
      <div style="margin:8px 0 14px;padding:14px;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px">
        <label class="hl-label" style="margin-bottom:8px">🚚 Mode Antar Jemput</label>
        <div style="display:flex;gap:10px;margin-bottom:14px">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;padding:6px 12px;border:1px solid #E5E7EB;border-radius:8px;background:#fff">
            <input type="radio" name="ed_antar_mode" value="free" onchange="toggleZonaSection()"> Free
          </label>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;padding:6px 12px;border:1px solid #E5E7EB;border-radius:8px;background:#fff">
            <input type="radio" name="ed_antar_mode" value="zona" onchange="toggleZonaSection()"> Zona (fee per zona)
          </label>
        </div>

        <div id="zonaSection" style="display:none">
          <label class="hl-label">Daftar Zona</label>
          <div id="zonaList" style="margin-bottom:10px">⏳</div>
          <div style="display:flex;gap:6px">
            <input type="text" id="zona_nama_new" placeholder="Zona 1 - radius 3km" class="hl-input" style="flex:1">
            <input type="number" id="zona_fee_new" placeholder="Rp" class="lm-rp hl-input" style="width:120px">
            <button class="hl-btn hl-btn-primary hl-btn-sm" onclick="addZona()">+ Tambah</button>
          </div>
        </div>
      </div>

      <!-- Absensi & Geofence -->
      <div style="margin:8px 0 14px;padding:14px;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px">
        <div style="font-weight:700;font-size:14px;color:#0F1C3A;margin-bottom:10px">📍 Absensi & Geofence</div>
        <label style="display:flex;gap:8px;align-items:center;margin:10px 0">
          <input type="checkbox" id="abSelfie"> Wajib selfie saat clock-in
        </label>
        <label style="display:flex;gap:8px;align-items:center;margin:10px 0">
          <input type="checkbox" id="abGeofence" onchange="abToggleGeofence()"> Aktifkan geofence (batasi clock-in dalam radius)
        </label>
        <div id="abGeoBox" style="display:none">
          <div id="abMap" style="height:280px;border-radius:10px;margin:10px 0"></div>
          <button type="button" class="hl-btn hl-btn-outline hl-btn-sm" onclick="abUseMyLocation()">📍 Pakai lokasi saya</button>
          <div style="display:flex;gap:10px;margin-top:10px;align-items:center">
            <span>Radius:</span>
            <input type="range" id="abRadius" min="20" max="1000" step="10" value="100" oninput="abRadiusChanged()">
            <span id="abRadiusLbl">100 m</span>
          </div>
          <div style="font-size:12px;color:var(--gray);margin-top:6px">Titik: <span id="abLatLng">—</span></div>
        </div>
        <button class="hl-btn hl-btn-primary hl-btn-sm" style="margin-top:14px" onclick="saveAbsensiConfig()">💾 Simpan Absensi</button>
      </div>

      <!-- Quick templates -->
      <div style="font-size:12px;color:#6B7280;margin-bottom:6px;font-weight:600">⚡ Quick Template:</div>
      <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px">
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="applyTemplate('{PREFIX}{YYMMDD}-{COUNTER:3}')" type="button">Standar (HL-260607-001)</button>
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="applyTemplate('{PREFIX}{YYYYMMDD}-{COUNTER:4}')" type="button">Tahun Penuh (HL-20260607-0001)</button>
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="applyTemplate('{PREFIX}{OUTLET}-{YY}{MM}{DD}-{COUNTER:3}')" type="button">Multi-outlet (HL-HAR-260607-001)</button>
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="applyTemplate('{PREFIX}{COUNTER:5}')" type="button">Counter Only (HL-00001)</button>
        <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="applyTemplate('{PREFIX}{YYYY}/{MM}/{COUNTER:4}')" type="button">Slash (HL-2026/06/0001)</button>
      </div>

      <!-- Token reference -->
      <details style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;padding:8px 14px;font-size:12px;color:#4B5563">
        <summary style="cursor:pointer;font-weight:600;color:#374151">📖 Token yang Tersedia</summary>
        <table style="margin-top:8px;width:100%;font-size:12px;border-collapse:collapse">
          <tr><td style="padding:3px 8px;font-family:var(--mono,monospace)"><strong>{PREFIX}</strong></td><td>Isi dari "Prefix" di atas</td></tr>
          <tr><td style="padding:3px 8px;font-family:var(--mono,monospace)"><strong>{YYYY}</strong> / <strong>{YY}</strong></td><td>Tahun 4 digit (2026) / 2 digit (26)</td></tr>
          <tr><td style="padding:3px 8px;font-family:var(--mono,monospace)"><strong>{MM}</strong> / <strong>{DD}</strong></td><td>Bulan / tanggal 2 digit</td></tr>
          <tr><td style="padding:3px 8px;font-family:var(--mono,monospace)"><strong>{YYMMDD}</strong></td><td>Date 6 digit (260607)</td></tr>
          <tr><td style="padding:3px 8px;font-family:var(--mono,monospace)"><strong>{YYYYMMDD}</strong></td><td>Date 8 digit (20260607)</td></tr>
          <tr><td style="padding:3px 8px;font-family:var(--mono,monospace)"><strong>{OUTLET}</strong></td><td>3 huruf pertama nama outlet (HAR, NEN)</td></tr>
          <tr><td style="padding:3px 8px;font-family:var(--mono,monospace)"><strong>{COUNTER:N}</strong></td><td>Counter per hari, padded N digit (001, 0001)</td></tr>
        </table>
      </details>
    </div>
    <div class="hl-modal-footer">
      <button class="hl-btn hl-btn-outline" onclick="closeModal()">Batal</button>
      <button class="hl-btn hl-btn-primary" onclick="saveFormat()">💾 Simpan</button>
    </div>
  </div>
</div>

<?php renderToast(); ?>

<script>
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

async function loadOutlets() {
  const list = document.getElementById('outletList');
  list.innerHTML = '<div style="padding:20px;text-align:center;color:var(--gray)">⏳ Memuat...</div>';
  const r = await fetch('?action=list');
  const d = await r.json();
  const rows = d.rows || [];
  if (!d.has_cols) {
    list.innerHTML = '<div style="background:#FEF2F2;border:1px solid #FCA5A5;border-radius:8px;padding:14px 18px;color:#991B1B">⚠️ Kolom nota_prefix/nota_format belum ada di tabel outlets. Run migration <code>superadmin/sql/parfum_nota_format_migration.sql</code> dulu.</div>';
    return;
  }
  if (!rows.length) {
    list.innerHTML = '<div style="padding:40px;text-align:center;color:var(--gray)">Belum ada outlet</div>';
    return;
  }
  list.innerHTML = rows.map(r => `
    <div class="hl-card" style="margin-bottom:12px;padding:16px 18px;display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:center">
      <div style="flex:1;min-width:220px">
        <div style="font-weight:700;font-size:15px;color:#111827">
          🏪 ${esc(r.nama_outlet)}
          ${r.is_main == 1 ? '<span style="background:#F0FDF4;color:#166534;font-size:10px;font-weight:700;padding:2px 8px;border-radius:100px;margin-left:6px">UTAMA</span>' : ''}
          ${r.status === 'active' ? '' : `<span style="background:#FEF3C7;color:#92400E;font-size:10px;font-weight:700;padding:2px 8px;border-radius:100px;margin-left:6px">${esc(r.status)}</span>`}
        </div>
        <div style="font-size:12px;color:var(--gray);margin-top:2px">${esc(r.kota||'-')} · ${esc(r.telepon||'no phone')}</div>
        <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;font-size:12.5px">
          <span style="color:#6B7280">Prefix:</span>
          <code style="background:#F3F4F6;padding:2px 8px;border-radius:4px;font-weight:600;color:#0F7B6C">${esc(r.nota_prefix||'(kosong)')}</code>
          <span style="color:#6B7280">Format:</span>
          <code style="background:#F3F4F6;padding:2px 8px;border-radius:4px;font-size:11px;color:#4B5563">${esc(r.nota_format||'(default)')}</code>
        </div>
        <div style="margin-top:6px;font-size:12.5px;color:#6B7280">
          Preview nota: <strong style="font-family:var(--mono,monospace);color:#0F7B6C">${esc(r.preview||'-')}</strong>
        </div>
      </div>
      <button class="hl-btn hl-btn-outline" onclick='openEdit(${JSON.stringify(r)})'>✏️ Edit Format</button>
    </div>
  `).join('');
}

function openEdit(r) {
  document.getElementById('ed_id').value     = r.id;
  document.getElementById('ed_outlet_kode').value = (r.nama_outlet||'').replace(/[^A-Za-z]/g,'').toUpperCase().substring(0,3) || 'OUT';
  document.getElementById('edOutletNama').textContent = r.nama_outlet;
  document.getElementById('ed_prefix').value = r.nota_prefix || 'HL-';
  document.getElementById('ed_format').value = r.nota_format || '{PREFIX}{YYMMDD}-{COUNTER:3}';
  const lsz = (r.label_size === '58') ? '58' : '80';
  document.querySelectorAll('input[name=ed_label_size]').forEach(el => el.checked = (el.value === lsz));
  const am = (r.antar_mode === 'zona') ? 'zona' : 'free';
  document.querySelectorAll('input[name=ed_antar_mode]').forEach(el => el.checked = (el.value === am));
  toggleZonaSection();

  // ── Absensi state init ──
  abResetMap();
  document.getElementById('abSelfie').checked   = !!r.absensi_selfie_wajib;
  document.getElementById('abGeofence').checked = !!r.absensi_geofence_aktif;
  const savedRadius = r.absensi_radius_m ? Math.max(20, Math.min(1000, parseInt(r.absensi_radius_m))) : 100;
  document.getElementById('abRadius').value   = savedRadius;
  document.getElementById('abRadiusLbl').textContent = savedRadius + ' m';
  abLat = r.absensi_lat ? parseFloat(r.absensi_lat) : null;
  abLng = r.absensi_lng ? parseFloat(r.absensi_lng) : null;
  if (r.absensi_geofence_aktif) {
    document.getElementById('abGeoBox').style.display = 'block';
    // Defer map init until modal is visible
    setTimeout(() => abInitMap(abLat, abLng, savedRadius), 100);
  } else {
    document.getElementById('abGeoBox').style.display = 'none';
  }

  document.getElementById('modalEdit').classList.add('open');
  livePreview();
}
function closeModal() { document.getElementById('modalEdit').classList.remove('open'); abResetMap(); }

function applyTemplate(format) {
  document.getElementById('ed_format').value = format;
  livePreview();
}

async function livePreview() {
  const prefix = document.getElementById('ed_prefix').value;
  const format = document.getElementById('ed_format').value;
  const ok = document.getElementById('ed_outlet_kode').value;
  try {
    const r = await fetch('?action=preview', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
      body: JSON.stringify({nota_prefix: prefix, nota_format: format, outlet_kode: ok})
    });
    const d = await r.json();
    document.getElementById('livePreview').textContent = d.preview || '-';
  } catch(e) {
    document.getElementById('livePreview').textContent = '(error)';
  }
}

async function saveFormat() {
  const id = document.getElementById('ed_id').value;
  const prefix = document.getElementById('ed_prefix').value;
  const format = document.getElementById('ed_format').value;
  const labelSize = document.querySelector('input[name=ed_label_size]:checked')?.value || '80';
  const r = await fetch('?action=save', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({id, nota_prefix: prefix, nota_format: format, label_size: labelSize, antar_mode: document.querySelector('input[name=ed_antar_mode]:checked')?.value || 'free'})
  });
  const d = await r.json();
  if (d.error) { showToast(d.error, 'error'); return; }
  showToast('Format nota tersimpan', 'success');
  closeModal();
  loadOutlets();
}

function toggleZonaSection() {
  const mode = document.querySelector('input[name=ed_antar_mode]:checked')?.value;
  document.getElementById('zonaSection').style.display = mode === 'zona' ? 'block' : 'none';
  if (mode === 'zona') loadZonaList();
}

async function loadZonaList() {
  const outletId = document.getElementById('ed_id').value;
  if (!outletId) return;
  const r = await fetch('?action=zona_list&outlet_id=' + outletId);
  const d = await r.json();
  const list = document.getElementById('zonaList');
  if (!d.rows.length) { list.innerHTML = '<div style="color:var(--gray);font-size:12px;padding:8px 0">Belum ada zona</div>'; return; }
  list.innerHTML = d.rows.map(z => `
    <div style="display:flex;gap:8px;align-items:center;padding:6px 0;border-bottom:1px solid #EEF1F8">
      <span style="flex:1;font-size:13px">${esc(z.nama)}</span>
      <span style="font-size:13px;font-weight:600;color:#0F7B6C">Rp ${Number(z.fee).toLocaleString('id-ID')}</span>
      <button onclick="deleteZona(${z.id})" style="background:none;border:none;color:var(--red);cursor:pointer;font-size:14px">×</button>
    </div>
  `).join('');
}

async function addZona() {
  const outletId = document.getElementById('ed_id').value;
  const nama = document.getElementById('zona_nama_new').value.trim();
  const fee  = parseInt(document.getElementById('zona_fee_new').value) || 0;
  if (!nama) { showToast('Nama zona wajib', 'error'); return; }
  const r = await fetch('?action=zona_save', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({outlet_id: outletId, nama, fee})
  });
  const d = await r.json();
  if (d.error) { showToast(d.error,'error'); return; }
  document.getElementById('zona_nama_new').value = '';
  document.getElementById('zona_fee_new').value = '';
  loadZonaList();
}

async function deleteZona(id) {
  if (!await lmConfirm('Hapus zona ini?')) return;
  const outletId = document.getElementById('ed_id').value;
  await fetch('?action=zona_delete', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({id, outlet_id: parseInt(outletId)})
  });
  loadZonaList();
}

// ── PARFUM CRUD ──
let allOutletsForParfum = [];
async function loadParfum() {
  const list = document.getElementById('parfumList');
  list.innerHTML = '<div style="padding:14px;text-align:center;color:var(--gray)">⏳ Memuat parfum...</div>';
  const r = await fetch('?action=parfum_list');
  const d = await r.json();
  if (d.error) {
    list.innerHTML = `<div style="background:#FEF2F2;border:1px solid #FCA5A5;padding:10px 14px;border-radius:8px;color:#991B1B;font-size:12px">${esc(d.error)}</div>`;
    return;
  }
  const rows = d.rows || [];
  if (!rows.length) {
    list.innerHTML = '<div style="padding:24px;text-align:center;color:var(--gray);font-size:13px;background:#F9FAFB;border:1px dashed #E5E7EB;border-radius:8px">🌸 Belum ada parfum. Klik "Tambah Parfum" untuk mulai.</div>';
    return;
  }
  list.innerHTML = `<table style="width:100%;border-collapse:collapse;font-size:13px;background:#fff;border-radius:8px;overflow:hidden;border:1px solid #E5E7EB">
    <thead><tr style="background:#F3F4F6;text-align:left">
      <th style="padding:10px 12px">Nama Parfum</th>
      <th style="padding:10px 12px">Berlaku Di</th>
      <th style="padding:10px 12px">Status</th>
      <th style="padding:10px 12px;text-align:right"></th>
    </tr></thead>
    <tbody>${rows.map(r => `
      <tr style="border-top:1px solid #F3F4F6">
        <td style="padding:10px 12px"><strong>🌸 ${esc(r.nama)}</strong></td>
        <td style="padding:10px 12px;font-size:12px;${r.outlet_id?'color:#0F7B6C':'color:#6B7280'}">${r.outlet_id ? '🏪 '+esc(r.nama_outlet||'Outlet '+r.outlet_id) : '🌍 Semua outlet'}</td>
        <td style="padding:10px 12px">${r.is_active==1?'<span style="color:#059669">●Aktif</span>':'<span style="color:#9CA3AF">○Off</span>'}</td>
        <td style="padding:10px 12px;text-align:right;white-space:nowrap">
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick='editParfum(${JSON.stringify(r)})'>✏️</button>
          <button class="hl-btn hl-btn-danger hl-btn-sm" onclick="deleteParfum(${r.id})">🗑️</button>
        </td>
      </tr>`).join('')}</tbody></table>`;
}

async function populateParfumOutlets() {
  if (allOutletsForParfum.length > 0) return;
  const r = await fetch('?action=list');
  const d = await r.json();
  allOutletsForParfum = d.rows || [];
  const sel = document.getElementById('pf_outlet');
  sel.innerHTML = '<option value="">🌍 Semua outlet</option>' +
    allOutletsForParfum.map(o => `<option value="${o.id}">🏪 ${esc(o.nama_outlet)}</option>`).join('');
}

async function openParfumModal() {
  await populateParfumOutlets();
  document.getElementById('parfumModalTitle').textContent = '🌸 Tambah Parfum';
  document.getElementById('pf_id').value = '';
  document.getElementById('pf_nama').value = '';
  document.getElementById('pf_outlet').value = '';
  document.getElementById('pf_urutan').value = 0;
  document.getElementById('pf_active').value = 1;
  document.getElementById('modalParfum').classList.add('open');
}
function closeParfumModal() { document.getElementById('modalParfum').classList.remove('open'); }

async function editParfum(r) {
  await populateParfumOutlets();
  document.getElementById('parfumModalTitle').textContent = '✏️ Edit Parfum';
  document.getElementById('pf_id').value = r.id;
  document.getElementById('pf_nama').value = r.nama;
  document.getElementById('pf_outlet').value = r.outlet_id || '';
  document.getElementById('pf_urutan').value = r.urutan;
  document.getElementById('pf_active').value = r.is_active;
  document.getElementById('modalParfum').classList.add('open');
}

async function saveParfum() {
  const payload = {
    id: document.getElementById('pf_id').value || null,
    nama: document.getElementById('pf_nama').value.trim(),
    outlet_id: document.getElementById('pf_outlet').value || null,
    is_active: parseInt(document.getElementById('pf_active').value),
    urutan: parseInt(document.getElementById('pf_urutan').value)||0,
  };
  if (!payload.nama) { showToast('Nama wajib','error'); return; }
  const r = await fetch('?action=parfum_save', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify(payload)
  });
  const d = await r.json();
  if (d.error) { showToast(d.error,'error'); return; }
  showToast('Parfum disimpan','success');
  closeParfumModal();
  loadParfum();
}

async function deleteParfum(id) {
  if (!await lmConfirm('Hapus parfum ini?')) return;
  const r = await fetch('?action=parfum_delete', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({id})
  });
  const d = await r.json();
  if (d.error) { showToast(d.error,'error'); return; }
  showToast('Parfum dihapus','success');
  loadParfum();
}

// ── Absensi & Geofence ──
let abMap=null, abMarker=null, abCircle=null, abLat=null, abLng=null;

function abResetMap() {
  if (abMap) { abMap.remove(); abMap=null; }
  abMarker=null; abCircle=null; abLat=null; abLng=null;
}

function abInitMap(lat, lng, radius) {
  if (abMap) { abMap.remove(); abMap=null; }
  const c = [lat ?? -6.2, lng ?? 106.816]; // default Jakarta
  abMap = L.map('abMap').setView(c, lat ? 17 : 12);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(abMap);
  abCircle = L.circle(c, { radius: radius||100, color:'#35E8D5', fillOpacity:.15 }).addTo(abMap);
  abMarker = L.circleMarker(c, { radius:8, color:'#0F1C3A', fillColor:'#35E8D5', fillOpacity:1 }).addTo(abMap);
  if (lat) { abLat=lat; abLng=lng; abUpdateLbl(); }
  abMap.on('click', e => abSetPoint(e.latlng.lat, e.latlng.lng));
  setTimeout(()=>abMap.invalidateSize(), 200);
}
function abSetPoint(lat,lng){ abLat=lat; abLng=lng; const ll=[lat,lng]; abMarker.setLatLng(ll); abCircle.setLatLng(ll); abUpdateLbl(); }
function abUpdateLbl(){ document.getElementById('abLatLng').textContent = abLat.toFixed(6)+', '+abLng.toFixed(6); }
function abRadiusChanged(){ const r=+document.getElementById('abRadius').value; document.getElementById('abRadiusLbl').textContent=r+' m'; if(abCircle) abCircle.setRadius(r); }
function abToggleGeofence(){ const on=document.getElementById('abGeofence').checked; document.getElementById('abGeoBox').style.display=on?'block':'none'; if(on && !abMap) abInitMap(abLat,abLng,+document.getElementById('abRadius').value); else if(on) setTimeout(()=>abMap.invalidateSize(),200); }
function abUseMyLocation(){ if(!navigator.geolocation){showToast('GPS tak tersedia','error');return;} navigator.geolocation.getCurrentPosition(p=>{ abMap.setView([p.coords.latitude,p.coords.longitude],17); abSetPoint(p.coords.latitude,p.coords.longitude); },()=>showToast('Gagal ambil lokasi','error'),{enableHighAccuracy:true}); }
async function saveAbsensiConfig(){
  const id = document.getElementById('ed_id').value;
  const body = {
    id: parseInt(id),
    selfie_wajib: document.getElementById('abSelfie').checked,
    geofence_aktif: document.getElementById('abGeofence').checked,
    lat: abLat, lng: abLng, radius_m: +document.getElementById('abRadius').value
  };
  const r = await fetch('outlet-settings.php?action=save_absensi', { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()}, body: JSON.stringify(body) });
  const d = await r.json();
  if (d.success) showToast('Setting absensi tersimpan','success'); else showToast(d.error||'Gagal','error');
}

// ── Printer Thermal (client-side, per device via ThermalPrint) ──
function prnRefresh() {
  const cur = document.getElementById('prnCurrent');
  if (!cur) return;
  if (!window.ThermalPrint) { cur.textContent = '(plugin tak tersedia)'; return; }
  const pr = ThermalPrint.getPrinter();
  cur.textContent = (pr && pr.name) ? pr.name : 'Belum dipilih';
  const au = document.getElementById('prnAuto'); if (au) au.checked = ThermalPrint.autoEnabled();
}
async function prnPick() {
  if (!window.ThermalPrint || !ThermalPrint.isAvailable()) {
    lmAlert('Cetak thermal hanya tersedia di aplikasi (APK) dengan plugin printer. Buka lewat APK, bukan browser.');
    return;
  }
  const list = document.getElementById('prnList');
  list.innerHTML = '⏳ Memindai printer Bluetooth…';
  try {
    const printers = await ThermalPrint.scanPrinters(6000);
    if (!printers || !printers.length) {
      list.innerHTML = '<div style="color:#991B1B;font-size:12px;padding:6px 0">Tak ada printer ditemukan. Pastikan printer sudah di-pair di Setelan Bluetooth HP.</div>';
      return;
    }
    list.innerHTML = printers.map(p =>
      '<button class="hl-btn hl-btn-outline hl-btn-sm" style="display:block;width:100%;text-align:left;margin-bottom:6px" onclick=\'prnSelect(' + JSON.stringify(p).replace(/'/g, "&#39;") + ')\'>🖨 ' + (p.name || '(tanpa nama)') + '</button>'
    ).join('');
  } catch (e) {
    list.innerHTML = '<div style="color:#991B1B;font-size:12px;padding:6px 0">Gagal scan: ' + (e.message || e) + '</div>';
  }
}
function prnSelect(p) {
  ThermalPrint.setPrinter(p);
  document.getElementById('prnList').innerHTML = '';
  prnRefresh();
  showToast('✅ Printer dipilih: ' + (p.name || ''), 'success');
}

document.addEventListener('DOMContentLoaded', () => {
  loadOutlets();
  loadParfum();
  prnRefresh();
});
</script>

</body>
</html>

<?php
// ══════════════════════════════════════════════════════════════════
// superadmin/coin_pricing.php — Manage harga coin per fitur
// ══════════════════════════════════════════════════════════════════

if (!defined('SA_ROOT')) define('SA_ROOT', __DIR__);
require_once SA_ROOT . '/middleware/superadmin_guard.php';
require_once SA_ROOT . '/superadmin_components.php';
require_once dirname(__DIR__) . '/core/CoinLedger.php';
require_once SA_ROOT . '/../core/SaPermission.php';

date_default_timezone_set('Asia/Jakarta');

$action = $_GET['action'] ?? '';

// ─────────────────────────────────────────────────────────────────
// API LAYER
// ─────────────────────────────────────────────────────────────────
if ($action) {
    header('Content-Type: application/json');
    $db = Database::get();

    // ── GET: list pricing ─────────────────────────────────────
    if ($action === 'list') {
        $kat = $_GET['kategori'] ?? '';
        $sql = "SELECT id, feature_key, nama_fitur, deskripsi, kategori,
                       harga_coin, harga_minimum, daily_limit, is_active, catatan_internal,
                       updated_at,
                       (SELECT s.name FROM super_admins s WHERE s.id = p.updated_by) AS updated_by_name
                FROM saas_coin_pricing p";
        $params = [];
        if ($kat && in_array($kat, ['dokumen','whatsapp','ai','export','lainnya'], true)) {
            $sql .= " WHERE kategori = ?";
            $params[] = $kat;
        }
        $sql .= " ORDER BY kategori, nama_fitur";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['ok' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── GET: detail satu fitur (untuk modal edit) ────────────
    if ($action === 'detail') {
        $id = (int)($_GET['id'] ?? 0);
        $row = $db->prepare("SELECT * FROM saas_coin_pricing WHERE id = ?");
        $row->execute([$id]);
        $data = $row->fetch(PDO::FETCH_ASSOC);
        if (!$data) { echo json_encode(['error' => 'Tidak ditemukan']); exit; }
        echo json_encode(['ok' => true, 'row' => $data]);
        exit;
    }

    // ── GET: history perubahan ───────────────────────────────
    if ($action === 'history') {
        $featureKey = $_GET['feature_key'] ?? '';
        $sql = "SELECT h.*, s.name AS changed_by_name
                FROM saas_coin_pricing_history h
                LEFT JOIN super_admins s ON s.id = h.changed_by";
        $params = [];
        if ($featureKey) {
            $sql .= " WHERE h.feature_key = ?";
            $params[] = $featureKey;
        }
        $sql .= " ORDER BY h.changed_at DESC LIMIT 100";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Enrich dengan nama_fitur (kalau masih ada di pricing)
        $featKeys = array_unique(array_column($rows, 'feature_key'));
        $namaMap = [];
        if ($featKeys) {
            $place = implode(',', array_fill(0, count($featKeys), '?'));
            $nm = $db->prepare("SELECT feature_key, nama_fitur FROM saas_coin_pricing WHERE feature_key IN ($place)");
            $nm->execute($featKeys);
            foreach ($nm->fetchAll(PDO::FETCH_ASSOC) as $n) $namaMap[$n['feature_key']] = $n['nama_fitur'];
        }
        foreach ($rows as &$r) $r['nama_fitur'] = $namaMap[$r['feature_key']] ?? $r['feature_key'];
        echo json_encode(['ok' => true, 'rows' => $rows]);
        exit;
    }

    // ── POST actions ─────────────────────────────────────────
    saVerifyCsrf();

    // ── SAVE harga (update / create) ─────────────────────────
    if ($action === 'save') {
        SaPermission::require('coin_pricing.edit');
        $d            = json_decode(file_get_contents('php://input'), true) ?: [];
        $id           = (int)($d['id'] ?? 0);
        $featureKey   = preg_replace('/[^a-z0-9_]/i', '', substr(trim($d['feature_key'] ?? ''), 0, 50));
        $namaFitur    = substr(trim($d['nama_fitur'] ?? ''), 0, 100);
        $kategori     = in_array($d['kategori'] ?? '', ['dokumen','whatsapp','ai','export','lainnya'], true)
                        ? $d['kategori'] : 'lainnya';
        $hargaCoin    = max(0, (int)($d['harga_coin'] ?? 0));
        $hargaMin     = max(0, (int)($d['harga_minimum'] ?? 0));
        $dailyLimit   = max(0, (int)($d['daily_limit'] ?? 0));
        $isActive     = empty($d['is_active']) ? 0 : 1;
        $deskripsi    = trim($d['deskripsi'] ?? '');
        $catatan      = trim($d['catatan_internal'] ?? '');
        $alasan       = trim($d['alasan'] ?? '');
        $saId         = (int)($_SESSION['superadmin_id'] ?? 0);

        if (!$featureKey)         { echo json_encode(['error' => 'Feature key wajib diisi.']); exit; }
        if (!$namaFitur)          { echo json_encode(['error' => 'Nama fitur wajib diisi.']); exit; }
        if ($hargaCoin < $hargaMin) {
            echo json_encode(['error' => "Harga tidak boleh di bawah minimum ($hargaMin coin)."]);
            exit;
        }

        try {
            $db->beginTransaction();

            if ($id > 0) {
                // UPDATE — wajib ada alasan
                $old = $db->prepare("SELECT harga_coin, is_active, harga_minimum FROM saas_coin_pricing WHERE id = ?");
                $old->execute([$id]);
                $oldRow = $old->fetch(PDO::FETCH_ASSOC);
                if (!$oldRow) { $db->rollBack(); echo json_encode(['error' => 'Fitur tidak ditemukan']); exit; }

                $priceChanged = ((int)$oldRow['harga_coin'] !== $hargaCoin);
                $statusChanged = ((int)$oldRow['is_active'] !== $isActive);

                if (($priceChanged || $statusChanged) && !$alasan) {
                    $db->rollBack();
                    echo json_encode(['error' => 'Alasan perubahan wajib diisi.']);
                    exit;
                }

                $db->prepare(
                    "UPDATE saas_coin_pricing
                        SET feature_key=?, nama_fitur=?, kategori=?, harga_coin=?,
                            harga_minimum=?, daily_limit=?, is_active=?, deskripsi=?, catatan_internal=?,
                            updated_by=?
                      WHERE id=?"
                )->execute([$featureKey, $namaFitur, $kategori, $hargaCoin, $hargaMin,
                            $dailyLimit, $isActive, $deskripsi, $catatan, $saId, $id]);

                // Catat history kalau ada perubahan
                if ($priceChanged || $statusChanged) {
                    $db->prepare(
                        "INSERT INTO saas_coin_pricing_history
                            (feature_key, harga_lama, harga_baru, is_active_lama, is_active_baru, changed_by, alasan)
                         VALUES (?,?,?,?,?,?,?)"
                    )->execute([$featureKey, (int)$oldRow['harga_coin'], $hargaCoin,
                                (int)$oldRow['is_active'], $isActive, $saId, $alasan]);
                }

                $db->commit();
                CoinLedger::invalidateCache();
                logSuperAdminAction('update_coin_pricing', null,
                    "Update $featureKey: harga {$oldRow['harga_coin']}→$hargaCoin, aktif {$oldRow['is_active']}→$isActive. Alasan: $alasan");
                echo json_encode(['ok' => true, 'msg' => "Fitur \"$namaFitur\" berhasil diperbarui."]);

            } else {
                // INSERT — fitur baru
                $db->prepare(
                    "INSERT INTO saas_coin_pricing
                        (feature_key, nama_fitur, kategori, harga_coin, harga_minimum, daily_limit,
                         is_active, deskripsi, catatan_internal, updated_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?)"
                )->execute([$featureKey, $namaFitur, $kategori, $hargaCoin, $hargaMin, $dailyLimit,
                            $isActive, $deskripsi, $catatan, $saId]);
                $newId = (int)$db->lastInsertId();

                // Catat history sebagai 'created'
                $db->prepare(
                    "INSERT INTO saas_coin_pricing_history
                        (feature_key, harga_lama, harga_baru, is_active_lama, is_active_baru, changed_by, alasan)
                     VALUES (?,0,?,NULL,?,?,?)"
                )->execute([$featureKey, $hargaCoin, $isActive, $saId, $alasan ?: 'Fitur baru ditambahkan']);

                $db->commit();
                CoinLedger::invalidateCache();
                logSuperAdminAction('create_coin_pricing', null,
                    "Tambah fitur $featureKey ($namaFitur) harga $hargaCoin coin");
                echo json_encode(['ok' => true, 'msg' => "Fitur \"$namaFitur\" berhasil ditambahkan.", 'id' => $newId]);
            }

        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $msg = $e->getMessage();
            if (stripos($msg, 'duplicate') !== false) {
                $msg = "Feature key \"$featureKey\" sudah ada.";
            }
            echo json_encode(['error' => $msg]);
        }
        exit;
    }

    // ── TOGGLE is_active (quick switch) ──────────────────────
    if ($action === 'toggle') {
        $d   = json_decode(file_get_contents('php://input'), true) ?: [];
        $id  = (int)($d['id'] ?? 0);
        $val = empty($d['value']) ? 0 : 1;
        $saId = (int)($_SESSION['superadmin_id'] ?? 0);
        try {
            $db->beginTransaction();
            $old = $db->prepare("SELECT feature_key, harga_coin, is_active FROM saas_coin_pricing WHERE id = ?");
            $old->execute([$id]);
            $oldRow = $old->fetch(PDO::FETCH_ASSOC);
            if (!$oldRow) { $db->rollBack(); echo json_encode(['error' => 'Tidak ditemukan']); exit; }

            $db->prepare("UPDATE saas_coin_pricing SET is_active=?, updated_by=? WHERE id=?")
               ->execute([$val, $saId, $id]);
            $db->prepare(
                "INSERT INTO saas_coin_pricing_history
                    (feature_key, harga_lama, harga_baru, is_active_lama, is_active_baru, changed_by, alasan)
                 VALUES (?,?,?,?,?,?,?)"
            )->execute([$oldRow['feature_key'], (int)$oldRow['harga_coin'], (int)$oldRow['harga_coin'],
                        (int)$oldRow['is_active'], $val, $saId,
                        $val ? 'Diaktifkan via toggle' : 'Dinonaktifkan via toggle']);
            $db->commit();
            CoinLedger::invalidateCache();
            logSuperAdminAction('toggle_coin_pricing', null,
                "Toggle {$oldRow['feature_key']} → " . ($val ? 'aktif' : 'nonaktif'));
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// Tampilan halaman
$csrf = saGetCsrf();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php saRenderHead('Coin Pricing'); ?>
<style>
  .cp-toolbar { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:14px; }
  .cp-tabs { display:flex; gap:6px; flex-wrap:wrap; }
  .cp-tab {
    background:var(--slate-elev); color:var(--ink-soft); border:1px solid var(--crease);
    padding:7px 14px; border-radius:8px; cursor:pointer; font-size:13px; font-weight:600;
    transition:all .15s;
  }
  .cp-tab:hover { background:var(--slate-elev); color:var(--glow); }
  .cp-tab.active { background:var(--sa); color:#0F1C3A; border-color:var(--sa); }

  .cat-badge {
    display:inline-block; padding:3px 8px; border-radius:6px; font-size:10px; font-weight:700;
    text-transform:uppercase; letter-spacing:.03em;
  }
  .cat-ai       { background:rgba(168,85,247,.18); color:#C4B5FD; }
  .cat-whatsapp { background:rgba(34,197,94,.18); color:#86EFAC; }
  .cat-dokumen  { background:rgba(59,130,246,.18); color:#35E8D5; }
  .cat-export   { background:rgba(245,158,11,.18); color:#F59E0B; }
  .cat-lainnya  { background:rgba(156,163,175,.18); color:#D1D5DB; }

  .price-cell { font-weight:700; color:#F59E0B; font-family:var(--mono); }
  .min-cell   { font-size:11px; color:var(--ash); font-family:var(--mono); }
  .feat-key   { font-family:var(--mono); font-size:10px; color:var(--ash); margin-top:2px; }

  .modal-overlay {
    position:fixed; inset:0; background:rgba(0,0,0,.7); z-index:1000;
    display:none; align-items:center; justify-content:center; padding:20px;
  }
  .modal-overlay.open { display:flex; }
  .modal-box {
    background:var(--slate); color:var(--glow); border:1px solid var(--crease);
    border-radius:14px; padding:24px; max-width:520px; width:100%; max-height:90vh; overflow-y:auto;
  }
  .modal-title { font-size:16px; font-weight:800; margin-bottom:18px; padding-bottom:12px; border-bottom:1px solid var(--crease); }
  .form-row { margin-bottom:14px; }
  .form-row label { display:block; font-size:12px; font-weight:600; color:var(--ink-soft); margin-bottom:6px; }
  .form-row input, .form-row select, .form-row textarea {
    width:100%; padding:10px 12px; background:var(--slate-elev); border:1px solid var(--crease);
    border-radius:8px; color:var(--glow); font-size:13px; font-family:var(--font);
  }
  .form-row textarea { resize:vertical; min-height:60px; }
  .form-row small { display:block; font-size:11px; color:var(--ash); margin-top:4px; }
  .form-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:20px; padding-top:16px; border-top:1px solid var(--crease); }

  .toggle-pill {
    display:inline-flex; align-items:center; gap:6px; cursor:pointer; user-select:none;
    padding:4px 10px; border-radius:20px; font-size:11px; font-weight:700;
  }
  .toggle-pill.on  { background:rgba(34,197,94,.15); color:#86EFAC; }
  .toggle-pill.off { background:rgba(244,63,94,.18); color:#F43F5E; }
  .toggle-pill .dot { width:8px; height:8px; border-radius:50%; }
  .toggle-pill.on .dot  { background:#10B981; }
  .toggle-pill.off .dot { background:#EF4444; }

  .alert-msg { padding:10px 14px; border-radius:8px; font-size:13px; margin-bottom:14px; }
  .alert-msg.error   { background:rgba(244,63,94,.18); color:#F43F5E; border:1px solid rgba(239,68,68,.3); }
  .alert-msg.success { background:rgba(34,197,94,.15); color:#86EFAC; border:1px solid rgba(34,197,94,.3); }

  .tab-pane { display:none; }
  .tab-pane.active { display:block; }
</style>
</head>
<body>
<div class="sa-layout">
<?php saRenderNav('coin_pricing', 'Coin Pricing'); ?>

<div class="sa-page-header">
  <h1>💲 Coin Pricing Management</h1>
  <p>Atur harga coin per fitur — perubahan berlaku live tanpa deploy ulang</p>
</div>

<!-- ── Tabs Utama ──────────────────────────────────────── -->
<div class="cp-toolbar" style="margin-bottom:18px;">
  <button class="cp-tab active" data-tab="pricing" onclick="switchTab('pricing')">📋 Daftar Pricing</button>
  <button class="cp-tab" data-tab="history" onclick="switchTab('history')">📜 History Perubahan</button>
</div>

<!-- ════════════════════════════════════════════════════════════════ -->
<!-- TAB: Daftar Pricing                                              -->
<!-- ════════════════════════════════════════════════════════════════ -->
<div class="tab-pane active" id="tabPricing">
  <div class="sa-card">
    <div class="sa-card-header">
      <h3>Daftar Harga Fitur</h3>
      <button class="sa-btn sa-btn-primary sa-btn-sm" onclick="openEditModal(0)">＋ Tambah Fitur</button>
    </div>

    <div class="cp-toolbar" style="padding:0 16px 12px;">
      <span style="font-size:12px; color:var(--ash);">Filter:</span>
      <div class="cp-tabs" id="filterTabs">
        <button class="cp-tab active" data-kat="" onclick="filterKat('')">Semua</button>
        <button class="cp-tab" data-kat="dokumen"  onclick="filterKat('dokumen')">📄 Dokumen</button>
        <button class="cp-tab" data-kat="whatsapp" onclick="filterKat('whatsapp')">📱 WhatsApp</button>
        <button class="cp-tab" data-kat="ai"       onclick="filterKat('ai')">🤖 AI</button>
        <button class="cp-tab" data-kat="export"   onclick="filterKat('export')">📤 Export</button>
        <button class="cp-tab" data-kat="lainnya"  onclick="filterKat('lainnya')">⚙️ Lainnya</button>
      </div>
    </div>

    <div class="sa-table-wrap">
      <table class="sa-table">
        <thead>
          <tr>
            <th>Fitur</th>
            <th>Kategori</th>
            <th style="text-align:right;">Harga</th>
            <th style="text-align:right;">Min</th>
            <th style="text-align:right;">Limit/Hari</th>
            <th>Status</th>
            <th>Update</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody id="pricingBody">
          <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--ash-dim);">Memuat...</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════ -->
<!-- TAB: History Perubahan                                           -->
<!-- ════════════════════════════════════════════════════════════════ -->
<div class="tab-pane" id="tabHistory">
  <div class="sa-card">
    <div class="sa-card-header">
      <h3>History Perubahan Harga</h3>
      <small style="color:var(--ash);font-size:12px;">100 entry terakhir</small>
    </div>
    <div class="sa-table-wrap">
      <table class="sa-table">
        <thead>
          <tr>
            <th>Waktu</th>
            <th>Fitur</th>
            <th style="text-align:right;">Harga Lama</th>
            <th style="text-align:right;">Harga Baru</th>
            <th>Status</th>
            <th>Oleh</th>
            <th>Alasan</th>
          </tr>
        </thead>
        <tbody id="historyBody">
          <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--ash-dim);">Memuat...</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════ -->
<!-- MODAL: Edit / Tambah Fitur                                       -->
<!-- ════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="editModal" onclick="if(event.target===this)closeEditModal()">
  <div class="modal-box">
    <div class="modal-title" id="modalTitle">Edit Pricing</div>
    <div id="editAlert"></div>
    <input type="hidden" id="f_id" value="0">
    <div class="form-row">
      <label>Feature Key (kode internal)</label>
      <input type="text" id="f_key" placeholder="cth: send_wa_notif" maxlength="50">
      <small>Hanya huruf, angka, underscore. Dipakai di kode PHP — jangan diubah kalau sudah dipakai.</small>
    </div>
    <div class="form-row">
      <label>Nama Fitur (tampil ke admin)</label>
      <input type="text" id="f_nama" placeholder="cth: Kirim WA Notifikasi Status" maxlength="100">
    </div>
    <div class="form-row">
      <label>Kategori</label>
      <select id="f_kategori">
        <option value="dokumen">📄 Dokumen</option>
        <option value="whatsapp">📱 WhatsApp</option>
        <option value="ai">🤖 AI</option>
        <option value="export">📤 Export</option>
        <option value="lainnya">⚙️ Lainnya</option>
      </select>
    </div>
    <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
      <div>
        <label>Harga Coin</label>
        <input type="number" id="f_harga" min="0" step="1" value="0">
      </div>
      <div>
        <label>Harga Minimum</label>
        <input type="number" id="f_min" min="0" step="1" value="0">
        <small>Batas bawah — proteksi margin AI cost</small>
      </div>
    </div>
    <div class="form-row">
      <label>Limit per Hari per Tenant</label>
      <input type="number" id="f_limit" min="0" step="1" value="0">
      <small>0 = unlimited. Rate limit untuk fitur AI (cegah abuse + cost meledak). Reset otomatis 00:00 WIB.</small>
    </div>
    <div class="form-row">
      <label>Deskripsi (untuk tenant)</label>
      <textarea id="f_desk" rows="2" placeholder="Deskripsi singkat fitur ini..."></textarea>
    </div>
    <div class="form-row">
      <label>Catatan Internal (admin only)</label>
      <textarea id="f_catatan" rows="2" placeholder="cth: AI cost $0.01/call, margin 3x"></textarea>
    </div>
    <div class="form-row">
      <label>Alasan Perubahan <span style="color:#F43F5E">*</span></label>
      <textarea id="f_alasan" rows="2" placeholder="Wajib diisi kalau ada perubahan harga / status..."></textarea>
      <small>Wajib kalau ubah harga atau toggle status. Tercatat di history.</small>
    </div>
    <div class="form-row">
      <label>Status</label>
      <div style="display:flex;gap:14px;align-items:center;">
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
          <input type="radio" name="f_active" value="1" checked> <span>● Aktif</span>
        </label>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
          <input type="radio" name="f_active" value="0"> <span>○ Nonaktif (platform-wide)</span>
        </label>
      </div>
    </div>
    <div class="form-actions">
      <button class="sa-btn sa-btn-outline" onclick="closeEditModal()">Batal</button>
      <button class="sa-btn sa-btn-primary" onclick="saveFitur()">💾 Simpan</button>
    </div>
  </div>
</div>

<?php saRenderNavClose(); ?>

<script>
const CSRF = <?= json_encode($csrf) ?>;
let currentKat = '';

function switchTab(t) {
  document.querySelectorAll('.cp-tab[data-tab]').forEach(b => b.classList.toggle('active', b.dataset.tab === t));
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.toggle('active', p.id === 'tab' + t.charAt(0).toUpperCase() + t.slice(1)));
  if (t === 'history') loadHistory();
  if (t === 'pricing') loadPricing();
}

function filterKat(k) {
  currentKat = k;
  document.querySelectorAll('#filterTabs .cp-tab').forEach(b => b.classList.toggle('active', b.dataset.kat === k));
  loadPricing();
}

function catBadge(k) {
  const map = { ai:'🤖 AI', whatsapp:'📱 WA', dokumen:'📄 Dok', export:'📤 Export', lainnya:'⚙️ Lain' };
  return `<span class="cat-badge cat-${k}">${map[k] || k}</span>`;
}

function esc(s) {
  return (s == null ? '' : String(s))
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function fmtDate(s) {
  if (!s) return '-';
  const d = new Date(s.replace(' ', 'T'));
  return d.toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'2-digit' }) +
         ' ' + d.toLocaleTimeString('id-ID', { hour:'2-digit', minute:'2-digit' });
}

async function loadPricing() {
  const tb = document.getElementById('pricingBody');
  tb.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--ash-dim);">Memuat...</td></tr>';
  const url = '/superadmin/coin_pricing.php?action=list' + (currentKat ? '&kategori=' + currentKat : '');
  const r = await fetch(url);
  const j = await r.json();
  if (!j.ok) { tb.innerHTML = `<tr><td colspan="7" style="color:#F43F5E;padding:24px;text-align:center;">${esc(j.error || 'Gagal load')}</td></tr>`; return; }
  if (j.rows.length === 0) {
    tb.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--ash-dim);">Belum ada fitur</td></tr>';
    return;
  }
  tb.innerHTML = j.rows.map(r => `
    <tr>
      <td>
        <div style="font-weight:600;">${esc(r.nama_fitur)}</div>
        <div class="feat-key">${esc(r.feature_key)}</div>
      </td>
      <td>${catBadge(r.kategori)}</td>
      <td class="price-cell" style="text-align:right;">${Number(r.harga_coin).toLocaleString('id-ID')}</td>
      <td class="min-cell" style="text-align:right;">${Number(r.harga_minimum).toLocaleString('id-ID')}</td>
      <td style="text-align:right;font-family:'DM Mono',monospace;font-size:12px">
        ${(r.daily_limit && r.daily_limit > 0) ? `<span style="background:rgba(255,165,0,.15);color:var(--amber);padding:2px 8px;border-radius:8px">${r.daily_limit}×</span>` : '<span style="color:var(--ash-dim)">∞</span>'}
      </td>
      <td>
        <span class="toggle-pill ${r.is_active==1?'on':'off'}" onclick="quickToggle(${r.id}, ${r.is_active==1?0:1})">
          <span class="dot"></span>${r.is_active==1?'Aktif':'Nonaktif'}
        </span>
      </td>
      <td style="font-size:11px;color:var(--ash);">
        ${fmtDate(r.updated_at)}<br>
        <span style="font-size:10px;">${r.updated_by_name ? 'oleh ' + esc(r.updated_by_name) : ''}</span>
      </td>
      <td>
        <button class="sa-btn sa-btn-outline sa-btn-sm" onclick="openEditModal(${r.id})">✏️ Edit</button>
      </td>
    </tr>
  `).join('');
}

async function loadHistory() {
  const tb = document.getElementById('historyBody');
  tb.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--ash-dim);">Memuat...</td></tr>';
  const r = await fetch('/superadmin/coin_pricing.php?action=history');
  const j = await r.json();
  if (!j.ok) { tb.innerHTML = `<tr><td colspan="7" style="color:#F43F5E;padding:24px;text-align:center;">${esc(j.error || 'Gagal load')}</td></tr>`; return; }
  if (j.rows.length === 0) {
    tb.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--ash-dim);">Belum ada history perubahan</td></tr>';
    return;
  }
  tb.innerHTML = j.rows.map(r => {
    const priceDiff = Number(r.harga_baru) - Number(r.harga_lama);
    const diffClass = priceDiff > 0 ? 'color:#F43F5E' : (priceDiff < 0 ? 'color:#86EFAC' : 'color:var(--ash)');
    const statusChange = (r.is_active_lama != null && Number(r.is_active_lama) !== Number(r.is_active_baru))
      ? `<span class="toggle-pill ${r.is_active_baru==1?'on':'off'}"><span class="dot"></span>${r.is_active_baru==1?'Aktif':'Nonaktif'}</span>`
      : '<span style="color:var(--ash-dim);font-size:11px;">—</span>';
    return `
      <tr>
        <td style="font-size:11px;color:var(--ink-soft);">${fmtDate(r.changed_at)}</td>
        <td>
          <div style="font-weight:600;font-size:12px;">${esc(r.nama_fitur)}</div>
          <div class="feat-key">${esc(r.feature_key)}</div>
        </td>
        <td class="price-cell" style="text-align:right;color:var(--ash);">${Number(r.harga_lama).toLocaleString('id-ID')}</td>
        <td class="price-cell" style="text-align:right;">
          ${Number(r.harga_baru).toLocaleString('id-ID')}
          ${priceDiff !== 0 ? `<div style="font-size:10px;${diffClass};">${priceDiff > 0 ? '+' : ''}${priceDiff}</div>` : ''}
        </td>
        <td>${statusChange}</td>
        <td style="font-size:12px;">${esc(r.changed_by_name || '-')}</td>
        <td style="font-size:12px;color:var(--ink-soft);max-width:240px;">${esc(r.alasan || '-')}</td>
      </tr>
    `;
  }).join('');
}

async function openEditModal(id) {
  const a = document.getElementById('editAlert'); a.innerHTML = '';
  document.getElementById('modalTitle').textContent = id ? '✏️ Edit Pricing' : '+ Tambah Fitur Baru';
  document.getElementById('f_id').value = id;

  if (id) {
    const r = await fetch('/superadmin/coin_pricing.php?action=detail&id=' + id);
    const j = await r.json();
    if (!j.ok) { alert(j.error || 'Gagal load'); return; }
    const d = j.row;
    document.getElementById('f_key').value      = d.feature_key;
    document.getElementById('f_key').disabled   = true; // jangan ubah key kalau sudah dipakai
    document.getElementById('f_nama').value     = d.nama_fitur;
    document.getElementById('f_kategori').value = d.kategori;
    document.getElementById('f_harga').value    = d.harga_coin;
    document.getElementById('f_min').value      = d.harga_minimum;
    document.getElementById('f_limit').value    = d.daily_limit || 0;
    document.getElementById('f_desk').value     = d.deskripsi || '';
    document.getElementById('f_catatan').value  = d.catatan_internal || '';
    document.getElementById('f_alasan').value   = '';
    document.querySelector(`input[name="f_active"][value="${d.is_active}"]`).checked = true;
  } else {
    // Reset
    document.getElementById('f_key').value      = '';
    document.getElementById('f_key').disabled   = false;
    document.getElementById('f_nama').value     = '';
    document.getElementById('f_kategori').value = 'lainnya';
    document.getElementById('f_harga').value    = 0;
    document.getElementById('f_min').value      = 0;
    document.getElementById('f_limit').value    = 0;
    document.getElementById('f_desk').value     = '';
    document.getElementById('f_catatan').value  = '';
    document.getElementById('f_alasan').value   = '';
    document.querySelector('input[name="f_active"][value="1"]').checked = true;
  }
  document.getElementById('editModal').classList.add('open');
}

function closeEditModal() {
  document.getElementById('editModal').classList.remove('open');
}

async function saveFitur() {
  const a = document.getElementById('editAlert');
  a.innerHTML = '';
  const data = {
    id:               document.getElementById('f_id').value,
    feature_key:      document.getElementById('f_key').value,
    nama_fitur:       document.getElementById('f_nama').value,
    kategori:         document.getElementById('f_kategori').value,
    harga_coin:       document.getElementById('f_harga').value,
    harga_minimum:    document.getElementById('f_min').value,
    daily_limit:      document.getElementById('f_limit').value,
    deskripsi:        document.getElementById('f_desk').value,
    catatan_internal: document.getElementById('f_catatan').value,
    alasan:           document.getElementById('f_alasan').value,
    is_active:        document.querySelector('input[name="f_active"]:checked').value,
  };

  const r = await fetch('/superadmin/coin_pricing.php?action=save', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
    body: JSON.stringify(data),
  });
  const j = await r.json();
  if (j.error) { a.innerHTML = `<div class="alert-msg error">${esc(j.error)}</div>`; return; }
  a.innerHTML = `<div class="alert-msg success">✓ ${esc(j.msg || 'Tersimpan')}</div>`;
  setTimeout(() => { closeEditModal(); loadPricing(); }, 700);
}

async function quickToggle(id, newVal) {
  if (!await lmConfirm('Yakin ' + (newVal ? 'aktifkan' : 'NONAKTIFKAN') + ' fitur ini?\n\nKalau dinonaktifkan, fitur tidak akan bisa dipakai semua tenant.')) return;
  const r = await fetch('/superadmin/coin_pricing.php?action=toggle', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
    body: JSON.stringify({ id, value: newVal }),
  });
  const j = await r.json();
  if (j.error) { alert(j.error); return; }
  loadPricing();
}

loadPricing();
</script>
</body>
</html>

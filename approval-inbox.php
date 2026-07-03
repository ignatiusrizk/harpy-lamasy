<?php
// ══════════════════════════════════════════════════════
// approval-inbox.php — Action Queue / Approval Inbox
//
// Inspired by Smartlink "206 Tindakan butuh konfirmasi anda".
// Centralize semua pending approval owner ke satu inbox:
// - Permintaan hapus transaksi/kas/pelanggan
// - (extensible: refund, top-up, void payment, dll)
// ══════════════════════════════════════════════════════
$activePage = 'approval-inbox';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once ROOT . '/core/DeleteRequest.php';
require_once ROOT . '/core/DepositManager.php';
require_once __DIR__ . '/components.php';
$user = currentUser();
// Owner-only — approval is owner's responsibility
requirePermission('owner');

$action = $_GET['action'] ?? '';
if ($action === '') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}
if ($action) {
    header('Content-Type: application/json');
    $tid = TenantResolver::id();
    $db  = Database::get();

    if ($action === 'list') {
        $status = $_GET['status'] ?? 'pending';
        if (!in_array($status, ['pending','approved','rejected'], true)) $status = 'pending';
        try {
            $st = $db->prepare(
                "SELECT r.*,
                        u_req.nama AS requester_nama,
                        u_rev.nama AS reviewer_nama
                   FROM hl_delete_request r
                   LEFT JOIN hl_users u_req ON u_req.id = r.requested_by AND u_req.tenant_id = r.tenant_id
                   LEFT JOIN hl_users u_rev ON u_rev.id = r.reviewed_by AND u_rev.tenant_id = r.tenant_id
                  WHERE r.tenant_id = ? AND r.status = ?
                  ORDER BY r.requested_at DESC
                  LIMIT 500"
            );
            $st->execute([$tid, $status]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            // Enrich dengan info entity (mis. no_order utk transaksi)
            foreach ($rows as &$r) {
                $snap = json_decode($r['entity_snapshot'] ?? 'null', true);
                $r['source'] = 'delete';
                $r['entity_label'] = match($r['entity_type']) {
                    'transaksi' => $snap['no_order'] ?? "#{$r['entity_id']}",
                    'kas'       => 'Kas #' . $r['entity_id'] . ($snap ? ' — Rp ' . number_format((float)($snap['jumlah'] ?? 0), 0, ',', '.') : ''),
                    'pelanggan' => $snap['nama'] ?? "#{$r['entity_id']}",
                    default     => "#{$r['entity_id']}",
                };
                $r['entity_extra'] = match($r['entity_type']) {
                    'transaksi' => 'Total Rp ' . number_format((float)($snap['total'] ?? 0), 0, ',', '.') . ' · ' . ($snap['nama_pelanggan'] ?? '-'),
                    'kas'       => ($snap['keterangan'] ?? '-'),
                    'pelanggan' => ($snap['telepon'] ?? '-'),
                    default     => '',
                };
            }
            unset($r);

            // Append refund requests (sama status filter)
            // Map: pending=pending, approved=approved+executed, rejected=rejected
            $refundStatus = $status === 'approved' ? "('approved','executed')" : "('$status')";
            try {
                $st2 = $db->prepare(
                    "SELECT r.id, r.entity_type AS dummy, r.pelanggan_id AS entity_id,
                            r.alasan, r.requested_by, r.requested_at, r.status, r.reviewed_by, r.reviewed_at, r.review_note,
                            r.jumlah_refund, r.metode_refund, r.saldo_sebelum,
                            p.nama AS pelanggan_nama, p.telepon AS pelanggan_telp,
                            u_req.nama AS requester_nama, u_rev.nama AS reviewer_nama
                       FROM hl_deposit_refund r
                       LEFT JOIN hl_pelanggan p ON p.id = r.pelanggan_id AND p.tenant_id = r.tenant_id
                       LEFT JOIN hl_users u_req ON u_req.id = r.requested_by AND u_req.tenant_id = r.tenant_id
                       LEFT JOIN hl_users u_rev ON u_rev.id = r.reviewed_by AND u_rev.tenant_id = r.tenant_id
                      WHERE r.tenant_id = ? AND r.status IN $refundStatus
                      ORDER BY r.requested_at DESC LIMIT 500"
                );
                $st2->execute([$tid]);
                $refunds = $st2->fetchAll(PDO::FETCH_ASSOC);
                foreach ($refunds as &$rf) {
                    $rf['source']        = 'refund';
                    $rf['entity_type']   = 'refund';
                    $rf['entity_label']  = 'Refund Saldo: ' . ($rf['pelanggan_nama'] ?? '#'.$rf['entity_id']);
                    $rf['entity_extra']  = 'Rp ' . number_format((float)$rf['jumlah_refund'], 0, ',', '.') . ' · via ' . strtoupper($rf['metode_refund']);
                    $rf['status'] = $rf['status'] === 'executed' ? 'approved' : $rf['status'];
                    $rows[] = $rf;
                }
            } catch (Throwable) { /* tabel belum ada → skip */ }

            // Sort all by requested_at desc
            usort($rows, fn($a, $b) => strcmp($b['requested_at'] ?? '', $a['requested_at'] ?? ''));

            echo json_encode(['rows' => $rows, 'count' => count($rows)]);
        } catch (Throwable $e) {
            echo json_encode(['error' => 'Tabel belum ada. Run delete_request_migration.sql', 'rows' => []]);
        }
        exit;
    }

    if ($action === 'approve' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $reqId  = (int)($d['id'] ?? 0);
        $source = (string)($d['source'] ?? 'delete');
        $note   = (string)($d['note'] ?? '');
        $err = $source === 'refund'
            ? DepositManager::approveRefund($tid, $reqId, (int)$user['id'], $note)
            : DeleteRequest::approve($reqId, (int)$user['id'], $note);
        echo json_encode($err ? ['error'=>$err] : ['success'=>true]);
        exit;
    }

    if ($action === 'reject' && $_SERVER['REQUEST_METHOD']==='POST') {
        verifyCsrf();
        $d = json_decode(file_get_contents('php://input'), true);
        $reqId  = (int)($d['id'] ?? 0);
        $source = (string)($d['source'] ?? 'delete');
        $note   = (string)($d['note'] ?? '');
        $err = $source === 'refund'
            ? DepositManager::rejectRefund($tid, $reqId, (int)$user['id'], $note)
            : DeleteRequest::reject($reqId, (int)$user['id'], $note);
        echo json_encode($err ? ['error'=>$err] : ['success'=>true]);
        exit;
    }

    if ($action === 'count') {
        echo json_encode([
            'pending' => DeleteRequest::pendingCount($tid) + DepositManager::pendingRefundCount($tid),
        ]);
        exit;
    }

    exit;
}

// Badge awal harus cocok dgn API count & isi list (delete request + refund deposit),
// biar tak salah tampil kalau AJAX refresh gagal.
$pendingCount = DeleteRequest::pendingCount(TenantResolver::id())
              + DepositManager::pendingRefundCount(TenantResolver::id());
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Approval Inbox'); ?>
</head>
<body>
<?php renderTopbar('approval-inbox'); ?>

<div class="hl-main">
  <div class="inbox-hint">
    💡 <strong>Permintaan hapus dari kasir</strong> (transaksi/kas/pelanggan) menunggu keputusanmu.
    <span class="inbox-hint-more">Approve = data dihapus permanen · Reject = data tetap ada.</span>
  </div>

  <!-- Tabs status -->
  <div class="inbox-tabs">
    <button class="tab-btn active" data-status="pending" onclick="switchTab('pending')">
      ⏳ Menunggu <span class="tab-count" id="cntPending"><?= $pendingCount ?></span>
    </button>
    <button class="tab-btn" data-status="approved" onclick="switchTab('approved')">
      ✅ Disetujui
    </button>
    <button class="tab-btn" data-status="rejected" onclick="switchTab('rejected')">
      ❌ Ditolak
    </button>
  </div>

  <div id="inboxList" style="min-height:200px">⏳ Memuat...</div>
</div>

<style>
.inbox-hint{background:#FEF3C7;border:1px solid #FDE68A;border-radius:10px;padding:12px 14px;margin-bottom:16px;font-size:12.5px;color:#92400E;line-height:1.5}
.inbox-hint-more{display:block;margin-top:3px;font-size:11.5px;color:#B45309}
.inbox-tabs{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));margin-bottom:14px;border-bottom:2px solid #E5E7EB}
.tab-btn{background:none;border:none;padding:10px 4px;font-size:13px;color:#6B7280;cursor:pointer;border-bottom:3px solid transparent;font-weight:600;white-space:nowrap;text-align:center;-webkit-tap-highlight-color:transparent;outline:none;-webkit-appearance:none}
.tab-btn.active{color:#0F7B6C;border-bottom-color:#0F7B6C}
.tab-count{display:inline-block;background:#DC2626;color:white;border-radius:100px;padding:1px 7px;font-size:11px;margin-left:3px;font-weight:700}
.tab-btn.active .tab-count{background:#0F7B6C}
.req-card{border:1px solid #E5E7EB;border-radius:10px;padding:14px 16px;margin-bottom:10px;background:#fff}
.req-card.pending{border-left:4px solid #F59E0B}
.req-card.approved{border-left:4px solid #10B981;background:#F0FDF4}
.req-card.rejected{border-left:4px solid #DC2626;background:#FEF2F2}
.req-meta{font-size:11px;color:#9CA3AF;margin-top:6px}
.req-alasan{background:#F9FAFB;border:1px solid #E5E7EB;border-radius:6px;padding:8px 12px;margin:8px 0;font-size:12.5px;color:#4B5563}
</style>

<?php renderToast(); ?>
<script>
let currentStatus = 'pending';
const fmt = n => Number(n||0).toLocaleString('id-ID');
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

function fmtDate(d){
  if (!d) return '-';
  const dt = new Date(d);
  if (isNaN(dt)) return d;
  return dt.toLocaleString('id-ID', {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
}

async function loadInbox() {
  const list = document.getElementById('inboxList');
  list.innerHTML = '<div style="text-align:center;padding:40px;color:var(--gray)">⏳ Memuat...</div>';
  const r = await fetch('?action=list&status=' + currentStatus);
  const d = await r.json();
  if (d.error) { list.innerHTML = `<div style="text-align:center;padding:40px;color:#DC2626">${esc(d.error)}</div>`; return; }
  renderInbox(d.rows || []);
  // Update pending badge
  if (currentStatus === 'pending') document.getElementById('cntPending').textContent = (d.rows||[]).length;
}

function entityIcon(type) {
  return {'transaksi':'📋','kas':'💰','pelanggan':'👤','refund':'↩️'}[type] || '📌';
}
function entityLabel(type) {
  return {'transaksi':'Hapus Transaksi','kas':'Hapus Kas','pelanggan':'Nonaktif Pelanggan','refund':'Refund Saldo'}[type] || type;
}

function renderInbox(rows) {
  const list = document.getElementById('inboxList');
  if (!rows.length) {
    const msg = {'pending':'🎉 Tidak ada permintaan menunggu approval','approved':'Belum ada yang disetujui','rejected':'Belum ada yang ditolak'};
    list.innerHTML = `<div style="text-align:center;padding:60px;color:var(--gray);font-size:14px">${msg[currentStatus]}</div>`;
    return;
  }
  list.innerHTML = rows.map(r => `
    <div class="req-card ${r.status}">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
        <div style="flex:1;min-width:200px">
          <div style="font-size:13px;color:#6B7280">${entityIcon(r.entity_type)} ${entityLabel(r.entity_type)}</div>
          <div style="font-size:15px;font-weight:700;color:#111827">${esc(r.entity_label)}</div>
          <div style="font-size:12px;color:#6B7280;margin-top:2px">${esc(r.entity_extra)}</div>
          <div class="req-alasan"><strong>Alasan hapus:</strong> ${esc(r.alasan)}</div>
          <div class="req-meta">
            Diajukan oleh <strong>${esc(r.requester_nama||'-')}</strong> · ${fmtDate(r.requested_at)}
            ${r.reviewed_at ? `<br>Direview oleh <strong>${esc(r.reviewer_nama||'-')}</strong> · ${fmtDate(r.reviewed_at)}${r.review_note?` · "${esc(r.review_note)}"`:''}` : ''}
          </div>
        </div>
        ${r.status === 'pending' ? `
        <div style="display:flex;gap:6px;flex-shrink:0">
          <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="doReject(${r.id}, '${esc(r.source||'delete')}')">❌ Tolak</button>
          <button class="hl-btn hl-btn-danger hl-btn-sm" onclick="doApprove(${r.id}, '${esc(r.source||'delete')}')">${r.source==='refund'?'✅ Approve & Refund':'✅ Approve & Hapus'}</button>
        </div>` : ''}
      </div>
    </div>`).join('');
}

function switchTab(status) {
  currentStatus = status;
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b.dataset.status === status));
  loadInbox();
}

async function doApprove(id, source) {
  const msg = source === 'refund'
    ? 'Approve & potong saldo customer? Cash harus diberikan ke customer.'
    : 'Approve & hapus permanen? Aksi tidak bisa di-undo.';
  if (!await lmConfirm(msg)) return;
  const note = await lmPrompt('Catatan (opsional):') || '';
  const r = await fetch('?action=approve', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({id, source, note})
  });
  const d = await r.json();
  if (d.error) { showToast(d.error, 'error'); return; }
  showToast(source === 'refund' ? 'Refund disetujui & saldo dipotong' : 'Disetujui & dihapus', 'success');
  loadInbox();
}

async function doReject(id, source) {
  const note = await lmPrompt('Alasan ditolak (wajib):');
  if (!note || note.trim().length < 3) { showToast('Alasan minimal 3 karakter', 'error'); return; }
  const r = await fetch('?action=reject', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken()},
    body: JSON.stringify({id, source, note: note.trim()})
  });
  const d = await r.json();
  if (d.error) { showToast(d.error, 'error'); return; }
  showToast('Ditolak', 'success');
  loadInbox();
}

document.addEventListener('DOMContentLoaded', loadInbox);
</script>

</body>
</html>

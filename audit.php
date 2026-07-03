<?php
$activePage = 'audit';
define('ROOT', __DIR__);
require_once ROOT . '/middleware/tenant_guard.php';
require_once __DIR__ . '/components.php';
$user = currentUser();
requirePermission('audit.view');

$action = $_GET['action'] ?? '';
if ($action === '') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}
if ($action) {
    header('Content-Type: application/json');
    $tid = TenantResolver::id();

    if ($action === 'list') {
        $q      = $_GET['q']      ?? '';
        $modul  = $_GET['modul']  ?? '';
        $userId = $_GET['user_id']?? '';
        $dari   = $_GET['dari']   ?? '';
        $sampai = $_GET['sampai'] ?? '';
        $page   = max(1, intval($_GET['page'] ?? 1));
        $limit  = 50;
        $offset = ($page - 1) * $limit;

        $where = ['tenant_id = ?']; $params = [$tid];
        if ($q)      { $where[] = '(aksi LIKE ? OR keterangan LIKE ? OR user_nama LIKE ?)'; $like="%$q%"; $params=array_merge($params,[$like,$like,$like]); }
        if ($modul)  { $where[] = 'modul=?';   $params[] = $modul; }
        if ($userId) { $where[] = 'user_id=?'; $params[] = $userId; }
        if ($dari)   { $where[] = 'DATE(created_at)>=?'; $params[] = $dari; }
        if ($sampai) { $where[] = 'DATE(created_at)<=?'; $params[] = $sampai; }

        $whereStr = implode(' AND ', $where);
        $rows  = TenantQuery::raw("SELECT * FROM hl_audit_log WHERE $whereStr ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}", $params);
        $count = TenantQuery::raw("SELECT COUNT(*) as c FROM hl_audit_log WHERE $whereStr", $params);
        $total = intval($count[0]['c'] ?? 0);

        echo json_encode(['data'=>$rows,'total'=>$total,'page'=>$page,'total_pages'=>ceil($total/$limit)]);
        exit;
    }

    if ($action === 'stats') {
        $today = date('Y-m-d');
        $total  = TenantQuery::count('hl_audit_log');
        $hari   = TenantQuery::count('hl_audit_log', "DATE(created_at)=?", [$today]);
        $users  = TenantQuery::raw("SELECT COUNT(DISTINCT user_id) as c FROM hl_audit_log WHERE tenant_id=? AND DATE(created_at)=?", [$tid, $today]);
        $moduls = TenantQuery::raw("SELECT modul, COUNT(*) as c FROM hl_audit_log WHERE tenant_id=? GROUP BY modul ORDER BY c DESC", [$tid]);
        echo json_encode(['total'=>$total,'hari'=>$hari,'users'=>intval($users[0]['c']??0),'moduls'=>$moduls]); exit;
    }

    if ($action === 'users') {
        $rows = TenantQuery::raw(
            "SELECT DISTINCT user_id, user_nama FROM hl_audit_log WHERE tenant_id=? AND user_id IS NOT NULL ORDER BY user_nama",
            [$tid]
        );
        echo json_encode($rows); exit;
    }

    echo json_encode(['error'=>'Unknown']); exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php renderHead('Audit Log'); ?>
<style>
.aksi-badge{display:inline-block;font-size:10px;font-weight:700;padding:2px 8px;border-radius:100px;white-space:nowrap;letter-spacing:.04em}
.aksi-create{background:#D1FAE5;color:#065F46}
.aksi-update{background:#DBEAFE;color:#1D4ED8}
.aksi-update_status{background:#EDE9FE;color:#5B21B6}
.aksi-delete{background:#FEE2E2;color:#991B1B}
.aksi-payment{background:#FEF3C7;color:#92400E}
.aksi-login{background:#D1FAE5;color:#065F46}
.aksi-logout{background:#F3F4F6;color:#374151}
.aksi-generate_gaji{background:#FEF3C7;color:#92400E}
.aksi-bayar_gaji{background:#FEE2E2;color:#991B1B}
.aksi-update_permission{background:#EDE9FE;color:#5B21B6}
.aksi-default{background:var(--light);color:var(--gray)}
.modul-badge{display:inline-block;font-size:10px;font-weight:600;padding:2px 7px;border-radius:6px;background:var(--light);color:var(--gray)}
.log-time{font-family:var(--mono);font-size:11px;color:var(--gray);white-space:nowrap}
.log-ket{font-size:12px;color:var(--gray);max-width:300px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media(max-width:680px){
  .hl-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
  .hl-table thead th{font-size:11px;padding:8px 8px}
  .hl-table tbody td{font-size:12px;padding:8px 8px}
  .log-ket{max-width:none;white-space:normal !important}
}
/* Kontrol custom filter (ganti select/date native) */
.lm-cust{display:none!important}
.lmui-trg{padding:9px 12px;border:1px solid rgba(27,45,90,.14);border-radius:9px;font-family:inherit;font-size:14px;background:#fff;text-align:left;display:inline-flex;align-items:center;justify-content:space-between;gap:8px;cursor:pointer;color:var(--navy);font-weight:600;min-width:150px}
.lmui-trg .lmui-lbl{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.lmui-trg .lmui-car{color:var(--gray);font-size:12px;flex:0 0 auto}
.lmui-trg.ph .lmui-lbl{color:var(--gray)}
.lmui-pop{position:fixed;background:#fff;border:1px solid rgba(27,45,90,.12);border-radius:10px;box-shadow:0 12px 32px rgba(15,28,58,.16);z-index:9000;max-height:280px;overflow-y:auto;padding:6px}
.lmui-opt{display:block;width:100%;text-align:left;padding:10px 12px;border:0;background:none;font-family:inherit;font-size:14px;border-radius:7px;cursor:pointer;color:var(--navy);font-weight:600}
.lmui-opt:hover{background:var(--off,#F1F5FB)}
.lmui-opt.sel{background:#E8F0FE;color:#1E40AF;font-weight:700}
.lm-date{position:relative;display:inline-block}
.lm-date-btn{display:inline-flex;align-items:center;justify-content:space-between;gap:10px;min-width:150px;padding:9px 12px;border:1px solid rgba(27,45,90,.14);border-radius:9px;background:#fff;color:var(--navy);font-size:14px;font-weight:600;font-family:inherit;cursor:pointer}
.lm-cal{position:fixed;z-index:9001;background:#fff;border:1px solid rgba(27,45,90,.12);border-radius:12px;box-shadow:0 12px 34px rgba(15,28,58,.18);padding:12px;width:264px}
.lm-cal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.lm-cal-head button{border:none;background:var(--off,#F1F5FB);width:30px;height:30px;border-radius:8px;cursor:pointer;font-size:15px;color:var(--navy)}
.lm-cal-title{font-weight:800;font-size:14px;color:var(--navy)}
.lm-cal-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:2px}
.lm-cal-dow{font-size:10px;color:var(--gray);text-align:center;font-weight:700;padding:4px 0}
.lm-cal-day{border:none;background:none;height:32px;border-radius:8px;font-size:13px;color:var(--navy);cursor:pointer;font-family:inherit}
.lm-cal-day:hover{background:var(--off,#F1F5FB)}
.lm-cal-day.today{outline:1.5px solid var(--teal)}
.lm-cal-day.sel{background:var(--navy);color:#fff;font-weight:800}
.lm-cal-day.empty{visibility:hidden}
@media(max-width:680px){
  .hl-filter-bar .lmui-trg,.hl-filter-bar .lm-date,.hl-filter-bar .lm-date-btn{width:100%!important}
  #fSearch{max-width:none!important;flex:1 1 100%!important}
}
</style>
</head>
<body>
<?php renderTopbar('audit'); ?>
<div class="hl-main">

  <div class="hl-stat-grid-4" style="margin-bottom:20px">
    <div class="hl-stat-card teal"><div class="hl-stat-num" id="sTotal">-</div><div class="hl-stat-label">Total Log</div></div>
    <div class="hl-stat-card green"><div class="hl-stat-num" id="sHari">-</div><div class="hl-stat-label">Aktivitas Hari Ini</div></div>
    <div class="hl-stat-card navy"><div class="hl-stat-num" id="sUsers">-</div><div class="hl-stat-label">User Aktif Hari Ini</div></div>
    <div class="hl-stat-card purple">
      <div class="hl-stat-num" id="sTopModul" style="font-size:1.1rem;font-family:var(--font);text-transform:capitalize;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">-</div>
      <div class="hl-stat-label">Modul Tersibuk</div>
    </div>
  </div>

  <div class="hl-filter-collapsible">
    <button class="hl-filter-toggle-btn" id="auditFilterBtn" onclick="toggleFilter('auditFilter')">
      🔍 Filter Log <span class="hl-filter-active-dot" id="auditFilterDot"></span>
      <span class="hl-toggle-arrow">▼</span>
    </button>
    <div class="hl-filter-bar" id="auditFilter">
      <input type="text" id="fSearch" class="hl-input" placeholder="Cari aksi, keterangan, user..."
        oninput="debounce()" style="flex:1;max-width:280px"/>
      <select id="fModul" class="hl-input lm-cust" style="width:auto" onchange="loadLog(1)">
        <option value="">Semua Modul</option>
        <option value="orders">Orders</option>
        <option value="kas">Kas</option>
        <option value="customer">Customer</option>
        <option value="karyawan">Karyawan</option>
        <option value="layanan">Layanan</option>
        <option value="settings">Settings</option>
        <option value="auth">Auth (Login)</option>
      </select>
      <select id="fUser" class="hl-input lm-cust" style="width:auto" onchange="loadLog(1)">
        <option value="">Semua User</option>
      </select>
      <div class="lm-date"><button type="button" class="lm-date-btn" onclick="lmDateOpen('fDari',this)"><span class="lm-date-txt">Dari tanggal</span> <span>📅</span></button><input type="hidden" id="fDari" onchange="loadLog(1)"></div>
      <div class="lm-date"><button type="button" class="lm-date-btn" onclick="lmDateOpen('fSampai',this)"><span class="lm-date-txt">Sampai tanggal</span> <span>📅</span></button><input type="hidden" id="fSampai" onchange="loadLog(1)"></div>
      <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="resetFilter()">✕ Reset</button>
      <button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadLog(1)">↻</button>
    </div>
  </div>

  <div class="hl-card">
    <div class="hl-card-header">
      <div class="hl-card-title">📋 Riwayat Aktivitas</div>
      <span id="logInfo" style="font-size:12px;color:var(--gray)"></span>
    </div>
    <div class="hl-table-wrap">
      <table class="hl-table hl-stack-mobile">
        <thead>
          <tr>
            <th>Waktu</th>
            <th>User</th>
            <th>Role</th>
            <th>Modul</th>
            <th>Aksi</th>
            <th>Keterangan</th>
            <th>Ref</th>
            <th>IP</th>
          </tr>
        </thead>
        <tbody id="logBody">
          <tr><td colspan="8" class="hl-loading">⏳ Memuat...</td></tr>
        </tbody>
      </table>
    </div>
    <div id="logPaging" style="padding:12px 16px;border-top:1px solid var(--light)"></div>
  </div>

</div>
<?php renderToast(); ?>
<script>
let searchTimer = null;
let currentPage = 1;

document.addEventListener('DOMContentLoaded', () => {
  initFilter('auditFilter');
  lmEnhanceFilters();
  const today = localDateStr();
  lmDateSet('fDari', today);
  lmDateSet('fSampai', today);
  loadStats();
  loadUsers();
  loadLog(1);
});

function localDateStr(d) {
  const dt = d || new Date();
  return dt.getFullYear() + '-' + String(dt.getMonth()+1).padStart(2,'0') + '-' + String(dt.getDate()).padStart(2,'0');
}

async function loadStats() {
  const r = await fetch('audit.php?action=stats');
  const d = await r.json();
  document.getElementById('sTotal').textContent    = parseInt(d.total).toLocaleString('id-ID');
  document.getElementById('sHari').textContent     = d.hari;
  document.getElementById('sUsers').textContent    = d.users;
  document.getElementById('sTopModul').textContent = d.moduls[0]?.modul || '-';
}

async function loadUsers() {
  const r = await fetch('audit.php?action=users');
  const d = await r.json();
  const sel = document.getElementById('fUser');
  sel.innerHTML = '<option value="">Semua User</option>' +
    d.map(u => `<option value="${u.user_id}">${esc(u.user_nama)}</option>`).join('');
  lmSyncSel('fUser');
}

async function loadLog(page=1) {
  currentPage = page;
  const q      = document.getElementById('fSearch').value;
  const modul  = document.getElementById('fModul').value;
  const userId = document.getElementById('fUser').value;
  const dari   = document.getElementById('fDari').value;
  const sampai = document.getElementById('fSampai').value;

  document.getElementById('logBody').innerHTML = Array.from({length:6}).map(()=>`
    <tr><td colspan="8" style="padding:0;border-bottom:1px solid var(--light)">
      <div class="hl-skel-row" style="padding:11px 14px">
        <span class="hl-skel" style="width:90px"></span>
        <span class="hl-skel" style="width:110px"></span>
        <span class="hl-skel" style="width:80px"></span>
        <span class="hl-skel" style="width:200px;margin-left:auto"></span>
      </div></td></tr>`).join('');

  const r = await fetch(`audit.php?action=list&q=${encodeURIComponent(q)}&modul=${modul}&user_id=${userId}&dari=${dari}&sampai=${sampai}&page=${page}`);
  const d = await r.json();

  if (!d.data?.length) {
    document.getElementById('logBody').innerHTML = `<tr><td colspan="8" style="padding:0">
      <div class="hl-empty-v2" style="margin:14px;background:transparent;border:0">
        <div class="e-icon">🔍</div>
        <div class="e-title">Tidak ada log</div>
        <div class="e-sub">Coba ubah filter atau periode pencarian</div>
      </div></td></tr>`;
    document.getElementById('logPaging').innerHTML = '';
    document.getElementById('logInfo').textContent = '';
    return;
  }

  const aksiColor = {
    create:'aksi-create', update:'aksi-update', update_status:'aksi-update_status',
    delete:'aksi-delete', payment:'aksi-payment', login:'aksi-login',
    logout:'aksi-logout', generate_gaji:'aksi-generate_gaji', bayar_gaji:'aksi-bayar_gaji',
    update_permission:'aksi-update_permission', edit_gaji:'aksi-update',
  };

  document.getElementById('logBody').innerHTML = d.data.map(row => `
    <tr>
      <td data-lbl="Waktu" class="log-time">${fmtDateTime(row.created_at)}</td>
      <td data-lbl="User" style="font-weight:600;font-size:13px;color:var(--navy)">${esc(row.user_nama||'-')}</td>
      <td data-lbl="Role"><span class="modul-badge">${esc(row.user_role||'-')}</span></td>
      <td data-lbl="Modul"><span class="modul-badge" style="background:var(--teal-bg);color:var(--teal-d)">${esc(row.modul)}</span></td>
      <td data-lbl="Aksi"><span class="aksi-badge ${aksiColor[row.aksi]||'aksi-default'}">${esc(row.aksi)}</span></td>
      <td data-lbl="Keterangan" class="log-ket" title="${esc(row.keterangan||'')}">${esc(row.keterangan||'-')}</td>
      <td data-lbl="Ref" style="font-family:var(--mono);font-size:11px;color:var(--teal-d)">${esc(row.ref_id||'-')}</td>
      <td data-lbl="IP" style="font-size:11px;color:var(--gray)">${esc(row.ip_address||'-')}</td>
    </tr>`).join('');

  document.getElementById('logInfo').textContent = `${d.total.toLocaleString('id-ID')} aktivitas · hal ${page}/${d.total_pages}`;
  renderPaging(page, d.total_pages);
}

function renderPaging(page, total) {
  const el = document.getElementById('logPaging');
  if (total <= 1) { el.innerHTML=''; return; }
  let html = '<div style="display:flex;align-items:center;justify-content:center;gap:6px;flex-wrap:wrap">';
  html += `<button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadLog(${page-1})" ${page===1?'disabled':''}>← Prev</button>`;
  const start=Math.max(1,page-2), end=Math.min(total,page+2);
  if(start>1) html+=`<button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadLog(1)">1</button>`;
  if(start>2) html+=`<span style="color:var(--gray)">...</span>`;
  for(let i=start;i<=end;i++) html+=`<button class="hl-btn ${i===page?'hl-btn-primary':'hl-btn-outline'} hl-btn-sm" onclick="loadLog(${i})">${i}</button>`;
  if(end<total-1) html+=`<span style="color:var(--gray)">...</span>`;
  if(end<total) html+=`<button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadLog(${total})">${total}</button>`;
  html += `<button class="hl-btn hl-btn-outline hl-btn-sm" onclick="loadLog(${page+1})" ${page===total?'disabled':''}>Next →</button>`;
  html += '</div>';
  el.innerHTML = html;
}

function resetFilter() {
  document.getElementById('fSearch').value  = '';
  document.getElementById('fModul').value   = '';
  document.getElementById('fUser').value    = '';
  lmSyncSel('fModul','fUser');
  lmDateSet('fDari', localDateStr());
  lmDateSet('fSampai', localDateStr());
  loadLog(1);
}

function debounce(){ clearTimeout(searchTimer); searchTimer=setTimeout(()=>loadLog(1),400); }
function fmtDateTime(d){if(!d)return'-';const dt=new Date(d);return dt.toLocaleDateString('id-ID',{day:'2-digit',month:'short'})+' '+dt.toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit',second:'2-digit'});}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}

/* ── Dropdown custom (ganti select native) — pola sama piutang/layanan ── */
let _pop=null,_popAnchor=null;
function _closePop(){ if(_pop){_pop.remove();_pop=null;} _popAnchor=null;
  document.removeEventListener('mousedown',_onOutside,true);
  window.removeEventListener('scroll',_closePop,true); window.removeEventListener('resize',_closePop); }
function _onOutside(e){ if(e.target.closest('.lmui-pop')||e.target.closest('.lmui-trg')) return; _closePop(); }
function _placePop(a){ const r=a.getBoundingClientRect(); _pop.style.left=r.left+'px'; _pop.style.minWidth=r.width+'px';
  const ph=_pop.offsetHeight; let top=r.bottom+4; if(top+ph>window.innerHeight-8) top=Math.max(8,r.top-ph-4); _pop.style.top=top+'px';
  const pw=_pop.offsetWidth; if(r.left+pw>window.innerWidth-8) _pop.style.left=Math.max(8,window.innerWidth-pw-8)+'px'; }
function _initSel(sel){
  sel.classList.remove('lm-cust'); sel.style.display='none';
  const trg=document.createElement('button'); trg.type='button'; trg.className='lmui-trg';
  trg.innerHTML='<span class="lmui-lbl"></span><span class="lmui-car">▾</span>';
  const lbl=trg.querySelector('.lmui-lbl');
  const sync=()=>{ const o=sel.options[sel.selectedIndex]; lbl.textContent=o?o.textContent:'—'; trg.classList.toggle('ph',!sel.value); };
  sel._lmSync=sync; sel.addEventListener('change',sync);
  trg.onclick=()=>{ if(_popAnchor===trg){_closePop();return;} _closePop();
    _pop=document.createElement('div'); _pop.className='lmui-pop';
    _pop.innerHTML=Array.from(sel.options).map((o,i)=>`<button type="button" class="lmui-opt${i===sel.selectedIndex?' sel':''}" data-i="${i}">${esc(o.textContent)}</button>`).join('');
    _pop.onclick=e=>{ const b=e.target.closest('.lmui-opt'); if(!b) return; sel.selectedIndex=+b.dataset.i; sel.dispatchEvent(new Event('change')); _closePop(); };
    document.body.appendChild(_pop); _popAnchor=trg; _placePop(trg);
    document.addEventListener('mousedown',_onOutside,true);
    window.addEventListener('scroll',_closePop,true); window.addEventListener('resize',_closePop); };
  sel.after(trg); sync();
}
function lmSyncSel(...ids){ ids.forEach(id=>{ const el=document.getElementById(id); if(el&&el._lmSync) el._lmSync(); }); }
function lmEnhanceFilters(){ document.querySelectorAll('select.lm-cust').forEach(_initSel); }

/* ── Date picker: pola laporan.php (terbukti jalan) ── */
function lmFmtDMY(v){ if(!v) return 'Pilih tanggal'; const p=v.split('-'); const mo=['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des']; return (+p[2])+' '+mo[(+p[1])-1]+' '+p[0]; }
function lmDateSet(id,val){ const h=document.getElementById(id); if(!h) return; h.value=val||''; const w=h.closest('.lm-date'); if(w){ const t=w.querySelector('.lm-date-txt'); if(t) t.textContent=lmFmtDMY(val); } }
let _lmCalFor=null;
function lmCalClose(){ document.querySelectorAll('.lm-cal').forEach(c=>c.remove()); _lmCalFor=null; }
function lmDateOpen(id,btn){
  if(_lmCalFor===id){ lmCalClose(); return; }
  lmCalClose(); _lmCalFor=id;
  const cur=(document.getElementById(id).value)||localDateStr(); const [y,m]=cur.split('-').map(Number);
  const cal=document.createElement('div'); cal.className='lm-cal'; document.body.appendChild(cal);
  const r=btn.getBoundingClientRect(); const estH=300;
  cal.style.top=Math.max(8,(r.bottom+estH>window.innerHeight-8 ? r.top-estH-6 : r.bottom+6))+'px';
  cal.style.left=Math.max(8,Math.min(r.left,window.innerWidth-272))+'px';
  lmCalRender(cal,id,y,m);
}
function lmCalRender(cal,id,y,m){
  const mo=['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
  const first=new Date(y,m-1,1).getDay(), days=new Date(y,m,0).getDate();
  const today=localDateStr(), sel=document.getElementById(id).value, pad=n=>String(n).padStart(2,'0');
  let h='<div class="lm-cal-head"><button type="button" onclick="lmCalNav(this,\''+id+'\','+y+','+m+',-1)">‹</button><span class="lm-cal-title">'+mo[m-1]+' '+y+'</span><button type="button" onclick="lmCalNav(this,\''+id+'\','+y+','+m+',1)">›</button></div><div class="lm-cal-grid">';
  ['M','S','S','R','K','J','S'].forEach(d=>h+='<div class="lm-cal-dow">'+d+'</div>');
  for(let i=0;i<first;i++) h+='<button class="lm-cal-day empty"></button>';
  for(let d=1;d<=days;d++){ const v=y+'-'+pad(m)+'-'+pad(d); h+='<button type="button" class="lm-cal-day'+(v===sel?' sel':'')+(v===today?' today':'')+'" onclick="lmCalPick(\''+id+'\',\''+v+'\')">'+d+'</button>'; }
  cal.innerHTML=h+'</div>';
}
function lmCalNav(btn,id,y,m,delta){ m+=delta; if(m<1){m=12;y--;} if(m>12){m=1;y++;} lmCalRender(btn.closest('.lm-cal'),id,y,m); }
function lmCalPick(id,v){ lmDateSet(id,v); lmCalClose(); const h=document.getElementById(id); if(h) h.dispatchEvent(new Event('change')); }
document.addEventListener('click', e=>{ if(!e.target.closest('.lm-date') && !e.target.closest('.lm-cal')) lmCalClose(); });
</script>
</body>
</html>

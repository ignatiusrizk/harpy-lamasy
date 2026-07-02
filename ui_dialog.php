<?php /* ui_dialog.php — Dialog cantik global (pengganti alert/confirm/prompt native).
   Dipakai oleh renderToast() (tenant/HQ) & saRenderNavClose() (superadmin).
   API JS global: lmAlert(msg), lmConfirm(msg,opts)→Promise<bool>, lmPrompt(msg,def,opts)→Promise<str|null>,
   lmAsk(event,msg) utk <a>, lmAskSubmit(event,msg) utk <form>. window.alert di-override. */ ?>
    <!-- ══ Dialog cantik global (pengganti alert/confirm/prompt native) ══ -->
    <style>
      .lm-dlg-ov{position:fixed;inset:0;z-index:100000;display:none;align-items:center;justify-content:center;
        padding:20px;background:rgba(15,28,58,.55);-webkit-backdrop-filter:blur(2px);backdrop-filter:blur(2px);opacity:0;transition:opacity .16s ease}
      .lm-dlg-ov.show{display:flex;opacity:1}
      .lm-dlg{background:#fff;border-radius:18px;max-width:380px;width:100%;padding:24px 22px 18px;
        box-shadow:0 18px 50px rgba(15,28,58,.28);text-align:center;transform:scale(.94);opacity:0;transition:transform .18s cubic-bezier(.2,.9,.3,1.2),opacity .18s ease}
      .lm-dlg-ov.show .lm-dlg{transform:scale(1);opacity:1}
      .lm-dlg-icon{width:56px;height:56px;margin:0 auto 12px;border-radius:50%;background:#E8FBF9;
        display:flex;align-items:center;justify-content:center;font-size:28px;line-height:1}
      .lm-dlg-icon.danger{background:#FEE2E2}
      .lm-dlg-title{font-size:17px;font-weight:800;color:#1B2D5A;margin-bottom:6px;line-height:1.3}
      .lm-dlg-msg{font-size:14px;color:#6C7A8D;line-height:1.55;white-space:normal}
      .lm-dlg-cost{display:inline-flex;align-items:center;gap:5px;margin-top:12px;padding:5px 12px;border-radius:999px;
        background:#FEF3C7;color:#92400E;font-size:13px;font-weight:700}
      .lm-dlg-input{width:100%;margin-top:14px;padding:11px 13px;border:1.5px solid #D8DEE8;border-radius:11px;
        font-size:15px;color:#1B2D5A;box-sizing:border-box}
      .lm-dlg-input:focus{outline:none;border-color:#1CC4B2;box-shadow:0 0 0 3px rgba(53,232,213,.18)}
      .lm-dlg-actions{display:flex;gap:10px;margin-top:20px}
      .lm-dlg-btn{flex:1;padding:12px 14px;border-radius:12px;font-size:15px;font-weight:700;cursor:pointer;
        border:none;min-height:46px;transition:filter .12s,background .12s}
      .lm-dlg-btn:active{transform:translateY(1px)}
      .lm-dlg-cancel{background:#EEF1F6;color:#4A5A70}
      .lm-dlg-cancel:hover{background:#E2E7EF}
      .lm-dlg-ok{background:linear-gradient(180deg,#35E8D5,#1CC4B2);color:#0F1C3A}
      .lm-dlg-ok:hover{filter:brightness(1.04)}
      .lm-dlg-ok.danger{background:linear-gradient(180deg,#F87171,#DC2626);color:#fff}
      @media (prefers-reduced-motion:reduce){.lm-dlg-ov,.lm-dlg{transition:none}}
    </style>
    <div class="lm-dlg-ov" id="lmDlgOv" role="dialog" aria-modal="true">
      <div class="lm-dlg">
        <div class="lm-dlg-icon" id="lmDlgIcon">❓</div>
        <div class="lm-dlg-title" id="lmDlgTitle"></div>
        <div class="lm-dlg-msg" id="lmDlgMsg"></div>
        <div class="lm-dlg-cost" id="lmDlgCost" style="display:none"></div>
        <input class="lm-dlg-input" id="lmDlgInput" style="display:none">
        <div class="lm-dlg-actions">
          <button type="button" class="lm-dlg-btn lm-dlg-cancel" id="lmDlgCancel">Batal</button>
          <button type="button" class="lm-dlg-btn lm-dlg-ok" id="lmDlgOk">OK</button>
        </div>
      </div>
    </div>
    <script>
    (function(){
      if(window.lmDialog) return; // sudah ter-load (hindari dobel bila 2 layout ke-include)
      var ov=document.getElementById('lmDlgOv'), icon=document.getElementById('lmDlgIcon'),
          titleEl=document.getElementById('lmDlgTitle'), msgEl=document.getElementById('lmDlgMsg'),
          costEl=document.getElementById('lmDlgCost'), inputEl=document.getElementById('lmDlgInput'),
          okBtn=document.getElementById('lmDlgOk'), cancelBtn=document.getElementById('lmDlgCancel');
      if(!ov) return;
      var resolver=null, mode='confirm';
      function esc(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
      function close(val){
        ov.classList.remove('show');
        var r=resolver; resolver=null;
        document.removeEventListener('keydown',onKey,true);
        if(r) setTimeout(function(){r(val);},120);
      }
      function onKey(e){
        if(!resolver) return;
        if(e.key==='Escape'){ e.preventDefault(); close(mode==='prompt'?null:(mode==='alert'?undefined:false)); }
        else if(e.key==='Enter' && mode!=='prompt'){ e.preventDefault(); okOnce(); }
      }
      function okOnce(){ close(mode==='prompt'?(inputEl.value):(mode==='alert'?undefined:true)); }
      okBtn.addEventListener('click', okOnce);
      cancelBtn.addEventListener('click', function(){ close(mode==='prompt'?null:false); });
      ov.addEventListener('click', function(e){ if(e.target===ov && mode!=='alert') close(mode==='prompt'?null:false); });

      // API utama — return Promise
      window.lmDialog=function(opts){
        opts=opts||{}; mode=opts.type||'confirm';
        var danger=!!opts.danger;
        icon.textContent=opts.icon || (mode==='alert'?'ℹ️':(mode==='prompt'?'✏️':'❓'));
        icon.className='lm-dlg-icon'+(danger?' danger':'');
        titleEl.textContent=opts.title || (mode==='alert'?'Informasi':(mode==='prompt'?'Masukkan Data':'Konfirmasi'));
        titleEl.style.display=opts.title===''?'none':'';
        msgEl.innerHTML=esc(opts.message).replace(/\n/g,'<br>');
        msgEl.style.display=opts.message?'':'none';
        if(opts.cost){ costEl.style.display=''; costEl.innerHTML='💰 '+esc(opts.cost); } else costEl.style.display='none';
        if(mode==='prompt'){ inputEl.style.display=''; inputEl.value=opts.defaultValue||''; inputEl.placeholder=opts.placeholder||''; }
        else inputEl.style.display='none';
        okBtn.textContent=opts.okText || (mode==='alert'?'OK':(mode==='prompt'?'Simpan':'Ya'));
        okBtn.className='lm-dlg-btn lm-dlg-ok'+(danger?' danger':'');
        cancelBtn.textContent=opts.cancelText || 'Batal';
        cancelBtn.style.display=(mode==='alert')?'none':'';
        ov.classList.add('show');
        document.addEventListener('keydown',onKey,true);
        setTimeout(function(){ (mode==='prompt'?inputEl:okBtn).focus(); },60);
        return new Promise(function(res){ resolver=res; });
      };
      window.lmAlert=function(msg,opts){ return window.lmDialog(Object.assign({type:'alert',message:msg},opts||{})); };
      window.lmConfirm=function(msg,opts){ return window.lmDialog(Object.assign({type:'confirm',message:msg},opts||{})); };
      window.lmPrompt=function(msg,def,opts){ return window.lmDialog(Object.assign({type:'prompt',message:msg,defaultValue:def},opts||{})); };
      // Gate link/navigasi: onclick="return lmAsk(event,'Yakin?')"
      window.lmAsk=function(ev, msg, opts){
        ev.preventDefault(); if(ev.stopPropagation) ev.stopPropagation();
        var a=ev.currentTarget||ev.target, href=(a && a.getAttribute)?a.getAttribute('href'):null;
        window.lmConfirm(msg,opts).then(function(ok){ if(ok && href) window.location.href=href; });
        return false;
      };
      // Gate submit form: onsubmit="return lmAskSubmit(event,'Yakin?')"
      window.lmAskSubmit=function(ev, msg, opts){
        ev.preventDefault();
        var form=ev.currentTarget||ev.target;
        window.lmConfirm(msg,opts).then(function(ok){ if(ok) HTMLFormElement.prototype.submit.call(form); });
        return false;
      };
      // Override alert native → cantik (non-blocking, tak butuh nilai balik)
      window.alert=function(m){ window.lmAlert(String(m==null?'':m)); };
    })();
    </script>

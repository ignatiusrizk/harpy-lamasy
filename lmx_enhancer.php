<?php
// lmx_enhancer.php — de-native controls (select/date → custom) STANDALONE.
// Salinan IIFE lmx dari components.php (self-contained, guard window.__lmxInit,
// butuh CSS .lmx-* dari harpy-erp.css). Dipakai layout SuperAdmin yang tak lewat
// renderGlobalJsHelpers. Kalau logika lmx di components.php berubah, sinkronkan ke sini.
?>
<script>
    (function(){
      if (window.__lmxInit) return; window.__lmxInit = true;
      var BLN = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
      var panel = null, panelFor = null;

      function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
      function closePanel(){ if (panel){ panel.remove(); panel = null; panelFor = null; } }
      window.lmxClose = closePanel;
      document.addEventListener('click', function(e){
        if (panel && !e.target.closest('.lmx-panel') && !e.target.closest('.lmx-btn')) closePanel();
      }, true);
      window.addEventListener('resize', closePanel);
      window.addEventListener('scroll', function(e){
        if (panel && !(e.target && e.target.closest && e.target.closest('.lmx-panel'))) closePanel();
      }, true);

      function openPanel(btn, build){
        if (panel && panelFor === btn){ closePanel(); return; }
        closePanel();
        var p = document.createElement('div');
        p.className = 'lmx-panel';
        build(p);
        document.body.appendChild(p);
        var r = btn.getBoundingClientRect();
        var w = p.offsetWidth, h = p.offsetHeight;
        p.style.left = Math.max(8, Math.min(r.left, window.innerWidth - w - 8)) + 'px';
        var top = r.bottom + 6;
        if (top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 6);
        p.style.top = top + 'px';
        panel = p; panelFor = btn;
        var sel = p.querySelector('.sel');
        if (sel && sel.scrollIntoView) sel.scrollIntoView({ block: 'nearest' });
      }

      function makeBtn(elm, extra){
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = (elm.className || '') + ' lmx-btn ' + (extra || '');
        btn.classList.remove('lmx-skip');
        if (elm.style && elm.style.cssText) btn.style.cssText = elm.style.cssText;
        btn.innerHTML = '<span class="lmx-lbl"></span><span class="lmx-car">▾</span>';
        elm.after(btn);
        elm.classList.add('lmx-native-hide');
        // inline+important: CSS page ber-!important (mis. .hl-filter-bar select{width:100%!important})
        // bisa mengalahkan class → paksa via style inline (menang selalu). fixed = nol dampak layout,
        // tetap 'focusable' utk validasi required (jangan display:none — validation error).
        [['position','fixed'],['top','0'],['left','0'],['width','2px'],['height','2px'],
         ['opacity','0'],['pointer-events','none'],['margin','0'],['padding','0'],
         ['border','0'],['z-index','-1'],['min-width','0'],['max-width','2px']
        ].forEach(function(kv){ elm.style.setProperty(kv[0], kv[1], 'important'); });
        return btn;
      }
      function watchSync(elm, btn, syncFn){
        syncFn();
        elm.addEventListener('change', syncFn);
        var iv = setInterval(function(){
          if (!document.contains(elm)){ clearInterval(iv); btn.remove(); return; }
          syncFn();
        }, 600);
      }

      // ── SELECT ──
      function enhanceSelect(sel){
        if (sel.dataset.lmx || sel.multiple || sel.size > 1 || sel.classList.contains('lmx-skip') ||
            sel.classList.contains('lm-cust') || sel.style.display === 'none') return; // lm-cust/display:none = sudah punya custom UI page
        sel.dataset.lmx = '1';
        var btn = makeBtn(sel, 'lmx-dd');
        function sync(){
          var o = sel.options[sel.selectedIndex];
          btn.querySelector('.lmx-lbl').textContent = o ? o.text : '—';
          btn.disabled = !!sel.disabled;
        }
        watchSync(sel, btn, sync);
        btn.addEventListener('click', function(ev){
          ev.stopPropagation(); ev.preventDefault();
          openPanel(btn, function(p){
            var h = '';
            for (var i = 0; i < sel.options.length; i++){
              var o = sel.options[i];
              if (o.hidden) continue;
              h += '<button type="button" class="lmx-opt' + (i === sel.selectedIndex ? ' sel' : '') + (o.disabled ? ' dis' : '') + '" data-i="' + i + '">' + esc(o.text) + '</button>';
            }
            p.innerHTML = h || '<div class="lmx-empty">Tidak ada pilihan</div>';
            p.style.minWidth = Math.max(btn.getBoundingClientRect().width, 150) + 'px';
            p.addEventListener('click', function(e){
              var b = e.target.closest('.lmx-opt');
              if (!b || b.classList.contains('dis')) return;
              sel.selectedIndex = +b.dataset.i;
              sel.dispatchEvent(new Event('change', { bubbles: true }));
              sync(); closePanel();
            });
          });
        });
      }

      // ── DATE ──
      function fmtDate(v){
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v || '');
        return m ? (+m[3]) + ' ' + BLN[+m[2] - 1] + ' ' + m[1] : '';
      }
      function pad2(n){ return (n < 10 ? '0' : '') + n; }
      function enhanceDate(inp){
        if (inp.dataset.lmx || inp.classList.contains('lmx-skip')) return;
        inp.dataset.lmx = '1';
        var btn = makeBtn(inp, 'lmx-dd lmx-date');
        btn.querySelector('.lmx-car').textContent = '📅';
        function sync(){
          btn.querySelector('.lmx-lbl').textContent = fmtDate(inp.value) || inp.getAttribute('placeholder') || 'Pilih tanggal';
          btn.querySelector('.lmx-lbl').classList.toggle('ph', !inp.value);
          btn.disabled = !!inp.disabled;
        }
        watchSync(inp, btn, sync);
        btn.addEventListener('click', function(ev){
          ev.stopPropagation(); ev.preventDefault();
          var cur = /^(\d{4})-(\d{2})/.exec(inp.value || '') || [];
          var now = new Date();
          var vy = cur[1] ? +cur[1] : now.getFullYear(), vm = cur[2] ? +cur[2] - 1 : now.getMonth();
          openPanel(btn, function(p){
            p.classList.add('lmx-panel-cal');
            function render(y, mo){
              var min = inp.getAttribute('min') || '', max = inp.getAttribute('max') || '';
              var first = new Date(y, mo, 1).getDay(), days = new Date(y, mo + 1, 0).getDate();
              var today = now.getFullYear() + '-' + pad2(now.getMonth() + 1) + '-' + pad2(now.getDate());
              var h = '<div class="lmx-cal-head"><button type="button" data-nav="-1">‹</button><div class="lmx-cal-title">' + BLN[mo] + ' ' + y + '</div><button type="button" data-nav="1">›</button></div><div class="lmx-cal-grid">';
              ['M','S','S','R','K','J','S'].forEach(function(d){ h += '<div class="lmx-cal-dow">' + d + '</div>'; });
              for (var i = 0; i < first; i++) h += '<span class="lmx-cal-day empty"></span>';
              for (var d = 1; d <= days; d++){
                var val = y + '-' + pad2(mo + 1) + '-' + pad2(d);
                var dis = (min && val < min) || (max && val > max);
                h += '<button type="button" class="lmx-cal-day' + (val === inp.value ? ' sel' : '') + (val === today ? ' today' : '') + (dis ? ' dis' : '') + '" data-v="' + val + '"' + (dis ? ' disabled' : '') + '>' + d + '</button>';
              }
              p.innerHTML = h + '</div>';
            }
            render(vy, vm);
            p.addEventListener('click', function(e){
              var nav = e.target.closest('[data-nav]');
              if (nav){ vm += +nav.dataset.nav; if (vm < 0){ vm = 11; vy--; } if (vm > 11){ vm = 0; vy++; } render(vy, vm); return; }
              var day = e.target.closest('.lmx-cal-day[data-v]');
              if (!day) return;
              inp.value = day.dataset.v;
              inp.dispatchEvent(new Event('input', { bubbles: true }));
              inp.dispatchEvent(new Event('change', { bubbles: true }));
              sync(); closePanel();
            });
          });
        });
      }

      // ── MONTH ──
      function enhanceMonth(inp){
        if (inp.dataset.lmx || inp.classList.contains('lmx-skip')) return;
        inp.dataset.lmx = '1';
        var btn = makeBtn(inp, 'lmx-dd lmx-date');
        btn.querySelector('.lmx-car').textContent = '📅';
        function sync(){
          var m = /^(\d{4})-(\d{2})$/.exec(inp.value || '');
          btn.querySelector('.lmx-lbl').textContent = m ? BLN[+m[2] - 1] + ' ' + m[1] : 'Pilih bulan';
          btn.querySelector('.lmx-lbl').classList.toggle('ph', !m);
          btn.disabled = !!inp.disabled;
        }
        watchSync(inp, btn, sync);
        btn.addEventListener('click', function(ev){
          ev.stopPropagation(); ev.preventDefault();
          var cur = /^(\d{4})/.exec(inp.value || '');
          var vy = cur ? +cur[1] : new Date().getFullYear();
          openPanel(btn, function(p){
            p.classList.add('lmx-panel-cal');
            function render(y){
              var min = inp.getAttribute('min') || '', max = inp.getAttribute('max') || '';
              var h = '<div class="lmx-cal-head"><button type="button" data-nav="-1">‹</button><div class="lmx-cal-title">' + y + '</div><button type="button" data-nav="1">›</button></div><div class="lmx-mo-grid">';
              for (var i = 0; i < 12; i++){
                var val = y + '-' + pad2(i + 1);
                var dis = (min && val < min) || (max && val > max);
                h += '<button type="button" class="lmx-mo' + (val === inp.value ? ' sel' : '') + (dis ? ' dis' : '') + '" data-v="' + val + '"' + (dis ? ' disabled' : '') + '>' + BLN[i] + '</button>';
              }
              p.innerHTML = h + '</div>';
            }
            render(vy);
            p.addEventListener('click', function(e){
              var nav = e.target.closest('[data-nav]');
              if (nav){ vy += +nav.dataset.nav; render(vy); return; }
              var mo = e.target.closest('.lmx-mo[data-v]');
              if (!mo) return;
              inp.value = mo.dataset.v;
              inp.dispatchEvent(new Event('input', { bubbles: true }));
              inp.dispatchEvent(new Event('change', { bubbles: true }));
              sync(); closePanel();
            });
          });
        });
      }

      // ── INPUT UANG (.lm-rp): tampilkan separator ribuan live saat ketik.
      //    Input asli disembunyikan tapi tetap pegang nilai MENTAH (digit polos) —
      //    kode existing yang baca/set .value tak berubah (pola sama dgn select di atas). ──
      function rpFmt(v){
        v = String(v == null ? '' : v).replace(/\D/g, '');
        return v ? v.replace(/\B(?=(\d{3})+(?!\d))/g, '.') : '';
      }
      function enhanceRp(inp){
        if (inp.dataset.lmrp) return;
        inp.dataset.lmrp = '1';
        var vis = document.createElement('input');
        vis.type = 'text'; vis.inputMode = 'numeric'; vis.autocomplete = 'off';
        vis.className = (inp.className || '').replace(/\blm-rp\b/, '').trim();
        if (inp.style && inp.style.cssText) vis.style.cssText = inp.style.cssText;
        if (inp.placeholder) vis.placeholder = inp.placeholder;
        inp.after(vis);
        [['position','fixed'],['top','0'],['left','0'],['width','2px'],['height','2px'],
         ['opacity','0'],['pointer-events','none'],['margin','0'],['padding','0'],
         ['border','0'],['z-index','-1'],['min-width','0'],['max-width','2px']
        ].forEach(function(kv){ inp.style.setProperty(kv[0], kv[1], 'important'); });
        var lastRaw = null;
        function fromRaw(){
          var raw = String(inp.value == null ? '' : inp.value).replace(/\D/g, '');
          if (raw === lastRaw) return;
          lastRaw = raw;
          vis.value = rpFmt(raw);
          vis.disabled = !!inp.disabled;
        }
        fromRaw();
        inp.addEventListener('change', fromRaw);
        var iv = setInterval(function(){
          if (!document.contains(inp)){ clearInterval(iv); vis.remove(); return; }
          if (document.activeElement !== vis) fromRaw();
        }, 600);
        vis.addEventListener('input', function(){
          var raw = vis.value.replace(/\D/g, '');
          vis.value = rpFmt(raw);
          lastRaw = raw;
          inp.value = raw;
          inp.dispatchEvent(new Event('input', { bubbles: true }));
        });
        vis.addEventListener('blur', function(){
          inp.dispatchEvent(new Event('change', { bubbles: true }));
        });
      }

      var scanQueued = false;
      function scan(){
        scanQueued = false;
        try {
          document.querySelectorAll('select').forEach(enhanceSelect);
          document.querySelectorAll('input[type=date]').forEach(enhanceDate);
          document.querySelectorAll('input[type=month]').forEach(enhanceMonth);
          document.querySelectorAll('input.lm-rp, input[data-rp]').forEach(enhanceRp);
        } catch(e){}
      }
      window.lmxScan = scan;
      function boot(){
        scan();
        new MutationObserver(function(){
          if (scanQueued) return; scanQueued = true;
          setTimeout(scan, 60);
        }).observe(document.body, { childList: true, subtree: true });
      }
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
    })();
</script>

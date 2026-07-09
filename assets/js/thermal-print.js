/* thermal-print.js — cetak struk ke printer thermal Bluetooth (native app) atau fallback window.print().
 * Plugin: capacitor-thermal-printer-bluetooth → window.Capacitor.Plugins.CapacitorThermalPrinter
 * Model: scan (discoverDevices) → connect({address}) → begin().image(base64).write()
 * Butuh html2canvas (vendor lokal) untuk render struk HTML → bitmap.
 */
(function () {
  var LS_PRINTER = 'lamasy_printer';   // {address,name}
  var LS_AUTO    = 'lamasy_print_auto'; // '1'/'0'

  function pl() {
    try { return (window.Capacitor && window.Capacitor.Plugins) ? window.Capacitor.Plugins.CapacitorThermalPrinter : null; }
    catch (e) { return null; }
  }
  function isNative() {
    return !!(window.Capacitor && typeof window.Capacitor.isNativePlatform === 'function' && window.Capacitor.isNativePlatform());
  }

  var TP = {
    isAvailable: function () { return isNative() && !!pl(); },

    getPrinter: function () {
      try { return JSON.parse(localStorage.getItem(LS_PRINTER) || 'null'); } catch (e) { return null; }
    },
    setPrinter: function (p) { localStorage.setItem(LS_PRINTER, JSON.stringify(p)); },
    autoEnabled: function () { return localStorage.getItem(LS_AUTO) === '1'; },
    setAuto: function (b) { localStorage.setItem(LS_AUTO, b ? '1' : '0'); },

    /* Scan printer BT ~timeoutMs, kumpulkan device unik → [{address,name}].
     * Plugin emit event 'discoverDevices' dengan daftar device. */
    scanPrinters: function (timeoutMs) {
      var p = pl();
      return new Promise(function (resolve, reject) {
        if (!p) { reject(new Error('Printer tidak tersedia')); return; }
        var seen = {}, out = [], handle = null, done = false;
        function finish() {
          if (done) return; done = true;
          try { if (handle && handle.remove) handle.remove(); } catch (e) {}
          try { p.stopScan(); } catch (e) {}
          resolve(out);
        }
        // addListener bisa return Promise<handle> (Capacitor baru) ATAU handle langsung
        // (plugin versi lama) → bungkus Promise.resolve biar aman keduanya.
        var reg = p.addListener('discoverDevices', function (data) {
          var list = (data && data.devices) ? data.devices : (Array.isArray(data) ? data : []);
          list.forEach(function (d) {
            var addr = d.address || d.mac; if (!addr || seen[addr]) return;
            seen[addr] = 1; out.push({ address: addr, name: d.name || addr });
          });
        });
        Promise.resolve(reg).then(function (h) {
          handle = h;
          return p.startScan();
        }).catch(reject);
        setTimeout(finish, timeoutMs || 6000);
      });
    },

    /* Render node struk → base64 PNG monokrom selebar widthPx (384=58mm / 576=80mm) */
    renderBitmap: async function (node, widthPx) {
      if (typeof html2canvas !== 'function') throw new Error('html2canvas belum dimuat');
      var clone = node.cloneNode(true);
      var holder = document.createElement('div');
      holder.style.cssText = 'position:fixed;left:-99999px;top:0;background:#fff;width:' + widthPx + 'px;';
      clone.style.width = widthPx + 'px'; clone.style.margin = '0'; clone.style.maxWidth = 'none';
      holder.appendChild(clone);
      document.body.appendChild(holder);
      try {
        var canvas = await html2canvas(clone, { backgroundColor: '#fff', scale: 2, width: widthPx, logging: false });
        var out = document.createElement('canvas');
        out.width = widthPx;
        out.height = Math.max(1, Math.round(canvas.height * (widthPx / canvas.width)));
        var ctx = out.getContext('2d');
        ctx.drawImage(canvas, 0, 0, out.width, out.height);
        var img = ctx.getImageData(0, 0, out.width, out.height), d = img.data;
        for (var i = 0; i < d.length; i += 4) {
          var v = (d[i] * 0.299 + d[i + 1] * 0.587 + d[i + 2] * 0.114) < 160 ? 0 : 255;
          d[i] = d[i + 1] = d[i + 2] = v; d[i + 3] = 255;
        }
        ctx.putImageData(img, 0, 0);
        return out.toDataURL('image/png');
      } finally { document.body.removeChild(holder); }
    },

    /* Cetak node ke printer tersimpan: connect → begin/align/image/cut/write → disconnect.
     * ⚠️ JANGAN fluent-chain (begin().align()...) — itu API wrapper JS plugin yang TIDAK
     * termuat di webview remote (server.url); yang ada hanya proxy bridge Capacitor:
     * tiap method return Promise & butuh payload objek bernama + connectionId (lihat
     * node_modules/.../dist/esm/index.js: payload = {connectionId, ...mappedArgs}). */
    print: async function (node, widthPx) {
      // Trace tiap langkah → /api/print_debug.php (dibaca dari server; device tanpa remote debug)
      var T = [];
      function tlog(s, v) { try { T.push(s + (v !== undefined ? ': ' + (typeof v === 'string' ? v : JSON.stringify(v)) : '')); } catch (e) { T.push(s); } }
      function tsend() {
        try {
          var h = { 'Content-Type': 'application/json' };
          if (typeof window.csrfToken === 'function') h['X-CSRF-Token'] = window.csrfToken();
          fetch('/api/print_debug.php', { method: 'POST', headers: h, body: JSON.stringify({ trace: 'TP.print ' + new Date().toISOString() + '\n' + T.join('\n') }) });
        } catch (e) {}
      }
      async function step(name, fn) {
        try { var r = await fn(); tlog('OK ' + name, r); return r; }
        catch (e) { tlog('ERR ' + name, (e && (e.message || e.code)) || String(e)); throw e; }
      }
      tlog('widthPx', widthPx);
      // Guard double-tap: klik beruntun bikin connect kedua ditolak native
      // ("Printer already connecting!") padahal yang pertama masih jalan.
      if (TP._printing) { throw new Error('Masih mencetak — tunggu sebentar'); }
      TP._printing = true;
      try {
        var p = pl(); if (!p) { tlog('plugin', 'null'); throw new Error('Printer tidak tersedia'); }
        var pr = TP.getPrinter(); tlog('printer', pr);
        if (!pr || !pr.address) throw new Error('Printer belum dipilih');
        await step('renderBitmap', async function () {
          var dataUrl = await TP.renderBitmap(node, widthPx);
          TP._lastB64 = dataUrl.replace(/^data:[^,]*,/, ''); // base64 murni tanpa prefix dataURL
          return 'len=' + TP._lastB64.length; // JANGAN log dataURL utuh — bikin trace kepotong
        });
        var base64 = TP._lastB64;
        // connect + timeout 12s (connect yang tak pernah resolve = pending nyangkut di native,
        // hanya bisa pulih dgn restart app) + 1x retry utk kasus "already connecting" sesaat
        function connectOnce() {
          return Promise.race([
            p.connect({ address: pr.address, encoding: 'GBK' }),
            new Promise(function (_, rej) { setTimeout(function () { rej(new Error('Printer tidak merespons (timeout 12 dtk). Pastikan printer NYALA & dekat, lalu tutup-buka aplikasi.')); }, 12000); })
          ]);
        }
        var res;
        try {
          res = await step('connect', connectOnce);
        } catch (e1) {
          if (/already connecting/i.test(String(e1 && e1.message))) {
            tlog('retry connect 2.5s (already connecting)');
            await new Promise(function (r) { setTimeout(r, 2500); });
            try { res = await step('connect-retry', connectOnce); }
            catch (e2) {
              if (/already connecting/i.test(String(e2 && e2.message)))
                throw new Error('Koneksi printer nyangkut — tutup aplikasi sepenuhnya (swipe dari recent apps), nyalakan printer, buka lagi.');
              throw e2;
            }
          } else { throw e1; }
        }
        var cid = res && res.connectionId ? { connectionId: res.connectionId } : {};
        function arg(extra) { return Object.assign({}, cid, extra || {}); }
        try {
          await step('begin', function () { return p.begin(arg()); });
          await step('align', function () { return p.align(arg({ alignment: 'center' })); });
          await step('image', function () { return p.image(arg({ image: base64 })); });
          await step('feedCutPaper', function () { return p.feedCutPaper(arg({ half: false, feedLines: 2 })); });
          await step('write', function () { return p.write(arg()); });
        } finally {
          // JANGAN disconnect setelah cetak: thread BT native masih mengirim buffer —
          // disconnect terlalu cepat memicu crash APK (app close pasca-print).
          // Koneksi dibiarkan hidup; connect() berikutnya otomatis re-pakai koneksi
          // yang sudah tersambung (native resolve existing) → cetakan ke-2 lebih cepat.
          tlog('skip disconnect (biarkan koneksi hidup)');
        }
        tlog('SELESAI tanpa error');
      } catch (e) {
        tlog('GAGAL', (e && (e.message || e.code)) || String(e));
        TP._printing = false;
        tsend();
        throw e;
      }
      TP._printing = false;
      tsend();
    }
  };
  window.ThermalPrint = TP;
})();

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

    /* Cetak node ke printer tersimpan: connect → begin().image().cutPaper().write() → disconnect */
    print: async function (node, widthPx) {
      var p = pl(); if (!p) throw new Error('Printer tidak tersedia');
      var pr = TP.getPrinter(); if (!pr || !pr.address) throw new Error('Printer belum dipilih');
      var base64 = await TP.renderBitmap(node, widthPx);
      await p.connect({ address: pr.address });
      try {
        await p.begin().align('center').image(base64).feedCutPaper(false, 2).write();
      } finally {
        try { await p.disconnect(); } catch (e) {}
      }
    }
  };
  window.ThermalPrint = TP;
})();

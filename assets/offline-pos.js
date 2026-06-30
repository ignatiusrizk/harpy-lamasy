(function () {
  const DB_NAME = 'lamasy_offline', DB_VER = 1;
  const STORES = ['catalog', 'queue', 'meta'];
  let _db = null, _scope = null;

  function openDB() {
    return new Promise((res, rej) => {
      const r = indexedDB.open(DB_NAME, DB_VER);
      r.onupgradeneeded = (e) => {
        const db = e.target.result;
        if (!db.objectStoreNames.contains('catalog')) db.createObjectStore('catalog');
        if (!db.objectStoreNames.contains('queue'))   db.createObjectStore('queue', { keyPath: 'uuid' });
        if (!db.objectStoreNames.contains('meta'))    db.createObjectStore('meta');
      };
      r.onsuccess = () => res(r.result);
      r.onerror = () => rej(r.error);
    });
  }
  function tx(store, mode) { return _db.transaction(store, mode).objectStore(store); }
  function pput(store, val, key) { return new Promise((res, rej) => { const q = tx(store,'readwrite').put(val, key); q.onsuccess=()=>res(); q.onerror=()=>rej(q.error); }); }
  function pget(store, key) { return new Promise((res, rej) => { const q = tx(store,'readonly').get(key); q.onsuccess=()=>res(q.result); q.onerror=()=>rej(q.error); }); }
  function pall(store) { return new Promise((res, rej) => { const q = tx(store,'readonly').getAll(); q.onsuccess=()=>res(q.result||[]); q.onerror=()=>rej(q.error); }); }
  function pdel(store, key) { return new Promise((res, rej) => { const q = tx(store,'readwrite').delete(key); q.onsuccess=()=>res(); q.onerror=()=>rej(q.error); }); }

  const OfflinePOS = {
    async init(scope) {
      _db = await openDB();
      _scope = `${scope.tenantId}_${scope.outletId}_${scope.userId}`;
      const savedScope = await pget('meta', 'scope');
      if (savedScope && savedScope !== _scope) { await this.clearScope(); }
      await pput('meta', _scope, 'scope');
      let dev = await pget('meta', 'deviceId');
      if (!dev) { dev = Math.random().toString(36).slice(2, 4).toUpperCase(); await pput('meta', dev, 'deviceId'); }
      window.addEventListener('online',  () => { this._renderBanner(); this.sync(); });
      window.addEventListener('offline', () => this._renderBanner());
      this._renderBanner(); this._renderIndicator();
      if (this.isOnline()) { this.snapshotCatalog().catch(()=>{}); this.sync().catch(()=>{}); }
    },
    isOnline() { return navigator.onLine; },
    async snapshotCatalog() {
      const r = await fetch('pos.php?action=catalog_snapshot');
      if (!r.ok) throw new Error('snapshot fail');
      const data = await r.json(); data.ts = Date.now();
      await pput('catalog', data, 'data');
      return data;
    },
    async getCatalog() { return (await pget('catalog', 'data')) || null; },
    async enqueueOrder(payload) {
      const dev = await pget('meta', 'deviceId');
      let seq = (await pget('meta', 'seq')) || 0; seq++; await pput('meta', seq, 'seq');
      const uuid = (crypto.randomUUID ? crypto.randomUUID() : (Date.now()+'-'+Math.random()));
      const tempCode = `OFF-${dev}-${String(seq).padStart(3,'0')}`;
      const entry = { uuid, tempCode, createdAt: new Date().toISOString(), payload, status: 'pending', errorMsg: '' };
      await pput('queue', entry);
      this._renderIndicator();
      return { uuid, tempCode };
    },
    async pendingCount() { return (await pall('queue')).filter(e => e.status==='pending' || e.status==='error').length; },
    async listAttention() { return (await pall('queue')).filter(e => e.status==='error'); },
    async sync() {
      if (!this.isOnline()) return;
      const all = await pall('queue');
      const pending = all.filter(e => e.status === 'pending');
      if (!pending.length) { this._renderIndicator(); return; }
      const orders = pending.map(e => ({ uuid:e.uuid, tempCode:e.tempCode, createdAt:e.createdAt, payload:e.payload }));
      let resp;
      try {
        const r = await fetch('pos.php?action=sync_offline', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ orders }) });
        if (!r.ok) throw new Error('http '+r.status);
        resp = await r.json();
      } catch (e) { return; } // koneksi putus lagi — biarkan pending
      const results = (resp && resp.results) || {};
      for (const e of pending) {
        const res = results[e.uuid];
        if (!res) continue;
        if (res.ok) {
          const synced = (await pget('meta','synced')) || {};
          synced[e.tempCode] = res.no_order; await pput('meta', synced, 'synced');
          await pdel('queue', e.uuid);
        } else {
          e.status = 'error'; e.errorMsg = res.error || 'Gagal'; await pput('queue', e);
        }
      }
      this._renderIndicator();
    },
    async clearScope() {
      for (const s of STORES) { await new Promise((res)=>{ const q = tx(s,'readwrite').clear(); q.onsuccess=()=>res(); q.onerror=()=>res(); }); }
    },
    _renderBanner() {
      let el = document.getElementById('offlineBanner');
      if (!el) { el = document.createElement('div'); el.id = 'offlineBanner'; document.body.appendChild(el); }
      el.style.cssText = 'position:fixed;left:0;right:0;top:0;z-index:9999;text-align:center;padding:6px;font-size:13px;font-weight:700;'+
        (this.isOnline() ? 'display:none' : 'display:block;background:#F59E0B;color:#fff');
      el.textContent = '📴 Offline — order disimpan lokal, ter-sync saat online';
    },
    async _renderIndicator() {
      const n = await this.pendingCount();
      let el = document.getElementById('offlinePending');
      if (!el) { el = document.createElement('div'); el.id='offlinePending'; el.onclick=()=>OfflinePOS.sync(); document.body.appendChild(el); }
      el.style.cssText = 'position:fixed;right:12px;bottom:12px;z-index:9999;cursor:pointer;'+
        (n>0 ? 'display:block;background:#1B2D5A;color:#fff;padding:8px 12px;border-radius:20px;font-size:12px;font-weight:700;box-shadow:0 2px 8px rgba(0,0,0,.2)' : 'display:none');
      el.textContent = `⏳ ${n} order belum ter-sync — tap sync`;
    }
  };
  window.OfflinePOS = OfflinePOS;
})();

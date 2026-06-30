// test_sw_navfallback.js — uji keputusan fetch-handler sw-tenant.js tanpa framework.
// Jalankan: node tests/offline/test_sw_navfallback.js   (butuh Node 18+ utk global fetch/Response/URL)
const fs = require('fs');
const vm = require('vm');
const path = require('path');

let pass = 0, fail = 0;
const ok = (c, m) => c ? (pass++, console.log('  ok   - ' + m)) : (fail++, console.error('  FAIL - ' + m));
const SW_ORIGIN = 'https://lamasy.harpy.id';
// Normalize any string path or Request to a full URL, mirroring what the real
// Cache API does internally (it resolves relative keys against the SW origin).
const urlOf = (r) => new URL(typeof r === 'string' ? r : r.url, SW_ORIGIN).href;

// Fake Cache API berbagi satu store (full-url -> Response).
// Seed keys are normalized to full URLs so that seeding with '/pos' and looking
// up with '/pos' (or the full URL) resolve to the same Map entry — matching how
// the real Cache API resolves keys against the SW origin.
function makeCaches(seed) {
  const store = new Map(Object.entries(seed || {}).map(([k, v]) => [urlOf(k), v]));
  const cache = {
    match: (r) => Promise.resolve(store.get(urlOf(r))),
    put: (r, resp) => { store.set(urlOf(r), resp); return Promise.resolve(); },
    delete: (r) => Promise.resolve(store.delete(urlOf(r))),
    addAll: () => Promise.resolve(),
  };
  return {
    _store: store,
    open: () => Promise.resolve(cache),
    match: (r) => Promise.resolve(store.get(urlOf(r))),
    keys: () => Promise.resolve(['lamasy-tenant-v2']),
    delete: () => Promise.resolve(true),
  };
}

function loadSW(caches, fetchImpl) {
  const code = fs.readFileSync(path.join(__dirname, '../../sw-tenant.js'), 'utf8');
  const handlers = {};
  const sandbox = {
    self: {
      addEventListener: (t, cb) => { handlers[t] = cb; },
      location: { origin: 'https://lamasy.harpy.id' },
      skipWaiting: () => Promise.resolve(),
      clients: { claim: () => Promise.resolve() },
    },
    caches, fetch: fetchImpl, Response, URL, console, Promise,
  };
  vm.createContext(sandbox);
  vm.runInContext(code, sandbox);
  return { handlers, sandbox };
}

function fire(handlers, sandbox, reqUrl, mode, fetchImpl) {
  sandbox.fetch = fetchImpl;
  const event = { request: { url: reqUrl, method: 'GET', mode }, respondWith(p) { this._res = p; } };
  handlers.fetch(event);
  return event._res;
}

(async () => {
  // (1) navigasi offline ke /login tanpa cache → offlinePage (ada teks "Tidak ada koneksi")
  {
    const caches = makeCaches();
    const { handlers, sandbox } = loadSW(caches, () => Promise.reject(new Error('offline')));
    const res = await fire(handlers, sandbox, 'https://lamasy.harpy.id/login', 'navigate', () => Promise.reject(new Error('offline')));
    ok(!!res, 'navigasi /login offline → respondWith dipanggil (tidak lolos ke browser)');
    const txt = res ? await res.text() : '';
    ok(/Tidak ada koneksi/.test(txt), 'navigasi /login offline tanpa cache → offlinePage');
  }

  // (2) navigasi offline ke /login DENGAN /pos cached → serve POS cached
  {
    const caches = makeCaches({ '/pos': new Response('<html>POS CACHED</html>', { headers: { 'Content-Type': 'text/html' } }) });
    const { handlers, sandbox } = loadSW(caches, () => Promise.reject(new Error('offline')));
    const res = await fire(handlers, sandbox, 'https://lamasy.harpy.id/login', 'navigate', () => Promise.reject(new Error('offline')));
    const txt = res ? await res.text() : '';
    ok(/POS CACHED/.test(txt), 'navigasi /login offline + /pos cached → serve POS cached');
  }

  // (3) navigasi online ke /pos → stale-while-revalidate menaruh ke cache
  {
    const caches = makeCaches();
    const onlineResp = () => Promise.resolve(new Response('<html>FRESH POS</html>', { status: 200, headers: { 'Content-Type': 'text/html' } }));
    const { handlers, sandbox } = loadSW(caches, onlineResp);
    const res = await fire(handlers, sandbox, 'https://lamasy.harpy.id/pos', 'navigate', onlineResp);
    const txt = res ? await res.text() : '';
    ok(/FRESH POS/.test(txt), 'navigasi /pos online → serve hasil network');
    // beri kesempatan promise put() selesai
    await new Promise(r => setTimeout(r, 10));
    ok(caches._store.has('https://lamasy.harpy.id/pos'), 'navigasi /pos online → shell di-cache (SWR)');
  }

  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})();

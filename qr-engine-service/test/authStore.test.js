import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import path from 'node:path';
import fs from 'node:fs/promises';
import { fileURLToPath, pathToFileURL } from 'node:url';

/**
 * Credentials must survive a process restart, which is what a redeploy or
 * pod recreation is. The "backend" here is a fake that implements the same
 * contract as backend-api's /api/internal/whatsapp-auth routes. Each
 * "restart" is a fresh import of authStore.js, so it has no in-memory state.
 */

const SECRET = 'test-secret';
const store = new Map(); // accountId -> Map(name -> raw JSON string)
let putDelayMs = 0; // simulates a backend with no free worker

function accountEntries(id) {
  if (!store.has(id)) store.set(id, new Map());
  return store.get(id);
}

function readBody(req) {
  return new Promise((resolve) => {
    let raw = '';
    req.on('data', (chunk) => (raw += chunk));
    req.on('end', () => resolve(raw ? JSON.parse(raw) : {}));
  });
}

const server = http.createServer(async (req, res) => {
  const send = (status, body) => {
    res.writeHead(status, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(body));
  };

  if (req.headers['x-internal-secret'] !== SECRET) return send(401, { message: 'Unauthorized.' });

  const url = new URL(req.url, 'http://x');
  const match = url.pathname.match(/^\/api\/internal\/whatsapp-auth\/(\w+)$/);

  if (req.method === 'GET' && url.pathname === '/api/internal/whatsapp-auth/paired') {
    const ids = [...store.keys()].filter((id) => {
      const raw = store.get(id).get('creds.json');
      // Same rule as backend-api's /paired: registered OR a device identity.
      const creds = raw && JSON.parse(raw);
      return !!creds && (creds.registered === true || !!creds.me?.id);
    });
    return send(200, { number_ids: ids.map(Number) });
  }

  if (!match) return send(404, {});
  const id = Number(match[1]);

  if (req.method === 'GET') {
    return send(200, { entries: Object.fromEntries(accountEntries(id)) });
  }
  if (req.method === 'PUT') {
    const body = await readBody(req);
    if (putDelayMs) await new Promise((resolve) => setTimeout(resolve, putDelayMs));
    // Same limit as backend-api's WhatsAppAuthStateController.
    if (Object.keys(body.set ?? {}).length > 1000 || (body.delete ?? []).length > 1000) {
      return send(422, { message: 'Too many entries.' });
    }
    const entries = accountEntries(id);
    for (const [name, value] of Object.entries(body.set ?? {})) entries.set(name, value);
    for (const name of body.delete ?? []) entries.delete(name);
    return send(200, { message: 'Auth state updated.' });
  }
  if (req.method === 'DELETE') {
    store.delete(id);
    return send(200, { message: 'Auth state removed.' });
  }
  return send(405, {});
});

let authStore; // the module "instance" currently under test
const here = path.dirname(fileURLToPath(import.meta.url));
const moduleUrl = pathToFileURL(path.join(here, '../src/services/authStore.js')).href;

async function restart() {
  // A new query string makes Node load a separate module instance, so it
  // starts with empty in-process state (handles, caches), like a new pod.
  return import(`${moduleUrl}?run=${Math.random()}`);
}

before(async () => {
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const { port } = server.address();
  process.env.BACKEND_API_URL = `http://127.0.0.1:${port}`;
  process.env.INTERNAL_API_SECRET = SECRET;
  process.env.QR_SESSION_STORE = 'backend';
  authStore = await restart();
});

after(() => {
  server.close();
});

test('credentials and keys survive a restart with their binary fields intact', async () => {
  const first = await authStore.loadAuthState(2001);
  first.state.creds.registered = true;
  first.state.creds.me = { id: '919876543210@s.whatsapp.net', name: 'Test' };
  await first.saveCreds();
  await first.state.keys.set({
    'pre-key': { 7: { keyId: 7, keyPair: { public: Buffer.from([1, 2, 3]), private: Buffer.from([4, 5, 6]) } } },
  });
  // Key writes are queued (not awaited by set()); a shutdown drains them first.
  await authStore.drainAllAuthStates();

  const originalNoise = first.state.creds.noiseKey.public;

  const later = await restart();
  const second = await later.loadAuthState(2001);

  assert.equal(second.state.creds.registered, true);
  assert.equal(second.state.creds.me.id, '919876543210@s.whatsapp.net');
  assert.ok(Buffer.isBuffer(second.state.creds.noiseKey.public), 'creds Buffers must come back as Buffers');
  assert.deepEqual(second.state.creds.noiseKey.public, originalNoise);

  const keys = await second.state.keys.get('pre-key', ['7']);
  assert.ok(Buffer.isBuffer(keys['7'].keyPair.private));
  assert.deepEqual(keys['7'].keyPair.private, Buffer.from([4, 5, 6]));
});

test('a key written as null is removed from storage', async () => {
  const handle = await authStore.loadAuthState(2002);
  await handle.state.keys.set({ 'session': { 'abc': { value: 1 } } });
  await handle.state.keys.set({ 'session': { 'abc': null } });
  await handle.drain();

  const later = await restart();
  const reloaded = await later.loadAuthState(2002);
  const keys = await reloaded.state.keys.get('session', ['abc']);
  assert.equal(keys.abc, null);
});

test('clearing an account removes its credentials for every later start', async () => {
  const handle = await authStore.loadAuthState(2003);
  handle.state.creds.registered = true;
  await handle.saveCreds();
  await handle.drain();

  await authStore.clearAuthState(2003);

  const later = await restart();
  const reloaded = await later.loadAuthState(2003);
  assert.equal(reloaded.state.creds.registered, false, 'a logged-out account must start a fresh pairing');
});

test('only completed pairings are listed as resumable', async () => {
  const pending = await authStore.loadAuthState(2004);
  pending.state.creds.registered = false;
  await pending.saveCreds();

  const paired = await authStore.loadAuthState(2005);
  paired.state.creds.registered = true;
  await paired.saveCreds();
  await paired.drain();
  await pending.drain();

  const ids = await authStore.listPairedAccountIds();
  assert.ok(ids.includes('2005'), 'a registered account must be resumable');
  assert.ok(!ids.includes('2004'), 'a mid-pairing account must not be resumed silently');
});

test('a pre-existing local sessions folder is imported once, without re-pairing', async () => {
  const sessionsDir = path.join(here, '../sessions/2006');
  await fs.mkdir(sessionsDir, { recursive: true });
  try {
    const creds = { registered: true, me: { id: '919111111111@s.whatsapp.net', name: 'Legacy' } };
    await fs.writeFile(path.join(sessionsDir, 'creds.json'), JSON.stringify(creds));

    const imported = await authStore.loadAuthState(2006);
    assert.equal(imported.state.creds.registered, true);
    assert.equal(imported.state.creds.me.name, 'Legacy');

    const later = await restart();
    const reloaded = await later.loadAuthState(2006);
    assert.equal(reloaded.state.creds.registered, true, 'the import must be persisted to the backend');
  } finally {
    await fs.rm(sessionsDir, { recursive: true, force: true });
  }
});

test('a paired pre-migration folder is resumable at boot, even before anything is imported', async () => {
  const sessionsDir = path.join(here, '../sessions/2007');
  await fs.mkdir(sessionsDir, { recursive: true });
  try {
    await fs.writeFile(path.join(sessionsDir, 'creds.json'), JSON.stringify({ registered: true }));
    const ids = await authStore.listPairedAccountIds();
    assert.ok(ids.includes('2007'), 'a legacy paired folder must be listed so the boot resume picks it up');
  } finally {
    await fs.rm(sessionsDir, { recursive: true, force: true });
  }
});

test('clearing an account also removes its leftover local folder', async () => {
  const sessionsDir = path.join(here, '../sessions/2008');
  await fs.mkdir(sessionsDir, { recursive: true });
  await fs.writeFile(path.join(sessionsDir, 'creds.json'), JSON.stringify({ registered: true }));

  await authStore.clearAuthState(2008);

  await assert.rejects(fs.stat(sessionsDir), 'the logged-out folder must be gone');
});

test('a QR-paired account (device identity set, registered still false) is resumable after a restart', async () => {
  const handle = await authStore.loadAuthState(2009);
  handle.state.creds.me = { id: '919222222222:7@s.whatsapp.net', name: 'QR' };
  handle.state.creds.registered = false;
  await handle.saveCreds();
  await handle.drain();

  const later = await restart();
  const ids = await later.listPairedAccountIds();
  assert.ok(ids.includes('2009'), 'Baileys 7 leaves registered=false after a QR pairing; the device identity is the signal');
});

test('a key write does not wait for a slow backend, and is still stored in order', async () => {
  const handle = await authStore.loadAuthState(2101);
  putDelayMs = 1500;
  try {
    const started = Date.now();
    await handle.state.keys.set({ session: { a: { v: 1 } } });
    await handle.state.keys.set({ session: { a: { v: 2 } } });
    assert.ok(Date.now() - started < 500, 'set() must not block on the backend (it is awaited inside a send)');
    assert.deepEqual((await handle.state.keys.get('session', ['a'])).a, { v: 2 }, 'the running socket reads the new value at once');

    await authStore.drainAllAuthStates();
    assert.equal(accountEntries(2101).get('session-a.json'), JSON.stringify({ v: 2 }), 'the last write wins after draining');
  } finally {
    putDelayMs = 0;
  }
});

test('a write larger than the backend limit is stored in full, in chunks', async () => {
  const handle = await authStore.loadAuthState(2201);
  const many = {};
  for (let i = 0; i < 1200; i += 1) many[`k${i}`] = { v: i };
  await handle.state.keys.set({ tctoken: many });
  await authStore.drainAllAuthStates();

  const stored = accountEntries(2201);
  assert.equal([...stored.keys()].filter((name) => name.startsWith('tctoken-')).length, 1200);
  assert.equal(stored.get('tctoken-k1199.json'), JSON.stringify({ v: 1199 }));
});

test('chunkBatch splits sets and deletes and keeps an empty batch as one no-op', () => {
  const set = Object.fromEntries(Array.from({ length: 1001 }, (_, i) => [`a${i}`, 'x']));
  const chunks = authStore.chunkBatch({ set, delete: ['d1', 'd2'] }, 500);
  assert.deepEqual(chunks.map((c) => [Object.keys(c.set).length, c.delete.length]), [[500, 0], [500, 0], [1, 0], [0, 2]]);
  assert.deepEqual(authStore.chunkBatch({ set: {}, delete: [] }), [{ set: {}, delete: [] }]);
});

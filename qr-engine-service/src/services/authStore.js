import path from 'node:path';
import fs from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { useMultiFileAuthState, initAuthCreds, BufferJSON, proto } from '@whiskeysockets/baileys';
import {
  fetchBackendAuthState,
  patchBackendAuthState,
  removeBackendAuthState,
  fetchBackendPairedAccountIds,
} from './backendClient.js';

/**
 * Where a Baileys account's credentials live.
 *
 *  - 'backend' (default): encrypted rows in backend-api's MariaDB
 *    (whatsapp_engine_auth_states), reached through the internal API.
 *    Survives a redeploy, a pod recreation, or a node change — the Node
 *    process keeps no authoritative state on its own disk.
 *  - 'file': the original sessions/<accountId>/ folder on local disk.
 *    Fine on a developer machine; lost whenever a container's filesystem
 *    is replaced, so never use it in Kubernetes.
 *
 * Both stores hold the SAME file names and the SAME BufferJSON encoding
 * Baileys' useMultiFileAuthState writes, so a file-store folder can be
 * imported into the backend store without re-pairing.
 */
const STORE = process.env.QR_SESSION_STORE === 'file' ? 'file' : 'backend';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SESSIONS_ROOT = path.join(__dirname, '../../sessions');

// Keys live in a filename-shaped namespace. Same mapping Baileys uses, so
// names written by the file store and the backend store are identical.
const fixFileName = (file) => file.replace(/\//g, '__').replace(/:/g, '-');

const PERSIST_RETRIES = 3;
const PERSIST_BACKOFF_MS = [500, 1000, 2000];

// Live handle per account. Lets clear() and a replacement socket find the
// previous handle and wait for its pending writes before touching the store.
const handles = new Map();

function sessionDirFor(accountId) {
  return path.join(SESSIONS_ROOT, String(accountId));
}

function parseEntry(raw) {
  if (raw == null) return null;
  try {
    return JSON.parse(raw, BufferJSON.reviver);
  } catch {
    // Same as the file store's readData(): an unreadable entry is treated
    // as absent, so Baileys regenerates it instead of crashing the socket.
    return null;
  }
}

/* ------------------------------------------------------------------ */
/* Backend store                                                       */
/* ------------------------------------------------------------------ */

async function loadBackendHandle(accountId) {
  const id = String(accountId);
  const cache = new Map(Object.entries(await fetchBackendAuthState(id)));

  // One-time import: an account paired before this store existed still has
  // its credentials in sessions/<id>/. Copy them up so the user does not
  // have to scan again. Only runs while the backend has no creds for the
  // account, so it can never overwrite newer backend state.
  if (!cache.has('creds.json')) {
    const local = await readLocalFolder(sessionDirFor(id));
    if (local.size > 0) {
      await patchBackendAuthState(id, { set: Object.fromEntries(local), delete: [] });
      for (const [name, raw] of local) cache.set(name, raw);
      console.log(`[qr-engine] AUTH_STATE_IMPORTED account_id=${id} entries=${local.size} source=local_folder`);
    }
  }

  let chain = Promise.resolve();
  let closed = false;

  const persist = (batch) => {
    if (closed) return chain;
    chain = chain.then(() => pushWithRetry(id, batch)).catch(() => {});
    return chain;
  };

  const creds = cache.has('creds.json') ? parseEntry(cache.get('creds.json')) || initAuthCreds() : initAuthCreds();

  const handle = {
    state: {
      creds,
      keys: {
        get: async (type, ids) => {
          const data = {};
          for (const keyId of ids) {
            let value = parseEntry(cache.get(fixFileName(`${type}-${keyId}.json`)));
            if (type === 'app-state-sync-key' && value) {
              value = proto.Message.AppStateSyncKeyData.fromObject(value);
            }
            data[keyId] = value;
          }
          return data;
        },
        set: async (data) => {
          const set = {};
          const remove = [];
          for (const category in data) {
            for (const keyId in data[category]) {
              const name = fixFileName(`${category}-${keyId}.json`);
              const value = data[category][keyId];
              if (value) {
                const raw = JSON.stringify(value, BufferJSON.replacer);
                cache.set(name, raw);
                set[name] = raw;
              } else {
                cache.delete(name);
                remove.push(name);
              }
            }
          }
          // Awaited on purpose: Baileys only treats a key as used once
          // set() resolves, so the row must be durable before we return.
          await persist({ set, delete: remove });
        },
      },
    },
    saveCreds: async () => {
      const raw = JSON.stringify(creds, BufferJSON.replacer);
      cache.set('creds.json', raw);
      return persist({ set: { 'creds.json': raw }, delete: [] });
    },
    drain: () => chain,
    clear: async () => {
      closed = true;
      chain = chain
        .then(() => removeWithRetry(id))
        .catch((err) => console.error(`[qr-engine] AUTH_STATE_CLEAR_FAILED account_id=${id}:`, err?.message || err));
      return chain;
    },
    close: () => {
      closed = true;
    },
  };

  return handle;
}

async function pushWithRetry(accountId, batch) {
  if (batch.set && Object.keys(batch.set).length === 0 && (!batch.delete || batch.delete.length === 0)) return;

  for (let attempt = 0; ; attempt += 1) {
    try {
      await patchBackendAuthState(accountId, batch);
      return;
    } catch (err) {
      if (attempt >= PERSIST_RETRIES) {
        console.error(`[qr-engine] AUTH_STATE_PERSIST_FAILED account_id=${accountId} — credential update NOT stored after ${attempt + 1} attempts:`, err?.response?.data ?? err?.message ?? err);
        throw err;
      }
      await new Promise((resolve) => setTimeout(resolve, PERSIST_BACKOFF_MS[attempt]));
    }
  }
}

async function readLocalFolder(dir) {
  const files = new Map();
  let names;
  try {
    names = await fs.readdir(dir);
  } catch {
    return files;
  }
  for (const name of names) {
    if (!name.endsWith('.json')) continue;
    try {
      files.set(name, await fs.readFile(path.join(dir, name), 'utf8'));
    } catch {
      // Unreadable file: skip it, same as the file store would.
    }
  }
  return files;
}

/* ------------------------------------------------------------------ */
/* File store (local development)                                      */
/* ------------------------------------------------------------------ */

async function loadFileHandle(accountId) {
  const dir = sessionDirFor(accountId);
  await fs.mkdir(dir, { recursive: true, mode: 0o700 });
  await fs.chmod(dir, 0o700).catch(() => {});

  const { state, saveCreds } = await useMultiFileAuthState(dir);

  return {
    state,
    saveCreds,
    drain: async () => {},
    close: () => {},
    clear: async () => {
      await fs.rm(dir, { recursive: true, force: true });
    },
  };
}

/* ------------------------------------------------------------------ */
/* Public API used by sessionManager.js                                */
/* ------------------------------------------------------------------ */

export const SESSION_STORE = STORE;

/**
 * Loads (or creates) the auth state for accountId. Any previous handle for
 * the same account is closed after its pending writes have been flushed,
 * so the new handle never reads a version older than what was just saved.
 */
export async function loadAuthState(accountId) {
  const id = String(accountId);
  const previous = handles.get(id);
  if (previous) {
    previous.close();
    await previous.drain().catch(() => {});
  }

  const handle = STORE === 'file' ? await loadFileHandle(id) : await loadBackendHandle(id);
  handles.set(id, handle);
  return handle;
}

/** Waits for any writes still queued for accountId (no-op if none). */
export async function drainAuthState(accountId) {
  const handle = handles.get(String(accountId));
  if (handle) await handle.drain().catch(() => {});
}

/** Stops further writes for accountId and deletes its stored credentials. */
export async function clearAuthState(accountId) {
  const id = String(accountId);
  const handle = handles.get(id);
  handles.delete(id);

  if (handle) {
    await handle.clear();
    return;
  }

  // No live handle (for example a logout after a restart): still delete the
  // stored credentials, otherwise they would be resumed on the next boot.
  if (STORE === 'file') {
    await fs.rm(sessionDirFor(id), { recursive: true, force: true });
  } else {
    await removeWithRetry(id);
    // A leftover pre-migration folder would be imported again on the next
    // start, resurrecting credentials the user just logged out of.
    await fs.rm(sessionDirFor(id), { recursive: true, force: true });
  }
}

/** A logout must not leave credentials behind because of one transient failure. */
async function removeWithRetry(accountId) {
  for (let attempt = 0; ; attempt += 1) {
    try {
      await removeBackendAuthState(accountId);
      return;
    } catch (err) {
      if (attempt >= PERSIST_RETRIES) throw err;
      await new Promise((resolve) => setTimeout(resolve, PERSIST_BACKOFF_MS[attempt]));
    }
  }
}

/** Account IDs whose stored credentials completed real WhatsApp pairing. */
export async function listPairedAccountIds() {
  if (STORE === 'backend') {
    const fromBackend = (await fetchBackendPairedAccountIds()).map(String);
    // Also resume paired accounts that exist only as a pre-migration local
    // folder. startSession() imports them into the backend on first load.
    const legacy = (await listLocalPairedAccountIds()).filter((id) => !fromBackend.includes(id));
    return [...fromBackend, ...legacy];
  }
  return listLocalPairedAccountIds();
}

/**
 * "Paired" means the device identity exists. Baileys 7 rc14 sets creds.me on
 * every successful pairing, but it sets creds.registered only for the
 * phone-link flow, so `registered` alone would miss every QR-paired account.
 */
export function isPairedCreds(creds) {
  return creds?.registered === true || !!creds?.me?.id;
}

async function listLocalPairedAccountIds() {

  let names;
  try {
    names = await fs.readdir(SESSIONS_ROOT, { withFileTypes: true });
  } catch (err) {
    if (err.code === 'ENOENT') return [];
    throw err;
  }

  const paired = [];
  for (const entry of names) {
    if (!entry.isDirectory() || !/^\d+$/.test(entry.name)) continue;
    try {
      const creds = JSON.parse(await fs.readFile(path.join(sessionDirFor(entry.name), 'creds.json'), 'utf8'));
      if (isPairedCreds(creds)) paired.push(entry.name);
    } catch {
      // No creds.json yet (mid-pairing, never scanned): not resumable.
    }
  }
  return paired;
}

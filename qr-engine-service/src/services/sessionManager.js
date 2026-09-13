import path from 'node:path';
import fs from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import QRCode from 'qrcode';
import pino from 'pino';
import {
  makeWASocket,
  useMultiFileAuthState,
  DisconnectReason,
  fetchLatestBaileysVersion,
  Browsers,
} from '@whiskeysockets/baileys';
import { notifyBackend } from './backendClient.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SESSIONS_ROOT = path.join(__dirname, '../../sessions');
const MAX_RECONNECT_ATTEMPTS = 5;

const logger = pino({ level: process.env.LOG_LEVEL || 'silent' });

/**
 * In-memory session registry, keyed strictly by account_id (as a string).
 * This map — plus Baileys' own multi-file auth state directory per
 * account_id below — IS the multi-tenant isolation boundary: nothing in
 * this module ever looks up or mutates a session using anything other
 * than the caller-supplied account_id.
 *
 * account_id -> { sock, status, qr, reconnectAttempts }
 */
const sessions = new Map();

function sessionDirFor(accountId) {
  return path.join(SESSIONS_ROOT, String(accountId));
}

function getSession(accountId) {
  return sessions.get(String(accountId));
}

export function getStatus(accountId) {
  return getSession(accountId)?.status ?? 'disconnected';
}

export function getQr(accountId) {
  return getSession(accountId)?.qr ?? null;
}

/**
 * Sends a text message over an already-connected session. Returns a plain
 * {success, ...} result rather than throwing, so server.js's route handler
 * never has to guess whether a rejection was ours or Baileys'.
 *
 * Session Health Guard: checks our own in-memory status AND sock.user (only
 * populated once Baileys has a live, authenticated connection) before ever
 * attempting a send. On failure, notifies backend-api's status webhook
 * immediately — this is the one path that can leave backend-api's DB
 * showing 'connected' when it silently isn't: a connection.update 'close'
 * event never fires if the in-memory session is simply gone (e.g.
 * qr-engine-service restarted since the account last connected), so
 * without this, backend-api would never learn about the disconnect until
 * someone manually forced a re-sync.
 */
export async function sendMessage(accountId, to, message) {
  const id = String(accountId);
  const record = getSession(id);

  const isHealthy = !!record && record.status === 'connected' && !!record.sock && !!record.sock.user;

  if (!isHealthy) {
    const reason = !record
      ? 'no_session'
      : !record.sock
        ? 'no_socket'
        : !record.sock.user
          ? 'not_authenticated'
          : `status_${record.status}`;
    console.log(`[qr-engine] SESSION_HEALTH_CHECK_FAILED account_id=${id} reason=${reason}`);
    await notifyBackend(id, 'disconnected');
    return { success: false, error: 'Session disconnected' };
  }

  try {
    const sent = await record.sock.sendMessage(to, { text: message });
    const messageId = sent?.key?.id ?? null;
    console.log(`[qr-engine] MESSAGE_SENT account_id=${id} message_id=${messageId ?? 'unknown'}`);
    return { success: true, message_id: messageId };
  } catch (err) {
    logger.error({ err, accountId: id }, 'sendMessage failed');
    console.error(`[qr-engine] MESSAGE_SEND_FAILED account_id=${id}:`, err);
    return { success: false, error: err?.message || 'Failed to send message.' };
  }
}

async function clearSessionFiles(accountId) {
  await fs.rm(sessionDirFor(accountId), { recursive: true, force: true });
}

/**
 * Starts (or resumes/reuses) the Baileys session for accountId.
 * `broadcast(accountId, payload)` is injected by server.js so this module
 * has no direct dependency on Socket.IO.
 */
export async function startSession(accountId, broadcast, { isReconnect = false } = {}) {
  const id = String(accountId);
  const existing = getSession(id);

  // isReconnect=true is set only by this module's own post-close retry
  // (below). Without it, a 'connecting' session mid-reconnect would hit
  // this dedupe guard and never actually recreate the dead socket — the
  // guard exists to stop a NEW external start-session call from racing an
  // in-progress one, not to block our own internal retry.
  if (!isReconnect && existing && (existing.status === 'connected' || existing.status === 'connecting')) {
    // Already running — re-emit current state so a newly opened modal
    // catches up instead of waiting indefinitely for the next event.
    broadcast(id, { status: existing.status, qr: existing.qr ?? null });
    return existing.status;
  }

  const sessionDir = sessionDirFor(id);
  await fs.mkdir(sessionDir, { recursive: true });

  const { state, saveCreds } = await useMultiFileAuthState(sessionDir);
  const { version } = await fetchLatestBaileysVersion();

  const sock = makeWASocket({
    version,
    auth: state,
    logger,
    browser: Browsers.ubuntu('WA SaaS Platform'),
    printQRInTerminal: false,
  });

  const record = { sock, status: 'connecting', qr: null, reconnectAttempts: 0 };
  sessions.set(id, record);

  sock.ev.on('creds.update', saveCreds);

  sock.ev.on('connection.update', async (update) => {
    const { connection, lastDisconnect, qr } = update;

    try {
      if (qr) {
        record.qr = await QRCode.toDataURL(qr);
        record.status = 'connecting';
        // Plain, greppable console output — separate from pino (which runs
        // at LOG_LEVEL=silent by default) so ops can `tail`/`grep` these
        // three events without turning on verbose Baileys logging.
        console.log(`[qr-engine] QR_RECEIVED account_id=${id}`);
        broadcast(id, { status: 'connecting', qr: record.qr });
      }

      if (connection === 'open') {
        record.status = 'connected';
        record.qr = null;
        record.reconnectAttempts = 0;
        console.log(`[qr-engine] CONNECTION_OPEN account_id=${id}`);
        broadcast(id, { status: 'connected', qr: null });
        await notifyBackend(id, 'connected');
      }

      if (connection === 'close') {
        const statusCode = lastDisconnect?.error?.output?.statusCode;
        const loggedOut = statusCode === DisconnectReason.loggedOut;
        console.log(`[qr-engine] CONNECTION_CLOSED account_id=${id} status_code=${statusCode ?? 'unknown'} logged_out=${loggedOut}`);

        // [Corrupted-session cleanup, disclosed]: badSession means
        // Baileys itself has determined this account's auth state
        // (sessions/<accountId>/ — this codebase's actual session
        // directory; there is no auth_info_baileys or .wwebjs_auth here,
        // those are whatsapp-web.js/Puppeteer naming, a different library
        // this codebase does not use) is corrupt and cannot be resumed.
        // Previously this fell through to the generic "any other close
        // reason -> reconnect" branch below, which would retry up to
        // MAX_RECONNECT_ATTEMPTS times against the SAME corrupted files —
        // guaranteed to keep failing the same way every time, never
        // actually fixing anything, and only delaying the moment the
        // caller could start a fresh pairing. Treated the same as
        // loggedOut: wipe the corrupted files immediately so the very
        // next start-session call begins from a clean pairing instead of
        // silently hanging/looping against unrecoverable state.
        if (loggedOut || statusCode === DisconnectReason.badSession) {
          console.log(`[qr-engine] CONNECTION_CLOSED account_id=${id} clearing ${loggedOut ? 'logged-out' : 'corrupted'} session files`);
          await clearSessionFiles(id);
          sessions.delete(id);
          broadcast(id, { status: 'disconnected', qr: null });
          await notifyBackend(id, 'disconnected');
          return;
        }

        // Any other close reason (network blip, restartRequired right after
        // pairing, etc.) — Baileys' documented pattern is to just reconnect.
        record.status = 'connecting';
        record.reconnectAttempts += 1;

        if (record.reconnectAttempts > MAX_RECONNECT_ATTEMPTS) {
          logger.error({ accountId: id }, 'giving up after max reconnect attempts');
          console.log(`[qr-engine] CONNECTION_CLOSED account_id=${id} giving up after ${MAX_RECONNECT_ATTEMPTS} reconnect attempts, clearing session files`);
          // [Corrupted-session cleanup, disclosed]: repeatedly failing to
          // reconnect for a non-loggedOut, non-badSession reason (a flaky
          // network, a hung Baileys handshake) still very often means the
          // local auth files are in a state Baileys can't cleanly resume
          // from. Previously these files were left on disk after giving
          // up, so the NEXT start-session call would try to resume the
          // same bad state and could fail the same way again. Clearing
          // them here guarantees the next attempt always starts clean.
          await clearSessionFiles(id);
          sessions.delete(id);
          broadcast(id, { status: 'disconnected', qr: null });
          await notifyBackend(id, 'disconnected');
          return;
        }

        broadcast(id, { status: 'connecting', qr: null });
        // Auto-recovery: backend-api's DB otherwise keeps showing the
        // pre-close status (usually 'connected') for the whole reconnect
        // window — 'connecting' is a valid WhatsAppStatusController status,
        // so reflect the in-progress recovery immediately instead of only
        // notifying once it either succeeds (CONNECTION_OPEN) or gives up.
        await notifyBackend(id, 'connecting');
        await startSession(id, broadcast, { isReconnect: true });
      }
    } catch (err) {
      // A failure in this handler must never crash the process or take
      // down every other tenant's session — log and surface 'disconnected'.
      logger.error({ err, accountId: id }, 'connection.update handler failed');
      console.error(`[qr-engine] connection.update handler failed account_id=${id}:`, err);
      record.status = 'disconnected';
      broadcast(id, { status: 'disconnected', qr: null, error: 'internal_error' });
    }
  });

  return record.status;
}

/**
 * Ends accountId's session (if any) and wipes its auth files, so a future
 * start-session for this account_id begins from a clean pairing.
 */
export async function logoutSession(accountId, broadcast) {
  const id = String(accountId);
  const record = getSession(id);

  if (record?.sock) {
    try {
      await record.sock.logout();
    } catch (err) {
      logger.warn({ err, accountId: id }, 'sock.logout() failed, clearing session anyway');
    } finally {
      record.sock.ev.removeAllListeners();
    }
  }

  sessions.delete(id);
  await clearSessionFiles(id);

  if (broadcast) {
    broadcast(id, { status: 'disconnected', qr: null });
  }

  await notifyBackend(id, 'disconnected');
}

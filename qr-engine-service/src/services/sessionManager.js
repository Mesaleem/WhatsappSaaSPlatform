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
import { notifyBackend, notifyInboundMessage } from './backendClient.js';

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
  const session = getHealthySession(id);

  if (session.error) {
    console.log(`[qr-engine] SESSION_HEALTH_CHECK_FAILED account_id=${id} reason=${session.error}`);
    await notifyBackend(id, 'disconnected');
    return { success: false, error: 'Session disconnected' };
  }

  try {
    const sent = await session.sock.sendMessage(to, { text: message });
    const messageId = sent?.key?.id ?? null;
    console.log(`[qr-engine] MESSAGE_SENT account_id=${id} message_id=${messageId ?? 'unknown'}`);
    return { success: true, message_id: messageId };
  } catch (err) {
    logger.error({ err, accountId: id }, 'sendMessage failed');
    console.error(`[qr-engine] MESSAGE_SEND_FAILED account_id=${id}:`, err);
    return { success: false, error: err?.message || 'Failed to send message.' };
  }
}

/**
 * Native WhatsApp Group Re-Architecture — shared health check, factored
 * out of sendMessage() below with NO behavior change to it: same three
 * conditions (record exists, status === 'connected', sock.user
 * populated), same reason string derivation. createGroup()/
 * addGroupParticipants() need the identical check before doing anything
 * Baileys-side, so this avoids a third copy of the same four lines.
 */
function getHealthySession(accountId) {
  const id = String(accountId);
  const record = getSession(id);
  const isHealthy = !!record && record.status === 'connected' && !!record.sock && !!record.sock.user;

  if (isHealthy) {
    return record;
  }

  const reason = !record
    ? 'no_session'
    : !record.sock
      ? 'no_socket'
      : !record.sock.user
        ? 'not_authenticated'
        : `status_${record.status}`;

  return { error: reason };
}

/**
 * Native WhatsApp Group Re-Architecture — creates a REAL WhatsApp group
 * via Baileys' groupCreate(subject, participantJids), then fetches its
 * invite code (groupInviteCode) so backend-api can store a shareable
 * https://chat.whatsapp.com/<code> link alongside the group JID.
 * `participantJids` are already full JIDs ("<digits>@s.whatsapp.net")
 * built by NativeWhatsAppGroupService on the Laravel side — this module
 * never re-derives a JID from a bare phone number itself, the same
 * division of responsibility sendMessage() below already follows for
 * its own `to` parameter.
 *
 * Returns a plain {success, ...} result, never throws — same contract
 * as sendMessage(), so server.js's route handler doesn't need a second
 * error-shape convention.
 */
export async function createGroup(accountId, subject, participantJids) {
  const id = String(accountId);
  const session = getHealthySession(id);

  if (session.error) {
    console.log(`[qr-engine] GROUP_CREATE_HEALTH_CHECK_FAILED account_id=${id} reason=${session.error}`);
    await notifyBackend(id, 'disconnected');
    return { success: false, error: 'Session disconnected' };
  }

  try {
    const metadata = await session.sock.groupCreate(subject, participantJids);
    const groupJid = metadata?.id;
    console.log(`[qr-engine] GROUP_CREATED account_id=${id} group_jid=${groupJid ?? 'unknown'}`);

    let inviteLink = null;
    try {
      const code = await session.sock.groupInviteCode(groupJid);
      inviteLink = code ? `https://chat.whatsapp.com/${code}` : null;
    } catch (err) {
      // Non-fatal: the group itself was created successfully; a missing
      // invite link only means the UI can't show a shareable link yet,
      // not that this call failed. Logged, not surfaced as an error.
      logger.warn({ err, accountId: id }, 'groupInviteCode failed after successful groupCreate');
      console.warn(`[qr-engine] GROUP_INVITE_CODE_FAILED account_id=${id}:`, err?.message || err);
    }

    return { success: true, jid: groupJid, invite_link: inviteLink };
  } catch (err) {
    logger.error({ err, accountId: id }, 'groupCreate failed');
    console.error(`[qr-engine] GROUP_CREATE_FAILED account_id=${id}:`, err);
    return { success: false, error: err?.message || 'Failed to create the WhatsApp group.' };
  }
}

/**
 * Native WhatsApp Group Re-Architecture — adds participants to an
 * already-created live group via Baileys' groupParticipantsUpdate(jid,
 * participants, 'add'). Same health-check-first, never-throws contract
 * as createGroup()/sendMessage().
 */
export async function addGroupParticipants(accountId, groupJid, participantJids) {
  const id = String(accountId);
  const session = getHealthySession(id);

  if (session.error) {
    console.log(`[qr-engine] GROUP_ADD_PARTICIPANTS_HEALTH_CHECK_FAILED account_id=${id} reason=${session.error}`);
    await notifyBackend(id, 'disconnected');
    return { success: false, error: 'Session disconnected' };
  }

  try {
    const results = await session.sock.groupParticipantsUpdate(groupJid, participantJids, 'add');
    console.log(`[qr-engine] GROUP_PARTICIPANTS_ADDED account_id=${id} group_jid=${groupJid} count=${participantJids.length}`);
    return { success: true, results };
  } catch (err) {
    logger.error({ err, accountId: id, groupJid }, 'groupParticipantsUpdate failed');
    console.error(`[qr-engine] GROUP_ADD_PARTICIPANTS_FAILED account_id=${id} group_jid=${groupJid}:`, err);
    return { success: false, error: err?.message || 'Failed to add participants to the WhatsApp group.' };
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
  // [Partial mitigation, disclosed]: mode 0o700 restricts the Baileys
  // credential directory to the owning OS user on POSIX filesystems.
  // This is NOT encryption at rest — the creds.json files underneath
  // remain plaintext, and on Windows/NTFS (this platform's actual
  // deployment target per the connected XAMPP environment) Node's
  // POSIX mode bits are not honored the same way, so this has little
  // to no effect there. Full encryption-at-rest for session credentials
  // is a larger, disclosed business/design decision, not made here.
  await fs.mkdir(sessionDir, { recursive: true, mode: 0o700 });
  await fs.chmod(sessionDir, 0o700).catch(() => {});

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

  // Module 10 (qr-engine-service side, previously the disclosed "no
  // listener yet" gap): forwards inbound DM text/interactive-reply
  // messages to backend-api's WhatsAppInboundController so chatbot
  // rules / Journey Builder can fire for Baileys ('qr' engine) tenants,
  // the same way MetaWebhookController::handleInboundMessages() already
  // does for Meta-engine tenants.
  //
  // Deliberately scoped, mirroring MetaWebhookController's own scope:
  //  - type !== 'notify' (history-sync backfill on reconnect, not a new
  //    live message) is skipped entirely.
  //  - message.key.fromMe is skipped (our own sent messages must not be
  //    re-interpreted as inbound chatbot triggers).
  //  - Only DIRECT messages (remoteJid ending in @s.whatsapp.net) are
  //    forwarded. Group messages (@g.us) are intentionally NOT forwarded
  //    to the chatbot/journey pipeline here — that pipeline resolves one
  //    sender_phone per tenant and was not designed around group
  //    semantics (who "owns" the conversation in a group), and Meta's
  //    own webhook path this mirrors has no group-messaging capability
  //    at all (Cloud API cannot send/receive in groups), so there is no
  //    existing group-inbound behavior to match. A future module can
  //    add group-aware chatbot routing explicitly if that's wanted.
  //  - Only plain text (conversation / extendedTextMessage) and button
  //    or list interactive replies are extracted, same as
  //    MetaWebhookController::handleInboundMessages()'s $body match().
  //    Every other message type (image, audio, video, location, ...) is
  //    not forwarded.
  sock.ev.on('messages.upsert', ({ messages, type }) => {
    if (type !== 'notify') return;

    for (const msg of messages) {
      try {
        if (msg.key?.fromMe) continue;

        const remoteJid = msg.key?.remoteJid || '';
        if (!remoteJid.endsWith('@s.whatsapp.net')) continue;

        const senderPhone = remoteJid.split('@')[0];
        if (!senderPhone) continue;

        const m = msg.message;
        if (!m) continue;

        const body =
          m.conversation ??
          m.extendedTextMessage?.text ??
          m.buttonsResponseMessage?.selectedButtonId ??
          m.listResponseMessage?.singleSelectReply?.selectedRowId ??
          null;

        if (!body || !String(body).trim()) continue;

        notifyInboundMessage(id, senderPhone, String(body));
      } catch (err) {
        console.error(`[sessionManager] failed to process inbound message for account_id=${id}:`, err.message);
      }
    }
  });

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

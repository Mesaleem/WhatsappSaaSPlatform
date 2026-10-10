import QRCode from 'qrcode';
import pino from 'pino';
import {
  makeWASocket,
  DisconnectReason,
  fetchLatestBaileysVersion,
  Browsers,
} from '@whiskeysockets/baileys';
import axios from 'axios';
import { notifyBackend, notifyInboundMessage, registerSessionOwner, hasSessionOwner, fetchNumberOwner } from './backendClient.js';
import { loadAuthState, drainAuthState, clearAuthState, listPairedAccountIds, isPairedCreds } from './authStore.js';

const MAX_RECONNECT_ATTEMPTS = 5;
// The number each number slot must link to, keyed by session key. A login on
// any other number is refused and logged out (see the open handler).
const expectedPhones = new Map();
// WhatsApp issues a pairing code only while the socket is connecting; if
// none arrives within this window the number is almost certainly wrong or
// not a WhatsApp account, so the UI is told to say so instead of spinning.
const PAIRING_CODE_TIMEOUT_MS = 45000;
// Upper bound on waiting for WhatsApp to acknowledge a logout. The local
// cleanup runs either way; see logoutSession().
const LOGOUT_WAIT_MS = 5000;
// A pairing request waits for the WhatsApp socket to open: up to 30 tries, 1 s apart.
const PAIRING_REQUEST_RETRIES = 30;
const PAIRING_REQUEST_RETRY_MS = 1000;

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

function getSession(accountId) {
  return sessions.get(String(accountId));
}

export function getStatus(accountId) {
  return getSession(accountId)?.status ?? 'disconnected';
}

export function getQr(accountId) {
  return getSession(accountId)?.qr ?? null;
}

export function getPairingCode(accountId) {
  return getSession(accountId)?.pairingCode ?? null;
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
/**
 * Distinguishes "the media_url the caller supplied is unreachable/
 * invalid" (the caller's fault -- sendMessage() maps this to a
 * 400-style result, see below) from every other failure in
 * sendMessage() (Baileys/session errors -- still mapped to the existing
 * 422-style result).
 */
class MediaFetchError extends Error {
  constructor(message) {
    super(message);
    this.name = 'MediaFetchError';
  }
}

// Whole-file in-memory buffering (see fetchMediaBuffer() below) replaces
// the old { url } form, where Baileys/axios streamed the source instead
// of us holding it all in memory at once -- this cap keeps a mistaken or
// hostile media_url from ballooning the process's memory. Comfortably
// above anything a WhatsApp template attachment needs.
const MAX_MEDIA_BYTES = 25 * 1024 * 1024; // 25 MB

const DEFAULT_MIMETYPES = {
  image: 'image/jpeg',
  video: 'video/mp4',
  document: 'application/pdf',
  audio: 'audio/mpeg',
};

/**
 * Fetches `url` into an in-memory Buffer ourselves, before Baileys or
 * WhatsApp's own servers are ever involved.
 *
 * Previously buildContent() below handed Baileys a raw { url } reference
 * and let Baileys fetch it internally as part of its own
 * encrypt-then-upload-to-WhatsApp's-CDN pipeline. That entangled two
 * unrelated failure modes -- "this third-party media host is slow/down/
 * wrong" vs. "WhatsApp's own upload servers rejected it" -- behind one
 * generic Baileys error ("Media upload failed on all hosts") that gave
 * no way to tell which had actually happened (this is what surfaced in
 * production: a valid, reachable media_url still failed this way,
 * because Baileys' own internal fetch-and-upload pipeline offered no way
 * to see which stage broke). Fetching up front means a bad media_url
 * fails right here, with a clear reason, before Baileys or WhatsApp are
 * involved at all.
 */
async function fetchMediaBuffer(url) {
  let response;
  try {
    response = await axios.get(url, {
      responseType: 'arraybuffer',
      timeout: 20000,
      maxRedirects: 5,
      maxContentLength: MAX_MEDIA_BYTES,
      maxBodyLength: MAX_MEDIA_BYTES,
      // Some file hosts reject (or silently redirect to an error/login
      // page for) requests with no User-Agent at all.
      headers: { 'User-Agent': 'Mozilla/5.0 (compatible; WA-SaaS-Bot/1.0)' },
      validateStatus: (status) => status >= 200 && status < 300,
    });
  } catch (err) {
    throw new MediaFetchError(`Could not download media from the provided URL: ${err.message}`);
  }

  const contentType = String(response.headers?.['content-type'] || '').split(';')[0].trim();
  if (contentType.startsWith('text/html')) {
    // The overwhelmingly common shape of "this link doesn't actually
    // serve a file" -- an error page, login wall, or redirect target the
    // host renders instead of the file. We don't try to whitelist every
    // legitimate image/document content type beyond this.
    throw new MediaFetchError('The media URL did not return a file — it returned an HTML page.');
  }

  const buffer = Buffer.from(response.data);
  console.log(`[qr-engine] MEDIA_FETCHED url=${url} bytes=${buffer.length} content_type=${contentType || 'unknown'}`);
  return { buffer, contentType: contentType || null };
}

/**
 * Developer API Platform for WhatsApp Group Creation & Unified
 * Messaging -- builds the Baileys content object for sendMessage()
 * below. Plain text (media === null/undefined) is unchanged from
 * before this feature ({ text: message }).
 *
 * Media is now fetched into a Buffer ourselves (fetchMediaBuffer()
 * above) rather than handed to Baileys as a raw { url } reference --
 * see that function's docblock for why. `mimetype` comes from the
 * fetch's actual Content-Type response header when available, falling
 * back to a per-type default only when the header is missing.
 *
 * @param {{type: string, url: string, caption?: string, filename?: string}|null} [media]
 * @throws {MediaFetchError} if media is present but its URL can't be fetched as a usable file.
 */
async function buildContent(message, media) {
  if (!media || !media.type || !media.url) {
    return { text: message };
  }

  const caption = media.caption || message || undefined;
  const { buffer, contentType } = await fetchMediaBuffer(media.url);
  const mimetype = contentType || DEFAULT_MIMETYPES[media.type] || 'application/octet-stream';

  switch (media.type) {
    case 'image':
      return { image: buffer, caption, mimetype };
    case 'video':
      return { video: buffer, caption, mimetype };
    case 'document':
      return { document: buffer, caption, fileName: media.filename || 'document', mimetype };
    case 'audio':
      // WhatsApp audio messages carry no caption -- Baileys' own audio
      // content type has no such field, unlike image/video/document.
      return { audio: buffer, mimetype: 'audio/mpeg' };
    default:
      return { text: message };
  }
}

export async function sendMessage(accountId, to, message, media = null) {
  const id = String(accountId);
  const session = getHealthySession(id);

  if (session.error) {
    console.log(`[qr-engine] SESSION_HEALTH_CHECK_FAILED account_id=${id} reason=${session.error}`);
    await notifyBackend(id, 'disconnected');
    return { success: false, error: 'Session disconnected' };
  }

  let content;
  try {
    content = await buildContent(message, media);
  } catch (err) {
    if (err instanceof MediaFetchError) {
      // Caller's payload is at fault (bad/unreachable media_url), not
      // Baileys or the session -- sendMessage() never even attempts to
      // send here. code: 'MEDIA_UNREACHABLE' lets the HTTP layer map
      // this to 400 instead of the generic 422 used below.
      logger.warn({ err, accountId: id, mediaUrl: media?.url }, 'media fetch failed');
      console.error(`[qr-engine] MEDIA_FETCH_FAILED account_id=${id} url=${media?.url}:`, err.message);
      return { success: false, error: err.message, code: 'MEDIA_UNREACHABLE' };
    }
    throw err;
  }

  try {
    // 60s per WhatsApp CDN host attempt -- see fetchMediaBuffer()'s
    // logging above for whether a given failure correlates with a larger
    // file needing more of that time than the previous, shorter, implicit
    // Baileys default allowed.
    const sent = await session.sock.sendMessage(to, content, { mediaUploadTimeoutMs: 60000 });
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

/**
 * Native WhatsApp Group Re-Architecture, "select an existing group"
 * extension — lists every real WhatsApp group the connected account is
 * CURRENTLY a participant of, via Baileys' groupFetchAllParticipating().
 * Read-only: never creates, joins, or modifies anything. Same
 * health-check-first, never-throws contract as createGroup()/
 * addGroupParticipants() above, so server.js's route handler doesn't
 * need a third error-shape convention.
 *
 * Returns { success: true, groups: [{ jid, subject, participants_count }] }
 * on success — deliberately NOT the full Baileys metadata (participant
 * JIDs, admin flags, descriptions, etc.) since this is only ever used to
 * populate a picker list; ContactGroupController::importNative() fetches
 * the full metadata itself (via a second call) only for the one group the
 * user actually selects, keeping this list call cheap regardless of how
 * many groups the account belongs to.
 */
export async function listGroups(accountId) {
  const id = String(accountId);
  const session = getHealthySession(id);

  if (session.error) {
    console.log(`[qr-engine] GROUP_LIST_HEALTH_CHECK_FAILED account_id=${id} reason=${session.error}`);
    await notifyBackend(id, 'disconnected');
    return { success: false, error: 'Session disconnected' };
  }

  try {
    const metadataByJid = await session.sock.groupFetchAllParticipating();
    const groups = Object.values(metadataByJid || {}).map((meta) => ({
      jid: meta.id,
      subject: meta.subject || '(untitled group)',
      participants_count: Array.isArray(meta.participants) ? meta.participants.length : 0,
    }));
    console.log(`[qr-engine] GROUP_LIST_FETCHED account_id=${id} count=${groups.length}`);
    return { success: true, groups };
  } catch (err) {
    logger.error({ err, accountId: id }, 'groupFetchAllParticipating failed');
    console.error(`[qr-engine] GROUP_LIST_FAILED account_id=${id}:`, err);
    return { success: false, error: err?.message || 'Failed to list WhatsApp groups.' };
  }
}

/**
 * Native WhatsApp Group Re-Architecture, "select an existing group"
 * extension — full Baileys metadata (including the participant list) for
 * ONE group, by JID. Used by ContactGroupController::importNative() right
 * after the user picks a group from listGroups()'s summary, so the
 * imported ContactGroup starts with its real member list already
 * populated instead of showing 0 members.
 *
 * Returns { success: true, jid, subject, participants: [{ jid, is_admin }] }.
 */
export async function getGroupMetadata(accountId, groupJid) {
  const id = String(accountId);
  const session = getHealthySession(id);

  if (session.error) {
    console.log(`[qr-engine] GROUP_METADATA_HEALTH_CHECK_FAILED account_id=${id} reason=${session.error}`);
    await notifyBackend(id, 'disconnected');
    return { success: false, error: 'Session disconnected' };
  }

  try {
    const meta = await session.sock.groupMetadata(groupJid);
    const participants = Array.isArray(meta.participants)
      ? meta.participants.map((p) => ({ jid: p.id, is_admin: p.admin != null }))
      : [];
    return { success: true, jid: meta.id, subject: meta.subject || '(untitled group)', participants };
  } catch (err) {
    logger.error({ err, accountId: id, groupJid }, 'groupMetadata failed');
    console.error(`[qr-engine] GROUP_METADATA_FAILED account_id=${id} group_jid=${groupJid}:`, err);
    return { success: false, error: err?.message || 'Failed to fetch this group\'s details.' };
  }
}

async function clearSessionFiles(accountId) {
  await clearAuthState(accountId);
}

/**
 * [Deploy-safe reconnect, disclosed]: qr-engine-service's `sessions` Map
 * (in-memory) is the ONLY place a Baileys socket lives -- restarting this
 * process (a new build deployed, a crash, a pod recreated) empties it
 * completely, even though the credentials are still stored (in the
 * backend database by default, see authStore.js) and are perfectly able
 * to resume the same WhatsApp connection without any new QR scan. Before
 * this function existed, nothing ever called startSession() again after
 * such a restart -- the account just sat there reported as whatever
 * backend-api's DB last knew (usually still 'connected', now stale and
 * wrong) until a human opened the WhatsApp Setup page and clicked Connect
 * themselves. That's exactly the "deploy -> WhatsApp looks disconnected ->
 * customer frustration -> churn risk" scenario this was built to close
 * (owner request 2026-10-05).
 *
 * Called once, from server.js, right after this process starts listening
 * -- asks the auth store for every account whose credentials completed a
 * real WhatsApp pairing, and calls the exact same startSession() the
 * Connect button itself calls. Baileys then resumes from the stored keys
 * with no QR and no user action at all.
 *
 * What this deliberately does NOT do, and why each is safe/correct:
 *  - An account the user explicitly disconnected is never considered at
 *    all, because logoutSession() already deletes its stored credentials
 *    (clearAuthState) -- "disconnect -> stay disconnected across a
 *    restart" (owner requirement 1) falls out of this for free.
 *  - An account mid-pairing (QR shown, never scanned, or the process died
 *    before creds were marked `registered`) is skipped, not resumed --
 *    listPairedAccountIds() only returns registered credentials.
 *  - A resumed account whose phone was unlinked from WhatsApp while this
 *    process was down surfaces through the EXACT SAME path a live
 *    disconnect already does: Baileys' connection.update close handler
 *    (below, unchanged) sees DisconnectReason.loggedOut on the first
 *    reconnect attempt, wipes the folder, and notifies backend-api --
 *    no special-casing needed here.
 *  - Never throws out of this function for one bad account: a single
 *    corrupt/unreadable folder is logged and skipped so it can never
 *    block every other tenant's reconnect.
 *  - A small stagger between each resume (RESUME_STAGGER_MS) avoids
 *    opening a burst of WhatsApp sockets in the same instant when many
 *    tenants reconnect after the same deploy -- gentler on WhatsApp's own
 *    rate limits and on this process's own startup CPU/network spike than
 *    firing all of them concurrently.
 */
const RESUME_STAGGER_MS = 400;

export async function resumeAllSessions(broadcast) {
  let accountIds;
  try {
    accountIds = await listPairedAccountIds();
  } catch (err) {
    // Backend unreachable at boot: nothing can be resumed right now. The
    // accounts are NOT lost (their credentials are stored), so the next
    // process start, or a user clicking Connect, will bring them back.
    console.error('[qr-engine] RESUME_ALL_SESSIONS could not list stored sessions, nothing resumed:', err?.message || err);
    return;
  }

  if (accountIds.length === 0) {
    console.log('[qr-engine] RESUME_ALL_SESSIONS no paired sessions stored, nothing to resume');
    return;
  }

  console.log(`[qr-engine] RESUME_ALL_SESSIONS resuming ${accountIds.length} paired session(s)...`);

  let resumed = 0;
  for (const accountId of accountIds) {
    console.log(`[qr-engine] RESUME_ALL_SESSIONS auto-resuming account_id=${accountId}`);
    try {
      await startSession(accountId, broadcast);
      resumed += 1;
    } catch (err) {
      console.error(`[qr-engine] RESUME_ALL_SESSIONS failed to resume account_id=${accountId}:`, err?.message || err);
    }

    await new Promise((resolve) => setTimeout(resolve, RESUME_STAGGER_MS));
  }

  console.log(`[qr-engine] RESUME_ALL_SESSIONS complete — resumed ${resumed} of ${accountIds.length} session folder(s)`);
}

/**
 * Starts (or resumes/reuses) the Baileys session for accountId.
 * `broadcast(accountId, payload)` is injected by server.js so this module
 * has no direct dependency on Socket.IO.
 */
/**
 * Asks WhatsApp for a pairing code for record.pairingPhone and broadcasts
 * it. Issued once per socket: a code stays valid until the user enters it
 * or it expires, and Baileys does not hand out a second one on the same
 * socket. Never throws, so a failed request cannot take the socket down.
 */
async function requestPairingCode(record, id, broadcast, attempt = 0) {
  if (record.pairingInFlight || record.pairingCode || !record.pairingPhone) return;
  // Not gated on creds.registered: Baileys 7 sets it only for the phone-link flow, so a QR-paired account would look unpaired.

  // The request is an iq over the WhatsApp socket. Sent before the socket is
  // open it fails with 'Connection Closed' (seen in the log), so wait for the
  // socket instead of giving up on the first 'connecting' event.
  // sock.ws is Baileys' WebSocket wrapper; isOpen is true once it is connected.
  const socketOpen = record.sock.ws?.isOpen === true;
  if (!socketOpen) {
    if (attempt < PAIRING_REQUEST_RETRIES && sessions.get(id) === record) {
      setTimeout(() => requestPairingCode(record, id, broadcast, attempt + 1), PAIRING_REQUEST_RETRY_MS).unref?.();
    }
    return;
  }

  record.pairingInFlight = true;
  try {
    const code = await record.sock.requestPairingCode(record.pairingPhone);
    record.pairingCode = code;
    console.log(`[qr-engine] PAIRING_CODE_ISSUED account_id=${id}`);
    broadcast(id, { status: record.status, qr: record.qr, pairing_code: code });
  } catch (err) {
    console.error(`[qr-engine] PAIRING_CODE_REQUEST_FAILED account_id=${id}:`, err?.message || err);
  } finally {
    record.pairingInFlight = false;
  }
}

/**
 * Ends a live socket without touching its stored credentials. Used when a
 * phone-number link replaces a QR socket that is still waiting for a scan.
 * Listeners go first so the old socket's close handler cannot start a
 * reconnect or wipe the account.
 */
async function retireSocket(record) {
  clearTimeout(record.pairingTimer);
  record.sock.ev.removeAllListeners();
  try {
    record.sock.end(undefined);
  } catch {
    // Already closed: nothing to end.
  }
  await drainAuthState(record.accountId);
}

/**
 * Starts (or resumes/reuses) the Baileys session for accountId.
 * `broadcast(accountId, payload)` is injected by server.js so this module
 * has no direct dependency on Socket.IO.
 *
 * `phoneNumber` (digits only, country code included) switches the session
 * to pairing-code login: Baileys issues an 8-character code that the user
 * types into WhatsApp (Linked devices -> Link with phone number). The code
 * is broadcast as `pairing_code` on the same Socket.IO stream as the QR.
 * It is ignored if this account is already paired.
 */
export async function startSession(
  accountId,
  broadcast,
  { isReconnect = false, phoneNumber = null, accountId: ownerAccountId = null, slotBased = false, expectedPhone = null } = {},
) {
  const id = String(accountId);

  // Who owns this session, for the backend callbacks. Given on a start request;
  // a reconnect keeps what the first start registered.
  // A slot session that starts without its owner (a boot resume, or a reconnect after
  // a restart) asks the backend which account owns it. Without this its status
  // callbacks would go to the wrong account, and a disconnect would never reach the slot.
  if (!hasSessionOwner(id)) {
    const owner = await fetchNumberOwner(id);
    if (owner) {
      registerSessionOwner(id, { accountId: owner.accountId, slotBased: true });
    }
  }

  if (ownerAccountId != null) {
    registerSessionOwner(id, { accountId: ownerAccountId, slotBased });
    if (expectedPhone) {
      expectedPhones.set(id, String(expectedPhone).replace(/\D/g, ''));
    }
  }
  const existing = getSession(id);

  // isReconnect=true is set only by this module's own post-close retry
  // (below). Without it, a 'connecting' session mid-reconnect would hit
  // this dedupe guard and never actually recreate the dead socket — the
  // guard exists to stop a NEW external start-session call from racing an
  // in-progress one, not to block our own internal retry.
  if (!isReconnect && existing && (existing.status === 'connected' || existing.status === 'connecting')) {
    // A pairing-code request needs a socket that is still unpaired. If a QR
    // socket is waiting for a scan, replace it with a fresh one; a connected
    // session is never touched.
    const canSwitchToPhone = !!phoneNumber && existing.status === 'connecting';
    if (!canSwitchToPhone) {
      // Already running — re-emit current state so a newly opened modal
      // catches up instead of waiting indefinitely for the next event.
      broadcast(id, { status: existing.status, qr: existing.qr ?? null, pairing_code: existing.pairingCode ?? null });
      return existing.status;
    }
    await retireSocket(existing);
    sessions.delete(id);
  } else if (existing) {
    // Reconnect path: the previous socket is already closed, but its last
    // credential write may still be in flight. Wait for it so the new
    // socket never loads state older than what the old one just saved.
    await drainAuthState(id);
  }

  // [Credential storage, disclosed]: credentials live in the backend
  // database by default (see authStore.js for why this is the only
  // store that survives a pod recreation). Stored encrypted with the
  // backend's APP_KEY, never in this process's own filesystem.
  //
  // [Phone login starts a new device, disclosed]: a number login registers
  // a NEW linked device, so it must not reuse a stored login. Stale creds
  // make WhatsApp answer 401 logged-out, which wipes them and kills the
  // pairing request (seen as "PAIRING_CODE_REQUEST_FAILED: Connection
  // Closed" followed by a logged-out close). Only reached when the account
  // is not connected: a connected session returned earlier.
  //
  // [Bug fix, disclosed]: NOT on our own reconnect (isReconnect=true), though
  // -- the mandatory stream:error 515 restart that WhatsApp sends right after
  // ANY first-time pairing (QR or phone number, completely normal, not a
  // failure) fires BEFORE connection:'open', so creds.update may already have
  // saved the real, just-paired credentials while record.pairingPhone is
  // still set (only cleared once 'open' actually fires). The reconnect call
  // passes that same pairingPhone through so pairing-code mode survives a
  // restart that happens BEFORE pairing ever completes (see that call site's
  // own comment) -- but if it HAD already completed, clearing auth state here
  // wiped the credentials that were just saved, one line before loading them
  // back, forcing a brand new QR/code every time and never actually
  // connecting. A genuinely NEW phone-login request is never a reconnect, so
  // this still clears stale creds for that case exactly as before.
  if (phoneNumber && !isReconnect) {
    await clearAuthState(id);
  }
  const authHandle = await loadAuthState(id);
  const { state, saveCreds } = authHandle;
  const { version } = await fetchLatestBaileysVersion();

  const sock = makeWASocket({
    version,
    auth: state,
    logger,
    // [Pairing-code browser, disclosed]: Baileys documents pairing codes with its
    // default Chrome browser identity. A custom name ("WA SaaS Platform") was
    // seen to fail with WhatsApp's "couldn't link device" after a correct code.
    // One identity is used for every session, because the restart that follows
    // pairing must present the same device as the request that started it.
    //
    // [Branded device name, disclosed, scoped]: WhatsApp's own Linked
    // Devices label comes from TWO separate fields of this triple, verified
    // against Baileys' own source (node_modules/@whiskeysockets/baileys/
    // lib/Utils/validate-connection.js -- generateRegistrationNode(), used
    // for every session including QR) --
    //   element[0] -> sent as `os` in the DeviceProps companion payload;
    //                 this is the text WhatsApp shows for the device.
    //   element[1] -> resolved through getPlatformType()/getCompanionPlatformId()
    //                 to a recognized WAProto PlatformType enum value
    //                 (CHROME/FIREFOX/EDGE/...) -- THIS is the field whose
    //                 past rename ("WA SaaS Platform", see the note above)
    //                 broke pairing-code linking, and it also feeds the
    //                 pairing-code companion_platform_display string
    //                 (`${browser[1]} (${browser[0]})`) directly.
    // Browsers.ubuntu('Chrome') => ['Ubuntu', 'Chrome', '22.04.4']. Renaming
    // only element[0] here swaps the shown device name from "Ubuntu" to
    // "WapHub WhatsApp" while leaving element[1] untouched at the exact
    // literal string ('Chrome') already proven safe for pairing-code login
    // -- no regression risk for the issue the note above already paid down.
    browser: ['WapHub WhatsApp', 'Chrome', '22.04.4'],
    printQRInTerminal: false,
    // [Owner instruction, disclosed]: this platform only ever SENDS messages
    // through a linked number -- it never reads or displays the account's own
    // chat history, blocklist, or privacy settings, so none of what these
    // three normally fetch is used anywhere in this codebase (grepped: no
    // caller reads messaging-history.set, chats.set, contacts.set, or a
    // blocklist/privacy-settings event). Baileys defaults all three to true.
    // Turning them off skips that unused work entirely, which matters more
    // here than usual: a free-tier host (lower CPU/network priority, see
    // startSession's own reconnect-backoff comment) has less headroom to
    // absorb extra round-trips and parsing during the already-fragile window
    // right after pairing. NOTE, precisely: this does NOT touch WhatsApp's
    // separate, mandatory app-state (contacts/chat-list/pins) sync -- that is
    // gated by its own sync-state machine, not by these flags, and is also
    // not the cause of a "blocked on missing key ... parking" log: Baileys
    // already catches that itself and retries once the key arrives (that IS
    // what "parking" means) rather than crashing -- it is informational, not
    // a connection failure, with or without this change.
    syncFullHistory: false,
    fireInitQueries: false,
    markOnlineOnConnect: false,
  });

  const record = {
    accountId: id,
    sock,
    status: 'connecting',
    qr: null,
    reconnectAttempts: 0,
    authHandle,
    pairingCode: null,
    pairingPhone: null,
    pairingInFlight: false,
    pairingTimer: null,
  };
  sessions.set(id, record);

  sock.ev.on('creds.update', saveCreds);

  // A number is only meaningful for an account that is not paired yet.
  // Already-paired accounts keep their session and ignore the number.
  // [Bug fix, disclosed]: this comment describes the intent, but nothing below
  // actually checked it -- so a reconnect resuming ALREADY-paired
  // credentials (the mandatory post-pairing 515 restart, see the
  // clearAuthState guard above) still re-armed pairingPhone/the pairing
  // timer, which could re-request a pairing code (and re-broadcast one to
  // the browser) for a socket that was already paired, before 'open' caught
  // up and cleared it. isPairedCreds() (not creds.registered alone -- see
  // its own docblock) makes this actually match the comment: a resumed,
  // already-paired session ignores the number.
  if (phoneNumber && ! isPairedCreds(state.creds)) {
    record.pairingPhone = String(phoneNumber).replace(/\D/g, '');
    record.pairingTimer = setTimeout(() => {
      if (sessions.get(id) !== record || record.pairingCode || record.status === 'connected') return;
      console.log(`[qr-engine] PAIRING_CODE_TIMEOUT account_id=${id}`);
      broadcast(id, { status: record.status, qr: record.qr, error: 'pairing_code_timeout' });
    }, PAIRING_CODE_TIMEOUT_MS);
    record.pairingTimer.unref?.();
  }

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

        notifyInboundMessage(id, senderPhone, String(body), msg.key?.id ?? null);
      } catch (err) {
        console.error(`[sessionManager] failed to process inbound message for account_id=${id}:`, err.message);
      }
    }
  });

  sock.ev.on('connection.update', async (update) => {
    const { connection, lastDisconnect, qr } = update;

    try {
      // Pairing-code login: retried on each event until WhatsApp accepts the
      // request. A failed attempt (socket not ready yet) is not fatal; the
      // next 'connecting' or 'qr' event tries again.
      if (record.pairingPhone && (qr || connection === 'connecting')) {
        await requestPairingCode(record, id, broadcast);
      }

      if (qr) {
        record.qr = await QRCode.toDataURL(qr);
        record.status = 'connecting';
        // Plain, greppable console output — separate from pino (which runs
        // at LOG_LEVEL=silent by default) so ops can `tail`/`grep` these
        // three events without turning on verbose Baileys logging.
        console.log(`[qr-engine] QR_RECEIVED account_id=${id}`);
        broadcast(id, { status: 'connecting', qr: record.qr, pairing_code: record.pairingCode });
      }

      if (connection === 'open') {
        // [Slot number check, disclosed]: a number slot links only to the number
        // it was added with. A login on another number is logged out before it is
        // reported as connected, so the wrong device is not left linked.
        const linkedPhone = String(sock.user?.id ?? '').split(/[:@]/)[0].replace(/\D/g, '');
        const expected = expectedPhones.get(id);
        if (expected && linkedPhone && linkedPhone !== expected) {
          console.log(`[qr-engine] NUMBER_MISMATCH session=${id}: linked a different number; logging it out`);
          broadcast(id, { status: 'disconnected', qr: null, error: 'number_mismatch' });
          await logoutSession(id, broadcast);
          return;
        }

        record.status = 'connected';
        record.qr = null;
        record.pairingCode = null;
        record.pairingPhone = null;
        clearTimeout(record.pairingTimer);
        record.reconnectAttempts = 0;
        console.log(`[qr-engine] CONNECTION_OPEN account_id=${id}`);
        // sock.user.id is "<digits>[:device]@s.whatsapp.net" — the linked number.
        const phoneNumber = String(sock.user?.id ?? '').split(/[:@]/)[0].replace(/\D/g, '') || null;
        // Saved before it is announced: a page that reloads its number list on the
        // live event must already see the new status.
        const backendResult = await notifyBackend(id, 'connected', { phone_number: phoneNumber });

        // [Owner instruction, disclosed]: a default/placeholder slot accepts ANY
        // real number (see backend-api's WhatsAppNumber::hasPendingPlaceholderNumber()
        // docblock) -- but that same real number may ALREADY be adopted on a
        // different slot/account, which only backend-api can know (it is the one
        // place every slot is visible). backend-api refuses that with 409
        // number_already_used and rolls its own row back to unlinked; without this
        // check the Baileys session here stayed genuinely connected to WhatsApp
        // regardless, and the browser was told "connected" either way. Logged out
        // the same way an engine-side number_mismatch already is.
        if (backendResult?.error_code === 'number_already_used') {
          console.log(`[qr-engine] NUMBER_ALREADY_USED account_id=${id}: backend-api refused this number; logging it out`);
          broadcast(id, { status: 'disconnected', qr: null, error: 'number_already_used' });
          await logoutSession(id, broadcast);
          return;
        }

        broadcast(id, { status: 'connected', qr: null });
      }

      if (connection === 'close') {
        const statusCode = lastDisconnect?.error?.output?.statusCode;
        const loggedOut = statusCode === DisconnectReason.loggedOut;
        console.log(`[qr-engine] CONNECTION_CLOSED account_id=${id} status_code=${statusCode ?? 'unknown'} logged_out=${loggedOut}`);

        // [No reconnect after takeover or logout, disclosed]: a socket that was
        // replaced, or is being logged out, must not reconnect. The earlier
        // handler did, so each reconnect took the login back from the other
        // client (440 "replaced" loop), and a logout that raced one was undone.
        // The login is kept on "replaced" (the device is still linked); only the
        // user's own logout removes it.
        if (sessions.get(id) !== record || record.loggingOut) {
          return;
        }
        if (statusCode === DisconnectReason.connectionReplaced) {
          console.log(`[qr-engine] CONNECTION_REPLACED account_id=${id}: another client took over this WhatsApp login; not reconnecting`);
          sessions.delete(id);
          broadcast(id, { status: 'disconnected', qr: null, error: 'session_replaced' });
          await notifyBackend(id, 'disconnected');
          return;
        }

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
          console.log(`[qr-engine] CONNECTION_CLOSED account_id=${id} giving up after ${MAX_RECONNECT_ATTEMPTS} reconnect attempts; stored credentials KEPT (device not logged out)`);
          // [Credentials kept, disclosed]: WhatsApp never said this device was
          // logged out. A run of timeouts or network failures is not a reason to
          // delete the login: the earlier version wiped it here, so a flaky
          // network dropped the account from WhatsApp on the next restart. The
          // stored login stays, the next boot or Connect resumes it, and only
          // loggedOut / badSession (handled above) remove it.
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
        // Back off between attempts so a struggling connection is not hammered.
        await new Promise((resolve) => setTimeout(resolve, Math.min(30000, 2000 * record.reconnectAttempts)));
        // [Bug fix, disclosed]: this reconnect call previously never passed
        // phoneNumber, so startSession() built a brand new record with
        // pairingPhone=null -- silently dropping pairing-code mode back to
        // plain QR on every reconnect, while the browser kept showing the
        // now-orphaned old code (nothing ever told it to clear/replace it).
        // On a flakier connection (more reconnects -- e.g. the production
        // host vs. local) this meant the code on screen could belong to a
        // session that had already quietly reverted to QR underneath it,
        // so WhatsApp correctly rejected it as invalid. Passing the current
        // record's own pairingPhone through keeps a reconnect mid pairing-
        // code login retrying pairing-code mode (and re-broadcasting a
        // fresh code) instead of reverting.
        //
        // The dead socket's own listeners (this very 'connection.update'
        // handler among them) are removed before the new one is created --
        // startSession() below registers a fresh set on the new sock, and
        // without this the old, already-closed socket's listeners stayed
        // attached (harmless once truly unreferenced and garbage collected,
        // but not guaranteed to be immediate) instead of being dropped right
        // away with the socket that owned them.
        record.sock.ev.removeAllListeners();
        await startSession(id, broadcast, { isReconnect: true, phoneNumber: record.pairingPhone || null });
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

  if (record) {
    // Set before logout: the close that logout causes must not reconnect.
    record.loggingOut = true;
  }

  if (record?.sock) {
    try {
      // Capped: sock.logout() waits on WhatsApp's servers, and a slow answer
      // must not hold up the local cleanup or the UI's answer.
      await Promise.race([
        record.sock.logout(),
        new Promise((resolve) => setTimeout(resolve, LOGOUT_WAIT_MS)),
      ]);
    } catch (err) {
      logger.warn({ err, accountId: id }, 'sock.logout() failed, clearing session anyway');
    } finally {
      record.sock.ev.removeAllListeners();
    }
  }

  sessions.delete(id);

  // [Logout must always finish, disclosed]: the live session is already gone
  // at this point, so the UI has to hear about it even if the stored-credential
  // delete fails. If the delete fails, the credentials stay behind, but
  // sock.logout() already unlinked this device on WhatsApp's side. The next
  // boot's resume then gets a loggedOut close and wipes them, so nothing
  // reconnects silently.
  if (broadcast) {
    broadcast(id, { status: 'disconnected', qr: null });
  }

  // [Logout answers before the backend is called, disclosed]: the backend is
  // a single-threaded dev server here. While it waits for this answer it cannot
  // serve the engine's storage delete or status callback, so awaiting them
  // deadlocked both sides (5 s and 20 s timeouts in the log). The cleanup and the
  // notification run in the background; the request returns as soon as WhatsApp
  // has been asked to unlink the device. Failures are logged. A failed storage
  // delete still leaves a safe state: the device is unlinked, so the next boot's
  // resume gets a loggedOut close and wipes the leftovers.
  void (async () => {
    try {
      await clearSessionFiles(id);
    } catch (err) {
      console.error(`[qr-engine] LOGOUT_STORAGE_CLEAR_FAILED account_id=${id}:`, err?.message || err);
    }
    await notifyBackend(id, 'disconnected');
  })();
}

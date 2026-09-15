import 'dotenv/config';
import http from 'node:http';
import crypto from 'node:crypto';
import express from 'express';
import cors from 'cors';
import { Server as SocketIOServer } from 'socket.io';
import { startSession, logoutSession, getStatus, getQr, sendMessage, createGroup, addGroupParticipants, listGroups, getGroupMetadata } from './services/sessionManager.js';
import { verifyAccountAccess } from './services/backendClient.js';

/**
 * [Cross-device boot-stall hardening, disclosed]: added because a "silent
 * stall" during boot (nodemon prints "starting `node src/server.js`" and
 * then nothing — no "listening on :PORT", no error) is exactly what an
 * uncaught synchronous throw or an unhandled promise rejection during
 * module load looks like when nothing is listening for it: Node's default
 * behavior for an uncaughtException IS to print a stack trace and exit,
 * but if something upstream (a wrapper script, a misbehaving library) or a
 * future refactor ever attaches an empty/no-op handler, that default is
 * silently lost. These two listeners are placed as the very first thing
 * this file does — before any other import's top-level code has a chance
 * to run — specifically so a broken dependency load (a native binding
 * mismatch, a bad optional dependency, etc.) prints its full stack trace
 * instead of vanishing. uncaughtException logs and exits(1) — Node's own
 * guidance is that the process is in an undefined state afterward and
 * should not keep running; nodemon will auto-restart it in dev.
 * unhandledRejection only logs — most such rejections here are recoverable
 * (an individual Baileys session's own promise chain), and process-wide
 * exit-on-every-rejection is unnecessarily aggressive for a multi-tenant
 * service where one account's failure must not take down every other
 * account's live session.
 */
process.on('uncaughtException', (err) => {
  console.error('[server] FATAL uncaughtException — this process cannot continue safely:', err);
  process.exit(1);
});

process.on('unhandledRejection', (reason) => {
  console.error('[server] unhandledRejection (process continues running):', reason);
});

const PORT = process.env.PORT || 4000;
// [Windows IPv6/IPv4 resolution fix, disclosed]: binding explicitly to an
// IPv4 literal instead of leaving the host unspecified removes any
// ambiguity about which interface this process is actually reachable on.
// Pairs with the matching change on the Laravel side (config/services.php
// qr_engine.url default: 'localhost' -> '127.0.0.1') — 'localhost' can
// resolve to the IPv6 loopback (::1) first on Windows, and if this
// process were only listening on IPv4, that would cost a slow
// connect-then-fallback delay (or an outright failure) before the caller
// route to 127.0.0.1. Overridable via HOST for a LAN-reachable deployment.
const HOST = process.env.HOST || '0.0.0.0';
const FRONTEND_ORIGIN = process.env.FRONTEND_ORIGIN || 'http://localhost:5173';
// [Local-dev secret fallback, disclosed]: if INTERNAL_API_SECRET is not
// set in qr-engine-service/.env AND this process is not explicitly marked
// production (NODE_ENV=production), fall back to the SAME placeholder
// value already committed in both backend-api/.env.example and
// qr-engine-service/.env.example (INTERNAL_API_SECRET=devsecret_...) —
// reusing that exact value instead of inventing a second, different
// hardcoded string means there is only ever one well-known dev secret to
// reason about. This mirrors backend-api's own config/services.php
// fallback, which is gated the same way (there: APP_ENV=local).
//
// IMPORTANT — this does not fully satisfy "strictly fails closed in
// production" by itself: NODE_ENV is not read anywhere else in this repo,
// and nothing here (no Dockerfile, no PM2/ecosystem config) currently sets
// NODE_ENV=production for a real deployment. Until your actual production
// process explicitly sets NODE_ENV=production, this guard provides NO
// protection there — a missing .env in that environment would silently
// use this same public, git-committed placeholder instead of rejecting
// requests as it did before this change. Set NODE_ENV=production wherever
// this service actually runs outside your own machine.
const IS_PRODUCTION = process.env.NODE_ENV === 'production';
const LOCAL_DEV_FALLBACK_SECRET = 'devsecret_9f3a1c7b2e4d6a8f0b1c3d5e7f9a0b2c';
const INTERNAL_API_SECRET = process.env.INTERNAL_API_SECRET
  || (IS_PRODUCTION ? '' : LOCAL_DEV_FALLBACK_SECRET);

if (!process.env.INTERNAL_API_SECRET && !IS_PRODUCTION) {
  console.warn(
    '[server] INTERNAL_API_SECRET is not set in qr-engine-service/.env — ' +
      'falling back to the shared LOCAL-DEV-ONLY secret (soft warning, not ' +
      'a rejection). Requests are accepted as long as backend-api resolves ' +
      'to the same fallback value (true whenever backend-api/.env also has ' +
      'no INTERNAL_API_SECRET set while APP_ENV=local there). Create a real ' +
      'qr-engine-service/.env with your own secret before this runs ' +
      'anywhere but your own machine.',
  );
}

const app = express();
app.use(cors({ origin: FRONTEND_ORIGIN }));
app.use(express.json());

// [Diagnostic logging, disclosed]: confirms whether a request from
// Laravel is reaching this process at all — the exact symptom reported
// ("no incoming request logs appear in the Node terminal") is otherwise
// impossible to distinguish from "Laravel never sent the request" versus
// "Laravel sent it but it never arrived" versus "it arrived but a
// downstream handler swallowed it silently". Placed before every route.
app.use((req, res, next) => {
  console.log(`[INCOMING REQUEST] ${req.method} ${req.originalUrl}`);
  next();
});

const httpServer = http.createServer(app);
const io = new SocketIOServer(httpServer, {
  cors: { origin: FRONTEND_ORIGIN },
});

function room(accountId) {
  return `account:${accountId}`;
}

function broadcast(accountId, payload) {
  io.to(room(accountId)).emit('connection:update', payload);
}

/**
 * Socket.IO auth: the browser connects with { accountId, token } (its
 * Sanctum Bearer token, forwarded from frontend-app's AuthContext). We ask
 * backend-api who that token belongs to and only join the account's room
 * if it matches — this is what stops one tenant's browser from ever
 * receiving another tenant's QR code / connection status.
 */
io.use(async (socket, next) => {
  const { accountId, token } = socket.handshake.auth ?? {};

  if (!accountId || !token) {
    return next(new Error('accountId and token are required'));
  }

  try {
    const allowed = await verifyAccountAccess(token, accountId);
    if (!allowed) {
      return next(new Error('forbidden'));
    }
    socket.accountId = String(accountId);
    next();
  } catch {
    next(new Error('token verification failed'));
  }
});

io.on('connection', (socket) => {
  socket.join(room(socket.accountId));

  // Catch the client up immediately rather than making it wait for the
  // next Baileys event, which may never come if the session is idle/stable.
  socket.emit('connection:update', {
    status: getStatus(socket.accountId),
    qr: getQr(socket.accountId),
  });
});

/**
 * Only backend-api should ever be able to call these two routes.
 *
 * [Diagnostic logging added, disclosed]: this never used to log a
 * rejection at all, so a secret mismatch between this process and
 * backend-api was invisible from qr-engine-service's own console — the
 * only symptom was a bare 401 on the Laravel side. The most common cause
 * in practice: INTERNAL_API_SECRET is loaded once at process startup
 * (`import 'dotenv/config'` in server.js) — editing .env after this
 * process is already running does NOT take effect until it is
 * restarted. Neither branch below ever logs the secret values
 * themselves, only which failure mode occurred.
 */
function requireInternalSecret(req, res, next) {
  if (!INTERNAL_API_SECRET) {
    console.warn(
      '[server] Rejected an internal request: this process has no INTERNAL_API_SECRET loaded (env var is empty/unset). ' +
        'If you just created or edited qr-engine-service/.env, restart this process — dotenv only loads .env once, at startup.',
    );
    return res.status(401).json({ message: 'Unauthorized.' });
  }

  const provided = req.header('X-Internal-Secret');

  // [Fix, disclosed]: constant-time comparison via crypto.timingSafeEqual
  // instead of !== , to avoid a byte-by-byte timing side channel on the
  // shared secret (mirrors VerifyInternalSecret.php's use of
  // hash_equals() on the Laravel side, which this repo already relied on
  // for the reverse direction — status/inbound notifications FROM this
  // process TO backend-api never send a comparable header here, so this
  // was the one place still using a plain string comparison for the
  // secret check). Buffers must be equal length for timingSafeEqual, so
  // a length mismatch is checked first and short-circuits to unequal
  // without leaking timing on length itself (both branches return the
  // same 401 either way).
  const providedBuf = Buffer.from(provided ?? '', 'utf8');
  const expectedBuf = Buffer.from(INTERNAL_API_SECRET, 'utf8');
  const matches =
    providedBuf.length === expectedBuf.length && crypto.timingSafeEqual(providedBuf, expectedBuf);

  if (!matches) {
    console.warn(
      `[server] Rejected an internal request from ${req.ip}: X-Internal-Secret header ${
        provided ? 'did not match this process\'s INTERNAL_API_SECRET' : 'was missing'
      }. If backend-api/.env's INTERNAL_API_SECRET was changed recently, restart BOTH backend-api and this process so they agree again.`,
    );
    return res.status(401).json({ message: 'Unauthorized.' });
  }

  next();
}

app.get('/health', (req, res) => res.json({ status: 'ok' }));

app.post('/api/qr/start-session', requireInternalSecret, async (req, res) => {
  const accountId = req.body?.account_id;
  if (!accountId) {
    return res.status(422).json({ message: 'account_id is required.' });
  }

  try {
    const status = await startSession(accountId, broadcast);
    res.status(202).json({ message: 'Session starting.', status });
  } catch (err) {
    console.error(`[server] start-session failed for account_id=${accountId}:`, err);
    res.status(500).json({ message: 'Failed to start WhatsApp session.' });
  }
});

app.post('/api/qr/logout', requireInternalSecret, async (req, res) => {
  const accountId = req.body?.account_id;
  if (!accountId) {
    return res.status(422).json({ message: 'account_id is required.' });
  }

  try {
    await logoutSession(accountId, broadcast);
    res.json({ message: 'Session ended.' });
  } catch (err) {
    console.error(`[server] logout failed for account_id=${accountId}:`, err);
    res.status(500).json({ message: 'Failed to end WhatsApp session.' });
  }
});

/**
 * POST /api/message/send — called by backend-api's BaileysDriver.
 * Body: { account_id, to, message }, "to" already a full JID
 * ("<digits>@s.whatsapp.net", built by BaileysDriver).
 * Response: { success: true, message_id } on success, or
 * { success: false, error } (HTTP 422) otherwise — including the
 * "Session disconnected" case when no connected session exists for
 * account_id.
 */
// Developer API Platform for WhatsApp Group Creation & Unified
// Messaging -- optional media_type/media_url/caption/filename fields,
// forwarded verbatim by backend-api's BaileysDriver (which spreads its
// $metaData param straight into this POST body -- see that class and
// App\Support\WhatsAppMediaPayloadBuilder on the backend-api side).
// `message` remains required for a plain text send, but is now OPTIONAL
// when a media_type/media_url pair is present (a media message's
// caption is carried separately, in `caption`, matching Meta's own
// convention of caption-is-part-of-the-media-object rather than a
// separate text body).
app.post('/api/message/send', requireInternalSecret, async (req, res) => {
  const { account_id: accountId, to, message, media_type: mediaType, media_url: mediaUrl, caption, filename } = req.body ?? {};
  const media = mediaType && mediaUrl ? { type: mediaType, url: mediaUrl, caption, filename } : null;

  if (!accountId || !to || (!message && !media)) {
    return res.status(422).json({ success: false, error: 'account_id, to, and either message or media_type+media_url are required.' });
  }

  try {
    const result = await sendMessage(accountId, to, message, media);
    // A bad/unreachable media_url is the caller's fault, not a session or
    // Baileys problem -- surfaced as 400 (Bad Request) instead of the
    // generic 422 used for every other failure (e.g. 'Session
    // disconnected'). backend-api's BaileysDriver only branches on the
    // JSON 'success' flag, never the HTTP status class, so this is purely
    // a clearer contract for direct callers of this endpoint -- it does
    // not change how backend-api itself behaves on failure.
    const status = result.success ? 200 : result.code === 'MEDIA_UNREACHABLE' ? 400 : 422;
    res.status(status).json(result);
  } catch (err) {
    console.error(`[server] send-message failed for account_id=${accountId}:`, err);
    res.status(500).json({ success: false, error: 'Internal error while sending the message.' });
  }
});

/**
 * POST /api/group/create — Native WhatsApp Group Re-Architecture, called
 * by backend-api's NativeWhatsAppGroupService.
 * Body: { account_id, subject, participants: ["<digits>@s.whatsapp.net", ...] }
 * (already full JIDs, built by NativeWhatsAppGroupService — same
 * division of responsibility as /api/message/send's "to" field above).
 * Response: { success: true, jid, invite_link } on success, or
 * { success: false, error } (HTTP 422) otherwise — same shape convention
 * as /api/message/send.
 */
app.post('/api/group/create', requireInternalSecret, async (req, res) => {
  const { account_id: accountId, subject, participants } = req.body ?? {};
  if (!accountId || !subject || !Array.isArray(participants) || participants.length === 0) {
    return res.status(422).json({ success: false, error: 'account_id, subject, and a non-empty participants array are required.' });
  }

  try {
    const result = await createGroup(accountId, subject, participants);
    res.status(result.success ? 200 : 422).json(result);
  } catch (err) {
    console.error(`[server] group-create failed for account_id=${accountId}:`, err);
    res.status(500).json({ success: false, error: 'Internal error while creating the WhatsApp group.' });
  }
});

/**
 * POST /api/group/add-participants — Native WhatsApp Group
 * Re-Architecture, called by backend-api's NativeWhatsAppGroupService.
 * Body: { account_id, group_jid, participants: ["<digits>@s.whatsapp.net", ...] }.
 * Response: { success: true } on success, or { success: false, error }
 * (HTTP 422) otherwise.
 */
app.post('/api/group/add-participants', requireInternalSecret, async (req, res) => {
  const { account_id: accountId, group_jid: groupJid, participants } = req.body ?? {};
  if (!accountId || !groupJid || !Array.isArray(participants) || participants.length === 0) {
    return res.status(422).json({ success: false, error: 'account_id, group_jid, and a non-empty participants array are required.' });
  }

  try {
    const result = await addGroupParticipants(accountId, groupJid, participants);
    res.status(result.success ? 200 : 422).json(result);
  } catch (err) {
    console.error(`[server] group-add-participants failed for account_id=${accountId}:`, err);
    res.status(500).json({ success: false, error: 'Internal error while adding participants to the WhatsApp group.' });
  }
});

/**
 * GET /api/group/list — Native WhatsApp Group Re-Architecture, "select
 * an existing group" extension, called by backend-api's
 * NativeWhatsAppGroupService::listGroups(). Query: ?account_id=X.
 * Read-only: lists every real WhatsApp group the connected account is
 * CURRENTLY a participant of (via Baileys' groupFetchAllParticipating()),
 * so backend-api can offer a picker instead of only ever creating brand
 * new groups. Response: { success: true, groups: [{ jid, subject,
 * participants_count }] } on success, or { success: false, error }
 * (HTTP 422) otherwise — same shape convention as the other /api/group/*
 * routes.
 */
app.get('/api/group/list', requireInternalSecret, async (req, res) => {
  const accountId = req.query.account_id;
  if (!accountId) {
    return res.status(422).json({ success: false, error: 'account_id is required.' });
  }

  try {
    const result = await listGroups(accountId);
    res.status(result.success ? 200 : 422).json(result);
  } catch (err) {
    console.error(`[server] group-list failed for account_id=${accountId}:`, err);
    res.status(500).json({ success: false, error: 'Internal error while listing WhatsApp groups.' });
  }
});

/**
 * GET /api/group/metadata — Native WhatsApp Group Re-Architecture,
 * "select an existing group" extension. Query: ?account_id=X&group_jid=Y.
 * Read-only: full Baileys metadata (including the current participant
 * list) for ONE group, fetched right after the user picks it from
 * /api/group/list's summary — so backend-api's import step can populate
 * the new ContactGroup's members from the real group instead of starting
 * at 0. Response: { success: true, jid, subject, participants: [{ jid,
 * is_admin }] } on success, or { success: false, error } (HTTP 422)
 * otherwise.
 */
app.get('/api/group/metadata', requireInternalSecret, async (req, res) => {
  const accountId = req.query.account_id;
  const groupJid = req.query.group_jid;
  if (!accountId || !groupJid) {
    return res.status(422).json({ success: false, error: 'account_id and group_jid are required.' });
  }

  try {
    const result = await getGroupMetadata(accountId, groupJid);
    res.status(result.success ? 200 : 422).json(result);
  } catch (err) {
    console.error(`[server] group-metadata failed for account_id=${accountId} group_jid=${groupJid}:`, err);
    res.status(500).json({ success: false, error: 'Internal error while fetching this group\'s details.' });
  }
});

// [Disclosed]: an explicit 'error' listener on the server itself — not
// just the process-wide uncaughtException handler above — because
// http.Server emits 'error' (e.g. EADDRINUSE when port 4000 is already
// held by a leftover process from a previous run) as an ordinary event,
// not a thrown exception; without a listener for it, EventEmitter's
// default behavior is to throw it asynchronously, which the
// uncaughtException handler above WOULD still catch, but naming the
// likely cause (address already in use) here gives a much faster answer
// than a bare stack trace does.
httpServer.on('error', (err) => {
  if (err?.code === 'EADDRINUSE') {
    console.error(
      `[server] FATAL: port ${PORT} is already in use on ${HOST} — another process (maybe a previous run of this ` +
        'service that never fully exited) is already listening there. Find and stop it, then restart this process.',
    );
  } else {
    console.error('[server] FATAL: failed to start listening:', err);
  }
  process.exit(1);
});

httpServer.listen(PORT, HOST, () => {
  console.log(`qr-engine-service listening on ${HOST}:${PORT}`);
});

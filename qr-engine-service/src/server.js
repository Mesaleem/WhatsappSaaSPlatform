import 'dotenv/config';
import http from 'node:http';
import express from 'express';
import cors from 'cors';
import { Server as SocketIOServer } from 'socket.io';
import { startSession, logoutSession, getStatus, getQr, sendMessage } from './services/sessionManager.js';
import { verifyAccountAccess } from './services/backendClient.js';

const PORT = process.env.PORT || 4000;
const FRONTEND_ORIGIN = process.env.FRONTEND_ORIGIN || 'http://localhost:5173';
const INTERNAL_API_SECRET = process.env.INTERNAL_API_SECRET || '';

const app = express();
app.use(cors({ origin: FRONTEND_ORIGIN }));
app.use(express.json());

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

/** Only backend-api should ever be able to call these two routes. */
function requireInternalSecret(req, res, next) {
  if (!INTERNAL_API_SECRET || req.header('X-Internal-Secret') !== INTERNAL_API_SECRET) {
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
app.post('/api/message/send', requireInternalSecret, async (req, res) => {
  const { account_id: accountId, to, message } = req.body ?? {};
  if (!accountId || !to || !message) {
    return res.status(422).json({ success: false, error: 'account_id, to, and message are required.' });
  }

  try {
    const result = await sendMessage(accountId, to, message);
    res.status(result.success ? 200 : 422).json(result);
  } catch (err) {
    console.error(`[server] send-message failed for account_id=${accountId}:`, err);
    res.status(500).json({ success: false, error: 'Internal error while sending the message.' });
  }
});

httpServer.listen(PORT, () => {
  console.log(`qr-engine-service listening on :${PORT}`);
});

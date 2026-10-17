/**
 * Fix for "Waiting for this message. This may take a while." (2026-10-17).
 *
 * When a phone cannot decrypt a message sent from a linked device, it sends a
 * retry receipt. Baileys answers by re-encrypting the ORIGINAL message on a
 * fresh session, but it can only do that if the socket's getMessage(key)
 * returns that message. Baileys' default getMessage returns undefined, so every
 * retry was dropped ("recv retry request, but message not available") and the
 * recipient, and the account's own phone, showed the placeholder forever.
 *
 * Sent messages are kept here per account (in memory, bounded) so getMessage
 * can return them. The store is per account, not per socket, so a reconnect
 * can still answer retries for messages sent before it. A restart forgets the
 * store; retries for messages sent before a restart still cannot be answered.
 */

const MAX_PER_ACCOUNT = 1000;
const TTL_MS = 3 * 24 * 60 * 60 * 1000;

// accountId -> Map(messageId -> { message, at }); Map keeps insertion order, oldest first.
const stores = new Map();

function storeFor(accountId) {
  const id = String(accountId);
  if (!stores.has(id)) stores.set(id, new Map());
  return stores.get(id);
}

/** Remembers a message this account sent (a Baileys WAMessage: { key, message }). */
export function rememberSentMessage(accountId, sent, now = Date.now()) {
  const messageId = sent?.key?.id;
  if (!messageId || !sent.message) return;

  const store = storeFor(accountId);
  store.delete(messageId);
  store.set(messageId, { message: sent.message, at: now });

  while (store.size > MAX_PER_ACCOUNT) {
    store.delete(store.keys().next().value);
  }
}

/** The message for a retry receipt's key, or undefined when it is unknown or too old. */
export function recallSentMessage(accountId, key, now = Date.now()) {
  const store = stores.get(String(accountId));
  const entry = key?.id ? store?.get(key.id) : undefined;
  if (!entry) return undefined;
  if (now - entry.at > TTL_MS) {
    store.delete(key.id);
    return undefined;
  }
  return entry.message;
}

/** Drops an account's sent messages (logout). */
export function forgetSentMessages(accountId) {
  stores.delete(String(accountId));
}

/**
 * The sender's phone number (digits) for an inbound 1:1 message, or null.
 *
 * WhatsApp now addresses many chats by LID (a private id, "<digits>@lid")
 * instead of the phone number. Such a message's remoteJid is the LID, and the
 * real number is in key.senderPn ("<digits>@s.whatsapp.net"). These were
 * dropped before, because only "@s.whatsapp.net" chats were forwarded, so
 * replies from those contacts never reached the inbox or the chatbot.
 */
export function inboundSenderPhone(key) {
  const remoteJid = key?.remoteJid || '';
  let jid = null;
  if (remoteJid.endsWith('@s.whatsapp.net')) {
    jid = remoteJid;
  } else if (remoteJid.endsWith('@lid')) {
    jid = [key.senderPn, key.remoteJidAlt].find((candidate) => String(candidate || '').endsWith('@s.whatsapp.net')) || null;
  }
  if (!jid) return null;

  // "<digits>[:device]@s.whatsapp.net" -> digits
  const phone = jid.split('@')[0].split(':')[0].replace(/\D/g, '');
  return phone || null;
}

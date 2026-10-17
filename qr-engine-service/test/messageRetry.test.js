import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  rememberSentMessage,
  recallSentMessage,
  forgetSentMessages,
  inboundSenderPhone,
  resolveSendJid,
} from '../src/services/messageRetry.js';

const sent = (id, text) => ({ key: { id, remoteJid: '917236062374@s.whatsapp.net', fromMe: true }, message: { conversation: text } });

test('a retry receipt gets the original message back', () => {
  rememberSentMessage('1', sent('A1', 'hello'));
  assert.deepEqual(recallSentMessage('1', { id: 'A1' }), { conversation: 'hello' });
});

test('messages are kept per account', () => {
  rememberSentMessage('2', sent('B1', 'mine'));
  assert.equal(recallSentMessage('3', { id: 'B1' }), undefined);
});

test('an unknown or expired message is not returned', () => {
  const now = Date.now();
  rememberSentMessage('4', sent('C1', 'old'), now);
  assert.equal(recallSentMessage('4', { id: 'nope' }, now), undefined);
  assert.equal(recallSentMessage('4', { id: 'C1' }, now + 4 * 24 * 60 * 60 * 1000), undefined);
});

test('the store is bounded and drops the oldest first', () => {
  for (let i = 0; i < 1005; i += 1) rememberSentMessage('5', sent(`D${i}`, `m${i}`));
  assert.equal(recallSentMessage('5', { id: 'D0' }), undefined);
  assert.deepEqual(recallSentMessage('5', { id: 'D1004' }), { conversation: 'm1004' });
});

test('logout forgets the account', () => {
  rememberSentMessage('6', sent('E1', 'x'));
  forgetSentMessages('6');
  assert.equal(recallSentMessage('6', { id: 'E1' }), undefined);
});

test('a message without content is not stored', () => {
  rememberSentMessage('7', { key: { id: 'F1' } });
  assert.equal(recallSentMessage('7', { id: 'F1' }), undefined);
});

test('inbound sender: phone-number chat', () => {
  assert.equal(inboundSenderPhone({ remoteJid: '917236062374@s.whatsapp.net' }), '917236062374');
  assert.equal(inboundSenderPhone({ remoteJid: '917236062374:12@s.whatsapp.net' }), '917236062374');
});

test('inbound sender: LID chat uses senderPn', () => {
  assert.equal(
    inboundSenderPhone({ remoteJid: '123456789012345@lid', senderPn: '917236062374@s.whatsapp.net' }),
    '917236062374',
  );
});

test('inbound sender: LID chat without a phone, groups and broadcasts are skipped', () => {
  assert.equal(inboundSenderPhone({ remoteJid: '123456789012345@lid' }), null);
  assert.equal(inboundSenderPhone({ remoteJid: '120363000000000000@g.us', senderPn: '917236062374@s.whatsapp.net' }), null);
  assert.equal(inboundSenderPhone({ remoteJid: 'status@broadcast' }), null);
  assert.equal(inboundSenderPhone(undefined), null);
});

test('send address: a contact with a LID is sent to its LID', async () => {
  const lookup = async (pn) => (pn === '917236062374@s.whatsapp.net' ? '222350507286561@lid' : null);
  assert.equal(await resolveSendJid(lookup, '917236062374@s.whatsapp.net'), '222350507286561@lid');
});

test('send address: no LID, a failed or slow lookup keeps the phone number', async () => {
  const pn = '919876543210@s.whatsapp.net';
  assert.equal(await resolveSendJid(async () => null, pn), pn);
  assert.equal(await resolveSendJid(async () => { throw new Error('usync failed'); }, pn), pn);
  assert.equal(await resolveSendJid(() => new Promise(() => {}), pn, 50), pn);
  assert.equal(await resolveSendJid(undefined, pn), pn);
});

test('send address: groups and LIDs are never looked up', async () => {
  let called = false;
  const lookup = async () => { called = true; return '1@lid'; };
  assert.equal(await resolveSendJid(lookup, '120363000000000000@g.us'), '120363000000000000@g.us');
  assert.equal(await resolveSendJid(lookup, '222350507286561@lid'), '222350507286561@lid');
  assert.equal(called, false);
});

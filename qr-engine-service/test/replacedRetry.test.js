import { test } from 'node:test';
import assert from 'node:assert/strict';
import { nextReplacedRetryDelay, resetReplacedRetries, REPLACED_RETRY_DELAYS_MS } from '../src/services/replacedRetry.js';

test('a replaced session is retried with growing delays, then given up', () => {
  const now = 1_000_000;
  assert.equal(nextReplacedRetryDelay('a', now), REPLACED_RETRY_DELAYS_MS[0]);
  assert.equal(nextReplacedRetryDelay('a', now + 1), REPLACED_RETRY_DELAYS_MS[1]);
  assert.equal(nextReplacedRetryDelay('a', now + 2), REPLACED_RETRY_DELAYS_MS[2]);
  assert.equal(nextReplacedRetryDelay('a', now + 3), null, 'two engines running side by side must not fight forever');
});

test('the limit is per session and resets after the window or a logout', () => {
  const now = 2_000_000;
  for (let i = 0; i < 3; i += 1) nextReplacedRetryDelay('b', now);
  assert.equal(nextReplacedRetryDelay('b', now), null);
  assert.equal(nextReplacedRetryDelay('c', now), REPLACED_RETRY_DELAYS_MS[0], 'another session is unaffected');
  assert.equal(nextReplacedRetryDelay('b', now + 10 * 60 * 1000 + 1), REPLACED_RETRY_DELAYS_MS[0], 'a new deploy later is retried again');

  for (let i = 0; i < 3; i += 1) nextReplacedRetryDelay('d', now);
  resetReplacedRetries('d');
  assert.equal(nextReplacedRetryDelay('d', now), REPLACED_RETRY_DELAYS_MS[0]);
});

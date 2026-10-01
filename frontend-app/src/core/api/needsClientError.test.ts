import type { AxiosError } from 'axios';
import { describe, expect, it } from 'vitest';
import { friendlyNeedsClientError, NEEDS_CLIENT_MESSAGE } from './axiosInstance';

const err = (status: number, message: string) => ({ response: { status, data: { message } } }) as unknown as AxiosError<{ message: string }>;

describe('friendlyNeedsClientError', () => {
  it.each([
    'Select a client/tenant account first (pass ?account_id=).',
    'Select a client from the header to manage their chatbot.',
    'A WhatsApp session requires a selected tenant account (pass ?account_id=).',
    'Super Admin has no tenant account to bill.',
  ])('rewrites %s when no client is selected', (message) => {
    const e = friendlyNeedsClientError(err(422, message) as never, false);
    expect(e.response?.data?.message).toBe(NEEDS_CLIENT_MESSAGE);
    expect(e.response?.status).toBe(422);
  });

  it('leaves the message alone when a client is selected, for other statuses and for other messages', () => {
    expect(friendlyNeedsClientError(err(422, 'Select a client/tenant account first.') as never, true).response?.data?.message).toBe('Select a client/tenant account first.');
    expect(friendlyNeedsClientError(err(403, 'Select a client first.') as never, false).response?.data?.message).toBe('Select a client first.');
    expect(friendlyNeedsClientError(err(422, 'The q field is required.') as never, false).response?.data?.message).toBe('The q field is required.');
  });
});

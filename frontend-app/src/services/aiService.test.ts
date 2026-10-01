import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import aiService from './aiService';
import axiosInstance from '../core/api/axiosInstance';

/**
 * Phase 8 Task 6 — Ad Copywriter generation is metered AI. Every generation
 * carries an Idempotency-Key so a retry of the same request can never be
 * charged twice, while a new click is a new generation.
 */

vi.mock('../core/api/axiosInstance', () => ({ default: { post: vi.fn() } }));

const post = axiosInstance.post as unknown as Mock;

const payload = {
  business_name: 'Sunrise Dental',
  target_industry: 'Healthcare',
  offer_details: '',
  target_goal: 'PAID_LEAD_AD' as const,
  tone: 'Professional' as const,
};

beforeEach(() => {
  post.mockReset();
  post.mockResolvedValue({ data: { data: { provider: 'openai', variants: [] } } });
});

describe('aiService.generate', () => {
  it('posts the unchanged payload to the unchanged endpoint and unwraps data', async () => {
    const result = await aiService.generate(payload);

    expect(result).toEqual({ provider: 'openai', variants: [] });
    expect(post).toHaveBeenCalledWith('/social/ai/generate', payload, expect.any(Object));
  });

  it('sends a fresh Idempotency-Key per generation', async () => {
    await aiService.generate(payload);
    await aiService.generate(payload);

    const keys = post.mock.calls.map((call) => call[2].headers['Idempotency-Key'] as string);
    expect(keys[0]).toMatch(/^adcopy-[A-Za-z0-9-]+$/);
    expect(keys[0]).not.toEqual(keys[1]);
  });

  it('reuses a given key for a retry of the same generation', async () => {
    await aiService.generate(payload, 'adcopy-retry-1');
    await aiService.generate(payload, 'adcopy-retry-1');

    expect(post.mock.calls.map((call) => call[2].headers['Idempotency-Key'])).toEqual(['adcopy-retry-1', 'adcopy-retry-1']);
  });
});

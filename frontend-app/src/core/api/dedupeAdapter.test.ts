import axios, { type AxiosAdapter, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios';
import { describe, expect, it, vi } from 'vitest';

import { dedupeGetAdapter } from './dedupeAdapter';

/** Identical GETs in flight together share one call; anything else goes through untouched. */

function fakeBase() {
  const pending: ((v: AxiosResponse) => void)[] = [];
  const base = vi.fn<AxiosAdapter>(
    (config: InternalAxiosRequestConfig) =>
      new Promise<AxiosResponse>((resolve) => {
        // Writes answer at once; GETs wait for finish().
        if (config.method !== 'get') resolve({ data: '{"ok":true}', status: 200, statusText: 'OK', headers: {}, config });
        else pending.push((v) => resolve({ ...v, config }));
      }),
  );
  /** Lets axios reach the adapter (it does so asynchronously), then answers every waiting GET. */
  const finish = async (body: string) => {
    await new Promise((r) => setTimeout(r, 0));
    pending.splice(0).forEach((r) => r({ data: body, status: 200, statusText: 'OK', headers: {}, config: {} as InternalAxiosRequestConfig }));
  };
  return { base, finish };
}

describe('dedupeGetAdapter', () => {
  it('joins identical GETs in flight and gives each caller its own parsed copy', async () => {
    const { base, finish } = fakeBase();
    const client = axios.create({ baseURL: 'http://api.test', adapter: dedupeGetAdapter(base) });

    const a = client.get('/admin/module-offers');
    const b = client.get('/admin/module-offers');
    const c = client.get('/admin/module-offers', { params: { page: 2 } });
    await finish('{"data":[1,2]}');
    const [ra, rb] = await Promise.all([a, b, c]);

    expect(base).toHaveBeenCalledTimes(2); // a+b joined, c separate
    expect(ra.data).toEqual({ data: [1, 2] });
    expect(rb.data).toEqual({ data: [1, 2] });
    expect(ra.data).not.toBe(rb.data); // parsed separately, safe to mutate
  });

  it('never joins writes, cancellable requests, or a request after the first finished', async () => {
    const { base, finish } = fakeBase();
    const client = axios.create({ baseURL: 'http://api.test', adapter: dedupeGetAdapter(base) });

    await Promise.all([client.post('/x', {}), client.post('/x', {})]);
    expect(base).toHaveBeenCalledTimes(2);

    const ctrl = new AbortController();
    const y1 = client.get('/y', { signal: ctrl.signal });
    const y2 = client.get('/y', { signal: ctrl.signal });
    await finish('{}');
    await Promise.all([y1, y2]);
    expect(base).toHaveBeenCalledTimes(4);

    const first = client.get('/z');
    await finish('{}');
    await first;
    const second = client.get('/z');
    await finish('{}');
    await second;
    expect(base).toHaveBeenCalledTimes(6);
  });
});

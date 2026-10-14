import axios, { type AxiosAdapter, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios';

/**
 * Scale (2026-10-14): identical GET requests that are in flight at the same moment share ONE
 * network call. Two cards asking for the same list, a page and its header asking for the same
 * thing, React StrictMode's double mount — each used to cost the server a request.
 *
 * Only GETs with the same URL, params, signed-in user and response type are joined, and only
 * while the first is still running (nothing is cached afterwards, so data is never stale).
 * This sits at the adapter level, so the raw body is shared but every caller still runs its own
 * response transform — each one gets its own parsed object and can change it freely.
 * A request with an abort signal or cancel token is never joined, so one caller cancelling
 * cannot cancel another's request.
 */
export function dedupeGetAdapter(base: AxiosAdapter): AxiosAdapter {
  const inFlight = new Map<string, Promise<AxiosResponse>>();

  return (config: InternalAxiosRequestConfig) => {
    if ((config.method ?? 'get').toLowerCase() !== 'get' || config.signal || config.cancelToken) {
      return base(config);
    }

    const auth = config.headers?.Authorization ?? config.headers?.authorization ?? '';
    let params = '';
    try {
      params = JSON.stringify(config.params ?? null);
    } catch {
      return base(config);
    }
    const key = [config.baseURL ?? '', config.url ?? '', params, String(auth), config.responseType ?? ''].join('|');

    let shared = inFlight.get(key);
    if (!shared) {
      shared = base(config).finally(() => inFlight.delete(key));
      inFlight.set(key, shared);
    }
    // Every caller (the first one too) gets its own copy with its own config: axios writes the
    // parsed body back onto the response object, so a shared object would hand every caller the
    // same parsed data.
    return shared.then((res) => ({ ...res, data: ownCopy(res.data), config }));
  };
}

/** Raw bodies are strings (immutable); anything already parsed is deep-copied. */
function ownCopy<T>(data: T): T {
  if (data === null || typeof data !== 'object') return data;
  if (typeof Blob !== 'undefined' && data instanceof Blob) return data;
  if (data instanceof ArrayBuffer) return data.slice(0) as T;
  try {
    return structuredClone(data);
  } catch {
    return data;
  }
}

/** The browser's default axios adapter, wrapped. */
export function defaultDedupedAdapter(): AxiosAdapter {
  return dedupeGetAdapter(axios.getAdapter(axios.defaults.adapter));
}

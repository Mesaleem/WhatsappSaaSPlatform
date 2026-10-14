/*
 * Load test — "can the server take 250 people at once?" (k6, https://k6.io)
 *
 *   k6 run -e BASE=https://your-domain/api -e EMAIL=loadtest@example.com -e PASSWORD=... load-test/k6-dashboard.js
 *
 * Two kinds of traffic, together:
 *  - website visitors: home/pricing content, plans, the analytics tracker (no login)
 *  - signed-in clients: /auth/me and the dashboard calls, a page every few seconds
 *
 * Ramps to VUS (default 250) signed-in users plus a third as many website visitors, holds for
 * HOLD (default 3m), then ramps down. It FAILS if more than 1% of requests fail or 95% of
 * requests are slower than 1.5 s — the "nobody waits" bar.
 *
 * Use a dedicated test client account (one login is shared by every virtual user, since the app
 * allows one session per user). Run it against a staging copy, or at a quiet hour: it is real
 * load. The tracker events it sends are marked with a fixed user agent, so filter them out of the
 * Website Analytics report afterwards (or delete them: source "k6").
 */
import http from 'k6/http';
import { check, sleep } from 'k6';
import { uuidv4 } from 'https://jslib.k6.io/k6-utils/1.4.0/index.js';

const BASE = (__ENV.BASE || 'http://localhost:8000/api').replace(/\/$/, '');
const VUS = Number(__ENV.VUS || 250);
const HOLD = __ENV.HOLD || '3m';

export const options = {
  scenarios: {
    clients: {
      executor: 'ramping-vus',
      exec: 'client',
      stages: [
        { duration: '1m', target: VUS },
        { duration: HOLD, target: VUS },
        { duration: '30s', target: 0 },
      ],
    },
    visitors: {
      executor: 'ramping-vus',
      exec: 'visitor',
      stages: [
        { duration: '1m', target: Math.ceil(VUS / 3) },
        { duration: HOLD, target: Math.ceil(VUS / 3) },
        { duration: '30s', target: 0 },
      ],
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<1500'],
    'http_req_duration{kind:client}': ['p(95)<1500'],
    'http_req_duration{kind:website}': ['p(95)<800'],
  },
};

export function setup() {
  if (!__ENV.EMAIL || !__ENV.PASSWORD) {
    throw new Error('Pass -e EMAIL=... -e PASSWORD=... for a test client account.');
  }
  const res = http.post(`${BASE}/auth/login`, JSON.stringify({ email: __ENV.EMAIL, password: __ENV.PASSWORD }), {
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  });
  check(res, { 'logged in': (r) => r.status === 200 });
  return { token: res.json('token') };
}

const DASHBOARD = ['/auth/me', '/dashboard/overview', '/analytics/summary', '/analytics/charts?range=7', '/message-logs?page=1&per_page=5', '/notifications/inbox/unread-count'];

export function client(data) {
  const params = { headers: { Authorization: `Bearer ${data.token}`, Accept: 'application/json' }, tags: { kind: 'client' } };
  // A page opens: the dashboard's calls go out together, as the browser sends them.
  const responses = http.batch(DASHBOARD.map((path) => ['GET', `${BASE}${path}`, null, params]));
  responses.forEach((r, i) => check(r, { [`${DASHBOARD[i]} ok`]: (x) => x.status === 200 }));
  sleep(3 + Math.random() * 4); // reading the page
}

export function visitor() {
  const params = { headers: { Accept: 'application/json', 'User-Agent': 'Mozilla/5.0 k6-loadtest' }, tags: { kind: 'website' } };
  const [site, plans] = http.batch([
    ['GET', `${BASE}/public/site`, null, params],
    ['GET', `${BASE}/public/plans`, null, params],
  ]);
  check(site, { 'site ok': (r) => r.status === 200 });
  check(plans, { 'plans ok': (r) => r.status === 200 });

  const session = uuidv4();
  const view = uuidv4();
  const event = (body) => http.post(`${BASE}/public/site-events`, JSON.stringify(body), { headers: { 'Content-Type': 'text/plain', 'User-Agent': 'Mozilla/5.0 k6-loadtest' }, tags: { kind: 'website' } });
  event({ type: 'view', session_id: session, visitor_id: uuidv4(), view_id: view, path: '/home', utm: { source: 'k6' } });
  sleep(5 + Math.random() * 10);
  event({ type: 'ping', session_id: session, view_id: view, seconds: 15 });
}

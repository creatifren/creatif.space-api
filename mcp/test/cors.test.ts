import { afterEach, describe, expect, it } from 'vitest';
import { ORIGIN, startApp, TOKEN_ALL } from './helpers.js';

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

const BROWSER = 'https://inspector.example';

const expectCors = (h: Headers) => {
  expect(h.get('access-control-allow-origin')).toBe('*');
  expect(h.get('access-control-allow-methods')).toBe('GET, POST, DELETE, OPTIONS');
  expect(h.get('access-control-allow-headers')).toBe('authorization, content-type, mcp-protocol-version, mcp-session-id, last-event-id');
  expect(h.get('access-control-expose-headers')).toBe('mcp-session-id, www-authenticate');
  expect(h.get('access-control-max-age')).toBe('600');
  // Bearer header only: never cookies.
  expect(h.get('access-control-allow-credentials')).toBeNull();
};

const rpc = (method: string) => JSON.stringify({ jsonrpc: '2.0', id: 1, method, params: {} });
const post = (base: string, path: string, token?: string) =>
  fetch(`${base}${path}`, {
    method: 'POST',
    headers: {
      Origin: BROWSER,
      'Content-Type': 'application/json',
      Accept: 'application/json, text/event-stream',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: rpc('tools/list'),
  });

describe('CORS preflight', () => {
  for (const path of ['/mcp', '/', '/free']) {
    it(`OPTIONS ${path} → 204 with the CORS headers, no token needed`, async () => {
      app = await startApp();
      const res = await fetch(`${app.base}${path}`, {
        method: 'OPTIONS',
        headers: {
          Origin: BROWSER,
          'Access-Control-Request-Method': 'POST',
          'Access-Control-Request-Headers': 'authorization, content-type, mcp-protocol-version',
        },
      });
      expect(res.status).toBe(204);
      expectCors(res.headers);
      expect(await res.text()).toBe('');
    });
  }

  it('leaves other paths alone', async () => {
    app = await startApp();
    const res = await fetch(`${app.base}/nope`, { method: 'OPTIONS', headers: { Origin: BROWSER } });
    expect(res.headers.get('access-control-allow-methods')).toBeNull();
  });
});

describe('CORS on actual responses', () => {
  it('200 (authenticated tools/list)', async () => {
    app = await startApp();
    const res = await post(app.base, '/mcp', TOKEN_ALL);
    expect(res.status).toBe(200);
    expectCors(res.headers);
  });

  for (const path of ['/mcp', '/']) {
    it(`401 on ${path}: the browser can read WWW-Authenticate`, async () => {
      app = await startApp();
      const res = await post(app.base, path);
      expect(res.status).toBe(401);
      expectCors(res.headers);
      expect(res.headers.get('www-authenticate')).toBe(`Bearer resource_metadata="${ORIGIN}/.well-known/oauth-protected-resource/mcp"`);
    });
  }

  it('429 from the per-token limiter', async () => {
    app = await startApp({ rateLimit: 1 });
    expect((await post(app.base, '/mcp', TOKEN_ALL)).status).toBe(200);
    const res = await post(app.base, '/mcp', TOKEN_ALL);
    expect(res.status).toBe(429);
    expectCors(res.headers);
    expect(res.headers.get('retry-after')).toMatch(/^\d+$/);
  });

  it('200 on /free (no auth)', async () => {
    app = await startApp();
    const res = await post(app.base, '/free');
    expect(res.status).toBe(200);
    expectCors(res.headers);
  });

  it('429 from the /free per-IP limiter', async () => {
    app = await startApp({ freeRateLimit: 1 });
    expect((await post(app.base, '/free')).status).toBe(200);
    const res = await post(app.base, '/free');
    expect(res.status).toBe(429);
    expectCors(res.headers);
  });

  it('405 on GET /mcp (stateless) carries them too', async () => {
    app = await startApp();
    const res = await fetch(`${app.base}/mcp`, { headers: { Origin: BROWSER, Authorization: `Bearer ${TOKEN_ALL}` } });
    expect(res.status).toBe(405);
    expectCors(res.headers);
  });
});

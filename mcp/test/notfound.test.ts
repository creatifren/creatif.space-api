import { afterEach, describe, expect, it } from 'vitest';
import { startApp } from './helpers.js';

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

describe('unknown routes', () => {
  it('answer JSON 404 {"error":"not_found"}, never Express HTML', async () => {
    app = await startApp();
    for (const [method, path] of [
      ['GET', '/nope'],
      ['GET', '/.well-known/nope'],
      ['POST', '/api/whatever'],
      ['PUT', '/mcp'],
    ] as const) {
      const res = await fetch(`${app.base}${path}`, { method });
      expect(res.status, `${method} ${path}`).toBe(404);
      expect(res.headers.get('content-type')).toMatch(/^application\/json/);
      const text = await res.text();
      expect(text).not.toMatch(/Cannot (GET|POST|PUT)/);
      expect(JSON.parse(text)).toEqual({ error: 'not_found' });
    }
  });

  it('leaves the real routes alone', async () => {
    app = await startApp();
    expect((await fetch(`${app.base}/health`)).status).toBe(200);
    expect((await fetch(`${app.base}/.well-known/oauth-protected-resource`)).status).toBe(200);
    expect((await fetch(`${app.base}/mcp`, { method: 'POST' })).status).toBe(401);
  });
});

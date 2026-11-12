import { afterEach, describe, expect, it } from 'vitest';
import { startApp } from './helpers.js';

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

describe('security headers (blackbox B13)', () => {
  it('sends HSTS and nosniff on a public answer', async () => {
    app = await startApp();
    const res = await fetch(`${app.base}/health`);
    expect(res.status).toBe(200);
    expect(res.headers.get('strict-transport-security')).toBe('max-age=31536000; includeSubDomains');
    expect(res.headers.get('x-content-type-options')).toBe('nosniff');
  });

  it('sends them on an error answer too', async () => {
    app = await startApp();
    const res = await fetch(`${app.base}/mcp`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream' },
      body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'tools/list', params: {} }),
    });
    expect(res.status).toBe(401);
    expect(res.headers.get('strict-transport-security')).toBe('max-age=31536000; includeSubDomains');
    expect(res.headers.get('x-content-type-options')).toBe('nosniff');
  });
});

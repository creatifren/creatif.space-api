import { afterEach, describe, expect, it } from 'vitest';
import { ORIGIN, RESOURCE, startApp } from './helpers.js';

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

/** Rewrite a public mcp.creatif.space URL onto the test server. */
const local = (base: string, url: string) => base + new URL(url).pathname;

describe('Claude-style OAuth discovery from a 401 (RFC 9728)', () => {
  it('connector URL → 401 → resource_metadata → resource equals the connector URL → authorization server', async () => {
    app = await startApp();

    // 1. The client POSTs to the connector URL with no token.
    const first = await fetch(`${app.base}/mcp`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream' },
      body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'initialize', params: {} }),
    });
    expect(first.status).toBe(401);

    // 2. It reads resource_metadata out of the challenge.
    const header = first.headers.get('www-authenticate')!;
    const metadataUrl = /resource_metadata="([^"]+)"/.exec(header)![1]!;
    expect(metadataUrl).toBe(`${ORIGIN}/.well-known/oauth-protected-resource`);

    // 3. The metadata names this exact connector URL as the resource, and the AS.
    const meta = await (await fetch(local(app.base, metadataUrl))).json();
    expect(meta.resource).toBe(RESOURCE);
    expect(meta.resource).toBe('https://mcp.creatif.space/mcp');
    expect(meta.authorization_servers).toEqual(['https://api.creatif.space']);
    expect(meta.scopes_supported).toEqual(['read', 'draft', 'approve', 'publish']);
  });

  it('a client that skips the header finds the same document at the RFC 9728 path form', async () => {
    app = await startApp();
    const connector = new URL(RESOURCE);
    const pathForm = `${connector.origin}/.well-known/oauth-protected-resource${connector.pathname}`;
    expect(pathForm).toBe('https://mcp.creatif.space/.well-known/oauth-protected-resource/mcp');

    const res = await fetch(local(app.base, pathForm));
    expect(res.status).toBe(200);
    expect(res.headers.get('access-control-allow-origin')).toBe('*');
    const root = await (await fetch(`${app.base}/.well-known/oauth-protected-resource`)).json();
    expect(await res.json()).toEqual(root);
  });
});

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
    expect(metadataUrl).toBe(`${ORIGIN}/.well-known/oauth-protected-resource/mcp`);

    // 3. The metadata names this exact connector URL as the resource, and the AS.
    const meta = await (await fetch(local(app.base, metadataUrl))).json();
    expect(meta.resource).toBe(RESOURCE);
    expect(meta.resource).toBe('https://mcp.creatif.space/mcp');
    expect(meta.authorization_servers).toEqual(['https://api.creatif.space']);
    expect(meta.scopes_supported).toEqual(['read', 'draft', 'approve', 'publish', 'approvals:decide']);
  });

  it('builds the challenge from the origin, whatever path the resource has', async () => {
    const { metadataUrls } = await import('../src/auth/middleware.js');
    expect(metadataUrls('https://mcp.creatif.space/mcp')).toMatchObject({
      root: 'https://mcp.creatif.space/.well-known/oauth-protected-resource',
      pathForm: 'https://mcp.creatif.space/.well-known/oauth-protected-resource/mcp',
    });
    // A bare origin still works: both forms collapse to the root.
    expect(metadataUrls('https://mcp.creatif.space')).toMatchObject({
      root: 'https://mcp.creatif.space/.well-known/oauth-protected-resource',
      pathForm: 'https://mcp.creatif.space/.well-known/oauth-protected-resource',
    });
    // Never resourceUrl + metadataPath (the bug found at deploy).
    expect(metadataUrls('https://mcp.creatif.space/mcp').pathForm).not.toContain('/mcp/.well-known');
  });

  it('a client that ignores the header finds the same document at the root well-known', async () => {
    app = await startApp();
    const connector = new URL(RESOURCE);
    const pathForm = `${connector.origin}/.well-known/oauth-protected-resource${connector.pathname}`;
    expect(pathForm).toBe('https://mcp.creatif.space/.well-known/oauth-protected-resource/mcp');

    const res = await fetch(`${app.base}/.well-known/oauth-protected-resource`);
    expect(res.status).toBe(200);
    expect(res.headers.get('access-control-allow-origin')).toBe('*');
    const atPath = await (await fetch(local(app.base, pathForm))).json();
    const atRoot = await res.json();
    expect(atRoot).toEqual(atPath);
    expect(atRoot.resource).toBe('https://mcp.creatif.space/mcp');
  });
});

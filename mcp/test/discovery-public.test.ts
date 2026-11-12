import { afterEach, describe, expect, it } from 'vitest';
import { StreamableHTTPError } from '@modelcontextprotocol/sdk/client/streamableHttp.js';
import { loadConfig } from '../src/config.js';
import { payload, startApp, TOKEN_FULL } from './helpers.js';

/*
 * MCP_PUBLIC_DISCOVERY: initialize + tools/list without a token, tools/call
 * still behind OAuth. The 401 + WWW-Authenticate (resource_metadata) on the
 * first tools/call is what makes Claude and ChatGPT show their sign-in, so
 * it is pinned here exactly as the token-less initialize gets it today.
 */

const METADATA = 'https://mcp.creatif.space/.well-known/oauth-protected-resource/mcp';
const CHALLENGE = `Bearer resource_metadata="${METADATA}"`;

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

const rpc = (method: string, params: Record<string, unknown> = {}, id: number | undefined = 1) => ({ jsonrpc: '2.0', method, params, ...(id === undefined ? {} : { id }) });
const INIT = rpc('initialize', { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'crawler', version: '1' } });

const post = (body: unknown, headers: Record<string, string> = {}) =>
  fetch(`${app!.base}/mcp`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', ...headers },
    body: typeof body === 'string' ? body : JSON.stringify(body),
  });

describe('public discovery flag', () => {
  it('is off unless MCP_PUBLIC_DISCOVERY is exactly "true"', () => {
    expect(loadConfig({ MCP_INTROSPECT_SECRET: 'x' }).publicDiscovery).toBe(false);
    expect(loadConfig({ MCP_INTROSPECT_SECRET: 'x', MCP_PUBLIC_DISCOVERY: '1' }).publicDiscovery).toBe(false);
    expect(loadConfig({ MCP_INTROSPECT_SECRET: 'x', MCP_PUBLIC_DISCOVERY: 'true' }).publicDiscovery).toBe(true);
  });

  it('off (default): initialize without a token is the 401 sign-in challenge, as before', async () => {
    app = await startApp();
    const res = await post(INIT);
    expect(res.status).toBe(401);
    expect(res.headers.get('www-authenticate')).toBe(CHALLENGE);
  });
});

describe('public discovery on', () => {
  it('initialize and tools/list work without a token, with the same tools as a signed-in client', async () => {
    app = await startApp({ publicDiscovery: true });
    const anon = await app.connectAnonymous();
    expect(anon.getServerVersion()?.name).toBe('creatif.space');
    const listed = (await anon.listTools()).tools;
    const signedIn = (await (await app.connect(TOKEN_FULL)).listTools()).tools;
    expect(listed.map((t) => t.name)).toEqual(signedIn.map((t) => t.name));
    expect(listed.length).toBeGreaterThan(30);
    expect(app.v1.calls).toEqual([]);
  });

  it('the first tools/call without a token is the 401 + WWW-Authenticate sign-in challenge (Claude, ChatGPT)', async () => {
    app = await startApp({ publicDiscovery: true });
    const res = await post(rpc('tools/call', { name: 'list_channels', arguments: {} }));
    expect(res.status).toBe(401);
    expect(res.headers.get('www-authenticate')).toBe(CHALLENGE);
    expect(await res.json()).toEqual({ error: 'unauthorized', error_description: 'Sign in to creatif.space to use this server.' });
    expect(app.v1.calls).toEqual([]);

    // The resource metadata the challenge points at is served, naming the authorization server.
    const meta = await (await fetch(`${app.base}/.well-known/oauth-protected-resource/mcp`)).json();
    expect(meta).toMatchObject({ resource: 'https://mcp.creatif.space/mcp', authorization_servers: ['https://api.creatif.space'] });
  });

  it('an SDK client that listed tools anonymously gets a 401 on its first tools/call', async () => {
    app = await startApp({ publicDiscovery: true });
    const anon = await app.connectAnonymous();
    await anon.listTools();
    const err = await anon.callTool({ name: 'list_channels', arguments: {} }).then(
      () => null,
      (e: unknown) => e,
    );
    expect(err).toBeInstanceOf(StreamableHTTPError);
    expect((err as StreamableHTTPError).code).toBe(401);
    expect(app.v1.calls).toEqual([]);
  });

  it('everything else without a token is 401 too: prompts, resources, a batch that hides a tools/call, junk', async () => {
    app = await startApp({ publicDiscovery: true });
    for (const body of [
      rpc('prompts/list'),
      rpc('resources/list'),
      rpc('resources/read', { uri: 'creatif://channels' }),
      [rpc('tools/list', {}, 1), rpc('tools/call', { name: 'list_channels', arguments: {} }, 2)],
      [],
      { jsonrpc: '2.0', id: 1 },
      '{not json',
    ]) {
      const res = await post(body);
      expect(res.status, JSON.stringify(body)).toBe(401);
      expect(res.headers.get('www-authenticate')).toBe(CHALLENGE);
    }
    expect(app.v1.calls).toEqual([]);
  });

  it('a request with a token always takes normal auth: a bad token is 401 even for initialize', async () => {
    app = await startApp({ publicDiscovery: true });
    const res = await post(INIT, { Authorization: 'Bearer nope' });
    expect(res.status).toBe(401);
    expect(res.headers.get('www-authenticate')).toContain('error="invalid_token"');

    const ok = await post(rpc('tools/call', { name: 'list_channels', arguments: {} }), { Authorization: `Bearer ${TOKEN_FULL}` });
    expect(ok.status).toBe(200);
  });

  it('a signed-in tools/call works as before', async () => {
    app = await startApp({ publicDiscovery: true, routes: { 'GET /social-accounts': () => ({ body: { data: [] } }) } });
    const client = await app.connect(TOKEN_FULL);
    const out = payload(await client.callTool({ name: 'list_channels', arguments: {} }));
    expect(out.channels).toEqual([]);
  });

  it('anonymous discovery is limited per address', async () => {
    app = await startApp({ publicDiscovery: true, discoveryRateLimit: 2 });
    expect((await post(INIT)).status).toBe(200);
    expect((await post(rpc('tools/list'))).status).toBe(200);
    const third = await post(rpc('tools/list'));
    expect(third.status).toBe(429);
    expect(third.headers.get('retry-after')).toMatch(/^\d+$/);
  });

  it('GET and DELETE on /mcp still need a token', async () => {
    app = await startApp({ publicDiscovery: true });
    expect((await fetch(`${app.base}/mcp`)).status).toBe(401);
    expect((await fetch(`${app.base}/mcp`, { method: 'DELETE' })).status).toBe(401);
  });
});

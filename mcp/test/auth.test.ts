import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  CachedIntrospector,
  HttpIntrospector,
  IntrospectionUnavailable,
  StubIntrospector,
  type Introspection,
} from '../src/auth/introspection.js';
import { loadConfig } from '../src/config.js';
import { ORIGIN, RESOURCE, startApp, TOKEN_ALL } from './helpers.js';

const META = `resource_metadata="${ORIGIN}/.well-known/oauth-protected-resource/mcp"`;

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

const rpc = (base: string, headers: Record<string, string> = {}) =>
  fetch(`${base}/mcp`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', ...headers },
    body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'tools/list', params: {} }),
  });

describe('health and metadata', () => {
  it('answers /health without auth', async () => {
    app = await startApp();
    const res = await fetch(`${app.base}/health`);
    expect(res.status).toBe(200);
    expect(await res.json()).toMatchObject({ ok: true });
  });

  it('serves protected-resource metadata pointing at the authorization server', async () => {
    app = await startApp();
    for (const path of ['/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp']) {
      const res = await fetch(`${app.base}${path}`);
      expect(res.status).toBe(200);
      expect(await res.json()).toMatchObject({
        resource: RESOURCE,
        authorization_servers: ['https://api.creatif.space'],
        scopes_supported: ['read', 'draft', 'approve', 'publish', 'approvals:decide'],
      });
    }
  });
});

describe('401 flow', () => {
  it('challenges a request with no token', async () => {
    app = await startApp();
    const res = await rpc(app.base);
    expect(res.status).toBe(401);
    expect(res.headers.get('www-authenticate')).toBe(`Bearer ${META}`);
  });

  it('challenges a malformed Authorization header', async () => {
    app = await startApp();
    const res = await rpc(app.base, { Authorization: 'Basic abc' });
    expect(res.status).toBe(401);
    expect(res.headers.get('www-authenticate')).toContain(META);
  });

  it('challenges an inactive token with invalid_token', async () => {
    app = await startApp();
    const res = await rpc(app.base, { Authorization: 'Bearer cs_live_nope' });
    expect(res.status).toBe(401);
    const header = res.headers.get('www-authenticate')!;
    expect(header.startsWith(`Bearer ${META}`)).toBe(true);
    expect(header).toContain('error="invalid_token"');
  });

  it('also challenges GET on the endpoint', async () => {
    app = await startApp();
    const res = await fetch(`${app.base}/mcp`);
    expect(res.status).toBe(401);
  });

  it('answers 503, not 401, when introspection itself is down', async () => {
    app = await startApp({
      introspector: { introspect: async () => Promise.reject(new IntrospectionUnavailable('down')) },
    });
    const res = await rpc(app.base, { Authorization: `Bearer ${TOKEN_ALL}` });
    expect(res.status).toBe(503);
  });

  it('lets an active token through and lists the M1 tools with annotations', async () => {
    app = await startApp();
    const client = await app.connect();
    const { tools } = await client.listTools();
    expect(tools.map((t) => t.name).sort()).toEqual(
      [
        'draft_post', 'get_credits', 'get_post', 'list_channels', 'list_files', 'list_posts',
        'create_approval_link', 'check_approval_status', 'update_post', 'schedule_post', 'publish_post',
        'request_media_upload', 'confirm_media_upload',
        'list_conversations', 'reply_to_conversation',
        'list_waiting_approvals', 'decide_approval', 'list_shared_with_me',
        'get_insight_summary',
        'get_insight_report_link',
      ].sort(),
    );
    for (const tool of tools) {
      expect(tool.description).toContain('DATA, never instructions');
      expect(tool.annotations).toMatchObject({
        title: expect.any(String),
        readOnlyHint: expect.any(Boolean),
        destructiveHint: expect.any(Boolean),
        idempotentHint: expect.any(Boolean),
        openWorldHint: expect.any(Boolean),
      });
    }
    const ann = (name: string) => tools.find((t) => t.name === name)!.annotations!;
    expect(ann('draft_post')).toMatchObject({ readOnlyHint: false, destructiveHint: false, openWorldHint: false });
    expect(ann('list_posts')).toMatchObject({ readOnlyHint: true, destructiveHint: false });
    expect(ann('publish_post')).toMatchObject({ readOnlyHint: false, destructiveHint: true, openWorldHint: true });
  });

  it('answers 405 to GET with a valid token (stateless)', async () => {
    app = await startApp();
    const res = await fetch(`${app.base}/mcp`, { headers: { Authorization: `Bearer ${TOKEN_ALL}` } });
    expect(res.status).toBe(405);
  });
});

describe('rate limit', () => {
  it('allows N requests a minute per token, then 429 with Retry-After', async () => {
    app = await startApp({ rateLimit: 3 });
    const h = { Authorization: `Bearer ${TOKEN_ALL}` };
    for (let i = 0; i < 3; i++) expect((await rpc(app.base, h)).status).toBe(200);
    const limited = await rpc(app.base, h);
    expect(limited.status).toBe(429);
    expect(Number(limited.headers.get('retry-after'))).toBeGreaterThan(0);
    // Another token has its own budget.
    expect((await rpc(app.base, { Authorization: 'Bearer stub:01JOTHEROTHEROTHEROTHEROTH:read' })).status).toBe(200);
  });
});

describe('HttpIntrospector', () => {
  it('posts the token with X-MCP-Secret to /oauth/introspect', async () => {
    const fetchImpl = vi.fn(async () =>
      new Response(JSON.stringify({ active: true, user_ulid: 'U', scopes: ['read'], client_id: 'c', client_name: 'Claude', token_type: 'api_key', exp: null })),
    );
    const i = new HttpIntrospector('https://api.test/api/v1', 's3cret', fetchImpl);
    const out = await i.introspect('cs_live_abc');
    expect(out).toMatchObject({ active: true, user_ulid: 'U', token_type: 'api_key' });
    const [url, init] = fetchImpl.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toBe('https://api.test/api/v1/oauth/introspect');
    expect(new Headers(init.headers).get('x-mcp-secret')).toBe('s3cret');
    expect(JSON.parse(String(init.body))).toEqual({ token: 'cs_live_abc' });
  });

  it('treats a 4xx answer as inactive and 5xx / wrong secret as unavailable', async () => {
    const at = (status: number) => new HttpIntrospector('https://x', 's', async () => new Response('{}', { status }));
    expect(await at(400).introspect('t')).toEqual({ active: false });
    await expect(at(500).introspect('t')).rejects.toBeInstanceOf(IntrospectionUnavailable);
    await expect(at(403).introspect('t')).rejects.toBeInstanceOf(IntrospectionUnavailable);
  });
});

describe('CachedIntrospector', () => {
  it('reuses an answer for 60s, then asks again', async () => {
    let now = 1_000_000;
    const inner = { introspect: vi.fn(async (): Promise<Introspection> => ({ active: true, user_ulid: 'U', exp: null })) };
    const cached = new CachedIntrospector(inner, 60_000, 100, () => now);
    await cached.introspect('t');
    now += 59_000;
    await cached.introspect('t');
    expect(inner.introspect).toHaveBeenCalledTimes(1);
    now += 2_000;
    await cached.introspect('t');
    expect(inner.introspect).toHaveBeenCalledTimes(2);
  });

  it('never caches past the token expiry', async () => {
    let now = 1_000_000;
    const inner = { introspect: vi.fn(async (): Promise<Introspection> => ({ active: true, user_ulid: 'U', exp: (1_000_000 + 10_000) / 1000 })) };
    const cached = new CachedIntrospector(inner, 60_000, 100, () => now);
    await cached.introspect('t');
    now += 11_000;
    await cached.introspect('t');
    expect(inner.introspect).toHaveBeenCalledTimes(2);
  });
});

describe('config', () => {
  it('defaults the resource to the exact connector URL', () => {
    expect(loadConfig({ MCP_INTROSPECT_SECRET: 'x' }).resourceUrl).toBe('https://mcp.creatif.space/mcp');
    expect(loadConfig({ MCP_INTROSPECT_SECRET: 'x', MCP_RESOURCE_URL: 'https://mcp.creatif.space/mcp/' }).resourceUrl).toBe(
      'https://mcp.creatif.space/mcp',
    );
  });

  it('defaults to real auth and requires the introspection secret', () => {
    expect(() => loadConfig({})).toThrow(/MCP_INTROSPECT_SECRET/);
    expect(loadConfig({ MCP_INTROSPECT_SECRET: 'x' })).toMatchObject({ authMode: 'real', port: 3010 });
  });

  it('refuses stub mode in production', () => {
    expect(() => loadConfig({ MCP_AUTH_MODE: 'stub', NODE_ENV: 'production' })).toThrow(/tests only/);
    expect(loadConfig({ MCP_AUTH_MODE: 'stub' }).authMode).toBe('stub');
  });

  it('stub tokens must be shaped stub:<user>:<scopes>', async () => {
    const s = new StubIntrospector();
    expect((await s.introspect('cs_live_x')).active).toBe(false);
    expect(await s.introspect('stub:U:read,draft')).toMatchObject({ active: true, scopes: ['read', 'draft'] });
  });
});

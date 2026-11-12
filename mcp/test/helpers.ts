import type { AddressInfo } from 'node:net';
import type { Server } from 'node:http';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';
import type { Analytics, FreeAnalytics, FreeToolEvent, ToolCallEvent } from '../src/analytics.js';
import { CachedIntrospector, StubIntrospector, type Introspector } from '../src/auth/introspection.js';
import { TokenRateLimiter } from '../src/rateLimit.js';
import { createApp } from '../src/server.js';

export const V1 = 'https://api.test/api/v1';
export const RESOURCE = 'https://mcp.creatif.space/mcp';
export const ORIGIN = 'https://mcp.creatif.space';
export const USER = '01JUSERUSERUSERUSERUSERUSE';
export const TOKEN_ALL = `stub:${USER}:read,draft:Claude`;
export const TOKEN_READ = `stub:${USER}:read:Claude`;
export const TOKEN_FULL = `stub:${USER}:read,draft,approve,publish:Claude`;

export interface V1Call {
  method: string;
  path: string;
  query: Record<string, string>;
  body: unknown;
  auth: string | null;
  headers: Headers;
}

type Handler = (call: V1Call) => { status?: number; body?: unknown } | Promise<{ status?: number; body?: unknown }>;

/** A fake V1 API: routes keyed "METHOD /path" (exact path, no query). */
export function mockV1(routes: Record<string, Handler>) {
  const calls: V1Call[] = [];
  const fetchImpl = async (input: string, init?: RequestInit): Promise<Response> => {
    const url = new URL(input);
    const path = url.pathname.replace('/api/v1', '');
    const headers = new Headers(init?.headers);
    const call: V1Call = {
      method: init?.method ?? 'GET',
      path,
      query: Object.fromEntries(url.searchParams),
      body: init?.body ? JSON.parse(String(init.body)) : undefined,
      auth: headers.get('authorization'),
      headers,
    };
    calls.push(call);
    const handler = routes[`${call.method} ${path}`];
    if (!handler) return new Response(JSON.stringify({ message: 'Not Found' }), { status: 404 });
    const { status = 200, body = null } = await handler(call);
    return new Response(body === null ? null : JSON.stringify(body), {
      status,
      headers: { 'Content-Type': 'application/json' },
    });
  };
  return { fetchImpl, calls };
}

export function recordingAnalytics() {
  const events: { distinctId: string; event: ToolCallEvent }[] = [];
  const analytics: Analytics = { toolCalled: (distinctId, event) => events.push({ distinctId, event }) };
  return { analytics, events };
}

export async function startApp(opts: {
  routes?: Record<string, Handler>;
  introspector?: Introspector;
  rateLimit?: number;
  freeRateLimit?: number;
} = {}) {
  const v1 = mockV1(opts.routes ?? {});
  const rec = recordingAnalytics();
  const freeEvents: FreeToolEvent[] = [];
  const freeAnalytics: FreeAnalytics = { freeToolCalled: (e) => freeEvents.push(e) };
  const app = createApp({
    v1ApiUrl: V1,
    resourceUrl: RESOURCE,
    authorizationServer: 'https://api.creatif.space',
    introspector: opts.introspector ?? new CachedIntrospector(new StubIntrospector()),
    analytics: rec.analytics,
    rateLimiter: new TokenRateLimiter(opts.rateLimit ?? 60),
    fetchImpl: v1.fetchImpl,
    freeAnalytics,
    ...(opts.freeRateLimit ? { freeRateLimitPerMinute: opts.freeRateLimit } : {}),
  });
  const server: Server = await new Promise((resolve) => {
    const s = app.listen(0, '127.0.0.1', () => resolve(s));
  });
  const base = `http://127.0.0.1:${(server.address() as AddressInfo).port}`;

  const clients: Client[] = [];
  const connect = async (token = TOKEN_ALL) => {
    const client = new Client({ name: 'test', version: '1.0.0' });
    const transport = new StreamableHTTPClientTransport(new URL(`${base}/mcp`), {
      requestInit: { headers: { Authorization: `Bearer ${token}` } },
    });
    await client.connect(transport);
    clients.push(client);
    return client;
  };

  /** An MCP client on /free with no Authorization header at all. */
  const connectFree = async () => {
    const client = new Client({ name: 'test', version: '1.0.0' });
    await client.connect(new StreamableHTTPClientTransport(new URL(`${base}/free`)));
    clients.push(client);
    return client;
  };

  const close = async () => {
    await Promise.all(clients.map((c) => c.close()));
    await new Promise<void>((r) => server.close(() => r()));
  };

  return { base, v1, events: rec.events, freeEvents, connect, connectFree, close };
}

/** Parse a tool result's JSON text. */
// eslint-disable-next-line @typescript-eslint/no-explicit-any -- test assertions
export function payload(result: unknown): any {
  const r = result as { content: { type: string; text: string }[] };
  return JSON.parse(r.content[0]!.text);
}

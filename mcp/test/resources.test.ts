import { afterEach, describe, expect, it } from 'vitest';
import { account, post } from './fixtures.js';
import { payload, startApp, TOKEN_ALL, USER } from './helpers.js';

/* resources/list + resources/read: creatif://channels and creatif://schedule/next-7-days, read-only, scope read. */

const IG = '01JACCTACCTACCTACCTACCTIG1';
const X = '01JACCTACCTACCTACCTACCTXX1';
const DAY = 86_400_000;

const routes = {
  'GET /social-accounts': () => ({ body: { data: [account(IG), account(X, 'x', { status: 'expired' })] } }),
  'GET /social-posts': () => ({
    body: {
      data: [
        post('01JPOSTPOSTPOSTPOSTPOSTLAT', { scheduled_at: '2026-11-15T09:00:00Z' }),
        post('01JPOSTPOSTPOSTPOSTPOSTSON', { scheduled_at: '2026-11-13T09:00:00Z' }),
      ],
    },
  }),
};

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

async function client(token = TOKEN_ALL, r: Record<string, unknown> = routes) {
  app = await startApp({ routes: r as never });
  return app.connect(token);
}

const json = (res: { contents: { text?: unknown; mimeType?: string }[] }) => JSON.parse(String(res.contents[0]!.text));

describe('resources/list', () => {
  it('lists creatif://channels and creatif://schedule/next-7-days as JSON, and calls no API', async () => {
    const c = await client();
    expect(c.getServerCapabilities()?.resources).toBeDefined();
    const { resources } = await c.listResources();
    expect(resources.map((r) => r.uri).sort()).toEqual(['creatif://channels', 'creatif://schedule/next-7-days']);
    for (const r of resources) {
      expect(r.mimeType, r.uri).toBe('application/json');
      expect(r.title, r.uri).toEqual(expect.any(String));
      expect(r.description, r.uri).toEqual(expect.any(String));
    }
    expect(app!.v1.calls).toHaveLength(0);
  });
});

describe('resources/read', () => {
  it('creatif://channels is the same JSON list_channels returns', async () => {
    const c = await client();
    const res = await c.readResource({ uri: 'creatif://channels' });
    expect(res.contents[0]).toMatchObject({ uri: 'creatif://channels', mimeType: 'application/json' });
    const fromTool = payload(await c.callTool({ name: 'list_channels', arguments: { limit: 100 } }));
    expect(json(res)).toEqual(fromTool);
    expect(json(res).needs_reconnect).toEqual([X]);
    expect(app!.v1.calls[0]!.headers.get('x-mcp-tool')).toBe('resource.channels');
  });

  it('creatif://schedule/next-7-days asks for scheduled posts from now to now + 7 days, soonest first', async () => {
    const c = await client();
    const before = Date.now();
    const out = json(await c.readResource({ uri: 'creatif://schedule/next-7-days' }));
    const q = app!.v1.calls[0]!.query;
    expect(q.status).toBe('scheduled');
    expect(q.limit).toBe('100');
    expect(Math.abs(Date.parse(q.from!) - before)).toBeLessThan(5000);
    expect(Date.parse(q.to!) - Date.parse(q.from!)).toBe(7 * DAY);
    expect(out).toMatchObject({ from: q.from, to: q.to, count: 2 });
    expect(out.posts.map((p: { scheduled_at: string }) => p.scheduled_at)).toEqual(['2026-11-13T09:00:00Z', '2026-11-15T09:00:00Z']);
    expect(app!.v1.calls[0]!.headers.get('x-mcp-tool')).toBe('resource.schedule');
  });

  it('needs the read scope: refuses with scope_missing in the error data, calling no API', async () => {
    const c = await client(`stub:${USER}:draft:Claude`);
    const err = await c.readResource({ uri: 'creatif://channels' }).then(() => null, (e: { message: string; data?: unknown }) => e);
    expect(err).not.toBeNull();
    expect(err!.message).toMatch(/scope_missing/);
    expect(err!.data).toMatchObject({ code: 'scope_missing', next_step: expect.stringMatching(/reconnect/), details: { required: 'read', resource: 'creatif://channels' } });
    expect(app!.v1.calls).toHaveLength(0);
  });

  it('an API error comes back as the usual envelope in the error data', async () => {
    const c = await client(TOKEN_ALL, { 'GET /social-accounts': () => ({ status: 401, body: { message: 'Unauthenticated.' } }) });
    const err = await c.readResource({ uri: 'creatif://channels' }).then(() => null, (e: { data?: unknown }) => e);
    expect(err!.data).toMatchObject({ code: 'unauthorized', next_step: expect.any(String), details: { resource: 'creatif://channels' } });
  });

  it('an unknown resource is refused', async () => {
    const c = await client();
    await expect(c.readResource({ uri: 'creatif://nope' })).rejects.toThrow();
  });
});

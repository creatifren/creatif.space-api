import { afterEach, describe, expect, it } from 'vitest';
import { account } from './fixtures.js';
import { payload, startApp, TOKEN_ALL } from './helpers.js';

/*
 * list_channels' reconnect marks and get_connect_link over fakes of
 * GET /social-accounts and GET /credits (limits.connected_accounts, urls).
 * The OAuth start (POST /social-accounts/auth-url) must never be called.
 */

const IG = '01JACCTACCTACCTACCTACCTIG1';
const X = '01JACCTACCTACCTACCTACCTXX1';
const LI = '01JACCTACCTACCTACCTACCTLI1';
const BILLING = 'https://creatif.space/settings?section=subscription';

const accounts = [
  account(IG, 'instagram', { needs_reconnect_for_messages: true }),
  account(X, 'x', { status: 'expired' }),
  account(LI, 'linkedin', { status: 'disconnected', reconnect_required: true }),
];

const routes = (opts: { list?: unknown[]; limit?: number | null; used?: number } = {}) => ({
  'GET /social-accounts': () => ({ body: { data: opts.list ?? accounts } }),
  'GET /credits': () => ({
    body: { data: { limits: { connected_accounts: { limit: opts.limit === undefined ? 3 : opts.limit, used: opts.used ?? 1 }, urls: { billing: BILLING } } } },
  }),
});

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

async function call(name: string, args: Record<string, unknown>, r: Record<string, () => unknown> = routes()) {
  app = await startApp({ routes: r as never });
  return (await app.connect(TOKEN_ALL)).callTool({ name, arguments: args });
}

describe('list_channels marks channels that need reconnecting', () => {
  it('adds a reconnect block with reason and next step, and lists them in needs_reconnect', async () => {
    const out = payload(await call('list_channels', {}));
    const [ig, x, li] = out.channels;
    expect(ig).toMatchObject({ reconnect_required: false, reconnect_for_messages: { next_step: expect.stringContaining('get_connect_link { "network": "instagram" }') } });
    expect(ig.reconnect).toBeUndefined();
    expect(x).toMatchObject({ reconnect_required: true, reconnect: { reason: expect.stringMatching(/expired/), next_step: expect.stringContaining('get_connect_link { "network": "x" }') } });
    expect(li.reconnect.reason).toMatch(/older connection/);
    expect(out.needs_reconnect).toEqual([X, LI]);
    expect(out.next_step).toMatch(/cannot be posted to.*get_connect_link/s);
  });

  it('adds nothing when every channel is connected', async () => {
    const out = payload(await call('list_channels', {}, routes({ list: [account(IG)] })));
    expect(out.needs_reconnect).toBeUndefined();
    expect(out.next_step).toBeUndefined();
    expect(Object.keys(out.channels[0])).not.toContain('reconnect');
  });
});

describe('get_connect_link', () => {
  it('returns the app connect URL with steps, and no token anywhere', async () => {
    const res = await call('get_connect_link', { network: 'tiktok' });
    expect(res.isError).toBeFalsy();
    const out = payload(res);
    expect(out).toMatchObject({ network: 'tiktok', url: 'https://creatif.space/social/connect?network=tiktok', existing: [], room: { used: 1, limit: 3 } });
    expect(out.steps.length).toBeGreaterThan(2);
    expect(out.next_step).toMatch(/connect TikTok/);
    const text = JSON.stringify(out);
    expect(text).not.toMatch(/token|state=|code=|secret|Bearer/i);
    expect(new URL(out.url).searchParams.size).toBe(1);
    expect(app!.v1.calls.map((c) => `${c.method} ${c.path}`).sort()).toEqual(['GET /credits', 'GET /social-accounts']);
  });

  it('a channel that needs reconnecting: reconnect wording, works even when the plan is full', async () => {
    const out = payload(await call('get_connect_link', { network: 'x' }, routes({ limit: 3, used: 3 })));
    expect(out.existing).toEqual([{ id: X, username: 'x_user', status: 'expired', reconnect_required: true }]);
    expect(out.next_step).toMatch(/reconnect X/);
  });

  it('a new network on a full plan → plan_required with the billing link', async () => {
    const res = await call('get_connect_link', { network: 'youtube' }, routes({ limit: 3, used: 3 }));
    expect(res.isError).toBe(true);
    const { error } = payload(res);
    expect(error).toMatchObject({ code: 'plan_required', details: { feature: 'connected_accounts', limit: 3, used: 3, billing_url: BILLING, tool: 'get_connect_link' } });
    expect(error.next_step).toContain(BILLING);
  });

  it('no limit (null) never blocks', async () => {
    const out = payload(await call('get_connect_link', { network: 'youtube' }, routes({ limit: null, used: 40 })));
    expect(out.url).toContain('network=youtube');
  });

  it('Bluesky: steps say handle and app password, in the app', async () => {
    const out = payload(await call('get_connect_link', { network: 'bluesky' }));
    expect(out.steps.join(' ')).toMatch(/app password/);
  });

  it('refuses an unknown network before calling the API', async () => {
    const { error } = payload(await call('get_connect_link', { network: 'myspace' }));
    expect(error.code).toBe('invalid_input');
    expect(app!.v1.calls).toHaveLength(0);
  });

  it('describes itself in the describeTool shape; list_channels points at it', async () => {
    app = await startApp();
    const d = Object.fromEntries((await (await app.connect()).listTools()).tools.map((t) => [t.name, t.description!]));
    expect(d.get_connect_link).toMatch(/\nExample: get_connect_link \{/);
    expect(d.get_connect_link).toMatch(/no token/);
    expect(d.get_connect_link).toMatch(/plan_required → /);
    expect(d.list_channels).toMatch(/get_connect_link/);
  });
});

import { afterEach, describe, expect, it } from 'vitest';
import { account } from './fixtures.js';
import { payload, startApp, TOKEN_READ } from './helpers.js';

const IG = '01JACCTACCTACCTACCTACCTIG1';
const X = '01JACCTACCTACCTACCTACCTXX1';

const credits = (limits: Record<string, unknown> = {}) => ({
  body: {
    data: {
      plan: 'free',
      limits: {
        plan: { key: 'free', name: 'Free' },
        monthly_posts: { limit: 30, used: 12, resets_at: '2026-12-01T00:00:00+07:00' },
        connected_accounts: { limit: 3, used: 2 },
        scheduling: { window_days: 7, queued_posts: { limit: 10, used: 4 } },
        approval_clients: { limit: 1, used: 0 },
        credits: { monthly: 300, monthly_amount: 300, topup: 50, total: 350, resets_at: '2026-12-01T00:00:00+07:00' },
        ...limits,
      },
    },
  },
});

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

const run = async (routes: Record<string, unknown>, token = TOKEN_READ) => {
  app = await startApp({ routes: routes as never });
  const client = await app.connect(token);
  return client.callTool({ name: 'get_account_summary', arguments: {} });
};

const base = {
  'GET /credits': () => credits(),
  'GET /social-accounts': () => ({ body: { data: [account(IG), account(X, 'x', { status: 'expired', reconnect_required: true })] } }),
  'GET /files/usage': () => ({ body: { data: { tier: 'free', used_bytes: 250_000_000, quota_bytes: 1_000_000_000 } } }),
};

describe('get_account_summary', () => {
  it('plan, limits used/left, Credits, channels and storage in one call (scope read)', async () => {
    const out = payload(await run(base));
    expect(out.plan).toEqual({ key: 'free', name: 'Free' });
    expect(out.limits).toEqual({
      monthly_posts: { used: 12, limit: 30, left: 18 },
      connected_accounts: { used: 2, limit: 3, left: 1 },
      queued_posts: { used: 4, limit: 10, left: 6 },
      approval_clients: { used: 0, limit: 1, left: 1 },
      schedule_window_days: 7,
    });
    expect(out.credits).toEqual({ left: 350, monthly: 300, topup: 50, resets_at: '2026-12-01T00:00:00+07:00' });
    expect(out.channels).toEqual({
      total: 2, connected: 1, needs_reconnect: 1,
      reconnect: [{ id: X, platform: 'x', username: 'x_user', status: 'expired' }],
    });
    expect(out.storage).toEqual({ tier: 'free', counts: 'library', used_bytes: 250_000_000, quota_bytes: 1_000_000_000, left_bytes: 750_000_000, percent_used: 25 });
    expect(out.next_steps).toEqual(['x x_user needs reconnecting: get_connect_link { "network": "x" }.']);
    expect(app!.v1.calls.map((c) => c.path).sort()).toEqual(['/credits', '/files/usage', '/social-accounts']);
    expect(app!.v1.calls.every((c) => c.headers.get('x-mcp-tool') === 'get_account_summary')).toBe(true);
  });

  it('paid plan: no limit is null left, storage counts the Keep quota', async () => {
    const out = payload(
      await run({
        ...base,
        'GET /credits': () =>
          credits({
            plan: { key: 'pro', name: 'Pro' },
            connected_accounts: { limit: null, used: 9 },
            scheduling: { window_days: null, queued_posts: { limit: null, used: 40 } },
            approval_clients: { limit: null, used: 5 },
          }),
        'GET /files/usage': () => ({ body: { data: { tier: 'pro', keep_used_bytes: 24_000_000_000, keep_quota_bytes: 25_000_000_000 } } }),
      }),
    );
    expect(out.limits.connected_accounts).toEqual({ used: 9, limit: null, left: null });
    expect(out.limits.queued_posts).toEqual({ used: 40, limit: null, left: null });
    expect(out.limits.schedule_window_days).toBeNull();
    expect(out.storage).toMatchObject({ tier: 'pro', counts: 'kept', left_bytes: 1_000_000_000, percent_used: 96 });
    expect(out.next_steps.join(' ')).toContain('Media storage is 90% used or more');
  });

  it('onboarding next steps: no channels, no Credits, full queue, no channel room', async () => {
    const out = payload(
      await run({
        ...base,
        'GET /credits': () =>
          credits({
            connected_accounts: { limit: 3, used: 3 },
            scheduling: { window_days: 7, queued_posts: { limit: 10, used: 12 } },
            credits: { monthly: 0, topup: 0, total: 0, resets_at: '2026-12-01T00:00:00+07:00' },
          }),
        'GET /social-accounts': () => ({ body: { data: [] } }),
      }),
    );
    expect(out.limits.queued_posts.left).toBe(0);
    expect(out.channels).toEqual({ total: 0, connected: 0, needs_reconnect: 0, reconnect: [] });
    const steps = out.next_steps.join(' ');
    expect(steps).toContain('No channels yet: call get_connect_link');
    expect(steps).toContain('no room for another channel');
    expect(steps).toContain('The queue is full');
    expect(steps).toContain('No Credits left');
  });

  it('all clear: points at list_channels and the plan_week prompt', async () => {
    const out = payload(await run({ ...base, 'GET /social-accounts': () => ({ body: { data: [account(IG)] } }) }));
    expect(out.next_steps).toEqual(['Ready: list_channels for channel ids, then draft_post. The plan_week prompt plans a week of posts.']);
  });

  it('an API error is the usual envelope', async () => {
    const res = await run({ ...base, 'GET /credits': () => ({ status: 401, body: { message: 'Unauthenticated.' } }) });
    expect(res.isError).toBe(true);
    expect(payload(res).error.code).toBe('unauthorized');
  });
});

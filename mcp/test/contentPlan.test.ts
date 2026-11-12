import { afterEach, describe, expect, it } from 'vitest';
import { account, post } from './fixtures.js';
import { planPreviews, startOfDay } from '../src/tools/contentPlan.js';
import { payload, startApp, TOKEN_ALL, TOKEN_FULL } from './helpers.js';

const IG = '01JACCTACCTACCTACCTACCTIG1';
const X = '01JACCTACCTACCTACCTACCTXX1';
const LINK = '01JLINKLINKLINKLINKLINKLI1';
const P1 = '01JPOSTPOSTPOSTPOSTPOSTPO1';
const P2 = '01JPOSTPOSTPOSTPOSTPOSTPO2';
const P3 = '01JPOSTPOSTPOSTPOSTPOSTPO3';

const ARGS = { date_from: '2026-12-01', date_to: '2026-12-31', timezone: 'Asia/Jakarta', approver_email: 'Rani@Client.test', title: 'December' };

const onX = (id: string, extra: Record<string, unknown> = {}) =>
  post(id, { targets: [{ account: { id: X, platform: 'x', username: 'studio' }, status: 'pending', fail_reason: null, platform_url: null }], ...extra });

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
  planPreviews.clear();
});

/** A workspace whose channels and posts the test can change between calls. */
function world() {
  const state = {
    accounts: [account(IG), account(X, 'x')] as Record<string, unknown>[],
    posts: [post(P2, { scheduled_at: '2026-12-09T02:00:00Z' }), post(P1, { scheduled_at: '2026-12-02T02:00:00Z' }), onX(P3, { scheduled_at: '2026-12-03T02:00:00Z' })] as Record<string, unknown>[],
  };
  const routes = {
    'GET /social-accounts': () => ({ body: { data: state.accounts } }),
    'GET /social-posts': () => ({ body: { data: state.posts } }),
    'POST /social-approval-links': () => ({
      status: 201,
      body: {
        data: {
          id: LINK, url: 'https://creatif.space/a/tok', status: 'active', kind: 'plan', posts: 3,
          scope: { date_from: '2026-12-01', date_to: '2026-12-31' }, approver_email: 'rani@client.test', expires_at: null, approver_notified: true,
        },
      },
    }),
  };
  return { state, routes };
}

const open = async (routes: Record<string, unknown>, token = TOKEN_FULL) => {
  app = await startApp({ routes: routes as never });
  return app.connect(token);
};
const posted = () => app!.v1.calls.filter((c) => c.method === 'POST');

describe('create_content_plan', () => {
  it('preview: shows dates, channels, approver and the posts in the dates; creates and emails nothing', async () => {
    const { routes } = world();
    const client = await open(routes);
    const out = payload(await client.callTool({ name: 'create_content_plan', arguments: ARGS }));

    expect(out.confirmation_required).toBe(true);
    expect(out.preview).toMatchObject({
      date_from: '2026-12-01', date_to: '2026-12-31', timezone: 'Asia/Jakarta', channels: 'all connected channels',
      approver_email: 'rani@client.test', title: 'December', expires: 'never', posts_in_dates: 3,
      on_confirm: 'creatif.space creates the Content Plan link and emails it to rani@client.test.',
    });
    expect(out.preview.posts.map((p: { id: string }) => p.id)).toEqual([P1, P3, P2]); // soonest first
    expect(out.next_step).toMatch(/^Nothing was created or emailed\./);
    expect(posted()).toEqual([]);

    // The dates are local days in the timezone: Jakarta is UTC+7.
    const list = app!.v1.calls.find((c) => c.path === '/social-posts')!;
    expect(list.query).toMatchObject({ from: '2026-11-30T17:00:00.000Z', to: '2026-12-31T16:59:59.000Z', limit: '100' });
  });

  it('confirm after the preview: creates the plan with notify_approver: true and reports the email', async () => {
    const { routes } = world();
    const client = await open(routes);
    await client.callTool({ name: 'create_content_plan', arguments: { ...ARGS, channel_ids: [IG], expires_in_hours: 168 } });
    const out = payload(await client.callTool({ name: 'create_content_plan', arguments: { ...ARGS, channel_ids: [IG], expires_in_hours: 168, confirm: true } }));

    expect(posted()).toHaveLength(1);
    expect(posted()[0]!.body).toEqual({
      date_from: '2026-12-01', date_to: '2026-12-31', timezone: 'Asia/Jakarta', channel_ids: [IG],
      approver_email: 'Rani@Client.test', title: 'December', expires_in_hours: 168, notify_approver: true,
    });
    expect(posted()[0]!.headers.get('x-mcp-tool')).toBe('create_content_plan');
    expect(out.link).toMatchObject({ id: LINK, url: 'https://creatif.space/a/tok', kind: 'plan' });
    expect(out.approver_notified).toBe(true);
    expect(out.next_step).toContain('emailed the link to rani@client.test');

    // Used up: confirming again previews again and creates nothing more.
    const again = payload(await client.callTool({ name: 'create_content_plan', arguments: { ...ARGS, channel_ids: [IG], expires_in_hours: 168, confirm: true } }));
    expect(again.confirmation_required).toBe(true);
    expect(posted()).toHaveLength(1);
  });

  it('channel_ids: only those channels and their posts', async () => {
    const { routes } = world();
    const client = await open(routes);
    const out = payload(await client.callTool({ name: 'create_content_plan', arguments: { ...ARGS, channel_ids: [X] } }));
    expect(out.preview.channels).toEqual([{ id: X, platform: 'x', username: 'x_user', reconnect_required: false }]);
    expect(out.preview.posts_in_dates).toBe(1);
    expect(out.preview.posts[0].id).toBe(P3);
  });

  it('confirm with no preview shown: a preview, nothing created', async () => {
    const { routes } = world();
    const client = await open(routes);
    const out = payload(await client.callTool({ name: 'create_content_plan', arguments: { ...ARGS, confirm: true } }));
    expect(out.confirmation_required).toBe(true);
    expect(out.note).toMatch(/No current preview/);
    expect(posted()).toEqual([]);
  });

  it('confirm with different arguments than the preview: a preview, nothing created', async () => {
    const { routes } = world();
    const client = await open(routes);
    await client.callTool({ name: 'create_content_plan', arguments: ARGS });
    const out = payload(await client.callTool({ name: 'create_content_plan', arguments: { ...ARGS, approver_email: 'other@client.test', confirm: true } }));
    expect(out.confirmation_required).toBe(true);
    expect(posted()).toEqual([]);
  });

  it('preview_stale: a post added in the dates since the preview → new preview, nothing created', async () => {
    const { state, routes } = world();
    const client = await open(routes);
    await client.callTool({ name: 'create_content_plan', arguments: ARGS });
    state.posts.push(post('01JPOSTPOSTPOSTPOSTPOSTPO4', { scheduled_at: '2026-12-20T02:00:00Z' }));

    const res = await client.callTool({ name: 'create_content_plan', arguments: { ...ARGS, confirm: true } });
    expect(res.isError).toBe(true);
    const err = payload(res).error;
    expect(err.code).toBe('preview_stale');
    expect(err.message).toMatch(/^Nothing was created or emailed/);
    expect(err.details.preview.posts_in_dates).toBe(4);
    expect(posted()).toEqual([]);

    // The new preview is the one on record now: confirming it goes through.
    const out = payload(await client.callTool({ name: 'create_content_plan', arguments: { ...ARGS, confirm: true } }));
    expect(out.approver_notified).toBe(true);
    expect(posted()).toHaveLength(1);
  });

  it('preview_stale: a channel needing reconnect since the preview', async () => {
    const { state, routes } = world();
    const client = await open(routes);
    await client.callTool({ name: 'create_content_plan', arguments: ARGS });
    state.accounts[1] = account(X, 'x', { status: 'expired', reconnect_required: true });

    const err = payload(await client.callTool({ name: 'create_content_plan', arguments: { ...ARGS, confirm: true } })).error;
    expect(err.code).toBe('preview_stale');
    expect(err.details.preview.warnings.join(' ')).toContain('Needs reconnecting before its posts can go out: x x_user.');
    expect(posted()).toEqual([]);
  });

  it('a preview older than 10 minutes is not confirmable', async () => {
    let now = Date.parse('2026-11-12T09:00:00Z');
    const { PlanPreviews } = await import('../src/tools/contentPlan.js');
    const p = new PlanPreviews(() => now);
    p.remember('u:k', 'state');
    now += 9 * 60_000;
    expect(p.take('u:k')).toBe('state');
    now += 2 * 60_000;
    expect(p.take('u:k')).toBeUndefined();
  });

  it('cancelled posts are not counted; an empty range says posts added later still show', async () => {
    const { state, routes } = world();
    state.posts = [post(P1, { status: 'cancelled', scheduled_at: '2026-12-02T02:00:00Z' })];
    const client = await open(routes);
    const out = payload(await client.callTool({ name: 'create_content_plan', arguments: ARGS }));
    expect(out.preview.posts_in_dates).toBe(0);
    expect(out.preview.warnings).toContain('No posts in these dates yet. The plan still shows posts added to them later.');
  });

  it('refuses an unknown channel, a bad timezone, reversed dates and more than 93 days — before any write', async () => {
    const { routes } = world();
    const client = await open(routes);
    const unknown = payload(await client.callTool({ name: 'create_content_plan', arguments: { ...ARGS, channel_ids: ['01JNOPENOPENOPENOPENOPENO1'] } })).error;
    expect(unknown).toMatchObject({ code: 'not_found', details: { channel_ids: ['01JNOPENOPENOPENOPENOPENO1'] } });

    const tz = payload(await client.callTool({ name: 'create_content_plan', arguments: { ...ARGS, timezone: 'Mars/Olympus' } })).error;
    expect(tz).toMatchObject({ code: 'invalid_input', details: { field: 'timezone' } });

    const reversed = payload(await client.callTool({ name: 'create_content_plan', arguments: { ...ARGS, date_from: '2026-12-31', date_to: '2026-12-01' } })).error;
    expect(reversed).toMatchObject({ code: 'invalid_input', details: { field: 'date_to' } });

    const long = payload(await client.callTool({ name: 'create_content_plan', arguments: { ...ARGS, date_from: '2026-01-01', date_to: '2026-06-01' } })).error;
    expect(long).toMatchObject({ code: 'invalid_input', details: { max_days: 93 } });

    expect(posted()).toEqual([]);
  });

  it('needs the approve scope: a read+draft token gets scope_missing and no API call', async () => {
    const { routes } = world();
    const client = await open(routes, TOKEN_ALL);
    const res = await client.callTool({ name: 'create_content_plan', arguments: ARGS });
    expect(res.isError).toBe(true);
    expect(payload(res).error.code).toBe('scope_missing');
    expect(app!.v1.calls).toEqual([]);
  });

  it('UTC when no timezone is given (the API uses the account timezone)', async () => {
    const { routes } = world();
    const client = await open(routes);
    const noTz: Record<string, unknown> = { ...ARGS };
    delete noTz.timezone;
    const out = payload(await client.callTool({ name: 'create_content_plan', arguments: noTz }));
    expect(out.preview.timezone).toBe('the account timezone');
    expect(app!.v1.calls.find((c) => c.path === '/social-posts')!.query).toMatchObject({ from: '2026-12-01T00:00:00.000Z', to: '2026-12-31T23:59:59.000Z' });
  });
});

describe('startOfDay', () => {
  it('is local midnight in the zone, across DST', () => {
    expect(new Date(startOfDay('2026-12-01', 'Asia/Jakarta')).toISOString()).toBe('2026-11-30T17:00:00.000Z');
    expect(new Date(startOfDay('2026-07-01', 'Europe/London')).toISOString()).toBe('2026-06-30T23:00:00.000Z');
    expect(new Date(startOfDay('2026-12-01', 'Europe/London')).toISOString()).toBe('2026-12-01T00:00:00.000Z');
    expect(new Date(startOfDay('2026-03-29', 'Europe/London')).toISOString()).toBe('2026-03-29T00:00:00.000Z');
    expect(new Date(startOfDay('2026-12-01', 'America/Los_Angeles')).toISOString()).toBe('2026-12-01T08:00:00.000Z');
  });
});

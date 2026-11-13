import { afterEach, describe, expect, it } from 'vitest';
import { post } from './fixtures.js';
import { previewTokens } from '../src/tools/m2.js';
import { payload, startApp, TOKEN_ALL, TOKEN_FULL, TOKEN_READ } from './helpers.js';

const IG = '01JACCTACCTACCTACCTACCTIG1';
const POST = '01JPOSTPOSTPOSTPOSTPOSTPO1';
const LINK = '01JLINKLINKLINKLINKLINKLI1';
const FUTURE = new Date(Date.now() + 86_400_000).toISOString();

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
  previewTokens.clear();
});

const call = async (name: string, args: Record<string, unknown> = {}, routes = {}, token = TOKEN_FULL) => {
  app = await startApp({ routes });
  const client = await app.connect(token);
  return client.callTool({ name, arguments: args });
};

const previewBody = (extra: Record<string, unknown> = {}) => ({
  data: {
    post_id: POST,
    sendable: true,
    status: 'draft',
    scheduled_at: null,
    targets: [{ channel_id: IG, platform: 'instagram', username: 'studio', usable: true, credits: 15 }],
    credits: { price: 15, from_topup: 0, monthly_balance: 300, topup_balance: 0, can_afford: true },
    daily_cap: { cap: 20, used: 3, remaining: 17 },
    confirm_token: 'tok1.sig',
    confirm_expires_at: new Date(Date.now() + 600_000).toISOString(),
    ...extra,
  },
});

const PREVIEW_ROUTE = `GET /social-posts/${POST}/send-preview`;
const SEND_ROUTE = `POST /social-posts/${POST}/send`;

/** Preview (confirm false), then confirm: true — in one app, as a client would. */
const twoStep = async (name: string, args: Record<string, unknown>, routes: Record<string, unknown>, token = TOKEN_FULL) => {
  app = await startApp({ routes: { [PREVIEW_ROUTE]: () => ({ body: previewBody() }), ...routes } as never });
  const client = await app.connect(token);
  const shown = await client.callTool({ name, arguments: args });
  const confirmed = await client.callTool({ name, arguments: { ...args, confirm: true } });
  return { shown, confirmed, client };
};

const sent = (body: unknown) => ({ body: { data: post(POST, { status: 'scheduled', ...(body as object) }) } });

describe('audit headers', () => {
  it('labels every V1 call with X-MCP-Tool and X-MCP-Client', async () => {
    await call('get_post', { post_id: POST }, { [`GET /social-posts/${POST}`]: () => ({ body: { data: post(POST) } }) });
    const h = app!.v1.calls[0]!.headers;
    expect(h.get('x-mcp-tool')).toBe('get_post');
    expect(h.get('x-mcp-client')).toBe('Claude');
  });
});

describe('create_approval_link', () => {
  it('posts the ids and returns the shareable url', async () => {
    const res = await call(
      'create_approval_link',
      { post_ids: [POST], approver_email: 'rani@client.test', title: 'November', expires_in_hours: 168 },
      {
        'POST /social-approval-links': () => ({
          status: 201,
          body: { data: { id: LINK, url: 'https://creatif.space/a/tok', status: 'active', posts: 1, approver_email: 'rani@client.test', expires_at: 'x', counts: {} } },
        }),
      },
    );
    expect(app!.v1.calls[0]!.body).toEqual({ post_ids: [POST], approver_email: 'rani@client.test', title: 'November', expires_in_hours: 168 });
    expect(payload(res).link).toEqual({ id: LINK, url: 'https://creatif.space/a/tok', status: 'active', posts: 1, approver_email: 'rani@client.test', expires_at: 'x' });
  });

  it('creates a Content Plan from a date range and channels', async () => {
    const res = await call(
      'create_approval_link',
      { date_from: '2026-12-01', date_to: '2026-12-31', timezone: 'Asia/Jakarta', channel_ids: [POST], approver_email: 'rani@client.test' },
      {
        'POST /social-approval-links': () => ({
          status: 201,
          body: { data: { id: LINK, url: 'https://creatif.space/a/tok', status: 'active', posts: 4, kind: 'plan', scope: { date_from: '2026-12-01', date_to: '2026-12-31' }, approver_email: 'rani@client.test', expires_at: null } },
        }),
      },
    );
    expect(app!.v1.calls[0]!.body).toEqual({
      date_from: '2026-12-01', date_to: '2026-12-31', timezone: 'Asia/Jakarta', channel_ids: [POST], approver_email: 'rani@client.test',
    });
    expect(payload(res).link).toMatchObject({ kind: 'plan', posts: 4, scope: { date_from: '2026-12-01', date_to: '2026-12-31' } });
  });

  it('refuses post_ids together with a date range, and half a range, without calling the API', async () => {
    const both = await call('create_approval_link', { post_ids: [POST], date_from: '2026-12-01', date_to: '2026-12-31', approver_email: 'rani@client.test' }, {});
    expect(payload(both).error.code).toBe('invalid_input');
    const half = await call('create_approval_link', { date_from: '2026-12-01', approver_email: 'rani@client.test' }, {});
    expect(payload(half).error.code).toBe('invalid_input');
    const bad = await call('create_approval_link', { date_from: '1 Dec', date_to: '2026-12-31', approver_email: 'rani@client.test' }, {});
    expect(bad.isError).toBe(true);
    expect(app!.v1.calls).toHaveLength(0);
  });

  it('requires approver_email and never calls the API without one', async () => {
    const res = await call('create_approval_link', { post_ids: [POST] }, {});
    expect(res.isError).toBe(true);
    expect(app!.v1.calls).toHaveLength(0);
  });

  it('refuses an approver_email that is not an address', async () => {
    const res = await call('create_approval_link', { post_ids: [POST], approver_email: 'rani' }, {});
    expect(res.isError).toBe(true);
    expect(app!.v1.calls).toHaveLength(0);
  });

  it('needs the approve scope', async () => {
    const res = await call('create_approval_link', { post_ids: [POST], approver_email: 'rani@client.test' }, {}, TOKEN_ALL);
    expect(payload(res).error).toMatchObject({ code: 'scope_missing', details: { required: 'approve' } });
    expect(app!.v1.calls).toHaveLength(0);
  });

  it('maps the Free-plan refusal to plan_required', async () => {
    const res = await call('create_approval_link', { post_ids: [POST], approver_email: 'rani@client.test' }, {
      'POST /social-approval-links': () => ({ status: 422, body: { message: 'Client approvals are part of Pro and Max.', errors: {} } }),
    });
    expect(payload(res).error.code).toBe('plan_required');
  });

  it('refuses an expiry the app does not offer', async () => {
    const res = await call('create_approval_link', { post_ids: [POST], approver_email: 'rani@client.test', expires_in_hours: 5 }, {});
    expect(res.isError).toBe(true);
    expect(app!.v1.calls).toHaveLength(0);
  });
});

describe('check_approval_status', () => {
  it('reads one post’s approval and feedback (read scope is enough)', async () => {
    const res = await call(
      'check_approval_status',
      { post_id: POST },
      {
        [`GET /social-posts/${POST}`]: () => ({
          body: { data: post(POST, { approval: 'client', approval_by: 'Dana', approval_at: 'y', comments: [{ body: 'tweak' }] }) },
        }),
      },
      TOKEN_READ,
    );
    expect(payload(res)).toEqual({
      post_id: POST, status: 'scheduled', approval: 'client', approval_by: 'Dana', approval_at: 'y', feedback: [{ body: 'tweak' }],
    });
  });

  it('reads a whole link', async () => {
    const res = await call('check_approval_status', { link_id: LINK }, {
      [`GET /social-approval-links/${LINK}`]: () => ({
        body: { data: { link: { id: LINK, counts: { approved: 1 } }, posts: [post(POST, { approval: 'approved' })] } },
      }),
    });
    const out = payload(res);
    expect(out.link.counts.approved).toBe(1);
    expect(out.posts[0]).toMatchObject({ id: POST, approval: 'approved' });
  });

  it('wants exactly one of post_id / link_id', async () => {
    const res = await call('check_approval_status', {}, {});
    expect(payload(res).error.code).toBe('invalid_input');
  });
});

describe('send_approval_reminder', () => {
  it('posts to /remind with the approve scope and passes the reminder back', async () => {
    const res = await call('send_approval_reminder', { link_id: LINK }, {
      [`POST /social-approval-links/${LINK}/remind`]: () => ({
        body: { data: { link_id: LINK, reminded_at: '2026-11-12T09:00:00+00:00', next_reminder_at: '2026-11-12T21:00:00+00:00', waiting: 2 } },
      }),
    });
    const out = payload(res);
    expect(out.reminder).toEqual({ link_id: LINK, reminded_at: '2026-11-12T09:00:00+00:00', next_reminder_at: '2026-11-12T21:00:00+00:00', waiting: 2 });
    expect(out.note).toMatch(/2 posts/);
  });

  it('inside 12 hours: rate_limited with next_reminder_at, no retry', async () => {
    const res = await call('send_approval_reminder', { link_id: LINK }, {
      [`POST /social-approval-links/${LINK}/remind`]: () => ({
        status: 429,
        body: { message: 'A reminder went out less than 12 hours ago.', code: 'reminder_too_soon', next_reminder_at: '2026-11-12T21:00:00+00:00' },
      }),
    });
    expect(res.isError).toBe(true);
    const e = payload(res).error;
    expect(e.code).toBe('rate_limited');
    expect(e.details).toMatchObject({ reason: 'reminder_too_soon', next_reminder_at: '2026-11-12T21:00:00+00:00' });
  });

  it('nothing waiting: invalid_input with the reason', async () => {
    const res = await call('send_approval_reminder', { link_id: LINK }, {
      [`POST /social-approval-links/${LINK}/remind`]: () => ({ status: 422, body: { message: 'Nothing on this link is waiting for an answer.', code: 'nothing_waiting' } }),
    });
    const e = payload(res).error;
    expect(e.code).toBe('invalid_input');
    expect(e.details.reason).toBe('nothing_waiting');
  });

  it('needs the approve scope', async () => {
    const res = await call('send_approval_reminder', { link_id: LINK }, {}, TOKEN_READ);
    expect(res.isError).toBe(true);
    expect(payload(res).error.code).toBe('scope_missing');
    expect(app!.v1.calls).toHaveLength(0);
  });
});

describe('update_post', () => {
  it('patches a draft with the draft scope', async () => {
    const res = await call(
      'update_post',
      { post_id: POST, caption: 'New', labels: ['nov'] },
      {
        [`GET /social-posts/${POST}`]: () => ({ body: { data: post(POST, { status: 'draft', scheduled_at: null }) } }),
        [`PATCH /social-posts/${POST}`]: ({ body }: { body: unknown }) => ({ body: { data: post(POST, { status: 'draft', ...(body as object) }) } }),
      },
      TOKEN_ALL,
    );
    expect(app!.v1.calls[1]).toMatchObject({ method: 'PATCH', body: { caption: 'New', labels: ['nov'] } });
    expect(payload(res).post.caption).toBe('New');
  });

  it('refuses a post already queued to go out, before calling PATCH', async () => {
    const res = await call('update_post', { post_id: POST, scheduled_at: FUTURE }, {
      [`GET /social-posts/${POST}`]: () => ({ body: { data: post(POST, { status: 'scheduled', approval: null }) } }),
    });
    expect(payload(res).error.code).toBe('not_editable');
    expect(app!.v1.calls.map((c) => c.method)).toEqual(['GET']);
  });

  it('maps the API refusal (409 not_editable) too', async () => {
    const res = await call('update_post', { post_id: POST, caption: 'x' }, {
      [`GET /social-posts/${POST}`]: () => ({ body: { data: post(POST, { status: 'draft' }) } }),
      [`PATCH /social-posts/${POST}`]: () => ({ status: 409, body: { message: 'Connected apps can only change drafts…', code: 'not_editable' } }),
    });
    expect(payload(res).error.code).toBe('not_editable');
  });

  it('refuses an empty change', async () => {
    const res = await call('update_post', { post_id: POST }, {});
    expect(payload(res).error.code).toBe('invalid_input');
  });
});

describe('schedule_post', () => {
  it('without confirm: preview only — channels and Credit cost, no send', async () => {
    const res = await call('schedule_post', { post_id: POST, scheduled_at: FUTURE }, {
      [`GET /social-posts/${POST}/send-preview`]: () => ({ body: previewBody() }),
    });
    const out = payload(res);
    expect(out).toMatchObject({
      confirmation_required: true,
      action: 'schedule',
      scheduled_at: FUTURE,
      credit_cost: 15,
      sends_left_today: 17,
      channels: [{ channel_id: IG, platform: 'instagram', username: 'studio', credits: 15 }],
    });
    expect(out.blocked).toBeUndefined();
    expect(app!.v1.calls.map((c) => `${c.method} ${c.path}`)).toEqual([`GET /social-posts/${POST}/send-preview`]);
  });

  it('with confirm: true sends with scheduled_at and the preview’s confirm_token', async () => {
    const { confirmed } = await twoStep('schedule_post', { post_id: POST, scheduled_at: FUTURE }, {
      [SEND_ROUTE]: ({ body }: { body: unknown }) => sent({ scheduled_at: (body as { scheduled_at: string }).scheduled_at }),
    });
    expect(app!.v1.calls.map((c) => `${c.method} ${c.path}`)).toEqual([PREVIEW_ROUTE, SEND_ROUTE]);
    expect(app!.v1.calls[1]!.body).toEqual({ scheduled_at: FUTURE, confirm_token: 'tok1.sig' });
    expect(payload(confirmed).post.status).toBe('scheduled');
  });

  it('refuses a past time before calling V1', async () => {
    const res = await call('schedule_post', { post_id: POST, scheduled_at: '2020-01-01T00:00:00Z', confirm: true }, {});
    expect(payload(res).error.code).toBe('invalid_input');
    expect(app!.v1.calls).toHaveLength(0);
  });

  it('needs the publish scope', async () => {
    const res = await call('schedule_post', { post_id: POST, scheduled_at: FUTURE }, {}, TOKEN_ALL);
    expect(payload(res).error).toMatchObject({ code: 'scope_missing', details: { required: 'publish' } });
  });
});

describe('publish_post', () => {
  it('preview flags what blocks the send', async () => {
    const res = await call('publish_post', { post_id: POST }, {
      [`GET /social-posts/${POST}/send-preview`]: () => ({
        body: previewBody({
          credits: { price: 15, from_topup: 15, monthly_balance: 0, topup_balance: 0, can_afford: false },
          daily_cap: { cap: 20, used: 20, remaining: 0 },
          targets: [{ channel_id: IG, platform: 'instagram', username: 'studio', usable: false, credits: 15 }],
        }),
      }),
    });
    const out = payload(res);
    expect(out.confirmation_required).toBe(true);
    expect(out.blocked).toHaveLength(3);
    expect(out.next).toMatch(/cannot go out yet/);
  });

  it('refuses a post that is not a draft', async () => {
    const res = await call('publish_post', { post_id: POST }, {
      [`GET /social-posts/${POST}/send-preview`]: () => ({ body: previewBody({ sendable: false, status: 'published' }) }),
    });
    expect(payload(res).error).toMatchObject({ code: 'not_draft', details: { status: expect.any(String) } });
  });

  it('with confirm: true sends now with the preview’s confirm_token (no scheduled_at)', async () => {
    const { confirmed } = await twoStep('publish_post', { post_id: POST }, {
      [SEND_ROUTE]: () => sent({ status: 'published', scheduled_at: null }),
    });
    expect(app!.v1.calls[1]).toMatchObject({ method: 'POST', path: `/social-posts/${POST}/send`, body: { confirm_token: 'tok1.sig' } });
    expect(payload(confirmed).post.status).toBe('published');
  });

  it('maps the daily cap to daily_cap_reached', async () => {
    const { confirmed: res } = await twoStep('publish_post', { post_id: POST }, {
      [SEND_ROUTE]: () => ({
        status: 429,
        body: { message: 'Connected apps can send 20 posts a day.', code: 'daily_send_cap', cap: 20, resets_at: 'z' },
      }),
    });
    expect(payload(res).error).toMatchObject({ code: 'daily_cap_reached', details: { cap: 20, resets_at: 'z' } });
  });

  it('maps a short balance to insufficient_credits', async () => {
    const { confirmed: res } = await twoStep('publish_post', { post_id: POST }, {
      [SEND_ROUTE]: () => ({
        status: 422,
        body: { message: "This needs 15 Credits past this month's and the balance has 0 — top up, or send to fewer accounts." },
      }),
    });
    expect(payload(res).error.code).toBe('insufficient_credits');
  });

  it('maps the API token fence (403 insufficient_scope) to scope_missing', async () => {
    const { confirmed: res } = await twoStep('publish_post', { post_id: POST }, {
      [SEND_ROUTE]: () => ({ status: 403, body: { message: 'This needs the "publish" permission.', error: 'insufficient_scope' } }),
    });
    expect(payload(res).error.code).toBe('scope_missing');
  });
});

describe('plan and Credit limits are passed on verbatim', () => {
  const BILLING = 'https://creatif.space/settings?section=subscription';
  const APPROVAL_CLIENTS_402 = `On Free, approval links are for one client at a time. Approval for more clients is on Pro and Max. Upgrade: ${BILLING}`;
  const plan402 = (feature: string, message: string, limit?: number) => ({
    status: 402,
    body: {
      message,
      reason: 'plan_required',
      feature,
      required_plan: 'pro',
      ...(limit !== undefined ? { limit } : {}),
      code: 'plan_required',
      billing_url: BILLING,
      errors: { post_ids: [message] },
    },
  });

  it('402 plan_required → plan_required with the API sentence as the message', async () => {
    const res = await call('create_approval_link', { post_ids: [POST], approver_email: 'second@client.test', client_name: 'Second client' }, {
      'POST /social-approval-links': () => plan402('approval_clients', APPROVAL_CLIENTS_402, 1),
    });
    expect(payload(res).error).toMatchObject({
      code: 'plan_required',
      message: APPROVAL_CLIENTS_402,
      next_step: expect.stringMatching(/as it is/),
      details: { reason: 'plan_required', feature: 'approval_clients', required_plan: 'pro', limit: 1, billing_url: BILLING },
    });
  });

  it('schedule_post sends scheduled_at with the preview; a Free window refusal is plan_required before any confirm', async () => {
    const msg = `On Free, posts can be scheduled up to 7 days ahead. Scheduling further out is on Pro and Max. Upgrade: ${BILLING}`;
    const far = new Date(Date.now() + 8 * 86_400_000).toISOString();
    const res = await call('schedule_post', { post_id: POST, scheduled_at: far }, {
      [PREVIEW_ROUTE]: () => plan402('schedule_window', msg, 7),
    });
    expect(app!.v1.calls[0]!.query).toEqual({ scheduled_at: far });
    expect(app!.v1.calls.some((c) => c.method === 'POST')).toBe(false);
    expect(payload(res).error).toMatchObject({
      code: 'plan_required',
      message: msg,
      details: { feature: 'schedule_window', required_plan: 'pro', limit: 7 },
    });
  });

  it('schedule_post confirm: a queue_limit 402 from the send is plan_required', async () => {
    const msg = `On Free, up to 10 posts can be queued (scheduled or waiting for approval) at once. More is on Pro and Max. Upgrade: ${BILLING}`;
    const { confirmed } = await twoStep('schedule_post', { post_id: POST, scheduled_at: FUTURE }, {
      [SEND_ROUTE]: () => plan402('queue_limit', msg, 10),
    });
    expect(payload(confirmed).error).toMatchObject({ code: 'plan_required', message: msg, details: { feature: 'queue_limit', limit: 10 } });
  });

  it('publish_post: a 402 from the send is plan_required with the same fields', async () => {
    const msg = `Creating and editing Spaces and your link in bio is on Pro and Max. Upgrade: ${BILLING}`;
    const { confirmed } = await twoStep('publish_post', { post_id: POST }, {
      [SEND_ROUTE]: () => plan402('spaces', msg),
    });
    expect(payload(confirmed).error).toMatchObject({ code: 'plan_required', details: { reason: 'plan_required', feature: 'spaces', required_plan: 'pro' } });
    expect(payload(confirmed).error.details.limit).toBeUndefined();
  });

  it('insufficient_credits → needed vs available and the top-up link', async () => {
    const msg = 'Not enough Credits: this send needs 600 and 1 are available (0 monthly + 1 top-up). Top up: https://creatif.space/credits';
    const { confirmed: res } = await twoStep('publish_post', { post_id: POST }, {
      [SEND_ROUTE]: () => ({
        status: 422,
        body: { message: msg, code: 'insufficient_credits', needed: 600, available: 1, monthly_balance: 0, topup_balance: 1, topup_url: 'https://creatif.space/credits', resets_at: 'r', errors: { caption: [msg] } },
      }),
    });
    expect(payload(res).error).toMatchObject({
      code: 'insufficient_credits',
      message: msg,
      details: { needed: 600, available: 1, topup_url: 'https://creatif.space/credits' },
    });
    expect(payload(res).error.next_step).toMatch(/Nothing was charged/);
  });

  it('quota_exceeded → the reset date', async () => {
    const msg = "This month's Credits on the Free plan are used up — they reset on 1 Dec 2026. This send needs 300 Credits. Top up now: https://creatif.space/credits";
    const { confirmed: res } = await twoStep('schedule_post', { post_id: POST, scheduled_at: FUTURE }, {
      [SEND_ROUTE]: () => ({
        status: 422,
        body: { message: msg, code: 'quota_exceeded', needed: 300, available: 0, resets_at: '2026-12-01T00:00:00+00:00', topup_url: 'https://creatif.space/credits' },
      }),
    });
    expect(payload(res).error).toMatchObject({
      code: 'quota_exceeded',
      message: msg,
      details: { resets_at: '2026-12-01T00:00:00+00:00', needed: 300, available: 0 },
    });
  });

  it('tool descriptions state the plan and Credit rules', async () => {
    app = await startApp();
    const { tools } = await (await app.connect(TOKEN_FULL)).listTools();
    const d = (n: string) => tools.find((t) => t.name === n)!.description!;
    expect(d('create_approval_link')).toMatch(/On some plans, links are for one client at a time/);
    expect(d('create_approval_link')).toMatch(/approval_clients, schedule_window or queue_limit/);
    expect(d('create_approval_link')).not.toMatch(/Pro and Max plans only/);
    expect(d('schedule_post')).toMatch(/at most 7 days ahead and at most 10 posts/);
    expect(d('get_credits')).toMatch(/approval clients used vs allowed/);
    expect(d('publish_post')).toMatch(/insufficient_credits or quota_exceeded/);
    expect(d('schedule_post')).toMatch(/Monthly Credits first/);
    expect(d('get_credits')).toMatch(/Every plan can use this server/);
    // No plan names in any description (directory rule); limits are stated neutrally.
    for (const t of tools) expect(t.description, t.name).not.toMatch(/\bFree\b/);
  });
});

describe('confirm_token (two-step flow)', () => {
  it('never hands the token to the model', async () => {
    const res = await call('publish_post', { post_id: POST }, { [PREVIEW_ROUTE]: () => ({ body: previewBody() }) });
    expect(JSON.stringify(payload(res))).not.toContain('tok1.sig');
  });

  it('confirm: true with no preview shown sends nothing and returns a preview', async () => {
    const res = await call('publish_post', { post_id: POST, confirm: true }, {
      [PREVIEW_ROUTE]: () => ({ body: previewBody() }),
      [SEND_ROUTE]: () => sent({}),
    });
    const out = payload(res);
    expect(res.isError).toBeFalsy();
    expect(out).toMatchObject({ confirmation_required: true, action: 'publish', credit_cost: 15 });
    expect(out.note).toMatch(/nothing was sent/);
    expect(app!.v1.calls.map((c) => `${c.method} ${c.path}`)).toEqual([PREVIEW_ROUTE]);
  });

  it('a stale preview (409) sends nothing, returns the new preview, and the next confirm uses the new token', async () => {
    let previews = 0;
    let sends = 0;
    const { confirmed, client } = await twoStep('publish_post', { post_id: POST }, {
      [PREVIEW_ROUTE]: () => ({ body: previewBody({ confirm_token: `tok${++previews}.sig`, credits: { price: previews * 15, from_topup: 0, monthly_balance: 300, topup_balance: 0, can_afford: true } }) }),
      [SEND_ROUTE]: () => (++sends === 1
        ? { status: 409, body: { message: 'The preview is out of date.', code: 'preview_stale' } }
        : sent({ status: 'published' })),
    });
    const err = payload(confirmed).error;
    expect(confirmed.isError).toBe(true);
    expect(err.code).toBe('preview_stale');
    expect(err.details.preview).toMatchObject({ confirmation_required: true, credit_cost: 30 });

    const again = await client.callTool({ name: 'publish_post', arguments: { post_id: POST, confirm: true } });
    expect(payload(again).post.status).toBe('published');
    const bodies = app!.v1.calls.filter((c) => c.method === 'POST').map((c) => c.body);
    expect(bodies).toEqual([{ confirm_token: 'tok1.sig' }, { confirm_token: 'tok2.sig' }]);
  });

  it('an expired preview is not used: confirm previews again', async () => {
    await twoStep('publish_post', { post_id: POST }, {
      [PREVIEW_ROUTE]: () => ({ body: previewBody({ confirm_expires_at: new Date(Date.now() - 1000).toISOString() }) }),
      [SEND_ROUTE]: () => sent({}),
    });
    expect(app!.v1.calls.some((c) => c.method === 'POST')).toBe(false);
  });

  it('a token is used once: a second confirm previews again', async () => {
    const { client } = await twoStep('publish_post', { post_id: POST }, { [SEND_ROUTE]: () => sent({ status: 'published' }) });
    await client.callTool({ name: 'publish_post', arguments: { post_id: POST, confirm: true } });
    expect(app!.v1.calls.filter((c) => c.method === 'POST')).toHaveLength(1);
  });

  it('is kept per user: another user’s preview does not confirm', async () => {
    app = await startApp({ routes: { [PREVIEW_ROUTE]: () => ({ body: previewBody() }), [SEND_ROUTE]: () => sent({}) } as never });
    const a = await app.connect(TOKEN_FULL);
    await a.callTool({ name: 'publish_post', arguments: { post_id: POST } });
    const b = await app.connect('stub:01JOTHEROTHEROTHEROTHEROTH:read,draft,approve,publish:Claude');
    const res = await b.callTool({ name: 'publish_post', arguments: { post_id: POST, confirm: true } });
    expect(payload(res).confirmation_required).toBe(true);
    expect(app!.v1.calls.some((c) => c.method === 'POST')).toBe(false);
  });
});

describe('approval enforced by the API', () => {
  it('maps 409 approval_required on a confirmed send', async () => {
    const { confirmed } = await twoStep('publish_post', { post_id: POST }, {
      [SEND_ROUTE]: () => ({ status: 409, body: { message: 'This draft needs its client’s approval.', code: 'approval_required' } }),
    });
    expect(payload(confirmed).error.code).toBe('approval_required');
  });
});

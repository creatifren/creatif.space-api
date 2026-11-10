import { afterEach, describe, expect, it } from 'vitest';
import { post } from './fixtures.js';
import { payload, startApp, TOKEN_ALL, TOKEN_FULL, TOKEN_READ } from './helpers.js';

const IG = '01JACCTACCTACCTACCTACCTIG1';
const POST = '01JPOSTPOSTPOSTPOSTPOSTPO1';
const LINK = '01JLINKLINKLINKLINKLINKLI1';
const FUTURE = new Date(Date.now() + 86_400_000).toISOString();

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
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
    ...extra,
  },
});

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
      { post_ids: [POST], title: 'November', expires_in_hours: 168 },
      {
        'POST /social-approval-links': () => ({
          status: 201,
          body: { data: { id: LINK, url: 'https://creatif.space/a/tok', status: 'active', posts: 1, expires_at: 'x', counts: {} } },
        }),
      },
    );
    expect(app!.v1.calls[0]!.body).toEqual({ post_ids: [POST], title: 'November', expires_in_hours: 168 });
    expect(payload(res).link).toEqual({ id: LINK, url: 'https://creatif.space/a/tok', status: 'active', posts: 1, expires_at: 'x' });
  });

  it('needs the approve scope', async () => {
    const res = await call('create_approval_link', { post_ids: [POST] }, {}, TOKEN_ALL);
    expect(payload(res).error).toMatchObject({ code: 'scope_missing', details: { required: 'approve' } });
    expect(app!.v1.calls).toHaveLength(0);
  });

  it('maps the Free-plan refusal to plan_required', async () => {
    const res = await call('create_approval_link', { post_ids: [POST] }, {
      'POST /social-approval-links': () => ({ status: 422, body: { message: 'Client approvals are part of Pro and Max.', errors: {} } }),
    });
    expect(payload(res).error.code).toBe('plan_required');
  });

  it('refuses an expiry the app does not offer', async () => {
    const res = await call('create_approval_link', { post_ids: [POST], expires_in_hours: 5 }, {});
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

  it('needs publish to change a post already queued to go out', async () => {
    const res = await call('update_post', { post_id: POST, scheduled_at: FUTURE }, {
      [`GET /social-posts/${POST}`]: () => ({ body: { data: post(POST, { status: 'scheduled', approval: null }) } }),
    }, TOKEN_ALL);
    expect(payload(res).error).toMatchObject({ code: 'scope_missing', details: { required: 'publish' } });
    expect(app!.v1.calls.map((c) => c.method)).toEqual(['GET']);
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

  it('with confirm: true sends with scheduled_at', async () => {
    const res = await call('schedule_post', { post_id: POST, scheduled_at: FUTURE, confirm: true }, {
      [`POST /social-posts/${POST}/send`]: ({ body }: { body: unknown }) => sent({ scheduled_at: (body as { scheduled_at: string }).scheduled_at }),
    });
    expect(app!.v1.calls[0]).toMatchObject({ method: 'POST', path: `/social-posts/${POST}/send`, body: { scheduled_at: FUTURE } });
    expect(payload(res).post.status).toBe('scheduled');
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
    expect(payload(res).error).toMatchObject({ code: 'invalid_input' });
  });

  it('with confirm: true sends now (no scheduled_at)', async () => {
    const res = await call('publish_post', { post_id: POST, confirm: true }, {
      [`POST /social-posts/${POST}/send`]: () => sent({ status: 'published', scheduled_at: null }),
    });
    expect(app!.v1.calls[0]).toMatchObject({ method: 'POST', body: {} });
    expect(payload(res).post.status).toBe('published');
  });

  it('maps the daily cap to daily_cap_reached', async () => {
    const res = await call('publish_post', { post_id: POST, confirm: true }, {
      [`POST /social-posts/${POST}/send`]: () => ({
        status: 429,
        body: { message: 'Connected apps can send 20 posts a day.', code: 'daily_send_cap', cap: 20, resets_at: 'z' },
      }),
    });
    expect(payload(res).error).toMatchObject({ code: 'daily_cap_reached', details: { cap: 20, resets_at: 'z' } });
  });

  it('maps a short balance to insufficient_credits', async () => {
    const res = await call('publish_post', { post_id: POST, confirm: true }, {
      [`POST /social-posts/${POST}/send`]: () => ({
        status: 422,
        body: { message: "This needs 15 Credits past this month's and the balance has 0 — top up, or send to fewer accounts." },
      }),
    });
    expect(payload(res).error.code).toBe('insufficient_credits');
  });

  it('maps the API token fence (403 insufficient_scope) to scope_missing', async () => {
    const res = await call('publish_post', { post_id: POST, confirm: true }, {
      [`POST /social-posts/${POST}/send`]: () => ({ status: 403, body: { message: 'This needs the "publish" permission.', error: 'insufficient_scope' } }),
    });
    expect(payload(res).error.code).toBe('scope_missing');
  });
});

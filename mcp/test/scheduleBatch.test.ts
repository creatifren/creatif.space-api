import { afterEach, describe, expect, it } from 'vitest';
import { post } from './fixtures.js';
import { previewTokens } from '../src/tools/m2.js';
import { batchPreviews } from '../src/tools/scheduleBatch.js';
import { payload, startApp, TOKEN_ALL, TOKEN_FULL } from './helpers.js';

const P1 = '01JPOSTPOSTPOSTPOSTPOSTPO1';
const P2 = '01JPOSTPOSTPOSTPOSTPOSTPO2';
const IG = '01JACCTACCTACCTACCTACCTIG1';
const T1 = '2026-12-01T09:00:00+07:00';
const T2 = '2026-12-02T09:00:00+07:00';
const POSTS = [{ post_id: P1, scheduled_at: T1 }, { post_id: P2, scheduled_at: T2 }];

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
  previewTokens.clear();
  batchPreviews.clear();
});

let n = 0;
const previewOf = (id: string, extra: { price?: number; usable?: boolean; balance?: number; remaining?: number } = {}) => ({
  body: {
    data: {
      post_id: id,
      sendable: true,
      status: 'draft',
      scheduled_at: null,
      targets: [{ channel_id: IG, platform: 'instagram', username: 'studio', usable: extra.usable ?? true, credits: extra.price ?? 15 }],
      credits: { price: extra.price ?? 15, from_topup: 0, monthly_balance: extra.balance ?? 300, topup_balance: 0, can_afford: true },
      daily_cap: { cap: 20, used: 3, remaining: extra.remaining ?? 17 },
      confirm_token: `tok-${id.slice(-1)}-${++n}`,
      confirm_expires_at: new Date(Date.now() + 600_000).toISOString(),
    },
  },
});

const routes = (over: Record<string, unknown> = {}) => ({
  [`GET /social-posts/${P1}/send-preview`]: () => previewOf(P1),
  [`GET /social-posts/${P2}/send-preview`]: () => previewOf(P2),
  'POST /social-posts/send-batch': () => ({ body: { data: { scheduled: [post(P1), post(P2)] } } }),
  ...over,
});

const open = async (r: Record<string, unknown>, token = TOKEN_FULL) => {
  app = await startApp({ routes: r as never });
  return app.connect(token);
};
const batchCalls = () => app!.v1.calls.filter((c) => c.method === 'POST' && c.path === '/social-posts/send-batch');

describe('schedule_posts', () => {
  it('preview: every post with its time, channels and cost, the total, and nothing sent', async () => {
    const client = await open(routes());
    const out = payload(await client.callTool({ name: 'schedule_posts', arguments: { posts: POSTS } }));
    expect(out).toMatchObject({ confirmation_required: true, total_credit_cost: 30, credits_available: 300, sends_left_today: 17 });
    expect(out.posts).toEqual([
      { post_id: P1, scheduled_at: T1, channels: [{ channel_id: IG, platform: 'instagram', username: 'studio', credits: 15 }], credit_cost: 15 },
      { post_id: P2, scheduled_at: T2, channels: [{ channel_id: IG, platform: 'instagram', username: 'studio', credits: 15 }], credit_cost: 15 },
    ]);
    expect(out.blocked).toBeUndefined();
    expect(out.next).toMatch(/^Nothing was scheduled\./);
    expect(app!.v1.calls.find((c) => c.path === `/social-posts/${P1}/send-preview`)!.query).toEqual({ scheduled_at: T1 });
    expect(batchCalls()).toEqual([]);
  });

  it('confirm after the preview: one batch call with each preview’s token, all scheduled', async () => {
    const client = await open(routes());
    await client.callTool({ name: 'schedule_posts', arguments: { posts: POSTS } });
    const out = payload(await client.callTool({ name: 'schedule_posts', arguments: { posts: POSTS, confirm: true } }));

    expect(batchCalls()).toHaveLength(1);
    const body = batchCalls()[0]!.body as { posts: { post_id: string; scheduled_at: string; confirm_token: string }[] };
    expect(body.posts.map((p) => [p.post_id, p.scheduled_at])).toEqual([[P1, T1], [P2, T2]]);
    expect(body.posts.every((p) => /^tok-/.test(p.confirm_token))).toBe(true);
    expect(batchCalls()[0]!.headers.get('x-mcp-tool')).toBe('schedule_posts');
    expect(out.scheduled.map((p: { id: string }) => p.id)).toEqual([P1, P2]);

    // Used up: confirming again previews again and sends nothing more.
    const again = payload(await client.callTool({ name: 'schedule_posts', arguments: { posts: POSTS, confirm: true } }));
    expect(again.confirmation_required).toBe(true);
    expect(batchCalls()).toHaveLength(1);
  });

  it('confirm with no preview shown, or with other times: a preview, nothing sent', async () => {
    const client = await open(routes());
    const cold = payload(await client.callTool({ name: 'schedule_posts', arguments: { posts: POSTS, confirm: true } }));
    expect(cold.note).toMatch(/No current preview/);
    const moved = [{ post_id: P1, scheduled_at: '2026-12-05T09:00:00+07:00' }, POSTS[1]];
    const out = payload(await client.callTool({ name: 'schedule_posts', arguments: { posts: moved, confirm: true } }));
    expect(out.confirmation_required).toBe(true);
    expect(batchCalls()).toEqual([]);
  });

  it('partial failure rolled back by the API → batch_failed with what was taken back; nothing scheduled', async () => {
    const client = await open(
      routes({
        'POST /social-posts/send-batch': () => ({
          status: 409,
          body: { message: 'Nothing was scheduled: one post was refused, so the others were taken back.', code: 'batch_failed', failed_post_id: P2, reason: 'boom', rolled_back: [P1], rollback_failed: [] },
        }),
      }),
    );
    await client.callTool({ name: 'schedule_posts', arguments: { posts: POSTS } });
    const res = await client.callTool({ name: 'schedule_posts', arguments: { posts: POSTS, confirm: true } });
    expect(res.isError).toBe(true);
    const err = payload(res).error;
    expect(err).toMatchObject({ code: 'batch_failed', details: { failed_post_id: P2, reason: 'boom', rolled_back: [P1], rollback_failed: [] } });
    expect(err.message).toMatch(/^Nothing was scheduled/);
    expect(err.next_step).toMatch(/preview again/);
  });

  it('preview_stale from the API → the new preview in details, which can then be confirmed', async () => {
    let first = true;
    const client = await open(
      routes({
        'POST /social-posts/send-batch': () => {
          if (first) {
            first = false;
            return { status: 409, body: { message: 'stale', code: 'preview_stale', refused: [{ post_id: P2, code: 'preview_stale' }] } };
          }
          return { body: { data: { scheduled: [post(P1), post(P2)] } } };
        },
      }),
    );
    await client.callTool({ name: 'schedule_posts', arguments: { posts: POSTS } });
    const err = payload(await client.callTool({ name: 'schedule_posts', arguments: { posts: POSTS, confirm: true } })).error;
    expect(err.code).toBe('preview_stale');
    expect(err.details.preview.total_credit_cost).toBe(30);
    const out = payload(await client.callTool({ name: 'schedule_posts', arguments: { posts: POSTS, confirm: true } }));
    expect(out.scheduled).toHaveLength(2);
  });

  it('maps the API refusals: not_schedulable, insufficient_credits, daily_cap_reached', async () => {
    for (const [status, code, ours] of [
      [409, 'not_schedulable', 'not_schedulable'],
      [422, 'not_enough_credits', 'insufficient_credits'],
      [429, 'daily_send_cap', 'daily_cap_reached'],
    ] as const) {
      const client = await open(routes({ 'POST /social-posts/send-batch': () => ({ status, body: { message: 'Nothing was scheduled.', code } }) }));
      await client.callTool({ name: 'schedule_posts', arguments: { posts: POSTS } });
      expect(payload(await client.callTool({ name: 'schedule_posts', arguments: { posts: POSTS, confirm: true } })).error.code, code).toBe(ours);
      await app!.close();
      app = undefined;
      batchPreviews.clear();
    }
  });

  it('the preview says what blocks the batch: total Credits, sends left today, a channel to reconnect', async () => {
    const client = await open(
      routes({
        [`GET /social-posts/${P1}/send-preview`]: () => previewOf(P1, { price: 200, balance: 250, remaining: 1 }),
        [`GET /social-posts/${P2}/send-preview`]: () => previewOf(P2, { price: 100, balance: 250, remaining: 1, usable: false }),
      }),
    );
    const out = payload(await client.callTool({ name: 'schedule_posts', arguments: { posts: POSTS } }));
    expect(out.total_credit_cost).toBe(300);
    expect(out.blocked).toEqual([
      'not enough Credits for all of them: 300 needed, 250 available — the user must top up first, or schedule fewer',
      'connected apps have 1 sends left today, fewer than 2',
      `${P2}: reconnect required for instagram studio`,
    ]);
  });

  it('refuses a post listed twice, more than 10, and a token without publish', async () => {
    const client = await open(routes());
    const dup = payload(await client.callTool({ name: 'schedule_posts', arguments: { posts: [POSTS[0], POSTS[0]] } })).error;
    expect(dup).toMatchObject({ code: 'invalid_input', details: { post_ids: [P1] } });
    const many = await client.callTool({ name: 'schedule_posts', arguments: { posts: Array.from({ length: 11 }, () => POSTS[0]) } });
    expect(many.isError).toBe(true);
    await app!.close();

    const draftOnly = await open(routes(), TOKEN_ALL);
    const res = await draftOnly.callTool({ name: 'schedule_posts', arguments: { posts: POSTS } });
    expect(payload(res).error.code).toBe('scope_missing');
    expect(app!.v1.calls).toEqual([]);
  });
});

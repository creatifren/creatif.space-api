import { afterEach, describe, expect, it } from 'vitest';
import { post } from './fixtures.js';
import { payload, startApp, TOKEN_READ } from './helpers.js';

const IG = '01JACCTACCTACCTACCTACCTIG1';
const X = '01JACCTACCTACCTACCTACCTXX1';
const P1 = '01JPOSTPOSTPOSTPOSTPOSTPO1';
const P2 = '01JPOSTPOSTPOSTPOSTPOSTPO2';
const P3 = '01JPOSTPOSTPOSTPOSTPOSTPO3';

const target = (id: string, platform: string, status: string, fail: [string, string] | null = null) => ({
  account: { id, platform, username: `${platform}_brand` },
  status,
  fail_reason: fail?.[1] ?? null,
  fail_code: fail?.[0] ?? null,
  platform_url: null,
});

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

const run = async (byStatus: Record<string, unknown[]>, args: Record<string, unknown> = {}) => {
  app = await startApp({
    routes: { 'GET /social-posts': (c) => ({ body: { data: byStatus[c.query.status!] ?? [] } }) },
  });
  const client = await app.connect(TOKEN_READ);
  return client.callTool({ name: 'list_failed_posts', arguments: args });
};

describe('list_failed_posts', () => {
  it('failed posts and partly-published ones, newest first, per channel code and reason (scope read)', async () => {
    const out = payload(
      await run({
        failed: [
          post(P1, {
            status: 'failed',
            scheduled_at: '2026-11-10T02:00:00Z',
            targets: [target(IG, 'instagram', 'failed', ['auth', 'Instagram needs you to reconnect this account in Creatif Space → Channels.'])],
          }),
        ],
        published: [
          post(P2, {
            status: 'published',
            scheduled_at: '2026-11-11T02:00:00Z',
            targets: [target(IG, 'instagram', 'published'), target(X, 'x', 'failed', ['rate_limited', 'X is limiting how often this account can post right now.'])],
          }),
          post(P3, { status: 'published', scheduled_at: '2026-11-12T02:00:00Z', targets: [target(IG, 'instagram', 'published')] }),
        ],
      }),
    );

    expect(out.count).toBe(2);
    expect(out.posts.map((p: { post_id: string }) => p.post_id)).toEqual([P2, P1]);
    expect(out.posts[0]).toMatchObject({
      status: 'published',
      retry: `retry_post { "post_id": "${P2}" }`,
      failed_channels: [{ channel_id: X, platform: 'x', username: 'x_brand', failure_code: 'rate_limited', reason: 'X is limiting how often this account can post right now.' }],
    });
    expect(out.posts[1].failed_channels).toEqual([
      { channel_id: IG, platform: 'instagram', username: 'instagram_brand', failure_code: 'auth', reason: 'Instagram needs you to reconnect this account in Creatif Space → Channels.' },
    ]);
    expect(out.posts[0].caption.length).toBeLessThanOrEqual(81);
    expect(out.next_step).toContain('retry_post');

    // The last 30 days, both kinds, up to 100 each.
    const calls = app!.v1.calls.filter((c) => c.path === '/social-posts');
    expect(calls.map((c) => c.query.status).sort()).toEqual(['failed', 'published']);
    for (const c of calls) {
      expect(c.query.limit).toBe('100');
      const days = (Date.parse(c.query.to!) - Date.parse(c.query.from!)) / 86_400_000;
      expect(days).toBeCloseTo(30, 3);
    }
    expect(calls.every((c) => c.headers.get('x-mcp-tool') === 'list_failed_posts')).toBe(true);
  });

  it('nothing failed: an empty list that says so', async () => {
    const out = payload(await run({ published: [post(P3, { status: 'published', targets: [target(IG, 'instagram', 'published')] })] }));
    expect(out).toMatchObject({ count: 0, total: 0, posts: [], next_cursor: null, next_step: 'Nothing failed in the last 30 days.' });
  });

  it('pages with limit and next_cursor', async () => {
    const failed = Array.from({ length: 3 }, (_, i) =>
      post(`01JPOSTPOSTPOSTPOSTPOSTPF${i}`, { status: 'failed', scheduled_at: `2026-11-0${i + 1}T02:00:00Z`, targets: [target(IG, 'instagram', 'failed', ['auth', 'r'])] }),
    );
    const first = payload(await run({ failed }, { limit: 2 }));
    expect(first.posts).toHaveLength(2);
    expect(first.next_cursor).toEqual(expect.any(String));
    await app!.close();
    const second = payload(await run({ failed }, { limit: 2, cursor: first.next_cursor }));
    expect(second.posts).toHaveLength(1);
    expect(second.next_cursor).toBeNull();
  });
});

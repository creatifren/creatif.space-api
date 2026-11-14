import { defineTool, describeTool } from './define.js';
import { pageInput, pageOf } from './paging.js';
import type { V1Post } from './shape.js';

/*
 * list_failed_posts: publish failures of the last 30 days, per channel,
 * with the failure code and the reason a person can read. A post whose
 * every channel failed is "failed"; one that went out on some channels and
 * failed on others is "published" with failed channels — both are listed.
 * GET /social-posts?status=failed and ?status=published over the window
 * (the API's from/to match the slot, or the creation time when there is
 * none), newest first.
 */

const DAYS = 30;
const DAY = 86_400_000;

export const listFailedPosts = defineTool({
  name: 'list_failed_posts',
  title: 'List failed posts',
  description: describeTool({
    does: `Lists posts of the last ${DAYS} days that failed to publish on one or more channels, newest first, with each channel's failure_code and the reason in plain words.`,
    when: 'when the user asks what did not go out, or why a post failed. Fix the cause the reason names first (reconnect a channel with get_connect_link, change the post with update_post), then try again with retry_post (preview first). For one post’s full detail, use get_post.',
    example: 'list_failed_posts { "limit": 10 } → { count, posts: [{ post_id, status, caption, scheduled_at, retry, failed_channels: [{ channel_id, platform, username, failure_code, reason }] }], next_cursor }',
    rules: `Read-only. Only the last ${DAYS} days. A post that went out on some channels and failed on others has status "published" and lists only the failed channels. failure_code is a stable code (e.g. auth, rate_limited, missing_board); reason is the sentence to show the user. Looks at up to 100 failed and 100 published posts in the window.`,
    errors: [
      ['scope_missing', 'reconnect and allow "read"'],
      ['unauthorized', 'ask the user to reconnect the creatif.space connector'],
      ['upstream_unavailable', 'wait a minute and call again'],
    ],
  }),
  scope: 'read',
  input: pageInput(100, 25, 'failed posts'),
  annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
  run: async (args, { v1 }) => {
    const now = Date.now();
    const window = { from: new Date(now - DAYS * DAY).toISOString(), to: new Date(now).toISOString(), limit: 100 };
    const [failed, published] = await Promise.all([
      v1.get<{ data: V1Post[] }>('/social-posts', { ...window, status: 'failed' }),
      v1.get<{ data: V1Post[] }>('/social-posts', { ...window, status: 'published' }),
    ]);

    const when = (p: V1Post) => p.scheduled_at ?? p.created_at;
    // status=failed also returns partly failed posts, which status=published returns too: once each.
    const seen = new Set<string>();
    const rows = [...failed.data, ...published.data]
      .filter((p) => (seen.has(p.id) ? false : (seen.add(p.id), true)))
      .map((p) => ({ post: p, bad: (p.targets ?? []).filter((t) => t.status === 'failed') }))
      .filter(({ post, bad }) => post.status === 'failed' || bad.length > 0)
      .sort((a, b) => when(b.post).localeCompare(when(a.post)) || b.post.id.localeCompare(a.post.id))
      .map(({ post, bad }) => ({
        post_id: post.id,
        status: post.status,
        caption: post.caption.length > 80 ? `${post.caption.slice(0, 80)}…` : post.caption,
        scheduled_at: post.scheduled_at,
        retry: `retry_post { "post_id": "${post.id}" }`,
        failed_channels: (bad.length ? bad : post.targets ?? []).map((t) => ({
          channel_id: t.account.id,
          platform: t.account.platform,
          username: t.account.username,
          failure_code: t.fail_code ?? null,
          reason: t.fail_reason ?? post.fail_reason,
        })),
      }));

    const { page, next_cursor, total } = pageOf('list_failed_posts', {}, rows, args.limit, args.cursor);
    return {
      count: page.length,
      total,
      since: window.from,
      posts: page,
      next_cursor,
      next_step: total === 0
        ? `Nothing failed in the last ${DAYS} days.`
        : 'Tell the user which channels failed and why (reason). Fix what the reason names first (get_connect_link to reconnect, update_post to change the post); then, if the user wants, retry_post with the post_id: it previews the cost first and resends only the failed channels.',
    };
  },
});

export const failedPostsTools = [listFailedPosts];

import { createHash } from 'node:crypto';
import { z } from 'zod';
import { ToolError, type ErrorCode } from '../errors.js';
import { idempotencyKey, V1Error } from '../v1.js';
import { defineTool, describeTool, type ToolContext } from './define.js';
import { preview, type SendPreview } from './m2.js';
import { shapePost, type V1Post } from './shape.js';

/*
 * schedule_posts: up to 10 drafts scheduled together, all or none.
 * Without confirm: one preview per post (GET /social-posts/{id}/send-preview
 * with its slot) shown as one list with the total Credits; nothing is sent.
 * confirm: true sends the same posts and slots with each preview's
 * confirm_token to POST /social-posts/send-batch, which checks all of them
 * before sending any and takes back the ones already sent if one is
 * refused. The tokens stay here (10 minutes, per user and exact request);
 * the model never handles them.
 */

const MAX = 10;
const TTL_MS = 10 * 60_000;

const item = z.object({
  post_id: z.string().trim().min(10).max(26).describe('A draft’s id (list_posts status "draft", or draft_post)'),
  scheduled_at: z.iso.datetime({ offset: true }).describe('When it goes out, ISO 8601 in the future (e.g. 2026-11-20T09:00:00+07:00)'),
});

type Item = z.infer<typeof item>;

/** Previews shown, by user + request: each post's confirm_token. */
export class BatchPreviews {
  private readonly seen = new Map<string, { tokens: Record<string, string>; expiresAt: number }>();

  constructor(private readonly now: () => number = Date.now) {}

  remember(key: string, tokens: Record<string, string>): void {
    for (const [k, v] of this.seen) if (v.expiresAt <= this.now()) this.seen.delete(k);
    this.seen.set(key, { tokens, expiresAt: this.now() + TTL_MS });
  }

  take(key: string): Record<string, string> | undefined {
    const hit = this.seen.get(key);
    if (!hit || hit.expiresAt <= this.now()) {
      this.seen.delete(key);
      return undefined;
    }
    return hit.tokens;
  }

  forget(key: string): void {
    this.seen.delete(key);
  }

  clear(): void {
    this.seen.clear();
  }
}

export const batchPreviews = new BatchPreviews();

const keyOf = (user: string, posts: Item[]) =>
  `${user}:${createHash('sha256').update(JSON.stringify(posts.map((p) => [p.post_id, Date.parse(p.scheduled_at)]))).digest('hex')}`;

async function previewAll(ctx: ToolContext, posts: Item[]) {
  const previews: SendPreview[] = [];
  for (const p of posts) previews.push(await preview(ctx, p.post_id, p.scheduled_at));

  const total = previews.reduce((n, p) => n + p.credits.price, 0);
  const last = previews.at(-1)!;
  const balance = last.credits.monthly_balance + last.credits.topup_balance;
  const sendsLeft = last.daily_cap.remaining;

  const blocked: string[] = [];
  if (total > balance) blocked.push(`not enough Credits for all of them: ${total} needed, ${balance} available — the user must top up first, or schedule fewer`);
  if (sendsLeft < posts.length) blocked.push(`connected apps have ${sendsLeft} sends left today, fewer than ${posts.length}`);
  for (const p of previews) {
    const stale = p.targets.filter((t) => !t.usable);
    if (stale.length) blocked.push(`${p.post_id}: reconnect required for ${stale.map((t) => `${t.platform} ${t.username ?? ''}`.trim()).join(', ')}`);
  }

  const tokens = Object.fromEntries(previews.map((p) => [p.post_id, p.confirm_token ?? '']));
  return {
    tokens,
    result: {
      confirmation_required: true,
      posts: previews.map((p, i) => ({
        post_id: p.post_id,
        scheduled_at: posts[i]!.scheduled_at,
        channels: p.targets.map((t) => ({ channel_id: t.channel_id, platform: t.platform, username: t.username, credits: t.credits })),
        credit_cost: p.credits.price,
      })),
      total_credit_cost: total,
      credits_available: balance,
      sends_left_today: sendsLeft,
      ...(blocked.length ? { blocked } : {}),
      next: blocked.length
        ? 'These cannot go out yet — tell the user what is blocking them. Nothing was scheduled.'
        : 'Nothing was scheduled. Show the user every post, its time and channels, and the total Credits. Only if they explicitly agree, call schedule_posts again with the same posts and confirm: true: all are scheduled, or none.',
    },
  };
}

export const schedulePosts = defineTool({
  name: 'schedule_posts',
  title: 'Schedule several drafts at once',
  description: describeTool({
    does: 'Schedules up to 10 DRAFTS to publish publicly on their channels, each at its own time, after the user agrees to one preview of every post, its channels and the total Credit cost. All or none: if one is refused, none stays scheduled and no Credits are kept.',
    when: 'when the user wants a set of drafts (a planned week) to go out later; for one draft use schedule_post, to send now publish_post. Two steps: call with confirm false (default) to get the preview; show it to the user, and only after they explicitly agree call again with the same posts and confirm: true.',
    example: 'schedule_posts { "posts": [{ "post_id": "01JD…", "scheduled_at": "2026-11-20T09:00:00+07:00" }, { "post_id": "01JE…", "scheduled_at": "2026-11-21T09:00:00+07:00" }] } → { confirmation_required: true, posts, total_credit_cost }; then with "confirm": true → { scheduled: [...] }',
    rules: 'The confirm schedules exactly what that preview showed. Credits are charged when they are scheduled (Monthly Credits first, then the Top-up balance; on every plan). Every post counts against the 20 sends a day connected apps may make. confirm: true without a current preview of the same posts and times (shown in the last 10 minutes) returns a preview again and schedules nothing. Drafts waiting for approval cannot be scheduled here.',
    errors: [
      ['preview_stale', 'nothing was scheduled: channels or Credits changed since the preview; show the user the new preview in details.preview and confirm again only if they agree'],
      ['batch_failed', 'nothing was scheduled: details.failed_post_id was refused (details.reason) and the others were taken back; fix that post or leave it out, preview again'],
      ['not_schedulable', 'nothing was scheduled: details.refused names each post that is not a draft or waits for approval; leave them out and preview again'],
      ['insufficient_credits', 'nothing was scheduled: the user tops up, or schedules fewer posts'],
      ['daily_cap_reached', 'nothing was scheduled: fewer posts, or tomorrow (UTC)'],
      ['plan_required', 'pass the message to the user as it is (window or queue limit), do not retry'],
      ['invalid_input', 'a post is listed twice (details.post_ids): list each draft once and call again'],
      ['not_found', 'take draft ids from list_posts status "draft"'],
      ['scope_missing', 'reconnect and allow "publish"'],
    ],
  }),
  scope: 'publish',
  input: {
    posts: z.array(item).min(1).max(MAX).describe(`Up to ${MAX} drafts, each with its own time`),
    confirm: z.boolean().default(false).describe('Leave false first: a preview of every post and the total Credits. Set true only after the user explicitly agreed.'),
  },
  annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true },
  notFound: { what: 'One of these posts', next: 'Take draft ids from list_posts with status "draft", then call again.' },
  run: async ({ posts, confirm }, ctx) => {
    const ids = posts.map((p) => p.post_id);
    const dupes = ids.filter((id, i) => ids.indexOf(id) !== i);
    if (dupes.length) {
      throw new ToolError('invalid_input', 'A post is listed more than once.', 'List each draft once, with one time, then call again.', { post_ids: [...new Set(dupes)] });
    }
    const key = keyOf(ctx.auth.extra.userUlid, posts);

    const tokens = confirm ? batchPreviews.take(key) : undefined;
    if (!tokens) {
      const shown = await previewAll(ctx, posts);
      batchPreviews.remember(key, shown.tokens);
      return confirm
        ? { ...shown.result, note: 'No current preview of these posts and times had been shown (or it expired after 10 minutes), so nothing was scheduled. Show the user this preview first.' }
        : shown.result;
    }

    try {
      const body = { posts: posts.map((p) => ({ post_id: p.post_id, scheduled_at: p.scheduled_at, confirm_token: tokens[p.post_id] })) };
      // One key per confirm: a retried request is replayed, never a second batch.
      const { data } = await ctx.v1.post<{ data: { scheduled: V1Post[] } }>('/social-posts/send-batch', body, {
        idempotencyKey: idempotencyKey('send-batch', ctx.auth.extra.userUlid, JSON.stringify(body)),
      });
      batchPreviews.forget(key);
      return {
        scheduled: data.scheduled.map((p) => shapePost(p)),
        next_step: `All ${data.scheduled.length} are scheduled. Use get_post or list_posts status "scheduled" to follow delivery.`,
      };
    } catch (e) {
      if (!(e instanceof V1Error) || !e.body?.code) throw e;
      batchPreviews.forget(key);
      const body = e.body as Record<string, unknown>;
      const { message, code, ...details } = body;
      if (code === 'preview_stale') {
        const fresh = await previewAll(ctx, posts);
        batchPreviews.remember(key, fresh.tokens);
        throw new ToolError(
          'preview_stale',
          'Nothing was scheduled: channels or Credits changed since the preview (or 10 minutes passed).',
          'Show the user the new preview in details.preview. Only if they agree again, call again with confirm: true.',
          { preview: fresh.result, ...details },
        );
      }
      // The API's codes → this server's, each with its next step.
      const MAP: Record<string, [ErrorCode, string]> = {
        batch_failed: ['batch_failed', 'Tell the user nothing was scheduled and why (details.reason, details.failed_post_id). Fix or leave out that post, then preview again.'],
        not_schedulable: ['not_schedulable', 'Leave out the posts in details.refused (or fix them), then preview again.'],
        not_enough_credits: ['insufficient_credits', 'Tell the user: top up Credits, or schedule fewer posts.'],
        daily_send_cap: ['daily_cap_reached', 'Schedule fewer posts, or the rest tomorrow (UTC).'],
        not_found: ['not_found', 'Take draft ids from list_posts with status "draft", then call again.'],
      };
      const mapped = typeof code === 'string' ? MAP[code] : undefined;
      if (mapped) throw new ToolError(mapped[0], String(message ?? 'Nothing was scheduled.'), mapped[1], details);
      throw e;
    }
  },
});

export const scheduleBatchTools = [schedulePosts];

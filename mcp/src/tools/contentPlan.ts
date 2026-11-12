import { createHash } from 'node:crypto';
import { z } from 'zod';
import { ToolError } from '../errors.js';
import { defineTool, describeTool, type ToolContext } from './define.js';
import type { V1Post } from './shape.js';

/*
 * create_content_plan: a Content Plan approval link (dates + channels, #58)
 * behind a preview → confirm, like publish_post. The preview creates and
 * sends nothing. confirm: true creates the link with notify_approver, so
 * creatif.space emails it to the approver — only then.
 *
 * What the user saw is remembered here (10 minutes, per user and per exact
 * request): confirm without a current preview shows a fresh one and does
 * nothing; if the channels or the posts in the dates changed since, it is
 * preview_stale and nothing is created.
 */

const ulid = (what: string) =>
  z.string().trim().min(10).max(26).describe(`${what} id, as returned by the list tools`);
const day = z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'YYYY-MM-DD');

const PREVIEW_MINUTES = 10;
const MAX_DAYS = 93;
const LISTED = 10;

interface V1Account {
  id: string;
  platform: string;
  username: string | null;
  status: string;
  reconnect_required?: boolean;
}

/** Remembered previews: user + request → state fingerprint the user saw. */
export class PlanPreviews {
  private readonly seen = new Map<string, { state: string; expiresAt: number }>();

  constructor(private readonly now: () => number = Date.now) {}

  remember(key: string, state: string): void {
    this.sweep();
    this.seen.set(key, { state, expiresAt: this.now() + PREVIEW_MINUTES * 60_000 });
  }

  take(key: string): string | undefined {
    const hit = this.seen.get(key);
    if (!hit || hit.expiresAt <= this.now()) {
      this.seen.delete(key);
      return undefined;
    }
    return hit.state;
  }

  forget(key: string): void {
    this.seen.delete(key);
  }

  clear(): void {
    this.seen.clear();
  }

  private sweep(): void {
    const now = this.now();
    for (const [k, v] of this.seen) if (v.expiresAt <= now) this.seen.delete(k);
  }
}

export const planPreviews = new PlanPreviews();

const sha = (v: unknown) => createHash('sha256').update(JSON.stringify(v)).digest('hex');

/** Minutes east of UTC for `tz` at instant `ms`. Throws RangeError on an unknown zone. */
function offsetMinutes(tz: string, ms: number): number {
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat('en-US', { timeZone: tz, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' })
      .formatToParts(ms)
      .map((p) => [p.type, p.value]),
  );
  const local = Date.UTC(+parts.year!, +parts.month! - 1, +parts.day!, +parts.hour!, +parts.minute!, +parts.second!);
  return Math.round((local - ms) / 60_000);
}

/** The UTC instant of local midnight starting `date` in `tz`. */
export function startOfDay(date: string, tz: string): number {
  const [y, m, d] = date.split('-').map(Number) as [number, number, number];
  const guess = Date.UTC(y, m - 1, d);
  const first = guess - offsetMinutes(tz, guess) * 60_000;
  return guess - offsetMinutes(tz, first) * 60_000;
}

const addDays = (date: string, n: number) => new Date(Date.UTC(+date.slice(0, 4), +date.slice(5, 7) - 1, +date.slice(8, 10) + n)).toISOString().slice(0, 10);

const input = {
  date_from: day.describe('First day the plan shows (YYYY-MM-DD, in timezone)'),
  date_to: day.describe('Last day the plan shows, included (YYYY-MM-DD); at most 93 days after date_from'),
  timezone: z.string().trim().max(64).optional().describe('IANA timezone the dates are in, e.g. Asia/Jakarta (default: the account timezone; the preview then counts posts in UTC)'),
  channel_ids: z.array(ulid('Channel')).min(1).max(20).optional().describe('Only these channels (ids from list_channels); omit for every channel'),
  approver_email: z.string().trim().email().max(254).describe('Who approves: creatif.space emails them the link on confirm, and they sign in with this address to decide'),
  title: z.string().trim().max(120).optional().describe('Shown to the client above the posts and in the email'),
  client_name: z.string().trim().max(120).optional().describe('Private note on who it is for; the client never sees it'),
  expires_in_hours: z.union([z.literal(48), z.literal(168), z.literal(720)]).optional().describe('48, 168 (7 days) or 720 (30 days); omit for no expiry'),
  confirm: z.boolean().default(false).describe('Leave false first: you get a preview and nothing is created or emailed. Set true only after the user explicitly agreed to that preview.'),
};

type Args = z.infer<z.ZodObject<typeof input>>;

/** What the preview shows, and the fingerprint of what could change under it. */
async function buildPreview(args: Args, { v1 }: ToolContext) {
  const tz = args.timezone ?? 'UTC';
  let from: number;
  let to: number;
  try {
    from = startOfDay(args.date_from, tz);
    to = startOfDay(addDays(args.date_to, 1), tz) - 1000;
  } catch {
    throw new ToolError('invalid_input', `"${args.timezone}" is not a timezone.`, 'Use an IANA name such as Asia/Jakarta or Europe/London, or leave timezone out.', { field: 'timezone' });
  }
  if (args.date_to < args.date_from) {
    throw new ToolError('invalid_input', 'The plan ends before it starts.', 'Make date_to the same day as date_from or later, then call again.', { field: 'date_to' });
  }
  if ((Date.parse(args.date_to) - Date.parse(args.date_from)) / 86_400_000 > MAX_DAYS) {
    throw new ToolError('invalid_input', `A Content Plan covers at most ${MAX_DAYS} days.`, `Pick dates at most ${MAX_DAYS} days apart (or make two plans), then call again.`, { field: 'date_to', max_days: MAX_DAYS });
  }

  const { data: accounts } = await v1.get<{ data: V1Account[] }>('/social-accounts');
  const wanted = args.channel_ids ? [...new Set(args.channel_ids)] : null;
  const unknown = wanted?.filter((id) => !accounts.some((a) => a.id === id)) ?? [];
  if (unknown.length) {
    throw new ToolError('not_found', 'One or more channels are not connected to this workspace.', 'Take channel ids from list_channels, or leave channel_ids out for every channel, then call again.', { channel_ids: unknown });
  }
  const channels = accounts
    .filter((a) => !wanted || wanted.includes(a.id))
    .map((a) => ({ id: a.id, platform: a.platform, username: a.username, reconnect_required: a.reconnect_required === true || a.status !== 'connected' }));

  const { data: posts } = await v1.get<{ data: V1Post[] }>('/social-posts', {
    from: new Date(from).toISOString(),
    to: new Date(to).toISOString(),
    limit: 100,
  });
  const inPlan = posts
    .filter((p) => p.status !== 'cancelled')
    .filter((p) => !wanted || (p.targets ?? []).some((t) => wanted.includes(t.account.id)))
    .sort((a, b) => String(a.scheduled_at).localeCompare(String(b.scheduled_at)) || a.id.localeCompare(b.id));

  const warnings: string[] = [];
  const stale = channels.filter((c) => c.reconnect_required);
  if (stale.length) warnings.push(`Needs reconnecting before its posts can go out: ${stale.map((c) => `${c.platform} ${c.username ?? ''}`.trim()).join(', ')}.`);
  if (inPlan.length === 0) warnings.push('No posts in these dates yet. The plan still shows posts added to them later.');
  if (posts.length >= 100) warnings.push('100 or more posts in these dates: the count below stops at 100.');

  const preview = {
    date_from: args.date_from,
    date_to: args.date_to,
    timezone: args.timezone ?? 'the account timezone',
    channels: wanted ? channels : 'all connected channels',
    approver_email: args.approver_email.toLowerCase(),
    ...(args.title ? { title: args.title } : {}),
    ...(args.client_name ? { client_name: args.client_name } : {}),
    expires: args.expires_in_hours ? `${args.expires_in_hours} hours after it is created` : 'never',
    posts_in_dates: inPlan.length,
    posts: inPlan.slice(0, LISTED).map((p) => ({
      id: p.id,
      status: p.status,
      scheduled_at: p.scheduled_at,
      caption: p.caption.length > 80 ? `${p.caption.slice(0, 80)}…` : p.caption,
      channels: (p.targets ?? []).map((t) => t.account.platform),
    })),
    on_confirm: `creatif.space creates the Content Plan link and emails it to ${args.approver_email.toLowerCase()}.`,
    warnings,
  };
  const state = sha({ channels, posts: inPlan.map((p) => [p.id, p.status, p.scheduled_at]) });
  return { preview, state };
}

const requestKey = (user: string, a: Args) =>
  `${user}:${sha([a.date_from, a.date_to, a.timezone ?? null, a.channel_ids ? [...new Set(a.channel_ids)].sort() : null, a.approver_email.toLowerCase(), a.title ?? null, a.client_name ?? null, a.expires_in_hours ?? null])}`;

const PREVIEW_NEXT = 'Nothing was created or emailed. Show the user this preview (dates, channels, approver, posts) and ask whether to send it; call again with the same arguments and confirm: true only if they agree.';

export const createContentPlan = defineTool({
  name: 'create_content_plan',
  title: 'Create a Content Plan and email the approver',
  description: describeTool({
    does: 'Creates a Content Plan approval link (a date range and channels) and, only on confirm: true, has creatif.space email it to the approver.',
    when: 'when the user wants a client to approve a period (“send my December plan to Rani”) and wants creatif.space to email it. First call without confirm for a preview; call again with confirm: true only after the user agrees. To get a link without an email, or for a list of posts, use create_approval_link.',
    example: 'create_content_plan { "date_from": "2026-12-01", "date_to": "2026-12-31", "approver_email": "client@example.com" } → { confirmation_required: true, preview: { channels, posts_in_dates, posts, on_confirm } }; then the same with "confirm": true → { link: { id, url }, approver_notified: true }',
    rules: 'The preview creates and sends nothing. confirm: true without a current preview (shown in the last 10 minutes, same arguments) returns a preview again and does nothing. The plan shows every post in those dates on those channels, including posts added later. Dates are YYYY-MM-DD, both included, at most 93 days. Free: one client at a time; a Free client can decide only posts in the next 7 days.',
    errors: [
      ['preview_stale', 'nothing was created: channels or posts in the dates changed since the preview; show the user the new preview in details.preview and confirm again only if they agree'],
      ['invalid_input', 'fix the dates, timezone or email named in details and call again'],
      ['not_found', 'take channel ids from list_channels'],
      ['plan_required', 'pass the message to the user as it is (it has the upgrade link); do not retry'],
      ['scope_missing', 'reconnect and allow "approve"'],
      ['upstream_unavailable', 'check list_approval_links before calling again: the plan may have been created'],
    ],
  }),
  scope: 'approve',
  input,
  annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true },
  notFound: { what: 'A channel', next: 'Take channel ids from list_channels, or leave channel_ids out, then call again.' },
  run: async (args, ctx) => {
    const key = requestKey(ctx.auth.extra.userUlid, args);
    const { preview, state } = await buildPreview(args, ctx);

    if (!args.confirm) {
      planPreviews.remember(key, state);
      return { confirmation_required: true, preview, next_step: PREVIEW_NEXT };
    }

    const seen = planPreviews.take(key);
    if (seen === undefined) {
      planPreviews.remember(key, state);
      return {
        confirmation_required: true,
        preview,
        note: 'No current preview of this plan had been shown (or it expired after 10 minutes, or the arguments changed), so nothing was created or emailed. Show the user this preview first.',
        next_step: PREVIEW_NEXT,
      };
    }
    if (seen !== state) {
      planPreviews.remember(key, state);
      throw new ToolError(
        'preview_stale',
        'Nothing was created or emailed: the channels or the posts in these dates changed since the preview.',
        'Show the user the new preview in details.preview. Only if they agree again, call again with confirm: true.',
        { preview },
      );
    }

    planPreviews.forget(key);
    const { data } = await ctx.v1.post<{ data: Record<string, unknown> }>('/social-approval-links', {
      date_from: args.date_from,
      date_to: args.date_to,
      ...(args.timezone ? { timezone: args.timezone } : {}),
      ...(args.channel_ids ? { channel_ids: [...new Set(args.channel_ids)] } : {}),
      approver_email: args.approver_email,
      ...(args.title ? { title: args.title } : {}),
      ...(args.client_name ? { client_name: args.client_name } : {}),
      ...(args.expires_in_hours ? { expires_in_hours: args.expires_in_hours } : {}),
      notify_approver: true,
    });
    return {
      link: {
        id: data.id,
        url: data.url,
        status: data.status,
        kind: data.kind,
        scope: data.scope,
        approver_email: data.approver_email,
        expires_at: data.expires_at,
      },
      approver_notified: data.approver_notified === true,
      next_step:
        data.approver_notified === true
          ? `Created, and creatif.space emailed the link to ${String(data.approver_email)}. Use check_approval_status with link_id to follow their decisions.`
          : 'Created, but creatif.space did not confirm the email: give the user the url to share by hand. Use check_approval_status with link_id to follow decisions.',
    };
  },
});

export const contentPlanTools = [createContentPlan];

import { z } from 'zod';
import { ToolError } from '../errors.js';
import { idempotencyKey, V1Error } from '../v1.js';
import { defineTool, describeTool, type ToolContext } from './define.js';
import { shapePost, type V1Post } from './shape.js';

/** A repeat of the same create_approval_link within this window gets the first link back. */
const LINK_DEDUPE_MS = 10 * 60_000;

const ulid = (what: string) =>
  z.string().trim().min(10).max(26).describe(`${what} id, as returned by the list tools`);

const confirm = z
  .boolean()
  .default(false)
  .describe('Leave false first: you get a preview (channels and Credit cost). Set true only after the user explicitly agreed to that preview.');

export interface SendPreview {
  post_id: string;
  sendable: boolean;
  status: string;
  scheduled_at: string | null;
  targets: { channel_id: string; platform: string; username: string | null; usable: boolean; credits: number }[];
  credits: { price: number; from_topup: number; monthly_balance: number; topup_balance: number; can_afford: boolean };
  daily_cap: { cap: number; used: number; remaining: number };
  /** Signed by the API: user + post + destinations + Credit price, 10 minutes. */
  confirm_token?: string;
  confirm_expires_at?: string;
}

/**
 * The confirm_token of the last preview shown for a post, kept here so
 * confirm: true sends exactly what was previewed — the model never handles
 * the token. Keyed by user and post; gone after a send or at expiry. No
 * remembered preview means the user has not seen one: preview again, never
 * send.
 */
export class PreviewTokens {
  private readonly tokens = new Map<string, { token: string; expiresAt: number }>();

  constructor(private readonly now: () => number = Date.now) {}

  remember(user: string, postId: string, p: Pick<SendPreview, 'confirm_token' | 'confirm_expires_at'>): void {
    if (!p.confirm_token) return;
    const expiresAt = p.confirm_expires_at ? Date.parse(p.confirm_expires_at) : this.now() + 10 * 60_000;
    this.tokens.set(`${user}:${postId}`, { token: p.confirm_token, expiresAt });
    this.sweep();
  }

  take(user: string, postId: string): string | undefined {
    const key = `${user}:${postId}`;
    const hit = this.tokens.get(key);
    if (!hit || hit.expiresAt <= this.now()) {
      this.tokens.delete(key);
      return undefined;
    }
    return hit.token;
  }

  forget(user: string, postId: string): void {
    this.tokens.delete(`${user}:${postId}`);
  }

  clear(): void {
    this.tokens.clear();
  }

  private sweep(): void {
    const now = this.now();
    for (const [k, v] of this.tokens) if (v.expiresAt <= now) this.tokens.delete(k);
  }
}

export const previewTokens = new PreviewTokens();

export async function preview(ctx: ToolContext, postId: string, scheduledAt?: string): Promise<SendPreview> {
  // With the slot, the plan's limits (scheduling window, queue) answer
  // plan_required here, before the user is asked to confirm anything.
  const { data } = await ctx.v1.get<{ data: SendPreview }>(
    `/social-posts/${encodeURIComponent(postId)}/send-preview`,
    scheduledAt ? { scheduled_at: scheduledAt } : undefined,
  );
  if (!data.sendable) {
    throw new ToolError(
      'not_draft',
      `Only a draft can be scheduled or published from here; this post is "${data.status}".`,
      'Use list_posts with status "draft" to find a draft to send, or draft_post to make a new one.',
      { post_id: postId, status: data.status },
    );
  }
  previewTokens.remember(ctx.auth.extra.userUlid, postId, data);
  return data;
}

/**
 * The confirmed send: with the remembered preview's token, or not at all.
 * No preview on record (never shown, expired, already used) → a fresh
 * preview to show the user. A preview the API calls stale → the new one,
 * as a preview_stale error: nothing was sent.
 */
async function confirmedSend(
  ctx: ToolContext,
  postId: string,
  action: 'publish' | 'schedule',
  body: Record<string, unknown>,
  scheduledAt?: string,
): Promise<{ data: V1Post } | (ReturnType<typeof previewResult> & { note: string })> {
  const user = ctx.auth.extra.userUlid;
  const token = previewTokens.take(user, postId);
  if (!token) {
    const fresh = previewResult(await preview(ctx, postId, scheduledAt), action, scheduledAt);
    return { ...fresh, note: 'No current preview of this post had been shown (or it expired after 10 minutes), so nothing was sent. Show the user this preview first.' };
  }
  try {
    const res = await ctx.v1.post<{ data: V1Post }>(`/social-posts/${encodeURIComponent(postId)}/send`, { ...body, confirm_token: token }, {
      idempotencyKey: idempotencyKey('send', user, postId, token, JSON.stringify(body)),
    });
    previewTokens.forget(user, postId);
    return res;
  } catch (e) {
    if (e instanceof V1Error && e.status === 409 && e.body?.code === 'preview_stale') {
      previewTokens.forget(user, postId);
      const fresh = previewResult(await preview(ctx, postId, scheduledAt), action, scheduledAt);
      throw new ToolError(
        'preview_stale',
        'Nothing was sent: the post’s channels or Credit cost changed since the preview (or 10 minutes passed).',
        'Show the user the new preview in details.preview. Only if they agree again, call again with confirm: true.',
        { preview: fresh },
      );
    }
    throw e;
  }
}

const isSent = (r: object): r is { data: V1Post } => 'data' in r;

function previewResult(p: SendPreview, action: 'publish' | 'schedule', scheduledAt?: string) {
  const blocked: string[] = [];
  if (!p.credits.can_afford) blocked.push('not enough Credits — the user must top up first');
  if (p.daily_cap.remaining <= 0) blocked.push('the daily limit for sends from connected apps is reached');
  const stale = p.targets.filter((t) => !t.usable);
  if (stale.length) blocked.push(`reconnect required for: ${stale.map((t) => `${t.platform} ${t.username ?? ''}`.trim()).join(', ')}`);

  return {
    confirmation_required: true,
    action,
    post_id: p.post_id,
    ...(scheduledAt ? { scheduled_at: scheduledAt } : {}),
    channels: p.targets.map((t) => ({ channel_id: t.channel_id, platform: t.platform, username: t.username, credits: t.credits })),
    credit_cost: p.credits.price,
    credits_from_topup: p.credits.from_topup,
    monthly_balance: p.credits.monthly_balance,
    topup_balance: p.credits.topup_balance,
    sends_left_today: p.daily_cap.remaining,
    ...(blocked.length ? { blocked } : {}),
    next: blocked.length
      ? 'This cannot go out yet — tell the user what is blocking it.'
      : `Show the user these channels and the Credit cost. Only if they explicitly agree, call ${action === 'publish' ? 'publish_post' : 'schedule_post'} again with the same arguments and confirm: true.`,
  };
}

export const createApprovalLink = defineTool({
  name: 'create_approval_link',
  title: 'Create a client approval link',
  description: describeTool({
    does: "Creates a creatif.space approval link — for a list of posts, or a Content Plan of dates and channels — and returns its URL for the user to share with their client; the posts are held until the client approves.",
    when: "when the user wants a client to approve posts before they go out — a few posts by id, or a period (\"approve my December\") as a Content Plan. To follow decisions on an existing link, use check_approval_status with link_id instead of making a new link. The link is not sent anywhere by this tool; hand the URL to the user.",
    example: "create_approval_link { \"post_ids\": [\"01JD…\"], \"approver_email\": \"client@example.com\", \"title\": \"November posts\", \"expires_in_hours\": 168 } → { link: { id, url, posts }, skipped? }; Content Plan: { \"date_from\": \"2026-12-01\", \"date_to\": \"2026-12-31\", \"approver_email\": \"client@example.com\" }",
    rules: "Two kinds: a fixed list (post_ids: drafts, or posts already held for approval) or a Content Plan (date_from + date_to, optionally channel_ids): the client sees every post in those dates on those channels, including posts added later. Give post_ids OR date_from/date_to, never both. Content Plan dates are YYYY-MM-DD, both included, at most 93 days, in timezone (default the account's). approver_email is required: only that person, signed in with that address (Google or a one-time email link on the approval page), can approve or ask for changes; ask the user for it if you do not have it. Every plan: on Free, links are for one client at a time (the approver_email tells clients apart; links to the same address are fine), posts scheduled at most 7 days ahead, and at most 10 queued posts (on a Content Plan, a Free client can decide only posts in the next 7 days; the rest show). Fixed-list posts that cannot be approved are left off and listed in skipped with their status and reason.",
    errors: [
      ["invalid_input", "give either post_ids or both date_from and date_to"],
      ["not_approvable", "none of the posts can go on a link (details.refused gives each post’s status and reason): list_posts status \"draft\", or draft_post a new one, then call again"],
      ["plan_required", "details.feature approval_clients, schedule_window or queue_limit — pass the message to the user as it is (it has the trial/upgrade link), do not retry"],
      ["not_found", "check ids with list_posts / list_channels"],
      ["scope_missing", "reconnect and allow \"approve\""],
      ["upstream_unavailable", "check list_posts / check_approval_status before calling again"],
    ],
  }),
  scope: 'approve',
  input: {
    post_ids: z.array(ulid('Post')).min(1).max(50).optional().describe('Fixed list: the posts to put on the link (omit for a Content Plan)'),
    date_from: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'YYYY-MM-DD').optional().describe('Content Plan: first day shown (YYYY-MM-DD, in timezone)'),
    date_to: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'YYYY-MM-DD').optional().describe('Content Plan: last day shown, included (YYYY-MM-DD)'),
    timezone: z.string().trim().max(64).optional().describe('Content Plan: IANA timezone the dates are in, e.g. Asia/Jakarta (default: the account timezone)'),
    channel_ids: z.array(ulid('Channel')).max(20).optional().describe('Content Plan: only these channels (from list_channels); omit for all'),
    approver_email: z.string().trim().email().max(254).describe('Email of the person who approves; they sign in with it to decide'),
    title: z.string().trim().max(120).optional().describe('Shown to the client above the posts'),
    client_name: z.string().trim().max(120).optional().describe('Private note on who it is for; the client never sees it'),
    expires_in_hours: z.union([z.literal(48), z.literal(168), z.literal(720)]).optional().describe('48, 168 (7 days) or 720 (30 days); omit for no expiry'),
  },
  annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
  notFound: { what: 'A post or channel', next: 'Check the post ids with list_posts (status draft) and channel ids with list_channels, then call again.' },
  run: async (args, ctx) => {
    const plan = args.date_from !== undefined || args.date_to !== undefined;
    if (plan === (args.post_ids !== undefined)) {
      throw new ToolError('invalid_input', 'Give either post_ids (a fixed list) or date_from and date_to (a Content Plan) — one of the two.', 'Pass post_ids, or date_from + date_to.');
    }
    if (plan && (args.date_from === undefined || args.date_to === undefined)) {
      throw new ToolError('invalid_input', 'A Content Plan needs both date_from and date_to.', 'Pass both dates as YYYY-MM-DD.');
    }
    const ids = plan ? [] : [...new Set(args.post_ids)];
    let data: Record<string, unknown>;
    const body = {
      ...(plan
        ? {
            date_from: args.date_from,
            date_to: args.date_to,
            ...(args.timezone ? { timezone: args.timezone } : {}),
            ...(args.channel_ids ? { channel_ids: args.channel_ids } : {}),
          }
        : { post_ids: ids }),
      approver_email: args.approver_email,
      ...(args.title ? { title: args.title } : {}),
      ...(args.client_name ? { client_name: args.client_name } : {}),
      ...(args.expires_in_hours ? { expires_in_hours: args.expires_in_hours } : {}),
    };
    try {
      // The same link asked twice within ten minutes (a double call) is
      // made once: the API replays the first answer.
      const window = String(Math.floor(Date.now() / LINK_DEDUPE_MS));
      ({ data } = await ctx.v1.post<{ data: Record<string, unknown> }>('/social-approval-links', body, {
        idempotencyKey: idempotencyKey('approval-link', ctx.auth.extra.userUlid, JSON.stringify(body), window),
      }));
    } catch (e) {
      // The API refuses a set with no approvable post in one bare sentence;
      // say which post is what, and what to do instead.
      if (!plan && e instanceof V1Error && e.status === 422 && !e.body?.errors && !e.body?.code && /can still be approved/i.test(String(e.body?.message ?? ''))) {
        const refused = await whyNotApprovable(ctx, ids);
        throw new ToolError(
          'not_approvable',
          'None of these posts can go on an approval link: only drafts and posts still held for approval can.',
          'Use list_posts with status "draft" to find drafts to share, or draft_post to make a new draft (you can reuse a published post’s caption and media), then call create_approval_link with those ids.',
          { refused },
        );
      }
      throw e;
    }
    // Posts the API left off a fixed list (published, queued, cancelled…)
    // are dropped silently by the API; name them. A Content Plan has no list.
    const onLink = typeof data.posts === 'number' ? data.posts : ids.length;
    const skipped = !plan && onLink < ids.length ? await skippedFromLink(ctx, String(data.id), ids) : [];
    return {
      link: {
        id: data.id,
        url: data.url,
        status: data.status,
        posts: data.posts,
        kind: data.kind,
        scope: data.scope,
        approver_email: data.approver_email,
        expires_at: data.expires_at,
      },
      ...(skipped.length ? { skipped } : {}),
      note:
        'Share the url with the client. They sign in with the approver email to decide. Use check_approval_status with link_id to follow their decisions.' +
        (skipped.length ? ' Some posts were not added (see skipped, with each one’s status and reason): tell the user.' : ''),
    };
  },
});

interface Refusal {
  post_id: string;
  status: string;
  reason: string;
}

const REASONS: Record<string, string> = {
  published: 'already published; a published post cannot be approved again',
  scheduled: 'already handed to the channels to publish (approved or not held for approval), so it can no longer be put up for approval',
  failed: 'publishing failed; it is no longer a draft',
  cancelled: 'cancelled',
};

/** Each post's status and why it cannot go on a link. One read per post (at most 50). */
async function whyNotApprovable(ctx: ToolContext, ids: string[]): Promise<Refusal[]> {
  return Promise.all(
    ids.map(async (id): Promise<Refusal> => {
      try {
        const { data } = await ctx.v1.get<{ data: V1Post }>(`/social-posts/${encodeURIComponent(id)}`);
        return { post_id: id, status: data.status, reason: REASONS[data.status] ?? `its status is "${data.status}"` };
      } catch (e) {
        if (e instanceof V1Error && e.status === 404) return { post_id: id, status: 'not_found', reason: 'no such post in this workspace' };
        return { post_id: id, status: 'unknown', reason: 'could not be checked right now' };
      }
    }),
  );
}

/** The requested posts that are not on the new link, with why. */
async function skippedFromLink(ctx: ToolContext, linkId: string, ids: string[]): Promise<Refusal[]> {
  try {
    const { data } = await ctx.v1.get<{ data: { posts: { id: string }[] } }>(`/social-approval-links/${encodeURIComponent(linkId)}`);
    const on = new Set(data.posts.map((p) => p.id));
    return whyNotApprovable(ctx, ids.filter((id) => !on.has(id)));
  } catch {
    return [];
  }
}

export const checkApprovalStatus = defineTool({
  name: 'check_approval_status',
  title: 'Check approval status',
  description: describeTool({
    does: "Checks where client approval stands — for one post (post_id) or a whole approval link (link_id): approved / waiting / changes requested, who decided and when, and the open feedback threads.",
    when: "after create_approval_link, to follow the client’s decisions. Give exactly one of post_id or link_id. For a post’s full detail (media, delivery), use get_post.",
    example: "check_approval_status { \"link_id\": \"01JL…\" } → { link, posts: [{ id, approval, approval_by, approval_at, open_feedback }] }",
    errors: [
      ["invalid_input", "pass exactly one of post_id or link_id"],
      ["not_found", "post ids come from list_posts, link ids from create_approval_link"],
      ["unauthorized", "ask the user to reconnect the creatif.space connector"],
      ["upstream_unavailable", "wait a minute and call again"],
    ],
  }),
  scope: 'read',
  input: {
    post_id: ulid('Post').optional(),
    link_id: ulid('Approval link').optional(),
  },
  annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
  notFound: { what: 'That post or approval link', next: 'Check the id: posts come from list_posts, link ids from create_approval_link. Call again with one valid id.' },
  run: async (args, { v1 }) => {
    if (!!args.post_id === !!args.link_id) {
      throw new ToolError('invalid_input', 'Give exactly one of post_id or link_id.', 'Call again with post_id for one post, or link_id for a whole link — not both, not neither.', {
        fields: ['post_id', 'link_id'],
        given: { post_id: !!args.post_id, link_id: !!args.link_id },
      });
    }
    if (args.post_id) {
      const { data } = await v1.get<{ data: V1Post }>(`/social-posts/${encodeURIComponent(args.post_id)}`);
      return {
        post_id: data.id,
        status: data.status,
        approval: data.approval,
        approval_by: data.approval_by ?? null,
        approval_at: data.approval_at ?? null,
        feedback: data.comments ?? [],
      };
    }
    const { data } = await v1.get<{ data: { link: Record<string, unknown>; posts: V1Post[] } }>(
      `/social-approval-links/${encodeURIComponent(args.link_id!)}`,
    );
    return {
      link: data.link,
      posts: data.posts.map((p) => ({
        id: p.id,
        status: p.status,
        approval: p.approval,
        approval_by: p.approval_by ?? null,
        approval_at: p.approval_at ?? null,
        caption: p.caption,
        open_feedback: p.comments ?? [],
      })),
    };
  },
});

export const sendApprovalReminder = defineTool({
  name: 'send_approval_reminder',
  title: 'Send an approval reminder',
  description: describeTool({
    does: "Emails the client (the link's approver) a reminder to review the posts still waiting on an approval link — the same link, sent once more.",
    when: "when the user wants to nudge a client who has not answered yet. Use check_approval_status with link_id first to see what is still waiting; do not make a new link to remind someone.",
    example: "send_approval_reminder { \"link_id\": \"01JL…\" } → { reminder: { link_id, reminded_at, next_reminder_at, waiting } }",
    rules: "At most one reminder per link every 12 hours. Only a live link (not revoked or expired) with an approver email, reminders not turned off by the client, and at least one post still waiting can be reminded.",
    errors: [
      ["rate_limited", "details.reason reminder_too_soon: a reminder already went out; tell the user the next one can go at details.next_reminder_at, do not retry before then"],
      ["invalid_input", "details.reason nothing_waiting, no_approver, reminders_off or link_not_live: tell the user (nothing left to answer, no approver email on an old link, the client turned reminders off, or the link is revoked/expired), do not retry"],
      ["not_found", "link ids come from create_approval_link"],
      ["scope_missing", "reconnect and allow \"approve\""],
      ["upstream_unavailable", "check_approval_status shows last_reminded_at before calling again"],
    ],
  }),
  scope: 'approve',
  input: {
    link_id: ulid('Approval link'),
  },
  annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
  notFound: { what: 'That approval link', next: 'Check the link id: it comes from create_approval_link (or check_approval_status). Call again with a valid id.' },
  run: async (args, { v1 }) => {
    try {
      const { data } = await v1.post<{ data: { link_id: string; reminded_at: string; next_reminder_at: string; waiting: number } }>(
        `/social-approval-links/${encodeURIComponent(args.link_id)}/remind`,
        {},
      );
      return {
        reminder: data,
        note: `The client was emailed a reminder about ${data.waiting === 1 ? 'one post' : `${data.waiting} posts`} on this link. The next reminder can go from next_reminder_at.`,
      };
    } catch (e) {
      const code = e instanceof V1Error ? e.body?.code : undefined;
      const message = e instanceof V1Error && typeof e.body?.message === 'string' ? e.body.message : undefined;
      if (e instanceof V1Error && e.status === 429 && code === 'reminder_too_soon') {
        throw new ToolError(
          'rate_limited',
          message ?? 'A reminder went out less than 12 hours ago.',
          'Tell the user a reminder was already sent; the next one can go at details.next_reminder_at. Do not retry before then.',
          { reason: 'reminder_too_soon', next_reminder_at: e.body?.next_reminder_at ?? null, retryable: false },
        );
      }
      if (e instanceof V1Error && e.status === 422 && (code === 'nothing_waiting' || code === 'no_approver' || code === 'link_not_live' || code === 'reminders_off')) {
        throw new ToolError('invalid_input', message ?? 'This link cannot be reminded.', 'Tell the user this message as it is; do not retry.', {
          reason: code,
          link_id: args.link_id,
        });
      }
      throw e;
    }
  },
});

/** The post's current media as the API takes it back: url, plus a video cover when there is one. */
const keptMedia = (post: V1Post) =>
  (post.media ?? []).map((m) => ({ url: m.url, ...(m.thumbnail_url ? { thumbnail_url: m.thumbnail_url } : {}) }));

export const updatePost = defineTool({
  name: 'update_post',
  title: 'Update a post',
  description: describeTool({
    does: "Changes a draft or a post still waiting for approval: its caption, scheduled time, labels or media.",
    when: "to edit an existing draft instead of drafting a new one. Posts already queued to publish, published or otherwise done cannot be changed from here — the user edits those in creatif.space.",
    example: "update_post { \"post_id\": \"01JD…\", \"caption\": \"New caption\", \"add_file_ids\": [\"01JE…\"] }",
    rules: "Media: file_ids REPLACES the post’s media with these library files ([] removes all); add_file_ids keeps the current media and adds these after it (10 items at most). Ids come from list_files, or from confirm_media_upload for a new upload.",
    errors: [
      ["not_editable", "details.status says why; tell the user to change it in creatif.space, or draft_post a new version"],
      ["file_not_ready", "call confirm_media_upload first"],
      ["invalid_input", "fix what details names (both file_ids and add_file_ids, nothing to change, more than 10 media)"],
      ["not_found", "find the id with list_posts"],
      ["scope_missing", "reconnect and allow \"draft\""],
    ],
  }),
  scope: 'draft',
  input: {
    post_id: ulid('Post'),
    caption: z.string().min(1).max(63206).optional(),
    scheduled_at: z.iso.datetime({ offset: true }).optional().describe('New time, ISO 8601, in the future'),
    labels: z.array(z.string().max(40)).max(10).optional().describe('Replaces the post’s labels'),
    file_ids: z.array(ulid('File')).max(10).optional().describe('Replaces the post’s media with these library files ([] removes all media)'),
    add_file_ids: z.array(ulid('File')).min(1).max(10).optional().describe('Adds these library files after the post’s current media'),
  },
  annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
  notFound: { what: 'That post', next: 'Check the post id: find it with list_posts (status draft for drafts) and call again.' },
  run: async (args, { v1 }) => {
    if (args.file_ids !== undefined && args.add_file_ids !== undefined) {
      throw new ToolError('invalid_input', 'Pass file_ids or add_file_ids, not both.', 'Call again with only one: file_ids replaces the media; add_file_ids appends to it.', {
        fields: ['file_ids', 'add_file_ids'],
      });
    }
    const changesMedia = args.file_ids !== undefined || args.add_file_ids !== undefined;
    if (args.caption === undefined && args.scheduled_at === undefined && args.labels === undefined && !changesMedia) {
      throw new ToolError('invalid_input', 'Nothing to change: no field to update was given.', 'Call again with at least one of caption, scheduled_at, labels, file_ids or add_file_ids.', {
        fields: ['caption', 'scheduled_at', 'labels', 'file_ids', 'add_file_ids'],
      });
    }
    // The API decides what may change (drafts and posts waiting for approval
    // only, through a token); this only says so before trying.
    const { data: current } = await v1.get<{ data: V1Post }>(`/social-posts/${encodeURIComponent(args.post_id)}`);
    const editable = current.status === 'draft' || (current.status === 'scheduled' && current.approval !== null && current.approval !== 'approved');
    if (!editable) {
      throw new ToolError(
        'not_editable',
        `Only drafts and posts waiting for approval can be changed from here; this post is "${current.status}"${current.approval === 'approved' ? ' (approved)' : ''}.`,
        'Tell the user to change it in creatif.space, or draft_post a new version and work on that.',
        { post_id: args.post_id, status: current.status, approval: current.approval },
      );
    }
    if (args.add_file_ids !== undefined && keptMedia(current).length + args.add_file_ids.length > 10) {
      throw new ToolError(
        'invalid_input',
        `A post takes up to 10 media items; this one has ${keptMedia(current).length}.`,
        'Add fewer files, or pass file_ids to replace the media instead.',
        { max: 10, current: keptMedia(current).length, adding: args.add_file_ids.length },
      );
    }
    const { data } = await v1.patch<{ data: V1Post }>(`/social-posts/${encodeURIComponent(args.post_id)}`, {
      ...(args.caption !== undefined ? { caption: args.caption } : {}),
      ...(args.scheduled_at !== undefined ? { scheduled_at: args.scheduled_at } : {}),
      ...(args.labels !== undefined ? { labels: args.labels } : {}),
      ...(args.file_ids !== undefined ? { media: [], file_ids: args.file_ids } : {}),
      ...(args.add_file_ids !== undefined ? { media: keptMedia(current), file_ids: args.add_file_ids } : {}),
    });
    return { post: shapePost(data) };
  },
});

export const schedulePost = defineTool({
  name: 'schedule_post',
  title: 'Schedule a draft',
  description: describeTool({
    does: "Schedules a DRAFT to publish at a future time on its channels, after the user agrees to a preview of channels and Credit cost.",
    when: "when the user wants a draft to go out later; use publish_post to send now, and create_approval_link if a client must approve first. Two steps: call with confirm false (default) to get the preview; show it to the user, and only after they explicitly agree call again with confirm: true.",
    example: "schedule_post { \"post_id\": \"01JD…\", \"scheduled_at\": \"2026-12-01T09:00:00+07:00\" } → preview; then the same call with \"confirm\": true → { post, note }",
    rules: "The confirm sends exactly what that preview showed (a signed confirm_token kept by the server, valid 10 minutes). Credits are charged when it is scheduled (Monthly Credits first, then Top-up; on every plan). On Free, scheduled_at is at most 7 days ahead and at most 10 posts can be queued. Connected apps can send at most 20 posts a day.",
    errors: [
      ["preview_stale", "nothing was sent; show the user the new preview in details.preview and confirm again only if they agree"],
      ["insufficient_credits or quota_exceeded", "pass the message on as it is (nothing was charged); retry after a top-up"],
      ["plan_required", "details.feature schedule_window or queue_limit — pass the message on as it is"],
      ["not_draft", "list_posts status \"draft\", or draft_post"],
      ["invalid_input", "scheduled_at must be in the future"],
      ["daily_cap_reached", "send from creatif.space or wait for details.resets_at"],
      ["reconnect_required", "the user reconnects the channel"],
      ["scope_missing", "reconnect and allow \"publish\""],
    ],
  }),
  scope: 'publish',
  input: {
    post_id: ulid('Draft post'),
    scheduled_at: z.iso.datetime({ offset: true }).describe('When to publish, ISO 8601, in the future'),
    confirm,
  },
  annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true },
  notFound: { what: 'That post', next: 'Check the post id: find it with list_posts (status draft for drafts) and call again.' },
  run: async (args, ctx) => {
    if (Date.parse(args.scheduled_at) <= Date.now()) {
      throw new ToolError('invalid_input', 'scheduled_at must be in the future.', 'Pick a later time and call again, or use publish_post to send now.', {
        field: 'scheduled_at',
        scheduled_at: args.scheduled_at,
        now: new Date().toISOString(),
      });
    }
    if (!args.confirm) {
      return previewResult(await preview(ctx, args.post_id, args.scheduled_at), 'schedule', args.scheduled_at);
    }
    const res = await confirmedSend(ctx, args.post_id, 'schedule', { scheduled_at: args.scheduled_at }, args.scheduled_at);
    if (!isSent(res)) return res;
    return { post: shapePost(res.data), note: 'Scheduled. It will publish at scheduled_at on the listed channels.' };
  },
});

export const publishPost = defineTool({
  name: 'publish_post',
  title: 'Publish a draft now',
  description: describeTool({
    does: "Publishes a DRAFT now on its channels — it goes public and cannot be undone — after the user agrees to a preview of channels and Credit cost.",
    when: "only when the user wants it out now; use schedule_post for later and create_approval_link if a client must approve first. Two steps: call with confirm false (default) to get the preview; show it to the user, and only after they explicitly agree call again with confirm: true.",
    example: "publish_post { \"post_id\": \"01JD…\" } → preview; then publish_post { \"post_id\": \"01JD…\", \"confirm\": true } → { post, note }; then get_post for each channel’s link",
    rules: "The confirm sends exactly what that preview showed (a signed confirm_token kept by the server, valid 10 minutes). Credits are charged (Monthly Credits first, then Top-up; on every plan). Connected apps can send at most 20 posts a day.",
    errors: [
      ["preview_stale", "nothing was sent; show the user the new preview in details.preview and confirm again only if they agree"],
      ["insufficient_credits or quota_exceeded", "pass the message on as it is (nothing was charged); retry after a top-up"],
      ["plan_required", "pass the message on as it is"],
      ["not_draft", "list_posts status \"draft\", or draft_post"],
      ["daily_cap_reached", "send from creatif.space or wait for details.resets_at"],
      ["reconnect_required", "the user reconnects the channel"],
      ["scope_missing", "reconnect and allow \"publish\""],
      ["upstream_unavailable", "check get_post before calling again — it may have gone out"],
    ],
  }),
  scope: 'publish',
  input: {
    post_id: ulid('Draft post'),
    confirm,
  },
  annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true },
  notFound: { what: 'That post', next: 'Check the post id: find it with list_posts (status draft for drafts) and call again.' },
  run: async (args, ctx) => {
    if (!args.confirm) {
      return previewResult(await preview(ctx, args.post_id), 'publish');
    }
    const res = await confirmedSend(ctx, args.post_id, 'publish', {});
    if (!isSent(res)) return res;
    return { post: shapePost(res.data), note: 'Sent to the channels. Check get_post for each channel’s delivery status and link.' };
  },
});

export const m2Tools = [createApprovalLink, checkApprovalStatus, sendApprovalReminder, updatePost, schedulePost, publishPost];

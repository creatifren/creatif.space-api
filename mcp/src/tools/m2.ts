import { z } from 'zod';
import { ToolError } from '../errors.js';
import { V1Error } from '../v1.js';
import { defineTool, type ToolContext } from './define.js';
import { shapePost, type V1Post } from './shape.js';

const ulid = (what: string) =>
  z.string().trim().min(10).max(26).describe(`${what} id, as returned by the list tools`);

const confirm = z
  .boolean()
  .default(false)
  .describe('Leave false first: you get a preview (channels and Credit cost). Set true only after the user explicitly agreed to that preview.');

interface SendPreview {
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

  remember(user: string, postId: string, p: SendPreview): void {
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

async function preview(ctx: ToolContext, postId: string, scheduledAt?: string): Promise<SendPreview> {
  // With the slot, the plan's limits (scheduling window, queue) answer
  // plan_required here, before the user is asked to confirm anything.
  const { data } = await ctx.v1.get<{ data: SendPreview }>(
    `/social-posts/${encodeURIComponent(postId)}/send-preview`,
    scheduledAt ? { scheduled_at: scheduledAt } : undefined,
  );
  if (!data.sendable) {
    throw new ToolError(
      'invalid_input',
      `Only a draft can be scheduled or published from here; this post is "${data.status}".`,
      'Use list_posts with status "draft" to find drafts, or draft_post to make one.',
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
    const res = await ctx.v1.post<{ data: V1Post }>(`/social-posts/${encodeURIComponent(postId)}/send`, { ...body, confirm_token: token });
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
  description:
    'Creates a creatif.space approval link for one or more posts (drafts, or posts already held for approval) and returns its URL for the user to share with their client. ' +
    'The posts are held — nothing is published until the client approves. approver_email is required: only that person, signed in with that address (Google or a one-time email link on the approval page), can approve or request changes; ask the user for it if you do not have it. ' +
    'Every plan: on Free, links are for one client at a time (the approver_email tells clients apart; links to the same address are fine), posts scheduled at most 7 days ahead, and at most 10 queued posts. ' +
    'Past a Free limit it returns plan_required (details.feature approval_clients, schedule_window or queue_limit) with a message to pass on to the user as it is (it has the trial/upgrade link). ' +
    'The link is not sent anywhere by this tool; hand the URL to the user.',
  scope: 'approve',
  input: {
    post_ids: z.array(ulid('Post')).min(1).max(50).describe('Posts to put on the link'),
    approver_email: z.string().trim().email().max(254).describe('Email of the person who approves; they sign in with it to decide'),
    title: z.string().trim().max(120).optional().describe('Shown to the client above the posts'),
    client_name: z.string().trim().max(120).optional().describe('Private note on who it is for; the client never sees it'),
    expires_in_hours: z.union([z.literal(48), z.literal(168), z.literal(720)]).optional().describe('48, 168 (7 days) or 720 (30 days); omit for no expiry'),
  },
  annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true },
  run: async (args, { v1 }) => {
    const { data } = await v1.post<{ data: Record<string, unknown> }>('/social-approval-links', {
      post_ids: args.post_ids,
      approver_email: args.approver_email,
      ...(args.title ? { title: args.title } : {}),
      ...(args.client_name ? { client_name: args.client_name } : {}),
      ...(args.expires_in_hours ? { expires_in_hours: args.expires_in_hours } : {}),
    });
    return {
      link: {
        id: data.id,
        url: data.url,
        status: data.status,
        posts: data.posts,
        approver_email: data.approver_email,
        expires_at: data.expires_at,
      },
      note: 'Share the url with the client. They sign in with the approver email to decide. Use check_approval_status with link_id to follow their decisions.',
    };
  },
});

export const checkApprovalStatus = defineTool({
  name: 'check_approval_status',
  title: 'Check approval status',
  description:
    'Checks where client approval stands — for one post (post_id) or for a whole approval link (link_id): approved / waiting / changes requested, who decided and when, and the open feedback threads.',
  scope: 'read',
  input: {
    post_id: ulid('Post').optional(),
    link_id: ulid('Approval link').optional(),
  },
  annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
  notFound: 'That post or approval link',
  run: async (args, { v1 }) => {
    if (!!args.post_id === !!args.link_id) {
      throw new ToolError('invalid_input', 'Give exactly one of post_id or link_id.', 'Pass post_id for one post, or link_id for a link.');
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

/** The post's current media as the API takes it back: url, plus a video cover when there is one. */
const keptMedia = (post: V1Post) =>
  (post.media ?? []).map((m) => ({ url: m.url, ...(m.thumbnail_url ? { thumbnail_url: m.thumbnail_url } : {}) }));

export const updatePost = defineTool({
  name: 'update_post',
  title: 'Update a post',
  description:
    'Changes a draft or a post still waiting for approval: its caption, its scheduled time, its labels, or its media. ' +
    'Media: file_ids REPLACES the post’s media with these library files (ids from list_files, or from confirm_media_upload for a new upload); add_file_ids keeps the current media and adds these after it (10 items at most). ' +
    'Example: update_post { "post_id": "01JD…", "add_file_ids": ["01JE…"] }. A file that was not confirmed is refused with file_not_ready — call confirm_media_upload first. ' +
    'Posts already queued to publish, published or otherwise done cannot be changed from here (not_editable) — the user edits those in creatif.space.',
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
  notFound: 'That post',
  run: async (args, { v1 }) => {
    if (args.file_ids !== undefined && args.add_file_ids !== undefined) {
      throw new ToolError('invalid_input', 'Pass file_ids or add_file_ids, not both.', 'file_ids replaces the media; add_file_ids appends to it.');
    }
    const changesMedia = args.file_ids !== undefined || args.add_file_ids !== undefined;
    if (args.caption === undefined && args.scheduled_at === undefined && args.labels === undefined && !changesMedia) {
      throw new ToolError('invalid_input', 'Nothing to change.', 'Pass caption, scheduled_at, labels, file_ids and/or add_file_ids.');
    }
    // The API decides what may change (drafts and posts waiting for approval
    // only, through a token); this only says so before trying.
    const { data: current } = await v1.get<{ data: V1Post }>(`/social-posts/${encodeURIComponent(args.post_id)}`);
    const editable = current.status === 'draft' || (current.status === 'scheduled' && current.approval !== null && current.approval !== 'approved');
    if (!editable) {
      throw new ToolError(
        'not_editable',
        `Only drafts and posts waiting for approval can be changed from here; this post is "${current.status}"${current.approval === 'approved' ? ' (approved)' : ''}.`,
        'Tell the user to change it in creatif.space.',
      );
    }
    if (args.add_file_ids !== undefined && keptMedia(current).length + args.add_file_ids.length > 10) {
      throw new ToolError(
        'invalid_input',
        `A post takes up to 10 media items; this one has ${keptMedia(current).length}.`,
        'Add fewer files, or pass file_ids to replace the media instead.',
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
  description:
    'Schedules a DRAFT to publish at a future time on its channels. Two steps: call with confirm false (default) to get a preview — channels and Credit cost; ' +
    'show it to the user, and only after they explicitly agree call again with confirm: true. The confirm sends exactly what that preview showed (it carries the preview’s signed confirm_token, valid 10 minutes); if the channels or cost changed, nothing is sent and you get preview_stale with the new preview. Credits are charged when it is scheduled (Monthly Credits first, then Top-up; on every plan). ' +
    'If the Credits do not cover it you get insufficient_credits or quota_exceeded with a message to pass on as it is — nothing is charged. ' +
    'On Free, scheduled_at is at most 7 days ahead and at most 10 posts can be queued: past that you get plan_required (details.feature schedule_window or queue_limit) with a message to pass on as it is. Connected apps can send at most 20 posts a day.',
  scope: 'publish',
  input: {
    post_id: ulid('Draft post'),
    scheduled_at: z.iso.datetime({ offset: true }).describe('When to publish, ISO 8601, in the future'),
    confirm,
  },
  annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true },
  notFound: 'That post',
  run: async (args, ctx) => {
    if (Date.parse(args.scheduled_at) <= Date.now()) {
      throw new ToolError('invalid_input', 'scheduled_at must be in the future.', 'Use publish_post to send now, or pick a later time.');
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
  description:
    'Publishes a DRAFT now on its channels — this goes public and cannot be undone. Two steps: call with confirm false (default) to get a preview — channels and Credit cost; ' +
    'show it to the user, and only after they explicitly agree call again with confirm: true. The confirm sends exactly what that preview showed (it carries the preview’s signed confirm_token, valid 10 minutes); if the channels or cost changed, nothing is sent and you get preview_stale with the new preview. Credits are charged (Monthly Credits first, then Top-up; on every plan). ' +
    'If the Credits do not cover it you get insufficient_credits or quota_exceeded, and past a plan limit plan_required, each with a message to pass on as it is — nothing is charged. Connected apps can send at most 20 posts a day.',
  scope: 'publish',
  input: {
    post_id: ulid('Draft post'),
    confirm,
  },
  annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true },
  notFound: 'That post',
  run: async (args, ctx) => {
    if (!args.confirm) {
      return previewResult(await preview(ctx, args.post_id), 'publish');
    }
    const res = await confirmedSend(ctx, args.post_id, 'publish', {});
    if (!isSent(res)) return res;
    return { post: shapePost(res.data), note: 'Sent to the channels. Check get_post for each channel’s delivery status and link.' };
  },
});

export const m2Tools = [createApprovalLink, checkApprovalStatus, updatePost, schedulePost, publishPost];

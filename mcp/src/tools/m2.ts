import { z } from 'zod';
import { ToolError } from '../errors.js';
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
}

async function preview(v1: ToolContext['v1'], postId: string): Promise<SendPreview> {
  const { data } = await v1.get<{ data: SendPreview }>(`/social-posts/${encodeURIComponent(postId)}/send-preview`);
  if (!data.sendable) {
    throw new ToolError(
      'invalid_input',
      `Only a draft can be scheduled or published from here; this post is "${data.status}".`,
      'Use list_posts with status "draft" to find drafts, or draft_post to make one.',
    );
  }
  return data;
}

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
    'The posts are held — nothing is published until the client approves. Pro and Max plans only (plan_required otherwise). ' +
    'The link is not sent anywhere by this tool; hand the URL to the user.',
  scope: 'approve',
  input: {
    post_ids: z.array(ulid('Post')).min(1).max(50).describe('Posts to put on the link'),
    title: z.string().trim().max(120).optional().describe('Shown to the client above the posts'),
    client_name: z.string().trim().max(120).optional().describe('Private note on who it is for; the client never sees it'),
    expires_in_hours: z.union([z.literal(48), z.literal(168), z.literal(720)]).optional().describe('48, 168 (7 days) or 720 (30 days); omit for no expiry'),
  },
  annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true },
  run: async (args, { v1 }) => {
    const { data } = await v1.post<{ data: Record<string, unknown> }>('/social-approval-links', {
      post_ids: args.post_ids,
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
        expires_at: data.expires_at,
      },
      note: 'Share the url with the client. Use check_approval_status with link_id to follow their decisions.',
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

export const updatePost = defineTool({
  name: 'update_post',
  title: 'Update a post',
  description:
    'Changes a post that has not gone out: its caption, its scheduled time, or its labels. Works on drafts and on posts held for approval with the "draft" permission; ' +
    'changing a post already queued to publish also needs the "publish" permission. Published posts cannot be changed.',
  scope: 'draft',
  input: {
    post_id: ulid('Post'),
    caption: z.string().min(1).max(63206).optional(),
    scheduled_at: z.iso.datetime({ offset: true }).optional().describe('New time, ISO 8601, in the future'),
    labels: z.array(z.string().max(40)).max(10).optional().describe('Replaces the post’s labels'),
  },
  annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
  notFound: 'That post',
  run: async (args, { v1, auth }) => {
    if (args.caption === undefined && args.scheduled_at === undefined && args.labels === undefined) {
      throw new ToolError('invalid_input', 'Nothing to change.', 'Pass caption, scheduled_at and/or labels.');
    }
    const { data: current } = await v1.get<{ data: V1Post }>(`/social-posts/${encodeURIComponent(args.post_id)}`);
    const queued = current.status === 'scheduled' && current.approval === null;
    if (queued && !auth.scopes.includes('publish') && (args.caption !== undefined || args.scheduled_at !== undefined)) {
      throw new ToolError(
        'scope_missing',
        'This post is already queued to publish; changing it needs the "publish" permission.',
        'Reconnect the creatif.space connector and allow "publish", or ask the user to edit it in creatif.space.',
        { required: 'publish', granted: auth.scopes },
      );
    }
    const { data } = await v1.patch<{ data: V1Post }>(`/social-posts/${encodeURIComponent(args.post_id)}`, {
      ...(args.caption !== undefined ? { caption: args.caption } : {}),
      ...(args.scheduled_at !== undefined ? { scheduled_at: args.scheduled_at } : {}),
      ...(args.labels !== undefined ? { labels: args.labels } : {}),
    });
    return { post: shapePost(data) };
  },
});

export const schedulePost = defineTool({
  name: 'schedule_post',
  title: 'Schedule a draft',
  description:
    'Schedules a DRAFT to publish at a future time on its channels. Two steps: call with confirm false (default) to get a preview — channels and Credit cost; ' +
    'show it to the user, and only after they explicitly agree call again with confirm: true. Credits are charged when it is scheduled. Connected apps can send at most 20 posts a day.',
  scope: 'publish',
  input: {
    post_id: ulid('Draft post'),
    scheduled_at: z.iso.datetime({ offset: true }).describe('When to publish, ISO 8601, in the future'),
    confirm,
  },
  annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true },
  notFound: 'That post',
  run: async (args, { v1 }) => {
    if (Date.parse(args.scheduled_at) <= Date.now()) {
      throw new ToolError('invalid_input', 'scheduled_at must be in the future.', 'Use publish_post to send now, or pick a later time.');
    }
    if (!args.confirm) {
      return previewResult(await preview(v1, args.post_id), 'schedule', args.scheduled_at);
    }
    const { data } = await v1.post<{ data: V1Post }>(`/social-posts/${encodeURIComponent(args.post_id)}/send`, {
      scheduled_at: args.scheduled_at,
    });
    return { post: shapePost(data), note: 'Scheduled. It will publish at scheduled_at on the listed channels.' };
  },
});

export const publishPost = defineTool({
  name: 'publish_post',
  title: 'Publish a draft now',
  description:
    'Publishes a DRAFT now on its channels — this goes public and cannot be undone. Two steps: call with confirm false (default) to get a preview — channels and Credit cost; ' +
    'show it to the user, and only after they explicitly agree call again with confirm: true. Credits are charged. Connected apps can send at most 20 posts a day.',
  scope: 'publish',
  input: {
    post_id: ulid('Draft post'),
    confirm,
  },
  annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true },
  notFound: 'That post',
  run: async (args, { v1 }) => {
    if (!args.confirm) {
      return previewResult(await preview(v1, args.post_id), 'publish');
    }
    const { data } = await v1.post<{ data: V1Post }>(`/social-posts/${encodeURIComponent(args.post_id)}/send`, {});
    return { post: shapePost(data), note: 'Sent to the channels. Check get_post for each channel’s delivery status and link.' };
  },
});

export const m2Tools = [createApprovalLink, checkApprovalStatus, updatePost, schedulePost, publishPost];

import { z } from 'zod';
import { ToolError } from '../errors.js';
import { defineTool } from './define.js';
import { shapePost, type V1Post } from './shape.js';

const READ_ONLY = { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false } as const;

const ulid = (what: string) =>
  z.string().trim().min(10).max(26).describe(`${what} id, as returned by the list tools`);

export const listChannels = defineTool({
  name: 'list_channels',
  title: 'List connected channels',
  description:
    'Lists the social channels (Instagram, Facebook, TikTok, LinkedIn, X, Threads, YouTube, Pinterest, Bluesky…) connected to the user’s creatif.space workspace. ' +
    'Use the returned channel ids for list_posts and draft_post. A channel with reconnect_required=true or status other than "connected" cannot be posted to until the user reconnects it in creatif.space → Channels.',
  scope: 'read',
  input: {},
  annotations: READ_ONLY,
  run: async (_args, { v1 }) => {
    const res = await v1.get<{ data: Record<string, unknown>[] }>('/social-accounts');
    return {
      channels: res.data.map((a) => ({
        id: a.id,
        platform: a.platform,
        username: a.username,
        status: a.status,
        reconnect_required: a.reconnect_required === true || a.status !== 'connected',
        connected_at: a.connected_at,
      })),
    };
  },
});

export const listPosts = defineTool({
  name: 'list_posts',
  title: 'List posts',
  description:
    'Lists the user’s creatif.space posts, newest first: drafts, scheduled, published, failed and cancelled. ' +
    'Filter by status, by a date window (from/to, ISO 8601 — matched on the scheduled time, or on creation time for unscheduled posts) and by channel. ' +
    'Comments are omitted here; call get_post for one post’s full detail and review thread.',
  scope: 'read',
  input: {
    status: z.enum(['draft', 'scheduled', 'published', 'failed', 'cancelled']).optional().describe('Only posts in this state'),
    from: z.iso.datetime({ offset: true }).optional().describe('Window start, ISO 8601 (e.g. 2026-11-01T00:00:00Z)'),
    to: z.iso.datetime({ offset: true }).optional().describe('Window end, ISO 8601'),
    channel_id: ulid('Channel').optional().describe('Only posts going to this channel (id from list_channels)'),
    limit: z.number().int().min(1).max(100).default(25).describe('At most this many posts (1–100, default 25)'),
  },
  annotations: READ_ONLY,
  run: async (args, { v1 }) => {
    const res = await v1.get<{ data: V1Post[] }>('/social-posts', {
      status: args.status,
      from: args.from,
      to: args.to,
      account_id: args.channel_id,
      limit: args.limit,
    });
    return { count: res.data.length, posts: res.data.map((p) => shapePost(p)) };
  },
});

export const getPost = defineTool({
  name: 'get_post',
  title: 'Get a post',
  description:
    'Gets one creatif.space post by id: caption, media, schedule, approval state, each channel’s delivery status and link, and the review thread (comments, client feedback, suggestions).',
  scope: 'read',
  input: { post_id: ulid('Post') },
  annotations: READ_ONLY,
  notFound: 'That post',
  run: async ({ post_id }, { v1 }) => {
    const res = await v1.get<{ data: V1Post }>(`/social-posts/${encodeURIComponent(post_id)}`);
    return { post: shapePost(res.data, true) };
  },
});

export const listFiles = defineTool({
  name: 'list_files',
  title: 'List library files',
  description:
    'Lists files in the user’s creatif.space library (Crefile), newest first. Filter by a name search and by type (image, video, pdf). ' +
    'Pass the returned file ids to draft_post to attach them. The url is a signed link valid for about 24 hours.',
  scope: 'read',
  input: {
    search: z.string().trim().max(255).optional().describe('Match on file name'),
    type: z.enum(['image', 'video', 'pdf']).optional(),
    folder_id: ulid('Folder').optional().describe('Only files directly in this folder'),
    limit: z.number().int().min(1).max(60).default(30).describe('At most this many files (1–60, default 30)'),
  },
  annotations: READ_ONLY,
  run: async (args, { v1 }) => {
    const res = await v1.get<{ data: Record<string, unknown>[]; meta?: Record<string, unknown> }>('/files', {
      search: args.search,
      type: args.type,
      folder_id: args.folder_id,
      per_page: args.limit,
    });
    return {
      total: res.meta?.total ?? res.data.length,
      files: res.data.map((f) => ({
        id: f.id,
        name: f.name,
        mime_type: f.mime_type,
        size_bytes: f.size_bytes,
        width: f.width,
        height: f.height,
        status: f.status,
        url: f.url,
        created_at: f.created_at,
      })),
    };
  },
});

export const draftPost = defineTool({
  name: 'draft_post',
  title: 'Draft a post',
  description:
    'Saves a DRAFT post in creatif.space for the given channels. Nothing is published or scheduled and no Credits are spent (on every plan, Free included); the user reviews and sends it from creatif.space, or you schedule/publish it with the user’s explicit agreement. ' +
    'Attach media by library file ids (from list_files, or from confirm_media_upload for a file you just uploaded — an upload that was not confirmed is refused with file_not_ready) and/or public https media URLs (up to 10 in all). ' +
    'captions_by_channel optionally overrides the caption for specific channels, e.g. a shorter one for X.',
  scope: 'draft',
  input: {
    caption: z.string().min(1).max(63206).describe('The post caption'),
    channel_ids: z.array(ulid('Channel')).min(1).max(20).describe('Channels to post to (ids from list_channels)'),
    file_ids: z.array(ulid('File')).max(10).optional().describe('Library files to attach (ids from list_files or confirm_media_upload)'),
    media_urls: z.array(z.url({ protocol: /^https$/ })).max(10).optional().describe('Public https image/video URLs to attach'),
    captions_by_channel: z
      .record(z.string(), z.string().min(1).max(63206))
      .optional()
      .describe('Per-channel caption overrides: { "<channel id>": "caption" }'),
    type: z.enum(['post', 'reels', 'story']).default('post').describe('Placement on Facebook, Instagram and Threads; others post normally'),
  },
  annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
  run: async (args, { v1 }) => {
    const unknownChannels = Object.keys(args.captions_by_channel ?? {}).filter((id) => !args.channel_ids.includes(id));
    if (unknownChannels.length > 0) {
      throw new ToolError(
        'invalid_input',
        'captions_by_channel names channels that are not in channel_ids.',
        'Use only ids that are also listed in channel_ids.',
        { channels: unknownChannels },
      );
    }
    if ((args.file_ids?.length ?? 0) + (args.media_urls?.length ?? 0) > 10) {
      throw new ToolError('invalid_input', 'A post takes up to 10 media items.', 'Attach 10 or fewer files and URLs in total.');
    }

    const res = await v1.post<{ data: V1Post }>('/social-posts', {
      caption: args.caption,
      account_ids: args.channel_ids,
      ...(args.captions_by_channel ? { captions: args.captions_by_channel } : {}),
      ...(args.media_urls?.length ? { media: args.media_urls.map((url) => ({ url })) } : {}),
      ...(args.file_ids?.length ? { file_ids: args.file_ids } : {}),
      type: args.type,
      draft: true,
    });
    return {
      post: shapePost(res.data),
      note: 'Saved as a draft. Nothing was published and no Credits were spent; the user can review and send it in creatif.space.',
    };
  },
});

/** Credits already spoken for by sends handed over but not yet out. */
interface CommittedCredits {
  sends: number;
  sends_monthly: number;
}

export const getCredits = defineTool({
  name: 'get_credits',
  title: 'Get plan, limits and Credits',
  description:
    'Gets the workspace’s creatif.space plan and its limits, all as set on the plan: plan name; this month’s posts vs the plan’s monthly figure; connected channels used vs allowed; ' +
    'scheduling (how many days ahead a post may be scheduled, and queued posts — scheduled or waiting for approval — used vs allowed); approval clients used vs allowed; ' +
    'features (approval links, analytics, AI captions, Spaces…); Credits (Monthly, when they reset, Top-up, total, committed to scheduled posts); the Credit price per send by platform; ' +
    'and how many sends connected apps have left today (cap 20/day). Every plan, Free included, can use this server: sending spends Credits. Free schedules up to 7 days ahead, holds up to 10 queued posts and has approval links for one client; null means no limit (Pro, Max). ' +
    'Call this before scheduling or publishing when the user asks what they can afford.',
  scope: 'read',
  input: {},
  annotations: READ_ONLY,
  run: async (_args, { v1 }) => {
    const { data } = await v1.get<{
      data: Record<string, unknown> & { limits?: Record<string, unknown>; committed?: CommittedCredits };
    }>('/credits');
    const limits = data.limits ?? {};
    // Only the sends: storage rental (and its `rent`) is gone.
    const committed = data.committed ? { sends: data.committed.sends, sends_monthly: data.committed.sends_monthly } : undefined;
    return {
      plan: limits.plan ?? { key: data.plan },
      credits: { ...(limits.credits as object | undefined), committed },
      price_per_send: limits.price_per_send,
      monthly_posts: limits.monthly_posts,
      connected_accounts: limits.connected_accounts,
      scheduling: limits.scheduling,
      approval_clients: limits.approval_clients,
      features: limits.features,
      mcp_daily_sends: limits.mcp_daily_sends,
      urls: limits.urls,
    };
  },
});

export const m1Tools = [listChannels, listPosts, getPost, listFiles, draftPost, getCredits];

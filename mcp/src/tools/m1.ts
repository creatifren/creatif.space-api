import { z } from 'zod';
import { ToolError } from '../errors.js';
import { defineTool, describeTool } from './define.js';
import { shapePost, type V1Post } from './shape.js';
import { decodeCursor, encodeCursor, pageInput, pageOf } from './paging.js';

const READ_ONLY = { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false } as const;

const ulid = (what: string) =>
  z.string().trim().min(10).max(26).describe(`${what} id, as returned by the list tools`);

export const listChannels = defineTool({
  name: 'list_channels',
  title: 'List connected channels',
  description: describeTool({
    does: "Lists the social channels (Instagram, Facebook, TikTok, LinkedIn, X, Threads, YouTube, Pinterest, Bluesky…) connected to the user’s creatif.space workspace, with each channel’s id and whether it can be posted to.",
    when: "first, before draft_post, list_posts by channel or any send — it is the only source of channel ids. A channel with reconnect_required=true (or status other than \"connected\") cannot be posted to until the user reconnects it: such channels carry a reconnect block, and needs_reconnect lists them — call get_connect_link with the channel’s platform for the link to send the user. To add a new network, use get_connect_link too. A disconnected copy of an account that is also connected (same network and username) is left out; hidden_duplicates counts them, include_disconnected: true shows them.",
    example: "list_channels {} → { channels: [{ id: \"01JD…\", platform: \"instagram\", username: \"studio\", status: \"connected\", reconnect_required: false }, { id: \"01JE…\", platform: \"x\", status: \"expired\", reconnect_required: true, reconnect: { reason, next_step } }], needs_reconnect: [\"01JE…\"] }",
    errors: [
      ["scope_missing", "reconnect and allow \"read\""],
      ["unauthorized", "ask the user to reconnect the creatif.space connector"],
      ["upstream_unavailable", "wait a minute and call again"],
    ],
  }),
  scope: 'read',
  input: {
    include_disconnected: z
      .boolean()
      .default(false)
      .describe('true: also list disconnected copies of accounts that are connected too (hidden by default)'),
    ...pageInput(100, 50, 'channels'),
  },
  annotations: READ_ONLY,
  run: async (args, { v1 }) => {
    const res = await v1.get<{ data: Record<string, unknown>[] }>('/social-accounts');
    const dupes = args.include_disconnected ? new Set<unknown>() : disconnectedDuplicates(res.data);
    const rows = res.data.filter((a) => !dupes.has(a.id));
    const { page, next_cursor } = pageOf('list_channels', { include_disconnected: args.include_disconnected }, rows, args.limit, args.cursor);
    const channels = page.map((a) => {
      const reconnect = needsReconnect(a);
      return {
        id: a.id,
        platform: a.platform,
        username: a.username,
        status: a.status,
        reconnect_required: reconnect,
        ...(reconnect ? { reconnect: reconnectHint(a) } : {}),
        // Posting works; replying to DMs needs a fresh sign-in with messages allowed.
        ...(!reconnect && a.needs_reconnect_for_messages === true
          ? { reconnect_for_messages: { next_step: `Posting works. To reply to DMs, the user reconnects it and allows messages: get_connect_link { "network": "${String(a.platform)}" }.` } }
          : {}),
      };
    });
    const needs = channels.filter((c) => c.reconnect_required).map((c) => c.id);
    return {
      channels,
      next_cursor,
      ...(dupes.size
        ? {
            hidden_duplicates: dupes.size,
            note: `${dupes.size} disconnected cop${dupes.size === 1 ? 'y' : 'ies'} of a connected account ${dupes.size === 1 ? 'is' : 'are'} left out; post to the connected one. include_disconnected: true lists ${dupes.size === 1 ? 'it' : 'them'}.`,
          }
        : {}),
      ...(needs.length
        ? {
            needs_reconnect: needs,
            next_step: 'Channels in needs_reconnect cannot be posted to. Tell the user which ones, and give them the link from get_connect_link for each platform; do not draft or send to them until they show as connected.',
          }
        : {}),
    };
  },
});

const needsReconnect = (a: Record<string, unknown>) => a.reconnect_required === true || a.status !== 'connected';
const accountKey = (a: Record<string, unknown>) =>
  typeof a.username === 'string' && a.username.trim() !== '' ? `${String(a.platform)}:${a.username.trim().replace(/^@/, '').toLowerCase()}` : null;

/**
 * Ids of disconnected rows that copy a connected account (same network and
 * username, e.g. an old connection of @loyaldigicard next to the working
 * one). A disconnected account with no working copy is never hidden: the
 * user still has to reconnect it.
 */
export function disconnectedDuplicates(accounts: Record<string, unknown>[]): Set<unknown> {
  const connected = new Set(accounts.filter((a) => !needsReconnect(a)).map(accountKey).filter((k) => k !== null));
  return new Set(accounts.filter((a) => { const k = accountKey(a); return needsReconnect(a) && k !== null && connected.has(k); }).map((a) => a.id));
}

/** Why a channel needs reconnecting, in words the user understands, and the one next step. */
function reconnectHint(a: Record<string, unknown>) {
  const reason =
    a.reconnect_required === true && a.status === 'disconnected'
      ? 'It was connected through an older connection that no longer works; it has to be connected again.'
      : a.status === 'expired'
        ? 'The platform sign-in expired or was revoked.'
        : 'The channel is not connected right now.';
  return { reason, next_step: `Ask the user to reconnect it: get_connect_link { "network": "${String(a.platform)}" } gives the link.` };
}

const DAY = /^\d{4}-\d{2}-\d{2}$/;
const dateOrTime = z.union([z.iso.date(), z.iso.datetime({ offset: true })]);

/** list_posts statuses that are an approval state of a queued post, not an API status. */
const APPROVAL_FILTER: Record<string, string[]> = {
  waiting: ['client', 'awaiting'],
  changes_requested: ['revision'],
};
/** At most this many API pages of 100 are read to fill one page of an approval filter. */
const MAX_APPROVAL_ROUNDS = 5;

export const listPosts = defineTool({
  name: 'list_posts',
  title: 'List posts',
  description: describeTool({
    does: "Lists the user’s creatif.space posts, newest first — drafts, scheduled, published, failed and cancelled — filtered by status, date window and channel.",
    when: "to find post ids or see what is planned or out (e.g. status \"draft\" before schedule_post, publish_post or create_approval_link). status \"waiting\" is posts held for approval (the client or the user has not said yes yet), \"changes_requested\" the ones a client sent back; both are also in \"scheduled\". Comments are omitted here; use get_post for one post’s full detail and review thread. from/to are a date (YYYY-MM-DD, UTC; to covers the whole day) or an ISO 8601 time, matched on the scheduled time (creation time for unscheduled posts).",
    example: "list_posts { \"status\": \"draft\", \"limit\": 10 } → { count, posts: [{ id, status, caption, scheduled_at, approval, channels }] }",
    errors: [
      ["not_found", "the channel_id is unknown: take one from list_channels or drop the filter"],
      ["invalid_input", "fix the fields in details and call again"],
      ["unauthorized", "ask the user to reconnect the creatif.space connector"],
      ["upstream_unavailable", "wait a minute and call again"],
    ],
  }),
  scope: 'read',
  input: {
    status: z
      .enum(['draft', 'waiting', 'changes_requested', 'scheduled', 'published', 'failed', 'cancelled'])
      .optional()
      .describe('Only posts in this state: waiting = held for approval, changes_requested = sent back by the client'),
    from: dateOrTime.optional().describe('Window start: a date (YYYY-MM-DD, UTC) or ISO 8601 time, e.g. 2026-11-01'),
    to: dateOrTime.optional().describe('Window end: a date (the whole day, UTC) or ISO 8601 time, e.g. 2026-11-30'),
    channel_id: ulid('Channel').optional().describe('Only posts going to this channel (id from list_channels)'),
    ...pageInput(100, 25, 'posts'),
  },
  annotations: READ_ONLY,
  notFound: { what: 'That channel', next: 'Call list_channels for valid channel ids, or call list_posts without channel_id.' },
  run: async (args, { v1 }) => {
    const filters = { status: args.status, from: args.from, to: args.to, channel_id: args.channel_id };
    const { o } = decodeCursor('list_posts', filters, args.cursor);
    const from = args.from && DAY.test(args.from) ? `${args.from}T00:00:00Z` : args.from;
    const to = args.to && DAY.test(args.to) ? `${args.to}T23:59:59Z` : args.to;
    if (from && to && Date.parse(from) > Date.parse(to)) {
      throw new ToolError('invalid_input', 'from is after to.', 'Swap from and to (from is the earlier one) and call again.', { field: 'from', from: args.from, to: args.to });
    }

    // Approval states are not an API status: held posts sit in the queue as "scheduled", so
    // read that and keep the ones in the state asked for. The cursor counts raw rows read.
    const approval = args.status ? APPROVAL_FILTER[args.status] : undefined;
    if (approval) {
      const kept: V1Post[] = [];
      let at = o;
      let exhausted = false;
      for (let round = 0; round < MAX_APPROVAL_ROUNDS && kept.length <= args.limit && !exhausted; round++) {
        const res = await v1.get<{ data: V1Post[] }>('/social-posts', { status: 'scheduled', from, to, account_id: args.channel_id, limit: 100, offset: at || undefined });
        for (const p of res.data) {
          at++;
          if (approval.includes(p.approval ?? '')) kept.push(p);
          if (kept.length > args.limit) break;
        }
        exhausted = res.data.length < 100;
      }
      const more = kept.length > args.limit || !exhausted;
      const page = kept.slice(0, args.limit);
      // With one row too many, the next page starts right at that row.
      const next = kept.length > args.limit ? at - 1 : at;
      return {
        count: page.length,
        posts: page.map((p) => shapePost(p)),
        next_cursor: more ? encodeCursor('list_posts', filters, { o: next }) : null,
      };
    }

    // One extra row says whether another page exists (the API caps a page at 100).
    const ask = Math.min(args.limit + 1, 100);
    const res = await v1.get<{ data: V1Post[] }>('/social-posts', {
      status: args.status,
      from,
      to,
      account_id: args.channel_id,
      limit: ask,
      offset: o || undefined,
    });
    const page = res.data.slice(0, args.limit);
    const more = res.data.length > args.limit || (ask === args.limit && res.data.length === ask);
    return {
      count: page.length,
      posts: page.map((p) => shapePost(p)),
      next_cursor: more ? encodeCursor('list_posts', filters, { o: o + args.limit }) : null,
    };
  },
});

export const getPost = defineTool({
  name: 'get_post',
  title: 'Get a post',
  description: describeTool({
    does: "Gets one creatif.space post by id: caption, media, schedule, approval state, each channel’s delivery status and link, and the review thread (comments, client feedback, suggestions).",
    when: "for the detail of one post you already have the id of (list_posts gives ids), e.g. to check delivery after publish_post. For approval decisions alone, check_approval_status is shorter.",
    example: "get_post { \"post_id\": \"01JD…\" } → { post: { id, status, caption, channels: [{ platform, status, platform_url }], comments } }",
    errors: [
      ["not_found", "find the id with list_posts"],
      ["unauthorized", "ask the user to reconnect the creatif.space connector"],
      ["upstream_unavailable", "wait a minute and call again"],
    ],
  }),
  scope: 'read',
  input: { post_id: ulid('Post') },
  annotations: READ_ONLY,
  notFound: { what: 'That post', next: 'Check the post id: find it with list_posts (status draft for drafts) and call again.' },
  run: async ({ post_id }, { v1 }) => {
    const res = await v1.get<{ data: V1Post }>(`/social-posts/${encodeURIComponent(post_id)}`);
    return { post: shapePost(res.data, true) };
  },
});

export const listFiles = defineTool({
  name: 'list_files',
  title: 'List library files',
  description: describeTool({
    does: "Lists files in the user’s creatif.space library (Crefile), newest first, filtered by name search, type (image, video, pdf) and folder.",
    when: "to attach media that is already in the library: pass the returned ids to draft_post or update_post file_ids. For a new file you hold the bytes of, use request_media_upload instead; for a public https URL, pass it to draft_post media_urls. The url is a signed link valid about 24 hours.",
    example: "list_files { \"search\": \"launch\", \"type\": \"image\" } → { total, files: [{ id, name, mime_type, status, url }] }",
    errors: [
      ["not_found", "the folder_id is unknown: call again without it"],
      ["unauthorized", "ask the user to reconnect the creatif.space connector"],
      ["upstream_unavailable", "wait a minute and call again"],
    ],
  }),
  scope: 'read',
  input: {
    search: z.string().trim().max(255).optional().describe('Match on file name'),
    type: z.enum(['image', 'video', 'pdf']).optional(),
    folder_id: ulid('Folder').optional().describe('Only files directly in this folder'),
    ...pageInput(60, 30, 'files'),
  },
  annotations: READ_ONLY,
  notFound: { what: 'That folder', next: 'Call list_files without folder_id to see the whole library.' },
  run: async (args, { v1 }) => {
    // The API pages by page number: the cursor keeps the page and the page size it started with.
    const filters = { search: args.search, type: args.type, folder_id: args.folder_id };
    const at = decodeCursor('list_files', filters, args.cursor);
    const perPage = at.n ?? args.limit;
    const pageNo = at.o + 1;
    const res = await v1.get<{ data: Record<string, unknown>[]; meta?: Record<string, unknown> }>('/files', {
      search: args.search,
      type: args.type,
      folder_id: args.folder_id,
      per_page: perPage,
      ...(pageNo > 1 ? { page: pageNo } : {}),
    });
    const last = typeof res.meta?.last_page === 'number' ? res.meta.last_page : pageNo;
    return {
      next_cursor: pageNo < last ? encodeCursor('list_files', filters, { o: pageNo, n: perPage }) : null,
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
  description: describeTool({
    does: "Saves a DRAFT post in creatif.space for the given channels — nothing is published or scheduled and no Credits are spent (on every plan).",
    when: "to create any new post; it is always the safe first step. The user reviews and sends it from creatif.space, or you send it later with schedule_post / publish_post (with the user’s explicit agreement) or share it with create_approval_link. To change an existing draft, use update_post instead of drafting again.",
    example: "draft_post { \"caption\": \"Winter set is live\", \"channel_ids\": [\"01JD…\"], \"file_ids\": [\"01JE…\"], \"captions_by_channel\": { \"01JF…\": \"Shorter for X\" } }",
    rules: "Media: library file ids (from list_files, or from confirm_media_upload for a file you just uploaded) and/or public https media URLs, 10 items in all. captions_by_channel overrides the caption for channels that are also in channel_ids. type picks the placement on Facebook, Instagram and Threads.",
    errors: [
      ["file_not_ready", "call confirm_media_upload for that file, or take ids from list_files"],
      ["invalid_input", "fix what details names (e.g. a captions_by_channel id not in channel_ids, more than 10 media)"],
      ["not_found", "take channel ids from list_channels and file ids from list_files"],
      ["reconnect_required", "the user reconnects the channel in creatif.space → Channels"],
      ["scope_missing", "reconnect and allow \"draft\""],
      ["upstream_unavailable", "check list_posts before calling again — it may have been saved"],
    ],
  }),
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
  notFound: { what: 'A channel or file', next: 'Take channel ids from list_channels and file ids from list_files (or confirm_media_upload), then call again.' },
  run: async (args, { v1 }) => {
    const unknownChannels = Object.keys(args.captions_by_channel ?? {}).filter((id) => !args.channel_ids.includes(id));
    if (unknownChannels.length > 0) {
      throw new ToolError(
        'invalid_input',
        'captions_by_channel names channels that are not in channel_ids.',
        'Use only ids that are also listed in channel_ids (add the channel to channel_ids, or drop it from captions_by_channel) and call again.',
        { field: 'captions_by_channel', channels: unknownChannels, channel_ids: args.channel_ids },
      );
    }
    if ((args.file_ids?.length ?? 0) + (args.media_urls?.length ?? 0) > 10) {
      throw new ToolError('invalid_input', 'A post takes up to 10 media items.', 'Attach 10 or fewer files and URLs in total, then call again.', {
        max: 10,
        file_ids: args.file_ids?.length ?? 0,
        media_urls: args.media_urls?.length ?? 0,
      });
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

interface SpendBlock {
  since?: string;
  by_action?: { publish?: number; reply?: number; ai?: number; total?: number };
}

/** This cycle's Credits by action, as the API counts them (undefined from an older API). */
function spendThisMonth(spend: SpendBlock | undefined) {
  if (!spend?.by_action) return undefined;
  const a = spend.by_action;
  return {
    since: spend.since ?? null,
    by_action: { publish: a.publish ?? 0, reply: a.reply ?? 0, ai: a.ai ?? 0 },
    total: a.total ?? (a.publish ?? 0) + (a.reply ?? 0) + (a.ai ?? 0),
  };
}

/** Whole days until the Monthly Credits renew (0 on the day itself). */
export function renewal(resetsAt: string | undefined, now: number = Date.now()) {
  if (!resetsAt) return undefined;
  const at = Date.parse(resetsAt);
  if (Number.isNaN(at)) return undefined;
  return { resets_at: resetsAt, days_until: Math.max(0, Math.ceil((at - now) / 86_400_000)) };
}

export const getCredits = defineTool({
  name: 'get_credits',
  title: 'Get plan, limits and Credits',
  description: describeTool({
    does: "Gets the workspace’s creatif.space plan, its limits and its Credits — everything that decides what can be sent.",
    when: "before schedule_post or publish_post when the user asks what they can afford or why something was refused. It returns, as set on the plan: plan name; this month’s posts vs the plan’s monthly figure; connected channels used vs allowed; scheduling (days ahead allowed, queued posts — scheduled or waiting for approval — used vs allowed); approval clients used vs allowed; features (approval links, analytics, AI captions, Spaces…); Credits (Monthly, when they reset, Top-up balance, total, committed to scheduled posts); the Credits each send uses, by platform; how many sends connected apps have left today (cap 20/day); this month’s Credit spend by action (publish, reply, ai — AI captions are free today, so ai is 0); and the days until the Monthly Credits renew.",
    example: "get_credits {} → { plan: { key: \"free\" }, credits: { monthly, topup, resets_at, committed }, price_per_send, scheduling, approval_clients, mcp_daily_sends, spend: { since, by_action: { publish, reply, ai }, total }, renewal: { resets_at, days_until } }",
    rules: "Every plan can use this server: sending spends Credits. Some plans schedule up to 7 days ahead, hold up to 10 queued posts and have approval links for one client; null means no limit.",
    errors: [
      ["unauthorized", "ask the user to reconnect the creatif.space connector"],
      ["upstream_unavailable", "wait a minute and call again"],
    ],
  }),
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
      features: aiCaptionHonest(limits.features, limits.ai_caption_ready),
      mcp_daily_sends: limits.mcp_daily_sends,
      urls: limits.urls,
      spend: spendThisMonth(limits.spend as SpendBlock | undefined),
      renewal: renewal((limits.credits as { resets_at?: string } | undefined)?.resets_at),
    };
  },
});

export const m1Tools = [listChannels, listPosts, getPost, listFiles, draftPost, getCredits];

/**
 * B10: the plan can include AI captions while the AI route is still
 * switched off; then suggest_caption answers not_enabled. features.ai_caption
 * says what suggest_caption will do, not only what the plan includes.
 */
export function aiCaptionHonest(features: unknown, ready: unknown): unknown {
  if (!features || typeof features !== 'object' || typeof ready !== 'boolean') return features;
  const f = features as Record<string, unknown>;
  return { ...f, ai_caption: f.ai_caption === true && ready };
}

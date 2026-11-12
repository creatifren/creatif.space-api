import { defineTool, describeTool } from './define.js';

const READ_ONLY = { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false } as const;

/*
 * get_account_summary: one read for onboarding — plan, what the plan's
 * limits leave, Credits, channels (and which need reconnecting) and media
 * storage. GET /credits, /social-accounts and /files/usage in parallel;
 * every number comes from the API (no plan figure lives here).
 */

interface Quota {
  limit: number | null;
  used: number;
}
interface Limits {
  plan?: { key: string; name: string };
  monthly_posts?: Quota & { resets_at?: string };
  connected_accounts?: Quota;
  scheduling?: { window_days: number | null; queued_posts: Quota };
  approval_clients?: Quota;
  credits?: { monthly: number; monthly_amount?: number; topup: number; total: number; resets_at: string };
}
interface V1Account {
  id: string;
  platform: string;
  username: string | null;
  status: string;
  reconnect_required?: boolean;
}
type Usage =
  | { tier: 'free'; used_bytes: number; quota_bytes: number }
  | { tier: string; keep_used_bytes: number; keep_quota_bytes: number };

/** used / limit / left; null limit = no limit, so nothing to run out of. */
const quota = (q?: Quota) => (q ? { used: q.used, limit: q.limit, left: q.limit === null ? null : Math.max(0, q.limit - q.used) } : undefined);

export const getAccountSummary = defineTool({
  name: 'get_account_summary',
  title: 'Get an account summary',
  description: describeTool({
    does: 'Gets the workspace at a glance in one call: plan, what its limits leave (posts this month, channels, queued posts, approval clients), Credits left, channels connected and needing reconnect, and media storage used.',
    when: 'at the start of a conversation or when onboarding a user, to know what they can do before planning. For Credits per send and this month’s spend, use get_credits; for which files will be deleted, get_storage_usage; for channel ids to post to, list_channels.',
    example: 'get_account_summary {} → { plan: { key: "free", name }, limits: { monthly_posts: { used, limit, left }, connected_accounts, queued_posts, approval_clients, schedule_window_days }, credits: { left, monthly, topup, resets_at }, channels: { connected, needs_reconnect, reconnect: [...] }, storage: { used_bytes, quota_bytes, percent_used }, next_steps }',
    rules: 'Read-only. limit null means no limit (left null); limits depend on the plan (on some plans: 3 channels, up to 7 days ahead, 10 queued posts, one approval client). connected counts channels that can be posted to now.',
    errors: [
      ['scope_missing', 'reconnect and allow "read"'],
      ['unauthorized', 'ask the user to reconnect the creatif.space connector'],
      ['upstream_unavailable', 'wait a minute and call again'],
    ],
  }),
  scope: 'read',
  input: {},
  annotations: READ_ONLY,
  run: async (_args, { v1 }) => {
    const [credits, accounts, usage] = await Promise.all([
      v1.get<{ data: { plan?: string; limits?: Limits } }>('/credits'),
      v1.get<{ data: V1Account[] }>('/social-accounts'),
      v1.get<{ data: Usage }>('/files/usage'),
    ]);
    const limits = credits.data.limits ?? {};

    const stale = accounts.data.filter((a) => a.reconnect_required === true || a.status !== 'connected');
    const channels = {
      total: accounts.data.length,
      connected: accounts.data.length - stale.length,
      needs_reconnect: stale.length,
      reconnect: stale.map((a) => ({ id: a.id, platform: a.platform, username: a.username, status: a.status })),
    };

    const u = usage.data;
    const free = 'used_bytes' in u;
    const used = free ? u.used_bytes : u.keep_used_bytes;
    const cap = free ? u.quota_bytes : u.keep_quota_bytes;
    const storage = {
      tier: u.tier,
      // Free counts the whole library; paid plans count kept files and media on drafts and scheduled posts.
      counts: free ? 'library' : 'kept',
      used_bytes: used,
      quota_bytes: cap,
      left_bytes: Math.max(0, cap - used),
      percent_used: cap > 0 ? Math.round((used / cap) * 1000) / 10 : null,
    };

    const c = limits.credits;
    const out = {
      plan: limits.plan ?? { key: credits.data.plan ?? 'unknown' },
      limits: {
        monthly_posts: quota(limits.monthly_posts),
        connected_accounts: quota(limits.connected_accounts),
        queued_posts: quota(limits.scheduling?.queued_posts),
        approval_clients: quota(limits.approval_clients),
        schedule_window_days: limits.scheduling?.window_days ?? null,
      },
      credits: c ? { left: c.total, monthly: c.monthly, topup: c.topup, resets_at: c.resets_at } : undefined,
      channels,
      storage,
    };

    const next: string[] = [];
    if (channels.total === 0) next.push('No channels yet: call get_connect_link with the network the user wants to connect first.');
    for (const a of channels.reconnect) next.push(`${a.platform} ${a.username ?? ''}`.trim() + ` needs reconnecting: get_connect_link { "network": "${a.platform}" }.`);
    if (out.limits.connected_accounts?.left === 0) next.push('The plan has no room for another channel: disconnect one first.');
    if (out.limits.queued_posts?.left === 0) next.push('The queue is full: wait for posts to go out before scheduling more.');
    if (c && c.total <= 0) next.push('No Credits left: sending waits for more Credits or the monthly reset (credits.resets_at). Drafts are still free.');
    if (storage.percent_used !== null && storage.percent_used >= 90) next.push('Media storage is 90% used or more: see get_storage_usage before uploading.');
    if (next.length === 0) next.push('Ready: list_channels for channel ids, then draft_post. The plan_week prompt plans a week of posts.');

    return { ...out, next_steps: next };
  },
});

export const accountSummaryTools = [getAccountSummary];

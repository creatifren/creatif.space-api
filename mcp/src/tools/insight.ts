import { z } from 'zod';
import { defineTool, describeTool } from './define.js';
import { pageInput, pageOf } from './paging.js';

const READ_ONLY = { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false } as const;

const month = z
  .string()
  .regex(/^\d{4}-(0[1-9]|1[0-2])$/, 'A month as YYYY-MM')
  .describe('A calendar month, YYYY-MM (e.g. 2026-10)');

/** {month} or {from, to}: the two period shapes the Insight report takes. */
const period = z
  .union([
    z.object({ month }).strict(),
    z
      .object({
        from: z.iso.date().describe('First day, YYYY-MM-DD'),
        to: z.iso.date().describe('Last day, YYYY-MM-DD (at most 92 days after from)'),
      })
      .strict(),
  ])
  .describe('Either { "month": "YYYY-MM" } or { "from": "YYYY-MM-DD", "to": "YYYY-MM-DD" } (at most 92 days, not in the future)');

export const getInsightSummary = defineTool({
  name: 'get_insight_summary',
  title: 'Get Insight numbers',
  description: describeTool({
    does: 'Gets the numbers of an Insight report (as in the PDF, without it) for a month or up to 92 days: posts, reach, impressions, engagement and its rate, followers, weekly reach, top posts (with post_id when made here) and the approval workflow.',
    when: 'when the user asks how their channels did over a period, or wants numbers for a client report. in_one_line is the period in one sentence (headline numbers with their change, and the best time to post from the user’s own posts): say it as it stands when one line is enough. Every figure (totals, each channel, the workflow) comes with the previous period of the same length (totals_previous, channels[].previous, workflow.previous, followers.previous_change) and change_pct, only when both periods have it. null means the network did not report that number — never read it as zero — and notes[] says why: pass those notes on. channels[].notes[] marks a metric the network holds back until the account is bigger ({ metric, reason: "below_threshold", threshold, source_url, text }): say it in those words, never as a drop to zero. channel_ids (from list_channels) limits it to some channels; by default all connected channels.',
    example: 'get_insight_summary { "period": { "month": "2026-10" } } → { period, totals: { posts: 36, reach: 64900, engagement_rate: 0.089 }, change_pct: { reach: 70.8 }, channels: [...], top_posts: [{ post_id, channel_id, network, engagement, url, … }], workflow: {...}, notes: ["Saves not available from Facebook API"] }',
    errors: [
      ['plan_required', 'Insight is not included on this plan: tell the user it is not available on their plan; do not retry'],
      ['invalid_input', 'fix the period (YYYY-MM, or from/to at most 92 days, not in the future) or the channel ids and call again'],
      ['scope_missing', 'reconnect and allow "read"'],
      ['unauthorized', 'ask the user to reconnect the creatif.space connector'],
      ['upstream_unavailable', 'wait a minute and call again'],
    ],
  }),
  scope: 'read',
  input: {
    period,
    channel_ids: z.array(z.string().trim().min(10).max(26)).min(1).max(50).optional().describe('Only these channels (ids from list_channels)'),
  },
  annotations: READ_ONLY,
  run: async ({ period, channel_ids }, { v1 }) => {
    const res = await v1.get<{ data: Record<string, unknown> }>('/insight/summary', {
      ...('month' in period ? { month: period.month } : { from: period.from, to: period.to }),
      channel_ids: channel_ids?.join(','),
    });
    // The best slot is a bonus: a failure there never costs the numbers.
    const best = await v1
      .get<{ data: BestTimesChannel[] }>('/insight/best-times', { channel_ids: channel_ids?.join(',') })
      .then((r) => r.data)
      .catch(() => null);
    return { in_one_line: oneLine(res.data as unknown as SummaryForLine, best), ...res.data };
  },
});

type SummaryForLine = {
  period?: { label?: string };
  compared_with?: { label?: string };
  totals?: Record<string, number | null>;
  change_pct?: Record<string, number | null>;
};
type BestTimesChannel = { network?: string; status?: string; slots?: { label: string; avg_engagement: number }[] };

const LINE_METRICS: [key: string, label: string][] = [
  ['reach', 'reach'],
  ['engagement', 'engagement'],
  ['posts', 'posts'],
];

/**
 * The period in one sentence an agent can say as it stands: each headline
 * number with its change against the previous period (only when the API
 * gave one), then the best slot from the user's own posts. Never a number
 * the API did not send.
 */
export function oneLine(s: SummaryForLine, best: BestTimesChannel[] | null): string {
  const n = (v: number) => v.toLocaleString('en-US');
  const parts = LINE_METRICS.flatMap(([key, label]) => {
    const value = s.totals?.[key];
    if (value === null || value === undefined) return [];
    const pct = s.change_pct?.[key];
    const change = pct === null || pct === undefined ? '' : pct === 0 ? ' (no change)' : ` (${pct > 0 ? '↑' : '↓'} ${Math.abs(pct)}%)`;
    return [`${label} ${n(value)}${change}`];
  });
  const vs = s.compared_with?.label ? ` vs ${s.compared_with.label}` : '';
  const head = parts.length > 0
    ? `${s.period?.label ?? 'This period'}${vs}: ${parts.join(', ')}.`
    : `${s.period?.label ?? 'This period'}: the networks reported no numbers yet.`;

  const slots = (best ?? [])
    .filter((c) => c.status === 'ok' && c.slots && c.slots.length > 0)
    .map((c) => ({ network: c.network ?? 'a channel', slot: c.slots![0]! }))
    .sort((a, b) => b.slot.avg_engagement - a.slot.avg_engagement);
  const tail = slots.length > 0 ? ` Best time to post, from your posts: ${slots[0]!.slot.label} on ${slots[0]!.network}.` : '';

  return head + tail;
}

export const listTopPosts = defineTool({
  name: 'list_top_posts',
  title: 'List top posts',
  description: describeTool({
    does: 'Lists the five posts with the most engagement (likes + comments + shares + saves, as each network reports them) in a month or up to 92 days, highest first, with each one’s numbers and link.',
    when: 'when the user asks which posts did best, or wants examples for a client report. post_id is set when the post went out through creatif.space (use get_post for details) and null for one posted elsewhere. A post whose network reports no engagement is left out, never ranked as 0. channel_ids (from list_channels) limits it to some channels; use get_insight_summary for totals instead.',
    example: 'list_top_posts { "period": { "month": "2026-10" } } → { data: [{ network: "TikTok", engagement: 4680, likes: 4100, url, caption, posted_at, post_id }, ...], next_cursor: null, meta: { ranked_by: "engagement", limit: 5 } }',
    errors: [
      ['plan_required', 'Insight is not included on this plan: tell the user it is not available on their plan; do not retry'],
      ['invalid_input', 'fix the period (YYYY-MM, or from/to at most 92 days, not in the future) or the channel ids and call again'],
      ['scope_missing', 'reconnect and allow "read"'],
      ['unauthorized', 'ask the user to reconnect the creatif.space connector'],
      ['upstream_unavailable', 'wait a minute and call again'],
    ],
  }),
  scope: 'read',
  input: {
    period,
    channel_ids: z.array(z.string().trim().min(10).max(26)).min(1).max(50).optional().describe('Only these channels (ids from list_channels)'),
    ...pageInput(5, 5, 'posts'),
  },
  annotations: READ_ONLY,
  run: async ({ period, channel_ids, limit, cursor }, { v1 }) => {
    const query = {
      ...('month' in period ? { month: period.month } : { from: period.from, to: period.to }),
      channel_ids: channel_ids?.join(','),
    };
    const res = await v1.get<{ data: unknown[]; meta: Record<string, unknown> }>('/insight/top-posts', query);
    const { page, next_cursor } = pageOf('list_top_posts', query, res.data, limit, cursor);
    return { data: page, next_cursor, meta: res.meta };
  },
});

export const getBestTimes = defineTool({
  name: 'get_best_times',
  title: 'Get best times to post',
  description: describeTool({
    does: 'Gets, for each channel, the three weekday + hour slots in which its own posts drew the most engagement on average over the last 7–92 days (default 90), in the workspace’s timezone.',
    when: 'when the user asks when to post. It is from their own posts only, never a benchmark: say "from your posts". A channel with fewer than 10 posts with engagement comes back as status "not_enough_data" with no slots: say so, never guess a time. channel_ids (from list_channels) limits it to some channels.',
    example: 'get_best_times { "days": 90 } → { data: [{ network: "Instagram", status: "ok", posts_analyzed: 24, slots: [{ label: "Tue 19:00", posts: 4, avg_engagement: 412.5 }, ...] }], meta: { basis: "from your posts", timezone: "Asia/Jakarta" } }',
    errors: [
      ['plan_required', 'Insight is not included on this plan: tell the user it is not available on their plan; do not retry'],
      ['invalid_input', 'days must be 7–92; fix the channel ids and call again'],
      ['scope_missing', 'reconnect and allow "read"'],
      ['unauthorized', 'ask the user to reconnect the creatif.space connector'],
      ['upstream_unavailable', 'wait a minute and call again'],
    ],
  }),
  scope: 'read',
  input: {
    days: z.number().int().min(7).max(92).optional().describe('How many days back to look, 7–92 (default 90)'),
    channel_ids: z.array(z.string().trim().min(10).max(26)).min(1).max(50).optional().describe('Only these channels (ids from list_channels)'),
  },
  annotations: READ_ONLY,
  run: async ({ days, channel_ids }, { v1 }) => {
    const res = await v1.get<{ data: unknown[]; meta: Record<string, unknown> }>('/insight/best-times', {
      days,
      channel_ids: channel_ids?.join(','),
    });
    return { data: res.data, meta: res.meta };
  },
});

export const insightTools = [getInsightSummary, listTopPosts, getBestTimes];

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
    does: 'Gets the numbers of an Insight report (as in the PDF, without it) for a month or up to 92 days: posts, reach, impressions, engagement and its rate, followers, weekly reach, top posts and the approval workflow.',
    when: 'when the user asks how their channels did over a period, or wants numbers for a client report. Every figure (totals, each channel, the workflow) comes with the previous period of the same length (totals_previous, channels[].previous, workflow.previous, followers.previous_change) and change_pct, only when both periods have it. null means the network did not report that number — never read it as zero — and notes[] says why: pass those notes on. channels[].notes[] marks a metric the network holds back until the account is bigger ({ metric, reason: "below_threshold", threshold, source_url, text }): say it in those words, never as a drop to zero. channel_ids (from list_channels) limits it to some channels; by default all connected channels.',
    example: 'get_insight_summary { "period": { "month": "2026-10" } } → { period, totals: { posts: 36, reach: 64900, engagement_rate: 0.089 }, change_pct: { reach: 70.8 }, channels: [...], top_posts: [...], workflow: {...}, notes: ["Saves not available from Facebook API"] }',
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
    return res.data;
  },
});

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

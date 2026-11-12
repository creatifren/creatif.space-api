import { z } from 'zod';
import { defineTool, describeTool } from './define.js';

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
      ['plan_required', 'Insight is on Pro and Max: tell the user the message as it is (it has the upgrade link); do not retry'],
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

export const insightTools = [getInsightSummary];

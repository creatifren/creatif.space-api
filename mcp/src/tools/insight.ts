import { z } from 'zod';
import { defineTool } from './define.js';

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
  description:
    'Gets the numbers of a creatif.space Insight report (the same ones the PDF report prints, without the PDF) for a month or a date range of up to 92 days: ' +
    'per channel and in total — posts published, reach, impressions, engagement (likes + comments + shares + saves), engagement rate (engagement ÷ reach), follower change; ' +
    'reach per week; the top posts by engagement; and the workflow (posts approved, average approval time, share published on time). ' +
    'Each figure is compared with the previous period of the same length (change_pct), only when both periods have it. ' +
    'Numbers come from each network’s API; null means the network did not report that number (never read it as zero) and notes[] says why — pass those notes on. ' +
    'Insight is on Pro and Max: on Free this returns a plan_required error whose message (with the upgrade link) should be told to the user as it is. ' +
    'channel_ids (from list_channels) limits it to some channels; by default all connected channels.',
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

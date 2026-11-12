import { z } from 'zod';
import { defineTool, describeTool } from './define.js';

/** Reads only: making a link changes nothing in the workspace. */
const READ_ONLY = { readOnlyHint: true, destructiveHint: false, idempotentHint: false, openWorldHint: false } as const;

const day = (what: string) => z.iso.date().describe(`${what}, YYYY-MM-DD (whole days in the app's timezone)`);

export const getInsightReportLink = defineTool({
  name: 'get_insight_report_link',
  title: 'Get an Insight PDF link',
  description: describeTool({
    does: 'Makes a short-lived (15 minute) signed download link to the Insight PDF report for a date range of up to 92 days: one page, a 16:9 deck, or the detailed report.',
    when: 'when the user wants the report as a file to open, download or forward. The link works without signing in until it expires, so hand it to the user rather than posting it anywhere public. For the numbers themselves use get_insight_summary. channel_ids (from list_channels) limits the report to some channels; by default all connected channels.',
    example: 'get_insight_report_link { "from": "2026-10-01", "to": "2026-10-31", "format": "deck" } → { url: "https://api.creatif.space/api/v1/insight/export.pdf/signed?…", expires_at, file_name: "studio-2026-10-deck.pdf" }',
    errors: [
      ['plan_required', 'Insight is not included on this plan: tell the user it is not available on their plan; do not retry'],
      ['invalid_input', 'fix the dates (at most 92 days, not in the future), the format or the channel ids and call again'],
      ['scope_missing', 'reconnect and allow "read"'],
      ['unauthorized', 'ask the user to reconnect the creatif.space connector'],
      ['upstream_unavailable', 'wait a minute and call again'],
    ],
  }),
  scope: 'read',
  input: {
    from: day('First day'),
    to: day('Last day'),
    format: z.enum(['onepage', 'deck', 'details']).default('onepage').describe('onepage (A4, one page), deck (16:9 slides) or details (A4, a page per channel)'),
    channel_ids: z.array(z.string().trim().min(10).max(26)).min(1).max(50).optional().describe('Only these channels (ids from list_channels)'),
    locale: z.enum(['en', 'id']).optional().describe('Language of the report; default the user’s own'),
  },
  annotations: READ_ONLY,
  run: async ({ from, to, format, channel_ids, locale }, { v1 }) => {
    const res = await v1.post<{ url: string; expires_at: string; file_name: string }>('/insight/export-link', {
      from,
      to,
      format,
      ...(channel_ids ? { channels: channel_ids.join(',') } : {}),
      ...(locale ? { locale } : {}),
    });
    return {
      url: res.url,
      expires_at: res.expires_at,
      file_name: res.file_name,
      note: 'The link downloads the PDF without signing in and stops working at expires_at. Give it to the user; do not post it publicly.',
    };
  },
});

export const getInsightCsvLink = defineTool({
  name: 'get_insight_csv_link',
  title: 'Get an Insight CSV link',
  description: describeTool({
    does: 'Makes a signed download link (24 hours) to the Insight CSV: one row per published post in a date range of up to 366 days, with date, platform, channel, link and the six metrics.',
    when: 'when the user wants the raw post numbers in a spreadsheet. The link works without signing in until it expires, so hand it to the user rather than posting it anywhere public. For a formatted report use get_insight_report_link; for totals get_insight_summary. channel_ids (from list_channels) limits it to some channels; by default all connected channels.',
    example: 'get_insight_csv_link { "from": "2026-10-01", "to": "2026-10-31" } → { url: "https://api.creatif.space/api/v1/insight/export.csv/signed?…", expires_at, file_name: "insight-2026-10-01-to-2026-10-31.csv", columns: ["date", ...] }',
    errors: [
      ['plan_required', 'Insight is not included on this plan: tell the user it is not available on their plan; do not retry'],
      ['invalid_input', 'fix the dates (to on or after from, at most 366 days) or the channel ids and call again'],
      ['scope_missing', 'reconnect and allow "read"'],
      ['unauthorized', 'ask the user to reconnect the creatif.space connector'],
      ['upstream_unavailable', 'wait a minute and call again'],
    ],
  }),
  scope: 'read',
  input: {
    from: day('First day'),
    to: day('Last day'),
    channel_ids: z.array(z.string().trim().min(10).max(26)).min(1).max(50).optional().describe('Only these channels (ids from list_channels)'),
  },
  annotations: READ_ONLY,
  run: async ({ from, to, channel_ids }, { v1 }) => {
    const res = await v1.post<{ url: string; expires_at: string; file_name: string; columns: string[] }>('/insight/export-csv-link', {
      from,
      to,
      ...(channel_ids ? { channels: channel_ids.join(',') } : {}),
    });
    return {
      url: res.url,
      expires_at: res.expires_at,
      file_name: res.file_name,
      columns: res.columns,
      note: 'The link downloads the CSV without signing in and stops working at expires_at. Give it to the user; do not post it publicly.',
    };
  },
});

export const insightReportTools = [getInsightReportLink, getInsightCsvLink];

import { z } from 'zod';
import { defineTool, describeTool } from './define.js';
import { pageInput, pageOf } from './paging.js';

/**
 * Monthly client reports (v7): one report per client per month, built from
 * Insight for the client's channels, shared by link (Link only by default)
 * and emailed to the client's approver on the user's schedule.
 */

const ulid = (what: string) =>
  z.string().trim().min(10).max(26).describe(`${what} id, as returned by the list tools`);

const READ_ONLY = { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false } as const;

type Obj = Record<string, unknown>;

export const getReportSettings = defineTool({
  name: 'get_report_settings',
  title: 'Get Report emails settings',
  description: describeTool({
    does: 'Shows whether monthly reports are emailed to clients automatically, on which day and hour (the user’s timezone), the sender name, and which clients get one.',
    when: 'when the user asks when client reports go out, or before changing that with update_report_settings.',
    example: 'get_report_settings {} → { enabled: true, send_day: 1, send_time: "09:00", timezone, from_name, clients: [{ id, name, approver_email, report_email }] }',
    rules: 'Read-only.',
    errors: [['upstream_unavailable', 'wait a minute and call again']],
  }),
  scope: 'read',
  input: {},
  annotations: READ_ONLY,
  run: async (_args, { v1 }) => (await v1.get<{ data: Obj }>('/me/report-settings')).data,
});

export const updateReportSettings = defineTool({
  name: 'update_report_settings',
  title: 'Change Report emails settings',
  description: describeTool({
    does: 'Turns automatic monthly client report emails on or off, and sets the day, hour, sender name and which clients get one.',
    when: 'only when the user asks to change how or when their clients get the monthly report.',
    example: 'update_report_settings { "enabled": true, "send_day": 1, "send_time": "09:00", "clients": [{ "client_id": "01JK…", "report_email": false }] } → { enabled, send_day, … }',
    rules: 'Turning it on means each client with an approver email gets last month’s report by email on that day: confirm with the user first. send_day is 1–28; send_time is a whole hour (HH:00) in the user’s timezone.',
    errors: [
      ['invalid_input', 'fix what details.errors names (send_day 1–28, send_time HH:00)'],
      ['scope_missing', 'reconnect and allow "draft"'],
    ],
  }),
  scope: 'draft',
  input: {
    enabled: z.boolean().optional().describe('Email reports to clients automatically'),
    send_day: z.number().int().min(1).max(28).optional().describe('Day of the month, 1–28'),
    send_time: z.string().regex(/^([01]\d|2[0-3]):00$/).optional().describe('Whole hour, e.g. "09:00", in the user’s timezone'),
    from_name: z.string().trim().max(120).nullable().optional().describe('Sender name clients see; null = the user’s name'),
    clients: z.array(z.object({ client_id: ulid('Client'), report_email: z.boolean() })).max(200).optional().describe('Per client: send the report or not'),
  },
  annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
  run: async (args, { v1 }) => {
    const body = {
      ...(args.enabled !== undefined ? { enabled: args.enabled } : {}),
      ...(args.send_day !== undefined ? { send_day: args.send_day } : {}),
      ...(args.send_time !== undefined ? { send_time: args.send_time } : {}),
      ...(args.from_name !== undefined ? { from_name: args.from_name } : {}),
      ...(args.clients ? { clients: args.clients.map((c) => ({ id: c.client_id, report_email: c.report_email })) } : {}),
    };
    return (await v1.patch<{ data: Obj }>('/me/report-settings', body)).data;
  },
});

export const listClientReports = defineTool({
  name: 'list_client_reports',
  title: 'List client reports',
  description: describeTool({
    does: 'Lists this month’s monthly reports per client (scheduled or not, and to whom) and the reports already sent, with how often each client opened theirs.',
    when: 'when the user asks about their clients’ monthly reports, which went out, or whether a client opened one.',
    example: 'list_client_reports {} → { automatic: true, send_on: "1st of the month, 09:00", scheduled: [{ client, month, to, will_send }], sent: [{ id, client, month, sent_at, open_count, url }] }',
    rules: 'Read-only. To open one report use get_client_report.',
    errors: [['upstream_unavailable', 'wait a minute and call again']],
  }),
  scope: 'read',
  input: { ...pageInput(50, 50, 'sent reports') },
  annotations: READ_ONLY,
  run: async (args, { v1 }) => {
    const { data } = await v1.get<{ data: { automatic: boolean; send_on: string; scheduled: Obj[]; sent: Obj[] } }>('/client-reports');
    const { page, next_cursor } = pageOf('list_client_reports', {}, data.sent, args.limit, args.cursor);
    return { automatic: data.automatic, send_on: data.send_on, scheduled: data.scheduled, sent: page, next_cursor };
  },
});

export const getClientReport = defineTool({
  name: 'get_client_report',
  title: 'Get a client’s monthly report',
  description: describeTool({
    does: 'Opens one client’s monthly report: accounts, KPIs vs the month before, weekly reach, per account, top posts, content types, the summary and the share link; makes it if it does not exist yet.',
    when: 'when the user wants to see, check or share a client’s report for a month — the monthly_report prompt.',
    example: 'get_client_report { "client_id": "01JK…", "month": "2026-10" } → { id, period, client, accounts, kpis, weekly_reach, by_account, top_posts, content_types, summary, url, visibility }',
    rules: 'month is YYYY-MM, not in the future. The share link is Link only unless the user made it public. The summary is a draft the user can change with update_client_report.',
    errors: [
      ['invalid_input', 'month must be YYYY-MM and not in the future'],
      ['plan_required (402)', 'reports are not on the user’s plan: tell them, do not retry'],
    ],
  }),
  scope: 'read',
  input: {
    client_id: ulid('Client'),
    month: z.string().regex(/^\d{4}-\d{2}$/).describe('The month, YYYY-MM, e.g. "2026-10"'),
  },
  annotations: { ...READ_ONLY, idempotentHint: true },
  notFound: { what: 'That client', next: 'Call list_clients for the user’s client ids, then call again.' },
  run: async (args, { v1 }) => (await v1.post<{ data: Obj }>('/client-reports', { client_id: args.client_id, month: args.month })).data,
});

export const updateClientReport = defineTool({
  name: 'update_client_report',
  title: 'Edit a client report',
  description: describeTool({
    does: 'Changes a client report’s summary text or who can see it (link, public on the user’s profile, or private).',
    when: 'when the user rewrites the report summary or asks to make a report public or private.',
    example: 'update_client_report { "report_id": "01JR…", "summary": "A strong month: reach up 18%…" } → { report }',
    rules: 'Only the fields given change. Make it public only if the user asks: public reports are listed on their profile.',
    errors: [
      ['invalid_input', 'summary at most 3000 characters; visibility link | public | private'],
      ['scope_missing', 'reconnect and allow "draft"'],
    ],
  }),
  scope: 'draft',
  input: {
    report_id: ulid('Report'),
    summary: z.string().trim().max(3000).nullable().optional().describe('The new summary; null = write a fresh draft'),
    visibility: z.enum(['link', 'public', 'private']).optional().describe('link (default), public (on the profile) or private (link off)'),
  },
  annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
  notFound: { what: 'That report', next: 'Call list_client_reports or get_client_report for the report id.' },
  run: async (args, { v1 }) => {
    const body = {
      ...(args.summary !== undefined ? { summary: args.summary } : {}),
      ...(args.visibility ? { visibility: args.visibility } : {}),
    };
    return { report: (await v1.patch<{ data: Obj }>(`/client-reports/${encodeURIComponent(args.report_id)}`, body)).data };
  },
});

export const sendClientReport = defineTool({
  name: 'send_client_report',
  title: 'Email a client report now',
  description: describeTool({
    does: 'Emails a client’s monthly report link to that client’s approver now.',
    when: 'only when the user asks to send a report to a client right away.',
    example: 'send_client_report { "report_id": "01JR…" } → { sent_at, sent_to }',
    rules: 'It reaches the client: confirm with the user first. The client needs an approver email (update_client).',
    errors: [
      ['invalid_input', 'the client has no approver email: set one with update_client, then call again'],
      ['scope_missing', 'reconnect and allow "approve"'],
    ],
  }),
  scope: 'approve',
  input: { report_id: ulid('Report') },
  annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true },
  notFound: { what: 'That report', next: 'Call list_client_reports or get_client_report for the report id.' },
  run: async (args, { v1 }) => {
    const { data } = await v1.post<{ data: Obj }>(`/client-reports/${encodeURIComponent(args.report_id)}/send`, {});
    return { sent_at: data.sent_at, sent_to: data.sent_to, url: data.url };
  },
});

export const clientReportTools = [getReportSettings, updateReportSettings, listClientReports, getClientReport, updateClientReport, sendClientReport];

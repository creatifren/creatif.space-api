import { z } from 'zod';
import { ToolError } from '../errors.js';
import { V1Error } from '../v1.js';
import { defineTool, describeTool } from './define.js';

/**
 * A Content Plan's calendar feed (ICS): GET /social-approval-links/{id}/calendar.
 * Read-only: every Content Plan has a feed, on by default, at a stable signed
 * URL. Turning it off or giving it a new URL is done in creatif.space.
 */
export const getPlanCalendarLink = defineTool({
  name: 'get_plan_calendar_link',
  title: 'Get a Content Plan calendar feed link',
  description: describeTool({
    does: 'Returns the calendar feed URL (ICS) of one of the user’s Content Plans: every scheduled post in the plan’s dates and channels, titled with the first 60 characters of its caption, for Google Calendar, Apple Calendar or Outlook.',
    when: 'when the user wants the plan in their (or their client’s) calendar app. To share the plan for approval, use create_approval_link; to see what was decided, use get_approval_audit.',
    example: 'get_plan_calendar_link { "link_id": "01JD…" } → { enabled: true, url, webcal_url }',
    rules: 'Read-only: the URL is the same each time. Content Plans only (a link made with date_from/date_to). webcal_url opens the calendar app directly. Anyone with the URL can read post titles and times: hand it only to people the user chose. If the feed was turned off, enabled is false; the user turns it back on in creatif.space.',
    errors: [
      ['not_a_plan', 'this link is a fixed list of posts, not a Content Plan: tell the user, or create a Content Plan with create_approval_link'],
      ['link_inactive', 'the plan is revoked or expired (details.status): tell the user'],
      ['not_found', 'check the link id with check_approval_status'],
      ['scope_missing', 'reconnect and allow "read"'],
    ],
  }),
  scope: 'read',
  input: {
    link_id: z.string().trim().min(10).max(26).describe('Content Plan link id (create_approval_link returns it as link.id)'),
  },
  annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
  notFound: { what: 'An approval link in this workspace', next: 'Check the link id with check_approval_status (create_approval_link returned it as link.id) and call again.' },
  run: async (args, { v1 }) => {
    try {
      const { data } = await v1.get<{ data: { link_id: string; enabled: boolean; url: string | null; webcal_url: string | null } }>(
        `/social-approval-links/${encodeURIComponent(args.link_id)}/calendar`,
      );
      return data.enabled
        ? { link_id: data.link_id, enabled: true, url: data.url, webcal_url: data.webcal_url, note: 'Give the user this URL to subscribe in their calendar app.' }
        : { link_id: data.link_id, enabled: false, note: 'The calendar feed of this plan is turned off. The user can turn it back on in creatif.space (Content Plan → Calendar feed).' };
    } catch (e) {
      if (e instanceof V1Error && e.status === 422 && e.body?.reason === 'not_a_plan') {
        throw new ToolError('not_a_plan', 'This link is a fixed list of posts, not a Content Plan, so it has no calendar feed.', 'Tell the user. For a calendar, create a Content Plan (create_approval_link with date_from and date_to).');
      }
      if (e instanceof V1Error && e.status === 409 && typeof e.body?.reason === 'string' && e.body.reason.startsWith('link_')) {
        const status = e.body.reason.slice(5);
        throw new ToolError('link_inactive', `This Content Plan is ${status}, so it has no calendar feed.`, 'Tell the user; a new Content Plan (create_approval_link) has its own feed.', { status });
      }
      throw e;
    }
  },
});

export const calendarTools = [getPlanCalendarLink];

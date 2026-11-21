import { z } from 'zod';
import { ToolError } from '../errors.js';
import { defineTool, describeTool } from './define.js';

/**
 * The user's notification switches (GET / PATCH /me/notification-settings),
 * prototype v10.8 (21 Nov 2026): the in-app bell gets everything (no
 * switches); email only what needs action (problems_email, default on;
 * billing always on); push only the important ones (push master switch +
 * five push_events, default on). Same switches as creatif.space →
 * Settings → Notifications.
 */

const EVENTS = ['approved', 'changes', 'post_problem', 'channel', 'credits'] as const;
type PushEvent = (typeof EVENTS)[number];

interface Settings {
  problems_email: boolean;
  /** Older name of problems_email. */
  publish_failed?: boolean;
  push: boolean;
  push_events: Record<PushEvent, boolean>;
  timezone: string;
}

const shape = (s: Settings) => ({
  problems_email: s.problems_email ?? s.publish_failed ?? true,
  push: s.push ?? true,
  push_events: s.push_events,
  timezone: s.timezone,
});

const MEANING = {
  problems_email: 'the one email switch, "Problems that need you": a post failed, missed its time or was held, a channel needs attention, Credits or storage running low, kept files about to be deleted',
  push: 'push notifications on the user’s phones (Android app), master switch',
  'push_events.approved': 'push when a client approves',
  'push_events.changes': 'push when a client asks for changes',
  'push_events.post_problem': 'push when a post failed, missed its time or was held',
  'push_events.channel': 'push when a channel needs attention (sign-in expired)',
  'push_events.credits': 'push when Credits run low or Auto DM pauses',
  timezone: 'the user’s timezone for scheduled notices',
} as const;

const RULES = 'The in-app bell always gets everything and has no switches. Account emails that need action cannot be turned off. There are no digest or per-approval emails.';

export const getNotificationSettings = defineTool({
  name: 'get_notification_settings',
  title: 'Get the user’s notification settings',
  description: describeTool({
    does: 'Returns the user’s notification switches: problems_email (the only email switch), push (phone push, master) and push_events (approved, changes, post_problem, channel, credits). true = on; all default on.',
    when: 'when the user asks which notifications or emails they get, or before changing one with update_notification_settings.',
    example: 'get_notification_settings {} → { settings: { problems_email: true, push: true, push_events: { approved: true, changes: true, post_problem: true, channel: true, credits: false }, timezone: "Asia/Jakarta" }, meaning }',
    rules: `Read-only. ${RULES}`,
    errors: [
      ['scope_missing', 'reconnect and allow "read"'],
      ['unauthorized', 'reconnect creatif.space'],
      ['upstream_unavailable', 'call again in a minute'],
    ],
  }),
  scope: 'read',
  input: {},
  annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
  run: async (_args, { v1 }) => {
    const { data } = await v1.get<{ data: Settings }>('/me/notification-settings');
    return { settings: shape(data), meaning: MEANING };
  },
});

export const updateNotificationSettings = defineTool({
  name: 'update_notification_settings',
  title: 'Turn creatif.space emails or push notifications on or off',
  description: describeTool({
    does: 'Turns the "Problems that need you" email (problems_email), phone push (push) or one push event on or off, or sets the timezone, and returns all settings.',
    when: 'when the user asks to stop or start an email or a push (e.g. "stop the problem emails", "don’t push me when a client approves"). To only see them, use get_notification_settings.',
    example: 'update_notification_settings { "push_events": { "approved": false } } → { settings: { problems_email: true, push: true, push_events: { approved: false, … } } }',
    rules: `Give at least one switch as true or false. Only change what the user asked for. ${RULES}`,
    errors: [
      ['invalid_input', 'pass at least one of problems_email, push, push_events.<event> as true/false, or a valid IANA timezone'],
      ['scope_missing', 'reconnect and allow "draft"'],
      ['unauthorized', 'reconnect creatif.space'],
      ['upstream_unavailable', 'call again in a minute'],
    ],
  }),
  scope: 'draft',
  input: {
    problems_email: z.boolean().optional().describe('The "Problems that need you" email: failed / missed / held posts, channel problems, Credits or storage low, kept files about to go'),
    push: z.boolean().optional().describe('Push notifications on the user’s phones (master switch)'),
    push_events: z
      .object(Object.fromEntries(EVENTS.map((e) => [e, z.boolean().optional()])) as Record<PushEvent, z.ZodOptional<z.ZodBoolean>>)
      .partial()
      .optional()
      .describe('One or more push events: approved, changes, post_problem, channel, credits'),
    timezone: z.string().trim().min(1).max(64).nullable().optional().describe('IANA timezone, e.g. "Europe/London"; null resets it. Omit it unless the user asked to change the timezone'),
  },
  annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
  run: async (args, { v1 }) => {
    const body: Record<string, unknown> = {};
    if (typeof args.problems_email === 'boolean') body.problems_email = args.problems_email;
    if (typeof args.push === 'boolean') body.push = args.push;
    const events = Object.fromEntries(Object.entries(args.push_events ?? {}).filter(([, v]) => typeof v === 'boolean'));
    if (Object.keys(events).length) body.push_events = events;
    if (args.timezone !== undefined) body.timezone = args.timezone;
    if (Object.keys(body).length === 0) {
      throw new ToolError(
        'invalid_input',
        'Nothing to change: give problems_email, push or a push_events entry as true or false, or a timezone.',
        'Ask the user which email or push to turn on or off, then call again with that one.',
      );
    }
    const { data } = await v1.patch<{ data: Settings }>('/me/notification-settings', body);
    return { settings: shape(data), note: 'Saved. The in-app bell is unchanged (it always gets everything).' };
  },
});

export const notificationTools = [getNotificationSettings, updateNotificationSettings];

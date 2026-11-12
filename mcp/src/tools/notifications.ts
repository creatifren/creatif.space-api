import { z } from 'zod';
import { ToolError } from '../errors.js';
import { defineTool, describeTool } from './define.js';

/**
 * The user's three email switches (GET / PATCH /me/notification-settings):
 * publish_failed, approval_decided, weekly_digest. Default on. The same
 * switches as creatif.space → Settings → Notifications; the in-app bell
 * is not changed here.
 */

interface Settings {
  publish_failed: boolean;
  approval_decided: boolean;
  weekly_digest: boolean;
}

const MEANING = {
  publish_failed: 'an email when a post fails to publish',
  approval_decided: 'an email when a client approves or asks for changes',
  weekly_digest: 'a Monday 08:00 summary of last week (published, waiting approvals, failures)',
} as const;

export const getNotificationSettings = defineTool({
  name: 'get_notification_settings',
  title: 'Get the user’s email notification settings',
  description: describeTool({
    does: 'Returns which creatif.space emails the user gets: publish_failed, approval_decided and weekly_digest (true = on; all default on).',
    when: 'when the user asks which emails they get, or before changing one with update_notification_settings.',
    example: 'get_notification_settings {} → { settings: { publish_failed: true, approval_decided: true, weekly_digest: false }, meaning }',
    rules: 'Read-only. Email only: the in-app bell is not part of these. Billing and storage emails cannot be turned off and are not listed.',
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
    return { settings: data, meaning: MEANING };
  },
});

export const updateNotificationSettings = defineTool({
  name: 'update_notification_settings',
  title: 'Turn creatif.space emails on or off',
  description: describeTool({
    does: 'Turns the user’s publish_failed, approval_decided or weekly_digest email on or off and returns all three settings.',
    when: 'when the user asks to stop or start one of these emails (e.g. "stop the weekly summary"). To only see them, use get_notification_settings.',
    example: 'update_notification_settings { "weekly_digest": false } → { settings: { publish_failed: true, approval_decided: true, weekly_digest: false } }',
    rules: 'Give at least one of the three as true or false; the others stay as they are. Only change what the user asked for. Email only: the in-app bell stays. Billing and storage emails cannot be turned off.',
    errors: [
      ['invalid_input', 'pass at least one of publish_failed, approval_decided, weekly_digest as true/false'],
      ['scope_missing', 'reconnect and allow "draft"'],
      ['unauthorized', 'reconnect creatif.space'],
      ['upstream_unavailable', 'call again in a minute'],
    ],
  }),
  scope: 'draft',
  input: {
    publish_failed: z.boolean().optional().describe('Email when a post fails to publish'),
    approval_decided: z.boolean().optional().describe('Email when a client approves or asks for changes'),
    weekly_digest: z.boolean().optional().describe('The Monday 08:00 weekly summary email'),
  },
  annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
  run: async (args, { v1 }) => {
    const body = Object.fromEntries(Object.entries(args).filter(([, v]) => typeof v === 'boolean'));
    if (Object.keys(body).length === 0) {
      throw new ToolError(
        'invalid_input',
        'Nothing to change: give publish_failed, approval_decided or weekly_digest as true or false.',
        'Ask the user which email to turn on or off, then call again with that one.',
      );
    }
    const { data } = await v1.patch<{ data: Settings }>('/me/notification-settings', body);
    return { settings: data, note: 'Saved. Email only: the in-app notifications are unchanged.' };
  },
});

export const notificationTools = [getNotificationSettings, updateNotificationSettings];

import { z } from 'zod';
import { ToolError } from '../errors.js';
import { defineTool, describeTool } from './define.js';

/**
 * The user's email switches (GET / PATCH /me/notification-settings):
 * publish_failed, approval_decided, weekly_digest. Default on. The same
 * switches as creatif.space → Settings → Notifications; the in-app bell
 * is not changed here.
 *
 * "Post missed / failed" (post_missed_or_failed): since API #199 the
 * publish_failed switch also covers a scheduled post that missed its time.
 * Feature-detected: if the API serves its own post_missed_or_failed key,
 * that key is read and written; otherwise the setting is publish_failed.
 */

interface Settings {
  publish_failed: boolean;
  approval_decided: boolean;
  weekly_digest: boolean;
  /** Present only when the API has its own switch for it. */
  post_missed_or_failed?: boolean;
  /** Where the weekly digest's Monday 08:00 is (IANA). */
  timezone: string;
}

/** The API's own key for "Post missed / failed", when it has one. */
export const MISSED_KEY = 'post_missed_or_failed';

/** Settings as the model sees them: post_missed_or_failed always present. */
const shape = (s: Settings) => ({ ...s, post_missed_or_failed: s.post_missed_or_failed ?? s.publish_failed });

const MEANING = {
  post_missed_or_failed: 'an email when a scheduled post misses its time or fails to publish (one per post)',
  publish_failed: 'the same switch as post_missed_or_failed on this server: an email when a post misses its time or fails to publish',
  approval_decided: 'an email when a client approves or asks for changes',
  weekly_digest: 'a Monday 08:00 summary of last week (published, waiting approvals, failures)',
  timezone: 'the zone the weekly digest’s Monday 08:00 is in',
} as const;

export const getNotificationSettings = defineTool({
  name: 'get_notification_settings',
  title: 'Get the user’s email notification settings',
  description: describeTool({
    does: 'Returns which creatif.space emails the user gets: post_missed_or_failed (“Post missed / failed”), approval_decided and weekly_digest (true = on; all default on).',
    when: 'when the user asks which emails they get, or before changing one with update_notification_settings.',
    example: 'get_notification_settings {} → { settings: { post_missed_or_failed: true, publish_failed: true, approval_decided: true, weekly_digest: false, timezone: "Asia/Jakarta" }, meaning }',
    rules: 'Read-only. Email only: the in-app bell is not part of these. Account and storage emails cannot be turned off and are not listed.',
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
  title: 'Turn creatif.space emails on or off',
  description: describeTool({
    does: 'Turns the user’s post_missed_or_failed (“Post missed / failed”), approval_decided or weekly_digest email on or off, or sets the weekly digest’s timezone, and returns all settings.',
    when: 'when the user asks to stop or start one of these emails (e.g. "stop the weekly summary", "tell me when a post misses its time"). To only see them, use get_notification_settings.',
    example: 'update_notification_settings { "post_missed_or_failed": true } → { settings: { post_missed_or_failed: true, publish_failed: true, approval_decided: true, weekly_digest: false } }',
    rules: 'Give at least one switch as true or false, or timezone (IANA, e.g. "Asia/Jakarta"; null resets it); the rest stay as they are. post_missed_or_failed and publish_failed are one switch here: give one of them. Only change what the user asked for. Email only: the in-app bell stays. Account and storage emails cannot be turned off.',
    errors: [
      ['invalid_input', 'pass at least one of post_missed_or_failed, approval_decided, weekly_digest as true/false, or a valid IANA timezone'],
      ['scope_missing', 'reconnect and allow "draft"'],
      ['unauthorized', 'reconnect creatif.space'],
      ['upstream_unavailable', 'call again in a minute'],
    ],
  }),
  scope: 'draft',
  input: {
    post_missed_or_failed: z.boolean().optional().describe('“Post missed / failed”: email when a scheduled post misses its time or fails to publish'),
    publish_failed: z.boolean().optional().describe('The same switch as post_missed_or_failed (older name)'),
    approval_decided: z.boolean().optional().describe('Email when a client approves or asks for changes'),
    weekly_digest: z.boolean().optional().describe('The Monday 08:00 weekly summary email'),
    timezone: z.string().trim().min(1).max(64).nullable().optional().describe('IANA timezone for the weekly digest, e.g. "Asia/Jakarta"; null resets it'),
  },
  annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
  run: async (args, { v1 }) => {
    const { post_missed_or_failed: missed, ...rest } = args;
    if (missed !== undefined && rest.publish_failed !== undefined && missed !== rest.publish_failed) {
      throw new ToolError(
        'invalid_input',
        'post_missed_or_failed and publish_failed are the same switch, and were given different values.',
        'Ask the user whether they want the “Post missed / failed” email on or off, then call again with post_missed_or_failed only.',
        { field: 'post_missed_or_failed' },
      );
    }
    const body: Record<string, unknown> = Object.fromEntries(Object.entries(rest).filter(([k, v]) => typeof v === 'boolean' || (k === 'timezone' && v !== undefined)));
    if (missed !== undefined) {
      // Feature-detect: the API's own key if it serves one, else the publish_failed switch.
      const { data: now } = await v1.get<{ data: Settings }>('/me/notification-settings');
      if (MISSED_KEY in now) {
        body[MISSED_KEY] = missed;
        delete body.publish_failed;
      } else {
        body.publish_failed = missed;
      }
    }
    if (Object.keys(body).length === 0) {
      throw new ToolError(
        'invalid_input',
        'Nothing to change: give post_missed_or_failed, approval_decided or weekly_digest as true or false, or a timezone.',
        'Ask the user which email to turn on or off, then call again with that one.',
      );
    }
    const { data } = await v1.patch<{ data: Settings }>('/me/notification-settings', body);
    return { settings: shape(data), note: 'Saved. Email only: the in-app notifications are unchanged.' };
  },
});

export const notificationTools = [getNotificationSettings, updateNotificationSettings];

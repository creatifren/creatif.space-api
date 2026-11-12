import { z } from 'zod';
import { defineTool, describeTool } from './define.js';

/**
 * Tester feedback to the creatif.space team (POST /feedback). Nothing is
 * published and no Credits are spent: it is stored and emailed to the
 * team, who can reply to the user's email.
 */
export const sendFeedback = defineTool({
  name: 'send_feedback',
  title: 'Send feedback to the creatif.space team',
  description: describeTool({
    does: 'Sends the user’s feedback (a bug, an idea or anything else) to the creatif.space team. Nothing is published and no Credits are spent; the team can reply to the user’s email.',
    when: 'when the user wants to report a problem with creatif.space, suggest a feature, or tell the team something. Not for messages to the user’s own audience: use reply_to_conversation for that.',
    example: 'send_feedback { "kind": "bug", "message": "The calendar shows my post a day late", "page": "Content Plan → Calendar" } → { sent: true, id }',
    rules: 'Send only what the user asked you to send, in their words; do not add private data they did not mention. At most 10 a hour per user.',
    errors: [
      ['rate_limited', 'the user sent 10 in the last hour: wait details.retry_after_seconds, do not loop'],
      ['invalid_input', 'fix what details.errors names (message 2–5000 characters, kind bug | idea | other)'],
      ['scope_missing', 'reconnect and allow "draft"'],
      ['upstream_unavailable', 'it may have been sent: tell the user, do not resend automatically'],
    ],
  }),
  scope: 'draft',
  input: {
    kind: z.enum(['bug', 'idea', 'other']).describe('"bug", "idea" or "other"'),
    message: z.string().trim().min(2).max(5000).describe('The feedback, in the user’s words'),
    page: z.string().trim().max(500).optional().describe('Where in creatif.space it happened, if the user said (e.g. "Content Plan → Calendar")'),
  },
  annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
  run: async (args, { v1 }) => {
    const { data } = await v1.post<{ data: { id: string; kind: string; received_at: string } }>('/feedback', {
      kind: args.kind,
      message: args.message,
      ...(args.page ? { page: args.page } : {}),
    });
    return {
      sent: true,
      id: data.id,
      kind: data.kind,
      note: 'Sent to the creatif.space team. They reply by email if they need more.',
    };
  },
});

export const feedbackTools = [sendFeedback];

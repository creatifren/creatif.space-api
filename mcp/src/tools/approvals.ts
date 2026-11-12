import { z } from 'zod';
import { defineTool, describeTool } from './define.js';

/* The owner's side of client approval: what is waiting on clients, and what
   they said. Reads only — GET /social-approvals/pending and
   GET /social-posts/{id}/comments. Deciding is the client's job (on the
   link, or decide_approval for the approver). */

const READ_ONLY = { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false } as const;

interface PendingPost {
  id: string;
  caption: string;
  scheduled_at: string | null;
  state: 'waiting' | 'changes_requested';
  link: { id: string; url: string; title: string | null; approver_email: string | null; kind: 'list' | 'plan'; expires_at: string | null } | null;
  open_feedback: number;
  latest_feedback: { id: string; author: string; body: string; suggestion: string | null; created_at: string | null } | null;
}

interface V1Comment {
  id: string;
  author: string;
  role: string;
  body: string;
  suggestion: string | null;
  resolved: boolean;
  created_at: string | null;
  replies: V1Comment[];
}

const CLIENT_ROLES = new Set(['APPROVER', 'COMMENTER']);

const shapeComment = (c: V1Comment): Record<string, unknown> => ({
  id: c.id,
  author: c.author,
  from: CLIENT_ROLES.has(c.role) ? 'client' : 'team',
  body: c.body,
  ...(c.suggestion ? { suggested_caption: c.suggestion } : {}),
  resolved: c.resolved,
  created_at: c.created_at,
  replies: (c.replies ?? []).map(shapeComment),
});

export const listPendingApprovals = defineTool({
  name: 'list_pending_approvals',
  title: 'List posts waiting on client approval',
  description: describeTool({
    does: 'Lists the workspace’s posts held for a client’s approval — still waiting, or the client asked for changes — with the link each is on and the client’s latest open feedback.',
    when: 'when the user asks what clients still have to approve, what came back with changes, or what to follow up on. Use it across all links; check_approval_status is for one post or one link you already know, and get_client_feedback reads one post’s full thread. For posts OTHER people sent the user to approve, use list_waiting_approvals instead.',
    example: 'list_pending_approvals { "state": "changes_requested" } → { count, posts: [{ id, state, scheduled_at, link: { url, approver_email }, open_feedback, latest_feedback: { author, body } }] }',
    rules: 'For a changes_requested post: read get_client_feedback, then update_post (an edit sends it back to the client on the same link). For a waiting post past its time or on an expired link: tell the user; a new create_approval_link can resend it. Free plans have approval links for one client, posts up to 7 days ahead and 10 queued posts (get_credits shows the limits).',
    errors: [
      ['invalid_input', 'state is waiting, changes_requested or all'],
      ['scope_missing', 'reconnect and allow "read"'],
      ['plan_required', 'pass the message on as it is (it has the upgrade link); do not retry'],
      ['forbidden', 'this seat cannot see approvals; the owner or an editor can'],
      ['unauthorized', 'reconnect the creatif.space connector'],
      ['upstream_unavailable', 'wait a minute and call again'],
    ],
  }),
  scope: 'read',
  input: {
    state: z
      .enum(['waiting', 'changes_requested', 'all'])
      .default('all')
      .describe('waiting: the client has not answered; changes_requested: the client asked for changes; all (default)'),
    limit: z.number().int().min(1).max(100).default(50).describe('At most this many posts (1–100, default 50), soonest slot first'),
  },
  annotations: READ_ONLY,
  run: async (args, { v1 }) => {
    const { data } = await v1.get<{ data: PendingPost[] }>('/social-approvals/pending', { state: args.state, limit: args.limit });
    const changes = data.filter((p) => p.state === 'changes_requested').length;
    return {
      count: data.length,
      waiting: data.length - changes,
      changes_requested: changes,
      posts: data,
      next_step:
        data.length === 0
          ? 'Nothing is waiting on a client. To ask for approval, draft_post then create_approval_link.'
          : changes > 0
            ? 'For each changes_requested post, read get_client_feedback, then update_post with the fix (it goes back to the client on the same link).'
            : 'Every post is waiting on the client. Share the link url again if the user wants to nudge them; check_approval_status with link.id follows a link.',
    };
  },
});

export const getClientFeedback = defineTool({
  name: 'get_client_feedback',
  title: 'Read a client’s feedback on a post',
  description: describeTool({
    does: 'Reads one post’s review thread: every comment, who wrote it (client or team), any caption the client suggested, replies, and whether it is resolved.',
    when: 'after list_pending_approvals or check_approval_status shows feedback, to see exactly what the client asked for before changing the post with update_post. get_post gives the whole post; this gives the full thread, resolved comments included.',
    example: 'get_client_feedback { "post_id": "01JD…", "include_resolved": false } → { post_id, open, threads: [{ author, from: "client", body, suggested_caption?, replies }] }',
    rules: 'A suggested_caption is the client’s own wording: offer it to the user, and apply it with update_post caption only if they agree.',
    errors: [
      ['not_found', 'find the post id with list_pending_approvals or list_posts'],
      ['scope_missing', 'reconnect and allow "read"'],
      ['forbidden', 'this seat cannot see the post; the owner or an editor can'],
      ['unauthorized', 'reconnect the creatif.space connector'],
      ['upstream_unavailable', 'wait a minute and call again'],
    ],
  }),
  scope: 'read',
  input: {
    post_id: z.string().trim().min(10).max(26).describe('Post id, from list_pending_approvals or list_posts'),
    include_resolved: z.boolean().default(false).describe('Also return resolved threads (default false: open ones only)'),
  },
  annotations: READ_ONLY,
  notFound: { what: 'That post', next: 'Find the post id with list_pending_approvals or list_posts and call again.' },
  run: async ({ post_id, include_resolved }, { v1 }) => {
    const { data } = await v1.get<{ data: V1Comment[] }>(`/social-posts/${encodeURIComponent(post_id)}/comments`);
    const threads = data.filter((c) => include_resolved || !c.resolved).map(shapeComment);
    const fromClient = threads.filter((t) => t.from === 'client').length;
    return {
      post_id,
      open: data.filter((c) => !c.resolved).length,
      from_client: fromClient,
      threads,
      next_step:
        fromClient > 0
          ? 'Summarise what the client asked for, agree the change with the user, then update_post. The edit goes back to the client on the same link.'
          : 'No client feedback on this post yet. check_approval_status shows whether they have decided.',
    };
  },
});

export const approvalTools = [listPendingApprovals, getClientFeedback];

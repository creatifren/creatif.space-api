import { z } from 'zod';
import { ToolError } from '../errors.js';
import { V1Error } from '../v1.js';
import { pageInput, pageOf } from './paging.js';
import { defineTool, describeTool, type ToolContext } from './define.js';

/**
 * The approver side: posts OTHER people sent this user for approval
 * (approval links whose approver_email is the user's verified email).
 * Scope approvals:decide — "Approve or request changes on posts sent to
 * you". The decision runs through the same path as the approval page and
 * is audited as source "mcp".
 */

interface DecisionPreview {
  link: { id: string; title: string | null; editor: string };
  post: { id: string; caption: string; scheduled_at: string | null; channels: { platform: string; handle: string | null }[]; media: number; state: string };
  decision: 'approve' | 'revise';
  reason: string | null;
  decidable: boolean;
  decidable_from: string | null;
  already: boolean;
  /** Approve on a post with no time: refused (a yes would publish it at once). */
  needs_time?: boolean;
  effect: string;
  confirm_token: string | null;
  confirm_expires_at: string;
}

/**
 * The confirm_token of the last decision preview shown, kept here so
 * confirm: true decides exactly what was previewed — the model never
 * handles the token. Keyed by user, link, post, decision and reason.
 */
export class DecisionTokens {
  private readonly tokens = new Map<string, { token: string; expiresAt: number }>();

  constructor(private readonly now: () => number = Date.now) {}

  static key(user: string, link: string, post: string, decision: string, reason?: string): string {
    return JSON.stringify([user, link, post, decision, (reason ?? '').trim()]);
  }

  remember(key: string, p: DecisionPreview): void {
    if (!p.confirm_token) return;
    this.tokens.set(key, { token: p.confirm_token, expiresAt: Date.parse(p.confirm_expires_at) || this.now() + 10 * 60_000 });
  }

  take(key: string): string | undefined {
    const hit = this.tokens.get(key);
    if (!hit || hit.expiresAt <= this.now()) {
      this.tokens.delete(key);
      return undefined;
    }
    return hit.token;
  }

  forget(key: string): void {
    this.tokens.delete(key);
  }

  clear(): void {
    this.tokens.clear();
  }
}

export const decisionTokens = new DecisionTokens();

export const listWaitingApprovals = defineTool({
  name: 'list_waiting_approvals',
  title: 'List posts waiting for your approval',
  description: describeTool({
    does: 'Lists posts that OTHER creatif.space users sent to this user for approval (links and Content Plans addressed to the user’s verified email) that still wait for a decision.',
    when: 'when the user asks what they have to approve, or before decide_approval to get the link id and post id. To follow a link the user created themselves, use check_approval_status instead.',
    example: 'list_waiting_approvals {} → { waiting: [{ link: { id, title, from, kind, url, expires_at }, waiting, posts: [{ id, caption, scheduled_at, decidable }] }] }',
    rules: 'Pass the link id to decide_approval as `link`. On some sender plans only posts in the next 7 days can be decided; decidable is false for the rest.',
    errors: [
      ['scope_missing', 'reconnect and allow "approvals:decide"'],
      ['unauthorized', 'reconnect creatif.space'],
      ['upstream_unavailable', 'call again in a minute'],
    ],
  }),
  scope: 'approvals:decide',
  input: pageInput(100, 50, 'links'),
  annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
  run: async (args, { v1 }) => {
    const { data } = await v1.get<{ data: { link: Record<string, unknown>; waiting: number; posts: Record<string, unknown>[] }[] }>('/me/approvals/waiting');
    const { page, next_cursor } = pageOf('list_waiting_approvals', {}, data, args.limit, args.cursor);
    return {
      next_cursor,
      waiting: page.map((row) => ({
        link: {
          id: row.link.id,
          title: row.link.title,
          from: row.link.editor,
          kind: row.link.kind,
          url: row.link.url,
          expires_at: row.link.expires_at,
        },
        waiting: row.waiting,
        posts: row.posts,
      })),
      note: data.length === 0
        ? 'Nothing is waiting for this user’s approval.'
        : 'To decide on a post, call decide_approval with the link id and post id; it shows a preview first.',
    };
  },
});

function previewResult(p: DecisionPreview) {
  const blocked = (!p.decidable || p.needs_time === true) && !p.already;
  return {
    confirmation_required: !blocked,
    link: p.link,
    post: p.post,
    decision: p.decision,
    ...(p.reason ? { reason: p.reason } : {}),
    effect: p.effect,
    ...(p.already ? { already: true } : {}),
    ...(blocked
      ? {
          blocked: p.needs_time
            ? `This post has no publish time yet, so it cannot be approved (approving would publish it at once). ${p.link.editor} has to set a time first; asking for changes ("revise") still works.`
            : p.decidable_from
            ? `This post can be decided from ${p.decidable_from} (the sender’s plan allows deciding 7 days ahead).`
            : `This post is not waiting for a decision (it is "${p.post.state}").`,
        }
      : {}),
    next: blocked
      ? 'Tell the user why this cannot be decided now.'
      : `Show the user this post and the decision. Only if they explicitly agree, call decide_approval again with the same arguments and confirm: true.`,
  };
}

async function fetchPreview(ctx: ToolContext, args: { link: string; post_id: string; decision: 'approve' | 'revise'; reason?: string }): Promise<DecisionPreview> {
  const { data } = await ctx.v1.get<{ data: DecisionPreview }>(`/me/approvals/${encodeURIComponent(args.post_id)}/preview`, {
    link: args.link,
    decision: args.decision,
    ...(args.reason ? { reason: args.reason } : {}),
  });
  return data;
}

export const decideApproval = defineTool({
  name: 'decide_approval',
  title: 'Approve or request changes on a post sent to you',
  description: describeTool({
    does: 'Approves a post someone sent this user for approval, or requests changes on it (decision "revise", with a reason the sender reads); previews first, decides only with confirm: true.',
    when: 'when the user wants to approve or send back a post that is waiting for them (ids from list_waiting_approvals). To share the user’s own posts for approval, use create_approval_link instead.',
    example: 'decide_approval { "link": "01JD…", "post_id": "01JE…", "decision": "approve" } → preview; then the same with "confirm": true → { decided }',
    rules: 'Two steps: call with confirm false (default) for a preview; show it to the user and only after they explicitly agree call again with the same arguments and confirm: true. Approving sends the post at its scheduled time on the sender’s channels — it goes public; it never publishes at once. A post with no time cannot be approved (needs_time) until the sender sets one. The confirm decides exactly what the preview showed (a signed token, valid 10 minutes). Only links sent to this user’s email. Deciding the same way twice changes nothing.',
    errors: [
      ['preview_stale', 'the post changed since the preview: show the user the new preview in details, then confirm again only if they agree'],
      ['needs_time', 'the post has no publish time yet: nothing was decided; tell the user the sender has to set a time first (or send it back with decision "revise")'],
      ['forbidden', 'the link was sent to another email (details.expected_hint): tell the user, do not retry'],
      ['invalid_input', 'revise needs a reason; or the post is outside the decidable window (details.decidable_from): tell the user'],
      ['not_found', 'check ids with list_waiting_approvals'],
      ['scope_missing', 'reconnect and allow "approvals:decide"'],
    ],
  }),
  scope: 'approvals:decide',
  input: {
    link: z.string().trim().min(10).max(2048).describe('The link id from list_waiting_approvals (or the approval link URL)'),
    post_id: z.string().trim().min(10).max(26).describe('Post id from list_waiting_approvals'),
    decision: z.enum(['approve', 'revise']).describe('"approve", or "revise" to request changes'),
    reason: z.string().trim().min(1).max(5000).optional().describe('Required for "revise": what should change (the sender reads it)'),
    confirm: z.boolean().default(false).describe('Leave false first: you get a preview. Set true only after the user explicitly agreed to that preview.'),
  },
  annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false },
  notFound: { what: 'A post on that approval link', next: 'Check the link and post ids with list_waiting_approvals and call again.' },
  run: async (args, ctx) => {
    if (args.decision === 'revise' && !args.reason) {
      throw new ToolError('invalid_input', 'Requesting changes needs a reason the sender can act on.', 'Ask the user what should change and pass it as reason.');
    }
    const key = DecisionTokens.key(ctx.auth.extra.userUlid, args.link, args.post_id, args.decision, args.reason);

    if (!args.confirm) {
      const p = await fetchPreview(ctx, args);
      decisionTokens.remember(key, p);
      return previewResult(p);
    }

    const token = decisionTokens.take(key);
    if (!token) {
      const p = await fetchPreview(ctx, args);
      decisionTokens.remember(key, p);
      return {
        ...previewResult(p),
        note: 'No current preview of this decision had been shown (or it expired after 10 minutes), so nothing was decided. Show the user this preview first.',
      };
    }

    try {
      const { data } = await ctx.v1.post<{ data: Record<string, unknown> }>(`/me/approvals/${encodeURIComponent(args.post_id)}/decision`, {
        link: args.link,
        decision: args.decision,
        ...(args.reason ? { reason: args.reason } : {}),
        confirm_token: token,
      });
      decisionTokens.forget(key);
      return {
        decided: true,
        post_id: data.id,
        state: data.state,
        approval: data.approval,
        ...(args.decision === 'approve' ? { scheduled_at: data.scheduled_at ?? null } : {}),
        note: args.decision === 'approve'
          ? `Approved. It goes out at its scheduled time (${String(data.scheduled_at ?? 'as planned')}), not now; the sender is told.`
          : 'Changes requested. The sender is told, with the reason.',
      };
    } catch (e) {
      if (e instanceof V1Error && e.status === 422 && e.body?.reason === 'needs_time') {
        decisionTokens.forget(key);
        throw new ToolError(
          'needs_time',
          'Nothing was decided: this post has no publish time yet, and approving it would publish it at once.',
          'Tell the user the sender has to set a time first; or send it back with decision "revise" and a reason.',
          { post_id: args.post_id },
        );
      }
      if (e instanceof V1Error && e.status === 409 && e.body?.code === 'preview_stale') {
        decisionTokens.forget(key);
        const p = await fetchPreview(ctx, args);
        decisionTokens.remember(key, p);
        throw new ToolError(
          'preview_stale',
          'Nothing was decided: the post changed since the preview (or 10 minutes passed).',
          'Show the user the new preview in details.preview. Only if they agree again, call again with confirm: true.',
          { preview: previewResult(p) },
        );
      }
      throw e;
    }
  },
});

interface SharedReportRow {
  report_id: string;
  kind: 'content_plan' | 'insight';
  title: string | null;
  period: { from: string | null; to: string | null; timezone?: string };
  owner: { name: string };
  url: string;
  posts?: number;
  waiting?: number;
  expires_at?: string | null;
}

export const listSharedWithMe = defineTool({
  name: 'list_shared_with_me',
  title: 'List reports shared with you',
  description: describeTool({
    does: 'Lists what OTHER creatif.space users shared with this user to look at ("Reports shared with you"): Content Plans sent to the user’s verified email, and Insight reports shared with that email.',
    when: 'when the user asks what has been shared with them, which plans they were invited to review, or for the link to a plan someone sent. To see only posts that still need the user’s decision, use list_waiting_approvals instead.',
    example: 'list_shared_with_me {} → { shared: [{ id, kind: "content_plan", title, period: { from, to, timezone }, from, url, posts, waiting }] }',
    rules: 'Read-only. kind is content_plan or insight. Expired or revoked links are left out. To decide on posts in a plan, get the ids from list_waiting_approvals and call decide_approval.',
    errors: [
      ['scope_missing', 'reconnect and allow "approvals:decide"'],
      ['unauthorized', 'reconnect creatif.space'],
      ['upstream_unavailable', 'call again in a minute'],
    ],
  }),
  scope: 'approvals:decide',
  input: pageInput(100, 50, 'reports'),
  annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
  run: async (args, { v1 }) => {
    const { data } = await v1.get<{ data: SharedReportRow[] }>('/me/reports/shared');
    const { page, next_cursor } = pageOf('list_shared_with_me', {}, data, args.limit, args.cursor);
    return {
      next_cursor,
      shared: page.map((r) => ({
        id: r.report_id,
        kind: r.kind,
        title: r.title,
        period: r.period,
        from: r.owner.name,
        url: r.url,
        ...(r.posts !== undefined ? { posts: r.posts } : {}),
        ...(r.waiting !== undefined ? { waiting: r.waiting } : {}),
        ...(r.expires_at !== undefined ? { expires_at: r.expires_at } : {}),
      })),
      note: data.length === 0
        ? 'Nothing has been shared with this user.'
        : 'Give the user the url to open a report. For posts waiting on their decision, call list_waiting_approvals.',
    };
  },
});

export const approverTools = [listWaitingApprovals, decideApproval, listSharedWithMe];

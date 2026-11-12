import { z } from 'zod';
import { ToolError } from '../errors.js';
import { V1Error } from '../v1.js';
import { defineTool, describeTool, type ToolContext } from './define.js';
import { PreviewTokens } from './m2.js';
import { shapePost, type V1Post } from './shape.js';

/**
 * Try a failed publish again: the post's failed destinations go to their
 * networks once more (GET /social-posts/{id}/retry-preview, then POST
 * /social-posts/{id}/retry). Preview → confirm like publish_post: the
 * confirm retries exactly what the preview showed (a signed token kept
 * here, 10 minutes). Credits as on any send: the failure paid the
 * destination back, the retry charges it again.
 */

interface RetryPreview {
  post_id: string;
  status: string;
  retryable: boolean;
  blocked: { code: string; message: string } | null;
  targets: { channel_id: string | null; platform: string | null; username: string | null; usable: boolean; failure_code: string; reason: string | null; credits: number }[];
  credits: { price: number; from_topup: number; monthly_balance: number; topup_balance: number };
  confirm_token?: string;
  confirm_expires_at?: string;
}

/** Kept apart from the send previews: a send token never confirms a retry. */
export const retryTokens = new PreviewTokens();

async function preview(ctx: ToolContext, postId: string): Promise<RetryPreview> {
  const { data } = await ctx.v1.get<{ data: RetryPreview }>(`/social-posts/${encodeURIComponent(postId)}/retry-preview`);
  retryTokens.remember(ctx.auth.extra.userUlid, postId, data);
  return data;
}

function previewResult(p: RetryPreview) {
  const failed = p.targets.map((t) => ({
    channel_id: t.channel_id,
    platform: t.platform,
    username: t.username,
    failure_code: t.failure_code,
    reason: t.reason,
    credits: t.credits,
  }));

  if (!p.retryable) {
    if (p.blocked?.code === 'not_failed') {
      throw new ToolError(
        'not_failed',
        'Nothing to retry: no channel of this post failed.',
        'Call get_post to see each channel’s status; a post still sending or already out needs no retry.',
        { post_id: p.post_id, status: p.status },
      );
    }
    return {
      confirmation_required: false,
      post_id: p.post_id,
      failed,
      blocked: p.blocked?.message ?? 'This post cannot be retried now.',
      blocked_code: p.blocked?.code,
      next: 'Tell the user why it cannot be retried now (blocked), and what to do first.',
    };
  }

  return {
    confirmation_required: true,
    post_id: p.post_id,
    failed,
    credit_cost: p.credits.price,
    credits_from_topup: p.credits.from_topup,
    monthly_balance: p.credits.monthly_balance,
    topup_balance: p.credits.topup_balance,
    next: 'Show the user each failed channel with its reason and the Credit cost. If a reason says to change the post first (caption, media, board), suggest update_post before retrying. Only if the user explicitly agrees, call retry_post again with confirm: true.',
  };
}

export const retryPost = defineTool({
  name: 'retry_post',
  title: 'Retry a failed post',
  description: describeTool({
    does: 'Sends the FAILED channels of a post again (the ones that already went out are left alone), after the user agrees to a preview of each failure’s reason and the Credit cost.',
    when: 'when get_post or list_posts shows a post failed (status "failed", or a channel with a fail_code) and the user wants to try again. For a draft that was never sent, use publish_post instead.',
    example: 'retry_post { "post_id": "01JD…" } → preview { failed: [{ platform, failure_code: "platform_unavailable", reason }], credit_cost }; then retry_post { "post_id": "01JD…", "confirm": true } → { post, retried, note }',
    rules: 'Two steps: confirm false (default) gives the preview; show it, and only after the user explicitly agrees call again with confirm: true. The confirm retries exactly what the preview showed (a signed token, valid 10 minutes). It goes public again on those networks. Credits are charged again (the failure gave them back). failure_code is stable: network_rejected = the network said no without a reason; reasons like missing_board, content_too_long or needs_video need the post changed first (update_post), or the retry fails the same way. Connected apps can send at most 20 posts a day, retries included.',
    errors: [
      ['not_failed', 'nothing failed: get_post shows each channel’s status; do not retry'],
      ['preview_stale', 'nothing was sent; show the user the new preview in details.preview and confirm again only if they agree'],
      ['insufficient_credits or quota_exceeded', 'pass the message on as it is (nothing was charged)'],
      ['reconnect_required', 'the user reconnects the channel in creatif.space → Channels'],
      ['daily_cap_reached', 'retry from creatif.space or wait for details.resets_at'],
      ['not_found', 'take ids from list_posts'],
      ['scope_missing', 'reconnect and allow "publish"'],
      ['upstream_unavailable', 'check get_post before calling again — it may have gone out'],
    ],
  }),
  scope: 'publish',
  input: {
    post_id: z.string().trim().min(10).max(26).describe('Post id, as list_posts or get_post return it'),
    confirm: z.boolean().default(false).describe('Leave false first: you get a preview (failed channels, reasons, Credit cost). Set true only after the user explicitly agreed to that preview.'),
  },
  annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true },
  notFound: { what: 'That post', next: 'Check the post id with list_posts and call again.' },
  run: async (args, ctx) => {
    const user = ctx.auth.extra.userUlid;
    if (!args.confirm) {
      return previewResult(await preview(ctx, args.post_id));
    }

    const token = retryTokens.take(user, args.post_id);
    if (!token) {
      return {
        ...previewResult(await preview(ctx, args.post_id)),
        note: 'No current preview of this retry had been shown (or it expired after 10 minutes), so nothing was sent. Show the user this preview first.',
      };
    }

    try {
      const res = await ctx.v1.post<{ data: V1Post; retried: number }>(`/social-posts/${encodeURIComponent(args.post_id)}/retry`, { confirm_token: token });
      retryTokens.forget(user, args.post_id);
      return {
        post: shapePost(res.data),
        retried: res.retried,
        note: res.retried > 0
          ? 'Sent again. Each channel’s result arrives in a few minutes: check get_post.'
          : 'The network could not be reached; creatif.space tries again on its own shortly, or the channel shows as failed in get_post.',
      };
    } catch (e) {
      if (e instanceof V1Error && e.status === 409 && e.body?.code === 'preview_stale') {
        retryTokens.forget(user, args.post_id);
        const fresh = previewResult(await preview(ctx, args.post_id));
        throw new ToolError(
          'preview_stale',
          'Nothing was sent: the failed channels or the Credit cost changed since the preview (or 10 minutes passed).',
          'Show the user the new preview in details.preview. Only if they agree again, call again with confirm: true.',
          { preview: fresh },
        );
      }
      if (e instanceof V1Error && e.status === 409 && e.body?.code === 'not_failed') {
        retryTokens.forget(user, args.post_id);
        throw new ToolError('not_failed', 'Nothing to retry: no channel of this post failed (it may have been retried already).', 'Call get_post to see each channel’s status. Do not retry.', { post_id: args.post_id });
      }
      throw e;
    }
  },
});

export const retryTools = [retryPost];

import { z } from 'zod';
import { ToolError } from '../errors.js';
import { V1Error } from '../v1.js';
import { defineTool, describeTool } from './define.js';
import { displayStatus, type V1Post } from './shape.js';
import { pageInput, pageOf } from './paging.js';

/*
 * Post housekeeping over existing V1 routes:
 * discard_draft → GET /social-posts/{id}, then DELETE /social-posts/{id}/draft
 * (SocialPostController::discard: drafts, and posts held for approval that
 * never reached the networks — a draft shared on a link — 422 otherwise);
 * list_approval_links → GET /social-approval-links;
 * revoke_approval_link → GET then DELETE /social-approval-links/{id}.
 */

const postId = z.string().trim().min(10).max(26).describe('The post id, from list_posts (status "draft" or "waiting") or draft_post');

/** Why a post that is not a draft cannot be discarded here, and what to do instead. */
const NOT_DRAFT_NEXT: Record<string, string> = {
  scheduled:
    'It is scheduled, not a draft, so it was not deleted. Tell the user they can cancel it in creatif.space (Credits for channels not sent yet come back); to change it instead, use update_post.',
  published: 'It is already published, so it was not deleted. Tell the user a published post can only be removed in creatif.space or on the platform itself.',
  failed: 'It failed to publish and is not a draft, so it was not deleted. Tell the user they can remove it in creatif.space.',
  cancelled: 'It was cancelled and is not a draft, so it was not deleted. Nothing more to do here.',
};

const notDraft = (post_id: string, status: string) =>
  new ToolError(
    'not_draft',
    `Only a draft can be discarded from here; this post is "${status}".`,
    NOT_DRAFT_NEXT[status] ?? 'It is not a draft, so it was not deleted. Tell the user to manage it in creatif.space.',
    { post_id, status, deleted: false },
  );

export const discardDraft = defineTool({
  name: 'discard_draft',
  title: 'Discard a draft',
  description: describeTool({
    does: 'Deletes a draft post for good — also one waiting for approval on a link (status "waiting_for_approval" or "changes_requested"), which takes it off that link. Refuses anything scheduled with a time, published, failed or cancelled.',
    when: 'when the user wants to throw a draft away, or take a post off an approval link. Without confirm you get a preview of what will be deleted; call again with confirm: true only after the user agrees. To change a draft instead, use update_post; to close a whole link, revoke_approval_link; scheduled posts are cancelled in creatif.space, not here.',
    example: 'discard_draft { "post_id": "01JD…" } → { preview: { post, warnings }, next_step }; then discard_draft { "post_id": "01JD…", "confirm": true } → { deleted: true, post_id }',
    rules: 'Cannot be undone. A post on an approval link disappears from that link too (the preview warns). No Credits were spent on it: it never went out.',
    errors: [
      ['not_draft', 'it is scheduled or published: nothing was deleted; follow next_step (cancel in creatif.space, or update_post)'],
      ['not_found', 'take the id from list_posts status "draft"'],
      ['forbidden', 'only the workspace owner can discard this draft; tell the user'],
      ['scope_missing', 'reconnect with "draft" allowed'],
    ],
  }),
  scope: 'draft',
  input: {
    post_id: postId,
    confirm: z.boolean().default(false).describe('Leave false first to see what will be deleted. Set true only after the user explicitly agreed.'),
  },
  annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
  notFound: { what: 'That post', next: 'Take the id from list_posts with status "draft" and call again.' },
  run: async ({ post_id, confirm }, { v1 }) => {
    const path = `/social-posts/${encodeURIComponent(post_id)}`;
    const { data: post } = await v1.get<{ data: V1Post }>(path);
    const shown = displayStatus(post);
    const held = shown === 'waiting_for_approval' || shown === 'changes_requested';
    if (post.status !== 'draft' && !held) throw notDraft(post_id, post.status);

    if (!confirm) {
      const warnings = post.approval ? [`It is on a client approval link (approval "${post.approval}"); discarding deletes it and removes it from that link.`] : [];
      return {
        preview: {
          post: {
            id: post.id,
            status: shown,
            caption: post.caption.length > 200 ? `${post.caption.slice(0, 200)}…` : post.caption,
            scheduled_at: post.scheduled_at,
            channels: (post.targets ?? []).map((t) => ({ platform: t.account.platform, username: t.account.username })),
            media_count: (post.media ?? []).length,
          },
          warnings,
        },
        next_step: 'Nothing was deleted. Show the user this draft and ask whether to delete it for good; call again with confirm: true only if they agree.',
      };
    }

    try {
      await v1.delete(`${path}/draft`);
    } catch (e) {
      // Changed state between the check and the delete.
      if (e instanceof V1Error && e.status === 422) throw notDraft(post_id, 'not a draft any more');
      throw e;
    }
    return { deleted: true, post_id, next_step: 'Deleted. Library files it used stay in the library.' };
  },
});

interface V1Link {
  id: string;
  url: string;
  title: string | null;
  client_name: string | null;
  approver_email: string | null;
  kind: string;
  posts: number;
  status: 'active' | 'expired' | 'revoked';
  counts: { approved: number; waiting: number; revision: number };
  expires_at: string | null;
  revoked_at: string | null;
  created_at: string | null;
}

export const listApprovalLinks = defineTool({
  name: 'list_approval_links',
  title: 'List approval links',
  description: describeTool({
    does: 'Lists the workspace’s client approval links, newest first: status, approver email, post count and how many posts are approved, waiting or need changes.',
    when: 'to see which links are open or to find a link_id. For one link’s posts and comments use check_approval_status with link_id; to make a new link use create_approval_link.',
    example: 'list_approval_links { "status": "active" } → { total, links: [{ id, title, client_name, approver_email, status, post_count, counts: { approved, waiting, revision }, url, expires_at }] }',
    rules: 'Default: open (active) links only; status "all" includes expired and revoked ones. At most the 200 newest links are read.',
    errors: [
      ['unauthorized', 'ask the user to reconnect the creatif.space connector'],
      ['scope_missing', 'reconnect with "read" allowed'],
      ['upstream_unavailable', 'wait a minute and call again'],
    ],
  }),
  scope: 'read',
  input: {
    status: z.enum(['active', 'expired', 'revoked', 'all']).default('active').describe('Which links: "active" (open, default), "expired", "revoked" or "all"'),
    ...pageInput(200, 50, 'links'),
  },
  annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
  run: async ({ status, limit, cursor }, { v1 }) => {
    const { data } = await v1.get<{ data: V1Link[] }>('/social-approval-links');
    const matching = data.filter((l) => status === 'all' || l.status === status);
    const { page, next_cursor } = pageOf('list_approval_links', { status }, matching, limit, cursor);
    return {
      total: matching.length,
      links: page.map((l) => ({
        id: l.id,
        title: l.title,
        client_name: l.client_name,
        approver_email: l.approver_email,
        status: l.status,
        kind: l.kind,
        post_count: l.posts,
        counts: l.counts,
        url: l.url,
        expires_at: l.expires_at,
        ...(l.revoked_at ? { revoked_at: l.revoked_at } : {}),
        created_at: l.created_at,
      })),
      next_cursor,
    };
  },
});

export const revokeApprovalLink = defineTool({
  name: 'revoke_approval_link',
  title: 'Revoke an approval link',
  description: describeTool({
    does: 'Revokes a client approval link for good: the client can no longer open it or decide. Posts the client had not answered come back to the user (approval "awaiting"); posts already approved stay approved.',
    when: 'when the user wants to withdraw a link (wrong client, wrong posts, sent by mistake). Without confirm you get a preview of the link; call again with confirm: true only after the user agrees. To take one post off a link instead, use discard_draft on it; to make a new link, create_approval_link.',
    example: 'revoke_approval_link { "link_id": "01JD…" } → { confirmation_required: true, preview: { link: { id, title, approver_email, status, post_count, counts } }, next_step }; then the same with "confirm": true → { revoked: true, link_id }',
    rules: 'Cannot be undone: a revoked link stays revoked (make a new one instead). Revoking a link that is already revoked changes nothing. Nothing is published or deleted.',
    errors: [
      ['not_found', 'take the id from list_approval_links'],
      ['forbidden', 'viewers cannot revoke links; tell the user'],
      ['scope_missing', 'reconnect with "approve" allowed'],
    ],
  }),
  scope: 'approve',
  input: {
    link_id: z.string().trim().min(10).max(26).describe('The link id, from list_approval_links or create_approval_link'),
    confirm: z.boolean().default(false).describe('Leave false first to see the link. Set true only after the user explicitly agreed to revoke it.'),
  },
  annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false },
  notFound: { what: 'That approval link', next: 'Take the id from list_approval_links and call again.' },
  run: async ({ link_id, confirm }, { v1 }) => {
    const linkPath = `/social-approval-links/${encodeURIComponent(link_id)}`;
    const { data: { link: l } } = await v1.get<{ data: { link: V1Link } }>(linkPath);
    if (l.status === 'revoked') {
      return { revoked: true, already: true, link_id: l.id, next_step: 'This link was already revoked; nothing changed. create_approval_link makes a new one.' };
    }
    if (!confirm) {
      return {
        confirmation_required: true,
        preview: {
          link: { id: l.id, title: l.title, client_name: l.client_name, approver_email: l.approver_email, status: l.status, kind: l.kind, post_count: l.posts, counts: l.counts },
          on_confirm: 'The link stops working for good. Posts not yet answered come back to the user; approved posts stay approved. Nothing is published or deleted.',
        },
        next_step: 'Nothing was revoked. Show the user this link and ask whether to revoke it for good; call again with confirm: true only if they agree.',
      };
    }
    await v1.delete(linkPath);
    return {
      revoked: true,
      link_id: l.id,
      next_step: 'Revoked. Posts the client had not answered are back with the user (list_posts status "waiting"): discard_draft deletes one, update_post changes it, create_approval_link shares it again.',
    };
  },
});

export const housekeepingTools = [discardDraft, listApprovalLinks, revokeApprovalLink];

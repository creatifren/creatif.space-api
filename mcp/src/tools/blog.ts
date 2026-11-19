import { z } from 'zod';
import { defineTool, describeTool } from './define.js';
import { pageInput, pageOf } from './paging.js';

/**
 * Blogs (v7): a Space of type blog holds posts — a cover, a title and
 * blocks. A post that needs client approval goes onto an approval link and
 * is published to the Blog only when the client approves. A social post can
 * also land in a Gallery or a Blog when it is published ("Also add to").
 */

const ulid = (what: string) =>
  z.string().trim().min(10).max(26).describe(`${what} id, as returned by the list tools`);

const READ_ONLY = { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false } as const;

type Obj = Record<string, unknown>;

const block = z.discriminatedUnion('type', [
  z.object({ type: z.literal('paragraph'), text: z.string().max(20000) }),
  z.object({ type: z.literal('heading'), text: z.string().max(500) }),
  z.object({ type: z.literal('quote'), text: z.string().max(5000) }),
  z.object({ type: z.literal('list'), items: z.array(z.string().max(2000)).max(100) }),
  z.object({ type: z.literal('photo'), file_id: ulid('File'), caption: z.string().max(500).optional() }),
  z.object({ type: z.literal('divider') }),
]);

const shape = (p: Obj) => ({
  id: p.id,
  title: p.title,
  slug: p.slug,
  status: p.status,
  needs_approval: p.needs_approval,
  ...(p.approval_comment ? { client_comment: p.approval_comment } : {}),
  published_at: p.published_at ?? null,
  ...(p.blocks ? { blocks: p.blocks } : {}),
});

export const listBlogPosts = defineTool({
  name: 'list_blog_posts',
  title: 'List a Blog’s posts',
  description: describeTool({
    does: 'Lists the posts of one of the user’s Blogs with their status: draft, waiting (for the client), changes_requested, approved or published.',
    when: 'when the user asks about their blog or press posts, or before editing one. Blog ids come from list_spaces (type blog).',
    example: 'list_blog_posts { "space_id": "01JS…" } → { posts: [{ id, title, slug, status: "waiting", needs_approval: true }] }',
    rules: 'Read-only. Only published posts are visible on the public Blog.',
    errors: [['invalid_input', 'that Space is a Gallery, not a Blog: pick one with type blog from list_spaces']],
  }),
  scope: 'read',
  input: { space_id: ulid('Blog (Space)'), ...pageInput(100, 25, 'posts') },
  annotations: READ_ONLY,
  notFound: { what: 'That Blog', next: 'Call list_spaces and pick a Space with type blog.' },
  run: async (args, { v1 }) => {
    const { data } = await v1.get<{ data: Obj[] }>(`/spaces/${encodeURIComponent(args.space_id)}/posts`);
    const { page, next_cursor, total } = pageOf('list_blog_posts', { space_id: args.space_id }, data, args.limit, args.cursor);
    return { count: page.length, total, posts: page.map(shape), next_cursor };
  },
});

export const createBlogPost = defineTool({
  name: 'create_blog_post',
  title: 'Write a Blog post',
  description: describeTool({
    does: 'Writes a draft post in one of the user’s Blogs: title, optional cover, and blocks (paragraph, heading, quote, list, photo, divider).',
    when: 'when the user asks for a blog post, a news item or a press release. A press release is a Blog post that needs client approval (needs_approval true).',
    example: 'create_blog_post { "space_id": "01JS…", "title": "Ombak opens rooftop bar", "blocks": [{ "type": "paragraph", "text": "…" }], "needs_approval": true } → { post: { id, status: "draft" } }',
    rules: 'It stays a draft: nothing is public until publish_blog_post (no approval needed) or the client approves it (send_blog_post_for_approval). Photo blocks take file ids from list_files.',
    errors: [
      ['invalid_input', 'fix what details.errors names (a photo must be a ready library file)'],
      ['scope_missing', 'reconnect and allow "draft"'],
    ],
  }),
  scope: 'draft',
  input: {
    space_id: ulid('Blog (Space)'),
    title: z.string().trim().min(1).max(200).describe('The headline'),
    blocks: z.array(block).max(200).optional().describe('The body, in order'),
    cover_file_id: ulid('File').optional().describe('Cover photo (from list_files)'),
    needs_approval: z.boolean().optional().describe('The client approves it before it is published'),
  },
  annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
  notFound: { what: 'That Blog', next: 'Call list_spaces and pick a Space with type blog.' },
  run: async (args, { v1 }) => {
    const { space_id, ...body } = args;
    const { data } = await v1.post<{ data: Obj }>(`/spaces/${encodeURIComponent(space_id)}/posts`, body);
    return { post: shape(data) };
  },
});

export const updateBlogPost = defineTool({
  name: 'update_blog_post',
  title: 'Edit a Blog post',
  description: describeTool({
    does: 'Changes a Blog post’s title, cover, blocks or whether it needs client approval.',
    when: 'when the user asks to rewrite or fix a blog post, or to act on the client’s requested changes.',
    example: 'update_blog_post { "post_id": "01JB…", "blocks": [{ "type": "paragraph", "text": "…" }] } → { post }',
    rules: 'Only the fields given change; blocks replaces the whole body. A post the client sent back returns to draft when edited.',
    errors: [
      ['invalid_input', 'fix what details.errors names'],
      ['scope_missing', 'reconnect and allow "draft"'],
    ],
  }),
  scope: 'draft',
  input: {
    post_id: ulid('Blog post'),
    title: z.string().trim().min(1).max(200).optional(),
    blocks: z.array(block).max(200).optional(),
    cover_file_id: ulid('File').nullable().optional(),
    needs_approval: z.boolean().optional(),
  },
  annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false },
  notFound: { what: 'That Blog post', next: 'Call list_blog_posts for the post id.' },
  run: async (args, { v1 }) => {
    const { post_id, ...body } = args;
    const { data } = await v1.patch<{ data: Obj }>(`/blog-posts/${encodeURIComponent(post_id)}`, body);
    return { post: shape(data) };
  },
});

export const publishBlogPost = defineTool({
  name: 'publish_blog_post',
  title: 'Publish a Blog post',
  description: describeTool({
    does: 'Publishes a Blog post on the user’s public Blog.',
    when: 'only when the user asks to publish it now. A post that needs approval is published by the client’s approval instead.',
    example: 'publish_blog_post { "post_id": "01JB…" } → preview; then publish_blog_post { "post_id": "01JB…", "confirm": true } → { post: { status: "published", published_at } }',
    rules: 'Two steps: without confirm it only returns a preview (title, status) to show the user; only after they explicitly agree call again with confirm: true. Refused (invalid_input, reason needs_approval) for a post that needs the client’s approval and does not have it.',
    errors: [
      ['invalid_input', 'it needs the client’s approval first: use send_blog_post_for_approval'],
      ['scope_missing', 'reconnect and allow "publish"'],
    ],
  }),
  scope: 'publish',
  input: { post_id: ulid('Blog post'), confirm: z.boolean().default(false).describe('false (default) = preview only; true = publish, after the user agreed to the preview') },
  annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: true },
  notFound: { what: 'That Blog post', next: 'Call list_blog_posts for the post id.' },
  run: async (args, { v1 }) => {
    const id = encodeURIComponent(args.post_id);
    if (!args.confirm) {
      const { data } = await v1.get<{ data: Obj }>(`/blog-posts/${id}`);
      return {
        preview: { title: data.title, status: data.status, needs_approval: data.needs_approval },
        confirmation_required: true,
        next_step: 'Show the user the title and that it will be public on their Blog. Only if they explicitly agree, call publish_blog_post again with confirm: true.',
      };
    }
    return { post: shape((await v1.post<{ data: Obj }>(`/blog-posts/${id}/publish`, {})).data) };
  },
});

export const sendBlogPostForApproval = defineTool({
  name: 'send_blog_post_for_approval',
  title: 'Send a Blog post for approval',
  description: describeTool({
    does: 'Puts a Blog post on an approval link for the client; it is published to the Blog when they approve.',
    when: 'when the user wants a client to approve a blog post or press release first.',
    example: 'send_blog_post_for_approval { "post_id": "01JB…", "client_id": "01JK…" } → { post: { status: "waiting" }, approval_link: { id, url } }',
    rules: 'Give link_id (an approval link the user already has), or client_id (a client with an approver), or approver_email. Share the returned url with the client.',
    errors: [
      ['invalid_input', 'who approves? give client_id of a client with an approver, or approver_email'],
      ['scope_missing', 'reconnect and allow "approve"'],
    ],
  }),
  scope: 'approve',
  input: {
    post_id: ulid('Blog post'),
    link_id: ulid('Approval link').optional(),
    client_id: ulid('Client').optional(),
    approver_email: z.string().trim().email().max(254).optional(),
  },
  annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
  notFound: { what: 'That Blog post or link', next: 'Call list_blog_posts or list_approval_links for the ids.' },
  run: async (args, { v1 }) => {
    const { post_id, ...body } = args;
    const { data } = await v1.post<{ data: Obj & { approval_link?: Obj } }>(`/blog-posts/${encodeURIComponent(post_id)}/send-for-approval`, body);
    return { post: shape(data), approval_link: data.approval_link ?? null };
  },
});

export const setPostSpaces = defineTool({
  name: 'set_post_spaces',
  title: 'Also add a social post to a Gallery or Blog',
  description: describeTool({
    does: 'Chooses the Galleries and Blogs a social post also goes into when it is published (after approval if it needs one).',
    when: 'when the user says a post should also go in their portfolio, a gallery or a blog.',
    example: 'set_post_spaces { "post_id": "01JP…", "space_ids": ["01JS…"] } → { post_id, space_ids }',
    rules: 'Replaces the set; [] removes them all. A Gallery gets the post’s photos; a Blog gets a post made from the caption and photos. Space ids from list_spaces.',
    errors: [
      ['invalid_input', 'a Space is not the user’s: take ids from list_spaces'],
      ['scope_missing', 'reconnect and allow "draft"'],
    ],
  }),
  scope: 'draft',
  input: { post_id: ulid('Post'), space_ids: z.array(ulid('Space')).max(10) },
  annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
  notFound: { what: 'That post', next: 'Call list_posts for the post id.' },
  run: async (args, { v1 }) => (await v1.put<{ data: Obj }>(`/social-posts/${encodeURIComponent(args.post_id)}/spaces`, { space_ids: args.space_ids })).data,
});

export const blogTools = [listBlogPosts, createBlogPost, updateBlogPost, publishBlogPost, sendBlogPostForApproval, setPostSpaces];

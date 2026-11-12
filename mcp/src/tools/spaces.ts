import { z } from 'zod';
import { ToolError } from '../errors.js';
import { V1Error } from '../v1.js';
import { pageInput, pageOf } from './paging.js';
import { defineTool, describeTool, type ToolContext } from './define.js';
import { PreviewTokens } from './m2.js';

/**
 * Spaces: a page of the user's library files they share with a client by
 * link (creatif.space/{handle}/{slug}). Pro and Max only — for a Free
 * caller every Spaces route answers the API's 402 plan_required, which
 * reaches the model as the standard plan_required tool error.
 */

const ulid = (what: string) =>
  z.string().trim().min(10).max(26).describe(`${what} id, as returned by the list tools`);

interface V1SpaceCard {
  id: string;
  title: string;
  slug: string;
  status: 'draft' | 'published' | 'archived';
  visibility: 'private' | 'public';
  has_password: boolean;
  expires_at: string | null;
  items_count?: number;
  items?: unknown[];
  public_url: string | null;
  published_at: string | null;
  updated_at: string;
}

interface PublishPreview {
  space_id: string;
  title: string;
  status: string;
  publishable: boolean;
  url: string | null;
  visibility: 'private' | 'public';
  has_password: boolean;
  expires_at: string | null;
  items_count: number;
  active_slots: { limit: number | null; used: number; remaining: number | null };
  blocked: string[];
  warnings: string[];
  /** Signed by the API: user + Space + what goes live, 10 minutes. */
  confirm_token?: string;
  confirm_expires_at?: string;
}

/** What a visitor gets, in words the user can check. */
const VISIBILITY = {
  private: 'link only — anyone with the link can open it; it is not listed on the profile',
  public: 'public — anyone with the link can open it, and it is listed on the profile',
} as const;

const BLOCKED: Record<string, string> = {
  active_slots_full: 'every published-Space slot on this plan is in use — the user archives or unpublishes another Space in creatif.space first',
  read_only_role: 'this user’s role in the workspace cannot publish; the workspace owner or an editor can',
};

const WARNINGS: Record<string, string> = {
  no_handle: 'the account has no creatif.space username yet, so the Space will have no address until the user claims one in creatif.space → Profile',
  empty: 'the Space has no files yet',
  expired: 'its expiry date has passed, so visitors will see it as expired',
};

/** A Space as the model needs it: ids, state and, once live, its link. */
function shapeSpace(s: V1SpaceCard) {
  return {
    id: s.id,
    title: s.title,
    slug: s.slug,
    status: s.status,
    visibility: s.visibility,
    items_count: s.items_count ?? s.items?.length ?? 0,
    url: s.status === 'published' ? s.public_url : null,
    has_password: s.has_password,
    expires_at: s.expires_at,
    published_at: s.published_at,
    updated_at: s.updated_at,
  };
}

/**
 * The confirm_token of the last publish preview shown for a Space, kept
 * here so confirm: true publishes exactly what was previewed — the model
 * never handles the token. Keyed by user and Space.
 */
export const spacePreviewTokens = new PreviewTokens();

export const listSpaces = defineTool({
  name: 'list_spaces',
  title: 'List Spaces',
  description: describeTool({
    does: 'Lists the user’s creatif.space Spaces (shareable pages of library files), most recently changed first, with status (draft, published, archived), visibility, file count and the link of each published one.',
    when: 'to find a Space id before publish_space, or to see which Spaces are live and their links. To make a new one from library files, use create_space_from_files instead. Archived Spaces are listed only with status "archived".',
    example: 'list_spaces { "status": "draft" } → { count, spaces: [{ id, title, status, visibility, items_count, url, updated_at }] }',
    rules: 'Spaces are not included on every plan. url is set only for a published Space (and only once the account has a username).',
    errors: [
      ['plan_required', 'details.feature spaces — tell the user Spaces are not available on their plan, do not retry'],
      ['scope_missing', 'reconnect and allow "read"'],
      ['unauthorized', 'ask the user to reconnect the creatif.space connector'],
      ['upstream_unavailable', 'wait a minute and call again'],
    ],
  }),
  scope: 'read',
  input: {
    status: z.enum(['draft', 'published', 'archived']).optional().describe('Only Spaces in this state (default: drafts and published, not archived)'),
    search: z.string().trim().max(255).optional().describe('Match on the Space title'),
    ...pageInput(100, 50, 'Spaces'),
  },
  annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
  run: async (args, { v1 }) => {
    const { data } = await v1.get<{ data: V1SpaceCard[] }>('/spaces', {
      tab: args.status === 'archived' ? 'archived' : 'all',
      search: args.search,
    });
    const matching = data.filter((s) => !args.status || s.status === args.status);
    const { page, next_cursor } = pageOf('list_spaces', { status: args.status, search: args.search }, matching, args.limit, args.cursor);
    const spaces = page.map(shapeSpace);
    return { count: spaces.length, spaces, next_cursor };
  },
});

export const createSpaceFromFiles = defineTool({
  name: 'create_space_from_files',
  title: 'Create a draft Space from library files',
  description: describeTool({
    does: 'Creates a new DRAFT Space holding the given library files, in that order — a private draft nobody else can open; it is never published by this tool.',
    when: 'when the user wants to share a set of files (a shoot, a delivery, a portfolio) as one page. File ids come from list_files, or confirm_media_upload for a new upload. To put the Space live afterwards, use publish_space; to see existing Spaces, use list_spaces instead.',
    example: 'create_space_from_files { "title": "Winter shoot — selects", "file_ids": ["01JE…", "01JF…"] } → { space: { id, title, status: "draft", items_count }, note }',
    rules: 'Spaces are not included on every plan. 1 to 100 files, each a ready file in the user’s library; a repeated id is added once. If any id is not usable nothing is created. The design (sections, captions, cover) is edited in creatif.space.',
    errors: [
      ['file_not_ready', 'every id must be a ready library file: take ids from list_files, or confirm_media_upload first; nothing was created'],
      ['plan_required', 'details.feature spaces — pass the message to the user as it is, do not retry'],
      ['invalid_input', 'fix what details names (e.g. the plan’s total number of Spaces is reached: the user deletes one in creatif.space)'],
      ['scope_missing', 'reconnect and allow "draft"'],
      ['upstream_unavailable', 'check list_spaces before calling again — it may have been created'],
    ],
  }),
  scope: 'draft',
  input: {
    title: z.string().trim().min(1).max(255).describe('The Space title, shown to visitors'),
    file_ids: z.array(ulid('File')).min(1).max(100).describe('Library files to put in the Space, in order (1–100; ids from list_files)'),
    view_mode: z.enum(['editorial', 'grid', 'board']).optional().describe('Layout: editorial, grid or board (default: the account’s setting)'),
  },
  annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
  notFound: { what: 'A file', next: 'Check the file ids with list_files and call again.' },
  run: async (args, { v1 }) => {
    const { data } = await v1.post<{ data: V1SpaceCard }>('/spaces', {
      title: args.title,
      file_ids: [...new Set(args.file_ids)],
      ...(args.view_mode ? { view_mode: args.view_mode } : {}),
    });
    return {
      space: shapeSpace(data),
      note: 'Saved as a draft: nobody else can open it yet. To put it live, call publish_space with this id (it shows the user a preview first). The user can arrange it in creatif.space → Spaces.',
    };
  },
});

async function preview(ctx: ToolContext, spaceId: string): Promise<PublishPreview> {
  // A Free plan answers plan_required here, before the user is asked anything.
  const { data } = await ctx.v1.get<{ data: PublishPreview }>(`/spaces/${encodeURIComponent(spaceId)}/publish-preview`);
  // No token (blocked or already live): an older one must not stand in for it.
  if (data.confirm_token) spacePreviewTokens.remember(ctx.auth.extra.userUlid, spaceId, data);
  else spacePreviewTokens.forget(ctx.auth.extra.userUlid, spaceId);
  return data;
}

function previewResult(p: PublishPreview) {
  if (p.status === 'published') {
    return {
      already_published: true,
      space_id: p.space_id,
      title: p.title,
      url: p.url,
      next: 'It is already live; nothing to publish. Give the user the url.',
    };
  }
  const blocked = p.blocked.map((b) => BLOCKED[b] ?? b);
  const warnings = p.warnings.map((w) => WARNINGS[w] ?? w);
  return {
    confirmation_required: blocked.length === 0,
    action: 'publish_space',
    space_id: p.space_id,
    title: p.title,
    url: p.url,
    visibility: VISIBILITY[p.visibility] ?? p.visibility,
    password_protected: p.has_password,
    expires_at: p.expires_at,
    files: p.items_count,
    ...(p.active_slots.remaining !== null ? { published_slots_left: p.active_slots.remaining } : {}),
    ...(warnings.length ? { warnings } : {}),
    ...(blocked.length ? { blocked } : {}),
    next: blocked.length
      ? 'This cannot be published yet — tell the user what is blocking it.'
      : 'Show the user what goes live: the title, the url, who can open it, the password and expiry, and the number of files. Only if they explicitly agree, call publish_space again with the same space_id and confirm: true.',
  };
}

export const publishSpace = defineTool({
  name: 'publish_space',
  title: 'Publish a Space',
  description: describeTool({
    does: 'Publishes a draft Space so anyone with its link can open it, after the user agrees to a preview of the link, who can open it, password, expiry and file count.',
    when: 'only when the user wants the Space live now; create_space_from_files makes the draft and list_spaces finds ids. Two steps: call with confirm false (default) to get the preview; show it to the user, and only after they explicitly agree call again with confirm: true.',
    example: 'publish_space { "space_id": "01JS…" } → preview; then publish_space { "space_id": "01JS…", "confirm": true } → { space: { status: "published", url }, note }',
    rules: 'The confirm publishes exactly what that preview showed (a signed confirm_token kept by the server, valid 10 minutes). Spaces are not included on every plan; the plan sets how many can be published at once. Unpublishing is done in creatif.space.',
    errors: [
      ['preview_stale', 'nothing was published; show the user the new preview in details.preview and confirm again only if they agree'],
      ['plan_required', 'details.feature spaces — pass the message to the user as it is, do not retry'],
      ['invalid_input', 'every published-Space slot is in use: the user archives another Space in creatif.space first'],
      ['not_found', 'find the id with list_spaces'],
      ['scope_missing', 'reconnect and allow "publish"'],
      ['upstream_unavailable', 'check list_spaces before calling again — it may be live'],
    ],
  }),
  scope: 'publish',
  input: {
    space_id: ulid('Space'),
    confirm: z
      .boolean()
      .default(false)
      .describe('Leave false first: you get a preview (link, who can open it, files). Set true only after the user explicitly agreed to that preview.'),
  },
  annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: true },
  notFound: { what: 'That Space', next: 'Check the Space id with list_spaces and call again.' },
  run: async (args, ctx) => {
    if (!args.confirm) return previewResult(await preview(ctx, args.space_id));

    const user = ctx.auth.extra.userUlid;
    const token = spacePreviewTokens.take(user, args.space_id);
    if (!token) {
      const fresh = previewResult(await preview(ctx, args.space_id));
      return 'already_published' in fresh
        ? fresh
        : { ...fresh, note: 'No current preview of this Space had been shown (or it expired after 10 minutes), so nothing was published. Show the user this preview first.' };
    }
    try {
      const { data } = await ctx.v1.post<{ data: V1SpaceCard }>(`/spaces/${encodeURIComponent(args.space_id)}/publish`, { confirm_token: token });
      spacePreviewTokens.forget(user, args.space_id);
      return {
        space: shapeSpace(data),
        note: data.public_url
          ? 'Published. Share the url with the client.'
          : 'Published, but it has no address until the user claims a username in creatif.space → Profile.',
      };
    } catch (e) {
      if (e instanceof V1Error && e.status === 409 && e.body?.code === 'preview_stale') {
        spacePreviewTokens.forget(user, args.space_id);
        const fresh = previewResult(await preview(ctx, args.space_id));
        throw new ToolError(
          'preview_stale',
          'Nothing was published: the Space changed since the preview (title, address, visibility, password, expiry or files), or 10 minutes passed.',
          'Show the user the new preview in details.preview. Only if they agree again, call again with confirm: true.',
          { preview: fresh },
        );
      }
      throw e;
    }
  },
});

export const spaceTools = [listSpaces, createSpaceFromFiles, publishSpace];

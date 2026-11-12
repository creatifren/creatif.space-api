import { afterEach, describe, expect, it } from 'vitest';
import { post } from './fixtures.js';
import { payload, startApp, TOKEN_ALL, TOKEN_READ } from './helpers.js';

/*
 * discard_draft and list_approval_links over fakes of the V1 routes:
 * GET /social-posts/{id}, DELETE /social-posts/{id}/draft (204; 422 for
 * anything not a draft, like SocialPostController::discard) and
 * GET /social-approval-links (linkArray rows).
 */

const DRAFT = '01JPOSTPOSTPOSTPOSTPOSTDRF';
const SCHED = '01JPOSTPOSTPOSTPOSTPOSTSCH';
const LIVE = '01JPOSTPOSTPOSTPOSTPOSTPUB';
const FAIL = '01JPOSTPOSTPOSTPOSTPOSTFAL';

function fakePosts(opts: { approval?: string | null; raceToScheduled?: boolean } = {}) {
  const posts = new Map([
    [DRAFT, post(DRAFT, { status: 'draft', scheduled_at: null, approval: opts.approval ?? null })],
    [SCHED, post(SCHED, { status: 'scheduled' })],
    [LIVE, post(LIVE, { status: 'published' })],
    [FAIL, post(FAIL, { status: 'failed' })],
  ]);
  const routes: Record<string, () => { status?: number; body?: unknown }> = {};
  for (const [id, p] of posts) {
    routes[`GET /social-posts/${id}`] = () => (posts.has(id) ? { body: { data: p } } : { status: 404, body: { message: 'Not Found' } });
    routes[`DELETE /social-posts/${id}/draft`] = () => {
      if (!posts.has(id)) return { status: 404, body: { message: 'Not Found' } };
      if (p.status !== 'draft' || opts.raceToScheduled) return { status: 422, body: { message: 'Only a draft can be discarded.' } };
      posts.delete(id);
      return { status: 204 };
    };
  }
  return { routes, posts };
}

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

async function client(routes: Record<string, () => { status?: number; body?: unknown }>, token = TOKEN_ALL) {
  app = await startApp({ routes });
  return app.connect(token);
}

describe('discard_draft', () => {
  it('previews first and deletes nothing; confirm: true deletes the draft', async () => {
    const lib = fakePosts();
    const c = await client(lib.routes);

    const pre = await c.callTool({ name: 'discard_draft', arguments: { post_id: DRAFT } });
    expect(pre.isError).toBeFalsy();
    const out = payload(pre);
    expect(out.preview.post).toMatchObject({ id: DRAFT, status: 'draft', channels: [{ platform: 'instagram', username: 'studio' }], media_count: 1 });
    expect(out.preview.warnings).toEqual([]);
    expect(out.next_step).toMatch(/Nothing was deleted.*confirm: true/s);
    expect(app!.v1.calls.map((x) => x.method)).toEqual(['GET']);
    expect(lib.posts.has(DRAFT)).toBe(true);

    const done = await c.callTool({ name: 'discard_draft', arguments: { post_id: DRAFT, confirm: true } });
    expect(done.isError).toBeFalsy();
    expect(payload(done)).toMatchObject({ deleted: true, post_id: DRAFT });
    expect(app!.v1.calls.at(-1)).toMatchObject({ method: 'DELETE', path: `/social-posts/${DRAFT}/draft` });
    expect(app!.v1.calls.at(-1)!.headers.get('x-mcp-tool')).toBe('discard_draft');
    expect(lib.posts.has(DRAFT)).toBe(false);
  });

  it('warns when the draft is on an approval link', async () => {
    const out = payload(await (await client(fakePosts({ approval: 'client' }).routes)).callTool({ name: 'discard_draft', arguments: { post_id: DRAFT } }));
    expect(out.preview.warnings[0]).toMatch(/approval link/);
  });

  it.each([
    [SCHED, 'scheduled', /cancel it in creatif\.space.*update_post/s],
    [LIVE, 'published', /already published/],
    [FAIL, 'failed', /remove it in creatif\.space/],
  ])('refuses a %s post (%s) with not_draft and a next step, never calling DELETE', async (id, status, next) => {
    const c = await client(fakePosts().routes);
    for (const confirm of [false, true]) {
      const res = await c.callTool({ name: 'discard_draft', arguments: { post_id: id, confirm } });
      expect(res.isError).toBe(true);
      const { error } = payload(res);
      expect(error).toMatchObject({ code: 'not_draft', details: { post_id: id, status, deleted: false, tool: 'discard_draft' } });
      expect(error.next_step).toMatch(next);
    }
    expect(app!.v1.calls.every((x) => x.method === 'GET')).toBe(true);
  });

  it('a post that stopped being a draft before the delete (422) → not_draft', async () => {
    const { error } = payload(await (await client(fakePosts({ raceToScheduled: true }).routes)).callTool({ name: 'discard_draft', arguments: { post_id: DRAFT, confirm: true } }));
    expect(error).toMatchObject({ code: 'not_draft', details: { deleted: false } });
  });

  it('an unknown id → not_found pointing at list_posts', async () => {
    const { error } = payload(await (await client(fakePosts().routes)).callTool({ name: 'discard_draft', arguments: { post_id: '01JNOPENOPENOPENOPENOPENOP', confirm: true } }));
    expect(error).toMatchObject({ code: 'not_found', message: 'That post was not found in this workspace.' });
    expect(error.next_step).toMatch(/list_posts/);
  });

  it('a team seat (403 from the owner-only gate) → forbidden', async () => {
    const routes = { ...fakePosts().routes, [`DELETE /social-posts/${DRAFT}/draft`]: () => ({ status: 403, body: { message: 'This action is unauthorized.' } }) };
    const { error } = payload(await (await client(routes)).callTool({ name: 'discard_draft', arguments: { post_id: DRAFT, confirm: true } }));
    expect(error.code).toBe('forbidden');
  });

  it('needs the draft scope and calls nothing without it', async () => {
    const { error } = payload(await (await client(fakePosts().routes, TOKEN_READ)).callTool({ name: 'discard_draft', arguments: { post_id: DRAFT, confirm: true } }));
    expect(error).toMatchObject({ code: 'scope_missing', details: { required: 'draft' } });
    expect(app!.v1.calls).toHaveLength(0);
  });
});

const link = (id: string, status: string, extra: Record<string, unknown> = {}) => ({
  id,
  url: `https://creatif.space/a/${id}`,
  title: `Link ${id}`,
  client_name: 'Kopi Co',
  approver_email: 'client@example.com',
  approver_bound_at: null,
  kind: 'list',
  scope: null,
  posts: 3,
  status,
  counts: { approved: 1, waiting: 2, revision: 0 },
  by_state: { total: 3 },
  expires_at: '2026-11-19T00:00:00Z',
  extended_at: null,
  revoked_at: status === 'revoked' ? '2026-11-11T00:00:00Z' : null,
  created_at: '2026-11-10T00:00:00Z',
  ...extra,
});

describe('list_approval_links', () => {
  const routes = { 'GET /social-approval-links': () => ({ body: { data: [link('L1', 'active'), link('L2', 'expired'), link('L3', 'revoked'), link('L4', 'active', { approver_email: null })] } }) };

  it('lists open links by default, with status, approver email and post count', async () => {
    const res = await (await client(routes, TOKEN_READ)).callTool({ name: 'list_approval_links', arguments: {} });
    expect(res.isError).toBeFalsy();
    const out = payload(res);
    expect(out.total).toBe(2);
    expect(out.links.map((l: { id: string }) => l.id)).toEqual(['L1', 'L4']);
    expect(out.links[0]).toEqual({
      id: 'L1',
      title: 'Link L1',
      client_name: 'Kopi Co',
      approver_email: 'client@example.com',
      status: 'active',
      kind: 'list',
      post_count: 3,
      counts: { approved: 1, waiting: 2, revision: 0 },
      url: 'https://creatif.space/a/L1',
      expires_at: '2026-11-19T00:00:00Z',
      created_at: '2026-11-10T00:00:00Z',
    });
    expect(out.links[1].approver_email).toBeNull();
    expect(app!.v1.calls).toMatchObject([{ method: 'GET', path: '/social-approval-links' }]);
  });

  it('filters by status, "all" includes everything, and limit caps the list', async () => {
    const c = await client(routes);
    expect(payload(await c.callTool({ name: 'list_approval_links', arguments: { status: 'revoked' } })).links).toMatchObject([{ id: 'L3', revoked_at: '2026-11-11T00:00:00Z' }]);
    const all = payload(await c.callTool({ name: 'list_approval_links', arguments: { status: 'all', limit: 3 } }));
    expect(all.total).toBe(4);
    expect(all.links).toHaveLength(3);
  });

  it('descriptions follow describeTool and point at the neighbouring tools', async () => {
    const d = Object.fromEntries((await (await client(routes)).listTools()).tools.map((t) => [t.name, t.description!]));
    expect(d.list_approval_links).toMatch(/\nExample: list_approval_links \{/);
    expect(d.list_approval_links).toMatch(/check_approval_status with link_id/);
    expect(d.discard_draft).toMatch(/\nExample: discard_draft \{/);
    expect(d.discard_draft).toMatch(/not_draft → /);
    expect(d.discard_draft).toMatch(/use update_post/);
  });
});

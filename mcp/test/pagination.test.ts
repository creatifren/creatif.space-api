import { afterEach, describe, expect, it } from 'vitest';
import { account, post } from './fixtures.js';
import { payload, startApp, USER } from './helpers.js';

/*
 * Every list_* tool pages the same way: limit + cursor in, next_cursor out
 * (null at the end). Walking a list page by page returns each item once. A
 * cursor only works on the list, and the filters, that made it.
 */

const TOKEN = `stub:${USER}:read,draft,approve,publish,approvals:decide:Claude`;
const ulid = (prefix: string, n: number) => `01J${prefix}${String(n).padStart(23 - prefix.length, '0')}`;

type Route = (call: { query: Record<string, string> }) => { status?: number; body?: unknown };

/** For each tool: a fake V1 with `count` items, how to read ids from a page, and its list key. */
function world(count: number): Record<string, { routes: Record<string, Route>; ids: (out: Record<string, unknown[]>) => string[]; all: string[]; args?: Record<string, unknown> }> {
  const channels = Array.from({ length: count }, (_, i) => account(ulid('ACCT', i + 1)));
  const posts = Array.from({ length: count }, (_, i) => post(ulid('POST', i + 1)));
  const files = Array.from({ length: count }, (_, i) => ({ id: ulid('FILE', i + 1), name: `f${i}.jpg`, status: 'ready' }));
  const links = Array.from({ length: count }, (_, i) => ({ id: ulid('LINK', i + 1), status: 'active', posts: 1, counts: {} }));
  const spaces = Array.from({ length: count }, (_, i) => ({ id: ulid('SPACE', i + 1), title: `S${i}`, status: 'draft' }));
  const waiting = Array.from({ length: count }, (_, i) => ({ link: { id: ulid('WAIT', i + 1) }, waiting: 1, posts: [] }));
  const shared = Array.from({ length: count }, (_, i) => ({ report_id: ulid('SHARE', i + 1), kind: 'plan', title: 't', period: null, owner: { name: 'Bro' }, url: 'https://x' }));
  const byId = (k: string) => (out: Record<string, unknown[]>) => (out[k] as { id: string }[]).map((x) => x.id);

  return {
    list_channels: { routes: { 'GET /social-accounts': () => ({ body: { data: channels } }) }, ids: byId('channels'), all: channels.map((c) => c.id) },
    list_posts: {
      routes: {
        'GET /social-posts': ({ query }) => {
          const offset = Number(query.offset ?? 0);
          return { body: { data: posts.slice(offset, offset + Number(query.limit)) } };
        },
      },
      ids: byId('posts'),
      all: posts.map((p) => p.id),
    },
    list_files: {
      routes: {
        'GET /files': ({ query }) => {
          const per = Number(query.per_page);
          const page = Number(query.page ?? 1);
          return { body: { data: files.slice((page - 1) * per, page * per), meta: { total: files.length, current_page: page, last_page: Math.max(1, Math.ceil(files.length / per)) } } };
        },
      },
      ids: byId('files'),
      all: files.map((f) => f.id),
    },
    list_approval_links: { routes: { 'GET /social-approval-links': () => ({ body: { data: links } }) }, ids: byId('links'), all: links.map((l) => l.id) },
    list_spaces: { routes: { 'GET /spaces': () => ({ body: { data: spaces } }) }, ids: byId('spaces'), all: spaces.map((s) => s.id) },
    list_waiting_approvals: {
      routes: { 'GET /me/approvals/waiting': () => ({ body: { data: waiting } }) },
      ids: (out) => (out.waiting as { link: { id: string } }[]).map((w) => w.link.id),
      all: waiting.map((w) => w.link.id),
    },
    list_shared_with_me: { routes: { 'GET /me/reports/shared': () => ({ body: { data: shared } }) }, ids: byId('shared'), all: shared.map((s) => s.report_id) },
  };
}

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

describe('pagination', () => {
  it('every list_* tool takes limit and cursor', async () => {
    app = await startApp();
    const { tools } = await (await app.connect(TOKEN)).listTools();
    const lists = tools.filter((t) => t.name.startsWith('list_'));
    expect(lists.length).toBeGreaterThanOrEqual(8);
    for (const t of lists) {
      const props = (t.inputSchema as { properties: Record<string, unknown> }).properties;
      expect(props.limit, `${t.name} limit`).toBeDefined();
      expect(props.cursor, `${t.name} cursor`).toBeDefined();
    }
  });

  it.each(Object.keys(world(1)))('%s: walking the pages returns each item once, then next_cursor null', async (name) => {
    const w = world(7)[name]!;
    app = await startApp({ routes: w.routes as never });
    const c = await app.connect(TOKEN);
    const seen: string[] = [];
    let cursor: string | undefined;
    for (let i = 0; i < 10; i++) {
      const res = await c.callTool({ name, arguments: { ...(w.args ?? {}), limit: 3, ...(cursor ? { cursor } : {}) } });
      expect(res.isError, JSON.stringify(res.content)).toBeFalsy();
      const out = payload(res);
      expect(out).toHaveProperty('next_cursor');
      seen.push(...w.ids(out));
      if (out.next_cursor === null) break;
      expect(typeof out.next_cursor).toBe('string');
      cursor = out.next_cursor;
    }
    expect(seen).toEqual(w.all);
  });

  it('a single short page has next_cursor null', async () => {
    const w = world(2).list_channels!;
    app = await startApp({ routes: w.routes as never });
    const out = payload(await (await app.connect(TOKEN)).callTool({ name: 'list_channels', arguments: {} }));
    expect(out.next_cursor).toBeNull();
    expect(out.channels).toHaveLength(2);
  });

  it('list_posts asks the API for one extra row and passes the offset', async () => {
    const w = world(7).list_posts!;
    app = await startApp({ routes: w.routes as never });
    const c = await app.connect(TOKEN);
    const first = payload(await c.callTool({ name: 'list_posts', arguments: { limit: 3, status: 'scheduled' } }));
    await c.callTool({ name: 'list_posts', arguments: { limit: 3, status: 'scheduled', cursor: first.next_cursor } });
    expect(app.v1.calls.map((x) => x.query)).toEqual([
      { status: 'scheduled', limit: '4' },
      { status: 'scheduled', limit: '4', offset: '3' },
    ]);
  });

  it('refuses a cursor from another list, a changed filter, or garbage — with a next step', async () => {
    const w = world(7);
    app = await startApp({ routes: { ...w.list_channels!.routes, ...w.list_spaces!.routes, ...w.list_posts!.routes } as never });
    const c = await app.connect(TOKEN);
    const chCursor = payload(await c.callTool({ name: 'list_channels', arguments: { limit: 2 } })).next_cursor;

    const other = payload(await c.callTool({ name: 'list_spaces', arguments: { cursor: chCursor } }));
    expect(other.error).toMatchObject({ code: 'invalid_input', details: { field: 'cursor', reason: 'bad_cursor' } });
    expect(other.error.next_step).toMatch(/without cursor/);

    const postCursor = payload(await c.callTool({ name: 'list_posts', arguments: { limit: 2, status: 'draft' } })).next_cursor;
    const changed = payload(await c.callTool({ name: 'list_posts', arguments: { limit: 2, status: 'scheduled', cursor: postCursor } }));
    expect(changed.error).toMatchObject({ code: 'invalid_input', details: { reason: 'filters_changed' } });

    const garbage = payload(await c.callTool({ name: 'list_channels', arguments: { cursor: 'not-a-cursor' } }));
    expect(garbage.error).toMatchObject({ code: 'invalid_input', details: { reason: 'bad_cursor' } });
  });
});

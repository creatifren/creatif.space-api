import { afterEach, describe, expect, it } from 'vitest';
import { post } from './fixtures.js';
import { payload, startApp, TOKEN_ALL } from './helpers.js';

/*
 * list_posts filters, one test each: the API statuses pass straight
 * through; waiting and changes_requested are approval states of queued
 * posts (read as status=scheduled, kept by approval); from/to take a date
 * or an ISO time.
 */

const id = (n: number) => `01JPOSTPOSTPOSTPOSTPOST${String(n).padStart(3, '0')}`;

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

/** A fake GET /social-posts over `rows`, honouring status, limit and offset like the API. */
async function list(args: Record<string, unknown>, rows: ReturnType<typeof post>[]) {
  app = await startApp({
    routes: {
      'GET /social-posts': (req: { query: Record<string, string> }) => {
        const q = req.query;
        const matching = rows.filter((r) => !q.status || r.status === q.status);
        const off = Number(q.offset ?? 0);
        return { body: { data: matching.slice(off, off + Number(q.limit ?? 100)) } };
      },
    } as never,
  });
  const res = await (await app.connect(TOKEN_ALL)).callTool({ name: 'list_posts', arguments: args });
  return { res, out: payload(res), queries: app.v1.calls.map((c) => c.query) };
}

const rows = [
  post(id(1), { status: 'draft', scheduled_at: null }),
  post(id(2), { status: 'scheduled', approval: 'client' }),
  post(id(3), { status: 'scheduled', approval: 'awaiting' }),
  post(id(4), { status: 'scheduled', approval: 'revision' }),
  post(id(5), { status: 'scheduled', approval: null }),
  post(id(6), { status: 'scheduled', approval: 'approved' }),
  post(id(7), { status: 'published' }),
  post(id(8), { status: 'failed' }),
];
const ids = (out: { posts: { id: string }[] }) => out.posts.map((p) => p.id);

describe('list_posts filters', () => {
  it('status draft → the API status draft', async () => {
    const { out, queries } = await list({ status: 'draft' }, rows);
    expect(queries[0]).toMatchObject({ status: 'draft' });
    expect(ids(out)).toEqual([id(1)]);
  });

  it('status waiting → queued posts held for the client or the user (client, awaiting)', async () => {
    const { out, queries } = await list({ status: 'waiting' }, rows);
    expect(queries[0]).toMatchObject({ status: 'scheduled', limit: '100' });
    expect(ids(out)).toEqual([id(2), id(3)]);
    expect(out.posts.map((p: { approval: string }) => p.approval)).toEqual(['client', 'awaiting']);
    expect(out.next_cursor).toBeNull();
  });

  it('status changes_requested → queued posts a client sent back (revision)', async () => {
    const { out, queries } = await list({ status: 'changes_requested' }, rows);
    expect(queries[0]).toMatchObject({ status: 'scheduled' });
    expect(ids(out)).toEqual([id(4)]);
  });

  it('status scheduled → the API status scheduled, held posts included', async () => {
    const { out, queries } = await list({ status: 'scheduled' }, rows);
    expect(queries[0]).toMatchObject({ status: 'scheduled' });
    expect(ids(out)).toEqual([id(2), id(3), id(4), id(5), id(6)]);
  });

  it('status published → the API status published', async () => {
    const { out, queries } = await list({ status: 'published' }, rows);
    expect(queries[0]).toMatchObject({ status: 'published' });
    expect(ids(out)).toEqual([id(7)]);
  });

  it('status failed → the API status failed', async () => {
    const { out, queries } = await list({ status: 'failed' }, rows);
    expect(queries[0]).toMatchObject({ status: 'failed' });
    expect(ids(out)).toEqual([id(8)]);
  });

  it('from as a date → the start of that day (UTC); as a time → passed as it is', async () => {
    const day = await list({ from: '2026-11-01' }, rows);
    expect(day.queries[0]).toMatchObject({ from: '2026-11-01T00:00:00Z' });
    await app!.close();
    const time = await list({ from: '2026-11-01T08:00:00+07:00' }, rows);
    expect(time.queries[0]).toMatchObject({ from: '2026-11-01T08:00:00+07:00' });
  });

  it('to as a date → the end of that day (UTC); from after to → invalid_input, no API call', async () => {
    const day = await list({ to: '2026-11-30' }, rows);
    expect(day.queries[0]).toMatchObject({ to: '2026-11-30T23:59:59Z' });
    await app!.close();
    const bad = await list({ from: '2026-12-01', to: '2026-11-30' }, rows);
    expect(bad.res.isError).toBe(true);
    expect(bad.out.error).toMatchObject({ code: 'invalid_input', details: { field: 'from' } });
    expect(bad.queries).toHaveLength(0);
  });

  it('waiting pages over the raw rows: the cursor resumes right after the last post shown', async () => {
    const many = Array.from({ length: 7 }, (_, i) => post(id(20 + i), { status: 'scheduled', approval: i % 2 === 0 ? 'client' : null }));
    const first = await list({ status: 'waiting', limit: 2 }, many);
    expect(ids(first.out)).toEqual([id(20), id(22)]);
    expect(first.out.next_cursor).toEqual(expect.any(String));
    await app!.close();
    const second = await list({ status: 'waiting', limit: 2, cursor: first.out.next_cursor }, many);
    expect(ids(second.out)).toEqual([id(24), id(26)]);
    expect(second.out.next_cursor).toBeNull();
  });
});

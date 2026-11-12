import { afterEach, describe, expect, it } from 'vitest';
import { payload, startApp, TOKEN_ALL, TOKEN_READ } from './helpers.js';

/*
 * get_storage_usage / keep_file / unkeep_file over a fake of the V1 file
 * routes that answers the way FileController + MediaQuota do: usage per tier,
 * Keep is paid and quota-bound (402 quota_exceeded keep_quota), 409 for a file
 * that is not ready, 404 outside the workspace.
 */

const BILLING = 'https://creatif.space/settings?section=subscription';
const FILES = 'https://creatif.space/files';
const GB = 1024 ** 3;
const id = (n: number) => `01JFILE${String(n).padStart(19, '0')}`;

interface F { id: string; name: string; size_bytes: number; status: string; is_kept: boolean; auto_delete_at: string | null }

function fakeFiles(opts: { tier?: 'free' | 'pro' | 'max'; files?: F[]; keepUsed?: number } = {}) {
  const tier = opts.tier ?? 'pro';
  const files = new Map((opts.files ?? []).map((f) => [f.id, { ...f }]));
  const quota = tier === 'max' ? 50 * GB : 25 * GB;
  let keepUsed = opts.keepUsed ?? 0;
  const out = (f: F) => ({ ...f, mime_type: 'image/jpeg', url: `https://r2.test/${f.id}.jpg` });

  const handle = (key: string, query: Record<string, string>): { status?: number; body?: unknown } => {
    if (key === 'GET /files/usage') {
      return { body: { data: tier === 'free' ? { tier, used_bytes: 900 * 1024 ** 2, quota_bytes: GB } : { tier, keep_used_bytes: keepUsed, keep_quota_bytes: quota } } };
    }
    if (key === 'GET /files') {
      const all = [...files.values()];
      const per = Number(query.per_page ?? 60);
      const page = Number(query.page ?? 1);
      const last = Math.max(1, Math.ceil(all.length / per));
      return { body: { data: all.slice((page - 1) * per, page * per).map(out), meta: { current_page: page, last_page: last, total: all.length } } };
    }
    let m = /^POST \/files\/([^/]+)\/keep$/.exec(key);
    if (m) {
      const f = files.get(m[1]!);
      if (!f) return { status: 404, body: { message: 'Not Found' } };
      if (f.status !== 'ready') return { status: 409, body: { message: 'Conflict' } };
      if (f.is_kept) return { body: { data: out(f) } };
      if (tier === 'free') {
        return { status: 402, body: { message: 'Keep is on Pro and Max. Upgrade to keep files.', reason: 'quota_exceeded', feature: 'keep_quota', next_step: 'upgrade_pro', billing_url: BILLING, files_url: FILES } };
      }
      if (keepUsed + f.size_bytes > quota) {
        return tier === 'pro'
          ? { status: 402, body: { message: 'Your Keep storage is full. Upgrade to Max for more.', reason: 'quota_exceeded', feature: 'keep_quota', next_step: 'upgrade_max', billing_url: BILLING, files_url: FILES } }
          : { status: 402, body: { message: 'Your Keep storage is full. Contact us for more storage.', reason: 'quota_exceeded', feature: 'keep_quota', next_step: 'contact_sales', files_url: FILES } };
      }
      keepUsed += f.size_bytes;
      Object.assign(f, { is_kept: true, auto_delete_at: null });
      return { body: { data: out(f) } };
    }
    m = /^DELETE \/files\/([^/]+)\/keep$/.exec(key);
    if (m) {
      const f = files.get(m[1]!);
      if (!f) return { status: 404, body: { message: 'Not Found' } };
      if (f.is_kept) keepUsed -= f.size_bytes;
      Object.assign(f, { is_kept: false, auto_delete_at: '2026-12-12T09:00:00Z' });
      return { body: { data: out(f) } };
    }
    return { status: 404, body: { message: 'Not Found' } };
  };

  const routes = new Proxy({} as Record<string, (call: { query: Record<string, string> }) => { status?: number; body?: unknown }>, {
    get: (_t, key: string) => (call: { query: Record<string, string> }) => handle(key, call.query),
  });
  return { routes, files };
}

const file = (n: number, extra: Partial<F> = {}): F => ({ id: id(n), name: `f${n}.jpg`, size_bytes: 1000, status: 'ready', is_kept: false, auto_delete_at: null, ...extra });

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

async function client(lib: ReturnType<typeof fakeFiles>, token = TOKEN_ALL) {
  app = await startApp({ routes: lib.routes });
  return app.connect(token);
}

describe('get_storage_usage', () => {
  it('paid: Keep used vs quota, and kept vs auto-delete with the soonest first', async () => {
    const lib = fakeFiles({
      tier: 'pro',
      keepUsed: 3000,
      files: [
        file(1, { is_kept: true, size_bytes: 3000 }),
        file(2, { auto_delete_at: '2026-12-20T00:00:00Z', size_bytes: 200 }),
        file(3, { auto_delete_at: '2026-12-01T00:00:00Z', size_bytes: 100 }),
        file(4, { size_bytes: 50 }),
        file(5, { status: 'pending' }),
      ],
    });
    const res = await (await client(lib, TOKEN_READ)).callTool({ name: 'get_storage_usage', arguments: {} });
    expect(res.isError).toBeFalsy();
    const out = payload(res);
    expect(out.tier).toBe('pro');
    expect(out.quota).toMatchObject({ used_bytes: 3000, quota_bytes: 25 * GB, counts: expect.stringMatching(/kept files plus media on drafts/) });
    expect(out.files).toMatchObject({
      kept: { count: 1, bytes: 3000 },
      auto_delete: { count: 2, bytes: 300, next_at: '2026-12-01T00:00:00Z' },
      no_auto_delete: { count: 1, bytes: 50 },
      scanned: 5,
      complete: true,
    });
    expect(out.files.auto_delete.soonest.map((f: F) => f.id)).toEqual([id(3), id(2)]);
    expect(out.next_step).toMatch(/keep_file/);
    expect(app!.v1.calls.map((c) => c.path)).toEqual(['/files/usage', '/files']);
    expect(app!.v1.calls[1]!.query).toMatchObject({ per_page: '60', page: '1' });
  });

  it('Free: used vs 1 GB, nearly full → next step to free up space; Keep is not offered', async () => {
    const out = payload(await (await client(fakeFiles({ tier: 'free', files: [file(1, { auto_delete_at: '2026-11-13T00:00:00Z' })] }))).callTool({ name: 'get_storage_usage', arguments: {} }));
    expect(out.quota).toMatchObject({ used_bytes: 900 * 1024 ** 2, quota_bytes: GB, percent_used: 87.9 });
    expect(out.next_step).toMatch(/Keep is on Pro and Max/);
    expect(out.next_step).not.toMatch(/keep_file stops/);
  });

  it('reads at most 5 pages and says when the split is not complete', async () => {
    const many = Array.from({ length: 400 }, (_, i) => file(i + 1));
    const out = payload(await (await client(fakeFiles({ files: many }))).callTool({ name: 'get_storage_usage', arguments: {} }));
    expect(out.files).toMatchObject({ scanned: 300, complete: false });
    expect(app!.v1.calls.filter((c) => c.path === '/files')).toHaveLength(5);
  });

  it('no next_step when nothing needs doing', async () => {
    const out = payload(await (await client(fakeFiles({ files: [file(1)] }))).callTool({ name: 'get_storage_usage', arguments: {} }));
    expect(out.next_step).toBeUndefined();
  });
});

describe('keep_file / unkeep_file', () => {
  it('keeps a file, is idempotent, and unkeeps it with the deletion date', async () => {
    const lib = fakeFiles({ files: [file(1, { auto_delete_at: '2026-12-01T00:00:00Z' })] });
    const c = await client(lib);

    const kept = await c.callTool({ name: 'keep_file', arguments: { file_id: id(1) } });
    expect(kept.isError).toBeFalsy();
    expect(payload(kept).file).toMatchObject({ id: id(1), is_kept: true, auto_delete_at: null });
    expect(app!.v1.calls.at(-1)).toMatchObject({ method: 'POST', path: `/files/${id(1)}/keep` });
    expect(app!.v1.calls.at(-1)!.headers.get('x-mcp-tool')).toBe('keep_file');

    expect((await c.callTool({ name: 'keep_file', arguments: { file_id: id(1) } })).isError).toBeFalsy();

    const un = payload(await c.callTool({ name: 'unkeep_file', arguments: { file_id: id(1) } }));
    expect(app!.v1.calls.at(-1)).toMatchObject({ method: 'DELETE', path: `/files/${id(1)}/keep` });
    expect(un.file).toMatchObject({ is_kept: false, auto_delete_at: '2026-12-12T09:00:00Z' });
    expect(un.next_step).toContain('2026-12-12T09:00:00Z');
  });

  it('Free → plan_required (Keep is paid), not storage_full', async () => {
    const { error } = payload(await (await client(fakeFiles({ tier: 'free', files: [file(1)] }))).callTool({ name: 'keep_file', arguments: { file_id: id(1) } }));
    expect(error).toMatchObject({ code: 'plan_required', message: 'Keep is on Pro and Max. Upgrade to keep files.', details: { feature: 'keep_quota', next_step: 'upgrade_pro', billing_url: BILLING } });
    expect(error.next_step).toContain(BILLING);
    expect(error.next_step).toMatch(/Do not retry/);
  });

  it('Pro Keep quota full (402) → storage_full: unkeep or upgrade to Max', async () => {
    const lib = fakeFiles({ tier: 'pro', keepUsed: 25 * GB, files: [file(1)] });
    const { error } = payload(await (await client(lib)).callTool({ name: 'keep_file', arguments: { file_id: id(1) } }));
    expect(error).toMatchObject({ code: 'storage_full', message: 'Your Keep storage is full. Upgrade to Max for more.' });
    expect(error.next_step).toMatch(/un-Keep/);
    expect(error.next_step).toContain(`upgrade to Max: ${BILLING}`);
  });

  it('Max Keep quota full → storage_full that says contact creatif.space', async () => {
    const lib = fakeFiles({ tier: 'max', keepUsed: 50 * GB, files: [file(1)] });
    const { error } = payload(await (await client(lib)).callTool({ name: 'keep_file', arguments: { file_id: id(1) } }));
    expect(error.code).toBe('storage_full');
    expect(error.next_step).toContain('contact creatif.space');
  });

  it('a file that is not ready (409) → file_not_ready pointing at confirm_media_upload', async () => {
    const { error } = payload(await (await client(fakeFiles({ files: [file(1, { status: 'pending' })] }))).callTool({ name: 'keep_file', arguments: { file_id: id(1) } }));
    expect(error).toMatchObject({ code: 'file_not_ready', details: { file_id: id(1), tool: 'keep_file' } });
    expect(error.next_step).toMatch(/confirm_media_upload/);
  });

  it('an unknown or foreign id → not_found pointing at list_files', async () => {
    const c = await client(fakeFiles());
    for (const name of ['keep_file', 'unkeep_file']) {
      const { error } = payload(await c.callTool({ name, arguments: { file_id: id(9) } }));
      expect(error).toMatchObject({ code: 'not_found', message: 'That file was not found in this workspace.' });
      expect(error.next_step).toMatch(/list_files/);
    }
  });

  it('keep/unkeep need draft; usage works with read only', async () => {
    const c = await client(fakeFiles({ files: [file(1)] }), TOKEN_READ);
    for (const name of ['keep_file', 'unkeep_file']) {
      expect(payload(await c.callTool({ name, arguments: { file_id: id(1) } })).error).toMatchObject({ code: 'scope_missing', details: { required: 'draft' } });
    }
    expect(app!.v1.calls).toHaveLength(0);
  });

  it('descriptions follow describeTool and name the quota errors', async () => {
    const c = await client(fakeFiles());
    const d = Object.fromEntries((await c.listTools()).tools.map((t) => [t.name, t.description!]));
    for (const name of ['get_storage_usage', 'keep_file', 'unkeep_file']) {
      expect(d[name]).toMatch(new RegExp(`\\nExample: ${name} \\{`));
    }
    expect(d.keep_file).toMatch(/plan_required → /);
    expect(d.keep_file).toMatch(/storage_full → .*unkeep_file/);
    expect(d.get_storage_usage).toMatch(/use keep_file/);
  });
});

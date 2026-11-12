import { afterEach, describe, expect, it } from 'vitest';
import { OUTPUT_SCHEMAS } from '../src/tools/outputs.js';
import { account, post } from './fixtures.js';
import { payload, startApp, USER } from './helpers.js';

/*
 * outputSchema + structuredContent for the 10 most-used read tools. The SDK
 * checks structuredContent against the schema on the server and the client;
 * the same JSON stays in the text block for older clients.
 */

const TOKEN = `stub:${USER}:read,draft,approve,publish,approvals:decide:Claude`;
const IG = '01JACCTACCTACCTACCTACCTIG1';
const POST = '01JPOSTPOSTPOSTPOSTPOSTPO1';
const LINK = '01JLINKLINKLINKLINKLINKLI1';
const FILE = '01JFILEFILEFILEFILEFILEFI1';
const SPACE = '01JSPACESPACESPACESPACESP1';

const TOOLS = ['list_channels', 'list_posts', 'get_post', 'get_credits', 'list_waiting_approvals', 'check_approval_status', 'get_insight_summary', 'list_files', 'list_spaces', 'get_storage_usage'];

const file = { id: FILE, name: 'launch.jpg', mime_type: 'image/jpeg', size_bytes: 1000, status: 'ready', url: 'https://r2.test/f.jpg', is_kept: true, auto_delete_at: null, created_at: '2026-11-01T00:00:00Z', brand_new_field: 'the API may add fields' };

/** One realistic V1 answer per tool, and the arguments to call it with. */
const CASES: Record<string, { args: Record<string, unknown>; routes: Record<string, () => { status?: number; body?: unknown }> }> = {
  list_channels: { args: {}, routes: { 'GET /social-accounts': () => ({ body: { data: [account(IG), account('01JACCTACCTACCTACCTACCTXX1', 'x', { status: 'expired' })] } }) } },
  list_posts: { args: {}, routes: { 'GET /social-posts': () => ({ body: { data: [post(POST), post('01JPOSTPOSTPOSTPOSTPOSTPO2', { status: 'draft', scheduled_at: null })] } }) } },
  get_post: { args: { post_id: POST }, routes: { [`GET /social-posts/${POST}`]: () => ({ body: { data: { ...post(POST), comments: [] } } }) } },
  get_credits: {
    args: {},
    routes: {
      'GET /credits': () => ({
        body: { data: { plan: 'pro', committed: { sends: 0, sends_monthly: 300 }, limits: { plan: { key: 'pro', name: 'Pro' }, credits: { monthly: 1, topup: 2, resets_at: '2026-12-01T00:00:00+00:00' }, spend: { since: '2026-11-01T00:00:00+00:00', by_action: { publish: 900, reply: 0, ai: 0, total: 900 } } } } },
      }),
    },
  },
  list_waiting_approvals: {
    args: {},
    routes: { 'GET /me/approvals/waiting': () => ({ body: { data: [{ link: { id: LINK, url: 'https://creatif.space/a/t', title: 'December', editor: 'Bro', kind: 'plan', expires_at: null }, waiting: 1, posts: [{ id: POST }] }] } }) },
  },
  check_approval_status: {
    args: { link_id: LINK },
    routes: { [`GET /social-approval-links/${LINK}`]: () => ({ body: { data: { link: { id: LINK, status: 'active' }, posts: [post(POST, { approval: 'client' })] } } }) },
  },
  get_insight_summary: {
    args: { period: { month: '2026-10' } },
    routes: { 'GET /insight/summary': () => ({ body: { data: { period: { from: '2026-10-01', to: '2026-10-31' }, totals: { posts: 3 }, channels: [{ id: IG, saves: null }], notes: [] } } }) },
  },
  list_files: { args: {}, routes: { 'GET /files': () => ({ body: { data: [file], meta: { total: 1 } } }) } },
  list_spaces: { args: {}, routes: { 'GET /spaces': () => ({ body: { data: [{ id: SPACE, title: 'Winter', slug: 'winter', status: 'draft', visibility: 'private', items_count: 2, public_url: null, has_password: false, expires_at: null, published_at: null, updated_at: '2026-11-10T09:00:00Z' }] } }) } },
  get_storage_usage: {
    args: {},
    routes: {
      'GET /files/usage': () => ({ body: { data: { tier: 'pro', keep_used_bytes: 1000, keep_quota_bytes: 25 * 1024 ** 3 } } }),
      'GET /files': () => ({ body: { data: [file], meta: { last_page: 1 } } }),
    },
  },
};

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

describe('outputSchema', () => {
  it('is declared on exactly the 10 read tools, as an object schema', async () => {
    expect(Object.keys(OUTPUT_SCHEMAS).sort()).toEqual([...TOOLS].sort());
    app = await startApp();
    const { tools } = await (await app.connect(TOKEN)).listTools();
    const withSchema = tools.filter((t) => t.outputSchema).map((t) => t.name).sort();
    expect(withSchema).toEqual([...TOOLS].sort());
    for (const t of tools.filter((x) => x.outputSchema)) {
      expect(t.outputSchema!.type, t.name).toBe('object');
      expect(Object.keys(t.outputSchema!.properties ?? {}).length, t.name).toBeGreaterThan(0);
      expect(t.annotations?.readOnlyHint, t.name).toBe(true);
    }
  });

  it.each(TOOLS)('%s returns structuredContent equal to the text block', async (name) => {
    const c = CASES[name]!;
    app = await startApp({ routes: c.routes });
    const res = await (await app.connect(TOKEN)).callTool({ name, arguments: c.args });
    expect(res.isError, JSON.stringify(res.content)).toBeFalsy();
    expect(res.structuredContent).toBeDefined();
    expect(res.structuredContent).toEqual(payload(res));
  });

  it('lets fields the API adds through, inside items and at the top level', async () => {
    app = await startApp({ routes: CASES.list_files!.routes });
    const res = await (await app.connect(TOKEN)).callTool({ name: 'list_files', arguments: {} });
    expect(res.isError).toBeFalsy();
    expect((res.structuredContent as { files: unknown[] }).files).toHaveLength(1);
  });

  it('an error result carries no structuredContent — the {code, message, next_step, details} envelope is in the text', async () => {
    app = await startApp({ routes: { 'GET /social-accounts': () => ({ status: 401, body: { message: 'Unauthenticated.' } }) } });
    const res = await (await app.connect(TOKEN)).callTool({ name: 'list_channels', arguments: {} });
    expect(res.isError).toBe(true);
    expect(res.structuredContent).toBeUndefined();
    expect(payload(res).error.code).toBe('unauthorized');
  });

  it('tools without a schema keep the text block only', async () => {
    app = await startApp({ routes: { 'GET /social-approval-links': () => ({ body: { data: [] } }) } });
    const res = await (await app.connect(TOKEN)).callTool({ name: 'list_approval_links', arguments: {} });
    expect(res.isError).toBeFalsy();
    expect(res.structuredContent).toBeUndefined();
    expect(payload(res)).toMatchObject({ total: 0, links: [] });
  });
});

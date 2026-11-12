import { afterEach, describe, expect, it } from 'vitest';
import { payload, startApp, TOKEN_FULL, USER } from './helpers.js';

const POST = '01JPOSTPOSTPOSTPOSTPOSTPO1';
const ROUTE = `GET /social-posts/${POST}/approval-audit`;
const TOKEN_DRAFT = `stub:${USER}:draft:Claude`;

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

const start = async (routes: Record<string, unknown>, token = TOKEN_FULL) => {
  app = await startApp({ routes: routes as never });
  return app.connect(token);
};

const decision = {
  decided_at: '2026-11-20T03:00:00Z', decision: 'changes_requested',
  approver: { name: 'Rani', email: 'r***@c***.test' }, comment: 'Swap the photo', source: 'web',
  link: { id: '01JLINKLINKLINKLINKLINKLI1', title: 'November', kind: 'list' },
};

describe('get_approval_history', () => {
  it('returns the post’s decisions as the API gives them, with a count', async () => {
    const client = await start({ [ROUTE]: () => ({ body: { data: { post_id: POST, approval: 'revision', decisions: [decision] } } }) });
    const out = payload(await client.callTool({ name: 'get_approval_history', arguments: { post_id: POST } }));
    expect(out).toMatchObject({ post_id: POST, approval: 'revision', count: 1, decisions: [decision] });
    expect(out.note).toMatch(/masked/);
    expect(app!.v1.calls[0]).toMatchObject({ method: 'GET', path: `/social-posts/${POST}/approval-audit` });
  });

  it('says so when nobody decided yet', async () => {
    const client = await start({ [ROUTE]: () => ({ body: { data: { post_id: POST, approval: 'client', decisions: [] } } }) });
    expect(payload(await client.callTool({ name: 'get_approval_history', arguments: { post_id: POST } })).note).toMatch(/Nobody has decided/);
  });

  it('another workspace’s post (404) is not_found pointing at list_posts; needs read', async () => {
    let client = await start({});
    const err = payload(await client.callTool({ name: 'get_approval_history', arguments: { post_id: POST } })).error;
    expect(err).toMatchObject({ code: 'not_found', message: expect.any(String), next_step: expect.stringContaining('list_posts') });
    await app!.close();

    client = await start({}, TOKEN_DRAFT);
    const scope = payload(await client.callTool({ name: 'get_approval_history', arguments: { post_id: POST } })).error;
    expect(scope).toMatchObject({ code: 'scope_missing', details: { required: 'read' } });
    expect(app!.v1.calls).toHaveLength(0);
  });
});

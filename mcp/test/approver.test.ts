import { afterEach, describe, expect, it } from 'vitest';
import { decisionTokens } from '../src/tools/approver.js';
import { protectedResourceMetadata } from '../src/auth/middleware.js';
import { StubIntrospector } from '../src/auth/introspection.js';
import { payload, startApp, TOKEN_FULL, USER } from './helpers.js';

const POST = '01JPOSTPOSTPOSTPOSTPOSTPO1';
const LINK = '01JLINKLINKLINKLINKLINKLI1';
const TOKEN_DECIDE = `stub:${USER}:read,approvals:decide:Claude`;

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
  decisionTokens.clear();
});

const PREVIEW = `GET /me/approvals/${POST}/preview`;
const DECIDE = `POST /me/approvals/${POST}/decision`;

const preview = (extra: Record<string, unknown> = {}) => ({
  data: {
    link: { id: LINK, title: 'December', editor: 'Bro' },
    post: { id: POST, caption: 'Launch day', scheduled_at: '2026-12-05T03:00:00Z', channels: [{ platform: 'instagram', handle: 'brand' }], media: 1, state: 'waiting' },
    decision: 'approve',
    reason: null,
    decidable: true,
    decidable_from: null,
    already: false,
    effect: 'The post is approved and goes out at its scheduled time on these channels.',
    confirm_token: 'ctok.sig',
    confirm_expires_at: new Date(Date.now() + 600_000).toISOString(),
    ...extra,
  },
});

const start = async (routes: Record<string, unknown>, token = TOKEN_DECIDE) => {
  app = await startApp({ routes: routes as never });
  return app.connect(token);
};

describe('scope approvals:decide', () => {
  it('is advertised and parsed from tokens (it holds a colon)', async () => {
    expect(protectedResourceMetadata('https://mcp.test/mcp', 'https://api.test').scopes_supported).toContain('approvals:decide');
    const info = await new StubIntrospector().introspect(TOKEN_DECIDE);
    expect(info).toMatchObject({ active: true, scopes: ['read', 'approvals:decide'], client_name: 'Claude' });
  });

  it('is required by both tools', async () => {
    const client = await start({}, TOKEN_FULL);
    const res = await client.callTool({ name: 'list_waiting_approvals', arguments: {} });
    expect(payload(res).error).toMatchObject({ code: 'scope_missing', details: { required: 'approvals:decide' } });
    const res2 = await client.callTool({ name: 'decide_approval', arguments: { link: LINK, post_id: POST, decision: 'approve' } });
    expect(payload(res2).error.code).toBe('scope_missing');
    expect(app!.v1.calls).toHaveLength(0);
  });
});

describe('list_waiting_approvals', () => {
  it('lists what is waiting on the user, with the link id to decide with', async () => {
    const client = await start({
      'GET /me/approvals/waiting': () => ({
        body: { data: [{ link: { id: LINK, url: 'https://creatif.space/a/tok', title: 'December', editor: 'Bro', kind: 'plan', expires_at: null }, waiting: 1, posts: [{ id: POST, caption: 'Launch day', scheduled_at: 'x', approval: 'client', decidable: true }] }] },
      }),
    });
    const out = payload(await client.callTool({ name: 'list_waiting_approvals', arguments: {} }));
    expect(out.waiting[0].link).toMatchObject({ id: LINK, from: 'Bro', kind: 'plan' });
    expect(out.waiting[0].posts[0]).toMatchObject({ id: POST, decidable: true });
  });

  it('says so when nothing is waiting', async () => {
    const client = await start({ 'GET /me/approvals/waiting': () => ({ body: { data: [] } }) });
    expect(payload(await client.callTool({ name: 'list_waiting_approvals', arguments: {} })).note).toMatch(/Nothing is waiting/);
  });
});

describe('decide_approval', () => {
  it('previews first and decides only on confirm, with the preview token the model never sees', async () => {
    const client = await start({
      [PREVIEW]: () => ({ body: preview() }),
      [DECIDE]: () => ({ body: { data: { id: POST, state: 'approved', approval: 'approved' } } }),
    });
    const args = { link: LINK, post_id: POST, decision: 'approve' };

    const shown = payload(await client.callTool({ name: 'decide_approval', arguments: args }));
    expect(shown).toMatchObject({ confirmation_required: true, decision: 'approve', post: { id: POST } });
    expect(JSON.stringify(shown)).not.toContain('ctok.sig');
    expect(app!.v1.calls.map((c) => c.method)).toEqual(['GET']);
    expect(app!.v1.calls[0]!.query).toEqual({ link: LINK, decision: 'approve' });

    const done = payload(await client.callTool({ name: 'decide_approval', arguments: { ...args, confirm: true } }));
    expect(done).toMatchObject({ decided: true, state: 'approved' });
    expect(app!.v1.calls[1]!.body).toEqual({ link: LINK, decision: 'approve', confirm_token: 'ctok.sig' });
  });

  it('never decides on confirm without a preview shown — it shows one instead', async () => {
    const client = await start({ [PREVIEW]: () => ({ body: preview() }) });
    const out = payload(await client.callTool({ name: 'decide_approval', arguments: { link: LINK, post_id: POST, decision: 'approve', confirm: true } }));
    expect(out.note).toMatch(/nothing was decided/);
    expect(app!.v1.calls.map((c) => c.method)).toEqual(['GET']);
  });

  it('needs a reason to request changes, and sends it', async () => {
    const client = await start({
      [PREVIEW]: () => ({ body: preview({ decision: 'revise', reason: 'Brighter' }) }),
      [DECIDE]: () => ({ body: { data: { id: POST, state: 'changes_requested', approval: 'revision' } } }),
    });
    const missing = payload(await client.callTool({ name: 'decide_approval', arguments: { link: LINK, post_id: POST, decision: 'revise' } }));
    expect(missing.error.code).toBe('invalid_input');

    const args = { link: LINK, post_id: POST, decision: 'revise', reason: 'Brighter' };
    await client.callTool({ name: 'decide_approval', arguments: args });
    await client.callTool({ name: 'decide_approval', arguments: { ...args, confirm: true } });
    expect(app!.v1.calls.at(-1)!.body).toEqual({ link: LINK, decision: 'revise', reason: 'Brighter', confirm_token: 'ctok.sig' });
  });

  it('a preview for one decision does not confirm another', async () => {
    const client = await start({ [PREVIEW]: () => ({ body: preview() }) });
    await client.callTool({ name: 'decide_approval', arguments: { link: LINK, post_id: POST, decision: 'approve' } });
    const other = payload(await client.callTool({ name: 'decide_approval', arguments: { link: LINK, post_id: POST, decision: 'revise', reason: 'x', confirm: true } }));
    expect(other.note).toMatch(/nothing was decided/);
    expect(app!.v1.calls.filter((c) => c.method === 'POST')).toHaveLength(0);
  });

  it('reports a post that cannot be decided yet (Free window) without a confirm step', async () => {
    const client = await start({ [PREVIEW]: () => ({ body: preview({ decidable: false, decidable_from: '2026-11-28T03:00:00Z', confirm_token: null }) }) });
    const out = payload(await client.callTool({ name: 'decide_approval', arguments: { link: LINK, post_id: POST, decision: 'approve' } }));
    expect(out).toMatchObject({ confirmation_required: false });
    expect(out.blocked).toMatch(/2026-11-28T03:00:00Z/);
  });

  it('turns a stale preview into preview_stale with the new preview, deciding nothing', async () => {
    const client = await start({
      [PREVIEW]: () => ({ body: preview() }),
      [DECIDE]: () => ({ status: 409, body: { message: 'changed', code: 'preview_stale' } }),
    });
    const args = { link: LINK, post_id: POST, decision: 'approve' };
    await client.callTool({ name: 'decide_approval', arguments: args });
    const out = payload(await client.callTool({ name: 'decide_approval', arguments: { ...args, confirm: true } }));
    expect(out.error.code).toBe('preview_stale');
    expect(out.error.details.preview.post.id).toBe(POST);
  });

  it('maps the API refusals: someone else’s link, and the Free window', async () => {
    const client = await start({
      [PREVIEW]: () => ({ status: 403, body: { message: 'Different address', reason: 'approver_mismatch', expected_hint: 'r***@c***.com' } }),
    });
    const out = payload(await client.callTool({ name: 'decide_approval', arguments: { link: LINK, post_id: POST, decision: 'approve' } }));
    expect(out.error).toMatchObject({ code: 'forbidden', details: { expected_hint: 'r***@c***.com' } });
  });
});

describe('list_shared_with_me', () => {
  it('lists Content Plans shared with the user, with who sent them and the url', async () => {
    const client = await start({
      'GET /me/reports/shared': () => ({
        body: { data: [{
          report_id: LINK, kind: 'content_plan', title: 'December',
          period: { from: '2026-12-01', to: '2026-12-31', timezone: 'Asia/Jakarta' },
          owner: { name: 'Bro' }, url: 'https://creatif.space/a/tok', posts: 6, waiting: 2, expires_at: null,
        }] },
      }),
    });
    const out = payload(await client.callTool({ name: 'list_shared_with_me', arguments: {} }));
    expect(out.shared[0]).toEqual({
      id: LINK, kind: 'content_plan', title: 'December',
      period: { from: '2026-12-01', to: '2026-12-31', timezone: 'Asia/Jakarta' },
      from: 'Bro', url: 'https://creatif.space/a/tok', posts: 6, waiting: 2, expires_at: null,
    });
    expect(out.note).toMatch(/list_waiting_approvals/);
  });

  it('lists Insight reports shared with the user, without plan-only fields', async () => {
    const client = await start({
      'GET /me/reports/shared': () => ({
        body: { data: [{
          report_id: '01JINSIGHTINSIGHTINSIGHTIN', kind: 'insight', title: 'Insight report — 1 Oct – 31 Oct 2026',
          period: { from: '2026-10-01', to: '2026-10-31' }, owner: { name: 'Kopi Senja' },
          url: 'https://app.creatif.test/report/tok', expires_at: '2026-12-01T00:00:00Z',
        }] },
      }),
    });
    const out = payload(await client.callTool({ name: 'list_shared_with_me', arguments: {} }));
    expect(out.shared[0]).toEqual({
      id: '01JINSIGHTINSIGHTINSIGHTIN', kind: 'insight', title: 'Insight report — 1 Oct – 31 Oct 2026',
      period: { from: '2026-10-01', to: '2026-10-31' }, from: 'Kopi Senja',
      url: 'https://app.creatif.test/report/tok', expires_at: '2026-12-01T00:00:00Z',
    });
  });

  it('says so when nothing is shared', async () => {
    const client = await start({ 'GET /me/reports/shared': () => ({ body: { data: [] } }) });
    expect(payload(await client.callTool({ name: 'list_shared_with_me', arguments: {} })).note).toMatch(/Nothing has been shared/);
  });

  it('needs approvals:decide', async () => {
    const client = await start({}, TOKEN_FULL);
    const res = await client.callTool({ name: 'list_shared_with_me', arguments: {} });
    expect(payload(res).error).toMatchObject({ code: 'scope_missing', details: { required: 'approvals:decide' } });
    expect(app!.v1.calls).toHaveLength(0);
  });

  it('turns a 401 into the error envelope with a next step', async () => {
    const client = await start({ 'GET /me/reports/shared': () => ({ status: 401, body: { message: 'Unauthenticated.' } }) });
    const err = payload(await client.callTool({ name: 'list_shared_with_me', arguments: {} })).error;
    expect(err).toMatchObject({ code: expect.any(String), message: expect.any(String), next_step: expect.any(String) });
  });
});

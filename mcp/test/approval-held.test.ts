import { afterEach, describe, expect, it } from 'vitest';
import { decisionTokens } from '../src/tools/approver.js';
import { post } from './fixtures.js';
import { payload, startApp, TOKEN_ALL, TOKEN_FULL, USER } from './helpers.js';

/*
 * B3 (blackbox 13 Nov): after create_approval_link a draft is held as
 * "scheduled" with no time. It now reads waiting_for_approval, discard_draft
 * can take it away, revoke_approval_link withdraws the link, and approving a
 * post with no time is refused instead of publishing it at once.
 */

const P = '01JPOSTPOSTPOSTPOSTPOSTPO1';
const LINK = '01JLINKLINKLINKLINKLINKLI1';
const TOKEN_DECIDE = `stub:${USER}:read,approvals:decide:Claude`;

const held = (extra: Record<string, unknown> = {}) => post(P, { status: 'scheduled', approval: 'client', scheduled_at: null, ...extra });

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
  decisionTokens.clear();
});

const open = async (routes: Record<string, unknown>, token = TOKEN_FULL) => {
  app = await startApp({ routes: routes as never });
  return app.connect(token);
};
const writes = () => app!.v1.calls.filter((c) => c.method !== 'GET').map((c) => `${c.method} ${c.path}`);

describe('a post on an approval link reads as waiting, not scheduled', () => {
  it('get_post: waiting_for_approval, and needs_time when it has no time', async () => {
    const client = await open({ [`GET /social-posts/${P}`]: () => ({ body: { data: held() } }) }, TOKEN_ALL);
    const out = payload(await client.callTool({ name: 'get_post', arguments: { post_id: P } })).post;
    expect(out.status).toBe('waiting_for_approval');
    expect(out.approval).toBe('client');
    expect(out.needs_time).toMatch(/update_post scheduled_at/);
  });

  it('list_posts: changes_requested when sent back; a plain scheduled post stays "scheduled", with no needs_time', async () => {
    const client = await open({
      'GET /social-posts': () => ({ body: { data: [held({ approval: 'revision', scheduled_at: '2026-12-01T03:00:00Z' }), post('01JPOSTPOSTPOSTPOSTPOSTPO2')] } }),
    }, TOKEN_ALL);
    const out = payload(await client.callTool({ name: 'list_posts', arguments: {} }));
    expect(out.posts.map((p: { status: string }) => p.status)).toEqual(['changes_requested', 'scheduled']);
    expect(out.posts.every((p: Record<string, unknown>) => !('needs_time' in p))).toBe(true);
  });
});

describe('discard_draft takes a held post off its link', () => {
  const routes = {
    [`GET /social-posts/${P}`]: () => ({ body: { data: held() } }),
    [`DELETE /social-posts/${P}/draft`]: () => ({ status: 204, body: null }),
  };

  it('previews with a warning, deletes only on confirm', async () => {
    const client = await open(routes, TOKEN_ALL);
    const shown = payload(await client.callTool({ name: 'discard_draft', arguments: { post_id: P } }));
    expect(shown.preview.post.status).toBe('waiting_for_approval');
    expect(shown.preview.warnings[0]).toMatch(/removes it from that link/);
    expect(writes()).toEqual([]);

    const done = payload(await client.callTool({ name: 'discard_draft', arguments: { post_id: P, confirm: true } }));
    expect(done).toMatchObject({ deleted: true, post_id: P });
    expect(writes()).toEqual([`DELETE /social-posts/${P}/draft`]);
  });

  it('still refuses an approved post, with nothing deleted', async () => {
    const client = await open({ [`GET /social-posts/${P}`]: () => ({ body: { data: held({ approval: 'approved', scheduled_at: '2026-12-01T03:00:00Z' }) } }) }, TOKEN_ALL);
    const res = await client.callTool({ name: 'discard_draft', arguments: { post_id: P, confirm: true } });
    expect(res.isError).toBe(true);
    expect(payload(res).error).toMatchObject({ code: 'not_draft', details: { status: 'scheduled', deleted: false } });
    expect(writes()).toEqual([]);
  });
});

describe('revoke_approval_link', () => {
  const link = (status: string) => ({
    body: {
      data: {
        link: { id: LINK, url: 'https://creatif.space/a/tok', title: 'December', client_name: null, approver_email: 'rani@client.test', kind: 'list', posts: 2, status, counts: { approved: 0, waiting: 2, revision: 0 }, expires_at: null, revoked_at: null, created_at: null },
        posts: [],
      },
    },
  });

  it('previews the link, revokes only on confirm (scope approve, destructive)', async () => {
    const client = await open({ [`GET /social-approval-links/${LINK}`]: () => link('active'), [`DELETE /social-approval-links/${LINK}`]: () => ({ status: 204, body: null }) });
    const tool = (await client.listTools()).tools.find((t) => t.name === 'revoke_approval_link')!;
    expect(tool.annotations).toMatchObject({ destructiveHint: true, readOnlyHint: false });

    const shown = payload(await client.callTool({ name: 'revoke_approval_link', arguments: { link_id: LINK } }));
    expect(shown).toMatchObject({ confirmation_required: true, preview: { link: { id: LINK, approver_email: 'rani@client.test', post_count: 2 } } });
    expect(writes()).toEqual([]);

    const done = payload(await client.callTool({ name: 'revoke_approval_link', arguments: { link_id: LINK, confirm: true } }));
    expect(done).toMatchObject({ revoked: true, link_id: LINK });
    expect(writes()).toEqual([`DELETE /social-approval-links/${LINK}`]);
  });

  it('an already revoked link: nothing to do, nothing sent', async () => {
    const client = await open({ [`GET /social-approval-links/${LINK}`]: () => link('revoked') });
    const out = payload(await client.callTool({ name: 'revoke_approval_link', arguments: { link_id: LINK, confirm: true } }));
    expect(out).toMatchObject({ revoked: true, already: true });
    expect(writes()).toEqual([]);
  });

  it('needs the approve scope', async () => {
    const client = await open({}, TOKEN_ALL);
    const res = await client.callTool({ name: 'revoke_approval_link', arguments: { link_id: LINK } });
    expect(payload(res).error.code).toBe('scope_missing');
  });
});

describe('decide_approval never publishes a post at once', () => {
  const PREVIEW = `GET /me/approvals/${P}/preview`;
  const DECIDE = `POST /me/approvals/${P}/decision`;
  const preview = (extra: Record<string, unknown> = {}) => ({
    body: {
      data: {
        link: { id: LINK, title: 'December', editor: 'Bro' },
        post: { id: P, caption: 'Launch', scheduled_at: '2026-12-05T03:00:00+00:00', channels: [], media: 0, state: 'waiting' },
        decision: 'approve', reason: null, decidable: true, decidable_from: null, already: false, needs_time: false,
        effect: 'The post is approved and goes out at its scheduled time.', confirm_token: 'ctok.sig',
        confirm_expires_at: new Date(Date.now() + 600_000).toISOString(),
        ...extra,
      },
    },
  });
  const args = { link: LINK, post_id: P, decision: 'approve' };

  it('a post with no time: the preview is blocked and says why', async () => {
    const client = await open({ [PREVIEW]: () => preview({ needs_time: true, confirm_token: null, post: { id: P, caption: 'Launch', scheduled_at: null, channels: [], media: 0, state: 'waiting' } }) }, TOKEN_DECIDE);
    const out = payload(await client.callTool({ name: 'decide_approval', arguments: args }));
    expect(out.confirmation_required).toBe(false);
    expect(out.blocked).toMatch(/no publish time yet.*Bro has to set a time first/);
  });

  it('a 422 needs_time on confirm is the needs_time error, nothing decided', async () => {
    const client = await open({
      [PREVIEW]: () => preview(),
      [DECIDE]: () => ({ status: 422, body: { message: 'This post has no publish time yet…', reason: 'needs_time' } }),
    }, TOKEN_DECIDE);
    await client.callTool({ name: 'decide_approval', arguments: args });
    const res = await client.callTool({ name: 'decide_approval', arguments: { ...args, confirm: true } });
    expect(res.isError).toBe(true);
    expect(payload(res).error).toMatchObject({ code: 'needs_time', details: { post_id: P } });
  });

  it('approved: returns the planned time — it goes out then, not now', async () => {
    const client = await open({
      [PREVIEW]: () => preview(),
      [DECIDE]: () => ({ body: { data: { id: P, state: 'approved', approval: 'approved', scheduled_at: '2026-12-05T03:00:00+00:00' } } }),
    }, TOKEN_DECIDE);
    await client.callTool({ name: 'decide_approval', arguments: args });
    const out = payload(await client.callTool({ name: 'decide_approval', arguments: { ...args, confirm: true } }));
    expect(out).toMatchObject({ decided: true, scheduled_at: '2026-12-05T03:00:00+00:00' });
    expect(out.note).toMatch(/2026-12-05T03:00:00\+00:00\), not now/);
  });
});

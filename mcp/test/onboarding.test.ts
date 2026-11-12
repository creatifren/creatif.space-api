import { afterEach, describe, expect, it } from 'vitest';
import { payload, startApp, TOKEN_ALL, TOKEN_FULL } from './helpers.js';

/* get_onboarding over GET /me/onboarding: the four steps, done/at, and the one next step to suggest. */

const DRAFT = '01JPOSTPOSTPOSTPOSTPOSTPO1';
const AT = '2026-11-01T09:00:00+00:00';
const step = (done: boolean) => ({ done, at: done ? AT : null });
const answer = (done: [boolean, boolean, boolean, boolean], draft: string | null = null) => ({
  data: { connect: step(done[0]), draft: step(done[1]), send: step(done[2]), publish: step(done[3]), draft_post_id: draft },
});

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

async function call(body: unknown, token = TOKEN_ALL) {
  app = await startApp({ routes: { 'GET /me/onboarding': () => ({ body }) } });
  return (await app.connect(token)).callTool({ name: 'get_onboarding', arguments: {} });
}

describe('get_onboarding', () => {
  it('a new workspace: all four steps open, next is connecting a channel', async () => {
    const res = await call(answer([false, false, false, false]));
    const out = payload(res);
    expect(res.isError).toBeFalsy();
    expect(out.steps).toEqual([
      { key: 'connect', label: 'Connect a channel', done: false, at: null },
      { key: 'draft', label: 'Make a post', done: false, at: null },
      { key: 'send', label: 'Send a post to a client', done: false, at: null },
      { key: 'publish', label: 'Publish a post', done: false, at: null },
    ]);
    expect(out).toMatchObject({ done_count: 0, all_done: false, draft_post_id: null, next: { step: 'connect', tool: 'get_connect_link' } });
    expect(res.structuredContent).toEqual(out);
    expect(app!.v1.calls.map((c) => `${c.method} ${c.path}`)).toEqual(['GET /me/onboarding']);
  });

  it('a step done carries when it happened; next is the first step not done', async () => {
    const out = payload(await call(answer([true, false, false, false])));
    expect(out.steps[0]).toEqual({ key: 'connect', label: 'Connect a channel', done: true, at: AT });
    expect(out).toMatchObject({ done_count: 1, next: { step: 'draft', tool: 'draft_post' } });
    expect(out.next.suggest).toMatch(/draft_post.*nothing is sent/);
  });

  it('send: names the newest draft for create_approval_link; without a draft, offers to make one', async () => {
    const withDraft = payload(await call(answer([true, true, false, false], DRAFT)));
    expect(withDraft.next).toMatchObject({ step: 'send', tool: 'create_approval_link' });
    expect(withDraft.next.suggest).toContain(DRAFT);
    await app!.close();
    const noDraft = payload(await call(answer([true, true, false, false])));
    expect(noDraft.next).toMatchObject({ step: 'send', tool: 'draft_post' });
  });

  it('publish: a preview-first send of the newest draft', async () => {
    const out = payload(await call(answer([true, true, true, false], DRAFT)));
    expect(out.next).toMatchObject({ step: 'publish', tool: 'schedule_post' });
    expect(out.next.suggest).toMatch(/preview/);
  });

  it('all done: nothing to suggest from the checklist', async () => {
    const out = payload(await call(answer([true, true, true, true])));
    expect(out).toMatchObject({ done_count: 4, all_done: true, next: { step: null, tool: null } });
  });

  it('is read-only, needs only "read", and never writes', async () => {
    app = await startApp({ routes: { 'GET /me/onboarding': () => ({ body: answer([false, false, false, false]) }) } });
    const client = await app.connect(TOKEN_FULL.replace(/read,draft,approve,publish/, 'read'));
    const { tools } = await client.listTools();
    expect(tools.find((t) => t.name === 'get_onboarding')!.annotations).toMatchObject({ readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false });
    const res = await client.callTool({ name: 'get_onboarding', arguments: {} });
    expect(res.isError).toBeFalsy();
    expect(app!.v1.calls.every((c) => c.method === 'GET')).toBe(true);
  });
});

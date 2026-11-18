import { afterEach, describe, expect, it } from 'vitest';
import { batchPreviews } from '../src/tools/scheduleBatch.js';
import { previewTokens } from '../src/tools/m2.js';
import { post } from './fixtures.js';
import { payload, startApp, TOKEN_FULL } from './helpers.js';

/*
 * Bug b202611140812: a Pin sent from a connected app failed with
 * missing_board, and no tool could name a board. list_pinterest_boards lists
 * them; pinterest.board_id on the post tools sets the board of the post's
 * Pinterest channel; a missing board says which tool to call.
 */

const PIN = '01JACCTACCTACCTACCTACCTPN1';
const IG = '01JACCTACCTACCTACCTACCTIG1';
const POST = '01JPOSTPOSTPOSTPOSTPOSTPO1';
const POST2 = '01JPOSTPOSTPOSTPOSTPOSTPO2';

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
  previewTokens.clear();
  batchPreviews.clear();
});

const start = async (routes: Record<string, unknown>) => {
  app = await startApp({ routes: routes as never });
  return app.connect(TOKEN_FULL);
};
const callsTo = (method: string, path: string) => app!.v1.calls.filter((c) => c.method === method && c.path === path);

const pinPost = (id: string) =>
  post(id, {
    status: 'draft',
    scheduled_at: null,
    targets: [
      { account: { id: IG, platform: 'instagram', username: 'studio' }, status: 'pending', fail_reason: null, platform_url: null },
      { account: { id: PIN, platform: 'pinterest', username: 'studio_pins' }, status: 'pending', fail_reason: null, platform_url: null },
    ],
  });

const preview = (id: string, problems: unknown[] = []) => ({
  body: {
    data: {
      post_id: id, sendable: true, status: 'draft', scheduled_at: null,
      targets: [{ channel_id: PIN, platform: 'pinterest', username: 'studio_pins', usable: true, credits: 15 }],
      problems,
      credits: { price: 15, from_topup: 0, monthly_balance: 300, topup_balance: 0, can_afford: true },
      daily_cap: { cap: 20, used: 0, remaining: 20 },
      confirm_token: 'tok.sig', confirm_expires_at: new Date(Date.now() + 600_000).toISOString(),
    },
  },
});

const MISSING = { account_id: PIN, platform: 'pinterest', code: 'missing_board', reason: 'Pinterest needs a board for this Pin. Choose a board and send again.' };

describe('list_pinterest_boards', () => {
  it('lists the channel’s boards and marks the one it used last', async () => {
    const c = await start({
      [`GET /social-accounts/${PIN}/pinterest-boards`]: () => ({ body: { data: [{ id: 'b1', name: 'Recipes' }, { id: 'b2', name: 'Travel' }], meta: { default_board_id: 'b2' } } }),
    });
    const out = payload(await c.callTool({ name: 'list_pinterest_boards', arguments: { account_id: PIN } }));
    expect(out.boards).toEqual([{ id: 'b1', name: 'Recipes', default: false }, { id: 'b2', name: 'Travel', default: true }]);
    expect(out.default_board_id).toBe('b2');
    expect(out.next_cursor).toBeNull();
    expect(out.next_step).toContain('pinterest: { "board_id"');
  });

  it('says plainly when the channel is not a Pinterest channel', async () => {
    const c = await start({ [`GET /social-accounts/${IG}/pinterest-boards`]: () => ({ status: 422, body: { message: 'Only a Pinterest channel has boards.' } }) });
    const res = await c.callTool({ name: 'list_pinterest_boards', arguments: { account_id: IG } });
    expect(res.isError).toBe(true);
    expect(payload(res).error).toMatchObject({ code: 'invalid_input', details: { field: 'account_id' } });
  });

  it('is read-only and closed-world', async () => {
    const c = await start({});
    const tool = (await c.listTools()).tools.find((t) => t.name === 'list_pinterest_boards')!;
    expect(tool.title).toBe('List Pinterest boards');
    expect(tool.annotations).toMatchObject({ readOnlyHint: true, destructiveHint: false, openWorldHint: false });
  });
});

describe('pinterest.board_id on the post tools', () => {
  it('draft_post: sends the board as options for the Pinterest channel only', async () => {
    const c = await start({
      'GET /social-accounts': () => ({ body: { data: [{ id: IG, platform: 'instagram' }, { id: PIN, platform: 'pinterest' }] } }),
      'POST /social-posts': () => ({ status: 201, body: { data: pinPost(POST) } }),
    });
    const res = await c.callTool({ name: 'draft_post', arguments: { caption: 'Pin it', channel_ids: [IG, PIN], media_urls: ['https://cdn.test/a.jpg'], pinterest: { board_id: 'b2' } } });
    expect(res.isError, JSON.stringify(res.content)).toBeFalsy();
    expect((callsTo('POST', '/social-posts')[0]!.body as { options: unknown }).options).toEqual({ [PIN]: { board_id: 'b2' } });
  });

  it('draft_post: refuses a board when no channel is a Pinterest channel, and saves nothing', async () => {
    const c = await start({ 'GET /social-accounts': () => ({ body: { data: [{ id: IG, platform: 'instagram' }] } }) });
    const res = await c.callTool({ name: 'draft_post', arguments: { caption: 'Hi', channel_ids: [IG], pinterest: { board_id: 'b2' } } });
    expect(payload(res).error).toMatchObject({ code: 'invalid_input', details: { field: 'pinterest.board_id' } });
    expect(callsTo('POST', '/social-posts')).toHaveLength(0);
  });

  it('update_post: sets the board alone', async () => {
    const c = await start({
      [`GET /social-posts/${POST}`]: () => ({ body: { data: pinPost(POST) } }),
      [`PATCH /social-posts/${POST}`]: () => ({ body: { data: pinPost(POST) } }),
    });
    const res = await c.callTool({ name: 'update_post', arguments: { post_id: POST, pinterest: { board_id: 'b1' } } });
    expect(res.isError, JSON.stringify(res.content)).toBeFalsy();
    expect(callsTo('PATCH', `/social-posts/${POST}`)[0]!.body).toEqual({ options: { [PIN]: { board_id: 'b1' } } });
  });

  it('publish_post: sets the board on the draft before the preview, then sends', async () => {
    const c = await start({
      [`GET /social-posts/${POST}`]: () => ({ body: { data: pinPost(POST) } }),
      [`PATCH /social-posts/${POST}`]: () => ({ body: { data: pinPost(POST) } }),
      [`GET /social-posts/${POST}/send-preview`]: () => preview(POST),
      [`POST /social-posts/${POST}/send`]: () => ({ body: { data: pinPost(POST) } }),
    });
    const shown = payload(await c.callTool({ name: 'publish_post', arguments: { post_id: POST, pinterest: { board_id: 'b1' } } }));
    expect(shown.confirmation_required).toBe(true);
    expect(shown.blocked).toBeUndefined();
    const order = app!.v1.calls.map((x) => `${x.method} ${x.path}`);
    expect(order.indexOf(`PATCH /social-posts/${POST}`)).toBeLessThan(order.indexOf(`GET /social-posts/${POST}/send-preview`));

    const sent = await c.callTool({ name: 'publish_post', arguments: { post_id: POST, pinterest: { board_id: 'b1' }, confirm: true } });
    expect(sent.isError, JSON.stringify(sent.content)).toBeFalsy();
    expect(callsTo('POST', `/social-posts/${POST}/send`)).toHaveLength(1);
  });

  it('the preview flags a missing board before anything is confirmed, naming list_pinterest_boards', async () => {
    const c = await start({ [`GET /social-posts/${POST}/send-preview`]: () => preview(POST, [MISSING]) });
    const shown = payload(await c.callTool({ name: 'schedule_post', arguments: { post_id: POST, scheduled_at: '2030-01-04T10:00:00Z' } }));
    expect(shown.blocked.join(' ')).toContain(`list_pinterest_boards { "account_id": "${PIN}" }`);
    expect(shown.blocked.join(' ')).toContain('schedule_post again with pinterest');
    expect(shown.next).toContain('cannot go out yet');
  });

  it('a missing_board refusal at send says which tool and field fix it', async () => {
    const c = await start({
      [`GET /social-posts/${POST}/send-preview`]: () => preview(POST),
      [`POST /social-posts/${POST}/send`]: () => ({
        status: 422,
        body: { message: MISSING.reason, code: 'publish_preflight_failed', problems: [MISSING], errors: { account_ids: [MISSING.reason] } },
      }),
    });
    await c.callTool({ name: 'publish_post', arguments: { post_id: POST } });
    const res = await c.callTool({ name: 'publish_post', arguments: { post_id: POST, confirm: true } });
    const { error } = payload(res);
    expect(error.code).toBe('invalid_input');
    expect(error.message).toBe(MISSING.reason);
    expect(error.next_step).toContain('list_pinterest_boards');
    expect(error.next_step).toContain('pinterest: { "board_id"');
    expect(error.details).toMatchObject({ reason: 'missing_board', channel_id: PIN });
  });

  it('schedule_posts: sets each post’s board before the one preview', async () => {
    const c = await start({
      [`GET /social-posts/${POST}`]: () => ({ body: { data: pinPost(POST) } }),
      [`PATCH /social-posts/${POST}`]: () => ({ body: { data: pinPost(POST) } }),
      [`GET /social-posts/${POST}/send-preview`]: () => preview(POST),
      [`GET /social-posts/${POST2}/send-preview`]: () => preview(POST2),
    });
    const res = await c.callTool({
      name: 'schedule_posts',
      arguments: { posts: [{ post_id: POST, scheduled_at: '2030-01-04T10:00:00Z', pinterest: { board_id: 'b1' } }, { post_id: POST2, scheduled_at: '2030-01-05T10:00:00Z' }] },
    });
    expect(res.isError, JSON.stringify(res.content)).toBeFalsy();
    expect(callsTo('PATCH', `/social-posts/${POST}`)[0]!.body).toEqual({ options: { [PIN]: { board_id: 'b1' } } });
    expect(callsTo('PATCH', `/social-posts/${POST2}`)).toHaveLength(0);
  });
});

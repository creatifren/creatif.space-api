import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { retryTokens } from '../src/tools/retry.js';
import { post } from './fixtures.js';
import { payload, startApp, TOKEN_FULL, TOKEN_READ } from './helpers.js';

const POST = '01JPOSTPOSTPOSTPOSTPOSTPO1';
const PREVIEW = `GET /social-posts/${POST}/retry-preview`;
const RETRY = `POST /social-posts/${POST}/retry`;

let app: Awaited<ReturnType<typeof startApp>> | undefined;
beforeEach(() => retryTokens.clear());
afterEach(async () => {
  await app?.close();
  app = undefined;
});

const start = async (routes: Record<string, unknown>, token = TOKEN_FULL) => {
  app = await startApp({ routes: routes as never });
  return app.connect(token);
};

const failed = {
  channel_id: '01JCHANCHANCHANCHANCHANCH1', platform: 'pinterest', username: 'studio', usable: true,
  failure_code: 'platform_unavailable', reason: 'Pinterest was unavailable. Try again in a few minutes.', credits: 300,
};
const preview = (extra: Record<string, unknown> = {}) => ({
  body: {
    data: {
      post_id: POST, status: 'failed', retryable: true, blocked: null, targets: [failed],
      credits: { price: 300, from_topup: 300, monthly_balance: 0, topup_balance: 30000 },
      confirm_token: 'rt1.sig', confirm_expires_at: new Date(Date.now() + 600_000).toISOString(),
      ...extra,
    },
  },
});
const call = (client: Awaited<ReturnType<typeof start>>, args: Record<string, unknown>) =>
  client.callTool({ name: 'retry_post', arguments: { post_id: POST, ...args } }).then(payload);

describe('retry_post', () => {
  it('previews each failure’s code, reason and the Credit cost, and sends nothing', async () => {
    const client = await start({ [PREVIEW]: () => preview() });
    const out = await call(client, {});
    expect(out).toMatchObject({
      confirmation_required: true,
      post_id: POST,
      failed: [{ platform: 'pinterest', failure_code: 'platform_unavailable', reason: failed.reason, credits: 300 }],
      credit_cost: 300,
    });
    expect(JSON.stringify(out)).not.toContain('rt1.sig');
    expect(app!.v1.calls.map((c) => c.method)).toEqual(['GET']);
  });

  it('confirm retries with the previewed token, once', async () => {
    const client = await start({
      [PREVIEW]: () => preview(),
      [RETRY]: () => ({ body: { data: post(POST, { status: 'scheduled' }), retried: 1 } }),
    });
    await call(client, {});
    const out = await call(client, { confirm: true });
    expect(out).toMatchObject({ retried: 1, post: { id: POST } });
    expect(app!.v1.calls[1]).toMatchObject({ method: 'POST', path: `/social-posts/${POST}/retry`, body: { confirm_token: 'rt1.sig' } });

    // The token is spent: a second confirm only previews again.
    const again = await call(client, { confirm: true });
    expect(again).toMatchObject({ confirmation_required: true });
    expect(again.note).toMatch(/nothing was sent/);
    expect(app!.v1.calls.filter((c) => c.method === 'POST')).toHaveLength(1);
  });

  it('confirm without a preview shows one and sends nothing', async () => {
    const client = await start({ [PREVIEW]: () => preview() });
    const out = await call(client, { confirm: true });
    expect(out.note).toMatch(/nothing was sent/);
    expect(app!.v1.calls.map((c) => c.method)).toEqual(['GET']);
  });

  it('not_failed when nothing failed, from the preview or the retry', async () => {
    let client = await start({ [PREVIEW]: () => preview({ retryable: false, blocked: { code: 'not_failed', message: 'Nothing to retry' }, targets: [], confirm_token: undefined }) });
    expect((await call(client, {})).error).toMatchObject({ code: 'not_failed', next_step: expect.stringContaining('get_post') });
    await app!.close();

    client = await start({
      [PREVIEW]: () => preview(),
      [RETRY]: () => ({ status: 409, body: { code: 'not_failed', message: 'Nothing to retry' } }),
    });
    await call(client, {});
    expect((await call(client, { confirm: true })).error).toMatchObject({ code: 'not_failed' });
  });

  it('shows why it is blocked (reconnect, Credits) without a confirm step', async () => {
    const client = await start({ [PREVIEW]: () => preview({ retryable: false, blocked: { code: 'account_reconnect_required', message: 'This account needs to be reconnected: studio' }, confirm_token: undefined }) });
    const out = await call(client, {});
    expect(out).toMatchObject({ confirmation_required: false, blocked: expect.stringContaining('reconnected'), blocked_code: 'account_reconnect_required' });
  });

  it('preview_stale carries the new preview', async () => {
    const client = await start({
      [PREVIEW]: () => preview(),
      [RETRY]: () => ({ status: 409, body: { code: 'preview_stale', message: 'stale' } }),
    });
    await call(client, {});
    const err = (await call(client, { confirm: true })).error;
    expect(err).toMatchObject({ code: 'preview_stale', details: { preview: { confirmation_required: true, credit_cost: 300 } } });
  });

  it('needs publish, and calls nothing without it', async () => {
    const client = await start({}, TOKEN_READ);
    expect((await call(client, {})).error).toMatchObject({ code: 'scope_missing', details: { required: 'publish' } });
    expect(app!.v1.calls).toHaveLength(0);
  });
});

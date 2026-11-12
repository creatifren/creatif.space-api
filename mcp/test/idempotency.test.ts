import { afterEach, describe, expect, it } from 'vitest';
import { previewTokens } from '../src/tools/m2.js';
import { retryTokens } from '../src/tools/retry.js';
import { idempotencyKey } from '../src/v1.js';
import { post } from './fixtures.js';
import { startApp, TOKEN_FULL } from './helpers.js';

const IG = '01JACCTACCTACCTACCTACCTIG1';
const POST = '01JPOSTPOSTPOSTPOSTPOSTPO1';
const LINK = '01JLINKLINKLINKLINKLINKLI1';

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
  previewTokens.clear();
  retryTokens.clear();
});

const start = async (routes: Record<string, unknown>) => {
  app = await startApp({ routes: routes as never });
  return app.connect(TOKEN_FULL);
};
const keysOf = (method: string, path: string) =>
  app!.v1.calls.filter((c) => c.method === method && c.path === path).map((c) => c.headers.get('idempotency-key'));

const sendPreview = (token: string) => ({
  body: {
    data: {
      post_id: POST, sendable: true, status: 'draft', scheduled_at: null,
      targets: [{ channel_id: IG, platform: 'instagram', username: 'studio', usable: true, credits: 15 }],
      credits: { price: 15, from_topup: 0, monthly_balance: 300, topup_balance: 0, can_afford: true },
      daily_cap: { cap: 20, used: 0, remaining: 20 },
      confirm_token: token, confirm_expires_at: new Date(Date.now() + 600_000).toISOString(),
    },
  },
});

describe('Idempotency-Key from the MCP server', () => {
  it('sends one key per confirm of publish_post: the same confirm twice is the same key', async () => {
    let n = 0;
    const client = await start({
      [`GET /social-posts/${POST}/send-preview`]: () => sendPreview(`tok${++n}.sig`),
      // The first answer is lost on the way back: the token stays, the confirm is repeated.
      [`POST /social-posts/${POST}/send`]: () => ({ status: n === 1 && keysOf('POST', `/social-posts/${POST}/send`).length === 1 ? 502 : 200, body: { data: post(POST) } }),
    });
    await client.callTool({ name: 'publish_post', arguments: { post_id: POST } });
    await client.callTool({ name: 'publish_post', arguments: { post_id: POST, confirm: true } });
    await client.callTool({ name: 'publish_post', arguments: { post_id: POST, confirm: true } });

    const keys = keysOf('POST', `/social-posts/${POST}/send`);
    expect(keys).toHaveLength(2);
    expect(keys[0]).toMatch(/^mcp-[0-9a-f]{40}$/);
    expect(keys[1]).toBe(keys[0]);
    expect(keys[0]).not.toContain('tok1');

    // A new preview (a new token) is a new confirm, so a new key.
    await client.callTool({ name: 'publish_post', arguments: { post_id: POST } });
    await client.callTool({ name: 'publish_post', arguments: { post_id: POST, confirm: true } });
    expect(new Set(keysOf('POST', `/social-posts/${POST}/send`)).size).toBe(2);
  });

  it('sends a key on retry_post’s confirm', async () => {
    const client = await start({
      [`GET /social-posts/${POST}/retry-preview`]: () => ({
        body: { data: { post_id: POST, status: 'failed', retryable: true, blocked: null, targets: [], credits: { price: 0, from_topup: 0, monthly_balance: 0, topup_balance: 0 }, confirm_token: 'rt.sig', confirm_expires_at: new Date(Date.now() + 600_000).toISOString() } },
      }),
      [`POST /social-posts/${POST}/retry`]: () => ({ body: { data: post(POST), retried: 1 } }),
    });
    await client.callTool({ name: 'retry_post', arguments: { post_id: POST } });
    await client.callTool({ name: 'retry_post', arguments: { post_id: POST, confirm: true } });

    const [key] = keysOf('POST', `/social-posts/${POST}/retry`);
    expect(key).toMatch(/^mcp-[0-9a-f]{40}$/);
    expect(key).not.toBe(idempotencyKey('send', 'x', POST, 'rt.sig'));
  });

  it('makes the same approval link once when asked twice, and a different one for other arguments', async () => {
    const client = await start({
      'POST /social-approval-links': () => ({ status: 201, body: { data: { id: LINK, url: 'https://creatif.space/a/t', status: 'active', posts: 1, approver_email: 'a@b.test', expires_at: null, counts: {} } } }),
    });
    const args = { post_ids: [POST], approver_email: 'rani@client.test' };
    await client.callTool({ name: 'create_approval_link', arguments: args });
    await client.callTool({ name: 'create_approval_link', arguments: args });
    await client.callTool({ name: 'create_approval_link', arguments: { ...args, approver_email: 'other@client.test' } });

    const [a, b, c] = keysOf('POST', '/social-approval-links');
    expect(a).toMatch(/^mcp-/);
    expect(b).toBe(a);
    expect(c).not.toBe(a);
  });

  it('sends no key on reads', async () => {
    await start({});
    const client = await app!.connect(TOKEN_FULL);
    await client.callTool({ name: 'get_post', arguments: { post_id: POST } });
    expect(app!.v1.calls[0]!.headers.get('idempotency-key')).toBeNull();
  });
});

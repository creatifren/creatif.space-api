import { afterEach, describe, expect, it } from 'vitest';
import { account } from './fixtures.js';
import { payload, startApp, TOKEN_ALL } from './helpers.js';

/* list_channels hides a disconnected copy of an account that is also connected (P3: @loyaldigicard twice in ChatGPT). */

const IG = '01JACCTACCTACCTACCTACCTIG1';
const IG_OLD = '01JACCTACCTACCTACCTACCTIG2';
const X = '01JACCTACCTACCTACCTACCTXX1';

const accounts = [
  account(IG, 'instagram', { username: 'loyaldigicard' }),
  // The same Instagram account through an old connection that no longer works.
  account(IG_OLD, 'instagram', { username: '@LoyalDigicard', status: 'disconnected', reconnect_required: true }),
  // A disconnected account with no working copy: still listed, the user must reconnect it.
  account(X, 'x', { username: 'loyaldigicard', status: 'expired' }),
];

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

async function list(args: Record<string, unknown>) {
  app = await startApp({ routes: { 'GET /social-accounts': () => ({ body: { data: accounts } }) } as never });
  const res = await (await app.connect(TOKEN_ALL)).callTool({ name: 'list_channels', arguments: args });
  return { res, out: payload(res) };
}

describe('list_channels: disconnected duplicates', () => {
  it('by default leaves out the disconnected copy of a connected account, and says so', async () => {
    const { res, out } = await list({});
    expect(res.isError).toBeFalsy();
    expect(out.channels.map((c: { id: string }) => c.id)).toEqual([IG, X]);
    expect(out.hidden_duplicates).toBe(1);
    expect(out.note).toMatch(/include_disconnected: true/);
    // The lone disconnected X account still needs reconnecting and is still there.
    expect(out.needs_reconnect).toEqual([X]);
    expect(res.structuredContent).toEqual(out);
  });

  it('include_disconnected: true lists them all', async () => {
    const { out } = await list({ include_disconnected: true });
    expect(out.channels.map((c: { id: string }) => c.id)).toEqual([IG, IG_OLD, X]);
    expect(out.hidden_duplicates).toBeUndefined();
    expect(out.needs_reconnect).toEqual([IG_OLD, X]);
  });

  it('hides nothing when no connected copy exists', async () => {
    app = await startApp({ routes: { 'GET /social-accounts': () => ({ body: { data: [accounts[1], accounts[2]] } }) } as never });
    const out = payload(await (await app.connect(TOKEN_ALL)).callTool({ name: 'list_channels', arguments: {} }));
    expect(out.channels).toHaveLength(2);
    expect(out.hidden_duplicates).toBeUndefined();
  });
});

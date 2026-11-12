import { afterEach, describe, expect, it } from 'vitest';
import { account } from './fixtures.js';
import { payload, startApp, TOKEN_ALL, TOKEN_FULL } from './helpers.js';

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

/* B2: the same rule in every tool that reports channels needing a reconnect. */
describe('hidden duplicates are not reported as needing a reconnect elsewhere', () => {
  const others = {
    'GET /social-accounts': () => ({ body: { data: accounts } }),
    'GET /credits': () => ({ body: { data: { plan: 'free', limits: { connected_accounts: { limit: 3, used: 2 } } } } }),
    'GET /files/usage': () => ({ body: { data: { tier: 'free', used_bytes: 0, quota_bytes: 1_000_000_000 } } }),
    'GET /social-posts': () => ({ body: { data: [] } }),
  };
  const call = async (name: string, args: Record<string, unknown>) => {
    app = await startApp({ routes: others as never });
    return payload(await (await app.connect(TOKEN_FULL)).callTool({ name, arguments: args }));
  };

  it('get_account_summary: needs_reconnect and next_steps leave the duplicate out', async () => {
    const out = await call('get_account_summary', {});
    expect(out.channels).toMatchObject({ total: 2, connected: 1, needs_reconnect: 1, hidden_duplicates: 1 });
    expect(out.channels.reconnect.map((c: { id: string }) => c.id)).toEqual([X]);
    expect(out.next_steps.join(' ')).not.toMatch(/instagram[^.]*needs reconnecting/i);
    expect(out.next_steps.join(' ')).toMatch(/x loyaldigicard needs reconnecting/);
  });

  it('create_content_plan preview: no reconnect warning for the duplicate', async () => {
    const out = await call('create_content_plan', { date_from: '2026-12-01', date_to: '2026-12-07', approver_email: 'a@client.test' });
    const warning = out.preview.warnings.find((w: string) => w.startsWith('Needs reconnecting'));
    expect(warning).toBe('Needs reconnecting before its posts can go out: x loyaldigicard.');
  });

  it('get_connect_link: existing leaves the duplicate out, so the connected Instagram is not "reconnecting"', async () => {
    const out = await call('get_connect_link', { network: 'instagram' });
    expect(out.existing).toEqual([{ id: IG, username: 'loyaldigicard', status: 'connected', reconnect_required: false }]);
    expect(out.next_step).not.toMatch(/reconnect/);
  });

  it('get_connect_link: a lone disconnected account is still offered for reconnecting', async () => {
    const out = await call('get_connect_link', { network: 'x' });
    expect(out.existing).toEqual([{ id: X, username: 'loyaldigicard', status: 'expired', reconnect_required: true }]);
  });
});

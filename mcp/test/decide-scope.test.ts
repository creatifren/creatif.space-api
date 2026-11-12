import { afterEach, describe, expect, it } from 'vitest';
import { StubIntrospector } from '../src/auth/introspection.js';
import { payload, startApp, USER } from './helpers.js';

/*
 * approvals:decide, as a bug fix (CTO q1345): no new scope, no re-consent.
 * The owner scopes are unchanged; a token without the grant still gets
 * scope_missing on the approver tools; a token with it — however the scope
 * list is written, client name or not — reaches the API.
 */

const OWNER = `stub:${USER}:read,draft,approve,publish:Claude`;
const APPROVER_TOOLS = ['list_waiting_approvals', 'decide_approval', 'list_shared_with_me'] as const;
const ARGS: Record<string, Record<string, unknown>> = {
  list_waiting_approvals: {},
  decide_approval: { link: '01JD2ZK4M0Q5X8Y9R7T6V5W4X3', post_id: '01JD2ZK4M0Q5X8Y9R7T6V5W4X4', decision: 'approve' },
  list_shared_with_me: {},
};

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

describe('stub tokens: approvals:decide is read whole', () => {
  const stub = new StubIntrospector();
  it.each([
    [`stub:${USER}:read,approvals:decide`, ['read', 'approvals:decide'], 'Stub client'],
    [`stub:${USER}:approvals:decide`, ['approvals:decide'], 'Stub client'],
    [`stub:${USER}:read,approvals:decide:Claude`, ['read', 'approvals:decide'], 'Claude'],
    [`stub:${USER}:approvals:decide,read:ChatGPT`, ['approvals:decide', 'read'], 'ChatGPT'],
    [OWNER, ['read', 'draft', 'approve', 'publish'], 'Claude'],
    [`stub:${USER}:read`, ['read'], 'Stub client'],
  ])('%s', async (token, scopes, client) => {
    const info = await stub.introspect(token);
    expect(info.scopes).toEqual(scopes);
    expect(info.client_name).toBe(client);
  });
});

describe('approver tools and the scope check', () => {
  const routes = {
    'GET /me/approvals/waiting': () => ({ body: { data: [] } }),
    'GET /me/reports/shared': () => ({ body: { data: [] } }),
    'GET /me/approvals/01JD2ZK4M0Q5X8Y9R7T6V5W4X4/preview': () => ({ status: 404, body: { message: 'Not found.' } }),
  };

  it('owner scopes are unchanged: read, draft, approve, publish — and no approver tool runs on them', async () => {
    app = await startApp({ routes: routes as never });
    const client = await app.connect(OWNER);
    for (const name of APPROVER_TOOLS) {
      const err = payload(await client.callTool({ name, arguments: ARGS[name] })).error;
      expect(err, name).toMatchObject({ code: 'scope_missing', details: { required: 'approvals:decide', granted: ['read', 'draft', 'approve', 'publish'] } });
    }
    expect(app.v1.calls).toHaveLength(0);
  });

  it('a token without the grant gets scope_missing, whatever else it holds', async () => {
    app = await startApp({ routes: routes as never });
    const client = await app.connect(`stub:${USER}:read`);
    const err = payload(await client.callTool({ name: 'list_waiting_approvals', arguments: {} })).error;
    expect(err).toMatchObject({ code: 'scope_missing', details: { required: 'approvals:decide', granted: ['read'] } });
    expect(app.v1.calls).toHaveLength(0);
  });

  it.each([
    ['granted with other scopes, named client', `stub:${USER}:read,draft,approve,publish,approvals:decide:Claude`],
    ['granted alone, no client name (the old parse dropped it)', `stub:${USER}:read,approvals:decide`],
    ['granted first in the list', `stub:${USER}:approvals:decide,read:Claude`],
  ])('a token with the grant reaches the API: %s', async (_label, token) => {
    app = await startApp({ routes: routes as never });
    const client = await app.connect(token);
    for (const name of ['list_waiting_approvals', 'list_shared_with_me']) {
      const res = await client.callTool({ name, arguments: {} });
      expect(res.isError, `${name}: ${JSON.stringify(res.content)}`).toBeFalsy();
    }
    expect(app.v1.calls.map((c) => c.path)).toEqual(['/me/approvals/waiting', '/me/reports/shared']);
  });
});

import { afterEach, describe, expect, it } from 'vitest';
import { payload, startApp, TOKEN_FULL } from './helpers.js';

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

const start = async (routes: Record<string, unknown>) => {
  app = await startApp({ routes: routes as never });
  return app.connect(TOKEN_FULL);
};

const HOTEL = '01JCLIENTCLIENTCLIENTHOTL1';
const IG = '01JACCTACCTACCTACCTACCTIG1';
const v1Client = {
  id: HOTEL, name: 'Hotel Ombak', initials: 'HO', color: '#3E6A8A', approver_email: 'made@ombak.test', approver_name: 'Made', report_email: true,
  accounts: [{ id: IG, platform: 'instagram', username: '@ombakgroup', shared: true }], posts: 3, waiting: 1,
};

describe('clients (v7 labels on channels)', () => {
  it('lists clients with their channels and marks a shared channel', async () => {
    const client = await start({ 'GET /client-labels': () => ({ body: { data: [v1Client] } }) });
    const out = payload(await client.callTool({ name: 'list_clients', arguments: {} }));
    expect(out.clients[0]).toEqual({
      id: HOTEL, name: 'Hotel Ombak', initials: 'HO', color: '#3E6A8A', approver_email: 'made@ombak.test', approver_name: 'Made',
      monthly_report_email: true, channels: [{ channel_id: IG, platform: 'instagram', username: '@ombakgroup', shared_with_other_clients: true }],
      posts: 3, waiting_for_approval: 1,
    });
    expect(out.next_cursor).toBeNull();
  });

  it('creates a client with the API field names', async () => {
    const client = await start({ 'POST /client-labels': () => ({ status: 201, body: { data: v1Client } }) });
    await client.callTool({ name: 'create_client', arguments: { name: 'Hotel Ombak', monthly_report_email: false, channel_ids: [IG] } });
    expect(app!.v1.calls[0]).toMatchObject({ method: 'POST', path: '/client-labels', body: { name: 'Hotel Ombak', report_email: false, account_ids: [IG] } });
  });

  it('sets the clients of one channel', async () => {
    const client = await start({
      [`PUT /social-accounts/${IG}/client-labels`]: () => ({ body: { data: { account_id: IG, clients: [{ id: HOTEL, name: 'Hotel Ombak', initials: 'HO', color: '#3E6A8A' }] } } }),
    });
    const out = payload(await client.callTool({ name: 'set_channel_clients', arguments: { channel_id: IG, client_ids: [HOTEL] } }));
    expect(out).toEqual({ channel_id: IG, clients: [{ id: HOTEL, name: 'Hotel Ombak' }] });
  });

  it('filters list_posts by client', async () => {
    const client = await start({ 'GET /social-posts': () => ({ body: { data: [] } }) });
    await client.callTool({ name: 'list_posts', arguments: { client_id: HOTEL } });
    expect(app!.v1.calls[0]!.query).toMatchObject({ client_id: HOTEL });
  });
});

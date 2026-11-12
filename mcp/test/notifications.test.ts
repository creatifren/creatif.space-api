import { afterEach, describe, expect, it } from 'vitest';
import { payload, startApp, TOKEN_FULL, TOKEN_READ } from './helpers.js';

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

const start = async (routes: Record<string, unknown>, token = TOKEN_FULL) => {
  app = await startApp({ routes: routes as never });
  return app.connect(token);
};
const ON = { publish_failed: true, approval_decided: true, weekly_digest: true };

describe('get_notification_settings', () => {
  it('returns the three switches and what each means', async () => {
    const client = await start({ 'GET /me/notification-settings': () => ({ body: { data: ON } }) });
    const out = payload(await client.callTool({ name: 'get_notification_settings', arguments: {} }));
    expect(out.settings).toEqual(ON);
    expect(Object.keys(out.meaning)).toEqual(['publish_failed', 'approval_decided', 'weekly_digest']);
  });

  it('works with read only', async () => {
    const client = await start({ 'GET /me/notification-settings': () => ({ body: { data: ON } }) }, TOKEN_READ);
    expect(payload(await client.callTool({ name: 'get_notification_settings', arguments: {} })).settings).toEqual(ON);
  });
});

describe('update_notification_settings', () => {
  it('sends only what was given and returns all three', async () => {
    const client = await start({ 'PATCH /me/notification-settings': () => ({ body: { data: { ...ON, weekly_digest: false } } }) });
    const out = payload(await client.callTool({ name: 'update_notification_settings', arguments: { weekly_digest: false } }));
    expect(app!.v1.calls[0]).toMatchObject({ method: 'PATCH', path: '/me/notification-settings', body: { weekly_digest: false } });
    expect(out.settings.weekly_digest).toBe(false);
  });

  it('refuses an empty change without calling the API', async () => {
    const client = await start({});
    const err = payload(await client.callTool({ name: 'update_notification_settings', arguments: {} })).error;
    expect(err).toMatchObject({ code: 'invalid_input', next_step: expect.any(String) });
    expect(app!.v1.calls).toHaveLength(0);
  });

  it('needs draft', async () => {
    const client = await start({}, TOKEN_READ);
    const err = payload(await client.callTool({ name: 'update_notification_settings', arguments: { publish_failed: false } })).error;
    expect(err).toMatchObject({ code: 'scope_missing', details: { required: 'draft' } });
    expect(app!.v1.calls).toHaveLength(0);
  });
});

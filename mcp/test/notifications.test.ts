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
const ON = { publish_failed: true, approval_decided: true, weekly_digest: true, timezone: 'Asia/Jakarta' };

describe('get_notification_settings', () => {
  it('returns the three switches and what each means', async () => {
    const client = await start({ 'GET /me/notification-settings': () => ({ body: { data: ON } }) });
    const out = payload(await client.callTool({ name: 'get_notification_settings', arguments: {} }));
    expect(out.settings).toEqual({ ...ON, post_missed_or_failed: true });
    expect(Object.keys(out.meaning)).toEqual(['post_missed_or_failed', 'publish_failed', 'approval_decided', 'weekly_digest', 'timezone']);
  });

  it('works with read only', async () => {
    const client = await start({ 'GET /me/notification-settings': () => ({ body: { data: ON } }) }, TOKEN_READ);
    expect(payload(await client.callTool({ name: 'get_notification_settings', arguments: {} })).settings).toMatchObject(ON);
  });
});

describe('update_notification_settings', () => {
  it('sends only what was given and returns all three', async () => {
    const client = await start({ 'PATCH /me/notification-settings': () => ({ body: { data: { ...ON, weekly_digest: false } } }) });
    const out = payload(await client.callTool({ name: 'update_notification_settings', arguments: { weekly_digest: false } }));
    expect(app!.v1.calls[0]).toMatchObject({ method: 'PATCH', path: '/me/notification-settings', body: { weekly_digest: false } });
    expect(out.settings.weekly_digest).toBe(false);
  });

  it('sets the digest timezone', async () => {
    const client = await start({ 'PATCH /me/notification-settings': () => ({ body: { data: { ...ON, timezone: 'Indian/Maldives' } } }) });
    const out = payload(await client.callTool({ name: 'update_notification_settings', arguments: { timezone: 'Indian/Maldives' } }));
    expect(app!.v1.calls[0]!.body).toEqual({ timezone: 'Indian/Maldives' });
    expect(out.settings.timezone).toBe('Indian/Maldives');
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

describe('“Post missed / failed” (post_missed_or_failed), feature-detected', () => {
  it('API without its own key (main since #199): post_missed_or_failed is the publish_failed switch', async () => {
    const client = await start({
      'GET /me/notification-settings': () => ({ body: { data: ON } }),
      'PATCH /me/notification-settings': () => ({ body: { data: { ...ON, publish_failed: false } } }),
    });
    const got = payload(await client.callTool({ name: 'get_notification_settings', arguments: {} }));
    expect(got.settings.post_missed_or_failed).toBe(true);
    const out = payload(await client.callTool({ name: 'update_notification_settings', arguments: { post_missed_or_failed: false } }));
    const patch = app!.v1.calls.find((c) => c.method === 'PATCH')!;
    expect(patch.body).toEqual({ publish_failed: false });
    expect(out.settings).toMatchObject({ post_missed_or_failed: false, publish_failed: false });
  });

  it('API with its own post_missed_or_failed key: that key is read and written', async () => {
    const OWN = { ...ON, post_missed_or_failed: true };
    const client = await start({
      'GET /me/notification-settings': () => ({ body: { data: { ...OWN, publish_failed: true } } }),
      'PATCH /me/notification-settings': () => ({ body: { data: { ...OWN, post_missed_or_failed: false } } }),
    });
    const out = payload(await client.callTool({ name: 'update_notification_settings', arguments: { post_missed_or_failed: false, weekly_digest: true } }));
    const patch = app!.v1.calls.find((c) => c.method === 'PATCH')!;
    expect(patch.body).toEqual({ weekly_digest: true, post_missed_or_failed: false });
    expect(out.settings).toMatchObject({ post_missed_or_failed: false, publish_failed: true });
  });

  it('the same switch given two values → invalid_input, nothing sent', async () => {
    const client = await start({});
    const err = payload(await client.callTool({ name: 'update_notification_settings', arguments: { post_missed_or_failed: true, publish_failed: false } })).error;
    expect(err).toMatchObject({ code: 'invalid_input', details: { field: 'post_missed_or_failed' } });
    expect(app!.v1.calls).toHaveLength(0);
  });

  it('publish_failed alone still works and needs no detection call', async () => {
    const client = await start({ 'PATCH /me/notification-settings': () => ({ body: { data: { ...ON, publish_failed: false } } }) });
    await client.callTool({ name: 'update_notification_settings', arguments: { publish_failed: false } });
    expect(app!.v1.calls.map((c) => `${c.method} ${c.path}`)).toEqual(['PATCH /me/notification-settings']);
  });
});

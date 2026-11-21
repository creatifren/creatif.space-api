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
const EVENTS = { approved: true, changes: true, post_problem: true, channel: true, credits: true };
const ON = { problems_email: true, publish_failed: true, push: true, push_events: EVENTS, timezone: 'Asia/Jakarta' };
const SHOWN = { problems_email: true, push: true, push_events: EVENTS, timezone: 'Asia/Jakarta' };

describe('get_notification_settings (v10.8)', () => {
  it('returns the email switch, push and the five push events, with meanings', async () => {
    const client = await start({ 'GET /me/notification-settings': () => ({ body: { data: ON } }) });
    const out = payload(await client.callTool({ name: 'get_notification_settings', arguments: {} }));
    expect(out.settings).toEqual(SHOWN);
    expect(Object.keys(out.meaning)).toContain('push_events.post_problem');
  });

  it('works with read only', async () => {
    const client = await start({ 'GET /me/notification-settings': () => ({ body: { data: ON } }) }, TOKEN_READ);
    expect(payload(await client.callTool({ name: 'get_notification_settings', arguments: {} })).settings).toMatchObject(SHOWN);
  });
});

describe('update_notification_settings (v10.8)', () => {
  it('sends only what was given', async () => {
    const client = await start({ 'PATCH /me/notification-settings': () => ({ body: { data: { ...ON, push_events: { ...EVENTS, approved: false } } } }) });
    const out = payload(await client.callTool({ name: 'update_notification_settings', arguments: { push_events: { approved: false } } }));
    expect(app!.v1.calls[0]).toMatchObject({ method: 'PATCH', path: '/me/notification-settings', body: { push_events: { approved: false } } });
    expect(out.settings.push_events.approved).toBe(false);
  });

  it('turns the problems email off and sets the timezone', async () => {
    const client = await start({ 'PATCH /me/notification-settings': () => ({ body: { data: { ...ON, problems_email: false, timezone: 'Indian/Maldives' } } }) });
    const out = payload(await client.callTool({ name: 'update_notification_settings', arguments: { problems_email: false, timezone: 'Indian/Maldives' } }));
    expect(app!.v1.calls[0]!.body).toEqual({ problems_email: false, timezone: 'Indian/Maldives' });
    expect(out.settings).toMatchObject({ problems_email: false, timezone: 'Indian/Maldives' });
  });

  it('refuses an empty change without calling the API', async () => {
    const client = await start({});
    const err = payload(await client.callTool({ name: 'update_notification_settings', arguments: {} })).error;
    expect(err).toMatchObject({ code: 'invalid_input', next_step: expect.any(String) });
    expect(app!.v1.calls).toHaveLength(0);
  });

  it('needs draft', async () => {
    const client = await start({}, TOKEN_READ);
    const err = payload(await client.callTool({ name: 'update_notification_settings', arguments: { push: false } })).error;
    expect(err).toMatchObject({ code: 'scope_missing', details: { required: 'draft' } });
    expect(app!.v1.calls).toHaveLength(0);
  });
});

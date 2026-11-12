import { afterEach, describe, expect, it } from 'vitest';
import { payload, startApp, TOKEN_FULL, USER } from './helpers.js';

const TOKEN_READ = `stub:${USER}:read:Claude`;

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

const start = async (routes: Record<string, unknown>, token = TOKEN_FULL) => {
  app = await startApp({ routes: routes as never });
  return app.connect(token);
};

describe('send_feedback', () => {
  it('posts the feedback and says it was sent', async () => {
    const client = await start({
      'POST /feedback': () => ({ status: 201, body: { data: { id: '01JFEEDBACKFEEDBACKFEEDBA', kind: 'bug', received_at: '2026-11-12T05:00:00Z' } } }),
    });
    const out = payload(await client.callTool({ name: 'send_feedback', arguments: { kind: 'bug', message: 'Calendar is a day late', page: 'Content Plan' } }));
    expect(out).toMatchObject({ sent: true, id: '01JFEEDBACKFEEDBACKFEEDBA', kind: 'bug' });
    expect(app!.v1.calls[0]).toMatchObject({ method: 'POST', path: '/feedback', body: { kind: 'bug', message: 'Calendar is a day late', page: 'Content Plan' } });
  });

  it('leaves page out when not given', async () => {
    const client = await start({ 'POST /feedback': () => ({ status: 201, body: { data: { id: 'x', kind: 'idea', received_at: 'x' } } }) });
    await client.callTool({ name: 'send_feedback', arguments: { kind: 'idea', message: 'Dark mode please' } });
    expect(app!.v1.calls[0]!.body).toEqual({ kind: 'idea', message: 'Dark mode please' });
  });

  it('turns the 10-an-hour throttle (429) into rate_limited with the API’s retry_after', async () => {
    const client = await start({
      'POST /feedback': () => ({ status: 429, body: { message: 'Too many attempts. Try again in 1800 seconds.', retry_after: 1800 } }),
    });
    const res = await client.callTool({ name: 'send_feedback', arguments: { kind: 'other', message: 'Hello again' } });
    expect(res.isError).toBe(true);
    const err = payload(res).error;
    expect(err).toMatchObject({
      code: 'rate_limited',
      message: expect.any(String),
      next_step: expect.stringContaining('1800 seconds'),
      details: { retryable: true, retry_after_seconds: 1800 },
    });
  });

  it('needs draft, and checks kind before calling', async () => {
    const client = await start({}, TOKEN_READ);
    const err = payload(await client.callTool({ name: 'send_feedback', arguments: { kind: 'bug', message: 'x y' } })).error;
    expect(err).toMatchObject({ code: 'scope_missing', details: { required: 'draft' } });
    const bad = payload(await client.callTool({ name: 'send_feedback', arguments: { kind: 'rant', message: 'x y' } })).error;
    expect(bad.code).toBe('invalid_input');
    expect(app!.v1.calls).toHaveLength(0);
  });
});

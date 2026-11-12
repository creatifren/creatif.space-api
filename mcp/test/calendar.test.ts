import { afterEach, describe, expect, it } from 'vitest';
import { payload, startApp, TOKEN_FULL, USER } from './helpers.js';

const LINK = '01JLINKLINKLINKLINKLINKLI1';
const ROUTE = `GET /social-approval-links/${LINK}/calendar`;
const TOKEN_DRAFT = `stub:${USER}:draft:Claude`;

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

const start = async (routes: Record<string, unknown>, token = TOKEN_FULL) => {
  app = await startApp({ routes: routes as never });
  return app.connect(token);
};

describe('get_plan_calendar_link', () => {
  it('returns the feed URL and the webcal URL', async () => {
    const client = await start({
      [ROUTE]: () => ({ body: { data: { link_id: LINK, enabled: true, url: 'https://api.test/api/v1/content-plans/tok/calendar.ics', webcal_url: 'webcal://api.test/api/v1/content-plans/tok/calendar.ics' } } }),
    });
    const out = payload(await client.callTool({ name: 'get_plan_calendar_link', arguments: { link_id: LINK } }));
    expect(out).toMatchObject({ link_id: LINK, enabled: true, url: expect.stringContaining('/calendar.ics'), webcal_url: expect.stringMatching(/^webcal:\/\//) });
    expect(app!.v1.calls[0]).toMatchObject({ method: 'GET' });
  });

  it('says so when the feed is turned off, without a URL', async () => {
    const client = await start({ [ROUTE]: () => ({ body: { data: { link_id: LINK, enabled: false, url: null, webcal_url: null } } }) });
    const out = payload(await client.callTool({ name: 'get_plan_calendar_link', arguments: { link_id: LINK } }));
    expect(out).toEqual({ link_id: LINK, enabled: false, note: expect.stringMatching(/turned off/) });
  });

  it('maps a fixed list to not_a_plan and a revoked plan to link_inactive', async () => {
    let client = await start({ [ROUTE]: () => ({ status: 422, body: { message: 'x', reason: 'not_a_plan' } }) });
    let err = payload(await client.callTool({ name: 'get_plan_calendar_link', arguments: { link_id: LINK } })).error;
    expect(err).toMatchObject({ code: 'not_a_plan', message: expect.any(String), next_step: expect.stringContaining('create_approval_link') });
    await app!.close();

    client = await start({ [ROUTE]: () => ({ status: 409, body: { message: 'x', reason: 'link_revoked' } }) });
    err = payload(await client.callTool({ name: 'get_plan_calendar_link', arguments: { link_id: LINK } })).error;
    expect(err).toMatchObject({ code: 'link_inactive', details: { status: 'revoked' } });
  });

  it('another workspace’s link (404) is not_found with a next step; needs read', async () => {
    let client = await start({});
    let err = payload(await client.callTool({ name: 'get_plan_calendar_link', arguments: { link_id: LINK } })).error;
    expect(err).toMatchObject({ code: 'not_found', next_step: expect.stringContaining('check_approval_status') });
    await app!.close();

    client = await start({}, TOKEN_DRAFT);
    err = payload(await client.callTool({ name: 'get_plan_calendar_link', arguments: { link_id: LINK } })).error;
    expect(err).toMatchObject({ code: 'scope_missing', details: { required: 'read' } });
    expect(app!.v1.calls).toHaveLength(0);
  });
});

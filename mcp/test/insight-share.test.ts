import { afterEach, describe, expect, it } from 'vitest';
import { payload, startApp, TOKEN_FULL, TOKEN_READ } from './helpers.js';

const IG = '01JACCTACCTACCTACCTACCTIG1';
const SHARE = '01JSHARESHARESHARESHARESH1';

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

const call = async (name: string, args: Record<string, unknown>, routes = {}, token = TOKEN_FULL) => {
  app = await startApp({ routes });
  const client = await app.connect(token);
  return client.callTool({ name, arguments: args });
};

const created = {
  data: {
    id: SHARE,
    url: 'https://app.creatif.test/report/abc',
    token: 'abc',
    period: { from: '2026-10-01', to: '2026-10-31', label: '1 Oct – 31 Oct 2026' },
    channel_ids: [IG],
    status: 'active',
    expires_at: '2026-12-05T03:00:00Z',
  },
};

describe('create_insight_share_link', () => {
  it('posts /insight/share and returns the link and its id', async () => {
    const res = await call('create_insight_share_link', { from: '2026-10-01', to: '2026-10-31', channel_ids: [IG] }, {
      'POST /insight/share': () => ({ status: 201, body: created }),
    });

    expect(res.isError).toBeFalsy();
    expect(payload(res)).toMatchObject({ id: SHARE, url: created.data.url, expires_at: created.data.expires_at, channel_ids: [IG] });
    expect(payload(res).note).toContain('revoke_insight_share_link');
    expect(app!.v1.calls[0]!.body).toEqual({ from: '2026-10-01', to: '2026-10-31', channels: IG });
    expect(app!.v1.calls[0]!.headers.get('X-MCP-Tool')).toBe('create_insight_share_link');
  });

  it('needs the approve permission', async () => {
    const res = await call('create_insight_share_link', { from: '2026-10-01', to: '2026-10-31' }, {}, TOKEN_READ);

    expect(res.isError).toBe(true);
    expect(payload(res).error.code).toBe('scope_missing');
    expect(app!.v1.calls).toHaveLength(0);
  });

  it('passes the 402 on Free on as plan_required', async () => {
    const res = await call('create_insight_share_link', { from: '2026-10-01', to: '2026-10-31' }, {
      'POST /insight/share': () => ({
        status: 402,
        body: { message: 'Insights and analytics is on Pro and Max.', reason: 'plan_required', code: 'plan_required', feature: 'insight', required_plan: 'pro' },
      }),
    });

    expect(payload(res).error).toMatchObject({ code: 'plan_required', details: { feature: 'insight' } });
  });
});

describe('revoke_insight_share_link', () => {
  it('deletes the link', async () => {
    const res = await call('revoke_insight_share_link', { share_id: SHARE }, {
      [`DELETE /insight/share/${SHARE}`]: () => ({ status: 204 }),
    });

    expect(res.isError).toBeFalsy();
    expect(payload(res)).toEqual({ revoked: true, id: SHARE });
    expect(app!.v1.calls[0]!.method).toBe('DELETE');
  });

  it('says not_found for another workspace’s or an unknown id', async () => {
    const res = await call('revoke_insight_share_link', { share_id: SHARE }, {});

    expect(res.isError).toBe(true);
    expect(payload(res).error.code).toBe('not_found');
    expect(payload(res).error.message).toContain('That share link');
  });
});

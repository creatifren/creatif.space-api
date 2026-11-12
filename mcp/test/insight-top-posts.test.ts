import { afterEach, describe, expect, it } from 'vitest';
import { payload, startApp, TOKEN_READ } from './helpers.js';

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

const call = async (args: Record<string, unknown>, routes = {}, token = TOKEN_READ) => {
  app = await startApp({ routes });
  const client = await app.connect(token);
  return client.callTool({ name: 'list_top_posts', arguments: args });
};

const IG = '01JACCTACCTACCTACCTACCTIG1';
const top = {
  data: [
    { channel_id: IG, network: 'Instagram', engagement: 4680, likes: 4100, comments: 380, shares: 120, saves: 80, post_id: null, url: 'https://instagram.example/p/1' },
  ],
  meta: { period: { from: '2026-10-01', to: '2026-10-31' }, ranked_by: 'engagement', limit: 5 },
};

describe('list_top_posts', () => {
  it('reads GET /insight/top-posts for a month and passes the ranking through', async () => {
    const res = await call({ period: { month: '2026-10' } }, { 'GET /insight/top-posts': () => ({ body: top }) });

    expect(res.isError).toBeFalsy();
    const out = payload(res);
    expect(out.data[0].engagement).toBe(4680);
    expect(out.data[0].post_id).toBeNull();
    expect(out.meta.limit).toBe(5);
    expect(app!.v1.calls[0]!.query).toEqual({ month: '2026-10' });
  });

  it('sends a range and channel filter as from / to / channel_ids', async () => {
    await call({ period: { from: '2026-09-15', to: '2026-10-14' }, channel_ids: [IG] }, { 'GET /insight/top-posts': () => ({ body: top }) });
    expect(app!.v1.calls[0]!.query).toEqual({ from: '2026-09-15', to: '2026-10-14', channel_ids: IG });
  });

  it('turns the 402 on Free into plan_required', async () => {
    const res = await call({ period: { month: '2026-10' } }, {
      'GET /insight/top-posts': () => ({ status: 402, body: { message: 'Insight is on Pro and Max.', code: 'plan_required', reason: 'plan_required', feature: 'insight', required_plan: 'pro' } }),
    });
    expect(res.isError).toBe(true);
    expect(payload(res).error.code).toBe('plan_required');
  });

  it('refuses a malformed period before calling the API', async () => {
    const res = await call({ period: { month: '2026-13' } });
    expect(res.isError).toBe(true);
    expect(app!.v1.calls).toHaveLength(0);
  });
});

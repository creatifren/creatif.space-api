import { afterEach, describe, expect, it } from 'vitest';
import { payload, startApp, TOKEN_READ } from './helpers.js';

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

const call = async (args: Record<string, unknown>, routes = {}) => {
  app = await startApp({ routes });
  const client = await app.connect(TOKEN_READ);
  return client.callTool({ name: 'get_best_times', arguments: args });
};

const IG = '01JACCTACCTACCTACCTACCTIG1';
const body = {
  data: [
    { channel_id: IG, network: 'Instagram', status: 'ok', posts_analyzed: 24, min_posts: 10, slots: [{ label: 'Tue 19:00', weekday: 'Tuesday', hour: 19, posts: 4, avg_engagement: 412.5 }] },
    { channel_id: '01JACCTACCTACCTACCTACCTLI1', network: 'LinkedIn', status: 'not_enough_data', posts_analyzed: 3, min_posts: 10, slots: [] },
  ],
  meta: { basis: 'from your posts', timezone: 'Asia/Jakarta', min_posts: 10 },
};

describe('get_best_times', () => {
  it('reads GET /insight/best-times and passes slots and not_enough_data through', async () => {
    const res = await call({}, { 'GET /insight/best-times': () => ({ body }) });

    expect(res.isError).toBeFalsy();
    const out = payload(res);
    expect(out.data[0].slots[0].label).toBe('Tue 19:00');
    expect(out.data[1].status).toBe('not_enough_data');
    expect(out.meta.basis).toBe('from your posts');
    expect(app!.v1.calls[0]!.query).toEqual({});
  });

  it('sends days and the channel filter', async () => {
    await call({ days: 30, channel_ids: [IG] }, { 'GET /insight/best-times': () => ({ body }) });
    expect(app!.v1.calls[0]!.query).toEqual({ days: '30', channel_ids: IG });
  });

  it('refuses days outside 7–92 before calling the API', async () => {
    const res = await call({ days: 400 });
    expect(res.isError).toBe(true);
    expect(app!.v1.calls).toHaveLength(0);
  });

  it('turns the 402 on Free into plan_required', async () => {
    const res = await call({}, {
      'GET /insight/best-times': () => ({ status: 402, body: { message: 'Insight is on Pro and Max.', code: 'plan_required', reason: 'plan_required', feature: 'insight', required_plan: 'pro' } }),
    });
    expect(res.isError).toBe(true);
    expect(payload(res).error.code).toBe('plan_required');
  });
});

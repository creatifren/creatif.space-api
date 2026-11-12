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
  return client.callTool({ name: 'get_insight_csv_link', arguments: args });
};

const link = {
  url: 'https://api.creatif.space/api/v1/insight/export.csv/signed?u=01J&signature=abc',
  expires_at: '2026-11-13T09:00:00Z',
  file_name: 'insight-2026-10-01-to-2026-10-31.csv',
  columns: ['date', 'posted_at', 'platform'],
};

describe('get_insight_csv_link', () => {
  it('posts the range to /insight/export-csv-link and returns the link with a note', async () => {
    const res = await call({ from: '2026-10-01', to: '2026-10-31', channel_ids: ['01JACCTACCTACCTACCTACCTIG1'] }, {
      'POST /insight/export-csv-link': () => ({ body: link }),
    });

    expect(res.isError).toBeFalsy();
    const out = payload(res);
    expect(out.url).toBe(link.url);
    expect(out.file_name).toBe(link.file_name);
    expect(out.columns).toEqual(link.columns);
    expect(out.note).toMatch(/without signing in/);
    expect(app!.v1.calls[0]!.body).toEqual({ from: '2026-10-01', to: '2026-10-31', channels: '01JACCTACCTACCTACCTACCTIG1' });
  });

  it('turns the 402 on Free into plan_required', async () => {
    const res = await call({ from: '2026-10-01', to: '2026-10-31' }, {
      'POST /insight/export-csv-link': () => ({ status: 402, body: { message: 'Insight is on Pro and Max.', code: 'plan_required', reason: 'plan_required', feature: 'insight', required_plan: 'pro' } }),
    });
    expect(res.isError).toBe(true);
    expect(payload(res).error.code).toBe('plan_required');
  });

  it('passes the API’s field errors back as invalid_input', async () => {
    const res = await call({ from: '2026-10-31', to: '2026-10-01' }, {
      'POST /insight/export-csv-link': () => ({ status: 422, body: { message: 'The to field must be a date after or equal to from.', errors: { to: ['The to field must be a date after or equal to from.'] } } }),
    });
    expect(res.isError).toBe(true);
    expect(payload(res).error.code).toBe('invalid_input');
  });
});

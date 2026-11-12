import { afterEach, describe, expect, it } from 'vitest';
import { payload, startApp, TOKEN_READ } from './helpers.js';

const IG = '01JACCTACCTACCTACCTACCTIG1';
const FB = '01JACCTACCTACCTACCTACCTFB1';

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

const call = async (args: Record<string, unknown>, routes = {}, token = TOKEN_READ) => {
  app = await startApp({ routes });
  const client = await app.connect(token);
  return client.callTool({ name: 'get_insight_report_link', arguments: args });
};

const link = {
  url: 'https://api.test/api/v1/insight/export.pdf/signed?u=01JU&from=2026-10-01&to=2026-10-31&format=deck&expires=1&signature=abc',
  expires_at: '2026-11-12T10:15:00Z',
  file_name: 'studio-2026-10-deck.pdf',
};

describe('get_insight_report_link', () => {
  it('asks POST /insight/export-link and hands back the signed link', async () => {
    const res = await call({ from: '2026-10-01', to: '2026-10-31', format: 'deck', channel_ids: [IG, FB], locale: 'id' }, {
      'POST /insight/export-link': () => ({ body: link }),
    });

    expect(res.isError).toBeFalsy();
    expect(payload(res)).toMatchObject({ url: link.url, expires_at: link.expires_at, file_name: link.file_name });
    expect(payload(res).note).toContain('stops working at expires_at');
    const v1 = app!.v1.calls[0]!;
    expect(v1.body).toEqual({ from: '2026-10-01', to: '2026-10-31', format: 'deck', channels: `${IG},${FB}`, locale: 'id' });
    expect(v1.auth).toBe(`Bearer ${TOKEN_READ}`);
    expect(v1.headers.get('X-MCP-Tool')).toBe('get_insight_report_link');
  });

  it('defaults to the one-page report and every channel', async () => {
    await call({ from: '2026-10-01', to: '2026-10-31' }, { 'POST /insight/export-link': () => ({ body: link }) });

    expect(app!.v1.calls[0]!.body).toEqual({ from: '2026-10-01', to: '2026-10-31', format: 'onepage' });
  });

  it('turns the 402 on Free into plan_required', async () => {
    const message = 'Insights and analytics is on Pro and Max. Upgrade: https://creatif.space/settings?section=subscription';
    const res = await call({ from: '2026-10-01', to: '2026-10-31' }, {
      'POST /insight/export-link': () => ({
        status: 402,
        body: { message, reason: 'plan_required', code: 'plan_required', feature: 'insight', required_plan: 'pro', billing_url: 'https://creatif.space/settings?section=subscription' },
      }),
    });

    expect(res.isError).toBe(true);
    expect(payload(res).error).toMatchObject({ code: 'plan_required', message, details: { feature: 'insight' } });
  });

  it('refuses a malformed date before calling the API', async () => {
    const res = await call({ from: '2026-10', to: '2026-10-31' }, {});

    expect(res.isError).toBe(true);
    expect(app!.v1.calls).toHaveLength(0);
  });
});

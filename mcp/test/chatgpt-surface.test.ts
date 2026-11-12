import { afterEach, describe, expect, it } from 'vitest';
import { clientSurface } from '../src/auth/middleware.js';
import { payload, startApp, USER } from './helpers.js';

/*
 * G2: when the OAuth client is ChatGPT, a plan refusal (402) is the neutral
 * not_available_on_plan — no upgrade link, no pricing next step — and other
 * errors lose billing / top-up links. Every other client keeps the API's
 * message and links as before.
 */

const token = (client: string) => `stub:${USER}:read,draft,approve,publish:${client}`;
const BILLING = 'https://creatif.space/settings/subscription';
const UPSELL = /upgrad|pric(e|ing)|trial|subscri|billing|top[- ]?up|https?:\/\/|\b(Pro|Max)\b/i;

const plan402 = () => ({
  status: 402,
  body: { code: 'plan_required', message: `Insight is part of Pro. Upgrade: ${BILLING}`, feature: 'insight', required_plan: 'pro', billing_url: BILLING },
});

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

async function call(client: string, name: string, args: Record<string, unknown>, routes: NonNullable<Parameters<typeof startApp>[0]>['routes']) {
  app = await startApp({ routes });
  const res = await (await app.connect(token(client))).callTool({ name, arguments: args });
  return { res, err: (payload(res) as { error: { code: string; message: string; next_step: string; details: Record<string, unknown> } }).error };
}

describe('402 for ChatGPT', () => {
  for (const client of ['ChatGPT', 'OpenAI-ChatGPT-Connector']) {
    it(`is neutral not_available_on_plan for "${client}"`, async () => {
      const { res, err } = await call(client, 'get_insight_summary', { period: { month: '2026-10' } }, { 'GET /insight/summary': plan402 });
      expect(res.isError).toBe(true);
      expect(err).toEqual({
        code: 'not_available_on_plan',
        message: 'This isn’t available on this account’s plan.',
        next_step: expect.any(String),
        details: { tool: 'get_insight_summary', feature: 'insight' },
      });
      expect(JSON.stringify(err.next_step)).not.toMatch(UPSELL);
      expect(JSON.stringify(err.message)).not.toMatch(UPSELL);
    });
  }

  it('a plan refusal raised by the MCP server itself is neutral too (connect: channel limit)', async () => {
    const { err } = await call('ChatGPT', 'get_connect_link', { network: 'instagram' }, {
      'GET /social-accounts': () => ({ body: { data: [] } }),
      'GET /credits': () => ({ body: { data: { limits: { connected_accounts: { used: 3, limit: 3 }, urls: { billing: BILLING } } } } }),
    });
    expect(err.code).toBe('not_available_on_plan');
    expect(JSON.stringify(err)).not.toMatch(UPSELL);
  });

  it('other errors lose top-up / billing links and wording', async () => {
    const { err } = await call('ChatGPT', 'request_media_upload', { filename: 'a.jpg', mime: 'image/jpeg', size_bytes: 10 }, {
      'POST /files/presign': () => ({
        status: 422,
        body: { reason: 'quota_exceeded', feature: 'media_quota', next_step: 'upgrade_pro', message: 'Storage is full. Upgrade to Pro for more.', billing_url: BILLING, files_url: 'https://creatif.space/files' },
      }),
    });
    expect(err.code).toBe('storage_full');
    expect(err.details).not.toHaveProperty('billing_url');
    expect(err.message + err.next_step).not.toMatch(/upgrad|billing|\bPro\b/i);
  });
});

describe('402 for other clients (unchanged)', () => {
  it('Claude keeps plan_required with the API message and billing_url', async () => {
    const { err } = await call('Claude', 'get_insight_summary', { period: { month: '2026-10' } }, { 'GET /insight/summary': plan402 });
    expect(err.code).toBe('plan_required');
    expect(err.message).toContain(BILLING);
    expect(err.details.billing_url).toBe(BILLING);
  });
});

describe('clientSurface', () => {
  it('detects ChatGPT by the API flag (redirect host) or the registered client name', () => {
    expect(clientSurface({ client_surface: 'chatgpt', client_name: 'My connector' })).toBe('chatgpt');
    expect(clientSurface({ client_name: 'ChatGPT' })).toBe('chatgpt');
    expect(clientSurface({ client_name: 'chatgpt-connector' })).toBe('chatgpt');
    expect(clientSurface({ client_name: 'OpenAI' })).toBe('chatgpt');
    expect(clientSurface({ client_name: 'Claude' })).toBe('other');
    expect(clientSurface({ client_surface: 'other', client_name: 'Cursor' })).toBe('other');
    expect(clientSurface({})).toBe('other');
  });
});

import { afterEach, describe, expect, it } from 'vitest';
import { payload, startApp, USER } from './helpers.js';
import { argsFor } from './sampleArgs.js';

/*
 * G2 check, over every tool: for a ChatGPT client, any API 402 a tool hits
 * comes back as the neutral not_available_on_plan — no upgrade link, no
 * price, no plan name — whatever the tool. Each tool is called with minimal
 * valid arguments built from its own inputSchema, against an API that
 * answers 402 to everything.
 */

const CHATGPT = `stub:${USER}:read,draft,approve,publish,approvals:decide:ChatGPT`;
const BILLING = 'https://creatif.space/settings/subscription';
const UPSELL = /upgrad|pric(e|ing)|trial|subscri|billing|top[- ]?up|https?:\/\/|\b(Pro|Max)\b/i;

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

describe('every tool, ChatGPT client, API answers 402', () => {
  it('returns not_available_on_plan with no upgrade wording or link', async () => {
    let served402 = 0;
    app = await startApp({
      routes: {
        '*': () => {
          served402++;
          return { status: 402, body: { code: 'plan_required', message: `Upgrade to Pro: ${BILLING}`, feature: 'insight', billing_url: BILLING } };
        },
      },
    });
    const client = await app.connect(CHATGPT);
    const { tools } = await client.listTools();

    const table: { tool: string; hit402: boolean; code: string | null }[] = [];
    for (const t of tools) {
      const before = served402;
      const res = await client.callTool({ name: t.name, arguments: argsFor(t) });
      const text = JSON.stringify(res.content);
      const err = res.isError ? (payload(res).error as { code: string }) : null;
      const hit402 = served402 > before;
      table.push({ tool: t.name, hit402, code: err?.code ?? null });
      if (hit402) {
        expect(err?.code, `${t.name}: a 402 must be not_available_on_plan for ChatGPT`).toBe('not_available_on_plan');
        expect(text, `${t.name}: upgrade wording or a link in the ChatGPT error`).not.toMatch(UPSELL);
      }
    }

    // Nearly every tool calls the API; a schema-sampling slip must not hide tools from this check.
    const reached = table.filter((r) => r.hit402).map((r) => r.tool);
    const missed = table.filter((r) => !r.hit402).map((r) => `${r.tool} (${r.code ?? 'ok'})`);
    expect(reached.length, `tools that never reached the API: ${missed.join(', ')}`).toBeGreaterThanOrEqual(tools.length - 2);
  });
});

import { afterEach, describe, expect, it } from 'vitest';
import { payload, startApp, USER } from './helpers.js';

/*
 * G2 check, over every tool: for a ChatGPT client, any API 402 a tool hits
 * comes back as the neutral not_available_on_plan — no upgrade link, no
 * price, no plan name — whatever the tool. Each tool is called with minimal
 * valid arguments built from its own inputSchema, against an API that
 * answers 402 to everything.
 */

const CHATGPT = `stub:${USER}:read,draft,approve,publish,approvals:decide:ChatGPT`;
const ULID = '01JD2ZK4M0Q5X8Y9R7T6V5W4X3';
const BILLING = 'https://creatif.space/settings/subscription';
const UPSELL = /upgrad|pric(e|ing)|trial|subscri|billing|top[- ]?up|https?:\/\/|\b(Pro|Max)\b/i;

type Schema = {
  type?: string | string[];
  properties?: Record<string, Schema>;
  required?: string[];
  items?: Schema;
  enum?: unknown[];
  const?: unknown;
  anyOf?: Schema[];
  oneOf?: Schema[];
  format?: string;
  pattern?: string;
  minimum?: number;
  minLength?: number;
  maxLength?: number;
  minItems?: number;
  default?: unknown;
};

/** The smallest value that satisfies a JSON schema (as the SDK publishes zod schemas). */
function sample(s: Schema, key = ''): unknown {
  if (s.const !== undefined) return s.const;
  if (s.enum) return s.enum[0];
  const alt = s.anyOf ?? s.oneOf;
  if (alt) return sample(alt[0]!, key);
  const type = Array.isArray(s.type) ? s.type.find((t) => t !== 'null') : s.type;
  switch (type) {
    case 'object': {
      const out: Record<string, unknown> = {};
      for (const k of s.required ?? []) out[k] = sample(s.properties![k]!, k);
      return out;
    }
    case 'array':
      return Array.from({ length: Math.max(1, s.minItems ?? 1) }, () => sample(s.items ?? { type: 'string' }, key));
    case 'integer':
    case 'number':
      return s.minimum ?? 1;
    case 'boolean':
      return false;
    default:
      if (s.format === 'email') return 'client@example.test';
      if (s.format === 'uri') return 'https://example.test/a.png';
      if (s.format === 'date-time') return '2030-01-04T10:00:00Z';
      if (s.pattern?.includes('\\d{4}-\\d{2}-\\d{2}') || /(^|_)(day|from|to|date)$/.test(key)) return '2026-10-01';
      if (s.pattern?.includes('\\d{4}-\\d{2}')) return '2026-10';
      if ((s.maxLength ?? 0) >= 26 && (s.minLength ?? 0) >= 10) return ULID;
      if (/(^|_)id$/.test(key) || key === 'link' || key.endsWith('_ids')) return ULID;
      return 'test text';
  }
}

/** Tools whose rules the schema cannot express ("one of x or y", a past month): minimal valid arguments by hand. */
const ARGS: Record<string, Record<string, unknown>> = {
  create_approval_link: { post_ids: [ULID], approver_email: 'client@example.test' },
  check_approval_status: { post_id: ULID },
  update_post: { post_id: ULID, caption: 'New caption' },
  reply_to_conversation: { conversation_id: `comment_${ULID}`, text: 'Thank you!' },
  get_insight_summary: { period: { month: '2026-10' } },
  list_top_posts: { period: { month: '2026-10' } },
  update_notification_settings: { weekly_digest: false },
};

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
      const res = await client.callTool({ name: t.name, arguments: ARGS[t.name] ?? (sample(t.inputSchema as Schema) as Record<string, unknown>) });
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

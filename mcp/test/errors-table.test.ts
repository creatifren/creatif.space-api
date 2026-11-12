import { afterEach, describe, expect, it } from 'vitest';
import { payload, startApp, USER } from './helpers.js';
import { STATE_TOOL } from '../src/tools/errorSteps.js';
import { argsFor } from './sampleArgs.js';

/*
 * Errors audit, over every tool: whatever the API answers — 401, 403, 404,
 * 409, 422 or 429 — the tool returns one error with a code and ONE plain
 * next step: a single instruction, no vendor names (the services behind
 * creatif.space), no prices or upgrade wording, no links. Each tool is
 * called with minimal valid arguments built from its own inputSchema
 * against an API that answers the status to everything; the code each tool
 * gives per status is pinned in a snapshot table.
 */

const ALL = `stub:${USER}:read,draft,approve,publish,approvals:decide:Claude`;
const STATUSES = [401, 403, 404, 409, 422, 429] as const;

/** Services behind creatif.space: never named to the user. */
const VENDOR = /outstand|post ?for ?me|creem|stripe|paddle|google play|sentry|resend|posthog|cloudflare|\br2\b|\bs3\b|openai|anthropic|gemini|laravel|sanctum|horizon/i;
/** Prices, plans for sale and buying. */
const PRICE = /\$\s?\d|€\s?\d|\bRp\s?\d|\bIDR\b|\bUSD\b|\bprice|pricing|upgrad|\btrial\b|subscription|billing|purchase|\bbuy\b|top[- ]?up/i;
const LINK = /https?:\/\//i;

/** A plain API answer for each status: just a message (422 also as field errors). */
const body = (status: number, n: number) =>
  status === 422 && n % 2 === 1
    ? { message: 'The caption field is too long.', errors: { caption: ['The caption field is too long.'] } }
    : status === 429
      ? { message: 'Too many requests.', retry_after: 30 }
      : { message: 'Refused.' };

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

describe('every tool × 401/403/404/409/422/429 → one plain next step', () => {
  for (const status of STATUSES) {
    it(`API answers ${status}`, async () => {
      let served = 0;
      app = await startApp({
        routes: {
          '*': () => {
            served++;
            return { status, body: body(status, served) };
          },
        },
      });
      const client = await app.connect(ALL);
      const { tools } = await client.listTools();

      const table: Record<string, string> = {};
      const missed: string[] = [];
      for (const t of tools) {
        const before = served;
        const res = await client.callTool({ name: t.name, arguments: argsFor(t) });
        if (served === before) {
          missed.push(t.name);
          continue;
        }
        const err = res.isError ? (payload(res).error as { code: string; message: string; next_step: string; details: object }) : undefined;
        expect(err, `${t.name} ${status}: no error envelope`).toBeDefined();
        table[t.name] = err!.code;

        const step = err!.next_step;
        expect(step, `${t.name} ${status}: next_step`).toEqual(expect.any(String));
        expect(step.trim().length, `${t.name} ${status}: empty next_step`).toBeGreaterThan(10);
        expect(step.length, `${t.name} ${status}: next_step too long to be one step`).toBeLessThanOrEqual(320);
        expect(step, `${t.name} ${status}: one step, not a list`).not.toMatch(/\n|^\s*\d\./m);
        // Specific to the tool: never the generic "matching list tool" fallback.
        expect(step, `${t.name} ${status}: generic next step`).not.toMatch(/matching list tool/);
        if (status === 409) {
          expect(STATE_TOOL[t.name], `${t.name}: no STATE_TOOL entry`).toBeDefined();
          if (err!.code === 'invalid_input') expect(step, `${t.name} 409`).toContain(`call ${STATE_TOOL[t.name]} to see where it stands now`);
        }
        for (const text of [step, err!.message]) {
          expect(text, `${t.name} ${status}: vendor name`).not.toMatch(VENDOR);
          expect(text, `${t.name} ${status}: price or upgrade wording`).not.toMatch(PRICE);
          expect(text, `${t.name} ${status}: link`).not.toMatch(LINK);
        }
      }

      // A schema-sampling slip must not hide tools from this check.
      expect(missed.length, `tools that never reached the API: ${missed.join(', ')}`).toBeLessThanOrEqual(2);
      expect(table).toMatchSnapshot();
      // Every STATE_TOOL entry is a served tool.
      const names = new Set(tools.map((t) => t.name));
      for (const [tool, state] of Object.entries(STATE_TOOL)) expect(names.has(tool) && names.has(state), `${tool} → ${state}`).toBe(true);
    });
  }
});

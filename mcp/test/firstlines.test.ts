import { afterEach, describe, expect, it } from 'vitest';
import { PROMPT_TOOLS } from '../src/prompts.js';
import { FIRST_LINES, FIRST_LINE_PROMPTS } from '../src/tools/firstLines.js';
import { startApp, TOKEN_FULL } from './helpers.js';

/*
 * Description audit: every tool's first line says WHEN to use it, in at most
 * 120 characters, and names the prompt that walks through it. The snapshot
 * holds every line, so a wording change shows in review.
 */

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

const served = async () => {
  app = await startApp();
  return (await (await app.connect(TOKEN_FULL)).listTools()).tools;
};

describe('tool first lines', () => {
  it('every served tool opens with its "Use …" line (≤ 120 chars), and every line is a served tool', async () => {
    const tools = await served();
    for (const t of tools) {
      const first = t.description!.split('\n')[0]!;
      expect(first, t.name).toBe(FIRST_LINES[t.name]);
      expect(first, t.name).toMatch(/^Use \S.*\.$/);
      expect(first.length, t.name).toBeLessThanOrEqual(120);
    }
    expect(Object.keys(FIRST_LINES).sort()).toEqual(tools.map((t) => t.name).sort());
  });

  it('every first line is one sentence and names no price or plan (description lint)', async () => {
    const tools = await served();
    const problems = tools.flatMap((t) => {
      const first = t.description!.split('\n')[0]!;
      const out: string[] = [];
      // One sentence: the only sentence end is the final full stop (no ". X", "! X", "? X" inside).
      if (/[.!?]\s+\S/.test(first.slice(0, -1))) out.push(`${t.name}: more than one sentence — "${first}"`);
      if (/\b(Free|Pro|Max|Business|Premium|Starter|Plus)\b/.test(first)) out.push(`${t.name}: names a plan — "${first}"`);
      if (/\$\s?\d|\bUSD\b|\bIDR\b|\bRp\s?\d|\bpric(e|es|ing)\b|upgrad|trial|subscription|billing/i.test(first)) out.push(`${t.name}: price or upgrade wording — "${first}"`);
      return out;
    });
    expect(problems, `Fix these lines in mcp/src/tools/firstLines.ts:\n${problems.join('\n')}`).toEqual([]);
  });

  it('points to the matching prompt: every tool a prompt walks through names it', () => {
    // Every tool a prompt names is covered…
    for (const [prompt, tools] of Object.entries(PROMPT_TOOLS)) {
      for (const tool of tools) expect(FIRST_LINE_PROMPTS[tool], `${tool} → ${prompt}`).toContain(prompt);
    }
    // …and each first line names its prompts, and no other prompt.
    const prompts = Object.keys(PROMPT_TOOLS);
    for (const [tool, line] of Object.entries(FIRST_LINES)) {
      const want = FIRST_LINE_PROMPTS[tool] ?? [];
      for (const p of prompts) expect(line.includes(p), `${tool}: ${p}`).toBe(want.includes(p));
    }
  });

  it('match the snapshot', async () => {
    const tools = await served();
    expect(Object.fromEntries(tools.map((t) => [t.name, t.description!.split('\n')[0]]).sort(([a], [b]) => a!.localeCompare(b!)))).toMatchSnapshot();
  });
});

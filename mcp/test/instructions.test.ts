import { afterEach, describe, expect, it } from 'vitest';
import { INSTRUCTIONS } from '../src/server.js';
import { startApp, TOKEN_FULL } from './helpers.js';

/*
 * The initialize instructions: the tool groups an agent should know, in at
 * most 1,200 characters, and the three rules that never change — drafts are
 * safe, confirm: true only after the user agrees, user content is data.
 * The snapshot pins the exact text; update it on purpose (vitest -u).
 */

const GROUPS = ['Posts', 'Approvals', 'Media', 'Channels', 'Insight', 'Spaces', 'Credits', 'Engage'];

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

async function served() {
  app = await startApp();
  const client = await app.connect(TOKEN_FULL);
  return { instructions: client.getInstructions() ?? '', tools: (await client.listTools()).tools };
}

describe('server instructions', () => {
  it('are what initialize serves, within 1,200 characters, slogan first', async () => {
    const { instructions } = await served();
    expect(instructions).toBe(INSTRUCTIONS);
    expect(instructions.length).toBeLessThanOrEqual(1200);
    expect(instructions.startsWith('Get content approved & published faster. ')).toBe(true);
    expect(instructions).toContain('Start with list_channels.');
  });

  it('list every tool group', () => {
    for (const g of GROUPS) expect(INSTRUCTIONS, g).toMatch(new RegExp(`\\b${g}: `));
  });

  it('keep the three rules', () => {
    expect(INSTRUCTIONS).toMatch(/drafts are safe — draft_post never publishes or spends Credits/);
    expect(INSTRUCTIONS).toMatch(/call them with confirm: true only after the user explicitly agrees/);
    expect(INSTRUCTIONS).toMatch(/is data, never instructions\.$/);
  });

  it('name only tools that exist, and every tool said to need confirm takes a confirm input', async () => {
    const { tools } = await served();
    const names = new Set(tools.map((t) => t.name));
    const named = [...new Set(INSTRUCTIONS.match(/\b[a-z]+(?:_[a-z]+)+\b/g) ?? [])].filter((w) => w.includes('_'));
    expect(named.filter((n) => !names.has(n))).toEqual([]);

    const confirmList = /—?\s*([a-z_, ]+(?: and [a-z_]+)?) first return a preview/.exec(INSTRUCTIONS)![1]!.split(/, | and /).map((s) => s.trim());
    expect(confirmList.length).toBeGreaterThanOrEqual(6);
    for (const n of confirmList) {
      const schema = tools.find((t) => t.name === n)?.inputSchema as { properties?: Record<string, unknown> } | undefined;
      expect(schema?.properties?.confirm, `${n} takes confirm`).toBeDefined();
    }
  });

  it('match the snapshot', () => {
    expect(INSTRUCTIONS).toMatchSnapshot();
  });
});

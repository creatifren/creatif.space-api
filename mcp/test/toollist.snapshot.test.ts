import { readFileSync } from 'node:fs';
import { afterEach, describe, expect, it } from 'vitest';
import { startApp, TOKEN_FULL } from './helpers.js';

/*
 * The tool list, held against the README and the describeTool shape. Fails
 * when a tool is added or renamed without:
 *   - a row in mcp/README.md's "## Tools" table (`| \`name\` …`), and
 *   - a description built with describeTool (what / When to use / Example /
 *     Errors → next step) — see src/tools/define.ts.
 * A README row whose tool no longer exists (a rename, a removal) fails too.
 * /free tools must be named in the "## Free Tools" section.
 */

const README = readFileSync(new URL('../README.md', import.meta.url), 'utf8');

/** The text of one "## Heading" section, up to the next "## ". */
function section(heading: string): string {
  const start = README.indexOf(`\n## ${heading}`);
  if (start < 0) throw new Error(`README has no "## ${heading}" section`);
  const next = README.indexOf('\n## ', start + 1);
  return README.slice(start, next < 0 ? undefined : next);
}

/** Tool names in the first column of the Tools table. */
const readmeRows = (): string[] =>
  section('Tools')
    .split('\n')
    .map((line) => /^\| `([a-z0-9_]+)`/.exec(line)?.[1])
    .filter((n): n is string => n !== undefined);

/** The shape describeTool produces, line by line. */
function describeToolProblems(name: string, description: string): string[] {
  const lines = description.split('\n');
  const problems: string[] = [];
  if (!/^Use \S/.test(lines[0] ?? '') || (lines[0] ?? '').length > 120) problems.push('line 1 must be the "Use …" line (≤ 120 chars, src/tools/firstLines.ts)');
  if (!lines[1] || /^(Use |When to use|Example|Errors)/.test(lines[1])) problems.push('line 2 must say what the tool does');
  if (!/^When to use: \S/.test(lines[2] ?? '')) problems.push('line 3 must start "When to use: "');
  if (!(lines[3] ?? '').startsWith(`Example: ${name} {`)) problems.push(`line 4 must start "Example: ${name} {"`);
  const errors = lines.find((l) => l.startsWith('Errors → next step: '));
  if (!errors) problems.push('needs an "Errors → next step: " line');
  else if ((errors.match(/ → /g) ?? []).length < 2) problems.push('the errors line must give at least one code → next step');
  return problems;
}

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

describe('tool list snapshot (README + describeTool)', () => {
  it('every /mcp tool has a README row and a describeTool description; every README row is a tool', async () => {
    app = await startApp();
    const tools = (await (await app.connect(TOKEN_FULL)).listTools()).tools;
    const names = tools.map((t) => t.name).sort();
    const rows = readmeRows();

    const missingRow = names.filter((n) => !rows.includes(n));
    const staleRow = rows.filter((r) => !names.includes(r));
    const duplicateRow = rows.filter((r, i) => rows.indexOf(r) !== i);
    const badShape = tools
      .map((t) => ({ name: t.name, problems: describeToolProblems(t.name, t.description ?? '') }))
      .filter((t) => t.problems.length > 0);

    expect(
      { missingRow, staleRow, duplicateRow, badShape },
      'Add a "| `tool` | scope | V1 route |" row to mcp/README.md ## Tools for each new tool, drop rows of renamed/removed tools, and build each description with describeTool().',
    ).toEqual({ missingRow: [], staleRow: [], duplicateRow: [], badShape: [] });
  });

  it('every /free tool is named in the README Free Tools section', async () => {
    app = await startApp();
    const tools = (await (await app.connectFree()).listTools()).tools;
    expect(tools.length).toBeGreaterThan(0);
    const free = section('Free Tools');
    expect(tools.map((t) => t.name).filter((n) => !free.includes(`\`${n}\``))).toEqual([]);
  });

  it('catches the mistakes it is for', () => {
    const ok = 'Use when x.\nDoes x.\nWhen to use: now\nExample: x_tool {} → {}\nErrors → next step: not_found → list it.';
    expect(describeToolProblems('x_tool', ok)).toEqual([]);
    expect(describeToolProblems('x_tool', 'Does x, a free-text description.')).toHaveLength(5);
    expect(describeToolProblems('renamed', ok)).toEqual(['line 4 must start "Example: renamed {"']);
    expect(describeToolProblems('x_tool', ok.replace('Use when x.', `Use ${'x'.repeat(120)}`))).toEqual([
      'line 1 must be the "Use …" line (≤ 120 chars, src/tools/firstLines.ts)',
    ]);
    expect(readmeRows()).toContain('list_channels');
  });
});

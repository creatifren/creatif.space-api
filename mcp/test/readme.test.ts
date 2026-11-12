import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { approverTools } from '../src/tools/approver.js';
import { auditTools } from '../src/tools/audit.js';

const readme = readFileSync(new URL('../README.md', import.meta.url), 'utf8');
const section = (title: string) => {
  const start = readme.indexOf(`## ${title}`);
  expect(start, title).toBeGreaterThan(-1);
  const next = readme.indexOf('\n## ', start + 3);
  return readme.slice(start, next === -1 ? undefined : next);
};

describe('README — Approve from your AI chat', () => {
  const text = section('Approve from your AI chat');

  it('names every approver tool, the scope and the audit tool', () => {
    for (const tool of [...approverTools, ...auditTools]) {
      expect(text, tool.name).toContain(`\`${tool.name}\``);
    }
    expect(text).toContain('approvals:decide');
  });

  it('has an example conversation that previews before it confirms', () => {
    expect(text).toContain('### Example conversation');
    const preview = text.indexOf('without `confirm`');
    const confirm = text.indexOf('`confirm: true`', preview);
    expect(preview).toBeGreaterThan(-1);
    expect(confirm).toBeGreaterThan(preview);
  });

  it('every approver tool is in the Tools table', () => {
    const tools = section('Tools');
    for (const tool of [...approverTools, ...auditTools]) {
      expect(tools, tool.name).toContain(`| \`${tool.name}\``);
    }
  });
});

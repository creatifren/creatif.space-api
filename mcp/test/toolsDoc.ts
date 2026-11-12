import type { Tool } from '@modelcontextprotocol/sdk/types.js';

/*
 * docs/mcp-tools.md, rendered from the served tool list (tools/list) plus
 * each tool's scope. test/tools-doc.test.ts compares the file with this
 * output, so CI fails when a tool changes and the doc is not regenerated:
 * `npm run docs:tools` rewrites it.
 */

type Prop = { type?: string | string[]; enum?: unknown[]; items?: Prop; anyOf?: Prop[]; description?: string; properties?: Record<string, Prop> };

const cell = (s: string) => s.replace(/\|/g, '\\|').replace(/\s*\n\s*/g, ' ');

function typeOf(p: Prop): string {
  if (p.enum) return p.enum.map((v) => JSON.stringify(v)).join(' | ');
  if (p.anyOf) return p.anyOf.map(typeOf).join(' or ');
  const t = Array.isArray(p.type) ? p.type.filter((x) => x !== 'null').join(' or ') : (p.type ?? 'any');
  if (t === 'array') return `array of ${p.items ? typeOf(p.items) : 'any'}`;
  if (t === 'object' && p.properties) return `object { ${Object.keys(p.properties).join(', ')} }`;
  return t;
}

const yn = (b: boolean | undefined) => (b ? 'yes' : 'no');

export function renderToolsDoc(tools: Tool[], scopes: Record<string, string>, dataNotice: string): string {
  const out: string[] = [
    '# MCP tools',
    '',
    '<!-- Generated from the served tool list by `npm run docs:tools` (in mcp/). Do not edit by hand: test/tools-doc.test.ts fails in CI when this file is out of date. -->',
    '',
    `${tools.length} tools on \`https://mcp.creatif.space/mcp\`, in the order \`tools/list\` gives them. Scope is the OAuth permission a tool needs; the hints are its MCP annotations.`,
    '',
    '| Tool | Title | Scope | Read-only | Destructive |',
    '|---|---|---|---|---|',
    ...tools.map((t) => `| [\`${t.name}\`](#${t.name}) | ${cell(t.title ?? '')} | \`${scopes[t.name]}\` | ${yn(t.annotations?.readOnlyHint)} | ${yn(t.annotations?.destructiveHint)} |`),
  ];
  for (const t of tools) {
    const a = t.annotations ?? {};
    const props = (t.inputSchema.properties ?? {}) as Record<string, Prop>;
    const required = new Set((t.inputSchema.required as string[] | undefined) ?? []);
    const description = (t.description ?? '').replace(dataNotice, '').trim();
    out.push(
      '',
      `## ${t.name}`,
      '',
      `**${t.title ?? t.name}** · scope \`${scopes[t.name]}\` · read-only ${yn(a.readOnlyHint)} · destructive ${yn(a.destructiveHint)} · idempotent ${yn(a.idempotentHint)} · open world ${yn(a.openWorldHint)}${t.outputSchema ? ' · structured output' : ''}`,
      '',
      ...description.split(/\n+/).flatMap((line) => [line.trim(), '']),
    );
    const names = Object.keys(props);
    if (names.length === 0) {
      out.push('No input.');
    } else {
      out.push('| Input | Type | Required | Description |', '|---|---|---|---|');
      for (const n of names) out.push(`| \`${n}\` | ${cell(typeOf(props[n]!))} | ${required.has(n) ? 'yes' : 'no'} | ${cell(props[n]!.description ?? '')} |`);
    }
  }
  return `${out.join('\n').replace(/\n{3,}/g, '\n\n')}\n`;
}

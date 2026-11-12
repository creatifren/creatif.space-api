/*
 * MCP contract smoke: the real MCP server (dist/, MCP_AUTH_MODE=real) in
 * front of the real Laravel API on a migrated, seeded test database. No
 * mocks on either side: tokens are API keys minted for a seeded Free user
 * and introspected by Laravel, and every error comes from Laravel.
 *
 * Pins two contracts every tool shares:
 *   #78  each description is in the describeTool shape — what it does,
 *        "When to use: …", "Example: <tool> {…}", "Errors → next step: …",
 *        then the data notice — with a title and the four hint annotations;
 *   #76  a refusal is the envelope {error: {code, message, next_step,
 *        details}} with `details.tool`, for a 402 (plan), a 403 (scope)
 *        and a 404 (unknown id).
 *
 * Started by scripts/mcp-contract-smoke.sh (CI job "MCP contract smoke").
 * Env: MCP_CONTRACT_URL, MCP_CONTRACT_KEY_FULL, MCP_CONTRACT_KEY_READ.
 */
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';
import type { CallToolResult, Tool } from '@modelcontextprotocol/sdk/types.js';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { DATA_NOTICE } from '../src/tools/define.js';

const need = (k: string): string => {
  const v = process.env[k];
  if (!v) throw new Error(`${k} is required (run scripts/mcp-contract-smoke.sh)`);
  return v;
};

const URL_MCP = need('MCP_CONTRACT_URL');
const KEY_FULL = need('MCP_CONTRACT_KEY_FULL');
const KEY_READ = need('MCP_CONTRACT_KEY_READ');

async function connect(key: string): Promise<Client> {
  const client = new Client({ name: 'contract-smoke', version: '1.0.0' });
  await client.connect(
    new StreamableHTTPClientTransport(new URL(URL_MCP), { requestInit: { headers: { Authorization: `Bearer ${key}` } } }),
  );
  return client;
}

type Envelope = { error: { code: string; message: string; next_step: string; details: Record<string, unknown> } };

/** The tool's refusal, checked against the #76 envelope. */
function envelope(res: CallToolResult, tool: string): Envelope['error'] {
  expect(res.isError, `${tool} should refuse`).toBe(true);
  const text = (res.content as { type: string; text?: string }[]).find((c) => c.type === 'text')?.text ?? '';
  const body = JSON.parse(text) as Envelope;
  expect(Object.keys(body)).toEqual(['error']);
  expect(Object.keys(body.error).sort()).toEqual(['code', 'details', 'message', 'next_step']);
  expect(body.error.code).toMatch(/^[a-z_]+$/);
  expect(body.error.message.trim().length).toBeGreaterThan(0);
  expect(body.error.next_step.trim().length).toBeGreaterThan(0);
  expect(body.error.details.tool).toBe(tool);
  return body.error;
}

let full: Client;
let read: Client;
let tools: Tool[];

beforeAll(async () => {
  full = await connect(KEY_FULL);
  read = await connect(KEY_READ);
  tools = (await full.listTools()).tools;
});

afterAll(async () => {
  await full?.close();
  await read?.close();
});

describe('tools/list against the real API (#78 describeTool shape)', () => {
  it('lists the tools', () => {
    expect(tools.length).toBeGreaterThanOrEqual(30);
    expect(new Set(tools.map((t) => t.name)).size).toBe(tools.length);
  });

  it('every tool: "Use …" line / does / When to use / Example / Errors → next step, then the data notice', () => {
    const bad: string[] = [];
    for (const t of tools) {
      const d = t.description ?? '';
      const lines = d.split('\n');
      const problems = [
        /^[a-z][a-z0-9_]*$/.test(t.name) ? null : 'name is not snake_case',
        t.title?.trim() ? null : 'no title',
        /^Use \S/.test(lines[0] ?? '') && (lines[0] ?? '').length <= 120 ? null : 'first line is not "Use …" (≤ 120 chars)',
        lines[1] && !/^(Use |When to use|Example|Errors)/.test(lines[1]) ? null : 'line 2 is not what it does',
        /^When to use: \S/.test(lines[2] ?? '') ? null : 'line 3 is not "When to use: …"',
        new RegExp(`^Example: ${t.name} \\{`).test(lines[3] ?? '') ? null : `line 4 is not "Example: ${t.name} {…}"`,
        lines.some((l) => /^Errors → next step: .+ → .+\.$/.test(l)) ? null : 'no "Errors → next step: code → step." line',
        d.endsWith(`\n\n${DATA_NOTICE}`) ? null : 'does not end with the data notice',
      ].filter(Boolean);
      if (problems.length) bad.push(`${t.name}: ${problems.join('; ')}`);
    }
    expect(bad, bad.join('\n')).toEqual([]);
  });

  it('every tool: input schema is an object and the four hints are set', () => {
    const bad: string[] = [];
    for (const t of tools) {
      const a = t.annotations ?? {};
      if (t.inputSchema.type !== 'object') bad.push(`${t.name}: inputSchema.type ${t.inputSchema.type}`);
      for (const h of ['readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint'] as const)
        if (typeof a[h] !== 'boolean') bad.push(`${t.name}: ${h} missing`);
      if (a.title !== t.title) bad.push(`${t.name}: annotations.title differs from title`);
    }
    expect(bad, bad.join('\n')).toEqual([]);
  });
});

describe('refusals from the real API (#76 envelope)', () => {
  it('402: a plan feature the Free account lacks → plan_required', async () => {
    const err = envelope((await full.callTool({ name: 'list_spaces', arguments: {} })) as CallToolResult, 'list_spaces');
    expect(err.code).toBe('plan_required');
  });

  it('403: a permission the key was not granted → scope_missing, naming it', async () => {
    const err = envelope(
      (await read.callTool({ name: 'draft_post', arguments: { caption: 'Contract smoke', channel_ids: ['01JZZZZZZZZZZZZZZZZZZZZZZZ'] } })) as CallToolResult,
      'draft_post',
    );
    expect(err.code).toBe('scope_missing');
    expect(err.details.required).toBe('draft');
  });

  it('404: an id that does not exist → not_found, with where to get a valid one', async () => {
    const err = envelope(
      (await full.callTool({ name: 'get_post', arguments: { post_id: '01JZZZZZZZZZZZZZZZZZZZZZZZ' } })) as CallToolResult,
      'get_post',
    );
    expect(err.code).toBe('not_found');
    expect(err.next_step).toMatch(/list_posts/);
  });

  it('a call that works still works (read-only key, list_channels)', async () => {
    const res = (await read.callTool({ name: 'list_channels', arguments: {} })) as CallToolResult;
    expect(res.isError ?? false).toBe(false);
  });
});

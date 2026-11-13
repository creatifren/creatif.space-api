#!/usr/bin/env node
/* global process, fetch, URL, console */
/*
 * Reviewer flow smoke — what a directory reviewer does first, against a live
 * environment (staging or production). QA runs it (the staff runbook is
 * kept outside this public repo).
 *
 *   1. sign in as the directory reviewer   POST /auth/reviewer/login
 *   2. mint a short-lived API key          POST /api/v1/me/api-keys (read, draft, approve)
 *   3. MCP: tools/list → list_channels → draft_post (a draft only)
 *        → create_content_plan with confirm false (a preview: creates and emails nothing)
 *   4. clean up: discard the smoke draft, revoke the key
 *
 * Nothing is published, scheduled, sent or charged. Its only writes are one
 * draft and one API key, both removed again at the end, even after a
 * failure. No secret is read from or written to a file: the password comes
 * from SMOKE_PASSWORD or a hidden prompt, the key lives in memory only.
 *
 * Env: SMOKE_EMAIL (required), SMOKE_PASSWORD (else prompted),
 *      SMOKE_API    default https://api.creatif.space
 *      SMOKE_MCP    default https://mcp.creatif.space/mcp
 *      SMOKE_ORIGIN default https://app.creatif.space (the frontend origin the API trusts for sessions)
 *
 *   cd mcp && SMOKE_EMAIL=reviewer@example.com node scripts/reviewer-smoke.mjs
 */
import { createInterface } from 'node:readline';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';

const API = (process.env.SMOKE_API ?? 'https://api.creatif.space').replace(/\/+$/, '');
const MCP = process.env.SMOKE_MCP ?? 'https://mcp.creatif.space/mcp';
const ORIGIN = (process.env.SMOKE_ORIGIN ?? 'https://app.creatif.space').replace(/\/+$/, '');
const EMAIL = process.env.SMOKE_EMAIL;

const results = [];
const step = async (name, fn) => {
  try {
    const detail = await fn();
    results.push({ name, ok: true });
    console.log(`PASS  ${name}${detail ? ` — ${detail}` : ''}`);
    return true;
  } catch (e) {
    results.push({ name, ok: false });
    console.log(`FAIL  ${name} — ${e instanceof Error ? e.message : String(e)}`);
    return false;
  }
};

function hiddenPrompt(question) {
  return new Promise((resolve) => {
    const rl = createInterface({ input: process.stdin, output: process.stdout, terminal: true });
    rl._writeToOutput = (s) => { if (s.startsWith(question)) process.stdout.write(s); };
    rl.question(question, (answer) => { rl.close(); process.stdout.write('\n'); resolve(answer); });
  });
}

/** A tiny cookie jar for the session + XSRF cookies of steps 1–2 and 4. */
const jar = new Map();
const keep = (res) => {
  for (const c of res.headers.getSetCookie?.() ?? []) {
    const [pair] = c.split(';');
    const i = pair.indexOf('=');
    jar.set(pair.slice(0, i).trim(), pair.slice(i + 1).trim());
  }
};
const cookieHeader = () => [...jar].map(([k, v]) => `${k}=${v}`).join('; ');
const xsrf = () => decodeURIComponent(jar.get('XSRF-TOKEN') ?? '');
const web = async (method, path, body) => {
  const res = await fetch(`${API}${path}`, {
    method,
    redirect: 'manual',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Origin: ORIGIN,
      Referer: `${ORIGIN}/`,
      Cookie: cookieHeader(),
      'X-XSRF-TOKEN': xsrf(),
    },
    ...(body ? { body: JSON.stringify(body) } : {}),
  });
  keep(res);
  return res;
};

/** The tool result as JSON (structuredContent, else the text block); throws on a tool error. */
async function call(client, name, args) {
  const res = await client.callTool({ name, arguments: args });
  const data = res.structuredContent ?? JSON.parse(res.content?.[0]?.text ?? '{}');
  if (res.isError) throw new Error(`${name}: ${data.error?.code ?? 'error'} — ${data.error?.message ?? ''}`);
  return data;
}

const ymd = (d) => d.toISOString().slice(0, 10);

async function main() {
  if (!EMAIL) {
    console.error('Set SMOKE_EMAIL to the directory reviewer account.');
    process.exit(2);
  }
  const password = process.env.SMOKE_PASSWORD ?? (await hiddenPrompt('Reviewer password: '));

  let keyId = null;
  let key = null;
  let draftId = null;
  let client = null;

  try {
    const signedIn = await step('1. reviewer sign-in (POST /auth/reviewer/login)', async () => {
      keep(await fetch(`${API}/sanctum/csrf-cookie`, { headers: { Origin: ORIGIN, Referer: `${ORIGIN}/` } }));
      const res = await web('POST', '/auth/reviewer/login', { email: EMAIL, password });
      if (res.status !== 204) throw new Error(`HTTP ${res.status} (expected 204)`);
    });
    if (!signedIn) return;

    const minted = await step('2. short-lived API key (read, draft, approve)', async () => {
      const res = await web('POST', '/api/v1/me/api-keys', { name: 'directory reviewer smoke', scopes: ['read', 'draft', 'approve'], expires_in_days: 30 });
      if (res.status !== 201) throw new Error(`HTTP ${res.status} (expected 201)`);
      const { data } = await res.json();
      keyId = data.id;
      key = data.key;
      return `key ${keyId} (revoked at the end)`;
    });
    if (!minted) return;

    let channelId = null;
    await step('3a. MCP connect + tools/list', async () => {
      client = new Client({ name: 'creatif-reviewer-smoke', version: '1.0.0' });
      await client.connect(new StreamableHTTPClientTransport(new URL(MCP), { requestInit: { headers: { Authorization: `Bearer ${key}` } } }));
      const { tools } = await client.listTools();
      const missing = ['list_channels', 'draft_post', 'create_content_plan', 'discard_draft'].filter((n) => !tools.some((t) => t.name === n));
      if (missing.length) throw new Error(`missing tools: ${missing.join(', ')}`);
      return `${tools.length} tools`;
    });
    if (!client) return;

    await step('3b. list_channels', async () => {
      const out = await call(client, 'list_channels', {});
      const usable = (out.channels ?? []).find((c) => !c.reconnect_required);
      if (!usable) throw new Error('no connected channel on the reviewer account (see the test pack sample data)');
      channelId = usable.id;
      return `${out.channels.length} channel(s), using ${usable.platform}`;
    });

    if (channelId) {
      await step('3c. draft_post (draft only — nothing published, no Credits)', async () => {
        const out = await call(client, 'draft_post', { caption: '[reviewer smoke] safe to delete', channel_ids: [channelId] });
        draftId = out.post?.id;
        if (out.post?.status !== 'draft') throw new Error(`status ${out.post?.status} (expected draft)`);
        return `draft ${draftId}`;
      });

      await step('3d. create_content_plan preview (confirm false — creates and emails nothing)', async () => {
        const from = new Date(Date.now() + 86_400_000);
        const to = new Date(Date.now() + 7 * 86_400_000);
        const out = await call(client, 'create_content_plan', { date_from: ymd(from), date_to: ymd(to), channel_ids: [channelId], approver_email: EMAIL, confirm: false });
        if (!out.preview) throw new Error('no preview in the answer');
        return 'preview returned';
      });
    }
  } finally {
    if (client && draftId) {
      await step('4a. clean up: discard the smoke draft', async () => {
        await call(client, 'discard_draft', { post_id: draftId });
        const out = await call(client, 'discard_draft', { post_id: draftId, confirm: true });
        if (!out.deleted) throw new Error('not deleted');
      });
    }
    await client?.close().catch(() => {});
    if (keyId) {
      await step('4b. clean up: revoke the API key', async () => {
        const res = await web('DELETE', `/api/v1/me/api-keys/${encodeURIComponent(keyId)}`);
        if (res.status >= 300) throw new Error(`HTTP ${res.status}`);
      });
    }
  }
}

await main();
const failed = results.filter((r) => !r.ok).length;
console.log(failed ? `\n${failed} step(s) failed.` : '\nAll steps passed.');
process.exit(failed ? 1 : 0);

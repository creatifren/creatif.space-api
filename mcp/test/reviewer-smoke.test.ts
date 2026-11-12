import { spawn } from 'node:child_process';
import { createServer, type Server } from 'node:http';
import type { AddressInfo } from 'node:net';
import { afterEach, describe, expect, it } from 'vitest';
import { account, post } from './fixtures.js';
import { startApp, USER } from './helpers.js';

/*
 * scripts/reviewer-smoke.mjs end to end: a fake API for the browser half
 * (CSRF cookie, reviewer login, API key mint + revoke) and the stub MCP
 * server for the tools. Checks every step passes and that the script
 * cleans up after itself (draft discarded, key revoked), and that a wrong
 * password stops it before any key is minted.
 */

const IG = '01JACCTACCTACCTACCTACCTIG1';
const DRAFT = '01JDRAFTDRAFTDRAFTDRAFTDR1';
const KEY = `stub:${USER}:read,draft,approve:Smoke`;

let app: Awaited<ReturnType<typeof startApp>> | undefined;
let api: Server | undefined;
afterEach(async () => {
  await app?.close();
  await new Promise((r) => (api ? api.close(r) : r(undefined)));
  app = undefined;
  api = undefined;
});

async function fakeApi(password: string) {
  const seen: string[] = [];
  api = createServer((req, res) => {
    let body = '';
    req.on('data', (c) => (body += c));
    req.on('end', () => {
      seen.push(`${req.method} ${req.url}`);
      if (req.url === '/sanctum/csrf-cookie') {
        res.writeHead(204, { 'Set-Cookie': ['XSRF-TOKEN=tok%3D; Path=/', 'session=s1; Path=/; HttpOnly'] }).end();
      } else if (req.url === '/auth/reviewer/login') {
        const ok = req.headers['x-xsrf-token'] === 'tok=' && JSON.parse(body).password === password;
        res.writeHead(ok ? 204 : 422, { 'Content-Type': 'application/json' }).end(ok ? undefined : '{"message":"no"}');
      } else if (req.url === '/api/v1/me/api-keys' && req.method === 'POST') {
        res.writeHead(201, { 'Content-Type': 'application/json' }).end(JSON.stringify({ data: { id: '42', key: KEY } }));
      } else if (req.url === '/api/v1/me/api-keys/42' && req.method === 'DELETE') {
        res.writeHead(204).end();
      } else res.writeHead(404).end();
    });
  });
  await new Promise<void>((r) => api!.listen(0, '127.0.0.1', r));
  return { base: `http://127.0.0.1:${(api.address() as AddressInfo).port}`, seen };
}

function run(env: Record<string, string>): Promise<{ code: number | null; out: string }> {
  return new Promise((resolve) => {
    const child = spawn(process.execPath, ['scripts/reviewer-smoke.mjs'], { env: { ...process.env, ...env }, stdio: ['ignore', 'pipe', 'pipe'] });
    let out = '';
    child.stdout.on('data', (c) => (out += c));
    child.stderr.on('data', (c) => (out += c));
    child.on('close', (code) => resolve({ code, out }));
  });
}

describe('scripts/reviewer-smoke.mjs', () => {
  it('runs the reviewer flow and cleans up after itself', async () => {
    let discarded = false;
    app = await startApp({
      routes: {
        'GET /social-accounts': () => ({ body: { data: [account(IG)] } }),
        'POST /social-posts': () => ({ status: 201, body: { data: post(DRAFT, { status: 'draft', scheduled_at: null }) } }),
        'GET /social-posts': () => ({ body: { data: [], meta: {} } }),
        [`GET /social-posts/${DRAFT}`]: () => ({ body: { data: post(DRAFT, { status: 'draft', scheduled_at: null }) } }),
        [`DELETE /social-posts/${DRAFT}/draft`]: () => {
          discarded = true;
          return { status: 204 };
        },
      },
    });
    const { base, seen } = await fakeApi('pw-123456789012');

    const { code, out } = await run({ SMOKE_API: base, SMOKE_ORIGIN: 'https://app.test', SMOKE_MCP: `${app.base}/mcp`, SMOKE_EMAIL: 'reviewer@example.test', SMOKE_PASSWORD: 'pw-123456789012' });

    expect(out).toContain('All steps passed.');
    expect(code).toBe(0);
    for (const s of ['1. reviewer sign-in', '2. short-lived API key', '3a. MCP connect', '3b. list_channels', '3c. draft_post', '3d. create_content_plan preview', '4a. clean up', '4b. clean up']) {
      expect(out).toMatch(new RegExp(`PASS  ${s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`));
    }
    expect(discarded).toBe(true);
    expect(seen).toContain('DELETE /api/v1/me/api-keys/42');
    // Nothing that sends: no approval link created, no publish/schedule.
    expect(app.v1.calls.map((c) => `${c.method} ${c.path}`).filter((c) => /approval-links|send|publish/.test(c))).toEqual([]);
    // The password and the key never reach the output.
    expect(out).not.toContain('pw-123456789012');
    expect(out).not.toContain(KEY);
  });

  it('stops at a wrong password, before any key is minted', async () => {
    app = await startApp();
    const { base, seen } = await fakeApi('right-password-1');

    const { code, out } = await run({ SMOKE_API: base, SMOKE_MCP: `${app.base}/mcp`, SMOKE_EMAIL: 'reviewer@example.test', SMOKE_PASSWORD: 'wrong-password-1' });

    expect(code).toBe(1);
    expect(out).toMatch(/FAIL {2}1\. reviewer sign-in/);
    expect(seen.some((s) => s.includes('api-keys'))).toBe(false);
  });
});

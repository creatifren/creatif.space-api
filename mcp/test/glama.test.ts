import { readFileSync } from 'node:fs';
import { afterEach, describe, expect, it } from 'vitest';
import { DEFAULT_GLAMA_CLAIM, loadConfig } from '../src/config.js';
import { ORIGIN, startApp } from './helpers.js';

const BODY = '{"$schema":"https://glama.ai/mcp/schemas/connector.json","claim":"glama_claim_U8l5ipmr_QunqGhWyIg0VXQeWfhkoEky"}';

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

describe('GET /mcp/.well-known/glama.json', () => {
  it('answers 200 with the exact claim, no token, no redirect', async () => {
    app = await startApp();
    const res = await fetch(`${app.base}/mcp/.well-known/glama.json`, { redirect: 'manual' });
    expect(res.status).toBe(200);
    expect(res.headers.get('content-type')).toMatch(/^application\/json/);
    expect(res.headers.get('access-control-allow-origin')).toBe('*');
    expect(await res.text()).toBe(BODY);
  });

  it('answers HEAD too', async () => {
    app = await startApp();
    const res = await fetch(`${app.base}/mcp/.well-known/glama.json`, { method: 'HEAD' });
    expect(res.status).toBe(200);
    expect(res.headers.get('content-type')).toMatch(/^application\/json/);
  });

  it('leaves /mcp behind auth', async () => {
    app = await startApp();
    const res = await fetch(`${app.base}/mcp`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream' },
      body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'tools/list', params: {} }),
    });
    expect(res.status).toBe(401);
  });

  it('reads the claim from GLAMA_CLAIM, defaulting to the published one', () => {
    expect(DEFAULT_GLAMA_CLAIM).toBe('glama_claim_U8l5ipmr_QunqGhWyIg0VXQeWfhkoEky');
    expect(loadConfig({ MCP_INTROSPECT_SECRET: 'x' }).glamaClaim).toBe(DEFAULT_GLAMA_CLAIM);
    expect(loadConfig({ MCP_INTROSPECT_SECRET: 'x', GLAMA_CLAIM: 'glama_claim_other' }).glamaClaim).toBe('glama_claim_other');
  });
});

describe('GET /.well-known/glama.json (origin root, where Glama looks)', () => {
  it('answers 200 with the same claim, no token, no redirect', async () => {
    app = await startApp();
    const res = await fetch(`${app.base}/.well-known/glama.json`, { redirect: 'manual' });
    expect(res.status).toBe(200);
    expect(res.headers.get('content-type')).toMatch(/^application\/json/);
    expect(res.headers.get('access-control-allow-origin')).toBe('*');
    expect(await res.text()).toBe(BODY);
  });

  it('answers HEAD too', async () => {
    app = await startApp();
    const res = await fetch(`${app.base}/.well-known/glama.json`, { method: 'HEAD' });
    expect(res.status).toBe(200);
  });

  it('holds no email or secret, only the public claim', async () => {
    app = await startApp();
    const text = await (await fetch(`${app.base}/.well-known/glama.json`)).text();
    expect(text).not.toMatch(/@/);
    expect(Object.keys(JSON.parse(text)).sort()).toEqual(['$schema', 'claim']);
  });
});

describe('repo glama.json', () => {
  it('names the GitHub account creatifren as maintainer, nothing else', () => {
    const json = JSON.parse(readFileSync(new URL('../../glama.json', import.meta.url), 'utf8'));
    expect(json).toEqual({ $schema: 'https://glama.ai/mcp/schemas/server.json', maintainers: ['creatifren'] });
  });
});

describe('unauthenticated discovery (what a directory checker sends)', () => {
  const rpc = (method: string) =>
    JSON.stringify({ jsonrpc: '2.0', id: 1, method, params: method === 'initialize' ? { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'checker', version: '1' } } : {} });

  for (const path of ['/mcp', '/']) {
    for (const method of ['initialize', 'tools/list']) {
      it(`POST ${path} ${method} without a token → 401 + WWW-Authenticate to our OAuth metadata`, async () => {
        app = await startApp();
        const res = await fetch(`${app.base}${path}`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream' },
          body: rpc(method),
          redirect: 'manual',
        });
        expect(res.status).toBe(401);
        expect(res.headers.get('www-authenticate')).toBe(
          `Bearer resource_metadata="${ORIGIN}/.well-known/oauth-protected-resource/mcp"`,
        );
        expect(await res.json()).toMatchObject({ error: 'unauthorized' });
        // The metadata it points at is public and names the authorization server.
        const meta = await (await fetch(`${app.base}/.well-known/oauth-protected-resource/mcp`)).json();
        expect(meta.authorization_servers).toEqual(['https://api.creatif.space']);
      });
    }
  }
});

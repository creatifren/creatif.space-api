import { afterEach, describe, expect, it } from 'vitest';
import { DEFAULT_GLAMA_CLAIM, loadConfig } from '../src/config.js';
import { startApp } from './helpers.js';

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

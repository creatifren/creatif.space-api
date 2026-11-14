import { afterEach, describe, expect, it } from 'vitest';
import { DEFAULT_OPENAI_APPS_CHALLENGE, loadConfig } from '../src/config.js';
import { startApp } from './helpers.js';

/* G8: OpenAI Apps domain verification at /.well-known/openai-apps-challenge (ChatGPT Apps directory, 14 Nov 2026). */

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

const PATH = '/.well-known/openai-apps-challenge';
const TOKEN = 'uYNaMuKD7ghXNsfqvWYJtgy4EnJSE7mSD51RdcYzwDI';

describe(`GET ${PATH}`, () => {
  it('serves the public OpenAI token by default: 200, text/plain, the exact body (no newline), no auth, no redirect', async () => {
    expect(DEFAULT_OPENAI_APPS_CHALLENGE).toBe(TOKEN);
    app = await startApp({});
    const res = await fetch(`${app.base}${PATH}`, { redirect: 'manual' });
    expect(res.status).toBe(200);
    expect(res.headers.get('content-type')).toMatch(/^text\/plain/);
    const body = await res.text();
    expect(body).toBe(TOKEN);
    expect(body).not.toMatch(/\s/);
    expect(res.headers.get('content-length')).toBe(String(TOKEN.length));
  });

  it('serves the token from the deps when one is set (env override)', async () => {
    app = await startApp({ openaiAppsChallenge: 'oai-challenge-abc123' });
    const res = await fetch(`${app.base}${PATH}`, { redirect: 'manual' });
    expect(res.status).toBe(200);
    expect(res.headers.get('content-type')).toMatch(/^text\/plain/);
    expect(await res.text()).toBe('oai-challenge-abc123');
  });

  it('is 404 when the token is switched off explicitly (null or empty)', async () => {
    for (const value of [null, '', '   ']) {
      app = await startApp({ openaiAppsChallenge: value });
      const res = await fetch(`${app.base}${PATH}`);
      expect(res.status, String(value)).toBe(404);
      await app.close();
      app = undefined;
    }
  });

  it('reads OPENAI_APPS_CHALLENGE (trimmed) and falls back to the public token when it is unset or empty', () => {
    const base = { MCP_AUTH_MODE: 'stub' } as NodeJS.ProcessEnv;
    expect(loadConfig({ ...base, OPENAI_APPS_CHALLENGE: ' tok-1 \n' }).openaiAppsChallenge).toBe('tok-1');
    expect(loadConfig({ ...base, OPENAI_APPS_CHALLENGE: '' }).openaiAppsChallenge).toBe(TOKEN);
    expect(loadConfig(base).openaiAppsChallenge).toBe(TOKEN);
  });
});

import { afterEach, describe, expect, it } from 'vitest';
import { loadConfig } from '../src/config.js';
import { startApp } from './helpers.js';

/* G8: OpenAI Apps domain verification at /.well-known/openai-apps-challenge. */

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

const PATH = '/.well-known/openai-apps-challenge';

describe(`GET ${PATH}`, () => {
  it('serves the token as text/plain, no auth, no redirect', async () => {
    app = await startApp({ openaiAppsChallenge: 'oai-challenge-abc123' });
    const res = await fetch(`${app.base}${PATH}`, { redirect: 'manual' });
    expect(res.status).toBe(200);
    expect(res.headers.get('content-type')).toMatch(/^text\/plain/);
    expect(await res.text()).toBe('oai-challenge-abc123');
  });

  it('is 404 when OPENAI_APPS_CHALLENGE is not set', async () => {
    for (const value of [undefined, null, '', '   ']) {
      app = await startApp(value === undefined ? {} : { openaiAppsChallenge: value });
      const res = await fetch(`${app.base}${PATH}`);
      expect(res.status, String(value)).toBe(404);
      await app.close();
      app = undefined;
    }
  });

  it('reads the token from OPENAI_APPS_CHALLENGE (trimmed; empty → null)', () => {
    const base = { MCP_AUTH_MODE: 'stub' } as NodeJS.ProcessEnv;
    expect(loadConfig({ ...base, OPENAI_APPS_CHALLENGE: ' tok-1 \n' }).openaiAppsChallenge).toBe('tok-1');
    expect(loadConfig({ ...base, OPENAI_APPS_CHALLENGE: '' }).openaiAppsChallenge).toBeNull();
    expect(loadConfig(base).openaiAppsChallenge).toBeNull();
  });
});

import { afterEach, describe, expect, it } from 'vitest';
import { PRIVACY_URL, TERMS_URL, WEBSITE_URL } from '../src/server.js';
import { startApp, TOKEN_FULL } from './helpers.js';

/* Directory readiness: serverInfo names the website; the RFC 9728 metadata names the privacy policy and terms. */

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

describe('website, privacy and terms', () => {
  it('serverInfo carries websiteUrl', async () => {
    app = await startApp();
    const client = await app.connect(TOKEN_FULL);
    expect(client.getServerVersion()).toMatchObject({ name: 'creatif.space', websiteUrl: 'https://creatif.space' });
    expect(WEBSITE_URL).toBe('https://creatif.space');
  });

  it('protected resource metadata carries resource_policy_uri and resource_tos_uri, at both well-known paths', async () => {
    app = await startApp();
    for (const path of ['/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp']) {
      const meta = (await (await fetch(`${app.base}${path}`)).json()) as Record<string, unknown>;
      expect(meta, path).toMatchObject({ resource_policy_uri: PRIVACY_URL, resource_tos_uri: TERMS_URL });
    }
    expect(PRIVACY_URL).toBe('https://creatif.space/privacy');
    expect(TERMS_URL).toBe('https://creatif.space/terms');
  });
});

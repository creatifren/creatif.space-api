import { afterEach, describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { startApp } from './helpers.js';
import { protectedResourceMetadata } from '../src/auth/middleware.js';

const SLOGAN = 'Get content approved & published faster.';

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

describe('slogan', () => {
  it('initialize instructions start with the slogan, then the guidance', async () => {
    app = await startApp();
    const client = await app.connect();
    const instructions = client.getInstructions() ?? '';
    expect(instructions.startsWith(`${SLOGAN} `)).toBe(true);
    expect(instructions).toContain('Start with list_channels.');
  });

  it('server.json carries the registry description and version 1.0.2', () => {
    const json = JSON.parse(readFileSync(new URL('../server.json', import.meta.url), 'utf8'));
    expect(json.description).toBe('Get content approved & published faster: draft, send approval links and publish from your AI chat.');
    expect(json.description.length).toBeLessThanOrEqual(100);
    expect(json.version).toBe('1.0.2');
  });

  it('protected-resource metadata documents the MCP docs page', () => {
    expect(protectedResourceMetadata('https://mcp.creatif.space/mcp', 'https://api.creatif.space').resource_documentation)
      .toBe('https://creatif.space/docs/mcp');
  });
});

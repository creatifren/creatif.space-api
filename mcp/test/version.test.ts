import { readFileSync } from 'node:fs';
import { afterEach, describe, expect, it } from 'vitest';
import { SERVER_INFO } from '../src/server.js';
import { VERSION } from '../src/version.js';
import { startApp, TOKEN_ALL } from './helpers.js';

/* One version everywhere: package.json → serverInfo, /health, the API User-Agent, and the CHANGELOG. */

const pkg = JSON.parse(readFileSync(new URL('../package.json', import.meta.url), 'utf8')) as { version: string };
const changelog = readFileSync(new URL('../CHANGELOG.md', import.meta.url), 'utf8');

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

describe('version', () => {
  it('is 0.3.0, read from package.json', () => {
    expect(pkg.version).toBe('0.3.0');
    expect(VERSION).toBe(pkg.version);
    expect(SERVER_INFO.version).toBe(pkg.version);
  });

  it('/health and the MCP serverInfo agree', async () => {
    app = await startApp({ routes: { 'GET /me': () => ({ body: { data: {} } }) } });
    const health = (await (await fetch(`${app.base}/health`)).json()) as { name: string; version: string };
    const client = await app.connect(TOKEN_ALL);
    const info = client.getServerVersion()!;
    expect(health).toEqual({ ok: true, name: info.name, version: info.version });
    expect(info.version).toBe(pkg.version);
  });

  it('sends the version in the User-Agent to the V1 API', async () => {
    app = await startApp({ routes: { 'GET /social-accounts': () => ({ body: { data: [] } }) } });
    await (await app.connect(TOKEN_ALL)).callTool({ name: 'list_channels', arguments: {} });
    expect(app.v1.calls[0]!.headers.get('user-agent')).toBe(`creatif-mcp/${pkg.version}`);
  });

  it('has a CHANGELOG entry for the current version that names every tool', async () => {
    expect(changelog).toContain(`## ${pkg.version}`);
    app = await startApp();
    const tools = (await (await app.connect(TOKEN_ALL)).listTools()).tools.map((t) => t.name);
    const missing = tools.filter((n) => !changelog.includes(`\`${n}\``));
    expect(missing, 'add these tools to CHANGELOG.md').toEqual([]);
  });
});

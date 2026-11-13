import { readFileSync } from 'node:fs';
import { afterEach, describe, expect, it } from 'vitest';
import { metadataUrls, SCOPES } from '../src/auth/middleware.js';
import { VERSION } from '../src/version.js';
import { SERVER_ICONS } from '../src/server.js';
import { RESOURCE, startApp, TOKEN_FULL } from './helpers.js';

/*
 * Registry metadata in step with the server: mcp/server.json (official MCP
 * registry) lists the same tools, prompts, scopes and version the server
 * serves; the README Tools table has one row per tool; glama.json names the
 * same owner as the registry namespace. Publishing stays manual
 * (.github/workflows/mcp-registry.yml, workflow_dispatch only).
 */

const read = (rel: string) => JSON.parse(readFileSync(new URL(rel, import.meta.url), 'utf8'));
const server = read('../server.json');
const glama = read('../../glama.json');
const readme = readFileSync(new URL('../README.md', import.meta.url), 'utf8');
const meta = server._meta['io.modelcontextprotocol.registry/publisher-provided'];

/** Top-level fields the 2025-12-11 server.schema.json defines (ServerDetail). */
const ALLOWED = ['$schema', '_meta', 'description', 'icons', 'name', 'packages', 'remotes', 'repository', 'title', 'version', 'websiteUrl'];

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

async function live() {
  app = await startApp();
  const c = await app.connect(TOKEN_FULL);
  return {
    tools: (await c.listTools()).tools.map((t) => t.name).sort(),
    prompts: (await c.listPrompts()).prompts.map((p) => p.name).sort(),
  };
}

const readmeTools = () => {
  const start = readme.indexOf('\n## Tools');
  const section = readme.slice(start, readme.indexOf('\n## ', start + 1));
  return section.split('\n').map((l) => /^\| `([a-z0-9_]+)`/.exec(l)?.[1]).filter(Boolean) as string[];
};

describe('mcp/server.json (official MCP registry)', () => {
  it('names the server, the remote and OAuth, with only schema fields', () => {
    expect(server.$schema).toBe('https://static.modelcontextprotocol.io/schemas/2025-12-11/server.schema.json');
    expect(server.name).toBe('io.github.creatifren/creatif-space');
    expect(server.remotes).toEqual([{ type: 'streamable-http', url: 'https://mcp.creatif.space/mcp' }]);
    expect(Object.keys(server).filter((k) => !ALLOWED.includes(k))).toEqual([]);
    expect(server.version).toMatch(/^\d+\.\d+\.\d+$/);
    expect(meta.auth).toMatchObject({
      type: 'oauth2',
      authorization_server: 'https://api.creatif.space',
      protected_resource_metadata: `https://mcp.creatif.space${metadataUrls(RESOURCE).pathFormPath}`,
    });
    expect(meta.auth.scopes).toEqual([...SCOPES]);
  });

  it('shows the rocket mark: a 512×512 PNG and the SVG on mcp.creatif.space, the same ones serverInfo gives', async () => {
    expect(server.icons).toEqual([
      { src: 'https://mcp.creatif.space/icon.png', mimeType: 'image/png', sizes: ['512x512'] },
      { src: 'https://mcp.creatif.space/icon.svg', mimeType: 'image/svg+xml', sizes: ['any'] },
    ]);
    expect(server.icons).toEqual(SERVER_ICONS);
    const app = await startApp();
    try {
      const client = await app.connect();
      expect(client.getServerVersion()?.icons).toEqual(SERVER_ICONS);
    } finally {
      await app.close();
    }
  });

  it('links the public source repository, in the mcp/ subfolder', () => {
    expect(server.repository).toEqual({ url: 'https://github.com/creatifren/creatif.space-api', source: 'github', subfolder: 'mcp' });
  });

  it('lists exactly the tools and prompts the server serves, and its version', async () => {
    const { tools, prompts } = await live();
    expect(meta.tools).toEqual(tools);
    expect(meta.prompts).toEqual(prompts);
    expect(meta.server_version).toBe(VERSION);
  });
});

describe('tool count in step', () => {
  it('README Tools table, server.json and the server agree', async () => {
    const { tools } = await live();
    expect(readmeTools()).toHaveLength(tools.length);
    expect(meta.tools).toHaveLength(tools.length);
  });

  it('glama.json names the owner of the registry namespace', () => {
    const owner = /^io\.github\.([^/]+)\//.exec(server.name)![1];
    expect(glama.maintainers).toContain(owner);
  });
});

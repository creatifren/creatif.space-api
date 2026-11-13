import { afterEach, describe, expect, it } from 'vitest';
import { startApp } from './helpers.js';
import { SERVER_ICONS } from '../src/server.js';

/* The connector's icon is served by the MCP server itself (Claude showed the old logo: favicon.ico was a 404). */

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

const get = async (path: string) => {
  app ??= await startApp();
  const res = await fetch(`${app.base}${path}`);
  return { res, body: Buffer.from(await res.arrayBuffer()) };
};

describe('icon routes', () => {
  it('GET /icon.png: a 512×512 PNG, long cache, no auth', async () => {
    const { res, body } = await get('/icon.png');
    expect(res.status).toBe(200);
    expect(res.headers.get('content-type')).toBe('image/png');
    expect(res.headers.get('cache-control')).toContain('max-age=31536000');
    expect(body.subarray(1, 4).toString()).toBe('PNG');
    expect([body.readUInt32BE(16), body.readUInt32BE(20)]).toEqual([512, 512]);
  });

  it('GET /favicon.ico: an ICO with 16, 32 and 48 px images', async () => {
    const { res, body } = await get('/favicon.ico');
    expect(res.status).toBe(200);
    expect(res.headers.get('content-type')).toBe('image/x-icon');
    expect(res.headers.get('cache-control')).toContain('max-age=31536000');
    expect(body.readUInt16LE(2)).toBe(1); // type: icon
    const count = body.readUInt16LE(4);
    const sizes = Array.from({ length: count }, (_, i) => body[6 + i * 16]).sort((a, b) => a! - b!);
    expect(sizes).toEqual([16, 32, 48]);
  });

  it('GET /icon.svg: the rocket mark as SVG', async () => {
    const { res, body } = await get('/icon.svg');
    expect(res.status).toBe(200);
    expect(res.headers.get('content-type')).toContain('image/svg+xml');
    expect(res.headers.get('cache-control')).toContain('max-age=31536000');
    expect(body.toString()).toMatch(/^<svg[\s\S]*<\/svg>\s*$/);
  });
});

describe('initialize', () => {
  it('serverInfo.icons: the PNG and the SVG on mcp.creatif.space, plus websiteUrl', async () => {
    app = await startApp();
    const info = (await app.connect()).getServerVersion();
    expect(info?.icons).toEqual([
      { src: 'https://mcp.creatif.space/icon.png', mimeType: 'image/png', sizes: ['512x512'] },
      { src: 'https://mcp.creatif.space/icon.svg', mimeType: 'image/svg+xml', sizes: ['any'] },
    ]);
    expect(info?.icons).toEqual(SERVER_ICONS);
    expect(info?.websiteUrl).toBe('https://creatif.space');
  });
});

describe('protected resource metadata', () => {
  it('names the served PNG as logo_uri', async () => {
    const { protectedResourceMetadata } = await import('../src/auth/middleware.js');
    expect(protectedResourceMetadata('https://mcp.creatif.space/mcp', 'https://api.creatif.space').logo_uri).toBe('https://mcp.creatif.space/icon.png');
  });
});

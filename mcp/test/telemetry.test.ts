import { afterEach, describe, expect, it } from 'vitest';
import { clientSurface, PostHogAnalytics } from '../src/analytics.js';
import { payload, startApp, TOKEN_ALL, TOKEN_READ, USER } from './helpers.js';

/*
 * mcp_tool_called: tool, client_surface, ok, code, duration_ms — metadata
 * only, never arguments, captions or anything else the user wrote.
 */

const SECRET = 'CAPTION-SECRET-Rani-launch-0042';
const CH = '01JACCTACCTACCTACCTACCTIG1';

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

const flush = () => new Promise((r) => setTimeout(r, 10));

describe('mcp_tool_called', () => {
  it('a success: tool, client_surface, ok, code "ok", duration_ms — and none of the arguments or the result', async () => {
    app = await startApp({ routes: { 'POST /social-posts': () => ({ status: 201, body: { data: { id: '01JPOSTPOSTPOSTPOSTPOSTPO1', status: 'draft', caption: SECRET, targets: [] } } }) } });
    const client = await app.connect(TOKEN_ALL);
    await client.callTool({ name: 'draft_post', arguments: { caption: SECRET, channel_ids: [CH] } });
    await flush();

    expect(app.events).toHaveLength(1);
    const { distinctId, event } = app.events[0]!;
    expect(distinctId).toBe(USER);
    expect(event).toMatchObject({ tool: 'draft_post', client_surface: 'claude', client_name: 'Claude', ok: true, code: 'ok' });
    expect(Number.isInteger(event.duration_ms) && event.duration_ms >= 0).toBe(true);
    const sent = JSON.stringify(event);
    expect(sent).not.toContain(SECRET);
    expect(sent).not.toContain(CH);
    expect(sent).not.toContain('01JPOSTPOST');
  });

  it('a refusal: ok false and the error code, still no user text', async () => {
    app = await startApp();
    const client = await app.connect(TOKEN_READ);
    const res = await client.callTool({ name: 'draft_post', arguments: { caption: SECRET, channel_ids: [CH] } });
    expect(payload(res).error.code).toBe('scope_missing');
    await flush();

    const { event } = app.events[0]!;
    expect(event).toMatchObject({ tool: 'draft_post', ok: false, code: 'scope_missing', error: 'scope_missing' });
    expect(JSON.stringify(event)).not.toContain(SECRET);
  });

  it('an upstream error is coded too', async () => {
    app = await startApp({ routes: { 'GET /social-accounts': () => ({ status: 503, body: { message: 'down' } }) } });
    await (await app.connect(TOKEN_ALL)).callTool({ name: 'list_channels', arguments: {} });
    await flush();
    expect(app.events[0]!.event).toMatchObject({ tool: 'list_channels', ok: false, code: 'upstream_unavailable' });
  });

  it('client_surface from the OAuth client name, or api_key for a personal key', () => {
    expect(clientSurface('Claude', 'oauth')).toBe('claude');
    expect(clientSurface('claude.ai', 'oauth')).toBe('claude');
    expect(clientSurface('ChatGPT', 'oauth')).toBe('chatgpt');
    expect(clientSurface('OpenAI Connector', 'oauth')).toBe('chatgpt');
    expect(clientSurface('Cursor', 'oauth')).toBe('cursor');
    expect(clientSurface('Visual Studio Code', 'oauth')).toBe('vscode');
    expect(clientSurface('My script', 'oauth')).toBe('other');
    expect(clientSurface('Claude', 'api_key')).toBe('api_key');
  });

  it('PostHog gets the event as mcp_tool_called with these properties only', async () => {
    const sent: { url: string; body: Record<string, unknown> }[] = [];
    const fetchImpl = (async (url: string, init?: RequestInit) => {
      sent.push({ url, body: JSON.parse(String(init?.body)) });
      return new Response('{}');
    }) as never;
    new PostHogAnalytics('phc_test', 'https://ph.test', fetchImpl).toolCalled(USER, {
      tool: 'list_posts', client_surface: 'chatgpt', client_name: 'ChatGPT', ok: true, code: 'ok', duration_ms: 42, ms: 42,
    });
    await flush();
    expect(sent[0]!.url).toBe('https://ph.test/i/v0/e/');
    expect(sent[0]!.body).toMatchObject({ event: 'mcp_tool_called', distinct_id: USER });
    expect(Object.keys(sent[0]!.body.properties as object).sort()).toEqual(['$lib', 'client_name', 'client_surface', 'code', 'duration_ms', 'ms', 'ok', 'tool']);
  });
});

import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';
import { ALL_TOOLS } from '../src/server.js';
import { post } from './fixtures.js';
import { payload, startApp, TOKEN_FULL } from './helpers.js';

/* Blackbox 13 Nov, P3 chain: B9, B10 (since replaced: AI captions dropped), B11, B14, B15. */

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});
const call = async (name: string, args: Record<string, unknown>, routes: Record<string, unknown>) => {
  app = await startApp({ routes: routes as never });
  return payload(await (await app.connect(TOKEN_FULL)).callTool({ name, arguments: args }));
};

describe('B9: every error code a tool throws itself is listed in its description', () => {
  it('scans each tool body in src/tools for ToolError codes', () => {
    const listed = new Map(
      ALL_TOOLS.map((t) => {
        const errs = t.description.match(/Errors → next step: (.*)$/s)?.[1] ?? '';
        return [t.name, new Set([...errs.matchAll(/(?:^|; )([a-z_]+) →/g)].map((m) => m[1]))];
      }),
    );
    const missing: string[] = [];
    for (const f of readdirSync('src/tools').filter((n) => n.endsWith('.ts'))) {
      const src = readFileSync(join('src/tools', f), 'utf8');
      const starts = [...src.matchAll(/defineTool\(\{\s*name: '([a-z_]+)'/g)];
      starts.forEach((m, i) => {
        const body = src.slice(m.index, starts[i + 1]?.index ?? src.length);
        // Only throws inside the tool's own run (up to its closing `});`) count; helpers between tools belong to whoever calls them.
        const fromRun = body.slice(body.indexOf('run:'));
        const end = fromRun.indexOf('\n});\n');
        const run = end === -1 ? fromRun : fromRun.slice(0, end);
        for (const c of run.matchAll(/ToolError\(\s*['"]([a-z_]+)['"]/g)) {
          if (!listed.get(m[1]!)?.has(c[1]!)) missing.push(`${m[1]}: ${c[1]}`);
        }
      });
    }
    expect(missing).toEqual([]);
  });

  it('schedule_posts lists invalid_input (a post listed twice)', () => {
    expect(ALL_TOOLS.find((t) => t.name === 'schedule_posts')!.description).toMatch(/invalid_input → a post is listed twice/);
  });
});

describe('AI captions dropped (13 Nov): no suggest_caption; get_credits features as the plan says', () => {
  it('lists no suggest_caption tool, and passes features.ai_caption through unchanged', async () => {
    expect(ALL_TOOLS.some((t) => t.name === 'suggest_caption')).toBe(false);
    const credits = () => ({ body: { data: { plan: 'pro', limits: { plan: { key: 'pro' }, features: { ai_caption: true, insight: true } } } } });
    const out = await call('get_credits', {}, { 'GET /credits': credits });
    expect(out.features).toEqual({ ai_caption: true, insight: true });
  });
});

describe('B11: Space visibility in words', () => {
  it('list_spaces explains "private" as link only, and the description says a draft opens for nobody', async () => {
    const space = { id: '01JSPACESPACESPACESPACESP1', title: 'Winter', slug: 'winter', status: 'published', visibility: 'private', items_count: 2, public_url: 'https://creatif.space/s/x', has_password: false, expires_at: null, published_at: null, updated_at: '2026-11-12T00:00:00Z' };
    const out = await call('list_spaces', {}, { 'GET /spaces': () => ({ body: { data: [space] } }) });
    expect(out.spaces[0]).toMatchObject({ visibility: 'private', visibility_means: expect.stringMatching(/^link only — anyone with the link can open it/) });
    const d = ALL_TOOLS.find((t) => t.name === 'list_spaces')!.description;
    expect(d).toMatch(/"private" means link only/);
    expect(ALL_TOOLS.find((t) => t.name === 'create_space_from_files')!.description).not.toMatch(/a private draft/);
  });
});

describe('B14: storage used_bytes vs the whole library', () => {
  it('get_storage_usage and get_account_summary give library_used_bytes beside the quota figure', async () => {
    const usage = { tier: 'pro', keep_used_bytes: 5_000, keep_quota_bytes: 25_000_000_000, library_used_bytes: 9_000_000 };
    const out = await call('get_storage_usage', {}, {
      'GET /files/usage': () => ({ body: { data: usage } }),
      'GET /files': () => ({ body: { data: [], meta: { last_page: 1 } } }),
    });
    expect(out.quota).toMatchObject({ used_bytes: 5_000, library_used_bytes: 9_000_000, counts: 'kept files plus media on drafts and scheduled posts' });
  });

  it('on Free the quota is the library, so the two are the same', async () => {
    const out = await call('get_storage_usage', {}, {
      'GET /files/usage': () => ({ body: { data: { tier: 'free', used_bytes: 7_000, quota_bytes: 1_000_000_000 } } }),
      'GET /files': () => ({ body: { data: [], meta: { last_page: 1 } } }),
    });
    expect(out.quota).toMatchObject({ used_bytes: 7_000, library_used_bytes: 7_000 });
  });
});

describe('B15: captions_by_channel reads back in get_post', () => {
  it('get_post returns the per-channel captions draft_post took, on the channel and as a map', async () => {
    const P = '01JPOSTPOSTPOSTPOSTPOSTPO1';
    const X = '01JACCTACCTACCTACCTACCTXX1';
    const p = post(P, {
      status: 'draft',
      targets: [
        { account: { id: '01JACCTACCTACCTACCTACCTIG1', platform: 'instagram', username: 'studio' }, caption: null, status: 'pending', fail_reason: null, platform_url: null },
        { account: { id: X, platform: 'x', username: 'studio' }, caption: 'Shorter for X', status: 'pending', fail_reason: null, platform_url: null },
      ],
    });
    const out = (await call('get_post', { post_id: P }, { [`GET /social-posts/${P}`]: () => ({ body: { data: p } }) })).post;
    expect(out.captions_by_channel).toEqual({ [X]: 'Shorter for X' });
    expect(out.channels.find((c: { channel_id: string }) => c.channel_id === X).caption).toBe('Shorter for X');
    expect(out.channels[0]).not.toHaveProperty('caption');
  });
});

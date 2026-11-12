import { afterEach, describe, expect, it } from 'vitest';
import { existsSync, readFileSync } from 'node:fs';
import { RESOURCE, TOKEN_ALL, startApp } from './helpers.js';
import { approvalTemplate, MESSAGE_MAX, NICHES } from '../src/free/approval.js';
import { CONNECT_GUIDES, CONNECT_NETWORKS, connectGuide } from '../src/free/connect.js';
import { ctaLine } from '../src/free/cta.js';
import { FREE_SERVER_ICONS } from '../src/free/server.js';

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

const CTA_PREFIX =
  'Need your client\'s yes on this? Creatif Space sends one approval link and publishes when it\'s approved — https://creatif.space/?utm_source=mcp&utm_medium=free-tools&utm_campaign=free-tools&utm_content=';

const text = (r: unknown) => (r as { content: { text: string }[] }).content.map((c) => c.text).join('\n');
const lastLine = (r: unknown) => text(r).trimEnd().split('\n').at(-1);
// eslint-disable-next-line @typescript-eslint/no-explicit-any -- test assertions
const json = (r: unknown): any => JSON.parse(text(r).slice(0, text(r).lastIndexOf('\n\n')));

const initBody = JSON.stringify({
  jsonrpc: '2.0',
  id: 1,
  method: 'initialize',
  params: { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'curl', version: '1' } },
});
const post = (base: string, path: string, headers: Record<string, string> = {}) =>
  fetch(`${base}${path}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', ...headers },
    body: initBody,
  });

describe('/free endpoint', () => {
  it('needs no auth: initialize, list and call with no Authorization header', async () => {
    app = await startApp();
    const client = await app.connectFree();
    expect(client.getServerVersion()).toMatchObject({
      name: 'Creatif Space Free Tools',
      version: '1.0.0',
      description: 'Free caption checks, client approval templates and connect guides for social media managers. No sign-in.',
    });
    expect(client.getInstructions()).toBe(
      'Free helpers for social media managers. No account needed. To draft, get client approval and publish, connect the main Creatif Space server (io.github.creatifren/creatif-space).',
    );
    const { tools } = await client.listTools();
    expect(tools.map((t) => t.name).sort()).toEqual(['approval_request_template', 'check_caption', 'connect_guide']);
    const byName = Object.fromEntries(tools.map((t) => [t.name, t]));
    expect(byName.check_caption!.description).toBe(
      'Check a social post caption against each network\'s published limits before you schedule it: length, hashtags, mentions, links. Free, no sign-in. Use it on any draft; it does not post anything.',
    );
    expect(byName.approval_request_template!.description).toBe(
      'Write a ready-to-send message asking a client to approve social posts, with what they must check and a reply deadline. Free, no sign-in.',
    );
    expect(byName.connect_guide!.description).toBe(
      'Explain what you need before connecting a social account to Creatif Space (account type, permissions) and the steps. Free, no sign-in.',
    );
    for (const t of tools) expect(t.annotations?.readOnlyHint).toBe(true);
  });

  it('answers 200 to a raw POST with no token, and no WWW-Authenticate challenge', async () => {
    app = await startApp();
    const res = await post(app.base, '/free');
    expect(res.status).toBe(200);
    expect(res.headers.get('www-authenticate')).toBeNull();
  });

  it('GET and DELETE /free are 405', async () => {
    app = await startApp();
    expect((await fetch(`${app.base}/free`)).status).toBe(405);
    expect((await fetch(`${app.base}/free`, { method: 'DELETE' })).status).toBe(405);
  });

  it('every non-error response ends with the CTA line for its tool', async () => {
    app = await startApp();
    const client = await app.connectFree();
    const calls = [
      { name: 'check_caption', arguments: { caption: 'Hello' } },
      { name: 'approval_request_template', arguments: { client_name: 'Maya', posts_count: 3, period: 'next week', deadline: 'Friday' } },
      { name: 'connect_guide', arguments: { network: 'instagram' } },
    ] as const;
    for (const call of calls) {
      const r = await client.callTool(call);
      expect(r.isError, call.name).toBeFalsy();
      expect(lastLine(r)).toBe(`${CTA_PREFIX}${call.name}`);
      expect(lastLine(r)).toBe(ctaLine(call.name));
      expect(r.structuredContent).toEqual(json(r));
    }
  });

  it('an error response carries no CTA', async () => {
    app = await startApp();
    const client = await app.connectFree();
    const r = await client.callTool({ name: 'connect_guide', arguments: { network: 'myspace' } });
    expect(r.isError).toBe(true);
    expect(text(r)).not.toContain('creatif.space/?utm_source');
  });

  it('sends free_tool_called {tool, network} and never the caption', async () => {
    app = await startApp();
    const client = await app.connectFree();
    const secret = 'SECRET caption text #launch';
    await client.callTool({ name: 'check_caption', arguments: { caption: secret, networks: ['x', 'bluesky'] } });
    await client.callTool({ name: 'check_caption', arguments: { caption: secret } });
    await client.callTool({ name: 'connect_guide', arguments: { network: 'tiktok' } });
    await client.callTool({
      name: 'approval_request_template',
      arguments: { client_name: 'Maya', posts_count: 2, period: 'May', deadline: 'Monday' },
    });
    expect(app.freeEvents).toEqual([
      { tool: 'check_caption', network: 'bluesky,x' },
      { tool: 'check_caption', network: 'all' },
      { tool: 'connect_guide', network: 'tiktok' },
      { tool: 'approval_request_template', network: null },
    ]);
    expect(JSON.stringify(app.freeEvents)).not.toMatch(/SECRET|launch|Maya/);
    // The authenticated server's analytics see nothing from /free.
    expect(app.events).toEqual([]);
  });

  it('rate limits per IP', async () => {
    app = await startApp({ freeRateLimit: 3 });
    for (let i = 0; i < 3; i++) expect((await post(app.base, '/free')).status).toBe(200);
    const limited = await post(app.base, '/free');
    expect(limited.status).toBe(429);
    expect(Number(limited.headers.get('retry-after'))).toBeGreaterThan(0);
    expect((await limited.json()).error.message).toMatch(/^rate_limited: more than 3 requests a minute from this address/);
    // Another address (as nginx reports it on loopback) has its own window.
    expect((await post(app.base, '/free', { 'X-Forwarded-For': '203.0.113.7' })).status).toBe(200);
    // The authenticated server's per-token limiter is separate.
    expect((await post(app.base, '/mcp', { Authorization: `Bearer ${TOKEN_ALL}` })).status).toBe(200);
  });
});

describe('the authenticated server is unchanged', () => {
  it('/mcp and / still require auth', async () => {
    app = await startApp();
    for (const path of ['/mcp', '/']) {
      const res = await post(app.base, path);
      expect(res.status, path).toBe(401);
      expect(res.headers.get('www-authenticate')).toContain('resource_metadata=');
    }
  });

  it('/mcp lists no free tools and keeps its own identity', async () => {
    app = await startApp();
    const client = await app.connect();
    expect(client.getServerVersion()).toMatchObject({ name: 'creatif.space', version: '0.3.0' });
    const names = (await client.listTools()).tools.map((t) => t.name);
    expect(names).not.toContain('check_caption');
    expect(names).not.toContain('connect_guide');
    expect(names).toContain('list_channels');
  });

  it('a /free token-less call does not unlock /mcp', async () => {
    app = await startApp();
    await app.connectFree();
    expect((await post(app.base, '/mcp')).status).toBe(401);
    expect(RESOURCE).toBe('https://mcp.creatif.space/mcp');
  });
});

describe('approval_request_template', () => {
  const base = { client_name: 'Maya', posts_count: 12, period: 'December', deadline: 'Friday 5pm' };

  it('friendly general copy, as approved', () => {
    const out = approvalTemplate(base);
    expect(out.message).toBe(
      'Hi Maya, 12 posts for December are ready for your review. Please check each one and reply "approve" or tell me what to change by Friday 5pm. If I don\'t hear back by then, I\'ll hold the posts — nothing goes out without your yes.',
    );
    expect(out.checklist).toEqual([
      'Names and spellings',
      'Prices, dates and offers',
      "Photos you're happy to show",
      'Links',
      'Anything legal or sensitive for your business',
    ]);
    expect(out.subject).toBe('12 posts for December — ready for your review');
    expect(out.follow_up_message).toContain('Friday 5pm');
  });

  it('formal variant and singular post', () => {
    const out = approvalTemplate({ ...base, posts_count: 1, tone: 'formal' });
    expect(out.message.startsWith('Dear Maya, 1 post for December')).toBe(true);
    expect(out.subject).toBe('For your approval: 1 post for December');
    expect(out.follow_up_message.startsWith('Dear Maya,')).toBe(true);
  });

  it('every niche resolves (general copy for now) and stays within 900 characters at max input', () => {
    const long = { client_name: 'C'.repeat(80), posts_count: 500, period: 'P'.repeat(60), deadline: 'D'.repeat(60) };
    for (const niche of NICHES) {
      for (const tone of ['friendly', 'formal'] as const) {
        const out = approvalTemplate({ ...long, niche, tone });
        expect(out.message.length).toBeLessThanOrEqual(MESSAGE_MAX);
        expect(out.message).not.toMatch(/\{\w+\}/);
        expect(out.follow_up_message).not.toMatch(/\{\w+\}/);
        expect(out.checklist.length).toBe(5);
      }
      expect(approvalTemplate({ ...base, niche })).toEqual(approvalTemplate(base));
    }
  });

  it('the JSON is keyed by every niche', () => {
    const json = JSON.parse(readFileSync(new URL('../free-tools/approval-templates.json', import.meta.url), 'utf8'));
    expect(Object.keys(json).sort()).toEqual([...NICHES].sort());
  });
});

describe('connect_guide', () => {
  it('covers the 9 networks the API connects', () => {
    const enumSrc = readFileSync(new URL('../../app/Enums/SocialPlatform.php', import.meta.url), 'utf8');
    const values = [...enumSrc.matchAll(/case \w+ = '(\w+)';/g)].map((m) => m[1]);
    expect([...CONNECT_NETWORKS].sort()).toEqual(values.sort());
    expect(Object.keys(CONNECT_GUIDES.networks).sort()).toEqual(values.sort());
  });

  it('every network has steps, requirements, errors and the help URL; no evidence leaks', () => {
    for (const network of CONNECT_NETWORKS) {
      const g = connectGuide({ network });
      expect(g.steps[0]).toBe('Open Creatif Space and go to Channels.');
      expect(g.steps.at(-1)).toBe("You're back in Channels with the account listed.");
      expect(g.requirements.length).toBeGreaterThan(0);
      expect(g.common_errors.length).toBeGreaterThan(0);
      expect(Array.isArray(g.notes)).toBe(true);
      expect(g.help_url).toBe('https://creatif.space/social/connect');
      expect(JSON.stringify(g)).not.toMatch(/\.php|evidence|\{network\}/);
    }
  });

  it('known facts: Instagram engage + 24 h, Facebook comments, Bluesky app password', () => {
    const ig = connectGuide({ network: 'instagram' });
    expect(ig.requirements.join(' ')).toContain('Engage for Instagram covers DMs and comments');
    expect(ig.requirements.join(' ')).toContain('within 24 hours');
    expect(connectGuide({ network: 'facebook' }).requirements.join(' ')).toContain('Engage for Facebook covers comments');
    const bs = connectGuide({ network: 'bluesky' });
    expect(bs.steps.join(' ')).toContain('app password');
    expect(bs.steps.join(' ')).not.toContain('Allow access');
    expect(connectGuide({ network: 'x' }).steps).toContain('Sign in on X\'s own page.');
  });

  it('plan room states what PlanSeeder says: Free 3, Pro and Max no limit', () => {
    const seeder = readFileSync(new URL('../../database/seeders/PlanSeeder.php', import.meta.url), 'utf8').split('\n');
    expect(seeder[124]).toContain("'social_accounts' => 3,");
    expect(seeder[99]).toContain("'social_accounts' => null,");
    expect(seeder[94]).toContain('null = no limit');
    expect(seeder[145]).toContain('$paidQuotas');
    expect(seeder[156]).toContain('$paidQuotas');
    const line = 'Room on your plan: Free connects up to 3 social accounts; Pro and Max have no limit.';
    for (const network of CONNECT_NETWORKS) expect(connectGuide({ network }).requirements).toContain(line);
  });

  it('TikTok and Threads delete behaviour is a note, not a connect error', () => {
    for (const network of ['tiktok', 'threads'] as const) {
      const g = connectGuide({ network });
      expect(g.notes.join(' ')).toContain('does not let apps delete posts');
      expect(g.common_errors.join(' ')).not.toContain('delete posts');
    }
  });

  // Per network: what the code proves (confirmed) and what it does not (check in app).
  const ACCOUNT: Record<(typeof CONNECT_NETWORKS)[number], { confirmed: string[]; check: string[] }> = {
    instagram: { confirmed: ['Business or Creator account', 'instagram_business_*'], check: ['linked to a Facebook Page'] },
    facebook: { confirmed: ['A Facebook Page', 'choose the Pages'], check: ['role on the Page'] },
    linkedin: { confirmed: ['A LinkedIn page', 'choose from the pages'], check: ['role on the page', 'personal profile'] },
    tiktok: { confirmed: [], check: ['TikTok account types'] },
    youtube: { confirmed: [], check: ['YouTube channel'] },
    x: { confirmed: [], check: ['X account types'] },
    threads: { confirmed: [], check: ['Threads account types'] },
    pinterest: { confirmed: ['at least one board'], check: ['Pinterest business account'] },
    bluesky: { confirmed: ['app password'], check: [] },
  };

  it.each(CONNECT_NETWORKS)('%s: account type is confirmed from the code or marked "check in app"', (network) => {
    const g = connectGuide({ network });
    const want = ACCOUNT[network];
    const confirmed = g.account_type.filter((a) => a.status === 'confirmed').map((a) => a.text).join(' ');
    const check = g.account_type.filter((a) => a.status === 'check in app').map((a) => a.text).join(' ');
    for (const phrase of want.confirmed) expect(confirmed).toContain(phrase);
    for (const phrase of want.check) expect(check).toContain(phrase);
    if (want.confirmed.length === 0) expect(confirmed).toBe('');
    if (want.check.length === 0) expect(check).toBe('');
    expect(g.account_type.length).toBeGreaterThan(0);
    for (const a of g.account_type) {
      expect(Object.keys(a).sort()).toEqual(['status', 'text']);
      expect(['confirmed', 'check in app']).toContain(a.status);
    }
  });

  it('a "check in app" item carries no evidence; a confirmed one always does', () => {
    for (const n of Object.values(CONNECT_GUIDES.networks)) {
      for (const a of n.account_type) {
        if (a.status === 'confirmed') expect(a.evidence.length, a.text).toBeGreaterThan(0);
        else expect('evidence' in a, a.text).toBe(false);
      }
    }
  });

  it('the Instagram scopes it cites are the instagram_business_* defaults in config', () => {
    const cfg = readFileSync(new URL('../../config/services.php', import.meta.url), 'utf8').split('\n');
    expect(cfg[116]).toMatch(/'instagram_business_basic,instagram_business_content_publish/);
    expect(cfg[112]).toContain('Instagram sign-in');
  });

  it('every evidence reference points at a real file and line in this repo', () => {
    const items = [
      ...Object.values(CONNECT_GUIDES.common).flat(),
      ...Object.values(CONNECT_GUIDES.networks).flatMap((n) => [...n.requirements, ...n.common_errors, ...n.notes]),
      ...Object.values(CONNECT_GUIDES.networks).flatMap((n) => n.account_type.filter((a) => a.status === 'confirmed')),
    ];
    for (const item of items) {
      expect(item.evidence.length, item.text).toBeGreaterThan(0);
      for (const ref of item.evidence) {
        const [file, lines] = ref.split(':');
        const url = new URL(`../../${file}`, import.meta.url);
        expect(existsSync(url), ref).toBe(true);
        const count = readFileSync(url, 'utf8').split('\n').length;
        const last = Number(lines!.split('-').at(-1));
        expect(last, ref).toBeLessThanOrEqual(count);
      }
    }
  });
});

describe('free-tools/server.json', () => {
  it('names the free server and is not the main one', () => {
    const json = JSON.parse(readFileSync(new URL('../free-tools/server.json', import.meta.url), 'utf8'));
    expect(json.name).toBe('io.github.creatifren/creatif-space-free-tools');
    expect(json.version).toBe('1.1.0');
    expect(json.description.length).toBeLessThanOrEqual(100);
    expect(json.remotes).toEqual([{ type: 'streamable-http', url: 'https://mcp.creatif.space/free' }]);
    expect(json.repository).toEqual({ url: 'https://github.com/creatifren/creatif.space-api', source: 'github', subfolder: 'mcp' });
    expect(json.icons).toEqual(FREE_SERVER_ICONS);
  });

  it('lists exactly the free tools served on /free, no auth, and the same icon as serverInfo', async () => {
    const json = JSON.parse(readFileSync(new URL('../free-tools/server.json', import.meta.url), 'utf8'));
    const meta = json._meta['io.modelcontextprotocol.registry/publisher-provided'];
    const app = await startApp();
    try {
      const client = await app.connectFree();
      expect(meta.tools).toEqual((await client.listTools()).tools.map((t) => t.name).sort());
      expect(client.getServerVersion()?.icons).toEqual(FREE_SERVER_ICONS);
    } finally {
      await app.close();
    }
    expect(meta.auth.type).toBe('none');
    expect(meta.tools).toHaveLength(3);
  });
});

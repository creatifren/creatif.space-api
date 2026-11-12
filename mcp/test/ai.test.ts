import { afterEach, describe, expect, it } from 'vitest';
import { payload, startApp, TOKEN_ALL, TOKEN_READ } from './helpers.js';

/*
 * suggest_caption over a fake of POST /ai/caption that answers the way
 * AiCaptionController does with the fake provider (AI_CAPTION_PROVIDER=fake):
 * 404 while AI_CAPTION_ENABLED is off, 402 plan_required on Free, 422 on a bad
 * brief, 502 when the provider fails, else the brief back plus hashtags.
 */

const BILLING = 'https://creatif.space/settings?section=subscription';

function fakeAi(opts: { enabled?: boolean; tier?: 'free' | 'pro' | 'max'; failing?: boolean; setUp?: boolean } = {}) {
  const { enabled = true, tier = 'pro', failing = false, setUp = true } = opts;
  return {
    'POST /ai/caption': ({ body }: { body: unknown }) => {
      if (!enabled) return { status: 404, body: { message: 'Not Found' } };
      if (tier === 'free') {
        return {
          status: 402,
          body: {
            message: `AI captions and hashtags is on Pro and Max. Start a free trial or upgrade: ${BILLING}`,
            reason: 'plan_required',
            code: 'plan_required',
            feature: 'ai_caption',
            required_plan: 'pro',
            billing_url: BILLING,
            errors: { brief: ['AI captions and hashtags is on Pro and Max.'] },
          },
        };
      }
      const { brief, platform } = body as { brief: string; platform?: string };
      if (platform === 'myspace') return { status: 422, body: { message: 'The selected platform is invalid.', errors: { platform: ['The selected platform is invalid.'] } } };
      if (!setUp) return { status: 503, body: { message: 'AI is not set up.' } };
      if (failing) return { status: 502, body: { message: 'The AI did not answer — try again.' } };
      const words = brief.split(/\s+/).filter(Boolean);
      return { body: { data: { caption: brief, hashtags: [...words.map((w) => `#${w}`), '#tag1', '#tag2', '#tag3'].slice(0, 5) } } };
    },
  };
}

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

async function call(args: Record<string, unknown>, routes = fakeAi(), token = TOKEN_ALL) {
  app = await startApp({ routes });
  const client = await app.connect(token);
  return client.callTool({ name: 'suggest_caption', arguments: args });
}

describe('suggest_caption', () => {
  it('returns a caption and hashtags from the brief, and saves nothing', async () => {
    const res = await call({ brief: 'Kopi pagi', platform: 'instagram' });
    expect(res.isError).toBeFalsy();
    expect(payload(res)).toEqual({
      caption: 'Kopi pagi',
      hashtags: ['#Kopi', '#pagi', '#tag1', '#tag2', '#tag3'],
      platform: 'instagram',
      next_step: expect.stringMatching(/nothing was saved.*draft_post/s),
    });
    expect(app!.v1.calls).toHaveLength(1);
    const sent = app!.v1.calls[0]!;
    expect(sent).toMatchObject({ method: 'POST', path: '/ai/caption', body: { brief: 'Kopi pagi', platform: 'instagram' } });
    expect(sent.headers.get('x-mcp-tool')).toBe('suggest_caption');
  });

  it('leaves platform out when it is not given', async () => {
    await call({ brief: 'Kopi pagi' });
    expect(app!.v1.calls[0]!.body).toEqual({ brief: 'Kopi pagi' });
  });

  it('flag off (404) → not_enabled: says plainly it is not switched on, next step write it yourself', async () => {
    const res = await call({ brief: 'Kopi pagi' }, fakeAi({ enabled: false }));
    expect(res.isError).toBe(true);
    const { error } = payload(res);
    expect(error).toMatchObject({
      code: 'not_enabled',
      message: 'AI captions are not switched on for this account yet.',
      details: { tool: 'suggest_caption', feature: 'ai_caption', reason: 'feature_off', retryable: false },
    });
    expect(error.next_step).toMatch(/not switched on yet/);
    expect(error.next_step).toMatch(/write the caption yourself/);
    expect(error.next_step).toMatch(/Do not retry/);
  });

  it('provider not set up (503) → not_enabled too', async () => {
    const { error } = payload(await call({ brief: 'Kopi pagi' }, fakeAi({ setUp: false })));
    expect(error).toMatchObject({ code: 'not_enabled', details: { reason: 'not_set_up' } });
    expect(error.next_step).toMatch(/write the caption yourself/);
  });

  it('Free (402) → plan_required with the API message and billing link', async () => {
    const { error } = payload(await call({ brief: 'Kopi pagi' }, fakeAi({ tier: 'free' })));
    expect(error).toMatchObject({
      code: 'plan_required',
      message: expect.stringContaining('AI captions and hashtags is on Pro and Max.'),
      details: { feature: 'ai_caption', required_plan: 'pro', billing_url: BILLING },
    });
    expect(error.next_step).toMatch(/Do not retry/);
  });

  it('provider failed (502) → upstream_unavailable: once more, then write it yourself', async () => {
    const { error } = payload(await call({ brief: 'Kopi pagi' }, fakeAi({ failing: true })));
    expect(error).toMatchObject({ code: 'upstream_unavailable', message: 'The AI did not answer — try again.', details: { retryable: true } });
    expect(error.next_step).toMatch(/once more.*write the caption yourself/s);
  });

  it('a viewer (403) → forbidden', async () => {
    const { error } = payload(await call({ brief: 'x' }, { 'POST /ai/caption': () => ({ status: 403, body: { message: 'Viewers cannot write captions.' } }) }));
    expect(error).toMatchObject({ code: 'forbidden', message: 'Viewers cannot write captions.' });
  });

  it('refuses an empty or too long brief and an unknown platform before calling the API', async () => {
    for (const args of [{ brief: '' }, { brief: 'x'.repeat(2001) }, { brief: 'ok', platform: 'myspace' }]) {
      const res = await call(args);
      expect(payload(res).error.code).toBe('invalid_input');
      expect(app!.v1.calls).toHaveLength(0);
      await app!.close();
      app = undefined;
    }
  });

  it('needs the draft scope and calls nothing without it', async () => {
    const { error } = payload(await call({ brief: 'Kopi pagi' }, fakeAi(), TOKEN_READ));
    expect(error).toMatchObject({ code: 'scope_missing', details: { required: 'draft' } });
    expect(app!.v1.calls).toHaveLength(0);
  });

  it('describes itself in the describeTool shape, naming not_enabled and plan_required', async () => {
    app = await startApp();
    const { tools } = await (await app.connect()).listTools();
    const t = tools.find((x) => x.name === 'suggest_caption')!;
    expect(t.description).toMatch(/^Writes a caption and hashtags in the user's own style from a short brief, used by the plan_week prompt\.\nWrites a caption and hashtags/);
    expect(t.description).toMatch(/\nWhen to use: /);
    expect(t.description).toMatch(/\nExample: suggest_caption \{/);
    expect(t.description).toMatch(/not_enabled → AI captions are not switched on yet: write the caption yourself/);
    expect(t.description).toMatch(/plan_required → /);
    expect(t.annotations).toMatchObject({ readOnlyHint: true, destructiveHint: false });
  });
});

import { describe, expect, it } from 'vitest';
import { PostHogAnalytics } from '../src/analytics.js';

/** A fetch that records each PostHog capture body. */
function recorder() {
  const bodies: Record<string, unknown>[] = [];
  const fetchImpl = async (_url: string, init?: RequestInit) => {
    bodies.push(JSON.parse(String(init?.body)));
    return new Response('{}', { status: 200 });
  };
  return { bodies, fetchImpl };
}

describe('PostHogAnalytics', () => {
  it('free_tool_called: tool and network only, no person profile, no geo-IP', async () => {
    const { bodies, fetchImpl } = recorder();
    new PostHogAnalytics('phc_test', 'https://us.i.posthog.com', fetchImpl as never).freeToolCalled({ tool: 'connect_guide', network: 'instagram' });
    await new Promise((r) => setTimeout(r, 0));

    expect(bodies).toHaveLength(1);
    expect(bodies[0]).toMatchObject({ event: 'free_tool_called', distinct_id: 'mcp-free-tools' });
    expect(bodies[0]!.properties).toEqual({
      tool: 'connect_guide',
      network: 'instagram',
      $lib: 'creatif-mcp',
      $process_person_profile: false,
      $geoip_disable: true,
    });
  });
});

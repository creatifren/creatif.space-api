import { readFileSync } from 'node:fs';
import { afterEach, describe, expect, it } from 'vitest';
import { payload, startApp, TOKEN_ALL } from './helpers.js';

/* docs/telemetry.md: its HogQL reads only properties mcp_tool_called carries. */

const doc = readFileSync(new URL('../docs/telemetry.md', import.meta.url), 'utf8');
/** Fields of older events (before code and duration_ms), read as fallbacks. */
const LEGACY = ['error'];

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

describe('docs/telemetry.md', () => {
  it('queries mcp_tool_called and reads only the properties the server sends', async () => {
    app = await startApp({ routes: { 'GET /credits': () => ({ status: 503, body: {} }) } });
    const client = await app.connect(TOKEN_ALL);
    payload(await client.callTool({ name: 'list_channels', arguments: {} }));
    await new Promise((r) => setTimeout(r, 10));
    const sent = new Set([...Object.keys(app.events[0]!.event), ...LEGACY]);

    const sql = [...doc.matchAll(/```sql\n([\s\S]*?)```/g)].map((m) => m[1]!);
    expect(sql.length).toBeGreaterThanOrEqual(2);
    for (const q of sql) {
      expect(q).toContain("event = 'mcp_tool_called'");
      expect(q, 'every query has a time window').toMatch(/timestamp >= now\(\) - INTERVAL \d+ DAY/);
    }
    const read = [...new Set(sql.join('\n').match(/properties\.([a-z_]+)/g)!.map((p) => p.slice('properties.'.length)))];
    expect(read.filter((p) => !sent.has(p))).toEqual([]);
    // The two asked-for views are there.
    expect(doc).toMatch(/## p50 \/ p95 duration per tool/);
    expect(doc).toMatch(/## Error rate per code/);
  });
});

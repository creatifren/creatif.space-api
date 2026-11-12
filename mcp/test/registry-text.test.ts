import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

/*
 * Registry entries: Growth's final names, titles and descriptions, and the
 * registry's limit — a description is at most 100 characters.
 */

const read = (rel: string) => JSON.parse(readFileSync(new URL(rel, import.meta.url), 'utf8'));
const ENTRIES = { main: read('../server.json'), free: read('../free-tools/server.json') };

describe('registry entries (server.json files)', () => {
  it.each(Object.entries(ENTRIES))('%s: description is at most 100 characters', (_name, entry) => {
    expect(typeof entry.description).toBe('string');
    expect(entry.description.length).toBeGreaterThan(0);
    expect(entry.description.length).toBeLessThanOrEqual(100);
  });

  it('main entry: Growth’s title and description, the same name, remote and websiteUrl', () => {
    expect(ENTRIES.main).toMatchObject({
      name: 'io.github.creatifren/creatif-space',
      title: 'Creatif Space',
      description: 'Get content approved & published faster: draft posts, send client approval links, publish from AI.',
      websiteUrl: 'https://creatif.space/mcp',
      remotes: [{ type: 'streamable-http', url: 'https://mcp.creatif.space/mcp' }],
    });
    expect(ENTRIES.main.description).toHaveLength(98);
  });

  it('free tools entry: creatif-space-free-tools, its title, description, remote and websiteUrl', () => {
    expect(ENTRIES.free).toMatchObject({
      name: 'io.github.creatifren/creatif-space-free-tools',
      title: 'Creatif Space Free Tools',
      description: 'Free social media tools for your AI chat: caption limit check, client approval message, no login.',
      websiteUrl: 'https://creatif.space/free-tools',
      remotes: [{ type: 'streamable-http', url: 'https://mcp.creatif.space/free' }],
    });
    expect(ENTRIES.free.description).toHaveLength(97);
  });
});

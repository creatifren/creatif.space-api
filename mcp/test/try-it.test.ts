import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { PROMPT_TOOLS } from '../src/prompts.js';

/* The README's "Try it in 60 seconds" section: Claude only, the connector
   URL, and one example per ready-made prompt. */

const readme = readFileSync(new URL('../README.md', import.meta.url), 'utf8');
const start = readme.indexOf('\n## Try it in 60 seconds (Claude)');
const section = readme.slice(start, readme.indexOf('\n## ', start + 1));

describe('README: Try it in 60 seconds', () => {
  it('comes before the reference sections', () => {
    expect(start).toBeGreaterThan(0);
    expect(start).toBeLessThan(readme.indexOf('\n## Endpoints'));
  });

  it('gives the connector URL and the Claude steps', () => {
    expect(section).toContain('https://mcp.creatif.space/mcp');
    expect(section).toContain('Settings → Connectors → Add custom connector');
  });

  it('has an example ask for every ready-made prompt', () => {
    for (const prompt of Object.keys(PROMPT_TOOLS)) expect(section, prompt).toContain(`\`${prompt}\``);
  });

  it('is for Claude only: no "or ChatGPT"', () => {
    expect(section).not.toMatch(/chatgpt|openai/i);
  });
});

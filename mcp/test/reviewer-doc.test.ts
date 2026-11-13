import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

/*
 * docs/reviewer.md is the public page for directory reviewers: how to sign
 * in, the sample data, the 8 test prompts. Its prompts are the test pack's,
 * word for word, and it carries no internal process, server paths,
 * credentials or prices (this repository is public).
 */

const read = (f: string) => readFileSync(new URL(`../docs/${f}`, import.meta.url), 'utf8');
const reviewer = read('reviewer.md');
const pack = read('directory-test-pack.md');

/** id → prompt text, from lines like **P1 — “…”** (pack) or - **P1** “…” (reviewer). */
const prompts = (md: string) =>
  Object.fromEntries([...md.matchAll(/\*\*([PN]\d)(?: — |\*\* )“([^”]+)”/g)].map((m) => [m[1]!, m[2]!]));

describe('docs/reviewer.md', () => {
  it('has the 8 prompts of the test pack, word for word', () => {
    const mine = prompts(reviewer);
    expect(Object.keys(mine).sort()).toEqual(['N1', 'N2', 'N3', 'P1', 'P2', 'P3', 'P4', 'P5']);
    expect(mine).toEqual(prompts(pack));
  });

  it('says how to sign in and where to connect', () => {
    expect(reviewer).toContain('https://mcp.creatif.space/mcp');
    expect(reviewer).toContain('Sign in with email');
    expect(reviewer).toContain('Allow');
  });

  it('has no internal process, server paths, secrets or prices', () => {
    const banned = [
      /artisan/i, /reviewer:grant/i, /--revoke/i, /\bssh\b/i, /\/var\//, /\/home\//, /\/etc\//, /\.env\b/i,
      /runbook/i, /\bstaff\b/i, /\bRizal\b/i, /\bQA\b/, /mcp_audit_logs/, /directory_reviewer_logins/,
      /\/auth\/reviewer/, /password\s*[:=]/i, /[$€£]\s?\d/, /\bupgrade\b(?! me)/i,
    ];
    const hits = banned.filter((re) => re.test(reviewer)).map(String);
    expect(hits, `docs/reviewer.md must stay reviewer-facing: ${hits.join(', ')}`).toEqual([]);
  });
});

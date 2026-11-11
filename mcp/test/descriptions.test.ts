import { afterEach, describe, expect, it } from 'vitest';
import { startApp, TOKEN_FULL } from './helpers.js';

const SLOGAN = /approved & published faster/i;

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

const tools = async () => {
  app = await startApp();
  return (await (await app.connect(TOKEN_FULL)).listTools()).tools;
};

describe('tool descriptions for agents', () => {
  it('every tool: one line on what it does, when to use it, an example call, errors with next steps', async () => {
    const list = await tools();
    expect(list).toHaveLength(13);
    for (const t of list) {
      const lines = t.description!.split('\n');
      expect(lines[0], t.name).not.toMatch(/^(When to use|Example|Errors)/);
      expect(lines[0]!.length, t.name).toBeLessThan(260);
      expect(lines[1], t.name).toMatch(/^When to use: /);
      expect(lines[2], t.name).toMatch(new RegExp(`^Example: ${t.name} \\{`));
      const errors = lines.find((l) => l.startsWith('Errors → next step: '));
      expect(errors, t.name).toBeDefined();
      // code → next step, for each error listed
      expect(errors!.match(/ → /g)!.length, t.name).toBeGreaterThanOrEqual(3);
      expect(t.description, t.name).not.toMatch(SLOGAN);
    }
  });

  it('the "when to use" lines point at the tool to use instead', async () => {
    const d = Object.fromEntries((await tools()).map((t) => [t.name, t.description!]));
    expect(d.list_files).toMatch(/use request_media_upload instead/);
    expect(d.draft_post).toMatch(/use update_post instead/);
    expect(d.publish_post).toMatch(/use schedule_post for later/);
    expect(d.schedule_post).toMatch(/use publish_post to send now/);
    expect(d.get_post).toMatch(/check_approval_status is shorter/);
    expect(d.create_approval_link).toMatch(/use check_approval_status with link_id/);
  });

  it('errors named in descriptions use the codes tools return', async () => {
    const d = Object.fromEntries((await tools()).map((t) => [t.name, t.description!]));
    expect(d.create_approval_link).toMatch(/not_approvable → .*list_posts status "draft"/);
    expect(d.publish_post).toMatch(/not_draft → /);
    expect(d.update_post).toMatch(/not_editable → /);
  });
});

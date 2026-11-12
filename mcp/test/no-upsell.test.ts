import { afterEach, describe, expect, it } from 'vitest';
import { startApp, TOKEN_FULL } from './helpers.js';

/*
 * Directory readiness (G2): no tool description — for any client — talks
 * price, plan names or upgrading. Limits and Credits may be named; what
 * they cost or how to buy more is for creatif.space itself, not the model.
 */
const UPSELL = /upgrad|\bpric(e|es|ing)\b|\btrial\b|subscription|billing|\bpaid\b|\$\s?\d|\btop up\b|\bbuy\b|purchase/i;
/** Plan tier names, case-sensitive ("max" is a limit, "Max" a plan). */
const TIERS = /\b(Free|Pro|Max|Enterprise|Business|Premium|Starter)\b/;

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

describe('tool descriptions carry no price or upgrade wording', () => {
  for (const client of ['Claude', 'ChatGPT']) {
    it(`for ${client}`, async () => {
      app = await startApp();
      const token = TOKEN_FULL.replace(/:Claude$/, `:${client}`);
      const { tools } = await (await app.connect(token)).listTools();
      const offenders = tools.flatMap((t) => {
        const texts = [t.title ?? '', t.description ?? '', JSON.stringify(t.inputSchema)];
        return texts.flatMap((s) =>
          [UPSELL, TIERS].flatMap((re) => {
            const m = re.exec(s);
            return m ? [`${t.name}: …${s.slice(Math.max(0, m.index - 40), m.index + 40)}…`] : [];
          }),
        );
      });
      expect(offenders).toEqual([]);
    });
  }
});

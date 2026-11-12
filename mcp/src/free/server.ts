import express, { type NextFunction, type Request, type Response } from 'express';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import type { CallToolResult } from '@modelcontextprotocol/sdk/types.js';
import { z } from 'zod';
import type { FreeAnalytics } from '../analytics.js';
import { ToolError } from '../errors.js';
import { structureSdkErrors } from '../tools/define.js';
import { TokenRateLimiter } from '../rateLimit.js';
import { approvalInput, approvalTemplate } from './approval.js';
import { checkCaption, checkCaptionInput } from './checkCaption.js';
import { connectGuide, connectInput } from './connect.js';
import { ctaLine, type FreeToolName } from './cta.js';

/** The Pen Rocket, as in free-tools/server.json (served by the web app). */
export const FREE_SERVER_ICONS = [{ src: 'https://creatif.space/icons/icon-512.png', mimeType: 'image/png', sizes: ['512x512'] }];

export const FREE_SERVER_INFO = {
  name: 'Creatif Space Free Tools',
  version: '1.0.0',
  icons: FREE_SERVER_ICONS,
  description: 'Free caption checks, client approval templates and connect guides for social media managers. No sign-in.',
};

export const FREE_INSTRUCTIONS =
  'Free helpers for social media managers. No account needed. ' +
  'To draft, get client approval and publish, connect the main Creatif Space server (io.github.creatifren/creatif-space).';

const ANNOTATIONS = { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false };

interface FreeTool<S extends z.ZodRawShape> {
  name: FreeToolName;
  title: string;
  description: string;
  input: S;
  run: (args: z.infer<z.ZodObject<S>>) => unknown;
  /** The `network` property of free_tool_called. Never caption text. */
  network: (args: z.infer<z.ZodObject<S>>) => string | null;
}
const tool = <S extends z.ZodRawShape>(t: FreeTool<S>) => t;

/** The same failure every /free tool can meet besides bad arguments. */
const RETRY: [string, string] = ['internal_error', 'call it once more; if it fails again, answer without this tool'];

/**
 * The agent shape the /mcp tools use (#78): what it does, when to use it,
 * an example, and each error with its next step. No sign-in, so no
 * 401/402/403 lines.
 */
export function describeFreeTool(d: { does: string; when: string; example: string; errors: [string, string][] }): string {
  const errors = [...d.errors, RETRY];
  return [d.does, `When to use: ${d.when}`, `Example: ${d.example}`, `Errors → next step: ${errors.map(([c, n]) => `${c} → ${n}`).join('; ')}.`].join('\n');
}

export const FREE_TOOLS = [
  tool({
    name: 'check_caption',
    title: 'Check caption limits',
    description: describeFreeTool({
      does: 'Check a social post caption against each network\'s published limits before you schedule it: length, hashtags, mentions, links. Free, no sign-in. Use it on any draft; it does not post anything.',
      when: 'before a caption is scheduled or sent to a client, to see which networks it fits; pass networks to check only those.',
      example: 'check_caption { "caption": "New drop today #launch", "networks": ["x", "instagram"] } → { summary, results: [{ network, length, limit, ok, hashtags }] }',
      errors: [['invalid_input', 'fix the fields in details.issues (an empty caption, a network not in the input schema) and call again']],
    }),
    input: checkCaptionInput,
    run: (a) => checkCaption(a),
    network: (a) => (a.networks ? [...new Set(a.networks)].sort().join(',') : 'all'),
  }),
  tool({
    name: 'approval_request_template',
    title: 'Client approval message',
    description: describeFreeTool({
      does: 'Write a ready-to-send message asking a client to approve social posts, with what they must check and a reply deadline. Free, no sign-in.',
      when: 'when the user wants to ask a client for approval by email or chat. For approvals the client clicks and that are tracked, connect the main Creatif Space server and use create_approval_link.',
      example: 'approval_request_template { "client_name": "Maya", "posts_count": 3, "period": "next week", "deadline": "Friday" } → { subject, message, checklist, follow_up_message }',
      errors: [['invalid_input', 'fix the fields in details.issues (client_name, posts_count 1–500, period, deadline) and call again']],
    }),
    input: approvalInput,
    run: (a) => approvalTemplate(a),
    network: () => null,
  }),
  tool({
    name: 'connect_guide',
    title: 'Connect guide',
    description: describeFreeTool({
      does: 'Explain what you need before connecting a social account to Creatif Space (account type, permissions) and the steps. Free, no sign-in.',
      when: 'before the user connects a network, to say which account type it needs and what the sign-in will ask for.',
      example: 'connect_guide { "network": "instagram" } → { network, account_type, requirements, steps, common_errors, notes, help_url }',
      errors: [['invalid_input', 'use one of the networks the input schema lists, and call again']],
    }),
    input: connectInput,
    run: (a) => connectGuide(a),
    network: (a) => a.network,
  }),
];

export function buildFreeServer(analytics: FreeAnalytics): McpServer {
  const server = new McpServer(FREE_SERVER_INFO, { instructions: FREE_INSTRUCTIONS });
  for (const t of FREE_TOOLS) {
    server.registerTool(
      t.name,
      { title: t.title, description: t.description, inputSchema: t.input, annotations: { title: t.title, ...ANNOTATIONS } },
      async (args: unknown): Promise<CallToolResult> => {
        try {
          // eslint-disable-next-line @typescript-eslint/no-explicit-any -- each tool's own schema already validated args
          const a = args as any;
          const out = t.run(a);
          analytics.freeToolCalled({ tool: t.name, network: t.network(a) });
          return {
            content: [{ type: 'text', text: `${JSON.stringify(out, null, 2)}\n\n${ctaLine(t.name)}` }],
            structuredContent: out as Record<string, unknown>,
          };
        } catch {
          const err = new ToolError('internal_error', 'This free tool could not answer that call.', `Call ${t.name} once more; if it fails again, answer without it.`, { retryable: true });
          return { content: [{ type: 'text', text: JSON.stringify(err.toJSON(t.name), null, 2) }], isError: true };
        }
      },
    );
  }
  // Bad arguments and unknown tools answer in the same {code, message, next_step, details} envelope.
  structureSdkErrors(server, FREE_TOOLS.map((t) => t.name));
  return server;
}

/** Per-IP limit. The key is hashed in memory for one window; nothing is stored. */
export function ipRateLimit(limiter: TokenRateLimiter, perMinute: number) {
  return (req: Request, res: Response, next: NextFunction) => {
    const retry = limiter.hit(`free-ip:${req.ip ?? 'unknown'}`);
    if (retry > 0) {
      res.status(429).setHeader('Retry-After', String(retry)).json({
        jsonrpc: '2.0',
        error: { code: -32000, message: `rate_limited: more than ${perMinute} requests a minute from this address; retry in ${retry}s.` },
        id: null,
      });
      return;
    }
    next();
  };
}

export function mountFree(app: express.Express, opts: { analytics: FreeAnalytics; perMinute: number; limiter?: TokenRateLimiter }) {
  const limiter = opts.limiter ?? new TokenRateLimiter(opts.perMinute);

  const handle = async (req: Request, res: Response) => {
    const server = buildFreeServer(opts.analytics);
    const transport = new StreamableHTTPServerTransport({ sessionIdGenerator: undefined, enableJsonResponse: true });
    res.on('close', () => {
      void transport.close();
      void server.close();
    });
    try {
      await server.connect(transport);
      await transport.handleRequest(req, res, req.body);
    } catch {
      if (!res.headersSent) {
        res.status(500).json({ jsonrpc: '2.0', error: { code: -32603, message: 'Internal server error' }, id: null });
      }
    }
  };

  const notAllowed = (_req: Request, res: Response) => {
    res.status(405).setHeader('Allow', 'POST').json({
      jsonrpc: '2.0',
      error: { code: -32000, message: 'Method not allowed: this server is stateless; use POST.' },
      id: null,
    });
  };

  app.post('/free', ipRateLimit(limiter, opts.perMinute), express.json({ limit: '256kb' }), handle);
  app.get('/free', notAllowed);
  app.delete('/free', notAllowed);
}

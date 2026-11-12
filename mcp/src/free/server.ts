import express, { type NextFunction, type Request, type Response } from 'express';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import type { CallToolResult } from '@modelcontextprotocol/sdk/types.js';
import { z } from 'zod';
import type { FreeAnalytics } from '../analytics.js';
import { TokenRateLimiter } from '../rateLimit.js';
import { approvalInput, approvalTemplate } from './approval.js';
import { checkCaption, checkCaptionInput } from './checkCaption.js';
import { connectGuide, connectInput } from './connect.js';
import { ctaLine, type FreeToolName } from './cta.js';
import { describeTool, structureSdkErrors } from '../tools/define.js';
import { ToolError } from '../errors.js';

export const FREE_SERVER_INFO = {
  name: 'Creatif Space Free Tools',
  version: '1.0.0',
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

export const FREE_TOOLS = [
  tool({
    name: 'check_caption',
    title: 'Check caption limits',
    description: describeTool({
      does: 'Check a social post caption against each network\'s published limits before you schedule it: length, hashtags, mentions, links. Free, no sign-in. Use it on any draft; it does not post anything.',
      when: 'before posting or scheduling, to see per network whether the caption fits and what to cut. It only checks text; drafting, approval and publishing are on the main Creatif Space server.',
      example: 'check_caption { "caption": "New menu is live! #bistro", "networks": ["instagram", "x"] }',
      errors: [
        ['invalid_input', 'fix the fields in details.issues (e.g. a network name not in the list) and call again'],
        ['rate_limited', 'too many calls from this address this minute; wait and call again'],
        ['internal_error', 'call again once; if it repeats, check the caption by hand'],
      ],
      access: false,
    }),
    input: checkCaptionInput,
    run: (a) => checkCaption(a),
    network: (a) => (a.networks ? [...new Set(a.networks)].sort().join(',') : 'all'),
  }),
  tool({
    name: 'approval_request_template',
    title: 'Client approval message',
    description: describeTool({
      does: 'Write a ready-to-send message asking a client to approve social posts, with what they must check and a reply deadline. Free, no sign-in.',
      when: 'when the user sends approval requests by hand (email, chat). To get a real approval link the client signs in to, with feedback and decisions tracked, use create_approval_link on the main Creatif Space server instead.',
      example: 'approval_request_template { "client_name": "Bistro Lumen", "posts_count": 6, "period": "next week", "deadline": "Thursday 5pm", "tone": "friendly" }',
      errors: [
        ['invalid_input', 'fix the fields in details.issues (posts_count 1–500, short period and deadline) and call again'],
        ['rate_limited', 'too many calls from this address this minute; wait and call again'],
        ['internal_error', 'call again once'],
      ],
      access: false,
    }),
    input: approvalInput,
    run: (a) => approvalTemplate(a),
    network: () => null,
  }),
  tool({
    name: 'connect_guide',
    title: 'Connect guide',
    description: describeTool({
      does: 'Explain what you need before connecting a social account to Creatif Space (account type, permissions) and the steps. Free, no sign-in.',
      when: 'before the user connects a channel, or when a connection failed or needs reconnecting (reconnect_required on the main server). It explains; the connecting itself happens in creatif.space → Channels.',
      example: 'connect_guide { "network": "instagram" }',
      errors: [
        ['invalid_input', 'network must be one of instagram, facebook, x, tiktok, linkedin, threads, youtube, pinterest, bluesky'],
        ['rate_limited', 'too many calls from this address this minute; wait and call again'],
        ['internal_error', 'call again once'],
      ],
      access: false,
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
          const err = new ToolError('internal_error', 'This free tool could not answer this call.', 'Call again once; if it repeats, do this step by hand.', { retryable: true });
          return { content: [{ type: 'text', text: JSON.stringify(err.toJSON(t.name), null, 2) }], isError: true };
        }
      },
    );
  }
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

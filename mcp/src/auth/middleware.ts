import type { NextFunction, Request, Response } from 'express';
import type { AuthInfo } from '@modelcontextprotocol/sdk/server/auth/types.js';
import { IntrospectionUnavailable, type Introspector } from './introspection.js';

export interface CallerExtra {
  userUlid: string;
  clientName: string;
  tokenType: 'oauth' | 'api_key';
  /** Which app is calling: ChatGPT gets neutral plan errors (no upgrade wording or links). */
  surface?: 'chatgpt' | 'other';
  [key: string]: unknown;
}

/** ChatGPT's own names for itself as a DCR client. The API also checks redirect hosts. */
const CHATGPT_NAME = /chat\s*gpt|openai/i;

/** 'chatgpt' when the API says so, or the client's registered name is ChatGPT / OpenAI. */
export function clientSurface(info: { client_surface?: string; client_name?: string }): 'chatgpt' | 'other' {
  return info.client_surface === 'chatgpt' || CHATGPT_NAME.test(info.client_name ?? '') ? 'chatgpt' : 'other';
}

export type CallerAuth = AuthInfo & { extra: CallerExtra };

export const metadataPath = '/.well-known/oauth-protected-resource';

/** Every scope a tool can ask for (must match config/mcp.php on the API). */
export const SCOPES = ['read', 'draft', 'approve', 'publish', 'approvals:decide'] as const;

/**
 * Where the metadata lives for a resource URL, always built from its ORIGIN
 * (never resourceUrl + path, which breaks once the resource has a path).
 * `pathForm` is RFC 9728 §3.1's insertion of the resource path
 * (…/oauth-protected-resource/mcp) and is what the 401 challenge
 * advertises; `root` is served too, for clients that only try the root.
 */
export function metadataUrls(resourceUrl: string) {
  const url = new URL(resourceUrl);
  const path = url.pathname.replace(/\/+$/, '');
  return {
    root: `${url.origin}${metadataPath}`,
    rootPath: metadataPath,
    pathForm: `${url.origin}${metadataPath}${path}`,
    pathFormPath: `${metadataPath}${path}`,
  };
}

export function protectedResourceMetadata(resourceUrl: string, authorizationServer: string) {
  return {
    resource: resourceUrl,
    authorization_servers: [authorizationServer],
    scopes_supported: [...SCOPES],
    bearer_methods_supported: ['header'],
    resource_name: 'creatif.space',
    resource_documentation: 'https://creatif.space/docs/mcp',
  };
}

export function challenge(res: Response, resourceUrl: string, error?: string, description?: string) {
  const parts = [`resource_metadata="${metadataUrls(resourceUrl).pathForm}"`];
  if (error) parts.push(`error="${error}"`);
  if (description) parts.push(`error_description="${description}"`);
  res.setHeader('WWW-Authenticate', `Bearer ${parts.join(', ')}`);
  res.status(401).json({
    error: error ?? 'unauthorized',
    error_description: description ?? 'Sign in to creatif.space to use this server.',
  });
}

/** Bearer → introspection → req.auth. Anything short of an active token is a 401 challenge. */
export function bearerAuth(introspector: Introspector, resourceUrl: string) {
  return async (req: Request & { auth?: AuthInfo }, res: Response, next: NextFunction) => {
    const header = req.headers.authorization ?? '';
    const match = /^Bearer\s+(\S+)\s*$/i.exec(header);
    if (!match || !match[1]) {
      challenge(res, resourceUrl);
      return;
    }
    const token = match[1];

    let info;
    try {
      info = await introspector.introspect(token);
    } catch (e) {
      if (e instanceof IntrospectionUnavailable) {
        res.status(503).setHeader('Retry-After', '5').json({
          error: 'temporarily_unavailable',
          error_description: 'Could not verify the token right now; try again shortly.',
        });
        return;
      }
      throw e;
    }

    if (!info.active || !info.user_ulid) {
      challenge(res, resourceUrl, 'invalid_token', 'The token is invalid, expired or revoked.');
      return;
    }

    const auth: CallerAuth = {
      token,
      clientId: info.client_id ?? 'unknown',
      scopes: info.scopes ?? [],
      ...(typeof info.exp === 'number' ? { expiresAt: info.exp } : {}),
      extra: {
        userUlid: info.user_ulid,
        clientName: info.client_name ?? info.client_id ?? 'unknown',
        tokenType: info.token_type ?? 'oauth',
        surface: clientSurface(info),
      },
    };
    req.auth = auth;
    next();
  };
}

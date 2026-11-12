import { analyticsFrom } from './analytics.js';
import { CachedIntrospector, HttpIntrospector, StubIntrospector } from './auth/introspection.js';
import { loadConfig } from './config.js';
import { TokenRateLimiter } from './rateLimit.js';
import { createApp } from './server.js';

const config = loadConfig();

const inner =
  config.authMode === 'stub' ? new StubIntrospector() : new HttpIntrospector(config.v1ApiUrl, config.introspectSecret);

const analytics = analyticsFrom(config.posthogKey, config.posthogHost);

const app = createApp({
  v1ApiUrl: config.v1ApiUrl,
  resourceUrl: config.resourceUrl,
  authorizationServer: config.authorizationServer,
  introspector: new CachedIntrospector(inner, config.introspectionTtlMs),
  analytics,
  freeAnalytics: analytics,
  rateLimiter: new TokenRateLimiter(config.rateLimitPerMinute),
  glamaClaim: config.glamaClaim,
  publicDiscovery: config.publicDiscovery,
});

const server = app.listen(config.port, config.host, () => {
  console.log(`creatif mcp listening on http://${config.host}:${config.port} (auth: ${config.authMode})`);
});

// Long-lived streams: let nginx own the timeouts.
server.keepAliveTimeout = 65_000;
server.headersTimeout = 66_000;

for (const signal of ['SIGTERM', 'SIGINT'] as const) {
  process.on(signal, () => {
    server.close(() => process.exit(0));
    setTimeout(() => process.exit(0), 10_000).unref();
  });
}

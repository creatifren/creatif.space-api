import { defineConfig } from 'vitest/config';

/* The contract smoke (scripts/mcp-contract-smoke.sh): against a running
   MCP server and API, never part of `npm test`. */
export default defineConfig({
  test: {
    include: ['contract/**/*.test.ts'],
    environment: 'node',
    testTimeout: 20_000,
  },
});

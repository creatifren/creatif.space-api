/** Every scope a tool can ask for (must match config/mcp.php on the API). */
export const SCOPES = ['read', 'draft', 'approve', 'publish', 'approvals:decide'] as const;
export type Scope = (typeof SCOPES)[number];

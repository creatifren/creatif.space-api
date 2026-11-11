export type FreeToolName = 'check_caption' | 'approval_request_template' | 'connect_guide';

/** The last line of every non-error free-tool response. */
export const ctaLine = (tool: FreeToolName): string =>
  'Need your client\'s yes on this? Creatif Space sends one approval link and publishes when it\'s approved — ' +
  `https://creatif.space/?utm_source=mcp&utm_medium=free-tools&utm_campaign=free-tools&utm_content=${tool}`;

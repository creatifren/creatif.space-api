import { defineTool, describeTool } from './define.js';

/*
 * get_onboarding: the app's "Get started" checklist (GET /me/onboarding),
 * counted from the workspace's own data — connect a channel, make a post,
 * send one to a client, publish one — each {done, at}. The tool adds the one
 * next step an AI should suggest: the first step not done, with the tool
 * that does it. Read-only; nothing here changes the checklist.
 */

const STEPS = ['connect', 'draft', 'send', 'publish'] as const;
type Step = (typeof STEPS)[number];

interface V1Onboarding {
  connect: { done: boolean; at: string | null };
  draft: { done: boolean; at: string | null };
  send: { done: boolean; at: string | null };
  publish: { done: boolean; at: string | null };
  draft_post_id: string | null;
}

const LABEL: Record<Step, string> = {
  connect: 'Connect a channel',
  draft: 'Make a post',
  send: 'Send a post to a client',
  publish: 'Publish a post',
};

/** The one next step for the first step not done, naming the tool that does it. */
export function nextStep(step: Step | null, draftId: string | null): { step: Step | null; tool: string | null; suggest: string } {
  switch (step) {
    case 'connect':
      return { step, tool: 'get_connect_link', suggest: 'Suggest connecting a first channel: ask which network, then call get_connect_link with it and send the user the link.' };
    case 'draft':
      return { step, tool: 'draft_post', suggest: 'Suggest making a first post: offer to write one and save it with draft_post (a draft only; nothing is sent).' };
    case 'send':
      return draftId
        ? { step, tool: 'create_approval_link', suggest: `Suggest sending the newest draft (post ${draftId}) to a client for approval: ask for the client's email, then create_approval_link with that post.` }
        : { step, tool: 'draft_post', suggest: 'Suggest sending a post to a client for approval: there is no draft right now, so offer to make one with draft_post first.' };
    case 'publish':
      return draftId
        ? { step, tool: 'schedule_post', suggest: `Suggest publishing the newest draft (post ${draftId}): offer schedule_post or publish_post, which show a preview before anything goes out.` }
        : { step, tool: 'draft_post', suggest: 'Suggest publishing a first post: offer to make one with draft_post, then schedule it with schedule_post (preview first).' };
    default:
      return { step: null, tool: null, suggest: 'All set up: nothing to suggest from the checklist. Offer the weekly_results prompt to see how posts are doing.' };
  }
}

export const getOnboarding = defineTool({
  name: 'get_onboarding',
  title: 'Get the setup checklist',
  description: describeTool({
    does: 'Shows the user’s “Get started” checklist in creatif.space — connect a channel, make a post, send one to a client, publish one — which steps are done and when, and the one next step to suggest.',
    when: 'at the start of a conversation with a new user, or when they ask how to get started or what to do next. Suggest only next.suggest, in your own words; the steps finish themselves when the user does them, here or in the app.',
    example: 'get_onboarding {} → { steps: [{ key: "connect", label, done: true, at }, …], done_count: 1, all_done: false, draft_post_id, next: { step: "draft", tool: "draft_post", suggest } }',
    rules: 'Read-only. Counted across the workspace (the owner and its members), from the data itself. at is when the step first happened.',
    errors: [
      ['unauthorized', 'ask the user to reconnect the creatif.space connector'],
      ['scope_missing', 'reconnect with "read" allowed'],
      ['upstream_unavailable', 'wait a minute and call again'],
    ],
  }),
  scope: 'read',
  input: {},
  annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
  run: async (_args, { v1 }) => {
    const { data } = await v1.get<{ data: V1Onboarding }>('/me/onboarding');
    const steps = STEPS.map((key) => ({ key, label: LABEL[key], done: data[key]?.done === true, at: data[key]?.at ?? null }));
    const first = steps.find((s) => !s.done)?.key ?? null;
    return {
      steps,
      done_count: steps.filter((s) => s.done).length,
      all_done: first === null,
      draft_post_id: data.draft_post_id ?? null,
      next: nextStep(first, data.draft_post_id ?? null),
    };
  },
});

export const onboardingTools = [getOnboarding];

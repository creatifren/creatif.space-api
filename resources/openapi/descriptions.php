<?php

/*
 * Longer descriptions for operations whose response shape a client needs
 * to know (OpenApi::document puts each on its operation). Optional: the
 * summary in operations.php is required, this is not.
 */

return [
    'api.v1.me.deletion.show' => 'Session only. Reachable while the account is pending deletion (every other route answers 423). '
        .'data: {status: "active" | "pending_deletion", requested_at: ISO 8601 | null, purge_at: ISO 8601 | null, '
        .'grace_days: 30, days_left: integer | null (whole days until purge_at, rounded up), can_cancel: boolean}.',
    'api.v1.me.deletion.cancel' => 'Session only. Brings a pending account back to active and emails the person. '
        .'Answers the same data as GET /me/deletion. 409 {reason: "not_pending"} when the account is not pending, '
        .'409 {reason: "grace_ended"} once purge_at has passed. What the deletion request stopped (scheduled posts, '
        .'approval links, connected channels, the subscription) stays stopped.',
    'api.v1.social-accounts.index' => 'Each channel also carries its last live check: health_status: "ok" | "failed" | "unchecked" '
        .'(failed whenever the channel cannot be posted to, e.g. disconnected), health_reason: string | null (why, in the '
        .'owner\'s words), last_verified_at: ISO 8601 | null, needs_attention: boolean. Connected channels are checked right '
        .'after they connect and once a day; a failed daily check emails the owner once (publish_failed switch). '
        .'Removed channels are not listed.',
    'api.v1.social-accounts.verify' => 'Check now: one live, read-only check of a connected channel through the publishing '
        .'relay. Nothing is posted. Answers the channel as GET /social-accounts lists it (health_status, health_reason, '
        .'last_verified_at). 429 {reason: "too_soon", retry_after} more than once a minute per channel; 409 {reason: '
        .'"not_connected"} for a channel that is not connected; 503 {reason: "upstream_unavailable"} when the relay '
        .'did not answer (the last result is kept).',
    'api.v1.social-accounts.destroy' => 'Owner only. A connected channel is disconnected (it stays listed as disconnected). '
        .'A disconnected channel is removed: hidden from GET /social-accounts, its posts still to go lose it (a post aimed '
        .'only there goes back to draft), its published history kept. Connecting the same account again brings it back. '
        .'204 either way; removing twice is a no-op.',
];

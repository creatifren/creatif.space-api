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
];

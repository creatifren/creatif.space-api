<?php

namespace App\Models\Contracts;

/**
 * A row that belongs to one workspace (an owner and their active team).
 *
 * Every model a route takes by id implements this, and ScopeToWorkspace
 * answers 404 for one outside the caller's workspace — before the
 * controller runs, so no controller can answer 403 and confirm the id.
 */
interface WorkspaceOwned
{
    /** The user id that places this row in a workspace (its owner, or the member who made it). */
    public function workspaceUserId(): ?int;
}

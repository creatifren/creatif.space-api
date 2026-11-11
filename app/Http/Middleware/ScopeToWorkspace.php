<?php

namespace App\Http\Middleware;

use App\Models\Contracts\WorkspaceOwned;
use App\Support\Workspace;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One rule for every authenticated route that takes a workspace-owned id:
 * outside the caller's workspace the row does not exist — 404, the same
 * answer as an id nobody has, never 403, which would confirm the id.
 *
 * Runs after route-model binding (see the priority list in
 * bootstrap/app.php) and before the controller, so a controller's own
 * checks — owner-only, viewer cannot write (403) — only ever see rows in
 * the caller's workspace. A model joins the rule by implementing
 * WorkspaceOwned; WorkspaceRouteInventoryTest fails for a route whose id
 * does not.
 */
class ScopeToWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $route = $request->route();

        if ($user !== null && $route !== null) {
            foreach ($route->parameters() as $value) {
                if ($value instanceof WorkspaceOwned && $value instanceof Model
                    && ! Workspace::contains($user, $value->workspaceUserId())) {
                    // Exactly what binding throws for an id nobody has, so
                    // the 404 body cannot tell the two apart either.
                    throw (new ModelNotFoundException)->setModel($value::class, [$value->getRouteKey()]);
                }
            }
        }

        return $next($request);
    }
}

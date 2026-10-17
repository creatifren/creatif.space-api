<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Support\AutoHandle;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * Return the authenticated user.
     */
    public function __invoke(Request $request): UserResource
    {
        $user = $request->user();

        // A session from before the onboarding screen was removed may still
        // have no address; sign-in gives one, so does the first /me after it.
        if ($user->onboarded_at === null) {
            AutoHandle::give($user);
        }

        return new UserResource($user->load('handle'));
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicProfileResource;
use App\Models\Handle;

class PublicProfileController extends Controller
{
    /**
     * The public page behind creatif.space/{handle}. No auth: this is what
     * any visitor (or client) sees.
     */
    public function show(string $handle): PublicProfileResource
    {
        $record = Handle::query()
            ->where('name', Handle::normalize($handle))
            ->whereNotNull('user_id')
            ->with(['user.profile'])
            ->firstOrFail();

        abort_if($record->user === null, 404);
        abort_if($record->user->status !== UserStatus::Active, 404);

        return new PublicProfileResource($record);
    }
}

<?php

namespace App\Http\Resources;

use App\Models\DriveAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DriveAccount
 */
class DriveAccountResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'provider' => $this->provider,
            'email' => $this->email,
            'status' => $this->status,
            'connected_at' => $this->created_at,
        ];
    }
}

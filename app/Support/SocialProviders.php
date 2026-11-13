<?php

namespace App\Support;

use App\Contracts\SocialProvider;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\DemoSocialProvider;
use App\Services\OutstandService;
use App\Services\PostForMeService;
use InvalidArgumentException;

/**
 * Which relay to ask. New accounts and posts go through the configured
 * one (`SOCIAL_PROVIDER`); an existing row goes back to the relay that
 * holds it, since its ids mean nothing to the other.
 */
class SocialProviders
{
    public const NAMES = ['outstand', 'postforme'];

    public function current(): SocialProvider
    {
        return $this->named((string) config('services.social.provider'));
    }

    public function named(string $name): SocialProvider
    {
        return match ($name) {
            'outstand' => app(OutstandService::class),
            'postforme' => app(PostForMeService::class),
            // The directory reviewer's seeded channel: never a network call.
            DemoSocialProvider::NAME => app(DemoSocialProvider::class),
            default => throw new InvalidArgumentException("Unknown social provider [{$name}]."),
        };
    }

    public function for(SocialAccount|SocialPost $row): SocialProvider
    {
        return $this->named($row->provider);
    }
}

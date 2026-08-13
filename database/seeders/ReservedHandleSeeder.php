<?php

namespace Database\Seeders;

use App\Models\Handle;
use Illuminate\Database\Seeder;

class ReservedHandleSeeder extends Seeder
{
    /**
     * Names a creator may never claim. Two sources:
     * - every public route of the Next.js frontend (a handle lives at
     *   creatif.space/{name}, so route names must never be handles)
     * - generic/brand/abuse-prone words worth holding back
     *
     * Idempotent: safe to re-run after adding names.
     */
    public function run(): void
    {
        $names = [
            // Frontend routes (keep in sync with frontend/src/app)
            'landing', 'pricing', 'crefile', 'affiliate', 'login', 'onboarding',
            'home', 'files', 'spaces', 'space-editor', 'insights', 'profile',
            'settings', 'referral', 'foundations', 'components', 'public-profile',
            'space-viewer', 'studio',
            // File Request links live at /r/{slug} — a creator holding the
            // handle "r" would collide with every one of them.
            'r', 'req',

            // Infrastructure & app
            'admin', 'api', 'auth', 'webhooks', 'app', 'dashboard', 'www',
            'mail', 'email', 'static', 'assets', 'cdn', 'storage', 'status',

            // Brand
            'creatif', 'creatifspace', 'creatif-space', 'capy',

            // Generic / abuse-prone
            'about', 'contact', 'help', 'support', 'blog', 'news', 'terms',
            'privacy', 'legal', 'security', 'official', 'team', 'staff',
            'explore', 'search', 'people', 'portfolio', 'account', 'billing',
            'upgrade', 'premium', 'free', 'pro', 'new', 'popular', 'trending',
            'root', 'system', 'moderator', 'mod', 'test', 'demo', 'null',
            'undefined', 'anonymous', 'user', 'users', 'me', 'my', 'you',
        ];

        foreach ($names as $name) {
            Handle::query()->firstOrCreate(
                ['name' => $name],
                ['is_reserved' => true],
            );
        }
    }
}

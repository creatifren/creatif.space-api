<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MyProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyProfileController extends Controller
{
    /**
     * The authenticated creator's own profile (Settings → Account Setting).
     */
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->profile()->firstOrCreate([]);

        // Explicit 200: a lazily-created profile is still a read, not a create.
        return (new MyProfileResource($profile->load('user')))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Update profile content and/or the account's display fields.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'locale' => ['sometimes', 'string', 'in:en,id'],
            'theme' => ['sometimes', 'string', 'in:light,dark,system'],
            'mode' => ['sometimes', 'string', 'in:portfolio,freelance'],
            'headline' => ['sometimes', 'nullable', 'string', 'max:120'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'location' => ['sometimes', 'nullable', 'string', 'max:120'],
            'socials' => ['sometimes', 'array'],
            'socials.email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'socials.instagram' => ['sometimes', 'nullable', 'string', 'max:255'],
            'socials.behance' => ['sometimes', 'nullable', 'string', 'max:255'],
            'socials.linkedin' => ['sometimes', 'nullable', 'string', 'max:255'],
            'socials.dribbble' => ['sometimes', 'nullable', 'string', 'max:255'],
            'socials.whatsapp' => ['sometimes', 'nullable', 'string', 'max:32'],
            'appearance' => ['sometimes', 'array'],

            /* Freelance-only sections. Values persist in portfolio mode
               ("kept for the trip back") — the public page just omits them. */
            'freelance' => ['sometimes', 'array'],
            'freelance.status' => ['sometimes', 'nullable', 'string', 'max:60'],
            'freelance.show_status' => ['sometimes', 'boolean'],
            'freelance.show_rate' => ['sometimes', 'boolean'],
            'freelance.rates' => ['sometimes', 'array', 'max:5'],
            'freelance.rates.*.unit' => ['required_with:freelance.rates', 'string', 'in:hour,day,month,year,project'],
            /*
             * `present`, not `required_with`. A rate row exists for every
             * unit the owner has touched, and its amount is null until they
             * type one — "Per hour, switched on, price still blank" is a
             * normal half-filled form, not an error.
             *
             * `required_with` rejected exactly that: it treats null as
             * absent however `nullable` is spelled after it, so any rate row
             * with an empty amount failed the whole PATCH — and because this
             * screen sends one merged patch, a single blank rate took every
             * other field on the profile down with it. `present` asks only
             * that the key be there, which the client always sends, and
             * leaves `nullable` to accept the empty value.
             */
            'freelance.rates.*.amount' => ['present', 'nullable', 'integer', 'min:0'],
            'freelance.rates.*.on' => ['sometimes', 'boolean'],
            'freelance.contact_whatsapp' => ['sometimes', 'boolean'],
            'freelance.contact_email' => ['sometimes', 'boolean'],
            'freelance.show_reviews' => ['sometimes', 'boolean'],
            'freelance.show_review_count' => ['sometimes', 'boolean'],
            'freelance.hire_button' => ['sometimes', 'boolean'],

            // Free plan: max 3 categories (from plans.quotas in Fase 5).
            'categories' => ['sometimes', 'array', 'max:3'],
            'categories.*' => ['string', 'max:40'],
            'cover_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
        ]);

        $user = $request->user();

        $accountFields = array_intersect_key($validated, array_flip(['name', 'locale', 'theme']));
        $profileFields = array_diff_key($validated, $accountFields);

        $user->fill($accountFields);
        $user->save();

        $profile = $user->profile()->firstOrCreate([]);
        $profile->fill($profileFields);
        $profile->save();

        return (new MyProfileResource($profile->refresh()->load('user')))
            ->response()
            ->setStatusCode(200);
    }
}

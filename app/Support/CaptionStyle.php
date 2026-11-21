<?php

namespace App\Support;

use App\Models\ClientLabel;
use App\Models\User;

/**
 * v10.9.2 Preferences › Caption style (Rizal 21 Nov): how "Improve with AI"
 * rewrites captions. One default for the workspace
 * (social_settings.caption_style) and, per client, "Own style for this
 * client" (social_settings.caption_styles[client ulid] — no migration).
 * Shape: {tone, emoji, hashtags, examples: up to 3}. Improve with AI needs
 * the default set first (422 caption_style_required); Add hashtags does not.
 */
class CaptionStyle
{
    public const TONES = ['formal', 'semi_formal', 'casual', 'playful'];

    public const EMOJI = ['off', 'a_little', 'more'];

    public const HASHTAGS = ['none', 'at_the_end'];

    /** @return array<string, list<mixed>> validation rules under $key */
    public static function rules(string $key): array
    {
        return [
            $key => ['sometimes', 'nullable', 'array'],
            "{$key}.tone" => ['required_with:'.$key, 'in:'.implode(',', self::TONES)],
            "{$key}.emoji" => ['required_with:'.$key, 'in:'.implode(',', self::EMOJI)],
            "{$key}.hashtags" => ['required_with:'.$key, 'in:'.implode(',', self::HASHTAGS)],
            "{$key}.examples" => ['sometimes', 'array', 'max:3'],
            "{$key}.examples.*" => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $s
     * @return array{tone: string, emoji: string, hashtags: string, examples: list<string>}|null
     */
    public static function clean(?array $s): ?array
    {
        if ($s === null) {
            return null;
        }
        $examples = array_values(array_filter(array_map(fn ($e) => trim((string) $e), (array) ($s['examples'] ?? [])), fn ($e) => $e !== ''));

        return [
            'tone' => (string) $s['tone'],
            'emoji' => (string) $s['emoji'],
            'hashtags' => (string) $s['hashtags'],
            'examples' => array_slice($examples, 0, 3),
        ];
    }

    /** @return array<string, mixed>|null the workspace default */
    public static function default(User $user): ?array
    {
        $s = Workspace::owner($user)->social_settings['caption_style'] ?? null;

        return is_array($s) ? $s : null;
    }

    /** @return array<string, mixed>|null a client's own style (null = it uses the default) */
    public static function ofClient(ClientLabel $label): ?array
    {
        $owner = User::query()->find($label->user_id);
        $s = $owner === null ? null : (Workspace::owner($owner)->social_settings['caption_styles'][$label->ulid] ?? null);

        return is_array($s) ? $s : null;
    }

    /**
     * Store (or clear, with null) a client's own style.
     *
     * @param  array<string, mixed>|null  $style
     */
    public static function setForClient(ClientLabel $label, ?array $style): void
    {
        $owner = Workspace::owner(User::query()->findOrFail($label->user_id));
        $settings = $owner->social_settings ?? [];
        $all = (array) ($settings['caption_styles'] ?? []);
        if ($style === null) {
            unset($all[$label->ulid]);
        } else {
            $all[$label->ulid] = $style;
        }
        $settings['caption_styles'] = $all;
        $owner->forceFill(['social_settings' => $settings])->save();
    }

    /**
     * The style for a caption on these accounts: the first of their clients
     * with its own style, else the workspace default, else none.
     *
     * @param  list<string>  $accountUlids
     * @return array<string, mixed>|null
     */
    public static function forAccounts(User $user, array $accountUlids): ?array
    {
        $owner = Workspace::owner($user);
        $own = (array) (($owner->fresh() ?? $owner)->social_settings['caption_styles'] ?? []);
        if ($accountUlids !== [] && $own !== []) {
            $labels = ClientLabel::query()->where('user_id', $owner->id)
                ->whereHas('accounts', fn ($q) => $q->whereIn('social_accounts.ulid', $accountUlids))
                ->orderBy('id')->pluck('ulid');
            foreach ($labels as $ulid) {
                if (is_array($own[$ulid] ?? null)) {
                    return $own[$ulid];
                }
            }
        }

        return self::default($user);
    }

    /**
     * One paragraph for the rewrite's system prompt; '' when no style is set.
     *
     * @param  array<string, mixed>|null  $s
     */
    public static function prompt(?array $s): string
    {
        if ($s === null) {
            return '';
        }
        $tone = [
            'formal' => 'formal and polished',
            'semi_formal' => 'semi-formal: warm but professional',
            'casual' => 'casual and relaxed',
            'playful' => 'playful and upbeat',
        ][$s['tone'] ?? ''] ?? 'semi-formal';
        $emoji = [
            'off' => 'Use no emoji.',
            'a_little' => 'Use at most one or two emoji.',
            'more' => 'Use a few emoji where they fit.',
        ][$s['emoji'] ?? ''] ?? '';
        $tags = ($s['hashtags'] ?? '') === 'none'
            ? 'Add no new hashtags (keep the ones already written).'
            : 'Keep hashtags together at the end.';
        $examples = array_values(array_filter((array) ($s['examples'] ?? [])));

        return " Write in this brand's caption style: tone {$tone}. {$emoji} {$tags}"
            .($examples === [] ? '' : ' Match the way these captions of the brand are written: '.implode(' | ', array_map(fn ($e) => '"'.mb_substr((string) $e, 0, 1000).'"', $examples)).'.');
    }
}

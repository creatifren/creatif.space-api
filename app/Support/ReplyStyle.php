<?php

namespace App\Support;

use App\Models\ClientLabel;
use App\Models\SocialAccount;
use App\Models\User;

/**
 * v10.6 Preferences › Reply style (Rizal 21 Nov): how AI writes DM and
 * comment replies — Suggest replies, Reply all with AI and Auto DM (Written
 * by AI). One default for the workspace (social_settings.reply_style) and,
 * per client, "Own style for this client" (client_labels.reply_style).
 * Shape: {tone, address, emoji, examples: up to 3}.
 */
class ReplyStyle
{
    public const TONES = ['formal', 'semi_formal', 'casual', 'playful'];

    public const ADDRESS = ['by_name', 'friendly', 'polite'];

    public const EMOJI = ['off', 'a_little', 'more'];

    /** @return array<string, list<mixed>> validation rules under $key */
    public static function rules(string $key): array
    {
        return [
            $key => ['sometimes', 'nullable', 'array'],
            "{$key}.tone" => ['required_with:'.$key, 'in:'.implode(',', self::TONES)],
            "{$key}.address" => ['required_with:'.$key, 'in:'.implode(',', self::ADDRESS)],
            "{$key}.emoji" => ['required_with:'.$key, 'in:'.implode(',', self::EMOJI)],
            "{$key}.examples" => ['sometimes', 'array', 'max:3'],
            "{$key}.examples.*" => ['nullable', 'string', 'max:300'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $s
     * @return array{tone: string, address: string, emoji: string, examples: list<string>}|null
     */
    public static function clean(?array $s): ?array
    {
        if ($s === null) {
            return null;
        }
        $examples = array_values(array_filter(array_map(fn ($e) => trim((string) $e), (array) ($s['examples'] ?? [])), fn ($e) => $e !== ''));

        return [
            'tone' => (string) $s['tone'],
            'address' => (string) $s['address'],
            'emoji' => (string) $s['emoji'],
            'examples' => array_slice($examples, 0, 3),
        ];
    }

    /**
     * The style for replies on this account: a client's own (the first of its
     * labels that has one), else the workspace default, else none.
     *
     * @return array<string, mixed>|null
     */
    public static function forAccount(?SocialAccount $account): ?array
    {
        if ($account === null) {
            return null;
        }
        $own = $account->clientLabels()->whereNotNull('reply_style')->orderBy('client_labels.id')->first();
        if ($own instanceof ClientLabel && is_array($own->reply_style)) {
            return $own->reply_style;
        }
        $owner = User::query()->find($account->user_id);
        $default = $owner === null ? null : (Workspace::owner($owner)->social_settings['reply_style'] ?? null);

        return is_array($default) ? $default : null;
    }

    /**
     * One paragraph for the AI's system prompt; '' when no style is set.
     *
     * @param  array<string, mixed>|null  $s
     */
    public static function prompt(?array $s): string
    {
        if ($s === null) {
            return '';
        }
        $tone = [
            'formal' => 'formal and courteous',
            'semi_formal' => 'semi-formal: warm but professional',
            'casual' => 'casual and relaxed',
            'playful' => 'playful and upbeat',
        ][$s['tone'] ?? ''] ?? 'semi-formal';
        $address = [
            'by_name' => 'Address the person by their name or username when it is known.',
            'friendly' => 'Address the person in a friendly way (like "hi there", or "kak" in Indonesian).',
            'polite' => 'Address the person politely (like "sir/madam", or "Bapak/Ibu" in Indonesian).',
        ][$s['address'] ?? ''] ?? '';
        $emoji = [
            'off' => 'Use no emoji.',
            'a_little' => 'Use at most one emoji.',
            'more' => 'Use a few emoji where they fit.',
        ][$s['emoji'] ?? ''] ?? '';
        $examples = array_values(array_filter((array) ($s['examples'] ?? [])));

        return " Write in this brand's reply style: tone {$tone}. {$address} {$emoji}"
            .($examples === [] ? '' : ' Match the way these replies of the brand are written: '.implode(' | ', array_map(fn ($e) => '"'.mb_substr((string) $e, 0, 300).'"', $examples)).'.');
    }
}

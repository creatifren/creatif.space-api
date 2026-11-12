<?php

namespace App\Support;

use App\Enums\SocialPostStatus;
use App\Models\SocialApprovalLink;
use App\Models\SocialPost;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A Content Plan as an iCalendar feed (RFC 5545): one event per scheduled
 * post inside the plan's dates and channels. Titles are the first 60
 * characters of the caption; nothing else of the post is in the feed.
 */
final class PlanCalendar
{
    public const TITLE_CHARS = 60;

    /** Every event is this long; a post is a moment, a calendar wants a span. */
    public const EVENT_MINUTES = 15;

    /** @return Collection<int, SocialPost> */
    public static function posts(SocialApprovalLink $link): Collection
    {
        return PlanScope::posts($link)
            ->where('status', SocialPostStatus::Scheduled)
            ->whereNotNull('scheduled_at')
            ->with('targets.account')
            ->orderBy('scheduled_at')
            ->get();
    }

    public static function ics(SocialApprovalLink $link): string
    {
        $stamp = CarbonImmutable::now('UTC')->format('Ymd\THis\Z');
        $name = $link->title !== null && $link->title !== '' ? $link->title : 'Content Plan';

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//creatif.space//Content Plan//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.self::text($name),
            'X-WR-TIMEZONE:'.self::text(PlanScope::timezone($link)),
        ];

        foreach (self::posts($link) as $post) {
            $start = CarbonImmutable::parse($post->scheduled_at)->utc();
            $channels = $post->targets->map(fn ($t) => $t->account === null ? null
                : trim($t->account->platform->value.' '.($t->account->username !== null ? '@'.$t->account->username : '')))
                ->filter()->unique()->implode(', ');

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.$post->ulid.'@creatif.space';
            $lines[] = 'DTSTAMP:'.$stamp;
            $lines[] = 'DTSTART:'.$start->format('Ymd\THis\Z');
            $lines[] = 'DTEND:'.$start->addMinutes(self::EVENT_MINUTES)->format('Ymd\THis\Z');
            $lines[] = 'SUMMARY:'.self::text(self::title($post->caption));
            if ($channels !== '') {
                $lines[] = 'DESCRIPTION:'.self::text('Channels: '.$channels);
            }
            $lines[] = 'URL:'.$link->url();
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    public static function title(?string $caption): string
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', (string) $caption));

        return $flat === '' ? 'Post' : mb_substr($flat, 0, self::TITLE_CHARS);
    }

    /** TEXT value escaping (RFC 5545 §3.3.11). */
    private static function text(string $value): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], $value);
    }

    /** Lines longer than 75 octets continue on the next line after a space, never splitting a character. */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = [];
        $current = '';
        foreach (mb_str_split($line) as $char) {
            $limit = $out === [] ? 75 : 74;
            if (strlen($current.$char) > $limit) {
                $out[] = $current;
                $current = '';
            }
            $current .= $char;
        }
        $out[] = $current;

        return implode("\r\n ", $out);
    }
}

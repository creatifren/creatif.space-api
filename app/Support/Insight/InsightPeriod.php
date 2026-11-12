<?php

namespace App\Support\Insight;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * The span a report covers, in the app's timezone (Asia/Jakarta): whole
 * days, `from` 00:00 to `to` 23:59:59. Either a calendar month ("2026-10")
 * or a custom range of at most MAX_DAYS days.
 */
final class InsightPeriod
{
    public const MAX_DAYS = 92;

    public const MONTH = 'month';

    public const RANGE = 'range';

    private function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $kind,
    ) {}

    public static function month(string $yearMonth): self
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $yearMonth, config('app.timezone'));

        if ($start === null) {
            throw new \InvalidArgumentException("Not a month: {$yearMonth}");
        }

        return new self($start->startOfMonth(), $start->endOfMonth(), self::MONTH);
    }

    public static function range(string $from, string $to): self
    {
        $tz = config('app.timezone');

        return new self(
            CarbonImmutable::parse($from, $tz)->startOfDay(),
            CarbonImmutable::parse($to, $tz)->endOfDay(),
            self::RANGE,
        );
    }

    /** Rebuild from a stored report (dates + kind). */
    public static function fromDates(CarbonInterface $from, CarbonInterface $to, string $kind): self
    {
        return $kind === self::MONTH
            ? self::month($from->format('Y-m'))
            : self::range($from->toDateString(), $to->toDateString());
    }

    /**
     * A period from already-shaped input ({month} or {from, to}, both
     * Y-m-d / Y-m strings), with the report rules: not in the future, end
     * not before start, at most MAX_DAYS days. Errors name $prefix.month /
     * $prefix.from / $prefix.to.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function fromInput(array $input, string $prefix = 'period.'): self
    {
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();

        if (! empty($input['month'])) {
            $period = self::month((string) $input['month']);
            if ($period->from->greaterThan($today)) {
                throw ValidationException::withMessages([$prefix.'month' => 'That month has not started yet.']);
            }

            return $period;
        }

        $period = self::range((string) $input['from'], (string) $input['to']);

        if ($period->to->lessThan($period->from)) {
            throw ValidationException::withMessages([$prefix.'to' => 'The end date is before the start date.']);
        }
        if ($period->from->greaterThan($today)) {
            throw ValidationException::withMessages([$prefix.'from' => 'That period has not started yet.']);
        }
        if ($period->days() > self::MAX_DAYS) {
            throw ValidationException::withMessages([$prefix.'to' => 'A period covers at most '.self::MAX_DAYS.' days.']);
        }

        return $period;
    }

    public function days(): int
    {
        return (int) $this->from->startOfDay()->diffInDays($this->to->startOfDay()) + 1;
    }

    /**
     * What the comparison runs against: the calendar month before, or the
     * same number of days immediately before a custom range.
     */
    public function previous(): self
    {
        if ($this->kind === self::MONTH) {
            return self::month($this->from->subMonthNoOverflow()->format('Y-m'));
        }

        $to = $this->from->subDay();

        return self::range($to->subDays($this->days() - 1)->toDateString(), $to->toDateString());
    }

    public function contains(?CarbonInterface $at): bool
    {
        if ($at === null) {
            return false;
        }

        $local = $at->copy()->setTimezone(config('app.timezone'));

        return $local->greaterThanOrEqualTo($this->from) && $local->lessThanOrEqualTo($this->to);
    }

    /** {yyyy-mm} in the file name: the month the period starts in. */
    public function fileMonth(): string
    {
        return $this->from->format('Y-m');
    }

    public function label(string $locale): string
    {
        $from = $this->from->locale($locale);
        $to = $this->to->locale($locale);

        if ($this->kind === self::MONTH) {
            return $from->translatedFormat('F Y');
        }

        if ($from->year === $to->year) {
            return $from->translatedFormat('j M').' – '.$to->translatedFormat('j M Y');
        }

        return $from->translatedFormat('j M Y').' – '.$to->translatedFormat('j M Y');
    }

    /**
     * Seven-day buckets from `from`; the last one may be shorter.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function weeks(): array
    {
        $weeks = [];
        $start = $this->from;

        while ($start->lessThanOrEqualTo($this->to)) {
            $end = $start->addDays(6)->endOfDay();
            $weeks[] = [$start, $end->greaterThan($this->to) ? $this->to : $end];
            $start = $start->addDays(7)->startOfDay();
        }

        return $weeks;
    }
}

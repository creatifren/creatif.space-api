<?php

use App\Support\Insight\InsightClients;
use App\Support\Insight\InsightFormat;
use App\Support\Insight\InsightPeriod;
use App\Support\Insight\InsightStrings;
use Carbon\CarbonImmutable;

it('formats numbers as 950 · 24.1k · 1.2M, with a decimal comma in Indonesian', function () {
    expect(InsightFormat::number(950))->toBe('950')
        ->and(InsightFormat::number(1000))->toBe('1k')
        ->and(InsightFormat::number(24_130))->toBe('24.1k')
        ->and(InsightFormat::number(24_130, 'id'))->toBe('24,1k')
        ->and(InsightFormat::number(999_960))->toBe('1M')
        ->and(InsightFormat::number(1_249_000))->toBe('1.2M')
        ->and(InsightFormat::number(0))->toBe('0')
        ->and(InsightFormat::number(null))->toBe('—')
        ->and(InsightFormat::percent(0.0449))->toBe('4.5%')
        ->and(InsightFormat::percent(0.0449, 'id'))->toBe('4,5%')
        ->and(InsightFormat::percent(null))->toBe('—')
        ->and(InsightFormat::signed(120))->toBe('+120')
        ->and(InsightFormat::signed(-3))->toBe('-3')
        ->and(InsightFormat::duration(45 * 60))->toBe('45 min')
        ->and(InsightFormat::duration(5 * 3600 + 720, 'id'))->toBe('5,2 jam')
        ->and(InsightFormat::duration(2 * 86400 + 7200))->toBe('2.1 d');
});

it('compares with the previous period only when both have a number', function () {
    expect(InsightFormat::delta(120, 100))->toBe(['dir' => 'up', 'text' => '▲ 20.0%'])
        ->and(InsightFormat::delta(80, 100, 'id'))->toBe(['dir' => 'down', 'text' => '▼ 20,0%'])
        ->and(InsightFormat::delta(100, 100))->toBe(['dir' => 'flat', 'text' => '0.0%'])
        ->and(InsightFormat::delta(100, null))->toBeNull()
        ->and(InsightFormat::delta(null, 100))->toBeNull()
        ->and(InsightFormat::delta(100, 0))->toBeNull();
});

it('reads a month as the whole calendar month and compares with the month before', function () {
    $p = InsightPeriod::month('2026-03');

    expect($p->from->toDateTimeString())->toBe('2026-03-01 00:00:00')
        ->and($p->to->toDateTimeString())->toBe('2026-03-31 23:59:59')
        ->and($p->from->timezoneName)->toBe(config('app.timezone'))
        ->and($p->days())->toBe(31)
        ->and($p->previous()->from->toDateString())->toBe('2026-02-01')
        ->and($p->previous()->to->toDateString())->toBe('2026-02-28')
        ->and($p->weeks())->toHaveCount(5)
        ->and($p->label('en'))->toBe('March 2026')
        ->and($p->label('id'))->toBe('Maret 2026')
        ->and($p->fileMonth())->toBe('2026-03');
});

it('compares a custom range with the same number of days just before it', function () {
    $p = InsightPeriod::range('2026-09-15', '2026-10-14');

    expect($p->days())->toBe(30)
        ->and($p->previous()->from->toDateString())->toBe('2026-08-16')
        ->and($p->previous()->to->toDateString())->toBe('2026-09-14')
        ->and($p->previous()->days())->toBe(30)
        ->and($p->label('en'))->toBe('15 Sep – 14 Oct 2026')
        ->and($p->fileMonth())->toBe('2026-09');
});

it('places a post by its local (Jakarta) time', function () {
    $p = InsightPeriod::month('2026-10');

    // 31 Oct 20:00 UTC is already 1 Nov in Jakarta.
    expect($p->contains(CarbonImmutable::parse('2026-10-31T16:59:59Z')))->toBeTrue()
        ->and($p->contains(CarbonImmutable::parse('2026-10-31T17:00:00Z')))->toBeFalse();
});

it('names the file {client-slug}-{yyyy-mm}-{format}.pdf', function () {
    expect(InsightClients::fileName('kopi-senja', InsightPeriod::month('2026-10'), 'deck'))
        ->toBe('kopi-senja-2026-10-deck.pdf');
});

it('has every string in both languages', function () {
    expect(array_keys(InsightStrings::STRINGS['id']))->toEqualCanonicalizing(array_keys(InsightStrings::STRINGS['en']));
});

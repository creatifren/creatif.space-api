<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<title>{{ $client }} — {{ $period }}</title>
@include('insight.partials.style')
<style>
@page { margin: 0; }
body { font-size: 10px; }
.page { position: relative; width: 210mm; height: 296.6mm; overflow: hidden; page-break-after: always; }
.page.last { page-break-after: auto; }
.body { position: absolute; left: 16mm; right: 16mm; top: 16mm; }
.head { font-family: 'Lora'; font-size: 24px; line-height: 1.15; }
h2 { font-family: 'Lora'; font-weight: normal; font-size: 14px; margin: 0 0 7px; }
.section { margin-top: 18px; }
.grid td { padding: 6px 6px; border-bottom: 1px solid #ECE9E2; font-size: 9.5px; }
.grid th { padding: 0 6px 4px; text-align: left; font-weight: normal; font-size: 8px; }
.num { text-align: right; }
.pfoot { position: absolute; left: 16mm; bottom: 9mm; font-size: 8px; }
</style>
</head>
<body>
@php($pages = 2 + count($channels) + 1)
@php($p = 0)

{{-- Cover: full orange, rocket on a paper disc --}}
@php($p++)
<div class="page" style="background: #FF9F43;">
    <div class="body" style="top: 40mm;">
        <div class="label" style="font-size: 10px; color: #0F172A;">{{ $strings['report_title'] }}</div>
        <div class="serif" style="font-size: 44px; line-height: 1.08; margin-top: 10px;">{{ $client }}</div>
        <div style="font-size: 16px; margin-top: 10px;">{{ $period }}</div>
    </div>
    @if ($branding || $logo)
        <div style="position: absolute; left: 60mm; top: 150mm; width: 90mm; height: 90mm; border-radius: 45mm; background: #FAFAF8;">
            @if ($logo && ! $branding)
                <img src="{{ $logo }}" style="position: absolute; left: 25mm; top: 25mm; width: 40mm; height: 40mm; border-radius: 20mm;" alt="">
            @else
                <img src="{{ $rocket_light }}" style="position: absolute; left: 17.1mm; top: 18.5mm; width: 55.8mm;" alt="">
            @endif
        </div>
    @endif
    <table style="position: absolute; left: 16mm; right: 16mm; bottom: 14mm; width: 178mm;">
        <tr>
            <td style="font-size: 9px;">{{ $generated }}</td>
            <td style="text-align: right;">
                @if ($logo && $branding)<img src="{{ $logo }}" style="width: 34px; height: 34px; border-radius: 17px;" alt="">@endif
            </td>
        </tr>
    </table>
</div>

{{-- Summary --}}
@php($p++)
<div class="page">
    <div class="body">
        <div class="label" style="font-size: 8px;">{{ $period }}</div>
        <div class="head" style="margin-top: 3px;">{{ $strings['summary'] }}</div>

        <table style="width: 100%; margin-top: 14px; table-layout: fixed;">
            <tr>
                @foreach ($kpis as $i => $kpi)
                    <td style="padding-right: {{ $i < 3 ? 7 : 0 }}px; vertical-align: top;">
                        <div class="tile {{ $kpi['tone'] }}" style="padding: 11px; height: 84px;">
                            <div class="label" style="font-size: 7.5px;">{{ $kpi['label'] }}</div>
                            <div class="serif" style="font-size: 26px; margin-top: 5px;">{{ $kpi['value'] }}</div>
                            <div style="font-size: 8.5px; margin-top: 2px;">@include('insight.partials.delta', ['delta' => $kpi['delta']])</div>
                            @if ($kpi['sub'])<div class="muted" style="font-size: 8px; margin-top: 1px;">{{ $kpi['sub'] }}</div>@endif
                        </div>
                    </td>
                @endforeach
            </tr>
        </table>
        <div class="muted" style="font-size: 7.5px; margin-top: 4px;">▲▼ {{ $strings['vs_previous'] }} ({{ $previous }})</div>

        @if ($empty)
            <div class="empty section" style="padding: 50px 0; font-size: 13px;"><span class="serif">{{ $strings['no_posts'] }}</span></div>
        @else
            <div class="section">
                <h2>{{ $strings['weekly_reach'] }}</h2>
                @if ($weekly)
                    @include('insight.partials.bars', ['bars' => $weekly, 'height' => 64, 'gap' => 6, 'valueSize' => 10, 'labelSize' => 7.5])
                @else
                    <div class="empty" style="padding: 16px 0;">{{ $strings['no_reach_chart'] }}</div>
                @endif
            </div>
        @endif

        <div class="section">
            <h2>{{ $strings['workflow'] }}</h2>
            <table style="width: 100%; table-layout: fixed;">
                <tr>
                    @foreach ($workflow as $i => $item)
                        <td style="padding-right: {{ $i < 2 ? 7 : 0 }}px;">
                            <div class="tile {{ $i === 0 ? 'lavender' : '' }}" style="padding: 10px 11px;">
                                <div class="label" style="font-size: 7.5px;">{{ $item['label'] }}</div>
                                <div class="serif" style="font-size: 20px; margin-top: 4px;">{{ $item['value'] }}
                                    <span style="font-family: 'Inter'; font-size: 8.5px;">@include('insight.partials.delta', ['delta' => $item['delta']])</span></div>
                                @if (! empty($item['sub']))<div class="muted" style="font-size: 8px;">{{ $item['sub'] }}</div>@endif
                            </div>
                        </td>
                    @endforeach
                </tr>
            </table>
        </div>

        @unless ($empty)
            <div class="section">
                <h2>{{ $strings['channels'] }}</h2>
                <table class="grid" style="width: 100%;">
                    <tr class="label">
                        <th>{{ $strings['channel'] }}</th>
                        <th class="num">{{ $strings['posts_published'] }}</th>
                        <th class="num">{{ $strings['reach'] }}</th>
                        <th class="num">{{ $strings['engagement'] }}</th>
                        <th class="num">{{ $strings['engagement_rate'] }}</th>
                        <th class="num">{{ $strings['followers_change'] }}</th>
                    </tr>
                    @foreach ($channels as $row)
                        <tr>
                            <td>{{ $row['network'] }} <span class="muted">{{ $row['username'] }}</span></td>
                            <td class="num">{{ $row['posts'] }}</td>
                            <td class="num">{{ $row['reach'] }}</td>
                            <td class="num">{{ $row['engagement'] }}</td>
                            <td class="num">{{ $row['rate'] }}</td>
                            <td class="num">{{ $row['followers_delta'] }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>

            @if ($top)
                <div class="section">
                    <h2>{{ $strings['top_posts'] }}</h2>
                    @foreach ($top as $post)
                        <div class="tile" style="padding: 8px; margin-bottom: 6px;">@include('insight.partials.post', ['post' => $post, 'thumb' => 54, 'size' => 9])</div>
                    @endforeach
                </div>
            @endif
        @endunless
    </div>
    @include('insight.partials.details-foot')
</div>

{{-- One page per channel --}}
@foreach ($channels as $channel)
    @php($p++)
    <div class="page">
        <div class="body">
            <div class="label" style="font-size: 8px;">{{ $strings['channel'] }} · {{ $period }}</div>
            <div class="head" style="margin-top: 3px;">{{ $channel['network'] }} <span class="muted" style="font-size: 15px;">{{ $channel['username'] }}</span></div>

            <table class="grid" style="width: 100%; margin-top: 14px;">
                @foreach ([
                    [$strings['posts_published'], $channel['posts'], $channel['deltas']['posts']],
                    [$strings['reach'], $channel['reach'], $channel['deltas']['reach']],
                    [$strings['impressions'], $channel['views'], null],
                    [$strings['engagement'], $channel['engagement'], $channel['deltas']['engagement']],
                    [$strings['engagement_rate'], $channel['rate'], $channel['deltas']['rate']],
                    [$strings['likes'], $channel['likes'], null],
                    [$strings['comments'], $channel['comments'], null],
                    [$strings['shares'], $channel['shares'], null],
                    [$strings['saves'], $channel['saves'], null],
                    [$strings['followers'].' ('.$strings['start'].' → '.$strings['end'].')', null, null],
                    [$strings['followers_change'], $channel['followers_delta'], null],
                ] as [$label, $value, $delta])
                    <tr>
                        <td class="muted">{{ $label }}</td>
                        <td class="num serif" style="font-size: 12px;">@if ($value === null){{ $channel['followers_start'] }} <span style="font-family: 'Inter';">→</span> {{ $channel['followers_end'] }}@else{{ $value }}@endif</td>
                        <td class="num" style="width: 70px;">@include('insight.partials.delta', ['delta' => $delta])</td>
                    </tr>
                @endforeach
            </table>

            @if (! $channel['available'])
                <div class="empty section" style="padding: 40px 0; font-size: 12px;"><span class="serif">{{ str_replace(':network', $channel['network'], $strings['channel_unavailable']) }}</span></div>
            @elseif ($channel['empty'])
                <div class="empty section" style="padding: 40px 0; font-size: 12px;"><span class="serif">{{ $strings['no_posts'] }}</span></div>
            @else
                @if ($channel['weekly'])
                    <div class="section">
                        <h2>{{ $strings['weekly_reach'] }}</h2>
                        @include('insight.partials.bars', ['bars' => $channel['weekly'], 'height' => 56, 'gap' => 6, 'valueSize' => 9.5, 'labelSize' => 7.5])
                    </div>
                @endif
                @if ($channel['top'])
                    <div class="section">
                        <h2>{{ $strings['top_posts'] }}</h2>
                        @foreach ($channel['top'] as $post)
                            <div class="tile" style="padding: 8px; margin-bottom: 6px;">@include('insight.partials.post', ['post' => $post, 'thumb' => 60, 'size' => 9])</div>
                        @endforeach
                    </div>
                @endif
            @endif

            @if ($channel['notes'])
                <div class="muted section" style="font-size: 7.5px; line-height: 1.4;">{{ implode(' · ', $channel['notes']) }}</div>
            @endif
        </div>
        @include('insight.partials.details-foot')
    </div>
@endforeach

{{-- Method notes (+ notes for next month) --}}
@php($p++)
<div class="page last">
    <div class="body">
        @if ($notes)
            <div class="tile lavender" style="padding: 14px 16px; margin-bottom: 20px;">
                <div class="serif" style="font-size: 15px;">{{ $strings['next_month'] }}</div>
                <div style="font-size: 10px; line-height: 1.5; margin-top: 6px; white-space: pre-line;">{{ $notes }}</div>
            </div>
        @endif
        <div class="head">{{ $strings['method'] }}</div>
        <div style="font-size: 9.5px; line-height: 1.55; margin-top: 10px;">
            @foreach (['source_note', 'reach_note', 'engagement_note', 'followers_note', 'approval_note', 'on_time_note', 'comparison_note'] as $key)
                <p style="margin-bottom: 6px;">{{ $strings[$key] }}</p>
            @endforeach
        </div>
        @if ($footnotes)
            <div class="section">
                <h2>{{ $strings['footnotes'] }}</h2>
                @foreach ($footnotes as $note)
                    <p class="muted" style="font-size: 8.5px; margin-bottom: 3px;">{{ $note }}</p>
                @endforeach
            </div>
        @endif
    </div>
    @include('insight.partials.details-foot')
</div>
</body>
</html>

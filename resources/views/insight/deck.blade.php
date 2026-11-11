<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<title>{{ $client }} — {{ $period }}</title>
@include('insight.partials.style')
<style>
@page { margin: 0; }
body { font-size: 16px; }
.slide { position: relative; width: 1280px; height: 719px; overflow: hidden; page-break-after: always; background: #FAFAF8; }
.slide.last { page-break-after: auto; }
.inner { position: absolute; left: 72px; right: 72px; top: 60px; }
.foot { position: absolute; left: 72px; right: 72px; bottom: 34px; }
.title { font-family: 'Lora'; font-size: 40px; line-height: 1.1; }
.kicker { font-size: 13px; }
.notes { font-size: 11px; line-height: 1.45; }
</style>
</head>
<body>
@php($slides = 3 + count($channels) + ($top ? 1 : 0) + ($notes ? 1 : 0))
@php($n = 0)

{{-- 1. Cover: black --}}
@php($n++)
<div class="slide {{ $n === $slides ? 'last' : '' }}" style="background: #121212; color: #FAFAF8;">
    <div class="inner" style="top: 200px;">
        <div class="label kicker" style="color: #9A9A9A;">{{ $strings['report_title'] }}</div>
        <div class="serif" style="font-size: 76px; line-height: 1.05; margin-top: 16px;">{{ $client }}</div>
        <div style="font-size: 26px; margin-top: 18px; color: #9A9A9A;">{{ $period }}</div>
    </div>
    @if ($logo)
        <img src="{{ $logo }}" style="position: absolute; left: 72px; top: 72px; width: 72px; height: 72px; border-radius: 36px;" alt="">
    @endif
    @if ($branding)
        <img src="{{ $rocket_dark }}" style="position: absolute; right: 80px; bottom: 80px; width: 190px;" alt="">
    @endif
</div>

{{-- 2. Summary --}}
@php($n++)
<div class="slide {{ $n === $slides ? 'last' : '' }}">
    <div class="inner">
        <div class="title">{{ $strings['summary'] }}</div>
        <table style="width: 100%; margin-top: 30px; table-layout: fixed;">
            <tr>
                @foreach ($kpis as $i => $kpi)
                    <td style="padding-right: {{ $i < 3 ? 16 : 0 }}px; vertical-align: top;">
                        <div class="tile {{ $kpi['tone'] }}" style="padding: 22px 22px; height: 150px;">
                            <div class="label" style="font-size: 12px;">{{ $kpi['label'] }}</div>
                            <div class="serif" style="font-size: 54px; margin-top: 10px;">{{ $kpi['value'] }}</div>
                            <div style="font-size: 15px; margin-top: 4px;">@include('insight.partials.delta', ['delta' => $kpi['delta']])</div>
                            @if ($kpi['sub'])<div class="muted" style="font-size: 13px; margin-top: 2px;">{{ $kpi['sub'] }}</div>@endif
                        </div>
                    </td>
                @endforeach
            </tr>
        </table>
        <div style="margin-top: 34px;">
            @if ($empty)
                <div class="empty" style="padding: 80px 0; font-size: 24px;"><span class="serif">{{ $strings['no_posts'] }}</span></div>
            @elseif ($weekly)
                <div class="label" style="font-size: 12px; margin-bottom: 10px;">{{ $strings['weekly_reach'] }}</div>
                @include('insight.partials.bars', ['bars' => $weekly, 'height' => 150, 'gap' => 14, 'valueSize' => 16, 'labelSize' => 12])
            @else
                <div class="empty" style="padding: 60px 0; font-size: 18px;">{{ $strings['no_reach_chart'] }}</div>
            @endif
        </div>
    </div>
    @include('insight.partials.deck-foot')
</div>

{{-- 3. Workflow --}}
@php($n++)
<div class="slide {{ $n === $slides ? 'last' : '' }}">
    <div class="inner">
        <div class="title">{{ $strings['workflow'] }}</div>
        <table style="width: 100%; margin-top: 60px; table-layout: fixed;">
            <tr>
                @foreach ($workflow as $i => $item)
                    <td style="padding-right: {{ $i < 2 ? 20 : 0 }}px; vertical-align: top;">
                        <div class="tile {{ $i === 0 ? 'lavender' : '' }}" style="padding: 30px 28px; height: 220px;">
                            <div class="label" style="font-size: 13px;">{{ $item['label'] }}</div>
                            <div class="serif" style="font-size: 84px; margin-top: 18px;">{{ $item['value'] }}</div>
                            <div style="font-size: 16px; margin-top: 6px;">@include('insight.partials.delta', ['delta' => $item['delta']])</div>
                            @if (! empty($item['sub']))<div class="muted" style="font-size: 14px; margin-top: 2px;">{{ $item['sub'] }}</div>@endif
                        </div>
                    </td>
                @endforeach
            </tr>
        </table>
        <div class="muted notes" style="margin-top: 40px;">{{ $strings['approval_note'] }} {{ $strings['on_time_note'] }}</div>
    </div>
    @include('insight.partials.deck-foot')
</div>

{{-- 4. One slide per channel --}}
@foreach ($channels as $channel)
    @php($n++)
    <div class="slide {{ $n === $slides ? 'last' : '' }}">
        <div class="inner">
            <div class="label kicker">{{ $strings['channel'] }}</div>
            <div class="title" style="margin-top: 6px;">{{ $channel['network'] }} <span class="muted" style="font-size: 26px;">{{ $channel['username'] }}</span></div>
            <table style="width: 100%; margin-top: 28px; table-layout: fixed;">
                <tr>
                    @foreach ([
                        [$strings['posts_published'], $channel['posts'], $channel['deltas']['posts']],
                        [$strings['reach'], $channel['reach'], $channel['deltas']['reach']],
                        [$strings['engagement'], $channel['engagement'], $channel['deltas']['engagement']],
                        [$strings['engagement_rate'], $channel['rate'], $channel['deltas']['rate']],
                        [$strings['followers_change'], $channel['followers_delta'], null],
                    ] as $i => [$label, $value, $delta])
                        <td style="padding-right: {{ $i < 4 ? 14 : 0 }}px; vertical-align: top;">
                            <div class="tile {{ $i === 0 ? 'orange' : '' }}" style="padding: 18px; height: 112px;">
                                <div class="label" style="font-size: 11px;">{{ $label }}</div>
                                <div class="serif" style="font-size: 42px; margin-top: 8px;">{{ $value }}</div>
                                <div style="font-size: 13px; margin-top: 2px;">@include('insight.partials.delta', ['delta' => $delta])</div>
                            </div>
                        </td>
                    @endforeach
                </tr>
            </table>
            <div style="margin-top: 26px;">
                @if (! $channel['available'])
                    <div class="empty" style="padding: 70px 0; font-size: 22px;"><span class="serif">{{ str_replace(':network', $channel['network'], $strings['channel_unavailable']) }}</span></div>
                @elseif ($channel['empty'])
                    <div class="empty" style="padding: 70px 0; font-size: 22px;"><span class="serif">{{ $strings['no_posts'] }}</span></div>
                @elseif ($channel['top'])
                    <div class="label" style="font-size: 12px; margin-bottom: 10px;">{{ $strings['top_posts'] }}</div>
                    <table style="width: 100%; table-layout: fixed;">
                        <tr>
                            @foreach ($channel['top'] as $post)
                                <td style="vertical-align: top; padding-right: 14px;">
                                    <div class="tile" style="padding: 14px;">@include('insight.partials.post', ['post' => $post, 'thumb' => 96, 'size' => 13])</div>
                                </td>
                            @endforeach
                            @for ($i = count($channel['top']); $i < 3; $i++)<td></td>@endfor
                        </tr>
                    </table>
                @endif
            </div>
            @if ($channel['notes'])
                <div class="muted notes" style="margin-top: 16px;">{{ implode(' · ', $channel['notes']) }}</div>
            @endif
        </div>
        @include('insight.partials.deck-foot')
    </div>
@endforeach

{{-- 5. Top posts --}}
@if ($top)
    @php($n++)
    <div class="slide {{ $n === $slides ? 'last' : '' }}">
        <div class="inner">
            <div class="title">{{ $strings['top_posts'] }}</div>
            <table style="width: 100%; margin-top: 34px; table-layout: fixed;">
                <tr>
                    @foreach ($top as $post)
                        <td style="vertical-align: top; padding-right: 18px;">
                            <div class="tile" style="padding: 18px;">
                                <div class="thumb" style="width: 330px; height: 250px;">
                                    @if ($post['thumb'])<img src="{{ $post['thumb'] }}" style="width: 330px; height: 250px;" alt="">@endif
                                </div>
                                <div class="muted" style="font-size: 13px; margin-top: 12px;">{{ $post['network'] }} · {{ $post['date'] }}</div>
                                <div style="font-size: 15px; margin-top: 6px; height: 44px; line-height: 1.4;">{{ $post['caption'] }}</div>
                                <table style="margin-top: 8px;">
                                    <tr>
                                        @foreach ($post['numbers'] as [$label, $value])
                                            <td style="padding-right: 22px;">
                                                <div class="serif" style="font-size: 30px;">{{ $value }}</div>
                                                <div class="muted" style="font-size: 12px;">{{ $label }}</div>
                                            </td>
                                        @endforeach
                                    </tr>
                                </table>
                            </div>
                        </td>
                    @endforeach
                    @for ($i = count($top); $i < 3; $i++)<td></td>@endfor
                </tr>
            </table>
        </div>
        @include('insight.partials.deck-foot')
    </div>
@endif

{{-- 6. Notes for next month (optional) --}}
@if ($notes)
    @php($n++)
    <div class="slide {{ $n === $slides ? 'last' : '' }}" style="background: #C4A8FF;">
        <div class="inner">
            <div class="title">{{ $strings['next_month'] }}</div>
            <div style="font-size: 22px; line-height: 1.5; margin-top: 30px; white-space: pre-line;">{{ $notes }}</div>
        </div>
        @include('insight.partials.deck-foot', ['onFill' => true])
    </div>
@endif
</body>
</html>

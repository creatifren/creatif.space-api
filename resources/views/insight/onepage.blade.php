<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<title>{{ $client }} — {{ $period }}</title>
@include('insight.partials.style')
<style>
@page { margin: 0; }
body { font-size: 10px; }
.footer { position: absolute; left: 12mm; right: 12mm; bottom: 12mm; }
h2 { font-family: 'Lora'; font-weight: normal; font-size: 13px; margin: 0 0 6px; }
.section { margin-top: 14px; }
.grid td { padding: 5px 6px; border-bottom: 1px solid #ECE9E2; font-size: 9.5px; }
.grid th { padding: 0 6px 4px; text-align: left; font-weight: normal; font-size: 8px; }
.num { text-align: right; }
</style>
</head>
<body>
{{-- One A4 page, paper to the edge, content inside 12 mm margins. Overflow
     is clipped so the report can never spill onto a second page. --}}
<div style="position: relative; width: 210mm; height: 296.6mm; overflow: hidden; background: #FAFAF8;">
<div style="position: absolute; left: 12mm; right: 12mm; top: 12mm;">
    {{-- Header --}}
    <table style="width: 100%;">
        <tr>
            <td style="vertical-align: bottom;">
                <div class="label" style="font-size: 8px;">{{ $strings['report_title'] }}</div>
                <div class="serif" style="font-size: 26px; line-height: 1.15; margin-top: 3px;">{{ $client }}</div>
                <div class="muted" style="font-size: 11px; margin-top: 2px;">{{ $period }}</div>
            </td>
            @if ($logo)
                <td style="width: 56px; text-align: right; vertical-align: bottom;">
                    <img src="{{ $logo }}" style="width: 52px; height: 52px; border-radius: 26px;" alt="">
                </td>
            @endif
        </tr>
    </table>

    {{-- KPI tiles: orange / white / white / lavender --}}
    <table style="width: 100%; margin-top: 12px; table-layout: fixed;">
        <tr>
            @foreach ($kpis as $i => $kpi)
                <td style="padding: 0 {{ $i < 3 ? 6 : 0 }}px 0 0; vertical-align: top;">
                    <div class="tile {{ $kpi['tone'] }}" style="padding: 10px 11px; height: 82px;">
                        <div class="label" style="font-size: 7.5px;">{{ $kpi['label'] }}</div>
                        <div class="serif" style="font-size: 25px; margin-top: 5px;">{{ $kpi['value'] }}</div>
                        <div style="font-size: 8.5px; margin-top: 2px;">@include('insight.partials.delta', ['delta' => $kpi['delta']])</div>
                        @if ($kpi['sub'])<div class="muted" style="font-size: 8px; margin-top: 1px;">{{ $kpi['sub'] }}</div>@endif
                    </div>
                </td>
            @endforeach
        </tr>
    </table>
    @if ($kpis[0]['delta'] || $kpis[1]['delta'] || $kpis[2]['delta'])
        <div class="muted" style="font-size: 7.5px; margin-top: 4px;">▲▼ {{ $strings['vs_previous'] }} ({{ $previous }})</div>
    @endif

    @if ($empty)
        <div class="empty section" style="padding: 60px 0; font-size: 13px;">
            <span class="serif">{{ $strings['no_posts'] }}</span>
        </div>
    @else
        {{-- Weekly reach --}}
        <div class="section">
            <h2>{{ $strings['weekly_reach'] }}</h2>
            @if ($weekly)
                @include('insight.partials.bars', ['bars' => $weekly, 'height' => 70, 'gap' => 6, 'valueSize' => 10, 'labelSize' => 7.5])
            @else
                <div class="empty" style="padding: 18px 0; font-size: 10px;">{{ $strings['no_reach_chart'] }}</div>
            @endif
        </div>

        {{-- Channels (max 6) --}}
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
                @foreach ($table as $row)
                    <tr>
                        <td>{{ $row['network'] }} <span class="muted">{{ $row['username'] }}</span></td>
                        <td class="num">{{ $row['posts'] }}</td>
                        <td class="num">{{ $row['reach'] }} @include('insight.partials.delta', ['delta' => $row['deltas']['reach']])</td>
                        <td class="num">{{ $row['engagement'] }}</td>
                        <td class="num">{{ $row['rate'] }}</td>
                        <td class="num">{{ $row['followers_delta'] }}</td>
                    </tr>
                @endforeach
            </table>
            @if ($more_channels > 0)
                <div class="muted" style="font-size: 8px; margin-top: 4px;">{{ str_replace(':count', (string) $more_channels, $strings['more_channels']) }}</div>
            @endif
        </div>

        {{-- Top 3 posts --}}
        @if ($top)
            <div class="section">
                <h2>{{ $strings['top_posts'] }}</h2>
                <table style="width: 100%; table-layout: fixed;">
                    <tr>
                        @foreach ($top as $post)
                            <td style="vertical-align: top; padding-right: 8px;">
                                <div class="tile" style="padding: 8px;">
                                    @include('insight.partials.post', ['post' => $post, 'thumb' => 58, 'size' => 8.5])
                                </div>
                            </td>
                        @endforeach
                        @for ($i = count($top); $i < 3; $i++)<td></td>@endfor
                    </tr>
                </table>
            </div>
        @endif
    @endif

    {{-- Every "—" above has its reason here. --}}
    @if ($footnotes)
        <div class="muted section" style="font-size: 7px; line-height: 1.4;">
            @foreach ($footnotes as $i => $note){{ $i > 0 ? ' · ' : '' }}{{ $note }}@endforeach
        </div>
    @endif
</div>

{{-- Footer --}}
<div class="footer">
    <table style="width: 100%;" class="rule">
        <tr>
            <td class="muted" style="font-size: 7.5px; padding-top: 5px;">{{ $client }} · {{ $period }} · {{ $generated }}</td>
            <td style="text-align: right; padding-top: 5px;">@include('insight.partials.branding', ['rocket' => $rocket_light, 'size' => 8])</td>
        </tr>
    </table>
</div>
</div>
</body>
</html>

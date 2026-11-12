{{-- Weekly reach. $bars: list of {label, range, value, pct}; $height in px. --}}
<table style="width: 100%; table-layout: fixed;">
    <tr>
        @foreach ($bars as $bar)
            <td style="vertical-align: bottom; text-align: center; height: {{ $height + 18 }}px; padding: 0 {{ $gap }}px;">
                <div class="serif" style="font-size: {{ $valueSize }}px; margin-bottom: 3px;">{{ $bar['value'] }}</div>
                <div class="bar" style="height: {{ max(2, (int) round($bar['pct'] * $height / 100)) }}px;"></div>
            </td>
        @endforeach
    </tr>
    <tr>
        @foreach ($bars as $bar)
            <td class="muted" style="text-align: center; font-size: {{ $labelSize }}px; padding-top: 4px; border-top: 1px solid #ECE9E2;">{{ $bar['label'] }} · {{ $bar['range'] }}</td>
        @endforeach
    </tr>
</table>

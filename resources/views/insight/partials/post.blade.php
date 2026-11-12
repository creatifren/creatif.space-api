{{-- One top post card. $post, $thumb (px), $size (base font px). --}}
<table style="width: 100%;">
    <tr>
        <td style="width: {{ $thumb }}px; vertical-align: top; padding-right: 10px;">
            <div class="thumb" style="width: {{ $thumb }}px; height: {{ $thumb }}px;">
                @if ($post['thumb'])
                    <img src="{{ $post['thumb'] }}" style="width: {{ $thumb }}px; height: {{ $thumb }}px;" alt="">
                @endif
            </div>
        </td>
        <td style="vertical-align: top;">
            <div class="muted" style="font-size: {{ $size - 1 }}px;">{{ $post['network'] }}@if ($post['username']) {{ '@'.ltrim($post['username'], '@') }}@endif · {{ $post['date'] }}</div>
            <div style="font-size: {{ $size }}px; margin: 4px 0 6px; line-height: 1.35;">{{ $post['caption'] }}</div>
            <table>
                <tr>
                    @foreach ($post['numbers'] as [$label, $value])
                        <td style="padding-right: 14px;">
                            <div class="serif" style="font-size: {{ $size + 5 }}px;">{{ $value }}</div>
                            <div class="muted" style="font-size: {{ $size - 2 }}px;">{{ $label }}</div>
                        </td>
                    @endforeach
                </tr>
            </table>
        </td>
    </tr>
</table>

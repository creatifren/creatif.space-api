<table class="foot" style="width: 1136px;">
    <tr>
        <td class="{{ empty($onFill) ? 'muted' : '' }}" style="font-size: 12px;">{{ $client }} · {{ $period }}</td>
        <td style="text-align: right;">@include('insight.partials.branding', ['rocket' => $rocket_light, 'size' => 12])</td>
    </tr>
</table>

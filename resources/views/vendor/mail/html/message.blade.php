<x-mail::layout>
{{-- Header --}}
<x-slot:header>
@php($slogan = (string) config('app.slogan'))
<tr>
<td class="header">
<a href="{{ config('app.marketing_url') }}" style="display: inline-block;">
{{ config('app.name') }}
</a>
<div class="slogan" style="margin: 8px 0 0; padding: 0; text-align: center; font-family: 'Lora', Georgia, serif; font-size: 16px; font-weight: 600; line-height: 1.5; color: #0F172A;">{{ \Illuminate\Support\Str::before($slogan, 'published') }}<span style="font-family: 'Lora', Georgia, serif; font-weight: 600; background-color: #FF9F43; color: #0F172A; padding: 0 4px; border-radius: 4px;">published</span>{{ \Illuminate\Support\Str::after($slogan, 'published') }}</div>
</td>
</tr>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ config('app.name') }}. {{ __('All rights reserved.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>

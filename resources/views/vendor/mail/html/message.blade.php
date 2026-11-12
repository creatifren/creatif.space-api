@props(['approvalFooter' => false])
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
@if ($approvalFooter)
{{-- Approval emails only (App\Notifications\ApprovalMailFooter): the
     slogan at 30px as live text, then the link to the site. Same two lines
     as the client page's footer (/a). The header above stays as it is. --}}
@php($footerSlogan = (string) config('app.slogan'))
<tr>
<td>
<table class="approval-footer" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 auto; padding: 0; width: 100%; max-width: 570px;">
<tr>
<td align="left" style="padding: 40px 32px 8px;">
<div class="approval-slogan" style="margin: 0; padding: 0; font-family: 'Lora', Georgia, 'Times New Roman', Times, serif; font-size: 30px; font-weight: 600; line-height: 1.25; letter-spacing: -0.02em; color: #0F172A; text-align: left;">{{ \Illuminate\Support\Str::before($footerSlogan, 'approved & ') }}<span style="font-family: 'Lora', Georgia, 'Times New Roman', Times, serif; font-weight: 600; white-space: nowrap;">approved&nbsp;&amp;</span> <span style="font-family: 'Lora', Georgia, 'Times New Roman', Times, serif; font-weight: 600; background-color: #FF9F43; color: #0F172A; padding: 0 0.12em; border-radius: 0.08em; -webkit-box-decoration-break: clone; box-decoration-break: clone;">published</span>{{ \Illuminate\Support\Str::after($footerSlogan, 'published') }}</div>
<p style="margin: 14px 0 0; padding: 0; font-size: 15px; line-height: 1.5; text-align: left;"><a class="approval-link" href="{{ \App\Notifications\ApprovalMailFooter::URL }}" style="color: #0F172A; font-weight: 600; text-decoration: underline;">{{ \App\Notifications\ApprovalMailFooter::LINK }}</a></p>
</td>
</tr>
</table>
</td>
</tr>
@endif
<x-mail::footer>
© {{ date('Y') }} {{ config('app.name') }}. {{ __('All rights reserved.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>

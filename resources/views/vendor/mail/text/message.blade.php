@props(['approvalFooter' => false, 'footerLink' => true])
<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        <x-mail::header :url="config('app.marketing_url')">
            {{ config('app.name') }}
        </x-mail::header>
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer --}}
    <x-slot:footer>
        @if ($approvalFooter)
{{ config('app.slogan') }}
@if ($footerLink)
{{ \App\Notifications\ApprovalMailFooter::LINK }} {{ \App\Notifications\ApprovalMailFooter::URL }}
@endif

        @endif
        <x-mail::footer>
            © {{ date('Y') }} {{ config('app.name') }}. @lang('All rights reserved.')

            {{ config('app.slogan') }}
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>

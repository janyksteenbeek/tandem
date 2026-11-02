@php
$branding = app(\App\Services\Branding::class);
$copyright = '© '.date('Y').' '.$branding->name();

if ($branding->tagline()) {
    $copyright .= ' · '.$branding->tagline();
}
@endphp
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')" :logo="$branding->mailLogo()">
{{ $branding->name() }}
</x-mail::header>
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
{{ $copyright }}

[{{ parse_url(config('app.url'), PHP_URL_HOST) ?: config('app.url') }}]({{ config('app.url') }})
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>

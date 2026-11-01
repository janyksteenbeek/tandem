<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(0.985 0.002 285);
            }

            html.dark {
                background-color: oklch(0.16 0.006 275);
            }
        </style>

        @php($branding = app(\App\Services\Branding::class))
        @if (($brandCss = $branding->css()) !== '')
            <style>{!! $brandCss !!}</style>
        @endif

        <link rel="icon" href="{{ $branding->favicon() }}">
        <link rel="apple-touch-icon" href="{{ $branding->icons()['apple_touch'] }}">
        <link rel="manifest" href="{{ route('pwa.manifest', absolute: false) }}">
        <meta name="theme-color" media="(prefers-color-scheme: light)" content="{{ $branding->themeColor() }}">
        <meta name="theme-color" media="(prefers-color-scheme: dark)" content="{{ $branding->themeColorDark() }}">
        <meta name="application-name" content="{{ $branding->shortName() }}">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ $branding->name() }}">
        <meta property="og:title" content="{{ $branding->name() }}">
        @if ($tagline = config('branding.tagline'))
            <meta property="og:description" content="{{ $tagline }}">
        @endif
        @if ($ogImage = $branding->ogImage())
            <meta property="og:image" content="{{ $ogImage }}">
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:image" content="{{ $ogImage }}">
        @endif
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="{{ $branding->shortName() }}">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ $branding->name() }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="{{ $branding->themeColor() }}">
        <title>Offline · {{ $branding->name() }}</title>
        <link rel="icon" href="{{ $branding->favicon() }}">

        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    document.documentElement.classList.add('dark');
                }
            })();
        </script>

        {{-- Everything inline: this page must render with nothing but itself in the cache. --}}
        <style>
            :root {
                --background: oklch(0.985 0.002 285);
                --foreground: oklch(0.2 0.012 275);
                --muted-foreground: oklch(0.5 0.01 270);
                --card: oklch(1 0 0);
                --border: oklch(0.92 0.004 285);
                --brand: oklch(0.52 0.25 282);
                --brand-foreground: oklch(0.99 0.002 285);
                --brand-soft: oklch(0.955 0.025 290);
                --highlight: oklch(0.82 0.13 80);
            }

            html.dark {
                --background: oklch(0.16 0.006 275);
                --foreground: oklch(0.93 0.004 285);
                --muted-foreground: oklch(0.66 0.008 275);
                --card: oklch(0.19 0.007 275);
                --border: oklch(0.27 0.008 275);
                --brand: oklch(0.67 0.17 289);
                --brand-foreground: oklch(0.14 0.03 285);
                --brand-soft: oklch(0.29 0.07 287);
            }

            {!! $branding->css() !!}

            * { box-sizing: border-box; }

            html, body {
                margin: 0;
                min-height: 100dvh;
                background: var(--background);
                color: var(--foreground);
                font-family: 'Figtree', ui-sans-serif, system-ui, -apple-system, sans-serif;
                -webkit-font-smoothing: antialiased;
            }

            body {
                display: grid;
                place-items: center;
                padding: 1.5rem;
            }

            main {
                width: 100%;
                max-width: 26rem;
                padding: 2rem;
                border: 1px solid var(--border);
                border-radius: 1.25rem;
                background: var(--card);
                box-shadow: 0 1px 2px rgb(0 0 0 / 0.04), 0 12px 32px -16px color-mix(in oklch, var(--brand) 35%, transparent);
            }

            .mark {
                display: inline-flex;
                margin-bottom: 1.25rem;
            }

            h1 {
                margin: 0 0 0.5rem;
                font-family: 'Figtree', ui-sans-serif, system-ui, sans-serif;
                font-weight: 600;
                font-size: 1.75rem;
                line-height: 1.15;
                letter-spacing: -0.01em;
            }

            p {
                margin: 0;
                font-size: 0.9375rem;
                line-height: 1.55;
                color: var(--muted-foreground);
            }

            .status {
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                margin-bottom: 1rem;
                padding: 0.25rem 0.625rem 0.25rem 0.5rem;
                border-radius: 999px;
                background: var(--brand-soft);
                color: var(--foreground);
                font-size: 0.75rem;
                font-weight: 600;
                letter-spacing: 0.02em;
                text-transform: uppercase;
            }

            .status::before {
                content: '';
                width: 0.5rem;
                height: 0.5rem;
                border-radius: 999px;
                background: var(--highlight);
            }

            .actions {
                display: flex;
                gap: 0.75rem;
                margin-top: 1.5rem;
            }

            button, a.button {
                appearance: none;
                border: 1px solid transparent;
                border-radius: 0.75rem;
                padding: 0.625rem 1rem;
                font: inherit;
                font-size: 0.9375rem;
                font-weight: 500;
                cursor: pointer;
                text-decoration: none;
            }

            button {
                background: var(--brand);
                color: var(--brand-foreground);
            }

            a.button {
                border-color: var(--border);
                background: transparent;
                color: var(--foreground);
            }
        </style>
    </head>
    <body>
        <main>
            <span class="mark" aria-hidden="true">
                <img src="{{ $branding->icons()['icon_192'] }}" width="48" height="48" alt="" style="border-radius: 0.75rem;">
            </span>

            <div><span class="status">Offline</span></div>

            <h1>No connection right now.</h1>
            <p>{{ $branding->name() }} needs the network for this page. Mail you opened recently is still available; everything else will catch up once you're back online.</p>

            <div class="actions">
                <button type="button" onclick="window.location.reload()">Try again</button>
                <a class="button" href="/">Go to mail</a>
            </div>
        </main>

        <script>
            window.addEventListener('online', () => window.location.reload());
        </script>
    </body>
</html>

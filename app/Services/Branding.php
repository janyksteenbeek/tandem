<?php

namespace App\Services;

/**
 * Reads config/branding.php and shapes it for the places that render it:
 * the Inertia shared props and the inline CSS in the root Blade view.
 */
class Branding
{
    private const DEFAULT_THEME_COLOR = '#603df3';

    private const DEFAULT_THEME_COLOR_DARK = '#9581f5';

    /**
     * CSS custom properties that may be overridden per colour scheme.
     *
     * @var array<string, string>
     */
    private const COLOR_VARIABLES = [
        'brand' => '--brand',
        'brand_foreground' => '--brand-foreground',
        'brand_soft' => '--brand-soft',
        'brand_soft_foreground' => '--brand-soft-foreground',
        'highlight' => '--highlight',
        'highlight_foreground' => '--highlight-foreground',
    ];

    public function name(): string
    {
        return (string) config('branding.name', config('app.name'));
    }

    /**
     * The one-liner under the name, or null when it is cleared.
     */
    public function tagline(): ?string
    {
        $tagline = config('branding.tagline');

        return is_string($tagline) && $tagline !== '' ? $tagline : null;
    }

    /**
     * Absolute URL of the square PNG at the top of system emails. Mail
     * clients do not render SVG, so this is the PWA icon rather than the mark.
     */
    public function mailLogo(): string
    {
        return url($this->icons()['icon_192']);
    }

    public function favicon(): string
    {
        return (string) config('branding.favicon', '/tandem/icon.svg');
    }

    /**
     * Absolute URL of the social card image, or null when none is set.
     */
    public function ogImage(): ?string
    {
        $image = config('branding.og_image');

        return is_string($image) && $image !== '' ? url($image) : null;
    }

    /**
     * The label under the installed app's icon; home screens truncate past
     * roughly twelve characters, so it is the name unless told otherwise.
     */
    public function shortName(): string
    {
        return (string) (config('branding.pwa.short_name') ?: $this->name());
    }

    /**
     * Colour the OS paints around the installed window. Follows the light
     * brand colour when that is customised so the two never disagree.
     */
    public function themeColor(): string
    {
        return $this->sanitize((string) (
            config('branding.pwa.theme_color')
            ?: config('branding.colors.light.brand')
            ?: self::DEFAULT_THEME_COLOR
        ));
    }

    public function themeColorDark(): string
    {
        return $this->sanitize((string) (
            config('branding.pwa.theme_color_dark')
            ?: config('branding.colors.dark.brand')
            ?: self::DEFAULT_THEME_COLOR_DARK
        ));
    }

    public function backgroundColor(): string
    {
        return $this->sanitize((string) config('branding.pwa.background_color', '#fafafb'));
    }

    /**
     * @return array{icon_192: string, icon_512: string, maskable: string, apple_touch: string}
     */
    public function icons(): array
    {
        return [
            'icon_192' => (string) config('branding.pwa.icons.icon_192', '/tandem/icon-192.png'),
            'icon_512' => (string) config('branding.pwa.icons.icon_512', '/tandem/icon-512.png'),
            'maskable' => (string) config('branding.pwa.icons.maskable', '/tandem/icon-maskable-512.png'),
            'apple_touch' => (string) config('branding.pwa.icons.apple_touch', '/tandem/apple-touch-icon.png'),
        ];
    }

    /**
     * The web app manifest. Scope and start URL are the root so one install
     * covers every organisation; the home route sends the user on from there.
     *
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        $icons = $this->icons();

        return [
            'id' => '/',
            'name' => $this->name(),
            'short_name' => $this->shortName(),
            'description' => config('branding.tagline'),
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'display_override' => ['window-controls-overlay', 'standalone'],
            'orientation' => 'any',
            'theme_color' => $this->themeColor(),
            'background_color' => $this->backgroundColor(),
            'icons' => [
                ['src' => $icons['icon_192'], 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $icons['icon_512'], 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $icons['maskable'], 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'protocol_handlers' => [
                ['protocol' => 'mailto', 'url' => '/compose?mailto=%s'],
            ],
            'shortcuts' => [
                [
                    'name' => 'Mail',
                    'url' => '/',
                    'icons' => [['src' => $icons['icon_192'], 'sizes' => '192x192', 'type' => 'image/png']],
                ],
                [
                    'name' => 'Settings',
                    'url' => '/settings',
                    'icons' => [['src' => $icons['icon_192'], 'sizes' => '192x192', 'type' => 'image/png']],
                ],
            ],
        ];
    }

    /**
     * @return array{
     *     name: string,
     *     tagline: string|null,
     *     logo: string|null,
     *     logo_dark: string|null,
     *     wordmark: string|null,
     *     wordmark_dark: string|null,
     *     auth: array{quote: string|null}
     * }
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name(),
            'tagline' => config('branding.tagline'),
            'logo' => config('branding.logo'),
            'logo_dark' => config('branding.logo_dark'),
            'wordmark' => config('branding.wordmark') ?: null,
            'wordmark_dark' => config('branding.wordmark_dark') ?: null,
            'auth' => [
                'quote' => config('branding.auth.quote'),
            ],
        ];
    }

    /**
     * Inline stylesheet overriding the palette for whichever colours are set.
     * Returns an empty string when every colour is left at its default.
     */
    public function css(): string
    {
        $blocks = [];

        foreach (['light' => ':root', 'dark' => 'html.dark'] as $scheme => $selector) {
            $declarations = [];

            foreach (self::COLOR_VARIABLES as $key => $variable) {
                $value = config("branding.colors.{$scheme}.{$key}");

                if (is_string($value) && $value !== '') {
                    $declarations[] = sprintf('%s: %s;', $variable, $this->sanitize($value));
                }
            }

            if ($declarations !== []) {
                $blocks[] = sprintf('%s { %s }', $selector, implode(' ', $declarations));
            }
        }

        return implode("\n", $blocks);
    }

    /**
     * Keep the value to characters a CSS colour can contain so config cannot
     * break out of the style block.
     */
    private function sanitize(string $value): string
    {
        return (string) preg_replace('/[^a-zA-Z0-9#%().,\/\s-]/', '', $value);
    }
}

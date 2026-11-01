<?php

namespace App\Services;

/**
 * Reads config/branding.php and shapes it for the places that render it:
 * the Inertia shared props and the inline CSS in the root Blade view.
 */
class Branding
{
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

    public function favicon(): string
    {
        return (string) config('branding.favicon', '/favicon.svg');
    }

    /**
     * @return array{
     *     name: string,
     *     tagline: string|null,
     *     logo: string|null,
     *     logo_dark: string|null,
     *     auth: array{quote: string|null, attribution: string|null, perks: list<string>, footnote: string|null}
     * }
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name(),
            'tagline' => config('branding.tagline'),
            'logo' => config('branding.logo'),
            'logo_dark' => config('branding.logo_dark'),
            'auth' => [
                'quote' => config('branding.auth.quote'),
                'attribution' => config('branding.auth.attribution'),
                'perks' => array_values(config('branding.auth.perks', [])),
                'footnote' => config('branding.auth.footnote'),
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

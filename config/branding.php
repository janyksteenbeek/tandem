<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Product name and tagline
    |--------------------------------------------------------------------------
    |
    | The name is used everywhere the UI refers to the product itself: the
    | browser title, the sidebar, dialogs, outbound test mail and the
    | X-Mailer header. The tagline sits under the name in the mail sidebar.
    |
    */

    'name' => env('BRANDING_NAME', env('APP_NAME', 'Tandem')),
    'tagline' => env('BRANDING_TAGLINE', 'calm mail for busy teams'),

    /*
    |--------------------------------------------------------------------------
    | Logo and favicon
    |--------------------------------------------------------------------------
    |
    | Absolute or root-relative URLs. Leave the logo null to use the built-in
    | envelope mark. "logo_dark" is optional and only used in dark mode. The
    | wordmark is the mark with the product name set beside it; clear it when
    | you rename the product and the UI falls back to the mark plus the name
    | as text. The OG image is what chat apps and social cards unfurl.
    |
    */

    'logo' => env('BRANDING_LOGO'),
    'logo_dark' => env('BRANDING_LOGO_DARK'),
    'wordmark' => env('BRANDING_WORDMARK', '/tandem/tandem-mail-wordmark-purple.svg'),
    'wordmark_dark' => env('BRANDING_WORDMARK_DARK', '/tandem/tandem-mail-wordmark-light.svg'),
    'favicon' => env('BRANDING_FAVICON', '/tandem/icon.svg'),
    'og_image' => env('BRANDING_OG_IMAGE', '/tandem/tandem-mail-og.png'),

    /*
    |--------------------------------------------------------------------------
    | Sign-in screens
    |--------------------------------------------------------------------------
    |
    | The one line on the brand panel next to the login and register forms.
    |
    */

    'auth' => [
        'quote' => env('BRANDING_AUTH_QUOTE', 'Inbox zero is a team sport.'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Colours
    |--------------------------------------------------------------------------
    |
    | Any CSS colour value (oklch, hex, rgb...). A null value keeps the
    | default from resources/css/app.css. "brand" drives buttons, links and
    | the mark; "highlight" is the accent for stars, snoozes and badges.
    |
    */

    'colors' => [
        'light' => [
            'brand' => env('BRANDING_COLOR_BRAND'),
            'brand_foreground' => env('BRANDING_COLOR_BRAND_FOREGROUND'),
            'brand_soft' => env('BRANDING_COLOR_BRAND_SOFT'),
            'brand_soft_foreground' => env('BRANDING_COLOR_BRAND_SOFT_FOREGROUND'),
            'highlight' => env('BRANDING_COLOR_HIGHLIGHT'),
            'highlight_foreground' => env('BRANDING_COLOR_HIGHLIGHT_FOREGROUND'),
        ],
        'dark' => [
            'brand' => env('BRANDING_COLOR_BRAND_DARK'),
            'brand_foreground' => env('BRANDING_COLOR_BRAND_FOREGROUND_DARK'),
            'brand_soft' => env('BRANDING_COLOR_BRAND_SOFT_DARK'),
            'brand_soft_foreground' => env('BRANDING_COLOR_BRAND_SOFT_FOREGROUND_DARK'),
            'highlight' => env('BRANDING_COLOR_HIGHLIGHT_DARK'),
            'highlight_foreground' => env('BRANDING_COLOR_HIGHLIGHT_FOREGROUND_DARK'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Installed app
    |--------------------------------------------------------------------------
    |
    | What the browser shows once the app is installed to a home screen or
    | dock: the short name under the icon, the icons themselves and the two
    | colours the OS paints around the window. The theme colour falls back to
    | the light brand colour when that is overridden above. Icons must be
    | PNGs; the maskable one should keep the mark inside the centre 80%.
    |
    */

    'pwa' => [
        'short_name' => env('BRANDING_SHORT_NAME'),
        'theme_color' => env('BRANDING_THEME_COLOR'),
        'theme_color_dark' => env('BRANDING_THEME_COLOR_DARK'),
        'background_color' => env('BRANDING_BACKGROUND_COLOR', '#fafafb'),
        'icons' => [
            'icon_192' => env('BRANDING_ICON_192', '/tandem/icon-192.png'),
            'icon_512' => env('BRANDING_ICON_512', '/tandem/icon-512.png'),
            'maskable' => env('BRANDING_ICON_MASKABLE', '/tandem/icon-maskable-512.png'),
            'apple_touch' => env('BRANDING_ICON_APPLE_TOUCH', '/tandem/apple-touch-icon.png'),
        ],
    ],
];

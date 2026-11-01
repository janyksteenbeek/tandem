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
    | mark, which follows the brand colours below. "logo_dark" is optional
    | and only used when the UI is in dark mode.
    |
    */

    'logo' => env('BRANDING_LOGO'),
    'logo_dark' => env('BRANDING_LOGO_DARK'),
    'favicon' => env('BRANDING_FAVICON', '/favicon.svg'),

    /*
    |--------------------------------------------------------------------------
    | Sign-in screens
    |--------------------------------------------------------------------------
    |
    | Copy shown on the brand panel next to the login and register forms.
    |
    */

    'auth' => [
        'quote' => env('BRANDING_AUTH_QUOTE', 'Inbox zero is a team sport.'),
        'attribution' => env('BRANDING_AUTH_ATTRIBUTION', 'someone on the team, probably on a Friday'),
        'perks' => [
            'Personal mail and team inboxes, side by side',
            'Internal comments nobody outside ever sees',
            'Gmail, IMAP or forwarding. Mail lands in seconds',
        ],
        'footnote' => env('BRANDING_AUTH_FOOTNOTE', 'Warm paper, plum accents, no blue inbox in sight.'),
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
];

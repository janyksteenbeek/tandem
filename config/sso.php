<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Force single sign-on
    |--------------------------------------------------------------------------
    |
    | When enabled, the only way in is through one of the identity providers
    | below. Password login, registration, password resets, password changes
    | and passkey login are switched off and the sign-in screens only show
    | the provider buttons. Make sure at least one provider is enabled and
    | that every existing user can reach it before turning this on.
    |
    */

    'force' => (bool) env('SSO_FORCE', false),

    /*
    |--------------------------------------------------------------------------
    | Link existing accounts by email
    |--------------------------------------------------------------------------
    |
    | On the first SSO sign-in the identity is linked to the user whose email
    | matches, provided the provider reports the address as verified. Turn
    | this off to only allow linking from the security settings while signed
    | in; unknown identities then always create a fresh account.
    |
    */

    'link_existing_accounts' => (bool) env('SSO_LINK_EXISTING_ACCOUNTS', true),

    /*
    |--------------------------------------------------------------------------
    | Identity providers
    |--------------------------------------------------------------------------
    |
    | Each entry is a Socialite driver. "redirect" is the callback registered
    | with the provider. Google falls back to the client used for Gmail
    | connections, so a single OAuth client can serve both; add the SSO
    | callback URL to that client's authorised redirect URIs.
    |
    | "hosted_domains" restricts Google sign-in to the given Workspace
    | domains (comma separated). Leave it empty to accept any Google account.
    |
    */

    'providers' => [
        'google' => [
            'enabled' => (bool) env('SSO_GOOGLE_ENABLED', false),
            'name' => 'Google',
            'client_id' => env('SSO_GOOGLE_CLIENT_ID', env('GOOGLE_CLIENT_ID')),
            'client_secret' => env('SSO_GOOGLE_CLIENT_SECRET', env('GOOGLE_CLIENT_SECRET')),
            'redirect' => '/auth/google/callback',
            'hosted_domains' => env('SSO_GOOGLE_HOSTED_DOMAINS'),
        ],
    ],
];

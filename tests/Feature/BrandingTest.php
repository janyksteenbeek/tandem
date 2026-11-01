<?php

use App\Services\Branding;

test('branding from config is shared with every page', function () {
    config()->set('branding.name', 'Acme Mail');
    config()->set('branding.tagline', 'mail, but calmer');
    config()->set('branding.logo', 'https://cdn.example.com/logo.svg');
    config()->set('branding.auth.perks', ['One thing']);

    $this->get(route('login'))->assertInertia(fn ($page) => $page
        ->where('name', 'Acme Mail')
        ->where('branding.name', 'Acme Mail')
        ->where('branding.tagline', 'mail, but calmer')
        ->where('branding.logo', 'https://cdn.example.com/logo.svg')
        ->where('branding.wordmark', '/tandem/tandem-mail-wordmark-purple.svg')
        ->where('branding.wordmark_dark', '/tandem/tandem-mail-wordmark-light.svg')
        ->where('branding.auth.perks', ['One thing'])
    );
});

test('the root view uses the configured title and favicon', function () {
    config()->set('branding.name', 'Acme Mail');
    config()->set('branding.favicon', '/brand/acme.svg');

    $this->get(route('login'))
        ->assertSee('<title>Acme Mail</title>', false)
        ->assertSee('href="/brand/acme.svg"', false);
});

test('colour overrides become css variables and unset colours are left alone', function () {
    config()->set('branding.colors.light.brand', '#ff0066');
    config()->set('branding.colors.dark.highlight', 'oklch(0.8 0.16 75)');

    $css = app(Branding::class)->css();

    expect($css)
        ->toContain(':root { --brand: #ff0066; }')
        ->toContain('html.dark { --highlight: oklch(0.8 0.16 75); }')
        ->not->toContain('--brand-soft');

    $this->get(route('login'))->assertSee('--brand: #ff0066;', false);
});

test('no inline stylesheet is rendered when every colour is default', function () {
    expect(app(Branding::class)->css())->toBe('');

    $this->get(route('login'))->assertDontSee('--brand:', false);
});

test('colour values cannot break out of the style block', function () {
    config()->set('branding.colors.light.brand', '#fff;}</style><script>alert(1)</script>');

    expect(app(Branding::class)->css())->not->toContain('<');
});

test('a cleared wordmark is shared as null so the UI falls back to the mark and name', function () {
    config()->set('branding.wordmark', '');
    config()->set('branding.wordmark_dark', '');

    $this->get(route('login'))->assertInertia(fn ($page) => $page
        ->where('branding.wordmark', null)
        ->where('branding.wordmark_dark', null)
    );
});

test('the root view carries the social card image and name', function () {
    config()->set('branding.name', 'Acme Mail');
    config()->set('branding.og_image', '/brand/card.png');

    $this->get(route('login'))
        ->assertSee('<meta property="og:site_name" content="Acme Mail">', false)
        ->assertSee('<meta property="og:image" content="'.url('/brand/card.png').'">', false)
        ->assertSee('<meta name="twitter:card" content="summary_large_image">', false);

    config()->set('branding.og_image', null);

    $this->get(route('login'))->assertDontSee('og:image', false);
});

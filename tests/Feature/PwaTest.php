<?php

use Illuminate\Support\Facades\File;

test('the manifest is built from branding', function () {
    config()->set('branding.name', 'Acme Mail');
    config()->set('branding.pwa.short_name', 'Acme');
    config()->set('branding.pwa.theme_color', '#112233');
    config()->set('branding.pwa.icons.icon_512', '/brand/acme-512.png');

    $this->get(route('pwa.manifest'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json')
        ->assertJsonPath('name', 'Acme Mail')
        ->assertJsonPath('short_name', 'Acme')
        ->assertJsonPath('theme_color', '#112233')
        ->assertJsonPath('start_url', '/')
        ->assertJsonPath('display', 'standalone')
        ->assertJsonPath('icons.1.src', '/brand/acme-512.png')
        ->assertJsonPath('icons.2.purpose', 'maskable')
        ->assertJsonPath('protocol_handlers.0.protocol', 'mailto')
        ->assertJsonPath('protocol_handlers.0.url', '/compose?mailto=%s');
});

test('the short name and theme colour fall back to the name and brand colour', function () {
    config()->set('branding.name', 'Acme Mail');
    config()->set('branding.colors.light.brand', '#ff0066');

    $this->get(route('pwa.manifest'))
        ->assertJsonPath('short_name', 'Acme Mail')
        ->assertJsonPath('theme_color', '#ff0066');
});

test('the service worker is served from the root with its configuration spliced in', function () {
    $response = $this->get('/sw.js')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
        ->assertHeader('Service-Worker-Allowed', '/')
        ->assertHeader('Cache-Control', 'must-revalidate, no-cache, private');

    $script = $response->getContent();

    expect($script)
        ->not->toContain('__PWA_CONFIG__')
        ->toContain('"offlineUrl":"/offline"')
        ->toContain('"precache":["/offline","/manifest.webmanifest"')
        ->toMatch('/"version":"[0-9a-f]{12}"/');
});

test('the service worker version changes when the worker source changes', function () {
    $first = $this->get('/sw.js')->getContent();

    $path = resource_path('js/pwa/service-worker.js');
    $original = File::get($path);

    try {
        File::put($path, $original."\n// changed\n");

        $second = $this->get('/sw.js')->getContent();
    } finally {
        File::put($path, $original);
    }

    preg_match('/"version":"([0-9a-f]+)"/', $first, $a);
    preg_match('/"version":"([0-9a-f]+)"/', $second, $b);

    expect($a[1])->not->toBe($b[1]);
});

test('the offline page is self-contained and branded', function () {
    config()->set('branding.name', 'Acme Mail');

    $this->get(route('pwa.offline'))
        ->assertOk()
        ->assertSee('Acme Mail')
        ->assertSee('No connection right now.')
        ->assertDontSee('/build/', false);
});

test('the root view declares the manifest, theme colours and touch icon', function () {
    config()->set('branding.pwa.theme_color', '#112233');
    config()->set('branding.pwa.theme_color_dark', '#445566');
    config()->set('branding.pwa.icons.apple_touch', '/brand/touch.png');

    $this->get(route('login'))
        ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false)
        ->assertSee('media="(prefers-color-scheme: light)" content="#112233"', false)
        ->assertSee('media="(prefers-color-scheme: dark)" content="#445566"', false)
        ->assertSee('<link rel="apple-touch-icon" href="/brand/touch.png">', false);
});

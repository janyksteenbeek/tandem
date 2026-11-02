<?php

use Inertia\Testing\AssertableInertia as Assert;

test('the browser receives the reverb connection from the server', function () {
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'public-key');
    config()->set('broadcasting.connections.reverb.browser', [
        'host' => 'ws.example.com',
        'port' => '443',
        'scheme' => 'https',
    ]);

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
        ->where('reverb', [
            'key' => 'public-key',
            'host' => 'ws.example.com',
            'port' => 443,
            'scheme' => 'https',
        ]),
    );
});

test('an empty public host leaves the hostname to the browser', function () {
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'public-key');
    config()->set('broadcasting.connections.reverb.browser', ['host' => '', 'port' => 8080, 'scheme' => 'http']);

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
        ->where('reverb.host', null)
        ->where('reverb.port', 8080)
        ->where('reverb.scheme', 'http'),
    );
});

test('no reverb connection is shared when broadcasting does not use reverb', function () {
    config()->set('broadcasting.default', 'null');

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('reverb', null));
});

test('the reverb secret is never shared', function () {
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'public-key');
    config()->set('broadcasting.connections.reverb.secret', 'top-secret');

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
        ->where('reverb.key', 'public-key')
        ->missing('reverb.secret'),
    );
});

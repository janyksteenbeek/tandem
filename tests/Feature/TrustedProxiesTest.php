<?php

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/_proxy-probe', fn (Request $request) => [
        'ip' => $request->ip(),
        'scheme' => $request->getScheme(),
    ]);
});

afterEach(function () {
    TrustProxies::flushState();
    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
});

function bootTrustedProxies(?string $value): void
{
    config()->set('tandem.trusted_proxies', $value);

    (new AppServiceProvider(app()))->boot();
}

it('ignores forwarded headers when no proxies are trusted', function () {
    bootTrustedProxies(null);

    $this->get('/_proxy-probe', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.9'])
        ->assertOk()
        ->assertJson(['ip' => '127.0.0.1', 'scheme' => 'http']);
});

it('trusts every proxy with a wildcard', function () {
    bootTrustedProxies('*');

    $this->get('/_proxy-probe', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.9'])
        ->assertOk()
        ->assertJson(['ip' => '203.0.113.9', 'scheme' => 'https']);
});

it('trusts a proxy that is on the list', function () {
    bootTrustedProxies('10.0.0.0/8, 127.0.0.1');

    $this->get('/_proxy-probe', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.9'])
        ->assertOk()
        ->assertJson(['ip' => '203.0.113.9', 'scheme' => 'https']);
});

it('ignores a proxy that is not on the list', function () {
    bootTrustedProxies('10.0.0.0/8');

    $this->get('/_proxy-probe', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.9'])
        ->assertOk()
        ->assertJson(['ip' => '127.0.0.1', 'scheme' => 'http']);
});

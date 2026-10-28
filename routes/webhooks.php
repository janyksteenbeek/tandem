<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inbound webhooks
|--------------------------------------------------------------------------
|
| These routes are registered without the web middleware group (no session,
| no CSRF). Every endpoint authenticates its caller explicitly.
|
*/

Route::prefix('webhooks')->name('webhooks.')->group(function () {
    // --- Gmail Pub/Sub push -------------------------------------------------

    // --- Cloudflare Email Worker ------------------------------------------------
});

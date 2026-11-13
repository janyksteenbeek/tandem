<?php

use App\Http\Controllers\Calls\Webhooks\MediaServerWebhookController;
use App\Http\Controllers\Calls\Webhooks\RecorderWebhookController;
use App\Http\Controllers\Mail\Webhooks\CloudflareInboundController;
use App\Http\Controllers\Mail\Webhooks\GmailPushController;
use App\Http\Middleware\VerifyCloudflareWorkerSignature;
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
    Route::post('gmail/push/{token}', GmailPushController::class)->name('gmail.push');

    // --- Cloudflare Email Worker ------------------------------------------------
    Route::post('cloudflare/inbound', CloudflareInboundController::class)
        ->middleware(VerifyCloudflareWorkerSignature::class)
        ->name('cloudflare.inbound');

    // --- Tandem Calls: media server room events and recorder reports ------------
    // Both controllers verify the caller's signature themselves.
    Route::post('calls/media', MediaServerWebhookController::class)->name('calls.media');
    Route::post('calls/recorder', RecorderWebhookController::class)->name('calls.recorder');
});

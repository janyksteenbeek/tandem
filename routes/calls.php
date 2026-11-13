<?php

use App\Http\Controllers\Calls\CallAdminController;
use App\Http\Controllers\Calls\CallController;
use App\Http\Controllers\Calls\CallLinkController;
use App\Http\Controllers\Calls\CallRecordingController;
use App\Http\Controllers\Calls\CallTranscriptController;
use App\Http\Controllers\Calls\MeetChatController;
use App\Http\Controllers\Calls\MeetController;
use App\Http\Controllers\Calls\MeetFileController;
use App\Http\Controllers\Calls\MeetHostController;
use App\Http\Controllers\Calls\MeetRecordingController;
use App\Http\Controllers\Calls\SharedRecordingController;
use App\Http\Middleware\EnsureCallParticipant;
use App\Http\Middleware\EnsureOrganizationMembership;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tandem Calls
|--------------------------------------------------------------------------
|
| /meet/{code} is the shareable meeting link. It needs no account: guests are
| recognised by their session and can only reach the one call they were let
| into. Everything under /{organization}/calls is the signed-in history.
*/

Route::prefix('meet')->name('meet.')->group(function () {
    // --- Recordings and files opened from a secret or signed link -------------------
    Route::get('recordings/shared/{token}', [SharedRecordingController::class, 'shared'])->name('recordings.shared')->middleware('throttle:60,1');
    Route::get('recordings/watch/{recording}', [SharedRecordingController::class, 'signed'])->name('recordings.signed')->middleware('signed');
    Route::get('recordings/media/{recording}/{file}', [SharedRecordingController::class, 'media'])->name('recordings.media')->middleware('signed')->where('file', '[A-Za-z0-9._-]+');
    Route::get('files/{file}', [MeetFileController::class, 'signed'])->name('files.signed')->middleware('signed');

    // --- The meeting link -----------------------------------------------------------
    Route::scopeBindings()->prefix('{call:code}')->where(['call' => '[a-z]{3}-[a-z]{4}-[a-z]{3}'])->group(function () {
        Route::get('/', [MeetController::class, 'show'])->name('show');
        Route::post('join', [MeetController::class, 'join'])->name('join')->middleware('throttle:calls-join');
        Route::get('status', [MeetController::class, 'status'])->name('status');
        Route::get('files/{file}', [MeetFileController::class, 'show'])->name('files.show');

        Route::middleware(EnsureCallParticipant::class)->group(function () {
            Route::post('credentials', [MeetController::class, 'credentials'])->name('credentials')->middleware('throttle:calls-join');
            Route::post('connected', [MeetController::class, 'connected'])->name('connected');
            Route::post('leave', [MeetController::class, 'leave'])->name('leave');
            Route::get('state', [MeetController::class, 'state'])->name('state');

            Route::get('messages', [MeetChatController::class, 'index'])->name('messages.index');
            Route::post('messages', [MeetChatController::class, 'store'])->name('messages.store')->middleware('throttle:calls-chat');
            Route::post('messages/read', [MeetChatController::class, 'read'])->name('messages.read');
            Route::patch('messages/{message}', [MeetChatController::class, 'update'])->name('messages.update')->middleware('throttle:calls-chat');
            Route::delete('messages/{message}', [MeetChatController::class, 'destroy'])->name('messages.destroy');
            Route::post('messages/{message}/reactions', [MeetChatController::class, 'react'])->name('messages.react')->middleware('throttle:calls-chat');

            Route::post('files', [MeetFileController::class, 'store'])->name('files.store')->middleware('throttle:calls-upload');
            Route::delete('files/{file}', [MeetFileController::class, 'destroy'])->name('files.destroy');

            Route::post('recordings', [MeetRecordingController::class, 'store'])->name('recordings.store');
            Route::post('recordings/{recording}/stop', [MeetRecordingController::class, 'stop'])->name('recordings.stop');
            Route::post('recording-consent', [MeetRecordingController::class, 'consent'])->name('recordings.consent');

            Route::post('invitations', [MeetHostController::class, 'invite'])->name('invitations.store')->middleware('throttle:30,1');
        });

        Route::middleware(EnsureCallParticipant::class.':host')->group(function () {
            Route::post('participants/{participant}/admit', [MeetHostController::class, 'admit'])->name('participants.admit');
            Route::post('participants/{participant}/deny', [MeetHostController::class, 'deny'])->name('participants.deny');
            Route::post('participants/{participant}/remove', [MeetHostController::class, 'remove'])->name('participants.remove');
            Route::post('participants/{participant}/mute', [MeetHostController::class, 'mute'])->name('participants.mute');
            Route::patch('settings', [MeetHostController::class, 'settings'])->name('settings');
            Route::post('end', [MeetHostController::class, 'end'])->name('end');
        });
    });
});

Route::prefix('{current_organization}/calls')
    ->middleware(['auth', 'verified', EnsureOrganizationMembership::class])
    ->name('calls.')
    ->scopeBindings()
    ->group(function () {
        Route::get('/', [CallController::class, 'index'])->name('index');
        Route::post('/', [CallController::class, 'store'])->name('store');
        Route::post('lookup', [CallController::class, 'lookup'])->name('lookup')->middleware('throttle:30,1');
        Route::get('linked', [CallController::class, 'linked'])->name('linked');
        Route::put('settings', [CallAdminController::class, 'settings'])->name('settings');
        Route::get('diagnostics', [CallAdminController::class, 'diagnostics'])->name('diagnostics');

        Route::prefix('{call}')->whereUuid('call')->group(function () {
            Route::get('/', [CallController::class, 'show'])->name('show');
            Route::patch('/', [CallController::class, 'update'])->name('update');
            Route::delete('/', [CallController::class, 'destroy'])->name('destroy');

            Route::get('transcript', [CallTranscriptController::class, 'show'])->name('transcript');
            Route::post('transcript/email', [CallTranscriptController::class, 'email'])->name('transcript.email')->middleware('throttle:20,1');
            Route::post('transcript/deliveries/{transcriptDelivery}/resend', [CallTranscriptController::class, 'resend'])->name('transcript.resend')->middleware('throttle:20,1');

            Route::post('links', [CallLinkController::class, 'store'])->name('links.store');
            Route::delete('links/{link}', [CallLinkController::class, 'destroy'])->name('links.destroy');
            Route::post('links/{link}/comment', [CallLinkController::class, 'comment'])->name('links.comment');

            Route::prefix('recordings/{recording}')->whereUuid('recording')->name('recordings.')->group(function () {
                Route::get('/', [CallRecordingController::class, 'show'])->name('show');
                Route::delete('/', [CallRecordingController::class, 'destroy'])->name('destroy');
                Route::get('media/{file}', [CallRecordingController::class, 'media'])->name('media')->where('file', '[A-Za-z0-9._-]+');
                Route::post('share', [CallRecordingController::class, 'share'])->name('share');
                Route::delete('share', [CallRecordingController::class, 'unshare'])->name('unshare');
                Route::post('exports', [CallRecordingController::class, 'export'])->name('exports.store');
                Route::get('exports/{export}', [CallRecordingController::class, 'exportStatus'])->name('exports.show');
                Route::get('exports/{export}/download', [CallRecordingController::class, 'download'])->name('exports.download');
            });
        });
    });

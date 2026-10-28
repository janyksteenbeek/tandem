<?php

use App\Http\Controllers\Mail\ForwardingAddressController;
use App\Http\Controllers\Mail\GmailOAuthController;
use App\Http\Controllers\Mail\ImapAccountController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mail account connection routes
|--------------------------------------------------------------------------
|
| Authenticated routes for connecting and managing provider accounts.
| Each provider adapter owns one section below; keep additions inside it.
|
*/

Route::middleware(['auth', 'verified'])->group(function () {
    // --- Gmail (OAuth) ---------------------------------------------------
    Route::get('mail/connect/gmail', [GmailOAuthController::class, 'redirect'])->name('mail.gmail.redirect');
    Route::get('mail/connect/gmail/callback', [GmailOAuthController::class, 'callback'])->name('mail.gmail.callback');

    // --- IMAP / SMTP ------------------------------------------------------
    Route::post('mail/accounts/imap/test', [ImapAccountController::class, 'test'])->name('mail.imap.test');
    Route::post('mail/accounts/imap', [ImapAccountController::class, 'store'])->name('mail.imap.store');
    Route::patch('mail/accounts/imap/{account}', [ImapAccountController::class, 'update'])->name('mail.imap.update');
    Route::delete('mail/accounts/{account}', [ImapAccountController::class, 'destroy'])->name('mail.accounts.destroy');

    // --- Forwarding addresses (Cloudflare Email Routing) ------------------
    Route::post('mail/inboxes/{inbox}/forwarding-addresses', [ForwardingAddressController::class, 'store'])->name('mail.forwarding.store');
    Route::delete('mail/forwarding-addresses/{account}', [ForwardingAddressController::class, 'destroy'])->name('mail.forwarding.destroy');
    Route::post('mail/forwarding-addresses/{account}/test', [ForwardingAddressController::class, 'test'])->name('mail.forwarding.test');
});

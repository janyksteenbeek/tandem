<?php

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

    // --- IMAP / SMTP ------------------------------------------------------

    // --- Forwarding addresses (Cloudflare Email Routing) ------------------
});

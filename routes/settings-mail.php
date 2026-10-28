<?php

use App\Http\Controllers\Settings\InboxSettingsController;
use App\Http\Controllers\Settings\MailAccountController;
use App\Http\Controllers\Settings\OrganizationIntegrationController;
use App\Http\Controllers\Settings\SenderIdentityController;
use App\Http\Controllers\Settings\TeamSettingsController;
use App\Http\Middleware\EnsureOrganizationMembership;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/mail-accounts', [MailAccountController::class, 'index'])->name('mail.accounts.index');
    Route::post('settings/mail-accounts/default-inbox', [MailAccountController::class, 'updateDefaultInbox'])->name('mail.accounts.default-inbox');
    Route::get('mail/accounts/{account}/health', [MailAccountController::class, 'health'])->name('mail.accounts.health');
    Route::post('mail/accounts/{account}/sync', [MailAccountController::class, 'sync'])->name('mail.accounts.sync');
    Route::post('mail/accounts/{account}/test', [MailAccountController::class, 'test'])->name('mail.accounts.test');
    Route::post('mail/accounts/{account}/disconnect', [MailAccountController::class, 'disconnect'])->name('mail.accounts.disconnect');
    Route::delete('mail/accounts/{account}/remove', [MailAccountController::class, 'destroy'])->name('mail.accounts.remove');

    Route::post('mail/accounts/{account}/identities', [SenderIdentityController::class, 'store'])->name('mail.identities.store');
    Route::patch('mail/identities/{identity}', [SenderIdentityController::class, 'update'])->name('mail.identities.update');
    Route::delete('mail/identities/{identity}', [SenderIdentityController::class, 'destroy'])->name('mail.identities.destroy');

    Route::middleware(EnsureOrganizationMembership::class)->prefix('settings/organizations/{organization}')->name('settings.')->group(function () {
        Route::get('inboxes', [InboxSettingsController::class, 'index'])->name('inboxes.index');
        Route::post('inboxes', [InboxSettingsController::class, 'store'])->name('inboxes.store');
        Route::patch('inboxes/{inbox}', [InboxSettingsController::class, 'update'])->name('inboxes.update');
        Route::put('inboxes/{inbox}/access', [InboxSettingsController::class, 'access'])->name('inboxes.access');
        Route::delete('inboxes/{inbox}', [InboxSettingsController::class, 'destroy'])->name('inboxes.destroy');

        Route::get('teams', [TeamSettingsController::class, 'index'])->name('teams.index');
        Route::post('teams', [TeamSettingsController::class, 'store'])->name('teams.store');
        Route::patch('teams/{team}', [TeamSettingsController::class, 'update'])->name('teams.update');
        Route::delete('teams/{team}', [TeamSettingsController::class, 'destroy'])->name('teams.destroy');

        Route::get('integrations', [OrganizationIntegrationController::class, 'edit'])->name('integrations.edit');
        Route::patch('integrations', [OrganizationIntegrationController::class, 'update'])->name('integrations.update');
    });
});

<?php

use App\Http\Controllers\Mail\AttachmentController;
use App\Http\Controllers\Mail\CommentController;
use App\Http\Controllers\Mail\ContactController;
use App\Http\Controllers\Mail\ConversationController;
use App\Http\Controllers\Mail\DraftAttachmentController;
use App\Http\Controllers\Mail\DraftController;
use App\Http\Controllers\Mail\InboxViewController;
use App\Http\Controllers\Mail\MailHomeController;
use App\Http\Controllers\Mail\NotificationController;
use App\Http\Controllers\Mail\SavedViewController;
use App\Http\Controllers\Mail\SearchController;
use App\Http\Controllers\Mail\TagController;
use App\Http\Middleware\EnsureOrganizationMembership;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The mail application
|--------------------------------------------------------------------------
*/

Route::prefix('{current_organization}')
    ->middleware(['auth', 'verified', EnsureOrganizationMembership::class])
    ->group(function () {
        Route::get('mail', MailHomeController::class)->name('mail.home');
        Route::get('mail/{inbox}/{view?}/{conversation?}', [InboxViewController::class, 'show'])
            ->where(['inbox' => '[A-Za-z0-9-]+', 'view' => '[a-z]+', 'conversation' => '[0-9a-f-]{36}'])
            ->name('mail.inbox');
    });

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('search', SearchController::class)->name('search');
    Route::get('contacts', [ContactController::class, 'index'])->name('contacts.index');

    Route::prefix('conversations')->name('conversations.')->group(function () {
        Route::post('bulk', [ConversationController::class, 'bulk'])->name('bulk');
        Route::get('{conversation}', [ConversationController::class, 'show'])->name('show');
        Route::post('{conversation}/assign', [ConversationController::class, 'assign'])->name('assign');
        Route::post('{conversation}/status', [ConversationController::class, 'status'])->name('status');
        Route::post('{conversation}/priority', [ConversationController::class, 'priority'])->name('priority');
        Route::post('{conversation}/tags', [ConversationController::class, 'tags'])->name('tags');
        Route::post('{conversation}/star', [ConversationController::class, 'star'])->name('star');
        Route::post('{conversation}/read', [ConversationController::class, 'read'])->name('read');
        Route::post('{conversation}/mailbox', [ConversationController::class, 'mailbox'])->name('mailbox');
        Route::post('{conversation}/follow', [ConversationController::class, 'follow'])->name('follow');
        Route::post('{conversation}/share', [ConversationController::class, 'share'])->name('share');
        Route::delete('{conversation}/share/{inbox}', [ConversationController::class, 'unshare'])->name('unshare');
        Route::delete('{conversation}', [ConversationController::class, 'destroy'])->name('destroy');
        Route::post('{conversation}/comments', [CommentController::class, 'store'])->name('comments.store');
    });

    Route::patch('comments/{comment}', [CommentController::class, 'update'])->name('comments.update');
    Route::delete('comments/{comment}', [CommentController::class, 'destroy'])->name('comments.destroy');

    Route::prefix('drafts')->name('drafts.')->group(function () {
        Route::post('/', [DraftController::class, 'store'])->name('store');
        Route::patch('{draft}', [DraftController::class, 'update'])->name('update');
        Route::post('{draft}/send', [DraftController::class, 'send'])->name('send');
        Route::delete('{draft}', [DraftController::class, 'destroy'])->name('destroy');
        Route::post('{draft}/attachments', [DraftAttachmentController::class, 'store'])->name('attachments.store');
        Route::get('{draft}/attachments/{attachment}', [DraftAttachmentController::class, 'show'])->name('attachments.show');
        Route::delete('{draft}/attachments/{attachment}', [DraftAttachmentController::class, 'destroy'])->name('attachments.destroy');
    });

    Route::get('attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');

    Route::post('tags', [TagController::class, 'store'])->name('tags.store');
    Route::patch('tags/{tag}', [TagController::class, 'update'])->name('tags.update');
    Route::delete('tags/{tag}', [TagController::class, 'destroy'])->name('tags.destroy');

    Route::post('saved-views', [SavedViewController::class, 'store'])->name('saved-views.store');
    Route::delete('saved-views/{savedView}', [SavedViewController::class, 'destroy'])->name('saved-views.destroy');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');
});

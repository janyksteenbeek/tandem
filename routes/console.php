<?php

use App\Models\OrganizationInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    OrganizationInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired organization invitations');

// Gmail
Schedule::command('mail:gmail:renew-watches')->hourly()->description('Renew expiring Gmail push watches');
Schedule::command('mail:gmail:reconcile')->everyFifteenMinutes()->description('Queue incremental syncs for Gmail accounts that missed push notifications');

// Automations
Schedule::command('automations:business-hours')
    ->everyMinute()
    ->withoutOverlapping()
    ->description('Fire business-hours automation triggers');

// IMAP
// Polling is the safety net for every IMAP account; `mail:imap:idle` (see docs/providers/imap.md)
// delivers changes instantly for servers that support IDLE. The command applies poll_minutes itself.
Schedule::command('mail:imap:poll')->everyMinute()->withoutOverlapping()->description('Queue incremental syncs for due IMAP accounts');

// Conversations
Schedule::command('conversations:wake-snoozed')->everyMinute()->withoutOverlapping()->description('Reopen conversations whose snooze elapsed');

// Housekeeping
Schedule::command('tandem:prune')->daily()->description('Prune old sync runs, webhook receipts and automation runs');

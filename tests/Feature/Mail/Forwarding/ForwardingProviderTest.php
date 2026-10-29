<?php

use App\Enums\MailAccountStatus;
use App\Enums\SyncRunType;
use App\Models\MailAccount;
use App\Models\SyncRun;
use App\Services\Mail\Data\Address;
use App\Services\Mail\Data\OutgoingMessage;
use App\Services\Mail\Exceptions\UnsupportedOperationException;
use App\Services\Mail\MailProviderManager;
use App\Services\Mail\Providers\Forwarding\ForwardingProvider;

beforeEach(function () {
    $this->account = MailAccount::factory()->forwarding()->create(['last_synced_at' => null, 'sync_state' => ['received_count' => 0]]);
    $this->provider = app(ForwardingProvider::class);
});

it('is resolved by the provider manager for forwarding accounts', function () {
    expect(app(MailProviderManager::class)->for($this->account))->toBeInstanceOf(ForwardingProvider::class);
});

it('is resolved by the provider manager for Google Group channels as well', function () {
    $group = MailAccount::factory()->googleGroup()->create();

    expect(app(MailProviderManager::class)->for($group))->toBeInstanceOf(ForwardingProvider::class)
        ->and($this->provider->testConnection($group)->message)->toStartWith('Google Group address');
});

it('reports a successful connection and whether mail was ever received', function () {
    $before = $this->provider->testConnection($this->account);

    $this->account->forceFill(['last_synced_at' => now()])->save();
    $this->account->mergeSyncState(['received_count' => 3]);

    $after = $this->provider->testConnection($this->account);

    expect($before->ok)->toBeTrue()
        ->and($before->details['has_received_mail'])->toBeFalse()
        ->and($before->details['address'])->toBe($this->account->email_address)
        ->and($after->details['has_received_mail'])->toBeTrue()
        ->and($after->details['received_count'])->toBe(3)
        ->and($after->message)->toContain('last message received');
});

it('treats syncs as push-only no-ops', function () {
    $run = SyncRun::start($this->account, SyncRunType::Initial);

    $result = $this->provider->initialSync($this->account, $run);

    expect($result->fetched)->toBe(0)
        ->and($result->hasMore)->toBeFalse()
        ->and($run->fresh()->stats)->toBe(['mode' => 'push'])
        ->and($this->provider->discoverFolders($this->account))->toBeEmpty()
        ->and($this->provider->incrementalSync($this->account, $run)->created)->toBe(0);
});

it('refuses to send and lets the fallback mailer handle outbound mail', function () {
    $message = new OutgoingMessage(new Address('a@b.test'), [new Address('c@d.test')], 'Hi', '<p>Hi</p>');

    expect(fn () => $this->provider->send($this->account, $message))->toThrow(UnsupportedOperationException::class);
});

it('marks the account disconnected on disconnect', function () {
    $this->provider->disconnect($this->account);

    expect($this->account->fresh()->status)->toBe(MailAccountStatus::Disconnected);
});

<?php

use App\Enums\InboxRole;
use App\Enums\InboxType;
use App\Enums\MailAccountStatus;
use App\Enums\MailProvider;
use App\Enums\SyncRunType;
use App\Jobs\Mail\SyncMailAccount;
use App\Models\Inbox;
use App\Models\MailAccount;
use App\Models\User;
use App\Services\Mail\Exceptions\AuthenticationRequiredException;
use App\Services\Mail\Providers\Imap\ImapClientFactory;
use App\Services\Mail\Providers\Imap\SmtpTransportFactory;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Mail\FakeImapSession;
use Tests\Support\Mail\FakeSmtpTransport;

function imapPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Acme Support',
        'host' => 'imap.acme.test',
        'port' => 993,
        'encryption' => 'ssl',
        'username' => 'support@acme.test',
        'password' => 'secret',
        'smtp_host' => 'smtp.acme.test',
        'smtp_port' => 587,
        'smtp_encryption' => 'starttls',
    ], $overrides);
}

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->mock(SmtpTransportFactory::class, fn ($mock) => $mock->shouldReceive('makeFromSettings')->andReturn(new FakeSmtpTransport));
});

test('the test endpoint reports a readable failure without persisting anything', function () {
    $this->mock(ImapClientFactory::class, fn ($mock) => $mock->shouldReceive('makeFromSettings')
        ->once()
        ->andThrow(new AuthenticationRequiredException('The IMAP server rejected the username or password.')));

    $this->actingAs($this->user)
        ->postJson(route('mail.imap.test'), imapPayload())
        ->assertStatus(422)
        ->assertJson(['ok' => false, 'message' => 'The IMAP server rejected the username or password.', 'details' => ['stage' => 'imap']]);

    expect(MailAccount::count())->toBe(0)->and(Inbox::count())->toBe(0);
});

test('the test endpoint returns folder details on success', function () {
    $session = (new FakeImapSession)->addFolder('INBOX')->addFolder('Sent', ['\Sent']);
    $this->mock(ImapClientFactory::class, fn ($mock) => $mock->shouldReceive('makeFromSettings')->once()->andReturn($session));

    $this->actingAs($this->user)
        ->postJson(route('mail.imap.test'), imapPayload())
        ->assertOk()
        ->assertJson(['ok' => true, 'details' => ['folders' => 2, 'idle' => true]]);
});

test('the test endpoint validates its input', function () {
    $this->actingAs($this->user)
        ->postJson(route('mail.imap.test'), ['host' => '', 'encryption' => 'rot13'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['host', 'port', 'encryption', 'username', 'password', 'smtp_host']);
});

test('storing creates a personal inbox, the account, a default identity and queues the initial sync', function () {
    $session = (new FakeImapSession)->addFolder('INBOX')->addFolder('Sent', ['\Sent']);
    $this->mock(ImapClientFactory::class, function ($mock) use ($session) {
        $mock->shouldReceive('makeFromSettings')->andReturn($session);
        $mock->shouldReceive('make')->andReturn($session);
    });

    $this->actingAs($this->user)
        ->from('/settings/mail')
        ->post(route('mail.imap.store'), imapPayload())
        ->assertRedirect('/settings/mail')
        ->assertSessionHasNoErrors();

    $account = MailAccount::firstOrFail();
    $inbox = $account->inbox;

    expect($account->provider)->toBe(MailProvider::Imap)
        ->and($account->status)->toBe(MailAccountStatus::Pending)
        ->and($account->email_address)->toBe('support@acme.test')
        ->and($account->display_name)->toBe('Acme Support')
        ->and($account->user_id)->toBe($this->user->id)
        ->and($account->organization_id)->toBeNull()
        ->and($account->credential('password'))->toBe('secret')
        ->and($account->setting('imap.host'))->toBe('imap.acme.test')
        ->and($account->setting('smtp.username'))->toBe('support@acme.test')
        ->and($account->setting('smtp.append_sent'))->toBeTrue()
        ->and($inbox->type)->toBe(InboxType::Personal)
        ->and($inbox->user_id)->toBe($this->user->id)
        ->and($inbox->name)->toBe('support@acme.test')
        ->and($inbox->email_address)->toBe('support@acme.test')
        ->and($account->identities()->where('is_default', true)->value('email'))->toBe('support@acme.test')
        ->and($account->folders()->count())->toBe(2);

    $this->assertDatabaseMissing('mail_accounts', ['credentials' => json_encode(['password' => 'secret'])]);

    Queue::assertPushed(SyncMailAccount::class, fn (SyncMailAccount $job) => $job->account->is($account) && $job->type === SyncRunType::Initial);
});

test('a failed connection test blocks storing with a validation error', function () {
    $this->mock(ImapClientFactory::class, fn ($mock) => $mock->shouldReceive('makeFromSettings')
        ->andThrow(new AuthenticationRequiredException('The IMAP server rejected the username or password.')));

    $this->actingAs($this->user)
        ->from('/settings/mail')
        ->post(route('mail.imap.store'), imapPayload())
        ->assertRedirect('/settings/mail')
        ->assertSessionHasErrors(['host' => 'The IMAP server rejected the username or password.']);

    expect(MailAccount::count())->toBe(0)->and(Inbox::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('an inbox admin can attach an account to a shared inbox', function () {
    $inbox = Inbox::factory()->create();
    $inbox->users()->attach($this->user, ['role' => InboxRole::Admin->value]);

    $session = (new FakeImapSession)->addFolder('INBOX');
    $this->mock(ImapClientFactory::class, function ($mock) use ($session) {
        $mock->shouldReceive('makeFromSettings')->andReturn($session);
        $mock->shouldReceive('make')->andReturn($session);
    });

    $this->actingAs($this->user)
        ->post(route('mail.imap.store'), imapPayload(['inbox' => $inbox->uuid]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $account = MailAccount::firstOrFail();

    expect($account->inbox_id)->toBe($inbox->id)
        ->and($account->organization_id)->toBe($inbox->organization_id)
        ->and(Inbox::count())->toBe(1);
});

test('a non-admin cannot attach an account to a shared inbox', function () {
    $inbox = Inbox::factory()->create();
    $inbox->users()->attach($this->user, ['role' => InboxRole::Agent->value]);

    $this->mock(ImapClientFactory::class, fn ($mock) => $mock->shouldNotReceive('makeFromSettings'));

    $this->actingAs($this->user)
        ->post(route('mail.imap.store'), imapPayload(['inbox' => $inbox->uuid]))
        ->assertForbidden();

    expect(MailAccount::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('the owner can disconnect an account and an empty personal inbox is removed with it', function () {
    $account = MailAccount::factory()->personal($this->user)->create();
    $inbox = $account->inbox;

    $this->actingAs($this->user)
        ->from('/settings/mail')
        ->delete(route('mail.accounts.destroy', $account))
        ->assertRedirect('/settings/mail');

    expect(MailAccount::find($account->id))->toBeNull()
        ->and(MailAccount::withTrashed()->find($account->id)->status)->toBe(MailAccountStatus::Disconnected)
        ->and(Inbox::find($inbox->id))->toBeNull()
        ->and(Inbox::withTrashed()->find($inbox->id))->not->toBeNull();
});

test('a stranger cannot disconnect someone else\'s account', function () {
    $account = MailAccount::factory()->personal(User::factory()->create())->create();

    $this->actingAs($this->user)
        ->delete(route('mail.accounts.destroy', $account))
        ->assertForbidden();

    expect(MailAccount::find($account->id))->not->toBeNull();
});

test('updating re-tests the connection and keeps the stored password when none is given', function () {
    $account = MailAccount::factory()->personal($this->user)->needsReauth()->create();
    $session = (new FakeImapSession)->addFolder('INBOX');

    $this->mock(ImapClientFactory::class, function ($mock) use ($session) {
        $mock->shouldReceive('makeFromSettings')->once()->withArgs(fn (array $settings, string $password) => $settings['host'] === 'imap.new.test' && $password === 'secret')->andReturn($session);
    });

    $this->actingAs($this->user)
        ->patch(route('mail.imap.update', $account), imapPayload(['host' => 'imap.new.test', 'password' => null, 'name' => 'Renamed']))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $account->refresh();

    expect($account->setting('imap.host'))->toBe('imap.new.test')
        ->and($account->display_name)->toBe('Renamed')
        ->and($account->credential('password'))->toBe('secret')
        ->and($account->status)->toBe(MailAccountStatus::Connected);

    Queue::assertPushed(SyncMailAccount::class, fn (SyncMailAccount $job) => $job->type === SyncRunType::Incremental);
});

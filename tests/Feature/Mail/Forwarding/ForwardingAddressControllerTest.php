<?php

use App\Enums\InboxRole;
use App\Enums\MailProvider;
use App\Enums\OrganizationRole;
use App\Models\Inbox;
use App\Models\MailAccount;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    config()->set('tandem.forwarding.domain', 'forward.tandem.test');

    $this->organization = Organization::factory()->create();
    $this->inbox = Inbox::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Support']);

    $this->admin = User::factory()->create();
    $this->organization->members()->attach($this->admin, ['role' => OrganizationRole::Owner->value]);

    $this->agent = User::factory()->create();
    $this->organization->members()->attach($this->agent, ['role' => OrganizationRole::Member->value]);
    $this->inbox->users()->attach($this->agent, ['role' => InboxRole::Agent->value]);
});

describe('store', function () {
    it('redirects guests to login', function () {
        $this->post(route('mail.forwarding.store', $this->inbox))->assertRedirect(route('login'));
    });

    it('forbids inbox agents from creating addresses', function () {
        $this->actingAs($this->agent)
            ->post(route('mail.forwarding.store', $this->inbox), ['label' => 'Nope'])
            ->assertForbidden();

        expect(MailAccount::count())->toBe(0);
    });

    it('forbids users outside the organization', function () {
        $this->actingAs(User::factory()->create())
            ->post(route('mail.forwarding.store', $this->inbox))
            ->assertForbidden();
    });

    it('creates a forwarding address for an inbox admin and redirects back', function () {
        $this->actingAs($this->admin)
            ->from('/mail/settings')
            ->post(route('mail.forwarding.store', $this->inbox), ['label' => 'Google Group'])
            ->assertRedirect('/mail/settings');

        $account = MailAccount::firstOrFail();

        expect($account->provider)->toBe(MailProvider::Forwarding)
            ->and($account->inbox_id)->toBe($this->inbox->id)
            ->and($account->organization_id)->toBe($this->organization->id)
            ->and($account->display_name)->toBe('Google Group')
            ->and($account->email_address)->toEndWith('@forward.tandem.test');
    });

    it('creates a Google Group channel on the same forwarding domain when asked', function () {
        $this->actingAs($this->admin)
            ->from('/mail/settings')
            ->post(route('mail.forwarding.store', $this->inbox), ['channel' => 'google_group'])
            ->assertRedirect('/mail/settings')
            ->assertSessionHasNoErrors();

        $account = MailAccount::firstOrFail();

        expect($account->provider)->toBe(MailProvider::GoogleGroup)
            ->and($account->usesForwardingAddress())->toBeTrue()
            ->and($account->email_address)->toEndWith('@forward.tandem.test');
    });

    it('rejects channels that are not forwarding based', function () {
        $this->actingAs($this->admin)
            ->from('/mail/settings')
            ->post(route('mail.forwarding.store', $this->inbox), ['channel' => 'gmail'])
            ->assertRedirect('/mail/settings')
            ->assertSessionHasErrors('channel');

        expect(MailAccount::count())->toBe(0);
    });

    it('rejects labels that are too long', function () {
        $this->actingAs($this->admin)
            ->from('/mail/settings')
            ->post(route('mail.forwarding.store', $this->inbox), ['label' => str_repeat('a', 81)])
            ->assertRedirect('/mail/settings')
            ->assertSessionHasErrors('label');

        expect(MailAccount::count())->toBe(0);
    });
});

describe('destroy', function () {
    it('forbids inbox agents from removing addresses', function () {
        $account = MailAccount::factory()->forwarding()->create(['inbox_id' => $this->inbox->id]);

        $this->actingAs($this->agent)->delete(route('mail.forwarding.destroy', $account))->assertForbidden();

        expect($account->fresh()->trashed())->toBeFalse();
    });

    it('soft-deletes the address for an inbox admin', function () {
        $account = MailAccount::factory()->forwarding()->create(['inbox_id' => $this->inbox->id]);

        $this->actingAs($this->admin)
            ->from('/mail/settings')
            ->delete(route('mail.forwarding.destroy', $account))
            ->assertRedirect('/mail/settings');

        expect(MailAccount::withTrashed()->find($account->id)->trashed())->toBeTrue();
    });

    it('soft-deletes a Google Group channel the same way', function () {
        $account = MailAccount::factory()->googleGroup()->create(['inbox_id' => $this->inbox->id]);

        $this->actingAs($this->admin)
            ->from('/mail/settings')
            ->delete(route('mail.forwarding.destroy', $account))
            ->assertRedirect('/mail/settings');

        expect(MailAccount::withTrashed()->find($account->id)->trashed())->toBeTrue();
    });

    it('returns 404 for accounts that are not forwarding addresses', function () {
        $account = MailAccount::factory()->create(['inbox_id' => $this->inbox->id]);

        $this->actingAs($this->admin)->delete(route('mail.forwarding.destroy', $account))->assertNotFound();
    });
});

describe('test', function () {
    it('forbids inbox agents from sending a test email', function () {
        $account = MailAccount::factory()->forwarding()->create(['inbox_id' => $this->inbox->id]);

        $this->actingAs($this->agent)->postJson(route('mail.forwarding.test', $account))->assertForbidden();
    });

    it('sends a test email to the forwarding address and records when it was sent', function () {
        config()->set('tandem.outbound.mailer', 'array');
        config()->set('mail.from.address', 'hello@tandem.test');
        $account = MailAccount::factory()->forwarding()->create(['inbox_id' => $this->inbox->id, 'sync_state' => []]);

        $this->actingAs($this->admin)
            ->postJson(route('mail.forwarding.test', $account))
            ->assertOk()
            ->assertJsonPath('status', 'sent')
            ->assertJsonPath('address', $account->email_address);

        $sent = Mail::mailer('array')->getSymfonyTransport()->messages();

        expect($sent)->toHaveCount(1)
            ->and($sent->first()->getOriginalMessage()->getTo()[0]->getAddress())->toBe($account->email_address)
            ->and($sent->first()->getOriginalMessage()->getFrom()[0]->getAddress())->toBe('hello@tandem.test')
            ->and($sent->first()->getOriginalMessage()->getSubject())->toBe('Tandem forwarding test')
            ->and($sent->first()->getOriginalMessage()->getHeaders()->has('X-Tandem-Message'))->toBeFalse()
            ->and($account->fresh()->syncState('last_test_sent_at'))->not->toBeNull();
    });
});

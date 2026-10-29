<?php

use App\Enums\MailAccountStatus;
use App\Enums\MailProvider;
use App\Models\Inbox;
use App\Models\MailAccount;
use App\Models\User;
use App\Services\Mail\Providers\Forwarding\ForwardingAddressGenerator;

beforeEach(function () {
    config()->set('tandem.forwarding.domain', 'forward.tandem.test');

    $this->generator = app(ForwardingAddressGenerator::class);
});

it('creates a connected forwarding account owned by the inbox organization', function () {
    $inbox = Inbox::factory()->create(['name' => 'Support']);
    $creator = User::factory()->create();

    $account = $this->generator->generate($inbox, $creator, '  Google Group ');

    expect($account->provider)->toBe(MailProvider::Forwarding)
        ->and($account->status)->toBe(MailAccountStatus::Connected)
        ->and($account->inbox_id)->toBe($inbox->id)
        ->and($account->organization_id)->toBe($inbox->organization_id)
        ->and($account->user_id)->toBeNull()
        ->and($account->display_name)->toBe('Google Group')
        ->and($account->setting('label'))->toBe('Google Group')
        ->and($account->setting('created_by'))->toBe($creator->id)
        ->and($account->inbound_token)->toHaveLength(48)
        ->and($account->email_address)->toEndWith('@forward.tandem.test')
        ->and($account->email_address)->toMatch('/^[abcdefghijkmnpqrstuvwxyz23456789]{10}@/');
});

it('creates a Google Group channel that shares the forwarding domain and capabilities', function () {
    $inbox = Inbox::factory()->create(['name' => 'Support']);

    $account = $this->generator->generate($inbox, User::factory()->create(), null, MailProvider::GoogleGroup);

    expect($account->provider)->toBe(MailProvider::GoogleGroup)
        ->and($account->usesForwardingAddress())->toBeTrue()
        ->and($account->provider->capabilities())->toBe(MailProvider::Forwarding->capabilities())
        ->and($account->email_address)->toEndWith('@forward.tandem.test');
});

it('refuses to generate an address for a mailbox-backed provider', function () {
    $inbox = Inbox::factory()->create();

    $this->generator->generate($inbox, User::factory()->create(), null, MailProvider::Gmail);
})->throws(InvalidArgumentException::class);

it('falls back to the inbox name when no label is given', function () {
    $inbox = Inbox::factory()->create(['name' => 'Billing']);

    $account = $this->generator->generate($inbox, User::factory()->create());

    expect($account->display_name)->toBe('Billing')
        ->and($account->setting('label'))->toBeNull();
});

it('never produces an address that already exists, even soft-deleted', function () {
    $inbox = Inbox::factory()->create();
    $creator = User::factory()->create();

    $addresses = collect(range(1, 15))->map(fn () => $this->generator->generate($inbox, $creator)->email_address);
    MailAccount::whereIn('email_address', $addresses->take(5))->get()->each->delete();

    $more = collect(range(1, 10))->map(fn () => $this->generator->generate($inbox, $creator)->email_address);

    expect($addresses->merge($more)->unique())->toHaveCount(25);
});

it('generates local parts only from the unambiguous alphabet', function () {
    $local = $this->generator->randomLocalPart(500);

    expect($local)->toHaveLength(500)
        ->and($local)->toMatch('/^[abcdefghijkmnpqrstuvwxyz23456789]+$/')
        ->and($local)->not->toMatch('/[01lo]/');
});

it('normalizes recipients by lowercasing and stripping sub-addresses and brackets', function (string $input, string $expected) {
    expect($this->generator->normalizeRecipient($input))->toBe($expected);
})->with([
    ['X7K2M9QP4A@Forward.Tandem.test', 'x7k2m9qp4a@forward.tandem.test'],
    ['x7k2m9qp4a+invoices@forward.tandem.test', 'x7k2m9qp4a@forward.tandem.test'],
    ['  <x7k2m9qp4a@forward.tandem.test>  ', 'x7k2m9qp4a@forward.tandem.test'],
    ['Support <x7k2m9qp4a+tag@forward.tandem.test>', 'x7k2m9qp4a@forward.tandem.test'],
    ['not-an-address', 'not-an-address'],
]);

it('recognises addresses on the forwarding domain only', function () {
    expect($this->generator->isForwardingAddress('abc@forward.tandem.test'))->toBeTrue()
        ->and($this->generator->isForwardingAddress('ABC+x@FORWARD.TANDEM.TEST'))->toBeTrue()
        ->and($this->generator->isForwardingAddress('abc@tandem.test'))->toBeFalse()
        ->and($this->generator->isForwardingAddress('abc@notforward.tandem.test'))->toBeFalse();
});

<?php

use Illuminate\Notifications\Messages\MailMessage;

beforeEach(function () {
    config([
        'branding.name' => 'Tandem',
        'branding.tagline' => 'calm mail for busy teams',
    ]);
});

test('system mail renders the brand logo, name and footer through the tandem theme', function () {
    $html = (new MailMessage)
        ->line('A line of body text.')
        ->action('Open inbox', 'https://tandem.test/mail')
        ->render()
        ->toHtml();

    expect($html)
        ->toContain('src="'.url('/tandem/icon-192.png').'"')
        ->toContain('href="https://tandem.test/mail"')
        ->toContain('Open inbox')
        ->toContain('Tandem · calm mail for busy teams')
        ->toContain('The Tandem team')
        ->toContain('background-color: #603df3')
        ->not->toContain('laravel.com/img');
});

test('system mail follows the configured brand name and drops a cleared tagline', function () {
    config(['branding.name' => 'Acme Mail', 'branding.tagline' => '']);

    $html = (new MailMessage)->line('Body')->render()->toHtml();

    expect($html)
        ->toContain('<title>Acme Mail</title>')
        ->toContain('The Acme Mail team')
        ->toContain('Acme Mail</p>')
        ->not->toContain('calm mail for busy teams')
        ->not->toContain('·');
});

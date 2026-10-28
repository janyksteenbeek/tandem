<?php

namespace App\Services\Automations;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Mail\Data\Address;
use Illuminate\Support\Str;

/**
 * Blade-free `{{ variable }}` substitution for templates and comments. Every value is HTML
 * escaped and unknown variables render as empty strings, so templates can never inject markup.
 */
class TemplateRenderer
{
    public function __construct(protected BusinessHours $businessHours) {}

    /**
     * @param  array{message?: Message|null}  $context
     */
    public function render(?string $template, Conversation $conversation, array $context = []): string
    {
        if ($template === null || $template === '') {
            return '';
        }

        $variables = $this->variables($conversation, $context);

        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            fn (array $m) => e($variables[Str::lower($m[1])] ?? ''),
            $template,
        );
    }

    /**
     * Resolve every supported variable for a conversation.
     *
     * @param  array{message?: Message|null}  $context
     * @return array<string, string>
     */
    public function variables(Conversation $conversation, array $context = []): array
    {
        $conversation->loadMissing(['inbox', 'organization', 'assignee']);

        $message = $context['message'] ?? null;
        $inbound = $message instanceof Message && $message->isInbound()
            ? $message
            : $conversation->messages()->where('direction', 'inbound')->latest('sent_at')->first();

        $contact = $this->contact($conversation, $inbound);
        $assignee = $conversation->assignee;
        $organization = $conversation->organization;
        $timezone = $organization ? $this->businessHours->timezone($organization) : 'UTC';
        $now = now()->setTimezone($timezone);
        $nextBusinessDay = $organization ? $this->businessHours->nextBusinessDay($organization, $now) : $now->addWeekday();

        return [
            'contact.name' => $contact ? $contact->displayName() : '',
            'contact.first_name' => $contact ? Str::before(trim($contact->displayName()), ' ') : '',
            'contact.email' => $contact ? $contact->email : '',
            'conversation.subject' => (string) $conversation->subject,
            'conversation.id' => $conversation->uuid,
            'inbox.name' => $conversation->inbox->name,
            'organization.name' => $organization ? $organization->name : '',
            'assignee.name' => $assignee ? $assignee->name : '',
            'assignee.first_name' => $assignee ? Str::before(trim($assignee->name), ' ') : '',
            'date' => $now->isoFormat('LL'),
            'time' => $now->isoFormat('LT'),
            'next_business_day' => $nextBusinessDay->isoFormat('dddd LL'),
        ];
    }

    /**
     * @return array<int, array{key: string, label: string}>
     */
    public static function catalog(): array
    {
        return [
            ['key' => 'contact.name', 'label' => 'Contact full name'],
            ['key' => 'contact.first_name', 'label' => 'Contact first name'],
            ['key' => 'contact.email', 'label' => 'Contact email address'],
            ['key' => 'conversation.subject', 'label' => 'Conversation subject'],
            ['key' => 'conversation.id', 'label' => 'Conversation reference'],
            ['key' => 'inbox.name', 'label' => 'Inbox name'],
            ['key' => 'organization.name', 'label' => 'Organization name'],
            ['key' => 'assignee.name', 'label' => 'Assignee full name'],
            ['key' => 'assignee.first_name', 'label' => 'Assignee first name'],
            ['key' => 'date', 'label' => 'Current date'],
            ['key' => 'time', 'label' => 'Current time'],
            ['key' => 'next_business_day', 'label' => 'Next business day'],
        ];
    }

    protected function contact(Conversation $conversation, ?Message $inbound): ?Address
    {
        if ($inbound) {
            $address = Address::fromArrayList($inbound->reply_to)[0] ?? Address::fromArray($inbound->from);

            if ($address) {
                return $address;
            }
        }

        return Address::fromArrayList($conversation->participants)[0] ?? null;
    }
}

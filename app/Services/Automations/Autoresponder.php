<?php

namespace App\Services\Automations;

use App\Enums\DraftKind;
use App\Models\AutomationRule;
use App\Models\AutoresponderSend;
use App\Models\Conversation;
use App\Models\Draft;
use App\Models\Inbox;
use App\Models\MailAccount;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\SenderIdentity;
use App\Models\User;
use App\Services\Mail\Data\Address;
use App\Services\Mail\Ingestion\HtmlSanitizer;
use App\Services\Mail\Outbound\MessageSender;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Implements the `reply_with_template` action with every loop and abuse guard: never answer
 * automated mail, never answer ourselves, and never answer the same person twice within the
 * cooldown window. Every send is recorded in the autoresponder ledger.
 */
class Autoresponder
{
    public const DEFAULT_COOLDOWN_MINUTES = 1440;

    /** @var array<int, string> */
    protected const BLOCKED_SENDER_PATTERNS = ['mailer-daemon', 'noreply', 'no-reply', 'no_reply', 'donotreply', 'do-not-reply', 'postmaster', 'bounce'];

    public function __construct(
        protected TemplateRenderer $renderer,
        protected HtmlSanitizer $sanitizer,
        protected MessageSender $sender,
    ) {}

    /**
     * @param  array{template_id?: mixed, cooldown_minutes?: mixed}  $params
     * @param  array{message?: Message|null}  $context
     * @return array{status: 'sent'|'skipped', reason?: string, recipient?: string, message_id?: int, template_id?: int}
     */
    public function respond(AutomationRule $rule, Conversation $conversation, array $params, array $context = []): array
    {
        $inbound = $this->inboundMessage($conversation, $context);

        if (! $inbound) {
            return ['status' => 'skipped', 'reason' => 'no_inbound_message'];
        }

        $recipient = Address::fromArrayList($inbound->reply_to)[0] ?? Address::fromArray($inbound->from);

        if (! $recipient || ! filter_var($recipient->email, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'skipped', 'reason' => 'no_recipient'];
        }

        if ($this->isAutoSubmitted($inbound)) {
            return ['status' => 'skipped', 'reason' => 'auto_submitted', 'recipient' => $recipient->email];
        }

        if ($this->isBlockedSender($recipient->email) || $this->isBlockedSender((string) $inbound->fromAddress())) {
            return ['status' => 'skipped', 'reason' => 'blocked_sender', 'recipient' => $recipient->email];
        }

        if ($this->isOwnAddress($conversation, $recipient->email) || $this->isOwnAddress($conversation, (string) $inbound->fromAddress())) {
            return ['status' => 'skipped', 'reason' => 'own_address', 'recipient' => $recipient->email];
        }

        if ($this->withinCooldown($rule, $recipient->email, $this->cooldownMinutes($rule, $params))) {
            return ['status' => 'skipped', 'reason' => 'cooldown', 'recipient' => $recipient->email];
        }

        $template = MessageTemplate::query()
            ->where('organization_id', $conversation->organization_id)
            ->find(is_numeric($params['template_id'] ?? null) ? (int) $params['template_id'] : 0);

        if (! $template) {
            return ['status' => 'skipped', 'reason' => 'template_missing', 'recipient' => $recipient->email];
        }

        $user = $this->sendingUser($rule, $conversation);

        if (! $user) {
            return ['status' => 'skipped', 'reason' => 'no_sending_user', 'recipient' => $recipient->email, 'template_id' => $template->id];
        }

        $html = $this->sanitizer->sanitize($this->renderer->render($template->body_html, $conversation, $context));

        if ($html === null) {
            return ['status' => 'skipped', 'reason' => 'empty_body', 'recipient' => $recipient->email, 'template_id' => $template->id];
        }

        $draft = Draft::create([
            'conversation_id' => $conversation->id,
            'inbox_id' => $conversation->inbox_id,
            'mail_account_id' => $inbound->mail_account_id ?? $conversation->mail_account_id,
            'user_id' => $user->id,
            'in_reply_to_message_id' => $inbound->id,
            'kind' => DraftKind::Reply,
            'to' => [$recipient->toArray()],
            'cc' => [],
            'bcc' => [],
            'subject' => $template->subject ? $this->renderer->render($template->subject, $conversation, $context) : null,
            'html_body' => $html,
            'last_saved_at' => now(),
        ]);

        $message = $this->sender->sendDraft($draft, $user);

        $message->forceFill([
            'headers' => array_merge($message->headers ?? [], [
                'x-tandem-autoresponse' => $rule->uuid,
                'auto-submitted' => 'auto-replied',
            ]),
        ])->save();

        AutoresponderSend::create([
            'automation_rule_id' => $rule->id,
            'conversation_id' => $conversation->id,
            'recipient_email' => $recipient->email,
            'sent_at' => now(),
        ]);

        return ['status' => 'sent', 'recipient' => $recipient->email, 'message_id' => $message->id, 'template_id' => $template->id];
    }

    /**
     * Whether an inbound message was produced by a machine (bounce, list, another autoresponder...).
     */
    public function isAutoSubmitted(Message $message): bool
    {
        $autoSubmitted = $this->header($message, 'auto-submitted');

        if ($autoSubmitted !== null && Str::lower($autoSubmitted) !== 'no') {
            return true;
        }

        $precedence = Str::lower((string) $this->header($message, 'precedence'));

        if (in_array($precedence, ['bulk', 'list', 'junk'], true)) {
            return true;
        }

        foreach (['x-auto-response-suppress', 'list-id', 'x-tandem-autoresponse', 'x-autoreply', 'x-autorespond'] as $name) {
            if ($this->header($message, $name) !== null) {
                return true;
            }
        }

        return false;
    }

    public function isBlockedSender(string $email): bool
    {
        $email = Str::lower(trim($email));

        if ($email === '') {
            return true;
        }

        return Str::contains($email, self::BLOCKED_SENDER_PATTERNS);
    }

    /**
     * Whether the address belongs to one of the organization's own mail accounts, identities or inboxes.
     */
    public function isOwnAddress(Conversation $conversation, string $email): bool
    {
        $email = Str::lower(trim($email));

        if ($email === '' || $conversation->organization_id === null) {
            return false;
        }

        $organizationId = $conversation->organization_id;

        return MailAccount::query()->where('organization_id', $organizationId)->where('email_address', $email)->exists()
            || Inbox::query()->where('organization_id', $organizationId)->whereRaw('LOWER(email_address) = ?', [$email])->exists()
            || SenderIdentity::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->whereHas('mailAccount', fn (Builder $q) => $q->where('organization_id', $organizationId))
                ->exists();
    }

    /**
     * @param  array{cooldown_minutes?: mixed}  $params
     */
    public function cooldownMinutes(AutomationRule $rule, array $params): int
    {
        $fromParams = $params['cooldown_minutes'] ?? null;

        if (is_numeric($fromParams)) {
            return max(0, (int) $fromParams);
        }

        return $rule->cooldown_minutes ?? self::DEFAULT_COOLDOWN_MINUTES;
    }

    public function withinCooldown(AutomationRule $rule, string $recipient, int $minutes): bool
    {
        if ($minutes <= 0) {
            return false;
        }

        return AutoresponderSend::query()
            ->where('automation_rule_id', $rule->id)
            ->where('recipient_email', Str::lower($recipient))
            ->where('sent_at', '>=', now()->subMinutes($minutes))
            ->exists();
    }

    /**
     * The user the automated reply is sent as: the rule's creator, else the organization owner.
     */
    public function sendingUser(AutomationRule $rule, Conversation $conversation): ?User
    {
        if ($rule->created_by_id) {
            $creator = User::query()->find($rule->created_by_id);

            if ($creator && $conversation->organization && $creator->belongsToOrganization($conversation->organization)) {
                return $creator;
            }
        }

        $owner = $conversation->organization?->owner();

        return $owner instanceof User ? $owner : null;
    }

    /**
     * @param  array{message?: Message|null}  $context
     */
    protected function inboundMessage(Conversation $conversation, array $context): ?Message
    {
        $message = $context['message'] ?? null;

        if ($message instanceof Message && $message->isInbound() && $message->conversation_id === $conversation->id) {
            return $message;
        }

        return $conversation->messages()->where('direction', 'inbound')->latest('sent_at')->latest('id')->first();
    }

    protected function header(Message $message, string $name): ?string
    {
        $headers = $message->headers ?? [];
        $value = null;

        foreach ($headers as $key => $candidate) {
            if (Str::lower((string) $key) === $name) {
                $value = $candidate;
                break;
            }
        }

        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_scalar($value) ? trim((string) $value) : null;
    }
}

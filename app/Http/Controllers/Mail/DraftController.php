<?php

namespace App\Http\Controllers\Mail;

use App\Enums\ConversationStatus;
use App\Enums\DraftKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\DraftResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Draft;
use App\Models\Inbox;
use App\Models\MailAccount;
use App\Models\Message;
use App\Models\SenderIdentity;
use App\Services\Conversations\ConversationActions;
use App\Services\Mail\Data\Address;
use App\Services\Mail\Outbound\MessageSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DraftController extends Controller
{
    public function __construct(protected MessageSender $sender, protected ConversationActions $actions) {}

    /**
     * Start a draft: a new message in an inbox, or a reply / reply-all / forward on a conversation.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'inbox' => ['required', 'string', 'exists:inboxes,uuid'],
            'conversation' => ['nullable', 'string', 'exists:conversations,uuid'],
            'kind' => ['required', Rule::enum(DraftKind::class)],
            'in_reply_to' => ['nullable', 'string', 'exists:messages,uuid'],
            'is_shared' => ['boolean'],
        ]);

        $inbox = Inbox::query()->where('uuid', $data['inbox'])->firstOrFail();
        $this->authorize('act', $inbox);

        $conversation = isset($data['conversation']) ? Conversation::query()->where('uuid', $data['conversation'])->firstOrFail() : null;

        if ($conversation) {
            $this->authorize('reply', $conversation);
        }

        $kind = DraftKind::from($data['kind']);
        $parent = isset($data['in_reply_to'])
            ? Message::query()->where('uuid', $data['in_reply_to'])->firstOrFail()
            : $conversation?->messages()->where('is_draft', false)->latest('sent_at')->first();

        $existing = $conversation && $kind !== DraftKind::New
            ? $conversation->drafts()->where('user_id', $request->user()->id)->where('kind', $kind->value)->first()
            : null;

        if ($existing) {
            return response()->json(['draft' => DraftResource::make($existing)->resolve()]);
        }

        $account = $this->resolveAccount($inbox, $conversation);
        $identity = $account?->identities()->orderByDesc('is_default')->first();

        [$to, $cc] = $this->recipientsFor($kind, $parent, $account, $inbox);

        $draft = Draft::create([
            'conversation_id' => $conversation?->id,
            'inbox_id' => $inbox->id,
            'mail_account_id' => $account?->id,
            'sender_identity_id' => $identity?->id,
            'user_id' => $request->user()->id,
            'in_reply_to_message_id' => $kind === DraftKind::New ? null : $parent?->id,
            'kind' => $kind,
            'to' => $to,
            'cc' => $cc,
            'bcc' => [],
            'subject' => $kind === DraftKind::New ? null : $this->subjectFor($kind, $conversation?->subject),
            'html_body' => $this->initialBody($kind, $parent, $identity, $request->user()->signature_html),
            'is_shared' => (bool) ($data['is_shared'] ?? $inbox->isShared()),
            'locked_by_id' => $request->user()->id,
            'locked_at' => now(),
            'last_saved_at' => now(),
        ]);

        $conversation?->forceFill(['has_draft' => true])->save();

        return response()->json(['draft' => DraftResource::make($draft)->resolve()], 201);
    }

    public function update(Request $request, Draft $draft): JsonResponse
    {
        $this->authorize('update', $draft);

        $data = $request->validate([
            'to' => ['array'],
            'to.*.email' => ['required', 'email'],
            'to.*.name' => ['nullable', 'string', 'max:190'],
            'cc' => ['array'],
            'cc.*.email' => ['required', 'email'],
            'cc.*.name' => ['nullable', 'string', 'max:190'],
            'bcc' => ['array'],
            'bcc.*.email' => ['required', 'email'],
            'bcc.*.name' => ['nullable', 'string', 'max:190'],
            'subject' => ['nullable', 'string', 'max:255'],
            'html_body' => ['nullable', 'string', 'max:2000000'],
            'sender_identity_id' => ['nullable', 'integer', 'exists:sender_identities,id'],
            'mail_account_uuid' => ['nullable', 'string', 'exists:mail_accounts,uuid'],
            'is_shared' => ['boolean'],
        ]);

        if (isset($data['mail_account_uuid'])) {
            $account = MailAccount::query()->where('uuid', $data['mail_account_uuid'])->firstOrFail();
            abort_unless($request->user()->can('view', $account), 403);
            $draft->mail_account_id = $account->id;
        }

        if (array_key_exists('sender_identity_id', $data) && $data['sender_identity_id']) {
            $identity = SenderIdentity::query()->whereKey((int) $data['sender_identity_id'])->firstOrFail();
            abort_unless($identity->mail_account_id === $draft->mail_account_id, 422, 'Identity does not belong to the selected account.');
            $draft->sender_identity_id = $identity->id;
        }

        $draft->fill(collect($data)->only(['to', 'cc', 'bcc', 'subject', 'html_body', 'is_shared'])->all());
        $draft->locked_by_id = $request->user()->id;
        $draft->locked_at = now();
        $draft->last_saved_at = now();
        $draft->save();

        return response()->json(['draft' => DraftResource::make($draft->fresh())->resolve()]);
    }

    public function send(Request $request, Draft $draft): JsonResponse
    {
        $this->authorize('send', $draft);

        $data = $request->validate([
            'then' => ['nullable', Rule::in(['open', 'close', 'pending'])],
        ]);

        $draft->loadMissing('conversation');

        abort_if(empty($draft->to), 422, 'Add at least one recipient.');

        $status = match ($data['then'] ?? null) {
            'close' => ConversationStatus::Closed,
            'pending' => ConversationStatus::Pending,
            default => null,
        };

        $message = $this->sender->sendDraft($draft, $request->user(), ['status' => $status]);

        return response()->json([
            'message' => MessageResource::make($message->load(['attachments', 'author']))->resolve(),
            'conversation_uuid' => $message->conversation->uuid,
        ]);
    }

    public function destroy(Request $request, Draft $draft): JsonResponse
    {
        $this->authorize('delete', $draft);

        $conversation = $draft->conversation;
        $draft->attachments()->each(fn ($attachment) => $attachment->delete());
        $draft->forceDelete();
        $conversation?->forceFill(['has_draft' => $conversation->drafts()->exists()])->save();

        return response()->json(['deleted' => true]);
    }

    protected function resolveAccount(Inbox $inbox, ?Conversation $conversation): ?MailAccount
    {
        if ($conversation?->mail_account_id && $conversation->inbox_id === $inbox->id) {
            return $conversation->mailAccount;
        }

        return $inbox->mailAccounts()->orderBySendingPreference()->first();
    }

    /**
     * @return array{0: array<int, array{name: string|null, email: string}>, 1: array<int, array{name: string|null, email: string}>}
     */
    protected function recipientsFor(DraftKind $kind, ?Message $parent, ?MailAccount $account, Inbox $inbox): array
    {
        if ($kind === DraftKind::New || $kind === DraftKind::Forward || ! $parent) {
            return [[], []];
        }

        $own = collect([$account?->email_address, $inbox->email_address])
            ->merge($account?->identities->pluck('email') ?? [])
            ->filter()
            ->map(fn ($e) => strtolower($e));

        $replyTo = Address::fromArrayList($parent->reply_to ?: ($parent->from ? [$parent->from] : []));

        if ($parent->isOutbound()) {
            $to = Address::fromArrayList($parent->to);
        } else {
            $to = $replyTo;
        }

        $to = collect($to)->reject(fn (Address $a) => $own->contains($a->email))->values();

        if ($to->isEmpty() && $parent->from) {
            $to = collect([Address::fromArray($parent->from)])->filter()->values();
        }

        $cc = collect();

        if ($kind === DraftKind::ReplyAll) {
            $cc = collect([...Address::fromArrayList($parent->to), ...Address::fromArrayList($parent->cc)])
                ->reject(fn (Address $a) => $own->contains($a->email) || $to->contains(fn (Address $t) => $t->email === $a->email))
                ->unique(fn (Address $a) => $a->email)
                ->values();
        }

        return [$to->map(fn (Address $a) => $a->toArray())->all(), $cc->map(fn (Address $a) => $a->toArray())->all()];
    }

    protected function subjectFor(DraftKind $kind, ?string $subject): string
    {
        $subject = trim((string) $subject);

        return match ($kind) {
            DraftKind::Forward => preg_match('/^fwd?:/i', $subject) ? $subject : 'Fwd: '.$subject,
            DraftKind::New => $subject,
            default => preg_match('/^re:/i', $subject) ? $subject : 'Re: '.$subject,
        };
    }

    protected function initialBody(DraftKind $kind, ?Message $parent, ?SenderIdentity $identity, ?string $userSignature): string
    {
        $signature = $identity?->signature_html ?: $userSignature;
        $body = '<p></p>';

        if ($signature) {
            $body .= '<div class="tandem-signature">'.$signature.'</div>';
        }

        if ($kind === DraftKind::Forward && $parent) {
            $from = e($parent->fromName() ? $parent->fromName().' <'.$parent->fromAddress().'>' : (string) $parent->fromAddress());
            $to = e(collect($parent->to ?? [])->map(fn ($a) => $a['email'])->implode(', '));
            $date = e((string) $parent->sent_at?->toDayDateTimeString());

            $body .= '<br><div class="tandem-forward"><p>---------- Forwarded message ----------<br>From: '.$from.'<br>Date: '.$date.'<br>Subject: '.e((string) $parent->subject).'<br>To: '.$to.'</p>'.($parent->html_body ?? nl2br(e((string) $parent->text_body))).'</div>';
        } elseif ($parent && $kind !== DraftKind::New) {
            $attribution = 'On '.e((string) $parent->sent_at?->toDayDateTimeString()).', '.e($parent->fromName() ?: (string) $parent->fromAddress()).' wrote:';
            $body .= '<br><div class="tandem-quote"><p>'.$attribution.'</p><blockquote>'.($parent->html_body ?? nl2br(e((string) $parent->text_body))).'</blockquote></div>';
        }

        return $body;
    }
}

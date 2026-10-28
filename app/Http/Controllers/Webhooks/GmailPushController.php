<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\MailAccountStatus;
use App\Enums\MailProvider;
use App\Enums\SyncRunType;
use App\Http\Controllers\Controller;
use App\Jobs\Mail\SyncMailAccount;
use App\Models\MailAccount;
use App\Models\WebhookReceipt;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Receives Google Pub/Sub push deliveries for Gmail watches. The envelope's data field is a
 * base64 JSON document `{emailAddress, historyId}`; every delivery is claimed exactly once.
 */
class GmailPushController extends Controller
{
    public function __invoke(Request $request, string $token): Response
    {
        $expected = (string) config('tandem.gmail.pubsub_token');

        abort_if($expected === '' || ! hash_equals($expected, $token), 404);

        $content = $request->getContent();

        if ($content === '' || ! json_validate($content)) {
            return response('Malformed JSON.', 400);
        }

        $envelope = json_decode($content, true);
        $message = is_array($envelope) ? ($envelope['message'] ?? null) : null;

        if (! is_array($message)) {
            Log::info('Gmail push without a message envelope ignored');

            return response()->noContent();
        }

        $messageId = (string) ($message['messageId'] ?? $message['message_id'] ?? '');
        $data = json_decode((string) base64_decode((string) ($message['data'] ?? ''), true), true);

        if ($messageId === '' || ! is_array($data) || empty($data['emailAddress'])) {
            Log::info('Gmail push with unusable payload ignored', ['message_id' => $messageId]);

            return response()->noContent();
        }

        $email = Str::lower(trim((string) $data['emailAddress']));
        $historyId = (string) ($data['historyId'] ?? '');

        $account = MailAccount::query()
            ->where('provider', MailProvider::Gmail)
            ->where('email_address', $email)
            ->first();

        $receipt = WebhookReceipt::claim('gmail', $messageId, $account?->id, [
            'emailAddress' => $email,
            'historyId' => $historyId,
            'subscription' => $envelope['subscription'] ?? null,
        ]);

        if ($receipt === null) {
            return response()->noContent();
        }

        if ($account === null) {
            Log::info('Gmail push for unknown account ignored', ['email' => $email]);
            $receipt->markProcessed();

            return response()->noContent();
        }

        if ($this->shouldSync($account, $historyId)) {
            SyncMailAccount::dispatch($account, SyncRunType::Incremental);
        }

        $receipt->markProcessed();

        return response()->noContent();
    }

    protected function shouldSync(MailAccount $account, string $historyId): bool
    {
        if (in_array($account->status, [MailAccountStatus::Disconnected, MailAccountStatus::NeedsReauth], true)) {
            return false;
        }

        $stored = (string) $account->syncState('history_id', '');

        if ($historyId === '' || $stored === '') {
            return true;
        }

        return (int) $historyId > (int) $stored;
    }
}

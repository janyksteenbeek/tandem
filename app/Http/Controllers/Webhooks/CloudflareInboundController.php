<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\MailAccountStatus;
use App\Enums\MailProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\CloudflareInboundRequest;
use App\Jobs\Mail\IngestForwardedEmail;
use App\Models\MailAccount;
use App\Models\WebhookReceipt;
use App\Services\Mail\Providers\Forwarding\ForwardingAddressGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Receives raw MIME from the Cloudflare Email Worker. Stays fast: validate, dedupe, resolve
 * the forwarding address, persist the bytes and queue the real work. Never parses MIME.
 *
 * Responses other than 401/413/422 are always 2xx so the Worker (and the upstream MTA) does not
 * retry mail we deliberately dropped.
 */
class CloudflareInboundController extends Controller
{
    public const PROVIDER = 'cloudflare';

    public const DISK = 'local';

    public const DIRECTORY = 'inbound';

    public function __invoke(CloudflareInboundRequest $request, ForwardingAddressGenerator $addresses): JsonResponse
    {
        $maxBytes = (int) config('tandem.forwarding.max_message_bytes');
        $size = (int) $request->validated('size');

        if ($size > $maxBytes) {
            return response()->json(['status' => 'too_large', 'max_bytes' => $maxBytes], 413);
        }

        $raw = base64_decode((string) $request->validated('raw'), true);

        if ($raw === false || $raw === '') {
            return response()->json(['status' => 'invalid', 'errors' => ['raw' => ['The raw field must be valid base64.']]], 422);
        }

        if (strlen($raw) > $maxBytes) {
            return response()->json(['status' => 'too_large', 'max_bytes' => $maxBytes], 413);
        }

        $recipient = $addresses->normalizeRecipient((string) $request->validated('to'));

        $receipt = WebhookReceipt::claim(self::PROVIDER, (string) $request->validated('id'), null, [
            'to' => $recipient,
            'envelope_to' => $request->validated('to'),
            'from' => $request->validated('from'),
            'size' => strlen($raw),
            'received_at' => $request->validated('received_at'),
            'hops' => $request->hops(),
            'headers' => $request->interestingHeaders(),
        ]);

        if ($receipt === null) {
            return response()->json(['status' => 'duplicate']);
        }

        $account = MailAccount::query()
            ->where('provider', MailProvider::Forwarding)
            ->where('email_address', $recipient)
            ->first();

        if ($account === null || $account->status === MailAccountStatus::Disconnected) {
            Log::info('Cloudflare inbound mail for unknown or disconnected forwarding address.', [
                'to' => $recipient,
                'from' => $request->validated('from'),
                'receipt_id' => $receipt->id,
                'account_status' => $account?->status->value,
            ]);

            $receipt->update(['status' => 'skipped', 'payload' => array_merge($receipt->payload ?? [], ['skipped_reason' => 'unknown_recipient'])]);
            $receipt->markProcessed();

            return response()->json(['status' => 'unknown_recipient'], 202);
        }

        $path = self::DIRECTORY.'/'.Str::uuid().'.eml';

        Storage::disk(self::DISK)->put($path, $raw);

        $receipt->update([
            'mail_account_id' => $account->id,
            'payload' => array_merge($receipt->payload ?? [], ['path' => $path]),
        ]);

        IngestForwardedEmail::dispatch($account->id, $receipt->id, $path);

        return response()->json(['status' => 'queued'], 202);
    }
}

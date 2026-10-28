<?php

namespace App\Console\Commands\Mail;

use App\Enums\MailProvider;
use App\Http\Controllers\Webhooks\CloudflareInboundController;
use App\Jobs\Mail\IngestForwardedEmail;
use App\Models\MailAccount;
use App\Models\WebhookReceipt;
use App\Services\Mail\Providers\Forwarding\ForwardingAddressGenerator;
use App\Services\Mail\Providers\Forwarding\WebhookSignature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Developer helper: deliver a .eml fixture to a forwarding address as if the Cloudflare
 * Email Worker had posted it, without needing Cloudflare, a public URL or a queue worker.
 */
class SimulateForwardedEmailCommand extends Command
{
    protected $signature = 'mail:forwarding:simulate
        {address : The forwarding address (local part or full address) to deliver to}
        {file=tests/Fixtures/mail/simple.eml : Path to a raw RFC 5322 .eml file}
        {--http : Post the signed payload through the HTTP kernel instead of ingesting directly}
        {--hops=1 : Value for the hop counter}';

    protected $description = 'Simulate a message arriving at a forwarding address via the Cloudflare Email Worker';

    public function handle(ForwardingAddressGenerator $addresses, WebhookSignature $signature): int
    {
        $address = (string) $this->argument('address');

        if (! str_contains($address, '@')) {
            $address .= '@'.$addresses->domain();
        }

        $recipient = $addresses->normalizeRecipient($address);
        $file = (string) $this->argument('file');
        $path = Str::startsWith($file, '/') ? $file : base_path($file);

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $raw = (string) file_get_contents($path);

        $payload = [
            'id' => 'simulate-'.Str::uuid(),
            'to' => $recipient,
            'from' => $this->envelopeFrom($raw),
            'size' => strlen($raw),
            'raw' => base64_encode($raw),
            'received_at' => now()->toIso8601String(),
            'headers' => array_filter([
                'message-id' => $this->header($raw, 'Message-ID'),
                'subject' => $this->header($raw, 'Subject'),
            ]),
            'hops' => (int) $this->option('hops'),
        ];

        return $this->option('http')
            ? $this->postThroughKernel($payload, $signature)
            : $this->ingestDirectly($payload, $raw, $recipient);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function ingestDirectly(array $payload, string $raw, string $recipient): int
    {
        $account = MailAccount::query()
            ->where('provider', MailProvider::Forwarding)
            ->where('email_address', $recipient)
            ->first();

        if ($account === null) {
            $this->error("No forwarding account exists for {$recipient}. Create one from the inbox settings first.");

            return self::FAILURE;
        }

        $storagePath = CloudflareInboundController::DIRECTORY.'/'.Str::uuid().'.eml';
        Storage::disk(CloudflareInboundController::DISK)->put($storagePath, $raw);

        $receipt = WebhookReceipt::claim(CloudflareInboundController::PROVIDER, (string) $payload['id'], $account->id, [
            'to' => $recipient,
            'from' => $payload['from'],
            'size' => $payload['size'],
            'received_at' => $payload['received_at'],
            'hops' => $payload['hops'],
            'headers' => $payload['headers'],
            'path' => $storagePath,
            'simulated' => true,
        ]);

        if ($receipt === null) {
            $this->error('Could not claim a webhook receipt for the simulated delivery.');

            return self::FAILURE;
        }

        IngestForwardedEmail::dispatchSync($account->id, $receipt->id, $storagePath);

        $receipt->refresh();

        $this->components->info(sprintf('Receipt #%d for %s is now "%s".', $receipt->id, $recipient, $receipt->status));

        if ($receipt->status === 'processed') {
            $this->components->twoColumnDetail('Inbox', $account->inbox->name);
            $this->components->twoColumnDetail('Conversation', (string) ($receipt->payload['conversation_id'] ?? '-'));
            $this->components->twoColumnDetail('Message', (string) ($receipt->payload['message_id'] ?? '-'));
        } elseif (isset($receipt->payload['skipped_reason'])) {
            $this->components->warn('Skipped: '.$receipt->payload['skipped_reason']);
        }

        return $receipt->status === 'processed' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function postThroughKernel(array $payload, WebhookSignature $signature): int
    {
        $secret = (string) config('tandem.forwarding.webhook_secret');

        if ($secret === '') {
            $this->error('TANDEM_FORWARDING_WEBHOOK_SECRET is not configured.');

            return self::FAILURE;
        }

        $body = (string) json_encode($payload);

        $request = Request::create(route('webhooks.cloudflare.inbound', absolute: false), 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_'.strtoupper(str_replace('-', '_', WebhookSignature::HEADER)) => $signature->sign($secret, $body),
        ], content: $body);

        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        $this->components->twoColumnDetail('HTTP status', (string) $response->getStatusCode());
        $this->line((string) $response->getContent());

        return $response->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }

    protected function envelopeFrom(string $raw): string
    {
        $from = $this->header($raw, 'From') ?? '';

        return preg_match('/<([^>]+)>/', $from, $matches) ? $matches[1] : trim($from);
    }

    protected function header(string $raw, string $name): ?string
    {
        $headers = Str::before(str_replace("\r\n", "\n", $raw), "\n\n");

        return preg_match('/^'.preg_quote($name, '/').':[ \t]*(.+)$/mi', $headers, $matches) ? trim($matches[1]) : null;
    }
}

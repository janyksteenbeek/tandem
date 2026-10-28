<?php

namespace App\Http\Middleware;

use App\Services\Mail\Providers\Forwarding\WebhookSignature;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates calls from the Cloudflare Email Worker using the timestamped HMAC header.
 */
class VerifyCloudflareWorkerSignature
{
    public function __construct(protected WebhookSignature $signature) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('tandem.forwarding.webhook_secret');

        if ($secret === '') {
            Log::error('Cloudflare inbound webhook called but TANDEM_FORWARDING_WEBHOOK_SECRET is not configured.');

            return response()->json(['status' => 'unauthorized', 'message' => 'Webhook secret not configured.'], 401);
        }

        $header = $request->header(WebhookSignature::HEADER);

        if (! is_string($header) || ! $this->signature->verify($secret, $request->getContent(), $header)) {
            return response()->json(['status' => 'unauthorized', 'message' => 'Invalid or expired signature.'], 401);
        }

        return $next($request);
    }
}

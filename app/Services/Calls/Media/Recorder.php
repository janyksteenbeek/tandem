<?php

namespace App\Services\Calls\Media;

use App\Models\Calls\CallRecording;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Client for the recorder inside the media server (media/tandem-media). Starting and stopping are requests; the
 * outcome always arrives later through the signed recorder webhook.
 */
class Recorder
{
    public function isConfigured(): bool
    {
        return filled(config('calls.recorder.url')) && filled(config('calls.recorder.secret'));
    }

    /**
     * @throws RecorderException
     */
    public function start(CallRecording $recording): void
    {
        $response = $this->send(fn (PendingRequest $request) => $request->post('/recordings', [
            'id' => $recording->uuid,
            'room' => $recording->call->roomName(),
            'mode' => $recording->mode->value,
            'path' => $recording->path,
        ]));

        if ($response->status() === 507) {
            throw new RecorderException('There is not enough free disk space to record.');
        }

        if ($response->failed()) {
            throw new RecorderException((string) ($response->json('error') ?? 'The recorder refused to start.'));
        }
    }

    /**
     * @throws RecorderException
     */
    public function stop(CallRecording $recording): void
    {
        $response = $this->send(fn (PendingRequest $request) => $request->post('/recordings/'.$recording->uuid.'/stop'));

        // Unknown to the recorder means it already finished or never started; the caller settles it.
        if ($response->failed() && $response->status() !== 404) {
            throw new RecorderException((string) ($response->json('error') ?? 'The recorder refused to stop.'));
        }
    }

    /**
     * The recorder's view of one recording, or null when it does not know it.
     *
     * @return array<string, mixed>|null
     *
     * @throws RecorderException
     */
    public function status(CallRecording $recording): ?array
    {
        $response = $this->send(fn (PendingRequest $request) => $request->get('/recordings/'.$recording->uuid));

        return $response->successful() ? (array) $response->json() : null;
    }

    /**
     * Load, disk and active recordings; null when the recorder is unreachable.
     *
     * @return array<string, mixed>|null
     */
    public function stats(): ?array
    {
        try {
            $response = $this->send(fn (PendingRequest $request) => $request->get('/stats'));
        } catch (RecorderException) {
            return null;
        }

        return $response->successful() ? (array) $response->json() : null;
    }

    /**
     * @param  callable(PendingRequest): Response  $callback
     *
     * @throws RecorderException
     */
    protected function send(callable $callback): Response
    {
        if (! $this->isConfigured()) {
            throw new RecorderException('Recording is not configured on this server.');
        }

        try {
            return $callback(
                Http::baseUrl(rtrim((string) config('calls.recorder.url'), '/'))
                    ->withToken((string) config('calls.recorder.secret'))
                    ->timeout((int) config('calls.recorder.timeout', 5))
                    ->connectTimeout(2)
                    ->acceptJson()
                    ->asJson()
            );
        } catch (ConnectionException $exception) {
            throw new RecorderException('The recorder could not be reached.', previous: $exception);
        }
    }
}

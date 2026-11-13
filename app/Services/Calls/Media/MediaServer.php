<?php

namespace App\Services\Calls\Media;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * The media server's room API (Twirp over HTTP). Everything here acts on live rooms: it is
 * how hosts remove or mute people and how permissions change mid-call. Media itself never
 * passes through this application.
 */
class MediaServer
{
    public function __construct(protected AccessToken $tokens) {}

    public function isConfigured(): bool
    {
        return $this->tokens->isConfigured() && filled(config('calls.livekit.url'));
    }

    /**
     * Create the room ahead of the first participant so limits and timeouts are ours.
     *
     * @return array<string, mixed>
     */
    public function createRoom(string $room, int $maxParticipants, int $emptyTimeout, string $metadata = ''): array
    {
        return $this->call('CreateRoom', [
            'name' => $room,
            'empty_timeout' => $emptyTimeout,
            'departure_timeout' => $emptyTimeout,
            'max_participants' => $maxParticipants,
            'metadata' => $metadata,
        ], ['roomCreate' => true]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRooms(): array
    {
        return array_values((array) ($this->call('ListRooms', (object) [], ['roomList' => true])['rooms'] ?? []));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listParticipants(string $room): array
    {
        return array_values((array) ($this->call('ListParticipants', ['room' => $room], ['roomAdmin' => true, 'room' => $room])['participants'] ?? []));
    }

    public function removeParticipant(string $room, string $identity): void
    {
        $this->call('RemoveParticipant', ['room' => $room, 'identity' => $identity], ['roomAdmin' => true, 'room' => $room]);
    }

    public function muteTrack(string $room, string $identity, string $trackSid, bool $muted = true): void
    {
        $this->call('MutePublishedTrack', ['room' => $room, 'identity' => $identity, 'track_sid' => $trackSid, 'muted' => $muted], ['roomAdmin' => true, 'room' => $room]);
    }

    /**
     * Change what a connected participant may do, without making them reconnect.
     *
     * @param  array<string, mixed>  $permission
     */
    public function updateParticipant(string $room, string $identity, array $permission, ?string $metadata = null): void
    {
        $this->call('UpdateParticipant', array_filter([
            'room' => $room,
            'identity' => $identity,
            'permission' => $permission,
            'metadata' => $metadata,
        ], fn ($value) => $value !== null), ['roomAdmin' => true, 'room' => $room]);
    }

    public function updateRoomMetadata(string $room, string $metadata): void
    {
        $this->call('UpdateRoomMetadata', ['room' => $room, 'metadata' => $metadata], ['roomAdmin' => true, 'room' => $room]);
    }

    public function deleteRoom(string $room): void
    {
        $this->call('DeleteRoom', ['room' => $room], ['roomCreate' => true]);
    }

    /**
     * Whether the server answers at all; used by diagnostics.
     */
    public function isReachable(): bool
    {
        try {
            return $this->request()->get('/')->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>|object  $payload
     * @param  array<string, mixed>  $grant
     * @return array<string, mixed>
     *
     * @throws MediaServerException
     */
    protected function call(string $method, array|object $payload, array $grant): array
    {
        try {
            $response = $this->request()
                ->withToken($this->tokens->sign('tandem', $grant, 60))
                ->post('/twirp/livekit.RoomService/'.$method, $payload);
        } catch (ConnectionException $exception) {
            throw new MediaServerException('The media server could not be reached.', previous: $exception);
        }

        if ($response->failed()) {
            throw new MediaServerException(
                (string) ($response->json('msg') ?? 'The media server refused the request.'),
                $response->status(),
                (string) ($response->json('code') ?? ''),
            );
        }

        return (array) $response->json();
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('calls.livekit.url'), '/'))
            ->timeout((int) config('calls.livekit.timeout', 5))
            ->connectTimeout(2)
            ->acceptJson()
            ->asJson();
    }
}

<?php

namespace App\Services\Calls;

use App\Enums\Calls\CallStatus;
use App\Enums\Calls\ParticipantRole;
use App\Enums\Calls\ParticipantStatus;
use App\Events\Calls\Broadcast\CallParticipantUpdated;
use App\Events\Calls\Broadcast\CallUpdated;
use App\Events\Calls\CallEnded;
use App\Models\Calls\Call;
use App\Models\Calls\CallParticipant;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Calls\Media\AccessToken;
use App\Services\Calls\Media\MediaServer;
use App\Services\Calls\Media\MediaServerException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Every state change of a call and its participants goes through here: it writes the row,
 * mirrors the change onto the media server where that matters, tells the room over Reverb and
 * leaves an audit entry for the sensitive ones.
 */
class CallActions
{
    public function __construct(
        protected MediaServer $media,
        protected AccessToken $tokens,
        protected AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     */
    public function start(Organization $organization, User $host, ?string $title = null, array $settings = [], ?string $password = null, ?Model $linkable = null): Call
    {
        $call = DB::transaction(function () use ($organization, $host, $title, $settings, $password, $linkable) {
            $call = Call::create([
                'organization_id' => $organization->id,
                'host_id' => $host->id,
                'title' => filled($title) ? Str::limit(trim((string) $title), 200, '') : null,
                'status' => CallStatus::Pending,
                'password' => filled($password) ? $password : null,
                'settings' => array_intersect_key($settings, Call::DEFAULT_SETTINGS),
            ]);

            $call->participants()->create([
                'user_id' => $host->id,
                'name' => $host->name,
                'email' => $host->email,
                'role' => ParticipantRole::Host,
                'status' => ParticipantStatus::Admitted,
                'admitted_at' => now(),
            ]);

            if ($linkable !== null) {
                $call->links()->create([
                    'linkable_type' => $linkable->getMorphClass(),
                    'linkable_id' => $linkable->getKey(),
                    'created_by_id' => $host->id,
                ]);
            }

            return $call;
        });

        $this->audit->record('call.created', $organization, $host, $call, ['code' => $call->code]);

        return $call;
    }

    /**
     * Ask to enter a call. Returns the participant in the state the call put them in:
     * admitted, waiting for a host, or (for a returning guest who was turned away) denied.
     *
     * @throws JoinRefused
     */
    public function join(Call $call, ?User $user, string $name, ?string $password = null, ?string $inviteToken = null, ?CallParticipant $existing = null): CallParticipant
    {
        if ($call->hasEnded()) {
            throw JoinRefused::ended();
        }

        if ($existing?->status === ParticipantStatus::Removed) {
            throw JoinRefused::removed();
        }

        if ($existing !== null && ($existing->isInside() || in_array($existing->status, [ParticipantStatus::Waiting, ParticipantStatus::Denied], true))) {
            return $existing;
        }

        $role = $this->roleFor($call, $user);
        $invitation = $existing ?? $this->invitationFor($call, $user, $inviteToken);
        $invited = $invitation?->status === ParticipantStatus::Invited;

        if ($call->setting('locked') && $role !== ParticipantRole::Host) {
            throw JoinRefused::locked();
        }

        if ($role === ParticipantRole::Guest && ! $invited && $call->hasPassword() && ! Hash::check((string) $password, (string) $call->password)) {
            throw JoinRefused::password();
        }

        $waits = $role === ParticipantRole::Guest && ! $invited && $call->setting('waiting_room');

        $attributes = [
            'user_id' => $user?->id,
            'name' => Str::limit(trim($user->name ?? $name), 80, '') ?: 'Guest',
            'email' => $user->email ?? $invitation?->email,
            'role' => $role,
            'status' => $waits ? ParticipantStatus::Waiting : ParticipantStatus::Admitted,
            'admitted_at' => $waits ? null : now(),
            'invite_token' => null,
        ];

        $participant = $invitation ?? $call->participants()->make();
        $participant->fill($attributes)->save();
        $participant->setRelation('call', $call);

        CallParticipantUpdated::dispatch($participant);

        return $participant;
    }

    public function roleFor(Call $call, ?User $user): ParticipantRole
    {
        if ($user === null) {
            return ParticipantRole::Guest;
        }

        if ($call->host_id === $user->id) {
            return ParticipantRole::Host;
        }

        return $user->belongsToOrganization($call->organization) ? ParticipantRole::Member : ParticipantRole::Guest;
    }

    /**
     * The url and short-lived token a browser needs to connect to the media server, scoped to
     * this one room and to what this participant may publish.
     *
     * @return array{url: string, token: string, identity: string}
     *
     * @throws JoinRefused
     */
    public function mediaCredentials(CallParticipant $participant): array
    {
        $call = $participant->call;

        if ($call->hasEnded()) {
            throw JoinRefused::ended();
        }

        if ($participant->status !== ParticipantStatus::Joined && $call->connectedCount() >= (int) config('calls.max_participants')) {
            throw JoinRefused::full();
        }

        if (! $call->isLive()) {
            $this->ensureRoom($call);
        }

        $token = $this->tokens->sign($participant->identity(), [
            'room' => $call->roomName(),
            'roomJoin' => true,
            'canSubscribe' => true,
            'canPublish' => true,
            'canPublishData' => true,
            'canUpdateOwnMetadata' => false,
            'canPublishSources' => $this->publishSources($participant),
        ], (int) config('calls.token_ttl'), [
            'name' => $participant->name,
            'metadata' => $this->metadataFor($participant),
        ]);

        return [
            'url' => (string) config('calls.livekit.public_url'),
            'token' => $token,
            'identity' => $participant->identity(),
        ];
    }

    public function markConnected(CallParticipant $participant): void
    {
        $call = $participant->call;

        if ($call->hasEnded()) {
            return;
        }

        $wasJoined = $participant->status === ParticipantStatus::Joined;

        $participant->forceFill([
            'status' => ParticipantStatus::Joined,
            'joined_at' => $participant->joined_at ?? now(),
            'left_at' => null,
            'last_seen_at' => now(),
        ])->save();

        $connected = $call->connectedCount();
        $wasLive = $call->isLive();

        $call->forceFill([
            'status' => CallStatus::Live,
            'started_at' => $call->started_at ?? now(),
            'peak_participants' => max($call->peak_participants, $connected),
        ])->save();

        if (! $wasJoined) {
            CallParticipantUpdated::dispatch($participant);
        }

        if (! $wasLive) {
            CallUpdated::dispatch($call, 'started');
        }
    }

    public function markDisconnected(CallParticipant $participant): void
    {
        if ($participant->status !== ParticipantStatus::Joined) {
            return;
        }

        $participant->forceFill(['status' => ParticipantStatus::Left, 'left_at' => now()])->save();

        CallParticipantUpdated::dispatch($participant);
    }

    public function admit(CallParticipant $participant, CallParticipant $by): void
    {
        if ($participant->status !== ParticipantStatus::Waiting) {
            return;
        }

        $participant->forceFill(['status' => ParticipantStatus::Admitted, 'admitted_at' => now()])->save();

        CallParticipantUpdated::dispatch($participant);
    }

    public function deny(CallParticipant $participant, CallParticipant $by): void
    {
        if ($participant->status !== ParticipantStatus::Waiting) {
            return;
        }

        $participant->forceFill(['status' => ParticipantStatus::Denied])->save();

        CallParticipantUpdated::dispatch($participant);
    }

    /**
     * Take someone out of the meeting. They cannot come back through the same link.
     */
    public function remove(CallParticipant $participant, CallParticipant $by): void
    {
        $call = $participant->call;

        $this->quietly(fn () => $this->media->removeParticipant($call->roomName(), $participant->identity()));

        $participant->forceFill(['status' => ParticipantStatus::Removed, 'left_at' => now()])->save();

        CallParticipantUpdated::dispatch($participant);

        $this->audit->record('call.participant_removed', $call->organization, $by->user, $call, [
            'participant' => $participant->name,
            'by' => $by->name,
        ]);
    }

    /**
     * Switch off someone's microphone. They can unmute themselves again; hosts cannot unmute
     * anyone, which would be listening in.
     */
    public function mute(CallParticipant $participant, CallParticipant $by): bool
    {
        $room = $participant->call->roomName();
        $muted = false;

        $this->quietly(function () use ($room, $participant, &$muted) {
            foreach ($this->media->listParticipants($room) as $remote) {
                if (($remote['identity'] ?? null) !== $participant->identity()) {
                    continue;
                }

                foreach ((array) ($remote['tracks'] ?? []) as $track) {
                    if (($track['source'] ?? null) === 'MICROPHONE' && empty($track['muted'])) {
                        $this->media->muteTrack($room, $participant->identity(), (string) $track['sid']);
                        $muted = true;
                    }
                }
            }
        });

        return $muted;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function updateSettings(Call $call, CallParticipant $by, array $settings, ?string $password = null, bool $clearPassword = false): Call
    {
        $before = $call->allSettings();

        $call->settings = [...($call->settings ?? []), ...array_intersect_key($settings, Call::DEFAULT_SETTINGS)];

        if ($clearPassword) {
            $call->password = null;
        } elseif (filled($password)) {
            $call->password = $password;
        }

        $call->save();

        if ($before['screen_share'] !== $call->setting('screen_share')) {
            $this->syncPublishPermissions($call);
        }

        if ($before['waiting_room'] && ! $call->setting('waiting_room')) {
            $this->admitWaiting($call, $by);
        }

        CallUpdated::dispatch($call, 'settings');

        $this->audit->record('call.settings_updated', $call->organization, $by->user, $call, [
            'changed' => array_keys(array_diff_assoc(array_map('json_encode', $call->allSettings()), array_map('json_encode', $before))),
            'password' => $clearPassword ? 'removed' : (filled($password) ? 'set' : 'unchanged'),
        ]);

        return $call;
    }

    public function rename(Call $call, ?string $title): Call
    {
        $call->forceFill(['title' => filled($title) ? Str::limit(trim((string) $title), 200, '') : null])->save();

        CallUpdated::dispatch($call, 'renamed');

        return $call;
    }

    /**
     * End the meeting for everyone and close the room.
     */
    public function end(Call $call, ?CallParticipant $by = null, string $reason = 'ended'): void
    {
        if ($call->hasEnded()) {
            return;
        }

        $this->quietly(fn () => $this->media->deleteRoom($call->roomName()));

        $call->forceFill([
            'status' => CallStatus::Ended,
            'ended_at' => now(),
        ])->save();

        $call->participants()
            ->where('status', ParticipantStatus::Joined->value)
            ->update(['status' => ParticipantStatus::Left->value, 'left_at' => now()]);

        $call->participants()
            ->where('status', ParticipantStatus::Waiting->value)
            ->update(['status' => ParticipantStatus::Denied->value]);

        CallUpdated::dispatch($call, 'ended');
        CallEnded::dispatch($call);

        if ($by !== null) {
            $this->audit->record('call.ended', $call->organization, $by->user, $call, ['by' => $by->name, 'reason' => $reason]);
        }
    }

    /**
     * The sources a participant may publish. Screen sharing can be reserved for hosts.
     *
     * @return list<string>
     */
    public function publishSources(CallParticipant $participant): array
    {
        $sources = ['camera', 'microphone'];

        if (ParticipantAbilities::for($participant)->shareScreen()) {
            array_push($sources, 'screen_share', 'screen_share_audio');
        }

        return $sources;
    }

    /**
     * The waiting room was switched off: whoever is still in it walks in, the same as anyone
     * arriving from now on. A locked meeting keeps them waiting, since nobody new may join it.
     */
    protected function admitWaiting(Call $call, CallParticipant $by): void
    {
        if ($call->setting('locked')) {
            return;
        }

        $call->participants()->where('status', ParticipantStatus::Waiting->value)->get()->each(function (CallParticipant $participant) use ($call, $by) {
            $this->admit($participant->setRelation('call', $call), $by);
        });
    }

    /**
     * Push the current screen-sharing rule to everyone who is already connected.
     */
    protected function syncPublishPermissions(Call $call): void
    {
        $call->participants()->where('status', ParticipantStatus::Joined->value)->get()->each(function (CallParticipant $participant) use ($call) {
            $participant->setRelation('call', $call);

            $this->quietly(fn () => $this->media->updateParticipant($call->roomName(), $participant->identity(), [
                'can_subscribe' => true,
                'can_publish' => true,
                'can_publish_data' => true,
                'can_publish_sources' => array_map('strtoupper', $this->publishSources($participant)),
            ]));
        });
    }

    protected function ensureRoom(Call $call): void
    {
        try {
            $this->media->createRoom(
                $call->roomName(),
                (int) config('calls.max_participants') + 1, // one seat for the recorder
                (int) config('calls.empty_timeout'),
            );
        } catch (MediaServerException $exception) {
            // The server creates rooms on first join as well; a failure here only costs us our limits.
            Log::warning('calls.room_create_failed', ['call' => $call->uuid, 'error' => $exception->getMessage()]);
        }
    }

    protected function metadataFor(CallParticipant $participant): string
    {
        return (string) json_encode([
            'uuid' => $participant->uuid,
            'role' => $participant->role->value,
            'guest' => $participant->isGuest(),
            'avatar' => $participant->avatarUrl(),
            'initials' => $participant->initials(),
        ], JSON_UNESCAPED_SLASHES);
    }

    protected function invitationFor(Call $call, ?User $user, ?string $inviteToken): ?CallParticipant
    {
        if (filled($inviteToken)) {
            $invitation = $call->participants()
                ->where('invite_token', hash('sha256', (string) $inviteToken))
                ->where('status', ParticipantStatus::Invited->value)
                ->first();

            if ($invitation !== null && ($invitation->user_id === null || $invitation->user_id === $user?->id)) {
                return $invitation;
            }
        }

        if ($user === null) {
            return null;
        }

        return $call->participants()
            ->where('status', ParticipantStatus::Invited->value)
            ->whereNull('user_id')
            ->whereRaw('LOWER(email) = ?', [strtolower($user->email)])
            ->first();
    }

    /**
     * Run a media-server call whose failure must not block the state change: the room or the
     * participant may simply be gone already.
     */
    protected function quietly(callable $callback): void
    {
        try {
            $callback();
        } catch (MediaServerException $exception) {
            if (! $exception->isNotFound()) {
                Log::warning('calls.media_server_call_failed', ['error' => $exception->getMessage(), 'status' => $exception->status]);
            }
        }
    }
}

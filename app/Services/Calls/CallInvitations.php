<?php

namespace App\Services\Calls;

use App\Enums\Calls\ParticipantRole;
use App\Enums\Calls\ParticipantStatus;
use App\Mail\Calls\CallInvitationMail;
use App\Models\Calls\Call;
use App\Models\Calls\CallParticipant;
use App\Models\User;
use App\Notifications\Calls\CallInvitationNotification;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Invites people to a call. Tandem users in the organization get a notification and an email;
 * everyone else gets an email with a personal link that lets them skip the waiting room.
 */
class CallInvitations
{
    public function __construct(protected CallMailer $mailer, protected AuditLogger $audit) {}

    /**
     * @param  list<string>  $emails
     * @param  list<int>  $userIds
     * @return Collection<int, CallParticipant>
     */
    public function invite(Call $call, User $inviter, array $emails = [], array $userIds = [], ?string $note = null): Collection
    {
        $invited = collect();
        $organization = $call->organization;

        $users = $userIds === [] ? collect() : $organization->members()->whereIn('users.id', $userIds)->get();
        $byEmail = $emails === [] ? collect() : $organization->members()
            ->whereIn('users.email', array_map('strtolower', $emails))
            ->get()
            ->keyBy(fn (User $user) => strtolower($user->email));

        foreach ($users->concat($byEmail->values())->unique('id') as $user) {
            if ($user->id === $inviter->id) {
                continue;
            }

            $participant = $call->participants()->firstOrNew(['user_id' => $user->id]);

            if (! $participant->exists) {
                $participant->fill([
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => ParticipantRole::Member,
                    'status' => ParticipantStatus::Invited,
                    'invited_by_id' => $inviter->id,
                    'invited_at' => now(),
                ])->save();
            }

            $user->notify(new CallInvitationNotification($call, $inviter));
            $this->mailer->send($organization, $user->email, new CallInvitationMail($call, $inviter, route('meet.show', ['call' => $call->code]), $note));
            $invited->push($participant);
        }

        foreach (array_unique(array_map('strtolower', $emails)) as $email) {
            if ($byEmail->has($email)) {
                continue;
            }

            $token = Str::random(48);
            $participant = $call->participants()->whereNull('user_id')->whereRaw('LOWER(email) = ?', [$email])->first() ?? $call->participants()->make();

            if ($participant->exists && $participant->status !== ParticipantStatus::Invited) {
                // Already asked to join or sat in the call: the plain link is all they need.
                $this->mailer->send($organization, $email, new CallInvitationMail($call, $inviter, route('meet.show', ['call' => $call->code]), $note));
                $invited->push($participant);

                continue;
            }

            $participant->fill([
                'name' => $participant->name ?: Str::before($email, '@'),
                'email' => $email,
                'role' => ParticipantRole::Guest,
                'status' => ParticipantStatus::Invited,
                'invite_token' => hash('sha256', $token),
                'invited_by_id' => $inviter->id,
                'invited_at' => now(),
            ])->save();

            $this->mailer->send($organization, $email, new CallInvitationMail($call, $inviter, route('meet.show', ['call' => $call->code, 'invite' => $token]), $note));
            $invited->push($participant);
        }

        if ($invited->isNotEmpty()) {
            $this->audit->record('call.invited', $organization, $inviter, $call, ['count' => $invited->count()]);
        }

        return $invited;
    }
}

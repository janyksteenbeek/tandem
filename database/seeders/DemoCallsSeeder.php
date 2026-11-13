<?php

namespace Database\Seeders;

use App\Enums\Calls\CallStatus;
use App\Enums\Calls\ParticipantRole;
use App\Enums\Calls\ParticipantStatus;
use App\Models\Calls\Call;
use App\Models\Calls\CallParticipant;
use App\Models\Organization;
use App\Models\Projects\Issue;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Gives the Northwind Labs demo workspace a little meeting history: two finished calls with
 * chat (one about an issue, one with an outside guest) and one that is ready to be joined.
 * No recordings are seeded, because those are real media files only the recorder produces.
 */
class DemoCallsSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()->where('slug', 'northwind-labs')->first();

        if ($organization === null || Call::query()->where('organization_id', $organization->id)->exists()) {
            return;
        }

        $members = $organization->members()->orderBy('users.id')->get();
        $host = $members->firstWhere('email', 'demo@tandem.test') ?? $members->first();

        if ($host === null) {
            return;
        }

        $others = $members->reject(fn (User $user) => $user->id === $host->id)->values();
        $issue = Issue::query()->where('organization_id', $organization->id)->orderBy('id')->first();

        $review = $this->finishedCall($organization, $host, $issue ? $issue->identifier.' · '.$issue->title : 'Release review', 3, 42);
        $reviewPeople = $this->attend($review, $others->take(2));

        if ($issue !== null) {
            $review->links()->create(['linkable_type' => $issue->getMorphClass(), 'linkable_id' => $issue->id, 'created_by_id' => $host->id]);
        }

        $this->chat($review, [
            [$reviewPeople['host'], 'Thanks for joining. Two things: the regression and the rollout order.'],
            [$reviewPeople['members'][0] ?? $reviewPeople['host'], 'The regression is fixed on the branch, I shared the build in the channel.'],
            [$reviewPeople['members'][1] ?? $reviewPeople['host'], 'Then I would roll out to the pilot customers first: https://example.com/rollout-plan'],
            [$reviewPeople['host'], 'Agreed. Pilot on Thursday, everyone else next week.'],
        ]);

        $kickoff = $this->finishedCall($organization, $host, 'Customer kickoff', 1, 28);
        $kickoffPeople = $this->attend($kickoff, $others->take(1));
        $guest = $kickoff->participants()->create([
            'name' => 'Morgan Reyes',
            'email' => 'morgan.reyes@example.com',
            'role' => ParticipantRole::Guest,
            'status' => ParticipantStatus::Left,
            'admitted_at' => $kickoff->started_at,
            'joined_at' => $kickoff->started_at,
            'left_at' => $kickoff->ended_at,
        ]);

        $this->chat($kickoff, [
            [$guest, 'Can you send the onboarding checklist afterwards?'],
            [$kickoffPeople['host'], 'Of course. I will email this chat to you when we are done.'],
        ]);

        Call::create([
            'organization_id' => $organization->id,
            'host_id' => $host->id,
            'title' => 'Weekly sync',
            'status' => CallStatus::Pending,
        ])->participants()->create([
            'user_id' => $host->id,
            'name' => $host->name,
            'email' => $host->email,
            'role' => ParticipantRole::Host,
            'status' => ParticipantStatus::Admitted,
            'admitted_at' => now(),
        ]);
    }

    protected function finishedCall(Organization $organization, User $host, string $title, int $daysAgo, int $minutes): Call
    {
        $startedAt = now()->subDays($daysAgo)->setTime(10, 0);

        return Call::create([
            'organization_id' => $organization->id,
            'host_id' => $host->id,
            'title' => $title,
            'status' => CallStatus::Ended,
            'started_at' => $startedAt,
            'ended_at' => $startedAt->addMinutes($minutes),
        ]);
    }

    /**
     * @param  iterable<int, User>  $members
     * @return array{host: CallParticipant, members: list<CallParticipant>}
     */
    protected function attend(Call $call, iterable $members): array
    {
        $attendee = fn (User $user, ParticipantRole $role) => $call->participants()->create([
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $role,
            'status' => ParticipantStatus::Left,
            'admitted_at' => $call->started_at,
            'joined_at' => $call->started_at,
            'left_at' => $call->ended_at,
        ]);

        $host = $attendee($call->host, ParticipantRole::Host);
        $joined = [];

        foreach ($members as $member) {
            $joined[] = $attendee($member, ParticipantRole::Member);
        }

        $call->forceFill(['peak_participants' => count($joined) + 1])->save();

        return ['host' => $host, 'members' => $joined];
    }

    /**
     * @param  list<array{0: CallParticipant, 1: string}>  $lines
     */
    protected function chat(Call $call, array $lines): void
    {
        foreach ($lines as $index => [$participant, $body]) {
            $message = $call->messages()->create(['participant_id' => $participant->id, 'body' => $body, 'type' => 'text']);
            $message->forceFill(['created_at' => $call->started_at->addMinutes(2 + $index * 3)])->save();
        }

        $call->forceFill(['message_count' => count($lines)])->save();
    }
}

<?php

use App\Models\Calls\Call;
use App\Models\Calls\CallParticipant;
use App\Models\Mail\Conversation;
use App\Models\Mail\Inbox;
use App\Models\Organization;
use App\Models\Projects\Issue;
use App\Models\Projects\Product;
use App\Models\Projects\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, int $id) {
    return $user->id === $id;
});

Broadcast::channel('user.{id}', function (User $user, int $id) {
    return $user->id === $id;
});

Broadcast::channel('inbox.{inboxId}', function (User $user, int $inboxId) {
    $inbox = Inbox::query()->find($inboxId);

    return $inbox !== null && $user->can('view', $inbox);
});

Broadcast::channel('conversation.{uuid}', function (User $user, string $uuid) {
    $conversation = Conversation::query()->where('uuid', $uuid)->first();

    return $conversation !== null && $user->can('view', $conversation);
});

Broadcast::channel('presence.conversation.{uuid}', function (User $user, string $uuid) {
    $conversation = Conversation::query()->where('uuid', $uuid)->first();

    if ($conversation === null || ! $user->can('view', $conversation)) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name, 'initials' => $user->initials(), 'avatar' => $user->avatarUrl()];
});

// --- Projects ---------------------------------------------------------------------------

Broadcast::channel('issue.{uuid}', function (User $user, string $uuid) {
    $issue = Issue::query()->where('uuid', $uuid)->first();

    return $issue !== null && $user->can('view', $issue);
});

Broadcast::channel('presence.issue.{uuid}', function (User $user, string $uuid) {
    $issue = Issue::query()->where('uuid', $uuid)->first();

    if ($issue === null || ! $user->can('view', $issue)) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name, 'initials' => $user->initials(), 'avatar' => $user->avatarUrl()];
});

Broadcast::channel('project.{uuid}', function (User $user, string $uuid) {
    $project = Project::query()->where('uuid', $uuid)->first();

    return $project !== null && $user->can('view', $project);
});

Broadcast::channel('product.{uuid}', function (User $user, string $uuid) {
    $product = Product::query()->where('uuid', $uuid)->first();

    return $product !== null && $user->can('view', $product);
});

Broadcast::channel('team.{teamId}', function (User $user, int $teamId) {
    $team = Team::query()->find($teamId);

    return $team !== null && $user->can('view', $team);
});

Broadcast::channel('organization.{organizationId}.issues', function (User $user, int $organizationId) {
    $organization = Organization::query()->find($organizationId);

    return $organization !== null && $user->belongsToOrganization($organization);
});

// --- Calls ------------------------------------------------------------------------------

// Everyone who was let into a call, signed in or not. Guests authenticate through the
// `call-guest` guard, which only ever yields the participant their session holds for this call.
Broadcast::channel('call.{uuid}', function (User|CallParticipant $actor, string $uuid) {
    $call = Call::query()->where('uuid', $uuid)->first();

    if ($call === null) {
        return false;
    }

    // Always read the participant's current standing: someone removed a moment ago must not
    // keep listening on the strength of an older lookup.
    $participant = $actor instanceof CallParticipant
        ? $call->participants()->whereKey($actor->id)->whereNull('user_id')->first()
        : $call->participants()->where('user_id', $actor->id)->first();

    return $participant !== null && $participant->isInside();
}, ['guards' => ['web', 'call-guest']]);

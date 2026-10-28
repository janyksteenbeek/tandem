<?php

use App\Models\Conversation;
use App\Models\Inbox;
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

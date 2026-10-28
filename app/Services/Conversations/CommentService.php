<?php

namespace App\Services\Conversations;

use App\Enums\ConversationEventType;
use App\Events\Broadcast\CommentAdded;
use App\Events\Broadcast\ConversationUpdated;
use App\Events\Conversations\CommentPosted;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\User;
use App\Notifications\Conversations\MentionedInCommentNotification;
use App\Notifications\Conversations\NewCommentNotification;
use App\Services\Mail\Ingestion\HtmlSanitizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Internal comments live only inside Tandem. They are never handed to a mail provider.
 */
class CommentService
{
    public function __construct(protected HtmlSanitizer $sanitizer, protected ConversationActions $actions) {}

    /**
     * @param  array<int, int>  $mentionIds
     */
    public function post(Conversation $conversation, User $author, string $body, array $mentionIds = []): Comment
    {
        $mentions = $this->resolveMentions($conversation, $mentionIds);

        $comment = DB::transaction(function () use ($conversation, $author, $body, $mentions) {
            $comment = $conversation->comments()->create([
                'user_id' => $author->id,
                'body' => $this->sanitizer->sanitize($body) ?? '',
                'mentions' => $mentions->pluck('id')->all(),
            ]);

            $conversation->followers()->syncWithoutDetaching([$author->id, ...$mentions->pluck('id')->all()]);
            $conversation->forceFill(['comment_count' => $conversation->comments()->count(), 'last_activity_at' => now()])->save();

            $this->actions->record($conversation, ConversationEventType::CommentAdded, $author, ['comment_id' => $comment->id]);

            return $comment;
        });

        $mentions->each(fn (User $user) => $user->notify(new MentionedInCommentNotification($comment)));

        $conversation->followers()
            ->whereKeyNot([$author->id, ...$mentions->pluck('id')->all()])
            ->get()
            ->each(fn (User $follower) => $follower->notify(new NewCommentNotification($comment)));

        CommentPosted::dispatch($conversation, $comment, $author);
        CommentAdded::dispatch($comment, 'created');
        ConversationUpdated::dispatch($conversation->fresh(), 'comment');

        return $comment;
    }

    /**
     * @param  array<int, int>  $mentionIds
     */
    public function update(Comment $comment, string $body, array $mentionIds = []): Comment
    {
        $mentions = $this->resolveMentions($comment->conversation, $mentionIds);

        $comment->update([
            'body' => $this->sanitizer->sanitize($body) ?? '',
            'mentions' => $mentions->pluck('id')->all(),
            'edited_at' => now(),
        ]);

        CommentAdded::dispatch($comment, 'updated');

        return $comment;
    }

    public function delete(Comment $comment): void
    {
        $conversation = $comment->conversation;
        $comment->delete();
        $conversation->forceFill(['comment_count' => $conversation->comments()->count()])->save();

        CommentAdded::dispatch($comment, 'deleted');
        ConversationUpdated::dispatch($conversation->fresh(), 'comment');
    }

    /**
     * Only people who can actually see the conversation may be mentioned.
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, User>
     */
    protected function resolveMentions(Conversation $conversation, array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return User::query()->whereKey(array_unique($ids))->get()
            ->filter(fn (User $user) => $user->can('view', $conversation))
            ->values();
    }
}

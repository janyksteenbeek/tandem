<?php

namespace App\Services\Projects;

use App\Enums\Projects\IssueActivityType;
use App\Events\Projects\Broadcast\IssueCommentAdded;
use App\Events\Projects\IssueCommentPosted;
use App\Models\Projects\Issue;
use App\Models\Projects\IssueComment;
use App\Models\User;
use App\Notifications\Projects\IssueCommentNotification;
use App\Notifications\Projects\IssueMentionedNotification;
use App\Services\Mail\Ingestion\HtmlSanitizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class IssueCommentService
{
    public function __construct(protected HtmlSanitizer $sanitizer, protected IssueActions $actions) {}

    /**
     * @param  array<int, int>  $mentionIds
     */
    public function post(Issue $issue, User $author, string $html, array $mentionIds = [], ?IssueComment $parent = null): IssueComment
    {
        $clean = $this->sanitizer->sanitize($html) ?? '';
        $mentioned = $this->mentionable($issue, $mentionIds);

        $comment = DB::transaction(function () use ($issue, $author, $clean, $mentioned, $parent) {
            $comment = $issue->comments()->create([
                'user_id' => $author->id,
                'parent_id' => $parent?->id,
                'body_html' => $clean,
                'body_text' => Str::limit(trim(html_entity_decode(strip_tags($clean))), 60000, ''),
                'mentions' => $mentioned->pluck('id')->all(),
            ]);

            $issue->followers()->syncWithoutDetaching([$author->id, ...$mentioned->pluck('id')->all()]);
            $issue->forceFill(['comment_count' => $issue->comments()->count(), 'last_activity_at' => now()])->save();
            $this->actions->record($issue, IssueActivityType::CommentAdded, $author, ['comment_uuid' => $comment->uuid]);

            return $comment;
        });

        IssueCommentPosted::dispatch($issue, $comment, $author);
        IssueCommentAdded::dispatch($comment, 'created');

        if ($mentioned->isNotEmpty()) {
            Notification::send($mentioned->reject(fn (User $u) => $u->id === $author->id), new IssueMentionedNotification($issue, $comment, $author));
        }

        $followers = $issue->followers()->whereKeyNot($author->id)->whereNotIn('users.id', $mentioned->pluck('id'))->get();

        if ($followers->isNotEmpty()) {
            Notification::send($followers, new IssueCommentNotification($issue, $comment, $author));
        }

        $this->actions->broadcast($issue, 'comments');

        return $comment;
    }

    /**
     * @param  array<int, int>  $mentionIds
     */
    public function update(IssueComment $comment, string $html, array $mentionIds = []): IssueComment
    {
        $clean = $this->sanitizer->sanitize($html) ?? '';
        $mentioned = $this->mentionable($comment->issue, $mentionIds);

        $comment->forceFill([
            'body_html' => $clean,
            'body_text' => Str::limit(trim(html_entity_decode(strip_tags($clean))), 60000, ''),
            'mentions' => $mentioned->pluck('id')->all(),
            'edited_at' => now(),
        ])->save();

        IssueCommentAdded::dispatch($comment, 'updated');

        return $comment;
    }

    public function delete(IssueComment $comment): void
    {
        $issue = $comment->issue;
        $comment->delete();
        $issue->forceFill(['comment_count' => $issue->comments()->count()])->save();
        IssueCommentAdded::dispatch($comment, 'deleted');
        $this->actions->broadcast($issue, 'comments');
    }

    public function react(IssueComment $comment, User $user, string $emoji, bool $on): IssueComment
    {
        $emoji = Str::limit(trim($emoji), 32, '');

        if ($on) {
            $comment->reactions()->firstOrCreate(['user_id' => $user->id, 'emoji' => $emoji]);
        } else {
            $comment->reactions()->where('user_id', $user->id)->where('emoji', $emoji)->delete();
        }

        $comment->unsetRelation('reactions');
        IssueCommentAdded::dispatch($comment, 'updated');

        return $comment;
    }

    /**
     * Only people who can see the issue can be mentioned on it.
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, User>
     */
    protected function mentionable(Issue $issue, array $ids)
    {
        if ($ids === []) {
            return collect();
        }

        return User::query()->whereIn('id', $ids)->get()->filter(fn (User $user) => $user->can('view', $issue))->values();
    }
}

<?php

namespace App\Notifications\Projects;

use App\Enums\PushTopic;
use App\Models\Projects\Issue;
use App\Models\Projects\IssueComment;
use App\Models\User;
use Illuminate\Support\Str;

class IssueCommentNotification extends IssueNotification
{
    public function __construct(Issue $issue, public IssueComment $comment, ?User $actor = null)
    {
        parent::__construct($issue, $actor);
    }

    protected function kind(): string
    {
        return 'issue_comment';
    }

    protected function title(): string
    {
        return $this->actorName().' commented on '.$this->issue->identifier;
    }

    protected function body(): ?string
    {
        return Str::limit((string) $this->comment->body_text, 140);
    }

    protected function topic(): PushTopic
    {
        return PushTopic::Follows;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return parent::toArray($notifiable) + ['comment_uuid' => $this->comment->uuid];
    }
}

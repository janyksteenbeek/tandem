<?php

namespace App\Services\Projects;

use App\Enums\Mail\ConversationEventType;
use App\Enums\Projects\IssueActivityType;
use App\Enums\Projects\IssueLinkKind;
use App\Enums\Projects\SharedContentKind;
use App\Events\Mail\Broadcast\ConversationUpdated;
use App\Events\Projects\ConversationLinkedToIssue;
use App\Http\Resources\Projects\IssueListItemResource;
use App\Http\Resources\UserSummaryResource;
use App\Models\Automations\AutomationRule;
use App\Models\Mail\Attachment;
use App\Models\Mail\Conversation;
use App\Models\Mail\Message;
use App\Models\Projects\Issue;
use App\Models\Projects\IssueConversationLink;
use App\Models\Projects\IssueSharedContent;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Mail\Ingestion\HtmlSanitizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The bridge between mail and issues. Linking records a reference and nothing more: a user only
 * sees a linked conversation when they can open it themselves. Content only crosses over through
 * the explicit share methods, which authorise on both sides and are audited.
 */
class ConversationIssueLinks
{
    public function __construct(protected IssueActions $actions, protected HtmlSanitizer $sanitizer, protected AuditLogger $audit, protected IssueAttachments $attachments) {}

    public function link(Issue $issue, Conversation $conversation, ?User $actor, ?string $note = null, IssueLinkKind $kind = IssueLinkKind::Linked, ?AutomationRule $rule = null): IssueConversationLink
    {
        if ($conversation->organization_id !== $issue->organization_id) {
            throw new AuthorizationException('Conversations can only be linked to issues of the same organization.');
        }

        $existing = IssueConversationLink::query()->where('issue_id', $issue->id)->where('conversation_id', $conversation->id)->first();

        if ($existing) {
            return $existing;
        }

        $link = DB::transaction(function () use ($issue, $conversation, $actor, $note, $kind, $rule) {
            $link = IssueConversationLink::query()->create([
                'issue_id' => $issue->id,
                'conversation_id' => $conversation->id,
                'kind' => $kind,
                'linked_by_id' => $actor?->id,
                'automation_rule_id' => $rule?->id,
                'note' => $note,
            ]);

            $issue->forceFill(['link_count' => $issue->conversationLinks()->count(), 'last_activity_at' => now()])->save();

            // No subject or content in the activity: the issue timeline must not leak mail.
            $this->actions->record($issue, IssueActivityType::ConversationLinked, $actor, ['conversation_uuid' => $conversation->uuid, 'kind' => $kind->value, 'automation_rule_id' => $rule?->id]);

            $conversation->events()->create([
                'type' => ConversationEventType::IssueLinked,
                'actor_id' => $actor?->id,
                'payload' => ['issue_identifier' => $issue->identifier, 'issue_uuid' => $issue->uuid, 'issue_title' => $issue->title, 'kind' => $kind->value],
            ]);

            return $link;
        });

        ConversationLinkedToIssue::dispatch($issue, $conversation, $actor);
        $this->actions->broadcast($issue, 'links');
        ConversationUpdated::dispatch($conversation, 'issue_link');

        return $link;
    }

    public function unlink(Issue $issue, Conversation $conversation, ?User $actor): void
    {
        $link = IssueConversationLink::query()->where('issue_id', $issue->id)->where('conversation_id', $conversation->id)->first();

        if (! $link) {
            return;
        }

        DB::transaction(function () use ($link, $issue, $conversation, $actor) {
            $link->delete();
            $issue->forceFill(['link_count' => $issue->conversationLinks()->count()])->save();
            $this->actions->record($issue, IssueActivityType::ConversationUnlinked, $actor, ['conversation_uuid' => $conversation->uuid]);

            $conversation->events()->create([
                'type' => ConversationEventType::IssueUnlinked,
                'actor_id' => $actor?->id,
                'payload' => ['issue_identifier' => $issue->identifier, 'issue_uuid' => $issue->uuid],
            ]);
        });

        $this->actions->broadcast($issue, 'links');
        ConversationUpdated::dispatch($conversation, 'issue_link');
    }

    /**
     * Linked conversations the viewer may open, plus how many they may not.
     *
     * @return array{conversations: array<int, array<string, mixed>>, hidden_count: int}
     */
    public function visibleLinksFor(Issue $issue, User $viewer): array
    {
        $links = $issue->conversationLinks()->with(['conversation.inbox', 'conversation.shares.inbox', 'conversation.inbox.mailAccounts', 'linkedBy'])->get();
        $visible = [];
        $hidden = 0;

        foreach ($links as $link) {
            $conversation = $link->conversation;

            if (! $viewer->can('view', $conversation)) {
                $hidden++;

                continue;
            }

            $visible[] = [
                'uuid' => $conversation->uuid,
                'inbox_uuid' => $conversation->inbox->uuid,
                'inbox_name' => $conversation->inbox->name,
                'inbox_color' => $conversation->inbox->color,
                'subject' => $conversation->subject,
                'snippet' => $conversation->snippet,
                'status' => $conversation->status->value,
                'counterpart' => collect($conversation->participants ?? [])->first(),
                'last_activity_at' => $conversation->last_activity_at?->toISOString(),
                'kind' => $link->kind->value,
                'note' => $link->note,
                'linked_by' => $link->linkedBy ? UserSummaryResource::make($link->linkedBy)->resolve() : null,
                'linked_at' => $link->created_at?->toISOString(),
            ];
        }

        return ['conversations' => $visible, 'hidden_count' => $hidden];
    }

    /**
     * Issues linked to a conversation that the viewer may see.
     *
     * @return array<int, array<string, mixed>>
     */
    public function issuesFor(Conversation $conversation, User $viewer): array
    {
        $issues = Issue::query()->visibleTo($viewer)->with(IssueQuery::EAGER)
            ->whereIn('id', IssueConversationLink::query()->where('conversation_id', $conversation->id)->select('issue_id'))
            ->orderByDesc('last_activity_at')
            ->get();

        return IssueListItemResource::collection($issues)->resolve();
    }

    /**
     * Copy a sanitised excerpt of a message into the issue. Both sides are authorised by the caller.
     */
    public function shareExcerpt(Issue $issue, Message $message, User $actor, ?string $selectionHtml = null): IssueSharedContent
    {
        $source = $selectionHtml ?: ($message->html_body ?: nl2br(e((string) $message->text_body)));
        $clean = $this->sanitizer->sanitize($source) ?? '';
        $clean = preg_replace('/<img[^>]+src=["\']cid:[^"\']*["\'][^>]*>/i', '', $clean) ?? $clean;

        $content = DB::transaction(function () use ($issue, $message, $actor, $clean) {
            $content = IssueSharedContent::query()->create([
                'issue_id' => $issue->id,
                'conversation_id' => $message->conversation_id,
                'message_id' => $message->id,
                'shared_by_id' => $actor->id,
                'kind' => SharedContentKind::Excerpt,
                'body_html' => $clean,
            ]);

            $this->actions->record($issue, IssueActivityType::ContentShared, $actor, ['kind' => 'excerpt', 'conversation_uuid' => $message->conversation->uuid, 'message_uuid' => $message->uuid]);

            return $content;
        });

        $this->audit->record('issue.content_shared', $issue->organization, $actor, $issue, ['kind' => 'excerpt', 'message_uuid' => $message->uuid]);
        $this->actions->broadcast($issue, 'shared_content');

        return $content;
    }

    public function shareAttachment(Issue $issue, Attachment $attachment, User $actor): IssueSharedContent
    {
        $copy = $this->attachments->copyFromMail($issue, $attachment, $actor);

        $content = DB::transaction(function () use ($issue, $attachment, $actor, $copy) {
            $content = IssueSharedContent::query()->create([
                'issue_id' => $issue->id,
                'conversation_id' => $attachment->message->conversation_id,
                'message_id' => $attachment->message_id,
                'shared_by_id' => $actor->id,
                'kind' => SharedContentKind::Attachment,
                'issue_attachment_id' => $copy->id,
            ]);

            $this->actions->record($issue, IssueActivityType::ContentShared, $actor, ['kind' => 'attachment', 'filename' => $attachment->filename, 'conversation_uuid' => $attachment->message->conversation->uuid]);

            return $content;
        });

        $this->audit->record('issue.content_shared', $issue->organization, $actor, $issue, ['kind' => 'attachment', 'attachment_uuid' => $attachment->uuid]);
        $this->actions->broadcast($issue, 'shared_content');

        return $content;
    }
}

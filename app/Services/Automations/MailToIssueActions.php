<?php

namespace App\Services\Automations;

use App\Enums\Automations\AutomationTrigger;
use App\Enums\Projects\IssueLinkKind;
use App\Enums\Projects\IssuePriority;
use App\Models\Automations\AutomationRule;
use App\Models\Mail\Conversation;
use App\Models\Mail\Message;
use App\Models\Projects\Issue;
use App\Models\Projects\IssueConversationLink;
use App\Models\Projects\Product;
use App\Models\Projects\Project;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Projects\IssueCreatedFromConversationNotification;
use App\Services\Projects\ConversationIssueLinks;
use App\Services\Projects\IssueActions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * The two conversation actions that reach into projects: create an issue from the
 * conversation, or link one. Both are idempotent per (rule, conversation).
 */
class MailToIssueActions
{
    public function __construct(protected IssueActions $issues, protected ConversationIssueLinks $links, protected TemplateRenderer $renderer, protected TriggerEchoGuard $echoes) {}

    /**
     * @param  array<string, mixed>  $params
     * @param  array{message?: Message|null}  $context
     * @return array<string, mixed>
     */
    public function createIssue(AutomationRule $rule, Conversation $conversation, array $params, array $context): array
    {
        $organization = $conversation->organization;

        if (! $organization) {
            return ['status' => 'skipped', 'reason' => 'no_organization'];
        }

        $existing = IssueConversationLink::query()->where('conversation_id', $conversation->id)->where('automation_rule_id', $rule->id)->where('kind', IssueLinkKind::CreatedFrom->value)->first();

        if ($existing) {
            return ['status' => 'skipped', 'reason' => 'already_created', 'issue_id' => $existing->issue_id];
        }

        $product = is_numeric($params['product_id'] ?? null) ? Product::query()->where('organization_id', $organization->id)->whereKey((int) $params['product_id'])->first() : null;
        $project = is_numeric($params['project_id'] ?? null) ? Project::query()->where('organization_id', $organization->id)->whereKey((int) $params['project_id'])->first() : null;
        $owner = is_numeric($params['owner_id'] ?? null) ? $organization->members()->whereKey((int) $params['owner_id'])->first() : null;

        $teamIds = collect(is_array($params['team_ids'] ?? null) ? $params['team_ids'] : [])->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id);
        $teams = Team::query()->where('organization_id', $organization->id)->whereIn('id', $teamIds->all())->get()->map(fn (Team $team) => ['team' => $team])->all();

        $template = is_string($params['title_template'] ?? null) && trim($params['title_template']) !== '' ? trim($params['title_template']) : '{{ conversation.subject }}';
        $title = Str::limit(trim(strip_tags($this->renderer->render($template, $conversation, $context))) ?: ($conversation->subject ?: 'Untitled issue'), 255, '');

        $this->echoes->expect($conversation, AutomationTrigger::ConversationReceived);

        $issue = $this->issues->create($organization, [
            'title' => $title,
            'product' => $product,
            'project' => $project,
            'priority' => IssuePriority::tryFromKey(is_string($params['priority'] ?? null) ? $params['priority'] : null),
            'owner' => $owner instanceof User ? $owner : null,
            'labels' => collect(is_array($params['label_ids'] ?? null) ? $params['label_ids'] : [])->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->all(),
        ], null, $teams, 'automation');

        $this->echoes->consume($conversation, AutomationTrigger::ConversationReceived);

        $this->links->link($issue, $conversation, null, null, IssueLinkKind::CreatedFrom, $rule);

        if (! empty($params['include_excerpt'])) {
            $message = ($context['message'] ?? null) instanceof Message ? $context['message'] : $conversation->messages()->where('direction', 'inbound')->orderByDesc('sent_at')->first();
            $sharer = $rule->created_by_id ? User::query()->whereKey($rule->created_by_id)->first() : null;

            if ($message && $sharer && $sharer->can('view', $conversation)) {
                $this->links->shareExcerpt($issue, $message, $sharer);
            }
        }

        $recipients = collect([$owner instanceof User ? $owner : null])->filter();

        foreach ($teams as $entry) {
            $recipients = $recipients->merge($entry['team']->members()->wherePivot('role', 'lead')->get());
        }

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients->unique('id'), new IssueCreatedFromConversationNotification($issue));
        }

        return ['status' => 'done', 'issue_id' => $issue->id, 'identifier' => $issue->identifier];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function linkIssue(AutomationRule $rule, Conversation $conversation, array $params): array
    {
        $organization = $conversation->organization;

        if (! $organization) {
            return ['status' => 'skipped', 'reason' => 'no_organization'];
        }

        $mode = is_string($params['mode'] ?? null) ? $params['mode'] : 'identifier';
        $identifier = null;

        if ($mode === 'subject_match') {
            if (preg_match('/\b([A-Z][A-Z0-9]{1,9}-\d+)\b/i', (string) $conversation->subject, $m)) {
                $identifier = strtoupper($m[1]);
            }
        } else {
            $identifier = is_string($params['identifier'] ?? null) ? strtoupper(trim($params['identifier'])) : null;
        }

        if (! $identifier) {
            return ['status' => 'skipped', 'reason' => 'no_identifier'];
        }

        $issue = Issue::query()->where('organization_id', $organization->id)->where('identifier', $identifier)->first();

        if (! $issue) {
            return ['status' => 'skipped', 'reason' => 'issue_not_found', 'identifier' => $identifier];
        }

        if ($issue->conversationLinks()->where('conversation_id', $conversation->id)->exists()) {
            return ['status' => 'skipped', 'reason' => 'already_linked', 'identifier' => $identifier];
        }

        $this->links->link($issue, $conversation, null, null, IssueLinkKind::Linked, $rule);

        return ['status' => 'done', 'issue_id' => $issue->id, 'identifier' => $identifier];
    }
}

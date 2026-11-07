<?php

namespace App\Http\Controllers\Mail;

use App\Enums\Projects\IssueLinkKind;
use App\Enums\Projects\IssuePriority;
use App\Http\Controllers\Controller;
use App\Http\Resources\Mail\ConversationResource;
use App\Http\Resources\Projects\IssueListItemResource;
use App\Models\Mail\Attachment;
use App\Models\Mail\Conversation;
use App\Models\Mail\Message;
use App\Models\Projects\Issue;
use App\Models\Projects\Product;
use App\Models\Projects\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\Projects\ConversationIssueLinks;
use App\Services\Projects\IssueActions;
use App\Services\Projects\IssueQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The mail side of the bridge: list, link, create and share issues from a conversation. Every
 * action checks the conversation policy first and the issue policy second.
 */
class ConversationIssueController extends Controller
{
    public function __construct(protected ConversationIssueLinks $links, protected IssueActions $issues) {}

    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        return response()->json(['issues' => $this->links->issuesFor($conversation, $request->user())]);
    }

    /**
     * Link an existing issue (`identifier`) or create a new one (`title` + properties).
     */
    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);
        $user = $request->user();
        $organization = $conversation->organization;

        abort_if($organization === null, 422, 'Only conversations in a shared inbox can be linked to issues.');

        $data = $request->validate([
            'identifier' => ['nullable', 'string', 'max:24'],
            'note' => ['nullable', 'string', 'max:500'],
            'title' => ['required_without:identifier', 'nullable', 'string', 'max:255'],
            'description_html' => ['nullable', 'string', 'max:200000'],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('organization_id', $organization->id)],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('organization_id', $organization->id)],
            'priority' => ['nullable', 'string'],
            'owner_id' => ['nullable', 'integer', Rule::exists('organization_members', 'user_id')->where('organization_id', $organization->id)],
            'lead_team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')->where('organization_id', $organization->id)],
            'team_ids' => ['nullable', 'array', 'max:20'],
            'team_ids.*' => ['integer', Rule::exists('teams', 'id')->where('organization_id', $organization->id)],
            'module_ids' => ['nullable', 'array'],
            'module_ids.*' => ['integer'],
            'label_ids' => ['nullable', 'array'],
            'label_ids.*' => ['integer'],
            'include_excerpt' => ['nullable', 'boolean'],
            'attachment_uuids' => ['nullable', 'array', 'max:20'],
            'attachment_uuids.*' => ['string', 'uuid'],
        ]);

        if (filled($data['identifier'] ?? null)) {
            $issue = Issue::query()->where('organization_id', $organization->id)->where('identifier', strtoupper($data['identifier']))->firstOrFail();
            $this->authorize('linkConversation', $issue);
            $this->links->link($issue, $conversation, $user, $data['note'] ?? null);
        } else {
            $this->authorize('create', [Issue::class, $organization]);

            $product = isset($data['product_id']) ? Product::query()->whereKey($data['product_id'])->first() : null;
            $project = isset($data['project_id']) ? Project::query()->whereKey($data['project_id'])->first() : null;

            if ($project) {
                $this->authorize('contribute', $project);
            }

            /** @var array<int, int> $teamIds */
            $teamIds = $data['team_ids'] ?? [];
            $teams = collect($teamIds)->map(fn (int $id) => ['team' => Team::query()->whereKey($id)->firstOrFail()])->all();

            $issue = $this->issues->create($organization, [
                'title' => $data['title'] ?? ($conversation->subject ?: 'Untitled issue'),
                'description_html' => $data['description_html'] ?? null,
                'product' => $product,
                'project' => $project,
                'priority' => IssuePriority::tryFromKey($data['priority'] ?? null),
                'owner' => isset($data['owner_id']) ? User::query()->whereKey($data['owner_id'])->first() : null,
                'lead_team' => isset($data['lead_team_id']) ? Team::query()->whereKey($data['lead_team_id'])->first() : null,
                'labels' => $data['label_ids'] ?? [],
                'modules' => $data['module_ids'] ?? [],
            ], $user, $teams, 'email');

            $this->links->link($issue, $conversation, $user, $data['note'] ?? null, IssueLinkKind::CreatedFrom);

            if (! empty($data['include_excerpt'])) {
                $message = $conversation->messages()->where('direction', 'inbound')->orderByDesc('sent_at')->first() ?? $conversation->messages()->orderByDesc('sent_at')->first();

                if ($message) {
                    $this->links->shareExcerpt($issue, $message, $user);
                }
            }

            foreach ($data['attachment_uuids'] ?? [] as $uuid) {
                $attachment = Attachment::query()->where('uuid', $uuid)->whereHas('message', fn ($q) => $q->where('conversation_id', $conversation->id))->first();

                if ($attachment) {
                    $this->links->shareAttachment($issue, $attachment, $user);
                }
            }
        }

        return response()->json([
            'issue' => IssueListItemResource::make($issue->fresh(IssueQuery::EAGER))->resolve(),
            'conversation' => ConversationResource::make($conversation->fresh())->resolve(),
        ], 201);
    }

    /**
     * Copy an excerpt or an attachment of this conversation into a linked issue.
     */
    public function share(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);
        $user = $request->user();

        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:24'],
            'message_uuid' => ['nullable', 'string', 'uuid'],
            'selection_html' => ['nullable', 'string', 'max:200000'],
            'attachment_uuids' => ['nullable', 'array', 'max:20'],
            'attachment_uuids.*' => ['string', 'uuid'],
        ]);

        $issue = Issue::query()->where('organization_id', (int) $conversation->organization_id)->where('identifier', strtoupper($data['identifier']))->firstOrFail();
        $this->authorize('shareContent', $issue);

        if (! empty($data['message_uuid'])) {
            $message = Message::query()->where('uuid', $data['message_uuid'])->where('conversation_id', $conversation->id)->firstOrFail();
            $this->links->shareExcerpt($issue, $message, $user, $data['selection_html'] ?? null);
        }

        foreach ($data['attachment_uuids'] ?? [] as $uuid) {
            $attachment = Attachment::query()->where('uuid', $uuid)->whereHas('message', fn ($q) => $q->where('conversation_id', $conversation->id))->firstOrFail();
            $this->links->shareAttachment($issue, $attachment, $user);
        }

        if (! $issue->conversationLinks()->where('conversation_id', $conversation->id)->exists()) {
            $this->links->link($issue, $conversation, $user);
        }

        return response()->json(['issue' => IssueListItemResource::make($issue->fresh(IssueQuery::EAGER))->resolve()]);
    }

    public function destroy(Request $request, Conversation $conversation, string $identifier): JsonResponse
    {
        $this->authorize('view', $conversation);
        $issue = Issue::query()->where('organization_id', (int) $conversation->organization_id)->where('identifier', strtoupper($identifier))->firstOrFail();
        $this->authorize('linkConversation', $issue);

        $this->links->unlink($issue, $conversation, $request->user());

        return response()->json(['conversation' => ConversationResource::make($conversation->fresh())->resolve()]);
    }
}

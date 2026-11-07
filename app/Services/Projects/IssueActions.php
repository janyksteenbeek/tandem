<?php

namespace App\Services\Projects;

use App\Enums\Projects\IssueActivityType;
use App\Enums\Projects\IssuePriority;
use App\Enums\Projects\IssueRelationType;
use App\Enums\Projects\StatusCategory;
use App\Events\Projects\Broadcast\IssueActivityAdded;
use App\Events\Projects\Broadcast\IssueUpdated;
use App\Events\Projects\Broadcast\WorkstreamUpdated;
use App\Events\Projects\IssueAssigned;
use App\Events\Projects\IssueCompleted;
use App\Events\Projects\IssueCreated;
use App\Events\Projects\IssueMoved;
use App\Events\Projects\IssueOwnerChanged;
use App\Events\Projects\IssueStatusChanged;
use App\Events\Projects\TeamAddedToIssue;
use App\Events\Projects\TeamRemovedFromIssue;
use App\Events\Projects\WorkstreamStatusChanged;
use App\Exceptions\Projects\CompletionBlockedException;
use App\Models\Organization;
use App\Models\Projects\Cycle;
use App\Models\Projects\Issue;
use App\Models\Projects\IssueActivity;
use App\Models\Projects\IssueLabel;
use App\Models\Projects\IssueRelation;
use App\Models\Projects\IssueWorkstream;
use App\Models\Projects\Milestone;
use App\Models\Projects\Product;
use App\Models\Projects\Project;
use App\Models\Projects\WorkflowStatus;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Projects\IssueCompletionConflictNotification;
use App\Notifications\Projects\IssueOwnerAssignedNotification;
use App\Notifications\Projects\IssueReadyToCompleteNotification;
use App\Notifications\Projects\IssueStatusChangedNotification;
use App\Notifications\Projects\TeamAddedToIssueNotification;
use App\Notifications\Projects\WorkstreamAssignedNotification;
use App\Services\Audit\AuditLogger;
use App\Services\Mail\Ingestion\HtmlSanitizer;
use App\Services\Projects\Data\CompletionReadiness;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Every change to an issue goes through here: it writes the activity row, dispatches the domain
 * event (for automations), broadcasts the realtime update and notifies the people involved.
 * Mirrors ConversationActions for mail.
 */
class IssueActions
{
    public function __construct(
        protected IssueIdentifiers $identifiers,
        protected WorkflowActions $workflows,
        protected IssueCompletion $completion,
        protected DependencyGraph $dependencies,
        protected HtmlSanitizer $sanitizer,
        protected AuditLogger $audit,
    ) {}

    /**
     * Attributes: title, description_html, product, project, milestone, parent, priority, owner, lead_team,
     * status, labels (ids), modules (ids), estimate, start_date, due_date. Teams: team, responsibility,
     * is_required, assignees (ids), cycle.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $teams
     */
    public function create(Organization $organization, array $attributes, ?User $actor, array $teams = [], string $source = 'manual'): Issue
    {
        $product = $attributes['product'] ?? null;
        $project = $attributes['project'] ?? null;

        if ($project && $product && $project->product_id !== null && $project->product_id !== $product->id) {
            throw ValidationException::withMessages(['project_id' => 'That project belongs to another product.']);
        }

        if ($project && $product === null && $project->product_id !== null) {
            $product = $project->product;
        }

        $workflow = $this->workflows->issueWorkflowFor($organization, $product);
        $status = $attributes['status'] ?? $workflow->defaultStatus();

        if ($status->workflow_id !== $workflow->id) {
            throw ValidationException::withMessages(['status_id' => 'That status does not belong to this workflow.']);
        }

        $issue = DB::transaction(function () use ($organization, $attributes, $actor, $teams, $product, $project, $status, $source) {
            $identity = $this->identifiers->next($organization, $product);
            $description = $this->describe($attributes['description_html'] ?? null);

            $issue = Issue::query()->create([
                'organization_id' => $organization->id,
                'product_id' => $product?->id,
                'project_id' => $project?->id,
                'milestone_id' => ($attributes['milestone'] ?? null)?->id,
                'parent_id' => ($attributes['parent'] ?? null)?->id,
                'prefix' => $identity['prefix'],
                'number' => $identity['number'],
                'identifier' => $identity['identifier'],
                'title' => trim($attributes['title']),
                'description_html' => $description['html'],
                'description_text' => $description['text'],
                'status_id' => $status->id,
                'status_category' => $status->category,
                'priority' => $attributes['priority'] ?? IssuePriority::None,
                'owner_id' => ($attributes['owner'] ?? null)?->id,
                'lead_team_id' => ($attributes['lead_team'] ?? null)?->id,
                'created_by_id' => $actor?->id,
                'estimate' => $attributes['estimate'] ?? null,
                'start_date' => $attributes['start_date'] ?? null,
                'due_date' => $attributes['due_date'] ?? null,
                'started_at' => $status->category === StatusCategory::Started ? now() : null,
                'last_activity_at' => now(),
            ]);

            if (! empty($attributes['labels'])) {
                $issue->labels()->sync($this->labelIds($organization, $attributes['labels']));
            }

            if (! empty($attributes['modules'])) {
                $issue->modules()->sync($this->moduleIds($product, $attributes['modules']));
            }

            foreach (array_filter([$actor, $attributes['owner'] ?? null]) as $follower) {
                $issue->followers()->syncWithoutDetaching([$follower->id]);
            }

            $this->record($issue, IssueActivityType::Created, $actor, ['source' => $source, 'status_id' => $status->id]);

            foreach ($teams as $entry) {
                $this->addTeam($issue, $entry['team'], $actor, $entry, recompute: false, broadcast: false);
            }

            if ($issue->parent_id) {
                $this->syncParentCounters($issue->parent_id);
            }

            if (($attributes['owner'] ?? null) && $actor?->id !== $attributes['owner']->id) {
                $attributes['owner']->notify(new IssueOwnerAssignedNotification($issue, $actor));
            }

            return $issue;
        }, attempts: 3);

        if ($teams !== []) {
            $this->completion->recompute($issue, $this, $actor, 'created');
        }

        IssueCreated::dispatch($issue, $actor, $source);
        $this->broadcast($issue, 'created');

        return $issue->refresh();
    }

    /**
     * @param  array{title?: string, description_html?: string|null, estimate?: float|string|null, start_date?: string|null, due_date?: string|null, labels?: array<int, int>|null, modules?: array<int, int>|null}  $attributes
     */
    public function update(Issue $issue, array $attributes, ?User $actor): Issue
    {
        $changes = [];

        DB::transaction(function () use ($issue, $attributes, $actor, &$changes) {
            if (array_key_exists('title', $attributes) && trim((string) $attributes['title']) !== $issue->title) {
                $changes['title'] = ['from' => $issue->title, 'to' => trim((string) $attributes['title'])];
                $issue->title = trim((string) $attributes['title']);
            }

            if (array_key_exists('description_html', $attributes)) {
                $description = $this->describe($attributes['description_html']);

                if ($description['html'] !== $issue->description_html) {
                    $changes['description'] = true;
                    $issue->description_html = $description['html'];
                    $issue->description_text = $description['text'];
                }
            }

            if (array_key_exists('estimate', $attributes) && (string) $attributes['estimate'] !== (string) $issue->estimate) {
                $this->record($issue, IssueActivityType::EstimateChanged, $actor, ['from' => $issue->estimate, 'to' => $attributes['estimate']]);
                $issue->estimate = $attributes['estimate'] === null || $attributes['estimate'] === '' ? null : (string) $attributes['estimate'];
            }

            $dates = [];

            foreach (['start_date', 'due_date'] as $field) {
                if (array_key_exists($field, $attributes)) {
                    $current = $issue->{$field}?->toDateString();
                    $next = $attributes[$field] ? Str::substr((string) $attributes[$field], 0, 10) : null;

                    if ($current !== $next) {
                        $dates[$field] = ['from' => $current, 'to' => $next];
                        $issue->setAttribute($field, $next);
                    }
                }
            }

            if ($dates !== []) {
                $this->record($issue, IssueActivityType::DatesChanged, $actor, $dates);
            }

            if (array_key_exists('labels', $attributes) && $attributes['labels'] !== null) {
                $this->syncLabels($issue, $attributes['labels'], $actor);
            }

            if (array_key_exists('modules', $attributes) && $attributes['modules'] !== null) {
                $this->syncModules($issue, $attributes['modules'], $actor);
            }

            if ($changes !== []) {
                $this->record($issue, IssueActivityType::Updated, $actor, ['changes' => $changes]);
            }

            $issue->last_activity_at = now();
            $issue->save();
        });

        $this->broadcast($issue, 'updated');

        return $issue;
    }

    /**
     * Change the overall status. Manual completion requires every required workstream to be
     * finished unless the actor explicitly overrides (which is audited). Automatic and automation
     * sources never bypass that rule.
     */
    public function setStatus(Issue $issue, WorkflowStatus $to, ?User $actor, string $source = 'manual', bool $override = false, ?string $reason = null): Issue
    {
        $workflow = $this->workflows->issueWorkflowFor($issue->organization, $issue->product);

        if ($to->workflow_id !== $workflow->id) {
            throw ValidationException::withMessages(['status_id' => 'That status does not belong to this issue\'s workflow.']);
        }

        $from = $issue->status;

        if ($from->id === $to->id) {
            return $issue;
        }

        if (! $this->workflows->transitionAllowed($workflow, $from, $to)) {
            throw ValidationException::withMessages(['status_id' => "Moving from {$from->name} to {$to->name} is not allowed by the workflow."]);
        }

        if ($to->category === StatusCategory::Completed && $issue->workstreams_required_count > 0 && ! $issue->is_ready_to_complete && $source !== 'auto') {
            $readiness = $this->completion->evaluate($issue);

            if (! $readiness->ready) {
                if (! $override || $source === 'automation') {
                    throw new CompletionBlockedException($readiness);
                }
            }
        }

        DB::transaction(function () use ($issue, $from, $to, $actor, $source, $override, $reason) {
            $now = now();
            $wasTerminal = $from->category->isTerminal();

            $issue->forceFill([
                'status_id' => $to->id,
                'status_category' => $to->category,
                'started_at' => $issue->started_at ?? ($to->category->rank() >= StatusCategory::Started->rank() ? $now : null),
                'completed_at' => $to->category === StatusCategory::Completed ? $now : null,
                'canceled_at' => $to->category === StatusCategory::Canceled ? $now : null,
                'status_overridden_at' => $override ? $now : $issue->status_overridden_at,
                'last_activity_at' => $now,
            ])->save();

            $payload = [
                'from_id' => $from->id,
                'to_id' => $to->id,
                'from' => $from->name,
                'to' => $to->name,
                'from_category' => $from->category->value,
                'to_category' => $to->category->value,
                'source' => $source,
                'reopened' => $wasTerminal && $to->category->isOpen(),
            ];

            $this->record($issue, $override ? IssueActivityType::StatusOverridden : IssueActivityType::StatusChanged, $actor, $override ? $payload + ['reason' => $reason, 'readiness' => $this->completion->evaluate($issue)->toArray()] : $payload);

            if ($override) {
                $this->audit->record('issue.completion_overridden', $issue->organization, $actor, $issue, ['to' => $to->name, 'reason' => $reason]);
            }

            if ($issue->parent_id) {
                $this->syncParentCounters($issue->parent_id);
            }
        });

        $issue->setRelation('status', $to);

        IssueStatusChanged::dispatch($issue, $from, $to, $actor, $source);

        if ($to->category === StatusCategory::Completed) {
            IssueCompleted::dispatch($issue, $actor, $source);
        }

        if ($to->category->isTerminal()) {
            $this->dependencies->resolveFor($issue, $this);
        } elseif ($from->category->isTerminal()) {
            $this->dependencies->unresolveFor($issue, $this);
        }

        if ($source !== 'auto') {
            $this->notifyFollowers($issue, new IssueStatusChangedNotification($issue, $from, $to, $actor), $actor);
        } elseif ($issue->owner && $to->category->isTerminal()) {
            $issue->owner->notify(new IssueStatusChangedNotification($issue, $from, $to, $actor));
        }

        $this->broadcast($issue, 'status');

        return $issue;
    }

    public function setPriority(Issue $issue, IssuePriority $priority, ?User $actor): Issue
    {
        if ($issue->priority === $priority) {
            return $issue;
        }

        $from = $issue->priority;
        $issue->forceFill(['priority' => $priority, 'last_activity_at' => now()])->save();
        $this->record($issue, IssueActivityType::PriorityChanged, $actor, ['from' => $from->key(), 'to' => $priority->key()]);
        $this->broadcast($issue, 'priority');

        return $issue;
    }

    public function setOwner(Issue $issue, ?User $owner, ?User $actor): Issue
    {
        if ($issue->owner_id === $owner?->id) {
            return $issue;
        }

        $previous = $issue->owner;

        DB::transaction(function () use ($issue, $owner, $actor, $previous) {
            $issue->forceFill(['owner_id' => $owner?->id, 'last_activity_at' => now()])->save();
            $this->record($issue, IssueActivityType::OwnerChanged, $actor, ['from' => $previous?->id, 'to' => $owner?->id, 'from_name' => $previous?->name, 'to_name' => $owner?->name]);

            if ($owner) {
                $issue->followers()->syncWithoutDetaching([$owner->id]);
            }
        });

        $issue->unsetRelation('owner');
        IssueOwnerChanged::dispatch($issue, $owner, $previous, $actor);

        if ($owner && $owner->id !== $actor?->id) {
            $owner->notify(new IssueOwnerAssignedNotification($issue, $actor));
        }

        $this->broadcast($issue, 'owner');

        return $issue;
    }

    public function setLeadTeam(Issue $issue, ?Team $team, ?User $actor): Issue
    {
        if ($issue->lead_team_id === $team?->id) {
            return $issue;
        }

        if ($team && $team->organization_id !== $issue->organization_id) {
            throw ValidationException::withMessages(['lead_team_id' => 'That team belongs to another organization.']);
        }

        $from = $issue->leadTeam;
        $issue->forceFill(['lead_team_id' => $team?->id, 'last_activity_at' => now()])->save();
        $issue->unsetRelation('leadTeam');
        $this->record($issue, IssueActivityType::LeadTeamChanged, $actor, ['from' => $from?->name, 'to' => $team?->name, 'from_id' => $from?->id, 'to_id' => $team?->id]);

        if ($team && ! $issue->workstreams()->where('team_id', $team->id)->exists()) {
            $this->addTeam($issue, $team, $actor, ['is_required' => true]);
        }

        $this->broadcast($issue, 'lead_team');

        return $issue;
    }

    public function setParent(Issue $issue, ?Issue $parent, ?User $actor): Issue
    {
        if ($issue->parent_id === $parent?->id) {
            return $issue;
        }

        if ($parent) {
            if ($parent->id === $issue->id || $parent->organization_id !== $issue->organization_id) {
                throw ValidationException::withMessages(['parent' => 'An issue cannot be its own parent.']);
            }

            $ancestor = $parent;

            while ($ancestor?->parent_id) {
                if ($ancestor->parent_id === $issue->id) {
                    throw ValidationException::withMessages(['parent' => 'That would make the issue a descendant of itself.']);
                }

                $ancestor = $ancestor->parent;
            }
        }

        $previousParentId = $issue->parent_id;

        DB::transaction(function () use ($issue, $parent, $actor, $previousParentId) {
            $issue->forceFill(['parent_id' => $parent?->id, 'last_activity_at' => now()])->save();
            $this->record($issue, IssueActivityType::ParentChanged, $actor, ['from' => $previousParentId, 'to' => $parent?->id, 'to_identifier' => $parent?->identifier]);

            foreach (array_filter([$previousParentId, $parent?->id]) as $parentId) {
                $this->syncParentCounters($parentId);
            }
        });

        $issue->unsetRelation('parent');
        $this->broadcast($issue, 'parent');

        return $issue;
    }

    public function setMilestone(Issue $issue, ?Milestone $milestone, ?User $actor): Issue
    {
        if ($issue->milestone_id === $milestone?->id) {
            return $issue;
        }

        if ($milestone && $milestone->project_id !== null && $milestone->project_id !== $issue->project_id) {
            throw ValidationException::withMessages(['milestone_id' => 'That milestone belongs to another project.']);
        }

        $from = $issue->milestone;
        $issue->forceFill(['milestone_id' => $milestone?->id, 'last_activity_at' => now()])->save();
        $issue->unsetRelation('milestone');
        $this->record($issue, IssueActivityType::MilestoneChanged, $actor, ['from' => $from?->name, 'to' => $milestone?->name]);
        $this->broadcast($issue, 'milestone');

        return $issue;
    }

    /**
     * Move between products and projects. The identifier never changes; the overall status is
     * remapped by category when the destination uses another workflow.
     */
    public function move(Issue $issue, ?Product $product, ?Project $project, ?User $actor): Issue
    {
        if ($project && $project->product_id !== null) {
            $product = $project->product;
        }

        if ($project && $project->organization_id !== $issue->organization_id) {
            throw ValidationException::withMessages(['project_id' => 'That project belongs to another organization.']);
        }

        if ($product && $product->organization_id !== $issue->organization_id) {
            throw ValidationException::withMessages(['product_id' => 'That product belongs to another organization.']);
        }

        if ($issue->product_id === $product?->id && $issue->project_id === $project?->id) {
            return $issue;
        }

        $fromProduct = $issue->product;
        $fromProject = $issue->project;

        DB::transaction(function () use ($issue, $product, $project, $actor, $fromProduct, $fromProject) {
            $currentWorkflow = $this->workflows->issueWorkflowFor($issue->organization, $fromProduct);
            $nextWorkflow = $this->workflows->issueWorkflowFor($issue->organization, $product);
            $remapped = null;

            if ($currentWorkflow->id !== $nextWorkflow->id) {
                $status = $nextWorkflow->statusFor($issue->status_category) ?? $nextWorkflow->defaultStatus();
                $remapped = ['from' => $issue->status->name, 'to' => $status->name];
                $issue->status_id = $status->id;
                $issue->status_category = $status->category;
                $issue->unsetRelation('status');
            }

            if ($fromProduct?->id !== $product?->id) {
                $issue->modules()->detach();
            }

            if ($fromProject?->id !== $project?->id) {
                $issue->milestone_id = null;
            }

            $issue->forceFill(['product_id' => $product?->id, 'project_id' => $project?->id, 'last_activity_at' => now()])->save();

            $this->record($issue, IssueActivityType::Moved, $actor, [
                'from_product' => $fromProduct?->name,
                'to_product' => $product?->name,
                'from_project' => $fromProject?->name,
                'to_project' => $project?->name,
                'status_remapped' => $remapped,
            ]);
        });

        $issue->unsetRelation('product')->unsetRelation('project');
        IssueMoved::dispatch($issue, $fromProduct, $product, $fromProject, $project, $actor);
        $this->broadcast($issue, 'moved');

        return $issue;
    }

    /**
     * @param  array{responsibility?: string|null, is_required?: bool, assignees?: array<int, int>, cycle?: Cycle|null, estimate?: float|string|null, due_date?: string|null}  $attributes
     */
    public function addTeam(Issue $issue, Team $team, ?User $actor, array $attributes = [], bool $recompute = true, bool $broadcast = true): IssueWorkstream
    {
        if ($team->organization_id !== $issue->organization_id) {
            throw ValidationException::withMessages(['team_id' => 'That team belongs to another organization.']);
        }

        $existing = $issue->workstreams()->where('team_id', $team->id)->first();

        if ($existing) {
            return $existing;
        }

        $workflow = $this->workflows->forTeam($team);
        $status = $workflow->defaultStatus();

        $workstream = DB::transaction(function () use ($issue, $team, $actor, $attributes, $status) {
            $workstream = $issue->workstreams()->create([
                'team_id' => $team->id,
                'responsibility' => $attributes['responsibility'] ?? null,
                'status_id' => $status->id,
                'status_category' => $status->category,
                'is_required' => $attributes['is_required'] ?? true,
                'cycle_id' => ($attributes['cycle'] ?? null)?->id,
                'estimate' => $attributes['estimate'] ?? null,
                'due_date' => $attributes['due_date'] ?? null,
                'position' => (int) $issue->workstreams()->max('position') + 1,
            ]);

            $this->record($issue, IssueActivityType::TeamAdded, $actor, ['team_id' => $team->id, 'team' => $team->name, 'is_required' => $workstream->is_required], $workstream);

            foreach ($attributes['assignees'] ?? [] as $userId) {
                $assignee = User::query()->find($userId);

                if ($assignee) {
                    $this->assign($workstream, $assignee, $actor, broadcast: false);
                }
            }

            return $workstream;
        });

        $issue->unsetRelation('workstreams');
        TeamAddedToIssue::dispatch($issue, $workstream, $actor);
        $this->notifyTeamLeads($team, new TeamAddedToIssueNotification($issue, $team, $actor), $actor);

        if ($recompute) {
            $this->completion->recompute($issue, $this, $actor, 'team_added');
        }

        if ($broadcast) {
            WorkstreamUpdated::dispatch($workstream, 'created');
            $this->broadcast($issue, 'workstream');
        }

        return $workstream;
    }

    public function removeTeam(Issue $issue, Team $team, ?User $actor): void
    {
        $workstream = $issue->workstreams()->where('team_id', $team->id)->first();

        if (! $workstream) {
            return;
        }

        DB::transaction(function () use ($issue, $team, $actor, $workstream) {
            $this->dependencies->onNodeRemoved($workstream, $this);
            $workstream->delete();
            $this->record($issue, IssueActivityType::TeamRemoved, $actor, ['team_id' => $team->id, 'team' => $team->name]);

            if ($issue->lead_team_id === $team->id) {
                $issue->forceFill(['lead_team_id' => null])->save();
            }
        });

        $issue->unsetRelation('workstreams');
        TeamRemovedFromIssue::dispatch($issue, $team, $actor);
        $this->completion->recompute($issue, $this, $actor, 'team_removed');
        WorkstreamUpdated::dispatch($workstream, 'deleted');
        $this->broadcast($issue, 'workstream');
    }

    /**
     * @param  array{responsibility?: string|null, is_required?: bool, estimate?: float|string|null, due_date?: string|null}  $attributes
     */
    public function updateWorkstream(IssueWorkstream $workstream, array $attributes, ?User $actor): IssueWorkstream
    {
        $issue = $workstream->issue;
        $changes = [];

        foreach (['responsibility', 'estimate', 'due_date'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $current = $workstream->{$field};
                $current = $current instanceof CarbonInterface ? $current->toDateString() : $current;
                $next = $attributes[$field];

                if ((string) $current !== (string) $next) {
                    $changes[$field] = ['from' => $current, 'to' => $next];
                    $workstream->setAttribute($field, $next === '' ? null : $next);
                }
            }
        }

        $requiredChanged = array_key_exists('is_required', $attributes) && (bool) $attributes['is_required'] !== $workstream->is_required;

        if ($requiredChanged) {
            $workstream->is_required = (bool) $attributes['is_required'];
        }

        if ($changes === [] && ! $requiredChanged) {
            return $workstream;
        }

        $workstream->save();

        if ($changes !== []) {
            $this->record($issue, IssueActivityType::WorkstreamUpdated, $actor, ['team' => $workstream->team->name, 'changes' => $changes], $workstream);
        }

        if ($requiredChanged) {
            $this->record($issue, IssueActivityType::WorkstreamRequiredChanged, $actor, ['team' => $workstream->team->name, 'is_required' => $workstream->is_required], $workstream);
            $this->completion->recompute($issue, $this, $actor, 'required_changed');
        }

        WorkstreamUpdated::dispatch($workstream, 'updated');
        $this->broadcast($issue, 'workstream');

        return $workstream;
    }

    /**
     * Change one team's status without touching the others. Completing a blocked workstream
     * needs an explicit force.
     */
    public function setWorkstreamStatus(IssueWorkstream $workstream, WorkflowStatus $to, ?User $actor, string $source = 'manual', bool $force = false): IssueWorkstream
    {
        $workflow = $this->workflows->forTeam($workstream->team);

        if ($to->workflow_id !== $workflow->id) {
            throw ValidationException::withMessages(['status_id' => 'That status does not belong to this team\'s workflow.']);
        }

        $from = $workstream->status;

        if ($from->id === $to->id) {
            return $workstream;
        }

        if (! $this->workflows->transitionAllowed($workflow, $from, $to)) {
            throw ValidationException::withMessages(['status_id' => "Moving from {$from->name} to {$to->name} is not allowed by the team's workflow."]);
        }

        if ($to->category === StatusCategory::Completed && $workstream->blocked_by_count > 0 && ! $force) {
            throw ValidationException::withMessages(['status_id' => 'This workstream is still blocked. Resolve its blockers or complete it anyway.']);
        }

        $issue = $workstream->issue;

        DB::transaction(function () use ($workstream, $issue, $from, $to, $actor, $source, $force) {
            $now = now();

            $workstream->forceFill([
                'status_id' => $to->id,
                'status_category' => $to->category,
                'started_at' => $workstream->started_at ?? ($to->category->rank() >= StatusCategory::Started->rank() ? $now : null),
                'completed_at' => $to->category === StatusCategory::Completed ? $now : null,
            ])->save();

            $this->record($issue, $force && $workstream->blocked_by_count > 0 ? IssueActivityType::WorkstreamStatusOverridden : IssueActivityType::WorkstreamStatusChanged, $actor, [
                'team_id' => $workstream->team_id,
                'team' => $workstream->team->name,
                'from' => $from->name,
                'to' => $to->name,
                'from_category' => $from->category->value,
                'to_category' => $to->category->value,
                'source' => $source,
            ], $workstream);
        });

        $workstream->setRelation('status', $to);
        $issue->unsetRelation('workstreams');

        WorkstreamStatusChanged::dispatch($issue, $workstream, $from, $to, $actor, $source);

        if ($to->category->isTerminal()) {
            $this->dependencies->resolveFor($workstream, $this);
        } elseif ($from->category->isTerminal()) {
            $this->dependencies->unresolveFor($workstream, $this);
        }

        $this->completion->recompute($issue, $this, $actor, 'workstream_status');

        WorkstreamUpdated::dispatch($workstream, 'status');
        $this->broadcast($issue, 'workstream');

        return $workstream;
    }

    public function assign(IssueWorkstream $workstream, User $assignee, ?User $actor, bool $broadcast = true): IssueWorkstream
    {
        $issue = $workstream->issue;

        if (! $assignee->belongsToOrganization($issue->organization)) {
            throw ValidationException::withMessages(['user_id' => 'That person is not a member of this organization.']);
        }

        if ($workstream->assignees()->whereKey($assignee->id)->exists()) {
            return $workstream;
        }

        DB::transaction(function () use ($workstream, $issue, $assignee, $actor) {
            $workstream->assignees()->attach($assignee->id, ['assigned_by_id' => $actor?->id]);
            $issue->followers()->syncWithoutDetaching([$assignee->id]);
            $this->record($issue, IssueActivityType::WorkstreamAssigned, $actor, ['team' => $workstream->team->name, 'user_id' => $assignee->id, 'user' => $assignee->name], $workstream);
        });

        $workstream->unsetRelation('assignees');
        IssueAssigned::dispatch($issue, $workstream, $assignee, $actor);

        if ($assignee->id !== $actor?->id) {
            $assignee->notify(new WorkstreamAssignedNotification($issue, $workstream, $actor));
        }

        if ($broadcast) {
            WorkstreamUpdated::dispatch($workstream, 'assignees');
            $this->broadcast($issue, 'assignees');
        }

        return $workstream;
    }

    public function unassign(IssueWorkstream $workstream, User $assignee, ?User $actor): IssueWorkstream
    {
        $issue = $workstream->issue;

        if ($workstream->assignees()->detach($assignee->id) === 0) {
            return $workstream;
        }

        $workstream->unsetRelation('assignees');
        $this->record($issue, IssueActivityType::WorkstreamUnassigned, $actor, ['team' => $workstream->team->name, 'user_id' => $assignee->id, 'user' => $assignee->name], $workstream);
        WorkstreamUpdated::dispatch($workstream, 'assignees');
        $this->broadcast($issue, 'assignees');

        return $workstream;
    }

    /**
     * Replace the assignee set of a workstream in one go.
     *
     * @param  array<int, int>  $userIds
     */
    public function syncAssignees(IssueWorkstream $workstream, array $userIds, ?User $actor): IssueWorkstream
    {
        $current = $workstream->assignees()->pluck('users.id')->map(fn ($id) => (int) $id)->all();

        foreach (array_diff($current, $userIds) as $removed) {
            $user = User::query()->find($removed);

            if ($user) {
                $this->unassign($workstream, $user, $actor);
            }
        }

        foreach (array_diff($userIds, $current) as $added) {
            $user = User::query()->find($added);

            if ($user) {
                $this->assign($workstream, $user, $actor);
            }
        }

        return $workstream->refresh();
    }

    public function setWorkstreamCycle(IssueWorkstream $workstream, ?Cycle $cycle, ?User $actor, string $source = 'manual'): IssueWorkstream
    {
        if ($cycle && $cycle->team_id !== $workstream->team_id) {
            throw ValidationException::withMessages(['cycle_id' => 'That cycle belongs to another team.']);
        }

        if ($workstream->cycle_id === $cycle?->id) {
            return $workstream;
        }

        $from = $workstream->cycle;
        $workstream->forceFill(['cycle_id' => $cycle?->id])->save();
        $workstream->unsetRelation('cycle');

        $this->record($workstream->issue, $source === 'carry_over' ? IssueActivityType::CycleCarriedOver : IssueActivityType::WorkstreamCycleChanged, $actor, [
            'team' => $workstream->team->name,
            'from' => $from?->displayName(),
            'to' => $cycle?->displayName(),
            'from_id' => $from?->id,
            'to_id' => $cycle?->id,
        ], $workstream);

        WorkstreamUpdated::dispatch($workstream, 'cycle');
        $this->broadcast($workstream->issue, 'workstream');

        return $workstream;
    }

    public function toggleLabel(Issue $issue, IssueLabel $label, bool $attached, ?User $actor): Issue
    {
        if ($label->organization_id !== $issue->organization_id || ($label->product_id && $label->product_id !== $issue->product_id)) {
            throw ValidationException::withMessages(['label_id' => 'That label is not available for this issue.']);
        }

        $has = $issue->labels()->whereKey($label->id)->exists();

        if ($attached && ! $has) {
            $issue->labels()->attach($label->id);
            $this->record($issue, IssueActivityType::LabelAdded, $actor, ['label_id' => $label->id, 'label' => $label->name, 'color' => $label->color]);
        } elseif (! $attached && $has) {
            $issue->labels()->detach($label->id);
            $this->record($issue, IssueActivityType::LabelRemoved, $actor, ['label_id' => $label->id, 'label' => $label->name, 'color' => $label->color]);
        } else {
            return $issue;
        }

        $issue->unsetRelation('labels');
        $issue->forceFill(['last_activity_at' => now()])->save();
        $this->broadcast($issue, 'labels');

        return $issue;
    }

    /**
     * @param  array<int, int>  $moduleIds
     */
    public function syncModules(Issue $issue, array $moduleIds, ?User $actor): Issue
    {
        $ids = $this->moduleIds($issue->product, $moduleIds);
        $current = $issue->modules()->pluck('modules.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        sort($ids);

        if ($ids === $current) {
            return $issue;
        }

        $issue->modules()->sync($ids);
        $issue->unsetRelation('modules');
        $this->record($issue, IssueActivityType::ModulesChanged, $actor, ['from' => $current, 'to' => $ids]);
        $this->broadcast($issue, 'modules');

        return $issue;
    }

    /**
     * Blocks / related / duplicate links between issues. `blocks` is checked for cycles.
     */
    public function addRelation(Issue $issue, Issue $related, IssueRelationType $type, ?User $actor): IssueRelation
    {
        if ($related->id === $issue->id || $related->organization_id !== $issue->organization_id) {
            throw ValidationException::withMessages(['related' => 'Pick another issue of this organization.']);
        }

        [$a, $b] = $type === IssueRelationType::Blocks ? [$issue, $related] : ($issue->id < $related->id ? [$issue, $related] : [$related, $issue]);

        $existing = IssueRelation::query()->where('issue_id', $a->id)->where('related_issue_id', $b->id)->where('type', $type->value)->first();

        if ($existing) {
            return $existing;
        }

        $relation = DB::transaction(function () use ($issue, $a, $b, $type, $actor) {
            if ($type === IssueRelationType::Blocks) {
                $this->dependencies->assertAcyclic($b, $a);
            }

            $relation = IssueRelation::query()->create([
                'organization_id' => $issue->organization_id,
                'issue_id' => $a->id,
                'related_issue_id' => $b->id,
                'type' => $type,
                'created_by_id' => $actor?->id,
            ]);

            $payload = ['type' => $type->value, 'issue_id' => $a->id, 'issue' => $a->identifier, 'related_id' => $b->id, 'related' => $b->identifier];
            $this->record($a, IssueActivityType::RelationAdded, $actor, $payload);
            $this->record($b, IssueActivityType::RelationAdded, $actor, $payload);

            if ($type === IssueRelationType::Blocks) {
                $this->dependencies->recomputeBlocked($b);
            }

            return $relation;
        });

        $this->broadcast($a, 'relations');
        $this->broadcast($b, 'relations');

        return $relation;
    }

    public function removeRelation(IssueRelation $relation, ?User $actor): void
    {
        $a = $relation->issue;
        $b = $relation->relatedIssue;

        DB::transaction(function () use ($relation, $a, $b, $actor) {
            $relation->delete();
            $payload = ['type' => $relation->type->value, 'issue' => $a->identifier, 'related' => $b->identifier];
            $this->record($a, IssueActivityType::RelationRemoved, $actor, $payload);
            $this->record($b, IssueActivityType::RelationRemoved, $actor, $payload);

            if ($relation->type === IssueRelationType::Blocks) {
                $this->dependencies->recomputeBlocked($b);
            }
        });

        $this->broadcast($a, 'relations');
        $this->broadcast($b, 'relations');
    }

    public function follow(Issue $issue, User $user, bool $following, ?User $actor = null): Issue
    {
        $has = $issue->followers()->whereKey($user->id)->exists();

        if ($following && ! $has) {
            $issue->followers()->attach($user->id);
            $this->record($issue, IssueActivityType::FollowerAdded, $actor ?? $user, ['user_id' => $user->id, 'user' => $user->name]);
        } elseif (! $following && $has) {
            $issue->followers()->detach($user->id);
            $this->record($issue, IssueActivityType::FollowerRemoved, $actor ?? $user, ['user_id' => $user->id, 'user' => $user->name]);
        }

        $issue->unsetRelation('followers');

        return $issue;
    }

    public function delete(Issue $issue, ?User $actor): void
    {
        DB::transaction(function () use ($issue, $actor) {
            $this->record($issue, IssueActivityType::Deleted, $actor);
            $issue->delete();

            if ($issue->parent_id) {
                $this->syncParentCounters($issue->parent_id);
            }
        });

        $this->audit->record('issue.deleted', $issue->organization, $actor, $issue, ['identifier' => $issue->identifier]);
        $this->broadcast($issue, 'deleted');
    }

    public function restore(Issue $issue, ?User $actor): Issue
    {
        $issue->restore();
        $this->record($issue, IssueActivityType::Restored, $actor);
        $this->broadcast($issue, 'restored');

        return $issue;
    }

    /**
     * Append one timeline entry and push it to anyone watching the issue.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(Issue $issue, IssueActivityType $type, ?User $actor, array $payload = [], ?IssueWorkstream $workstream = null): IssueActivity
    {
        $activity = $issue->activities()->create([
            'workstream_id' => $workstream?->id,
            'actor_id' => $actor?->id,
            'type' => $type,
            'payload' => $payload ?: null,
        ]);

        $activity->setRelation('issue', $issue);
        IssueActivityAdded::dispatch($activity);

        return $activity;
    }

    public function broadcast(Issue $issue, string $reason): void
    {
        IssueUpdated::dispatch($issue, $reason);
    }

    public function notifyReadyToComplete(Issue $issue, CompletionReadiness $readiness): void
    {
        $recipients = collect([$issue->owner, $issue->project?->lead])->filter()->unique('id');

        Notification::send($recipients, new IssueReadyToCompleteNotification($issue));
    }

    public function notifyCompletionConflict(Issue $issue, CompletionReadiness $readiness): void
    {
        $recipients = collect([$issue->owner, $issue->project?->lead])->filter()->unique('id');

        Notification::send($recipients, new IssueCompletionConflictNotification($issue));
    }

    /**
     * Followers minus the actor. Workstream-level noise never goes through here.
     */
    protected function notifyFollowers(Issue $issue, object $notification, ?User $actor): void
    {
        $recipients = $issue->followers()->whereKeyNot($actor->id ?? 0)->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, $notification);
        }
    }

    protected function notifyTeamLeads(Team $team, object $notification, ?User $actor): void
    {
        $leads = $team->members()->wherePivot('role', 'lead')->whereKeyNot($actor->id ?? 0)->get();

        if ($leads->isNotEmpty()) {
            Notification::send($leads, $notification);
        }
    }

    /**
     * @param  array<int, int>  $labelIds
     */
    protected function syncLabels(Issue $issue, array $labelIds, ?User $actor): void
    {
        $ids = $this->labelIds($issue->organization, $labelIds);
        $current = $issue->labels()->pluck('issue_labels.id')->map(fn ($id) => (int) $id)->all();

        foreach (array_diff($current, $ids) as $removed) {
            $label = IssueLabel::query()->find($removed);

            if ($label) {
                $this->toggleLabel($issue, $label, false, $actor);
            }
        }

        foreach (array_diff($ids, $current) as $added) {
            $label = IssueLabel::query()->find($added);

            if ($label) {
                $this->toggleLabel($issue, $label, true, $actor);
            }
        }
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    protected function labelIds(Organization $organization, array $ids): array
    {
        return IssueLabel::query()->where('organization_id', $organization->id)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Modules must belong to the issue's product.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    protected function moduleIds(?Product $product, array $ids): array
    {
        if (! $product || $ids === []) {
            return [];
        }

        return $product->modules()->whereIn('modules.id', $ids)->pluck('modules.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return array{html: string|null, text: string|null}
     */
    protected function describe(?string $html): array
    {
        $html = $html !== null ? trim($html) : null;

        if ($html === null || $html === '' || $html === '<p></p>') {
            return ['html' => null, 'text' => null];
        }

        $clean = $this->sanitizer->sanitize($html) ?? '';
        $text = trim(html_entity_decode(strip_tags(preg_replace('/<\/(p|div|li|h[1-6]|blockquote|br)>/i', "$0\n", $clean) ?? $clean)));

        return ['html' => $clean, 'text' => Str::limit($text, 60000, '')];
    }

    protected function syncParentCounters(int $parentId): void
    {
        $counts = Issue::query()->where('parent_id', $parentId)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(status_category IN ('completed', 'canceled')) AS done")
            ->first();

        Issue::query()->whereKey($parentId)->update([
            'sub_issues_count' => (int) ($counts->total ?? 0),
            'completed_sub_issues_count' => (int) ($counts->done ?? 0),
        ]);
    }

    /**
     * @return Collection<int, User>
     */
    protected function assigneesOf(Issue $issue): Collection
    {
        return User::query()
            ->whereIn('id', DB::table('issue_workstream_assignees')
                ->join('issue_workstreams', 'issue_workstreams.id', '=', 'issue_workstream_assignees.workstream_id')
                ->where('issue_workstreams.issue_id', $issue->id)
                ->pluck('issue_workstream_assignees.user_id'))
            ->get();
    }
}

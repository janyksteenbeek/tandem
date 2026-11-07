<?php

namespace App\Models\Projects;

use App\Enums\Projects\IssuePriority;
use App\Enums\Projects\StatusCategory;
use App\Models\Mail\Conversation;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Projects\Access\IssueAccess;
use Carbon\CarbonInterface;
use Database\Factories\Projects\IssueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * One unit of work. Several teams can participate through workstreams; the issue keeps one
 * description, conversation and overall status.
 *
 * @property int $id
 * @property string $uuid
 * @property int $organization_id
 * @property int|null $product_id
 * @property int|null $project_id
 * @property int|null $milestone_id
 * @property int|null $parent_id
 * @property string $prefix
 * @property int $number
 * @property string $identifier
 * @property string $title
 * @property string|null $description_html
 * @property string|null $description_text
 * @property int $status_id
 * @property StatusCategory $status_category
 * @property int|null $suggested_status_id
 * @property bool $is_ready_to_complete
 * @property CarbonInterface|null $status_overridden_at
 * @property IssuePriority $priority
 * @property int|null $owner_id
 * @property int|null $lead_team_id
 * @property int|null $created_by_id
 * @property string|null $estimate
 * @property CarbonInterface|null $start_date
 * @property CarbonInterface|null $due_date
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $canceled_at
 * @property int $workstreams_count
 * @property int $workstreams_required_count
 * @property int $workstreams_completed_count
 * @property int $blocked_workstreams_count
 * @property int $blocked_by_count
 * @property int $comment_count
 * @property int $attachment_count
 * @property int $link_count
 * @property int $sub_issues_count
 * @property int $completed_sub_issues_count
 * @property string|null $sort_order
 * @property CarbonInterface|null $last_activity_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 * @property-read Organization $organization
 * @property-read Product|null $product
 * @property-read Project|null $project
 * @property-read Milestone|null $milestone
 * @property-read Issue|null $parent
 * @property-read WorkflowStatus $status
 * @property-read WorkflowStatus|null $suggestedStatus
 * @property-read User|null $owner
 * @property-read Team|null $leadTeam
 * @property-read User|null $createdBy
 * @property-read Collection<int, Issue> $subIssues
 * @property-read Collection<int, IssueWorkstream> $workstreams
 * @property-read Collection<int, Module> $modules
 * @property-read Collection<int, IssueLabel> $labels
 * @property-read Collection<int, Release> $releases
 * @property-read Collection<int, User> $followers
 * @property-read Collection<int, IssueRelation> $relations
 * @property-read Collection<int, IssueRelation> $inverseRelations
 * @property-read Collection<int, IssueComment> $comments
 * @property-read Collection<int, IssueAttachment> $attachments
 * @property-read Collection<int, IssueActivity> $activities
 * @property-read Collection<int, IssueConversationLink> $conversationLinks
 * @property-read Collection<int, Conversation> $conversations
 * @property-read Collection<int, IssueSharedContent> $sharedContent
 * @property-read Collection<int, EntityLink> $links
 */
#[Fillable([
    'organization_id', 'product_id', 'project_id', 'milestone_id', 'parent_id', 'prefix', 'number', 'identifier',
    'title', 'description_html', 'description_text', 'status_id', 'status_category', 'suggested_status_id',
    'is_ready_to_complete', 'status_overridden_at', 'priority', 'owner_id', 'lead_team_id', 'created_by_id',
    'estimate', 'start_date', 'due_date', 'started_at', 'completed_at', 'canceled_at', 'sort_order', 'last_activity_at',
])]
class Issue extends Model
{
    /** @use HasFactory<IssueFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(fn (Issue $issue) => $issue->uuid ??= (string) Str::uuid());
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Milestone, $this> */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    /** @return BelongsTo<Issue, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'parent_id');
    }

    /** @return HasMany<Issue, $this> */
    public function subIssues(): HasMany
    {
        return $this->hasMany(Issue::class, 'parent_id')->orderBy('number');
    }

    /** @return BelongsTo<WorkflowStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(WorkflowStatus::class, 'status_id');
    }

    /** @return BelongsTo<WorkflowStatus, $this> */
    public function suggestedStatus(): BelongsTo
    {
        return $this->belongsTo(WorkflowStatus::class, 'suggested_status_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Team, $this> */
    public function leadTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'lead_team_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** @return HasMany<IssueWorkstream, $this> */
    public function workstreams(): HasMany
    {
        return $this->hasMany(IssueWorkstream::class)->orderBy('position')->orderBy('id');
    }

    /** @return BelongsToMany<Module, $this> */
    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'issue_module')->withTimestamps();
    }

    /** @return BelongsToMany<IssueLabel, $this> */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(IssueLabel::class, 'issue_label', 'issue_id', 'label_id')->withTimestamps();
    }

    /** @return BelongsToMany<Release, $this> */
    public function releases(): BelongsToMany
    {
        return $this->belongsToMany(Release::class, 'issue_release')->withTimestamps();
    }

    /** @return BelongsToMany<User, $this> */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'issue_followers')->withTimestamps();
    }

    /** @return HasMany<IssueRelation, $this> */
    public function relations(): HasMany
    {
        return $this->hasMany(IssueRelation::class);
    }

    /** @return HasMany<IssueRelation, $this> */
    public function inverseRelations(): HasMany
    {
        return $this->hasMany(IssueRelation::class, 'related_issue_id');
    }

    /** @return HasMany<IssueComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(IssueComment::class)->orderBy('created_at');
    }

    /** @return HasMany<IssueAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(IssueAttachment::class)->orderBy('created_at');
    }

    /** @return HasMany<IssueActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(IssueActivity::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<IssueConversationLink, $this> */
    public function conversationLinks(): HasMany
    {
        return $this->hasMany(IssueConversationLink::class);
    }

    /** @return BelongsToMany<Conversation, $this> */
    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'issue_conversation_links')->withPivot(['kind', 'linked_by_id', 'note'])->withTimestamps();
    }

    /** @return HasMany<IssueSharedContent, $this> */
    public function sharedContent(): HasMany
    {
        return $this->hasMany(IssueSharedContent::class)->orderBy('created_at');
    }

    /** @return MorphMany<EntityLink, $this> */
    public function links(): MorphMany
    {
        return $this->morphMany(EntityLink::class, 'linkable')->orderBy('position');
    }

    /**
     * Issues the user may see. Mirrors IssueAccess::levelFor for list queries.
     *
     * @param  Builder<Issue>  $query
     * @return Builder<Issue>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return app(IssueAccess::class)->applyVisibility($query, $user);
    }

    /**
     * Identifier search is exact; everything else goes through FULLTEXT with a LIKE fallback for
     * tokens shorter than the InnoDB minimum.
     *
     * @param  Builder<Issue>  $query
     * @return Builder<Issue>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9]{1,9}-\d+$/', $term)) {
            return $query->where('issues.identifier', strtoupper($term));
        }

        $tokens = collect(preg_split('/\s+/', $term) ?: [])
            ->map(fn (string $t) => preg_replace('/[^\p{L}\p{N}]/u', '', $t) ?? '')
            ->filter(fn (string $t) => $t !== '');

        $long = $tokens->filter(fn (string $t) => mb_strlen($t) >= 3);

        return $query->where(function (Builder $q) use ($term, $long) {
            if ($long->isNotEmpty()) {
                $boolean = $long->map(fn (string $t) => '+'.$t.'*')->implode(' ');
                $q->whereRaw('MATCH(issues.title, issues.description_text) AGAINST (? IN BOOLEAN MODE)', [$boolean]);
            }

            $q->orWhere('issues.title', 'like', '%'.$term.'%')
                ->orWhere('issues.identifier', 'like', strtoupper($term).'%');
        });
    }

    public function isTerminal(): bool
    {
        return $this->status_category->isTerminal();
    }

    protected function casts(): array
    {
        return [
            'status_category' => StatusCategory::class,
            'priority' => IssuePriority::class,
            'is_ready_to_complete' => 'boolean',
            'status_overridden_at' => 'datetime',
            'start_date' => 'date',
            'due_date' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'canceled_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'identifier';
    }
}

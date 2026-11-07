<?php

namespace App\Models\Projects;

use App\Enums\Projects\IssueLinkKind;
use App\Models\Automations\AutomationRule;
use App\Models\Mail\Conversation;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reference between an issue and a mail conversation. Linking never exposes mail content; the
 * link is only shown to users who can view the conversation themselves.
 *
 * @property int $id
 * @property int $issue_id
 * @property int $conversation_id
 * @property IssueLinkKind $kind
 * @property int|null $linked_by_id
 * @property int|null $automation_rule_id
 * @property string|null $note
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Issue $issue
 * @property-read Conversation $conversation
 * @property-read User|null $linkedBy
 * @property-read AutomationRule|null $automationRule
 */
#[Fillable(['issue_id', 'conversation_id', 'kind', 'linked_by_id', 'automation_rule_id', 'note'])]
class IssueConversationLink extends Model
{
    /** @return BelongsTo<Issue, $this> */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_id');
    }

    /** @return BelongsTo<AutomationRule, $this> */
    public function automationRule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class);
    }

    protected function casts(): array
    {
        return ['kind' => IssueLinkKind::class];
    }
}

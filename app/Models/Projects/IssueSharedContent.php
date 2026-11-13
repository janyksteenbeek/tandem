<?php

namespace App\Models\Projects;

use App\Enums\Projects\SharedContentKind;
use App\Models\Mail\Conversation;
use App\Models\Mail\Message;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Mail content deliberately copied into an issue (an excerpt or an attachment).
 *
 * @property int $id
 * @property string $uuid
 * @property int $issue_id
 * @property int|null $conversation_id
 * @property int|null $message_id
 * @property int|null $shared_by_id
 * @property SharedContentKind $kind
 * @property string|null $body_html
 * @property int|null $issue_attachment_id
 * @property CarbonInterface $created_at
 * @property-read Issue $issue
 * @property-read Conversation|null $conversation
 * @property-read Message|null $message
 * @property-read User|null $sharedBy
 * @property-read IssueAttachment|null $attachment
 */
#[Fillable(['issue_id', 'conversation_id', 'message_id', 'shared_by_id', 'kind', 'body_html', 'issue_attachment_id', 'created_at'])]
class IssueSharedContent extends Model
{
    public $timestamps = false;

    protected $table = 'issue_shared_content';

    protected static function booted(): void
    {
        static::creating(function (IssueSharedContent $content) {
            $content->uuid ??= (string) Str::uuid();
            $content->created_at ??= now();
        });
    }

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

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sharedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_by_id');
    }

    /** @return BelongsTo<IssueAttachment, $this> */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(IssueAttachment::class, 'issue_attachment_id');
    }

    protected function casts(): array
    {
        return [
            'kind' => SharedContentKind::class,
            'created_at' => 'datetime',
        ];
    }
}

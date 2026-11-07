<?php

namespace App\Models\Projects;

use App\Models\Mail\Attachment;
use App\Models\Mail\Conversation;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A file on an issue. Served only through IssueAttachmentController. May be a copy of a mail
 * attachment explicitly shared into the issue.
 *
 * @property int $id
 * @property string $uuid
 * @property int $issue_id
 * @property int|null $comment_id
 * @property int|null $uploaded_by_id
 * @property string $filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $disk
 * @property string $path
 * @property string|null $checksum
 * @property int|null $source_attachment_id
 * @property int|null $source_conversation_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Issue $issue
 * @property-read IssueComment|null $comment
 * @property-read User|null $uploadedBy
 * @property-read Attachment|null $sourceAttachment
 * @property-read Conversation|null $sourceConversation
 */
#[Fillable(['issue_id', 'comment_id', 'uploaded_by_id', 'filename', 'mime_type', 'size_bytes', 'disk', 'path', 'checksum', 'source_attachment_id', 'source_conversation_id'])]
class IssueAttachment extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (IssueAttachment $attachment) => $attachment->uuid ??= (string) Str::uuid());

        static::deleted(function (IssueAttachment $attachment) {
            if ($attachment->path) {
                Storage::disk($attachment->disk)->delete($attachment->path);
            }
        });
    }

    /** @return BelongsTo<Issue, $this> */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /** @return BelongsTo<IssueComment, $this> */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(IssueComment::class, 'comment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    /** @return BelongsTo<Attachment, $this> */
    public function sourceAttachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'source_attachment_id');
    }

    /** @return BelongsTo<Conversation, $this> */
    public function sourceConversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'source_conversation_id');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}

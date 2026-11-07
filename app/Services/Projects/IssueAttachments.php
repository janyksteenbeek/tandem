<?php

namespace App\Services\Projects;

use App\Enums\Projects\IssueActivityType;
use App\Models\Mail\Attachment;
use App\Models\Projects\Issue;
use App\Models\Projects\IssueAttachment;
use App\Models\Projects\IssueComment;
use App\Models\User;
use App\Services\Mail\Ingestion\AttachmentStore;
use App\Services\Mail\MailProviderManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Files on issues. Same upload limits and blocked extensions as mail drafts; files live on the
 * attachments disk and are only ever served through an authorised controller.
 */
class IssueAttachments
{
    public function __construct(protected IssueActions $actions) {}

    public function store(Issue $issue, UploadedFile $file, User $actor, ?IssueComment $comment = null): IssueAttachment
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, (array) config('tandem.attachments.blocked_extensions', []), true)) {
            throw ValidationException::withMessages(['file' => 'That file type is not allowed.']);
        }

        $disk = (string) config('tandem.attachments.disk', 'local');
        $path = $file->storeAs('issues/'.$issue->uuid, Str::random(12).($extension ? '.'.$extension : ''), $disk);

        $attachment = $issue->attachments()->create([
            'comment_id' => $comment?->id,
            'uploaded_by_id' => $actor->id,
            'filename' => Str::limit($file->getClientOriginalName(), 200, ''),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize() ?: 0,
            'disk' => $disk,
            'path' => (string) $path,
            'checksum' => hash_file('sha256', $file->getRealPath()) ?: null,
        ]);

        $issue->forceFill(['attachment_count' => $issue->attachments()->count(), 'last_activity_at' => now()])->save();
        $this->actions->record($issue, IssueActivityType::AttachmentAdded, $actor, ['filename' => $attachment->filename, 'uuid' => $attachment->uuid]);
        $this->actions->broadcast($issue, 'attachments');

        return $attachment;
    }

    /**
     * Copy a mail attachment's bytes into the issue, fetching them from the provider if needed.
     */
    public function copyFromMail(Issue $issue, Attachment $source, User $actor): IssueAttachment
    {
        $extension = strtolower(pathinfo($source->filename, PATHINFO_EXTENSION));

        if (in_array($extension, (array) config('tandem.attachments.blocked_extensions', []), true)) {
            throw ValidationException::withMessages(['attachment' => 'That file type is not allowed.']);
        }

        if (! $source->isStored()) {
            $account = $source->message->mailAccount;

            if ($account === null) {
                throw ValidationException::withMessages(['attachment' => 'The attachment content is not available.']);
            }

            app(AttachmentStore::class)->put($source, app(MailProviderManager::class)->for($account)->fetchAttachment($account, $source));
            $source->refresh();
        }

        $disk = (string) config('tandem.attachments.disk', 'local');
        $target = 'issues/'.$issue->uuid.'/'.Str::random(12).($extension ? '.'.$extension : '');
        $stream = Storage::disk($source->disk)->readStream((string) $source->path);

        if ($stream === null) {
            throw ValidationException::withMessages(['attachment' => 'The attachment could not be read.']);
        }

        Storage::disk($disk)->writeStream($target, $stream);

        $attachment = $issue->attachments()->create([
            'uploaded_by_id' => $actor->id,
            'filename' => $source->filename,
            'mime_type' => $source->mime_type,
            'size_bytes' => $source->size_bytes,
            'disk' => $disk,
            'path' => $target,
            'checksum' => $source->checksum,
            'source_attachment_id' => $source->id,
            'source_conversation_id' => $source->message->conversation_id,
        ]);

        $issue->forceFill(['attachment_count' => $issue->attachments()->count(), 'last_activity_at' => now()])->save();
        $this->actions->record($issue, IssueActivityType::AttachmentAdded, $actor, ['filename' => $attachment->filename, 'uuid' => $attachment->uuid, 'from_mail' => true]);

        return $attachment;
    }

    public function delete(IssueAttachment $attachment, User $actor): void
    {
        $issue = $attachment->issue;
        $attachment->delete();
        $issue->forceFill(['attachment_count' => $issue->attachments()->count()])->save();
        $this->actions->record($issue, IssueActivityType::AttachmentRemoved, $actor, ['filename' => $attachment->filename]);
        $this->actions->broadcast($issue, 'attachments');
    }
}

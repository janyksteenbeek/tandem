<?php

namespace App\Jobs\Calls;

use App\Enums\Calls\ExportStatus;
use App\Models\Calls\CallRecordingExport;
use App\Services\Calls\CallRecordings;
use App\Services\Calls\RecordingExporter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds one downloadable export. Runs on its own long-timeout, single-worker, low-priority
 * queue so encoding can never compete with live calls or mail.
 */
class BuildRecordingExport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3700;

    public int $uniqueFor = 3900;

    public function __construct(public CallRecordingExport $export)
    {
        $this->onConnection('redis-long')->onQueue('calls-media');
        $this->timeout = (int) config('calls.ffmpeg.timeout', 3600) + 100;
    }

    public function uniqueId(): string
    {
        return (string) $this->export->id;
    }

    public function handle(RecordingExporter $exporter, CallRecordings $recordings): void
    {
        $export = $this->export;
        $recording = $export->recording;

        $export->forceFill(['status' => ExportStatus::Processing, 'error' => null])->save();

        $target = $recordings->directory($recording).'/exports/'.$export->uuid.'.'.$export->kind->extension();
        $size = $exporter->export($recording, $export->kind, $target);

        $export->forceFill([
            'status' => ExportStatus::Ready,
            'disk' => $recording->disk,
            'path' => $target,
            'filename' => Str::slug($recording->call->displayTitle()).'-'.$export->kind->value.'.'.$export->kind->extension(),
            'size_bytes' => $size,
            'finished_at' => now(),
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        $this->export->forceFill([
            'status' => ExportStatus::Failed,
            'error' => Str::limit($exception?->getMessage() ?? 'The export failed.', 900, ''),
            'finished_at' => now(),
        ])->save();
    }
}

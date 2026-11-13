<?php

namespace App\Services\Calls;

use App\Enums\Calls\ExportKind;
use App\Models\Calls\CallRecording;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Builds the single-file versions of a recording with FFmpeg: every voice mixed into one
 * audio file, the shared screen with that mix, or one combined video. This runs on the queue
 * after the call, at low priority, and never touches the per-track originals.
 *
 * The screen export copies the video stream as recorded, so shared code and text keep the
 * resolution and sharpness they were captured with; only the audio mix is encoded.
 */
class RecordingExporter
{
    public function __construct(protected CallRecordings $recordings) {}

    /**
     * Whether FFmpeg can be run at all on this machine.
     */
    public function isAvailable(): bool
    {
        try {
            return Process::timeout(10)->run([$this->binary(), '-version'])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Produce the export at `$target` (a path relative to the recording's disk) and return its
     * size in bytes.
     *
     * @throws RuntimeException
     */
    public function export(CallRecording $recording, ExportKind $kind, string $target): int
    {
        $disk = Storage::disk($recording->disk);

        if (config('filesystems.disks.'.$recording->disk.'.driver') !== 'local') {
            throw new RuntimeException('Exports need the recordings on a local disk.');
        }

        $disk->makeDirectory(dirname($target));
        $output = $disk->path($target);

        $command = $this->command($recording, $kind, fn (string $file) => $disk->path($this->recordings->directory($recording).'/'.$file), $output);

        $result = Process::timeout((int) config('calls.ffmpeg.timeout', 3600))->run($command);

        if ($result->failed() || ! is_file($output) || filesize($output) === 0) {
            @unlink($output);

            throw new RuntimeException('FFmpeg could not build the export: '.mb_substr(trim($result->errorOutput() ?: $result->output()), -600));
        }

        return (int) filesize($output);
    }

    /**
     * The FFmpeg invocation for one export.
     *
     * @param  callable(string): string  $resolve  Turns a manifest file name into an absolute path.
     * @return list<string>
     *
     * @throws RuntimeException
     */
    public function command(CallRecording $recording, ExportKind $kind, callable $resolve, string $output): array
    {
        $tracks = collect($recording->tracks());
        $audio = $tracks->where('kind', 'audio')->values();
        $screens = $tracks->where('source', 'screen_share')->sortByDesc('duration_ms')->values();
        $cameras = $tracks->where('source', 'camera')->sortByDesc('duration_ms')->values();

        $base = [$this->binary(), '-hide_banner', '-nostdin', '-loglevel', 'error', '-y', '-threads', (string) max(1, (int) config('calls.ffmpeg.threads', 1))];

        if ($kind === ExportKind::Audio) {
            if ($audio->isEmpty()) {
                throw new RuntimeException('This recording has no audio tracks.');
            }

            return [
                ...$base,
                ...$this->inputs($audio->all(), $resolve),
                '-filter_complex', $this->audioMix(array_values($audio->all()), 0, 0),
                '-map', '[aout]',
                '-c:a', 'aac', '-b:a', '160k', '-ar', '48000',
                '-movflags', '+faststart',
                $output,
            ];
        }

        if ($kind === ExportKind::Screen) {
            $screen = $screens->first();

            if ($screen === null) {
                throw new RuntimeException('Nobody shared a screen in this recording.');
            }

            $command = [...$base, '-i', $resolve((string) $screen['file']), ...$this->inputs($audio->all(), $resolve)];

            if ($audio->isNotEmpty()) {
                array_push($command, '-filter_complex', $this->audioMix(array_values($audio->all()), 1, (int) $screen['start_ms']), '-map', '0:v:0', '-map', '[aout]', '-c:a', 'libopus', '-b:a', '96k');
            } else {
                array_push($command, '-map', '0:v:0');
            }

            return [...$command, '-c:v', 'copy', '-t', $this->seconds((int) $screen['duration_ms']), $output];
        }

        $stage = $screens->isNotEmpty() ? $screens : $cameras->take(4);

        if ($stage->isEmpty()) {
            throw new RuntimeException('This recording has no video to combine.');
        }

        $thumbnails = $screens->isNotEmpty() ? $cameras->take(4)->values() : collect();
        [$width, $height, $fps] = $screens->isNotEmpty() ? [1920, 1080, 15] : [1280, 720, 25];
        $duration = max(1, (int) $recording->duration_ms);
        $videos = $stage->concat($thumbnails)->values();

        $filters = [sprintf('color=c=0x101014:s=%dx%d:r=%d:d=%s[base]', $width, $height, $fps, $this->seconds($duration))];
        $last = 'base';

        foreach ($videos as $index => $track) {
            [$w, $h, $x, $y] = $index < $stage->count()
                ? $this->stageBox($screens->isNotEmpty() ? 1 : $stage->count(), $screens->isNotEmpty() ? 0 : $index, $width, $height)
                : [288, 162, $width - 288 - 24, 24 + ($index - $stage->count()) * (162 + 12)];

            $start = $this->seconds((int) $track['start_ms']);
            $end = $this->seconds((int) $track['start_ms'] + (int) $track['duration_ms']);

            $filters[] = sprintf(
                '[%d:v]fps=%d,scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2:color=0x101014,setpts=PTS-STARTPTS+%s/TB[v%d]',
                $index, $fps, $w, $h, $w, $h, $start, $index,
            );
            $filters[] = sprintf("[%s][v%d]overlay=%d:%d:enable='between(t,%s,%s)':eof_action=pass[b%d]", $last, $index, $x, $y, $start, $end, $index);
            $last = 'b'.$index;
        }

        $command = [...$base, ...$this->inputs($videos->all(), $resolve), ...$this->inputs($audio->all(), $resolve)];

        if ($audio->isNotEmpty()) {
            $filters[] = $this->audioMix(array_values($audio->all()), $videos->count(), 0);
        }

        array_push($command, '-filter_complex', implode(';', $filters), '-map', '['.$last.']');

        if ($audio->isNotEmpty()) {
            array_push($command, '-map', '[aout]', '-c:a', 'aac', '-b:a', '160k', '-ar', '48000');
        }

        return [
            ...$command,
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p',
            '-movflags', '+faststart',
            '-t', $this->seconds($duration),
            $output,
        ];
    }

    /**
     * Mix audio inputs onto one timeline. Each track is shifted to where it started in the
     * meeting, measured from `originMs`; a track that began before the origin is trimmed.
     *
     * @param  list<array<string, mixed>>  $tracks
     */
    protected function audioMix(array $tracks, int $firstInput, int $originMs): string
    {
        $filters = [];
        $labels = '';

        foreach ($tracks as $index => $track) {
            $offset = (int) $track['start_ms'] - $originMs;
            $chain = $offset >= 0
                ? sprintf('adelay=%d:all=1', $offset)
                : sprintf('atrim=start=%s,asetpts=PTS-STARTPTS', $this->seconds(-$offset));

            $filters[] = sprintf('[%d:a]aresample=48000,%s[a%d]', $firstInput + $index, $chain, $index);
            $labels .= '[a'.$index.']';
        }

        $filters[] = count($tracks) > 1
            ? sprintf('%samix=inputs=%d:normalize=0:dropout_transition=0,alimiter=limit=0.95[aout]', $labels, count($tracks))
            : '[a0]anull[aout]';

        return implode(';', $filters);
    }

    /**
     * Position of tile `index` when `count` cameras share the canvas.
     *
     * @return array{int, int, int, int} width, height, x, y
     */
    protected function stageBox(int $count, int $index, int $width, int $height): array
    {
        if ($count <= 1) {
            return [$width, $height, 0, 0];
        }

        $columns = 2;
        $rows = (int) ceil($count / $columns);
        $w = intdiv($width, $columns);
        $h = intdiv($height, $rows);

        return [$w, $h, ($index % $columns) * $w, intdiv($index, $columns) * $h];
    }

    /**
     * @param  array<int, array<string, mixed>>  $tracks
     * @param  callable(string): string  $resolve
     * @return list<string>
     */
    protected function inputs(array $tracks, callable $resolve): array
    {
        $arguments = [];

        foreach ($tracks as $track) {
            array_push($arguments, '-i', $resolve((string) $track['file']));
        }

        return $arguments;
    }

    protected function seconds(int $milliseconds): string
    {
        return number_format($milliseconds / 1000, 3, '.', '');
    }

    protected function binary(): string
    {
        return (string) config('calls.ffmpeg.binary', 'ffmpeg');
    }
}

<?php

use App\Enums\Calls\ExportKind;
use App\Models\Calls\CallRecording;
use App\Services\Calls\RecordingExporter;

/**
 * The exporter turns a manifest into an FFmpeg invocation. These tests pin the properties
 * that matter: tracks land where they were on the meeting timeline, and the shared screen is
 * copied rather than re-encoded.
 */
beforeEach(function () {
    $this->exporter = app(RecordingExporter::class);
    $this->resolve = fn (string $file) => '/rec/'.$file;

    $this->recording = CallRecording::factory()->ready()->make(['duration_ms' => 60000, 'manifest' => ['tracks' => [
        ['file' => 'a.webm', 'kind' => 'audio', 'source' => 'microphone', 'start_ms' => 0, 'duration_ms' => 60000],
        ['file' => 'b.webm', 'kind' => 'audio', 'source' => 'microphone', 'start_ms' => 12500, 'duration_ms' => 40000],
        ['file' => 's.webm', 'kind' => 'video', 'source' => 'screen_share', 'start_ms' => 5000, 'duration_ms' => 50000],
        ['file' => 'c.webm', 'kind' => 'video', 'source' => 'camera', 'start_ms' => 1000, 'duration_ms' => 59000],
    ]]]);

    $this->filter = fn (array $command) => $command[array_search('-filter_complex', $command, true) + 1];
});

test('the audio export delays each voice to where it started and mixes without changing levels', function () {
    $command = $this->exporter->command($this->recording, ExportKind::Audio, $this->resolve, '/out/a.m4a');

    expect($command)->toContain('/rec/a.webm', '/rec/b.webm')->not->toContain('/rec/s.webm')
        ->and(($this->filter)($command))
        ->toContain('[0:a]aresample=48000,adelay=0:all=1[a0]')
        ->toContain('[1:a]aresample=48000,adelay=12500:all=1[a1]')
        ->toContain('amix=inputs=2:normalize=0')
        ->and(end($command))->toBe('/out/a.m4a');
});

test('the screen export copies the video untouched and trims audio that began before the share', function () {
    $command = $this->exporter->command($this->recording, ExportKind::Screen, $this->resolve, '/out/s.webm');
    $videoCodec = $command[array_search('-c:v', $command, true) + 1];

    expect($videoCodec)->toBe('copy')
        ->and($command[array_search('-i', $command, true) + 1])->toBe('/rec/s.webm')
        ->and(($this->filter)($command))
        ->toContain('[1:a]aresample=48000,atrim=start=5.000,asetpts=PTS-STARTPTS[a0]')   // started 5 s before the share
        ->toContain('[2:a]aresample=48000,adelay=7500:all=1[a1]')                          // started 7.5 s into it
        ->and($command[array_search('-t', $command, true) + 1])->toBe('50.000');
});

test('the combined export places the screen for exactly the time it was shared, with the camera on top', function () {
    $filter = ($this->filter)($this->exporter->command($this->recording, ExportKind::Combined, $this->resolve, '/out/c.mp4'));

    expect($filter)
        ->toContain('color=c=0x101014:s=1920x1080:r=15:d=60.000[base]')
        ->toContain("overlay=0:0:enable='between(t,5.000,55.000)'")
        ->toContain("overlay=1608:24:enable='between(t,1.000,60.000)'");
});

test('a single voice needs no mixer', function () {
    $recording = CallRecording::factory()->make(['manifest' => ['tracks' => [['file' => 'a.webm', 'kind' => 'audio', 'source' => 'microphone', 'start_ms' => 300, 'duration_ms' => 1000]]]]);

    expect(($this->filter)($this->exporter->command($recording, ExportKind::Audio, $this->resolve, '/out/a.m4a')))
        ->toBe('[0:a]aresample=48000,adelay=300:all=1[a0];[a0]anull[aout]');
});

test('exports that have nothing to work with say why', function (ExportKind $kind, array $tracks, string $message) {
    $recording = CallRecording::factory()->make(['manifest' => ['tracks' => $tracks]]);

    expect(fn () => $this->exporter->command($recording, $kind, $this->resolve, '/out/x'))->toThrow(RuntimeException::class, $message);
})->with([
    'audio without audio' => [ExportKind::Audio, [['file' => 'c.webm', 'kind' => 'video', 'source' => 'camera', 'start_ms' => 0, 'duration_ms' => 1]], 'no audio tracks'],
    'screen without a share' => [ExportKind::Screen, [['file' => 'a.webm', 'kind' => 'audio', 'source' => 'microphone', 'start_ms' => 0, 'duration_ms' => 1]], 'Nobody shared a screen'],
    'combined without video' => [ExportKind::Combined, [['file' => 'a.webm', 'kind' => 'audio', 'source' => 'microphone', 'start_ms' => 0, 'duration_ms' => 1]], 'no video to combine'],
]);

<?php

namespace Database\Factories\Calls;

use App\Enums\Calls\RecordingMode;
use App\Enums\Calls\RecordingStatus;
use App\Models\Calls\Call;
use App\Models\Calls\CallRecording;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CallRecording>
 */
class CallRecordingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'call_id' => Call::factory(),
            'mode' => RecordingMode::Full,
            'status' => RecordingStatus::Recording,
            'disk' => 'local',
            'path' => Str::uuid().'/'.Str::uuid(),
            'started_at' => now()->subMinutes(2),
            'heartbeat_at' => now(),
        ];
    }

    /**
     * A finished recording with one voice and one shared screen.
     */
    public function ready(): static
    {
        return $this->state([
            'status' => RecordingStatus::Ready,
            'ended_at' => now(),
            'duration_ms' => 60000,
            'size_bytes' => 2048,
            'manifest' => [
                'version' => 1,
                'status' => 'complete',
                'duration_ms' => 60000,
                'size_bytes' => 2048,
                'tracks' => [
                    ['file' => '0001-microphone-p_a.webm', 'kind' => 'audio', 'source' => 'microphone', 'codec' => 'opus', 'participant' => ['identity' => 'p_a', 'name' => 'Ada'], 'start_ms' => 0, 'duration_ms' => 60000, 'size_bytes' => 1024],
                    ['file' => '0002-screen_share-p_a.webm', 'kind' => 'video', 'source' => 'screen_share', 'codec' => 'vp9', 'participant' => ['identity' => 'p_a', 'name' => 'Ada'], 'start_ms' => 5000, 'duration_ms' => 50000, 'width' => 1920, 'height' => 1080, 'size_bytes' => 1024],
                ],
                'events' => [],
            ],
        ]);
    }
}

<?php

namespace App\Enums\Calls;

enum RecordingMode: string
{
    /** Every microphone, camera and shared screen. */
    case Full = 'full';
    /** Shared screens with the meeting audio; cameras are left out. */
    case Screen = 'screen';
    case Audio = 'audio';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full meeting',
            self::Screen => 'Screen and audio',
            self::Audio => 'Audio only',
        };
    }
}

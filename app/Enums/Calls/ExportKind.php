<?php

namespace App\Enums\Calls;

enum ExportKind: string
{
    /** Every voice mixed into one audio file. */
    case Audio = 'audio';
    /** The shared screen, untouched, with the mixed meeting audio. */
    case Screen = 'screen';
    /** One video: the shared screen (or the speaker) with cameras alongside. */
    case Combined = 'combined';

    public function label(): string
    {
        return match ($this) {
            self::Audio => 'Audio only',
            self::Screen => 'Screen recording',
            self::Combined => 'Combined video',
        };
    }

    public function extension(): string
    {
        return match ($this) {
            self::Audio => 'm4a',
            self::Screen => 'webm',
            self::Combined => 'mp4',
        };
    }
}

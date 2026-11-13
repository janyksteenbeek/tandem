<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Media server (self-hosted LiveKit)
    |--------------------------------------------------------------------------
    |
    | `url` is how this application reaches the server API; `public_url` is the
    | WebSocket address browsers connect to (wss://meet.example.com). The key
    | pair signs the short-lived access tokens handed to participants and
    | verifies the webhooks the server sends back.
    |
    */

    'livekit' => [
        'url' => env('LIVEKIT_URL', 'http://127.0.0.1:7880'),
        'public_url' => env('LIVEKIT_PUBLIC_URL', 'ws://localhost:7880'),
        'api_key' => env('LIVEKIT_API_KEY'),
        'api_secret' => env('LIVEKIT_API_SECRET'),
        'timeout' => (int) env('LIVEKIT_TIMEOUT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Access tokens
    |--------------------------------------------------------------------------
    |
    | A media token only has to live long enough to connect; the media server
    | refreshes it for connected participants on its own.
    |
    */

    'token_ttl' => (int) env('CALLS_TOKEN_TTL', 600),

    'max_participants' => (int) env('CALLS_MAX_PARTICIPANTS', 12),

    // Seconds an empty room is kept before the media server closes it and the call ends.
    'empty_timeout' => (int) env('CALLS_EMPTY_TIMEOUT', 120),

    /*
    |--------------------------------------------------------------------------
    | Recorder
    |--------------------------------------------------------------------------
    |
    | The recorder built into the media server (media/tandem-media) joins a room
    | as a hidden subscriber and writes every track to its own WebM file. `path` is the directory both the
    | recorder and this application can read: it is relative to the recordings
    | disk root on this side and RECORDINGS_DIR on the recorder's side.
    |
    */

    'recorder' => [
        'url' => env('CALLS_RECORDER_URL', 'http://127.0.0.1:7890'),
        'secret' => env('CALLS_RECORDER_SECRET'),
        'timeout' => (int) env('CALLS_RECORDER_TIMEOUT', 5),
        // A recording that has not reported for this long is considered stalled.
        'stall_seconds' => (int) env('CALLS_RECORDER_STALL_SECONDS', 90),
    ],

    'recordings' => [
        'disk' => env('CALLS_RECORDINGS_DISK', 'local'),
        'path' => env('CALLS_RECORDINGS_PATH', 'calls/recordings'),
        // Days a recording is kept when the organization has not chosen; null keeps them.
        'retention_days' => env('CALLS_RECORDING_RETENTION_DAYS'),
        'share_link_days' => (int) env('CALLS_SHARE_LINK_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Exports
    |--------------------------------------------------------------------------
    |
    | Mixed audio and combined videos are produced after the call by FFmpeg on
    | the queue, never while a meeting is running. Without the binary the
    | per-track playback and downloads keep working; exports report that
    | FFmpeg is missing.
    |
    */

    'ffmpeg' => [
        'binary' => env('CALLS_FFMPEG_BINARY', 'ffmpeg'),
        'timeout' => (int) env('CALLS_FFMPEG_TIMEOUT', 3600),
        'threads' => (int) env('CALLS_FFMPEG_THREADS', 1),
    ],

    'files' => [
        'max_upload_bytes' => (int) env('CALLS_MAX_UPLOAD_BYTES', 100 * 1024 * 1024),
        // How long a file or recording link in a transcript email stays valid.
        'link_days' => (int) env('CALLS_FILE_LINK_DAYS', 14),
    ],
];

/** Tandem Calls: the shapes the backend sends. Mirrors app/Http/Resources/Calls. */

export type CallStatus = 'pending' | 'live' | 'ended';
export type CallParticipantRole = 'host' | 'member' | 'guest';
export type CallParticipantStatus =
    | 'invited'
    | 'waiting'
    | 'admitted'
    | 'joined'
    | 'left'
    | 'denied'
    | 'removed';
export type RecordingMode = 'full' | 'screen' | 'audio';
export type RecordingStatus =
    | 'starting'
    | 'recording'
    | 'processing'
    | 'ready'
    | 'partial'
    | 'failed';
export type ExportKind = 'audio' | 'screen' | 'combined';
export type ExportStatus = 'queued' | 'processing' | 'ready' | 'failed';
/** Named apart from mail's `DeliveryStatus`, which the types barrel also exports. */
export type TranscriptDeliveryStatus = 'queued' | 'sent' | 'failed';

export type CallSettings = {
    waiting_room: boolean;
    locked: boolean;
    screen_share: 'everyone' | 'hosts';
    recording: 'hosts' | 'members';
    guest_uploads: boolean;
    auto_email_transcript: boolean;
};

/** CallResource */
export type Call = {
    uuid: string;
    code: string;
    title: string | null;
    display_title: string;
    status: CallStatus;
    settings: CallSettings;
    has_password: boolean;
    join_url: string;
    host: {
        id: number;
        name: string;
        avatar: string | null;
        initials: string;
    } | null;
    organization: { name: string; slug: string };
    message_count: number;
    file_count: number;
    peak_participants: number;
    duration_seconds: number | null;
    started_at: string | null;
    ended_at: string | null;
    created_at: string | null;
};

/** Call rows on the Calls home and in "linked calls" lists. */
export type CallSummary = Call & {
    attendee_count: number;
    recording_count: number;
    url: string;
};

/** CallParticipantResource: what everyone in the room knows about each other. */
export type CallParticipant = {
    uuid: string;
    /** Identity on the media server: `p_<uuid>`. */
    identity: string;
    name: string;
    initials: string;
    avatar: string | null;
    role: CallParticipantRole;
    status: CallParticipantStatus;
    is_guest: boolean;
    joined_at: string | null;
    left_at: string | null;
};

export type CallAbilities = {
    manage: boolean;
    share_screen: boolean;
    record: boolean;
    chat: boolean;
    upload: boolean;
    invite: boolean;
};

export type CallMe = CallParticipant & {
    can: CallAbilities;
    last_read_message_id: number | null;
    recording_consent_at: string | null;
};

/** CallFileResource */
export type CallFile = {
    uuid: string;
    filename: string;
    mime_type: string;
    size_bytes: number;
    is_image: boolean;
    url: string;
    download_url: string;
    created_at: string | null;
};

/** CallMessageResource. `id` orders messages and drives "everything after" catch-up. */
export type CallMessage = {
    id: number;
    uuid: string;
    client_id: string | null;
    body: string | null;
    deleted: boolean;
    author: {
        uuid: string;
        name: string;
        initials: string;
        avatar: string | null;
        is_guest: boolean;
    } | null;
    parent: {
        uuid: string;
        author: string | null;
        excerpt: string | null;
    } | null;
    reactions: {
        emoji: string;
        count: number;
        participants: { uuid: string; name: string }[];
    }[];
    files: CallFile[];
    created_at: string | null;
    edited_at: string | null;
};

/** CallRecordingResource */
export type CallRecording = {
    uuid: string;
    mode: RecordingMode;
    mode_label: string;
    status: RecordingStatus;
    is_active: boolean;
    is_playable: boolean;
    started_at: string | null;
    ended_at: string | null;
    duration_ms: number;
    size_bytes: number;
    track_count: number;
    has_screen_share: boolean;
    started_by: string | null;
    failure_reason: string | null;
    warning: string | null;
    expires_at: string | null;
};

export type RecordingExport = {
    uuid: string;
    kind: ExportKind;
    label: string;
    status: ExportStatus;
    size_bytes: number;
    error: string | null;
    created_at: string | null;
};

export type RecordingTrackSource =
    | 'microphone'
    | 'camera'
    | 'screen_share'
    | 'screen_share_audio';

/** One per-track WebM file on the recording's shared timeline. */
export type RecordingTrack = {
    file: string;
    kind: 'audio' | 'video';
    source: RecordingTrackSource;
    codec: string;
    participant: { identity: string; name: string };
    /** Where this track starts on the recording timeline. */
    start_ms: number;
    duration_ms: number;
    width: number;
    height: number;
    size_bytes: number;
    /** Stream URL the viewer is authorised for (supports range requests). */
    url: string;
};

export type RecordingEvent = {
    at_ms: number;
    type:
        | 'participant_joined'
        | 'participant_left'
        | 'track_muted'
        | 'track_unmuted'
        | 'active_speaker'
        | 'recorder_restarted';
    participant?: string;
    source?: RecordingTrackSource;
};

/** RecordingPlayback::describe(): a recording plus everything the player needs. */
export type RecordingPlayback = CallRecording & {
    title: string;
    tracks: RecordingTrack[];
    events: RecordingEvent[];
};

// --- /meet/{code} ------------------------------------------------------------------------

/** `call` prop of pages/meet/Room.vue: what anyone holding the link may know. */
export type MeetCall = {
    /** Only set once the viewer has been let in; needed for the realtime channel. */
    uuid: string | null;
    code: string;
    title: string;
    organization: string;
    status: CallStatus;
    has_password: boolean;
    waiting_room: boolean;
    locked: boolean;
    is_recording: boolean;
    history_url: string | null;
};

/** `viewer` prop of pages/meet/Room.vue. */
export type MeetViewer = {
    name: string | null;
    avatar: string | null;
    is_signed_in: boolean;
    role: CallParticipantRole;
    status: CallParticipantStatus | null;
    /** A valid personal invitation token from the URL; send it back when joining. */
    invite: string | null;
    calls_url: string | null;
};

/** POST meet/{code}/join and GET meet/{code}/status */
export type JoinStanding = {
    status: CallParticipantStatus;
    participant: CallParticipant;
    call_uuid: string | null;
    call_status?: CallStatus;
};

/** Body of a refused join (HTTP 403 / 422). */
export type JoinRefusal = {
    message: string;
    reason: 'ended' | 'locked' | 'password' | 'full' | 'removed';
};

/** POST meet/{code}/credentials */
export type MediaCredentials = {
    url: string;
    token: string;
    identity: string;
};

/** GET meet/{code}/state */
export type RoomState = {
    call: Call;
    me: CallMe;
    participants: CallParticipant[];
    recording: CallRecording | null;
    unread_count: number;
};

/**
 * Reverb events on the private channel `call.{uuid}`:
 *   `.call.updated`        CallUpdatedEvent
 *   `.participant.updated` { participant: CallParticipant }
 *   `.message.created` / `.message.updated` / `.message.deleted`  { message: CallMessage }
 */
export type CallUpdatedEvent = {
    /**
     * `started`, `settings`, `renamed`, `ended`, `recording.starting`, `recording.started`,
     * `recording.stopped`, `recording.warning`, `recording.finished`, `recording.failed`.
     */
    reason: string;
    call: Call;
    recording: CallRecording | null;
    by?: string | null;
    message?: string | null;
};

// --- /{organization}/calls ---------------------------------------------------------------

export type CallAttendee = {
    uuid: string;
    name: string;
    email: string | null;
    initials: string;
    avatar: string | null;
    role: CallParticipantRole;
    status: CallParticipantStatus;
    attended: boolean;
    joined_at: string | null;
    left_at: string | null;
};

export type TranscriptDelivery = {
    uuid: string;
    email: string;
    name: string | null;
    status: TranscriptDeliveryStatus;
    error: string | null;
    is_automatic: boolean;
    sent_by: string | null;
    sent_at: string | null;
    created_at: string | null;
};

export type CallLinkItem = {
    id: number;
    type: 'issue' | 'project';
    key: string;
    title: string;
    url: string;
};

export type CallRecordingWithExports = CallRecording & {
    has_share_link: boolean;
    share_expires_at: string | null;
    exports: RecordingExport[];
};

export type CallOrganizationSettings = {
    defaults: CallSettings;
    recording_retention_days: number | null;
};

/** GET /{organization}/calls/diagnostics (organization admins). */
export type CallDiagnostics = {
    media: {
        configured: boolean;
        reachable: boolean;
        error: string | null;
        public_url: string;
        rooms: {
            title: string | null;
            code: string | null;
            participants: number;
            publishers: number;
            recording: boolean;
        }[];
    };
    recorder: {
        configured: boolean;
        stats: {
            version?: string;
            uptime_s?: number;
            active_recordings?: number;
            disk_free_bytes?: number;
            disk_total_bytes?: number;
            mem_alloc_bytes?: number;
            mem_sys_bytes?: number;
            goroutines?: number;
        } | null;
    };
    storage: { free_bytes: number; total_bytes: number } | null;
    exports: { ffmpeg: boolean };
    recordings: {
        active: number;
        processing: number;
        failed_last_week: number;
        stored_bytes: number;
    };
    recent_errors: {
        action: string;
        reason: string | null;
        at: string | null;
    }[];
};

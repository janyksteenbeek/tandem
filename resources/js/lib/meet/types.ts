import type {
    LocalTrackPublication,
    RemoteTrackPublication,
    Track,
} from 'livekit-client';
import type { CallMessage, CallParticipantRole } from '@/types';

/** Client-only shapes of the meeting room. The backend contract lives in `@/types/calls`. */

export type VideoQualitySetting = 'auto' | 'standard' | 'high';

/** What the browser remembers about how someone likes to join. */
export type DevicePreferences = {
    audioInputId: string | null;
    videoInputId: string | null;
    audioOutputId: string | null;
    micEnabled: boolean;
    cameraEnabled: boolean;
    noiseSuppression: boolean;
    videoQuality: VideoQualitySetting;
    /** Share screens tuned for motion (video, animation) instead of sharp text. */
    screenShareMotion: boolean;
    displayName: string;
};

export type DeviceKind = 'audioinput' | 'videoinput' | 'audiooutput';

export type DeviceOption = { id: string; label: string };

/** Simple three-step health everyone understands. */
export type QualityLevel = 'good' | 'fair' | 'poor' | 'lost' | 'unknown';

export type ParticipantMeta = {
    uuid: string;
    role: CallParticipantRole;
    guest: boolean;
    avatar: string | null;
    initials: string;
};

/**
 * A plain snapshot of one person in the media room, rebuilt whenever the media server says
 * something changed. Track objects are kept raw (never made reactive).
 */
export type RoomParticipant = {
    identity: string;
    name: string;
    isLocal: boolean;
    meta: ParticipantMeta;
    micEnabled: boolean;
    cameraEnabled: boolean;
    cameraTrack: Track | null;
    cameraPublication: RemoteTrackPublication | LocalTrackPublication | null;
    /** The server paused this camera for us because bandwidth ran short. */
    cameraPaused: boolean;
    screenTrack: Track | null;
    screenAudioEnabled: boolean;
    audioTracks: Track[];
    isSpeaking: boolean;
    quality: QualityLevel;
    joinedAt: number;
};

/** A shared screen that can take the stage. */
export type ScreenShare = {
    id: string;
    identity: string;
    name: string;
    isLocal: boolean;
    track: Track;
};

export type MessageState = 'sent' | 'pending' | 'failed';

/** A chat message as the room holds it: server data plus where an optimistic send stands. */
export type LocalMessage = CallMessage & {
    state: MessageState;
    error?: string | null;
};

export type UploadState = 'uploading' | 'done' | 'failed';

export type PendingUpload = {
    key: string;
    name: string;
    size: number;
    progress: number;
    state: UploadState;
    error: string | null;
    /** Set once the server stored the file. */
    uuid: string | null;
    previewUrl: string | null;
    abort: () => void;
};

export type JoinPhase =
    | 'prejoin'
    | 'waiting'
    | 'connecting'
    | 'room'
    | 'left'
    | 'ended'
    | 'denied'
    | 'removed';

export type ConnectionPhase =
    | 'idle'
    | 'connecting'
    | 'connected'
    | 'reconnecting'
    | 'disconnected';

export type ConnectionStats = {
    /** Audio packets lost, as a percentage of what should have arrived (worst direction). */
    audioLossPercent: number | null;
    jitterMs: number | null;
    roundTripMs: number | null;
    sendKbps: number | null;
    receiveKbps: number | null;
    /** True when media flows through the TURN relay instead of directly. */
    relayed: boolean | null;
    protocol: string | null;
    updatedAt: number;
};

package recorder

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"path/filepath"
	"regexp"
	"runtime/debug"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/livekit/protocol/auth"
	"github.com/livekit/protocol/livekit"
	lksdk "github.com/livekit/server-sdk-go/v2"
	"github.com/pion/webrtc/v4"

	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/config"
	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/manifest"
	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/webhook"
	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/webm"
)

// Recording modes.
const (
	ModeFull   = "full"
	ModeScreen = "screen"
	ModeAudio  = "audio"
)

// Track sources as they appear in file names and the manifest.
const (
	SourceMicrophone       = "microphone"
	SourceCamera           = "camera"
	SourceScreenShare      = "screen_share"
	SourceScreenShareAudio = "screen_share_audio"
)

// Statuses reported by the HTTP API.
const (
	StatusStarting   = "starting"
	StatusRecording  = "recording"
	StatusFinalizing = "finalizing"
	StatusComplete   = "complete"
	StatusPartial    = "partial"
	StatusFailed     = "failed"
)

// stopSuspend finalises the files but leaves the recording open, so that the next recorder
// process continues it. It is what a graceful shutdown of the standalone recorder uses: the
// calls go on while it is away.
const stopSuspend = "suspend"

const (
	progressInterval  = 15 * time.Second
	rejoinFor         = 20 * time.Second
	speakerInterval   = time.Second
	finalizeTimeout   = 20 * time.Second
	identityPrefix    = "recorder_"
	tokenValidity     = 24 * time.Hour
	connectTimeout    = 10 * time.Second
	firstJoinAttempts = 5
)

var unsafeFileChars = regexp.MustCompile(`[^A-Za-z0-9_-]+`)

// Session is one recording: a hidden participant in a room plus the files it writes.
type Session struct {
	cfg      config.Config
	log      *slog.Logger
	notifier *webhook.Notifier

	id   string
	mode string
	dir  string

	clock sfuClock

	mu          sync.Mutex
	state       *manifest.State
	manifest    *manifest.Manifest
	status      string
	startedAt   time.Time
	room        *lksdk.Room
	tracks      map[string]*trackRecorder
	finished    []*trackRecorder
	fileSeq     int
	dirty       bool
	stopping    bool
	failure     string
	emptySince  time.Time
	lastSpeaker string
	speakerAt   time.Time

	// A reconnect that ended tracks starts a new part with the next file.
	reconnecting bool
	tracksLost   int
	newPart      bool
	stateDirty   bool

	// endedAt overrides "now" as the end of a recording that is closed after the fact.
	endedAt time.Time

	stopWanted  atomic.Bool
	outcomeSent atomic.Bool
	stopCh      chan string
	lostCh      chan struct{}
	done        chan struct{}
}

// newSession prepares a recording. resume carries the manifest of a recording that an
// earlier recorder process left unfinished.
func newSession(cfg config.Config, log *slog.Logger, notifier *webhook.Notifier, state *manifest.State, resume *manifest.Manifest) *Session {
	s := &Session{
		cfg:       cfg,
		log:       log.With("recording", state.ID, "room", state.Room),
		notifier:  notifier,
		id:        state.ID,
		mode:      state.Mode,
		dir:       filepath.Join(cfg.RecordingsDir, filepath.FromSlash(state.Path)),
		state:     state,
		status:    StatusStarting,
		startedAt: state.StartedAt,
		tracks:    make(map[string]*trackRecorder),
		stopCh:    make(chan string, 1),
		lostCh:    make(chan struct{}, 1),
		done:      make(chan struct{}),
	}

	if resume != nil {
		s.manifest = resume
		s.fileSeq = highestSequence(resume)
	} else {
		s.manifest = manifest.New(state.ID, state.Room, state.Mode, state.StartedAt)
	}

	return s
}

// Stop asks the recording to finalise. It returns immediately; Done closes when it has.
func (s *Session) Stop(reason string) {
	s.stopWanted.Store(true)

	select {
	case s.stopCh <- reason:
	default: // a stop is already pending
	}
}

// Done closes once the recording is finalised.
func (s *Session) Done() <-chan struct{} { return s.done }

// Status returns the API status and a copy of the manifest.
func (s *Session) Status() (string, *manifest.Manifest) {
	s.mu.Lock()
	defer s.mu.Unlock()

	s.refreshTotals(time.Now())

	return s.status, s.manifest.Clone()
}

// Active reports whether the recording is still capturing or finalising.
func (s *Session) Active() bool {
	select {
	case <-s.done:
		return false
	default:
		return true
	}
}

// Snapshot is the per-recording part of GET /stats.
type Snapshot struct {
	ID          string `json:"id"`
	Room        string `json:"room"`
	Mode        string `json:"mode"`
	StartedAt   string `json:"started_at"`
	DurationMs  int64  `json:"duration_ms"`
	SizeBytes   int64  `json:"size_bytes"`
	Tracks      int    `json:"tracks"`
	Packets     uint64 `json:"packets"`
	PacketsLost uint64 `json:"packets_lost"`
}

func (s *Session) snapshot() Snapshot {
	s.mu.Lock()
	defer s.mu.Unlock()

	snap := Snapshot{
		ID:         s.id,
		Room:       s.state.Room,
		Mode:       s.mode,
		StartedAt:  manifest.FormatTime(s.startedAt),
		DurationMs: time.Since(s.startedAt).Milliseconds(),
		Tracks:     len(s.tracks),
	}

	for _, t := range s.tracks {
		snap.SizeBytes += t.sizeBytes.Load()
		snap.Packets += t.packets.Load()
		snap.PacketsLost += t.lost.Load()
	}

	for _, t := range s.finished {
		snap.SizeBytes += t.sizeBytes.Load()
		snap.Packets += t.packets.Load()
		snap.PacketsLost += t.lost.Load()
	}

	return snap
}

// start is the goroutine a recording lives on. A bug in the recorder must never take the
// process down (in the combined binary that would end every call): a panic is turned into a
// failed recording that keeps whatever reached the disk.
func (s *Session) start(work func()) {
	defer close(s.done)
	defer func() {
		if cause := recover(); cause != nil {
			s.crashed("recording", cause)

			// Close the files and tell Tandem. Guarded, because the state the panic left
			// behind is unknown.
			defer func() {
				if again := recover(); again != nil {
					s.log.Error("finalising after a panic failed", "panic", fmt.Sprint(again))
				}
			}()

			s.finalize(manifest.StopError)
		}
	}()

	work()
}

// crashed records a recovered panic and marks the recording as failed.
func (s *Session) crashed(where string, cause any) {
	s.log.Error("recovered from a panic in the recorder", "where", where, "panic", fmt.Sprint(cause), "stack", string(debug.Stack()))
	s.setFailure("The recorder hit an internal error; the recording was closed with what had been captured.")
}

// guard is deferred by callbacks that run on goroutines the recorder does not own. It turns
// a panic into a failed recording.
func (s *Session) guard(where string) {
	if cause := recover(); cause != nil {
		s.crashed(where, cause)
		s.Stop(manifest.StopError)
	}
}

// run drives the recording from joining the room to the terminal webhook.
func (s *Session) run(resumed bool) {
	if err := s.persist(); err != nil {
		s.fail(fmt.Sprintf("The recording folder is not writable: %v", err))

		return
	}

	if err := s.join(firstJoinAttempts); err != nil {
		if resumed {
			// The room ended while the recorder was down: keep what was captured.
			s.finalize(manifest.StopRecovered)

			return
		}

		s.fail(fmt.Sprintf("Could not join the room: %v", err))

		return
	}

	s.mu.Lock()
	s.status = StatusRecording
	s.mu.Unlock()

	if resumed {
		s.event(manifest.Event{Type: "recorder_restarted"})
		s.warn(webhook.WarnRecovered, "The recorder restarted and picked the recording back up.")
	} else {
		s.notifier.Send(webhook.EventStarted, s.id, map[string]any{"started_at": manifest.FormatTime(s.startedAt)}, nil)
	}

	s.log.Info("recording", "mode", s.mode, "part", s.state.Part, "resumed", resumed)

	housekeeping := time.NewTicker(time.Second)
	defer housekeeping.Stop()

	progress := time.NewTicker(progressInterval)
	defer progress.Stop()

	deadline := time.NewTimer(time.Until(s.startedAt.Add(s.cfg.MaxDuration)))
	defer deadline.Stop()

	for {
		select {
		case reason := <-s.stopCh:
			s.finalize(reason)

			return

		case <-deadline.C:
			s.log.Warn("maximum duration reached")
			s.finalize(manifest.StopMaxDuration)

			return

		case <-s.lostCh:
			if !s.rejoin() {
				s.setFailure("The connection to the media server was lost and could not be restored.")
				s.finalize(manifest.StopError)

				return
			}

		case now := <-housekeeping.C:
			if s.roomEmptyFor(now) >= s.cfg.EmptyGrace {
				s.log.Info("room is empty")
				s.finalize(manifest.StopRoomClosed)

				return
			}

			s.flush(false)

		case <-progress.C:
			s.flush(true)

			free, _, _ := diskUsage(s.cfg.RecordingsDir)
			snap := s.snapshot()

			s.notifier.Send(webhook.EventProgress, s.id, map[string]any{
				"duration_ms":     snap.DurationMs,
				"size_bytes":      snap.SizeBytes,
				"tracks":          snap.Tracks,
				"disk_free_bytes": free,
			}, nil)
		}
	}
}

// join connects to the room as a hidden, subscribe-only participant.
func (s *Session) join(attempts int) error {
	token, err := s.token()
	if err != nil {
		return err
	}

	var room *lksdk.Room

	for attempt := 1; ; attempt++ {
		room = lksdk.NewRoom(s.callbacks())

		ctx, cancel := context.WithTimeout(context.Background(), connectTimeout)
		err = room.JoinWithContextAndToken(ctx, s.cfg.LiveKitURL, token,
			lksdk.WithAutoSubscribe(false),
			lksdk.WithConnectTimeout(connectTimeout),
		)
		cancel()

		if err == nil {
			break
		}

		if attempt >= attempts || s.stopRequested() {
			return err
		}

		s.log.Warn("could not join the room, retrying", "attempt", attempt, "error", err)
		time.Sleep(time.Duration(attempt) * time.Second)
	}

	s.mu.Lock()
	s.room = room
	s.emptySince = time.Time{}
	s.mu.Unlock()

	for _, participant := range room.GetRemoteParticipants() {
		s.event(manifest.Event{Type: "participant_joined", Participant: participant.Identity()})

		for _, publication := range participant.TrackPublications() {
			if remote, ok := publication.(*lksdk.RemoteTrackPublication); ok {
				s.subscribe(remote)
			}
		}
	}

	return nil
}

// rejoin replaces a dead connection with a new one. Tracks recorded after it land in new
// files, marked as the next part of the same recording.
func (s *Session) rejoin() bool {
	s.warn(webhook.WarnReconnecting, "The connection to the media server was lost; trying to reconnect.")
	s.stopTracks()

	s.mu.Lock()
	s.state.Part++
	s.mu.Unlock()

	deadline := time.Now().Add(rejoinFor)

	for time.Now().Before(deadline) && !s.stopRequested() {
		if err := s.join(1); err == nil {
			s.event(manifest.Event{Type: "recorder_restarted"})
			_ = s.persist()
			s.warn(webhook.WarnRecovered, "Reconnected to the media server; the recording continues.")

			return true
		}

		time.Sleep(3 * time.Second)
	}

	return false
}

func (s *Session) token() (string, error) {
	yes, no := true, false

	return auth.NewAccessToken(s.cfg.LiveKitKey, s.cfg.LiveKitSecret).
		SetIdentity(identityPrefix + s.id).
		SetName("Recorder").
		SetValidFor(tokenValidity).
		SetVideoGrant(&auth.VideoGrant{
			RoomJoin:       true,
			Room:           s.state.Room,
			Hidden:         true,
			Recorder:       true,
			CanSubscribe:   &yes,
			CanPublish:     &no,
			CanPublishData: &no,
		}).
		ToJWT()
}

func (s *Session) callbacks() *lksdk.RoomCallback {
	return &lksdk.RoomCallback{
		OnDisconnectedWithReason: func(reason lksdk.DisconnectionReason) {
			defer s.guard("disconnected")
			s.onDisconnected(reason)
		},
		OnReconnecting: func() {
			defer s.guard("reconnecting")

			s.mu.Lock()
			s.reconnecting, s.tracksLost = true, 0
			s.mu.Unlock()

			s.warn(webhook.WarnReconnecting, "The connection to the media server was interrupted.")
		},
		OnReconnected: func() {
			defer s.guard("reconnected")

			s.mu.Lock()
			s.reconnecting = false
			s.newPart = s.newPart || s.tracksLost > 0
			s.mu.Unlock()

			s.warn(webhook.WarnRecovered, "The connection to the media server is back.")
		},
		OnParticipantConnected: func(p *lksdk.RemoteParticipant) {
			defer s.guard("participant connected")

			s.mu.Lock()
			s.emptySince = time.Time{}
			s.mu.Unlock()

			s.event(manifest.Event{Type: "participant_joined", Participant: p.Identity()})
		},
		OnParticipantDisconnected: func(p *lksdk.RemoteParticipant) {
			defer s.guard("participant disconnected")
			s.event(manifest.Event{Type: "participant_left", Participant: p.Identity()})
		},
		OnActiveSpeakersChanged: func(speakers []lksdk.Participant) {
			defer s.guard("active speakers")
			s.onSpeakers(speakers)
		},
		ParticipantCallback: lksdk.ParticipantCallback{
			OnTrackPublished: func(pub *lksdk.RemoteTrackPublication, _ *lksdk.RemoteParticipant) {
				defer s.guard("track published")
				s.subscribe(pub)
			},
			OnTrackSubscribed: func(track *webrtc.TrackRemote, pub *lksdk.RemoteTrackPublication, rp *lksdk.RemoteParticipant) {
				defer s.guard("track subscribed")
				s.onTrackSubscribed(track, pub, rp)
			},
			OnTrackUnsubscribed: func(_ *webrtc.TrackRemote, pub *lksdk.RemoteTrackPublication, _ *lksdk.RemoteParticipant) {
				defer s.guard("track unsubscribed")
				s.endTrack(pub.SID())
			},
			OnTrackSubscriptionFailed: func(sid string, rp *lksdk.RemoteParticipant) {
				defer s.guard("subscription failed")
				s.warn(webhook.WarnTrackInterrupted, fmt.Sprintf("Could not subscribe to a track of %s.", rp.Identity()))
			},
			OnTrackMuted: func(pub lksdk.TrackPublication, p lksdk.Participant) {
				defer s.guard("track muted")
				s.onMuted(pub, p, true)
			},
			OnTrackUnmuted: func(pub lksdk.TrackPublication, p lksdk.Participant) {
				defer s.guard("track unmuted")
				s.onMuted(pub, p, false)
			},
		},
	}
}

// subscribe asks for a track when the recording mode wants it.
func (s *Session) subscribe(pub *lksdk.RemoteTrackPublication) {
	source := sourceName(pub.Source(), pub.Kind())

	if !modeIncludes(s.mode, source) || s.stopRequested() {
		return
	}

	if err := pub.SetSubscribed(true); err != nil {
		s.log.Warn("could not subscribe", "track", pub.SID(), "error", err)

		return
	}

	if pub.Kind() == lksdk.TrackKindVideo {
		_ = pub.SetVideoQuality(livekit.VideoQuality_HIGH)
	}
}

func (s *Session) onTrackSubscribed(track *webrtc.TrackRemote, pub *lksdk.RemoteTrackPublication, rp *lksdk.RemoteParticipant) {
	source := sourceName(pub.Source(), pub.Kind())

	codec := codecName(track.Codec().MimeType)
	if codec == "" {
		s.log.Warn("unsupported codec, track not recorded", "mime", track.Codec().MimeType, "track", pub.SID())
		s.warn(webhook.WarnTrackInterrupted, fmt.Sprintf(
			"The %s of %s uses %s, which cannot be recorded.", strings.ReplaceAll(source, "_", " "), rp.Identity(), track.Codec().MimeType,
		))

		return
	}

	recorder := newTrackRecorder(s, remoteTrack{track}, remoteOptions(track, pub, rp, source, codec))
	pub.OnRTCP(recorder.onRTCP)

	s.mu.Lock()
	if s.stopping {
		s.mu.Unlock()

		return
	}

	previous := s.tracks[pub.SID()]
	s.tracks[pub.SID()] = recorder
	s.mu.Unlock()

	if previous != nil {
		previous.stop()
	}

	go func() {
		recorder.run()

		s.mu.Lock()
		if s.tracks[recorder.opts.sid] == recorder {
			delete(s.tracks, recorder.opts.sid)
		}
		if s.reconnecting {
			s.tracksLost++
		}
		s.finished = append(s.finished, recorder)
		s.dirty = true
		s.mu.Unlock()
	}()
}

func (s *Session) endTrack(sid string) {
	s.mu.Lock()
	recorder := s.tracks[sid]
	s.mu.Unlock()

	if recorder != nil {
		recorder.stop()
	}
}

func (s *Session) onMuted(pub lksdk.TrackPublication, p lksdk.Participant, muted bool) {
	s.mu.Lock()
	recorder := s.tracks[pub.SID()]
	s.mu.Unlock()

	if recorder != nil {
		recorder.setMuted(muted)
	}

	kind := "track_unmuted"
	if muted {
		kind = "track_muted"
	}

	source := sourceName(pub.Source(), pub.Kind())
	if modeIncludes(s.mode, source) {
		s.event(manifest.Event{Type: kind, Participant: p.Identity(), Source: source})
	}
}

// onSpeakers records who the dominant speaker is, at most once a second and only on change.
func (s *Session) onSpeakers(speakers []lksdk.Participant) {
	if len(speakers) == 0 {
		return
	}

	identity := speakers[0].Identity()
	now := time.Now()

	s.mu.Lock()
	changed := identity != s.lastSpeaker && now.Sub(s.speakerAt) >= speakerInterval
	if changed {
		s.lastSpeaker, s.speakerAt = identity, now
	}
	s.mu.Unlock()

	if changed {
		s.event(manifest.Event{Type: "active_speaker", Participant: identity})
	}
}

func (s *Session) onDisconnected(reason lksdk.DisconnectionReason) {
	if s.stopRequested() {
		return
	}

	var raw livekit.DisconnectReason

	s.mu.Lock()
	if s.room != nil {
		raw = s.room.DisconnectReason()
	}
	s.mu.Unlock()

	s.log.Warn("disconnected from the room", "reason", string(reason), "detail", raw.String())

	switch {
	case reason == lksdk.RoomClosed, reason == lksdk.ParticipantRemoved, raw == livekit.DisconnectReason_ROOM_DELETED:
		s.Stop(manifest.StopRoomClosed)
	case reason == lksdk.DuplicateIdentity:
		s.setFailure("Another recorder joined the room for the same recording.")
		s.Stop(manifest.StopError)
	default:
		select {
		case s.lostCh <- struct{}{}:
		default:
		}
	}
}

// roomEmptyFor returns how long the room has had no participants besides the recorder.
func (s *Session) roomEmptyFor(now time.Time) time.Duration {
	s.mu.Lock()
	defer s.mu.Unlock()

	if s.room == nil || len(s.room.GetRemoteParticipants()) > 0 {
		s.emptySince = time.Time{}

		return 0
	}

	if s.emptySince.IsZero() {
		s.emptySince = now
	}

	return now.Sub(s.emptySince)
}

// trackFailed is called by a track whose file could not be written. Storage that fails for
// one track is failing for all of them, so the recording is closed with what it has.
func (s *Session) trackFailed(t *trackRecorder, err error) {
	message := fmt.Sprintf("Writing the %s of %s failed: %v", strings.ReplaceAll(t.opts.source, "_", " "), t.describe(), err)

	s.warn(webhook.WarnTrackInterrupted, message)
	s.setFailure(message)
	s.Stop(manifest.StopError)
}

// finalize closes every file, writes the final manifest and tells Tandem how it ended.
func (s *Session) finalize(reason string) {
	s.mu.Lock()
	s.stopping = true
	s.status = StatusFinalizing
	room := s.room
	s.mu.Unlock()

	s.log.Info("finalising", "reason", reason)
	s.stopTracks()

	if room != nil {
		room.Disconnect()
	}

	now := time.Now()

	s.mu.Lock()

	if !s.endedAt.IsZero() {
		now = s.endedAt
	}

	if reason == stopSuspend {
		// Leave the recording open for the next recorder process.
		s.refreshTotals(now)
		s.mu.Unlock()
		_ = s.persist()
		s.warn(webhook.WarnReconnecting, "The recorder is shutting down; the recording continues when it is back.")

		return
	}

	complete := reason != manifest.StopError && reason != manifest.StopRecovered
	for _, track := range s.manifest.Tracks {
		complete = complete && track.Seekable
	}

	s.manifest.Status = manifest.StatusPartial
	if complete {
		s.manifest.Status = manifest.StatusComplete
	}

	s.manifest.StopReason = reason
	s.manifest.EndedAt = manifest.FormatTime(now)
	s.manifest.DurationMs = now.Sub(s.startedAt).Milliseconds()
	s.manifest.SizeBytes = s.manifest.TotalSize()

	failed := reason == manifest.StopError
	failure := s.failure

	s.status = s.manifest.Status
	s.state.Status = manifest.StateDone
	s.state.Outcome = "finished"
	s.state.Reason = reason

	if failed {
		s.status = StatusFailed
		s.state.Outcome = "failed"
		s.state.Reason = failure
	}

	final := s.manifest.Clone()
	s.mu.Unlock()

	if err := s.persist(); err != nil {
		s.log.Error("could not write the final manifest", "error", err)
	}

	s.log.Info("recording finished", "status", final.Status, "stop_reason", reason,
		"duration_ms", final.DurationMs, "bytes", final.SizeBytes, "tracks", len(final.Tracks))

	if s.outcomeSent.Swap(true) {
		return // a panic interrupted an earlier finalisation after it had reported the outcome
	}

	s.notifyOutcome(final, failed, failure)
}

// notifyOutcome sends the terminal webhook and remembers once Tandem has it.
func (s *Session) notifyOutcome(final *manifest.Manifest, failed bool, failure string) {
	acknowledged := func() {
		s.mu.Lock()
		s.state.Notified = true
		s.mu.Unlock()

		_ = s.persist()
	}

	if !failed {
		s.notifier.Send(webhook.EventFinished, s.id, map[string]any{"manifest": final}, acknowledged)

		return
	}

	if failure == "" {
		failure = "The recording stopped unexpectedly."
	}

	fields := map[string]any{"reason": failure}
	if len(final.Tracks) > 0 {
		fields["manifest"] = final
	}

	s.notifier.Send(webhook.EventFailed, s.id, fields, acknowledged)
}

// fail ends a recording that never produced anything.
func (s *Session) fail(reason string) {
	s.log.Error("recording failed", "reason", reason)

	s.mu.Lock()
	s.stopping = true
	s.failure = reason
	s.mu.Unlock()

	s.finalize(manifest.StopError)
}

func (s *Session) setFailure(reason string) {
	s.mu.Lock()
	if s.failure == "" {
		s.failure = reason
	}
	s.mu.Unlock()
}

// stopTracks finalises every open file and waits for the writers to finish.
func (s *Session) stopTracks() {
	s.mu.Lock()
	recorders := make([]*trackRecorder, 0, len(s.tracks))
	for _, recorder := range s.tracks {
		recorders = append(recorders, recorder)
	}
	s.mu.Unlock()

	for _, recorder := range recorders {
		recorder.stop()
	}

	timeout := time.After(finalizeTimeout)

	for _, recorder := range recorders {
		select {
		case <-recorder.done:
		case <-timeout:
			s.log.Error("a track did not finalise in time", "track", recorder.opts.sid)

			return
		}
	}
}

func (s *Session) stopRequested() bool {
	if s.stopWanted.Load() {
		return true
	}

	s.mu.Lock()
	defer s.mu.Unlock()

	return s.stopping
}

// closeRecovered finalises a recording whose room ended while no recorder was running.
func (s *Session) closeRecovered() {
	s.mu.Lock()
	for _, track := range s.manifest.Tracks {
		if end := s.startedAt.Add(time.Duration(track.EndMs) * time.Millisecond); end.After(s.endedAt) {
			s.endedAt = end
		}
	}
	s.manifest.Events = append(s.manifest.Events, manifest.Event{
		AtMs: s.timelineMs(time.Now()), Type: "recorder_restarted",
	})
	s.mu.Unlock()

	s.finalize(manifest.StopRecovered)
}

func (s *Session) warn(code, message string) {
	s.log.Warn("recording warning", "code", code, "message", message)
	s.notifier.Send(webhook.EventWarning, s.id, map[string]any{"code": code, "message": message}, nil)
}

// timelineMs converts a wall-clock instant to milliseconds on the recording timeline.
func (s *Session) timelineMs(at time.Time) int64 {
	return max(at.Sub(s.startedAt).Milliseconds(), 0)
}

func (s *Session) event(event manifest.Event) {
	s.mu.Lock()
	event.AtMs = s.timelineMs(time.Now())
	s.manifest.Events = append(s.manifest.Events, event)
	s.dirty = true
	s.mu.Unlock()
}

// nextFile reserves the next file name: NNNN-<source>-<identity>.webm.
func (s *Session) nextFile(source, identity string) (string, int) {
	s.mu.Lock()
	defer s.mu.Unlock()

	// The first file after a reconnect that cut tracks short opens a new part.
	if s.newPart {
		s.newPart = false
		s.state.Part++
		s.stateDirty = true
		s.manifest.Events = append(s.manifest.Events, manifest.Event{AtMs: s.timelineMs(time.Now()), Type: "recorder_restarted"})
	}

	s.fileSeq++

	return fmt.Sprintf("%04d-%s-%s.webm", s.fileSeq, source, safeName(identity)), s.state.Part
}

func (s *Session) addTrack(track manifest.Track) {
	s.mu.Lock()
	s.manifest.Tracks = append(s.manifest.Tracks, track)
	s.mu.Unlock()

	// A new file must be in the manifest on disk before it holds anything worth keeping.
	s.flush(true)
}

func (s *Session) updateTrack(file string, update func(*manifest.Track)) {
	s.mu.Lock()
	defer s.mu.Unlock()

	for i := range s.manifest.Tracks {
		if s.manifest.Tracks[i].File == file {
			update(&s.manifest.Tracks[i])
			s.dirty = true

			return
		}
	}
}

func (s *Session) removeTrack(file string) {
	s.mu.Lock()
	defer s.mu.Unlock()

	for i := range s.manifest.Tracks {
		if s.manifest.Tracks[i].File == file {
			s.manifest.Tracks = append(s.manifest.Tracks[:i], s.manifest.Tracks[i+1:]...)
			s.dirty = true

			return
		}
	}
}

// refreshTotals updates the recording-level numbers of an unfinished manifest. Callers hold mu.
func (s *Session) refreshTotals(now time.Time) {
	if s.manifest.EndedAt != "" {
		return
	}

	s.manifest.DurationMs = now.Sub(s.startedAt).Milliseconds()
	s.manifest.SizeBytes = s.manifest.TotalSize()
}

// flush writes the manifest when it changed (or regardless, when forced).
func (s *Session) flush(force bool) {
	s.mu.Lock()

	if s.stateDirty {
		s.stateDirty = false
		s.mu.Unlock()

		if err := s.persist(); err != nil {
			s.log.Error("could not write the recording state", "error", err)
		}

		return
	}

	if !s.dirty && !force {
		s.mu.Unlock()

		return
	}

	s.dirty = false
	s.refreshTotals(time.Now())
	snapshot := s.manifest.Clone()
	s.mu.Unlock()

	if err := manifest.WriteAtomic(filepath.Join(s.dir, manifest.ManifestFile), snapshot); err != nil {
		s.log.Error("could not write the manifest", "error", err)
	}
}

// persist writes both the manifest and the recorder state.
func (s *Session) persist() error {
	s.mu.Lock()
	s.dirty = false
	snapshot := s.manifest.Clone()
	state := *s.state
	s.mu.Unlock()

	return errors.Join(
		manifest.WriteAtomic(filepath.Join(s.dir, manifest.ManifestFile), snapshot),
		manifest.WriteAtomic(filepath.Join(s.dir, manifest.StateFile), &state),
	)
}

func sourceName(source livekit.TrackSource, kind lksdk.TrackKind) string {
	switch source {
	case livekit.TrackSource_MICROPHONE:
		return SourceMicrophone
	case livekit.TrackSource_CAMERA:
		return SourceCamera
	case livekit.TrackSource_SCREEN_SHARE:
		return SourceScreenShare
	case livekit.TrackSource_SCREEN_SHARE_AUDIO:
		return SourceScreenShareAudio
	}

	if kind == lksdk.TrackKindAudio {
		return SourceMicrophone
	}

	return SourceCamera
}

func modeIncludes(mode, source string) bool {
	switch mode {
	case ModeAudio:
		return source == SourceMicrophone || source == SourceScreenShareAudio
	case ModeScreen:
		return source != SourceCamera
	default:
		return true
	}
}

func codecName(mime string) string {
	switch strings.ToLower(mime) {
	case "audio/opus":
		return webm.CodecOpus
	case "video/vp8":
		return webm.CodecVP8
	case "video/vp9":
		return webm.CodecVP9
	}

	return ""
}

func safeName(identity string) string {
	name := unsafeFileChars.ReplaceAllString(identity, "_")
	if len(name) > 64 {
		name = name[:64]
	}

	if name == "" {
		name = "unknown"
	}

	return name
}

// highestSequence finds the last file number used by a recording being resumed.
func highestSequence(m *manifest.Manifest) int {
	highest := 0

	for _, track := range m.Tracks {
		var seq int
		if _, err := fmt.Sscanf(track.File, "%04d-", &seq); err == nil && seq > highest {
			highest = seq
		}
	}

	return highest
}

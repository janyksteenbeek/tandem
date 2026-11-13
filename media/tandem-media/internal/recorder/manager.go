// Package recorder joins LiveKit rooms as a hidden participant and writes every subscribed
// track to its own WebM file, untouched: no decoding, no re-encoding, no compositing.
package recorder

import (
	"context"
	"errors"
	"fmt"
	"io/fs"
	"log/slog"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"strings"
	"sync"
	"time"

	"github.com/livekit/protocol/livekit"
	lksdk "github.com/livekit/server-sdk-go/v2"

	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/config"
	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/disk"
	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/manifest"
	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/webhook"
	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/webm"
)

// Version is reported by GET /stats and -version; the build overrides it with -ldflags.
var Version = "dev"

var (
	ErrNotFound     = errors.New("recording not found")
	ErrConflict     = errors.New("a different recording already uses this id or path")
	ErrDiskLow      = errors.New("not enough free disk space to record")
	ErrShuttingDown = errors.New("the recorder is shutting down")
)

// InvalidError describes a request that cannot be accepted.
type InvalidError struct{ Message string }

func (e *InvalidError) Error() string { return e.Message }

var (
	validID   = regexp.MustCompile(`^[A-Za-z0-9_-]{1,64}$`)
	validPath = regexp.MustCompile(`^[A-Za-z0-9._/-]{1,255}$`)
)

const (
	diskCheckInterval = 5 * time.Second
	keepFinishedFor   = 10 * time.Minute
	scanDepth         = 5
)

// StartRequest is the body of POST /recordings.
type StartRequest struct {
	ID   string `json:"id"`
	Room string `json:"room"`
	Mode string `json:"mode"`
	Path string `json:"path"`
}

// Validate checks a request and returns it with the path normalised.
func (r StartRequest) Validate() (StartRequest, error) {
	if !validID.MatchString(r.ID) {
		return r, &InvalidError{"id must be 1-64 characters of letters, digits, dashes or underscores"}
	}

	if r.Room == "" || len(r.Room) > 255 {
		return r, &InvalidError{"room is required"}
	}

	switch r.Mode {
	case ModeFull, ModeScreen, ModeAudio:
	default:
		return r, &InvalidError{"mode must be full, screen or audio"}
	}

	// The path decides where files are written, so it is held to a strict shape: a relative
	// path of plain segments that stays inside the recordings directory.
	clean := filepath.ToSlash(filepath.Clean(filepath.FromSlash(r.Path)))

	switch {
	case !validPath.MatchString(r.Path),
		strings.HasPrefix(r.Path, "/"),
		!filepath.IsLocal(filepath.FromSlash(r.Path)),
		clean == ".",
		clean != strings.TrimSuffix(r.Path, "/"):
		return r, &InvalidError{"path must be a relative directory without '..' segments"}
	}

	for _, segment := range strings.Split(clean, "/") {
		if strings.HasPrefix(segment, ".") {
			return r, &InvalidError{"path segments may not start with a dot"}
		}
	}

	r.Path = clean

	return r, nil
}

// Manager owns every recording of this process.
type Manager struct {
	cfg      config.Config
	log      *slog.Logger
	notifier *webhook.Notifier
	started  time.Time

	mu       sync.Mutex
	sessions map[string]*Session
	index    map[string]string // recording id -> directory, for everything known on disk
	closing  bool

	// roomOpen asks the media server whether a room still exists; tests replace it.
	roomOpen func(name string) (exists, known bool)

	stop chan struct{}
}

// NewManager creates a manager and starts its disk watchdog.
func NewManager(cfg config.Config, log *slog.Logger, notifier *webhook.Notifier) *Manager {
	m := &Manager{
		cfg:      cfg,
		log:      log,
		notifier: notifier,
		started:  time.Now(),
		sessions: make(map[string]*Session),
		index:    make(map[string]string),
		stop:     make(chan struct{}),
	}

	m.roomOpen = m.roomExists

	go m.watch()

	return m
}

// Start begins a recording, or returns the status of the one already known under this id.
func (m *Manager) Start(request StartRequest) (string, error) {
	request, err := request.Validate()
	if err != nil {
		return "", err
	}

	dir := filepath.Join(m.cfg.RecordingsDir, filepath.FromSlash(request.Path))

	m.mu.Lock()
	defer m.mu.Unlock()

	if m.closing {
		return "", ErrShuttingDown
	}

	if session, ok := m.sessions[request.ID]; ok {
		if session.state.Room != request.Room || session.dir != dir {
			return "", ErrConflict
		}

		status, _ := session.Status()

		return status, nil
	}

	if known, ok := m.index[request.ID]; ok {
		if known != dir {
			return "", ErrConflict
		}

		status, _, err := loadFromDisk(known)

		return status, err
	}

	if free, _, err := disk.Usage(m.cfg.RecordingsDir); err == nil && free < m.cfg.MinFreeBytes {
		return "", ErrDiskLow
	}

	if existing, err := manifest.LoadState(dir); err == nil && existing.ID != request.ID {
		return "", ErrConflict
	}

	if err := os.MkdirAll(dir, 0o755); err != nil {
		return "", err
	}

	state := &manifest.State{
		ID:        request.ID,
		Room:      request.Room,
		Mode:      request.Mode,
		Path:      request.Path,
		Status:    manifest.StateActive,
		Part:      1,
		StartedAt: time.Now(),
	}

	session := newSession(m.cfg, m.log, m.notifier, state, nil)
	m.sessions[request.ID] = session
	m.index[request.ID] = dir

	go session.start(func() { session.run(false) })

	return StatusStarting, nil
}

// Stop asks a recording to finalise. Stopping one that already ended is not an error.
func (m *Manager) Stop(id string) error {
	m.mu.Lock()
	session, active := m.sessions[id]
	_, known := m.index[id]
	m.mu.Unlock()

	switch {
	case active:
		session.Stop(manifest.StopRequested)

		return nil
	case known:
		return nil
	default:
		return ErrNotFound
	}
}

// Get returns the status and manifest of a recording, live or from disk.
func (m *Manager) Get(id string) (string, *manifest.Manifest, error) {
	m.mu.Lock()
	session, active := m.sessions[id]
	dir, known := m.index[id]
	m.mu.Unlock()

	switch {
	case active:
		status, snapshot := session.Status()

		return status, snapshot, nil
	case known:
		return loadFromDisk(dir)
	default:
		return "", nil, ErrNotFound
	}
}

// Stats is the body of GET /stats.
type Stats struct {
	Version          string     `json:"version"`
	UptimeS          int64      `json:"uptime_s"`
	ActiveRecordings int        `json:"active_recordings"`
	Recordings       []Snapshot `json:"recordings"`
	DiskFreeBytes    uint64     `json:"disk_free_bytes"`
	DiskTotalBytes   uint64     `json:"disk_total_bytes"`
	MemAllocBytes    uint64     `json:"mem_alloc_bytes"`
	MemSysBytes      uint64     `json:"mem_sys_bytes"`
	Goroutines       int        `json:"goroutines"`
}

// Stats reports what the recorder is doing and what it costs.
func (m *Manager) Stats() Stats {
	stats := Stats{
		Version:    Version,
		UptimeS:    int64(time.Since(m.started).Seconds()),
		Recordings: []Snapshot{},
		Goroutines: runtime.NumGoroutine(),
	}

	for _, session := range m.active() {
		stats.Recordings = append(stats.Recordings, session.snapshot())
	}

	stats.ActiveRecordings = len(stats.Recordings)
	stats.DiskFreeBytes, stats.DiskTotalBytes, _ = disk.Usage(m.cfg.RecordingsDir)

	var memory runtime.MemStats
	runtime.ReadMemStats(&memory)
	stats.MemAllocBytes, stats.MemSysBytes = memory.Alloc, memory.Sys

	return stats
}

// Healthy reports whether recordings can be written right now.
func (m *Manager) Healthy() error {
	return disk.Writable(m.cfg.RecordingsDir)
}

// Recover picks up what an earlier process left behind: unfinished recordings are repaired
// and continued (or closed, when their room is gone), and outcomes Tandem never acknowledged
// are sent again.
func (m *Manager) Recover() {
	root := m.cfg.RecordingsDir

	_ = filepath.WalkDir(root, func(path string, entry fs.DirEntry, err error) error {
		if err != nil {
			return nil //nolint:nilerr // unreadable entries are skipped, not fatal
		}

		if entry.IsDir() {
			if rel, _ := filepath.Rel(root, path); strings.Count(rel, string(filepath.Separator)) >= scanDepth {
				return filepath.SkipDir
			}

			return nil
		}

		if entry.Name() != manifest.StateFile {
			return nil
		}

		dir := filepath.Dir(path)

		state, err := manifest.LoadState(dir)
		if err != nil || !validID.MatchString(state.ID) {
			m.log.Warn("unreadable recording state skipped", "dir", dir, "error", err)

			return nil
		}

		m.mu.Lock()
		m.index[state.ID] = dir
		m.mu.Unlock()

		switch {
		case state.Status == manifest.StateActive:
			m.resume(dir, state)
		case !state.Notified:
			m.renotify(dir, state)
		}

		return nil
	})
}

// resume repairs the files of an interrupted recording and continues it.
func (m *Manager) resume(dir string, state *manifest.State) {
	log := m.log.With("recording", state.ID, "room", state.Room)
	log.Warn("found an interrupted recording, recovering")

	recorded, err := manifest.Load(dir)
	if err != nil {
		recorded = manifest.New(state.ID, state.Room, state.Mode, state.StartedAt)
	}

	kept := recorded.Tracks[:0]
	listed := map[string]bool{}

	for _, track := range recorded.Tracks {
		listed[track.File] = true
		path := filepath.Join(dir, track.File)

		if !track.Seekable {
			info, repaired, err := webm.Repair(path)
			if err != nil || info.Frames == 0 {
				log.Warn("dropping an unusable track file", "file", track.File, "error", err)
				_ = os.Remove(path)

				continue
			}

			track.DurationMs = info.LastTimestampMs
			track.EndMs = track.StartMs + track.DurationMs
			track.SizeBytes = info.Size
			track.Seekable = info.Finalised

			log.Info("track file recovered", "file", track.File, "repaired", repaired, "duration_ms", track.DurationMs, "seekable", track.Seekable)
		}

		kept = append(kept, track)
	}

	recorded.Tracks = kept

	// A file created an instant before the crash never made it into the manifest and holds
	// no frames yet.
	if entries, err := os.ReadDir(dir); err == nil {
		for _, entry := range entries {
			if strings.HasSuffix(entry.Name(), ".webm") && !listed[entry.Name()] {
				_ = os.Remove(filepath.Join(dir, entry.Name()))
			}
		}
	}

	state.Part++
	session := newSession(m.cfg, m.log, m.notifier, state, recorded)

	m.mu.Lock()
	m.sessions[state.ID] = session
	m.mu.Unlock()

	if exists, known := m.roomOpen(state.Room); known && !exists {
		log.Warn("the room is gone, closing the recording with what was captured")

		go session.start(session.closeRecovered)

		return
	}

	go session.start(func() { session.run(true) })
}

// renotify repeats a terminal webhook that Tandem never acknowledged.
func (m *Manager) renotify(dir string, state *manifest.State) {
	recorded, err := manifest.Load(dir)
	if err != nil {
		return
	}

	session := newSession(m.cfg, m.log, m.notifier, state, recorded)
	session.notifyOutcome(recorded, state.Outcome == "failed", state.Reason)
}

// roomExists asks the media server whether a room is still open. known is false when the
// server could not be asked.
func (m *Manager) roomExists(name string) (exists, known bool) {
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	client := lksdk.NewRoomServiceClient(m.cfg.HTTPURL(), m.cfg.LiveKitKey, m.cfg.LiveKitSecret)

	response, err := client.ListRooms(ctx, &livekit.ListRoomsRequest{Names: []string{name}})
	if err != nil {
		m.log.Warn("could not ask the media server about the room", "room", name, "error", err)

		return false, false
	}

	return len(response.GetRooms()) > 0, true
}

// Shutdown finalises the files of every recording and delivers queued webhooks.
//
// With finish false the recordings themselves stay open and the next recorder process
// continues them: that is right for a standalone recorder, whose restart does not touch the
// calls. With finish true they end here (stop_reason "shutdown"): that is right for the
// combined binary, where stopping the process ends the calls as well.
func (m *Manager) Shutdown(ctx context.Context, finish bool) {
	m.mu.Lock()
	alreadyClosing := m.closing
	m.closing = true
	m.mu.Unlock()

	if alreadyClosing {
		return
	}

	close(m.stop)

	reason := stopSuspend
	if finish {
		reason = manifest.StopShutdown
	}

	sessions := m.active()
	for _, session := range sessions {
		session.Stop(reason)
	}

	for _, session := range sessions {
		select {
		case <-session.Done():
		case <-ctx.Done():
			m.log.Error("shutdown deadline reached before every recording was finalised")
		}
	}

	m.notifier.Close(ctx)
}

func (m *Manager) active() []*Session {
	m.mu.Lock()
	defer m.mu.Unlock()

	sessions := make([]*Session, 0, len(m.sessions))
	for _, session := range m.sessions {
		if session.Active() {
			sessions = append(sessions, session)
		}
	}

	return sessions
}

// watch stops recordings before the disk fills up, and forgets recordings that ended a while ago.
func (m *Manager) watch() {
	defer func() {
		if cause := recover(); cause != nil {
			m.log.Error("recovered from a panic in the recorder", "where", "disk watchdog", "panic", fmt.Sprint(cause))

			go m.watch()
		}
	}()

	ticker := time.NewTicker(diskCheckInterval)
	defer ticker.Stop()

	ended := map[string]time.Time{}

	for {
		select {
		case <-m.stop:
			return
		case now := <-ticker.C:
			if free, _, err := disk.Usage(m.cfg.RecordingsDir); err == nil && free < m.cfg.MinFreeBytes {
				for _, session := range m.active() {
					if !session.stopRequested() {
						session.warn(webhook.WarnDiskLow, "The recording was stopped because the disk is almost full.")
						session.Stop(manifest.StopDiskLow)
					}
				}
			}

			m.mu.Lock()
			for id, session := range m.sessions {
				if session.Active() {
					continue
				}

				if since, ok := ended[id]; !ok {
					ended[id] = now
				} else if now.Sub(since) > keepFinishedFor {
					delete(m.sessions, id)
					delete(ended, id)
				}
			}
			m.mu.Unlock()
		}
	}
}

// loadFromDisk reads the outcome of a recording that is no longer in memory.
func loadFromDisk(dir string) (string, *manifest.Manifest, error) {
	state, err := manifest.LoadState(dir)
	if err != nil {
		return "", nil, ErrNotFound
	}

	recorded, err := manifest.Load(dir)
	if err != nil {
		return "", nil, ErrNotFound
	}

	switch {
	case state.Status == manifest.StateActive:
		return StatusRecording, recorded, nil
	case state.Outcome == "failed":
		return StatusFailed, recorded, nil
	default:
		return recorded.Status, recorded, nil
	}
}

func diskUsage(dir string) (free, total uint64, err error) {
	return disk.Usage(dir)
}

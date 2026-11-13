// Package manifest defines the files that describe a recording on disk: manifest.json (what
// was recorded and where each track sits on the shared timeline) and state.json (what the
// recorder was doing, so a restart can pick the recording back up).
package manifest

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"time"
)

const (
	// Version is the manifest schema version.
	Version = 1

	ManifestFile = "manifest.json"
	StateFile    = "state.json"

	StatusRecording = "recording"
	StatusComplete  = "complete"
	StatusPartial   = "partial"

	StopRequested   = "requested"
	StopRoomClosed  = "room_closed"
	StopMaxDuration = "max_duration"
	StopDiskLow     = "disk_low"
	StopError       = "error"
	StopRecovered   = "recovered"
	// StopShutdown is used by the combined media binary: stopping it ends the calls, so the
	// recordings end with it.
	StopShutdown = "shutdown"

	StateActive = "active"
	StateDone   = "done"
)

// Participant identifies who a track belongs to.
type Participant struct {
	Identity string `json:"identity"`
	Name     string `json:"name"`
}

// Track is one media file. StartMs places it on the shared recording timeline; inside the
// file timestamps start at zero and follow the sender's media clock.
type Track struct {
	File        string      `json:"file"`
	Kind        string      `json:"kind"`
	Source      string      `json:"source"`
	Participant Participant `json:"participant"`
	Codec       string      `json:"codec"`
	StartMs     int64       `json:"start_ms"`
	EndMs       int64       `json:"end_ms"`
	DurationMs  int64       `json:"duration_ms"`
	Width       int         `json:"width,omitempty"`
	Height      int         `json:"height,omitempty"`
	SizeBytes   int64       `json:"size_bytes"`
	Packets     uint64      `json:"packets"`
	PacketsLost uint64      `json:"packets_lost"`
	Part        int         `json:"part"`
	Seekable    bool        `json:"seekable"`
	// ClockDriftMs is how much longer (+) or shorter (-) the track ran on the shared timeline
	// than its own media clock says, measured from RTCP sender reports. Absent when unknown.
	ClockDriftMs *int64 `json:"clock_drift_ms,omitempty"`
}

// Event is something that happened during the recording, on the shared timeline.
type Event struct {
	AtMs        int64  `json:"at_ms"`
	Type        string `json:"type"`
	Participant string `json:"participant,omitempty"`
	Source      string `json:"source,omitempty"`
}

// Manifest is the public description of a recording.
type Manifest struct {
	Version    int     `json:"version"`
	ID         string  `json:"id"`
	Room       string  `json:"room"`
	Mode       string  `json:"mode"`
	Status     string  `json:"status"`
	StopReason string  `json:"stop_reason,omitempty"`
	StartedAt  string  `json:"started_at"`
	EndedAt    string  `json:"ended_at"`
	DurationMs int64   `json:"duration_ms"`
	SizeBytes  int64   `json:"size_bytes"`
	Tracks     []Track `json:"tracks"`
	Events     []Event `json:"events"`
}

// New returns an empty manifest for a recording that is about to start.
func New(id, room, mode string, startedAt time.Time) *Manifest {
	return &Manifest{
		Version:   Version,
		ID:        id,
		Room:      room,
		Mode:      mode,
		Status:    StatusRecording,
		StartedAt: FormatTime(startedAt),
		Tracks:    []Track{},
		Events:    []Event{},
	}
}

// Clone returns a deep copy that is safe to hand to another goroutine.
func (m *Manifest) Clone() *Manifest {
	clone := *m
	clone.Tracks = make([]Track, len(m.Tracks))
	copy(clone.Tracks, m.Tracks)

	for i := range clone.Tracks {
		if drift := clone.Tracks[i].ClockDriftMs; drift != nil {
			value := *drift
			clone.Tracks[i].ClockDriftMs = &value
		}
	}

	clone.Events = make([]Event, len(m.Events))
	copy(clone.Events, m.Events)

	return &clone
}

// TotalSize sums the track file sizes.
func (m *Manifest) TotalSize() int64 {
	var total int64
	for _, track := range m.Tracks {
		total += track.SizeBytes
	}

	return total
}

// State is the recorder's private bookkeeping for one recording.
type State struct {
	ID        string    `json:"id"`
	Room      string    `json:"room"`
	Mode      string    `json:"mode"`
	Path      string    `json:"path"`
	Status    string    `json:"status"`
	Part      int       `json:"part"`
	StartedAt time.Time `json:"started_at"`
	// Outcome is "finished" or "failed" once Status is done.
	Outcome string `json:"outcome,omitempty"`
	Reason  string `json:"reason,omitempty"`
	// Notified records that Tandem acknowledged the terminal webhook.
	Notified bool `json:"notified"`
}

// FormatTime renders a timestamp the way the manifest stores it.
func FormatTime(t time.Time) string {
	return t.UTC().Format(time.RFC3339Nano)
}

// WriteAtomic writes v as JSON next to path and renames it into place, so readers and a
// crashed recorder only ever see a complete file.
func WriteAtomic(path string, v any) error {
	data, err := json.MarshalIndent(v, "", "  ")
	if err != nil {
		return err
	}

	dir := filepath.Dir(path)

	tmp, err := os.CreateTemp(dir, "."+filepath.Base(path)+".*.tmp")
	if err != nil {
		return err
	}

	tmpName := tmp.Name()
	cleanup := func(cause error) error {
		_ = tmp.Close()
		_ = os.Remove(tmpName)

		return cause
	}

	if _, err := tmp.Write(append(data, '\n')); err != nil {
		return cleanup(err)
	}

	if err := tmp.Sync(); err != nil {
		return cleanup(err)
	}

	if err := tmp.Close(); err != nil {
		return cleanup(err)
	}

	if err := os.Chmod(tmpName, 0o644); err != nil {
		return cleanup(err)
	}

	if err := os.Rename(tmpName, path); err != nil {
		return cleanup(err)
	}

	// Persist the rename itself; not every filesystem supports syncing a directory.
	if handle, err := os.Open(dir); err == nil {
		_ = handle.Sync()
		_ = handle.Close()
	}

	return nil
}

// Load reads a manifest from a recording directory.
func Load(dir string) (*Manifest, error) {
	var m Manifest
	if err := readJSON(filepath.Join(dir, ManifestFile), &m); err != nil {
		return nil, err
	}

	if m.Version != Version {
		return nil, errors.New("unsupported manifest version")
	}

	if m.Tracks == nil {
		m.Tracks = []Track{}
	}

	if m.Events == nil {
		m.Events = []Event{}
	}

	return &m, nil
}

// LoadState reads the recorder state from a recording directory.
func LoadState(dir string) (*State, error) {
	var s State
	if err := readJSON(filepath.Join(dir, StateFile), &s); err != nil {
		return nil, err
	}

	return &s, nil
}

func readJSON(path string, v any) error {
	data, err := os.ReadFile(path)
	if err != nil {
		return err
	}

	return json.Unmarshal(data, v)
}

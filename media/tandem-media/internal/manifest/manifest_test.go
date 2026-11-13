package manifest

import (
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"
)

func TestWriteAtomicRoundTrip(t *testing.T) {
	dir := t.TempDir()
	drift := int64(-12)

	m := New("rec-1", "room-a", "full", time.Date(2026, 11, 12, 10, 0, 0, 123456789, time.UTC))
	m.Tracks = append(m.Tracks, Track{
		File: "0001-microphone-p_abc.webm", Kind: "audio", Source: "microphone",
		Participant: Participant{Identity: "p_abc", Name: "Jane"}, Codec: "opus",
		StartMs: 1200, EndMs: 50000, DurationMs: 48800, Part: 1, Seekable: true, ClockDriftMs: &drift,
	})
	m.Events = append(m.Events, Event{AtMs: 0, Type: "participant_joined", Participant: "p_abc"})

	if err := WriteAtomic(filepath.Join(dir, ManifestFile), m); err != nil {
		t.Fatal(err)
	}

	loaded, err := Load(dir)
	if err != nil {
		t.Fatal(err)
	}

	if loaded.StartedAt != "2026-11-12T10:00:00.123456789Z" {
		t.Errorf("started_at = %q", loaded.StartedAt)
	}

	if len(loaded.Tracks) != 1 || loaded.Tracks[0].StartMs != 1200 || *loaded.Tracks[0].ClockDriftMs != -12 {
		t.Errorf("tracks did not round-trip: %+v", loaded.Tracks)
	}

	if loaded.EndedAt != "" || loaded.Status != StatusRecording {
		t.Errorf("unexpected defaults: ended_at=%q status=%q", loaded.EndedAt, loaded.Status)
	}
}

func TestWriteAtomicLeavesNoTemporaryFiles(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, ManifestFile)

	for i := 0; i < 20; i++ {
		if err := WriteAtomic(path, New("rec", "room", "audio", time.Now())); err != nil {
			t.Fatal(err)
		}
	}

	entries, err := os.ReadDir(dir)
	if err != nil {
		t.Fatal(err)
	}

	if len(entries) != 1 || entries[0].Name() != ManifestFile {
		t.Fatalf("directory holds %d entries, want only %s", len(entries), ManifestFile)
	}
}

// A reader racing a writer must always parse a whole manifest: the rename is the only
// moment the file changes.
func TestWriteAtomicIsNeverObservedHalfWritten(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, ManifestFile)

	big := New("rec", "room", "full", time.Now())
	for i := 0; i < 2000; i++ {
		big.Events = append(big.Events, Event{AtMs: int64(i), Type: "active_speaker", Participant: strings.Repeat("p", 40)})
	}

	if err := WriteAtomic(path, big); err != nil {
		t.Fatal(err)
	}

	var wg sync.WaitGroup
	stop := make(chan struct{})

	wg.Add(1)
	go func() {
		defer wg.Done()
		for {
			select {
			case <-stop:
				return
			default:
				if err := WriteAtomic(path, big); err != nil {
					t.Errorf("write: %v", err)

					return
				}
			}
		}
	}()

	for i := 0; i < 200; i++ {
		loaded, err := Load(dir)
		if err != nil {
			t.Fatalf("read %d saw a broken manifest: %v", i, err)
		}

		if len(loaded.Events) != 2000 {
			t.Fatalf("read %d saw %d events", i, len(loaded.Events))
		}
	}

	close(stop)
	wg.Wait()
}

func TestCloneIsDeep(t *testing.T) {
	drift := int64(5)
	m := New("rec", "room", "full", time.Now())
	m.Tracks = []Track{{File: "a.webm", ClockDriftMs: &drift}}

	clone := m.Clone()
	clone.Tracks[0].File = "b.webm"
	*clone.Tracks[0].ClockDriftMs = 9
	clone.Events = append(clone.Events, Event{Type: "x"})

	if m.Tracks[0].File != "a.webm" || *m.Tracks[0].ClockDriftMs != 5 || len(m.Events) != 0 {
		t.Fatalf("clone shares memory with the original: %+v", m)
	}
}

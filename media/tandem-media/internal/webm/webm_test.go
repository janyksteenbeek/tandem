package webm

import (
	"bytes"
	"io"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/at-wat/ebml-go"
	"github.com/at-wat/ebml-go/webm"
)

type parsed struct {
	Header  webm.EBMLHeader `ebml:"EBML"`
	Segment webm.Segment    `ebml:"Segment"`
}

// parse reads the whole file back with the muxing library's own reader.
func parse(t *testing.T, path string) parsed {
	t.Helper()

	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}

	var out parsed
	if err := ebml.Unmarshal(bytes.NewReader(data), &out, ebml.WithIgnoreUnknown(true)); err != nil && err != io.EOF {
		t.Fatalf("parse %s: %v", filepath.Base(path), err)
	}

	return out
}

func frames(p parsed) int {
	count := 0
	for _, c := range p.Segment.Cluster {
		count += len(c.SimpleBlock)
	}

	return count
}

// writeAudio writes n 20 ms Opus-sized frames and returns the still-open writer.
func writeAudio(t *testing.T, path string, n int) *Writer {
	t.Helper()

	w, err := NewAudio(path, 2)
	if err != nil {
		t.Fatal(err)
	}

	for i := 0; i < n; i++ {
		if err := w.Write(true, int64(i*20), []byte{0xF8, 0xFF, 0xFE, byte(i)}); err != nil {
			t.Fatal(err)
		}
	}

	return w
}

func TestFinalisedAudioHasDurationAndCues(t *testing.T) {
	path := filepath.Join(t.TempDir(), "audio.webm")

	w := writeAudio(t, path, 500) // 10 s
	if err := w.Close(); err != nil {
		t.Fatal(err)
	}

	info, err := Inspect(path)
	if err != nil {
		t.Fatal(err)
	}

	if !info.Finalised || info.DurationMs != 9980 || info.Frames != 500 {
		t.Fatalf("unexpected info: %+v", info)
	}

	// One cluster every two seconds over ten seconds.
	if info.CuePoints != 5 {
		t.Fatalf("cue points = %d, want 5", info.CuePoints)
	}

	p := parse(t, path)

	if p.Header.DocType != "webm" {
		t.Errorf("doctype = %q", p.Header.DocType)
	}

	if p.Segment.Info.Duration != 9980 || p.Segment.Info.TimecodeScale != 1_000_000 {
		t.Errorf("info = %+v", p.Segment.Info)
	}

	if p.Segment.Cues == nil || len(p.Segment.Cues.CuePoint) != 5 {
		t.Fatalf("cues did not parse back: %+v", p.Segment.Cues)
	}

	if got := p.Segment.Cues.CuePoint[2].CueTime; got != 4000 {
		t.Errorf("third cue at %d ms, want 4000", got)
	}

	if frames(p) != 500 {
		t.Errorf("parsed %d frames, want 500", frames(p))
	}

	track := p.Segment.Tracks.TrackEntry[0]
	if track.CodecID != "A_OPUS" || string(track.CodecPrivate[:8]) != "OpusHead" || track.Audio.SamplingFrequency != 48000 {
		t.Errorf("track = %+v", track)
	}
}

// Every cue must point at a cluster that really starts at that offset, otherwise players seek into garbage.
func TestCuesPointAtClusters(t *testing.T) {
	path := filepath.Join(t.TempDir(), "video.webm")

	w, err := NewVideo(path, CodecVP8, 1280, 720)
	if err != nil {
		t.Fatal(err)
	}

	for i := 0; i < 300; i++ { // 10 s at 30 fps, keyframe every 2 s
		if err := w.Write(i%60 == 0, int64(i*33), bytes.Repeat([]byte{byte(i)}, 200)); err != nil {
			t.Fatal(err)
		}
	}

	if err := w.Close(); err != nil {
		t.Fatal(err)
	}

	file, err := os.Open(path)
	if err != nil {
		t.Fatal(err)
	}
	defer file.Close()

	l, err := scan(file)
	if err != nil {
		t.Fatal(err)
	}

	p := parse(t, path)
	if p.Segment.Cues == nil || len(p.Segment.Cues.CuePoint) != 5 {
		t.Fatalf("want 5 cues (one per keyframe), got %+v", p.Segment.Cues)
	}

	starts := map[int64]int64{}
	for _, c := range l.clusters {
		if c.frames > 0 && !c.keyframe {
			t.Errorf("cluster at %d starts on a delta frame", c.offset)
		}

		starts[c.offset-l.segmentData] = c.timecode
	}

	for _, cue := range p.Segment.Cues.CuePoint {
		timecode, ok := starts[int64(cue.CueTrackPositions[0].CueClusterPosition)]
		if !ok || timecode != int64(cue.CueTime) {
			t.Errorf("cue %d ms points at offset %d which is not a matching cluster", cue.CueTime, cue.CueTrackPositions[0].CueClusterPosition)
		}
	}

	video := p.Segment.Tracks.TrackEntry[0]
	if video.CodecID != "V_VP8" || video.Video.PixelWidth != 1280 || video.Video.PixelHeight != 720 {
		t.Errorf("track = %+v", video)
	}
}

// A recorder that dies leaves a header with a placeholder duration and no index. Repair must
// turn that into the same thing a clean close produces.
func TestRepairFinishesAnUnclosedFile(t *testing.T) {
	dir := t.TempDir()
	live := filepath.Join(dir, "live.webm")
	crashed := filepath.Join(dir, "crashed.webm")

	w := writeAudio(t, live, 500)
	syncAll(t, w, live, 500)

	copyFile(t, live, crashed) // what is on disk at the moment of the crash
	_ = w.Close()

	before, err := Inspect(crashed)
	if err != nil {
		t.Fatal(err)
	}

	if before.Finalised || before.CuePoints != 0 || before.DurationMs >= 1 {
		t.Fatalf("crashed copy already looks finalised: %+v", before)
	}

	info, repaired, err := Repair(crashed)
	if err != nil || !repaired {
		t.Fatalf("repair: repaired=%v err=%v", repaired, err)
	}

	if !info.Finalised || info.DurationMs != 9980 || info.Frames != 500 || info.CuePoints != 5 {
		t.Fatalf("after repair: %+v", info)
	}

	p := parse(t, crashed)
	if p.Segment.Info.Duration != 9980 || p.Segment.Cues == nil || len(p.Segment.Cues.CuePoint) != 5 || frames(p) != 500 {
		t.Fatalf("repaired file does not parse back: duration=%v cues=%+v frames=%d", p.Segment.Info.Duration, p.Segment.Cues, frames(p))
	}

	// The repaired file and the cleanly closed one must agree on the index.
	clean := parse(t, live)
	for i, cue := range clean.Segment.Cues.CuePoint {
		got := p.Segment.Cues.CuePoint[i]
		if got.CueTime != cue.CueTime || got.CueTrackPositions[0].CueClusterPosition != cue.CueTrackPositions[0].CueClusterPosition {
			t.Errorf("cue %d differs: repaired %+v, clean %+v", i, got, cue)
		}
	}

	if _, again, _ := Repair(crashed); again {
		t.Error("repairing a finalised file must be a no-op")
	}
}

func TestRepairCutsAHalfWrittenFrame(t *testing.T) {
	dir := t.TempDir()
	live := filepath.Join(dir, "live.webm")
	crashed := filepath.Join(dir, "crashed.webm")

	w := writeAudio(t, live, 500)
	syncAll(t, w, live, 500)

	copyFile(t, live, crashed)
	_ = w.Close()

	stat, err := os.Stat(crashed)
	if err != nil {
		t.Fatal(err)
	}

	// Lose the last two bytes of the final frame, as a crash between write() calls would.
	if err := os.Truncate(crashed, stat.Size()-2); err != nil {
		t.Fatal(err)
	}

	info, repaired, err := Repair(crashed)
	if err != nil || !repaired {
		t.Fatalf("repair: repaired=%v err=%v", repaired, err)
	}

	if info.Frames != 499 || info.DurationMs != 9960 || !info.Finalised {
		t.Fatalf("after repair: %+v", info)
	}

	if p := parse(t, crashed); frames(p) != 499 {
		t.Fatalf("parsed %d frames, want 499", frames(p))
	}
}

func TestRepairReportsEmptyAndForeignFiles(t *testing.T) {
	dir := t.TempDir()

	empty := filepath.Join(dir, "empty.webm")
	w, err := NewAudio(empty, 1)
	if err != nil {
		t.Fatal(err)
	}

	crashed := filepath.Join(dir, "crashed.webm")
	copyFile(t, empty, crashed)
	_ = w.Close()

	info, repaired, err := Repair(crashed)
	if err != nil || repaired || info.Frames != 0 {
		t.Fatalf("header-only file: info=%+v repaired=%v err=%v", info, repaired, err)
	}

	foreign := filepath.Join(dir, "notes.webm")
	if err := os.WriteFile(foreign, []byte("definitely not matroska"), 0o644); err != nil {
		t.Fatal(err)
	}

	if _, _, err := Repair(foreign); err != ErrNotWebM {
		t.Fatalf("foreign file: err = %v, want ErrNotWebM", err)
	}
}

func TestCuesAreThinnedToFitTheReservedSpace(t *testing.T) {
	points := make([]cuePoint, 20000)
	for i := range points {
		points[i] = cuePoint{CueTime: uint64(i * 2000), CueTrackPositions: []cuePosition{{CueTrack: 1, CueClusterPosition: uint64(i * 50000)}}}
	}

	encoded, kept := encodeCues(points, cuesReserve)
	if len(encoded) != cuesReserve {
		t.Fatalf("encoded %d bytes, want exactly %d", len(encoded), cuesReserve)
	}

	if kept == 0 || kept >= len(points) {
		t.Fatalf("kept %d of %d cue points", kept, len(points))
	}
}

func TestWriteAfterCloseIsRefused(t *testing.T) {
	w := writeAudio(t, filepath.Join(t.TempDir(), "a.webm"), 3)
	if err := w.Close(); err != nil {
		t.Fatal(err)
	}

	if err := w.Write(true, 60, []byte{1}); err != ErrClosed {
		t.Fatalf("err = %v, want ErrClosed", err)
	}
}

// syncAll flushes the writer and waits until every frame handed to it is on disk: Write
// returns as soon as the muxer has taken a frame, slightly before it is encoded.
func syncAll(t *testing.T, w *Writer, path string, want int) {
	t.Helper()

	deadline := time.Now().Add(2 * time.Second)

	for {
		if err := w.Sync(); err != nil {
			t.Fatal(err)
		}

		info, err := Inspect(path)
		if err != nil {
			t.Fatal(err)
		}

		if info.Frames == want {
			return
		}

		if time.Now().After(deadline) {
			t.Fatalf("only %d of %d frames reached the disk", info.Frames, want)
		}

		time.Sleep(5 * time.Millisecond)
	}
}

func copyFile(t *testing.T, from, to string) {
	t.Helper()

	data, err := os.ReadFile(from)
	if err != nil {
		t.Fatal(err)
	}

	if err := os.WriteFile(to, data, 0o644); err != nil {
		t.Fatal(err)
	}
}

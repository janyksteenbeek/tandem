package recorder

import (
	"context"
	"encoding/json"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/pion/rtp"

	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/config"
	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/manifest"
	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/media"
	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/webhook"
	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/webm"
)

func quiet() *slog.Logger {
	return slog.New(slog.NewTextHandler(io.Discard, nil))
}

func testConfig(dir string) config.Config {
	return config.Config{
		RecordingsDir:    dir,
		LiveKitURL:       "ws://127.0.0.1:1", // nothing listens here
		LiveKitKey:       "key",
		LiveKitSecret:    "secret",
		Secret:           "s3cret",
		MaxDuration:      time.Hour,
		KeyframeInterval: 10 * time.Second,
		EmptyGrace:       30 * time.Second,
	}
}

func TestStartRequestValidation(t *testing.T) {
	valid := StartRequest{ID: "0b0f6c1e-9f0e-4c53-9f6e-0d7c6f2a9a11", Room: "call_abc", Mode: ModeFull, Path: "calluuid/recuuid"}

	if got, err := valid.Validate(); err != nil || got.Path != "calluuid/recuuid" {
		t.Fatalf("valid request refused: %v", err)
	}

	if got, err := (StartRequest{ID: "a", Room: "r", Mode: ModeAudio, Path: "calls/rec/"}).Validate(); err != nil || got.Path != "calls/rec" {
		t.Fatalf("trailing slash should be tolerated: path=%q err=%v", got.Path, err)
	}

	bad := map[string]func(*StartRequest){
		"parent traversal":          func(r *StartRequest) { r.Path = "../outside" },
		"nested traversal":          func(r *StartRequest) { r.Path = "calls/../../outside" },
		"traversal that stays in":   func(r *StartRequest) { r.Path = "calls/x/../rec" },
		"absolute path":             func(r *StartRequest) { r.Path = "/etc/cron.d" },
		"current directory":         func(r *StartRequest) { r.Path = "." },
		"empty path":                func(r *StartRequest) { r.Path = "" },
		"hidden segment":            func(r *StartRequest) { r.Path = "calls/.ssh" },
		"backslash traversal":       func(r *StartRequest) { r.Path = `calls\..\..\x` },
		"null byte":                 func(r *StartRequest) { r.Path = "calls/rec\x00.webm" },
		"double slash":              func(r *StartRequest) { r.Path = "calls//rec" },
		"spaces and shell":          func(r *StartRequest) { r.Path = "calls/$(rm -rf ~)" },
		"overlong path":             func(r *StartRequest) { r.Path = strings.Repeat("a", 300) },
		"id with a slash":           func(r *StartRequest) { r.ID = "../x" },
		"empty id":                  func(r *StartRequest) { r.ID = "" },
		"missing room":              func(r *StartRequest) { r.Room = "" },
		"unknown mode":              func(r *StartRequest) { r.Mode = "everything" },
		"windows drive is rejected": func(r *StartRequest) { r.Path = "C:/recordings" },
	}

	for name, mutate := range bad {
		request := valid
		mutate(&request)

		if _, err := request.Validate(); err == nil {
			t.Errorf("%s: accepted %+v", name, request)
		}
	}
}

func TestStartNeverCreatesDirectoriesOutsideTheRoot(t *testing.T) {
	root := filepath.Join(t.TempDir(), "recordings")
	if err := os.MkdirAll(root, 0o755); err != nil {
		t.Fatal(err)
	}

	m := NewManager(testConfig(root), quiet(), webhook.New("", "", quiet()))

	if _, err := m.Start(StartRequest{ID: "rec", Room: "room", Mode: ModeFull, Path: "../escaped"}); err == nil {
		t.Fatal("traversal was accepted")
	}

	if _, err := os.Stat(filepath.Join(filepath.Dir(root), "escaped")); !os.IsNotExist(err) {
		t.Fatal("a directory was created outside the recordings root")
	}
}

func TestStartRefusesWhenTheDiskIsLow(t *testing.T) {
	cfg := testConfig(t.TempDir())
	cfg.MinFreeBytes = 1 << 62 // more than any disk has

	m := NewManager(cfg, quiet(), webhook.New("", "", quiet()))

	if _, err := m.Start(StartRequest{ID: "rec", Room: "room", Mode: ModeFull, Path: "a/b"}); err != ErrDiskLow {
		t.Fatalf("err = %v, want ErrDiskLow", err)
	}

	if _, _, err := m.Get("rec"); err != ErrNotFound {
		t.Fatalf("a refused recording must not exist, got %v", err)
	}
}

func TestModeDecidesWhichSourcesAreRecorded(t *testing.T) {
	want := map[string][]string{
		ModeFull:   {SourceMicrophone, SourceScreenShareAudio, SourceCamera, SourceScreenShare},
		ModeScreen: {SourceMicrophone, SourceScreenShareAudio, SourceScreenShare},
		ModeAudio:  {SourceMicrophone, SourceScreenShareAudio},
	}

	for mode, sources := range want {
		included := map[string]bool{}
		for _, source := range sources {
			included[source] = true
		}

		for _, source := range []string{SourceMicrophone, SourceCamera, SourceScreenShare, SourceScreenShareAudio} {
			if modeIncludes(mode, source) != included[source] {
				t.Errorf("mode %s, source %s: included = %v", mode, source, !included[source])
			}
		}
	}
}

// fakeSource plays packets to a trackRecorder the way a subscribed track would.
type fakeSource struct {
	packets  chan *rtp.Packet
	deadline time.Time
}

func (f *fakeSource) SetReadDeadline(t time.Time) error {
	f.deadline = t

	return nil
}

func (f *fakeSource) ReadRTP() (*rtp.Packet, error) {
	select {
	case packet, ok := <-f.packets:
		if !ok {
			return nil, io.EOF
		}

		return packet, nil
	case <-time.After(time.Until(f.deadline)):
		return nil, os.ErrDeadlineExceeded
	}
}

func testSession(t *testing.T) *Session {
	t.Helper()

	dir := t.TempDir()
	state := &manifest.State{ID: "rec", Room: "room", Mode: ModeFull, Path: "rec", Status: manifest.StateActive, Part: 1, StartedAt: time.Now()}

	if err := os.MkdirAll(filepath.Join(dir, "rec"), 0o755); err != nil {
		t.Fatal(err)
	}

	return newSession(testConfig(dir), quiet(), webhook.New("", "", quiet()), state, nil)
}

func opusPacket(seq uint16, timestamp uint32) *rtp.Packet {
	// CELT fullband, 20 ms, one frame, with some payload behind the TOC byte.
	return &rtp.Packet{Header: rtp.Header{SequenceNumber: seq, Timestamp: timestamp}, Payload: []byte{0xF8, 1, 2, 3, 4}}
}

func TestAudioTrackIsWrittenAsAContinuousTimeline(t *testing.T) {
	session := testSession(t)
	source := &fakeSource{packets: make(chan *rtp.Packet, 256)}

	recorder := newTrackRecorder(session, source, trackOptions{
		sid: "TR_a", source: SourceMicrophone, codec: webm.CodecOpus, clockRate: 48000, channels: 2, identity: "p_jane", name: "Jane",
	})

	// 1 s of audio, then 3 packets lost in transit (60 ms), 1 s more, then the sender skips
	// two seconds of RTP time without losing a packet (a muted microphone), and 1 s more.
	seq, ts := uint16(65500), uint32(0xFFFFFF00) // both wrap during the test

	send := func(n int) {
		for i := 0; i < n; i++ {
			source.packets <- opusPacket(seq, ts)
			seq++
			ts += 960
		}
	}

	send(50)
	seq, ts = seq+3, ts+3*960
	send(50)
	ts += 2 * 48000
	send(50)
	close(source.packets)

	recorder.run()

	if len(session.manifest.Tracks) != 1 {
		t.Fatalf("manifest lists %d tracks", len(session.manifest.Tracks))
	}

	track := session.manifest.Tracks[0]
	info, err := webm.Inspect(filepath.Join(session.dir, track.File))
	if err != nil {
		t.Fatal(err)
	}

	// 150 real frames + 3 for the loss + 100 for the two silent seconds, back to back.
	if info.Frames != 253 || info.DurationMs != 252*20 || !info.Finalised {
		t.Fatalf("file: %+v", info)
	}

	if track.File != "0001-microphone-p_jane.webm" || track.Kind != "audio" || track.Codec != "opus" ||
		!track.Seekable || track.DurationMs != 5040 || track.EndMs != track.StartMs+5040 ||
		track.Packets != 150 || track.PacketsLost != 3 || track.Part != 1 {
		t.Fatalf("manifest track: %+v", track)
	}
}

func TestAudioTrackIgnoresAnImpossibleJumpInRTPTime(t *testing.T) {
	session := testSession(t)
	source := &fakeSource{packets: make(chan *rtp.Packet, 256)}

	recorder := newTrackRecorder(session, source, trackOptions{
		sid: "TR_a", source: SourceMicrophone, codec: webm.CodecOpus, clockRate: 48000, channels: 2, identity: "p_jane",
	})

	for i := 0; i < 25; i++ {
		source.packets <- opusPacket(uint16(i), uint32(i*960))
	}

	// An hour of RTP time passes in no time at all: the sender restarted its clock.
	for i := 0; i < 25; i++ {
		source.packets <- opusPacket(uint16(25+i), uint32(3600*48000+i*960))
	}

	close(source.packets)
	recorder.run()

	if duration := session.manifest.Tracks[0].DurationMs; duration > 2000 {
		t.Fatalf("file runs %d ms; the bogus hour was written as silence", duration)
	}
}

// vp8Packets builds the RTP packets of one VP8 frame split in two.
func vp8Packets(seq *uint16, timestamp uint32, keyframe bool, width, height int) []*rtp.Packet {
	frame := make([]byte, 40)
	frame[0] = 0x01 // delta frame
	if keyframe {
		frame[0] = 0x00
		copy(frame[3:], []byte{0x9D, 0x01, 0x2A, byte(width), byte(width >> 8), byte(height), byte(height >> 8)})
	}

	first := &rtp.Packet{Header: rtp.Header{SequenceNumber: *seq, Timestamp: timestamp}, Payload: append([]byte{0x10}, frame[:20]...)}
	second := &rtp.Packet{Header: rtp.Header{SequenceNumber: *seq + 1, Timestamp: timestamp, Marker: true}, Payload: append([]byte{0x00}, frame[20:]...)}
	*seq += 2

	return []*rtp.Packet{first, second}
}

func TestVideoTrackStartsOnAKeyframeAndRecoversFromLoss(t *testing.T) {
	session := testSession(t)
	source := &fakeSource{packets: make(chan *rtp.Packet, 1024)}

	var plis atomic.Int32

	recorder := newTrackRecorder(session, source, trackOptions{
		sid: "TR_v", source: SourceScreenShare, codec: webm.CodecVP8, clockRate: 90000, identity: "p_jane",
		requestPLI: func() { plis.Add(1) },
	})

	seq, ts := uint16(100), uint32(5000)

	frame := func(keyframe bool) []*rtp.Packet {
		packets := vp8Packets(&seq, ts, keyframe, 1920, 1080)
		ts += 3000 // 30 fps

		return packets
	}

	push := func(packets []*rtp.Packet) {
		for _, p := range packets {
			source.packets <- p
		}
	}

	for i := 0; i < 5; i++ {
		push(frame(false)) // before the first keyframe: nothing to decode from
	}

	push(frame(true))
	for i := 0; i < 9; i++ {
		push(frame(false))
	}

	broken := frame(false)
	push(broken[:1]) // the second half of this frame is lost

	for i := 0; i < 4; i++ {
		push(frame(false)) // undecodable without the lost frame
	}

	push(frame(true))
	for i := 0; i < 5; i++ {
		push(frame(false))
	}

	time.AfterFunc(600*time.Millisecond, func() { close(source.packets) })
	recorder.run()

	track := session.manifest.Tracks[0]
	info, err := webm.Inspect(filepath.Join(session.dir, track.File))
	if err != nil {
		t.Fatal(err)
	}

	// 10 frames from the first keyframe, then 6 from the second; nothing from before the
	// first keyframe and nothing between the loss and the next keyframe.
	if info.Frames != 16 || !info.Finalised || info.CuePoints != 2 {
		t.Fatalf("file: %+v", info)
	}

	// Timestamps keep the real spacing: the second keyframe is 15 frame intervals after the first.
	if info.DurationMs != 666 {
		t.Fatalf("duration = %v ms, want 666", info.DurationMs)
	}

	if track.Width != 1920 || track.Height != 1080 || track.Codec != "vp8" || track.Kind != "video" || track.PacketsLost != 1 || !track.Seekable {
		t.Fatalf("manifest track: %+v", track)
	}

	if plis.Load() < 2 {
		t.Fatalf("PLI requested %d times, want one at the start and one after the loss", plis.Load())
	}
}

func TestVideoTrackWithoutAKeyframeLeavesNoFile(t *testing.T) {
	session := testSession(t)
	source := &fakeSource{packets: make(chan *rtp.Packet, 64)}

	recorder := newTrackRecorder(session, source, trackOptions{
		sid: "TR_v", source: SourceCamera, codec: webm.CodecVP8, clockRate: 90000, identity: "p_jane",
	})

	seq := uint16(1)
	for i := 0; i < 10; i++ {
		for _, p := range vp8Packets(&seq, uint32(i*3000), false, 0, 0) {
			source.packets <- p
		}
	}

	close(source.packets)
	recorder.run()

	if len(session.manifest.Tracks) != 0 {
		t.Fatalf("manifest lists %+v", session.manifest.Tracks)
	}

	if entries, _ := os.ReadDir(session.dir); len(entries) != 0 {
		t.Fatalf("directory is not empty: %v", entries)
	}
}

// hookRecorder collects the webhooks a test recorder sends.
type hookRecorder struct {
	mu     sync.Mutex
	events []map[string]any
	server *httptest.Server
}

func newHookRecorder(t *testing.T) *hookRecorder {
	t.Helper()

	h := &hookRecorder{}
	h.server = httptest.NewServer(http.HandlerFunc(func(_ http.ResponseWriter, r *http.Request) {
		var event map[string]any
		_ = json.NewDecoder(r.Body).Decode(&event)

		h.mu.Lock()
		h.events = append(h.events, event)
		h.mu.Unlock()
	}))
	t.Cleanup(h.server.Close)

	return h
}

func (h *hookRecorder) find(name string) map[string]any {
	h.mu.Lock()
	defer h.mu.Unlock()

	for _, event := range h.events {
		if event["event"] == name {
			return event
		}
	}

	return nil
}

func (h *hookRecorder) wait(t *testing.T, name string) map[string]any {
	t.Helper()

	for deadline := time.Now().Add(5 * time.Second); time.Now().Before(deadline); time.Sleep(10 * time.Millisecond) {
		if event := h.find(name); event != nil {
			return event
		}
	}

	t.Fatalf("webhook %s never arrived", name)

	return nil
}

// crashedAudio leaves a file on disk exactly as a killed recorder would: frames written and
// flushed, but no seek index and no duration.
func crashedAudio(t *testing.T, path string, frames int) {
	t.Helper()

	scratch := path + ".live"

	w, err := webm.NewAudio(scratch, 2)
	if err != nil {
		t.Fatal(err)
	}

	for i := 0; i < frames; i++ {
		if err := w.Write(true, int64(i*20), media.OpusSilence()); err != nil {
			t.Fatal(err)
		}
	}

	for deadline := time.Now().Add(2 * time.Second); ; time.Sleep(5 * time.Millisecond) {
		if err := w.Sync(); err != nil {
			t.Fatal(err)
		}

		if info, _ := webm.Inspect(scratch); info.Frames == frames {
			break
		}

		if time.Now().After(deadline) {
			t.Fatal("frames never reached the disk")
		}
	}

	data, err := os.ReadFile(scratch)
	if err != nil {
		t.Fatal(err)
	}

	if err := os.WriteFile(path, data, 0o644); err != nil {
		t.Fatal(err)
	}

	_ = w.Close()
	_ = os.Remove(scratch)
}

func TestRecoverRepairsAnInterruptedRecordingWhoseRoomIsGone(t *testing.T) {
	root := t.TempDir()
	dir := filepath.Join(root, "call-1", "rec-1")

	if err := os.MkdirAll(dir, 0o755); err != nil {
		t.Fatal(err)
	}

	startedAt := time.Now().Add(-2 * time.Minute)

	crashedAudio(t, filepath.Join(dir, "0001-microphone-p_jane.webm"), 1500) // 30 s
	crashedAudio(t, filepath.Join(dir, "0003-microphone-p_late.webm"), 0)    // created, never written, never listed

	recorded := manifest.New("rec-1", "room-1", ModeAudio, startedAt)
	recorded.Tracks = []manifest.Track{
		{File: "0001-microphone-p_jane.webm", Kind: "audio", Source: SourceMicrophone, Codec: "opus", StartMs: 400, EndMs: 15400, DurationMs: 15000, Part: 1,
			Participant: manifest.Participant{Identity: "p_jane", Name: "Jane"}},
		{File: "0002-microphone-p_gone.webm", Kind: "audio", Source: SourceMicrophone, Codec: "opus", StartMs: 900, Part: 1}, // file never made it to disk
	}

	state := &manifest.State{ID: "rec-1", Room: "room-1", Mode: ModeAudio, Path: "call-1/rec-1", Status: manifest.StateActive, Part: 1, StartedAt: startedAt}

	for name, value := range map[string]any{manifest.ManifestFile: recorded, manifest.StateFile: state} {
		if err := manifest.WriteAtomic(filepath.Join(dir, name), value); err != nil {
			t.Fatal(err)
		}
	}

	hooks := newHookRecorder(t)
	m := NewManager(testConfig(root), quiet(), webhook.New(hooks.server.URL, "s3cret", quiet()))
	m.roomOpen = func(string) (bool, bool) { return false, true }

	m.Recover()

	finished := hooks.wait(t, webhook.EventFinished)

	status, final, err := m.Get("rec-1")
	if err != nil {
		t.Fatal(err)
	}

	if status != StatusPartial || final.Status != manifest.StatusPartial || final.StopReason != manifest.StopRecovered {
		t.Fatalf("status=%s manifest status=%s stop_reason=%s", status, final.Status, final.StopReason)
	}

	if len(final.Tracks) != 1 {
		t.Fatalf("tracks = %+v", final.Tracks)
	}

	track := final.Tracks[0]
	if !track.Seekable || track.DurationMs != 29980 || track.EndMs != 400+29980 || track.SizeBytes == 0 {
		t.Fatalf("recovered track: %+v", track)
	}

	if info, err := webm.Inspect(filepath.Join(dir, track.File)); err != nil || !info.Finalised || info.CuePoints == 0 {
		t.Fatalf("file was not repaired: %+v %v", info, err)
	}

	// The recording ends where its last track did, not when the recorder happened to restart.
	if final.DurationMs != 400+29980 {
		t.Fatalf("recording duration = %d ms", final.DurationMs)
	}

	if _, err := os.Stat(filepath.Join(dir, "0003-microphone-p_late.webm")); !os.IsNotExist(err) {
		t.Error("the empty unlisted file was left behind")
	}

	if last := final.Events[len(final.Events)-1]; last.Type != "recorder_restarted" {
		t.Errorf("last event = %+v", last)
	}

	sent, _ := finished["manifest"].(map[string]any)
	if sent == nil || sent["stop_reason"] != manifest.StopRecovered {
		t.Fatalf("webhook manifest = %v", finished["manifest"])
	}

	// Once Tandem has acknowledged the outcome, the state on disk says so.
	for deadline := time.Now().Add(2 * time.Second); ; time.Sleep(10 * time.Millisecond) {
		persisted, err := manifest.LoadState(dir)
		if err == nil && persisted.Status == manifest.StateDone && persisted.Notified {
			break
		}

		if time.Now().After(deadline) {
			t.Fatalf("state on disk: %+v (%v)", persisted, err)
		}
	}
}

func TestRecoverRepeatsAnOutcomeTandemNeverAcknowledged(t *testing.T) {
	root := t.TempDir()
	dir := filepath.Join(root, "call-1", "rec-2")

	if err := os.MkdirAll(dir, 0o755); err != nil {
		t.Fatal(err)
	}

	recorded := manifest.New("rec-2", "room-1", ModeAudio, time.Now().Add(-time.Hour))
	recorded.Status = manifest.StatusComplete
	recorded.StopReason = manifest.StopRequested

	state := &manifest.State{ID: "rec-2", Room: "room-1", Mode: ModeAudio, Path: "call-1/rec-2", Status: manifest.StateDone, Outcome: "finished", Part: 1}

	for name, value := range map[string]any{manifest.ManifestFile: recorded, manifest.StateFile: state} {
		if err := manifest.WriteAtomic(filepath.Join(dir, name), value); err != nil {
			t.Fatal(err)
		}
	}

	hooks := newHookRecorder(t)
	m := NewManager(testConfig(root), quiet(), webhook.New(hooks.server.URL, "s3cret", quiet()))
	m.Recover()

	hooks.wait(t, webhook.EventFinished)

	if status, _, err := m.Get("rec-2"); err != nil || status != StatusComplete {
		t.Fatalf("status=%s err=%v", status, err)
	}

	if err := m.Stop("rec-2"); err != nil {
		t.Fatalf("stopping a finished recording should be a no-op, got %v", err)
	}

	if err := m.Stop("never-heard-of-it"); err != ErrNotFound {
		t.Fatalf("err = %v, want ErrNotFound", err)
	}
}

// panicSource blows up the first time the track is read, as a bug in the media path would.
type panicSource struct{}

func (panicSource) SetReadDeadline(time.Time) error { return nil }

func (panicSource) ReadRTP() (*rtp.Packet, error) {
	panic("index out of range [7] with length 3")
}

// In the combined binary the recorder shares a process with every live call, so a bug in it
// must end one recording, never the process.
func TestAPanicInATrackFailsTheRecordingInsteadOfTheProcess(t *testing.T) {
	session := testSession(t)

	recorder := newTrackRecorder(session, panicSource{}, trackOptions{
		sid: "TR_a", source: SourceMicrophone, codec: webm.CodecOpus, clockRate: 48000, channels: 2, identity: "p_jane",
	})

	recorder.run() // must return rather than propagate the panic

	select {
	case <-recorder.done:
	default:
		t.Fatal("the track never signalled that it finished")
	}

	select {
	case reason := <-session.stopCh:
		if reason != manifest.StopError {
			t.Fatalf("stop reason = %q, want %q", reason, manifest.StopError)
		}
	default:
		t.Fatal("the recording was not asked to stop")
	}

	if session.failure == "" {
		t.Fatal("no failure was recorded for the webhook")
	}
}

func TestAPanicInARecordingIsReportedAsFailed(t *testing.T) {
	root := t.TempDir()
	hooks := newHookRecorder(t)

	state := &manifest.State{ID: "rec-9", Room: "room", Mode: ModeFull, Path: "rec-9", Status: manifest.StateActive, Part: 1, StartedAt: time.Now()}
	if err := os.MkdirAll(filepath.Join(root, "rec-9"), 0o755); err != nil {
		t.Fatal(err)
	}

	session := newSession(testConfig(root), quiet(), webhook.New(hooks.server.URL, "s3cret", quiet()), state, nil)

	// A file that was being written when the bug hit.
	crashedAudio(t, filepath.Join(session.dir, "0001-microphone-p_jane.webm"), 100)
	session.manifest.Tracks = []manifest.Track{{File: "0001-microphone-p_jane.webm", Kind: "audio", Source: SourceMicrophone, Codec: "opus", Seekable: true, DurationMs: 1980, EndMs: 1980, Part: 1}}

	go session.start(func() { panic("nil pointer dereference") })

	select {
	case <-session.Done():
	case <-time.After(5 * time.Second):
		t.Fatal("the recording never finished")
	}

	failed := hooks.wait(t, webhook.EventFailed)
	if failed["id"] != "rec-9" || failed["reason"] == "" || failed["manifest"] == nil {
		t.Fatalf("webhook = %v", failed)
	}

	if strings.Contains(failed["reason"].(string), "nil pointer") {
		t.Error("internal details leaked into the webhook reason")
	}

	status, final := session.Status()
	if status != StatusFailed || final.Status != manifest.StatusPartial || final.StopReason != manifest.StopError {
		t.Fatalf("status=%s manifest=%s/%s", status, final.Status, final.StopReason)
	}

	if hooks.find(webhook.EventFinished) != nil {
		t.Error("a failed recording must not also be reported as finished")
	}
}

func TestShutdownEndsOrSuspendsRecordings(t *testing.T) {
	for _, finish := range []bool{true, false} {
		root := t.TempDir()
		hooks := newHookRecorder(t)
		m := NewManager(testConfig(root), quiet(), webhook.New(hooks.server.URL, "s3cret", quiet()))

		state := &manifest.State{ID: "rec-1", Room: "room", Mode: ModeAudio, Path: "rec-1", Status: manifest.StateActive, Part: 1, StartedAt: time.Now().Add(-time.Minute)}
		if err := os.MkdirAll(filepath.Join(root, "rec-1"), 0o755); err != nil {
			t.Fatal(err)
		}

		// A recording that is past joining the room: it only waits to be told to stop.
		session := newSession(m.cfg, quiet(), m.notifier, state, nil)
		session.status = StatusRecording
		m.sessions["rec-1"] = session

		go session.start(func() { session.finalize(<-session.stopCh) })

		ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		m.Shutdown(ctx, finish)
		cancel()

		persisted, err := manifest.LoadState(session.dir)
		if err != nil {
			t.Fatal(err)
		}

		recorded, err := manifest.Load(session.dir)
		if err != nil {
			t.Fatal(err)
		}

		if finish {
			// The combined binary: the calls end with the process, so the recording does too.
			finished := hooks.find(webhook.EventFinished)
			if finished == nil || recorded.Status != manifest.StatusComplete || recorded.StopReason != manifest.StopShutdown || persisted.Status != manifest.StateDone {
				t.Fatalf("finish: webhook=%v manifest=%s/%s state=%s", finished, recorded.Status, recorded.StopReason, persisted.Status)
			}

			if _, err := m.Start(StartRequest{ID: "rec-2", Room: "room", Mode: ModeAudio, Path: "rec-2"}); err != ErrShuttingDown {
				t.Fatalf("a recording was accepted during shutdown: %v", err)
			}

			continue
		}

		// The standalone recorder: the calls go on, the next process picks the recording up.
		if hooks.find(webhook.EventFinished) != nil || hooks.find(webhook.EventFailed) != nil {
			t.Fatal("suspend: a terminal webhook was sent")
		}

		warning := hooks.find(webhook.EventWarning)
		if warning == nil || warning["code"] != webhook.WarnReconnecting || recorded.Status != manifest.StatusRecording || persisted.Status != manifest.StateActive {
			t.Fatalf("suspend: warning=%v manifest=%s state=%s", warning, recorded.Status, persisted.Status)
		}
	}
}

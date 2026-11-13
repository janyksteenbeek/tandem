package recorder

import (
	"errors"
	"fmt"
	"io"
	"log/slog"
	"os"
	"path/filepath"
	"sort"
	"sync"
	"sync/atomic"
	"time"

	lksdk "github.com/livekit/server-sdk-go/v2"
	"github.com/pion/rtcp"
	"github.com/pion/rtp"
	"github.com/pion/webrtc/v4"

	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/manifest"
	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/media"
	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/webhook"
	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/webm"
)

const (
	// readInterval bounds how long a track goes without housekeeping when no media arrives.
	readInterval = 250 * time.Millisecond
	// reorderLatency is how long a missing packet is waited for before its frame is dropped.
	reorderLatency = 200 * time.Millisecond
	// syncInterval is how often buffered frames are forced to disk.
	syncInterval = 2 * time.Second
	// stallAfter is how long an unmuted track may be silent before Tandem is warned.
	stallAfter = 15 * time.Second
	// maxForwardJump is the largest unexplained jump in RTP time that is taken at face value.
	maxForwardJump = 5 * time.Second
	// anchorWindow is how long after its first frame a track's position may still be refined.
	anchorWindow = 60 * time.Second
	// anchorTolerance is how far a sender-report position may be from the arrival position.
	anchorTolerance = 1500 * time.Millisecond
)

// packetSource is the part of webrtc.TrackRemote the recorder reads from.
type packetSource interface {
	ReadRTP() (*rtp.Packet, error)
	SetReadDeadline(time.Time) error
}

type remoteTrack struct{ *webrtc.TrackRemote }

func (r remoteTrack) ReadRTP() (*rtp.Packet, error) {
	packet, _, err := r.TrackRemote.ReadRTP()

	return packet, err
}

// trackOptions describes a track to record.
type trackOptions struct {
	sid       string
	source    string
	codec     string // webm.Codec*
	clockRate uint32
	channels  int
	identity  string
	name      string
	// fallback dimensions announced by the publisher, used when the bitstream does not say.
	width, height int
	muted         bool
	requestPLI    func()
}

// senderReport is an RTCP sender report waiting to be applied by the track's goroutine.
type senderReport struct {
	stamp   time.Time // media server wall clock
	rtp     uint32
	arrival time.Time
}

// trackRecorder writes one subscribed track to one file. All media handling happens on the
// goroutine running run; other goroutines only flip the atomic flags and queue sender reports.
type trackRecorder struct {
	session *Session
	opts    trackOptions
	log     *slog.Logger
	source  packetSource

	assembler media.Assembler
	vp9       media.VP9Depacketizer
	audio     media.AudioTimeline
	writer    *webm.Writer
	file      string

	// RTP time, unwrapped. position = ext - first + adjust, in clock ticks from the first frame.
	haveRaw   bool
	lastRaw   uint32
	ext       int64
	first     int64
	adjust    int64
	started   bool
	lastPos   int64
	lastAt    time.Time
	startedAt time.Time
	startMs   int64
	broken    bool // RTP time was re-anchored at least once; sender reports no longer apply

	needKeyframe bool
	lastKeyframe time.Time
	lastPLI      time.Time
	width        int
	height       int

	lastPacket time.Time
	lastSync   time.Time
	stalled    bool
	failed     bool

	reportMu  sync.Mutex
	report    *senderReport
	latest    *senderReport
	latestExt int64
	estimates []int64

	muted    atomic.Bool
	resumed  atomic.Bool
	stopping atomic.Bool
	done     chan struct{}

	// Published for the session's progress report.
	sizeBytes  atomic.Int64
	durationMs atomic.Int64
	packets    atomic.Uint64
	lost       atomic.Uint64
}

func newTrackRecorder(session *Session, source packetSource, opts trackOptions) *trackRecorder {
	t := &trackRecorder{
		session:      session,
		opts:         opts,
		source:       source,
		needKeyframe: true,
		done:         make(chan struct{}),
		log: session.log.With(
			"track", opts.sid, "source", opts.source, "participant", opts.identity, "codec", opts.codec,
		),
	}

	t.muted.Store(opts.muted)

	t.assembler = media.Assembler{Latency: reorderLatency, MaxBuffered: 64}

	if t.video() {
		t.assembler.Video = true
		t.assembler.MaxBuffered = 1024

		if opts.codec == webm.CodecVP9 {
			t.assembler.IsFrameStart = media.VP9IsFrameStart
		} else {
			t.assembler.IsFrameStart = media.VP8IsFrameStart
		}
	}

	return t
}

func (t *trackRecorder) video() bool { return t.opts.codec != webm.CodecOpus }

// onRTCP queues sender reports; it is called from the SDK's RTCP reader.
func (t *trackRecorder) onRTCP(packet rtcp.Packet) {
	defer t.session.guard("rtcp")

	report, ok := packet.(*rtcp.SenderReport)
	if !ok {
		return
	}

	now := time.Now()
	stamp := ntpToTime(report.NTPTime)
	t.session.clock.observe(stamp, now)

	t.reportMu.Lock()
	t.report = &senderReport{stamp: stamp, rtp: report.RTPTime, arrival: now}
	t.reportMu.Unlock()
}

// stop asks the track to finish its file. It returns immediately; wait on done.
func (t *trackRecorder) stop() { t.stopping.Store(true) }

func (t *trackRecorder) setMuted(muted bool) {
	if t.muted.Swap(muted) && !muted {
		t.resumed.Store(true)
	}
}

// run reads the track until it ends or the recording stops, then finalises the file. A
// panic anywhere in the media path is contained here: the file is closed with what it has
// and the recording is reported as failed.
func (t *trackRecorder) run() {
	defer close(t.done)
	defer func() {
		cause := recover()
		if cause == nil {
			return
		}

		t.failed = true
		t.session.crashed("track "+t.opts.source, cause)

		func() {
			defer func() { _ = recover() }()
			t.finish()
		}()

		t.session.Stop(manifest.StopError)
	}()

	t.read()
	t.finish()
}

func (t *trackRecorder) read() {
	if t.video() {
		t.pli(time.Now())
	}

	for !t.stopping.Load() {
		_ = t.source.SetReadDeadline(time.Now().Add(readInterval))

		packet, err := t.source.ReadRTP()
		now := time.Now()

		switch {
		case err == nil:
			t.lastPacket = now
			t.handle(t.assembler.Push(packet, now))
		case isTimeout(err):
			// No media right now; fall through to housekeeping.
		default:
			if !errors.Is(err, io.EOF) {
				t.log.Debug("track ended", "error", err)
			}

			return
		}

		t.tick(now)

		if t.failed {
			return
		}
	}
}

// tick does everything that has to happen regularly whether or not media is flowing.
func (t *trackRecorder) tick(now time.Time) {
	t.handle(t.assembler.Tick(now))
	t.applySenderReport()

	if t.video() && !t.muted.Load() {
		switch {
		case t.needKeyframe && now.Sub(t.lastPLI) >= time.Second:
			t.pli(now)
		case t.writer != nil && now.Sub(t.lastKeyframe) >= t.session.cfg.KeyframeInterval && now.Sub(t.lastPLI) >= 2*time.Second:
			t.pli(now)
		}
	}

	if !t.lastPacket.IsZero() && !t.muted.Load() {
		silent := now.Sub(t.lastPacket) >= stallAfter

		if silent && !t.stalled {
			t.session.warn(webhook.WarnTrackInterrupted, fmt.Sprintf(
				"No media from %s (%s) for %s.", t.describe(), t.opts.source, stallAfter,
			))
		}

		t.stalled = silent
	}

	if t.writer == nil {
		return
	}

	if now.Sub(t.lastSync) >= syncInterval {
		t.lastSync = now
		_ = t.writer.Sync()
		t.publishStats()
	}

	if err := t.writer.Err(); err != nil && !t.failed {
		t.failed = true
		t.log.Error("writing the track failed", "file", t.file, "error", err)
		t.session.trackFailed(t, err)
	}
}

func (t *trackRecorder) handle(frames []media.Frame) {
	for _, frame := range frames {
		if t.failed {
			return
		}

		if t.video() {
			t.writeVideo(frame)
		} else {
			t.writeAudio(frame)
		}
	}
}

// advance unwraps the frame's RTP timestamp. Every frame must pass through here, written or not.
func (t *trackRecorder) advance(frame media.Frame) {
	if !t.haveRaw {
		t.haveRaw = true
		t.lastRaw = frame.Timestamp

		return
	}

	t.ext += int64(int32(frame.Timestamp - t.lastRaw)) //nolint:gosec // wrap-around arithmetic
	t.lastRaw = frame.Timestamp
}

// position returns where a frame belongs in the file, in clock ticks from the first frame.
// RTP time is trusted because it follows the sender's media clock exactly; it is only
// overruled when it jumps in a way no real stream does, or disagrees with the wall clock
// right after a mute, in which case the frame is placed by its arrival time instead.
func (t *trackRecorder) position(frame media.Frame) int64 {
	if !t.started {
		t.started = true
		t.first = t.ext
		t.startedAt = frame.At
		t.lastAt = frame.At
		t.startMs = t.session.timelineMs(frame.At)

		return 0
	}

	rate := int64(t.opts.clockRate)
	pos := t.ext - t.first + t.adjust
	delta := pos - t.lastPos
	elapsed := frame.At.Sub(t.lastAt).Nanoseconds() * rate / int64(time.Second)
	elapsed = max(elapsed, 0)

	unreal := delta < 0 || delta > elapsed+int64(maxForwardJump/time.Second)*rate
	afterMute := t.resumed.Swap(false) && abs(delta-elapsed) > rate/2

	if unreal || afterMute {
		t.log.Warn("RTP time is discontinuous, placing the frame by arrival time",
			"rtp_delta_ms", delta*1000/rate, "elapsed_ms", elapsed*1000/rate, "after_mute", afterMute)

		t.adjust += t.lastPos + elapsed - pos
		pos = t.lastPos + elapsed
		t.broken = true
	}

	t.lastPos = pos
	t.lastAt = frame.At

	return pos
}

func (t *trackRecorder) writeAudio(frame media.Frame) {
	payload := frame.Packets[0].Payload
	samples := media.OpusSamples(payload)

	t.advance(frame)

	if samples == 0 {
		return
	}

	pos := t.position(frame)

	if t.writer == nil && !t.open(func(path string) (*webm.Writer, error) {
		return webm.NewAudio(path, t.opts.channels)
	}) {
		return
	}

	at, silence := t.audio.Place(pos, samples)

	for _, gap := range silence {
		_ = t.writer.Write(true, gap*1000/media.OpusClockRate, media.OpusSilence())
	}

	_ = t.writer.Write(true, at*1000/media.OpusClockRate, payload)
}

func (t *trackRecorder) writeVideo(frame media.Frame) {
	t.advance(frame)

	var (
		decoded media.VideoFrame
		ok      bool
	)

	if t.opts.codec == webm.CodecVP9 {
		decoded, ok = t.vp9.Depacketize(frame.Packets)
	} else {
		decoded, ok = media.DepacketizeVP8(frame.Packets)
	}

	// After a gap the decoder's reference frames are gone: wait for the next keyframe rather
	// than write frames that would render as garbage.
	if !ok || (frame.AfterLoss && !decoded.Keyframe) {
		t.needKeyframe = true
		t.pli(frame.At)

		return
	}

	// The media server marks a paused video track with tiny blank keyframes. They are not
	// part of the picture; skip them and resume on the next real keyframe.
	if decoded.Keyframe && t.writer != nil && decoded.Width > 0 && decoded.Width <= 16 && decoded.Height <= 16 && t.width > 16 {
		t.needKeyframe = true

		return
	}

	if t.needKeyframe {
		if !decoded.Keyframe {
			return
		}

		t.needKeyframe = false
	}

	if decoded.Keyframe {
		t.lastKeyframe = frame.At

		if decoded.Width > 0 && decoded.Height > 0 {
			t.width, t.height = max(t.width, decoded.Width), max(t.height, decoded.Height)
		}
	}

	if t.writer == nil {
		// The header describes the picture at its best: a simulcast track often opens on a
		// small layer and switches up a moment later, and both codecs carry the actual size
		// of every frame in the bitstream.
		headerWidth, headerHeight := t.width, t.height
		if t.opts.width*t.opts.height > headerWidth*headerHeight {
			headerWidth, headerHeight = t.opts.width, t.opts.height
		}

		if headerWidth == 0 || headerHeight == 0 {
			t.needKeyframe = true // cannot describe the track yet; try the next keyframe

			return
		}

		if t.width == 0 || t.height == 0 {
			t.width, t.height = headerWidth, headerHeight
		}

		pos := t.position(frame)

		if !t.open(func(path string) (*webm.Writer, error) {
			return webm.NewVideo(path, t.opts.codec, headerWidth, headerHeight)
		}) {
			return
		}

		if decoded.Layers > 1 {
			t.log.Info("track uses spatial scalability; pictures are stored with all their layers", "spatial_layers", decoded.Layers)
		}

		_ = t.writer.Write(decoded.Keyframe, pos*1000/int64(t.opts.clockRate), decoded.Data)

		return
	}

	pos := t.position(frame)

	_ = t.writer.Write(decoded.Keyframe, pos*1000/int64(t.opts.clockRate), decoded.Data)
}

// open creates the file and lists it in the manifest before the first frame is written, so
// that every file on disk is always accounted for.
func (t *trackRecorder) open(create func(path string) (*webm.Writer, error)) bool {
	name, part := t.session.nextFile(t.opts.source, t.opts.identity)

	writer, err := create(filepath.Join(t.session.dir, name))
	if err != nil {
		t.failed = true
		t.log.Error("could not create the track file", "file", name, "error", err)
		t.session.trackFailed(t, err)

		return false
	}

	t.writer = writer
	t.file = name
	t.lastSync = time.Now()

	kind := "audio"
	if t.video() {
		kind = "video"
	}

	start := t.startMs

	t.session.addTrack(manifest.Track{
		File:        name,
		Kind:        kind,
		Source:      t.opts.source,
		Participant: manifest.Participant{Identity: t.opts.identity, Name: t.opts.name},
		Codec:       t.opts.codec,
		StartMs:     start,
		EndMs:       start,
		Width:       t.width,
		Height:      t.height,
		Part:        part,
	})

	t.log.Info("recording track", "file", name, "width", t.width, "height", t.height)

	return true
}

// applySenderReport refines where the file sits on the shared timeline. Arrival time places
// the first frame to within network and encoder jitter; a sender report says which wall-clock
// instant an RTP timestamp stands for, which lines tracks up by capture time instead.
func (t *trackRecorder) applySenderReport() {
	t.reportMu.Lock()
	report := t.report
	t.report = nil
	t.reportMu.Unlock()

	if report == nil || !t.haveRaw || !t.started || t.broken {
		return
	}

	t.latest = report
	t.latestExt = t.ext + int64(int32(report.rtp-t.lastRaw)) //nolint:gosec // wrap-around arithmetic

	if len(t.estimates) >= 9 || report.arrival.Sub(t.startedAt) > anchorWindow {
		return
	}

	firstFrame, ok := t.wallClock(t.first)
	if !ok {
		return
	}

	t.estimates = append(t.estimates, t.session.timelineMs(firstFrame))

	sorted := append([]int64(nil), t.estimates...)
	sort.Slice(sorted, func(i, j int) bool { return sorted[i] < sorted[j] })
	median := sorted[len(sorted)/2]

	arrival := t.session.timelineMs(t.startedAt)
	if abs(median-arrival) > anchorTolerance.Milliseconds() {
		t.log.Warn("sender report disagrees with arrival time, keeping arrival time", "report_ms", median, "arrival_ms", arrival)

		return
	}

	if median != t.startMs {
		t.startMs = median
		t.publishStats()
	}
}

// wallClock converts an unwrapped RTP timestamp of this track to local time using the newest
// sender report.
func (t *trackRecorder) wallClock(ext int64) (time.Time, bool) {
	if t.latest == nil {
		return time.Time{}, false
	}

	anchor, ok := t.session.clock.local(t.latest.stamp)
	if !ok {
		return time.Time{}, false
	}

	ticks := ext - t.latestExt

	return anchor.Add(time.Duration(ticks * int64(time.Second) / int64(t.opts.clockRate))), true
}

func (t *trackRecorder) pli(now time.Time) {
	t.lastPLI = now

	if t.opts.requestPLI != nil {
		t.opts.requestPLI()
	}
}

func (t *trackRecorder) describe() string {
	if t.opts.name != "" {
		return t.opts.name
	}

	return t.opts.identity
}

// publishStats copies the file's progress into the manifest.
func (t *trackRecorder) publishStats() {
	if t.writer == nil {
		return
	}

	t.sizeBytes.Store(t.writer.Size())
	t.durationMs.Store(t.writer.LastTimestampMs())
	t.packets.Store(t.assembler.Received())
	t.lost.Store(t.assembler.Lost())

	t.session.updateTrack(t.file, func(track *manifest.Track) {
		track.StartMs = t.startMs
		track.DurationMs = t.writer.LastTimestampMs()
		track.EndMs = track.StartMs + track.DurationMs
		track.SizeBytes = t.writer.Size()
		track.Packets = t.assembler.Received()
		track.PacketsLost = t.assembler.Lost()
		track.Width, track.Height = t.width, t.height
	})
}

// finish flushes what is buffered, closes the file and records the final numbers.
func (t *trackRecorder) finish() {
	if !t.failed {
		t.handle(t.assembler.Flush())
	}

	if t.writer == nil {
		t.packets.Store(t.assembler.Received())
		t.lost.Store(t.assembler.Lost())

		return
	}

	// A microphone that is still muted when the recording stops has sent nothing since the
	// mute; extend the file with silence so it ends when the track did.
	if !t.video() && t.muted.Load() && t.started && !t.failed {
		elapsed := time.Since(t.lastAt).Nanoseconds() * int64(media.OpusClockRate) / int64(time.Second)

		for _, gap := range t.audio.PadTo(t.lastPos + elapsed) {
			_ = t.writer.Write(true, gap*1000/media.OpusClockRate, media.OpusSilence())
		}
	}

	frames := t.writer.Frames()
	duration := t.writer.LastTimestampMs()
	closeErr := t.writer.Close()
	path := filepath.Join(t.session.dir, t.file)

	if frames == 0 {
		_ = os.Remove(path)
		t.session.removeTrack(t.file)

		return
	}

	size := t.writer.Size()
	if stat, err := os.Stat(path); err == nil {
		size = stat.Size()
	}

	// A file whose writer failed still holds every frame that reached the disk; index it in
	// place so that what was captured stays playable.
	seekable := closeErr == nil
	if !seekable {
		if info, _, err := webm.Repair(path); err == nil && info.Finalised {
			seekable = true
			duration = info.LastTimestampMs
			size = info.Size
		}
	}

	var drift *int64

	if end, ok := t.wallClock(t.first + t.lastPos - t.adjust); ok && !t.broken && t.latest.arrival.Sub(t.startedAt) > 20*time.Second {
		value := t.session.timelineMs(end) - t.startMs - duration
		drift = &value
	}

	t.sizeBytes.Store(size)
	t.durationMs.Store(duration)
	t.packets.Store(t.assembler.Received())
	t.lost.Store(t.assembler.Lost())

	t.session.updateTrack(t.file, func(track *manifest.Track) {
		track.StartMs = t.startMs
		track.DurationMs = duration
		track.EndMs = t.startMs + duration
		track.SizeBytes = size
		track.Packets = t.assembler.Received()
		track.PacketsLost = t.assembler.Lost()
		track.Width, track.Height = t.width, t.height
		track.Seekable = seekable
		track.ClockDriftMs = drift
	})

	t.log.Info("track finished", "file", t.file, "duration_ms", duration, "bytes", size,
		"packets", t.assembler.Received(), "lost", t.assembler.Lost(), "seekable", seekable)
}

// isTimeout recognises an expired read deadline, whichever layer reports it.
func isTimeout(err error) bool {
	var timeout interface{ Timeout() bool }

	return errors.Is(err, os.ErrDeadlineExceeded) || (errors.As(err, &timeout) && timeout.Timeout())
}

func ntpToTime(ntp uint64) time.Time {
	const epochOffset = 2208988800 // seconds between 1900 and 1970

	seconds := int64(ntp>>32) - epochOffset //nolint:gosec // 32-bit value
	fraction := (ntp & 0xFFFFFFFF) * uint64(time.Second) >> 32

	return time.Unix(seconds, int64(fraction)) //nolint:gosec // below one second
}

func abs(v int64) int64 {
	if v < 0 {
		return -v
	}

	return v
}

// remoteOptions describes an SDK track to the recorder.
func remoteOptions(track *webrtc.TrackRemote, pub *lksdk.RemoteTrackPublication, rp *lksdk.RemoteParticipant, source, codec string) trackOptions {
	opts := trackOptions{
		sid:       pub.SID(),
		source:    source,
		codec:     codec,
		clockRate: track.Codec().ClockRate,
		channels:  int(track.Codec().Channels),
		identity:  rp.Identity(),
		name:      rp.Name(),
		muted:     pub.IsMuted(),
	}

	if info := pub.TrackInfo(); info != nil {
		opts.width, opts.height = int(info.GetWidth()), int(info.GetHeight())
	}

	ssrc := track.SSRC()
	opts.requestPLI = func() { rp.WritePLI(ssrc) }

	return opts
}

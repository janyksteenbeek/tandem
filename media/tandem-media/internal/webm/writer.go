// Package webm writes one media track to a seekable WebM file without touching the encoded
// frames, and repairs files that were left unfinished by a crash.
//
// Muxing is done by github.com/at-wat/ebml-go: it reserves space for Cues behind the header
// and fills in Cues and the Segment Duration when the file is closed. This package adds the
// crash-safe sink underneath it and the in-place repair pass (see repair.go).
package webm

import (
	"bufio"
	"encoding/binary"
	"errors"
	"os"
	"sync"
	"time"

	"github.com/at-wat/ebml-go/mkvcore"
	"github.com/at-wat/ebml-go/webm"
)

const (
	CodecOpus = "opus"
	CodecVP8  = "vp8"
	CodecVP9  = "vp9"

	// cuesReserve is the space kept behind the header for the seek index. A cue is ~17 bytes,
	// so this holds an entry every two seconds for about two hours; longer files get a
	// proportionally coarser index instead of running out of room.
	cuesReserve = 64 << 10

	// audioClusterMs is how often an audio file starts a new cluster, and therefore how fine
	// its seek index is.
	audioClusterMs = 2000

	// maxClusterMs is the longest a cluster may run: block timestamps are 16-bit offsets.
	maxClusterMs = 0x7FFF

	trackNumber = 1
	appName     = "tandem-recorder"
)

// ErrClosed is returned when writing to a file that was already finalised.
var ErrClosed = errors.New("webm: writer is closed")

// Writer appends frames of a single track to a WebM file.
type Writer struct {
	sink   *sink
	block  mkvcore.BlockWriteCloser
	frames uint64
	lastMs int64
	closed bool

	mu    sync.Mutex
	fatal error
}

// NewAudio creates an Opus file. Opus in WebM always runs at 48 kHz.
func NewAudio(path string, channels int) (*Writer, error) {
	if channels < 1 || channels > 2 {
		channels = 2
	}

	return create(path, webm.TrackEntry{
		Name:         "Audio",
		TrackNumber:  trackNumber,
		TrackUID:     1,
		CodecID:      "A_OPUS",
		CodecPrivate: opusHead(channels),
		TrackType:    2,
		SeekPreRoll:  80_000_000,
		Audio:        &webm.Audio{SamplingFrequency: 48000, Channels: uint64(channels)}, //nolint:gosec // 1 or 2
	}, true)
}

// NewVideo creates a VP8 or VP9 file. The dimensions describe the first keyframe; both
// codecs carry later resolution changes in the bitstream.
func NewVideo(path, codec string, width, height int) (*Writer, error) {
	var codecID string

	switch codec {
	case CodecVP8:
		codecID = "V_VP8"
	case CodecVP9:
		codecID = "V_VP9"
	default:
		return nil, errors.New("webm: unsupported video codec " + codec)
	}

	if width <= 0 || height <= 0 {
		return nil, errors.New("webm: video dimensions are required")
	}

	return create(path, webm.TrackEntry{
		Name:        "Video",
		TrackNumber: trackNumber,
		TrackUID:    1,
		CodecID:     codecID,
		TrackType:   1,
		Video:       &webm.Video{PixelWidth: uint64(width), PixelHeight: uint64(height)}, //nolint:gosec // positive
	}, false)
}

func create(path string, entry webm.TrackEntry, audio bool) (*Writer, error) {
	file, err := os.OpenFile(path, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0o644)
	if err != nil {
		return nil, err
	}

	w := &Writer{sink: newSink(file)}

	// Audio has no keyframes, so it starts a cluster on a timer; video starts one on every
	// keyframe so that each index entry points at a frame a decoder can start from.
	minCluster := int64(0)
	if audio {
		minCluster = audioClusterMs
	}

	writers, err := mkvcore.NewSimpleBlockWriter(
		w.sink,
		[]mkvcore.TrackDescription{{TrackNumber: trackNumber, TrackEntry: entry}},
		mkvcore.WithEBMLHeader(webm.DefaultEBMLHeader),
		mkvcore.WithSegmentInfo(&webm.Info{
			TimecodeScale: 1_000_000, // block timestamps are milliseconds
			MuxingApp:     appName,
			WritingApp:    appName,
			DateUTC:       time.Now().UTC(),
		}),
		mkvcore.WithSeekHead(true),
		mkvcore.WithCues(cuesReserve),
		mkvcore.WithMinMaxClusterDuration(trackNumber, minCluster, maxClusterMs),
		mkvcore.WithOnFatalHandler(w.setFatal),
		mkvcore.WithOnErrorHandler(func(error) {}),
	)
	if err != nil {
		_ = file.Close()
		_ = os.Remove(path)

		return nil, err
	}

	w.block = writers[0]

	// Get the header on disk straight away: from here on a crash leaves a repairable file.
	if err := w.Sync(); err != nil {
		_ = w.Close()
		_ = os.Remove(path)

		return nil, err
	}

	return w, nil
}

// Write appends one frame. Timestamps are milliseconds from the start of the file and must
// not go backwards. The data slice is retained until the frame is on its way to disk.
func (w *Writer) Write(keyframe bool, timestampMs int64, data []byte) error {
	if w.closed {
		return ErrClosed
	}

	if err := w.Err(); err != nil {
		return err
	}

	if timestampMs < w.lastMs {
		timestampMs = w.lastMs
	}

	if _, err := w.block.Write(keyframe, timestampMs, data); err != nil {
		return err
	}

	w.lastMs = timestampMs
	w.frames++

	return w.Err()
}

// Frames is the number of frames written so far.
func (w *Writer) Frames() uint64 { return w.frames }

// LastTimestampMs is the timestamp of the newest frame.
func (w *Writer) LastTimestampMs() int64 { return w.lastMs }

// Size is the number of bytes handed to the file so far.
func (w *Writer) Size() int64 { return w.sink.size() }

// Sync flushes buffered frames and asks the OS to put them on stable storage.
func (w *Writer) Sync() error {
	if w.closed {
		return nil
	}

	return w.sink.sync()
}

// Err reports the first write failure, if any. Once it is non-nil the file stops growing.
func (w *Writer) Err() error {
	w.mu.Lock()
	fatal := w.fatal
	w.mu.Unlock()

	if fatal != nil {
		return fatal
	}

	return w.sink.failure()
}

// Close writes the seek index and duration and closes the file.
func (w *Writer) Close() error {
	if w.closed {
		return w.Err()
	}

	w.closed = true

	// Blocks until the muxer has written Cues and Duration and closed the sink.
	_ = w.block.Close()

	return w.Err()
}

func (w *Writer) setFatal(err error) {
	w.mu.Lock()
	if w.fatal == nil {
		w.fatal = err
	}
	w.mu.Unlock()
}

// opusHead builds the identification header WebM stores as CodecPrivate (RFC 7845 §5.1).
func opusHead(channels int) []byte {
	head := make([]byte, 19)
	copy(head, "OpusHead")
	head[8] = 1                                      // version
	head[9] = byte(channels)                         //nolint:gosec // 1 or 2
	binary.LittleEndian.PutUint16(head[10:], 0)      // pre-skip: the stream is cut from a live call
	binary.LittleEndian.PutUint32(head[12:], 48_000) // input sample rate
	binary.LittleEndian.PutUint16(head[16:], 0)      // output gain
	head[18] = 0                                     // channel mapping family: mono/stereo

	return head
}

// sink is the file underneath the muxer. It buffers small writes, lets the muxer patch the
// header at the end, and swallows write errors so the muxer's goroutine never stops reading
// frames: the first failure is kept and surfaced through Writer.Err instead.
type sink struct {
	mu      sync.Mutex
	file    *os.File
	buffer  *bufio.Writer
	written int64
	err     error
	closed  bool
}

func newSink(file *os.File) *sink {
	return &sink{file: file, buffer: bufio.NewWriterSize(file, 64<<10)}
}

func (s *sink) Write(p []byte) (int, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	if s.err == nil && !s.closed {
		if _, err := s.buffer.Write(p); err != nil {
			s.err = err
		} else {
			s.written += int64(len(p))
		}
	}

	return len(p), nil
}

func (s *sink) WriteAt(p []byte, offset int64) (int, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	if s.err == nil && !s.closed {
		if err := s.buffer.Flush(); err != nil {
			s.err = err
		} else if _, err := s.file.WriteAt(p, offset); err != nil {
			s.err = err
		}
	}

	return len(p), nil
}

func (s *sink) Close() error {
	s.mu.Lock()
	defer s.mu.Unlock()

	if s.closed {
		return nil
	}

	s.closed = true

	if s.err == nil {
		if err := s.buffer.Flush(); err != nil {
			s.err = err
		} else if err := s.file.Sync(); err != nil {
			s.err = err
		}
	}

	if err := s.file.Close(); err != nil && s.err == nil {
		s.err = err
	}

	return nil
}

func (s *sink) sync() error {
	s.mu.Lock()
	defer s.mu.Unlock()

	if s.err != nil || s.closed {
		return s.err
	}

	if err := s.buffer.Flush(); err != nil {
		s.err = err
	} else if err := s.file.Sync(); err != nil {
		s.err = err
	}

	return s.err
}

func (s *sink) size() int64 {
	s.mu.Lock()
	defer s.mu.Unlock()

	return s.written
}

func (s *sink) failure() error {
	s.mu.Lock()
	defer s.mu.Unlock()

	return s.err
}

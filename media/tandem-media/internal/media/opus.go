// Package media turns RTP packets into frames ready to be written to a file: reordering,
// loss detection, Opus gap filling and VP8/VP9 keyframe inspection. Nothing is decoded.
package media

const (
	// OpusClockRate is the RTP clock rate of Opus, whatever the encoder's real sample rate.
	OpusClockRate = 48000

	// OpusFrameSamples is the length of the silence frame used to fill gaps: 20 ms.
	OpusFrameSamples = 960
)

// opusSilence is a 20 ms fullband CELT frame that decodes to silence. Decoders switch modes
// between packets, so it is valid inside any Opus stream.
var opusSilence = []byte{0xF8, 0xFF, 0xFE}

// OpusSilence returns a 20 ms Opus frame of silence.
func OpusSilence() []byte {
	return opusSilence
}

// OpusSamples returns how many 48 kHz samples an Opus packet holds, from its TOC byte
// (RFC 6716 §3.1). It returns 0 for packets it cannot interpret.
func OpusSamples(packet []byte) int {
	if len(packet) == 0 {
		return 0
	}

	toc := packet[0]
	config := toc >> 3

	var frame int

	switch {
	case config < 12: // SILK: 10, 20, 40, 60 ms
		frame = [...]int{480, 960, 1920, 2880}[config&3]
	case config < 16: // hybrid: 10, 20 ms
		frame = [...]int{480, 960}[config&1]
	default: // CELT: 2.5, 5, 10, 20 ms
		frame = [...]int{120, 240, 480, 960}[config&3]
	}

	switch toc & 3 {
	case 0:
		return frame
	case 1, 2:
		return 2 * frame
	default:
		if len(packet) < 2 {
			return 0
		}

		return frame * int(packet[1]&0x3F)
	}
}

// AudioTimeline keeps an audio file continuous. Opus frames carry no duration of their own
// in WebM, and many players and ffmpeg filters play them back to back, so every hole in the
// RTP timeline (packet loss, a muted microphone, DTX) is filled with silence frames.
type AudioTimeline struct {
	started bool
	next    int64 // where the next frame is expected, in samples from the first frame
}

// Place takes a frame's position (samples since the first frame of the file) and length, and
// returns where to write it plus the positions of any silence frames that must precede it.
func (t *AudioTimeline) Place(position int64, samples int) (at int64, silence []int64) {
	if samples <= 0 {
		samples = OpusFrameSamples
	}

	if !t.started {
		t.started = true
		t.next = position
	}

	// Positions come from RTP timestamps and are exact; anything that lands before the end
	// of the previous frame is pushed back so the file never runs backwards.
	if position < t.next {
		position = t.next
	}

	for gap := position - t.next; gap >= OpusFrameSamples; gap -= OpusFrameSamples {
		silence = append(silence, t.next)
		t.next += OpusFrameSamples
	}

	t.next = position + int64(samples)

	return position, silence
}

// PadTo returns the silence frames needed to extend the file to the given position.
func (t *AudioTimeline) PadTo(position int64) (silence []int64) {
	if !t.started {
		return nil
	}

	for position-t.next >= OpusFrameSamples {
		silence = append(silence, t.next)
		t.next += OpusFrameSamples
	}

	return silence
}

// End is where the audio written so far stops, in samples from the first frame.
func (t *AudioTimeline) End() int64 {
	return t.next
}

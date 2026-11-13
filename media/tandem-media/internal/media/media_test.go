package media

import (
	"reflect"
	"testing"
	"time"

	"github.com/pion/rtp"
)

func TestOpusSamples(t *testing.T) {
	cases := []struct {
		name   string
		packet []byte
		want   int
	}{
		{"empty", nil, 0},
		{"silence frame is 20 ms", OpusSilence(), 960},
		{"SILK NB 10 ms, one frame", []byte{0 << 3}, 480},
		{"SILK WB 60 ms, one frame", []byte{11 << 3}, 2880},
		{"hybrid FB 20 ms, one frame", []byte{15 << 3}, 960},
		{"CELT FB 2.5 ms, one frame", []byte{28 << 3}, 120},
		{"CELT FB 20 ms, two frames", []byte{31<<3 | 1}, 1920},
		{"CELT FB 10 ms, three frames (code 3)", []byte{30<<3 | 3, 3}, 1440},
		{"code 3 without a count byte", []byte{30<<3 | 3}, 0},
	}

	for _, c := range cases {
		if got := OpusSamples(c.packet); got != c.want {
			t.Errorf("%s: %d samples, want %d", c.name, got, c.want)
		}
	}
}

func TestAudioTimelineFillsGapsWithSilence(t *testing.T) {
	var timeline AudioTimeline

	// Two back-to-back frames: nothing to fill.
	if at, silence := timeline.Place(0, 960); at != 0 || silence != nil {
		t.Fatalf("first frame: at=%d silence=%v", at, silence)
	}

	if at, silence := timeline.Place(960, 960); at != 960 || silence != nil {
		t.Fatalf("second frame: at=%d silence=%v", at, silence)
	}

	// Three frames went missing (60 ms): they are replaced one for one.
	at, silence := timeline.Place(4800, 960)
	if at != 4800 || !reflect.DeepEqual(silence, []int64{1920, 2880, 3840}) {
		t.Fatalf("after loss: at=%d silence=%v", at, silence)
	}

	// A hole shorter than a frame cannot hold a silence frame and is left alone.
	if at, silence := timeline.Place(5760+200, 960); at != 5960 || silence != nil {
		t.Fatalf("sub-frame gap: at=%d silence=%v", at, silence)
	}

	if timeline.End() != 6920 {
		t.Fatalf("end = %d", timeline.End())
	}
}

func TestAudioTimelineCoversALongMute(t *testing.T) {
	var timeline AudioTimeline
	timeline.Place(0, 960)

	const muted = 10 * 60 * OpusClockRate // ten minutes

	at, silence := timeline.Place(960+muted, 960)
	if at != 960+muted || len(silence) != muted/OpusFrameSamples {
		t.Fatalf("at=%d silence frames=%d, want %d", at, len(silence), muted/OpusFrameSamples)
	}

	for i, position := range silence {
		if position != int64(960+i*OpusFrameSamples) {
			t.Fatalf("silence frame %d at %d: timeline is not continuous", i, position)
		}
	}
}

func TestAudioTimelineNeverRunsBackwards(t *testing.T) {
	var timeline AudioTimeline
	timeline.Place(0, 960)
	timeline.Place(960, 960)

	if at, silence := timeline.Place(1000, 960); at != 1920 || silence != nil {
		t.Fatalf("overlapping frame placed at %d (silence %v), want 1920", at, silence)
	}
}

func TestAudioTimelinePadTo(t *testing.T) {
	var timeline AudioTimeline

	if silence := timeline.PadTo(48000); silence != nil {
		t.Fatalf("padding an empty timeline produced %v", silence)
	}

	timeline.Place(0, 960)

	silence := timeline.PadTo(960 + 3*960 + 100)
	if !reflect.DeepEqual(silence, []int64{960, 1920, 2880}) {
		t.Fatalf("pad = %v", silence)
	}
}

func packet(seq uint16, ts uint32, marker bool, payload ...byte) *rtp.Packet {
	return &rtp.Packet{Header: rtp.Header{SequenceNumber: seq, Timestamp: ts, Marker: marker}, Payload: payload}
}

func videoAssembler() *Assembler {
	return &Assembler{
		Video:        true,
		Latency:      100 * time.Millisecond,
		MaxBuffered:  64,
		IsFrameStart: func(payload []byte) bool { return payload[0] == 'S' },
	}
}

func sequences(frames []Frame) [][]uint16 {
	out := make([][]uint16, 0, len(frames))

	for _, frame := range frames {
		var seqs []uint16
		for _, p := range frame.Packets {
			seqs = append(seqs, p.SequenceNumber)
		}

		out = append(out, seqs)
	}

	return out
}

func TestAssemblerGroupsVideoPacketsByMarker(t *testing.T) {
	a := videoAssembler()
	now := time.Unix(100, 0)

	var frames []Frame
	frames = append(frames, a.Push(packet(10, 1000, false, 'S'), now)...)
	frames = append(frames, a.Push(packet(11, 1000, false, 'x'), now)...)
	frames = append(frames, a.Push(packet(12, 1000, true, 'x'), now)...)
	frames = append(frames, a.Push(packet(13, 4000, true, 'S'), now)...)

	if got := sequences(frames); !reflect.DeepEqual(got, [][]uint16{{10, 11, 12}, {13}}) {
		t.Fatalf("frames = %v", got)
	}

	if frames[0].Timestamp != 1000 || frames[0].AfterLoss || !frames[0].At.Equal(now) {
		t.Fatalf("first frame = %+v", frames[0])
	}
}

func TestAssemblerReordersWithinTheLatencyWindow(t *testing.T) {
	a := videoAssembler()
	now := time.Unix(100, 0)

	a.Push(packet(1, 1000, false, 'S'), now)

	if frames := a.Push(packet(3, 1000, true, 'x'), now); frames != nil {
		t.Fatalf("released a frame with a hole in it: %v", sequences(frames))
	}

	frames := a.Push(packet(2, 1000, false, 'x'), now.Add(20*time.Millisecond))
	if got := sequences(frames); !reflect.DeepEqual(got, [][]uint16{{1, 2, 3}}) {
		t.Fatalf("frames = %v", got)
	}

	if a.Lost() != 0 {
		t.Fatalf("lost = %d", a.Lost())
	}
}

func TestAssemblerDropsTheWholeFrameAfterLoss(t *testing.T) {
	a := videoAssembler()
	now := time.Unix(100, 0)

	a.Push(packet(1, 1000, false, 'S'), now)
	a.Push(packet(3, 1000, true, 'x'), now) // 2 never arrives
	a.Push(packet(4, 4000, false, 'S'), now)
	a.Push(packet(5, 4000, true, 'x'), now)

	if frames := a.Tick(now.Add(50 * time.Millisecond)); frames != nil {
		t.Fatalf("gave up on the missing packet too early: %v", sequences(frames))
	}

	frames := a.Tick(now.Add(150 * time.Millisecond))
	if got := sequences(frames); !reflect.DeepEqual(got, [][]uint16{{4, 5}}) {
		t.Fatalf("frames = %v, want only the intact frame", got)
	}

	if !frames[0].AfterLoss || a.Lost() != 1 {
		t.Fatalf("AfterLoss=%v lost=%d", frames[0].AfterLoss, a.Lost())
	}

	next := a.Push(packet(6, 7000, true, 'S'), now.Add(160*time.Millisecond))
	if len(next) != 1 || next[0].AfterLoss {
		t.Fatalf("loss flag must clear after one frame: %+v", next)
	}
}

func TestAssemblerSkipsPaddingWithoutCountingLoss(t *testing.T) {
	a := videoAssembler()
	now := time.Unix(100, 0)

	var frames []Frame
	frames = append(frames, a.Push(packet(1, 1000, true, 'S'), now)...)
	frames = append(frames, a.Push(packet(2, 1000, false), now)...) // padding-only probe
	frames = append(frames, a.Push(packet(3, 1000, false), now)...)
	frames = append(frames, a.Push(packet(4, 4000, true, 'S'), now)...)

	if got := sequences(frames); !reflect.DeepEqual(got, [][]uint16{{1}, {4}}) {
		t.Fatalf("frames = %v", got)
	}

	if a.Lost() != 0 || a.Received() != 2 || frames[1].AfterLoss {
		t.Fatalf("lost=%d received=%d afterLoss=%v", a.Lost(), a.Received(), frames[1].AfterLoss)
	}
}

func TestAssemblerAudioCountsLossAndSurvivesWrapAround(t *testing.T) {
	a := &Assembler{Latency: 100 * time.Millisecond, MaxBuffered: 16}
	now := time.Unix(100, 0)

	var frames []Frame
	frames = append(frames, a.Push(packet(65534, 0, false, 1), now)...)
	frames = append(frames, a.Push(packet(65535, 960, false, 1), now)...)
	frames = append(frames, a.Push(packet(1, 2880, false, 1), now)...) // 0 is lost
	frames = append(frames, a.Tick(now.Add(time.Second))...)
	frames = append(frames, a.Push(packet(65535, 960, false, 1), now.Add(time.Second))...) // stale duplicate

	if got := sequences(frames); !reflect.DeepEqual(got, [][]uint16{{65534}, {65535}, {1}}) {
		t.Fatalf("frames = %v", got)
	}

	if a.Lost() != 1 || !frames[2].AfterLoss {
		t.Fatalf("lost=%d afterLoss=%v", a.Lost(), frames[2].AfterLoss)
	}
}

func TestAssemblerFlushReleasesWhatIsComplete(t *testing.T) {
	a := videoAssembler()
	now := time.Unix(100, 0)

	a.Push(packet(1, 1000, true, 'S'), now)
	a.Push(packet(3, 4000, true, 'S'), now)  // 2 missing
	a.Push(packet(4, 7000, false, 'S'), now) // never completed

	if got := sequences(a.Flush()); !reflect.DeepEqual(got, [][]uint16{{3}}) {
		t.Fatalf("flush = %v", got)
	}
}

func TestAssemblerGivesUpWhenTheBufferIsFull(t *testing.T) {
	a := &Assembler{Latency: time.Hour, MaxBuffered: 4}
	now := time.Unix(100, 0)

	a.Push(packet(1, 0, false, 1), now)

	var frames []Frame
	for seq := uint16(3); seq <= 8; seq++ { // 2 never arrives
		frames = append(frames, a.Push(packet(seq, uint32(seq)*960, false, 1), now)...)
	}

	if len(frames) != 6 || a.Lost() != 1 {
		t.Fatalf("released %d frames, lost %d", len(frames), a.Lost())
	}
}

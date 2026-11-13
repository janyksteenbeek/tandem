package media

import (
	"time"

	"github.com/pion/rtp"
)

// Frame is a complete media frame: every RTP packet that belongs to it, in order.
type Frame struct {
	Packets []*rtp.Packet
	// Timestamp is the RTP timestamp shared by the packets.
	Timestamp uint32
	// At is when the first packet of the frame arrived.
	At time.Time
	// AfterLoss is set on the first frame released after packets went missing.
	AfterLoss bool
}

// Assembler puts RTP packets back in order and groups them into frames. Missing packets are
// waited for briefly (retransmissions arrive within a round trip); after that the frame they
// belonged to is dropped whole, because a partial video frame poisons everything up to the
// next keyframe. It is not safe for concurrent use.
type Assembler struct {
	// Video frames end on the RTP marker bit; every audio packet is a frame.
	Video bool
	// IsFrameStart recognises the first packet of a video frame, to resynchronise after loss.
	IsFrameStart func(payload []byte) bool
	// Latency is how long to wait for a missing packet.
	Latency time.Duration
	// MaxBuffered caps how many out-of-order packets are held before giving up on a gap.
	MaxBuffered int

	started bool
	next    uint16
	pending map[uint16]pendingPacket

	current   []*rtp.Packet
	currentAt time.Time
	resync    bool
	lossSeen  bool

	received uint64
	lost     uint64
}

type pendingPacket struct {
	packet *rtp.Packet
	at     time.Time
}

// maxJump is the forward sequence distance beyond which the stream is treated as restarted.
const maxJump = 3000

// Received is the number of media packets accepted so far (padding excluded).
func (a *Assembler) Received() uint64 { return a.received }

// Lost is the number of packets that never arrived.
func (a *Assembler) Lost() uint64 { return a.lost }

// Push adds a packet and returns the frames it completes.
func (a *Assembler) Push(packet *rtp.Packet, at time.Time) []Frame {
	if a.pending == nil {
		a.pending = make(map[uint16]pendingPacket)
	}

	seq := packet.SequenceNumber

	switch distance := int16(seq - a.next); { //nolint:gosec // wrap-around arithmetic
	case !a.started:
		a.started = true
		a.next = seq
	case distance < 0 && distance > -maxJump:
		return nil // late duplicate of something already released or given up on
	case distance < 0 || distance > maxJump:
		// The sender restarted its numbering: drop what is buffered and start over.
		a.pending = make(map[uint16]pendingPacket)
		a.next = seq
		a.breakFrame()
	}

	if _, duplicate := a.pending[seq]; duplicate {
		return nil
	}

	a.pending[seq] = pendingPacket{packet, at}

	return a.drain(at, false)
}

// Tick releases frames that were waiting on a packet for longer than Latency.
func (a *Assembler) Tick(now time.Time) []Frame {
	if len(a.pending) == 0 {
		return nil
	}

	return a.drain(now, false)
}

// Flush releases every complete frame still buffered. Call it when the track ends.
func (a *Assembler) Flush() []Frame {
	return a.drain(time.Time{}, true)
}

func (a *Assembler) drain(now time.Time, force bool) []Frame {
	var frames []Frame

	for len(a.pending) > 0 {
		if entry, ok := a.pending[a.next]; ok {
			delete(a.pending, a.next)
			a.next++

			if frame, complete := a.consume(entry); complete {
				frames = append(frames, frame)
			}

			continue
		}

		// The next packet is missing. Keep waiting unless it has been too long.
		nearest, oldest := a.nearest()
		if !force && len(a.pending) <= a.MaxBuffered && now.Sub(oldest) < a.Latency {
			break
		}

		a.lost += uint64(nearest - a.next)
		a.next = nearest
		a.breakFrame()
	}

	return frames
}

// nearest returns the closest buffered sequence number after the gap and the arrival time of
// the packet that has been waiting longest.
func (a *Assembler) nearest() (uint16, time.Time) {
	var (
		best     uint16
		bestDist = uint16(0xFFFF)
		oldest   time.Time
	)

	for seq, entry := range a.pending {
		if dist := seq - a.next; dist < bestDist {
			best, bestDist = seq, dist
		}

		if oldest.IsZero() || entry.at.Before(oldest) {
			oldest = entry.at
		}
	}

	return best, oldest
}

// breakFrame discards the frame under construction after a discontinuity.
func (a *Assembler) breakFrame() {
	a.current = nil
	a.lossSeen = true
	a.resync = a.Video
}

func (a *Assembler) consume(entry pendingPacket) (Frame, bool) {
	packet := entry.packet

	// Padding-only packets occupy a sequence number but carry no media.
	if len(packet.Payload) == 0 {
		return Frame{}, false
	}

	a.received++

	if !a.Video {
		return a.release([]*rtp.Packet{packet}, entry.at), true
	}

	if a.resync {
		if a.IsFrameStart == nil || !a.IsFrameStart(packet.Payload) {
			return Frame{}, false
		}

		a.resync = false
	}

	// A new timestamp without a marker on the previous packet: the sender moved on.
	if len(a.current) > 0 && a.current[0].Timestamp != packet.Timestamp {
		a.current = nil
		a.lossSeen = true

		if a.IsFrameStart != nil && !a.IsFrameStart(packet.Payload) {
			a.resync = true

			return Frame{}, false
		}
	}

	if len(a.current) == 0 {
		a.currentAt = entry.at
	}

	a.current = append(a.current, packet)

	if !packet.Marker {
		return Frame{}, false
	}

	frame := a.release(a.current, a.currentAt)
	a.current = nil

	return frame, true
}

func (a *Assembler) release(packets []*rtp.Packet, at time.Time) Frame {
	frame := Frame{Packets: packets, Timestamp: packets[0].Timestamp, At: at, AfterLoss: a.lossSeen}
	a.lossSeen = false

	return frame
}

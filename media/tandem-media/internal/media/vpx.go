package media

import (
	"encoding/binary"

	"github.com/pion/rtp"
	"github.com/pion/rtp/codecs"
	"github.com/pion/rtp/codecs/vp9"
)

// VideoFrame is a depacketised VP8 or VP9 frame.
type VideoFrame struct {
	Data     []byte
	Keyframe bool
	// Width and Height are set when the frame announces them (keyframes).
	Width, Height int
	// Layers is the number of spatial layers joined into this frame (1 unless VP9 SVC).
	Layers int
}

// VP8IsFrameStart reports whether an RTP payload opens a VP8 frame: start of partition 0.
func VP8IsFrameStart(payload []byte) bool {
	var packet codecs.VP8Packet
	if _, err := packet.Unmarshal(payload); err != nil {
		return false
	}

	return packet.S == 1 && packet.PID == 0
}

// VP9IsFrameStart reports whether an RTP payload opens a VP9 picture: the beginning of the
// lowest spatial layer present.
func VP9IsFrameStart(payload []byte) bool {
	var packet codecs.VP9Packet
	if _, err := packet.Unmarshal(payload); err != nil {
		return false
	}

	return packet.B && (!packet.L || packet.SID == 0 || !packet.D)
}

// DepacketizeVP8 joins the packets of one frame.
func DepacketizeVP8(packets []*rtp.Packet) (VideoFrame, bool) {
	var frame VideoFrame

	for _, p := range packets {
		var packet codecs.VP8Packet

		payload, err := packet.Unmarshal(p.Payload)
		if err != nil {
			return VideoFrame{}, false
		}

		frame.Data = append(frame.Data, payload...)
	}

	if len(frame.Data) < 3 {
		return VideoFrame{}, false
	}

	// Frame tag, RFC 6386 §9.1: bit 0 clear means keyframe; a keyframe continues with the
	// start code 9d 01 2a and 14-bit width and height.
	frame.Keyframe = frame.Data[0]&0x01 == 0
	frame.Layers = 1

	if frame.Keyframe && len(frame.Data) >= 10 && frame.Data[3] == 0x9D && frame.Data[4] == 0x01 && frame.Data[5] == 0x2A {
		frame.Width = int(binary.LittleEndian.Uint16(frame.Data[6:]) & 0x3FFF)
		frame.Height = int(binary.LittleEndian.Uint16(frame.Data[8:]) & 0x3FFF)
	}

	return frame, true
}

// VP9Depacketizer joins the packets of one VP9 picture. It remembers the scalability
// structure the sender announces on keyframes, which is where the resolution of each spatial
// layer comes from when a picture carries more than one.
type VP9Depacketizer struct {
	widths, heights []uint16
}

// Depacketize joins the packets of one picture. All spatial layers of the picture end up in
// the same frame, lowest first, which is the order decoders expect.
func (d *VP9Depacketizer) Depacketize(packets []*rtp.Packet) (VideoFrame, bool) {
	var (
		frame      VideoFrame
		topLayer   int
		firstLayer []byte
		layers     int
	)

	for i, p := range packets {
		var packet codecs.VP9Packet

		payload, err := packet.Unmarshal(p.Payload)
		if err != nil {
			return VideoFrame{}, false
		}

		if i == 0 {
			// P is clear when the picture does not depend on earlier pictures.
			frame.Keyframe = !packet.P && packet.B
		}

		if packet.V && packet.Y && len(packet.Width) > 0 {
			d.widths = append(d.widths[:0], packet.Width...)
			d.heights = append(d.heights[:0], packet.Height...)
		}

		if packet.L && int(packet.SID) > topLayer {
			topLayer = int(packet.SID)
		}

		if packet.B {
			layers++
		}

		if layers <= 1 {
			firstLayer = append(firstLayer, payload...)
		}

		frame.Data = append(frame.Data, payload...)
	}

	if len(frame.Data) == 0 {
		return VideoFrame{}, false
	}

	frame.Layers = max(layers, 1)

	if !frame.Keyframe {
		return frame, true
	}

	if topLayer < len(d.widths) && topLayer < len(d.heights) {
		frame.Width, frame.Height = int(d.widths[topLayer]), int(d.heights[topLayer])

		return frame, true
	}

	// No scalability structure: read the size from the bitstream of the (only) layer.
	var header vp9.Header
	if err := header.Unmarshal(firstLayer); err == nil && !header.NonKeyFrame && header.FrameSize != nil {
		frame.Width, frame.Height = int(header.Width()), int(header.Height())
	}

	return frame, true
}

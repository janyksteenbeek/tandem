package webm

import (
	"bytes"
	"encoding/binary"
	"errors"
	"io"
	"math"
	"os"

	"github.com/at-wat/ebml-go"
)

// Matroska element IDs this package needs to recognise.
const (
	idEBML        = 0x1A45DFA3
	idSegment     = 0x18538067
	idSeekHead    = 0x114D9B74
	idInfo        = 0x1549A966
	idTracks      = 0x1654AE6B
	idCluster     = 0x1F43B675
	idCues        = 0x1C53BB6B
	idVoid        = 0xEC
	idTimecode    = 0xE7
	idSimpleBlock = 0xA3
	idDuration    = 0x4489
	idCuePoint    = 0xBB
)

// ErrNotWebM is returned for files that do not start like the ones this package writes.
var ErrNotWebM = errors.New("webm: not a WebM file")

// Info describes a file as found on disk.
type Info struct {
	// DurationMs is the Segment Duration stored in the header (0 while unfinished).
	DurationMs float64
	// LastTimestampMs is the timestamp of the newest complete frame.
	LastTimestampMs int64
	Frames          int
	Clusters        int
	CuePoints       int
	// Finalised reports that the file has a seek index and a real duration.
	Finalised bool
	Size      int64
}

type cluster struct {
	offset   int64 // from the start of the file
	timecode int64
	keyframe bool
	frames   int
}

type layout struct {
	segmentData  int64
	durationPos  int64 // file offset of the Duration element, 0 when absent
	duration     float64
	reservedPos  int64 // file offset of the Void kept for Cues, 0 when absent
	reservedSize int64
	cuesPos      int64
	cuePoints    int
	clusters     []cluster
	lastMs       int64
	frames       int
	validEnd     int64 // everything before this offset is a complete element
	size         int64
}

// Inspect reads the structure of a file without changing it.
func Inspect(path string) (Info, error) {
	file, err := os.Open(path)
	if err != nil {
		return Info{}, err
	}
	defer file.Close()

	l, err := scan(file)
	if err != nil {
		return Info{}, err
	}

	return l.info(), nil
}

// Repair finishes a file whose writer never got to close it: it cuts off a half-written
// trailing frame, builds the seek index from the clusters that made it to disk, and stores
// the real duration. Both go into space the writer reserved in the header, so nothing is
// rewritten and the cost is one pass over the frame headers. Finalised files are left alone.
func Repair(path string) (info Info, repaired bool, err error) {
	file, err := os.OpenFile(path, os.O_RDWR, 0)
	if err != nil {
		return Info{}, false, err
	}
	defer file.Close()

	l, err := scan(file)
	if err != nil {
		return Info{}, false, err
	}

	if l.finalised() || l.frames == 0 {
		return l.info(), false, nil
	}

	if l.validEnd < l.size {
		if err := file.Truncate(l.validEnd); err != nil {
			return l.info(), false, err
		}

		l.size = l.validEnd
	}

	if l.reservedPos > 0 {
		cues, count := encodeCues(l.cuePointsFromClusters(), int(l.reservedSize))
		if cues != nil {
			if _, err := file.WriteAt(cues, l.reservedPos); err != nil {
				return l.info(), false, err
			}

			l.cuesPos, l.cuePoints = l.reservedPos, count
		}
	}

	if l.durationPos > 0 {
		var raw [8]byte
		binary.BigEndian.PutUint64(raw[:], math.Float64bits(float64(l.lastMs)))

		// Element layout: 2-byte ID, 1-byte size (0x88), 8-byte float.
		if _, err := file.WriteAt(raw[:], l.durationPos+3); err != nil {
			return l.info(), false, err
		}

		l.duration = float64(l.lastMs)
	}

	if err := file.Sync(); err != nil {
		return l.info(), false, err
	}

	return l.info(), true, nil
}

func (l *layout) finalised() bool {
	// The writer stores the smallest positive float as a placeholder until it closes the file.
	return l.cuesPos > 0 && l.duration >= 1
}

func (l *layout) info() Info {
	return Info{
		DurationMs:      l.duration,
		LastTimestampMs: l.lastMs,
		Frames:          l.frames,
		Clusters:        len(l.clusters),
		CuePoints:       l.cuePoints,
		Finalised:       l.finalised(),
		Size:            l.size,
	}
}

type cuePosition struct {
	CueTrack           uint64 `ebml:"CueTrack"`
	CueClusterPosition uint64 `ebml:"CueClusterPosition"`
}

type cuePoint struct {
	CueTime           uint64        `ebml:"CueTime"`
	CueTrackPositions []cuePosition `ebml:"CueTrackPositions"`
}

// cuePointsFromClusters indexes every cluster that opens with a keyframe.
func (l *layout) cuePointsFromClusters() []cuePoint {
	points := make([]cuePoint, 0, len(l.clusters))

	for _, c := range l.clusters {
		if c.frames == 0 || !c.keyframe {
			continue
		}

		points = append(points, cuePoint{
			CueTime: uint64(c.timecode), //nolint:gosec // never negative
			CueTrackPositions: []cuePosition{{
				CueTrack:           trackNumber,
				CueClusterPosition: uint64(c.offset - l.segmentData), //nolint:gosec // clusters follow the header
			}},
		})
	}

	return points
}

// encodeCues renders a Cues element padded with a Void to exactly size bytes, thinning the
// index until it fits. It returns nil when not even one entry fits.
func encodeCues(points []cuePoint, size int) ([]byte, int) {
	for len(points) > 0 {
		var buffer bytes.Buffer

		element := struct {
			Cues struct {
				CuePoint []cuePoint `ebml:"CuePoint"`
			} `ebml:"Cues"`
		}{}
		element.Cues.CuePoint = points

		if err := ebml.Marshal(&element, &buffer); err != nil {
			return nil, 0
		}

		// The remainder must be empty or large enough to hold a Void header (ID + 8-byte size).
		if remaining := size - buffer.Len(); remaining == 0 || remaining >= 9 {
			return append(buffer.Bytes(), void(remaining)...), len(points)
		}

		if len(points) == 1 {
			break
		}

		thinned := make([]cuePoint, 0, len(points)/2+1)
		for i := 0; i < len(points); i += 2 {
			thinned = append(thinned, points[i])
		}

		points = thinned
	}

	return nil, 0
}

func void(size int) []byte {
	if size == 0 {
		return nil
	}

	element := make([]byte, size)
	element[0] = idVoid
	element[1] = 0x01 // 8-byte size follows
	binary.BigEndian.PutUint64(element[1:9], uint64(size-9)|1<<56)

	return element
}

// scan walks the top level of the Segment and the frame headers inside every cluster.
func scan(file *os.File) (*layout, error) {
	stat, err := file.Stat()
	if err != nil {
		return nil, err
	}

	r := &reader{src: file, size: stat.Size()}
	l := &layout{size: stat.Size()}

	header, err := r.element(0)
	if err != nil || header.id != idEBML || header.unknown {
		return nil, ErrNotWebM
	}

	segment, err := r.element(header.end())
	if err != nil || segment.id != idSegment {
		return nil, ErrNotWebM
	}

	l.segmentData = segment.data
	l.validEnd = segment.data

	for pos := segment.data; pos < l.size; {
		el, err := r.element(pos)
		if err != nil {
			break // half-written element header at the end of the file
		}

		if el.id == idCluster {
			next, complete := l.scanCluster(r, el)
			if !complete {
				break
			}

			pos = next

			continue
		}

		if el.unknown || el.end() > l.size {
			break
		}

		switch el.id {
		case idInfo:
			l.scanInfo(r, el)
		case idVoid:
			if len(l.clusters) == 0 && l.reservedPos == 0 {
				l.reservedPos, l.reservedSize = el.pos, el.end()-el.pos
			}
		case idCues:
			if l.cuesPos == 0 {
				l.cuesPos = el.pos
				l.cuePoints = r.countChildren(el, idCuePoint)
			}
		}

		pos = el.end()
		l.validEnd = pos
	}

	return l, nil
}

// scanCluster records a cluster and its frames. It returns where the next top-level element
// starts and whether the cluster was read without hitting the end of the file mid-element.
func (l *layout) scanCluster(r *reader, el element) (int64, bool) {
	c := cluster{offset: el.pos}
	limit := l.size
	if !el.unknown {
		limit = min(el.end(), l.size)
	}

	pos := el.data
	complete := true

	for pos < limit {
		child, err := r.element(pos)
		if err != nil {
			complete = false

			break
		}

		// A cluster of unknown size ends where the next top-level element begins.
		if el.unknown && isTopLevel(child.id) {
			break
		}

		if child.unknown || child.end() > l.size {
			complete = false

			break
		}

		switch child.id {
		case idTimecode:
			c.timecode = int64(r.uint(child)) //nolint:gosec // milliseconds
		case idSimpleBlock:
			offset, flags, ok := r.blockHeader(child)
			if !ok {
				complete = false
			} else {
				if c.frames == 0 {
					c.keyframe = flags&0x80 != 0
				}

				c.frames++
				l.frames++
				l.lastMs = max(l.lastMs, c.timecode+int64(offset))
			}
		}

		if !complete {
			break
		}

		pos = child.end()
		l.validEnd = pos
	}

	l.clusters = append(l.clusters, c)

	return pos, complete
}

func (l *layout) scanInfo(r *reader, el element) {
	for pos := el.data; pos < el.end(); {
		child, err := r.element(pos)
		if err != nil || child.unknown || child.end() > el.end() {
			return
		}

		if child.id == idDuration && child.size == 8 && child.data-child.pos == 3 {
			var raw [8]byte
			if _, err := r.src.ReadAt(raw[:], child.data); err == nil {
				l.durationPos = child.pos
				l.duration = math.Float64frombits(binary.BigEndian.Uint64(raw[:]))
			}
		}

		pos = child.end()
	}
}

func isTopLevel(id uint32) bool {
	switch id {
	case idCluster, idCues, idSeekHead, idInfo, idTracks, idSegment, idEBML:
		return true
	}

	return false
}

type element struct {
	id      uint32
	pos     int64 // offset of the ID
	data    int64 // offset of the payload
	size    int64
	unknown bool
}

func (e element) end() int64 { return e.data + e.size }

type reader struct {
	src  io.ReaderAt
	size int64
}

// element reads the ID and size of the element at pos.
func (r *reader) element(pos int64) (element, error) {
	var head [12]byte

	n, err := r.src.ReadAt(head[:], pos)
	if n == 0 {
		if err == nil {
			err = io.ErrUnexpectedEOF
		}

		return element{}, err
	}

	buffer := head[:n]

	idLength := vintLength(buffer[0])
	if idLength == 0 || idLength > 4 || idLength > len(buffer) {
		return element{}, io.ErrUnexpectedEOF
	}

	var id uint32
	for _, b := range buffer[:idLength] {
		id = id<<8 | uint32(b)
	}

	buffer = buffer[idLength:]
	if len(buffer) == 0 {
		return element{}, io.ErrUnexpectedEOF
	}

	sizeLength := vintLength(buffer[0])
	if sizeLength == 0 || sizeLength > len(buffer) {
		return element{}, io.ErrUnexpectedEOF
	}

	size := uint64(buffer[0]) & (0xFF >> sizeLength)
	unknown := size == uint64(0xFF>>sizeLength)

	for _, b := range buffer[1:sizeLength] {
		size = size<<8 | uint64(b)
		unknown = unknown && b == 0xFF
	}

	el := element{id: id, pos: pos, data: pos + int64(idLength+sizeLength), unknown: unknown}
	if !unknown {
		if size > math.MaxInt64/2 {
			return element{}, io.ErrUnexpectedEOF
		}

		el.size = int64(size)
	}

	return el, nil
}

func (r *reader) uint(el element) uint64 {
	if el.size < 1 || el.size > 8 {
		return 0
	}

	raw := make([]byte, el.size)
	if _, err := r.src.ReadAt(raw, el.data); err != nil {
		return 0
	}

	var value uint64
	for _, b := range raw {
		value = value<<8 | uint64(b)
	}

	return value
}

// blockHeader returns a SimpleBlock's timestamp offset within its cluster and its flags.
func (r *reader) blockHeader(el element) (int16, byte, bool) {
	var raw [12]byte

	n, _ := r.src.ReadAt(raw[:min(int64(len(raw)), el.size)], el.data)
	if n < 4 {
		return 0, 0, false
	}

	trackLength := vintLength(raw[0])
	if trackLength == 0 || trackLength+3 > n {
		return 0, 0, false
	}

	offset := int16(binary.BigEndian.Uint16(raw[trackLength:])) //nolint:gosec // signed by definition

	return offset, raw[trackLength+2], true
}

func (r *reader) countChildren(el element, id uint32) int {
	count := 0

	for pos := el.data; pos < el.end(); {
		child, err := r.element(pos)
		if err != nil || child.unknown || child.end() > el.end() {
			break
		}

		if child.id == id {
			count++
		}

		pos = child.end()
	}

	return count
}

// vintLength returns how many bytes an EBML variable-length integer occupies, from its first byte.
func vintLength(first byte) int {
	for length := 1; length <= 8; length++ {
		if first&(0x80>>(length-1)) != 0 {
			return length
		}
	}

	return 0
}

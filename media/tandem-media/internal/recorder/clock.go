package recorder

import (
	"sync"
	"time"
)

// sfuClock translates the media server's clock into ours. RTCP sender reports stamp an RTP
// timestamp with the server's wall clock; the smallest "arrival minus stamp" seen recently is
// the offset between the two clocks plus the (tiny, same-host or same-LAN) network delay.
// One offset is shared by every track of a recording, so the tracks stay aligned with each
// other even when the two machines disagree about the time of day.
type sfuClock struct {
	mu      sync.Mutex
	samples [32]time.Duration
	count   int
}

func (c *sfuClock) observe(stamp, arrival time.Time) {
	c.mu.Lock()
	c.samples[c.count%len(c.samples)] = arrival.Sub(stamp)
	c.count++
	c.mu.Unlock()
}

// local converts a server timestamp to the local clock.
func (c *sfuClock) local(stamp time.Time) (time.Time, bool) {
	c.mu.Lock()
	defer c.mu.Unlock()

	n := min(c.count, len(c.samples))
	if n == 0 {
		return time.Time{}, false
	}

	offset := c.samples[0]
	for _, sample := range c.samples[1:n] {
		offset = min(offset, sample)
	}

	return stamp.Add(offset), true
}

// Package webhook reports recording events to Tandem. Delivery happens on its own goroutine
// with retries, so a slow or unreachable application never holds up media.
package webhook

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"sync"
	"time"

	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/signature"
)

// Event names sent to Tandem.
const (
	EventStarted  = "recording.started"
	EventProgress = "recording.progress"
	EventWarning  = "recording.warning"
	EventFailed   = "recording.failed"
	EventFinished = "recording.finished"
)

// Warning codes.
const (
	WarnDiskLow          = "disk_low"
	WarnTrackInterrupted = "track_interrupted"
	WarnReconnecting     = "reconnecting"
	WarnRecovered        = "recovered"
)

// backoff is the wait before each retry. Progress pings are not retried at all: the next
// one supersedes them.
var backoff = []time.Duration{time.Second, 2 * time.Second, 4 * time.Second, 8 * time.Second, 15 * time.Second, 30 * time.Second, time.Minute, time.Minute}

// Notifier delivers events in the order they were sent.
type Notifier struct {
	url    string
	secret string
	client *http.Client
	log    *slog.Logger
	queue  chan delivery
	done   chan struct{}
	once   sync.Once
	now    func() time.Time
	wait   func(context.Context, time.Duration) bool
}

type delivery struct {
	event       string
	body        []byte
	attempts    int
	onDelivered func()
}

// New starts a notifier. With an empty URL events are logged and dropped.
func New(url, secret string, log *slog.Logger) *Notifier {
	n := &Notifier{
		url:    url,
		secret: secret,
		client: &http.Client{Timeout: 10 * time.Second},
		log:    log,
		queue:  make(chan delivery, 1024),
		done:   make(chan struct{}),
		now:    time.Now,
		wait:   sleep,
	}

	go n.run()

	return n
}

// Send queues an event. The fields are merged into {"event", "id", "at"}. onDelivered, when
// set, runs once Tandem has answered with a 2xx status.
func (n *Notifier) Send(event, id string, fields map[string]any, onDelivered func()) {
	payload := make(map[string]any, len(fields)+3)
	for key, value := range fields {
		payload[key] = value
	}

	payload["event"] = event
	payload["id"] = id
	payload["at"] = n.now().UTC().Format(time.RFC3339)

	body, err := json.Marshal(payload)
	if err != nil {
		n.log.Error("webhook payload could not be encoded", "event", event, "recording", id, "error", err)

		return
	}

	if n.url == "" {
		n.log.Debug("webhook not configured, event dropped", "event", event, "recording", id)

		return
	}

	attempts := 1 + len(backoff)
	if event == EventProgress {
		attempts = 1
	}

	select {
	case n.queue <- delivery{event: event, body: body, attempts: attempts, onDelivered: onDelivered}:
	default:
		n.log.Error("webhook queue is full, event dropped", "event", event, "recording", id)
	}
}

// Close delivers what is queued, waiting at most until the context ends.
func (n *Notifier) Close(ctx context.Context) {
	n.once.Do(func() { close(n.queue) })

	select {
	case <-n.done:
	case <-ctx.Done():
	}
}

func (n *Notifier) run() {
	defer close(n.done)

	for d := range n.queue {
		n.safely(d)
	}
}

// safely keeps the delivery goroutine alive if a callback panics.
func (n *Notifier) safely(d delivery) {
	defer func() {
		if cause := recover(); cause != nil {
			n.log.Error("recovered from a panic while delivering a webhook", "event", d.event, "panic", fmt.Sprint(cause))
		}
	}()

	n.deliver(d)
}

func (n *Notifier) deliver(d delivery) {
	for attempt := 1; attempt <= d.attempts; attempt++ {
		err := n.post(d.body)
		if err == nil {
			if d.onDelivered != nil {
				d.onDelivered()
			}

			return
		}

		if attempt == d.attempts {
			n.log.Error("webhook delivery failed, giving up", "event", d.event, "attempts", attempt, "error", err)

			return
		}

		n.log.Warn("webhook delivery failed, retrying", "event", d.event, "attempt", attempt, "error", err)

		if !n.wait(context.Background(), backoff[attempt-1]) {
			return
		}
	}
}

func (n *Notifier) post(body []byte) error {
	request, err := http.NewRequest(http.MethodPost, n.url, bytes.NewReader(body))
	if err != nil {
		return err
	}

	request.Header.Set("Content-Type", "application/json")
	request.Header.Set("Accept", "application/json")
	request.Header.Set("User-Agent", "tandem-recorder")
	request.Header.Set(signature.Header, signature.Sign(n.secret, body, n.now().Unix()))

	response, err := n.client.Do(request)
	if err != nil {
		return err
	}
	defer response.Body.Close()

	_, _ = io.Copy(io.Discard, io.LimitReader(response.Body, 64<<10))

	if response.StatusCode < 200 || response.StatusCode >= 300 {
		return fmt.Errorf("unexpected status %d", response.StatusCode)
	}

	return nil
}

func sleep(ctx context.Context, d time.Duration) bool {
	timer := time.NewTimer(d)
	defer timer.Stop()

	select {
	case <-timer.C:
		return true
	case <-ctx.Done():
		return false
	}
}

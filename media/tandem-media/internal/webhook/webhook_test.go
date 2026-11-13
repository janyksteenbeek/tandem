package webhook

import (
	"context"
	"encoding/json"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/signature"
)

func quiet() *slog.Logger {
	return slog.New(slog.NewTextHandler(io.Discard, nil))
}

func TestEventsAreSignedAndDeliveredInOrder(t *testing.T) {
	var (
		mu     sync.Mutex
		events []string
	)

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)

		if !signature.Verify("s3cret", body, r.Header.Get(signature.Header), time.Now().Unix()) {
			t.Errorf("signature did not verify: %s", r.Header.Get(signature.Header))
		}

		var payload map[string]any
		if err := json.Unmarshal(body, &payload); err != nil {
			t.Errorf("body is not JSON: %v", err)
		}

		if payload["id"] != "rec-1" || payload["at"] == "" {
			t.Errorf("payload = %v", payload)
		}

		mu.Lock()
		events = append(events, payload["event"].(string))
		mu.Unlock()
	}))
	defer server.Close()

	n := New(server.URL, "s3cret", quiet())
	delivered := make(chan struct{})

	n.Send(EventStarted, "rec-1", map[string]any{"started_at": "now"}, nil)
	n.Send(EventProgress, "rec-1", map[string]any{"duration_ms": 15000}, nil)
	n.Send(EventFinished, "rec-1", map[string]any{"manifest": map[string]any{}}, func() { close(delivered) })

	select {
	case <-delivered:
	case <-time.After(5 * time.Second):
		t.Fatal("finished event was never acknowledged")
	}

	mu.Lock()
	defer mu.Unlock()

	if len(events) != 3 || events[0] != EventStarted || events[1] != EventProgress || events[2] != EventFinished {
		t.Fatalf("events = %v", events)
	}
}

func TestFailedDeliveriesAreRetriedButProgressIsNot(t *testing.T) {
	var progress, finished atomic.Int32

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)

		var payload map[string]any
		_ = json.Unmarshal(body, &payload)

		switch payload["event"] {
		case EventProgress:
			progress.Add(1)
			w.WriteHeader(http.StatusInternalServerError)
		case EventFinished:
			if finished.Add(1) < 3 {
				w.WriteHeader(http.StatusBadGateway)
			}
		}
	}))
	defer server.Close()

	n := New(server.URL, "s3cret", quiet())
	n.wait = func(context.Context, time.Duration) bool { return true } // no real backoff in tests

	acknowledged := make(chan struct{})
	n.Send(EventProgress, "rec-1", nil, func() { t.Error("a failed delivery must not be acknowledged") })
	n.Send(EventFinished, "rec-1", nil, func() { close(acknowledged) })

	select {
	case <-acknowledged:
	case <-time.After(5 * time.Second):
		t.Fatal("finished event was never acknowledged")
	}

	if progress.Load() != 1 || finished.Load() != 3 {
		t.Fatalf("progress attempts = %d (want 1), finished attempts = %d (want 3)", progress.Load(), finished.Load())
	}
}

func TestSendNeverBlocksWithoutAnEndpoint(t *testing.T) {
	n := New("", "s3cret", quiet())

	done := make(chan struct{})
	go func() {
		for i := 0; i < 5000; i++ {
			n.Send(EventProgress, "rec-1", nil, nil)
		}
		close(done)
	}()

	select {
	case <-done:
	case <-time.After(2 * time.Second):
		t.Fatal("Send blocked")
	}

	ctx, cancel := context.WithTimeout(context.Background(), time.Second)
	defer cancel()
	n.Close(ctx)
}

package api

import (
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/manifest"
	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/recorder"
)

// fakeRecordings stands in for the manager; it validates requests the same way.
type fakeRecordings struct {
	started   []recorder.StartRequest
	startErr  error
	unhealthy bool
}

func (f *fakeRecordings) Start(request recorder.StartRequest) (string, error) {
	request, err := request.Validate()
	if err != nil {
		return "", err
	}

	if f.startErr != nil {
		return "", f.startErr
	}

	f.started = append(f.started, request)

	return recorder.StatusStarting, nil
}

func (f *fakeRecordings) Stop(id string) error {
	if id != "rec-1" {
		return recorder.ErrNotFound
	}

	return nil
}

func (f *fakeRecordings) Get(id string) (string, *manifest.Manifest, error) {
	if id != "rec-1" {
		return "", nil, recorder.ErrNotFound
	}

	return recorder.StatusRecording, manifest.New("rec-1", "room", "full", time.Unix(1700000000, 0)), nil
}

func (f *fakeRecordings) Stats() recorder.Stats {
	return recorder.Stats{Version: "test", Recordings: []recorder.Snapshot{}}
}

func (f *fakeRecordings) Healthy() error {
	if f.unhealthy {
		return errors.New("read-only file system")
	}

	return nil
}

const secret = "s3cret"

func call(t *testing.T, recordings Recordings, method, path, token, body string) (*http.Response, map[string]any) {
	t.Helper()

	request := httptest.NewRequest(method, path, strings.NewReader(body))
	if token != "" {
		request.Header.Set("Authorization", "Bearer "+token)
	}

	response := httptest.NewRecorder()
	New(recordings, secret, slog.New(slog.NewTextHandler(io.Discard, nil))).ServeHTTP(response, request)

	var decoded map[string]any
	_ = json.NewDecoder(response.Body).Decode(&decoded)

	return response.Result(), decoded
}

func TestEverythingButHealthNeedsTheSecret(t *testing.T) {
	recordings := &fakeRecordings{}

	protected := []struct{ method, path string }{
		{http.MethodGet, "/stats"},
		{http.MethodPost, "/recordings"},
		{http.MethodGet, "/recordings/rec-1"},
		{http.MethodPost, "/recordings/rec-1/stop"},
	}

	for _, route := range protected {
		for _, token := range []string{"", "wrong", secret + "x", "s3cre"} {
			if response, _ := call(t, recordings, route.method, route.path, token, `{}`); response.StatusCode != http.StatusUnauthorized {
				t.Errorf("%s %s with token %q: status %d, want 401", route.method, route.path, token, response.StatusCode)
			}
		}
	}

	if len(recordings.started) != 0 {
		t.Fatal("an unauthenticated request started a recording")
	}

	if response, body := call(t, recordings, http.MethodGet, "/healthz", "", ""); response.StatusCode != http.StatusOK || body["status"] != "ok" {
		t.Fatalf("healthz: %d %v", response.StatusCode, body)
	}
}

func TestHealthReportsAnUnwritableDirectory(t *testing.T) {
	if response, _ := call(t, &fakeRecordings{unhealthy: true}, http.MethodGet, "/healthz", "", ""); response.StatusCode != http.StatusServiceUnavailable {
		t.Fatalf("status %d, want 503", response.StatusCode)
	}
}

func TestHealthFailsWhenAnExtraCheckFails(t *testing.T) {
	log := slog.New(slog.NewTextHandler(io.Discard, nil))
	sfuDown := func() error { return errors.New("media server is not running") }
	fine := func() error { return nil }

	cases := []struct {
		name    string
		handler http.Handler
		want    int
	}{
		{"recorder fine, media server down", New(&fakeRecordings{}, secret, log, sfuDown), http.StatusServiceUnavailable},
		{"recorder down, media server fine", New(&fakeRecordings{unhealthy: true}, secret, log, fine), http.StatusServiceUnavailable},
		{"both fine", New(&fakeRecordings{}, secret, log, fine), http.StatusOK},
		{"recording disabled, media server fine", HealthOnly(log, fine), http.StatusOK},
		{"recording disabled, media server down", HealthOnly(log, sfuDown), http.StatusServiceUnavailable},
	}

	for _, c := range cases {
		response := httptest.NewRecorder()
		c.handler.ServeHTTP(response, httptest.NewRequest(http.MethodGet, "/healthz", nil))

		if response.Code != c.want {
			t.Errorf("%s: status %d, want %d", c.name, response.Code, c.want)
		}
	}
}

func TestDisabledRecorderExposesNothingButHealth(t *testing.T) {
	handler := HealthOnly(slog.New(slog.NewTextHandler(io.Discard, nil)))

	for _, route := range []struct{ method, path string }{
		{http.MethodGet, "/stats"},
		{http.MethodPost, "/recordings"},
		{http.MethodGet, "/recordings/rec-1"},
		{http.MethodPost, "/recordings/rec-1/stop"},
	} {
		request := httptest.NewRequest(route.method, route.path, strings.NewReader(`{}`))
		request.Header.Set("Authorization", "Bearer "+secret)

		response := httptest.NewRecorder()
		handler.ServeHTTP(response, request)

		if response.Code != http.StatusNotFound {
			t.Errorf("%s %s: status %d, want 404", route.method, route.path, response.Code)
		}
	}
}

func TestStartRecording(t *testing.T) {
	recordings := &fakeRecordings{}

	response, body := call(t, recordings, http.MethodPost, "/recordings", secret,
		`{"id":"rec-1","room":"call_abc","mode":"screen","path":"calluuid/recuuid"}`)

	if response.StatusCode != http.StatusAccepted || body["id"] != "rec-1" || body["status"] != "starting" {
		t.Fatalf("start: %d %v", response.StatusCode, body)
	}

	if len(recordings.started) != 1 || recordings.started[0].Path != "calluuid/recuuid" || recordings.started[0].Mode != "screen" {
		t.Fatalf("started = %+v", recordings.started)
	}
}

func TestStartRejectsPathTraversalAndBadInput(t *testing.T) {
	recordings := &fakeRecordings{}

	bodies := map[string]string{
		"parent traversal":  `{"id":"rec-1","room":"r","mode":"full","path":"../../etc"}`,
		"absolute path":     `{"id":"rec-1","room":"r","mode":"full","path":"/var/lib/tandem"}`,
		"encoded traversal": `{"id":"rec-1","room":"r","mode":"full","path":"a/%2e%2e/b"}`,
		"id traversal":      `{"id":"../rec","room":"r","mode":"full","path":"a/b"}`,
		"unknown mode":      `{"id":"rec-1","room":"r","mode":"video","path":"a/b"}`,
		"missing fields":    `{}`,
	}

	for name, body := range bodies {
		if response, _ := call(t, recordings, http.MethodPost, "/recordings", secret, body); response.StatusCode != http.StatusUnprocessableEntity {
			t.Errorf("%s: status %d, want 422", name, response.StatusCode)
		}
	}

	if response, _ := call(t, recordings, http.MethodPost, "/recordings", secret, `not json`); response.StatusCode != http.StatusBadRequest {
		t.Errorf("malformed body: status %d, want 400", response.StatusCode)
	}

	if len(recordings.started) != 0 {
		t.Fatalf("a rejected request started a recording: %+v", recordings.started)
	}
}

func TestStartMapsFailuresToStatusCodes(t *testing.T) {
	cases := map[int]error{
		http.StatusInsufficientStorage: recorder.ErrDiskLow,
		http.StatusConflict:            recorder.ErrConflict,
		http.StatusServiceUnavailable:  recorder.ErrShuttingDown,
		http.StatusInternalServerError: errors.New("mkdir: permission denied"),
	}

	for want, err := range cases {
		response, body := call(t, &fakeRecordings{startErr: err}, http.MethodPost, "/recordings", secret,
			`{"id":"rec-1","room":"r","mode":"full","path":"a/b"}`)

		if response.StatusCode != want {
			t.Errorf("%v: status %d, want %d", err, response.StatusCode, want)
		}

		if want == http.StatusInternalServerError && strings.Contains(body["error"].(string), "permission denied") {
			t.Error("internal error details leaked to the caller")
		}
	}
}

func TestShowAndStop(t *testing.T) {
	recordings := &fakeRecordings{}

	response, body := call(t, recordings, http.MethodGet, "/recordings/rec-1", secret, "")
	if response.StatusCode != http.StatusOK || body["status"] != "recording" || body["manifest"].(map[string]any)["version"] != float64(1) {
		t.Fatalf("show: %d %v", response.StatusCode, body)
	}

	if response, _ := call(t, recordings, http.MethodGet, "/recordings/unknown", secret, ""); response.StatusCode != http.StatusNotFound {
		t.Errorf("show unknown: status %d, want 404", response.StatusCode)
	}

	if response, _ := call(t, recordings, http.MethodPost, "/recordings/rec-1/stop", secret, ""); response.StatusCode != http.StatusAccepted {
		t.Errorf("stop: status %d, want 202", response.StatusCode)
	}

	if response, _ := call(t, recordings, http.MethodPost, "/recordings/unknown/stop", secret, ""); response.StatusCode != http.StatusNotFound {
		t.Errorf("stop unknown: status %d, want 404", response.StatusCode)
	}
}

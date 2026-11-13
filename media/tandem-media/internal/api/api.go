// Package api is the recorder's HTTP control surface, used by Tandem only.
package api

import (
	"crypto/subtle"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"strings"

	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/manifest"
	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/recorder"
)

// Recordings is what the API needs from the recording manager.
type Recordings interface {
	Start(recorder.StartRequest) (string, error)
	Stop(id string) error
	Get(id string) (string, *manifest.Manifest, error)
	Stats() recorder.Stats
	Healthy() error
}

// Check reports a problem that should make /healthz fail, or nil.
type Check func() error

// New returns the HTTP handler. Everything except /healthz requires the shared secret.
// /healthz fails when the recordings directory is not writable or any extra check fails;
// the combined binary passes the health of the media server it embeds.
func New(recordings Recordings, secret string, log *slog.Logger, checks ...Check) http.Handler {
	h := &handler{recordings: recordings, secret: []byte(secret), log: log, checks: append([]Check{recordings.Healthy}, checks...)}

	mux := http.NewServeMux()
	mux.HandleFunc("GET /healthz", h.health)
	mux.HandleFunc("GET /stats", h.authenticated(h.stats))
	mux.HandleFunc("POST /recordings", h.authenticated(h.start))
	mux.HandleFunc("GET /recordings/{id}", h.authenticated(h.show))
	mux.HandleFunc("POST /recordings/{id}/stop", h.authenticated(h.stop))

	return h.contained(mux)
}

// HealthOnly returns a handler that serves /healthz and nothing else. The combined binary
// uses it when recording is switched off, so that one probe covers every configuration.
func HealthOnly(log *slog.Logger, checks ...Check) http.Handler {
	h := &handler{log: log, checks: checks}

	mux := http.NewServeMux()
	mux.HandleFunc("GET /healthz", h.health)

	return h.contained(mux)
}

type handler struct {
	recordings Recordings
	secret     []byte
	log        *slog.Logger
	checks     []Check
}

// contained answers 500 instead of dropping the connection when a handler panics.
func (h *handler) contained(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		defer func() {
			if cause := recover(); cause != nil {
				h.log.Error("recovered from a panic in the recorder", "where", r.Method+" "+r.URL.Path, "panic", fmt.Sprint(cause))
				respond(w, http.StatusInternalServerError, map[string]string{"error": "internal error"})
			}
		}()

		next.ServeHTTP(w, r)
	})
}

func (h *handler) authenticated(next http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		token, ok := strings.CutPrefix(r.Header.Get("Authorization"), "Bearer ")

		if !ok || len(h.secret) == 0 || subtle.ConstantTimeCompare([]byte(token), h.secret) != 1 {
			respond(w, http.StatusUnauthorized, map[string]string{"error": "unauthorized"})

			return
		}

		next(w, r)
	}
}

func (h *handler) health(w http.ResponseWriter, _ *http.Request) {
	for _, check := range h.checks {
		if err := check(); err != nil {
			h.log.Error("health check failed", "error", err)
			respond(w, http.StatusServiceUnavailable, map[string]string{"status": "unavailable"})

			return
		}
	}

	respond(w, http.StatusOK, map[string]string{"status": "ok"})
}

func (h *handler) stats(w http.ResponseWriter, _ *http.Request) {
	respond(w, http.StatusOK, h.recordings.Stats())
}

func (h *handler) start(w http.ResponseWriter, r *http.Request) {
	var request recorder.StartRequest

	decoder := json.NewDecoder(io.LimitReader(r.Body, 64<<10))
	if err := decoder.Decode(&request); err != nil {
		respond(w, http.StatusBadRequest, map[string]string{"error": "the body must be a JSON object"})

		return
	}

	status, err := h.recordings.Start(request)

	var invalid *recorder.InvalidError

	switch {
	case err == nil:
		respond(w, http.StatusAccepted, map[string]string{"id": request.ID, "status": status})
	case errors.As(err, &invalid):
		respond(w, http.StatusUnprocessableEntity, map[string]string{"error": invalid.Message})
	case errors.Is(err, recorder.ErrConflict):
		respond(w, http.StatusConflict, map[string]string{"error": err.Error()})
	case errors.Is(err, recorder.ErrDiskLow):
		respond(w, http.StatusInsufficientStorage, map[string]string{"error": err.Error()})
	case errors.Is(err, recorder.ErrShuttingDown):
		respond(w, http.StatusServiceUnavailable, map[string]string{"error": err.Error()})
	default:
		h.log.Error("could not start recording", "recording", request.ID, "error", err)
		respond(w, http.StatusInternalServerError, map[string]string{"error": "the recording could not be started"})
	}
}

func (h *handler) show(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")

	status, recorded, err := h.recordings.Get(id)
	if err != nil {
		respond(w, http.StatusNotFound, map[string]string{"error": "recording not found"})

		return
	}

	respond(w, http.StatusOK, map[string]any{"id": id, "status": status, "manifest": recorded})
}

func (h *handler) stop(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")

	if err := h.recordings.Stop(id); err != nil {
		respond(w, http.StatusNotFound, map[string]string{"error": "recording not found"})

		return
	}

	respond(w, http.StatusAccepted, map[string]string{"id": id, "status": recorder.StatusFinalizing})
}

func respond(w http.ResponseWriter, status int, body any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(body)
}

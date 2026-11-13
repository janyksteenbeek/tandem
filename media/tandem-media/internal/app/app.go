// Package app wires the recorder together: webhooks, the recording manager, crash recovery
// and the HTTP control surface. Both commands start it; only how it is configured and how it
// shuts down differs between them.
package app

import (
	"context"
	"errors"
	"log/slog"
	"net"
	"net/http"
	"os"
	"time"

	"github.com/go-logr/logr"
	protoLogger "github.com/livekit/protocol/logger"
	lksdk "github.com/livekit/server-sdk-go/v2"

	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/api"
	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/config"
	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/recorder"
	"github.com/janyksteenbeek/tandem/media/tandem-media/internal/webhook"
)

// Options configures a recorder.
type Options struct {
	Config config.Config
	Log    *slog.Logger

	// FinishOnShutdown ends open recordings for good when the process stops (the combined
	// binary, where stopping ends the calls). Otherwise their files are finalised and the
	// recordings are left open for the next process to continue (the standalone recorder).
	FinishOnShutdown bool

	// Checks are extra conditions for /healthz, such as the health of an embedded media server.
	Checks []api.Check
}

// Recorder is a running recorder.
type Recorder struct {
	opts     Options
	manager  *recorder.Manager
	notifier *webhook.Notifier
	server   *http.Server
	failed   chan error
}

// Start launches the recorder: it recovers what an earlier process left behind and begins
// serving the control API. A listen error is returned right away.
func Start(opts Options) (*Recorder, error) {
	cfg, log := opts.Config, opts.Log

	if err := os.MkdirAll(cfg.RecordingsDir, 0o755); err != nil {
		return nil, err
	}

	// The LiveKit client logs through its own logger; route it into ours at warn and above.
	sdkLog := slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{Level: max(cfg.LogLevel, slog.LevelWarn)}))
	lksdk.SetLogger(protoLogger.LogRLogger(logr.FromSlogHandler(sdkLog.Handler())))

	listener, err := net.Listen("tcp", cfg.Listen)
	if err != nil {
		return nil, err
	}

	r := &Recorder{opts: opts, failed: make(chan error, 1)}
	r.notifier = webhook.New(cfg.WebhookURL, cfg.Secret, log)
	r.manager = recorder.NewManager(cfg, log, r.notifier)
	r.manager.Recover()

	r.server = newServer(api.New(r.manager, cfg.Secret, log, opts.Checks...))
	r.serve(listener)

	log.Info("recorder listening", "addr", cfg.Listen, "version", recorder.Version,
		"livekit", cfg.LiveKitURL, "recordings_dir", cfg.RecordingsDir, "webhook", cfg.WebhookURL != "")

	return r, nil
}

// StartHealthOnly serves /healthz without a recorder behind it.
func StartHealthOnly(log *slog.Logger, checks ...api.Check) (*Recorder, error) {
	listener, err := net.Listen("tcp", config.Listen())
	if err != nil {
		return nil, err
	}

	r := &Recorder{opts: Options{Log: log}, failed: make(chan error, 1)}
	r.server = newServer(api.HealthOnly(log, checks...))
	r.serve(listener)

	return r, nil
}

// Failed reports an HTTP listener that stopped unexpectedly.
func (r *Recorder) Failed() <-chan error { return r.failed }

// Shutdown stops accepting requests, finalises every open file and delivers queued webhooks,
// within the time the context allows.
func (r *Recorder) Shutdown(ctx context.Context) {
	_ = r.server.Shutdown(ctx)

	if r.manager != nil {
		r.manager.Shutdown(ctx, r.opts.FinishOnShutdown)
	}
}

func (r *Recorder) serve(listener net.Listener) {
	go func() {
		if err := r.server.Serve(listener); err != nil && !errors.Is(err, http.ErrServerClosed) {
			r.failed <- err
		}
	}()
}

func newServer(handler http.Handler) *http.Server {
	return &http.Server{
		Handler:           handler,
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       15 * time.Second,
		WriteTimeout:      15 * time.Second,
		IdleTimeout:       60 * time.Second,
	}
}
